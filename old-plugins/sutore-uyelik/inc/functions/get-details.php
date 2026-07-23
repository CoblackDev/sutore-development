<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_get_details', 'get_details');
//add_action('wp_ajax_nopriv_get_details', 'get_details');

function sutore_user_can_access_product($product_id){
    $post_author_id = (int) get_post_field('post_author', $product_id);
    $current_user_id = get_current_user_id();
    $can_manage_all = current_user_can('administrator') || current_user_can('shop_manager') || current_user_can('editor');

    if (!$current_user_id) {
        return false;
    }

    return $can_manage_all || $current_user_id === $post_author_id;
}


function get_details(){
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else {
        //print_r($_POST);
        $product_id = sanitize_text_field($_POST['product_id']);
        if (!sutore_user_can_access_product($product_id)) {
            wp_send_json(array("status" => false, "message" => "Yetkisiz işlem."));
            wp_die();
        }
		$product = wc_get_product($product_id);
        $merchant_id = get_post_field( 'post_author', $product_id );
        $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
		$guvence_bedeli =  (int) get_option("guvence_bedeli",true);
        $price = $product->get_price();
        $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));

       $productStatus = get_post_status($product_id);
        if($productStatus == "sold" || $productStatus == "shipped-to-sutore" || $productStatus == "arrived-to-sutore" || $productStatus == "verified" || $productStatus == "confirmed"  || $productStatus == "shipped" || $productStatus == "paid" || $productStatus == "ready-to-shipping"){
            $order_id = get_post_meta($product_id,"product_sold_order_id",true);
            $order_date = get_post_meta($product_id,"product_sold_date",true);
            $order = new WC_Order($order_id);

            if(!empty($order_date)){
                $sold_date = date("d-m-Y H:i:s",$order_date);
            }else{
                $sold_date = $order->order_date;
            }

            // Get $order object from order ID
            //$order = wc_get_order( get_post_meta($product_id,"product_sold_order_id",true) );
            $html = null;
            $html .= '<table>';
            $html .= '<th colspan="2">'.esc_html(get_the_title($product_id)).'</th>';
            $html .= '<tr>';
            $html .= '<td>Sipariş No:</td><td>'.(int) $order_id.'</td>';
            $html .= '</tr>';
            $html .= '<tr>';
            $html .= '<td>Satış Tarihi:</td><td>'.esc_html($sold_date).'</td>';
            $html .= '</tr>';

            if(get_post_status($product_id) == "sold"){
                $html .= '<tr>';
                $html .= '<td>Satışı Onaylamak İçin Kalan Süreniz:</td><td>'.get_vendor_product_confirm_expire_time($product_id).'</td>';
                $html .= '</tr>';
            }elseif(get_post_status($product_id) == "shipped-to-sutore"){
                $html .= '<tr>';
                $html .= '<td>Kargo Takip Kodu:</td><td>'.get_post_meta($product_id,"shipment_code",true).'</td>';
                $html .= '</tr>';
            }elseif(get_post_status($product_id) == "confirmed"){
                if(in_array(get_post_meta($product_id,"merchant_level",true),array(1,2,6))){
                    $html .= '<tr>';
                    $html .= '<td>Ürünü Kargolamak İçin Kalan Süreniz:</td><td>'.get_vendor_product_cargo_expire_time($product_id).'</td>';
                    $html .= '</tr>';
                }else if(in_array(get_user_meta($merchant_id,"merchant_level",true),array(3,4,5,7))){
                    $deadline_timestamp = get_post_meta($order_id, "sutore_shipment_deadline_timestamp", true);
                    $deadline_minus_3_days = $deadline_timestamp - (3 * 24 * 60 * 60);
                    $formatted_date = date_i18n('j F Y', $deadline_minus_3_days);
                    $html .= '<tr>';
                    $html .= '<td>Son Teslim Tarihi:</td><td>' . $formatted_date . '</td>';
                    $html .= '</tr>';
                }
            }

            $html .= '<tr>';
            $html .= '<td>Size Ödenecek Net Tutar:</td><td>'.wc_price(merchant_net_paid($product_id,$merchant_id)).'</td>';
            $html .= '</tr>';
            $html .= '<tr>';
            $html .= '<td>Ödeme Yapılacak IBAN No:</td><td>'.esc_html(get_post_meta($product_id,"merchant_iban",true)).'</td>';
            $html .= '</tr>';
            $html .= '</table>';

        }elseif($productStatus == "publish" || $productStatus == "draft"){

        $productId = get_post_meta($product_id,"child_of",true);
        $termId = get_post_meta($product_id,"term_id",true);

            $term = get_term_by("id",$termId,"pa_beden-numara");
            $current_on_sale = wc_get_product(find_matching_product_variation_id($productId, array("attribute_pa_beden-numara" => $term->slug)));

            if(!$current_on_sale){
                $minSalePrice = "-";
                $minPrice = 0;
            }else{
                $price = $current_on_sale->get_price();
                $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));
                $minSalePrice = wc_price($basePrice);
                $minPrice = $basePrice;
            }
            if($current_on_sale) {
                if ($current_on_sale->get_id() == $product_id) {
                    $sale_order = 1;
                } else {
                    $sale_order = get_product_sale_order($productId, $termId, $product_id);
                }
            }


            $merchant_id = get_post_field ('post_author', $product_id);
            //$campaing = get_post_meta($product->get_id(),"campaing_response",true) ? " - ".get_post_meta($product->get_id(),"sutore_campaing_merchant_discount",true) : null;
        //$minPrice = get_product_min_price($productId,$termId,false,$product_id);
        //$renderPrice = $minPrice["price"] != null ? wc_price($minPrice["price"]) : "-";
        $html = null;
        $html .= '<table>';
        $html .= '<th colspan="2">'.esc_html(get_the_title($product_id)).'</th>';
        $html .= '<tr>';
        $html .= '<td>Kalan Süre:</td><td>'.get_vendor_product_expire_time($product_id).'</td>';
        $html .= '</tr>';
        $html .= '<tr>';
        $html .= '<td>Size Ödenecek Net Tutar:</td><td>'.wc_price(merchant_net_paid($product_id,$merchant_id)).'</td>';
        $html .= '</tr>';
        $html .= '<tr>';
        $html .= '<td>En Düşük Fiyat:</td><td>'.$minSalePrice.'</td>';
        $html .= '</tr>';
        $html .= '<tr>';
        $html .= '<td>Sıra:</td><td>' . $sale_order . '</td>';
        $html .= '</tr>';

        if(current_user_can('editor') || current_user_can('administrator') || get_user_meta(get_current_user_id(),"merchant_level",true) == 3 || get_user_meta(get_current_user_id(),"merchant_level",true) == 4 || get_user_meta(get_current_user_id(),"merchant_level",true) == 5 || get_user_meta(get_current_user_id(),"merchant_level",true) == 7){
            $html .= '<tr>';
            $html .= '<td>Sıradaki Ürünlerin Fiyatları:</td><td>' . get_vendor_product_sale_list($productId,$termId) . '</td>';
            $html .= '</tr>';
        }

        if(get_post_status($product_id) == "confirmed"){
                $html .= '<tr>';
                $html .= '<td>Ürünü Kargolamak İçin Kalan Süreniz:</td><td>'.get_vendor_product_cargo_expire_time($product_id).'</td>';
                $html .= '</tr>';
        }

        $html .= '</table>';
      }else if(get_post_status($product_id) == "payment"){
            wp_send_json(array(
                "status"  => false,
                "message" => "Bir hata oluştu. Lütfen daha sonra tekrar deneyin."
            ));
            wp_die();
      }

        wp_send_json(array("status" => true, "html" => $html));
        wp_die();

    }


}

