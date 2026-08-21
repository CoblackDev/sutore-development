<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Hooks;

use SutoreMarketplace\Modules\Listings\Services\ListingBulkImportService;

final class ListingBulkImportScheduler
{
    public const HOOK_BATCH = 'sutore_marketplace_bulk_import_batch';

    public const GROUP = 'sutore-marketplace-bulk';

    public function register(): void
    {
        add_action(self::HOOK_BATCH, [$this, 'runBatch'], 10, 1);
    }

    public static function isAvailable(): bool
    {
        return function_exists('as_enqueue_async_action');
    }

    public static function schedule(string $jobId): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        as_enqueue_async_action(self::HOOK_BATCH, [$jobId], self::GROUP);

        return true;
    }

    public function runBatch(string $jobId): void
    {
        if ($jobId === '') {
            return;
        }

        (new ListingBulkImportService())->processJobBatch($jobId);
    }
}
