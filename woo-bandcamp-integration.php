<?php
namespace mnml_bandcamp_woo;
/*
Plugin Name: WooCommerce Bandcamp Integration
Description: Import orders from Bandcamp to WooCommerce
Version:     2022-10-20 get orders back 2 years
Plugin URI: 
Author URI: https://github.com/andrewklimek/
Author:     Andrew J Klimek
*/
defined('ABSPATH') || exit;

// include( __DIR__ . "/import.php" );

// add_action( 'mnmlbc2wc_main_cron_hook', __NAMESPACE__ .'\main_process' );
// if ( ! wp_next_scheduled( 'mnmlbc2wc_main_cron_hook' ) ) {
//     wp_schedule_event( strtotime('+ 1 minute'), 'hourly', 'mnmlbc2wc_main_cron_hook' );
// }
// wp_unschedule_event( wp_next_scheduled( 'mnmlbc2wc_main_cron_hook' ), 'mnmlbc2wc_main_cron_hook' );

// wp_unschedule_event( wp_next_scheduled( 'mnmlbc2wc_retry_cron_hook' ), 'mnmlbc2wc_retry_cron_hook' );
// add_action( 'mnmlbc2wc_retry_cron_hook', __NAMESPACE__ .'\retry_add_tracking' );
// if ( ! wp_next_scheduled( 'mnmlbc2wc_retry_cron_hook' ) ) {
// 	wp_schedule_event( strtotime('+ 30 seconds'), 'twicedaily', 'mnmlbc2wc_retry_cron_hook' );
// }

function vendor_address( $vendor )
{
	if ( is_object( $vendor ) )
	{
		if ( class_exists('Dokan_Pro', false) )// would i work with dokan-lite?
		{
			$dokan = get_user_meta( $vendor->user_id, 'dokan_profile_settings', true );
			if ( !empty( $dokan["address"]["street_1"] ) ) {
				$first_name = get_user_meta( $vendor->user_id, 'first_name', true );
				$last_name = get_user_meta( $vendor->user_id, 'last_name', true );
				$address = [
					'billing_first_name' => $first_name,
					'billing_last_name' => $last_name,
					'billing_phone' => $dokan["phone"],
					'billing_email' => $vendor->email,
					'billing_address_1' => $dokan["address"]["street_1"],
					'billing_address_2' => $dokan["address"]["street_2"],
					'billing_city' => $dokan["address"]["city"],
					'billing_state' => $dokan["address"]["state"],
					'billing_postcode' => $dokan["address"]["zip"],
					'billing_country' => $dokan["address"]["country"],
				];
			}
			return $address; 
		}
		// implied else for no dokan class, or no dokan address
		// get standard woo fields
		$address = [];
		$all_meta = get_user_meta( $vendor->user_id );
		foreach ( $all_meta as $k => $v ) {
			if ( 'billing_' === substr( $k, 0, 8 ) ) {
				$address[ $k ] = $v[0];
			}
		}
	} else { // no vendor object, no idividual vendors on this site.  Use global setting
		$address = get_option('mnmlbc2wc_address');
		// this option is an array like so:
		// 	'billing_first_name' => '',
		// 	'billing_last_name' => '',
		// 	'billing_phone' => '',
		// 	'billing_email' => '',
		// 	'billing_address_1' => '',
		// 	'billing_address_2' => '',
		// 	'billing_city' => '',
		// 	'billing_state' => '',
		// 	'billing_postcode' => '',
		// 	'billing_country' => '',]
	}
	// wbi_debug($address, "info");
	return $address;
}


function main_process(){

	$log_errors_setting = ini_get( 'log_errors' );
	$error_log_setting = ini_get( 'error_log' );
	error_reporting( E_ALL );
	// ini_set( 'display_errors', 0 );
	ini_set( 'log_errors', 1 );
	ini_set( 'error_log', __DIR__ . '/php_errors.log' );

	$settings = $GLOBALS['bc2wc_settings'] = get_option('mnmlbc2wc');

	if ( empty($settings['client_id']) || empty($settings['client_secret']) ) return;// Quit right away if no credentials

	add_action( 'woocommerce_email', __NAMESPACE__ .'\disable_woo_emails' );

	// Circumvent checks on if this can be bought: https://github.com/woocommerce/woocommerce/blob/6.3.1/plugins/woocommerce/includes/class-wc-cart.php#L1168
	add_filter( 'woocommerce_is_purchasable', '__return_true' );// is_purchasable()
	add_filter( 'woocommerce_product_is_in_stock', '__return_true' );// is_in_stock()
	add_filter( 'woocommerce_product_backorders_allowed', '__return_true' );// has_enough_stock()
	add_filter( 'woocommerce_product_backorders_require_notification', '__return_false' );
	
	// echo ini_get('max_execution_time');
	// ini_set('max_execution_time',300);
	// echo ini_get('max_execution_time');
	// return;

	$GLOBALS['bc_wc_ids'] = false;// turned off for now
	// cache a lookup table to match bandcamp product IDs to woocommerce product IDs.
	// TODO: clear cache when a product is deleted
// 	$cache_ver = "220402";
// 	$GLOBALS['bc_wc_ids'] = get_option( 'bandcamp_woo_id_pairs', [] );
// 	if ( empty($GLOBALS['bc_wc_ids']['v']) || $GLOBALS['bc_wc_ids']['v'] !== $cache_ver ) {
// 		$GLOBALS['bc_wc_ids'] = [ 'v' => $cache_ver ];
// 	}

	$exclude_countries = empty( $settings['exclude_countries'] ) ? [] : explode(',', str_replace(' ','', $settings['exclude_countries'] ) );
	$include_countries = empty( $settings['include_countries'] ) ? [] : explode(',', str_replace(' ','', $settings['include_countries'] ) );

	$last_import = get_option( 'mnmlbc2wc_last_import', array() );

	$update_last_import = false;

	$report = "";

	$token = auth();
	wbi_debug("using token $token");

	$bands = bands($token);
	if ( ! $bands ) {// in case the token is somehow expired or invalid, force refresh of token and retry. this shouldnt happen, but just in case...
		wbi_debug("try force refresh");
		$token = auth('refresh');
		$bands = bands($token);
	}
	// wbi_debug($bands);
	if ( $bands ) :

	$vendors = [];
	if ( !empty( $settings['use_vendor_settings'] ) ) {
		$vendor_ids = get_users(['meta_key' => 'mnml_bandcamp_woo', 'fields' => ['ID','user_email'] ]);
		if ( $vendor_ids ) {
			foreach($vendor_ids as $vid) {
				$meta = get_user_meta($vid->ID,'mnml_bandcamp_woo',true);
				if ( $meta['band_id'] )
					$vendors[ $meta['band_id'] ] = (object) (['user_id' => $vid->ID, 'email' => $vid->user_email ] + $meta);
			}
		}
	}

	foreach( $bands as $band ) {

		$bid = $band->band_id;

		if ( $vendors ) { // individual WP users with band IDs.  Use them.
			if ( empty( $vendors[ $bid ] ) ) {
				wbi_debug("vendors: skipping {$bid}");
				continue;
			}
			$vendor_address = vendor_address( $vendors[ $bid ] );
			$vendor = $vendors[ $bid ];
			$exclude_countries = empty($vendor->exclude_country) ? [] : explode(',', str_replace(' ','', $vendor->exclude_country ) );
			$include_countries = empty($vendor->include_country) ? [] : explode(',', str_replace(' ','', $vendor->include_country ) );
		}
		else // no individual users.  Use global settings include / exclude for band IDs
		{
			$vendor = false;
			// only include these bands
			if ( !empty($settings['bands_include']) && ! in_array( (string) $bid, explode(',', str_replace(' ','',$settings['bands_include'])), true ) ) {
				wbi_debug("skipping band {$bid}");
				continue;
			}
			// don't include these bands
			if ( !empty($settings['bands_exclude']) && in_array( (string) $bid, explode(',', str_replace(' ','',$settings['bands_exclude'])), true ) ) {
				wbi_debug("skipping band {$bid}");
				continue;
			}
			$vendor_address = vendor_address('global');

		}
		wbi_debug("Processing band {$bid}");
		// var_export($vendor_address);
		

		if ( empty( $last_import[$bid] ) ) $last_import[$bid] = 0;
		$order_date = 0;
		$prepared_orders = [];
		
		$count = 0;
		// test data
		// $orders = get_option('mnmlbc2wc_test_data');
		$orders = get_orders( $bid, $token );
		// update_option('mnmlbc2wc_test_data', $orders, false );

		foreach ( $orders as $data ) {

			wbi_debug("{$data->ship_to_name}");

			if ( $data->payment_state !== "paid" ) {
				wbi_debug("Payment status is {$data->payment_state}");
				continue;// skip pending orders, right?
			}

			// skip countries the vendor ships to themselves.
			// $data->ship_from_country_name !== "United States"
			if ( $exclude_countries && in_array( $data->ship_to_country_code, $exclude_countries ) ) {
				wbi_debug("from an excluded country");
				continue;
			}
			if ( $include_countries && ! in_array( $data->ship_to_country_code, $include_countries ) ) {
				wbi_debug("not from an included country");
				continue;
			}
			
			// if ( $count === 10 ) continue;// test

			if ( strtotime( $data->order_date ) <= $last_import[$bid] ) {
				// this would seem to indicate an order that was already imported but not yet shipped
				// but it's possible the order was skipped last time, eg because the product wasn't entered in Woo yet... 
				// better check to see if the order was imported and if not, try again now
				global $wpdb;
				if ( $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_value='{$data->payment_id}' AND meta_key='bandcamp_id' LIMIT 1")) {
					wbi_debug("skip because date is before last imported");
					continue;
				} else {
					wbi_debug("old order but not in the system yet... must have failed last time, try again now.");
				}
			} else {
				$last_import[$bid] = 0;
				$order_date = $data->order_date;
			}

			$prepared_orders = prepare_data_for_woo_order( $data, $prepared_orders );

			// echo "<pre>"; var_dump( $prepared_orders ); echo "</pre>";
		}
		// echo "<pre>Prepared Orders<br>"; var_dump($prepared_orders); echo "</pre>";
		foreach ( $prepared_orders as $order ) {
			$order['billing'] = $vendor_address;
			if ( $vendor ) {
				$order['user_id'] = $vendor->user_id;
				// $order['band_id'] = $bid;
			} elseif ( !empty( $settings['assign_orders_to'] ) ) {
			    $order['user_id'] = $settings['assign_orders_to'];
			}
			$order_id = make_woo_order( $order );
			if ( $order_id ) $count++;
		}

		if ( $order_date ) {
			$last_import[$bid] = strtotime( $order_date );
			$update_last_import = true;
		}

		$report .= "created $count orders for {$band->name}";
	}
	
	if ( $update_last_import ) update_option( 'mnmlbc2wc_last_import', $last_import, false );
	
	// save ID lookup cache if updated during this run
	if ( !empty( $GLOBALS['bc_wc_ids']['updated'] ) ) {
		unset( $GLOBALS['bc_wc_ids']['updated'] );
		update_option( 'bandcamp_woo_id_pairs', $GLOBALS['bc_wc_ids'], 'no' );
	}

	endif; // bands

	return $report;

	ini_set( 'log_errors', $log_errors_setting );
	ini_set( 'error_log', $error_log_setting );
}


