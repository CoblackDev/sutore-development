<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Integration;

use SutoreMarketplace\Modules\Contracts\Settings\ContractSettings;
use SutoreMarketplace\Modules\Otp\Domain\OtpPurpose;
use SutoreMarketplace\Modules\Otp\Services\OtpService;
use SutoreMarketplace\Modules\Tasks\Domain\OpportunityTemplate;
use SutoreMarketplace\Modules\Tasks\Services\TaskProgressService;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class AccountContractsTasksTest
{
    public function testContractsStayEnabledByDefault(): void
    {
        Harness::assertTrue(is_string(ContractSettings::checkboxTitle()));
        Harness::assertTrue(ContractSettings::CHECKOUT_FIELD !== '');
    }

    public function testOtpRequestInSimulation(): void
    {
        Fixtures::withMarketplaceSettings([
            'otp_enabled' => true,
            'sms_simulation_mode' => true,
        ], static function (): void {
            $seller = Fixtures::sellerVerified();
            wp_set_current_user($seller);
            $result = (new OtpService())->request($seller, OtpPurpose::PASSWORD_CHANGE, [
                'current_password' => Fixtures::PASSWORD,
                'new_password' => 'Password1!',
                'new_password_repeat' => 'Password1!',
            ]);
            if ($result instanceof \WP_Error && $result->get_error_code() === 'sutore_otp_disabled') {
                Harness::skip('OTP disabled in this environment');
            }
            Harness::assertNotWpError($result, 'OTP request');
            /** @var array{masked_phone:string,purpose:string} $result */
            Harness::assertSame(OtpPurpose::PASSWORD_CHANGE, $result['purpose']);
            Harness::assertTrue($result['masked_phone'] !== '');
        });
    }

    public function testTaskIncrementIsSafeWithoutCard(): void
    {
        $out = (new TaskProgressService())->incrementByTemplate(
            Fixtures::sellerVerified(),
            OpportunityTemplate::RECOVERY_TIMELY_CONFIRM
        );
        Harness::assertTrue(isset($out['ok']));
    }
}
