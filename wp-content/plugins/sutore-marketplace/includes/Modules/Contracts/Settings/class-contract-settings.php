<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Contracts\Settings;

use SutoreMarketplace\Shared\Settings\Settings;

final class ContractSettings
{
    public const ORDER_META_KEY = '_sutore_marketplace_contracts_snapshot';

    public const PLATFORM_SELLER_NAME = 'sutore Elektronik Hizmetler ve Ticaret Anonim Şirketi';

    public const PLATFORM_SELLER_CITY = 'İstanbul';

    public const CHECKOUT_FIELD = 'sutore_marketplace_contracts_accepted';

    public const BLOCK_FIELD_ID = 'sutore-marketplace/contracts-accepted';

    public const BLOCK_FIELD_META_KEY = '_wc_other/sutore-marketplace/contracts-accepted';

    public static function enabled(): bool
    {
        $value = Settings::get('contracts_enabled', true);

        return $value === true || $value === 1 || $value === '1' || $value === 'yes';
    }

    public static function checkboxTitle(): string
    {
        $title = trim((string) Settings::get('contracts_checkbox_title', ''));
        if ($title === '') {
            return __('Contracts', 'sutore-marketplace');
        }

        return $title;
    }

    public static function isAccepted(): bool
    {
        return !empty($_POST[self::CHECKOUT_FIELD]);
    }

    public static function isOrderAccepted(\WC_Order $order): bool
    {
        $blockValue = $order->get_meta(self::BLOCK_FIELD_META_KEY, true);
        if ($blockValue !== '' && $blockValue !== null) {
            return (bool) $blockValue;
        }

        return (bool) $order->get_meta(self::CHECKOUT_FIELD, true);
    }

    public static function templateVersion(): int
    {
        return max(1, (int) Settings::get('contracts_template_version', 1));
    }
}
