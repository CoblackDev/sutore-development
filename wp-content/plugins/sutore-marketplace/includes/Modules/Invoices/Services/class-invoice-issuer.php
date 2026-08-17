<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Services;

use SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind;
use SutoreMarketplace\Modules\Invoices\Domain\InvoiceLineName;
use SutoreMarketplace\Modules\Invoices\Domain\InvoiceStatus;
use SutoreMarketplace\Modules\Invoices\Hooks\InvoiceCronHooks;
use SutoreMarketplace\Modules\Invoices\Repositories\InvoiceRepository;
use SutoreMarketplace\Modules\Invoices\Settings\InvoiceSettings;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Orders\Services\MerchantSnapshot;
use SutoreMarketplace\Modules\Orders\Services\Notifications;
use SutoreMarketplace\Shared\Hooks\CheckoutIdentityHooks;

final class InvoiceIssuer
{
    public function __construct(
        private readonly InvoiceRepository $repo = new InvoiceRepository(),
        private readonly ParasutClient $client = new ParasutClient(),
        private readonly ParasutCatalog $catalog = new ParasutCatalog(),
        private readonly YouthInvoiceAllocator $allocator = new YouthInvoiceAllocator(),
        private readonly InvoiceMailer $mailer = new InvoiceMailer(),
        private readonly InvoiceOrderSettlement $settlement = new InvoiceOrderSettlement(),
    ) {
    }

    public function syncCustomerFeesForOrder(int $orderId): void
    {
        $this->queueOrderCustomerFees($orderId);
    }

    public function queueSellerCommission(Listing $listing, int $orderId): void
    {
        $this->queue($listing, $orderId, InvoiceKind::SELLER_COMMISSION);
    }

    public function processDue(int $limit = 8): void
    {
        if (!$this->canRun()) {
            return;
        }

        foreach ($this->repo->dueBatch($limit) as $row) {
            $this->processOne((int) $row->id);
        }
    }

    public function processOne(int $id): void
    {
        if (!$this->canRun() || $id <= 0) {
            return;
        }

        $row = $this->repo->find($id);
        if (!$row) {
            return;
        }
        if ((string) $row->status === InvoiceStatus::SENT || (string) $row->status === InvoiceStatus::SKIPPED) {
            return;
        }
        if (!$this->repo->claim($id)) {
            return;
        }

        $row = $this->repo->find($id);
        if (!$row) {
            return;
        }

        if ((string) $row->kind === InvoiceKind::CUSTOMER_FEES && !$this->settlement->isSettled((int) $row->order_id)) {
            $this->wait(
                $id,
                InvoiceStatus::QUEUED,
                __('Waiting until all remaining order items are verified or removed.', 'sutore-marketplace')
            );

            return;
        }

        try {
            $this->advance($row);
        } catch (\Throwable $e) {
            $this->fail((int) $row->id, (int) $row->retry_count, $e->getMessage());
        }
    }

    private function canRun(): bool
    {
        if (defined('SUTORE_MARKETPLACE_SEEDING') && SUTORE_MARKETPLACE_SEEDING) {
            return false;
        }

        return InvoiceSettings::enabled();
    }

    private function queueOrderCustomerFees(int $orderId): void
    {
        if (!$this->canRun() || $orderId <= 0) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $settlement = $this->settlement->inspect($orderId);
        if (!$settlement['settled']) {
            return;
        }

        $built = $this->allocator->customerLinesForOrder($order, $settlement['billable_ids']);
        $hizmet = $built['hizmet'];
        $guvence = $built['guvence'];
        $total = round($hizmet + $guvence, 2);
        $recipient = sanitize_email((string) $order->get_billing_email());
        $lineItems = (string) wp_json_encode($built['lines']);
        $existing = $this->repo->findByKindAndOrder(InvoiceKind::CUSTOMER_FEES, $orderId);

        if ($existing) {
            if ((string) $existing->status !== InvoiceStatus::QUEUED) {
                return;
            }
            $this->repo->update((int) $existing->id, [
                'hizmet_amount' => $hizmet,
                'guvence_amount' => $guvence,
                'commission_amount' => 0,
                'total_amount' => $total < 0.01 ? 0 : $total,
                'line_items' => $lineItems,
                'recipient_email' => $recipient,
                'status' => $total < 0.01 ? InvoiceStatus::SKIPPED : InvoiceStatus::QUEUED,
            ]);
            if ($total >= 0.01 && (string) $existing->status === InvoiceStatus::QUEUED) {
                $this->scheduleOne((int) $existing->id);
            }

            return;
        }

        $id = $this->repo->insert([
            'kind' => InvoiceKind::CUSTOMER_FEES,
            'variation_id' => InvoiceKind::ORDER_SCOPE_VARIATION,
            'order_id' => $orderId,
            'merchant_id' => 0,
            'status' => $total < 0.01 ? InvoiceStatus::SKIPPED : InvoiceStatus::QUEUED,
            'hizmet_amount' => $hizmet,
            'guvence_amount' => $guvence,
            'commission_amount' => 0,
            'total_amount' => $total < 0.01 ? 0 : $total,
            'line_items' => $lineItems,
            'recipient_email' => $recipient,
        ]);
        if ($id > 0 && $total >= 0.01) {
            $this->scheduleOne($id);
        }
    }

