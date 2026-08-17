<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Services;

use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService;
use SutoreMarketplace\Modules\Merchants\Services\CommissionOverrideService;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Merchants\Settings\BehaviorSettings;
use SutoreMarketplace\Modules\Tasks\Domain\OpportunityCardFamily;
use SutoreMarketplace\Modules\Tasks\Domain\OpportunityTemplate;
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

    public function incrementByTemplate(int $merchantId, string $templateKey, int $by = 1): array
    {
        $cards = $this->tasks->findActiveCardsByTemplate($merchantId, $templateKey);
        if ($cards === []) {
            return ['ok' => false, 'reason' => 'no_active_card'];
        }

        $last = ['ok' => false, 'reason' => 'no_active_card'];
        foreach ($cards as $card) {
            $last = $this->incrementForDefinition($merchantId, $card, $by);
        }

        return $last;
    }

    public function increment(int $merchantId, string $taskKey, int $by = 1): array
    {
        $def = $this->tasks->findByTaskKey($taskKey);
        if (!$def) {
            return ['ok' => false, 'reason' => 'task_not_found'];
        }

        return $this->incrementForDefinition($merchantId, $def, $by);
    }

    private function incrementForDefinition(int $merchantId, object $def, int $by = 1): array
    {
        if ((int) ($def->merchant_id ?? 0) > 0 && (int) $def->merchant_id !== $merchantId) {
            return ['ok' => false, 'reason' => 'not_owner'];
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
            $this->onCardCompleted($merchantId, $def, $count);
        }

        return [
            'ok' => true,
            'status' => $status,
            'progress' => $count,
            'target' => (int) $def->target_count,
        ];
    }

    private function onCardCompleted(int $merchantId, object $def, int $progressCount): void
    {
        $taskKey = (string) $def->task_key;
        $templateKey = (string) ($def->template_key ?? '');
        $rewardType = (string) ($def->reward_type ?? 'none');
        $rewardValue = (float) ($def->reward_value ?? 0);

        if ($templateKey === OpportunityTemplate::GROWTH_MONTHLY_SALES) {
            $rewardValue = $this->tierRewardForProgress($def, $progressCount);
            $rewardType = 'commission_percent';
        }

        $rewardId = null;
        if ($rewardType === 'commission_percent' && $rewardValue > 0) {
            $rewardId = $this->tasks->addReward([
                'merchant_id' => $merchantId,
                'task_id' => (int) $def->id,
                'reward_type' => $rewardType,
                'reward_value' => $rewardValue,
                'note' => sprintf(__('Opportunity reward: %s', 'sutore-marketplace'), $taskKey),
            ]);
            (new CommissionOverrideService())->createFromTaskReward(
                $merchantId,
                $rewardValue,
                max(0, (int) ($def->reward_duration_days ?? 0)),
                (int) $def->id,
                $rewardId
            );
        }

        $this->events->log('task_completed', [
            'task_key' => $taskKey,
            'template_key' => $templateKey,
            'merchant_id' => $merchantId,
        ], null, $merchantId, 'merchant_visible');

        (new NotificationService())->dispatch($merchantId, NotificationType::TASK_COMPLETED, [
            'task_key' => $taskKey,
            'task_id' => (int) $def->id,
            'task_title' => (string) $def->title,
        ]);

        if ($templateKey === OpportunityTemplate::RECOVERY_TIMELY_CONFIRM) {
            (new BehaviorScoreService())->refreshMerchant($merchantId);
        }
    }

    private function tierRewardForProgress(object $def, int $progressCount): float
    {
        $params = json_decode((string) ($def->template_params ?? '{}'), true);
        if (!is_array($params)) {
            $params = [];
        }
        $tiers = (array) ($params['tiers'] ?? BehaviorSettings::growthSalesTiers());
        $rewards = (array) ($params['rewards'] ?? BehaviorSettings::growthCommissionRewards());
        $best = (float) ($rewards[0] ?? 11.0);
        foreach ($tiers as $i => $tier) {
            if ($progressCount >= (int) $tier) {
                $best = (float) ($rewards[$i] ?? $best);
            }
        }

        return $best;
    }

    public function progressForMerchant(int $merchantId): array
    {
        return $this->progress->progressWithDefinitionsForMerchant($merchantId);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function saveTemplateDefinition(array $params): void
    {
        $templateKey = sanitize_key((string) ($params['template_key'] ?? ''));
        if ($templateKey === '' || !in_array($templateKey, OpportunityTemplate::adminSelectable(), true)) {
            return;
        }

        $rewardType = sanitize_key((string) ($params['reward_type'] ?? 'none'));
        if (!in_array($rewardType, ['none', 'commission_percent'], true)) {
            $rewardType = 'none';
        }

        $this->tasks->saveDefinition([
            'task_key' => $templateKey,
            'title' => sanitize_text_field((string) ($params['title'] ?? OpportunityTemplate::label($templateKey))),
            'description' => sanitize_textarea_field((string) ($params['description'] ?? '')),
            'target_count' => max(1, (int) ($params['target_count'] ?? 1)),
            'reward_type' => $rewardType,
            'reward_value' => (float) ($params['reward_value'] ?? 0),
            'reward_duration_days' => max(0, (int) ($params['reward_duration_days'] ?? 30)),
            'card_family' => OpportunityTemplate::familyFor($templateKey),
            'template_key' => $templateKey,
            'template_params' => wp_json_encode($params['template_params'] ?? []),
            'period_key' => '',
            'merchant_id' => 0,
            'is_template' => 1,
            'is_active' => 1,
        ]);
    }

    /**
     * @return array{cards: list<array<string, mixed>>, period_key: string}
     */
    public function dashboardForMerchant(int $merchantId): array
    {
        $periodKey = BehaviorSettings::currentPeriodKey();
        $defs = $this->tasks->cardsForMerchant($merchantId, $periodKey);
        if ($defs === []) {
            (new OpportunityCardService())->generateForMerchant($merchantId, $periodKey);
            $defs = $this->tasks->cardsForMerchant($merchantId, $periodKey);
        }

        $progressRows = $this->progressForMerchant($merchantId);
        $byTaskId = [];
        foreach ($progressRows as $row) {
            $byTaskId[(int) $row->task_id] = $row;
        }

        $cards = [];
        foreach ($defs as $def) {
            $progress = $byTaskId[(int) $def->id] ?? null;
            $count = $progress ? (int) $progress->progress_count : 0;
            $status = $progress ? (string) $progress->status : 'not_started';
            $family = (string) ($def->card_family ?? OpportunityCardFamily::GROWTH);
            $cards[] = [
                'task_id' => (int) $def->id,
                'task_key' => (string) $def->task_key,
                'template_key' => (string) ($def->template_key ?? ''),
                'card_family' => $family,
                'card_family_label' => OpportunityCardFamily::label($family),
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

        usort($cards, static function (array $a, array $b): int {
            $order = [
                OpportunityCardFamily::RECOVERY => 0,
                OpportunityCardFamily::GROWTH => 1,
                OpportunityCardFamily::ENGAGEMENT => 2,
            ];
            $fa = $order[$a['card_family']] ?? 9;
            $fb = $order[$b['card_family']] ?? 9;
            if ($fa !== $fb) {
                return $fa <=> $fb;
            }

            return strcmp((string) $a['title'], (string) $b['title']);
        });

        return [
            'cards' => $cards,
            'period_key' => $periodKey,
        ];
    }
}
