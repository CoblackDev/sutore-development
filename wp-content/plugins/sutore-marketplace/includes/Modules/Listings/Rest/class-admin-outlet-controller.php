<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Rest;

use SutoreMarketplace\Modules\Listings\Services\OutletQueryPresenter;
use SutoreMarketplace\Modules\Listings\Services\OutletService;
use SutoreMarketplace\Shared\Rest\AdminPermission;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class AdminOutletController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/admin/outlet-windows', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/outlet-windows/(?P<id>\d+)/publish', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'publish'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/outlet-windows/(?P<id>\d+)/end', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'end'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/outlet-windows/(?P<id>\d+)/items', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'addItem'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/outlet-windows/(?P<id>\d+)/items/(?P<item_id>\d+)', [
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteItem'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
    }

    public function index(\WP_REST_Request $req): \WP_REST_Response
    {
        unset($req);

        return RestResponse::success([
            'items' => (new OutletQueryPresenter())->listForAdmin(),
        ]);
    }

    public function create(\WP_REST_Request $req): \WP_REST_Response
    {
        $id = (new OutletService())->createWindow($this->params($req));
        if (is_wp_error($id)) {
            return RestResponse::fromWpError($id);
        }

        return RestResponse::success([
            'id' => $id,
            'message' => sprintf(__('Outlet window #%d created.', 'sutore-marketplace'), (int) $id),
        ]);
    }

    public function publish(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new OutletService())->publish((int) $req['id']);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Outlet window published.', 'sutore-marketplace')]);
    }

    public function end(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new OutletService())->endWindow((int) $req['id']);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Outlet window ended. Unsold listings were taken off sale.', 'sutore-marketplace')]);
    }

    public function addItem(\WP_REST_Request $req): \WP_REST_Response
    {
        $id = (new OutletService())->addItem((int) $req['id'], $this->params($req));
        if (is_wp_error($id)) {
            return RestResponse::fromWpError($id);
        }

        return RestResponse::success([
            'id' => $id,
            'message' => __('Outlet item added.', 'sutore-marketplace'),
        ]);
    }

    public function deleteItem(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new OutletService())->deleteItem((int) $req['id'], (int) $req['item_id']);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Outlet item removed.', 'sutore-marketplace')]);
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
