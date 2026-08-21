(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplacePdpOffers || {};
  var lastCtx = null;
  var lastFocus = null;

  var t = function (key, fallback) {
    return (cfg.i18n && cfg.i18n[key]) || fallback;
  };

  function rest(path, method, body) {
    method = method || 'GET';
    var core = window.SutoreMarketplace;
    if (core && typeof core.request === 'function') {
      return core.request(method, path, {
        query: method === 'GET' ? body : undefined,
        body: method !== 'GET' ? body || {} : undefined,
        restUrl: cfg.restUrl,
        restNonce: cfg.restNonce
      });
    }
    return $.ajax({
      url: (cfg.restUrl || '') + path,
      method: method,
      data: method === 'POST' ? JSON.stringify(body || {}) : body,
      contentType: method === 'POST' ? 'application/json' : undefined,
      beforeSend: function (xhr) {
        if (cfg.restNonce) {
          xhr.setRequestHeader('X-WP-Nonce', cfg.restNonce);
        }
      }
    });
  }

  function money(n) {
    return Number(n || 0).toLocaleString() + ' TL';
  }

  function $triggers() {
    return $('[data-sutore-pdp-offer-open]');
  }

  function $modal() {
    return $('[data-sutore-pdp-offer-modal]');
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

  function setTriggers(opts) {
    var $btn = $triggers();
    $btn.prop('hidden', !opts.visible);
    $btn.prop('disabled', !!opts.disabled);
    $btn.text(opts.label || t('makeOffer', 'Make an offer'));
  }

  function modalOpen() {
    var $ov = $modal();
    if (!$ov.length || $ov.hasClass('is-open')) {
      return;
    }
    lastFocus = document.activeElement;
    $ov.prop('hidden', false).addClass('is-open');
    $('body').addClass('sutore-mp-modal-open');
    window.setTimeout(function () {
      var $form = $ov.find('.sutore-mp-pdp-offer__form');
      if ($form.length && !$form.prop('hidden')) {
        $('#sutore-mp-pdp-offer-bid').trigger('focus');
        return;
      }
      $ov.find('[data-sutore-pdp-offer-close]').trigger('focus');
    }, 20);
  }

  function modalClose() {
    var $ov = $modal();
    $ov.removeClass('is-open').prop('hidden', true);
    $('body').removeClass('sutore-mp-modal-open');
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus();
    }
  }

  function fillBidBounds(ctx) {
    var $bid = $('#sutore-mp-pdp-offer-bid');
    var minBid = ctx.min_bid || 1;
    var maxBid = ctx.max_bid || Math.max(0, Number(ctx.customer_price || 0) - Number(ctx.price_step || 1));
    $bid.attr('min', minBid);
    $bid.attr('max', maxBid);
    $bid.attr('step', ctx.price_step || 1);
    var current = parseFloat($bid.val() || '0');
    if (!current || current < minBid || current > maxBid) {
      $bid.val(minBid);
    }
  }

  function renderContext(ctx) {
    lastCtx = ctx || null;
    var $ov = $modal();
    var $lead = $ov.find('[data-sutore-pdp-offer-lead]');
    var $form = $ov.find('.sutore-mp-pdp-offer__form');
    var $status = $ov.find('.sutore-mp-pdp-offer__status');
    $status.text('');

    if (!ctx || !ctx.enabled) {
      setTriggers({ visible: false });
      return;
    }

    if (ctx.pending_offer) {
      setTriggers({
        visible: true,
        disabled: false,
        label: t('offerPending', 'Offer pending')
      });
      $form.prop('hidden', true);
      var pendingAmount = ctx.pending_offer.bid_amount;
      var pendingText = t('pending', 'You already have a pending offer on this size.');
      if (pendingAmount) {
        pendingText += ' ' + t('yourOffer', 'Your offer') + ': ' + money(pendingAmount);
      }
      $lead.text(pendingText);
      if (cfg.myOffersUrl) {
        $lead.append(' ').append(
          $('<a/>').attr('href', cfg.myOffersUrl).text(t('viewOffers', 'View my offers'))
        );
      }
      return;
    }

    if (ctx.accepted_offer) {
      setTriggers({
        visible: true,
        disabled: false,
        label: t('offerAccepted', 'Offer accepted')
      });
      $form.prop('hidden', true);
      var accepted = t('accepted', 'Your offer was accepted. Use your coupon at checkout.');
      if (ctx.accepted_offer.bid_amount) {
        accepted += ' ' + t('yourOffer', 'Your offer') + ': ' + money(ctx.accepted_offer.bid_amount);
      }
      if (cfg.myOffersUrl) {
        $lead.empty().text(accepted + ' ').append(
          $('<a/>').attr('href', cfg.myOffersUrl).text(t('viewOffers', 'View my offers'))
        );
      } else {
        $lead.text(accepted);
      }
      return;
    }

    if (!ctx.can_offer && ctx.reason === 'login') {
      setTriggers({
        visible: true,
        disabled: false,
        label: t('makeOffer', 'Make an offer')
      });
      $form.prop('hidden', true);
      $lead.empty().append(
        $('<a/>').attr('href', cfg.loginUrl || '#').text(t('loginToOffer', 'Log in to make an offer.'))
      );
      return;
    }

    if (!ctx.can_offer) {
      setTriggers({ visible: false });
      $form.prop('hidden', true);
      $lead.text('');
      return;
    }

    setTriggers({
      visible: true,
      disabled: false,
      label: t('makeOffer', 'Make an offer')
    });
    $form.prop('hidden', false).data('variation-id', ctx.variation_id);
    $lead.text(
      t('listedPrice', 'Listed price') + ': ' + money(ctx.customer_price) + ' · ' +
      t('minBid', 'Minimum offer') + ': ' + money(ctx.min_bid)
    );
    fillBidBounds(ctx);
  }

  function loadContext() {
    var variationId = selectedVariationId();
    if (variationId <= 0) {
      lastCtx = null;
      setTriggers({ visible: false });
      return;
    }
    rest('my-offers/context', 'GET', { variation_id: variationId }).done(function (res) {
      var ctx = res && res.data ? res.data : res;
      renderContext(ctx);
    }).fail(function () {
      lastCtx = null;
      setTriggers({ visible: false });
    });
  }

  $(function () {
    if (!$triggers().length || !$modal().length) {
      return;
    }

    $('form.variations_form').on('found_variation hide_variation show_variation', function () {
      window.setTimeout(loadContext, 50);
    });

    $triggers().on('click', function (e) {
      e.preventDefault();
      if (!lastCtx) {
        return;
      }
      if (!cfg.loggedIn && lastCtx.reason === 'login') {
        window.location.href = cfg.loginUrl || '/';
        return;
      }
      modalOpen();
    });

    $modal().on('click', '[data-sutore-pdp-offer-close]', function (e) {
      e.preventDefault();
      modalClose();
    });

    $modal().on('click', function (e) {
      if (e.target === this) {
        modalClose();
      }
    });

    $(document).on('keydown.sutorePdpOffer', function (e) {
      if (e.key === 'Escape' && $modal().hasClass('is-open')) {
        modalClose();
      }
    });

    $modal().on('submit', '.sutore-mp-pdp-offer__form', function (e) {
      e.preventDefault();
      if (!cfg.loggedIn) {
        window.location.href = cfg.loginUrl || '/';
        return;
      }
      if (!lastCtx || !lastCtx.can_offer) {
        return;
      }
      var variationId = parseInt($(this).data('variation-id') || selectedVariationId() || '0', 10);
      var bid = parseFloat($('#sutore-mp-pdp-offer-bid').val() || '0');
      var $status = $modal().find('.sutore-mp-pdp-offer__status');
      $status.text('');
      if (bid > Number(lastCtx.max_bid || 0)) {
        $status.text(t('bidHigh', 'Your offer must be below the current price. Add the product to your cart to buy at the listed price.'));
        return;
      }
      var $submit = $(this).find('.sutore-mp-pdp-offer__submit');
      $submit.prop('disabled', true);
      rest('my-offers', 'POST', { variation_id: variationId, bid_amount: bid }).done(function (res) {
        $submit.prop('disabled', false);
        if (res && res.success) {
          $status.text((res.data && res.data.message) || t('sent', 'Your offer was sent to the seller.'));
          loadContext();
          return;
        }
        $status.text((res && res.message) || t('error', 'Error'));
      }).fail(function (xhr) {
        $submit.prop('disabled', false);
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
