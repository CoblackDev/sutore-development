<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingConditionRank;
use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ListingPriceValidator;
use SutoreMarketplace\Modules\Listings\Domain\ProductCodeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Listings\Hooks\ListingBulkImportScheduler;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Tasks\Services\TaskProgressService;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Shared\Domain\ReleasePriceService;
use SutoreMarketplace\Shared\Settings\Settings;

final class ListingBulkImportService
{
    public const MAX_ROWS = 200;

    public const BATCH_SIZE = 10;

    private const TRANSIENT_PREFIX = 'sutore_mp_bulk_';

    private const JOB_TRANSIENT_PREFIX = 'sutore_mp_bulk_job_';

    private const TRANSIENT_TTL = 900;

    private const JOB_TTL = DAY_IN_SECONDS;

    /** @var list<string> */
    private const REQUIRED_HEADERS = ['product_code', 'size', 'price'];

    /** @var list<string> */
    private const OPTIONAL_HEADERS = [
        'no_box',
        'box_damaged',
        'missing_accessory',
        'damaged',
        'express',
        'international',
    ];

    public function __construct(
        private readonly ListingService $listings = new ListingService(),
        private readonly ListingSelector $selector = new ListingSelector(),
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
    ) {
    }

    public function templateCsv(): string
    {
        $headers = array_merge(self::REQUIRED_HEADERS, self::OPTIONAL_HEADERS);
        $lines = [implode(',', $headers)];
        $lines[] = 'AJ1-001,42,12500,0,0,0,0,0,0';
        $lines[] = 'AJ1-001,43,12800,1,0,0,0,0,1';
        $lines[] = 'DUNK-220,41,9900,0,0,0,0,0,0';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{import_token: string, expires_in: int, summary: array<string, int>, rows: list<array<string, mixed>>}|\WP_Error
     */
    public function validate(string $csv, int $merchantId): array|\WP_Error
    {
        $auth = ListingPolicy::assertCanManage($merchantId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $parsed = $this->parseCsv($csv);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $rows = [];
        $summary = ['total' => 0, 'ready' => 0, 'warning' => 0, 'error' => 0];
        $fingerprints = [];

        foreach ($parsed as $item) {
            $line = (int) $item['line'];
            $row = $this->validateRow($item['data'], $line, $merchantId, $fingerprints);
            $rows[] = $row;
            $summary['total']++;
            $summary[$row['status']]++;
            if ($row['status'] !== 'error' && !empty($row['row_key'])) {
                $fingerprints[(string) $row['row_key']] = $line;
            }
        }

        $rows = $this->enrichPreviewContext($rows, $merchantId);

        $token = wp_generate_password(32, false, false);
        set_transient(self::TRANSIENT_PREFIX . $token, [
            'merchant_id' => $merchantId,
            'rows' => $rows,
            'created_at' => time(),
        ], self::TRANSIENT_TTL);

        return [
            'import_token' => $token,
            'expires_in' => self::TRANSIENT_TTL,
            'summary' => $summary,
            'rows' => $this->publicRows($rows),
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function publicRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            unset($row['input'], $row['row_key'], $row['csv_data']);

            return $row;
        }, $rows);
    }

    /**
     * @return array{import_token: string, summary: array<string, int>, rows: list<array<string, mixed>>}|\WP_Error
     */
    public function updateRowPrice(string $token, int $merchantId, int $line, string $priceRaw): array|\WP_Error
    {
        $auth = ListingPolicy::assertCanManage($merchantId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $payload = get_transient(self::TRANSIENT_PREFIX . $token);
        if (!is_array($payload) || (int) ($payload['merchant_id'] ?? 0) !== $merchantId) {
            return new \WP_Error(
                'sutore_bulk_import_expired',
                __('Import session expired. Please upload the file again.', 'sutore-marketplace'),
                ['status' => 410]
            );
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $payload['rows'] ?? [];
        $found = false;
        foreach ($rows as $row) {
            if ((int) ($row['line'] ?? 0) === $line) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return new \WP_Error(
                'sutore_bulk_row_missing',
                __('Row not found in import preview.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $fingerprints = [];
        $refreshed = [];
        foreach ($rows as $row) {
            $rowLine = (int) ($row['line'] ?? 0);
            $csvData = $row['csv_data'] ?? null;
            if (!is_array($csvData)) {
                $refreshed[] = $row;
                continue;
            }

            if ($rowLine === $line) {
                $csvData['price'] = trim($priceRaw);
            }

            $refreshed[] = $this->validateRow($csvData, $rowLine, $merchantId, $fingerprints);
            $last = $refreshed[count($refreshed) - 1];
            if ($last['status'] !== 'error' && !empty($last['row_key'])) {
                $fingerprints[(string) $last['row_key']] = $rowLine;
            }
        }

        $refreshed = $this->enrichPreviewContext($refreshed, $merchantId);
        $summary = $this->summarizeRows($refreshed);

        set_transient(self::TRANSIENT_PREFIX . $token, [
            'merchant_id' => $merchantId,
            'rows' => $refreshed,
            'created_at' => (int) ($payload['created_at'] ?? time()),
        ], self::TRANSIENT_TTL);

        return [
            'import_token' => $token,
            'summary' => $summary,
            'rows' => $this->publicRows($refreshed),
        ];
    }

    /**
     * @return array{import_token: string, summary: array<string, int>, rows: list<array<string, mixed>>}|\WP_Error
     */
    public function deleteRow(string $token, int $merchantId, int $line): array|\WP_Error
    {
        $auth = ListingPolicy::assertCanManage($merchantId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $payload = get_transient(self::TRANSIENT_PREFIX . $token);
        if (!is_array($payload) || (int) ($payload['merchant_id'] ?? 0) !== $merchantId) {
            return new \WP_Error(
                'sutore_bulk_import_expired',
                __('Import session expired. Please upload the file again.', 'sutore-marketplace'),
                ['status' => 410]
            );
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $payload['rows'] ?? [];
        $remaining = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) ($row['line'] ?? 0) !== $line
        ));

        if (count($remaining) === count($rows)) {
            return new \WP_Error(
                'sutore_bulk_row_missing',
                __('Row not found in import preview.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $fingerprints = [];
        $refreshed = [];
        foreach ($remaining as $row) {
            $rowLine = (int) ($row['line'] ?? 0);
            $csvData = $row['csv_data'] ?? null;
            if (!is_array($csvData)) {
                $refreshed[] = $row;
                continue;
            }

            $refreshed[] = $this->validateRow($csvData, $rowLine, $merchantId, $fingerprints);
            $last = $refreshed[count($refreshed) - 1];
            if ($last['status'] !== 'error' && !empty($last['row_key'])) {
                $fingerprints[(string) $last['row_key']] = $rowLine;
            }
        }

        $refreshed = $this->enrichPreviewContext($refreshed, $merchantId);
        $summary = $this->summarizeRows($refreshed);

        set_transient(self::TRANSIENT_PREFIX . $token, [
            'merchant_id' => $merchantId,
            'rows' => $refreshed,
            'created_at' => (int) ($payload['created_at'] ?? time()),
        ], self::TRANSIENT_TTL);

        return [
            'import_token' => $token,
            'summary' => $summary,
            'rows' => $this->publicRows($refreshed),
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function enrichPreviewContext(array $rows, int $merchantId): array
    {
        /** @var array<int, Listing> $drafts */
        $drafts = [];
        foreach ($rows as $row) {
            if (!in_array((string) ($row['status'] ?? ''), ['ready', 'warning'], true)) {
                continue;
            }
            if (!is_array($row['input'] ?? null)) {
                continue;
            }

            $line = (int) ($row['line'] ?? 0);
            if ($line <= 0) {
                continue;
            }

            $draft = $this->buildPreviewDraft($row, $merchantId, $line);
            if ($draft !== null) {
                $drafts[$line] = $draft;
            }
        }

        if ($drafts === []) {
            return $rows;
        }

        $repo = new ListingRepository();
        $merchantAutoActivates = Settings::merchantAutoActivates($merchantId);

        foreach ($rows as &$row) {
            $line = (int) ($row['line'] ?? 0);
            if ($line <= 0 || !isset($drafts[$line])) {
                $row['preview'] = null;
                continue;
            }

            $draft = $drafts[$line];
            $parentId = $draft->parentProductId;
            $sizeTermId = $draft->sizeTermId;

            $sizeLeader = $repo->getLowestOnSaleForSize($parentId, $sizeTermId);
            $slotWinner = $repo->getWinnerForSize($parentId, $sizeTermId);
            if (!$slotWinner) {
                $competing = $repo->findCompetingForSize($parentId, $sizeTermId);
                $slotWinner = $competing[0] ?? null;
            }
            $priceLeader = $sizeLeader ?: $slotWinner;
            $minAsking = $priceLeader ? MarketplacePricing::activeAsking($priceLeader) : null;

            $ranked = $this->rankPreviewBatch($parentId, $sizeTermId, $drafts, $repo);
            $draftId = -$line;
            $queuePosition = 1;
            $queueTotal = count($ranked);
            foreach ($ranked as $index => $listing) {
                if ((int) $listing->id === $draftId) {
                    $queuePosition = $index + 1;
                    break;
                }
            }

            $blocked = ListingConditionRank::isBlockedByBetterCondition($ranked, $draftId);
            $canWin = $queuePosition === 1 && !$blocked;
            $firstPlace = ListingConditionRank::firstPlaceAskingForDraft($ranked, $draftId);

            $row['preview'] = [
                'min_on_sale_asking' => $minAsking,
                'min_on_sale_display' => $minAsking !== null
                    ? MarketplacePricing::formatTl($minAsking)
                    : null,
                'no_active_sale' => $priceLeader === null,
                'queue_position' => $queuePosition,
                'queue_total' => $queueTotal,
                'can_win_sale' => $canWin,
                'merchant_auto_activates' => $merchantAutoActivates,
                'blocked_by_better_condition' => $blocked,
                'first_place_asking' => $firstPlace,
                'first_place_display' => $firstPlace !== null
                    ? MarketplacePricing::formatTl($firstPlace)
                    : null,
                'show_first_place_button' => $firstPlace !== null,
            ];
        }
        unset($row);

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function buildPreviewDraft(array $row, int $merchantId, int $line): ?Listing
    {
        $input = $row['input'] ?? null;
        if (!is_array($input)) {
            return null;
        }

        $parentId = (int) ($input['parent_product_id'] ?? 0);
        $sizeTermId = (int) ($input['size_term_id'] ?? 0);
        $asking = (int) ($input['asking'] ?? 0);
        if ($parentId <= 0 || $sizeTermId <= 0 || $asking <= 0) {
            return null;
        }

        $conditions = is_array($row['conditions'] ?? null) ? $row['conditions'] : [];
        [$fastShipment, $hasInvoice] = ListingRepository::resolveShippingFlags([
            'fast_shipment' => !empty($row['express']),
            'has_invoice' => !empty($row['international']),
        ]);
        $fingerprint = ListingRepository::fingerprint($conditions, $fastShipment, $hasInvoice);
        $createdAt = gmdate('Y-m-d H:i:s', 2000000000 + $line);

        return new Listing(
            id: -$line,
            variationId: 0,
            parentProductId: $parentId,
            sizeTermId: $sizeTermId,
            merchantId: $merchantId,
            listingStatus: 'pending',
            asking: (float) $asking,
            conditionFingerprint: $fingerprint,
            fastShipment: $fastShipment,
            hasInvoice: $hasInvoice,
            createdAt: $createdAt,
            conditions: $conditions,
        );
    }

    /**
     * @param array<int, Listing> $drafts
     * @return Listing[]
     */
    private function rankPreviewBatch(int $parentId, int $sizeTermId, array $drafts, ListingRepository $repo): array
    {
        $candidates = array_values(array_filter(
            $repo->findCompetingForSize($parentId, $sizeTermId),
            static fn (Listing $listing): bool => in_array($listing->listingStatus, ['publish', 'queued', 'pending'], true)
        ));

        foreach ($drafts as $draft) {
            if ($draft->parentProductId === $parentId && $draft->sizeTermId === $sizeTermId) {
                $candidates[] = $draft;
            }
        }

        return ListingConditionRank::sortForSale($candidates);
    }

    /** @param list<array<string, mixed>> $rows @return array<string, int> */
    private function summarizeRows(array $rows): array
    {
        $summary = ['total' => 0, 'ready' => 0, 'warning' => 0, 'error' => 0];
        foreach ($rows as $row) {
            $summary['total']++;
            $status = (string) ($row['status'] ?? 'error');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    /**
     * Queue a background import job (Action Scheduler).
     *
     * @return array{job_id: string, status: string, total_rows: int, skipped_rows: int}|\WP_Error
     */
    public function queueJob(string $token, int $merchantId): array|\WP_Error
    {
        $auth = ListingPolicy::assertCanManage($merchantId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        if (!ListingBulkImportScheduler::isAvailable()) {
            return new \WP_Error(
                'sutore_bulk_scheduler_unavailable',
                __('Background import is unavailable. Please ensure WooCommerce is active.', 'sutore-marketplace'),
                ['status' => 503]
            );
        }

        if (BulkImportContext::isActiveForMerchant($merchantId)) {
            return new \WP_Error(
                'sutore_bulk_import_active',
                __('Another bulk import is already running for your account.', 'sutore-marketplace'),
                ['status' => 409]
            );
        }

        $payload = get_transient(self::TRANSIENT_PREFIX . $token);
        if (!is_array($payload) || (int) ($payload['merchant_id'] ?? 0) !== $merchantId) {
            return new \WP_Error(
                'sutore_bulk_import_expired',
                __('Import session expired. Please upload the file again.', 'sutore-marketplace'),
                ['status' => 410]
            );
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $payload['rows'] ?? [];
        $pending = [];
        $failed = [];

        foreach ($rows as $row) {
            $line = (int) ($row['line'] ?? 0);
            $status = (string) ($row['status'] ?? 'error');
            if ($status === 'error') {
                $failed[] = $this->failRow($line, 'skipped', __('Row has errors.', 'sutore-marketplace'));
                continue;
            }

            $input = $row['input'] ?? null;
            if (!is_array($input)) {
                $failed[] = $this->failRow($line, 'invalid_row', __('Invalid row data.', 'sutore-marketplace'));
                continue;
            }

            $pending[] = [
                'line' => $line,
                'input' => $input,
            ];
        }

        if ($pending === []) {
            return new \WP_Error(
                'sutore_bulk_no_rows',
                __('No valid rows to import. Fix the CSV and try again.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $jobId = wp_generate_password(32, false, false);
        $job = [
            'job_id' => $jobId,
            'merchant_id' => $merchantId,
            'status' => 'queued',
            'pending' => $pending,
            'offset' => 0,
            'created' => [],
            'failed' => $failed,
            'size_slots' => [],
            'summary' => [],
            'queued_at' => time(),
            'started_at' => null,
            'completed_at' => null,
            'error_message' => '',
        ];

        $this->saveJob($jobId, $job);
        delete_transient(self::TRANSIENT_PREFIX . $token);

        if (!ListingBulkImportScheduler::schedule($jobId)) {
            delete_transient(self::JOB_TRANSIENT_PREFIX . $jobId);

            return new \WP_Error(
                'sutore_bulk_schedule_failed',
                __('Could not queue the import job.', 'sutore-marketplace'),
                ['status' => 500]
            );
        }

        BulkImportContext::activate($merchantId, $jobId);

        return [
            'job_id' => $jobId,
            'status' => 'queued',
            'total_rows' => count($pending),
            'skipped_rows' => count($failed),
        ];
    }

    public function processJobBatch(string $jobId): void
    {
        $job = $this->loadJob($jobId);
        if ($job === null || in_array((string) ($job['status'] ?? ''), ['completed', 'failed'], true)) {
            return;
        }

        $merchantId = (int) ($job['merchant_id'] ?? 0);
        if ($merchantId <= 0) {
            return;
        }

        if ((int) ($job['offset'] ?? 0) === 0) {
            $job['status'] = 'processing';
            $job['started_at'] = time();
        }

        /** @var list<array{line: int, input: array<string, mixed>}> $pending */
        $pending = $job['pending'] ?? [];
        $offset = (int) ($job['offset'] ?? 0);
        $batch = array_slice($pending, $offset, self::BATCH_SIZE);
        $createOptions = ['defer_selector' => true, 'skip_task_increment' => true];

        try {
            foreach ($batch as $row) {
                $line = (int) ($row['line'] ?? 0);
                $input = $row['input'] ?? null;
                if (!is_array($input)) {
                    $job['failed'][] = $this->failRow($line, 'invalid_row', __('Invalid row data.', 'sutore-marketplace'));
                    continue;
                }

                $result = $this->listings->create($input, $merchantId, $createOptions);
                if (is_wp_error($result)) {
                    $job['failed'][] = [
                        'line' => $line,
                        'code' => $result->get_error_code(),
                        'message' => $result->get_error_message(),
                    ];
                    continue;
                }

                $parentId = (int) ($input['parent_product_id'] ?? 0);
                $sizeTermId = (int) ($input['size_term_id'] ?? 0);
                if ($parentId && $sizeTermId) {
                    $job['size_slots'][$parentId . ':' . $sizeTermId] = [$parentId, $sizeTermId];
                }

                $job['created'][] = [
                    'line' => $line,
                    'listing_id' => (int) $result->id,
                ];
            }

            $job['offset'] = $offset + count($batch);
            $this->saveJob($jobId, $job);

            if ($job['offset'] < count($pending)) {
                $this->processJobBatch($jobId);

                return;
            }

            $this->finalizeJob($jobId, $job);
        } catch (\Throwable $e) {
            $job['status'] = 'failed';
            $job['error_message'] = $e->getMessage();
            $job['completed_at'] = time();
            $this->saveJob($jobId, $job);
            BulkImportContext::deactivate($merchantId);
        }
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function getJob(string $jobId, int $merchantId): array|\WP_Error
    {
        $auth = ListingPolicy::assertCanManage($merchantId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $job = $this->loadJob($jobId);
        if ($job === null || (int) ($job['merchant_id'] ?? 0) !== $merchantId) {
            return new \WP_Error(
                'sutore_bulk_job_missing',
                __('Import job not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        return $this->publicJob($job);
    }

    /** @param array<string, mixed> $job */
    private function finalizeJob(string $jobId, array $job): void
    {
        $merchantId = (int) ($job['merchant_id'] ?? 0);
        $created = $job['created'] ?? [];
        $failed = $job['failed'] ?? [];
        $sizeSlots = $job['size_slots'] ?? [];

        foreach ($sizeSlots as $slot) {
            if (!is_array($slot) || count($slot) !== 2) {
                continue;
            }
            [$parentId, $sizeTermId] = $slot;
            $this->selector->rerunSize((int) $parentId, (int) $sizeTermId);
        }

        $winnerCount = 0;
        $queuedCount = 0;
        $repo = new ListingRepository();
        foreach ($created as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $fresh = $repo->find((int) ($item['listing_id'] ?? 0));
            if (!$fresh) {
                continue;
            }
            $item['is_winner'] = (bool) $fresh->isWinner;
            $item['listing_status'] = (string) $fresh->listingStatus;
            if ($fresh->isWinner && in_array($fresh->listingStatus, ['publish', 'pending'], true)) {
                $winnerCount++;
            } else {
                $queuedCount++;
            }
        }
        unset($item);

        $createdCount = count($created);
        if ($createdCount > 0) {
            (new TaskProgressService())->increment($merchantId, 'listings_created', $createdCount);
        }

        $this->events->log('listing_bulk_import', [
            'import_id' => $jobId,
            'created_count' => $createdCount,
            'failed_count' => count($failed),
            'winner_count' => $winnerCount,
            'queued_count' => $queuedCount,
        ], null, null, $merchantId, 'merchant_visible');

        if ($createdCount > 0) {
            $listingsUrl = function_exists('wc_get_account_endpoint_url')
                ? wc_get_account_endpoint_url('listings')
                : home_url('/hesabim/listings/');
            (new NotificationService())->dispatch($merchantId, NotificationType::LISTING_BULK_IMPORT_COMPLETED, [
                'import_id' => $jobId,
                'created_count' => $createdCount,
                'failed_count' => count($failed),
                'winner_count' => $winnerCount,
                'queued_count' => $queuedCount,
                'action_url' => $listingsUrl,
            ]);
        }

        $job['status'] = 'completed';
        $job['completed_at'] = time();
        $job['created'] = $created;
        $job['summary'] = [
            'created_count' => $createdCount,
            'failed_count' => count($failed),
            'winner_count' => $winnerCount,
            'queued_count' => $queuedCount,
        ];
        $this->saveJob($jobId, $job);
        BulkImportContext::deactivate($merchantId);
    }

    /** @param array<string, mixed> $job */
    private function saveJob(string $jobId, array $job): void
    {
        set_transient(self::JOB_TRANSIENT_PREFIX . $jobId, $job, self::JOB_TTL);
    }

    /** @return array<string, mixed>|null */
    private function loadJob(string $jobId): ?array
    {
        $job = get_transient(self::JOB_TRANSIENT_PREFIX . $jobId);

        return is_array($job) ? $job : null;
    }

    /** @param array<string, mixed> $job @return array<string, mixed> */
    private function publicJob(array $job): array
    {
        $pending = is_array($job['pending'] ?? null) ? $job['pending'] : [];
        $offset = (int) ($job['offset'] ?? 0);
        $total = count($pending);
        $processed = min($offset, $total);
        $status = (string) ($job['status'] ?? 'queued');

        $response = [
            'job_id' => (string) ($job['job_id'] ?? ''),
            'status' => $status,
            'total_rows' => $total,
            'processed_rows' => $processed,
            'progress_percent' => $total > 0 ? (int) min(100, round(($processed / $total) * 100)) : 0,
            'queued_at' => (int) ($job['queued_at'] ?? 0),
            'started_at' => isset($job['started_at']) ? (int) $job['started_at'] : null,
            'completed_at' => isset($job['completed_at']) ? (int) $job['completed_at'] : null,
        ];

        if ($status === 'completed') {
            $response['created'] = $job['created'] ?? [];
            $response['failed'] = $job['failed'] ?? [];
            $response['summary'] = $job['summary'] ?? [];
        }

        if ($status === 'failed') {
            $response['error_message'] = (string) ($job['error_message'] ?? '');
            $response['failed'] = $job['failed'] ?? [];
        }

        return $response;
    }

    /**
     * @return array{line: int, data: array<string, string>}|list<array{line: int, data: array<string, string>}>|\WP_Error
     */
    private function parseCsv(string $csv): array|\WP_Error
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $csv = trim($csv);
        if ($csv === '') {
            return new \WP_Error('sutore_bulk_csv_empty', __('CSV file is empty.', 'sutore-marketplace'));
        }

        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
        if (count($lines) < 2) {
            return new \WP_Error('sutore_bulk_csv_header', __('CSV must include a header row and at least one data row.', 'sutore-marketplace'));
        }

        $headerLine = array_shift($lines);
        $headers = array_map(static fn (string $h): string => strtolower(trim($h)), str_getcsv((string) $headerLine));
        foreach (self::REQUIRED_HEADERS as $required) {
            if (!in_array($required, $headers, true)) {
                return new \WP_Error(
                    'sutore_bulk_csv_header',
                    sprintf(
                        /* translators: %s: column name */
                        __('Missing required column: %s', 'sutore-marketplace'),
                        $required
                    )
                );
            }
        }

        if (count($lines) > self::MAX_ROWS) {
            return new \WP_Error(
                'sutore_bulk_csv_limit',
                sprintf(
                    /* translators: %d: max rows */
                    __('CSV cannot contain more than %d rows.', 'sutore-marketplace'),
                    self::MAX_ROWS
                )
            );
        }

        $parsed = [];
        foreach ($lines as $index => $line) {
            $cells = str_getcsv((string) $line);
            $data = [];
            foreach ($headers as $i => $header) {
                if ($header === '') {
                    continue;
                }
                $data[$header] = trim((string) ($cells[$i] ?? ''));
            }
            $parsed[] = [
                'line' => $index + 2,
                'data' => $data,
            ];
        }

        return $parsed;
    }

    /**
     * @param array<string, string> $data
     * @param array<string, int> $fingerprints
     * @return array<string, mixed>
     */
    private function validateRow(array $data, int $line, int $merchantId, array &$fingerprints): array
    {
        $errors = [];
        $warnings = [];

        $productCode = trim((string) ($data['product_code'] ?? ''));
        $sizeLabel = trim((string) ($data['size'] ?? ''));
        $priceRaw = trim((string) ($data['price'] ?? ''));

        if ($productCode === '') {
            $errors[] = __('Product code is required.', 'sutore-marketplace');
        }
        if ($sizeLabel === '') {
            $errors[] = __('Size is required.', 'sutore-marketplace');
        }
        if ($priceRaw === '') {
            $errors[] = __('Price is required.', 'sutore-marketplace');
        }

        $parentId = $productCode !== '' ? ProductCodeLookup::findParentByExactCode($productCode) : null;
        if ($productCode !== '' && !$parentId) {
            $errors[] = __('Product not found.', 'sutore-marketplace');
        }

        $sizeTermId = ($parentId && $sizeLabel !== '') ? ProductSizeLookup::resolveTermId($parentId, $sizeLabel) : null;
        if ($parentId && $sizeLabel !== '' && !$sizeTermId) {
            $errors[] = __('Size not found for this product.', 'sutore-marketplace');
        }

        $askingCheck = ListingPriceValidator::assertStepMultiple($priceRaw !== '' ? $priceRaw : 0);
        if ($priceRaw !== '' && is_wp_error($askingCheck)) {
            $errors[] = $askingCheck->get_error_message();
        }
        $asking = !is_wp_error($askingCheck) && $priceRaw !== ''
            ? (int) ListingPriceValidator::normalizeAsking($priceRaw)
            : 0;

        $conditions = $this->conditionsFromRow($data);
        $express = $this->boolFromCell($data['express'] ?? '0');
        $international = $this->boolFromCell($data['international'] ?? '0');

        if ($express && !ListingPolicy::canUseFastShipment($merchantId)) {
            $errors[] = __('You are not eligible for fast shipping.', 'sutore-marketplace');
        }

        if ($international) {
            $warnings[] = __('International shipping commitment applies to this row.', 'sutore-marketplace');
        }

        if ($parentId && $asking > 0) {
            $retailTl = ReleasePriceService::retailTl($parentId);
            if ($retailTl !== null && $asking < $retailTl) {
                $warnings[] = __('Price is below the product starting price.', 'sutore-marketplace');
            }
        }

        $rowKey = null;
        if ($parentId && $sizeTermId) {
            [$fastShipment, $hasInvoice] = ListingRepository::resolveShippingFlags([
                'fast_shipment' => $express,
                'has_invoice' => $international,
            ]);
            $rowKey = $parentId . ':' . $sizeTermId . ':' . ListingRepository::fingerprint($conditions, $fastShipment, $hasInvoice);
            if (isset($fingerprints[$rowKey])) {
                $errors[] = sprintf(
                    /* translators: %d: line number */
                    __('Duplicate of row %d in this file.', 'sutore-marketplace'),
                    $fingerprints[$rowKey]
                );
            }
        }

        $status = 'ready';
        if ($errors !== []) {
            $status = 'error';
        } elseif ($warnings !== []) {
            $status = 'warning';
        }

        if ($status !== 'error' && $rowKey !== null) {
            $fingerprints[$rowKey] = $line;
        }

        $parentTitle = $parentId ? get_the_title($parentId) : '';
        $resolvedSizeLabel = ($parentId && $sizeTermId)
            ? ProductSizeLookup::labelForTerm($parentId, $sizeTermId)
            : $sizeLabel;

        $input = null;
        if ($status !== 'error' && $parentId && $sizeTermId && $asking > 0) {
            $input = [
                'parent_product_id' => $parentId,
                'size_term_id' => $sizeTermId,
                'asking' => $asking,
                'conditions' => $conditions,
                'fast_shipment' => $express,
                'has_invoice' => $international,
            ];
        }

        return [
            'line' => $line,
            'status' => $status,
            'messages' => array_merge($errors, $warnings),
            'row_key' => $rowKey,
            'csv_data' => $data,
            'product_code' => $productCode,
            'parent_product_id' => $parentId,
            'parent_title' => $parentTitle,
            'thumbnail' => $parentId ? ProductThumbnail::url($parentId) : '',
            'size' => $resolvedSizeLabel,
            'size_term_id' => $sizeTermId,
            'price' => $asking,
            'price_display' => $asking > 0 ? MarketplacePricing::formatTl((float) $asking) : '',
            'conditions' => $conditions,
            'express' => $express,
            'international' => $international,
            'conditions_label' => $this->conditionsLabel($conditions),
            'shipping_label' => $this->shippingLabel($express, $international),
            'input' => $input,
        ];
    }

    /** @param array<string, string> $data @return array<string, bool> */
    private function conditionsFromRow(array $data): array
    {
        $out = [];
        foreach (['no_box', 'box_damaged', 'missing_accessory', 'damaged'] as $key) {
            if ($this->boolFromCell($data[$key] ?? '0')) {
                $out[$key] = true;
            }
        }

        return $out;
    }

    private function boolFromCell(string $value): bool
    {
        $value = strtolower(trim($value));

        return in_array($value, ['1', 'yes', 'true', 'y', 'evet'], true);
    }

    /** @param array<string, bool> $conditions */
    private function conditionsLabel(array $conditions): string
    {
        if ($conditions === []) {
            return __('Flawless', 'sutore-marketplace');
        }

        $map = [
            'no_box' => __('No box', 'sutore-marketplace'),
            'box_damaged' => __('Box damaged', 'sutore-marketplace'),
            'missing_accessory' => __('Missing accessory', 'sutore-marketplace'),
            'damaged' => __('Damaged', 'sutore-marketplace'),
        ];
        $labels = [];
        foreach ($conditions as $key => $on) {
            if ($on && isset($map[$key])) {
                $labels[] = $map[$key];
            }
        }

        return $labels ? implode(', ', $labels) : __('Flawless', 'sutore-marketplace');
    }

    private function shippingLabel(bool $express, bool $international): string
    {
        $parts = [__('Standard', 'sutore-marketplace')];
        if ($express) {
            $parts[] = __('Fast / Express', 'sutore-marketplace');
        }
        if ($international) {
            $parts[] = __('International', 'sutore-marketplace');
        }

        return implode(', ', $parts);
    }

    /** @return array{line: int, code: string, message: string} */
    private function failRow(int $line, string $code, string $message): array
    {
        return [
            'line' => $line,
            'code' => $code,
            'message' => $message,
        ];
    }
}
