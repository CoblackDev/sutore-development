<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Support;

use SutoreMarketplace\Modules\Listings\Domain\Listing;

final class ListingEventPayload
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function withAsking(array $payload, ?Listing $listing, ?object $row = null): array
    {
        if (!isset($payload['asking']) || (float) $payload['asking'] <= 0) {
            if ($listing !== null && $listing->asking > 0) {
                $payload['asking'] = (float) $listing->asking;
            } elseif ($row !== null && isset($row->asking)) {
                $payload['asking'] = (float) $row->asking;
            }
        }

        return $payload;
    }
}
