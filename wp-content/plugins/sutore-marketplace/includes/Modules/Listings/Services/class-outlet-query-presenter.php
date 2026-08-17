<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\OutletItem;
use SutoreMarketplace\Modules\Listings\Domain\OutletOptin;
use SutoreMarketplace\Modules\Listings\Domain\OutletOptinStatus;
use SutoreMarketplace\Modules\Listings\Domain\OutletWindow;
use SutoreMarketplace\Modules\Listings\Domain\OutletWindowStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductSizeLookup;
use SutoreMarketplace\Modules\Listings\Domain\ProductThumbnail;
use SutoreMarketplace\Modules\Listings\Repositories\OutletItemRepository;
use SutoreMarketplace\Modules\Listings\Repositories\OutletOptinRepository;
use SutoreMarketplace\Modules\Listings\Repositories\OutletWindowRepository;
use SutoreMarketplace\Shared\Domain\MarketplacePricing;

final class OutletQueryPresenter
{
    public function __construct(
        private readonly OutletWindowRepository $windows = new OutletWindowRepository(),
        private readonly OutletItemRepository $items = new OutletItemRepository(),
        private readonly OutletOptinRepository $optins = new OutletOptinRepository(),
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function presentForMerchant(int $merchantId): array
    {
        $windows = $this->windows->findOpenForCatalog();
        $windowIds = array_map(static fn (OutletWindow $window): int => $window->id, $windows);
        $catalog = $this->items->findByWindowIds($windowIds);
        $itemIds = array_map(static fn (OutletItem $item): int => $item->id, $catalog);
        $optinMap = $this->optins->findActiveForMerchantItems($merchantId, $itemIds);
        $windowMap = [];
        foreach ($windows as $window) {
            $windowMap[$window->id] = $window;
        }

        $rows = [];
        foreach ($catalog as $item) {
            $window = $windowMap[$item->windowId] ?? null;
            if (!$window) {
                continue;
            }
            $rows[] = $this->serializeMerchantItem($item, $window, $optinMap[$item->id] ?? null);
        }

        return ['items' => $rows];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        $windows = $this->windows->all();
        $windowIds = array_map(static fn (OutletWindow $window): int => $window->id, $windows);
        $itemCounts = $this->items->countsByWindowIds($windowIds);
        $allItems = $this->items->findByWindowIds($windowIds);
        $itemsByWindow = [];
        foreach ($allItems as $item) {
            $itemsByWindow[$item->windowId][] = $item;
        }
        $itemIds = array_map(static fn (OutletItem $item): int => $item->id, $allItems);
        $optinCounts = $this->optins->countsByItemIds($itemIds);

        $out = [];
        foreach ($windows as $window) {
            $pending = 0;
            $live = 0;
            $itemPayload = [];
            foreach ($itemsByWindow[$window->id] ?? [] as $item) {
                $counts = $optinCounts[$item->id] ?? ['pending' => 0, 'live' => 0];
                $pending += (int) $counts['pending'];
                $live += (int) $counts['live'];
                $itemPayload[] = $this->serializeAdminItem($item, $counts);
            }
            $out[] = [
                'id' => $window->id,
                'name' => $window->name,
                'status' => $window->status,
                'status_label' => OutletWindowStatus::label($window->status),
                'starts_at' => $window->startsAt,
                'starts_at_label' => CampaignDatetime::formatLabel($window->startsAt),
                'ends_at' => $window->endsAt,
                'ends_at_label' => CampaignDatetime::formatLabel($window->endsAt),
                'notes' => $window->notes,
                'item_count' => (int) ($itemCounts[$window->id] ?? 0),
                'optins_pending' => $pending,
                'optins_live' => $live,
                'items' => $itemPayload,
                'created_at' => $window->createdAt,
            ];
        }

        return $out;
    }

    /**
     * @param array{pending?: int, live?: int} $counts
     * @return array<string, mixed>
     */
    private function serializeAdminItem(OutletItem $item, array $counts): array
    {
        return [
            'id' => $item->id,
            'window_id' => $item->windowId,
            'parent_product_id' => $item->parentProductId,
            'size_term_id' => $item->sizeTermId,
            'product_title' => get_the_title($item->parentProductId) ?: __('Product', 'sutore-marketplace'),
            'size_label' => ProductSizeLookup::labelForTerm($item->parentProductId, $item->sizeTermId),
            'customer_sale' => $item->customerSale,
            'customer_sale_display' => MarketplacePricing::formatTl($item->customerSale),
            'seller_net' => $item->sellerNet,
            'seller_net_display' => MarketplacePricing::formatTl($item->sellerNet),
            'optins_pending' => (int) ($counts['pending'] ?? 0),
            'optins_live' => (int) ($counts['live'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeMerchantItem(OutletItem $item, OutletWindow $window, ?OutletOptin $optin): array
    {
        $permalink = get_permalink($item->parentProductId);
        $optinStatus = $optin?->status;
        $canOptIn = $optin === null || $optin->status === OutletOptinStatus::CANCELLED
            || $optin->status === OutletOptinStatus::EXPIRED;
        $canCancel = $optin !== null && $optin->status === OutletOptinStatus::PENDING;

        return [
            'id' => $item->id,
            'window_id' => $window->id,
            'window_name' => $window->name,
            'window_status' => $window->status,
            'window_status_label' => OutletWindowStatus::label($window->status),
            'parent_product_id' => $item->parentProductId,
            'product_title' => get_the_title($item->parentProductId) ?: __('Product', 'sutore-marketplace'),
            'permalink' => is_string($permalink) ? $permalink : '',
            'thumbnail' => ProductThumbnail::url($item->parentProductId),
            'size_term_id' => $item->sizeTermId,
            'size_label' => ProductSizeLookup::labelForTerm($item->parentProductId, $item->sizeTermId),
            'customer_sale' => $item->customerSale,
            'customer_sale_display' => MarketplacePricing::formatTl($item->customerSale),
            'seller_net' => $item->sellerNet,
            'seller_net_display' => MarketplacePricing::formatTl($item->sellerNet),
            'starts_at' => $window->startsAt,
            'starts_at_label' => CampaignDatetime::formatLabel($window->startsAt),
            'ends_at' => $window->endsAt,
            'ends_at_label' => CampaignDatetime::formatLabel($window->endsAt),
            'optin_id' => $optin?->id,
            'optin_status' => $optinStatus,
            'optin_status_label' => $optinStatus ? OutletOptinStatus::label($optinStatus) : '',
            'variation_id' => $optin?->variationId,
            'can_opt_in' => $canOptIn && OutletWindowStatus::isOpenForOptIn($window->status),
            'can_cancel' => $canCancel,
        ];
    }
}
