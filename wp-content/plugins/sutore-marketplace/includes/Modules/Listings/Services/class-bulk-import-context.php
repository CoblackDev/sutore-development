<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;

/**
 * Suppresses per-listing queue/winner notifications for the importing merchant during bulk jobs.
 */
final class BulkImportContext
{
    private const ACTIVE_PREFIX = 'sutore_mp_bulk_active_';

    private static ?string $importId = null;

    private static ?int $merchantId = null;

    public static function activate(int $merchantId, string $jobId): void
    {
        self::$importId = $jobId;
        self::$merchantId = $merchantId;
        set_transient(self::ACTIVE_PREFIX . $merchantId, $jobId, DAY_IN_SECONDS);
    }

    public static function deactivate(int $merchantId): void
    {
        delete_transient(self::ACTIVE_PREFIX . $merchantId);
        self::$importId = null;
        self::$merchantId = null;
    }

    public static function isActiveForMerchant(int $merchantId): bool
    {
        if ($merchantId <= 0) {
            return false;
        }

        $active = get_transient(self::ACTIVE_PREFIX . $merchantId);

        return $active !== false && $active !== '';
    }

    public static function shouldSuppressNotification(int $userId, string $type): bool
    {
        if ($userId <= 0 || !self::isActiveForMerchant($userId)) {
            return false;
        }

        return in_array($type, [
            NotificationType::LISTING_WINNER_GAINED,
            NotificationType::LISTING_WINNER_LOST,
        ], true);
    }

    public static function shouldSuppressQueueNotification(int $userId): bool
    {
        return self::isActiveForMerchant($userId);
    }
}
