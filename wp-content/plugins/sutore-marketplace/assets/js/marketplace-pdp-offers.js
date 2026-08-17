(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplacePdpOffers || {};
  var t = function (key, fallback) {
    return (cfg.i18n && cfg.i18n[key]) || fallback;
  };

  function rest(path, method, body) {
    return $.ajax({
      url: (cfg.restUrl || '') + path,
      method: method || 'GET',
      data: method === 'POST' ? JSON.stringify(body || {}) : body,
      contentType: method === 'POST' ? 'application/json' : undefined,
      beforeSend: function (xhr) {
        if (cfg.restNonce) {
          xhr.setRequestHeader('X-WP-Nonce', cfg.restNonce);
        }
      }
    });
  }

  function selectedVariationId() {
    var $form = $('form.variations_form');
    var id = parseInt($form.find('input.variation_id').val() || '0', 10);
    if (id > 0) {
      return id;
    }
    var simple = parseInt($('form.cart input[name="product_id"], form.cart button[name="add-to-cart"]').val() || '0', 10);
    return simple > 0 ? simple : 0;
  }

  function renderContext(ctx) {
    var $box = $('[data-sutore-pdp-offer]');
    if (!$box.length) {
      return;
    }
    var $lead = $box.find('.sutore-mp-pdp-offer__lead');
    var $form = $box.find('.sutore-mp-pdp-offer__form');
    var $status = $box.find('.sutore-mp-pdp-offer__status');
    var $bid = $box.find('#sutore-mp-pdp-offer-bid');
    $status.text('');

    if (!ctx || !ctx.enabled) {
      $box.prop('hidden', true);
      return;
    }

    if (ctx.pending_offer) {
      $box.prop('hidden', false);
      $form.prop('hidden', true);
      $lead.text(t('pending', 'You already have a pending offer on this size.'));
      return;
    }
    if (ctx.accepted_offer) {
      $box.prop('hidden', false);
      $form.prop('hidden', true);
      var accepted = t('accepted', 'Your offer was accepted. Use your coupon at checkout.');
      if (cfg.myOffersUrl) {
        accepted += ' ';
        $lead.empty().text(accepted).append(
          $('<a/>').attr('href', cfg.myOffersUrl).text(t('viewOffers', 'View my offers'))
        );
      } else {
        $lead.text(accepted);
      }
      return;
    }

    if (!ctx.can_offer && ctx.reason === 'login') {
      $box.prop('hidden', false);
      $form.prop('hidden', true);
      $lead.empty().append(
        $('<a/>').attr('href', cfg.loginUrl || '#').text(t('loginToOffer', 'Log in to make an offer.'))
      );
      return;
    }

    if (!ctx.can_offer) {
      $box.prop('hidden', true);
      return;
    }

    $box.prop('hidden', false);
    $form.prop('hidden', false);
    $lead.text(
      t('asking', 'Seller asking') + ': ' + Number(ctx.asking || 0).toLocaleString() + ' TL · ' +
      t('minBid', 'Minimum offer') + ': ' + Number(ctx.min_bid || 0).toLocaleString() + ' TL'
    );
    $bid.attr('min', ctx.min_bid || 1);
    $bid.attr('step', ctx.price_step || 1);
    if (!$bid.val()) {
      $bid.val(ctx.min_bid || '');
    }
    $form.data('variation-id', ctx.variation_id);
  }

  function loadContext() {
    var variationId = selectedVariationId();
    if (variationId <= 0) {
      $('[data-sutore-pdp-offer]').prop('hidden', true);
      return;
    }
    rest('my-offers/context', 'GET', { variation_id: variationId }).done(function (res) {
      var ctx = res && res.data ? res.data : res;
      renderContext(ctx);
    }).fail(function () {
      $('[data-sutore-pdp-offer]').prop('hidden', true);
    });
  }

  $(function () {
    if (!$('[data-sutore-pdp-offer]').length) {
      return;
    }
    $('form.variations_form').on('found_variation hide_variation show_variation', function () {
      window.setTimeout(loadContext, 50);
    });
    $('[data-sutore-pdp-offer]').on('submit', '.sutore-mp-pdp-offer__form', function (e) {
      e.preventDefault();
      if (!cfg.loggedIn) {
        window.location.href = cfg.loginUrl || '/';
        return;
      }
      var variationId = parseInt($(this).data('variation-id') || selectedVariationId() || '0', 10);
      var bid = parseFloat($('#sutore-mp-pdp-offer-bid').val() || '0');
      var $status = $('[data-sutore-pdp-offer] .sutore-mp-pdp-offer__status');
      $status.text('');
      rest('my-offers', 'POST', { variation_id: variationId, bid_amount: bid }).done(function (res) {
        if (res && res.success) {
          $status.text((res.data && res.data.message) || t('sent', 'Your offer was sent to the seller.'));
          loadContext();
          return;
        }
        $status.text((res && res.message) || t('error', 'Error'));
      }).fail(function (xhr) {
        var msg = t('error', 'Error');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        $status.text(msg);
      });
    });
    loadContext();
  });
})(jQuery);
