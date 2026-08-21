<?php

declare(strict_types=1);

namespace SutoreMarketplace\Admin;

use SutoreMarketplace\Modules\Listings\Domain\ListingEventType;
use SutoreMarketplace\Modules\Merchants\Settings\BehaviorSettings;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Shared\Settings\Settings;

final class BehaviorSettingsSection
{
    /** @param array<string, mixed> $settings */
    public function render(array $settings): void
    {
        $behavior = is_array($settings['behavior'] ?? null)
            ? $settings['behavior']
            : BehaviorSettings::defaults();
        $defaults = BehaviorSettings::defaults();
        $levels = is_array($settings['merchant_levels'] ?? null)
            ? $settings['merchant_levels']
            : Settings::defaults()['merchant_levels'];

        echo '<p class="description">' . esc_html__(
            'Score is computed daily from product events in the score window. Event weights, thresholds, and protection rules are applied by cron and REST surfaces.',
            'sutore-marketplace'
        ) . '</p>';

        $this->renderScoreSection($behavior, $defaults);
        $this->renderEventWeightsSection($behavior, $defaults);
        $this->renderProtectionSection($behavior, $defaults);
        $this->renderConfirmedSection($behavior, $defaults);
        $this->renderSuperSection($behavior, $defaults);
        $this->renderOpportunitySection($behavior, $defaults);
        $this->renderCommissionSection($levels);
    }

    /** @return array<string, mixed> */
    public function buildSavePatch(): array
    {
        $tierInput = sanitize_text_field((string) ($_POST['behavior_growth_sales_tiers'] ?? '3,5,8'));
        $rewardInput = sanitize_text_field((string) ($_POST['behavior_growth_commission_rewards'] ?? '11,10,9'));
        $tiers = $this->parseIntList($tierInput);
        $rewards = $this->parseFloatList($rewardInput);

        if ($tiers === []) {
            $tiers = [3, 5, 8];
        }
        if ($rewards === []) {
            $rewards = [11.0, 10.0, 9.0];
        }
        while (count($rewards) < count($tiers)) {
            $rewards[] = end($rewards) ?: 11.0;
        }
        $rewards = array_slice($rewards, 0, count($tiers));

        $eventWeights = [];
        foreach (BehaviorSettings::defaultEventWeights() as $eventType => $defaultWeight) {
            $field = 'behavior_event_weight_' . sanitize_key($eventType);
            $raw = $_POST[$field] ?? $defaultWeight;
            $eventWeights[$eventType] = round((float) $raw, 3);
        }

        $behavior = [
            'score_window_days' => max(7, (int) ($_POST['behavior_score_window_days'] ?? 90)),
            'asking_reference' => max(1.0, (float) ($_POST['behavior_asking_reference'] ?? 10000)),
            'confirmed_min_score' => $this->clampScore((float) ($_POST['behavior_confirmed_min_score'] ?? 3.5)),
            'confirmed_min_sales' => max(0, (int) ($_POST['behavior_confirmed_min_sales'] ?? 1)),
            'sourcing_min_score' => $this->clampScore((float) ($_POST['behavior_sourcing_min_score'] ?? 4.0)),
            'premium_min_score' => $this->clampScore((float) ($_POST['behavior_premium_min_score'] ?? 4.5)),
            'premium_monthly_min_sales' => max(1, (int) ($_POST['behavior_premium_monthly_min_sales'] ?? 5)),
            'premium_monthly_min_revenue' => max(0.0, (float) ($_POST['behavior_premium_monthly_min_revenue'] ?? 50000)),
            'sourcing_early_access_hours' => max(0, (int) ($_POST['behavior_sourcing_early_access_hours'] ?? 24)),
            'new_seller_protection_deliveries' => max(0, (int) ($_POST['behavior_new_seller_protection_deliveries'] ?? 3)),
            'new_seller_protection_days' => max(0, (int) ($_POST['behavior_new_seller_protection_days'] ?? 30)),
            'shadow_mode_weeks' => max(0, (int) ($_POST['behavior_shadow_mode_weeks'] ?? 4)),
            'shadow_mode_enabled' => !empty($_POST['behavior_shadow_mode_enabled']),
            'event_weights' => $eventWeights,
            'growth_sales_tiers' => $tiers,
            'growth_commission_rewards' => $rewards,
            'recovery_confirm_target' => max(1, (int) ($_POST['behavior_recovery_confirm_target'] ?? 3)),
        ];

        $levelInput = is_array($_POST['merchant_levels_commission'] ?? null)
            ? $_POST['merchant_levels_commission']
            : [];
        $merchantLevels = [];
        foreach (Settings::defaults()['merchant_levels'] as $level => $config) {
            $merchantLevels[$level] = [
                'commission_percent' => max(0.0, min(100.0, (float) ($levelInput[$level] ?? $config['commission_percent']))),
            ];
        }

        return [
            'behavior' => $behavior,
            'merchant_levels' => $merchantLevels,
        ];
    }

