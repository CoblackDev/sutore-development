<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_delete_user_account', 'delete_user_account');
//add_action('wp_ajax_nopriv_delete_user_account', 'delete_user_account');


function delete_user_account(){
    if (!is_user_logged_in()) {
        wp_send_json(array("status" => false, "message" => "Lütfen giriş yapın."));
        wp_die();
    }
    if ( ! isset( $_POST['merchant_field_nonce'] ) || ! wp_verify_nonce( $_POST['merchant_field_nonce'], 'sutore_merchant_nonce' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else {
        //wp_send_json(array("status" => false, "message" => "HATA. Lütfen sayfayı yenileyerek tekrar deneyin."));
        //wp_die();
        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        $user_sms_code = get_user_meta($user_id, "user_sms_code", true);
        $user_sms_code_expire = get_user_meta($user_id, "user_sms_code_expire", true);
        $sms_code = sanitize_text_field($_POST["sms_code"]);
        $new_password = sanitize_text_field($_POST["new_password"]);
        $new_password_repeat = sanitize_text_field($_POST["new_password_repeat"]);
        // Rate limiter için kullanıcıya özel meta key kullanalım
        $rate_limit_key = 'delete_account_rate_limit';
        $rate_limit_attempts = (int) get_user_meta($user_id, $rate_limit_key, true);
        $max_attempts = 3;
        $rate_limit_window = 15 * MINUTE_IN_SECONDS;

        $last_attempt_time = (int) get_user_meta($user_id, $rate_limit_key . '_last', true);

        if ($rate_limit_attempts >= $max_attempts) {
            if (current_time('timestamp') - $last_attempt_time < $rate_limit_window) {
                wp_send_json(array("status" => false, "message" => "Çok fazla başarısız deneme yaptınız. Lütfen birkaç dakika sonra tekrar deneyiniz."));
                wp_die();
            } else {
                delete_user_meta($user_id, $rate_limit_key);
                delete_user_meta($user_id, $rate_limit_key . '_last');
                $rate_limit_attempts = 0;
                $last_attempt_time = 0;
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
            // Doğru kod girildi, rate limit'i sıfırla/kaldır
            delete_user_meta($user_id, $rate_limit_key);
            delete_user_meta($user_id, $rate_limit_key . '_last');

            $cquery = new WP_Query( array(
                'posts_per_page' => -1,
                'post_type' => array("product_variation"),
                'author' => get_current_user_id(),
                'post_status' => array("pending","publish","private","draft"),
            ) );
            if ( $cquery->have_posts() ) {
                while ( $cquery->have_posts() ) {
                    $cquery->the_post();
                    $product_id = sanitize_text_field(get_the_ID());
                    $childof = get_post_meta($product_id,"child_of",true);
                    $term_id = get_post_meta($product_id,"term_id",true);
                    $variation = wc_get_product($product_id);
                    //add_product_to_system($childof, (int) $variation->get_id(),$term_id,true);
                    sutore_merchant_system($product_id,"delete");
                    wp_delete_post(get_the_ID(),true);
                }
            }
        }

            $email = $user->user_email;
            $phone = "+90".get_user_meta($user_id, 'billing_phone_account', true);
            sutore_iys_send([$email, $phone], "RET");
            update_user_meta( $user_id, 'marketing_consent', '0' );
            wp_set_password("deleting", $user_id);
            wp_delete_user( $user_id);
            $message = "Sutore.com hesabınız başarıyla silindi.";
            wp_send_json(array("status" => true, "message" => $message, "reload" => true));
            wp_die();
        }
}

?>