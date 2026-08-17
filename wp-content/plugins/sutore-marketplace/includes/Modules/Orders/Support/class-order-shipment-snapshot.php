<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Support;

use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Modules\Shipping\Services\EtaDisplay;
use SutoreMarketplace\Modules\Shipping\Settings\ShippingSettings;

/**
 * Denormalize customer checkout shipment onto the listing sale row.
 */
final class OrderShipmentSnapshot
{
    /**
     * Columns to merge into listings when attaching an order.
     *
     * @return array{order_shipment_type: ?string, order_shipment_deadline_at: ?string}
     */
    public static function columnsForOrder(int $orderId): array
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return [
                'order_shipment_type' => null,
                'order_shipment_deadline_at' => null,
            ];
        }

        $type = trim((string) $order->get_meta(ShipmentMeta::TYPE));
        $ts = (int) $order->get_meta(ShipmentMeta::DEADLINE_TIMESTAMP);

        if ($ts <= 0 && $type !== '') {
            $etaDays = (int) $order->get_meta(ShipmentMeta::ETA_DAYS);
            if ($etaDays <= 0) {
                $etaDays = ShippingSettings::etaDays($type);
            }
            $created = $order->get_date_created();
            if ($created) {
                $base = $created->getTimestamp();
                $ts = $base + ($etaDays * DAY_IN_SECONDS);
            } else {
                $ts = EtaDisplay::deadlineTimestamp($etaDays);
            }
        }

        $deadlineAt = null;
        if ($ts > 0) {
            $deadlineAt = wp_date('Y-m-d H:i:s', $ts, wp_timezone());
        }

        return [
            'order_shipment_type' => $type !== '' ? $type : null,
            'order_shipment_deadline_at' => $deadlineAt,
        ];
    }

    /** @return array{order_shipment_type: null, order_shipment_deadline_at: null} */
    public static function clearedColumns(): array
    {
        return [
            'order_shipment_type' => null,
            'order_shipment_deadline_at' => null,
        ];
    }
}
