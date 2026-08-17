<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms;

use SutoreMarketplace\Shared\Sms\Settings\SmsSimulationSettings;

final class SmsGateway
{
    public static function send(string $phone, string $message): bool
    {
        $result = self::sendDetailed($phone, $message);

        return $result->success;
    }

    public static function sendDetailed(string $phone, string $message): NetgsmSendResult
    {
        if (SmsSimulationSettings::isEnabled()) {
            do_action('sutore_marketplace_sms_simulated', $phone, $message);

            return NetgsmSendResult::ok('simulated');
        }

        $result = (new NetgsmClient())->sendSingle($phone, $message);

        if (!$result->success) {
            do_action('sutore_marketplace_sms_failed', $phone, $message, $result->error);
        } else {
            do_action('sutore_marketplace_sms_sent', $phone, $message, $result->reference);
        }

        return $result;
    }
}
