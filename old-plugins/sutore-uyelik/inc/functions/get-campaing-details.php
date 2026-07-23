<?php
if (!defined('ABSPATH')) {
  exit;
}

add_action('wp_ajax_get_campaing_dialog', 'get_campaing_dialog');
//add_action('wp_ajax_nopriv_get_campaing_dialog', 'get_campaing_dialog');

function sutore_can_manage_product($product_id) {
  $post_author_id = (int) get_post_field('post_author', $product_id);
  $current_user_id = get_current_user_id();
  $can_manage_all = current_user_can('administrator') || current_user_can('shop_manager');

  if (!$current_user_id) {
    return false;
  }

  return $can_manage_all || $current_user_id === $post_author_id;
}

function get_campaing_dialog(){
  //print_r($_POST);
  if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
    wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
    wp_die();
  }else{
    $product_id = (int) sanitize_text_field($_POST["product_id"]);
    if (!sutore_can_manage_product($product_id)) {
      wp_send_json(array("status" => false, "message" => "Yetkisiz işlem."));
      wp_die();
    }
    $merchant_id = get_post_field( 'post_author', $product_id );
    $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
    $guvence_bedeli =  (int) get_option("guvence_bedeli",true);
    $price = get_post_meta($product_id,"_price",true);
    $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));

    wp_send_json(array("status" => true, "html" => "<p>Ürününüz için ".wc_price(get_post_meta($product_id,"sutore_campaing_merchant_discount",true))." 'lik bir indirim yapmayı kabul ettiğinizde, ".wc_price(get_post_meta($product_id,"sutore_campaing_discount",true))." TL tutarında bir kampanya bonusu kazanacaksınız. Böylece toplamda ".wc_price(get_post_meta($product_id,"sutore_campaing_discount",true) + get_post_meta($product_id,"sutore_campaing_merchant_discount",true))." TL'lik bir indirim uygulanacak ve ürününüz kampanya kapsamında daha fazla öne çıkarılacaktır.</p><p>&nbsp;</p><p>Teklif Sonrası Net Kazancınız: ".wc_price(merchant_net_paid($product_id,$merchant_id) - get_post_meta($product_id,"sutore_campaing_merchant_discount",true))." TL</p>"));
  }
}


add_action('wp_ajax_get_campaing_details', 'get_campaing_details');
//add_action('wp_ajax_nopriv_get_campaing_details', 'get_campaing_details');

function get_campaing_details(){
  //print_r($_POST);
  if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
    wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
    wp_die();
  }else{
    $product_id = (int) sanitize_text_field($_POST["product_id"]);
    if (!sutore_can_manage_product($product_id)) {
      wp_send_json(array("status" => false, "message" => "Yetkisiz işlem."));
      wp_die();
    }
    $merchant_id = get_post_field( 'post_author', $product_id );
    $date_format = get_option('date_format') . ' ' . get_option('time_format'); // WordPress tarih ve saat formatı
    $formatted_date = date_i18n($date_format, get_post_meta($product_id,"campaing_response_date",true));
    $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
    $guvence_bedeli =  (int) get_option("guvence_bedeli",true);
    $price = get_post_meta($product_id,"_price",true);
    $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));

      $html = '<table>';
      $html .= '<tbody>';
      $html .= '<tr><th colspan="2">Teklif Detayı</th></tr>';
      $html .= '<tr><td>Kabul Tarihi:</td><td>'.$formatted_date.'</td></tr>';
      $html .= '<tr><td>Onayladığınız Teklif Tutarı:</td><td>'.wc_price(get_post_meta($product_id,"sutore_campaing_merchant_discount",true)).'</td></tr>';
      $html .= '<tr><td>Kampanya Bonusu:</td><td>'.wc_price( (int) get_post_meta($product_id,"sutore_campaing_discount",true)).'</td></tr>';
      $html .= '<tr><td>Toplam İndirim Tutarı:</td><td>'.wc_price( (int) get_post_meta($product_id,"sutore_campaing_discount",true) + (int) get_post_meta($product_id,"sutore_campaing_merchant_discount",true)).'</td></tr>';
      $html .= '<tr><td>Teklif Sonrası Net Kazancınız:</td><td>'.wc_price( (int) merchant_net_paid($product_id,$merchant_id) - (int) get_post_meta($product_id,"sutore_campaing_merchant_discount",true)).'</td></tr>';
      $html .= '</tbody>';
      $html .= '</table>';
      wp_send_json(array("status" => true, "detail" => !in_array(get_post_status($product_id), array("publish","draft")) ? true : false, "html" => $html));
  }
}


add_action('wp_ajax_confirm_campaing', 'confirm_campaing');
//add_action('wp_ajax_nopriv_confirm_campaing', 'confirm_campaing');

function confirm_campaing(){
  //print_r($_POST);
  if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
    wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
    wp_die();
  }else{
    $product_id = (int) sanitize_text_field($_POST["product_id"]);
    if (!sutore_can_manage_product($product_id)) {
      wp_send_json(array("status" => false, "message" => "Yetkisiz işlem."));
      wp_die();
    }
    update_post_meta($product_id,"campaing_response", 1);
    update_post_meta($product_id,"campaing_response_date", current_time("timestamp"));
    update_post_meta($product_id, "expire_date", current_time("timestamp") + 3888000);
    sutore_merchant_system($product_id,"add");
    wp_send_json(array("status" => true, "message" => "Kampanya teklifini kabul ettiniz."));

  }
}


add_action('wp_ajax_reject_campaing', 'reject_campaing');
//add_action('wp_ajax_nopriv_reject_campaing', 'reject_campaing');

function reject_campaing(){
  //print_r($_POST);
  if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
    wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
    wp_die();
  }else{
    $product_id = (int) sanitize_text_field($_POST["product_id"]);
    if (!sutore_can_manage_product($product_id)) {
      wp_send_json(array("status" => false, "message" => "Yetkisiz işlem."));
      wp_die();
    }
    delete_post_meta($product_id,"sutore_campaing_discount");
    delete_post_meta($product_id,"sutore_campaing_merchant_discount");
    update_post_meta($product_id,"campaing_response", 0);
    update_post_meta($product_id,"campaing_response_date", current_time("timestamp"));
    update_post_meta($product_id, "expire_date", current_time("timestamp") + 3888000);
    wp_send_json(array("status" => true, "message" => "Kampanya teklifini reddettiniz."));

  }
}


?>
