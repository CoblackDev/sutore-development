(function ($) {
  'use strict';

  var cfg = window.SutoreMarketplace || {};

  function t(key, fallback) {
    return cfg.t ? cfg.t(key, fallback) : fallback;
  }

  function feedback($el, message, type) {
    $el.removeClass('is-error is-success')
      .addClass(type === 'error' ? 'is-error' : 'is-success')
      .text(message || '');
  }

  function formPayload($form) {
    var payload = {};
    $form.serializeArray().forEach(function (pair) {
      payload[pair.name] = pair.value;
    });
    return payload;
  }

  function handleSuccess($form, $feedbackEl, res, redirectHome) {
    if (!res || !res.success) {
      feedback($feedbackEl, (res && res.data && res.data.message) || t('error', 'Error'), 'error');
      return;
    }

    feedback($feedbackEl, (res.data && res.data.message) || t('saved', 'Saved'), 'success');

    if (res.data && res.data.reload) {
      window.setTimeout(function () {
        window.location.href = redirectHome ? (cfg.homeUrl || '/') : window.location.href;
      }, redirectHome ? 800 : 1200);
    }
  }

  function bindOtpForm($form, purpose, apiAction, options) {
    var $feedbackEl = $form.find('.sutore-mp-account-security__feedback');
    options = options || {};

    $form.on('submit', function (event) {
      event.preventDefault();

      var payload = formPayload($form);
      $form.addClass('is-loading');
      feedback($feedbackEl, t('otpSending', 'Sending verification code…'), 'success');

      var run = function () {
        cfg.withOtp(purpose, payload, function (completePayload) {
          return cfg.api(apiAction, completePayload);
        }).done(function (res) {
          handleSuccess($form, $feedbackEl, res, !!options.redirectHome);
        }).always(function () {
          $form.removeClass('is-loading');
        });
      };

      if (options.confirmTitle) {
        cfg.showConfirm(
          options.confirmTitle,
          options.confirmText,
          options.confirmLabel || t('yes', 'Yes'),
          run
        );
        $form.removeClass('is-loading');
        feedback($feedbackEl, '', '');
        return;
      }

      run();
    });
  }

  $(function () {
    var $details = $('#sutore-mp-account-details-form');
    var $password = $('#sutore-mp-account-password-form');
    var $delete = $('#sutore-mp-account-delete-form');

    if ($details.length) {
      bindOtpForm($details, 'account_details', 'marketplace_account_details_save');
    }

    if ($password.length) {
      bindOtpForm($password, 'password_change', 'marketplace_account_password_save');
    }

    if ($delete.length) {
      bindOtpForm($delete, 'account_delete', 'marketplace_account_delete', {
        confirmTitle: t('deleteAccountTitle', 'Delete your account?'),
        confirmText: t('deleteAccountConfirm', 'This will permanently delete your account and listings. You cannot undo this action.'),
        confirmLabel: t('deleteAccountConfirmButton', 'Delete account'),
        redirectHome: true
      });
    }
  });
}(jQuery));
