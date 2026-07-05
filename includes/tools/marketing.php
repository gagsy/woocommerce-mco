<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sales, advertising, and promotion tools. Unlike the cart-recovery module,
 * these focus on proactive growth: running real scheduled sales, increasing
 * average order value via native WooCommerce cross-sells, finding pricing
 * opportunities, and giving the store owner an actual customer list to
 * retarget with ads - all grounded in real store data, not generic advice.
 */
return array(

	array(
		'name'        => 'create_flash_sale',
		'description' => 'Run a time-limited sale on a category or list of SKUs using WooCommerce\'s native scheduled sale price - it starts and automatically reverts on its own, no extra automation needed. Honors dry_run.',
		'is_write'    => true,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'category'         => array( 'type' => 'string', 'description' => 'Category slug to put on sale. Omit if using skus.' ),
				'skus'             => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Specific SKUs to put on sale. Omit if using category.' ),
				'discount_percent' => array( 'type' => 'number', 'description' => 'Percent off the regular price, e.g. 20 for 20% off.' ),
				'starts_in_hours'  => array( 'type' => 'integer', 'description' => 'Hours from now until the sale starts. Default 0 (immediately).' ),
				'duration_hours'   => array( 'type' => 'integer', 'description' => 'How many hours the sale runs. Default 48.' ),
				'dry_run'          => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'discount_percent' ),
		),
		'callback'    => function ( $args ) {
			$percent    = (float) $args['discount_percent'];
			$starts_in  = (int) ( $args['starts_in_hours'] ?? 0 );
			$duration   = (int) ( $args['duration_hours'] ?? 48 );
			$is_dry     = WC_Ops_MCP_Tools::is_dry_run( $args );

			if ( $percent <= 0 || $percent >= 100 ) {
				return array( 'error' => 'discount_percent must be between 1 and 99.' );
			}
			if ( abs( $percent ) > WC_Ops_MCP_Tools::max_price_change_percent() ) {
				return array(
					'error'   => sprintf( 'A %s%% discount exceeds the configured max price change guardrail of %s%%.', $percent, WC_Ops_MCP_Tools::max_price_change_percent() ),
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
				$products = wc_get_products( array( 'category' => array( sanitize_text_field( $args['category'] ) ), 'limit' => -1 ) );
			} else {
				return array( 'error' => 'Provide either "category" or "skus".' );
			}

			if ( empty( $products ) ) {
				return array( 'error' => 'No matching products found.' );
			}

			$from = time() + ( $starts_in * HOUR_IN_SECONDS );
			$to   = $from + ( $duration * HOUR_IN_SECONDS );

			$results = array();
			foreach ( $products as $product ) {
				$regular = (float) $product->get_regular_price();
				if ( $regular <= 0 ) {
					continue;
				}
				$sale_price = round( $regular * ( 1 - $percent / 100 ), 2 );

				if ( $is_dry ) {
					$results[] = array(
						'product_id'    => $product->get_id(),
						'name'          => $product->get_name(),
						'regular_price' => $regular,
						'sale_price'    => $sale_price,
						'starts_at'     => gmdate( 'c', $from ),
						'ends_at'       => gmdate( 'c', $to ),
						'dry_run'       => true,
					);
					continue;
				}

				$product->set_sale_price( $sale_price );
				$product->set_date_on_sale_from( $from );
				$product->set_date_on_sale_to( $to );
				$product->save();

				$results[] = array(
					'product_id'    => $product->get_id(),
					'name'          => $product->get_name(),
					'regular_price' => $regular,
					'sale_price'    => $sale_price,
					'starts_at'     => gmdate( 'c', $from ),
					'ends_at'       => gmdate( 'c', $to ),
					'success'       => true,
				);
			}

			return array( 'dry_run' => $is_dry, 'products_updated' => count( $results ), 'results' => $results );
		},
	),

	array(
		'name'        => 'set_product_cross_sells',
		'description' => 'Link related products as cross-sells (shown in cart, "you may also like") to increase average order value. Uses WooCommerce\'s native cross-sell feature.',
		'is_write'    => true,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'product_sku'     => array( 'type' => 'string', 'description' => 'SKU of the main product.' ),
				'cross_sell_skus' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'SKUs to show as cross-sells for the main product.' ),
			),
			'required'   => array( 'product_sku', 'cross_sell_skus' ),
		),
		'callback'    => function ( $args ) {
			$main_id = wc_get_product_id_by_sku( sanitize_text_field( $args['product_sku'] ) );
			if ( ! $main_id ) {
				return array( 'error' => 'Main product SKU not found.' );
			}

			$cross_sell_ids = array();
			$not_found      = array();
			foreach ( $args['cross_sell_skus'] as $sku ) {
				$id = wc_get_product_id_by_sku( sanitize_text_field( $sku ) );
				if ( $id ) {
					$cross_sell_ids[] = $id;
				} else {
					$not_found[] = $sku;
				}
			}

			$product = wc_get_product( $main_id );
			$product->set_cross_sell_ids( $cross_sell_ids );
			$product->save();

			return array(
				'success'         => true,
				'product_id'      => $main_id,
				'cross_sells_set' => count( $cross_sell_ids ),
				'skus_not_found'  => $not_found,
			);
		},
	),

	array(
		'name'        => 'find_pricing_opportunities',
		'description' => 'Find best-selling products that are NOT currently discounted - candidates for a price increase or a featured promotion, since demand is already proven.',
		'is_write'    => false,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'days'     => array( 'type' => 'integer', 'description' => 'Look-back window in days. Default 30.' ),
				'per_page' => array( 'type' => 'integer', 'description' => 'Max results. Default 10.' ),
			),
		),
		'callback'    => function ( $args ) {
			global $wpdb;
			$days     = (int) ( $args['days'] ?? 30 );
			$per_page = min( 50, max( 1, (int) ( $args['per_page'] ?? 10 ) ) );
			$after    = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			$sql = $wpdb->prepare(
				"SELECT oi.order_item_name AS name, oim_pid.meta_value AS product_id, SUM(oim_qty.meta_value) AS qty_sold
				 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id'
				 INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
				 WHERE oi.order_item_type = 'line_item' AND p.post_date >= %s AND p.post_status IN ('wc-completed','wc-processing')
				 GROUP BY oim_pid.meta_value
				 ORDER BY qty_sold DESC
				 LIMIT 50",
				$after
			);

			$rows   = $wpdb->get_results( $sql );
			$result = array();

			foreach ( $rows as $row ) {
				$product = wc_get_product( (int) $row->product_id );
				if ( ! $product || $product->is_on_sale() ) {
					continue;
				}
				$result[] = array(
					'product_id'    => $product->get_id(),
					'name'          => $product->get_name(),
					'sku'           => $product->get_sku(),
					'current_price' => $product->get_regular_price(),
					'units_sold'    => (int) $row->qty_sold,
				);
				if ( count( $result ) >= $per_page ) {
					break;
				}
			}

			return array( 'window_days' => $days, 'opportunities' => $result, 'count' => count( $result ) );
		},
	),

	array(
		'name'        => 'find_slow_moving_stock',
		'description' => 'Find products with high stock quantity and low or no recent sales - candidates for a clearance sale to free up cash and warehouse space.',
		'is_write'    => false,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'min_stock'      => array( 'type' => 'integer', 'description' => 'Only consider products with at least this much stock. Default 10.' ),
				'days'           => array( 'type' => 'integer', 'description' => 'Sales look-back window in days. Default 60.' ),
				'max_units_sold' => array( 'type' => 'integer', 'description' => 'Only include products that sold at most this many units in the window. Default 2.' ),
			),
		),
		'callback'    => function ( $args ) {
			global $wpdb;
			$min_stock      = (int) ( $args['min_stock'] ?? 10 );
			$days           = (int) ( $args['days'] ?? 60 );
			$max_units_sold = (int) ( $args['max_units_sold'] ?? 2 );
			$after          = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			$high_stock = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT product_id, stock_quantity FROM {$wpdb->wc_product_meta_lookup} WHERE stock_quantity >= %d",
					$min_stock
				)
			);

			$sales_sql = $wpdb->prepare(
				"SELECT oim_pid.meta_value AS product_id, SUM(oim_qty.meta_value) AS qty_sold
				 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id'
				 INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
				 WHERE oi.order_item_type = 'line_item' AND p.post_date >= %s AND p.post_status IN ('wc-completed','wc-processing')
				 GROUP BY oim_pid.meta_value",
				$after
			);
			$sales_rows = $wpdb->get_results( $sales_sql );
			$sold_map   = array();
			foreach ( $sales_rows as $row ) {
				$sold_map[ (int) $row->product_id ] = (int) $row->qty_sold;
			}

			$result = array();
			foreach ( $high_stock as $row ) {
				$sold = $sold_map[ (int) $row->product_id ] ?? 0;
				if ( $sold > $max_units_sold ) {
					continue;
				}
				$product = wc_get_product( (int) $row->product_id );
				if ( ! $product ) {
					continue;
				}
				$result[] = array(
					'product_id'           => $product->get_id(),
					'name'                 => $product->get_name(),
					'sku'                  => $product->get_sku(),
					'stock_quantity'       => (int) $row->stock_quantity,
					'units_sold_in_window' => $sold,
					'price'                => $product->get_price(),
				);
			}

			return array( 'window_days' => $days, 'slow_movers' => $result, 'count' => count( $result ) );
		},
	),

	array(
		'name'        => 'export_customer_audience',
		'description' => 'Export a CSV of customer emails (optionally filtered by minimum lifetime spend or recent purchase) for uploading to Facebook, Google, or TikTok Custom Audiences for retargeting ads. IMPORTANT: this file contains personal data (emails) - it is saved with a random, hard-to-guess filename, but should be downloaded and then deleted, and only used in accordance with your ad platform\'s and your local data protection rules.',
		'is_write'    => true,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'min_lifetime_spend' => array( 'type' => 'number', 'description' => 'Only include customers who have spent at least this much in total. Default 0 (all customers).' ),
				'days_since_order'   => array( 'type' => 'integer', 'description' => 'Only include customers who ordered within this many days. Omit for all-time.' ),
			),
		),
		'callback'    => function ( $args ) {
			global $wpdb;
			$min_spend = isset( $args['min_lifetime_spend'] ) ? (float) $args['min_lifetime_spend'] : 0;
			$days      = isset( $args['days_since_order'] ) ? (int) $args['days_since_order'] : null;

			$date_clause = '';
			if ( $days ) {
				$cutoff      = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
				$date_clause = $wpdb->prepare( ' AND p.post_date >= %s', $cutoff );
			}

			$sql = "SELECT pm.meta_value AS email, SUM(pm_total.meta_value) AS lifetime_value
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
				INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed','wc-processing') {$date_clause}
				GROUP BY pm.meta_value
				HAVING lifetime_value >= " . (float) $min_spend;

			$rows = $wpdb->get_results( $sql );

			if ( empty( $rows ) ) {
				return array( 'error' => 'No customers matched those filters.' );
			}

			$upload_dir = wp_upload_dir();
			$export_dir = trailingslashit( $upload_dir['basedir'] ) . 'wc-ops-mcp-exports';
			if ( ! file_exists( $export_dir ) ) {
				wp_mkdir_p( $export_dir );
				file_put_contents( $export_dir . '/index.php', "<?php // Silence is golden.\n" );
			}

			$filename = 'audience_' . wp_generate_password( 20, false, false ) . '.csv';
			$filepath = $export_dir . '/' . $filename;

			$fh = fopen( $filepath, 'w' );
			fputcsv( $fh, array( 'email' ) );
			foreach ( $rows as $row ) {
				fputcsv( $fh, array( $row->email ) );
			}
			fclose( $fh );

			$file_url = trailingslashit( $upload_dir['baseurl'] ) . 'wc-ops-mcp-exports/' . $filename;

			return array(
				'success'        => true,
				'customer_count' => count( $rows ),
				'download_url'   => $file_url,
				'warning'        => 'This file contains customer emails. Download it, upload it to your ad platform, then delete it from your server.',
			);
		},
	),

	array(
		'name'        => 'traffic_source_performance',
		'description' => 'Show which traffic sources (from utm_source/utm_medium/utm_campaign links) actually generated sales - useful for deciding which ads or channels to spend more or less on.',
		'is_write'    => false,
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => array(
				'days' => array( 'type' => 'integer', 'description' => 'Look-back window in days. Default 30.' ),
			),
		),
		'callback'    => function ( $args ) {
			global $wpdb;
			$days  = (int) ( $args['days'] ?? 30 );
			$after = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			$sql = $wpdb->prepare(
				"SELECT pm_source.meta_value AS source,
				        COUNT(DISTINCT p.ID) AS orders,
				        SUM(pm_total.meta_value) AS revenue
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_source ON p.ID = pm_source.post_id AND pm_source.meta_key = '_wc_ops_mcp_utm_source'
				 INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed','wc-processing') AND p.post_date >= %s
				 GROUP BY pm_source.meta_value
				 ORDER BY revenue DESC",
				$after
			);

			$rows = $wpdb->get_results( $sql );

			if ( empty( $rows ) ) {
				return array(
					'window_days' => $days,
					'sources'     => array(),
					'note'        => 'No tagged traffic yet. Add ?utm_source=facebook (or google, instagram, email, etc.) to links you share in ads/posts/emails, and this will start populating.',
				);
			}

			$result = array();
			foreach ( $rows as $row ) {
				$result[] = array(
					'source'  => $row->source,
					'orders'  => (int) $row->orders,
					'revenue' => round( (float) $row->revenue, 2 ),
				);
			}

			return array( 'window_days' => $days, 'sources' => $result );
		},
	),
);
