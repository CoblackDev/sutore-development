<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms;

/**
 * Deferred SMS delivery for fan-out paths (cron, digest, AskMerchants).
 * Uses Action Scheduler when available; otherwise sends synchronously
 * so behavior matches the previous direct SmsGateway::send path.
 */
final class SmsQueue
{
    public const HOOK = 'sutore_marketplace_send_sms';

    public const GROUP = 'sutore-marketplace-sms';

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'deliver'], 10, 2);
    }

    public static function enqueue(string $phone, string $message): void
    {
        $phone = trim($phone);
        $message = trim($message);
        if ($phone === '' || $message === '') {
            return;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, [$phone, $message], self::GROUP);
            self::dispatchQueueOnShutdown();

            return;
        }

        SmsGateway::send($phone, $message);
    }

    public static function deliver(string $phone, string $message): void
    {
        SmsGateway::send($phone, $message);
    }

    private static function dispatchQueueOnShutdown(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        add_action('shutdown', static function (): void {
            if (!class_exists(\ActionScheduler_QueueRunner::class)) {
                return;
            }

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            \ActionScheduler_QueueRunner::instance()->run('Sutore Marketplace SMS');
        }, 999);
    }
}
