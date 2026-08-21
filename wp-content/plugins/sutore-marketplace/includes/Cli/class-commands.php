<?php

declare(strict_types=1);

namespace SutoreMarketplace\Cli;

use SutoreMarketplace\Modules\Invoices\Hooks\InvoiceCronHooks;
use SutoreMarketplace\Modules\Listings\Hooks\CampaignCronHooks;
use SutoreMarketplace\Modules\Listings\Hooks\CustomerOfferCronHooks;
use SutoreMarketplace\Modules\Listings\Hooks\OutletCronHooks;
use SutoreMarketplace\Modules\Merchants\Hooks\BehaviorCronHooks;
use SutoreMarketplace\Modules\Orders\Hooks\CronHooks as FulfillmentCronHooks;
use SutoreMarketplace\Modules\Sourcing\Hooks\SourcingDigestCron;
use SutoreMarketplace\Shared\Effects\OutboundEffectService;
use SutoreMarketplace\Shared\Hooks\Cron;
use SutoreMarketplace\Shared\Hooks\CronRegistry;
use SutoreMarketplace\Shared\Hooks\EventRetentionHooks;

/**
 * WP-CLI manual cron runners (idempotent; delegates to existing hook handlers).
 *
 * ## EXAMPLES
 *
 *     wp sutore-marketplace cron list
 *     wp sutore-marketplace cron run expired-listings
 *     wp sutore-marketplace cron run-all
 */
final class Commands
{
    public static function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        \WP_CLI::add_command('sutore-marketplace cron list', [self::class, 'listCron']);
        \WP_CLI::add_command('sutore-marketplace cron run', [self::class, 'run']);
        \WP_CLI::add_command('sutore-marketplace cron run-all', [self::class, 'runAll']);
        \WP_CLI::add_command('sutore-marketplace schema-upgrade', [self::class, 'schemaUpgrade']);
    }

    /**
     * Run Schema::install (dbDelta) then heavy guarded migrations (UNIQUE swaps, etc.).
     *
     * Prefer this over relying solely on request-path install for large ALTERs.
     * MySQL 8: avoid bigint display-width in hand-written ALTER (dbDelta CREATE uses bare bigint).
     *
     * ## EXAMPLES
     *
     *     wp sutore-marketplace schema-upgrade
     *
     * @when after_wp_load
     */
    public static function schemaUpgrade(array $args, array $assocArgs): void
    {
        unset($args, $assocArgs);

        \SutoreMarketplace\Shared\Database\Schema::install();
        \SutoreMarketplace\Shared\Database\Schema::upgradeHeavy();
        \WP_CLI::success(sprintf(
            'Schema at version %d (heavy migrations applied when pending).',
            \SutoreMarketplace\Shared\Database\Schema::VERSION
        ));
    }

    /**
     * List marketplace WP-Cron hooks and next run times.
     *
     * ## EXAMPLES
     *
     *     wp sutore-marketplace cron list
     *
     * @when after_wp_load
     */
    public static function listCron(array $args, array $assocArgs): void
    {
        unset($args, $assocArgs);

        $rows = [];
        foreach (CronRegistry::wpCronHooks() as $hook) {
            $next = wp_next_scheduled($hook);
            $rows[] = [
                'hook' => $hook,
                'next_run' => $next ? wp_date('Y-m-d H:i:s', $next) : '(not scheduled)',
                'schedule' => $next ? (string) (wp_get_schedule($hook) ?: '') : '',
            ];
        }

        \WP_CLI\Utils\format_items('table', $rows, ['hook', 'next_run', 'schedule']);
    }

    /**
     * Run one marketplace cron job by slug.
     *
     * ## OPTIONS
     *
     * <job>
     * : Job slug (expired-listings, fulfillment-deadlines, campaign, outlet,
     *   customer-offers, invoices, sourcing-digest, behavior, retention, effects).
     *
     * ## EXAMPLES
     *
     *     wp sutore-marketplace cron run campaign
     *
     * @when after_wp_load
     */
    public static function run(array $args, array $assocArgs): void
    {
        unset($assocArgs);

        $job = sanitize_key((string) ($args[0] ?? ''));
        if ($job === '' || !isset(self::jobMap()[$job])) {
            \WP_CLI::error(
                'Unknown job. Use: ' . implode(', ', array_keys(self::jobMap()))
            );
        }

        self::execute($job);
        \WP_CLI::success(sprintf('Ran cron job: %s', $job));
    }

    /**
     * Run every marketplace cron job in sequence.
     *
     * ## EXAMPLES
     *
     *     wp sutore-marketplace cron run-all
     *
     * @when after_wp_load
     */
    public static function runAll(array $args, array $assocArgs): void
    {
        unset($args, $assocArgs);

        foreach (array_keys(self::jobMap()) as $job) {
            \WP_CLI::log(sprintf('Running %s…', $job));
            self::execute($job);
        }

        \WP_CLI::success('All marketplace cron jobs completed.');
    }

    /**
     * @return array<string, callable():void>
     */
    private static function jobMap(): array
    {
        return [
            'expired-listings' => static function (): void {
                (new Cron())->run();
            },
            'fulfillment-deadlines' => static function (): void {
                (new FulfillmentCronHooks())->run();
            },
            'campaign' => static function (): void {
                (new CampaignCronHooks())->run();
            },
            'outlet' => static function (): void {
                (new OutletCronHooks())->run();
            },
            'customer-offers' => static function (): void {
                (new CustomerOfferCronHooks())->run();
            },
            'invoices' => static function (): void {
                (new InvoiceCronHooks())->run();
            },
            'sourcing-digest' => static function (): void {
                (new SourcingDigestCron())->run();
            },
            'behavior' => static function (): void {
                (new BehaviorCronHooks())->runDaily();
            },
            'retention' => static function (): void {
                (new EventRetentionHooks())->run();
            },
            'effects' => static function (): void {
                OutboundEffectService::drainDue();
            },
        ];
    }

    private static function execute(string $job): void
    {
        $map = self::jobMap();
        $map[$job]();
    }
}