    private function queue(Listing $listing, int $orderId, string $kind): void
    {
        if (!$this->canRun()) {
            return;
        }

        $variationId = (int) $listing->variationId;
        if ($variationId <= 0 || $orderId <= 0) {
            return;
        }

        $existing = $this->repo->findByKindAndVariation($kind, $variationId);
        if ($existing) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $allocated = $this->allocator->forListing($listing, $order);
        $hizmet = 0.0;
        $guvence = 0.0;
        $commission = $kind === InvoiceKind::SELLER_COMMISSION ? $allocated['commission'] : 0.0;
        $total = round($commission, 2);
        $title = Notifications::productTitle(
            (int) $listing->variationId,
            (int) $listing->variationId,
            (int) $listing->parentProductId,
            (int) $listing->sizeTermId
        );
        $lines = [];
        if ($commission >= 0.01) {
            $lines[] = [
                'variation_id' => $variationId,
                'title' => $title,
                'code' => 'commission',
                'amount' => $commission,
            ];
        }
        $lineItems = (string) wp_json_encode($lines);

        $recipient = $kind === InvoiceKind::SELLER_COMMISSION
            ? $this->sellerEmail($listing)
            : sanitize_email((string) $order->get_billing_email());

        if ($total < 0.01) {
            $this->repo->insert([
                'kind' => $kind,
                'variation_id' => $variationId,
                'order_id' => $orderId,
                'merchant_id' => (int) $listing->merchantId,
                'status' => InvoiceStatus::SKIPPED,
                'hizmet_amount' => $hizmet,
                'guvence_amount' => $guvence,
                'commission_amount' => $commission,
                'total_amount' => 0,
                'line_items' => $lineItems,
                'recipient_email' => $recipient,
            ]);

            return;
        }

        $id = $this->repo->insert([
            'kind' => $kind,
            'variation_id' => $variationId,
            'order_id' => $orderId,
            'merchant_id' => (int) $listing->merchantId,
            'status' => InvoiceStatus::QUEUED,
            'hizmet_amount' => $hizmet,
            'guvence_amount' => $guvence,
            'commission_amount' => $commission,
            'total_amount' => $total,
            'line_items' => $lineItems,
            'recipient_email' => $recipient,
        ]);
        if ($id <= 0) {
            return;
        }

        $this->scheduleOne($id);
    }

