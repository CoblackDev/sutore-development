<?php
/*

Plugin Name: Sutore Kargo Eklentisi

Plugin URI: https://tolgahandemir.com

Description: Sutore.com için hızlı kargo eklentisi

Version: 1.0.0

Author: Tolgahan Demir

Author URI: https://tolgahandemir.com

License: GPLv2 or later

Text Domain: sutore

*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}


function is_customer_has_completed_order(){
  $customer = wp_get_current_user();
// Get all customer orders
    $customer_orders = get_posts(array(
        'numberposts' => 1,
        'meta_key' => '_customer_user',
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_value' => get_current_user_id(),
        'post_type' => "shop_order",
        'post_status' => array('wc-completed'),
    ));

    if(!empty($customer_orders)){
      return true;
    }else{
      return false;
    }
}

add_action("woocommerce_check_cart_items","check_cart_invoice");

function check_cart_invoice(){
    global $woocommerce;
    global $sitepress;
    $current_lang = apply_filters( 'wpml_current_language', NULL );
    $items = $woocommerce->cart->get_cart();
    //print_r($items);

    if(isset($_COOKIE["sutore_location"]) && $_COOKIE["sutore_location"] == "int") {
            foreach ($items as $item => $values) {
                if (!$values['data']->is_type('simple')) {
                    if (!get_post_meta($values['data']->get_id(), "has_invoice", true)) {
                        wc_add_notice(__('The product in your cart has been removed because it is not suitable for international shipping.', 'sutore'), 'error');
                        //$woocommerce->cart->empty_cart();
                        WC()->cart->remove_cart_item($item);
                    }
                }
                //$cats = get_the_terms($values['data']->get_id(), 'product_cat');
            }

    }
}

add_filter( 'woocommerce_get_order_item_totals', 'customize_email_order_line_totals', 1000, 3 );
function customize_email_order_line_totals( $total_rows, $order, $tax_display ){
    // Only on emails notifications
    if( ! is_wc_endpoint_url() || !isset( $_GET["key"])) {
        // Remove shipping line from totals rows
       $total_rows['shipping'] = array("label" => __("Shipping:","sutore"), "value" => get_post_meta($order->get_id(),"sutore_shipment_deadline",true)."");
    }
    return $total_rows;
}

////add_filter( 'woocommerce_email_order_meta_fields', 'custom_woocommerce_email_order_meta_fields', 10, 3 );
//
//function custom_woocommerce_email_order_meta_fields( $fields, $sent_to_admin, $order ) {
//    $fields['hear_about_us'] = array(
//        'label' => __( 'Hear About Us' ),
//        'value' => "ssdadf",
//    );
//    return $fields;
//}

add_filter( 'woocommerce_get_order_item_totals', function( $total_rows, $order, $tax_display ){

    if(get_post_meta($order->get_id(),"sutore_shipment_type",true) != "free") {
        unset($total_rows['shipping']);
    }

    return $total_rows;
}, 11, 3 );

function sutore_cargo_localize_script() {
    $user_id = get_current_user_id();
    wp_localize_script( 'sutore-cargo-js', 'sutoreCargo', array("fast_local" => get_option("fast_local") ? get_option("fast_local") : false, "user_state" => get_user_meta($user_id,"billing_state",true), "user_city" => get_user_meta($user_id,"billing_city",true), "user_country" => get_user_meta($user_id,"billing_country",true), 'ajaxurl' => admin_url( "admin-ajax.php" ), 'nonce' => wp_create_nonce("sutore-register-request" ),  'tckn_opt' => __('TCKN (İsteğe Bağlı)', 'woocommerce'), 'state' => __('State', 'woocommerce'), 'city' => __('City', 'woocommerce')));
}
add_action( 'wp_print_scripts', 'sutore_cargo_localize_script', 100 );


function sutore_cargo_style_scripts() {
    if(is_checkout()) {
        wp_enqueue_script('sutore-cargo-js', plugin_dir_url(__FILE__) . '/js/cargo.js?v=33.22345622225555647asd7777777asd', array('jquery'));
    }
}
add_action( 'wp_enqueue_scripts', 'sutore_cargo_style_scripts' );

add_action( 'woocommerce_checkout_update_order_meta', 'save_sutore_shipment_type');
function save_sutore_shipment_type( $order_id )
{
    if (!empty($_POST['shipment'])) {
        $shipment_type = sanitize_text_field($_POST['shipment']);
        update_post_meta($order_id, 'sutore_shipment_type', $shipment_type);

        if($shipment_type == "fast"){
            $day = 6;
        }elseif($shipment_type == "express"){
            $day = 1;
        }elseif($shipment_type == "free"){
            $day = 8;
        }elseif($shipment_type == "international"){
            $day = 12;
        }elseif($shipment_type == "cyprus"){
            $day = 10;
        }elseif($shipment_type == "free_imported"){
            $day = 17;
        }
        update_post_meta($order_id,"sutore_shipment_deadline", date_tr("d F l",$day));
        update_post_meta($order_id,"sutore_shipment_deadline_timestamp", current_time("timestamp", 8) + ($day * 86400));


    }


}

add_action( 'woocommerce_after_order_notes', 'sutore_fast_cargo_visual' );

function sutore_fast_cargo_visual(){
    //update_option("hizli_kargo_bedeli",150);
    //update_option("express_kargo_bedeli",300);
    global $woocommerce;
    $express = 0;
    $imported = 0;

    $items = $woocommerce->cart->get_cart();


    foreach($items as $item => $values) {
        $cats = get_the_terms($values['data']->get_id(), 'product_cat');
        foreach ($cats as $cat) {
            $catArray[$values['data']->get_id()][] = $cat->term_id;
        }
    }

    echo "<style>.sutore-shipment, .sutore-shipment-cyprus{width: 48%;} .sutore-shipment span.amount, .sutore-shipment-cyprus span.amount{font-weight: normal; color: #222} .sutore-shipment label, .sutore-shipment-cyprus label{text-overflow: ellipsis;  overflow: hidden; white-space: nowrap;margin-bottom: 1em; border-bottom: 1px solid black; height: 50px; line-height: 3rem; font-weight: 400; color: #777777 !important; font-size:16px !important; padding:0 0.675em !important} .sutore-shipment input{display:none;} .sutore-shipment label.selected, .sutore-shipment-cyprus label.selected{font-weight:normal; color:#222 !important;} .sutore-shipment label.selected span.amount, .sutore-shipment-cyprus label.selected span.amount{font-weight: normal; color: #222 !important}@media screen and (max-width: 849px){ .sutore-shipment, .sutore-shipment-cyprus{width: 100%;} .sutore-shipment label, .sutore-shipment-cyprus label{font-size:13.6px !important;}}</style>";

    echo "<div class='sutore-shipment'>";
    echo "<h3>".__("Shipping Method","sutore")."</h3>";


    foreach ($items as $item => $values) {
        if (get_post_meta($values['data']->get_id(), "imported", true) == 1 ) {
            $imported++;
        }
    }



   if($imported != 0){

    echo '<label class="selected" id="shipment_free"><input type="radio" name="shipment" value="free_imported" data-label="' . __("Free Shipping", "woocommerce") . '" data-cost="0" style="height: auto !important;" checked>' . __("Free", "sutore") . ' - ' . date_tr("d F l", 17) . '</label>';
    echo '</div>';
  }else{


    //if(isset($_COOKIE["sutore_location"]) && $_COOKIE["sutore_location"] != "int") {
    if(!isset($_COOKIE["sutore_location"]) || (isset($_COOKIE["sutore_location"]) && $_COOKIE["sutore_location"] != "int")) {

        echo '<label class="selected" id="shipment_free"><input type="radio" name="shipment" value="free" data-label="' . __("Free Shipping", "woocommerce") . '" data-cost="0" style="height: auto !important;" checked>' . __("Free", "sutore") . ' - ' . date_tr("d F l", 8) . '</label>';

        foreach ($items as $item => $values) {

            if ( get_post_meta( $values['data']->get_id(), 'fast_shipment', true ) == 1 ) {
                $express++;
            } else {
                $express = false;
                break;
            }

        }



        //print_r($catArray);

        if(is_user_logged_in() && is_customer_has_completed_order() && !empty(get_option("fast_campaing_active"))) {
            echo '<label id="shipment_fast_free"><input type="radio" name="shipment" value="fast" data-label="' . __("Fast Shipping", "sutore") . '" data-cost="395" style="height: auto !important;">' . __("Fast (₺395, exclusive shipping offer)", "sutore") . ' - ' . date_tr("d F l", 6) . '</label>';
        }else if (count($items) < 4 ) {
            echo '<label id="shipment_fast"><input type="radio" name="shipment" value="fast" data-label="' . __("Fast Shipping", "sutore") . '" data-cost="' . get_option("hizli_kargo_bedeli", true) . '" style="height: auto !important">' . __("Fast", "sutore") . ' (' . wc_price(get_option("hizli_kargo_bedeli", true)) . ') - ' . date_tr("d F l", 6) . '</label>';
        } else if (count($items) >= 4 ){
            echo '<label id="shipment_fast"><input type="radio" name="shipment" value="fast" data-label="' . __("Fast Shipping", "sutore") . '" data-cost="0" style="height: auto !important;">' . __("Fast (free for 4 or more products)", "sutore") . ' - ' . date_tr("d F l", 6) . '</label>';
        }


        if ($express != false) {
            $express_cost = get_option("express_kargo_bedeli", true) + ($express * 200);

            echo '<label  id="shipment_express" style="border-bottom: 1px solid black; padding: 0px 0px"><input style="display:none" type="radio" name="shipment" value="express" data-label="' . __("Express Shipping", "sutore") . '" data-cost="' . $express_cost . '" style="height: auto !important;">' . __("Express", "sutore") . ' (' . wc_price($express_cost) . ') - ' . date_tr("d F l", 1) . '</label>';
        }

    }else{

        foreach ($items as $item => $values) {
            if (get_post_meta($values['data']->get_id(), "has_invoice", true) == 1 || in_array(1561, $catArray[$values['data']->get_id()])) {
                $international = true;
            } else {
                $international = false;
                break;
            }

        }

        if ($international != false) {
            $international_cost = 1500; //get_option("international_kargo_bedeli",true);

            echo '<label id="shipment_international" style="border-bottom: 1px solid black; padding: 0px 0px"><input style="display:none" type="radio" name="shipment" value="international" data-label="' . __("International", "sutore") . '" data-cost="' . $international_cost . '" style="height: auto !important;">'.__("International","sutore").' (' . wc_price($international_cost) . ') - ' . date_tr("d F l", 12) . '</label>';
        }
    }
    echo "</div>";

    //$fees = WC()->cart->get_fees();

}

$cyprus_cost = 600;
echo "<div class='sutore-shipment-cyprus' style='display:none;'>";
echo "<h3>".__("Shipping Method","sutore")."</h3>";
echo '<label  style="border-bottom: 1px solid black; padding: 0px 0px"><input style="display:none" type="radio" name="shipment" value="cyprus" data-label="' . __("Cyprus Shipment", "sutore") . '" data-cost="' . $cyprus_cost . '" style="height: auto !important;">'.__("Cyprus","sutore").' (' . wc_price($cyprus_cost) . ') - ' . date_tr("d F l", 17) . '</label>';
echo "</div>";

    //print_r($fees);


}

add_action( 'wp_ajax_get_total', 'get_total');
add_action( 'wp_ajax_nopriv_get_total', 'get_total');

function get_total()
{
    $total = intval( $_POST['total'] );
    $cost = intval( $_POST['cost'] );

    $cart = WC()->cart;
    if ($cart) {
        foreach ($cart->get_applied_coupons() as $coupon_code) {
            $coupon = new WC_Coupon($coupon_code);
            if ($coupon->get_discount_type() === 'percent') {
                // Yüzdelik kupon ise indirimi sepet toplamı üzerinden hesapla
                $cart_total = $cart->get_subtotal();
                $discount = ($cart_total * $coupon->get_amount()) / 100;
            } else {
                // Diğer durumlarda WooCommerce fonksiyonunu kullan
                $discount = $cart->get_coupon_discount_amount($coupon_code);
            }
        }
    }

    if(WC()->cart->has_discount()) {
        $total_cost = '<span class="before-discount">' . wc_price(WC()->cart->get_total("edit") + $discount) . "</span> " . wc_price(WC()->cart->get_total("edit"));
    } else {
        $total_cost = wc_price(WC()->cart->total);
    }


    if($cost == 0) {
        wp_send_json(array("status" => true, "total" => $total_cost,  "cost" => "Ücretsiz", "total_cost" => $total_cost));
        //echo wc_price($total);
    }else{
        wp_send_json(array("status" => true, "total" => $total_cost, "cost" => wc_price($cost), "total_cost" => $total_cost));
        //echo wc_price($total + $cost);
    }
    wp_die();
}


add_action( 'woocommerce_cart_calculate_fees', 'cargo_type' );

function cargo_type()
{
    global $woocommerce;
    if ( ! $_POST || ( is_admin() && ! is_ajax() ) ) {
        return;
    }

    if ( isset( $_POST['post_data'] ) ) {
        parse_str( $_POST['post_data'], $post_data );
    } else {
        $post_data = $_POST; // fallback for final checkout (non-ajax)
    }


    if (isset($post_data['shipment'])) {
        switch ($post_data['shipment']) {
            case "fast" :
                $items = WC()->cart->get_cart();
                if(is_user_logged_in() && is_customer_has_completed_order() && !empty(get_option("fast_campaing_active"))){
                    WC()->cart->add_fee(__("Fast Shipping","sutore"), 395); // will not be removed.
                }else if(count($items) < 4) {
                    WC()->cart->add_fee(__("Fast Shipping","sutore"), get_option("hizli_kargo_bedeli", true)); // will not be removed.
                }else if(count($items) >= 4){
                    WC()->cart->add_fee(__("Fast Shipping","sutore"), 0); // will not be removed.
                }
                break;
            case "express" :
                $items = $woocommerce->cart->get_cart();

                foreach ( $items as $item => $values ) {
                    if ( get_post_meta( $values['data']->get_id(), 'fast_shipment', true ) == 1 || get_post_meta( $values['data']->get_id(), 'merchant_product', true ) == 1 ) {
                        $express++;
                    } else {
                        $express = false;
                        break;
                    }
                }
                if ($express != false) {
                    $express_cost = get_option("express_kargo_bedeli", true) + ($express * 200);
                }
                WC()->cart->add_fee(__("Express Shipping","sutore"), $express_cost); // will not be removed.
                break;
            case "international" :
                WC()->cart->add_fee(__("International Shipping","sutore"), 1500); // will not be removed.
                break;
            case "cyprus" :
                    WC()->cart->add_fee(__("Cyprus Shipping","sutore"), 600); // will not be removed.
                break;
            case "free" :
                        $fees = WC()->cart->get_fees();
                           foreach ($fees as $key => $fee) {
                               unset($fees[$key]);
                           }
                WC()->cart->fees_api()->set_fees($fees);
                break;
        }
    }


// //if ( current_user_can( 'manage_options' ) ) {
//     $items = WC()->cart->get_cart();
//     $item_count = count( $items);
//
//   if($item_count >= 2){
//           foreach($items as $item => $values) {
//               $product =  wc_get_product( $values['data']->get_id());
//               $prices[] = $product->get_price();
//           }
//
//           $min_price = min($prices);
//           $discount = ($min_price * 10) / 100;
//
//          WC()->cart->add_fee( 'Discount', -$discount );
//     }
//   //}

}

//add_action( 'woocommerce_before_checkout_form', 'print_donation_notice', 10 );
function print_donation_notice() {
//  if ( current_user_can( 'manage_options' ) ) {
  $items = WC()->cart->get_cart();
  $item_count = count( $items);

if($item_count >= 2){
  foreach($items as $item => $values) {
      $product =  wc_get_product( $values['data']->get_id());
      $prices[] = $product->get_price();
  }

  $min_price = min($prices);
  $discount = ($min_price * 10) / 100;
  wc_print_notice( sprintf(__("A discount of %s has been applied to your cart for the Valentine's Day week.", "woocommerce"),'<strong>' . wc_price( $discount ) . '</strong>' ), 'success' );
  }
//}
}

       // WC()->cart->calculate_fees();
        //WC()->cart->calculate_totals();
//        global $woocommerce;
//        $type = sanitize_text_field($_POST['cargo_type']);
//        $fees = WC()->cart->get_fees();
////    foreach ($fees as $key => $fee) {
////            unset($fees[$key]);
////    }
//
//        WC()->cart->fees_api()->set_fees($fees);
//
//        switch ($type) {
//            case "fast" :
//                WC()->cart->add_fee(__("Hızlı Kargo"), get_option("hizli_kargo_bedeli", true)); // will not be removed.
//                echo "hizli";
//                break;
//            case "express" :
//                $items = $woocommerce->cart->get_cart();
//                foreach ($items as $item => $values) {
//                    if (get_post_meta($values['data']->get_id(), "merchant_product", true) == 1) {
//                        $express++;
//                    } else {
//                        $express = false;
//                        break;
//                    }
//                }
//                if ($express != false) {
//                    $express_cost = get_option("express_kargo_bedeli", true) + ($express * 200);
//                }
//                WC()->cart->add_fee(__("Express Kargo"), $express_cost); // will not be removed.
//                echo "express++";
//                break;
//        }
//
//        $fees = WC()->cart->get_fees();
//        //echo WC()->cart->get_total();
//
//        WC()->cart->fees_api()->set_fees($fees);
//        WC()->cart->calculate_fees();
//        WC()->cart->calculate_totals();
//        //$fees = WC()->cart->get_fees();
//        print_r($fees);
//
//        echo WC()->cart->get_total();




// -------------------------------------------------------------------------
// A6 — Buy Now kargo oranları (checkout sutore_fast_cargo_visual ile hizalı)
// -------------------------------------------------------------------------

/**
 * PDP ve checkout için standart ücretsiz kargo ETA gün sayısı (tek kaynak).
 *
 * @param int $product_id Ürün veya varyasyon ID'si.
 * @return int Gün sayısı (0 = ETA yok).
 */
