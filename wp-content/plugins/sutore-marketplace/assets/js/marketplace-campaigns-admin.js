(function () {
  'use strict';

  var form = document.querySelector('.sutore-mp-campaign-form');
  if (!form || !window.SutoreMarketplaceAdmin) {
    return;
  }
  var cfg = window.SutoreMarketplaceAdmin;
  var i18n = window.SutoreMpCampaignPreviewI18n || {};
  var previewOut = form.querySelector('.sutore-mp-campaign-preview-result');
  var samplesOut = form.querySelector('.sutore-mp-campaign-preview-samples');
  var debounceTimer = null;
  var requestSeq = 0;

  function collectTargeting(formEl) {
    var levels = [];
    formEl.querySelectorAll('input[name="merchant_levels[]"]:checked').forEach(function (el) {
      levels.push(el.value);
    });
    function multi(name) {
      return Array.prototype.map.call(formEl.querySelectorAll('select[name="' + name + '"] option:checked'), function (o) {
        return parseInt(o.value, 10);
      }).filter(Boolean);
    }
    var productRaw = (formEl.querySelector('[name="product_ids"]') || {}).value || '';
    var productIds = productRaw.split(/[\s,]+/).map(function (v) { return parseInt(v, 10); }).filter(Boolean);
    var askingMin = (formEl.querySelector('[name="asking_min"]') || {}).value || '';
    var askingMax = (formEl.querySelector('[name="asking_max"]') || {}).value || '';
    return {
      merchant_levels: levels,
      asking_min: askingMin !== '' ? askingMin : null,
      asking_max: askingMax !== '' ? askingMax : null,
      category_ids: multi('category_ids[]'),
      brand_ids: multi('brand_ids[]'),
      product_ids: productIds
    };
  }

  function formatPreview(data) {
    var listings = parseInt((data && data.listing_count) || 0, 10) || 0;
    var merchants = parseInt((data && data.merchant_count) || 0, 10) || 0;
    var matched = parseInt((data && data.matched_count) || 0, 10) || 0;
    var busy = parseInt((data && data.busy_count) || 0, 10) || 0;
    var truncated = !!(data && data.truncated);
    if (listings <= 0) {
      if (matched > 0 && busy > 0) {
        return (i18n.coverBusy || 'No eligible products: %d matching product(s) already have a campaign offer or active campaign.').replace('%d', String(busy));
      }
      return i18n.coverZero || 'No products match the current targeting.';
    }
    var tpl = i18n.coverTpl || 'This campaign will cover %1$d products (%2$d merchants).';
    var text = tpl.replace('%1$d', String(listings)).replace('%2$d', String(merchants));
    if (truncated) {
      text += ' ' + (i18n.truncated || 'Audience scan is capped at 2000 matching products; the real audience may be larger.');
    }
    return text;
  }

  function renderSamples(data) {
    if (!samplesOut) {
      return;
    }
    samplesOut.innerHTML = '';
    var samples = (data && data.samples) || [];
    var listings = parseInt((data && data.listing_count) || 0, 10) || 0;
    if (!samples.length) {
      return;
    }
    samples.forEach(function (item) {
      var li = document.createElement('li');
      var title = item.parent_title || ('#' + (item.parent_product_id || item.variation_id || ''));
      var code = item.product_code ? ' · ' + item.product_code : '';
      var price = item.asking_display ? ' · ' + item.asking_display : '';
      li.textContent = title + code + price + ' (#' + (item.variation_id || '') + ')';
      samplesOut.appendChild(li);
    });
    if (listings > samples.length) {
      var more = document.createElement('li');
      more.textContent = (i18n.moreTpl || '…and %d more.').replace('%d', String(listings - samples.length));
      samplesOut.appendChild(more);
    }
  }

  function adminRequest(method, path, body) {
    var core = window.SutoreMarketplace;
    if (core && typeof core.request === 'function') {
      return Promise.resolve(
        core.request(method, path, {
          body: body,
          restUrl: cfg.restUrl,
          restNonce: cfg.restNonce
        })
      ).then(function (json) {
        if (!json || json.success !== true) {
          throw new Error((json && json.message) || (json && json.data && json.data.message) || (i18n.error || 'Error'));
        }
        return json.data || {};
      });
    }
    return fetch((cfg.restUrl || '') + path.replace(/^\//, ''), {
      method: method || 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
      body: JSON.stringify(body || {})
    }).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok || !json || json.success !== true) {
          throw new Error((json && json.message) || (i18n.error || 'Error'));
        }
        return json.data || {};
      });
    });
  }

  function refreshPreview() {
    if (!previewOut) {
      return;
    }
    var seq = ++requestSeq;
    previewOut.textContent = i18n.loading || 'Counting matching products…';
    if (samplesOut) {
      samplesOut.innerHTML = '';
    }
    adminRequest('POST', 'admin/campaigns/preview', { targeting: collectTargeting(form) }).then(function (data) {
      if (seq !== requestSeq) {
        return;
      }
      previewOut.textContent = formatPreview(data);
      renderSamples(data);
    }).catch(function (err) {
      if (seq !== requestSeq) {
        return;
      }
      previewOut.textContent = (err && err.message) || (i18n.error || 'Error');
      if (samplesOut) {
        samplesOut.innerHTML = '';
      }
    });
  }

  function schedulePreview() {
    if (debounceTimer) {
      clearTimeout(debounceTimer);
    }
    debounceTimer = setTimeout(refreshPreview, 350);
  }

  form.addEventListener('change', function (event) {
    var name = (event.target && event.target.name) || '';
    if (
      name.indexOf('merchant_levels') === 0 ||
      name === 'asking_min' ||
      name === 'asking_max' ||
      name === 'category_ids[]' ||
      name === 'brand_ids[]' ||
      name === 'product_ids'
    ) {
      schedulePreview();
    }
  });
  form.addEventListener('input', function (event) {
    var name = (event.target && event.target.name) || '';
    if (name === 'asking_min' || name === 'asking_max' || name === 'product_ids') {
      schedulePreview();
    }
  });

  function toMysqlLocal(v) {
    if (!v) {
      return null;
    }
    return v.replace('T', ' ') + ':00';
  }
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    event.stopImmediatePropagation();
    var body = {
      name: form.name.value,
      seller_discount_type: form.seller_discount_type.value,
      seller_discount_amount: form.seller_discount_amount.value,
      platform_discount_type: form.platform_discount_type.value,
      platform_discount_amount: form.platform_discount_amount.value,
      starts_at: toMysqlLocal(form.starts_at.value),
      ends_at: toMysqlLocal(form.ends_at.value),
      notes: form.notes.value,
      targeting: collectTargeting(form)
    };
    adminRequest('POST', 'admin/campaigns', body).then(function () {
      window.location.reload();
    }).catch(function (err) {
      window.alert(err.message || 'Error');
    });
  }, true);

  refreshPreview();
})();
