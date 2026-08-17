<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Hooks;

use SutoreMarketplace\Modules\Invoices\Services\InvoiceIssuer;

final class InvoiceCronHooks
{
    public const HOOK = 'sutore_marketplace_invoice_queue';

    public const HOOK_ONE = 'sutore_marketplace_process_invoice';

    public const INTERVAL = 'sutore_marketplace_every_minute';

    public const GROUP = 'sutore-marketplace-invoices';

    public function register(): void
    {
        add_filter('cron_schedules', [self::class, 'registerInterval']);
        add_action(self::HOOK, [$this, 'run']);
        add_action(self::HOOK_ONE, [$this, 'runOne']);
        add_action('init', [self::class, 'schedule']);
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function registerInterval(array $schedules): array
    {
        $schedules[self::INTERVAL] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => __('Every minute (Sutore invoices)', 'sutore-marketplace'),
        ];

        return $schedules;
    }

    public static function schedule(): void
    {
        $next = wp_next_scheduled(self::HOOK);
        if ($next) {
            $schedule = wp_get_schedule(self::HOOK);
            if ($schedule === self::INTERVAL) {
                return;
            }
            wp_unschedule_event($next, self::HOOK);
        }

        wp_schedule_event(time() + MINUTE_IN_SECONDS, self::INTERVAL, self::HOOK);
    }

    public static function unschedule(): void
    {
        while ($timestamp = wp_next_scheduled(self::HOOK)) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public function run(): void
    {
        (new InvoiceIssuer())->processDue(8);
    }

    public function runOne(int $id = 0): void
    {
        (new InvoiceIssuer())->processOne($id);
    }
}
