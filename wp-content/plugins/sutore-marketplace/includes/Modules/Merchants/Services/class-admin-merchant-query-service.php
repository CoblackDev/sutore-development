<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\MerchantProfileRepository;
use SutoreMarketplace\Modules\Merchants\Repositories\RestrictionsRepository;
use SutoreMarketplace\Modules\Merchants\Services\CommissionResolver;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class AdminMerchantQueryService
{
    public function __construct(
        private readonly MerchantProfileRepository $profiles = new MerchantProfileRepository(),
        private readonly RestrictionsRepository $restrictions = new RestrictionsRepository(),
        private readonly MerchantBalanceService $balance = new MerchantBalanceService(),
        private readonly MerchantActivityPresenter $activity = new MerchantActivityPresenter(),
        private readonly ListingRepository $listings = new ListingRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, level_labels: array<string, string>, filters: array<string, mixed>}
     */
    public function list(array $args): array
    {
        global $wpdb;

        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($args['per_page'] ?? 30)));
        $offset = ($page - 1) * $perPage;
        $search = trim((string) ($args['search'] ?? ''));
        $status = sanitize_key((string) ($args['status'] ?? ''));
        $tcVerified = $args['tc_verified'] ?? null;
        $hasRestriction = $args['has_restriction'] ?? null;
        $balance = sanitize_key((string) ($args['balance'] ?? ''));
        $sales = sanitize_key((string) ($args['sales'] ?? ''));
        $orderBy = sanitize_key((string) ($args['orderby'] ?? 'id_desc'));

        $profiles = Schema::table('merchant_profiles');
        $restrictions = Schema::table('merchant_restrictions');
        $payoutLines = Schema::table('merchant_payout_lines');
        $users = $wpdb->users;
        $usermeta = $wpdb->usermeta;
        $now = current_time('mysql');

        $capabilityKey = $wpdb->get_blog_prefix() . 'capabilities';
        $paid = PayoutStatus::PAID;
        $pending = PayoutStatus::PENDING;

        if (!in_array($orderBy, ['pending_desc', 'paid_desc', 'sold_desc', 'name_asc', 'id_desc'], true)) {
            $orderBy = 'id_desc';
        }

        $needsBalanceJoin = in_array($balance, ['has_pending', 'no_pending', 'has_paid'], true)
            || in_array($sales, ['has_sales', 'no_sales'], true)
            || in_array($orderBy, ['pending_desc', 'paid_desc', 'sold_desc'], true);

        $joinSqlParts = [
            "INNER JOIN {$usermeta} umcap ON umcap.user_id = u.ID AND umcap.meta_key = %s AND umcap.meta_value LIKE %s",
            "LEFT JOIN {$profiles} p ON p.user_id = u.ID",
        ];
        $joinParams = [$capabilityKey, '%"merchant"%'];

        if ($needsBalanceJoin) {
            $joinSqlParts[] = "LEFT JOIN (
                SELECT merchant_id,
                    COALESCE(SUM(CASE WHEN payout_status = '{$paid}' THEN net_amount ELSE 0 END), 0) AS paid_total,
                    COALESCE(SUM(CASE WHEN payout_status = '{$pending}' THEN net_amount ELSE 0 END), 0) AS pending_total,
                    COALESCE(SUM(CASE WHEN payout_status IN ('{$paid}', '{$pending}') THEN 1 ELSE 0 END), 0) AS sold_count
                FROM {$payoutLines}
                GROUP BY merchant_id
            ) bal ON bal.merchant_id = u.ID";
        }

        $where = ['1=1'];
        $whereParams = [];

        if ($search !== '') {
            $tokens = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens === []) {
                $tokens = [$search];
            }

            $nameFields = [
                'u.display_name',
                'u.user_login',
                'u.user_email',
                'p.account_name',
                'p.account_lastname',
                'p.account_phone',
                'p.account_email',
                "CONCAT(IFNULL(p.account_name, ''), ' ', IFNULL(p.account_lastname, ''))",
                "CONCAT(IFNULL(p.account_lastname, ''), ' ', IFNULL(p.account_name, ''))",
            ];

            if (count($tokens) === 1 && ctype_digit($tokens[0])) {
                $like = '%' . $wpdb->esc_like($tokens[0]) . '%';
                $parts = ['u.ID = %d'];
                $whereParams[] = (int) $tokens[0];
                foreach ($nameFields as $field) {
                    $parts[] = "{$field} LIKE %s";
                    $whereParams[] = $like;
                }
                $where[] = '(' . implode(' OR ', $parts) . ')';
            } else {
                // Each token must match at least one identity field (supports "First Last").
                $tokenClauses = [];
                foreach ($tokens as $token) {
                    $like = '%' . $wpdb->esc_like($token) . '%';
                    $parts = [];
                    foreach ($nameFields as $field) {
                        $parts[] = "{$field} LIKE %s";
                        $whereParams[] = $like;
                    }
                    $tokenClauses[] = '(' . implode(' OR ', $parts) . ')';
                }
                $where[] = '(' . implode(' AND ', $tokenClauses) . ')';
            }
        }

        if (in_array($status, [MerchantLevels::NORMAL, MerchantLevels::VERIFIED, MerchantLevels::PREMIUM], true)) {
            $where[] = 'COALESCE(NULLIF(p.merchant_status, \'\'), %s) = %s';
            $whereParams[] = MerchantLevels::NORMAL;
            $whereParams[] = $status;
        }

        if ($tcVerified === true || $tcVerified === 1 || $tcVerified === '1') {
            $where[] = 'COALESCE(p.tckno_verified, 0) = 1';
        } elseif ($tcVerified === false || $tcVerified === 0 || $tcVerified === '0') {
            $where[] = 'COALESCE(p.tckno_verified, 0) = 0';
        }

        if ($hasRestriction === true || $hasRestriction === 1 || $hasRestriction === '1') {
            $joinSqlParts[] = "INNER JOIN {$restrictions} r ON r.merchant_id = u.ID AND r.is_active = 1 AND (r.expires_at IS NULL OR r.expires_at > %s)";
            $joinParams[] = $now;
        } elseif ($hasRestriction === false || $hasRestriction === 0 || $hasRestriction === '0') {
            $where[] = "NOT EXISTS (
                SELECT 1 FROM {$restrictions} rx
                WHERE rx.merchant_id = u.ID AND rx.is_active = 1
                  AND (rx.expires_at IS NULL OR rx.expires_at > %s)
            )";
            $whereParams[] = $now;
        }

        if ($balance === 'has_pending') {
            $where[] = 'COALESCE(bal.pending_total, 0) > 0';
        } elseif ($balance === 'no_pending') {
            $where[] = 'COALESCE(bal.pending_total, 0) <= 0';
        } elseif ($balance === 'has_paid') {
            $where[] = 'COALESCE(bal.paid_total, 0) > 0';
        }

        if ($sales === 'has_sales') {
            $where[] = 'COALESCE(bal.sold_count, 0) > 0';
        } elseif ($sales === 'no_sales') {
            $where[] = 'COALESCE(bal.sold_count, 0) = 0';
        }

        $orderSql = match ($orderBy) {
            'pending_desc' => 'COALESCE(bal.pending_total, 0) DESC, u.ID DESC',
            'paid_desc' => 'COALESCE(bal.paid_total, 0) DESC, u.ID DESC',
            'sold_desc' => 'COALESCE(bal.sold_count, 0) DESC, u.ID DESC',
            'name_asc' => 'COALESCE(NULLIF(TRIM(CONCAT(IFNULL(p.account_name, \'\'), \' \', IFNULL(p.account_lastname, \'\'))), \'\'), u.display_name, u.user_login) ASC, u.ID DESC',
            default => 'u.ID DESC',
        };

        $joinSql = implode("\n", $joinSqlParts);
        $whereSql = implode(' AND ', $where);
        $params = array_merge($joinParams, $whereParams);

        $countSql = "SELECT COUNT(DISTINCT u.ID) FROM {$users} u {$joinSql} WHERE {$whereSql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($countSql, $params));

        $balanceSelect = $needsBalanceJoin
            ? 'COALESCE(bal.paid_total, 0) AS paid_total,
                COALESCE(bal.pending_total, 0) AS pending_total,
                COALESCE(bal.sold_count, 0) AS sold_count'
            : '0 AS paid_total, 0 AS pending_total, 0 AS sold_count';

        $listSql = "SELECT DISTINCT u.ID AS user_id, u.user_login, u.user_email, u.display_name,
                p.account_name, p.account_lastname, p.account_phone, p.account_email,
                p.merchant_status, p.tckno_verified,
                {$balanceSelect}
            FROM {$users} u
            {$joinSql}
            WHERE {$whereSql}
            ORDER BY {$orderSql}
            LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($listSql, array_merge($params, [$perPage, $offset])), ARRAY_A) ?: [];

        $ids = array_map(static fn (array $row): int => (int) $row['user_id'], $rows);
        $listingCounts = $this->listingCountsForMerchants($ids);
        $restrictedIds = $this->activeRestrictionMerchantIds($ids);
        $balanceByMerchant = $needsBalanceJoin
            ? []
            : (new \SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository())->summariesForMerchants($ids);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['user_id'];
            $level = (string) ($row['merchant_status'] ?? '');
            if (!in_array($level, [MerchantLevels::NORMAL, MerchantLevels::VERIFIED, MerchantLevels::PREMIUM], true)) {
                $level = MerchantLevels::NORMAL;
            }
            $name = trim((string) ($row['account_name'] ?? '') . ' ' . (string) ($row['account_lastname'] ?? ''));
            if ($name === '') {
                $name = (string) ($row['display_name'] ?: $row['user_login']);
            }
            if (!$needsBalanceJoin && isset($balanceByMerchant[$id])) {
                $pendingTotal = (float) $balanceByMerchant[$id]['pending_total'];
                $paidTotal = (float) $balanceByMerchant[$id]['paid_total'];
                $soldCount = (int) ($balanceByMerchant[$id]['paid_count'] + $balanceByMerchant[$id]['pending_count']);
            } else {
                $pendingTotal = round((float) ($row['pending_total'] ?? 0), 2);
                $paidTotal = round((float) ($row['paid_total'] ?? 0), 2);
                $soldCount = (int) ($row['sold_count'] ?? 0);
            }

            $items[] = [
                'id' => $id,
                'display_name' => $name,
                'user_login' => (string) $row['user_login'],
                'email' => (string) (($row['account_email'] ?? '') !== '' ? $row['account_email'] : $row['user_email']),
                'phone' => (string) ($row['account_phone'] ?? ''),
                'level' => $level,
                'level_label' => MerchantLevels::labelForStatus($level),
                'tc_verified' => !empty($row['tckno_verified']),
                'tc_verified_label' => MerchantLevels::tcStatusLabel(!empty($row['tckno_verified'])),
                'listing_count' => (int) ($listingCounts[$id] ?? 0),
                'sold_count' => $soldCount,
                'pending_total' => $pendingTotal,
                'paid_total' => $paidTotal,
                'formatted_pending' => MarketplacePricing::formatTl($pendingTotal),
                'formatted_paid' => MarketplacePricing::formatTl($paidTotal),
                'has_active_restriction' => isset($restrictedIds[$id]),
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'level_labels' => [
                MerchantLevels::NORMAL => MerchantLevels::labelForStatus(MerchantLevels::NORMAL),
                MerchantLevels::VERIFIED => MerchantLevels::labelForStatus(MerchantLevels::VERIFIED),
                MerchantLevels::PREMIUM => MerchantLevels::labelForStatus(MerchantLevels::PREMIUM),
            ],
            'platform_overrides' => (new CommissionResolver())->visiblePlatformOverrides(),
            'filters' => [
                'search' => $search,
                'level' => $status,
                'tc_verified' => $tcVerified === null || $tcVerified === '' ? '' : (string) $tcVerified,
                'has_restriction' => $hasRestriction === null || $hasRestriction === '' ? '' : (string) $hasRestriction,
                'balance' => $balance,
                'sales' => $sales,
                'orderby' => $orderBy,
            ],
        ];
    }

    /** @return array<string, mixed>|\WP_Error */
    public function detail(int $merchantId): array|\WP_Error
    {
        $user = get_userdata($merchantId);
        if (!$user || !in_array('merchant', (array) $user->roles, true)) {
            return new \WP_Error('sutore_merchant_not_found', __('Seller not found.', 'sutore-marketplace'), ['status' => 404]);
        }

        $profile = MerchantMeta::readProfile($merchantId);
        $row = $this->profiles->find($merchantId);
        $level = MerchantLevels::statusForUser($merchantId);
        $balance = $this->balance->summaryForMerchant($merchantId);
        $listingTotal = $this->listings->query(['merchant_id' => $merchantId, 'per_page' => 1, 'page' => 1])['total'] ?? 0;

        $restrictionResult = $this->restrictions->query([
            'merchant_id' => $merchantId,
            'page' => 1,
            'per_page' => 50,
        ]);
        $restrictionItems = [];
        $now = current_time('mysql');
        foreach ($restrictionResult['items'] as $restriction) {
            $flagActive = (int) ($restriction->is_active ?? 0) === 1;
            $currentlyActive = RestrictionsRepository::rowIsCurrentlyActive($restriction, $now);
            $restrictionItems[] = [
                'id' => (int) $restriction->id,
                'restriction_key' => (string) $restriction->restriction_key,
                'is_active' => $currentlyActive,
                'is_expired' => $flagActive && !$currentlyActive,
                'reason' => (string) ($restriction->reason ?? ''),
                'expires_at' => $restriction->expires_at !== null && (string) $restriction->expires_at !== ''
                    ? (string) $restriction->expires_at
                    : null,
                'created_at' => (string) ($restriction->created_at ?? ''),
            ];
        }

        $recent = [];
        foreach ($balance['recent'] as $line) {
            $recent[] = PayoutLineService::presentLine($line);
        }
        unset($balance['recent']);

        $cities = [];
        if (function_exists('WC') && WC()->countries) {
            $states = WC()->countries->get_states('TR');
            if (is_array($states)) {
                foreach ($states as $code => $label) {
                    $cities[] = [
                        'code' => (string) $code,
                        'label' => (string) $label,
                    ];
                }
            }
        }

        $name = trim($profile[MerchantMeta::ACCOUNT_NAME] . ' ' . $profile[MerchantMeta::ACCOUNT_LASTNAME]);
        if ($name === '') {
            $name = $user->display_name ?: $user->user_login;
        }

        $registeredRaw = (string) $user->user_registered;
        $registeredTs = $registeredRaw !== '' ? (int) strtotime($registeredRaw . ' UTC') : 0;
        $registeredLabel = $registeredTs > 0
            ? (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $registeredTs)
            : '';

        return [
            'id' => $merchantId,
            'display_name' => $name,
            'user' => [
                'login' => $user->user_login,
                'email' => $user->user_email,
                'registered' => $registeredRaw,
                'registered_label' => $registeredLabel,
                'roles' => array_values((array) $user->roles),
            ],
            'profile' => $profile,
            'tc_verified_label' => MerchantLevels::tcStatusLabel(MerchantMeta::isTcVerified($merchantId)),
            'tc_verified' => MerchantMeta::isTcVerified($merchantId),
            'tckno_verified_at' => $row !== null ? (int) ($row['tckno_verified_at'] ?? 0) : 0,
            'tckno_verify_method' => $row !== null ? (string) ($row['tckno_verify_method'] ?? '') : '',
            'level' => $level,
            'level_label' => MerchantLevels::labelForStatus($level),
            'commission_percent' => MerchantLevels::commissionPercentForUser($merchantId),
            'commission' => $this->commissionBlock($merchantId),
            'balance' => $balance,
            'recent_payouts' => $recent,
            'listing_count' => (int) $listingTotal,
            'restrictions' => $restrictionItems,
            'restriction_keys' => RestrictionsRepository::KEYS,
            'events' => $this->activity->forMerchant($merchantId, 100),
            'cities' => $cities,
            'birth_year_max' => (int) gmdate('Y'),
            'level_options' => [
                MerchantLevels::NORMAL => MerchantLevels::labelForStatus(MerchantLevels::NORMAL),
                MerchantLevels::VERIFIED => MerchantLevels::labelForStatus(MerchantLevels::VERIFIED),
                MerchantLevels::PREMIUM => MerchantLevels::labelForStatus(MerchantLevels::PREMIUM),
            ],
            'referral' => $this->referralBlock($merchantId, $row),
        ];
    }

    /**
     * @return array{
     *   effective_percent: float,
     *   level_percent: float,
     *   is_overridden: bool,
     *   expires_at: ?string,
     *   starts_at: ?string,
     *   source: string,
     *   raises_level: bool,
     *   active_overrides: list<array<string, mixed>>
     * }
     */
    private function commissionBlock(int $merchantId): array
    {
        $resolved = (new CommissionResolver())->forUser($merchantId);

        return [
            'effective_percent' => (float) $resolved['percent'],
            'level_percent' => (float) $resolved['level_percent'],
            'is_overridden' => (bool) $resolved['is_overridden'],
            'raises_level' => (bool) $resolved['raises_level'],
            'expires_at' => $resolved['expires_at'],
            'starts_at' => $resolved['starts_at'],
            'source' => (string) $resolved['source'],
            'active_overrides' => (new CommissionResolver())->visibleOverridesForMerchant($merchantId),
        ];
    }

    /**
     * @param array<string, string>|null $row
     * @return array{code: string, link: string, referred_by_user_id: int, referred_by_login: string, rewarded_at: ?string}
     */
    private function referralBlock(int $merchantId, ?array $row): array
    {
        $referral = (new ReferralService())->snapshotForUser($merchantId, true);
        $referredBy = (int) ($referral['referred_by_user_id'] ?? 0);
        $referredLogin = '';
        if ($referredBy > 0) {
            $inviter = get_userdata($referredBy);
            $referredLogin = $inviter ? (string) $inviter->user_login : '#' . $referredBy;
        }

        $rewardedAt = trim((string) ($row['referral_rewarded_at'] ?? ''));

        return [
            'code' => (string) ($referral['code'] ?? ''),
            'link' => (string) ($referral['link'] ?? ''),
            'referred_by_user_id' => $referredBy,
            'referred_by_login' => $referredLogin,
            'rewarded_at' => $rewardedAt !== '' ? $rewardedAt : null,
        ];
    }

    /**
     * @param list<int> $merchantIds
     * @return array<int, int>
     */
    private function listingCountsForMerchants(array $merchantIds): array
    {
        $merchantIds = array_values(array_filter(array_map('intval', $merchantIds)));
        if ($merchantIds === []) {
            return [];
        }

        global $wpdb;
        $table = Schema::table('listings');
        $placeholders = implode(',', array_fill(0, count($merchantIds), '%d'));
        $sql = "SELECT merchant_id, COUNT(*) AS cnt FROM {$table} WHERE merchant_id IN ({$placeholders}) GROUP BY merchant_id";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $merchantIds), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['merchant_id']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * @param list<int> $merchantIds
     * @return array<int, true>
     */
    private function activeRestrictionMerchantIds(array $merchantIds): array
    {
        $merchantIds = array_values(array_filter(array_map('intval', $merchantIds)));
        if ($merchantIds === []) {
            return [];
        }

        global $wpdb;
        $table = Schema::table('merchant_restrictions');
        $placeholders = implode(',', array_fill(0, count($merchantIds), '%d'));
        $now = current_time('mysql');
        $sql = "SELECT DISTINCT merchant_id FROM {$table}
            WHERE merchant_id IN ({$placeholders})
              AND is_active = 1
              AND (expires_at IS NULL OR expires_at > %s)";
        $rows = $wpdb->get_col($wpdb->prepare($sql, array_merge($merchantIds, [$now]))) ?: [];

        $out = [];
        foreach ($rows as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }
}
