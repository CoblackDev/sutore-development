<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Services;

use SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind;

final class InvoiceMailer
{
    public function send(object $row, string $pdfPath): true|\WP_Error
    {
        $to = sanitize_email((string) ($row->recipient_email ?? ''));
        if ($to === '' || !is_email($to)) {
            return new \WP_Error(
                'sutore_invoice_email',
                __('Invoice recipient email is missing.', 'sutore-marketplace')
            );
        }
        if ($pdfPath === '' || !is_readable($pdfPath)) {
            return new \WP_Error(
                'sutore_invoice_pdf_file',
                __('Invoice PDF is not available to attach.', 'sutore-marketplace')
            );
        }

        $number = trim((string) ($row->invoice_number ?? ''));
        if ($number === '') {
            $number = '#' . (int) $row->id;
        }

        $subject = sprintf(
            /* translators: %s: invoice number */
            __('Your Sutore invoice %s', 'sutore-marketplace'),
            $number
        );
        $kind = (string) ($row->kind ?? '');
        $intro = $kind === InvoiceKind::SELLER_COMMISSION
            ? __('Please find attached the e-Archive invoice for the marketplace commission on your sale.', 'sutore-marketplace')
            : __('Please find attached the e-Archive invoice for the marketplace service and guarantee fees on your order.', 'sutore-marketplace');

        $body = implode("\n\n", [
            $intro,
            sprintf(
                /* translators: %s: invoice number */
                __('Invoice number: %s', 'sutore-marketplace'),
                $number
            ),
            __('Sutore', 'sutore-marketplace'),
        ]);

        $sent = wp_mail(
            $to,
            $subject,
            $body,
            ['Content-Type: text/plain; charset=UTF-8'],
            [$pdfPath]
        );

        if (!$sent) {
            return new \WP_Error(
                'sutore_invoice_mail_failed',
                __('The invoice email could not be sent.', 'sutore-marketplace')
            );
        }

        return true;
    }
}
