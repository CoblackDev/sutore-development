<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Sourcing\Rest;

use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Sourcing\Services\SourcingFeedPresenter;
use SutoreMarketplace\Modules\Sourcing\Services\SourcingService;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class SourcingController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/sourcing', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'query'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/sourcing/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getOne'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/sourcing/(?P<id>\d+)/accept', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'accept'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
    }

    public function canManage(): bool
    {
        return is_user_logged_in() && !is_wp_error(ListingPolicy::assertCanManage());
    }

    public function query(\WP_REST_Request $req): \WP_REST_Response
    {
        $page = max(1, (int) ($req->get_param('page') ?: 1));
        $perPage = max(1, (int) ($req->get_param('per_page') ?: 20));
        $status = sanitize_key((string) ($req->get_param('status') ?: ''));
        $status = $status !== '' ? $status : null;
        $search = sanitize_text_field((string) ($req->get_param('search') ?: ''));
        $orderby = sanitize_key((string) ($req->get_param('orderby') ?: 'default'));
        $data = (new SourcingFeedPresenter())->presentForMerchant(
            get_current_user_id(),
            $page,
            $perPage,
            $status,
            $search,
            $orderby
        );

        return RestResponse::success($data);
    }

    public function getOne(\WP_REST_Request $req): \WP_REST_Response
    {
        $item = (new SourcingFeedPresenter())->presentOneForMerchant((int) $req['id'], get_current_user_id());
        if ($item === null) {
            return RestResponse::fail(
                __('Sourcing request not found.', 'sutore-marketplace'),
                404,
                'sutore_sourcing_missing'
            );
        }

        return RestResponse::success(['item' => $item]);
    }

    public function accept(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params() ?: $req->get_params();
        $requestId = (int) $req['id'];
        $listingId = (int) ($params['listing_id'] ?? 0) ?: null;
        $createNewListing = rest_sanitize_boolean($params['create_new_listing'] ?? false);
        $merchantId = get_current_user_id();

        // Ownership / mismatch checks live in SourcingService::accept().
        $result = (new SourcingService())->accept($requestId, $merchantId, $listingId, $createNewListing);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }
}
