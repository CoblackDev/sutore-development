<?php
/*

Plugin Name: Sutore Kupon Eklentisi

Plugin URI: https://tolgahandemir.com

Description: Sutore.com için kupon eklentisi

Version: 1.0.0

Author: Tolgahan Demir

Author URI: https://tolgahandemir.com

License: GPLv2 or later

Text Domain: sutore

*/

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Eklenti için gerekli script ve stilleri yükler.
 * - Dosya değiştirilme zamanını kullanarak otomatik cache-busting (önbellek temizleme) yapar.
 * - JS dosyasını sayfa performansını artırmak için footer'da yükler.
 */
function sutore_coupon_scripts() {
    // JS dosyası sadece ödeme sayfasında yüklenecek
    if (is_checkout()) {
        // --- JS Versiyonlama ve Yükleme ---
        $plugin_path = plugin_dir_path(__FILE__);
        $js_file_path = $plugin_path . 'js/coupon.js';
        $js_file_url  = plugin_dir_url(__FILE__) . 'js/coupon.js';

        // Dosyanın varlığını kontrol edip, son değiştirilme zamanını versiyon olarak kullanıyoruz.
        // Bu sayede dosyayı her güncellediğinizde, tarayıcılar yeni versiyonu indirir.
        $js_version = file_exists($js_file_path) ? filemtime($js_file_path) : '1.0.0';

        // Script'i enqueue ederken son parametre olarak 'true' eklemek,
        // script'in sayfanın en altında (footer) yüklenmesini sağlar. Bu, sayfa açılış hızını artırır.
        wp_enqueue_script('sutore-coupon-js', $js_file_url, array('jquery'), $js_version, true);

        // wp_localize_script, ilgili script'ten hemen sonra çağrılmalıdır.
        wp_localize_script('sutore-coupon-js', 'coupon', array(
            'ajaxurl'            => admin_url('admin-ajax.php'),
            'remove_coupon_text' => __("Remove", "sutore"),
            'apply_coupon_text'  => __("Apply", "sutore"),
            'nonce'              => wp_create_nonce('sutore_coupon_nonce'),
        ));
    }

    // CSS dosyası sepet veya ödeme sayfasında yüklenecek
    if (is_cart() || is_checkout()) {
        // --- CSS Versiyonlama ---
        $plugin_path  = plugin_dir_path(__FILE__);
        $css_file_path = $plugin_path . 'css/coupon.css';
        $css_file_url  = plugin_dir_url(__FILE__) . 'css/coupon.css';

        // CSS dosyası için de aynı versiyonlama mantığını kullanıyoruz.
        $css_version = file_exists($css_file_path) ? filemtime($css_file_path) : '1.0.0';

        wp_enqueue_style('sutore-coupon-css', $css_file_url, array(), $css_version);
    }
}
add_action('wp_enqueue_scripts', 'sutore_coupon_scripts');

