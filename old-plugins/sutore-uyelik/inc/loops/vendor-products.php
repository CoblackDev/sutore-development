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
//$min_variation_price = get_product_variation_min_price($productId,$vendor_item_id,$termId);
//print_r($minPrice);
$hizmet_bedeli =  (int) get_option("hizmet_bedeli",true);
$guvence_bedeli =  (int) get_option("guvence_bedeli",true);
$price = $product->get_price();
$basePrice = ($price - $hizmet_bedeli) * (100/(100 + $guvence_bedeli));


$campaing = get_post_meta($product->get_id(),"campaing_response",true) ? " - ".get_post_meta($product->get_id(),"sutore_campaing_merchant_discount",true) + get_post_meta($product->get_id(),"sutore_campaing_discount",true) : null;
$campaing_merchant = get_post_meta($product->get_id(),"campaing_response",true) ? " - ".get_post_meta($product->get_id(),"sutore_campaing_merchant_discount",true) : null;


if(get_post_meta($vendor_item_id,"child_of",true) && get_post_meta($vendor_item_id,"used",true)){
    $thumnnail_id = $vendor_item_id;
}else{
    $thumnnail_id = get_post_meta($vendor_item_id,"child_of",true);
}

$status      = get_product_status($vendor_item_id);
$product_status_filter = isset($_GET['product_status']) ? sanitize_text_field(wp_unslash($_GET['product_status'])) : '';

// bunları ÖNCE set et
$order_id    = (int) get_post_meta($product->get_id(), 'product_sold_order_id', true);
$merchant_id = (int) get_post_field('post_author', $product->get_id());
$merchant    = get_user_by('id', $merchant_id);
$merchant_level = $merchant_id ? get_user_meta($merchant_id, 'merchant_level', true) : '';

//if(16662 == $product->get_id()) {
//    update_post_meta($product->get_id(), "product_sold_order_id", 11111);
//}
 ?>
 