add_action('wp_ajax_get_pre_order_details', 'get_pre_order_details');
//add_action('wp_ajax_nopriv_get_details', 'get_details');


function get_pre_order_details(){
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else {

        $product_id = sanitize_text_field($_POST['product_id']);
		$product = wc_get_product($product_id);
        $merchant_id = get_post_field( 'post_author', $product_id );
        $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
		$guvence_bedeli =  (int) get_option("guvence_bedeli",true);
        $price = $product->get_price();
        $order_id = get_post_meta($product->get_id(),"product_sold_order_id",true);
        $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));

        $html = null;
        $html .= '<table>';
        $html .= '<th colspan="2">'.esc_html(get_the_title($product_id)).'</th>';
        $html .= '<tr>';
        $deadline_timestamp = get_post_meta($order_id, "sutore_shipment_deadline_timestamp", true);
        if ($deadline_timestamp) {
            // Her koşulda deadline_timestamp değerinden 3 gün öncesini göstermeliyiz
            $deadline_minus_3_days = $deadline_timestamp - (3 * 24 * 60 * 60);
            $formatted_date = date_i18n('j F Y', $deadline_minus_3_days);
            $html .= '<td>Son Teslim Tarihi:</td><td>' . $formatted_date . '</td>';
        } else {
            $html .= '<td>Son Teslim Tarihi:</td><td>-</td>';
        }
        $html .= '</tr>';
        $html .= '<tr>';
        $html .= '<td>Size Ödenecek Net Tutar:</td><td>'.wc_price(merchant_net_paid($product_id,get_current_user_id())).'</td>';
        $html .= '</tr>';
        $html .= '<tr>';
        $html .= '</table>';

        wp_send_json(array("status" => true, "html" => $html, "date" => $formatted_date, "price" => wc_price(merchant_net_paid($product_id,get_current_user_id())) ) );

    }

}

add_action('wp_ajax_confirm_pre_order', 'confirm_pre_order');

