<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Listings\Domain\ListingExpireDisplay;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;

/**
 * Admin order-line seller/fulfillment info resolved from the seller variation (not stored on item meta).
 */
final class OrderItemSellerDisplay
{
    /**
     * @return list<array{label: string, value: string}>
     */
    public static function adminRowsForItem(\WC_Order_Item $item): array
    {
        $variationId = self::resolveVariationId($item);
        if ($variationId <= 0) {
            return [];
        }

        $listing = (new ListingRepository())->findByVariationId($variationId);
        $merchantId = $listing?->merchantId ?: (int) get_post_field('post_author', $variationId);
        $rows = [];

        if ($merchantId > 0) {
            $user = get_userdata($merchantId);
            if ($user && $user->user_login !== '') {
                $rows[] = [
                    'label' => __('Seller', 'sutore-marketplace'),
                    'value' => (string) $user->user_login,
                ];
            }
        }

        if (!$listing || !$listing->variationId) {
            return $rows;
        }

        $repo = new FulfillmentRepository();
        $fulfillment = $repo->findActiveByVariationId((int) $listing->variationId);
        if (!$fulfillment) {
            $fulfillment = $repo->findByVariationId((int) $listing->variationId);
        }
        if (!$fulfillment) {
            return $rows;
        }

        return array_merge($rows, self::fulfillmentRows($fulfillment));
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function fulfillmentRows(object $row): array
    {
        $status = (string) ($row->fulfillment_status ?? '');
        $confirmAt = (string) ($row->confirm_deadline_at ?? '');
        $cargoAt = (string) ($row->cargo_deadline_at ?? '');
        $merchantTrack = trim((string) ($row->merchant_shipment_code ?? ''));
        $sutoreTrack = trim((string) ($row->sutore_shipment_code ?? ''));
        $rows = [];

        if ($status !== '') {
            $rows[] = [
                'label' => __('Fulfillment status', 'sutore-marketplace'),
                'value' => ListingStatus::label($status),
            ];
        }

        if ($merchantTrack !== '') {
            $rows[] = [
                'label' => __('Tracking number', 'sutore-marketplace'),
                'value' => $merchantTrack,
            ];
        }

        if ($sutoreTrack !== '') {
            $rows[] = [
                'label' => __('Sutore shipping', 'sutore-marketplace'),
                'value' => $sutoreTrack,
            ];
        }

        if ($status === ListingStatus::SOLD && $confirmAt !== '') {
            $rows[] = [
                'label' => __('Confirmation deadline', 'sutore-marketplace'),
                'value' => self::formatDeadlineValue($confirmAt),
            ];
            $remaining = ListingExpireDisplay::remainingFromDatetime($confirmAt);
            if ($remaining !== null) {
                $rows[] = [
                    'label' => __('Confirmation time remaining', 'sutore-marketplace'),
                    'value' => $remaining,
                ];
            }
        }

        $sellerConfirmedAt = (string) ($row->seller_confirmed_at ?? '');
        if ($sellerConfirmedAt !== '') {
            $rows[] = [
                'label' => __('Seller confirmed at', 'sutore-marketplace'),
                'value' => self::formatDeadlineValue($sellerConfirmedAt),
            ];
        }

        if ($status === ListingStatus::CONFIRMED && $cargoAt !== '') {
            $rows[] = [
                'label' => __('Shipping deadline', 'sutore-marketplace'),
                'value' => self::formatDeadlineValue($cargoAt),
            ];
            $remaining = ListingExpireDisplay::remainingFromDatetime($cargoAt);
            if ($remaining !== null) {
                $rows[] = [
                    'label' => __('Shipping time remaining', 'sutore-marketplace'),
                    'value' => $remaining,
                ];
            }
        }

        return $rows;
    }

    private static function resolveVariationId(\WC_Order_Item $item): int
    {
        if ($item instanceof \WC_Order_Item_Product) {
            $vid = (int) $item->get_variation_id();
            if ($vid > 0) {
                return $vid;
            }

            return (int) $item->get_product_id();
        }

        return 0;
    }

    private static function formatDeadlineValue(string $mysqlDatetime): string
    {
        $ts = strtotime($mysqlDatetime);

        return $ts
            ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts)
            : $mysqlDatetime;
    }
}
