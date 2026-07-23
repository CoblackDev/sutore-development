jQuery(document).ready(function ($) {
  console.log(sutoreRegister.user_state);
  $("#billing_states").val(sutoreRegister.user_state);

  if (sutoreRegister.user_state == "TR34") {
    //$('.sutore-shipment').show();
  } else {
    $("input[type=radio][name=shipment]:first").click();
    //$('.sutore-shipment').hide();
    $("#place_order").attr("disabled", false);
  }

  if ($("#update_billing_adress #billing_country option:selected").val() == "CY") {
    // alert("gg");
    $("#billing_state_field").hide();
    $("#billing_city_field").hide();
    $("#billing_state").val($("#billing_state option:first").val());
    $("#billing_city").val($("#billing_city option:first").val());
  }

  if ($(".woocommerce-checkout #billing_country option:selected").val() == "CY") {
    // alert("gg");
    $("#billing_states_field").hide();
    $("#billing_city_field").hide();
    $("#billing_states").val($("#billing_states option:first").val());
    $("#billing_city").val($("#billing_city option:first").val());
  }

  $("#product_code_field").append('<div class="product-dropdown"></div>');

  if (sutoreRegister.user_country.length !== 0) {
    $("#reg_country").val(sutoreRegister.user_country).addClass("fl-is-active").parent("fl-is-active").addClass("fl-is-active");
    $("#reg_country").parent().addClass("fl-is-active");
  }
  if (sutoreRegister.user_state.length !== 0) {
    $("#reg_state").val(sutoreRegister.user_state).addClass("fl-is-active").parent("fl-is-active").addClass("fl-is-active");
    $("#reg_state").parent().addClass("fl-is-active");
  }
  if (sutoreRegister.user_city.length !== 0) {
    $("#reg_city").val(sutoreRegister.user_city).addClass("fl-is-active").parent("fl-is-active").addClass("fl-is-active");
    $("#reg_city").parent().addClass("fl-is-active");
  }

  var floatlabels = new FloatLabels("form:not(.sutore-login-form)", {
    // options go here
  });

  $(document).on("click", ".listing-item .remove", function (e) {
    $(this).parents(".listing-item").remove();
    $(document)
      .find('.variations li[data-id="' + $(this).attr("data-id") + '"]')
      .removeClass("selected");
  });

  $(document).on("keyup", "#product_price", function (e) {
    var salePrice = parseInt($("#product_price").val());
    var taxPrice = (salePrice * window.taxPercent) / 100;
    console.log(taxPrice);
    console.log(salePrice);

    $(".net-gross").text((salePrice - taxPrice).toFixed(2) + "₺");
  });

  $(document).on("click", ".product-action .confirm", function (e) {
    window.updating = $(this);
    Swal.fire({
      icon: "info",
      text: "Ürününüzün satışını onaylıyor musunuz?",
      timerProgressBar: false,
      allowOutsideClick: false,
      showConfirmButton: true,
      showCancelButton: true,
      cancelButtonText: "İptal",
      confirmButtonText: "Onayla",
    }).then((result) => {
      /* Read more about isConfirmed, isDenied below */
      if (result.isConfirmed) {
        $.ajax({
          type: "POST",
          url: sutoreRegister.ajaxurl,
          data: { action: "confirm_sale", product_id: window.updating.attr("data-id"), nonce: sutoreRegister.nonce },
          beforeSend: function (data) {
            Swal.fire({
              text: "Lütfen bekleyin...",
              allowOutsideClick: false,
              showConfirmButton: false,
              didOpen: () => {
                Swal.showLoading();
              },
            });
          },
          success: function (data) {
            Swal.hideLoading();
            console.log(data);
            if (data.status == true) {
              Swal.fire({
                icon: "success",
                text: data.message,
                timer: 3000,
                timerProgressBar: true,
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => {
                  Swal.showLoading();
                },
              }).then((result) => {
                if (result.dismiss === Swal.DismissReason.timer) {
                  location.reload();
                }
              });
            }
            if (data.status == false) {
              Swal.fire("Hata", data.message, "error");
            }
          },
        });
      } else if (result.isDenied) {
        Swal.fire("Changes are not saved", "", "info");
      }
    });

    e.preventDefault();
  });

  function isNumeric(value) {
    return /^-?\d+$/.test(value);
  }

  ///////////////////////// Sold Item Details /////////

  $(document).on("click", ".product-action .shipped", function (e) {
    window.updating = $(this);
    $.ajax({
      type: "POST",
      url: sutoreRegister.ajaxurl,
      data: { action: "confirm_shipment", product_id: window.updating.attr("data-id"), nonce: sutoreRegister.nonce },
      beforeSend: function (data) {
        Swal.fire({
          text: "Lütfen bekleyin...",
          allowOutsideClick: false,
          showConfirmButton: false,
          didOpen: () => {
            Swal.showLoading();
          },
        });
      },
      success: function (data) {
        Swal.hideLoading();
        console.log(data);
        window.firstPlacePrice = data.min;
        window.taxPercent = data.tax_percent;
        if (data.status == true) {
          Swal.fire({
            title: false,
            icon: false,
            html: data.html,
            showCancelButton: true,
            cancelButtonText: "İptal",
            didOpen: () => {
              Swal.hideLoading();
            },
            preConfirm: (login) => {
              if ($("#product_shipment_code").val() == "") {
                Swal.showValidationMessage("Kargo takip numarası girmeden satışı onaylayamazsınız.");
                return;
              }

              if (!isNumeric($("#product_shipment_code").val())) {
                Swal.showValidationMessage("Kargo takip numarası sadece rakamlardan oluşmalıdır.");
                return;
              }

              if ($("#product_shipment_code").val().length != 12) {
                Swal.showValidationMessage("Kargo takip numaranız 12 haneden oluşmalıdır. Lütfen kontrol ediniz.");
                return;
              }
            },
            confirmButtonText: "Onayla",
          }).then((result) => {
            /* Read more about isConfirmed, isDenied below */
            if (result.isConfirmed) {
              $.ajax({
                type: "POST",
                url: sutoreRegister.ajaxurl,
                data: { action: "update_shipment_code", product_id: window.updating.attr("data-id"), product_shipment_code: $("#product_shipment_code").val(), nonce: sutoreRegister.nonce },
                success: function (data) {
                  console.log(data);
                  if (data.status == true) {
                    Swal.fire({
                      icon: "success",
                      text: data.message,
                      timer: 2000,
                      timerProgressBar: true,
                      allowOutsideClick: false,
                      showConfirmButton: false,
                      didOpen: () => {
                        Swal.showLoading();
                      },
                    }).then((result) => {
                      if (result.dismiss === Swal.DismissReason.timer) {
                        location.reload();
                      }
                    });
                  }

                  if (data.status == false) {
                    Swal.fire("Hata", data.message, "error");
                  }
                },
              });
            } else if (result.isDenied) {
              Swal.fire("Changes are not saved", "", "info");
            }
          });
        }
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");
        }
      },
    });

    e.preventDefault();
  });

  $(document).on("click", ".update-merchant-product", function (e) {
    window.updating = $(this);
    $.ajax({
      type: "POST",
      url: sutoreRegister.ajaxurl,
      data: { action: "update_merchant_product", product_id: window.updating.attr("data-id"), nonce: window.updating.attr("data-nonce") },
      beforeSend: function (data) {
        Swal.fire({
          text: "Lütfen bekleyin...",
          allowOutsideClick: false,
          showConfirmButton: false,
          didOpen: () => {
            Swal.showLoading();
          },
        });
      },
      success: function (data) {
        Swal.hideLoading();
        console.log(data);
        if (data.status == true) {
          Swal.fire({
            title: false,
            icon: false,
            html: data.html,
            showCancelButton: true,
            cancelButtonText: "İptal",
            didOpen: () => {
              Swal.hideLoading();
            },
            confirmButtonText: "Durumu Güncelle",
          }).then((result) => {
            /* Read more about isConfirmed, isDenied below */
            if (result.isConfirmed) {
              $.ajax({
                type: "POST",
                url: sutoreRegister.ajaxurl,
                data: { action: "update_product_status", sutore_campaing_discount: $("#sutore_campaing_discount").val(), sutore_campaing_merchant_discount: $("#sutore_campaing_merchant_discount").val(), product_id: window.updating.attr("data-id"), product_status: $("#merchant_product_status").val(), sutore_shipment_code: $("#sutore_shipment_code").val(), merchant_product_id: $("#merchant_product_id").val(), product_order_id: $("#merchant_product_order_id").val(), product_price: $("#merchant_product_price").val(), nonce: window.updating.attr("data-nonce") },
                beforeSend: function (data) {
                  Swal.fire({
                    text: "Lütfen bekleyin...",
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                      Swal.showLoading();
                    },
                  });
                },
                success: function (data) {
                  console.log(data);
                  if (data.status == true) {
                    Swal.fire({
                      icon: "success",
                      text: data.message,
                      timer: 2000,
                      timerProgressBar: true,
                      allowOutsideClick: false,
                      showConfirmButton: false,
                      didOpen: () => {
                        Swal.showLoading();
                      },
                    }).then((result) => {
                      if (result.dismiss === Swal.DismissReason.timer) {
                        location.reload();
                      }
                    });
                  }

                  if (data.status == false) {
                    Swal.fire("Hata", data.message, "error");
                  }
                },
              });
            } else if (result.isDenied) {
              Swal.fire("Changes are not saved", "", "info");
            }
          });
        }
      },
    });

    e.preventDefault();
  });

  $(document).on("click", ".product-action .detail, .merchant-product .detail", function (e) {
    window.updating = $(this);
    $.ajax({
      type: "POST",
      url: sutoreRegister.ajaxurl,
      data: { action: "get_details", product_id: window.updating.attr("data-id"), nonce: sutoreRegister.nonce },
      beforeSend: function (data) {
        Swal.fire({
          text: "Lütfen bekleyin...",
          allowOutsideClick: false,
          showConfirmButton: false,
          didOpen: () => {
            Swal.showLoading();
          },
        });
      },
      success: function (data) {
        Swal.hideLoading();
        console.log(data);
        window.firstPlacePrice = data.min;
        window.taxPercent = data.tax_percent;
        if (data.status == true) {
          Swal.fire({
            title: false,
            icon: false,
            html: data.html,
            showCancelButton: false,
            cancelButtonText: "İptal",
            didOpen: () => {
              Swal.hideLoading();
            },
            confirmButtonText: "Kapat",
          });
        }
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");
        }
      },
    });

    e.preventDefault();
  });

  $(document).on("click", ".user-detail", function (e) {
    window.updating = $(this);
    $.ajax({
      type: "POST",
      url: sutoreRegister.ajaxurl,
      data: { action: "get_user_details", user_id: window.updating.attr("data-userid"), nonce: sutoreRegister.nonce },
      beforeSend: function (data) {
        Swal.fire({
          text: "Lütfen bekleyin...",
          allowOutsideClick: false,
          showConfirmButton: false,
          didOpen: () => {
            Swal.showLoading();
          },
        });
      },
      success: function (data) {
        Swal.hideLoading();
        console.log(data);
        window.firstPlacePrice = data.min;
        window.taxPercent = data.tax_percent;
        if (data.status == true) {
          Swal.fire({
            title: false,
            icon: false,
            html: data.html,
            showCancelButton: false,
            cancelButtonText: "İptal",
            didOpen: () => {
              Swal.hideLoading();
            },
            confirmButtonText: "Kapat",
          });
        }
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");
        }
      },
    });

    e.preventDefault();
  });

  $(document).on("click", ".product-action .update", function (e) {
    window.updating = $(this);
    $.ajax({
      type: "POST",
      url: sutoreRegister.ajaxurl,
      data: { action: "get_price_change_form", product_id: window.updating.attr("data-id"), nonce: sutoreRegister.nonce },
      beforeSend: function (data) {
        Swal.fire({
          text: "Lütfen bekleyin...",
          allowOutsideClick: false,
          showConfirmButton: false,
          didOpen: () => {
            Swal.showLoading();
          },
        });
      },
      success: function (data) {
        Swal.hideLoading();
        console.log(data);
        window.firstPlacePrice = data.min;
        window.taxPercent = data.tax_percent;
        if (data.status == true) {
          Swal.fire({
            title: false,
            icon: false,
            html: data.html,
            showCancelButton: true,
            cancelButtonText: "İptal",
            didOpen: () => {
              Swal.hideLoading();
            },
            preConfirm: (login) => {
              window.product_price = $("#product_price").val();
              window.fast_shipment = $("#fast_shipment:checked").val();
              window.has_invoice = $("#has_invoice:checked").val();

              if ($("#product_price").val() < data.release_price) {
                return Swal.fire({
                  title: "Emin Misiniz?",
                  text: "Belirlemiş olduğunuz fiyat ürünün çıkış fiyatından daha düşük.",
                  icon: "warning",
                  confirmButtonText: "Evet",
                  cancelButtonText: "Hayır",
                  showCancelButton: true,
                }).then((result) => {
                  /* Read more about isConfirmed, isDenied below */
                  if (result.isConfirmed) {
                  } else {
                    Swal.close();
                    return false;
                  }
                });
              }

              if ($("#product_price").val() == "") {
                Swal.showValidationMessage("Lütfen fiyat bilgisi girin.");
                return;
              } else if ($("#product_price").val() % 25 != 0) {
                Swal.showValidationMessage("Belirleyeceğiniz fiyat 25 ve katları olmalıdır.");
                return;
              }
            },
            confirmButtonText: "Güncelle",
          }).then((result) => {
            /* Read more about isConfirmed, isDenied below */
            if (result.isConfirmed) {
              $.ajax({
                type: "POST",
                url: sutoreRegister.ajaxurl,
                data: { action: "update_product_price", product_id: window.updating.attr("data-id"), has_invoice: window.has_invoice, fast_shipment: window.fast_shipment, product_price: window.product_price, nonce: sutoreRegister.nonce },
                beforeSend: function (data) {
                  Swal.fire({
                    text: "Lütfen bekleyin...",
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                      Swal.showLoading();
                    },
                  });
                },
                success: function (data) {
                  console.log(data);
                  if (data.status == true) {
                    Swal.fire({
                      icon: "success",
                      text: data.message,
                      timer: 2000,
                      timerProgressBar: true,
                      allowOutsideClick: false,
                      showConfirmButton: false,
                      didOpen: () => {
                        Swal.showLoading();
                      },
                    }).then((result) => {
                      if (result.dismiss === Swal.DismissReason.timer) {
                        location.reload();
                      }
                    });
                  }

                  if (data.status == false) {
                    Swal.fire("Hata", data.message, "error");
                  }
                },
              });
            } else if (result.isDenied) {
              Swal.fire("Changes are not saved", "", "info");
            }
          });
        }
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");
        }
      },
    });

    e.preventDefault();
  });

  $(document).on("click", ".product-action .remove", function (e) {
    window.deleting = $(this);
    e.preventDefault();
    Swal.fire({
      title: "Emin Misiniz?",
      text: "Seçmiş olduğunuz ürün sistemden silinecek.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Evet",
      cancelButtonText: "Hayır",
    }).then((result) => {
      if (result.isConfirmed) {
        $.ajax({
          type: "POST",
          url: sutoreRegister.ajaxurl,
          data: { action: "delete_vendor_product", product_id: window.deleting.attr("data-id"), nonce: sutoreRegister.nonce },
          beforeSend: function (data) {
            Swal.fire({
              text: "Ürün sistemden siliniyor...",
              allowOutsideClick: false,
              showConfirmButton: false,
              didOpen: () => {
                Swal.showLoading();
              },
            });
          },
          success: function (data) {
            Swal.hideLoading();
            console.log(data);
            if (data.status == true) {
              Swal.fire({
                icon: "success",
                text: data.message,
                timer: 2000,
                timerProgressBar: true,
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => {
                  Swal.showLoading();
                },
              }).then((result) => {
                if (result.dismiss === Swal.DismissReason.timer) {
                  location.reload();
                }
              });
            }
            if (data.status == false) {
              Swal.fire("Hata", data.message, "error");
            }
          },
        });
      } else if (
        /* Read more about handling dismissals below */
        result.dismiss === Swal.DismissReason.cancel
      ) {
        /// cancel
      }
    });
  });

  $("#product_code").keyup(function (e) {
    if ($(this).val().length >= 3) {
      $.ajax({
        type: "POST",
        url: sutoreRegister.ajaxurl,
        data: { action: "search_product", product_code: $("#product_code").val(), not_exists: $("#not_exists").val(), nonce: sutoreRegister.nonce, product_status: $("#product_status").val() },
        success: function (data) {
          $(".product-dropdown").html(data).show();
          if (data.status == true) {
            Swal.fire("Başarılı", data.message, "success");
          }
          if (data.status == false) {
            Swal.fire("Hata", data.message, "error");
          }
        },
      });
    }
  });

  // $(document).on("change","#product_category", function(e) {
  //     if($(this).find("option:selected").val() != ""){
  //         $("#product_status").attr("disabled",false);
  //     }else {
  //         $("#product_status").attr("disabled",true);
  //     }
  //
  // });

  $(document).on("change", "#product_status", function (e) {
    $(".product-dropdown").hide();
    $("#product_code").val("");
    if ($(this).find("option:selected").val() == "1") {
      $("#product_type").attr("disabled", false);
      $("#product_code").attr("disabled", false);
      $("#not_exists").val("0");
    } else if ($(this).find("option:selected").val() == "2") {
      $("#product_type").attr("disabled", false);
      $("#product_code").attr("disabled", false);
      $("#not_exists").val("1");
    } else {
      $("#product_type").attr("disabled", true);
      $("#product_code").attr("disabled", true);
    }
  });

  $(document).on("change", "#product_type", function (e) {
    if ($(this).find("option:selected").val() != "") {
      if ($(this).find("option:selected").val() == 1) {
        $("#product_code").attr("disabled", false).show();
      } else if ($(this).find("option:selected").val() == 2) {
        $("#product_code").attr("disabled", true).val("").hide();
        $(".variations").html("");
        $.ajax({
          type: "POST",
          url: sutoreRegister.ajaxurl,
          data: { action: "get_simple_product_details", nonce: sutoreRegister.nonce },
          beforeSend: function (data) {
            Swal.fire({
              text: "Lütfen bekleyin...",
              allowOutsideClick: false,
              showConfirmButton: false,
              didOpen: () => {
                Swal.showLoading();
              },
            });
          },
          success: function (data) {
            Swal.hideLoading();
            console.log(data);
            if (data.status == true) {
              Swal.fire({
                title: false,
                icon: false,
                html: data.html,
                showCancelButton: true,
                cancelButtonText: "İptal",
                didOpen: () => {
                  Swal.hideLoading();
                },
                preConfirm: (login) => {
                  if ($("#product_price").val() == "") {
                    Swal.showValidationMessage("Lütfen fiyat bilgisi girin.");
                    return;
                  } else if ($("#product_price").val() % 25 != 0) {
                    Swal.showValidationMessage("Belirleyeceğiniz fiyat 25 ve katları olmalıdır.");
                    return;
                  }
                },
                confirmButtonText: "Ürünü Ekle",
              }).then((result) => {
                /* Read more about isConfirmed, isDenied below */
                if (result.isConfirmed) {
                  $(document)
                    .find('.variations li[data-id="' + window.productID + '"]')
                    .addClass("selected");
                  $.ajax({
                    type: "POST",
                    url: sutoreRegister.ajaxurl,
                    data: { action: "add_product_to_list", product_id: window.productID, has_invoice: $("#has_invoice").val(), fast_shipment: $("#fast_shipment").val(), product_price: $("#product_price").val(), no_box: $("#no_box").val(), tried_product: $("#tried_product").val(), damaged_product: $("#damaged_product").val(), damaged: $("#damaged").val(), missing: $("#missing").val(), not_exists: $("#not_exists").val(), product_status: $("#product_status").val(), product_condition: $("#product_condition").val(), product_box_condition: $("#product_box_condition").val(), product_desc: $("#product_desc").val(), nonce: sutoreRegister.nonce },
                    success: function (data) {
                      console.log(data);
                      if (data.status == true) {
                        //$(".adding-products").append(data.html);

                        Swal.fire("Başarılı", data.message, "success");
                      }
                    },
                  });
                } else if (result.isDenied) {
                  Swal.fire("Changes are not saved", "", "info");
                }
              });
            }
            if (data.status == false) {
              Swal.fire("Hata", data.message, "error");
            }
          },
        });
      }
    } else {
      $("#product_type").attr("disabled", true);
    }
  });

  $(document).on("change", "input[name='product_def']", function (e) {
    if ($(this).is(":checked")) {
      $("#first_place_price").hide();
    } else {
      if ($("input[name='product_def']:checked").length == 0) {
        $("#first_place_price").show();
      }
    }
  });

  $(document).on("click", ".product-dropdown .product:not(.new)", function (e) {
    $(".product-dropdown").hide();
    $.ajax({
      type: "POST",
      url: sutoreRegister.ajaxurl,
      data: { action: "get_product_variations", product_id: $(this).attr("data-id"), product_status: $("#product_status").val(), nonce: sutoreRegister.nonce },
      beforeSend: function (data) {
        Swal.fire({
          text: "Beden bilgileri alınıyor...",
          showConfirmButton: false,
          didOpen: () => {
            Swal.showLoading();
          },
        });
      },
      success: function (data) {
        console.log(data);
        Swal.close();
        if (data.status == true) {
          $("ul.variations").html(data.html);
          $(".adding-products ul").each(function () {
            $(document)
              .find('.variations li[data-id="' + $(this).attr("data-id") + '"]')
              .addClass("selected");
          });
        }
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");
        }
      },
    });
  });

  function addProduct(id, img, title, price) {
    $(".adding-products ul").append("");
  }

  $(document).on("click", "#first_place_price", function (e) {
    console.log(window.firstPlacePrice);
    if (window.firstPlacePrice >= 25) {
      $("#product_price").val(window.firstPlacePrice);
      var salePrice = parseInt($("#product_price").val());
      var taxPrice = (salePrice * window.taxPercent) / 100;
      console.log(taxPrice);
      console.log(salePrice);
      $(".net-gross").text((salePrice - taxPrice).toFixed(2) + "₺");
    }
  });

  $(document).on("change", "#product_photos", function () {
    for (var i = 0; i < this.files.length; i++) {
      var file = this.files[i];
      size = file.size;
      if (size > 2097152) {
        Swal.showValidationMessage("Yüklediğiniz görsellerden bir veya birkaçı 2MB'nin üzerindedir. Lütfen görsel dosya boyutlarınızı kontrol edin.");
      }
      console.log(size);
    }
  });

  $(document).on("click", ".variations li:not(.selected), .product.new", function (e) {
    $(".product-dropdown").hide();
    console.log("gg");
    window.productID = $(this).attr("data-id");
    window.termID = $(this).attr("data-term-id");
    $.ajax({
      type: "POST",
      url: sutoreRegister.ajaxurl,
      data: { action: "get_product_details", term_id: $(this).attr("data-term-id"), product_id: $(this).attr("data-id"), product_status: $("#product_status").val(), not_exists: $("#not_exists").val(), nonce: sutoreRegister.nonce },
      beforeSend: function (data) {
        Swal.fire({
          text: "Ürün bilgileri alınıyor...",
          showConfirmButton: false,
          didOpen: () => {
            Swal.showLoading();
          },
        });
      },
      error: function (xhr, ajaxOptions, thrownError) {
        console.log(xhr.status);
        console.log(xhr.responseText);
        console.log(thrownError);
      },
      success: function (data) {
        Swal.hideLoading();
        console.log(data);
        window.firstPlacePrice = data.min;
        window.taxPercent = data.tax_percent;
        window.release_price = data.release_price;
        if (data.status == true) {
          Swal.fire({
            title: false,
            icon: false,
            html: data.html,
            showCancelButton: true,
            cancelButtonText: "İptal",
            didOpen: () => {
              Swal.hideLoading();
            },
            preConfirm: (login) => {
              window.product_price = $("#product_price").val();
              window.no_box = $("#no_box:checked").val();
              window.damaged = $("#damaged:checked").val();
              window.damaged_product = $("#damaged_product:checked").val();
              window.tried_product = $("#tried_product:checked").val();
              window.fast_shipment = $("#fast_shipment:checked").val();
              window.has_invoice = $("#has_invoice:checked").val();
              window.missing = $("#missing:checked").val();
              window.not_exists = $("#not_exists:checked").val();
              window.product_status = $("#product_status:checked").val();
              window.product_status = $("#product_status:checked").val();
              window.product_condition = $("#product_condition:checked").val();
              window.product_box_condition = $("#product_box_condition:checked").val();

              if ($("#product_price").val() < data.release_price) {
                return Swal.fire({
                  title: "Emin Misiniz?",
                  text: "Belirlemiş olduğunuz fiyat ürünün çıkış fiyatından daha düşük.",
                  icon: "warning",
                  confirmButtonText: "Evet",
                  cancelButtonText: "Hayır",
                  showCancelButton: true,
                }).then((result) => {
                  /* Read more about isConfirmed, isDenied below */
                  if (result.isConfirmed) {
                  } else {
                    Swal.close();
                    return false;
                  }
                });
              }

              if ($("#product_price").val() == "") {
                Swal.showValidationMessage("Lütfen fiyat bilgisi girin.");
                return;
              } else if ($("#product_price").val() % 25 != 0) {
                Swal.showValidationMessage("Belirleyeceğiniz fiyat 25 ve katları olmalıdır.");
                return;
              } else if ($("#product_condition").val() == "" || $("#product_condition").val() > 10) {
                Swal.showValidationMessage("Lütfen ürüne ait genel kondisyon bilgisini doğru formatta girin.");
                return;
              } else if ($("#product_box_condition").val() == "" || $("#product_box_condition").val() > 10) {
                Swal.showValidationMessage("Lütfen ürün kutusuna ait kondisyon bilgisini doğru formatta girin.");
                return;
              } else if ($("#product_photos").length && parseInt($("#product_photos").get(0).files.length) > 5) {
                Swal.showValidationMessage("En fazla 5 adet görsel yükleyebilirsiniz.");
                return;
              } else if ($("#product_photos").length && parseInt($("#product_photos").get(0).files.length) < 4) {
                Swal.showValidationMessage("En az 4 adet görseli yüklemelisiniz.");
                return;
              }
            },
            confirmButtonText: "Ürünü Ekle",
          }).then((result) => {
            /* Read more about isConfirmed, isDenied below */
            if (result.isConfirmed) {
              var file_data = $("#product_photos");
              var form_data = new FormData();
              $.each($(file_data), function (i, obj) {
                $.each(obj.files, function (j, file) {
                  form_data.append("files[" + j + "]", file);
                });
              });
              //form_data.append('file', file_data);
              form_data.append("action", "add_product_to_list");
              form_data.append("product_id", window.productID);
              form_data.append("term_id", window.termID);
              form_data.append("product_price", window.product_price);
              form_data.append("no_box", window.no_box);
              form_data.append("damaged", window.damaged);
              form_data.append("damaged_product", window.damaged_product);
              form_data.append("tried_product", window.tried_product);
              form_data.append("fast_shipment", window.fast_shipment);
              form_data.append("has_invoice", window.has_invoice);
              form_data.append("missing", window.missing);
              form_data.append("not_exists", window.not_exists);
              form_data.append("product_status", window.product_status);
              form_data.append("product_condition", window.product_condition);
              form_data.append("product_box_condition", window.product_box_condition);
              // form_data.append('product_price', $("#product_price").val());
              // form_data.append('no_box', $("#no_box:checked").val());
              // form_data.append('damaged', $("#damaged:checked").val());
              // form_data.append('damaged_product', $("#damaged_product:checked").val());
              // form_data.append('fast_shipment', $("#fast_shipment:checked").val());
              // form_data.append('has_invoice', $("#has_invoice:checked").val());
              // form_data.append('tried_product', $("#tried_product:checked").val());
              // form_data.append('missing', $("#missing:checked").val());
              // form_data.append('not_exists', $("#not_exists").val());
              // form_data.append('product_status', $("#product_status").val());
              // form_data.append('product_condition', $("#product_condition").val());
              // form_data.append('product_box_condition', $("#product_box_condition").val());
              form_data.append("product_desc", $("#product_desc").val());
              form_data.append("product_title", $("#product_title").val());
              form_data.append("product_size", $("#product_size").val());
              form_data.append("product_new_code", $("#product_new_code").val());
              form_data.append("nonce", sutoreRegister.nonce);
              $(document)
                .find('.variations li[data-term-id="' + window.termID + '"]')
                .addClass("selected");
              $.ajax({
                type: "POST",
                cache: false,
                contentType: false,
                processData: false,
                url: sutoreRegister.ajaxurl,
                //data: {action: "add_product_to_list", product_photos: $('#product_photos').prop('files')[0], product_id: window.productID, product_price: $("#product_price").val(), no_box: $("#no_box").val(), damaged: $("#damaged").val(), missing:$("#missing").val(),  not_exists: $("#not_exists").val(), product_status: $("#product_status").val(), product_condition: $("#product_condition").val(), product_box_condition: $("#product_box_condition").val(), product_desc: $("#product_desc").val(), nonce: sutoreRegister.nonce},
                data: form_data,
                beforeSend: function (data) {
                  Swal.fire({
                    text: "Lütfen bekleyin...",
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                      Swal.showLoading();
                    },
                  });
                },
                success: function (data) {
                  Swal.hideLoading();
                  console.log(data);
                  if (data.status == true) {
                    //$(".adding-products").append(data.html);

                    Swal.fire("Başarılı", data.message, "success");
                  } else {
                    Swal.fire("Hata", data.message, "error");
                  }
                },
              });
            } else if (result.isDenied) {
              Swal.fire("Changes are not saved", "", "info");
            }
          });
        }
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");
        }
      },
    });
  });

  $("form#update_billing_adress").submit(function (e) {
    var buttonval = $(this).find("input[type='submit']").val();
    var x = $(this);
    e.preventDefault();
    window.formData = new FormData($(this)[0]);
    window.formData.append("action", "update_billing_adress");
    $.ajax({
      type: "POST",
      enctype: "multipart/form-data",
      processData: false,
      contentType: false,
      cache: false,
      url: sutoreRegister.ajaxurl,
      data: window.formData,
      beforeSend: function (data) {
        x.find("input[type='submit']").attr("disabled", true).val("Lütfen Bekleyin...");
      },
      success: function (data) {
        console.log(data);
        if (data.status == true) {
          Swal.fire("Başarılı", data.message, "success");
        }
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");
        }
      },
    });
  });

  $("form.sms-validate").submit(function (e) {
    var buttonval = $(this).find("input[type='submit']").val();
    var x = $(this);
    e.preventDefault();
    window.formData = new FormData($(this)[0]);
    window.smsData = new FormData($(this)[0]);
    window.smsData.append("action", "send_sms");
    console.log(window.formData);
    $.ajax({
      type: "POST",
      enctype: "multipart/form-data",
      processData: false,
      contentType: false,
      cache: false,
      url: sutoreRegister.ajaxurl,
      data: window.smsData,
      beforeSend: function (data) {
        x.find("input[type='submit']").attr("disabled", true).val("Lütfen Bekleyin...");
      },
      success: function (data) {
        console.log(data);
        x.find("input[type='submit']").attr("disabled", false).val(buttonval);
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");

          return;
        }

        let timerInterval;
        Swal.fire({
          title: data.sms,
          html: '<p>Lütfen gönderilen kodu <b></b> saniye içinde girin.<p><p class="form-row form-row-wide validate-required"><span class="woocommerce-input-wrapper"><div class="fl-wrap fl-wrap-input"><input type="text" class="input-text fl-input"  id="sms_code" placeholder="Doğrulama Kodu" value=""></div></span></p><p class="ajax_message"></p>',
          timer: 120000,
          timerProgressBar: true,
          confirmButtonText: "Doğrula",
          preConfirm: (login) => {
            window.formData.append("sms_code", $(document).find("#sms_code").val());
            $.ajax({
              type: "POST",
              url: sutoreRegister.ajaxurl,
              data: window.formData,
              enctype: "multipart/form-data",
              processData: false,
              contentType: false,
              cache: false,
              async: false,
              timeout: 800000,
              success: function (data) {
                console.log(data);
                if (data.status == false) {
                  Swal.showValidationMessage(data.message);
                }
                if (data.status == true) {
                  if (data.merchant) {
                    Swal.fire({
                      title: "Başarılı",
                      text: data.message,
                      icon: "success",
                      timer: 3000,
                      timerProgressBar: true,
                      allowOutsideClick: false,
                      showConfirmButton: false,
                    }).then((result) => {
                      if (result.dismiss === Swal.DismissReason.timer) {
                        location.reload();
                      }
                    });
                  } else {
                    Swal.fire("Başarılı", data.message, "success");
                  }
                }
              },
            });
          },
          didOpen: () => {
            timerInterval = setInterval(() => {
              const content = Swal.getHtmlContainer();
              if (content) {
                const b = content.querySelector("b");
                if (b) {
                  b.textContent = (Swal.getTimerLeft() / 1000).toFixed(0);
                }
              }
            }, 100);
          },
          willClose: () => {
            clearInterval(timerInterval);
          },
        }).then((result) => {
          /* Read more about handling dismissals below */
          if (result.dismiss === Swal.DismissReason.timer) {
            console.log("I was closed by the timer");
          }
        });
      },
    });
  });

  //$("#reg_phone").before('<select name="reg_phone_code" class="select fl-select phone-code"><option value="+90">+90</option></select>');

  $("#registerForm").submit(function (e) {
    e.preventDefault();
    var btnText = $("button#registerButton").text();
    $(".ajax_message").empty();
    var formData = $("#registerForm").serialize();
    $.ajax({
      type: "POST",
      dataType: "json",
      url: sutoreRegister.ajaxurl,
      data: {
        action: "custom_vendor_form_submit",
        formData: formData,
        nonce: sutoreRegister.nonce,
      },
      beforeSend: function () {
        $("button#registerButton").html("Lütfen Bekleyin...");
        $("button#registerButton").attr("disabled", true);
      },
      success: function (data) {
        console.log(data);
        if (data.status == true) {
          Swal.fire({
            title: "Başarılı",
            text: data.message,
            icon: "success",
            timer: 5000,
            timerProgressBar: true,
            allowOutsideClick: false,
            showConfirmButton: false,
          }).then((result) => {
            if (result.dismiss === Swal.DismissReason.timer) {
              window.location.href = data.url;
            }
          });
        }
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");
        }
        //$('.ajax_message').html('<ul class="woocommerce-error"><li>'+data.message+'</li></ul>');
        $("button#registerButton").html(btnText);
        $("button#registerButton").removeAttr("disabled");
        $("a[data-open]").magnificPopup({
          closeBtnInside: false,
          items: {
            src: "#login-form-popup",
            type: "inline",
          },
        });
      },
    });
  });

  $("#reg_country").on("change", function () {
    if ($("#reg_country").val() == "TR") {
      $("#reg_city_text_field").hide();
      $("#reg_city_field").show();
    } else {
      $("#reg_city_text_field").show();
      $("#reg_city_field").hide();
    }
    $.ajax({
      type: "POST",
      dataType: "json",
      url: sutoreRegister.ajaxurl,
      data: {
        action: "register_country_change",
        country: $("#reg_country").val(),
        nonce: sutoreRegister.nonce,
      },
      success: function (data) {
        $("#reg_state").empty();
        $("#reg_state").append($("<option></option>").attr("value", "").text(sutoreRegister.state));
        if (data.status == 1 && data.states) {
          $("#reg_state_text_field").hide();
          $("#reg_state_field").show();
          $.each(data.states, function (key, value) {
            $("#reg_state").append($("<option></option>").attr("value", key).text(value));
          });
        } else {
          $("#reg_state_text_field").show();
          $("#reg_state_field").hide();
        }
      },
      error: function (xhr, textStatus, thrownError) {
        console.log(xhr);
        console.log(textStatus);
        console.log(thrownError);
      },
    });
  });
  $("#reg_state").on("change", function () {
    if ($("#reg_country").val() == "TR") {
      var state = $("#reg_state").val();
      $.ajax({
        type: "POST",
        dataType: "json",
        url: sutoreRegister.ajaxurl,
        data: {
          action: "register_state_change",
          state: state,
          nonce: sutoreRegister.nonce,
        },
        success: function (data) {
          $("#reg_city").empty();
          $("#reg_city").append($("<option></option>").attr("value", "").text(sutoreRegister.city));
          if (data.status == 1 && data.cities) {
            $.each(data["cities"][state], function (key, value) {
              $("#reg_city").append($("<option></option>").attr("value", value).text(value));
            });
          }
        },
        error: function (xhr, textStatus, thrownError) {
          console.log(xhr);
          console.log(textStatus);
          console.log(thrownError);
        },
      });
    }
  });

  $("#billing_country").on("change", function () {
    if ($("#billing_country option:selected").val() == "TR") {
      $("#billing_states_field").show();
      $("#billing_state_field").show();
      $("#billing_city_field").show();
    } else {
      $("#billing_states_field").hide();
      $("#billing_state_field").hide();
      $("#billing_city_field").hide();
      $("#billing_states").val($("#billing_states option:first").val());
      $("#billing_state").val($("#billing_state option:first").val());
      $("#billing_city").val($("#billing_city option:first").val());
    }
  });

  $("#billing_states").on("change", function () {
    if ($("#billing_country option:selected").val() == "TR") {
      var state = $("#billing_states").val();
      $.ajax({
        type: "POST",
        dataType: "json",
        url: sutoreRegister.ajaxurl,
        data: {
          action: "register_state_change",
          state: state,
          nonce: sutoreRegister.nonce,
        },
        success: function (data) {
          $("#billing_city").empty();
          $("#billing_city").append($("<option></option>").attr("value", "").text(sutoreRegister.city));
          if (data.status == 1 && data.cities) {
            $.each(data["cities"][state], function (key, value) {
              $("#billing_city").append($("<option></option>").attr("value", value).text(value));
            });
          }
        },
        error: function (xhr, textStatus, thrownError) {
          console.log(xhr);
          console.log(textStatus);
          console.log(thrownError);
        },
      });
    }
  });

  $("#billing_state").on("change", function () {
    if ($("#billing_country option:selected").val() == "TR") {
      var state = $("#billing_state").val();
      $.ajax({
        type: "POST",
        dataType: "json",
        url: sutoreRegister.ajaxurl,
        data: {
          action: "register_state_change",
          state: state,
          nonce: sutoreRegister.nonce,
        },
        success: function (data) {
          $("#billing_city").empty();
          $("#billing_city").append($("<option></option>").attr("value", "").text(sutoreRegister.city));
          if (data.status == 1 && data.cities) {
            $.each(data["cities"][state], function (key, value) {
              $("#billing_city").append($("<option></option>").attr("value", value).text(value));
            });
          }
        },
        error: function (xhr, textStatus, thrownError) {
          console.log(xhr);
          console.log(textStatus);
          console.log(thrownError);
        },
      });
    }
  });

  $("#account_city").on("change", function () {
    var state = $("#account_city").val();
    $.ajax({
      type: "POST",
      dataType: "json",
      url: sutoreRegister.ajaxurl,
      data: {
        action: "register_state_change",
        state: state,
        nonce: sutoreRegister.nonce,
      },
      success: function (data) {
        //console.log(data);
        $("#account_state").empty();
        $("#account_state").append($("<option></option>").attr("value", "").text(sutoreRegister.city));
        if (data.status == 1 && data.cities) {
          $.each(data["cities"][state], function (key, value) {
            $("#account_state").append($("<option></option>").attr("value", value).text(value));
          });
        }
      },
      error: function (xhr, textStatus, thrownError) {
        console.log(xhr);
        console.log(textStatus);
        console.log(thrownError);
      },
    });
  });

  function validateSize(input) {
    const fileSize = input.files[0].size / 1024 / 1024; // in MiB
    if (fileSize > 2) {
      alert("File size exceeds 2 MiB");
      // $(file).val(''); //for clearing with Jquery
    } else {
      // Proceed further
    }
  }

  $(document).on("click", ".show-campaing", function (e) {
    window.updating = $(this);
    $.ajax({
      type: "POST",
      url: sutoreRegister.ajaxurl,
      data: { action: "get_campaing_dialog", product_id: window.updating.attr("data-id"), nonce: sutoreRegister.nonce },
      beforeSend: function (data) {
        Swal.fire({
          text: "Lütfen bekleyin...",
          allowOutsideClick: false,
          showConfirmButton: false,
          didOpen: () => {
            Swal.showLoading();
          },
        });
      },
      success: function (data) {
        Swal.hideLoading();
        console.log(data);
        if (data.status == true) {
          Swal.fire({
            title: false,
            icon: false,
            html: data.html,
            showCancelButton: true,
            showConfirmButton: true,
            showDenyButton: true,
            denyButtonText: "Reddet",
            confirmButtonText: "Onayla",
            didOpen: () => {
              Swal.hideLoading();
            },
          }).then((result) => {
            /* Read more about isConfirmed, isDenied below */
            if (result.isConfirmed) {
              $.ajax({
                type: "POST",
                url: sutoreRegister.ajaxurl,
                data: { action: "confirm_campaing", product_id: window.updating.attr("data-id"), nonce: sutoreRegister.nonce },
                beforeSend: function (data) {
                  Swal.fire({
                    text: "Lütfen bekleyin...",
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                      Swal.showLoading();
                    },
                  });
                },
                success: function (data) {
                  Swal.hideLoading();
                  console.log(data);
                  if (data.status == true) {
                    Swal.fire({
                      icon: "success",
                      text: data.message,
                      timer: 3000,
                      timerProgressBar: true,
                      allowOutsideClick: false,
                      showConfirmButton: false,
                      didOpen: () => {
                        Swal.showLoading();
                      },
                    }).then((result) => {
                      if (result.dismiss === Swal.DismissReason.timer) {
                        location.reload();
                      }
                    });
                  }
                  if (data.status == false) {
                    Swal.fire("Hata", data.message, "error");
                  }
                },
              });
            } else if (result.isDenied) {
              $.ajax({
                type: "POST",
                url: sutoreRegister.ajaxurl,
                data: { action: "reject_campaing", product_id: window.updating.attr("data-id"), nonce: sutoreRegister.nonce },
                beforeSend: function (data) {
                  Swal.fire({
                    text: "Lütfen bekleyin...",
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                      Swal.showLoading();
                    },
                  });
                },
                success: function (data) {
                  Swal.hideLoading();
                  console.log(data);
                  if (data.status == true) {
                    Swal.fire({
                      icon: "success",
                      text: data.message,
                      timer: 3000,
                      timerProgressBar: true,
                      allowOutsideClick: false,
                      showConfirmButton: false,
                      didOpen: () => {
                        Swal.showLoading();
                      },
                    }).then((result) => {
                      if (result.dismiss === Swal.DismissReason.timer) {
                        location.reload();
                      }
                    });
                  }
                  if (data.status == false) {
                    Swal.fire("Hata", data.message, "error");
                  }
                },
              });
            }
          });
        }
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");
        }
      },
    });

    e.preventDefault();
  });

  $(document).on("click", ".campaing-details", function (e) {
    window.updating = $(this);
    $.ajax({
      type: "POST",
      url: sutoreRegister.ajaxurl,
      data: { action: "get_campaing_details", product_id: window.updating.attr("data-id"), nonce: sutoreRegister.nonce },
      beforeSend: function (data) {
        Swal.fire({
          text: "Lütfen bekleyin...",
          allowOutsideClick: false,
          showConfirmButton: false,
          didOpen: () => {
            Swal.showLoading();
          },
        });
      },
      success: function (data) {
        Swal.hideLoading();
        console.log(data);
        if (data.status == true) {
          Swal.fire({
            title: false,
            icon: false,
            html: data.html,
            showCancelButton: true,
            showConfirmButton: false,
            showDenyButton: true,
            denyButtonText: "Reddet",
            didOpen: () => {
              Swal.hideLoading();
            },
          }).then((result) => {
            /* Read more about isConfirmed, isDenied below */
            if (result.isDenied) {
              $.ajax({
                type: "POST",
                url: sutoreRegister.ajaxurl,
                data: { action: "reject_campaing", product_id: window.updating.attr("data-id"), nonce: sutoreRegister.nonce },
                beforeSend: function (data) {
                  Swal.fire({
                    text: "Lütfen bekleyin...",
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                      Swal.showLoading();
                    },
                  });
                },
                success: function (data) {
                  Swal.hideLoading();
                  console.log(data);
                  if (data.status == true) {
                    Swal.fire({
                      icon: "success",
                      text: data.message,
                      timer: 3000,
                      timerProgressBar: true,
                      allowOutsideClick: false,
                      showConfirmButton: false,
                      didOpen: () => {
                        Swal.showLoading();
                      },
                    }).then((result) => {
                      if (result.dismiss === Swal.DismissReason.timer) {
                        location.reload();
                      }
                    });
                  }
                  if (data.status == false) {
                    Swal.fire("Hata", data.message, "error");
                  }
                },
              });
            }
          });

          if (data.detail == true) {
            $(".swal2-deny").remove();
          }
        }
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");
        }
      },
    });

    e.preventDefault();
  });

  $(document).on("click", ".product-action .pre-order-detail, .merchant-product .pre-order-detail", function (e) {
    window.updating = $(this);
    $.ajax({
      type: "POST",
      url: sutoreRegister.ajaxurl,
      data: { action: "get_pre_order_details", product_id: window.updating.attr("data-id"), nonce: sutoreRegister.nonce },
      beforeSend: function (data) {
        Swal.fire({
          text: "Lütfen bekleyin...",
          allowOutsideClick: false,
          showConfirmButton: false,
          didOpen: () => {
            Swal.showLoading();
          },
        });
      },
      success: function (data) {
        Swal.hideLoading();
        console.log(data);
        if (data.status == true) {
          Swal.fire({
            title: false,
            icon: false,
            html: data.html,
            showCancelButton: true,
            cancelButtonText: "Kapat",
            didOpen: () => {
              Swal.hideLoading();
            },
            confirmButtonText: "Satışı Kabul Et",
            preConfirm: () => {
              Swal.fire({
                icon: "warning",
                html: "Onay vermeniz hâlinde, ürünü <strong>" + data.date + "</strong> tarihine kadar kontrol merkezine eksiksiz ve hasarsız olarak ulaştırmayı, aykırılık hâlinde doğacak <strong>iptal/iade</strong> ve tüm zararlardan münhasıran sorumlu olacağınızı kabul etmiş sayılırsınız.",
                allowOutsideClick: false,
                showConfirmButton: true,
                showCancelButton: true,
                cancelButtonText: "Vazgeç",
                confirmButtonText: "Kabul Ediyorum",
                reverseButtons: true,
              }).then((result) => {
                /* Read more about isConfirmed, isDenied below */
                if (result.isConfirmed) {
                  $.ajax({
                    type: "POST",
                    url: sutoreRegister.ajaxurl,
                    data: {
                      action: "confirm_pre_order",
                      product_id: window.updating.attr("data-id"),
                      nonce: sutoreRegister.nonce,
                    },
                    beforeSend: function () {
                      Swal.fire({
                        text: "Lütfen bekleyin...",
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        didOpen: () => {
                          Swal.showLoading();
                        },
                      });
                    },
                    success: function (data) {
                      Swal.hideLoading();
                      console.log(data);
                      if (data.status == true) {
                        Swal.fire({
                          icon: "success",
                          text: data.message,
                          timer: 3000,
                          timerProgressBar: true,
                          allowOutsideClick: false,
                          showConfirmButton: false,
                          didOpen: () => {
                            Swal.showLoading();
                          },
                        }).then((result) => {
                          if (result.dismiss === Swal.DismissReason.timer) {
                            location.reload();
                          }
                        });
                      }
                      if (data.status == false) {
                        Swal.fire("Hata", data.message, "error");
                      }
                    },
                    error: function (xhr, textStatus, thrownError) {
                      console.log(xhr);
                      console.log(textStatus);
                      console.log(thrownError);
                    },
                  });
                } else if (result.isDenied) {
                  Swal.fire("Changes are not saved", "", "info");
                }
              });
            },
          });
        }
        if (data.status == false) {
          Swal.fire("Hata", data.message, "error");
        }
      },
    });

    e.preventDefault();
  });
});