// class WBI_WC_Session extends WC\WC_Session {
    // ...
// }
/**
 * Some clues as to how this all works:
 * https://github.com/woocommerce/woocommerce/blob/a5cd695e629e1c4c48b257646b60c0d92f479509/includes/wc-core-functions.php#L84
 * https://stackoverflow.com/a/55437588
 * https://github.com/woocommerce/woocommerce/blob/4487b3fa9a4d2ac7ce0464082ef0926d01a31f0b/includes/rest-api/Controllers/Version3/class-wc-rest-orders-controller.php#L102
 * https://plugins.svn.wordpress.org/woo-calculate-shipping-in-product-page/trunk/lib/main.php
 * https://github.com/woocommerce/woocommerce/pull/30855/commits/c5e91190bb54b24eaa1d712751651327ac4160bb
 */
function make_woo_order( $data ) {

	if ( !empty( $data['missing_item'] ) ) {
		log( $data['missing_item'] . " — SKIPPED");
		return false;
	}

    
	$settings = !empty( $GLOBALS['bc2wc_settings'] ) ? $GLOBALS['bc2wc_settings'] : get_option('mnmlbc2wc');

	$order = new \WC_Order();

	wbi_debug( count($order->get_items()), "items in new order" );
	if ( count($order->get_items()) ) {
		wbi_debug("new order already had items, clearing.");// this seemed to happen while testing... not sure if it would normally happen. products from user session I guess.
		$order->remove_order_items();
	}

	if ( !empty( $data['user_id'] ) ) {
		// $order->add_meta_data( 'bandcamp_band_id', $data['band_id'] );
		// $order->add_meta_data( '_dokan_vendor_id', $data['user_id'] );// Dokan... needed?
		// $order->add_meta_data( '_customer_user', $data['user_id'] );// official way is WC_Order::set_customer_id()
		$order->set_customer_id( $data['user_id'] );
	} 
	// else {
	// 	$order->set_customer_id( 1 );
	// }

    if ( isset($data['source']) && $data['source'] === 'import' ) {
        $order->set_payment_method('Import');
	    $order->set_payment_method_title('Import (credit)');
    } else {
    	$order->set_payment_method('Bandcamp');
	    $order->set_payment_method_title('Bandcamp (credit)');
    }
	$order->set_created_via( "Bandcamp Integration" );
	// $order->set_currency('EUR');// defaults to get_woocommerce_currency()
	// $order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
	// $order->set_prices_include_tax( false );
	// $order->set_status( 'Completed' );// doesnt seem to do anything
	// $order->save();// if we dont save here it seems to grab a cached order somehow

	foreach ( $data['line_items'] as $item ) {
		$product_obj = wc_get_product( $item['product_id'] );
		$order->add_product( $product_obj, $item['quantity'] );
		//  add to cart also for shipping.
		// if ($calc_ship) {
		// 	wbi_debug($item['product_id'],'$item[product_id]');
		// 	wbi_debug($item['quantity'],'$item[quantity]');
		// 	$result = WC()->cart->add_to_cart($item['product_id'], $item['quantity']);// variant ID is supposed to go in the 3rd param but the function accounts for it being 1st.
		// 	wbi_debug($result);
		// }
	}
	// if ($calc_ship) wbi_debug(WC()->cart->get_cart(), "get cart 1");

	/**
	 * add VAT as done by WC add-on "EU VAT Number"
	 * see line 555 in class-wc-eu-vat-number.php
	 * however I think they are using an old version of this plugin and the metadata has been renamed to _billing_vat_number
	 * https://woocommerce.com/document/eu-vat-number/#vat-numbers-meta-field
	 */
	if ( !empty( $data['billing']['billing_vat_number'] ) ) {
		$order->add_meta_data( '_vat_number', $data['billing']['billing_vat_number'] );
		$order->add_meta_data( '_vat_number_is_validated', 'true' );// couldnt find why these would be needed
		$order->add_meta_data( '_vat_number_is_valid', 'true' );
		unset($data['billing']['billing_vat_number']);// not used in loop below
	}
    
	foreach ( ['billing', 'shipping'] as $type ) {
		foreach ( $data[$type] as $key => $value ) {
			if ( is_callable( [ $order, "set_{$key}" ] ) ) {
				$order->{"set_{$key}"}( $value );
			}
		}
	}


	// Set up a cart with an in-memory store (instead of storing in a cookie).
	// $calc_ship = false;// if plugin setting "dont calculate shipping" is checked, cart will stay false. cart is only used to calculate shipping.
	if ( empty( $settings['dont_calculate_shipping'] ) ) {
		// $calc_ship = true;
		if ( is_null( WC()->cart ) ) {
			wbi_debug('is_null( WC()->cart )');
			// add_filter( 'woocommerce_session_handler', function( $handler ) {
			// 	if ( class_exists( 'WC_Session' ) ) {
			// 		include __DIR__ . '/sessionhandler.php';
			// 		// class WBI_WC_Session extends \WC_Session {}
			// 		$handler = 'WBI_WC_Session';
			// 	}
			// 	return $handler;
			// } );
			// add_filter( 'woocommerce_session_handler', function(){ return 'WC_Session'; } );// PHP Fatal error:  Uncaught Error: Cannot instantiate abstract class WC_Session... but it's here https://github.com/woocommerce/woocommerce/pull/30855/commits/c5e91190bb54b24eaa1d712751651327ac4160bb
			wc_load_cart();
		}
		// $cart = new \WC_Cart();
		if ( count( WC()->cart->get_cart() ) > 0 ) {
			WC()->cart->empty_cart();
			wbi_debug("had to empty cart");
		}
		// wbi_debug(WC()->cart,'WC()->cart');
	// }
	// if ( $calc_ship ) {
		// get shipping rates by simulating a cart
		// if ( is_null( WC()->cart ) ) {
		// 	wc_load_cart();
		// } elseif ( count( WC()->cart->get_cart() ) > 0 ) {
		// 	WC()->cart->empty_cart();
		// }
		// WC()->shipping()->reset_shipping();
		// WC()->customer->set_billing_location( $data['billing']['billing_country'], $data['billing']['billing_state'], $data['billing']['billing_postcode'], $data['billing']['billing_city'] );
		// if ( ! WC()->cart->get_customer()->has_shipping_address() ) {
		wbi_debug( WC()->cart->get_customer()->get_shipping(), "new cart customer, before setting it" );
		WC()->customer->set_shipping_location( $data['shipping']['shipping_country'], $data['shipping']['shipping_state'], $data['shipping']['shipping_postcode'], $data['shipping']['shipping_city'] );
		// WC()->customer->set_shipping_location( $data['shipping']['shipping_country'], $data['shipping']['shipping_state'], $data['shipping']['shipping_postcode'], $data['shipping']['shipping_city'] );
		// }
		// foreach ( $order->get_items() as $i ) WC()->cart->add_to_cart($i['product_id'], $i['qty']);
		foreach ( $data['line_items'] as $item ) {
			wbi_debug($item['product_id'],'$item[product_id]');
			wbi_debug($item['quantity'],'$item[quantity]');
			$result = WC()->cart->add_to_cart($item['product_id'], $item['quantity']);// variant ID is supposed to go in the 3rd param but the function accounts for it being 1st.
			wbi_debug($result);
			if ( ! $result ) {
			   wbi_debug( end( wc_get_notices('error') )['notice'] );
			}
		}
		// wbi_debug(WC()->cart->get_cart(), "get cart 1");
		$packages = WC()->cart->get_shipping_packages();
		$shipping = WC()->shipping()->calculate_shipping($packages);
		// $shipping = WC()->shipping()->get_packages();

		$rate = false;

		foreach( $shipping[0]['rates'] as $r ) {
			if ( count( $shipping[0]['rates'] ) === 1 || ( false === stripos( $r->label, 'fedex' ) && false === stripos( $r->label, 'ups' ) ) ) {// TODO relying on the label isn't great, and what about other methods?
				$rate = $r;
				break;
			}
		}
		wbi_debug($shipping[0]['destination'], "calculate shipping array destination");
		// wbi_debug(WC()->cart->get_cart(), "get cart 2");
		if ( ! $rate ) {
			wbi_debug('couldnt find rate with the right label');
			wbi_debug($shipping);
			// return false;
		} else {
		
			$shipping = new \WC_Order_Item_Shipping();
			$shipping->set_props([
					'method_title' => $rate->label,
					'method_id'    => $rate->id,
					'total'        => wc_format_decimal( $rate->cost ),
					'taxes'        => $rate->taxes,
			]);

			$order->add_item( $shipping );
		}
	}

	foreach ( $data['bc_id'] as $bcid ) {
		$order->add_meta_data( 'bandcamp_id', $bcid );
	}

	$order->calculate_totals();
	$order->update_status( 'processing', 'Imported order' );

	$order_id = $order->save();

	log( "{$data['shipping']['shipping_first_name']} {$data['shipping']['shipping_last_name']} — created order $order_id" );

	wbi_debug( wc_get_notices(), 'notices' );

	return $order_id;
}

