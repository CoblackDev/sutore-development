<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Shared\Effects\OutboundEffectService;
use SutoreMarketplace\Shared\Effects\OutboundEffectType;
use SutoreMarketplace\Shared\Security\OutboundUrl;

final class WebhookNotifier
{
    /** @param array<string, mixed> $payload */
    public static function dispatch(string $event, array $payload, string $operationId = ''): void
    {
        $url = trim((string) Settings::get('webhook_url', ''));
        if ($url === '' || !OutboundUrl::isSafe($url)) {
            return;
        }

        $operationId = $operationId !== ''
            ? sanitize_text_field($operationId)
            : (string) ($payload['operation_id'] ?? '');
        if ($operationId === '') {
            $operationId = wp_generate_uuid4();
        }

        $payload['operation_id'] = $operationId;
        $payload['event_id'] = $operationId;

        (new OutboundEffectService())->enqueue(
            OutboundEffectType::WEBHOOK,
            [
                'url' => $url,
                'event' => $event,
                'event_id' => $operationId,
                'timestamp' => current_time('mysql'),
                'payload' => $payload,
            ],
            'webhook:' . $event . ':' . $operationId
        );
    }
}
