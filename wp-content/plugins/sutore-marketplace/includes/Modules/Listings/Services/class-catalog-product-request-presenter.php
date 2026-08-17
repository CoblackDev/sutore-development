<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Services;

use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequest;
use SutoreMarketplace\Modules\Listings\Domain\CatalogProductRequestStatus;
use SutoreMarketplace\Modules\Listings\Domain\ProductCodeLookup;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class CatalogProductRequestPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(CatalogProductRequest $request, bool $forStaff = false): array
    {
        $parentId = $request->resolvedParentProductId ?? 0;
        $productTitle = '';
        $productCode = '';
        if ($parentId > 0) {
            $productTitle = (string) get_the_title($parentId);
            $productCode = ProductCodeLookup::codeForProduct($parentId);
        }

        $item = [
            'id' => $request->id,
            'merchant_id' => $request->merchantId,
            'sku_or_link' => $request->skuOrLink,
            'size_note' => $request->sizeNote,
            'note' => $request->note,
            'status' => $request->status,
            'status_label' => CatalogProductRequestStatus::label($request->status),
            'resolved_parent_product_id' => $parentId > 0 ? $parentId : null,
            'resolved_product_title' => $productTitle,
            'resolved_product_code' => $productCode,
            'created_at' => $request->createdAt,
            'created_at_display' => CampaignDatetime::formatLabel($request->createdAt),
            'resolved_at' => $request->resolvedAt,
            'resolved_at_display' => CampaignDatetime::formatLabel($request->resolvedAt),
            'listing_create_url' => $this->listingCreateUrl($productCode !== '' ? $productCode : $request->skuOrLink),
        ];

        if ($forStaff) {
            $user = get_userdata($request->merchantId);
            $item['merchant_name'] = $user ? (string) $user->display_name : ('#' . $request->merchantId);
            $level = MerchantLevels::statusForUser($request->merchantId);
            $item['merchant_level'] = $level;
            $item['merchant_level_label'] = MerchantLevels::labelForStatus($level);
            $item['staff_note'] = $request->staffNote;
            $item['resolved_by'] = $request->resolvedBy;
        }

        return $item;
    }

    private function listingCreateUrl(string $productCode): string
    {
        $base = function_exists('wc_get_account_endpoint_url')
            ? wc_get_account_endpoint_url('listings')
            : home_url('/hesabim/listings/');
        $args = ['action' => 'create'];
        $code = trim($productCode);
        if ($code !== '' && preg_match('#^https?://#i', $code) !== 1) {
            $args['product_code'] = $code;
        }

        return add_query_arg($args, $base);
    }
}
