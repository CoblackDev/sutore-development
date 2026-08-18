<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Otp\Services\AccountDeletionService;
use SutoreMarketplace\Modules\Otp\Services\AccountSecurityService;
use SutoreMarketplace\Modules\Otp\Services\OtpPhoneResolver;
use SutoreMarketplace\Shared\Sms\IysConsentService;
use SutoreMarketplace\Shared\Sms\IysPayload;
use SutoreMarketplace\Shared\Sms\Settings\NetgsmSettings;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class IysConsentFlowTest
{
    public function testSimulationRecordsGrantAndRevokeWithoutApi(): void
    {
        $this->withIysSimulation(function (): void {
            $events = $this->captureIys(static function (): void {
                $service = new IysConsentService();
                $service->grant(['iys-sim@sutore-test.local', '5551112233']);
                $service->revoke(['iys-sim@sutore-test.local', '5551112233']);
            });

            Harness::assertSame(2, count($events), 'grant + revoke should each record once');
            Harness::assertTrue($events[0]['simulated']);
            Harness::assertTrue($events[1]['simulated']);
            Harness::assertSame(IysPayload::STATUS_GRANT, $events[0]['status']);
            Harness::assertSame(IysPayload::STATUS_REVOKE, $events[1]['status']);
            Harness::assertSame(IysPayload::TYPE_EMAIL, $events[0]['records'][0]['type']);
            Harness::assertSame('iys-sim@sutore-test.local', $events[0]['records'][0]['recipient']);
            Harness::assertSame(IysPayload::TYPE_SMS, $events[0]['records'][1]['type']);
            Harness::assertSame('+905551112233', $events[0]['records'][1]['recipient']);
            Harness::assertSame(IysPayload::SOURCE, $events[0]['records'][0]['source']);
            Harness::assertSame(IysPayload::RECIPIENT_TYPE, $events[0]['records'][0]['recipientType']);
        });
    }

    public function testOptInHookPersistsConsentAndRecordsOnay(): void
    {
        $this->withIysSimulation(function (): void {
            $userId = Fixtures::user('st_iys_optin', 'customer');
            MerchantMeta::setMarketingConsent($userId, false);

            $events = $this->captureIys(static function () use ($userId): void {
                do_action(
                    'sutore_marketplace_marketing_opt_in',
                    $userId,
                    'st_iys_optin@sutore-test.local',
                    '5552223344'
                );
            });

            Harness::assertTrue(MerchantMeta::marketingConsent($userId));
            Harness::assertSame(1, count($events));
            Harness::assertTrue($events[0]['simulated']);
            Harness::assertSame(IysPayload::STATUS_GRANT, $events[0]['status']);
            MerchantMeta::setMarketingConsent($userId, false);
        });
    }

    public function testGuestOptInRecordsEmailWithoutUserRow(): void
    {
        $this->withIysSimulation(function (): void {
            $events = $this->captureIys(static function (): void {
                do_action('sutore_marketplace_marketing_opt_in', 0, 'guest-iys@sutore-test.local', '');
            });

            Harness::assertFalse(MerchantMeta::marketingConsent(0));
            Harness::assertSame(1, count($events));
            Harness::assertSame(['guest-iys@sutore-test.local'], array_column($events[0]['records'], 'recipient'));
        });
    }

    public function testAccountDeletionRevokesConsentAndRecordsRet(): void
    {
        $this->withIysSimulation(function (): void {
            $userId = Fixtures::user('st_iys_delete', 'customer');
            MerchantMeta::setMarketingConsent($userId, true);
            $user = get_userdata($userId);
            Harness::assertTrue($user instanceof \WP_User);

            $events = $this->captureIys(static function () use ($userId): void {
                (new AccountDeletionService())->revokeMarketing($userId);
            });

            Harness::assertFalse(MerchantMeta::marketingConsent($userId));
            Harness::assertSame(1, count($events));
            Harness::assertSame(IysPayload::STATUS_REVOKE, $events[0]['status']);
            $recipients = array_column($events[0]['records'], 'recipient');
            Harness::assertTrue(in_array(strtolower((string) $user->user_email), $recipients, true));
        });
    }

    public function testAccountDetailsConsentToggleRecordsOnayThenRet(): void
    {
        $this->withIysSimulation(function (): void {
            $userId = Fixtures::user('st_iys_details', 'customer');
            $user = get_userdata($userId);
            Harness::assertTrue($user instanceof \WP_User);
            MerchantMeta::setMarketingConsent($userId, false);
            $phone = OtpPhoneResolver::forUser($userId);
            $base = [
                'current_password' => Fixtures::PASSWORD,
                'user_name' => 'Iys',
                'user_lastname' => 'Tester',
                'user_email' => (string) $user->user_email,
                'user_phone' => $phone,
            ];

            $grantEvents = $this->captureIys(function () use ($userId, $base): void {
                $this->updateAccountDetails($userId, $base + ['marketing_consent' => true]);
            });
            Harness::assertTrue(MerchantMeta::marketingConsent($userId));
            Harness::assertSame(1, count($grantEvents));
            Harness::assertSame(IysPayload::STATUS_GRANT, $grantEvents[0]['status']);

            $revokeEvents = $this->captureIys(function () use ($userId, $base): void {
                $this->updateAccountDetails($userId, $base + ['marketing_consent' => false]);
            });
            Harness::assertFalse(MerchantMeta::marketingConsent($userId));
            Harness::assertSame(1, count($revokeEvents));
            Harness::assertSame(IysPayload::STATUS_REVOKE, $revokeEvents[0]['status']);
        });
    }

    public function testUnconfiguredSkipDoesNotRecordWhenSimulationOff(): void
    {
        Fixtures::withMarketplaceSettings([
            'sms_simulation_mode' => false,
            'netgsm_brand_code' => '',
            'netgsm_usercode' => '',
            'netgsm_password' => '',
        ], function (): void {
            Harness::assertFalse(NetgsmSettings::isIysConfigured());
            $events = $this->captureIys(static function (): void {
                (new IysConsentService())->grant(['skip-iys@sutore-test.local', '5551112233']);
            });
            Harness::assertSame([], $events);
        });
    }

    /**
     * @param callable():void $fn
     */
    private function withIysSimulation(callable $fn): void
    {
        $brand = NetgsmSettings::brandCode();
        Fixtures::withMarketplaceSettings([
            'sms_simulation_mode' => true,
            'otp_enabled' => false,
            'netgsm_brand_code' => $brand !== '' ? $brand : 'test-brand',
        ], $fn);
    }

    /**
     * @param callable():void $fn
     * @return list<array{status:string,records:list<array<string,string>>,simulated:bool}>
     */
    private function captureIys(callable $fn): array
    {
        $listener = new class {
            /** @var list<array{status:string,records:list<array<string,string>>,simulated:bool}> */
            public array $events = [];

            public function handle(string $status, array $records, bool $simulated): void
            {
                $this->events[] = [
                    'status' => $status,
                    'records' => $records,
                    'simulated' => $simulated,
                ];
            }
        };
        add_action('sutore_marketplace_iys_recorded', [$listener, 'handle'], 20, 3);
        try {
            $fn();
        } finally {
            remove_action('sutore_marketplace_iys_recorded', [$listener, 'handle'], 20);
        }

        return $listener->events;
    }

    /** @param array<string, mixed> $input */
    private function updateAccountDetails(int $userId, array $input): void
    {
        if (wp_get_environment_type() === 'production') {
            Harness::skip('Account-details IYS test skips OTP in production');
        }
        $input['otp_code'] = '';
        $result = (new AccountSecurityService())->updateDetails($userId, $input);
        Harness::assertNotWpError($result, 'Account details update');
    }
}
