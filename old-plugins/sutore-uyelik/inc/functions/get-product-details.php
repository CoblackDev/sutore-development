<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_get_product_details', 'get_product_details');
//add_action('wp_ajax_nopriv_get_product_details', 'get_product_details');

function get_product_details(){
    //print_r($_POST);
    if (!is_user_logged_in()) {
        wp_send_json(array("status" => false, "message" => "Lütfen giriş yapın."));
        wp_die();
    }
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else{

        if(isset($_POST['product_id'])) {
            $merchant_id = get_current_user_id();
            $productId = sanitize_text_field($_POST['product_id']);
            $termId = sanitize_text_field($_POST['term_id']);
            //echo $productId;
            $product = wc_get_product($productId);
            //$parentId = $product->get_parent_id();
        }
        //print_r($variations);
        $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
		$guvence_bedeli =  (int) get_option("guvence_bedeli",true);
        $html = null;
        $html .= ' <link rel="stylesheet"  href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@4.0/dist/fancybox.css"/>';
        if(isset($_POST['product_id'])) {
            $html .= "<h2>" . esc_html(get_the_title($productId)) . "</h2>";
        }else{
            $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><input type="text" class="input-text fl-input"  id="product_title" placeholder="Ürün adı" value=""></div></span></p>';
            $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><input type="text" class="input-text fl-input"  id="product_size" placeholder="Ürün bedeni" value=""></div></span></p>';
            $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><input type="text" class="input-text fl-input"  id="product_new_code" placeholder="Ürün kodu" value=""></div></span></p>';
        }
        if(isset($_POST["product_status"]) && $_POST["product_status"] == 1 ) {


            //$minPrice = get_product_min_price($productId, $termId);
            //$minSalePrice = $minPrice["price"];

            $term = get_term_by("id",$termId,"pa_beden-numara");
            $current_on_sale = wc_get_product(find_matching_product_variation_id($productId, array("attribute_pa_beden-numara" => $term->slug)));


            if(!$current_on_sale){
                $minSalePrice = "-";
            }else{
				$price = $current_on_sale->get_price();
                $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));
                $minSalePrice = wc_price($basePrice);
                $minPrice = $basePrice;
            }

            $html .= "<div>" . get_the_post_thumbnail($productId, "woocommerce_thumbnail") . "</div>";
            $html .= "<p style='margin-bottom: 15px;'><small>En Düşük Fiyat: <strong>".$minSalePrice."</strong></small><small style='margin-left:5px;'>Net Kazancınız: <strong class='net-gross'>-</strong></small></p>";
            $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><input type="number" class="input-text fl-input"  min="0" id="product_price" placeholder="Fiyat" value=""><input type="button" class="button"  id="first_place_price" value="İlk Sıraya Geç"></div></span></p>';
            $html .= "<p style='margin-bottom: 15px; margin-top: 15px; text-align: left;'>Üründe aşağıdaki kusurlardan biri bulunuyorsa işaretleyiniz:</p>";
            $html .= "<p style='text-align:left'>";
            $html .= '<input type="checkbox" id="no_box" name="product_def" value="1">';
            $html .= '<label for="no_box">Kutusu Yok</label><br>';
            $html .= '<input type="checkbox" id="damaged" name="product_def" value="1">';
            $html .= '<label for="damaged">Hasarlı Kutu</label><br>';
            $html .= '<input type="checkbox" id="missing" name="product_def" value="1">';
            $html .= '<label for="missing">Eksik Aksesuar</label><br>';
            $html .= '<input type="checkbox" id="damaged_product" name="product_def" value="1">';
            $html .= '<label for="damaged_product">Hasarlı Ürün</label><br>';
            $html .= '<input type="checkbox" id="tried_product" name="product_def" value="1">';
            $html .= '<label for="tried_product">Denenmiş Ürün</label><br>';
            $html .= "</p>";

            $user = wp_get_current_user(); // The current user
            $user_state = get_user_meta($user->ID,"account_city",true);
            if($user_state == "TR34" && !in_array(get_user_meta($user->ID,"merchant_level",true), array(0,1,6))) {
                $html .= "<p style='margin-bottom: 15px; margin-top: 15px; text-align: left;'>Ürünü satıldığı gün, İstanbul’da kuryeye teslim edebileceksiniz işaretleyiniz:</p>";
                $html .= "<p style='text-align:left'>";
                $html .= '<input type="checkbox" id="fast_shipment" name="fast_shipment" value="1">';
                $html .= '<label for="fast_shipment">Hızlı Kargo</label><br>';
                $html .= "</p>";
            }

            $html .= "<p style='margin-bottom: 15px; margin-top: 15px; text-align: left;'>Aşağıdaki koşulların karşılandığını taahhüt ediyorsanız işaretleyiniz:</p>";
            $html .= "<ul style='margin-bottom: 15px; margin-top: 15px; text-align: left; margin-left: 20px'><li>Ürünün ilk alım (retail) faturasının veya sipariş ekran görüntüsünün mevcut olduğunu (tek başına fiş, slip yeterli değildir)</li><li>Ürün satıldığında ilgili faturayı veya ekran görüntüsünü tüm bilgiler okunabilecek şekilde matbu, basılı olarak ürünle birlikte merkezimize göndereceğinizi</li><li>Ürünün gümrük mevzuatına uygun olduğunu</li></ul>";
            $html .= "<p style='text-align:left'>";
            $html .= '<input type="checkbox" id="has_invoice" name="has_invoice" value="1">';
            $html .= '<label for="has_invoice">Uluslararası Kargo</label><br>';
            $html .= "</p>";

            $html .= "<p><small>Ürününüz hizmet ve güvence bedeli eklenmiş olarak listelenecektir.</small></p>";
        }else{
            $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><input type="file" name="product_photos[]" accept="image/png, image/jpeg" style="border: 2px dotted black; padding: 15px; width: 100%;" class="input-text fl-input"  id="product_photos" placeholder="Ürün fotoğrafları (En az 3 adet)" value="Ürün Görselleri" multiple></div></span></p>';
            $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><p style="text-align: left;margin-bottom: 15px;font-size: small;text-decoration: underline;"><a href="#" onclick="document.getElementById(\'example\').click();"  style="color:dodgerblue">Yükleyeceğim ürün görselleri nasıl olmalı?</a></p></div></span></p>';
            $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><p style="display:none;">
      <a data-fancybox="gallery" id="example" data-src="https://guryaz.com/wp-content/uploads/2021/12/1.jpeg"></a>
      <a data-fancybox="gallery" data-src="https://guryaz.com/wp-content/uploads/2021/12/2.jpeg"></a>
      <a data-fancybox="gallery" data-src="https://guryaz.com/wp-content/uploads/2021/12/3.jpeg"></a>
      <a data-fancybox="gallery" data-src="https://guryaz.com/wp-content/uploads/2021/12/4.jpeg"></a>
      <a data-fancybox="gallery" data-src="https://guryaz.com/wp-content/uploads/2021/12/5.jpeg"></a>
    </p></p></div></span></p>';
            $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><input type="number" class="input-text fl-input"  id="product_price" placeholder="Fiyat" value=""></div></span></p>';
            $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><input type="number" class="input-text fl-input"  id="product_condition" placeholder="Ürün kondisyonu (10 üzerinden puanlayın)" value=""></div></span></p>';
            $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><select id="product_box_condition"><option value="">Kutu durumu seçin</option><option value="1">İyi durumda</option><option value="2">Hasarlı</option><option value="3">Mevcut değil</option></select></div></span></p>';
            $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><textarea class="input-text fl-input" id="product_desc" placeholder="Ürün açıklaması"></textarea></div></span></p>';

        }
        $html .= '<script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@4.0/dist/fancybox.umd.js"></script>';
        $firstPlacePrice = $minPrice - 25;

        if($firstPlacePrice < 0){
            $firstPlacePrice = "-";
        }

        wp_send_json(array("status" => true, "html" => $html, "min" => $firstPlacePrice, "tax_percent" => get_merchant_commision($merchant_id), "release_price" => (int) get_field("liste_fiyati",$productId) * 20));
        wp_die();
    }
}


