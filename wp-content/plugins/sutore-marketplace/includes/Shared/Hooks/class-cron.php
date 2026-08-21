<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;

final class Cron
{
    public const HOOK = 'marketplace_check_expired_listings';

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        CronRegistry::clearHook(self::HOOK);
    }

    public function run(): void
    {
        $repo = new ListingRepository();
        $service = new ListingService();
        foreach ($repo->expiredBatch(100) as $listing) {
            $service->expire($listing);
        }
    }
}
