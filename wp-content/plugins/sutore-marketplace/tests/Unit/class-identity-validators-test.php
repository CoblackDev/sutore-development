<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Modules\Merchants\Services\IbanValidator;
use SutoreMarketplace\Modules\Merchants\Services\TcValidator;
use SutoreMarketplace\Shared\Sms\PhoneNormalizer;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class IdentityValidatorsTest
{
    public function testValidTcFromSeed(): void
    {
        Harness::assertTrue(TcValidator::isValid(Fixtures::VALID_TC));
        Harness::assertFalse(TcValidator::isValid('12345678901'));
        Harness::assertFalse(TcValidator::isValid('01234567890'));
        Harness::assertFalse(TcValidator::isValid('123'));
    }

    public function testValidTurkishIban(): void
    {
        Harness::assertTrue(IbanValidator::isValid(Fixtures::VALID_IBAN));
        Harness::assertFalse(IbanValidator::isValid('TR000000000000000000000000'));
        Harness::assertFalse(IbanValidator::isValid('TR12'));
    }

    public function testPhoneNormalizer(): void
    {
        Harness::assertSame('5551112233', PhoneNormalizer::toDomestic('+90 555 111 22 33'));
        Harness::assertSame('5551112233', PhoneNormalizer::toDomestic('05551112233'));
        Harness::assertTrue(PhoneNormalizer::isValidDomestic('5551112233'));
        Harness::assertFalse(PhoneNormalizer::isValidDomestic('2121112233'));
    }
}
