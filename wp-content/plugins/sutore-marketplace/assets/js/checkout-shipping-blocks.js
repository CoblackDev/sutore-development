/**
 * WooCommerce Blocks checkout: force Store API customer update when country/state changes.
 *
 * Blocks can update local customer data without immediately pushing to /cart/update-customer,
 * leaving shipping rates stuck on the previous country (e.g. TR options while UI shows Kıbrıs).
 */
(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    if (!document.querySelector('.wp-block-woocommerce-checkout, .wc-block-checkout')) {
      return;
    }
    if (!window.wp || !wp.data || typeof wp.data.subscribe !== 'function') {
      return;
    }

    var CART = 'wc/store/cart';
    var timer = null;
    var lastKey = '';
    var pushing = false;

    function customerData() {
      try {
        return wp.data.select(CART).getCustomerData() || {};
      } catch (e) {
        return {};
      }
    }

    function destinationKey(data) {
      var shipping = data.shippingAddress || {};
      var billing = data.billingAddress || {};
      return [
        String(shipping.country || '').toUpperCase(),
        String(shipping.state || '').toUpperCase(),
        String(billing.country || '').toUpperCase(),
        String(billing.state || '').toUpperCase()
      ].join('|');
    }

    function pushDestination() {
      if (pushing) {
        return;
      }

      var data = customerData();
      var shipping = Object.assign({}, data.shippingAddress || {});
      var billing = Object.assign({}, data.billingAddress || {});

      if (!shipping.country && !billing.country) {
        return;
      }

      // Keep billing country/state aligned when they lagged behind shipping (common Blocks race).
      if (shipping.country && shipping.country !== billing.country) {
        billing.country = shipping.country;
        billing.state = shipping.state || '';
      }

      pushing = true;
      try {
        var result = wp.data.dispatch(CART).updateCustomerData(
          {
            shipping_address: shipping,
            billing_address: billing
          },
          false
        );
        Promise.resolve(result).finally(function () {
          pushing = false;
          lastKey = destinationKey(customerData());
        });
      } catch (e) {
        pushing = false;
      }
    }

    function schedulePush() {
      var key = destinationKey(customerData());
      if (!key || key === lastKey || pushing) {
        return;
      }
      window.clearTimeout(timer);
      timer = window.setTimeout(pushDestination, 120);
    }

    lastKey = destinationKey(customerData());
    wp.data.subscribe(schedulePush);

    // Also catch native select changes if React store lags.
    document.addEventListener(
      'change',
      function (event) {
        var target = event.target;
        if (!(target instanceof HTMLSelectElement || target instanceof HTMLInputElement)) {
          return;
        }
        var id = String(target.id || '');
        var name = String(target.name || '');
        var hay = (id + ' ' + name).toLowerCase();
        if (hay.indexOf('country') === -1 && hay.indexOf('state') === -1) {
          return;
        }
        window.clearTimeout(timer);
        timer = window.setTimeout(pushDestination, 180);
      },
      true
    );
  });
})();
