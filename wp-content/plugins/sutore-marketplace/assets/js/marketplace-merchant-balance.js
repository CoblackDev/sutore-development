(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplace || {};

  function t(key, fallback) {
    return cfg.t ? cfg.t(key, fallback) : fallback;
  }

  function esc(str) {
    return $('<div/>').text(str == null ? '' : String(str)).html();
  }

  function loadingHtml() {
    return (
      '<div class="sutore-mp-list-loading" role="status" aria-live="polite">' +
      '<span class="sutore-mp-list-spinner" aria-hidden="true"></span>' +
      '<span class="screen-reader-text">' +
      esc(t('loading', 'Loading…')) +
      '</span></div>'
    );
  }

  function errorHtml(message) {
    return '<p class="sutore-mp-error">' + esc(message || t('error', 'Error')) + '</p>';
  }

  function renderCommission(profileData) {
    var html =
      '<div class="sutore-mp-merchant-balance__stat"><span class="sutore-mp-merchant-balance__stat-label">' +
      esc(t('commission', 'Commission')) +
      '</span><strong>' +
      esc(String(profileData.commission_percent || 0)) +
      '%</strong>';

    if (profileData.commission_overridden) {
      var overrideNote = esc(
        profileData.commission_override_label || t('commissionDiscountActive', 'Commission discount active')
      );
      var levelPct = String(profileData.commission_level_percent || 0);
      var effectivePct = String(profileData.commission_percent || 0);
      overrideNote +=
        ' — ' +
        esc(
          t('commissionLevelToEffective', 'Level %1$s%% → Effective %2$s%%')
            .replace('%1$s', levelPct)
            .replace('%2$s', effectivePct)
        );
      if (profileData.commission_override_expires_at) {
        overrideNote +=
          '<br />' +
          esc(t('expiresAt', 'Expires')) +
          ': ' +
          esc(String(profileData.commission_override_expires_at));
      } else {
        overrideNote += '<br />' + esc(t('noExpiry', 'No end date'));
      }
      html += '<small class="sutore-mp-merchant-balance__commission-note">' + overrideNote + '</small>';
    }

    html += '</div>';
    return html;
  }

  function payoutStatusText(line) {
    var statusText = line.scheduled_message || '';
    if (!statusText && line.payout_status === 'paid') {
      statusText = line.paid_at_display
        ? (line.payout_status_label || t('paidPayout', 'Paid payout')) + ' · ' + line.paid_at_display
        : line.payout_status_label || t('paidPayout', 'Paid payout');
      if (line.payment_ref) {
        statusText += ' · ' + line.payment_ref;
      }
    }
    if (!statusText) {
      statusText = line.payout_status_label || line.payout_status || '';
    }
    return statusText;
  }

  function renderPayouts(balance) {
    var html =
      '<div class="sutore-mp-merchant-balance__stat"><span class="sutore-mp-merchant-balance__stat-label">' +
      esc(t('paidPayout', 'Paid payout')) +
      '</span><strong>' +
      esc((balance && balance.formatted_paid) || '0 TL') +
      '</strong><small>' +
      esc(t('salesCount', '%d sales').replace('%d', String((balance && balance.paid_count) || 0))) +
      '</small></div>' +
      '<div class="sutore-mp-merchant-balance__stat"><span class="sutore-mp-merchant-balance__stat-label">' +
      esc(t('pendingPayout', 'Pending payout')) +
      '</span><strong>' +
      esc((balance && balance.formatted_pending) || '0 TL') +
      '</strong><small>' +
      esc(t('salesCount', '%d sales').replace('%d', String((balance && balance.pending_count) || 0))) +
      '</small></div>';

    return html;
  }

  function renderRecent(balance) {
    var recent = (balance && balance.recent) || [];
    var html =
      '<div class="sutore-mp-merchant-balance__payouts"><h3>' +
      esc(t('recentPayouts', 'Recent payouts')) +
      '</h3>';

    if (!recent.length) {
      html +=
        '<p class="sutore-mp-merchant-balance__empty">' +
        esc(t('noPayouts', 'No payout lines yet.')) +
        '</p></div>';
      return html;
    }

    html +=
      '<table class="shop_table shop_table_responsive"><thead><tr>' +
      '<th>' +
      esc(t('product', 'Product')) +
      '</th>' +
      '<th>' +
      esc(t('listing', 'Product')) +
      '</th>' +
      '<th>' +
      esc(t('net', 'Net')) +
      '</th>' +
      '<th>' +
      esc(t('payment', 'Payment')) +
      '</th>' +
      '</tr></thead><tbody>';

    recent.forEach(function (line) {
      html +=
        '<tr><td>' +
        esc(line.product_title || '') +
        '</td><td>#' +
        esc(String(line.variation_id || 0)) +
        '</td><td>' +
        esc(line.formatted_net || '') +
        '</td><td>' +
        esc(payoutStatusText(line)) +
        '</td></tr>';
    });

    html += '</tbody></table></div>';
    return html;
  }

  function renderPage(profileData, balance) {
    return (
      '<div class="sutore-mp-merchant-balance__stats">' +
      renderCommission(profileData) +
      renderPayouts(balance) +
      '</div>' +
      renderRecent(balance)
    );
  }

  function bootBalance($root) {
    if (!$root.length || !cfg.api) {
      return;
    }

    $root.attr('aria-busy', 'true').html(loadingHtml());

    var profileReq = cfg.api('marketplace_merchant_profile_get');
    var balanceReq = cfg.api('marketplace_merchant_balance_get');

    profileReq.done(function (res) {
      if (!res || !res.success || !res.data) {
        $root.attr('aria-busy', 'false').html(errorHtml(res && (res.message || (res.data && res.data.message))));
        return;
      }

      balanceReq.done(function (balRes) {
        var balance = balRes && balRes.success ? balRes.data : null;
        $root.attr('aria-busy', 'false').html(renderPage(res.data, balance));
      });
    }).fail(function (xhr) {
      var msg = t('error', 'Error');
      if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
        msg = xhr.responseJSON.message;
      }
      $root.attr('aria-busy', 'false').html(errorHtml(msg));
    });
  }

  $(function () {
    bootBalance($('.sutore-mp-merchant-balance__root[data-rest-boot="1"]'));
  });
}(jQuery));
