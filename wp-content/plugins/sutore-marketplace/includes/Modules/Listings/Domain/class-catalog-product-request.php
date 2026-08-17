<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class CatalogProductRequest
{
    public function __construct(
        public readonly int $id,
        public readonly int $merchantId,
        public readonly string $skuOrLink,
        public readonly string $sizeNote,
        public readonly string $note,
        public readonly string $status,
        public readonly ?int $resolvedParentProductId,
        public readonly ?int $resolvedBy,
        public readonly ?string $resolvedAt,
        public readonly string $staffNote,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {
    }

    public static function fromRow(object $row): self
    {
        $resolvedParent = isset($row->resolved_parent_product_id) && $row->resolved_parent_product_id !== null
            ? (int) $row->resolved_parent_product_id
            : 0;
        $resolvedBy = isset($row->resolved_by) && $row->resolved_by !== null
            ? (int) $row->resolved_by
            : 0;

        return new self(
            id: (int) $row->id,
            merchantId: (int) $row->merchant_id,
            skuOrLink: (string) ($row->sku_or_link ?? ''),
            sizeNote: (string) ($row->size_note ?? ''),
            note: (string) ($row->note ?? ''),
            status: (string) ($row->status ?? CatalogProductRequestStatus::PENDING),
            resolvedParentProductId: $resolvedParent > 0 ? $resolvedParent : null,
            resolvedBy: $resolvedBy > 0 ? $resolvedBy : null,
            resolvedAt: isset($row->resolved_at) && $row->resolved_at !== null && $row->resolved_at !== ''
                ? (string) $row->resolved_at
                : null,
            staffNote: (string) ($row->staff_note ?? ''),
            createdAt: isset($row->created_at) ? (string) $row->created_at : null,
            updatedAt: isset($row->updated_at) ? (string) $row->updated_at : null,
        );
    }
}