function set_product_sale($product_id, $term_id, $parent_id){

    //$minPrice = get_product_min_price($product_id, $term_id,false,false);

    $term = get_term_by("id",$term_id,"pa_beden-numara");
    //echo $term->slug;
    $variation_id = find_matching_product_variation_id($parent_id, array("attribute_pa_beden-numara" => $term->slug));


    if(empty($variation_id)){
        wp_update_post(array("ID" => $product_id, "post_type" => "product_variation", "post_parent" => $parent_id, "menu_order" => 55, "post_status" => "publish"));
        return "gg";
    }else{
        $vproduct = wc_get_product( $variation_id );
        $product = wc_get_product( $product_id );
        $menu_order = $vproduct->get_menu_order();

        if($vproduct->get_price() > $product->get_price()){
            wp_update_post(array("ID" => $vproduct->get_id(), "post_type" => "product", "post_parent" => 0, "menu_order" => 0, "post_status" => "publish"));
            wp_update_post(array("ID" => $product_id, "post_type" => "product_variation", "post_parent" => $parent_id, "menu_order" => $menu_order, "post_status" => "publish"));
            $vproduct->save();
            $product->save();
            return "yenisi daha ucuz";
        }else{
            wp_update_post(array("ID" => $product_id, "post_type" => "product", "post_parent" => 0, "menu_order" => 0, "post_status" => "publish"));
            wp_update_post(array("ID" => $vproduct->get_id(), "post_type" => "product_variation", "post_parent" => $parent_id, "menu_order" => $menu_order, "post_status" => "publish"));
            $vproduct->save();
            $product->save();
            return "yenisi daha pahali";
        }

        return "updated";
    }

    //echo $variation_id;
    return $variation_id;

    if($minPrice != null){
        // ayarla stoğu
        wc_update_product_stock($variation_id, 1, $operation='set', $updating = false);
        $_pf = new WC_Product_Factory();
        $product = $_pf->get_product( $variation_id );
        $product->set_price( $minPrice["price"] );
        $product->set_regular_price( $minPrice["price"] );
        $product->set_sale_price( $minPrice["price"] );
        $product->save();

        return $minPrice["price"];

    }else{
        wc_update_product_stock($variation_id, 0, $operation='set', $updating = false);
        $_pf = new WC_Product_Factory();
        $product = $_pf->get_product( $variation_id );
        $product->set_price( $minPrice["price"] );
        $product->set_regular_price( $minPrice["price"] );
        $product->set_sale_price( $minPrice["price"] );
        $product->save();
        return false;
    }

}

