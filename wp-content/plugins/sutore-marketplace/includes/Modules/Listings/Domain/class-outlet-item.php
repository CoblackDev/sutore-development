<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class OutletItem
{
    public function __construct(
        public readonly int $id,
        public readonly int $windowId,
        public readonly int $parentProductId,
        public readonly int $sizeTermId,
        public readonly float $customerSale,
        public readonly float $sellerNet,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            windowId: (int) $row->window_id,
            parentProductId: (int) $row->parent_product_id,
            sizeTermId: (int) $row->size_term_id,
            customerSale: (float) $row->customer_sale,
            sellerNet: (float) $row->seller_net,
            createdAt: isset($row->created_at) ? (string) $row->created_at : null,
            updatedAt: isset($row->updated_at) ? (string) $row->updated_at : null,
        );
    }
}