    /** @param array<string, mixed> $behavior @param array<string, mixed> $defaults */
    private function renderScoreSection(array $behavior, array $defaults): void
    {
        echo '<h2>' . esc_html__('Behavior score', 'sutore-marketplace') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        $this->numberRow(
            'behavior_score_window_days',
            __('Score window (days)', 'sutore-marketplace'),
            (int) ($behavior['score_window_days'] ?? $defaults['score_window_days']),
            '1',
            __('How many days of product events are included in the 1–5 score.', 'sutore-marketplace')
        );
        $this->numberRow(
            'behavior_asking_reference',
            __('Price reference (TL)', 'sutore-marketplace'),
            (float) ($behavior['asking_reference'] ?? $defaults['asking_reference']),
            '1',
            __('Amount weighting uses price / reference, clamped between 0.5× and 2×.', 'sutore-marketplace')
        );
        $this->numberRow(
            'behavior_sourcing_min_score',
            __('Pre-order board minimum score', 'sutore-marketplace'),
            (float) ($behavior['sourcing_min_score'] ?? $defaults['sourcing_min_score']),
            '0.1',
            __('Default: 4.0. Confirmed/Super sellers below this score cannot open the board once sanctions apply.', 'sutore-marketplace')
        );

        echo '</tbody></table>';
    }