function get_product_sale_order($parent_id,$term_id, $product_id){


    $args = array(
        'posts_per_page' => -1,
        'post_type' => "product_variation",
        'fields' => "ids",
        'post_status' => array("draft"),
        'meta_key' => "_price",
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => 'child_of',
                'value'   => $parent_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'term_id',
                'value'   => $term_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'used',
                'compare' => 'NOT EXISTS',
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'damaged',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'damaged_product',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'tried_product',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'no_box',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'missing',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
//            array(
//                'key' => '_stock_status',
//                'value' => 'instock'
//            ),
        ),
        'orderby' => "meta_value_num",
        'order' => "ASC",

    );


    $cquery = new WP_Query( $args );
    $prices = $cquery->posts;

    $args = array(
        'posts_per_page' => -1,
        'post_type' => "product_variation",
        'fields' => "ids",
        'post_status' => array("draft"),
        'meta_key' => "_price",
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => 'child_of',
                'value'   => $parent_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'term_id',
                'value'   => $term_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'used',
                'compare' => 'NOT EXISTS',
                'type' => 'NUMERIC',
            ),
            array(
                'relation' => 'OR',
                array(
                    'key'     => 'damaged',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'damaged_product',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'tried_product',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'no_box',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'missing',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
            ),
        ),
        'orderby' => "meta_value_num",
        'order' => "ASC",

    );

    $cquery = new WP_Query( $args );
    if ( ! empty($cquery->posts) ) {
        $prices = array_merge($prices, $cquery->posts);
    }

    if (empty($prices)) return null;

    return array_search($product_id, $prices) + 2;

}

