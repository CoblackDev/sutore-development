(function ($) {
  'use strict';

  var t = SutoreMarketplace.t;
  var api = SutoreMarketplace.api;
  var showConfirm = SutoreMarketplace.showConfirm;
  var showFormConfirm = SutoreMarketplace.showFormConfirm;
  var currentPage = 1;
  var searchTimer = null;

  function esc(str) {
    return $('<div/>').text(str == null ? '' : String(str)).html();
  }

  function $page() {
    return $('.sutore-mp-staff-catalog-requests');
  }

  function $results() {
    return $page().find('.sutore-mp-staff-catalog-results');
  }

  function query() {
    var $root = $results();
    return {
      search: $.trim($page().find('.sutore-mp-staff-catalog-search').val() || $root.attr('data-search') || ''),
      status: $page().find('#sutore-mp-staff-catalog-status').val() || $root.attr('data-status') || 'pending',
      page: currentPage,
      per_page: 30
    };
  }

  function syncUrl(params) {
    try {
      var base = $results().attr('data-base-url') || window.location.pathname;
      var u = new URL(base, window.location.origin);
      if (params.search) {
        u.searchParams.set('search', params.search);
      } else {
        u.searchParams.delete('search');
      }
      if (params.status) {
        u.searchParams.set('status', params.status);
      } else {
        u.searchParams.delete('status');
      }
      if (params.page > 1) {
        u.searchParams.set('paged', String(params.page));
      } else {
        u.searchParams.delete('paged');
      }
      window.history.replaceState({}, '', u.pathname + u.search + u.hash);
    } catch (e) {
      /* ignore */
    }
  }

  function renderPager(total, page, perPage) {
    var $pager = $page().find('.sutore-mp-list-pager').empty();
    var pages = Math.max(1, Math.ceil((total || 0) / (perPage || 30)));
    if (pages <= 1) {
      return;
    }
    var $prev = $('<button type="button" class="wp-element-button is-style-outline sutore-mp-staff-catalog-page"/>')
      .attr('data-page', String(Math.max(1, page - 1)))
      .prop('disabled', page <= 1)
      .text(t('previous', 'Previous'));
    var $next = $('<button type="button" class="wp-element-button is-style-outline sutore-mp-staff-catalog-page"/>')
      .attr('data-page', String(Math.min(pages, page + 1)))
      .prop('disabled', page >= pages)
      .text(t('next', 'Next'));
    $pager.append($prev).append(
      $('<span class="sutore-mp-pager-status"/>').text(
        t('pageOf', 'Page %1$d / %2$d').replace('%1$d', String(page)).replace('%2$d', String(pages))
      )
    ).append($next);
  }

  function isLink(value) {
    return /^https?:\/\//i.test(String(value || ''));
  }

  function renderCard(row) {
    var $card = $('<div class="sutore-mp-card sutore-mp-staff-catalog-card"/>').attr('data-id', String(row.id));
    var $info = $('<div class="sutore-mp-card-info"/>');
    var sku = row.sku_or_link || '';
    if (isLink(sku)) {
      $info.append(
        $('<a class="sutore-mp-card-title"/>')
          .attr('href', sku)
          .attr('target', '_blank')
          .attr('rel', 'noopener noreferrer')
          .text(sku)
      );
    } else {
      $info.append($('<div class="sutore-mp-card-title"/>').text(sku || ('#' + row.id)));
    }

    var meta = [];
    if (row.merchant_name) {
      meta.push(t('seller', 'Seller') + ': ' + row.merchant_name);
    }
    if (row.merchant_level_label) {
      meta.push(row.merchant_level_label);
    }
    if (row.size_note) {
      meta.push(t('size', 'Size') + ': ' + row.size_note);
    }
    meta.push(row.status_label || row.status || '');
    if (row.created_at_display) {
      meta.push(row.created_at_display);
    }
    $info.append($('<div class="sutore-mp-card-meta"/>').html(meta.map(esc).join(' · ')));

    if (row.note) {
      $info.append($('<div class="sutore-mp-card-offer-headline"/>').text(row.note));
    }
    if (row.resolved_product_title || row.resolved_product_code) {
      $info.append(
        $('<div class="sutore-mp-card-code"/>').text(
          (row.resolved_product_title || '') +
            (row.resolved_product_code ? ' (' + row.resolved_product_code + ')' : '')
        )
      );
    }

    $card.append($('<div class="sutore-mp-card-main"/>').append($info));

    if (row.status === 'pending') {
      var $actions = $('<div class="sutore-mp-card-actions"/>');
      $actions.append(
        $('<button type="button" class="wp-element-button sutore-mp-staff-catalog-fulfill"/>')
          .attr('data-id', String(row.id))
          .text(t('catalogRequestFulfill', 'Mark added'))
      );
      $actions.append(
        $('<button type="button" class="wp-element-button is-style-outline sutore-mp-staff-catalog-reject"/>')
          .attr('data-id', String(row.id))
          .text(t('catalogRequestReject', 'Decline'))
      );
      $card.append($actions);
    }

    return $card;
  }

  function loadList(page) {
    currentPage = page || 1;
    var params = query();
    params.page = currentPage;
    $results().attr('aria-busy', 'true');
    $page().find('.sutore-mp-list-chrome').prop('hidden', false);
    api('marketplace_admin_catalog_requests', params).done(function (res) {
      $results().attr('aria-busy', 'false').empty();
      if (!res || !res.success) {
        $results().text((res && res.data && res.data.message) || t('error', 'Error'));
        return;
      }
      var data = res.data || {};
      var items = data.items || [];
      if (!items.length) {
        $results().append($('<p class="sutore-mp-empty"/>').text(t('noRecords', 'No catalog requests.')));
        $page().find('.sutore-mp-list-pager').empty();
        syncUrl(params);
        return;
      }
      items.forEach(function (row) {
        $results().append(renderCard(row));
      });
      renderPager(data.total || 0, data.page || 1, data.per_page || 30);
      syncUrl(params);
    });
  }

  function fulfillRequest(id) {
    if (typeof showFormConfirm === 'function') {
      showFormConfirm({
        title: t('catalogRequestFulfillTitle', 'Mark this product as added to the catalog?'),
        text: t(
          'catalogRequestFulfillText',
          'The seller will be notified that they can open a product. Optionally link the WooCommerce parent product ID.'
        ),
        confirmLabel: t('catalogRequestFulfill', 'Mark added'),
        fields: [
          {
            name: 'parent_product_id',
            label: t('catalogRequestParentSearch', 'Catalog product (optional)'),
            type: 'product_search',
            required: false,
            placeholder: t('searchNameOrSku', 'Search by product name or SKU…')
          }
        ],
        onConfirm: function (values) {
          var payload = { id: id };
          var parentId = parseInt(values.parent_product_id || '0', 10) || 0;
          if (parentId > 0) {
            payload.parent_product_id = parentId;
          }
          api('marketplace_admin_catalog_request_fulfill', payload).done(function (res) {
            if (!res || !res.success) {
              window.alert((res && res.data && res.data.message) || t('error', 'Error'));
              return;
            }
            if (typeof SutoreMarketplace.showToast === 'function') {
              SutoreMarketplace.showToast((res.data && res.data.message) || t('updated', 'Updated.'), 'success');
            }
            loadList(currentPage);
          });
        }
      });
      return;
    }

    showConfirm(
      t('catalogRequestFulfillTitle', 'Mark this product as added to the catalog?'),
      t('catalogRequestFulfillText', 'The seller will be notified that they can open a product.'),
      t('catalogRequestFulfill', 'Mark added'),
      function () {
        api('marketplace_admin_catalog_request_fulfill', { id: id }).done(function (res) {
          if (!res || !res.success) {
            window.alert((res && res.data && res.data.message) || t('error', 'Error'));
            return;
          }
          loadList(currentPage);
        });
      }
    );
  }

  function rejectRequest(id) {
    if (typeof showFormConfirm === 'function') {
      showFormConfirm({
        title: t('catalogRequestRejectTitle', 'Decline this catalog request?'),
        text: t('catalogRequestRejectText', 'The seller will be notified. You can include a short reason.'),
        confirmLabel: t('catalogRequestReject', 'Decline'),
        fields: [
          {
            name: 'staff_note',
            label: t('catalogRequestStaffNote', 'Note to seller (optional)'),
            type: 'text',
            required: false
          }
        ],
        onConfirm: function (values) {
          api('marketplace_admin_catalog_request_reject', {
            id: id,
            staff_note: values.staff_note || ''
          }).done(function (res) {
            if (!res || !res.success) {
              window.alert((res && res.data && res.data.message) || t('error', 'Error'));
              return;
            }
            loadList(currentPage);
          });
        }
      });
      return;
    }

    showConfirm(
      t('catalogRequestRejectTitle', 'Decline this catalog request?'),
      t('catalogRequestRejectText', 'The seller will be notified.'),
      t('catalogRequestReject', 'Decline'),
      function () {
        api('marketplace_admin_catalog_request_reject', { id: id }).done(function (res) {
          if (!res || !res.success) {
            window.alert((res && res.data && res.data.message) || t('error', 'Error'));
            return;
          }
          loadList(currentPage);
        });
      }
    );
  }

  $(document).on('submit', '.sutore-mp-staff-catalog-filter', function (e) {
    e.preventDefault();
    if (SutoreMarketplace.closeListOverlays) {
      SutoreMarketplace.closeListOverlays($page());
    }
    loadList(1);
  });

  $(document).on('click', '.sutore-mp-staff-catalog-filter-apply', function () {
    if (SutoreMarketplace.closeListOverlays) {
      SutoreMarketplace.closeListOverlays($page());
    }
    loadList(1);
  });

  $(document).on('input', '.sutore-mp-staff-catalog-search', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () {
      loadList(1);
    }, 280);
  });

  $(document).on('click', '.sutore-mp-staff-catalog-page', function () {
    var page = parseInt($(this).attr('data-page') || '1', 10) || 1;
    loadList(page);
  });

  $(document).on('click', '.sutore-mp-staff-catalog-fulfill', function () {
    fulfillRequest(parseInt($(this).attr('data-id') || '0', 10) || 0);
  });

  $(document).on('click', '.sutore-mp-staff-catalog-reject', function () {
    rejectRequest(parseInt($(this).attr('data-id') || '0', 10) || 0);
  });

  $(function () {
    if (!$page().length) {
      return;
    }
    currentPage = parseInt($results().attr('data-page') || '1', 10) || 1;
    loadList(currentPage);
  });
})(jQuery);