function add_sutore_coupon()
{
    // Nonce doğrulaması
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sutore_coupon_nonce')) {
        wp_send_json(array('status' => false, 'message' => __("Nonce verification failed.", "sutore")));
        wp_die(); // AJAX işlemini sonlandır
    }

    // Başlangıç kontrolleri aynı kalıyor
    if (!isset($_POST['coupon_code'])) {
        wp_send_json(array('status' => false, 'message' => __("Coupon code is required.", "sutore")));
        wp_die(); // AJAX işlemini sonlandır
    }

    $coupon_code = sanitize_text_field($_POST['coupon_code']);
    $lockout_time =  WC()->session->get('coupon_lockout_time');

    // Check if the user is locked out
    if (!empty($lockout_time) && current_time('timestamp') < $lockout_time) { // 30 minutes lockout
        wp_send_json(array('status' => false, 'message' => __("We are currently unable to process your request. If you believe this is an error, please contact our customer service.", "sutore")));
        wp_die(); // AJAX işlemini sonlandır
    } else {
        WC()->session->set('coupon_lockout_time', 0);
    }

    // Kupon zaten uygulanmış mı kontrolü aynı kalıyor
    if (WC()->cart->has_discount($coupon_code)) {
        wp_send_json(array('status' => false, 'message' => __("Coupon already applied.", "sutore")));
        wp_die(); // AJAX işlemini sonlandır
    }

    // Kuponu uygulamayı dene
    $applied = WC()->cart->add_discount($coupon_code);

    if ($applied) {
        // Başarılı durum aynı kalıyor
        wp_send_json(array(
            'status' => true,
            'message' => sprintf(__("Your %s coupon code successfully applied to cart.", "sutore"), "₺" . WC()->cart->get_discount_total()), // Mevcut mesaj
            'cart_total' => coupon_cart_total(), // Mevcut fonksiyon
            "fees" => WC()->cart->get_fees() // Mevcut çağrı
        ));
        
        // Reset failed attempts on success
        WC()->session->set('failed_coupon_attempts', 0);
        WC()->session->set('coupon_lockout_time', 0);
    } else {
        // --- HATA DURUMU - GÜNCELLENEN KISIM ---
        $error_message = __("Invalid coupon code.", "sutore");
        $failed_attempts = null !== WC()->session->get('failed_coupon_attempts') ? WC()->session->get('failed_coupon_attempts') : 0;

        $failed_attempts++;
        WC()->session->set('failed_coupon_attempts', $failed_attempts);
        if ($failed_attempts >= 5) {
            WC()->session->set('failed_coupon_attempts', 0);
            WC()->session->set('coupon_lockout_time', current_time('timestamp') + 900); // Set lockout time on first failure (15 minutes)
        }

        // WooCommerce tarafından ayarlanan hata bildirimlerini al ('error' tipindekileri)
        if (function_exists('wc_get_notices')) { // Fonksiyonun varlığını kontrol et
            $error_notices = wc_get_notices('error');

            // AJAX yanıtından sonra bu bildirimlerin sayfada tekrar görünmemesi için temizle
            wc_clear_notices();

            if (!empty($error_notices)) {
                $last_notice_data = end($error_notices); // Son bildirimi al
                $message_text = is_array($last_notice_data) && isset($last_notice_data['notice']) ? $last_notice_data['notice'] : $last_notice_data;

                // Mesajdan HTML etiketlerini temizleyelim (JS tarafında sorun olmasın)
                $error_message = wp_strip_all_tags($message_text);
            }
        }
        // --- GÜNCELLENEN KISIM SONU ---

        // WooCommerce'den alınan veya varsayılan hata mesajını gönder
        wp_send_json(array('status' => false, 'message' => $error_message, "failed_attempts" => $failed_attempts, "lockout_time" => $lockout_time));
    }
    wp_die(); // AJAX işlemini her durumda sonlandır
}

// Bu action hook'ları aynı kalıyor
add_action('wp_ajax_add_sutore_coupon', 'add_sutore_coupon');
add_action('wp_ajax_nopriv_add_sutore_coupon', 'add_sutore_coupon');

function remove_sutore_coupon()
{
    // Nonce doğrulaması
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sutore_coupon_nonce')) {
        wp_send_json(array('status' => false, 'message' => __("Nonce verification failed.", "sutore")));
        wp_die();
    }
    
    // Remove all applied coupons
    if (function_exists('WC')) {
        WC()->cart->remove_coupons();
        WC()->cart->calculate_totals(); // Ensure cart totals are recalculated after removing coupons
        wp_send_json(array('status' => true, 'message' => __("Coupon removed successfully.", "sutore"), 'cart_total' => WC()->cart->get_total()));
    } else {
        wp_send_json(array('status' => false, 'message' => __("WooCommerce is not active.", "sutore")));
    }
}



