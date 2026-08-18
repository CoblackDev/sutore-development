<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Shared\Security\OutboundUrl;
use SutoreMarketplace\Tests\Support\Harness;

final class OutboundUrlTest
{
    public function testRejectsEmptyAndNonHttps(): void
    {
        Harness::assertFalse(OutboundUrl::isSafe(''));
        Harness::assertFalse(OutboundUrl::isSafe('http://example.com/hook'));
        Harness::assertFalse(OutboundUrl::isSafe('ftp://example.com/hook'));
    }

    public function testRejectsLoopbackAndPrivateLiterals(): void
    {
        Harness::assertFalse(OutboundUrl::isSafe('https://127.0.0.1/hook'));
        Harness::assertFalse(OutboundUrl::isSafe('https://localhost/hook'));
        Harness::assertFalse(OutboundUrl::isSafe('https://10.0.0.5/hook'));
        Harness::assertFalse(OutboundUrl::isSafe('https://192.168.1.20/hook'));
        Harness::assertFalse(OutboundUrl::isSafe('https://169.254.169.254/latest/meta-data/'));
    }

    public function testRejectsUserInfoAndInvalidHost(): void
    {
        Harness::assertFalse(OutboundUrl::isSafe('https://user:pass@example.com/hook'));
        Harness::assertFalse(OutboundUrl::isSafe('https://not a host/hook'));
    }
}
