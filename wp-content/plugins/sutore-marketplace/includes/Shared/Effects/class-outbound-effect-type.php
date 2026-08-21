<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Effects;

final class OutboundEffectType
{
    public const SMS = 'sms';
    public const WEBHOOK = 'webhook';
    public const IYS = 'iys';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::SMS, self::WEBHOOK, self::IYS];
    }
}
