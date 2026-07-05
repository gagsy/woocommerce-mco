<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal MCP server: implements JSON-RPC 2.0 over a WP REST route.
 * Handles: initialize, tools/list, tools/call
 */
class WC_Ops_MCP_Server {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		add_action( 'rest_api_init', array( $this, 'add_route' ) );
	}

	public function add_route() {
		register_rest_route(
			'wc-ops-mcp/v1',
			'/mcp',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission( WP_REST_Request $request ) {
		return WC_Ops_MCP_Auth::instance()->validate_request( $request );
	}

	public function handle_request( WP_REST_Request $request ) {
		$body = json_decode( $request->get_body(), true );

		if ( ! is_array( $body ) || ! isset( $body['method'] ) ) {
			return $this->rpc_error( null, -32600, 'Invalid JSON-RPC request.' );
		}

		$id     = $body['id'] ?? null;
		$method = $body['method'];
		$params = $body['params'] ?? array();

		switch ( $method ) {
			case 'initialize':
				return $this->rpc_result(
					$id,
					array(
						'protocolVersion' => '2024-11-05',
						'serverInfo'      => array(
							'name'    => 'wc-ops-mcp',
							'version' => WC_OPS_MCP_VERSION,
						),
						'capabilities'    => array( 'tools' => new stdClass() ),
					)
				);

			case 'tools/list':
				$scope = WC_Ops_MCP_Auth::instance()->get_current_scope();
				return $this->rpc_result(
					$id,
					array( 'tools' => WC_Ops_MCP_Tools::instance()->get_tools_list_schema( $scope ) )
				);

			case 'tools/call':
				return $this->handle_tool_call( $id, $params );

			default:
				return $this->rpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	private function handle_tool_call( $id, $params ) {
		$tool_name = $params['name'] ?? '';
		$args      = $params['arguments'] ?? array();
		$scope     = WC_Ops_MCP_Auth::instance()->get_current_scope();

		$result = $this->execute_tool( $tool_name, $args, $scope );

		if ( is_wp_error( $result ) ) {
			return $this->rpc_error( $id, (int) $result->get_error_code(), $result->get_error_message() );
		}

		return $this->rpc_result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $result ),
					),
				),
			)
		);
	}

	/**
	 * Core tool execution, decoupled from JSON-RPC transport so it can be
	 * reused by the AI chat agent as well as the MCP endpoint. Handles
	 * enable/disable checks, scope enforcement, guardrails (inside each
	 * tool's own callback), and audit logging.
	 *
	 * @return array|WP_Error Tool output array, or WP_Error on failure/refusal.
	 */
	public function execute_tool( $tool_name, $args, $scope = 'full' ) {
		$tool = WC_Ops_MCP_Tools::instance()->get( $tool_name );
		if ( ! $tool ) {
			return new WP_Error( -32602, 'Unknown tool: ' . $tool_name . '. Call tools/list to see available tools.' );
		}

		if ( ! WC_Ops_MCP_Tools::instance()->is_tool_enabled( $tool_name ) ) {
			return new WP_Error( -32001, 'This tool has been disabled by the store owner in WooCommerce > Ops MCP > Tools.' );
		}

		if ( 'readonly' === $scope && ! empty( $tool['is_write'] ) ) {
			return new WP_Error( -32002, 'This API key is read-only and cannot call write tools like "' . $tool_name . '". Use the full-access key for write operations.' );
		}

		try {
			$output = call_user_func( $tool['callback'], $args );

			if ( ! empty( $tool['is_write'] ) ) {
				$is_dry = isset( $output['dry_run'] ) ? (bool) $output['dry_run'] : false;
				WC_Ops_MCP_Audit::instance()->log(
					$tool_name,
					$args,
					null,
					$output,
					$is_dry,
					isset( $output['error'] ) ? 'error' : 'success',
					$output['error'] ?? ''
				);
			}

			return $output;
		} catch ( Exception $e ) {
			if ( ! empty( $tool['is_write'] ) ) {
				WC_Ops_MCP_Audit::instance()->log( $tool_name, $args, null, null, false, 'error', $e->getMessage() );
			}
			return new WP_Error( -32000, 'Tool execution error: ' . $e->getMessage() );
		}
	}

	private function rpc_result( $id, $result ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	private function rpc_error( $id, $code, $message ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			200 // JSON-RPC errors are still transport-level 200s.
		);
	}
}
