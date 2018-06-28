$(document).ready(function () {
    $('#cart-form, .purchase.addtocart').submit(function () {
        $.post('/shop/metrika/cart/addInfo', $(this).serialize(), function (response) {
            if (response) {
                window.dataLayer = window.dataLayer || [];
                dataLayer.push({ecommerce: {add: {products: [JSON.parse(response)]}}});
            }
        });
    });
});
