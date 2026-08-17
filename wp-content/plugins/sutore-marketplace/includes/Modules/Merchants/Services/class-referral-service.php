<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Frontend\MerchantAccount;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Repositories\CommissionOverrideRepository;
use SutoreMarketplace\Modules\Merchants\Repositories\MerchantEventsRepository;
use SutoreMarketplace\Modules\Merchants\Repositories\MerchantProfileRepository;
use SutoreMarketplace\Modules\Merchants\Settings\ReferralSettings;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;

final class ReferralService
{
    private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const CODE_LENGTH = 8;

    public function __construct(
        private readonly MerchantProfileRepository $profiles = new MerchantProfileRepository(),
        private readonly CommissionOverrideRepository $overrides = new CommissionOverrideRepository(),
        private readonly MerchantEventsRepository $events = new MerchantEventsRepository(),
        private readonly CommissionOverrideService $overrideService = new CommissionOverrideService(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly ListingRepository $listings = new ListingRepository(),
    ) {
    }

    /**
     * @return array{
     *   code: string,
     *   link: string,
     *   can_enter_code: bool,
     *   referred_by_user_id: int
     * }
     */
    public function snapshotForUser(int $userId, bool $isMerchant): array
    {
        if (!$isMerchant) {
            return [
                'code' => '',
                'link' => '',
                'can_enter_code' => true,
                'referred_by_user_id' => 0,
            ];
        }

        $row = $this->profiles->find($userId);
        $code = $this->ensureCode($userId);

        return [
            'code' => $code,
            'link' => $this->linkForCode($code),
            'can_enter_code' => false,
            'referred_by_user_id' => (int) ($row['referred_by_user_id'] ?? 0),
        ];
    }

    public function ensureCode(int $merchantId): string
    {
        if ($merchantId <= 0) {
            return '';
        }

        $row = $this->profiles->find($merchantId);
        $existing = strtoupper(trim((string) ($row['referral_code'] ?? '')));
        if ($existing !== '') {
            return $existing;
        }

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = $this->generateCode();
            if ($this->profiles->findUserIdByReferralCode($code) > 0) {
                continue;
            }
            if ($this->profiles->setReferralCode($merchantId, $code)) {
                return $code;
            }
        }

        return '';
    }

