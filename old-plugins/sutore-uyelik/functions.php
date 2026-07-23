<?php

function has_order_coupon($order_id) {
  $order = wc_get_order($order_id);
  
  if (!$order) {
      return false;
  }
  
  $coupons = $order->get_coupon_codes();
  return !empty($coupons);
}

// Admin dashboard'a metabox ekleyen fonksiyon
function set_imported_dashboard_metabox() {
  wp_add_dashboard_widget(
    'set_imported_dashboard_metabox',            // Widget ID
    'Imported Products',                   // Widget Başlığı
    'display_set_imported_product_dashboard_metabox'    // Widget içeriğini gösterecek fonksiyon
  );
}
add_action('wp_dashboard_setup', 'set_imported_dashboard_metabox');

// Admin dashboard metabox içeriğini gösteren fonksiyon
function display_set_imported_product_dashboard_metabox() {
  ?>
  <form name="post" action="https://sutore.com/wp-admin/admin-post.php" method="post" id="quick-pressf" class="initial-form hide-if-no-js">
    <div class="textarea-wrap" id="description-wrap">
      <label for="content">Imported Ürünler</label>
      <textarea name="product_data" id="product_data" rows="4" cols="50" style="width:100%"></textarea>
    </div>
    <p class="submit">
      <input type="hidden" name="action" value="set_imported_products">
      <input type="submit" class="button button-primary" value="Uygula">
      <br class="clear">
    </p>
  </form>

  <?php
}
add_action( 'admin_post_set_imported_products', 'sutore_set_imported_products' );

function sutore_set_imported_products() {
  $product_data = sanitize_textarea_field($_POST['product_data']);
  $product_lines = explode("\n", $product_data);

  foreach($product_lines as $variation_id){
    $product = wc_get_product($variation_id);

    if(!$product){
      echo "<p style='color:red;'>$variation_id Bulunamadı!</p>";
    }else{
      if($product->get_parent_id() == 0){
        echo "<p style='color:red;'>Varyasyon olmayan ürüne imported verisi eklenemez.</p>";
      }else{
        update_post_meta($product->get_id(),"imported",1);
        update_post_meta($product->get_id(), "expire_date", current_time("timestamp") + 3888000);
        echo "<p style='color:gren;'>$variation_id id'li ürüne imported verisi eklendi.</p>";
      }
    }
  }
}

// Admin dashboard'a metabox ekleyen fonksiyon
function add_product_dashboard_metabox() {
  wp_add_dashboard_widget(
    'add_product_dashboard_metabox',            // Widget ID
    'Ürün Ekle',                   // Widget Başlığı
    'display_add_product_dashboard_metabox'    // Widget içeriğini gösterecek fonksiyon
  );
}
add_action('wp_dashboard_setup', 'add_product_dashboard_metabox');

// Admin dashboard metabox içeriğini gösteren fonksiyon
function display_add_product_dashboard_metabox() {
  ?>
  <form name="post" action="https://sutore.com/wp-admin/admin-post.php" method="post" id="quick-pressf" class="initial-form hide-if-no-js">
    <div class="textarea-wrap" id="description-wrap">
      <label for="content">Ürünler</label>
      <textarea name="product_data" id="product_data" rows="4" cols="50" style="width:100%"></textarea>
    </div>
    <p class="submit">
      <input type="hidden" name="action" value="bulk_add_basic_product">
      <input type="submit" class="button button-primary" value="Ekle">
      <br class="clear">
    </p>
  </form>

  <?php
}
add_action( 'admin_post_bulk_add_basic_product', 'sutore_bulk_add_basic_product' );

function sutore_bulk_add_basic_product() {
  $product_data = sanitize_textarea_field($_POST['product_data']);

  // Her satırı işlemek için explode kullan
  $product_lines = explode("\n", $product_data);

  foreach($product_lines as $line_data){
    $line = explode("|",$line_data);

    if(wc_get_product_id_by_sku( $line[4] )){
      echo "<p style='color:red;'>{$line[4]} Eklenemedi. SKU Mevcut.</p>";
    }else{

      $args = array(
        'taxonomy'   => "product_cat",
        'hide_empty' => false, // Boş terimleri göster veya gizle
        'slug' => explode(",",$line[9]), // Terim adı içinde belirli bir kelimeyi içeren terimleri al
        'order'      => 'ASC', // Sıralama düzeni (ASC veya DESC)
        'fields' => "ids"
      );

      // WP_Term_Query nesnesini oluştur
      $term_query = new WP_Term_Query($args);
      $category_ids = $term_query->get_terms();


      $args = array(
        'taxonomy'   => "pa_beden-numara",
        'hide_empty' => false, // Boş terimleri göster veya gizle
        'slug' => explode(",",$line[8]), // Terim adı içinde belirli bir kelimeyi içeren terimleri al
        'order'      => 'ASC', // Sıralama düzeni (ASC veya DESC)
        'fields' => "ids"
      );

      // WP_Term_Query nesnesini oluştur
      $term_query = new WP_Term_Query($args);
      $attr_ids = $term_query->get_terms();

      $product = new WC_Product_Variable();
      $product->set_name( $line[0] );
      $product->set_description( $line[2] );
      $product->set_sku( $line[4] );
      $product->set_slug( $line[1] );
      $product->set_short_description( $line[2] );
      $product->set_sold_individually(false);
      $product->set_status( 'draft' );
      $product->set_catalog_visibility( 'visible' );
      $product->set_category_ids( $category_ids );

      $product->save();

      $attribute = new WC_Product_Attribute();
      $attribute->set_id( wc_attribute_taxonomy_id_by_name( 'pa_beden-numara' ) );
      $attribute->set_name( 'pa_beden-numara' );
      $attribute->set_options( $attr_ids ); // color att terms
      $attribute->set_position( 1 );
      $attribute->set_visible( 1 );
      $attribute->set_variation( 1 );
      $attributes[] = $attribute;
      $product->set_attributes( $attributes );

      $product->save();

      update_post_meta($product->get_id(),"cikis_tarihi",$line[5]);
      update_post_meta($product->get_id(),"liste_fiyati",$line[7]);
      update_post_meta($product->get_id(),"aile",$line[3]);
      update_post_meta($product->get_id(),"urun_kodu",$line[4]);
      update_post_meta($product->get_id(),"renk",$line[6]);

      $brand_name = $line[3];
      $brand_terms = get_terms(array(
          'taxonomy' => 'product_brand',
          'hide_empty' => false,
      ));

      foreach ($brand_terms as $term) {
          if (strcasecmp($term->name, $brand_name) === 0) {
              wp_set_object_terms($product->get_id(), [$term->slug, $term->slug."-en"], 'product_brand', true);
              break;
          }
      }

      echo "<p style='color:green;'>{$line[4]} Eklendi.</p>";
    }
  }
  return;
}

// // Add custom order meta data to make it accessible in Order preview template
add_filter( 'woocommerce_admin_order_preview_get_order_details', 'admin_order_preview_add_custom_meta_data', 10, 2 );
function admin_order_preview_add_custom_meta_data( $data, $order ) {

  $key = 1;
  foreach ( $order->get_fees() as $fee_id => $fee ) {
    $data["custom_key_$key"] = $fee->get_name();
    $data["custom_value_$key"] = $fee->get_total();
    $key++;
  }
  // <= Store the value in the data array.

  return $data;
}

// Display custom values in Order preview
add_action( 'woocommerce_admin_order_preview_end', 'custom_display_order_data_in_admin' );
function custom_display_order_data_in_admin(){
  // Call the stored value and display it

  echo '<table cellspacing="0" class="wc-order-preview-table"><tbody>';
  echo '<tr class="wc-order-preview-table__item wc-order-preview-table__item--71469"><td class="wc-order-preview-table__column--product">{{data.custom_key_1}}</td><td class="wc-order-preview-table__column--quantity"></td><td class="wc-order-preview-table__column--total">{{data.custom_value_1}}</td></tr>';
  echo '<tr class="wc-order-preview-table__item wc-order-preview-table__item--71469"><td class="wc-order-preview-table__column--product">{{data.custom_key_2}}</td><td class="wc-order-preview-table__column--quantity"></td><td class="wc-order-preview-table__column--total">{{data.custom_value_2}}</td></tr>';
  echo '</tbody></table>';

}

