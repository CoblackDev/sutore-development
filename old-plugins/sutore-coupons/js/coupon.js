jQuery(document).ready(function ($) {
  $(document).on("click", ".coupon-apply-button", function () {
    var couponCode = $(".coupon-code").val();
    $.ajax({
      url: coupon.ajaxurl,
      type: "POST",
      data: {
        action: "add_sutore_coupon",
        coupon_code: couponCode,
        nonce: coupon.nonce,
      },
      beforeSend: function () {
        $(".coupon-apply-button").prop("disabled", true);
      },
      success: function (response) {
        console.log(response);
        $(".coupon-apply-button").prop("disabled", false);
        if (response.status) {
          $(".coupon-code").attr("disabled", true);
          $(".coupon-apply-button")
            .text(coupon.remove_coupon_text)
            .removeClass("coupon-apply-button")
            .addClass("coupon-remove-button");
          $(".cart-total").html(response.cart_total);
          $(".coupon-message")
            .html(response.message)
            .removeClass("coupon-message-error")
            .addClass("coupon-message-success");
        } else {
          $(".coupon-message")
            .html(response.message)
            .removeClass("coupon-message-success")
            .addClass("coupon-message-error");
        }
      },
    });
  });

  $(document).on("click", ".coupon-remove-button", function () {
    var couponCode = $(".coupon-code").val();
    $.ajax({
      url: coupon.ajaxurl,
      type: "POST",
      data: {
        action: "remove_sutore_coupon",
        coupon_code: couponCode,
        nonce: coupon.nonce,
      },
      success: function (response) {
        console.log("remove", response);
        if (response.status) {
          $(".coupon-code").attr("disabled", false).val("");
          $(".coupon-remove-button")
            .text(coupon.apply_coupon_text)
            .removeClass("coupon-remove-button")
            .addClass("coupon-apply-button")
            .hide();
          $(".coupon-message").html("");
          $(".cart-total").html(response.cart_total);
        } else {
          $(".coupon-message")
            .html(response.message)
            .removeClass("coupon-message-success")
            .addClass("coupon-message-error");
        }
      },
    });
  });

  $(".coupon-code")
    .focus(function () {
      $(".coupon-apply-button").show();
    })
    .blur(function () {
      if ($(this).val() === "") {
        $(".coupon-apply-button").hide();
      }
    });

  $(".coupon-code").keypress(function (e) {
    if (e.which === 13) {
      e.preventDefault();
      // Check if the Enter key is pressed
      $(".coupon-apply-button").click(); // Trigger click on the apply button
    }
  });
});
