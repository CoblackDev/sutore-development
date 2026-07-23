<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Otp\Services;

use SutoreMarketplace\Shared\Sms\SmsGateway;

final class OtpSmsGateway
{
    public static function send(string $phone, string $message): void
    {
        SmsGateway::send($phone, $message);
    }
}
