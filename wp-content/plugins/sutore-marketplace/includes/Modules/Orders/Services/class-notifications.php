<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Modules\Orders\Settings\SmsTemplates;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Shared\Sms\PhoneNormalizer;
use SutoreMarketplace\Shared\Sms\SmsGateway;

final class Notifications
{
    /** @param array<string, string|int|float|null> $vars */
    public static function sendEvent(string $eventKey, string $phone, array $vars = [], bool $async = false): void
    {
        if (!Settings::smsEnabled() || !Settings::smsEventEnabled($eventKey)) {
            return;
        }

        self::sms($phone, SmsTemplates::render($eventKey, $vars), $async);
    }

    public static function sms(string $phone, string $message, bool $async = false): void
    {
        if (!Settings::smsEnabled() || $message === '') {
            return;
        }

        $phone = PhoneNormalizer::toDomestic($phone);
        if ($phone === '') {
            return;
        }

        if ($async) {
            \SutoreMarketplace\Shared\Sms\SmsQueue::enqueue($phone, $message);

            return;
        }

        SmsGateway::send($phone, $message);
    }

    public static function notifyAdmins(string $eventKey, array $vars = [], bool $async = false): void
    {
        foreach (Settings::adminPhones() as $number) {
            self::sendEvent($eventKey, $number, $vars, $async);
        }
    }

    public static function notifyExpress(string $eventKey, array $vars = [], bool $async = false): void
    {
        $phone = Settings::expressPhone();
        if ($phone !== '') {
            self::sendEvent($eventKey, $phone, $vars, $async);
        }
    }

    public static function merchantPhone(int $merchantId): string
    {
        $profile = MerchantMeta::readProfile($merchantId);

        return (string) ($profile[MerchantMeta::ACCOUNT_PHONE] ?? '');
    }

    public static function productTitle(int $listingId, int $variationId, int $parentId, int $sizeTermId = 0): string
    {
        $product = $variationId > 0 ? wc_get_product($variationId) : null;
        $title = '';

        if ($product) {
            $title = (string) $product->get_name();
        }

        if ($title === '' || $title === (string) $variationId) {
            $title = (string) get_the_title($variationId);
        }

        if ($title === '' || $title === (string) $variationId) {
            $title = (string) get_the_title($parentId);
        }

        $sizeLabel = self::sizeLabelForListing($listingId, $variationId, $sizeTermId);
        if ($sizeLabel !== '' && stripos($title, $sizeLabel) === false) {
            $title = trim($title . ' ' . $sizeLabel);
        }

        return trim(str_replace(['&#8211;', '–'], '', $title));
    }

    private static function sizeLabelForListing(int $listingId, int $variationId, int $sizeTermId = 0): string
    {
        if ($sizeTermId <= 0 && $listingId > 0) {
            $listing = (new \SutoreMarketplace\Modules\Listings\Repositories\ListingRepository())->find($listingId);
            if ($listing) {
                $sizeTermId = (int) $listing->sizeTermId;
            }
        }

        if ($sizeTermId <= 0 && $variationId > 0) {
            $product = wc_get_product($variationId);
            if ($product && $product->is_type('variation')) {
                foreach ($product->get_attributes() as $taxonomy => $slug) {
                    if (!is_string($taxonomy) || !is_string($slug) || $slug === '') {
                        continue;
                    }
                    $term = get_term_by('slug', $slug, $taxonomy);
                    if ($term && !is_wp_error($term)) {
                        return (string) $term->name;
                    }
                }
            }

            return '';
        }

        return \SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup::labelForTermId($sizeTermId);
    }

    /** @return array<string, string|int> */
    public static function baseVars(\WC_Order $order, string $productTitle, float $asking = 0): array
    {
        return [
            'order_id' => (string) $order->get_id(),
            'product' => $productTitle,
            'price' => $asking > 0 ? \SutoreMarketplace\Shared\Domain\MarketplacePricing::formatTl($asking) : '',
            'customer_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'shipment_type' => (string) $order->get_meta(ShipmentMeta::TYPE),
            'yurtici_code' => Settings::yurticiCustomerCode(),
            'confirm_hours' => (string) (int) (Settings::confirmDeadlineSeconds() / HOUR_IN_SECONDS),
            'cargo_hours' => (string) (int) (Settings::cargoDeadlineSecondsForShipmentType((string) $order->get_meta(ShipmentMeta::TYPE)) / HOUR_IN_SECONDS),
            'track_code' => '',
        ];
    }
}
