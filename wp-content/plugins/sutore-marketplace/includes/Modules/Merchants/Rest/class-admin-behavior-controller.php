<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Rest;

use SutoreMarketplace\Modules\Listings\Domain\ListingEventType;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService;
use SutoreMarketplace\Shared\Rest\AdminPermission;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class AdminBehaviorController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'sutore-marketplace/v1';

        register_rest_route($ns, '/admin/behavior/events/(?P<id>\d+)/reverse', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'reverseEvent'],
                'permission_callback' => [AdminPermission::class, 'canManage'],
            ],
        ]);
    }

    public function reverseEvent(\WP_REST_Request $req): \WP_REST_Response
    {
        $eventId = (int) $req['id'];
        $params = $req->get_json_params() ?: $req->get_params();
        $note = sanitize_textarea_field((string) ($params['note'] ?? ''));

        global $wpdb;
        $table = (new ListingEventsRepository())->table();
        $target = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $table . ' WHERE id = %d',
            $eventId
        ));

        if (!$target) {
            return RestResponse::fail(__('Event not found.', 'sutore-marketplace'), 404, 'sutore_event_missing');
        }

        if (!in_array((string) $target->event_type, ListingEventType::scorableTypes(), true)) {
            return RestResponse::fail(
                __('Only scorable product events can be reversed.', 'sutore-marketplace'),
                400,
                'sutore_event_not_scorable'
            );
        }

        $scores = new BehaviorScoreService();
        if (!$scores->reverseEvent($eventId, $note)) {
            return RestResponse::fail(__('Could not reverse event.', 'sutore-marketplace'), 500, 'sutore_event_reverse_failed');
        }

        $merchantId = (int) ($target->merchant_id ?? 0);
        if ($merchantId > 0) {
            $scores->refreshMerchant($merchantId);
        }

        return RestResponse::success([
            'message' => __('Score record reversed.', 'sutore-marketplace'),
            'merchant_id' => $merchantId,
            'behavior' => $merchantId > 0 ? $scores->snapshotForMerchant($merchantId) : null,
        ]);
    }
}
