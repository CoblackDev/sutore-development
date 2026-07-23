<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_update_user_password', 'update_user_password');
//add_action('wp_ajax_nopriv_update_user_password', 'update_user_password');


function update_user_password(){
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
        $new_password = sanitize_text_field($_POST["new_password"]);
        $new_password_repeat = sanitize_text_field($_POST["new_password_repeat"]);
        $rate_limit_key = 'update_password_rate_limit';
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
            wp_set_password($new_password, $user_id);
            $message = "Parolanız başarıyla güncellendi.";
            wp_send_json(array("status" => true, "message" => $message, "reload" => true));
            wp_die();
        }
    }

}

?>