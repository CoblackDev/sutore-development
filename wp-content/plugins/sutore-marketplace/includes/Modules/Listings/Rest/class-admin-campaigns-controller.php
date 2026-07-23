<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Rest;

use SutoreMarketplace\Modules\Listings\Services\CampaignService;
use SutoreMarketplace\Shared\Rest\AdminPermission;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class AdminCampaignsController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/admin/campaigns', [
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
        register_rest_route($ns, '/admin/campaigns/preview', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'preview'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/campaigns/(?P<id>\d+)/publish', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'publish'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/campaigns/(?P<id>\d+)/end', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'end'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/listings/(?P<id>\d+)/approve', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'approveListing'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/listings/(?P<id>\d+)/remove-from-sale', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'removeFromSale'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/listings/(?P<id>\d+)/activity', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listingActivity'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/listings/(?P<id>\d+)', [
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'forceDeleteListing'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
    }

    public function index(): \WP_REST_Response
    {
        return RestResponse::success([
            'items' => (new CampaignService())->listForAdmin(),
        ]);
    }

    public function create(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $this->params($req);
        $id = (new CampaignService())->createCampaign($params);
        if (is_wp_error($id)) {
            return RestResponse::fromWpError($id);
        }

        return RestResponse::success([
            'id' => $id,
            'message' => sprintf(__('Campaign #%d created.', 'sutore-marketplace'), (int) $id),
        ]);
    }

    public function preview(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new CampaignService())->previewTargeting($this->params($req));

        return RestResponse::success([
            'listing_count' => $result['listing_count'],
            'merchant_count' => $result['merchant_count'],
            /* translators: 1: listing/product count, 2: merchant count */
            'message' => $result['listing_count'] > 0
                ? sprintf(
                    __('This campaign will cover %1$d products (%2$d merchants).', 'sutore-marketplace'),
                    $result['listing_count'],
                    $result['merchant_count']
                )
                : __('No products match the current targeting.', 'sutore-marketplace'),
        ]);
    }

    public function publish(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new CampaignService())->publish((int) $req['id']);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success([
            ...$result,
            'message' => sprintf(
                /* translators: 1: offers created, 2: skipped */
                __('Published: %1$d offers created (%2$d skipped).', 'sutore-marketplace'),
                (int) $result['offers_created'],
                (int) $result['offers_skipped']
            ),
        ]);
    }

    public function end(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new CampaignService())->endCampaign((int) $req['id']);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Campaign ended and offers reverted.', 'sutore-marketplace')]);
    }

    public function forceDeleteListing(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new \SutoreMarketplace\Modules\Listings\Services\ListingService())->delete((int) $req['id']);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Deleted.', 'sutore-marketplace')]);
    }

    public function approveListing(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new \SutoreMarketplace\Modules\Listings\Services\ListingSelector())->approvePendingWinner((int) $req['id']);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success([
            'listing' => $result->toArray(),
            'message' => __('Listing approved.', 'sutore-marketplace'),
        ]);
    }

    public function removeFromSale(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $this->params($req);
        $result = (new \SutoreMarketplace\Modules\Listings\Services\ListingService())->removeFromSale(
            (int) $req['id'],
            null,
            [
                'staff_note' => sanitize_textarea_field((string) ($params['staff_note'] ?? '')),
            ]
        );
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success([
            'listing' => $result->toArray(),
            'message' => __('Listing removed from sale.', 'sutore-marketplace'),
        ]);
    }

    public function listingActivity(\WP_REST_Request $req): \WP_REST_Response
    {
        return RestResponse::success(
            (new CampaignService())->listingActivityForAdmin(
                (int) $req['id'],
                (int) ($req->get_param('variation_id') ?: 0)
            )
        );
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
