<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Repositories\NotificationRepository;
use SutoreMarketplace\Modules\Merchants\Settings\NotificationSettings;

final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $repo = new NotificationRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function dispatch(int $userId, string $type, array $context = [], int $dedupeSeconds = 3600): int
    {
        if ($userId <= 0) {
            return 0;
        }

        if (!NotificationSettings::eventEnabled($type)) {
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

        $id = $this->repo->insert([
            'user_id' => $userId,
            'type' => sanitize_key($type),
            'category' => sanitize_key($rendered['category']),
            'title' => wp_strip_all_tags($rendered['title']),
            'body' => wp_strip_all_tags($rendered['body']),
            'payload' => wp_json_encode($payload),
            'entity_type' => $rendered['entity_type'] ? sanitize_key($rendered['entity_type']) : null,
            'entity_id' => $rendered['entity_id'],
            'listing_id' => $rendered['listing_id'],
            'dedupe_key' => $dedupeKey !== '' ? $dedupeKey : null,
        ]);

        if ($id > 0) {
            do_action('sutore_marketplace_notification_created', $id, $userId, $type, $context);
        }

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
            'listing_id' => $row->listing_id ? (int) $row->listing_id : null,
            'entity_type' => $row->entity_type ? (string) $row->entity_type : null,
            'entity_id' => $row->entity_id ? (int) $row->entity_id : null,
            'action_url' => esc_url_raw((string) ($payload['action_url'] ?? '')),
            'is_read' => $row->read_at !== null,
            'read_at' => $row->read_at ? (string) $row->read_at : null,
            'created_at' => (string) $row->created_at,
        ];
    }
}