    private function advance(object $row): void
    {
        $id = (int) $row->id;
        if ((string) $row->parasut_invoice_id === '') {
            $created = $this->createSalesInvoice($row);
            if ($created instanceof \WP_Error) {
                $this->fail($id, (int) $row->retry_count, $created->get_error_message());

                return;
            }
            $row = $this->repo->find($id) ?? $row;
        }

        if ((string) ($row->parasut_earchive_id ?? '') === '' && (string) ($row->parasut_job_id ?? '') === '') {
            $job = $this->ensureEarchive($row);
            if ($job instanceof \WP_Error) {
                if ($this->isAlreadyIssued($job->get_error_message())) {
                    $resolved = $this->resolveEarchiveFromInvoice($row);
                    if ($resolved instanceof \WP_Error) {
                        if ($this->isPending($resolved)) {
                            $this->wait($id, InvoiceStatus::WAITING_JOB, $resolved->get_error_message());
                            return;
                        }
                        $this->fail($id, (int) $row->retry_count, $resolved->get_error_message(), InvoiceStatus::WAITING_JOB);
                        return;
                    }
                    $row = $this->repo->find($id) ?? $row;
                } elseif ($this->isPending($job)) {
                    $this->wait($id, InvoiceStatus::WAITING_JOB, $job->get_error_message());
                    return;
                } else {
                    $this->fail($id, (int) $row->retry_count, $job->get_error_message(), InvoiceStatus::WAITING_JOB);
                    return;
                }
            } else {
                $row = $this->repo->find($id) ?? $row;
            }
        }

        if ((string) ($row->parasut_job_id ?? '') !== '' && (string) ($row->parasut_earchive_id ?? '') === '') {
            $polled = $this->pollJob($row);
            if ($polled instanceof \WP_Error) {
                if ($this->isPending($polled)) {
                    $this->wait($id, InvoiceStatus::WAITING_JOB, $polled->get_error_message());
                    return;
                }
                $this->fail($id, (int) $row->retry_count, $polled->get_error_message(), InvoiceStatus::WAITING_JOB);
                return;
            }
            $row = $this->repo->find($id) ?? $row;
        }

        if ((string) ($row->pdf_path ?? '') === '' || !is_readable((string) $row->pdf_path)) {
            $pdf = $this->storePdf($row);
            if ($pdf instanceof \WP_Error) {
                if ($this->isPending($pdf)) {
                    $this->wait($id, InvoiceStatus::WAITING_PDF, $pdf->get_error_message());
                    return;
                }
                $this->fail($id, (int) $row->retry_count, $pdf->get_error_message(), InvoiceStatus::WAITING_PDF);
                return;
            }
            $row = $this->repo->find($id) ?? $row;
        }

        $mailed = $this->mailer->send($row, (string) ($row->pdf_path ?? ''));
        if ($mailed instanceof \WP_Error) {
            $this->fail($id, (int) $row->retry_count, $mailed->get_error_message(), InvoiceStatus::WAITING_PDF);

            return;
        }

        $this->repo->update($id, [
            'status' => InvoiceStatus::SENT,
            'last_error' => '',
            'next_retry_at' => null,
        ]);
    }

    private function createSalesInvoice(object $row): true|\WP_Error
    {
        $order = wc_get_order((int) $row->order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('sutore_invoice_order', __('Order not found for this invoice.', 'sutore-marketplace'));
        }

        $listing = null;
        $variationId = (int) $row->variation_id;
        if ($variationId > 0) {
            $listing = (new \SutoreMarketplace\Modules\Listings\Repositories\ListingRepository())->find($variationId);
        }
        if ((string) $row->kind === InvoiceKind::SELLER_COMMISSION && !$listing instanceof Listing) {
            return new \WP_Error('sutore_invoice_listing', __('Listing not found.', 'sutore-marketplace'));
        }

        $person = (string) $row->kind === InvoiceKind::SELLER_COMMISSION && $listing instanceof Listing
            ? $this->sellerPerson($listing)
            : $this->customerPerson($order);
        $contactId = $this->catalog->contactId($person);
        if ($contactId instanceof \WP_Error) {
            return $contactId;
        }

        $lines = InvoiceRepository::decodeLines($row);
        if ($lines === []) {
            return new \WP_Error('sutore_invoice_empty', __('Invoice has no billable lines.', 'sutore-marketplace'));
        }

        $vat = InvoiceSettings::vatRate();
        $divisor = 1 + ($vat / 100);
        $details = [];
        $headline = '';

        foreach ($lines as $line) {
            $productId = $this->catalog->productId($line['code']);
            if ($productId instanceof \WP_Error) {
                return $productId;
            }
            $title = trim((string) $line['title']);
            if ($title === '' && $line['variation_id'] > 0) {
                $lineListing = (new \SutoreMarketplace\Modules\Listings\Repositories\ListingRepository())->find($line['variation_id']);
                if ($lineListing instanceof Listing) {
                    $title = Notifications::productTitle(
                        $line['variation_id'],
                        $line['variation_id'],
                        (int) $lineListing->parentProductId,
                        (int) $lineListing->sizeTermId
                    );
                }
            }
            if ($headline === '' && $title !== '') {
                $headline = $title;
            }
            $details[] = [
                'type' => 'sales_invoice_details',
                'attributes' => [
                    'quantity' => 1,
                    'unit_price' => round($line['amount'] / $divisor, 4),
                    'vat_rate' => $vat,
                    'description' => InvoiceLineName::description($line['code'], $title),
                ],
                'relationships' => [
                    'product' => [
                        'data' => ['id' => (string) $productId, 'type' => 'products'],
                    ],
                ],
            ];
        }

        $issueDate = wp_date('Y-m-d');
        $description = (string) $row->kind === InvoiceKind::SELLER_COMMISSION
            ? sprintf('Sutore — %s (sipariş #%d)', $headline !== '' ? $headline : 'komisyon', (int) $row->order_id)
            : sprintf('Sutore — sipariş #%d', (int) $row->order_id);
        $created = $this->client->post($this->client->companyPath('sales_invoices'), [
            'data' => [
                'type' => 'sales_invoices',
                'attributes' => [
                    'item_type' => 'invoice',
                    'description' => $description,
                    'issue_date' => $issueDate,
                    'due_date' => $issueDate,
                    'currency' => 'TRL',
                    'shipment_included' => false,
                ],
                'relationships' => [
                    'contact' => [
                        'data' => ['id' => (string) $contactId, 'type' => 'contacts'],
                    ],
                    'details' => ['data' => $details],
                ],
            ],
        ]);
        $invoiceId = (string) ($created['data']['id'] ?? '');
        if ($invoiceId === '' || empty($created['ok'])) {
            return new \WP_Error(
                'sutore_invoice_create',
                (string) ($created['error'] ?? __('Could not create the Paraşüt sales invoice.', 'sutore-marketplace'))
            );
        }

        $number = (string) ($created['data']['attributes']['invoice_no']
            ?? $created['data']['attributes']['invoice_id']
            ?? '');

        $this->repo->update((int) $row->id, [
            'parasut_contact_id' => $contactId,
            'parasut_invoice_id' => $invoiceId,
            'invoice_number' => $number,
            'invoice_date' => $issueDate,
            'status' => InvoiceStatus::WAITING_JOB,
        ]);

        return true;
    }

