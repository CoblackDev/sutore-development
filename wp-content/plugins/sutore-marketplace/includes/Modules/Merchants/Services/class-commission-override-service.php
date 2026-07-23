<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Repositories\CommissionOverrideRepository;
use SutoreMarketplace\Modules\Merchants\Repositories\MerchantEventsRepository;

final class CommissionOverrideService
{
    public function __construct(
        private readonly CommissionOverrideRepository $repo = new CommissionOverrideRepository(),
        private readonly MerchantEventsRepository $events = new MerchantEventsRepository(),
    ) {
    }

    /**
     * @param array{commission_percent:float,expires_at?:?string,note?:string,source?:string,task_id?:int,reward_id?:int,created_by?:int} $input
     * @return array{id:int}| \WP_Error
     */
    public function create(int $merchantId, array $input): array|\WP_Error
    {
        if ($merchantId <= 0) {
            return new \WP_Error('sutore_merchant_not_found', __('Seller not found.', 'sutore-marketplace'), ['status' => 404]);
        }

        $percent = round((float) ($input['commission_percent'] ?? -1), 2);
        if ($percent < 0 || $percent > 100) {
            return new \WP_Error(
                'sutore_commission_invalid',
                __('Commission percent must be between 0 and 100.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $expiresAt = null;
        if (!empty($input['expires_at'])) {
            $raw = str_replace('T', ' ', sanitize_text_field((string) $input['expires_at']));
            $raw = trim($raw);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
                $raw .= ':00';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
                $expiresAt = $raw;
            }
        }

        $source = sanitize_key((string) ($input['source'] ?? 'staff'));
        if (!in_array($source, ['staff', 'task'], true)) {
            $source = 'staff';
        }

        $id = $this->repo->create([
            'merchant_id' => $merchantId,
            'commission_percent' => $percent,
            'is_active' => 1,
            'expires_at' => $expiresAt,
            'source' => $source,
            'task_id' => !empty($input['task_id']) ? (int) $input['task_id'] : null,
            'reward_id' => !empty($input['reward_id']) ? (int) $input['reward_id'] : null,
            'note' => sanitize_textarea_field((string) ($input['note'] ?? '')),
            'created_by' => (int) ($input['created_by'] ?? get_current_user_id()),
        ]);

        $this->events->log($merchantId, 'merchant_commission_override_set', [
            'actor_role' => $source === 'task' ? 'system' : 'staff',
            'override_id' => $id,
            'commission_percent' => $percent,
            'expires_at' => $expiresAt,
            'source' => $source,
        ]);

        return ['id' => $id];
    }

    public function delete(int $overrideId): array|\WP_Error
    {
        $row = $this->repo->find($overrideId);
        if ($row === null) {
            return new \WP_Error(
                'sutore_override_not_found',
                __('Commission override not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $this->repo->delete($overrideId);
        $this->events->log((int) $row->merchant_id, 'merchant_commission_override_deleted', [
            'actor_role' => 'staff',
            'override_id' => $overrideId,
            'commission_percent' => (float) $row->commission_percent,
            'source' => (string) ($row->source ?? ''),
        ]);

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
            'expires_at' => $expiresAt,
            'source' => 'task',
            'task_id' => $taskId,
            'reward_id' => $rewardId,
            'created_by' => 0,
            'note' => __('Task reward commission discount', 'sutore-marketplace'),
        ]);
    }
}
