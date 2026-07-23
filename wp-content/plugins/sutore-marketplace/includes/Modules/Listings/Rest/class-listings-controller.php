<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Rest;

use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ProductCodeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Listings\Services\ListingActivityPresenter;
use SutoreMarketplace\Modules\Listings\Services\ListingFormContext;
use SutoreMarketplace\Modules\Listings\Services\ListingQueryPresenter;
use SutoreMarketplace\Modules\Listings\Services\ListingService;
use SutoreMarketplace\Modules\Listings\Services\ListingSelector;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class ListingsController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/search-parents', [
            'methods' => 'GET',
            'callback' => [$this, 'searchParents'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/sizes/(?P<parent_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'sizes'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/form-context', [
            'methods' => 'GET',
            'callback' => [$this, 'formContext'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/listings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'query'],
                'permission_callback' => [$this, 'canManage'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/listings/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getOne'],
                'permission_callback' => [$this, 'canManage'],
            ],
            [
                'methods' => 'PUT,PATCH',
                'callback' => [$this, 'update'],
                'permission_callback' => [$this, 'canManage'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete'],
                'permission_callback' => [$this, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/listings/(?P<id>\d+)/put-on-sale', [
            'methods' => 'POST',
            'callback' => [$this, 'putOnSale'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/listings/(?P<id>\d+)/remove-from-sale', [
            'methods' => 'POST',
            'callback' => [$this, 'removeFromSale'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/listings/(?P<id>\d+)/activity', [
            'methods' => 'GET',
            'callback' => [$this, 'activity'],
            'permission_callback' => [$this, 'canManage'],
        ]);
    }

    public function canManage(): bool
    {
        return is_user_logged_in() && !is_wp_error(ListingPolicy::assertCanManage());
    }

    public function searchParents(\WP_REST_Request $req): \WP_REST_Response
    {
        $code = sanitize_text_field((string) $req->get_param('product_code'));
        if ($code === '') {
            return RestResponse::fail(__('Enter product code.', 'sutore-marketplace'), 400);
        }

        $cat = sanitize_title((string) $req->get_param('product_cat'));
        $items = ProductCodeLookup::searchParents($code, $cat);

        return RestResponse::success([
            'items' => $items,
            'message' => $items ? null : __('The product you searched for was not found', 'sutore-marketplace'),
        ]);
    }

    public function sizes(\WP_REST_Request $req): \WP_REST_Response
    {
        $parentId = (int) $req['parent_id'];
        $sizes = ProductSizeLookup::termsForParent($parentId);
        if ($sizes === []) {
            $product = wc_get_product($parentId);
            if (!$product || !$product->is_type('variable')) {
                return RestResponse::fail(__('Product is not variable.', 'sutore-marketplace'), 400);
            }
        }

        return RestResponse::success(['items' => $sizes]);
    }

    public function formContext(\WP_REST_Request $req): \WP_REST_Response
    {
        $listingId = (int) $req->get_param('listing_id') ?: null;
        if ($listingId) {
            $listing = (new ListingRepository())->find($listingId);
            if (!$listing) {
                return RestResponse::fail(__('Listing not found.', 'sutore-marketplace'), 404, 'not_found');
            }
            $owns = ListingPolicy::assertOwnsListing($listing);
            if (is_wp_error($owns)) {
                return RestResponse::fromWpError($owns);
            }
        }

        $input = [
            'parent_product_id' => (int) $req->get_param('parent_product_id'),
            'size_term_id' => (int) $req->get_param('size_term_id'),
            'conditions' => (array) $req->get_param('conditions'),
            'asking' => $req->get_param('asking'),
            'listing_id' => $listingId,
        ];
        if ($req->has_param('fast_shipment')) {
            $input['fast_shipment'] = !empty($req->get_param('fast_shipment'));
        }
        if ($req->has_param('has_invoice')) {
            $input['has_invoice'] = !empty($req->get_param('has_invoice'));
        }

        $ctx = (new ListingFormContext())->build($input);
        return RestResponse::success($ctx);
    }

    public function query(\WP_REST_Request $req): \WP_REST_Response
    {
        $args = [
            'search' => sanitize_text_field((string) $req->get_param('search')),
            'status' => sanitize_key((string) $req->get_param('status')),
            'campaign' => sanitize_key((string) $req->get_param('campaign')),
            'is_sourcing' => sanitize_key((string) $req->get_param('is_sourcing')),
            'is_imported' => sanitize_key((string) $req->get_param('is_imported')),
            'orderby' => sanitize_key((string) ($req->get_param('orderby') ?: 'created_at')),
            'order' => sanitize_key((string) ($req->get_param('order') ?: 'DESC')),
            'page' => (int) ($req->get_param('page') ?: 1),
            'per_page' => (int) ($req->get_param('per_page') ?: 20),
            'product_cat' => sanitize_title((string) ($req->get_param('product_cat') ?: '')),
            'size_term_id' => (int) ($req->get_param('size_term_id') ?: 0) ?: null,
            'fast_shipment' => !empty($req->get_param('fast_shipment')),
            'condition_key' => sanitize_key((string) ($req->get_param('condition_key') ?: '')),
            'merchant_id' => get_current_user_id(),
        ];

        return RestResponse::success((new ListingQueryPresenter())->present($args));
    }

    public function getOne(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $listing = (new ListingRepository())->find((int) $req['id']);
        if (!$listing) {
            return new \WP_Error('not_found', __('Listing not found.', 'sutore-marketplace'), ['status' => 404]);
        }
        $owns = ListingPolicy::assertOwnsListing($listing);
        if (is_wp_error($owns)) {
            return $owns;
        }

        $item = (new ListingQueryPresenter())->enrich($listing);
        $formContext = (new ListingFormContext())->build(['listing_id' => (int) $req['id']]);
        $activity = (new ListingActivityPresenter())->present(
            (int) $listing->id,
            $listing->variationId,
            'merchant_visible'
        );

        return RestResponse::success([
            'item' => $item,
            'form_context' => $formContext,
            'activity' => $activity,
        ]);
    }

    public function create(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new ListingService())->create($req->get_json_params() ?: $req->get_params());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }
        $queue = (new ListingSelector())->queuePosition($result);
        return RestResponse::success(array_merge($result->toArray(), $queue), 201);
    }

    public function update(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new ListingService())->update((int) $req['id'], $req->get_json_params() ?: $req->get_params());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }
        $queue = (new ListingSelector())->queuePosition($result);
        return RestResponse::success(array_merge($result->toArray(), $queue));
    }

    public function delete(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new ListingService())->delete((int) $req['id']);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }
        return RestResponse::success(['deleted' => true]);
    }

    public function activity(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $listingId = (int) $req['id'];
        $variationId = (int) ($req->get_param('variation_id') ?: 0);
        $userId = get_current_user_id();
        $listing = (new ListingRepository())->find($listingId);

        if ($listing) {
            $owns = ListingPolicy::assertOwnsListing($listing);
            if (is_wp_error($owns)) {
                return $owns;
            }
            $variationId = $listing->variationId;
        } elseif (!user_can($userId, 'manage_woocommerce')
            && !(new ListingEventsRepository())->merchantCanAccessListing($listingId, $userId)) {
            return new \WP_Error('not_found', __('Listing not found.', 'sutore-marketplace'), ['status' => 404]);
        }

        $activity = (new ListingActivityPresenter())->present(
            $listingId,
            $variationId,
            user_can($userId, 'manage_woocommerce') ? null : 'merchant_visible'
        );

        return RestResponse::success([
            'listing_id' => $listingId,
            'deleted' => $listing === null,
            'activity' => $activity,
        ]);
    }

    public function putOnSale(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new ListingService())->putOnSale((int) $req['id']);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }
        $queue = (new ListingSelector())->queuePosition($result);

        return RestResponse::success(array_merge($result->toArray(), $queue));
    }

    public function removeFromSale(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params() ?: $req->get_params();
        $result = (new ListingService())->removeFromSale((int) $req['id'], null, [
            'staff_note' => sanitize_textarea_field((string) ($params['staff_note'] ?? '')),
        ]);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result->toArray());
    }
}
