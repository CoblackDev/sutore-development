(function ($) {
  'use strict';

  var searchTimer = null;
  var searchReq = null;
  var listSearchTimer = null;
  var editReq = null;
  var askingTimer = null;
  var t = SutoreMarketplace.t;
  var api = SutoreMarketplace.api;
  var thumbBox = SutoreMarketplace.thumbBox;
  var showConfirm = SutoreMarketplace.showConfirm;

  function statusLabel(item) {
    if (item.listing_status_label) {
      var preferred = item.listing_status_label;
      if (item.is_winner && item.listing_status === 'publish') {
        preferred += ' ★';
      }
      return preferred;
    }
    var map = {
      publish: t('statusPublish', 'For sale'),
      queued: t('statusQueued', 'In queue'),
      pending: t('statusPending', 'Awaiting approval'),
      expired: t('statusExpired', 'Expired'),
      not_sale: t('statusNotSale', 'Not for sale'),
      payment: t('statusPayment', 'Awaiting payment confirmation'),
      sold: t('statusSold', 'Awaiting merchant confirmation'),
      confirmed: t('statusConfirmed', 'Merchant confirmed'),
      shipped_to_sutore: t('statusShippedToSutore', 'Shipped to Sutore'),
      arrived_to_sutore: t('statusArrivedToSutore', 'Arrived at Sutore'),
      verified: t('statusVerified', 'Verified'),
      ready_to_shipping: t('statusReadyToShipping', 'Ready to ship'),
      shipped: t('statusShipped', 'Shipped to customer'),
      delivered_to_customer: t('statusDeliveredToCustomer', 'Delivered to customer'),
      chargeback: t('statusChargeback', 'Refunded'),
      winner: t('statusPublish', 'For sale')
    };
    var label = map[item.listing_status] || item.listing_status;
    if (item.is_winner && item.listing_status === 'publish') {
      label += ' ★';
    }
    if ((!label || label === item.listing_status) && item.fulfillment && item.fulfillment.status_label) {
      return item.fulfillment.status_label;
    }
    return label;
  }

  var SALE_LIFECYCLE_STATUSES = [
    'payment',
    'sold',
    'confirmed',
    'shipped_to_sutore',
    'arrived_to_sutore',
    'verified',
    'ready_to_shipping',
    'shipped',
    'delivered_to_customer',
    'chargeback'
  ];

  var SALE_ACTIVE_STATUSES = [
    'payment',
    'sold',
    'confirmed',
    'shipped_to_sutore',
    'arrived_to_sutore',
    'verified',
    'ready_to_shipping',
    'shipped',
    'delivered_to_customer'
  ];

  function isSaleLifecycleStatus(status) {
    return SALE_LIFECYCLE_STATUSES.indexOf(String(status || '')) !== -1;
  }

  function isEditLockedStatus(status) {
    status = String(status || '');
    return SALE_ACTIVE_STATUSES.indexOf(status) !== -1;
  }

  function conditionLabel(key) {
    var map = {
      no_box: t('condNoBox', 'No box'),
      box_damaged: t('condBoxDamaged', 'Box damaged'),
      missing_accessory: t('condMissingAccessory', 'Missing accessory'),
      damaged: t('condDamaged', 'Damaged'),
      used: t('condUsed', 'Used')
    };
    return map[key] || key;
  }

  function conditionTags(item) {
    var tags = [];
    var conds = item.conditions || {};
    Object.keys(conds).forEach(function (key) {
      if (!conds[key]) return;
      if (key === 'has_invoice' || key === 'fast_shipment') return;
      tags.push({ label: conditionLabel(key), cls: 'is-condition' });
    });
    return tags;
  }

  function shippingTags(item) {
    var tags = [];
    if (item.fast_shipment) {
      tags.push({ label: t('expressShipping', 'Fast / Express'), cls: 'is-shipping' });
    }
    if (item.has_invoice) {
      tags.push({ label: t('internationalShipping', 'International'), cls: 'is-international' });
    }
    return tags;
  }

  function listingTags(item) {
    var tags = conditionTags(item).concat(shippingTags(item));
    if (item.is_sourcing) {
      tags.unshift({ label: t('preOrderProduct', 'Pre-order'), cls: 'is-sourcing' });
    }
    if (item.is_imported) {
      tags.unshift({ label: t('importedProduct', 'Imported'), cls: 'is-imported' });
    }
    if (item.campaign_status === 'offer') {
      tags.unshift({ label: t('campaignOfferTag', 'Campaign offer'), cls: 'is-campaign-offer' });
    } else if (item.campaign_status === 'active') {
      tags.unshift({ label: t('campaignActiveTag', 'On campaign'), cls: 'is-campaign-active' });
    }
    return tags;
  }

  function renderTagsCell(tags, className) {
    var $cell = $('<td/>').addClass(className);
    if (!tags.length) {
      return $cell.text('—');
    }
    var $inline = $('<span class="sutore-mp-competing-tags-inline"/>');
    tags.forEach(function (tag) {
      $inline.append($('<span class="sutore-mp-tag"/>').addClass(tag.cls).text(tag.label));
    });
    return $cell.append($inline);
  }

  function formatRemaining(item) {
    if (item.fulfillment) {
      if (item.fulfillment.can_confirm && item.fulfillment.confirm_remaining_label) {
        return item.fulfillment.confirm_remaining_label;
      }
      if (item.fulfillment.can_ship && item.fulfillment.cargo_remaining_label) {
        return item.fulfillment.cargo_remaining_label;
      }
    }
    if (item.remaining_label) {
      return item.remaining_label;
    }
    if (item.listing_status === 'expired') {
      return t('timeExpired', 'Expired');
    }
    return '';
  }

  function conditions($root) {
    var out = {};
    $root.find('.sutore-mp-conditions input[type=checkbox][name^="conditions"]').each(function () {
      if (this.checked) {
        var key = $(this).attr('name').replace('conditions[', '').replace(']', '');
        out[key] = 1;
      }
    });
    return out;
  }

  function $shell($from) {
    return $from.closest('.sutore-mp-listings');
  }

  function $formWrap($from) {
    var $wrap = $from.closest('.sutore-mp-listing-form-wrap');
    if ($wrap.length) {
      return $wrap;
    }

    return $shell($from).find('.sutore-mp-listing-form-wrap');
  }

  function $overlay($from) {
    return $formWrap($from);
  }

  function $form($from) {
    var $wrap = $formWrap($from);
    if ($wrap.length) {
      return $wrap;
    }

    return $shell($from).find('.sutore-mp-listing-form-wrap');
  }

  function isPageMode($listings) {
    return $listings.attr('data-create-mode') === '1';
  }

  function hasManageModal($listings) {
    return $listings.find('.sutore-mp-manage-overlay').length > 0;
  }

  function isManageModalOpen($listings) {
    var $overlay = $listings.find('.sutore-mp-manage-overlay');
    return $overlay.length > 0 && !$overlay.prop('hidden');
  }

  function listingIdFromUrl() {
    try {
      return parseInt(new URL(window.location.href).searchParams.get('listing_id') || '0', 10) || 0;
    } catch (err) {
      return 0;
    }
  }

  function createModeFromUrl() {
    try {
      return new URL(window.location.href).searchParams.get('action') === 'create';
    } catch (err) {
      return false;
    }
  }

  function bulkModeFromUrl() {
    try {
      return new URL(window.location.href).searchParams.get('action') === 'bulk';
    } catch (err) {
      return false;
    }
  }

  function isCreateManageMode($listings) {
    return $listings.data('manage-mode') === 'create';
  }

  function hasBulkModal($listings) {
    return $listings.find('.sutore-mp-bulk-overlay').length > 0;
  }

  function isBulkModalOpen($listings) {
    var $overlay = $listings.find('.sutore-mp-bulk-overlay');
    return $overlay.length > 0 && !$overlay.prop('hidden') && $overlay.hasClass('is-open');
  }

  function syncManageUrl(listingId, replace, mode) {
    try {
      var u = new URL(window.location.href);
      u.searchParams.delete('mp_page');
      if (listingId) {
        u.searchParams.delete('action');
        u.searchParams.set('listing_id', String(listingId));
      } else if (mode === 'create' || mode === 'bulk') {
        u.searchParams.delete('listing_id');
        u.searchParams.set('action', mode);
      } else {
        u.searchParams.delete('listing_id');
        u.searchParams.delete('action');
      }
      var next = u.pathname + u.search + u.hash;
      var state = {
        sutoreManageId: listingId || 0,
        sutoreCreate: mode === 'create',
        sutoreBulk: mode === 'bulk'
      };
      if (replace) {
        window.history.replaceState(state, '', next);
      } else {
        window.history.pushState(state, '', next);
      }
    } catch (err) {
      /* ignore */
    }
  }

  function listingsUrl() {
    return (window.SutoreMarketplace && SutoreMarketplace.listingsUrl) || '';
  }

  function manageUrl(listingId) {
    var base = listingsUrl();
    if (!base) {
      return window.location.href;
    }
    try {
      var u = new URL(base, window.location.origin);
      u.searchParams.delete('action');
      u.searchParams.delete('listing_id');
      u.searchParams.delete('mp_page');
      if (listingId) {
        u.searchParams.set('listing_id', String(listingId));
      } else {
        u.searchParams.set('action', 'create');
      }
      return u.toString();
    } catch (err) {
      if (listingId) {
        return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'listing_id=' + encodeURIComponent(String(listingId));
      }
      return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'action=create';
    }
  }

  function goToListings() {
    var url = listingsUrl();
    window.location.href = url || window.location.pathname;
  }

  function priceStep($root) {
    return parseInt($root.attr('data-price-step') || SutoreMarketplace.priceStep || 25, 10) || 25;
  }

  function setPriceAlert($root, variant, message) {
    var $alert = $root.find('.sutore-mp-price-alert');
    if (!message) {
      $alert.text('').removeClass('is-warn is-error is-success').addClass('is-hidden').prop('hidden', true);
      return;
    }
    $alert
      .text(message)
      .removeClass('is-hidden is-warn is-error is-success')
      .addClass(variant ? 'is-' + variant : '')
      .prop('hidden', false);
  }

  function validateAsking($root, opts) {
    opts = opts || {};
    var step = priceStep($root);
    var raw = $.trim(String($root.find('.sutore-mp-asking').val() || ''));
    var $submit = $overlay($root).find('.sutore-mp-submit');
    var $input = $root.find('.sutore-mp-asking');

    if (raw === '') {
      if (opts.requireValue) {
        setPriceAlert($root, 'error', t('priceRequired', 'Enter a price.'));
        $input.addClass('is-invalid');
        $submit.prop('disabled', true);
        return false;
      }
      setPriceAlert($root, null, '');
      $input.removeClass('is-invalid');
      $submit.prop('disabled', true);
      return false;
    }

    var n = Number(String(raw).replace(',', '.'));
    var isInt = Number.isFinite(n) && Math.abs(n - Math.round(n)) < 0.00001;
    var ok = isInt && n > 0 && (Math.round(n) % step) === 0;
    if (!ok) {
      setPriceAlert(
        $root,
        'error',
        (t('priceStepError', 'Price must be in multiples of %d TL. Decimal prices are not allowed.')).replace('%d', String(step))
      );
      $input.addClass('is-invalid');
      $submit.prop('disabled', true);
      return false;
    }

    var maxAsking = $root.data('max-asking');
    if (maxAsking != null && Number.isFinite(Number(maxAsking)) && n > Number(maxAsking)) {
      setPriceAlert(
        $root,
        'error',
        t('campaignAskingRaiseBlocked', 'This listing is in a campaign, so you cannot increase the asking price.')
      );
      $input.addClass('is-invalid');
      $submit.prop('disabled', true);
      return false;
    }

    $input.removeClass('is-invalid');
    $submit.prop('disabled', false);
    updatePriceInfoAlert($root, n);
    return true;
  }

  function updatePriceInfoAlert($root, asking) {
    if (asking == null) {
      var raw = $.trim(String($root.find('.sutore-mp-asking').val() || ''));
      asking = Number(String(raw).replace(',', '.'));
      if (!Number.isFinite(asking) || asking <= 0) {
        return;
      }
    }

    var retailTl = parseFloat($root.data('retail-tl'));
    if (retailTl && asking < retailTl) {
      var warn = (t('belowRetailWarn', 'This listing will go on sale below the product’s starting price (≈ %s TL).'))
        .replace('%s', Number(retailTl).toLocaleString('tr-TR'));
      setPriceAlert($root, 'warn', warn);
      return;
    }

    var queuePos = parseInt($root.data('queue-position'), 10);
    var blockedByCondition = !!$root.data('blocked-by-condition');
    if (blockedByCondition) {
      setPriceAlert($root, 'warn', t('blockedByFlawlessWarn', 'Defective products cannot go for sale until undamaged products are sold — they wait in queue regardless of price.'));
      return;
    }
    if (queuePos === 1 || $root.data('can-win-sale')) {
      var alertKey = $root.data('merchant-auto-activates')
        ? 'firstPlaceAlertForSale'
        : 'firstPlaceAlertAwaitingApproval';
      var alertFallback = $root.data('merchant-auto-activates')
        ? 'At this price you will move to #1 — the product will be for sale.'
        : 'At this price you will move to #1 — awaiting approval before going on sale.';
      setPriceAlert($root, 'success', t(alertKey, alertFallback));
      return;
    }

    setPriceAlert($root, null, '');
  }

  function setSelectedProduct($root, data) {
    if (data.has_retail_price && data.retail_price_usd != null) {
      $root.data('retail-tl', data.retail_price_tl);
      $root.data('retail-usd', data.retail_price_usd);
    } else if (data.clear_retail) {
      $root.removeData('retail-tl').removeData('retail-usd');
    }
    updateRetailPriceDisplay($root, data);
  }

  function updateRetailPriceDisplay($root, data) {
    data = data || {};
    var $row = $root.find('.sutore-mp-retail-price-row');
    var $dd = $root.find('.sutore-mp-retail-price');
    if (!$row.length) {
      return;
    }

    var tl = data.retail_price_tl != null ? data.retail_price_tl : $root.data('retail-tl');
    var usd = data.retail_price_usd != null ? data.retail_price_usd : $root.data('retail-usd');
    var hasRetail = data.has_retail_price != null
      ? !!data.has_retail_price
      : (tl != null && tl !== '' && !isNaN(parseFloat(tl)));

    $row.prop('hidden', false);
    if (!hasRetail || tl == null || tl === '') {
      $dd.text('—');
      return;
    }

    var label = Number(tl).toLocaleString('tr-TR') + ' TL';
    if (usd != null && usd !== '') {
      label += ' (≈ $' + Number(usd).toLocaleString('en-US', { maximumFractionDigits: 2 }) + ')';
    }
    $dd.text(label);
  }

  function shippingPayload($root) {
    return {
      fast_shipment: $root.find('.sutore-mp-shipping-express-flag').is(':checked') ? 1 : 0,
      has_invoice: $root.find('.sutore-mp-shipping-intl-flag').is(':checked') ? 1 : 0
    };
  }

  function syncInternationalCommit($root) {
    var intlOn = $root.find('.sutore-mp-shipping-intl-flag').is(':checked');
    var $block = $root.find('.sutore-mp-international-commit');
    if (intlOn) {
      $block.prop('hidden', false).removeClass('is-hidden');
    } else {
      $block.prop('hidden', true).addClass('is-hidden');
    }
  }

  function setShippingError($root, message) {
    var $err = $root.find('.sutore-mp-shipping-error');
    if (!message) {
      $err.text('').prop('hidden', true).addClass('is-hidden');
      return;
    }
    $err.text(message).prop('hidden', false).removeClass('is-hidden');
  }

  function applyShippingOptions($root, d) {
    var expressOn = !!d.fast_shipment;
    var intlOn = !!d.has_invoice;
    var eligible = !!d.fast_shipment_eligible;
    var $expressOption = $root.find('.sutore-mp-shipping-express');
    var $expressInput = $root.find('.sutore-mp-shipping-express-flag');
    var $intlInput = $root.find('.sutore-mp-shipping-intl-flag');
    var $hint = $root.find('.sutore-mp-express-ineligible');

    if (eligible) {
      $expressOption.removeClass('is-disabled');
      $expressInput.prop('disabled', false);
      $hint.prop('hidden', true).addClass('is-hidden');
    } else {
      $expressOption.addClass('is-disabled');
      $expressInput.prop('disabled', true);
      $hint.prop('hidden', false).removeClass('is-hidden');
      expressOn = false;
    }

    $expressInput.prop('checked', expressOn);
    $intlInput.prop('checked', intlOn);
    syncInternationalCommit($root);
    setShippingError($root, '');
  }

  function sizeTermId($root) {
    var locked = $root.data('locked-size-id');
    if (locked) {
      return String(locked);
    }
    return $root.find('.sutore-mp-size:checked').val() || '';
  }

  function setSizeLocked($root, locked, termId, label) {
    var $sizes = $root.find('.sutore-mp-sizes');

    if (locked && termId) {
      $root.data('locked-size-id', termId);
      $root.data('locked-size-label', label || '');
      $sizes.prop('hidden', true).addClass('is-hidden');
    } else {
      $root.removeData('locked-size-id').removeData('locked-size-label');
      $sizes.prop('hidden', false).removeClass('is-hidden');
    }
  }

  function competingPricesScope($root) {
    var $listings = $shell($root);
    if (isCreateManageMode($listings)) {
      return $listings.find('.sutore-mp-size-prices-modal');
    }
    return $root;
  }

  function renderCompetingPrices($root, d) {
    var $scope = competingPricesScope($root);
    var $block = $scope.find('.sutore-mp-competing-prices');
    var $tableWrap = $scope.find('.sutore-mp-competing-prices-table-wrap');
    var $tbody = $scope.find('.sutore-mp-competing-prices-table tbody');
    var $locked = $scope.find('.sutore-mp-competing-prices-locked');
    var $empty = $scope.find('.sutore-mp-competing-prices-empty');
    var $listings = $shell($root);
    var inCreateModal = isCreateManageMode($listings);

    if (!d.parent_product_id || !d.size_term_id) {
      if (!inCreateModal) {
        $block.prop('hidden', true).addClass('is-hidden');
      }
      $tbody.empty();
      $tableWrap.prop('hidden', true).addClass('is-hidden');
      $locked.prop('hidden', true).addClass('is-hidden');
      $empty.prop('hidden', true).addClass('is-hidden');
      return;
    }

    $block.prop('hidden', false).removeClass('is-hidden');

    if (!d.can_view_competing_prices) {
      $tbody.empty();
      $tableWrap.prop('hidden', true).addClass('is-hidden');
      $empty.prop('hidden', true).addClass('is-hidden');
      $locked.prop('hidden', false).removeClass('is-hidden');
      return;
    }

    $locked.prop('hidden', true).addClass('is-hidden');
    var items = d.competing_prices || [];
    if (!items.length) {
      $tbody.empty();
      $tableWrap.prop('hidden', true).addClass('is-hidden');
      $empty.prop('hidden', false).removeClass('is-hidden');
      return;
    }

    $empty.prop('hidden', true).addClass('is-hidden');
    $tableWrap.prop('hidden', false).removeClass('is-hidden');
    $tbody.empty();

    items.forEach(function (item) {
      var $row = $('<tr/>');
      if (item.is_current) {
        $row.addClass('is-current');
      } else if (item.is_own) {
        $row.addClass('is-own');
      }
      if (item.is_winner && item.listing_status === 'publish') {
        $row.addClass('is-winner');
      }

      $row.append($('<td class="sutore-mp-competing-col-pos"/>').text(String(item.position)));
      $row.append($('<td class="sutore-mp-competing-col-asking"/>').text(item.asking_display || '—'));
      $row.append(renderTagsCell(conditionTags(item), 'sutore-mp-competing-col-tags'));
      $row.append(renderTagsCell(shippingTags(item), 'sutore-mp-competing-col-shipping'));
      $row.append($('<td class="sutore-mp-competing-col-time"/>').text(formatRemaining(item) || '—'));

      $tbody.append($row);
    });
  }

  function renderManagePricesPanel(d) {
    d = d || {};
    var items = d.competing_prices || [];
    if (!items.length) {
      return $('<p class="sutore-mp-empty"/>').text(
        t('sizePriceListEmpty', 'No other Listing for sale or in queue for this size.')
      );
    }

    var $block = $('<div class="sutore-mp-competing-prices"/>');
    var $wrap = $('<div class="sutore-mp-staff-table-wrap sutore-mp-competing-prices-table-wrap"/>');
    var $table = $(
      '<table class="sutore-mp-staff-table sutore-mp-competing-prices-table">' +
      '<thead><tr>' +
      '<th scope="col"></th><th scope="col"></th><th scope="col"></th>' +
      '<th scope="col"></th><th scope="col"></th>' +
      '</tr></thead><tbody></tbody></table>'
    );
    var $ths = $table.find('th');
    $ths.eq(0).text(t('queue', 'Queue'));
    $ths.eq(1).text(t('price', 'Price'));
    $ths.eq(2).text(t('condition', 'Condition'));
    $ths.eq(3).text(t('shippingOptions', 'Shipping'));
    $ths.eq(4).text(t('timeLeft', 'Time remaining'));

    var $tbody = $table.find('tbody');
    items.forEach(function (item) {
      var $row = $('<tr/>');
      if (item.is_current) {
        $row.addClass('is-current');
      } else if (item.is_own) {
        $row.addClass('is-own');
      }
      if (item.is_winner && item.listing_status === 'publish') {
        $row.addClass('is-winner');
      }
      $row.append($('<td class="sutore-mp-competing-col-pos"/>').text(String(item.position)));
      $row.append($('<td class="sutore-mp-competing-col-asking"/>').text(item.asking_display || '—'));
      $row.append(renderTagsCell(conditionTags(item), 'sutore-mp-competing-col-tags'));
      $row.append(renderTagsCell(shippingTags(item), 'sutore-mp-competing-col-shipping'));
      $row.append($('<td class="sutore-mp-competing-col-time"/>').text(formatRemaining(item) || '—'));
      $tbody.append($row);
    });

    return $block.append($wrap.append($table));
  }

  function applyContext($root, d, options) {
    options = options || {};
    $root.find('.sutore-mp-min-price').text(d.min_on_sale_display || d.no_active_sale_message || '—');
    $root.find('.sutore-mp-queue').text((d.queue_position || '—') + ' / ' + (d.queue_total || '—'));
    var payoutText = d.estimated_net_payout != null ? d.estimated_net_payout + ' TL' : '—';
    $root.find('.sutore-mp-payout').text(payoutText);
    if (d.commission_percent != null) {
      $root.find('.sutore-mp-commission').text('%' + d.commission_percent);
    }
    updateRetailPriceDisplay($root, d);
    $root.data('first-place', d.first_place_asking);
    $root.data('queue-position', d.queue_position);
    $root.data('can-win-sale', d.can_win_sale);
    $root.data('merchant-auto-activates', d.merchant_auto_activates !== false);
    $root.data('blocked-by-condition', d.blocked_by_better_condition);
    var showFirstPlace = !!(d.show_first_place_button && d.first_place_asking != null);
    $root.find('.sutore-mp-first-place')
      .toggleClass('is-hidden', !showFirstPlace)
      .prop('hidden', !showFirstPlace);
    applyShippingOptions($root, d);
    if (d.listing_price_step) {
      $root.find('.sutore-mp-asking').attr('step', d.listing_price_step).attr('min', d.listing_price_step);
    }
    if (d.parent_thumbnail || d.parent_title || (d.listing && d.listing.thumbnail)) {
      setSelectedProduct($root, {
        title: d.parent_title || (d.listing && d.listing.parent_title),
        product_code: d.listing && d.listing.product_code,
        thumbnail: d.parent_thumbnail || (d.listing && d.listing.thumbnail),
        permalink: d.permalink || '',
        has_retail_price: d.has_retail_price,
        retail_price_usd: d.retail_price_usd,
        retail_price_tl: d.retail_price_tl,
        has_release_year: d.has_release_year,
        release_year: d.release_year
      });
    }
    validateAsking($root);
    if (!options.skipCompetingPrices) {
      var $listings = $shell($root);
      if (isCreateManageMode($listings)) {
        // Keep inline form list hidden; data is rendered into the size-prices modal.
        $root.find('.sutore-mp-form-section-competing').prop('hidden', true).addClass('is-hidden');
        $listings.data('wizard-form-context', d);
        renderCompetingPrices($root, d);
      } else if (isManageModalOpen($listings)) {
        $root.find('.sutore-mp-competing-prices').prop('hidden', true).addClass('is-hidden');
        if ($listings.data('manage-can-view-prices')) {
          $listings.find('.sutore-mp-manage-panel[data-panel="prices"]')
            .empty()
            .append(renderManagePricesPanel(d));
        }
      } else {
        renderCompetingPrices($root, d);
      }
    }
  }

  function refreshContext($root, options) {
    options = options || {};
    var parentId = $root.find('.sutore-mp-parent-id').val();
    var sizeId = sizeTermId($root);
    if (!parentId || !sizeId) return;

    var ship = shippingPayload($root);
    api('marketplace_listing_form_context', {
      parent_product_id: parentId,
      size_term_id: sizeId,
      conditions: conditions($root),
      asking: $root.find('.sutore-mp-asking').val(),
      listing_id: $root.data('listing-id') || '',
      fast_shipment: ship.fast_shipment,
      has_invoice: ship.has_invoice
    }).done(function (res) {
      if (!res.success) return;
      applyContext($root, res.data, options);
    });
  }

  function loadSizes($root, parentId, selectedSize, then) {
    api('marketplace_listing_sizes', { parent_product_id: parentId }).done(function (res) {
      var items = res.data.items || [];
      var $options = $root.find('.sutore-mp-size-options').empty();
      items.forEach(function (s) {
        var value = String(s.term_id);
        var $input = $('<input/>', {
          type: 'checkbox',
          name: 'listing_size',
          value: value,
          class: 'wc-block-components-checkbox__input sutore-mp-size'
        });
        var $label = $('<label/>');
        var $mark = $('<svg class="wc-block-components-checkbox__mark" aria-hidden="true" viewBox="0 0 24 20"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"></path></svg>');
        $label.append($input, $mark, $('<span class="wc-block-components-checkbox__label"/>').text(s.name));
        $options.append($('<div class="wc-block-components-checkbox"/>').append($label));
      });
      if (selectedSize) {
        $options.find('.sutore-mp-size[value="' + String(selectedSize) + '"]').prop('checked', true);
      } else if (items.length === 1) {
        $options.find('.sutore-mp-size').prop('checked', true);
      }
      if (then) then();
      else refreshContext($root);
    });
  }

  function initFlatForm($root) {
    var listingId = parseInt($root.attr('data-listing-id') || $root.data('listing-id'), 10) || 0;
    $root.find('.sutore-mp-form-section').not('.sutore-mp-form-section-competing')
      .prop('hidden', false).removeClass('is-hidden');
    if (listingId > 0) {
      $root.find('.sutore-mp-form-section-product, .sutore-mp-form-section-size')
        .prop('hidden', true).addClass('is-hidden');
    }
    refreshContext($root);
    validateAsking($root);
  }

  function validateFlatForm($root) {
    if (!canLeaveStep($root, 1)) {
      return false;
    }
    if (!canLeaveStep($root, 2)) {
      return false;
    }
    if (!canLeaveStep($root, 3)) {
      return false;
    }
    return validateAsking($root, { requireValue: true });
  }

  function setFormLoading($from, loading) {
    var $wrap = $formWrap($from);
    if (!$wrap.length) {
      return;
    }
    $wrap.toggleClass('is-loading', loading);
    $wrap.find('.sutore-mp-listing-form-loading').prop('hidden', !loading);
  }

  function canLeaveStep($root, step) {
    if (step === 1) {
      if (!$root.find('.sutore-mp-parent-id').val()) {
        $root.find('.sutore-mp-search-results').text(t('pickProduct', 'Select a product to continue.'));
        return false;
      }
      return true;
    }
    if (step === 2) {
      if (!sizeTermId($root)) {
        var $sizes = $root.find('.sutore-mp-sizes');
        if (!$sizes.find('.sutore-mp-step-error').length) {
          $sizes.prepend($('<p class="sutore-mp-step-error"/>').text(t('pickSize', 'Select a size to continue.')));
        }
        return false;
      }
      $root.find('.sutore-mp-step-error').remove();
      return true;
    }
    if (step === 3) {
      setShippingError($root, '');
      var ship = shippingPayload($root);
      if (ship.fast_shipment && $root.find('.sutore-mp-shipping-express-flag').prop('disabled')) {
        setShippingError($root, t('expressIneligible', 'You are not eligible for fast shipping.'));
        return false;
      }
      return true;
    }
    return true;
  }

  function clearFormState($root) {
    $root.data('listing-id', 0).attr('data-listing-id', '0');
    $root.find('.sutore-mp-parent-id').val('');
    $root.find('.sutore-mp-product-code').val('').prop('disabled', false);
    $root.find('.sutore-mp-search-results').empty();
    $root.removeData('retail-tl').removeData('retail-usd').removeData('queue-position')
      .removeData('can-win-sale').removeData('merchant-auto-activates').removeData('blocked-by-condition').removeData('first-place');
    updateRetailPriceDisplay($root, { has_retail_price: false, clear_retail: true });
    setPriceAlert($root, null, '');
    setSizeLocked($root, false);
    $root.find('.sutore-mp-size-options').empty();
    $root.find('.sutore-mp-conditions input[type=checkbox]').prop('checked', false);
    $root.find('.sutore-mp-asking').val('');
    $root.find('.sutore-mp-message').text('');
    $root.find('.sutore-mp-shipping-express-flag, .sutore-mp-shipping-intl-flag').prop('checked', false);
    $root.find('.sutore-mp-international-commit').prop('hidden', true);
    $root.find('.sutore-mp-express-ineligible').prop('hidden', true).addClass('is-hidden');
    $root.find('.sutore-mp-shipping-express').removeClass('is-disabled');
    $root.find('.sutore-mp-shipping-express-flag').prop('disabled', false);
    setShippingError($root, '');
    $root.find('.sutore-mp-competing-prices').prop('hidden', true).addClass('is-hidden');
    $root.find('.sutore-mp-competing-prices-table tbody').empty();
    $root.find('.sutore-mp-competing-prices-table-wrap').prop('hidden', true);
    $root.find('.sutore-mp-competing-prices-locked, .sutore-mp-competing-prices-empty').prop('hidden', true);
    $root.find('.sutore-mp-first-place').addClass('is-hidden').prop('hidden', true);
  }

  function setPageWizardTitle($listings, text) {
    if (!isPageMode($listings)) {
      return;
    }
    $listings.find('.sutore-mp-listings-header h2').first().text(text);
  }

  function resetForm($root) {
    clearFormState($root);
    $formWrap($root).find('.sutore-mp-submit').text(t('submit', 'Submit')).prop('hidden', false).removeClass('is-hidden');
    $formWrap($root).find('.sutore-mp-delete, .sutore-mp-remove-from-sale').prop('hidden', true).addClass('is-hidden');
    var $listings = $shell($root);
    if (isPageMode($listings)) {
      setPageWizardTitle($listings, t('addTitle', 'Add Product'));
    }
    initFlatForm($root);
  }

  function openWizard($listings, listingId) {
    if (listingId) {
      if (hasManageModal($listings)) {
        openManageModal($listings, listingId);
        return;
      }
      window.location.href = manageUrl(listingId);
      return;
    }

    if (hasManageModal($listings)) {
      openCreateModal($listings);
      return;
    }

    if (!isPageMode($listings)) {
      window.location.href = manageUrl(0);
      return;
    }

    var $root = $listings.find('.sutore-mp-listing-form-wrap');

    if (editReq && editReq.abort) {
      editReq.abort();
      editReq = null;
    }

    setFormLoading($root, false);
    resetForm($root);
  }

  function closeWizard($listings) {
    if (editReq && editReq.abort) {
      editReq.abort();
      editReq = null;
    }
    if (isPageMode($listings)) {
      goToListings();
      return;
    }
    if (isManageModalOpen($listings)) {
      closeManageModal($listings);
      return;
    }
    if (searchReq && searchReq.abort) searchReq.abort();
  }

  function listLoadingHtml() {
    return (
      '<div class="sutore-mp-list-loading" role="status" aria-live="polite">' +
      '<span class="sutore-mp-list-spinner" aria-hidden="true"></span>' +
      '<span class="screen-reader-text">' +
      $('<div/>').text(t('loading', 'Loading…')).html() +
      '</span></div>'
    );
  }

  function isListingLocked(item) {
    if (!item) {
      return true;
    }
    if (typeof item.can_edit === 'boolean') {
      return !item.can_edit;
    }
    if (item.is_sourcing) {
      return true;
    }
    var status = String(item.listing_status || '');
    if (isEditLockedStatus(status)) {
      return true;
    }
    return !!item.fulfillment;
  }

  function applyFormCapabilities($root, item) {
    var canEdit = !!(item && item.can_edit);
    var canDelete = !!(item && item.can_delete);
    var canRemove = !!(item && item.can_remove_from_sale);
    var listingId = item ? parseInt(item.id, 10) || 0 : 0;
    var inManageModal = $root.closest('.sutore-mp-manage-modal').length > 0;

    var $submit = $formWrap($root).find('.sutore-mp-submit');
    var $delete = $formWrap($root).find('.sutore-mp-delete');
    var $remove = $formWrap($root).find('.sutore-mp-remove-from-sale');

    $root.data('listing-item', item || null);

    if (canEdit) {
      $submit.prop('hidden', false).removeClass('is-hidden');
    } else {
      $submit.prop('hidden', true).addClass('is-hidden');
    }

    applyAskingCampaignLimits($root, item);

    // List page owns delete / remove-from-sale; never show them in the manage modal.
    if (inManageModal || !(canDelete && listingId > 0)) {
      $delete.prop('hidden', true).addClass('is-hidden');
    } else {
      $delete.attr('data-listing-id', String(listingId)).prop('hidden', false).removeClass('is-hidden');
    }

    if (inManageModal || !(canRemove && listingId > 0)) {
      $remove.prop('hidden', true).addClass('is-hidden');
    } else {
      $remove.attr('data-listing-id', String(listingId)).prop('hidden', false).removeClass('is-hidden');
    }

    if (!canEdit && item && item.campaign_status === 'offer') {
      setPriceAlert(
        $root,
        'warn',
        t('campaignOfferBlocksEdit', 'Respond to the campaign offer before updating this listing.')
      );
    } else if (canEdit && item && item.can_increase_asking === false) {
      setPriceAlert(
        $root,
        'warn',
        t('campaignAskingRaiseBlocked', 'This listing is in a campaign, so you cannot increase the asking price.')
      );
    }
  }

  function applyAskingCampaignLimits($root, item) {
    var $asking = $root.find('.sutore-mp-asking');
    if (!$asking.length) {
      return;
    }
    if (item && item.can_increase_asking === false && item.max_asking != null) {
      $asking.attr('max', String(item.max_asking));
      $root.data('max-asking', Number(item.max_asking));
    } else {
      $asking.removeAttr('max');
      $root.removeData('max-asking');
    }
  }

  function escHtml(str) {
    return $('<div/>').text(str == null ? '' : String(str)).html();
  }

  function formatListingPriceHtml(item) {
    // Merchants only see their asking — never customer (storefront) price.
    return formatAskingPriceHtml(item);
  }

  function formatAskingPriceHtml(item) {
    var camp = item && item.campaign ? item.campaign : {};
    var before = camp.asking_before != null ? Number(camp.asking_before) : NaN;
    var asking = item && item.asking != null ? Number(item.asking) : NaN;
    if (
      item &&
      item.campaign_status === 'active' &&
      Number.isFinite(before) &&
      Number.isFinite(asking) &&
      before > asking
    ) {
      return (
        '<span class="sutore-mp-price is-sale">' +
        '<del>' + escHtml(String(camp.asking_before)) + ' TL</del> ' +
        '<ins>' + escHtml(String(item.asking)) + ' TL</ins>' +
        '</span>'
      );
    }
    return '<span class="sutore-mp-price">' + escHtml(String(item && item.asking != null ? item.asking : '—') + ' TL') + '</span>';
  }

  function dash(val) {
    var s = val == null ? '' : String(val).trim();
    return s !== '' ? s : '—';
  }

  function listingStatusLabel(item) {
    return statusLabel(item || {});
  }

  function renderManageActions(item, scope) {
    scope = scope || 'all';
    var listingId = parseInt(item.id, 10) || 0;
    var fulfillment = item.fulfillment || null;
    var fulfillmentId = fulfillment ? parseInt(fulfillment.id, 10) || 0 : 0;
    var includeSale = scope === 'all' || scope === 'sale';
    var html = '';

    if (includeSale && fulfillment && fulfillment.can_confirm && fulfillmentId > 0) {
      html +=
        '<button type="button" class="wp-element-button is-style-outline sutore-mp-ful-confirm" data-listing-id="' +
        fulfillmentId +
        '">' +
        escHtml(t('confirmSale', 'Confirm Sale')) +
        '</button>';
    }
    if (includeSale && fulfillment && fulfillment.can_ship && fulfillmentId > 0) {
      html +=
        '<button type="button" class="wp-element-button is-style-outline sutore-mp-ful-ship" data-listing-id="' +
        fulfillmentId +
        '">' +
        escHtml(t('ship', 'Ship to Sutore')) +
        '</button>';
    }
    return html;
  }

  function renderActivityTimeline(activity) {
    if (!activity || !activity.length) {
      return '<p class="sutore-mp-empty">' + escHtml(t('noActivity', 'No activity recorded yet.')) + '</p>';
    }

    var rows = activity.map(function (event) {
      return (
        '<tr>' +
        '<td class="sutore-mp-manage-activity-date">' + escHtml(event.date || '') + '</td>' +
        '<td><strong>' + escHtml(event.event_label || '') + '</strong></td>' +
        '<td>' + escHtml(event.actor || '—') + '</td>' +
        '<td class="sutore-mp-manage-activity-details">' + escHtml(event.summary || '—') + '</td>' +
        '</tr>'
      );
    }).join('');

    return (
      '<div class="sutore-mp-manage-activity-wrap">' +
      '<table class="sutore-mp-manage-activity-table">' +
      '<thead><tr>' +
      '<th>' + escHtml(t('date', 'Date')) + '</th>' +
      '<th>' + escHtml(t('event', 'Event')) + '</th>' +
      '<th>' + escHtml(t('actor', 'Actor')) + '</th>' +
      '<th>' + escHtml(t('details', 'Details')) + '</th>' +
      '</tr></thead><tbody>' +
      rows +
      '</tbody></table></div>'
    );
  }

  function manageKvRow(label, valueHtml) {
    return (
      '<div class="sutore-mp-manage-kv__row">' +
      '<dt>' +
      escHtml(label) +
      '</dt><dd>' +
      valueHtml +
      '</dd></div>'
    );
  }

  function manageMetaCell(label, valueHtml) {
    return (
      '<div><dt>' +
      escHtml(label) +
      '</dt><dd>' +
      valueHtml +
      '</dd></div>'
    );
  }

  function managePhase(item) {
    var status = String((item && item.listing_status) || '');
    if ((item && item.fulfillment) || isSaleLifecycleStatus(status)) {
      return item && item.fulfillment ? 'fulfillment' : 'order_locked';
    }
    if (item && item.is_sourcing) {
      return 'order_locked';
    }
    if (['publish', 'queued', 'pending'].indexOf(status) !== -1) {
      return 'on_sale';
    }
    return 'off_sale';
  }

  function trackLinkHtml(code, url) {
    if (!code) {
      return '';
    }
    var href = url || ('https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' + encodeURIComponent(code));
    return (
      '<a href="' +
      escHtml(href) +
      '" target="_blank" rel="noopener noreferrer">' +
      escHtml(code) +
      '</a>'
    );
  }

  function deadlineWithRemaining(dateLabel, remainingLabel) {
    var parts = [];
    if (dateLabel) {
      parts.push(String(dateLabel));
    }
    if (remainingLabel) {
      parts.push(String(remainingLabel));
    }
    return parts.join(' · ');
  }

  function renderManageOverview(item, formContext, options) {
    options = options || {};
    var includeEditableFacts = options.includeEditableFacts !== false;
    var fc = formContext || {};
    var listingMeta = fc.listing && typeof fc.listing === 'object' ? fc.listing : {};
    var overviewItem = Object.assign({}, item, {
      conditions: item.conditions || listingMeta.conditions || {},
      fast_shipment: item.fast_shipment != null ? item.fast_shipment : listingMeta.fast_shipment,
      has_invoice: item.has_invoice != null ? item.has_invoice : listingMeta.has_invoice
    });
    var phase = managePhase(item);
    var inMarket = phase === 'on_sale';
    var fulfillment = item.fulfillment || null;
    var payout = fulfillment && fulfillment.payout ? fulfillment.payout : null;
    var cells = '';

    if (item.asking != null && item.asking !== '') {
      cells += manageMetaCell(t('price', 'Price'), formatAskingPriceHtml(item));
    }

    if (inMarket && fc.has_retail_price && fc.retail_price_tl != null && fc.retail_price_tl !== '') {
      var startLabel = Number(fc.retail_price_tl).toLocaleString('tr-TR') + ' TL';
      if (fc.retail_price_usd != null && fc.retail_price_usd !== '') {
        startLabel +=
          ' (≈ $' +
          Number(fc.retail_price_usd).toLocaleString('en-US', { maximumFractionDigits: 2 }) +
          ')';
      }
      cells += manageMetaCell(t('startingPrice', 'Starting price'), escHtml(startLabel));
    }

    cells += manageMetaCell(
      t('listingSource', 'Listing source'),
      escHtml(
        item.is_sourcing
          ? t('preOrderProduct', 'Pre-order')
          : t('regularProduct', 'Regular product')
      )
    );

    if (includeEditableFacts) {
      var conditionLabels = conditionTags(overviewItem).map(function (tag) { return tag.label; });
      cells += manageMetaCell(
        t('condition', 'Condition'),
        escHtml(conditionLabels.length ? conditionLabels.join(', ') : t('conditionNone', 'No defects'))
      );

      var shipLabels = shippingTags(overviewItem).map(function (tag) { return tag.label; });
      cells += manageMetaCell(
        t('shippingOptions', 'Shipping'),
        escHtml(shipLabels.length ? shipLabels.join(', ') : t('shippingStandard', 'Standard'))
      );
    }

    if (inMarket && item.is_winner && item.listing_status === 'publish') {
      cells += manageMetaCell(
        t('salePosition', 'Sale position'),
        escHtml(t('currentlyFirstForSale', 'Currently #1 for sale'))
      );
    }

    if (item.campaign_status_label || (item.campaign && item.campaign.campaign_id)) {
      var camp = item.campaign || {};
      var campaignHtml = escHtml(camp.status_label || item.campaign_status_label || '');
      if (camp.name) {
        campaignHtml = '<strong>' + escHtml(camp.name) + '</strong> · ' + campaignHtml;
      }
      if (item.campaign_status === 'offer') {
        var offersUrl = (window.SutoreMarketplace && SutoreMarketplace.campaignOffersUrl) || '';
        var offerId = camp.offer_id ? parseInt(camp.offer_id, 10) : 0;
        if (offersUrl) {
          var href = offersUrl;
          if (offerId > 0) {
            href += (offersUrl.indexOf('?') >= 0 ? '&' : '?') + 'offer=' + offerId;
          }
          campaignHtml += ' · <a href="' + escHtml(href) + '">' + escHtml(t('reviewCampaignOffer', 'Review offer')) + '</a>';
        }
      }
      cells += manageMetaCell(t('campaign', 'Campaign'), campaignHtml);
      if (camp.ends_at_label || camp.ends_at) {
        cells += manageMetaCell(
          t('campaignEndsAt', 'Campaign ends'),
          escHtml(camp.ends_at_label || camp.ends_at)
        );
      }
      if (camp.starts_at_label || camp.starts_at) {
        cells += manageMetaCell(
          t('campaignStartsAt', 'Campaign starts'),
          escHtml(camp.starts_at_label || camp.starts_at)
        );
      }
      if (camp.seller_discount != null || camp.seller_discount_label) {
        cells += manageMetaCell(
          t('campaignSellerDiscount', 'Your discount'),
          escHtml(camp.seller_discount_label || String(camp.seller_discount) + ' TL')
        );
      }
      if (camp.platform_discount != null || camp.platform_discount_label) {
        cells += manageMetaCell(
          t('campaignPlatformDiscount', 'Platform contribution'),
          escHtml(camp.platform_discount_label || String(camp.platform_discount) + ' TL')
        );
      }
    }

    if (inMarket) {
      var lowestDisplay = fc.min_on_sale_display || fc.no_active_sale_message || '';
      if (lowestDisplay) {
        cells += manageMetaCell(t('lowestPrice', 'Lowest Price'), escHtml(String(lowestDisplay)));
      }
      if (fc.queue_position != null || fc.queue_total != null) {
        cells += manageMetaCell(
          t('currentQueue', 'Current queue'),
          escHtml(String(fc.queue_position || '—') + ' / ' + String(fc.queue_total || '—'))
        );
      }
      if (item.remaining_label) {
        cells += manageMetaCell(t('timeLeft', 'Time remaining'), escHtml(item.remaining_label));
      }
    }

    if (payout) {
      cells += manageMetaCell(
        t('commission', 'Commission'),
        escHtml('%' + payout.commission_percent)
      );
      cells += manageMetaCell(
        t('netEarnings', 'Your net earnings'),
        escHtml(payout.net_amount_display || (String(payout.net_amount) + ' TL'))
      );
      cells += manageMetaCell(
        t('payoutStatus', 'Payout status'),
        escHtml(payout.payout_status_label || payout.payout_status || '—')
      );
      if (payout.paid_at) {
        cells += manageMetaCell(t('payoutPaidAt', 'Paid at'), escHtml(payout.paid_at));
      }
    } else if (fc.commission_percent != null || fc.estimated_net_payout != null) {
      if (fc.commission_percent != null) {
        cells += manageMetaCell(
          t('commission', 'Commission'),
          escHtml('%' + fc.commission_percent)
        );
      }
      if (fc.estimated_net_payout != null) {
        cells += manageMetaCell(
          t('netEarnings', 'Your net earnings'),
          escHtml(String(fc.estimated_net_payout) + ' TL')
        );
      }
    }

    if ((phase === 'fulfillment' || phase === 'order_locked') && item.sold_at_label) {
      cells += manageMetaCell(t('soldAt', 'Sold at'), escHtml(item.sold_at_label));
    }

    if (fulfillment) {
      cells += manageMetaCell(
        t('listingStatus', 'Status'),
        escHtml(dash(fulfillment.status_label || item.listing_status_label))
      );

      if (fulfillment.confirm_deadline_at) {
        cells += manageMetaCell(
          t('confirmDeadline', 'Confirmation deadline'),
          escHtml(deadlineWithRemaining(
            fulfillment.confirm_deadline_at,
            fulfillment.confirm_remaining_label
          ))
        );
      }
      if (fulfillment.seller_confirmed_at) {
        cells += manageMetaCell(
          t('sellerConfirmedAt', 'Seller confirmation date'),
          escHtml(fulfillment.seller_confirmed_at)
        );
      }
      if (fulfillment.cargo_deadline_at) {
        cells += manageMetaCell(
          t('shipDeadline', 'Shipping deadline'),
          escHtml(deadlineWithRemaining(
            fulfillment.cargo_deadline_at,
            fulfillment.cargo_remaining_label
          ))
        );
      }
      if (fulfillment.cargo_expired) {
        cells += manageMetaCell(
          t('cargoExpiredTitle', 'Shipping alert'),
          escHtml(t(
            'cargoExpiredHint',
            'The shipping deadline has passed. Contact Sutore to avoid suspension.'
          ))
        );
      }
      if (fulfillment.merchant_shipped_at) {
        cells += manageMetaCell(
          t('sellerShippedAt', 'Seller shipping date'),
          escHtml(fulfillment.merchant_shipped_at)
        );
      }
      if (fulfillment.merchant_shipment_code) {
        cells += manageMetaCell(
          t('merchantTrackingNumber', 'Tracking to Sutore'),
          trackLinkHtml(fulfillment.merchant_shipment_code, fulfillment.merchant_track_url)
        );
      }
      if (fulfillment.sutore_shipment_code) {
        cells += manageMetaCell(
          t('sutoreTrackingNumber', 'Tracking to customer'),
          trackLinkHtml(fulfillment.sutore_shipment_code, fulfillment.sutore_track_url)
        );
      }
      if (fulfillment.delivered_at) {
        cells += manageMetaCell(
          t('deliveredAt', 'Delivered to customer'),
          escHtml(fulfillment.delivered_at)
        );
      }
      if (fulfillment.return_window_ends_at) {
        cells += manageMetaCell(
          t('returnWindowEnds', 'Return / dispute window ends'),
          escHtml(fulfillment.return_window_ends_at)
        );
      }
      if (fulfillment.status === 'chargeback') {
        cells += manageMetaCell(
          t('saleRefunded', 'Sale refunded'),
          escHtml(t('saleRefundedHint', 'This sale was refunded.'))
        );
      }
    }

    var html = cells
      ? '<dl class="sutore-mp-staff-meta sutore-mp-form-context-meta sutore-mp-manage-summary-meta">' +
        cells +
        '</dl>'
      : '<p class="sutore-mp-empty">' + escHtml(t('noDetails', 'No details available.')) + '</p>';

    var actions = renderManageActions(item, 'sale');
    if (actions) {
      html += '<div class="sutore-mp-manage-panel-actions">' + actions + '</div>';
    }
    return html;
  }

  function updateManageFoot($listings, tab) {
    var $foot = $listings.find('.sutore-mp-manage-modal__foot');
    if (isCreateManageMode($listings)) {
      updateCreateWizardFoot($listings);
      return;
    }

    var item = $listings.data('manage-item');
    if (!item) {
      $foot.prop('hidden', true).empty();
      return;
    }

    var canUpdate = tab === 'details' && $listings.data('manage-can-edit') !== false;
    var html =
      '<button type="button" class="wp-element-button is-style-outline sutore-mp-manage-close">' +
      escHtml(t('close', 'Close')) +
      '</button>';
    if (canUpdate) {
      html +=
        '<button type="button" class="wp-element-button sutore-mp-manage-update">' +
        escHtml(t('update', 'Update')) +
        '</button>';
    }
    $foot.html(html).prop('hidden', false);
  }

  var CREATE_WIZARD_TOTAL = 4;
  var CREATE_WIZARD_SECTIONS = {
    1: ['product'],
    2: ['size'],
    3: ['condition', 'shipping'],
    4: ['price']
  };

  function createWizardStep($listings) {
    return Math.min(
      CREATE_WIZARD_TOTAL,
      Math.max(1, parseInt($listings.data('create-wizard-step'), 10) || 1)
    );
  }

  function createWizardStepTitle(step) {
    if (step === 1) {
      return t('wizardStepProduct', 'Product');
    }
    if (step === 2) {
      return t('wizardStepSize', 'Size');
    }
    if (step === 3) {
      return t('wizardStepDetails', 'Details');
    }
    return t('wizardStepPrice', 'Price');
  }

  function updateCreateWizardChrome($listings, step) {
    var $nav = $listings.find('.sutore-mp-create-wizard-steps');
    $nav.prop('hidden', false);
    $nav.find('.sutore-mp-create-wizard-step').each(function () {
      var n = parseInt($(this).attr('data-step'), 10) || 0;
      $(this)
        .toggleClass('is-current', n === step)
        .toggleClass('is-done', n < step)
        .attr('aria-current', n === step ? 'step' : null);
    });
    $listings.find('.sutore-mp-manage-modal__title').text(t('addTitle', 'Add Product'));
    $listings.find('.sutore-mp-manage-modal__sub').text(
      (t('wizardStepOf', 'Step %1$d of %2$d'))
        .replace('%1$d', String(step))
        .replace('%2$d', String(CREATE_WIZARD_TOTAL)) +
        ' · ' +
        createWizardStepTitle(step)
    );
  }

  function showCreateWizardSections($formRoot, step) {
    var allowed = CREATE_WIZARD_SECTIONS[step] || [];
    $formRoot.find('.sutore-mp-form-section').each(function () {
      var name = $(this).attr('data-section') || '';
      if (name === 'competing-prices') {
        $(this).prop('hidden', true).addClass('is-hidden');
        return;
      }
      var show = allowed.indexOf(name) !== -1;
      $(this).prop('hidden', !show).toggleClass('is-hidden', !show);
    });
  }

  function createSuccessCopy(data) {
    data = data || {};
    var status = String(data.listing_status || '');
    if (status === 'publish') {
      return t('createSuccessForSale', 'Your listing is now for sale.');
    }
    if (status === 'queued') {
      return t('createSuccessQueued', 'Your listing was added to the queue for this size.');
    }
    return t(
      'createSuccessPending',
      'Your listing was submitted and will go on sale after approval.'
    );
  }

  function showCreateSuccess($listings, data) {
    $listings.data('create-wizard-done', 1);
    $listings.find('.sutore-mp-manage-modal').attr('data-create-wizard-step', 'done');
    $listings.find('.sutore-mp-create-wizard-steps').prop('hidden', true);
    $listings.find('.sutore-mp-wizard-context').prop('hidden', true);
    $listings.find('.sutore-mp-manage-modal__loading').prop('hidden', true);
    $listings.find('.sutore-mp-manage-panel').prop('hidden', true);
    $listings.find('.sutore-mp-manage-modal__tabs').prop('hidden', true);
    $listings.find('.sutore-mp-manage-modal__title').text(t('createSuccessTitle', 'Listing added'));
    $listings.find('.sutore-mp-manage-modal__sub').text('');
    $listings.find('.sutore-mp-manage-modal__media').empty().prop('hidden', true);
    $listings.find('.sutore-mp-manage-modal__badge').prop('hidden', true);

    var $success = $listings.find('.sutore-mp-create-success');
    $success.find('.sutore-mp-create-success__text').text(createSuccessCopy(data));
    $success.prop('hidden', false);

    $listings.find('.sutore-mp-manage-modal__body').attr('aria-busy', 'false');
    $listings
      .find('.sutore-mp-manage-modal__foot')
      .html(
        '<button type="button" class="wp-element-button sutore-mp-manage-close">' +
          escHtml(t('close', 'Close')) +
          '</button>'
      )
      .prop('hidden', false);
  }

  function resetCreateSuccess($listings) {
    $listings.removeData('create-wizard-done');
    $listings.find('.sutore-mp-create-success').prop('hidden', true);
    $listings.find('.sutore-mp-create-success__text').text('');
  }

  function updateCreateWizardFoot($listings) {
    var step = createWizardStep($listings);
    var $foot = $listings.find('.sutore-mp-manage-modal__foot');
    var html = '';
    if (step > 1) {
      html +=
        '<button type="button" class="wp-element-button is-style-outline sutore-mp-create-wizard-back">' +
        escHtml(t('previous', 'Previous')) +
        '</button>';
    } else {
      html +=
        '<button type="button" class="wp-element-button is-style-outline sutore-mp-manage-close">' +
        escHtml(t('close', 'Close')) +
        '</button>';
    }
    if (step < CREATE_WIZARD_TOTAL) {
      html +=
        '<button type="button" class="wp-element-button sutore-mp-create-wizard-next">' +
        escHtml(t('next', 'Next')) +
        '</button>';
    } else {
      html +=
        '<button type="button" class="wp-element-button sutore-mp-manage-update">' +
        escHtml(t('submit', 'Submit')) +
        '</button>';
    }
    $foot.html(html).prop('hidden', false);
  }

  function setCreateWizardStep($listings, step) {
    step = Math.min(CREATE_WIZARD_TOTAL, Math.max(1, parseInt(step, 10) || 1));
    $listings.data('create-wizard-step', step);
    $listings.find('.sutore-mp-manage-modal').attr('data-create-wizard-step', String(step));

    var $formRoot = $listings.find('.sutore-mp-manage-panel[data-panel="details"] .sutore-mp-listing-form-wrap');
    updateCreateWizardChrome($listings, step);
    showCreateWizardSections($formRoot, step);
    updateCreateWizardFoot($listings);
    updateWizardContext($listings);

    if (step === 4 && $formRoot.length) {
      // Ensure size/parent are still readable from hidden prior steps, then load price meta + list.
      refreshContext($formRoot, { skipCompetingPrices: false });
    }

    var $body = $listings.find('.sutore-mp-manage-modal__body');
    if ($body.length && $body[0].scrollTop !== undefined) {
      $body.scrollTop(0);
    }
  }

  function canLeaveCreateWizardStep($listings, step) {
    var $formRoot = $listings.find('.sutore-mp-manage-panel[data-panel="details"] .sutore-mp-listing-form-wrap');
    if (!$formRoot.length) {
      return false;
    }
    if (step === 1 || step === 2 || step === 3) {
      return canLeaveStep($formRoot, step);
    }
    return true;
  }

  function setManageTab($listings, tab) {
    if (isCreateManageMode($listings)) {
      $listings.find('.sutore-mp-manage-tab').attr('aria-selected', 'false');
      $listings.find('.sutore-mp-manage-panel').each(function () {
        $(this).prop('hidden', $(this).attr('data-panel') !== 'details');
      });
      $listings.find('.sutore-mp-manage-edit').prop('hidden', false);
      $listings.find('.sutore-mp-manage-summary').prop('hidden', true);
      setCreateWizardStep($listings, createWizardStep($listings));
      return;
    }

    $listings.find('.sutore-mp-create-wizard-steps').prop('hidden', true);
    $listings.find('.sutore-mp-manage-modal').removeAttr('data-create-wizard-step');
    $listings.removeData('create-wizard-step');

    var canEdit = $listings.data('manage-can-edit') !== false;
    var canViewPrices = !!$listings.data('manage-can-view-prices');
    if (tab === 'sale') {
      tab = 'details';
    }
    if (tab === 'prices' && !canViewPrices) {
      tab = 'details';
    }
    if (tab === 'overview' || tab === 'edit' || tab === 'condition' || tab === 'shipping' || tab === 'pricing' || tab === 'form') {
      tab = 'details';
    }

    $listings.find('.sutore-mp-manage-tab').each(function () {
      var name = $(this).attr('data-tab');
      var selected = name === tab;
      $(this).attr('aria-selected', selected ? 'true' : 'false');
    });

    $listings.find('.sutore-mp-manage-panel').each(function () {
      var name = $(this).attr('data-panel');
      $(this).prop('hidden', name !== tab);
    });

    var $edit = $listings.find('.sutore-mp-manage-edit');
    if ($edit.length) {
      $edit.prop('hidden', !(canEdit && tab === 'details'));
    }
    $listings.find('.sutore-mp-manage-summary').prop('hidden', false);

    updateManageFoot($listings, tab);
  }

  function setWizardProduct($listings, data) {
    data = data || {};
    $listings.data('wizard-product', {
      title: data.title || '',
      product_code: data.product_code || data.code || '',
      thumbnail: data.thumbnail || data.thumb || '',
      permalink: data.permalink || ''
    });
    updateWizardContext($listings);
  }

  function clearWizardProduct($listings) {
    $listings.removeData('wizard-product');
    updateWizardContext($listings);
  }

  function wizardSelectedSizeLabel($listings) {
    var $formRoot = $listings.find('.sutore-mp-manage-panel[data-panel="details"] .sutore-mp-listing-form-wrap');
    if (!$formRoot.length) {
      return '';
    }
    var locked = $formRoot.data('locked-size-label');
    if (locked) {
      return String(locked);
    }
    var $checked = $formRoot.find('.sutore-mp-size:checked');
    if (!$checked.length) {
      return '';
    }
    return $.trim($checked.closest('label').find('.wc-block-components-checkbox__label').text() || '');
  }

  function wizardAskingPreview($listings) {
    var $formRoot = $listings.find('.sutore-mp-manage-panel[data-panel="details"] .sutore-mp-listing-form-wrap');
    if (!$formRoot.length) {
      return '';
    }
    var raw = $.trim(String($formRoot.find('.sutore-mp-asking').val() || ''));
    if (!raw) {
      return '';
    }
    var n = Number(raw);
    if (!isFinite(n) || n <= 0) {
      return '';
    }
    return n.toLocaleString('tr-TR') + ' TL';
  }

  function updateWizardContext($listings) {
    var $ctx = $listings.find('.sutore-mp-wizard-context');
    if (!$ctx.length) {
      return;
    }
    if (!isCreateManageMode($listings)) {
      $ctx.prop('hidden', true);
      return;
    }
    var product = $listings.data('wizard-product') || {};
    if (!product.title && !product.product_code) {
      $ctx.prop('hidden', true);
      return;
    }

    var title = product.title || product.product_code || '';
    var metaParts = [];
    if (product.product_code) {
      metaParts.push(product.product_code);
    }
    var sizeLabel = wizardSelectedSizeLabel($listings);
    if (sizeLabel) {
      metaParts.push(t('size', 'Size') + ': ' + sizeLabel);
    }

    $ctx.find('.sutore-mp-wizard-context__title').text(title);
    $ctx.find('.sutore-mp-wizard-context__meta').text(metaParts.join(' · '));

    var pricePreview = wizardAskingPreview($listings);
    var $price = $ctx.find('.sutore-mp-wizard-context__price');
    if (pricePreview) {
      $price.text(pricePreview).prop('hidden', false);
    } else {
      $price.text('').prop('hidden', true);
    }

    var $media = $ctx.find('.sutore-mp-wizard-context__media');
    if (product.thumbnail && typeof thumbBox === 'function') {
      $media
        .empty()
        .append(
          thumbBox(
            'sutore-mp-wizard-context__thumb-box',
            'sutore-mp-wizard-context__thumb',
            product.thumbnail,
            title
          )
        )
        .prop('hidden', false);
    } else {
      $media.empty().prop('hidden', true);
    }
    $ctx.prop('hidden', false);
  }

  function setManageHeaderProduct($listings, title, permalink, thumbnail) {
    var $title = $listings.find('.sutore-mp-manage-modal__title');
    var $media = $listings.find('.sutore-mp-manage-modal__media');
    var href = permalink ? String(permalink) : '';

    if (href) {
      $title.empty().append(
        $('<a class="sutore-mp-manage-modal__title-link"/>')
          .attr('href', href)
          .attr('target', '_blank')
          .attr('rel', 'noopener noreferrer')
          .text(title)
      );
    } else {
      $title.text(title);
    }

    if (thumbnail && typeof thumbBox === 'function') {
      var $thumb = thumbBox(
        'sutore-mp-manage-modal__thumb-box',
        'sutore-mp-manage-modal__thumb',
        thumbnail,
        title
      );
      $media.empty();
      if (href) {
        $media.append(
          $('<a class="sutore-mp-manage-modal__media-link"/>')
            .attr('href', href)
            .attr('target', '_blank')
            .attr('rel', 'noopener noreferrer')
            .attr('aria-label', title)
            .append($thumb)
        );
      } else {
        $media.append($thumb);
      }
      $media.prop('hidden', false);
    } else {
      $media.empty().prop('hidden', true);
    }
  }

  function revealManageOverlay($listings) {
    if (!$listings.find('.sutore-mp-filter-overlay').prop('hidden') || !$listings.find('.sutore-mp-sort-overlay').prop('hidden')) {
      SutoreMarketplace.closeListOverlays($listings);
    }
    if (isBulkModalOpen($listings)) {
      closeBulkModal($listings, { skipUrl: true });
    }
    var $overlay = $listings.find('.sutore-mp-manage-overlay');
    $overlay
      .removeClass('is-closing')
      .prop('hidden', false);
    // Force reflow so the open transition runs from the closed scale state.
    void $overlay[0].offsetWidth;
    $overlay.addClass('is-open');
    $('body').addClass('sutore-mp-modal-open');
  }

  function revealBulkOverlay($listings) {
    if (!$listings.find('.sutore-mp-filter-overlay').prop('hidden') || !$listings.find('.sutore-mp-sort-overlay').prop('hidden')) {
      SutoreMarketplace.closeListOverlays($listings);
    }
    if (isManageModalOpen($listings)) {
      closeManageModal($listings, { skipUrl: true });
    }
    closeSizePricesModal($listings);
    var $overlay = $listings.find('.sutore-mp-bulk-overlay');
    $overlay
      .removeClass('is-closing')
      .prop('hidden', false);
    void $overlay[0].offsetWidth;
    $overlay.addClass('is-open');
    $('body').addClass('sutore-mp-modal-open');
  }

  function openBulkModal($listings, options) {
    options = options || {};
    if (!hasBulkModal($listings)) {
      return;
    }
    revealBulkOverlay($listings);
    if (!options.skipUrl) {
      syncManageUrl(0, !!options.replaceUrl, 'bulk');
    }
    $listings.find('.sutore-mp-listing-bulk').trigger('sutore-mp-bulk:open');
  }

  function finishCloseBulkModal($listings, options) {
    options = options || {};
    var $overlay = $listings.find('.sutore-mp-bulk-overlay');
    $overlay
      .prop('hidden', true)
      .removeClass('is-open is-closing')
      .off('transitionend.sutoreBulkClose');
    if (
      !$listings.find('.sutore-mp-manage-overlay').hasClass('is-open') &&
      !$listings.find('.sutore-mp-size-prices-overlay').hasClass('is-open') &&
      !$listings.find('.sutore-mp-filter-overlay:not([hidden])').length &&
      !$listings.find('.sutore-mp-sort-overlay:not([hidden])').length
    ) {
      $('body').removeClass('sutore-mp-modal-open');
    }
    $listings.find('.sutore-mp-listing-bulk').trigger('sutore-mp-bulk:reset');
    $listings.find('.sutore-mp-bulk-modal').attr('data-bulk-wizard-step', '1');
    $listings.find('.sutore-mp-bulk-wizard-steps').prop('hidden', false);
    $listings.find('.sutore-mp-bulk-modal__foot').prop('hidden', true).empty();
    $listings.find('.sutore-mp-bulk-modal__sub').text('');
    if (!options.skipUrl) {
      syncManageUrl(0, !!options.replaceUrl);
    }
  }

  function closeBulkModal($listings, options) {
    options = options || {};
    var $overlay = $listings.find('.sutore-mp-bulk-overlay');
    if (!$overlay.length || $overlay.prop('hidden')) {
      return;
    }
    if ($overlay.hasClass('is-closing')) {
      return;
    }

    var reduceMotion = false;
    try {
      reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (err) {
      reduceMotion = false;
    }

    if (reduceMotion || !$overlay.hasClass('is-open')) {
      finishCloseBulkModal($listings, options);
      return;
    }

    $overlay.removeClass('is-open').addClass('is-closing');
    var done = false;
    var finish = function () {
      if (done) {
        return;
      }
      done = true;
      finishCloseBulkModal($listings, options);
    };
    $overlay.one('transitionend.sutoreBulkClose', finish);
    window.setTimeout(finish, 320);
  }

  function openCreateModal($listings, options) {
    options = options || {};
    if (!hasManageModal($listings)) {
      return;
    }

    revealManageOverlay($listings);
    if (!options.skipUrl) {
      syncManageUrl(0, !!options.replaceUrl, 'create');
    }

    $listings
      .data('manage-mode', 'create')
      .data('manage-listing-id', 0)
      .data('manage-can-edit', true)
      .data('manage-can-view-prices', false)
      .data('manage-has-sale', false)
      .data('create-wizard-step', 1)
      .removeData('manage-item');
    clearWizardProduct($listings);
    resetCreateSuccess($listings);

    var $body = $listings.find('.sutore-mp-manage-modal__body');
    var $loading = $listings.find('.sutore-mp-manage-modal__loading');
    var $formRoot = $listings.find('.sutore-mp-manage-panel[data-panel="details"] .sutore-mp-listing-form-wrap');

    $listings.find('.sutore-mp-manage-modal').attr('data-create-wizard-step', '1');
    $listings.find('.sutore-mp-manage-modal__badge').text('').prop('hidden', true);
    $listings.find('.sutore-mp-manage-modal__media').empty().prop('hidden', true);
    $listings.find('.sutore-mp-manage-modal__tabs').prop('hidden', true);
    $listings.find('.sutore-mp-manage-tab--prices').prop('hidden', true);
    $listings.find('.sutore-mp-manage-summary').empty().prop('hidden', true);
    $listings.find('.sutore-mp-manage-panel[data-panel="prices"]').empty();
    $listings.find('.sutore-mp-manage-panel[data-panel="activity"]').empty();

    $loading.prop('hidden', true);
    $body.attr('aria-busy', 'false');

    if ($formRoot.length) {
      if (editReq && editReq.abort) {
        editReq.abort();
        editReq = null;
      }
      setFormLoading($formRoot, false);
      clearFormState($formRoot);
      $formRoot.find('.sutore-mp-submit').text(t('submit', 'Submit')).prop('hidden', false).removeClass('is-hidden');
      $formRoot.find('.sutore-mp-delete, .sutore-mp-remove-from-sale').prop('hidden', true).addClass('is-hidden');
      $formRoot.find('.sutore-mp-product-code').prop('disabled', false);
    }

    $listings.find('.sutore-mp-manage-panel[data-panel="details"]').prop('hidden', false);
    $listings.find('.sutore-mp-manage-edit').prop('hidden', false);
    setCreateWizardStep($listings, 1);
  }

  function openSizePricesModal($listings) {
    var $overlay = $listings.find('.sutore-mp-size-prices-overlay');
    if (!$overlay.length) {
      return;
    }
    var $formRoot = $listings.find('.sutore-mp-manage-panel[data-panel="details"] .sutore-mp-listing-form-wrap');
    var cached = $listings.data('wizard-form-context');
    if (cached) {
      renderCompetingPrices($formRoot, cached);
    } else if ($formRoot.length) {
      refreshContext($formRoot);
    }
    $overlay
      .removeClass('is-closing')
      .prop('hidden', false);
    void $overlay[0].offsetWidth;
    $overlay.addClass('is-open');
    $('body').addClass('sutore-mp-modal-open');
  }

  function closeSizePricesModal($listings) {
    var $overlay = $listings.find('.sutore-mp-size-prices-overlay');
    if (!$overlay.length || $overlay.prop('hidden')) {
      return;
    }
    $overlay
      .prop('hidden', true)
      .removeClass('is-open is-closing');
    if (
      !$listings.find('.sutore-mp-manage-overlay').hasClass('is-open') &&
      !$listings.find('.sutore-mp-bulk-overlay').hasClass('is-open') &&
      !$listings.find('.sutore-mp-filter-overlay:not([hidden])').length &&
      !$listings.find('.sutore-mp-sort-overlay:not([hidden])').length
    ) {
      $('body').removeClass('sutore-mp-modal-open');
    }
  }

  function openManageModal($listings, listingId, options) {
    options = options || {};
    listingId = parseInt(listingId, 10) || 0;
    if (!listingId || !hasManageModal($listings)) {
      return;
    }
    $listings.data('manage-mode', 'edit');
    revealManageOverlay($listings);
    if (!options.skipUrl) {
      syncManageUrl(listingId, !!options.replaceUrl);
    }
    loadManageModal($listings, listingId);
  }

  function finishCloseManageModal($listings, options) {
    options = options || {};
    var $overlay = $listings.find('.sutore-mp-manage-overlay');
    $overlay
      .prop('hidden', true)
      .removeClass('is-open is-closing')
      .off('transitionend.sutoreManageClose');
    if (
      !$listings.find('.sutore-mp-bulk-overlay').hasClass('is-open') &&
      !$listings.find('.sutore-mp-size-prices-overlay').hasClass('is-open') &&
      !$listings.find('.sutore-mp-filter-overlay:not([hidden])').length &&
      !$listings.find('.sutore-mp-sort-overlay:not([hidden])').length
    ) {
      $('body').removeClass('sutore-mp-modal-open');
    }
    $listings.removeData('manage-listing-id').removeData('manage-can-edit')
      .removeData('manage-has-sale').removeData('manage-can-view-prices').removeData('manage-item')
      .removeData('manage-mode').removeData('create-wizard-step').removeData('create-wizard-done')
      .removeData('wizard-product').removeData('wizard-form-context');
    $listings.find('.sutore-mp-manage-modal').removeAttr('data-create-wizard-step');
    $listings.find('.sutore-mp-create-wizard-steps').prop('hidden', true);
    $listings.find('.sutore-mp-wizard-context').prop('hidden', true);
    resetCreateSuccess($listings);
    closeSizePricesModal($listings);
    $listings.find('.sutore-mp-manage-modal__title').text('');
    $listings.find('.sutore-mp-manage-modal__sub').text('');
    $listings.find('.sutore-mp-manage-modal__media').empty().prop('hidden', true);
    $listings.find('.sutore-mp-manage-modal__badge').text('').prop('hidden', true);
    $listings.find('.sutore-mp-manage-modal__tabs').prop('hidden', true);
    $listings.find('.sutore-mp-manage-tab--prices').prop('hidden', true);
    $listings.find('.sutore-mp-manage-modal__foot').prop('hidden', true).empty();
    $listings.find('.sutore-mp-manage-summary').empty().prop('hidden', false);
    $listings.find('.sutore-mp-manage-edit').prop('hidden', true);
    $listings.find('.sutore-mp-manage-panel[data-panel="prices"]').empty();
    $listings.find('.sutore-mp-manage-panel[data-panel="activity"]').empty();
    $listings.find('.sutore-mp-manage-modal__loading').prop('hidden', false);
    $listings.find('.sutore-mp-manage-panel').prop('hidden', true);
    if (!options.skipUrl) {
      syncManageUrl(0, !!options.replaceUrl);
    }
  }

  function closeManageModal($listings, options) {
    options = options || {};
    var $overlay = $listings.find('.sutore-mp-manage-overlay');
    if (!$overlay.length || $overlay.prop('hidden')) {
      return;
    }
    if ($overlay.hasClass('is-closing')) {
      return;
    }

    var reduceMotion = false;
    try {
      reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (err) {
      reduceMotion = false;
    }

    if (reduceMotion || !$overlay.hasClass('is-open')) {
      finishCloseManageModal($listings, options);
      return;
    }

    $overlay.removeClass('is-open').addClass('is-closing');
    var done = false;
    var finish = function () {
      if (done) {
        return;
      }
      done = true;
      window.clearTimeout(fallback);
      finishCloseManageModal($listings, options);
    };
    var fallback = window.setTimeout(finish, 280);
    $overlay.on('transitionend.sutoreManageClose', function (e) {
      if (e.target !== $overlay[0]) {
        return;
      }
      finish();
    });
  }

  function loadManageModal($listings, listingId) {
    listingId = parseInt(listingId, 10) || 0;
    var $body = $listings.find('.sutore-mp-manage-modal__body');
    var $loading = $listings.find('.sutore-mp-manage-modal__loading');
    var $tabs = $listings.find('.sutore-mp-manage-modal__tabs');
    var $foot = $listings.find('.sutore-mp-manage-modal__foot');
    var $formRoot = $listings.find('.sutore-mp-manage-panel[data-panel="details"] .sutore-mp-listing-form-wrap');
    var $summary = $listings.find('.sutore-mp-manage-summary');
    var $edit = $listings.find('.sutore-mp-manage-edit');

    if (!listingId) {
      return;
    }

    $listings.data('manage-listing-id', listingId);
    $body.attr('aria-busy', 'true');
    $loading.prop('hidden', false);
    $tabs.prop('hidden', true);
    $foot.prop('hidden', true).empty();
    $listings.find('.sutore-mp-manage-panel').prop('hidden', true);
    $edit.prop('hidden', true);

    api('marketplace_listing_get', { listing_id: listingId }).done(function (res) {
      if (parseInt($listings.data('manage-listing-id'), 10) !== listingId) {
        return;
      }
      if (!res || !res.success || !res.data || !res.data.item) {
        var msg = (res && res.data && res.data.message) ? res.data.message : t('listingNotFound', 'Listing not found.');
        if (res && res.message) {
          msg = res.message;
        }
        $loading.prop('hidden', true);
        $body.attr('aria-busy', 'false');
        $summary.html('<p class="sutore-mp-error">' + escHtml(msg) + '</p>');
        $listings.find('.sutore-mp-manage-panel[data-panel="details"]').prop('hidden', false);
        $listings.find('.sutore-mp-manage-modal__title').text(t('manage', 'Manage'));
        $listings.find('.sutore-mp-manage-modal__media').empty().prop('hidden', true);
        return;
      }

      var item = res.data.item;
      var formContext = res.data.form_context || null;
      var title = item.parent_title || (item.variation_id ? '#' + item.variation_id : t('manage', 'Manage'));
      var subParts = [];
      if (item.product_code) {
        subParts.push(item.product_code);
      }
      if (item.variation_id) {
        subParts.push('#' + item.variation_id);
      }

      setManageHeaderProduct($listings, title, item.permalink || '', item.thumbnail || '');
      $listings.find('.sutore-mp-manage-modal__sub').text(subParts.join(' · '));
      $listings.find('.sutore-mp-manage-modal__badge')
        .text(listingStatusLabel(item))
        .prop('hidden', false);

      var hasSale = !!(item.fulfillment);
      var canEdit = !isListingLocked(item);
      var phase = managePhase(item);
      var canViewPrices = !!(formContext && formContext.can_view_competing_prices)
        && phase === 'on_sale';
      $listings.data('manage-item', item);
      $listings.data('manage-has-sale', hasSale);
      $listings.data('manage-can-edit', canEdit);
      $listings.data('manage-can-view-prices', canViewPrices);
      $listings.find('.sutore-mp-manage-tab--prices').prop('hidden', !canViewPrices);

      var summaryHtml = renderManageOverview(item, formContext, { includeEditableFacts: !canEdit });
      $summary.html(summaryHtml);

      var $pricesPanel = $listings.find('.sutore-mp-manage-panel[data-panel="prices"]');
      if (canViewPrices) {
        $pricesPanel.empty().append(renderManagePricesPanel(formContext || {}));
      } else {
        $pricesPanel.empty();
      }

      $listings.find('.sutore-mp-manage-panel[data-panel="activity"]').html(
        renderActivityTimeline(res.data.activity || [])
      );

      if (canEdit && $formRoot.length) {
        clearFormState($formRoot);
        $formRoot.data('listing-id', listingId).attr('data-listing-id', String(listingId));
        $formRoot.find('.sutore-mp-submit').text(t('update', 'Update'));
        applyFormCapabilities($formRoot, item);
        if (formContext) {
          setFormLoading($formRoot, true);
          applyFormContext($formRoot, formContext);
          applyFormCapabilities($formRoot, item);
          setFormLoading($formRoot, false);
        } else {
          initFlatForm($formRoot);
        }
      }

      $loading.prop('hidden', true);
      $tabs.prop('hidden', false);
      $body.attr('aria-busy', 'false');
      setManageTab($listings, 'details');
    }).fail(function (xhr) {
      if (parseInt($listings.data('manage-listing-id'), 10) !== listingId) {
        return;
      }
      var msg = t('listingNotFound', 'Listing not found.');
      if (xhr.responseJSON && xhr.responseJSON.message) {
        msg = xhr.responseJSON.message;
      }
      $loading.prop('hidden', true);
      $body.attr('aria-busy', 'false');
      $summary.html('<p class="sutore-mp-error">' + escHtml(msg) + '</p>');
      $listings.find('.sutore-mp-manage-panel[data-panel="details"]').prop('hidden', false);
      $listings.find('.sutore-mp-manage-modal__title').text(t('manage', 'Manage'));
      $listings.find('.sutore-mp-manage-modal__media').empty().prop('hidden', true);
    });
  }

  function applyFormContext($root, d) {
    var listing = d.listing || {};
    $root.find('.sutore-mp-parent-id').val(listing.parent_product_id || d.parent_product_id);
    $root.find('.sutore-mp-product-code').val(listing.product_code || '').prop('disabled', true);
    $root.find('.sutore-mp-asking').val(listing.asking || d.asking || '');

    setSelectedProduct($root, {
      title: listing.parent_title || d.parent_title,
      product_code: listing.product_code,
      thumbnail: listing.thumbnail || d.parent_thumbnail,
      permalink: d.permalink || '',
      has_retail_price: d.has_retail_price,
      retail_price_usd: d.retail_price_usd,
      retail_price_tl: d.retail_price_tl,
      has_release_year: d.has_release_year,
      release_year: d.release_year
    });

    var conds = listing.conditions || d.conditions || {};
    Object.keys(conds).forEach(function (key) {
      $root.find('input[name="conditions[' + key + ']"]').prop('checked', !!conds[key]);
    });

    setSizeLocked(
      $root,
      true,
      listing.size_term_id || d.size_term_id,
      d.size_label || listing.size_label || ''
    );
    applyShippingOptions($root, d);
    applyContext($root, d);
    initFlatForm($root);
  }

  function hydrateEditForm($root, listingId) {
    editReq = api('marketplace_listing_get', { listing_id: listingId });
    editReq.done(function (res) {
      editReq = null;
      if (parseInt($root.data('listing-id'), 10) !== listingId) {
        return;
      }
      if (!res || !res.success) {
        var msg = (res && res.data && res.data.message) ? res.data.message : t('error', 'Error');
        $root.find('.sutore-mp-message').text(msg);
        setFormLoading($root, false);
        return;
      }
      var d = res.data.form_context || res.data;
      var item = res.data.item || d.listing || null;
      applyFormContext($root, d);
      if (item) {
        applyFormCapabilities($root, item);
      }
      setFormLoading($root, false);
    }).fail(function (xhr, status) {
      if (status === 'abort') {
        return;
      }
      editReq = null;
      if (parseInt($root.data('listing-id'), 10) !== listingId) {
        return;
      }
      $root.find('.sutore-mp-message').text(t('error', 'Error'));
      setFormLoading($root, false);
    });
  }

  function renderSearchResults($root, res) {
    var $box = $root.find('.sutore-mp-search-results').empty();
    if (!res.success || !res.data.items || !res.data.items.length) {
      $box.text((res.data && res.data.message) || t('notFound', 'The product you searched for was not found'));
      return;
    }
    res.data.items.forEach(function (item) {
      var $btn = $('<button type="button" class="sutore-mp-pick-parent sutore-mp-pick-row"/>')
        .attr('data-id', item.id)
        .attr('data-title', item.title || '')
        .attr('data-code', item.product_code || '')
        .attr('data-thumb', item.thumbnail || '')
        .attr('data-permalink', item.permalink || '')
        .attr('data-has-retail', item.has_retail_price ? '1' : '0')
        .attr('data-retail-usd', item.retail_price_usd != null ? item.retail_price_usd : '')
        .attr('data-retail-tl', item.retail_price_tl != null ? item.retail_price_tl : '')
        .attr('data-has-year', item.has_release_year ? '1' : '0')
        .attr('data-year', item.release_year != null ? item.release_year : '');
      if (item.thumbnail) {
        $btn.append(thumbBox('sutore-mp-pick-thumb-box', 'sutore-mp-pick-thumb', item.thumbnail, ''));
      }
      $btn.append($('<span/>').text(item.title + ' (' + item.product_code + ')'));
      $box.append($btn);
    });
  }

  function liveSearch($root, code) {
    code = $.trim(code || '');
    var $spinner = $root.find('.sutore-mp-spinner');
    if (searchReq && searchReq.abort) searchReq.abort();
    if (code.length < 2) {
      $spinner.prop('hidden', true);
      $root.find('.sutore-mp-search-results').empty();
      return;
    }
    $spinner.prop('hidden', false);
    searchReq = api('marketplace_search_parent_products', { product_code: code })
      .done(function (res) {
        renderSearchResults($root, res);
      })
      .always(function () {
        $spinner.prop('hidden', true);
      });
  }

  function submitListing($root) {
    var listingId = parseInt($root.data('listing-id'), 10) || 0;
    var ship = shippingPayload($root);
    var payload = {
      parent_product_id: $root.find('.sutore-mp-parent-id').val(),
      asking: $root.find('.sutore-mp-asking').val(),
      conditions: conditions($root),
      fast_shipment: ship.fast_shipment,
      has_invoice: ship.has_invoice,
      listing_id: listingId
    };
    if (!listingId) {
      payload.size_term_id = sizeTermId($root);
    }
    var action = listingId ? 'marketplace_listing_update' : 'marketplace_listing_create';
    var $listings = $shell($root);
    var $submitBtns = $formWrap($root).find('.sutore-mp-submit').add(
      $listings.find('.sutore-mp-manage-update')
    );
    $submitBtns.prop('disabled', true);
    api(action, payload).done(function (res) {
      $submitBtns.prop('disabled', false);
      if (!res.success) {
        var msg = res.data && res.data.message ? res.data.message : t('error', 'Error');
        if (res.data && res.data.code === 'sutore_marketplace_invalid_price') {
          setPriceAlert($root, 'error', msg);
          $root.find('.sutore-mp-asking').addClass('is-invalid');
        }
        if (isManageModalOpen($listings) && typeof SutoreMarketplace.showToast === 'function') {
          SutoreMarketplace.showToast(msg, 'error');
        } else {
          $root.find('.sutore-mp-message').text(msg);
        }
        return;
      }

      if (isManageModalOpen($listings)) {
        $root.find('.sutore-mp-message').text('');
        if (isCreateManageMode($listings) && !listingId) {
          showCreateSuccess($listings, res.data || {});
          loadListings($listings, $listings.data('page') || 1);
          return;
        }
        var savedMsg = t('savedTitle', 'Listing updated');
        if (typeof SutoreMarketplace.showToast === 'function') {
          SutoreMarketplace.showToast(savedMsg, 'success');
        }
        closeManageModal($listings);
        loadListings($listings, $listings.data('page') || 1);
        return;
      }

      $root.find('.sutore-mp-message').text(
        t('saved', 'Saved') + (res.data.variation_id ? ' #' + res.data.variation_id : '')
      );
      window.setTimeout(function () {
        if (isPageMode($listings)) {
          goToListings();
          return;
        }
        closeWizard($listings);
        loadListings($listings, 1);
      }, 500);
    }).fail(function () {
      $submitBtns.prop('disabled', false);
      if (isManageModalOpen($listings) && typeof SutoreMarketplace.showToast === 'function') {
        SutoreMarketplace.showToast(t('error', 'Error'), 'error');
      }
    });
  }

  function activeFilterCount($root) {
    var n = 0;
    if ($root.find('.sutore-mp-list-status').val()) n++;
    if ($root.find('.sutore-mp-list-size').val()) n++;
    if ($root.find('.sutore-mp-list-condition').val()) n++;
    if ($root.find('.sutore-mp-list-campaign').val()) n++;
    if ($root.find('.sutore-mp-list-sourcing').val()) n++;
    if ($root.find('.sutore-mp-list-imported').val()) n++;
    return n;
  }

  function updateFilterBadge($root) {
    SutoreMarketplace.setFilterBadge($root, activeFilterCount($root));
  }

  function isSortActive($root) {
    var orderby = $root.find('.sutore-mp-list-orderby').val() || 'created_at';
    var order = $root.find('.sutore-mp-list-order').val() || 'DESC';
    return orderby !== 'created_at' || order !== 'DESC';
  }

  function updateSortBadge($root) {
    SutoreMarketplace.setSortBadge($root, isSortActive($root));
  }

  function updateListBadges($root) {
    updateFilterBadge($root);
    updateSortBadge($root);
  }

  function loadListings($root, page) {
    page = page || 1;
    $root.data('page', page);
    updateListBadges($root);
    var $box = $root.find('.sutore-mp-list-results').attr('aria-busy', 'true').html(listLoadingHtml());
    api('marketplace_listings_query', {
      search: $root.find('.sutore-mp-list-search').val(),
      status: $root.find('.sutore-mp-list-status').val(),
      size_term_id: $root.find('.sutore-mp-list-size').val(),
      condition_key: $root.find('.sutore-mp-list-condition').val(),
      campaign: $root.find('.sutore-mp-list-campaign').val(),
      is_sourcing: $root.find('.sutore-mp-list-sourcing').val(),
      is_imported: $root.find('.sutore-mp-list-imported').val(),
      orderby: $root.find('.sutore-mp-list-orderby').val() || 'created_at',
      order: $root.find('.sutore-mp-list-order').val() || 'DESC',
      page: page,
      per_page: 20
    }).done(function (res) {
      if (!res.success) {
        $box.attr('aria-busy', 'false').html(
          '<p class="sutore-mp-error">' +
            $('<div/>').text((res.data && res.data.message) || t('error', 'Error')).html() +
            '</p>'
        );
        $root.find('.sutore-mp-list-chrome').prop('hidden', false);
        return;
      }
      $box.empty().attr('aria-busy', 'false');
      var items = res.data.items || [];
      if (!items.length) {
        var hasFilters =
          activeFilterCount($root) > 0 || $.trim($root.find('.sutore-mp-list-search').val() || '') !== '';
        $box.append(
          $('<p class="sutore-mp-empty"/>').text(
            hasFilters ? t('noResults', 'No results found.') : t('emptyListings', 'You have not added a product yet.')
          )
        );
      } else {
        items.forEach(function (item) {
          var $card = $('<div class="sutore-mp-card"/>');
          if (item.listing_status === 'expired') {
            $card.addClass('is-expired');
          }
          if (item.campaign_status === 'offer') {
            $card.addClass('is-campaign-offer');
          } else if (item.campaign_status === 'active') {
            $card.addClass('is-campaign-active');
          }
          var $main = $('<div class="sutore-mp-card-main"/>');
          if (item.thumbnail) {
            $main.append(thumbBox('sutore-mp-card-thumb-box', 'sutore-mp-card-thumb', item.thumbnail, item.parent_title || ''));
          } else {
            $main.append(thumbBox('sutore-mp-card-thumb-box', 'sutore-mp-card-thumb', '', ''));
          }
          var $info = $('<div class="sutore-mp-card-info"/>');
          if (item.permalink) {
            $info.append(
              $('<a class="sutore-mp-card-title"/>')
                .attr('href', item.permalink)
                .attr('target', '_blank')
                .attr('rel', 'noopener noreferrer')
                .text(item.parent_title || '')
            );
          } else {
            $info.append($('<div class="sutore-mp-card-title"/>').text(item.parent_title || ''));
          }
          var codeLine = item.product_code || '';
          if (codeLine) {
            $info.append($('<div class="sutore-mp-card-code"/>').text(codeLine));
          }
          var metaParts = [];
          if (item.variation_id) {
            metaParts.push('#' + item.variation_id);
          }
          metaParts.push(statusLabel(item));
          $info.append($('<div class="sutore-mp-card-meta"/>').html(
            metaParts.map(function (part) { return escHtml(part); }).join(' · ') +
            ' · ' + formatListingPriceHtml(item)
          ));

          var remaining = formatRemaining(item);
          if (remaining) {
            var remainingPrefix = t('timeLeft', 'Time remaining');
            if (item.fulfillment && item.fulfillment.can_confirm) {
              remainingPrefix = t('confirmTimeLeft', 'Confirmation time remaining');
            } else if (item.fulfillment && item.fulfillment.can_ship) {
              remainingPrefix = t('shipTimeLeft', 'Shipping time remaining');
            }
            $info.append(
              $('<div class="sutore-mp-card-remaining"/>').text(remainingPrefix + ': ' + remaining)
            );
          }

          if (item.campaign && (item.campaign.ends_at_label || item.campaign.ends_at)) {
            $info.append(
              $('<div class="sutore-mp-card-campaign-end"/>').text(
                t('campaignEndsAt', 'Campaign ends') +
                  ': ' +
                  (item.campaign.ends_at_label || item.campaign.ends_at)
              )
            );
          }

          var tags = listingTags(item);
          if (tags.length) {
            var $tags = $('<div class="sutore-mp-card-tags"/>');
            tags.forEach(function (tag) {
              $tags.append($('<span class="sutore-mp-tag"/>').addClass(tag.cls || '').text(tag.label));
            });
            $info.append($tags);
          }
          var $actions = $('<div class="sutore-mp-card-actions"/>');
          var listingId = parseInt(item.id, 10) || 0;
          $actions.append(
            $('<a class="wp-element-button sutore-mp-open-manage"/>')
              .attr('href', manageUrl(listingId))
              .attr('data-listing-id', String(listingId))
              .text(t('manage', 'Manage'))
          );
          if (item.can_put_on_sale && listingId > 0) {
            $actions.append(
              $('<button type="button" class="wp-element-button sutore-mp-put-on-sale"/>')
                .attr('data-listing-id', String(listingId))
                .attr('data-page', String(page))
                .text(t('putOnSale', 'Put on sale'))
            );
          }
          if (item.can_remove_from_sale && listingId > 0) {
            $actions.append(
              $('<button type="button" class="wp-element-button is-style-outline sutore-mp-remove-from-sale"/>')
                .attr('data-listing-id', String(listingId))
                .attr('data-page', String(page))
                .text(t('removeFromSale', 'Remove from sale'))
            );
          }
          if (item.can_delete && listingId > 0) {
            $actions.append(
              $('<button type="button" class="wp-element-button is-style-outline sutore-mp-delete"/>')
                .attr('data-listing-id', String(listingId))
                .attr('data-page', String(page))
                .text(t('delete', 'Delete'))
            );
          }
          if (item.campaign_status === 'offer') {
            var offersUrl = (window.SutoreMarketplace && SutoreMarketplace.campaignOffersUrl) || '';
            var offerId = item.campaign && item.campaign.offer_id ? parseInt(item.campaign.offer_id, 10) : 0;
            if (offersUrl) {
              var href = offersUrl;
              if (offerId > 0) {
                href += (offersUrl.indexOf('?') >= 0 ? '&' : '?') + 'offer=' + offerId;
              }
              $actions.append(
                $('<a class="wp-element-button is-style-outline sutore-mp-open-campaign-offer"/>')
                  .attr('href', href)
                  .text(t('reviewCampaignOffer', 'Review offer'))
              );
            }
          }
          $info.append($actions);
          $main.append($info);
          $card.append($main);
          $box.append($card);
        });
      }

      var total = res.data.total || 0;
      var perPage = res.data.per_page || 20;
      var pages = Math.max(1, Math.ceil(total / perPage));
      var $pager = $root.find('.sutore-mp-list-pager').empty();
      if (pages > 1 && items.length) {
        for (var i = 1; i <= pages; i++) {
          (function (p) {
            var $b = $('<button type="button" class="wp-element-button is-style-outline"/>').text(p);
            if (p === page) $b.removeClass('is-style-outline');
            $b.on('click', function () { loadListings($root, p); });
            $pager.append($b);
          })(i);
        }
      }
      $root.find('.sutore-mp-list-chrome').prop('hidden', false);
    }).fail(function () {
      $box.attr('aria-busy', 'false').html(
        '<p class="sutore-mp-error">' + $('<div/>').text(t('error', 'Error')).html() + '</p>'
      );
      $root.find('.sutore-mp-list-chrome').prop('hidden', false);
    });
  }

  $(document).on('click', '.sutore-mp-open-size-prices', function (e) {
    e.preventDefault();
    openSizePricesModal($shell($(this)));
  });

  $(document).on('click', '.sutore-mp-size-prices-close', function () {
    closeSizePricesModal($shell($(this)));
  });

  $(document).on('click', '.sutore-mp-size-prices-overlay', function (e) {
    if (e.target === this) {
      closeSizePricesModal($shell($(this)));
    }
  });

  $(document).on('sutore-mp-bulk:committed', '.sutore-mp-listings', function () {
    var $listings = $(this);
    loadListings($listings, $listings.data('page') || 1);
  });

  $(document).on('click', '.sutore-mp-open-create', function (e) {
    e.preventDefault();
    openWizard($shell($(this)), 0);
  });

  $(document).on('click', '.sutore-mp-open-bulk', function (e) {
    e.preventDefault();
    var $listings = $shell($(this));
    if (hasBulkModal($listings)) {
      openBulkModal($listings);
    }
  });

  $(document).on('click', '.sutore-mp-bulk-modal__close, .sutore-mp-bulk-close', function () {
    var $listings = $shell($(this));
    closeBulkModal($listings);
  });

  $(document).on('click', '.sutore-mp-bulk-overlay', function (e) {
    if (e.target === this) {
      closeBulkModal($shell($(this)));
    }
  });

  $(document).on('click', '.sutore-mp-open-manage', function (e) {
    var $listings = $shell($(this));
    if (!hasManageModal($listings)) {
      return;
    }
    e.preventDefault();
    var id =
      parseInt(String($(this).attr('data-listing-id') || $(this).data('listing-id') || '0'), 10) || 0;
    if (!id) {
      try {
        id = parseInt(new URL($(this).attr('href'), window.location.origin).searchParams.get('listing_id') || '0', 10) || 0;
      } catch (err) {
        id = 0;
      }
    }
    if (id) {
      openManageModal($listings, id);
    }
  });

  $(document).on('click', '.sutore-mp-manage-modal__close', function () {
    closeManageModal($shell($(this)));
  });

  $(document).on('click', '.sutore-mp-manage-close', function () {
    closeManageModal($shell($(this)));
  });

  $(document).on('click', '.sutore-mp-create-wizard-next', function () {
    var $listings = $shell($(this));
    if (!isCreateManageMode($listings)) {
      return;
    }
    var step = createWizardStep($listings);
    if (!canLeaveCreateWizardStep($listings, step)) {
      return;
    }
    setCreateWizardStep($listings, step + 1);
  });

  $(document).on('click', '.sutore-mp-create-wizard-back', function () {
    var $listings = $shell($(this));
    if (!isCreateManageMode($listings)) {
      return;
    }
    setCreateWizardStep($listings, createWizardStep($listings) - 1);
  });

  $(document).on('click', '.sutore-mp-manage-update', function () {
    var $listings = $shell($(this));
    if (isCreateManageMode($listings) && createWizardStep($listings) !== CREATE_WIZARD_TOTAL) {
      return;
    }
    var $submit = $listings.find('.sutore-mp-manage-panel[data-panel="details"] .sutore-mp-submit');
    if ($submit.length && !$submit.prop('hidden') && !$submit.hasClass('is-hidden')) {
      $submit.trigger('click');
    }
  });

  $(document).on('click', '.sutore-mp-manage-overlay', function (e) {
    if (e.target === this) {
      closeManageModal($shell($(this)));
    }
  });

  $(document).on('click', '.sutore-mp-manage-tab', function () {
    var $listings = $shell($(this));
    setManageTab($listings, $(this).attr('data-tab') || 'details');
  });

  $(document).on('click', '.sutore-mp-list-apply', function () {
    var $root = $shell($(this));
    SutoreMarketplace.closeListOverlays($root);
    loadListings($root, 1);
  });

  $(document).on('click', '.sutore-mp-list-clear', function () {
    var $root = $shell($(this));
    $root.find('.sutore-mp-filter-body select').each(function () {
      $(this).prop('selectedIndex', 0);
    });
    updateListBadges($root);
    SutoreMarketplace.closeListOverlays($root);
    loadListings($root, 1);
  });

  $(document).on('click', '.sutore-mp-list-sort-apply', function () {
    var $root = $shell($(this));
    SutoreMarketplace.closeListOverlays($root);
    loadListings($root, 1);
  });

  $(document).on('click', '.sutore-mp-list-sort-clear', function () {
    var $root = $shell($(this));
    $root.find('.sutore-mp-list-orderby').val('created_at');
    $root.find('.sutore-mp-list-order').val('DESC');
    updateListBadges($root);
    SutoreMarketplace.closeListOverlays($root);
    loadListings($root, 1);
  });

  $(document).on('input', '.sutore-mp-list-search', function () {
    var $root = $shell($(this));
    clearTimeout(listSearchTimer);
    listSearchTimer = setTimeout(function () {
      loadListings($root, 1);
    }, 320);
  });

  $(document).on('keydown', '.sutore-mp-list-search', function (e) {
    if (e.key !== 'Enter') {
      return;
    }
    e.preventDefault();
    clearTimeout(listSearchTimer);
    loadListings($shell($(this)), 1);
  });

  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') return;
    $('.sutore-mp-listings').each(function () {
      var $root = $(this);
      var $sizePrices = $root.find('.sutore-mp-size-prices-overlay');
      if ($sizePrices.length && !$sizePrices.prop('hidden')) {
        closeSizePricesModal($root);
        return;
      }
      if (isBulkModalOpen($root)) {
        closeBulkModal($root);
        return;
      }
      if (isManageModalOpen($root)) {
        closeManageModal($root);
      }
    });
  });

  $(document).on('click', '.sutore-mp-open-edit', function (e) {
    e.preventDefault();
    openWizard($shell($(this)), parseInt($(this).data('listing-id'), 10) || 0);
  });

  $(document).on('input', '.sutore-mp-product-code', function () {
    var $root = $form($(this));
    if ($root.data('listing-id')) return;
    clearTimeout(searchTimer);
    var code = $(this).val();
    searchTimer = setTimeout(function () { liveSearch($root, code); }, 280);
  });

  $(document).on('click', '.sutore-mp-pick-parent', function () {
    var $btn = $(this);
    var $root = $form($btn);
    if (parseInt($root.attr('data-listing-id') || $root.data('listing-id'), 10)) return;
    var id = $btn.attr('data-id');
    $root.find('.sutore-mp-parent-id').val(id);
    $root.find('.sutore-mp-search-results').empty();
    var productData = {
      title: $btn.attr('data-title') || '',
      product_code: $btn.attr('data-code') || '',
      thumbnail: $btn.attr('data-thumb') || '',
      permalink: $btn.attr('data-permalink') || '',
      has_retail_price: $btn.attr('data-has-retail') === '1',
      retail_price_usd: $btn.attr('data-retail-usd') || null,
      retail_price_tl: $btn.attr('data-retail-tl') || null,
      has_release_year: $btn.attr('data-has-year') === '1',
      release_year: $btn.attr('data-year') || null,
      clear_size: true
    };
    setSelectedProduct($root, productData);
    setWizardProduct($shell($root), productData);
    loadSizes($root, id, null, function () {
      setSizeLocked($root, false);
      refreshContext($root);
    });
  });

  $(document).on('change', '.sutore-mp-size, .sutore-mp-conditions input, .sutore-mp-shipping-express-flag, .sutore-mp-shipping-intl-flag', function () {
    var $root = $form($(this));
    if ($(this).hasClass('sutore-mp-size')) {
      if ($root.data('locked-size-id')) {
        return;
      }
      if ($(this).is(':checked')) {
        $root.find('.sutore-mp-size').not(this).prop('checked', false);
      }
      updateWizardContext($shell($root));
    }
    if ($(this).hasClass('sutore-mp-shipping-express-flag') || $(this).hasClass('sutore-mp-shipping-intl-flag')) {
      syncInternationalCommit($root);
      setShippingError($root, '');
    }
    refreshContext($root);
  });

  $(document).on('input', '.sutore-mp-asking', function () {
    var $root = $form($(this));
    validateAsking($root);
    updateWizardContext($shell($root));
    clearTimeout(askingTimer);
    askingTimer = setTimeout(function () { refreshContext($root, { skipCompetingPrices: true }); }, 300);
  });

  $(document).on('click', '.sutore-mp-first-place', function () {
    var $root = $form($(this));
    var val = $root.data('first-place');
    if (val == null || val === '') {
      return;
    }
    $root.find('.sutore-mp-asking').val(val).trigger('input');
  });

  $(document).on('click', '.sutore-mp-submit', function () {
    var $root = $form($(this));
    if (!validateFlatForm($root)) {
      return;
    }
    submitListing($root);
  });

  $(document).on('click', '.sutore-mp-delete', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $btn = $(this);
    var $listings = $shell($btn);
    var id = parseInt(String($btn.attr('data-listing-id') || $btn.data('listing-id') || '0'), 10) || 0;
    var page = parseInt(String($btn.attr('data-page') || $btn.data('page') || $listings.data('page') || '1'), 10) || 1;
    if (!id) {
      return;
    }
    showConfirm(t('deleteTitle', 'Delete this Listing?'), t('confirmDelete', ''), t('delete', 'Delete'), function () {
      api('marketplace_listing_delete', { listing_id: id }).done(function (r) {
        if (!r.success) {
          showConfirm(t('deleteTitle', 'Delete this Listing?'), (r.data && r.data.message) || t('cannotDelete', ''), t('cancel', 'Cancel'), function () {});
          return;
        }
        // Manage modal / create page / list refresh.
        if (isManageModalOpen($listings)) {
          closeManageModal($listings);
          loadListings($listings, page);
          return;
        }
        if (isPageMode($listings)) {
          goToListings();
          return;
        }
        loadListings($listings, page);
      });
    });
  });

  $(document).on('click', '.sutore-mp-put-on-sale', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $btn = $(this);
    var $listings = $shell($btn);
    var id = parseInt(String($btn.attr('data-listing-id') || $btn.data('listing-id') || '0'), 10) || 0;
    var page = parseInt(String($btn.attr('data-page') || $btn.data('page') || $listings.data('page') || '1'), 10) || 1;
    if (!id) {
      return;
    }

    var confirmFn = (window.SutoreMarketplace && SutoreMarketplace.showConfirm) || showConfirm;
    var runPutOnSale = function () {
      api('marketplace_listing_put_on_sale', { listing_id: id }).done(function (r) {
        if (!r.success) {
          if (confirmFn) {
            confirmFn(
              t('putOnSaleTitle', 'Put this listing back on sale?'),
              (r.data && r.data.message) || t('putOnSaleFailed', 'This listing cannot be put back on sale right now.'),
              t('cancel', 'Cancel'),
              function () {}
            );
          }
          return;
        }
        if (isManageModalOpen($listings)) {
          loadManageModal($listings, id);
          loadListings($listings, page);
          return;
        }
        if (isPageMode($listings)) {
          goToListings();
          return;
        }
        loadListings($listings, page);
      });
    };

    if (typeof confirmFn === 'function') {
      confirmFn(
        t('putOnSaleTitle', 'Put this listing back on sale?'),
        t('putOnSaleConfirm', 'The listing will re-enter the sale queue with a fresh expiry window.'),
        t('putOnSale', 'Put on sale'),
        runPutOnSale
      );
      return;
    }

    runPutOnSale();
  });

  $(document).on('click', '.sutore-mp-remove-from-sale', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $btn = $(this);
    var $listings = $shell($btn);
    var id = parseInt(String($btn.attr('data-listing-id') || $btn.data('listing-id') || '0'), 10) || 0;
    var page = parseInt(String($btn.attr('data-page') || $btn.data('page') || $listings.data('page') || '1'), 10) || 1;
    if (!id) {
      return;
    }

    var confirmFn = (window.SutoreMarketplace && SutoreMarketplace.showConfirm) || showConfirm;
    var runRemove = function () {
      api('marketplace_listing_remove_from_sale', { listing_id: id, staff_note: '' }).done(function (r) {
        if (!r.success) {
          if (confirmFn) {
            confirmFn(
              t('removeFromSaleTitle', 'Remove this listing from sale?'),
              (r.data && r.data.message) || t('removeFromSaleFailed', 'This listing cannot be removed from sale right now.'),
              t('cancel', 'Cancel'),
              function () {}
            );
          }
          return;
        }
        if (isManageModalOpen($listings)) {
          loadManageModal($listings, id);
          loadListings($listings, page);
          return;
        }
        if (isPageMode($listings)) {
          goToListings();
          return;
        }
        loadListings($listings, page);
      });
    };

    if (typeof confirmFn === 'function') {
      confirmFn(
        t('removeFromSaleTitle', 'Remove this listing from sale?'),
        t('removeFromSaleConfirm', 'The listing will leave the sale queue without being deleted.'),
        t('removeFromSale', 'Remove from sale'),
        runRemove
      );
      return;
    }

    runRemove();
  });

  $(document).on('click', '.sutore-mp-pager-btn', function () {
    var $btn = $(this);
    var $root = $shell($btn);
    var p = parseInt($btn.attr('data-page') || $btn.data('page'), 10) || 1;
    loadListings($root, p);
  });

  $(window).on('popstate', function () {
    $('.sutore-mp-listings').each(function () {
      var $root = $(this);
      if (!hasManageModal($root) || isPageMode($root)) {
        return;
      }
      var id = listingIdFromUrl();
      if (id) {
        if (isBulkModalOpen($root)) {
          closeBulkModal($root, { skipUrl: true });
        }
        openManageModal($root, id, { skipUrl: true });
      } else if (createModeFromUrl()) {
        if (isBulkModalOpen($root)) {
          closeBulkModal($root, { skipUrl: true });
        }
        openCreateModal($root, { skipUrl: true });
      } else if (bulkModeFromUrl()) {
        if (isManageModalOpen($root)) {
          closeManageModal($root, { skipUrl: true });
        }
        openBulkModal($root, { skipUrl: true });
      } else {
        if (isManageModalOpen($root)) {
          closeManageModal($root, { skipUrl: true });
        }
        if (isBulkModalOpen($root)) {
          closeBulkModal($root, { skipUrl: true });
        }
      }
    });
  });

  $(function () {
    $('.sutore-mp-listings[data-create-mode="1"]').each(function () {
      openWizard($(this), 0);
    });

    $('.sutore-mp-listings').each(function () {
      var $root = $(this);
      if (isPageMode($root)) {
        return;
      }
      loadListings($root, 1);
      var manageId = listingIdFromUrl();
      if (manageId && hasManageModal($root)) {
        openManageModal($root, manageId, { replaceUrl: true });
      } else if (createModeFromUrl() && hasManageModal($root)) {
        openCreateModal($root, { replaceUrl: true });
      } else if (bulkModeFromUrl() && hasBulkModal($root)) {
        openBulkModal($root, { replaceUrl: true });
      }
    });
  });
})(jQuery);