function display_cart_totals()
{
    if ( wp_doing_ajax() && function_exists('WC') && WC()->cart ) {
        WC()->cart->calculate_totals();
        WC()->session->save_data();
    }

    ob_start();
    $cart = WC()->cart;
    ?>
    <div class="cart-bottom">
        <div class="gradient-border"></div>
        <div class="cart-bottom-content">
            <div class="cart-bottom-container">
                <div class="privacy-policy">
                    <?php echo sprintf(__('By continuing, you agree to the <a href="%s" style="display:contents;">%s</a> for the processing of your personal data.', 'sutore'), esc_url(home_url('/privacy-policy')), __('Privacy Policy', 'sutore')); ?>
                </div>
                <div class="cart-totals">
                    <div class="width-60">
                        <div>
                            <?php esc_html_e('Total', 'woocommerce'); ?>:
                            <span class="cart-total">
                                <?php
                                if ( wp_doing_ajax() || is_checkout() ) {
                                    echo coupon_cart_total();
                                } elseif ( $cart && $cart->has_discount() ) {
                                    echo coupon_cart_total();
                                } else {
                                    echo $cart ? $cart->get_total() : wc_price(0);
                                }
                                ?>
                            </span>
                        </div>
                        <div class="cargo-label"><?php esc_html_e('Free Shipping', 'woocommerce'); ?></div>
                    </div>
                    <div class="width-40">
                        <?php if (is_cart() || wp_doing_ajax()) : ?>
                            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="to-checkout"><?php esc_html_e('Buy', 'sutore'); ?> →</a>
                        <?php else : ?>
                            <button type="submit" class="to-checkout"><?php esc_html_e('Checkout', 'sutore'); ?> →</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}



function display_cart_summary()
{
    ob_start(); // Start output buffering
?>
    <div class="<?php echo !wp_is_mobile() ? 'large-4 col' : null; ?> checkout-summary-container">
        <div class="checkout-summary">
        <h3>
                <?php esc_html_e('Cart Summary', 'sutore'); ?>
                <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="edit-cart-link">
                    [<?php esc_html_e('Edit', 'sutore'); ?>]
                </a>
            </h3>
            <div class="cart-items">
                <?php foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) :
                    $product = $cart_item['data'];
                    $product_name = $product->get_name();
                    $product_price = wc_price($product->get_price());
                    $product_size = get_post_meta($cart_item['product_id'], 'size', true); // Assuming 'size' is stored as post meta
                    $product_image = wp_get_attachment_image_src($product->get_image_id(), 'woocommerce_thumbnail'); ?>
                    <div class="cart-item">
                        <div class="item-image">
                            <img src="<?php echo esc_url($product_image[0]); ?>" />
                        </div>
                        <div class="item-info">
                            <div class="item-name"><?php echo esc_html($product_name); ?><span class="item-size"><?php echo esc_html($product_size); ?></span></div>

                            <?php if ((get_option("custom_campaing_active") == 1 && has_discounted_category($cart_item['product_id']))) : ?>
                                <div class="item-price"><?php echo campaing_discounted_price_html($cart_item['data']->get_id()); ?></div>
                            <?php elseif (is_product_discounted($cart_item['variation_id'])) : ?>
                                <div class="item-price"><?php echo campaing_discounted_price_html($cart_item['variation_id']); ?></div>
                            <?php else : ?>
                                <div class="item-price"><?php echo $product_price; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>


            <?php
            $coupon_codes = WC()->cart->get_coupons();
            $coupon_code = key($coupon_codes); // Get the first applied coupon code
            $coupon_discount = WC()->cart->get_discount_total(); // Get the total discount amount
            ?>


            <div class="coupon-container">
                <div class="coupon-item-label"><?php esc_attr_e('Coupon code', 'sutore'); ?></div>
                <div class="coupon-item-value">
                    <input type="text" name="sutore_coupon_code" class="coupon-code" id="sutore_coupon_code" value="<?php echo WC()->cart->has_discount() ? $coupon_code : null; ?>" placeholder="<?php esc_attr_e('Enter Coupon Code', 'sutore'); ?>" <?php echo WC()->cart->has_discount() ? 'disabled' : null; ?> />
                    <button type="button" class="<?php echo WC()->cart->has_discount() ? 'coupon-remove-button' : 'coupon-apply-button'; ?>"><?php echo WC()->cart->has_discount() ? esc_attr_e('Remove', 'sutore') : esc_attr_e('Apply', 'sutore'); ?></button>
                </div>
                <div class="coupon-message <?php echo WC()->cart->has_discount() ? 'coupon-message-success' : null; ?>">
                    <?php echo WC()->cart->has_discount() ? sprintf(__("Your %s coupon code successfully applied to cart.", "sutore"), "₺" . WC()->cart->get_discount_total()) : null; ?>
                </div>
            </div>
        </div>
    </div>
