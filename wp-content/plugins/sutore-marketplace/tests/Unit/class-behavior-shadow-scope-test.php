<?php

declare(strict_types=1);

namespace SutoreMarketplace\Tests\Unit;

use SutoreMarketplace\Modules\Merchants\Services\BehaviorLevelService;
use SutoreMarketplace\Modules\Merchants\Services\BehaviorScoreService;
use SutoreMarketplace\Modules\Merchants\Settings\BehaviorSettings;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Domain\MerchantLevels;
use SutoreMarketplace\Tests\Support\Fixtures;
use SutoreMarketplace\Tests\Support\Harness;

final class BehaviorShadowScopeTest
{
    public function testShadowWindowFreezesLevelsWithoutGlobalKillSwitch(): void
    {
        $behavior = array_merge(BehaviorSettings::defaults(), [
            'shadow_mode_enabled' => true,
            'shadow_mode_weeks' => 52,
            'new_seller_protection_deliveries' => 0,
            'new_seller_protection_days' => 0,
        ]);

        Fixtures::withMarketplaceSettings(['behavior' => $behavior], static function (): void {
            $seller = Fixtures::user('st_shadow_fresh', 'merchant', MerchantLevels::NORMAL);
            $scores = new BehaviorScoreService();
            $levels = new BehaviorLevelService();

            Harness::assertTrue($scores->isInShadowMode($seller), 'fresh seller is in shadow window');
            Harness::assertFalse($scores->sanctionsActive($seller), 'sanctions paused only inside the window');
            Harness::assertFalse($levels->levelChangesActive($seller), 'no promote/demote while score is hidden');

            $before = MerchantLevels::statusForUser($seller);
            $levels->evaluateConfirmed($seller);
            $levels->evaluatePremium($seller);
            Harness::assertSame($before, MerchantLevels::statusForUser($seller), 'level frozen in shadow');
        });
    }

    public function testPastShadowWindowRestoresSanctionsWhileFlagStaysOn(): void
    {
        $behavior = array_merge(BehaviorSettings::defaults(), [
            'shadow_mode_enabled' => true,
            'shadow_mode_weeks' => 1,
            'new_seller_protection_deliveries' => 0,
            'new_seller_protection_days' => 0,
        ]);

        Fixtures::withMarketplaceSettings(['behavior' => $behavior], static function (): void {
            $seller = Fixtures::user('st_shadow_alumni', 'merchant', MerchantLevels::VERIFIED);
            global $wpdb;
            $old = wp_date('Y-m-d H:i:s', time() - (10 * WEEK_IN_SECONDS));
            $wpdb->update(
                Schema::table('merchant_profiles'),
                ['created_at' => $old, 'updated_at' => $old],
                ['user_id' => $seller]
            );

            $scores = new BehaviorScoreService();
            Harness::assertFalse($scores->isInShadowMode($seller), 'alumni left the shadow window');
            Harness::assertTrue(
                $scores->sanctionsActive($seller),
                'shadow_mode_enabled must not disable sanctions for everyone'
            );
            Harness::assertTrue((new BehaviorLevelService())->levelChangesActive($seller));
        });
    }
}