function sutore_cargo_get_standard_eta_days( $product_id ) {
	$product_id = (int) $product_id;
	if ( $product_id <= 0 ) {
		return 0;
	}

	$product = wc_get_product( $product_id );
	if ( ! $product instanceof WC_Product ) {
		return 0;
	}

	$parent_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : $product_id;
	$lookup_id = $product->is_type( 'variation' ) ? $product_id : $parent_id;

	if ( function_exists( 'sutore_pp_is_imported' ) && sutore_pp_is_imported( $product_id ) ) {
		return 17;
	}

	$imported = (int) get_post_meta( $lookup_id, 'imported', true ) === 1;
	if ( ! $imported && $product->is_type( 'variation' ) && $parent_id ) {
		$imported = (int) get_post_meta( $parent_id, 'imported', true ) === 1;
	}
	if ( $imported ) {
		return 17;
	}

	$is_int = isset( $_COOKIE['sutore_location'] ) && 'int' === (string) $_COOKIE['sutore_location'];
	if ( $is_int ) {
		$cats    = get_the_terms( $lookup_id, 'product_cat' );
		$cat_ids = ( $cats && ! is_wp_error( $cats ) ) ? array_map( 'intval', wp_list_pluck( $cats, 'term_id' ) ) : array();
		$intl_ok = (int) get_post_meta( $lookup_id, 'has_invoice', true ) === 1 || in_array( 1561, $cat_ids, true );

		return $intl_ok ? 12 : 0;
	}

	return 8;
}

