<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Admin;

use SutoreMarketplace\Admin\AdminAssets;
use SutoreMarketplace\Admin\AdminMenu;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDatetime;
use SutoreMarketplace\Modules\Listings\Domain\CampaignDiscountType;
use SutoreMarketplace\Modules\Listings\Domain\CampaignStatus;
use SutoreMarketplace\Modules\Listings\Repositories\CampaignRepository;
use SutoreMarketplace\Modules\Listings\Services\CampaignService;
use SutoreMarketplace\Shared\Domain\MerchantLevels;

final class CampaignsPage
{
    public function render(): void
    {
        if (!current_user_can(AdminMenu::CAP)) {
            return;
        }

        AdminAssets::enqueueCampaigns();

        $items = (new CampaignRepository())->all();
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);
        $brands = taxonomy_exists('product_brand')
            ? get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false])
            : [];

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Campaigns', 'sutore-marketplace') . '</h1>';
        echo '<hr class="wp-header-end" />';
        echo '<p class="description">' . esc_html__(
            'Timed strikethrough campaigns. Create targeting rules, then publish to send product-level offers. Seller and system campaigns use the same accept → sale → revert path.',
            'sutore-marketplace'
        ) . '</p>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        foreach ([
            'ID',
            __('Name', 'sutore-marketplace'),
            __('Source', 'sutore-marketplace'),
            __('Status', 'sutore-marketplace'),
            __('Seller discount', 'sutore-marketplace'),
            __('Platform discount', 'sutore-marketplace'),
            __('Ends', 'sutore-marketplace'),
            __('Action', 'sutore-marketplace'),
        ] as $h) {
            echo '<th scope="col">' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (!$items) {
            echo '<tr><td colspan="8">' . esc_html__('No campaign.', 'sutore-marketplace') . '</td></tr>';
        }

        foreach ($items as $row) {
            echo '<tr>';
            echo '<td>' . (int) $row->id . '</td>';
            echo '<td><strong>' . esc_html($row->name) . '</strong></td>';
            echo '<td>' . esc_html(\SutoreMarketplace\Modules\Listings\Domain\CampaignSource::label(
                isset($row->source) ? (string) $row->source : 'admin'
            )) . '</td>';
            echo '<td><span class="post-state">' . esc_html(CampaignStatus::label((string) $row->status)) . '</span></td>';
            echo '<td>' . esc_html(CampaignDiscountType::ruleLabel(
                isset($row->seller_discount_type) ? (string) $row->seller_discount_type : CampaignDiscountType::FIXED,
                (float) $row->seller_discount_amount,
                __('price', 'sutore-marketplace')
            )) . '</td>';
            echo '<td>' . esc_html(CampaignDiscountType::ruleLabel(
                isset($row->platform_discount_type) ? (string) $row->platform_discount_type : CampaignDiscountType::FIXED,
                (float) $row->platform_discount_amount,
                __('fees', 'sutore-marketplace')
            )) . '</td>';
            echo '<td>' . esc_html(CampaignDatetime::formatLabel(
                isset($row->ends_at) ? (string) $row->ends_at : null
            ) ?: '—') . '</td>';
            echo '<td>';
            if ((string) $row->status === 'draft') {
                echo '<button type="button" class="button button-primary" data-rest-click data-rest-path="admin/campaigns/'
                    . (int) $row->id . '/publish" data-rest-method="POST">'
                    . esc_html__('Publish offers', 'sutore-marketplace') . '</button> ';
            }
            if ((string) $row->status === 'active') {
                echo '<button type="button" class="button" data-rest-click data-rest-path="admin/campaigns/'
                    . (int) $row->id . '/publish" data-rest-method="POST">'
                    . esc_html__('Re-publish', 'sutore-marketplace') . '</button> ';
                echo '<button type="button" class="button" data-rest-click data-rest-path="admin/campaigns/'
                    . (int) $row->id . '/end" data-rest-method="POST">'
                    . esc_html__('End', 'sutore-marketplace') . '</button>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('New campaign', 'sutore-marketplace') . '</h2>';
        echo '<form class="sutore-mp-admin-rest sutore-mp-campaign-form" data-rest-path="admin/campaigns" data-rest-method="POST">';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="campaign_name">' . esc_html__('Name', 'sutore-marketplace') . '</label></th>';
        echo '<td><input name="name" type="text" id="campaign_name" class="regular-text" required /></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Seller discount', 'sutore-marketplace') . '</th><td>';
        echo '<select name="seller_discount_type" id="seller_discount_type">';
        echo '<option value="fixed">' . esc_html(CampaignDiscountType::label(CampaignDiscountType::FIXED)) . '</option>';
        echo '<option value="percent">' . esc_html(CampaignDiscountType::label(CampaignDiscountType::PERCENT)) . '</option>';
        echo '</select> ';
        echo '<input name="seller_discount_amount" type="number" id="seller_discount_amount" class="regular-text" value="0" step="0.01" min="0" />';
        echo '<p class="description">' . esc_html__(
            'Fixed TL off the price, or percent of the product price (resolved per product when offers are published).',
            'sutore-marketplace'
        ) . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Platform discount / fee waiver', 'sutore-marketplace') . '</th><td>';
        echo '<select name="platform_discount_type" id="platform_discount_type">';
        echo '<option value="fixed">' . esc_html(CampaignDiscountType::label(CampaignDiscountType::FIXED)) . '</option>';
        echo '<option value="percent">' . esc_html(CampaignDiscountType::label(CampaignDiscountType::PERCENT)) . '</option>';
        echo '</select> ';
        echo '<input name="platform_discount_amount" type="number" id="platform_discount_amount" class="regular-text" value="0" step="0.01" min="0" />';
        echo '<p class="description">' . esc_html__(
            'Fixed TL fee waiver, or percent of service + guarantee fees after the seller cut. Always capped by those fees.',
            'sutore-marketplace'
        ) . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="starts_at">' . esc_html__('Starts at', 'sutore-marketplace') . '</label></th>';
        echo '<td><input name="starts_at" type="datetime-local" id="starts_at" class="regular-text" /></td></tr>';

        echo '<tr><th scope="row"><label for="ends_at">' . esc_html__('Ends at', 'sutore-marketplace') . '</label></th>';
        echo '<td><input name="ends_at" type="datetime-local" id="ends_at" class="regular-text" /></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Seller levels', 'sutore-marketplace') . '</th><td>';
        foreach ([MerchantLevels::NORMAL, MerchantLevels::VERIFIED, MerchantLevels::PREMIUM] as $level) {
            echo '<label style="margin-right:12px;"><input type="checkbox" name="merchant_levels[]" value="'
                . esc_attr($level) . '" /> ' . esc_html(MerchantLevels::labelForStatus($level)) . '</label>';
        }
        echo '<p class="description">' . esc_html__('Leave empty to include all levels.', 'sutore-marketplace') . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Product price range (TL)', 'sutore-marketplace') . '</th><td>';
        echo '<input name="asking_min" type="number" class="small-text" min="0" step="1" placeholder="'
            . esc_attr__('Min', 'sutore-marketplace') . '" /> — ';
        echo '<input name="asking_max" type="number" class="small-text" min="0" step="1" placeholder="'
            . esc_attr__('Max', 'sutore-marketplace') . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="category_ids">' . esc_html__('Categories (optional)', 'sutore-marketplace') . '</label></th><td>';
        echo '<select name="category_ids[]" id="category_ids" multiple style="min-width:280px;min-height:100px;">';
        if (!is_wp_error($categories) && is_array($categories)) {
            foreach ($categories as $term) {
                echo '<option value="' . (int) $term->term_id . '">' . esc_html($term->name) . '</option>';
            }
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Leave empty to include all categories.', 'sutore-marketplace') . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="brand_ids">' . esc_html__('Brands (optional)', 'sutore-marketplace') . '</label></th><td>';
        echo '<select name="brand_ids[]" id="brand_ids" multiple style="min-width:280px;min-height:100px;">';
        if (!is_wp_error($brands) && is_array($brands)) {
            foreach ($brands as $term) {
                echo '<option value="' . (int) $term->term_id . '">' . esc_html($term->name) . '</option>';
            }
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Leave empty to include all brands.', 'sutore-marketplace') . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="campaign_product_search">' . esc_html__('Products (optional)', 'sutore-marketplace') . '</label></th><td>';
        echo '<div class="sutore-mp-admin-product-picker" data-mode="multiple">';
        echo '<input type="search" id="campaign_product_search" class="sutore-mp-admin-product-search regular-text" autocomplete="off" placeholder="'
            . esc_attr__('Search by product name or SKU…', 'sutore-marketplace') . '" />';
        echo '<input type="hidden" name="product_ids" id="product_ids" value="" />';
        echo '<div class="sutore-mp-admin-product-results" hidden></div>';
        echo '<ul class="sutore-mp-admin-product-chips"></ul>';
        echo '</div>';
        echo '<p class="description">' . esc_html__('Search by name or SKU to narrow the campaign to specific catalog products. Leave empty to include all matching products.', 'sutore-marketplace') . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Audience preview', 'sutore-marketplace') . '</th><td>';
        echo '<div class="sutore-mp-campaign-live-preview notice notice-info inline" style="margin:0;padding:10px 12px;" role="status" aria-live="polite">';
        echo '<p class="sutore-mp-campaign-preview-result" style="margin:0 0 8px;">'
            . esc_html__('Adjust targeting to see how many products this campaign will cover.', 'sutore-marketplace')
            . '</p>';
        echo '<ul class="sutore-mp-campaign-preview-samples" style="margin:0;padding-left:18px;list-style:disc;"></ul>';
        echo '</div>';
        echo '<p class="description">' . esc_html__('Updates automatically as you change levels, price range, categories, brands, or products. Category and brand filters are optional.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="campaign_notes">' . esc_html__('Note', 'sutore-marketplace') . '</label></th>';
        echo '<td><textarea name="notes" id="campaign_notes" class="large-text" rows="3"></textarea></td></tr>';

        echo '</tbody></table>';
        echo '<p>';
        submit_button(__('Create draft', 'sutore-marketplace'), 'primary', 'submit', false);
        echo '</p>';
        echo '</form></div>';
    }
}
