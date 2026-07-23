<?php

function find_in_returns($sku, $term_id){
  $ids = get_users(array('role' => 'merchant' ,'fields' => 'ID', "meta_key" => "merchant_level", "meta_value" => 6));

  $args = array(
    "post_type" => array("product_variation"),
    "post_status" => array("publish","draft","expired","pending"),
    "posts_per_page" => 1,
    "author__in" => $ids,
    'meta_query' => array(
      'relation' => 'AND',
      array(
        'key' => 'merchant_product',
        'value' => 1,
      ),
      array(
        'key'     => 'urun_kodu',
        'value'   => sanitize_text_field($sku),
        'compare' => '=',
      ),
      array(
        'key'     => 'term_id',
        'value'   => sanitize_text_field($term_id),
        'compare' => '=',
      ),
    ),
  );

  $the_query = new WP_Query( $args );


  if ( $the_query->have_posts() ) {
     return true;
  }else{
    return false;
  }
}

function is_product_in_order($product_id){
  global $wpdb;

  $product_id = (int) $product_id;
  $cache_key = 'sutore_product_in_order_' . $product_id;
  $cached_result = wp_cache_get($cache_key, 'sutore_order_check');
  if ($cached_result !== false) {
    return (bool) $cached_result;
  }

  // Fast path: item meta tablosundan direkt eşleşme ara.
  $fast_result = (bool) $wpdb->get_var($wpdb->prepare(
    "SELECT oi.order_item_id
     FROM {$wpdb->prefix}woocommerce_order_items oi
     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
       ON oi.order_item_id = oim.order_item_id
     INNER JOIN {$wpdb->posts} p
       ON oi.order_id = p.ID
     WHERE p.post_type = 'shop_order'
       AND p.post_status IN ('wc-pending','wc-failed')
       AND oim.meta_key IN ('_product_id','_variation_id')
       AND oim.meta_value = %d
     LIMIT 1",
    $product_id
  ));

  if ($fast_result) {
    wp_cache_set($cache_key, true, 'sutore_order_check', 60);
    return true;
  }

  // Fallback: toplu -1 yerine sayfalı kontrol yap.
  $page = 1;
  $page_size = 100;
  do {
    $orders = wc_get_orders(array(
      'limit'  => $page_size,
      'paged'  => $page,
      'type'   => 'shop_order',
      'status' => array('wc-pending','wc-failed'),
    ));

    foreach ($orders as $order){
      $items = $order->get_items();
      foreach ($items as $item) {
        if ($item->get_product_id() == $product_id || $item->get_variation_id() == $product_id) {
          wp_cache_set($cache_key, true, 'sutore_order_check', 60);
          return true;
        }
      }
    }

    $page++;
  } while (count($orders) === $page_size);

  wp_cache_set($cache_key, false, 'sutore_order_check', 60);
  return false;
}

