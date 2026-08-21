<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

use SutoreMarketplace\Modules\Invoices\Hooks\InvoiceCronHooks;
use SutoreMarketplace\Modules\Listings\Hooks\CampaignCronHooks;
use SutoreMarketplace\Modules\Listings\Hooks\CustomerOfferCronHooks;
use SutoreMarketplace\Modules\Listings\Hooks\ListingBulkImportScheduler;
use SutoreMarketplace\Modules\Listings\Hooks\OutletCronHooks;
use SutoreMarketplace\Modules\Merchants\Hooks\BehaviorCronHooks;
use SutoreMarketplace\Modules\Orders\Hooks\CronHooks as FulfillmentCronHooks;
use SutoreMarketplace\Modules\Sourcing\Hooks\SourcingDigestCron;
use SutoreMarketplace\Shared\Effects\OutboundEffectService;

/**
 * Central WP-Cron + Action Scheduler lifecycle for activation / deactivation.
 */
final class CronRegistry
{
    public const EFFECTS_RETRY_HOOK = 'sutore_marketplace_effects_retry';

    /**
     * @return list<string>
     */
    public static function wpCronHooks(): array
    {
        return [
            Cron::HOOK,
            FulfillmentCronHooks::HOOK,
            CampaignCronHooks::HOOK,
            OutletCronHooks::HOOK,
            CustomerOfferCronHooks::HOOK,
            InvoiceCronHooks::HOOK,
            SourcingDigestCron::HOOK,
            BehaviorCronHooks::HOOK_DAILY,
            EventRetentionHooks::HOOK,
            self::EFFECTS_RETRY_HOOK,
        ];
    }

    public static function scheduleAll(): void
    {
        Cron::schedule();
        FulfillmentCronHooks::schedule();
        CampaignCronHooks::schedule();
        OutletCronHooks::schedule();
        CustomerOfferCronHooks::schedule();
        InvoiceCronHooks::schedule();
        SourcingDigestCron::schedule();
        BehaviorCronHooks::schedule();
        EventRetentionHooks::schedule();
        OutboundEffectService::scheduleRetry();
    }

    public static function unscheduleAll(): void
    {
        Cron::unschedule();
        FulfillmentCronHooks::unschedule();
        CampaignCronHooks::unschedule();
        OutletCronHooks::unschedule();
        CustomerOfferCronHooks::unschedule();
        InvoiceCronHooks::unschedule();
        SourcingDigestCron::unschedule();
        BehaviorCronHooks::unschedule();
        EventRetentionHooks::unschedule();
        OutboundEffectService::unscheduleRetry();
        self::cancelActionScheduler();
    }

    /**
     * Clear every scheduled occurrence of a WP-Cron hook.
     */
    public static function clearHook(string $hook): void
    {
        while ($timestamp = wp_next_scheduled($hook)) {
            wp_unschedule_event($timestamp, $hook);
        }
    }

    public static function cancelActionScheduler(): void
    {
        $groups = [
            OutboundEffectService::GROUP,
            InvoiceCronHooks::GROUP,
            ListingBulkImportScheduler::GROUP,
        ];

        if (function_exists('as_unschedule_all_actions')) {
            foreach ($groups as $group) {
                as_unschedule_all_actions('', [], $group);
            }
        }

        $hooks = [
            OutboundEffectService::HOOK,
            InvoiceCronHooks::HOOK_ONE,
            ListingBulkImportScheduler::HOOK_BATCH,
        ];

        foreach ($hooks as $hook) {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook);
            }
        }
    }
}
