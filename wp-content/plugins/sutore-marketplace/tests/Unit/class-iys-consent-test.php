<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Shared\Sms\IysConsentService;
use SutoreMarketplace\Shared\Sms\IysPayload;
use SutoreMarketplace\Shared\Sms\PhoneNormalizer;
use SutoreMarketplace\Tests\Support\Harness;

final class IysConsentTest
{
    public function testPayloadClassifiesEmailAndPhone(): void
    {
        $records = IysPayload::records(
            ['Demo@Sutore.com', '+90 555 111 22 33', 'Demo@Sutore.com', ''],
            IysPayload::STATUS_GRANT,
            '2026-08-18 11:00:00'
        );
        Harness::assertSame(2, count($records));
        Harness::assertSame(IysPayload::TYPE_EMAIL, $records[0]['type']);
        Harness::assertSame('demo@sutore.com', $records[0]['recipient']);
        Harness::assertSame(IysPayload::TYPE_SMS, $records[1]['type']);
        Harness::assertSame('+905551112233', $records[1]['recipient']);
        Harness::assertSame(IysPayload::STATUS_GRANT, $records[0]['status']);
        Harness::assertSame(IysPayload::SOURCE, $records[0]['source']);
        Harness::assertSame(IysPayload::RECIPIENT_TYPE, $records[0]['recipientType']);
    }

    public function testPhoneNormalizerIysRecipient(): void
    {
        Harness::assertSame('+905551112233', PhoneNormalizer::toIysRecipient('05551112233'));
        Harness::assertSame('', PhoneNormalizer::toIysRecipient('2121112233'));
    }

    public function testSyncGrantsOnConsentEnable(): void
    {
        $calls = IysConsentService::plannedCalls('', '', 'a@b.com', '5551112233', false, true);
        Harness::assertSame(1, count($calls));
        Harness::assertSame(IysPayload::STATUS_GRANT, $calls[0]['status']);
        Harness::assertTrue(in_array('a@b.com', $calls[0]['identifiers'], true));
    }

    public function testSyncRevokesOnConsentDisable(): void
    {
        $calls = IysConsentService::plannedCalls('a@b.com', '5551112233', 'a@b.com', '5551112233', true, false);
        Harness::assertSame(1, count($calls));
        Harness::assertSame(IysPayload::STATUS_REVOKE, $calls[0]['status']);
    }

    public function testSyncReplacesChangedPhoneWhileConsented(): void
    {
        $calls = IysConsentService::plannedCalls('a@b.com', '5551112233', 'a@b.com', '5559998877', true, true);
        Harness::assertSame(2, count($calls));
        Harness::assertSame(IysPayload::STATUS_REVOKE, $calls[0]['status']);
        Harness::assertSame(IysPayload::STATUS_GRANT, $calls[1]['status']);
        Harness::assertSame(['+905551112233'], $calls[0]['identifiers']);
        Harness::assertSame(['+905559998877'], $calls[1]['identifiers']);
    }

    public function testSyncReplacesChangedEmailWhileConsented(): void
    {
        $calls = IysConsentService::plannedCalls('old@b.com', '5551112233', 'new@b.com', '5551112233', true, true);
        Harness::assertSame(2, count($calls));
        Harness::assertSame(IysPayload::STATUS_REVOKE, $calls[0]['status']);
        Harness::assertSame(IysPayload::STATUS_GRANT, $calls[1]['status']);
        Harness::assertSame(['old@b.com'], $calls[0]['identifiers']);
        Harness::assertSame(['new@b.com'], $calls[1]['identifiers']);
    }

    public function testSyncDoesNothingWhenConsentStaysOff(): void
    {
        $calls = IysConsentService::plannedCalls('a@b.com', '5551112233', 'a@b.com', '5551112233', false, false);
        Harness::assertSame([], $calls);
    }

    public function testSyncDoesNothingWhenIdentifiersUnchanged(): void
    {
        $calls = IysConsentService::plannedCalls('a@b.com', '5551112233', 'a@b.com', '5551112233', true, true);
        Harness::assertSame([], $calls);
    }

    public function testPayloadRejectsUnknownStatusAndLandline(): void
    {
        Harness::assertSame([], IysPayload::records(['a@b.com'], 'MAYBE', '2026-08-18 11:00:00'));
        $records = IysPayload::records(['2121112233', ''], IysPayload::STATUS_REVOKE, '2026-08-18 11:00:00');
        Harness::assertSame([], $records);
    }
}