add_action("check_expired_products","check_expired_merchant_products");
function check_expired_merchant_products(){
  $current_time = current_time("timestamp");
  $paged = 1;
  $batch_size = (int) apply_filters('sutore_expired_cron_batch_size', 300);
  do {
    $cquery = new WP_Query(array(
      'posts_per_page' => $batch_size,
      'paged' => $paged,
      'order' => "ASC",
      'post_type' => array("product","product_variation"),
      'post_status' => array('publish', 'draft'),
      "meta_query" => array(
        "relation" => "AND",
        array(
          "key" => "expire_date",
          "value" => $current_time,
          "compare" => "<",
        )
      )
    ));

    if ($cquery->have_posts()) {
      while ($cquery->have_posts()) {
        $cquery->the_post();
        sutore_merchant_system(get_the_ID(),"expired");
      }
    }
    $paged++;
    wp_reset_postdata();
  } while ($paged <= (int) $cquery->max_num_pages);
}

/**
* When an item is added to the cart, check total cart quantity
*/
function so_21363268_limit_cart_quantity( $valid, $product_id, $quantity ) {

  $max_allowed = 8;
  $current_cart_count = WC()->cart->get_cart_contents_count();

  if( ( $current_cart_count >= $max_allowed ) && $valid ){
    wc_add_notice(  'Tek siparişte maksimum 8 adet ürün satın alabilirsiniz.', 'error' );
    $valid = false;
  }

  return $valid;

}
add_filter( 'woocommerce_add_to_cart_validation', 'so_21363268_limit_cart_quantity', 10, 3 );

function fix_variation_title($variation_name){
  $replaced =  str_replace("⅔"," 2/3",$variation_name);
  $replaced =  str_replace("⅓"," 1/3",$replaced);
  return $replaced;
}

/**
 * Bir parent ürünün wc_product_attributes_lookup tablosundaki in-stock izini döndürür.
 */
function sutore_filter_lookup_fingerprint(int $parent_id): string {
  global $wpdb;
  if ($parent_id <= 0) {
    return '';
  }
  $table = $wpdb->prefix . 'wc_product_attributes_lookup';
  $rows  = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT DISTINCT taxonomy, term_id
       FROM {$table}
       WHERE product_or_parent_id = %d AND in_stock = 1
       ORDER BY taxonomy, term_id",
      $parent_id
    ),
    ARRAY_N
  );
  if (!is_array($rows)) {
    return '';
  }
  return implode('|', array_map(function ($r) {
    return $r[0] . ':' . $r[1];
  }, $rows));
}

function _sutore_maybe_clear_filter_cache(string $fp_before, int $parent_id): void {
  if ($fp_before !== sutore_filter_lookup_fingerprint($parent_id)) {
    do_action('sutore_filter_clear_cache_manual');
  }
}

