<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Invoices\Domain;

final class InvoiceStatus
{
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const WAITING_JOB = 'waiting_job';
    public const WAITING_PDF = 'waiting_pdf';
    public const SENT = 'sent';
    public const SKIPPED = 'skipped';
    public const ERROR = 'error';

    public static function label(string $status): string
    {
        return match ($status) {
            self::QUEUED => __('Queued', 'sutore-marketplace'),
            self::PROCESSING => __('Processing', 'sutore-marketplace'),
            self::WAITING_JOB => __('Waiting for e-Archive', 'sutore-marketplace'),
            self::WAITING_PDF => __('Waiting for PDF', 'sutore-marketplace'),
            self::SENT => __('Sent', 'sutore-marketplace'),
            self::SKIPPED => __('Skipped (zero amount)', 'sutore-marketplace'),
            self::ERROR => __('Error', 'sutore-marketplace'),
            default => $status,
        };
    }

    /** @return list<string> */
    public static function dueForCron(): array
    {
        return [self::QUEUED, self::WAITING_JOB, self::WAITING_PDF, self::ERROR];
    }
}
