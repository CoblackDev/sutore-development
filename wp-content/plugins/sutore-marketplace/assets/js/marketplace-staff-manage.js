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

  function merchantDetailUrl(merchantId) {
    merchantId = parseInt(merchantId, 10) || 0;
    var base = String(cfg.merchantsUrl || '').trim();
    if (!merchantId || !base) {
      return '';
    }
    try {
      var u = new URL(base, window.location.origin);
      u.searchParams.set('merchant_id', String(merchantId));
      return u.pathname + u.search + u.hash;
    } catch (err) {
      return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'merchant_id=' + merchantId;
    }
  }

  function merchantDeeplinkHtml(label, merchantId) {
    var text = String(label == null ? '' : label).trim();
    merchantId = parseInt(merchantId, 10) || 0;
    var href = merchantDetailUrl(merchantId);
    if (!merchantId || text === '') {
      return esc(text);
    }
    return (
      '<a class="sutore-mp-staff-merchant-link sutore-mp-staff-open-merchant" href="' +
      esc(href || '#') +
      '" data-merchant-id="' +
      esc(String(merchantId)) +
      '" title="' +
      esc(t('openSellerDetail', 'Open seller detail')) +
      '">' +
      esc(text) +
      '</a>'
    );
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
      key: 'approve',
      value: 'approve',
      labelKey: 'approveListing',
      fallback: 'Approve & put on sale',
      primary: true,
      confirmTitleKey: 'approveListing',
      confirmTitleFallback: 'Approve & put on sale',
      confirmTextKey: 'approveListingConfirm',
      confirmTextFallback: 'Approve this listing and put it on sale for customers?'
    },
    {
      key: 'send_campaign_offer',
      value: 'send_campaign_offer',
      labelKey: 'sendCampaignOffer',
      fallback: 'Send campaign offer',
      primary: false,
      textKey: 'sendCampaignOfferConfirm',
      textFallback: 'Choose a campaign to send an offer to this seller for this product.',
      fields: [
        {
          name: 'campaign_id',
          labelKey: 'selectCampaign',
          labelFallback: 'Campaign',
          type: 'select',
          required: true,
          optionsKey: 'sendable_campaigns'
        }
      ]
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
      key: 'adjust_commission',
      value: 'adjust_commission',
      labelKey: 'adjustCommission',
      fallback: 'Adjust commission',
      primary: false,
      textKey: 'adjustCommissionConfirm',
      textFallback:
        'Set a new commission percent for this pending payout only. Net amount is recalculated from the sale asking price.',
      fields: [
        {
          name: 'commission_percent',
          labelKey: 'commissionPercent',
          labelFallback: 'Commission %',
          type: 'number',
          required: true
        },
        {
          name: 'staff_note',
          labelKey: 'staffNote',
          labelFallback: 'Staff note (visible to merchant)',
          type: 'textarea',
          required: false
        }
      ]
    },
    {
      key: 'mark_imported',
      value: 'mark_imported',
      labelKey: 'markImported',
      fallback: 'Mark as imported',
      primary: false
    },
    {
      key: 'unmark_imported',
      value: 'unmark_imported',
      labelKey: 'unmarkImported',
      fallback: 'Mark as not imported',
      primary: false
    },
    {
      key: 'swap',
      value: 'swap',
      labelKey: 'changeSeller',
      fallback: 'Change Seller',
      primary: false,
      textKey: 'changeSellerConfirm',
      textFallback:
        'Replace this sale with another eligible listing. Same product is listed by default; search to pick a different product.',
      fields: [
        {
          name: 'new_variation_id',
          labelKey: 'replacementListing',
          labelFallback: 'Replacement listing',
          type: 'select',
          required: true,
          optionsKey: 'swap_candidates'
        },
        {
          name: 'staff_note',
          labelKey: 'staffNote',
          labelFallback: 'Staff note (visible to merchant)',
          type: 'textarea',
          required: false,
          placeholderKey: 'staffNotePlaceholder',
          placeholderFallback: 'Explain why this action is taken…'
        },
        {
          name: 'return_to_queue',
          labelKey: 'returnToQueue',
          labelFallback: 'Return detached product to the sale queue',
          type: 'checkbox',
          checked: true
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
        'Add this listing to an order. Paid orders start as sold (seller is notified). Unpaid pending/on-hold orders wait for payment confirmation — no sold SMS.',
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
      key: 'mark_pre_order',
      value: 'mark_pre_order',
      labelKey: 'markPreOrder',
      fallback: 'Mark as pre-order',
      primary: false,
      textKey: 'markPreOrderConfirm',
      textFallback: 'Open this sale on the pre-order board for other merchants. Continue?'
    },
    {
      key: 'close_pre_order',
      value: 'close_pre_order',
      labelKey: 'closePreOrder',
      fallback: 'Could not be sourced',
      primary: true,
      textKey: 'closePreOrderConfirm',
      textFallback:
        'Mark this pre-order as could not be sourced, detach it from the order, and refund the line if the order is paid. Continue?',
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
      key: 'detach',
      value: 'split',
      labelKey: 'detach',
      fallback: 'Detach from Order',
      primary: false,
      textKey: 'confirmDetach',
      textFallback: 'Will be unlinked from the order. Continue?',
      fields: [
        {
          name: 'staff_note',
          labelKey: 'staffNote',
          labelFallback: 'Staff note (visible to merchant)',
          type: 'textarea',
          required: true,
          placeholderKey: 'staffNotePlaceholder',
          placeholderFallback: 'Explain why this action is taken…'
        },
        {
          name: 'return_to_queue',
          labelKey: 'returnToQueue',
          labelFallback: 'Return detached product to the sale queue',
          type: 'checkbox',
          checked: true
        }
      ]
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
      key: 'remove_from_sale',
      value: 'remove_from_sale',
      labelKey: 'removeFromSale',
      fallback: 'Remove from sale',
      primary: false,
      textKey: 'removeFromSaleConfirm',
      textFallback: 'This listing will be taken off sale and marked as not for sale.',
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

  /** workflow_action values that need no extra input (bulk-safe). */
  var BULK_WORKFLOWS = [
    'confirm_payment',
    'mark_arrived',
    'mark_verified',
    'mark_ready_to_ship',
    'mark_delivered',
    'mark_payout_paid',
    'mark_imported',
    'unmark_imported',
    'put_on_sale',
    'approve',
    'send_campaign_offer',
    'remove_from_sale',
    'mark_not_for_sale',
    'delete_listing'
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

  function actionIconSvg(key) {
    var icons = {
      detail:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>',
      confirm_payment:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>',
      mark_arrived:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2 4.5 20.3l.7.7L12 18l6.8 3 .7-.7L12 2zm0 5.8 3.9 9.1-3.9-1.7-3.9 1.7L12 7.8z"/></svg>',
      mark_verified:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 1 3 5v6c0 5.6 3.8 10.7 9 12 5.2-1.3 9-6.4 9-12V5l-9-4zm-1.2 14.5-3.5-3.5 1.4-1.4 2.1 2.1 4.6-4.6 1.4 1.4-6 6z"/></svg>',
      mark_ready_to_ship:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20 8h-3V4H3v13h2a3 3 0 0 0 6 0h4a3 3 0 0 0 6 0h1v-5l-2-4zM8 18.5A1.5 1.5 0 1 1 8 15a1.5 1.5 0 0 1 0 3.5zM18 7.5l1.5 3H17v-3h1zm0 11a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z"/></svg>',
      mark_delivered:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1.1 14.3-3.7-3.7 1.4-1.4 2.3 2.3 5-5 1.4 1.4-6.4 6.4z"/></svg>',
      put_on_sale:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2 3 7v2h18V7l-9-5zm-7 9v8h2v-8H5zm4 0v8h2v-8H9zm4 0v8h2v-8h-2zm4 0v8h2v-8h-2z"/></svg>',
      approve:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>',
      send_campaign_offer:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M2 21 23 12 2 3v7l15 2-15 2v7z"/></svg>',
      remove_from_sale:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm5 11H7v-2h10v2z"/></svg>',
      mark_not_for_sale:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm5 11H7v-2h10v2z"/></svg>',
      delete:
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>'
    };
    return icons[key] || icons.detail;
  }

  function iconButtonHtml(className, attrs, iconKey, label) {
    var attrHtml = '';
    Object.keys(attrs || {}).forEach(function (key) {
      attrHtml += ' ' + key + '="' + esc(String(attrs[key])) + '"';
    });
    return (
      '<button type="button" class="' +
      esc(className) +
      '" title="' +
      esc(label) +
      '" aria-label="' +
      esc(label) +
      '"' +
      attrHtml +
      '>' +
      actionIconSvg(iconKey) +
      '<span class="screen-reader-text">' +
      esc(label) +
      '</span></button>'
    );
  }

  function invoiceButtonsHtml(invoices) {
    invoices = Array.isArray(invoices) ? invoices : [];
    var html = '';
    invoices.forEach(function (invoice) {
      if (!invoice || !invoice.has_pdf || !invoice.pdf_url) {
        return;
      }
      var label =
        invoice.kind === 'seller_commission'
          ? t('viewSellerInvoice', 'View seller invoice')
          : t('viewCustomerInvoice', 'View customer invoice');
      html +=
        '<a class="sutore-mp-invoice-link" href="' +
        esc(invoice.pdf_url) +
        '" target="_blank" rel="noopener">' +
        esc(label) +
        '</a>';
    });
    return html;
  }

  function renderRowActions(item) {
    var id = item.id;
    // List rows only open detail — workflow actions live in the modal footer
    // (primary next step + More actions).
    return (
      '<div class="sutore-mp-staff-row-actions">' +
      iconButtonHtml(
        'sutore-mp-staff-icon-btn is-outline sutore-mp-staff-open-manage',
        {
          'data-variation-id': String(id),
          'data-thumbnail': item.thumbnail || '',
          'data-product-title': item.product_title || '',
          'data-parent-product-id': String(item.parent_product_id || ''),
          'data-status-label': item.listing_status_label || item.status_label || ''
        },
        'detail',
        t('detail', 'Detail')
      ) +
      invoiceButtonsHtml(item.invoices) +
      '</div>'
    );
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
          options: Array.isArray(field.options) ? field.options.slice() : [],
          checked: field.checked
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

  function fetchProcessingOrders(listingId, search) {
    var data = { per_page: 50 };
    if (listingId) {
      data.variation_id = listingId;
    }
    if (search) {
      data.search = search;
    }
    return $.ajax({
      url: (cfg.restUrl || '') + 'admin/processing-orders',
      method: 'GET',
      dataType: 'json',
      data: data,
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    }).then(function (res) {
      var items = (res && res.data && res.data.items) || [];
      return items.map(function (row) {
        return {
          value: String(row.id),
          label: row.label || '#' + String(row.id),
          contains_same_product: !!row.contains_same_product
        };
      });
    });
  }

  function fetchSwapCandidates(listingId, search) {
    var data = { per_page: 30 };
    if (search) {
      data.search = search;
    }
    return $.ajax({
      url: (cfg.restUrl || '') + 'fulfillments/' + listingId + '/swap-candidates',
      method: 'GET',
      dataType: 'json',
      data: data,
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    }).then(function (res) {
      var items = (res && res.data && res.data.items) || [];
      return items.map(function (row) {
        return {
          value: String(row.id),
          label: row.label || '#' + String(row.id),
          same_parent: !!row.same_parent,
          parent_product_id: row.parent_product_id || 0
        };
      });
    });
  }

  function fetchSendableCampaigns() {
    return $.ajax({
      url: (cfg.restUrl || '') + 'admin/campaigns',
      method: 'GET',
      dataType: 'json',
      data: { sendable: 1 },
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    }).then(function (res) {
      var items = (res && res.data && res.data.items) || [];
      return items.map(function (item) {
        var parts = [item.name || '#' + String(item.id || '')];
        if (item.seller_discount_label) {
          parts.push(item.seller_discount_label);
        }
        if (item.platform_discount_label) {
          parts.push(item.platform_discount_label);
        }
        return {
          value: String(item.id || ''),
          label: parts.join(' · ')
        };
      });
    });
  }

  function withSelectOptions(fields, context) {
    context = context || {};
    var listingId = parseInt(context.listingId, 10) || 0;
    var needsOrders = fields.some(function (f) {
      return f.type === 'select' && f.optionsKey === 'processing_orders' && !(f.options && f.options.length);
    });
    var needsCampaigns = fields.some(function (f) {
      return f.type === 'select' && f.optionsKey === 'sendable_campaigns' && !(f.options && f.options.length);
    });
    var needsSwap = fields.some(function (f) {
      return f.type === 'select' && f.optionsKey === 'swap_candidates' && !(f.options && f.options.length);
    });
    if (!needsOrders && !needsCampaigns && !needsSwap) {
      return $.Deferred().resolve(fields).promise();
    }

    var maps = {};
    var chain = $.Deferred().resolve().promise();
    if (needsOrders) {
      chain = chain.then(function () {
        return fetchProcessingOrders(listingId).then(function (options) {
          maps.processing_orders = options || [];
        });
      });
    }
    if (needsSwap) {
      chain = chain.then(function () {
        return fetchSwapCandidates(listingId).then(function (options) {
          maps.swap_candidates = options || [];
        });
      });
    }
    if (needsCampaigns) {
      chain = chain.then(function () {
        return fetchSendableCampaigns().then(function (options) {
          maps.sendable_campaigns = options || [];
        });
      });
    }

    return chain.then(function () {
      return fields.map(function (field) {
        if (field.type === 'select' && field.optionsKey && maps[field.optionsKey]) {
          return Object.assign({}, field, {
            options: maps[field.optionsKey],
            placeholder: selectPlaceholder(field)
          });
        }
        return field;
      });
    });
  }

  function selectPlaceholder(field) {
    if (field.optionsKey === 'sendable_campaigns') {
      return t('selectCampaignPlaceholder', 'Select a campaign…');
    }
    if (field.optionsKey === 'swap_candidates') {
      return t('selectReplacementListing', 'Select a replacement listing…');
    }
    return t('selectOrderPlaceholder', 'Select a processing order…');
  }

  function fillSelectOptions($select, options, placeholder) {
    $select.empty();
    $select.append($('<option value=""/>').text(placeholder || '—'));
    (options || []).forEach(function (opt) {
      var $opt = $('<option/>')
        .attr('value', String(opt.value))
        .text(opt.label || opt.value);
      if (opt.same_parent != null) {
        $opt.attr('data-same-parent', opt.same_parent ? '1' : '0');
      }
      if (opt.parent_product_id != null) {
        $opt.attr('data-parent-product-id', String(opt.parent_product_id));
      }
      if (opt.contains_same_product != null) {
        $opt.attr('data-contains-same-product', opt.contains_same_product ? '1' : '0');
      }
      $select.append($opt);
    });
  }

  function appendFieldInput($field, field) {
    var $input;
    if (field.type === 'checkbox') {
      $field.addClass('is-checkbox');
      $input = $('<input type="checkbox"/>');
      if (field.checked !== false && field.checked !== 0 && field.checked !== '0') {
        $input.prop('checked', true);
      }
      $input.attr('name', field.name);
      $field.append($input);
      return $input;
    }
    if (field.type === 'select') {
      $input = $('<select class="sutore-mp-input"/>');
      fillSelectOptions($input, field.options || [], selectPlaceholder(field));
    } else if (field.type === 'textarea') {
      $input = $('<textarea class="sutore-mp-input" rows="3"/>');
    } else if (field.type === 'number') {
      $input = $('<input type="number" class="sutore-mp-input" min="0" max="100" step="0.01"/>');
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

  function wireStaffPickerSearch($root, def, listingId) {
    if (!def || !listingId) {
      return;
    }
    var isSwap = def.value === 'swap';
    var isAttach = def.value === 'attach_to_order';
    if (!isSwap && !isAttach) {
      return;
    }
    if (isAttach) {
      // "Add to order" should rely on the default processing_orders dropdown.
      // Searching another order was removed.
      return;
    }

    var selectName = isSwap ? 'new_variation_id' : 'order_id';
    var $select = $root.find('select[name="' + selectName + '"]').first();
    if (!$select.length) {
      return;
    }

    var $selectField = $select.closest('label, .sutore-mp-staff-action-form__field, .sutore-mp-confirm-field');
    var $searchWrap = $(
      '<div class="sutore-mp-staff-picker-search">' +
        '<input type="search" class="sutore-mp-input sutore-mp-staff-picker-search__input" />' +
        '<button type="button" class="wp-element-button is-style-outline sutore-mp-staff-picker-search__btn"></button>' +
        '<button type="button" class="sutore-mp-link-btn sutore-mp-staff-picker-search__reset" hidden></button>' +
        '</div>'
    );
    var $input = $searchWrap.find('.sutore-mp-staff-picker-search__input');
    var $btn = $searchWrap.find('.sutore-mp-staff-picker-search__btn');
    var $reset = $searchWrap.find('.sutore-mp-staff-picker-search__reset');
    $input.attr(
      'placeholder',
      isSwap
        ? t('searchDifferentProduct', 'Search a different product…')
        : t('searchOtherOrder', 'Search another order…')
    );
    $btn.text(t('search', 'Search'));
    $reset.text(t('showDefaultMatches', 'Show default matches'));
    $selectField.after($searchWrap);

    var $noteField = $root.find('[name="staff_note"]').closest('label, .sutore-mp-staff-action-form__field, .sutore-mp-confirm-field');
    var $noteHint = $('<p class="sutore-mp-staff-picker-hint" hidden/>').text(
      t(
        'differentProductNoteRequired',
        'A staff note is required when replacing with a different product.'
      )
    );
    if ($noteField.length) {
      $noteField.before($noteHint);
    }

    function syncSwapNoteRequirement() {
      if (!isSwap || !$noteField.length) {
        return;
      }
      var $opt = $select.find('option:selected');
      var sameParent = String($opt.attr('data-same-parent') || '1') !== '0';
      var $note = $root.find('[name="staff_note"]');
      if (!sameParent && $select.val()) {
        $note.attr('aria-required', 'true');
        $noteField.addClass('is-required');
        $noteHint.prop('hidden', false);
      } else {
        $note.removeAttr('aria-required');
        $noteField.removeClass('is-required');
        $noteHint.prop('hidden', true);
      }
    }

    function runSearch(resetToDefault) {
      var q = resetToDefault ? '' : String($input.val() || '').trim();
      if (!resetToDefault && !q) {
        showToast(t('enterSearchTerm', 'Enter a search term.'), 'error');
        return;
      }
      $btn.addClass('is-loading').prop('disabled', true);
      var req = isSwap
        ? fetchSwapCandidates(listingId, q)
        : fetchProcessingOrders(listingId, q);
      req
        .done(function (options) {
          fillSelectOptions(
            $select,
            options,
            isSwap
              ? t('selectReplacementListing', 'Select a replacement listing…')
              : t('selectOrderPlaceholder', 'Select a processing order…')
          );
          $reset.prop('hidden', !q);
          if (!options.length) {
            showToast(
              isSwap
                ? t('noSwapCandidates', 'No eligible replacement listings found.')
                : t('noProcessingOrders', 'No processing orders found.'),
              'error'
            );
          }
          syncSwapNoteRequirement();
        })
        .fail(function (xhr) {
          showToast(actionErrorMessage(null, xhr), 'error');
        })
        .always(function () {
          $btn.removeClass('is-loading').prop('disabled', false);
        });
    }

    $btn.on('click', function () {
      runSearch(false);
    });
    $input.on('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        runSearch(false);
      }
    });
    $reset.on('click', function () {
      $input.val('');
      runSearch(true);
    });
    $select.on('change', syncSwapNoteRequirement);
    syncSwapNoteRequirement();
  }

  function openActionForm($shell, def) {
    var detail = $shell.data('manage-detail') || {};
    var actions = detail.actions || {};
    var listingId = parseInt($shell.data('manage-variation-id'), 10) || parseInt(detail.variation_id || detail.id, 10) || 0;
    var fields = fieldDefsForAction(def, actions, detail);
    var $form = $shell.find('.sutore-mp-staff-action-form').empty();
    closeMoreMenu($shell);

    withSelectOptions(fields, { listingId: listingId })
      .done(function (resolvedFields) {
        if (
          def.value === 'send_campaign_offer' &&
          resolvedFields.some(function (f) {
            return f.name === 'campaign_id' && !(f.options && f.options.length);
          })
        ) {
          showToast(
            t(
              'noSendableCampaigns',
              'No sendable campaigns found. Create one with start and end dates first.'
            ),
            'error'
          );
          return;
        }
        var $card = $('<div class="sutore-mp-staff-action-form__card"/>');
        $card.append($('<h3 class="sutore-mp-staff-action-form__title"/>').text(actionLabel(def)));
        if (def.textKey) {
          $card.append(
            $('<p class="sutore-mp-staff-action-form__text"/>').text(t(def.textKey, def.textFallback || ''))
          );
        }

        resolvedFields.forEach(function (field) {
          var $field = $('<label class="sutore-mp-staff-action-form__field"/>');
          if (field.type === 'checkbox') {
            appendFieldInput($field, field);
            $field.append($('<span class="sutore-mp-field-label"/>').text(field.label));
          } else {
            $field.append($('<span class="sutore-mp-field-label"/>').text(field.label));
            appendFieldInput($field, field);
          }
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
        wireStaffPickerSearch($form, def, listingId);
        $form.find('.sutore-mp-input').first().trigger('focus');
      })
      .fail(function (xhr) {
        showToast(actionErrorMessage(null, xhr), 'error');
      });
  }

  function collectFormValues($form, def, actions) {
    var fields = fieldDefsForAction(def, actions, {});
    var values = {};
    var missing = null;
    fields.forEach(function (field) {
      var $input = $form.find('[name="' + field.name + '"]');
      if (field.type === 'checkbox') {
        values[field.name] = $input.prop('checked') ? '1' : '0';
        return;
      }
      var val = String($input.val() || '').trim();
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
      if (key === 'return_to_queue') {
        body[key] =
          values[key] === true ||
          values[key] === 1 ||
          values[key] === '1' ||
          values[key] === 'true';
        return;
      }
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
      var $root = $manageListRoot();
      var $modalShell = $manageDetailHost();
      if (def.value === 'delete_listing') {
        closeManageModal($modalShell, { syncUrl: isManageProductsPage() });
        if ($root.length) {
          loadListRoot($root, readListState($root));
        }
        return;
      }
      if ($root.length) {
        loadListRoot($root, readListState($root));
      }
      if (
        source === 'modal' ||
        parseInt($modalShell.data('manage-variation-id'), 10) === id
      ) {
        loadManageModal($modalShell, id);
      }
    }

    function submit(values, ui) {
      if (def.value === 'send_campaign_offer') {
        var campaignId = parseInt((values && values.campaign_id) || 0, 10) || 0;
        if (!campaignId) {
          var missing = t('selectCampaignPlaceholder', 'Select a campaign…');
          if (ui && ui.setError) {
            ui.setError(missing);
          } else {
            showToast(missing, 'error');
          }
          return;
        }
        if ($busy) {
          $busy.addClass('is-loading').prop('disabled', true);
        }
        if (ui && ui.setBusy) {
          ui.setBusy(true);
        }
        if (ui && ui.setError) {
          ui.setError('');
        }
        postCampaignOffer(campaignId, [id])
          .done(function (res) {
            if (!(res && res.success)) {
              var failMsg = actionErrorMessage(res);
              if (ui && ui.setError) {
                ui.setError(failMsg);
              } else {
                showToast(failMsg, 'error');
              }
              return;
            }
            var okMsg =
              (res.data && res.data.message) ||
              t('campaignOfferSent', 'Campaign offer sent.');
            showToast(okMsg, 'success');
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
              showToast(msg, 'error');
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
        return;
      }

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
              showToast(msg, 'error');
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
            showToast(msg, 'error');
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
      withSelectOptions(fields, { listingId: id })
        .done(function (resolvedFields) {
          if (
            def.value === 'send_campaign_offer' &&
            resolvedFields.some(function (f) {
              return f.name === 'campaign_id' && !(f.options && f.options.length);
            })
          ) {
            showToast(t('noSendableCampaigns', 'No sendable campaigns found. Create one with start and end dates first.'), 'error');
            return;
          }
          showFormConfirm({
            title: actionLabel(def),
            text: def.textKey ? t(def.textKey, def.textFallback || '') : '',
            confirmLabel: t('confirmAction', 'Confirm'),
            requiredMessage: t('fieldRequired', 'This field is required.'),
            fields: resolvedFields,
            onReady: function ($modal) {
              wireStaffPickerSearch($modal, def, id);
            },
            onConfirm: function (values, ui) {
              if (def.value === 'swap') {
                var $liveOpt = $('.sutore-mp-form-confirm select[name="new_variation_id"] option:selected');
                var sameParent = String($liveOpt.attr('data-same-parent') || '1') !== '0';
                if (!sameParent && !(values.staff_note || '').trim()) {
                  if (ui && ui.setError) {
                    ui.setError(
                      t(
                        'differentProductNoteRequired',
                        'A staff note is required when replacing with a different product.'
                      )
                    );
                  }
                  return false;
                }
              }
              submit(values, ui);
              return false;
            }
          });
        })
        .fail(function (xhr) {
          showToast(actionErrorMessage(null, xhr), 'error');
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

  function postCampaignOffer(campaignId, listingIds) {
    return $.ajax({
      url: (cfg.restUrl || '') + 'admin/campaigns/' + campaignId + '/offers',
      method: 'POST',
      contentType: 'application/json',
      dataType: 'json',
      headers: { 'X-WP-Nonce': cfg.restNonce || '' },
      data: JSON.stringify({
        variation_ids: listingIds || []
      })
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
    var orderId = parseInt(data.order_id, 10) || 0;
    var label = '#' + orderId;
    var href = '';
    if (cfg.manageOrdersUrl) {
      try {
        var u = new URL(String(cfg.manageOrdersUrl), window.location.origin);
        u.searchParams.set('order_id', String(orderId));
        href = u.pathname + u.search + u.hash;
      } catch (err) {
        href =
          String(cfg.manageOrdersUrl) +
          (String(cfg.manageOrdersUrl).indexOf('?') >= 0 ? '&' : '?') +
          'order_id=' +
          orderId;
      }
    } else if (data.order_edit_url) {
      href = String(data.order_edit_url);
    }
    return (
      '<a class="sutore-mp-staff-order-link sutore-mp-staff-open-order" href="' +
      esc(href || '#') +
      '" data-order-id="' +
      esc(String(orderId)) +
      '" title="' +
      esc(t('openOrderDetail', 'Open order detail')) +
      '">' +
      esc(label) +
      '</a>'
    );
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
    if (snap.merchant_level) {
      rows += kvRow(t('sellerLevel', 'Seller level'), esc(snap.merchant_level_label || snap.merchant_level));
    }
    if (snap.city || snap.state) {
      rows += kvRow(
        t('city', 'City'),
        esc([snap.state, snap.city].filter(Boolean).join(' / '))
      );
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
        merchantDeeplinkHtml((data.merchant_name || '') + ' (#' + data.merchant_id + ')', data.merchant_id)
      ) +
      kvRow(t('parentProductId', 'Parent product ID'), esc(parentId)) +
      kvRow(t('variationId', 'Variation ID'), esc(variationId));
    if (data.created_at_display || data.created_at) {
      rows += kvRow(
        t('createdAt', 'Created at'),
        esc(data.created_at_display || data.created_at)
      );
    }
    if (hasShippingContext(data) && data.order_shipment_type) {
      rows += kvRow(t('customerShipping', 'Customer shipping'), esc(data.order_shipment_type_label || '—'));
    }
    rows += kvRow(t('paymentStatus', 'Payment status'), esc(data.payment_status_display || '—'));

    var commission = data.commission || {};
    var listingPct =
      data.listing_commission_percent != null ? data.listing_commission_percent : commission.listing_percent;
    var livePct = commission.live_percent != null ? commission.live_percent : '';
    var salePct =
      data.sale_commission_percent != null ? data.sale_commission_percent : commission.sale_percent;
    if (livePct !== '') {
      rows += kvRow(t('liveCommission', 'Live rate'), esc('%' + String(livePct)));
    }
    if (salePct != null && salePct !== '') {
      rows += kvRow(t('saleLockedCommission', 'Locked at sale'), esc('%' + String(salePct)));
    }

    var listingVal = listingPct == null || listingPct === '' ? '' : String(listingPct);

    return (
      '<section class="sutore-mp-staff-summary">' +
      '<dl class="sutore-mp-manage-kv">' +
      rows +
      '</dl>' +
      '<form class="sutore-mp-staff-listing-commission sutore-mp-staff-form" data-listing-id="' +
      esc(String(data.variation_id || data.id || '')) +
      '">' +
      '<h3 class="sutore-mp-staff-panel-title sutore-mp-staff-subheading">' +
      esc(t('listingCommission', 'Listing commission %')) +
      '</h3>' +
      '<p class="description sutore-mp-staff-form-hint">' +
      esc(
        t(
          'listingCommissionHelp',
          'Optional. Leave empty for the normal seller rate. 0 means no commission on this listing.'
        )
      ) +
      '</p>' +
      '<div class="sutore-mp-staff-form-grid">' +
      '<label>' +
      esc(t('listingCommission', 'Listing commission %')) +
      '<input type="number" name="commission_percent" class="sutore-mp-input" min="0" max="100" step="0.01" value="' +
      esc(listingVal) +
      '" /></label></div>' +
      '<div class="sutore-mp-staff-actions">' +
      '<button type="submit" class="wp-element-button is-style-outline">' +
      esc(t('save', 'Save')) +
      '</button></div></form></section>'
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
        t('customerShipping', 'Customer shipping'),
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
    var snap = arguments.length > 1 ? arguments[1] : snapshotRows(data);
    if (!snap) {
      return '';
    }

    var paymentValue = data.payment_status_display || data.payout_status_label || '—';
    if (data.payout_net_amount_display && !data.payment_status_display) {
      paymentValue =
        (data.payout_status_label || paymentValue) + ' · ' + data.payout_net_amount_display;
    }

    var rows = kvRow(t('paymentStatus', 'Payment status'), esc(paymentValue)) + snap;
    var commission = data.commission || {};
    var payout = data.payout || {};
    if (commission.sale_percent != null && commission.sale_percent !== '') {
      rows += kvRow(
        t('saleLockedCommission', 'Locked at sale'),
        esc('%' + String(commission.sale_percent))
      );
    }
    if (payout.commission_percent != null) {
      rows += kvRow(
        t('payoutCommission', 'Payout commission'),
        esc('%' + String(payout.commission_percent))
      );
    }
    if (payout.scheduled_payout_date_display || payout.scheduled_message) {
      rows += kvRow(
        t('scheduledPayoutDate', 'Scheduled payout date'),
        esc(payout.scheduled_message || payout.scheduled_payout_date_display)
      );
    }
    if (payout.paid_at_display || payout.paid_at) {
      rows += kvRow(t('payoutPaidAt', 'Paid at'), esc(payout.paid_at_display || payout.paid_at));
    }
    if (payout.payment_ref) {
      rows += kvRow(t('paymentRef', 'Payment reference (EFT/receipt)'), esc(payout.payment_ref));
    }

    var invoices = Array.isArray(data.invoices) ? data.invoices : [];
    invoices.forEach(function (invoice) {
      var value = invoice.status_label || invoice.status || '—';
      if (invoice.invoice_number) {
        value = invoice.invoice_number + ' · ' + value;
      }
      if (invoice.invoice_date_display) {
        value += ' · ' + invoice.invoice_date_display;
      }
      if (invoice.amount_display) {
        value += ' · ' + invoice.amount_display;
      }
      rows += kvRow(invoice.kind_label || t('invoice', 'Invoice'), esc(value));
      if (invoice.has_pdf && invoice.pdf_url) {
        rows += kvRow(
          t('viewInvoice', 'View invoice'),
          '<a class="sutore-mp-invoice-link" href="' +
            esc(invoice.pdf_url) +
            '" target="_blank" rel="noopener">' +
            esc(t('openPdf', 'Open PDF')) +
            '</a>'
        );
      }
      if (invoice.last_error && invoice.status === 'error') {
        rows += kvRow(t('invoiceError', 'Invoice error'), esc(invoice.last_error));
      }
    });

    return (
      '<section class="sutore-mp-staff-payout-details">' +
      '<dl class="sutore-mp-manage-kv">' +
      rows +
      '</dl></section>'
    );
  }

  function renderDetail(data) {
    var paymentSnapRows = snapshotRows(data);
    var paymentEmpty = !paymentSnapRows;
    var title = data.product_title || t('product', 'Product');
    var html =
      '<article class="sutore-mp-staff-detail">' +
      '<div class="sutore-mp-manage-panel" data-panel="details">' +
      renderDetailsPanel(data) +
      '</div>' +
      '<div class="sutore-mp-manage-panel" data-panel="shipping" hidden>' +
      renderShippingPanel(data) +
      '</div>' +
      '<div class="sutore-mp-manage-panel" data-panel="payment" hidden' +
      (paymentEmpty ? ' data-empty="1"' : '') +
      '>' +
      (paymentEmpty ? '' : renderPaymentPanel(data, paymentSnapRows)) +
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
      var $panel = $(this);
      var name = String($panel.attr('data-panel') || '');
      var isEmpty = String($panel.attr('data-empty') || '') === '1';
      $panel.prop('hidden', name !== tab || isEmpty);
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

  function $manageDetailHost() {
    return $('.sutore-mp-staff-product-detail-host').first();
  }

  function $manageListRoot() {
    return $('.sutore-mp-staff-manage')
      .not(
        '.sutore-mp-staff-orders, .sutore-mp-staff-merchants, .sutore-mp-staff-product-detail-host'
      )
      .find('.sutore-mp-staff-list-root')
      .first();
  }

  function isManageProductsPage() {
    return $manageListRoot().length > 0;
  }

  function otherStaffOverlaysOpen() {
    return (
      $('.sutore-mp-manage-overlay.is-open').not('.sutore-mp-staff-product-detail-overlay').length > 0 ||
      (window.SutoreMarketplace &&
        SutoreMarketplace.isStaffMerchantOpen &&
        SutoreMarketplace.isStaffMerchantOpen()) ||
      (window.SutoreMarketplace &&
        SutoreMarketplace.isStaffOrderOpen &&
        SutoreMarketplace.isStaffOrderOpen())
    );
  }

  function $manageOverlay() {
    return $manageDetailHost().find('.sutore-mp-staff-manage-overlay');
  }

  function revealManageOverlay($shell) {
    var $overlay = $manageOverlay();
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

  function syncManageUrl(listingId, replace) {
    var $list = $manageListRoot();
    var baseUrl =
      ($list.length ? $list.data('baseUrl') || $list.data('base-url') : '') ||
      cfg.manageProductsUrl ||
      '';
    if (!baseUrl && !isManageProductsPage()) {
      return;
    }
    try {
      var u = new URL(baseUrl || window.location.href, window.location.origin);
      if (listingId) {
        u.searchParams.set('variation_id', String(listingId));
      } else {
        u.searchParams.delete('variation_id');
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
    $shell = $shell && $shell.length ? $shell : $manageDetailHost();
    var $overlay = $manageOverlay();
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
        .removeClass('is-open is-closing is-over-merchant')
        .off('transitionend.sutoreStaffManageClose');
      if (!otherStaffOverlaysOpen()) {
        $('body').removeClass('sutore-mp-modal-open');
      }
      $shell.removeData('manage-variation-id');
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
      var shouldSync =
        options.syncUrl === true ||
        (options.skipUrl !== true && options.syncUrl !== false && isManageProductsPage());
      if (shouldSync) {
        syncManageUrl(0, true);
        $('.sutore-mp-staff-manage')
          .not('.sutore-mp-staff-product-detail-host')
          .first()
          .attr('data-open-variation-id', '0');
      }
    };
    $overlay.off('transitionend.sutoreStaffManageClose').one('transitionend.sutoreStaffManageClose', finish);
    window.setTimeout(finish, 280);
  }

  function loadManageModal($shell, listingId) {
    $shell = $shell && $shell.length ? $shell : $manageDetailHost();
    listingId = parseInt(listingId, 10) || 0;
    var $root = $shell.find('.sutore-mp-staff-detail-root');
    var $panels = $shell.find('.sutore-mp-staff-detail-panels');
    var $loading = $shell.find('.sutore-mp-staff-manage-loading');
    $shell.data('manage-variation-id', listingId);
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
        if (parseInt($shell.data('manage-variation-id'), 10) !== listingId) {
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
        if (parseInt($shell.data('manage-variation-id'), 10) !== listingId) {
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

  function openManageModal($shellOrId, listingIdOrOptions, maybeOptions) {
    var $shell;
    var listingId;
    var options;
    if (typeof $shellOrId === 'number' || (typeof $shellOrId === 'string' && /^\d+$/.test($shellOrId))) {
      listingId = parseInt($shellOrId, 10) || 0;
      options = listingIdOrOptions || {};
      $shell = $manageDetailHost();
    } else {
      $shell = $manageDetailHost().length ? $manageDetailHost() : $shellOrId;
      listingId = parseInt(listingIdOrOptions, 10) || 0;
      options = maybeOptions || {};
    }
    if (!listingId || !$manageOverlay().length) {
      return;
    }
    var $overlay = $manageOverlay();
    if (
      window.SutoreMarketplace &&
      SutoreMarketplace.isStaffMerchantOpen &&
      SutoreMarketplace.isStaffMerchantOpen()
    ) {
      $overlay.addClass('is-over-merchant');
    } else {
      $overlay.removeClass('is-over-merchant');
    }
    if (options.headerPreview) {
      setDetailHeader($shell, options.headerPreview);
    }
    revealManageOverlay($shell);
    var shouldSync =
      options.syncUrl === true ||
      (options.skipUrl !== true && options.syncUrl !== false && isManageProductsPage());
    if (shouldSync) {
      syncManageUrl(listingId, !!options.replaceUrl);
    }
    loadManageModal($shell, listingId);
  }

  function isStaffProductOpen() {
    var $overlay = $manageOverlay();
    return $overlay.length > 0 && $overlay.hasClass('is-open') && !$overlay.prop('hidden');
  }

  window.SutoreMarketplace = window.SutoreMarketplace || {};
  SutoreMarketplace.openStaffProduct = function (variationId, options) {
    openManageModal(variationId, options || { syncUrl: false });
  };
  SutoreMarketplace.closeStaffProduct = function (options) {
    closeManageModal($manageDetailHost(), options || { syncUrl: false });
  };
  SutoreMarketplace.isStaffProductOpen = isStaffProductOpen;

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
      var keys = ['search', 'status', 'queue', 'payout_status', 'campaign', 'is_sourcing', 'shipment_type', 'is_imported', 'payout_due', 'sold_from', 'sold_to', 'orderby'];
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
      var manageId = parseInt($shell.data('manage-variation-id'), 10) || 0;
      if ($shell.find('.sutore-mp-staff-manage-overlay').hasClass('is-open') && manageId > 0) {
        u.searchParams.set('variation_id', String(manageId));
      } else {
        u.searchParams.delete('variation_id');
      }
      window.history.replaceState({}, '', u.pathname + u.search + u.hash);
    } catch (err) {
      // ignore
    }
  }

  function manageUrl(baseUrl, listingId, status, queue) {
    try {
      var u = new URL(baseUrl, window.location.origin);
      u.searchParams.set('variation_id', String(listingId));
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
      return baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + 'variation_id=' + listingId;
    }
  }

  function $pageShell($from) {
    var $host = $from.closest('.sutore-mp-staff-product-detail-host');
    if ($host.length) {
      return $host;
    }
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
      payout_due: String($filter.find('[name="payout_due"]').val() || ''),
      sold_from: String($filter.find('[name="sold_from"]').val() || ''),
      sold_to: String($filter.find('[name="sold_to"]').val() || ''),
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
      payout_due: pick('payout_due', 'payoutDue', ''),
      sold_from: pick('sold_from', 'soldFrom', ''),
      sold_to: pick('sold_to', 'soldTo', ''),
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
    $filter.find('[name="payout_due"]').val(state.payout_due || '');
    $filter.find('[name="sold_from"]').val(state.sold_from || '');
    $filter.find('[name="sold_to"]').val(state.sold_to || '');
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
    if (state.payout_due) n++;
    if (state.sold_from) n++;
    if (state.sold_to) n++;
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
      return esc(item.campaign_status_label || t('campaignActiveTag', 'On campaign'));
    }
    if (status === 'offer') {
      return esc(item.campaign_status_label || t('campaignOfferTag', 'Campaign offer'));
    }
    return '—';
  }

  function renderPreOrderCell(item) {
    if (item.is_pre_order || item.is_sourcing) {
      return esc(t('preOrderProduct', 'Pre-order'));
    }
    return '—';
  }

  function renderImportedCell(item) {
    if (item.is_imported) {
      return esc(t('importedProduct', 'Imported'));
    }
    return '—';
  }

  function renderStatusCell(item) {
    var status = String(item.listing_status || item.fulfillment_status || '').trim();
    var label = String(item.listing_status_label || item.status_label || '').trim();
    if (!status && (!label || label === '—')) {
      return '—';
    }
    var modifier = status ? 'is-status-' + status.replace(/_/g, '-') : 'is-status-unknown';
    return (
      '<span class="sutore-mp-tag ' +
      modifier +
      '">' +
      esc(label && label !== '—' ? label : status) +
      '</span>'
    );
  }

  function renderPaymentStatusCell(item) {
    var label =
      item.payout_status_label ||
      item.payment_status_display ||
      t('payoutNotCreated', 'Not created yet');
    var html = esc(label);
    if (item.invoice_has_error) {
      html +=
        ' <span class="sutore-mp-tag is-status-error">' +
        esc(t('invoiceError', 'Invoice error')) +
        '</span>';
    } else if (item.invoice_summary) {
      html +=
        '<br><span class="sutore-mp-staff-sub">' + esc(item.invoice_summary) + '</span>';
    }
    return html;
  }

  function renderListingShippingCell(item) {
    var parts = [t('shippingStandard', 'Standard')];
    if (item.fast_shipment) {
      parts.push(t('expressShipping', 'Fast / Express'));
    }
    if (item.has_invoice) {
      parts.push(t('internationalShipping', 'International'));
    }
    return esc(parts.join(', '));
  }

  function renderShipmentTypeCell(item) {
    var label = String(item.order_shipment_type_label || '').trim();
    var type = String(item.order_shipment_type || '').trim();
    if ((!label || label === '—') && !type) {
      return '—';
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
      '<div class="sutore-mp-staff-bulk-bar" hidden>' +
      '<span class="sutore-mp-staff-bulk-count" aria-live="polite"></span>' +
      '<label class="sutore-mp-staff-bulk-action-label screen-reader-text" for="sutore-mp-staff-bulk-action">' +
      esc(t('bulkActions', 'Bulk actions')) +
      '</label>' +
      '<select id="sutore-mp-staff-bulk-action" class="sutore-mp-input sutore-mp-staff-bulk-action" disabled>' +
      '<option value="">' +
      esc(t('bulkActions', 'Bulk actions')) +
      '</option></select>' +
      '<button type="button" class="wp-element-button sutore-mp-staff-bulk-apply" disabled>' +
      esc(t('apply', 'Apply')) +
      '</button>' +
      '</div>' +
      '<div class="sutore-mp-staff-table-wrap"><table class="sutore-mp-staff-table"><thead><tr>' +
      '<th class="sutore-mp-staff-col-select">' +
      '<label class="sutore-mp-staff-select-all-wrap">' +
      '<input type="checkbox" class="sutore-mp-staff-select-all" />' +
      '<span class="screen-reader-text">' +
      esc(t('selectAll', 'Select all')) +
      '</span></label></th>' +
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
      esc(t('customerShipping', 'Customer shipping')) +
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
        '<tr><td colspan="13">' +
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
        var listingId = item.variation_id || item.id;
        html +=
          '<tr class="sutore-mp-staff-list-row" data-variation-id="' +
          esc(String(listingId)) +
          '" data-actions="' +
          encodeURIComponent(JSON.stringify(item.actions || {})) +
          '"><td class="sutore-mp-staff-col-select">' +
          '<input type="checkbox" class="sutore-mp-staff-row-select" value="' +
          esc(String(listingId)) +
          '" /></td><td><div class="sutore-mp-staff-product-cell">' +
          thumbHtml +
          '<div class="sutore-mp-staff-product-info"><strong>' +
          esc(item.product_title || '') +
          '</strong><span class="sutore-mp-staff-sub">' +
          esc(idLine) +
          '</span></div></div></td><td>' +
          renderOrderCell(item) +
          '</td><td>' +
          merchantDeeplinkHtml(item.merchant_name || '', item.merchant_id) +
          '</td><td>' +
          esc(item.asking_display || '—') +
          '</td><td>' +
          renderStatusCell(item) +
          '</td><td>' +
          renderListingShippingCell(item) +
          '</td><td>' +
          renderShipmentTypeCell(item) +
          '</td><td>' +
          renderCampaignCell(item) +
          '</td><td>' +
          renderPreOrderCell(item) +
          '</td><td>' +
          renderImportedCell(item) +
          '</td><td>' +
          renderPaymentStatusCell(item) +
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

  function selectedListRows($root) {
    var rows = [];
    $root.find('.sutore-mp-staff-row-select:checked').each(function () {
      var $tr = $(this).closest('tr');
      var actions = {};
      try {
        actions = JSON.parse(decodeURIComponent($tr.attr('data-actions') || '') || '{}');
      } catch (e) {
        actions = {};
      }
      rows.push({
        id: parseInt($(this).val(), 10) || 0,
        actions: actions
      });
    });
    return rows;
  }

  function intersectBulkWorkflows(rows) {
    if (!rows.length) {
      return [];
    }
    return BULK_WORKFLOWS.filter(function (workflow) {
      var def = findActionDef(workflow);
      if (!def) {
        return false;
      }
      for (var i = 0; i < rows.length; i++) {
        if (!rows[i].actions || !rows[i].actions[def.key]) {
          return false;
        }
      }
      return true;
    });
  }

  function refreshBulkBar($root) {
    var $bar = $root.find('.sutore-mp-staff-bulk-bar');
    if (!$bar.length) {
      return;
    }
    var rows = selectedListRows($root);
    var count = rows.length;
    var $selectAll = $root.find('.sutore-mp-staff-select-all');
    var totalBoxes = $root.find('.sutore-mp-staff-row-select').length;
    var $action = $bar.find('.sutore-mp-staff-bulk-action');
    var $apply = $bar.find('.sutore-mp-staff-bulk-apply');
    var workflows = count ? intersectBulkWorkflows(rows) : [];

    if (totalBoxes) {
      $selectAll.prop('checked', count > 0 && count === totalBoxes);
      $selectAll.prop('indeterminate', count > 0 && count < totalBoxes);
    } else {
      $selectAll.prop('checked', false).prop('indeterminate', false);
    }

    if (!count || !workflows.length) {
      $bar.attr('hidden', true);
      $action.prop('disabled', true).html(
        '<option value="">' + esc(t('bulkActions', 'Bulk actions')) + '</option>'
      );
      $apply.prop('disabled', true);
      return;
    }

    $bar.removeAttr('hidden');
    $bar.find('.sutore-mp-staff-bulk-count').text(
      t('selectedCount', '%d selected').replace('%d', String(count))
    );

    var options =
      '<option value="">' + esc(t('bulkActions', 'Bulk actions')) + '</option>';
    workflows.forEach(function (workflow) {
      var def = findActionDef(workflow);
      if (!def) {
        return;
      }
      options +=
        '<option value="' +
        esc(def.value) +
        '">' +
        esc(actionLabel(def)) +
        '</option>';
    });
    $action.html(options).prop('disabled', false);
    $apply.prop('disabled', true);
  }

  function postBulkAction(ids, workflowAction, extra) {
    var body = {
      ids: ids,
      workflow_action: workflowAction
    };
    extra = extra || {};
    Object.keys(extra).forEach(function (key) {
      body[key] = extra[key];
    });
    return $.ajax({
      url: (cfg.restUrl || '') + 'fulfillments/bulk-actions',
      method: 'POST',
      contentType: 'application/json',
      dataType: 'json',
      headers: { 'X-WP-Nonce': cfg.restNonce || '' },
      data: JSON.stringify(body)
    });
  }

  function showToast(message, type) {
    if (window.SutoreMarketplace && typeof SutoreMarketplace.showToast === 'function') {
      SutoreMarketplace.showToast(message, type);
      return;
    }
    window.alert(message);
  }

  function runBulkAction($root, workflowAction) {
    var rows = selectedListRows($root);
    var ids = rows.map(function (r) {
      return r.id;
    }).filter(Boolean);
    if (!ids.length || !workflowAction) {
      return;
    }
    if (intersectBulkWorkflows(rows).indexOf(workflowAction) === -1) {
      showToast(t('noCommonBulkActions', 'No common actions for this selection'), 'error');
      refreshBulkBar($root);
      return;
    }

    var def = findActionDef(workflowAction);
    var title = def ? actionLabel(def) : t('bulkActions', 'Bulk actions');
    var $apply = $root.find('.sutore-mp-staff-bulk-apply');

    function finishOk(msg) {
      showToast(msg, 'success');
      loadListRoot($root, readListState($root));
    }

    function finishFail(xhr) {
      showToast(actionErrorMessage(null, xhr), 'error');
      $apply.prop('disabled', false).removeClass('is-busy');
      refreshBulkBar($root);
    }

    if (workflowAction === 'send_campaign_offer') {
      withSelectOptions([
        {
          name: 'campaign_id',
          label: t('selectCampaign', 'Campaign'),
          type: 'select',
          required: true,
          optionsKey: 'sendable_campaigns',
          options: []
        }
      ])
        .done(function (resolvedFields) {
          if (
            resolvedFields.some(function (f) {
              return f.name === 'campaign_id' && !(f.options && f.options.length);
            })
          ) {
            showToast(
              t(
                'noSendableCampaigns',
                'No sendable campaigns found. Create one with start and end dates first.'
              ),
              'error'
            );
            return;
          }
          showFormConfirm({
            title: title,
            text: t(
              'sendCampaignOfferBulkConfirm',
              'Choose a campaign to send an offer to the selected products.'
            ),
            confirmLabel: t('apply', 'Apply'),
            requiredMessage: t('fieldRequired', 'This field is required.'),
            fields: resolvedFields,
            onConfirm: function (values, ui) {
              var campaignId = parseInt((values && values.campaign_id) || 0, 10) || 0;
              if (!campaignId) {
                if (ui && ui.setError) {
                  ui.setError(t('selectCampaignPlaceholder', 'Select a campaign…'));
                }
                return false;
              }
              if (ui && ui.setBusy) {
                ui.setBusy(true);
              }
              $apply.prop('disabled', true).addClass('is-busy');
              postCampaignOffer(campaignId, ids)
                .done(function (res) {
                  if (!(res && res.success)) {
                    var failMsg = actionErrorMessage(res);
                    if (ui && ui.setError) {
                      ui.setError(failMsg);
                    } else {
                      showToast(failMsg, 'error');
                    }
                    return;
                  }
                  if (ui && ui.close) {
                    ui.close();
                  }
                  finishOk(
                    (res.data && res.data.message) ||
                      t('campaignOfferSent', 'Campaign offer sent.')
                  );
                })
                .fail(function (xhr) {
                  var msg = actionErrorMessage(null, xhr);
                  if (ui && ui.setError) {
                    ui.setError(msg);
                  } else {
                    finishFail(xhr);
                  }
                })
                .always(function () {
                  if (ui && ui.setBusy) {
                    ui.setBusy(false);
                  }
                  $apply.prop('disabled', false).removeClass('is-busy');
                  refreshBulkBar($root);
                });
              return false;
            }
          });
        })
        .fail(function (xhr) {
          showToast(actionErrorMessage(null, xhr), 'error');
        });
      return;
    }

    if (workflowAction === 'mark_payout_paid') {
      showFormConfirm({
        title: title,
        text: t(
          'bulkMarkPaidRef',
          'Optional. The same payment reference is stored on every selected payout.'
        ),
        confirmLabel: t('apply', 'Apply'),
        fields: [
          {
            name: 'payment_ref',
            label: t('paymentRef', 'Payment reference (EFT/receipt)'),
            type: 'text',
            required: false
          }
        ],
        onConfirm: function (values, ui) {
          if (ui && ui.setBusy) {
            ui.setBusy(true);
          }
          $apply.prop('disabled', true).addClass('is-busy');
          postBulkAction(ids, workflowAction, {
            payment_ref: String((values && values.payment_ref) || '')
          })
            .done(function (res) {
              if (ui && ui.close) {
                ui.close();
              }
              finishOk(
                (res && res.data && res.data.message) ||
                  (res && res.message) ||
                  t('updated', 'Updated.')
              );
            })
            .fail(function (xhr) {
              var msg = actionErrorMessage(null, xhr);
              if (ui && ui.setError) {
                ui.setError(msg);
              } else {
                finishFail(xhr);
              }
            })
            .always(function () {
              if (ui && ui.setBusy) {
                ui.setBusy(false);
              }
              $apply.prop('disabled', false).removeClass('is-busy');
              refreshBulkBar($root);
            });
          return false;
        }
      });
      return;
    }

    var text = t(
      'bulkConfirm',
      'Apply “%1$s” to %2$d selected products?'
    )
      .replace('%1$s', title)
      .replace('%2$d', String(ids.length));
    if (def && def.confirmTextKey) {
      text =
        t(def.confirmTextKey, def.confirmTextFallback) +
        '\n\n' +
        t('bulkConfirmCount', '%d products will be updated.').replace('%d', String(ids.length));
    }

    showConfirm(title, text, t('apply', 'Apply'), function () {
      $apply.prop('disabled', true).addClass('is-busy');
      postBulkAction(ids, workflowAction)
        .done(function (res) {
          var msg =
            (res && res.data && res.data.message) ||
            (res && res.message) ||
            t('updated', 'Updated.');
          finishOk(msg);
        })
        .fail(finishFail);
    });
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
    if (state.payout_due) {
      params.payout_due = '1';
    }
    if (state.sold_from) {
      params.sold_from = state.sold_from;
    }
    if (state.sold_to) {
      params.sold_to = state.sold_to;
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
        $root.data('payoutDue', state.payout_due);
        $root.data('soldFrom', state.sold_from);
        $root.data('soldTo', state.sold_to);
        $root.data('orderby', state.orderby);
        $root.data('page', state.page);
        syncFilterFields($shell, state);
        updateListBadges($shell, state);
        syncListUrl(state.baseUrl, state);
        $root.attr('aria-busy', 'false').html(renderList(res.data, state));
        $chrome.prop('hidden', false);

        var openId = parseInt($shell.attr('data-open-variation-id'), 10) || 0;
        if (openId > 0) {
          $shell.attr('data-open-variation-id', '0');
          openManageModal(openId, { replaceUrl: true, syncUrl: true });
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
    var $list = $manageListRoot();
    if ($list.length) {
      loadListRoot($list);
    }
  });

  $(document).on('sutore-mp:staff-listing-created sutore-mp-bulk:committed', function () {
    var $list = $manageListRoot();
    if ($list.length) {
      loadListRoot($list, readListState($list));
    }
  });

  $(document).on('submit', '.sutore-mp-staff-listing-commission', function (e) {
    e.preventDefault();
    var $form = $(this);
    var $shell = $form.closest('.sutore-mp-staff-product-detail-host, .sutore-mp-staff-manage');
    var id = parseInt($form.data('listing-id'), 10) || 0;
    if (!id) {
      return;
    }
    $form.find('button[type="submit"]').prop('disabled', true).text(t('saving', 'Saving…'));
    postAction(id, {
      workflow_action: 'set_listing_commission',
      commission_percent: String($form.find('[name="commission_percent"]').val() || '')
    })
      .done(function (res) {
        if (!(res && res.success)) {
          showToast(actionErrorMessage(res), 'error');
          return;
        }
        showToast((res.data && res.data.message) || t('saved', 'Saved'), 'success');
        var $root = $manageListRoot();
        if ($root.length) {
          loadListRoot($root, readListState($root));
        }
        loadManageModal($shell.length ? $shell : $manageDetailHost(), id);
      })
      .fail(function (xhr) {
        showToast(actionErrorMessage(null, xhr), 'error');
      })
      .always(function () {
        $form.find('button[type="submit"]').prop('disabled', false).text(t('save', 'Save'));
      });
  });

  $(document).on('click', '.sutore-mp-staff-open-manage', function (e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.which === 2) {
      return;
    }
    e.preventDefault();
    var $btn = $(this);
    var id =
      parseInt($btn.attr('data-variation-id'), 10) ||
      parseInt($btn.data('variation-id'), 10) ||
      0;
    if (!id) {
      return;
    }
    openManageModal(id, {
      syncUrl: isManageProductsPage(),
      headerPreview: {
        product_title: $btn.attr('data-product-title') || $btn.text() || '',
        thumbnail: $btn.attr('data-thumbnail') || '',
        parent_product_id: parseInt($btn.attr('data-parent-product-id'), 10) || 0,
        variation_id: id,
        listing_status_label: $btn.attr('data-status-label') || ''
      }
    });
  });

  $(document).on('click', '.sutore-mp-staff-manage-close', function (e) {
    e.preventDefault();
    closeManageModal($manageDetailHost(), { syncUrl: isManageProductsPage() });
  });

  $(document).on('change', '.sutore-mp-staff-list-root .sutore-mp-staff-row-select', function () {
    refreshBulkBar($pageShell($(this)).find('.sutore-mp-staff-list-root'));
  });

  $(document).on('change', '.sutore-mp-staff-list-root .sutore-mp-staff-select-all', function () {
    var $root = $pageShell($(this)).find('.sutore-mp-staff-list-root');
    var checked = $(this).prop('checked');
    $root.find('.sutore-mp-staff-row-select').prop('checked', checked);
    refreshBulkBar($root);
  });

  $(document).on('change', '.sutore-mp-staff-list-root .sutore-mp-staff-bulk-action', function () {
    var $root = $pageShell($(this)).find('.sutore-mp-staff-list-root');
    var hasAction = !!String($(this).val() || '');
    $root.find('.sutore-mp-staff-bulk-apply').prop('disabled', !hasAction);
  });

  $(document).on('click', '.sutore-mp-staff-list-root .sutore-mp-staff-bulk-apply', function (e) {
    e.preventDefault();
    var $root = $pageShell($(this)).find('.sutore-mp-staff-list-root');
    var action = String($root.find('.sutore-mp-staff-bulk-action').val() || '');
    if (!action) {
      return;
    }
    runBulkAction($root, action);
  });

  $(document).on('click', '.sutore-mp-staff-product-detail-overlay', function (e) {
    if (e.target !== this) {
      return;
    }
    closeManageModal($manageDetailHost(), { syncUrl: isManageProductsPage() });
  });

  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') {
      return;
    }
    if ($('.sutore-mp-confirm').length) {
      return;
    }
    if (
      window.SutoreMarketplace &&
      SutoreMarketplace.isStaffMerchantOpen &&
      SutoreMarketplace.isStaffMerchantOpen() &&
      !$manageOverlay().hasClass('is-over-merchant')
    ) {
      return;
    }
    if (
      window.SutoreMarketplace &&
      SutoreMarketplace.isStaffOrderOpen &&
      SutoreMarketplace.isStaffOrderOpen()
    ) {
      return;
    }
    if (isStaffProductOpen()) {
      closeManageModal($manageDetailHost(), { syncUrl: isManageProductsPage() });
      e.stopImmediatePropagation();
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
  });

  $(document).on('click', '.sutore-mp-staff-manage .sutore-mp-staff-detail-tabs .sutore-mp-manage-tab', function (e) {
    if ($(this).closest('.sutore-mp-staff-merchant-detail-host').length) {
      return;
    }
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
    $shell.find('.sutore-mp-staff-manage-filter input[type="date"]').val('');
    $shell.find('.sutore-mp-staff-manage-filter [name="status"]').prop('disabled', false);
    SutoreMarketplace.closeListOverlays($shell);
    loadListRoot($root, collectFilterState($shell));
  });

  $(document).on('click', '.sutore-mp-staff-export-csv', function (e) {
    e.preventDefault();
    var $shell = $pageShell($(this));
    var $root = $shell.find('.sutore-mp-staff-list-root');
    var state = readListState($root, collectFilterState($shell));
    var $btn = $(this);
    if ($btn.prop('disabled')) {
      return;
    }
    var params = {
      export: 'csv',
      orderby: state.orderby || 'id_desc'
    };
    if (state.search) params.search = state.search;
    if (state.queue) params.queue = state.queue;
    else if (state.status) params.status = state.status;
    if (state.payout_status) params.payout_status = state.payout_status;
    if (state.campaign) params.campaign = state.campaign;
    if (state.is_sourcing) params.is_sourcing = state.is_sourcing;
    if (state.shipment_type) params.shipment_type = state.shipment_type;
    if (state.is_imported) params.is_imported = state.is_imported;
    if (state.payout_due) params.payout_due = '1';
    if (state.sold_from) params.sold_from = state.sold_from;
    if (state.sold_to) params.sold_to = state.sold_to;

    $btn.prop('disabled', true).addClass('is-busy');
    fetchList(params)
      .done(function (res) {
        var data = res && res.data ? res.data : {};
        var csv = data.csv || '';
        if (!csv || !(data.count > 0)) {
          showToast(t('exportEmpty', 'No payout rows to export for this filter.'), 'error');
          return;
        }
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url = window.URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = data.filename || 'sutore-payouts.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
      })
      .fail(function (xhr) {
        showToast(actionErrorMessage(null, xhr), 'error');
      })
      .always(function () {
        $btn.prop('disabled', false).removeClass('is-busy');
      });
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
    var id = parseInt($btn.data('variation-id'), 10) || 0;
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
    var id = parseInt($shell.data('manage-variation-id'), 10) || 0;
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
    var id = parseInt($shell.data('manage-variation-id'), 10) || 0;
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
    var id = parseInt($shell.data('manage-variation-id'), 10) || 0;
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
    if (def.value === 'swap') {
      var $opt = $form.find('select[name="new_variation_id"] option:selected');
      var sameParent = String($opt.attr('data-same-parent') || '1') !== '0';
      if (!sameParent && !(collected.values.staff_note || '').trim()) {
        $error.text(
          t(
            'differentProductNoteRequired',
            'A staff note is required when replacing with a different product.'
          )
        );
        return;
      }
    }
    $error.text('');
    $btn.addClass('is-loading').prop('disabled', true);

    function afterFormSuccess() {
      var $root = $manageListRoot();
      var $modalShell = $manageDetailHost();
      if (def.value === 'delete_listing') {
        closeManageModal($modalShell, { syncUrl: isManageProductsPage() });
        if ($root.length) {
          loadListRoot($root, readListState($root));
        }
        return;
      }
      if ($root.length) {
        loadListRoot($root, readListState($root));
      }
      loadManageModal($modalShell, id);
    }

    if (def.value === 'send_campaign_offer') {
      var campaignId = parseInt(collected.values.campaign_id || 0, 10) || 0;
      if (!campaignId) {
        $error.text(t('selectCampaignPlaceholder', 'Select a campaign…'));
        $btn.removeClass('is-loading').prop('disabled', false);
        return;
      }
      postCampaignOffer(campaignId, [id])
        .done(function (res) {
          if (!(res && res.success)) {
            $error.text(actionErrorMessage(res));
            return;
          }
          showToast(
            (res.data && res.data.message) || t('campaignOfferSent', 'Campaign offer sent.'),
            'success'
          );
          hideActionForm($shell);
          afterFormSuccess();
        })
        .fail(function (xhr) {
          $error.text(actionErrorMessage(null, xhr));
        })
        .always(function () {
          $btn.removeClass('is-loading').prop('disabled', false);
        });
      return;
    }

    postAction(id, buildBodyFromValues(def.value, collected.values))
      .done(function (res) {
        if (!(res && res.success)) {
          $error.text(actionErrorMessage(res));
          return;
        }
        afterFormSuccess();
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
