<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Hooks;

use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Shared\Database\Schema;

/**
 * Purges listing/merchant event rows older than event_retention_days.
 */
final class EventRetentionHooks
{
    public const HOOK = 'sutore_marketplace_purge_old_events';

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public function run(): void
    {
        $days = max(1, (int) OrderSettings::get('event_retention_days', 365));
        $cutoff = wp_date('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        global $wpdb;

        foreach (['listing_events', 'merchant_events'] as $suffix) {
            $table = Schema::table($suffix);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM `{$table}` WHERE created_at < %s LIMIT 5000",
                    $cutoff
                )
            );
        }
    }
}
