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

  function selectOptions(options, selected, emptyLabel) {
    var html = '<option value="">' + esc(emptyLabel) + '</option>';
    options.forEach(function (opt) {
      html +=
        '<option value="' +
        esc(opt.value) +
        '"' +
        (String(opt.value) === String(selected) ? ' selected' : '') +
        '>' +
        esc(opt.label) +
        '</option>';
    });
    return html;
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
    var baseUrl = state.baseUrl || '';

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
          '</td><td><a class="wp-element-button is-style-outline" href="' +
          esc(detailUrl(baseUrl, item.id)) +
          '">' +
          esc(t('detail', 'Detail')) +
          '</a></td></tr>';
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

    return html;
  }

  function metaItem(label, valueHtml) {
    return '<div><dt>' + esc(label) + '</dt><dd>' + valueHtml + '</dd></div>';
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
      return '<p>' + esc(t('noActivity', 'No activity recorded yet.')) + '</p>';
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
    var commissionLabel = '%' + String(commission.effective_percent != null ? commission.effective_percent : data.commission_percent || 0);
    if (commission.is_overridden) {
      commissionLabel +=
        ' (' +
        t('levelCommission', 'Level') +
        ' %' +
        String(commission.level_percent || 0) +
        ')';
      if (commission.expires_at) {
        commissionLabel += ' · ' + t('expiresAt', 'Expires at') + ' ' + commission.expires_at;
      } else {
        commissionLabel += ' · ' + t('noExpiry', 'No end date');
      }
    }

    var html = '<article class="sutore-mp-staff-detail">';

    html +=
      '<section class="sutore-mp-staff-summary">' +
      '<dl class="sutore-mp-staff-meta">' +
      metaItem(t('userId', 'User ID'), esc('#' + String(data.id))) +
      metaItem(t('login', 'Username'), esc((data.user && data.user.login) || '—')) +
      metaItem(t('level', 'Level'), esc(data.level_label || '—')) +
      metaItem(
        t('commission', 'Commission'),
        commission.is_overridden
          ? '<span class="sutore-mp-staff-meta-warn">' + esc(commissionLabel) + '</span>'
          : esc(commissionLabel)
      ) +
      metaItem(
        t('tc', 'TC identity'),
        esc(
          data.tc_verified_label ||
            (data.tc_verified ? t('tcVerified', 'TC verified') : t('tcNotVerified', 'TC not verified'))
        )
      ) +
      metaItem(
        t('restrictionStatus', 'Restrictions'),
        activeRestrictions.length
          ? '<span class="sutore-mp-staff-meta-warn">' + esc(restrictionSummary) + '</span>'
          : esc(restrictionSummary)
      ) +
      metaItem(t('listings', 'Listings'), esc(String(data.listing_count || 0))) +
      metaItem(t('pendingBalance', 'Pending balance'), esc(balance.formatted_pending || '—')) +
      metaItem(t('paidTotal', 'Paid total'), esc(balance.formatted_paid || '—')) +
      '</dl></section>';

    html +=
      '<section class="sutore-mp-staff-shipping-details">' +
      '<h3>' +
      esc(t('profile', 'Profile')) +
      '</h3>' +
      '<p class="description">' +
      esc(t('profileDesc', 'Edit seller profile fields. Sensitive changes are recorded in activity history.')) +
      '</p>' +
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
        t('district', 'District / Neighborhood'),
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
      '</button>' +
      '<p class="sutore-mp-staff-msg" hidden></p></div></form></section>';

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
      '<section class="sutore-mp-staff-actions-panel">' +
      '<h3>' +
      esc(t('actions', 'Actions')) +
      '</h3>' +
      '<p class="description">' +
      esc(t('actionsDesc', 'Update seller level, commission overrides, and account restrictions.')) +
      '</p>' +
      '<form class="sutore-mp-staff-merchant-level sutore-mp-staff-form" data-merchant-id="' +
      esc(String(data.id)) +
      '">' +
      '<div class="sutore-mp-staff-actions">' +
      '<label>' +
      esc(t('updateLevel', 'Update level')) +
      '<select name="status" class="sutore-mp-input">' +
      levelOpts +
      '</select></label>' +
      '<button type="submit" class="wp-element-button is-style-outline">' +
      esc(t('save', 'Save')) +
      '</button>' +
      '<p class="sutore-mp-staff-msg" hidden></p></div></form>';

    var overrides = commission.active_overrides || [];
    html += '<h4 class="sutore-mp-staff-subheading">' + esc(t('commissionOverrides', 'Commission overrides')) + '</h4>';
    if (!overrides.length) {
      html += '<p class="description">' + esc(t('noCommissionOverrides', 'No active commission overrides.')) + '</p>';
    } else {
      html +=
        '<div class="sutore-mp-staff-table-wrap"><table class="sutore-mp-staff-table"><thead><tr>' +
        '<th>' +
        esc(t('commission', 'Commission')) +
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
        html +=
          '<tr><td>' +
          esc('%' + String(o.commission_percent)) +
          '</td><td>' +
          esc(o.source || '—') +
          '</td><td>' +
          esc(o.expires_at ? o.expires_at : t('noExpiry', 'No end date')) +
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
    }

    html +=
      '<form class="sutore-mp-staff-merchant-commission sutore-mp-staff-form" data-merchant-id="' +
      esc(String(data.id)) +
      '">' +
      '<div class="sutore-mp-staff-form-grid">' +
      '<label>' +
      esc(t('commissionPercent', 'Commission %')) +
      '<input type="number" name="commission_percent" class="sutore-mp-input" min="0" max="100" step="0.01" required /></label>' +
      '<label>' +
      esc(t('expiresAt', 'Expires at')) +
      '<input type="datetime-local" name="expires_at" class="sutore-mp-input" />' +
      '<span class="description">' +
      esc(t('expiresAtHelp', 'Optional. Leave empty for no end date.')) +
      '</span></label>' +
      '<label>' +
      esc(t('note', 'Note')) +
      '<input type="text" name="note" class="sutore-mp-input" /></label></div>' +
      '<div class="sutore-mp-staff-actions">' +
      '<button type="submit" class="wp-element-button is-style-outline">' +
      esc(t('addCommissionOverride', 'Set commission override')) +
      '</button>' +
      '<p class="sutore-mp-staff-msg" hidden></p></div></form>';

    html += '<h4 class="sutore-mp-staff-subheading">' + esc(t('restrictions', 'Restrictions')) + '</h4>';
    if (!restrictions.length) {
      html += '<p class="description">' + esc(t('noRestrictions', 'No restrictions.')) + '</p>';
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
      '<input type="datetime-local" name="expires_at" class="sutore-mp-input" />' +
      '<span class="description">' +
      esc(t('expiresAtHelp', 'Optional. Leave empty for no end date.')) +
      '</span></label></div>' +
      '<div class="sutore-mp-staff-actions">' +
      '<button type="submit" class="wp-element-button is-style-outline">' +
      esc(t('addRestriction', 'Add restriction')) +
      '</button>' +
      '<p class="sutore-mp-staff-msg" hidden></p></div></form></section>';

    html +=
      '<section class="sutore-mp-staff-payout-details">' +
      '<h3>' +
      esc(t('recentPayouts', 'Recent payouts')) +
      '</h3>' +
      '<p class="description">' +
      esc(t('recentPayoutsDesc', 'Latest payout lines for this seller.')) +
      '</p>';
    var payouts = data.recent_payouts || [];
    if (!payouts.length) {
      html += '<p>' + esc(t('noPayouts', 'No payout lines yet.')) + '</p>';
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
        html +=
          '<tr><td>' +
          esc(p.product_title || '') +
          (p.listing_id
            ? '<span class="sutore-mp-staff-sub">#' + esc(String(p.listing_id)) + '</span>'
            : '') +
          '</td><td>' +
          esc(p.formatted_net || '') +
          '</td><td>' +
          esc(p.payout_status_label || '—') +
          '</td><td>' +
          esc(p.created_at || '') +
          '</td></tr>';
      });
      html += '</tbody></table></div>';
    }
    html += '</section>';

    html +=
      '<section class="sutore-mp-staff-activity">' +
      '<h3>' +
      esc(t('activityHistory', 'Activity history')) +
      '</h3>' +
      '<p class="description">' +
      esc(t('activityHistoryDesc', 'Profile, level, and restriction changes for this seller.')) +
      '</p>' +
      renderActivity(data.events || []) +
      '</section>';

    html += '</article>';

    return { title: title, html: html };
  }

  function showMsg($form, ok, message) {
    var $msg = $form.find('.sutore-mp-staff-msg');
    $msg
      .prop('hidden', false)
      .toggleClass('sutore-mp-staff-msg--ok', !!ok)
      .toggleClass('sutore-mp-staff-msg--err', !ok)
      .text(message || (ok ? t('saved', 'Saved') : t('error', 'Error')));
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

  function loadDetailRoot($root) {
    var id = parseInt($root.data('merchantId'), 10) || 0;
    var $chrome = $root.closest('.sutore-mp-staff-merchants').find('.sutore-mp-detail-chrome');
    $chrome.prop('hidden', true);
    if (!id || !cfg.restUrl) {
      $root.attr('aria-busy', 'false').html(
        '<p class="sutore-mp-error">' + esc(t('error', 'Error')) + '</p>'
      );
      $chrome.prop('hidden', false);
      return;
    }

    $root.attr('aria-busy', 'true').html(loadingHtml());

    ajax('GET', 'admin/merchants/' + id)
      .done(function (res) {
        if (!res || !res.success || !res.data) {
          $root.attr('aria-busy', 'false').html(
            '<p class="sutore-mp-error">' +
              esc((res && res.message) || t('notFound', 'Seller not found.')) +
              '</p>'
          );
          $chrome.find('.sutore-mp-staff-detail-title').text(t('seller', 'Seller'));
          $chrome.prop('hidden', false);
          return;
        }
        var rendered = renderDetail(res.data);
        $chrome.find('.sutore-mp-staff-detail-title').text(rendered.title);
        $root.attr('aria-busy', 'false').html(rendered.html);
        bindProfileLocation(
          $root.find('.sutore-mp-staff-merchant-profile'),
          (res.data && res.data.profile) || {}
        );
        $chrome.prop('hidden', false);
      })
      .fail(function (xhr) {
        var msg = t('notFound', 'Seller not found.');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        $root.attr('aria-busy', 'false').html('<p class="sutore-mp-error">' + esc(msg) + '</p>');
        $chrome.find('.sutore-mp-staff-detail-title').text(t('seller', 'Seller'));
        $chrome.prop('hidden', false);
      });
  }

  $(function () {
    var $detail = $('.sutore-mp-staff-merchants-detail-root');
    if ($detail.length) {
      loadDetailRoot($detail);
    }
    var $list = $('.sutore-mp-staff-merchants-list-root');
    if ($list.length) {
      loadListRoot($list);
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
        loadDetailRoot($('.sutore-mp-staff-merchants-detail-root'));
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
        loadDetailRoot($('.sutore-mp-staff-merchants-detail-root'));
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
      expires_at: String($form.find('[name="expires_at"]').val() || ''),
      note: String($form.find('[name="note"]').val() || '')
    })
      .done(function (res) {
        if (!res || !res.success) {
          showMsg($form, false, (res && res.message) || t('error', 'Error'));
          return;
        }
        showMsg($form, true, (res.data && res.data.message) || t('saved', 'Saved'));
        loadDetailRoot($('.sutore-mp-staff-merchants-detail-root'));
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
    if (!window.confirm(t('deleteOverrideConfirm', 'Delete this commission override?'))) {
      return;
    }
    $btn.prop('disabled', true).text(t('deleting', 'Deleting…'));
    ajax('DELETE', 'admin/merchants/commission-overrides/' + id, {})
      .done(function (res) {
        if (!res || !res.success) {
          window.alert((res && res.message) || t('error', 'Error'));
          $btn.prop('disabled', false).text(t('deleteOverride', 'Delete'));
          return;
        }
        loadDetailRoot($('.sutore-mp-staff-merchants-detail-root'));
      })
      .fail(function (xhr) {
        var msg = t('error', 'Error');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        window.alert(msg);
        $btn.prop('disabled', false).text(t('deleteOverride', 'Delete'));
      });
  });

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
        loadDetailRoot($('.sutore-mp-staff-merchants-detail-root'));
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
    if (!window.confirm(t('deactivateConfirm', 'Remove this restriction?'))) {
      return;
    }
    $btn.prop('disabled', true).text(t('removing', 'Removing…'));
    ajax('POST', 'admin/restrictions/' + id + '/deactivate', {})
      .done(function (res) {
        if (!res || !res.success) {
          window.alert((res && res.message) || t('error', 'Error'));
          $btn.prop('disabled', false).text(t('deactivate', 'Remove restriction'));
          return;
        }
        loadDetailRoot($('.sutore-mp-staff-merchants-detail-root'));
      })
      .fail(function (xhr) {
        var msg = t('error', 'Error');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        window.alert(msg);
        $btn.prop('disabled', false).text(t('deactivate', 'Remove restriction'));
      });
  });
})(jQuery);
