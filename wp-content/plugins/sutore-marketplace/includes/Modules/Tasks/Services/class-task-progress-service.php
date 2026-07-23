<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Services;

use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Services\CommissionOverrideService;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Tasks\Repositories\TaskProgressRepository;
use SutoreMarketplace\Modules\Tasks\Repositories\TasksRepository;

final class TaskProgressService
{
    public function __construct(
        private readonly TasksRepository $tasks = new TasksRepository(),
        private readonly TaskProgressRepository $progress = new TaskProgressRepository(),
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
    ) {
    }

    public function increment(int $merchantId, string $taskKey, int $by = 1): array
    {
        $defs = $this->tasks->allDefinitions(true);
        $def = null;
        foreach ($defs as $row) {
            if ($row->task_key === $taskKey) {
                $def = $row;
                break;
            }
        }
        if (!$def) {
            return ['ok' => false, 'reason' => 'task_not_found'];
        }

        $existing = $this->progress->find($merchantId, (int) $def->id);
        $now = current_time('mysql');

        if (!$existing) {
            $this->progress->create($merchantId, (int) $def->id, $by);
            $count = $by;
            $status = 'in_progress';
        } else {
            if ($existing->status === 'completed') {
                return ['ok' => true, 'status' => 'completed', 'progress' => (int) $existing->progress_count];
            }
            $count = (int) $existing->progress_count + $by;
            $status = $count >= (int) $def->target_count ? 'completed' : 'in_progress';
            $this->progress->update((int) $existing->id, [
                'progress_count' => $count,
                'status' => $status,
                'completed_at' => $status === 'completed' ? $now : null,
            ]);
        }

        if ($status === 'completed') {
            $rewardId = $this->tasks->addReward([
                'merchant_id' => $merchantId,
                'task_id' => (int) $def->id,
                'reward_type' => $def->reward_type,
                'reward_value' => $def->reward_value,
                'note' => sprintf(__('Task reward: %s', 'sutore-marketplace'), $def->task_key),
            ]);
            if ((string) $def->reward_type === 'commission_percent') {
                (new CommissionOverrideService())->createFromTaskReward(
                    $merchantId,
                    (float) $def->reward_value,
                    max(0, (int) ($def->reward_duration_days ?? 0)),
                    (int) $def->id,
                    $rewardId
                );
            }
            $this->events->log('task_completed', [
                'task_key' => $taskKey,
                'merchant_id' => $merchantId,
            ], null, null, $merchantId, 'merchant_visible');
            (new NotificationService())->dispatch($merchantId, NotificationType::TASK_COMPLETED, [
                'task_key' => $taskKey,
                'task_id' => (int) $def->id,
                'task_title' => (string) $def->title,
            ]);
        }

        return ['ok' => true, 'status' => $status, 'progress' => $count, 'target' => (int) $def->target_count];
    }

    public function progressForMerchant(int $merchantId): array
    {
        return $this->progress->progressWithDefinitionsForMerchant($merchantId);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function saveDefinition(array $params): void
    {
        $rewardType = sanitize_key((string) ($params['reward_type'] ?? 'points'));
        if (!in_array($rewardType, ['points', 'badge', 'commission_percent'], true)) {
            $rewardType = 'points';
        }

        $this->tasks->saveDefinition([
            'task_key' => sanitize_key((string) ($params['task_key'] ?? '')),
            'title' => sanitize_text_field((string) ($params['title'] ?? '')),
            'description' => sanitize_textarea_field((string) ($params['description'] ?? '')),
            'target_count' => max(1, (int) ($params['target_count'] ?? 1)),
            'reward_type' => $rewardType,
            'reward_value' => (float) ($params['reward_value'] ?? 0),
            'reward_duration_days' => max(0, (int) ($params['reward_duration_days'] ?? 0)),
            'is_active' => 1,
        ]);
    }

    /**
     * @return array{tasks: list<array<string, mixed>>, rewards: list<object>}
     */
    public function dashboardForMerchant(int $merchantId): array
    {
        $defs = $this->tasks->allDefinitions(true);
        $progressRows = $this->progressForMerchant($merchantId);
        $byTaskId = [];
        foreach ($progressRows as $row) {
            $byTaskId[(int) $row->task_id] = $row;
        }

        $tasks = [];
        foreach ($defs as $def) {
            $progress = $byTaskId[(int) $def->id] ?? null;
            $count = $progress ? (int) $progress->progress_count : 0;
            $status = $progress ? (string) $progress->status : 'not_started';
            $tasks[] = [
                'task_id' => (int) $def->id,
                'task_key' => (string) $def->task_key,
                'title' => (string) $def->title,
                'description' => (string) ($def->description ?? ''),
                'target_count' => (int) $def->target_count,
                'progress_count' => $count,
                'status' => $status,
                'reward_type' => (string) $def->reward_type,
                'reward_value' => (float) $def->reward_value,
                'completed_at' => $progress->completed_at ?? null,
                'percent' => (int) $def->target_count > 0
                    ? min(100, (int) round(($count / (int) $def->target_count) * 100))
                    : 0,
            ];
        }

        return [
            'tasks' => $tasks,
            'rewards' => $this->tasks->rewardsForMerchant($merchantId),
        ];
    }
}
