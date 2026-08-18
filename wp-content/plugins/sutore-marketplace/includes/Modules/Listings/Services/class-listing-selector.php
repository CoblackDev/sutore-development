<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Merchants\Domain\NotificationType;
use SutoreMarketplace\Modules\Merchants\Services\NotificationService;
use SutoreMarketplace\Modules\Orders\Services\Notifications;
use SutoreMarketplace\Modules\Listings\Domain\Listing;
use SutoreMarketplace\Modules\Listings\Domain\ListingConditionRank;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;
use SutoreMarketplace\Modules\Listings\Services\BulkImportContext;
use SutoreMarketplace\Modules\Listings\Repositories\ListingEventsRepository;
use SutoreMarketplace\Modules\Listings\Repositories\ListingRepository;
use SutoreMarketplace\Shared\Settings\Settings;

final class ListingSelector
{
    public function __construct(
        private readonly ListingRepository $listings = new ListingRepository(),
        private readonly ListingEventsRepository $events = new ListingEventsRepository(),
        private readonly ActivePriceSync $sync = new ActivePriceSync(),
    ) {
    }

    /**
     * Re-rank all competing listings for a parent+size.
     * Lowest asking wins; older listing wins a tie. Index 0 becomes active/winner.
     */
    public function rerunSize(int $parentId, int $sizeTermId): ?Listing
    {
        $candidates = $this->listings->findCompetingForSize($parentId, $sizeTermId);
        if (!$candidates) {
            $this->sync->clearParentSize($parentId, $sizeTermId);
            return null;
        }

        $oldPositions = $this->capturePreviousQueuePositions($candidates);
        $oldWinners = $this->captureWinnerStates($candidates);
        $candidates = ListingConditionRank::sortForSale($candidates);

        $winner = $candidates[0];

        foreach ($candidates as $index => $listing) {
            if (!$listing->variationId) {
                continue;
            }
            $isWinner = $index === 0;
            $previousStatus = $listing->listingStatus;
            $winnerStatus = $isWinner && Settings::merchantAutoActivates((int) $listing->merchantId)
                ? 'publish'
                : ($isWinner ? 'pending' : 'queued');
            $newStatus = $isWinner ? $winnerStatus : 'queued';
            $this->listings->update($listing->variationId, [
                'is_winner' => $isWinner ? 1 : 0,
                'listing_status' => $newStatus,
                'expire_at' => $this->resolveExpireAt($listing),
            ]);
            $this->applyWcStatus($listing->variationId, $isWinner && $winnerStatus === 'publish');

            if ($isWinner && $previousStatus === 'pending' && $newStatus === 'publish') {
                $this->events->log('listing_approved', [
                    'approval_mode' => Settings::merchantAutoActivates((int) $listing->merchantId) ? 'auto' : 'manual',
                    'previous_status' => $previousStatus,
                    'listing_status' => $newStatus,
                ], $listing->variationId, $listing->merchantId, 'merchant_visible');
            }
        }

        $fresh = $this->listings->find((int) $winner->variationId);
        if ($fresh) {
            $this->sync->syncFromWinner($fresh);
        }

        $this->notifyQueueChanges($oldPositions, $candidates);
        $this->notifyWinnerChanges($oldWinners, $candidates);

        return $fresh;
    }

    public function approvePendingWinner(int $listingId): Listing|\WP_Error
    {
        $listing = $this->listings->find($listingId);
        if (!$listing) {
            return new \WP_Error('sutore_marketplace_listing_missing', __('Product not found.', 'sutore-marketplace'));
        }

        if (!$listing->isWinner) {
            return new \WP_Error(
                'sutore_marketplace_not_winner',
                __('Only the queue winner can be approved.', 'sutore-marketplace')
            );
        }

        if ($listing->listingStatus !== 'pending') {
            return new \WP_Error(
                'sutore_marketplace_not_pending',
                __('Product is not awaiting approval.', 'sutore-marketplace')
            );
        }

        $this->listings->update($listingId, ['listing_status' => 'publish']);
        $this->applyWcStatus($listing->variationId, true);
        $this->events->log('listing_approved', [
            'approval_mode' => 'manual',
            'previous_status' => 'pending',
            'listing_status' => 'publish',
        ], $listing->variationId, $listing->merchantId, 'merchant_visible');

        $fresh = $this->listings->find($listingId);
        if ($fresh) {
            $this->sync->syncFromWinner($fresh);
        }

        return $fresh ?: new \WP_Error('sutore_marketplace_approve_failed', __('Product could not be approved.', 'sutore-marketplace'));
    }

    public function queuePosition(Listing $listing): array
    {
        $candidates = $this->listings->findCompetingForSize(
            $listing->parentProductId,
            $listing->sizeTermId
        );

        $candidates = ListingConditionRank::sortForSale($candidates);

        $position = 1;
        foreach ($candidates as $i => $candidate) {
            if ($candidate->variationId === $listing->variationId) {
                $position = $i + 1;
                break;
            }
        }

        return [
            'queue_position' => $position,
            'queue_total' => count($candidates),
        ];
    }