/**
 * Some data parsing / structuring
 */
function prepare_data_for_woo_order( $data, $o ) {
    
    $order_key = $data->payment_id;
    
    // merge seperate orders with same shipping address & name  (This will pause all orders by anyone who has ordered a pre-order item)
    if ( !empty( $GLOBALS['bc2wc_settings']['combine_orders'] ) ) {
        $order_key = $data->ship_to_name .'---'. $data->ship_to_street;
    }

	// initialize order with blank parameters
	if ( ! isset( $o[ $order_key ] ) ) {
		$o[ $order_key ] = [ 'shipping' => [], 'bc_id' => [], 'line_items' => [] ];
	}
    
	// find Woo product ID for this bandcamp item
	// if it's a variant, use that ID instead
	$package_id = !empty( $data->option_id ) ? $data->option_id : $data->package_id;
	
	// check cache
	if ( !empty( $GLOBALS['bc_wc_ids'][ $package_id ] ) ) {
		wbi_debug("got from cache");
		$product_id = $GLOBALS['bc_wc_ids'][ $package_id ];
	} else {
		// run function with a few ways to try to find it
		$product_id = find_woo_product( $data );
		if ( ! $product_id ) {
			wbi_debug("Couldnt find product ID for $data->item_name");
			$o[ $order_key ]['missing_item'] = "{$data->ship_to_name} — couldnt find product {$data->item_name} {$data->sku}";
			return $o;
		}
		
		// check for variant parent item
		// https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/class-wc-product-variable.php
		if ( is_a( wc_get_product( $product_id ), '\WC_Product_Variable' ) ) {
		   	wbi_debug("Got parent product of variable product for $data->item_name sku $data->sku please try again.");
			$o[ $order_key ]['missing_item'] = "{$data->ship_to_name} — product was matched to parent variable product {$data->item_name} {$data->sku}";
			return $o;
		}

        if ( $GLOBALS['bc_wc_ids'] ) {
    		$GLOBALS['bc_wc_ids'][ $package_id ] = $product_id;// add new match to cache
    		$GLOBALS['bc_wc_ids']['updated'] = true;// flag to update in database at the end
        }
	}
	wbi_debug("product id is " . $product_id);
	
	if ( empty( $o[ $order_key ]['shipping'] ) ) {

		if ( false === strpos( $data->ship_to_name, ' ' ) || preg_match("/[\p{Han}\p{Katakana}\p{Hiragana}]+/u", $data->ship_to_name ) ) {
			wbi_debug( "{$data->ship_to_name} didnt have a space or had japanese... trying {$data->buyer_name} instead");
			$name = explode( ' ', $data->buyer_name, 2 );
		} else {
			$name = explode( ' ', $data->ship_to_name, 2 );
		}

		$o[ $order_key ]['shipping'] = [
			'shipping_first_name' => $name[0],
			'shipping_last_name'  => isset($name[1]) ? $name[1] : '',
			// 'shipping_phone'      => '', //$data->buyer_phone,
			// 'shipping_email'      => '', //$data->buyer_email,
			'shipping_address_1'  => $data->ship_to_street,
			'shipping_address_2'  => $data->ship_to_street_2,
			'shipping_city'       => $data->ship_to_city,
			'shipping_state'      => $data->ship_to_state,
			'shipping_postcode'   => $data->ship_to_zip,
			'shipping_country'    => $data->ship_to_country_code
		];
	}
	
    // add product as line item
	$o[ $order_key ]['line_items'][] = [ 'product_id' => $product_id, 'quantity' => $data->quantity ];
    
    // Bandcamp Payment ID for later marking as shipped
	if ( ! in_array( $data->payment_id, $o[ $order_key ]['bc_id'] ) ) $o[ $order_key ]['bc_id'][] = $data->payment_id;

	return $o;
}


/**
 * find product
 * https://github.com/woocommerce/woocommerce/wiki/wc_get_products-and-WC_Product_Query
 * https://developer.wordpress.org/reference/functions/get_page_by_title/
 */
function find_woo_product( $data ) {
    wbi_debug( str_replace( ["   ", "\n"], [""," "], var_export( $data, true ) ) );

	// try getting by sku
	$sku = $data->sku;
	if ( !empty( $sku ) ) {
        // $product_id = $wpdb->get_var( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='{$sku}' LIMIT 1" );
    	$product_id = wc_get_product_id_by_sku( $sku );
    	if ( $product_id ) {
    	    wbi_debug("Found product $product_id for sku $sku");
    	    return $product_id;
    	}
	}
	// no SKU...
	// Try special "bandcamp title" field
	global $wpdb;

	$data->item_name = substr( $data->item_name, 0, strrpos( $data->item_name, ' by ' ) );// remove " by artist" portion

	$product = $data->item_name;
	// if ( !empty( $data->option ) ) $product .= "||" . $data->option;

	$results = $wpdb->get_col( "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key='bandcamp_title' AND meta_value='". esc_sql($product) ."' LIMIT 2" );


	// wbi_debug("data option");
	// wbi_debug($data->option);


	// maybe shouldn't include the $data->option check, but the logic is this should only run for merch like shirts.
	// they dont show "album name:" in the bc fullfillment dash, btu they DO have that in the API title, if the merch included an album download.
	if ( ! $results && !empty( $data->option ) && strpos( $product, ': ' ) ) {
		$product = explode( ': ', $product, 2 )[1];
		wbi_debug($product);
		$results = $wpdb->get_col( "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key='bandcamp_title' AND meta_value='". esc_sql($product) ."' LIMIT 2" );
	}

	if ( ! $results ) {
		log("couldnt match product to bandcamp title {$product}");
		wbi_debug($results);
		return false;
	} elseif ( count( $results ) > 1  ) {
		log("multiple products had the title... couldn't match it. {$product}");
		return false;
	} else {
		wbi_debug("Found product {$results[0]} by matching bandcamp product title {$product}");
    	$product_id = $results[0];
	}

	// see if this is a variable product
	if ( !empty( $data->option ) ) {
		// if its a variable product, it could be set on BC as seperate products (vinyl colors set as seperate items so each displays on the page)
		// in this case the data->option would be empty but its OK because it would match just one variation in WC. The variations would hold the BC title not the parent.
		// $product = wc_get_product( $product_id ); if ( $product->is_type( 'variable' ) ) ... // if ia check is actually needed, this is how.
		$opt = trim($data->option);
		$results = $wpdb->get_col("SELECT post_id FROM {$wpdb->prefix}posts as p JOIN {$wpdb->prefix}postmeta as m ON (p.ID=m.post_id) WHERE post_parent={$product_id} AND meta_key='bandcamp_title' AND meta_value='{$opt}' LIMIT 2");
		if ( ! $results ) {
			log("Matched bandcamp title but couldnt find option {$opt} - {$product}");
			wbi_debug($results);
			return false;
		} elseif ( count( $results ) > 1  ) {
			log("multiple products had the option... couldn't match it. {$opt} - {$product}");
			return false;
		} else {
			wbi_debug("Found product {$results[0]} by matching bandcamp product title {$product} AND option {$opt}");
			$product_id = $results[0];
		}
	}


	return $product_id;
	
	// maybe make an option to use matching attempts below
	
	if ( false !== stripos( " ". $data->item_name, " CD" ) ) {
	    $format = " CD";
	} elseif ( false !== stripos( $data->item_name, "cassette" ) ) {
	    $format = " MC";
	} elseif ( false !== stripos( $data->item_name, " LP" ) || false !== stripos( $data->item_name, "vinyl" ) ) {
	    $format = " LP";
	} else {
	    return false;
	}
	
	// try getting by album name
	$album = explode( ': ', $data->item_name, 2 )[0];// "natural serenity: CD digipak edition by taennya" where "CD digipak edition" is the portion you define on merch page

	$results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}posts WHERE post_type='product' AND post_title LIKE '%". esc_sql($album) ."%'" );

	// none found
	// if ( ! $results ) {
	// 	wbi_debug("no products with the album title $album");
	//
	// 	// try a shortened version
	// 	$short = $album;
	// 	foreach ( [':','(','-'] as $sep ) {
	// 		$short = explode( $sep, $short )[0];
	// 	}
	// 	if ( $short !== $album ) {
	// 		wbi_debug($short);
	// 		$results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}posts WHERE post_type='product' AND post_title LIKE '%{$short}%'" );
	// 	}
	// }
	if ( ! $results ) return false;

	// one found, return the ID
