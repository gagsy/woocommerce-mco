<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventory tools: stock levels, bulk stock updates, variant sync.
 */
return array(

	array(
		'name'        => 'check_stock_levels',
		'description' => 'List low-stock and out-of-stock products, using the fast product meta lookup table.',
		'is_write'    => false,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'threshold' => array(
					'type'        => 'integer',
					'description' => 'Stock quantity at or below which a product is considered low-stock. Default 5.',
				),
				'per_page'  => array(
					'type'        => 'integer',
					'description' => 'Max results. Default 50.',
				),
			),
		),
		'callback'    => function ( $args ) {
			global $wpdb;

			$threshold = isset( $args['threshold'] ) ? (int) $args['threshold'] : 5;
			$per_page  = min( 200, max( 1, (int) ( $args['per_page'] ?? 50 ) ) );

			$sql = $wpdb->prepare(
				"SELECT product_id, stock_quantity, stock_status
				 FROM {$wpdb->wc_product_meta_lookup}
				 WHERE stock_status IN ('outofstock','onbackorder')
				    OR (stock_quantity IS NOT NULL AND stock_quantity <= %d)
				 ORDER BY stock_quantity ASC
				 LIMIT %d",
				$threshold,
				$per_page
			);

			$rows   = $wpdb->get_results( $sql );
			$result = array();

			foreach ( $rows as $row ) {
				$product = wc_get_product( $row->product_id );
				if ( ! $product ) {
					continue;
				}
				$result[] = array(
					'product_id'     => $row->product_id,
					'name'           => $product->get_name(),
					'sku'            => $product->get_sku(),
					'stock_quantity' => $row->stock_quantity,
					'stock_status'   => $row->stock_status,
				);
			}

			return array( 'products' => $result, 'count' => count( $result ) );
		},
	),

	array(
		'name'        => 'bulk_update_stock',
		'description' => 'Update stock quantity for multiple products at once, matched by SKU. Honors dry_run.',
		'is_write'    => true,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'updates' => array(
					'type'        => 'array',
					'description' => 'List of { sku, quantity } objects.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'sku'      => array( 'type' => 'string' ),
							'quantity' => array( 'type' => 'integer' ),
						),
						'required'   => array( 'sku', 'quantity' ),
					),
				),
				'dry_run' => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'updates' ),
		),
		'callback'    => function ( $args ) {
			$is_dry  = WC_Ops_MCP_Tools::is_dry_run( $args );
			$results = array();

			foreach ( $args['updates'] as $update ) {
				$sku       = sanitize_text_field( $update['sku'] );
				$new_qty   = (int) $update['quantity'];
				$product_id = wc_get_product_id_by_sku( $sku );

				if ( ! $product_id ) {
					$results[] = array( 'sku' => $sku, 'error' => 'SKU not found.' );
					continue;
				}

				$product = wc_get_product( $product_id );
				$old_qty = $product->get_stock_quantity();

				if ( $is_dry ) {
					$results[] = array(
						'sku'          => $sku,
						'product_id'   => $product_id,
						'from_quantity'=> $old_qty,
						'to_quantity'  => $new_qty,
						'dry_run'      => true,
					);
					continue;
				}

				$product->set_stock_quantity( $new_qty );
				$product->set_stock_status( $new_qty > 0 ? 'instock' : 'outofstock' );
				$product->save();

				$results[] = array(
					'sku'           => $sku,
					'product_id'    => $product_id,
					'from_quantity' => $old_qty,
					'to_quantity'   => $new_qty,
					'success'       => true,
				);
			}

			return array( 'dry_run' => $is_dry, 'results' => $results );
		},
	),
);
