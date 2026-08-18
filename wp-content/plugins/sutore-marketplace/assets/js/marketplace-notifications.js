(function ($) {
  'use strict';

  var t = SutoreMarketplace.t;
  var api = SutoreMarketplace.api;

  var categoryLabels = {
    sales: t('notifCategorySales', 'Sale'),
    fulfillment: t('notifCategoryFulfillment', 'Shipping'),
    payout: t('notifCategoryPayout', 'Payout'),
    listing: t('notifCategoryListing', 'Product'),
    customer: t('notifCategoryCustomer', 'Offers'),
    system: t('notifCategorySystem', 'System')
  };

  function formatDate(value) {
    if (!value) return '';
    var d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d.getTime())) return value;
    return d.toLocaleString('tr-TR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  }

  function renderItem(item) {
    var $item = $('<a class="sutore-mp-notification"/>')
      .attr('href', item.action_url || '#')
      .attr('data-id', item.id);

    if (!item.is_read) {
      $item.addClass('is-unread');
    }

    $item.append($('<div class="sutore-mp-notification__title"/>').text(item.title || ''));

    if (item.body) {
      $item.append($('<div class="sutore-mp-notification__body"/>').text(item.body));
    }

    var $meta = $('<div class="sutore-mp-notification__meta"/>');
    if (item.category && categoryLabels[item.category]) {
      $meta.append($('<span/>').text(categoryLabels[item.category]));
    }
    $meta.append($('<span/>').text(formatDate(item.created_at)));
    if (!item.is_read) {
      $meta.append($('<span class="sutore-mp-notification__badge"/>').text(t('notifUnread', 'New')));
    }
    $item.append($meta);

    return $item;
  }

  function updateMenuBadge(unread) {
    var $link = $('.woocommerce-MyAccount-navigation-link--notifications a');
    if (!$link.length) return;
    var base = t('notifMenuLabel', 'Notifications');
    $link.text(unread > 0 ? base + ' (' + unread + ')' : base);
  }

  function loadPage($root, page) {
    var $list = $root.find('.sutore-mp-notifications__list');
    var $pager = $root.find('.sutore-mp-notifications__pager');
    var $markAll = $root.find('.sutore-mp-notifications__mark-all');
    var perPage = 20;

    $list.attr('aria-busy', 'true').empty().append(
      $('<div class="sutore-mp-list-loading" role="status"/>').append(
        $('<span class="sutore-mp-list-spinner" aria-hidden="true"/>'),
        $('<span class="screen-reader-text"/>').text(t('loading', 'Loading…'))
      )
    );

    api('marketplace_notifications_list', { page: page, per_page: perPage }).done(function (res) {
      $list.empty().attr('aria-busy', 'false');
      if (!res.success) {
        $list.append(
          $('<p class="sutore-mp-notifications__empty"/>').text((res.data && res.data.message) || t('error', 'Error'))
        );
        return;
      }

      var items = res.data.items || [];
      var total = res.data.total || 0;
      var unread = res.data.unread || 0;

      $list.attr('data-unread', unread);
      updateMenuBadge(unread);
      if (unread > 0) {
        $markAll.prop('hidden', false);
      } else {
        $markAll.prop('hidden', true);
      }

      if (!items.length) {
        $list.append(
          $('<p class="sutore-mp-notifications__empty"/>').text(t('notifEmpty', 'You have no notifications yet.'))
        );
        $pager.prop('hidden', true).empty();
        return;
      }

      items.forEach(function (item) {
        $list.append(renderItem(item));
      });

      var pages = Math.max(1, Math.ceil(total / perPage));
      if (pages <= 1) {
        $pager.prop('hidden', true).empty();
        return;
      }

      $pager.prop('hidden', false).empty();
      var $prev = $('<button type="button" class="wp-element-button is-style-outline"/>')
        .text(t('notifPrev', 'Previous'))
        .prop('disabled', page <= 1);
      var $next = $('<button type="button" class="wp-element-button is-style-outline"/>')
        .text(t('notifNext', 'Next'))
        .prop('disabled', page >= pages);
      var $info = $('<span/>').text(page + ' / ' + pages);

      $prev.on('click', function () {
        if (page > 1) loadPage($root, page - 1);
      });
      $next.on('click', function () {
        if (page < pages) loadPage($root, page + 1);
      });

      $pager.append($prev, $info, $next);
    });
  }

  function markRead(id, $root) {
    api('marketplace_notifications_mark_read', { notification_id: id }).done(function (res) {
      if (!res.success) return;
      var unread = (res.data && res.data.unread) || 0;
      $root.find('.sutore-mp-notifications__list').attr('data-unread', unread);
      updateMenuBadge(unread);
      if (unread <= 0) {
        $root.find('.sutore-mp-notifications__mark-all').prop('hidden', true);
      }
    });
  }

  $(function () {
    var $root = $('.sutore-mp-notifications');
    if (!$root.length) return;

    loadPage($root, 1);

    $root.on('click', '.sutore-mp-notification.is-unread', function () {
      var id = parseInt($(this).attr('data-id'), 10);
      if (!id) return;
      $(this).removeClass('is-unread').find('.sutore-mp-notification__badge').remove();
      markRead(id, $root);
    });

    $root.on('click', '.sutore-mp-notifications__mark-all', function () {
      var $btn = $(this).prop('disabled', true);
      api('marketplace_notifications_mark_all_read').done(function (res) {
        if (res.success) {
          $btn.prop('hidden', true);
          $root.find('.sutore-mp-notification').removeClass('is-unread');
          $root.find('.sutore-mp-notification__badge').remove();
          $root.find('.sutore-mp-notifications__list').attr('data-unread', 0);
          updateMenuBadge(0);
        }
      }).always(function () {
        $btn.prop('disabled', false);
      });
    });
  });
})(jQuery);