// 	if ( count( $results ) === 1 ) return $results[0]->ID;
	
	// more than one found, might be multiple formats (CD, vinyl)... there's no great way to get this from the bandcamp API
	$filtered = [];
	foreach ( $results as $r ) {
		if ( false !== strpos( $r->post_title, $format ) ) {
			$filtered[] = $r;
		}
	}
	if ( ! $filtered ) return false;
	// just one found, return the ID
	if ( count( $filtered ) === 1 ) return $filtered[0]->ID;
	// if still multiple, set $results to the filtered set for next check
	$results = $filtered;
	
	// more than one still, try finding one with the artist in the title as well
	if ( !empty( $data->artist ) ) {
    	$filtered = [];
    	foreach ( $results as $r ) {
    		if ( false !== stripos( $r->post_title, $data->artist ) ) {
    			$filtered[] = $r;
    		}
    	}
    	// just one found, return the ID
    	if ( count( $filtered ) === 1 ) return $filtered[0]->ID;
	}
	
	log("multiple products had the title... couldn't match it. {$data->item_name}");
	wbi_debug($results);
	return false;
}



/**
 * Mark Shipped on Bandcamp when status changes in Woo
 */

/**
 * tracking number is stored in 3 meta: '_tracking_number' and '_aftership_tracking_number' which are just text strings,
 * and '_wc_shipment_tracking_items' which is an array of arrays: ["tracking_number" => "RR957850707PL", "tracking_provider" => "pocztapolska", "date_shipped" => "1632987284"]
 * 	$tracking = $order->get_meta('_wc_shipment_tracking_items', 1 ); $number = $tracking[0]["tracking_number"]);
 */


add_action('woocommerce_after_order_object_save',__NAMESPACE__ .'\handle_order_completion' );// Seems to run 3 times and only has tracking the final time
function handle_order_completion( $order ){

	// wbi_debug("would remove actions at this point");
	// add_action( 'woocommerce_email', __NAMESPACE__ .'\disable_woo_emails' );// too late to run this.

	if ( 'completed' !== $order->get_status() || $order->get_meta('marked_off_on_bandcamp') || ! $order->get_meta('bandcamp_id') ) return;
	wbi_debug( "Processing tracking update for ". $order->get_id()  );
	

	$tracking = $order->get_meta('_tracking_number');// used by baselinker
	if ( ! $tracking ) {
		wbi_debug("no _tracking_number meta... looking into order notes.");
		// try order comment as added by some systems.
		// pirateship.com note looks like this:
		// Order shipped via USPS with tracking number <a target="_blank" href="https://tools.usps.com/go/TrackConfirmAction.action?tLabels=000000000" data-tracking-number="000000000">000000000</a>
		// ShipStation looks like this:
		// [name of product] x 1 shipped via USPS on July 14, 2022 with tracking number 000000000. 
		global $wpdb;

		$note = $wpdb->get_var("SELECT comment_content FROM wp_comments WHERE comment_post_ID=". $order->get_id() ." AND comment_type='order_note' AND comment_content LIKE '%with tracking number%' ORDER BY comment_date DESC LIMIT 1");
		if ( $note ) {
			if ( strpos( $note, "</" ) ) $note = strip_tags( $note );
			$tracking = explode( ' ', trim( explode( 'with tracking number', $note )[1], " ." ), 2 )[0];
			wbi_debug("tracking number parsed from order note is " . $tracking);
		}
	}

	if ( ! $tracking ) return;
	wbi_debug('mark off shipped');
	// return;

	$bcid = $order->get_meta('bandcamp_id', false);// false gets an array of WC_Meta_Data objects instead of a string value of 1st result. This is because we combine multiple bandcamp orders when possible and need to mark off each

	if ( !$bcid ) return;// no bandcamp id means this order was placed some other way. nothing to do.

	$carrier = $order->get_shipping_method();
	if ( $carrier === "Registered Priority Postal Service" ) $carrier = "Poczta Polska Registered Priority Postal Service";// probably clarify which postal service
	
	$message = notification_message( $order );

	$token = auth();
	$result = mark_shipped( $token, $bcid, $carrier, $tracking, $message );
	if ( ! $result ) {// in case the token is somehow expired or invalid, force refresh of token and retry. this shouldnt happen, but just in case...
		wbi_debug( "had to force refresh when marking ". $order->get_id() ." shipped" );
		$token = auth('refresh');
		$result = mark_shipped( $token, $bcid, $carrier, $tracking, $message );
	}
	if ( $result ) {
		$order->add_meta_data( 'marked_off_on_bandcamp', 1 );
		$order->save();
	}
}


function retry_add_tracking()
{
	$orders = wc_get_orders(['limit' => 100, 'status' => ['wc-completed'], 'meta_key' => 'marked_off_on_bandcamp', 'meta_compare' => 'NOT EXISTS']);
	foreach ( $orders as $order ) {
		handle_order_completion( $order );
	}
}

add_action( "added_post_meta", function( $mid, $object_id, $meta_key, $meta_value ){// This works.  $object_id is the order number
	// if ( $meta_key === 'hide_breadcrumb' ) {
	if ( $meta_key === '_tracking_number' ) {
		wbi_debug($object_id, "adding action within added_post_meta");
		add_action('woocommerce_after_order_object_save', __NAMESPACE__ .'\added_from_added_post_meta');
	}
}, 10, 4 );

function added_from_added_post_meta( $order ) {
	wbi_debug("running added_from_added_post_meta for order ". $order->get_id() );
	remove_action('woocommerce_after_order_object_save', __NAMESPACE__ .'\added_from_added_post_meta');
}


// JUST TESTING
// add_action( 'woocommerce_order_status_changed', __NAMESPACE__ .'\handle_status_change', 10, 4 );// id, from, to, this
// function handle_status_change( $id, $from, $to, $order ) {
add_action( 'woocommerce_order_status_completed', __NAMESPACE__ .'\handle_status_change', 10, 2 );// id, this 3. no tracking
function handle_status_change( $id, $order ) {

	add_action( "added_post_meta", function( $mid, $object_id, $meta_key, $meta_value ){
		if ( $meta_key === '_tracking_number' ) {
			wbi_debug("status change added action to added_post_meta.  Tracking: {$meta_value}");
		}
	}, 10, 4 );
}


/**
 * get custom shipping message
 */
function notification_message($order){
	if ( ! is_object( $order ) ) return;
	$message = null;
	
	$settings = get_option('mnmlbc2wc');// global setting
	
	if ( !empty ( $settings['use_vendor_settings'] ) ) {
		if ( $user_id = $order->get_customer_id() ) {
			$settings = get_user_meta( $user_id, 'mnml_bandcamp_woo', true );// per-user setting
		} else {
			$settings = [];// missing user id for this order... probably don't use global setting.
		}
	}
	
	if ( ! empty( $settings['notification_message'] ) ) {
		$message = $settings['notification_message'];
		// merge tag
		if ( stripos( $message, "%name%" ) !== false )
		{
			$name = $order->get_shipping_first_name();
			if ( !$name || strlen($name) === 1 ) $name = $order->get_formatted_shipping_full_name();// get full name if first name is just an initial, to seem less weird "Hello P"
			if ( $name ) {
				$message = str_ireplace( "%name%", $name, $message );
			} else {
				$message = str_ireplace( [" %name%","%name%"], '', $message );// gets rid of possible space if name is missing. ("Hello ," vs "Hello,") really not sure how this would ever happen though.
			}
		}
	}
	return $message;
}

/**
 * Bandcamp API Functions
 */

function mark_shipped( $token, $bcid, $carrier, $tracking, $message ) {

	$body = [ 'items' => [] ];

	// $bcid = explode( ',', $bcid );

	foreach ( $bcid as $i ) {
		$body['items'][] = [
			"id"					=> (int) $i->value,
			"id_type"				=> "p",
			"notification_message"	=> $message,
			"carrier"				=> $carrier,
			"tracking_code"			=> $tracking
		];
	}

	$options = [
		CURLOPT_URL => 'https://bandcamp.com/api/merchorders/2/update_shipped',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_HTTPHEADER => [ "Content-Type:application/json", "Authorization: Bearer $token" ],
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => json_encode($body)
	];

	$ch = curl_init();
	curl_setopt_array($ch, $options);
	$json = curl_exec($ch);
	curl_close($ch);
	$result = json_decode($json);

	if ( empty ( $result->success ) ) {
		wbi_debug( "marking shipped via bandcamp api failed" );
		if ( is_null( $result ) ) wbi_debug($json);
		else wbi_debug($result);
		return false;
	}
	wbi_debug( "marking shipped via bandcamp api success" );
	return true;
}


function get_orders( $band_id, $token ) {

	$body = '{"band_id":' . $band_id .',"unshipped_only":"true","start_time":"'. date( 'Y-m-d', strtotime('-2 year') ) .'"}';

	// https://www.php.net/manual/en/function.curl-setopt.php
	$options = [
		CURLOPT_URL => 'https://bandcamp.com/api/merchorders/3/get_orders',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_HTTPHEADER => [ "Content-Type:application/json", "Authorization: Bearer $token" ],
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => $body,
	];
	// wbi_debug($body);
	$ch = curl_init();
	curl_setopt_array($ch, $options);
	$json = curl_exec($ch);
	curl_close($ch);
	$result = json_decode($json);

	if ( empty ( $result->success ) ) {
		wbi_debug("get orders call result not ok");
		if ( is_null( $result ) ) wbi_debug($json);
		else wbi_debug($result);
		return false;
	}
	$orders = $result->items;
// 	echo "<pre>"; var_dump($orders); echo "</pre>";
	return $orders;
}

