<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Rest;

use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Services\OutletQueryPresenter;
use SutoreMarketplace\Modules\Listings\Services\OutletService;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class OutletController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/outlet', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/outlet/(?P<id>\d+)/opt-in', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'optIn'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/outlet/optins/(?P<id>\d+)/cancel', [
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
        unset($req);

        return RestResponse::success(
            (new OutletQueryPresenter())->presentForMerchant(get_current_user_id())
        );
    }

    public function optIn(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new OutletService())->optIn((int) $req['id'], get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        $message = !empty($result['listing_created'])
            ? __('You joined this outlet item. Your product is now on sale at the committed price.', 'sutore-marketplace')
            : __('You joined this outlet item. A product will be created when the window opens.', 'sutore-marketplace');

        return RestResponse::success([
            ...$result,
            'message' => $message,
        ]);
    }

    public function cancel(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new OutletService())->cancelOptIn((int) $req['id'], get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Outlet opt-in cancelled.', 'sutore-marketplace')]);
    }
}
