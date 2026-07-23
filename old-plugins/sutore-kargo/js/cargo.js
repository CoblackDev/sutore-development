jQuery(document).ready(function ($) {
  $("#place_order").attr("disabled", true);
  window.cart_total = $(".order-total .woocommerce-Price-amount.amount")
    .text()
    .replace(/[^a-zA-Z0-9 ]/g, "");
  window.state = sutoreCargo.user_state;
  window.fast_local = sutoreCargo.fast_local;
  window.country = $("#billing_country option:selected").val();
  console.log(window.state);
  console.log(window.country);
  console.log(window.fast_local);

  $(document).ready(function ($) {
    if (window.state == "TR34") {
      //$('.sutore-shipment').show();
      $("#shipment_express").show();
      if (window.fast_local == false) {
        $("#shipment_fast_free, #shipment_fast").show();
        $("#shipment_fast_free input, #shipment_fast input").attr(
          "disabled",
          false
        );
      }
    } else {
      $("input[type=radio][name=shipment]:first").click();
      //$('.sutore-shipment').hide();
      $("#shipment_express").hide();
      if (window.fast_local == false) {
        $("#shipment_fast_free, #shipment_fast").hide();
        $("#shipment_fast_free input, #shipment_fast input").attr(
          "disabled",
          true
        );
      }
    }

    if (window.country == "TR") {
      $("#billing_states_field").show();
      $("#billing_state_field").show();
      $("#billing_city_field").show();
      $(".sutore-shipment input[type=radio][name=shipment]:first").click();
    } else if (window.country == "CY") {
      console.log("Cyprus!");
      $("#billing_states_field").hide();
      $("#billing_state_field").hide();
      $("#billing_city_field").hide();
      $(".sutore-shipment").hide();
      $(".sutore-shipment-cyprus").show();
      $(
        ".sutore-shipment-cyprus input[type=radio][name=shipment]:first"
      ).click();
      $("#place_order").attr("disabled", false);
    } else {
      $("#billing_states_field").hide();
      $("#billing_state_field").hide();
      $("#billing_city_field").hide();
      $(".sutore-shipment").show();
      $(".sutore-shipment input[type=radio][name=shipment]:first").click();
    }

    $("#place_order").attr("disabled", false);
  });

  $("#billing_states").on("change", function () {
    if ($(this).find("option:selected").val() == "TR34") {
      //$('.sutore-shipment').show();
      $("#shipment_express").show();
      if (window.fast_local == false) {
        $("#shipment_fast_free, #shipment_fast").show();
        $("#shipment_fast_free input, #shipment_fast input").attr(
          "disabled",
          false
        );
      }
    } else {
      $("input[type=radio][name=shipment]:first").click();
      $("#shipment_express").hide();
      //$('.sutore-shipment').hide();
      if (window.fast_local == false) {
        $("#shipment_fast_free, #shipment_fast").hide();
        $("#shipment_fast_free input, #shipment_fast input").attr(
          "disabled",
          true
        );
      }
      $("#place_order").attr("disabled", false);
    }
  });

  $("#billing_country").on("change", function () {
    if ($(this).find("option:selected").val() == "CY") {
      console.log("Cyprus!!");
      $(".sutore-shipment").hide();
      $(".sutore-shipment-cyprus").show();
      $(
        ".sutore-shipment-cyprus input[type=radio][name=shipment]:first"
      ).click();
      $("#place_order").attr("disabled", false);
    } else {
      console.log("Not TR!");
      $(".sutore-shipment-cyprus").hide();
      $(".sutore-shipment input[type=radio][name=shipment]:first").click();
      $(".sutore-shipment").show();
      $("#place_order").attr("disabled", false);
    }
  });

  $(".ordet-title span, .cart-subtotal span, span.cart-total").html("...");
  var data = {
    action: "get_total",
    cost: parseInt($(this).attr("data-cost")),
    total: parseInt(window.cart_total),
  };
  $.post(sutoreRegister.ajaxurl, data, function (response) {
    console.log(response);
    $(".ordet-title span").html(response.total);
    $("span.cart-total").html(response.total);
    $(".cargo-label").html(window.cargoLabel);
    $("li.order-total").html(window.cargoLabel);
  });

  $("input[type=radio][name=shipment]").change(function () {
    $("#place_order").attr("disabled", true);
    console.log($(this).val());
    window.cargoLabel = $(this).attr("data-label");
    window.selected = $(this).parent();

    $("body").trigger("update_checkout");
    //window.cart_total = $(".order-total .woocommerce-Price-amount.amount").text().replace(/[^a-zA-Z0-9 ]/g, "");
    var data = {
      action: "get_total",
      cost: parseInt($(this).attr("data-cost")),
      total: parseInt(window.cart_total),
    };
    $.post(sutoreRegister.ajaxurl, data, function (response) {
      console.log(response);
      $(".sutore-shipment label").removeClass("selected");
      window.selected.addClass("selected");
      $(".ordet-title span").html(response.total);
      $("span.shipping-cost").html(response.cost);
      $("li.order-total").html(window.cargoLabel);
      $("#place_order").attr("disabled", false);
      $("span.cart-total").html(response.total);
      $(".cargo-label").html(window.cargoLabel);
    });
  });
});
