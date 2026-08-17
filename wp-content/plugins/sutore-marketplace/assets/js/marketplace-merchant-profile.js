(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplace || {};

  function t(key, fallback) {
    return cfg.t ? cfg.t(key, fallback) : fallback;
  }

  function esc(str) {
    return $('<div/>').text(str == null ? '' : String(str)).html();
  }

  function feedback($el, message, type) {
    $el.removeClass('is-error is-success')
      .addClass(type === 'error' ? 'is-error' : 'is-success')
      .text(message || '');
  }

  function loadingHtml() {
    return (
      '<div class="sutore-mp-list-loading" role="status" aria-live="polite">' +
      '<span class="sutore-mp-list-spinner" aria-hidden="true"></span>' +
      '<span class="screen-reader-text">' + esc(t('loading', 'Loading…')) + '</span></div>'
    );
  }

  function loadDistricts(city, selected) {
    var $state = $('#account_state');
    if (!$state.length || !city || !cfg.api) {
      return;
    }

    cfg.api('marketplace_merchant_districts', { city: city }).done(function (res) {
      $state.empty().append($('<option>').val('').text(t('pickDistrict', 'Select')));
      if (res && res.success && res.data && res.data.districts) {
        res.data.districts.forEach(function (district) {
          var $opt = $('<option>').val(district).text(district);
          if (selected && selected === district) {
            $opt.prop('selected', true);
          }
          $state.append($opt);
        });
      }
    });
  }

  function fieldRow(name, label, type, attrs, className) {
    attrs = attrs || {};
    var id = name;
    var html =
      '<p class="form-row ' + esc(className || 'form-row-wide') + '">' +
      '<label for="' + esc(id) + '">' + esc(label) +
      (attrs.required ? '&nbsp;<abbr class="required" title="required">*</abbr>' : '') +
      '</label>';
    if (type === 'select') {
      html += '<select name="' + esc(name) + '" id="' + esc(id) + '" class="select sutore-mp-input"';
      if (attrs.required) html += ' required';
      html += '></select>';
    } else {
      html += '<input type="' + esc(type || 'text') + '" class="woocommerce-Input input-text sutore-mp-input" name="' +
        esc(name) + '" id="' + esc(id) + '"';
      Object.keys(attrs).forEach(function (key) {
        if (key === 'required' && attrs[key]) {
          html += ' required';
          return;
        }
        if (key === 'readonly' && attrs[key]) {
          html += ' readonly';
          return;
        }
        html += ' ' + esc(key) + '="' + esc(String(attrs[key])) + '"';
      });
      html += ' />';
    }
    html += '</p>';
    return html;
  }

  function renderSummary(profileData, balance) {
    var html = '';
    if (profileData.can_view_dashboard) {
      html +=
        '<section class="sutore-mp-merchant-profile__section sutore-mp-merchant-profile__summary">' +
        '<h2>' + esc(t('merchantSummary', 'Merchant summary')) + '</h2>' +
        '<div class="sutore-mp-merchant-profile__stats">' +
        '<div class="sutore-mp-merchant-profile__stat"><span class="sutore-mp-merchant-profile__stat-label">' +
        esc(t('level', 'Level')) + '</span><strong>' + esc(profileData.level_label || profileData.level || '') +
        '</strong></div>';

      if (profileData.behavior && profileData.behavior.score != null) {
        html +=
          '<div class="sutore-mp-merchant-profile__stat sutore-mp-merchant-profile__stat--behavior">' +
          '<span class="sutore-mp-merchant-profile__stat-label">' +
          esc(t('behaviorScore', 'Behavior score')) + '</span><strong>' +
          esc(String(profileData.behavior.score)) + ' / ' +
          esc(String(profileData.behavior.score_max || 5)) + '</strong>';
        if (profileData.behavior.summary) {
          html += '<small>' + esc(profileData.behavior.summary) + '</small>';
        }
        html += '</div>';
      }

      html +=
        '<div class="sutore-mp-merchant-profile__stat"><span class="sutore-mp-merchant-profile__stat-label">' +
        esc(t('commission', 'Commission')) + '</span><strong>' +
        esc(String(profileData.commission_percent || 0)) + '%</strong>';

      if (profileData.commission_overridden) {
        var overrideNote =
          esc(profileData.commission_override_label || t('commissionDiscountActive', 'Commission discount active'));
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
        html += '<small class="sutore-mp-merchant-profile__commission-note">' + overrideNote + '</small>';
      }

      html += '</div>';

      if (balance) {
        html +=
          '<div class="sutore-mp-merchant-profile__stat"><span class="sutore-mp-merchant-profile__stat-label">' +
          esc(t('paidPayout', 'Paid payout')) + '</span><strong>' +
          esc(balance.formatted_paid || '0 TL') + '</strong><small>' +
          esc(t('salesCount', '%d sales').replace('%d', String(balance.paid_count || 0))) +
          '</small></div>' +
          '<div class="sutore-mp-merchant-profile__stat"><span class="sutore-mp-merchant-profile__stat-label">' +
          esc(t('pendingPayout', 'Pending payout')) + '</span><strong>' +
          esc(balance.formatted_pending || '0 TL') + '</strong><small>' +
          esc(t('salesCount', '%d sales').replace('%d', String(balance.pending_count || 0))) +
          '</small></div>';
      }
      html += '</div>';
      html += renderReferral(profileData);

      if (balance && balance.recent && balance.recent.length) {
        html +=
          '<div class="sutore-mp-merchant-profile__payouts"><h3>' +
          esc(t('recentPayouts', 'Recent payouts')) +
          '</h3><table class="shop_table shop_table_responsive"><thead><tr>' +
          '<th>' + esc(t('product', 'Product')) + '</th>' +
          '<th>' + esc(t('listing', 'Listing')) + '</th>' +
          '<th>' + esc(t('net', 'Net')) + '</th>' +
          '<th>' + esc(t('payment', 'Payment')) + '</th>' +
          '</tr></thead><tbody>';
        balance.recent.forEach(function (line) {
          var statusText = line.scheduled_message || '';
          if (!statusText && line.payout_status === 'paid') {
            statusText = line.paid_at_display
              ? (line.payout_status_label || t('paidPayout', 'Paid payout')) +
                ' · ' +
                line.paid_at_display
              : (line.payout_status_label || t('paidPayout', 'Paid payout'));
          }
          if (!statusText) {
            statusText = line.payout_status_label || line.payout_status || '';
          }
          html +=
            '<tr><td>' + esc(line.product_title || '') + '</td><td>#' +
            esc(String(line.variation_id || 0)) +
            '</td><td>' + esc(line.formatted_net || '') + '</td><td>' +
            esc(statusText) + '</td></tr>';
        });
        html += '</tbody></table></div>';
      }

      if (profileData.tc_verified) {
        html +=
          '<p class="sutore-mp-merchant-profile__verified">' +
          esc(t('tcVerified', 'Your TC identity has been verified — Confirmed seller level')) +
          '</p>';
      }
      html += '</section>';
    } else if (profileData.tc_verified) {
      html +=
        '<p class="sutore-mp-merchant-profile__verified">' +
        esc(t('tcVerified', 'Your TC identity has been verified — Confirmed seller level')) +
        '</p>';
    }
    return html;
  }

  function renderReferral(profileData) {
    var referral = profileData.referral || {};
    if (!referral.code) {
      return '';
    }
    return (
      '<div class="sutore-mp-merchant-profile__referral">' +
      '<h3>' +
      esc(t('inviteSellers', 'Invite sellers')) +
      '</h3>' +
      '<p class="sutore-mp-merchant-profile__referral-code"><span class="sutore-mp-merchant-profile__stat-label">' +
      esc(t('yourInviteCode', 'Your invite code')) +
      '</span><strong>' +
      esc(referral.code) +
      '</strong></p>' +
      (referral.link
        ? '<p class="sutore-mp-merchant-profile__referral-link"><input type="text" readonly class="sutore-mp-input" value="' +
          esc(referral.link) +
          '" /><button type="button" class="wp-element-button is-style-outline sutore-mp-copy-referral" data-copy="' +
          esc(referral.link) +
          '">' +
          esc(t('copyLink', 'Copy link')) +
          '</button></p>'
        : '') +
      '</div>'
    );
  }

  function renderBilling(profileData) {
    var p = profileData.profile || {};
    var verified = !!profileData.tc_verified;
    var yearMax = profileData.birth_year_max || new Date().getUTCFullYear();
    var html =
      '<section class="sutore-mp-merchant-profile__section"><h2>' +
      esc(t('billing', 'Billing')) + '</h2>';

    if (profileData.intro) {
      html += '<p class="sutore-mp-merchant-profile__intro">' + esc(profileData.intro) + '</p>';
    }

    html +=
      '<form id="sutore-merchant-profile-form" class="woocommerce-EditAccountForm edit-account sutore-mp-merchant-profile__form" autocomplete="off">' +
      fieldRow('account_name', t('accountName', 'Account Holder First Name'), 'text', {
        required: true,
        pattern: '[a-zA-ZığüşöçİĞÜŞÖÇ ]+'
      }, 'form-row-first') +
      fieldRow('account_lastname', t('accountLastname', 'Account Holder Last Name'), 'text', {
        required: true,
        pattern: '[a-zA-ZığüşöçİĞÜŞÖÇ ]+'
      }, 'form-row-last') +
      fieldRow('account_iban', t('iban', 'IBAN'), 'text', {
        required: true,
        pattern: '[a-zA-Z0-9-]+'
      }, 'form-row-first') +
      fieldRow('account_tckno', t('tc', 'TC Identity Number'), 'text', {
        required: true,
        pattern: '[0-9]{11}',
        inputmode: 'numeric',
        maxlength: '11',
        readonly: verified
      }, 'form-row-last') +
      fieldRow('account_birth_year', t('birthYear', 'Year of Birth'), 'number', {
        required: true,
        min: '1900',
        max: String(yearMax),
        readonly: verified
      }, 'form-row-first') +
      fieldRow('account_email', t('email', 'Email Address'), 'email', { required: true }, 'form-row-last') +
      fieldRow('account_phone', t('phone', 'Phone Number'), 'tel', {
        required: true,
        pattern: '[0-9]{10,11}',
        inputmode: 'numeric'
      }, 'form-row-wide') +
      fieldRow('account_city', t('city', 'City'), 'select', { required: true }, 'form-row-first') +
      fieldRow('account_state', t('district', 'District'), 'select', { required: true }, 'form-row-last');

    if (profileData.referral && profileData.referral.can_enter_code) {
      html += fieldRow('invite_code', t('inviteCode', 'Invite code (optional)'), 'text', {
        maxlength: '16',
        autocomplete: 'off'
      }, 'form-row-wide');
    }

    html +=
      fieldRow('current_password', t('currentPassword', 'Your current password'), 'password', {
        required: true,
        autocomplete: 'current-password'
      }, 'form-row-wide');

    if (profileData.note) {
      html += '<p class="sutore-mp-merchant-profile__note">' + esc(profileData.note) + '</p>';
    }

    html +=
      '<p class="form-row"><button type="submit" class="woocommerce-Button button wp-element-button">' +
      esc(profileData.submit_label || t('saveInfo', 'Save My Info')) +
      '</button></p>' +
      '<div class="sutore-mp-merchant-profile__feedback" aria-live="polite"></div></form></section>';

    return html;
  }

  function fillForm(profileData) {
    var p = profileData.profile || {};
    $('#account_name').val(p.account_name || '');
    $('#account_lastname').val(p.account_lastname || '');
    $('#account_iban').val(p.account_iban || 'TR');
    $('#account_tckno').val(p.account_tckno || '');
    $('#account_birth_year').val(p.account_birth_year || '');
    $('#account_email').val(p.account_email || '');
    $('#account_phone').val(p.account_phone || '');

    var $city = $('#account_city').empty().append($('<option>').val('').text(t('pickDistrict', 'Select')));
    (profileData.cities || []).forEach(function (city) {
      var $opt = $('<option>').val(city.code).text(city.label);
      if (p.account_city && p.account_city === city.code) {
        $opt.prop('selected', true);
      }
      $city.append($opt);
    });

    $('#account_state').empty().append($('<option>').val('').text(t('pickDistrict', 'Select')));
    if (p.account_city) {
      loadDistricts(p.account_city, p.account_state || '');
    }

    if ($('#invite_code').length) {
      var fromUrl = '';
      try {
        fromUrl = String(new URLSearchParams(window.location.search).get('ref') || '').trim();
      } catch (e) {
        fromUrl = '';
      }
      $('#invite_code').val(fromUrl);
    }
  }

  function bindForm() {
    var $form = $('#sutore-merchant-profile-form');
    if (!$form.length) {
      return;
    }

    $('#account_city').off('change.sutore').on('change.sutore', function () {
      loadDistricts($(this).val(), '');
    });

    var $feedback = $form.find('.sutore-mp-merchant-profile__feedback');
    $form.off('submit.sutore').on('submit.sutore', function (event) {
      event.preventDefault();

      var payload = {};
      $form.serializeArray().forEach(function (pair) {
        payload[pair.name] = pair.value;
      });

      $form.addClass('is-loading');
      feedback($feedback, t('otpSending', 'Sending verification code…'), 'success');

      cfg.withOtp('merchant_profile', payload, function (completePayload) {
        return cfg.api('marketplace_merchant_profile_save', completePayload);
      }).done(function (res) {
        if (!res || !res.success) {
          feedback($feedback, (res && res.data && res.data.message) || t('error', 'Error'), 'error');
          return;
        }

        feedback($feedback, res.data.message || t('profileSaved', 'Saved'), 'success');

        if (res.data.reload) {
          window.setTimeout(function () {
            bootProfile($('.sutore-mp-merchant-profile'));
          }, 800);
        }
      }).always(function () {
        $form.removeClass('is-loading');
      });
    });
  }

  function bootProfile($root) {
    if (!$root.length || !cfg.api) {
      return;
    }

    var $mount = $root.find('.sutore-mp-merchant-profile__root');
    if (!$mount.length) {
      return;
    }

    $root.attr('aria-busy', 'true');
    $mount.attr('aria-busy', 'true').html(loadingHtml());

    $.when(
      cfg.api('marketplace_merchant_profile_get'),
      cfg.api('marketplace_merchant_balance_get')
    ).done(function (res, balRes) {
      if (!res || !res.success || !res.data) {
        $root.attr('aria-busy', 'false');
        $mount.attr('aria-busy', 'false').html(
          '<p class="sutore-mp-error">' + esc((res && res.message) || t('error', 'Error')) + '</p>'
        );
        return;
      }

      var profileData = res.data;
      var balance = profileData.can_view_dashboard && balRes && balRes.success ? balRes.data : null;
      var html = renderSummary(profileData, balance) + renderBilling(profileData);
      $mount.attr('aria-busy', 'false').html(html);
      fillForm(profileData);
      bindForm();
      $root.attr('aria-busy', 'false');
    }).fail(function (xhr) {
      var msg = t('error', 'Error');
      if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
        msg = xhr.responseJSON.message;
      }
      $root.attr('aria-busy', 'false');
      $mount.attr('aria-busy', 'false').html(
        '<p class="sutore-mp-error">' + esc(msg) + '</p>'
      );
    });
  }

  $(document).on('click', '.sutore-mp-copy-referral', function () {
    var value = String($(this).attr('data-copy') || '');
    if (!value || !navigator.clipboard || !navigator.clipboard.writeText) {
      return;
    }
    navigator.clipboard.writeText(value);
  });

  $(function () {
    bootProfile($('.sutore-mp-merchant-profile[data-rest-boot="1"]'));
  });
}(jQuery));
