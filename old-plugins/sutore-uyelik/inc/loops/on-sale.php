<?php
if (!defined('ABSPATH')) {
    exit;
}

$product = wc_get_product(get_the_ID());
$vendor_item_id = get_the_ID();
$productId = get_post_meta($vendor_item_id,"child_of",true);
$termId = get_post_meta($vendor_item_id,"term_id",true);
//$minPrice = get_product_min_price($productId,$termId,false,$vendor_item_id);
//print_r($minPrice);
$hizmet_bedeli = (float) get_option('hizmet_bedeli', '0');
$guvence_bedeli = (float) get_option('guvence_bedeli', '0');
$price = (float) wc_format_decimal($product->get_price());
$denom = 100 + $guvence_bedeli;
$basePrice = $denom != 0.0 ? ($price - $hizmet_bedeli) * (100 / $denom) : 0.0;

if(get_post_meta($vendor_item_id,"child_of",true) && get_post_meta($vendor_item_id,"used",true)){
    $thumnnail_id = $vendor_item_id;
}else{
    $thumnnail_id = get_post_meta($vendor_item_id,"child_of",true);
}
$status = get_product_status($vendor_item_id);
$parent_id     = (int) $productId;
$parent_status = $parent_id ? get_post_status($parent_id) : get_post_status($vendor_item_id);
$item_title    = get_the_title($vendor_item_id);
 
 ?>
<ul class="woocommerce-cart-form__cart-item col large-4 cart_item">

    <li class="product-thumbnail">
        <a href="<?php echo get_the_permalink($product->get_id()); ?>"><?php echo get_the_post_thumbnail($product->get_id(),"woocommerce_thumbnail"); ?></a>					</li>


    <div class="product-details">
    <li class="product-title" data-title="Ürün">
        <a href="<?php echo get_the_permalink($product->get_id()); ?>"><?php the_title(); ?></a>						<div class="show-for-small mobile-product-price">
            <span class="mobile-product-price__qty">1 x </span>
            <span class="woocommerce-Price-amount amount"><?php echo wc_price($basePrice); ?></span>	</div>
    </li>

    <li class="product-price" data-title="Fiyat">
        <span class="woocommerce-Price-amount amount"><?php echo wc_price($basePrice); ?></span>							</li>

    <li style="display:none" class="lowest-ask">En Düşük Fiyat: <?php //echo $minPrice["price"] != null ? wc_price($minPrice["price"]) : "-";?></li>
    <?php //if( get_post_status($product->get_id()) == "publish") : ?>
    <li style="display:none" class="lowest-ask">Mevcut Sıra: <span class="min-query">?</span><?php //echo $minPrice["order"] != null ? $minPrice["order"]."." : "-";?></li>
    <?php //endif; ?>
    <li class="lowest-ask">Durum: <?php echo get_vendor_product_status($vendor_item_id);?></li>
        <!-- <?php if( get_post_status($product->get_id()) == "publish" || get_post_status($product->get_id()) == "draft") : ?>
    <li class="lowest-ask">Kalan Süre: <?php echo get_vendor_product_expire_time($product->get_id());?></li>
        <?php endif; ?> -->
    <?php if( $status) : ?>
    <li class="lowest-ask">Kondisyon: <?php echo $status;?></li>
    <?php endif; ?>
    
    <li class="product-action">
        <?php
        // Re-sutore Kontrolü (variation üzerinde)
        $pid = $vendor_item_id; // bu template'de get_the_ID() zaten item id
        $is_resale = (bool) get_post_meta($pid, 're_sutore_item', true);

        if ( $is_resale ) :
            // --- RE-SUTORE BUTONLARI ---
            $listing_price = (float) $product->get_price();

            // Base'i en doğru yerden al: create'de zaten product_price meta yazıyorsun
            $base_price = (int) get_post_meta($pid, 'product_price', true);
            if ( $base_price <= 0 ) {
                // fallback (normalde gerek kalmamalı)
                $base_price = (int) round( ($listing_price - 749) / 1.10 );
            }

            $expire_ts = (int) get_post_meta($pid, 'expire_date', true);
            $days_left = $expire_ts ? max(0, (int) ceil( ($expire_ts - current_time('timestamp')) / 86400 )) : 0;
        ?>
            <?php if ( get_post_status($product->get_id()) != "not-sale") : ?>
              <a href="#" style="width:auto" class="remove" data-id="<?php echo (int) $product->get_id(); ?>">Sil</a>
            <?php endif; ?>

            <a href="#"
               style="color:black; font-weight:bold; font-size:15px;"
               class="resale-update"
               data-id="<?php echo (int) $product->get_id(); ?>"
               data-base="<?php echo (int) $base_price; ?>"
               data-listing="<?php echo esc_attr( $listing_price ); ?>"
               data-expire="<?php echo (int) $expire_ts; ?>"
               data-days="<?php echo (int) $days_left; ?>"
               data-status="<?php echo esc_attr( get_post_status($product->get_id()) ); ?>"
               data-title="<?php echo esc_attr( $item_title ); ?>"
               data-parent-status="<?php echo esc_attr( $parent_status ); ?>">
               Güncelle
            </a>

            <a href="#"
               style="color:black; font-weight:bold; font-size:15px;"
               class="resale-detail"
               data-id="<?php echo (int) $product->get_id(); ?>"
               data-listing="<?php echo esc_attr( $listing_price ); ?>"
               data-base="<?php echo (int) $base_price; ?>"
               data-days="<?php echo (int) $days_left; ?>"
               data-status="<?php echo esc_attr( get_post_status($product->get_id()) ); ?>"
               data-title="<?php echo esc_attr( $item_title ); ?>"
               data-parent-status="<?php echo esc_attr( $parent_status ); ?>">
               Detay
            </a>

        <?php else : ?>

            <?php if( get_post_status($product->get_id()) != "not-sale") : ?>
            <a href="#" style="width:auto" class="remove" data-id="<?php echo $product->get_id(); ?>" >Sil</a>
            <a href="#" style="color:black; font-weight:bold; font-size:15px;" class="update" data-id="<?php echo $product->get_id(); ?>">Güncelle</a>
            <?php endif; ?>
            <?php if( get_post_status($product->get_id()) == "publish" || get_post_status($product->get_id()) == "draft" || get_post_status($product->get_id()) == "payment" && !get_post_meta($product->get_id(),"used",true)) : ?>
            <a href="#" style="color:black; font-weight:bold; font-size:15px;" class="detail" data-id="<?php echo $product->get_id(); ?>">Detay</a>
            <?php endif; ?>
            <?php if(!get_post_meta($product->get_id(),"campaing_response",true) && get_post_meta($product->get_id(),"sutore_campaing_discount",true) && get_post_meta($product->get_id(),"sutore_campaing_merchant_discount",true)) : ?>
            <a href="#" style="color:black; font-weight:bold; font-size:15px;" class="show-campaing" data-id="<?php echo $product->get_id(); ?>">Teklifi Gör</a>
            <?php endif; ?>
            <?php if(get_post_meta($product->get_id(),"campaing_response",true)) : ?>
            <a href="#" style="color:black; font-weight:bold; font-size:15px;" class="campaing-details" data-id="<?php echo $product->get_id(); ?>">Teklifi Detayı</a>
            <?php endif; ?>

        <?php endif; ?>
    </li>
    </div>
</ul>
