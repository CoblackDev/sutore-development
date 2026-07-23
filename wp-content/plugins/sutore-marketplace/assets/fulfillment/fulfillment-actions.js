(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplaceFulfillment || {};
  var i18n = cfg.i18n || {};
  var core = window.SutoreMarketplace || {};

  function t(key, def) {
    return i18n[key] || def;
  }

  function api(action, data) {
    core.restUrl = core.restUrl || cfg.restUrl;
    core.restNonce = core.restNonce || cfg.restNonce;
    if (!core.restRequest) {
      var d = $.Deferred();
      d.resolve({ success: false, data: { message: t('error', 'Error') } });
      return d.promise();
    }
    return core.restRequest(action, data || {});
  }

  function modal(title, bodyHtml, actions) {
    $('.sutore-mp-ful-modal').remove();
    var $m = $('<div class="sutore-mp-ful-modal sutore-mp-confirm"/>');
    var $card = $('<div class="sutore-mp-confirm-card"/>');
    $card.append($('<div class="sutore-mp-confirm-head"/>').append($('<strong/>').text(title)).append(
      $('<button type="button" class="sutore-mp-confirm-x sutore-mp-ful-close"/>').text('×')
    ));
    var $body = $('<div class="sutore-mp-confirm-body"/>').html(bodyHtml);
    $body.append($('<p class="sutore-mp-confirm-error sutore-mp-ful-modal-error" aria-live="polite"/>'));
    $card.append($body);
    var $acts = $('<div class="sutore-mp-confirm-actions"/>');
    (actions || []).forEach(function (a) {
      $acts.append($('<button type="button"/>').addClass(a.cls || 'wp-element-button').text(a.label).on('click', a.onClick));
    });
    $card.append($acts);
    $m.append($card);
    $('body').append($m);
    $m.on('click', '.sutore-mp-ful-close', function () { $m.remove(); });
    return $m;
  }

  function setModalError($m, msg) {
    $m.find('.sutore-mp-ful-modal-error').text(msg || '');
  }

  $(document).on('click', '.sutore-mp-ful-details', function () {
    var id = $(this).data('listing-id');
    api('marketplace_fulfillment_details', { listing_id: id }).done(function (res) {
      if (!res || !res.success) {
        if (typeof core.showAlert === 'function') {
          core.showAlert(t('error', 'Error'), (res && res.data && res.data.message) || t('error', 'Error'));
        } else {
          alert((res && res.data && res.data.message) || t('error', 'Error'));
        }
        return;
      }
      var d = res.data;
      var html = '<table class="sutore-mp-ful-details-table"><tbody>';
      html += '<tr><td>' + t('order', 'Order') + '</td><td>#' + d.order_id + '</td></tr>';
      html += '<tr><td>' + t('status', 'Status') + '</td><td>' + (d.status_label || '') + '</td></tr>';
      html += '<tr><td>' + t('price', 'Price') + '</td><td>' + (d.asking_display || '') + '</td></tr>';
      html += '<tr><td>' + t('payout', 'Net payout') + '</td><td>' + (d.net_payout_display || '') + '</td></tr>';
      if (d.confirm_deadline_at) {
        html += '<tr><td>' + t('confirmDeadline', 'Confirmation deadline') + '</td><td>' + d.confirm_deadline_at + '</td></tr>';
      }
      if (d.cargo_deadline_at) {
        html += '<tr><td>' + t('cargoDeadline', 'Shipping deadline') + '</td><td>' + d.cargo_deadline_at + '</td></tr>';
      }
      html += '</tbody></table>';
      if (d.shipment_hint) {
        html += '<p class="sutore-mp-ful-hint">' + d.shipment_hint + '</p>';
      }
      modal(t('details', 'Detail'), html, [{ label: t('close', 'Close'), cls: 'wp-element-button is-style-outline sutore-mp-ful-close', onClick: function () { $('.sutore-mp-ful-modal').remove(); } }]);
    });
  });

  $(document).on('click', '.sutore-mp-ful-confirm', function () {
    var id = $(this).data('listing-id');
    var $m = modal(t('confirmSaleTitle', 'Confirm this sale?'), '<p>' + t('confirmSaleBody', 'After confirming, you must hand the product over for shipping within the specified time.') + '</p>', [
      { label: t('cancel', 'Cancel'), cls: 'wp-element-button is-style-outline sutore-mp-ful-close', onClick: function () { $('.sutore-mp-ful-modal').remove(); } },
      { label: t('yes', 'Yes'), cls: 'wp-element-button sutore-mp-ful-confirm-ok', onClick: function () {
        var $btn = $m.find('.sutore-mp-ful-confirm-ok');
        if ($btn.prop('disabled')) {
          return;
        }
        setModalError($m, '');
        $btn.prop('disabled', true);
        api('marketplace_fulfillment_confirm', { listing_id: id }).done(function (res) {
          if (res && res.success) {
            location.reload();
            return;
          }
          setModalError($m, (res && res.data && res.data.message) || t('error', 'Error'));
          $btn.prop('disabled', false);
        }).fail(function () {
          setModalError($m, t('error', 'Error'));
          $btn.prop('disabled', false);
        });
      }}
    ]);
  });

  $(document).on('click', '.sutore-mp-ful-ship', function () {
    var id = $(this).data('listing-id');
    api('marketplace_fulfillment_details', { listing_id: id }).done(function (res) {
      var hint = (res && res.success && res.data && res.data.shipment_hint) ? res.data.shipment_hint : t('shipmentHint', '');
      var body = '<p>' + hint + '</p>';
      body += '<p><label>' + t('shipmentCode', 'Shipping Tracking No') + '<br/><input type="text" class="sutore-mp-ful-shipment-input" inputmode="numeric" maxlength="12" style="width:100%"/></label></p>';
      var $m = modal(t('ship', 'Ship'), body, [
        { label: t('cancel', 'Cancel'), cls: 'wp-element-button is-style-outline sutore-mp-ful-close', onClick: function () { $('.sutore-mp-ful-modal').remove(); } },
        { label: t('submit', 'Submit'), cls: 'wp-element-button sutore-mp-ful-ship-ok', onClick: function () {
          var $btn = $m.find('.sutore-mp-ful-ship-ok');
          if ($btn.prop('disabled')) {
            return;
          }
          var code = String($m.find('.sutore-mp-ful-shipment-input').val() || '').trim();
          setModalError($m, '');
          if (!code) {
            setModalError($m, t('shipmentCodeRequired', 'Enter a valid shipping tracking number.'));
            return;
          }
          $btn.prop('disabled', true);
          api('marketplace_fulfillment_ship', { listing_id: id, shipment_code: code }).done(function (r) {
            if (r && r.success) {
              location.reload();
              return;
            }
            setModalError($m, (r && r.data && r.data.message) || (r && r.message) || t('error', 'Error'));
            $btn.prop('disabled', false);
          }).fail(function (xhr) {
            var msg = t('error', 'Error');
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
              msg = xhr.responseJSON.message;
            }
            setModalError($m, msg);
            $btn.prop('disabled', false);
          });
        }}
      ]);
    });
  });
})(jQuery);
