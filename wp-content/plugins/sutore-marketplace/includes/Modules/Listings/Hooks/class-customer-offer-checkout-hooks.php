<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Hooks;

use SutoreMarketplace\Modules\Coupons\Support\CouponMeta;
use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferGuardrails;
use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferStatus;
use SutoreMarketplace\Modules\Listings\Repositories\CustomerOfferRepository;
use SutoreMarketplace\Modules\Listings\Services\CustomerOfferService;

final class CustomerOfferCheckoutHooks
{
    public function register(): void
    {
        add_filter('woocommerce_variation_is_purchasable', [$this, 'filterPurchasable'], 20, 2);
        add_filter('woocommerce_is_purchasable', [$this, 'filterPurchasable'], 20, 2);
        add_filter('woocommerce_variation_is_visible', [$this, 'filterVariationVisible'], 20, 4);
        add_filter('woocommerce_coupon_is_valid', [$this, 'validateOfferCoupon'], 25, 3);
        add_action('woocommerce_applied_coupon', [$this, 'autoAddOfferProduct']);
        add_action('woocommerce_add_to_cart', [$this, 'maybeApplyOfferCoupon'], 20, 6);
        add_action('woocommerce_after_add_to_cart_button', [$this, 'renderOfferTrigger'], 20);
        add_action('wp_footer', [$this, 'renderOfferModal']);
        add_action('wp_enqueue_scripts', [$this, 'enqueuePdpAssets']);
    }

    /**
     * @param bool $purchasable
     * @param \WC_Product $product
     */
    public function filterPurchasable($purchasable, $product): bool
    {
        if ($purchasable || !is_object($product) || !method_exists($product, 'get_id')) {
            return (bool) $purchasable;
        }
        if (!CustomerOfferGuardrails::enabled()) {
            return (bool) $purchasable;
        }

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return (bool) $purchasable;
        }

