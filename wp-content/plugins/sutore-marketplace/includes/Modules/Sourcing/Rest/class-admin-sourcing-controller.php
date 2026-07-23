<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Sourcing\Rest;

use SutoreMarketplace\Modules\Sourcing\Services\SourcingService;
use SutoreMarketplace\Shared\Rest\AdminPermission;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class AdminSourcingController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/admin/sourcing', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/sourcing/(?P<id>\d+)/accept', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'accept'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/sourcing/(?P<id>\d+)/fulfill', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'fulfill'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/sourcing/(?P<id>\d+)/cancel', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'cancel'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
    }

    public function create(\WP_REST_Request $req): \WP_REST_Response
    {
        $id = (new SourcingService())->createRequest($this->params($req), get_current_user_id());
        if (is_wp_error($id)) {
            return RestResponse::fromWpError($id);
        }

        return RestResponse::success([
            'id' => $id,
            'message' => __('Pre-order request created.', 'sutore-marketplace'),
        ]);
    }

    public function accept(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $this->params($req);
        $result = (new SourcingService())->accept(
            (int) $req['id'],
            (int) ($params['merchant_id'] ?? 0),
            (int) ($params['listing_id'] ?? 0) ?: null
        );
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Accepted.', 'sutore-marketplace')]);
    }

    public function fulfill(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new SourcingService())->fulfill((int) $req['id']);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Completed.', 'sutore-marketplace')]);
    }

    public function cancel(\WP_REST_Request $req): \WP_REST_Response
    {
        (new SourcingService())->cancel((int) $req['id']);

        return RestResponse::success(['message' => __('Cancelled.', 'sutore-marketplace')]);
    }

    /** @return array<string, mixed> */
    private function params(\WP_REST_Request $req): array
    {
        $params = $req->get_json_params();
        if (!is_array($params)) {
            $params = $req->get_params();
        }

        return is_array($params) ? $params : [];
    }
}
