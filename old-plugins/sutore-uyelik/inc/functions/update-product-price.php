<?php
if (!defined('ABSPATH')) {
  exit;
}

add_action('wp_ajax_update_product_price', 'update_product_price');
//add_action('wp_ajax_nopriv_update_product_price', 'update_product_price');

function update_product_price(){
  if (  ! isset( $_POST['product_price'] ) || ! isset( $_POST['product_id'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
    wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
    wp_die();
  }else{
    $post_author_id = get_post_field( 'post_author', sanitize_text_field($_POST['product_id']) );

    if(get_post_status(sanitize_text_field($_POST['product_id'])) == "payment"){
      wp_send_json(array("status" => false, "message" => "Bir hata oluştu. Lütfen daha sonra tekrar deneyin."));
      wp_die();
    }


    $user_id = get_current_user_id();

    $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
    $guvence_bedeli =  (int) get_option("guvence_bedeli",true);
    $price = get_post_meta($_POST["product_id"],"_price",true);
    $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));


    if(get_post_meta($_POST['product_id'],"sutore_campaing_discount",true) && get_post_meta($_POST['product_id'],"sutore_campaing_merchant_discount",true) && !get_post_meta($_POST['product_id'],"campaing_response",true)){
      wp_send_json(array("status" => false, "message" =>  "Kampanya teklifine yanıt verilmeden ürün fiyatı güncellenemez."));
    }else if(get_post_meta($_POST['product_id'],"campaing_response",true) && sanitize_text_field($_POST['product_price']) > $basePrice){
      wp_send_json(array("status" => false, "message" =>  "Ürününüz kampanyaya dahil olduğu için fiyatını yükseltemezsiniz."));
    }


    if(current_time("timestamp") < get_user_meta($user_id,"disabled_account_until",true)){
      wp_send_json(array("status" => false, "message" =>  "Hesabınız ". date("d-m-Y",get_user_meta($user_id,"disabled_account_until",true))." tarihine kadar kısıtlanmıştır."));
      wp_die();
    }


    if(get_user_meta($user_id,"last_ajax_request",true)){
      $last_ajax_request = get_user_meta($user_id,"last_ajax_request",true);
      $account_disabled_count = get_user_meta($user_id,"disabled_account_count",true) ? get_user_meta($user_id,"disabled_account_count",true) : 0;

      if(current_time("timestamp") - $last_ajax_request < 3){
        update_user_meta($user_id,"last_ajax_request", current_time("timestamp"));
        update_user_meta($user_id,"disabled_account_until", current_time("timestamp") + 259200);
        update_user_meta($user_id,"disabled_account_count", $account_disabled_count + 1);

        if($account_disabled_count >= 0){
          netgsm_send_sms(str_replace("+90", "", get_user_meta($user_id, "billing_phone_account", true)), "sutore'nin yazılı izni olmadan screen scraping yazılımı kullandığınız tespit edilmiştir. Hesabınızın kalıcı olarak askıya alınmasını önlemek için bizimle iletişime geçmeniz gerekmektedir.");
          netgsm_send_sms("5543889207", $user_id.", bot ile işlem sağlamayı denedi. ". get_user_meta($user_id,"disabled_account_count",true));
          netgsm_send_sms("5323690927", $user_id.", bot ile işlem sağlamayı denedi. ". get_user_meta($user_id,"disabled_account_count",true));
        }
        wp_send_json(array("status" => false, "message" => "Bir hata oluştu. Lütfen daha sonra tekrar deneyin..."));
        wp_die();
      }

    }

    update_user_meta($user_id,"last_ajax_request", current_time("timestamp"));


    if(get_current_user_id() == $post_author_id) {

      $variation = wc_get_product(sanitize_text_field($_POST['product_id']));
      $price = sanitize_text_field($_POST['product_price']);

      if(sanitize_text_field($_POST['fast_shipment']) == "1"){
        update_post_meta($variation->get_id(),"fast_shipment", 1);
      }else{
        delete_post_meta($variation->get_id(),"fast_shipment");
      }

      if(sanitize_text_field($_POST['has_invoice']) == "1"){
        update_post_meta($variation->get_id(),"has_invoice", 1);
      }else{
        delete_post_meta($variation->get_id(),"has_invoice");
      }


      sutore_merchant_system($variation->get_id(),"add",array("price" => $price));

      wp_send_json(array("status" => true, "message" => "Ürün fiyatı başarıyla güncellendi."));
      wp_die();
    }else{
      wp_send_json(array("status" => false, "message" => "Geçersiz işlem."));
      wp_die();
    }
  }
}

?>
