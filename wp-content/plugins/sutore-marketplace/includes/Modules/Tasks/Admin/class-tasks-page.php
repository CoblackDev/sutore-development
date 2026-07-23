<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Admin;

use SutoreMarketplace\Admin\AdminAssets;
use SutoreMarketplace\Admin\AdminMenu;
use SutoreMarketplace\Modules\Tasks\Repositories\TasksRepository;

final class TasksPage
{
    public function render(): void
    {
        if (!current_user_can(AdminMenu::CAP)) {
            return;
        }

        AdminAssets::enqueue();

        $repo = new TasksRepository();

        if (!$repo->allDefinitions()) {
            foreach ([
                ['listings_created', __('First listings', 'sutore-marketplace'), 1, 'points', 10],
                ['listing_updates', __('Update price', 'sutore-marketplace'), 3, 'points', 5],
                ['first_sale', __('First sale', 'sutore-marketplace'), 1, 'points', 50],
                ['sales_count', __('5 sales', 'sutore-marketplace'), 5, 'badge', 1],
            ] as [$key, $title, $target, $type, $val]) {
                $repo->saveDefinition([
                    'task_key' => $key,
                    'title' => $title,
                    'description' => '',
                    'target_count' => $target,
                    'reward_type' => $type,
                    'reward_value' => $val,
                    'reward_duration_days' => 0,
                    'is_active' => 1,
                ]);
            }
        }

        $defs = $repo->allDefinitions();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Tasks & Rewards', 'sutore-marketplace') . '</h1>';
        echo '<hr class="wp-header-end" />';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        foreach ([
            __('Key', 'sutore-marketplace'),
            __('Title', 'sutore-marketplace'),
            __('Target', 'sutore-marketplace'),
            __('Reward', 'sutore-marketplace'),
            __('Duration (days)', 'sutore-marketplace'),
            __('Active', 'sutore-marketplace'),
        ] as $h) {
            echo '<th scope="col">' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($defs as $def) {
            $duration = (int) ($def->reward_duration_days ?? 0);
            echo '<tr>';
            echo '<td><code>' . esc_html($def->task_key) . '</code></td>';
            echo '<td>' . esc_html($def->title) . '</td>';
            echo '<td>' . (int) $def->target_count . '</td>';
            echo '<td>' . esc_html($def->reward_type . ' / ' . $def->reward_value) . '</td>';
            echo '<td>' . esc_html($duration > 0 ? (string) $duration : '—') . '</td>';
            echo '<td>' . ((int) $def->is_active ? esc_html__('Yes', 'sutore-marketplace') : esc_html__('No', 'sutore-marketplace')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('New task', 'sutore-marketplace') . '</h2>';
        echo '<form class="sutore-mp-admin-rest" data-rest-path="admin/tasks/definitions" data-rest-method="POST">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="task_key">task_key</label></th><td><input name="task_key" type="text" id="task_key" class="regular-text" required /></td></tr>';
        echo '<tr><th scope="row"><label for="task_title">' . esc_html__('Title', 'sutore-marketplace') . '</label></th><td><input name="title" type="text" id="task_title" class="regular-text" required /></td></tr>';
        echo '<tr><th scope="row"><label for="task_description">' . esc_html__('Description', 'sutore-marketplace') . '</label></th><td><textarea name="description" id="task_description" class="large-text" rows="3"></textarea></td></tr>';
        echo '<tr><th scope="row"><label for="target_count">' . esc_html__('Target', 'sutore-marketplace') . '</label></th><td><input name="target_count" type="number" id="target_count" class="small-text" value="1" min="1" /></td></tr>';
        echo '<tr><th scope="row"><label for="reward_type">' . esc_html__('Reward type', 'sutore-marketplace') . '</label></th><td>';
        echo '<select name="reward_type" id="reward_type">';
        foreach ([
            'points' => __('Points', 'sutore-marketplace'),
            'badge' => __('Badge', 'sutore-marketplace'),
            'commission_percent' => __('Commission percent', 'sutore-marketplace'),
        ] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($value, 'points', false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('For commission percent, reward value is the absolute commission % applied as an override.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="reward_value">' . esc_html__('Reward value', 'sutore-marketplace') . '</label></th><td><input name="reward_value" type="number" id="reward_value" class="regular-text" value="0" step="0.01" /></td></tr>';
        echo '<tr><th scope="row"><label for="reward_duration_days">' . esc_html__('Reward duration (days)', 'sutore-marketplace') . '</label></th><td>';
        echo '<input name="reward_duration_days" type="number" id="reward_duration_days" class="small-text" value="0" min="0" />';
        echo '<p class="description">' . esc_html__('Used for commission percent overrides. 0 = no end date.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save', 'sutore-marketplace'));
        echo '</form>';

        echo '<h2>' . esc_html__('Progress bump (test)', 'sutore-marketplace') . '</h2>';
        echo '<form class="sutore-mp-admin-rest" data-rest-path="admin/tasks/progress" data-rest-method="POST">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="bump_merchant_id">' . esc_html__('Merchant ID', 'sutore-marketplace') . '</label></th><td><input name="merchant_id" type="number" id="bump_merchant_id" class="regular-text" required /></td></tr>';
        echo '<tr><th scope="row"><label for="task_key_bump">task_key</label></th><td><input name="task_key" type="text" id="task_key_bump" class="regular-text" required /></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Increment', 'sutore-marketplace'), 'secondary');
        echo '</form></div>';
    }
}
