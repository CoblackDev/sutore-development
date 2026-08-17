<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Domain\CommissionAdjustment;
use SutoreMarketplace\Modules\Merchants\Domain\CommissionOverrideSource;
use SutoreMarketplace\Modules\Merchants\Repositories\CommissionOverrideRepository;
use SutoreMarketplace\Modules\Merchants\Repositories\MerchantEventsRepository;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class CommissionOverrideService
{
    public const PLATFORM_MERCHANT_ID = 0;

    public function __construct(
        private readonly CommissionOverrideRepository $repo = new CommissionOverrideRepository(),
        private readonly MerchantEventsRepository $events = new MerchantEventsRepository(),
    ) {
    }

    /**
     * @param array{
     *   commission_percent?:float,
     *   adjustment?:string,
     *   starts_at?:?string,
     *   expires_at?:?string,
     *   note?:string,
     *   source?:string,
     *   task_id?:int,
     *   reward_id?:int,
     *   created_by?:int
     * } $input
     * @return array{id:int,raises_level:bool}| \WP_Error
     */
    public function create(int $merchantId, array $input): array|\WP_Error
    {
        if ($merchantId < 0) {
            return new \WP_Error('sutore_merchant_not_found', __('Seller not found.', 'sutore-marketplace'), ['status' => 404]);
        }

        $adjustment = sanitize_key((string) ($input['adjustment'] ?? CommissionAdjustment::ABSOLUTE));
        if (!CommissionAdjustment::isValid($adjustment)) {
            $adjustment = CommissionAdjustment::ABSOLUTE;
        }

        $percent = round((float) ($input['commission_percent'] ?? -1), 2);
        if ($percent < 0 || $percent > 100) {
            return new \WP_Error(
                'sutore_commission_invalid',
                __('Commission percent must be between 0 and 100.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $startsAt = $this->parseDatetime($input['starts_at'] ?? null);
        $expiresAt = $this->parseDatetime($input['expires_at'] ?? null);
        if ($startsAt !== null && $expiresAt !== null && $startsAt >= $expiresAt) {
            return new \WP_Error(
                'sutore_commission_window',
                __('Start date must be before the end date.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $source = sanitize_key((string) ($input['source'] ?? ($merchantId === self::PLATFORM_MERCHANT_ID ? CommissionOverrideSource::CAMPAIGN : CommissionOverrideSource::STAFF)));
        if (!CommissionOverrideSource::isValid($source)) {
            $source = $merchantId === self::PLATFORM_MERCHANT_ID ? CommissionOverrideSource::CAMPAIGN : CommissionOverrideSource::STAFF;
        }

        $id = $this->repo->create([
            'merchant_id' => $merchantId,
            'commission_percent' => $percent,
            'adjustment' => $adjustment,
            'is_active' => 1,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'source' => $source,
            'task_id' => !empty($input['task_id']) ? (int) $input['task_id'] : null,
            'reward_id' => !empty($input['reward_id']) ? (int) $input['reward_id'] : null,
            'note' => sanitize_textarea_field((string) ($input['note'] ?? '')),
            'created_by' => (int) ($input['created_by'] ?? get_current_user_id()),
        ]);

        $levelPercent = $merchantId > 0 ? MerchantLevels::levelCommissionPercentForUser($merchantId) : 0.0;
        $effective = CommissionAdjustment::apply($levelPercent, $adjustment, $percent);
        $raisesLevel = $merchantId > 0 && $effective > $levelPercent + 0.001;

        if ($merchantId > 0) {
            $this->events->log($merchantId, 'merchant_commission_override_set', [
                'actor_role' => CommissionOverrideSource::actorRole($source),
                'override_id' => $id,
                'commission_percent' => $percent,
                'effective_percent' => $effective,
                'adjustment' => $adjustment,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'source' => $source,
                'raises_level' => $raisesLevel,
                'is_platform' => false,
            ]);
        }

        return ['id' => $id, 'raises_level' => $raisesLevel];
    }

    public function delete(int $overrideId): array|\WP_Error
    {
        $row = $this->repo->find($overrideId);
        if ($row === null || (int) ($row->is_active ?? 1) !== 1) {
            return new \WP_Error(
                'sutore_override_not_found',
                __('Commission override not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $this->repo->deactivate($overrideId);
        $merchantId = (int) $row->merchant_id;
        if ($merchantId > 0) {
            $this->events->log($merchantId, 'merchant_commission_override_deleted', [
                'actor_role' => 'staff',
                'override_id' => $overrideId,
                'commission_percent' => (float) $row->commission_percent,
                'adjustment' => (string) ($row->adjustment ?? CommissionAdjustment::ABSOLUTE),
                'source' => (string) ($row->source ?? ''),
                'is_platform' => false,
            ]);
        }

        return ['message' => __('Commission override deleted.', 'sutore-marketplace')];
    }

    public function createFromTaskReward(
        int $merchantId,
        float $percent,
        int $durationDays,
        int $taskId,
        int $rewardId
    ): void {
        $expiresAt = null;
        if ($durationDays > 0) {
            $base = strtotime(current_time('mysql'));
            if ($base !== false) {
                $expiresAt = wp_date('Y-m-d H:i:s', $base + ($durationDays * DAY_IN_SECONDS));
            }
        }

        $this->create($merchantId, [
            'commission_percent' => $percent,
            'adjustment' => CommissionAdjustment::ABSOLUTE,
            'expires_at' => $expiresAt,
            'source' => CommissionOverrideSource::TASK,
            'task_id' => $taskId,
            'reward_id' => $rewardId,
            'created_by' => 0,
            'note' => __('Task reward commission discount', 'sutore-marketplace'),
        ]);
    }

    public function createFromReferral(
        int $merchantId,
        float $pointsOff,
        int $durationDays,
        string $note
    ): array|\WP_Error {
        $expiresAt = null;
        if ($durationDays > 0) {
            $base = strtotime(current_time('mysql'));
            if ($base !== false) {
                $expiresAt = wp_date('Y-m-d H:i:s', $base + ($durationDays * DAY_IN_SECONDS));
            }
        }

        return $this->create($merchantId, [
            'commission_percent' => $pointsOff,
            'adjustment' => CommissionAdjustment::POINTS_OFF,
            'expires_at' => $expiresAt,
            'source' => CommissionOverrideSource::REFERRAL,
            'created_by' => 0,
            'note' => $note,
        ]);
    }

    private function parseDatetime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = str_replace('T', ' ', sanitize_text_field((string) $value));
        $raw = trim($raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
            $raw .= ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
            return $raw;
        }

        return null;
    }
}
