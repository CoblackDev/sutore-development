<?php
/*
Plugin Name: Sutore Kayıt & Kullanıcı Paneli
Plugin URI: https://tolgahandemir.com
Description: Sutore.com için özel geliştirilmiş kayıt ve kullanıcı paneli geliştirmeleri.
Version: 1.0.0
Author: Tolgahan Demir
Author URI: https://tolgahandemir.com
License: GPLv2 or later
Text Domain: woocommerce
*/

// Plugin sabiti (cache busting vs. için fallback)
if ( ! defined( 'SUTORE_PLUGIN_VERSION' ) ) {
    define( 'SUTORE_PLUGIN_VERSION', '1.0.0' );
}

add_filter( 'woocommerce_admin_order_preview_get_order_details', function($data, $order){
  $data['tckno'] = get_post_meta($order->get_id(), '_billing_tckno', true);
  return $data;
}, 10, 2);

add_action( 'woocommerce_admin_order_preview_end', function(){
  echo '<div><strong>TCKNO:</strong> {{data.tckno}}</div>';
});


add_action( 'woocommerce_admin_order_data_after_billing_address', 'custom_display_billing_tckno_field', 10, 1 );
function custom_display_billing_tckno_field( $order ){
    $custom_field = get_post_meta( $order->get_id(), '_billing_tckno', true );

    echo '<p><strong>'.__('TCKNO').':</strong> ' . esc_html($custom_field) . '</p>';
}

add_action( 'woocommerce_checkout_update_order_meta', 'custom_update_order_meta' );

function custom_update_order_meta( $order_id ) {
    $custom_data = isset($_POST['billing_tckno']) ? wp_unslash($_POST['billing_tckno']) : '';
    if ( $custom_data !== '' ) {
        update_post_meta( $order_id, '_billing_tckno', sanitize_text_field( $custom_data ) );
    }
}

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

include("sutore.php");
include("functions.php");
include("inc/functions/update-merchant-data.php");
include("inc/functions/update-user-data.php");
include("inc/functions/update-billing-adress.php");
include("inc/functions/update-password.php");
include("inc/functions/send-sms.php");
include("inc/functions/delete-user-account.php");
include("inc/functions/get-product-variations.php");
include("inc/functions/get-product-details.php");
include("inc/functions/get-campaing-details.php");
include("inc/functions/get-simple-product-details.php");
include("inc/functions/search-product.php");
include("inc/functions/add-product-to-list.php");
include("inc/functions/delete-vendor-product.php");
include("inc/functions/get-price-change-form.php");
include("inc/functions/update-product-price.php");
include("inc/functions/update-merchant-product.php");
include("inc/functions/get-details.php");
include("inc/functions/get-user-details.php");
include("inc/functions/update-shipment-code.php");
include("inc/statuses/shipped-customer.php");
include("inc/statuses/shipped-sutore.php");
include("inc/statuses/arrived-to-sutore.php");
include("inc/statuses/awaiting-payment.php");
include("inc/statuses/verified.php");
include("inc/statuses/confirmed.php");
include("inc/statuses/paid.php");
include("inc/statuses/shipped.php");
include("inc/statuses/ready-to-shipping.php");
include("inc/statuses/expired.php");
include("inc/statuses/chargeback.php");

include("inc/functions/get-sale-confirm-form.php");


add_action( 'edit_user_profile', 'wk_custom_user_profile_fields' );

function wk_custom_user_profile_fields( $user )
{
  echo '<h3 class="heading">Satıcı Seviyesi</h3>';

  ?>

  <table class="form-table">
    <tr>
      <th><label for="contact">Seviye</label></th>

      <td><input type="number" class="input-text form-control" name="merchant_level" id="merchant_level" value="<?php echo esc_attr(get_user_meta($user->ID,"merchant_level",true)); ?>"/>
      </td>

    </tr>
    <tr>
      <th><label for="contact">Hesap Telefon Numarası</label></th>

      <td><input type="number" class="input-text form-control" name="billing_phone_account" id="billing_phone_account" value="<?php echo esc_attr(get_user_meta($user->ID,"billing_phone_account",true)); ?>"/>
      </td>

    </tr>
  </table>

  <?php
}

add_action( 'edit_user_profile_update', 'wk_save_custom_user_profile_fields' );

/**
*   @param User Id $user_id
*/
function wk_save_custom_user_profile_fields( $user_id )
{

  $merchant_level = absint($_POST['merchant_level']);
  update_user_meta( $user_id, 'merchant_level', $merchant_level );

  $billing_phone_account = sanitize_text_field($_POST['billing_phone_account']);
  update_user_meta( $user_id, 'billing_phone_account', $billing_phone_account );

}

register_activation_hook(__FILE__, function () {
  if ( ! get_role('merchant') ) {
    add_role('merchant', 'Satıcı', get_role('subscriber')->capabilities);
  }
});

function wws_allow_shop_manager_role_edit_capabilities( $roles ) {
  $roles[] = 'merchant';
  $roles[] = 'custom_vendor';
  return $roles;
}
add_filter( 'woocommerce_shop_manager_editable_roles', 'wws_allow_shop_manager_role_edit_capabilities' );

/* Lets Shop Managers be able to edit users again */
function wws_add_shop_manager_user_editing_capability() {
  $shop_manager = get_role( 'shop_manager' );
  $shop_manager->add_cap( 'edit_users' );
  $shop_manager->add_cap( 'edit_user' );
}
add_action( 'admin_init', 'wws_add_shop_manager_user_editing_capability');

function modify_billing_fields($fields){
  global $woocommerce;
  $user_id = get_current_user_id();
  $countries_obj   = new WC_Countries();
  $countries   = $countries_obj->get_allowed_countries();

  ////////// Countries Fix

  if(isset($_COOKIE["sutore_location"]) &&  $_COOKIE["sutore_location"] == "int"){
    //print_r($countries);
    unset($countries["TR"]);
  }else{
    foreach ($countries as $key => $value){
      if($key == "TR" || $key == "CY"){
        /// TR ya da CY ISE SİLME
      }else{
        unset($countries[$key]);
      }
    }
  }

  // echo get_user_meta($user_id,"billing_state",true);

  if(get_user_meta($user_id,"billing_state",true)){
    $ilceler = sehirIlceleri(get_user_meta($user_id,"billing_state",true));
  }else{
    $ilceler = array("" => __("City","woocommerce"));
  }

  if(get_user_meta($user_id,"billing_country",true)){
    $default_county_states = $countries_obj->get_states( "TR" );
  }else{
    $default_county_states = $countries_obj->get_states( "TR" );
  }

  //unset($fields["billing_email"]);
  //unset($fields["billing_phone"]);
  //    unset($fields["billing_country"]);
  //    unset($fields["billing_state"]);
  //    unset($fields["billing_city"]);
  //    unset($fields['billing_company']);

  //    if( is_wc_endpoint_url( 'edit-address' )) {
  //        unset($fields['billing_company']);
  //        unset($fields["billing_country"]);
  //        unset($fields["billing_state"]);
  //        unset($fields["billing_city"]);
  //    }
  //
  //
  //
  //    $billing_fields['billing_city']['required'] = false;
  //    $billing_fields['billing_country']['required'] = false;
  //    $billing_fields['billing_state']['required'] = false;
  //    $fields['billing_company']["label"] = __('Company name', 'woocommerce');
  ////    $fields['billing_company']["class"] = array("form-row-wide");
  //    $fields['reg_country'] = array(
  //        'label'     => __('Country', 'woocommerce'),
  //        'placeholder'   => _x('Country', 'placeholder', 'woocommerce'),
  //        'required'  => true,
  //        'priority' => 3,
  //        'class'     => array('form-row-wide'),
  //        'type'      => 'select',
  //        'options'     => $countries,
  //        'default' => "TR",
  //        'value' => "TR",
  //        'custom_attributes' => array("required" => "required"),
  //
  //    );
  //
  //    $fields['reg_country']["value"] = "TR";
  //    $fields['reg_city'] = array(
  //        'label'     => __('City', 'woocommerce'),
  //        'placeholder'   => _x('City', 'placeholder', 'woocommerce'),
  //        'required'  => true,
  //        'priority' => 5,
  //        'class'     => array('form-row-last'),
  //        'type'      => 'select',
  //        'options'     => $ilceler,
  //        'custom_attributes' => array("required" => "required"),
  //    );
  //    $fields['reg_state'] = array(
  //        'label'     => __('State', 'woocommerce'),
  //        'placeholder'   => _x('State', 'placeholder', 'woocommerce'),
  //        'required'  => true,
  //        'priority' => 4,
  //        'class'     => array('form-row-first'),
  //        'clear'     => true,
  //        'type'      => 'select',
  //        'options'     => $default_county_states,
  //        'custom_attributes' => array("required" => "required"),
  //    );

  //print_r($fields);



  $fields["billing"]["billing_first_name"]["priority"] = 1;
  $fields["billing"]["billing_last_name"]["priority"] = 2;
  $fields["billing"]["billing_address_1"]["priority"] = 5;
  $fields["billing"]["billing_address_2"]["priority"] = 6;
  $fields["billing"]["billing_postcode"]["priority"] = 7;
  $fields["billing"]["billing_postcode"]["class"] = array('form-row-first');
  $fields["billing"]["billing_phone"]["priority"] = 9;
  $fields["billing"]["billing_phone"]["class"] = array('form-row-first');


  unset($fields["billing"]["billing_company"]);
  unset($fields["billing"]["billing_city"]);
  unset($fields["billing"]["billing_state"]);
  unset($fields["billing"]["billing_country"]);
  //$fields["billing"]['billing_country']["value"] = "TR";
  $fields["billing"]['billing_country'] = array(
    'label'     => __('Country', 'woocommerce'),
    'placeholder'   => _x('Country', 'placeholder', 'woocommerce'),
    'required'  => true,
    'priority' => 8,
    'class'     => array('form-row-last'),
    'type'      => 'select',
    'options'     => $countries,
    'value' => "TR",
    'custom_attributes' => array("required" => "required"),

  );

  //print_r($default_county_states);

  $fields["billing"]['billing_states'] = array(
    'label'     => __('State', 'woocommerce'),
    'placeholder'   => _x('State', 'placeholder', 'woocommerce'),
    //'required'  => false,
    'priority' => 3,
    'class'     => array('form-row-first'),
    'type'      => 'select',
    'options'     => $default_county_states,
    'default'  => get_user_meta($user_id,"billing_state",true),
    'custom_attributes' => array("required" => "required"),
  );
  $fields["billing"]['billing_city'] = array(
    'label'     => __('City', 'woocommerce'),
    'placeholder'   => _x('City', 'placeholder', 'woocommerce'),
    'required'  => false,
    'priority' => 4,
    'class'     => array('form-row-last'),
    'type'      => 'select',
    'options'     => $ilceler,
    'default'  => get_user_meta($user_id,"billing_city",true),
    'custom_attributes' => array("required" => "required"),
  );


$imported = false;

  foreach (WC()->cart->get_cart() as $item => $values) {
      if (get_post_meta($values['data']->get_id(), "imported", true) == 1 ) {
          $imported = true;
          break;
      }
  }

  if($imported == true || WC()->cart->total > 80000){
    $required = true;
    $label = __('TCKN (Required)', 'woocommerce');
  }else{
    $label = __('TCKN (Optional)', 'woocommerce');
    $required = false;
  }

  $fields["billing"]['billing_tckno'] = array(
    'label'     => $label,
    'placeholder'   => $label,
    'required'  => $required,
    'priority' => 10,
    'class'     => array('form-row-last'),
    'type'      => 'number',
    'custom_attributes' => array("pattern" => "[0-9]{11}"),

  );

  if(is_user_logged_in()) {
    unset($fields["billing"]["billing_email"]);
  }else {
    $fields["billing"]["billing_email"]["priority"] = 11;
    $fields["billing"]["billing_email"]["class"] = array('form-row-wide');
  }

  $fields["account"]["account_username"]["class"] = array('form-row-wide');
  $fields["account"]["account_password"]["class"] = array('form-row-first');
  $fields["account"]["account_password_repeat"] = array(
    'label'     => __('Password (Repeat)', 'woocommerce'),
    'placeholder'   => _x('Password (Repeat)', 'placeholder', 'woocommerce'),
    'required'  => true,
    'class'     => array('form-row-last'),
    'type'      => 'password',
  );




  return $fields;

}
add_filter('woocommerce_checkout_fields', 'modify_billing_fields', 1001);

/**
* Remove optional label
* https://elextensions.com/knowledge-base/remove-optional-text-woocommerce-checkout-fields/
*/
add_filter( 'woocommerce_form_field' , 'elex_remove_checkout_optional_text', 10, 4 );
function elex_remove_checkout_optional_text( $field, $key, $args, $value ) {
  if( is_wc_endpoint_url("edit-address") || is_checkout() && ! is_wc_endpoint_url("edit-address") ) {
    $optional = '&nbsp;<span class="optional">(' . esc_html__( 'optional', 'woocommerce' ) . ')</span>';
    $field = str_replace( $optional, '', $field );
  }
  return $field;
}

// Billing fields on my account edit-addresses and checkout
add_filter( 'woocommerce_billing_fields' , 'custom_billing_fields' );
function custom_billing_fields( $fields ) {

  if(!is_checkout()) {
    global $woocommerce;
    $user_id = get_current_user_id();

    //echo get_user_meta($user_id, "billing_state", true);

    $countries_obj = new WC_Countries();
    $countries = $countries_obj->get_allowed_countries();

    if (get_user_meta($user_id, "billing_state", true)) {
      $ilceler = sehirIlceleri(get_user_meta($user_id, "billing_state", true));
    } else {
      $ilceler = array("" => __("City","woocommerce"));
    }

    if (get_user_meta($user_id, "billing_country", true)) {
      //$default_county_states = $countries_obj->get_states(get_user_meta($user_id, "billing_country", true));
      $default_county_states = $countries_obj->get_states("TR");
    } else {
      $default_county_states = $countries_obj->get_states("TR");
    }

    unset($fields["billing_company"]);
    unset($fields["billing_city"]);
    unset($fields["billing_state"]);
    unset($fields["billing_country"]);
    unset($fields["billing_email"]);

    $fields["billing_first_name"]["priority"] = 1;
    $fields["billing_last_name"]["priority"] = 2;
    $fields["billing_address_1"]["priority"] = 5;
    $fields["billing_address_2"]["priority"] = 6;
    $fields["billing_postcode"]["priority"] = 7;
    $fields["billing_postcode"]["class"] = array('form-row-first');
    $fields["billing_phone"]["priority"] = 9;
    $fields["billing_phone"]["class"] = array('form-row-first');
    $fields["billing_phone"]["type"] = "number";

    $fields['billing_country'] = array(
      'label' => __('Country', 'woocommerce'),
      'placeholder' => _x('Country', 'placeholder', 'woocommerce'),
      'required' => true,
      'priority' => 8,
      'class' => array('form-row-last'),
      'type' => 'select',
      'options' => $countries,
      'custom_attributes' => array("required" => "required"),

    );
    $fields['billing_state'] = array(
      'label' => __('State', 'woocommerce'),
      'placeholder' => _x('State', 'placeholder', 'woocommerce'),
      'required' => false,
      'priority' => 3,
      'class' => array('form-row-first state'),
      'type' => 'select',
      'options' => $default_county_states,
      'default' => get_user_meta($user_id, "billing_state", true),
    );
    $fields['billing_city'] = array(
      'label' => __('City', 'woocommerce'),
      'placeholder' => _x('City', 'placeholder', 'woocommerce'),
      'required' => false,
      'priority' => 4,
      'class' => array('form-row-last'),
      'type' => 'select',
      'options' => $ilceler ? $ilceler : array("" => "Seçin"),
      'default' => get_user_meta($user_id, "billing_city", true),
    );

    $fields['billing_tckno'] = array(
      'label' => __('TCKN (İsteğe Bağlı)', 'woocommerce'),
      'placeholder' => _x('TCKN (İsteğe Bağlıı)', 'placeholder', 'woocommerce'),
      'required' => false,
      'priority' => 10,
      'class' => array('form-row-last'),
      'type' => 'number',
      'custom_attributes' => array("pattern" => "[0-9]{11}"),
      'clear' => true,
      'default' => false,

    );

    $fields['billing_fix'] = array(
      'label' => "",
      'type' => 'hidden',
    );


    //print_r($fields);

  }

  return $fields;

}


