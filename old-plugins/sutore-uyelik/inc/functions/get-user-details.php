<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_get_user_details', 'get_user_details');
//add_action('wp_ajax_nopriv_get_details', 'get_details');


function get_user_details(){
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    }else {
        $user_id = absint($_POST['user_id']);

        $args = array(
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post_type' => "product_variation",
            'post_status' => array("shipped-to-sutore","arrived-to-sutore","verified","confirmed","paid","shipped","sold","ready-to-shipping"),
            'author' => $user_id,
        );

        $cquery = new WP_Query( $args);

        $soldCount = $cquery->found_posts;

        $args = array(
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post_type' => "product_variation",
            'post_status' => array("publish","draft","shipped-to-sutore","arrived-to-sutore","verified","confirmed","paid","shipped","sold","ready-to-shipping"),
            'author' => $user_id,
        );

        $cquery = new WP_Query( $args);

        $productCount = $cquery->found_posts;

        global $wpdb;
        $soldTotal = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(pm.meta_value + 0), 0)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = %s
                   AND p.post_status = %s
                   AND p.post_author = %d
                   AND pm.meta_key = %s",
                "product_variation",
                "paid",
                $user_id,
                "_price"
            )
        );

                $html = null;
            $html .= '<table>';
            $html .= '<th colspan="2">Satıcı Detayları</th>';
            $html .= '<tr>';
            $html .= '<td>Toplam Ürün Girişi:</td><td>'.$productCount.'</td>';
            $html .= '</tr>';
            $html .= '<tr>';
            $html .= '<td>Satılan Ürünler Adedi:</td><td>'.$soldCount.'</td>';
            $html .= '</tr>';
            $html .= '<tr>';
            $html .= '<td>Satış Hacmi:</td><td>'.wc_price($soldTotal).'</td>';
            $html .= '</tr>';


        wp_send_json(array("status" => true, "html" => $html));
        wp_die();

    }


}
?>