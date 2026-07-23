<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Rest;

use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Services\CommissionResolver;
use SutoreMarketplace\Modules\Merchants\Services\MerchantBalanceService;
use SutoreMarketplace\Modules\Merchants\Services\MerchantProfileService;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Shared\Data\TrDistricts;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Shared\Rest\RestResponse;
use SutoreMarketplace\Shared\Settings\Settings;

final class MerchantsController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/notifications', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'notificationsList'],
                'permission_callback' => [$this, 'canViewDashboard'],
            ],
        ]);
        register_rest_route($ns, '/notifications/unread-count', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'unreadCount'],
                'permission_callback' => [$this, 'canViewDashboard'],
            ],
        ]);
        register_rest_route($ns, '/notifications/read-all', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'markAllRead'],
                'permission_callback' => [$this, 'canViewDashboard'],
            ],
        ]);
        register_rest_route($ns, '/notifications/(?P<id>\d+)/read', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'markRead'],
                'permission_callback' => [$this, 'canViewDashboard'],
            ],
        ]);

        register_rest_route($ns, '/merchant/profile', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getProfile'],
                'permission_callback' => [$this, 'canAccessLoggedIn'],
            ],
            [
                'methods' => 'PUT,PATCH',
                'callback' => [$this, 'saveProfile'],
                'permission_callback' => [$this, 'canAccessLoggedIn'],
            ],
        ]);

        register_rest_route($ns, '/merchant/districts', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'districts'],
                'permission_callback' => [$this, 'canAccessLoggedIn'],
            ],
        ]);

        register_rest_route($ns, '/merchant/balance', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'balance'],
                'permission_callback' => [$this, 'canViewDashboard'],
            ],
        ]);
    }

    public function canAccessLoggedIn(): bool
    {
        return is_user_logged_in();
    }

    public function canViewDashboard(): bool
    {
        return is_user_logged_in() && MerchantMeta::canViewMerchantDashboard(get_current_user_id());
    }

    public function notificationsList(\WP_REST_Request $req): \WP_REST_Response
    {
        $page = max(1, (int) ($req->get_param('page') ?: 1));
        $perPage = min(50, max(1, (int) ($req->get_param('per_page') ?: 20)));
        $unreadOnly = !empty($req->get_param('unread_only'));
        $data = (new NotificationService())->feedForUser(get_current_user_id(), $page, $perPage, $unreadOnly);

        return RestResponse::success($data);
    }

    public function unreadCount(\WP_REST_Request $req): \WP_REST_Response
    {
        return RestResponse::success([
            'unread' => (new NotificationService())->unreadCount(get_current_user_id()),
        ]);
    }

    public function markRead(\WP_REST_Request $req): \WP_REST_Response
    {
        $service = new NotificationService();
        $result = $service->markRead((int) $req['id'], get_current_user_id());
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['unread' => $service->unreadCount(get_current_user_id())]);
    }

    public function markAllRead(\WP_REST_Request $req): \WP_REST_Response
    {
        $service = new NotificationService();
        $updated = $service->markAllRead(get_current_user_id());

        return RestResponse::success([
            'updated' => $updated,
            'unread' => 0,
        ]);
    }

    public function getProfile(\WP_REST_Request $req): \WP_REST_Response
    {
        $userId = get_current_user_id();
        $user = get_userdata($userId);
        $profile = MerchantMeta::readProfile($userId);
        $level = MerchantLevels::statusForUser($userId);
        $tcMode = Settings::tcVerificationMode();
        $isMerchant = $user ? in_array('merchant', (array) $user->roles, true) : false;
        $isTcVerified = MerchantMeta::isTcVerified($userId);

        $cities = [];
        if (function_exists('WC') && WC()->countries) {
            $states = WC()->countries->get_states('TR');
            if (is_array($states)) {
                foreach ($states as $code => $label) {
                    $cities[] = [
                        'code' => (string) $code,
                        'label' => (string) $label,
                    ];
                }
            }
        }

        $intro = '';
        $note = '';
        if (!$isMerchant) {
            $intro = match ($tcMode) {
                'manual' => __('To access the merchant area, complete the information below. TC verification is completed with admin approval.', 'sutore-marketplace'),
                'algorithm' => __('To access the merchant area, complete the information below. In the development environment, TC verification uses the algorithm.', 'sutore-marketplace'),
                default => __('To access the merchant area, you must complete the information below and have your TC identity verified via the NVI service.', 'sutore-marketplace'),
            };
        }
        if (!$isTcVerified) {
            $note = match ($tcMode) {
                'manual' => __('After your information is saved, TC verification is performed by an administrator. Once approved, you move to Confirmed seller level.', 'sutore-marketplace'),
                'algorithm' => __('In development mode, the TC number is checked algorithmically; the NVI service is not contacted.', 'sutore-marketplace'),
                default => __('During registration, your TC identity number, first name, last name, and birth year are verified with the Directorate General of Population and Citizenship Affairs service. If the NVI public service is unavailable, change the verification mode from the admin panel.', 'sutore-marketplace'),
            };
        }

        $commission = (new CommissionResolver())->forUser($userId);
        $overrideLabel = '';
        if ($commission['is_overridden']) {
            $overrideLabel = __('Commission discount active', 'sutore-marketplace');
        }

        return RestResponse::success([
            'profile' => $profile,
            'tc_verified' => $isTcVerified,
            'profile_complete' => MerchantMeta::isProfileComplete($userId),
            'level' => $level,
            'level_label' => MerchantLevels::labelForStatus($level),
            'commission_percent' => (float) $commission['percent'],
            'commission_level_percent' => (float) $commission['level_percent'],
            'commission_overridden' => (bool) $commission['is_overridden'],
            'commission_override_expires_at' => $commission['expires_at'],
            'commission_override_label' => $overrideLabel,
            'can_view_dashboard' => MerchantMeta::canViewMerchantDashboard($userId),
            'is_merchant' => $isMerchant,
            'tc_mode' => $tcMode,
            'cities' => $cities,
            'birth_year_max' => (int) gmdate('Y'),
            'intro' => $intro,
            'note' => $note,
            'submit_label' => $isMerchant
                ? __('Update My Info', 'sutore-marketplace')
                : __('Save My Info', 'sutore-marketplace'),
        ]);
    }

    public function saveProfile(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params() ?: $req->get_params();
        if (!is_array($params)) {
            $params = [];
        }
        $result = (new MerchantProfileService())->save(get_current_user_id(), $params);
        if ($result instanceof \WP_Error) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function districts(\WP_REST_Request $req): \WP_REST_Response
    {
        $city = sanitize_text_field((string) ($req->get_param('city') ?: ''));

        return RestResponse::success([
            'districts' => TrDistricts::forCity($city),
        ]);
    }

    public function balance(\WP_REST_Request $req): \WP_REST_Response
    {
        $summary = (new MerchantBalanceService())->summaryForMerchant(get_current_user_id());
        $recent = [];
        foreach ($summary['recent'] as $line) {
            $recent[] = [
                'product_title' => (string) ($line->product_title ?? ''),
                'listing_id' => (int) ($line->listing_id ?? 0),
                'variation_id' => (int) ($line->variation_id ?? 0),
                'net_amount' => (float) ($line->net_amount ?? 0),
                'formatted_net' => number_format((float) ($line->net_amount ?? 0), 0, ',', '.') . ' TL',
                'payout_status' => (string) ($line->payout_status ?? ''),
                'payout_status_label' => PayoutStatus::label((string) ($line->payout_status ?? '')),
            ];
        }
        $summary['recent'] = $recent;

        return RestResponse::success($summary);
    }
}