<?php
    return ob_get_clean(); // Return the buffered content
}


add_action('wp_ajax_remove_sutore_coupon', 'remove_sutore_coupon');
add_action('wp_ajax_nopriv_remove_sutore_coupon', 'remove_sutore_coupon');

function coupon_cart_total()
{
    $cart = WC()->cart;
    if ( ! $cart ) {
        return '';
    }

    $discount = 0.0;

    foreach ( $cart->get_applied_coupons() as $coupon_code ) {
        $coupon = new WC_Coupon( $coupon_code );
        if ( $coupon->get_id() <= 0 ) {
            continue;
        }

        if ( $coupon->get_discount_type() === 'percent' ) {
            $brand_ids = $coupon->get_meta( 'product_brands', true );
            if ( ! empty( $brand_ids ) ) {
                if ( ! is_array( $brand_ids ) ) {
                    $brand_ids = array_map( 'trim', explode( ',', $brand_ids ) );
                }
                $brand_ids = array_filter( $brand_ids );

                $brand_cart_total = 0;
                foreach ( $cart->get_cart() as $cart_item ) {
                    $product_id       = $cart_item['product_id'];
                    $item_brand_terms = wp_get_post_terms( $product_id, 'product_brand', [ 'fields' => 'ids' ] );
                    if ( ! empty( $item_brand_terms ) && array_intersect( $brand_ids, $item_brand_terms ) ) {
                        $brand_cart_total += $cart_item['line_subtotal'];
                    }
                }

                if ( $brand_cart_total > 0 ) {
                    $discount += ( $brand_cart_total * $coupon->get_amount() ) / 100;
                }
            } else {
                $discount += ( $cart->get_subtotal() * $coupon->get_amount() ) / 100;
            }
        } else {
            $discount += (float) $cart->get_coupon_discount_amount( $coupon_code );
        }
    }

    if ( $cart->has_discount() ) {
        return '<span class="before-discount">' . wc_price( $cart->get_total( 'edit' ) + $discount ) . '</span> ' . wc_price( $cart->get_total( 'edit' ) );
    }

    return $cart->get_total();
}

function coupon_calculate_fix($order) {
    // $coupons = $order->get_used_coupons(); // Get the applied coupons

    // if (!empty($coupons)) {
    //     foreach ($coupons as $coupon_code) {
    //         $order->remove_coupon($coupon_code); // Remove the coupon
    //     }
    // }

    // $order->calculate_totals(); 
    // $order->save(); 

    // if (!empty($coupons)) {
    //     foreach ($coupons as $coupon_code) {
    //         $order->apply_coupon($coupon_code);
    //     }
    // }

    $order->recalculate_coupons();
    $order->save();
}

