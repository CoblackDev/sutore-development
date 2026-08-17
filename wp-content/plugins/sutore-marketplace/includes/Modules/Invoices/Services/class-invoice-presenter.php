<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Services;

use SutoreMarketplace\Modules\Invoices\Domain\InvoiceKind;
use SutoreMarketplace\Modules\Invoices\Domain\InvoiceStatus;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class InvoicePresenter
{
    /**
     * @param list<object> $rows
     * @return list<array<string, mixed>>
     */
    public static function forStaff(array $rows): array
    {
        $out = [];
        $storage = new InvoiceStorage();
        foreach ($rows as $row) {
            $date = trim((string) ($row->invoice_date ?? ''));
            $dateTs = $date !== '' ? strtotime($date . ' 00:00:00') : false;
            $hasPdf = $storage->isReadable((string) ($row->pdf_path ?? ''));
            $id = (int) $row->id;
            $out[] = [
                'id' => $id,
                'kind' => (string) $row->kind,
                'kind_label' => InvoiceKind::label((string) $row->kind),
                'status' => (string) $row->status,
                'status_label' => InvoiceStatus::label((string) $row->status),
                'invoice_number' => (string) ($row->invoice_number ?? ''),
                'invoice_date' => $date,
                'invoice_date_display' => $dateTs
                    ? (string) wp_date((string) get_option('date_format'), $dateTs)
                    : '',
                'amount' => round((float) ($row->total_amount ?? 0), 2),
                'amount_display' => MarketplacePricing::formatTl((float) ($row->total_amount ?? 0)),
                'recipient_email' => (string) ($row->recipient_email ?? ''),
                'last_error' => (string) ($row->last_error ?? ''),
                'has_pdf' => $hasPdf,
                'pdf_url' => $hasPdf ? self::pdfUrl($id) : '',
            ];
        }

        return $out;
    }

    public static function pdfUrl(int $id): string
    {
        return add_query_arg(
            '_wpnonce',
            wp_create_nonce('wp_rest'),
            rest_url('sutore-marketplace/v1/invoices/' . $id . '/pdf')
        );
    }

    /**
     * @param list<object> $rows
     */
    public static function summary(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $parts = [];
        foreach ($rows as $row) {
            $parts[] = InvoiceKind::label((string) $row->kind) . ': ' . InvoiceStatus::label((string) $row->status);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param list<object> $rows
     */
    public static function hasError(array $rows): bool
    {
        foreach ($rows as $row) {
            if ((string) $row->status === InvoiceStatus::ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<object> $rows
     */
    public static function byKind(array $rows, string $kind): ?object
    {
        foreach ($rows as $row) {
            if ((string) $row->kind === $kind) {
                return $row;
            }
        }

        return null;
    }
}