/**
 * @param WC_Order   $order   Buy Now siparişi.
 * @param WC_Product $product Siparişteki ürün (tek kalem).
 * @return array<array{id:string,label:string,cost:float,eta_days:int,method_title:string}>
 */
function sutore_cargo_resolve_buy_now_rates( $order, $product ) {
	$rates      = array();
	$product_id = (int) $product->get_id();
	$parent_id  = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : $product_id;
	$lookup_id  = $product->is_type( 'variation' ) ? $product_id : $parent_id;

	$state       = $order instanceof WC_Order ? $order->get_billing_state() : '';
	$is_tr34     = (string) $state === 'TR34';
	$fast_local  = (bool) get_option( 'fast_local', false );
	$fast_visible = $fast_local || $is_tr34;

	$imported = (int) get_post_meta( $lookup_id, 'imported', true ) === 1;
	if ( ! $imported && $product->is_type( 'variation' ) && $parent_id ) {
		$imported = (int) get_post_meta( $parent_id, 'imported', true ) === 1;
	}

	if ( $imported ) {
		$standard_eta = (int) sutore_cargo_get_standard_eta_days( $product_id );
		$rates[]      = array(
			'id'           => 'free_imported',
			'label'        => __( 'Free', 'sutore' ),
			'cost'         => 0.0,
			'eta_days'     => $standard_eta > 0 ? $standard_eta : 17,
			'method_title' => __( 'Free Shipping', 'sutore' ),
		);
		return apply_filters( 'sutore_buy_now_shipping_rates', $rates, $order, $product );
	}

	$is_int = isset( $_COOKIE['sutore_location'] ) && 'int' === (string) $_COOKIE['sutore_location'];

	if ( $is_int ) {
		$cats    = get_the_terms( $lookup_id, 'product_cat' );
		$cat_ids = ( $cats && ! is_wp_error( $cats ) ) ? array_map( 'intval', wp_list_pluck( $cats, 'term_id' ) ) : array();
		$intl_ok = (int) get_post_meta( $lookup_id, 'has_invoice', true ) === 1 || in_array( 1561, $cat_ids, true );

		if ( $intl_ok ) {
			$standard_eta = (int) sutore_cargo_get_standard_eta_days( $product_id );
			$rates[]      = array(
				'id'           => 'international',
				'label'        => __( 'International', 'sutore' ) . ' (' . wc_price( 1500.0 ) . ')',
				'cost'         => 1500.0,
				'eta_days'     => $standard_eta > 0 ? $standard_eta : 12,
				'method_title' => __( 'International Shipping', 'sutore' ),
			);
		}
		return apply_filters( 'sutore_buy_now_shipping_rates', $rates, $order, $product );
	}

	$free_eta = (int) sutore_cargo_get_standard_eta_days( $product_id );
	$rates[]  = array(
		'id'           => 'free',
		'label'        => __( 'Free', 'sutore' ),
		'cost'         => 0.0,
		'eta_days'     => $free_eta > 0 ? $free_eta : 8,
		'method_title' => __( 'Free Shipping', 'sutore' ),
	);

	// fast_local / TR34 gate — Buy Now UI'nın beklediği görünürlükle hizalı.
	if ( $fast_visible ) {
		// Buy Now tek ürün → count < 4 her zaman; checkout fast görünürlüğü ile hizalı.
		if ( is_user_logged_in() && is_customer_has_completed_order() && ! empty( get_option( 'fast_campaing_active' ) ) ) {
			$rates[] = array(
				'id'           => 'fast',
				'label'        => __( 'Fast (₺395, exclusive shipping offer)', 'sutore' ),
				'cost'         => 395.0,
				'eta_days'     => 6,
				'method_title' => __( 'Fast Shipping', 'sutore' ),
			);
		} else {
			$fast_cost = (float) get_option( 'hizli_kargo_bedeli', 0 );
			$rates[]   = array(
				'id'           => 'fast',
				'label'        => __( 'Fast', 'sutore' ) . ' (' . wc_price( $fast_cost ) . ')',
				'cost'         => $fast_cost,
				'eta_days'     => 6,
				'method_title' => __( 'Fast Shipping', 'sutore' ),
			);
		}
	}

	$express_eligible = (int) get_post_meta( $lookup_id, 'fast_shipment', true ) === 1;

	// Express yalnız TR34 senaryosunda.
	if ( $is_tr34 && $express_eligible ) {
		$express_base = (float) get_option( 'express_kargo_bedeli', 0 );
		$express_cost = $express_base + 200.0;
		$rates[]      = array(
			'id'           => 'express',
			'label'        => __( 'Express', 'sutore' ) . ' (' . wc_price( $express_cost ) . ')',
			'cost'         => $express_cost,
			'eta_days'     => 1,
			'method_title' => __( 'Express Shipping', 'sutore' ),
		);
	}

	return apply_filters( 'sutore_buy_now_shipping_rates', $rates, $order, $product );
}

