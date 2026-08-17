<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Services;

use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignOfferRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService;
use SutoreMarketplace\Modules\Merchants\Settings\BehaviorSettings;
use SutoreMarketplace\Modules\Tasks\Domain\OpportunityCardFamily;
use SutoreMarketplace\Modules\Tasks\Domain\OpportunityTemplate;
use SutoreMarketplace\Modules\Tasks\Repositories\TasksRepository;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class OpportunityCardService
{
    public function __construct(
        private readonly TasksRepository $tasks = new TasksRepository(),
        private readonly BehaviorScoreService $scores = new BehaviorScoreService(),
    ) {
    }

    public function ensureSystemTemplates(): void
    {
        $templates = [
            [
                'task_key' => OpportunityTemplate::GROWTH_MONTHLY_SALES,
                'template_key' => OpportunityTemplate::GROWTH_MONTHLY_SALES,
                'card_family' => OpportunityCardFamily::GROWTH,
                'title' => OpportunityTemplate::label(OpportunityTemplate::GROWTH_MONTHLY_SALES),
                'description' => __('Reach monthly sales tiers to unlock a temporary commission discount next month.', 'sutore-marketplace'),
                'target_count' => max(BehaviorSettings::growthSalesTiers()),
                'reward_type' => 'commission_percent',
                'reward_value' => 0,
                'reward_duration_days' => 30,
                'is_template' => 1,
            ],
            [
                'task_key' => OpportunityTemplate::RECOVERY_TIMELY_CONFIRM,
                'template_key' => OpportunityTemplate::RECOVERY_TIMELY_CONFIRM,
                'card_family' => OpportunityCardFamily::RECOVERY,
                'title' => OpportunityTemplate::label(OpportunityTemplate::RECOVERY_TIMELY_CONFIRM),
                'description' => __('Confirm your next sales before the deadline to recover your behavior score.', 'sutore-marketplace'),
                'target_count' => BehaviorSettings::recoveryConfirmTarget(),
                'reward_type' => 'none',
                'reward_value' => 0,
                'reward_duration_days' => 0,
                'is_template' => 1,
            ],
            [
                'task_key' => OpportunityTemplate::ENGAGEMENT_SOURCING,
                'template_key' => OpportunityTemplate::ENGAGEMENT_SOURCING,
                'card_family' => OpportunityCardFamily::ENGAGEMENT,
                'title' => OpportunityTemplate::label(OpportunityTemplate::ENGAGEMENT_SOURCING),
                'description' => __('Accept a matching pre-order request from the board.', 'sutore-marketplace'),
                'target_count' => 1,
                'reward_type' => 'none',
                'reward_value' => 0,
                'reward_duration_days' => 0,
                'is_template' => 1,
            ],
            [
                'task_key' => OpportunityTemplate::ENGAGEMENT_CAMPAIGN,
                'template_key' => OpportunityTemplate::ENGAGEMENT_CAMPAIGN,
                'card_family' => OpportunityCardFamily::ENGAGEMENT,
                'title' => OpportunityTemplate::label(OpportunityTemplate::ENGAGEMENT_CAMPAIGN),
                'description' => __('Accept a campaign offer to unlock better visibility.', 'sutore-marketplace'),
                'target_count' => 1,
                'reward_type' => 'none',
                'reward_value' => 0,
                'reward_duration_days' => 0,
                'is_template' => 1,
            ],
        ];

        foreach ($templates as $tpl) {
            $existing = $this->tasks->findByTaskKey($tpl['task_key']);
            if ($existing) {
                continue;
            }
            $this->tasks->saveDefinition(array_merge($tpl, [
                'template_params' => wp_json_encode([
                    'tiers' => BehaviorSettings::growthSalesTiers(),
                    'rewards' => BehaviorSettings::growthCommissionRewards(),
                ]),
                'period_key' => '',
                'merchant_id' => 0,
            ]));
        }

        $this->tasks->deactivateLegacyTasks();
    }

    public function generateForMerchant(int $merchantId, ?string $periodKey = null): void
    {
        $periodKey = $periodKey ?: BehaviorSettings::currentPeriodKey();
        $this->tasks->deactivateMerchantPeriodCards($merchantId, $periodKey);

        $level = MerchantLevels::statusForUser($merchantId);
        $score = $this->scores->scoreForMerchant($merchantId);

        if ($score < BehaviorSettings::confirmedMinScore()) {
            $this->spawnCard($merchantId, OpportunityTemplate::RECOVERY_TIMELY_CONFIRM, $periodKey, [
                'target' => BehaviorSettings::recoveryConfirmTarget(),
            ]);
        }

        if (in_array($level, [MerchantLevels::VERIFIED, MerchantLevels::PREMIUM], true)) {
            $tiers = BehaviorSettings::growthSalesTiers();
            $rewards = BehaviorSettings::growthCommissionRewards();
            $this->spawnCard($merchantId, OpportunityTemplate::GROWTH_MONTHLY_SALES, $periodKey, [
                'tiers' => $tiers,
                'rewards' => $rewards,
                'target' => max($tiers),
            ]);
        }

        if (ListingPolicy::canAccessSourcingBoard($merchantId)) {
            $preOrders = (new ListingRepository())->query([
                'status' => ListingStatus::PRE_ORDER,
                'page' => 1,
                'per_page' => 1,
            ]);
            if (($preOrders['total'] ?? 0) > 0) {
                $this->spawnCard($merchantId, OpportunityTemplate::ENGAGEMENT_SOURCING, $periodKey, []);
            }
        }

        $pendingOffers = (new CampaignOfferRepository())->countForMerchant($merchantId, 'pending');
        if ($pendingOffers > 0) {
            $this->spawnCard($merchantId, OpportunityTemplate::ENGAGEMENT_CAMPAIGN, $periodKey, []);
        }
    }

    public function generateForAllMerchants(?string $periodKey = null): void
    {
        $periodKey = $periodKey ?: BehaviorSettings::currentPeriodKey();
        $merchantIds = get_users([
            'role' => 'merchant',
            'fields' => 'ID',
        ]);

        foreach ($merchantIds as $userId) {
            $this->generateForMerchant((int) $userId, $periodKey);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function spawnCard(int $merchantId, string $templateKey, string $periodKey, array $params): void
    {
        $template = $this->tasks->findTemplateByKey($templateKey);
        if (!$template) {
            return;
        }

        $taskKey = $templateKey . '_' . $periodKey . '_m' . $merchantId;
        $target = (int) ($params['target'] ?? $template->target_count ?? 1);
        $family = OpportunityTemplate::familyFor($templateKey);
        $title = $this->titleForCard($templateKey, $params);
        $description = $this->descriptionForCard($templateKey, $params);

        $rewardType = (string) ($template->reward_type ?? 'none');
        $rewardValue = (float) ($template->reward_value ?? 0);
        if ($templateKey === OpportunityTemplate::GROWTH_MONTHLY_SALES) {
            $tiers = (array) ($params['tiers'] ?? BehaviorSettings::growthSalesTiers());
            $rewards = (array) ($params['rewards'] ?? BehaviorSettings::growthCommissionRewards());
            $rewardValue = (float) ($rewards[0] ?? 11.0);
            $target = max($tiers ?: [1]);
            $rewardType = 'commission_percent';
        }

        $this->tasks->saveDefinition([
            'task_key' => $taskKey,
            'title' => $title,
            'description' => $description,
            'target_count' => max(1, $target),
            'reward_type' => $rewardType,
            'reward_value' => $rewardValue,
            'reward_duration_days' => (int) ($template->reward_duration_days ?? 30),
            'card_family' => $family,
            'template_key' => $templateKey,
            'template_params' => wp_json_encode($params),
            'period_key' => $periodKey,
            'merchant_id' => $merchantId,
            'is_template' => 0,
            'is_active' => 1,
        ]);
    }

    /** @param array<string, mixed> $params */
    private function titleForCard(string $templateKey, array $params): string
    {
        return match ($templateKey) {
            OpportunityTemplate::GROWTH_MONTHLY_SALES => __('Grow this month', 'sutore-marketplace'),
            OpportunityTemplate::RECOVERY_TIMELY_CONFIRM => __('Recover your score', 'sutore-marketplace'),
            OpportunityTemplate::ENGAGEMENT_SOURCING => __('Bring a pre-order', 'sutore-marketplace'),
            OpportunityTemplate::ENGAGEMENT_CAMPAIGN => __('Join a campaign', 'sutore-marketplace'),
            default => OpportunityTemplate::label($templateKey),
        };
    }

    /** @param array<string, mixed> $params */
    private function descriptionForCard(string $templateKey, array $params): string
    {
        if ($templateKey === OpportunityTemplate::GROWTH_MONTHLY_SALES) {
            $tiers = (array) ($params['tiers'] ?? BehaviorSettings::growthSalesTiers());
            $rewards = (array) ($params['rewards'] ?? BehaviorSettings::growthCommissionRewards());
            $parts = [];
            foreach ($tiers as $i => $tier) {
                $pct = $rewards[$i] ?? ($rewards[0] ?? 11);
                $parts[] = sprintf(
                    /* translators: 1: sales count, 2: commission percent */
                    __('%1$d sales → %2$s%% commission next month', 'sutore-marketplace'),
                    (int) $tier,
                    (string) $pct
                );
            }

            return implode(' · ', $parts);
        }

        return match ($templateKey) {
            OpportunityTemplate::RECOVERY_TIMELY_CONFIRM => sprintf(
                /* translators: %d: number of on-time confirmations required */
                __('Confirm your next %d sales before the deadline to improve your score.', 'sutore-marketplace'),
                BehaviorSettings::recoveryConfirmTarget()
            ),
            OpportunityTemplate::ENGAGEMENT_SOURCING => __('Accept a pre-order that matches your inventory.', 'sutore-marketplace'),
            OpportunityTemplate::ENGAGEMENT_CAMPAIGN => __('Accept a pending campaign offer.', 'sutore-marketplace'),
            default => '',
        };
    }
}