    /** @param array<string, mixed> $behavior @param array<string, mixed> $defaults */
    private function renderEventWeightsSection(array $behavior, array $defaults): void
    {
        $weights = is_array($behavior['event_weights'] ?? null)
            ? array_merge(BehaviorSettings::defaultEventWeights(), $behavior['event_weights'])
            : BehaviorSettings::defaultEventWeights();

        echo '<h2>' . esc_html__('Event weights', 'sutore-marketplace') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'Each scorable product event adds weight × amount factor to the score (starting from 5.0). Negative values lower the score.',
            'sutore-marketplace'
        ) . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        foreach ($weights as $eventType => $weight) {
            $field = 'behavior_event_weight_' . sanitize_key((string) $eventType);
            $label = ListingEventType::label((string) $eventType);
            echo '<tr><th scope="row"><label for="' . esc_attr($field) . '">' . esc_html($label) . '</label></th><td>';
            printf(
                '<input name="%1$s" type="number" id="%1$s" value="%2$s" class="small-text" step="0.01" />',
                esc_attr($field),
                esc_attr((string) $weight)
            );
            echo '<p class="description"><code>' . esc_html((string) $eventType) . '</code></p>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /** @param array<string, mixed> $behavior @param array<string, mixed> $defaults */
    private function renderProtectionSection(array $behavior, array $defaults): void
    {
        echo '<h2>' . esc_html__('New seller protection & shadow mode', 'sutore-marketplace') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        $this->numberRow(
            'behavior_new_seller_protection_deliveries',
            __('Protection: minimum deliveries', 'sutore-marketplace'),
            (int) ($behavior['new_seller_protection_deliveries'] ?? $defaults['new_seller_protection_deliveries']),
            '1',
            __('Score stays hidden and level sanctions are paused until this many deliveries are completed. 0 = disabled.', 'sutore-marketplace')
        );
        $this->numberRow(
            'behavior_new_seller_protection_days',
            __('Protection: minimum account age (days)', 'sutore-marketplace'),
            (int) ($behavior['new_seller_protection_days'] ?? $defaults['new_seller_protection_days']),
            '1',
            __('Score stays hidden until the merchant profile is at least this many days old. 0 = disabled.', 'sutore-marketplace')
        );
        $this->numberRow(
            'behavior_shadow_mode_weeks',
            __('Shadow mode duration (weeks)', 'sutore-marketplace'),
            (int) ($behavior['shadow_mode_weeks'] ?? $defaults['shadow_mode_weeks']),
            '1',
            __('When shadow mode is enabled, score is computed but hidden for this many weeks after profile creation. After the window ends, sanctions and the pre-order score gate apply normally.', 'sutore-marketplace')
        );

        $shadowEnabled = !empty($behavior['shadow_mode_enabled'] ?? $defaults['shadow_mode_enabled']);
        echo '<tr><th scope="row">' . esc_html__('Shadow mode enabled', 'sutore-marketplace') . '</th><td>';
        printf(
            '<label><input type="checkbox" name="behavior_shadow_mode_enabled" value="1" %s /> %s</label>',
            checked($shadowEnabled, true, false),
            esc_html__('Compute score silently for new sellers — no display, no sanctions, no level changes.', 'sutore-marketplace')
        );
        echo '</td></tr>';

        echo '</tbody></table>';
    }

    /** @param array<string, mixed> $behavior @param array<string, mixed> $defaults */
    private function renderConfirmedSection(array $behavior, array $defaults): void
    {
        echo '<h2>' . esc_html__('Confirmed level', 'sutore-marketplace') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'Earned once when TC is verified, minimum sales are reached, and score meets threshold. Dropped back to New if score falls below threshold.',
            'sutore-marketplace'
        ) . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        $this->numberRow(
            'behavior_confirmed_min_score',
            __('Minimum score', 'sutore-marketplace'),
            (float) ($behavior['confirmed_min_score'] ?? $defaults['confirmed_min_score']),
            '0.1',
            __('Default: 3.5', 'sutore-marketplace')
        );
        $this->numberRow(
            'behavior_confirmed_min_sales',
            __('Minimum lifetime sales', 'sutore-marketplace'),
            (int) ($behavior['confirmed_min_sales'] ?? $defaults['confirmed_min_sales']),
            '1',
            __('Sales required before Confirmed can be granted.', 'sutore-marketplace')
        );

        echo '</tbody></table>';
    }

    /** @param array<string, mixed> $behavior @param array<string, mixed> $defaults */
    private function renderSuperSection(array $behavior, array $defaults): void
    {
        echo '<h2>' . esc_html__('Super level', 'sutore-marketplace') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'Re-evaluated monthly from the previous calendar month. Super sellers see pre-orders immediately; Confirmed sellers wait for the early-access window.',
            'sutore-marketplace'
        ) . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        $this->numberRow(
            'behavior_premium_min_score',
            __('Minimum score', 'sutore-marketplace'),
            (float) ($behavior['premium_min_score'] ?? $defaults['premium_min_score']),
            '0.1',
            __('Default: 4.5', 'sutore-marketplace')
        );
        $this->numberRow(
            'behavior_premium_monthly_min_sales',
            __('Previous month minimum sales', 'sutore-marketplace'),
            (int) ($behavior['premium_monthly_min_sales'] ?? $defaults['premium_monthly_min_sales']),
            '1',
            ''
        );
        $this->numberRow(
            'behavior_premium_monthly_min_revenue',
            __('Previous month minimum revenue (price TL)', 'sutore-marketplace'),
            (float) ($behavior['premium_monthly_min_revenue'] ?? $defaults['premium_monthly_min_revenue']),
            '1',
            ''
        );
        $this->numberRow(
            'behavior_sourcing_early_access_hours',
            __('Pre-order early access (hours)', 'sutore-marketplace'),
            (int) ($behavior['sourcing_early_access_hours'] ?? $defaults['sourcing_early_access_hours']),
            '1',
            __('Super sees board items immediately; Confirmed sees them after this many hours. 0 = no delay.', 'sutore-marketplace')
        );

