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
        register_rest_route($ns, '/admin/campaigns/(?P<id>\d+)/offers', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'createOffer'],
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

    public function index(\WP_REST_Request $req): \WP_REST_Response
    {
        $service = new CampaignService();
        $sendable = (string) $req->get_param('sendable') === '1'
            || (string) $req->get_param('sendable') === 'true';

        return RestResponse::success([
            'items' => $sendable ? $service->listSendableForStaff() : $service->listForAdmin(),
        ]);
    }

    public function createOffer(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $this->params($req);
        $campaignId = (int) $req['id'];
        $listingIds = [];
        if (isset($params['variation_ids']) && is_array($params['variation_ids'])) {
            $listingIds = array_values(array_unique(array_filter(array_map('intval', $params['variation_ids']))));
        } elseif (!empty($params['variation_id'])) {
            $listingIds = [(int) $params['variation_id']];
        }

        if ($listingIds === []) {
            return RestResponse::fail(
                __('Select at least one product.', 'sutore-marketplace'),
                400,
                'sutore_campaign_listing_required'
            );
        }

        $service = new CampaignService();
        $created = 0;
        $errors = [];
        foreach ($listingIds as $listingId) {
            $result = $service->createOfferForListing($campaignId, $listingId, [
                'skip_targeting' => true,
                'activate_campaign' => true,
                'staff_manual' => true,
            ]);
            if (is_wp_error($result)) {
                $errors[] = [
                    'variation_id' => $listingId,
                    'code' => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ];
                continue;
            }
            $created++;
        }

        if ($created === 0) {
            $first = $errors[0]['message'] ?? __('Campaign offer could not be created.', 'sutore-marketplace');

            return RestResponse::fail($first, 409, 'sutore_campaign_offer_failed', [
                'errors' => $errors,
            ]);
        }

        return RestResponse::success([
            'offers_created' => $created,
            'errors' => $errors,
            'message' => sprintf(
                /* translators: %d: number of offers created */
                _n('%d campaign offer sent.', '%d campaign offers sent.', $created, 'sutore-marketplace'),
                $created
            ),
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
        $listingCount = (int) $result['listing_count'];
        $merchantCount = (int) $result['merchant_count'];
        $matchedCount = (int) ($result['matched_count'] ?? $listingCount);
        $busyCount = (int) ($result['busy_count'] ?? 0);
        $truncated = !empty($result['truncated']);

        if ($listingCount > 0) {
            $message = sprintf(
                /* translators: 1: listing/product count, 2: merchant count */
                __('This campaign will cover %1$d products (%2$d merchants).', 'sutore-marketplace'),
                $listingCount,
                $merchantCount
            );
            if ($truncated) {
                $message .= ' ' . sprintf(
                    /* translators: %d: scan cap */
                    __('Audience scan is capped at %d matching products; the real audience may be larger.', 'sutore-marketplace'),
                    2000
                );
            }
        } elseif ($matchedCount > 0 && $busyCount > 0) {
            $message = sprintf(
                /* translators: %d: count of matching listings already in a campaign */
                __('No eligible products: %d matching product(s) already have a campaign offer or active campaign.', 'sutore-marketplace'),
                $busyCount
            );
        } else {
            $message = __('No products match the current targeting.', 'sutore-marketplace');
        }

        return RestResponse::success([
            'listing_count' => $listingCount,
            'merchant_count' => $merchantCount,
            'matched_count' => $matchedCount,
            'busy_count' => $busyCount,
            'truncated' => $truncated,
            'samples' => $result['samples'] ?? [],
            'message' => $message,
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
            'message' => __('Product approved.', 'sutore-marketplace'),
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
            'message' => __('Product removed from sale.', 'sutore-marketplace'),
        ]);
    }

    public function listingActivity(\WP_REST_Request $req): \WP_REST_Response
    {
        return RestResponse::success(
            (new CampaignService())->listingActivityForAdmin((int) $req['id'])
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
