<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Hooks;

use SutoreMarketplace\Modules\Listings\Services\CustomerOfferService;

final class CustomerOfferCronHooks
{
    public const HOOK = 'sutore_marketplace_customer_offer_tick';

    public function register(): void
    {
        add_filter('cron_schedules', [CampaignCronHooks::class, 'registerInterval']);
        add_action(self::HOOK, [$this, 'run']);
    }

    public static function schedule(): void
    {
        $next = wp_next_scheduled(self::HOOK);
        if ($next) {
            $schedule = wp_get_schedule(self::HOOK);
            if ($schedule === CampaignCronHooks::INTERVAL) {
                return;
            }
            wp_unschedule_event($next, self::HOOK);
        }

        wp_schedule_event(time() + MINUTE_IN_SECONDS, CampaignCronHooks::INTERVAL, self::HOOK);
    }

    public static function unschedule(): void
    {
        while ($timestamp = wp_next_scheduled(self::HOOK)) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public function run(): void
    {
        (new CustomerOfferService())->runExpiryPass(100);
    }
}
