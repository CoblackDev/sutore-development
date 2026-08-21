<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Merchants\Services\CommissionResolver;
use SutoreMarketplace\Modules\Merchants\Services\TcIdentityVerifier;
use SutoreMarketplace\Modules\Merchants\Services\TcValidator;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Hooks\CheckoutIdentityHooks;
use SutoreMarketplace\Shared\Settings\Settings;

/**
 * Age-verified platform discount. Seller asking and seller net stay unchanged.
 * Cart shows a negative fee capped by remaining hizmet + güvence + commission.
 */
final class YouthDiscount
{
    public const SESSION_KEY = 'sutore_marketplace_youth';
    public const USER_META_ELIGIBLE = '_sutore_marketplace_youth_eligible';
    public const USER_META_FINGERPRINT = '_sutore_marketplace_youth_fingerprint';
    public const USER_META_VERIFIED_AT = '_sutore_marketplace_youth_verified_at';
    public const ORDER_META_ELIGIBLE = '_sutore_marketplace_youth_eligible';
    public const ORDER_META_AMOUNT = '_sutore_marketplace_youth_discount_amount';

    /** Proof TTL: after this, place-order may re-call NVI; cart never does. */
    public const PROOF_TTL_SECONDS = 86400;

    /**
     * @param array<string, mixed> $posted
     */
    public static function captureFromPosted(array $posted, bool $addNotices = false, bool $allowRemoteVerify = false): void
    {
        $tckno = self::digits((string) ($posted[CheckoutIdentityHooks::CLASSIC_TCKNO_FIELD] ?? ''));
        $birthYear = self::normalizeBirthYear((string) ($posted[CheckoutIdentityHooks::CLASSIC_BIRTH_YEAR_FIELD] ?? ''));
        $firstName = sanitize_text_field((string) ($posted['billing_first_name'] ?? ''));
        $lastName = sanitize_text_field((string) ($posted['billing_last_name'] ?? ''));

        self::captureIdentity($tckno, $birthYear, $firstName, $lastName, $addNotices, $allowRemoteVerify);
    }

    /**
     * @param array<string, mixed> $additionalFields
     */
    public static function captureFromBlocks(\WC_Customer $customer, array $additionalFields, bool $addNotices = false, bool $allowRemoteVerify = false): void
    {
        $tckno = self::digits((string) ($additionalFields[CheckoutIdentityHooks::BLOCKS_TCKNO_ID] ?? ''));
        if ($tckno === '') {
            $tckno = self::digits((string) $customer->get_meta(CheckoutIdentityHooks::USER_META_TCKNO));
        }

        $birthYear = self::normalizeBirthYear((string) ($additionalFields[CheckoutIdentityHooks::BLOCKS_BIRTH_YEAR_ID] ?? ''));
        if ($birthYear <= 0) {
            $birthYear = self::normalizeBirthYear((string) $customer->get_meta(CheckoutIdentityHooks::USER_META_BIRTH_YEAR));
        }

        self::captureIdentity(
            $tckno,
            $birthYear,
            (string) $customer->get_billing_first_name(),
            (string) $customer->get_billing_last_name(),
            $addNotices,
            $allowRemoteVerify
        );
    }

    /**
     * @param bool $allowRemoteVerify When false (cart/review), only reuse local proof — never call NVI.
     */
    public static function captureIdentity(
        string $tckno,
        int $birthYear,
        string $firstName,
        string $lastName,
        bool $addNotices = false,
        bool $allowRemoteVerify = false
    ): void {
        if (!Settings::youthDiscountEnabled()) {
            self::clearSession();
            return;
        }

        if ($tckno === '' || $birthYear <= 0) {
            self::clearSession();
            return;
        }

        if (!TcValidator::isValid($tckno)) {
            self::clearSession();
            return;
        }

        $maxYear = self::currentYear();
        if ($birthYear < 1900 || $birthYear > $maxYear) {
            self::clearSession();
            if ($addNotices) {
                wc_add_notice(__('Enter a valid year of birth.', 'sutore-marketplace'), 'error');
            }
            return;
        }

        $firstName = self::normalizeName($firstName);
        $lastName = self::normalizeName($lastName);
        if ($firstName === '' || $lastName === '') {
            self::clearSession();
            if ($addNotices) {
                wc_add_notice(
                    __('First and last name are required for the youth discount.', 'sutore-marketplace'),
                    'error'
                );
            }
            return;
        }

        $fingerprint = self::fingerprint($tckno, $birthYear, $firstName, $lastName);
        $existing = self::readState();
        if (
            is_array($existing)
            && ($existing['fingerprint'] ?? '') === $fingerprint
            && !empty($existing['verified'])
            && self::proofIsFresh($existing)
        ) {
            $existing['eligible'] = self::isAgeEligible($birthYear);
            $existing['birth_year'] = $birthYear;
            $existing['tckno'] = $tckno;
            self::writeState($existing, get_current_user_id(), false);
            return;
        }

        if (!$allowRemoteVerify) {
            // Cart/review: do not call NVI; clear stale proof so fee is not applied on mismatch.
            if (!is_array($existing) || ($existing['fingerprint'] ?? '') !== $fingerprint) {
                self::clearSession();
            }
            return;
        }

        if (Settings::tcVerificationMode() === 'manual') {
            self::clearSession();
            if ($addNotices) {
                wc_add_notice(
                    __('Youth discount is unavailable while TC verification is set to manual approval.', 'sutore-marketplace'),
                    'notice'
                );
            }
            return;
        }

        $verified = TcIdentityVerifier::verify($tckno, $firstName, $lastName, $birthYear);
        if ($verified instanceof \WP_Error) {
            self::clearSession();
            $userId = get_current_user_id();
            if ($userId > 0) {
                update_user_meta($userId, self::USER_META_ELIGIBLE, '0');
                delete_user_meta($userId, self::USER_META_FINGERPRINT);
                delete_user_meta($userId, self::USER_META_VERIFIED_AT);
            }
            if ($addNotices) {
                wc_add_notice($verified->get_error_message(), 'error');
            }
            return;
        }

        $state = [
            'tckno' => $tckno,
            'birth_year' => $birthYear,
            'fingerprint' => $fingerprint,
            'verified' => true,
            'eligible' => self::isAgeEligible($birthYear),
            'verified_at' => time(),
        ];
        self::writeState($state, get_current_user_id(), true);
    }