function bands( $token ) {

	$options = array(
		CURLOPT_URL => 'https://bandcamp.com/api/account/1/my_bands',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_HTTPHEADER => ["Authorization: Bearer $token" ],
		CURLOPT_POST => true,
	);
	$ch = curl_init();
	curl_setopt_array($ch, $options);
	$json = curl_exec($ch);
	curl_close($ch);
	$result = json_decode($json);

	if ( empty ( $result->bands) ) {
		wbi_debug("band call result not ok");
		if ( is_null( $result ) ) wbi_debug( $json );
		else wbi_debug($result);
		return false;
	}
	return $result->bands;
}


function auth( $force_refresh=false, $return_array=false ){

	$token = get_option( 'mnmlbc2wc_token' );

	$refresh_token = '';

	if ( $token ) {
		if ( $token['expires'] > time() && ! $force_refresh ) {
			wbi_debug( "using saved token" );
			return $return_array ? $token : $token['access'];
		}// else
		wbi_debug( "refreshing" );
		$refresh_token = $token['refresh'];
	}

	$settings = !empty( $GLOBALS['bc2wc_settings'] ) ? $GLOBALS['bc2wc_settings'] : get_option('mnmlbc2wc');

	if ( ! empty( $settings['fetch_tokens_url'] ) ) {
		$token = fetch_token();
		if ( $token ) {
			wbi_debug("using saved token from remote site");
			return $return_array ? $token : $token['access'];
		} else {
			wbi_debug("tried but failed to get token from remote site.");
			return false;
		}
	}

	$grant_type = $refresh_token ? 'refresh_token' : 'client_credentials';
	
	if ( empty($settings['client_id']) || empty($settings['client_secret']) ) {
		wbi_debug('no bandcamp credentials... can’t do anything');
		return false;
	}

	$body = http_build_query([
		'grant_type'	=> $grant_type,
		'client_id'		=> $settings['client_id'],
		'client_secret'	=> $settings['client_secret'],
		'refresh_token'	=> $refresh_token
	]);

	// https://www.php.net/manual/en/function.curl-setopt.php
	$options = array(
		CURLOPT_URL => 'https://bandcamp.com/oauth_token',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => $body
	);

	$ch = curl_init();
	curl_setopt_array($ch, $options);
	$json = curl_exec($ch);
	curl_close($ch);
	$result = json_decode($json);

	if ( empty ( $result->ok ) ) {
	    
	    if ( $result->error === "invalid_refresh" ) {
	        delete_option( 'mnmlbc2wc_token' );
	        wbi_debug("invalid token, deleting now");
	    } else {
    		wbi_debug("auth call result not ok");
    		if ( is_null( $result ) ) wbi_debug($json);
    		else wbi_debug($result);
	    }
		return false;
	}
	
	$token = [
		'access' => $result->access_token,
		'refresh' => $result->refresh_token,
		'expires' => time() + 3570
	];

	update_option( 'mnmlbc2wc_token', $token, false );

	// echo "<pre>"; var_dump($token); echo "</pre>";
	return $return_array ? $token : $token['access'];
}

function fetch_token(){

	$options = get_option('mnmlbc2wc',[]);
	if ( empty( $options['client_secret'] ) ) {
		wbi_debug('no client secret!');
		return false;
	}
	if ( empty( $options['fetch_tokens_url'] ) ) {
		wbi_debug('no client secret!');
		return false;
	}
	$hash = password_hash( $options['client_secret'], PASSWORD_BCRYPT, ["cost" => 10] );
	$url = rtrim( $options['fetch_tokens_url'], '/ ' ) . '/wp-json/mnmlbc2wc/v1/t';// shouldn't have wp-json hard-coded, I guess.
	$options = [
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_HTTPHEADER => [ "Content-Type:application/json", "Authorization: $hash" ],
	];
	$ch = curl_init();
	curl_setopt_array($ch, $options);
	$json = curl_exec($ch);
	curl_close($ch);
	$token = json_decode($json);

	if ( empty( $token->access) ) {
		wbi_debug("Something wrong with the api return");
		wbi_debug($json);
	}
	$token = (array) $token;

	wbi_debug($token);
	update_option( 'mnmlbc2wc_token', $token, false );

	return $token;
}

/************************
* Settings Page
**/


add_action( 'rest_api_init', __NAMESPACE__ .'\register_api_endpoint' );
function register_api_endpoint() {
	register_rest_route( 'mnmlbc2wc/v1', '/i', ['methods' => 'GET', 'callback' => __NAMESPACE__ .'\api_process', 'permission_callback' => function(){ return current_user_can('import');} ] );
	register_rest_route( 'mnmlbc2wc/v1', '/s', ['methods' => 'POST', 'callback' => __NAMESPACE__ .'\api_settings', 'permission_callback' => function(){ return current_user_can('import');} ] );
	$options = get_option('mnmlbc2wc',[]);
	if ( !empty( $options['serve_tokens'] ) ) {
		register_rest_route( 'mnmlbc2wc/v1', '/t', ['methods' => 'GET', 'callback' => __NAMESPACE__ .'\api_tokens', 'permission_callback' => '__return_true' ] );
	}
}

function api_process( $request ) {
	// $data = $request->get_params();// in future, may want to get specific band ids on request
	$report = main_process();
	$return = [ 'terse' => $report ];
	$return['verbose'] = !empty( $GLOBALS['bc_wc_log'] ) ? $GLOBALS['bc_wc_log'] : 'nothing done';
	return $return;
}

function api_settings( $request ) {
	fetch_token();

	$data = $request->get_body_params();
	foreach ( $data as $k => $v ) update_option( $k, $v, false );
	
	set_default_customer($data);

	set_cron($data);
	
	return "Saved";
}

function set_cron( $data ) {

	if ( empty($data['mnmlbc2wc']['client_id']) || empty($data['mnmlbc2wc']['client_secret']) ) {
		wp_unschedule_event( wp_next_scheduled( 'mnmlbc2wc_main_cron_hook' ), 'mnmlbc2wc_main_cron_hook' );
	} else {
		if ( ! wp_next_scheduled( 'mnmlbc2wc_main_cron_hook' ) ) {
			wp_schedule_event( strtotime('+ 1 minute'), 'hourly', 'mnmlbc2wc_main_cron_hook' );
		}
	}
}
add_action( 'mnmlbc2wc_main_cron_hook', __NAMESPACE__ .'\main_process' );


function set_default_customer( $data ) {
    if ( !empty( $data['mnmlbc2wc']['assign_orders_to'] ) && is_numeric( $data['mnmlbc2wc']['assign_orders_to'] ) ) {
        global $wpdb;
        $wpdb->query( "UPDATE $wpdb->postmeta SET meta_value = '{$data['mnmlbc2wc']['assign_orders_to']}' WHERE meta_key = '_customer_user'" );//  AND meta_value = '0'
    }
}

function api_tokens( $request ) {
	$hash = $request->get_header('authorization');
	// wbi_debug($hash);
	// $data = $request->get_params();
	// wbi_debug($request);
	if ( empty( $hash ) ) {
		wbi_debug('no hash sent in api call!');
		return '';
	}
	$options = get_option('mnmlbc2wc',[]);
	if ( empty( $options['client_secret'] ) ) {
		wbi_debug('no client secret!');
		return '';
	}
	if ( ! password_verify( $options['client_secret'], $hash ) ) {
		wbi_debug('hash doesnt match!');
		return '';
	}
	$token = auth( false, 'return array' );
	if ( ! $token ) {
		wbi_debug('no token!');
		return '';
	}
	return $token;
}


add_action( 'admin_menu', __NAMESPACE__ .'\admin_menu' );
function admin_menu() {
	add_submenu_page( 'options-general.php', 'Bandcamp / WooCommerce Integration', 'Bandcamp + Woo', 'edit_users', 'mnmlbc2wc', __NAMESPACE__ .'\settings_page', 100 );
	add_submenu_page( 'tools.php', 'Bandcamp / WooCommerce Integration', 'Bandcamp + Woo Import', 'edit_users', 'mnmlbc2wc-import', __NAMESPACE__ .'\backend_import', 100 );

}

function backend_import() {
    
    $url = rest_url('mnmlbc2wc/v1/');
	$nonce = "x.setRequestHeader('X-WP-Nonce','". wp_create_nonce('wp_rest') ."')";
	?>
<div class=wrap>
	<h1>Bandcamp / WooCommerce Integration</h1>
	<div>
	    <h2>Import orders from Bandcamp</h2>
		<button class=button onclick="var t=this,x=new XMLHttpRequest;t.textContent='working...';x.open('GET','<?php echo $url.'i'; ?>'),<?php echo $nonce; ?>,x.onload=function(){t.textContent='Run Bandcamp Import';document.querySelector('#bcwclog').textContent=JSON.parse(x.response).verbose},x.send()">Run Bandcamp Import</button>
	</div>
	<div>
		<h2>Log</h2>
		<pre id=bcwclog><?php show_log(); ?></pre>
	</div>
	<hr>
	<div>
	    <h2>Import orders from a CSV / TSV</h2>
	    <?php $result = order_import();
	    echo $result; ?>
	</div>
</div>
    <?php
}

