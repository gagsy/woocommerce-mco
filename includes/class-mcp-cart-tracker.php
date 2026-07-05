<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks carts natively - no dependency on a third-party recovery plugin.
 * Captures cart contents + email (once known) into a custom table,
 * and periodically flags carts as "abandoned" via WP-Cron.
 */
class WC_Ops_MCP_Cart_Tracker {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_ops_mcp_carts';
	}

	public function maybe_create_table() {
		global $wpdb;
		$table_name      = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(64) NOT NULL,
			customer_email VARCHAR(190) NULL,
			cart_contents LONGTEXT NULL,
			cart_total DECIMAL(19,4) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			recovery_sent_at DATETIME NULL,
			recovery_coupon VARCHAR(50) NULL,
			order_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY session_key (session_key),
			KEY status (status),
			KEY customer_email (customer_email)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function register_hooks() {
		// Capture/update cart snapshot whenever the cart changes.
		add_action( 'woocommerce_add_to_cart', array( $this, 'snapshot_cart' ) );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'snapshot_cart' ) );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'snapshot_cart' ) );

		// Capture email as soon as it's typed at checkout, even before order placed.
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_checkout_email' ) );

		// Mark cart as converted once an order is placed, so it's excluded from "abandoned".
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'mark_converted' ), 10, 1 );

		// Hourly cron to flag stale carts as abandoned.
		add_action( 'wc_ops_mcp_mark_abandoned_carts', array( $this, 'mark_abandoned_carts' ) );
		if ( ! wp_next_scheduled( 'wc_ops_mcp_mark_abandoned_carts' ) ) {
			wp_schedule_event( time(), 'hourly', 'wc_ops_mcp_mark_abandoned_carts' );
		}
	}

	private function get_session_key() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		return WC()->session->get_customer_id();
	}

	public function snapshot_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$session_key = $this->get_session_key();
		if ( ! $session_key ) {
			return;
		}

		global $wpdb;

		$contents = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'];
			$contents[] = array(
				'product_id' => $item['product_id'],
				'name'       => $product ? $product->get_name() : '',
				'quantity'   => $item['quantity'],
				'line_total' => $item['line_total'],
			);
		}

		$email = '';
		if ( is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$email = $user->user_email;
		} elseif ( WC()->customer ) {
			$email = WC()->customer->get_billing_email();
		}

		$table = $this->table_name();
		$now   = current_time( 'mysql' );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE session_key = %s", $session_key ) );

		$data = array(
			'session_key'    => $session_key,
			'customer_email' => $email ? sanitize_email( $email ) : null,
			'cart_contents'  => wp_json_encode( $contents ),
			'cart_total'     => (float) WC()->cart->get_total( 'edit' ),
			'status'         => 'active',
			'updated_at'     => $now,
		);

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing->id ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data );
		}
	}

	public function capture_checkout_email( $posted_data ) {
		parse_str( $posted_data, $fields );
		if ( empty( $fields['billing_email'] ) || ! is_email( $fields['billing_email'] ) ) {
			return;
		}

		$session_key = $this->get_session_key();
		if ( ! $session_key ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			$this->table_name(),
			array(
				'customer_email' => sanitize_email( $fields['billing_email'] ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'session_key' => $session_key )
		);
	}

	public function mark_converted( $order_id ) {
		$session_key = $this->get_session_key();
		if ( ! $session_key ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			$this->table_name(),
			array(
				'status'     => 'converted',
				'order_id'   => $order_id,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'session_key' => $session_key )
		);
	}

	/**
	 * Cron job: any cart untouched for 1+ hour, still 'active', with a known email, becomes 'abandoned'.
	 */
	public function mark_abandoned_carts() {
		global $wpdb;
		$table = $this->table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'abandoned'
				 WHERE status = 'active'
				   AND updated_at < %s
				   AND customer_email IS NOT NULL
				   AND customer_email != ''",
				$cutoff
			)
		);
	}
}