function add_product_to_system($parent_id, $variation_id, $variation_term, $delete = false, $sold = false, $new = false, $price = false, $expire_duration = 3888000){
  $fp_before = sutore_filter_lookup_fingerprint((int) $parent_id);
  //error_log($parent_id."-".$variation_id."-".$variation_term);
  global $wpdb;
  $lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
  $update_taxonomy = 'pa_beden-numara';
  $get_terms_args = array('taxonomy' => $update_taxonomy, 'fields' => 'ids', 'hide_empty' => false,);


  $update_terms = get_terms($get_terms_args);
  $variation = wc_get_product($variation_id);
  $parent = wc_get_product($parent_id);
  $term = get_term_by("id",$variation_term,"pa_beden-numara");
  $current = wc_get_product(find_matching_product_variation_id($parent_id, array("attribute_pa_beden-numara" => $term->slug)));
  $min_awaiting = wc_get_product(get_min_price_vendor_product($parent_id,$variation_term,0));

  $user = wp_get_current_user();
  $roles = ( array ) $user->roles;
  $merchant_level = get_user_meta(get_post_field( 'post_author', $variation->get_id() ), "merchant_level", true);


  if($delete == true) {
    $variation->delete(true);
    $parent->save();
    wp_update_term_count_now($update_terms, $update_taxonomy);
    if (isset($current) && !empty($current)) {
      if (isset($min_awaiting) && !empty($min_awaiting)) {
        find_product_order($current, $min_awaiting, false, $expire_duration);
      }
    }
    _sutore_maybe_clear_filter_cache($fp_before, (int) $parent_id);
    return;
  }

  if($sold == true) {
    if (isset($min_awaiting) && !empty($min_awaiting)) {
      $min_awaiting->set_status("publish");
      $min_awaiting->set_menu_order($min_awaiting->get_id());
      $min_awaiting->save();
      //update_post_meta( $min_awaiting->get_id(), "expire_date", current_time("timestamp") + 2629743);
      $min_awaiting->set_status("sold");
      $min_awaiting->set_menu_order(0);
      $min_awaiting->save();
      delete_post_meta( $min_awaiting->get_id(), "expire_date");
    }
    $parent->save();
    wp_update_term_count_now($update_terms, $update_taxonomy);
    _sutore_maybe_clear_filter_cache($fp_before, (int) $parent_id);
    return;
  }

  //    if($price == true && get_post_status($variation->get_id()) != "publish") {
  //        return;
  //    }

  if(isset($current) && !empty($current)) {
    if($merchant_level == 2 || $merchant_level == 3 || $merchant_level == 4 || $merchant_level == 5 || $merchant_level == 7 || in_array("administrator",$roles) || $price == true && get_post_status($variation->get_id()) != "pending") {
      //error_log("Mevcut Ürün Hasarı:".get_post_meta($current->get_id(),"no_box",true).get_post_meta($current->get_id(),"damaged",true).get_post_meta($current->get_id(),"missing",true));
      //            $current_product_status[] = (int)get_post_meta($current->get_id(), "no_box", true);
      //            $current_product_status[] = (int)get_post_meta($current->get_id(), "damaged", true);
      //            $current_product_status[] = (int)get_post_meta($current->get_id(), "missing", true);
      //            $current_product_status[] = (int)get_post_meta($current->get_id(), "damaged_product", true);
      //            $current_product_status[] = (int)get_post_meta($current->get_id(), "tried_product", true);

      if ($current->get_id() == $variation->get_id()) {
        if (isset($min_awaiting) && !empty($min_awaiting)) {
          //error_log("Satıştaki ürünle güncellenen ürün aynı.");
          find_product_order($current, $min_awaiting, false, $expire_duration);
          //                    if ((int)$min_awaiting->get_price() < (int)$variation->get_price()) {
          ////                        error_log("Satıştaki ürün güncelleniyor.......");
          ////                        $variation->set_status("draft");
          ////                        $variation->set_menu_order(0);
          ////                        $variation->save();
          ////                        $wpdb->delete('wpnx_wc_product_attributes_lookup', ['product_id' => $variation->get_id()], ['%d']);
          ////                        $min_awaiting->set_status("publish");
          ////                        $min_awaiting->set_stock_quantity(1);
          ////                        $min_awaiting->set_stock_status('instock');
          ////                        $min_awaiting->set_menu_order($min_awaiting->get_menu_order());
          ////                        $min_awaiting->save();
          //                    }
        }
      } else {
        //error_log("Satıştaki ürünle güncellenen ürün aynı değil.");
        find_product_order($current, $variation, false, $expire_duration);
      }
      //
      //            ///// Satılık ürün hasarsızsa varyasyon ürün hasarsızsa
      //            //if((get_post_meta($variation->get_id(),"no_box",true) == 0 || get_post_meta($variation->get_id(),"damaged",true) == 0 || get_post_meta($variation->get_id(),"missing",true) == 0) && (get_post_meta($current->get_id(),"no_box",true) == 0 || get_post_meta($current->get_id(),"damaged",true) == 0 || get_post_meta($current->get_id(),"missing",true) == 0)) {
      //            if (!in_array(1, $current_product_status) && !in_array(1, $variation_product_status)) {
      //                error_log("Satılık ürün hasarsızsa varyasyon ürün hasarsızsa");
      //                if ((int)$current->get_price() > (int)$variation->get_price()) {
      //                    $variation->set_status("publish");
      //                    $variation->set_stock_quantity(1);
      //                    $variation->set_stock_status('instock');
      //                    $variation->set_menu_order($current->get_menu_order());
      //                    $variation->save();
      //                    $current->set_status("draft");
      //                    $wpdb->delete('wpnx_wc_product_attributes_lookup', ['product_id' => $current->get_id()], ['%d']);
      //                    $current->save();
      //                } elseif ((int)$current->get_price() < (int)$variation->get_price()) {
      //                    $variation->set_status("draft");
      //                    $variation->set_menu_order(0);
      //                    $variation->save();
      //                }
      //                ///// Satılık ürün hasarlıysa varyasyon ürün hasarlıysa
      //                //}elseif((get_post_meta($variation->get_id(),"no_box",true) == 1 || get_post_meta($variation->get_id(),"damaged",true) == 1 || get_post_meta($variation->get_id(),"missing",true) == 1) && (get_post_meta($current->get_id(),"no_box",true) == 1 || get_post_meta($current->get_id(),"damaged",true) == 1 || get_post_meta($current->get_id(),"missing",true) == 1)) {
      //            } elseif (in_array(1, $current_product_status) && in_array(1, $variation_product_status)) {
      //                error_log("Satılık ürün hasarlıysa varyasyon ürün hasarlıysa");
      //                if ((int)$current->get_price() > (int)$variation->get_price()) {
      //                    $variation->set_status("publish");
      //                    $variation->set_stock_quantity(1);
      //                    $variation->set_stock_status('instock');
      //                    $wpdb->delete('wpnx_wc_product_attributes_lookup', ['product_id' => $variation->get_id()], ['%d']);
      //                    $variation->set_menu_order($current->get_menu_order());
      //                    $variation->save();
      //                    $current->set_status("draft");
      //                    $current->save();
      //                } elseif ((int)$current->get_price() < (int)$variation->get_price()) {
      //                    $variation->set_status("draft");
      //                    $variation->set_menu_order(0);
      //                    $variation->save();
      //                }
      //                ///// Varyasyon hasarlıysa satılık ürün hasarsızsa
      //                //}elseif((get_post_meta($variation->get_id(),"no_box",true) == 1 || get_post_meta($variation->get_id(),"damaged",true) == 1 || get_post_meta($variation->get_id(),"missing",true) == 1) && (get_post_meta($current->get_id(),"no_box",true) == 0 || get_post_meta($current->get_id(),"damaged",true) == 0 || get_post_meta($current->get_id(),"missing",true) == 0)) {
      //            } elseif (!in_array(1, $current_product_status) && in_array(1, $variation_product_status)) {
      //                error_log("Varyasyon hasarlıysa satılık ürün hasarsızsa");
      //                $variation->set_status("draft");
      //                $variation->set_menu_order(0);
      //                $variation->save();
      //                ///// Varyasyon hasarsızsa satılık ürün hasarlıysa
      //                //}elseif((get_post_meta($variation->get_id(),"no_box",true) == 0 || get_post_meta($variation->get_id(),"damaged",true) == 0 || get_post_meta($variation->get_id(),"missing",true) == 0) && (get_post_meta($current->get_id(),"no_box",true) == 1 || get_post_meta($current->get_id(),"damaged",true) == 1 || get_post_meta($current->get_id(),"missing",true) == 1)) {
      //            } elseif (in_array(1, $current_product_status) && !in_array(1, $variation_product_status)) {
      //                error_log("Varyasyon hasarsızsa satılık ürün hasarlıysa");
      //                $variation->set_status("publish");
      //                $wpdb->delete('wpnx_wc_product_attributes_lookup', ['product_id' => $variation->get_id()], ['%d']);
      //                $variation->set_stock_quantity(1);
      //                $variation->set_stock_status('instock');
      //                $variation->set_menu_order($current->get_menu_order());
      //                $variation->save();
      //                $current->set_status("draft");
      //                $current->save();
      //            }
      //        }else{
      //            error_log("Merchant LVL 1 ise.");
      //            $variation->set_status("pending");
      //            $wpdb->delete('wpnx_wc_product_attributes_lookup', ['product_id' => $variation->get_id()], ['%d']);
      //            $variation->set_stock_quantity(0);
      //            $variation->set_stock_status('outofstock');
      //            $variation->set_menu_order($variation->get_id());
      //            $variation->save();
      //        }
    }
  }else{
    //error_log("Satışta ürün yoksa");
    if($merchant_level == 2 || $merchant_level == 3 || $merchant_level == 4 || $merchant_level == 5 || $merchant_level == 7 ||  in_array("administrator",$roles)){
      $variation->set_status("publish");
      $wpdb->replace($lookup_table, array('is_variation_attribute' => 1, 'in_stock' => 1, 'product_id' =>  $variation->get_id(), 'product_or_parent_id' => $parent->get_id(), 'term_id' => $term->term_id, 'taxonomy' => "pa_beden-numara",));
      $variation->set_stock_quantity(1);
      $variation->set_stock_status('instock');
      update_post_meta( $variation->get_id(), "expire_date", current_time("timestamp") + $expire_duration);
      sutore_in_stock($variation->get_id());
    }else{
      $variation->set_status("pending");
      $wpdb->delete($lookup_table, ['product_id' => $variation->get_id()], ['%d']);
      $variation->set_stock_quantity(0);
      $variation->set_stock_status('outofstock');
      delete_post_meta( $variation->get_id(), "expire_date");
      //update_post_meta( $variation->get_id(), "expire_date", current_time("timestamp") + 3888000);
    }
    $variation->set_menu_order($variation->get_id());
    $variation->save();
  }
  $parent->save();
  //wp_update_post(array("ID" => $parent->get_id()));
  wp_update_term_count_now($update_terms, $update_taxonomy);
  _sutore_maybe_clear_filter_cache($fp_before, (int) $parent_id);

}

