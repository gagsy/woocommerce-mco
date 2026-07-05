<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API key auth for the MCP endpoint - now with two scopes and basic rate limiting.
 *
 * Scopes:
 *  - 'full'     : can call read-only AND write tools
 *  - 'readonly' : can only call tools marked is_write = false
 *
 * This lets a store owner hand a "readonly" key to a reporting/analytics agent
 * without any risk of it touching orders, refunds, or prices.
 */
class WC_Ops_MCP_Auth {

	private static $instance = null;
	private $last_scope = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Validate the request's Authorization: Bearer <key> header
	 * against the full or readonly key stored in wp_options.
	 * On success, stores which scope matched so the server can enforce it.
	 */
	public function validate_request( WP_REST_Request $request ) {
		$full_key     = get_option( 'wc_ops_mcp_api_key', '' );
		$readonly_key = get_option( 'wc_ops_mcp_readonly_api_key', '' );

		if ( empty( $full_key ) && empty( $readonly_key ) ) {
			return new WP_Error(
				'wc_ops_mcp_no_key_configured',
				'No API key has been configured for WooCommerce Ops MCP yet. Set one in WooCommerce > Ops MCP.',
				array( 'status' => 500 )
			);
		}

		$auth_header = $request->get_header( 'authorization' );
		if ( empty( $auth_header ) || ! preg_match( '/Bearer\s+(.+)/i', $auth_header, $matches ) ) {
			return new WP_Error(
				'wc_ops_mcp_missing_auth',
				'Missing Authorization: Bearer <api_key> header.',
				array( 'status' => 401 )
			);
		}

		$provided_key = trim( $matches[1] );

		if ( ! empty( $full_key ) && hash_equals( $full_key, $provided_key ) ) {
			$this->last_scope = 'full';
		} elseif ( ! empty( $readonly_key ) && hash_equals( $readonly_key, $provided_key ) ) {
			$this->last_scope = 'readonly';
		} else {
			return new WP_Error(
				'wc_ops_mcp_invalid_key',
				'Invalid API key.',
				array( 'status' => 403 )
			);
		}

		$rate_check = $this->check_rate_limit( $provided_key );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		return true;
	}

	/**
	 * Which scope authenticated the current request ('full' or 'readonly').
	 */
	public function get_current_scope() {
		return $this->last_scope;
	}

	/**
	 * Simple sliding-window rate limit using transients: N requests per minute per key.
	 */
	private function check_rate_limit( $key ) {
		$limit = (int) get_option( 'wc_ops_mcp_rate_limit_per_minute', 60 );
		if ( $limit <= 0 ) {
			return true; // 0 = unlimited, for advanced users who disable this.
		}

		$bucket_key = 'wc_ops_mcp_rl_' . md5( $key ) . '_' . floor( time() / 60 );
		$count      = (int) get_transient( $bucket_key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'wc_ops_mcp_rate_limited',
				sprintf( 'Rate limit exceeded: max %d requests per minute for this API key.', $limit ),
				array( 'status' => 429 )
			);
		}

		set_transient( $bucket_key, $count + 1, 90 );
		return true;
	}

	/**
	 * Generate a new random API key (used from the settings page).
	 */
	public function generate_key( $prefix = 'wcops' ) {
		return $prefix . '_' . wp_generate_password( 40, false, false );
	}
}
