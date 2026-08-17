<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Rest;

use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequestStatus;
use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Services\CatalogProductRequestService;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class CatalogProductRequestsController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/catalog-product-requests', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'canManage'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/catalog-product-requests/(?P<id>\d+)/cancel', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'cancel'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
    }

    public function canManage(): bool|\WP_Error
    {
        $auth = ListingPolicy::assertCanManage();
        if (is_wp_error($auth)) {
            return $auth;
        }

        return true;
    }

    public function index(\WP_REST_Request $req): \WP_REST_Response
    {
        $status = sanitize_key((string) ($req->get_param('status') ?: ''));
        $status = $status !== '' && CatalogProductRequestStatus::isValid($status) ? $status : null;

        return RestResponse::success(
            (new CatalogProductRequestService())->listForMerchant(
                get_current_user_id(),
                $status,
                max(1, (int) ($req->get_param('page') ?: 1)),
                max(1, min(50, (int) ($req->get_param('per_page') ?: 20)))
            )
        );
    }

    public function create(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params();
        if (!is_array($params)) {
            $params = $req->get_params();
        }

        $result = (new CatalogProductRequestService())->create(get_current_user_id(), is_array($params) ? $params : []);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result, 201);
    }

    public function cancel(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new CatalogProductRequestService())->cancel((int) $req['id'], get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }
}
