<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Services;

use SutoreMarketplace\Modules\Contracts\Settings\ContractSettings;

final class OrderSnapshot
{
    /** @return array{version:int,accepted_at:string,pre_information:string,distance_sales:string}|null */
    public static function get(\WC_Order $order): ?array
    {
        $raw = $order->get_meta(ContractSettings::ORDER_META_KEY, true);
        if (!is_array($raw)) {
            return null;
        }

        if (empty($raw['pre_information']) && empty($raw['distance_sales'])) {
            return null;
        }

        return $raw;
    }

    public static function save(\WC_Order $order, array $contracts): void
    {
        $order->update_meta_data(ContractSettings::ORDER_META_KEY, [
            'version' => ContractSettings::templateVersion(),
            'accepted_at' => current_time('mysql'),
            'pre_information' => (string) ($contracts['pre_information'] ?? ''),
            'distance_sales' => (string) ($contracts['distance_sales'] ?? ''),
        ]);
    }

    /** @return array{pre_information:string,distance_sales:string} */
    public static function resolveForEmail(\WC_Order $order): array
    {
        $snapshot = self::get($order);
        if ($snapshot !== null) {
            return [
                'pre_information' => (string) $snapshot['pre_information'],
                'distance_sales' => (string) $snapshot['distance_sales'],
            ];
        }

        return ContractAssembler::buildFromOrder($order);
    }
}
