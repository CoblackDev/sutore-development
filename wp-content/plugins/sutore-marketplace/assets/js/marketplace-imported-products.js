(function () {
  'use strict';

  var cfg = window.SutoreMarketplaceImportedProducts || {};
  var i18n = cfg.i18n || {};
  var form = document.querySelector('.sutore-mp-imported-products__form');

  if (!form) {
    return;
  }

  var input = form.querySelector('#sutore_mp_imported_variation_ids');
  var submit = form.querySelector('[type="submit"]');
  var result = form.querySelector('.sutore-mp-imported-products__result');

  function variationIds() {
    return (input.value || '')
      .split(/\r?\n/)
      .map(function (value) { return parseInt(value.trim(), 10) || 0; })
      .filter(function (value, index, values) {
        return value > 0 && values.indexOf(value) === index;
      });
  }

  function showResult(message, skipped, isError) {
    result.replaceChildren();
    result.classList.toggle('notice', true);
    result.classList.toggle('notice-error', isError);
    result.classList.toggle('notice-success', !isError);

    var paragraph = document.createElement('p');
    paragraph.textContent = message || '';
    result.appendChild(paragraph);

    if (Array.isArray(skipped) && skipped.length) {
      var list = document.createElement('ul');
      skipped.forEach(function (item) {
        var row = document.createElement('li');
        row.textContent = item;
        list.appendChild(row);
      });
      result.appendChild(list);
    }
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    submit.disabled = true;
    submit.value = i18n.working || 'Saving…';

    fetch(cfg.restUrl || '', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.restNonce || ''
      },
      body: JSON.stringify({ variation_ids: variationIds() })
    })
      .then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok || !body || body.success !== true) {
            throw new Error(body && body.message ? body.message : '');
          }
          return body.data || {};
        });
      })
      .then(function (data) {
        showResult(data.message || '', data.skipped || [], false);
        input.value = '';
      })
      .catch(function (error) {
        showResult(
          error.message || i18n.requestFailed || 'The imported products could not be updated.',
          [],
          true
        );
      })
      .finally(function () {
        submit.disabled = false;
        submit.value = submit.getAttribute('data-original-value') || i18n.markImported || 'Mark as imported';
      });
  });

  submit.setAttribute('data-original-value', submit.value || i18n.markImported || 'Mark as imported');
})();
