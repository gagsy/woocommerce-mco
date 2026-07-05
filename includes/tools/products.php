<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product tools: search, bulk price update, visibility toggle.
 */
return array(

	array(
		'name'        => 'find_products',
		'description' => 'Search products by name, SKU, or category.',
		'is_write'    => false,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'search'   => array( 'type' => 'string', 'description' => 'Search term matched against product name/SKU.' ),
				'category' => array( 'type' => 'string', 'description' => 'Category slug to filter by.' ),
				'per_page' => array( 'type' => 'integer', 'description' => 'Max results. Default 20.' ),
			),
		),
		'callback'    => function ( $args ) {
			$query_args = array(
				'limit'  => min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) ),
				'status' => 'publish',
			);

			if ( ! empty( $args['search'] ) ) {
				$query_args['s'] = sanitize_text_field( $args['search'] );
			}
			if ( ! empty( $args['category'] ) ) {
				$query_args['category'] = array( sanitize_text_field( $args['category'] ) );
			}

			$products = wc_get_products( $query_args );
			$result   = array();

			foreach ( $products as $product ) {
				$result[] = array(
					'id'             => $product->get_id(),
					'name'           => $product->get_name(),
					'sku'            => $product->get_sku(),
					'price'          => $product->get_price(),
					'stock_quantity' => $product->get_stock_quantity(),
					'stock_status'   => $product->get_stock_status(),
				);
			}

			return array( 'products' => $result, 'count' => count( $result ) );
		},
	),

	array(
		'name'        => 'bulk_price_update',
		'description' => 'Apply a percentage or fixed-amount price change to products in a category or a specific SKU list. Blocked above the configured max % guardrail. Honors dry_run.',
		'is_write'    => true,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'category'      => array( 'type' => 'string', 'description' => 'Category slug to target. Omit if using skus.' ),
				'skus'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Specific SKUs to target. Omit if using category.' ),
				'change_type'   => array( 'type' => 'string', 'description' => "'percent' or 'fixed'." ),
				'change_value'  => array( 'type' => 'number', 'description' => "e.g. 10 for +10%, or -5 for -5, depending on change_type." ),
				'dry_run'       => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'change_type', 'change_value' ),
		),
		'callback'    => function ( $args ) {
			$change_type  = sanitize_text_field( $args['change_type'] );
			$change_value = (float) $args['change_value'];

			if ( 'percent' === $change_type && abs( $change_value ) > WC_Ops_MCP_Tools::max_price_change_percent() ) {
				return array(
					'error'   => sprintf(
						'Requested %s%% change exceeds the configured guardrail of %s%%. Adjust the limit in WooCommerce > Ops MCP settings if intentional.',
						$change_value,
						WC_Ops_MCP_Tools::max_price_change_percent()
					),
					'blocked' => true,
				);
			}

			$products = array();
			if ( ! empty( $args['skus'] ) ) {
				foreach ( $args['skus'] as $sku ) {
					$id = wc_get_product_id_by_sku( sanitize_text_field( $sku ) );
					if ( $id ) {
						$products[] = wc_get_product( $id );
					}
				}
			} elseif ( ! empty( $args['category'] ) ) {
				$products = wc_get_products(
					array(
						'category' => array( sanitize_text_field( $args['category'] ) ),
						'limit'    => -1,
					)
				);
			} else {
				return array( 'error' => 'Provide either "category" or "skus".' );
			}

			$is_dry  = WC_Ops_MCP_Tools::is_dry_run( $args );
			$results = array();

			foreach ( $products as $product ) {
				$old_price = (float) $product->get_regular_price();
				if ( 'percent' === $change_type ) {
					$new_price = round( $old_price * ( 1 + ( $change_value / 100 ) ), 2 );
				} else {
					$new_price = round( $old_price + $change_value, 2 );
				}
				$new_price = max( 0, $new_price );

				if ( $is_dry ) {
					$results[] = array(
						'product_id' => $product->get_id(),
						'sku'        => $product->get_sku(),
						'from_price' => $old_price,
						'to_price'   => $new_price,
						'dry_run'    => true,
					);
					continue;
				}

				$product->set_regular_price( $new_price );
				$product->save();

				$results[] = array(
					'product_id' => $product->get_id(),
					'sku'        => $product->get_sku(),
					'from_price' => $old_price,
					'to_price'   => $new_price,
					'success'    => true,
				);
			}

			return array( 'dry_run' => $is_dry, 'results' => $results, 'count' => count( $results ) );
		},
	),

	array(
		'name'        => 'toggle_product_visibility',
		'description' => 'Publish, draft, or hide a product from the catalog.',
		'is_write'    => true,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'product_id' => array( 'type' => 'integer' ),
				'status'     => array( 'type' => 'string', 'description' => "'publish', 'draft', or 'private'." ),
			),
			'required'   => array( 'product_id', 'status' ),
		),
		'callback'    => function ( $args ) {
			$product = wc_get_product( (int) $args['product_id'] );
			if ( ! $product ) {
				return array( 'error' => 'Product not found.' );
			}

			$before = $product->get_status();
			wp_update_post(
				array(
					'ID'          => $product->get_id(),
					'post_status' => sanitize_text_field( $args['status'] ),
				)
			);

			return array(
				'success'    => true,
				'product_id' => $product->get_id(),
				'before'     => $before,
				'after'      => sanitize_text_field( $args['status'] ),
			);
		},
	),
);
