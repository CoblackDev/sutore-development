(function ($) {
  'use strict';

  var config = window.SutoreMarketplaceContracts || {};
  var BILLING_FIELDS = [
    'billing_first_name',
    'billing_last_name',
    'billing_phone',
    'billing_email',
    'billing_address_1',
    'billing_address_2',
    'billing_postcode',
    'billing_city',
    'billing_state',
    'billing_country',
    'shipping_first_name',
    'shipping_last_name',
    'shipping_phone',
    'shipping_address_1',
    'shipping_address_2',
    'shipping_postcode',
    'shipping_city',
    'shipping_state',
    'shipping_country',
    'ship-to-different-address-checkbox'
  ];

  function isBlockCheckout() {
    return !!config.blockCheckout;
  }

  function blockFieldId(name) {
    if (name.indexOf('billing_') === 0) {
      return 'billing-' + name.slice('billing_'.length);
    }
    if (name.indexOf('shipping_') === 0) {
      return 'shipping-' + name.slice('shipping_'.length);
    }
    return '';
  }

  function fieldSelectorList() {
    var parts = [];

    BILLING_FIELDS.forEach(function (name) {
      parts.push('#' + name);
      parts.push('[name="' + name + '"]');

      var hyphenId = blockFieldId(name);
      if (hyphenId) {
        parts.push('#' + hyphenId);
      }
    });

    parts.push('#email');
    parts.push('[name="contact_email"]');
    parts.push('[autocomplete*="email"]');

    return parts.join(', ');
  }

  function fieldEl(name) {
    var $el = $('#' + name);
    if ($el.length) {
      return $el.first();
    }

    var hyphenId = blockFieldId(name);
    if (hyphenId) {
      $el = $('#' + hyphenId);
      if ($el.length) {
        return $el.first();
      }
    }

    $el = $('[name="' + name + '"]');
    if ($el.length) {
      return $el.first();
    }

    if (name === 'billing_email' || name === 'email' || name === 'contact_email') {
      $el = $('#email, [name="contact_email"], [autocomplete="section-contact contact email"]');
      if ($el.length) {
        return $el.first();
      }
    }

    return $();
  }

  function fieldValue(name) {
    var $el = fieldEl(name);
    if (!$el.length) {
      return '';
    }

    if ($el.is('select')) {
      var $opt = $el.find('option:selected');
      var label = ($opt.text() || '').trim();
      var val = String($el.val() || '').trim();
      if (label && label !== val && $opt.val() !== '') {
        return label;
      }
      return val;
    }

    return String($el.val() || '').trim();
  }

  function hasShippingFields() {
    return fieldEl('shipping_first_name').length > 0 || fieldEl('shipping_address_1').length > 0;
  }

  function useShippingAddress() {
    // Block checkout: shipping section is the delivery address.
    if (isBlockCheckout()) {
      return hasShippingFields();
    }

    var $ship = fieldEl('ship-to-different-address-checkbox');
    return $ship.length > 0 && $ship.is(':checked');
  }

  function billingName() {
    if (useShippingAddress()) {
      var shipName = [fieldValue('shipping_first_name'), fieldValue('shipping_last_name')]
        .filter(Boolean)
        .join(' ');
      if (shipName) {
        return shipName;
      }
    }

    return [fieldValue('billing_first_name'), fieldValue('billing_last_name')]
      .filter(Boolean)
      .join(' ');
  }

  function phoneValue() {
    if (useShippingAddress()) {
      var shipPhone = fieldValue('shipping_phone');
      if (shipPhone) {
        return shipPhone;
      }
    }

    return fieldValue('billing_phone') || fieldValue('shipping_phone');
  }

  function emailValue() {
    return (
      fieldValue('billing_email') ||
      fieldValue('email') ||
      fieldValue('contact_email')
    );
  }

  function addressFromPrefix(prefix) {
    var parts = [
      fieldValue(prefix + '_address_1'),
      fieldValue(prefix + '_address_2'),
      fieldValue(prefix + '_postcode'),
      fieldValue(prefix + '_state'),
      fieldValue(prefix + '_city'),
      fieldValue(prefix + '_country')
    ];

    return parts.filter(Boolean).join(', ');
  }

  function deliveryAddress() {
    if (useShippingAddress()) {
      var shipping = addressFromPrefix('shipping');
      if (shipping) {
        return shipping;
      }
    }

    return addressFromPrefix('billing');
  }

  function syncContractFields() {
    var $dialog = $('#sutore-contracts-dialog');
    var $scope = $dialog.length ? $dialog : $(document);

    $scope.find('.billing-name').text(billingName());
    $scope.find('.billing-phone').text(phoneValue());
    $scope.find('.billing-email').text(emailValue());
    $scope.find('.shipping-address').text(deliveryAddress());
  }

  function getDialog() {
    return document.getElementById('sutore-contracts-dialog');
  }

  function blockCheckbox() {
    return document.querySelector('input[id*="contracts-accepted"], input[name*="contracts-accepted"]');
  }

  function getContractsLabelSpan() {
    var field = blockCheckbox();
    if (field) {
      var label = field.closest('label');
      if (label) {
        return label.querySelector('.wc-block-components-checkbox__label') || label;
      }
    }

    return document.querySelector('.sutore-contracts-checkbox .sutore-contracts-copy');
  }

  function openDialog(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    var dialog = getDialog();
    if (!dialog) {
      return;
    }

    syncContractFields();

    if (typeof dialog.showModal === 'function') {
      try {
        if (!dialog.open) {
          dialog.showModal();
        }
        return;
      } catch (error) {
        // Fallback below for browsers/themes blocking native dialog modal.
      }
    }

    dialog.setAttribute('open', 'open');
    dialog.classList.add('sutore-contracts-dialog--open');
    document.body.classList.add('sutore-contracts-dialog-open');
  }

  function closeDialog() {
    var dialog = getDialog();
    if (!dialog) {
      return;
    }

    if (typeof dialog.close === 'function' && dialog.open) {
      dialog.close();
    }

    dialog.removeAttribute('open');
    dialog.classList.remove('sutore-contracts-dialog--open');
    document.body.classList.remove('sutore-contracts-dialog-open');
  }

  function acceptContracts(event) {
    if (event) {
      event.preventDefault();
    }

    $('#sutore-contracts-check').prop('checked', true).trigger('change');

    var blockField = blockCheckbox();
    if (blockField) {
      blockField.checked = true;
      blockField.dispatchEvent(new Event('change', { bubbles: true }));
      blockField.dispatchEvent(new Event('input', { bubbles: true }));
    }

    closeDialog();
  }

  function bindDialog() {
    var dialog = getDialog();
    if (!dialog) {
      return;
    }

    $(dialog)
      .off('click.sutoreContractsClose', '[data-sutore-contracts-close]')
      .on('click.sutoreContractsClose', '[data-sutore-contracts-close]', closeDialog)
      .off('click.sutoreContractsAccept', '[data-sutore-contracts-accept]')
      .on('click.sutoreContractsAccept', '[data-sutore-contracts-accept]', acceptContracts)
      .off('click.sutoreContractsBackdrop')
      .on('click.sutoreContractsBackdrop', function (event) {
        if (event.target === dialog) {
          closeDialog();
        }
      });

    document.removeEventListener('keydown', handleEscape, true);
    document.addEventListener('keydown', handleEscape, true);
  }

  function handleEscape(event) {
    if (event.key !== 'Escape') {
      return;
    }

    var dialog = getDialog();
    if (dialog && (dialog.open || dialog.classList.contains('sutore-contracts-dialog--open'))) {
      closeDialog();
    }
  }

  function bindFieldSync() {
    var selectors =
      fieldSelectorList() +
      ', form.checkout :input[name^="billing_"], form.checkout :input[name^="shipping_"]' +
      ', .wp-block-woocommerce-checkout :input, .wc-block-checkout :input';

    $(document.body)
      .off(
        'input.sutoreContracts change.sutoreContracts keyup.sutoreContracts blur.sutoreContracts select2:select.sutoreContracts select2:clear.sutoreContracts',
        selectors
      )
      .on(
        'input.sutoreContracts change.sutoreContracts keyup.sutoreContracts blur.sutoreContracts select2:select.sutoreContracts select2:clear.sutoreContracts',
        selectors,
        syncContractFields
      );
  }

  function injectBlockCheckoutLink() {
    if (!config.blockCheckout) {
      return;
    }

    var labelSpan = getContractsLabelSpan();
    if (!labelSpan || labelSpan.querySelector('.sutore-contracts-open')) {
      return;
    }

    var title = config.contractsTitle || 'Contracts';
    var text = labelSpan.textContent || '';
    if (text.indexOf(title) === -1) {
      return;
    }

    var safeTitle = String(title)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
    labelSpan.innerHTML = text.replace(
      title,
      '<a class="sutore-contracts-open" href="#" role="button">' + safeTitle + '</a>'
    );
  }

  function bindOpenLinks() {
    document.removeEventListener('click', handleOpenLink, true);
    document.addEventListener('click', handleOpenLink, true);
  }

  function handleOpenLink(event) {
    var link = event.target.closest('.sutore-contracts-open');
    if (!link) {
      return;
    }

    openDialog(event);
  }

  function refreshContractsUi() {
    injectBlockCheckoutLink();
    bindDialog();
    bindOpenLinks();
    bindFieldSync();
    syncContractFields();
  }

  function observeBlockCheckout() {
    if (!config.blockCheckout) {
      return;
    }

    var root = document.querySelector('.wp-block-woocommerce-checkout, .wc-block-checkout');
    if (!root || root.dataset.sutoreContractsObserved === '1') {
      return;
    }

    root.dataset.sutoreContractsObserved = '1';

    var observer = new MutationObserver(function () {
      refreshContractsUi();
    });

    observer.observe(root, { childList: true, subtree: true });
  }

  function initContracts() {
    if (!getDialog()) {
      return;
    }

    refreshContractsUi();
    observeBlockCheckout();
  }

  $(initContracts);
  $(document.body).on('updated_checkout', refreshContractsUi);

  if (config.blockCheckout) {
    window.setTimeout(refreshContractsUi, 300);
    window.setTimeout(refreshContractsUi, 1200);
  }
})(jQuery);