function find_product_order($current,$variation,$min_awaiting = false,$expire_duration = 3888000){
  global $wpdb;
  $lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
  $variation_product_status[] = (int) get_post_meta($variation->get_id(),"no_box",true);
  $variation_product_status[] = (int) get_post_meta($variation->get_id(),"damaged",true);
  $variation_product_status[] = (int) get_post_meta($variation->get_id(),"missing",true);
  $variation_product_status[] = (int) get_post_meta($variation->get_id(),"damaged_product",true);
  $variation_product_status[] = (int) get_post_meta($variation->get_id(),"tried_product",true);

  $current_product_status[] = (int)get_post_meta($current->get_id(), "no_box", true);
  $current_product_status[] = (int)get_post_meta($current->get_id(), "damaged", true);
  $current_product_status[] = (int)get_post_meta($current->get_id(), "missing", true);
  $current_product_status[] = (int)get_post_meta($current->get_id(), "damaged_product", true);
  $current_product_status[] = (int)get_post_meta($current->get_id(), "tried_product", true);
  ///// Satılık ürün hasarsızsa varyasyon ürün hasarsızsa
  //if((get_post_meta($variation->get_id(),"no_box",true) == 0 || get_post_meta($variation->get_id(),"damaged",true) == 0 || get_post_meta($variation->get_id(),"missing",true) == 0) && (get_post_meta($current->get_id(),"no_box",true) == 0 || get_post_meta($current->get_id(),"damaged",true) == 0 || get_post_meta($current->get_id(),"missing",true) == 0)) {
  if (!in_array(1, $current_product_status) && !in_array(1, $variation_product_status)) {
    //error_log("Satılık ürün hasarsızsa varyasyon ürün hasarsızsa");
    if ((int)$current->get_price() > (int)$variation->get_price()) {
      $variation->set_status("publish");
      $variation->set_stock_quantity(1);
      $variation->set_stock_status('instock');
      $variation->set_menu_order($current->get_menu_order());
      $variation->save();
      update_post_meta( $variation->get_id(), "expire_date", current_time("timestamp") + $expire_duration);
      $current->set_status("draft");
      $wpdb->delete($lookup_table, ['product_id' => $current->get_id()], ['%d']);
      $current->save();
    } elseif ((int)$current->get_price() < (int)$variation->get_price()) {
      $variation->set_status("draft");
      $variation->set_menu_order(0);
      $variation->save();
      update_post_meta( $variation->get_id(), "expire_date", current_time("timestamp") + $expire_duration);
    }elseif ((int)$current->get_price() == (int)$variation->get_price()) {
      $variation->set_status("draft");
      $variation->set_menu_order(0);
      $variation->save();
      update_post_meta( $variation->get_id(), "expire_date", current_time("timestamp") + $expire_duration);
    }
    ///// Satılık ürün hasarlıysa varyasyon ürün hasarlıysa
    //}elseif((get_post_meta($variation->get_id(),"no_box",true) == 1 || get_post_meta($variation->get_id(),"damaged",true) == 1 || get_post_meta($variation->get_id(),"missing",true) == 1) && (get_post_meta($current->get_id(),"no_box",true) == 1 || get_post_meta($current->get_id(),"damaged",true) == 1 || get_post_meta($current->get_id(),"missing",true) == 1)) {
  } elseif (in_array(1, $current_product_status) && in_array(1, $variation_product_status)) {
    //error_log("Satılık ürün hasarlıysa varyasyon ürün hasarlıysa");
    if ((int)$current->get_price() > (int)$variation->get_price()) {
      $variation->set_status("publish");
      $variation->set_stock_quantity(1);
      $variation->set_stock_status('instock');
      $variation->set_menu_order($current->get_menu_order());
      $variation->save();
      update_post_meta( $variation->get_id(), "expire_date", current_time("timestamp") + $expire_duration);
      $current->set_status("draft");
      $wpdb->delete($lookup_table, ['product_id' => $current->get_id()], ['%d']);
      $current->save();
    } elseif ((int)$current->get_price() < (int)$variation->get_price()) {
      $variation->set_status("draft");
      $variation->set_menu_order(0);
      $variation->save();
      update_post_meta( $variation->get_id(), "expire_date", current_time("timestamp") + $expire_duration);
    }elseif ((int)$current->get_price() == (int)$variation->get_price()) {
      $variation->set_status("draft");
      $variation->set_menu_order(0);
      $variation->save();
      update_post_meta( $variation->get_id(), "expire_date", current_time("timestamp") + $expire_duration);
    }
    ///// Varyasyon hasarlıysa satılık ürün hasarsızsa
    //}elseif((get_post_meta($variation->get_id(),"no_box",true) == 1 || get_post_meta($variation->get_id(),"damaged",true) == 1 || get_post_meta($variation->get_id(),"missing",true) == 1) && (get_post_meta($current->get_id(),"no_box",true) == 0 || get_post_meta($current->get_id(),"damaged",true) == 0 || get_post_meta($current->get_id(),"missing",true) == 0)) {
  } elseif (!in_array(1, $current_product_status) && in_array(1, $variation_product_status)) {
    //error_log("Varyasyon hasarlıysa satılık ürün hasarsızsa");
    $wpdb->delete($lookup_table, ['product_id' => $variation->get_id()], ['%d']);
    $variation->set_status("draft");
    $variation->set_menu_order(0);
    $variation->save();
    update_post_meta( $variation->get_id(), "expire_date", current_time("timestamp") + $expire_duration);
    ///// Varyasyon hasarsızsa satılık ürün hasarlıysa
    //}elseif((get_post_meta($variation->get_id(),"no_box",true) == 0 || get_post_meta($variation->get_id(),"damaged",true) == 0 || get_post_meta($variation->get_id(),"missing",true) == 0) && (get_post_meta($current->get_id(),"no_box",true) == 1 || get_post_meta($current->get_id(),"damaged",true) == 1 || get_post_meta($current->get_id(),"missing",true) == 1)) {
  } elseif (in_array(1, $current_product_status) && !in_array(1, $variation_product_status)) {
    //error_log("Varyasyon hasarsızsa satılık ürün hasarlıysa");
    $variation->set_status("publish");
    $variation->set_stock_quantity(1);
    $variation->set_stock_status('instock');
    $variation->set_menu_order($current->get_menu_order());
    $variation->save();
    update_post_meta( $variation->get_id(), "expire_date", current_time("timestamp") + $expire_duration);
    $wpdb->delete($lookup_table, ['product_id' => $current->get_id()], ['%d']);
    $current->set_status("draft");
    $current->save();
  }
}

//add_filter( 'woocommerce_billing_fields', 'remove_account_billing_phone_and_email_fields', 20, 1 );
function remove_account_billing_phone_and_email_fields( $billing_fields ) {
  // Only on my account 'edit-address'
  if( is_wc_endpoint_url( 'edit-address' ) || is_checkout() ){
    unset($billing_fields['billing_company']);
  }
  return $billing_fields;
}

function get_merchant_commision($user_id){
  $user_level = get_user_meta($user_id,"merchant_level",true);

  if($user_level == 0){
    return 25;
  }elseif($user_level == 1){
    return 20;
  }elseif($user_level == 2){
    return 19;
  }elseif($user_level == 3){
    return 18;
  }elseif($user_level == 4){
    return 17;
  }elseif($user_level == 5){
    return 15;
  }elseif($user_level == 6){
    return 5;
  }elseif($user_level == 7){
    return 12;
  }

}

function kia_woocommerce_order_item_name( $name, $item ){
  $variation_id = $item->get_variation_id();
  $product_id = $item->get_product_id();

  if ( $variation_id ) {
    $product_id = $variation_id;
  }else{
    $product_id = $product_id;
  }

  if(!is_checkout() && is_wc_endpoint_url("view-order") && !empty($variation_id)) {
    $name .= '<label>' . get_vendor_product_status_as_customer($product_id) . ' </label>';
  }

  return $name;
}
add_filter( 'woocommerce_order_item_name', 'kia_woocommerce_order_item_name', 10, 2 );

add_filter( 'woocommerce_order_item_get_formatted_meta_data', 'unset_specific_order_item_meta_data', 10, 2);
function unset_specific_order_item_meta_data($formatted_meta, $item){
  //	if(!is_checkout() && is_wc_endpoint_url("view-order")) {
  $formatted_meta = array();
  //	}
  return $formatted_meta;
}  

