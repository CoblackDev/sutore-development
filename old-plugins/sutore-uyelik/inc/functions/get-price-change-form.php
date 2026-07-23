<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_get_price_change_form', 'get_price_change_form');
//add_action('wp_ajax_nopriv_get_price_change_form', 'get_price_change_form');

function get_price_change_form(){
    if (!is_user_logged_in()) {
        wp_send_json(array("status" => false, "message" => "Lütfen giriş yapın."));
        wp_die();
    }

    if (  ! isset( $_POST['product_id'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else{
        $product_id = sanitize_text_field($_POST['product_id']);
		$product = wc_get_product($product_id);
        $post_author_id = (int) get_post_field('post_author', $product_id);
        $current_user_id = get_current_user_id();
        $can_manage_all = current_user_can('administrator') || current_user_can('shop_manager');
        if (!$current_user_id || (!$can_manage_all && $current_user_id !== $post_author_id)) {
            wp_send_json(array("status" => false, "message" => "Yetkisiz işlem."));
            wp_die();
        }
        $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
		$guvence_bedeli =  (int) get_option("guvence_bedeli",true);

        //print_r($variations);
        $merchant_id = get_current_user_id();
        $productId = get_post_meta($product_id,"child_of",true);
        $termId = get_post_meta($product_id,"term_id",true);
        //$minPrice = get_product_min_price($productId, $termId);
        //$renderPrice = $minPrice["price"] != null ? wc_price($minPrice["price"]) : "-";

        $term = get_term_by("id",$termId,"pa_beden-numara");
        $current_on_sale = wc_get_product(find_matching_product_variation_id($productId, array("attribute_pa_beden-numara" => $term->slug)));

        if(!$current_on_sale){
            $minSalePrice = "-";
            $minPrice = 0;
        }else{
			$price = $current_on_sale->get_price();
            $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));
            $minSalePrice = wc_price($basePrice);
            $minPrice = $basePrice;
        }

        $html = null;
        $html .= "<p style='margin-bottom: 15px;'><small>En Düşük Fiyat: <strong>".$minSalePrice."</strong></small><small style='margin-left:5px;'>Net Kazancınız: <strong class='net-gross'>-</strong></small></p>";
        $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><input type="text" class="input-text fl-input"  id="product_price" placeholder="Fiyat" value=""><input type="button" class="button"  id="first_place_price" value="İlk Sıraya Geç"></div></span></p>';

        $user = wp_get_current_user(); // The current user
        $user_state = get_user_meta($user->ID,"account_city",true);
        $is_fast = get_post_meta($product_id,"fast_shipment",true) ? "checked" : null;
        $is_int = get_post_meta($product_id,"has_invoice",true) ? "checked" : null;
        if($user_state == "TR34" && !in_array(get_user_meta($user->ID,"merchant_level",true), array(0,1,6))) {
            $html .= "<p style='margin-bottom: 15px; margin-top: 15px; text-align: left;'>Ürünü satıldığı gün, İstanbul’da kuryeye teslim edebileceksiniz işaretleyiniz: </p>";
            $html .= "<p style='text-align:left'>";
            $html .= '<input type="checkbox" id="fast_shipment" name="fast_shipment" value="1" '.$is_fast.'>';
            $html .= '<label for="fast_shipment">Hızlı Kargo</label><br>';
            $html .= "</p>";
        }

        $html .= "<p style='margin-bottom: 15px; margin-top: 15px; text-align: left;'>Aşağıdaki koşulların karşılandığını taahhüt ediyorsanız işaretleyiniz:</p>";
        $html .= "<ul style='margin-bottom: 15px; margin-top: 15px; text-align: left; margin-left: 20px'><li>Ürünün ilk alım (retail) faturasının veya sipariş ekran görüntüsünün mevcut olduğunu (tek başına fiş, slip yeterli değildir)</li><li>Ürün satıldığında ilgili faturayı veya ekran görüntüsünü tüm bilgiler okunabilecek şekilde matbu, basılı olarak ürünle birlikte merkezimize göndereceğinizi</li><li>Ürünün gümrük mevzuatına uygun olduğunu</li></ul>";
        $html .= "<p style='text-align:left'>";
        $html .= '<input type="checkbox" id="has_invoice" name="has_invoice" value="1" '.$is_int.'>';
        $html .= '<label for="has_invoice">Uluslararası Kargo</label><br>';
        $html .= "</p>";

        $html .= "<p><small>Ürününüz hizmet ve güvence bedeli eklenmiş olarak listelenecektir.</small></p>";
        $firstPlacePrice = $minPrice - 25;

        wp_send_json(array("status" => true, "html" => $html, "min" => $firstPlacePrice, "tax_percent" => get_merchant_commision($merchant_id), "release_price" => (int) get_field("liste_fiyati",$productId) * 20));
        wp_die();
    }
}

?>
