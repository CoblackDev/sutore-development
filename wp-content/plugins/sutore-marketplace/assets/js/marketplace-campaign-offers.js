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
    return $('.sutore-mp-campaign-offers');
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
      $card.addClass('is-campaign-offer');
    } else if (row.status === 'accepted') {
      $card.addClass('is-campaign-active');
    }

    var $main = $('<div class="sutore-mp-card-main"/>');
    if (row.thumbnail && typeof thumbBox === 'function') {
      $main.append(thumbBox('sutore-mp-card-thumb-box', 'sutore-mp-card-thumb', row.thumbnail, row.product_title || ''));
    } else if (typeof thumbBox === 'function') {
      $main.append(thumbBox('sutore-mp-card-thumb-box', 'sutore-mp-card-thumb', '', ''));
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

    if (row.product_code) {
      $info.append($('<div class="sutore-mp-card-code"/>').text(row.product_code));
    }

    var metaParts = [];
    if (row.variation_id) {
      metaParts.push('#' + row.variation_id);
    }
    if (row.size_label) {
      metaParts.push(row.size_label);
    }
    if (row.campaign_name) {
      metaParts.push(row.campaign_name);
    }
    if (row.source_label) {
      metaParts.push(row.source_label);
    }
    metaParts.push(row.status_label || row.status || '');
    $info.append(
      $('<div class="sutore-mp-card-meta"/>').html(metaParts.map(esc).join(' · '))
    );

    if (row.headline) {
      $info.append($('<div class="sutore-mp-card-offer-headline"/>').text(row.headline));
    }

    var discountLine =
      t('campaignAskingBefore', 'Current asking') +
      ': ' +
      money(row.asking_before) +
      ' → ' +
      money(row.asking_effective) +
      ' · ' +
      t('campaignSellerDiscount', 'Your discount') +
      ': −' +
      (row.seller_discount_label || money(row.seller_discount)) +
      ' · ' +
      t('campaignPlatformDiscount', 'Platform discount') +
      ': −' +
      (row.platform_discount_label || money(row.platform_discount));
    $info.append($('<div class="sutore-mp-card-offer-discounts"/>').text(discountLine));

    if (row.ends_at_label || row.ends_at) {
      $info.append(
        $('<div class="sutore-mp-card-campaign-end"/>').text(
          t('campaignEndsAt', 'Campaign ends') + ': ' + (row.ends_at_label || row.ends_at)
        )
      );
    }

    var $tags = $('<div class="sutore-mp-card-tags"/>');
    if (row.status === 'pending') {
      $tags.append(
        $('<span class="sutore-mp-tag is-campaign-offer"/>').text(
          t('campaignOfferTag', 'Campaign offer')
        )
      );
    }
    if (row.is_sourcing) {
      $tags.append(
        $('<span class="sutore-mp-tag is-sourcing"/>').text(t('preOrderProduct', 'Pre-order'))
      );
    }
    if ($tags.children().length) {
      $info.append($tags);
    }

    var $actions = $('<div class="sutore-mp-card-actions"/>');
    $actions.append(
      $('<button type="button" class="wp-element-button sutore-mp-open-offer"/>')
        .attr('data-id', String(row.id))
        .text(t('reviewCampaignOffer', 'Review offer'))
    );
    $info.append($actions);
    $main.append($info);
    $card.append($main);
    return $card;
  }

  function metaRow(label, valueHtml) {
    return (
      '<div><dt>' +
      esc(label) +
      '</dt><dd>' +
      valueHtml +
      '</dd></div>'
    );
  }

  function fillModal(row) {
    var $ov = $overlay();
    var $media = $ov.find('.sutore-mp-manage-modal__media').empty();
    var $badge = $ov.find('.sutore-mp-manage-modal__badge');
    var $sub = $ov.find('.sutore-mp-manage-modal__sub');
    var $title = $ov.find('#sutore-mp-offer-modal-title');
    var $summary = $ov.find('.sutore-mp-offer-modal-summary');
    var $foot = $ov.find('.sutore-mp-offer-modal-foot');

    var title = row.product_title || t('campaignOfferTitle', 'Campaign offer');
    if (row.permalink) {
      $title.empty().append(
        $('<a class="sutore-mp-manage-modal__title-link"/>')
          .attr('href', row.permalink)
          .attr('target', '_blank')
          .attr('rel', 'noopener noreferrer')
          .text(title)
      );
    } else {
      $title.text(title);
    }

    var subParts = [];
    if (row.product_code) {
      subParts.push(row.product_code);
    }
    if (row.variation_id) {
      subParts.push('#' + row.variation_id);
    }
    if (row.campaign_name) {
      subParts.push(row.campaign_name);
    }
    $sub.text(subParts.join(' · '));

    if (row.status_label) {
      $badge.text(row.status_label).prop('hidden', false);
    } else {
      $badge.prop('hidden', true);
    }

    if (row.thumbnail && typeof thumbBox === 'function') {
      var $thumb = thumbBox(
        'sutore-mp-manage-modal__thumb-box',
        'sutore-mp-manage-modal__thumb',
        row.thumbnail,
        title
      );
      if (row.permalink) {
        $media.append(
          $('<a class="sutore-mp-manage-modal__media-link"/>')
            .attr('href', row.permalink)
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
      $media.prop('hidden', true);
    }

    var html = '<dl class="sutore-mp-form-context-meta sutore-mp-manage-summary-meta sutore-mp-offer-meta">';
    if (row.headline) {
      html += metaRow(t('campaignHeadline', 'Suggestion'), esc(row.headline));
    }
    if (row.source_label) {
      html += metaRow(t('campaignSource', 'Source'), esc(row.source_label));
    }
    if (row.size_label) {
      html += metaRow(t('size', 'Size'), esc(row.size_label));
    }
    html += metaRow(t('campaignAskingBefore', 'Current asking'), esc(money(row.asking_before)));
    html += metaRow(
      t('campaignSellerDiscount', 'Your discount'),
      esc('−' + (row.seller_discount_label || money(row.seller_discount)))
    );
    html += metaRow(
      t('campaignPlatformDiscount', 'Platform discount'),
      esc('−' + (row.platform_discount_label || money(row.platform_discount)))
    );
    html += metaRow(t('campaignAskingAfter', 'Asking after accept'), esc(money(row.asking_effective)));
    if (row.starts_at_label || row.starts_at) {
      html += metaRow(t('campaignStartsAt', 'Campaign starts'), esc(row.starts_at_label || row.starts_at));
    }
    if (row.ends_at_label || row.ends_at) {
      html += metaRow(t('campaignEndsAt', 'Campaign ends'), esc(row.ends_at_label || row.ends_at));
    }
    html += '</dl>';
    $summary.html(html);

    if (row.status === 'pending') {
      $foot.prop('hidden', false);
      var $decline = $foot.find('.sutore-mp-campaign-decline');
      $decline.text(
        row.source === 'system'
          ? t('campaignNotNow', 'Not now')
          : t('campaignOfferDecline', 'Decline')
      );
    } else {
      $foot.prop('hidden', true);
    }

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
    var $filter = $page().find('.sutore-mp-campaign-offers-filter');
    var $sort = $page().find('.sutore-mp-campaign-offers-sort');
    return {
      status: String($filter.find('[name="status"]').val() || ''),
      orderby: String($sort.find('[name="orderby"]').val() || 'created_desc')
    };
  }

  function updateBadges(state) {
    var filterCount = state.status && state.status !== 'pending' ? 1 : 0;
    SutoreMarketplace.setFilterBadge($page(), filterCount);
    SutoreMarketplace.setSortBadge($page(), state.orderby !== 'created_desc');
  }

  function loadOffers(page) {
    var $root = $('.sutore-mp-campaign-offers-results');
    if (!$root.length) {
      return;
    }
    currentPage = page || 1;
    var state = filterState();
    updateBadges(state);
    var payload = { page: currentPage, per_page: 20, orderby: state.orderby };
    if (state.status) {
      payload.status = state.status;
    }

    $root.attr('aria-busy', 'true').html(
      '<div class="sutore-mp-list-loading" role="status">' +
        '<span class="sutore-mp-list-spinner" aria-hidden="true"></span>' +
        '<span class="screen-reader-text">' +
        esc(t('loading', 'Loading…')) +
        '</span></div>'
    );

    api('marketplace_campaign_offers_query', payload).done(function (res) {
      $('.sutore-mp-list-chrome').prop('hidden', false);
      if (!res || !res.success) {
        var msg = (res && res.data && res.data.message) || t('error', 'Error');
        $root.html('<p class="sutore-mp-error">' + esc(msg) + '</p>');
        return;
      }

      var items = (res.data && res.data.items) || [];
      offerCache = {};
      items.forEach(function (row) {
        offerCache[String(row.id)] = row;
      });

      $root.empty();
      if (!items.length) {
        var hasFilters = state.status && state.status !== 'pending';
        $root.html(
          '<p class="sutore-mp-empty">' +
            esc(
              hasFilters
                ? t('noResults', 'No results found.')
                : t('campaignOffersEmpty', 'You have no campaign offers.')
            ) +
            '</p>'
        );
      } else {
        items.forEach(function (row) {
          $root.append(renderCard(row));
        });
      }

      var total = (res.data && res.data.total) || 0;
      var perPage = (res.data && res.data.per_page) || 20;
      var pages = Math.max(1, Math.ceil(total / perPage));
      var $pager = $page().find('.sutore-mp-list-pager').empty();
      if (pages > 1 && items.length) {
        for (var i = 1; i <= pages; i++) {
          (function (p) {
            var $b = $('<button type="button" class="wp-element-button is-style-outline"/>').text(p);
            if (p === currentPage) {
              $b.removeClass('is-style-outline');
            }
            $b.on('click', function () {
              loadOffers(p);
            });
            $pager.append($b);
          })(i);
        }
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
      t('campaignOfferAccept', 'Accept'),
      t(
        'campaignOfferAcceptConfirm',
        'Accept this campaign offer? Your listing price will be updated for the campaign period.'
      ),
      t('campaignOfferAccept', 'Accept'),
      function () {
        api('marketplace_campaign_offer_accept', { offer_id: id }).done(function (res) {
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
      t('campaignOfferDecline', 'Decline'),
      t('campaignOfferDeclineConfirm', 'Decline this campaign offer?'),
      t('campaignOfferDecline', 'Decline'),
      function () {
        api('marketplace_campaign_offer_decline', { offer_id: id }).done(function (res) {
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

  $(document).on('click', '.sutore-mp-campaign-offers-filter-apply', function () {
    SutoreMarketplace.closeListOverlays($page());
    loadOffers(1);
  });

  $(document).on('click', '.sutore-mp-campaign-offers-filter-clear', function () {
    $page().find('.sutore-mp-campaign-offers-filter [name="status"]').val('pending');
    SutoreMarketplace.closeListOverlays($page());
    loadOffers(1);
  });

  $(document).on('click', '.sutore-mp-campaign-offers-sort-apply', function () {
    SutoreMarketplace.closeListOverlays($page());
    loadOffers(1);
  });

  $(document).on('click', '.sutore-mp-campaign-offers-sort-clear', function () {
    $page().find('.sutore-mp-campaign-offers-sort [name="orderby"]').val('created_desc');
    SutoreMarketplace.closeListOverlays($page());
    loadOffers(1);
  });

  $(document).on('click', '.sutore-mp-open-offer', function (e) {
    e.preventDefault();
    var id = String($(this).data('id') || $(this).closest('[data-id]').data('id') || '');
    if (offerCache[id]) {
      openModal(offerCache[id]);
    }
  });

  $(document).on('click', '.sutore-mp-offer-close', function () {
    closeModal();
  });

  $(document).on('click', '.sutore-mp-offer-overlay', function (e) {
    if (e.target === this) {
      closeModal();
    }
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && $overlay().hasClass('is-open')) {
      closeModal();
    }
  });

  $(document).on('click', '.sutore-mp-offer-modal .sutore-mp-campaign-accept', function () {
    if (activeOfferId > 0) {
      acceptOffer(activeOfferId);
    }
  });

  $(document).on('click', '.sutore-mp-offer-modal .sutore-mp-campaign-decline', function () {
    if (activeOfferId > 0) {
      declineOffer(activeOfferId);
    }
  });

  $(function () {
    if ($('.sutore-mp-campaign-offers').length) {
      loadOffers(1);
    }
  });
})(jQuery);
