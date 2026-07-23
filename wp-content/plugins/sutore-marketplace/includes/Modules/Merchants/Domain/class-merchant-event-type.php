<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Domain;

final class MerchantEventType
{
    public static function label(string $eventType): string
    {
        return match ($eventType) {
            'merchant_profile_created' => __('Merchant profile created', 'sutore-marketplace'),
            'merchant_iban_changed' => __('IBAN changed', 'sutore-marketplace'),
            'merchant_phone_changed' => __('Phone changed', 'sutore-marketplace'),
            'merchant_email_changed' => __('Email changed', 'sutore-marketplace'),
            'merchant_name_changed' => __('Name changed', 'sutore-marketplace'),
            'merchant_address_changed' => __('Address changed', 'sutore-marketplace'),
            'merchant_tckno_changed' => __('TC identity number changed', 'sutore-marketplace'),
            'merchant_birth_year_changed' => __('Birth year changed', 'sutore-marketplace'),
            'merchant_tc_verified' => __('TC identity verified', 'sutore-marketplace'),
            'merchant_tc_verification_cleared' => __('TC verification cleared', 'sutore-marketplace'),
            'merchant_level_changed' => __('Seller level changed', 'sutore-marketplace'),
            'merchant_restriction_created' => __('Restriction added', 'sutore-marketplace'),
            'merchant_restriction_deactivated' => __('Restriction deactivated', 'sutore-marketplace'),
            'merchant_profile_updated_by_staff' => __('Profile updated by staff', 'sutore-marketplace'),
            'merchant_role_granted' => __('Merchant role granted', 'sutore-marketplace'),
            'merchant_commission_override_set' => __('Commission override set', 'sutore-marketplace'),
            'merchant_commission_override_deleted' => __('Commission override deleted', 'sutore-marketplace'),
            default => $eventType,
        };
    }
}