    /**
     * Seed / staff helper: persist a verified identity without calling NVI.
     */
    public static function rememberVerified(int $userId, string $tckno, int $birthYear, string $firstName, string $lastName): void
    {
        $tckno = self::digits($tckno);
        $firstName = self::normalizeName($firstName);
        $lastName = self::normalizeName($lastName);
        $state = [
            'tckno' => $tckno,
            'birth_year' => $birthYear,
            'fingerprint' => self::fingerprint($tckno, $birthYear, $firstName, $lastName),
            'verified' => true,
            'eligible' => self::isAgeEligible($birthYear),
        ];
        self::writeState($state, $userId);
        update_user_meta($userId, CheckoutIdentityHooks::USER_META_TCKNO, $tckno);
        update_user_meta($userId, CheckoutIdentityHooks::USER_META_BIRTH_YEAR, (string) $birthYear);
    }

    public static function isEligible(): bool
    {
        if (!Settings::youthDiscountEnabled() || Settings::youthDiscountPercent() <= 0) {
            return false;
        }

        $state = self::readState();
        if (!is_array($state) || empty($state['verified'])) {
            return false;
        }

        return self::isAgeEligible((int) ($state['birth_year'] ?? 0));
    }

    public static function amountForCart($cart): float
    {
        if (!self::isEligible() || !is_object($cart) || !method_exists($cart, 'get_cart')) {
            return 0.0;
        }

        $variationIds = [];
        $qtyByVariation = [];
        foreach ($cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!$product || !is_object($product)) {
                continue;
            }
            $variationId = (int) ($item['variation_id'] ?? $product->get_id());
            if ($variationId <= 0) {
                continue;
            }
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $variationIds[] = $variationId;
            $qtyByVariation[$variationId] = ($qtyByVariation[$variationId] ?? 0) + $qty;
        }

        if ($variationIds === []) {
            return 0.0;
        }

        $byVariation = (new ListingRepository())->findByVariationIds($variationIds);
        $commission = new CommissionResolver();
        $customerTotal = 0.0;
        $platformCap = 0.0;

        foreach ($qtyByVariation as $variationId => $qty) {
            $listing = $byVariation[$variationId] ?? null;
            if (!$listing instanceof Listing) {
                continue;
            }
            $customerTotal += MarketplacePricing::customerPrice($listing) * $qty;
            $platformCap += self::platformPoolForListing($listing, $commission) * $qty;
        }

        if ($customerTotal <= 0 || $platformCap <= 0) {
            return 0.0;
        }

        $requested = round($customerTotal * Settings::youthDiscountPercent() / 100, 2);

