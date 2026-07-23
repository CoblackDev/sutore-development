<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class ListingConditionRank
{
    /** @var list<string> */
    public const DEFECT_KEYS = ['no_box', 'box_damaged', 'missing_accessory', 'used', 'damaged'];

    /** @var array<string, int> */
    private const SEVERITY_WEIGHTS = [
        'no_box' => 1,
        'box_damaged' => 2,
        'missing_accessory' => 3,
        'used' => 4,
        'damaged' => 5,
    ];

    public static function hasDefect(Listing|array $listingOrConditions): bool
    {
        return self::defectSeverity($listingOrConditions) > 0;
    }

    public static function isFlawless(Listing|array $listingOrConditions): bool
    {
        return !self::hasDefect($listingOrConditions);
    }

    public static function defectSeverity(Listing|array $listingOrConditions): int
    {
        $conditions = $listingOrConditions instanceof Listing
            ? $listingOrConditions->conditions
            : $listingOrConditions;

        $score = 0;
        foreach (self::DEFECT_KEYS as $key) {
            if (!empty($conditions[$key])) {
                $score += self::SEVERITY_WEIGHTS[$key];
            }
        }

        return $score;
    }

    /**
     * Sale order: flawless before defective; lower severity; lower asking; older first.
     *
     * @return int negative when $a ranks before $b (wins sale)
     */
    public static function compareForSale(Listing $a, Listing $b): int
    {
        $defectA = self::hasDefect($a);
        $defectB = self::hasDefect($b);
        if ($defectA !== $defectB) {
            return $defectA <=> $defectB;
        }

        $severityA = self::defectSeverity($a);
        $severityB = self::defectSeverity($b);
        if ($severityA !== $severityB) {
            return $severityA <=> $severityB;
        }

        $askingA = (float) $a->asking;
        $askingB = (float) $b->asking;
        if ($askingA !== $askingB) {
            return $askingA <=> $askingB;
        }

        return strcmp((string) $a->createdAt, (string) $b->createdAt);
    }

    /**
     * @param Listing[] $listings
     * @return Listing[]
     */
    public static function sortForSale(array $listings): array
    {
        usort($listings, [self::class, 'compareForSale']);

        return $listings;
    }

    /** Price alone cannot move $self ahead of $other when condition tiers differ. */
    public static function canBeatByPriceOnly(Listing $self, Listing $other): bool
    {
        if (self::hasDefect($self) !== self::hasDefect($other)) {
            return false;
        }

        return self::defectSeverity($self) === self::defectSeverity($other);
    }

    /**
     * @param Listing[] $ranked
     */
    public static function firstPlaceAskingForDraft(array $ranked, int $draftId): ?float
    {
        if ($ranked === []) {
            return null;
        }

        $index = null;
        $draft = null;
        foreach ($ranked as $i => $listing) {
            if ((int) $listing->id === $draftId) {
                $index = $i;
                $draft = $listing;
                break;
            }
        }

        if ($index === null || $index === 0 || !$draft) {
            return null;
        }

        $leader = $ranked[0];
        if (!self::canBeatByPriceOnly($draft, $leader)) {
            return null;
        }

        $target = \SutoreMarketplace\Shared\Domain\MarketplacePricing::firstPlaceAsking((float) $leader->asking);
        if ($target === null || (float) $draft->asking <= $target) {
            return null;
        }

        return $target;
    }

    /**
     * @param Listing[] $ranked
     */
    public static function isBlockedByBetterCondition(array $ranked, int $draftId): bool
    {
        $draftIndex = null;
        $draftListing = null;
        foreach ($ranked as $index => $listing) {
            if ((int) $listing->id === $draftId) {
                $draftIndex = $index;
                $draftListing = $listing;
                break;
            }
        }

        if ($draftIndex === null || !$draftListing || !self::hasDefect($draftListing)) {
            return false;
        }

        for ($i = 0; $i < $draftIndex; $i++) {
            if (self::isFlawless($ranked[$i])) {
                return true;
            }
        }

        return false;
    }
}
