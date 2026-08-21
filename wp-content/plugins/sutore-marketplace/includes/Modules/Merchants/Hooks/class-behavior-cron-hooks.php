<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Hooks;

use SutoreMarketplace\Modules\Merchants\Services\BehaviorLevelService;
use SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService;
use SutoreMarketplace\Modules\Tasks\Services\OpportunityCardService;
use SutoreMarketplace\Shared\Hooks\CronRegistry;

final class BehaviorCronHooks
{
    public const HOOK_DAILY = 'sutore_marketplace_behavior_daily';
    private const MONTHLY_OPTION = 'sutore_marketplace_behavior_monthly_run';

    public function register(): void
    {
        add_action(self::HOOK_DAILY, [$this, 'runDaily']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK_DAILY)) {
            wp_schedule_event(strtotime('tomorrow 3:00'), 'daily', self::HOOK_DAILY);
        }
    }

    public static function unschedule(): void
    {
        CronRegistry::clearHook(self::HOOK_DAILY);
    }

    public function runDaily(): void
    {
        $merchantIds = get_users(['role' => 'merchant', 'fields' => 'ID']);
        $scores = new BehaviorScoreService();
        $levels = new BehaviorLevelService();

        foreach ($merchantIds as $userId) {
            $merchantId = (int) $userId;
            $scores->refreshMerchant($merchantId);
            $levels->evaluateConfirmed($merchantId);
        }

        $monthKey = wp_date('Y-m');
        if (get_option(self::MONTHLY_OPTION, '') !== $monthKey) {
            $this->runMonthly();
            update_option(self::MONTHLY_OPTION, $monthKey, false);
        }
    }

    public function runMonthly(): void
    {
        (new OpportunityCardService())->ensureSystemTemplates();

        $merchantIds = get_users(['role' => 'merchant', 'fields' => 'ID']);
        $scores = new BehaviorScoreService();
        $levels = new BehaviorLevelService();
        $cards = new OpportunityCardService();

        foreach ($merchantIds as $userId) {
            $merchantId = (int) $userId;
            $scores->refreshMerchant($merchantId);
            $levels->evaluatePremium($merchantId);
            $cards->generateForMerchant($merchantId);
        }
    }
}
