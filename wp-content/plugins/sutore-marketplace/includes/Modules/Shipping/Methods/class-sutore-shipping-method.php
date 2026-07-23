<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Shipping\Methods;

use SutoreMarketplace\Modules\Shipping\Domain\ShipmentMeta;
use SutoreMarketplace\Modules\Shipping\Services\EtaDisplay;
use SutoreMarketplace\Modules\Shipping\Services\RateResolver;
use WC_Shipping_Method;

if (!defined('ABSPATH')) {
    exit;
}

final class SutoreShippingMethod extends WC_Shipping_Method
{
    public function __construct(int $instanceId = 0)
    {
        $this->id = 'sutore_marketplace';
        $this->instance_id = absint($instanceId);
        $this->method_title = __('Sutore Shipping', 'sutore-marketplace');
        $this->method_description = __('Marketplace shipping fees (WooCommerce native shipping).', 'sutore-marketplace');
        $this->supports = [
            'shipping-zones',
        ];

        $this->init();
    }

    public function init(): void
    {
        $this->title = $this->get_option('title', __('Shipping', 'sutore-marketplace'));
        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }

    /**
     * @param array<string, mixed> $package
     */
    public function calculate_shipping($package = []): void
    {
        $package = is_array($package) ? $package : [];

        $country = strtoupper(trim((string) ($package['destination']['country'] ?? '')));
        $state = strtoupper(trim((string) ($package['destination']['state'] ?? '')));
        if ($country === '') {
            $country = RateResolver::destinationCountry($package);
        }
        if ($state === '') {
            $state = RateResolver::destinationState($package);
        }

        foreach (RateResolver::checkoutRates(null, $country, $state) as $rate) {
            if (!$this->rateAllowedForCountry($rate['id'], $country)) {
                continue;
            }

            $label = $rate['method_title'] . EtaDisplay::formatDeliveryDate((int) $rate['eta_days']);

            $this->add_rate([
                'id' => $this->get_rate_id() . ':' . $rate['id'],
                'label' => $label,
                'cost' => $rate['cost'],
                'meta_data' => [
                    ShipmentMeta::TYPE => $rate['id'],
                    ShipmentMeta::ETA_DAYS => (string) (int) $rate['eta_days'],
                ],
            ]);
        }
    }

    private function rateAllowedForCountry(string $rateId, string $country): bool
    {
        if ($country === 'CY') {
            return $rateId === 'cyprus';
        }
        if ($country !== '' && $country !== 'TR') {
            return $rateId === 'international';
        }

        return !in_array($rateId, ['cyprus', 'international'], true);
    }
}
