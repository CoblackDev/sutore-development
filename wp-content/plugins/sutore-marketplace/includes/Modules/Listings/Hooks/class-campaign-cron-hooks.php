<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Hooks;

use SutoreMarketplace\Modules\Listings\Services\CampaignService;

final class CampaignCronHooks
{
    public const HOOK = 'sutore_marketplace_campaign_expiry';

    public const INTERVAL = 'sutore_marketplace_every_five_minutes';

    public function register(): void
    {
        add_filter('cron_schedules', [self::class, 'registerInterval']);
        add_action(self::HOOK, [$this, 'run']);
        add_action('woocommerce_scheduled_sales', [$this, 'run'], 20);
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function registerInterval(array $schedules): array
    {
        $schedules[self::INTERVAL] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every five minutes (Sutore campaigns)', 'sutore-marketplace'),
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
        (new CampaignService())->runExpiryPass(100);
    }
}
