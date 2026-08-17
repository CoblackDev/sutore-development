<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

final class ListingConditionRank
{
    /** @var list<string> */
    public const DEFECT_KEYS = ['no_box', 'box_damaged', 'missing_accessory', 'damaged'];

    public static function hasDefect(Listing|array $listingOrConditions): bool
    {
        return self::activeKeys($listingOrConditions) !== [];
    }

    public static function isFlawless(Listing|array $listingOrConditions): bool
    {
        return !self::hasDefect($listingOrConditions);
    }

    /**
     * Sale order: lower asking first; older listing first on a tie.
     *
     * @return int negative when $a ranks before $b (wins sale)
     */
    public static function compareForSale(Listing $a, Listing $b): int
    {
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

    /**
     * @param Listing[] $ranked
     */
    public static function firstPlaceAskingForDraft(array $ranked, int $draftId): ?float
    {
        if ($ranked === []) {
            return null;
        }

        $index = null;
        foreach ($ranked as $i => $listing) {
            if ((int) $listing->variationId === $draftId) {
                $index = $i;
                break;
            }
        }

        if ($index === null || $index === 0) {
            return null;
        }

        $target = \SutoreMarketplace\Shared\Domain\MarketplacePricing::firstPlaceAsking((float) $ranked[0]->asking);
        $draftAsking = (float) $ranked[$index]->asking;
        if ($target === null || $draftAsking <= $target) {
            return null;
        }

        return $target;
    }

    public static function label(string $key): string
    {
        return match ($key) {
            'no_box' => __('No box', 'sutore-marketplace'),
            'box_damaged' => __('Box damaged', 'sutore-marketplace'),
            'missing_accessory' => __('Missing accessory', 'sutore-marketplace'),
            'damaged' => __('Damaged', 'sutore-marketplace'),
            default => $key,
        };
    }

    /**
     * @param Listing|array<string, mixed> $listingOrConditions
     * @return list<string>
     */
    public static function activeKeys(Listing|array $listingOrConditions): array
    {
        $conditions = $listingOrConditions instanceof Listing
            ? $listingOrConditions->conditions
            : $listingOrConditions;

        $keys = [];
        foreach (self::DEFECT_KEYS as $key) {
            if (!empty($conditions[$key])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param Listing|array<string, mixed> $listingOrConditions
     * @return list<string>
     */
    public static function activeLabels(Listing|array $listingOrConditions): array
    {
        $labels = [];
        foreach (self::activeKeys($listingOrConditions) as $key) {
            $labels[] = self::label($key);
        }

        return $labels;
    }

    public static function customerBadgesHtml(Listing $listing): string
    {
        $labels = self::activeLabels($listing);
        if ($labels === []) {
            return '';
        }

        $items = '';
        foreach ($labels as $label) {
            $items .= '<span class="sutore-mp-pdp-condition-badge">' . esc_html($label) . '</span>';
        }

        return '<div class="sutore-mp-pdp-conditions" role="group" aria-label="'
            . esc_attr__('Condition', 'sutore-marketplace')
            . '">' . $items . '</div>';
    }
}
