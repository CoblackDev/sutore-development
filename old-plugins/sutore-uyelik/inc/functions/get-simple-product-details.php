<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_get_simple_product_details', 'get_simple_product_details');
add_action('wp_ajax_nopriv_get_simple_product_details', 'get_simple_product_details');

/**
 * Katalog ürünü için merchant variation'lardan net minimum listeleme fiyatını hesaplar.
 */
function sutore_uyelik_get_catalog_min_net_price( WC_Product $catalog ): ?float {
    $hizmet_bedeli  = (int) get_option( 'hizmet_bedeli', true );
    $guvence_bedeli = (int) get_option( 'guvence_bedeli', true );
    $lowest_gross   = null;

    if ( $catalog->is_type( 'variable' ) ) {
        foreach ( $catalog->get_children() as $child_id ) {
            $variation = wc_get_product( $child_id );
            if ( ! $variation || 'publish' !== $variation->get_status() ) {
                continue;
            }
            if ( (int) get_post_meta( $child_id, 'merchant_product', true ) !== 1 ) {
                continue;
            }
            if ( 'instock' !== $variation->get_stock_status() ) {
                continue;
            }
            $price = (float) $variation->get_price();
            if ( $price > 0 && ( null === $lowest_gross || $price < $lowest_gross ) ) {
                $lowest_gross = $price;
            }
        }
    } else {
        $price = (float) $catalog->get_price();
        if ( $price > 0 ) {
            $lowest_gross = $price;
        }
    }

    if ( null === $lowest_gross ) {
        return null;
    }

    return ( $lowest_gross - $hizmet_bedeli ) * ( 100 / ( 100 + $guvence_bedeli ) );
}

function get_simple_product_details(){
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sutore-register-request' ) ) {
        wp_send_json( array( 'status' => false, 'message' => 'Geçersiz işlem. Lütfen sayfayı yenileyerek tekrar deneyin.' ) );
        wp_die();
    }

    $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
    $product      = $product_id ? wc_get_product( $product_id ) : false;
    $parent_id    = $product ? (int) $product->get_parent_id() : 0;
    $catalog_id   = $parent_id > 0 ? $parent_id : $product_id;
    $catalog      = $catalog_id ? wc_get_product( $catalog_id ) : false;

    $min_price      = ( $catalog instanceof WC_Product ) ? sutore_uyelik_get_catalog_min_net_price( $catalog ) : null;
    $min_sale_price = null !== $min_price ? wc_price( $min_price ) : '-';

    $html = '';
    if ( $product_id && $product ) {
        $html .= '<h2>' . esc_html( get_the_title( $product_id ) ) . '</h2>';
        $thumb_id = $parent_id > 0 ? $parent_id : $product_id;
        $html .= '<div>' . get_the_post_thumbnail( $thumb_id, 'woocommerce_thumbnail' ) . '</div>';
    }
    $html .= "<p style='margin-bottom: 15px;'><small>En düşük fiyat: <strong>" . ( '-' === $min_sale_price ? '-' : $min_sale_price ) . '</strong></small></p>';
    $html .= '<p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><input type="text" class="input-text fl-input"  id="product_price" placeholder="Fiyat" value=""><input type="button" class="button"  id="first_place_price" value="İlk Sıraya Geç"></div></span></p>';
    $html .= "<p style='margin-bottom: 15px; text-align: left;'>Üründe aşağıdaki kusurlardan biri var ise seçin</p>";
    $html .= "<p style='text-align:left'>";
    $html .= '<input type="checkbox" id="no_box" name="product_def" value="1">';
    $html .= '<label for="no_box">Kutusu yok</label><br>';
    $html .= '<input type="checkbox" id="damaged" name="product_def" value="2">';
    $html .= '<label for="damaged">Hasarlı kutu</label><br>';
    $html .= '<input type="checkbox" id="missing" name="product_def" value="3">';
    $html .= '<label for="missing">Eksik aksesuar</label><br>';
    $html .= "</p>";
    $html .= "<p><small>Ürününüz <strong>₺99</strong> hizmet bedeli yansıtılarak listelenecektir.</small></p>";

    if ( null !== $min_price && $min_price > 25 ) {
        $first_place_price = $min_price - 25;
    } else {
        $first_place_price = '-';
    }

    wp_send_json( array( 'status' => true, 'html' => $html, 'min' => $first_place_price ) );
    wp_die();
}

?>
