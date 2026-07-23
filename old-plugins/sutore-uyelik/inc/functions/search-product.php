<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_search_product', 'search_product');
add_action('wp_ajax_nopriv_search_product', 'search_product');
function search_product(){
    if ( ! isset( $_POST['product_code'] ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' )) {
        wp_send_json(array("status" => false, "message" => "Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin."));
        wp_die();
    } else {
        // args
        $product_cat = sanitize_text_field($_POST["product_cat"]);
        $args = array(
            'posts_per_page'	=> 5,
            'post_type'		=> 'product',
            'post_status' => "publish",
            'meta_query' => array(
                array(
                    'key' => 'urun_kodu',
                    'value' => sanitize_text_field($_POST["product_code"]),
                    'compare' => 'LIKE'   // or if you want like then use 'compare' => 'LIKE'
                ),
                array(
                    'key' => 'child_of',
                    'compare' => 'NOT EXISTS'   // or if you want like then use 'compare' => 'LIKE'
                ),
            ),
//            'tax_query' => array(
//                array(
//                    'taxonomy' => 'product_cat',
//                    'field'    => 'term_id',
//                    'terms'    => $product_cat,
//                ),
//            )

        );

// query
        $the_query = new WP_Query( $args );
        if( $the_query->have_posts() ){
            while( $the_query->have_posts() ) { $the_query->the_post(); ?>
                <div class="product" data-id="<?php echo get_the_ID(); ?>">
                    <?php the_post_thumbnail("woocommerce_thumbnail"); ?>
                    <div class="title"><?php echo the_title(); ?></div>
                </div>


            <?php }
        }else{
            ?>
                <?php if($_POST["not_exists"] == 0) : ?>
            <div class="product">
                <div class="title">Aradığınız ürün bulunamadı. Bir hata olduğunu düşünüyorsanız Whatsapp üzerinden bizimle iletişime geçebilirsiniz.</div>
            </div>
            <?php else: ?>
            <div class="product new">
                <div class="title">+ Yeni Ürün (Ürünüm listede yok)</div>
            </div>
           <?php endif; ?>
            <?php
        }
        //print_r($_POST);
    }

    wp_die();
}
?>
