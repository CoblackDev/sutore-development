<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Shared\Settings\Settings;

/**
 * TC kimlik doğrulama — moda göre NVI, algoritma veya manuel akış.
 */
final class TcIdentityVerifier
{
    public static function verify(string $tc, string $firstName, string $lastName, int $birthYear): bool|\WP_Error
    {
        $mode = Settings::tcVerificationMode();

        if ($mode === 'manual') {
            return new \WP_Error(
                'sutore_tc_manual_mode',
                __('TC verification is completed with admin approval. Save your information; you will be notified after approval.', 'sutore-marketplace')
            );
        }

        if ($mode === 'algorithm') {
            if (wp_get_environment_type() === 'production') {
                return new \WP_Error(
                    'sutore_tc_algorithm_blocked',
                    __('Algorithm TC verification is not allowed in production. Use NVI or manual mode.', 'sutore-marketplace')
                );
            }

            return self::verifyAlgorithmOnly($tc);
        }

        return NviIdentityVerifier::verify($tc, $firstName, $lastName, $birthYear);
    }

    private static function verifyAlgorithmOnly(string $tc): bool|\WP_Error
    {
        if (!TcValidator::isValid($tc)) {
            return new \WP_Error(
                'sutore_tc_invalid',
                __('Invalid TC identity number.', 'sutore-marketplace')
            );
        }

        return true;
    }
}
