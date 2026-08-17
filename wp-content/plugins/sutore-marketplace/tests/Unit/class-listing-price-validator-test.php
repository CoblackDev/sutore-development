<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Modules\Listings\Domain\ListingPriceValidator;
use SutoreMarketplace\Shared\Settings\Settings;
use SutoreMarketplace\Tests\Support\Harness;

final class ListingPriceValidatorTest
{
    public function testDefaultStepRejectsTen(): void
    {
        $step = Settings::listingPriceStep();
        if ($step > 10) {
            Harness::assertWpError(ListingPriceValidator::assertStepMultiple(10), '10 TL must fail default step');
        } else {
            Harness::assertNotWpError(ListingPriceValidator::assertStepMultiple(10));
        }
    }

    public function testStepMultipleOfTwentyFive(): void
    {
        Harness::assertNotWpError(ListingPriceValidator::assertStepMultiple(100, 25));
        Harness::assertNotWpError(ListingPriceValidator::assertStepMultiple(25, 25));
        Harness::assertWpError(ListingPriceValidator::assertStepMultiple(30, 25));
        Harness::assertWpError(ListingPriceValidator::assertStepMultiple(0, 25));
        Harness::assertWpError(ListingPriceValidator::assertStepMultiple(12.5, 25));
    }

    public function testNormalizeRejectsDecimals(): void
    {
        Harness::assertSame(null, ListingPriceValidator::normalizeAsking('99.9'));
        Harness::assertSame(200, ListingPriceValidator::normalizeAsking('200'));
        Harness::assertSame(200, ListingPriceValidator::normalizeAsking('200,00'));
    }

    public function testRoundDownToStep(): void
    {
        Harness::assertSame(150, ListingPriceValidator::roundDownToStep(174, 25));
        Harness::assertSame(10, ListingPriceValidator::roundDownToStep(19, 10));
        Harness::assertSame(0, ListingPriceValidator::roundDownToStep(9, 10));
    }
}
