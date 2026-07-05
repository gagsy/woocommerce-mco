<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Ops_MCP_Settings_Page {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_font' ) );
		add_action( 'admin_post_wc_ops_mcp_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_wc_ops_mcp_regenerate_key', array( $this, 'regenerate_key' ) );
		add_action( 'admin_post_wc_ops_mcp_save_tools', array( $this, 'save_tools' ) );
		add_action( 'wp_ajax_wc_ops_mcp_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wc_ops_mcp_run_tool', array( $this, 'ajax_run_tool' ) );
		add_action( 'admin_post_wc_ops_mcp_save_ai_settings', array( $this, 'save_ai_settings' ) );
		add_action( 'wp_ajax_wc_ops_mcp_chat', array( $this, 'ajax_chat' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			'Ops MCP',
			'🤖 Ops MCP',
			'manage_woocommerce',
			'wc-ops-mcp',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads the Inter typeface only on this plugin's own admin page - never
	 * globally in wp-admin, so it doesn't affect other plugins' styling or
	 * add an unnecessary external request on every admin screen.
	 */
	public function enqueue_font( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'wc-ops-mcp' ) ) {
			return;
		}
		wp_enqueue_style(
			'wc-ops-mcp-inter-font',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
			array(),
			WC_OPS_MCP_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * Form handlers
	 * ------------------------------------------------------------------- */

	public function save_settings() {
		check_admin_referer( 'wc_ops_mcp_save_settings' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		update_option( 'wc_ops_mcp_dry_run_default', isset( $_POST['dry_run_default'] ) ? 'yes' : 'no' );
		update_option( 'wc_ops_mcp_max_refund_amount', sanitize_text_field( $_POST['max_refund_amount'] ?? '5000' ) );
		update_option( 'wc_ops_mcp_max_price_change', sanitize_text_field( $_POST['max_price_change'] ?? '25' ) );
		update_option( 'wc_ops_mcp_rate_limit_per_minute', sanitize_text_field( $_POST['rate_limit_per_minute'] ?? '60' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-ops-mcp&tab=settings&updated=1' ) );
		exit;
	}

	public function regenerate_key() {
		check_admin_referer( 'wc_ops_mcp_regenerate_key' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$type = sanitize_text_field( $_POST['key_type'] ?? 'full' );

		if ( 'readonly' === $type ) {
			update_option( 'wc_ops_mcp_readonly_api_key', WC_Ops_MCP_Auth::instance()->generate_key( 'wcops_ro' ) );
		} else {
			update_option( 'wc_ops_mcp_api_key', WC_Ops_MCP_Auth::instance()->generate_key( 'wcops' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wc-ops-mcp&tab=connection&key_regenerated=1' ) );
		exit;
	}

	public function save_tools() {
		check_admin_referer( 'wc_ops_mcp_save_tools' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$all_tools = array_keys( WC_Ops_MCP_Tools::instance()->get_all() );
		$enabled   = isset( $_POST['enabled_tools'] ) ? array_map( 'sanitize_text_field', (array) $_POST['enabled_tools'] ) : array();
		$disabled  = array_values( array_diff( $all_tools, $enabled ) );

		update_option( 'wc_ops_mcp_disabled_tools', $disabled );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-ops-mcp&tab=tools&updated=1' ) );
		exit;
	}

	public function save_ai_settings() {
		check_admin_referer( 'wc_ops_mcp_save_ai_settings' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		update_option( 'wc_ops_mcp_ai_provider', sanitize_text_field( $_POST['ai_provider'] ?? 'groq' ) );
		update_option( 'wc_ops_mcp_ai_model', sanitize_text_field( $_POST['ai_model'] ?? '' ) );
		update_option( 'wc_ops_mcp_ai_base_url', esc_url_raw( trim( $_POST['ai_base_url'] ?? '' ) ) );

		// Only overwrite the stored key if a new one was actually typed in
		// (the field is masked/blank on reload so we don't echo secrets back).
		if ( ! empty( $_POST['ai_api_key'] ) ) {
			update_option( 'wc_ops_mcp_ai_api_key', sanitize_text_field( $_POST['ai_api_key'] ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wc-ops-mcp&tab=chat&updated=1' ) );
		exit;
	}

	/**
	 * Chat endpoint backing the "Ask AI" tab. Receives the running conversation
	 * plus a new user message, hands it to WC_Ops_MCP_Ai_Agent, and returns the
	 * assistant's reply plus the updated conversation (client stores/replays it -
	 * this endpoint itself is stateless).
	 */
	public function ajax_chat() {
		check_ajax_referer( 'wc_ops_mcp_chat' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		// Local/self-hosted models can take a while per call, and the tool-calling
		// loop can make several round trips - raise the script time limit so PHP
		// itself doesn't kill the request before Ollama/LM Studio responds.
		// Some hosts disable this function entirely, so guard the call.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$history_raw = isset( $_POST['history'] ) ? wp_unslash( $_POST['history'] ) : '[]';
		$history     = json_decode( $history_raw, true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$user_message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		if ( '' === trim( $user_message ) ) {
			wp_send_json_error( array( 'message' => 'Type a message first.' ) );
		}

		$history[] = array( 'role' => 'user', 'content' => $user_message );

		$result = WC_Ops_MCP_Ai_Agent::instance()->run_conversation( $history );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'reply'      => $result['reply'],
				'history'    => $result['messages'],
				'tool_calls' => $result['tool_calls'],
			)
		);
	}

	/**
	 * One-click "Test Connection" button - fires a loopback tools/list call
	 * using the site's own full API key so a beginner never has to touch curl.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'wc_ops_mcp_test_connection' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$api_key = get_option( 'wc_ops_mcp_api_key', '' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'Generate an API key first.' ) );
		}

		$body = $this->dispatch_internal_mcp_request(
			$api_key,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
			)
		);

		if ( is_wp_error( $body ) ) {
			wp_send_json_error( array( 'message' => 'Connection failed: ' . $body->get_error_message() ) );
		}

		if ( empty( $body['result']['tools'] ) ) {
			$err_msg = ! empty( $body['error']['message'] ) ? $body['error']['message'] : 'Unexpected response from the MCP endpoint.';
			wp_send_json_error( array( 'message' => $err_msg ) );
		}

		wp_send_json_success(
			array(
				'message'    => 'Connected successfully!',
				'tool_count' => count( $body['result']['tools'] ),
			)
		);
	}

	/**
	 * Playground: run any tool from inside wp-admin, no external MCP client needed.
	 * Dispatches internally through WordPress's own REST server (rest_do_request),
	 * exercising the exact same route + permission_callback + tool code a real MCP
	 * client would hit - but without making a real HTTP round-trip. This means it
	 * works identically on Docker, XAMPP, shared hosting, or behind a proxy, since
	 * there's no "call myself over the network" step to misconfigure.
	 */
	public function ajax_run_tool() {
		check_ajax_referer( 'wc_ops_mcp_playground' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$api_key = get_option( 'wc_ops_mcp_api_key', '' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'Generate a full-access API key first (Connection tab).' ) );
		}

		$tool_name = isset( $_POST['tool_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tool_name'] ) ) : '';
		$args_raw  = isset( $_POST['arguments'] ) ? wp_unslash( $_POST['arguments'] ) : '{}';
		$arguments = json_decode( $args_raw, true );

		if ( null === $arguments && '' !== trim( $args_raw ) && 'null' !== trim( $args_raw ) ) {
			wp_send_json_error( array( 'message' => 'Arguments must be valid JSON, e.g. {"order_id": 1}' ) );
		}
		if ( ! is_array( $arguments ) ) {
			$arguments = array();
		}

		$body = $this->dispatch_internal_mcp_request(
			$api_key,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $tool_name,
					'arguments' => $arguments,
				),
			)
		);

		if ( is_wp_error( $body ) ) {
			wp_send_json_error( array( 'message' => 'Request failed: ' . $body->get_error_message() ) );
		}

		wp_send_json_success( array( 'raw' => $body ) );
	}

	/**
	 * Dispatch a JSON-RPC payload through our own REST route internally,
	 * without any real network round-trip. Returns decoded response array
	 * or a WP_Error if the internal dispatch itself failed.
	 */
	private function dispatch_internal_mcp_request( $api_key, $payload ) {
		if ( ! class_exists( 'WP_REST_Request' ) || ! function_exists( 'rest_do_request' ) ) {
			return new WP_Error( 'wc_ops_mcp_no_rest', 'WordPress REST API is not available on this site.' );
		}

		$request = new WP_REST_Request( 'POST', '/wc-ops-mcp/v1/mcp' );
		$request->set_header( 'Authorization', 'Bearer ' . $api_key );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$data = $response->as_error();
			return new WP_Error( 'wc_ops_mcp_dispatch_error', $data->get_error_message() );
		}

		return rest_get_server()->response_to_data( $response, false );
	}

	/* ---------------------------------------------------------------------
	 * Setup checklist (drives the "beginner friendly" banner)
	 * ------------------------------------------------------------------- */

	private function get_checklist() {
		$permalink_ok = '' !== get_option( 'permalink_structure', '' );
		$key_ok       = ! empty( get_option( 'wc_ops_mcp_api_key', '' ) );
		$wc_ok        = class_exists( 'WooCommerce' );

		return array(
			array( 'label' => 'WooCommerce is active', 'done' => $wc_ok ),
			array( 'label' => 'Pretty permalinks enabled', 'done' => $permalink_ok, 'fix_url' => admin_url( 'options-permalink.php' ) ),
			array( 'label' => 'API key generated', 'done' => $key_ok ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------- */

	public function render_page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'overview';

		$api_key           = get_option( 'wc_ops_mcp_api_key', '' );
		$readonly_key      = get_option( 'wc_ops_mcp_readonly_api_key', '' );
		$dry_run_default   = get_option( 'wc_ops_mcp_dry_run_default', 'yes' );
		$max_refund_amount = get_option( 'wc_ops_mcp_max_refund_amount', '5000' );
		$max_price_change  = get_option( 'wc_ops_mcp_max_price_change', '25' );
		$rate_limit        = get_option( 'wc_ops_mcp_rate_limit_per_minute', '60' );
		$endpoint_url      = rest_url( 'wc-ops-mcp/v1/mcp' );
		$logs              = WC_Ops_MCP_Audit::instance()->get_recent( 30 );
		$all_tools         = WC_Ops_MCP_Tools::instance()->get_all();
		$disabled_tools    = WC_Ops_MCP_Tools::instance()->get_disabled_tools();
		$checklist         = $this->get_checklist();
		$checklist_done    = count( array_filter( $checklist, function ( $i ) { return $i['done']; } ) );
		$checklist_total   = count( $checklist );
		?>
		<style>
			.wcops-wrap, .wcops-wrap * {
				font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
			}
			.wcops-wrap { max-width: 1140px; margin-top: 20px; font-size: 14px; color: #101828; }
			.wcops-wrap h1, .wcops-wrap h2, .wcops-wrap h3 { letter-spacing: -0.01em; }
			.wcops-hero {
				background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%);
				border-radius: 16px; padding: 28px 32px; color: #fff; margin-bottom: 24px;
				box-shadow: 0 8px 24px rgba(79, 70, 229, 0.18);
			}
			.wcops-hero h1 { color: #fff; margin: 0 0 6px; font-size: 22px; font-weight: 600; }
			.wcops-hero p { margin: 0; opacity: 0.9; font-size: 14px; }
			.wcops-tabs { display: flex; gap: 2px; margin-bottom: 20px; border-bottom: 1px solid #e4e7ec; }
			.wcops-tabs a {
				padding: 10px 16px; text-decoration: none; color: #667085; font-weight: 500; font-size: 14px;
				border-bottom: 2px solid transparent; margin-bottom: -1px; border-radius: 8px 8px 0 0;
				transition: color .15s, background-color .15s;
			}
			.wcops-tabs a:hover { color: #4f46e5; background: #f9fafb; }
			.wcops-tabs a.active { color: #4f46e5; border-bottom-color: #4f46e5; background: #f5f3ff; }
			.wcops-card {
				background: #fff; border: 1px solid #e4e7ec; border-radius: 14px;
				padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
			}
			.wcops-card h2 { margin-top: 0; font-size: 16px; font-weight: 600; }
			.wcops-checklist-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; }
			.wcops-badge-done { color: #00a32a; font-weight: bold; }
			.wcops-badge-todo { color: #d63638; font-weight: bold; }
			.wcops-key-box {
				display: flex; align-items: center; gap: 8px; background: #f9fafb;
				border: 1px solid #e4e7ec; border-radius: 8px; padding: 10px 12px; font-family: 'SFMono-Regular', Consolas, monospace !important;
			}
			.wcops-key-box, .wcops-key-box * { font-family: 'SFMono-Regular', Consolas, monospace !important; }
			.wcops-copy-btn { cursor: pointer; }
			.wcops-progress-bar { background: #eef2f6; border-radius: 6px; height: 8px; overflow: hidden; margin: 10px 0 4px; }
			.wcops-progress-fill { background: #7c3aed; height: 100%; }
			.wcops-tool-group { margin-bottom: 18px; }
			.wcops-tool-group h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #98a2b3; margin-bottom: 8px; font-weight: 600; }
			.wcops-tool-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f2f4f7; }
			.wcops-tool-row:last-child { border-bottom: none; }
			.wcops-tool-name { font-weight: 500; }
			.wcops-tool-desc { color: #667085; font-size: 12px; margin-top: 2px; max-width: 600px; }
			.wcops-write-badge { display:inline-block; font-size: 10px; background:#fef3f2; color:#d92d20; padding: 2px 7px; border-radius: 6px; margin-left: 6px; font-weight: 600; }
			#wcops-test-result { margin-top: 10px; font-weight: 500; }

			/* Stat card grid - Overview tab */
			.wcops-stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
			.wcops-stat-card { background: #fff; border: 1px solid #e4e7ec; border-radius: 14px; padding: 20px; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04); }
			.wcops-stat-icon {
				width: 42px; height: 42px; border-radius: 11px; display: flex; align-items: center;
				justify-content: center; font-size: 18px; margin-bottom: 14px;
			}
			.wcops-stat-icon.blue   { background: #eef4ff; }
			.wcops-stat-icon.green  { background: #ecfdf3; }
			.wcops-stat-icon.orange { background: #fff6ed; }
			.wcops-stat-icon.purple { background: #f4f3ff; }
			.wcops-stat-value { font-size: 26px; font-weight: 700; color: #101828; line-height: 1.2; letter-spacing: -0.02em; }
			.wcops-stat-label { font-size: 13px; color: #667085; margin-top: 4px; }
			@media (max-width: 900px) { .wcops-stat-grid { grid-template-columns: repeat(2, 1fr); } }

			/* Chat - avatar-based bubbles */
			.wcops-chat-row { display: flex; gap: 10px; margin-bottom: 14px; align-items: flex-start; }
			.wcops-chat-row.wcops-row-user { flex-direction: row-reverse; }
			.wcops-avatar {
				width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center;
				justify-content: center; font-size: 14px; flex-shrink: 0;
			}
			.wcops-avatar.assistant { background: linear-gradient(135deg, #7c3aed, #4f46e5); color: #fff; }
			.wcops-avatar.user { background: #eef2f6; color: #101828; }
			.wcops-chat-bubble-wrap { max-width: 78%; }
			.wcops-chat-actions { margin-top: 4px; display: flex; gap: 10px; }
			.wcops-chat-action-btn {
				background: none; border: none; cursor: pointer; font-size: 12px; color: #667085;
				padding: 2px 4px; display: inline-flex; align-items: center; gap: 4px;
			}
			.wcops-chat-action-btn:hover { color: #4f46e5; }
			.wcops-chat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
		</style>

		<div class="wrap wcops-wrap">
			<div class="wcops-hero">
				<h1>🤖 WooCommerce Ops MCP</h1>
				<p>Let an AI agent run orders, inventory, pricing, and cart recovery for this store — safely, with guardrails and a full activity log.</p>
			</div>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success"><p>Saved.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['key_regenerated'] ) ) : ?>
				<div class="notice notice-success"><p>New key generated. Update your MCP client config.</p></div>
			<?php endif; ?>

			<div class="wcops-tabs">
				<a href="?page=wc-ops-mcp&tab=overview" class="<?php echo 'overview' === $tab ? 'active' : ''; ?>">🏁 Overview</a>
				<a href="?page=wc-ops-mcp&tab=chat" class="<?php echo 'chat' === $tab ? 'active' : ''; ?>">💬 Ask AI</a>
				<a href="?page=wc-ops-mcp&tab=connection" class="<?php echo 'connection' === $tab ? 'active' : ''; ?>">🔑 Connection</a>
				<a href="?page=wc-ops-mcp&tab=tools" class="<?php echo 'tools' === $tab ? 'active' : ''; ?>">🛠️ Tools</a>
				<a href="?page=wc-ops-mcp&tab=playground" class="<?php echo 'playground' === $tab ? 'active' : ''; ?>">🧪 Playground (dev)</a>
				<a href="?page=wc-ops-mcp&tab=settings" class="<?php echo 'settings' === $tab ? 'active' : ''; ?>">🛡️ Guardrails</a>
				<a href="?page=wc-ops-mcp&tab=activity" class="<?php echo 'activity' === $tab ? 'active' : ''; ?>">📋 Activity Log</a>
				<a href="?page=wc-ops-mcp&tab=help" class="<?php echo 'help' === $tab ? 'active' : ''; ?>">❓ Help</a>
			</div>

			<?php
			switch ( $tab ) {
				case 'chat':
					$this->render_chat_tab();
					break;
				case 'connection':
					$this->render_connection_tab( $api_key, $readonly_key, $endpoint_url );
					break;
				case 'tools':
					$this->render_tools_tab( $all_tools, $disabled_tools );
					break;
				case 'playground':
					$this->render_playground_tab( $all_tools, $disabled_tools );
					break;
				case 'settings':
					$this->render_settings_tab( $dry_run_default, $max_refund_amount, $max_price_change, $rate_limit );
					break;
				case 'activity':
					$this->render_activity_tab( $logs );
					break;
				case 'help':
					$this->render_help_tab();
					break;
				default:
					$this->render_overview_tab( $checklist, $checklist_done, $checklist_total, $all_tools, $disabled_tools );
			}
			?>
		</div>
		<?php
	}

	private function render_overview_tab( $checklist, $done, $total, $all_tools, $disabled_tools ) {
		$active_tools = count( $all_tools ) - count( $disabled_tools );
		$stats        = $this->get_overview_stats();
		?>
		<div class="wcops-stat-grid">
			<div class="wcops-stat-card">
				<div class="wcops-stat-icon blue">🛒</div>
				<div class="wcops-stat-value"><?php echo (int) $stats['orders_today']; ?></div>
				<div class="wcops-stat-label">Orders Today</div>
			</div>
			<div class="wcops-stat-card">
				<div class="wcops-stat-icon green">💰</div>
				<div class="wcops-stat-value"><?php echo wp_kses_post( wc_price( $stats['revenue_month'] ) ); ?></div>
				<div class="wcops-stat-label">Revenue This Month</div>
			</div>
			<div class="wcops-stat-card">
				<div class="wcops-stat-icon orange">📦</div>
				<div class="wcops-stat-value"><?php echo (int) $stats['low_stock_count']; ?></div>
				<div class="wcops-stat-label">Low / Out of Stock</div>
			</div>
			<div class="wcops-stat-card">
				<div class="wcops-stat-icon purple">🛠️</div>
				<div class="wcops-stat-value"><?php echo (int) $active_tools; ?> / <?php echo count( $all_tools ); ?></div>
				<div class="wcops-stat-label">Tools Enabled</div>
			</div>
		</div>
		<?php
		$this->render_overview_tab_body( $checklist, $done, $total, $all_tools, $disabled_tools );
	}

	/**
	 * Real, current numbers for the overview stat cards - not decorative
	 * placeholders. Kept intentionally simple (no historical comparison)
	 * since fabricating a trend percentage would be misleading.
	 */
	private function get_overview_stats() {
		global $wpdb;

		$today_orders = wc_get_orders(
			array(
				'status'       => array( 'completed', 'processing', 'on-hold' ),
				'date_created' => '>=' . strtotime( 'today midnight' ),
				'limit'        => -1,
				'return'       => 'ids',
			)
		);

		$month_start = gmdate( 'Y-m-01 00:00:00' );
		$revenue_row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(pm.meta_value) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed','wc-processing') AND p.post_date >= %s",
				$month_start
			)
		);

		$low_stock_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} WHERE stock_status IN ('outofstock','onbackorder') OR (stock_quantity IS NOT NULL AND stock_quantity <= 5)"
		);

		return array(
			'orders_today'    => count( $today_orders ),
			'revenue_month'   => (float) $revenue_row,
			'low_stock_count' => $low_stock_count,
		);
	}

	private function render_overview_tab_body( $checklist, $done, $total, $all_tools, $disabled_tools ) {
		?>
		<div class="wcops-card">
			<h2>Setup checklist (<?php echo (int) $done; ?>/<?php echo (int) $total; ?> complete)</h2>
			<div class="wcops-progress-bar"><div class="wcops-progress-fill" style="width:<?php echo $total ? round( ( $done / $total ) * 100 ) : 0; ?>%"></div></div>
			<?php foreach ( $checklist as $item ) : ?>
				<div class="wcops-checklist-item">
					<span class="<?php echo $item['done'] ? 'wcops-badge-done' : 'wcops-badge-todo'; ?>"><?php echo $item['done'] ? '✓' : '○'; ?></span>
					<span><?php echo esc_html( $item['label'] ); ?></span>
					<?php if ( ! $item['done'] && ! empty( $item['fix_url'] ) ) : ?>
						<a href="<?php echo esc_url( $item['fix_url'] ); ?>" class="button button-small">Fix this</a>
					<?php endif; ?>
					<?php if ( ! $item['done'] && empty( $item['fix_url'] ) ) : ?>
						<a href="?page=wc-ops-mcp&tab=connection" class="button button-small">Fix this</a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="wcops-card">
			<h2>At a glance</h2>
			<p><strong><?php echo (int) $active_tools; ?></strong> of <strong><?php echo count( $all_tools ); ?></strong> tools enabled and ready for your AI agent to use.</p>
			<p>New here? Start with the <a href="?page=wc-ops-mcp&tab=chat">💬 Ask AI tab</a> — just type what you want in plain English. (The Connection tab is only needed if you're hooking up a separate MCP client like Claude Desktop instead of using the built-in chat.)</p>
		</div>

		<div class="wcops-card">
			<h2>What this plugin can do</h2>
			<ul style="list-style:disc; padding-left:20px; line-height:1.9;">
				<li><strong>Orders</strong> — view, update status, refund, add notes</li>
				<li><strong>Inventory</strong> — check stock, bulk update by SKU</li>
				<li><strong>Products</strong> — search, bulk price changes, publish/hide</li>
				<li><strong>Reports</strong> — daily sales, top products</li>
				<li><strong>Revenue recovery</strong> — abandoned carts, recovery coupons/emails, lapsed customers</li>
			</ul>
			<p>Every write action can run in <em>dry-run</em> preview mode first, is capped by guardrails you control, and is fully logged in the <a href="?page=wc-ops-mcp&tab=activity">Activity Log</a>.</p>
		</div>
		<?php
	}

	private function render_connection_tab( $api_key, $readonly_key, $endpoint_url ) {
		?>
		<div class="wcops-card">
			<h2>MCP Endpoint URL</h2>
			<p>Paste this into your MCP client's server config.</p>
			<div class="wcops-key-box">
				<code id="wcops-endpoint"><?php echo esc_html( $endpoint_url ); ?></code>
				<button type="button" class="button button-small wcops-copy-btn" data-copy-target="wcops-endpoint">Copy</button>
			</div>
		</div>

		<div class="wcops-card">
			<h2>Full-Access API Key</h2>
			<p>Can read <em>and</em> write (refunds, price changes, stock updates, etc). Keep this private — treat it like a password.</p>
			<?php if ( $api_key ) : ?>
				<div class="wcops-key-box">
					<code id="wcops-fullkey"><?php echo esc_html( $api_key ); ?></code>
					<button type="button" class="button button-small wcops-copy-btn" data-copy-target="wcops-fullkey">Copy</button>
				</div>
			<?php else : ?>
				<p><em>No key generated yet.</em></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
				<input type="hidden" name="action" value="wc_ops_mcp_regenerate_key" />
				<input type="hidden" name="key_type" value="full" />
				<?php wp_nonce_field( 'wc_ops_mcp_regenerate_key' ); ?>
				<button type="submit" class="button" onclick="return confirm('Generate a new full-access key? The old one stops working immediately.');">
					<?php echo $api_key ? 'Regenerate Full Key' : 'Generate Full Key'; ?>
				</button>
			</form>

			<?php if ( $api_key ) : ?>
				<p style="margin-top:16px;">
					<button type="button" class="button button-primary" id="wcops-test-connection">Test Connection</button>
					<span id="wcops-test-result"></span>
				</p>
			<?php endif; ?>
		</div>

		<div class="wcops-card">
			<h2>Read-Only API Key <span style="font-weight:normal; color:#787c82;">(recommended for reporting agents)</span></h2>
			<p>Can only call safe, read-only tools (orders list, stock levels, sales reports). Cannot refund, change prices, or touch stock. Hand this out freely to agents that only need to look, not act.</p>
			<?php if ( $readonly_key ) : ?>
				<div class="wcops-key-box">
					<code id="wcops-rokey"><?php echo esc_html( $readonly_key ); ?></code>
					<button type="button" class="button button-small wcops-copy-btn" data-copy-target="wcops-rokey">Copy</button>
				</div>
			<?php else : ?>
				<p><em>No read-only key generated yet.</em></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
				<input type="hidden" name="action" value="wc_ops_mcp_regenerate_key" />
				<input type="hidden" name="key_type" value="readonly" />
				<?php wp_nonce_field( 'wc_ops_mcp_regenerate_key' ); ?>
				<button type="submit" class="button" onclick="return confirm('Generate a new read-only key? The old one stops working immediately.');">
					<?php echo $readonly_key ? 'Regenerate Read-Only Key' : 'Generate Read-Only Key'; ?>
				</button>
			</form>
		</div>

		<script>
		document.addEventListener('click', function (e) {
			if (e.target && e.target.classList.contains('wcops-copy-btn')) {
				var targetId = e.target.getAttribute('data-copy-target');
				var text = document.getElementById(targetId).textContent;
				navigator.clipboard.writeText(text).then(function () {
					var original = e.target.textContent;
					e.target.textContent = 'Copied!';
					setTimeout(function () { e.target.textContent = original; }, 1500);
				});
			}
		});

		var testBtn = document.getElementById('wcops-test-connection');
		if (testBtn) {
			testBtn.addEventListener('click', function () {
				var resultEl = document.getElementById('wcops-test-result');
				resultEl.textContent = 'Testing...';
				resultEl.style.color = '#787c82';

				var formData = new FormData();
				formData.append('action', 'wc_ops_mcp_test_connection');
				formData.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'wc_ops_mcp_test_connection' ) ); ?>');

				fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						if (data.success) {
							resultEl.style.color = '#00a32a';
							resultEl.textContent = '✓ ' + data.data.message + ' (' + data.data.tool_count + ' tools available)';
						} else {
							resultEl.style.color = '#d63638';
							resultEl.textContent = '✗ ' + data.data.message;
						}
					})
					.catch(function () {
						resultEl.style.color = '#d63638';
						resultEl.textContent = '✗ Request failed.';
					});
			});
		}
		</script>
		<?php
	}

	private function render_tools_tab( $all_tools, $disabled_tools ) {
		$groups = array(
			'Orders'           => array( 'list_orders', 'get_order_details', 'update_order_status', 'create_refund', 'add_order_note' ),
			'Inventory'        => array( 'check_stock_levels', 'bulk_update_stock' ),
			'Products'         => array( 'find_products', 'bulk_price_update', 'toggle_product_visibility' ),
			'Reports'          => array( 'daily_sales_summary', 'top_products_by_period' ),
			'Revenue Recovery' => array( 'list_abandoned_carts', 'create_recovery_coupon', 'send_cart_recovery_email', 'find_lapsed_customers' ),
			'Sales & Marketing' => array( 'create_flash_sale', 'set_product_cross_sells', 'find_pricing_opportunities', 'find_slow_moving_stock', 'export_customer_audience', 'traffic_source_performance' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wc_ops_mcp_save_tools" />
			<?php wp_nonce_field( 'wc_ops_mcp_save_tools' ); ?>

			<div class="wcops-card">
				<h2>Enable or disable individual tools</h2>
				<p>Turn off anything you don't want an AI agent touching on this store. Disabled tools won't even show up when an agent asks what's available.</p>

				<?php foreach ( $groups as $group_label => $tool_names ) : ?>
					<div class="wcops-tool-group">
						<h3><?php echo esc_html( $group_label ); ?></h3>
						<?php foreach ( $tool_names as $name ) :
							if ( ! isset( $all_tools[ $name ] ) ) {
								continue;
							}
							$tool     = $all_tools[ $name ];
							$is_on    = ! in_array( $name, $disabled_tools, true );
							?>
							<div class="wcops-tool-row">
								<div>
									<div class="wcops-tool-name">
										<?php echo esc_html( $tool['name'] ); ?>
										<?php if ( ! empty( $tool['is_write'] ) ) : ?>
											<span class="wcops-write-badge">WRITE</span>
										<?php endif; ?>
									</div>
									<div class="wcops-tool-desc"><?php echo esc_html( $tool['description'] ); ?></div>
								</div>
								<label>
									<input type="checkbox" name="enabled_tools[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( $is_on ); ?> />
									Enabled
								</label>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>

				<?php submit_button( 'Save Tool Settings' ); ?>
			</div>
		</form>
		<?php
	}

	private function render_chat_tab() {
		$provider  = get_option( 'wc_ops_mcp_ai_provider', 'groq' );
		$model     = get_option( 'wc_ops_mcp_ai_model', '' );
		$base_url  = get_option( 'wc_ops_mcp_ai_base_url', '' );
		$has_key   = ! empty( get_option( 'wc_ops_mcp_ai_api_key', '' ) );
		$providers = WC_Ops_MCP_Ai_Agent::get_providers();
		?>
		<div class="wcops-card">
			<h2>💬 Ask AI — just type what you want</h2>
			<p>No tools to pick, no JSON to write. Type things like <em>"how much did I sell yesterday?"</em>, <em>"start a 20% flash sale on the shoes category for 48 hours"</em>, or <em>"which ads are actually working?"</em> and the assistant figures out the rest — using the same guardrails and activity log as everything else in this plugin.</p>
		</div>

		<div class="wcops-card">
			<h3 style="margin-top:0;">AI provider setup <?php echo $has_key || 'local' === $provider ? '<span style="color:#00a32a; font-weight:normal;">(configured)</span>' : '<span style="color:#d63638; font-weight:normal;">(not set up yet)</span>'; ?></h3>
			<p class="description">Pick any AI provider you like — this plugin doesn't lock you into one. <strong>Groq</strong> and <strong>Google Gemini</strong> are free with no credit card, good defaults if you're not sure. Prefer full privacy with zero cost? Use <strong>Local / self-hosted</strong> if you already run Ollama, LM Studio, or vLLM.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wc_ops_mcp_save_ai_settings" />
				<?php wp_nonce_field( 'wc_ops_mcp_save_ai_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="wcops-ai-provider">Provider</label></th>
						<td>
							<select name="ai_provider" id="wcops-ai-provider">
								<?php foreach ( $providers as $key => $info ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $provider ); ?>><?php echo esc_html( $info['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<div id="wcops-provider-notes" style="margin-top:8px;">
								<?php foreach ( $providers as $key => $info ) : ?>
									<p class="description wcops-provider-note" data-provider="<?php echo esc_attr( $key ); ?>" style="<?php echo $key === $provider ? '' : 'display:none;'; ?>">
										<?php echo esc_html( $info['note'] ); ?>
										<?php if ( ! empty( $info['signup_url'] ) ) : ?>
											Get a key at <a href="<?php echo esc_url( $info['signup_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $info['signup_url'] ); ?></a>.
										<?php endif; ?>
									</p>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
					<tr id="wcops-base-url-row" style="<?php echo 'local' === $provider ? '' : 'display:none;'; ?>">
						<th><label for="wcops-ai-base-url">Base URL</label></th>
						<td>
							<input type="text" name="ai_base_url" id="wcops-ai-base-url" value="<?php echo esc_attr( $base_url ); ?>" class="regular-text" placeholder="e.g. http://localhost:11434/v1/chat/completions" />
							<p class="description">The OpenAI-compatible chat completions endpoint of your local server (Ollama, LM Studio, vLLM, etc). Only needed for "Local / self-hosted".</p>
						</td>
					</tr>
					<tr>
						<th><label for="wcops-ai-model">Model</label></th>
						<td>
							<input type="text" name="ai_model" id="wcops-ai-model" value="<?php echo esc_attr( $model ); ?>" class="regular-text" placeholder="Leave blank for the recommended default" />
							<p class="description">Leave blank to use a sensible default for the selected provider (not applicable for Local — enter your model name explicitly).</p>
						</td>
					</tr>
					<tr id="wcops-ai-key-row" style="<?php echo 'local' === $provider ? 'display:none;' : ''; ?>">
						<th><label for="wcops-ai-key">API Key</label></th>
						<td>
							<input type="password" name="ai_api_key" id="wcops-ai-key" class="regular-text" placeholder="<?php echo $has_key ? 'Leave blank to keep the current key' : 'Paste your API key'; ?>" autocomplete="off" />
						</td>
					</tr>
				</table>
				<?php submit_button( $has_key || 'local' === $provider ? 'Update AI Settings' : 'Save & Enable Chat' ); ?>
			</form>
			<script>
			document.getElementById('wcops-ai-provider').addEventListener('change', function () {
				document.querySelectorAll('.wcops-provider-note').forEach(function (el) {
					el.style.display = el.getAttribute('data-provider') === this.value ? '' : 'none';
				}.bind(this));
				var isLocal = this.value === 'local';
				document.getElementById('wcops-base-url-row').style.display = isLocal ? '' : 'none';
				document.getElementById('wcops-ai-key-row').style.display = isLocal ? 'none' : '';
			});
			</script>
		</div>

		<?php if ( $has_key || 'local' === $provider ) : ?>
			<div class="wcops-card" style="padding: 0; overflow: hidden;">
				<div class="wcops-chat-header" style="padding: 20px 24px 0;">
					<strong>Conversation</strong>
					<button type="button" class="button" id="wcops-chat-new">+ New Chat</button>
				</div>
				<div id="wcops-chat-log" class="wcops-claude-log"></div>

				<div class="wcops-claude-input-wrap">
					<div class="wcops-claude-input-box">
						<textarea id="wcops-chat-input" rows="2" placeholder="e.g. how much did I sell yesterday?"></textarea>
						<div class="wcops-claude-input-toolbar">
							<button type="button" class="wcops-claude-icon-btn" title="Attach (not yet supported)" disabled>+</button>
							<div class="wcops-claude-input-right">
								<span class="wcops-claude-model-pill">✨ <?php echo esc_html( $providers[ $provider ]['label'] ?? $provider ); ?></span>
								<button type="button" class="wcops-claude-send-btn" id="wcops-chat-send" title="Send">➤</button>
							</div>
						</div>
					</div>
				</div>
				<p class="description" style="padding: 0 24px 20px; margin-top: 8px;">For actions that change your store (refunds, price changes), the assistant will always show you a preview and ask you to confirm before it actually happens.</p>
			</div>

			<style>
				.wcops-claude-log { padding: 20px 24px; min-height: 320px; max-height: 480px; overflow-y: auto; }
				.wcops-claude-row-user { display: flex; margin-bottom: 20px; }
				.wcops-claude-user-bubble {
					background: #f4f4f5; color: #101828; border-radius: 16px; padding: 12px 16px;
					max-width: 75%; margin-left: auto; line-height: 1.6;
				}
				.wcops-claude-user-actions { display: flex; justify-content: flex-end; gap: 4px; margin-top: 4px; }
				.wcops-claude-assistant-block { margin-bottom: 24px; }
				.wcops-claude-assistant-label {
					display: flex; align-items: center; gap: 6px; font-size: 13px; color: #667085;
					font-weight: 500; margin-bottom: 8px;
				}
				.wcops-claude-assistant-text { line-height: 1.7; color: #101828; white-space: pre-wrap; }
				.wcops-claude-assistant-actions { display: flex; gap: 4px; margin-top: 8px; }
				.wcops-claude-icon-btn {
					background: none; border: none; cursor: pointer; color: #98a2b3; font-size: 15px;
					padding: 5px 7px; border-radius: 6px; line-height: 1;
				}
				.wcops-claude-icon-btn:hover { background: #f2f4f7; color: #4f46e5; }
				.wcops-claude-tools-note { font-size: 11px; color: #98a2b3; margin: -14px 0 20px 2px; }

				.wcops-claude-input-wrap { padding: 0 24px; }
				.wcops-claude-input-box {
					border: 1px solid #e4e7ec; border-radius: 20px; padding: 14px 16px 10px;
					box-shadow: 0 1px 3px rgba(16,24,40,0.04);
				}
				.wcops-claude-input-box textarea {
					width: 100%; border: none; outline: none; resize: none; font-size: 14px;
					font-family: inherit; padding: 0; margin-bottom: 8px; color: #101828;
				}
				.wcops-claude-input-toolbar { display: flex; align-items: center; justify-content: space-between; }
				.wcops-claude-input-right { display: flex; align-items: center; gap: 10px; }
				.wcops-claude-model-pill { font-size: 12px; color: #667085; }
				.wcops-claude-send-btn {
					width: 32px; height: 32px; border-radius: 50%; background: #101828; color: #fff;
					border: none; cursor: pointer; font-size: 14px; display: flex; align-items: center;
					justify-content: center;
				}
				.wcops-claude-send-btn:disabled { opacity: 0.4; cursor: default; }
			</style>
			<script>
			(function () {
				var log        = document.getElementById('wcops-chat-log');
				var input      = document.getElementById('wcops-chat-input');
				var sendBtn    = document.getElementById('wcops-chat-send');
				var newChatBtn = document.getElementById('wcops-chat-new');
				var history    = [];
				var lastUserMessage = '';

				function addUserRow( text ) {
					var row = document.createElement('div');
					row.className = 'wcops-claude-row-user';

					var bubble = document.createElement('div');
					bubble.className = 'wcops-claude-user-bubble';
					bubble.textContent = text;
					row.appendChild( bubble );
					log.appendChild( row );

					var actions = document.createElement('div');
					actions.className = 'wcops-claude-user-actions';
					var copyBtn = document.createElement('button');
					copyBtn.type = 'button';
					copyBtn.className = 'wcops-claude-icon-btn';
					copyBtn.title = 'Copy';
					copyBtn.textContent = '⧉';
					copyBtn.addEventListener('click', function () {
						navigator.clipboard.writeText( text );
						copyBtn.textContent = '✓';
						setTimeout( function () { copyBtn.textContent = '⧉'; }, 1200 );
					});
					actions.appendChild( copyBtn );
					log.appendChild( actions );

					log.scrollTop = log.scrollHeight;
				}

				function addAssistantBlock( text ) {
					var block = document.createElement('div');
					block.className = 'wcops-claude-assistant-block';

					var label = document.createElement('div');
					label.className = 'wcops-claude-assistant-label';
					label.innerHTML = '✨ AI Assistant';
					block.appendChild( label );

					var textEl = document.createElement('div');
					textEl.className = 'wcops-claude-assistant-text';
					textEl.textContent = text;
					block.appendChild( textEl );

					var actions = document.createElement('div');
					actions.className = 'wcops-claude-assistant-actions';

					var copyBtn = document.createElement('button');
					copyBtn.type = 'button';
					copyBtn.className = 'wcops-claude-icon-btn';
					copyBtn.title = 'Copy';
					copyBtn.textContent = '⧉';
					copyBtn.addEventListener('click', function () {
						navigator.clipboard.writeText( textEl.textContent );
						copyBtn.textContent = '✓';
						setTimeout( function () { copyBtn.textContent = '⧉'; }, 1200 );
					});
					actions.appendChild( copyBtn );

					var upBtn = document.createElement('button');
					upBtn.type = 'button';
					upBtn.className = 'wcops-claude-icon-btn';
					upBtn.title = 'Good response';
					upBtn.textContent = '👍';
					actions.appendChild( upBtn );

					var downBtn = document.createElement('button');
					downBtn.type = 'button';
					downBtn.className = 'wcops-claude-icon-btn';
					downBtn.title = 'Bad response';
					downBtn.textContent = '👎';
					actions.appendChild( downBtn );

					var regenBtn = document.createElement('button');
					regenBtn.type = 'button';
					regenBtn.className = 'wcops-claude-icon-btn';
					regenBtn.title = 'Regenerate';
					regenBtn.textContent = '↻';
					regenBtn.addEventListener('click', function () {
						if ( ! lastUserMessage ) { return; }
						history = history.slice( 0, Math.max( 0, history.length - 2 ) );
						send( lastUserMessage, true );
					});
					actions.appendChild( regenBtn );

					block.appendChild( actions );
					log.appendChild( block );
					log.scrollTop = log.scrollHeight;
					return textEl;
				}

				function addToolNote( names ) {
					if ( ! names || ! names.length ) { return; }
					var div = document.createElement('div');
					div.className = 'wcops-claude-tools-note';
					div.textContent = '🔧 Checked: ' + names.join(', ');
					log.appendChild( div );
					log.scrollTop = log.scrollHeight;
				}

				function resetChat() {
					history = [];
					lastUserMessage = '';
					log.innerHTML = '';
					addAssistantBlock( "👋 Hi! Ask me anything about your store — sales, stock, orders, refunds, whatever you need." );
				}

				function send( overrideText, isRegenerate ) {
					var text = overrideText || input.value.trim();
					if ( ! text ) { return; }

					if ( ! isRegenerate ) {
						addUserRow( text );
						input.value = '';
					}
					lastUserMessage = text;
					sendBtn.disabled = true;
					var thinkingEl = addAssistantBlock( '<?php echo esc_js( 'local' === $provider ? 'Thinking... (local models can take up to a couple of minutes, please wait)' : 'Thinking...' ); ?>' );

					var formData = new FormData();
					formData.append( 'action', 'wc_ops_mcp_chat' );
					formData.append( '_wpnonce', '<?php echo esc_js( wp_create_nonce( 'wc_ops_mcp_chat' ) ); ?>' );
					formData.append( 'message', text );
					formData.append( 'history', JSON.stringify( history ) );

					fetch( ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							sendBtn.disabled = false;
							thinkingEl.closest('.wcops-claude-assistant-block').remove();
							if ( data.success ) {
								history = data.data.history;
								addToolNote( data.data.tool_calls );
								addAssistantBlock( data.data.reply || '(no reply)' );
							} else {
								addAssistantBlock( '⚠️ ' + data.data.message );
							}
						})
						.catch( function ( err ) {
							sendBtn.disabled = false;
							thinkingEl.closest('.wcops-claude-assistant-block').remove();
							addAssistantBlock( '⚠️ Request failed: ' + err );
						});
				}

				sendBtn.addEventListener( 'click', function () { send(); } );
				newChatBtn.addEventListener( 'click', resetChat );
				input.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Enter' && ! e.shiftKey ) {
						e.preventDefault();
						send();
					}
				});

				resetChat();
			})();
			</script>
		<?php endif; ?>
		<?php
	}

	private function render_playground_tab( $all_tools, $disabled_tools ) {
		$enabled_tools = array();
		foreach ( $all_tools as $name => $tool ) {
			if ( ! in_array( $name, $disabled_tools, true ) ) {
				$enabled_tools[ $name ] = $tool;
			}
		}
		?>
		<div class="wcops-card">
			<h2>Try it out — no external MCP client needed</h2>
			<p>Pick a tool, edit the arguments as JSON, and run it. This calls the real MCP endpoint using your full-access key — exactly what an AI agent would see — so it's a genuine test, not a simulation.</p>

			<table class="form-table">
				<tr>
					<th><label for="wcops-pg-tool">Tool</label></th>
					<td>
						<select id="wcops-pg-tool" style="min-width:320px;">
							<?php foreach ( $enabled_tools as $name => $tool ) : ?>
								<option value="<?php echo esc_attr( $name ); ?>" data-schema='<?php echo esc_attr( wp_json_encode( $tool['inputSchema'] ) ); ?>' data-write="<?php echo ! empty( $tool['is_write'] ) ? '1' : '0'; ?>">
									<?php echo esc_html( $name ); ?><?php echo ! empty( $tool['is_write'] ) ? ' (write)' : ''; ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description" id="wcops-pg-desc"></p>
					</td>
				</tr>
				<tr>
					<th><label for="wcops-pg-args">Arguments (JSON)</label></th>
					<td>
						<textarea id="wcops-pg-args" rows="6" style="width:100%; max-width:600px; font-family:monospace;">{}</textarea>
						<p class="description">e.g. <code>{"order_id": 1, "status": "completed"}</code>. Leave as <code>{}</code> for tools that need no arguments.</p>
					</td>
				</tr>
				<tr id="wcops-pg-dryrun-row">
					<th>Dry run</th>
					<td>
						<label><input type="checkbox" id="wcops-pg-dryrun" checked /> Preview only (recommended - shows what would happen without changing your store)</label>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button button-primary" id="wcops-pg-run">Run Tool</button>
			</p>

			<div id="wcops-pg-result" style="display:none;">
				<h3>Response</h3>
				<pre id="wcops-pg-response" style="background:#1e1e1e; color:#d4d4d4; padding:16px; border-radius:6px; max-height:400px; overflow:auto; font-size:12px;"></pre>
			</div>
		</div>

		<script>
		(function () {
			var toolSelect = document.getElementById('wcops-pg-tool');
			var descEl     = document.getElementById('wcops-pg-desc');
			var argsEl     = document.getElementById('wcops-pg-args');
			var dryRunRow  = document.getElementById('wcops-pg-dryrun-row');
			var dryRunBox  = document.getElementById('wcops-pg-dryrun');
			var runBtn     = document.getElementById('wcops-pg-run');
			var resultWrap = document.getElementById('wcops-pg-result');
			var responseEl = document.getElementById('wcops-pg-response');

			function updateForSelectedTool() {
				var opt = toolSelect.options[toolSelect.selectedIndex];
				if (!opt) { return; }
				var isWrite = opt.getAttribute('data-write') === '1';
				dryRunRow.style.display = isWrite ? '' : 'none';

				var schema = {};
				try { schema = JSON.parse(opt.getAttribute('data-schema') || '{}'); } catch (e) {}

				var props = schema.properties || {};
				var required = schema.required || [];
				var sample = {};
				Object.keys(props).forEach(function (key) {
					if (required.indexOf(key) === -1 && key !== 'dry_run') { return; }
					if (key === 'dry_run') { return; }
					var type = props[key].type;
					if (type === 'integer' || type === 'number') { sample[key] = 0; }
					else if (type === 'boolean') { sample[key] = true; }
					else if (type === 'array') { sample[key] = []; }
					else { sample[key] = ''; }
				});
				argsEl.value = JSON.stringify(sample, null, 2);

				var descParts = [];
				Object.keys(props).forEach(function (key) {
					descParts.push(key + (props[key].description ? ' - ' + props[key].description : ''));
				});
				descEl.textContent = descParts.join(' | ');
			}

			toolSelect.addEventListener('change', updateForSelectedTool);
			updateForSelectedTool();

			runBtn.addEventListener('click', function () {
				runBtn.disabled = true;
				runBtn.textContent = 'Running...';
				resultWrap.style.display = 'block';
				responseEl.textContent = '';

				var opt = toolSelect.options[toolSelect.selectedIndex];
				var isWrite = opt.getAttribute('data-write') === '1';

				var argsText = argsEl.value.trim() || '{}';
				var parsedArgs;
				try {
					parsedArgs = JSON.parse(argsText);
				} catch (e) {
					responseEl.textContent = 'Invalid JSON in arguments field.';
					runBtn.disabled = false;
					runBtn.textContent = 'Run Tool';
					return;
				}

				if (isWrite) {
					parsedArgs.dry_run = dryRunBox.checked;
				}

				var formData = new FormData();
				formData.append('action', 'wc_ops_mcp_run_tool');
				formData.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'wc_ops_mcp_playground' ) ); ?>');
				formData.append('tool_name', toolSelect.value);
				formData.append('arguments', JSON.stringify(parsedArgs));

				fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						runBtn.disabled = false;
						runBtn.textContent = 'Run Tool';
						if (data.success) {
							responseEl.textContent = JSON.stringify(data.data.raw, null, 2);
						} else {
							responseEl.textContent = 'Error: ' + data.data.message;
						}
					})
					.catch(function (err) {
						runBtn.disabled = false;
						runBtn.textContent = 'Run Tool';
						responseEl.textContent = 'Request failed: ' + err;
					});
			});
		})();
		</script>
		<?php
	}

	private function render_settings_tab( $dry_run_default, $max_refund_amount, $max_price_change, $rate_limit ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wc_ops_mcp_save_settings" />
			<?php wp_nonce_field( 'wc_ops_mcp_save_settings' ); ?>

			<div class="wcops-card">
				<h2>Guardrails</h2>
				<p>These limits protect your store from an overly enthusiastic (or misconfigured) AI agent. Defaults are conservative — raise them only once you trust the setup.</p>

				<table class="form-table">
					<tr>
						<th>Dry-run by default</th>
						<td>
							<label>
								<input type="checkbox" name="dry_run_default" <?php checked( 'yes', $dry_run_default ); ?> />
								Require write actions to preview before applying, unless the agent explicitly passes <code>dry_run=false</code>.
							</label>
							<p class="description">Recommended: keep this ON while you're still testing.</p>
						</td>
					</tr>
					<tr>
						<th>Max refund amount</th>
						<td>
							<input type="number" step="0.01" name="max_refund_amount" value="<?php echo esc_attr( $max_refund_amount ); ?>" class="regular-text" />
							<p class="description">Refund requests above this amount will be blocked automatically.</p>
						</td>
					</tr>
					<tr>
						<th>Max price change (%)</th>
						<td>
							<input type="number" step="1" name="max_price_change" value="<?php echo esc_attr( $max_price_change ); ?>" class="regular-text" />
							<p class="description">Bulk price updates beyond this percentage will be blocked automatically.</p>
						</td>
					</tr>
					<tr>
						<th>Rate limit</th>
						<td>
							<input type="number" step="1" name="rate_limit_per_minute" value="<?php echo esc_attr( $rate_limit ); ?>" class="regular-text" /> requests / minute per API key
							<p class="description">Prevents a runaway agent from hammering your store. Set to 0 to disable (not recommended).</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Guardrails' ); ?>
			</div>
		</form>
		<?php
	}

	private function render_help_tab() {
		?>
		<div class="wcops-card">
			<h2>What is this plugin, in plain English?</h2>
			<p>It lets an AI assistant look at your store's real data (orders, stock, sales, customers) and take actions for you (send a recovery email, start a sale, refund an order) — the same way a smart, careful employee would, except it's available 24/7 and never gets tired. Everything it does is capped by limits you control, and everything is logged so you can see exactly what happened.</p>
		</div>

		<div class="wcops-card">
			<h2>1. How to install &amp; set up (5 minutes)</h2>
			<ol style="line-height:2;">
				<li>Make sure WooCommerce is active on your site (this plugin needs it).</li>
				<li>Activate WooCommerce Ops MCP from your Plugins list.</li>
				<li>Go to the <strong>💬 Ask AI</strong> tab.</li>
				<li>Pick <strong>Groq</strong> or <strong>Google Gemini</strong> as your provider — both are free, no credit card. Click the signup link shown, create a free account, copy your API key.</li>
				<li>Paste the key in, click Save. You're done — start typing questions or requests in the chat box.</li>
			</ol>
		</div>

		<div class="wcops-card">
			<h2>2. Choosing an AI provider — you have 9 options</h2>
			<p>This plugin doesn't lock you into one AI company. Pick whichever you prefer:</p>
			<ul style="line-height:1.9; list-style:disc; padding-left:20px;">
				<li><strong>Groq</strong> and <strong>Google Gemini</strong> — genuinely free, no credit card. Best starting point.</li>
				<li><strong>OpenRouter, Cerebras, Mistral</strong> — also have free tiers/models, slightly more setup.</li>
				<li><strong>DeepSeek</strong> — very cheap (not free), good quality.</li>
				<li><strong>OpenAI, Anthropic (Claude), xAI (Grok)</strong> — paid, use these if you already have an account with credits.</li>
				<li><strong>Local / self-hosted</strong> — completely free and fully private if you already run Ollama, LM Studio, or vLLM on your own server (e.g. serving Ornith, DeepSeek, or Llama models). Requires that server to already be set up and running.</li>
			</ul>
			<p><strong>Getting a free Groq key:</strong></p>
			<ol style="line-height:1.9;">
				<li>Go to <a href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com/keys</a></li>
				<li>Sign in with Google or email — no credit card asked</li>
				<li>Click "Create API Key", copy it, paste into the AI provider setup above</li>
			</ol>
			<p><strong>Getting a free Gemini key:</strong></p>
			<ol style="line-height:1.9;">
				<li>Go to <a href="https://ai.google.dev/" target="_blank" rel="noopener">ai.google.dev</a></li>
				<li>Click "Get API key", sign in with your Google account</li>
				<li>Copy the key, paste it in above</li>
			</ol>
			<p class="description">Both of these are real, indefinite free tiers as of 2026 — not a trial that expires. You can switch providers any time from the dropdown without losing your chat history.</p>
		</div>

		<div class="wcops-card">
			<h2>3. How each part of the plugin helps you make more money</h2>

			<h3>💰 Sales &amp; Marketing tools</h3>
			<ul style="line-height:1.9; list-style:disc; padding-left:20px;">
				<li><strong>Flash sales</strong> — start a real, time-limited discount on a category or product list. It starts and ends automatically, so you never forget to revert prices.</li>
				<li><strong>Cross-sells</strong> — link related products so customers see "you might also like" suggestions, increasing how much each customer spends per order.</li>
				<li><strong>Pricing opportunities</strong> — finds your best-selling products that aren't discounted, so you know what's safe to feature or even raise the price on.</li>
				<li><strong>Slow-moving stock</strong> — finds products piling up in your warehouse with low sales, so you can clear them out before they tie up more cash.</li>
				<li><strong>Customer audience export</strong> — creates a list of your customers' emails you can upload to Facebook/Google/TikTok to show ads specifically to people who already bought from you (this is one of the highest-return types of advertising there is).</li>
				<li><strong>Traffic source performance</strong> — once you tag your ad/social links with <code>?utm_source=facebook</code> (or google, instagram, email, etc.), this shows you which channels are actually turning into sales, so you can stop wasting money on ones that aren't.</li>
			</ul>

			<h3>🛒 Revenue Recovery tools</h3>
			<ul style="line-height:1.9; list-style:disc; padding-left:20px;">
				<li><strong>Abandoned cart recovery</strong> — automatically notices when someone leaves items in their cart, and can send them a reminder email (with an optional discount code) to bring them back.</li>
				<li><strong>Lapsed customers</strong> — finds past customers who haven't ordered in a while, so you can win them back with a targeted offer instead of guessing who to email.</li>
			</ul>

			<h3>📦 Orders, Inventory &amp; Products</h3>
			<p>Day-to-day operations — checking stock, updating orders, processing refunds — handled instantly instead of you clicking through wp-admin manually. This frees up time you can spend on marketing and talking to customers instead of admin work.</p>
		</div>

		<div class="wcops-card">
			<h2>4. How this makes customers feel good about your store</h2>
			<ul style="line-height:1.9; list-style:disc; padding-left:20px;">
				<li><strong>Faster responses</strong> — refunds and order updates can happen in seconds instead of waiting for you to be online, which customers notice and appreciate.</li>
				<li><strong>Fewer stockouts</strong> — proactive stock and slow-mover monitoring means popular items are less likely to run out unexpectedly.</li>
				<li><strong>Relevant offers, not spam</strong> — cart recovery and win-back emails are targeted at people who already showed interest, not blasted to everyone, so customers get useful reminders instead of annoying ones.</li>
				<li><strong>Smart product suggestions</strong> — cross-sells mean customers discover genuinely related products, which feels helpful rather than pushy when done well (keep the linked products truly relevant).</li>
			</ul>
		</div>

		<div class="wcops-card">
			<h2>5. Safety — why this won't wreck your store</h2>
			<ul style="line-height:1.9; list-style:disc; padding-left:20px;">
				<li><strong>Guardrails</strong> — hard limits on refund size and price-change percentage, set by you in the Guardrails tab.</li>
				<li><strong>Dry-run by default</strong> — most actions preview what they would do before actually doing it.</li>
				<li><strong>Activity log</strong> — every action taken is recorded, so you can review or investigate anything.</li>
				<li><strong>Per-tool switches</strong> — turn off any individual capability you're not comfortable with in the Tools tab.</li>
				<li><strong>Your data stays yours</strong> — the AI provider (Groq/Gemini/etc.) only sees the specific question you ask and the specific data needed to answer it, not your whole database. Nothing is shared with us (the plugin developer) at all — this all runs on your own server with your own API key.</li>
			</ul>
		</div>
		<?php
	}

	private function render_activity_tab( $logs ) {
		?>
		<div class="wcops-card">
			<h2>Recent Activity</h2>
			<p>Every write action an AI agent takes on this store is logged here, with before/after state.</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Time</th>
						<th>Tool</th>
						<th>Mode</th>
						<th>Status</th>
						<th>Details</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="5">No activity logged yet. Once your agent starts calling write tools, they'll show up here.</td></tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log->created_at ); ?></td>
								<td><code><?php echo esc_html( $log->tool_name ); ?></code></td>
								<td><?php echo $log->is_dry_run ? '<span style="color:#787c82;">Preview only</span>' : '<span style="color:#00a32a;">Applied</span>'; ?></td>
								<td><?php echo 'error' === $log->status ? '<span style="color:#d63638;">Error</span>' : '<span style="color:#00a32a;">Success</span>'; ?></td>
								<td>
									<details>
										<summary>view</summary>
										<pre style="max-width:500px; white-space:pre-wrap;"><?php echo esc_html( $log->input_json ); ?></pre>
									</details>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
