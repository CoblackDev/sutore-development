<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Rest;

use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferStatus;
use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Services\CustomerOfferQueryPresenter;
use SutoreMarketplace\Modules\Listings\Services\CustomerOfferService;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class PriceOffersController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/price-offers', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/price-offers/(?P<id>\d+)/accept', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'accept'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/price-offers/(?P<id>\d+)/decline', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'decline'],
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
        $status = $status !== '' && CustomerOfferStatus::isValid($status) ? $status : null;
        $orderby = sanitize_key((string) ($req->get_param('orderby') ?: 'created_desc'));
        $page = max(1, (int) ($req->get_param('page') ?: 1));
        $perPage = max(1, min(50, (int) ($req->get_param('per_page') ?: 20)));

        return RestResponse::success(
            (new CustomerOfferQueryPresenter())->presentForMerchant(
                get_current_user_id(),
                $status,
                $page,
                $perPage,
                $orderby
            )
        );
    }

    public function accept(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new CustomerOfferService())->accept((int) $req['id'], get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success([
            ...$result,
            'message' => __('Customer offer accepted. A personal coupon was created.', 'sutore-marketplace'),
        ]);
    }

    public function decline(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new CustomerOfferService())->decline((int) $req['id'], get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Customer offer declined.', 'sutore-marketplace')]);
    }
}