        return min($requested, round($platformCap, 2));
    }

    public static function feeLabel(): string
    {
        $percent = rtrim(rtrim(number_format(Settings::youthDiscountPercent(), 2, '.', ''), '0'), '.');

        return sprintf(
            /* translators: %s: discount percent */
            __('Youth discount (%s%%)', 'sutore-marketplace'),
            $percent
        );
    }

    public static function attachOrderMeta(\WC_Order $order, float $amount): void
    {
        $state = self::readState();
        $eligible = is_array($state) && !empty($state['verified']) && self::isAgeEligible((int) ($state['birth_year'] ?? 0));
        $order->update_meta_data(self::ORDER_META_ELIGIBLE, $eligible ? '1' : '0');
        $order->update_meta_data(self::ORDER_META_AMOUNT, $amount > 0 ? (string) round($amount, 2) : '0');
    }

    public static function isAgeEligible(int $birthYear): bool
    {
        if ($birthYear < 1900) {
            return false;
        }

        return self::ageFromBirthYear($birthYear) < Settings::youthDiscountMaxAge();
    }

    public static function ageFromBirthYear(int $birthYear): int
    {
        return max(0, self::currentYear() - $birthYear);
    }

    public static function currentYear(): int
    {
        return (int) wp_date('Y');
    }

    public static function normalizeBirthYear(string $raw): int
    {
        $digits = self::digits($raw);
        if (strlen($digits) !== 4) {
            return 0;
        }

        return (int) $digits;
    }

    public static function platformPoolForListing(Listing $listing, ?CommissionResolver $resolver = null): float
    {
        $fees = MarketplacePricing::feeBreakdownForListing($listing);
        $asking = MarketplacePricing::activeAsking($listing);
        $percent = ($resolver ?? new CommissionResolver())->forListing($listing)['percent'];
        $commission = round($asking * max(0.0, $percent) / 100, 2);

        return round((float) $fees['hizmet'] + (float) $fees['guvence'] + $commission, 2);
    }

    /** @return array<string, mixed>|null */
    private static function readState(): ?array
    {
        $session = self::sessionGet();
        if (is_array($session) && !empty($session['verified']) && self::proofIsFresh($session)) {
            return $session;
        }

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return null;
        }

        $fingerprint = (string) get_user_meta($userId, self::USER_META_FINGERPRINT, true);
        $birthYear = (int) get_user_meta($userId, CheckoutIdentityHooks::USER_META_BIRTH_YEAR, true);
        $verifiedAt = (int) get_user_meta($userId, self::USER_META_VERIFIED_AT, true);
        if ($fingerprint === '' || $birthYear <= 0) {
            return null;
        }

        $state = [
            'tckno' => (string) get_user_meta($userId, CheckoutIdentityHooks::USER_META_TCKNO, true),
            'birth_year' => $birthYear,
            'fingerprint' => $fingerprint,
            'verified' => true,
            'eligible' => (string) get_user_meta($userId, self::USER_META_ELIGIBLE, true) === '1',
            'verified_at' => $verifiedAt,
        ];

        if (!self::proofIsFresh($state)) {
            return null;
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private static function proofIsFresh(array $state): bool
    {
        $at = (int) ($state['verified_at'] ?? 0);
        if ($at <= 0) {
            $userId = get_current_user_id();
            if ($userId > 0) {
                $at = (int) get_user_meta($userId, self::USER_META_VERIFIED_AT, true);
            }
        }
        if ($at <= 0) {
            return false;
        }

        return (time() - $at) <= self::PROOF_TTL_SECONDS;
    }

    /** @param array<string, mixed> $state */
    private static function writeState(array $state, int $userId, bool $bumpVerifiedAt = true): void
    {
        if ($bumpVerifiedAt || empty($state['verified_at'])) {
            $state['verified_at'] = time();
        }
        self::sessionSet($state);
        if ($userId <= 0) {
            return;
        }

        update_user_meta($userId, self::USER_META_ELIGIBLE, !empty($state['eligible']) ? '1' : '0');
        update_user_meta($userId, self::USER_META_FINGERPRINT, (string) ($state['fingerprint'] ?? ''));
        if ($bumpVerifiedAt) {
            update_user_meta($userId, self::USER_META_VERIFIED_AT, (int) ($state['verified_at'] ?? time()));
        }
        update_user_meta($userId, CheckoutIdentityHooks::USER_META_BIRTH_YEAR, (string) ((int) ($state['birth_year'] ?? 0)));
        $tckno = (string) ($state['tckno'] ?? '');
        if ($tckno !== '') {
            update_user_meta($userId, CheckoutIdentityHooks::USER_META_TCKNO, $tckno);
        }
    }

    private static function clearSession(): void
    {
        self::sessionSet(null);
    }

    /** @return array<string, mixed>|null */
    private static function sessionGet(): ?array
    {
        if (!function_exists('WC') || !WC()->session) {
            return null;
        }
        $raw = WC()->session->get(self::SESSION_KEY);
        return is_array($raw) ? $raw : null;
    }

    /** @param array<string, mixed>|null $state */
    private static function sessionSet(?array $state): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        if ($state === null) {
            WC()->session->set(self::SESSION_KEY, null);
            return;
        }
        WC()->session->set(self::SESSION_KEY, $state);
    }

    private static function fingerprint(string $tckno, int $birthYear, string $firstName, string $lastName): string
    {
        return hash_hmac(
            'sha256',
            $tckno . '|' . $birthYear . '|' . $firstName . '|' . $lastName,
            wp_salt('auth')
        );
    }

    private static function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return '';
        }

        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($name, 'UTF-8');
        }

        return strtoupper($name);
    }

    public static function digits(string $raw): string
    {
        return preg_replace('/\D/', '', $raw) ?? '';
    }
}
