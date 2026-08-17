<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Rest;

use SutoreMarketplace\Admin\AdminMenu;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutSchedule;
use SutoreMarketplace\Modules\Merchants\Services\PayoutExportService;
use SutoreMarketplace\Modules\Orders\Domain\StaffQueueFilter;
use SutoreMarketplace\Modules\Orders\Services\FulfillmentService;
use SutoreMarketplace\Modules\Orders\Services\StaffFulfillmentPresenter;
use SutoreMarketplace\Modules\Shipping\Domain\ShipmentType;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class FulfillmentsController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/fulfillments', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'query'],
                'permission_callback' => [$this, 'canAccessMerchantOrStaff'],
            ],
        ]);

        register_rest_route($ns, '/fulfillments/bulk-actions', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'bulkAdminAction'],
                'permission_callback' => [$this, 'canAccessStaff'],
            ],
        ]);

        register_rest_route($ns, '/fulfillments/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'details'],
                'permission_callback' => [$this, 'canAccessMerchantOrStaff'],
            ],
        ]);

        register_rest_route($ns, '/fulfillments/(?P<id>\d+)/confirm', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'confirm'],
                'permission_callback' => [$this, 'canAccessMerchant'],
            ],
        ]);

        register_rest_route($ns, '/fulfillments/(?P<id>\d+)/ship', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'ship'],
                'permission_callback' => [$this, 'canAccessMerchant'],
            ],
        ]);

        register_rest_route($ns, '/fulfillments/(?P<id>\d+)/cancel', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'cancel'],
                'permission_callback' => [$this, 'canAccessMerchant'],
            ],
        ]);

        register_rest_route($ns, '/fulfillments/(?P<id>\d+)/actions', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'adminAction'],
                'permission_callback' => [$this, 'canAccessStaff'],
            ],
        ]);

        register_rest_route($ns, '/fulfillments/(?P<id>\d+)/swap-candidates', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'swapCandidates'],
                'permission_callback' => [$this, 'canAccessStaff'],
            ],
        ]);

        register_rest_route($ns, '/admin/processing-orders', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'processingOrders'],
                'permission_callback' => [$this, 'canAccessStaff'],
            ],
        ]);
    }

    public function canAccessMerchant(): bool
    {
        return is_user_logged_in();
    }

    public function canAccessStaff(): bool
    {
        return is_user_logged_in() && current_user_can(AdminMenu::CAP);
    }

    public function canAccessMerchantOrStaff(): bool
    {
        return is_user_logged_in();
    }

    public function query(\WP_REST_Request $req): \WP_REST_Response
    {
        $orderby = sanitize_key((string) ($req->get_param('orderby') ?: 'id_desc'));
        $allowedOrderby = [
            'id_desc',
            'id_asc',
            'asking_asc',
            'asking_desc',
            'deadline_asc',
            'deadline_desc',
            'sold_at_desc',
            'sold_at_asc',
            'status_asc',
        ];
        if (!in_array($orderby, $allowedOrderby, true)) {
            $orderby = 'id_desc';
        }

        $campaign = sanitize_key((string) ($req->get_param('campaign') ?: ''));
        if (!in_array($campaign, ['none', 'offer', 'active'], true)) {
            $campaign = '';
        }
        $isSourcing = sanitize_key((string) ($req->get_param('is_sourcing') ?: ''));
        if (!in_array($isSourcing, ['yes', 'no'], true)) {
            $isSourcing = '';
        }
        $shipmentType = sanitize_key((string) ($req->get_param('shipment_type') ?: ''));
        if ($shipmentType !== 'none' && !ShipmentType::isValid($shipmentType)) {
            $shipmentType = '';
        }
        $isImported = sanitize_key((string) ($req->get_param('is_imported') ?: ''));
        if (!in_array($isImported, ['yes', 'no'], true)) {
            $isImported = '';
        }

        $args = [
            'status' => sanitize_key((string) ($req->get_param('status') ?: '')),
            'queue' => sanitize_key((string) ($req->get_param('queue') ?: '')),
            'payout_status' => sanitize_key((string) ($req->get_param('payout_status') ?: '')),
            'campaign' => $campaign,
            'is_sourcing' => $isSourcing,
            'shipment_type' => $shipmentType,
            'is_imported' => $isImported,
            'search' => sanitize_text_field((string) ($req->get_param('search') ?: '')),
            'orderby' => $orderby,
            'page' => max(1, (int) ($req->get_param('page') ?: 1)),
            'per_page' => min(100, max(1, (int) ($req->get_param('per_page') ?: 20))),
            'sold_from' => PayoutSchedule::normalizeDate($req->get_param('sold_from')),
            'sold_to' => PayoutSchedule::normalizeDate($req->get_param('sold_to')),
            'payout_due' => $req->get_param('payout_due') === '1' || $req->get_param('payout_due') === 1 || $req->get_param('payout_due') === true,
        ];
        if ($args['status'] === '') {
            unset($args['status']);
        }
        if ($args['queue'] === '' || !StaffQueueFilter::isValid($args['queue'])) {
            unset($args['queue']);
        }
        $payoutStatus = (string) ($args['payout_status'] ?? '');
        if ($payoutStatus !== 'none' && !PayoutStatus::isValid($payoutStatus)) {
            unset($args['payout_status']);
        }
        if ($args['campaign'] === '') {
            unset($args['campaign']);
        }
        if ($args['is_sourcing'] === '') {
            unset($args['is_sourcing']);
        }
        if ($args['shipment_type'] === '') {
            unset($args['shipment_type']);
        }
        if ($args['is_imported'] === '') {
            unset($args['is_imported']);
        }
        if ($args['search'] === '') {
            unset($args['search']);
        }
        if ($args['sold_from'] === '') {
            unset($args['sold_from']);
        }
        if ($args['sold_to'] === '') {
            unset($args['sold_to']);
        }
        if (empty($args['payout_due'])) {
            unset($args['payout_due']);
        } else {
            $args['payout_status'] = PayoutStatus::PENDING;
        }
        // Queue filters take precedence over a single status.
        if (!empty($args['queue'])) {
            unset($args['status']);
        }

        $isStaff = current_user_can(AdminMenu::CAP);
        if (!$isStaff) {
            $args['merchant_id'] = get_current_user_id();
            unset($args['queue']);
        } elseif ($req->get_param('merchant_id')) {
            $args['merchant_id'] = (int) $req->get_param('merchant_id');
        }

        $presenter = new StaffFulfillmentPresenter();
        if ($isStaff && sanitize_key((string) $req->get_param('export')) === 'csv') {
            $export = (new PayoutExportService())->csv($args);

            return RestResponse::success($export);
        }

        $payload = $isStaff
            ? $presenter->presentStaffQuery($args)
            : $presenter->presentMerchantQuery($args);

        return RestResponse::success($payload);
    }

    public function details(\WP_REST_Request $req): \WP_REST_Response
    {
        $id = (int) $req['id'];

        if (current_user_can(AdminMenu::CAP)) {
            $result = (new StaffFulfillmentPresenter())->presentDetail($id);
            if (is_wp_error($result)) {
                return RestResponse::fromWpError($result);
            }

            return RestResponse::success($result);
        }

        $result = (new FulfillmentService())->merchantDetails($id, get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }

    public function confirm(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new FulfillmentService())->merchantConfirmSale((int) $req['id'], get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('You confirmed your sale.', 'sutore-marketplace')]);
    }

    public function ship(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params() ?: $req->get_params();
        $code = sanitize_text_field((string) ($params['shipment_code'] ?? ''));
        $result = (new FulfillmentService())->merchantSubmitShipment((int) $req['id'], get_current_user_id(), $code);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('You have sent your product to our center.', 'sutore-marketplace')]);
    }

    public function cancel(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new FulfillmentService())->merchantCancelSale((int) $req['id'], get_current_user_id());
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Sale cancelled. The order was moved back to the pre-order board.', 'sutore-marketplace')]);
    }

    public function adminAction(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params() ?: $req->get_params();
        $id = (int) $req['id'];
        $action = sanitize_key((string) ($params['workflow_action'] ?? ''));
        $service = new FulfillmentService();

        $result = $service->runStaffWorkflowAction($id, $action, is_array($params) ? $params : []);

        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(['message' => __('Updated.', 'sutore-marketplace')]);
    }

    public function bulkAdminAction(\WP_REST_Request $req): \WP_REST_Response
    {
        $params = $req->get_json_params() ?: $req->get_params();
        $action = sanitize_key((string) ($params['workflow_action'] ?? ''));
        $ids = $params['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }

        $extra = [];
        if (isset($params['payment_ref'])) {
            $extra['payment_ref'] = sanitize_text_field((string) $params['payment_ref']);
        }
        $result = (new FulfillmentService())->bulkStaffWorkflowAction($ids, $action, $extra);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success(array_merge($result, [
            'message' => sprintf(
                /* translators: %d: number of products updated */
                _n('%d product updated.', '%d products updated.', (int) $result['updated'], 'sutore-marketplace'),
                (int) $result['updated']
            ),
        ]));
    }

    public function processingOrders(\WP_REST_Request $req): \WP_REST_Response
    {
        $limit = min(100, max(1, (int) ($req->get_param('per_page') ?: 50)));
        $items = (new FulfillmentService())->listProcessingOrdersForAttach($limit, [
            'variation_id' => (int) ($req->get_param('variation_id') ?: 0),
            'search' => sanitize_text_field((string) ($req->get_param('search') ?: '')),
        ]);

        return RestResponse::success([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    public function swapCandidates(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = (new FulfillmentService())->listSwapCandidates((int) $req['id'], [
            'search' => sanitize_text_field((string) ($req->get_param('search') ?: '')),
            'per_page' => (int) ($req->get_param('per_page') ?: 30),
        ]);
        if (is_wp_error($result)) {
            return RestResponse::fromWpError($result);
        }

        return RestResponse::success($result);
    }
}
