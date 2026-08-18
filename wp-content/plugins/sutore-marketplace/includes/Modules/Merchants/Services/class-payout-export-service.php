<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Merchants\Services;

use SutoreMarketplace\Modules\Merchants\Domain\PayoutSchedule;
use SutoreMarketplace\Modules\Merchants\Domain\PayoutStatus;
use SutoreMarketplace\Modules\Merchants\Repositories\PayoutLineRepository;
use SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository;
use SutoreMarketplace\Modules\Orders\Services\MerchantSnapshot;
use SutoreMarketplace\Modules\Orders\Services\Notifications;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class PayoutExportService
{
    public function __construct(
        private readonly FulfillmentRepository $fulfillments = new FulfillmentRepository(),
        private readonly PayoutLineRepository $payouts = new PayoutLineRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $args Same filters as staff Manage Products.
     * @return array{filename: string, csv: string, count: int}
     */
    public function csv(array $args): array
    {
        if (empty($args['payout_due']) && empty($args['payout_status'])) {
            $args['has_payout'] = true;
        }
        $args['all_statuses'] = true;
        $args['full_row'] = true;
        $args['page'] = 1;
        $args['per_page'] = 2000;
        $result = $this->fulfillments->query($args);
        $rows = $result['items'];

        $variationIds = [];
        $orderIds = [];
        foreach ($rows as $row) {
            $variationIds[] = (int) ($row->variation_id ?? 0);
            $orderId = (int) ($row->order_id ?? 0);
            if ($orderId > 0) {
                $orderIds[$orderId] = $orderId;
            }
        }
        $payoutMap = $this->payouts->findByVariationIds($variationIds);
        $orderIdByVariation = [];
        foreach ($rows as $row) {
            $variationId = (int) ($row->variation_id ?? 0);
            if ($variationId > 0) {
                $orderIdByVariation[$variationId] = (int) ($row->order_id ?? 0);
            }
        }
        $invoiceMap = (new \SutoreMarketplace\Modules\Invoices\Repositories\InvoiceRepository())->findForVariations($orderIdByVariation);
        $customers = $this->customerNames(array_values($orderIds));

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return [
                'filename' => $this->filename(),
                'csv' => "\xEF\xBB\xBF",
                'count' => 0,
            ];
        }

        fputcsv($handle, $this->headers());
        $count = 0;
        foreach ($rows as $row) {
            $variationId = (int) ($row->variation_id ?? 0);
            $payout = $payoutMap[$variationId] ?? null;
            if (!$payout) {
                continue;
            }
            fputcsv($handle, $this->line($row, $payout, $customers, $invoiceMap[(int) ($row->variation_id ?? 0)] ?? []));
            $count++;
        }
        rewind($handle);
        $body = stream_get_contents($handle) ?: '';
        fclose($handle);

        return [
            'filename' => $this->filename(),
            'csv' => "\xEF\xBB\xBF" . $body,
            'count' => $count,
        ];
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            __('Order', 'sutore-marketplace'),
            __('Customer', 'sutore-marketplace'),
            __('Product', 'sutore-marketplace'),
            __('Price', 'sutore-marketplace'),
            __('Guarantee fee', 'sutore-marketplace'),
            __('Service fee', 'sutore-marketplace'),
            __('Commission %', 'sutore-marketplace'),
            __('Commission amount', 'sutore-marketplace'),
            __('Seller name', 'sutore-marketplace'),
            __('Seller level', 'sutore-marketplace'),
            __('TC Identity Number', 'sutore-marketplace'),
            __('IBAN', 'sutore-marketplace'),
            __('District / city', 'sutore-marketplace'),
            __('Email Address', 'sutore-marketplace'),
            __('Phone Number', 'sutore-marketplace'),
            __('Net payout', 'sutore-marketplace'),
            __('Customer invoice', 'sutore-marketplace'),
            __('Customer invoice date', 'sutore-marketplace'),
            __('Customer invoice status', 'sutore-marketplace'),
            __('Seller invoice', 'sutore-marketplace'),
            __('Seller invoice date', 'sutore-marketplace'),
            __('Seller invoice status', 'sutore-marketplace'),
            __('Notes', 'sutore-marketplace'),
            __('Payout status', 'sutore-marketplace'),
            __('Scheduled payout date', 'sutore-marketplace'),
            __('Payment reference (EFT/receipt)', 'sutore-marketplace'),
        ];
    }

    /**
     * @param array<int, string> $customers
     * @param list<object> $invoices
     * @return list<string>
     */
    private function line(object $row, object $payout, array $customers, array $invoices = []): array
    {
        $variationId = (int) ($row->variation_id ?? 0);
        $parentId = (int) ($row->parent_product_id ?? 0);
        $sizeTermId = (int) ($row->size_term_id ?? 0);
        $title = (string) ($payout->product_title ?? '');
        if ($title === '') {
            $title = Notifications::productTitle($variationId, $variationId, $parentId, $sizeTermId);
        }

        $orderId = (int) ($payout->order_id ?? $row->order_id ?? 0);
        $snapshot = MerchantSnapshot::decode($row->merchant_snapshot ?? null);
        $levelKey = sanitize_key((string) ($snapshot['merchant_level'] ?? ''));
        $levelLabel = $levelKey !== '' ? MerchantLevels::labelForStatus($levelKey) : '';
        $district = trim((string) ($snapshot['state'] ?? ''));
        $city = trim((string) ($snapshot['city'] ?? ''));
        $place = trim($district . ($district !== '' && $city !== '' ? ' / ' : '') . $city);
        $customerInvoice = \SutoreMarketplace\Modules\Invoices\Services\InvoicePresenter::byKind(
            $invoices,
            \SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind::CUSTOMER_FEES
        );
        $sellerInvoice = \SutoreMarketplace\Modules\Invoices\Services\InvoicePresenter::byKind(
            $invoices,
            \SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind::SELLER_COMMISSION
        );

        return [
            $orderId > 0 ? (string) $orderId : '',
            $customers[$orderId] ?? '',
            $title,
            $this->money((float) ($payout->gross_asking ?? 0)),
            $this->money((float) ($payout->guvence_fee ?? 0)),
            $this->money((float) ($payout->hizmet_fee ?? 0)),
            $this->money((float) ($payout->commission_percent ?? 0)),
            $this->money((float) ($payout->commission_amount ?? 0)),
            (string) ($snapshot['name'] ?? ''),
            $levelLabel,
            (string) ($snapshot['tc'] ?? ''),
            (string) ($snapshot['iban'] ?? ''),
            $place,
            (string) ($snapshot['email'] ?? ''),
            (string) ($snapshot['phone'] ?? ''),
            $this->money((float) ($payout->net_amount ?? 0)),
            $customerInvoice ? (string) ($customerInvoice->invoice_number ?? '') : '',
            $customerInvoice ? (string) ($customerInvoice->invoice_date ?? '') : '',
            $customerInvoice
                ? \SutoreMarketplace\Modules\Invoices\Domain\InvoiceStatus::label((string) $customerInvoice->status)
                : '',
            $sellerInvoice ? (string) ($sellerInvoice->invoice_number ?? '') : '',
            $sellerInvoice ? (string) ($sellerInvoice->invoice_date ?? '') : '',
            $sellerInvoice
                ? \SutoreMarketplace\Modules\Invoices\Domain\InvoiceStatus::label((string) $sellerInvoice->status)
                : '',
            (string) ($row->notes ?? ''),
            PayoutStatus::label((string) ($payout->payout_status ?? '')),
            PayoutSchedule::formatDateWithWeekday((string) ($payout->scheduled_payout_date ?? '')),
            (string) ($payout->payment_ref ?? ''),
        ];
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, string>
     */
    private function customerNames(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        $out = [];
        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $out[$orderId] = trim($order->get_formatted_billing_full_name());
        }

        return $out;
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function filename(): string
    {
        return 'sutore-payouts-' . PayoutSchedule::today() . '.csv';
    }
}