        return (new CustomerOfferService())->currentUserCanPurchaseListing((int) $product->get_id(), $userId);
    }

    /**
     * @param bool $visible
     */
    public function filterVariationVisible($visible, int $variationId, int $parentId, $variation): bool
    {
        unset($parentId, $variation);
        if ($visible || !CustomerOfferGuardrails::enabled()) {
            return (bool) $visible;
        }
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return (bool) $visible;
        }

        return (new CustomerOfferService())->currentUserCanPurchaseListing($variationId, $userId);
    }

    public function validateOfferCoupon(bool $isValid, \WC_Coupon $coupon, $discounts): bool
    {
        if (!$isValid) {
            return false;
        }
        $offerId = (int) $coupon->get_meta(CouponMeta::CUSTOMER_OFFER_ID, true);
        if ($offerId <= 0) {
            return $isValid;
        }

        $offer = (new CustomerOfferRepository())->find($offerId);
        if (!$offer || (string) $offer->status !== CustomerOfferStatus::ACCEPTED) {
            throw new \Exception(__('This offer coupon is no longer valid.', 'sutore-marketplace'));
        }
        if ((int) $offer->customer_id !== get_current_user_id()) {
            throw new \Exception(__('This offer coupon belongs to another customer.', 'sutore-marketplace'));
        }

        unset($discounts);

        return true;
    }

    public function autoAddOfferProduct(string $couponCode): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        $coupon = new \WC_Coupon($couponCode);
        $listingId = (int) $coupon->get_meta(CouponMeta::CUSTOMER_OFFER_LISTING, true);
        if ($listingId <= 0) {
            return;
        }
        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (is_object($product) && (int) $product->get_id() === $listingId) {
                return;
            }
        }
        WC()->cart->add_to_cart($listingId, 1);
    }

    /**
     * Apply the personal offer coupon after the listing is in the cart
     * (product-restricted coupons fail if applied on add-to-cart validation).
     */
    public function maybeApplyOfferCoupon(
        string $cartItemKey,
        int $productId,
        int $quantity,
        int $variationId,
        $variation,
        $cartItemData
    ): void {
        unset($cartItemKey, $quantity, $variation, $cartItemData);
        if (!is_user_logged_in() || !function_exists('WC') || !WC()->cart) {
            return;
        }
        $listingId = $variationId > 0 ? $variationId : $productId;
        $offer = (new CustomerOfferRepository())->findAcceptedForListingAndCustomer(
            $listingId,
            get_current_user_id()
        );
        if (!$offer || (string) ($offer->coupon_code ?? '') === '') {
            return;
        }
        $code = (string) $offer->coupon_code;
        if (!WC()->cart->has_discount($code)) {
            WC()->cart->apply_coupon($code);
        }
    }

    public function renderOfferTrigger(): void
    {
        if (!is_product() || !CustomerOfferGuardrails::enabled()) {
            return;
        }

        $themeButton = function_exists('wc_wp_theme_get_element_class_name')
            ? (string) wc_wp_theme_get_element_class_name('button')
            : '';
        $btnClass = 'button sutore-mp-pdp-offer-btn';
        if ($themeButton !== '') {
            $btnClass .= ' ' . $themeButton;
        }

        echo '<button type="button" class="' . esc_attr($btnClass) . '" hidden data-sutore-pdp-offer-open aria-haspopup="dialog">';
        echo esc_html__('Make an offer', 'sutore-marketplace');
        echo '</button>';
    }

    public function renderOfferModal(): void
    {
        if (!is_product() || !CustomerOfferGuardrails::enabled()) {
            return;
        }

        echo '<div class="sutore-mp-pdp-offer-overlay" hidden data-sutore-pdp-offer-modal>';
        echo '<div class="sutore-mp-pdp-offer-modal" role="dialog" aria-modal="true" aria-labelledby="sutore-mp-pdp-offer-title" tabindex="-1">';
        echo '<div class="sutore-mp-pdp-offer-modal__head">';
        echo '<h2 id="sutore-mp-pdp-offer-title" class="sutore-mp-pdp-offer-modal__title">';
        echo esc_html__('Make an offer', 'sutore-marketplace');
        echo '</h2>';
        echo '<button type="button" class="sutore-mp-pdp-offer-modal__close" data-sutore-pdp-offer-close aria-label="'
            . esc_attr__('Close', 'sutore-marketplace') . '">&times;</button>';
        echo '</div>';
        echo '<div class="sutore-mp-pdp-offer-modal__body">';
        echo '<p class="sutore-mp-pdp-offer__lead" data-sutore-pdp-offer-lead></p>';
        echo '<form class="sutore-mp-pdp-offer__form" action="#">';
        echo '<label class="sutore-mp-pdp-offer__label" for="sutore-mp-pdp-offer-bid">';
        echo esc_html__('Your offer (seller price, TL)', 'sutore-marketplace');
        echo '</label>';
        echo '<input type="number" id="sutore-mp-pdp-offer-bid" name="bid_amount" class="sutore-mp-pdp-offer__input" min="1" step="1" inputmode="numeric" />';
        echo '<button type="submit" class="button alt wp-element-button sutore-mp-pdp-offer__submit">';
        echo esc_html__('Send offer', 'sutore-marketplace');
        echo '</button>';
        echo '</form>';
        echo '<p class="sutore-mp-pdp-offer__status" role="status"></p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    public function enqueuePdpAssets(): void
    {
        if (!is_product() || !CustomerOfferGuardrails::enabled()) {
            return;
        }

        wp_enqueue_style(
            'sutore-marketplace-pdp-offers',
            SUTORE_MARKETPLACE_URL . 'assets/css/marketplace-pdp-offers.css',
            [],
            (string) (is_file(SUTORE_MARKETPLACE_PATH . 'assets/css/marketplace-pdp-offers.css')
                ? (int) filemtime(SUTORE_MARKETPLACE_PATH . 'assets/css/marketplace-pdp-offers.css')
                : SUTORE_MARKETPLACE_VERSION)
        );
        wp_enqueue_script(
            'sutore-marketplace-pdp-offers',
            SUTORE_MARKETPLACE_URL . 'assets/js/marketplace-pdp-offers.js',
            ['jquery', 'sutore-marketplace-core'],
            (string) (is_file(SUTORE_MARKETPLACE_PATH . 'assets/js/marketplace-pdp-offers.js')
                ? (int) filemtime(SUTORE_MARKETPLACE_PATH . 'assets/js/marketplace-pdp-offers.js')
                : SUTORE_MARKETPLACE_VERSION),
            true
        );
        wp_localize_script('sutore-marketplace-pdp-offers', 'SutoreMarketplacePdpOffers', [
            'restUrl' => esc_url_raw(rest_url('sutore-marketplace/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
            'loginUrl' => esc_url_raw(wp_login_url(get_permalink() ?: home_url('/'))),
            'myOffersUrl' => function_exists('wc_get_account_endpoint_url')
                ? esc_url_raw(wc_get_account_endpoint_url('my-offers'))
                : '',
            'i18n' => [
                'makeOffer' => __('Make an offer', 'sutore-marketplace'),
                'sendOffer' => __('Send offer', 'sutore-marketplace'),
                'loginToOffer' => __('Log in to make an offer.', 'sutore-marketplace'),
                'pending' => __('You already have a pending offer on this size.', 'sutore-marketplace'),
                'accepted' => __('Your offer was accepted. Use your coupon at checkout.', 'sutore-marketplace'),
                'offerPending' => __('Offer pending', 'sutore-marketplace'),
                'offerAccepted' => __('Offer accepted', 'sutore-marketplace'),
                'minBid' => __('Minimum offer', 'sutore-marketplace'),
                'asking' => __('Seller price', 'sutore-marketplace'),
                'listedPrice' => __('Listed price', 'sutore-marketplace'),
                'yourOffer' => __('Your offer', 'sutore-marketplace'),
                'bidHigh' => __('Your offer must be below the current price. Add the product to your cart to buy at the listed price.', 'sutore-marketplace'),
                'sent' => __('Your offer was sent to the seller.', 'sutore-marketplace'),
                'error' => __('Error', 'sutore-marketplace'),
                'viewOffers' => __('View my offers', 'sutore-marketplace'),
            ],
        ]);
    }
}
