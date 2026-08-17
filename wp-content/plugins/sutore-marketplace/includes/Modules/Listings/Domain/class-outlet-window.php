<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class OutletWindow
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $status,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly string $notes,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            name: (string) ($row->name ?? ''),
            status: (string) ($row->status ?? OutletWindowStatus::DRAFT),
            startsAt: (string) ($row->starts_at ?? ''),
            endsAt: (string) ($row->ends_at ?? ''),
            notes: (string) ($row->notes ?? ''),
            createdAt: isset($row->created_at) ? (string) $row->created_at : null,
            updatedAt: isset($row->updated_at) ? (string) $row->updated_at : null,
        );
    }
}
