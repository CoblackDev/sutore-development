(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplaceStaffOrders || {};
  var i18n = cfg.i18n || {};
  var listSearchTimer = null;
  var swapSearchTimer = null;
  var statusLabelsCache = {};

  function t(key, def) {
    return i18n[key] || def;
  }

  function showAlert(title, text) {
    if (window.SutoreMarketplace && typeof SutoreMarketplace.showAlert === 'function') {
      SutoreMarketplace.showAlert(title || t('error', 'Error'), text || '');
      return;
    }
    window.alert(text || title || t('error', 'Error'));
  }

  function showToast(message, type) {
    if (window.SutoreMarketplace && typeof SutoreMarketplace.showToast === 'function') {
      SutoreMarketplace.showToast(message, type);
      return;
    }
    if (type === 'error') {
      showAlert(t('error', 'Error'), message);
    }
  }

  function setModalAlert($root, message) {
    var $alert = $root.find('.sutore-mp-staff-orders-modal-alert').first();
    if (!$alert.length) {
      if (message) {
        showAlert(t('error', 'Error'), message);
      }
      return;
    }
    var text = message == null ? '' : String(message);
    if (!text) {
      $alert.text('').prop('hidden', true);
      return;
    }
    $alert.text(text).prop('hidden', false);
  }

  function clearModalAlert($root) {
    setModalAlert($root, '');
  }

  function returnToQueueChecked($root) {
    var $box = $root.find('.sutore-mp-staff-orders-return-queue').first();
    if (!$box.length) {
      return true;
    }
    return !!$box.prop('checked');
  }

  function resetReturnToQueue($root) {
    $root.find('.sutore-mp-staff-orders-return-queue').prop('checked', true);
  }

  function syncEditCancelVisibility($shell) {
    var show = hasPendingEdits($shell) || !!pendingStatus($shell);
    $shell.find('.sutore-mp-staff-orders-edit-cancel').prop('hidden', !show);
  }

  function esc(str) {
    return $('<div/>').text(str == null ? '' : String(str)).html();
  }

  function dash(val) {
    var s = val == null ? '' : String(val).trim();
    return s !== '' ? s : '—';
  }

  function manageProductUrl(variationId) {
    variationId = parseInt(variationId, 10) || 0;
    var base = String(cfg.manageProductsUrl || '').trim();
    if (!variationId || !base) {
      return '';
    }
    try {
      var u = new URL(base, window.location.origin);
      u.searchParams.set('variation_id', String(variationId));
      return u.pathname + u.search + u.hash;
    } catch (err) {
      return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'variation_id=' + variationId;
    }
  }

  function productDeeplinkHtml(label, variationId, linkable) {
    var text = String(label == null ? '' : label);
    variationId = parseInt(variationId, 10) || 0;
    var href = linkable && variationId ? manageProductUrl(variationId) : '';
    if (!variationId || text === '' || !linkable) {
      return esc(text);
    }
    return (
      '<a class="sutore-mp-staff-orders-product-link sutore-mp-staff-open-manage" href="' +
      esc(href || '#') +
      '" data-variation-id="' +
      esc(String(variationId)) +
      '" data-product-title="' +
      esc(text) +
      '" title="' +
      esc(t('openListingDetail', 'Open product detail')) +
      '">' +
      esc(text) +
      '</a>'
    );
  }

  function merchantDetailUrl(merchantId) {
    merchantId = parseInt(merchantId, 10) || 0;
    var base = String(cfg.merchantsUrl || '').trim();
    if (!merchantId || !base) {
      return '';
    }
    try {
      var u = new URL(base, window.location.origin);
      u.searchParams.set('merchant_id', String(merchantId));
      return u.pathname + u.search + u.hash;
    } catch (err) {
      return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'merchant_id=' + merchantId;
    }
  }

  function merchantDeeplinkHtml(label, merchantId) {
    var text = String(label == null ? '' : label);
    merchantId = parseInt(merchantId, 10) || 0;
    var href = merchantDetailUrl(merchantId);
    if (!merchantId || text === '') {
      return esc(text);
    }
    return (
      '<a class="sutore-mp-staff-orders-merchant-link sutore-mp-staff-open-merchant" href="' +
      esc(href || '#') +
      '" data-merchant-id="' +
      esc(String(merchantId)) +
      '" title="' +
      esc(t('openSellerDetail', 'Open seller detail')) +
      '">' +
      esc(text) +
      '</a>'
    );
  }

  function loadingHtml() {
    return (
      '<p class="sutore-mp-staff-loading" role="status" aria-live="polite">' +
      '<span class="sutore-mp-staff-spinner" aria-hidden="true"></span>' +
      '<span class="screen-reader-text">' +
      esc(t('loading', 'Loading…')) +
      '</span></p>'
    );
  }

  function ajax(method, path, data) {
    var core = window.SutoreMarketplace;
    if (core && typeof core.request === 'function') {
      return core.request(method, path, {
        query: method === 'GET' ? data || {} : undefined,
        body: method !== 'GET' ? data || {} : undefined,
        restUrl: cfg.restUrl,
        restNonce: cfg.restNonce
      });
    }
    var opts = {
      url: (cfg.restUrl || '') + path,
      method: method,
      dataType: 'json',
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    };
    if (method === 'GET') {
      opts.data = data || {};
    } else {
      opts.contentType = 'application/json';
      opts.data = JSON.stringify(data || {});
    }
    return $.ajax(opts);
  }

  function $pageShell($from) {
    var $host = $from.closest('.sutore-mp-staff-order-detail-host');
    if ($host.length) {
      return $host;
    }
    return $from.closest('.sutore-mp-staff-orders');
  }

  function $orderDetailHost() {
    return $('.sutore-mp-staff-order-detail-host').first();
  }

  function $ordersListRoot() {
    return $('.sutore-mp-staff-orders-list-root')
      .filter(function () {
        return $(this).closest('.sutore-mp-staff-order-detail-host').length === 0;
      })
      .first();
  }

  function isOrdersListPage() {
    return $ordersListRoot().length > 0;
  }

  function otherStaffOverlaysOpen() {
    return (
      $('.sutore-mp-manage-overlay.is-open')
        .not(
          '.sutore-mp-staff-orders-overlay, .sutore-mp-staff-orders-swap-overlay, .sutore-mp-staff-orders-detach-overlay, .sutore-mp-staff-orders-attach-overlay, .sutore-mp-staff-orders-apply-overlay'
        ).length > 0 ||
      (window.SutoreMarketplace &&
        SutoreMarketplace.isStaffMerchantOpen &&
        SutoreMarketplace.isStaffMerchantOpen()) ||
      (window.SutoreMarketplace &&
        SutoreMarketplace.isStaffProductOpen &&
        SutoreMarketplace.isStaffProductOpen())
    );
  }

  function kvRow(label, valueHtml) {
    return (
      '<div class="sutore-mp-manage-kv__row">' +
      '<dt>' +
      esc(label) +
      '</dt><dd>' +
      valueHtml +
      '</dd></div>'
    );
  }

  function statusTag(status, label) {
    var key = String(status || '').trim();
    var text = String(label || status || '').trim() || '—';
    var modifier = key ? 'is-status-' + key.replace(/_/g, '-') : 'is-status-unknown';
    return '<span class="sutore-mp-tag ' + modifier + '">' + esc(text) + '</span>';
  }

  function syncListUrl(baseUrl, state) {
    try {
      var u = new URL(baseUrl, window.location.origin);
      if (state.search) {
        u.searchParams.set('search', state.search);
      } else {
        u.searchParams.delete('search');
      }
      if (state.status) {
        u.searchParams.set('status', state.status);
      } else {
        u.searchParams.delete('status');
      }
      if (state.orderby && state.orderby !== 'date_desc') {
        u.searchParams.set('orderby', state.orderby);
      } else {
        u.searchParams.delete('orderby');
      }
      if (state.page > 1) {
        u.searchParams.set('paged', String(state.page));
      } else {
        u.searchParams.delete('paged');
      }
      u.searchParams.delete('order_id');
      window.history.replaceState({}, '', u.pathname + u.search);
    } catch (err) {
      /* ignore */
    }
  }

  function detailUrl(baseUrl, orderId) {
    try {
      var u = new URL(baseUrl, window.location.origin);
      u.searchParams.set('order_id', String(orderId));
      return u.pathname + u.search;
    } catch (err) {
      return baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + 'order_id=' + orderId;
    }
  }

  function readListState($root, overrides) {
    overrides = overrides || {};
    function pick(key, dataKey, fallback) {
      if (Object.prototype.hasOwnProperty.call(overrides, key) && overrides[key] != null) {
        return String(overrides[key]);
      }
      var fromData = $root.data(dataKey);
      if (fromData != null && String(fromData) !== '') {
        return String(fromData);
      }
      return fallback != null ? String(fallback) : '';
    }
    return {
      baseUrl: pick('baseUrl', 'baseUrl', ''),
      search: pick('search', 'search', ''),
      status: pick('status', 'status', ''),
      orderby: pick('orderby', 'orderby', 'date_desc') || 'date_desc',
      page: Object.prototype.hasOwnProperty.call(overrides, 'page')
        ? parseInt(overrides.page, 10) || 1
        : parseInt($root.data('page'), 10) || 1,
      perPage: parseInt($root.data('perPage'), 10) || 30
    };
  }

  function collectFilterState($shell) {
    var $root = $shell.find('.sutore-mp-staff-orders-list-root');
    var $filter = $shell.find('.sutore-mp-staff-orders-filter');
    var $sort = $shell.find('.sutore-mp-staff-orders-sort');
    var search = String($shell.find('.sutore-mp-staff-orders-search').val() || '').trim();
    return {
      search: search,
      status: String($filter.find('[name="status"]').val() || ''),
      orderby: String($sort.find('[name="orderby"]').val() || 'date_desc'),
      page: 1,
      baseUrl: String($root.data('baseUrl') || '')
    };
  }

  function syncFilterFields($shell, state) {
    $shell.find('.sutore-mp-staff-orders-search').val(state.search || '');
    $shell.find('.sutore-mp-staff-orders-filter [name="status"]').val(state.status || '');
    $shell.find('.sutore-mp-staff-orders-sort [name="orderby"]').val(state.orderby || 'date_desc');
  }

  function updateListBadges($shell, state) {
    var filterCount = state.status ? 1 : 0;
    if (window.SutoreMarketplace && typeof SutoreMarketplace.setFilterBadge === 'function') {
      SutoreMarketplace.setFilterBadge($shell, filterCount);
    }
    if (window.SutoreMarketplace && typeof SutoreMarketplace.setSortBadge === 'function') {
      SutoreMarketplace.setSortBadge($shell, state.orderby && state.orderby !== 'date_desc');
    }
  }

  function renderPager(page, totalPages) {
    if (totalPages <= 1) {
      return '';
    }
    var prevDisabled = page <= 1 ? ' disabled' : '';
    var nextDisabled = page >= totalPages ? ' disabled' : '';
    return (
      '<nav class="sutore-mp-staff-pager" aria-label="' +
      esc(t('pagination', 'Pagination')) +
      '">' +
      '<button type="button" class="wp-element-button is-style-outline" data-page="' +
      String(page - 1) +
      '"' +
      prevDisabled +
      '>' +
      esc(t('previous', 'Previous')) +
      '</button>' +
      '<span class="sutore-mp-staff-pager-label">' +
      esc(
        (t('pageOf', 'Page %1$d / %2$d') || 'Page %1$d / %2$d')
          .replace('%1$d', String(page))
          .replace('%2$d', String(totalPages))
      ) +
      '</span>' +
      '<button type="button" class="wp-element-button is-style-outline" data-page="' +
      String(page + 1) +
      '"' +
      nextDisabled +
      '>' +
      esc(t('next', 'Next')) +
      '</button></nav>'
    );
  }

  function sellerCountLabel(count) {
    var n = parseInt(count, 10) || 0;
    if (n === 1) {
      return t('sellerOne', '1 seller');
    }
    return (t('sellerMany', '%d sellers') || '%d sellers').replace('%d', String(n));
  }

  function statusOptionsHtml(selected) {
    var html = '';
    Object.keys(statusLabelsCache || {}).forEach(function (key) {
      html +=
        '<option value="' +
        esc(key) +
        '"' +
        (String(key) === String(selected) ? ' selected' : '') +
        '>' +
        esc(statusLabelsCache[key]) +
        '</option>';
    });
    return html;
  }

  function renderList(data, state) {
    var items = data.items || [];
    var page = parseInt(data.page, 10) || state.page || 1;
    var perPage = parseInt(data.per_page, 10) || state.perPage || 30;
    var total = parseInt(data.total, 10) || 0;
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    if (data.status_labels && typeof data.status_labels === 'object') {
      statusLabelsCache = data.status_labels;
    }

    var html =
      '<div class="sutore-mp-staff-bulk-bar" hidden>' +
      '<span class="sutore-mp-staff-bulk-count" aria-live="polite"></span>' +
      '<label class="sutore-mp-staff-bulk-action-label screen-reader-text" for="sutore-mp-staff-orders-bulk-status">' +
      esc(t('bulkActions', 'Bulk actions')) +
      '</label>' +
      '<select id="sutore-mp-staff-orders-bulk-status" class="sutore-mp-input sutore-mp-staff-bulk-action" disabled>' +
      '<option value="">' +
      esc(t('changeStatus', 'Change status')) +
      '</option>' +
      statusOptionsHtml('') +
      '</select>' +
      '<button type="button" class="wp-element-button sutore-mp-staff-bulk-apply" disabled>' +
      esc(t('apply', 'Apply')) +
      '</button>' +
      '</div>' +
      '<div class="sutore-mp-staff-table-wrap"><table class="sutore-mp-staff-table"><thead><tr>' +
      '<th class="sutore-mp-staff-col-select">' +
      '<label class="sutore-mp-staff-select-all-wrap">' +
      '<input type="checkbox" class="sutore-mp-staff-select-all" />' +
      '<span class="screen-reader-text">' +
      esc(t('selectAll', 'Select all')) +
      '</span></label></th>' +
      '<th>' +
      esc(t('order', 'Order')) +
      '</th><th>' +
      esc(t('date', 'Date')) +
      '</th><th>' +
      esc(t('status', 'Status')) +
      '</th><th>' +
      esc(t('items', 'Items')) +
      '</th><th>' +
      esc(t('sellers', 'Sellers')) +
      '</th><th>' +
      esc(t('shipmentType', 'Shipment type')) +
      '</th><th>' +
      esc(t('deliveryDeadline', 'Delivery deadline')) +
      '</th><th>' +
      esc(t('total', 'Total')) +
      '</th><th></th></tr></thead><tbody>';

    if (!items.length) {
      html +=
        '<tr><td colspan="9">' +
        esc(t('noRecords', 'No orders found.')) +
        '</td></tr>';
    } else {
      items.forEach(function (item) {
        var id = parseInt(item.id || item.order_id, 10) || 0;
        html +=
          '<tr class="sutore-mp-staff-list-row" data-order-id="' +
          String(id) +
          '">' +
          '<td class="sutore-mp-staff-col-select">' +
          '<input type="checkbox" class="sutore-mp-staff-row-select" value="' +
          String(id) +
          '" /></td>' +
          '<td><strong>#' +
          esc(String(item.number || id)) +
          '</strong>' +
          (item.customer_name
            ? '<div class="sutore-mp-staff-sub">' + esc(item.customer_name) + '</div>'
            : '') +
          '</td><td>' +
          esc(dash(item.date_created_display)) +
          '</td><td>' +
          statusTag(item.status, item.status_label) +
          '</td><td>' +
          esc(String(item.item_count != null ? item.item_count : 0)) +
          '</td><td>' +
          esc(sellerCountLabel(item.seller_count)) +
          '</td><td>' +
          esc(dash(item.shipment_type_label || item.shipping_method_title)) +
          '</td><td>' +
          esc(dash(item.delivery_deadline_display)) +
          '</td><td>' +
          esc(dash(item.total_display)) +
          '</td><td class="sutore-mp-staff-row-actions-cell">' +
          '<div class="sutore-mp-staff-row-actions">' +
          '<button type="button" class="sutore-mp-staff-icon-btn is-outline sutore-mp-staff-open-order" title="' +
          esc(t('detail', 'Detail')) +
          '" aria-label="' +
          esc(t('detail', 'Detail')) +
          '">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>' +
          '<span class="screen-reader-text">' +
          esc(t('detail', 'Detail')) +
          '</span></button></div></td></tr>';
      });
    }

    html += '</tbody></table></div>' + renderPager(page, totalPages);
    return html;
  }

  function selectedOrderIds($root) {
    var ids = [];
    $root.find('.sutore-mp-staff-row-select:checked').each(function () {
      var id = parseInt($(this).val(), 10);
      if (id > 0) {
        ids.push(id);
      }
    });
    return ids;
  }

  function refreshBulkBar($root) {
    var $bar = $root.find('.sutore-mp-staff-bulk-bar');
    var ids = selectedOrderIds($root);
    var $action = $bar.find('.sutore-mp-staff-bulk-action');
    var $apply = $bar.find('.sutore-mp-staff-bulk-apply');
    var count = ids.length;
    if (count > 0) {
      $bar.prop('hidden', false);
      $action.prop('disabled', false);
      $apply.prop('disabled', !$action.val());
      $bar
        .find('.sutore-mp-staff-bulk-count')
        .text((t('selectedCount', '%d selected') || '%d selected').replace('%d', String(count)));
    } else {
      $bar.prop('hidden', true);
      $action.val('').prop('disabled', true);
      $apply.prop('disabled', true);
      $bar.find('.sutore-mp-staff-bulk-count').text('');
      $root.find('.sutore-mp-staff-select-all').prop('checked', false);
    }
  }

  function customerPriceDisplay(item) {
    return (item && (item.customer_price_display || item.total_display)) || '';
  }

  function sellerPriceDisplay(item) {
    return (item && (item.seller_price_display || item.asking_display)) || '';
  }

  function priceLinesHtml(item) {
    var customer = customerPriceDisplay(item);
    var seller = sellerPriceDisplay(item);
    var out = '';
    if (customer) {
      out +=
        '<div class="sutore-mp-staff-sub sutore-mp-staff-price is-customer">' +
        esc(t('customerPrice', 'Customer')) +
        ': ' +
        esc(customer) +
        '</div>';
    }
    if (seller) {
      out +=
        '<div class="sutore-mp-staff-sub sutore-mp-staff-price is-seller">' +
        esc(t('sellerPrice', 'Seller')) +
        ': ' +
        esc(seller) +
        '</div>';
    }
    return out;
  }

  function priceMetaHtml(item) {
    var customer = customerPriceDisplay(item);
    var seller = sellerPriceDisplay(item);
    var out = '';
    if (customer) {
      out += '<strong>' + esc(customer) + '</strong>';
    }
    if (seller) {
      out +=
        '<span class="sutore-mp-staff-order-seller-price">' +
        esc(t('sellerPrice', 'Seller')) +
        ': ' +
        esc(seller) +
        '</span>';
    }
    return out;
  }

  function productPreviewHtml(item, emptyLabel) {
    if (!item) {
      return '<p class="sutore-mp-empty">' + esc(emptyLabel || '—') + '</p>';
    }
    var thumb = String(item.thumbnail || '').trim();
    var media = thumb
      ? '<img class="sutore-mp-staff-order-product-thumb" src="' + esc(thumb) + '" alt="" loading="lazy" />'
      : '<span class="sutore-mp-staff-order-product-thumb is-empty" aria-hidden="true"></span>';
    var meta = [];
    if (item.merchant_name) {
      meta.push(merchantDeeplinkHtml(item.merchant_name, item.merchant_id));
    }
    if (item.size_label) {
      meta.push(esc(item.size_label));
    }
    if (item.variation_id) {
      meta.push('#' + esc(String(item.variation_id)));
    }
    return (
      '<div class="sutore-mp-staff-orders-preview-row">' +
      media +
      '<div class="sutore-mp-staff-order-product-body">' +
      '<div class="sutore-mp-staff-order-product-title">' +
      esc(item.name || item.product_title || '') +
      '</div>' +
      (meta.length ? '<div class="sutore-mp-staff-sub">' + meta.join(' · ') + '</div>' : '') +
      priceLinesHtml(item) +
      '</div></div>'
    );
  }

  function emptyPendingEdits() {
    return { adds: [], detaches: [], removes: [] };
  }

  function getPendingEdits($shell) {
    var pending = $shell.data('pendingEdits');
    if (!pending || !Array.isArray(pending.adds)) {
      pending = emptyPendingEdits();
      $shell.data('pendingEdits', pending);
    }
    return pending;
  }

  function clearPendingEdits($shell) {
    $shell.data('pendingEdits', emptyPendingEdits());
  }

  function pendingCounts(pending) {
    pending = pending || emptyPendingEdits();
    return {
      adds: pending.adds.length,
      detaches: pending.detaches.length,
      removes: pending.removes.length,
      total: pending.adds.length + pending.detaches.length + pending.removes.length
    };
  }

  function pendingStatus($shell) {
    var order = $shell.data('currentOrder') || {};
    var selected = String($shell.find('.sutore-mp-staff-orders-status-select').val() || '');
    var current = String(order.status || '');
    return selected !== '' && selected !== current ? selected : '';
  }

  function hasPendingEdits($shell) {
    return pendingCounts(getPendingEdits($shell)).total > 0 || !!pendingStatus($shell);
  }

  function isPendingDetach(pending, variationId) {
    variationId = parseInt(variationId, 10) || 0;
    return pending.detaches.some(function (row) {
      return parseInt(row.variation_id, 10) === variationId;
    });
  }

  function isPendingRemove(pending, orderItemId) {
    orderItemId = parseInt(orderItemId, 10) || 0;
    return pending.removes.some(function (row) {
      return parseInt(row.order_item_id, 10) === orderItemId;
    });
  }

  function isPendingAdd(pending, variationId) {
    variationId = parseInt(variationId, 10) || 0;
    return pending.adds.some(function (row) {
      return parseInt(row.variation_id, 10) === variationId;
    });
  }

  function countLabel(oneKey, manyKey, oneDef, manyDef, count) {
    var tpl = count === 1 ? t(oneKey, oneDef) : t(manyKey, manyDef);
    return (tpl || '').replace('%d', String(count));
  }

  function refreshDetailPanels($shell) {
    var order = $shell.data('currentOrder') || {};
    $shell.find('.sutore-mp-staff-detail-panels').html(renderDetail(order, isEditing($shell), getPendingEdits($shell)));
  }

  function iconSvg(kind) {
    if (kind === 'plus') {
      return (
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5z"/></svg>'
      );
    }
    if (kind === 'trash') {
      return (
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>'
      );
    }
    if (kind === 'swap') {
      return (
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7 7h11l-1.5-1.5L18 4l4 4-4 4-1.5-1.5L18 9H7V7zm10 10H6l1.5 1.5L6 20l-4-4 4-4 1.5 1.5L6 15h11v2z"/></svg>'
      );
    }
    if (kind === 'undo') {
      return (
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7.1 8H16a5 5 0 0 1 0 10h-3v-2h3a3 3 0 0 0 0-6H7.1l3.2 3.2-1.4 1.4L3.3 9l5.6-5.6 1.4 1.4L7.1 8z"/></svg>'
      );
    }
    return '';
  }

  function ensureEditing($shell) {
    if (isEditing($shell)) {
      syncEditCancelVisibility($shell);
      return;
    }
    $shell.find('.sutore-mp-staff-orders-modal').attr('data-editing', '1');
    $shell.find('.sutore-mp-staff-orders-edit-toggle').text(t('updateOrder', 'Update order'));
    syncEditCancelVisibility($shell);
  }

  function clearReplaceTarget($shell) {
    $shell.removeData('replaceTarget');
  }

  function pendingReplacementForItem(pending, orderItemId) {
    orderItemId = parseInt(orderItemId, 10) || 0;
    for (var i = 0; i < pending.adds.length; i++) {
      if (parseInt(pending.adds[i].replaces_order_item_id, 10) === orderItemId) {
        return pending.adds[i];
      }
    }
    return null;
  }

  function openReplaceSearch($shell, item) {
    openAttachModal($shell, { replaceTarget: item });
  }

  function invoiceLinksHtml(invoices) {
    invoices = Array.isArray(invoices) ? invoices : [];
    var html = '';
    invoices.forEach(function (invoice) {
      if (!invoice || !invoice.has_pdf || !invoice.pdf_url) {
        return;
      }
      var label =
        invoice.kind === 'seller_commission'
          ? t('viewSellerInvoice', 'View seller invoice')
          : t('viewCustomerInvoice', 'View customer invoice');
      html +=
        '<div class="sutore-mp-staff-sub"><a class="sutore-mp-invoice-link" href="' +
        esc(invoice.pdf_url) +
        '" target="_blank" rel="noopener">' +
        esc(label) +
        '</a></div>';
    });
    return html;
  }

  function itemActionsHtml(item, canEditItems, pending) {
    pending = pending || emptyPendingEdits();
    var variationId = parseInt(item.variation_id, 10) || 0;
    var orderItemId = parseInt(item.order_item_id, 10) || 0;
    if (!canEditItems) {
      return '';
    }
    if (isPendingDetach(pending, variationId) || isPendingRemove(pending, orderItemId)) {
      return (
        '<div class="sutore-mp-staff-order-product-actions">' +
        '<button type="button" class="sutore-mp-staff-orders-item-btn sutore-mp-staff-orders-undo-pending" data-undo="' +
        (pendingReplacementForItem(pending, orderItemId)
          ? 'replace'
          : isPendingDetach(pending, variationId)
            ? 'detach'
            : 'remove') +
        '" title="' +
        esc(t('undo', 'Undo')) +
        '" aria-label="' +
        esc(t('undo', 'Undo')) +
        '">' +
        iconSvg('undo') +
        '<span class="screen-reader-text">' +
        esc(t('undo', 'Undo')) +
        '</span></button></div>'
      );
    }
    var buttons = '';
    var canChange = !!item.can_swap || !!item.can_remove || !!item.can_detach;
    if (canChange) {
      buttons +=
        '<button type="button" class="sutore-mp-staff-orders-item-btn sutore-mp-staff-orders-item-action" data-item-action="' +
        (item.can_swap ? 'swap' : 'replace') +
        '" title="' +
        esc(t('changeProduct', 'Change')) +
        '" aria-label="' +
        esc(t('changeProduct', 'Change')) +
        '">' +
        iconSvg('swap') +
        '<span class="screen-reader-text">' +
        esc(t('changeProduct', 'Change')) +
        '</span></button>';
    }
    if (item.can_detach) {
      buttons +=
        '<button type="button" class="sutore-mp-staff-orders-item-btn is-danger sutore-mp-staff-orders-item-action" data-item-action="detach" title="' +
        esc(t('removeFromOrder', 'Remove')) +
        '" aria-label="' +
        esc(t('removeFromOrder', 'Remove')) +
        '">' +
        iconSvg('trash') +
        '<span class="screen-reader-text">' +
        esc(t('removeFromOrder', 'Remove')) +
        '</span></button>';
    } else if (item.can_remove) {
      buttons +=
        '<button type="button" class="sutore-mp-staff-orders-item-btn is-danger sutore-mp-staff-orders-item-action" data-item-action="remove" title="' +
        esc(t('removeFromOrder', 'Remove')) +
        '" aria-label="' +
        esc(t('removeFromOrder', 'Remove')) +
        '">' +
        iconSvg('trash') +
        '<span class="screen-reader-text">' +
        esc(t('removeFromOrder', 'Remove')) +
        '</span></button>';
    }
    if (!buttons) {
      return '';
    }
    return '<div class="sutore-mp-staff-order-product-actions">' + buttons + '</div>';
  }

  function isEditing($shell) {
    return $shell.find('.sutore-mp-staff-orders-modal').attr('data-editing') === '1';
  }

  function setEditing($shell, on) {
    var $modal = $shell.find('.sutore-mp-staff-orders-modal');
    var $toggle = $shell.find('.sutore-mp-staff-orders-edit-toggle');
    $modal.attr('data-editing', on ? '1' : '0');
    $toggle.text(t('updateOrder', 'Update order'));
    if (!on) {
      clearPendingEdits($shell);
    }
    syncEditCancelVisibility($shell);
    refreshDetailPanels($shell);
  }

  function pendingProductRowHtml(item, replacement) {
    var variationId = parseInt(item.variation_id, 10) || 0;
    var orderItemId = parseInt(item.replaces_order_item_id, 10) || 0;
    var thumb = String(item.thumbnail || '').trim();
    var media = thumb
      ? '<img class="sutore-mp-staff-order-product-thumb" src="' + esc(thumb) + '" alt="" loading="lazy" />'
      : '<span class="sutore-mp-staff-order-product-thumb is-empty" aria-hidden="true"></span>';
    var meta = [];
    if (item.merchant_name) {
      meta.push(merchantDeeplinkHtml(item.merchant_name, item.merchant_id));
    }
    if (item.size_label) {
      meta.push(esc(item.size_label));
    }
    if (variationId) {
      meta.push(productDeeplinkHtml('#' + String(variationId), variationId, true));
    }
    var label = t('pendingAdd', 'To be added');
    if (replacement) {
      var oldId = parseInt(item.replaces_variation_id, 10) || 0;
      var oldName = String(item.replaces_name || '').trim() || (oldId ? '#' + String(oldId) : '—');
      var oldLabel = oldName + (oldId ? ' (#' + String(oldId) + ')' : '');
      label = (t('replacesProduct', 'Replaces: %s') || 'Replaces: %s').replace('%s', oldLabel);
    }
    var statusHtml = item.listing_status_label
      ? statusTag(item.listing_status, item.listing_status_label)
      : '';
    var title =
      item.name || item.product_title || (variationId ? '#' + String(variationId) : '');

    return (
      '<li class="sutore-mp-staff-order-product ' +
      (replacement ? 'is-pending-replace' : 'is-pending-add') +
      '" data-variation-id="' +
      esc(String(variationId)) +
      '" data-order-item-id="' +
      esc(String(orderItemId)) +
      '">' +
      media +
      '<div class="sutore-mp-staff-order-product-body">' +
      '<div class="sutore-mp-staff-order-product-title">' +
      productDeeplinkHtml(title, variationId, true) +
      '</div>' +
      (meta.length ? '<div class="sutore-mp-staff-sub">' + meta.join(' · ') + '</div>' : '') +
      '<span class="sutore-mp-staff-orders-pending-badge is-add">' +
      esc(label) +
      '</span>' +
      (statusHtml ? '<div class="sutore-mp-staff-order-product-status">' + statusHtml + '</div>' : '') +
      '</div>' +
      '<div class="sutore-mp-staff-order-product-meta"><span>×1</span>' +
      priceMetaHtml(item) +
      '</div>' +
      '<div class="sutore-mp-staff-order-product-actions">' +
      '<button type="button" class="sutore-mp-staff-orders-item-btn sutore-mp-staff-orders-undo-pending" data-undo="' +
      (replacement ? 'replace' : 'add') +
      '" title="' +
      esc(t('undo', 'Undo')) +
      '" aria-label="' +
      esc(t('undo', 'Undo')) +
      '">' +
      iconSvg('undo') +
      '<span class="screen-reader-text">' +
      esc(t('undo', 'Undo')) +
      '</span></button></div></li>'
    );
  }

  function renderProductsSection(data, editing, pending) {
    pending = pending || emptyPendingEdits();
    var items = data.line_items || [];
    var canEdit = !!data.can_edit_items;
    var title =
      '<div class="sutore-mp-staff-order-section-head">' +
      '<h3 class="sutore-mp-staff-order-section-title">' +
      esc(t('products', 'Products')) +
      '</h3>' +
      (canEdit
        ? '<button type="button" class="sutore-mp-link-btn sutore-mp-staff-orders-add-product">' +
          iconSvg('plus') +
          '<span>' +
          esc(t('addProduct', 'Add product')) +
          '</span></button>'
        : '') +
      '</div>';

    var listHtml = '';
    items.forEach(function (item) {
      var variationId = parseInt(item.variation_id, 10) || 0;
      var orderItemId = parseInt(item.order_item_id, 10) || 0;
      var replacement = pendingReplacementForItem(pending, orderItemId);
      if (replacement) {
        listHtml += pendingProductRowHtml(replacement, true);
        return;
      }
      var markedDetach = isPendingDetach(pending, variationId);
      var markedRemove = isPendingRemove(pending, orderItemId);
      var pendingClass = markedDetach || markedRemove ? ' is-pending-out' : '';
      var pendingBadge = '';
      if (markedDetach) {
        pendingBadge =
          '<span class="sutore-mp-staff-orders-pending-badge">' +
          esc(t('pendingDetach', 'To be detached')) +
          '</span>';
      } else if (markedRemove) {
        pendingBadge =
          '<span class="sutore-mp-staff-orders-pending-badge">' +
          esc(t('pendingRemove', 'To be removed')) +
          '</span>';
      }
      var thumb = String(item.thumbnail || '').trim();
      var media = thumb
        ? '<img class="sutore-mp-staff-order-product-thumb" src="' + esc(thumb) + '" alt="" loading="lazy" />'
        : '<span class="sutore-mp-staff-order-product-thumb is-empty" aria-hidden="true"></span>';
      var meta = [];
      if (item.merchant_name) {
        meta.push(merchantDeeplinkHtml(item.merchant_name, item.merchant_id));
      }
      if (item.size_label) {
        meta.push(esc(item.size_label));
      }
      var linkable = !!(item.is_marketplace || item.listing_status);
      if (item.variation_id) {
        meta.push(productDeeplinkHtml('#' + String(item.variation_id), variationId, linkable));
      }
      listHtml +=
        '<li class="sutore-mp-staff-order-product' +
        pendingClass +
        '" data-variation-id="' +
        esc(String(variationId)) +
        '" data-order-item-id="' +
        esc(String(orderItemId)) +
        '" data-can-swap="' +
        (item.can_swap ? '1' : '0') +
        '" data-can-detach="' +
        (item.can_detach ? '1' : '0') +
        '" data-can-remove="' +
        (item.can_remove ? '1' : '0') +
        '">' +
        media +
        '<div class="sutore-mp-staff-order-product-body">' +
        '<div class="sutore-mp-staff-order-product-title">' +
        productDeeplinkHtml(item.name || '', variationId, linkable) +
        '</div>' +
        (meta.length ? '<div class="sutore-mp-staff-sub">' + meta.join(' · ') + '</div>' : '') +
        pendingBadge +
        invoiceLinksHtml(item.invoices) +
        '<div class="sutore-mp-staff-order-product-status">' +
        (item.listing_status_label
          ? statusTag(item.listing_status, item.listing_status_label)
          : '<span class="sutore-mp-staff-sub">' + esc(t('noListing', 'Not a marketplace product')) + '</span>') +
        '</div></div>' +
        '<div class="sutore-mp-staff-order-product-meta">' +
        '<span>×' +
        esc(String(item.quantity || 1)) +
        '</span>' +
        priceMetaHtml(item) +
        '</div>' +
        itemActionsHtml(item, canEdit, pending) +
        '</li>';
    });

    if (canEdit) {
      pending.adds.forEach(function (item) {
        if (!parseInt(item.replaces_order_item_id, 10)) {
          listHtml += pendingProductRowHtml(item, false);
        }
      });
    }

    if (!items.length && !(editing && pending.adds.length)) {
      return (
        '<section class="sutore-mp-staff-order-products">' +
        title +
        '<p class="sutore-mp-empty">' +
        esc(t('noProducts', 'No products on this order.')) +
        '</p></section>'
      );
    }

    return (
      '<section class="sutore-mp-staff-order-products">' +
      title +
      '<ul class="sutore-mp-staff-order-product-list">' +
      listHtml +
      '</ul></section>'
    );
  }

  function formatMoneyPreview(amount, data) {
    amount = Math.round((parseFloat(amount) || 0) * 100) / 100;
    var currency = String((data && data.currency) || 'TRY').toUpperCase();
    try {
      return new Intl.NumberFormat(document.documentElement.lang || 'tr-TR', {
        style: 'currency',
        currency: currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      }).format(amount);
    } catch (err) {
      return amount.toFixed(2) + ' ' + currency;
    }
  }

  function previewTotals(data, pending) {
    pending = pending || emptyPendingEdits();
    if (!pendingCounts(pending).total) {
      return data;
    }

    var removedItemIds = {};
    pending.removes.forEach(function (row) {
      var id = parseInt(row.order_item_id, 10) || 0;
      if (id) {
        removedItemIds[id] = true;
      }
    });
    pending.detaches.forEach(function (row) {
      var id = parseInt(row.order_item_id, 10) || 0;
      if (id) {
        removedItemIds[id] = true;
      }
    });

    var subtotal = 0;
    var itemCount = 0;
    var hizmetTotal = 0;
    var guvenceTotal = 0;
    var priceDiff = 0;
    (data.line_items || []).forEach(function (item) {
      var itemId = parseInt(item.order_item_id, 10) || 0;
      if (removedItemIds[itemId] || pendingReplacementForItem(pending, itemId)) {
        return;
      }
      subtotal += parseFloat(item.total) || 0;
      itemCount += parseInt(item.quantity, 10) || 1;
      hizmetTotal += parseFloat(item.hizmet_fee) || 0;
      guvenceTotal += parseFloat(item.guvence_fee) || 0;
    });

    pending.adds.forEach(function (row) {
      var customer = parseFloat(row.customer_price);
      if (!(customer > 0)) {
        customer = parseFloat(row.asking) || 0;
      }
      subtotal += customer > 0 ? customer : 0;
      itemCount += 1;
      hizmetTotal += parseFloat(row.hizmet_fee) || 0;
      guvenceTotal += parseFloat(row.guvence_fee) || 0;

      var replacesItemId = parseInt(row.replaces_order_item_id, 10) || 0;
      if (replacesItemId) {
        var oldPrice = parseFloat(row.replaces_customer_price);
        if (!(oldPrice > 0)) {
          var oldItem = null;
          (data.line_items || []).some(function (item) {
            if (parseInt(item.order_item_id, 10) === replacesItemId) {
              oldItem = item;
              return true;
            }
            return false;
          });
          if (oldItem) {
            oldPrice = parseFloat(oldItem.total) || parseFloat(oldItem.unit_total) || 0;
          }
        }
        if (oldPrice > 0 && customer > 0) {
          priceDiff += customer - oldPrice;
        }
      }
    });

    var persistedDiff = parseFloat(data.price_difference) || 0;
    var displayDiff = Math.round((persistedDiff + priceDiff) * 100) / 100;

    var shipping = parseFloat(data.shipping_total) || 0;
    var discount = parseFloat(data.discount_total) || 0;
    var tax = parseFloat(data.tax_total) || 0;
    var feesTotal = 0;
    (data.fees || []).forEach(function (fee) {
      feesTotal += parseFloat(fee.total) || 0;
    });
    var total = Math.max(0, subtotal + shipping + feesTotal + tax - discount);

    return Object.assign({}, data, {
      item_count: itemCount,
      subtotal: subtotal,
      subtotal_display: formatMoneyPreview(subtotal, data),
      hizmet_total: hizmetTotal,
      hizmet_total_display: formatMoneyPreview(hizmetTotal, data),
      guvence_total: guvenceTotal,
      guvence_total_display: formatMoneyPreview(guvenceTotal, data),
      price_difference: displayDiff,
      price_difference_display: formatMoneyPreview(displayDiff, data),
      total: total,
      total_display: formatMoneyPreview(total, data),
      totals_estimated: true
    });
  }

  function renderTotalsSection(data) {
    var title =
      esc(t('orderTotals', 'Order totals')) +
      (data.totals_estimated
        ? ' <span class="sutore-mp-staff-sub">(' + esc(t('estimatedTotals', 'Estimated')) + ')</span>'
        : '');
    var rows = kvRow(t('subtotal', 'Subtotal'), esc(dash(data.subtotal_display)));
    if (parseFloat(data.hizmet_total) > 0) {
      rows += kvRow(t('serviceFee', 'Service fee'), esc(dash(data.hizmet_total_display)));
    }
    if (parseFloat(data.guvence_total) > 0) {
      rows += kvRow(t('guaranteeFee', 'Guarantee fee'), esc(dash(data.guvence_total_display)));
    }
    var priceDiff = parseFloat(data.price_difference) || 0;
    if (Math.abs(priceDiff) >= 0.005) {
      rows += kvRow(
        t('priceDifference', 'Price difference'),
        esc(data.price_difference_display || formatMoneyPreview(priceDiff, data))
      );
    }
    rows += kvRow(t('shipping', 'Shipping'), esc(dash(data.shipping_total_display)));
    var coupons = data.coupons || [];
    if (coupons.length) {
      coupons.forEach(function (coupon) {
        rows += kvRow(
          (t('coupon', 'Coupon') || 'Coupon') + ' (' + (coupon.code || '') + ')',
          '−' + esc(dash(coupon.discount_display))
        );
      });
    } else if (parseFloat(data.discount_total) > 0) {
      rows += kvRow(t('discount', 'Discount'), '−' + esc(data.discount_total_display));
    }
    (data.fees || []).forEach(function (fee) {
      rows += kvRow(fee.name || t('fee', 'Fee'), esc(dash(fee.total_display)));
    });
    if (parseFloat(data.tax_total) > 0) {
      rows += kvRow(t('tax', 'Tax'), esc(dash(data.tax_total_display)));
    }
    rows += kvRow(t('total', 'Total'), esc(dash(data.total_display)));

    return (
      '<section class="sutore-mp-staff-order-totals' +
      (data.totals_estimated ? ' is-estimated' : '') +
      '">' +
      '<h3 class="sutore-mp-staff-order-section-title">' +
      title +
      '</h3><dl class="sutore-mp-manage-kv">' +
      rows +
      '</dl></section>'
    );
  }

  function renderAddressBlock(title, address) {
    address = address || {};
    var lines = [];
    if (address.name) {
      lines.push(esc(address.name));
    }
    if (address.company) {
      lines.push(esc(address.company));
    }
    if (address.formatted) {
      lines.push(esc(address.formatted).replace(/\n/g, '<br>'));
    }
    if (address.email) {
      lines.push(esc(address.email));
    }
    if (address.phone) {
      lines.push(esc(address.phone));
    }
    return (
      '<section class="sutore-mp-staff-order-address">' +
      '<h3 class="sutore-mp-staff-order-section-title">' +
      esc(title) +
      '</h3>' +
      (lines.length
        ? '<p class="sutore-mp-staff-order-address-text">' + lines.join('<br>') + '</p>'
        : '<p class="sutore-mp-empty">' + esc(t('noAddress', 'No address.')) + '</p>') +
      '</section>'
    );
  }

  function renderDetail(data, editing, pending) {
    editing = !!editing;
    pending = pending || emptyPendingEdits();
    var preview = previewTotals(data, pending);
    var summaryRows =
      kvRow(t('order', 'Order'), esc('#' + String(preview.number || preview.id || ''))) +
      kvRow(t('date', 'Date'), esc(dash(preview.date_created_display))) +
      kvRow(t('status', 'Status'), statusTag(preview.status, preview.status_label)) +
      kvRow(t('items', 'Items'), esc(String(preview.item_count || 0))) +
      kvRow(t('sellers', 'Sellers'), esc(sellerCountLabel(preview.seller_count))) +
      kvRow(
        t('shipmentType', 'Shipment type'),
        esc(dash(preview.shipment_type_label || preview.shipping_method_title))
      ) +
      kvRow(t('deliveryDeadline', 'Delivery deadline'), esc(dash(preview.delivery_deadline_display))) +
      kvRow(t('paymentMethod', 'Payment method'), esc(dash(preview.payment_method_title)));
    if (preview.customer_note) {
      summaryRows += kvRow(t('customerNote', 'Customer note'), esc(preview.customer_note));
    }

    return (
      '<article class="sutore-mp-staff-detail sutore-mp-staff-order-detail">' +
      '<section class="sutore-mp-staff-summary">' +
      '<h3 class="sutore-mp-staff-order-section-title">' +
      esc(t('details', 'Details')) +
      '</h3><dl class="sutore-mp-manage-kv">' +
      summaryRows +
      '</dl></section>' +
      renderProductsSection(data, editing, pending) +
      renderTotalsSection(preview) +
      '<div class="sutore-mp-staff-order-customer">' +
      // Billing and shipping are always the same — show one combined address block.
      renderAddressBlock(
        t('billingShippingAddress', 'Billing & shipping address'),
        preview.billing && (preview.billing.formatted || preview.billing.name)
          ? preview.billing
          : preview.shipping
      ) +
      '</div></article>'
    );
  }

  function closeStatusMenu($shell) {
    /* status uses a select; kept for shared close helpers */
  }

  function closeItemMenus($shell) {
    /* item actions are inline buttons */
  }

  function renderStatusSelect($shell, data) {
    if (data.status_labels && typeof data.status_labels === 'object') {
      statusLabelsCache = data.status_labels;
    }
    var current = String(data.status || '');
    var html = '';
    Object.keys(statusLabelsCache || {}).forEach(function (key) {
      html +=
        '<option value="' +
        esc(key) +
        '"' +
        (String(key) === current ? ' selected' : '') +
        '>' +
        esc(statusLabelsCache[key]) +
        '</option>';
    });
    var $field = $shell.find('.sutore-mp-staff-orders-status-field');
    var $select = $shell.find('.sutore-mp-staff-orders-status-select');
    $select.html(html).prop('disabled', !html);
    if (current) {
      $select.val(current);
    }
    $field.prop('hidden', !html);
  }

  function setDetailHeader($shell, data) {
    var title = (t('orderTitle', 'Order #%s') || 'Order #%s').replace(
      '%s',
      String(data.number || data.id || '')
    );
    var subParts = [];
    if (data.date_created_display) {
      subParts.push(data.date_created_display);
    }
    if (data.customer_name) {
      subParts.push(data.customer_name);
    }
    $shell.find('.sutore-mp-staff-detail-title').text(title);
    $shell.find('.sutore-mp-staff-detail-sub').text(subParts.join(' · '));
    var $badge = $shell.find('.sutore-mp-staff-detail-badge');
    if (data.status_label) {
      $badge
        .text(data.status_label)
        .removeAttr('hidden')
        .prop('hidden', false)
        .attr(
          'class',
          'sutore-mp-manage-modal__badge sutore-mp-staff-detail-badge sutore-mp-tag is-status-' +
            String(data.status || '').replace(/_/g, '-')
        );
    } else {
      $badge.text('').attr('hidden', 'hidden').prop('hidden', true);
    }
    renderStatusSelect($shell, data);
    var $edit = $shell.find('.sutore-mp-staff-orders-edit-toggle');
    var $cancel = $shell.find('.sutore-mp-staff-orders-edit-cancel');
    $edit.prop('hidden', false);
    if (!data.can_edit_items && isEditing($shell)) {
      $cancel.prop('hidden', true);
      $shell.find('.sutore-mp-staff-orders-modal').attr('data-editing', '0');
      clearPendingEdits($shell);
    }
    $edit.text(t('updateOrder', 'Update order'));
  }

  function closeOrderModal($shell, options) {
    options = options || {};
    $shell = $shell && $shell.length ? $shell : $orderDetailHost();
    closeStatusMenu($shell);
    closeItemMenus($shell);
    closeSwapModal($shell);
    closeDetachModal($shell);
    closeAttachModal($shell);
    closeApplyModal($shell);
    clearReplaceTarget($shell);
    clearPendingEdits($shell);
    $shell.find('.sutore-mp-staff-orders-modal').attr('data-editing', '0');
    $shell.find('.sutore-mp-staff-orders-edit-toggle').text(t('updateOrder', 'Update order')).prop('hidden', true);
    $shell.find('.sutore-mp-staff-orders-edit-cancel').prop('hidden', true);
    $shell.find('.sutore-mp-staff-orders-status-field').prop('hidden', true);
    $shell.find('.sutore-mp-staff-orders-status-select').prop('disabled', true).empty();
    var $overlay = $shell.find('.sutore-mp-staff-orders-overlay');
    $overlay.prop('hidden', true).removeClass('is-open');
    $shell.find('.sutore-mp-staff-detail-panels').empty();
    $shell.removeData('currentOrder');
    if (!otherStaffOverlaysOpen()) {
      $('body').removeClass('sutore-mp-modal-open');
    }
    var shouldSync =
      options.syncUrl === true ||
      (options.syncUrl !== false && isOrdersListPage());
    if (shouldSync) {
      var $root = $ordersListRoot();
      if ($root.length) {
        syncListUrl(String($root.data('baseUrl') || ''), readListState($root));
      }
      $('.sutore-mp-staff-orders')
        .not('.sutore-mp-staff-order-detail-host')
        .first()
        .attr('data-open-order-id', '0');
    }
  }

  function reloadOrderDetail($shell, orderId) {
    $shell = $shell && $shell.length ? $shell : $orderDetailHost();
    var $root = $shell.find('.sutore-mp-staff-detail-root');
    var $panels = $shell.find('.sutore-mp-staff-detail-panels');
    var $loading = $shell.find('.sutore-mp-staff-manage-loading');
    $root.attr('aria-busy', 'true');
    $loading.prop('hidden', false);
    return ajax('GET', 'admin/orders/' + orderId)
      .done(function (res) {
        $loading.prop('hidden', true);
        $root.attr('aria-busy', 'false');
        if (!res || !res.success || !res.data) {
          $panels.html(
            '<p class="sutore-mp-error">' + esc((res && res.message) || t('error', 'Error')) + '</p>'
          );
          return;
        }
        $shell.data('currentOrder', res.data);
        setDetailHeader($shell, res.data);
        $panels.html(renderDetail(res.data, isEditing($shell), getPendingEdits($shell)));
      })
      .fail(function (xhr) {
        $loading.prop('hidden', true);
        $root.attr('aria-busy', 'false');
        $panels.html(
          '<p class="sutore-mp-error">' +
            esc((xhr.responseJSON && xhr.responseJSON.message) || t('error', 'Error')) +
            '</p>'
        );
      });
  }

  function openOrderModal($shellOrId, orderIdOrOptions, maybeOptions) {
    var $shell;
    var orderId;
    var options;
    if (typeof $shellOrId === 'number' || (typeof $shellOrId === 'string' && /^\d+$/.test(String($shellOrId)))) {
      orderId = parseInt($shellOrId, 10) || 0;
      options = orderIdOrOptions || {};
      $shell = $orderDetailHost();
    } else {
      $shell = $orderDetailHost().length ? $orderDetailHost() : $shellOrId;
      orderId = parseInt(orderIdOrOptions, 10) || 0;
      options = maybeOptions || {};
    }
    if (!orderId || !$shell.find('.sutore-mp-staff-orders-overlay').length) {
      return;
    }
    var $overlay = $shell.find('.sutore-mp-staff-orders-overlay');
    $overlay.prop('hidden', false).addClass('is-open');
    $('body').addClass('sutore-mp-modal-open');
    clearPendingEdits($shell);
    $shell.find('.sutore-mp-staff-orders-modal').attr('data-editing', '0');
    $shell.find('.sutore-mp-staff-orders-edit-toggle').text(t('updateOrder', 'Update order'));
    $shell.find('.sutore-mp-staff-orders-edit-cancel').prop('hidden', true);
    var shouldSync =
      options.syncUrl === true ||
      (options.syncUrl !== false && isOrdersListPage());
    if (shouldSync) {
      var baseUrl = String($ordersListRoot().data('baseUrl') || cfg.manageOrdersUrl || '');
      try {
        var method = options.replaceUrl ? 'replaceState' : 'pushState';
        window.history[method]({}, '', detailUrl(baseUrl, orderId));
      } catch (err) {
        /* ignore */
      }
    }
    reloadOrderDetail($shell, orderId);
  }

  function isStaffOrderOpen() {
    var $overlay = $orderDetailHost().find('.sutore-mp-staff-orders-overlay');
    return $overlay.length > 0 && $overlay.hasClass('is-open') && !$overlay.prop('hidden');
  }

  window.SutoreMarketplace = window.SutoreMarketplace || {};
  SutoreMarketplace.openStaffOrder = function (orderId, options) {
    openOrderModal(orderId, options || { syncUrl: false });
  };
  SutoreMarketplace.closeStaffOrder = function (options) {
    closeOrderModal($orderDetailHost(), options || { syncUrl: false });
  };
  SutoreMarketplace.isStaffOrderOpen = isStaffOrderOpen;

  function resetStatusSelect($shell) {
    var order = $shell.data('currentOrder') || {};
    var current = String(order.status || '');
    if (current) {
      $shell.find('.sutore-mp-staff-orders-status-select').val(current);
    }
  }

  function formatMoneyAmount(amount) {
    var n = Math.abs(parseFloat(amount) || 0);
    return (
      n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' TL'
    );
  }

  function formatPriceDiff(currentAsking, newAsking) {
    var diff = (parseFloat(newAsking) || 0) - (parseFloat(currentAsking) || 0);
    if (Math.abs(diff) < 0.005) {
      return {
        html:
          '<p class="sutore-mp-staff-orders-swap-diff-text">' +
          esc(t('noPriceDiff', 'No price difference.')) +
          '</p>',
        hasDiff: false
      };
    }
    var display = formatMoneyAmount(diff);
    var msg =
      diff > 0
        ? (t('priceHigher', 'Replacement is %s higher.') || 'Replacement is %s higher.').replace(
            '%s',
            display
          )
        : (t('priceLower', 'Replacement is %s lower.') || 'Replacement is %s lower.').replace(
            '%s',
            display
          );
    return {
      html:
        '<p class="sutore-mp-staff-orders-swap-diff-text' +
        (diff > 0 ? ' is-higher' : ' is-lower') +
        '">' +
        esc(msg) +
        '</p>',
      hasDiff: true
    };
  }

  function closeSwapModal($shell) {
    clearTimeout(swapSearchTimer);
    var $overlay = $shell.find('.sutore-mp-staff-orders-swap-overlay');
    $overlay.prop('hidden', true).removeClass('is-open');
    $shell.removeData('swapCurrentItem');
    $shell.removeData('swapSelected');
    $shell.find('.sutore-mp-staff-orders-swap-search').val('');
    $shell.find('.sutore-mp-staff-orders-swap-note').val('');
    clearModalAlert($overlay);
    resetReturnToQueue($overlay);
    $shell.find('.sutore-mp-staff-orders-swap-diff').prop('hidden', true).empty();
    $shell
      .find('.sutore-mp-staff-orders-swap-replacement')
      .html(
        '<p class="sutore-mp-empty">' +
          esc(t('selectReplacement', 'Select a replacement product.')) +
          '</p>'
      );
    $shell.find('.sutore-mp-staff-orders-swap-candidates').empty();
    $shell.find('.sutore-mp-staff-orders-swap-confirm').prop('disabled', true);
    if (
      !$shell.find('.sutore-mp-staff-orders-overlay.is-open').length &&
      !$shell.find('.sutore-mp-staff-orders-detach-overlay.is-open').length
    ) {
      /* keep body class from parent order modal */
    }
  }

  function renderSwapCandidates($shell, items) {
    var $box = $shell.find('.sutore-mp-staff-orders-swap-candidates');
    if (!items || !items.length) {
      $box.html('<p class="sutore-mp-empty">' + esc(t('noCandidates', 'No eligible products found.')) + '</p>');
      return;
    }
    var selected = $shell.data('swapSelected') || {};
    var html = '<ul class="sutore-mp-staff-orders-candidate-list">';
    items.forEach(function (item) {
      var id = parseInt(item.variation_id || item.id, 10) || 0;
      var isSelected = selected && parseInt(selected.variation_id || selected.id, 10) === id;
      html +=
        '<li><button type="button" class="sutore-mp-staff-orders-candidate' +
        (isSelected ? ' is-selected' : '') +
        '" role="option" aria-selected="' +
        (isSelected ? 'true' : 'false') +
        '" data-candidate="' +
        encodeURIComponent(JSON.stringify(item)) +
        '">' +
        productPreviewHtml(
          {
            thumbnail: item.thumbnail,
            name: item.product_title || item.label,
          merchant_name: item.merchant_name,
          merchant_id: item.merchant_id,
          size_label: item.size_label,
          variation_id: id,
          asking_display: item.asking_display,
          customer_price_display: item.customer_price_display
        },
        ''
      ) +
      '</button></li>';
    });
    html += '</ul>';
    $box.html(html);
  }

  function loadSwapCandidates($shell, search) {
    var item = $shell.data('swapCurrentItem') || {};
    var variationId = parseInt(item.variation_id, 10) || 0;
    if (!variationId) {
      return;
    }
    var $box = $shell.find('.sutore-mp-staff-orders-swap-candidates');
    $box.html(loadingHtml());
    var params = { per_page: 30 };
    if (search) {
      params.search = search;
    }
    ajax('GET', 'fulfillments/' + variationId + '/swap-candidates', params)
      .done(function (res) {
        if (!res || !res.success || !res.data) {
          $box.html(
            '<p class="sutore-mp-error">' + esc((res && res.message) || t('error', 'Error')) + '</p>'
          );
          return;
        }
        renderSwapCandidates($shell, res.data.items || []);
      })
      .fail(function (xhr) {
        $box.html(
          '<p class="sutore-mp-error">' +
            esc((xhr.responseJSON && xhr.responseJSON.message) || t('error', 'Error')) +
            '</p>'
        );
      });
  }

  function selectSwapCandidate($shell, candidate) {
    $shell.data('swapSelected', candidate);
    var current = $shell.data('swapCurrentItem') || {};
    $shell.find('.sutore-mp-staff-orders-swap-replacement').html(
      productPreviewHtml(
        {
          thumbnail: candidate.thumbnail,
          name: candidate.product_title || candidate.label,
          merchant_name: candidate.merchant_name,
          merchant_id: candidate.merchant_id,
          size_label: candidate.size_label,
          variation_id: candidate.variation_id || candidate.id,
          asking_display: candidate.asking_display,
          customer_price_display: candidate.customer_price_display
        },
        ''
      )
    );
    // Compare fee-inclusive customer prices so the diff matches the order line total.
    var currentPrice = current.unit_total || current.total || current.asking;
    var candidatePrice = candidate.customer_price != null ? candidate.customer_price : candidate.asking;
    var diff = formatPriceDiff(currentPrice, candidatePrice);
    $shell.find('.sutore-mp-staff-orders-swap-diff').html(diff.html).prop('hidden', false);
    $shell.find('.sutore-mp-staff-orders-swap-confirm').prop('disabled', false);
    $shell.find('.sutore-mp-staff-orders-candidate').removeClass('is-selected').attr('aria-selected', 'false');
    $shell
      .find('.sutore-mp-staff-orders-candidate')
      .filter(function () {
        try {
          var raw = $(this).attr('data-candidate') || '';
          var parsed = JSON.parse(decodeURIComponent(raw || '%7B%7D'));
          return parseInt(parsed.variation_id || parsed.id, 10) === parseInt(candidate.variation_id || candidate.id, 10);
        } catch (e) {
          return false;
        }
      })
      .addClass('is-selected')
      .attr('aria-selected', 'true');
  }

  function openSwapModal($shell, item) {
    closeItemMenus($shell);
    closeDetachModal($shell);
    closeAttachModal($shell);
    $shell.data('swapCurrentItem', item);
    $shell.removeData('swapSelected');
    $shell
      .find('.sutore-mp-staff-orders-swap-sub')
      .text(item.name || '#' + String(item.variation_id || ''));
    $shell.find('.sutore-mp-staff-orders-swap-current').html(productPreviewHtml(item, ''));
    $shell
      .find('.sutore-mp-staff-orders-swap-replacement')
      .html(
        '<p class="sutore-mp-empty">' +
          esc(t('selectReplacement', 'Select a replacement product.')) +
          '</p>'
      );
    $shell.find('.sutore-mp-staff-orders-swap-diff').prop('hidden', true).empty();
    $shell.find('.sutore-mp-staff-orders-swap-note').val('');
    clearModalAlert($shell.find('.sutore-mp-staff-orders-swap-overlay'));
    resetReturnToQueue($shell.find('.sutore-mp-staff-orders-swap-overlay'));
    $shell.find('.sutore-mp-staff-orders-swap-search').val('');
    $shell.find('.sutore-mp-staff-orders-swap-confirm').prop('disabled', true);
    $shell.find('.sutore-mp-staff-orders-swap-overlay').prop('hidden', false).addClass('is-open');
    $('body').addClass('sutore-mp-modal-open');
    loadSwapCandidates($shell, '');
  }

  function confirmSwap($shell) {
    var current = $shell.data('swapCurrentItem') || {};
    var selected = $shell.data('swapSelected') || {};
    var variationId = parseInt(current.variation_id, 10) || 0;
    var newId = parseInt(selected.variation_id || selected.id, 10) || 0;
    var note = String($shell.find('.sutore-mp-staff-orders-swap-note').val() || '').trim();
    var $overlay = $shell.find('.sutore-mp-staff-orders-swap-overlay');
    var returnToQueue = returnToQueueChecked($overlay);
    clearModalAlert($overlay);
    if (!variationId || !newId) {
      setModalAlert($overlay, t('selectReplacement', 'Select a replacement product.'));
      return;
    }
    if (selected.same_parent === false && !note) {
      setModalAlert(
        $overlay,
        t(
          'differentProductNoteRequired',
          'A staff note is required when replacing with a different product.'
        )
      );
      return;
    }
    stageSwap($shell, current, selected, note, returnToQueue);
    closeSwapModal($shell);
  }

  function stageSwap($shell, current, candidate, note, returnToQueue) {
    var oldVariationId = parseInt(current.variation_id, 10) || 0;
    var orderItemId = parseInt(current.order_item_id, 10) || 0;
    var variationId = parseInt(candidate.variation_id || candidate.id, 10) || 0;
    if (!oldVariationId || !variationId) {
      return;
    }
    ensureEditing($shell);
    var pending = getPendingEdits($shell);
    pending.adds = pending.adds.filter(function (row) {
      return parseInt(row.replaces_order_item_id, 10) !== orderItemId;
    });
    pending.detaches = pending.detaches.filter(function (row) {
      return parseInt(row.order_item_id, 10) !== orderItemId;
    });
    pending.removes = pending.removes.filter(function (row) {
      return parseInt(row.order_item_id, 10) !== orderItemId;
    });
    pending.adds.push({
      variation_id: variationId,
      name:
        candidate.product_title || candidate.label || candidate.name || '#' + String(variationId),
      product_title: candidate.product_title || candidate.label || '',
      thumbnail: candidate.thumbnail || '',
      merchant_name: candidate.merchant_name || '',
      merchant_id: parseInt(candidate.merchant_id, 10) || 0,
      size_label: candidate.size_label || '',
      asking: candidate.asking || 0,
      asking_display: candidate.asking_display || '',
      customer_price: candidate.customer_price || 0,
      customer_price_display: candidate.customer_price_display || '',
      hizmet_fee: candidate.hizmet_fee || 0,
      guvence_fee: candidate.guvence_fee || 0,
      listing_status: candidate.listing_status || '',
      listing_status_label: candidate.listing_status_label || '',
      replaces_order_item_id: orderItemId,
      replaces_variation_id: oldVariationId,
      replaces_name: current.name || '#' + String(oldVariationId),
      replaces_customer_price:
        parseFloat(current.total) ||
        parseFloat(current.unit_total) ||
        parseFloat(current.customer_price) ||
        0,
      swap_from_variation_id: oldVariationId,
      staff_note: note || '',
      return_to_queue: !!returnToQueue
    });
    $shell.data('pendingEdits', pending);
    syncEditCancelVisibility($shell);
    refreshDetailPanels($shell);
  }

  function closeDetachModal($shell) {
    var $overlay = $shell.find('.sutore-mp-staff-orders-detach-overlay');
    $overlay.prop('hidden', true).removeClass('is-open');
    $shell.removeData('detachItem');
    $shell.find('.sutore-mp-staff-orders-detach-note').val('');
    clearModalAlert($overlay);
    resetReturnToQueue($overlay);
  }

  function openDetachModal($shell, item) {
    closeItemMenus($shell);
    closeSwapModal($shell);
    closeAttachModal($shell);
    $shell.data('detachItem', item);
    $shell
      .find('.sutore-mp-staff-orders-detach-sub')
      .text(item.name || '#' + String(item.variation_id || ''));
    $shell.find('.sutore-mp-staff-orders-detach-note').val('');
    clearModalAlert($shell.find('.sutore-mp-staff-orders-detach-overlay'));
    resetReturnToQueue($shell.find('.sutore-mp-staff-orders-detach-overlay'));
    $shell.find('.sutore-mp-staff-orders-detach-overlay').prop('hidden', false).addClass('is-open');
    $('body').addClass('sutore-mp-modal-open');
  }

  function confirmDetach($shell) {
    var item = $shell.data('detachItem') || {};
    var variationId = parseInt(item.variation_id, 10) || 0;
    var note = String($shell.find('.sutore-mp-staff-orders-detach-note').val() || '').trim();
    var $overlay = $shell.find('.sutore-mp-staff-orders-detach-overlay');
    var returnToQueue = returnToQueueChecked($overlay);
    clearModalAlert($overlay);
    if (!variationId) {
      return;
    }
    if (!note) {
      setModalAlert($overlay, t('staffNoteRequired', 'A staff note is required for this action.'));
      return;
    }
    ensureEditing($shell);
    var pending = getPendingEdits($shell);
    pending.detaches = pending.detaches.filter(function (row) {
      return parseInt(row.variation_id, 10) !== variationId;
    });
    pending.removes = pending.removes.filter(function (row) {
      return parseInt(row.order_item_id, 10) !== (parseInt(item.order_item_id, 10) || 0);
    });
    pending.detaches.push({
      variation_id: variationId,
      order_item_id: parseInt(item.order_item_id, 10) || 0,
      name: item.name || '#' + String(variationId),
      staff_note: note,
      return_to_queue: returnToQueue
    });
    $shell.data('pendingEdits', pending);
    closeDetachModal($shell);
    syncEditCancelVisibility($shell);
    refreshDetailPanels($shell);
  }

  function closeApplyModal($shell) {
    var $overlay = $shell.find('.sutore-mp-staff-orders-apply-overlay');
    $overlay.prop('hidden', true).removeClass('is-open');
    $shell.find('.sutore-mp-staff-orders-apply-confirm').prop('disabled', false);
  }

  function confirmProductHtml(action, name, variationId, secondary, tone) {
    var id = parseInt(variationId, 10) || 0;
    var title = String(name || '').trim() || (id ? '#' + String(id) : '—');
    return (
      '<li class="sutore-mp-staff-orders-apply-item' +
      (tone ? ' is-' + tone : '') +
      '">' +
      '<div class="sutore-mp-staff-orders-apply-row">' +
      '<span class="sutore-mp-staff-orders-apply-action">' +
      esc(action) +
      '</span>' +
      '<span class="sutore-mp-staff-orders-apply-name">' +
      esc(title) +
      '</span>' +
      (id ? '<span class="sutore-mp-staff-orders-apply-id">#' + esc(String(id)) + '</span>' : '') +
      '</div>' +
      (secondary
        ? '<div class="sutore-mp-staff-orders-apply-meta">' + esc(secondary) + '</div>'
        : '') +
      '</li>'
    );
  }

  function isReplacementRemoval(pending, row) {
    var itemId = parseInt(row.order_item_id, 10) || 0;
    return pending.adds.some(function (add) {
      return itemId > 0 && parseInt(add.replaces_order_item_id, 10) === itemId;
    });
  }

  function openApplyModal($shell) {
    var pending = getPendingEdits($shell);
    var counts = pendingCounts(pending);
    var status = pendingStatus($shell);
    if (!counts.total && !status) {
      showAlert(t('updateOrder', 'Update order'), t('noPendingChanges', 'No changes to apply.'));
      return;
    }
    closeDetachModal($shell);
    closeSwapModal($shell);
    var lines = [];
    if (status) {
      var statusLabel = (statusLabelsCache && statusLabelsCache[status]) || status;
      lines.push(
        confirmProductHtml(
          t('status', 'Status'),
          (t('willChangeStatus', 'Status will change to %s') || 'Status will change to %s').replace(
            '%s',
            statusLabel
          ),
          0,
          '',
          'status'
        )
      );
    }
    pending.adds.forEach(function (row) {
      var replacementId = parseInt(row.replaces_variation_id, 10) || 0;
      if (parseInt(row.replaces_order_item_id, 10)) {
        var oldName = String(row.replaces_name || '').trim() || (replacementId ? '#' + String(replacementId) : '—');
        var oldLabel = oldName + (replacementId ? ' · #' + String(replacementId) : '');
        lines.push(
          confirmProductHtml(
            t('updateProduct', 'Update product'),
            row.name || row.product_title,
            row.variation_id,
            (t('replacesProduct', 'Replaces: %s') || 'Replaces: %s').replace('%s', oldLabel),
            'update'
          )
        );
      } else {
        lines.push(
          confirmProductHtml(
            t('addProduct', 'Add product'),
            row.name || row.product_title,
            row.variation_id,
            '',
            'add'
          )
        );
      }
    });
    pending.detaches.forEach(function (row) {
      if (!isReplacementRemoval(pending, row)) {
        lines.push(
          confirmProductHtml(t('detach', 'Detach from order'), row.name, row.variation_id, '', 'remove')
        );
      }
    });
    pending.removes.forEach(function (row) {
      if (!isReplacementRemoval(pending, row)) {
        lines.push(confirmProductHtml(t('removeFromOrder', 'Remove'), row.name, row.variation_id, '', 'remove'));
      }
    });
    $shell.find('.sutore-mp-staff-orders-apply-summary').html(lines.join(''));
    $shell.find('.sutore-mp-staff-orders-apply-overlay').prop('hidden', false).addClass('is-open');
    $('body').addClass('sutore-mp-modal-open');
  }

  function runQueue(steps) {
    var chain = $.Deferred().resolve();
    steps.forEach(function (step) {
      chain = chain.then(step);
    });
    return chain;
  }

  function applyPendingEdits($shell) {
    var order = $shell.data('currentOrder') || {};
    var orderId = parseInt(order.id || order.order_id, 10) || 0;
    var pending = getPendingEdits($shell);
    var counts = pendingCounts(pending);
    var status = pendingStatus($shell);
    var $btn = $shell.find('.sutore-mp-staff-orders-apply-confirm');
    if (!orderId || (!counts.total && !status)) {
      return;
    }
    $btn.prop('disabled', true);

    var steps = [];
    if (status) {
      steps.push(function () {
        return ajax('POST', 'admin/orders/' + orderId + '/status', { status: status }).then(
          function (res) {
            if (!res || !res.success) {
              return $.Deferred().reject({ message: (res && res.message) || t('error', 'Error') });
            }
            return res;
          },
          function (xhr) {
            return $.Deferred().reject({
              message: (xhr.responseJSON && xhr.responseJSON.message) || t('error', 'Error')
            });
          }
        );
      });
    }
    pending.adds.forEach(function (row) {
      var variationId = parseInt(row.variation_id, 10) || 0;
      var swapFrom = parseInt(row.swap_from_variation_id, 10) || 0;
      steps.push(function () {
        var request = swapFrom
          ? ajax('POST', 'fulfillments/' + swapFrom + '/actions', {
              workflow_action: 'swap',
              new_variation_id: variationId,
              staff_note: String(row.staff_note || ''),
              return_to_queue: row.return_to_queue !== false
            })
          : ajax('POST', 'admin/orders/' + orderId + '/attach', { variation_id: variationId });
        return request.then(
          function (res) {
            if (!res || !res.success) {
              return $.Deferred().reject({ message: (res && res.message) || t('error', 'Error') });
            }
            return res;
          },
          function (xhr) {
            return $.Deferred().reject({
              message: (xhr.responseJSON && xhr.responseJSON.message) || t('error', 'Error')
            });
          }
        );
      });
    });
    pending.detaches.forEach(function (row) {
      var variationId = parseInt(row.variation_id, 10) || 0;
      var note = String(row.staff_note || '');
      var returnToQueue = row.return_to_queue !== false && row.return_to_queue !== 0 && row.return_to_queue !== '0';
      steps.push(function () {
        return ajax('POST', 'fulfillments/' + variationId + '/actions', {
          workflow_action: 'split',
          staff_note: note,
          return_to_queue: returnToQueue
        }).then(
          function (res) {
            if (!res || !res.success) {
              return $.Deferred().reject({ message: (res && res.message) || t('error', 'Error') });
            }
            return res;
          },
          function (xhr) {
            return $.Deferred().reject({
              message: (xhr.responseJSON && xhr.responseJSON.message) || t('error', 'Error')
            });
          }
        );
      });
    });
    pending.removes.forEach(function (row) {
      var itemId = parseInt(row.order_item_id, 10) || 0;
      steps.push(function () {
        return ajax('DELETE', 'admin/orders/' + orderId + '/items/' + itemId).then(
          function (res) {
            if (!res || !res.success) {
              return $.Deferred().reject({ message: (res && res.message) || t('error', 'Error') });
            }
            return res;
          },
          function (xhr) {
            return $.Deferred().reject({
              message: (xhr.responseJSON && xhr.responseJSON.message) || t('error', 'Error')
            });
          }
        );
      });
    });

    runQueue(steps)
      .done(function () {
        $btn.prop('disabled', false);
        closeApplyModal($shell);
        clearPendingEdits($shell);
        $shell.find('.sutore-mp-staff-orders-modal').attr('data-editing', '0');
        syncEditCancelVisibility($shell);
        $shell.find('.sutore-mp-staff-orders-edit-toggle').text(t('updateOrder', 'Update order'));
        showToast(t('orderUpdated', 'Order updated.'), 'success');
        reloadOrderDetail($shell, orderId);
        loadListRoot($shell.find('.sutore-mp-staff-orders-list-root'));
      })
      .fail(function (err) {
        $btn.prop('disabled', false);
        showAlert(t('error', 'Error'), (err && err.message) || t('error', 'Error'));
        clearPendingEdits($shell);
        reloadOrderDetail($shell, orderId).always(function () {
          $shell.find('.sutore-mp-staff-orders-modal').attr('data-editing', '1');
          syncEditCancelVisibility($shell);
          refreshDetailPanels($shell);
        });
        loadListRoot($shell.find('.sutore-mp-staff-orders-list-root'));
      });
  }

  function closeAttachModal($shell) {
    clearTimeout(addSearchTimer);
    var $overlay = $shell.find('.sutore-mp-staff-orders-attach-overlay');
    $overlay.prop('hidden', true).removeClass('is-open');
    $shell.removeData('attachSelected');
    $shell.find('.sutore-mp-staff-orders-attach-search').val('');
    $shell.find('.sutore-mp-staff-orders-attach-candidates').empty();
    $shell.find('.sutore-mp-staff-orders-attach-selected').empty();
    $shell.find('.sutore-mp-staff-orders-attach-body [data-role="selected"]').prop('hidden', true);
    $shell.find('.sutore-mp-staff-orders-attach-replace-opts').prop('hidden', true);
    $shell.find('.sutore-mp-staff-orders-attach-confirm').prop('disabled', true);
    clearModalAlert($overlay);
    resetReturnToQueue($overlay);
  }

  function openAttachModal($shell, opts) {
    opts = opts || {};
    closeItemMenus($shell);
    closeSwapModal($shell);
    closeDetachModal($shell);
    if (opts.replaceTarget) {
      $shell.data('replaceTarget', opts.replaceTarget);
    } else {
      clearReplaceTarget($shell);
    }
    $shell.removeData('attachSelected');
    var replaceTarget = $shell.data('replaceTarget') || null;
    var isReplace = !!replaceTarget;
    $shell
      .find('.sutore-mp-staff-orders-attach-title')
      .text(isReplace ? t('changeProduct', 'Change') : t('addProduct', 'Add product'));
    $shell
      .find('.sutore-mp-staff-orders-attach-sub')
      .text(
        isReplace
          ? replaceTarget.name || '#' + String(replaceTarget.variation_id || '')
          : ''
      );
    $shell
      .find('.sutore-mp-staff-orders-attach-confirm')
      .text(isReplace ? t('confirmChange', 'Confirm change') : t('addProduct', 'Add product'))
      .prop('disabled', true);
    $shell.find('.sutore-mp-staff-orders-attach-replace-opts').prop('hidden', !isReplace);
    clearModalAlert($shell.find('.sutore-mp-staff-orders-attach-overlay'));
    resetReturnToQueue($shell.find('.sutore-mp-staff-orders-attach-overlay'));
    $shell.find('.sutore-mp-staff-orders-attach-search').val('');
    $shell.find('.sutore-mp-staff-orders-attach-body [data-role="selected"]').prop('hidden', true);
    $shell.find('.sutore-mp-staff-orders-attach-overlay').prop('hidden', false).addClass('is-open');
    $('body').addClass('sutore-mp-modal-open');
    loadAttachCandidates($shell, '');
    window.setTimeout(function () {
      $shell.find('.sutore-mp-staff-orders-attach-search').trigger('focus');
    }, 0);
  }

  function selectAttachCandidate($shell, candidate) {
    $shell.data('attachSelected', candidate);
    clearModalAlert($shell.find('.sutore-mp-staff-orders-attach-overlay'));
    $shell.find('.sutore-mp-staff-orders-attach-body [data-role="selected"]').prop('hidden', false);
    $shell.find('.sutore-mp-staff-orders-attach-selected').html(
      productPreviewHtml(
        {
          thumbnail: candidate.thumbnail,
          name: candidate.product_title || candidate.name,
          merchant_name: candidate.merchant_name,
          merchant_id: candidate.merchant_id,
          size_label: candidate.size_label,
          variation_id: candidate.variation_id || candidate.id,
          asking_display: candidate.asking_display,
          customer_price_display: candidate.customer_price_display
        },
        ''
      )
    );
    $shell.find('.sutore-mp-staff-orders-attach-confirm').prop('disabled', false);
    $shell.find('.sutore-mp-staff-orders-attach-candidate').removeClass('is-selected');
    $shell
      .find('.sutore-mp-staff-orders-attach-candidate')
      .filter(function () {
        return (
          parseInt($(this).attr('data-variation-id'), 10) ===
          (parseInt(candidate.variation_id || candidate.id, 10) || 0)
        );
      })
      .addClass('is-selected');
  }

  function confirmAttach($shell) {
    var candidate = $shell.data('attachSelected') || null;
    var $overlay = $shell.find('.sutore-mp-staff-orders-attach-overlay');
    clearModalAlert($overlay);
    if (!candidate) {
      setModalAlert($overlay, t('selectReplacement', 'Select a replacement product.'));
      return;
    }
    var replaceTarget = $shell.data('replaceTarget') || null;
    var returnToQueue = replaceTarget ? returnToQueueChecked($overlay) : true;
    finishStageAttach($shell, candidate, replaceTarget, returnToQueue);
    closeAttachModal($shell);
  }

  function finishStageAttach($shell, candidate, replaceTarget, returnToQueue) {
    var variationId = parseInt(candidate.variation_id || candidate.id, 10) || 0;
    if (!variationId) {
      return;
    }
    ensureEditing($shell);
    var pending = getPendingEdits($shell);
    var replacementMeta = null;
    if (replaceTarget) {
      var replaceItemId = parseInt(replaceTarget.order_item_id, 10) || 0;
      var replaceVariationId = parseInt(replaceTarget.variation_id, 10) || 0;
      replacementMeta = {
        order_item_id: replaceItemId,
        variation_id: replaceVariationId,
        name: replaceTarget.name || '#' + String(replaceVariationId || replaceItemId),
        customer_price:
          parseFloat(replaceTarget.total) ||
          parseFloat(replaceTarget.unit_total) ||
          parseFloat(replaceTarget.customer_price) ||
          0
      };
      pending.removes = pending.removes.filter(function (row) {
        return parseInt(row.order_item_id, 10) !== replaceItemId;
      });
      pending.detaches = pending.detaches.filter(function (row) {
        return parseInt(row.order_item_id, 10) !== replaceItemId;
      });
      if (replaceTarget.can_detach && replaceVariationId) {
        pending.detaches.push({
          variation_id: replaceVariationId,
          order_item_id: replaceItemId,
          name: replaceTarget.name || '#' + String(replaceVariationId),
          staff_note: t('replacedByStaff', 'Replaced by staff.'),
          return_to_queue: !!returnToQueue
        });
      } else if (replaceItemId) {
        pending.removes.push({
          order_item_id: replaceItemId,
          variation_id: replaceVariationId,
          name: replaceTarget.name || '#' + String(replaceItemId)
        });
      }
      clearReplaceTarget($shell);
    }
    if (isPendingAdd(pending, variationId) || findLineItem($shell, variationId)) {
      $shell.data('pendingEdits', pending);
      syncEditCancelVisibility($shell);
      refreshDetailPanels($shell);
      return;
    }
    pending.adds.push({
      variation_id: variationId,
      name: candidate.product_title || candidate.name || '#' + String(variationId),
      product_title: candidate.product_title || candidate.name || '',
      thumbnail: candidate.thumbnail || '',
      merchant_name: candidate.merchant_name || '',
      merchant_id: parseInt(candidate.merchant_id, 10) || 0,
      size_label: candidate.size_label || '',
      asking: candidate.asking || 0,
      asking_display: candidate.asking_display || '',
      customer_price: candidate.customer_price || 0,
      customer_price_display: candidate.customer_price_display || '',
      hizmet_fee: candidate.hizmet_fee || 0,
      guvence_fee: candidate.guvence_fee || 0,
      listing_status: candidate.listing_status || '',
      listing_status_label: candidate.listing_status_label || '',
      replaces_order_item_id: replacementMeta ? replacementMeta.order_item_id : 0,
      replaces_variation_id: replacementMeta ? replacementMeta.variation_id : 0,
      replaces_name: replacementMeta ? replacementMeta.name : '',
      replaces_customer_price: replacementMeta ? replacementMeta.customer_price : 0
    });
    $shell.data('pendingEdits', pending);
    syncEditCancelVisibility($shell);
    refreshDetailPanels($shell);
  }

  function stageRemove($shell, item) {
    var orderItemId = parseInt(item.order_item_id, 10) || 0;
    if (!orderItemId) {
      return;
    }
    ensureEditing($shell);
    var pending = getPendingEdits($shell);
    pending.removes = pending.removes.filter(function (row) {
      return parseInt(row.order_item_id, 10) !== orderItemId;
    });
    pending.detaches = pending.detaches.filter(function (row) {
      return parseInt(row.order_item_id, 10) !== orderItemId;
    });
    pending.removes.push({
      order_item_id: orderItemId,
      variation_id: parseInt(item.variation_id, 10) || 0,
      name: item.name || '#' + String(orderItemId)
    });
    $shell.data('pendingEdits', pending);
    syncEditCancelVisibility($shell);
    refreshDetailPanels($shell);
  }

  function undoPending($shell, kind, variationId, orderItemId) {
    var pending = getPendingEdits($shell);
    variationId = parseInt(variationId, 10) || 0;
    orderItemId = parseInt(orderItemId, 10) || 0;
    if (kind === 'add') {
      pending.adds = pending.adds.filter(function (row) {
        return parseInt(row.variation_id, 10) !== variationId;
      });
    } else if (kind === 'replace') {
      pending.adds = pending.adds.filter(function (row) {
        return parseInt(row.replaces_order_item_id, 10) !== orderItemId;
      });
      pending.detaches = pending.detaches.filter(function (row) {
        return parseInt(row.order_item_id, 10) !== orderItemId;
      });
      pending.removes = pending.removes.filter(function (row) {
        return parseInt(row.order_item_id, 10) !== orderItemId;
      });
    } else if (kind === 'detach') {
      pending.detaches = pending.detaches.filter(function (row) {
        return parseInt(row.variation_id, 10) !== variationId;
      });
    } else if (kind === 'remove') {
      pending.removes = pending.removes.filter(function (row) {
        return parseInt(row.order_item_id, 10) !== orderItemId;
      });
    }
    $shell.data('pendingEdits', pending);
    syncEditCancelVisibility($shell);
    refreshDetailPanels($shell);
  }

  function runBulkStatus($root, status) {
    var ids = selectedOrderIds($root);
    if (!ids.length || !status) {
      return;
    }
    var confirmMsg = (
      t('bulkConfirm', 'Update status for %d orders?') || 'Update status for %d orders?'
    ).replace('%d', String(ids.length));
    if (!window.confirm(confirmMsg)) {
      return;
    }
    var $apply = $root.find('.sutore-mp-staff-bulk-apply');
    $apply.prop('disabled', true);
    ajax('POST', 'admin/orders/bulk-status', { ids: ids, status: status })
      .done(function (res) {
        $apply.prop('disabled', false);
        if (!res || !res.success) {
          showAlert(t('error', 'Error'), (res && res.message) || t('error', 'Error'));
          return;
        }
        loadListRoot($root);
      })
      .fail(function (xhr) {
        $apply.prop('disabled', false);
        showAlert(
          t('error', 'Error'),
          (xhr.responseJSON && xhr.responseJSON.message) || t('error', 'Error')
        );
      });
  }

  function loadListRoot($root, overrides) {
    var state = readListState($root, overrides);
    var $shell = $pageShell($root);
    var $chrome = $shell.find('.sutore-mp-list-chrome');
    $root.attr('aria-busy', 'true');
    $root.html(loadingHtml());
    var params = {
      page: state.page,
      per_page: state.perPage,
      orderby: state.orderby || 'date_desc'
    };
    if (state.search) {
      params.search = state.search;
    }
    if (state.status) {
      params.status = state.status;
    }
    ajax('GET', 'admin/orders', params)
      .done(function (res) {
        if (!res || !res.success || !res.data) {
          $root.attr('aria-busy', 'false').html(
            '<p class="sutore-mp-error">' +
              esc((res && res.message) || t('error', 'Error')) +
              '</p>'
          );
          $chrome.prop('hidden', false);
          return;
        }
        $root.data('search', state.search);
        $root.data('status', state.status);
        $root.data('orderby', state.orderby);
        $root.data('page', state.page);
        syncFilterFields($shell, state);
        updateListBadges($shell, state);
        syncListUrl(state.baseUrl, state);
        $root.attr('aria-busy', 'false').html(renderList(res.data, state));
        $chrome.prop('hidden', false);
        var openId = parseInt($shell.attr('data-open-order-id'), 10) || 0;
        if (openId > 0) {
          $shell.attr('data-open-order-id', '0');
          openOrderModal(openId, { replaceUrl: true, syncUrl: true });
        }
      })
      .fail(function (xhr) {
        $root.attr('aria-busy', 'false').html(
          '<p class="sutore-mp-error">' +
            esc((xhr.responseJSON && xhr.responseJSON.message) || t('error', 'Error')) +
            '</p>'
        );
        $chrome.prop('hidden', false);
      });
  }

  function findLineItem($shell, variationId) {
    var order = $shell.data('currentOrder') || {};
    var items = order.line_items || [];
    variationId = parseInt(variationId, 10) || 0;
    for (var i = 0; i < items.length; i++) {
      if (parseInt(items[i].variation_id, 10) === variationId) {
        return items[i];
      }
    }
    return null;
  }

  function findLineItemByOrderItemId($shell, orderItemId) {
    var order = $shell.data('currentOrder') || {};
    var items = order.line_items || [];
    orderItemId = parseInt(orderItemId, 10) || 0;
    for (var i = 0; i < items.length; i++) {
      if (parseInt(items[i].order_item_id, 10) === orderItemId) {
        return items[i];
      }
    }
    return null;
  }

  function resolveMenuLineItem($shell, $btn) {
    var $row = $btn.closest('[data-order-item-id]');
    var orderItemId = parseInt($row.attr('data-order-item-id'), 10) || 0;
    var variationId = parseInt($row.attr('data-variation-id'), 10) || 0;
    if (orderItemId) {
      return findLineItemByOrderItemId($shell, orderItemId) || findLineItem($shell, variationId);
    }
    return findLineItem($shell, variationId);
  }

  $(document).on('click', '.sutore-mp-staff-open-order', function (e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.which === 2) {
      return;
    }
    e.preventDefault();
    var $el = $(this);
    var orderId =
      parseInt($el.attr('data-order-id'), 10) ||
      parseInt($el.closest('[data-order-id]').attr('data-order-id'), 10) ||
      0;
    if (orderId > 0) {
      openOrderModal(orderId, { syncUrl: isOrdersListPage() });
    }
  });

  $(document).on('click', '.sutore-mp-staff-orders-close', function (e) {
    e.preventDefault();
    closeOrderModal($orderDetailHost(), { syncUrl: isOrdersListPage() });
  });

  $(document).on('click', '.sutore-mp-staff-orders-overlay', function (e) {
    if (e.target === this) {
      closeOrderModal($orderDetailHost(), { syncUrl: isOrdersListPage() });
    }
  });

  $(document).on('click', '.sutore-mp-staff-orders .sutore-mp-staff-orders-item-action', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    var item = resolveMenuLineItem($shell, $(this));
    var action = String($(this).attr('data-item-action') || '');
    if (!item) {
      return;
    }
    ensureEditing($shell);
    if (action === 'swap') {
      clearReplaceTarget($shell);
      openSwapModal($shell, item);
    } else if (action === 'replace') {
      openReplaceSearch($shell, item);
    } else if (action === 'detach') {
      clearReplaceTarget($shell);
      openDetachModal($shell, item);
    } else if (action === 'remove') {
      clearReplaceTarget($shell);
      stageRemove($shell, item);
    }
  });

  var addSearchTimer = null;

  function renderAttachCandidates($shell, items) {
    var pending = getPendingEdits($shell);
    var $box = $shell.find('.sutore-mp-staff-orders-attach-candidates');
    var selected = $shell.data('attachSelected') || {};
    var filtered = (items || []).filter(function (item) {
      var variationId = parseInt(item.variation_id || item.id, 10) || 0;
      return variationId && !isPendingAdd(pending, variationId) && !findLineItem($shell, variationId);
    });
    if (!filtered.length) {
      $box.html('<p class="sutore-mp-empty">' + esc(t('noCandidates', 'No eligible products found.')) + '</p>');
      return;
    }
    var html = '<ul class="sutore-mp-staff-orders-candidate-list">';
    filtered.forEach(function (item) {
      var id = parseInt(item.variation_id || item.id, 10) || 0;
      var isSelected = selected && parseInt(selected.variation_id || selected.id, 10) === id;
      html +=
        '<li><button type="button" class="sutore-mp-staff-orders-attach-candidate sutore-mp-staff-orders-candidate' +
        (isSelected ? ' is-selected' : '') +
        '" data-candidate="' +
        encodeURIComponent(JSON.stringify(item)) +
        '" data-variation-id="' +
        esc(String(id)) +
        '">' +
        productPreviewHtml(
          {
            thumbnail: item.thumbnail,
            name: item.product_title,
            merchant_name: item.merchant_name,
            merchant_id: item.merchant_id,
            size_label: item.size_label,
            variation_id: id,
            asking_display: item.asking_display,
            customer_price_display: item.customer_price_display
          },
          ''
        ) +
        '</button></li>';
    });
    html += '</ul>';
    $box.html(html);
  }

  function loadAttachCandidates($shell, search) {
    var order = $shell.data('currentOrder') || {};
    var orderId = parseInt(order.id || order.order_id, 10) || 0;
    if (!orderId) {
      return;
    }
    var $box = $shell.find('.sutore-mp-staff-orders-attach-candidates');
    $box.html(loadingHtml());
    var params = { per_page: 30 };
    if (search) {
      params.search = search;
    }
    ajax('GET', 'admin/orders/' + orderId + '/attach-candidates', params)
      .done(function (res) {
        if (!res || !res.success || !res.data) {
          $box.html(
            '<p class="sutore-mp-empty">' + esc((res && res.message) || t('error', 'Error')) + '</p>'
          );
          return;
        }
        renderAttachCandidates($shell, res.data.items || []);
      })
      .fail(function (xhr) {
        $box.html(
          '<p class="sutore-mp-empty">' +
            esc((xhr.responseJSON && xhr.responseJSON.message) || t('error', 'Error')) +
            '</p>'
        );
      });
  }

  $(document).on('click', '.sutore-mp-staff-orders-edit-toggle', function (e) {
    e.preventDefault();
    openApplyModal($pageShell($(this)));
  });

  $(document).on('click', '.sutore-mp-staff-orders-edit-cancel', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    closeApplyModal($shell);
    closeAttachModal($shell);
    clearReplaceTarget($shell);
    resetStatusSelect($shell);
    setEditing($shell, false);
  });

  $(document).on('click', '.sutore-mp-staff-orders-add-product', function (e) {
    e.preventDefault();
    openAttachModal($pageShell($(this)), {});
  });

  $(document).on('input', '.sutore-mp-staff-orders-attach-search', function () {
    var $shell = $pageShell($(this));
    var q = String($(this).val() || '').trim();
    clearTimeout(addSearchTimer);
    addSearchTimer = setTimeout(function () {
      loadAttachCandidates($shell, q);
    }, 280);
  });

  $(document).on('click', '.sutore-mp-staff-orders-attach-candidate', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $shell = $pageShell($(this));
    var candidate = {};
    try {
      candidate = JSON.parse(decodeURIComponent($(this).attr('data-candidate') || '%7B%7D'));
    } catch (err) {
      candidate = { variation_id: parseInt($(this).attr('data-variation-id'), 10) || 0 };
    }
    selectAttachCandidate($shell, candidate);
  });

  $(document).on('click', '.sutore-mp-staff-orders-attach-close', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    clearReplaceTarget($shell);
    closeAttachModal($shell);
  });

  $(document).on('click', '.sutore-mp-staff-orders-attach-overlay', function (e) {
    if (e.target === this) {
      var $shell = $pageShell($(this));
      clearReplaceTarget($shell);
      closeAttachModal($shell);
    }
  });

  $(document).on('click', '.sutore-mp-staff-orders-attach-confirm', function (e) {
    e.preventDefault();
    confirmAttach($pageShell($(this)));
  });

  $(document).on('click', '.sutore-mp-staff-orders-undo-pending', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    var $row = $(this).closest('.sutore-mp-staff-order-product');
    undoPending(
      $shell,
      String($(this).attr('data-undo') || ''),
      parseInt($row.attr('data-variation-id'), 10) || 0,
      parseInt($row.attr('data-order-item-id'), 10) || 0
    );
  });

  $(document).on('click', '.sutore-mp-staff-orders-apply-close', function (e) {
    e.preventDefault();
    closeApplyModal($pageShell($(this)));
  });

  $(document).on('click', '.sutore-mp-staff-orders-apply-overlay', function (e) {
    if (e.target === this) {
      closeApplyModal($pageShell($(this)));
    }
  });

  $(document).on('click', '.sutore-mp-staff-orders-apply-confirm', function (e) {
    e.preventDefault();
    applyPendingEdits($pageShell($(this)));
  });

  $(document).on('click', '.sutore-mp-staff-orders-swap-close', function (e) {
    e.preventDefault();
    closeSwapModal($pageShell($(this)));
  });

  $(document).on('click', '.sutore-mp-staff-orders-swap-overlay', function (e) {
    if (e.target === this) {
      closeSwapModal($pageShell($(this)));
    }
  });

  $(document).on('input', '.sutore-mp-staff-orders-swap-search', function () {
    var $shell = $pageShell($(this));
    var q = String($(this).val() || '').trim();
    clearTimeout(swapSearchTimer);
    swapSearchTimer = setTimeout(function () {
      loadSwapCandidates($shell, q);
    }, 280);
  });

  $(document).on('click', '.sutore-mp-staff-orders-candidate', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    try {
      selectSwapCandidate($shell, JSON.parse(decodeURIComponent($(this).attr('data-candidate') || '%7B%7D')));
    } catch (err) {
      /* ignore */
    }
  });

  $(document).on('click', '.sutore-mp-staff-orders-swap-confirm', function (e) {
    e.preventDefault();
    confirmSwap($pageShell($(this)));
  });

  $(document).on('click', '.sutore-mp-staff-orders-detach-close', function (e) {
    e.preventDefault();
    closeDetachModal($pageShell($(this)));
  });

  $(document).on('click', '.sutore-mp-staff-orders-detach-overlay', function (e) {
    if (e.target === this) {
      closeDetachModal($pageShell($(this)));
    }
  });

  $(document).on('click', '.sutore-mp-staff-orders-detach-confirm', function (e) {
    e.preventDefault();
    confirmDetach($pageShell($(this)));
  });

  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') {
      return;
    }
    if ($('.sutore-mp-confirm').length) {
      return;
    }
    if (
      window.SutoreMarketplace &&
      SutoreMarketplace.isStaffMerchantOpen &&
      SutoreMarketplace.isStaffMerchantOpen()
    ) {
      return;
    }
    var $swap = $('.sutore-mp-staff-orders-swap-overlay.is-open').first();
    if ($swap.length) {
      closeSwapModal($pageShell($swap));
      e.stopImmediatePropagation();
      return;
    }
    var $attach = $('.sutore-mp-staff-orders-attach-overlay.is-open').first();
    if ($attach.length) {
      var $shellAttach = $pageShell($attach);
      clearReplaceTarget($shellAttach);
      closeAttachModal($shellAttach);
      e.stopImmediatePropagation();
      return;
    }
    var $apply = $('.sutore-mp-staff-orders-apply-overlay.is-open').first();
    if ($apply.length) {
      closeApplyModal($pageShell($apply));
      e.stopImmediatePropagation();
      return;
    }
    var $detach = $('.sutore-mp-staff-orders-detach-overlay.is-open').first();
    if ($detach.length) {
      closeDetachModal($pageShell($detach));
      e.stopImmediatePropagation();
      return;
    }
    if (isStaffOrderOpen()) {
      closeOrderModal($orderDetailHost(), { syncUrl: isOrdersListPage() });
      e.stopImmediatePropagation();
    }
  });

  $(document).on('click', '.sutore-mp-staff-orders-filter-apply', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    if (window.SutoreMarketplace) {
      SutoreMarketplace.closeListOverlays($shell);
    }
    loadListRoot($shell.find('.sutore-mp-staff-orders-list-root'), collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-orders-filter-clear', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    $shell.find('.sutore-mp-staff-orders-filter select').each(function () {
      $(this).prop('selectedIndex', 0);
    });
    if (window.SutoreMarketplace) {
      SutoreMarketplace.closeListOverlays($shell);
    }
    loadListRoot($shell.find('.sutore-mp-staff-orders-list-root'), collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-orders-sort-apply', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    if (window.SutoreMarketplace) {
      SutoreMarketplace.closeListOverlays($shell);
    }
    loadListRoot($shell.find('.sutore-mp-staff-orders-list-root'), collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-orders-sort-clear', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    $shell.find('.sutore-mp-staff-orders-sort [name="orderby"]').val('date_desc');
    if (window.SutoreMarketplace) {
      SutoreMarketplace.closeListOverlays($shell);
    }
    loadListRoot($shell.find('.sutore-mp-staff-orders-list-root'), collectFilterState($shell));
  });

  $(document).on('input', '.sutore-mp-staff-orders-search', function () {
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-orders-list-root');
    clearTimeout(listSearchTimer);
    listSearchTimer = setTimeout(function () {
      loadListRoot($root, collectFilterState($shell));
    }, 320);
  });

  $(document).on('keydown', '.sutore-mp-staff-orders-search', function (e) {
    if (e.key !== 'Enter') {
      return;
    }
    e.preventDefault();
    clearTimeout(listSearchTimer);
    var $shell = $pageShell($(this));
    if (window.SutoreMarketplace) {
      SutoreMarketplace.closeListOverlays($shell);
    }
    loadListRoot($shell.find('.sutore-mp-staff-orders-list-root'), collectFilterState($shell));
  });

  $(document).on(
    'click',
    '.sutore-mp-staff-orders-list-root .sutore-mp-staff-pager [data-page]',
    function (e) {
      e.preventDefault();
      if ($(this).is(':disabled')) {
        return;
      }
      var $root = $(this).closest('.sutore-mp-staff-orders-list-root');
      loadListRoot($root, { page: parseInt($(this).attr('data-page'), 10) || 1 });
    }
  );

  $(document).on('change', '.sutore-mp-staff-orders-list-root .sutore-mp-staff-select-all', function () {
    var $root = $(this).closest('.sutore-mp-staff-orders-list-root');
    $root.find('.sutore-mp-staff-row-select').prop('checked', $(this).is(':checked'));
    refreshBulkBar($root);
  });

  $(document).on('change', '.sutore-mp-staff-orders-list-root .sutore-mp-staff-row-select', function () {
    refreshBulkBar($(this).closest('.sutore-mp-staff-orders-list-root'));
  });

  $(document).on('change', '.sutore-mp-staff-orders-list-root .sutore-mp-staff-bulk-action', function () {
    var $root = $(this).closest('.sutore-mp-staff-orders-list-root');
    $root.find('.sutore-mp-staff-bulk-apply').prop('disabled', !$(this).val());
  });

  $(document).on('click', '.sutore-mp-staff-orders-list-root .sutore-mp-staff-bulk-apply', function (e) {
    e.preventDefault();
    var $root = $(this).closest('.sutore-mp-staff-orders-list-root');
    runBulkStatus($root, String($root.find('.sutore-mp-staff-bulk-action').val() || ''));
  });

  $(function () {
    var $root = $ordersListRoot();
    if ($root.length) {
      loadListRoot($root);
    }
  });
})(jQuery);