    private function ensureEarchive(object $row): true|\WP_Error
    {
        $invoiceId = (string) ($row->parasut_invoice_id ?? '');
        if ($invoiceId === '') {
            return new \WP_Error('sutore_invoice_missing', __('Paraşüt invoice id is missing.', 'sutore-marketplace'));
        }

        $order = wc_get_order((int) $row->order_id);
        $paidDate = $order instanceof \WC_Order ? $order->get_date_paid() : null;
        $paymentDate = $paidDate ? wp_date('Y-m-d', $paidDate->getTimestamp()) : (string) ($row->invoice_date ?: wp_date('Y-m-d'));

        $created = $this->client->post($this->client->companyPath('e_archives'), [
            'data' => [
                'type' => 'e_archives',
                'attributes' => [
                    'internet_sale' => [
                        'url' => InvoiceSettings::shopUrl(),
                        'payment_type' => 'KREDIKARTI/BANKAKARTI',
                        'payment_platform' => 'Sutore',
                        'payment_date' => $paymentDate,
                    ],
                ],
                'relationships' => [
                    'sales_invoice' => [
                        'data' => ['id' => $invoiceId, 'type' => 'sales_invoices'],
                    ],
                ],
            ],
        ]);

        if (empty($created['ok'])) {
            return new \WP_Error(
                'sutore_invoice_earchive',
                (string) ($created['error'] ?? __('Could not create the e-Archive document.', 'sutore-marketplace'))
            );
        }

        $type = (string) ($created['data']['type'] ?? '');
        $jobId = '';
        $earchiveId = '';
        if ($type === 'trackable_jobs') {
            $jobId = (string) ($created['data']['id'] ?? '');
        } elseif ($type === 'e_archives') {
            $earchiveId = (string) ($created['data']['id'] ?? '');
        }

        $patch = ['status' => InvoiceStatus::WAITING_JOB];
        if ($jobId !== '') {
            $patch['parasut_job_id'] = $jobId;
        }
        if ($earchiveId !== '') {
            $patch['parasut_earchive_id'] = $earchiveId;
            $patch['status'] = InvoiceStatus::WAITING_PDF;
        }
        $this->repo->update((int) $row->id, $patch);

        return true;
    }

