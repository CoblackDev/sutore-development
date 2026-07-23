<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_update_merchant_data', 'update_merchant_data');
//add_action('wp_ajax_nopriv_update_merchant_data', 'update_merchant_data');


function update_merchant_data(){
    if (!is_user_logged_in()) {
        wp_send_json(array("status" => false, "message" => "Lütfen giriş yapın."));
        wp_die();
    }
    if ( ! isset( $_POST['merchant_field_nonce'] ) || ! wp_verify_nonce( $_POST['merchant_field_nonce'], 'sutore_merchant_nonce' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else {
        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        $user_sms_code = get_user_meta($user_id, "user_sms_code", true);
        $user_sms_code_expire = get_user_meta($user_id, "user_sms_code_expire", true);
        $sms_code = sanitize_text_field($_POST["sms_code"]);
        $rate_limit_key = 'update_merchant_data_rate_limit';
        $rate_limit_attempts = (int) get_user_meta($user_id, $rate_limit_key, true);
        $max_attempts = 3;
        $rate_limit_window = 15 * MINUTE_IN_SECONDS;
        $last_attempt_time = (int) get_user_meta($user_id, $rate_limit_key . '_last', true);

        if ($rate_limit_attempts >= $max_attempts) {
            if (current_time('timestamp') - $last_attempt_time < $rate_limit_window) {
                wp_send_json(array("status" => false, "message" => "Çok fazla hatalı deneme yaptınız. Lütfen 15 dakika sonra tekrar deneyin."));
                wp_die();
            } else {
                delete_user_meta($user_id, $rate_limit_key);
                delete_user_meta($user_id, $rate_limit_key . '_last');
                $rate_limit_attempts = 0;
            }
        }

        if (current_time("timestamp") >= $user_sms_code_expire) {
            update_user_meta($user_id, $rate_limit_key, $rate_limit_attempts + 1);
            update_user_meta($user_id, $rate_limit_key . '_last', current_time('timestamp'));
            wp_send_json(array("status" => false, "message" => "Belirlenen süre içerisinde kodu girmediniz. Lütfen tekrar deneyin."));
            wp_die();
        } else if ($user_sms_code != $sms_code) {
            update_user_meta($user_id, $rate_limit_key, $rate_limit_attempts + 1);
            update_user_meta($user_id, $rate_limit_key . '_last', current_time('timestamp'));
            wp_send_json(array("status" => false, "message" => "Hatalı SMS kodu. Lütfen tekrar deneyin."));
            wp_die();
        } else {
            delete_user_meta($user_id, $rate_limit_key);
            delete_user_meta($user_id, $rate_limit_key . '_last');
            delete_user_meta($user_id, "user_sms_code");
            delete_user_meta($user_id, "user_sms_code_expire");
            update_user_meta($user_id, "account_name", sanitize_text_field($_POST["account_name"]));
            update_user_meta($user_id, "account_lastname", sanitize_text_field($_POST["account_lastname"]));
            update_user_meta($user_id, "account_iban", sanitize_text_field($_POST["account_iban"]));
            update_user_meta($user_id, "account_tckno", sanitize_text_field($_POST["account_tckno"]));
            update_user_meta($user_id, "account_email", sanitize_text_field($_POST["account_email"]));
            update_user_meta($user_id, "account_phone", sanitize_text_field($_POST["account_phone"]));
            update_user_meta($user_id, "account_city", sanitize_text_field($_POST["account_city"]));
            update_user_meta($user_id, "account_state", sanitize_text_field($_POST["account_state"]));

            if (!in_array('merchant', (array)$user->roles)) {
                $u = new WP_User($user_id);
                if (!in_array('administrator', (array)$user->roles)) {
                    $u->set_role('merchant');
                }
                update_user_meta($user_id, "merchant_level", 1);
                $message = "Satıcı bilgileriniz başarıyla kaydedildi. Sayfayı yenileyerek satıcı alanına ulaşabilirsiniz.";
                $merchant_relaod = true;
            } else {
                $message = "Satıcı bilgileriniz başarıyla güncellendi.";
                $merchant_relaod = false;
            }

            wp_send_json(array("status" => true, "message" => $message, "merchant" => $merchant_relaod));
            wp_die();
        }
    }
}
?>