function get_product_min_price($product_id,$term_id, $listing = false, $order = false){
    $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
    $args = array(
        'posts_per_page' => 1,
        'post_type' => "product",
        'fields' => "ids",
        'post_status' => array("publish"),
        'meta_query' => array(
            'relation' => 'AND',
            "child_of_clause" => array(
                'key'     => 'child_of',
                'value'   => $product_id,
                'compare' => '=',
            ),
            "term_id_clause" => array(
                'key'     => 'term_id',
                'value'   => $term_id,
                'compare' => '=',
            ),
            "no_box_clause" => array(
                'key'     => 'no_box',
                'compare' => 'EXISTS',
                'type' => 'NUMERIC',
            ),
            "damaged_clause" => array(
                'key'     => 'damaged',
                'compare' => 'EXISTS',
                'type' => 'NUMERIC',
            ),
            "damaged_product_clause" => array(
                'key'     => 'damaged_product',
                'compare' => 'EXISTS',
                'type' => 'NUMERIC',
            ),
            "tried_clause" => array(
                'key'     => 'tried_product',
                'compare' => 'EXISTS',
                'type' => 'NUMERIC',
            ),
            "missing_clause" => array(
                'key'     => 'missing',
                'compare' => 'EXISTS',
                'type' => 'NUMERIC',
            ),
            "used_clause" => array(
                'key'     => 'used',
                'compare' => 'NOT EXISTS',
                'type' => 'NUMERIC',
            ),
            "stock_clause" => array(
                'key' => '_stock_status',
                'value' => 'instock'
            ),
            "price_clause" => array(
                'key' => '_price',
                'compare' => 'EXISTS',
                'type' => 'NUMERIC',
            ),
        ),
        'orderby' => array(
            'tried_clause' => 'ASC',
            'damaged_product_clause' => 'ASC',
            'no_box_clause' => 'ASC',
            'damaged_clause' => 'ASC',
            'missing_clause' => 'ASC',
            'price_clause' => 'ASC',
        ),

    );


    if($order != false){
        $args["posts_per_page"] = -1;
    }

    $cquery = new WP_Query( $args );
    //var_dump($cquery->posts);

    if ( $cquery->have_posts() ) {
        while ( $cquery->have_posts() ) {
            $cquery->the_post();


            if($order != false) {
                $order = array_search($order, $cquery->posts) + 1;
            }

            $product = wc_get_product(get_the_ID());
            if($listing == false && $order == false){
                //echo "ez";
                return array("price" => $product->get_price() - $hizmet_bedeli);
            }else if($order != false){
                return array("price" => $product->get_price() - $hizmet_bedeli, "order" => $order);
            }else if($listing == true){
                wp_reset_query();
                return array("price" => $product->get_price(), "ID" => $product->get_id());
            }else{
                return $product->get_price() - $hizmet_bedeli;
            }
        }
    }else{
        return null;
    }



}


function get_product_variation_min_price($parent_id, $product_id,$term_id){
    $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);

    $args = array(
        'posts_per_page' => 1,
        'post_type' => "product_variation",
        //'fields' => "ids",
        'post_status' => array("publish"),
        'post_parent' => $parent_id,
        'meta_query' => array(
            "relation" => "AND",
            array(
                "key" => "attribute_pa_beden-numara",
                "value" => get_term_by("term_id",$term_id,"pa_beden-numara")->slug,
            ),
            array(
                "key" => "merchant_product",
                "value" => 1,
            ),
        ),
    );

    $cquery = new WP_Query($args);
    //print_r($cquery->posts);

    if ($cquery->posts) {
        $product = wc_get_product($cquery->posts[0]->ID);
        $min_price = $product->get_price() - $hizmet_bedeli;
        //echo get_the_title(get_the_ID());
        return $min_price;
    } else {
        return null;
    }

}

