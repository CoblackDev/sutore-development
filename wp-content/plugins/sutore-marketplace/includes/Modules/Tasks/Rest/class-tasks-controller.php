<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Rest;

use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Tasks\Services\TaskProgressService;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class TasksController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route('sutore-marketplace/v1', '/tasks/dashboard', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'dashboard'],
                'permission_callback' => [$this, 'canAccess'],
            ],
        ]);
    }

    public function canAccess(): bool
    {
        return is_user_logged_in() && MerchantMeta::isMerchant(get_current_user_id());
    }

    public function dashboard(\WP_REST_Request $req): \WP_REST_Response
    {
        $dash = (new TaskProgressService())->dashboardForMerchant(get_current_user_id());
        $rewards = array_map(static function ($row): array {
            return [
                'id' => (int) $row->id,
                'task_id' => (int) $row->task_id,
                'reward_type' => (string) $row->reward_type,
                'reward_value' => (float) $row->reward_value,
                'note' => (string) ($row->note ?? ''),
                'created_at' => (string) ($row->created_at ?? ''),
            ];
        }, $dash['rewards']);

        return RestResponse::success([
            'tasks' => $dash['tasks'],
            'rewards' => $rewards,
        ]);
    }
}
