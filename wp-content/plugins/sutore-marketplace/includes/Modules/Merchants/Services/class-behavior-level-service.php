<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Support\MerchantMeta;
use SutoreMarketplace\Modules\Merchants\Settings\BehaviorSettings;
use SutoreMarketplace\Shared\Database\Schema;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class BehaviorLevelService
{
    public function __construct(
        private readonly BehaviorScoreService $scores = new BehaviorScoreService(),
    ) {
    }

    /**
     * Hidden scores (new-seller protection / shadow window) freeze level changes both ways.
     */
    public function levelChangesActive(int $merchantId): bool
    {
        return !$this->scores->isScoreHidden($merchantId);
    }

    public function evaluateConfirmed(int $merchantId): void
    {
        if (!$this->levelChangesActive($merchantId)) {
            return;
        }

        $sanctions = $this->scores->sanctionsActive($merchantId);
        $current = MerchantLevels::statusForUser($merchantId);
        if ($current === MerchantLevels::PREMIUM) {
            return;
        }

        if (!MerchantMeta::isTcVerified($merchantId)) {
            if ($sanctions && $current === MerchantLevels::VERIFIED) {
                MerchantLevels::setStatus($merchantId, MerchantLevels::NORMAL);
            }

            return;
        }

        $score = $this->scores->scoreForMerchant($merchantId);
        $sales = $this->lifetimeSalesCount($merchantId);
        $minScore = BehaviorSettings::confirmedMinScore();
        $minSales = BehaviorSettings::confirmedMinSales();

        if ($score >= $minScore && $sales >= $minSales) {
            if ($current === MerchantLevels::NORMAL) {
                MerchantLevels::setStatus($merchantId, MerchantLevels::VERIFIED);
                $this->notifyLevelChange($merchantId, MerchantLevels::NORMAL, MerchantLevels::VERIFIED);
            }

            return;
        }

        if ($sanctions && $current === MerchantLevels::VERIFIED && $score < $minScore) {
            MerchantLevels::setStatus($merchantId, MerchantLevels::NORMAL);
            $this->notifyLevelChange($merchantId, MerchantLevels::VERIFIED, MerchantLevels::NORMAL);
        }
    }

    public function evaluatePremium(int $merchantId): void
    {
        if (!$this->levelChangesActive($merchantId)) {
            return;
        }

        $sanctions = $this->scores->sanctionsActive($merchantId);
        $current = MerchantLevels::statusForUser($merchantId);
        if ($current === MerchantLevels::NORMAL) {
            return;
        }

        $score = $this->scores->scoreForMerchant($merchantId);
        $minScore = BehaviorSettings::premiumMinScore();
        $monthly = $this->previousMonthStats($merchantId);

        $meetsSales = $monthly['sales'] >= BehaviorSettings::premiumMonthlyMinSales();
        $meetsRevenue = $monthly['revenue'] >= BehaviorSettings::premiumMonthlyMinRevenue();
        $meetsScore = $score >= $minScore;
        $qualifies = $meetsSales && $meetsRevenue && $meetsScore;

        if ($current === MerchantLevels::PREMIUM) {
            if ($sanctions && !$qualifies) {
                MerchantLevels::setStatus($merchantId, MerchantLevels::VERIFIED);
                $this->notifyLevelChange($merchantId, MerchantLevels::PREMIUM, MerchantLevels::VERIFIED);
            }

            return;
        }

        if ($current === MerchantLevels::VERIFIED && $qualifies) {
            MerchantLevels::setStatus($merchantId, MerchantLevels::PREMIUM);
            $this->notifyLevelChange($merchantId, MerchantLevels::VERIFIED, MerchantLevels::PREMIUM);
        }
    }

    /** @return array{sales: int, revenue: float} */
    public function previousMonthStats(int $merchantId): array
    {
        global $wpdb;
        $listings = Schema::table('listings');
        $start = wp_date('Y-m-01 00:00:00', strtotime('first day of previous month'));
        $end = wp_date('Y-m-t 23:59:59', strtotime('last day of previous month'));

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS sales, COALESCE(SUM(asking), 0) AS revenue
             FROM {$listings}
             WHERE merchant_id = %d
               AND sold_at IS NOT NULL
               AND sold_at >= %s
               AND sold_at <= %s
               AND listing_status NOT IN ('not_sale', 'pending', 'publish', 'queued')",
            $merchantId,
            $start,
            $end
        ));

        return [
            'sales' => (int) ($row->sales ?? 0),
            'revenue' => (float) ($row->revenue ?? 0),
        ];
    }

    public function lifetimeSalesCount(int $merchantId): int
    {
        global $wpdb;
        $listings = Schema::table('listings');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$listings}
             WHERE merchant_id = %d
               AND sold_at IS NOT NULL
               AND listing_status NOT IN ('not_sale', 'pending', 'publish', 'queued')",
            $merchantId
        ));
    }

    private function notifyLevelChange(int $merchantId, string $from, string $to): void
    {
        (new NotificationService())->dispatch($merchantId, NotificationType::LEVEL_CHANGED, [
            'from' => $from,
            'to' => $to,
            'from_label' => MerchantLevels::labelForStatus($from),
            'to_label' => MerchantLevels::labelForStatus($to),
        ]);
    }
}
