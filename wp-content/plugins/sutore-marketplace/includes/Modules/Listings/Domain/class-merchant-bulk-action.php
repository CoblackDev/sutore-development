<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/**
 * Merchant My Listings bulk workflows.
 * UI only offers an action when every selected row allows it.
 */
final class MerchantBulkAction
{
    /**
     * @return list<string>
     */
    public static function actions(): array
    {
        return [
            'put_on_sale',
            'remove_from_sale',
            'delete',
            'confirm_sale',
        ];
    }

    public static function isValid(string $action): bool
    {
        return in_array($action, self::actions(), true);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'put_on_sale' => __('Put on sale', 'sutore-marketplace'),
            'remove_from_sale' => __('Remove from sale', 'sutore-marketplace'),
            'delete' => __('Delete', 'sutore-marketplace'),
            'confirm_sale' => __('Confirm Sale', 'sutore-marketplace'),
        ];
    }

    public static function label(string $action): string
    {
        $labels = self::labels();

        return $labels[$action] ?? $action;
    }
}