/**
 * Marka bazlı kampanya kurallarını tanımlar ve döndürür.
 *
 * Yapı:
 * 'marka-slugi' => [
 *      'required_count'   => (int) İndirim için gerekli minimum ürün adedi.
 *      'discount_percent' => (int) Uygulanacak yüzde indirimi.
 *      'coupon_code'      => (string) Bu kampanya için kullanılacak kupon kodu.
 *      'priority'         => (int) Mesaj gösterim önceliği (daha düşük sayı, daha yüksek öncelik).
 *      'color'            => (string) Bildirimler için kullanılacak hex renk kodu.
 * ]
 */
 function sutore_get_brand_campaign_rules() {
    // Statik değişken, fonksiyon çağrıları arasında değerini korur.
    static $sutore_campaigns = null;

    // Eğer kurallar daha önce hesaplanmışsa, doğrudan önbellekten döndür.
    if ($sutore_campaigns !== null) {
        return $sutore_campaigns;
    }

    // BURAYI KENDİ MARKALARINIZA GÖRE DÜZENLEYİN
    $campaigns = array(
        'kujten' => array(
            'required_count'   => 2,
            'discount_percent' => 10,
            'coupon_code'      => 'KUJTEN10',
            'priority'         => 10,
            'color'            => '#4caf50' // Marka rengi veya bildirim rengi
        ),
        'rhode' => array(
            'required_count'   => 3,
            'discount_percent' => 10,
            'coupon_code'      => 'RHODE10',
            'priority'         => 20,
            'color'            => '#4caf50'
        ),
        'patrick-ta' => array(
            'required_count'   => 3,
            'discount_percent' => 10,
            'coupon_code'      => 'PATRICKTA10',
            'priority'         => 30,
            'color'            => '#4caf50'
        ),
        'glossier' => array(
            'required_count'   => 3,
            'discount_percent' => 10,
            'coupon_code'      => 'GLOSSIER10',
            'priority'         => 40,
            'color'            => '#4caf50'
        ),
        'pop-mart' => array(
            'required_count'   => 3,
            'discount_percent' => 10,
            'coupon_code'      => 'POPMART10',
            'priority'         => 50,
            'color'            => '#4caf50' // Marka rengi veya bildirim rengi
        ),
        'sonny-angel' => array(
            'required_count'   => 3,
            'discount_percent' => 10,
            'coupon_code'      => 'SONNY10',
            'priority'         => 60,
            'color'            => '#4caf50'
        ),
        'vehla' => array(
            'required_count'   => 2,
            'discount_percent' => 10,
            'coupon_code'      => 'VEHLA10',
            'priority'         => 70,
            'color'            => '#4caf50'
        ),
        'alo-yoga' => array(
            'required_count'   => 2,
            'discount_percent' => 10,
            'coupon_code'      => 'ALO10',
            'priority'         => 80,
            'color'            => '#4caf50'
        ),
        'skims' => array(
            'required_count'   => 2,
            'discount_percent' => 10,
            'coupon_code'      => 'SKIMS10',
            'priority'         => 90,
            'color'            => '#4caf50'
        ),
        'longchamp' => array(
            'required_count'   => 2,
            'discount_percent' => 10,
            'coupon_code'      => 'LONGCHAMP10',
            'priority'         => 100,
            'color'            => '#4caf50'
        ),
        'refy' => array(
            'required_count'   => 3,
            'discount_percent' => 10,
            'coupon_code'      => 'REFY10',
            'priority'         => 110,
            'color'            => '#4caf50'
        ),
        'hourglass' => array(
            'required_count'   => 3,
            'discount_percent' => 10,
            'coupon_code'      => 'HOURGLASS10',
            'priority'         => 120,
            'color'            => '#4caf50'
        ),
        'agatha' => array(
            'required_count'   => 3,
            'discount_percent' => 10,
            'coupon_code'      => 'AGATHA10',
            'priority'         => 130,
            'color'            => '#4caf50'
        ),
        'urban-sophistication' => array(
            'required_count'   => 2,
            'discount_percent' => 10,
            'coupon_code'      => 'URBAN10',
            'priority'         => 140,
            'color'            => '#4caf50' 
        ),
        'aesop' => array(
            'required_count'   => 3,
            'discount_percent' => 10,
            'coupon_code'      => 'AESOP10',
            'priority'         => 150,
            'color'            => '#4caf50'
        ),
        'stanley' => array(
            'required_count'   => 2,
            'discount_percent' => 10,
            'coupon_code'      => 'STANLEY10',
            'priority'         => 160,
            'color'            => '#4caf50'
        ),
        'vivienne-westwood' => array(
            'required_count'   => 2,
            'discount_percent' => 10,
            'coupon_code'      => 'VIVIENNE10',
            'priority'         => 170,
            'color'            => '#4caf50'
        ),
    );

    // Kampanyaları öncelik sırasına göre sırala
    uasort($campaigns, function ($a, $b) {
        return $a['priority'] <=> $b['priority'];
    });
    
    // Sonucu statik değişkene ata
    $sutore_campaigns = $campaigns;

    return $sutore_campaigns;
}

/**
 * YENİDEN DÜZENLENDİ: Sepeti analiz eder ve öncelik sırasına göre en fazla 3 tam kampanya bildirimi gösterir.
 */