function get_vendor_product_status_as_customer($post_id){
  switch (get_post_status($post_id)) {
    case "pending" :
    return __("Pending Confirmation","sutore");
    break;
    case "publish" :
    return __("On Sale","sutore");
    break;
    case "sold" :
    case "pre-order" :
    case "not-sale" :
    case "payment" :
    return __("Pending Seller Confirmation","sutore");
    break;
    case "shipped-to-sutore" :
    $shipment_code = get_post_meta($post_id, "shipment_code", true);
    return __("Shipped to Sutore","sutore"). ' - <a href="https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' . esc_attr($shipment_code) . '" target="_blank" style="color:black; font-weight:bold; font-size:15px;">'.esc_html(__("Track","sutore")).'</a>';
    break;
    case "paid" :  
      $sutore_shipment_code = get_post_meta($post_id, "sutore_shipment_code", true);
      return __("Shipped","sutore").' - <a href="https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' . esc_attr($sutore_shipment_code) . '" target="_blank" style="color:black; font-weight:bold; font-size:15px;">'.esc_html(__("Track","sutore")).'</a>';
    break;
    case "arrived-to-sutore" :
    return __("Arrived at Sutore","sutore");
    break;
    case "ready-to-shipping" :
    return __("Ready to Shipping","sutore");
    break;
    case "verified" :
      return __("Verified","sutore");
      break;
      case "confirmed" :
      return __("Seller Confirmed","sutore");
      break;
      case "shipped" :
      $order_id = get_post_meta($post_id,"product_sold_order_id",true);
      if(isset($order_id)){
        $shipment_type = get_post_meta($order_id, 'sutore_shipment_type', true);
        if($shipment_type == "international"){
          $sutore_shipment_code = get_post_meta($post_id, "sutore_shipment_code", true);
          return __("Shipped to You","sutore").' - <a href="https://www.dhl.com/global-en/home/tracking/tracking-express.html?submit=1&tracking-id=' . esc_attr($sutore_shipment_code) . '" target="_blank" style="color:black; font-weight:bold; font-size:15px;">'.esc_html(__("Track","sutore")).'</a>';
        }else{
          $sutore_shipment_code = get_post_meta($post_id, "sutore_shipment_code", true);
          return __("Shipped","sutore").' - <a href="https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' . esc_attr($sutore_shipment_code) . '" target="_blank" style="color:black; font-weight:bold; font-size:15px;">'.esc_html(__("Track","sutore")).'</a>';
        }
      }
      break;
      case "chargeback" :
      return __("Returned","sutore");
      break;
    }
  }

  function check_duplicate_shipment_code($code){
    $cquery = new WP_Query( array(
      'posts_per_page' => 1,
      'post_type' => "product",
      'author' => get_current_user_id(),
      'post_status' => array('publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash',"shipped-to-sutore"),
      "meta_query" => array(
        "relation" => "AND",
        array(
          "key" => "shipment_code",
          "value" => sanitize_text_field($code),
        )
      )
    ) );

    if ($cquery->have_posts()) {
      return true;
    }else{
      return false;
    }

  }

  function send_warning_to_merchant()
  {
    $paged = 1;
    $batch_size = (int) apply_filters('sutore_merchant_warning_batch_size', 300);
    do {
      $args = array(
        'posts_per_page' => $batch_size,
        'paged' => $paged,
        'post_type' => "product_variation",
        'post_status' => array("sold","confirmed"),
        'meta_query' => array(
          'relation' => 'AND',
          array(
            'key' => 'merchant_product',
            'value' => 1,
          ),
        ),
      );

      $cquery = new WP_Query($args);

      if ($cquery->have_posts()) {
        while ($cquery->have_posts()) {  $cquery->the_post();
        $order_id = null;
        $billing_phone = null;
        $post_author_id = get_post_field( 'post_author', get_the_ID() );
        $order_id = get_post_meta(get_the_ID(),"product_sold_order_id",true);
        if($order_id) {
          $order = wc_get_order($order_id);
          $billing_phone = $order->get_billing_phone();
        }
        $confirm_notice = get_post_meta(get_the_ID(),"product_confirm_notice",true);
        $punishment_notice = get_post_meta(get_the_ID(),"product_confirm_punishment_notice",true);
        $cargo_notice = get_post_meta(get_the_ID(),"product_cargo_notice",true) ? true : false;
        $confirm_expire = get_post_meta(get_the_ID(),"product_confirm_expire",true);
        $cargo_expire = get_post_meta(get_the_ID(),"product_cargo_expire",true);
        $parent_id = get_post_meta(get_the_ID(),"child_of",true);
        $term_id = get_post_meta(get_the_ID(),"term_id",true);
        $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
        $product = wc_get_product(get_the_ID());
        $guvence_bedeli =  $product->get_price() * (int) get_option("guvence_bedeli",true) / 100;


        if(get_post_status(get_the_ID()) == "sold"){
          $timediff = $confirm_expire - current_time("timestamp");
          //error_log($timediff);
          if($timediff <= 0 && $confirm_notice != true && get_post_meta(get_the_ID(), "imported", true) != 1) {
            netgsm_send_sms(str_replace("+90","",$billing_phone), "$order_id numaralı sutore.com siparişinizdeki ".fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüzün satışının onaylanması için satıcıyla iletişime geçilmiştir.");
            netgsm_send_sms(str_replace("+90","",get_user_meta($post_author_id,"billing_phone_account",true)), fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." satışınızı 24 saat içerisinde onaylamadığınız takdirde askıya alınacaktır.");
            update_post_meta(get_the_ID(),"product_confirm_notice",true);
            update_post_meta(get_the_ID(),"product_confirm_expired",true);
            update_post_meta(get_the_ID(),"product_confirm_expire",current_time("timestamp") + 86400);

          }else if($timediff <= 0 && $confirm_notice == true && $punishment_notice != true && get_post_meta(get_the_ID(), "imported", true) != 1) {
            $product = wc_get_product(get_the_ID());
            $product->set_status("not-sale");
            $product->save();
            update_post_meta(get_the_ID(),"product_confirm_punishment_notice",true);
            update_post_meta(get_the_ID(),"product_confirm_expired",true);
            netgsm_send_sms(str_replace("+90","",$billing_phone), "$order_id numaralı sutore.com siparişinizdeki ".fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüz için diğer satıcılardan temin seçeneklerini değerlendiriyoruz. Size sorunsuz bir hizmet sunabilmek adına var gücümüzle çalışmaya devam ediyoruz, anlayışınız için teşekkür ederiz.");
            netgsm_send_sms(str_replace("+90","",get_user_meta($post_author_id,"billing_phone_account",true)), fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." satışınızı belirtilen süre içerisinde onaylamadığınız için askıya alınmıştır.");
            ask_to_merchant($parent_id, $term_id, $product->get_name(), ($product->get_price() - $hizmet_bedeli) / 1.10);
          }

          //                else if($timediff <= 0){
          //                    $product = wc_get_product(get_the_ID());
          //                    $product->set_status("not-sale");
          //                    $product->save();
          //                    update_post_meta(get_the_ID(),"product_confirm_expired",true);
          //                    netgsm_send_sms(str_replace("+90","",$billing_phone), "$order_id numaralı sutore.com siparişinizde yer alan ".fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüz için diğer temin seçeneklerini değerlendiriyoruz. ". rand(100,100000));
          //                    netgsm_send_sms(str_replace("+90","",get_user_meta($post_author_id,"billing_phone_account",true)), fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüzün satışını belirtilen süre içerisinde onaylamadığınızdan dolayı satışınız askıya alınmıştır. ". rand(100,100000));
          //                    ask_to_merchant($parent_id,$term_id,$product->get_name(),$product->get_price() - 99);
          //                }
        }

        if(get_post_status(get_the_ID()) == "confirmed"){
          $cargo_timediff = $cargo_expire - current_time("timestamp");
          if($cargo_timediff <= 86400 && $cargo_notice != true && get_post_meta(get_the_ID(), "imported", true) != 1 ) {
            netgsm_send_sms(str_replace("+90","",get_user_meta($post_author_id,"billing_phone_account",true)), fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüzü 24 saat içerisinde merkezimize göndermeniz gerekmektedir.");
            update_post_meta(get_the_ID(),"product_cargo_notice",true);
          }else if($cargo_timediff <= 0 && !get_post_meta(get_the_ID(),"product_cargo_expired",true) && get_post_meta(get_the_ID(), "imported", true) != 1){
            if(!empty($order_id)) {
              update_post_meta(get_the_ID(), "product_cargo_expired", true);
              netgsm_send_sms(str_replace("+90", "", $billing_phone), "$order_id numaralı sutore.com siparişinizdeki " . fix_variation_title(str_replace("&#8211;", "", get_the_title(get_the_ID()))) . " ürününüzün kontrol merkezimize gönderilmesi için satıcıyla iletişime geçilmiştir.");
              netgsm_send_sms(str_replace("+90", "", get_user_meta($post_author_id, "billing_phone_account", true)), fix_variation_title(str_replace("&#8211;", "", get_the_title(get_the_ID()))) . " ürününüzü merkezimize göndermeniz için ayrılan süre sona ermiştir. Satışınızın askıya alınmasını önlemek için bizimle iletişime geçmeniz gerekmektedir.");
            }
          }
        }


        //            if($confirmExpireTime <= current_time("timestamp") && !get_post_meta(get_the_ID(),"product_confirm_expire_48")){
        //                //echo "expired";
        //                netgsm_send_sms(str_replace("+90","",$billing_phone), "$order_id numaralı sutore.com siparişinizde yer alan ".fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüzün onaylaması için satıcıyla iletişime geçtik. ". rand(100,100000));
        //                netgsm_send_sms(str_replace("+90","",get_user_meta($post_author_id,"billing_phone_account",true)), fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüzü 24 saat içerisinde onaylamamanız durumunda satışınız askıya alınacaktır. ". rand(100,100000));
        //                update_post_meta(get_the_ID(),"product_confirm_expire_48",current_time("timestamp")  + 120);
        //            }else if(get_post_meta(get_the_ID(),"product_confirm_expire_48",true) && get_post_meta(get_the_ID(),"product_confirm_expire_48",true) <= current_time("timestamp") && !get_post_meta(get_the_ID(),"product_confirm_expire_72",true)){
        //                //echo "48 expired";
        //                if(get_post_status(get_the_ID()) == "confirmed") {
        //                    update_post_meta(get_the_ID(),"product_confirm_expire_72",current_time("timestamp")  + 120);
        //                    netgsm_send_sms(str_replace("+90","",get_user_meta($post_author_id,"billing_phone_account",true)), fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüzü 24 saat içerisinde tarafımıza kargolamanız gerekmektedir. ". rand(100,100000));
        //                }elseif(!get_post_meta(get_the_ID(),"product_confirm_expired",true)){
        //                    $product = wc_get_product(get_the_ID());
        //                    $product->set_status("not-sale");
        //                    $product->save();
        //                    //wp_update_post(array("ID" => get_the_ID(),"post_status" => "pending"));
        //                    update_post_meta(get_the_ID(),"product_confirm_expired",true);
        //                    netgsm_send_sms(str_replace("+90","",$billing_phone), "$order_id numaralı sutore.com siparişinizde yer alan ".fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüz için diğer temin seçeneklerini değerlendiriyoruz. ". rand(100,100000));
        //                    netgsm_send_sms(str_replace("+90","",get_user_meta($post_author_id,"billing_phone_account",true)), fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüzün satışını belirtilen süre içerisinde onaylamadığınızdan dolayı satışınız askıya alınmıştır. ". rand(100,100000));
        //
        //                }
        //
        //            }else if(get_post_meta(get_the_ID(),"product_confirm_expire_72",true) && get_post_meta(get_the_ID(),"product_confirm_expire_72",true) <= current_time("timestamp") && !get_post_meta(get_the_ID(),"product_cargo_expired",true)){
        //                //echo "72 expired";
        //                if(get_post_status(get_the_ID()) != "shipped-to-sutore") {
        ////                    $product = wc_get_product(get_the_ID());
        ////                    $product->set_status("not-sale");
        ////                    $product->save();
        //                    update_post_meta(get_the_ID(),"product_cargo_expired",true);
        //                    netgsm_send_sms(str_replace("+90","",$billing_phone), "$order_id numaralı sutore.com siparişinizde yer alan ".fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüzün merkezimize kargolanması için satıcı ile iletişime geçtik. ". rand(100,100000));
        //                    netgsm_send_sms(str_replace("+90","",get_user_meta($post_author_id,"billing_phone_account",true)), fix_variation_title(str_replace("&#8211;","",get_the_title(get_the_ID())))." ürününüzü kargoya vermeniz için gereken süreniz doldu. Lütfen bizimle iletişime geçin. ". rand(100,100000));
        //                }
        //
        //            }
        }
      }
      $paged++;
      wp_reset_postdata();
    } while ($paged <= (int) $cquery->max_num_pages);
  }
  add_action('init', function() {
    if ( ! wp_next_scheduled('sutore_merchant_warning_cron') ) {
      wp_schedule_event(time(), 'twicedaily', 'sutore_merchant_warning_cron');
    }
  });
  add_action('sutore_merchant_warning_cron', 'send_warning_to_merchant');

  function merchant_net_paid($product_id,$user_id){
    $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
    $guvence_bedeli =  (int) get_option("guvence_bedeli",true);

    $product = wc_get_product($product_id);
    $price = $product->get_price();
    $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));

    $merchantComission = get_merchant_commision($user_id);
    $product_price = $basePrice;

    $tax = ($product_price * $merchantComission) / 100;

    $net_paid = $product_price - $tax;

    return $net_paid;

  }

  add_action( 'woocommerce_pre_payment_complete', 'add_merchant_data_to_product_pre_payment', 10, 3 );
  function add_merchant_data_to_product_pre_payment( $order_id ){
    $order = wc_get_order( $order_id );
    foreach ($order->get_items() as $item_id => $item) {
      $sold_product = $item->get_product();
      $merchant_id = get_post_field("post_author",$sold_product->get_id());
      ///////// Satıcı bilgilerini siparişe kaydet
      //delete_post_meta($sold_product->get_id(), "expire_date");
      delete_post_meta($sold_product->get_id(), "product_expire");
      delete_post_meta($sold_product->get_id(), "product_confirm_expire");
      delete_post_meta($sold_product->get_id(), "product_cargo_expire");
      delete_post_meta($sold_product->get_id(), "product_confirm_notice");
      delete_post_meta($sold_product->get_id(), "product_cargo_notice");

      update_post_meta($sold_product->get_id(), "merchant_iban", get_user_meta($merchant_id, "account_iban", true));
      update_post_meta($sold_product->get_id(), "merchant_name", get_user_meta($merchant_id, "account_name", true) . " " . get_user_meta($merchant_id, "account_lastname", true));
      update_post_meta($sold_product->get_id(), "merchant_phone", get_user_meta($merchant_id, "account_phone", true));
      update_post_meta($sold_product->get_id(), "merchant_tc", get_user_meta($merchant_id, "account_tckno", true));
      update_post_meta($sold_product->get_id(), "merchant_email", get_user_meta($merchant_id, "account_email", true));
      update_post_meta($sold_product->get_id(), "merchant_city", get_user_meta($merchant_id, "account_city", true));
      update_post_meta($sold_product->get_id(), "merchant_state", get_user_meta($merchant_id, "account_state", true));
    }
  }



  add_action( 'woocommerce_payment_complete', 'so_payment_complete', 10, 3 );
  function so_payment_complete( $order_id ){
    $order = wc_get_order( $order_id );
    //$products = $order->get_items();
    $billing_name  = $order->get_billing_first_name();
    $billing_lastname  = $order->get_billing_last_name();
    $billing_phone  = $order->get_billing_phone();
    netgsm_send_sms(str_replace("+90","",$billing_phone), "Sayın $billing_name $billing_lastname, $order_id numaralı sutore.com siparişiniz işleme alınmıştır. Bizi tercih ettiğiniz için teşekkür ederiz.");

    foreach ($order->get_items() as $item_id => $item) {
      $sold_product = $item->get_product();
      if (get_post_meta($sold_product->get_id(),"merchant_product",1)) {
        sutore_merchant_system($sold_product->get_id(),"sold", array("order_id" => $order_id, "awaiting_payment" => true));
      }
    }
  }

  add_action( 'woocommerce_thankyou', 'fix_thankyou_notice', 10, 3 );
  function fix_thankyou_notice( $order_id ){
    ?>
    <style>
    .message-container{
      display:none !important;
    }
    </style>
    <?php
  }

  //add_action( 'woocommerce_order_status_shipped', 'shipped_to_customer' );
  function shipped_to_customer( $order_id ){
    $order = wc_get_order( $order_id );
    $billing_phone  = $order->get_billing_phone();
    netgsm_send_sms(str_replace("+90","",$billing_phone), "$order_id numaralı sutore.com siparişinizde yer alan ürünlerin orijinalliği doğrulandı. Ürünlerinizi size gönderiyoruz. Yurtiçi Kargo gönderi takip numarası: 1234567890. ".current_time("timestamp"));

  }

  add_action( 'woocommerce_order_status_completed', 'order_completed' );
  function order_completed( $order_id ){
    $order = wc_get_order( $order_id );
    $billing_phone  = $order->get_billing_phone();
    netgsm_send_sms(str_replace("+90","",$billing_phone), "$order_id numaralı sutore.com siparişiniz tamamlanmıştır. Bizi tercih ettiğiniz için tekrardan teşekkür ederiz.");

  }

  add_action( 'woocommerce_order_status_refunded', 'order_refunded' );
  function order_refunded( $order_id ){
    $order = wc_get_order( $order_id );
    $billing_phone  = $order->get_billing_phone();
    netgsm_send_sms(str_replace("+90","",$billing_phone), "$order_id numaralı sutore.com siparişinize ilişkin ücret iadeniz gerçekleştirilmiştir. İadenizin hesabınıza yansıma süresi bankanıza göre değişiklik gösterebilir.");

  }

  function netgsm_send_sms($numbers, $message, $startDate = null){
    //    try {
    //        $client = new SoapClient("http://soap.netgsm.com.tr:8080/Sms_webservis/SMS?wsdl");
    //
    //        $msg  = $message;
    //        $gsm  = $numbers;
    //
    //
    //        $Result = $client -> smsGonder1NV2(array('username'=>SUTORE_NETGSM_USER, 'password' => SUTORE_NETGSM_PASS, 'header' => 'SUTORE', 'msg' => $msg, 'gsm' => $gsm,  'filter' => '0', 'startdate'  => '', 'stopdate'  => '', 'encoding' => "TR"  ));
    //    } catch (Exception $exc)
    //    {
    //        // Hata olusursa yakala
    //        echo "Soap Hatasi Olustu: " . $exc->getMessage();
    //    }


    $username = defined('SUTORE_NETGSM_USER') ? constant('SUTORE_NETGSM_USER') : "8503070927";
    $password = rawurlencode(defined('SUTORE_NETGSM_PASS') ? constant('SUTORE_NETGSM_PASS') : "R3-YU7HP");
    $msg = rawurlencode($message);

    $url= "https://api.netgsm.com.tr/sms/send/get/?usercode=$username&password=$password&gsmno=$numbers&message=$msg&msgheader=SUTORE&dil=TR";


    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Connection timeout in seconds
    curl_setopt($ch, CURLOPT_TIMEOUT, 6); // Total timeout in seconds
    $http_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if($http_code != 200){
      //echo "$http_code $http_response\n";
      return false;
    }else{
      //echo "$numbers\n";
      //echo "$http_code $http_response\n";
      //echo "OK!";
    }

    $balanceInfo = $http_response;
    //echo "MesajID : $balanceInfo";
    return $balanceInfo;
  }

  function send_multiple_sms($numbers, $message){
    $data = [
      "msgheader" => "SUTORE",
      "encoding" => "TR",
      "iysfilter" => "",
      "partnercode" => ""
  ];

  foreach($numbers as $number){
    $data["messages"][] = array(
      "msg" => $message,
      "no" => $number
    );
  }

  // $data["messages"][] = array(
  //   "msg" => $message,
  //   "no" => "055543889207"
  // );

  
  // API URL'si
  $url = "https://api.netgsm.com.tr/sms/rest/v2/send"; // Buraya hedef API URL'sini yazın
  
  // Basic Auth kullanıcı adı ve şifresi
  $username = defined('SUTORE_NETGSM_USER') ? constant('SUTORE_NETGSM_USER') : "8503070927";
  $password = defined('SUTORE_NETGSM_PASS') ? constant('SUTORE_NETGSM_PASS') : "R3-YU7HP";
  
  // cURL başlat
  $ch = curl_init();
  
  // cURL ayarları
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'Authorization: Basic ' . base64_encode($username . ':' . $password)
  ]);
  
  // API isteğini gönder
  $response = curl_exec($ch);
  
  
  if (curl_errno($ch)) {
      echo 'Hata: ' . curl_error($ch);
  } else {
      // API yanıtını yazdır
      echo "Yanıt: " . $response;
  }
  
  curl_close($ch);
  }

  function isPasswordStrong($password)
  {
    // Validate password strength
    $uppercase = preg_match('@[A-Z]@', $password);
    $lowercase = preg_match('@[a-z]@', $password);
    $number    = preg_match('@[0-9]@', $password);
    $specialChars = preg_match('@[^\w]@', $password);

    if(!$uppercase || !$lowercase || !$number || !$specialChars || strlen($password) < 8) {
      return false;
    }else{
      return true;
    }
  }

  function isPostCodeValid($post_code)
  {
    // Allow +, - and . in phone number
    $filtered_post_code = $post_code;
    // Remove "-" from number
    $post_code_to_check = str_replace("-", "", $filtered_post_code);

    // Check the lenght of number
    // This can be customized if you want phone number from a specific country
    if (!is_numeric($post_code_to_check) || strlen($post_code_to_check) != 5) {
      return false;
    } else {
      return true;
    }
  }

  function isTelValid($phone)
  {
    // Allow +, - and . in phone number
    $filtered_phone_number = filter_var($phone, FILTER_SANITIZE_NUMBER_INT);
    // Remove "-" from number
    $phone_to_check = str_replace("-", "", $filtered_phone_number);

    // Check the lenght of number
    // This can be customized if you want phone number from a specific country
    if (!is_numeric($phone_to_check) || strlen($phone_to_check) < 10 || strlen($phone_to_check) > 14) {
      return false;
    } else {
      return true;
    }
  }

  function isTcValid($tc) {
    if (strlen($tc) != 11) {return false;}
    if ($tc[0] == '0') {return false;}
    $plus = ($tc[0] + $tc[2] + $tc[4] + $tc[6] + $tc[8]) * 7;
    $minus = $plus - ($tc[1] + $tc[3] + $tc[5] + $tc[7]);
    $mod = $minus % 10;
    if ($mod != $tc[9]) {return false;}
    $all = 0;
    for ($i = 0; $i < 10; $i++) {$all += intval($tc[$i]);}
    if ($all % 10 != intval($tc[10])) {return false;}
    return true;
  }

  function isValidIBAN($iban) {

    $iban = preg_replace("/\s+/", "", trim(strtolower($iban)));

    $Countries = array(
      'al' => 28, 'ad' => 24, 'at' => 20, 'az' => 28, 'bh' => 22, 'be' => 16, 'ba' => 20, 'br' => 29, 'bg' => 22, 'cr' => 21, 'hr' => 21, 'cy' => 28, 'cz' => 24,
      'dk' => 18, 'do' => 28, 'ee' => 20, 'fo' => 18, 'fi' => 18, 'fr' => 27, 'ge' => 22, 'de' => 22, 'gi' => 23, 'gr' => 27, 'gl' => 18, 'gt' => 28, 'hu' => 28,
      'is' => 26, 'ie' => 22, 'il' => 23, 'it' => 27, 'jo' => 30, 'kz' => 20, 'kw' => 30, 'lv' => 21, 'lb' => 28, 'li' => 21, 'lt' => 20, 'lu' => 20, 'mk' => 19,
      'mt' => 31, 'mr' => 27, 'mu' => 30, 'mc' => 27, 'md' => 24, 'me' => 22, 'nl' => 18, 'no' => 15, 'pk' => 24, 'ps' => 29, 'pl' => 28, 'pt' => 25, 'qa' => 29,
      'ro' => 24, 'sm' => 27, 'sa' => 24, 'rs' => 22, 'sk' => 24, 'si' => 19, 'es' => 24, 'se' => 24, 'ch' => 21, 'tn' => 24, 'tr' => 26, 'ae' => 23, 'gb' => 22, 'vg' => 24,
    );
    $Chars = array(
      'a' => 10, 'b' => 11, 'c' => 12, 'd' => 13, 'e' => 14, 'f' => 15, 'g' => 16, 'h' => 17, 'i' => 18, 'j' => 19, 'k' => 20, 'l' => 21, 'm' => 22,
      'n' => 23, 'o' => 24, 'p' => 25, 'q' => 26, 'r' => 27, 's' => 28, 't' => 29, 'u' => 30, 'v' => 31, 'w' => 32, 'x' => 33, 'y' => 34, 'z' => 35,
    );
    if (!array_key_exists(substr($iban, 0, 2), $Countries)) {return false;}
    if (strlen($iban) != $Countries[substr($iban, 0, 2)]) {return false;}

    $MovedChar = substr($iban, 4) . substr($iban, 0, 4);
    $MovedCharArray = str_split($MovedChar);
    $NewString = "";

    foreach ($MovedCharArray as $k => $v) {

      if (!is_numeric($MovedCharArray[$k])) {
        $MovedCharArray[$k] = $Chars[$MovedCharArray[$k]];
      }
      $NewString .= $MovedCharArray[$k];
    }
    if (function_exists("bcmod")) {return bcmod($NewString, '97') == 1;}

    // http://au2.php.net/manual/en/function.bcmod.php#38474
    $x = $NewString;
    $y = "97";
    $take = 5;
    $mod = "";

    do {
      $a = (int) $mod . substr($x, 0, $take);
      $x = substr($x, $take);
      $mod = $a % $y;
    } while (strlen($x));

    return (int) $mod == 1;
  }

  function sehirIlceleri($sehir){
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

    $selected = $ilceler[$sehir];

    foreach($selected as $select){
      $ilce[$select] = $select;
    }


    return $ilce;
  }

  add_filter('acf/settings/remove_wp_meta_box', '__return_false');

  function get_vendor_product_status($post_id){
    switch (get_post_status($post_id)){
      case "pending" :
      echo "Onay Bekliyor";
      break;
      case "publish" :
      echo "Satışta";
      break;
      case "not-sale" :
      echo "Satışta Değil";
      break;
      case "pre-order" :
      echo "Ön Sipariş";
      break;
      case "payment" :
      echo "Satışta";
      break;
      case "draft" :
      echo "Satış Sırasında";
      break;
      case "sold" :
      echo "Satıldı";
      break;
      case "not-sale" :
      echo "Satışta Değil";
      break;
      case "shipped-to-sutore" :
      echo "Sutore'ye Kargolandı";
      break;
      case "ready-to-shipping" :
      echo "Kargoya Hazır";
      break;
      case "arrived-to-sutore" :
      echo "Sutore'ye Ulaştı";
      break;
      case "verified" :
        echo "Doğrulandı";
        break;
        case "confirmed" :
        echo "Satıcı Onayladı";
        break;
        case "paid" :
        echo "Ödendi";
        break;
        case "shipped" :
        echo "Kargolandı";
        break;
        case "payment" :
        echo "Ödeme Bekleniyor";
        break;
        case "expired" :
        echo "Süresi Doldu";
        break;
        case "chargeback" :
        echo "İade Edildi";
        break;
      }
    }

    function get_vendor_product_expire_time($product_id){
      $datNow = date('Y-m-d', (int) current_time("timestamp"));
      $datExpire = date('Y-m-d', (int) get_post_meta($product_id,"expire_date",true));
      $date1=date_create($datNow);
      $date2=date_create($datExpire);
      $diff=date_diff($date1,$date2);
      return $diff->format("%a gün");
    }

    function get_vendor_product_cargo_expire_time($product_id){

      if(get_post_meta($product_id,"product_cargo_expired",true)){
        return "Gecikme Cezası (ürün merkezimize ulaştığında hesaplanacaktır)";
      }

      $datExpire = date('Y-m-d H:i:s', get_post_meta($product_id,"product_cargo_expire",true));;
      $datNow = date('Y-m-d H:i:s', current_time("timestamp"));;
      $date1=date_create($datExpire);
      $date2=date_create($datNow);
      $diff=date_diff($date1,$date2);


      if($diff->d > "0" && $diff->h == "0" && $diff->i == "0" && $diff->s == "0") {
        return $diff->d * 24 ." saat";
      }elseif($diff->d > "0" && $diff->h > "0" && $diff->i > "0" && $diff->s > "0") {
        $hours = $diff->days * 24;
        return $diff->h + $hours ." saat ".$diff->i. " dakika";
      }elseif($diff->d == "0" && $diff->h > "0" && $diff->i > "0" && $diff->s > "0"){
        return $diff->h ." saat ".$diff->i. " dakika";
      }elseif($diff->d == "0" && $diff->h == "0" && $diff->i > "0" && $diff->s > "0"){
        return $diff->i. " dakika ". $diff->s. " saniye ";
      }elseif($diff->d == "0" && $diff->h == "0" && $diff->i == "0" && $diff->s > "0"){
        return $diff->s. " saniye ";
      }elseif($diff->d == "0" && $diff->h == "0" && $diff->i == "0" && $diff->s == "0"){
        return "Gecikme Cezası (ürün merkezimize ulaştığında hesaplanacaktır)";
      }

    }

    function get_vendor_product_confirm_expire_time($product_id){

      $datExpire = date('Y-m-d H:i:s', get_post_meta($product_id,"product_confirm_expire",true));
      $datNow = date('Y-m-d H:i:s', current_time("timestamp"));;
      $date1=date_create($datExpire);
      $date2=date_create($datNow);
      $diff=date_diff($date1,$date2);

      if(get_post_meta($product_id,"product_confirm_expired",true)){
        return "Gecikme Cezası (ürün merkezimize ulaştığında hesaplanacaktır)";
      }

      if($diff->d > "0" && $diff->h == "0" && $diff->i == "0" && $diff->s == "0") {
        return $diff->d * 24 ." saat";
      }elseif($diff->d > "0" && $diff->h > "0" && $diff->i > "0" && $diff->s > "0") {
        $hours = $diff->days * 24;
        return $diff->h + $hours ." saat ".$diff->i. " dakika";
      }elseif($diff->d == "0" && $diff->h > "0" && $diff->i > "0" && $diff->s > "0"){
        return $diff->h ." saat ".$diff->i. " dakika";
      }elseif($diff->d == "0" && $diff->h == "0" && $diff->i > "0" && $diff->s > "0"){
        return $diff->i. " dakika ". $diff->s. " saniye ";
      }elseif($diff->d == "0" && $diff->h == "0" && $diff->i == "0" && $diff->s > "0"){
        return $diff->s. " saniye ";
      }elseif($diff->d == "0" && $diff->h == "0" && $diff->i == "0" && $diff->s == "0"){
        return "Gecikme Cezası (ürün merkezimize ulaştığında hesaplanacaktır)";
      }


    }

    function global_notice_meta_box() {

      add_meta_box(
        'product-meta',
        __( 'Ürün Bilgileri', 'sitepoint' ),
        'product_meta_meta_box_callback'
      );

    }

    add_action( 'add_meta_boxes_product', 'global_notice_meta_box' );

    function product_meta_meta_box_callback( $post )
    {
      wp_nonce_field('sutore_product_meta_save', 'sutore_product_meta_nonce');

      ?>
      <table>
        <?php if(get_post_meta($post->ID, 'no_box', true)) : ?>
          <tr>
            <td>Kutusu Yok</td>
          </tr>
        <?php endif; ?>

        <?php if(get_post_meta($post->ID, 'damaged', true)) : ?>
          <tr>
            <td>Hasarlı</td>
          </tr>
        <?php endif; ?>

        <?php if(get_post_meta($post->ID, 'missing', true)) : ?>
          <tr>
            <td>Eksik Aksesuar</td>
          </tr>
        <?php endif; ?>

        <?php if(get_post_meta($post->ID, 'damaged_product', true)) : ?>
          <tr>
            <td>Hasarlı Ürün</td>
          </tr>
        <?php endif; ?>

        <?php if(get_post_meta($post->ID, 'tried_product', true)) : ?>
          <tr>
            <td>Denenmiş Ürün</td>
          </tr>
        <?php endif; ?>

        <?php if(get_post_meta($post->ID, 'product_condition', true)) : ?>
          <tr>
            <td>Ürün Kondisyonu: <?php echo esc_html(get_post_meta($post->ID, 'product_condition', true));?></td>
          </tr>
        <?php endif; ?>

        <?php if(get_post_meta($post->ID, 'product_box_condition', true)) : ?>
          <tr>
            <td>Ürün Kutu Kondisyonu: <?php echo esc_html(get_post_meta($post->ID, 'product_box_condition', true));?></td>
          </tr>
        <?php endif; ?>

        <?php if(get_post_meta($post->ID, 'product_size', true)) : ?>
          <tr>
            <td>Ürün Bedeni: <?php echo esc_html(get_post_meta($post->ID, 'product_size', true));?></td>
          </tr>
        <?php endif; ?>

        <?php if(get_post_meta($post->ID, 'product_desc', true)) : ?>
          <tr>
            <td>Ürün Açıklaması: <?php echo esc_html(get_post_meta($post->ID, 'product_desc', true));?></td>
          </tr>
        <?php endif; ?>
        <?php if(get_post_meta($post->ID, 'urun_kodu', true)) : ?>
          <tr>
            <td>Ürün Kodu: <?php echo esc_html(get_post_meta($post->ID, 'urun_kodu', true));?></td>
          </tr>
        <?php endif; ?>

        <?php if(get_post_meta($post->ID, 'product_sold', true)) : ?>
          <tr>
            <td>Ürün Satıldı: <?php echo get_post_meta($post->ID, 'product_sold', true);?></td>
          </tr>
        <?php endif; ?>

      </table>

      <?php

    }

    function sutore_save_product_meta_box($post_id) {
      if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
      }
      if (!isset($_POST['sutore_product_meta_nonce']) || !wp_verify_nonce($_POST['sutore_product_meta_nonce'], 'sutore_product_meta_save')) {
        return;
      }
      if (!current_user_can('edit_post', $post_id)) {
        return;
      }

      if (isset($_POST['product_condition'])) {
        update_post_meta($post_id, 'product_condition', sanitize_text_field(wp_unslash($_POST['product_condition'])));
      }
      if (isset($_POST['product_box_condition'])) {
        update_post_meta($post_id, 'product_box_condition', sanitize_text_field(wp_unslash($_POST['product_box_condition'])));
      }
      if (isset($_POST['product_desc'])) {
        update_post_meta($post_id, 'product_desc', sanitize_text_field(wp_unslash($_POST['product_desc'])));
      }
    }
    add_action('save_post_product', 'sutore_save_product_meta_box');
    ?>