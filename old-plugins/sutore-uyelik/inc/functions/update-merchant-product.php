<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_update_merchant_product', 'update_merchant_product');
//add_action('wp_ajax_nopriv_update_merchant_product', 'update_merchant_product');


function update_merchant_product(){
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore_update_merchant_product_nonce' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else {


           $html = null;

           $html = '<label style="text-align: left;">Ürün Durumunu Güncelle</label>';
           $html .= '<select id="merchant_product_status">';
           $html .= '<option value="">Seçin</option>';
           $html .= '<option value="publish">Satışa Koy</option>';
           $html .= '<option value="not-sale">Satışta Değil</option>';
           $html .= '<option value="break">Siparişten Ayır</option>';
           $html .= '<option value="pending">Onay Bekliyor</option>';
           $html .= '<option value="sold">Satıldı</option>';
           $html .= '<option value="swap-merchant">Satıcı Ürünü Değiştir</option>';
           $html .= '<option value="confirm-payment">Satışı Onayla</option>';
           $html .= '<option value="arrived-to-sutore">Sutoreye Ulaştı</option>';
           $html .= '<option value="verified">Doğrulandı</option>';
           $html .= '<option value="ready-to-shipping">Kargoya Hazır</option>';
           $html .= '<option value="paid">Ödendi</option>';
           $html .= '<option value="shipped">Kargolandı</option>';
           $html .= '<option value="expired">Süresi Doldu</option>';
           $html .= '<option value="chargeback">İade Edildi</option>';
           $html .= '<option value="pre-order">Ön Sipariş</option>';
           $html .= '<option value="delete" style="color:red;">Sistemden Sil</option>';
           $html .= '</select>';
           $html .= '<label style="text-align: left;">Fiyat Güncelle</label>';
           $html .= '<input id="merchant_product_price" type="text" placeholder="Yeni fiyat"/>';
           $html .= '<label style="text-align: left;">Sipariş ID</label>';
           $html .= '<input id="merchant_product_order_id" type="text" placeholder="Siparis ID"/>';
           $html .= '<label style="text-align: left;">Ürün ID</label>';
           $html .= '<input id="merchant_product_id" type="text" placeholder="Ürün ID"/>';
           $html .= '<label style="text-align: left;">Kargo Takip</label>';
           $html .= '<input id="sutore_shipment_code" type="text" placeholder="Kargo takip no"/>';

           $html .= '<label style="text-align: left;">Sutore Kampanya Tutarı</label>';
           $html .= '<input id="sutore_campaing_discount" type="text" placeholder="Sutore indirim tutarı" value="'.esc_attr(get_post_meta($_POST["product_id"],"sutore_campaing_discount",true)).'"/>';
           $html .= '<label style="text-align: left;">Satıcıdan Talep Edilen Kampanya Tutarı</label>';
           $html .= '<input id="sutore_campaing_merchant_discount" type="text" placeholder="Satıcı indirim tutarı" value="'.esc_attr(get_post_meta($_POST["product_id"],"sutore_campaing_merchant_discount",true)).'"/>';
           if(get_post_meta($_POST["product_id"],"campaing_response_date",true)){
             $date_format = get_option('date_format') . ' ' . get_option('time_format'); // WordPress tarih ve saat formatı
             $formatted_date = date_i18n($date_format, get_post_meta($_POST["product_id"],"campaing_response_date",true));

             $html .= "<div>Kampanya Cevap Tarihi: ".$formatted_date."</div>";
             $html .= "<div>Kampanya Katılım Cevabı: ".get_post_meta($_POST["product_id"],"campaing_response",true)."</div>";
           }
            wp_send_json(array("status" => true, "html" => $html, "merchant" => "dasfg"));
            wp_die();
        }
}

add_action('wp_ajax_update_product_status', 'update_product_status');
//add_action('wp_ajax_nopriv_update_merchant_product', 'update_merchant_product');


