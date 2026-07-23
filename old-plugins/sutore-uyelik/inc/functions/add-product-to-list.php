<?php
if (!defined('ABSPATH')) {
  exit;
}

add_action('wp_ajax_add_product_to_list', 'add_product_to_list');
//add_action('wp_ajax_nopriv_add_product_to_list', 'add_product_to_list');

function add_product_to_list(){
  if (!is_user_logged_in()) {
    wp_send_json(array("status" => false, "message" => "Lütfen giriş yapın."));
    wp_die();
  }

  //    print_r($_POST);
  //    print_r($_FILES);
  //
  if ( ! isset( $_POST['product_price'] ) || ! isset( $_POST['product_id'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
    wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
    wp_die();
  }else{
    if(isset( $_POST['product_id'] ) && $_POST['product_id'] != "undefined" ) {


      $user_id = get_current_user_id();

      if(current_time("timestamp") < get_user_meta($user_id,"disabled_account_until",true)){
        wp_send_json(array("status" => false, "message" =>  "Hesabınız ". date("d-m-Y",get_user_meta($user_id,"disabled_account_until",true))." tarihine kadar kısıtlanmıştır."));
        wp_die();
      }


      //wp_send_json(array("status" => false, "message" => $_POST));
      $productId = sanitize_text_field($_POST['product_id']);
      $termId = sanitize_text_field($_POST['term_id']);
      //echo $productId;
      //$parentId = $product->get_parent_id();
      //print_r($variations);
      $merchant_id = get_current_user_id();
      $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
      $guvence_bedeli =  (int) get_option("guvence_bedeli",true);
      $merchant_level = get_user_meta($merchant_id,"merchant_level",true);
      if($merchant_level == 2 || $merchant_level == 3 || $merchant_level == 4 || $merchant_level == 5 || $merchant_level == 7){
        $status = "publish";
        $message = "Ürününüz başarıyla sisteme eklendi ve satışa sunuldu.";
      }else{
        $status = "pending";
        $message = "Ürününüzün onaylanması için bizimle iletişime geçmeniz gerekmektedir.";
      }

      $product = wc_get_product($productId);
      $variation = new WC_Product_Variation();
      $variation->set_parent_id( $product->get_id() );
      $variation->set_attributes(['pa_beden-numara' => get_term( $termId )->slug]);
      //$variation->set_attributes(array('beden-numara' => get_term( $termId )->slug));
      $insuranceFee = $_POST['product_price'] * $guvence_bedeli / 100;
      $variation->set_status("pending");
      $variation->set_price(sanitize_text_field($_POST['product_price']) + $hizmet_bedeli + $insuranceFee);
      $variation->set_regular_price(sanitize_text_field($_POST['product_price']) + $hizmet_bedeli + $insuranceFee);
      $variation->set_stock_status("instock");
      $variation->set_stock_quantity( 1 );
      $variation->set_manage_stock( true );
      $variation->save();
      $product->save();
      update_post_meta( $variation->get_id(), 'merchant_product', 1 );
      set_post_thumbnail($variation->get_id(), get_post_thumbnail_id($productId));
      //
      $product_id = $variation->get_id();

      //wp_send_json(array("status" => true, "message" => $variation->get_date_created()));

      update_post_meta( $product_id, 'child_of', sanitize_text_field($_POST['product_id']) );
      update_post_meta( $product_id, 'term_id', $termId );


      update_post_meta($product_id,"no_box", 0);
      update_post_meta($product_id,"damaged", 0);
      update_post_meta($product_id,"missing", 0);
      update_post_meta($product_id,"damaged_product", 0);
      update_post_meta($product_id,"tried_product", 0);
      update_post_meta($product_id,"has_invoice", 0);

      if(sanitize_text_field($_POST['no_box']) != "undefined"){
        update_post_meta($product_id,"no_box", 1);
      }

      if(sanitize_text_field($_POST['damaged']) != "undefined"){
        update_post_meta($product_id,"damaged", 1);
      }

      if(sanitize_text_field($_POST['missing']) != "undefined"){
        update_post_meta($product_id,"missing", 1);
      }

      if(sanitize_text_field($_POST['damaged_product']) != "undefined"){
        update_post_meta($product_id,"damaged_product", 1);
      }

      if(sanitize_text_field($_POST['tried_product']) != "undefined"){
        update_post_meta($product_id,"tried_product", 1);
      }

      if(sanitize_text_field($_POST['fast_shipment']) != "undefined"){
        update_post_meta($product_id,"fast_shipment", 1);
      }

      if(sanitize_text_field($_POST['has_invoice']) != "undefined"){
        update_post_meta($product_id,"has_invoice", 1);
      }

      //                update_post_meta( $product_id, '_price', sanitize_text_field($_POST['product_price'] + 99) );
      //                update_post_meta( $product_id, '_sale_price', sanitize_text_field($_POST['product_price'] + 99) );
      //                update_post_meta( $product_id, '_regular_price', sanitize_text_field($_POST['product_price'] + 99) );
      update_post_meta( $product_id, 'product_condition', sanitize_text_field($_POST['product_condition']) );
      update_post_meta( $product_id, 'product_box_condition', sanitize_text_field($_POST['product_box_condition']) );
      update_post_meta( $product_id, 'product_desc', sanitize_text_field($_POST['product_desc']) );
      //update_post_meta( $product_id, "expire_date", current_time("timestamp") + 2629743);
      update_post_meta( $product_id, "urun_kodu", get_field("urun_kodu",$productId));
      //set_post_thumbnail($product_id, get_post_thumbnail_id($productId));
      //                $variation = wc_get_product($product_id);
      //                $parent_id = $productId;
      //                $term = get_term_by("id",$termId,"pa_beden-numara");
      //                $current_on_sale = wc_get_product(find_matching_product_variation_id($parent_id, array("attribute_pa_beden-numara" => $term->slug)));
      //
      //                if(isset($current_on_sale) && !empty($current_on_sale)) {
      //                    if ((int)$current_on_sale->get_price() > (int)$variation->get_price()) {
      //                        $variation->set_status("publish");
      //                        $variation->set_menu_order($current_on_sale->get_menu_order());
      //                        $variation->save();
      //                        $current_on_sale->set_status("draft");
      //                        $current_on_sale->save();
      //                    } else {
      //                        $variation->set_status("draft");
      //                        $variation->set_menu_order(0);
      //                        $variation->save();
      //                    }
      //                }else{
      //                    $variation->set_status("publish");
      //                    $variation->set_menu_order($variation->get_id());
      //                    $variation->save();
      //                }
      add_product_to_system($productId, (int) $variation->get_id(),$termId, false, false, true);
      //wp_send_json(array("status" => true, "message" => $return));





      if(isset($_POST["product_status"]) && $_POST["product_status"] == 2 && isset($_FILES)) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');


        wp_set_object_terms( $product_id, 103, 'product_cat',true );
        wp_remove_object_terms( $product_id, $terms, 'product_visibility' );
        update_post_meta( $product_id, 'used', 1 );
        wp_update_post(array("ID"=> $product_id, "post_status" => "pending"));
        $message = "Ürününüz başarıyla eklendi. Ürününüz yönetici kontrolünden geçtikten sonra satışa koyulacaktır.";

        $files = $_FILES["files"];
        //print_r($files);
        foreach ($files['name'] as $key => $value) {
          if ($files['name'][$key]) {
            $file = array(
              'name' => $files['name'][$key],
              'type' => $files['type'][$key],
              'tmp_name' => $files['tmp_name'][$key],
              'error' => $files['error'][$key],
              'size' => $files['size'][$key]
            );
            $_FILES = array("upload_file" => $file);
            $attachment_id = media_handle_upload("upload_file", $product_id);

            if (is_wp_error($attachment_id)) {
              // There was an error uploading the image.
              wp_send_json(array("status" => false, "message" => "Ürün görselleri yüklenirken bir hata oluştu. Lütfen tekrar deneyin."));
              wp_die();
            } else {
              $images[] = $attachment_id;
            }
            $images_meta = "";
            foreach ($images as $im_id) {
              if (is_null(get_post_meta($product_id, "_product_image_gallery")))
              add_post_meta($product_id, "_product_image_gallery", $im_id);
              else {
                $images_meta = get_post_meta($product_id, "_product_image_gallery", true);
                update_post_meta($product_id, "_product_image_gallery", $images_meta . "," . $im_id);
              }
              set_post_thumbnail($product_id, $im_id);
            }
          }

        }
      }


      //            $args = array("post_type" => "vendor_sales", "post_status" => "pending", "post_title" => get_the_title($productId));
      //            $post_id = wp_insert_post( $args);
      //
      //            update_post_meta($post_id,"product_price", sanitize_text_field($_POST['product_price']));
      //            update_post_meta($post_id,"no_box", sanitize_text_field($_POST['no_box']));
      //            update_post_meta($post_id,"damaged", sanitize_text_field($_POST['damaged']));
      //            update_post_meta($post_id,"missing", sanitize_text_field($_POST['missing']));
      //            update_post_meta($post_id,"product_id", sanitize_text_field($_POST['product_id']));
      //            update_post_meta($post_id,"product_parent_id", $parentId);
      //
      //            if($_POST["product_status"] == 2){
      //                update_post_meta($post_id,"product_used", 1);
      //            }

      wp_send_json(array("status" => true, "message" => $message));
      wp_die();

    }else {
      ///////////// Ürün Sistemde Yoksa ////////////////
      require_once(ABSPATH . 'wp-admin/includes/image.php');
      require_once(ABSPATH . 'wp-admin/includes/file.php');
      require_once(ABSPATH . 'wp-admin/includes/media.php');

      $args = array("post_type" => "product", "post_author" => get_current_user_id(), "post_status" => "pending", "post_title" => sanitize_text_field($_POST['product_title']), 'post_excerpt' => sanitize_text_field($_POST['product_desc']));
      $product_id = wp_insert_post($args);
      $termId = sanitize_text_field($_POST['term_id']);

      wp_set_object_terms( $product_id, 103, 'product_cat' );
      wp_set_object_terms( $product_id, 947, 'product_cat',true );

      $terms = array( 'exclude-from-catalog', 'exclude-from-search' );
      wp_set_object_terms( $product_id, $terms, 'product_visibility' );

      if(sanitize_text_field($_POST['no_box']) != "undefined"){
        update_post_meta($product_id,"no_box", sanitize_text_field($_POST['no_box']));
      }

      if(sanitize_text_field($_POST['damaged']) != "undefined"){
        update_post_meta($product_id,"damaged", sanitize_text_field($_POST['damaged']));
      }

      if(sanitize_text_field($_POST['missing']) != "undefined"){
        update_post_meta($product_id,"missing", sanitize_text_field($_POST['missing']));
      }


      update_post_meta( $product_id, 'product_condition', sanitize_text_field($_POST['product_condition']) );
      update_post_meta( $product_id, 'product_box_condition', sanitize_text_field($_POST['product_box_condition']) );
      update_post_meta( $product_id, 'product_desc', sanitize_text_field($_POST['product_desc']) );
      update_post_meta( $product_id, 'product_size', sanitize_text_field($_POST['product_size']) );
      update_post_meta( $product_id, 'urun_kodu', sanitize_text_field($_POST['product_new_code']) );

      update_post_meta( $product_id, '_price', sanitize_text_field($_POST['product_price']) + 75 );
      update_post_meta( $product_id, 'term_id', $termId );
      update_post_meta($product_id,"used", 1);
      update_post_meta( $product_id, "expire_date", current_time("timestamp") + 2629743);

      $product = wc_get_product($product_id);
      $product->set_manage_stock( true );
      $product->set_stock_quantity( 1 );
      $product->save();


      $files = $_FILES["files"];
      //print_r($files);
      foreach ($files['name'] as $key => $value) {
        if ($files['name'][$key]) {
          $file = array(
            'name' => $files['name'][$key],
            'type' => $files['type'][$key],
            'tmp_name' => $files['tmp_name'][$key],
            'error' => $files['error'][$key],
            'size' => $files['size'][$key]
          );
          $_FILES = array("upload_file" => $file);
          $attachment_id = media_handle_upload("upload_file", $product_id);

          if (is_wp_error($attachment_id)) {
            // There was an error uploading the image.
            wp_send_json(array("status" => false, "message" => "Ürün görselleri yüklenirken bir hata oluştu. Lütfen tekrar deneyin."));
            wp_die();
          } else {
            $images[] = $attachment_id;
          }
          $images_meta = "";
          foreach ($images as $im_id) {
            if (is_null(get_post_meta($product_id, "_product_image_gallery")))
            add_post_meta($product_id, "_product_image_gallery", $im_id);
            else {
              $images_meta = get_post_meta($product_id, "_product_image_gallery", true);
              update_post_meta($product_id, "_product_image_gallery", $images_meta . "," . $im_id);
            }
            set_post_thumbnail($product_id, $im_id);
          }
        }

      }

      wp_send_json(array("status" => true, "message" => "Ürününüz başarıyla eklendi. Ürününüz yönetici kontrolünden geçtikten sonra satışa koyulacaktır."));
      wp_die();

    }
    $html = null;
    $html .= '<ul class="woocommerce-cart-form__cart-item col large-3 cart_item listing-item" data-id="'.$productId.'">';
    $html .= '<li class="product-thumbnail">';
    $html .= '<a href="#"><img width="500" height="356" src="'.get_the_post_thumbnail_url($parentId,'thumbnail').'" class="lazy-load attachment-woocommerce_thumbnail size-woocommerce_thumbnail lazy-load-active"></a></li>';
    $html .= '<li class="product-name" data-title="Ürün">';
    $html .= '<a href="#">'.get_the_title($productId).'</a><div class="show-for-small mobile-product-price">';
    $html .= '<span class="mobile-product-price__qty">1 x </span>';
    $html .= '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">₺</span'.esc_html(sanitize_text_field($_POST["product_price"])).'</bdi></span></div>';
    $html .= '</li>';
    $html .= '<li class="product-price" data-title="Fiyat">';
    $html .= '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">₺</span>'.esc_html(sanitize_text_field($_POST["product_price"])).'</bdi></span>';
    $html .= '</li>';
    $html .= '<td class="product-quantity" data-title="Miktar">';
    $html .= '<input type="hidden" name="cart[45527eac76e1b507b05b672af3b88cbb][qty]" value="1" /></td>';
    $html .= '<li class="product-remove">';
    $html .= '<a href="#" class="remove" aria-label="Bu ürünü çıkar" data-id="'.$productId.'" data-product_sku="">Sil</a>';
    $html .= '</li>';
    $html .= '</ul>';
    wp_send_json(array("status" => true, "html" => $html));
    wp_die();
  }
}

?>
