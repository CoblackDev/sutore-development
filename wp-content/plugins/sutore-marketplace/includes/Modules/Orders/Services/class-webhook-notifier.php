<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Services;

use SutoreMarketplace\Modules\Orders\Settings\Settings;
use SutoreMarketplace\Shared\Security\SecretBox;

final class WebhookNotifier
{
    /** @param array<string, mixed> $payload */
    public static function dispatch(string $event, array $payload): void
    {
        $url = trim((string) Settings::get('webhook_url', ''));
        if ($url === '') {
            return;
        }

        $body = [
            'event' => $event,
            'timestamp' => current_time('mysql'),
            'payload' => $payload,
        ];

        $args = [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ];

        $secret = trim(SecretBox::open((string) Settings::get('webhook_secret', '')));
        if ($secret !== '') {
            $args['headers']['X-Sutore-Signature'] = hash_hmac('sha256', (string) $args['body'], $secret);
        }

        wp_remote_post($url, $args);
        do_action('sutore_marketplace_fulfillment_webhook_sent', $event, $payload, $url);
    }
}