function confirm_pre_order(){
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else {
        // wp_delete_post(441367,true);
        // wp_die();
        $product_id = sanitize_text_field($_POST['product_id']);
		$product = wc_get_product($product_id);
        $merchant_id = get_post_field( 'post_author', $product_id );
        $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
		$guvence_bedeli =  (int) get_option("guvence_bedeli",true);
        $price = $product->get_price();
        $order_id = get_post_meta($product->get_id(),"product_sold_order_id",true);

        // pa_beden-numara değerini al

    if(get_current_user_id() != $merchant_id){
        $attributes = $product->get_attributes();

      $parent_product = wc_get_product($product->get_parent_id());
      $termSlug = $attributes['pa_beden-numara'];
      $termId = get_term_by('slug', $termSlug, 'pa_beden-numara')->term_id;
      $variation = new WC_Product_Variation();
      $variation->set_parent_id( $parent_product->get_id() );
      $variation->set_attributes(['pa_beden-numara' => $termSlug]);
      $variation->set_status("pending");
      $variation->set_price($price);
      $variation->set_regular_price($price);
      $variation->set_stock_status("instock");
      $variation->set_stock_quantity( 1 );
      $variation->set_manage_stock( true );
      $variation->save();
      update_post_meta( $variation->get_id(), 'merchant_product', 1 );
      set_post_thumbnail($variation->get_id(), get_post_thumbnail_id($parent_product->get_id()));

      update_post_meta( $variation->get_id(), 'child_of', $parent_product->get_id() );
      update_post_meta( $variation->get_id(), 'term_id', $termId );
      update_post_meta($variation->get_id(),"no_box", 0);
      update_post_meta($variation->get_id(),"damaged", 0);
      update_post_meta($variation->get_id(),"missing", 0);
      update_post_meta($variation->get_id(),"damaged_product", 0);
      update_post_meta($variation->get_id(),"tried_product", 0);
      update_post_meta($variation->get_id(),"has_invoice", 0);
      update_post_meta($variation->get_id(), "urun_kodu", get_field("urun_kodu",$parent_product->get_id()));
      update_post_meta($variation->get_id(),"pre_order", 1);
      sutore_merchant_system($product_id, "swap-merchant", array("swap_product_id" => $variation->get_id()));
    }else{
        //update_post_meta($product_id,"pre_order", 1);
        sutore_merchant_system($product_id, "swap-merchant", array("swap_product_id" => $product_id));
    }

        wp_send_json(array("status" => true, "message" => "Ön sipariş onaylandı. Lütfen ürünü belirtilen tarihe kadar kargoya teslim ediniz."));
        wp_die();
    }
}

// Pre-order durumundaki tüm product_variation'lara SMS gönder ve sms_sent flagi ekle

add_action('sutore_cron_send_preorder_sms', 'sutore_send_preorder_sms_to_merchants');
function sutore_send_preorder_sms_to_merchants() {

    $user_query = new WP_User_Query(array(
    'meta_query' => array(
        array(
            'key'     => 'merchant_level',
            'value'   => array(3, 4, 5, 7),
            'compare' => 'IN',
        ),
    ),
    'fields' => 'ID', // Sadece kullanıcı ID'lerini al
));

// Sorgu sonucu kullanıcı ID'lerini al
$user_ids = $user_query->get_results();

foreach($user_ids as $user_id){
    $phone = get_user_meta($user_id, 'billing_phone_account', true);
    $numbers[] = $phone;
}

    // Pre-order olan ve sms_sent flag'i olmayan tüm product_variation'ları çek
    $args = array(
        'post_type'      => 'product_variation',
        'post_status'    => 'pre-order',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => 'sms_sent',
                'compare' => 'NOT EXISTS',
            ),
        ),
    );
    $query = new WP_Query($args);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $variation_id = get_the_ID();
            $product_title = fix_variation_title(str_replace("&#8211;", "", get_the_title($variation_id)));
            $words = explode(' ', $product_title);
            $first_three = implode(' ', array_slice($words, 0, 3));
            $last_two = implode(' ', array_slice($words, -2));
            $last_two = array_map(function($word) {
                $trimmed = trim($word);
                if ($trimmed === '' || strtoupper($trimmed) === 'ONE' || strtoupper($trimmed) === 'SIZE' || strtoupper($trimmed) === 'ONE SIZE') {
                    return '';
                }
                return $word;
            }, explode(' ', $last_two));
            $last_two = str_replace(" ", "", implode(' ', array_filter($last_two, function($w) { return $w !== ''; })));
            $titles[] = $first_three." ".$last_two;
            update_post_meta($variation_id, 'sms_sent', 1);
        }
        wp_reset_postdata();
    }else{
        echo "No posts found";
    }

    if(!empty($titles)){
    $titles_chunks = array_chunk($titles, 5);
    foreach ($titles_chunks as $chunk) {
        $message = "Ön sipariş listesi güncellendi: " . implode(", ", $chunk);
        send_multiple_sms($numbers, $message);
    }
    }
}
?>
