<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Effects;

final class OutboundEffectStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PENDING, self::PROCESSING, self::DONE, self::FAILED];
    }
}
