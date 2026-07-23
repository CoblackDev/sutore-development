<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Tasks\Rest;

use SutoreMarketplace\Modules\Tasks\Services\TaskProgressService;
use SutoreMarketplace\Shared\Rest\AdminPermission;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class AdminTasksController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/admin/tasks/definitions', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'saveDefinition'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/tasks/progress', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'bumpProgress'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
    }

    public function saveDefinition(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params();
        if (!is_array($params)) {
            $params = $req->get_params();
        }
        $params = is_array($params) ? $params : [];

        (new TaskProgressService())->saveDefinition($params);

        return RestResponse::success(['message' => __('Task saved.', 'sutore-marketplace')]);
    }

    public function bumpProgress(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params();
        if (!is_array($params)) {
            $params = $req->get_params();
        }
        $params = is_array($params) ? $params : [];

        $result = (new TaskProgressService())->increment(
            (int) ($params['merchant_id'] ?? 0),
            sanitize_key((string) ($params['task_key'] ?? ''))
        );

        return RestResponse::success([
            'result' => $result,
            'message' => __('Progress updated.', 'sutore-marketplace'),
        ]);
    }
}
