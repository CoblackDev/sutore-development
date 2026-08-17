<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Rest;

use SutoreMarketplace\Modules\Otp\Domain\OtpPurpose;
use SutoreMarketplace\Modules\Otp\Services\AccountSecurityService;
use SutoreMarketplace\Modules\Otp\Services\OtpService;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class OtpController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/otp/config', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'config'],
                'permission_callback' => [$this, 'loggedIn'],
            ],
        ]);

        register_rest_route($ns, '/otp/request', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'request'],
                'permission_callback' => [$this, 'loggedIn'],
            ],
        ]);

        register_rest_route($ns, '/account/details', [
            [
                'methods' => 'PUT,PATCH',
                'callback' => [$this, 'updateDetails'],
                'permission_callback' => [$this, 'loggedIn'],
            ],
        ]);

        register_rest_route($ns, '/account/password', [
            [
                'methods' => 'PUT,PATCH',
                'callback' => [$this, 'changePassword'],
                'permission_callback' => [$this, 'loggedIn'],
            ],
        ]);

        register_rest_route($ns, '/account', [
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteAccount'],
                'permission_callback' => [$this, 'loggedIn'],
            ],
        ]);
    }

    public function loggedIn(): bool
    {
        return is_user_logged_in();
    }

    public function config(\WP_REST_Request $req): \WP_REST_Response
    {
        return RestResponse::success(AccountSecurityService::publicConfig());
    }

    public function request(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $this->params($req);
        $purpose = sanitize_key((string) ($params['purpose'] ?? ''));
        if (!OtpPurpose::isValid($purpose)) {
            return RestResponse::fail(__('Invalid verification purpose.', 'sutore-marketplace'), 400, 'sutore_otp_invalid_purpose');
        }

        unset($params['purpose']);
        $result = (new OtpService())->request(get_current_user_id(), $purpose, $params);
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function updateDetails(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new AccountSecurityService())->updateDetails(get_current_user_id(), $this->params($req));
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function changePassword(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new AccountSecurityService())->changePassword(get_current_user_id(), $this->params($req));
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function deleteAccount(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new AccountSecurityService())->deleteAccount(get_current_user_id(), $this->params($req));
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
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
