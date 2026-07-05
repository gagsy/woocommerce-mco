<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures utm_source/utm_medium/utm_campaign from the landing URL into a
 * cookie, then stamps it onto the order at checkout. This is what makes
 * "which ads/channels actually drive sales" answerable later via the
 * traffic_source_performance tool - without needing Google Analytics access.
 */
class WC_Ops_MCP_Attribution {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_hooks() {
		add_action( 'wp', array( $this, 'maybe_capture_utm' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'stamp_order_with_utm' ) );
	}

	public function maybe_capture_utm() {
		if ( is_admin() || headers_sent() ) {
			return;
		}
		if ( empty( $_GET['utm_source'] ) ) {
			return;
		}

		$data = array(
			'source'   => sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ),
			'medium'   => isset( $_GET['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) ) : '',
			'campaign' => isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : '',
		);

		setcookie( 'wc_ops_mcp_utm', wp_json_encode( $data ), time() + 30 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN );
	}

	public function stamp_order_with_utm( $order_id ) {
		if ( empty( $_COOKIE['wc_ops_mcp_utm'] ) ) {
			return;
		}

		$data = json_decode( stripslashes( $_COOKIE['wc_ops_mcp_utm'] ), true );
		if ( ! is_array( $data ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! empty( $data['source'] ) ) {
			$order->update_meta_data( '_wc_ops_mcp_utm_source', sanitize_text_field( $data['source'] ) );
		}
		if ( ! empty( $data['medium'] ) ) {
			$order->update_meta_data( '_wc_ops_mcp_utm_medium', sanitize_text_field( $data['medium'] ) );
		}
		if ( ! empty( $data['campaign'] ) ) {
			$order->update_meta_data( '_wc_ops_mcp_utm_campaign', sanitize_text_field( $data['campaign'] ) );
		}
		$order->save();
	}
}