function get_min_price_vendor_product($parent_id,$term_id,$menu_order){
    $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
    $args = array(
        'posts_per_page' => 1,
        'post_type' => "product_variation",
        'fields' => "ids",
        'post_status' => array("draft"),
        'meta_key' => "_price",
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => 'child_of',
                'value'   => $parent_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'term_id',
                'value'   => $term_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'used',
                'compare' => 'NOT EXISTS',
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'damaged',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'damaged_product',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'tried_product',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'no_box',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'missing',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
//            array(
//                'key' => '_stock_status',
//                'value' => 'instock'
//            ),
        ),
        'orderby' => "meta_value_num",
        'order' => "ASC",
    );

    $cquery = new WP_Query($args);
    //print_r($cquery->posts);

    if ($cquery->posts) {
        $product_id = $cquery->posts[0];
        //wp_update_post(array("ID" => $product_id, "post_type" => "product_variation", "post_parent" => $parent_id, "menu_order" => $menu_order, "post_status" => "publish"));
        return $product_id;
    } else {

        $args = array(
            'posts_per_page' => 1,
            'post_type' => "product_variation",
            'fields' => "ids",
            'post_status' => array("draft"),
            'meta_key' => "_price",
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key'     => 'child_of',
                    'value'   => $parent_id,
                    'compare' => '=',
                ),
                array(
                    'key'     => 'term_id',
                    'value'   => $term_id,
                    'compare' => '=',
                ),
                array(
                    'key'     => 'used',
                    'compare' => 'NOT EXISTS',
                    'type' => 'NUMERIC',
                ),
//                array(
//                    'key' => '_stock_status',
//                    'value' => 'instock'
//                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key'     => 'damaged',
                        'compare' => '=',
                        'value' => "1",
                        'type' => 'NUMERIC',
                    ),
                    array(
                        'key'     => 'damaged_product',
                        'compare' => '=',
                        'value' => "1",
                        'type' => 'NUMERIC',
                    ),
                    array(
                        'key'     => 'tried_product',
                        'compare' => '=',
                        'value' => "1",
                        'type' => 'NUMERIC',
                    ),
                    array(
                        'key'     => 'no_box',
                        'compare' => '=',
                        'value' => "1",
                        'type' => 'NUMERIC',
                    ),
                    array(
                        'key'     => 'missing',
                        'compare' => '=',
                        'value' => "1",
                        'type' => 'NUMERIC',
                    ),
                ),
            ),
            'orderby' => "meta_value_num",
            'order' => "ASC",

        );

        $cquery = new WP_Query($args);
        //print_r($cquery->posts);

        if ($cquery->posts) {
            $product_id = $cquery->posts[0];
            //wp_update_post(array("ID" => $product_id, "post_type" => "product_variation", "post_parent" => $parent_id, "menu_order" => $menu_order, "post_status" => "publish"));
            return $product_id;
        } else {

            return null;
        }
    }

}

function get_vendor_product_sale_list($product_id,$term_id){
    $hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
    $guvence_bedeli =  (int) get_option("guvence_bedeli",true);
    $args = array(
        'posts_per_page' => -1,
        'post_type' => "product_variation",
        'fields' => "ids",
        'post_status' => array("draft"),
        'meta_key' => "_price",
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => 'child_of',
                'value'   => $product_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'term_id',
                'value'   => $term_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'used',
                'compare' => 'NOT EXISTS',
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'damaged',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'damaged_product',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'tried_product',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'no_box',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'missing',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
//            array(
//                'key' => '_stock_status',
//                'value' => 'instock'
//            ),
        ),
        'orderby' => "meta_value_num",
        'order' => "ASC",
    );


    $cquery = new WP_Query( $args );
    $prices = array();

    foreach ($cquery->posts as $post_id) {
        $price = (float) get_post_meta($post_id, '_price', true);
        $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));
        $prices[] = $basePrice;
    }


    $args = array(
        'posts_per_page' => -1,
        'post_type' => "product_variation",
        'fields' => "ids",
        'post_status' => array("draft"),
        'meta_key' => "_price",
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => 'child_of',
                'value'   => $product_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'term_id',
                'value'   => $term_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'used',
                'compare' => 'NOT EXISTS',
                'type' => 'NUMERIC',
            ),
//            array(
//                'key' => '_stock_status',
//                'value' => 'instock'
//            ),
            array(
                'relation' => 'OR',
                array(
                    'key'     => 'damaged',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'damaged_product',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'tried_product',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'no_box',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'missing',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
            ),
        ),
        'orderby' => "meta_value_num",
        'order' => "ASC",

    );


    $cquery = new WP_Query( $args );
    foreach ($cquery->posts as $post_id) {
        $price = (float) get_post_meta($post_id, '_price', true);
        $basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));
        $prices[] = $basePrice;
    }


    if(count($prices) == 0){
        return "-";
    }

    $html = null;

    foreach ($prices as $price){
        $html .= "<div>".wc_price($price)."</div>";
    }

    return $html;



}


