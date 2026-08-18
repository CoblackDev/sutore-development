(function () {
  'use strict';

  var cfg = window.SutoreMarketplaceAdmin || {};

  function t(key, fallback) {
    return (cfg.i18n && cfg.i18n[key]) || fallback;
  }

  function notice(message, isError) {
    var existing = document.querySelector('.sutore-mp-admin-notice');
    if (existing) {
      existing.remove();
    }
    var el = document.createElement('div');
    el.className = 'notice sutore-mp-admin-notice is-dismissible ' + (isError ? 'notice-error' : 'notice-success');
    var p = document.createElement('p');
    p.textContent = message || '';
    el.appendChild(p);
    var wrap = document.querySelector('.wrap');
    if (wrap) {
      wrap.insertBefore(el, wrap.firstChild);
    }
  }

  function request(method, path, body) {
    var opts = {
      method: method || 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.restNonce || ''
      }
    };
    if (body && method !== 'GET' && method !== 'DELETE') {
      opts.body = JSON.stringify(body);
    } else if (body && method === 'DELETE') {
      opts.body = JSON.stringify(body);
    }
    return fetch((cfg.restUrl || '') + path.replace(/^\//, ''), opts).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok || !json || json.success !== true) {
          var msg = (json && (json.message || (json.data && json.data.message))) || t('error', 'Error');
          throw new Error(msg);
        }
        return json.data || {};
      });
    });
  }

  function formToObject(form) {
    var data = {};
    var fd = new FormData(form);
    fd.forEach(function (value, key) {
      if (key.indexOf('sutore_') === 0 || key === '_wpnonce' || key === '_wp_http_referer') {
        return;
      }
      data[key] = value;
    });
    return data;
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.classList || !form.classList.contains('sutore-mp-admin-rest')) {
      return;
    }
    event.preventDefault();
    var path = form.getAttribute('data-rest-path') || '';
    var method = (form.getAttribute('data-rest-method') || 'POST').toUpperCase();
    var reload = form.getAttribute('data-rest-reload') !== '0';
    var redirect = form.getAttribute('data-rest-redirect') || '';
    var submit = form.querySelector('[type="submit"]');
    if (submit) {
      submit.disabled = true;
    }
    request(method, path, formToObject(form))
      .then(function (data) {
        notice(data.message || t('updated', 'Updated.'), false);
        if (redirect) {
          window.setTimeout(function () {
            window.location.href = redirect;
          }, 400);
        } else if (reload) {
          window.setTimeout(function () {
            window.location.reload();
          }, 400);
        }
      })
      .catch(function (err) {
        notice(err.message || t('error', 'Error'), true);
      })
      .finally(function () {
        if (submit) {
          submit.disabled = false;
        }
      });
  });

  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-rest-click]');
    if (!btn) {
      return;
    }
    event.preventDefault();
    var path = btn.getAttribute('data-rest-path') || '';
    var method = (btn.getAttribute('data-rest-method') || 'POST').toUpperCase();
    var confirmMsg = btn.getAttribute('data-rest-confirm') || '';
    var redirect = btn.getAttribute('data-rest-redirect') || '';
    if (confirmMsg && !window.confirm(confirmMsg)) {
      return;
    }
    btn.setAttribute('aria-busy', 'true');
    request(method, path, {})
      .then(function (data) {
        notice(data.message || t('updated', 'Updated.'), false);
        window.setTimeout(function () {
          if (redirect) {
            window.location.href = redirect;
          } else {
            window.location.reload();
          }
        }, 400);
      })
      .catch(function (err) {
        notice(err.message || t('error', 'Error'), true);
      })
      .finally(function () {
        btn.removeAttribute('aria-busy');
      });
  });

  function get(path, query) {
    var url = (cfg.restUrl || '') + path.replace(/^\//, '');
    if (query && typeof query === 'object') {
      var params = [];
      Object.keys(query).forEach(function (key) {
        if (query[key] == null || query[key] === '') {
          return;
        }
        params.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(query[key])));
      });
      if (params.length) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + params.join('&');
      }
    }
    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.restNonce || '' }
    }).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok || !json || json.success !== true) {
          var msg = (json && (json.message || (json.data && json.data.message))) || t('error', 'Error');
          throw new Error(msg);
        }
        return json.data || {};
      });
    });
  }

  function productLabel(item) {
    var title = (item && item.title) || ('#' + ((item && item.id) || ''));
    var code = item && item.product_code ? ' (' + item.product_code + ')' : '';
    return title + code;
  }

  function bindProductPicker(root) {
    if (!root || root.getAttribute('data-picker-bound') === '1') {
      return;
    }
    root.setAttribute('data-picker-bound', '1');
    var mode = root.getAttribute('data-mode') === 'multiple' ? 'multiple' : 'single';
    var search = root.querySelector('.sutore-mp-admin-product-search');
    var results = root.querySelector('.sutore-mp-admin-product-results');
    var hidden = root.querySelector('input[type="hidden"]');
    var chosen = root.querySelector('.sutore-mp-admin-product-chosen');
    var chips = root.querySelector('.sutore-mp-admin-product-chips');
    var sizeId = root.getAttribute('data-size-select') || '';
    var sizeSelect = sizeId ? document.getElementById(sizeId) : null;
    var debounceTimer = null;
    var seq = 0;
    var selected = {};

    function hideResults() {
      if (results) {
        results.innerHTML = '';
        results.hidden = true;
      }
    }

    function syncHidden() {
      if (!hidden) {
        return;
      }
      var ids = Object.keys(selected);
      hidden.value = ids.join(',');
      hidden.dispatchEvent(new Event('input', { bubbles: true }));
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function resetSizeSelect(placeholder) {
      if (!sizeSelect) {
        return;
      }
      sizeSelect.innerHTML = '';
      var opt = document.createElement('option');
      opt.value = '';
      opt.textContent = placeholder || t('selectProductFirst', 'Select a product first');
      sizeSelect.appendChild(opt);
      sizeSelect.value = '';
      sizeSelect.disabled = true;
    }

    function loadSizes(parentId) {
      if (!sizeSelect) {
        return;
      }
      resetSizeSelect(t('selectProductFirst', 'Select a product first'));
      if (!parentId) {
        return;
      }
      get('sizes/' + parentId, {}).then(function (data) {
        var items = (data && data.items) || [];
        sizeSelect.innerHTML = '';
        var first = document.createElement('option');
        first.value = '';
        first.textContent = (data && data.axis_label) || t('selectProductFirst', 'Select a product first');
        sizeSelect.appendChild(first);
        items.forEach(function (item) {
          var opt = document.createElement('option');
          opt.value = String(item.term_id);
          opt.textContent = item.name || ('#' + item.term_id);
          sizeSelect.appendChild(opt);
        });
        sizeSelect.disabled = items.length === 0;
      }).catch(function () {
        resetSizeSelect(t('error', 'Error'));
      });
    }

    function renderChips() {
      if (!chips) {
        return;
      }
      chips.innerHTML = '';
      Object.keys(selected).forEach(function (id) {
        var item = selected[id];
        var li = document.createElement('li');
        li.textContent = productLabel(item) + ' ';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('aria-label', t('remove', 'Remove'));
        btn.textContent = '×';
        btn.addEventListener('click', function () {
          delete selected[id];
          renderChips();
          syncHidden();
        });
        li.appendChild(btn);
        chips.appendChild(li);
      });
    }

    function pick(item) {
      if (!item || !item.id) {
        return;
      }
      var id = String(item.id);
      if (mode === 'multiple') {
        selected[id] = item;
        renderChips();
        syncHidden();
        if (search) {
          search.value = '';
        }
      } else {
        selected = {};
        selected[id] = item;
        if (hidden) {
          hidden.value = id;
          hidden.dispatchEvent(new Event('input', { bubbles: true }));
          hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (chosen) {
          var tpl = t('selectedProduct', 'Selected: %s');
          chosen.textContent = tpl.replace('%s', productLabel(item));
          chosen.hidden = false;
        }
        if (search) {
          search.value = productLabel(item);
        }
        loadSizes(item.id);
      }
      hideResults();
    }

    function renderResults(items) {
      if (!results) {
        return;
      }
      results.innerHTML = '';
      if (!items.length) {
        var empty = document.createElement('p');
        empty.className = 'sutore-mp-admin-product-empty';
        empty.textContent = t('noMatchingProducts', 'No matching products.');
        results.appendChild(empty);
        results.hidden = false;
        return;
      }
      items.forEach(function (item) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = productLabel(item);
        btn.addEventListener('click', function () {
          pick(item);
        });
        results.appendChild(btn);
      });
      results.hidden = false;
    }

    function searchNow(term) {
      term = String(term || '').trim();
      var my = ++seq;
      if (term.length < 2) {
        hideResults();
        return;
      }
      get('search-parents', { product_code: term }).then(function (data) {
        if (my !== seq) {
          return;
        }
        renderResults((data && data.items) || []);
      }).catch(function () {
        if (my !== seq) {
          return;
        }
        renderResults([]);
      });
    }

    if (search) {
      search.addEventListener('input', function () {
        if (mode === 'single' && hidden && search.value.trim() === '') {
          hidden.value = '';
          selected = {};
          if (chosen) {
            chosen.hidden = true;
            chosen.textContent = '';
          }
          resetSizeSelect();
        }
        if (debounceTimer) {
          clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(function () {
          searchNow(search.value);
        }, 280);
      });
      search.addEventListener('focus', function () {
        if (search.value.trim().length >= 2 && results && results.childNodes.length) {
          results.hidden = false;
        }
      });
    }

    document.addEventListener('click', function (event) {
      if (!root.contains(event.target)) {
        hideResults();
      }
    });

    if (mode === 'single') {
      resetSizeSelect();
    }
  }

  document.querySelectorAll('.sutore-mp-admin-product-picker').forEach(bindProductPicker);

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.classList || !form.classList.contains('sutore-mp-admin-rest')) {
      return;
    }
    var picker = form.querySelector('.sutore-mp-admin-product-picker[data-mode="single"]');
    if (!picker) {
      return;
    }
    var parent = picker.querySelector('input[type="hidden"]');
    if (parent && !parent.value) {
      event.preventDefault();
      event.stopImmediatePropagation();
      notice(t('pickProduct', 'Select a catalog product.'), true);
      return;
    }
    var sizeSelect = form.querySelector('#outlet_size_term_id');
    if (sizeSelect && !sizeSelect.value) {
      event.preventDefault();
      event.stopImmediatePropagation();
      notice(t('pickVariation', 'Select a variation.'), true);
    }
  }, true);

  cfg.request = request;
  window.SutoreMarketplaceAdmin = cfg;
})();