function update_product_status(){
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore_update_merchant_product_nonce' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else {
        global $wpdb;
        $sutore_shipment_code = null;
        $set_order_id = null;
        //print_r($_POST);
        $post_id = sanitize_text_field($_POST["product_id"]);
        $post_author_id = get_post_field( 'post_author', sanitize_text_field($_POST['product_id']) );
        $status = sanitize_text_field($_POST["product_status"]);
        $price = sanitize_text_field($_POST["product_price"]);
        $set_order_id = sanitize_text_field($_POST["product_order_id"]);
        $sutore_shipment_code = sanitize_text_field($_POST["sutore_shipment_code"]);
        $merchant_product_id = sanitize_text_field($_POST["merchant_product_id"]);
        $product = wc_get_product( $post_id );
        $childof = get_post_meta($product->get_id(),"child_of",true);
        $term_id = get_post_meta($product->get_id(),"term_id",true);
        $menu_order = $product->get_menu_order();
        $term = get_term_by("id",$term_id,"pa_beden-numara");

        if(get_post_meta($post_id,"product_sold_order_id",true)){
            $order_id = get_post_meta($post_id,"product_sold_order_id",true);
            $order = wc_get_order( $order_id );
            $billing_phone  = $order->get_billing_phone();
        }

      // if(!isset($_POST["sutore_campaing_discount"])){
      //   netgsm_send_sms(str_replace("+90","",5543889207), "indirim smsi test.");
      // }

        if(isset($_POST["sutore_campaing_discount"]) && !empty($_POST["sutore_campaing_discount"]) && isset($_POST["sutore_campaing_merchant_discount"]) && !empty($_POST["sutore_campaing_merchant_discount"])){

          if($_POST["sutore_campaing_discount"] != get_post_meta($post_id,"sutore_campaing_discount",true) || $_POST["sutore_campaing_merchant_discount"] != get_post_meta($post_id,"sutore_campaing_merchant_discount",true)){
            delete_post_meta($post_id,"campaing_response_date");
            delete_post_meta($post_id,"campaing_response");
          }

          update_post_meta($post_id,"sutore_campaing_discount", sanitize_text_field($_POST["sutore_campaing_discount"]));
          update_post_meta($post_id,"sutore_campaing_merchant_discount", sanitize_text_field($_POST["sutore_campaing_merchant_discount"]));
        }else{
          delete_post_meta($post_id,"sutore_campaing_discount");
          delete_post_meta($post_id,"sutore_campaing_merchant_discount");
          delete_post_meta($post_id,"campaing_response_date");
          delete_post_meta($post_id,"campaing_response");
        }

        /////////// Fiyat değiştiyse /////////////////

        if(isset($price) && !empty($price)){
            sutore_merchant_system($product->get_id(),"add", array("price" => $price));
        }

        if(isset($merchant_product_id) && !empty($merchant_product_id)){
            if(!get_post_meta($post_id,"product_sold_order_id",true)) {
                wp_send_json(array("status" => false, "message" => "Ürün herhangi bir siparişe bağlı değil"));
                wp_die();
            }
        }

        if(isset($status) && !empty($status)){

            if($status == "pending" || $status == "not-sale"){
                if(get_post_status($post_id) == "sold" || get_post_status($post_id) == "confirmed" || get_post_status($post_id) == "arrived-to-sutore") {
                    sutore_merchant_system($product->get_id(),"not-sale");
                }else{
                    wp_send_json(array("status" => false, "message" => "Sadece satışta, satış sırasında ve süresi dolmuş ürünleri güncelleyebilirsiniz."));
                    wp_die();
                }
            }elseif($status == "publish"){
                if(get_post_status($post_id) == "pending" || get_post_status($post_id) == "publish" || get_post_status($post_id) == "draft" || get_post_status($post_id) == "expired") {
                    sutore_merchant_system($product->get_id(),"add");
                }else{
                    wp_send_json(array("status" => false, "message" => "Bu ürünü satışa koyamazsınız."));
                    wp_die();
                }
            }elseif($status == "break"){
                if(get_post_status($post_id) == "not-sale" || get_post_status($post_id) == "arrived-to-sutore" || get_post_status($post_id) == "sold" || get_post_status($post_id) == "confirmed" || get_post_status($post_id) == "shipped-to-sutore" || get_post_status($post_id) == "verified" || get_post_status($post_id) == "paid" || get_post_status($post_id) == "ready-to-shipping" || get_post_status($post_id) == "shipped") {
                    sutore_merchant_system($product->get_id(),"split");
                }else{
                    wp_send_json(array("status" => false, "message" => "Sadece satılmış ürün siparişten ayrılabilir."));
                    wp_die();
                }
            }elseif($status == "sold") {
                if(get_post_status($post_id) == "publish" || get_post_status($post_id) == "draft" || get_post_status($post_id) == "pending") {
                    sutore_merchant_system($product->get_id(), "sold", array("order_id" => $set_order_id, "manuel" => true));
                }else{
                    wp_send_json(array("status" => false, "message" => "Bu ürünün durumunu satıldı olarak güncelleyemezsiniz."));
                    wp_die();
                }
            }else if($status == "arrived-to-sutore"){
                if(get_post_status($post_id) == "arrived-to-sutore" || get_post_status($post_id) == "sold" || get_post_status($post_id) == "confirmed" || get_post_status($post_id) == "shipped-to-sutore" || get_post_status($post_id) == "verified" || get_post_status($post_id) == "ready-to-shipping" || get_post_status($post_id) == "chargeback" || get_post_status($post_id) == "shipped" || get_post_status($post_id) == "not-sale") {
                    sutore_merchant_system($product->get_id(), "arrived-to-sutore");
                }else{
                    wp_send_json(array("status" => false, "message" => "Sadece satılmış ürünleri sutoreye ulaştı olarak güncelleyebilirsiniz."));
                    wp_die();
                }
            }else if($status == "verified"){
                if(get_post_status($post_id) == "arrived-to-sutore") {
                    sutore_merchant_system($product->get_id(), "verified");
                }else{
                    wp_send_json(array("status" => false, "message" => "Sadece onaylanmış ürünleri güncelleyebilirsiniz."));
                    wp_die();
                }
            }else if($status == "shipped"){
                if(get_post_status($post_id) == "verified" || get_post_status($post_id) == "ready-to-shipping") {
                    sutore_merchant_system($product->get_id(), "shipped", array("shipment_code" => $sutore_shipment_code));
                }else{
                    wp_send_json(array("status" => false, "message" => "Sadece onaylanmış ve kargoya hazır ürünleri güncelleyebilirsiniz."));
                    wp_die();
                }
            }else if($status == "paid"){
                if(get_post_status($post_id) == "shipped") {
                    sutore_merchant_system($product->get_id(), "paid");
                }else{
                    wp_send_json(array("status" => false, "message" => "Sadece kargolanmış ürünleri güncelleyebilirsiniz."));
                    wp_die();
                }
            }else if($status == "delete"){
                sutore_merchant_system($product->get_id(),"delete");
            }else if($status == "ready-to-shipping"){
                if(get_post_status($post_id) == "verified") {
                    sutore_merchant_system($product->get_id(), "ready-to-shipping");
                }else{
                    wp_send_json(array("status" => false, "message" => "Sadece onaylanmış ürünleri güncelleyebilirsiniz."));
                    wp_die();
                }
            }else if($status == "confirm-payment"){
                if(get_post_status($post_id) == "payment") {
                    sutore_merchant_system($product->get_id(), "confirm-payment");
                }else{
                    wp_send_json(array("status" => false, "message" => "Sadece satış onayı bekleyen ürünlerin satışını onaylayabilirsiniz."));
                    wp_die();
                }
            }else if($status == "swap-merchant"){
                if(get_post_status($post_id) == "payment") {
                    sutore_merchant_system($product->get_id(), "swap-merchant", array("swap_product_id" => $merchant_product_id));
                }else{
                    wp_send_json(array("status" => false, "message" => "Sadece satış onayı bekleyen ürünlerin satıcısını değiştirebilirsiniz."));
                    wp_die();
                }
            }else if($status == "expired") {
                if(get_post_status($post_id) == "publish" || get_post_status($post_id) == "draft" || get_post_status($post_id) == "chargeback" || get_post_status($post_id) == "not-sale") {
                    sutore_merchant_system($product->get_id(), "expired");
                }else{
                    wp_send_json(array("status" => false, "message" => "Satılmış ürün süresi doldu olarak güncellenemez."));
                    wp_die();
                }
            }else if($status == "chargeback") {
                if(get_post_status($post_id) == "sold" || get_post_status($post_id) == "confirmed" || get_post_status($post_id) == "shipped-to-sutore" || get_post_status($post_id) == "arrived-to-sutore" || get_post_status($post_id) == "not-sale") {
                    sutore_merchant_system($product->get_id(), "chargeback");
                }else{
                    wp_send_json(array("status" => false, "message" => "Iade edildi olarak güncellenemez."));
                    wp_die();
                }
            }else if($status == "pre-order") {
                $product_author_id = get_post_field('post_author', $post_id);
                $merchant_level = get_user_meta($product_author_id, 'merchant_level', true);
                if(($merchant_level == 5 || current_user_can('administrator')) && (get_post_status($post_id) == "sold" || get_post_status($post_id) == "payment")) {
                    sutore_merchant_system($product->get_id(), "pre-order");
                }else{
                    wp_send_json(array("status" => false, "message" => "Ön sipariş olarak güncellenemez."));
                    wp_die();
                }
            }

        }

        $update_taxonomy = 'pa_beden-numara';
        $get_terms_args = array('taxonomy' => $update_taxonomy, 'fields' => 'ids', 'hide_empty' => false,);
        $update_terms = get_terms($get_terms_args);
        wp_update_term_count_now($update_terms, $update_taxonomy);

        $message = "Ürün başarıyla güncellendi.";
        wp_send_json(array("status" => true, "message" => $message));
        wp_die();
    }
}
?>
