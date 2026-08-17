<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequest;
use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequestStatus;
use SutoreMarketplace\Modules\Listings\Domain\ListingPolicy;
use SutoreMarketplace\Modules\Listings\Domain\ProductCodeLookup;
use SutoreMarketplace\Modules\Listings\Repositories\CatalogProductRequestRepository;
use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;

final class CatalogProductRequestService
{
    public const MAX_SKU_OR_LINK = 500;
    public const MAX_SIZE_NOTE = 80;
    public const MAX_NOTE = 500;
    public const MAX_PENDING = 10;

    public function __construct(
        private readonly CatalogProductRequestRepository $requests = new CatalogProductRequestRepository(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly CatalogProductRequestPresenter $presenter = new CatalogProductRequestPresenter(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function create(int $merchantId, array $input): array|\WP_Error
    {
        $auth = ListingPolicy::assertCanRequestCatalogProduct($merchantId);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $pendingCount = $this->requests->countForMerchant($merchantId, CatalogProductRequestStatus::PENDING);
        if ($pendingCount >= self::MAX_PENDING) {
            return new \WP_Error(
                'sutore_catalog_request_limit',
                __('You already have the maximum number of pending catalog requests.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $skuOrLink = $this->normalizeSkuOrLink($input['sku_or_link'] ?? '');
        if ($skuOrLink === '') {
            return new \WP_Error(
                'sutore_catalog_request_sku',
                __('Enter a product SKU or link.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $sizeNote = $this->normalizeSizeNote($input['size_note'] ?? '');
        if ($sizeNote === '') {
            return new \WP_Error(
                'sutore_catalog_request_size',
                __('Enter a size.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $duplicate = $this->requests->findPendingDuplicate($merchantId, $skuOrLink);
        if ($duplicate !== null) {
            return new \WP_Error(
                'sutore_catalog_request_duplicate',
                __('You already have a pending request for this product.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $id = $this->requests->create([
            'merchant_id' => $merchantId,
            'sku_or_link' => $skuOrLink,
            'size_note' => $sizeNote,
            'note' => $this->normalizeNote($input['note'] ?? ''),
            'status' => CatalogProductRequestStatus::PENDING,
        ]);
        if ($id <= 0) {
            return new \WP_Error(
                'sutore_catalog_request_create_failed',
                __('The catalog request could not be submitted.', 'sutore-marketplace'),
                ['status' => 500]
            );
        }

        $request = $this->requests->find($id);
        if ($request === null) {
            return new \WP_Error(
                'sutore_catalog_request_create_failed',
                __('The catalog request could not be submitted.', 'sutore-marketplace'),
                ['status' => 500]
            );
        }

        return [
            'item' => $this->presenter->present($request),
            'message' => __('Request submitted. We will notify you when the product is added to the catalog.', 'sutore-marketplace'),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listForMerchant(int $merchantId, ?string $status, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $status = $status !== null && CatalogProductRequestStatus::isValid($status) ? $status : null;
        $offset = ($page - 1) * $perPage;
        $rows = $this->requests->findForMerchant($merchantId, $status, $perPage, $offset);
        $total = $this->requests->countForMerchant($merchantId, $status);

        return [
            'items' => array_map(
                fn (CatalogProductRequest $request): array => $this->presenter->present($request),
                $rows
            ),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function cancel(int $id, int $merchantId): array|\WP_Error
    {
        $request = $this->requests->find($id);
        if ($request === null || $request->merchantId !== $merchantId) {
            return new \WP_Error(
                'sutore_catalog_request_missing',
                __('Catalog request not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }
        if ($request->status !== CatalogProductRequestStatus::PENDING) {
            return new \WP_Error(
                'sutore_catalog_request_not_pending',
                __('Only pending catalog requests can be cancelled.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        $this->requests->update($id, ['status' => CatalogProductRequestStatus::CANCELLED]);
        $updated = $this->requests->find($id);
        if ($updated === null) {
            return new \WP_Error(
                'sutore_catalog_request_missing',
                __('Catalog request not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        return [
            'item' => $this->presenter->present($updated),
            'message' => __('Catalog request cancelled.', 'sutore-marketplace'),
        ];
    }

    /**
     * @param array{status?:string,search?:string,merchant_id?:int} $args
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listForStaff(array $args, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset = ($page - 1) * $perPage;
        $rows = $this->requests->findForStaff($args, $perPage, $offset);
        $total = $this->requests->countForStaff($args);

        return [
            'items' => array_map(
                fn (CatalogProductRequest $request): array => $this->presenter->present($request, true),
                $rows
            ),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function fulfill(int $id, int $staffId, array $input = []): array|\WP_Error
    {
        $request = $this->requirePending($id);
        if (is_wp_error($request)) {
            return $request;
        }

        $parentId = (int) ($input['parent_product_id'] ?? 0);
        $resolvedParent = null;
        if ($parentId > 0) {
            $resolvedParent = $this->assertCatalogParent($parentId);
            if (is_wp_error($resolvedParent)) {
                return $resolvedParent;
            }
        }

        $this->requests->update($id, [
            'status' => CatalogProductRequestStatus::FULFILLED,
            'resolved_parent_product_id' => $resolvedParent,
            'resolved_by' => $staffId,
            'resolved_at' => current_time('mysql'),
            'staff_note' => $this->normalizeNote($input['staff_note'] ?? ''),
        ]);

        $updated = $this->requests->find($id);
        if ($updated === null) {
            return new \WP_Error(
                'sutore_catalog_request_missing',
                __('Catalog request not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $productCode = $updated->skuOrLink;
        $productTitle = $updated->skuOrLink;
        if ($updated->resolvedParentProductId) {
            $code = ProductCodeLookup::codeForProduct($updated->resolvedParentProductId);
            if ($code !== '') {
                $productCode = $code;
            }
            $title = get_the_title($updated->resolvedParentProductId);
            if (is_string($title) && $title !== '') {
                $productTitle = $title;
            }
        }

        $this->notifications->dispatch($updated->merchantId, NotificationType::CATALOG_REQUEST_FULFILLED, [
            'request_id' => $updated->id,
            'product' => $productTitle,
            'product_code' => $productCode,
            'size_note' => $updated->sizeNote,
            'parent_product_id' => $updated->resolvedParentProductId ?? 0,
        ], 60);

        return [
            'item' => $this->presenter->present($updated, true),
            'message' => __('Seller notified that the product was added to the catalog.', 'sutore-marketplace'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function reject(int $id, int $staffId, array $input = []): array|\WP_Error
    {
        $request = $this->requirePending($id);
        if (is_wp_error($request)) {
            return $request;
        }

        $staffNote = $this->normalizeNote($input['staff_note'] ?? '');
        $this->requests->update($id, [
            'status' => CatalogProductRequestStatus::REJECTED,
            'resolved_by' => $staffId,
            'resolved_at' => current_time('mysql'),
            'staff_note' => $staffNote,
        ]);

        $updated = $this->requests->find($id);
        if ($updated === null) {
            return new \WP_Error(
                'sutore_catalog_request_missing',
                __('Catalog request not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }

        $this->notifications->dispatch($updated->merchantId, NotificationType::CATALOG_REQUEST_REJECTED, [
            'request_id' => $updated->id,
            'product' => $updated->skuOrLink,
            'size_note' => $updated->sizeNote,
            'staff_note' => $staffNote,
        ], 60);

        return [
            'item' => $this->presenter->present($updated, true),
            'message' => __('Catalog request declined.', 'sutore-marketplace'),
        ];
    }

    private function requirePending(int $id): CatalogProductRequest|\WP_Error
    {
        $request = $this->requests->find($id);
        if ($request === null) {
            return new \WP_Error(
                'sutore_catalog_request_missing',
                __('Catalog request not found.', 'sutore-marketplace'),
                ['status' => 404]
            );
        }
        if ($request->status !== CatalogProductRequestStatus::PENDING) {
            return new \WP_Error(
                'sutore_catalog_request_not_pending',
                __('This catalog request is no longer pending.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        return $request;
    }

    private function assertCatalogParent(int $parentId): int|\WP_Error
    {
        $product = function_exists('wc_get_product') ? wc_get_product($parentId) : null;
        if (!$product instanceof \WC_Product) {
            return new \WP_Error(
                'sutore_catalog_request_parent',
                __('Catalog product not found.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }
        if (!$product->is_type('variable') || $product->get_status() !== 'publish') {
            return new \WP_Error(
                'sutore_catalog_request_parent',
                __('Link a published variable catalog product.', 'sutore-marketplace'),
                ['status' => 400]
            );
        }

        return $parentId;
    }

    private function normalizeSkuOrLink(mixed $raw): string
    {
        $value = trim(wp_strip_all_tags((string) $raw));
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            $value = esc_url_raw($value);
        } else {
            $value = sanitize_text_field($value);
        }

        return mb_substr($value, 0, self::MAX_SKU_OR_LINK);
    }

    private function normalizeSizeNote(mixed $raw): string
    {
        $value = sanitize_text_field((string) $raw);

        return mb_substr(trim($value), 0, self::MAX_SIZE_NOTE);
    }

    private function normalizeNote(mixed $raw): string
    {
        $value = sanitize_textarea_field((string) $raw);

        return mb_substr(trim($value), 0, self::MAX_NOTE);
    }
}