    public function linkForCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }

        $base = function_exists('wc_get_account_endpoint_url')
            ? wc_get_account_endpoint_url(MerchantAccount::ENDPOINT_MERCHANT_AREA)
            : home_url('/my-account/' . MerchantAccount::ENDPOINT_MERCHANT_AREA . '/');

        return add_query_arg('ref', $code, $base);
    }

    public function validateInvite(int $inviteeId, string $code): true|\WP_Error
    {
        $inviterId = $this->resolveInviter($inviteeId, $code);
        if ($inviterId instanceof \WP_Error) {
            return $inviterId;
        }

        return true;
    }

    public function acceptInvite(int $inviteeId, string $code): true|\WP_Error
    {
        $inviterId = $this->resolveInviter($inviteeId, $code);
        if ($inviterId instanceof \WP_Error) {
            return $inviterId;
        }

        $row = $this->profiles->find($inviteeId);
        $already = (int) ($row['referred_by_user_id'] ?? 0);
        if ($already !== $inviterId) {
            if (!$this->profiles->setReferredBy($inviteeId, $inviterId)) {
                return new \WP_Error(
                    'sutore_referral_locked',
                    __('A referral code is already attached to this seller.', 'sutore-marketplace'),
                    ['status' => 400]
                );
            }
        }

        $points = ReferralSettings::inviteePointsOff();
        $days = ReferralSettings::inviteeDurationDays();
        $overrideId = 0;
        if ($points > 0) {
            $created = $this->overrideService->createFromReferral(
                $inviteeId,
                $points,
                $days,
                __('Referral welcome commission discount', 'sutore-marketplace')
            );
            if (!($created instanceof \WP_Error)) {
                $overrideId = (int) ($created['id'] ?? 0);
            }
        }

        $this->events->log($inviteeId, 'merchant_referral_accepted', [
            'actor_role' => 'system',
            'inviter_id' => $inviterId,
            'override_id' => $overrideId,
            'points_off' => $points,
            'duration_days' => $days,
        ]);

        return true;
    }

    public function onFirstSale(int $inviteeId, int $listingId): void
    {
        if ($inviteeId <= 0) {
            return;
        }

        $row = $this->profiles->find($inviteeId);
        $inviterId = (int) ($row['referred_by_user_id'] ?? 0);
        if ($inviterId <= 0 || $inviterId === $inviteeId) {
            return;
        }

        if (!$this->profiles->claimReferralReward($inviteeId)) {
            return;
        }

        $points = ReferralSettings::inviterPointsOff();
        $days = ReferralSettings::inviterDurationDays();
        $maxRewards = ReferralSettings::inviterMaxRewardsPerPeriod();
        $periodDays = ReferralSettings::periodDays();
        $since = $this->periodStartMysql($periodDays);
        $used = $this->overrides->countReferralCreatedSince($inviterId, $since);

        if ($maxRewards > 0 && $used >= $maxRewards) {
            $this->events->log($inviterId, 'merchant_referral_inviter_capped', [
                'actor_role' => 'system',
                'invitee_id' => $inviteeId,
                'listing_id' => $listingId,
                'period_days' => $periodDays,
                'max_rewards' => $maxRewards,
                'used' => $used,
            ]);

            return;
        }

        $overrideId = 0;
        $expiresAt = null;
        if ($points > 0) {
            $created = $this->overrideService->createFromReferral(
                $inviterId,
                $points,
                $days,
                __('Referral reward commission discount', 'sutore-marketplace')
            );
            if (!($created instanceof \WP_Error)) {
                $overrideId = (int) ($created['id'] ?? 0);
            }
            $expiresAt = $this->expiresAtFromDays($days);
        }

        $inviteeName = $this->displayName($inviteeId);
        $this->events->log($inviterId, 'merchant_referral_inviter_rewarded', [
            'actor_role' => 'system',
            'invitee_id' => $inviteeId,
            'invitee_name' => $inviteeName,
            'listing_id' => $listingId,
            'override_id' => $overrideId,
            'points_off' => $points,
            'duration_days' => $days,
            'expires_at' => $expiresAt,
        ]);

        if ($points > 0 && $overrideId <= 0) {
            return;
        }

        $listing = $this->listings->find($listingId);
        $product = '';
        if ($listing) {
            $wcProduct = wc_get_product($listing->parentProductId ?: $listing->variationId);
            if ($wcProduct) {
                $product = $wcProduct->get_name();
            }
        }

        $this->notifications->dispatch($inviterId, NotificationType::REFERRAL_REWARDED, [
            'invitee_id' => $inviteeId,
            'invitee_name' => $inviteeName,
            'product' => $product,
            'points_off' => $points,
            'expires_at' => $expiresAt,
            'variation_id' => $listingId,
        ], DAY_IN_SECONDS * 365);
    }

    private function resolveInviter(int $inviteeId, string $code): int|\WP_Error
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? '');
        if ($normalized === '') {
            return new \WP_Error(
                'sutore_referral_code_required',
                __('Enter a valid invite code.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $inviterId = $this->profiles->findUserIdByReferralCode($normalized);
        if ($inviterId <= 0 || !MerchantMeta::isMerchant($inviterId)) {
            return new \WP_Error(
                'sutore_referral_code_invalid',
                __('This invite code is not valid.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        if ($inviterId === $inviteeId) {
            return new \WP_Error(
                'sutore_referral_self',
                __('You cannot use your own invite code.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $row = $this->profiles->find($inviteeId);
        $already = (int) ($row['referred_by_user_id'] ?? 0);
        if ($already > 0 && $already !== $inviterId) {
            return new \WP_Error(
                'sutore_referral_locked',
                __('A referral code is already attached to this seller.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        return $inviterId;
    }

    private function generateCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    private function periodStartMysql(int $periodDays): string
    {
        $base = strtotime(current_time('mysql'));
        $ts = ($base !== false ? $base : time()) - ($periodDays * DAY_IN_SECONDS);

        return wp_date('Y-m-d H:i:s', $ts);
    }

    private function expiresAtFromDays(int $durationDays): ?string
    {
        if ($durationDays <= 0) {
            return null;
        }

        $base = strtotime(current_time('mysql'));
        if ($base === false) {
            return null;
        }

        return wp_date('Y-m-d H:i:s', $base + ($durationDays * DAY_IN_SECONDS));
    }

    private function displayName(int $userId): string
    {
        $profile = MerchantMeta::readProfile($userId);
        $name = trim(($profile[MerchantMeta::ACCOUNT_NAME] ?? '') . ' ' . ($profile[MerchantMeta::ACCOUNT_LASTNAME] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $user = get_userdata($userId);

        return $user ? (string) $user->display_name : '#' . $userId;
    }
}
