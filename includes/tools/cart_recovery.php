<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Revenue-focused tools: abandoned cart recovery, coupon generation, churn signals.
 * These are the tools that justify a higher price tier - they act, not just report.
 */
return array(

	array(
		'name'        => 'list_abandoned_carts',
		'description' => 'List carts flagged as abandoned (inactive 1+ hour with a known email), with contents and value, sorted by highest value first.',
		'is_write'    => false,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'min_value' => array( 'type' => 'number', 'description' => 'Only return carts worth at least this much. Default 0.' ),
				'per_page'  => array( 'type' => 'integer', 'description' => 'Max results. Default 25.' ),
			),
		),
		'callback'    => function ( $args ) {
			global $wpdb;
			$table     = $wpdb->prefix . 'wc_ops_mcp_carts';
			$min_value = isset( $args['min_value'] ) ? (float) $args['min_value'] : 0;
			$per_page  = min( 100, max( 1, (int) ( $args['per_page'] ?? 25 ) ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					 WHERE status = 'abandoned' AND cart_total >= %f
					 ORDER BY cart_total DESC
					 LIMIT %d",
					$min_value,
					$per_page
				)
			);

			$result = array();
			foreach ( $rows as $row ) {
				$result[] = array(
					'cart_id'        => $row->id,
					'customer_email' => $row->customer_email,
					'cart_total'     => (float) $row->cart_total,
					'items'          => json_decode( $row->cart_contents, true ),
					'last_active'    => $row->updated_at,
					'recovery_sent'  => ! empty( $row->recovery_sent_at ),
				);
			}

			return array( 'abandoned_carts' => $result, 'count' => count( $result ) );
		},
	),

	array(
		'name'        => 'create_recovery_coupon',
		'description' => 'Generate a single-use, time-limited discount coupon, typically to send with a cart recovery email. Honors dry_run.',
		'is_write'    => true,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'discount_percent' => array( 'type' => 'number', 'description' => 'Percent discount, e.g. 10.' ),
				'expires_in_hours' => array( 'type' => 'integer', 'description' => 'Hours until the coupon expires. Default 48.' ),
				'restrict_email'   => array( 'type' => 'string', 'description' => 'If set, coupon only usable by this email address.' ),
				'dry_run'          => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'discount_percent' ),
		),
		'callback'    => function ( $args ) {
			$percent    = (float) $args['discount_percent'];
			$expires_in = (int) ( $args['expires_in_hours'] ?? 48 );
			$is_dry     = WC_Ops_MCP_Tools::is_dry_run( $args );
			$code       = 'WIN' . strtoupper( wp_generate_password( 6, false, false ) );

			if ( $is_dry ) {
				return array(
					'dry_run' => true,
					'preview' => array(
						'code'             => $code,
						'discount_percent' => $percent,
						'expires_in_hours' => $expires_in,
						'restrict_email'   => $args['restrict_email'] ?? null,
					),
					'message' => 'Dry run only. Pass dry_run=false to actually create this coupon.',
				);
			}

			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( $percent );
			$coupon->set_individual_use( true );
			$coupon->set_usage_limit( 1 );
			$coupon->set_date_expires( time() + ( $expires_in * HOUR_IN_SECONDS ) );

			if ( ! empty( $args['restrict_email'] ) && is_email( $args['restrict_email'] ) ) {
				$coupon->set_email_restrictions( array( sanitize_email( $args['restrict_email'] ) ) );
			}

			$coupon->save();

			return array(
				'success'          => true,
				'code'             => $code,
				'discount_percent' => $percent,
				'expires_at'       => gmdate( 'c', time() + ( $expires_in * HOUR_IN_SECONDS ) ),
			);
		},
	),

	array(
		'name'        => 'send_cart_recovery_email',
		'description' => 'Send a recovery email for an abandoned cart, optionally including a coupon code. Honors dry_run.',
		'is_write'    => true,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'cart_id'      => array( 'type' => 'integer', 'description' => 'ID from list_abandoned_carts.' ),
				'coupon_code'  => array( 'type' => 'string', 'description' => 'Optional coupon code to include, e.g. from create_recovery_coupon.' ),
				'custom_message' => array( 'type' => 'string', 'description' => 'Optional custom message to include in the email body.' ),
				'dry_run'      => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'cart_id' ),
		),
		'callback'    => function ( $args ) {
			global $wpdb;
			$table = $wpdb->prefix . 'wc_ops_mcp_carts';
			$cart  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $args['cart_id'] ) );

			if ( ! $cart ) {
				return array( 'error' => 'Cart not found.' );
			}
			if ( empty( $cart->customer_email ) ) {
				return array( 'error' => 'This cart has no known email address to send to.' );
			}

			$is_dry = WC_Ops_MCP_Tools::is_dry_run( $args );

			$items      = json_decode( $cart->cart_contents, true );
			$item_lines = array();
			foreach ( (array) $items as $item ) {
				$item_lines[] = sprintf( '%s x %d', $item['name'], $item['quantity'] );
			}

			$subject = 'You left something in your cart';
			$body    = "Hi,\n\nYou still have items waiting in your cart:\n\n" . implode( "\n", $item_lines );
			$body   .= "\n\nCart total: " . wc_price( $cart->cart_total );

			if ( ! empty( $args['coupon_code'] ) ) {
				$body .= "\n\nUse code " . sanitize_text_field( $args['coupon_code'] ) . " for a discount on your order.";
			}
			if ( ! empty( $args['custom_message'] ) ) {
				$body .= "\n\n" . sanitize_textarea_field( $args['custom_message'] );
			}

			if ( $is_dry ) {
				return array(
					'dry_run' => true,
					'preview' => array(
						'to'      => $cart->customer_email,
						'subject' => $subject,
						'body'    => $body,
					),
					'message' => 'Dry run only. Pass dry_run=false to actually send this email.',
				);
			}

			$sent = wp_mail( $cart->customer_email, $subject, $body );

			if ( $sent ) {
				$wpdb->update(
					$table,
					array(
						'recovery_sent_at' => current_time( 'mysql' ),
						'recovery_coupon'  => sanitize_text_field( $args['coupon_code'] ?? '' ),
					),
					array( 'id' => $cart->id )
				);
			}

			return array( 'success' => (bool) $sent, 'sent_to' => $cart->customer_email );
		},
	),

	array(
		'name'        => 'find_lapsed_customers',
		'description' => 'Find previously active customers who have not ordered in N days, sorted by their historical spend (highest-value lapsed customers first).',
		'is_write'    => false,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'days_since_last_order' => array( 'type' => 'integer', 'description' => 'Minimum days since their last order. Default 60.' ),
				'min_past_orders'       => array( 'type' => 'integer', 'description' => 'Minimum number of past orders to qualify as "previously active". Default 2.' ),
				'per_page'              => array( 'type' => 'integer', 'description' => 'Max results. Default 25.' ),
			),
		),
		'callback'    => function ( $args ) {
			global $wpdb;

			$days     = (int) ( $args['days_since_last_order'] ?? 60 );
			$min_orders = (int) ( $args['min_past_orders'] ?? 2 );
			$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 25 ) ) );
			$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			$sql = $wpdb->prepare(
				"SELECT pm.meta_value AS customer_email,
				        COUNT(p.ID) AS order_count,
				        SUM(pm_total.meta_value) AS lifetime_value,
				        MAX(p.post_date) AS last_order_date
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
				 INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-completed','wc-processing')
				 GROUP BY pm.meta_value
				 HAVING order_count >= %d AND last_order_date < %s
				 ORDER BY lifetime_value DESC
				 LIMIT %d",
				$min_orders,
				$cutoff,
				$per_page
			);

			$rows   = $wpdb->get_results( $sql );
			$result = array();
			foreach ( $rows as $row ) {
				$result[] = array(
					'customer_email'  => $row->customer_email,
					'past_orders'     => (int) $row->order_count,
					'lifetime_value'  => round( (float) $row->lifetime_value, 2 ),
					'last_order_date' => $row->last_order_date,
				);
			}

			return array( 'lapsed_customers' => $result, 'count' => count( $result ) );
		},
	),
);