<tr class="merchant-product">

  <!-- 1) AKSİYON HÜCRESİ -->
  <td class="col-actions">
    <button class="button update-merchant-product"
            data-nonce="<?php echo wp_create_nonce('sutore_update_merchant_product_nonce'); ?>"
            data-id="<?php echo $product->get_id(); ?>"
            style="padding: 10px 32px !important;background-color: #fff;color: #222;border: 1px solid rgb(230, 230, 230);border-radius: 8px;font-weight: 500;line-height: normal;text-transform: capitalize !important;">Güncelle</button>

    <?php if ( get_post_status($product->get_id()) == 'publish'
            || get_post_status($product->get_id()) == 'sold'
            || get_post_status($product->get_id()) == 'draft' ) : ?>
      <a href="#"
         style="padding: 10px 32px !important;background-color: #fff;color: #222;border: 1px solid rgb(230, 230, 230);border-radius: 8px;font-weight: 500;line-height: 1.6; font-size: 13.6px !important;"
         class="detail"
         data-id="<?php echo $product->get_id(); ?>">Detay</a>
    <?php elseif ( get_post_meta($product->get_id(), 'product_sold_order_id', true) ) : ?>
      <a href="<?php echo admin_url('post.php?post=' . get_post_meta($product->get_id(), 'product_sold_order_id', true) . '&action=edit'); ?>"
         style="padding: 10px 32px !important;background-color: #fff;color: #222;border: 1px solid rgb(230, 230, 230);border-radius: 8px;font-weight: 500;line-height: 1.6; font-size: 13.6px !important;"
         class="order"
         data-id="<?php echo $product->get_id(); ?>">Sipariş</a>
    <?php endif; ?>
  </td>

  <!-- 3) ID -->
  <td <?php echo ( $product_status_filter === 'payment' && find_in_returns(get_post_meta($product->get_id(),'urun_kodu',true), get_post_meta($product->get_id(),'term_id',true)) ) ? "style='color:red'" : null; ?>>
    <?php
      $pid = (int) $product->get_id();
      echo '<div>'.$pid.'</div>';
      if ( $order_id ) {
        echo '<div style="margin-top:8px;"><small>SU No: #'.(int)$order_id.'</small></div>';
      }
    ?>
  </td>

  <!-- 2) Görsel -->
  <td>
    <a <?php echo ( get_post_status($product->get_id()) == 'pre-order' || get_post_meta($product->get_id(),'pre_order',true) ) ? "class='text-red'" : ""; ?>
       href="<?php echo get_the_permalink($product->get_id()); ?>">
      <?php echo get_the_post_thumbnail($product->get_id(), 'woocommerce_thumbnail'); ?>
    </a>
  </td>

  <!-- 4) Ürün Adı -->
  <td>
    <a <?php echo ( get_post_status($product->get_id()) == 'pre-order' || get_post_meta($product->get_id(),'pre_order',true) ) ? "style='color:red'" : ""; ?>
       href="<?php echo '?search_param=' . get_post_meta($product->get_id(),'product_sold_order_id',true) . '&product_status=&user=0'; ?>">
      <?php
        $title_txt = get_the_title();
        // $status zaten dosyada en üstte: $status = get_product_status($vendor_item_id);
        if ( !empty($status) && $status !== '-' ) {
          // Örn: adidas Yeezy 500 Stone Taupe – EU 38 (hasarlı)
          $title_txt .= ' <span style="color:red;">(' . esc_html($status) . ')</span>';
        }
        echo $title_txt;
      ?>
    </a>
  </td>

  <!-- 8) Fiyat -->
  <td>
    <div <?php echo ($order_id && has_order_coupon($order_id)) ? "class='text-red'" : ""; ?>>
      <?php echo wc_price($basePrice) . $campaing; ?>
    </div>
    <div><small>
      <?php
        $merchant_tax = ($basePrice * get_merchant_commision($merchant_id)) / 100;
        echo wc_price($basePrice - $merchant_tax) . $campaing_merchant;
      ?>
    </small></div>
  </td>

  <!-- 6) Satıcı -->
  <td>
    <div>
      <?php if ( $merchant ) : ?>
        <a href="<?php echo esc_url(get_edit_user_link($merchant->ID)); ?>"><?php echo esc_html($merchant->user_login); ?></a>
      <?php else : ?>
        <?php echo esc_html($merchant_id ? 'Silinmiş kullanıcı #' . $merchant_id : 'Satıcı yok'); ?>
      <?php endif; ?>
    </div>
    <div><?php echo esc_html(get_post_meta($product->get_id(),'merchant_name',true)); ?> - <small><?php echo esc_html($merchant_level !== '' ? $merchant_level . '. Seviye' : 'Seviye yok'); ?></small></div>
    <div><small><?php echo esc_html(get_post_meta($product->get_id(),'merchant_tc',true)); ?></small></div>
    <div><small><?php echo esc_html(get_post_meta($product->get_id(),'merchant_iban',true)); ?></small></div>
    <div><small><?php echo esc_html(get_post_meta($product->get_id(),'merchant_state',true) ? get_post_meta($product->get_id(),'merchant_state',true) : ''); ?>, <?php echo esc_html(isset(WC()->countries->get_states('TR')[ get_post_meta($product->get_id(),'merchant_city',true) ]) ? WC()->countries->get_states('TR')[ get_post_meta($product->get_id(),'merchant_city',true) ] : ''); ?></small></div>
    <div><small><?php echo esc_html(get_post_meta($product->get_id(),'merchant_email',true)); ?> - <?php echo esc_html(get_post_meta($product->get_id(),'merchant_phone',true)); ?></small></div>
  </td>

  <!-- 7) Teslim Süresi -->
  <td>
    <div><small><?php echo esc_html(get_post_meta($order_id,'sutore_shipment_deadline',true)); ?> - <?php echo esc_html(ucfirst(get_post_meta($order_id,'sutore_shipment_type',true))); ?></small></div>
  </td>

  <!-- 9) Durum -->
  <td>
    <div><?php echo get_vendor_product_status($vendor_item_id); ?></div>
    <div><small>
      <?php
        $productSaleStatus = get_post_status($vendor_item_id);
        if($productSaleStatus == 'publish' || $productSaleStatus == 'draft'){
          echo get_vendor_product_expire_time($product->get_id());
        } else if($productSaleStatus == 'shipped-to-sutore' || $productSaleStatus == 'arrived-to-sutore' || $productSaleStatus == 'verified' || $productSaleStatus == 'shipped' || $productSaleStatus == 'paid'){
          echo '<b>' . esc_html(get_post_meta($product->get_id(),'shipment_code',true)) . '</b><br>';
          echo '<b>' . esc_html(get_post_meta($product->get_id(),'sutore_shipment_code',true)) . '</b>';
        }
      ?>
    </small></div>
  </td>
  
  <!-- 5) Ürün Kodu -->
  <td><?php echo esc_html(get_post_meta($product->get_id(), 'urun_kodu', true)); ?></td>

</tr>