function change_woocommerce_field_markup( $field, $key, $args, $value ) {

  $user_id = get_current_user_id();
  //  Remove the .form-row class from the current field wrapper
  //echo $user_id;
  $country =  get_user_meta($user_id,"billing_country",true);
  //print_r($value);
  if($country != "TR") {
    if ($key == "billing_state") {
      echo $key;
      return $field;
      //$field = str_replace('form-row form-row-first validate-required', '', $field);
      // $field = '<p class="form-row form-row-first validate-required is-tr" id="'.$key.'" data-priority="' . $args['priority'] . '">' . $field . '</p>';
    }
  }
  //    $field = str_replace('form-row', '', $field);
  //
  //    //  Wrap the field (and its wrapper) in a new custom div, adding .form-row so the reshuffling works as expected, and adding the field priority
  //
  //    $field = '<div class="form-row single-field-wrapperss" data-priority="' . $args['priority'] . '">' . $field . '</div>';

  return $field;
}

//add_filter( 'woocommerce_form_field', 'change_woocommerce_field_markup', 10, 4 );

/**
* Process the checkout
*/
add_action('woocommerce_checkout_process', 'my_custom_checkout_field_process');


function my_custom_checkout_field_process() {
  // Check if set, if its not set add an error.

  if(isset($_POST['billing_country']) && $_POST['billing_country'] == "TR"){
    if(empty($_POST['billing_states'])) {
      wc_add_notice(__("<strong>Billing City</strong> is a required field.","sutore"), 'error');
    }
    if(empty($_POST['billing_city'])) {
      wc_add_notice(__("<strong>Billing Town</strong> is a required field.","sutore"), 'error');
    }
    if(strlen($_POST['billing_phone']) != 10 || substr($_POST['billing_phone'], 0, 1) != 5){
      wc_add_notice(__("Invalid billing phone number <strong>(must start with 5 and consist of 10 digits)</strong>","sutore"), 'error');
    }

    if(isset($_POST['billing_postcode']) && !isPostCodeValid($_POST['billing_postcode'])){
      wc_add_notice(__("Invalid billing postcode","sutore"), 'error');
    }
  }

  if ( isset($_POST['billing_tckno']) && !empty($_POST['billing_tckno'])) {
    if(isTcValid($_POST['billing_tckno']) == false) {
      wc_add_notice(__("Invalid billing TCKN","sutore"), 'error');
    }
  }

  if(!isset($_POST['sozlesmeler']) || $_POST['sozlesmeler'] != 1){
    wc_add_notice(__("Please read and accept the sales contracts to proceed with your order.","sutore"), 'error');
  }


  if(isset($_POST['account_password']) && isset($_POST['account_password_repeat']) && $_POST['account_password_repeat'] != $_POST['account_password']){
    wc_add_notice(__("The passwords you entered do not match.","sutore"), 'error');
  }
}


function reigel_woocommerce_checkout_update_user_meta( $customer_id, $posted ) {
  if (isset($posted['billing_states'])) {
    $dob = sanitize_text_field( $posted['billing_states'] );
    update_user_meta( $customer_id, 'billing_state', $dob);
  }

  if (isset($posted['billing_tckno'])) {
    $dob = sanitize_text_field( $posted['billing_tckno'] );
    update_user_meta( $customer_id, 'billing_tckno', $dob);
  }
}
add_action( 'woocommerce_checkout_update_user_meta', 'reigel_woocommerce_checkout_update_user_meta', 10, 2 );


add_action( 'woocommerce_checkout_update_order_meta', 'saving_checkout_cf_data');
function saving_checkout_cf_data( $order_id ) {

  $recipient_address = $_POST['billing_states'];
  if ( ! empty( $recipient_address ) ) {
    update_post_meta( $order_id, '_billing_state', sanitize_text_field( $recipient_address ) );
    update_post_meta( $order_id, '_shipping_state', sanitize_text_field( $recipient_address ) );
  }


}

add_action( 'parse_request', 'change_post_per_page_wpent' );

function change_post_per_page_wpent( $query ){
  $user = wp_get_current_user();
  if((strpos($query->request, "/on-sales") !== false || strpos($query->request, "/sold-out") !== false) && !in_array( 'merchant', (array) $user->roles )){
    wp_safe_redirect(home_url());
    exit();
  }
  if (strpos($query->request, "/on-sales/") !== false || strpos($query->request, "/sold-out/") !== false  || strpos($query->request, "/49efigenia-81gunnarr/") !== false || strpos($query->request, "/pre-order/") !== false) {
    $req = $query->request;
    $paged = explode("page/", $req);
    $query->query_vars['paged'] = $paged[1];
    return $query;
  }
}

/**
* Flush rewrite rules on plugin activation.
*/
function sutore_add_endpoints() {
  add_rewrite_endpoint( 'merchant-area', EP_ROOT | EP_PAGES );
  add_rewrite_endpoint( 'sold-out', EP_ROOT | EP_PAGES );
  add_rewrite_endpoint( 'on-sales', EP_ROOT | EP_PAGES );
  add_rewrite_endpoint( 'sell-product', EP_ROOT | EP_PAGES );
  add_rewrite_endpoint( '49efigenia-81gunnarr', EP_ROOT | EP_PAGES );
  add_rewrite_endpoint( 'njeri16-sanjica25', EP_ROOT | EP_PAGES );
  add_rewrite_endpoint( 'pre-order', EP_ROOT | EP_PAGES );
}
add_action('init', 'sutore_add_endpoints');

register_activation_hook(__FILE__, function () {
  sutore_add_endpoints();
  flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
  flush_rewrite_rules();
});


/**
* Insert the new endpoint into the My Account menu.
*
* @param array $items
* @return array
*/
function my_custom_my_account_menu_items( $items ) {
  $user = wp_get_current_user();
  if ( !in_array( 'administrator', (array) $user->roles ) ) {
    $items['merchant-area'] = __('Satıcı Özel', 'woocommerce');
  }

  if ( in_array( 'administrator', (array) $user->roles ) || in_array( 'shop_manager', (array) $user->roles ) ) {
    //$items['49efigenia-81gunnarr'] = __('Satıcı Ürünleri', 'woocommerce');
    //$items['vendors'] = __('Satıcılar', 'woocommerce');
  }

  if ( in_array( 'merchant', (array) $user->roles ) ) {
    $items['sell-product'] = __('Ürün Ekle', 'woocommerce');
    $items['on-sales'] = __('Satıştaki Ürünler', 'woocommerce');
    $items['sold-out'] = __('Satılan Ürünler', 'woocommerce');
  }

  if(current_user_can("administrator") || (is_user_logged_in() && in_array(get_user_meta(wp_get_current_user()->ID, "merchant_level", true), array(3,4,5,7)) )){
    $items['pre-order'] = __('Ön Sipariş', 'woocommerce');
  }
  return $items;
}

add_filter( 'woocommerce_account_menu_items', 'my_custom_my_account_menu_items' );



if ( ! function_exists('sutore_vendor_filter_ids_by_meta') ) {
  function sutore_vendor_filter_ids_by_meta( array $ids, $resutore_only = false ) {
    global $wpdb;

    if ( empty($ids) ) return array();

    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ( empty($ids) ) return array();

    // 1) merchant_product = 1 filtresi (JOIN YOK)
    $ph = implode(',', array_fill(0, count($ids), '%d'));
    $sql = "SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE post_id IN ($ph)
              AND meta_key = 'merchant_product'
              AND meta_value = '1'";

    $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $ids));
    $merchant_ids = $wpdb->get_col($prepared);

    if ( empty($merchant_ids) ) return array();

    $merchant_ids = array_values(array_unique(array_map('intval', $merchant_ids)));
    if ( ! $resutore_only ) {
      return $merchant_ids;
    }

    // 2) re_sutore_item = 1 filtresi (JOIN YOK)
    $ph2 = implode(',', array_fill(0, count($merchant_ids), '%d'));
    $sql2 = "SELECT DISTINCT post_id
             FROM {$wpdb->postmeta}
             WHERE post_id IN ($ph2)
               AND meta_key = 're_sutore_item'
               AND meta_value = '1'";

    $prepared2 = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql2), $merchant_ids));
    $rs_ids = $wpdb->get_col($prepared2);

    return ! empty($rs_ids) ? array_values(array_unique(array_map('intval', $rs_ids))) : array();
  }
}

if ( ! function_exists('sutore_find_variation_ids_for_vendor_search') ) {
  function sutore_find_variation_ids_for_vendor_search( $sp, $resutore_only = false ) {
    global $wpdb;

    $sp = trim((string) $sp);
    if ($sp === '') return array();

    $cache_key = 'svp_search_v4_' . md5($sp . '|' . ($resutore_only ? '1' : '0'));
    $cached = wp_cache_get($cache_key, 'sutore_vendor_search');
    if ( false !== $cached ) {
      return is_array($cached) ? $cached : array();
    }

    $limit = 500;

    // --- 1) EXACT (en hızlı) ---
    $search_keys  = array('shipment_code', 'urun_kodu', 'product_sold_order_id');
    $kph          = implode(',', array_fill(0, count($search_keys), '%s'));

    $sql_exact = "SELECT DISTINCT post_id
                  FROM {$wpdb->postmeta}
                  WHERE meta_key IN ($kph)
                    AND meta_value = %s
                  LIMIT %d";

    $args_exact = array_merge($search_keys, array($sp, $limit));
    $prepared_exact = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql_exact), $args_exact));
    $ids = $wpdb->get_col($prepared_exact);

    // --- 2) LIKE fallback (>=4) ---
    $len = function_exists('mb_strlen') ? mb_strlen($sp) : strlen($sp);
    if ( empty($ids) && $len >= 4 ) {
      $like = '%' . $wpdb->esc_like($sp) . '%';
      $sql_like = $wpdb->prepare(
        "SELECT DISTINCT post_id
         FROM {$wpdb->postmeta}
         WHERE meta_key IN ('shipment_code','urun_kodu')
           AND meta_value LIKE %s
         LIMIT %d",
        $like,
        $limit
      );
      $ids = $wpdb->get_col($sql_like);
    }

    $ids = ! empty($ids) ? array_values(array_unique(array_map('intval', $ids))) : array();

    // --- 3) JOIN'siz merchant / resutore filtresi ---
    $ids = sutore_vendor_filter_ids_by_meta($ids, $resutore_only);

    wp_cache_set($cache_key, $ids, 'sutore_vendor_search', 60);
    return $ids;
  }
}

