<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Rest;

use SutoreMarketplace\Admin\StaffCapabilities;
use SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind;
use SutoreMarketplace\Modules\Invoices\Repositories\InvoiceRepository;
use SutoreMarketplace\Modules\Invoices\Services\InvoiceStorage;
use SutoreMarketplace\Shared\Rest\RestResponse;

final class InvoicesController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route('sutore-marketplace/v1', '/invoices/(?P<id>\d+)/pdf', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'pdf'],
                'permission_callback' => [$this, 'canView'],
                'args' => [
                    'id' => [
                        'type' => 'integer',
                        'required' => true,
                    ],
                ],
            ],
        ]);
    }

    public function canView(\WP_REST_Request $request): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $row = (new InvoiceRepository())->find((int) $request['id']);
        if (!$row) {
            return false;
        }

        if (StaffCapabilities::canManageOps()) {
            return true;
        }

        $userId = get_current_user_id();
        $kind = (string) $row->kind;
        if ($kind === InvoiceKind::SELLER_COMMISSION) {
            return $userId > 0 && $userId === (int) $row->merchant_id;
        }

        if ($kind === InvoiceKind::CUSTOMER_FEES) {
            $order = wc_get_order((int) $row->order_id);

            return $order instanceof \WC_Order && $userId > 0 && $userId === (int) $order->get_user_id();
        }

        return false;
    }

    public function pdf(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $row = (new InvoiceRepository())->find((int) $request['id']);
        if (!$row) {
            return RestResponse::fail(__('Invoice not found.', 'sutore-marketplace'), 404, 'sutore_invoice_missing');
        }

        $path = (string) ($row->pdf_path ?? '');
        if (!(new InvoiceStorage())->isReadable($path)) {
            return RestResponse::fail(
                __('The invoice PDF is not available yet.', 'sutore-marketplace'),
                404,
                'sutore_invoice_pdf_missing'
            );
        }

        $number = trim((string) ($row->invoice_number ?? ''));
        $filename = ($number !== '' ? $number : 'invoice-' . (int) $row->id) . '.pdf';
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'invoice.pdf';

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }
}
