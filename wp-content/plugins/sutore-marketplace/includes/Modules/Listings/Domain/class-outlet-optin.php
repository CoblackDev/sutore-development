<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class OutletOptin
{
    public function __construct(
        public readonly int $id,
        public readonly int $itemId,
        public readonly int $merchantId,
        public readonly ?int $variationId,
        public readonly string $status,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {
    }

    public static function fromRow(object $row): self
    {
        $variation = isset($row->variation_id) ? (int) $row->variation_id : 0;

        return new self(
            id: (int) $row->id,
            itemId: (int) $row->item_id,
            merchantId: (int) $row->merchant_id,
            variationId: $variation > 0 ? $variation : null,
            status: (string) ($row->status ?? OutletOptinStatus::PENDING),
            createdAt: isset($row->created_at) ? (string) $row->created_at : null,
            updatedAt: isset($row->updated_at) ? (string) $row->updated_at : null,
        );
    }
}