function settings_page() {

	$url = rest_url('mnmlbc2wc/v1/');
	$nonce = "x.setRequestHeader('X-WP-Nonce','". wp_create_nonce('wp_rest') ."')";
	?>
<div class=wrap>
	<h1>Bandcamp / WooCommerce Integration</h1>
	<div>
		<button class=button onclick="var t=this,x=new XMLHttpRequest;t.textContent='working...';x.open('GET','<?php echo $url.'i'; ?>'),<?php echo $nonce; ?>,x.onload=function(){t.textContent=JSON.parse(x.response).terse},x.send()">Run Import</button>
	</div>
	<form onsubmit="event.preventDefault();var t=this,b=t.querySelector('button'),x=new XMLHttpRequest;x.open('POST','<?php echo $url.'s'; ?>'),<?php echo $nonce; ?>,x.onload=function(){b.textContent=JSON.parse(x.response)},x.send(new FormData(t))">
	<p><button class=button-primary>Save Changes</button>
	<?php
	
	$main = array_fill_keys(['client_id','client_secret','serve_tokens','fetch_tokens_url','dont_calculate_shipping','combine_orders','assign_orders_to','use_vendor_settings','bands_include','bands_exclude','exclude_countries','include_countries','notification_message'],['type' => 'text']);
	$main['serve_tokens'] = ['type' => 'checkbox', 'desc' => "If using the same API key on multiple sites, one site must be the master and serve API tokens to the rest.  This option sets the master site."];
	$main['fetch_tokens_url']['desc'] = "If this is a non-master site which must fetch tokens for a shared API key, set this to the URL of the master site (the site which as the above option checked).";
	$main['dont_calculate_shipping'] = ['type' => 'checkbox', 'desc' => "just set shipping cost to 0"];
	$main['combine_orders'] = ['type' => 'checkbox', 'desc' => "merge seperate orders with same shipping address & name  (This will pause all orders by anyone who has ordered a pre-order item)"];
    $main['use_vendor_settings'] = ['type' => 'checkbox', 'desc' => "Disable global options below.  Use per-user options found in the user's profile.  Only import orders for bandcamp IDs that have been added to a user profile."];
	$main['bands_include']['desc'] = "import these bands only (bandcamp band id numbers, comma separated)";
	$main['bands_exclude']['desc'] = "exclude these bands (bandcamp band id numbers, comma separated)";
	$main['exclude_countries']['desc'] = "exclude these countries (2-letter country codes, comma seperated)";
	$main['include_countries']['desc'] = "import these countries only (2-letter country codes, comma seperated)";
	$main['notification_message'] = ['type' => 'textarea', 'desc' => "optional message that Bandcamp will send to the customer when you mark it as shipped.  Use %name% to insert the customer’s name."];
	$main['assign_orders_to'] = ['callback' => 'bc2wc_assign_order_to'];
	
	$address = array_fill_keys(['billing_first_name','billing_last_name','billing_phone','billing_email','billing_address_1','billing_address_2','billing_city','billing_state','billing_postcode','billing_country','billing_vat_number'],['type' => 'text']);
    $address['billing_country'] = ['type' => 'select', 'options' => WC()->countries->get_countries() ];
    
	$options = [
		'mnmlbc2wc' => $main,
		'mnmlbc2wc_address' => $address
	];
	
	echo '<table class=form-table>';
	foreach ( $options as $g => $fields ) {
		$values = get_option($g);
		echo "<input type=hidden name='{$g}[x]' value=1>";// hidden field to make sure things still update if all options are empty (defaults)
		foreach ( $fields as $k => $f ) {
			$v = isset( $values[$k] ) ? $values[$k] : '';
			$l = isset( $f['label'] ) ? $f['label'] : str_replace( '_', ' ', $k );
			$size = !empty( $f['size'] ) ? $f['size'] : 'regular';
			echo "<tr id='row-{$g}-{$k}'><th><label for='{$g}-{$k}'>{$l}</label><td>";
			if ( !empty( $f['callback'] ) && function_exists( __NAMESPACE__ .'\\'. $f['callback'] ) ) {
                call_user_func( __NAMESPACE__ .'\\'. $f['callback'], $g, $k, $v, $f );
	        } else {
    			switch ( $f['type'] ) {
    				case 'textarea':
    					echo "<textarea id='{$g}-{$k}' name='{$g}[{$k}]' placeholder='' rows=8 class={$size}-text>{$v}</textarea>";
    					break;
    				case 'checkbox':
    					echo "<input id='{$g}-{$k}' name='{$g}[{$k}]'"; if ( $v ) echo " checked"; echo " type=checkbox >";
    					break;
    				case 'number':
    					$size = !empty( $f['size'] ) ? $f['size'] : 'small';
    					echo "<input id='{$g}-{$k}' name='{$g}[{$k}]' placeholder='' value='{$v}' class={$size}-text type=number>";
    					break;
    				case 'select':
    				    if ( !empty( $f['options'] ) && is_array( $f['options'] ) ) {
        				    echo "<select id='{$g}-{$k}' name='{$g}[{$k}]'>";
        				    echo "<option value=''></option>";// placeholder
                            foreach ( $f['options'] as $key => $value ) {
                        		echo "<option value='{$key}'" . selected( $v, $key, false ) . ">{$value}</option>";
                        	}
        				    echo "</select>";
    				    }
    				    break;
    				case 'text':
    				default:
    					echo "<input id='{$g}-{$k}' name='{$g}[{$k}]' placeholder='' value='{$v}' class={$size}-text>";
    					break;
    			}
	        }
			if ( !empty( $f['desc'] ) ) echo "<p class=description>". $f['desc'];
		}
	}
	echo '</table>';

	?>
	</form>
	<script>
	function vendorToggle(){
		var c=document.querySelector('#mnmlbc2wc-use_vendor_settings').checked ? "none" : "";
		document.querySelectorAll('[id^=row-mnmlbc2wc-bands_],[id^=row-mnmlbc2wc_address],[id=row-mnmlbc2wc-exclude_countries],[id=row-mnmlbc2wc-include_countries],[id=row-mnmlbc2wc-notification_message]').forEach(function(e){e.style.display=c});
	}
	document.querySelector('#mnmlbc2wc-use_vendor_settings').addEventListener('change',vendorToggle);
	vendorToggle();
	</script>
</div>
<?php
}

function bc2wc_assign_order_to( $g, $k, $v, $f ) {
    wp_dropdown_users( [ 'show_option_all' => " ", 'name' => "{$g}[{$k}]", 'id' => "{$g}-{$k}", 'selected' => $v ] );
}


/***
 * Custom product field for entering bandcamp title exactly
 * Functions written to handle variations and simple products
 */
add_action( 'woocommerce_product_after_variable_attributes', __NAMESPACE__ . '\add_bandcamp_title_field_option', 10, 2 );
add_action( 'woocommerce_product_options_general_product_data', __NAMESPACE__ . '\add_bandcamp_title_field', 10, 0 );
add_action( 'woocommerce_save_product_variation', __NAMESPACE__ . '\save_bandcamp_title_field', 10, 2 );
add_action( 'save_post_product', __NAMESPACE__ . '\save_bandcamp_title_field', 10, 1 );

function add_bandcamp_title_field_option( $var, $data ){
	woocommerce_wp_text_input([
		'id'            => "bandcamp_title{$var}",
		'label'         => "Bandcamp Option Name",
		'wrapper_class' => 'form-row form-row-full',
		'value'         => !empty($data['bandcamp_title']) ? $data['bandcamp_title'][0] : null,// other way: get_post_meta( $variation->ID, 'bandcamp_title', true ) // $variation is the 3rd function param
		'placeholder'   => 'eg: Large, Splatter',
		'desc_tip'      => true,
		'description'   => "Optional. Use this to match Bandcamp products to Woo products if you can't use SKUs.",
	]);
}

function add_bandcamp_title_field(){
	// this function populates the value automatically
	woocommerce_wp_text_input([
		'id'            => "bandcamp_title",
		'label'         => "Bandcamp Product Title",
		'style'			=> "width:calc(100% - 25px)",// as much room as we can - room for tool tip
		'wrapper_class' => 'show_if_simple show_if_variable',
		// 'placeholder'   => 'Album Title: 12" Vinyl LP',//exactly as it appears in the bandcamp fulfillment portal. exclude “by artist”',
		'desc_tip'      => true,
		'description'   => "Optional. Use this to match Bandcamp products to Woo products if you can't use SKUs.  Enter exactly as shown on Bandcamp order page, or import log. Don't include “by artist”",
	]);
}

function save_bandcamp_title_field( $id, $var='' ) {
	empty($_POST['bandcamp_title'. $var]) ? delete_post_meta($id, 'bandcamp_title') : update_post_meta($id, 'bandcamp_title', $_POST['bandcamp_title'. $var]);
}


/**
 * Extra fields in user profiles
 */
