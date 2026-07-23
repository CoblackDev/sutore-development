<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Coupons\Rest;

use SutoreMarketplace\Modules\Coupons\Admin\CouponSeeder;
use SutoreMarketplace\Shared\Rest\AdminPermission;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class AdminCouponsController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route('sutore-marketplace/v1', '/admin/coupons/seed-brand', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'seedBrand'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
    }

    public function seedBrand(): \WP_REST_Response
    {
        $result = (new CouponSeeder())->seed();
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success([
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'message' => sprintf(
                __('Sample coupons created: %d (skipped: %d).', 'sutore-marketplace'),
                $result['created'],
                $result['skipped']
            ),
        ]);
    }
}
