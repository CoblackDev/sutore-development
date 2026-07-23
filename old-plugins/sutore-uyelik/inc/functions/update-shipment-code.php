<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_update_shipment_code', 'update_shipment_code');
//add_action('wp_ajax_nopriv_update_product_price', 'update_product_price');

function update_shipment_code(){
    if (  ! isset( $_POST['product_shipment_code'] ) || ! isset( $_POST['product_id'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else{
        $post_author_id = get_post_field( 'post_author', sanitize_text_field($_POST['product_id']) );
        $post_id = sanitize_text_field($_POST['product_id']);

        if(get_current_user_id() == $post_author_id) {
            $order_id = get_post_meta($post_id,"product_sold_order_id",true);
            $order = wc_get_order( $order_id );
            $billing_phone  = $order ? $order->get_billing_phone() : '';

            $productId = sanitize_text_field($_POST['product_id']);
            $productShipmentCode = sanitize_text_field($_POST['product_shipment_code']);

//            if(check_duplicate_shipment_code($productShipmentCode) == true){
//                wp_send_json(array("status" => false, "message" => "Girmiş olduğunuz kargo takip kodu daha önce kullanılmış. Lütfen yeni bir kargo takip kodu giriniz."));
//                wp_die();
//            }

            //delete_post_meta($productId,"pre_order");
            wp_update_post(array("ID" => $productId, "post_status" =>  "shipped-to-sutore"));
            update_post_meta($productId, "shipment_code", $productShipmentCode);


            if (!empty($billing_phone)) {
                netgsm_send_sms(str_replace("+90","",$billing_phone), "$order_id numaralı sutore.com siparişinizdeki ".fix_variation_title(str_replace("&#8211;","",get_the_title($post_id)))." ürününüz satıcı tarafından doğrulama için kontrol merkezimize gönderilmiştir.");
            }
            netgsm_send_sms(str_replace("+90", "", get_user_meta($post_author_id, "billing_phone_account", true)), fix_variation_title(str_replace("&#8211;","",get_the_title($_POST['product_id']))) . " ürününüzü merkezimize gönderdiniz. Yurtiçi Kargo gönderi takip bağlantısı: http://yurti.ci/".$productShipmentCode." ");

            wp_send_json(array("status" => true, "message" => "Ürününüzü merkezimize gönderdiniz."));
            wp_die();
        }else{
            wp_send_json(array("status" => false, "message" => "Geçersiz işlem."));
            wp_die();
        }
    }
}

?>