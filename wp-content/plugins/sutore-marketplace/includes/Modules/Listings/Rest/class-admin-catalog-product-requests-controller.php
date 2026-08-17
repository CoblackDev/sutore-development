<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Rest;

use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequestStatus;
use SutoreMarketplace\Modules\Listings\Services\CatalogProductRequestService;
use SutoreMarketplace\Shared\Rest\AdminPermission;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class AdminCatalogProductRequestsController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/admin/catalog-product-requests', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/catalog-product-requests/(?P<id>\d+)/fulfill', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'fulfill'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/catalog-product-requests/(?P<id>\d+)/reject', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'reject'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
    }

    public function index(\WP_REST_Request $req): \WP_REST_Response
    {
        $status = sanitize_key((string) ($req->get_param('status') ?: ''));
        if ($status !== '' && !CatalogProductRequestStatus::isValid($status)) {
            $status = '';
        }

        return RestResponse::success(
            (new CatalogProductRequestService())->listForStaff(
                [
                    'status' => $status,
                    'search' => sanitize_text_field((string) ($req->get_param('search') ?: '')),
                    'merchant_id' => (int) ($req->get_param('merchant_id') ?: 0),
                ],
                max(1, (int) ($req->get_param('page') ?: 1)),
                max(1, min(50, (int) ($req->get_param('per_page') ?: 30)))
            )
        );
    }

    public function fulfill(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params();
        if (!is_array($params)) {
            $params = $req->get_params();
        }

        $result = (new CatalogProductRequestService())->fulfill(
            (int) $req['id'],
            get_current_user_id(),
            is_array($params) ? $params : []
        );
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function reject(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params();
        if (!is_array($params)) {
            $params = $req->get_params();
        }

        $result = (new CatalogProductRequestService())->reject(
            (int) $req['id'],
            get_current_user_id(),
            is_array($params) ? $params : []
        );
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }
}
