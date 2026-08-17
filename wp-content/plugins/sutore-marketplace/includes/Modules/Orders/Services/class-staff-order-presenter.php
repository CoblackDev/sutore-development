<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Invoices\Repositories\InvoiceRepository;
use SutoreMarketplace\Modules\Invoices\Services\InvoicePresenter;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentType;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Settings\Settings;

/**
 * Staff WC order list/detail payloads for My Account Orders.
 */
final class StaffOrderPresenter
{
    public function __construct(
        private readonly StaffOrderService $orders = new StaffOrderService(),
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   status_labels: array<string, string>
     * }
     */
    public function presentQuery(array $args): array
    {
        $result = $this->orders->query($args);
        $orderIds = array_map(static fn (\WC_Order $o): int => (int) $o->get_id(), $result['orders']);
        $sellerCounts = $this->orders->sellerCountsByOrderIds($orderIds);

        $items = [];
        foreach ($result['orders'] as $order) {
            $items[] = $this->presentRow($order, $sellerCounts[(int) $order->get_id()] ?? 0);
        }

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'status_labels' => $this->orders->statusLabels(),
        ];
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function presentDetail(int $orderId): array|\WP_Error
    {
        $order = $this->orders->find($orderId);
        if ($order instanceof \WP_Error) {
            return $order;
        }

        $sellerCount = $this->orders->sellerCountsByOrderIds([$orderId])[$orderId] ?? 0;
        $row = $this->presentRow($order, $sellerCount);
        $listings = $this->orders->listingsForOrder($orderId);

        $listingByItemId = [];
        $listingByVariationId = [];
        $merchantIds = [];
        foreach ($listings as $listing) {
            $itemId = (int) ($listing->order_item_id ?? 0);
            $variationId = (int) ($listing->variation_id ?? $listing->id ?? 0);
            if ($itemId > 0) {
                $listingByItemId[$itemId] = $listing;
            }
            if ($variationId > 0) {
                $listingByVariationId[$variationId] = $listing;
            }
            $merchantId = (int) ($listing->merchant_id ?? 0);
            if ($merchantId > 0) {
                $merchantIds[$merchantId] = $merchantId;
            }
        }

        $merchantNames = $this->merchantNames(array_values($merchantIds));
        $listingRepo = new ListingRepository();

        $lineItems = [];
        $hizmetTotal = 0.0;
        $guvenceTotal = 0.0;
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $itemId = (int) $item->get_id();
            $variationId = (int) $item->get_variation_id();
            $productId = (int) $item->get_product_id();
            $thumbId = $variationId > 0 ? $variationId : $productId;

            $listing = $listingByItemId[$itemId]
                ?? ($variationId > 0 ? ($listingByVariationId[$variationId] ?? null) : null);

            $merchantId = $listing ? (int) ($listing->merchant_id ?? 0) : 0;
            $listingStatus = $listing
                ? (string) ($listing->fulfillment_status ?? $listing->listing_status ?? '')
                : '';
            $asking = $listing ? (float) ($listing->asking ?? 0) : 0.0;
            $sizeTermId = $listing ? (int) ($listing->size_term_id ?? 0) : 0;
            $sizeLabel = '';
            if ($sizeTermId > 0) {
                $sizeLabel = ProductSizeLookup::labelForTerm($productId, $sizeTermId);
                if ($sizeLabel === '') {
                    $sizeLabel = ProductSizeLookup::labelForTermId($sizeTermId);
                }
            }

            $caps = $listingStatus !== '' ? ListingStatus::staffCapabilities($listingStatus) : [];
            $linked = $listing
                && (int) ($listing->order_id ?? 0) > 0
                && (int) ($listing->order_item_id ?? 0) > 0;
            $productExists = false;
            if ($variationId > 0) {
                $wcProduct = wc_get_product($variationId);
                $productExists = $wcProduct instanceof \WC_Product;
            } elseif ($productId > 0) {
                $wcProduct = wc_get_product($productId);
                $productExists = $wcProduct instanceof \WC_Product;
            }
            $canSwap = $linked
                && $productExists
                && !empty($caps['swap'])
                && OrderSettings::swapAllowedFor($listingStatus);
            $canDetach = $linked
                && !empty($caps['detach'])
                && ListingStatus::allowsDetach($listingStatus);
            // Staff manage-orders: always allow removing the WC line when detach is unavailable.
            $canRemove = !$canDetach;

            $qty = max(1, (int) $item->get_quantity());
            $hizmet = 0.0;
            $guvence = 0.0;
            $resolvedVariationId = $variationId > 0
                ? $variationId
                : ($listing ? (int) ($listing->variation_id ?? 0) : 0);
            if ($listing && $asking > 0) {
                $domainListing = $resolvedVariationId > 0
                    ? $listingRepo->find($resolvedVariationId)
                    : null;
                if ($domainListing instanceof Listing) {
                    $fees = MarketplacePricing::feeBreakdownForListing($domainListing);
                    $hizmet = (float) $fees['hizmet'] * $qty;
                    $guvence = (float) $fees['guvence'] * $qty;
                } else {
                    $hizmet = Settings::hizmetBedeli() * $qty;
                    $guvence = MarketplacePricing::guvenceFeeForAsking($asking) * $qty;
                }
                $hizmetTotal += $hizmet;
                $guvenceTotal += $guvence;
            }

            $lineItems[] = [
                'order_item_id' => $itemId,
                'product_id' => $productId,
                'variation_id' => $resolvedVariationId,
                'name' => $item->get_name(),
                'quantity' => $qty,
                'total' => (float) $item->get_total(),
                'total_display' => $this->formatMoney((float) $item->get_total(), $order),
                'unit_total' => $qty > 0
                    ? ((float) $item->get_total() / $qty)
                    : (float) $item->get_total(),
                'thumbnail' => ProductThumbnail::url($thumbId),
                'merchant_id' => $merchantId,
                'merchant_name' => $merchantId > 0
                    ? ($merchantNames[$merchantId] ?? ('#' . $merchantId))
                    : '',
                'listing_status' => $listingStatus,
                'listing_status_label' => $listingStatus !== ''
                    ? ListingStatus::label($listingStatus)
                    : '',
                'asking' => $asking,
                'asking_display' => $asking > 0 ? MarketplacePricing::formatTl($asking) : '',
                'hizmet_fee' => $hizmet,
                'hizmet_fee_display' => $hizmet > 0 ? $this->formatMoney($hizmet, $order) : '',
                'guvence_fee' => $guvence,
                'guvence_fee_display' => $guvence > 0 ? $this->formatMoney($guvence, $order) : '',
                'size_label' => $sizeLabel,
                'can_swap' => $canSwap,
                'can_detach' => $canDetach,
                'can_remove' => $canRemove,
                'is_marketplace' => $listing !== null,
                'product_exists' => $productExists,
                'invoices' => [],
            ];
        }

