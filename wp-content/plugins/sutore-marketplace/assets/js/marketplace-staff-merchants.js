(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplaceStaffMerchants || {};
  var i18n = cfg.i18n || {};

  function t(key, def) {
    return i18n[key] || def;
  }

  function esc(str) {
    return $('<div/>').text(str == null ? '' : String(str)).html();
  }

  function dash(val) {
    var s = val == null ? '' : String(val).trim();
    return s !== '' ? s : '—';
  }

  function loadingHtml() {
    return (
      '<p class="sutore-mp-staff-loading" role="status" aria-live="polite">' +
      '<span class="sutore-mp-staff-spinner" aria-hidden="true"></span>' +
      '<span class="screen-reader-text">' +
      esc(t('loading', 'Loading…')) +
      '</span></p>'
    );
  }

  function alertError(message) {
    var text = String(message || t('error', 'Error'));
    if (window.SutoreMarketplace && SutoreMarketplace.showAlert) {
      SutoreMarketplace.showAlert(t('error', 'Error'), text, t('ok', 'OK'));
      return;
    }
    window.alert(text);
  }

  function confirmAction(title, text, onConfirm) {
    if (window.SutoreMarketplace && SutoreMarketplace.showConfirm) {
      SutoreMarketplace.showConfirm(title, text, t('confirm', 'Confirm'), onConfirm);
      return;
    }
    if (window.confirm(text)) {
      onConfirm();
    }
  }

  function ajax(method, path, data) {
    var opts = {
      url: (cfg.restUrl || '') + path,
      method: method,
      dataType: 'json',
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    };
    if (method === 'GET') {
      opts.data = data || {};
    } else {
      opts.contentType = 'application/json';
      opts.data = JSON.stringify(data || {});
    }
    return $.ajax(opts);
  }

  function detailUrl(baseUrl, merchantId) {
    try {
      var u = new URL(baseUrl, window.location.origin);
      u.searchParams.set('merchant_id', String(merchantId));
      return u.pathname + u.search;
    } catch (err) {
      return baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + 'merchant_id=' + merchantId;
    }
  }

  function syncListUrl(baseUrl, state) {
    try {
      var u = new URL(baseUrl, window.location.origin);
      var keys = ['search', 'level', 'tc_verified', 'has_restriction', 'balance', 'sales', 'orderby'];
      keys.forEach(function (key) {
        var val = state[key] || '';
        if (key === 'orderby' && (!val || val === 'id_desc')) {
          u.searchParams.delete(key);
          return;
        }
        if (val) {
          u.searchParams.set(key, val);
        } else {
          u.searchParams.delete(key);
        }
      });
      u.searchParams.delete('status');
      if (state.page > 1) {
        u.searchParams.set('paged', String(state.page));
      } else {
        u.searchParams.delete('paged');
      }
      u.searchParams.delete('merchant_id');
      window.history.replaceState({}, '', u.pathname + u.search);
    } catch (err) {
      /* ignore */
    }
  }

  function readListState($root, overrides) {
    overrides = overrides || {};
    function pick(key, dataKey, fallback) {
      if (Object.prototype.hasOwnProperty.call(overrides, key) && overrides[key] != null) {
        return String(overrides[key]);
      }
      var fromData = $root.data(dataKey);
      if (fromData != null && String(fromData) !== '') {
        return String(fromData);
      }
      return fallback != null ? String(fallback) : '';
    }
    return {
      baseUrl: pick('baseUrl', 'baseUrl', ''),
      search: pick('search', 'search', ''),
      level: pick('level', 'level', ''),
      tc_verified: pick('tc_verified', 'tcVerified', ''),
      has_restriction: pick('has_restriction', 'hasRestriction', ''),
      balance: pick('balance', 'balance', ''),
      sales: pick('sales', 'sales', ''),
      orderby: pick('orderby', 'orderby', 'id_desc') || 'id_desc',
      page: Object.prototype.hasOwnProperty.call(overrides, 'page')
        ? parseInt(overrides.page, 10) || 1
        : parseInt($root.data('page'), 10) || 1,
      perPage: parseInt($root.data('perPage'), 10) || 30
    };
  }

  function $pageShell($from) {
    return $from.closest('.sutore-mp-staff-merchants');
  }

  function syncFilterFields($shell, state) {
    var $filter = $shell.find('.sutore-mp-staff-merchants-filter');
    $shell.find('.sutore-mp-staff-merchants-search').val(state.search || '');
    $filter.find('[name="level"]').val(state.level || '');
    $filter.find('[name="tc_verified"]').val(state.tc_verified || '');
    $filter.find('[name="has_restriction"]').val(state.has_restriction || '');
    $filter.find('[name="balance"]').val(state.balance || '');
    $filter.find('[name="sales"]').val(state.sales || '');
    $shell.find('.sutore-mp-staff-merchants-sort [name="orderby"]').val(state.orderby || 'id_desc');
  }

  function activeFilterCount(state) {
    var n = 0;
    if (state.level) n++;
    if (state.tc_verified !== '') n++;
    if (state.has_restriction !== '') n++;
    if (state.balance) n++;
    if (state.sales) n++;
    return n;
  }

  function updateListBadges($shell, state) {
    if (window.SutoreMarketplace) {
      SutoreMarketplace.setFilterBadge($shell, activeFilterCount(state));
      SutoreMarketplace.setSortBadge($shell, (state.orderby || 'id_desc') !== 'id_desc');
    }
  }

  function renderList(data, state) {
    var items = data.items || [];
    var page = parseInt(data.page, 10) || state.page || 1;
    var perPage = parseInt(data.per_page, 10) || state.perPage || 30;
    var total = parseInt(data.total, 10) || 0;
    var totalPages = Math.max(1, Math.ceil(total / perPage));

    var html =
      '<div class="sutore-mp-staff-table-wrap"><table class="sutore-mp-staff-table"><thead><tr>' +
      '<th>' +
      esc(t('userId', 'User ID')) +
      '</th><th>' +
      esc(t('seller', 'Seller')) +
      '</th><th>' +
      esc(t('email', 'Email')) +
      '</th><th>' +
      esc(t('phone', 'Phone')) +
      '</th><th>' +
      esc(t('level', 'Level')) +
      '</th><th>' +
      esc(t('tc', 'TC identity')) +
      '</th><th>' +
      esc(t('restrictionStatus', 'Restrictions')) +
      '</th><th>' +
      esc(t('listings', 'Listings')) +
      '</th><th>' +
      esc(t('sold', 'Sold')) +
      '</th><th>' +
      esc(t('pendingBalance', 'Pending balance')) +
      '</th><th>' +
      esc(t('paidTotal', 'Paid total')) +
      '</th><th></th></tr></thead><tbody>';

    if (!items.length) {
      html +=
        '<tr><td colspan="12">' +
        esc(t('noRecords', 'No sellers found.')) +
        '</td></tr>';
    } else {
      items.forEach(function (item) {
        var tcHtml = esc(
          item.tc_verified_label ||
            (item.tc_verified ? t('tcVerified', 'TC verified') : t('tcNotVerified', 'TC not verified'))
        );
        var restrictionHtml = esc(
          item.has_active_restriction
            ? t('restricted', 'Restricted')
            : t('noActiveRestriction', 'None')
        );
        html +=
          '<tr><td>' +
          esc('#' + String(item.id)) +
          '</td><td><strong>' +
          esc(item.display_name || '') +
          '</strong></td><td>' +
          esc(dash(item.email)) +
          '</td><td>' +
          esc(dash(item.phone)) +
          '</td><td>' +
          esc(item.level_label || item.level || '') +
          '</td><td>' +
          tcHtml +
          '</td><td>' +
          restrictionHtml +
          '</td><td>' +
          esc(String(item.listing_count || 0)) +
          '</td><td>' +
          esc(String(item.sold_count || 0)) +
          '</td><td>' +
          esc(item.formatted_pending || '—') +
          '</td><td>' +
          esc(item.formatted_paid || '—') +
          '</td><td><button type="button" class="wp-element-button is-style-outline sutore-mp-staff-open-merchant" data-merchant-id="' +
          esc(String(item.id)) +
          '">' +
          esc(t('detail', 'Detail')) +
          '</button></td></tr>';
      });
    }

    html += '</tbody></table></div>';

    if (totalPages > 1) {
      html +=
        '<nav class="sutore-mp-staff-pager" aria-label="' +
        esc(t('pagination', 'Pagination')) +
        '">';
      if (page > 1) {
        html +=
          '<a href="#" data-page="' +
          (page - 1) +
          '">' +
          esc(t('previous', 'Previous')) +
          '</a>';
      }
      html +=
        '<span>' +
        esc(
          t('pageOf', 'Page %1$d / %2$d')
            .replace('%1$d', String(page))
            .replace('%2$d', String(totalPages))
        ) +
        '</span>';
      if (page < totalPages) {
        html +=
          '<a href="#" data-page="' +
          (page + 1) +
          '">' +
          esc(t('next', 'Next')) +
          '</a>';
      }
      html += '</nav>';
    }

    return renderPlatformCampaigns(data.platform_overrides) + html;
  }

  function adjustmentOptions(selected) {
    selected = selected || 'absolute';
    var opts = [
      ['absolute', t('adjustmentAbsolute', 'Absolute rate')],
      ['percent_off', t('adjustmentPercentOff', 'Percent off current rate')],
      ['points_off', t('adjustmentPointsOff', 'Points off current rate')]
    ];
    return opts
      .map(function (pair) {
        return (
          '<option value="' +
          esc(pair[0]) +
          '"' +
          (pair[0] === selected ? ' selected' : '') +
          '>' +
          esc(pair[1]) +
          '</option>'
        );
      })
      .join('');
  }

  function overrideWindowLabel(o) {
    var parts = [];
    if (o.scheduled) {
      parts.push(t('scheduled', 'Scheduled'));
    }
    if (o.starts_at) {
      parts.push(t('startsAt', 'Starts at') + ' ' + o.starts_at);
    }
    parts.push(o.expires_at ? o.expires_at : t('noExpiry', 'No end date'));
    return parts.join(' · ');
  }

  function renderOverrideTable(overrides, emptyText) {
    if (!overrides.length) {
      return '<p class="sutore-mp-empty">' + esc(emptyText) + '</p>';
    }
    var html =
      '<div class="sutore-mp-staff-table-wrap"><table class="sutore-mp-staff-table"><thead><tr>' +
      '<th>' +
      esc(t('commission', 'Commission')) +
      '</th><th>' +
      esc(t('adjustment', 'Rate type')) +
      '</th><th>' +
      esc(t('source', 'Source')) +
      '</th><th>' +
      esc(t('expiresAt', 'Expires at')) +
      '</th><th>' +
      esc(t('note', 'Note')) +
      '</th><th>' +
      esc(t('actions', 'Actions')) +
      '</th></tr></thead><tbody>';
    overrides.forEach(function (o) {
      var valueLabel = '%' + String(o.commission_percent);
      if (o.effective_percent != null && o.adjustment && o.adjustment !== 'absolute') {
        valueLabel += ' → %' + String(o.effective_percent);
      }
      if (o.raises_level) {
        valueLabel += ' ↑';
      }
      if (o.is_platform) {
        valueLabel += ' · ' + t('allSellers', 'All sellers');
      }
      html +=
        '<tr><td>' +
        esc(valueLabel) +
        '</td><td>' +
        esc(o.adjustment_label || o.adjustment || '—') +
        '</td><td>' +
        esc(o.source_label || o.source || '—') +
        '</td><td>' +
        esc(overrideWindowLabel(o)) +
        '</td><td>' +
        esc(dash(o.note)) +
        '</td><td>' +
        '<button type="button" class="wp-element-button is-style-outline sutore-mp-staff-delete-commission" data-id="' +
        esc(String(o.id)) +
        '">' +
        esc(t('deleteOverride', 'Delete')) +
        '</button></td></tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  function renderCommissionFields(selectedAdjustment) {
    return (
      '<div class="sutore-mp-staff-form-grid">' +
      '<label>' +
      esc(t('adjustment', 'Rate type')) +
      '<select name="adjustment" class="sutore-mp-input">' +
      adjustmentOptions(selectedAdjustment || 'absolute') +
      '</select></label>' +
      '<label>' +
      esc(t('commissionValue', 'Value')) +
      '<input type="number" name="commission_percent" class="sutore-mp-input" min="0" max="100" step="0.01" required /></label>' +
      '<label>' +
      esc(t('note', 'Note')) +
      '<input type="text" name="note" class="sutore-mp-input" /></label>' +
      '<label>' +
      esc(t('startsAt', 'Starts at')) +
      '<input type="datetime-local" name="starts_at" class="sutore-mp-input" /></label>' +
      '<label>' +
      esc(t('expiresAt', 'Expires at')) +
      '<input type="datetime-local" name="expires_at" class="sutore-mp-input" /></label></div>' +
      '<p class="description sutore-mp-staff-form-hint">' +
      esc(t('startsAtHelp', 'Optional. Leave empty to start immediately.')) +
      ' ' +
      esc(t('expiresAtHelp', 'Optional. Leave empty for no end date.')) +
      '</p>' +
      '<p class="sutore-mp-staff-commission-raise-warn sutore-mp-staff-meta-warn" hidden>' +
      esc(
        t(
          'raisesLevelWarn',
          'This rate is higher than the seller level and will increase commission.'
        )
      ) +
      '</p>'
    );
  }

  function renderPlatformCampaigns(overrides) {
    overrides = overrides || [];
    return (
      '<section class="sutore-mp-staff-platform-commission">' +
      '<h3 class="sutore-mp-staff-panel-title">' +
      esc(t('platformCampaigns', 'Platform commission campaigns')) +
      '</h3>' +
      '<p class="description">' +
      esc(
        t(
          'platformCampaignsHelp',
          'One record applies to every seller. Relative discounts follow each seller’s level rate.'
        )
      ) +
      '</p>' +
      renderOverrideTable(overrides, t('noPlatformCampaigns', 'No platform commission campaigns.')) +
      '<h3 class="sutore-mp-staff-panel-title sutore-mp-staff-subheading">' +
      esc(t('addPlatformCampaign', 'Set platform commission campaign')) +
      '</h3>' +
      '<form class="sutore-mp-staff-platform-commission-form sutore-mp-staff-form">' +
      renderCommissionFields('percent_off') +
      '<div class="sutore-mp-staff-actions">' +
      '<button type="submit" class="wp-element-button is-style-outline">' +
      esc(t('save', 'Save')) +
      '</button></div></form></section>'
    );
  }

  function manageProductUrl(variationId) {
    variationId = parseInt(variationId, 10) || 0;
    var base = String(cfg.manageProductsUrl || '').trim();
    if (!variationId || !base) {
      return '';
    }
    try {
      var u = new URL(base, window.location.origin);
      u.searchParams.set('variation_id', String(variationId));
      return u.pathname + u.search + u.hash;
    } catch (err) {
      return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'variation_id=' + variationId;
    }
  }

  function payoutProductCell(p) {
    var title = String(p.product_title || '').trim();
    var variationId = parseInt(p.variation_id, 10) || 0;
    var sub =
      variationId > 0
        ? '<div class="sutore-mp-staff-sub">#' + esc(String(variationId)) + '</div>'
        : '';
    if (!variationId || title === '') {
      return esc(title || '—') + sub;
    }
    var href = manageProductUrl(variationId);
    return (
      '<a class="sutore-mp-staff-merchant-product-link sutore-mp-staff-open-manage" href="' +
      esc(href || '#') +
      '" data-variation-id="' +
      esc(String(variationId)) +
      '" data-product-title="' +
      esc(title) +
      '" title="' +
      esc(t('openListingDetail', 'Open listing detail')) +
      '">' +
      esc(title) +
      '</a>' +
      sub
    );
  }


  function kvRow(label, valueHtml) {
    return (
      '<div class="sutore-mp-manage-kv__row"><dt>' +
      esc(label) +
      '</dt><dd>' +
      valueHtml +
      '</dd></div>'
    );
  }

  function profileFieldRow(name, label, type, attrs, className) {
    attrs = attrs || {};
    var id = 'staff-' + name;
    var html =
      '<p class="form-row ' +
      esc(className || 'form-row-wide') +
      '">' +
      '<label for="' +
      esc(id) +
      '">' +
      esc(label) +
      (attrs.required ? '&nbsp;<abbr class="required" title="required">*</abbr>' : '') +
      '</label>';
    if (type === 'select') {
      html +=
        '<select name="' +
        esc(name) +
        '" id="' +
        esc(id) +
        '" class="select sutore-mp-input"';
      if (attrs.required) {
        html += ' required';
      }
      html += '>';
      if (attrs.optionsHtml) {
        html += attrs.optionsHtml;
      }
      html += '</select>';
    } else {
      html +=
        '<input type="' +
        esc(type || 'text') +
        '" class="input-text sutore-mp-input" name="' +
        esc(name) +
        '" id="' +
        esc(id) +
        '"';
      if (attrs.value != null && attrs.value !== '') {
        html += ' value="' + esc(String(attrs.value)) + '"';
      }
      Object.keys(attrs).forEach(function (key) {
        if (key === 'required' && attrs[key]) {
          html += ' required';
          return;
        }
        if (key === 'readonly' && attrs[key]) {
          html += ' readonly';
          return;
        }
        if (key === 'value' || key === 'optionsHtml') {
          return;
        }
        html += ' ' + esc(key) + '="' + esc(String(attrs[key])) + '"';
      });
      html += ' />';
    }
    html += '</p>';
    return html;
  }

  function cityOptions(cities, selected) {
    var html = '<option value="">' + esc(t('pickDistrict', 'Select')) + '</option>';
    (cities || []).forEach(function (c) {
      html +=
        '<option value="' +
        esc(c.code) +
        '"' +
        (String(c.code) === String(selected) ? ' selected' : '') +
        '>' +
        esc(c.label) +
        '</option>';
    });
    return html;
  }

  function loadDistrictOptions($form, city, selected) {
    var $state = $form.find('[name="account_state"]');
    if (!$state.length) {
      return;
    }
    $state.empty().append($('<option>').val('').text(t('pickDistrict', 'Select')));
    if (!city) {
      return;
    }
    ajax('GET', 'merchant/districts', { city: city }).done(function (res) {
      var districts =
        res && res.success && res.data && res.data.districts ? res.data.districts : [];
      districts.forEach(function (district) {
        var $opt = $('<option>').val(district).text(district);
        if (selected && selected === district) {
          $opt.prop('selected', true);
        }
        $state.append($opt);
      });
    });
  }

  function bindProfileLocation($form, profile) {
    if (!$form || !$form.length) {
      return;
    }
    var city = String((profile && profile.account_city) || $form.find('[name="account_city"]').val() || '');
    var state = String((profile && profile.account_state) || '');
    if (city) {
      loadDistrictOptions($form, city, state);
    }
  }

  function restrictionLabels() {
    return {
      listing_create_ban: t('listingCreateBan', 'Ban creating listings'),
      price_update_ban: t('priceUpdateBan', 'Ban price updates'),
      disabled_account: t('disabledAccount', 'Disable account')
    };
  }

  function renderActivity(events) {
    if (!events || !events.length) {
      return '<p class="sutore-mp-empty">' + esc(t('noActivity', 'No activity recorded yet.')) + '</p>';
    }

    var rows = events
      .map(function (event) {
        return (
          '<tr>' +
          '<td>' +
          esc(event.date || '') +
          '</td>' +
          '<td><strong>' +
          esc(event.event_label || '') +
          '</strong></td>' +
          '<td>' +
          esc(event.actor || '—') +
          '</td>' +
          '<td><code class="sutore-mp-staff-event-summary">' +
          esc(event.summary || '—') +
          '</code></td>' +
          '</tr>'
        );
      })
      .join('');

    return (
      '<div class="sutore-mp-staff-table-wrap"><table class="sutore-mp-staff-table sutore-mp-staff-activity-table">' +
      '<thead><tr>' +
      '<th>' +
      esc(t('date', 'Date')) +
      '</th><th>' +
      esc(t('event', 'Event')) +
      '</th><th>' +
      esc(t('actor', 'Actor')) +
      '</th><th>' +
      esc(t('details', 'Details')) +
      '</th></tr></thead><tbody>' +
      rows +
      '</tbody></table></div>'
    );
  }

  function renderDetail(data) {
    var profile = data.profile || {};
    var balance = data.balance || {};
    var title = data.display_name || t('seller', 'Seller');
    var keyLabels = restrictionLabels();
    var restrictions = data.restrictions || [];
    var activeRestrictions = restrictions.filter(function (r) {
      return !!r.is_active;
    });
    var restrictionSummary =
      activeRestrictions.length > 0
        ? activeRestrictions
            .map(function (r) {
              return keyLabels[r.restriction_key] || r.restriction_key;
            })
            .join(', ')
        : t('noActiveRestriction', 'None');

    var commission = data.commission || {};
    var commissionLabel =
      '%' +
      String(
        commission.effective_percent != null
          ? commission.effective_percent
          : data.commission_percent || 0
      );
    if (commission.is_overridden) {
      commissionLabel +=
        ' (' +
        t('levelCommission', 'Level') +
        ' %' +
        String(commission.level_percent || 0) +
        ')';
      if (commission.raises_level) {
        commissionLabel += ' · ' + t('raisesLevelWarn', 'This rate is higher than the seller level and will increase commission.');
      }
      if (commission.expires_at) {
        commissionLabel += ' · ' + t('expiresAt', 'Expires at') + ' ' + commission.expires_at;
      } else {
        commissionLabel += ' · ' + t('noExpiry', 'No end date');
      }
    }

    var html = '<article class="sutore-mp-staff-detail">';

    html +=
      '<div class="sutore-mp-manage-panel" data-panel="profile">' +
      '<section class="sutore-mp-staff-summary">' +
      '<dl class="sutore-mp-manage-kv">' +
      kvRow(t('userId', 'User ID'), esc('#' + String(data.id))) +
      kvRow(t('login', 'Username'), esc((data.user && data.user.login) || '—')) +
      kvRow(
        t('registered', 'Registered'),
        esc((data.user && (data.user.registered_label || data.user.registered)) || '—')
      ) +
      kvRow(t('level', 'Level'), esc(data.level_label || '—')) +
      kvRow(
        t('commission', 'Commission'),
        commission.is_overridden
          ? '<span class="sutore-mp-staff-meta-warn">' + esc(commissionLabel) + '</span>'
          : esc(commissionLabel)
      ) +
      kvRow(
        t('tc', 'TC identity'),
        esc(
          data.tc_verified_label ||
            (data.tc_verified ? t('tcVerified', 'TC verified') : t('tcNotVerified', 'TC not verified'))
        )
      ) +
      kvRow(
        t('restrictionStatus', 'Restrictions'),
        activeRestrictions.length
          ? '<span class="sutore-mp-staff-meta-warn">' + esc(restrictionSummary) + '</span>'
          : esc(restrictionSummary)
      ) +
      kvRow(t('listings', 'Listings'), esc(String(data.listing_count || 0))) +
      kvRow(t('inviteCode', 'Invite code'), esc((data.referral && data.referral.code) || '—')) +
      kvRow(
        t('referredBy', 'Referred by'),
        esc(
          (data.referral && data.referral.referred_by_login) ||
            (data.referral && data.referral.referred_by_user_id
              ? '#' + String(data.referral.referred_by_user_id)
              : '—')
        )
      ) +
      kvRow(
        t('referralRewarded', 'Referral rewarded'),
        esc((data.referral && data.referral.rewarded_at) || '—')
      ) +
      kvRow(t('pendingBalance', 'Pending balance'), esc(balance.formatted_pending || '—')) +
      kvRow(t('paidTotal', 'Paid total'), esc(balance.formatted_paid || '—')) +
      '</dl></section>' +
      '<section class="sutore-mp-staff-profile-panel">' +
      '<form class="sutore-mp-staff-merchant-profile woocommerce-EditAccountForm edit-account" data-merchant-id="' +
      esc(String(data.id)) +
      '" autocomplete="off">' +
      '<div class="sutore-mp-staff-merchant-profile-fields">' +
      profileFieldRow(
        'account_name',
        t('firstName', 'Account Holder First Name'),
        'text',
        {
          required: true,
          pattern: '[a-zA-ZığüşöçİĞÜŞÖÇ ]+',
          value: profile.account_name || ''
        },
        'form-row-first'
      ) +
      profileFieldRow(
        'account_lastname',
        t('lastName', 'Account Holder Last Name'),
        'text',
        {
          required: true,
          pattern: '[a-zA-ZığüşöçİĞÜŞÖÇ ]+',
          value: profile.account_lastname || ''
        },
        'form-row-last'
      ) +
      profileFieldRow(
        'account_iban',
        t('iban', 'IBAN'),
        'text',
        {
          required: true,
          pattern: '[a-zA-Z0-9-]+',
          value: profile.account_iban || 'TR'
        },
        'form-row-first'
      ) +
      profileFieldRow(
        'account_tckno',
        t('tckno', 'TC Identity Number'),
        'text',
        {
          required: true,
          pattern: '[0-9]{11}',
          inputmode: 'numeric',
          maxlength: '11',
          value: profile.account_tckno || ''
        },
        'form-row-last'
      ) +
      profileFieldRow(
        'account_birth_year',
        t('birthYear', 'Year of Birth'),
        'number',
        {
          required: true,
          min: '1900',
          max: String(data.birth_year_max || new Date().getUTCFullYear()),
          value: profile.account_birth_year || ''
        },
        'form-row-first'
      ) +
      profileFieldRow(
        'account_email',
        t('emailAddress', 'Email Address'),
        'email',
        { required: true, value: profile.account_email || '' },
        'form-row-last'
      ) +
      profileFieldRow(
        'account_phone',
        t('phoneNumber', 'Phone Number'),
        'tel',
        {
          required: true,
          pattern: '[0-9]{10,11}',
          inputmode: 'numeric',
          value: profile.account_phone || ''
        },
        'form-row-wide'
      ) +
      profileFieldRow(
        'account_city',
        t('city', 'City'),
        'select',
        {
          required: true,
          optionsHtml: cityOptions(data.cities, profile.account_city)
        },
        'form-row-first'
      ) +
      profileFieldRow(
        'account_state',
        t('district', 'District'),
        'select',
        { required: true, optionsHtml: '<option value="">' + esc(t('pickDistrict', 'Select')) + '</option>' },
        'form-row-last'
      ) +
      '</div>' +
      '<label class="sutore-mp-staff-check">' +
      '<input type="checkbox" name="mark_tc_verified" value="1" />' +
      '<span>' +
      esc(t('markTcVerified', 'Mark TC as verified')) +
      '</span></label>' +
      '<div class="sutore-mp-staff-actions">' +
      '<button type="submit" class="woocommerce-Button button wp-element-button">' +
      esc(t('save', 'Save')) +
      '</button></div></form></section></div>';

    var levelOpts = '';
    var levelOptions = data.level_options || {};
    Object.keys(levelOptions).forEach(function (key) {
      levelOpts +=
        '<option value="' +
        esc(key) +
        '"' +
        (key === data.level ? ' selected' : '') +
        '>' +
        esc(levelOptions[key]) +
        '</option>';
    });

    html +=
      '<div class="sutore-mp-manage-panel" data-panel="level" hidden>' +
      '<section class="sutore-mp-staff-actions-panel">' +
      '<h3 class="sutore-mp-staff-panel-title">' +
      esc(t('updateLevel', 'Update level')) +
      '</h3>' +
      '<form class="sutore-mp-staff-merchant-level sutore-mp-staff-form" data-merchant-id="' +
      esc(String(data.id)) +
      '">' +
      '<div class="sutore-mp-staff-form-grid sutore-mp-staff-form-grid--level">' +
      '<label>' +
      esc(t('level', 'Level')) +
      '<select name="status" class="sutore-mp-input">' +
      levelOpts +
      '</select></label></div>' +
      '<div class="sutore-mp-staff-actions">' +
      '<button type="submit" class="wp-element-button is-style-outline">' +
      esc(t('save', 'Save')) +
      '</button></div></form></section></div>';

    var overrides = commission.active_overrides || [];
    html +=
      '<div class="sutore-mp-manage-panel" data-panel="commission" hidden>' +
      '<section class="sutore-mp-staff-actions-panel">' +
      '<h3 class="sutore-mp-staff-panel-title">' +
      esc(t('commissionOverrides', 'Commission overrides')) +
      '</h3>' +
      renderOverrideTable(overrides, t('noCommissionOverrides', 'No active commission overrides.')) +
      '<h3 class="sutore-mp-staff-panel-title sutore-mp-staff-subheading">' +
      esc(t('addCommissionOverride', 'Set commission override')) +
      '</h3>' +
      '<form class="sutore-mp-staff-merchant-commission sutore-mp-staff-form" data-merchant-id="' +
      esc(String(data.id)) +
      '" data-level-percent="' +
      esc(String(commission.level_percent || 0)) +
      '">' +
      renderCommissionFields() +
      '<div class="sutore-mp-staff-actions">' +
      '<button type="submit" class="wp-element-button is-style-outline">' +
      esc(t('save', 'Save')) +
      '</button></div></form></section></div>';

    html +=
      '<div class="sutore-mp-manage-panel" data-panel="restrictions" hidden>' +
      '<section class="sutore-mp-staff-actions-panel">' +
      '<h3 class="sutore-mp-staff-panel-title">' +
      esc(t('restrictions', 'Restrictions')) +
      '</h3>';
    if (!restrictions.length) {
      html += '<p class="sutore-mp-empty">' + esc(t('noRestrictions', 'No restrictions.')) + '</p>';
    } else {
      html +=
        '<div class="sutore-mp-staff-table-wrap"><table class="sutore-mp-staff-table"><thead><tr>' +
        '<th>' +
        esc(t('type', 'Type')) +
        '</th><th>' +
        esc(t('status', 'Status')) +
        '</th><th>' +
        esc(t('reason', 'Reason')) +
        '</th><th>' +
        esc(t('expiresAt', 'Expires at')) +
        '</th><th>' +
        esc(t('actions', 'Actions')) +
        '</th></tr></thead><tbody>';
      restrictions.forEach(function (r) {
        html +=
          '<tr><td>' +
          esc(keyLabels[r.restriction_key] || r.restriction_key) +
          '</td><td>' +
          esc(
            r.is_active
              ? t('active', 'Active')
              : r.is_expired
                ? t('expired', 'Expired')
                : t('inactive', 'Inactive')
          ) +
          '</td><td>' +
          esc(dash(r.reason)) +
          '</td><td>' +
          esc(r.expires_at ? r.expires_at : t('noExpiry', 'No end date')) +
          '</td><td>';
        if (r.is_active) {
          html +=
            '<button type="button" class="wp-element-button is-style-outline sutore-mp-staff-deactivate-restriction" data-id="' +
            esc(String(r.id)) +
            '">' +
            esc(t('deactivate', 'Remove restriction')) +
            '</button>';
        } else {
          html += '—';
        }
        html += '</td></tr>';
      });
      html += '</tbody></table></div>';
    }

    var keyOpts = '';
    (data.restriction_keys || []).forEach(function (key) {
      keyOpts +=
        '<option value="' +
        esc(key) +
        '">' +
        esc(keyLabels[key] || key) +
        '</option>';
    });

    html +=
      '<h3 class="sutore-mp-staff-panel-title sutore-mp-staff-subheading">' +
      esc(t('addRestriction', 'Add restriction')) +
      '</h3>' +
      '<form class="sutore-mp-staff-merchant-restriction sutore-mp-staff-form" data-merchant-id="' +
      esc(String(data.id)) +
      '">' +
      '<div class="sutore-mp-staff-form-grid">' +
      '<label>' +
      esc(t('type', 'Type')) +
      '<select name="restriction_key" class="sutore-mp-input">' +
      keyOpts +
      '</select></label>' +
      '<label>' +
      esc(t('reason', 'Reason')) +
      '<input type="text" name="reason" class="sutore-mp-input" /></label>' +
      '<label>' +
      esc(t('expiresAt', 'Expires at')) +
      '<input type="datetime-local" name="expires_at" class="sutore-mp-input" /></label></div>' +
      '<p class="description sutore-mp-staff-form-hint">' +
      esc(t('expiresAtHelp', 'Optional. Leave empty for no end date.')) +
      '</p>' +
      '<div class="sutore-mp-staff-actions">' +
      '<button type="submit" class="wp-element-button is-style-outline">' +
      esc(t('save', 'Save')) +
      '</button></div></form></section></div>';

    html +=
      '<div class="sutore-mp-manage-panel" data-panel="payouts" hidden>' +
      '<section class="sutore-mp-staff-payout-details">' +
      '<h3 class="sutore-mp-staff-panel-title">' +
      esc(t('recentPayouts', 'Recent payouts')) +
      '</h3>';
    var payouts = data.recent_payouts || [];
    if (!payouts.length) {
      html += '<p class="sutore-mp-empty">' + esc(t('noPayouts', 'No payout lines yet.')) + '</p>';
    } else {
      html +=
        '<div class="sutore-mp-staff-table-wrap"><table class="sutore-mp-staff-table"><thead><tr>' +
        '<th>' +
        esc(t('product', 'Product')) +
        '</th><th>' +
        esc(t('amount', 'Amount')) +
        '</th><th>' +
        esc(t('status', 'Status')) +
        '</th><th>' +
        esc(t('date', 'Date')) +
        '</th></tr></thead><tbody>';
      payouts.forEach(function (p) {
        var statusText = p.scheduled_message || p.payout_status_label || '—';
        var dateText = p.paid_at_display || p.scheduled_payout_date_display || p.created_at || '';
        html +=
          '<tr><td>' +
          payoutProductCell(p) +
          '</td><td>' +
          esc(p.formatted_net || '') +
          '</td><td>' +
          esc(statusText) +
          '</td><td>' +
          esc(dateText) +
          '</td></tr>';
      });
      html += '</tbody></table></div>';
    }
    html += '</section></div>';

    html +=
      '<div class="sutore-mp-manage-panel" data-panel="activity" hidden>' +
      '<section class="sutore-mp-staff-activity">' +
      '<h3 class="sutore-mp-staff-panel-title">' +
      esc(t('activityHistory', 'Activity history')) +
      '</h3>' +
      renderActivity(data.events || []) +
      '</section></div>';

    html += '</article>';

    var subParts = ['#' + String(data.id)];
    if (data.user && data.user.login) {
      subParts.push(String(data.user.login));
    }
    if (data.user && data.user.email) {
      subParts.push(String(data.user.email));
    }

    return {
      title: title,
      sub: subParts.join(' · '),
      badge: data.level_label || '',
      activeRestrictionCount: activeRestrictions.length,
      html: html
    };
  }

  function setDetailTab($host, tab) {
    var allowed = {
      profile: 1,
      level: 1,
      commission: 1,
      restrictions: 1,
      payouts: 1,
      activity: 1
    };
    if (!allowed[tab]) {
      tab = 'profile';
    }
    $host.find('.sutore-mp-staff-detail-tabs .sutore-mp-manage-tab').each(function () {
      var name = $(this).attr('data-tab');
      $(this).attr('aria-selected', name === tab ? 'true' : 'false');
    });
    $host.find('.sutore-mp-staff-detail-panels .sutore-mp-manage-panel').each(function () {
      $(this).prop('hidden', $(this).attr('data-panel') !== tab);
    });
  }

  function setRestrictionsTabBadge($host, count) {
    var $badge = $host.find('.sutore-mp-manage-tab[data-tab="restrictions"] .sutore-mp-staff-tab-badge');
    if (!$badge.length) {
      return;
    }
    count = parseInt(count, 10) || 0;
    if (count > 0) {
      $badge.text(String(count)).prop('hidden', false);
    } else {
      $badge.text('').prop('hidden', true);
    }
  }

  function showToast(message, type) {
    if (window.SutoreMarketplace && typeof SutoreMarketplace.showToast === 'function') {
      SutoreMarketplace.showToast(message, type);
      return;
    }
    if (type === 'error') {
      alertError(message);
      return;
    }
    window.alert(message);
  }

  function showMsg($form, ok, message) {
    var text = message || (ok ? t('saved', 'Saved') : t('error', 'Error'));
    if (ok) {
      showToast(text, 'success');
      return;
    }
    if (window.SutoreMarketplace && typeof SutoreMarketplace.showAlert === 'function') {
      SutoreMarketplace.showAlert(t('error', 'Error'), text, t('ok', 'OK'));
      return;
    }
    showToast(text, 'error');
  }

  function collectFilterState($shell) {
    var $filter = $shell.find('.sutore-mp-staff-merchants-filter');
    return {
      search: String($shell.find('.sutore-mp-staff-merchants-search').val() || '').trim(),
      level: String($filter.find('[name="level"]').val() || ''),
      tc_verified: String($filter.find('[name="tc_verified"]').val() || ''),
      has_restriction: String($filter.find('[name="has_restriction"]').val() || ''),
      balance: String($filter.find('[name="balance"]').val() || ''),
      sales: String($filter.find('[name="sales"]').val() || ''),
      orderby: String($shell.find('.sutore-mp-staff-merchants-sort [name="orderby"]').val() || 'id_desc'),
      page: 1
    };
  }

  function loadListRoot($root, overrides) {
    var state = readListState($root, overrides);
    var $shell = $pageShell($root);
    var $chrome = $shell.find('.sutore-mp-list-chrome');

    syncFilterFields($shell, state);
    updateListBadges($shell, state);
    $root.attr('aria-busy', 'true').html(loadingHtml());

    var query = {
      page: state.page,
      per_page: state.perPage
    };
    if (state.search) {
      query.search = state.search;
    }
    if (state.level) {
      query.level = state.level;
    }
    if (state.tc_verified !== '') {
      query.tc_verified = state.tc_verified;
    }
    if (state.has_restriction !== '') {
      query.has_restriction = state.has_restriction;
    }
    if (state.balance) {
      query.balance = state.balance;
    }
    if (state.sales) {
      query.sales = state.sales;
    }
    if (state.orderby && state.orderby !== 'id_desc') {
      query.orderby = state.orderby;
    }

    ajax('GET', 'admin/merchants', query)
      .done(function (res) {
        if (!res || !res.success || !res.data) {
          $root.attr('aria-busy', 'false').html(
            '<p class="sutore-mp-error">' + esc((res && res.message) || t('error', 'Error')) + '</p>'
          );
          $chrome.prop('hidden', false);
          return;
        }
        $root.data({
          search: state.search,
          level: state.level,
          tcVerified: state.tc_verified,
          hasRestriction: state.has_restriction,
          balance: state.balance,
          sales: state.sales,
          orderby: state.orderby,
          page: state.page
        });
        syncListUrl(state.baseUrl, state);
        $root.attr('aria-busy', 'false').html(renderList(res.data, state));
        $chrome.prop('hidden', false);
        var openId = parseInt($shell.attr('data-open-merchant-id'), 10) || 0;
        if (openId > 0) {
          $shell.attr('data-open-merchant-id', '0');
          openMerchantModal(openId, { replaceUrl: true, syncUrl: true });
        }
      })
      .fail(function (xhr) {
        var msg = t('error', 'Error');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        $root.attr('aria-busy', 'false').html('<p class="sutore-mp-error">' + esc(msg) + '</p>');
        $chrome.prop('hidden', false);
      });
  }

  function $detailHost() {
    return $('.sutore-mp-staff-merchant-detail-host').first();
  }

  function $detailOverlay() {
    return $detailHost().find('.sutore-mp-staff-merchant-detail-overlay');
  }

  function isMerchantListPage() {
    return $('.sutore-mp-staff-merchants-list-root').length > 0;
  }

  function otherManageOverlaysOpen() {
    return $(
      '.sutore-mp-manage-overlay.is-open:not(.sutore-mp-staff-merchant-detail-overlay)'
    ).length > 0;
  }

  function setDetailHeader($host, rendered) {
    $host.find('.sutore-mp-staff-detail-title').text(rendered.title || t('seller', 'Seller'));
    $host.find('.sutore-mp-staff-detail-sub').text(rendered.sub || '');
    var $badge = $host.find('.sutore-mp-staff-detail-badge');
    if (rendered.badge) {
      $badge.text(rendered.badge).prop('hidden', false);
    } else {
      $badge.text('').prop('hidden', true);
    }
  }

  function reloadMerchantDetail(merchantId, options) {
    options = options || {};
    var $host = $detailHost();
    merchantId = parseInt(merchantId, 10) || parseInt($host.data('currentMerchantId'), 10) || 0;
    var $root = $host.find('.sutore-mp-staff-detail-root');
    var $panels = $host.find('.sutore-mp-staff-detail-panels');
    var $loading = $host.find('.sutore-mp-staff-manage-loading');
    var keepTab =
      options.tab ||
      String($host.find('.sutore-mp-manage-tab[aria-selected="true"]').attr('data-tab') || 'profile');
    if (keepTab === 'controls') {
      keepTab = 'level';
    }

    if (!merchantId || !cfg.restUrl) {
      $panels.html('<p class="sutore-mp-error">' + esc(t('error', 'Error')) + '</p>');
      return;
    }

    $host.data('currentMerchantId', merchantId);
    $root.attr('aria-busy', 'true');
    $loading.prop('hidden', false);

    return ajax('GET', 'admin/merchants/' + merchantId)
      .done(function (res) {
        $loading.prop('hidden', true);
        $root.attr('aria-busy', 'false');
        if (!res || !res.success || !res.data) {
          setDetailHeader($host, { title: t('seller', 'Seller') });
          $panels.html(
            '<p class="sutore-mp-error">' +
              esc((res && res.message) || t('notFound', 'Seller not found.')) +
              '</p>'
          );
          return;
        }
        var rendered = renderDetail(res.data);
        setDetailHeader($host, rendered);
        setRestrictionsTabBadge($host, rendered.activeRestrictionCount);
        $panels.html(rendered.html);
        setDetailTab($host, keepTab);
        bindProfileLocation(
          $panels.find('.sutore-mp-staff-merchant-profile'),
          res.data.profile || {}
        );
      })
      .fail(function (xhr) {
        $loading.prop('hidden', true);
        $root.attr('aria-busy', 'false');
        setDetailHeader($host, { title: t('seller', 'Seller') });
        $panels.html(
          '<p class="sutore-mp-error">' +
            esc((xhr.responseJSON && xhr.responseJSON.message) || t('notFound', 'Seller not found.')) +
            '</p>'
        );
      });
  }

  function refreshDetailFrom() {
    reloadMerchantDetail($detailHost().data('currentMerchantId'));
  }

  function openMerchantModal(merchantId, options) {
    options = options || {};
    merchantId = parseInt(merchantId, 10) || 0;
    if (!merchantId) {
      return;
    }
    var $host = $detailHost();
    var $overlay = $detailOverlay();
    if (!$overlay.length) {
      return;
    }
    var syncUrl = options.syncUrl === true || (options.syncUrl !== false && isMerchantListPage());
    $overlay.prop('hidden', false).addClass('is-open');
    $('body').addClass('sutore-mp-modal-open');
    setDetailHeader($host, { title: t('seller', 'Seller') });
    setRestrictionsTabBadge($host, 0);
    setDetailTab($host, 'profile');
    $host.find('.sutore-mp-staff-detail-panels').empty();
    if (syncUrl) {
      var baseUrl = String($('.sutore-mp-staff-merchants-list-root').data('baseUrl') || cfg.merchantsUrl || '');
      try {
        var method = options.replaceUrl ? 'replaceState' : 'pushState';
        window.history[method]({}, '', detailUrl(baseUrl, merchantId));
      } catch (err) {
        /* ignore */
      }
    }
    reloadMerchantDetail(merchantId, { tab: 'profile' });
  }

  function closeMerchantModal(options) {
    options = options || {};
    var $host = $detailHost();
    var $overlay = $detailOverlay();
    $overlay.prop('hidden', true).removeClass('is-open');
    $host.find('.sutore-mp-staff-detail-panels').empty();
    $host.removeData('currentMerchantId');
    if (!otherManageOverlaysOpen()) {
      $('body').removeClass('sutore-mp-modal-open');
    }
    var syncUrl = options.syncUrl === true || (options.syncUrl !== false && isMerchantListPage());
    if (syncUrl) {
      var $root = $('.sutore-mp-staff-merchants-list-root');
      if ($root.length) {
        syncListUrl(String($root.data('baseUrl') || ''), readListState($root));
      }
      $('.sutore-mp-staff-merchants').first().attr('data-open-merchant-id', '0');
    }
  }

  function isStaffMerchantOpen() {
    return $detailOverlay().hasClass('is-open') && !$detailOverlay().prop('hidden');
  }

  window.SutoreMarketplace = window.SutoreMarketplace || {};
  SutoreMarketplace.openStaffMerchant = function (merchantId, options) {
    openMerchantModal(merchantId, options || { syncUrl: false });
  };
  SutoreMarketplace.closeStaffMerchant = function (options) {
    closeMerchantModal(options || { syncUrl: false });
  };
  SutoreMarketplace.isStaffMerchantOpen = isStaffMerchantOpen;

  $(function () {
    var $list = $('.sutore-mp-staff-merchants-list-root');
    if ($list.length) {
      loadListRoot($list);
    }
  });

  $(document).on('click', '.sutore-mp-staff-open-merchant', function (e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.which === 2) {
      return;
    }
    e.preventDefault();
    var merchantId =
      parseInt($(this).attr('data-merchant-id'), 10) ||
      parseInt($(this).data('merchant-id'), 10) ||
      0;
    if (merchantId > 0) {
      openMerchantModal(merchantId, {
        syncUrl: isMerchantListPage()
      });
    }
  });

  $(document).on('click', '.sutore-mp-staff-merchants-close', function (e) {
    e.preventDefault();
    closeMerchantModal({ syncUrl: isMerchantListPage() });
  });

  $(document).on('click', '.sutore-mp-staff-merchant-detail-host .sutore-mp-staff-detail-tabs .sutore-mp-manage-tab', function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    setDetailTab($detailHost(), String($(this).attr('data-tab') || 'profile'));
  });

  $(document).on('click', '.sutore-mp-staff-merchant-detail-overlay', function (e) {
    if (e.target === this) {
      closeMerchantModal({ syncUrl: isMerchantListPage() });
    }
  });

  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape' || $('.sutore-mp-confirm').length) {
      return;
    }
    if (
      window.SutoreMarketplace &&
      SutoreMarketplace.isStaffProductOpen &&
      SutoreMarketplace.isStaffProductOpen() &&
      $('.sutore-mp-staff-product-detail-overlay.is-over-merchant').length
    ) {
      return;
    }
    if (isStaffMerchantOpen()) {
      closeMerchantModal({ syncUrl: isMerchantListPage() });
      e.stopImmediatePropagation();
    }
  });

  $(document).on('click', '.sutore-mp-staff-merchants-filter-apply', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-merchants-list-root');
    SutoreMarketplace.closeListOverlays($shell);
    loadListRoot($root, collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-merchants-filter-clear', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-merchants-list-root');
    $shell.find('.sutore-mp-staff-merchants-filter select').each(function () {
      $(this).prop('selectedIndex', 0);
    });
    SutoreMarketplace.closeListOverlays($shell);
    loadListRoot($root, collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-merchants-sort-apply', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-merchants-list-root');
    SutoreMarketplace.closeListOverlays($shell);
    loadListRoot($root, collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-merchants-sort-clear', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-merchants-list-root');
    $shell.find('.sutore-mp-staff-merchants-sort [name="orderby"]').val('id_desc');
    SutoreMarketplace.closeListOverlays($shell);
    loadListRoot($root, collectFilterState($shell));
  });

  $(document).on('keydown', '.sutore-mp-staff-merchants-search', function (e) {
    if (e.key !== 'Enter') {
      return;
    }
    e.preventDefault();
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-merchants-list-root');
    SutoreMarketplace.closeListOverlays($shell);
    loadListRoot($root, collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-merchants-list-root .sutore-mp-staff-pager a[data-page]', function (e) {
    e.preventDefault();
    var $root = $(this).closest('.sutore-mp-staff-merchants-list-root');
    var state = readListState($root);
    state.page = parseInt($(this).data('page'), 10) || 1;
    loadListRoot($root, state);
  });

  $(document).on('change', '.sutore-mp-staff-merchant-profile [name="account_city"]', function () {
    var $form = $(this).closest('.sutore-mp-staff-merchant-profile');
    loadDistrictOptions($form, String($(this).val() || ''), '');
  });

  $(document).on('submit', '.sutore-mp-staff-merchant-profile', function (e) {
    e.preventDefault();
    var $form = $(this);
    var id = parseInt($form.data('merchant-id'), 10) || 0;
    if (!id) {
      return;
    }
    var body = {};
    $form.serializeArray().forEach(function (pair) {
      body[pair.name] = pair.value;
    });
    body.mark_tc_verified = $form.find('[name="mark_tc_verified"]').is(':checked') ? 1 : 0;
    $form.find('button[type="submit"]').prop('disabled', true).text(t('saving', 'Saving…'));
    ajax('PUT', 'admin/merchants/' + id, body)
      .done(function (res) {
        if (!res || !res.success) {
          showMsg($form, false, (res && res.message) || t('error', 'Error'));
          return;
        }
        showMsg($form, true, (res.data && res.data.message) || t('saved', 'Saved'));
        refreshDetailFrom($form);
      })
      .fail(function (xhr) {
        var msg = t('error', 'Error');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        showMsg($form, false, msg);
      })
      .always(function () {
        $form.find('button[type="submit"]').prop('disabled', false).text(t('save', 'Save'));
      });
  });

  $(document).on('submit', '.sutore-mp-staff-merchant-level', function (e) {
    e.preventDefault();
    var $form = $(this);
    var id = parseInt($form.data('merchant-id'), 10) || 0;
    ajax('POST', 'admin/merchants/status', {
      merchant_id: id,
      status: String($form.find('[name="status"]').val() || '')
    })
      .done(function (res) {
        if (!res || !res.success) {
          showMsg($form, false, (res && res.message) || t('error', 'Error'));
          return;
        }
        showMsg($form, true, (res.data && res.data.message) || t('saved', 'Saved'));
        refreshDetailFrom($form);
      })
      .fail(function (xhr) {
        var msg = t('error', 'Error');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        showMsg($form, false, msg);
      });
  });

  $(document).on('submit', '.sutore-mp-staff-merchant-commission', function (e) {
    e.preventDefault();
    var $form = $(this);
    var id = parseInt($form.data('merchant-id'), 10) || 0;
    ajax('POST', 'admin/merchants/' + id + '/commission-override', {
      commission_percent: String($form.find('[name="commission_percent"]').val() || ''),
      adjustment: String($form.find('[name="adjustment"]').val() || 'absolute'),
      starts_at: String($form.find('[name="starts_at"]').val() || ''),
      expires_at: String($form.find('[name="expires_at"]').val() || ''),
      note: String($form.find('[name="note"]').val() || '')
    })
      .done(function (res) {
        if (!res || !res.success) {
          showMsg($form, false, (res && res.message) || t('error', 'Error'));
          return;
        }
        showMsg($form, true, (res.data && res.data.message) || t('saved', 'Saved'));
        refreshDetailFrom($form);
      })
      .fail(function (xhr) {
        var msg = t('error', 'Error');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        showMsg($form, false, msg);
      });
  });

  $(document).on('click', '.sutore-mp-staff-delete-commission', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var id = parseInt($btn.data('id'), 10) || 0;
    if (!id) {
      return;
    }
    confirmAction(
      t('deleteOverride', 'Delete'),
      t('deleteOverrideConfirm', 'Delete this commission override?'),
      function () {
        $btn.prop('disabled', true).text(t('deleting', 'Deleting…'));
        ajax('DELETE', 'admin/merchants/commission-overrides/' + id, {})
          .done(function (res) {
            if (!res || !res.success) {
              alertError((res && res.message) || t('error', 'Error'));
              $btn.prop('disabled', false).text(t('deleteOverride', 'Delete'));
              return;
            }
            refreshDetailFrom($btn);
            var $root = $('.sutore-mp-staff-merchants-list-root');
            if ($root.length) {
              loadListRoot($root);
            }
          })
          .fail(function (xhr) {
            alertError((xhr.responseJSON && xhr.responseJSON.message) || t('error', 'Error'));
            $btn.prop('disabled', false).text(t('deleteOverride', 'Delete'));
          });
      }
    );
  });

  $(document).on('submit', '.sutore-mp-staff-platform-commission-form', function (e) {
    e.preventDefault();
    var $form = $(this);
    $form.find('button[type="submit"]').prop('disabled', true).text(t('saving', 'Saving…'));
    ajax('POST', 'admin/commission-overrides', {
      commission_percent: String($form.find('[name="commission_percent"]').val() || ''),
      adjustment: String($form.find('[name="adjustment"]').val() || 'percent_off'),
      starts_at: String($form.find('[name="starts_at"]').val() || ''),
      expires_at: String($form.find('[name="expires_at"]').val() || ''),
      note: String($form.find('[name="note"]').val() || '')
    })
      .done(function (res) {
        if (!res || !res.success) {
          showMsg($form, false, (res && res.message) || t('error', 'Error'));
          return;
        }
        showMsg($form, true, (res.data && res.data.message) || t('saved', 'Saved'));
        var $root = $('.sutore-mp-staff-merchants-list-root');
        if ($root.length) {
          loadListRoot($root);
        }
      })
      .fail(function (xhr) {
        var msg = t('error', 'Error');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        showMsg($form, false, msg);
      })
      .always(function () {
        $form.find('button[type="submit"]').prop('disabled', false).text(t('save', 'Save'));
      });
  });

  function syncRaiseWarn($form) {
    var $warn = $form.find('.sutore-mp-staff-commission-raise-warn');
    if (!$warn.length) {
      return;
    }
    var level = parseFloat($form.data('level-percent'));
    var mode = String($form.find('[name="adjustment"]').val() || 'absolute');
    var val = parseFloat($form.find('[name="commission_percent"]').val());
    var show = mode === 'absolute' && !isNaN(level) && !isNaN(val) && val > level;
    $warn.prop('hidden', !show);
  }

  $(document).on(
    'input change',
    '.sutore-mp-staff-merchant-commission [name="commission_percent"], .sutore-mp-staff-merchant-commission [name="adjustment"]',
    function () {
      syncRaiseWarn($(this).closest('form'));
    }
  );

  $(document).on('submit', '.sutore-mp-staff-merchant-restriction', function (e) {
    e.preventDefault();
    var $form = $(this);
    var id = parseInt($form.data('merchant-id'), 10) || 0;
    ajax('POST', 'admin/restrictions', {
      merchant_id: id,
      restriction_key: String($form.find('[name="restriction_key"]').val() || ''),
      reason: String($form.find('[name="reason"]').val() || ''),
      expires_at: String($form.find('[name="expires_at"]').val() || '')
    })
      .done(function (res) {
        if (!res || !res.success) {
          showMsg($form, false, (res && res.message) || t('error', 'Error'));
          return;
        }
        showMsg($form, true, (res.data && res.data.message) || t('saved', 'Saved'));
        refreshDetailFrom($form);
      })
      .fail(function (xhr) {
        var msg = t('error', 'Error');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        showMsg($form, false, msg);
      });
  });

  $(document).on('click', '.sutore-mp-staff-deactivate-restriction', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var id = parseInt($btn.data('id'), 10) || 0;
    if (!id) {
      return;
    }
    confirmAction(
      t('deactivate', 'Remove restriction'),
      t('deactivateConfirm', 'Remove this restriction?'),
      function () {
        $btn.prop('disabled', true).text(t('removing', 'Removing…'));
        ajax('POST', 'admin/restrictions/' + id + '/deactivate', {})
          .done(function (res) {
            if (!res || !res.success) {
              alertError((res && res.message) || t('error', 'Error'));
              $btn.prop('disabled', false).text(t('deactivate', 'Remove restriction'));
              return;
            }
            refreshDetailFrom($btn);
          })
          .fail(function (xhr) {
            alertError((xhr.responseJSON && xhr.responseJSON.message) || t('error', 'Error'));
            $btn.prop('disabled', false).text(t('deactivate', 'Remove restriction'));
          });
      }
    );
  });
})(jQuery);
