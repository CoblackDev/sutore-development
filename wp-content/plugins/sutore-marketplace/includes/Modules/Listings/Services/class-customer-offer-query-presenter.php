<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferGuardrails;
use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferStatus;
use SutoreMarketplace\Modules\Listings\Domain\ListingExpireDisplay;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductCodeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Listings\Repositories\CustomerOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class CustomerOfferQueryPresenter
{
    public function __construct(
        private readonly CustomerOfferRepository $offers = new CustomerOfferRepository(),
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly CustomerOfferService $service = new CustomerOfferService(),
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function presentForMerchant(
        int $merchantId,
        ?string $status,
        int $page,
        int $perPage,
        string $orderby = 'created_desc'
    ): array {
        $offset = ($page - 1) * $perPage;
        $rows = $this->offers->findForMerchant($merchantId, $status, $perPage, $offset, $orderby);
        $total = $this->offers->countForMerchant($merchantId, $status);

        return [
            'items' => $this->mapRows($rows, false),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function presentForCustomer(int $customerId, ?string $status, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $rows = $this->offers->findForCustomer($customerId, $status, $perPage, $offset);
        $total = $this->offers->countForCustomer($customerId, $status);

        return [
            'items' => $this->mapRows($rows, true),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentContext(int $variationId, int $customerId): array
    {
        $listing = $this->listings->find($variationId);
        $enabled = CustomerOfferGuardrails::enabled();
        $empty = [
            'enabled' => $enabled,
            'can_offer' => false,
            'logged_in' => $customerId > 0,
            'variation_id' => $variationId,
            'asking' => 0.0,
            'customer_price' => 0.0,
            'min_bid' => 0,
            'max_bid' => 0,
            'price_step' => \SutoreMarketplace\Shared\Settings\Settings::listingPriceStep(),
            'ttl_hours' => CustomerOfferGuardrails::ttlHours(),
            'auto_decline_hours' => CustomerOfferGuardrails::autoDeclineHours(),
            'min_percent' => CustomerOfferGuardrails::minPercent(),
            'pending_offer' => null,
            'accepted_offer' => null,
            'reason' => '',
        ];

        if (!$enabled) {
            $empty['reason'] = 'disabled';

            return $empty;
        }
        if (!$listing || !$listing->variationId) {
            $empty['reason'] = 'no_listing';

            return $empty;
        }

        $onSale = $listing->listingStatus === ListingStatus::PUBLISH
            && $listing->campaignStatus === 'none';

        if ($customerId <= 0 && !$onSale) {
            $empty['reason'] = $listing->listingStatus !== ListingStatus::PUBLISH ? 'not_on_sale' : 'campaign';

            return $empty;
        }

        $asking = (float) $listing->asking;
        $empty['asking'] = $asking;
        $empty['customer_price'] = MarketplacePricing::customerPrice($listing);
        $empty['min_bid'] = CustomerOfferGuardrails::minBidForAsking($asking);
        $empty['max_bid'] = CustomerOfferGuardrails::maxBidForAsking($asking);
        $empty['parent_product_id'] = (int) $listing->parentProductId;
        $empty['size_term_id'] = (int) $listing->sizeTermId;
        $empty['size_label'] = ProductSizeLookup::labelForTermId((int) $listing->sizeTermId);

        if ($customerId <= 0) {
            $empty['reason'] = 'login';
            $empty['can_offer'] = false;

            return $empty;
        }

        if ((int) $listing->merchantId === $customerId) {
            $empty['reason'] = 'own_listing';

            return $empty;
        }

        $pending = $this->offers->findPendingForCustomerProductSize(
            $customerId,
            (int) $listing->parentProductId,
            (int) $listing->sizeTermId
        );
        if ($pending) {
            $empty['pending_offer'] = $this->mapRow($pending, $listing, true);

            return $empty;
        }

        $accepted = $this->offers->findAcceptedForListingAndCustomer((int) $listing->variationId, $customerId);
        if ($accepted) {
            $empty['accepted_offer'] = $this->mapRow($accepted, $listing, true);

            return $empty;
        }

        if ($listing->listingStatus !== ListingStatus::PUBLISH) {
            $empty['reason'] = 'not_on_sale';

            return $empty;
        }
        if ($listing->campaignStatus !== 'none') {
            $empty['reason'] = 'campaign';

            return $empty;
        }

        $empty['can_offer'] = true;

        return $empty;
    }

    /**
     * @param object[] $rows
     * @return list<array<string, mixed>>
     */
    private function mapRows(array $rows, bool $forCustomer): array
    {
        $listingIds = [];
        foreach ($rows as $row) {
            $listingIds[] = (int) $row->listing_id;
        }
        $listings = $this->listings->findByIds($listingIds);
        $items = [];
        foreach ($rows as $row) {
            $listing = $listings[(int) $row->listing_id] ?? null;
            $items[] = $this->mapRow($row, $listing, $forCustomer);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(object $row, ?\SutoreMarketplace\Modules\Listings\Domain\Listing $listing, bool $forCustomer): array
    {
        $parentId = $listing ? (int) $listing->parentProductId : (int) $row->parent_product_id;
        $productTitle = $parentId > 0
            ? (get_the_title($parentId) ?: __('Product', 'sutore-marketplace'))
            : __('Product', 'sutore-marketplace');
        $asking = $listing ? (float) $listing->asking : (float) $row->asking_at_offer;
        $bid = (float) $row->bid_amount;
        $couponAmount = $this->service->couponAmountForBid(
            (float) $row->asking_at_offer > 0 ? (float) $row->asking_at_offer : $asking,
            $bid
        );
        $addToCartUrl = '';
        if ($forCustomer && (string) $row->status === CustomerOfferStatus::ACCEPTED && (int) $row->listing_id > 0) {
            $addToCartUrl = add_query_arg(
                'add-to-cart',
                (int) $row->listing_id,
                function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/')
            );
            if ((string) $row->coupon_code !== '') {
                $addToCartUrl = add_query_arg('coupon', (string) $row->coupon_code, $addToCartUrl);
            }
        }

        $permalink = '';
        if ($listing && (int) $listing->variationId > 0) {
            $permalink = (string) (get_permalink((int) $listing->variationId) ?: '');
        }
        if ($permalink === '' && $parentId > 0) {
            $permalink = (string) (get_permalink($parentId) ?: '');
        }

        return [
            'id' => (int) $row->id,
            'listing_id' => (int) $row->listing_id,
            'variation_id' => (int) $row->listing_id,
            'parent_product_id' => $parentId,
            'merchant_id' => (int) $row->merchant_id,
            'customer_id' => (int) $row->customer_id,
            'product_title' => $productTitle,
            'product_code' => $parentId > 0 ? ProductCodeLookup::codeForProduct($parentId) : '',
            'size_label' => ProductSizeLookup::labelForTermId(
                $listing ? (int) $listing->sizeTermId : (int) $row->size_term_id
            ),
            'thumbnail' => $parentId > 0 ? ProductThumbnail::url($parentId) : '',
            'permalink' => $permalink,
            'status' => (string) $row->status,
            'status_label' => CustomerOfferStatus::label((string) $row->status),
            'bid_amount' => $bid,
            'asking_at_offer' => (float) $row->asking_at_offer,
            'asking_now' => $asking,
            'customer_pay' => MarketplacePricing::listingComparePrice($bid),
            'coupon_amount' => $couponAmount,
            'coupon_code' => (string) ($row->coupon_code ?? ''),
            'expires_at' => $row->expires_at ?? null,
            'expires_at_label' => !empty($row->expires_at)
                ? CampaignDatetime::formatLabel((string) $row->expires_at)
                : '',
            'remaining_label' => !empty($row->expires_at)
                ? ListingExpireDisplay::remainingFromDatetime((string) $row->expires_at)
                : null,
            'created_at' => $row->created_at ?? null,
            'responded_at' => $row->responded_at ?? null,
            'add_to_cart_url' => $addToCartUrl,
            'forwarded' => (int) ($row->origin_offer_id ?? 0) > 0,
        ];
    }
}