function sutore_display_brand_campaign_notices() {
    // 1. Temel Kontroller
    if (!is_cart() || WC()->cart->is_empty()) {
        return;
    }

    $rules = sutore_get_brand_campaign_rules();
    
    // Uygulanmış bir kampanya kuponu varsa hiçbir mesaj gösterme
    $campaign_coupons = array_map('strtolower', wp_list_pluck($rules, 'coupon_code'));
    $applied_coupons  = array_map('strtolower', WC()->cart->get_applied_coupons());
    if (!empty(array_intersect($campaign_coupons, $applied_coupons))) {
        return;
    }

    // 2. ADIM: Marka sayılarını BASİTÇE hesapla ve potansiyel bildirimleri topla
    $potential_notices = [];
    foreach ($rules as $brand_slug => $rule) {
        $brand_count = 0;
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (has_term($brand_slug, 'product_brand', $cart_item['product_id'])) {
                $brand_count += $cart_item['quantity'];
            }
        }
        
        if ($brand_count > 0) {
            $potential_notices[] = [
                'slug'           => $brand_slug,
                'rule'           => $rule,
                'current_count'  => $brand_count,
            ];
        }
    }

    if (empty($potential_notices)) {
        return;
    }

    // 3. ADIM: Potansiyel bildirimleri döngüye al ve en fazla 3 tanesini göster
    $notices_shown = 0;
    foreach ($potential_notices as $notice_data) {
        
        // Gösterim limitine ulaşıldıysa döngüden çık
        if ($notices_shown >= 2) {
            break;
        }

        // --- Her bildirim için tam HTML oluşturma ---
        
        // Manuel link mantığı
        $is_english = (strpos($_SERVER['REQUEST_URI'], '/en/') !== false);
        $link_slug = $is_english ? $notice_data['slug'] . '-en' : $notice_data['slug'];
        $link_term = get_term_by('slug', $link_slug, 'product_brand');
        if (!$link_term) {
            $link_term = get_term_by('slug', $notice_data['slug'], 'product_brand');
        }

        // Eğer marka terimi bulunamazsa bu adımı atla ve bir sonrakine geç
        if (!$link_term) {
            continue;
        }

        // Gerekli değişkenleri hazırla
        $brand_name     = $link_term->name;
        $brand_url      = get_term_link($link_term);
        $rule           = $notice_data['rule'];
        $current_count  = $notice_data['current_count'];
        $required_count = $rule['required_count'];
        $border_color   = esc_attr($rule['color']);
        $message        = '';
        
        // Durum A: Şartlar sağlandı (Tebrikler HTML'i)
        if ($current_count >= $required_count) {
            $message = sprintf(
                '<div class="sutore-campaign-notice sutore-success" style="border-left-color: %s;">' .
                '<div class="sutore-progress-text">' .
                '<p>%s! %s <strong>%s</strong> %s</p>' .
                '<span>%s: <strong>%s</strong></span>' .
                '</div>' .
                '</div>',
                $border_color,
                __('Congratulations', 'sutore'),
                __('You\'ve unlocked a', 'sutore'),
                $rule['discount_percent'] . '% ' . esc_html($brand_name),
                __('discount.', 'sutore'),
                __('Use coupon code at checkout', 'sutore'),
                esc_html($rule['coupon_code'])
            );
        } 
        // Durum B: Şartlara az kaldı (İlerleme Çubuğu HTML'i)
        else {
            $needed = $required_count - $current_count;
            $progress_percent = ($current_count / $required_count) * 100;

            $message = sprintf(
                '<div class="sutore-campaign-notice sutore-progress" style="border-left-color: %s;">' .
                '<div class="sutore-progress-text">' .
                '<p>%s <a href="%s">%s</a> %s</p>' .
                '<span>%s</span>' .
                '</div>' .
                '<div class="sutore-progress-bar-container"><div class="sutore-progress-bar" style="width: %d%%; background-color: %s;"></div></div>' .
                '</div>',
                $border_color,
                __('Add just', 'sutore'),
                esc_url($brand_url),
                sprintf(
                    _n('%1$d more <strong>%2$s</strong> item', '%1$d more <strong>%2$s</strong> items', $needed, 'sutore'),
                    $needed,
                    esc_html($brand_name)
                ),
                __('to unlock your discount!', 'sutore'),
                sprintf(__('%d of %d items added', 'sutore'), $current_count, $required_count),
                (int)$progress_percent,
                $border_color
            );
        }
        
        // Mesajı göster ve sayacı artır
        wc_add_notice($message, 'notice');
        $notices_shown++;
    }
}
add_action('woocommerce_before_cart', 'sutore_display_brand_campaign_notices');

