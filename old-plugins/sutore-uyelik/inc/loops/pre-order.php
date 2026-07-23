<?php
if (!defined('ABSPATH')) {
    exit;
}

//$parentId = get_post_meta(get_the_ID(),"product_parent_id",true);
//if(empty($parentId)){
//    $parentId = get_post_meta(get_the_ID(),"product_id",true);
//}
//$productPrice = get_post_meta(get_the_ID(),"product_price",true);

$product = wc_get_product(get_the_ID());
$vendor_item_id = get_the_ID();
$productId = get_post_meta($vendor_item_id,"child_of",true);
$termId = get_post_meta($vendor_item_id,"term_id",true);


if(get_post_meta($vendor_item_id,"child_of",true) && get_post_meta($vendor_item_id,"used",true)){
    $thumnnail_id = $vendor_item_id;
}else{
    $thumnnail_id = get_post_meta($vendor_item_id,"child_of",true);
}
$status = get_product_status($vendor_item_id);

?>

<ul class="woocommerce-cart-form__cart-item col large-4 cart_item">

    <li class="product-thumbnail">
        <?php
        if ( ! isset($product) || ! $product instanceof WC_Product ) {
            $product = wc_get_product( get_the_ID() );
        }
        ?>
        <a href="<?php echo get_the_permalink( $product->get_id() ); ?>">
            <?php echo get_the_post_thumbnail( $product->get_id(), 'woocommerce_thumbnail' ); ?>
        </a>
    </li>

    <div class="product-details">
        
        <li class="product-title" data-title="Ürün">
          <a href="<?php echo esc_url( get_the_permalink( $product->get_id() ) ); ?>">
            <?php
              $pid   = (int) $product->get_id();
              $title = get_the_title( $product->get_id() );
              echo esc_html( $pid . ' – ' . $title );
            ?>
          </a>

            <?php
            // Satıcının eklediği ham fiyat (meta) varsa onu kullan
            $vendor_price_meta = get_post_meta( $product->get_id(), 'product_price', true );

            if ( $vendor_price_meta !== '' && $vendor_price_meta !== null ) {
                $vendor_price = (float) $vendor_price_meta;
            } else {
                // Yoksa, vitrindeki fiyattan hizmet + güvence bedellerini arındır
                $hizmet_bedeli  = (float) get_option( 'hizmet_bedeli', 0 );
                $guvence_bedeli = (float) get_option( 'guvence_bedeli', 0 );
                $price          = (float) $product->get_price();

                $denom = (100 + $guvence_bedeli);
                if ( $denom == 0 ) { $denom = 100; }

                $vendor_price = ($price - $hizmet_bedeli) * (100 / $denom);
            }

            // Sadece fiyatı yazdır (etiketsiz, kalınsız)
            echo '<div class="vendor-price">'
                . wc_price( $vendor_price ) .
                '</div>';
            ?>
        </li>

        <li class="product-action">
            <?php $productStatus = get_post_status( $product->get_id() ); ?>
            <?php if ( $productStatus == 'pre-order' || $productStatus == 'sold' ) : ?>
                <a href="#"
                   style="color:black; font-weight:bold; font-size:16px;"
                   class="pre-order-detail"
                   data-id="<?php echo esc_attr( $product->get_id() ); ?>">
                   <?php echo esc_html__( 'Sipariş Detayı', 'sutore' ); ?>
                </a>
            <?php endif; ?>
        </li>

    </div>
</ul>