        $orderIdByVariation = [];
        foreach ($lineItems as $line) {
            $variationId = (int) ($line['variation_id'] ?? 0);
            if ($variationId > 0) {
                $orderIdByVariation[$variationId] = $orderId;
            }
        }
        $invoiceMap = (new InvoiceRepository())->findForVariations($orderIdByVariation);
        foreach ($lineItems as $index => $line) {
            $variationId = (int) ($line['variation_id'] ?? 0);
            $lineItems[$index]['invoices'] = InvoicePresenter::forStaff($invoiceMap[$variationId] ?? []);
        }

        $coupons = [];
        foreach ($order->get_coupons() as $couponItem) {
            if (!$couponItem instanceof \WC_Order_Item_Coupon) {
                continue;
            }
            $discount = (float) $couponItem->get_discount() + (float) $couponItem->get_discount_tax();
            $coupons[] = [
                'code' => (string) $couponItem->get_code(),
                'discount' => (float) $couponItem->get_discount(),
                'discount_tax' => (float) $couponItem->get_discount_tax(),
                'discount_display' => $this->formatMoney((float) $couponItem->get_discount(), $order),
            ];
        }

        $fees = [];
        foreach ($order->get_fees() as $feeItem) {
            if (!$feeItem instanceof \WC_Order_Item_Fee) {
                continue;
            }
            $fees[] = [
                'name' => $feeItem->get_name(),
                'total' => (float) $feeItem->get_total(),
                'total_display' => $this->formatMoney((float) $feeItem->get_total(), $order),
            ];
        }

        $billing = [
            'name' => trim($order->get_formatted_billing_full_name()),
            'company' => (string) $order->get_billing_company(),
            'address_1' => (string) $order->get_billing_address_1(),
            'address_2' => (string) $order->get_billing_address_2(),
            'city' => (string) $order->get_billing_city(),
            'state' => (string) $order->get_billing_state(),
            'postcode' => (string) $order->get_billing_postcode(),
            'country' => (string) $order->get_billing_country(),
            'email' => (string) $order->get_billing_email(),
            'phone' => (string) $order->get_billing_phone(),
            'formatted' => wp_strip_all_tags($order->get_formatted_billing_address() ?: ''),
        ];

        $shipping = [
            'name' => trim($order->get_formatted_shipping_full_name()),
            'company' => (string) $order->get_shipping_company(),
            'address_1' => (string) $order->get_shipping_address_1(),
            'address_2' => (string) $order->get_shipping_address_2(),
            'city' => (string) $order->get_shipping_city(),
            'state' => (string) $order->get_shipping_state(),
            'postcode' => (string) $order->get_shipping_postcode(),
            'country' => (string) $order->get_shipping_country(),
            'phone' => (string) $order->get_shipping_phone(),
            'formatted' => wp_strip_all_tags($order->get_formatted_shipping_address() ?: ''),
            'method_title' => $order->get_shipping_method(),
        ];

