<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_update_billing_adress', 'update_billing_adress');
//add_action('wp_ajax_nopriv_update_billing_adress', 'update_billing_adress');


function update_billing_adress(){
    if (!is_user_logged_in()) {
        wp_send_json(array("status" => false, "message" => "Lütfen giriş yapın."));
        wp_die();
    }
    if ( ! isset( $_POST['woocommerce-edit-address-nonce'] ) || ! wp_verify_nonce( $_POST['woocommerce-edit-address-nonce'], 'woocommerce-edit_address' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else {
        //print_r($_POST);
        $user_id = get_current_user_id();
        $user = wp_get_current_user();

        if ( $_POST['billing_tckno'] && !isTcValid($_POST['billing_tckno'])) {
            //wc_add_notice('Lütfen geçerli bir TC kimlik numarası girin.', 'error');
            wp_send_json(array("status" => false, "message" => "Lütfen geçerli bir TC kimlik numarası girin."));
        }

        if(empty($_POST['billing_first_name'])){
            wp_send_json(array("status" => false, "message" => "Ad alanı boş bırakılamaz."));
        }elseif(empty($_POST['billing_last_name'])){
            wp_send_json(array("status" => false, "message" => "Soyad alanı boş bırakılamaz."));
        }elseif(empty($_POST['billing_phone'])){
            wp_send_json(array("status" => false, "message" => "Telefon numarası alanı boş bırakılamaz."));
        }elseif(empty($_POST['billing_postcode'])){
            wp_send_json(array("status" => false, "message" => "Posta kodu alanı boş bırakılamaz."));
        }elseif(empty($_POST['billing_address_1'])){
            wp_send_json(array("status" => false, "message" => "Adres alanı boş bırakılamaz."));
        }

        if(isset($_POST['billing_country']) && $_POST['billing_country'] == "TR"){
            if(empty($_POST['billing_state'])) {
                wp_send_json(array("status" => false, "message" => "Lütfen il alanını doldurun."));
            }
            if(empty($_POST['billing_city'])) {
                wp_send_json(array("status" => false, "message" => "Lütfen ilçe alanını doldurun."));
            }
            if(strlen($_POST['billing_phone']) != 10){
                wp_send_json(array("status" => false, "message" => "Lütfen telefon numaranızı başında 0 olmadan girin."));
            }
            if(!isPostCodeValid(sanitize_text_field($_POST["billing_postcode"]))){
                wp_send_json(array("status" => false, "message" => "Geçersiz posta kodu."));
                wp_die();
            }
        }

        update_user_meta($user_id, "billing_first_name", sanitize_text_field($_POST["billing_first_name"]));
        update_user_meta($user_id, "billing_last_name", sanitize_text_field($_POST["billing_last_name"]));
        update_user_meta($user_id, "billing_country", sanitize_text_field($_POST["billing_country"]));
        update_user_meta($user_id, "billing_state", sanitize_text_field($_POST["billing_state"]));
        update_user_meta($user_id, "billing_city", sanitize_text_field($_POST["billing_city"]));
        update_user_meta($user_id, "billing_address_1", sanitize_text_field($_POST["billing_address_1"]));
        update_user_meta($user_id, "billing_address_2", sanitize_text_field($_POST["billing_address_2"]));
        update_user_meta($user_id, "billing_postcode", sanitize_text_field($_POST["billing_postcode"]));
        update_user_meta($user_id, "billing_tckno", sanitize_text_field($_POST["billing_tckno"]));
        update_user_meta($user_id, "billing_phone", sanitize_text_field($_POST["billing_phone"]));
        wp_send_json(array("status" => true, "message" => "Adres bilgileriniz başarıyla güncellenmiştir."));
        wp_die();
    }
}
