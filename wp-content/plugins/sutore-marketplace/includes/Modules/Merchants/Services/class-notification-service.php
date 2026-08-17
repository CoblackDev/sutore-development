<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationChannel;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Repositories\NotificationRepository;
use SutoreMarketplace\Modules\Merchants\Settings\NotificationSettings;
use SutoreMarketplace\Modules\Orders\Services\Notifications;
use SutoreMarketplace\Modules\Orders\Settings\Settings as FulfillmentSettings;
use SutoreMarketplace\Modules\Orders\Settings\SmsTemplates;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $repo = new NotificationRepository(),
    ) {
    }

    /**
     * Single merchant-notification entry: panel row, SMS, and (later) push.
     *
     * @param array<string, mixed> $context
     */
    public function dispatch(int $userId, string $type, array $context = [], int $dedupeSeconds = 3600): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $panelOn = NotificationSettings::channelEnabled($type, NotificationChannel::PANEL);
        $smsOn = NotificationSettings::channelEnabled($type, NotificationChannel::SMS);
        $pushOn = NotificationSettings::channelEnabled($type, NotificationChannel::PUSH);

        if (!$panelOn && !$smsOn && !$pushOn) {
            return 0;
        }

        $rendered = NotificationTemplates::render($type, $context);
        $dedupeKey = (string) ($rendered['dedupe_key'] ?? '');

        if ($dedupeKey !== '' && $this->repo->hasRecentDuplicate($userId, $dedupeKey, $dedupeSeconds)) {
            return 0;
        }

        $payload = array_merge($context, [
            'action_url' => $rendered['action_url'],
        ]);

        $id = 0;
        if ($panelOn) {
            $id = $this->repo->insert([
                'user_id' => $userId,
                'type' => sanitize_key($type),
                'category' => sanitize_key($rendered['category']),
                'title' => wp_strip_all_tags($rendered['title']),
                'body' => wp_strip_all_tags($rendered['body']),
                'payload' => wp_json_encode($payload),
                'entity_type' => $rendered['entity_type'] ? sanitize_key($rendered['entity_type']) : null,
                'entity_id' => $rendered['entity_id'],
                'variation_id' => $rendered['variation_id'],
                'dedupe_key' => $dedupeKey !== '' ? $dedupeKey : null,
            ]);

            if ($id > 0) {
                do_action('sutore_marketplace_notification_created', $id, $userId, $type, $context);
            }
        }

        if ($smsOn) {
            $this->sendSms($userId, $type, $context, $rendered);
        }

        if ($pushOn) {
            /**
             * Mobile push is not implemented yet. Listen here when the app ships;
             * the channel flag is stored on the same per-event matrix.
             *
             * @param int                  $userId
             * @param string               $type
             * @param array<string, mixed> $context
             * @param array<string, mixed> $rendered
             * @param int                  $notificationId Panel row id, or 0 if panel was off.
             */
            do_action('sutore_marketplace_notification_push', $userId, $type, $context, $rendered, $id);
        }

        do_action('sutore_marketplace_notification_dispatched', $userId, $type, $context, $rendered, [
            'notification_id' => $id,
            'channels' => [
                NotificationChannel::PANEL => $panelOn,
                NotificationChannel::SMS => $smsOn,
                NotificationChannel::PUSH => $pushOn,
            ],
        ]);

        return $id;
    }

    public function unreadCount(int $userId): int
    {
        return max(0, $this->repo->countUnread($userId));
    }

    /** @return array{items: list<array<string, mixed>>, total: int, unread: int} */
    public function feedForUser(int $userId, int $page = 1, int $perPage = 20, bool $unreadOnly = false): array
    {
        $result = $this->repo->findForUser($userId, $page, $perPage, $unreadOnly);
        $items = [];

        foreach ($result['items'] as $row) {
            $items[] = $this->serializeRow($row);
        }

        return [
            'items' => $items,
            'total' => (int) $result['total'],
            'unread' => $this->unreadCount($userId),
        ];
    }

    public function markRead(int $notificationId, int $userId): true|\WP_Error
    {
        $row = $this->repo->findOwned($notificationId, $userId);
        if (!$row) {
            return new \WP_Error('sutore_notification_missing', __('Notification not found.', 'sutore-marketplace'));
        }

        if ($row->read_at !== null) {
            return true;
        }

        $this->repo->markRead($notificationId, $userId);

        return true;
    }

    public function markAllRead(int $userId): int
    {
        return $this->repo->markAllRead($userId);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $rendered
     */
    private function sendSms(int $userId, string $type, array $context, array $rendered): void
    {
        $phone = Notifications::merchantPhone($userId);
        if ($phone === '') {
            return;
        }

        $templateKey = NotificationType::smsTemplateKey($type);
        if ($templateKey !== null) {
            $message = SmsTemplates::render($templateKey, self::smsVars($context));
        } else {
            $message = trim(
                wp_strip_all_tags((string) ($rendered['title'] ?? ''))
                . "\n"
                . wp_strip_all_tags((string) ($rendered['body'] ?? ''))
            );
        }

        if ($message === '') {
            return;
        }

        Notifications::sms($phone, $message, NotificationType::queuesSms($type));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private static function smsVars(array $context): array
    {
        $price = $context['price'] ?? null;
        if ($price === null || $price === '') {
            $price = $context['net_amount'] ?? '';
        }

        return [
            'order_id' => (string) ($context['order_id'] ?? ''),
            'product' => (string) ($context['product'] ?? ''),
            'price' => self::formatSmsPrice($price),
            'customer_name' => (string) ($context['customer_name'] ?? ''),
            'shipment_type' => (string) ($context['shipment_type'] ?? ''),
            'yurtici_code' => FulfillmentSettings::yurticiCustomerCode(),
            'confirm_hours' => (string) (int) ($context['confirm_hours'] ?? 0),
            'cargo_hours' => (string) (int) ($context['cargo_hours'] ?? 0),
            'track_code' => (string) ($context['track_code'] ?? ''),
        ];
    }

    private static function formatSmsPrice(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric($value)) {
            return MarketplacePricing::formatTl((float) $value);
        }

        return sanitize_text_field((string) $value);
    }

    /** @return array<string, mixed> */
    private function serializeRow(object $row): array
    {
        $payload = json_decode((string) ($row->payload ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        return [
            'id' => (int) $row->id,
            'type' => (string) $row->type,
            'category' => (string) $row->category,
            'title' => (string) $row->title,
            'body' => (string) ($row->body ?? ''),
            'variation_id' => $row->variation_id ? (int) $row->variation_id : null,
            'entity_type' => $row->entity_type ? (string) $row->entity_type : null,
            'entity_id' => $row->entity_id ? (int) $row->entity_id : null,
            'action_url' => esc_url_raw((string) ($payload['action_url'] ?? '')),
            'is_read' => $row->read_at !== null,
            'read_at' => $row->read_at ? (string) $row->read_at : null,
            'created_at' => (string) $row->created_at,
        ];
    }
}
