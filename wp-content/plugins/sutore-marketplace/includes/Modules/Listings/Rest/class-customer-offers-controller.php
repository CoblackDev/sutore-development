<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Rest;

use SutoreMarketplace\Modules\Listings\Domain\CustomerOfferStatus;
use SutoreMarketplace\Modules\Listings\Services\CustomerOfferQueryPresenter;
use SutoreMarketplace\Modules\Listings\Services\CustomerOfferService;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class CustomerOffersController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/my-offers', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'isLoggedIn'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'isLoggedIn'],
                'args' => [
                    'variation_id' => [
                        'type' => 'integer',
                        'required' => true,
                    ],
                    'bid_amount' => [
                        'type' => 'number',
                        'required' => true,
                    ],
                ],
            ],
        ]);
        register_rest_route($ns, '/my-offers/context', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'context'],
                'permission_callback' => [$this, 'isLoggedIn'],
                'args' => [
                    'variation_id' => [
                        'type' => 'integer',
                        'required' => true,
                    ],
                ],
            ],
        ]);
        register_rest_route($ns, '/my-offers/(?P<id>\d+)/cancel', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'cancel'],
                'permission_callback' => [$this, 'isLoggedIn'],
            ],
        ]);
    }

    public function isLoggedIn(): bool|\WP_Error
    {
        if (!is_user_logged_in()) {
            return new \WP_Error(
                'sutore_marketplace_auth',
                __('You must log in.', 'sutore-marketplace'),
                ['status' => 401]
            );
        }

        return true;
    }

    public function index(\WP_REST_Request $req): \WP_REST_Response
    {
        $status = sanitize_key((string) ($req->get_param('status') ?: ''));
        $status = $status !== '' && CustomerOfferStatus::isValid($status) ? $status : null;
        $page = max(1, (int) ($req->get_param('page') ?: 1));
        $perPage = max(1, min(50, (int) ($req->get_param('per_page') ?: 20)));

        return RestResponse::success(
            (new CustomerOfferQueryPresenter())->presentForCustomer(
                get_current_user_id(),
                $status,
                $page,
                $perPage
            )
        );
    }

    public function context(\WP_REST_Request $req): \WP_REST_Response
    {
        $variationId = (int) $req->get_param('variation_id');
        if ($variationId <= 0) {
            return RestResponse::fail(
                __('Product not found.', 'sutore-marketplace'),
                400,
                'sutore_customer_offer_listing'
            );
        }

        return RestResponse::success(
            (new CustomerOfferQueryPresenter())->presentContext($variationId, get_current_user_id())
        );
    }

    public function create(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new CustomerOfferService())->create(
            get_current_user_id(),
            (int) $req->get_param('variation_id'),
            (float) $req->get_param('bid_amount')
        );
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success([
            ...$result,
            'message' => __('Your offer was sent to the seller.', 'sutore-marketplace'),
        ], 201);
    }

    public function cancel(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new CustomerOfferService())->cancel((int) $req['id'], get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Offer cancelled.', 'sutore-marketplace')]);
    }
}
