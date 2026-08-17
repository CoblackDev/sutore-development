<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Merchants\Domain\CommissionAdjustment;
use SutoreMarketplace\Modules\Merchants\Domain\CommissionOverrideSource;
use SutoreMarketplace\Modules\Merchants\Repositories\CommissionOverrideRepository;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class CommissionResolver
{
    public const SOURCE_LEVEL = 'level';
    public const SOURCE_LISTING = 'listing';
    public const SOURCE_OVERRIDE = 'override';

    public function __construct(
        private readonly CommissionOverrideRepository $overrides = new CommissionOverrideRepository(),
    ) {
    }

    /**
     * @return array{
     *   percent: float,
     *   level_percent: float,
     *   is_overridden: bool,
     *   raises_level: bool,
     *   source: string,
     *   starts_at: ?string,
     *   expires_at: ?string,
     *   override_id: int,
     *   listing_percent: ?float,
     *   sale_percent: ?float,
     *   active_overrides: list<array<string, mixed>>
     * }
     */
    public function forUser(int $userId): array
    {
        return $this->resolve($userId, null, null);
    }

    /**
     * Live rate for this listing (listing override > merchant/platform override > level).
     * Does not use the sale-time snapshot — that is payout-only.
     *
     * @return array{
     *   percent: float,
     *   level_percent: float,
     *   is_overridden: bool,
     *   raises_level: bool,
     *   source: string,
     *   starts_at: ?string,
     *   expires_at: ?string,
     *   override_id: int,
     *   listing_percent: ?float,
     *   sale_percent: ?float,
     *   active_overrides: list<array<string, mixed>>
     * }
     */
    public function forListing(Listing $listing): array
    {
        return $this->resolve($listing->merchantId, $listing->commissionPercent, $listing->saleCommissionPercent);
    }

    /**
     * Rate written onto the payout line: sale snapshot if present, otherwise live resolve.
     */
    public function percentForPayout(Listing $listing): float
    {
        if ($listing->saleCommissionPercent !== null) {
            return round((float) $listing->saleCommissionPercent, 2);
        }

        return $this->forListing($listing)['percent'];
    }

    /**
     * Freeze the live rate at sale start. Idempotent if already locked.
     *
     * @return array<string, mixed>
     */
    public function lockForSale(Listing $listing): array
    {
        if ($listing->saleCommissionPercent !== null) {
            return $this->forListing($listing);
        }

        $resolved = $this->forListing($listing);
        (new ListingRepository())->update($listing->variationId, [
            'sale_commission_percent' => $resolved['percent'],
        ]);

        (new ListingEventsRepository())->log('sale_commission_locked', [
            'commission_percent' => $resolved['percent'],
            'level_percent' => $resolved['level_percent'],
            'source' => $resolved['source'],
            'override_id' => $resolved['override_id'],
        ], $listing->variationId, $listing->merchantId, 'admin_only');

        return $resolved;
    }

    /**
     * Staff UI: active + scheduled overrides (not expired), including platform rows.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleOverridesForMerchant(int $merchantId): array
    {
        $levelPercent = MerchantLevels::levelCommissionPercentForUser($merchantId);
        $items = [];
        foreach ($this->overrides->visibleForMerchant($merchantId) as $row) {
            $items[] = $this->presentOverride($row, $levelPercent);
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function visiblePlatformOverrides(): array
    {
        $items = [];
        foreach ($this->overrides->visiblePlatform() as $row) {
            $items[] = $this->presentOverride($row, 0.0);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentOverride(object $row, float $levelPercent): array
    {
        $value = round((float) $row->commission_percent, 2);
        $adjustment = CommissionAdjustment::isValid((string) ($row->adjustment ?? ''))
            ? (string) $row->adjustment
            : CommissionAdjustment::ABSOLUTE;
        $effective = CommissionAdjustment::apply($levelPercent, $adjustment, $value);
        $isPlatform = (int) $row->merchant_id === 0;
        // List header has no seller level — skip "→ 0%" for relative platform rows.
        $hideEffective = $isPlatform && $adjustment !== CommissionAdjustment::ABSOLUTE && $levelPercent <= 0;
        $now = current_time('mysql');
        $startsAt = $this->nullableTime($row->starts_at ?? null);
        $expiresAt = $this->nullableTime($row->expires_at ?? null);
        $scheduled = $startsAt !== null && $startsAt > $now;

        return [
            'id' => (int) $row->id,
            'merchant_id' => (int) $row->merchant_id,
            'is_platform' => $isPlatform,
            'commission_percent' => $value,
            'adjustment' => $adjustment,
            'adjustment_label' => CommissionAdjustment::label($adjustment),
            'effective_percent' => $hideEffective ? null : $effective,
            'raises_level' => !$hideEffective && $levelPercent > 0 && $effective > $levelPercent + 0.001,
            'source' => (string) ($row->source ?? CommissionOverrideSource::STAFF),
            'source_label' => CommissionOverrideSource::label((string) ($row->source ?? CommissionOverrideSource::STAFF)),
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'scheduled' => $scheduled,
            'note' => (string) ($row->note ?? ''),
            'task_id' => isset($row->task_id) ? (int) $row->task_id : 0,
            'created_at' => (string) ($row->created_at ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(int $userId, ?float $listingPercent, ?float $salePercent): array
    {
        $levelPercent = MerchantLevels::levelCommissionPercentForUser($userId);
        $active = [];
        foreach ($this->overrides->effectiveForMerchant($userId) as $row) {
            $active[] = $this->presentOverride($row, $levelPercent);
        }
        usort(
            $active,
            static function (array $a, array $b): int {
                $ea = $a['effective_percent'] ?? PHP_FLOAT_MAX;
                $eb = $b['effective_percent'] ?? PHP_FLOAT_MAX;
                $cmp = $ea <=> $eb;

                return $cmp !== 0 ? $cmp : ($a['id'] <=> $b['id']);
            }
        );

        $base = [
            'percent' => $levelPercent,
            'level_percent' => $levelPercent,
            'is_overridden' => false,
            'raises_level' => false,
            'source' => self::SOURCE_LEVEL,
            'starts_at' => null,
            'expires_at' => null,
            'override_id' => 0,
            'listing_percent' => $listingPercent,
            'sale_percent' => $salePercent,
            'active_overrides' => $active,
        ];

        if ($listingPercent !== null) {
            $percent = round(max(0.0, min(100.0, $listingPercent)), 2);

            return array_merge($base, [
                'percent' => $percent,
                'is_overridden' => true,
                'raises_level' => $percent > $levelPercent + 0.001,
                'source' => self::SOURCE_LISTING,
            ]);
        }

        if ($active === []) {
            return $base;
        }

        $best = $active[0];

        return array_merge($base, [
            'percent' => (float) $best['effective_percent'],
            'is_overridden' => true,
            'raises_level' => (bool) $best['raises_level'],
            'source' => (string) $best['source'],
            'starts_at' => $best['starts_at'],
            'expires_at' => $best['expires_at'],
            'override_id' => (int) $best['id'],
        ]);
    }

    private function nullableTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
