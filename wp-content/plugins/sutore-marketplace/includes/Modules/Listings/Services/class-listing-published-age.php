<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingStatus;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;

/**
 * Cumulative time a listing has been on the market (publish + queued), including relists.
 */
final class ListingPublishedAge
{
    /** @var list<string> */
    private const START_TYPES = [
        'listing_created',
        'listing_put_on_sale',
        'listing_went_on_sale',
        'listing_approved',
    ];

    /** @var list<string> */
    private const END_TYPES = [
        'listing_expired',
        'listing_removed_from_sale',
        'listing_deleted',
        'listing_sold',
        'listing_payment',
        'order_listing_attached',
    ];

    public function __construct(
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
    ) {
    }

    public function seconds(Listing $listing): int
    {
        $now = current_time('timestamp');
        $rows = $this->events->findTimelineForVariation((int) $listing->variationId);
        $seconds = 0;
        $openAt = null;

        foreach ($rows as $row) {
            $type = sanitize_key((string) ($row->event_type ?? ''));
            $ts = CampaignDatetime::toTimestamp((string) ($row->created_at ?? ''));
            if ($ts === null) {
                continue;
            }
            if (in_array($type, self::START_TYPES, true)) {
                if ($openAt === null) {
                    $openAt = $ts;
                }
                continue;
            }
            if (in_array($type, self::END_TYPES, true) && $openAt !== null) {
                $seconds += max(0, $ts - $openAt);
                $openAt = null;
            }
        }

        $onMarket = in_array($listing->listingStatus, [ListingStatus::PUBLISH, ListingStatus::QUEUED], true);
        if ($onMarket) {
            if ($openAt === null) {
                $created = CampaignDatetime::toTimestamp($listing->createdAt);
                $openAt = $created ?? $now;
            }
            $seconds += max(0, $now - $openAt);
        }

        return $seconds;
    }

    public function days(Listing $listing): int
    {
        return (int) floor($this->seconds($listing) / DAY_IN_SECONDS);
    }
}
