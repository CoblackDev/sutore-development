<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_delete_vendor_product', 'delete_vendor_product');
//add_action('wp_ajax_nopriv_delete_vendor_product', 'delete_vendor_product');

function delete_vendor_product(){
    //print_r($_POST);
    if (!is_user_logged_in()) {
        wp_send_json(array("status" => false, "message" => "Lütfen giriş yapın."));
        wp_die();
    }
    if ( ! isset( $_POST['product_id'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else{
        $product_id = sanitize_text_field($_POST['product_id']);
        $post_author_id = get_post_field( 'post_author', sanitize_text_field($_POST['product_id']) );
        $childof = get_post_meta($product_id,"child_of",true);
        $term_id = get_post_meta($product_id,"term_id",true);
        $menu_order = 1;
        $term = get_term_by("id",$term_id,"pa_beden-numara");

        if(get_post_status($product_id) == "payment"){
          wp_send_json(array("status" => false, "message" => "Bir hata oluştu. Lütfen daha sonra tekrar deneyin."));
          wp_die();
        }else if(is_product_in_order($product_id)){
          wp_send_json(array("status" => false, "message" => "Bir hata oluştu. Lütfen daha sonra tekrar deneyin."));
          wp_die();
        }

        if(get_current_user_id() == $post_author_id) {
            $isUsed = get_post_meta(sanitize_text_field($_POST['product_id']), "used", true);
            if ($isUsed == 1) {

                $product = wc_get_product( $_POST['product_id'] );

                if ( !$product ) {
                    return;
                }

                $featured_image_id = $product->get_image_id();
                $image_galleries_id = $product->get_gallery_image_ids();

                if( !empty( $featured_image_id ) ) {
                    wp_delete_post( $featured_image_id );
                }

                if( !empty( $image_galleries_id ) ) {
                    foreach( $image_galleries_id as $single_image_id ) {
                        wp_delete_post( $single_image_id );
                    }
                }

                wp_delete_post(get_post_meta($_POST['product_id'], "product_id", true), true);
            }

            $variation = wc_get_product(sanitize_text_field($_POST['product_id']));
            $update_taxonomy = 'pa_beden-numara';
            $get_terms_args = array('taxonomy' => $update_taxonomy, 'fields' => 'ids', 'hide_empty' => false,);
            $update_terms = get_terms($get_terms_args);
            wp_update_term_count_now($update_terms, "$update_taxonomy");
            sutore_merchant_system($variation->get_id(),"delete");
            wp_send_json(array("status" => true, "message" => "Ürününüz başarıyla sistemden kaldırılmıştır."));
            wp_die();
        }else{
                wp_send_json(array("status" => false, "message" => "Geçersiz işlem."));
                wp_die();
            }
    }
}

?>
