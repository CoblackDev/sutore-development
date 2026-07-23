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
//$minPrice = get_product_min_price($productId,$termId,false,$vendor_item_id);
//print_r($minPrice);
$hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
$guvence_bedeli =  (int) get_option("guvence_bedeli",true);
$price = $product->get_price();
$basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));

if(get_post_meta($vendor_item_id,"child_of",true) && get_post_meta($vendor_item_id,"used",true)){
    $thumnnail_id = $vendor_item_id;
}else{
    $thumnnail_id = get_post_meta($vendor_item_id,"child_of",true);
}
$status = get_product_status($vendor_item_id);

?>
<ul class="woocommerce-cart-form__cart-item col large-4 cart_item">

    <li class="product-thumbnail">
        <a href="<?php echo get_the_permalink($product->get_id()); ?>"><?php echo get_the_post_thumbnail($product->get_id(),"woocommerce_thumbnail"); ?></a>					</li>


    <div class="product-details">
    <li class="product-title" data-title="Ürün">
        <a <?php echo get_post_meta($product->get_id(),"pre_order",true) == 1 ? "style='color:red'" : ""; ?> href="<?php echo get_the_permalink($product->get_id()); ?>"><?php the_title(); ?></a>						<div class="show-for-small mobile-product-price">
            <span class="mobile-product-price__qty">1 x </span>
            <span class="woocommerce-Price-amount amount"><?php echo wc_price($basePrice); ?></span>	</div>
    </li>
    <li class="product-price" data-title="Fiyat">
        <span class="woocommerce-Price-amount amount"><?php echo wc_price($basePrice); ?></span>							</li>

    <!--<td class="product-quantity" data-title="Miktar">
        1 <input type="hidden" name="cart[45527eac76e1b507b05b672af3b88cbb][qty]" value="1" />					</td>-->
    <li style="display:none" class="lowest-ask">En Düşük Fiyat: <?php //echo $minPrice["price"] != null ? wc_price($minPrice["price"]) : "-";?></li>
    <?php //if( get_post_status($product->get_id()) == "publish") : ?>
    <li style="display:none" class="lowest-ask">Mevcut Sıra: <span class="min-query">?</span><?php //echo $minPrice["order"] != null ? $minPrice["order"]."." : "-";?></li>
    <?php //endif; ?>
    <li class="lowest-ask">Durum: <?php echo get_vendor_product_status($vendor_item_id);?></li>
        <?php if(!empty($order_id) && get_post_meta($order_id,"sutore_shipment_type",true) == "international") : ?>
            <li class="lowest-ask"><?php echo __("International Shipment (Invoice Required)","sutore"); ?></li>
        <?php endif; ?>
    <?php if( $status) : ?>
        <li class="lowest-ask">Kondisyon: <?php echo $status;?></li>
    <?php endif; ?>
    <li class="product-action">
        <?php $productStatus = get_post_status($product->get_id()); ?>
        <?php if( $productStatus == "ready-to-shipping" || $productStatus == "sold" ||  $productStatus == "shipped-to-sutore" ||  $productStatus == "arrived-to-sutore" ||  $productStatus == "verified" ||  $productStatus == "confirmed" ||  $productStatus == "shipped" ||  $productStatus == "paid") : ?>
            <a href="#" style="color:black; font-weight:bold; font-size:15px;" class="detail" data-id="<?php echo $product->get_id(); ?>">Detay</a>
        <?php endif; ?>
        <?php if( $productStatus == "shipped-to-sutore" ||  $productStatus == "arrived-to-sutore" ||  $productStatus == "verified") : ?>
        <a href="https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=<?php echo esc_attr(get_post_meta($product->get_id(),"shipment_code",true)); ?>" target="_blank" style="color:black; font-weight:bold; font-size:15px;">Takip</a>
        <?php elseif( $productStatus == "confirmed") : ?>
            <a href="#" class="shipped" data-id="<?php echo $product->get_id(); ?>" style="color:black; font-weight:bold; font-size:15px;">Kargola</a>
        <?php elseif( $productStatus == "sold") : ?>
            <a href="#" class="confirm" style="color:black; font-weight:bold; font-size:15px;" data-id="<?php echo $product->get_id(); ?>">Onayla</a>
        <?php endif; ?>

        <?php if(get_post_meta($product->get_id(),"campaing_response",true)) : ?>
        <a href="#" style="color:black; font-weight:bold; font-size:15px;" class="campaing-details" data-id="<?php echo $product->get_id(); ?>">Teklifi Detayı</a>
        <?php endif; ?>
    </li>
    </div>
    <!--<li class="product-subtotal" data-title="Toplam">
        <span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">&#8378;</span>3369</bdi></span>						</li>-->
</ul>
