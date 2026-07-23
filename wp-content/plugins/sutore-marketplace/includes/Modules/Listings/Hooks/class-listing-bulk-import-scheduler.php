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
        self::dispatchQueueOnShutdown();

        return true;
    }

    public function runBatch(string $jobId): void
    {
        if ($jobId === '') {
            return;
        }

        (new ListingBulkImportService())->processJobBatch($jobId);
    }

    /**
     * Run Action Scheduler after the HTTP response when async loopback / WP-Cron did not pick up the job.
     */
    private static function dispatchQueueOnShutdown(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }

        $registered = true;

        add_action('shutdown', static function (): void {
            if (!class_exists(\ActionScheduler_QueueRunner::class)) {
                return;
            }

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            \ActionScheduler_QueueRunner::instance()->run('Sutore Marketplace Bulk Import');
        }, 999);
    }
}
