<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Sms;

final class NetgsmSendResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $reference,
        public readonly string $error,
    ) {
    }

    public static function ok(string $reference = ''): self
    {
        return new self(true, $reference, '');
    }

    public static function fail(string $error): self
    {
        return new self(false, '', $error);
    }
}
