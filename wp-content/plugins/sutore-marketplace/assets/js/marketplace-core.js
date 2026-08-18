(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplace || {};

  cfg.t = function (key, fallback) {
    return (cfg.i18n && cfg.i18n[key]) || fallback;
  };

  function normalizeResponse(res) {
    if (!res) {
      return { success: false, data: { message: cfg.t('emptyResponse', 'Empty response') } };
    }
    if (typeof res.success === 'boolean') {
      if (!res.success && res.message && !res.data) {
        return { success: false, data: { message: res.message, code: res.code } };
      }
      return res;
    }
    if (res.code && res.message) {
      return { success: false, data: { message: res.message, code: res.code } };
    }
    return { success: true, data: res };
  }

  var REST_ACTIONS = {
    marketplace_search_parent_products: function (data) {
      return { method: 'GET', path: 'search-parents', query: data };
    },
    marketplace_listing_sizes: function (data) {
      return { method: 'GET', path: 'sizes/' + data.parent_product_id };
    },
    marketplace_listing_form_context: function (data) {
      return { method: 'GET', path: 'form-context', query: data };
    },
    marketplace_listing_get: function (data) {
      return { method: 'GET', path: 'listings/' + data.variation_id };
    },
    marketplace_listing_create: function (data) {
      var body = Object.assign({}, data || {});
      delete body.variation_id;
      return { method: 'POST', path: 'listings', body: body };
    },
    marketplace_listing_update: function (data) {
      var body = Object.assign({}, data || {});
      var id = body.variation_id;
      delete body.variation_id;
      return { method: 'PUT', path: 'listings/' + id, body: body };
    },
    marketplace_listing_delete: function (data) {
      return { method: 'DELETE', path: 'listings/' + data.variation_id };
    },
    marketplace_listing_put_on_sale: function (data) {
      return { method: 'POST', path: 'listings/' + data.variation_id + '/put-on-sale', body: {} };
    },
    marketplace_listing_remove_from_sale: function (data) {
      return {
        method: 'POST',
        path: 'listings/' + data.variation_id + '/remove-from-sale',
        body: { staff_note: data.staff_note || '' }
      };
    },
    marketplace_listings_query: function (data) {
      return { method: 'GET', path: 'listings', query: data };
    },
    marketplace_listing_bulk_validate: function (data) {
      return { method: 'POST', path: 'listings/bulk/validate', body: data };
    },
    marketplace_listing_bulk_update_row: function (data) {
      return { method: 'POST', path: 'listings/bulk/update-row', body: data };
    },
    marketplace_listing_bulk_delete_row: function (data) {
      return { method: 'POST', path: 'listings/bulk/delete-row', body: data };
    },
    marketplace_listing_bulk_commit: function (data) {
      return { method: 'POST', path: 'listings/bulk/commit', body: data };
    },
    marketplace_listing_bulk_actions: function (data) {
      return { method: 'POST', path: 'listings/bulk-actions', body: data };
    },
    marketplace_sourcing_query: function (data) {
      return { method: 'GET', path: 'sourcing', query: data };
    },
    marketplace_sourcing_get: function (data) {
      return { method: 'GET', path: 'sourcing/' + data.request_id };
    },
    marketplace_sourcing_accept: function (data) {
      var body = Object.assign({}, data || {});
      var id = body.request_id;
      delete body.request_id;
      return { method: 'POST', path: 'sourcing/' + id + '/accept', body: body };
    },
    marketplace_notifications_list: function (data) {
      return { method: 'GET', path: 'notifications', query: data };
    },
    marketplace_notifications_mark_read: function (data) {
      return { method: 'POST', path: 'notifications/' + data.notification_id + '/read', body: {} };
    },
    marketplace_notifications_mark_all_read: function () {
      return { method: 'POST', path: 'notifications/read-all', body: {} };
    },
    marketplace_tasks_dashboard: function () {
      return { method: 'GET', path: 'tasks/dashboard' };
    },
    marketplace_merchant_districts: function (data) {
      return { method: 'GET', path: 'merchant/districts', query: data };
    },
    marketplace_merchant_profile_get: function () {
      return { method: 'GET', path: 'merchant/profile' };
    },
    marketplace_merchant_profile_save: function (data) {
      return { method: 'PUT', path: 'merchant/profile', body: data };
    },
    marketplace_merchant_balance_get: function () {
      return { method: 'GET', path: 'merchant/balance' };
    },
    marketplace_otp_request: function (data) {
      var body = Object.assign({}, data || {});
      return { method: 'POST', path: 'otp/request', body: body };
    },
    marketplace_account_details_save: function (data) {
      return { method: 'PUT', path: 'account/details', body: data };
    },
    marketplace_account_password_save: function (data) {
      return { method: 'PUT', path: 'account/password', body: data };
    },
    marketplace_account_delete: function (data) {
      return { method: 'DELETE', path: 'account', body: data };
    },
    marketplace_fulfillment_details: function (data) {
      return { method: 'GET', path: 'fulfillments/' + data.variation_id };
    },
    marketplace_fulfillment_confirm: function (data) {
      return { method: 'POST', path: 'fulfillments/' + data.variation_id + '/confirm', body: {} };
    },
    marketplace_fulfillment_ship: function (data) {
      return {
        method: 'POST',
        path: 'fulfillments/' + data.variation_id + '/ship',
        body: { shipment_code: data.shipment_code }
      };
    },
    marketplace_campaign_offers_query: function (data) {
      return { method: 'GET', path: 'campaign-offers', query: data };
    },
    marketplace_campaign_offer_accept: function (data) {
      return { method: 'POST', path: 'campaign-offers/' + data.offer_id + '/accept', body: {} };
    },
    marketplace_campaign_offer_decline: function (data) {
      return { method: 'POST', path: 'campaign-offers/' + data.offer_id + '/decline', body: {} };
    },
    marketplace_price_offers_query: function (data) {
      return { method: 'GET', path: 'price-offers', query: data };
    },
    marketplace_price_offer_accept: function (data) {
      return { method: 'POST', path: 'price-offers/' + data.offer_id + '/accept', body: {} };
    },
    marketplace_price_offer_decline: function (data) {
      return { method: 'POST', path: 'price-offers/' + data.offer_id + '/decline', body: {} };
    },
    marketplace_my_offers_query: function (data) {
      return { method: 'GET', path: 'my-offers', query: data };
    },
    marketplace_my_offer_cancel: function (data) {
      return { method: 'POST', path: 'my-offers/' + data.offer_id + '/cancel', body: {} };
    },
    marketplace_outlet_query: function () {
      return { method: 'GET', path: 'outlet' };
    },
    marketplace_outlet_opt_in: function (data) {
      return { method: 'POST', path: 'outlet/' + data.item_id + '/opt-in', body: {} };
    },
    marketplace_outlet_cancel: function (data) {
      return { method: 'POST', path: 'outlet/optins/' + data.optin_id + '/cancel', body: {} };
    },
    marketplace_listing_start_campaign: function (data) {
      return {
        method: 'POST',
        path: 'listings/' + data.variation_id + '/campaign',
        body: {
          percent: data.percent,
          duration_days: data.duration_days
        }
      };
    },
    marketplace_catalog_request_create: function (data) {
      return { method: 'POST', path: 'catalog-product-requests', body: data };
    },
    marketplace_catalog_requests_query: function (data) {
      return { method: 'GET', path: 'catalog-product-requests', query: data };
    },
    marketplace_catalog_request_cancel: function (data) {
      return { method: 'POST', path: 'catalog-product-requests/' + data.id + '/cancel', body: {} };
    },
    marketplace_admin_catalog_requests: function (data) {
      return { method: 'GET', path: 'admin/catalog-product-requests', query: data };
    },
    marketplace_admin_catalog_request_fulfill: function (data) {
      var body = Object.assign({}, data || {});
      var id = body.id;
      delete body.id;
      return { method: 'POST', path: 'admin/catalog-product-requests/' + id + '/fulfill', body: body };
    },
    marketplace_admin_catalog_request_reject: function (data) {
      var body = Object.assign({}, data || {});
      var id = body.id;
      delete body.id;
      return { method: 'POST', path: 'admin/catalog-product-requests/' + id + '/reject', body: body };
    }
  };

  cfg.restRequest = function (action, data) {
    var route = REST_ACTIONS[action];
    if (!route || !cfg.restUrl) {
      return null;
    }

    var spec = route(data || {});
    var url = cfg.restUrl + spec.path;
    var ajaxOpts = {
      url: url,
      method: spec.method,
      dataType: 'json',
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    };

    if (spec.method === 'GET') {
      if (spec.query) {
        ajaxOpts.data = spec.query;
      }
    } else if (spec.body) {
      ajaxOpts.contentType = 'application/json';
      ajaxOpts.data = JSON.stringify(spec.body);
    }

    var d = $.Deferred();
    var req = $.ajax(ajaxOpts);
    req.done(function (res) {
      d.resolve(normalizeResponse(res));
    }).fail(function (xhr) {
      var msg = cfg.t('error', 'Error');
      try {
        if (xhr && xhr.responseJSON) {
          d.resolve(normalizeResponse(xhr.responseJSON));
          return;
        }
        if (xhr && xhr.responseText) {
          msg = 'HTTP ' + (xhr.status || 0) + ': ' + String(xhr.responseText).slice(0, 200);
        }
      } catch (e) {}
      d.resolve({ success: false, data: { message: msg } });
    });

    d.abort = function () { if (req && req.abort) req.abort(); };
    return d.promise(req);
  };

  cfg.api = function (action, data) {
    var restReq = cfg.restRequest(action, data);
    if (restReq) {
      return restReq;
    }

    var d = $.Deferred();
    d.resolve({
      success: false,
      data: { message: cfg.t('restRouteMissing', 'REST route missing') + ': ' + action }
    });
    return d.promise();
  };

  cfg.thumbBox = function (boxClass, imgClass, src, alt) {
    var $box = $('<div class="sutore-mp-thumb-box"/>').addClass(boxClass);
    if (src) {
      $box.append(
        $('<img/>')
          .addClass(imgClass)
          .attr('src', src)
          .attr('alt', alt || '')
      );
    } else {
      $box.addClass('is-empty');
    }
    return $box;
  };

  cfg.showConfirm = function (title, text, confirmLabel, onConfirm) {
    var t = cfg.t;
    $('.sutore-mp-confirm').remove();
    var $modal = $('<div class="sutore-mp-confirm"/>');
    $modal.append(
      $('<div class="sutore-mp-confirm-card"/>')
        .append($('<div class="sutore-mp-confirm-head"/>').append($('<strong/>').text(title)).append($('<button type="button" class="sutore-mp-confirm-x"/>').text('×')))
        .append($('<div class="sutore-mp-confirm-body"/>').text(text))
        .append(
          $('<div class="sutore-mp-confirm-actions"/>')
            .append($('<button type="button" class="wp-element-button is-style-outline sutore-mp-confirm-cancel"/>').text(t('cancel', 'Cancel')))
            .append($('<button type="button" class="wp-element-button sutore-mp-confirm-ok"/>').text(confirmLabel || t('yes', 'Yes')))
        )
    );
    $('body').append($modal);
    $modal.on('click', '.sutore-mp-confirm-cancel, .sutore-mp-confirm-x', function () { $modal.remove(); });
    $modal.on('click', '.sutore-mp-confirm-ok', function () { $modal.remove(); onConfirm && onConfirm(); });
  };

  /**
   * Confirm dialog with one or more input fields.
   * @param {{
   *   title: string,
   *   text?: string,
   *   confirmLabel?: string,
   *   fields: Array<{name:string,label:string,type?:string,required?:boolean,value?:string,placeholder?:string,inputmode?:string}>,
   *   onConfirm: function(Object<string,string>): (boolean|void),
   *   onReady?: function(JQuery, Object<string,JQuery>): void
   * }} opts
   */
  cfg.showFormConfirm = function (opts) {
    var t = cfg.t;
    opts = opts || {};
    var fields = Array.isArray(opts.fields) ? opts.fields : [];
    $('.sutore-mp-confirm').remove();

    var $modal = $('<div class="sutore-mp-confirm sutore-mp-form-confirm"/>');
    var $body = $('<div class="sutore-mp-confirm-body"/>');
    if (opts.text) {
      $body.append($('<p class="sutore-mp-confirm-text"/>').text(opts.text));
    }
    var $fields = $('<div class="sutore-mp-confirm-fields"/>');
    var $inputs = {};
    fields.forEach(function (field) {
      var name = String(field.name || '');
      if (!name) {
        return;
      }
      var $field = $('<label class="sutore-mp-confirm-field"/>');
      var $input;
      if (field.type === 'checkbox') {
        $field.addClass('is-checkbox');
        $input = $('<input type="checkbox"/>');
        if (field.checked !== false && field.checked !== 0 && field.checked !== '0') {
          $input.prop('checked', true);
        }
        $field.append($input);
        $field.append($('<span class="sutore-mp-field-label"/>').text(field.label || name));
      } else {
        $field.append($('<span class="sutore-mp-field-label"/>').text(field.label || name));
        if (field.type === 'select') {
          $input = $('<select class="sutore-mp-input"/>');
          $input.append($('<option value=""/>').text(field.placeholder || '—'));
          (field.options || []).forEach(function (opt) {
            var $opt = $('<option/>')
              .attr('value', String(opt.value))
              .text(opt.label || opt.value);
            if (opt.same_parent != null) {
              $opt.attr('data-same-parent', opt.same_parent ? '1' : '0');
            }
            if (opt.parent_product_id != null) {
              $opt.attr('data-parent-product-id', String(opt.parent_product_id));
            }
            $input.append($opt);
          });
        } else if (field.type === 'textarea') {
          $input = $('<textarea class="sutore-mp-input" rows="3"/>');
        } else if (field.type === 'product_search') {
          $input = $('<input type="hidden"/>');
          var $search = $('<input type="search" class="sutore-mp-input sutore-mp-confirm-product-search" autocomplete="off"/>');
          if (field.placeholder) {
            $search.attr('placeholder', field.placeholder);
          }
          var $results = $('<div class="sutore-mp-confirm-product-results" hidden/>');
          var $picked = $('<p class="sutore-mp-confirm-product-chosen" hidden/>');
          $field.append($search);
          $field.append($results);
          $field.append($picked);
          var searchTimer = null;
          var searchSeq = 0;
          function hideProductResults() {
            $results.empty().prop('hidden', true);
          }
          function pickProduct(item) {
            var id = item && item.id ? String(item.id) : '';
            var title = (item && item.title) || '';
            var code = item && item.product_code ? ' (' + item.product_code + ')' : '';
            $input.val(id);
            $search.val(title + code);
            if (id) {
              $picked.text(title + code).prop('hidden', false);
            } else {
              $picked.prop('hidden', true).text('');
            }
            hideProductResults();
          }
          $search.on('input', function () {
            var term = $.trim($search.val() || '');
            if (term === '') {
              $input.val('');
              $picked.prop('hidden', true).text('');
            }
            if (searchTimer) {
              window.clearTimeout(searchTimer);
            }
            searchTimer = window.setTimeout(function () {
              var my = ++searchSeq;
              if (term.length < 2) {
                hideProductResults();
                return;
              }
              cfg.api('marketplace_search_parent_products', { product_code: term }).done(function (res) {
                if (my !== searchSeq) {
                  return;
                }
                $results.empty();
                var items = res && res.success && res.data && res.data.items ? res.data.items : [];
                if (!items.length) {
                  $results.append(
                    $('<p class="sutore-mp-search-empty"/>').text(t('noMatchingProducts', 'No matching products.'))
                  );
                  $results.prop('hidden', false);
                  return;
                }
                items.forEach(function (item) {
                  var label = (item.title || '') + (item.product_code ? ' (' + item.product_code + ')' : '');
                  $results.append(
                    $('<button type="button" class="sutore-mp-pick-row"/>')
                      .text(label)
                      .on('click', function () {
                        pickProduct(item);
                      })
                  );
                });
                $results.prop('hidden', false);
              });
            }, 280);
          });
        } else {
          $input = $('<input type="text" class="sutore-mp-input"/>');
        }
        if (field.placeholder && field.type !== 'select' && field.type !== 'product_search') {
          $input.attr('placeholder', field.placeholder);
        }
        if (field.inputmode) {
          $input.attr('inputmode', field.inputmode);
        }
        if (field.value != null && field.value !== '') {
          $input.val(String(field.value));
        }
        $input.attr('name', name);
        if (field.required) {
          $input.attr('aria-required', 'true');
        }
        $field.append($input);
      }
      if (field.type === 'checkbox') {
        $input.attr('name', name);
      }
      $fields.append($field);
      $inputs[name] = $input;
    });
    $body.append($fields);
    var $error = $('<p class="sutore-mp-confirm-error" aria-live="polite"/>');
    $body.append($error);

    $modal.append(
      $('<div class="sutore-mp-confirm-card"/>')
        .append(
          $('<div class="sutore-mp-confirm-head"/>')
            .append($('<strong/>').text(opts.title || ''))
            .append(
              $('<button type="button" class="sutore-mp-confirm-x" aria-label="' + t('close', 'Close') + '"/>').text('×')
            )
        )
        .append($body)
        .append(
          $('<div class="sutore-mp-confirm-actions"/>')
            .append(
              $('<button type="button" class="wp-element-button is-style-outline sutore-mp-confirm-cancel"/>').text(
                t('cancel', 'Cancel')
              )
            )
            .append(
              $('<button type="button" class="wp-element-button sutore-mp-confirm-ok"/>').text(
                opts.confirmLabel || t('yes', 'Yes')
              )
            )
        )
    );
    $('body').append($modal);

    if (typeof opts.onReady === 'function') {
      opts.onReady($modal, $inputs);
    }

    var first = fields[0] && $inputs[fields[0].name];
    if (fields[0] && fields[0].type === 'product_search') {
      window.setTimeout(function () {
        $modal.find('.sutore-mp-confirm-product-search').trigger('focus');
      }, 0);
    } else if (first) {
      window.setTimeout(function () {
        first.trigger('focus');
      }, 0);
    }

    function close() {
      $modal.remove();
    }

    $modal.on('click', '.sutore-mp-confirm-cancel, .sutore-mp-confirm-x', close);
    $modal.on('click', '.sutore-mp-confirm-ok', function () {
      var $okBtn = $(this);
      if ($okBtn.prop('disabled') || $okBtn.hasClass('is-loading')) {
        return;
      }
      var values = {};
      var missing = null;
      fields.forEach(function (field) {
        var name = String(field.name || '');
        if (!name || !$inputs[name]) {
          return;
        }
        if (field.type === 'checkbox') {
          values[name] = $inputs[name].prop('checked') ? '1' : '0';
          return;
        }
        var val = String($inputs[name].val() || '').trim();
        values[name] = val;
        if (field.required && !val && !missing) {
          missing = field.label || name;
        }
      });
      if (missing) {
        $error.text(opts.requiredMessage || t('error', 'Error'));
        return;
      }
      $error.text('');
      var ui = {
        setError: function (msg) {
          $error.text(msg || '');
        },
        close: close,
        setBusy: function (busy) {
          var on = !!busy;
          $okBtn.prop('disabled', on).toggleClass('is-loading', on);
          $modal.find('.sutore-mp-confirm-cancel, .sutore-mp-confirm-x').prop('disabled', on);
        }
      };
      var keepOpen = opts.onConfirm && opts.onConfirm(values, ui) === false;
      if (!keepOpen) {
        close();
      }
    });
  };

  cfg.showAlert = function (title, text, okLabel, onClose) {
    var t = cfg.t;
    $('.sutore-mp-confirm').remove();
    var $modal = $('<div class="sutore-mp-confirm sutore-mp-alert"/>');
    $modal.append(
      $('<div class="sutore-mp-confirm-card"/>')
        .append(
          $('<div class="sutore-mp-confirm-head"/>')
            .append($('<strong/>').text(title))
            .append($('<button type="button" class="sutore-mp-confirm-x" aria-label="' + (t('close', 'Close')) + '"/>').text('×'))
        )
        .append($('<div class="sutore-mp-confirm-body"/>').text(text))
        .append(
          $('<div class="sutore-mp-confirm-actions"/>')
            .append(
              $('<button type="button" class="wp-element-button sutore-mp-confirm-ok"/>').text(
                okLabel || t('ok', 'OK')
              )
            )
        )
    );
    $('body').append($modal);
    var close = function () {
      $modal.remove();
      if (typeof onClose === 'function') {
        onClose();
      }
    };
    $modal.on('click', '.sutore-mp-confirm-ok, .sutore-mp-confirm-x', close);
  };

  cfg.showToast = function (message, type) {
    var text = message == null ? '' : String(message);
    if (!text) {
      return;
    }
    var kind = type === 'error' ? 'error' : 'success';
    var $host = $('.sutore-mp-toast-host');
    if (!$host.length) {
      $host = $('<div class="sutore-mp-toast-host" aria-live="polite"/>').appendTo('body');
    }
    var $toast = $('<div class="sutore-mp-toast"/>')
      .addClass(kind === 'error' ? 'is-error' : 'is-success')
      .text(text);
    $host.append($toast);
    window.requestAnimationFrame(function () {
      $toast.addClass('is-visible');
    });
    window.setTimeout(function () {
      $toast.removeClass('is-visible');
      window.setTimeout(function () {
        $toast.remove();
      }, 260);
    }, 3200);
  };

  /**
   * Two-step OTP flow: request code, prompt user, then run submitAction with otp_code.
   * @param {string} purpose
   * @param {object} payload
   * @param {function(object): PromiseLike} submitAction
   * @returns {PromiseLike}
   */
  cfg.withOtp = function (purpose, payload, submitAction) {
    var t = cfg.t;
    var d = $.Deferred();

    if (!cfg.otpEnabled) {
      submitAction(payload).done(function (res) { d.resolve(res); }).fail(function (xhr) {
        d.resolve({ success: false, data: { message: t('error', 'Error') } });
      });
      return d.promise();
    }

    var requestPayload = Object.assign({ purpose: purpose }, payload || {});
    cfg.api('marketplace_otp_request', requestPayload).done(function (res) {
      if (!res || !res.success) {
        d.resolve(res || { success: false, data: { message: t('error', 'Error') } });
        return;
      }

      var timerSec = cfg.otpUiTimer || 120;
      var masked = (res.data && res.data.masked_phone) || '';
      var lead = masked
        ? (t('otpMaskedPhone', 'Code sent to %s').replace('%s', masked))
        : '';

      $('.sutore-mp-otp').remove();
      var $modal = $('<div class="sutore-mp-otp sutore-mp-confirm"/>');
      var $input = $('<input type="text" class="input-text sutore-mp-otp-input" inputmode="numeric" autocomplete="one-time-code" />')
        .attr('placeholder', t('otpPlaceholder', 'Verification code'));
      var $error = $('<p class="sutore-mp-otp-error" aria-live="polite"/>');
      var $timer = $('<strong class="sutore-mp-otp-timer"/>').text(String(timerSec));
      var tick = timerSec;
      var timerId = window.setInterval(function () {
        tick -= 1;
        $timer.text(String(Math.max(0, tick)));
        if (tick <= 0) {
          window.clearInterval(timerId);
        }
      }, 1000);

      function closeModal() {
        window.clearInterval(timerId);
        $modal.remove();
      }

      var $body = $('<div class="sutore-mp-confirm-body"/>');
      $body.append(
        $('<p class="sutore-mp-otp-prompt"/>').append(
          document.createTextNode(t('otpPromptPrefix', 'Enter the verification code sent to your phone. Time remaining: ')),
          $timer,
          document.createTextNode(' ' + t('otpSecondsSuffix', 'sec.'))
        )
      );
      if (lead) {
        $body.append($('<p class="sutore-mp-otp-lead"/>').text(lead));
      }
      $body.append($('<p class="sutore-mp-otp-field"/>').append($input)).append($error);

      var debugCode = res.data && res.data.debug_code ? String(res.data.debug_code) : '';
      if (debugCode) {
        $body.append(
          $('<p class="sutore-mp-otp-debug"/>').append(
            $('<span class="sutore-mp-otp-debug-label"/>').text(t('otpDebugLabel', 'Test code (simulation):') + ' '),
            $('<strong class="sutore-mp-otp-debug-code"/>').text(debugCode)
          )
        );
        $input.val(debugCode);
      }

      $modal.append(
        $('<div class="sutore-mp-confirm-card"/>')
          .append(
            $('<div class="sutore-mp-confirm-head"/>')
              .append($('<strong/>').text(t('otpTitle', 'SMS verification')))
              .append($('<button type="button" class="sutore-mp-confirm-x"/>').text('×'))
          )
          .append($body)
          .append(
            $('<div class="sutore-mp-confirm-actions"/>')
              .append($('<button type="button" class="wp-element-button is-style-outline sutore-mp-confirm-cancel"/>').text(t('cancel', 'Cancel')))
              .append($('<button type="button" class="wp-element-button sutore-mp-otp-submit"/>').text(t('otpConfirm', 'Verify')))
          )
      );

      $('body').append($modal);
      $input.trigger('focus');

      $modal.on('click', '.sutore-mp-confirm-cancel, .sutore-mp-confirm-x', function () {
        closeModal();
        d.resolve({ success: false, data: { message: t('cancel', 'Cancel') } });
      });

      $modal.on('click', '.sutore-mp-otp-submit', function () {
        var code = $.trim($input.val());
        if (!code) {
          $error.text(t('otpPlaceholder', 'Verification code'));
          return;
        }

        $error.text('');
        var completePayload = Object.assign({}, payload, { otp_code: code });
        submitAction(completePayload).done(function (saveRes) {
          if (saveRes && saveRes.success) {
            closeModal();
            d.resolve(saveRes);
            return;
          }
          $error.text((saveRes && saveRes.data && saveRes.data.message) || t('error', 'Error'));
        });
      });
    });

    return d.promise();
  };

  var LIST_SHELL =
    '.sutore-mp-listings, .sutore-mp-staff-manage, .sutore-mp-staff-merchants, .sutore-mp-campaign-offers, .sutore-mp-price-offers, .sutore-mp-my-offers, .sutore-mp-outlet, .sutore-mp-sourcing, .sutore-mp-staff-orders, .sutore-mp-staff-catalog-requests';

  function $listShell($from) {
    return $from.closest(LIST_SHELL);
  }

  function openListOverlay($shell, selector) {
    if (!$shell.length) {
      return;
    }
    $shell.find('.sutore-mp-filter-overlay, .sutore-mp-sort-overlay').not(selector).prop('hidden', true);
    $shell.find(selector).prop('hidden', false);
    $('body').addClass('sutore-mp-modal-open');
  }

  function closeListOverlays($shell) {
    if (!$shell.length) {
      return;
    }
    $shell.find('.sutore-mp-filter-overlay, .sutore-mp-sort-overlay').prop('hidden', true);
    if (
      !document.querySelector(
        '.sutore-mp-filter-overlay:not([hidden]), .sutore-mp-sort-overlay:not([hidden]), .sutore-mp-manage-overlay:not([hidden]), .sutore-mp-offer-overlay:not([hidden]), .sutore-mp-sourcing-overlay:not([hidden])'
      )
    ) {
      $('body').removeClass('sutore-mp-modal-open');
    }
  }

  cfg.listShellSelector = LIST_SHELL;
  cfg.listShell = $listShell;
  cfg.openListOverlay = openListOverlay;
  cfg.closeListOverlays = closeListOverlays;
  cfg.setFilterBadge = function ($shell, count) {
    var $badge = $shell.find('.sutore-mp-filter-badge').first();
    if (count > 0) {
      $badge.text(String(count)).prop('hidden', false);
    } else {
      $badge.text('').prop('hidden', true);
    }
  };
  cfg.setSortBadge = function ($shell, active) {
    var $badge = $shell.find('.sutore-mp-sort-badge').first();
    if (active) {
      $badge.text('•').prop('hidden', false);
    } else {
      $badge.text('').prop('hidden', true);
    }
  };

  $(document).on('click', '.sutore-mp-open-filters', function () {
    openListOverlay($listShell($(this)), '.sutore-mp-filter-overlay');
  });

  $(document).on('click', '.sutore-mp-open-sort', function () {
    openListOverlay($listShell($(this)), '.sutore-mp-sort-overlay');
  });

  $(document).on('click', '.sutore-mp-filter-close', function () {
    closeListOverlays($listShell($(this)));
  });

  $(document).on('click', '.sutore-mp-sort-close', function () {
    closeListOverlays($listShell($(this)));
  });

  $(document).on('click', '.sutore-mp-filter-overlay, .sutore-mp-sort-overlay', function (e) {
    if (e.target === this) {
      closeListOverlays($listShell($(this)));
    }
  });

  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') {
      return;
    }
    var $openFilter = $('.sutore-mp-filter-overlay:not([hidden])').first();
    if ($openFilter.length) {
      closeListOverlays($listShell($openFilter));
      return;
    }
    var $openSort = $('.sutore-mp-sort-overlay:not([hidden])').first();
    if ($openSort.length) {
      closeListOverlays($listShell($openSort));
    }
  });

  window.SutoreMarketplace = cfg;
})(jQuery);
