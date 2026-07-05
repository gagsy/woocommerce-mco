<?php
/**
 * Plugin Name: WooCommerce Ops MCP
 * Plugin URI: https://mcpmarket.com
 * Description: Exposes WooCommerce store operations (orders, inventory, products, reports) as MCP tools so AI agents can run day-to-day store ops via JSON-RPC/MCP, with audit logging and guardrails.
 * Version: 1.0.0
 * Author: Gagan
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: wc-ops-mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WC_OPS_MCP_VERSION', '1.0.0' );
define( 'WC_OPS_MCP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_OPS_MCP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check WooCommerce is active before doing anything.
 */
function wc_ops_mcp_check_dependencies() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p><strong>WooCommerce Ops MCP</strong> requires WooCommerce to be installed and active.</p></div>';
			}
		);
		return false;
	}
	return true;
}

require_once WC_OPS_MCP_PATH . 'includes/class-mcp-auth.php';
require_once WC_OPS_MCP_PATH . 'includes/class-mcp-audit.php';
require_once WC_OPS_MCP_PATH . 'includes/class-mcp-cart-tracker.php';
require_once WC_OPS_MCP_PATH . 'includes/class-mcp-attribution.php';
require_once WC_OPS_MCP_PATH . 'includes/class-mcp-tools.php';
require_once WC_OPS_MCP_PATH . 'includes/class-mcp-server.php';
require_once WC_OPS_MCP_PATH . 'includes/class-mcp-ai-agent.php';

/**
 * Init plugin.
 */
function wc_ops_mcp_init() {
	if ( ! wc_ops_mcp_check_dependencies() ) {
		return;
	}

	WC_Ops_MCP_Audit::instance()->maybe_create_table();
	WC_Ops_MCP_Cart_Tracker::instance()->maybe_create_table();
	WC_Ops_MCP_Cart_Tracker::instance()->register_hooks();
	WC_Ops_MCP_Attribution::instance()->register_hooks();
	WC_Ops_MCP_Server::instance()->register_routes();

	if ( is_admin() ) {
		require_once WC_OPS_MCP_PATH . 'admin/settings-page.php';
		new WC_Ops_MCP_Settings_Page();
	}
}
add_action( 'plugins_loaded', 'wc_ops_mcp_init' );

/**
 * Activation: create audit table + default options.
 */
function wc_ops_mcp_activate() {
	require_once WC_OPS_MCP_PATH . 'includes/class-mcp-audit.php';
	require_once WC_OPS_MCP_PATH . 'includes/class-mcp-cart-tracker.php';
	WC_Ops_MCP_Audit::instance()->maybe_create_table();
	WC_Ops_MCP_Cart_Tracker::instance()->maybe_create_table();

	$defaults = array(
		'dry_run_default'    => 'yes',
		'max_refund_amount'  => '5000',
		'max_price_change'   => '25', // percent
		'api_key'            => '',
	);
	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( 'wc_ops_mcp_' . $key, false ) ) {
			add_option( 'wc_ops_mcp_' . $key, $value );
		}
	}
}
register_activation_hook( __FILE__, 'wc_ops_mcp_activate' );

/**
 * Deactivation: clear scheduled cron event.
 */
function wc_ops_mcp_deactivate() {
	$timestamp = wp_next_scheduled( 'wc_ops_mcp_mark_abandoned_carts' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wc_ops_mcp_mark_abandoned_carts' );
	}
}
register_deactivation_hook( __FILE__, 'wc_ops_mcp_deactivate' );
