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

  cfg.request = request;
  window.SutoreMarketplaceAdmin = cfg;
})();