function vendor_products_content() { ?>
  <?php
  $user = wp_get_current_user();
  if ( ! in_array('administrator', (array) $user->roles, true) && ! in_array('shop_manager', (array) $user->roles, true) ) {
    wp_safe_redirect(home_url());
    exit;
  }

  $paged = ( get_query_var('paged') ) ? (int) get_query_var('paged') : 1;

  // GET sanitize
  $search_param    = isset($_GET['search_param']) ? trim( sanitize_text_field( wp_unslash($_GET['search_param']) ) ) : '';
  $product_status  = isset($_GET['product_status']) ? sanitize_key( wp_unslash($_GET['product_status']) ) : '';
  $product_orderby = isset($_GET['product_orderby']) ? sanitize_key( wp_unslash($_GET['product_orderby']) ) : '';
  $selected_user   = isset($_GET['user']) ? (int) $_GET['user'] : 0;

  $is_search     = ($search_param !== '');
  $resutore_only = ($product_orderby === 'resutore_only');

  $args = array(
    'posts_per_page'         => 40,
    'paged'                  => $paged,
    'post_type'              => array('product_variation'),
    'post_status'            => array(
      'pending','publish','private','draft','shipped-to-sutore','arrived-to-sutore',
      'verified','confirmed','paid','shipped','sold','not-sale','ready-to-shipping',
      'payment','expired','chargeback','pre-order'
    ),
    'ignore_sticky_posts'    => true,
    'no_found_rows'          => false, // pagination lazım
  );

  /**
   * --- ARAMA MANTIĞI (JOIN YOK) ---
   * Arama varsa: post__in ile daralt, meta_query verme.
   */
  if ( $is_search ) {

    // ID ile arama
    if ( stripos($search_param, 'ID') === 0 ) {
      $id = (int) trim( str_ireplace('ID', '', $search_param) );

      if ( $id > 0 ) {
        // ID aramasında merchant/resutore şartlarını WP_Query'e bırakmadan doğrula
        $mp = get_post_meta($id, 'merchant_product', true);
        $ok = ($mp === '1');

        if ( $ok && $resutore_only ) {
          $rs = get_post_meta($id, 're_sutore_item', true);
          $ok = ($rs === '1');
        }

        if ( $ok ) {
          $args['p'] = $id;
        } else {
          $args['post__in'] = array(0);
        }
      } else {
        $args['post__in'] = array(0);
      }

    } else {
      $found_ids = sutore_find_variation_ids_for_vendor_search($search_param, $resutore_only);
    
      if ( ! empty($found_ids) ) {
        $args['post__in'] = $found_ids;
    
        // ✅ Aramada default: en yeni -> en eski
        // (post__in sırası zorlanmasın, sıralamalar ezilmesin)
        $args['orderby'] = 'ID';
        $args['order']   = 'DESC';
      } else {
        $args['post__in'] = array(0);
      }
    }

  } else {

    /**
     * --- NORMAL LİSTELEME ---
     * Burada meta_query basit (nested yok): merchant_product (+ opsiyonel re_sutore_item)
     */
    $meta_query = array('relation' => 'AND');
    $meta_query[] = array(
      'key'     => 'merchant_product',
      'value'   => '1',
      'compare' => '=',
    );

    if ( $resutore_only ) {
      $meta_query[] = array(
        'key'     => 're_sutore_item',
        'value'   => '1',
        'compare' => '=',
      );
    }

    $args['meta_query'] = $meta_query;
  }

  // Status ve User filtreleri (her zaman)
  if ( $product_status !== '' ) {
    $args['post_status'] = array($product_status);
  }
  if ( $selected_user > 0 ) {
    $args['author'] = $selected_user;
  }

  /**
   * Sıralamalar / Zone'lar
   * Zone sorguları sadece arama YOKSA çalışır (performans korunur)
   */
  $zone_orderbys = array('yellow_zone','red_zone','fast_shipment','awaiting_merchant');

  if ( $product_orderby && ( ! $is_search || ( $is_search && ! in_array($product_orderby, $zone_orderbys, true) ) ) ) {

    if ( $product_orderby === 'price' ) {
      $args['orderby']  = 'meta_value_num';
      $args['meta_key'] = '_price';

    } elseif ( $product_orderby === 'price_asc' ) {
      $args['orderby']  = 'meta_value_num';
      $args['meta_key'] = '_price';
      $args['order']    = 'ASC';

    } elseif ( $product_orderby === 'time' ) {
      $args['orderby']  = 'meta_value_num';
      $args['meta_key'] = 'expire_date';

    } elseif ( $product_orderby === 'time_asc' ) {
      $args['orderby']  = 'meta_value_num';
      $args['meta_key'] = 'expire_date';
      $args['order']    = 'ASC';

    } elseif ( $product_orderby === 'name' ) {
      $args['orderby'] = 'title';
      $args['order']   = 'DESC';

    } elseif ( $product_orderby === 'name_desc' ) {
      $args['orderby'] = 'title';
      $args['order']   = 'ASC';

    } elseif ( $product_orderby === 'order_date' ) {
      $args['orderby']  = 'meta_value_num';
      $args['meta_key'] = 'product_sold_date';
      $args['order']    = 'DESC';

    } elseif ( $product_orderby === 'merchant_level' ) {
      $ids = get_users(array(
        'role'       => 'merchant',
        'fields'     => 'ID',
        'meta_key'   => 'merchant_level',
        'meta_value' => 6
      ));
      $args['author__in'] = $ids;

    } elseif ( in_array($product_orderby, array('yellow_zone','red_zone','fast_shipment','awaiting_merchant'), true) ) {

      $item_ids = array();
      $allow_empty_zone_ids = false;
      $dashboard_order_limit = (int) apply_filters('sutore_dashboard_order_limit', -1);

      if ( $product_orderby === 'yellow_zone' ) {

        $orders = new WP_Query(array(
          'posts_per_page'=> -1,
          'post_type'     => 'shop_order',
          'post_status'   => array('wc-processing','wc-shipped-customer'),
          'orderby'       => 'meta_value_num',
          'order'         => 'asc',
          'meta_query'    => array(
            array(
              'key'     => 'sutore_shipment_deadline_timestamp',
              'value'   => current_time('timestamp', true) + 345600,
              'compare' => '<',
              'type'    => 'NUMERIC',
            ),
          ),
        ));

        if ( $orders->have_posts() ) {
          while ( $orders->have_posts() ) {
            $orders->the_post();
            $order = wc_get_order(get_the_ID());
            foreach ( $order->get_items() as $item ) {
              $vid = (int) $item->get_variation_id();
              if ($vid) $item_ids[] = $vid;
            }
          }
          wp_reset_postdata();
        }

        $args['post_status'] = array('shipped-to-sutore','confirmed','not-sale','sold','publish','draft','payment','pending','expired');

      } elseif ( $product_orderby === 'red_zone' ) {

        $orders = new WP_Query(array(
          'posts_per_page'=> -1,
          'post_type'     => 'shop_order',
          'post_status'   => array('wc-processing','wc-shipped-customer','wc-completed'),
          'orderby'       => 'meta_value_num',
          'order'         => 'asc',
          'meta_query'    => array(
            array(
              'key'     => 'sutore_shipment_deadline_timestamp',
              'value'   => current_time('timestamp', true) + 86400,
              'compare' => '<',
              'type'    => 'NUMERIC',
            ),
          ),
        ));

        if ( $orders->have_posts() ) {
          while ( $orders->have_posts() ) {
            $orders->the_post();
            $order = wc_get_order(get_the_ID());
            foreach ( $order->get_items() as $item ) {
              $vid = (int) $item->get_variation_id();
              if ($vid) $item_ids[] = $vid;
            }
          }
          wp_reset_postdata();
        }

        $args['post_status'] = array('ready-to-shipping','arrived-to-sutore','shipped-to-sutore','confirmed','not-sale','sold','publish','draft','payment','pending','expired');

      } elseif ( $product_orderby === 'fast_shipment' ) {

        $orders = new WP_Query(array(
          'posts_per_page'=> $dashboard_order_limit,
          'no_found_rows' => true,
          'fields'        => 'ids',
          'post_type'     => 'shop_order',
          'post_status'   => array('wc-processing','wc-shipped-customer'),
          'orderby'       => 'meta_value_num',
          'order'         => 'asc',
          'meta_query'    => array(
            array(
              'key'     => 'sutore_shipment_type',
              'value'   => 'fast',
              'compare' => '=',
            ),
          ),
        ));

        if ( !empty($orders->posts) ) {
          foreach ( $orders->posts as $order_post_id ) {
            $order = wc_get_order((int) $order_post_id);
            if (!$order) {
              continue;
            }
            foreach ( $order->get_items() as $item ) {
              $vid = (int) $item->get_variation_id();
              if ($vid) {
                $item_ids[] = $vid;
              }
            }
          }
        }

        $args['post_status'] = array('ready-to-shipping','arrived-to-sutore','shipped-to-sutore','confirmed','not-sale','sold','publish','draft','payment','pending','expired');

      } elseif ( $product_orderby === 'awaiting_merchant' ) {

        $orders = new WP_Query(array(
          'posts_per_page'=> $dashboard_order_limit,
          'no_found_rows' => true,
          'fields'        => 'ids',
          'post_type'     => 'shop_order',
          'post_status'   => array('wc-processing','wc-shipped-customer','wc-completed'),
          'orderby'       => 'meta_value_num',
          'order'         => 'asc',
          'meta_query'    => array(
            array(
              'key'     => 'sutore_shipment_deadline_timestamp',
              'value'   => strtotime('-6 weeks'),
              'compare' => '>=',
              'type'    => 'NUMERIC',
            ),
          ),
        ));

        if ( !empty($orders->posts) ) {
          foreach ( $orders->posts as $order_post_id ) {
            $order = wc_get_order((int) $order_post_id);
            if (!$order) {
              continue;
            }
            foreach ( $order->get_items() as $item ) {
              $vid = (int) $item->get_variation_id();
              if ($vid) {
                $item_ids[] = $vid;
              }
            }
          }
        }

        $args['post_status'] = array('payment','pre-order');
        $allow_empty_zone_ids = true;
      }

      if ( ! empty($item_ids) ) {
        $item_ids = array_values(array_unique(array_map('intval', $item_ids)));
        $args['post__in'] = $item_ids;
        $args['orderby']  = 'post__in';
      } else {
        if ( $allow_empty_zone_ids ) {
          $args['orderby'] = 'ID';
          $args['order']   = 'DESC';
        } else {
          $args['post__in'] = array(0);
        }
      }

    } elseif ( $product_orderby === 'pre_order' ) {
      $args['orderby']  = 'meta_value_num';
      $args['meta_key'] = 'pre_order';
      $args['order']    = 'DESC';
      $args['sutore_orderby_deadline_desc'] = true;
    }
  }

  $cquery = new WP_Query($args);
  ?>

  <style>
    #main .row.vertical-tabs{overflow:auto !important;-ms-overflow-style:none;scrollbar-width:none;}
    #main .row.vertical-tabs::-webkit-scrollbar{display:none;width:0;height:0;}
  </style>

  <fieldset>
    <legend>Satıcı Ürünleri</legend>
    <div class="cart-container container page-wrapper page-checkout svp">
      <div class="row row-large row-divided shop_table shop_table_responsive cart woocommerce-cart-form__contents">
        <div class="col large-12 pb-0 cart-auto-refresh">
          <div class="row">

            <div class="filter"><form method="get">
              <input type="text" style="width: 20%; float:left; margin-right:10px; border-color: #222 !important;"
                     name="search_param" placeholder="Kargo kodu, ürün kodu, sipariş no ile ara..."
                     value="<?php echo esc_attr($search_param); ?>"/>

              <select name="product_status" style="width: 20%;  float:left; height: 52px !important; border-color: #222 !important;">
                <option value="">Durum Seçin</option>
                <option value="sold" <?php selected($product_status, "sold"); ?>>Satıldı</option>
                <option value="draft" <?php selected($product_status, "draft"); ?>>Satış Sırasında</option>
                <option value="publish" <?php selected($product_status, "publish"); ?>>Satışta</option>
                <option value="not-sale" <?php selected($product_status, "not-sale"); ?>>Satışta Değil</option>
                <option value="payment" <?php selected($product_status, "payment"); ?>>Ödeme Bekleniyor</option>
                <option value="pending" <?php selected($product_status, "pending"); ?>>Onay Bekliyor</option>
                <option value="confirmed" <?php selected($product_status, "confirmed"); ?>>Satıcı Onayladı</option>
                <option value="shipped-to-sutore" <?php selected($product_status, "shipped-to-sutore"); ?>>Sutoreye Kargolandı</option>
                <option value="arrived-to-sutore" <?php selected($product_status, "arrived-to-sutore"); ?>>Sutoreye Ulaştı</option>
                <option value="ready-to-shipping" <?php selected($product_status, "ready-to-shipping"); ?>>Kargoya Hazır</option>
                <option value="verified" <?php selected($product_status, "verified"); ?>>Doğrulandı</option>
                <option value="shipped" <?php selected($product_status, "shipped"); ?>>Müşteriye Kargolandı</option>
                <option value="paid" <?php selected($product_status, "paid"); ?>>Ödendi</option>
                <option value="expired" <?php selected($product_status, "expired"); ?>>Süresi Doldu</option>
                <option value="chargeback" <?php selected($product_status, "chargeback"); ?>>İade Edildi</option>
                <option value="pre-order" <?php selected($product_status, "pre-order"); ?>>Ön Sipariş</option>
              </select>

              <div style="width: 15%;  float:left; height: 52px !important; margin-left:10px; border-color: #222 !important;">
                <?php wp_dropdown_users(array(
                  "role"            => "merchant",
                  "show"            => "user_login",
                  "selected"        => $selected_user,
                  "orderby"         => "user_login",
                  "show_option_all" => "Tümü",
                  "name"            => "user",
                )); ?>
              </div>

              <div style="width: 20%;  float:left; height: 52px !important; margin-left:10px; border-color: #222 !important;">
                <select name="product_orderby" style="width: 100%;  float:left; height: 52px !important; border-color: #222 !important;">
                  <option value="">Sıralama</option>
                  <option value="name" <?php selected($product_orderby, "name"); ?>>İsme Göre (Z-A)</option>
                  <option value="name_desc" <?php selected($product_orderby, "name_desc"); ?>>İsme Göre (A-Z)</option>
                  <option value="price" <?php selected($product_orderby, "price"); ?>>Fiyata Göre (Azalan)</option>
                  <option value="price_asc" <?php selected($product_orderby, "price_asc"); ?>>Fiyata Göre (Artan)</option>
                  <option value="order_date" <?php selected($product_orderby, "order_date"); ?>>Sipariş Tarihine Göre</option>
                  <option value="time_asc" <?php selected($product_orderby, "time_asc"); ?>>Kalan Süreye Göre (Artan)</option>
                  <option value="merchant_level" <?php selected($product_orderby, "merchant_level"); ?>>Sadece 6. Seviye Satıcılar</option>
                  <option value="yellow_zone" <?php selected($product_orderby, "yellow_zone"); ?>>Sarı Bölge</option>
                  <option value="red_zone" <?php selected($product_orderby, "red_zone"); ?>>Kırmızı Bölge</option>
                  <option value="fast_shipment" <?php selected($product_orderby, "fast_shipment"); ?>>Fast Gönderi</option>
                  <option value="pre_order" <?php selected($product_orderby, "pre_order"); ?>>Ön Sipariş</option>
                  <option value="awaiting_merchant" <?php selected($product_orderby, "awaiting_merchant"); ?>>Satıcı Bekleyenler</option>
                  <option value="resutore_only" <?php selected($product_orderby, "resutore_only"); ?>>re-sutore</option>
                </select>
              </div>

              <button type="submit" value="Ara" style="color:white; height: 52px !important; margin-left:10px; border-radius: 8px; font-weight: 500;">Ara</button>

              <?php if ($product_orderby || $product_status || $search_param !== '' || $selected_user) : ?>
                <a href="<?php echo esc_url( home_url('hesabim/49efigenia-81gunnarr/') ); ?>"
                   class="button"
                   style="margin-left:-3px;height:52px !important;display:inline-flex;align-items:center;border-radius:8px; font-weight: 500;"
                >Temizle</a>
              <?php endif; ?>
            </form></div>

            <?php if ( $cquery->have_posts() ) : ?>
              <table>
                <thead>
                  <td class="col-actions" style="width:1%"></td>
                  <td>ID</td>
                  <td>Görsel</td>
                  <td>İsim</td>
                  <td>Fiyat</td>
                  <td>Satıcı</td>
                  <td>Teslimat</td>
                  <td>Durum</td>
                  <td>SKU</td>
                </thead>
                <tbody>
                  <?php while ( $cquery->have_posts() ) : $cquery->the_post(); ?>
                    <?php include("inc/loops/vendor-products.php"); ?>
                  <?php endwhile; ?>
                </tbody>
              </table>

              <div class="pagination">
                <?php
                echo paginate_links( array(
                  'base'         => str_replace( 999999999, '%#%', esc_url( get_pagenum_link( 999999999 ) ) ),
                  'total'        => $cquery->max_num_pages,
                  'current'      => max( 1, get_query_var( 'paged' ) ),
                  'format'       => '?paged=%#%',
                  'show_all'     => false,
                  'type'         => 'plain',
                  'end_size'     => 2,
                  'mid_size'     => 1,
                  'prev_next'    => true,
                  'prev_text'    => sprintf( '<i></i> %1$s', __( 'İleri', 'text-domain' ) ),
                  'next_text'    => sprintf( '%1$s <i></i>', __( 'Geri', 'text-domain' ) ),
                  'add_args'     => false,
                  'add_fragment' => '',
                ) );
                ?>
              </div>

            <?php else : ?>
              <p><?php _e('Satıcı ürünü bulunamadı.'); ?></p>
            <?php endif; ?>

          </div>
        </div>
      </div>
    </div>
  </fieldset>

  <?php wp_reset_postdata(); ?>
<?php }

add_action( 'woocommerce_account_49efigenia-81gunnarr_endpoint', 'vendor_products_content' );



function vendors_content() {
  if ( ! current_user_can('manage_woocommerce') && ! current_user_can('list_users') ) {
    wp_safe_redirect(home_url());
    exit;
  }

  $args = array(
    'role'       => 'merchant',
    'order'      => 'ASC',
    'meta_key'   => 'merchant_level',
    'orderby'    => 'meta_value_num',
    'count_total'=> true,
  );

  $wp_user_query = new WP_User_Query($args);
  $authors = $wp_user_query->get_results();

  ?>
  <fieldset>
    <legend>Satıcılar</legend>
    <div class="cart-container container page-wrapper page-checkout">
      <div class="row row-large row-divided shop_table shop_table_responsive cart woocommerce-cart-form__contents">
        <div class="col large-12 pb-0 cart-auto-refresh">
          <div class="row">

            <?php if ( ! empty($authors) ) : ?>
              <table>
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>İsim Soyad</th>
                    <th>Kullanıcı Adı</th>
                    <th>E-Mail</th>
                    <th>Telefon</th>
                    <th>IBAN</th>
                    <th>Seviye</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($authors as $author) :
                    $author_info = get_userdata($author->ID);

                    $full_name = trim($author_info->first_name . ' ' . $author_info->last_name);
                    $phone     = (string) get_user_meta($author->ID, 'billing_phone_account', true);
                    $iban      = (string) get_user_meta($author->ID, 'account_iban', true);
                    $level     = (string) get_user_meta($author->ID, 'merchant_level', true);

                    // opsiyonel maskeleme:
                    $masked_iban = $iban ? ('•••• ' . substr(preg_replace('/\s+/', '', $iban), -4)) : '';
                  ?>
                    <tr>
                      <td><?php echo esc_html($author->ID); ?></td>
                      <td><?php echo esc_html($full_name ?: '—'); ?></td>
                      <td>
                        <?php if ( current_user_can('edit_user', $author->ID) ) : ?>
                          <a href="<?php echo esc_url(get_edit_user_link($author->ID)); ?>">
                            <?php echo esc_html($author_info->user_login); ?>
                          </a>
                        <?php else : ?>
                          <?php echo esc_html($author_info->user_login); ?>
                        <?php endif; ?>
                      </td>
                      <td><?php echo esc_html($author_info->user_email); ?></td>
                      <td><?php echo esc_html($phone ?: '—'); ?></td>
                      <td><?php echo esc_html($masked_iban ?: '—'); ?></td>
                      <td><?php echo esc_html($level ?: '—'); ?></td>
                      <td>
                        <a
                          href="#"
                          class="user-detail"
                          style="color:black;font-weight:bold;font-size:15px;display:block;text-align:center;"
                          data-userid="<?php echo esc_attr($author->ID); ?>"
                          data-nonce="<?php echo esc_attr(wp_create_nonce('merchant_detail_nonce')); ?>"
                        >Detay</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else : ?>
              <p><?php esc_html_e('Satıcı bulunamadı.'); ?></p>
            <?php endif; ?>

          </div>
        </div>
      </div>
    </div>
  </fieldset>
  <?php
}

