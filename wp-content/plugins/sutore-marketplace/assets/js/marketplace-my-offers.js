(function ($) {
  'use strict';

  var t = SutoreMarketplace.t;
  var api = SutoreMarketplace.api;
  var showConfirm = SutoreMarketplace.showConfirm;
  var thumbBox = SutoreMarketplace.thumbBox;

  function money(n) {
    var v = Number(n || 0);
    return (Math.round(v * 100) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' TL';
  }

  function esc(str) {
    return $('<div/>').text(str == null ? '' : String(str)).html();
  }

  function renderCard(row) {
    var $card = $('<div class="sutore-mp-card sutore-mp-offer-card"/>');
    if (row.status === 'pending') {
      $card.addClass('is-price-offer');
    } else if (row.status === 'accepted') {
      $card.addClass('is-campaign-active');
    }
    var $main = $('<div class="sutore-mp-card-main"/>');
    var permalink = row.permalink || '';
    if (typeof thumbBox === 'function') {
      var $thumb = thumbBox('sutore-mp-card-thumb-box', 'sutore-mp-card-thumb', row.thumbnail || '', row.product_title || '');
      if (permalink) {
        $main.append(
          $('<a class="sutore-mp-card-thumb-link"/>')
            .attr('href', permalink)
            .attr('target', '_blank')
            .attr('rel', 'noopener noreferrer')
            .append($thumb)
        );
      } else {
        $main.append($thumb);
      }
    }
    var $info = $('<div class="sutore-mp-card-info"/>');
    if (permalink) {
      $info.append(
        $('<a class="sutore-mp-card-title"/>')
          .attr('href', permalink)
          .attr('target', '_blank')
          .attr('rel', 'noopener noreferrer')
          .text(row.product_title || '')
      );
    } else {
      $info.append($('<div class="sutore-mp-card-title"/>').text(row.product_title || ''));
    }
    var meta = [row.size_label, row.status_label].filter(Boolean);
    $info.append($('<div class="sutore-mp-card-meta"/>').text(meta.join(' · ')));
    $info.append(
      $('<div class="sutore-mp-card-offer-discounts"/>').text(
        t('myOfferBid', 'Your bid') + ': ' + money(row.bid_amount)
      )
    );
    if (row.coupon_code) {
      $info.append(
        $('<div class="sutore-mp-card-code"/>').text(
          t('myOfferCoupon', 'Coupon') + ': ' + row.coupon_code
        )
      );
    }
    if (row.remaining_label) {
      $info.append($('<div class="sutore-mp-card-remaining"/>').text(row.remaining_label));
    }
    var $actions = $('<div class="sutore-mp-card-actions"/>');
    if (row.status === 'pending') {
      $actions.append(
        $('<button type="button" class="wp-element-button is-style-outline sutore-mp-cancel-offer"/>')
          .attr('data-id', String(row.id))
          .text(t('myOfferCancel', 'Cancel offer'))
      );
    }
    if (row.status === 'accepted' && row.add_to_cart_url) {
      $actions.append(
        $('<a class="wp-element-button sutore-mp-offer-cart"/>')
          .attr('href', row.add_to_cart_url)
          .text(t('myOfferAddToCart', 'Add to cart'))
      );
    }
    if ($actions.children().length) {
      $info.append($actions);
    }
    $main.append($info);
    $card.append($main);
    return $card;
  }

  function loadOffers() {
    var $root = $('.sutore-mp-my-offers-results');
    if (!$root.length) {
      return;
    }
    $root.attr('aria-busy', 'true').html(
      '<div class="sutore-mp-list-loading" role="status"><span class="sutore-mp-list-spinner" aria-hidden="true"></span></div>'
    );
    api('marketplace_my_offers_query', { per_page: 50 }).done(function (res) {
      $('.sutore-mp-list-chrome').prop('hidden', false);
      if (!res || !res.success) {
        $root.html('<p class="sutore-mp-error">' + esc((res && res.data && res.data.message) || t('error', 'Error')) + '</p>');
        return;
      }
      var items = (res.data && res.data.items) || [];
      $root.empty();
      if (!items.length) {
        $root.html('<p class="sutore-mp-empty">' + esc(t('myOffersEmpty', 'You have not sent any offers yet.')) + '</p>');
        return;
      }
      items.forEach(function (row) {
        $root.append(renderCard(row));
      });
    }).always(function () {
      $root.attr('aria-busy', 'false');
    });
  }

  $(function () {
    var $page = $('.sutore-mp-my-offers');
    if (!$page.length) {
      return;
    }
    $page.on('click', '.sutore-mp-cancel-offer', function () {
      var id = parseInt($(this).attr('data-id') || '0', 10);
      showConfirm(
        t('myOfferCancel', 'Cancel offer'),
        t('myOfferCancelConfirm', 'Cancel this pending offer?'),
        t('myOfferCancel', 'Cancel offer'),
        function () {
          api('marketplace_my_offer_cancel', { offer_id: id }).done(function (res) {
            if (!res || !res.success) {
              window.alert((res && res.data && res.data.message) || t('error', 'Error'));
              return;
            }
            loadOffers();
          });
        }
      );
    });
    loadOffers();
  });
})(jQuery);
