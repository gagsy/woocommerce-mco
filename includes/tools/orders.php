<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order tools: list, get details, update status, refund, add note.
 */
return array(

	array(
		'name'        => 'list_orders',
		'description' => 'List WooCommerce orders, optionally filtered by status and date range.',
		'is_write'    => false,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'status'   => array(
					'type'        => 'string',
					'description' => "Order status filter, e.g. 'processing', 'completed', 'on-hold', 'refunded'. Omit for all.",
				),
				'after'    => array(
					'type'        => 'string',
					'description' => 'ISO 8601 date. Only return orders created after this date.',
				),
				'before'   => array(
					'type'        => 'string',
					'description' => 'ISO 8601 date. Only return orders created before this date.',
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => 'Max number of orders to return. Default 20, max 100.',
				),
			),
		),
		'callback'    => function ( $args ) {
			$query_args = array(
				'limit'   => min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) ),
				'orderby' => 'date',
				'order'   => 'DESC',
			);

			if ( ! empty( $args['status'] ) ) {
				$query_args['status'] = sanitize_text_field( $args['status'] );
			}
			if ( ! empty( $args['after'] ) ) {
				$query_args['date_created'] = '>' . strtotime( $args['after'] );
			}
			if ( ! empty( $args['before'] ) ) {
				$query_args['date_created'] = '<' . strtotime( $args['before'] );
			}

			$orders = wc_get_orders( $query_args );
			$result = array();

			foreach ( $orders as $order ) {
				$result[] = array(
					'id'           => $order->get_id(),
					'status'       => $order->get_status(),
					'total'        => $order->get_total(),
					'currency'     => $order->get_currency(),
					'customer'     => $order->get_formatted_billing_full_name(),
					'email'        => $order->get_billing_email(),
					'date_created' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
				);
			}

			return array( 'orders' => $result, 'count' => count( $result ) );
		},
	),

	array(
		'name'        => 'get_order_details',
		'description' => 'Get full details for a single order including line items.',
		'is_write'    => false,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'order_id' => array(
					'type'        => 'integer',
					'description' => 'The WooCommerce order ID.',
				),
			),
			'required'   => array( 'order_id' ),
		),
		'callback'    => function ( $args ) {
			$order = wc_get_order( (int) $args['order_id'] );
			if ( ! $order ) {
				return array( 'error' => 'Order not found.' );
			}

			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = array(
					'name'     => $item->get_name(),
					'quantity' => $item->get_quantity(),
					'total'    => $item->get_total(),
					'sku'      => $item->get_product() ? $item->get_product()->get_sku() : null,
				);
			}

			return array(
				'id'              => $order->get_id(),
				'status'          => $order->get_status(),
				'total'           => $order->get_total(),
				'currency'        => $order->get_currency(),
				'payment_method'  => $order->get_payment_method_title(),
				'customer_note'   => $order->get_customer_note(),
				'billing_email'   => $order->get_billing_email(),
				'billing_name'    => $order->get_formatted_billing_full_name(),
				'shipping_address'=> $order->get_formatted_shipping_address(),
				'line_items'      => $items,
				'date_created'    => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			);
		},
	),

	array(
		'name'        => 'update_order_status',
		'description' => 'Update the status of an order (e.g. processing -> completed). Honors dry_run.',
		'is_write'    => true,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'order_id' => array( 'type' => 'integer', 'description' => 'Order ID to update.' ),
				'status'   => array( 'type' => 'string', 'description' => "New status, e.g. 'completed', 'processing', 'cancelled', 'on-hold'." ),
				'note'     => array( 'type' => 'string', 'description' => 'Optional note to attach explaining the change.' ),
				'dry_run'  => array( 'type' => 'boolean', 'description' => 'If true, preview the change without applying it. Defaults to the site-wide setting.' ),
			),
			'required'   => array( 'order_id', 'status' ),
		),
		'callback'    => function ( $args ) {
			$order = wc_get_order( (int) $args['order_id'] );
			if ( ! $order ) {
				return array( 'error' => 'Order not found.' );
			}

			$before = array( 'status' => $order->get_status() );
			$is_dry = WC_Ops_MCP_Tools::is_dry_run( $args );

			if ( $is_dry ) {
				return array(
					'dry_run' => true,
					'preview' => array(
						'order_id'    => $order->get_id(),
						'from_status' => $before['status'],
						'to_status'   => sanitize_text_field( $args['status'] ),
					),
					'message' => 'Dry run only. Pass dry_run=false to apply this change.',
				);
			}

			$order->update_status( sanitize_text_field( $args['status'] ), $args['note'] ?? '' );

			return array(
				'success'  => true,
				'order_id' => $order->get_id(),
				'before'   => $before,
				'after'    => array( 'status' => $order->get_status() ),
			);
		},
	),

	array(
		'name'        => 'create_refund',
		'description' => 'Create a full or partial refund for an order. Blocked above the configured max refund guardrail. Honors dry_run.',
		'is_write'    => true,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'order_id' => array( 'type' => 'integer', 'description' => 'Order ID to refund.' ),
				'amount'   => array( 'type' => 'number', 'description' => 'Refund amount. Omit to refund the full order total.' ),
				'reason'   => array( 'type' => 'string', 'description' => 'Reason for the refund.' ),
				'dry_run'  => array( 'type' => 'boolean', 'description' => 'If true, preview only. Defaults to site-wide setting.' ),
			),
			'required'   => array( 'order_id' ),
		),
		'callback'    => function ( $args ) {
			$order = wc_get_order( (int) $args['order_id'] );
			if ( ! $order ) {
				return array( 'error' => 'Order not found.' );
			}

			$amount = isset( $args['amount'] ) ? (float) $args['amount'] : (float) $order->get_total();
			$max    = WC_Ops_MCP_Tools::max_refund_amount();

			if ( $amount > $max ) {
				return array(
					'error'   => sprintf(
						'Refund amount %s exceeds the configured guardrail of %s. Adjust the limit in WooCommerce > Ops MCP settings if this is intentional.',
						wc_format_decimal( $amount ),
						wc_format_decimal( $max )
					),
					'blocked' => true,
				);
			}

			$is_dry = WC_Ops_MCP_Tools::is_dry_run( $args );

			if ( $is_dry ) {
				return array(
					'dry_run' => true,
					'preview' => array(
						'order_id' => $order->get_id(),
						'amount'   => $amount,
						'reason'   => $args['reason'] ?? '',
					),
					'message' => 'Dry run only. Pass dry_run=false to apply this refund.',
				);
			}

			$refund = wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount'   => $amount,
					'reason'   => sanitize_text_field( $args['reason'] ?? '' ),
				)
			);

			if ( is_wp_error( $refund ) ) {
				return array( 'error' => $refund->get_error_message() );
			}

			return array(
				'success'   => true,
				'order_id'  => $order->get_id(),
				'refund_id' => $refund->get_id(),
				'amount'    => $amount,
			);
		},
	),

	array(
		'name'        => 'add_order_note',
		'description' => 'Add an internal note (not visible to customer) to an order.',
		'is_write'    => true,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'order_id' => array( 'type' => 'integer' ),
				'note'     => array( 'type' => 'string' ),
			),
			'required'   => array( 'order_id', 'note' ),
		),
		'callback'    => function ( $args ) {
			$order = wc_get_order( (int) $args['order_id'] );
			if ( ! $order ) {
				return array( 'error' => 'Order not found.' );
			}
			$order->add_order_note( sanitize_textarea_field( $args['note'] ) );
			return array( 'success' => true, 'order_id' => $order->get_id() );
		},
	),
);
