<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Sourcing\Hooks;

use SutoreMarketplace\Modules\Orders\Services\Notifications;
use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Modules\Sourcing\Repositories\SourcingRepository;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class SourcingDigestCron
{
    public const HOOK = 'sutore_marketplace_sourcing_digest';
    private const SENT_OPTION = 'sutore_marketplace_sourcing_digest_sent_ids';
    private const SENT_MAX = 500;
    private const MERCHANT_SCAN_LIMIT = 500;

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', static function (): void {
            self::schedule();
        }, 20);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        while ($timestamp = wp_next_scheduled(self::HOOK)) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public function run(): void
    {
        if (!OrderSettings::smsEnabled() || !OrderSettings::smsEventEnabled('sourcing_digest')) {
            return;
        }

        $repo = new SourcingRepository();
        $result = $repo->query(['status' => 'open', 'page' => 1, 'per_page' => 200]);
        $items = $result['items'] ?? [];
        if ($items === []) {
            return;
        }

        $sent = get_option(self::SENT_OPTION, []);
        if (!is_array($sent)) {
            $sent = [];
        }

        $newTitles = [];
        foreach ($items as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id <= 0 || !empty($sent[$id])) {
                continue;
            }

            $parentId = (int) ($row->parent_product_id ?? 0);
            $title = $parentId > 0 ? (string) get_the_title($parentId) : ('#' . $id);
            $title = trim(str_replace(['&#8211;', '–'], '', $title));
            if ($title !== '') {
                $newTitles[] = $title;
                $sent[$id] = time();
            }
        }

        if ($newTitles === []) {
            return;
        }

        $phones = $this->merchantPhonesForDigest();
        if ($phones === []) {
            return;
        }

        $chunks = array_chunk($newTitles, 5);
        foreach ($chunks as $chunk) {
            $vars = ['products' => implode(', ', $chunk)];
            foreach ($phones as $phone) {
                Notifications::sendEvent('sourcing_digest', $phone, $vars, true);
            }
        }

        update_option(self::SENT_OPTION, $this->pruneSentIds($sent), false);
    }

    /**
     * Same recipient set as before: first N merchants by login, then verified/premium with phone.
     *
     * @return list<string>
     */
    private function merchantPhonesForDigest(): array
    {
        global $wpdb;

        $profiles = Schema::table('merchant_profiles');
        $users = $wpdb->users;
        $usermeta = $wpdb->usermeta;
        $capabilityKey = $wpdb->get_blog_prefix() . 'capabilities';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(p.account_phone, '') AS account_phone,
                    COALESCE(p.merchant_status, '') AS merchant_status
             FROM {$users} u
             INNER JOIN {$usermeta} umcap
                ON umcap.user_id = u.ID
               AND umcap.meta_key = %s
               AND umcap.meta_value LIKE %s
             LEFT JOIN {$profiles} p ON p.user_id = u.ID
             ORDER BY u.user_login ASC
             LIMIT %d",
            $capabilityKey,
            '%"merchant"%',
            self::MERCHANT_SCAN_LIMIT
        ));

        $phones = [];
        foreach ($rows ?: [] as $row) {
            $status = (string) ($row->merchant_status ?? '');
            if (!in_array($status, [MerchantLevels::VERIFIED, MerchantLevels::PREMIUM], true)) {
                continue;
            }

            $phone = trim((string) ($row->account_phone ?? ''));
            if ($phone !== '') {
                $phones[] = $phone;
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * @param array<int|string, mixed> $sent
     * @return array<int, int>
     */
    private function pruneSentIds(array $sent): array
    {
        $normalized = [];
        foreach ($sent as $id => $ts) {
            $id = (int) $id;
            if ($id > 0) {
                $normalized[$id] = (int) $ts;
            }
        }

        if (count($normalized) <= self::SENT_MAX) {
            return $normalized;
        }

        arsort($normalized);

        return array_slice($normalized, 0, self::SENT_MAX, true);
    }
}
