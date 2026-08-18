(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplace || {};
  var t = cfg.t;
  var api = cfg.api;

  var BULK_WIZARD_TOTAL = 2;

  var state = {
    importToken: '',
    fileName: '',
    csv: '',
    priceTimer: null,
    updatingPrice: false,
    canCommit: false,
    validating: false
  };

  function $listingsFrom($root) {
    var $create = $root.closest('.sutore-mp-staff-listing-create');
    if ($create.length) {
      return $create;
    }
    var $staff = $root.closest('.sutore-mp-staff-manage');
    if ($staff.length) {
      var $host = $staff.find('.sutore-mp-staff-listing-create').first();
      if ($host.length) {
        return $host;
      }
    }
    return $root.closest('.sutore-mp-listings');
  }

  function isStaffCreateMode($listings) {
    return $listings && $listings.attr('data-staff-create') === '1';
  }

  function staffBulkMerchantId($listings) {
    return parseInt(
      $listings.find('.sutore-mp-bulk-overlay .sutore-mp-staff-merchant-id').val(),
      10
    ) || 0;
  }

  function priceStep() {
    var step = parseInt(cfg.priceStep, 10);
    return step > 0 ? step : 100;
  }

  function esc(str) {
    return $('<div/>').text(str == null ? '' : String(str)).html();
  }

  function queuePreviewLabel(preview) {
    if (!preview) {
      return '—';
    }
    if (preview.can_win_sale) {
      if (preview.merchant_auto_activates) {
        return t('bulkWillBeFirstForSale', 'Will be #1 (for sale)');
      }
      return t('bulkWillBeFirstAwaitingApproval', 'Will be #1 (awaiting approval)');
    }
    var template = t('bulkWillBeQueued', 'Queued (#%1$d of %2$d)');
    return template
      .replace('%1$d', String(preview.queue_position || 0))
      .replace('%2$d', String(preview.queue_total || 0));
  }

  function queuePreviewClass(preview) {
    if (!preview) {
      return '';
    }
    if (preview.can_win_sale) {
      return preview.merchant_auto_activates ? 'is-winner' : 'is-awaiting-approval';
    }
    return 'is-queued';
  }

  function lowestOnSaleLabel(preview) {
    if (!preview) {
      return '—';
    }
    if (preview.min_on_sale_display) {
      return preview.min_on_sale_display;
    }
    if (preview.no_active_sale) {
      return t('bulkNoActiveSale', 'No active sale for this size');
    }
    return '—';
  }

  function canEditPrice(row) {
    return row && row.status !== 'error' && row.price > 0;
  }

  function statusLabel(status) {
    var map = {
      ready: t('bulkStatusReady', 'Ready'),
      warning: t('bulkStatusWarning', 'Warning'),
      error: t('bulkStatusError', 'Error')
    };
    return map[status] || status;
  }

  function setBulkAlert($root, text) {
    var $alert = $root.find('.sutore-mp-bulk-alert');
    if (!$alert.length) {
      return;
    }
    if (!text) {
      $alert.text('').prop('hidden', true);
      return;
    }
    $alert.text(text).prop('hidden', false);
  }

  function setUploadMessage($root, text, isError) {
    if (isError) {
      $root.find('.sutore-mp-bulk-upload-message').text('');
      setBulkAlert($root, text);
      return;
    }
    setBulkAlert($root, '');
    $root.find('.sutore-mp-bulk-upload-message').text(text || '');
  }

  function setCommitMessage($root, text, isError) {
    if (isError) {
      $root.find('.sutore-mp-bulk-commit-message').text('');
      setBulkAlert($root, text);
      return;
    }
    setBulkAlert($root, '');
    $root.find('.sutore-mp-bulk-commit-message').text(text || '');
  }

  function bulkWizardStep($listings) {
    var raw = String($listings.find('.sutore-mp-bulk-modal').attr('data-bulk-wizard-step') || '1');
    if (raw === 'done') {
      return 'done';
    }
    return Math.min(BULK_WIZARD_TOTAL, Math.max(1, parseInt(raw, 10) || 1));
  }

  function bulkWizardStepTitle(step) {
    if (step === 2) {
      return t('bulkWizardStepReview', 'Review');
    }
    return t('bulkWizardStepUpload', 'Upload');
  }

  function updateBulkWizardChrome($listings, step) {
    var $modal = $listings.find('.sutore-mp-bulk-modal');
    var $nav = $listings.find('.sutore-mp-bulk-wizard-steps');
    if (step === 'done') {
      $nav.prop('hidden', true);
      $modal.attr('data-bulk-wizard-step', 'done');
      $listings.find('.sutore-mp-bulk-modal__title').text(t('bulkSuccessTitle', 'Import queued'));
      $listings.find('.sutore-mp-bulk-modal__sub').text('');
      return;
    }

    $nav.prop('hidden', false);
    $nav.find('.sutore-mp-bulk-wizard-step').each(function () {
      var n = parseInt($(this).attr('data-step'), 10) || 0;
      $(this)
        .toggleClass('is-current', n === step)
        .toggleClass('is-done', n < step)
        .attr('aria-current', n === step ? 'step' : null);
    });
    $modal.attr('data-bulk-wizard-step', String(step));
    $listings.find('.sutore-mp-bulk-modal__title').text(t('bulkUploadTitle', 'Bulk upload'));
    $listings.find('.sutore-mp-bulk-modal__sub').text(
      (t('bulkWizardStepOf', 'Step %1$d of %2$d'))
        .replace('%1$d', String(step))
        .replace('%2$d', String(BULK_WIZARD_TOTAL)) +
        ' · ' +
        bulkWizardStepTitle(step)
    );
  }

  function showBulkWizardSections($root, step) {
    $root.find('.sutore-mp-bulk-step').each(function () {
      var name = String($(this).attr('data-bulk-step') || '');
      var show = step === 'done' ? name === 'done' : name === String(step);
      $(this).prop('hidden', !show);
    });
  }

  function updateBulkWizardFoot($listings) {
    var step = bulkWizardStep($listings);
    var $foot = $listings.find('.sutore-mp-bulk-modal__foot');
    var $root = $listings.find('.sutore-mp-listing-bulk');
    var html = '';

    if (step === 'done') {
      html =
        '<button type="button" class="wp-element-button sutore-mp-bulk-close">' +
        esc(t('bulkClose', 'Close')) +
        '</button>';
      $foot.html(html).prop('hidden', false);
      return;
    }

    if (step > 1) {
      html +=
        '<button type="button" class="wp-element-button is-style-outline sutore-mp-bulk-wizard-back">' +
        esc(t('bulkPrevious', 'Previous')) +
        '</button>';
    } else {
      html +=
        '<button type="button" class="wp-element-button is-style-outline sutore-mp-bulk-close">' +
        esc(t('bulkClose', 'Close')) +
        '</button>';
    }

    if (step < BULK_WIZARD_TOTAL) {
      var hasFile = !!(
        $root.find('.sutore-mp-bulk-file')[0] &&
        $root.find('.sutore-mp-bulk-file')[0].files &&
        $root.find('.sutore-mp-bulk-file')[0].files[0]
      );
      html +=
        '<button type="button" class="wp-element-button sutore-mp-bulk-wizard-next"' +
        (hasFile && !state.validating ? '' : ' disabled') +
        '>' +
        esc(t('bulkNext', 'Next')) +
        '</button>';
    } else {
      var needsMerchant = isStaffCreateMode($listings) && !staffBulkMerchantId($listings);
      html +=
        '<button type="button" class="wp-element-button sutore-mp-bulk-commit"' +
        (!state.canCommit || !state.importToken || state.updatingPrice || needsMerchant
          ? ' disabled'
          : '') +
        '>' +
        esc(t('bulkCreateListings', 'Create products')) +
        '</button>';
    }

    $foot.html(html).prop('hidden', false);
  }

  function setBulkWizardStep($listings, step) {
    var $root = $listings.find('.sutore-mp-listing-bulk');
    if (step === 'done') {
      updateBulkWizardChrome($listings, 'done');
      showBulkWizardSections($root, 'done');
      updateBulkWizardFoot($listings);
      return;
    }

    step = Math.min(BULK_WIZARD_TOTAL, Math.max(1, parseInt(step, 10) || 1));
    updateBulkWizardChrome($listings, step);
    showBulkWizardSections($root, step);
    updateBulkWizardFoot($listings);
  }

  function applyPreviewResponse($root, data) {
    state.importToken = data.import_token || state.importToken;
    var summary = data.summary || {};
    state.canCommit = (summary.ready || 0) + (summary.warning || 0) > 0;
    renderSummary($root, summary);
    renderRows($root, data.rows || []);
    var $listings = $listingsFrom($root);
    if (!state.canCommit) {
      setCommitMessage($root, t('bulkNoValidRows', 'No valid rows to import. Fix the CSV and try again.'), true);
    } else if (isStaffCreateMode($listings) && !staffBulkMerchantId($listings)) {
      setCommitMessage($root, t('bulkPickSeller', 'Choose a seller to see the queue preview and create the products.'));
    } else {
      setCommitMessage($root, t('bulkPreviewReady', 'Review the rows below, then confirm to create products.'));
    }
    setBulkWizardStep($listings, 2);
  }

  function schedulePriceUpdate($root, line, price) {
    if (!state.importToken || !line) {
      return;
    }
    if (state.priceTimer) {
      clearTimeout(state.priceTimer);
    }
    state.priceTimer = setTimeout(function () {
      refreshPreview($root, 'marketplace_listing_bulk_update_row', {
        import_token: state.importToken,
        line: line,
        price: price
      }, 'bulkUpdatingPrice');
    }, 400);
  }

  function renderQueuedSuccess($root, data) {
    var total = data.total_rows || 0;
    var html =
      '<div class="sutore-mp-bulk-queued">' +
      '<div class="sutore-mp-bulk-queued__icon" aria-hidden="true">' +
      '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
      '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.75"/>' +
      '<path d="M8 12.5l2.5 2.5L16 9.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>' +
      '</svg></div>' +
      '<p class="sutore-mp-bulk-queued-lead">' +
      esc(t('bulkJobQueuedNotify', 'Your import has been queued. You will receive a notification when it is finished.')) +
      '</p>';

    if (total > 0) {
      html +=
        '<p class="sutore-mp-bulk-queued-meta">' +
        esc(t('bulkQueuedRowCount', '%d products queued for import.').replace('%d', String(total))) +
        '</p>';
    }

    html += '</div>';

    $root.find('.sutore-mp-bulk-result').html(html);
    setCommitMessage($root, '');
    state.importToken = '';
    state.canCommit = false;
    setBulkWizardStep($listingsFrom($root), 'done');
  }

  function resetPreview($root) {
    if (state.priceTimer) {
      clearTimeout(state.priceTimer);
      state.priceTimer = null;
    }
    state.updatingPrice = false;
    state.importToken = '';
    state.fileName = '';
    state.csv = '';
    state.canCommit = false;
    state.validating = false;
    $root.find('.sutore-mp-bulk-file').val('');
    $root.find('.sutore-mp-bulk-table tbody').empty();
    $root.find('.sutore-mp-bulk-summary').empty();
    $root.find('.sutore-mp-bulk-result').empty();
    setUploadMessage($root, '');
    setCommitMessage($root, '');
    setBulkWizardStep($listingsFrom($root), 1);
  }

  function renderSummary($root, summary) {
    if (!summary) {
      return;
    }
    var cards = [
      { key: 'total', label: t('bulkSummaryTotal', 'Total rows'), value: summary.total || 0 },
      { key: 'ready', label: t('bulkSummaryReady', 'Ready'), value: summary.ready || 0 },
      { key: 'warning', label: t('bulkSummaryWarning', 'Warnings'), value: summary.warning || 0 },
      { key: 'error', label: t('bulkSummaryError', 'Errors'), value: summary.error || 0 }
    ];
    var html =
      '<h3 class="sutore-mp-bulk-summary-title">' +
      esc(t('bulkSummaryTitle', 'Import preview')) +
      '</h3>' +
      '<div class="sutore-mp-bulk-stats" role="list">';

    cards.forEach(function (card) {
      html +=
        '<div class="sutore-mp-bulk-stat is-' + esc(card.key) + '" role="listitem">' +
        '<span class="sutore-mp-bulk-stat-label">' + esc(card.label) + '</span>' +
        '<strong class="sutore-mp-bulk-stat-value">' + esc(String(card.value)) + '</strong>' +
        '</div>';
    });

    html += '</div>';
    $root.find('.sutore-mp-bulk-summary').html(html);
  }

  function renderRows($root, rows) {
    var $tbody = $root.find('.sutore-mp-bulk-table tbody').empty();
    var step = priceStep();
    var showImported = $root.find('.sutore-mp-bulk-table thead th[data-col="imported"]').length > 0;
    var thumbBox = cfg.thumbBox || function () { return $('<span/>'); };
    (rows || []).forEach(function (row) {
      var preview = row.preview || null;
      var $tr = $('<tr/>')
        .addClass('is-' + (row.status || 'error'))
        .attr('data-line', row.line || '');
      var product = row.parent_title
        ? row.parent_title + (row.product_code ? ' (' + row.product_code + ')' : '')
        : (row.product_code || '—');
      var messages = (row.messages || []).join(' ');
      $tr.append($('<td/>').text(row.line || ''));
      $tr.append(
        $('<td class="sutore-mp-bulk-product"/>').append(
          $('<div class="sutore-mp-bulk-product-cell"/>').append(
            thumbBox('sutore-mp-bulk-thumb-box', 'sutore-mp-bulk-thumb', row.thumbnail || '', product),
            $('<div class="sutore-mp-bulk-product-text"/>').append(
              $('<strong class="sutore-mp-bulk-product-title"/>').text(row.parent_title || row.product_code || '—'),
              row.parent_title && row.product_code
                ? $('<span class="sutore-mp-bulk-product-sub"/>').text(row.product_code)
                : null
            )
          )
        )
      );
      $tr.append($('<td/>').text(row.size || '—'));
      $tr.append($('<td/>').text(row.conditions_label || '—'));
      $tr.append($('<td/>').text(row.shipping_label || '—'));
      if (showImported) {
        $tr.append(
          $('<td class="sutore-mp-bulk-imported"/>').text(
            row.imported ? t('importedProduct', 'Imported') : '—'
          )
        );
      }
      $tr.append(
        $('<td class="sutore-mp-bulk-lowest"/>').text(lowestOnSaleLabel(preview))
      );

      var $priceCell = $('<td class="sutore-mp-bulk-price-cell"/>');
      if (canEditPrice(row)) {
        $priceCell.append(
          $('<input type="number" class="sutore-mp-input sutore-mp-bulk-price-input"/>')
            .attr('min', step)
            .attr('step', step)
            .val(row.price || '')
            .attr('data-line', row.line || '')
        );
      } else {
        $priceCell.text(row.price_display || '—');
      }
      $tr.append($priceCell);

      $tr.append(
        $('<td class="sutore-mp-bulk-queue"/>').append(
          $('<span class="sutore-mp-bulk-queue-label"/>')
            .addClass(queuePreviewClass(preview))
            .text(queuePreviewLabel(preview))
        )
      );
      $tr.append(
        $('<td/>').append(
          $('<span class="sutore-mp-bulk-status"/>').text(statusLabel(row.status)),
          messages ? $('<div class="sutore-mp-bulk-row-messages"/>').text(messages) : null
        )
      );

      var $actions = $('<td class="sutore-mp-bulk-actions"/>');
      var $actionWrap = $('<div class="sutore-mp-bulk-action-wrap"/>');
      if (preview && preview.show_first_place_button && preview.first_place_asking != null) {
        $actionWrap.append(
          $('<button type="button" class="wp-element-button is-style-outline sutore-mp-bulk-first-place"/>')
            .text(t('bulkMoveToFirstPlace', 'Move to First Place'))
            .attr('data-line', row.line || '')
            .attr('data-price', preview.first_place_asking)
        );
      }
      $actionWrap.append(
        $('<button type="button" class="wp-element-button is-style-outline sutore-mp-bulk-delete-row"/>')
          .text(t('bulkDeleteRow', 'Remove row'))
          .attr('data-line', row.line || '')
      );
      $actions.append($actionWrap);
      $tr.append($actions);
      $tbody.append($tr);
    });
  }

  function refreshPreview($root, action, payload, messageKey) {
    state.updatingPrice = true;
    updateBulkWizardFoot($listingsFrom($root));
    setCommitMessage($root, t(messageKey || 'bulkUpdatingPreview', 'Updating preview…'));

    api(action, payload)
      .done(function (res) {
        state.updatingPrice = false;
        if (!res || !res.success || !res.data) {
          setCommitMessage($root, (res && res.data && res.data.message) || t('error', 'Error'), true);
          updateBulkWizardFoot($listingsFrom($root));
          return;
        }
        var summary = res.data.summary || {};
        state.importToken = res.data.import_token || state.importToken;
        state.canCommit = (summary.ready || 0) + (summary.warning || 0) > 0;
        renderSummary($root, summary);
        renderRows($root, res.data.rows || []);
        setCommitMessage(
          $root,
          state.canCommit
            ? t('bulkPreviewReady', 'Review the rows below, then confirm to create products.')
            : t('bulkNoValidRows', 'No valid rows to import. Fix the CSV and try again.'),
          !state.canCommit
        );
        updateBulkWizardFoot($listingsFrom($root));
      })
      .fail(function () {
        state.updatingPrice = false;
        setCommitMessage($root, t('error', 'Error'), true);
        updateBulkWizardFoot($listingsFrom($root));
      });
  }

  function validatePreview($root) {
    var $listings = $listingsFrom($root);
    if (!state.csv) {
      return;
    }

    var payload = { csv: state.csv };
    var merchantId = isStaffCreateMode($listings) ? staffBulkMerchantId($listings) : 0;
    if (merchantId) {
      payload.merchant_id = merchantId;
    }

    state.validating = true;
    setCommitMessage($root, t('bulkUpdatingPreview', 'Updating preview…'));
    updateBulkWizardFoot($listings);

    api('marketplace_listing_bulk_validate', payload)
      .done(function (res) {
        state.validating = false;
        if (!res || !res.success || !res.data) {
          setCommitMessage($root, (res && res.data && res.data.message) || t('error', 'Error'), true);
          updateBulkWizardFoot($listings);
          return;
        }

        state.importToken = res.data.import_token || '';
        applyPreviewResponse($root, res.data);
      })
      .fail(function () {
        state.validating = false;
        setCommitMessage($root, t('error', 'Error'), true);
        updateBulkWizardFoot($listings);
      });
  }

  function goToPreview($root) {
    var fileInput = $root.find('.sutore-mp-bulk-file')[0];
    if (!fileInput || !fileInput.files || !fileInput.files[0]) {
      setUploadMessage($root, t('bulkPickFile', 'Choose a CSV file.'), true);
      return;
    }

    var file = fileInput.files[0];
    var $listings = $listingsFrom($root);
    state.fileName = file.name;
    state.validating = true;
    setUploadMessage($root, t('loading', 'Loading…'));
    updateBulkWizardFoot($listings);

    var reader = new FileReader();
    reader.onload = function (event) {
      state.validating = false;
      state.csv = String(event.target.result || '');
      setUploadMessage($root, '');
      if (isStaffCreateMode($listings)) {
        $listings.addClass('is-staff-simple-create');
      }
      setBulkWizardStep($listings, 2);
      validatePreview($root);
    };
    reader.onerror = function () {
      state.validating = false;
      setUploadMessage($root, t('error', 'Error'), true);
      updateBulkWizardFoot($listings);
    };
    reader.readAsText(file, 'UTF-8');
  }

  function commitImport($root) {
    if (!state.importToken) {
      return;
    }

    var $listings = $listingsFrom($root);
    if (isStaffCreateMode($listings) && !staffBulkMerchantId($listings)) {
      setCommitMessage($root, t('sellerRequired', 'Select a seller before continuing.'), true);
      return;
    }

    state.updatingPrice = true;
    updateBulkWizardFoot($listingsFrom($root));
    setCommitMessage($root, t('bulkCommitting', 'Queuing import…'));

    api('marketplace_listing_bulk_commit', { import_token: state.importToken })
      .done(function (res) {
        state.updatingPrice = false;
        if (!res || !res.success || !res.data) {
          setCommitMessage($root, (res && res.data && res.data.message) || t('error', 'Error'), true);
          updateBulkWizardFoot($listingsFrom($root));
          return;
        }

        renderQueuedSuccess($root, res.data);
        var $listings = $listingsFrom($root);
        if ($listings.length) {
          $listings.trigger('sutore-mp-bulk:committed');
          $(document).trigger('sutore-mp-bulk:committed', [res.data || {}]);
        }
      })
      .fail(function () {
        state.updatingPrice = false;
        setCommitMessage($root, t('error', 'Error'), true);
        updateBulkWizardFoot($listingsFrom($root));
      });
  }

  function bind($root) {
    var $listings = $listingsFrom($root);

    $root.on('sutore-mp-bulk:reset', function () {
      resetPreview($root);
    });

    $root.on('sutore-mp-bulk:open', function () {
      resetPreview($root);
    });

    $listings.on('click', '.sutore-mp-bulk-wizard-next', function () {
      if (bulkWizardStep($listings) !== 1) {
        return;
      }
      goToPreview($root);
    });

    $listings.on('sutore-mp-bulk:merchant-changed', function () {
      if (bulkWizardStep($listings) !== 2) {
        return;
      }
      validatePreview($root);
    });

    $listings.on('click', '.sutore-mp-bulk-wizard-back', function () {
      if (bulkWizardStep($listings) === 2) {
        setBulkWizardStep($listings, 1);
      }
    });

    $root.on('change', '.sutore-mp-bulk-file', function () {
      if (state.priceTimer) {
        clearTimeout(state.priceTimer);
        state.priceTimer = null;
      }
      state.updatingPrice = false;
      state.importToken = '';
      state.csv = '';
      state.canCommit = false;
      state.validating = false;
      $root.find('.sutore-mp-bulk-table tbody').empty();
      $root.find('.sutore-mp-bulk-summary').empty();
      $root.find('.sutore-mp-bulk-result').empty();
      setCommitMessage($root, '');
      setUploadMessage($root, '');
      setBulkWizardStep($listings, 1);
    });

    $listings.on('click', '.sutore-mp-bulk-commit', function () {
      commitImport($root);
    });

    $root.on('input', '.sutore-mp-bulk-price-input', function () {
      var line = parseInt($(this).attr('data-line'), 10);
      schedulePriceUpdate($root, line, String($(this).val() || ''));
    });

    $root.on('click', '.sutore-mp-bulk-first-place', function () {
      var line = parseInt($(this).attr('data-line'), 10);
      var price = $(this).attr('data-price') || '';
      if (state.priceTimer) {
        clearTimeout(state.priceTimer);
        state.priceTimer = null;
      }
      var $input = $root.find('.sutore-mp-bulk-price-input[data-line="' + line + '"]');
      if ($input.length) {
        $input.val(price);
      }
      refreshPreview($root, 'marketplace_listing_bulk_update_row', {
        import_token: state.importToken,
        line: line,
        price: price
      }, 'bulkUpdatingPrice');
    });

    $root.on('click', '.sutore-mp-bulk-delete-row', function () {
      var line = parseInt($(this).attr('data-line'), 10);
      if (!state.importToken || !line) {
        return;
      }
      refreshPreview($root, 'marketplace_listing_bulk_delete_row', {
        import_token: state.importToken,
        line: line
      }, 'bulkUpdatingPreview');
    });

    $root.on('click', '.sutore-mp-bulk-template-download', function (event) {
      var fallbackHref = $(this).attr('href') || '';
      if (!cfg.restUrl || !cfg.restNonce) {
        return;
      }
      event.preventDefault();
      fetch(cfg.restUrl + 'listings/bulk/template', {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': cfg.restNonce }
      }).then(function (response) {
        if (!response.ok) {
          throw new Error('download failed');
        }
        return response.blob();
      }).then(function (blob) {
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'sutore-listings-import-template.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
      }).catch(function () {
        if (fallbackHref) {
          window.location.href = fallbackHref;
        }
      });
    });
  }

  $(function () {
    $('.sutore-mp-listing-bulk[data-bulk-mode="1"]').each(function () {
      bind($(this));
    });
  });
})(jQuery);
