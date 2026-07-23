(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplaceStaff || {};
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

  function needsStaffNote(actions, action) {
    var required = (actions && actions.requires_staff_note) || [];
    var key = action === 'split' ? 'detach' : action;
    return required.indexOf(key) !== -1;
  }

  /**
   * primary: pipeline next step (list + modal footer).
   * fields / confirm: collected before POST (modal form or confirm dialog).
   */
  var ACTION_DEFS = [
    {
      key: 'confirm_payment',
      value: 'confirm_payment',
      labelKey: 'confirmSale',
      fallback: 'Confirm Sale',
      primary: true,
      confirmTitleKey: 'confirmSale',
      confirmTitleFallback: 'Confirm Sale',
      confirmTextKey: 'confirmPayment',
      confirmTextFallback: 'Are you sure you want to confirm this sale? The seller will be notified.'
    },
    {
      key: 'mark_arrived',
      value: 'mark_arrived',
      labelKey: 'markArrived',
      fallback: 'Arrived at Sutore',
      primary: true
    },
    {
      key: 'mark_verified',
      value: 'mark_verified',
      labelKey: 'markVerified',
      fallback: 'Verify product',
      primary: true
    },
    {
      key: 'mark_ready_to_ship',
      value: 'mark_ready_to_ship',
      labelKey: 'markReady',
      fallback: 'Ready to ship',
      primary: true
    },
    {
      key: 'mark_shipped_to_customer',
      value: 'mark_shipped_to_customer',
      labelKey: 'markShippedCustomer',
      fallback: 'Ship to customer',
      primary: true,
      fields: [
        {
          name: 'sutore_shipment_code',
          labelKey: 'sutoreShippingCode',
          labelFallback: 'Sutore shipping code',
          type: 'text',
          required: true
        }
      ]
    },
    {
      key: 'mark_delivered',
      value: 'mark_delivered',
      labelKey: 'markDelivered',
      fallback: 'Delivered to customer',
      primary: true
    },
    {
      key: 'put_on_sale',
      value: 'put_on_sale',
      labelKey: 'putOnSale',
      fallback: 'Put on sale',
      primary: true
    },
    {
      key: 'mark_payout',
      value: 'mark_payout_paid',
      labelKey: 'markPaid',
      fallback: 'Mark as Paid to Seller',
      primary: false,
      textKey: 'markPaidConfirm',
      textFallback:
        'Record that the seller has been paid for this sale. Optional payment reference is stored on the payout line.',
      fields: [
        {
          name: 'payment_ref',
          labelKey: 'paymentRef',
          labelFallback: 'Payment reference (EFT/receipt)',
          type: 'text',
          required: false
        }
      ]
    },
    {
      key: 'swap',
      value: 'swap',
      labelKey: 'changeSeller',
      fallback: 'Change Seller',
      primary: false,
      fields: [
        {
          name: 'new_listing_id',
          labelKey: 'newListingId',
          labelFallback: 'New listing ID (swap)',
          type: 'text',
          required: true,
          inputmode: 'numeric'
        }
      ]
    },
    {
      key: 'attach_to_order',
      value: 'attach_to_order',
      labelKey: 'attachToOrder',
      fallback: 'Add to order',
      primary: true,
      textKey: 'attachToOrderConfirm',
      textFallback:
        'Add this listing to a processing order and start the sale as sold (awaiting merchant confirmation).',
      fields: [
        {
          name: 'order_id',
          labelKey: 'selectOrder',
          labelFallback: 'Processing order',
          type: 'select',
          required: true,
          optionsKey: 'processing_orders'
        }
      ]
    },
    {
      key: 'detach',
      value: 'split',
      labelKey: 'detach',
      fallback: 'Detach from Order',
      primary: false,
      textKey: 'confirmDetach',
      textFallback: 'Will be unlinked from the order. Continue?'
    },
    {
      key: 'chargeback',
      value: 'chargeback',
      labelKey: 'chargeback',
      fallback: 'Refund / chargeback',
      primary: false,
      textKey: 'chargebackConfirm',
      textFallback: 'This sale will be marked as refunded and any seller payout will be reversed.',
      fields: [
        {
          name: 'staff_note',
          labelKey: 'staffNote',
          labelFallback: 'Staff note (visible to merchant)',
          type: 'textarea',
          required: true,
          placeholderKey: 'staffNotePlaceholder',
          placeholderFallback: 'Explain why this action is taken…'
        }
      ]
    },
    {
      key: 'mark_not_for_sale',
      value: 'mark_not_for_sale',
      labelKey: 'markNotForSale',
      fallback: 'Not for sale',
      primary: false,
      textKey: 'markNotForSaleConfirm',
      textFallback: 'This sale will be taken off the order and the listing will become not for sale.',
      fields: [
        {
          name: 'staff_note',
          labelKey: 'staffNote',
          labelFallback: 'Staff note (visible to merchant)',
          type: 'textarea',
          required: true,
          placeholderKey: 'staffNotePlaceholder',
          placeholderFallback: 'Explain why this action is taken…'
        }
      ]
    },
    {
      key: 'delete',
      value: 'delete_listing',
      labelKey: 'delete',
      fallback: 'Delete',
      primary: false,
      confirmTitleKey: 'delete',
      confirmTitleFallback: 'Delete',
      confirmTextKey: 'confirmDelete',
      confirmTextFallback: 'Are you sure you want to permanently remove this product Listing?'
    }
  ];

  function findActionDef(valueOrKey) {
    var needle = String(valueOrKey || '');
    for (var i = 0; i < ACTION_DEFS.length; i++) {
      if (ACTION_DEFS[i].value === needle || ACTION_DEFS[i].key === needle) {
        return ACTION_DEFS[i];
      }
    }
    return null;
  }

  function availableDefs(actions, primaryOnly) {
    var out = [];
    ACTION_DEFS.forEach(function (def) {
      if (!actions || !actions[def.key]) {
        return;
      }
      if (primaryOnly === true && !def.primary) {
        return;
      }
      if (primaryOnly === false && def.primary) {
        return;
      }
      out.push(def);
    });
    return out;
  }

  function primaryDef(actions) {
    var list = availableDefs(actions, true);
    return list.length ? list[0] : null;
  }

  function actionNeedsForm(def, actions) {
    if (!def) {
      return false;
    }
    if (def.fields && def.fields.length) {
      return true;
    }
    return needsStaffNote(actions, def.value);
  }

  function actionLabel(def) {
    return t(def.labelKey, def.fallback);
  }

  function renderRowActions(item) {
    var actions = item.actions || {};
    var id = item.id;
    var primary = primaryDef(actions);
    var html =
      '<div class="sutore-mp-staff-row-actions">' +
      '<button type="button" class="wp-element-button is-style-outline sutore-mp-staff-open-manage" data-listing-id="' +
      esc(String(id)) +
      '" data-thumbnail="' +
      esc(item.thumbnail || '') +
      '" data-product-title="' +
      esc(item.product_title || '') +
      '" data-parent-product-id="' +
      esc(String(item.parent_product_id || '')) +
      '" data-variation-id="' +
      esc(String(item.variation_id || '')) +
      '" data-status-label="' +
      esc(item.listing_status_label || item.status_label || '') +
      '">' +
      esc(t('detail', 'Detail')) +
      '</button>';

    if (primary) {
      html +=
        '<button type="button" class="wp-element-button sutore-mp-staff-row-action" data-listing-id="' +
        esc(String(id)) +
        '" data-action="' +
        esc(primary.value) +
        '">' +
        esc(actionLabel(primary)) +
        '</button>';
    }

    html += '</div>';
    return html;
  }

  function showConfirm(title, text, confirmLabel, onConfirm) {
    var fn = window.SutoreMarketplace && SutoreMarketplace.showConfirm;
    if (typeof fn === 'function') {
      fn(title, text, confirmLabel, onConfirm);
      return;
    }
    if (window.confirm(text || title)) {
      onConfirm && onConfirm();
    }
  }

  function showFormConfirm(opts) {
    var fn = window.SutoreMarketplace && SutoreMarketplace.showFormConfirm;
    if (typeof fn === 'function') {
      fn(opts);
      return;
    }
    var values = {};
    var fields = opts.fields || [];
    for (var i = 0; i < fields.length; i++) {
      var field = fields[i];
      var raw = window.prompt(field.label || field.name, field.value || '');
      if (raw === null) {
        return;
      }
      values[field.name] = String(raw).trim();
      if (field.required && !values[field.name]) {
        alert(opts.requiredMessage || t('error', 'Error'));
        return;
      }
    }
    if (opts.onConfirm) {
      opts.onConfirm(values);
    }
  }

  function closeMoreMenu($shell) {
    var $more = $shell.find('.sutore-mp-staff-more');
    $more.removeClass('is-open');
    $more.find('.sutore-mp-staff-more-toggle').attr('aria-expanded', 'false');
    $more.find('.sutore-mp-staff-more-menu').prop('hidden', true);
  }

  function hideActionForm($shell) {
    var $form = $shell.find('.sutore-mp-staff-action-form');
    $form.empty().prop('hidden', true).removeData('action');
    $shell.find('.sutore-mp-staff-foot-bar').prop('hidden', false);
  }

  function clearStaffFoot($shell) {
    hideActionForm($shell);
    closeMoreMenu($shell);
    $shell.find('.sutore-mp-staff-foot-primary').empty();
    $shell.find('.sutore-mp-staff-more-menu').empty();
    $shell.find('.sutore-mp-staff-more-toggle').prop('hidden', true);
    $shell.find('.sutore-mp-staff-manage-foot').prop('hidden', true);
    $shell.removeData('manage-detail');
  }

  function renderStaffFoot($shell, data) {
    var actions = (data && data.actions) || {};
    var primary = primaryDef(actions);
    var secondary = availableDefs(actions, false);
    var $foot = $shell.find('.sutore-mp-staff-manage-foot');
    var $primary = $shell.find('.sutore-mp-staff-foot-primary').empty();
    var $toggle = $shell.find('.sutore-mp-staff-more-toggle');
    var $menu = $shell.find('.sutore-mp-staff-more-menu').empty();

    hideActionForm($shell);
    closeMoreMenu($shell);
    $shell.data('manage-detail', data || null);

    if (primary) {
      $primary.append(
        $('<button type="button" class="wp-element-button sutore-mp-staff-foot-primary-btn"/>')
          .attr('data-action', primary.value)
          .text(actionLabel(primary))
      );
    }

    if (secondary.length) {
      secondary.forEach(function (def) {
        $menu.append(
          $('<button type="button" class="sutore-mp-staff-more-item" role="menuitem"/>')
            .attr('data-action', def.value)
            .text(actionLabel(def))
        );
      });
      $toggle.prop('hidden', false).text(t('moreActions', 'More actions'));
    } else {
      $toggle.prop('hidden', true);
    }

    $foot.prop('hidden', !(primary || secondary.length));
  }

  function fieldDefsForAction(def, actions, detail) {
    var fields = [];
    if (def.fields && def.fields.length) {
      def.fields.forEach(function (field) {
        var copy = {
          name: field.name,
          label: t(field.labelKey, field.labelFallback),
          type: field.type || 'text',
          required: !!field.required,
          placeholder: field.placeholderKey
            ? t(field.placeholderKey, field.placeholderFallback || '')
            : '',
          inputmode: field.inputmode || '',
          value: '',
          optionsKey: field.optionsKey || '',
          options: Array.isArray(field.options) ? field.options.slice() : []
        };
        if (field.name === 'sutore_shipment_code' && detail) {
          copy.value = detail.sutore_shipment_code || '';
        }
        fields.push(copy);
      });
    } else if (needsStaffNote(actions, def.value)) {
      fields.push({
        name: 'staff_note',
        label: t('staffNote', 'Staff note (visible to merchant)'),
        type: 'textarea',
        required: true,
        placeholder: t('staffNotePlaceholder', 'Explain why this action is taken…'),
        value: ''
      });
    }
    return fields;
  }

  function fetchProcessingOrders() {
    return $.ajax({
      url: (cfg.restUrl || '') + 'admin/processing-orders',
      method: 'GET',
      dataType: 'json',
      data: { per_page: 50 },
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    }).then(function (res) {
      var items = (res && res.data && res.data.items) || [];
      return items.map(function (row) {
        return {
          value: String(row.id),
          label: row.label || '#' + String(row.id)
        };
      });
    });
  }

  function withSelectOptions(fields) {
    var needsOrders = fields.some(function (f) {
      return f.type === 'select' && f.optionsKey === 'processing_orders' && !(f.options && f.options.length);
    });
    if (!needsOrders) {
      return $.Deferred().resolve(fields).promise();
    }
    return fetchProcessingOrders().then(function (options) {
      return fields.map(function (field) {
        if (field.type === 'select' && field.optionsKey === 'processing_orders') {
          return Object.assign({}, field, { options: options });
        }
        return field;
      });
    });
  }

  function appendFieldInput($field, field) {
    var $input;
    if (field.type === 'select') {
      $input = $('<select class="sutore-mp-input"/>');
      $input.append(
        $('<option value=""/>').text(t('selectOrderPlaceholder', 'Select a processing order…'))
      );
      (field.options || []).forEach(function (opt) {
        $input.append($('<option/>').attr('value', String(opt.value)).text(opt.label || opt.value));
      });
    } else if (field.type === 'textarea') {
      $input = $('<textarea class="sutore-mp-input" rows="3"/>');
    } else {
      $input = $('<input type="text" class="sutore-mp-input"/>');
    }
    $input.attr('name', field.name);
    if (field.placeholder && field.type !== 'select') {
      $input.attr('placeholder', field.placeholder);
    }
    if (field.inputmode) {
      $input.attr('inputmode', field.inputmode);
    }
    if (field.required) {
      $input.attr('aria-required', 'true');
    }
    if (field.value) {
      $input.val(String(field.value));
    }
    $field.append($input);
    return $input;
  }

  function openActionForm($shell, def) {
    var detail = $shell.data('manage-detail') || {};
    var actions = detail.actions || {};
    var fields = fieldDefsForAction(def, actions, detail);
    var $form = $shell.find('.sutore-mp-staff-action-form').empty();
    closeMoreMenu($shell);

    withSelectOptions(fields)
      .done(function (resolvedFields) {
        var $card = $('<div class="sutore-mp-staff-action-form__card"/>');
        $card.append($('<h3 class="sutore-mp-staff-action-form__title"/>').text(actionLabel(def)));
        if (def.textKey) {
          $card.append(
            $('<p class="sutore-mp-staff-action-form__text"/>').text(t(def.textKey, def.textFallback || ''))
          );
        }

        resolvedFields.forEach(function (field) {
          var $field = $('<label class="sutore-mp-staff-action-form__field"/>');
          $field.append($('<span class="sutore-mp-field-label"/>').text(field.label));
          appendFieldInput($field, field);
          $card.append($field);
        });

        $card.append($('<p class="sutore-mp-staff-action-form__error" aria-live="polite"/>'));
        var $actions = $('<div class="sutore-mp-staff-action-form__actions"/>');
        $actions.append(
          $('<button type="button" class="wp-element-button is-style-outline sutore-mp-staff-action-form-cancel"/>').text(
            t('cancel', 'Cancel')
          )
        );
        $actions.append(
          $('<button type="button" class="wp-element-button sutore-mp-staff-action-form-confirm"/>')
            .attr('data-action', def.value)
            .text(t('confirmAction', 'Confirm'))
        );
        $card.append($actions);
        $form.append($card).prop('hidden', false).data('action', def.value);
        $shell.find('.sutore-mp-staff-foot-bar').prop('hidden', true);
        $form.find('.sutore-mp-input').first().trigger('focus');
      })
      .fail(function (xhr) {
        var msg = t('error', 'Error');
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        alert(msg);
      });
  }

  function collectFormValues($form, def, actions) {
    var fields = fieldDefsForAction(def, actions, {});
    var values = {};
    var missing = null;
    fields.forEach(function (field) {
      var val = String($form.find('[name="' + field.name + '"]').val() || '').trim();
      values[field.name] = val;
      if (field.required && !val && !missing) {
        missing = field.label;
      }
    });
    return { values: values, missing: missing };
  }

  function buildBodyFromValues(action, values) {
    var body = { workflow_action: action };
    Object.keys(values || {}).forEach(function (key) {
      body[key] = values[key];
    });
    return body;
  }

  function actionErrorMessage(res, xhr) {
    if (res && res.message) {
      return String(res.message);
    }
    if (res && res.data && res.data.message) {
      return String(res.data.message);
    }
    if (xhr && xhr.responseJSON) {
      if (xhr.responseJSON.message) {
        return String(xhr.responseJSON.message);
      }
      if (xhr.responseJSON.data && xhr.responseJSON.data.message) {
        return String(xhr.responseJSON.data.message);
      }
    }
    return t('error', 'Error');
  }

  function runAction(opts) {
    var def = opts.def;
    var id = opts.id;
    var actions = opts.actions || {};
    var detail = opts.detail || {};
    var $shell = opts.$shell;
    var source = opts.source || 'list';
    var $busy = opts.$busy;

    if (!def || !id) {
      return;
    }

    function afterSuccess() {
      var $root = $shell.find('.sutore-mp-staff-list-root');
      if (def.value === 'delete_listing') {
        closeManageModal($shell);
        loadListRoot($root, readListState($root));
        return;
      }
      loadListRoot($root, readListState($root));
      if (source === 'modal' || parseInt($shell.data('manage-listing-id'), 10) === id) {
        loadManageModal($shell, id);
      }
    }

    function submit(values, ui) {
      var body = buildBodyFromValues(def.value, values || {});
      if ($busy) {
        $busy.addClass('is-loading').prop('disabled', true);
      }
      if (ui && ui.setBusy) {
        ui.setBusy(true);
      }
      if (ui && ui.setError) {
        ui.setError('');
      }
      postAction(id, body)
        .done(function (res) {
          if (!(res && res.success)) {
            var msg = actionErrorMessage(res);
            if (ui && ui.setError) {
              ui.setError(msg);
            } else {
              alert(msg);
            }
            return;
          }
          if (ui && ui.close) {
            ui.close();
          }
          afterSuccess();
        })
        .fail(function (xhr) {
          var msg = actionErrorMessage(null, xhr);
          if (ui && ui.setError) {
            ui.setError(msg);
          } else {
            alert(msg);
          }
        })
        .always(function () {
          if ($busy) {
            $busy.removeClass('is-loading').prop('disabled', false);
          }
          if (ui && ui.setBusy) {
            ui.setBusy(false);
          }
        });
    }

    if (actionNeedsForm(def, actions)) {
      var fields = fieldDefsForAction(def, actions, detail);
      if (source === 'modal') {
        openActionForm($shell, def);
        return;
      }
      withSelectOptions(fields)
        .done(function (resolvedFields) {
          if (
            def.value === 'attach_to_order' &&
            resolvedFields.some(function (f) {
              return f.name === 'order_id' && !(f.options && f.options.length);
            })
          ) {
            alert(t('noProcessingOrders', 'No processing orders found.'));
            return;
          }
          showFormConfirm({
            title: actionLabel(def),
            text: def.textKey ? t(def.textKey, def.textFallback || '') : '',
            confirmLabel: t('confirmAction', 'Confirm'),
            requiredMessage: t('fieldRequired', 'This field is required.'),
            fields: resolvedFields,
            onConfirm: function (values, ui) {
              submit(values, ui);
              return false;
            }
          });
        })
        .fail(function (xhr) {
          alert(actionErrorMessage(null, xhr));
        });
      return;
    }

    if (def.confirmTextKey) {
      showConfirm(
        t(def.confirmTitleKey, def.confirmTitleFallback || actionLabel(def)),
        t(def.confirmTextKey, def.confirmTextFallback || ''),
        actionLabel(def),
        function () {
          submit({});
        }
      );
      return;
    }

    submit({});
  }

  function fetchDetail(fulfillmentId) {
    return $.ajax({
      url: (cfg.restUrl || '') + 'fulfillments/' + fulfillmentId,
      method: 'GET',
      dataType: 'json',
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    });
  }

  function fetchList(params) {
    return $.ajax({
      url: (cfg.restUrl || '') + 'fulfillments',
      method: 'GET',
      dataType: 'json',
      data: params || {},
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    });
  }

  function postAction(fulfillmentId, body) {
    return $.ajax({
      url: (cfg.restUrl || '') + 'fulfillments/' + fulfillmentId + '/actions',
      method: 'POST',
      contentType: 'application/json',
      dataType: 'json',
      headers: { 'X-WP-Nonce': cfg.restNonce || '' },
      data: JSON.stringify(body || {})
    });
  }

  function kvRow(label, valueHtml) {
    return (
      '<div class="sutore-mp-manage-kv__row">' +
      '<dt>' +
      esc(label) +
      '</dt><dd>' +
      valueHtml +
      '</dd></div>'
    );
  }

  function renderOrderCell(data) {
    if (!data.has_order_link || !(data.order_id > 0)) {
      return '—';
    }
    var label = '#' + data.order_id;
    if (data.order_edit_url) {
      return (
        '<a href="' +
        esc(data.order_edit_url) +
        '" target="_blank" rel="noopener noreferrer">' +
        esc(label) +
        '</a>'
      );
    }
    return esc(label);
  }

  function snapshotRows(data) {
    var snap = data.merchant_snapshot || {};
    if (!data.has_merchant_snapshot) {
      return '';
    }

    var rows = '';
    if (snap.name) {
      rows += kvRow(t('accountHolder', 'Account holder'), esc(snap.name));
    }
    if (snap.iban) {
      rows += kvRow(t('iban', 'IBAN'), '<code>' + esc(snap.iban) + '</code>');
    }
    if (snap.tc) {
      rows += kvRow(t('tc', 'TC Identity Number'), esc(snap.tc));
    }
    if (snap.birth_year) {
      rows += kvRow(t('birthYear', 'Year of Birth'), esc(snap.birth_year));
    }
    if (snap.phone) {
      rows += kvRow(t('phone', 'Phone Number'), esc(snap.phone));
    }
    if (snap.email) {
      rows += kvRow(t('email', 'Email Address'), esc(snap.email));
    }
    if (snap.captured_at_display || snap.captured_at) {
      rows += kvRow(
        t('recordedAt', 'Recorded at'),
        esc(snap.captured_at_display || snap.captured_at)
      );
    }

    return rows;
  }

  function renderActivity(activity) {
    if (!activity || !activity.length) {
      return '<p class="sutore-mp-empty">' + esc(t('noActivity', 'No activity recorded yet.')) + '</p>';
    }

    var rows = activity
      .map(function (event) {
        return (
          '<tr>' +
          '<td class="sutore-mp-manage-activity-date">' +
          esc(event.date || '') +
          '</td>' +
          '<td><strong>' +
          esc(event.event_label || '') +
          '</strong></td>' +
          '<td>' +
          esc(event.actor || '—') +
          '</td>' +
          '<td class="sutore-mp-manage-activity-details">' +
          esc(event.summary || '—') +
          '</td>' +
          '</tr>'
        );
      })
      .join('');

    return (
      '<div class="sutore-mp-manage-activity-wrap"><table class="sutore-mp-manage-activity-table">' +
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

  function renderDetailsPanel(data) {
    var parentId = data.parent_product_id ? '#' + String(data.parent_product_id) : '—';
    var variationId = data.variation_id ? '#' + String(data.variation_id) : '—';
    var rows =
      kvRow(t('product', 'Product'), esc(data.product_title || '')) +
      kvRow(t('status', 'Status'), esc(data.listing_status_label || data.status_label || '—')) +
      kvRow(t('order', 'Order'), renderOrderCell(data)) +
      kvRow(
        t('seller', 'Seller'),
        esc((data.merchant_name || '') + ' (#' + data.merchant_id + ')')
      ) +
      kvRow(t('parentProductId', 'Parent product ID'), esc(parentId)) +
      kvRow(t('variationId', 'Variation ID'), esc(variationId));
    if (hasShippingContext(data) && data.order_shipment_type) {
      rows += kvRow(t('shipmentType', 'Shipment type'), esc(data.order_shipment_type_label || '—'));
    }
    rows += kvRow(t('paymentStatus', 'Payment status'), esc(data.payment_status_display || '—'));

    return (
      '<section class="sutore-mp-staff-summary">' +
      '<dl class="sutore-mp-manage-kv">' +
      rows +
      '</dl></section>'
    );
  }

  function hasShippingContext(data) {
    return !!(data.has_order_link || data.in_sale_lifecycle);
  }

  function renderShippingPanel(data) {
    if (!hasShippingContext(data)) {
      return (
        '<section class="sutore-mp-staff-shipping-details">' +
        '<p class="sutore-mp-empty">' +
        esc(t('noShippingDetails', 'No shipping details for this product yet.')) +
        '</p></section>'
      );
    }

    var rows = '';
    if (data.order_shipment_type) {
      rows += kvRow(
        t('shipmentType', 'Shipment type'),
        esc(data.order_shipment_type_label || '—')
      );
    }
    if (data.merchant_shipment_code) {
      rows += kvRow(
        t('sellerTracking', 'Seller shipping tracking number'),
        esc(data.merchant_shipment_code)
      );
    }
    if (data.merchant_shipped_at_display || data.merchant_shipped_at) {
      rows += kvRow(
        t('sellerShippedAt', 'Seller shipping date'),
        esc(data.merchant_shipped_at_display || data.merchant_shipped_at)
      );
    }
    if (data.sutore_shipment_code) {
      rows += kvRow(
        t('sutoreTracking', 'Sutore shipping tracking number'),
        esc(data.sutore_shipment_code)
      );
    }
    if (data.sutore_shipped_at_display || data.sutore_shipped_at) {
      rows += kvRow(
        t('sutoreShippedAt', 'Shipped to customer date'),
        esc(data.sutore_shipped_at_display || data.sutore_shipped_at)
      );
    }
    if (data.delivered_at_display || data.delivered_at) {
      rows += kvRow(
        t('deliveredAt', 'Delivered to customer'),
        esc(data.delivered_at_display || data.delivered_at)
      );
    }

    if (!rows) {
      return (
        '<section class="sutore-mp-staff-shipping-details">' +
        '<p class="sutore-mp-empty">' +
        esc(t('noShippingDetails', 'No shipping details for this product yet.')) +
        '</p></section>'
      );
    }

    return (
      '<section class="sutore-mp-staff-shipping-details">' +
      '<dl class="sutore-mp-manage-kv">' +
      rows +
      '</dl></section>'
    );
  }

  function renderPaymentPanel(data) {
    var paymentValue = data.payment_status_display || data.payout_status_label || '—';
    if (data.payout_net_amount_display && !data.payment_status_display) {
      paymentValue =
        (data.payout_status_label || paymentValue) + ' · ' + data.payout_net_amount_display;
    }

    var rows = kvRow(t('paymentStatus', 'Payment status'), esc(paymentValue));
    var snapRows = snapshotRows(data);
    if (snapRows) {
      rows += snapRows;
    }

    var html =
      '<section class="sutore-mp-staff-payout-details">' +
      '<dl class="sutore-mp-manage-kv">' +
      rows +
      '</dl>';
    if (!snapRows) {
      html +=
        '<p class="description">' +
        esc(t('noPaymentDetails', 'No payment details were recorded at sale time.')) +
        '</p>';
    }
    html += '</section>';

    return html;
  }

  function renderDetail(data) {
    var title = data.product_title || t('product', 'Product');
    var html =
      '<article class="sutore-mp-staff-detail">' +
      '<div class="sutore-mp-manage-panel" data-panel="details">' +
      renderDetailsPanel(data) +
      '</div>' +
      '<div class="sutore-mp-manage-panel" data-panel="shipping" hidden>' +
      renderShippingPanel(data) +
      '</div>' +
      '<div class="sutore-mp-manage-panel" data-panel="payment" hidden>' +
      renderPaymentPanel(data) +
      '</div>' +
      '<div class="sutore-mp-manage-panel" data-panel="activity" hidden>' +
      '<section class="sutore-mp-staff-activity">' +
      renderActivity(data.activity || []) +
      '</section></div></article>';

    return { title: title, html: html, data: data };
  }

  function setDetailTab($shell, tab) {
    var allowed = { details: 1, shipping: 1, payment: 1, activity: 1 };
    if (!allowed[tab]) {
      tab = 'details';
    }
    $shell.find('.sutore-mp-staff-detail-tabs .sutore-mp-manage-tab').each(function () {
      var name = $(this).attr('data-tab');
      $(this).attr('aria-selected', name === tab ? 'true' : 'false');
    });
    $shell.find('.sutore-mp-staff-detail-panels .sutore-mp-manage-panel').each(function () {
      $(this).prop('hidden', $(this).attr('data-panel') !== tab);
    });
  }

  function setDetailHeader($shell, data) {
    var title = data.product_title || t('product', 'Product');
    var subParts = [];
    if (data.parent_product_id) {
      subParts.push('#' + String(data.parent_product_id));
    }
    if (data.variation_id) {
      subParts.push('#' + String(data.variation_id));
    }
    $shell.find('.sutore-mp-staff-detail-title').text(title);
    $shell.find('.sutore-mp-staff-detail-sub').text(subParts.join(' · '));

    var statusLabel = data.listing_status_label || data.status_label || '';
    var $badge = $shell.find('.sutore-mp-staff-detail-badge');
    if (statusLabel) {
      $badge.text(statusLabel).removeAttr('hidden').prop('hidden', false);
    } else {
      $badge.text('').attr('hidden', 'hidden').prop('hidden', true);
    }

    var $media = $shell.find('.sutore-mp-manage-modal__media, .sutore-mp-staff-detail-media').first();
    if (!$media.length) {
      return;
    }
    $media.empty();
    var thumb = String(data.thumbnail || '').trim();
    if (thumb) {
      var $box;
      if (window.SutoreMarketplace && typeof SutoreMarketplace.thumbBox === 'function') {
        $box = SutoreMarketplace.thumbBox(
          'sutore-mp-manage-modal__thumb-box',
          'sutore-mp-manage-modal__thumb',
          thumb,
          title
        );
      } else {
        $box = $(
          '<div class="sutore-mp-thumb-box sutore-mp-manage-modal__thumb-box" aria-hidden="true">' +
            '<img class="sutore-mp-manage-modal__thumb" src="' +
            esc(thumb) +
            '" alt="" /></div>'
        );
      }
      $media.append($box).removeAttr('hidden').prop('hidden', false);
    } else {
      $media.attr('hidden', 'hidden').prop('hidden', true);
    }
  }

  function $manageOverlay($shell) {
    return $shell.find('.sutore-mp-staff-manage-overlay');
  }

  function revealManageOverlay($shell) {
    var $overlay = $manageOverlay($shell);
    $overlay
      .off('transitionend.sutoreStaffManageClose')
      .removeClass('is-closing')
      .prop('hidden', false)
      .removeAttr('hidden');
    $('body').addClass('sutore-mp-modal-open');
    window.requestAnimationFrame(function () {
      $overlay.addClass('is-open').removeClass('is-closing');
    });
  }

  function syncManageUrl($shell, listingId, replace) {
    var $list = $shell.find('.sutore-mp-staff-list-root');
    var baseUrl = $list.data('baseUrl') || $list.data('base-url') || '';
    try {
      var u = new URL(baseUrl || window.location.href, window.location.origin);
      if (listingId) {
        u.searchParams.set('listing_id', String(listingId));
      } else {
        u.searchParams.delete('listing_id');
      }
      if (replace) {
        window.history.replaceState({}, '', u.pathname + u.search + u.hash);
      } else {
        window.history.pushState({}, '', u.pathname + u.search + u.hash);
      }
    } catch (err) {
      /* ignore */
    }
  }

  function closeManageModal($shell, options) {
    options = options || {};
    var $overlay = $manageOverlay($shell);
    if (!$overlay.length || $overlay.prop('hidden')) {
      return;
    }
    var closeGen = (parseInt($shell.data('manage-close-gen'), 10) || 0) + 1;
    $shell.data('manage-close-gen', closeGen);
    $overlay.addClass('is-closing').removeClass('is-open');
    var finish = function () {
      if (parseInt($shell.data('manage-close-gen'), 10) !== closeGen) {
        return;
      }
      if ($overlay.hasClass('is-open') || !$overlay.hasClass('is-closing')) {
        return;
      }
      $overlay
        .prop('hidden', true)
        .removeClass('is-open is-closing')
        .off('transitionend.sutoreStaffManageClose');
      if (
        !$shell.find('.sutore-mp-filter-overlay:not([hidden])').length &&
        !$shell.find('.sutore-mp-sort-overlay:not([hidden])').length
      ) {
        $('body').removeClass('sutore-mp-modal-open');
      }
      $shell.removeData('manage-listing-id');
      $shell.find('.sutore-mp-staff-detail-panels').empty();
      $shell.find('.sutore-mp-staff-detail-title').text('');
      $shell.find('.sutore-mp-staff-detail-sub').text('');
      $shell.find('.sutore-mp-staff-detail-badge').attr('hidden', 'hidden').prop('hidden', true);
      $shell
        .find('.sutore-mp-manage-modal__media, .sutore-mp-staff-detail-media')
        .empty()
        .attr('hidden', 'hidden')
        .prop('hidden', true);
      clearStaffFoot($shell);
      if (!options.skipUrl) {
        syncManageUrl($shell, 0, true);
      }
    };
    $overlay.off('transitionend.sutoreStaffManageClose').one('transitionend.sutoreStaffManageClose', finish);
    window.setTimeout(finish, 280);
  }

  function loadManageModal($shell, listingId) {
    listingId = parseInt(listingId, 10) || 0;
    var $root = $shell.find('.sutore-mp-staff-detail-root');
    var $panels = $shell.find('.sutore-mp-staff-detail-panels');
    var $loading = $shell.find('.sutore-mp-staff-manage-loading');
    $shell.data('manage-listing-id', listingId);
    // Invalidate any pending close cleanup so it cannot wipe the new header/media.
    $shell.data('manage-close-gen', (parseInt($shell.data('manage-close-gen'), 10) || 0) + 1);
    $root.attr('aria-busy', 'true');
    $panels.empty();
    clearStaffFoot($shell);
    $loading.prop('hidden', false);

    if (!listingId || !cfg.restUrl) {
      $loading.prop('hidden', true);
      $root.attr('aria-busy', 'false');
      $panels.html('<p class="sutore-mp-error">' + esc(t('error', 'Error')) + '</p>');
      return;
    }

    fetchDetail(listingId)
      .done(function (res) {
        if (parseInt($shell.data('manage-listing-id'), 10) !== listingId) {
          return;
        }
        if (!res || !res.success || !res.data) {
          $loading.prop('hidden', true);
          $root.attr('aria-busy', 'false');
          $panels.html(
            '<p class="sutore-mp-error">' +
              esc((res && res.message) || t('notFound', 'Record not found.')) +
              '</p>'
          );
          return;
        }
        var rendered = renderDetail(res.data);
        setDetailHeader($shell, res.data);
        $loading.prop('hidden', true);
        $root.attr('aria-busy', 'false');
        $panels.html(rendered.html);
        setDetailTab($shell, 'details');
        renderStaffFoot($shell, res.data);
      })
      .fail(function (xhr) {
        if (parseInt($shell.data('manage-listing-id'), 10) !== listingId) {
          return;
        }
        var msg = t('notFound', 'Record not found.');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        $loading.prop('hidden', true);
        $root.attr('aria-busy', 'false');
        $panels.html('<p class="sutore-mp-error">' + esc(msg) + '</p>');
        clearStaffFoot($shell);
      });
  }

  function openManageModal($shell, listingId, options) {
    options = options || {};
    listingId = parseInt(listingId, 10) || 0;
    if (!listingId || !$manageOverlay($shell).length) {
      return;
    }
    if (options.headerPreview) {
      setDetailHeader($shell, options.headerPreview);
    }
    revealManageOverlay($shell);
    if (!options.skipUrl) {
      syncManageUrl($shell, listingId, !!options.replaceUrl);
    }
    loadManageModal($shell, listingId);
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

  var listSearchTimer = null;

  function syncListUrl(baseUrl, state) {
    try {
      var u = new URL(baseUrl, window.location.origin);
      var keys = ['search', 'status', 'queue', 'payout_status', 'campaign', 'is_sourcing', 'shipment_type', 'is_imported', 'orderby'];
      keys.forEach(function (key) {
        var val = state[key] || '';
        if (key === 'orderby' && (!val || val === 'id_desc')) {
          u.searchParams.delete(key);
          return;
        }
        if (key === 'status' && state.queue) {
          u.searchParams.delete(key);
          return;
        }
        if (val) {
          u.searchParams.set(key, val);
        } else {
          u.searchParams.delete(key);
        }
      });
      if (state.page > 1) {
        u.searchParams.set('paged', String(state.page));
      } else {
        u.searchParams.delete('paged');
      }
      var $shell = $('.sutore-mp-staff-manage').first();
      var manageId = parseInt($shell.data('manage-listing-id'), 10) || 0;
      if ($shell.find('.sutore-mp-staff-manage-overlay').hasClass('is-open') && manageId > 0) {
        u.searchParams.set('listing_id', String(manageId));
      } else {
        u.searchParams.delete('listing_id');
      }
      window.history.replaceState({}, '', u.pathname + u.search + u.hash);
    } catch (err) {
      // ignore
    }
  }

  function manageUrl(baseUrl, listingId, status, queue) {
    try {
      var u = new URL(baseUrl, window.location.origin);
      u.searchParams.set('listing_id', String(listingId));
      if (queue) {
        u.searchParams.set('queue', queue);
        u.searchParams.delete('status');
      } else {
        u.searchParams.delete('queue');
        if (status) {
          u.searchParams.set('status', status);
        } else {
          u.searchParams.delete('status');
        }
      }
      return u.pathname + u.search + u.hash;
    } catch (err) {
      return baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + 'listing_id=' + listingId;
    }
  }

  function $pageShell($from) {
    return $from.closest('.sutore-mp-staff-manage');
  }

  function collectFilterState($shell) {
    var $filter = $shell.find('.sutore-mp-staff-manage-filter');
    var queue = String($filter.find('[name="queue"]').val() || '');
    return {
      search: String($shell.find('.sutore-mp-staff-manage-search').val() || '').trim(),
      queue: queue,
      status: queue ? '' : String($filter.find('[name="status"]').val() || ''),
      payout_status: String($filter.find('[name="payout_status"]').val() || ''),
      campaign: String($filter.find('[name="campaign"]').val() || ''),
      is_sourcing: String($filter.find('[name="is_sourcing"]').val() || ''),
      shipment_type: String($filter.find('[name="shipment_type"]').val() || ''),
      is_imported: String($filter.find('[name="is_imported"]').val() || ''),
      orderby: String($shell.find('.sutore-mp-staff-manage-sort [name="orderby"]').val() || 'id_desc') || 'id_desc',
      page: 1
    };
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
    var queue = pick('queue', 'queue', '');
    return {
      baseUrl: pick('baseUrl', 'baseUrl', ''),
      search: pick('search', 'search', ''),
      queue: queue,
      status: queue ? '' : pick('status', 'status', ''),
      payout_status: pick('payout_status', 'payoutStatus', ''),
      campaign: pick('campaign', 'campaign', ''),
      is_sourcing: pick('is_sourcing', 'isSourcing', ''),
      shipment_type: pick('shipment_type', 'shipmentType', ''),
      is_imported: pick('is_imported', 'isImported', ''),
      orderby: pick('orderby', 'orderby', 'id_desc') || 'id_desc',
      page: Object.prototype.hasOwnProperty.call(overrides, 'page')
        ? parseInt(overrides.page, 10) || 1
        : parseInt($root.data('page'), 10) || 1,
      perPage: parseInt($root.data('perPage'), 10) || 30
    };
  }

  function syncFilterFields($shell, state) {
    var $filter = $shell.find('.sutore-mp-staff-manage-filter');
    $shell.find('.sutore-mp-staff-manage-search').val(state.search || '');
    $filter.find('[name="queue"]').val(state.queue || '');
    $filter.find('[name="status"]').val(state.status || '').prop('disabled', !!state.queue);
    $filter.find('[name="payout_status"]').val(state.payout_status || '');
    $filter.find('[name="campaign"]').val(state.campaign || '');
    $filter.find('[name="is_sourcing"]').val(state.is_sourcing || '');
    $filter.find('[name="shipment_type"]').val(state.shipment_type || '');
    $filter.find('[name="is_imported"]').val(state.is_imported || '');
    $shell.find('.sutore-mp-staff-manage-sort [name="orderby"]').val(state.orderby || 'id_desc');
  }

  function activeFilterCount(state) {
    var n = 0;
    if (state.queue) n++;
    if (state.status) n++;
    if (state.payout_status) n++;
    if (state.campaign) n++;
    if (state.is_sourcing) n++;
    if (state.shipment_type) n++;
    if (state.is_imported) n++;
    return n;
  }

  function updateListBadges($shell, state) {
    if (window.SutoreMarketplace) {
      SutoreMarketplace.setFilterBadge($shell, activeFilterCount(state));
      SutoreMarketplace.setSortBadge($shell, (state.orderby || 'id_desc') !== 'id_desc');
    }
  }

  function renderCampaignCell(item) {
    var status = String(item.campaign_status || 'none');
    if (status === 'active') {
      return (
        '<span class="sutore-mp-tag is-campaign-active">' +
        esc(item.campaign_status_label || t('campaignActiveTag', 'On campaign')) +
        '</span>'
      );
    }
    if (status === 'offer') {
      return (
        '<span class="sutore-mp-tag is-campaign-offer">' +
        esc(item.campaign_status_label || t('campaignOfferTag', 'Campaign offer')) +
        '</span>'
      );
    }
    return '—';
  }

  function renderPreOrderCell(item) {
    if (item.is_pre_order || item.is_sourcing) {
      return (
        '<span class="sutore-mp-tag is-sourcing">' +
        esc(t('preOrderProduct', 'Pre-order')) +
        '</span>'
      );
    }
    return '—';
  }

  function renderImportedCell(item) {
    if (item.is_imported) {
      return (
        '<span class="sutore-mp-tag is-imported">' +
        esc(t('importedProduct', 'Imported')) +
        '</span>'
      );
    }
    return '—';
  }

  function renderShipmentTypeCell(item) {
    var label = String(item.order_shipment_type_label || '').trim();
    var type = String(item.order_shipment_type || '').trim();
    if ((!label || label === '—') && !type) {
      return '—';
    }
    if (label && label !== '—' && type && label.toLowerCase() !== type.toLowerCase()) {
      return (
        '<span class="sutore-mp-staff-shipment-type">' +
        esc(label) +
        '<span class="sutore-mp-staff-sub">' +
        esc(type) +
        '</span></span>'
      );
    }
    return esc(label && label !== '—' ? label : type);
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
      esc(t('product', 'Product')) +
      '</th><th>' +
      esc(t('order', 'Order')) +
      '</th><th>' +
      esc(t('seller', 'Seller')) +
      '</th><th>' +
      esc(t('price', 'Price')) +
      '</th><th>' +
      esc(t('status', 'Status')) +
      '</th><th>' +
      esc(t('shipmentType', 'Shipment type')) +
      '</th><th>' +
      esc(t('campaign', 'Campaign')) +
      '</th><th>' +
      esc(t('preOrder', 'Pre-order')) +
      '</th><th>' +
      esc(t('imported', 'Imported')) +
      '</th><th>' +
      esc(t('paymentStatus', 'Payment status')) +
      '</th><th></th></tr></thead><tbody>';

    if (!items.length) {
      html +=
        '<tr><td colspan="11">' +
        esc(t('noRecords', 'No records.')) +
        '</td></tr>';
    } else {
      items.forEach(function (item) {
        var thumbSrc = item.thumbnail || '';
        var thumbHtml = thumbSrc
          ? '<div class="sutore-mp-thumb-box sutore-mp-staff-product-thumb"><img class="sutore-mp-staff-product-thumb-img" src="' +
            esc(thumbSrc) +
            '" alt="" /></div>'
          : '<div class="sutore-mp-thumb-box sutore-mp-staff-product-thumb is-empty" aria-hidden="true"></div>';
        var idLine = item.variation_id ? '#' + String(item.variation_id) : '—';
        var payoutLabel =
          item.payout_status_label ||
          item.payment_status_display ||
          t('payoutNotCreated', 'Not created yet');
        html +=
          '<tr><td><div class="sutore-mp-staff-product-cell">' +
          thumbHtml +
          '<div class="sutore-mp-staff-product-info"><strong>' +
          esc(item.product_title || '') +
          '</strong><span class="sutore-mp-staff-sub">' +
          esc(idLine) +
          '</span></div></div></td><td>' +
          renderOrderCell(item) +
          '</td><td>' +
          esc(item.merchant_name || '') +
          '</td><td>' +
          esc(item.asking_display || '—') +
          '</td><td>' +
          esc(item.listing_status_label || item.status_label || '—') +
          '</td><td>' +
          renderShipmentTypeCell(item) +
          '</td><td>' +
          renderCampaignCell(item) +
          '</td><td>' +
          renderPreOrderCell(item) +
          '</td><td>' +
          renderImportedCell(item) +
          '</td><td>' +
          esc(payoutLabel) +
          '</td><td class="sutore-mp-staff-row-actions-cell" data-actions="' +
          encodeURIComponent(JSON.stringify(item.actions || {})) +
          '" data-sutore-shipment-code="' +
          esc(item.sutore_shipment_code || '') +
          '">' +
          renderRowActions(item) +
          '</td></tr>';
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

  function loadListRoot($root, overrides) {
    var state = readListState($root, overrides);
    if (state.queue) {
      state.status = '';
    }
    var $shell = $pageShell($root);
    var $chrome = $shell.find('.sutore-mp-list-chrome');

    $root.attr('aria-busy', 'true');
    $root.html(loadingHtml());

    var params = {
      page: state.page,
      per_page: state.perPage,
      orderby: state.orderby || 'id_desc'
    };
    if (state.search) {
      params.search = state.search;
    }
    if (state.queue) {
      params.queue = state.queue;
    } else if (state.status) {
      params.status = state.status;
    }
    if (state.payout_status) {
      params.payout_status = state.payout_status;
    }
    if (state.campaign) {
      params.campaign = state.campaign;
    }
    if (state.is_sourcing) {
      params.is_sourcing = state.is_sourcing;
    }
    if (state.shipment_type) {
      params.shipment_type = state.shipment_type;
    }
    if (state.is_imported) {
      params.is_imported = state.is_imported;
    }

    fetchList(params)
      .done(function (res) {
        if (!res || !res.success || !res.data) {
          $root.attr('aria-busy', 'false').html(
            '<p class="sutore-mp-error">' +
              esc((res && res.message) || t('error', 'Error')) +
              '</p>'
          );
          $chrome.prop('hidden', false);
          return;
        }
        $root.data('search', state.search);
        $root.data('status', state.status);
        $root.data('queue', state.queue);
        $root.data('payoutStatus', state.payout_status);
        $root.data('campaign', state.campaign);
        $root.data('isSourcing', state.is_sourcing);
        $root.data('shipmentType', state.shipment_type);
        $root.data('isImported', state.is_imported);
        $root.data('orderby', state.orderby);
        $root.data('page', state.page);
        syncFilterFields($shell, state);
        updateListBadges($shell, state);
        syncListUrl(state.baseUrl, state);
        $root.attr('aria-busy', 'false').html(renderList(res.data, state));
        $chrome.prop('hidden', false);

        var openId = parseInt($shell.attr('data-open-listing-id'), 10) || 0;
        if (openId > 0) {
          $shell.attr('data-open-listing-id', '0');
          openManageModal($shell, openId, { replaceUrl: true });
        }
      })
      .fail(function (xhr) {
        var msg = t('error', 'Error');
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        $root.attr('aria-busy', 'false').html(
          '<p class="sutore-mp-error">' + esc(msg) + '</p>'
        );
        $chrome.prop('hidden', false);
      });
  }

  $(function () {
    var $list = $('.sutore-mp-staff-list-root');
    if ($list.length) {
      loadListRoot($list);
    }
  });

  $(document).on('click', '.sutore-mp-staff-manage .sutore-mp-staff-open-manage', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var $shell = $pageShell($btn);
    var id = parseInt($btn.attr('data-listing-id'), 10) || 0;
    openManageModal($shell, id, {
      headerPreview: {
        product_title: $btn.attr('data-product-title') || '',
        thumbnail: $btn.attr('data-thumbnail') || '',
        parent_product_id: parseInt($btn.attr('data-parent-product-id'), 10) || 0,
        variation_id: parseInt($btn.attr('data-variation-id'), 10) || 0,
        listing_status_label: $btn.attr('data-status-label') || ''
      }
    });
  });

  $(document).on('click', '.sutore-mp-staff-manage .sutore-mp-staff-manage-close', function (e) {
    e.preventDefault();
    closeManageModal($pageShell($(this)));
  });

  $(document).on('click', '.sutore-mp-staff-manage-overlay', function (e) {
    if (e.target !== this) {
      return;
    }
    closeManageModal($pageShell($(this)));
  });

  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') {
      return;
    }
    var $openForm = $('.sutore-mp-staff-manage .sutore-mp-staff-action-form:not([hidden])').first();
    if ($openForm.length) {
      hideActionForm($pageShell($openForm));
      return;
    }
    var $openMore = $('.sutore-mp-staff-manage .sutore-mp-staff-more.is-open').first();
    if ($openMore.length) {
      closeMoreMenu($pageShell($openMore));
      return;
    }
    var $open = $('.sutore-mp-staff-manage-overlay.is-open').first();
    if ($open.length) {
      closeManageModal($pageShell($open));
    }
  });

  $(document).on('click', '.sutore-mp-staff-manage .sutore-mp-staff-detail-tabs .sutore-mp-manage-tab', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    setDetailTab($shell, String($(this).attr('data-tab') || 'details'));
  });

  $(document).on('click', '.sutore-mp-staff-manage-filter-apply', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-list-root');
    SutoreMarketplace.closeListOverlays($shell);
    loadListRoot($root, collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-manage-filter-clear', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-list-root');
    $shell.find('.sutore-mp-staff-manage-filter select').each(function () {
      $(this).prop('selectedIndex', 0);
    });
    $shell.find('.sutore-mp-staff-manage-filter [name="status"]').prop('disabled', false);
    SutoreMarketplace.closeListOverlays($shell);
    loadListRoot($root, collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-manage-sort-apply', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-list-root');
    SutoreMarketplace.closeListOverlays($shell);
    loadListRoot($root, collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-manage-sort-clear', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-list-root');
    $shell.find('.sutore-mp-staff-manage-sort [name="orderby"]').val('id_desc');
    SutoreMarketplace.closeListOverlays($shell);
    loadListRoot($root, collectFilterState($shell));
  });

  $(document).on('change', '.sutore-mp-staff-manage-filter [name="queue"]', function () {
    var $filter = $(this).closest('.sutore-mp-staff-manage-filter');
    var $status = $filter.find('[name="status"]');
    if ($(this).val()) {
      $status.val('').prop('disabled', true);
    } else {
      $status.prop('disabled', false);
    }
  });

  $(document).on('input', '.sutore-mp-staff-manage-search', function () {
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-list-root');
    clearTimeout(listSearchTimer);
    listSearchTimer = setTimeout(function () {
      loadListRoot($root, collectFilterState($shell));
    }, 320);
  });

  $(document).on('keydown', '.sutore-mp-staff-manage-search', function (e) {
    if (e.key !== 'Enter') {
      return;
    }
    e.preventDefault();
    clearTimeout(listSearchTimer);
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-list-root');
    SutoreMarketplace.closeListOverlays($shell);
    loadListRoot($root, collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-list-root .sutore-mp-staff-pager a[data-page]', function (e) {
    e.preventDefault();
    var $root = $(this).closest('.sutore-mp-staff-list-root');
    var state = readListState($root);
    state.page = parseInt($(this).data('page'), 10) || 1;
    loadListRoot($root, state);
  });

  $(document).on('click', '.sutore-mp-staff-list-root .sutore-mp-staff-row-action', function (e) {
    e.preventDefault();
    var $btn = $(this);
    if ($btn.prop('disabled') || $btn.hasClass('is-loading')) {
      return;
    }
    var id = parseInt($btn.data('listing-id'), 10) || 0;
    var action = String($btn.data('action') || '');
    var def = findActionDef(action);
    if (!id || !def || !cfg.restUrl) {
      return;
    }

    var $cell = $btn.closest('.sutore-mp-staff-row-actions-cell');
    var actions = {};
    try {
      actions =
        JSON.parse(decodeURIComponent(String($cell.attr('data-actions') || '%7B%7D'))) || {};
    } catch (err) {
      actions = {};
    }
    var detail = {
      sutore_shipment_code: String($cell.attr('data-sutore-shipment-code') || ''),
      actions: actions
    };
    runAction({
      def: def,
      id: id,
      actions: actions,
      detail: detail,
      $shell: $pageShell($btn),
      source: 'list',
      $busy: $btn
    });
  });

  $(document).on('click', '.sutore-mp-staff-manage .sutore-mp-staff-foot-primary-btn', function (e) {
    e.preventDefault();
    var $btn = $(this);
    if ($btn.prop('disabled') || $btn.hasClass('is-loading')) {
      return;
    }
    var $shell = $pageShell($btn);
    var id = parseInt($shell.data('manage-listing-id'), 10) || 0;
    var def = findActionDef(String($btn.attr('data-action') || ''));
    var detail = $shell.data('manage-detail') || {};
    runAction({
      def: def,
      id: id,
      actions: detail.actions || {},
      detail: detail,
      $shell: $shell,
      source: 'modal',
      $busy: $btn
    });
  });

  $(document).on('click', '.sutore-mp-staff-manage .sutore-mp-staff-more-toggle', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $shell = $pageShell($(this));
    var $more = $shell.find('.sutore-mp-staff-more');
    var open = !$more.hasClass('is-open');
    closeMoreMenu($shell);
    if (open) {
      $more.addClass('is-open');
      $more.find('.sutore-mp-staff-more-toggle').attr('aria-expanded', 'true');
      $more.find('.sutore-mp-staff-more-menu').prop('hidden', false);
    }
  });

  $(document).on('click', '.sutore-mp-staff-manage .sutore-mp-staff-more-item', function (e) {
    e.preventDefault();
    var $item = $(this);
    var $shell = $pageShell($item);
    closeMoreMenu($shell);
    var id = parseInt($shell.data('manage-listing-id'), 10) || 0;
    var def = findActionDef(String($item.attr('data-action') || ''));
    var detail = $shell.data('manage-detail') || {};
    runAction({
      def: def,
      id: id,
      actions: detail.actions || {},
      detail: detail,
      $shell: $shell,
      source: 'modal',
      $busy: null
    });
  });

  $(document).on('click', '.sutore-mp-staff-manage .sutore-mp-staff-action-form-cancel', function (e) {
    e.preventDefault();
    hideActionForm($pageShell($(this)));
  });

  $(document).on('click', '.sutore-mp-staff-manage .sutore-mp-staff-action-form-confirm', function (e) {
    e.preventDefault();
    var $btn = $(this);
    if ($btn.prop('disabled') || $btn.hasClass('is-loading')) {
      return;
    }
    var $shell = $pageShell($btn);
    var $form = $shell.find('.sutore-mp-staff-action-form');
    var id = parseInt($shell.data('manage-listing-id'), 10) || 0;
    var def = findActionDef(String($btn.attr('data-action') || $form.data('action') || ''));
    var detail = $shell.data('manage-detail') || {};
    var actions = detail.actions || {};
    if (!def || !id) {
      return;
    }
    var collected = collectFormValues($form, def, actions);
    var $error = $form.find('.sutore-mp-staff-action-form__error');
    if (collected.missing) {
      $error.text(t('fieldRequired', 'This field is required.'));
      return;
    }
    $error.text('');
    $btn.addClass('is-loading').prop('disabled', true);
    postAction(id, buildBodyFromValues(def.value, collected.values))
      .done(function (res) {
        if (!(res && res.success)) {
          $error.text(actionErrorMessage(res));
          return;
        }
        var $root = $shell.find('.sutore-mp-staff-list-root');
        if (def.value === 'delete_listing') {
          closeManageModal($shell);
          loadListRoot($root, readListState($root));
          return;
        }
        loadListRoot($root, readListState($root));
        loadManageModal($shell, id);
      })
      .fail(function (xhr) {
        $error.text(actionErrorMessage(null, xhr));
      })
      .always(function () {
        $btn.removeClass('is-loading').prop('disabled', false);
      });
  });

  $(document).on('click', '.sutore-mp-staff-manage .sutore-mp-staff-more', function (e) {
    e.stopPropagation();
  });

  $(document).on('click', function () {
    $('.sutore-mp-staff-manage .sutore-mp-staff-more.is-open').each(function () {
      closeMoreMenu($pageShell($(this)));
    });
  });
})(jQuery);
