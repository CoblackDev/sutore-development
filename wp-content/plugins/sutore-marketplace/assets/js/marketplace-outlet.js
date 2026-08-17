(function ($) {
  'use strict';

  var t = SutoreMarketplace.t;
  var api = SutoreMarketplace.api;
  var showConfirm = SutoreMarketplace.showConfirm;
  var thumbBox = SutoreMarketplace.thumbBox;

  function $page() {
    return $('.sutore-mp-outlet');
  }

  function renderCard(row) {
    var $card = $('<div class="sutore-mp-card sutore-mp-outlet-card"/>').attr('data-id', String(row.id));
    if (row.optin_status === 'pending') {
      $card.addClass('is-campaign-offer');
    } else if (row.optin_status === 'live') {
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

    $info.append($('<div class="sutore-mp-card-meta"/>').text(
      (row.size_label ? t('size', 'Size') + ': ' + row.size_label : '') +
      (row.window_name ? ' · ' + row.window_name : '')
    ));

    var $prices = $('<div class="sutore-mp-card-meta"/>');
    $prices.append($('<div/>').text(t('outletCustomerSale', 'Customer sale') + ': ' + (row.customer_sale_display || '')));
    $prices.append($('<div/>').text(t('outletSellerAsking', 'Your asking') + ': ' + (row.seller_net_display || '')));
    $info.append($prices);

    $info.append($('<div class="sutore-mp-card-meta"/>').text(
      t('outletWindow', 'Window') + ': ' + (row.starts_at_label || '') + ' — ' + (row.ends_at_label || '')
    ));

    if (row.optin_status_label) {
      $info.append($('<span class="sutore-mp-badge"/>').text(row.optin_status_label));
    } else if (row.window_status_label) {
      $info.append($('<span class="sutore-mp-badge"/>').text(row.window_status_label));
    }

    $main.append($info);
    $card.append($main);

    var $actions = $('<div class="sutore-mp-card-actions"/>');
    if (row.can_opt_in) {
      $actions.append(
        $('<button type="button" class="wp-element-button sutore-mp-outlet-join"/>')
          .attr('data-id', String(row.id))
          .text(t('outletJoin', 'Join at this asking'))
      );
    }
    if (row.can_cancel) {
      $actions.append(
        $('<button type="button" class="wp-element-button is-style-outline sutore-mp-outlet-cancel"/>')
          .attr('data-optin-id', String(row.optin_id || ''))
          .text(t('outletCancel', 'Cancel'))
      );
    }
    if ($actions.children().length) {
      $card.append($actions);
    }

    return $card;
  }

  function renderEmpty() {
    return $('<p class="sutore-mp-empty"/>').text(t('outletEmpty', 'There is no open outlet window right now.'));
  }

  function load() {
    var $root = $page().find('.sutore-mp-outlet-results');
    $root.attr('aria-busy', 'true');
    api('marketplace_outlet_query', {}).done(function (res) {
      $root.attr('aria-busy', 'false').empty();
      $page().find('.sutore-mp-list-chrome').prop('hidden', false);
      if (!res || !res.success) {
        var msg = (res && res.data && res.data.message) || t('error', 'Error');
        $root.append($('<p class="sutore-mp-error"/>').text(msg));
        return;
      }
      var items = (res.data && res.data.items) || [];
      if (!items.length) {
        $root.append(renderEmpty());
        return;
      }
      items.forEach(function (row) {
        $root.append(renderCard(row));
      });
    });
  }

  $(document).on('click', '.sutore-mp-outlet-join', function () {
    var id = parseInt($(this).attr('data-id') || '0', 10);
    if (!id) {
      return;
    }
    var run = function () {
      api('marketplace_outlet_opt_in', { item_id: id }).done(function (res) {
        if (!res || !res.success) {
          window.alert((res && res.data && res.data.message) || t('error', 'Error'));
          return;
        }
        load();
      });
    };
    if (typeof showConfirm === 'function') {
      showConfirm(
        t('outletJoin', 'Join at this asking'),
        t('outletJoinConfirm', 'Join this outlet item at the listed asking?'),
        t('outletJoin', 'Join at this asking'),
        run
      );
      return;
    }
    if (window.confirm(t('outletJoinConfirm', 'Join this outlet item at the listed asking?'))) {
      run();
    }
  });

  $(document).on('click', '.sutore-mp-outlet-cancel', function () {
    var id = parseInt($(this).attr('data-optin-id') || '0', 10);
    if (!id) {
      return;
    }
    var run = function () {
      api('marketplace_outlet_cancel', { optin_id: id }).done(function (res) {
        if (!res || !res.success) {
          window.alert((res && res.data && res.data.message) || t('error', 'Error'));
          return;
        }
        load();
      });
    };
    if (typeof showConfirm === 'function') {
      showConfirm(
        t('outletCancel', 'Cancel'),
        t('outletCancelConfirm', 'Cancel this outlet opt-in?'),
        t('outletCancel', 'Cancel'),
        run
      );
      return;
    }
    if (window.confirm(t('outletCancelConfirm', 'Cancel this outlet opt-in?'))) {
      run();
    }
  });

  $(function () {
    if ($page().length) {
      load();
    }
  });
})(jQuery);
