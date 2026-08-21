<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Admin;

use SutoreMarketplace\Admin\AdminAssets;
use SutoreMarketplace\Admin\StaffCapabilities;
use SutoreMarketplace\Modules\Tasks\Domain\OpportunityCardFamily;
use SutoreMarketplace\Modules\Tasks\Domain\OpportunityTemplate;
use SutoreMarketplace\Modules\Tasks\Repositories\TasksRepository;
use SutoreMarketplace\Modules\Tasks\Services\OpportunityCardService;

final class TasksPage
{
    public function render(): void
    {
        if (!StaffCapabilities::canManageOps()) {
            return;
        }

        AdminAssets::enqueue();
        (new OpportunityCardService())->ensureSystemTemplates();

        $repo = new TasksRepository();
        $defs = array_filter($repo->allDefinitions(), static fn ($row) => (int) ($row->is_template ?? 0) === 1);

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Opportunity templates', 'sutore-marketplace') . '</h1>';
        echo '<hr class="wp-header-end" />';
        echo '<p class="description">' . esc_html__('System generates personal opportunity cards from these templates each month.', 'sutore-marketplace') . '</p>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        foreach ([
            __('Template', 'sutore-marketplace'),
            __('Family', 'sutore-marketplace'),
            __('Title', 'sutore-marketplace'),
            __('Target', 'sutore-marketplace'),
            __('Reward', 'sutore-marketplace'),
            __('Duration (days)', 'sutore-marketplace'),
        ] as $h) {
            echo '<th scope="col">' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($defs as $def) {
            $duration = (int) ($def->reward_duration_days ?? 0);
            $family = (string) ($def->card_family ?? '');
            echo '<tr>';
            echo '<td><code>' . esc_html((string) ($def->template_key ?? $def->task_key)) . '</code></td>';
            echo '<td>' . esc_html(OpportunityCardFamily::label($family)) . '</td>';
            echo '<td>' . esc_html($def->title) . '</td>';
            echo '<td>' . (int) $def->target_count . '</td>';
            echo '<td>' . esc_html((string) $def->reward_type . ' / ' . (string) $def->reward_value) . '</td>';
            echo '<td>' . esc_html($duration > 0 ? (string) $duration : '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Edit template', 'sutore-marketplace') . '</h2>';
        echo '<form class="sutore-mp-admin-rest" data-rest-path="admin/tasks/definitions" data-rest-method="POST">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="template_key">' . esc_html__('Template', 'sutore-marketplace') . '</label></th><td><select name="template_key" id="template_key" required>';
        foreach (OpportunityTemplate::adminSelectable() as $key) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html(OpportunityTemplate::label($key)) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="task_title">' . esc_html__('Title', 'sutore-marketplace') . '</label></th><td><input name="title" type="text" id="task_title" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label for="task_description">' . esc_html__('Description', 'sutore-marketplace') . '</label></th><td><textarea name="description" id="task_description" class="large-text" rows="3"></textarea></td></tr>';
        echo '<tr><th scope="row"><label for="target_count">' . esc_html__('Target', 'sutore-marketplace') . '</label></th><td><input name="target_count" type="number" id="target_count" class="small-text" value="1" min="1" /></td></tr>';
        echo '<tr><th scope="row"><label for="reward_type">' . esc_html__('Reward type', 'sutore-marketplace') . '</label></th><td>';
        echo '<select name="reward_type" id="reward_type">';
        foreach ([
            'none' => __('None (behavior only)', 'sutore-marketplace'),
            'commission_percent' => __('Commission percent', 'sutore-marketplace'),
        ] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Growth cards use tier commission overrides. Recovery and engagement cards typically have no direct reward.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="reward_value">' . esc_html__('Reward value', 'sutore-marketplace') . '</label></th><td><input name="reward_value" type="number" id="reward_value" class="regular-text" value="0" step="0.01" /></td></tr>';
        echo '<tr><th scope="row"><label for="reward_duration_days">' . esc_html__('Reward duration (days)', 'sutore-marketplace') . '</label></th><td>';
        echo '<input name="reward_duration_days" type="number" id="reward_duration_days" class="small-text" value="30" min="0" />';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save template', 'sutore-marketplace'));
        echo '</form>';

        if (defined('WP_DEBUG') && WP_DEBUG) {
            echo '<h2>' . esc_html__('Progress bump (test)', 'sutore-marketplace') . '</h2>';
            echo '<form class="sutore-mp-admin-rest" data-rest-path="admin/tasks/progress" data-rest-method="POST">';
            echo '<table class="form-table" role="presentation"><tbody>';
            echo '<tr><th scope="row"><label for="bump_merchant_id">' . esc_html__('Merchant ID', 'sutore-marketplace') . '</label></th><td><input name="merchant_id" type="number" id="bump_merchant_id" class="regular-text" required /></td></tr>';
            echo '<tr><th scope="row"><label for="task_key_bump">task_key</label></th><td><input name="task_key" type="text" id="task_key_bump" class="regular-text" required /></td></tr>';
            echo '</tbody></table>';
            submit_button(__('Increment', 'sutore-marketplace'), 'secondary');
            echo '</form>';
        }

        echo '</div>';
    }
}
