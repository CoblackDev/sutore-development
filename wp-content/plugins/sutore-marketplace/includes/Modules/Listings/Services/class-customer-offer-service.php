<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Coupons\Support\CouponMeta;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferGuardrails;
use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferStatus;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingConditionRank;
use SutoreMarketplace\Modules\Listings\Domain\ListingPriceValidator;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\CustomerOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class CustomerOfferService
{
    public function __construct(
        private readonly CustomerOfferRepository $offers = new CustomerOfferRepository(),
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
        private readonly NotificationService $notifications = new NotificationService(),
    ) {
    }

    /**
     * @return array{offer_id:int, listing_id:int, expires_at:?string}|\WP_Error
     */
    public function create(int $customerId, int $variationId, float $bidAmount): array|\WP_Error
    {
        if (!CustomerOfferGuardrails::enabled()) {
            return new \WP_Error(
                'sutore_customer_offer_disabled',
                __('Customer offers are not available.', 'sutore-marketplace'),
                ['status' => 403]
            );
        }

        if ($customerId <= 0) {
            return new \WP_Error(
                'sutore_marketplace_auth',
                __('You must log in.', 'sutore-marketplace'),
                ['status' => 401]
            );
        }

        $listing = $this->listings->find($variationId);
        if (!$listing || !$listing->variationId) {
            return new \WP_Error('sutore_customer_offer_listing', __('Product not found.', 'sutore-marketplace'));
        }

        if ((int) $listing->merchantId === $customerId) {
            return new \WP_Error(
                'sutore_customer_offer_own',
                __('You cannot make an offer on your own product.', 'sutore-marketplace')
            );
        }

        $eligible = $this->assertListingCanReceiveOffer($listing);
        if (is_wp_error($eligible)) {
            return $eligible;
        }

        if ($listing->listingStatus !== ListingStatus::PUBLISH) {
            return new \WP_Error(
                'sutore_customer_offer_not_on_sale',
                __('Offers can only be sent to the product that is currently for sale.', 'sutore-marketplace')
            );
        }

        $existing = $this->offers->findPendingForCustomerProductSize(
            $customerId,
            (int) $listing->parentProductId,
            (int) $listing->sizeTermId
        );
        if ($existing) {
            return new \WP_Error(
                'sutore_customer_offer_pending',
                __('You already have a pending offer on this product and size.', 'sutore-marketplace')
            );
        }

        $acceptedExisting = $this->offers->findAcceptedForListingAndCustomer((int) $listing->variationId, $customerId);
        if ($acceptedExisting) {
            return new \WP_Error(
                'sutore_customer_offer_accepted',
                __('You already have an accepted offer on this product.', 'sutore-marketplace')
            );
        }

        $dayCap = CustomerOfferGuardrails::maxPerDay();
        if ($this->offers->countCreatedToday($customerId) >= $dayCap) {
            return new \WP_Error(
                'sutore_customer_offer_daily_limit',
                sprintf(
                    /* translators: %d: max offers per day */
                    __('You can send at most %d offers per day.', 'sutore-marketplace'),
                    $dayCap
                )
            );
        }

        $bid = ListingPriceValidator::requireValidAsking($bidAmount);
        if (is_wp_error($bid)) {
            return $bid;
        }

        $asking = (float) $listing->asking;
        $minBid = CustomerOfferGuardrails::minBidForAsking($asking);
        if ((int) $bid >= (int) $asking) {
            return new \WP_Error(
                'sutore_customer_offer_bid_high',
                __('Your offer must be below the current price. Add the product to your cart to buy at the listed price.', 'sutore-marketplace')
            );
        }
        if ((int) $bid < $minBid) {
            return new \WP_Error(
                'sutore_customer_offer_bid_low',
                sprintf(
                    /* translators: 1: minimum bid, 2: percent */
                    __('The minimum offer is %1$s (%2$s%% of the price).', 'sutore-marketplace'),
                    MarketplacePricing::formatTl((float) $minBid),
                    (string) CustomerOfferGuardrails::minPercent()
                )
            );
        }

        $couponAmount = $this->couponAmountForBid($asking, (float) $bid);
        if ($couponAmount <= 0) {
            return new \WP_Error(
                'sutore_customer_offer_bid',
                __('This offer would not change the checkout price.', 'sutore-marketplace')
            );
        }

        $expiresAt = CampaignDatetime::plusHours(CustomerOfferGuardrails::autoDeclineHours());
        $offerId = $this->offers->create([
            'customer_id' => $customerId,
            'listing_id' => (int) $listing->variationId,
            'parent_product_id' => (int) $listing->parentProductId,
            'size_term_id' => (int) $listing->sizeTermId,
            'merchant_id' => (int) $listing->merchantId,
            'bid_amount' => (float) $bid,
            'asking_at_offer' => $asking,
            'status' => CustomerOfferStatus::PENDING,
            'expires_at' => $expiresAt,
            'coupon_id' => null,
            'coupon_code' => '',
            'origin_offer_id' => null,
        ]);

        $this->events->log('customer_offer_sent', [
            'offer_id' => $offerId,
            'customer_id' => $customerId,
            'bid_amount' => (float) $bid,
            'asking_at_offer' => $asking,
        ], (int) $listing->variationId, (int) $listing->merchantId, 'merchant_visible');

        $this->notifyMerchant($listing, $offerId, (float) $bid);

        return [
            'offer_id' => $offerId,
            'listing_id' => (int) $listing->variationId,
            'expires_at' => $expiresAt,
        ];
    }

    public function cancel(int $offerId, int $customerId): true|\WP_Error
    {
        $offer = $this->offers->find($offerId);
        if (!$offer || (int) $offer->customer_id !== $customerId) {
            return new \WP_Error('sutore_customer_offer_missing', __('Offer not found.', 'sutore-marketplace'));
        }
        $cancelled = $this->offers->updateIfStatus($offerId, CustomerOfferStatus::PENDING, [
            'status' => CustomerOfferStatus::CANCELLED,
            'responded_at' => current_time('mysql'),
        ]);
        if (!$cancelled) {
            return new \WP_Error(
                'sutore_customer_offer_status',
                __('This offer can no longer be cancelled.', 'sutore-marketplace')
            );
        }

        $this->events->log('customer_offer_cancelled', [
            'offer_id' => $offerId,
            'customer_id' => $customerId,
        ], (int) $offer->listing_id, (int) $offer->merchant_id, 'merchant_visible');

        return true;
    }

    /**
     * @return array{offer_id:int, coupon_code:string, expires_at:?string}|\WP_Error
     */
    public function accept(int $offerId, int $merchantId): array|\WP_Error
    {
        $offer = $this->offers->find($offerId);
        if (!$offer || (int) $offer->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_customer_offer_missing', __('Offer not found.', 'sutore-marketplace'));
        }
        if ((string) $offer->status !== CustomerOfferStatus::PENDING) {
            return new \WP_Error(
                'sutore_customer_offer_status',
                __('This offer can no longer be accepted.', 'sutore-marketplace')
            );
        }

        $listing = $this->listings->find((int) $offer->listing_id);
        if (!$listing || !$listing->variationId) {
            return new \WP_Error('sutore_customer_offer_listing', __('Product not found.', 'sutore-marketplace'));
        }
        if ((int) $listing->merchantId !== $merchantId) {
            return new \WP_Error('sutore_customer_offer_missing', __('Offer not found.', 'sutore-marketplace'));
        }
        if (!in_array($listing->listingStatus, [ListingStatus::PUBLISH, ListingStatus::QUEUED], true)) {
            return new \WP_Error(
                'sutore_customer_offer_not_on_sale',
                __('This product is no longer available for an offer.', 'sutore-marketplace')
            );
        }

        $coupon = $this->issueCoupon($offer, $listing);
        if (is_wp_error($coupon)) {
            return $coupon;
        }

        $expiresAt = CampaignDatetime::plusHours(CustomerOfferGuardrails::ttlHours());
        $claimed = $this->offers->updateIfStatus($offerId, CustomerOfferStatus::PENDING, [
            'status' => CustomerOfferStatus::ACCEPTED,
            'coupon_id' => $coupon['coupon_id'],
            'coupon_code' => $coupon['coupon_code'],
            'expires_at' => $expiresAt,
            'responded_at' => current_time('mysql'),
        ]);
        if (!$claimed) {
            $this->discardUnusedCoupon((int) $coupon['coupon_id']);

            return new \WP_Error(
                'sutore_customer_offer_status',
                __('This offer can no longer be accepted.', 'sutore-marketplace')
            );
        }

        $this->events->log('customer_offer_accepted', [
            'offer_id' => $offerId,
            'customer_id' => (int) $offer->customer_id,
            'bid_amount' => (float) $offer->bid_amount,
            'coupon_id' => $coupon['coupon_id'],
        ], (int) $listing->variationId, $merchantId, 'merchant_visible');

        $this->mailCustomerAccepted($offer, $listing, $coupon['coupon_code'], $expiresAt);
        $this->notifyCustomer($offer, NotificationType::CUSTOMER_OFFER_ACCEPTED, [
            'coupon_code' => $coupon['coupon_code'],
            'expires_at' => $expiresAt,
        ]);

        return [
            'offer_id' => $offerId,
            'coupon_code' => $coupon['coupon_code'],
            'expires_at' => $expiresAt,
        ];
    }

    public function decline(int $offerId, int $merchantId): true|\WP_Error
    {
        $offer = $this->offers->find($offerId);
        if (!$offer || (int) $offer->merchant_id !== $merchantId) {
            return new \WP_Error('sutore_customer_offer_missing', __('Offer not found.', 'sutore-marketplace'));
        }
        if (!$this->closePendingAsDeclined($offer, 'merchant')) {
            return new \WP_Error(
                'sutore_customer_offer_status',
                __('This offer can no longer be declined.', 'sutore-marketplace')
            );
        }

        return true;
    }

    public function runExpiryPass(int $limit = 100): int
    {
        $n = 0;
        foreach ($this->offers->findPendingExpired($limit) as $offer) {
            $this->expirePending($offer);
            $n++;
        }
        foreach ($this->offers->findAcceptedExpired($limit) as $offer) {
            $this->expireAccepted($offer);
            $n++;
        }

        return $n;
    }

    public function couponAmountForBid(float $asking, float $bid): float
    {
        $from = MarketplacePricing::listingComparePrice($asking);
        $to = MarketplacePricing::listingComparePrice($bid);

        return round(max(0.0, $from - $to), 2);
    }

    public function bidGrossForPayout(int $listingId, int $orderId): ?float
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return null;
        }

        foreach ($order->get_coupon_codes() as $code) {
            $coupon = new \WC_Coupon((string) $code);
            $offerId = (int) $coupon->get_meta(CouponMeta::CUSTOMER_OFFER_ID, true);
            if ($offerId <= 0) {
                continue;
            }
            $offer = $this->offers->find($offerId);
            if (!$offer || (int) $offer->listing_id !== $listingId) {
                continue;
            }
            if ((string) $offer->status !== CustomerOfferStatus::ACCEPTED) {
                continue;
            }

            return max(0.0, (float) $offer->bid_amount);
        }

        return null;
    }

    public function currentUserCanPurchaseListing(int $listingId, int $userId): bool
    {
        if ($userId <= 0 || $listingId <= 0) {
            return false;
        }
        $offer = $this->offers->findAcceptedForListingAndCustomer($listingId, $userId);
        if (!$offer) {
            return false;
        }
        if (CampaignDatetime::isPast(isset($offer->expires_at) ? (string) $offer->expires_at : null)) {
            return false;
        }

        return true;
    }

    /**
     * @return true|\WP_Error
     */
    private function assertListingCanReceiveOffer(Listing $listing): true|\WP_Error
    {
        if ($listing->campaignStatus !== 'none') {
            return new \WP_Error(
                'sutore_customer_offer_campaign_busy',
                __('This product is in a campaign, so it cannot receive a customer offer.', 'sutore-marketplace')
            );
        }

        return true;
    }

    /**
     * Mark a pending offer declined and forward it when another seller is queued.
     */
    private function closePendingAsDeclined(object $offer, string $reason): bool
    {
        $claimed = $this->offers->updateIfStatus((int) $offer->id, CustomerOfferStatus::PENDING, [
            'status' => CustomerOfferStatus::DECLINED,
            'responded_at' => current_time('mysql'),
        ]);
        if (!$claimed) {
            return false;
        }

        $this->events->log('customer_offer_declined', [
            'offer_id' => (int) $offer->id,
            'customer_id' => (int) $offer->customer_id,
            'bid_amount' => (float) $offer->bid_amount,
            'reason' => $reason,
        ], (int) $offer->listing_id, (int) $offer->merchant_id, 'merchant_visible');

        if ($this->forwardToNextSeller($offer)) {
            $this->notifyCustomer($offer, NotificationType::CUSTOMER_OFFER_FORWARDED);
        } else {
            $this->notifyCustomer($offer, NotificationType::CUSTOMER_OFFER_DECLINED);
        }

        return true;
    }

    private function expirePending(object $offer): void
    {
        $this->closePendingAsDeclined($offer, 'timeout');
    }

    private function discardUnusedCoupon(int $couponId): void
    {
        if ($couponId <= 0 || !function_exists('wc_get_coupon_id_by_code')) {
            return;
        }

        $coupon = new \WC_Coupon($couponId);
        if ($coupon->get_id() > 0 && $coupon->get_usage_count() <= 0) {
            $coupon->delete(true);
        }
    }

    private function expireAccepted(object $offer): void
    {
        $this->discardUnusedCoupon((int) ($offer->coupon_id ?? 0));

        $expired = $this->offers->updateIfStatus((int) $offer->id, CustomerOfferStatus::ACCEPTED, [
            'status' => CustomerOfferStatus::EXPIRED,
            'responded_at' => current_time('mysql'),
        ]);
        if (!$expired) {
            return;
        }
        $this->events->log('customer_offer_expired', [
            'offer_id' => (int) $offer->id,
            'reason' => 'coupon_expired',
        ], (int) $offer->listing_id, (int) $offer->merchant_id, 'merchant_visible');
        $this->notifyCustomer($offer, NotificationType::CUSTOMER_OFFER_EXPIRED);
    }

    private function forwardToNextSeller(object $offer): bool
    {
        $next = $this->nextListingForOffer($offer);
        if (!$next) {
            return false;
        }

        $originId = (int) ($offer->origin_offer_id ?? 0);
        if ($originId <= 0) {
            $originId = (int) $offer->id;
        }

        $expiresAt = CampaignDatetime::plusHours(CustomerOfferGuardrails::autoDeclineHours());
        $offerId = $this->offers->create([
            'customer_id' => (int) $offer->customer_id,
            'listing_id' => (int) $next->variationId,
            'parent_product_id' => (int) $next->parentProductId,
            'size_term_id' => (int) $next->sizeTermId,
            'merchant_id' => (int) $next->merchantId,
            'bid_amount' => (float) $offer->bid_amount,
            'asking_at_offer' => (float) $next->asking,
            'status' => CustomerOfferStatus::PENDING,
            'expires_at' => $expiresAt,
            'coupon_id' => null,
            'coupon_code' => '',
            'origin_offer_id' => $originId,
        ]);

        $this->events->log('customer_offer_forwarded', [
            'offer_id' => $offerId,
            'from_offer_id' => (int) $offer->id,
            'from_listing_id' => (int) $offer->listing_id,
            'customer_id' => (int) $offer->customer_id,
            'bid_amount' => (float) $offer->bid_amount,
        ], (int) $next->variationId, (int) $next->merchantId, 'merchant_visible');

        $this->notifyMerchant($next, $offerId, (float) $offer->bid_amount);

        return true;
    }

    private function nextListingForOffer(object $offer): ?Listing
    {
        $candidates = ListingConditionRank::sortForSale(
            $this->listings->findCompetingForSize(
                (int) $offer->parent_product_id,
                (int) $offer->size_term_id
            )
        );
        $originId = (int) ($offer->origin_offer_id ?? 0);
        if ($originId <= 0) {
            $originId = (int) $offer->id;
        }
        $seenMerchants = $this->offers->merchantIdsInChain($originId);
        $seenMerchants[] = (int) $offer->merchant_id;
        $seenMerchants[] = (int) $offer->customer_id;
        $seenMerchants = array_values(array_unique(array_filter($seenMerchants)));

        $passedCurrent = false;
        foreach ($candidates as $listing) {
            if ((int) $listing->variationId === (int) $offer->listing_id) {
                $passedCurrent = true;
                continue;
            }
            if (!$passedCurrent) {
                continue;
            }
            if (!in_array($listing->listingStatus, [ListingStatus::PUBLISH, ListingStatus::QUEUED], true)) {
                continue;
            }
            if ($listing->campaignStatus !== 'none') {
                continue;
            }
            if (in_array((int) $listing->merchantId, $seenMerchants, true)) {
                continue;
            }

            return $listing;
        }

        return null;
    }

    /**
     * @return array{coupon_id:int, coupon_code:string}|\WP_Error
     */
    private function issueCoupon(object $offer, Listing $listing): array|\WP_Error
    {
        if (!function_exists('wc_get_coupon_id_by_code')) {
            return new \WP_Error(
                'sutore_customer_offer_coupon',
                __('WooCommerce coupons are not available.', 'sutore-marketplace')
            );
        }

        $user = get_userdata((int) $offer->customer_id);
        $email = $user ? strtolower((string) $user->user_email) : '';
        if ($email === '') {
            return new \WP_Error(
                'sutore_customer_offer_email',
                __('This customer has no email address for a personal coupon.', 'sutore-marketplace')
            );
        }

        $amount = $this->couponAmountForBid((float) $listing->asking, (float) $offer->bid_amount);
        if ($amount <= 0) {
            $amount = $this->couponAmountForBid((float) $offer->asking_at_offer, (float) $offer->bid_amount);
        }
        if ($amount <= 0) {
            return new \WP_Error(
                'sutore_customer_offer_bid',
                __('This offer would not change the checkout price.', 'sutore-marketplace')
            );
        }

        $code = $this->uniqueCouponCode((int) $offer->id);
        $coupon = new \WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('fixed_product');
        $coupon->set_amount((string) $amount);
        $coupon->set_product_ids([(int) $listing->variationId]);
        $coupon->set_email_restrictions([$email]);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_individual_use(true);
        $coupon->set_date_expires(time() + (CustomerOfferGuardrails::ttlHours() * HOUR_IN_SECONDS));
        $coupon->update_meta_data(CouponMeta::CUSTOMER_OFFER_ID, (int) $offer->id);
        $coupon->update_meta_data(CouponMeta::CUSTOMER_OFFER_LISTING, (int) $listing->variationId);
        $coupon->save();

        return [
            'coupon_id' => (int) $coupon->get_id(),
            'coupon_code' => $code,
        ];
    }

    private function uniqueCouponCode(int $offerId): string
    {
        $base = 'SUTOFFER-' . $offerId;
        $code = $base;
        $n = 0;
        while (wc_get_coupon_id_by_code($code)) {
            $n++;
            $code = $base . '-' . $n;
        }

        return strtoupper($code);
    }

    private function notifyMerchant(Listing $listing, int $offerId, float $bid): void
    {
        $product = get_the_title($listing->parentProductId) ?: __('Product', 'sutore-marketplace');
        $this->notifications->dispatch($listing->merchantId, NotificationType::CUSTOMER_OFFER, [
            'product' => $product,
            'variation_id' => (int) $listing->variationId,
            'offer_id' => $offerId,
            'bid_amount' => $bid,
            'asking' => (float) $listing->asking,
        ], 0);
    }

    private function notifyCustomer(object $offer, string $type, array $extra = []): void
    {
        $customerId = (int) $offer->customer_id;
        if ($customerId <= 0) {
            return;
        }

        $listing = $this->listings->find((int) $offer->listing_id);
        $product = ($listing && $listing->parentProductId > 0)
            ? (get_the_title($listing->parentProductId) ?: __('Product', 'sutore-marketplace'))
            : __('Product', 'sutore-marketplace');

        $this->notifications->dispatch($customerId, $type, array_merge([
            'product' => $product,
            'variation_id' => (int) $offer->listing_id,
            'offer_id' => (int) $offer->id,
            'bid_amount' => (float) $offer->bid_amount,
            'coupon_code' => (string) ($offer->coupon_code ?? ''),
        ], $extra), 0);
    }

    private function mailCustomerAccepted(object $offer, Listing $listing, string $couponCode, string $expiresAt): void
    {
        $user = get_userdata((int) $offer->customer_id);
        if (!$user || $user->user_email === '') {
            return;
        }

        $product = get_the_title($listing->parentProductId) ?: __('Product', 'sutore-marketplace');
        $subject = sprintf(
            /* translators: %s: product title */
            __('Your offer on %s was accepted', 'sutore-marketplace'),
            $product
        );
        $expiresLabel = CampaignDatetime::formatLabel($expiresAt);
        $body = sprintf(
            /* translators: 1: product title, 2: coupon code, 3: expiry datetime */
            __('The seller accepted your offer on %1$s. Use coupon %2$s at checkout before %3$s. This coupon is only for you and this product.', 'sutore-marketplace'),
            $product,
            $couponCode,
            $expiresLabel
        );

        wp_mail((string) $user->user_email, $subject, $body);
    }
}
