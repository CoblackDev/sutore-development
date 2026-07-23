(function ($) {
  'use strict';

  var timer = null;

  function triggerCheckoutRefresh() {
    if (typeof wc_checkout_params === 'undefined') {
      return;
    }
    window.clearTimeout(timer);
    timer = window.setTimeout(function () {
      $(document.body).trigger('update_checkout');
    }, 50);
  }

  $(function () {
    if (!$('form.checkout').length || typeof wc_checkout_params === 'undefined') {
      return;
    }

    var selectors = [
      '#billing_country',
      '#shipping_country',
      '#billing_state',
      '#shipping_state',
      '#billing_states',
      '#shipping_states'
    ].join(', ');

    $(document.body).on('change', selectors, triggerCheckoutRefresh);
    $(document.body).on('change.select2 select2:select', selectors, triggerCheckoutRefresh);
    $(document.body).on('country_to_state_changed', triggerCheckoutRefresh);
  });
})(jQuery);