if (!function_exists('sutore_merchant_system_find_matching_variation_id')) {
  function sutore_merchant_system_find_matching_variation_id($product_id, $attributes) {
    if (function_exists('find_matching_product_variation_id')) {
      return (int) find_matching_product_variation_id($product_id, $attributes);
    }

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) {
      return 0;
    }

    $data_store = WC_Data_Store::load('product');
    return (int) $data_store->find_matching_product_variation($product, (array) $attributes);
  }
}
if (!function_exists('sutore_merchant_system_send_sms')) {
  function sutore_merchant_system_send_sms($phone, $message) {
    $phone = trim(str_replace("+90", "", (string) $phone));
    if ($phone === '') {
      return false;
    }

    netgsm_send_sms($phone, $message);
    return true;
  }
}
function sutore_merchant_system($merchant_product_id,$action, $args = null){
  global $wpdb;
  $lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
  $product = wc_get_product($merchant_product_id);
  if (!$product) {
    return false;
  }

  $parent_id = get_post_meta($merchant_product_id,"child_of",true);
  $sutore_facet_fp_before = sutore_filter_lookup_fingerprint((int) $parent_id);
  $parent = wc_get_product($parent_id);
  $term_id = get_post_meta($merchant_product_id,"term_id",true);
  $merchant_id = (int) get_post_field("post_author",$merchant_product_id);
  $merchant_level = $merchant_id ? get_user_meta($merchant_id, "merchant_level", true) : 0;
  $merchant = $merchant_id ? get_user_by("ID",$merchant_id) : false;
  $merchant_role = ($merchant && !empty($merchant->roles)) ? $merchant->roles[0] : '';
  $merchant_phone = $merchant_id ? get_user_meta($merchant_id, "billing_phone_account", true) : '';
  $user = wp_get_current_user();
  $user_role = !empty($user->roles) ? $user->roles[0] : '';
  $order_id = get_post_meta($product->get_id(), "product_sold_order_id", true);
  $order = wc_get_order($order_id);
  $term = get_term_by("id",$term_id,"pa_beden-numara");
  $on_sale = $term ? wc_get_product(sutore_merchant_system_find_matching_variation_id($parent_id, array("attribute_pa_beden-numara" => $term->slug))) : false;
  $min_awaiting = wc_get_product(get_min_price_vendor_product($parent_id,$term_id,0));
  $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
  $guvence_bedeli =  (int) get_option("guvence_bedeli",true);
  $price = $product->get_price();
  $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));
  $insuranceFee = $basePrice * $guvence_bedeli / 100;

  $terms = get_the_terms ( $product->get_id(), 'product_cat' );
  $term_array = array();

  if($terms){
    foreach ($terms as $cat_term){
      $term_array[] = $cat_term->term_id;
    }
  }else{
    $term_array[] = 1;
  }

  $product_price = (int) $basePrice;
  $product_name = $product->get_name();

  if($merchant_level == 2 || $merchant_level == 3 ||$merchant_level == 4 || $merchant_level == 5 || $merchant_level == 7 || $user_role == "administrator" || $user_role == "shop_manager" ){
    $status = "publish";
    $quantity = 1;
    $stock_status = "instock";
  }else{
    $status = "pending";
    $quantity = 0;
    $stock_status = "outofstock";
  }

  if($action == "add" && !empty($args["price"])){

    if($args["price"] % 25 != 0){
      $current_user = wp_get_current_user();
      netgsm_send_sms("5543889207", $current_user->user_login. " ".$product->get_id()." ID numaralı üründe izin verilmeyen fiyatlandırma işlemi denedi.");
      wp_send_json(array("status" => false, "message" => "Belirlenen fiyat 25 ve katları olmalıdır!"));
      wp_die();
    }else if(is_product_in_order($product->get_id())){
      wp_send_json(array("status" => false, "message" => "Bir hata oluştu. Lütfen daha sonra tekrar deneyin."));
      wp_die();
    }
    //print_r("Fiyat güncellendi.");

    //wp_send_json(array("status" => false, "message" => $merchant_level));
    $insuranceFee = $args["price"] * $guvence_bedeli / 100;
    $product->set_price( $args["price"] + $hizmet_bedeli + $insuranceFee );
    $product->set_regular_price( $args["price"] + $hizmet_bedeli + $insuranceFee );
    $product->set_sale_price( $args["price"] + $hizmet_bedeli + $insuranceFee );
    $product->save();
    if(get_post_status($product->get_id()) == "publish" || get_post_status($product->get_id()) == "draft" || get_post_status($product->get_id()) == "expired"){
      $status = "publish";
      $quantity = 1;
      $stock_status = "instock";
    }else if(get_post_status($product->get_id()) == "not-sale" ){
      $status = "not-sale";
      $quantity = 0;
      $stock_status = "outofstock";
    }
  }

  if($product) {
    $product_status[] = (int)get_post_meta($product->get_id(), "no_box", true);
    $product_status[] = (int)get_post_meta($product->get_id(), "damaged", true);
    $product_status[] = (int)get_post_meta($product->get_id(), "missing", true);
    $product_status[] = (int)get_post_meta($product->get_id(), "damaged_product", true);
    $product_status[] = (int)get_post_meta($product->get_id(), "tried_product", true);
  }

  if($min_awaiting) {
    $min_awaiting_status[] = (int)get_post_meta($min_awaiting->get_id(), "no_box", true);
    $min_awaiting_status[] = (int)get_post_meta($min_awaiting->get_id(), "damaged", true);
    $min_awaiting_status[] = (int)get_post_meta($min_awaiting->get_id(), "missing", true);
    $min_awaiting_status[] = (int)get_post_meta($min_awaiting->get_id(), "damaged_product", true);
    $min_awaiting_status[] = (int)get_post_meta($min_awaiting->get_id(), "tried_product", true);
  }

  if($on_sale) {
    $on_sale_status[] = (int)get_post_meta($on_sale->get_id(), "no_box", true);
    $on_sale_status[] = (int)get_post_meta($on_sale->get_id(), "damaged", true);
    $on_sale_status[] = (int)get_post_meta($on_sale->get_id(), "missing", true);
    $on_sale_status[] = (int)get_post_meta($on_sale->get_id(), "damaged_product", true);
    $on_sale_status[] = (int)get_post_meta($on_sale->get_id(), "tried_product", true);
  }


  //    echo "Beden: ".$term->slug."<br>";
  //    if($on_sale) {
  //        echo "Satıştaki: " . $on_sale->get_id() . "<br>";
  //    }
  //    if($min_awaiting) {
  //        echo "Sıradaki En Ucuz: " . $min_awaiting->get_id() . "<br>";
  //    }
  //    echo "Satıcı Rolü: ".$merchant_role."<br>";
  //    echo "Satıcı Seviyesi: ".$merchant_level."<br>";

  if($min_awaiting) {
    //error_log("Sıradaki En Ucuz: " . $min_awaiting->get_id());
  }
  if($on_sale) {
    //error_log("Satıştaki: " . $on_sale->get_id());
  }

  switch ($action) {
    case "delete" :
    if(is_product_in_order($product->get_id())){
      wp_send_json(array("status" => false, "message" => "Bir hata oluştu. Lütfen daha sonra tekrar deneyin."));
      wp_die();
    }

    if($on_sale) {
      if ($product->get_id() == $on_sale->get_id()) {
        if ($min_awaiting) {
          $min_awaiting->set_status("publish");
          $min_awaiting->set_stock_quantity(1);
          $min_awaiting->set_stock_status('instock');
          $min_awaiting->set_menu_order($min_awaiting->get_menu_order());
          $min_awaiting->save();
          $wpdb->delete($lookup_table, ['product_id' => $min_awaiting->get_id()], ['%d']);
          $wpdb->insert($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $min_awaiting->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));
        }
      }
    }
    $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
    $product->delete(true);
    break;
    case "not-sale" :
    //print_r("Satıştan kaldır");
    if($on_sale) {
      if ($product->get_id() == $on_sale->get_id()) {
        if ($min_awaiting) {
          $min_awaiting->set_status("publish");
          $min_awaiting->set_stock_quantity(1);
          $min_awaiting->set_stock_status('instock');
          $min_awaiting->set_menu_order($min_awaiting->get_menu_order());
          $min_awaiting->save();
          $wpdb->delete($lookup_table, ['product_id' => $min_awaiting->get_id()], ['%d']);
          $wpdb->insert($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $min_awaiting->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));
        }
      }
    }
    delete_post_meta($product->get_id(),"expire_date");
    $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
    $product->set_status("not-sale");
    $product->set_stock_quantity(0);
    $product->set_stock_status('outofstock');
    $product->set_menu_order(0);
    $product->save();
    if(empty($args["expired"])) {
      if ($order) {
        netgsm_send_sms(str_replace("+90", "", $order->get_billing_phone()), "$order_id numaralı sutore.com siparişinizdeki " . fix_variation_title(str_replace("&#8211;", "", get_the_title($product->get_id()))) . " ürününüz için diğer satıcılardan temin seçeneklerini değerlendiriyoruz. Size sorunsuz bir hizmet sunabilmek adına var gücümüzle çalışmaya devam ediyoruz, anlayışınız için teşekkür ederiz.");
      }
      ask_to_merchant($parent_id, $term_id, $product->get_name(), ($product->get_price() - $hizmet_bedeli) / 1.10);
    }
    break;
    case "chargeback" :
    if($on_sale) {
      if ($product->get_id() == $on_sale->get_id()) {
        if ($min_awaiting) {
          $min_awaiting->set_status("publish");
          $min_awaiting->set_stock_quantity(1);
          $min_awaiting->set_stock_status('instock');
          $min_awaiting->set_menu_order($min_awaiting->get_menu_order());
          $min_awaiting->save();
          $wpdb->delete($lookup_table, ['product_id' => $min_awaiting->get_id()], ['%d']);
          $wpdb->insert($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $min_awaiting->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));
        }
      }
    }
    delete_post_meta($product->get_id(),"expire_date");
    $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
    $product->set_status("chargeback");
    $product->set_stock_quantity(0);
    $product->set_stock_status('outofstock');
    $product->set_menu_order(0);
    $product->save();
    break;
    case "split" :
    if ($order_id) {
      delete_post_meta($product->get_id(), "product_sold_order_id");
      delete_post_meta($product->get_id(), "product_cargo_expired");
      delete_post_meta($product->get_id(), "product_confirm_expired");
      delete_post_meta($product->get_id(), "product_confirm_expire");
      delete_post_meta($product->get_id(), "product_confirm_expire_48");
      delete_post_meta($product->get_id(), "product_confirm_expire_72");
      delete_post_meta($product->get_id(), "product_cargo_expired");
      delete_post_meta($product->get_id(), "expire_date");
      $order = wc_get_order($order_id);
      foreach ($order->get_items() as $item_id => $item) {
        $exproduct = $item->get_product();
        if ($exproduct->get_id() == $product->get_id()) {
          $order->remove_item($item_id);
          $exproduct->set_status("not-sale");
          $exproduct->set_stock_quantity(0);
          $exproduct->set_stock_status('outofstock');
          delete_post_meta($exproduct->get_id(), "merchant_iban");
          delete_post_meta($exproduct->get_id(), "merchant_name");
          delete_post_meta($exproduct->get_id(), "merchant_phone");
          delete_post_meta($exproduct->get_id(), "merchant_tc");
          delete_post_meta($exproduct->get_id(), "merchant_email");
          delete_post_meta($exproduct->get_id(), "merchant_city");
          delete_post_meta($exproduct->get_id(), "merchant_state");
          delete_post_meta($exproduct->get_id(), "product_sold");
          delete_post_meta($exproduct->get_id(), "product_sold_order_id");
          $wpdb->delete($lookup_table, ['product_id' => $exproduct->get_id()], ['%d']);
          $exproduct->save();
        }
      }
      $order->calculate_totals();
      $order->save();
      //print_r("Siparişten Ayrıldı");
    } else {
      return false;
    }
    break;
    case "confirm-payment" :
    $product->set_status("sold");
    $product->save();

    $time = current_time('mysql');
    //wp_update_post(array ('ID' => $product->get_id(), 'post_date' => $time, 'post_date_gmt' => get_gmt_from_date( $time )));
    //wp_update_post(array ('ID' => $order_id, 'post_date' => $time, 'post_date_gmt' => get_gmt_from_date( $time )));
    update_post_meta($product->get_id(), "product_confirm_expire", current_time("timestamp") + 86400);
    update_post_meta($product->get_id(), "product_sold_date", current_time("timestamp"));

    $title = fix_variation_title(str_replace("&#8211;", "", get_the_title($product->get_id())));
    $price = $basePrice;
    sutore_merchant_system_send_sms($merchant_phone, $title . " ürününüz " . $price . " TL'ye satılmıştır. 24 saat içerisinde satışınızı onaylamanız gerekmektedir.");

    if(get_post_meta($order_id,"sutore_shipment_type",true) == "international"){
      sutore_merchant_system_send_sms($merchant_phone, "UYARI: ".$title . " yurt dışına satılmıştır. Ürününüzü (basılı ve tüm bilgiler okunabilecek şekilde) ilk alım faturasıyla veya sipariş ekran görüntüsüyle birlikte merkezimize göndermeniz gerekmektedir.");
    }else if(get_post_meta($order_id,"sutore_shipment_type",true) == "express"){
      sutore_merchant_system_send_sms($merchant_phone, "UYARI: Express kargo yöntemiyle satılmış olan ".$title . " ürününüzü kargo ile göndermeyiniz. Kurye planlanabilmesi için bizimle iletişime geçmeniz gerekmektedir.");
      //$numbers = explode("|",get_option( "express_satis_bildirim", true));
      $number = get_option( "express_satis_bildirim", true);
      //foreach($numbers as $number) {
        netgsm_send_sms(str_replace("+90", "", $number), "UYARI: Express kargo yöntemiyle satılmış olan ".$title . " ürününüzü kargo ile göndermeyiniz. Kurye planlanabilmesi için bizimle iletişime geçmeniz gerekmektedir.");
      //}
    }

    break;
    case "sold" :
    $sold_lock_key = 'sutore_sold_lock_' . $product->get_id();
    if (get_transient($sold_lock_key)) {
      return false;
    }
    set_transient($sold_lock_key, 1, 15);
    if (!empty($args["order_id"])) {
      //print_r("Satıldı");
      $order = wc_get_order($args["order_id"]);
      if (!empty($args["manuel"]) && $args["manuel"] == true) {
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->save();
        $orderID = $order->get_id();
        $billing_phone  = $order->get_billing_phone();
        netgsm_send_sms(str_replace("+90", "", $billing_phone), "$orderID numaralı sutore.com siparişinizdeki " . fix_variation_title(str_replace("&#8211;", "", get_the_title($product->get_id()))) . " başka bir satıcıdan temin edilmiştir. Ürününüzün tahmini teslimat tarihinde size ulaşması için süreci takip etmeye devam ediyoruz.");
        //sutore_merchant_system_send_sms($merchant_phone, fix_variation_title(str_replace("&#8211;","",$product_name)) . " $product_price TL'ye satılmıştır. Ürününüzün satışını 24 saat içerisinde satıcı paneli üzerinden onaylamanız gerekmektedir." . current_time("timestamp"));
        ///get_user_meta($merchant_id, "billing_phone_account", true)
      }else if(!empty($args["swap_merchant"]) && $args["swap_merchant"] == true) {
        $order->add_product($product, 1);
        $order->calculate_totals();
        $order->save();
        if (function_exists('coupon_calculate_fix')) {
            coupon_calculate_fix($order);
        }
      }
      ///////// Satıcı bilgilerini siparişe kaydet
      //delete_post_meta($product->get_id(), "expire_date");
      delete_post_meta($product->get_id(), "product_expire");
      delete_post_meta($product->get_id(), "product_confirm_expire");
      delete_post_meta($product->get_id(), "product_cargo_expire");
      delete_post_meta($product->get_id(), "product_confirm_notice");
      delete_post_meta($product->get_id(), "product_cargo_notice");

      if ($merchant) {
        update_post_meta($product->get_id(), "merchant_iban", get_user_meta($merchant_id, "account_iban", true));
        update_post_meta($product->get_id(), "merchant_name", get_user_meta($merchant_id, "account_name", true) . " " . get_user_meta($merchant_id, "account_lastname", true));
        update_post_meta($product->get_id(), "merchant_phone", get_user_meta($merchant_id, "account_phone", true));
        update_post_meta($product->get_id(), "merchant_tc", get_user_meta($merchant_id, "account_tckno", true));
        update_post_meta($product->get_id(), "merchant_email", get_user_meta($merchant_id, "account_email", true));
        update_post_meta($product->get_id(), "merchant_city", get_user_meta($merchant_id, "account_city", true));
        update_post_meta($product->get_id(), "merchant_state", get_user_meta($merchant_id, "account_state", true));
      }
      update_post_meta($product->get_id(), "product_sold", 1);
      update_post_meta($product->get_id(), "product_sold_order_id", $args["order_id"]);
      update_post_meta($product->get_id(), "product_confirm_expire", current_time("timestamp") + 86400);
      $time = current_time('mysql');
      //                wp_update_post(array ('ID' => $product->get_id(), 'post_date' => $time, 'post_date_gmt' => get_gmt_from_date( $time )));
      //                wp_update_post(array ('ID' => $args["order_id"], 'post_date' => $time, 'post_date_gmt' => get_gmt_from_date( $time )));
      $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
      if (!empty($args["awaiting_payment"])) {
        $product->set_status("payment");
      }else{
        delete_post_meta($product->get_id(), "expire_date");
        update_post_meta($product->get_id(), "product_sold_date", current_time("timestamp"));
        $product->set_status("sold");
      }
      $product->set_stock_quantity(0);
      $product->set_stock_status("outofstock");
      $product->set_menu_order(0);
      $product->save();
      ///// Satılan ürün satıştaki ürünse
      if($on_sale && $product->get_id() == $on_sale->get_id()){
        $on_sale = null;
      }
      if(empty($on_sale)) {
        if ($min_awaiting) {
          $min_awaiting->set_status("publish");
          $min_awaiting->set_stock_quantity(1);
          $min_awaiting->set_stock_status('instock');
          $min_awaiting->set_menu_order($min_awaiting->get_menu_order());
          $min_awaiting->save();
          $wpdb->delete($lookup_table, ['product_id' => $min_awaiting->get_id()], ['%d']);
          $wpdb->insert($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $min_awaiting->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));
        }

      }

      $title = fix_variation_title(str_replace("&#8211;", "", get_the_title($product->get_id())));
      $price = $basePrice;
      if (empty($args["awaiting_payment"])) {
        $shipment_order_id = !empty($args["order_id"]) ? $args["order_id"] : $order_id;
        //error_log("Satıldı: " . $title. " - ".$price);
        //netgsm_send_sms(str_replace("+90", "", get_option( "satis_bildirim", true)), $title . " isimli ürün " . $price . " TL'ye satıldı. Sipariş ID'si: " . $args["order_id"]);
        sutore_merchant_system_send_sms($merchant_phone, $title . " ürününüz " . $price . " TL'ye satılmıştır. 24 saat içerisinde satışınızı onaylamanız gerekmektedir.");

        if(!empty($shipment_order_id) && get_post_meta($shipment_order_id,"sutore_shipment_type",true) == "international"){
          sutore_merchant_system_send_sms($merchant_phone, "UYARI: ".$title . " yurt dışına satılmıştır. Ürününüzü (basılı ve tüm bilgiler okunabilecek şekilde) ilk alım faturasıyla veya sipariş ekran görüntüsüyle birlikte merkezimize göndermeniz gerekmektedir.");
        }


      }else{
        //sutore_merchant_system_send_sms($merchant_phone, $title . " ürününüz " . $price . " TL'ye satıldı. Ürününüzü satıcı paneliniz üzerinden 1 gün içerisinde onaylamanız gerekmektedir. " . current_time("timestamp") . rand(0, 9999));
        //$numbers = explode("|",get_option( "satis_bildirim", true));
        $number = get_option( "satis_bildirim", true);
        //foreach($numbers as $number) {
          netgsm_send_sms(str_replace("+90", "", $number), $title . " ürünü " . $price . " TL'ye satıldı. Sipariş ID'si: " . $args["order_id"] . " Kargo Seçimi: " . get_post_meta($args["order_id"], "sutore_shipment_type", true));
        //}
      }
    }
    delete_transient($sold_lock_key);
    break;
    case "swap-merchant" :
    if ($order_id && isset($args["swap_product_id"]) && !empty($args["swap_product_id"])) {
      sutore_merchant_system($product->get_id(),"split");
      sutore_merchant_system($product->get_id(),"add",array("swap_merchant" => true));
      $swaping_product = wc_get_product($args["swap_product_id"]);
      sutore_merchant_system($swaping_product->get_id(),"sold", array("order_id" => $order_id, "swap_merchant" => true));
    } else {
      return false;
    }
    break;
    case "add" :
    $add_lock_key = 'sutore_add_lock_' . $product->get_id();
    if (get_transient($add_lock_key)) {
      return false;
    }
    set_transient($add_lock_key, 1, 15);
    if ($on_sale) {
      ///// Satılık ürün hasarsızsa varyasyon ürün hasarsızsa
      if (!in_array(1, $on_sale_status) && !in_array(1, $product_status)) {
        //print_r("Satılık ürün hasarsızsa varyasyon ürün hasarsızsa");
        if ($product->get_id() != $on_sale->get_id()) {
          if ((int)$on_sale->get_price() > (int)$product->get_price()) {
            $product->set_status($status);
            $product->set_stock_quantity($quantity);
            $product->set_stock_status($stock_status);
            $product->set_menu_order($product->get_id());
            $product->save();
            if($status == "publish" && in_array($merchant_level, array(1,2,3,4,5,6,7)) || $user_role == "administrator" || $user_role == "shop_manager") {
              $on_sale->set_status("draft");
              $on_sale->set_stock_quantity(0);
              $on_sale->set_stock_status("outofstock");
              $on_sale->save();
              $wpdb->delete($lookup_table, ['product_id' => $on_sale->get_id()], ['%d']);
              $wpdb->insert($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $product->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));
            }
          } elseif ($status != "pending" && (int)$on_sale->get_price() <= (int)$product->get_price()) {
            $product->set_status("draft");
            $product->set_menu_order(0);
            $product->set_stock_quantity(0);
            $product->set_stock_status("outofstock");
            $product->save();
            $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
          }
        }else{
          $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
          $wpdb->insert($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $product->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));
          //print_r("Satılık ürün güncelleniyor.");
          if ($min_awaiting) {
            if (!in_array(1, $min_awaiting_status) && !in_array(1, $product_status) || in_array(1, $min_awaiting_status) && in_array(1, $product_status)) {
              if ((int)$min_awaiting->get_price() < (int)$product->get_price()) {
                $min_awaiting->set_status("publish");
                $min_awaiting->set_stock_quantity(1);
                $min_awaiting->set_stock_status('instock');
                $min_awaiting->set_menu_order($min_awaiting->get_menu_order());
                $min_awaiting->save();
                $wpdb->delete($lookup_table, ['product_id' => $min_awaiting->get_id()], ['%d']);
                $wpdb->insert($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $min_awaiting->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));

                $product->set_status("draft");
                $product->set_menu_order(0);
                $product->set_stock_quantity(0);
                $product->set_stock_status("outofstock");
                $product->save();
                $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
              }
            }
          }
        }
        ///// Satılık ürün hasarlıysa varyasyon ürün hasarlıysa
      } elseif (in_array(1, $on_sale_status) && in_array(1, $product_status)) {
        //print_r("Satılık ürün hasarlıysa varyasyon ürün hasarlıysa");
        if ($product->get_id() != $on_sale->get_id()) {
          if ((int)$on_sale->get_price() > (int)$product->get_price()) {
            $product->set_status($status);
            $product->set_stock_quantity($quantity);
            $product->set_stock_status($stock_status);
            $product->set_menu_order($on_sale->get_menu_order());
            $product->save();
            if($status == "publish") {
              $on_sale->set_status("draft");
              $on_sale->save();
              $wpdb->insert($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $product->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));
              $wpdb->delete($lookup_table, ['product_id' => $on_sale->get_id()], ['%d']);
            }
          } elseif ((int)$on_sale->get_price() <= (int)$product->get_price()) {
            $product->set_status("draft");
            $product->set_menu_order(0);
            $product->set_stock_quantity(0);
            $product->set_stock_status("outofstock");
            $product->save();
          }
        }else{
          //print_r("Satılık ürün güncelleniyor.");
          if ($min_awaiting) {
            if ((int)$min_awaiting->get_price() < (int)$product->get_price()) {
              $min_awaiting->set_status("publish");
              $min_awaiting->set_stock_quantity(1);
              $min_awaiting->set_stock_status('instock');
              $min_awaiting->set_menu_order($min_awaiting->get_menu_order());
              $min_awaiting->save();
              $wpdb->delete($lookup_table, ['product_id' => $min_awaiting->get_id()], ['%d']);
              $wpdb->insert($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $min_awaiting->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));


              $product->set_status("draft");
              $product->set_menu_order(0);
              $product->set_stock_quantity(0);
              $product->set_stock_status("outofstock");
              $product->save();
              $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
            }
          }
        }
        ///// Varyasyon hasarlıysa satılık ürün hasarsızsa
      } elseif (!in_array(1, $on_sale_status) && in_array(1, $product_status)) {
        //print_r("Varyasyon hasarlıysa satılık ürün hasarsızsa");
        $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
        $product->set_status("draft");
        $product->set_stock_quantity(0);
        $product->set_stock_status("outofstock");
        $product->set_menu_order(0);
        $product->save();
        ///// Varyasyon hasarsızsa satılık ürün hasarlıysa
      } elseif (in_array(1, $on_sale_status) && !in_array(1, $product_status)) {
        //print_r("Varyasyon hasarsızsa satılık ürün hasarlıysa");
        $product->set_status($status);
        $product->set_stock_quantity($quantity);
        $product->set_stock_status($stock_status);
        $product->set_menu_order($on_sale->get_menu_order());
        $product->save();
        if($status == "publish") {
          $on_sale->set_status("draft");
          $on_sale->set_stock_quantity(0);
          $on_sale->set_stock_status("outofstock");
          $on_sale->save();
          $wpdb->delete($lookup_table, ['product_id' => $on_sale->get_id()], ['%d']);
          $wpdb->insert($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $product->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));
        }
      }
    } else {
      ///// Satışta ürün yok
      //print_r("Satışta ürün yok.");
      $product->set_status($status);
      $product->set_stock_quantity($quantity);
      $product->set_stock_status($stock_status);
      $product->set_menu_order($product->get_menu_order());
      $product->save();
      if($status == "publish"){
        sutore_in_stock($product->get_id());
        $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
        $wpdb->insert($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $product->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));
      }
    }
    ///////////////// Seviye düşükse fiyat ayarlanmıyorsa her koşulda pendinge çek //////////////
    if($user_role == "administrator" ||  $user_role == "shop_manager") {

    }else{
      if (!isset($args["price"]) && $merchant_level == 1 || $merchant_level == 0) {
        $product->set_status("pending");
        $product->set_stock_quantity(0);
        $product->set_stock_status("outofstock");
        $product->set_menu_order(0);
        $product->save();
        $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
      }
    }
    //if(empty($args["swap_merchant"])) {
    update_post_meta($product->get_id(), "expire_date", current_time("timestamp") + 3888000);
    //}
    delete_transient($add_lock_key);
    break;
    case "arrived-to-sutore" :
    if(!in_array(1727,$term_array) || !in_array(1731,$term_array)) {
      $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
      $product->set_status("arrived-to-sutore");
      $product->save();
      if ($order) {
        netgsm_send_sms(str_replace("+90", "", $order->get_billing_phone()), "$order_id numaralı sutore.com siparişinizdeki " . fix_variation_title(str_replace("&#8211;", "", get_the_title($product->get_id()))) . " kontrol merkezimize ulaşmıştır. Ürününüzün orijinalliği doğrulandıktan sonra siparişiniz en kısa sürede size gönderilecektir.");
      }
      sutore_merchant_system_send_sms($merchant_phone, $basePrice. " TL'ye satılmış olan " .fix_variation_title(str_replace("&#8211;", "", get_the_title($product->get_id()))) . " merkezimize ulaşmıştır. Ürününüzü inceliyoruz.");
    }
    break;
    case "verified" :
      if(!in_array(1727,$term_array) || !in_array(1731,$term_array)) {
        $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
        $product->set_status("verified");
        $product->save();
        if ($order) {
          netgsm_send_sms(str_replace("+90", "", $order->get_billing_phone()), "$order_id numaralı sutore.com siparişinizdeki " . fix_variation_title(str_replace("&#8211;", "", get_the_title($product->get_id()))) . " başarıyla doğrulanmıştır. Ürününüzü size gönderiyoruz.");
        }
        sutore_merchant_system_send_sms($merchant_phone, $basePrice. " TL'ye satılmış olan " .fix_variation_title(str_replace("&#8211;", "", get_the_title($product->get_id()))) . " doğrulanmıştır. Ödemenizi işleme alıyoruz.");
      }
      break;
      case "shipped" :
      if(isset($args["shipment_code"]) && !empty($args["shipment_code"])){
        update_post_meta( $product->get_id(), 'sutore_shipment_code', $args["shipment_code"] );
      }
      $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
      $product->set_status("shipped");
      $product->save();
      if ($order) {
        netgsm_send_sms(str_replace("+90","",$order->get_billing_phone()), "$order_id numaralı sutore.com siparişinizdeki ".fix_variation_title(str_replace("&#8211;","",get_the_title($product->get_id())))." ürününüz size gönderilmiştir. Yurtiçi Kargo gönderi takip bağlantısı: http://yurti.ci/".get_post_meta($product->get_id(),"sutore_shipment_code",true));
      }
      break;
      case "paid" :
      $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
      $product->set_status("paid");
      $product->save();
      sutore_merchant_system_send_sms($merchant_phone, $basePrice. " TL'ye satılmış olan " .fix_variation_title(str_replace("&#8211;", "", get_the_title($product->get_id()))) . " için ödemeniz gerçekleştirilmiştir.");
      break;
      case "ready-to-shipping" :
      $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
      $product->set_status("ready-to-shipping");
      $product->save();
      break;
      case "expired" :
      if($on_sale) {
        if ($product->get_id() == $on_sale->get_id()) {
          if ($min_awaiting) {
            $min_awaiting->set_status("publish");
            $min_awaiting->set_stock_quantity(1);
            $min_awaiting->set_stock_status('instock');
            $min_awaiting->set_menu_order($min_awaiting->get_menu_order());
            clean_object_term_cache($min_awaiting->get_id(), get_post_type($min_awaiting->get_id()));
            $min_awaiting->save();
            $wpdb->delete($lookup_table, ['product_id' => $min_awaiting->get_id()], ['%d']);
            $wpdb->replace($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $min_awaiting->get_id(), 'product_or_parent_id' => $parent_id, 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));
          }
        }
      }
      delete_post_meta($product->get_id(),"expire_date");
      $wpdb->delete($lookup_table, ['product_id' => $product->get_id()], ['%d']);
      $product->set_status("expired");
      $product->set_stock_quantity(0);
      $product->set_stock_status('outofstock');
      $product->set_menu_order(0);
      clean_object_term_cache($product->get_id(), get_post_type($product->get_id()));
      $product->save();
      break;
      case "pre-order" :  
        $product->set_status("pre-order");
        delete_post_meta($product->get_id(), "merchant_iban");
        delete_post_meta($product->get_id(), "merchant_name");
        delete_post_meta($product->get_id(), "merchant_phone");
        delete_post_meta($product->get_id(), "merchant_tc");
        delete_post_meta($product->get_id(), "merchant_email");
        delete_post_meta($product->get_id(), "merchant_city");
        delete_post_meta($product->get_id(), "merchant_state");
        $product->save();
      break;
    }

    if ($parent) {
      $parent->save();
    }

    if ($sutore_facet_fp_before !== sutore_filter_lookup_fingerprint((int) $parent_id)) {
      do_action('sutore_filter_clear_cache_manual');
    }

    return;
  }

  function sutore_in_stock( $variation_id ) {

    $parent_id = get_post_meta($variation_id,"child_of", true);
    $term_id = get_post_meta($variation_id,"term_id",true);

    $args = array(
      "post_type" => "beden_talep",
      "post_status" => "publish",
      "posts_per_page" => 1,
      "suppress_filters" => true,
      'meta_query' => array(
        array(
          'key'     => 'term_id',
          'value'   => $term_id,
          'compare' => '=',
        ),
        array(
          'key'     => 'parent_id',
          'value'   => $parent_id,
          'compare' => '=',
        ),
      ),
    );

    global $sitepress;
    $current_lang = apply_filters( 'wpml_current_language', NULL );
    $the_query = new WP_Query( $args );


    if ( $the_query->have_posts() ) {
      while ( $the_query->have_posts() ) {
        $the_query->the_post();
        $post_id = get_the_ID();
        $emails = explode("|",get_the_content(null,false,get_the_ID()));
        //$emails[] = array("tolgahandemir8888@gmail.com");
        $parent_id = get_post_meta($post_id,"parent_id", true)."<br>";
        $product = wc_get_product($variation_id);
        //print_r($product);
        if(isset($product) && !empty($product)) {
          //if ($product->managing_stock() && $product->is_in_stock()) {
          foreach ($emails as $email) {
            if (strpos($email,"#") !== false) {
              $language = explode("#",$email);
              $sitepress->switch_lang($language[1]);
              $email = $language[0];
            }
            //echo $email."<br>";
            $to = $email;
            if(!empty($to)){
              $subject = __("we have good news for you :)", "sutore");
              ob_start();
              include 'emails/product-in-stock.php';
              $body = ob_get_clean();
              $headers = array('Content-Type: text/html; charset=UTF-8');
              wp_mail($to, $subject, $body, $headers);
            }
          }
          wp_delete_post(get_the_ID(), true);
          // }
        }
      }
    }else{
      //echo "Ürün yok";
    }
    $sitepress->switch_lang($current_lang);
  }

  ?>