    /** Listing lifetime is fixed at creation — status changes must not clear it. */
    private function resolveExpireAt(Listing $listing): string
    {
        if ($listing->expireAt) {
            return $listing->expireAt;
        }

        $days = $listing->durationDays > 0
            ? $listing->durationDays
            : \SutoreMarketplace\Shared\Settings\Settings::defaultListingDurationDays();
        $created = $listing->createdAt ? strtotime((string) $listing->createdAt) : false;
        $base = $created !== false ? $created : current_time('timestamp');

        return wp_date('Y-m-d H:i:s', $base + ($days * DAY_IN_SECONDS));
    }

    private function applyWcStatus(int $variationId, bool $isWinner): void
    {
        $product = wc_get_product($variationId);
        if (!$product) {
            return;
        }

        if ($isWinner) {
            // Visible on storefront + in WC admin Variations tab.
            $product->set_status('publish');
            $product->set_stock_status('instock');
            if (!$product->get_stock_quantity()) {
                $product->set_stock_quantity(1);
            }
        } else {
            // draft (not private): WC admin load_variations / get_children only
            // include publish+private, so queued listings stay out of the edit UI.
            $product->set_status('draft');
            $product->set_stock_status('outofstock');
            $product->set_stock_quantity(0);
        }
        $product->save();
    }

    /** @param Listing[] $candidates @return array<int, int> */
    private function captureQueuePositions(array $candidates): array
    {
        $positions = [];
        foreach ($candidates as $i => $listing) {
            if ($listing->variationId) {
                $positions[(int) $listing->variationId] = $i + 1;
            }
        }

        return $positions;
    }

    /**
     * Approximate the previous queue using stored winner flags before this rerun.
     *
     * @param Listing[] $candidates
     * @return array<int, int>
     */
    private function capturePreviousQueuePositions(array $candidates): array
    {
        $previous = $candidates;
        usort($previous, static function (Listing $a, Listing $b): int {
            if ($a->isWinner !== $b->isWinner) {
                return $a->isWinner ? -1 : 1;
            }

            return ListingConditionRank::compareForSale($a, $b);
        });

        return $this->captureQueuePositions($previous);
    }

    /** @param array<int, int> $oldPositions @param Listing[] $candidates */
    private function notifyQueueChanges(array $oldPositions, array $candidates): void
    {
        $shouldNotify = Settings::notifyQueuePositionChange();

        foreach ($candidates as $i => $listing) {
            if (!$listing->variationId) {
                continue;
            }
            $newPos = $i + 1;
            $oldPos = $oldPositions[(int) $listing->variationId] ?? null;
            if ($oldPos === null || $oldPos === $newPos) {
                continue;
            }
            $this->events->log('queue_position_changed', [
                'old_position' => $oldPos,
                'new_position' => $newPos,
            ], $listing->variationId, $listing->merchantId, 'merchant_visible');

            if (!$shouldNotify || BulkImportContext::shouldSuppressQueueNotification((int) $listing->merchantId)) {
                continue;
            }

            do_action('sutore_marketplace_queue_position_changed', $listing, $oldPos, $newPos);
        }
    }

    /** @param Listing[] $candidates @return array<int, bool> */
    private function captureWinnerStates(array $candidates): array
    {
        $states = [];
        foreach ($candidates as $listing) {
            if ($listing->variationId) {
                $states[(int) $listing->variationId] = (bool) $listing->isWinner;
            }
        }

        return $states;
    }

    /** @param array<int, bool> $oldWinners @param Listing[] $candidates */
    private function notifyWinnerChanges(array $oldWinners, array $candidates): void
    {
        $notifications = new NotificationService();

        foreach ($candidates as $i => $listing) {
            if (!$listing->variationId) {
                continue;
            }

            $listingId = (int) $listing->variationId;
            $wasWinner = $oldWinners[$listingId] ?? false;
            $isWinner = $i === 0;
            if ($wasWinner === $isWinner) {
                continue;
            }

            if (!$wasWinner && $isWinner) {
                $winnerStatus = Settings::merchantAutoActivates((int) $listing->merchantId)
                    ? 'publish'
                    : 'pending';
                $this->events->log('listing_went_on_sale', [
                    'listing_status' => $winnerStatus,
                    'new_position' => 1,
                ], $listing->variationId, $listing->merchantId, 'merchant_visible');
            } elseif ($wasWinner && !$isWinner) {
                $this->events->log('listing_left_sale', [
                    'listing_status' => 'queued',
                    'new_position' => $i + 1,
                    'old_position' => 1,
                ], $listing->variationId, $listing->merchantId, 'merchant_visible');
            }

            if (BulkImportContext::shouldSuppressNotification((int) $listing->merchantId, NotificationType::LISTING_WINNER_GAINED)
                || BulkImportContext::shouldSuppressNotification((int) $listing->merchantId, NotificationType::LISTING_WINNER_LOST)) {
                continue;
            }

            $title = Notifications::productTitle($listingId, $listing->variationId, $listing->parentProductId);
            $context = [
                'product' => $title,
                'variation_id' => $listingId,
                'new_position' => $i + 1,
            ];

            if (!$wasWinner && $isWinner) {
                $notifications->dispatch((int) $listing->merchantId, NotificationType::LISTING_WINNER_GAINED, $context);
                continue;
            }

            if ($wasWinner && !$isWinner) {
                $context['old_position'] = 1;
                $notifications->dispatch((int) $listing->merchantId, NotificationType::LISTING_WINNER_LOST, $context);
            }
        }
    }
}
