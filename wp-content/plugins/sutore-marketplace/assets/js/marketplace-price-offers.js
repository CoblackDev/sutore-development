(function ($) {
  'use strict';

  var t = SutoreMarketplace.t;
  var api = SutoreMarketplace.api;
  var showConfirm = SutoreMarketplace.showConfirm;
  var thumbBox = SutoreMarketplace.thumbBox;
  var offerCache = {};
  var activeOfferId = 0;
  var currentPage = 1;

  function money(n) {
    var v = Number(n || 0);
    return (Math.round(v * 100) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' TL';
  }

  function esc(str) {
    return $('<div/>').text(str == null ? '' : String(str)).html();
  }

  function $page() {
    return $('.sutore-mp-price-offers');
  }

  function $overlay() {
    return $page().find('.sutore-mp-offer-overlay');
  }

  function queryOfferId() {
    try {
      var params = new URLSearchParams(window.location.search || '');
      var id = parseInt(params.get('offer') || '0', 10);
      return id > 0 ? id : 0;
    } catch (e) {
      return 0;
    }
  }

  function clearOfferQuery() {
    try {
      var url = new URL(window.location.href);
      if (!url.searchParams.has('offer')) {
        return;
      }
      url.searchParams.delete('offer');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    } catch (e) {
      /* ignore */
    }
  }

  function renderCard(row) {
    var $card = $('<div class="sutore-mp-card sutore-mp-offer-card"/>').attr('data-id', String(row.id));
    if (row.status === 'pending') {
      $card.addClass('is-price-offer');
    } else if (row.status === 'accepted') {
      $card.addClass('is-campaign-active');
    }

    var $main = $('<div class="sutore-mp-card-main"/>');
    if (typeof thumbBox === 'function') {
      $main.append(thumbBox('sutore-mp-card-thumb-box', 'sutore-mp-card-thumb', row.thumbnail || '', row.product_title || ''));
    }

    var $info = $('<div class="sutore-mp-card-info"/>');
    if (row.permalink) {
      $info.append(
        $('<a class="sutore-mp-card-title"/>')
          .attr('href', row.permalink)
          .attr('target', '_blank')
          .attr('rel', 'noopener noreferrer')
          .text(row.product_title || '')
      );
    } else {
      $info.append($('<div class="sutore-mp-card-title"/>').text(row.product_title || ''));
    }

    var metaParts = [];
    if (row.size_label) {
      metaParts.push(row.size_label);
    }
    metaParts.push(row.status_label || row.status || '');
    $info.append($('<div class="sutore-mp-card-meta"/>').html(metaParts.map(esc).join(' · ')));
    $info.append(
      $('<div class="sutore-mp-card-offer-discounts"/>').text(
        t('priceOfferBid', 'Customer bid (asking)') + ': ' + money(row.bid_amount) +
        ' · ' + t('priceOfferAsking', 'Your asking') + ': ' + money(row.asking_now || row.asking_at_offer)
      )
    );
    if (row.remaining_label) {
      $info.append($('<div class="sutore-mp-card-remaining"/>').text(row.remaining_label));
    }
    var $tags = $('<div class="sutore-mp-card-tags"/>');
    $tags.append($('<span class="sutore-mp-tag is-campaign-offer"/>').text(t('priceOfferTag', 'Customer offer')));
    if (row.forwarded) {
      $tags.append($('<span class="sutore-mp-tag"/>').text(t('priceOfferForwarded', 'Forwarded from the previous seller')));
    }
    $info.append($tags);

    var $actions = $('<div class="sutore-mp-card-actions"/>');
    $actions.append(
      $('<button type="button" class="wp-element-button sutore-mp-open-offer"/>')
        .attr('data-id', String(row.id))
        .text(t('reviewPriceOffer', 'Review offer'))
    );
    $info.append($actions);
    $main.append($info);
    $card.append($main);
    return $card;
  }

  function metaRow(label, valueHtml) {
    return '<div><dt>' + esc(label) + '</dt><dd>' + valueHtml + '</dd></div>';
  }

  function fillModal(row) {
    var $ov = $overlay();
    var $media = $ov.find('.sutore-mp-manage-modal__media').empty();
    var $badge = $ov.find('.sutore-mp-manage-modal__badge');
    var $sub = $ov.find('.sutore-mp-manage-modal__sub');
    var $title = $ov.find('#sutore-mp-price-offer-modal-title');
    var $summary = $ov.find('.sutore-mp-offer-modal-summary');
    var $foot = $ov.find('.sutore-mp-offer-modal-foot');
    var title = row.product_title || t('priceOfferTitle', 'Customer offer');
    $title.text(title);
    $sub.text([row.product_code, row.size_label].filter(Boolean).join(' · '));
    if (row.status_label) {
      $badge.text(row.status_label).prop('hidden', false);
    } else {
      $badge.prop('hidden', true);
    }
    if (row.thumbnail && typeof thumbBox === 'function') {
      $media.append(thumbBox('sutore-mp-manage-modal__thumb-box', 'sutore-mp-manage-modal__thumb', row.thumbnail, title));
      $media.prop('hidden', false);
    } else {
      $media.prop('hidden', true);
    }

    var html = '<dl class="sutore-mp-form-context-meta sutore-mp-manage-summary-meta sutore-mp-offer-meta">';
    if (row.size_label) {
      html += metaRow(t('size', 'Size'), esc(row.size_label));
    }
    html += metaRow(t('priceOfferBid', 'Customer bid (asking)'), esc(money(row.bid_amount)));
    html += metaRow(t('priceOfferAsking', 'Your asking'), esc(money(row.asking_now || row.asking_at_offer)));
    html += metaRow(t('priceOfferPay', 'Customer would pay'), esc(money(row.customer_pay)));
    if (row.expires_at_label) {
      html += metaRow(t('remaining', 'Expires'), esc(row.expires_at_label));
    }
    if (row.forwarded) {
      html += metaRow(t('priceOfferForwarded', 'Forwarded from the previous seller'), esc('—'));
    }
    html += '</dl>';
    $summary.html(html);
    $foot.prop('hidden', row.status !== 'pending');
    activeOfferId = parseInt(row.id, 10) || 0;
  }

  function openModal(row) {
    if (!row) {
      return;
    }
    fillModal(row);
    var $ov = $overlay();
    $ov.prop('hidden', false);
    window.requestAnimationFrame(function () {
      $ov.addClass('is-open');
    });
    $('body').addClass('sutore-mp-modal-open');
  }

  function closeModal() {
    var $ov = $overlay();
    $ov.removeClass('is-open').addClass('is-closing');
    window.setTimeout(function () {
      $ov.prop('hidden', true).removeClass('is-closing');
      activeOfferId = 0;
      clearOfferQuery();
    }, 180);
    $('body').removeClass('sutore-mp-modal-open');
  }

  function filterState() {
    var $filter = $page().find('.sutore-mp-price-offers-filter');
    var $sort = $page().find('.sutore-mp-price-offers-sort');
    return {
      status: String($filter.find('[name="status"]').val() || ''),
      orderby: String($sort.find('[name="orderby"]').val() || 'created_desc')
    };
  }

  function loadOffers(page) {
    var $root = $('.sutore-mp-price-offers-results');
    if (!$root.length) {
      return;
    }
    currentPage = page || 1;
    var state = filterState();
    SutoreMarketplace.setFilterBadge($page(), state.status && state.status !== 'pending' ? 1 : 0);
    SutoreMarketplace.setSortBadge($page(), state.orderby !== 'created_desc');
    var payload = { page: currentPage, per_page: 20, orderby: state.orderby };
    if (state.status) {
      payload.status = state.status;
    }
    $root.attr('aria-busy', 'true').html(
      '<div class="sutore-mp-list-loading" role="status"><span class="sutore-mp-list-spinner" aria-hidden="true"></span></div>'
    );
    api('marketplace_price_offers_query', payload).done(function (res) {
      $('.sutore-mp-list-chrome').prop('hidden', false);
      if (!res || !res.success) {
        $root.html('<p class="sutore-mp-error">' + esc((res && res.data && res.data.message) || t('error', 'Error')) + '</p>');
        return;
      }
      var items = (res.data && res.data.items) || [];
      offerCache = {};
      items.forEach(function (row) {
        offerCache[String(row.id)] = row;
      });
      $root.empty();
      if (!items.length) {
        $root.html('<p class="sutore-mp-empty">' + esc(t('priceOffersEmpty', 'You have no customer offers.')) + '</p>');
      } else {
        items.forEach(function (row) {
          $root.append(renderCard(row));
        });
      }
      var deep = queryOfferId();
      if (deep && offerCache[String(deep)]) {
        openModal(offerCache[String(deep)]);
      }
    }).always(function () {
      $root.attr('aria-busy', 'false');
    });
  }

  function acceptOffer(id) {
    showConfirm(
      t('priceOfferAccept', 'Accept'),
      t('priceOfferAcceptConfirm', 'Accept this offer? A personal coupon will be issued. Your public asking price will not change.'),
      t('priceOfferAccept', 'Accept'),
      function () {
        api('marketplace_price_offer_accept', { offer_id: id }).done(function (res) {
          if (!res || !res.success) {
            window.alert((res && res.data && res.data.message) || t('error', 'Error'));
            return;
          }
          closeModal();
          loadOffers(currentPage);
        });
      }
    );
  }

  function declineOffer(id) {
    showConfirm(
      t('priceOfferDecline', 'Decline'),
      t('priceOfferDeclineConfirm', 'Decline this offer? It may be sent to the next seller in the queue.'),
      t('priceOfferDecline', 'Decline'),
      function () {
        api('marketplace_price_offer_decline', { offer_id: id }).done(function (res) {
          if (!res || !res.success) {
            window.alert((res && res.data && res.data.message) || t('error', 'Error'));
            return;
          }
          closeModal();
          loadOffers(currentPage);
        });
      }
    );
  }

  $(function () {
    if (!$page().length) {
      return;
    }
    $page().on('click', '.sutore-mp-open-offer', function () {
      openModal(offerCache[String($(this).attr('data-id') || '')]);
    });
    $page().on('click', '.sutore-mp-offer-close', closeModal);
    $page().on('click', '.sutore-mp-price-accept', function () {
      acceptOffer(activeOfferId);
    });
    $page().on('click', '.sutore-mp-price-decline', function () {
      declineOffer(activeOfferId);
    });
    $page().on('click', '.sutore-mp-price-offers-filter-apply, .sutore-mp-price-offers-sort-apply', function (e) {
      e.preventDefault();
      SutoreMarketplace.closeListOverlays($page());
      loadOffers(1);
    });
    $page().on('click', '.sutore-mp-price-offers-filter-clear', function (e) {
      e.preventDefault();
      $page().find('[name="status"]').val('pending');
      SutoreMarketplace.closeListOverlays($page());
      loadOffers(1);
    });
    loadOffers(1);
  });
})(jQuery);