        echo '</tbody></table>';
    }

    /** @param array<string, mixed> $behavior @param array<string, mixed> $defaults */
    private function renderOpportunitySection(array $behavior, array $defaults): void
    {
        $tiers = BehaviorSettings::growthSalesTiers();
        $rewards = BehaviorSettings::growthCommissionRewards();

        echo '<h2>' . esc_html__('Opportunity cards', 'sutore-marketplace') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="behavior_growth_sales_tiers">' . esc_html__('Growth sales tiers', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="behavior_growth_sales_tiers" type="text" id="behavior_growth_sales_tiers" value="%s" class="regular-text" />',
            esc_attr(implode(', ', $tiers))
        );
        echo '<p class="description">' . esc_html__('Comma-separated monthly sales targets (e.g. 3, 5, 8).', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="behavior_growth_commission_rewards">' . esc_html__('Growth commission rewards (%)', 'sutore-marketplace') . '</label></th><td>';
        printf(
            '<input name="behavior_growth_commission_rewards" type="text" id="behavior_growth_commission_rewards" value="%s" class="regular-text" />',
            esc_attr(implode(', ', array_map(static fn ($v) => (string) $v, $rewards)))
        );
        echo '<p class="description">' . esc_html__('Matching commission override % for each tier, applied next month when a tier is completed.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';

        $this->numberRow(
            'behavior_recovery_confirm_target',
            __('Recovery card: on-time confirmations', 'sutore-marketplace'),
            (int) ($behavior['recovery_confirm_target'] ?? $defaults['recovery_confirm_target']),
            '1',
            __('How many on-time confirmations complete the recovery card.', 'sutore-marketplace')
        );

        echo '</tbody></table>';
    }

    /** @param array<string, mixed> $levels */
    private function renderCommissionSection(array $levels): void
    {
        echo '<h2>' . esc_html__('Commission by seller level', 'sutore-marketplace') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'Base commission percent before temporary overrides from growth cards or staff.',
            'sutore-marketplace'
        ) . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        foreach ([MerchantLevels::NORMAL, MerchantLevels::VERIFIED, MerchantLevels::PREMIUM] as $level) {
            $value = (float) ($levels[$level]['commission_percent'] ?? Settings::defaults()['merchant_levels'][$level]['commission_percent']);
            $label = MerchantLevels::labelForStatus($level);
            echo '<tr><th scope="row"><label for="merchant_levels_commission_' . esc_attr($level) . '">' . esc_html($label) . '</label></th><td>';
            printf(
                '<input name="merchant_levels_commission[%1$s]" type="number" id="merchant_levels_commission_%1$s" value="%2$s" class="small-text" min="0" max="100" step="0.01" /> %%',
                esc_attr($level),
                esc_attr((string) $value)
            );
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    private function numberRow(string $name, string $label, int|float $value, string $step, string $description): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        printf(
            '<input name="%1$s" type="number" id="%1$s" value="%2$s" class="regular-text" min="0" step="%3$s" />',
            esc_attr($name),
            esc_attr((string) $value),
            esc_attr($step)
        );
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private function clampScore(float $value): float
    {
        return round(max(1.0, min(5.0, $value)), 2);
    }

    /** @return list<int> */
    private function parseIntList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $out[] = max(1, (int) $part);
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /** @return list<float> */
    private function parseFloatList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $out[] = max(0.0, (float) $part);
        }

        return $out;
    }
}
