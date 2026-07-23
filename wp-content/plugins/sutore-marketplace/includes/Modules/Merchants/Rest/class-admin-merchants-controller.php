<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Rest;

use SutoreMarketplace\Modules\Merchants\Services\AdminMerchantQueryService;
use SutoreMarketplace\Modules\Merchants\Services\CommissionOverrideService;
use SutoreMarketplace\Modules\Merchants\Services\MerchantProfileService;
use SutoreMarketplace\Modules\Merchants\Services\RestrictionService;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Shared\Rest\AdminPermission;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class AdminMerchantsController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/admin/merchants', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listMerchants'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
                'args' => [
                    'page' => ['type' => 'integer', 'default' => 1],
                    'per_page' => ['type' => 'integer', 'default' => 30],
                    'search' => ['type' => 'string', 'default' => ''],
                    'level' => ['type' => 'string', 'default' => ''],
                    'tc_verified' => ['type' => 'string', 'default' => ''],
                    'has_restriction' => ['type' => 'string', 'default' => ''],
                    'balance' => ['type' => 'string', 'default' => ''],
                    'sales' => ['type' => 'string', 'default' => ''],
                    'orderby' => ['type' => 'string', 'default' => 'id_desc'],
                ],
            ],
        ]);

        register_rest_route($ns, '/admin/merchants/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getMerchant'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
            [
                'methods' => ['PUT', 'PATCH'],
                'callback' => [$this, 'updateMerchant'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);

        register_rest_route($ns, '/admin/merchants/status', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'setStatus'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/merchants/(?P<id>\d+)/commission-override', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'createCommissionOverride'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/merchants/commission-overrides/(?P<id>\d+)', [
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteCommissionOverride'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/restrictions', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'createRestriction'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
        register_rest_route($ns, '/admin/restrictions/(?P<id>\d+)/deactivate', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'deactivateRestriction'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
    }

    public function listMerchants(\WP_REST_Request $req): \WP_REST_Response
    {
        $tc = $req->get_param('tc_verified');
        $hasRestriction = $req->get_param('has_restriction');

        $data = (new AdminMerchantQueryService())->list([
            'page' => (int) $req->get_param('page'),
            'per_page' => (int) $req->get_param('per_page'),
            'search' => (string) $req->get_param('search'),
            'status' => (string) $req->get_param('level'),
            'tc_verified' => $tc === null || $tc === '' ? null : $tc,
            'has_restriction' => $hasRestriction === null || $hasRestriction === '' ? null : $hasRestriction,
            'balance' => (string) $req->get_param('balance'),
            'sales' => (string) $req->get_param('sales'),
            'orderby' => (string) $req->get_param('orderby'),
        ]);

        return RestResponse::success($data);
    }

    public function getMerchant(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new AdminMerchantQueryService())->detail((int) $req['id']);
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function updateMerchant(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params();
        if (!is_array($params)) {
            $params = $req->get_params();
        }
        $params = is_array($params) ? $params : [];

        $result = (new MerchantProfileService())->saveByStaff((int) $req['id'], $params);
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        $detail = (new AdminMerchantQueryService())->detail((int) $req['id']);
        if ($detail instanceof \WP_Error) {
            return RestResponse::success($result);
        }

        return RestResponse::success(array_merge($result, ['merchant' => $detail]));
    }

    public function setStatus(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params();
        if (!is_array($params)) {
            $params = $req->get_params();
        }
        $params = is_array($params) ? $params : [];
        $merchantId = (int) ($params['merchant_id'] ?? 0);
        $status = sanitize_key((string) ($params['status'] ?? ''));
        if ($merchantId <= 0) {
            return RestResponse::fail(__('Seller ID is required.', 'sutore-marketplace'), 400);
        }

        $user = get_userdata($merchantId);
        if (!$user || !in_array('merchant', (array) $user->roles, true)) {
            return RestResponse::fail(__('Seller not found.', 'sutore-marketplace'), 404);
        }

        MerchantLevels::setStatus($merchantId, $status);

        return RestResponse::success([
            'message' => __('Updated.', 'sutore-marketplace'),
            'level' => MerchantLevels::statusForUser($merchantId),
            'level_label' => MerchantLevels::labelForStatus(MerchantLevels::statusForUser($merchantId)),
        ]);
    }

    public function createCommissionOverride(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params();
        if (!is_array($params)) {
            $params = $req->get_params();
        }
        $params = is_array($params) ? $params : [];

        $result = (new CommissionOverrideService())->create((int) $req['id'], [
            'commission_percent' => $params['commission_percent'] ?? null,
            'expires_at' => $params['expires_at'] ?? null,
            'note' => $params['note'] ?? '',
            'source' => 'staff',
            'created_by' => get_current_user_id(),
        ]);

        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        $detail = (new AdminMerchantQueryService())->detail((int) $req['id']);

        return RestResponse::success([
            'message' => __('Commission override saved.', 'sutore-marketplace'),
            'id' => (int) $result['id'],
            'merchant' => $detail instanceof \WP_Error ? null : $detail,
        ]);
    }

    public function deleteCommissionOverride(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new CommissionOverrideService())->delete((int) $req['id']);
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function createRestriction(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params();
        if (!is_array($params)) {
            $params = $req->get_params();
        }
        $params = is_array($params) ? $params : [];

        $result = (new RestrictionService())->create($params, get_current_user_id());
        if ($result instanceof \WP_Error) {
            $data = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

            return RestResponse::fail($result->get_error_message(), $status);
        }

        return RestResponse::success($result);
    }

    public function deactivateRestriction(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new RestrictionService())->deactivate((int) $req['id']);
        if ($result instanceof \WP_Error) {
            $data = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

            return RestResponse::fail($result->get_error_message(), $status);
        }

        return RestResponse::success($result);
    }
}