/**
 * Marka kampanyası kuponlarının geçerliliğini özel olarak kontrol eder.
 *
 * @param bool $is_valid Mevcut geçerlilik durumu.
 * @param WC_Coupon $coupon Kontrol edilen kupon nesnesi.
 * @return bool
 */
function sutore_validate_brand_campaign_coupon($is_valid, $coupon, $discounts = null) {

    $rules = sutore_get_brand_campaign_rules();
    $coupon_code = strtolower($coupon->get_code());
    
    // Girilen kupon, bizim kampanya kuponlarımızdan biri mi?
    $matched_rule = null;
    $matched_brand_slug = null;
    foreach ($rules as $brand_slug => $rule) {
        if (strtolower($rule['coupon_code']) === $coupon_code) {
            $matched_rule = $rule;
            $matched_brand_slug = $brand_slug;
            break;
        }
    }

    // Bizim kuponumuz değilse, dokunmadan devam et.
    if (!$matched_rule) {
        return $is_valid;
    }

    // Bağlama göre ürünleri al (sipariş mi, sepet mi?)
    $items = array();
    $context = 'cart';

    // WC_Discounts nesnesinden bağlamı belirle (WooCommerce 3.2+)
    if ($discounts && method_exists($discounts, 'get_object')) {
        $object = $discounts->get_object();
        if ($object instanceof WC_Order) {
            $context = 'order';
            foreach ($object->get_items() as $item) {
                $items[] = array(
                    'product_id' => $item->get_product_id(),
                    'quantity'   => $item->get_quantity(),
                );
            }
        }
    }

    // Sipariş bağlamı değilse sepetten al
    if ($context === 'cart') {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return $is_valid;
        }
        foreach (WC()->cart->get_cart() as $cart_item) {
            $items[] = array(
                'product_id' => $cart_item['product_id'],
                'quantity'   => $cart_item['quantity'],
            );
        }
    }

    if (empty($items)) {
        return $is_valid;
    }

    // İlgili marka ürünlerini say ve tüm marka dağılımını topla
    $brand_count = 0;
    $brand_summary = array();
    foreach ($items as $item) {
        if (has_term($matched_brand_slug, 'product_brand', $item['product_id'])) {
            $brand_count += $item['quantity'];
        }
        $item_brands = wp_get_post_terms($item['product_id'], 'product_brand', array('fields' => 'names'));
        $item_brand_name = !empty($item_brands) ? implode(', ', $item_brands) : __('No brand', 'sutore');
        if (!isset($brand_summary[$item_brand_name])) {
            $brand_summary[$item_brand_name] = 0;
        }
        $brand_summary[$item_brand_name] += $item['quantity'];
    }

    // Şart sağlanıyor mu?
    if ($brand_count < (int) $matched_rule['required_count']) {
    
        $brand_term = get_term_by('slug', $matched_brand_slug, 'product_brand');
        $brand_name = $brand_term ? $brand_term->name : $matched_brand_slug;
    
        $error_message = sprintf(
            __('%s kuponunu kullanmak için sepetinizde en az %d adet %s ürünü bulunması gerekir.', 'sutore'),
            '<strong>' . esc_html(strtoupper($coupon_code)) . '</strong>',
            (int) $matched_rule['required_count'],
            '<strong>' . esc_html($brand_name) . '</strong>'
        );
    
        throw new Exception( $error_message );
    }
    
    // Tüm kontrollerden geçti, geçerli.
    return $is_valid;
}
add_filter('woocommerce_coupon_is_valid', 'sutore_validate_brand_campaign_coupon', 10, 3);