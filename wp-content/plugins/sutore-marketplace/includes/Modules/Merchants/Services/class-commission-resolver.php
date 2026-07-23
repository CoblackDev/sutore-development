<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Repositories\CommissionOverrideRepository;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class CommissionResolver
{
    public function __construct(
        private readonly CommissionOverrideRepository $overrides = new CommissionOverrideRepository(),
    ) {
    }

    /**
     * @return array{
     *   percent: float,
     *   level_percent: float,
     *   is_overridden: bool,
     *   source: string,
     *   expires_at: ?string,
     *   override_id: int,
     *   active_overrides: list<array<string, mixed>>
     * }
     */
    public function forUser(int $userId): array
    {
        $levelPercent = MerchantLevels::levelCommissionPercentForUser($userId);
        $active = $this->overrides->activeForMerchant($userId);
        $items = [];
        foreach ($active as $row) {
            $items[] = [
                'id' => (int) $row->id,
                'commission_percent' => round((float) $row->commission_percent, 2),
                'source' => (string) ($row->source ?? 'staff'),
                'expires_at' => $row->expires_at !== null && (string) $row->expires_at !== ''
                    ? (string) $row->expires_at
                    : null,
                'note' => (string) ($row->note ?? ''),
                'task_id' => isset($row->task_id) ? (int) $row->task_id : 0,
                'created_at' => (string) ($row->created_at ?? ''),
            ];
        }

        if ($items === []) {
            return [
                'percent' => $levelPercent,
                'level_percent' => $levelPercent,
                'is_overridden' => false,
                'source' => 'level',
                'expires_at' => null,
                'override_id' => 0,
                'active_overrides' => [],
            ];
        }

        $best = $items[0];

        return [
            'percent' => (float) $best['commission_percent'],
            'level_percent' => $levelPercent,
            'is_overridden' => true,
            'source' => (string) $best['source'],
            'expires_at' => $best['expires_at'],
            'override_id' => (int) $best['id'],
            'active_overrides' => $items,
        ];
    }
}