add_action('show_user_profile', __NAMESPACE__ .'\usermeta_form_field', 11);
add_action('edit_user_profile', __NAMESPACE__ .'\usermeta_form_field', 11);
add_action('personal_options_update', __NAMESPACE__ .'\usermeta_form_field_update');
add_action('edit_user_profile_update', __NAMESPACE__ .'\usermeta_form_field_update');
function usermeta_form_field( $user ) {
	$meta = get_user_meta( $user->ID, 'mnml_bandcamp_woo', true );
	if ( !$meta ) $meta = [];
	$meta = array_merge( ['band_id' => '', 'notification_message' => '', 'exclude_country' => '', 'include_country' => ''], $meta );

	?>
	<h3>Bandcamp + Woo Settings</h3>
	<table class=form-table>
		<tr>
			<th><label for=bandcamp_band_id>Bandcamp Band ID</label></th>
			<td><input class=regular-text id=bandcamp_band_id name="mnml_bandcamp_woo[band_id]" value="<?php echo esc_attr( $meta['band_id'] ) ?>">
		<tr>
			<th><label for=bandcamp_exclude_country>Exclude Country Codes</label></th>
			<td><input class=regular-text id=bandcamp_exclude_country name="mnml_bandcamp_woo[exclude_country]" value="<?php echo esc_attr( $meta['exclude_country'] ) ?>">
		<tr>
			<th><label for=bandcamp_include_country>Include Country Codes</label></th>
			<td><input class=regular-text id=bandcamp_include_country name="mnml_bandcamp_woo[include_country]" value="<?php echo esc_attr( $meta['include_country'] ) ?>">
		<tr>
			<th><label for=bandcamp_notification_message>Bandcamp Shipment Message</label></th>
			<td><textarea class=regular-text id=bandcamp_notification_message name="mnml_bandcamp_woo[notification_message]" rows=8><?php echo esc_attr( $meta['notification_message'] ) ?></textarea>
	</table>
	<?php
}
function usermeta_form_field_update( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) return false;
	if ( isset($_POST['mnml_bandcamp_woo']) ) {
		if ( array_filter($_POST['mnml_bandcamp_woo']) ) {
			return update_user_meta( $user_id, 'mnml_bandcamp_woo', $_POST['mnml_bandcamp_woo'] );
		} else {
			return delete_user_meta( $user_id, 'mnml_bandcamp_woo' );
		}
	}
}


/**
 * Disable Woo Emails
 */
function disable_woo_emails( $email_class ) {

	wbi_debug("run disable emails");

	// New order emails sent to Admin... do we want these?
	remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
	remove_action( 'woocommerce_order_status_pending_to_completed_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
	remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
	
	// Processing order emails
	remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $email_class->emails['WC_Email_Customer_Processing_Order'], 'trigger' ) );
	// remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $email_class->emails['WC_Email_Customer_Processing_Order'], 'trigger' ) );
	
	// Completed order emails
	remove_action( 'woocommerce_order_status_completed_notification', array( $email_class->emails['WC_Email_Customer_Completed_Order'], 'trigger' ) );
		
	// Note emails
	remove_action( 'woocommerce_new_customer_note_notification', array( $email_class->emails['WC_Email_Customer_Note'], 'trigger' ) );
}

// add_action( 'woocommerce_order_status_completed_notification', function(){ wbi_debug("woocommerce_order_status_completed_notification"); });// temp

// add_action( 'woocommerce_email', function(){ wbi_debug("woocommerce_email"); });// temp

add_filter('woocommerce_email_enabled_customer_completed_order', __NAMESPACE__.'\disable_woo_emails_via', 10, 2 );
function disable_woo_emails_via( $bool, $object ) {
	if ( $object && get_class($object) === "WC_Order") {
		if ( $object->get_created_via() === "Bandcamp Integration" ) {
			wbi_debug("disabling email because created via = Bandcamp Integration");
			$bool = false;
		}
	}
	return $bool;
}




add_filter('woocommerce_account_orders_columns', __NAMESPACE__.'\account_orders_columns', 1, 9 );
function account_orders_columns( $columns ){
	$columns = [
		'order-number'  => 'Order',
		'order-date'    => 'Date',
		'order-shipto'  => 'Ship To',
		'order-items'   => 'Items',
		'order-total'   => 'Total',
		'order-status'  => 'Status',
		'order-tracking' => 'Tracking',
		'order-carrier' => 'Carrier',
		'order-source' => 'Source',
		'order-actions' => 'Actions',
	];
	return $columns;
}

add_action( 'woocommerce_my_account_my_orders_column_order-source', function($order){
    // echo $order->get_payment_method();// wasnt set properly on  CSV imports before 2022-05-19
    echo $order->get_meta('bandcamp_id') ? 'Bandcamp' : 'Manual';
}, 1, 10 );

add_action( 'woocommerce_my_account_my_orders_column_order-carrier', function($order){
    echo $order->get_shipping_method();
}, 1, 10 );

add_action( 'woocommerce_my_account_my_orders_column_order-shipto', function($order){
    // echo implode( ' ', $order->get_address('shipping') );
    echo $order->get_shipping_first_name() .' '. $order->get_shipping_last_name();
}, 1, 10 );

add_action( 'woocommerce_my_account_my_orders_column_order-items', function($order){
    $order_items = $order->get_items( 'line_item' );
    foreach ( $order_items as $item )
        echo $item->get_quantity() ."x ". $item->get_name();
}, 1, 10 );

add_action( 'woocommerce_my_account_my_orders_column_order-tracking', function($order){
    if ( $tracking = $order->get_meta('_tracking_number') ) echo $tracking;
}, 1, 10 );

add_action( 'woocommerce_before_account_orders', function(){
    ?>
    <style>
    .woocommerce-orders .container {width:100%}
    .woocommerce table.my_account_orders td {padding:1em 1em 1em 0}
    .woocommerce nav.woocommerce-MyAccount-navigation {
        float: none;
        width: auto;
        padding: 0 0 5em;
        border: 0;
        text-align: center;
    }
    .woocommerce div.woocommerce-MyAccount-content {
        float: none;
        padding: 0;
        width: 100%;
    }
    .woocommerce .woocommerce-MyAccount-navigation li.woocommerce-MyAccount-navigation-link {
        display: inline-block;
        margin: 0 1em;
    }
    .woocommerce-MyAccount-order-search {
        margin: 0 1em 3em;
        text-align: right;
    }
    .woocommerce-MyAccount-order-search select,
    .woocommerce-MyAccount-order-search input {
        margin: 0 2em 0 1ex;
    }
    .woocommerce-pagination,
    .woocommerce-pagination-message {
        text-align: center;
        margin-top: 3em;
    }
    </style>
    <?php
    
    $find = empty($_GET['find']) ? '' : esc_attr($_GET['find']);
    $from = empty($_GET['from']) ? '' : esc_attr($_GET['from']);
    $to = empty($_GET['to']) ? '' : esc_attr($_GET['to']);
    $status = empty($_GET['status']) ? '' : esc_attr($_GET['status']);
    
    // $action = preg_replace( '/\d?\/$/', '', $_SERVER['DOCUMENT_URI'] );// remove page numbers
    $action = wc_get_endpoint_url( 'orders' );//  "?". $_SERVER['QUERY_STRING']
    ?>
    <form class=woocommerce-MyAccount-order-search method=get action='<?php echo $action; ?>'>
        From: <input name=from type=date value='<?php echo $from; ?>'>
        To: <input name=to type=date value='<?php echo $to; ?>'>
        <input type=text name=find value='<?php echo $find; ?>' placeholder='search term'>
        <select name=status>
            <option value=''>Status</option>
            <option value=processing <?php selected($status, 'processing' ); ?>>Processing</option>
            <option value=completed <?php selected($status, 'completed' ); ?>>Completed</option>
        </select>
        <button>Search</button>
     </form>
     <?php
     
    //  var_export($customer_orders);
});




// This might be temporary, to avoid retroactively assigning orders to the right user.
// UPDATE `wp_postmeta` SET `meta_value` = '3' WHERE `meta_key` = '_customer_user';
add_filter( 'woocommerce_my_account_my_orders_query', function($query){
    $settings = get_option('mnmlbc2wc');
    if ( !empty( $settings['assign_orders_to'] ) && (int) $settings['assign_orders_to'] === get_current_user_id() ) {
	    unset($query['customer']);
    }
    
    // Date search
    if ( !empty( $_GET['from'] ) || !empty( $_GET['to'] ) ) {
        if ( empty( $_GET['from'] ) ) $query['date_created'] = "<=" . $_GET['to'];
        elseif ( empty( $_GET['to'] ) ) $query['date_created'] = ">=" . $_GET['from'];
        else $query['date_created'] = $_GET['from'] . "..." . $_GET['to'];
    }
    // Text Search
    // https://github.com/woocommerce/woocommerce/blob/07b02fb858f6fade3579f8cef73c5b13fbc33ce7/plugins/woocommerce/includes/data-stores/class-wc-order-data-store-cpt.php#L514
    // https://github.com/woocommerce/woocommerce/blob/adc5b1ba425d1e4ee01c06da16dc984b1b521f4b/plugins/woocommerce/includes/admin/list-tables/class-wc-admin-list-table-orders.php#L879
    if ( !empty($_GET['find'] ) ) {
        add_filter( 'woocommerce_shop_order_search_fields', function(){ return ['_shipping_address_index','_tracking_number']; } );
        $post_ids = wc_order_search( wc_clean( wp_unslash( $_GET['find'] ) ) );// are these cleaners really needed
        if ( !empty( $post_ids ) ) {
            $query['post__in'] = $post_ids;
            $query['limit'] = -1;// show all for text searches (don't paginate)
        } else {
            $query['post__in'] = [0];
            echo "<p class='woocommerce-message woocommerce-info'>No Search Results</p>";
        }
    }
    // Status search
    if ( !empty( $_GET['status'] ) ) {
        $query['status'] = $_GET['status'];
    }
    
    if ( isset($query['limit']) && $query['limit'] === -1 ) {
        $GLOBALS['bc2wc_orders_pagination_message'] = 'all';
    } else {
        $query['limit'] = 20;
        $GLOBALS['bc2wc_orders_pagination_message'] = $query['limit'];
    }
    add_action( 'woocommerce_before_template_part', __NAMESPACE__ . '\set_pagination_message', 4, 10 );
    
    if ( $_GET ) {
        add_filter( 'woocommerce_get_endpoint_url', __NAMESPACE__ . '\add_search_params', 3, 10 );
    }
    
	return $query;
}, 1, 10 );