    private function pollJob(object $row): true|\WP_Error
    {
        $jobId = (string) ($row->parasut_job_id ?? '');
        if ($jobId === '') {
            return $this->resolveEarchiveFromInvoice($row);
        }

        $job = $this->client->get($this->client->companyPath('trackable_jobs/' . rawurlencode($jobId)));
        if (empty($job['ok'])) {
            return new \WP_Error(
                'sutore_invoice_job',
                (string) ($job['error'] ?? __('Could not read the e-Archive job.', 'sutore-marketplace'))
            );
        }

        $status = strtolower((string) ($job['data']['attributes']['status'] ?? ''));
        if (in_array($status, ['pending', 'running', 'in_progress', 'processing'], true) || $status === '') {
            $this->repo->update((int) $row->id, ['status' => InvoiceStatus::WAITING_JOB]);

            return new \WP_Error('sutore_invoice_job_pending', __('e-Archive is still being issued.', 'sutore-marketplace'));
        }
        if ($status !== 'done') {
            $errors = $job['data']['attributes']['errors'] ?? $job['errors'] ?? null;
            $detail = is_array($errors) ? wp_json_encode($errors, JSON_UNESCAPED_UNICODE) : (string) $errors;
            $resolved = $this->resolveEarchiveFromInvoice($row);
            if (!$resolved instanceof \WP_Error) {
                return true;
            }
            if ($this->isAlreadyIssued($detail) && $this->isPending($resolved)) {
                return $resolved;
            }

            return new \WP_Error(
                'sutore_invoice_job_failed',
                $detail !== '' && $detail !== 'null'
                    ? $detail
                    : __('e-Archive job failed.', 'sutore-marketplace')
            );
        }

        return $this->resolveEarchiveFromInvoice($row);
    }

    private function resolveEarchiveFromInvoice(object $row): true|\WP_Error
    {
        $invoiceId = (string) ($row->parasut_invoice_id ?? '');
        $fetched = $this->client->get($this->client->companyPath('sales_invoices/' . rawurlencode($invoiceId)), [
            'include' => 'active_e_document',
        ]);
        if (empty($fetched['ok'])) {
            return new \WP_Error(
                'sutore_invoice_fetch',
                (string) ($fetched['error'] ?? __('Could not load the Paraşüt invoice.', 'sutore-marketplace'))
            );
        }

        $number = (string) ($fetched['data']['attributes']['invoice_no']
            ?? $fetched['data']['attributes']['invoice_id']
            ?? $row->invoice_number
            ?? '');
        $earchiveId = (string) ($fetched['data']['relationships']['active_e_document']['data']['id'] ?? '');
        if ($earchiveId === '') {
            $included = $fetched['included'] ?? [];
            if (is_array($included)) {
                foreach ($included as $item) {
                    if (is_array($item) && ($item['type'] ?? '') === 'e_archives' && !empty($item['id'])) {
                        $earchiveId = (string) $item['id'];
                        break;
                    }
                }
            }
        }
        if ($earchiveId === '') {
            return new \WP_Error(
                'sutore_invoice_earchive_id',
                __('e-Archive document is not ready yet.', 'sutore-marketplace')
            );
        }

        $this->repo->update((int) $row->id, [
            'parasut_earchive_id' => $earchiveId,
            'invoice_number' => $number,
            'status' => InvoiceStatus::WAITING_PDF,
        ]);

        return true;
    }

    private function storePdf(object $row): true|\WP_Error
    {
        $earchiveId = (string) ($row->parasut_earchive_id ?? '');
        if ($earchiveId === '') {
            return new \WP_Error('sutore_invoice_pdf_id', __('e-Archive id is missing.', 'sutore-marketplace'));
        }

        $bytes = $this->downloadPdfBytes($earchiveId);
        if ($bytes instanceof \WP_Error) {
            return $bytes;
        }

        $path = (new InvoiceStorage())->pathFor((int) $row->id);
        if ($path instanceof \WP_Error) {
            return $path;
        }

        $written = file_put_contents($path, $bytes);
        if ($written === false) {
            return new \WP_Error('sutore_invoice_write', __('Could not save the invoice PDF.', 'sutore-marketplace'));
        }

        $this->repo->update((int) $row->id, [
            'pdf_path' => $path,
            'status' => InvoiceStatus::WAITING_PDF,
        ]);

        return true;
    }

    private function downloadPdfBytes(string $earchiveId): string|\WP_Error
    {
        $raw = $this->client->requestRaw('GET', $this->client->companyPath('e_archives/' . rawurlencode($earchiveId) . '/pdf'));
        $status = (int) ($raw['status'] ?? 0);
        if ($status === 204) {
            return new \WP_Error('sutore_invoice_pdf_pending', __('e-Archive PDF is not ready yet.', 'sutore-marketplace'));
        }
        if (empty($raw['ok'])) {
            return new \WP_Error(
                'sutore_invoice_pdf_http',
                (string) ($raw['error'] ?? sprintf(
                    /* translators: %d: HTTP status */
                    __('PDF download failed (%d).', 'sutore-marketplace'),
                    $status
                ))
            );
        }

        $contentType = strtolower((string) ($raw['content_type'] ?? ''));
        $body = (string) ($raw['body'] ?? '');
        if (str_contains($contentType, 'pdf') || str_starts_with($body, '%PDF')) {
            return $body;
        }

        $decoded = json_decode($body, true);
        $url = is_array($decoded) ? (string) ($decoded['data']['attributes']['url'] ?? '') : '';
        if ($url === '') {
            return new \WP_Error('sutore_invoice_pdf_url', __('Paraşüt did not return a PDF URL.', 'sutore-marketplace'));
        }

        return $this->client->download($url);
    }

