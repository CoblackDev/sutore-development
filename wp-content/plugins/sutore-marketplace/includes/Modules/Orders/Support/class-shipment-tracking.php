<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Support;

final class ShipmentTracking
{
    public static function customerTrackUrl(string $shipmentType, string $trackingCode): string
    {
        $trackingCode = trim($trackingCode);
        if ($trackingCode === '') {
            return '';
        }

        if ($shipmentType === 'international') {
            return 'https://www.dhl.com/global-en/home/tracking/tracking-express.html?submit=1&tracking-id='
                . rawurlencode($trackingCode);
        }

        return 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code='
            . rawurlencode($trackingCode);
    }
}
