<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_get_product_variations', 'get_product_variations');
add_action('wp_ajax_nopriv_get_product_variations', 'get_product_variations');

function get_product_variations(){
    //print_r($_POST);
    if ( ! isset( $_POST['product_id'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else{
        $productId = sanitize_text_field($_POST['product_id']);
        //echo $productId;
        //$product = wc_get_product( $productId );
        //$variations = $product->get_children();
        //print_r($variations);

//        $taxonomies = get_terms( array(
//            'taxonomy' => 'pa_beden-numara',
//            'hide_empty' => false
//        ) );


        $taxonomies =  wc_get_product_terms( $productId, 'pa_beden-numara' );



        $html = null;

        if ( !empty($taxonomies) ) {
            $output = '<select>';
            foreach ($taxonomies as $term) {
                $html .= "<li data-id='".$productId."' data-term-id='".$term->term_id."'>".$term->name."</li>";
            }
        }
//        foreach ($variations as $variation){
//            $product = wc_get_product($variation);
//            $attributes = $product->get_attributes();
//            foreach ($attributes as $key => $value) {
//                $term = get_term_by('slug', $attributes[$key], $key);
//            }
//            $html .= "<li data-id='".$productId."' data-term-id='".$term->term_id."'>".$term->name."</li>";
//        }

        wp_send_json(array("status" => true, "html" => $html));
        wp_die();
    }
}

?>