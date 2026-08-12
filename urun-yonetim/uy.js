jQuery(document).ready(function ($) {

    $(".uy-urun-listesi").on("click", ".uy-delete-product", function () {

        if (!confirm("Bu ürünü silmek istediğinize emin misiniz?")) {
            return;
        }

        let product_id = $(this).data("id");
        let btn = $(this);

        btn
            .prop("disabled", true)
            .text("Siliniyor...");

        $.post(
            uy_ajax.url,
            {
                action: "uy_delete_product",
                product_id: product_id,
                nonce: uy_ajax.nonce
            }
        )
        .done(function (response) {

            if (response.success) {

                btn
                    .closest(".uy-urun-item")
                    .fadeOut(300, function () {
                        $(this).remove();
                    });

            } else {

                let message = response.data
                    ? response.data
                    : "Silme işlemi başarısız.";

                alert("Hata: " + message);
            }
        })
        .fail(function () {

            alert("Sunucuyla bağlantı kurulamadı.");

        })
        .always(function () {

            btn
                .prop("disabled", false)
                .text("Sil");
        });
    });

});