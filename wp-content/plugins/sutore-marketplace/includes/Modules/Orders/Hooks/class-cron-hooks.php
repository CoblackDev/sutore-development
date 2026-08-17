<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Hooks;

use SutoreMarketplace\Modules\Orders\Settings\Settings;

final class CronHooks
{
    public const HOOK = 'sutore_marketplace_fulfillment_deadlines';

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public static function schedule(): void
    {
        self::reschedule();
    }

    public static function reschedule(): void
    {
        self::unschedule();
        $schedule = Settings::deadlineCronSchedule();
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, $schedule, self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        while ($ts = wp_next_scheduled(self::HOOK)) {
            wp_unschedule_event($ts, self::HOOK);
        }
    }

    public function run(): void
    {
        $repo = new \SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository();
        $service = new \SutoreMarketplace\Modules\Orders\Services\FulfillmentService();
        foreach ($repo->deadlineBatch(200) as $row) {
            $service->processDeadline($row);
        }
    }
}
