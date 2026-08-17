<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Orders\Settings;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationChannel;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class Settings
{
    public const OPTION = 'sutore_marketplace_fulfillment_settings';
    public const VERSION = 9;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'settings_version' => self::VERSION,
            'admin_notification_phones' => '',
            'express_notification_phone' => '',
            'sms_enabled' => true,
            'yurtici_customer_code' => '981317779',
            'confirm_deadline_hours' => 24,
            'confirm_deadline_hours_verified' => 0,
            'confirm_deadline_hours_premium' => 0,
            'confirm_grace_hours' => 24,
            'cargo_deadline_hours_standard' => 48,
            'cargo_deadline_hours_express' => 24,
            'cargo_deadline_hours_international' => 72,
            'cargo_reminder_hours' => 24,
            'return_window_days' => 14,
            'shipment_code_pattern' => '^\d{12}$',
            'deadline_cron_schedule' => 'twicedaily',
            'use_business_days_for_deadlines' => false,
            'require_admin_payment_confirm' => true,
            'sourcing_price_tolerance_percent' => 10,
            'default_fulfillment_filter' => 'payment',
            'swap_allowed_statuses' => 'payment,sold',
            'allow_manual_order_link' => true,
            'payout_min_hold_days' => 7,
            'payout_weekdays' => [3],
            'express_block_carrier_shipment' => true,
            'international_invoice_required' => true,
            'suspension_threshold' => 0,
            'webhook_url' => '',
            'webhook_secret' => '',
            'event_retention_days' => 365,
            'sms_events' => SmsTemplates::defaultEventFlags(),
            'sms_templates' => [],
            'merchant_notifications_enabled' => true,
            'merchant_notification_channels' => NotificationType::defaultChannels(),
        ];
    }

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    public static function ensureDefaults(): void
    {
        $current = get_option(self::OPTION);
        if (!is_array($current)) {
            $current = [];
        }

        $merged = self::normalize($current);
        if ($merged == $current) {
            return;
        }

        update_option(self::OPTION, $merged);
        self::$memo = null;
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        self::$memo = self::normalize($stored);

        return self::$memo;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $default ?? (self::defaults()[$key] ?? null);
    }

    /** @param array<string, mixed> $patch */
    public static function update(array $patch): array
    {
        $merged = array_replace_recursive(self::all(), $patch);
        $merged['settings_version'] = self::VERSION;
        if (array_key_exists('payout_weekdays', $patch)) {
            $merged['payout_weekdays'] = $patch['payout_weekdays'];
        }
        if (array_key_exists('merchant_notification_channels', $patch)) {
            $merged['merchant_notification_channels'] = $patch['merchant_notification_channels'];
        }
        if (array_key_exists('sms_events', $patch)) {
            $merged['sms_events'] = $patch['sms_events'];
        }
        unset($merged['merchant_notification_events']);
        update_option(self::OPTION, $merged);
        self::$memo = $merged;

        return $merged;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private static function normalize(array $stored): array
    {
        $merged = array_replace_recursive(self::defaults(), $stored);
        $merged['settings_version'] = self::VERSION;
        unset($merged['auto_sourcing_on_suspend'], $merged['auto_sourcing_on_split'], $merged['merchant_notification_events']);
        if (isset($merged['swap_allowed_statuses']) && is_string($merged['swap_allowed_statuses'])) {
            $merged['swap_allowed_statuses'] = str_replace(
                ['payment_pending', 'awaiting_seller'],
                ['payment', 'sold'],
                $merged['swap_allowed_statuses']
            );
        }

        $merged['merchant_notification_channels'] = self::migrateNotificationChannels($stored);
        $merged['sms_events'] = self::migrateSmsEvents($stored, $merged);

        return $merged;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, array{panel: bool, sms: bool, push: bool}>
     */
    private static function migrateNotificationChannels(array $stored): array
    {
        $defaults = NotificationType::defaultChannels();
        $storedVersion = (int) ($stored['settings_version'] ?? 0);
        $storedChannels = $stored['merchant_notification_channels'] ?? null;

        if (is_array($storedChannels) && $storedVersion >= 9) {
            $channels = $defaults;
            foreach ($storedChannels as $type => $row) {
                if (!isset($defaults[$type]) || !is_array($row)) {
                    continue;
                }
                foreach (NotificationChannel::all() as $channel) {
                    if (array_key_exists($channel, $row)) {
                        $channels[$type][$channel] = !empty($row[$channel]);
                    }
                }
            }

            return $channels;
        }

        $oldEvents = (array) ($stored['merchant_notification_events'] ?? []);
        $oldSms = (array) ($stored['sms_events'] ?? []);
        $channels = [];
        foreach ($defaults as $type => $flags) {
            $panel = $flags[NotificationChannel::PANEL];
            if ($oldEvents !== []) {
                $panel = !isset($oldEvents[$type]) || !empty($oldEvents[$type]);
            }
            $sms = $flags[NotificationChannel::SMS];
            $smsKey = NotificationType::smsTemplateKey($type);
            if ($smsKey !== null && $oldSms !== [] && array_key_exists($smsKey, $oldSms)) {
                $sms = !empty($oldSms[$smsKey]);
            }
            $channels[$type] = [
                NotificationChannel::PANEL => $panel,
                NotificationChannel::SMS => $sms,
                NotificationChannel::PUSH => false,
            ];
        }

        return $channels;
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $merged
     * @return array<string, bool>
     */
    private static function migrateSmsEvents(array $stored, array $merged): array
    {
        $allowed = SmsTemplates::defaultEventFlags();
        $existing = (array) ($merged['sms_events'] ?? []);
        if (isset($stored['sms_events']) && is_array($stored['sms_events'])) {
            $existing = $stored['sms_events'] + $existing;
        }

        $clean = [];
        foreach ($allowed as $key => $default) {
            $clean[$key] = array_key_exists($key, $existing) ? !empty($existing[$key]) : $default;
        }

        return $clean;
    }

    /** @return list<string> */
    public static function adminPhones(): array
    {
        $raw = (string) self::get('admin_notification_phones', '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[|,\n]/', $raw) ?: [])));
    }

    public static function expressPhone(): string
    {
        return trim((string) self::get('express_notification_phone', ''));
    }

    public static function yurticiCustomerCode(): string
    {
        $code = trim((string) self::get('yurtici_customer_code', '981317779'));

        return $code !== '' ? $code : '981317779';
    }

    public static function smsEnabled(): bool
    {
        return (bool) self::get('sms_enabled', true);
    }

    public static function smsEventEnabled(string $eventKey): bool
    {
        $events = (array) self::get('sms_events', []);

        return !isset($events[$eventKey]) || !empty($events[$eventKey]);
    }

    public static function requireAdminPaymentConfirm(): bool
    {
        return (bool) self::get('require_admin_payment_confirm', true);
    }

    public static function sourcingPriceTolerancePercent(): float
    {
        return max(0.0, (float) self::get('sourcing_price_tolerance_percent', 10));
    }

    public static function defaultFulfillmentFilter(): string
    {
        return sanitize_key((string) self::get('default_fulfillment_filter', 'payment'));
    }

    public static function useBusinessDays(): bool
    {
        return (bool) self::get('use_business_days_for_deadlines', false);
    }

    public static function confirmGraceSeconds(): int
    {
        return max(1, (int) self::get('confirm_grace_hours', 24)) * HOUR_IN_SECONDS;
    }

    public static function cargoReminderSeconds(): int
    {
        return max(1, (int) self::get('cargo_reminder_hours', 24)) * HOUR_IN_SECONDS;
    }

    public static function confirmDeadlineSeconds(?int $merchantId = null): int
    {
        if ($merchantId) {
            $status = MerchantLevels::statusForUser($merchantId);
            $override = match ($status) {
                MerchantLevels::PREMIUM => (int) self::get('confirm_deadline_hours_premium', 0),
                MerchantLevels::VERIFIED => (int) self::get('confirm_deadline_hours_verified', 0),
                default => 0,
            };
            if ($override > 0) {
                return $override * HOUR_IN_SECONDS;
            }
        }

        return max(1, (int) self::get('confirm_deadline_hours', 24)) * HOUR_IN_SECONDS;
    }

    public static function cargoDeadlineSecondsForShipmentType(string $shipmentType): int
    {
        $hours = match ($shipmentType) {
            'express' => (int) self::get('cargo_deadline_hours_express', 24),
            'international' => (int) self::get('cargo_deadline_hours_international', 72),
            default => (int) self::get('cargo_deadline_hours_standard', 48),
        };

        return max(1, $hours) * HOUR_IN_SECONDS;
    }

    public static function returnWindowDays(): int
    {
        return max(0, (int) self::get('return_window_days', 14));
    }

    public static function returnWindowSeconds(): int
    {
        return self::returnWindowDays() * DAY_IN_SECONDS;
    }

    public static function shipmentCodeValid(string $code): bool
    {
        $pattern = (string) self::get('shipment_code_pattern', '^\d{12}$');
        if ($pattern === '') {
            return $code !== '';
        }

        return (bool) preg_match('/' . $pattern . '/', $code);
    }

    /** @return list<string> */
    public static function swapAllowedStatuses(): array
    {
        $raw = (string) self::get('swap_allowed_statuses', 'payment');
        $parts = array_map('sanitize_key', array_filter(array_map('trim', explode(',', $raw))));

        return $parts ?: ['payment'];
    }

    public static function swapAllowedFor(string $status): bool
    {
        return in_array($status, self::swapAllowedStatuses(), true);
    }

    public static function allowManualOrderLink(): bool
    {
        return (bool) self::get('allow_manual_order_link', true);
    }

    public static function deadlineCronSchedule(): string
    {
        $schedule = sanitize_key((string) self::get('deadline_cron_schedule', 'twicedaily'));

        return in_array($schedule, ['hourly', 'twicedaily', 'daily'], true) ? $schedule : 'twicedaily';
    }

    public static function payoutMinHoldDays(): int
    {
        return max(0, (int) self::get('payout_min_hold_days', 7));
    }

    /** @return list<int> */
    public static function payoutWeekdays(): array
    {
        $raw = self::get('payout_weekdays', [3]);
        if (!is_array($raw)) {
            $raw = [3];
        }

        return \SutoreMarketplace\Modules\Merchants\Domain\PayoutSchedule::normalizeWeekdays($raw);
    }
}
