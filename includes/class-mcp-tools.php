<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registry of MCP tools.
 * Each tools/*.php file returns an array of tool definitions:
 * [
 *   'name'        => 'list_orders',
 *   'description' => '...',
 *   'inputSchema' => [ ... JSON schema ... ],
 *   'is_write'    => false,
 *   'callback'    => callable( array $args ) : array
 * ]
 */
class WC_Ops_MCP_Tools {

	private static $instance = null;
	private $tools = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_tools();
	}

	private function load_tools() {
		$files = array(
			'orders.php',
			'inventory.php',
			'products.php',
			'reports.php',
			'cart_recovery.php',
			'marketing.php',
		);

		foreach ( $files as $file ) {
			$path = WC_OPS_MCP_PATH . 'includes/tools/' . $file;
			if ( file_exists( $path ) ) {
				$defs = include $path;
				if ( is_array( $defs ) ) {
					foreach ( $defs as $tool ) {
						$this->tools[ $tool['name'] ] = $tool;
					}
				}
			}
		}
	}

	public function get_all() {
		return $this->tools;
	}

	public function get( $name ) {
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
	}

	/**
	 * Tools disabled by the store owner in the admin dashboard (per-tool kill switch).
	 * Stored as a simple array of tool names in wp_options.
	 */
	public function get_disabled_tools() {
		$disabled = get_option( 'wc_ops_mcp_disabled_tools', array() );
		return is_array( $disabled ) ? $disabled : array();
	}

	public function is_tool_enabled( $name ) {
		return ! in_array( $name, $this->get_disabled_tools(), true );
	}

	/**
	 * MCP tools/list response format. Only includes tools the store owner has enabled.
	 */
	public function get_tools_list_schema( $scope = 'full' ) {
		$list     = array();
		$disabled = $this->get_disabled_tools();

		foreach ( $this->tools as $tool ) {
			if ( in_array( $tool['name'], $disabled, true ) ) {
				continue;
			}
			if ( 'readonly' === $scope && ! empty( $tool['is_write'] ) ) {
				continue;
			}
			$list[] = array(
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'],
			);
		}
		return $list;
	}

	/**
	 * Shared guardrail helpers, usable by any tool file.
	 */
	public static function is_dry_run( $args ) {
		if ( isset( $args['dry_run'] ) ) {
			return (bool) $args['dry_run'];
		}
		return 'yes' === get_option( 'wc_ops_mcp_dry_run_default', 'yes' );
	}

	public static function max_refund_amount() {
		return (float) get_option( 'wc_ops_mcp_max_refund_amount', 5000 );
	}

	public static function max_price_change_percent() {
		return (float) get_option( 'wc_ops_mcp_max_price_change', 25 );
	}
}