        return array_merge($row, [
            'billing' => $billing,
            'shipping' => $shipping,
            'payment_method' => (string) $order->get_payment_method(),
            'payment_method_title' => (string) $order->get_payment_method_title(),
            'customer_note' => (string) $order->get_customer_note(),
            'item_count' => max(0, (int) $order->get_item_count()),
            'line_items' => $lineItems,
            'coupons' => $coupons,
            'fees' => $fees,
            'hizmet_total' => $hizmetTotal,
            'hizmet_total_display' => $this->formatMoney($hizmetTotal, $order),
            'guvence_total' => $guvenceTotal,
            'guvence_total_display' => $this->formatMoney($guvenceTotal, $order),
            'price_difference' => (float) $order->get_meta('_sutore_mp_price_difference', true),
            'price_difference_display' => $this->formatMoney(
                (float) $order->get_meta('_sutore_mp_price_difference', true),
                $order
            ),
            'status_labels' => $this->orders->statusLabels(),
            'subtotal' => (float) $order->get_subtotal(),
            'subtotal_display' => $this->formatMoney((float) $order->get_subtotal(), $order),
            'shipping_total' => (float) $order->get_shipping_total(),
            'shipping_total_display' => $this->formatMoney((float) $order->get_shipping_total(), $order),
            'discount_total' => (float) $order->get_discount_total(),
            'discount_total_display' => $this->formatMoney((float) $order->get_discount_total(), $order),
            'tax_total' => (float) $order->get_total_tax(),
            'tax_total_display' => $this->formatMoney((float) $order->get_total_tax(), $order),
            'can_edit_items' => !$order->has_status(['cancelled', 'refunded', 'failed', 'trash']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRow(\WC_Order $order, int $sellerCount = 0): array
    {
        $id = (int) $order->get_id();
        $status = $this->orders->normalizeStatus((string) $order->get_status());
        $shipmentType = sanitize_key((string) $order->get_meta(ShipmentMeta::TYPE, true));
        $created = $order->get_date_created();
        $createdMysql = $created ? $created->date('Y-m-d H:i:s') : '';
        $createdTs = $created ? $created->getTimestamp() : 0;
        $deliveryDeadline = $this->deliveryDeadline($order);

        return [
            'id' => $id,
            'order_id' => $id,
            'number' => $order->get_order_number(),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'date_created' => $createdMysql,
            'date_created_display' => $createdTs > 0
                ? wp_date(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    $createdTs
                )
                : '',
            'customer_name' => trim($order->get_formatted_billing_full_name()),
            'seller_count' => max(0, $sellerCount),
            'item_count' => max(0, (int) $order->get_item_count()),
            'shipment_type' => $shipmentType,
            'shipment_type_label' => $shipmentType !== '' && ShipmentType::isValid($shipmentType)
                ? ShipmentType::label($shipmentType)
                : ($shipmentType !== '' ? $shipmentType : ''),
            'shipping_method_title' => $order->get_shipping_method(),
            'delivery_deadline_at' => $deliveryDeadline['at'],
            'delivery_deadline_display' => $deliveryDeadline['display'],
            'currency' => $order->get_currency(),
            'total' => (float) $order->get_total(),
            'total_display' => $this->formatMoney((float) $order->get_total(), $order),
            'edit_url' => $order->get_edit_order_url(),
        ];
    }

    /**
     * @return array{at: string, display: string}
     */
    private function deliveryDeadline(\WC_Order $order): array
    {
        $ts = (int) $order->get_meta(ShipmentMeta::DEADLINE_TIMESTAMP, true);
        if ($ts <= 0) {
            return ['at' => '', 'display' => ''];
        }

        return [
            'at' => wp_date('Y-m-d H:i:s', $ts),
            'display' => wp_date((string) get_option('date_format'), $ts),
        ];
    }

    private function statusLabel(string $status): string
    {
        $labels = $this->orders->statusLabels();

        return $labels[$status] ?? $status;
    }

    private function formatMoney(float $amount, \WC_Order $order): string
    {
        $html = wc_price($amount, ['currency' => $order->get_currency()]);

        return html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param list<int> $merchantIds
     * @return array<int, string>
     */
    private function merchantNames(array $merchantIds): array
    {
        $merchantIds = array_values(array_unique(array_filter(array_map('intval', $merchantIds))));
        if ($merchantIds === []) {
            return [];
        }

        $names = [];
        $users = get_users([
            'include' => $merchantIds,
            'fields' => ['ID', 'display_name'],
        ]);
        foreach ($users as $user) {
            $names[(int) $user->ID] = (string) $user->display_name;
        }

        return $names;
    }
}
