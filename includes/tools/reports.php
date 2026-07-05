<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight reporting tools - just enough to justify "ops", not full BI.
 */
return array(

	array(
		'name'        => 'daily_sales_summary',
		'description' => 'Get total sales, order count, and average order value for a given date (default: today).',
		'is_write'    => false,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'date' => array( 'type' => 'string', 'description' => "Date in YYYY-MM-DD format. Defaults to today." ),
			),
		),
		'callback'    => function ( $args ) {
			$date = ! empty( $args['date'] ) ? sanitize_text_field( $args['date'] ) : gmdate( 'Y-m-d' );

			$orders = wc_get_orders(
				array(
					'status'       => array( 'completed', 'processing' ),
					'date_created' => $date,
					'limit'        => -1,
				)
			);

			$total = 0;
			foreach ( $orders as $order ) {
				$total += (float) $order->get_total();
			}
			$count = count( $orders );

			return array(
				'date'             => $date,
				'order_count'      => $count,
				'total_sales'      => round( $total, 2 ),
				'average_order'    => $count > 0 ? round( $total / $count, 2 ) : 0,
			);
		},
	),

	array(
		'name'        => 'top_products_by_period',
		'description' => 'Get best-selling products between two dates.',
		'is_write'    => false,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'after'    => array( 'type' => 'string', 'description' => 'Start date, YYYY-MM-DD.' ),
				'before'   => array( 'type' => 'string', 'description' => 'End date, YYYY-MM-DD.' ),
				'limit'    => array( 'type' => 'integer', 'description' => 'Number of top products to return. Default 10.' ),
			),
			'required'   => array( 'after', 'before' ),
		),
		'callback'    => function ( $args ) {
			global $wpdb;

			$after  = sanitize_text_field( $args['after'] );
			$before = sanitize_text_field( $args['before'] );
			$limit  = min( 50, max( 1, (int) ( $args['limit'] ?? 10 ) ) );

			$sql = $wpdb->prepare(
				"SELECT oi.order_item_name AS name,
				        SUM(oim_qty.meta_value) AS qty_sold
				 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
				     ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
				 INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
				 WHERE oi.order_item_type = 'line_item'
				   AND p.post_date >= %s AND p.post_date <= %s
				   AND p.post_status IN ('wc-completed','wc-processing')
				 GROUP BY oi.order_item_name
				 ORDER BY qty_sold DESC
				 LIMIT %d",
				$after . ' 00:00:00',
				$before . ' 23:59:59',
				$limit
			);

			$rows = $wpdb->get_results( $sql );

			$result = array();
			foreach ( $rows as $row ) {
				$result[] = array(
					'product_name' => $row->name,
					'quantity_sold' => (int) $row->qty_sold,
				);
			}

			return array( 'after' => $after, 'before' => $before, 'top_products' => $result );
		},
	),
);