function date_tr($format, $day){
    $tz = new DateTimeZone( 'Asia/Tokyo' );
    $dt = new DateTime( 'now', $tz );
    $day = (int) $day;
    if ( $day !== 0 ) {
        $dt->modify( ( $day > 0 ? '+' : '' ) . $day . ' days' );
    }

    $ingilizce = array(
        'January',
        'February',
        'March',
        'April',
        'May',
        'June',
        'July',
        'August',
        'September',
        'October',
        'November',
        'December',
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday'
    );

    $turkce = array(
        'Ocak',
        'Şubat',
        'Mart',
        'Nisan',
        'Mayıs',
        'Haziran',
        'Temmuz',
        'Ağustos',
        'Eylül',
        'Ekim',
        'Kasım',
        'Aralık',
        'Pazartesi',
        'Salı',
        'Çarşamba',
        'Perşembe',
        'Cuma',
        'Cumartesi',
        'Pazar'

    );
    $current_lang = apply_filters( 'wpml_current_language', NULL );
    if($current_lang != "en") {
        $date = str_replace($ingilizce,$turkce,$dt->format($format));
    }else{
        $format = "l, F d";
        $date = $dt->format($format);
    }
    return sprintf( __( '%s (Estimated Delivery Date)', 'sutore' ), $date );
}
 ?>