function add_search_params( $url, $endpoint, $value ) {
    if ( $endpoint === 'orders' && is_numeric($value) ) {
        $url .= "?". $_SERVER['QUERY_STRING'];
    }
    return $url;
}

function set_pagination_message( $name, $path, $located, $args ) {
    if ( 'myaccount/orders.php' === $name ) {
        if ( is_numeric( $GLOBALS['bc2wc_orders_pagination_message'] ) ) {
            $per_page = $GLOBALS['bc2wc_orders_pagination_message'];
            $first = $per_page * ( $args['current_page'] - 1 ) + 1;
            $last = count( $args['customer_orders']->orders ) + $first - 1;
            $GLOBALS['bc2wc_orders_pagination_message'] = "Displaying $first – $last of " . $args['customer_orders']->total;
        } else {
            $GLOBALS['bc2wc_orders_pagination_message'] = "Displaying all " . $args['customer_orders']->total . " results";
        }
    }
}

add_action( 'woocommerce_before_account_orders_pagination', function(){
    echo "<p class=woocommerce-pagination-message>{$GLOBALS['bc2wc_orders_pagination_message']}</p>";
} );

// function register_role(){
//     add_role( 'order_viewer', 'Bandcamp Order Viewer', ['read' => true, 'read_shop_order' => true, 'edit_shop_order' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true ] );
// }
// register_activation_hook( __FILE__, __NAMESPACE__ .'\register_role' );
// function deregister_role(){
//     remove_role('order_viewer');
// }
// register_deactivation_hook( __FILE__, __NAMESPACE__ .'\deregister_role' );


/**
 * CSV Import
 **/
 
 add_shortcode('bandcamp_woo_order_import', __NAMESPACE__ .'\order_import' );

function order_import( $a='' ) {

	$form = '<form method=post enctype="multipart/form-data">'
		   . '<input type=file id=csv name=csv>'
		   . '<p>please make sure the first line of the CSV is:</p> <pre>first name or full name,last name or blank,address 1,address 2,city,state,postcode,country,quantity,sku,artist,item name,option</pre>'
		   . '<button class=button type=submit>Import CSV</button></form>';
	
	if ( empty($_FILES) ) return $form;
	
	$html = '';
	
	ini_set("auto_detect_line_endings", true);// permit Mac line endings
	
	// make an array out of the uploaded file
	$csv = file( $_FILES['csv']['tmp_name'], FILE_IGNORE_NEW_LINES );

	// Default to comma delimiter but test for "tsv" in filename or number of tabs in first line if no "csv" in name
	$delimiter = ",";
	if ( stripos( $_FILES['csv']['name'], '.tsv' ) ) {
		$delimiter = "\t";
	} elseif ( false === stripos( $_FILES['csv']['name'], '.csv' ) ) {
		if ( substr_count( $csv[0], "\t" ) > substr_count( $csv[0], "," ) ) {
			$delimiter = "\t";
		}
	}
		
	// convert UTF-16le for bandcamp
	$need_to_convert = false;
	if (chr(0xFF) . chr(0xFE) === substr($csv[0], 0, 2) ) {	
		$csv[0] = mb_convert_encoding( substr($csv[0], 2), 'UTF-8', 'UTF-16LE' );
		$need_to_convert = true;
	}

	// compare header row to make sure this is a csv we can work with
	if ( str_replace("\t", ",", strtolower($csv[0]) ) !== "first name or full name,last name or blank,address 1,address 2,city,state,postcode,country,quantity,sku,artist,item name,option" ) {
		$html .= "<p>please make sure the first line of the CSV is:</p><pre>first name or full name,last name or blank,address 1,address 2,city,state,postcode,country,quantity,sku,artist,item name,option</pre>";
		return $html . $form;
	}

    // Prepare Data Stuff
	add_action( 'woocommerce_email', __NAMESPACE__ .'\disable_woo_emails' );
	add_filter( 'woocommerce_is_purchasable', '__return_true' );// is_purchasable()
	add_filter( 'woocommerce_product_is_in_stock', '__return_true' );// is_in_stock()
	add_filter( 'woocommerce_product_backorders_allowed', '__return_true' );// has_enough_stock()
	add_filter( 'woocommerce_product_backorders_require_notification', '__return_false' );
	$settings = $GLOBALS['bc2wc_settings'] = get_option('mnmlbc2wc');
	$count = 0;
	$o = [];
	$order_key = "";
	$vendor_address = vendor_address('global');
	$columns = [
	    'shipping_first_name',
	    'shipping_last_name',
	    'shipping_address_1',
	    'shipping_address_2',
	    'shipping_city',
	    'shipping_state',
	    'shipping_postcode',
	    'shipping_country',
	    'quantity',
	    'sku',
	    'artist',
	    'item_name',
	    'option',
	    ];
	    
    // process rows
	foreach ( $csv as $index => $row ) {
	    
		if ( $need_to_convert ) $row = mb_convert_encoding( $row, 'UTF-8', 'UTF-16' );// fix char encoding for bandcamp
		if ( ! $row || $index === 0 ) continue;
		
		$row = str_getcsv( $row, $delimiter );
        
        $data = array_combine( $columns, array_slice( $row, 0, count($columns) ) );
        if ( ! $data ) return "<p>CSV has too few columns" . $form;
	
	    if ( !empty( $data['shipping_first_name'] ) ) {
            $order_key = $data['shipping_first_name'] .'---'. $data['shipping_address_1'];
	    } else {
	        if ( ( empty( $data['sku'] ) && empty( $data['item_name'] ) ) || ! $order_key ) {
	            continue;// blank line apparently
	        }// if line has no customer info, but does have an item, reuse last order_key, this is another line item for the previous line.
	    }
	    
    	if ( empty( $o[ $order_key ] ) ) {
    	    
    	   // allow for full name in first column (leave last name column blank)    
            if ( empty( $data['shipping_last_name'] ) ) {
                $name = explode( ' ', $data['shipping_first_name'], 2 );
                $data['shipping_first_name'] = $name[0];
                $data['shipping_last_name'] = isset($name[1]) ? $name[1] : '';
            }
            
            // prepare address array with only the columes begining with "shipping"
            // $address = array_filter( $data, function($k) { return substr( $k, 0, 8 ) === 'shipping'; }, ARRAY_FILTER_USE_KEY );
            $address = array_slice( $data, 0, 8 );
    
    		$o[ $order_key ] = [ 'shipping' => $address, 'bc_id' => [], 'line_items' => [] ];// bc_id isnt used here but make_woo_order() expects it, and maybe it would be useful in future
    	}
        
		// run function with a few ways to try to find it
		$data['item_name'] = str_replace( ',', ':', $data['item_name'] );// stupidly bandcamp CSVs use commas where the API uses colons.
		$product_id = find_woo_product( (object) $data );
		
		if ( ! $product_id ) {
			$html .= "<p>Couldn't find product ID for {$data['item_name']}.  The order containing this product will not be imported.  See line " . (1 + $index);
            $o[ $order_key ]['missing_item'] = $data['item_name'];
		}
    	
        // add product as line item
        if ( ! $data['quantity'] ) $data['quantity'] = 1;
    	$o[ $order_key ]['line_items'][] = [ 'product_id' => $product_id, 'quantity' => $data['quantity'] ];
	}

    $li = "";
	foreach ( $o as $order ) {
		$order['billing'] = $vendor_address;
		$order['source'] = 'import';
        if ( !empty( $settings['assign_orders_to'] ) ) {
		    $order['user_id'] = $settings['assign_orders_to'];
		}
		$order_id = make_woo_order( $order );
		if ( $order_id ) {
		    $li .= "<li>{$order['shipping']['shipping_first_name']} {$order['shipping']['shipping_last_name']}";
		    $count++;
		}
	}
	$html .= "<p>{$count} orders created:</p><ol>{$li}</ol>";
	
	$html .= "<p>{$_FILES['csv']['name']} finished at " . date('h:i:s T');
	
	return $html . $form;
}

/* Log for admin */
function log( $text ){
	if ( empty( $GLOBALS['bc_wc_log'] ) ) {
		$GLOBALS['bc_wc_log'] = "";
		add_action('shutdown', __NAMESPACE__ . '\write_log' );
	}
	$GLOBALS['bc_wc_log'] .= date('m-d H:i:s') ." - ". $text ."\n";
	wbi_debug($text);
}
function write_log(){
	file_put_contents( __DIR__ . '/log.txt', $GLOBALS['bc_wc_log'] );
}
function show_log() {
	$log = @file_get_contents( __DIR__ . '/log.txt' );
	echo $log ? $log : 'no log yet';
}

/* Debug */
function wbi_debug( $var, $note='', $file='debug.log', $time='m-d H:i:s' ){
	if ( $note ) $note = "***{$note}***\n";
	ob_start();
	var_dump($var);
	$var = ob_get_clean();
	error_log("\n[". date($time) ."] ". $note . $var, 3, __DIR__ ."/". $file );
}