    private function wait(int $id, string $status, string $message): void
    {
        $this->repo->update($id, [
            'status' => $status,
            'last_error' => substr($message, 0, 1000),
            'next_retry_at' => wp_date('Y-m-d H:i:s', time() + MINUTE_IN_SECONDS),
        ]);
    }

    private function isPending(\WP_Error $error): bool
    {
        return in_array($error->get_error_code(), [
            'sutore_invoice_job_pending',
            'sutore_invoice_pdf_pending',
            'sutore_invoice_earchive_id',
        ], true);
    }

    private function isAlreadyIssued(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'resmile')
            || str_contains($normalized, 'already been issued')
            || str_contains($normalized, 'already issued');
    }

    private function fail(int $id, int $retryCount, string $message, string $status = InvoiceStatus::ERROR): void
    {
        $retryCount++;
        $minutes = min(360, (int) (5 * (2 ** min(8, max(0, $retryCount - 1)))));
        $next = wp_date('Y-m-d H:i:s', time() + $minutes * MINUTE_IN_SECONDS);
        $this->repo->update($id, [
            'status' => $status,
            'last_error' => substr($message, 0, 1000),
            'retry_count' => $retryCount,
            'next_retry_at' => $next,
        ]);
    }

    private function scheduleOne(int $id): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(InvoiceCronHooks::HOOK_ONE, [$id], InvoiceCronHooks::GROUP);
        }
    }

    /** @return array{name:string,email:string,tax_number:string,phone:string,city:string,district:string,address:string} */
    private function customerPerson(\WC_Order $order): array
    {
        return [
            'name' => trim($order->get_formatted_billing_full_name()),
            'email' => sanitize_email((string) $order->get_billing_email()),
            'tax_number' => preg_replace('/\D/', '', (string) $order->get_meta(CheckoutIdentityHooks::ORDER_META_TCKNO, true)) ?? '',
            'phone' => (string) $order->get_billing_phone(),
            'city' => (string) $order->get_billing_city(),
            'district' => (string) $order->get_billing_state(),
            'address' => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
        ];
    }

    /** @return array{name:string,email:string,tax_number:string,phone:string,city:string,district:string,address:string} */
    private function sellerPerson(Listing $listing): array
    {
        $row = (new \SutoreMarketplace\Modules\Orders\Repositories\FulfillmentRepository())->find((int) $listing->variationId);
        $snapshot = MerchantSnapshot::decode($row->merchant_snapshot ?? null);
        $profile = \SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::readProfile((int) $listing->merchantId);

        $name = trim((string) ($snapshot['name'] ?? ''));
        if ($name === '') {
            $name = trim(($profile[\SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::ACCOUNT_NAME] ?? '') . ' ' . ($profile[\SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::ACCOUNT_LASTNAME] ?? ''));
        }

        $email = sanitize_email((string) ($snapshot['email'] ?? ''));
        if ($email === '') {
            $email = sanitize_email((string) ($profile[\SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::ACCOUNT_EMAIL] ?? ''));
        }

        $tc = preg_replace('/\D/', '', (string) ($snapshot['tc'] ?? '')) ?? '';
        if ($tc === '') {
            $tc = preg_replace('/\D/', '', (string) ($profile[\SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::ACCOUNT_TCKNO] ?? '')) ?? '';
        }

        return [
            'name' => $name,
            'email' => $email,
            'tax_number' => $tc,
            'phone' => (string) ($snapshot['phone'] ?? $profile[\SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::ACCOUNT_PHONE] ?? ''),
            'city' => (string) ($snapshot['city'] ?? $profile[\SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::ACCOUNT_CITY] ?? ''),
            'district' => (string) ($snapshot['state'] ?? $profile[\SutoreMarketplace\Modules\Merchants\Support\MerchantMeta::ACCOUNT_STATE] ?? ''),
            'address' => '',
        ];
    }

    private function sellerEmail(Listing $listing): string
    {
        $person = $this->sellerPerson($listing);

        return $person['email'];
    }
}
