(function ($) {
  'use strict';

  var t = SutoreMarketplace.t;
  var api = SutoreMarketplace.api;
  var thumbBox = SutoreMarketplace.thumbBox;
  var showConfirm = SutoreMarketplace.showConfirm;
  var activeSourcingId = 0;

  function sourcingStatusLabel(status) {
    var map = {
      open: t('sourcingOpen', 'Open'),
      accepted: t('sourcingAccepted', 'Accepted'),
      fulfilled: t('sourcingFulfilled', 'Completed'),
      cancelled: t('sourcingCancelled', 'Cancel')
    };
    return map[status] || status;
  }

  function $page() {
    return $('.sutore-mp-sourcing').first();
  }

  function $overlay() {
    return $page().find('.sutore-mp-sourcing-overlay');
  }

  function $modal() {
    return $overlay().find('.sutore-mp-sourcing-modal');
  }

  function sourcingUrl() {
    return (window.SutoreMarketplace && SutoreMarketplace.sourcingUrl) || '';
  }

  function listingDetailUrl(listingId) {
    var base = (window.SutoreMarketplace && SutoreMarketplace.listingsUrl) || '';
    if (!base) {
      return '#';
    }
    try {
      var u = new URL(base, window.location.origin);
      u.searchParams.delete('action');
      u.searchParams.set('listing_id', String(listingId));
      return u.toString();
    } catch (err) {
      return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'listing_id=' + encodeURIComponent(String(listingId));
    }
  }

  function querySourcingId() {
    try {
      var params = new URLSearchParams(window.location.search || '');
      var id = parseInt(params.get('sourcing_id') || '0', 10);
      return id > 0 ? id : 0;
    } catch (e) {
      return 0;
    }
  }

  function setSourcingQuery(id) {
    try {
      var url = new URL(window.location.href);
      if (id > 0) {
        url.searchParams.set('sourcing_id', String(id));
      } else {
        url.searchParams.delete('sourcing_id');
      }
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    } catch (e) {
      /* ignore */
    }
  }

  function listSpinnerHtml() {
    return (
      '<div class="sutore-mp-list-loading" role="status" aria-live="polite">' +
      '<span class="sutore-mp-list-spinner" aria-hidden="true"></span>' +
      '<span class="screen-reader-text">' +
      $('<div/>').text(t('loading', 'Loading…')).html() +
      '</span></div>'
    );
  }

  function modalSpinnerHtml() {
    return (
      '<div class="sutore-mp-manage-modal__loading" role="status" aria-live="polite">' +
      '<span class="sutore-mp-list-spinner" aria-hidden="true"></span>' +
      '<span class="screen-reader-text">' +
      $('<div/>').text(t('loading', 'Loading…')).html() +
      '</span></div>'
    );
  }

  function metaRow(label, valueHtml) {
    return (
      '<div><dt>' +
      $('<div/>').text(label).html() +
      '</dt><dd>' +
      valueHtml +
      '</dd></div>'
    );
  }

  function commitmentText(deadlineDisplay) {
    if (deadlineDisplay) {
      return t(
        'sourcingCommitmentWithDate',
        'I confirm that the product is original and compliant, and that I will deliver it complete and undamaged to the Sutore control center by %s. I accept sole responsibility for any cancellation, return, or damages resulting from non-compliance.'
      ).replace('%s', deadlineDisplay);
    }
    return t(
      'sourcingCommitment',
      'I confirm that the product is original and compliant, and that I will deliver it complete and undamaged to the Sutore control center by the delivery deadline. I accept sole responsibility for any cancellation, return, or damages resulting from non-compliance.'
    );
  }

  function syncAcceptButton() {
    var $modalRoot = $modal();
    var checked = $modalRoot.find('.sutore-mp-sourcing-commitment-check').prop('checked');
    $modalRoot.find('.sutore-mp-sourcing-accept-submit').prop('disabled', !checked);
    $modalRoot.find('.sutore-mp-sourcing-commitment-hint').prop('hidden', true);
  }

  function acceptConfirmMessage(createNewListing) {
    var $modalRoot = $modal();
    var listingId = parseInt($modalRoot.attr('data-listing-id'), 10) || 0;
    var variationId = parseInt($modalRoot.attr('data-matching-variation-id'), 10) || 0;
    var existingPrice = $modalRoot.attr('data-matching-asking-display') || '';
    var offerPrice = $modalRoot.attr('data-offer-asking-display') || '';
    var existingAsking = Number($modalRoot.attr('data-matching-asking') || 0);
    var offerAsking = Number($modalRoot.attr('data-offer-asking') || 0);
    var variationLabel = variationId
      ? t('variationNumber', 'Variation #%d').replace('%d', String(variationId))
      : t('variationId', 'Variation ID');

    if (!listingId) {
      return t(
        'sourcingAcceptConfirmCreate',
        'A new listing will be created for this pre-order. Continue?'
      );
    }

    if (createNewListing) {
      return t(
        'sourcingAcceptConfirmKeepExisting',
        'Your existing listing (%1$s, %2$s) will stay unchanged, and a new listing will be created for this pre-order. Continue?'
      )
        .replace('%1$s', variationLabel)
        .replace('%2$s', existingPrice || '—');
    }

    if (existingAsking && offerAsking && existingAsking !== offerAsking) {
      return t(
        'sourcingAcceptConfirmReusePriceChange',
        'Your existing listing (%1$s) will be used for this pre-order, and its price will be updated from %2$s to %3$s. Continue?'
      )
        .replace('%1$s', variationLabel)
        .replace('%2$s', existingPrice || '—')
        .replace('%3$s', offerPrice || String(offerAsking));
    }

    return t(
      'sourcingAcceptConfirmReuse',
      'Your existing listing (%1$s, %2$s) will be used for this pre-order. A new listing will not be created. Continue?'
    )
      .replace('%1$s', variationLabel)
      .replace('%2$s', existingPrice || '—');
  }

  function fillModal(item, sourcingId) {
    var $ov = $overlay();
    var $modalRoot = $modal();
    var $media = $ov.find('.sutore-mp-manage-modal__media').empty();
    var $badge = $ov.find('.sutore-mp-manage-modal__badge');
    var $sub = $ov.find('.sutore-mp-manage-modal__sub');
    var $title = $ov.find('#sutore-mp-sourcing-modal-title');
    var $summary = $ov.find('.sutore-mp-sourcing-modal-summary');
    var $accept = $ov.find('.sutore-mp-sourcing-modal-accept');
    var $foot = $ov.find('.sutore-mp-sourcing-modal-foot');

    if (!item) {
      $title.text(t('sourcingDetail', 'Pre-order'));
      $sub.text('');
      $badge.prop('hidden', true);
      $media.prop('hidden', true);
      $summary.html(
        '<p class="sutore-mp-error">' +
          $('<div/>').text(t('sourcingNotFound', 'Pre-order not found.')).html() +
          '</p>'
      );
      $accept.empty().prop('hidden', true);
      $foot.prop('hidden', true);
      $modalRoot.removeAttr('data-can-accept data-listing-id data-matching-variation-id data-matching-asking data-matching-asking-display data-offer-asking data-offer-asking-display');
      activeSourcingId = 0;
      return;
    }

    var title = item.parent_title || ('#' + item.parent_product_id);
    var status = item.status || '';
    var statusLabel = sourcingStatusLabel(status);
    var matching = item.matching_listing && typeof item.matching_listing === 'object'
      ? item.matching_listing
      : null;
    var listingId = matching ? (parseInt(matching.listing_id, 10) || 0) : 0;
    var canAccept = !!item.can_accept;
    var deadlineDisplay = item.delivery_deadline_display || '';

    activeSourcingId = sourcingId;
    $modalRoot.attr({
      'data-sourcing-id': String(sourcingId),
      'data-can-accept': canAccept ? '1' : '0',
      'data-listing-id': String(listingId),
      'data-matching-variation-id': matching ? String(matching.variation_id || '') : '',
      'data-matching-asking': matching && matching.asking != null ? String(matching.asking) : '',
      'data-matching-asking-display': matching ? String(matching.asking_display || '') : '',
      'data-offer-asking': item.offer_asking != null ? String(item.offer_asking) : '',
      'data-offer-asking-display': String(item.offer_asking_display || '')
    });

    $title.text(title);
    $sub.text('#' + sourcingId);
    $badge.text(statusLabel).prop('hidden', false);

    if (item.thumbnail && typeof thumbBox === 'function') {
      var $thumb = thumbBox(
        'sutore-mp-manage-modal__thumb-box',
        'sutore-mp-manage-modal__thumb',
        item.thumbnail,
        title
      );
      if (item.permalink) {
        $media.append(
          $('<a class="sutore-mp-manage-modal__media-link"/>')
            .attr('href', item.permalink)
            .attr('target', '_blank')
            .attr('rel', 'noopener noreferrer')
            .append($thumb)
        );
      } else {
        $media.append($thumb);
      }
      $media.prop('hidden', false);
    } else {
      $media.prop('hidden', true);
    }

    var productHtml = item.permalink
      ? '<a href="' +
        $('<div/>').text(item.permalink).html() +
        '" target="_blank" rel="noopener noreferrer">' +
        $('<div/>').text(title).html() +
        '</a>'
      : $('<div/>').text(title).html();

    var html = '<dl class="sutore-mp-form-context-meta sutore-mp-manage-summary-meta sutore-mp-sourcing-meta">';
    html += metaRow(t('sourcingProduct', 'Product'), productHtml);
    html += metaRow(t('status', 'Status'), $('<div/>').text(statusLabel).html());
    if (item.product_code) {
      html += metaRow(t('productCode', 'Product code'), $('<div/>').text(item.product_code).html());
    }
    if (item.size_label) {
      html += metaRow(t('size', 'Size'), $('<div/>').text(item.size_label).html());
    }
    html += metaRow(t('sourcingOffer', 'Pre-order'), '#' + sourcingId);
    if (item.offer_asking_display) {
      html += metaRow(t('sourcingOfferPrice', 'Sale price'), $('<div/>').text(item.offer_asking_display).html());
    }
    if (item.estimated_net_display) {
      html += metaRow(t('sourcingNetPayout', 'Est. net payout'), $('<div/>').text(item.estimated_net_display).html());
    }
    if (deadlineDisplay) {
      html += metaRow(t('sourcingDeliveryDeadline', 'Delivery deadline'), $('<div/>').text(deadlineDisplay).html());
    }
    if (item.order_id) {
      html += metaRow(t('sourcingOrder', 'Order'), '#' + item.order_id);
    }
    html += '</dl>';
    $summary.html(html);

    $accept.empty();
    if (canAccept) {
      var $panel = $('<section class="sutore-mp-sourcing-accept-panel"/>');
      $panel.append($('<h3 class="sutore-mp-sourcing-accept-heading"/>').text(t('sourcingConfirmAccept', 'Accept sale')));

      if (matching && listingId > 0) {
        var variationId = parseInt(matching.variation_id, 10) || 0;
        var existingPrice = matching.asking_display || '—';
        var existingMessage = t(
          'sourcingExistingListingNotice',
          'You already have a listing for this product and size (%1$s, %2$s). It will be used for this pre-order; a new listing will not be created.'
        ).replace('%2$s', existingPrice);
        var messageParts = existingMessage.split('%1$s');
        var variationLabel = variationId
          ? t('variationNumber', 'Variation #%d').replace('%d', String(variationId))
          : t('variationId', 'Variation ID');
        var $existingNotice = $('<p class="sutore-mp-notice sutore-mp-sourcing-existing-notice"/>');
        $existingNotice.append(document.createTextNode(messageParts.shift() || ''));
        $existingNotice.append(
          $('<a/>').attr('href', listingDetailUrl(listingId)).attr('target', '_blank').text(variationLabel)
        );
        $existingNotice.append(document.createTextNode(messageParts.join('%1$s')));

        var existingAsking = Number(matching.asking);
        var offerAsking = Number(item.offer_asking);
        if (existingAsking && offerAsking && existingAsking !== offerAsking) {
          var priceUpdateMessage = t(
            'sourcingExistingPriceUpdate',
            'Its price will be updated from %1$s to the pre-order price of %2$s when you accept.'
          )
            .replace('%1$s', existingPrice)
            .replace('%2$s', item.offer_asking_display || String(offerAsking));
          $existingNotice.append(document.createTextNode(' ' + priceUpdateMessage));
        }
        $panel.append($existingNotice);
        $panel.append(
          $('<div class="wc-block-components-checkbox sutore-mp-sourcing-existing-option"/>').append(
            $('<label/>').append(
              $('<input type="checkbox" class="wc-block-components-checkbox__input sutore-mp-sourcing-create-new-check"/>'),
              $('<svg class="wc-block-components-checkbox__mark" aria-hidden="true" viewBox="0 0 24 20"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"></path></svg>'),
              $('<span class="wc-block-components-checkbox__label"/>').text(
                t('sourcingKeepExistingListing', 'Keep my existing listing; I will supply a new product.')
              )
            )
          )
        );
      }

      $panel.append(
        $('<div class="wc-block-components-checkbox sutore-mp-sourcing-commitment"/>').append(
          $('<label/>').append(
            $('<input type="checkbox" class="wc-block-components-checkbox__input sutore-mp-sourcing-commitment-check"/>'),
            $('<svg class="wc-block-components-checkbox__mark" aria-hidden="true" viewBox="0 0 24 20"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"></path></svg>'),
            $('<span class="wc-block-components-checkbox__label"/>').text(commitmentText(deadlineDisplay))
          )
        )
      );
      $panel.append(
        $('<p class="sutore-mp-sourcing-commitment-hint" hidden/>').text(
          t('sourcingCommitmentRequired', 'Please confirm the commitment to continue.')
        )
      );
      $panel.append($('<p class="sutore-mp-sourcing-accept-message" aria-live="polite"/>'));
      $accept.append($panel).prop('hidden', false);
      $foot.prop('hidden', false);
      $foot.find('.sutore-mp-sourcing-accept-submit').prop('disabled', true);
    } else {
      $accept.prop('hidden', true);
      $foot.prop('hidden', true);
      if (status === 'accepted' && item.is_mine) {
        $summary.append(
          $('<p class="description"/>').text(t('sourcingAcceptedMine', 'You have accepted this pre-order.'))
        );
      }
    }
  }

  function openModal(item, sourcingId) {
    var $ov = $overlay();
    $ov.find('.sutore-mp-sourcing-modal-summary').empty();
    $ov.find('.sutore-mp-sourcing-modal-accept').empty().prop('hidden', true);
    $ov.prop('hidden', false);
    window.requestAnimationFrame(function () {
      $ov.addClass('is-open');
    });
    $('body').addClass('sutore-mp-modal-open');
    setSourcingQuery(sourcingId);

    if (item) {
      fillModal(item, sourcingId);
      return;
    }

    $modal().find('.sutore-mp-manage-modal__body').attr('aria-busy', 'true');
    $ov.find('.sutore-mp-sourcing-modal-summary').html(modalSpinnerHtml());
    api('marketplace_sourcing_get', { request_id: sourcingId }).done(function (res) {
      $modal().find('.sutore-mp-manage-modal__body').attr('aria-busy', 'false');
      if (!res.success) {
        fillModal(null, sourcingId);
        return;
      }
      fillModal((res.data && res.data.item) || null, sourcingId);
    }).fail(function () {
      $modal().find('.sutore-mp-manage-modal__body').attr('aria-busy', 'false');
      fillModal(null, sourcingId);
    });
  }

  function closeModal() {
    var $ov = $overlay();
    $ov.removeClass('is-open').addClass('is-closing');
    window.setTimeout(function () {
      $ov.prop('hidden', true).removeClass('is-closing');
      activeSourcingId = 0;
      setSourcingQuery(0);
    }, 180);
    if (
      !document.querySelector(
        '.sutore-mp-filter-overlay:not([hidden]), .sutore-mp-sort-overlay:not([hidden]), .sutore-mp-manage-overlay:not([hidden]):not(.sutore-mp-sourcing-overlay), .sutore-mp-offer-overlay:not([hidden])'
      )
    ) {
      $('body').removeClass('sutore-mp-modal-open');
    }
  }

  function filterState($root) {
    var $filter = $root.find('.sutore-mp-sourcing-filter');
    var $sort = $root.find('.sutore-mp-sourcing-sort');
    return {
      search: String($root.find('.sutore-mp-sourcing-search').val() || '').trim(),
      status: String($filter.find('[name="status"]').val() || ''),
      orderby: String($sort.find('[name="orderby"]').val() || 'default')
    };
  }

  function updateBadges($root, state) {
    var filterCount = 0;
    if (state.status) filterCount++;
    SutoreMarketplace.setFilterBadge($root, filterCount);
    SutoreMarketplace.setSortBadge($root, state.orderby !== 'default');
  }

  function loadSourcing($root) {
    if (!$root.length) {
      return;
    }
    var state = filterState($root);
    updateBadges($root, state);
    var $box = $root.find('.sutore-mp-sourcing-results');
    var $chrome = $root.find('.sutore-mp-list-chrome');
    $box.attr('aria-busy', 'true').html(listSpinnerHtml());
    var payload = { page: 1, per_page: 30, orderby: state.orderby };
    if (state.status) {
      payload.status = state.status;
    }
    if (state.search) {
      payload.search = state.search;
    }
    api('marketplace_sourcing_query', payload).done(function (res) {
      $box.empty().attr('aria-busy', 'false');
      if (!res.success) {
        $box.text((res.data && res.data.message) || t('error', 'Error'));
        $chrome.prop('hidden', false);
        return;
      }
      var items = res.data.items || [];
      if (!items.length) {
        var hasFilters = state.search || state.status;
        $box.append(
          $('<p class="sutore-mp-empty"/>').text(
            hasFilters
              ? t('noResults', 'No results found.')
              : t('sourcingEmpty', 'There are no open pre-orders at the moment.')
          )
        );
        $chrome.prop('hidden', false);
        return;
      }
      items.forEach(function (item) {
        var $card = $('<div class="sutore-mp-card"/>');
        var $main = $('<div class="sutore-mp-card-main"/>');
        if (item.thumbnail) {
          $main.append(thumbBox('sutore-mp-card-thumb-box', 'sutore-mp-card-thumb', item.thumbnail, ''));
        } else {
          $main.append(thumbBox('sutore-mp-card-thumb-box', 'sutore-mp-card-thumb', '', ''));
        }
        var $info = $('<div class="sutore-mp-card-info"/>');
        var title = item.parent_title || ('#' + item.parent_product_id);
        if (item.permalink) {
          $info.append(
            $('<a class="sutore-mp-card-title"/>')
              .attr('href', item.permalink)
              .attr('target', '_blank')
              .attr('rel', 'noopener noreferrer')
              .text(title)
          );
        } else {
          $info.append($('<div class="sutore-mp-card-title"/>').text(title));
        }
        var codeLine = item.product_code || '';
        if (item.size_label) {
          codeLine = (codeLine ? codeLine + ' · ' : '') + (t('size', 'Size') + ': ' + item.size_label);
        }
        if (codeLine) {
          $info.append($('<div class="sutore-mp-card-code"/>').text(codeLine));
        }
        var metaParts = [sourcingStatusLabel(item.status)];
        if (item.offer_asking_display) {
          metaParts.push(t('sourcingOfferPrice', 'Sale price') + ': ' + item.offer_asking_display);
        }
        if (item.estimated_net_display) {
          metaParts.push(t('sourcingNetPayout', 'Est. net payout') + ': ' + item.estimated_net_display);
        }
        $info.append($('<div class="sutore-mp-card-meta"/>').text(metaParts.join(' · ')));
        var $actions = $('<div class="sutore-mp-card-actions"/>');
        $actions.append(
          $('<button type="button" class="wp-element-button sutore-mp-open-sourcing"/>')
            .attr('data-id', String(item.id))
            .text(item.can_accept ? t('sourcingReviewOffer', 'Review offer') : t('sourcingViewOffer', 'View offer'))
        );
        $info.append($actions);
        $main.append($info);
        $card.append($main);
        $box.append($card);
      });
      $chrome.prop('hidden', false);

      var deep = querySourcingId();
      if (deep) {
        var match = null;
        items.some(function (row) {
          if (parseInt(row.id, 10) === deep) {
            match = row;
            return true;
          }
          return false;
        });
        openModal(match, deep);
      }
    }).fail(function () {
      $box.attr('aria-busy', 'false').empty().text(t('error', 'Error'));
      $chrome.prop('hidden', false);
    });
  }

  $(document).on('click', '.sutore-mp-open-sourcing', function (e) {
    e.preventDefault();
    var id = parseInt($(this).attr('data-id'), 10) || 0;
    if (!id) {
      return;
    }
    openModal(null, id);
  });

  $(document).on('click', '.sutore-mp-sourcing-close', function () {
    closeModal();
  });

  $(document).on('click', '.sutore-mp-sourcing-overlay', function (e) {
    if (e.target === this) {
      closeModal();
    }
  });

  $(document).on('change', '.sutore-mp-sourcing-modal .sutore-mp-sourcing-commitment-check', function () {
    syncAcceptButton();
  });

  $(document).on('click', '.sutore-mp-sourcing-modal .sutore-mp-sourcing-accept-submit', function () {
    var $modalRoot = $modal();
    if ($modalRoot.attr('data-can-accept') !== '1') {
      return;
    }
    var $check = $modalRoot.find('.sutore-mp-sourcing-commitment-check');
    if (!$check.prop('checked')) {
      $modalRoot.find('.sutore-mp-sourcing-commitment-hint').prop('hidden', false);
      return;
    }

    var requestId = parseInt($modalRoot.attr('data-sourcing-id'), 10) || activeSourcingId || 0;
    var createNewListing = $modalRoot.find('.sutore-mp-sourcing-create-new-check').is(':checked');
    var listingId = createNewListing ? '' : (parseInt($modalRoot.attr('data-listing-id'), 10) || '');
    if (!requestId) {
      return;
    }

    var $btn = $(this);
    showConfirm(
      t('sourcingConfirmAccept', 'Accept sale'),
      acceptConfirmMessage(createNewListing),
      t('sourcingConfirmAccept', 'Accept sale'),
      function () {
        $btn.prop('disabled', true);
        $modalRoot.find('.sutore-mp-sourcing-accept-message').text('');
        api('marketplace_sourcing_accept', {
          request_id: requestId,
          listing_id: listingId,
          create_new_listing: createNewListing
        }).done(function (res) {
          if (!res.success) {
            $modalRoot.find('.sutore-mp-sourcing-accept-message').text(
              (res.data && res.data.message) || res.message || t('error', 'Error')
            );
            syncAcceptButton();
            return;
          }
          closeModal();
          loadSourcing($page());
        }).fail(function () {
          $modalRoot.find('.sutore-mp-sourcing-accept-message').text(t('error', 'Error'));
          syncAcceptButton();
        });
      }
    );
  });

  $(document).on('click', '.sutore-mp-sourcing-filter-apply', function () {
    var $root = $(this).closest('.sutore-mp-sourcing');
    SutoreMarketplace.closeListOverlays($root);
    loadSourcing($root);
  });

  $(document).on('click', '.sutore-mp-sourcing-filter-clear', function () {
    var $root = $(this).closest('.sutore-mp-sourcing');
    $root.find('.sutore-mp-sourcing-filter [name="status"]').val('');
    SutoreMarketplace.closeListOverlays($root);
    loadSourcing($root);
  });

  $(document).on('click', '.sutore-mp-sourcing-sort-apply', function () {
    var $root = $(this).closest('.sutore-mp-sourcing');
    SutoreMarketplace.closeListOverlays($root);
    loadSourcing($root);
  });

  $(document).on('click', '.sutore-mp-sourcing-sort-clear', function () {
    var $root = $(this).closest('.sutore-mp-sourcing');
    $root.find('.sutore-mp-sourcing-sort [name="orderby"]').val('default');
    SutoreMarketplace.closeListOverlays($root);
    loadSourcing($root);
  });

  var sourcingSearchTimer = null;

  $(document).on('input', '.sutore-mp-sourcing-search', function () {
    var $root = $(this).closest('.sutore-mp-sourcing');
    clearTimeout(sourcingSearchTimer);
    sourcingSearchTimer = setTimeout(function () {
      loadSourcing($root);
    }, 320);
  });

  $(document).on('keydown', '.sutore-mp-sourcing-search', function (e) {
    if (e.key !== 'Enter') {
      return;
    }
    e.preventDefault();
    var $root = $(this).closest('.sutore-mp-sourcing');
    clearTimeout(sourcingSearchTimer);
    SutoreMarketplace.closeListOverlays($root);
    loadSourcing($root);
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && $overlay().hasClass('is-open')) {
      closeModal();
    }
  });

  $(function () {
    $('.sutore-mp-sourcing').each(function () {
      loadSourcing($(this));
    });
  });
})(jQuery);
