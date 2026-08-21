<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Rest;

use SutoreMarketplace\Admin\StaffCapabilities;
use SutoreMarketplace\Modules\Orders\Services\StaffOrderPresenter;
use SutoreMarketplace\Modules\Orders\Services\StaffOrderService;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class StaffOrdersController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/admin/orders', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'query'],
                'permission_callback' => [$this, 'canAccessStaff'],
            ],
        ]);

        register_rest_route($ns, '/admin/orders/bulk-status', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'bulkStatus'],
                'permission_callback' => [$this, 'canAccessStaff'],
            ],
        ]);

        register_rest_route($ns, '/admin/orders/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'details'],
                'permission_callback' => [$this, 'canAccessStaff'],
            ],
        ]);

        register_rest_route($ns, '/admin/orders/(?P<id>\d+)/status', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'updateStatus'],
                'permission_callback' => [$this, 'canAccessStaff'],
            ],
        ]);

        register_rest_route($ns, '/admin/orders/(?P<id>\d+)/attach-candidates', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'attachCandidates'],
                'permission_callback' => [$this, 'canAccessStaff'],
            ],
        ]);

        register_rest_route($ns, '/admin/orders/(?P<id>\d+)/attach', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'attach'],
                'permission_callback' => [$this, 'canAccessStaff'],
            ],
        ]);

        register_rest_route($ns, '/admin/orders/(?P<id>\d+)/items/(?P<item_id>\d+)', [
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'removeItem'],
                'permission_callback' => [$this, 'canAccessStaff'],
            ],
        ]);
    }

    public function canAccessStaff(): bool
    {
        return is_user_logged_in() && StaffCapabilities::canManageOps();
    }

    public function query(\WP_REST_Request $req): \WP_REST_Response
    {
        $orderby = sanitize_key((string) ($req->get_param('orderby') ?: 'date_desc'));
        $allowed = [
            'date_desc',
            'date_asc',
            'id_desc',
            'id_asc',
            'total_desc',
            'total_asc',
            'deadline_asc',
            'deadline_desc',
        ];
        if (!in_array($orderby, $allowed, true)) {
            $orderby = 'date_desc';
        }

        $data = (new StaffOrderPresenter())->presentQuery([
            'search' => sanitize_text_field((string) ($req->get_param('search') ?? '')),
            'status' => sanitize_key((string) ($req->get_param('status') ?? '')),
            'orderby' => $orderby,
            'page' => max(1, absint($req->get_param('page') ?: 1)),
            'per_page' => max(1, min(100, absint($req->get_param('per_page') ?: 30))),
        ]);

        return RestResponse::success($data);
    }

    public function details(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new StaffOrderPresenter())->presentDetail(absint($req['id']));
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function updateStatus(\WP_REST_Request $req): \WP_REST_Response
    {
        $status = sanitize_key((string) ($req->get_param('status') ?? ''));
        $service = new StaffOrderService();
        $result = $service->updateStatus(absint($req['id']), $status);
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        $detail = (new StaffOrderPresenter())->presentDetail((int) $result['order']->get_id());
        if ($detail instanceof \WP_Error) {
            return RestResponse::fromWpError($detail);
        }

        return RestResponse::success($detail);
    }

    public function bulkStatus(\WP_REST_Request $req): \WP_REST_Response
    {
        $ids = $req->get_param('ids');
        if (!is_array($ids)) {
            $ids = [];
        }
        $status = sanitize_key((string) ($req->get_param('status') ?? ''));
        $result = (new StaffOrderService())->bulkUpdateStatus($ids, $status);
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function attachCandidates(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new StaffOrderService())->listAttachCandidates(absint($req['id']), [
            'search' => sanitize_text_field((string) ($req->get_param('search') ?? '')),
            'per_page' => max(1, min(50, absint($req->get_param('per_page') ?: 30))),
        ]);
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function attach(\WP_REST_Request $req): \WP_REST_Response
    {
        $service = new StaffOrderService();
        $result = $service->attachListing(
            absint($req['id']),
            absint($req->get_param('variation_id') ?: 0),
            sanitize_textarea_field((string) ($req->get_param('staff_note') ?? ''))
        );
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        $detail = (new StaffOrderPresenter())->presentDetail((int) $result['order']->get_id());
        if ($detail instanceof \WP_Error) {
            return RestResponse::fromWpError($detail);
        }

        return RestResponse::success($detail);
    }

    public function removeItem(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new StaffOrderService())->removeLineItem(
            absint($req['id']),
            absint($req['item_id'])
        );
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        $detail = (new StaffOrderPresenter())->presentDetail((int) $result['order']->get_id());
        if ($detail instanceof \WP_Error) {
            return RestResponse::fromWpError($detail);
        }

        return RestResponse::success($detail);
    }
}
