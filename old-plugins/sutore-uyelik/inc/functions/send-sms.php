<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_send_sms', 'send_sms');
//add_action('wp_ajax_nopriv_send_sms', 'send_sms');


function send_sms(){
    //print_r($_POST);
    if (!is_user_logged_in()) {
        wp_send_json(array("status" => false, "message" => "Lütfen giriş yapın."));
        wp_die();
    }
    if ( ! isset( $_POST['merchant_field_nonce'] ) || ! wp_verify_nonce( $_POST['merchant_field_nonce'], 'sutore_merchant_nonce' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    } else {
        $user_id = get_current_user_id();
        $user = get_user_by("ID",$user_id);
        $account_phone = get_user_meta($user_id,"billing_phone_account",true);

        if(!isset($_POST["doing"]) && !isset($_POST["new_password"]) && !isset($_POST["account_phone"]) && $account_phone != $_POST["user_phone"]  && !phone_is_used($_POST["user_phone"])) {
            wp_send_json(array("status" => false, "message" => "Girmiş olduğunuz telefon numarası sistemde kayıtlıdır."));
            wp_die();
        }else{
            if (!get_user_meta($user_id, "billing_phone_account", true)) {
                update_user_meta($user_id, "billing_phone_account", sanitize_text_field($_POST["user_phone"]));
                $expire = current_time("timestamp") + 300;
                $sms_code = random_int(100000,999999);
                update_user_meta($user_id,"user_sms_code",$sms_code);
                update_user_meta($user_id,"user_sms_code_expire",$expire);
                netgsm_send_sms(sanitize_text_field($_POST["user_phone"]),"sutore.com doğrulama kodunuz: $sms_code. Güvenliğiniz için kodunuzu kimseyle paylaşmayınız.");
                wp_send_json(array("sms" => "", "expire" => $expire));
                wp_die();
            }
        }

        if(isset($_POST["new_password"]) && isset($_POST["new_password_repeat"]) && isset($_POST["current_password"])) {

            if (isset($_POST["current_password"]) && !wp_check_password(sanitize_text_field($_POST["current_password"]), $user->data->user_pass, $user->ID)) {
                wp_send_json(array("status" => false, "message" => "Mevcut parolanız hatalı."));
                wp_die();
            } elseif (isset($_POST["new_password"]) && isset($_POST["new_password_repeat"]) && sanitize_text_field($_POST["new_password_repeat"]) != sanitize_text_field($_POST["new_password"])) {
                wp_send_json(array("status" => false, "message" => "Girmiş olduğunuz parolalar birbiriyle eşleşmiyor."));
                wp_die();
            } elseif (isset($_POST["new_password"]) && isset($_POST["new_password_repeat"]) && !isPasswordStrong(sanitize_text_field($_POST["new_password"]))) {
                wp_send_json(array("status" => false, "message" => "Girmiş olduğunuz parola en az 8 karakter uzunluğunda, bir büyük, bir küçük, bir sayı ve bir karakterden oluşmalıdır."));
                wp_die();
            }
        }else if(isset($_POST["doing"]) && $_POST["doing"] == "delete_user_account") {
            if (isset($_POST["current_password"]) && !wp_check_password(sanitize_text_field($_POST["current_password"]), $user->data->user_pass, $user->ID)) {
                wp_send_json(array("status" => false, "message" => "Mevcut parolanız hatalı."));
                wp_die();
            }
        }else {

            if (isset($_POST["current_password"]) && !wp_check_password(sanitize_text_field($_POST["current_password"]), $user->data->user_pass, $user->ID)) {
                wp_send_json(array("status" => false, "message" => "Mevcut parolanız hatalı."));
                wp_die();
            } elseif (isset($_POST["account_tckno"]) && !isTcValid(sanitize_text_field($_POST["account_tckno"]))) {
                wp_send_json(array("status" => false, "message" => "TC kimlik numarası geçerli değil."));
                wp_die();
            } elseif (isset($_POST["account_iban"]) && !isValidIBAN(sanitize_text_field($_POST["account_iban"]))) {
                wp_send_json(array("status" => false, "message" => "IBAN numarası geçerli değil."));
                wp_die();
            } elseif (isset($_POST["account_phone"]) && strlen($_POST['account_phone']) != 10) {
                wp_send_json(array("status" => false, "message" => "Lütfen telefon numaranızı 10 haneli formatta girin."));
                wp_die();
            } elseif (isset($_POST["user_phone"]) && !isTelValid(sanitize_text_field($_POST["user_phone"]))) {
                wp_send_json(array("status" => false, "message" => "Telefon numarası geçerli değil."));
                wp_die();
            } elseif (isset($_POST["account_email"]) && !filter_var($_POST["account_email"], FILTER_VALIDATE_EMAIL)) {
                wp_send_json(array("status" => false, "message" => "Girmiş olduğunuz e-mail adresi uygun formatta değildir."));
                wp_die();
            } elseif (isset($_POST["user_phone"]) && strlen($_POST['user_phone']) != 10) {
                wp_send_json(array("status" => false, "message" => "Lütfen telefon numaranızı 10 haneli formatta girin."));
                wp_die();
            } elseif (!isset($_POST["account_phone"]) && $account_phone != $_POST["user_phone"] && !phone_is_used($_POST["user_phone"])) {
                wp_send_json(array("status" => false, "message" => "Girmiş olduğunuz telefon numarası sistemde kullanılmaktadır."));
                wp_die();
            } elseif (isset($_POST["user_email"]) && !filter_var($_POST["user_email"], FILTER_VALIDATE_EMAIL)) {
                wp_send_json(array("status" => false, "message" => "Girmiş olduğunuz e-mail adresi uygun formatta değildir."));
                wp_die();
            } elseif (isset($_POST["user_email"]) && sanitize_text_field($_POST["user_email"]) != $user->user_email && email_exists(sanitize_text_field($_POST["user_email"]))) {
                wp_send_json(array("status" => false, "message" => "Girmiş olduğunuz e-mail adresi sistemde kayıtlıdır."));
                wp_die();
            }
        }


        $expire = current_time("timestamp") + 300;
        $sms_code = random_int(100000,999999);
        update_user_meta($user_id,"user_sms_code",$sms_code);
        update_user_meta($user_id,"user_sms_code_expire",$expire);
        netgsm_send_sms($account_phone,"sutore.com doğrulama kodunuz: $sms_code. Güvenliğiniz için kodunuzu kimseyle paylaşmayınız.");
        wp_send_json(array("sms" => "", "expire" => $expire));
        wp_die();
    }


}

?>