function ask_to_merchant($product_id,$term_id, $name, $price){
    $args = array(
        'posts_per_page' => -1,
        'post_type' => array("product_variation","product"),
        'fields' => "ids",
        'post_status' => array("draft","publish"),
        'meta_key' => "_price",
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => 'child_of',
                'value'   => $product_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'term_id',
                'value'   => $term_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'used',
                'compare' => 'NOT EXISTS',
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'damaged',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'damaged_product',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'tried_product',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'no_box',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
            array(
                'key'     => 'missing',
                'compare' => '=',
                'value' => "0",
                'type' => 'NUMERIC',
            ),
//            array(
//                'key' => '_stock_status',
//                'value' => 'instock'
//            ),
        ),
        'orderby' => "meta_value_num",
        'order' => "ASC",

    );


    $cquery = new WP_Query( $args );
    //var_dump($cquery->posts);
    $merchant_ids = array();

    if ( $cquery->have_posts() ) {
        //netgsm_send_sms(str_replace("+90","",5543889207), "hasarsız buldum. ".current_time("timestamp"));
        while ( $cquery->have_posts() ) {
            $cquery->the_post();
            $merchant_ids[] = get_post_field( 'post_author', get_the_ID() );
        }
    }

    $args = array(
        'posts_per_page' => -1,
        'post_type' => array("product_variation","product"),
        'fields' => "ids",
        'post_status' => array("draft","publish"),
        'meta_key' => "_price",
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key'     => 'child_of',
                'value'   => $product_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'term_id',
                'value'   => $term_id,
                'compare' => '=',
            ),
            array(
                'key'     => 'used',
                'compare' => 'NOT EXISTS',
                'type' => 'NUMERIC',
            ),
//            array(
//                'key' => '_stock_status',
//                'value' => 'instock'
//            ),
            array(
                'relation' => 'OR',
                array(
                    'key'     => 'damaged',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'damaged_product',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'tried_product',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'no_box',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
                array(
                    'key'     => 'missing',
                    'compare' => '=',
                    'value' => "1",
                    'type' => 'NUMERIC',
                ),
            ),
        ),
        'orderby' => "meta_value_num",
        'order' => "ASC",

    );


    $cquery = new WP_Query( $args );
    if ( $cquery->have_posts() ) {
        //netgsm_send_sms(str_replace("+90","",5543889207), "hasarlı buldum. ".current_time("timestamp"));
        while ( $cquery->have_posts() ) {
            $cquery->the_post();
            $merchant_ids[] = get_post_field( 'post_author', get_the_ID() );
        }
    }

    //netgsm_send_sms(str_replace("+90","",5543889207), "$name $price $product_id $term_id ".current_time("timestamp"));

    if(count($merchant_ids) == 0){
        //echo "ez";
        //error_log("ff");
        return;
    }

    $order_fix = 0;

    foreach ($merchant_ids as $merchant_id){
        //echo "fex";
        //error_log("gg");
        netgsm_send_sms(str_replace("+90","",get_user_meta($merchant_id,"billing_phone_account",true)), $price." TL'ye satılmış olan ".fix_variation_title(str_replace("&#8211;","",$name))." ürününü temin etmek isterseniz bizimle iletişime geçebilirsiniz.");
        $order_fix++;
    }



}

function get_product_status( $post_id ) {
    $status = false;
//echo $post_id;
    if(get_post_meta($post_id,"no_box",true) == 1){
        $status .= "Kutusu yok, ";
    }

    if(get_post_meta($post_id,"damaged",true) == 1){
        $status .= "Hasarlı kutu, ";
    }


    if(get_post_meta($post_id,"missing",true) == 1){
        $status .= "Eksik aksesuar, ";
    }

    if(get_post_meta($post_id,"damaged_product",true) == 1){
        $status .= "Hasarlı ürün, ";
    }

    if(get_post_meta($post_id,"tried_product",true) == 1){
        $status .= "Denenmiş ürün, ";
    }

    return substr_replace($status ,"",-2);

}


function firefog_custom_add_to_cart_redirect( $url ) {
    $url = wp_get_referer();
    return $url;
}
add_filter( 'woocommerce_cart_redirect_after_error', 'firefog_custom_add_to_cart_redirect' );

function redirect_to_parent_product() {
    if(is_product()){
        $termsArray = array();
        $terms = get_the_terms( get_the_ID(), 'product_cat' );

        foreach($terms as $term){
            $termsArray[] = $term->term_id;
        }
        if ( !empty( $terms ) ) {
            if(in_array(394,$termsArray) && !in_array(103,$termsArray)){
                wp_redirect(get_the_permalink(get_post_meta(get_the_ID(),"child_of",true)));
                exit();
            }
        }
    }
}
add_filter( 'wp', 'redirect_to_parent_product' );


?>
