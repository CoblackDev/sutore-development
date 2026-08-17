<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Services;

use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;

/**
 * Customer e-Archive waits until every remaining order listing is verified (or later) or gone.
 *
 * @phpstan-type Inspection array{settled: bool, billable_ids: list<int>, open_ids: list<int>}
 */
final class InvoiceOrderSettlement
{
    public function __construct(
        private readonly FulfillmentRepository $fulfillments = new FulfillmentRepository(),
    ) {
    }

    /**
     * @return Inspection
     */
    public function inspect(int $orderId): array
    {
        $billable = [];
        $open = [];
        if ($orderId <= 0) {
            return ['settled' => true, 'billable_ids' => [], 'open_ids' => []];
        }

        foreach ($this->fulfillments->findByOrderId($orderId) as $row) {
            $variationId = (int) ($row->variation_id ?? $row->id ?? 0);
            $status = (string) ($row->fulfillment_status ?? $row->listing_status ?? '');
            if ($variationId <= 0) {
                continue;
            }
            if (ListingStatus::invoiceOpen($status)) {
                $open[] = $variationId;
                continue;
            }
            if (ListingStatus::invoiceBillable($status)) {
                $billable[] = $variationId;
            }
        }

        return [
            'settled' => $open === [],
            'billable_ids' => array_values(array_unique($billable)),
            'open_ids' => array_values(array_unique($open)),
        ];
    }

    public function isSettled(int $orderId): bool
    {
        return $this->inspect($orderId)['settled'];
    }
}
