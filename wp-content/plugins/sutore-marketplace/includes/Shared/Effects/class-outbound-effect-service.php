<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Effects;

use SutoreMarketplace\Modules\Orders\Settings\Settings as OrderSettings;
use SutoreMarketplace\Shared\Security\OutboundUrl;
use SutoreMarketplace\Shared\Security\SecretBox;
use SutoreMarketplace\Shared\Sms\IysClient;
use SutoreMarketplace\Shared\Sms\IysPayload;
use SutoreMarketplace\Shared\Sms\SmsGateway;

/**
 * Persistent outbound effects (SMS / webhook / İYS) + Action Scheduler worker.
 * Domain code records an effect; delivery never runs on request shutdown.
 */
final class OutboundEffectService
{
    public const HOOK = 'sutore_marketplace_process_effect';

    public const GROUP = 'sutore-marketplace-effects';

    public const MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly OutboundEffectRepository $repo = new OutboundEffectRepository(),
    ) {
    }

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'processHook'], 10, 1);
        add_action('sutore_marketplace_effects_retry', [self::class, 'drainDue']);
        if (!wp_next_scheduled('sutore_marketplace_effects_retry')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'sutore_marketplace_effects_retry');
        }
    }

    public static function processHook(int $effectId): void
    {
        (new self())->process($effectId);
    }

    public static function drainDue(): void
    {
        $service = new self();
        foreach ($service->repo->dueForRetry(40) as $row) {
            $service->process((int) $row->id);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $effectType, array $payload, string $dedupeKey = ''): int
    {
        if (!in_array($effectType, OutboundEffectType::all(), true)) {
            return 0;
        }

        if ($dedupeKey === '') {
            $dedupeKey = $effectType . ':' . wp_generate_uuid4();
        }

        $existing = $this->repo->findByDedupe($dedupeKey);
        if ($existing) {
            $status = (string) ($existing->status ?? '');
            if ($status === OutboundEffectStatus::DONE || $status === OutboundEffectStatus::PROCESSING) {
                return (int) $existing->id;
            }
            if ($status === OutboundEffectStatus::PENDING) {
                $this->schedule((int) $existing->id);

                return (int) $existing->id;
            }
        }

        $id = $this->repo->insert($effectType, $payload, $dedupeKey);
        if ($id <= 0) {
            return 0;
        }

        $this->schedule($id);

        return $id;
    }

    public function process(int $effectId): void
    {
        if ($effectId <= 0) {
            return;
        }

        if (!$this->repo->claim($effectId)) {
            return;
        }

        $row = $this->repo->find($effectId);
        if (!$row) {
            return;
        }

        $payload = json_decode((string) ($row->payload ?? '{}'), true);
        if (!is_array($payload)) {
            $this->repo->markFailed($effectId, 'Invalid payload JSON.', null, true);

            return;
        }

        $ok = match ((string) $row->effect_type) {
            OutboundEffectType::SMS => $this->deliverSms($payload),
            OutboundEffectType::WEBHOOK => $this->deliverWebhook($payload),
            OutboundEffectType::IYS => $this->deliverIys($payload),
            default => false,
        };

        if ($ok === true) {
            $this->repo->markDone($effectId);

            return;
        }

        $attempts = (int) ($row->attempts ?? 0);
        $error = is_string($ok) ? $ok : 'Delivery failed.';
        $permanent = $attempts >= self::MAX_ATTEMPTS;
        $next = $permanent ? null : $this->backoffAt($attempts);
        $this->repo->markFailed($effectId, $error, $next, $permanent);
        if (!$permanent) {
            $this->schedule($effectId, $this->backoffSeconds($attempts));
        }
    }

    private function schedule(int $effectId, int $delaySeconds = 0): void
    {
        if (!function_exists('as_enqueue_async_action')
            || \SutoreMarketplace\Shared\Sms\Settings\SmsSimulationSettings::isEnabled()
        ) {
            // No AS, or SMS simulation (tests/local): process inline — never on shutdown.
            if ($delaySeconds <= 0) {
                $this->process($effectId);
            }

            return;
        }

        if ($delaySeconds > 0 && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delaySeconds, self::HOOK, [$effectId], self::GROUP);

            return;
        }

        as_enqueue_async_action(self::HOOK, [$effectId], self::GROUP);
    }

    /** @param array<string, mixed> $payload */
    private function deliverSms(array $payload): true|string
    {
        $phone = trim((string) ($payload['phone'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));
        if ($phone === '' || $message === '') {
            return 'SMS payload missing phone or message.';
        }

        return SmsGateway::send($phone, $message) ? true : 'SMS provider rejected the message.';
    }

    /** @param array<string, mixed> $payload */
    private function deliverWebhook(array $payload): true|string
    {
        $url = trim((string) ($payload['url'] ?? ''));
        $event = trim((string) ($payload['event'] ?? ''));
        $eventId = trim((string) ($payload['event_id'] ?? ''));
        $bodyPayload = $payload['payload'] ?? [];
        if ($url === '' || !OutboundUrl::isSafe($url) || $event === '') {
            return 'Webhook URL is missing or unsafe.';
        }
        if ($eventId === '') {
            $eventId = wp_generate_uuid4();
        }

        $body = [
            'event' => $event,
            'event_id' => $eventId,
            'timestamp' => (string) ($payload['timestamp'] ?? current_time('mysql')),
            'payload' => is_array($bodyPayload) ? $bodyPayload : [],
        ];
        $encoded = wp_json_encode($body);
        if (!is_string($encoded) || $encoded === '') {
            return 'Webhook body could not be encoded.';
        }

        $args = [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Sutore-Event-Id' => $eventId,
            ],
            'body' => $encoded,
        ];
        $secret = trim(SecretBox::open((string) OrderSettings::get('webhook_secret', '')));
        if ($secret !== '') {
            $args['headers']['X-Sutore-Signature'] = hash_hmac('sha256', $encoded, $secret);
        }

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            do_action('sutore_marketplace_fulfillment_webhook_failed', $event, $bodyPayload, $url, 0);

            return $response->get_error_message();
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            do_action('sutore_marketplace_fulfillment_webhook_failed', $event, $bodyPayload, $url, $status);

            return 'HTTP ' . $status;
        }

        do_action('sutore_marketplace_fulfillment_webhook_sent', $event, $bodyPayload, $url);

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function deliverIys(array $payload): true|string
    {
        $status = (string) ($payload['status'] ?? '');
        $identifiers = $payload['identifiers'] ?? [];
        if ($status !== IysPayload::STATUS_GRANT && $status !== IysPayload::STATUS_REVOKE) {
            return 'Invalid İYS status.';
        }
        if (!is_array($identifiers) || $identifiers === []) {
            return 'İYS identifiers missing.';
        }

        /** @var list<string> $identifiers */
        $identifiers = array_values(array_filter(array_map('strval', $identifiers)));
        $ok = (new IysClient())->submit($identifiers, $status);

        return $ok ? true : 'İYS provider rejected the request.';
    }

    private function backoffSeconds(int $attempts): int
    {
        $attempts = max(1, $attempts);

        return min(6 * HOUR_IN_SECONDS, (int) (30 * (2 ** min(6, $attempts - 1))));
    }

    private function backoffAt(int $attempts): string
    {
        return wp_date('Y-m-d H:i:s', time() + $this->backoffSeconds($attempts));
    }
}