add_action('woocommerce_account_njeri16-sanjica25_endpoint', 'vendors_content');



add_filter('posts_clauses', function($clauses, $query){
    if ( ! $query->get('sutore_orderby_deadline_desc') ) {
        return $clauses;
    }

    global $wpdb;

    $posts    = $wpdb->posts;
    $pm_var   = "{$wpdb->postmeta}";
    $oi       = "{$wpdb->prefix}woocommerce_order_items";
    $oim      = "{$wpdb->prefix}woocommerce_order_itemmeta";
    $orders   = "{$wpdb->posts}";
    $pm_order = "{$wpdb->postmeta}";

    // Varyasyon üzerindeki deadline (varsa)
    $clauses['join'] .= " LEFT JOIN $pm_var AS pm_var_deadline
                          ON pm_var_deadline.post_id = $posts.ID
                          AND pm_var_deadline.meta_key = 'sutore_shipment_deadline_timestamp' ";

    // Varyasyonu sipariş kalemine bağla (_variation_id)
    $clauses['join'] .= " LEFT JOIN $oim AS oim_var
                          ON oim_var.meta_key = '_variation_id'
                          AND oim_var.meta_value = $posts.ID ";

    // Kalemden siparişe (sadece line_item)
    $clauses['join'] .= " LEFT JOIN $oi AS oi
                          ON oi.order_item_id = oim_var.order_item_id
                          AND oi.order_item_type = 'line_item' ";

    // Sipariş post’u (durum filtresiyle)
    $clauses['join'] .= " LEFT JOIN $orders AS o
                          ON o.ID = oi.order_id
                          AND o.post_type = 'shop_order'
                          AND o.post_status IN ('wc-processing','wc-shipped-customer','wc-completed') ";

    // Siparişin deadline meta’sı
    $clauses['join'] .= " LEFT JOIN $pm_order AS pm_order_deadline
                          ON pm_order_deadline.post_id = o.ID
                          AND pm_order_deadline.meta_key = 'sutore_shipment_deadline_timestamp' ";

    // Tekil varyasyon
    $groupby = "{$posts}.ID";
    if ( empty($clauses['groupby']) ) {
        $clauses['groupby'] = $groupby;
    } elseif (strpos($clauses['groupby'], $groupby) === false) {
        $clauses['groupby'] .= ", $groupby";
    }

    // Önce varyasyon meta, yoksa siparişlerdeki EN YENİ deadline — DESC
    $orderby_expr = " COALESCE(CAST(pm_var_deadline.meta_value AS UNSIGNED), MAX(CAST(pm_order_deadline.meta_value AS UNSIGNED)), 0) DESC ";

    $clauses['orderby'] = $orderby_expr . ( $clauses['orderby'] ? ', ' . $clauses['orderby'] : '' );

    return $clauses;
}, 10, 2);

function pre_order_products_content() { ?>
  <?php
  $paged = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;

  $args = array(
    'posts_per_page' => 40,
    'paged' => $paged,
    'post_type' => "product_variation",
    'post_status' => array("pre-order"),
    'sutore_orderby_deadline_desc' => true,
  );

  $cquery = new WP_Query( $args);

  ?>
  <fieldset class="sutore-preorder">
    <legend>Ön Siparişler</legend>

    <div style="margin-bottom:30px; padding:16px; border:1px solid #ccc; background:#f9f9f9; border-radius:6px;">
      <p>
        Bu sayfada listelenen ürünlere ilişkin satışları kabul etmeniz halinde, aşağıdaki hususları peşinen kabul, beyan ve taahhüt etmiş sayılırsınız:
      </p>
      <ul style="margin-left:20px;">
        <li>Ürünlerin orijinal ve yürürlükteki gümrük mevzuatına uygun olduğunu,</li>
        <li>Ürünleri en geç ilgili üründe belirtilen teslimat tarihine kadar sutore kontrol merkezine eksiksiz ve hasarsız olarak ulaştıracağınızı,</li>
        <li>Belirtilen süre içerisinde ürünleri ulaştırmamanız halinde doğabilecek iptal, iade ve her türlü zarar ile sair yükümlülüklerden münhasıran sorumlu olduğunuzu.</li>
      </ul>
      <p>
        Yukarıdaki yükümlülüklere aykırılık hâlinde oluşabilecek tüm zararların tarafınızca karşılanacağını kabul etmiş sayılırsınız.
      </p>
    </div>

    <div class="cart-container container page-wrapper page-checkout">
      <div class="row row-large row-divided shop_table shop_table_responsive cart woocommerce-cart-form__contents">
        <div class="col large-12 pb-0 cart-auto-refresh">
          <div class="row">
          <?php if ( $cquery->have_posts() ) : ?>
        
            <?php while ( $cquery->have_posts() ) : $cquery->the_post(); ?>
              <?php include("inc/loops/pre-order.php"); ?>
            <?php endwhile; ?>
        
            <?php wp_reset_postdata(); ?>
        
          <?php else : ?>
        
            <p><?php _e( 'Henüz satışta olan bir ürününüz bulunmuyor.' ); ?></p>
        
          <?php endif; ?>
           <div class="pagination">
                  <?php
                  echo paginate_links( array(
                    'base'         => str_replace( 999999999, '%#%', esc_url( get_pagenum_link( 999999999 ) ) ),
                    'total'        => $cquery->max_num_pages,
                    'current'      => max( 1, get_query_var( 'paged' ) ),
                    'format'       => '?paged=%#%',
                    'show_all'     => false,
                    'type'         => 'plain',
                    'end_size'     => 2,
                    'mid_size'     => 1,
                    'prev_next'    => true,
                    'prev_text'    => sprintf( '<i></i> %1$s', __( 'İleri', 'text-domain' ) ),
                    'next_text'    => sprintf( '%1$s <i></i>', __( 'Geri', 'text-domain' ) ),
                    'add_args'     => false,
                    'add_fragment' => '',
                  ) );
                  ?>
                </div>
          </div>
        </div>
      </div>
    </fieldset>

<?php }
add_action( 'woocommerce_account_pre-order_endpoint', 'pre_order_products_content' );



