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

        (new CampaignService())->runExpiryPass(50);

        AdminAssets::enqueue();

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
            'Create a campaign with targeting rules, then publish to send product-level offers to matching merchants. Accepted offers apply WooCommerce scheduled sale prices.',
            'sutore-marketplace'
        ) . '</p>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        foreach ([
            'ID',
            __('Name', 'sutore-marketplace'),
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
            echo '<tr><td colspan="7">' . esc_html__('No campaign.', 'sutore-marketplace') . '</td></tr>';
        }

        foreach ($items as $row) {
            echo '<tr>';
            echo '<td>' . (int) $row->id . '</td>';
            echo '<td><strong>' . esc_html($row->name) . '</strong></td>';
            echo '<td><span class="post-state">' . esc_html(CampaignStatus::label((string) $row->status)) . '</span></td>';
            echo '<td>' . esc_html(CampaignDiscountType::ruleLabel(
                isset($row->seller_discount_type) ? (string) $row->seller_discount_type : CampaignDiscountType::FIXED,
                (float) $row->seller_discount_amount,
                __('asking', 'sutore-marketplace')
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
            'Fixed TL off asking, or percent of the listing asking price (resolved per product when offers are published).',
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

        echo '<tr><th scope="row">' . esc_html__('Listing asking range (TL)', 'sutore-marketplace') . '</th><td>';
        echo '<input name="asking_min" type="number" class="small-text" min="0" step="1" placeholder="'
            . esc_attr__('Min', 'sutore-marketplace') . '" /> — ';
        echo '<input name="asking_max" type="number" class="small-text" min="0" step="1" placeholder="'
            . esc_attr__('Max', 'sutore-marketplace') . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="category_ids">' . esc_html__('Categories', 'sutore-marketplace') . '</label></th><td>';
        echo '<select name="category_ids[]" id="category_ids" multiple style="min-width:280px;min-height:100px;">';
        if (!is_wp_error($categories) && is_array($categories)) {
            foreach ($categories as $term) {
                echo '<option value="' . (int) $term->term_id . '">' . esc_html($term->name) . '</option>';
            }
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="brand_ids">' . esc_html__('Brands', 'sutore-marketplace') . '</label></th><td>';
        echo '<select name="brand_ids[]" id="brand_ids" multiple style="min-width:280px;min-height:100px;">';
        if (!is_wp_error($brands) && is_array($brands)) {
            foreach ($brands as $term) {
                echo '<option value="' . (int) $term->term_id . '">' . esc_html($term->name) . '</option>';
            }
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="product_ids">' . esc_html__('Product IDs (optional)', 'sutore-marketplace') . '</label></th>';
        echo '<td><input name="product_ids" type="text" id="product_ids" class="regular-text" placeholder="12, 34, 56" />';
        echo '<p class="description">' . esc_html__('Comma-separated parent product IDs to further narrow the audience.', 'sutore-marketplace') . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Audience preview', 'sutore-marketplace') . '</th><td>';
        echo '<div class="sutore-mp-campaign-live-preview notice notice-info inline" style="margin:0;padding:10px 12px;" role="status" aria-live="polite">';
        echo '<p class="sutore-mp-campaign-preview-result" style="margin:0;">'
            . esc_html__('Adjust targeting to see how many products this campaign will cover.', 'sutore-marketplace')
            . '</p>';
        echo '</div>';
        echo '<p class="description">' . esc_html__('Updates automatically as you change levels, price range, categories, brands, or product IDs.', 'sutore-marketplace') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="campaign_notes">' . esc_html__('Note', 'sutore-marketplace') . '</label></th>';
        echo '<td><textarea name="notes" id="campaign_notes" class="large-text" rows="3"></textarea></td></tr>';

        echo '</tbody></table>';
        echo '<p>';
        submit_button(__('Create draft', 'sutore-marketplace'), 'primary', 'submit', false);
        echo '</p>';
        echo '</form></div>';

        $i18n = [
            'loading' => __('Counting matching products…', 'sutore-marketplace'),
            'error' => __('Could not refresh audience preview.', 'sutore-marketplace'),
            /* translators: 1: listing/product count, 2: merchant count */
            'coverTpl' => __('This campaign will cover %1$d products (%2$d merchants).', 'sutore-marketplace'),
            'coverZero' => __('No products match the current targeting.', 'sutore-marketplace'),
        ];

        $script = 'window.SutoreMpCampaignPreviewI18n = ' . wp_json_encode($i18n) . ";\n" . <<<'JS'
(function () {
  var form = document.querySelector('.sutore-mp-campaign-form');
  if (!form || !window.SutoreMarketplaceAdmin) return;
  var cfg = window.SutoreMarketplaceAdmin;
  var i18n = window.SutoreMpCampaignPreviewI18n || {};
  var previewOut = form.querySelector('.sutore-mp-campaign-preview-result');
  var debounceTimer = null;
  var requestSeq = 0;

  function collectTargeting(formEl) {
    var levels = [];
    formEl.querySelectorAll('input[name="merchant_levels[]"]:checked').forEach(function (el) {
      levels.push(el.value);
    });
    function multi(name) {
      return Array.prototype.map.call(formEl.querySelectorAll('select[name="' + name + '"] option:checked'), function (o) {
        return parseInt(o.value, 10);
      }).filter(Boolean);
    }
    var productRaw = (formEl.querySelector('[name="product_ids"]') || {}).value || '';
    var productIds = productRaw.split(/[\s,]+/).map(function (v) { return parseInt(v, 10); }).filter(Boolean);
    var askingMin = (formEl.querySelector('[name="asking_min"]') || {}).value || '';
    var askingMax = (formEl.querySelector('[name="asking_max"]') || {}).value || '';
    return {
      merchant_levels: levels,
      asking_min: askingMin !== '' ? askingMin : null,
      asking_max: askingMax !== '' ? askingMax : null,
      category_ids: multi('category_ids[]'),
      brand_ids: multi('brand_ids[]'),
      product_ids: productIds
    };
  }

  function formatPreview(data) {
    var listings = parseInt((data && data.listing_count) || 0, 10) || 0;
    var merchants = parseInt((data && data.merchant_count) || 0, 10) || 0;
    if (listings <= 0) {
      return i18n.coverZero || 'No products match the current targeting.';
    }
    var tpl = i18n.coverTpl || 'This campaign will cover %1$d products (%2$d merchants).';
    return tpl.replace('%1$d', String(listings)).replace('%2$d', String(merchants));
  }

  function refreshPreview() {
    if (!previewOut) return;
    var seq = ++requestSeq;
    previewOut.textContent = i18n.loading || 'Counting matching products…';
    fetch((cfg.restUrl || '') + 'admin/campaigns/preview', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
      body: JSON.stringify({ targeting: collectTargeting(form) })
    }).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok || !json || json.success !== true) {
          throw new Error((json && json.message) || (i18n.error || 'Error'));
        }
        if (seq !== requestSeq) return;
        previewOut.textContent = formatPreview(json.data || {});
      });
    }).catch(function (err) {
      if (seq !== requestSeq) return;
      previewOut.textContent = (err && err.message) || (i18n.error || 'Error');
    });
  }

  function schedulePreview() {
    if (debounceTimer) {
      clearTimeout(debounceTimer);
    }
    debounceTimer = setTimeout(refreshPreview, 350);
  }

  form.addEventListener('change', function (event) {
    var name = (event.target && event.target.name) || '';
    if (
      name.indexOf('merchant_levels') === 0 ||
      name === 'asking_min' ||
      name === 'asking_max' ||
      name === 'category_ids[]' ||
      name === 'brand_ids[]' ||
      name === 'product_ids'
    ) {
      schedulePreview();
    }
  });
  form.addEventListener('input', function (event) {
    var name = (event.target && event.target.name) || '';
    if (name === 'asking_min' || name === 'asking_max' || name === 'product_ids') {
      schedulePreview();
    }
  });

  function toMysqlLocal(v) {
    if (!v) return null;
    return v.replace('T', ' ') + ':00';
  }
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    event.stopImmediatePropagation();
    var body = {
      name: form.name.value,
      seller_discount_type: form.seller_discount_type.value,
      seller_discount_amount: form.seller_discount_amount.value,
      platform_discount_type: form.platform_discount_type.value,
      platform_discount_amount: form.platform_discount_amount.value,
      starts_at: toMysqlLocal(form.starts_at.value),
      ends_at: toMysqlLocal(form.ends_at.value),
      notes: form.notes.value,
      targeting: collectTargeting(form)
    };
    fetch((cfg.restUrl || '') + 'admin/campaigns', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
      body: JSON.stringify(body)
    }).then(function (res) { return res.json().then(function (json) {
      if (!res.ok || !json || json.success !== true) {
        throw new Error((json && json.message) || 'Error');
      }
      window.location.reload();
    }); }).catch(function (err) { window.alert(err.message || 'Error'); });
  }, true);

  refreshPreview();
})();
JS;
        wp_add_inline_script('sutore-marketplace-admin', $script);
    }
}
