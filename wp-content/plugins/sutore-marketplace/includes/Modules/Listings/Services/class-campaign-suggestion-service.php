<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignGuardrails;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class CampaignSuggestionService
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingPublishedAge $publishedAge = new ListingPublishedAge(),
    ) {
    }

    public function publishedDays(Listing $listing): int
    {
        return $this->publishedAge->days($listing);
    }

    public function agingStepDue(Listing $listing): int
    {
        $days = $this->publishedDays($listing);
        $step2 = CampaignGuardrails::agingDay(2);
        $step1 = CampaignGuardrails::agingDay(1);
        $already = max(0, $listing->campaignAgingStep);

        if ($days >= $step2 && $already < 2) {
            return 2;
        }
        if ($days >= $step1 && $already < 1) {
            return 1;
        }

        return 0;
    }

    /**
     * @return array{
     *   step: int,
     *   percent: int,
     *   takes_lead: bool,
     *   competitor_asking: ?float,
     *   competitor_asking_display: string,
     *   published_days: int,
     *   asking_after: float,
     *   headline: string,
     *   body: string
     * }
     */
    public function build(Listing $listing, int $step): array
    {
        $step = $step <= 1 ? 1 : 2;
        $publishedDays = $this->publishedDays($listing);
        $competitor = $this->competitor($listing);
        $competitorAsking = $competitor ? (float) $competitor->asking : null;
        $min = CampaignGuardrails::minPercent();
        $max = CampaignGuardrails::maxPercent();
        $percent = $min;
        $takesLead = false;

        if ($competitorAsking !== null && $competitorAsking < (float) $listing->asking) {
            $target = MarketplacePricing::firstPlaceAsking($competitorAsking);
            if ($target !== null && $target < (float) $listing->asking) {
                $needed = ((float) $listing->asking - $target) / (float) $listing->asking * 100;
                $percent = CampaignGuardrails::snapPercent($needed);
                $takesLead = true;
            }
        }

        if ($step === 2) {
            $stronger = CampaignGuardrails::snapPercent(min($max, $percent + 10));
            $percent = max($percent, $stronger);
        }

        $percent = max($min, min($max, $percent));
        $math = MarketplacePricing::resolveCampaignOfferMath(
            (float) $listing->asking,
            'percent',
            (float) $percent,
            'percent',
            100.0
        );

        $competitorDisplay = $competitorAsking !== null
            ? MarketplacePricing::formatTl($competitorAsking)
            : '';
        $askingAfterDisplay = MarketplacePricing::formatTl($math['asking_effective']);
        $headline = sprintf(
            /* translators: %d: days the listing has been on the market */
            __('%d days unsold.', 'sutore-marketplace'),
            max(1, $publishedDays)
        );
        $parts = [$headline];
        if ($competitorDisplay !== '') {
            $parts[] = sprintf(
                /* translators: %s: current asking on this size */
                __('This size is on sale at %s today.', 'sutore-marketplace'),
                $competitorDisplay
            );
        }
        if ($takesLead) {
            $parts[] = sprintf(
                /* translators: 1: percent, 2: asking after accept */
                __('Drop %1$s%% to %2$s and you take the lead.', 'sutore-marketplace'),
                (string) $percent,
                $askingAfterDisplay
            );
        } else {
            $parts[] = sprintf(
                /* translators: 1: percent, 2: asking after accept */
                __('Drop %1$s%% to %2$s for a timed strikethrough price.', 'sutore-marketplace'),
                (string) $percent,
                $askingAfterDisplay
            );
        }
        $parts[] = __('Fees on this product are waived for the campaign.', 'sutore-marketplace');

        return [
            'step' => $step,
            'percent' => $percent,
            'takes_lead' => $takesLead,
            'competitor_asking' => $competitorAsking,
            'competitor_asking_display' => $competitorDisplay,
            'published_days' => $publishedDays,
            'asking_after' => $math['asking_effective'],
            'headline' => $headline,
            'body' => implode(' ', $parts),
        ];
    }

    private function competitor(Listing $listing): ?Listing
    {
        $winner = $this->listings->getLowestOnSaleForSize(
            (int) $listing->parentProductId,
            (int) $listing->sizeTermId
        );
        if (!$winner || (int) $winner->variationId === (int) $listing->variationId) {
            return null;
        }

        return $winner;
    }
}
