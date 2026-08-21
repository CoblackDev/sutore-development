<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms;

use SutoreMarketplace\Shared\Effects\OutboundEffectService;
use SutoreMarketplace\Shared\Effects\OutboundEffectType;

/**
 * Deferred SMS delivery via the shared outbound effects outbox.
 * Action Scheduler processes effect IDs — never on web request shutdown.
 */
final class SmsQueue
{
    public static function register(): void
    {
        // Effects worker is registered by OutboundEffectService::register().
    }

    public static function enqueue(string $phone, string $message): void
    {
        $phone = trim($phone);
        $message = trim($message);
        if ($phone === '' || $message === '') {
            return;
        }

        (new OutboundEffectService())->enqueue(
            OutboundEffectType::SMS,
            [
                'phone' => $phone,
                'message' => $message,
            ],
            'sms:' . hash('sha256', $phone . "\0" . $message . "\0" . wp_generate_uuid4())
        );
    }
}
