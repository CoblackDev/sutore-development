<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Domain\CampaignGuardrails;
use SutoreMarketplace\Tests\Support\Harness;

final class CampaignRulesTest
{
    public function testDiscountTypeNormalize(): void
    {
        Harness::assertSame(CampaignDiscountType::PERCENT, CampaignDiscountType::normalize('pct'));
        Harness::assertSame(CampaignDiscountType::FIXED, CampaignDiscountType::normalize('tl'));
        Harness::assertSame(CampaignDiscountType::FIXED, CampaignDiscountType::normalize('nope'));
    }

    public function testPercentBandRejectsOutOfRange(): void
    {
        Harness::assertWpError(CampaignGuardrails::assertPercent(5));
        Harness::assertNotWpError(CampaignGuardrails::assertPercent(10));
        Harness::assertNotWpError(CampaignGuardrails::assertPercent(40));
        Harness::assertWpError(CampaignGuardrails::assertPercent(91));
    }

    public function testDatetimeLocalNormalizes(): void
    {
        $value = CampaignDatetime::normalizeInput('2026-08-17T10:30');
        Harness::assertSame('2026-08-17 10:30:00', $value);
        Harness::assertSame(null, CampaignDatetime::normalizeInput(''));
    }

    public function testDurationChoicesRespectMaxDays(): void
    {
        $options = CampaignGuardrails::durationOptions();
        Harness::assertTrue($options !== []);
        foreach ($options as $days) {
            Harness::assertTrue($days <= CampaignGuardrails::maxDays());
        }
    }
}
