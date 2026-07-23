<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_confirm_shipment', 'confirm_shipment');
//add_action('wp_ajax_nopriv_confirm_shipment', 'confirm_shipment');

function confirm_shipment(){
    if (!is_user_logged_in()) {
        wp_send_json(array("status" => false, "message" => "Lütfen giriş yapın."));
        wp_die();
    }

    if (  ! isset( $_POST['product_id'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else{
        $product_id = sanitize_text_field($_POST['product_id']);
        $post_author_id = (int) get_post_field('post_author', $product_id);
        $current_user_id = get_current_user_id();
        $can_manage_all = current_user_can('administrator') || current_user_can('shop_manager');
        if (!$current_user_id || (!$can_manage_all && $current_user_id !== $post_author_id)) {
            wp_send_json(array("status" => false, "message" => "Yetkisiz işlem."));
            wp_die();
        }
        //print_r($variations);
        $productId = get_post_meta($product_id,"child_of",true);
        $termId = get_post_meta($product_id,"term_id",true);
        $minPrice = get_product_min_price($productId, $termId);
        $renderPrice = $minPrice["price"] != null ? wc_price($minPrice["price"]) : "-";

        $html = null;
        $html .= "<p style='text-align: left;'>Ürününüzü 48 saat içerisinde çift kutu kullanarak Yurtiçi Kargoya teslim etmeniz gerekmektedir. Son teslim tarihinden sonra yaptığınız gönderimlerde ve ürün/kutu üzerinde önceden belirtmemiş olduğunuz bir hasarın bulunması halinde ücretsiz kargo avantajından yararlanamayacağınızı unutmayınız.</p>";
        $html .= '<p style="margin:10px 0px;" class="form-row form-row-wide validate-required"><b>Yurtiçi Kargo müşteri kodu: 981317779</b></p>';
        $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><input type="text" class="input-text fl-input"  id="product_shipment_code" placeholder="Kargo Takip No" pattern="[0-9]{12}" value=""></div></span></p>';

        wp_send_json(array("status" => true, "html" => $html, "tax_percent" => 3));
        wp_die();
    }
}


add_action('wp_ajax_confirm_sale', 'confirm_sale');
//add_action('wp_ajax_nopriv_confirm_sale', 'confirm_sale');

function confirm_sale(){
    if (!is_user_logged_in()) {
        wp_send_json(array("status" => false, "message" => "Lütfen giriş yapın."));
        wp_die();
    }

    if (  ! isset( $_POST['product_id'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else{
        $post_id = sanitize_text_field($_POST["product_id"]);
        $post_author_id = (int) get_post_field( 'post_author', $post_id );
        $current_user_id = get_current_user_id();
        $can_manage_all = current_user_can('administrator') || current_user_can('shop_manager');
        if (!$current_user_id || (!$can_manage_all && $current_user_id !== $post_author_id)) {
            wp_send_json(array("status" => false, "message" => "Yetkisiz işlem."));
            wp_die();
        }
        $order_id = get_post_meta($post_id,"product_sold_order_id",true);
        $order = wc_get_order( $order_id );
        $billing_phone  = $order->get_billing_phone();
        $title = fix_variation_title(str_replace("&#8211;","",get_the_title($post_id)));

        update_post_meta($_POST['product_id'],"product_cargo_expire",current_time("timestamp") + 172800);
        wp_update_post(array("ID" => $post_id, "post_status" => "confirmed" ));
        netgsm_send_sms(str_replace("+90","",$billing_phone), "$order_id numaralı sutore.com siparişinizdeki ".fix_variation_title(str_replace("&#8211;","",get_the_title($post_id)))." ürününüzün satışı satıcı tarafından onaylanmıştır ve doğrulama için kontrol merkezimize gönderilecektir.");
        
        if(get_post_meta($post_id, "imported", true) != 1){
            netgsm_send_sms(str_replace("+90","",get_user_meta($post_author_id,"billing_phone_account",true)), $title." satışınızı onayladınız. Ürününüzü 48 saat içerisinde çift kutu kullanarak 981317779 müşteri koduyla Yurtiçi Kargo'ya teslim etmeniz gerekmektedir.");
        }

        if(get_post_meta($order_id,"sutore_shipment_type",true) == "international"){
            netgsm_send_sms(str_replace("+90","",get_user_meta($post_author_id,"billing_phone_account",true)), "UYARI: ".$title . " yurt dışına satılmıştır. Ürününüzü (basılı ve tüm bilgiler okunabilecek şekilde) ilk alım faturasıyla veya sipariş ekran görüntüsüyle birlikte merkezimize göndermeniz gerekmektedir."); 
        }

        wp_send_json(array("status" => true, "message" => "Satışınızı onayladınız."));
        wp_die();
    }
}

?>