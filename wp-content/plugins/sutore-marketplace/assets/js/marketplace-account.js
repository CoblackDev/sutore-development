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

  function digitsOnly(value) {
    return String(value || '').replace(/\D/g, '');
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

  function bindAccountDetails($form) {
    var $feedbackEl = $form.find('.sutore-mp-account-security__feedback');

    $form.on('submit', function (event) {
      event.preventDefault();

      var payload = formPayload($form);
      var registered = digitsOnly($form.attr('data-registered-phone'));
      var nextPhone = digitsOnly(payload.user_phone || payload.phone);
      var phoneChanged = registered !== '' && nextPhone !== '' && registered !== nextPhone;

      $form.addClass('is-loading');
      feedback($feedbackEl, t('otpSending', 'Sending verification code…'), 'success');

      cfg.withOtp('account_details', payload, function (registeredPayload) {
        if (!phoneChanged) {
          return cfg.api('marketplace_account_details_save', registeredPayload);
        }

        $('.sutore-mp-otp').remove();
        feedback($feedbackEl, t('otpNewPhoneSending', 'Sending a code to your new phone…'), 'success');

        return cfg.withOtp('account_details_new_phone', payload, function (newPhonePayload) {
          return cfg.api(
            'marketplace_account_details_save',
            Object.assign({}, payload, {
              otp_code: registeredPayload.otp_code,
              otp_code_new_phone: newPhonePayload.otp_code
            })
          );
        });
      }).done(function (res) {
        handleSuccess($form, $feedbackEl, res, false);
      }).always(function () {
        $form.removeClass('is-loading');
      });
    });
  }

  $(function () {
    var $details = $('#sutore-mp-account-details-form');
    var $password = $('#sutore-mp-account-password-form');
    var $delete = $('#sutore-mp-account-delete-form');

    if ($details.length) {
      bindAccountDetails($details);
    }

    if ($password.length) {
      bindOtpForm($password, 'password_change', 'marketplace_account_password_save');
    }

    if ($delete.length) {
      bindOtpForm($delete, 'account_delete', 'marketplace_account_delete', {
        confirmTitle: t('deleteAccountTitle', 'Delete your account?'),
        confirmText: t('deleteAccountConfirm', 'This will permanently delete your account and products. You cannot undo this action.'),
        confirmLabel: t('deleteAccountConfirmButton', 'Delete account'),
        redirectHome: true
      });
    }
  });
}(jQuery));