function on_sales_content() { ?>

  <fieldset>

    <div class="cart-container container page-wrapper page-checkout">
      <div class="row row-large row-divided shop_table shop_table_responsive cart woocommerce-cart-form__contents">
        <div class="col large-12 pb-0 cart-auto-refresh">
          <div class="row">

            <?php
            $paged = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;

            $args = array(
              'posts_per_page' => 40,
              'paged' => $paged,
              'post_type' => array("product","product_variation"),
              'author' => get_current_user_id(),
              'post_status' => array("pending","publish","private","draft","not-sale","expired","payment"),
            );

            if(isset($_GET["product_orderby"])){
              if($_GET["product_orderby"] == "price") {
                $args["orderby"] = "meta_value_num";
                $args["meta_key"] = "_price";
              }elseif($_GET["product_orderby"] == "price_asc") {
                $args["orderby"] = "meta_value_num";
                $args["meta_key"] = "_price";
                $args["order"] = "ASC";
              }elseif($_GET["product_orderby"] == "time") {
                $args["orderby"] = "meta_value_num";
                $args["meta_key"] = "expire_date";
              }elseif($_GET["product_orderby"] == "time_asc") {
                $args["orderby"] = "meta_value_num";
                $args["meta_key"] = "expire_date";
                $args["order"] = "ASC";
              }elseif($_GET["product_orderby"] == "sale") {
                $args["orderby"] = "meta_value_num";
                $args["meta_key"] = "product_sold_date";
                $args["order"] = "ASC";
              }elseif($_GET["product_orderby"] == "name") {
                $args["orderby"] = "title";
                $args["order"] = "DESC";
              }elseif($_GET["product_orderby"] == "name_desc") {
                $args["orderby"] = "title";
                $args["order"] = "ASC";
              }elseif($_GET["product_orderby"] == "status") {
                $args["orderby"] = "post_status";
                $args["order"] = "ASC";
              }
            }

            $user = wp_get_current_user();

            $cquery = new WP_Query( $args );

            ?>

            <?php if ( get_user_meta( $user->ID, 'merchant_level', true ) == 1 ) : ?>
              <?php $wa_url = 'https://api.whatsapp.com/send?phone=908503070927'; ?>
              <p class="vendor-on-sales first-level-wp">
                <b>UYARI:</b>
                Ürünlerinizin onaylanması için
                <a href="<?php echo esc_url( $wa_url ); ?>"
                   target="_blank"
                   rel="noopener"
                   class="wa-link"
                   aria-label="WhatsApp üzerinden iletişime geçin">
                   0850 307 0927
                </a>
                numaralı WhatsApp hattı üzerinden bizimle iletişime geçmeniz gerekmektedir.
              </p>
            <?php endif; ?>
            
            <legend class="vendor-on-sales">Satıştaki Ürünlerim</legend>

            <div class="filter vendor-on-sales">
              <form method="get" style="display: flex; flex-direction: row; flex-wrap: wrap; justify-content: flex-end;">
                <select name="product_orderby" style="width: calc(76% - 20px); float: left; height: 52px !important; border-color: #222 !important;}">
                  <option value="">Sıralama</option>
                  <option value="name" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "name" ); ?>>İsme Göre (Z-A)</option>
                  <option value="name_desc" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "name_desc" ); ?>>İsme Göre (A-Z)</option>
                  <option value="price" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "price" ); ?>>Fiyata Göre (Azalan)</option>
                  <option value="price_asc" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "price_asc" ); ?>>Fiyata Göre (Artan)</option>
                  <option value="time" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "time" ); ?>>Kalan Süreye Göre (Azalan)</option>
                  <option value="time_asc" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "time_asc" ); ?>>Kalan Süreye Göre (Artan)</option>
                  <option value="status" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "status" ); ?>>Ürün Durumuna Göre</option>
                </select>
                <button type="submit" value="Ara" style="width: 24%; color: white; height: 52px !important; margin-right: 0px; border-radius: 8px; font-weight: 500; margin-left: 20px;">Sırala</button>
              </form></div>

              <?php if ( $cquery->have_posts() ) :  ?>

                <!-- begin loop -->
                <?php while ( $cquery->have_posts() ) : $cquery->the_post(); ?>
                  <?php include("inc/loops/on-sale.php"); ?>

                <?php endwhile; ?>
                <!-- end loop -->


                <div class="pagination">
                  <?php
                  echo paginate_links( array(
                    'base'         => str_replace( 999999999, '%#%', esc_url( get_pagenum_link( 999999999 ) ) ),
                    'total'        => $cquery->max_num_pages,
                    'current'      => max( 1, get_query_var( 'paged' ) ),
                    'format'       => '?paged=%#%',
                    'show_all'     => false,
                    'type'         => 'plain',
                    'end_size'     => 2,
                    'mid_size'     => 1,
                    'prev_next'    => true,
                    'prev_text'    => sprintf( '<i></i> %1$s', __( 'İleri', 'text-domain' ) ),
                    'next_text'    => sprintf( '%1$s <i></i>', __( 'Geri', 'text-domain' ) ),
                    'add_args'     => false,
                    'add_fragment' => '',
                  ) );
                  ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </fieldset>


      <?php wp_reset_postdata(); ?>

    <?php else : ?>
      <p><?php _e( 'Henüz satışta olan bir ürününüz bulunmuyor.' ); ?></p>
    <?php endif; ?>

  <?php }

  add_action( 'woocommerce_account_on-sales_endpoint', 'on_sales_content' );

  add_filter('posts_orderby', 'custom_post_status', 10,2);
  function custom_post_status($args, $wp_query){
    if(isset($wp_query->query_vars['orderby']) && $wp_query->query_vars['orderby'] == 'post_status') {
      $order = strtoupper($wp_query->query_vars['order'] ?? 'ASC');
      if (!in_array($order, array('ASC', 'DESC'), true)) {
        $order = 'ASC';
      }
      return 'post_status ' . $order;
    }
    return $args;
  }

  function sold_out_content() { ?>
    <fieldset>
      <legend>Satılan Ürünlerim</legend>
      <div class="cart-container container page-wrapper page-checkout">
        <div class="row row-large row-divided shop_table shop_table_responsive cart woocommerce-cart-form__contents">
          <div class="col large-12 pb-0 cart-auto-refresh">
            <div class="row">
              <div class="filter vendor-sold-out">
                <form method="get" style="display: flex; flex-direction: row; flex-wrap: wrap; justify-content: flex-end;">
                  <select name="product_orderby" style="width: calc(76% - 20px); float: left; height: 52px !important; border-color: #222 !important;">
                    <option value="">Sıralama</option>
                    <option value="name" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "name" ); ?>>İsme Göre (Z-A)</option>
                    <option value="name_desc" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "name_desc" ); ?>>İsme Göre (A-Z)</option>
                    <option value="price" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "price" ); ?>>Fiyata Göre (Azalan)</option>
                    <option value="price_asc" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "price_asc" ); ?>>Fiyata Göre (Artan)</option>
                    <option value="sale" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "sale" ); ?>>Satış Tarihine Göre (Yeni)</option>
                    <option value="sale_asc" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "sale_asc" ); ?>>Satış Tarihine Göre (Eski)</option>
                    <option value="status" <?php selected( isset($_GET["product_orderby"]) ? $_GET["product_orderby"] : null, "status" ); ?>>Ürün Durumuna Göre</option>
                  </select>
                  <button type="submit" value="Ara" style="width: 24%; color: white; height: 52px !important; margin-right: 0px; border-radius: 8px; font-weight: 500; margin-left: 20px;">Sırala</button>
                </form></div>

                <?php
                $paged = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;

                $args = array(
                  'posts_per_page' => 40,
                  'paged' => $paged,
                  'post_type' => array("product","product_variation"),
                  'orderby' => "date",
                  'author' => get_current_user_id(),
                  'post_status' => array("shipped-to-sutore","arrived-to-sutore","verified","confirmed","paid","shipped","sold","ready-to-shipping","chargeback"),
                );

                if(isset($_GET["product_orderby"])){
                  if($_GET["product_orderby"] == "price") {
                    $args["orderby"] = "meta_value_num";
                    $args["meta_key"] = "_price";
                  }elseif($_GET["product_orderby"] == "price_asc") {
                    $args["orderby"] = "meta_value_num";
                    $args["meta_key"] = "_price";
                    $args["order"] = "ASC";
                  }elseif($_GET["product_orderby"] == "time") {
                    $args["orderby"] = "meta_value_num";
                    $args["meta_key"] = "expire_date";
                  }elseif($_GET["product_orderby"] == "time_asc") {
                    $args["orderby"] = "meta_value_num";
                    $args["meta_key"] = "expire_date";
                    $args["order"] = "ASC";
                  }elseif($_GET["product_orderby"] == "sale") {
                    $args["orderby"] = "meta_value_num";
                    $args["meta_query"] = array(
                      'relation' => 'OR',
                      array(
                        'key'     => 'product_sold_date',
                        'compare' => 'EXISTS',
                      ),
                      array(
                        'key'     => 'product_sold_date',
                        'compare' => 'NOT EXISTS',
                      ),
                    );
                  }elseif($_GET["product_orderby"] == "sale_asc") {
                    $args["orderby"] = "meta_value_num";
                    $args["order"] = "ASC";
                    $args["meta_query"] = array(
                      'relation' => 'OR',
                      array(
                        'key'     => 'product_sold_date',
                        'compare' => 'EXISTS',
                      ),
                      array(
                        'key'     => 'product_sold_date',
                        'compare' => 'NOT EXISTS',
                      ),
                    );
                  }elseif($_GET["product_orderby"] == "name") {
                    $args["orderby"] = "title";
                    $args["order"] = "DESC";
                  }elseif($_GET["product_orderby"] == "name_desc") {
                    $args["orderby"] = "title";
                    $args["order"] = "ASC";
                  }elseif($_GET["product_orderby"] == "status") {
                    $args["orderby"] = "post_status";
                    $args["order"] = "ASC";
                  }
                }

                $cquery = new WP_Query( $args );
                ?>

                <?php if ( $cquery->have_posts() ) :  ?>

                  <!-- begin loop -->
                  <?php while ( $cquery->have_posts() ) : $cquery->the_post(); ?>
                    <?php include("inc/loops/sold-out.php"); ?>

                  <?php endwhile; ?>
                  <!-- end loop -->


                  <div class="pagination">
                    <?php
                    echo paginate_links( array(
                      'base'         => str_replace( 999999999, '%#%', esc_url( get_pagenum_link( 999999999 ) ) ),
                      'total'        => $cquery->max_num_pages,
                      'current'      => max( 1, get_query_var( 'paged' ) ),
                      'format'       => '?paged=%#%',
                      'show_all'     => false,
                      'type'         => 'plain',
                      'end_size'     => 2,
                      'mid_size'     => 1,
                      'prev_next'    => true,
                      'prev_text'    => sprintf( '<i></i> %1$s', __( 'İleri', 'text-domain' ) ),
                      'next_text'    => sprintf( '%1$s <i></i>', __( 'Geri', 'text-domain' ) ),
                      'add_args'     => false,
                      'add_fragment' => '',
                    ) );
                    ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </fieldset>


        <?php wp_reset_postdata(); ?>

      <?php else : ?>
        <p><?php _e( 'Üzgünüz, henüz satılan bir ürününüz bulunmuyor.' ); ?></p>
      <?php endif; ?>

    <?php }


    add_action( 'woocommerce_account_sold-out_endpoint', 'sold_out_content' );


    /**
    * Endpoint HTML content.
    */
    function my_custom_endpoint_content() { ?>
      <?php
      $user = wp_get_current_user();
      ?>
      <?php if ( in_array( 'merchant', (array) $user->roles ) ) : ?>
        <fieldset>

          <legend>Satıcı Seviyeniz</legend>

          <p>Mevcut Seviyeniz: <b>Sv.<?php echo esc_html(get_user_meta($user->ID,"merchant_level",true)); ?></b></p>
          <p>Komisyon Oranınız: <b><?php echo get_merchant_commision($user->ID); ?>%</b></p>


        </fieldset>
      <?php endif; ?>
      <fieldset>
        <legend>Faturalandırma</legend>
        <?php if ( !in_array( 'merchant', (array) $user->roles ) ) : ?>
          <p>Satıcı alanına erişmek için aşağıda yer alan bilgileri eksiksiz doldurmanız gerekmektedir.</p>
          <br>
        <?php endif; ?>
        <form class="sms-validate" autocomplete="off">
          <?php  woocommerce_form_field(
            'account_name',
            array(
              'type' => 'text',
              'required' => true,
              'label' => __('Hesap Sahibi Adı', 'woocommerce'),
              'class' => array('form-row-first'),
              'custom_attributes' => array("required" => "required", "pattern" => "[a-zA-ZığüşöçİĞÜŞÖÇ ]+"),

            ),
            (get_user_meta("$user->ID","account_name",true))
          );
          woocommerce_form_field(
            'account_lastname',
            array(
              'type' => 'text',
              'required' => true,
              'label' => __('Hesap Sahibi Soyadı', 'woocommerce'),
              'class' => array('form-row-last'),
              'custom_attributes' => array("required" => "required","pattern" => "[a-zA-ZığüşöçİĞÜŞÖÇ ]+"),
            ),
            (get_user_meta("$user->ID","account_lastname",true))
          );
          woocommerce_form_field(
            'account_iban',
            array(
              'type' => 'text',
              'required' => true,
              'label' => __('IBAN', 'woocommerce'),
              'class' => array('form-row-first'),
              'custom_attributes' => array("required" => "required", "pattern" => "[a-zA-Z0-9-]+"),
            ),
            (get_user_meta("$user->ID","account_iban",true) ? get_user_meta("$user->ID","account_iban",true) : "TR")
          );
          woocommerce_form_field(
            'account_tckno',
            array(
              'type' => 'number',
              'required' => true,
              'label' => __('TC Kimlik Numarası', 'woocommerce'),
              'class' => array('form-row-last'),
              'custom_attributes' => array("required" => "required", "pattern" => "[0-9]{11}"),
            ),
            (get_user_meta("$user->ID","account_tckno",true))
          );
          woocommerce_form_field(
            'account_email',
            array(
              'type' => 'email',
              'required' => true,
              'label' => __('E-posta Adresi', 'woocommerce'),
              'class' => array('form-row-first'),
              'custom_attributes' => array("required" => "required"),
            ),
            (get_user_meta("$user->ID","account_email",true))
          );
          woocommerce_form_field(
            'account_phone',
            array(
              'type' => 'number',
              'required' => true,
              'label' => __('Telefon Numarası', 'woocommerce'),
              'class' => array('form-row-last'),
              'custom_attributes' => array("required" => "required", "pattern" => "[0-9]{10}"),
            ),
            (get_user_meta("$user->ID","account_phone",true))
          );
          $states = WC()->countries->get_states("TR");
          woocommerce_form_field(
            'account_city',
            array(
              'type' => 'select',
              'required' => true,
              'label' => __('Şehir', 'woocommerce'),
              'class' => array('form-row-first'),
              'options' => $states,
              'custom_attributes' => array("required" => "required"),
            ),
            (get_user_meta("$user->ID","account_city",true))
          );
          //print_r($states);

          woocommerce_form_field(
            'account_state',
            array(
              'type' => 'select',
              'required' => true,
              'label' => __('İlçe / Semt', 'woocommerce'),
              'class' => array('form-row-last'),
              'options' => array(get_user_meta("$user->ID","account_state",true) => get_user_meta("$user->ID","account_state",true)),
              'custom_attributes' => array("required" => "required"),
            ),
            (get_user_meta("$user->ID","account_state",true))
          );
          ?>
          <?php
          woocommerce_form_field(
            'current_password',
            array(
              'type' => 'password',
              'required' => true,
              'label' => __('Mevcut Parolanız', 'woocommerce'),
              'class' => array('form-row-wide'),
              'custom_attributes' => array("required" => "required"),
            ),

          );
          ?>
          <?php wp_nonce_field("sutore_merchant_nonce","merchant_field_nonce"); ?>
          <p>
            <small>Ödemeniz ürün satıldığı anda sisteme kayıtlı olan bilgiler üzerinden gerçekleştirileceği için
              bilgilerinizin güncel ve doğru olduğunu, aksi hallerde oluşabilecek zarar ve diğer
              yükümlülüklerden tarafınızın sorumlu olacağını peşinen kabul, beyan ve taahhüt etmiş
              sayılırsınız.
            </small>
          </p>
          <input type="hidden" name="action" value="update_merchant_data">
          <input type="submit" id="validateSMS" value="<?php echo !in_array( 'merchant', (array) $user->roles ) ? "Bilgilerimi Kaydet" : "Bilgilerimi Güncelle"; ?>">
        </form>
      </fieldset>

    <?php }

    add_action( 'woocommerce_account_merchant-area_endpoint', 'my_custom_endpoint_content' );



    function sell_product_content() { ?>
      <form>
        <legend>Ürün Ekle</legend>
        <p>Platforma yüklediğiniz ürünlerle ilgili olarak aşağıdakilerle sınırlı olmamak üzere tüm kullanım koşullarını anladığınızı ve onayladığınızı; bu koşulların karşılanmaması durumunda doğabilecek tüm zarar ve diğer yükümlülükleri karşılayacağınızı peşinen kabul, beyan ve taahhüt etmiş sayılırsınız.</p>
        <ul style="margin-left: 20px;">
          <li>Aracı hizmet sağlayıcı olan sutore'nin ürünün alıcısı/satıcısı konumunda bulunmadığını,</li>
          <li>Ürünlerin ilanda belirtilen nitelikleri taşıdığını ve orijinal olduğunu,</li>
          <li>Satılması durumunda talep ettiğiniz fiyat üzerinden, zamanında ve eksiksiz/hasarız bir şekilde ürünü kontrol merkezimize ulaştırmakla yükümlü olduğunuzu.</li>
        </ul>
        <br>
        <?php
        //
        //        $args = array(
        //            'orderby' => 'name',
        //            'order' => 'ASC',
        //            'parent' => 0,
        //            'taxonomy' => "product_cat",
        //            'hide_empty' => false,
        //            'exclude' => array(18,103,362)
        //        );
        //        $categories = get_categories($args);
        //        $catArray[""] = "Ürün kategorisi";
        //        foreach($categories as $cat){
        //            $catArray[$cat->term_id] = $cat->name;
        //        }
        //
        //        woocommerce_form_field(
        //            'product_category',
        //            array(
        //                'type' => 'select',
        //                'required' => true,
        //                'label' => __('Ürün Kategorisi', 'woocommerce'),
        //                'class' => array('form-row-wide'),
        //                'custom_attributes' => array("required" => "required"),
        //                'options' => $catArray,
        //
        //            ),
        //            (get_user_meta("$user->ID","account_name",true))
        //        );
        woocommerce_form_field(
          'product_status',
          array(
            'type' => 'select',
            'required' => true,
            'label' => __('Ürün Durumu', 'woocommerce'),
            'class' => array('form-row-first'),
            'custom_attributes' => array("required" => "required"),
            //'options' => array("" => "Ürün durumu", "1" => "Yeni", "2" => "Kullanılmış"),
            'options' => array("" => "Ürün durumu", "1" => "Yeni"),

          )
        );
        //        woocommerce_form_field(
        //            'product_type',
        //            array(
        //                'type' => 'select',
        //                'required' => true,
        //                'label' => __('Ürün Tipi', 'woocommerce'),
        //                'class' => array('form-row-last'),
        //                'custom_attributes' => array("required" => "required", "disabled" => "disabled"),
        //                'options' => array("" => "Ürün tipi", "1" => "Varyasyonlu", "2" => "Basit"),
        //
        //            ),
        //            (get_user_meta("$user->ID","account_name",true))
        //        );
        woocommerce_form_field(
          'product_code',
          array(
            'type' => 'text',
            'required' => true,
            'label' => __('Ürün Kodu', 'woocommerce'),
            'class' => array('form-row-last'),
            'custom_attributes' => array("required" => "required", "disabled" => "disabled"),
          ),
          (get_user_meta("$user->ID","account_lastname",true))
        );
        ?>
        <ul class="variations">
        </ul>
        <?php //get_product_min_price(12494,87); ?>
        <br>
        <?php wp_nonce_field("sutore_merchant_nonce","merchant_field_nonce"); ?>
        <input type="hidden" name="action" value="add_product">
        <input type="hidden" id="not_exists" value="0">
      </fieldset>
    </form>

  <?php // Form burada bitiyor ?>

    <?php
}

  add_action( 'woocommerce_account_sell-product_endpoint', 'sell_product_content' );


  add_action( 'user_profile_update_errors','wooc_validate_custom_field', 10, 1 );
  add_action( 'woocommerce_save_account_details_errors','wooc_validate_custom_field', 10, 1 );


  add_filter('woocommerce_save_account_details_required_fields', 'wc_save_account_details_required_fields' );
  function wc_save_account_details_required_fields( $required_fields ){
    unset( $required_fields['account_display_name'] );
    return $required_fields;
  }


  function wooc_validate_custom_field( $args )
  {
    if ( isset( $_POST['reg_username'] ) ) // Your custom field
    {
      if(strlen($_POST['reg_username'])<4 ) // condition to be adapted
      $args->add( 'error', __( 'Your error message', 'woocommerce' ),'');
    }
  }

  function sutore_extra_account_fields() { ?>
    <?php
    $user = wp_get_current_user();
    ?>
    <style>.woocommerce-EditAccountForm.edit-account.fl-form {
      display: none;
      }</style>
      <fieldset>
        <legend><?php echo __('Account Details', 'sutore'); ?></legend>
        <form class="sms-validate">
          <?php  woocommerce_form_field(
            'user_name',
            array(
              'type' => 'text',
              'required' => true,
              'placeholder' => __('First Name', 'woocommerce'),
              'label' => __('First Name', 'woocommerce'),
              'class' => array('form-row-first'),
              'custom_attributes' => array("required" => "required"),
            ),
            ($user->first_name)
          );
          woocommerce_form_field(
            'user_lastname',
            array(
              'type' => 'text',
              'required' => true,
              'placeholder' => __('Last Name', 'woocommerce'),
              'label' => __('Last Name', 'woocommerce'),
              'class' => array('form-row-last'),
              'custom_attributes' => array("required" => "required"),
            ),
            ($user->last_name)
          );
          woocommerce_form_field(
            'user_email',
            array(
              'type' => 'email',
              'required' => true,
              'label' => __('Email Address', 'woocommerce'),
              'class' => array('form-row-first'),
              'custom_attributes' => array("required" => "required"),
            ),
            ($user->user_email)
          );
          woocommerce_form_field(
            'user_phone',
            array(
              'type' => 'text',
              'required' => true,
              'label' => __('Phone', 'woocommerce'),
              'class' => array('form-row-last'),
              'custom_attributes' => array("required" => "required"),
            ),
            (get_user_meta( $user->ID, 'billing_phone_account', true ))
          );
          ?>
          <?php
          woocommerce_form_field(
            'current_password',
            array(
              'type' => 'password',
              'required' => true,
              'label' => __('Current Password', 'woocommerce'),
              'class' => array('form-row-wide'),
              'custom_attributes' => array("required" => "required"),
            ),
          );

          ?>
          <div id="payment" style="margin-bottom:25px;">
            <p class="form-row  custom-checkboxes">
                  <label style="font-weight: normal; font-size: inherit !important; padding: 0px 0px 0px 20px; color: black !important;">
                    <input id="woocCheck" type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="user_campaing" name="user_campaing" value="1" <?php checked( get_user_meta( $user->ID, 'marketing_consent', true ), 1 ); ?>><span class="checkmark" style="top:2px; left:0px;"></span> <?php esc_attr_e("I would like to receive marketing communications from sutore via email and phone.", 'sutore');?>
                  </label>
                </p>
          </div>

          <?php wp_nonce_field("sutore_merchant_nonce","merchant_field_nonce"); ?>
          <input type="hidden" name="action" value="update_user_data">
          <input type="submit" value="<?php echo __('Update Details', 'sutore'); ?>">
        </form>
      </fieldset>

      <fieldset>
        <legend><?php echo __('Change Password', 'sutore'); ?></legend>
        <form class="sms-validate">
          <?php  woocommerce_form_field(
            'new_password',
            array(
              'type' => 'password',
              'required' => true,
              'label' => __('New Password', 'woocommerce'),
              'class' => array('form-row-first'),
              'custom_attributes' => array("required" => "required"),
            ),
            (isset($_POST['reg_username']) ? esc_attr(wp_unslash($_POST['reg_username'])) : '')
          );
          woocommerce_form_field(
            'new_password_repeat',
            array(
              'type' => 'password',
              'required' => true,
              'label' => __('New Password (Repeat)', 'woocommerce'),
              'class' => array('form-row-last'),
              'custom_attributes' => array("required" => "required"),
            ),
            (isset($_POST['reg_username']) ? esc_attr(wp_unslash($_POST['reg_username'])) : '')
          );
          woocommerce_form_field(
            'current_password',
            array(
              'type' => 'password',
              'required' => true,
              'label' => __('Current Password', 'woocommerce'),
              'class' => array('form-row-wide'),
              'custom_attributes' => array("required" => "required"),
            ),
            (isset($_POST['reg_username']) ? esc_attr(wp_unslash($_POST['reg_username'])) : '')
          );
          ?>
          <?php wp_nonce_field("sutore_merchant_nonce","merchant_field_nonce"); ?>
          <input type="hidden" name="action" value="update_user_password">
          <input type="submit" value="<?php echo __('Update Password', 'sutore'); ?>">
        </form>
      </fieldset>

      <fieldset>
        <form class="sms-validate">
          <legend><?php echo __('Delete Account', 'sutore'); ?></legend>
          <?php  woocommerce_form_field(
            'current_password',
            array(
              'type' => 'password',
              'required' => true,
              'label' => __('Current Password', 'woocommerce'),
              'class' => array('form-row-wide'),
              'custom_attributes' => array("required" => "required"),
            ),
            (isset($_POST['reg_username']) ? esc_attr(wp_unslash($_POST['reg_username'])) : '')
          );
          ?>
          <div><em><?php echo __('All information regarding your account will be deleted.', 'sutore'); ?></em></div>
          <br>
          <?php wp_nonce_field("sutore_merchant_nonce","merchant_field_nonce"); ?>
          <input type="hidden" name="action" value="delete_user_account">
          <input type="hidden" name="doing" value="delete_user_account">
          <input type="submit" value="<?php echo __('Delete My Account', 'sutore'); ?>">
        </form>
      </fieldset>
    <?php }

    add_action( 'woocommerce_after_edit_account_form', 'sutore_extra_account_fields', 10 );

    /**
    * Kayıt & panel script / css enqueue + localize
    */

    function sutore_register_style_scripts() {
      // Sadece ilgili sayfalarda yükle
      if ( is_account_page() || is_checkout() ) {

        $js_rel  = 'js/register.js';
        $css_rel = 'css/register.css';
    
        $js_path  = plugin_dir_path(__FILE__) . $js_rel;
        $css_path = plugin_dir_path(__FILE__) . $css_rel;
    
        $ver_js  = file_exists($js_path)  ? filemtime($js_path)  : SUTORE_PLUGIN_VERSION;
        $ver_css = file_exists($css_path) ? filemtime($css_path) : SUTORE_PLUGIN_VERSION;
    
        // Ana JS
        wp_enqueue_script(
          'sutore-register-js',
          plugin_dir_url(__FILE__) . $js_rel,
          array('jquery'),
          $ver_js,
          true
        );

        // Localize verileri (handle enqueued edildikten hemen sonra)
        $user_id = get_current_user_id();
        wp_localize_script(
          'sutore-register-js',
          'sutoreRegister',
          array(
            'user_state'   => get_user_meta($user_id, 'billing_state', true),
            'user_city'    => get_user_meta($user_id, 'billing_city', true),
            'user_country' => get_user_meta($user_id, 'billing_country', true),
            'ajaxurl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('sutore-register-request'),
            'tckn_opt'     => __('TCKN (İsteğe Bağlı)', 'woocommerce'),
            'state'        => __('State', 'woocommerce'),
            'city'         => __('City', 'woocommerce'),
          )
        );
    
        // SweetAlert sadece bu sayfalarda
        wp_enqueue_script(
          'sutore-sweetalert-js',
          'https://cdn.jsdelivr.net/npm/sweetalert2@11',
          array('jquery'),
          null,
          true
        );
    
        // CSS
        wp_enqueue_style(
          'sutore-register-css',
          plugin_dir_url(__FILE__) . $css_rel,
          array(),
          $ver_css
        );
      }
    }
    add_action( 'wp_enqueue_scripts', 'sutore_register_style_scripts' );

    function wpdocs_theme_name_scripts() {
      // FloatLabels sadece ihtiyaç olduğunda kullanılıyor olacak; handle burada.
      wp_enqueue_script(
        'flatsome-woocommerce-floating-labels',
        get_template_directory_uri() . '/assets/libs/float-labels.min.js',
        array('flatsome-theme-woocommerce-js'),
        '3.5',
        true
      );    }
    add_action( 'wp_enqueue_scripts', 'wpdocs_theme_name_scripts' );

    add_action('woocommerce_register_form_start', 'eg_register_fields');

    function eg_register_fields() {
      wp_dequeue_style('selectWoo');
      wp_deregister_style('selectWoo');
      wp_dequeue_script('selectWoo');
      wp_deregister_script('selectWoo');
      woocommerce_form_field(
        'reg_email',
        array(
          'type' => 'email',
          'required' => true,
          'label' => __('Email Address', 'woocommerce'),
          'class' => (('no' === get_option('woocommerce_registration_generate_username')) ? array('form-row-first') : array('form-row-wide')),
        ),
        (isset($_POST['reg_email']) ? esc_attr(wp_unslash($_POST['reg_email'])) : '')
      );
      if ('no' === get_option('woocommerce_registration_generate_username')) {
        woocommerce_form_field(
          'reg_username',
          array(
            'type' => 'text',
            'required' => true,
            'label' => __('Username', 'woocommerce'),
            'class' => array('form-row-last'),
          ),
          (isset($_POST['reg_username']) ? esc_attr(wp_unslash($_POST['reg_username'])) : '')
        );
      }
      woocommerce_form_field(
        'reg_password',
        array(
          'type' => 'password',
          'required' => true,
          'label' => __('Password', 'woocommerce'),
          'class' => array('form-row-first'),
        ),
        (isset($_POST['reg_password']) ? esc_attr($_POST['reg_password']) : '')
      );
      woocommerce_form_field(
        'reg_password2',
        array(
          'type' => 'password',
          'required' => true,
          'label' => __('Password (Repeat)', 'woocommerce'),
          'class' => array('form-row-last'),
        ),
        (isset($_POST['reg_password2']) ? esc_attr($_POST['reg_password2']) : '')
      );
      woocommerce_form_field(
        'reg_first_name',
        array(
          'type' => 'text',
          'required' => true,
          'label' => __('First Name', 'woocommerce'),
          'class' => array('form-row-first'),
        ),
        (isset($_POST['reg_first_name']) ? esc_attr($_POST['reg_first_name']) : '')
      );
      woocommerce_form_field(
        'reg_last_name',
        array(
          'type' => 'text',
          'required' => true,
          'label' => __('Last Name', 'woocommerce'),
          'class' => array('form-row-last'),
        ),
        (isset($_POST['reg_last_name']) ? esc_attr($_POST['reg_last_name']) : '')
      );
      woocommerce_form_field(
        'reg_country',
        array(
          'type' => 'country',
          'required' => true,
          'label' => __('Country', 'woocommerce'),
          'class' => array('form-row-first'),
        ),
        (isset($_POST['reg_country']) ? esc_attr($_POST['reg_country']) : '')
      );
      woocommerce_form_field(
        'reg_state',
        array(
          'type' => 'select',
          'required' => true,
          'label' => __('State', 'woocommerce'),
          'options' => array('' => __('State', 'woocommerce')),
          'class' => array('form-row-last'),
        ),
        (isset($_POST['reg_state']) ? esc_attr($_POST['reg_state']) : '')
      );
      woocommerce_form_field(
        'reg_city',
        array(
          'type' => 'select',
          'required' => true,
          'label' => __('City', 'woocommerce'),
          'options' => array('' => __('City', 'woocommerce')),
          'class' => array('form-row-first'),
        ),
        (isset($_POST['reg_city']) ? esc_attr($_POST['reg_city']) : '')
      );

      woocommerce_form_field(
        'reg_postcode',
        array(
          'type' => 'text',
          'required' => true,
          'label' => __('Postal Code', 'woocommerce'),
          'class' => array('form-row-last'),
          'input_class' => array('postcode-input'),
        ),
        (isset($_POST['reg_postcode']) ? esc_attr($_POST['reg_postcode']) : '')
      );
      woocommerce_form_field(
        'reg_address_1',
        array(
          'type' => 'text',
          'required' => true,
          'label' => __('Address', 'woocommerce'),
          'class' => array('form-row-first'),
        ),
        (isset($_POST['reg_address_1']) ? esc_attr($_POST['reg_address_1']) : '')
      );
      woocommerce_form_field(
        'reg_address_2',
        array(
          'type' => 'text',
          'required' => false,
          'label' => __('Address 2', 'woocommerce'),
          'class' => array('form-row-last'),
        ),
        (isset($_POST['reg_address_2']) ? esc_attr($_POST['reg_address_2']) : '')
      );
      woocommerce_form_field(
        'reg_phone',
        array(
          'type' => 'number',
          'required' => true,
          'label' => __('Phone', 'woocommerce'),
          'class' => array('form-row-first'),
          'input_class' => array('phone-input'),
        )
      );
      woocommerce_form_field(
        'reg_tckn',
        array(
          'type' => 'number',
          'required' => false,
          'label' => __('TCKN', 'woocommerce'),
          'class' => array('form-row-last'),
          'input_class' => array('tckn-input'),
        ),
        (isset($_POST['reg_tckn']) ? esc_attr($_POST['reg_tckn']) : '')
      );

      echo '<div class="clearfix"></div>';
    }


    add_action('wp_ajax_register_state_change', 'register_state_change');
    add_action('wp_ajax_nopriv_register_state_change', 'register_state_change');
    function register_state_change() {
      if (!check_ajax_referer('sutore-register-request', 'nonce')) {
        $response = array('status' => true, 'message' => "No naughty business please");
        echo json_encode($response);
        exit();
      }
      $ilceler = [
        "TR01" => [
          "Aladağ",
          "Ceyhan",
          "Çukurova",
          "Feke",
          "İmamoğlu",
          "Karaisalı",
          "Karataş",
          "Kozan",
          "Pozantı",
          "Saimbeyli",
          "Sarıçam",
          "Seyhan",
          "Tufanbeyli",
          "Yumurtalık",
          "Yüreğir",
        ],
        "TR02" => [
          "Besni",
          "Çelikhan",
          "Gerger",
          "Gölbaşı",
          "Kahta",
          "Merkez",
          "Samsat",
          "Sincik",
          "Tut",
        ],
        "TR03" => [
          "Başmakçı",
          "Bayat",
          "Bolvadin",
          "Çay",
          "Çobanlar",
          "Dazkırı",
          "Dinar",
          "Emirdağ",
          "Evciler",
          "Hocalar",
          "İhsaniye",
          "İscehisar",
          "Kızılören",
          "Merkez",
          "Sandıklı",
          "Sinanpaşa",
          "Sultandağı",
          "Şuhut",
        ],
        "TR04" => [
          "Diyadin",
          "Doğubayazıt",
          "Eleşkirt",
          "Hamur",
          "Merkez",
          "Patnos",
          "Taşlıçay",
          "Tutak",
        ],
        "TR05" => [
          "Göynücek",
          "Gümüşhacıköy",
          "Hamamözü",
          "Merkez",
          "Merzifon",
          "Suluova",
          "Taşova",
        ],
        "TR06" => [
          "Akyurt",
          "Altındağ",
          "Ayaş",
          "Bala",
          "Beypazarı",
          "Çamlıdere",
          "Çankaya",
          "Çubuk",
          "Elmadağ",
          "Etimesgut",
          "Evren",
          "Gölbaşı",
          "Güdül",
          "Haymana",
          "Kahramankazan",
          "Kalecik",
          "Keçiören",
          "Kızılcahamam",
          "Mamak",
          "Nallıhan",
          "Polatlı",
          "Pursaklar",
          "Sincan",
          "Şereflikoçhisar",
          "Yenimahalle",
        ],
        "TR07" => [
          "Akseki",
          "Aksu",
          "Alanya",
          "Demre",
          "Döşemealtı",
          "Elmalı",
          "Finike",
          "Gazipaşa",
          "Gündoğmuş",
          "İbradı",
          "Kaş",
          "Kemer",
          "Kepez",
          "Konyaaltı",
          "Korkuteli",
          "Kumluca",
          "Manavgat",
          "Muratpaşa",
          "Serik",
        ],
        "TR08" => [
          "Ardanuç",
          "Arhavi",
          "Borçka",
          "Hopa",
          "Kemalpaşa",
          "Merkez",
          "Murgul",
          "Şavşat",
          "Yusufeli",
        ],
        "TR09" => [
          "Bozdoğan",
          "Buharkent",
          "Çine",
          "Didim",
          "Efeler",
          "Germencik",
          "İncirliova",
          "Karacasu",
          "Karpuzlu",
          "Koçarlı",
          "Köşk",
          "Kuşadası",
          "Kuyucak",
          "Nazilli",
          "Söke",
          "Sultanhisar",
          "Yenipazar",
        ],
        "TR10" => [
          "Altıeylül",
          "Ayvalık",
          "Balya",
          "Bandırma",
          "Bigadiç",
          "Burhaniye",
          "Dursunbey",
          "Edremit",
          "Erdek",
          "Gömeç",
          "Gönen",
          "Havran",
          "İvrindi",
          "Karesi",
          "Kepsut",
          "Manyas",
          "Marmara",
          "Savaştepe",
          "Sındırgı",
          "Susurluk",
        ],
        "TR11" => [
          "Bozüyük",
          "Gölpazarı",
          "İnhisar",
          "Merkez",
          "Osmaneli",
          "Pazaryeri",
          "Söğüt",
          "Yenipazar",
        ],
        "TR12" => [
          "Adaklı",
          "Genç",
          "Karlıova",
          "Kiğı",
          "Merkez",
          "Solhan",
          "Yayladere",
          "Yedisu",
        ],
        "TR13" => [
          "Adilcevaz",
          "Ahlat",
          "Güroymak",
          "Hizan",
          "Merkez",
          "Mutki",
          "Tatvan",
        ],
        "TR14" => [
          "Dörtdivan",
          "Gerede",
          "Göynük",
          "Kıbrıscık",
          "Mengen",
          "Merkez",
          "Mudurnu",
          "Seben",
          "Yeniçağa",
        ],
        "TR15" => [
          "Ağlasun",
          "Altınyayla",
          "Bucak",
          "Çavdır",
          "Çeltikçi",
          "Gölhisar",
          "Karamanlı",
          "Kemer",
          "Merkez",
          "Tefenni",
          "Yeşilova",
        ],
        "TR16" => [
          "Büyükorhan",
          "Gemlik",
          "Gürsu",
          "Harmancık",
          "İnegöl",
          "İznik",
          "Karacabey",
          "Keles",
          "Kestel",
          "Mudanya",
          "Mustafakemalpaşa",
          "Nilüfer",
          "Orhaneli",
          "Orhangazi",
          "Osmangazi",
          "Yenişehir",
          "Yıldırım",
        ],
        "TR17" => [
          "Ayvacık",
          "Bayramiç",
          "Biga",
          "Bozcaada",
          "Çan",
          "Eceabat",
          "Ezine",
          "Gelibolu",
          "Gökçeada",
          "Lapseki",
          "Merkez",
          "Yenice",
        ],
        "TR18" => [
          "Atkaracalar",
          "Bayramören",
          "Çerkeş",
          "Eldivan",
          "Ilgaz",
          "Kızılırmak",
          "Korgun",
          "Kurşunlu",
          "Merkez",
          "Orta",
          "Şabanözü",
          "Yapraklı",
        ],
        "TR19" => [
          "Alaca",
          "Bayat",
          "Boğazkale",
          "Dodurga",
          "İskilip",
          "Kargı",
          "Laçin",
          "Mecitözü",
          "Merkez",
          "Oğuzlar",
          "Ortaköy",
          "Osmancık",
          "Sungurlu",
          "Uğurludağ",
        ],
        "TR20" => [
          "Acıpayam",
          "Babadağ",
          "Baklan",
          "Bekilli",
          "Beyağaç",
          "Bozkurt",
          "Buldan",
          "Çal",
          "Çameli",
          "Çardak",
          "Çivril",
          "Güney",
          "Honaz",
          "Kale",
          "Merkezefendi",
          "Pamukkale",
          "Sarayköy",
          "Serinhisar",
          "Tavas",
        ],
        "TR21" => [
          "Bağlar",
          "Bismil",
          "Çermik",
          "Çınar",
          "Çüngüş",
          "Dicle",
          "Eğil",
          "Ergani",
          "Hani",
          "Hazro",
          "Kayapınar",
          "Kocaköy",
          "Kulp",
          "Lice",
          "Silvan",
          "Sur",
          "Yenişehir",
        ],
        "TR22" => [
          "Enez",
          "Havsa",
          "İpsala",
          "Keşan",
          "Lalapaşa",
          "Meriç",
          "Merkez",
          "Süloğlu",
          "Uzunköprü",
        ],
        "TR23" => [
          "Ağın",
          "Alacakaya",
          "Arıcak",
          "Baskil",
          "Karakoçan",
          "Keban",
          "Kovancılar",
          "Maden",
          "Merkez",
          "Palu",
          "Sivrice",
        ],
        "TR24" => [
          "Çayırlı",
          "İliç",
          "Kemah",
          "Kemaliye",
          "Merkez",
          "Otlukbeli",
          "Refahiye",
          "Tercan",
          "Üzümlü",
        ],
        "TR25" => [
          "Aşkale",
          "Aziziye",
          "Çat",
          "Hınıs",
          "Horasan",
          "İspir",
          "Karaçoban",
          "Karayazı",
          "Köprüköy",
          "Narman",
          "Oltu",
          "Olur",
          "Palandöken",
          "Pasinler",
          "Pazaryolu",
          "Şenkaya",
          "Tekman",
          "Tortum",
          "Uzundere",
          "Yakutiye",
        ],
        "TR26" => [
          "Alpu",
          "Beylikova",
          "Çifteler",
          "Günyüzü",
          "Han",
          "İnönü",
          "Mahmudiye",
          "Mihalgazi",
          "Mihalıççık",
          "Odunpazarı",
          "Sarıcakaya",
          "Seyitgazi",
          "Sivrihisar",
          "Tepebaşı",
        ],
        "TR27" => [
          "Araban",
          "İslahiye",
          "Karkamış",
          "Nizip",
          "Nurdağı",
          "Oğuzeli",
          "Şahinbey",
          "Şehitkamil",
          "Yavuzeli",
        ],
        "TR28" => [
          "Alucra",
          "Bulancak",
          "Çamoluk",
          "Çanakçı",
          "Dereli",
          "Doğankent",
          "Espiye",
          "Eynesil",
          "Görele",
          "Güce",
          "Keşap",
          "Merkez",
          "Piraziz",
          "Şebinkarahisar",
          "Tirebolu",
          "Yağlıdere",
        ],
        "TR29" => [
          "Kelkit",
          "Köse",
          "Kürtün",
          "Merkez",
          "Şiran",
          "Torul",
        ],
        "TR30" => [
          "Çukurca",
          "Derecik",
          "Merkez",
          "Şemdinli",
          "Yüksekova",
        ],
        "TR31" => [
          "Altınözü",
          "Antakya",
          "Arsuz",
          "Belen",
          "Defne",
          "Dörtyol",
          "Erzin",
          "Hassa",
          "İskenderun",
          "Kırıkhan",
          "Kumlu",
          "Payas",
          "Reyhanlı",
          "Samandağ",
          "Yayladağı",
        ],
        "TR32" => [
          "Aksu",
          "Atabey",
          "Eğirdir",
          "Gelendost",
          "Gönen",
          "Keçiborlu",
          "Merkez",
          "Senirkent",
          "Sütçüler",
          "Şarkikaraağaç",
          "Uluborlu",
          "Yalvaç",
          "Yenişarbademli",
        ],
        "TR33" => [
          "Akdeniz",
          "Anamur",
          "Aydıncık",
          "Bozyazı",
          "Çamlıyayla",
          "Erdemli",
          "Gülnar",
          "Mezitli",
          "Mut",
          "Silifke",
          "Tarsus",
          "Toroslar",
          "Yenişehir",
        ],
        "TR34" => [
          "Adalar",
          "Arnavutköy",
          "Ataşehir",
          "Avcılar",
          "Bağcılar",
          "Bahçelievler",
          "Bakırköy",
          "Başakşehir",
          "Bayrampaşa",
          "Beşiktaş",
          "Beykoz",
          "Beylikdüzü",
          "Beyoğlu",
          "Büyükçekmece",
          "Çatalca",
          "Çekmeköy",
          "Esenler",
          "Esenyurt",
          "Eyüpsultan",
          "Fatih",
          "Gaziosmanpaşa",
          "Güngören",
          "Kadıköy",
          "Kağıthane",
          "Kartal",
          "Küçükçekmece",
          "Maltepe",
          "Pendik",
          "Sancaktepe",
          "Sarıyer",
          "Silivri",
          "Sultanbeyli",
          "Sultangazi",
          "Şile",
          "Şişli",
          "Tuzla",
          "Ümraniye",
          "Üsküdar",
          "Zeytinburnu",
        ],
        "TR35" => [
          "Aliağa",
          "Balçova",
          "Bayındır",
          "Bayraklı",
          "Bergama",
          "Beydağ",
          "Bornova",
          "Buca",
          "Çeşme",
          "Çiğli",
          "Dikili",
          "Foça",
          "Gaziemir",
          "Güzelbahçe",
          "Karabağlar",
          "Karaburun",
          "Karşıyaka",
          "Kemalpaşa",
          "Kınık",
          "Kiraz",
          "Konak",
          "Menderes",
          "Menemen",
          "Narlıdere",
          "Ödemiş",
          "Seferihisar",
          "Selçuk",
          "Tire",
          "Torbalı",
          "Urla",
        ],
        "TR36" => [
          "Akyaka",
          "Arpaçay",
          "Digor",
          "Kağızman",
          "Merkez",
          "Sarıkamış",
          "Selim",
          "Susuz",
        ],
        "TR37" => [
          "Abana",
          "Ağlı",
          "Araç",
          "Azdavay",
          "Bozkurt",
          "Cide",
          "Çatalzeytin",
          "Daday",
          "Devrekani",
          "Doğanyurt",
          "Hanönü",
          "İhsangazi",
          "İnebolu",
          "Küre",
          "Merkez",
          "Pınarbaşı",
          "Seydiler",
          "Şenpazar",
          "Taşköprü",
          "Tosya",
        ],
        "TR38" => [
          "Akkışla",
          "Bünyan",
          "Develi",
          "Felahiye",
          "Hacılar",
          "İncesu",
          "Kocasinan",
          "Melikgazi",
          "Özvatan",
          "Pınarbaşı",
          "Sarıoğlan",
          "Sarız",
          "Talas",
          "Tomarza",
          "Yahyalı",
          "Yeşilhisar",
        ],
        "TR39" => [
          "Babaeski",
          "Demirköy",
          "Kofçaz",
          "Lüleburgaz",
          "Merkez",
          "Pehlivanköy",
          "Pınarhisar",
          "Vize",
        ],
        "TR40" => [
          "Akçakent",
          "Akpınar",
          "Boztepe",
          "Çiçekdağı",
          "Kaman",
          "Merkez",
          "Mucur",
        ],
        "TR41" => [
          "Başiskele",
          "Çayırova",
          "Darıca",
          "Derince",
          "Dilovası",
          "Gebze",
          "Gölcük",
          "İzmit",
          "Kandıra",
          "Karamürsel",
          "Kartepe",
          "Körfez",
        ],
        "TR42" => [
          "Ahırlı",
          "Akören",
          "Akşehir",
          "Altınekin",
          "Beyşehir",
          "Bozkır",
          "Cihanbeyli",
          "Çeltik",
          "Çumra",
          "Derbent",
          "Derebucak",
          "Doğanhisar",
          "Emirgazi",
          "Ereğli",
          "Güneysınır",
          "Hadim",
          "Halkapınar",
          "Hüyük",
          "Ilgın",
          "Kadınhanı",
          "Karapınar",
          "Karatay",
          "Kulu",
          "Meram",
          "Sarayönü",
          "Selçuklu",
          "Seydişehir",
          "Taşkent",
          "Tuzlukçu",
          "Yalıhüyük",
          "Yunak",
        ],
        "TR43" => [
          "Altıntaş",
          "Aslanapa",
          "Çavdarhisar",
          "Domaniç",
          "Dumlupınar",
          "Emet",
          "Gediz",
          "Hisarcık",
          "Merkez",
          "Pazarlar",
          "Simav",
          "Şaphane",
          "Tavşanlı",
        ],
        "TR44" => [
          "Akçadağ",
          "Arapgir",
          "Arguvan",
          "Battalgazi",
          "Darende",
          "Doğanşehir",
          "Doğanyol",
          "Hekimhan",
          "Kale",
          "Kuluncak",
          "Pütürge",
          "Yazıhan",
          "Yeşilyurt",
        ],
        "TR45" => [
          "Ahmetli",
          "Akhisar",
          "Alaşehir",
          "Demirci",
          "Gölmarmara",
          "Gördes",
          "Kırkağaç",
          "Köprübaşı",
          "Kula",
          "Salihli",
          "Sarıgöl",
          "Saruhanlı",
          "Selendi",
          "Soma",
          "Şehzadeler",
          "Turgutlu",
          "Yunusemre",
        ],
        "TR46" => [
          "Afşin",
          "Andırın",
          "Çağlayancerit",
          "Dulkadiroğlu",
          "Ekinözü",
          "Elbistan",
          "Göksun",
          "Nurhak",
          "Onikişubat",
          "Pazarcık",
          "Türkoğlu",
        ],
        "TR47" => [
          "Artuklu",
          "Dargeçit",
          "Derik",
          "Kızıltepe",
          "Mazıdağı",
          "Midyat",
          "Nusaybin",
          "Ömerli",
          "Savur",
          "Yeşilli",
        ],
        "TR48" => [
          "Bodrum",
          "Dalaman",
          "Datça",
          "Fethiye",
          "Kavaklıdere",
          "Köyceğiz",
          "Marmaris",
          "Menteşe",
          "Milas",
          "Ortaca",
          "Seydikemer",
          "Ula",
          "Yatağan",
        ],
        "TR49" => [
          "Bulanık",
          "Hasköy",
          "Korkut",
          "Malazgirt",
          "Merkez",
          "Varto",
        ],
        "TR50" => [
          "Acıgöl",
          "Avanos",
          "Derinkuyu",
          "Gülşehir",
          "Hacıbektaş",
          "Kozaklı",
          "Merkez",
          "Ürgüp",
        ],
        "TR51" => [
          "Altunhisar",
          "Bor",
          "Çamardı",
          "Çiftlik",
          "Merkez",
          "Ulukışla",
        ],
        "TR52" => [
          "Akkuş",
          "Altınordu",
          "Aybastı",
          "Çamaş",
          "Çatalpınar",
          "Çaybaşı",
          "Fatsa",
          "Gölköy",
          "Gülyalı",
          "Gürgentepe",
          "İkizce",
          "Kabadüz",
          "Kabataş",
          "Korgan",
          "Kumru",
          "Mesudiye",
          "Perşembe",
          "Ulubey",
          "Ünye",
        ],
        "TR53" => [
          "Ardeşen",
          "Çamlıhemşin",
          "Çayeli",
          "Derepazarı",
          "Fındıklı",
          "Güneysu",
          "Hemşin",
          "İkizdere",
          "İyidere",
          "Kalkandere",
          "Merkez",
          "Pazar",
        ],
        "TR54" => [
          "Adapazarı",
          "Akyazı",
          "Arifiye",
          "Erenler",
          "Ferizli",
          "Geyve",
          "Hendek",
          "Karapürçek",
          "Karasu",
          "Kaynarca",
          "Kocaali",
          "Pamukova",
          "Sapanca",
          "Serdivan",
          "Söğütlü",
          "Taraklı",
        ],
        "TR55" => [
          "19 Mayıs",
          "Alaçam",
          "Asarcık",
          "Atakum",
          "Ayvacık",
          "Bafra",
          "Canik",
          "Çarşamba",
          "Havza",
          "İlkadım",
          "Kavak",
          "Ladik",
          "Salıpazarı",
          "Tekkeköy",
          "Terme",
          "Vezirköprü",
          "Yakakent",
        ],
        "TR56" => [
          "Baykan",
          "Eruh",
          "Kurtalan",
          "Merkez",
          "Pervari",
          "Şirvan",
          "Tillo",
        ],
        "TR57" => [
          "Ayancık",
          "Boyabat",
          "Dikmen",
          "Durağan",
          "Erfelek",
          "Gerze",
          "Merkez",
          "Saraydüzü",
          "Türkeli",
        ],
        "TR58" => [
          "Akıncılar",
          "Altınyayla",
          "Divriği",
          "Doğanşar",
          "Gemerek",
          "Gölova",
          "Gürün",
          "Hafik",
          "İmranlı",
          "Kangal",
          "Koyulhisar",
          "Merkez",
          "Suşehri",
          "Şarkışla",
          "Ulaş",
          "Yıldızeli",
          "Zara",
        ],
        "TR59" => [
          "Çerkezköy",
          "Çorlu",
          "Ergene",
          "Hayrabolu",
          "Kapaklı",
          "Malkara",
          "Marmaraereğlisi",
          "Muratlı",
          "Saray",
          "Süleymanpaşa",
          "Şarköy",
        ],
        "TR60" => [
          "Almus",
          "Artova",
          "Başçiftlik",
          "Erbaa",
          "Merkez",
          "Niksar",
          "Pazar",
          "Reşadiye",
          "Sulusaray",
          "Turhal",
          "Yeşilyurt",
          "Zile",
        ],
        "TR61" => [
          "Akçaabat",
          "Araklı",
          "Arsin",
          "Beşikdüzü",
          "Çarşıbaşı",
          "Çaykara",
          "Dernekpazarı",
          "Düzköy",
          "Hayrat",
          "Köprübaşı",
          "Maçka",
          "Of",
          "Ortahisar",
          "Sürmene",
          "Şalpazarı",
          "Tonya",
          "Vakfıkebir",
          "Yomra",
        ],
        "TR62" => [
          "Çemişgezek",
          "Hozat",
          "Mazgirt",
          "Merkez",
          "Nazımiye",
          "Ovacık",
          "Pertek",
          "Pülümür",
        ],
        "TR63" => [
          "Akçakale",
          "Birecik",
          "Bozova",
          "Ceylanpınar",
          "Eyyübiye",
          "Halfeti",
          "Haliliye",
          "Harran",
          "Hilvan",
          "Karaköprü",
          "Siverek",
          "Suruç",
          "Viranşehir",
        ],
        "TR64" => [
          "Banaz",
          "Eşme",
          "Karahallı",
          "Merkez",
          "Sivaslı",
          "Ulubey",
        ],
        "TR65" => [
          "Bahçesaray",
          "Başkale",
          "Çaldıran",
          "Çatak",
          "Edremit",
          "Erciş",
          "Gevaş",
          "Gürpınar",
          "İpekyolu",
          "Muradiye",
          "Özalp",
          "Saray",
          "Tuşba",
        ],
        "TR66" => [
          "Akdağmadeni",
          "Aydıncık",
          "Boğazlıyan",
          "Çandır",
          "Çayıralan",
          "Çekerek",
          "Kadışehri",
          "Merkez",
          "Saraykent",
          "Sarıkaya",
          "Sorgun",
          "Şefaatli",
          "Yenifakılı",
          "Yerköy",
        ],
        "TR67" => [
          "Alaplı",
          "Çaycuma",
          "Devrek",
          "Ereğli",
          "Gökçebey",
          "Kilimli",
          "Kozlu",
          "Merkez",
        ],
        "TR68" => [
          "Ağaçören",
          "Eskil",
          "Gülağaç",
          "Güzelyurt",
          "Merkez",
          "Ortaköy",
          "Sarıyahşi",
          "Sultanhanı",
        ],
        "TR69" => [
          "Aydıntepe",
          "Demirözü",
          "Merkez",
        ],
        "TR70" => [
          "Ayrancı",
          "Başyayla",
          "Ermenek",
          "Kazımkarabekir",
          "Merkez",
          "Sarıveliler",
        ],
        "TR71" => [
          "Bahşılı",
          "Balışeyh",
          "Çelebi",
          "Delice",
          "Karakeçili",
          "Keskin",
          "Merkez",
          "Sulakyurt",
          "Yahşihan",
        ],
        "TR72" => [
          "Beşiri",
          "Gercüş",
          "Hasankeyf",
          "Kozluk",
          "Merkez",
          "Sason",
        ],
        "TR73" => [
          "Beytüşşebap",
          "Cizre",
          "Güçlükonak",
          "İdil",
          "Merkez",
          "Silopi",
          "Uludere",
        ],
        "TR74" => [
          "Amasra",
          "Kurucaşile",
          "Merkez",
          "Ulus",
        ],
        "TR75" => [
          "Çıldır",
          "Damal",
          "Göle",
          "Hanak",
          "Merkez",
          "Posof",
        ],
        "TR76" => [
          "Aralık",
          "Karakoyunlu",
          "Merkez",
          "Tuzluca",
        ],
        "TR77" => [
          "Altınova",
          "Armutlu",
          "Çınarcık",
          "Çiftlikköy",
          "Merkez",
          "Termal",
        ],
        "TR78" => [
          "Eflani",
          "Eskipazar",
          "Merkez",
          "Ovacık",
          "Safranbolu",
          "Yenice",
        ],
        "TR79" => [
          "Elbeyli",
          "Merkez",
          "Musabeyli",
          "Polateli",
        ],
        "TR80" => [
          "Bahçe",
          "Düziçi",
          "Hasanbeyli",
          "Kadirli",
          "Merkez",
          "Sumbas",
          "Toprakkale",
        ],
        "TR81" => [
          "Akçakoca",
          "Cumayeri",
          "Çilimli",
          "Gölyaka",
          "Gümüşova",
          "Kaynaşlı",
          "Merkez",
          "Yığılca",
        ],
      ];
      $customer = new WC_Customer( get_current_user_id() );
      if($customer){
        $billing_city = $customer->get_billing_city();
      }else{
        $billing_city = false;
      }

      $response = array('status' => true, 'cities' => $ilceler, "default" => $billing_city);
      echo json_encode($response);
      exit;
    }

    add_action('wp_ajax_register_country_change', 'register_country_change');
    add_action('wp_ajax_nopriv_register_country_change', 'register_country_change');
    function register_country_change() {
      if (!check_ajax_referer('sutore-register-request', 'nonce')) {
        $response = array('status' => true, 'message' => "No naughty business please");
        echo json_encode($response);
        exit();
      }
      $country = $_POST['country'];
      $states = WC()->countries->get_states($country);
      if ($states) {
        $response = array('status' => 1, 'states' => $states);
      } else {
        $response = array('status' => 0);
      }
      echo json_encode($response);
      exit;
    }


    add_action('wp_ajax_custom_vendor_form_submit', 'custom_vendor_form_submit');
    add_action('wp_ajax_nopriv_custom_vendor_form_submit', 'custom_vendor_form_submit');
    function custom_vendor_form_submit()
    {
      if (!check_ajax_referer('sutore-register-request', 'nonce')) {
        $response = array('status' => "1", 'message' => "No naughty business please");
        echo json_encode($response);
        wp_die();
      }
      global $wpdb;
      parse_str($_POST['formData'], $form);
      $form['reg_first_name'] = ucwords($form['reg_first_name']);
      $form['reg_last_name'] = ucwords($form['reg_last_name']);

      if (empty($form["reg_password"])) {
        $response["message"] = __('Parola alanı boş bırakılamaz.', 'vendors-list');
      }

      if (empty($form["reg_password2"])) {
        $response["message"] = __('Parola alanı boş bırakılamaz.', 'vendors-list');
      }

      //wp_send_json(array("status" => false, "message" => strlen($form["reg_phone"])));

      if (empty($form["reg_email"])) {
        wp_send_json(array("status" => false, "message" => __('E-mail alanı boş bırakılamaz.', 'vendors-list')));
      }elseif (!filter_var($form["reg_email"], FILTER_VALIDATE_EMAIL)) {
        wp_send_json(array("status" => false, "message" => __('Girmiş olduğunuz e-mail adresi uygun formatta değildir.', 'vendors-list')));
      }elseif (empty($form["reg_username"])) {
        wp_send_json(array("status" => false, "message" => __('Kullanıcı adı alanı boş bırakılamaz.', 'vendors-list')));
      }elseif (empty($form["reg_password"])) {
        wp_send_json(array("status" => false, "message" => __('Parola alanı boş bırakılamaz.', 'vendors-list')));
      }elseif (empty($form["reg_password2"])) {
        wp_send_json(array("status" => false, "message" => __('Parola tekrar alanı boş bırakılamaz.', 'vendors-list')));
      }elseif ($form['reg_password'] != $form['reg_password2']) {
        wp_send_json(array("status" => false, "message" => __('Girilen parolalar eşleşmiyor', 'vendors-list')));
      }elseif(empty($form["reg_first_name"])){
        wp_send_json(array("status" => false, "message" => __('Lütfen isminizi giriniz.', 'vendors-list')));
      }elseif(empty($form["reg_last_name"])){
        wp_send_json(array("status" => false, "message" => __('Lütfen soyadınızı giriniz.', 'vendors-list')));
      }elseif(empty($form["reg_phone"])){
        wp_send_json(array("status" => false, "message" => __('Telefon numarası alanı boş bırakılamaz.', 'vendors-list')));
      }elseif(!phone_is_used($form["reg_phone"])){
        wp_send_json(array("status" => false, "message" => __('Girmiş olduğunuz telefon numarası sistemde kullanılmaktadır.', 'vendors-list')));
      }elseif(empty($form["reg_postcode"])){
        wp_send_json(array("status" => false, "message" => __('Posta kodu alanı boş bırakılamaz.', 'vendors-list')));
      }elseif(empty($form["reg_address_1"])){
        wp_send_json(array("status" => false, "message" => __('Lütfen geçerli bir adres giriniz.', 'vendors-list')));
      }elseif(empty($form["reg_country"])){
        wp_send_json(array("status" => false, "message" => __('Lütfen bir ülke seçiniz.', 'vendors-list')));
      }elseif(isset($form["reg_tckn"]) && !empty($form["reg_tckn"]) && !isTcValid($form["reg_tckn"])){
        wp_send_json(array("status" => false, "message" => __('TC kimlik numarası geçersiz.', 'vendors-list')));
      }elseif(!empty($form["reg_country"]) && $form["reg_country"] == "TR"){
        if(strlen($form["reg_phone"]) != "10"){
          wp_send_json(array("status" => false, "message" => __('Lütfen telefon numaranızı başında 0 olmadan 10 haneli formatta girin.', 'vendors-list')));
        }
        if(!isTelValid($form["reg_phone"])){
          wp_send_json(array("status" => false, "message" => __('Telefon numarası geçersiz.', 'vendors-list')));
        }
        if(!phone_is_used($form["reg_phone"])){
          wp_send_json(array("status" => false, "message" => __('Girmiş olduğunuz telefon numarası sistemde kullanılmaktadır.', 'vendors-list')));
        }
        if(!isset($form["reg_state"]) || empty($form["reg_state"])){
          wp_send_json(array("status" => false, "message" => __('Lütfen il seçin.', 'vendors-list')));
        }
        if(!isset($form["reg_city"]) || empty($form["reg_city"])){
          wp_send_json(array("status" => false, "message" => __('Lütfen ilçe seçin.', 'vendors-list')));
        }
        if(!isPostCodeValid($form["reg_postcode"])){
          wp_send_json(array("status" => false, "message" => __('Posta kodu geçersiz.', 'vendors-list')));
        }

      }


      $args = array(
        'first_name' => $form['reg_first_name'],
        'last_name' => $form['reg_last_name'],
        'show_admin_bar_front' => 'false',
      );
      $user_id = wc_create_new_customer($form['reg_email'], $form['reg_username'], $form['reg_password'], $args);
      if (is_wp_error($user_id)) {
        $error_code = array_key_first($user_id->errors);
        //$response["message"] = $user_id->errors[$error_code][0];
        wp_send_json(array("status" => false, "message" => $user_id->errors[$error_code][0]));
      } else {

        update_user_meta($user_id, 'billing_first_name', sanitize_text_field(ucwords($form['reg_first_name'])));
        update_user_meta($user_id, 'billing_last_name', sanitize_text_field(ucwords($form['reg_last_name'])));
        update_user_meta($user_id, 'billing_address_1', sanitize_text_field($form['reg_address_1']));
        if (!empty($form['reg_address_2'])) {update_user_meta($user_id, 'billing_address_2', sanitize_text_field($form['reg_address_2']));}
        if (!empty($form['reg_tckn'])) {update_user_meta($user_id, 'billing_tckno', sanitize_text_field($form['reg_tckn']));}
        update_user_meta($user_id, 'billing_postcode', sanitize_text_field($form['reg_postcode']));
        update_user_meta($user_id, 'billing_phone_account', sanitize_text_field($form['reg_phone']));
        //update_user_meta($user_id, 'billing_email', $form['reg_email']);
        update_user_meta($user_id, 'billing_country', sanitize_text_field($form['reg_country']));

        if($form["reg_country"] == "TR") {
          if (!empty($form['reg_city'])) {
            update_user_meta($user_id, 'billing_city', sanitize_text_field($form['reg_city']));
          } else {
            update_user_meta($user_id, 'billing_city', sanitize_text_field($form['reg_city']));
          }
          if (!empty($form['reg_state'])) {
            update_user_meta($user_id, 'billing_state', sanitize_text_field($form['reg_state']));
          } else {
            update_user_meta($user_id, 'billing_state', sanitize_text_field($form['reg_state']));
          }
        }

        if(isset($form["reg_campaing"]) && !empty($form["reg_campaing"])){
          update_user_meta($user_id, 'segmentify_campaing', 1);
        }
        //                $response["status"] = true;
        //                $response["url"] = home_url("hesabim");
        //$response["message"] = sprintf(__('Üyelik başarıyla oluşturuldu. Giriş sayfasına yönlendiriliyorsunuz...', 'vendors-list'));
        setcookie("registered", "okis", time() + (86400 * 30), "/"); // 86400 = 1 day
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        wp_send_json(array("status" => true, "url" => home_url("hesabim"), "message" => __('Üyelik başarıyla oluşturuldu. Giriş sayfasına yönlendiriliyorsunuz...', 'vendors-list')));
      }


      //    echo json_encode($response);
      //    wp_die();
    }


    function phone_is_used($phone){
      $users = get_users(
        array(
          'meta_key' => "billing_phone_account",
          'meta_value' => $phone,
          'number' => 1,
          'fields' => "ids",
        )
      );

      if(!empty($users)){
        return false;
      }else{
        return true;
      }

    }


    add_shortcode('sutore_register', 'sutore_register_page_print');
    function sutore_register_page_print(){ ?>
      <?php do_action('woocommerce_before_customer_login_form'); ?>
      <section id="register_page" class="register_page fl-labels">
        <div class="container">
          <div class="tabbed-content sp-register mt-5">
            <h2 class="uppercase text-center"><?php esc_html_e( 'Personal Details', 'sutore' ); ?></h2>
            <div class="tab-panels">
              <div class="panel active entry-content" id="tab_musteri">
                <div class="register_structure">
                  <form method="post" id="registerForm" class="woocommerce-form woocommerce-form-register register" <?php do_action('woocommerce_register_form_tag');?> >
                    <?php do_action('woocommerce_register_form_start');?>
                    <?php //do_action('woocommerce_register_form');?>
                    <div id="payment" class="woocommerce-privacy-policy-text">
                      <p class="form-row  custom-checkboxes">
                            <label style="font-weight: normal; font-size: inherit !important;">
                              <input id="woocCheck" type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="reg_campaing" name="reg_campaing" value="1"><span class="checkmark" style="top:0px;"></span> <?php esc_attr_e("I wish to be contacted by email to receive sutore campaigns.", 'woocommerce');?>
                            </label>
                          </p>
                    </div>
                    <div class="woocommerce-privacy-policy-text sutore-privacy-style"><p><?php echo sprintf(__("You confirm that you accept sutore's <a href='%s'>Privacy Policy</a> and <a href='%s'>Terms of Use</a>.", 'woocommerce'),get_permalink( get_page_by_path( 'gizlilik-ve-cerez-politikasi' ) ), get_permalink( get_page_by_path( 'uyelik-sozlesmesi' ) ));?></p></div>
                    <div class="item btn-default">
                      <button id="registerButton" type="submit" class="woocommerce-Button woocommerce-button button woocommerce-form-register__submit" name="register" value="<?php esc_attr_e('Register', 'woocommerce');?>"><?php esc_html_e('Register', 'woocommerce');?></button>
                    </div>
                    <?php do_action('woocommerce_register_form_end');?>
                    <span class="ajax_message"></span>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
      <?php do_action('woocommerce_after_customer_login_form');?>
    <?php } ?>
