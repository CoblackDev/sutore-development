<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class MerchantBalanceService
{
    public function __construct(
        private readonly PayoutLineRepository $payouts = new PayoutLineRepository(),
    ) {
    }

    /** @return array{paid_total:float,pending_total:float,paid_count:int,pending_count:int,formatted_paid:string,formatted_pending:string,recent:list<object>} */
    public function summaryForMerchant(int $merchantId): array
    {
        $summary = $this->payouts->summaryForMerchant($merchantId);

        return array_merge($summary, [
            'formatted_paid' => MarketplacePricing::formatTl($summary['paid_total']),
            'formatted_pending' => MarketplacePricing::formatTl($summary['pending_total']),
            'recent' => $this->payouts->recentForMerchant($merchantId, 8),
        ]);
    }
}
