<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_update_user_data', 'update_user_data');
//add_action('wp_ajax_nopriv_update_user_data', 'update_user_data');


function update_user_data(){
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
        $email = $user->user_email;
        $phone = "+90".get_user_meta($user_id, 'billing_phone_account', true);
        $user_sms_code = get_user_meta($user_id, "user_sms_code", true);
        $user_sms_code_expire = get_user_meta($user_id, "user_sms_code_expire", true);
        $sms_code = sanitize_text_field($_POST["sms_code"]);
        $rate_limit_key = 'update_user_data_rate_limit';
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
            wp_update_user(array("ID" => $user_id, "first_name" => sanitize_text_field($_POST["user_name"]), "last_name" => sanitize_text_field($_POST["user_lastname"]), "user_email" => sanitize_text_field($_POST["user_email"])));

//            if(!phone_is_used(sanitize_text_field($_POST["user_phone"]))){
//                wp_send_json(array("status" => false, "message" => "Girmiş olduğunuz telefon numarası sistemde kullanılmaktadır."));
//            }else {
//                update_user_meta($user_id, "billing_phone_account", sanitize_text_field($_POST["user_phone"]));
//            }

            //if(!phone_is_used(sanitize_text_field($_POST["user_phone"]))){
                //wp_send_json(array("status" => false, "message" => "Bir hata oluştu. Lütfen daha sonra tekrar deneyiniz."));
            //}

            if($phone != sanitize_text_field($_POST["user_phone"]) || $email != sanitize_text_field($_POST["user_email"])){
                $new_phone = "+90".sanitize_text_field($_POST["user_phone"]);
                $new_email = sanitize_text_field($_POST["user_email"]);
                sutore_iys_send([$email, $phone], "RET");
                update_user_meta( $user_id, 'marketing_consent', '0' );

                sutore_iys_send([$new_email, $new_phone], "ONAY");
                update_user_meta( $user_id, 'marketing_consent', '1' );
            }

            update_user_meta($user_id, "billing_phone_account", sanitize_text_field($_POST["user_phone"]));

            if ( isset( $_POST['user_campaing'] ) ) {
                sutore_iys_send([$email, $phone], "ONAY");
                update_user_meta( $user_id, 'marketing_consent', '1' );
            } else {
                sutore_iys_send([$email, $phone], "RET");
                update_user_meta( $user_id, 'marketing_consent', '0' );
            }

            $message = "Hesap bilgileriniz başarıyla güncellendi.";

            wp_send_json(array("status" => true, "message" => $message, "reload" => true));
            wp_die();
        }
    }
}
?>
