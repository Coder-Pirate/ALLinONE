/* eslint-disable */
(function ($) {
    'use strict';

    var products = [];
    var searchTimer = null;

    /* ── Product search ─────────────────────────────────── */
    $('#coc_co_product_search').on('input', function () {
        var term = $(this).val().trim();
        clearTimeout(searchTimer);

        if (term.length < 2) {
            $('#coc-co-search-results').hide().empty();
            return;
        }

        searchTimer = setTimeout(function () {
            $.ajax({
                url: COC_CO.ajax_url,
                data: { action: 'coc_search_products', nonce: COC_CO.nonce, term: term },
                success: function (res) {
                    var $wrap = $('#coc-co-search-results').empty();
                    if (!res.success || !res.data.length) {
                        $wrap.append('<div class="coc-co-sr-item coc-co-sr-empty">No products found</div>');
                        $wrap.show();
                        return;
                    }
                    $.each(res.data, function (i, p) {
                        var $item = $('<div class="coc-co-sr-item" />');
                        $item.text(p.name + '  —  ' + COC_CO.currency + parseFloat(p.price).toFixed(2));
                        $item.data('product', p);
                        $wrap.append($item);
                    });
                    $wrap.show();
                }
            });
        }, 300);
    });

    // Select product from dropdown
    $(document).on('click', '.coc-co-sr-item:not(.coc-co-sr-empty)', function () {
        var p = $(this).data('product');
        addProduct(p);
        $('#coc_co_product_search').val('');
        $('#coc-co-search-results').hide().empty();
    });

    // Hide dropdown on outside click
    $(document).on('click', function (e) {
        if (!$(e.target).closest('#coc_co_product_search, #coc-co-search-results').length) {
            $('#coc-co-search-results').hide();
        }
    });

    /* ── Add product to table ───────────────────────────── */
    function addProduct(p) {
        // Check if already added
        for (var i = 0; i < products.length; i++) {
            if (products[i].id === p.id) {
                products[i].qty++;
                renderProducts();
                return;
            }
        }
        products.push({ id: p.id, name: p.name, price: parseFloat(p.price), qty: 1 });
        renderProducts();
    }

    function renderProducts() {
        var $tbody = $('#coc-co-product-rows');
        $tbody.empty();

        if (!products.length) {
            $tbody.append('<tr id="coc-co-no-products"><td colspan="5" style="text-align:center;color:#999;">No products added yet.</td></tr>');
            recalc();
            return;
        }

        $.each(products, function (i, p) {
            var lineTotal = (p.price * p.qty).toFixed(2);
            var row = '<tr data-idx="' + i + '">' +
                '<td>' + escHtml(p.name) + '</td>' +
                '<td>' + COC_CO.currency + p.price.toFixed(2) + '</td>' +
                '<td><input type="number" class="coc-co-qty" value="' + p.qty + '" min="1" style="width:60px" /></td>' +
                '<td>' + COC_CO.currency + lineTotal + '</td>' +
                '<td><button type="button" class="button coc-co-remove" title="Remove">&times;</button></td>' +
                '</tr>';
            $tbody.append(row);
        });

        recalc();
    }

    // Qty change
    $(document).on('change input', '.coc-co-qty', function () {
        var idx = $(this).closest('tr').data('idx');
        var val = parseInt($(this).val(), 10);
        if (val < 1) val = 1;
        products[idx].qty = val;
        renderProducts();
    });

    // Remove product
    $(document).on('click', '.coc-co-remove', function () {
        var idx = $(this).closest('tr').data('idx');
        products.splice(idx, 1);
        renderProducts();
    });

    /* ── Recalculate totals ─────────────────────────────── */
    function recalc() {
        var subtotal = 0;
        $.each(products, function (i, p) {
            subtotal += p.price * p.qty;
        });
        var shipping = parseFloat($('#coc_co_shipping').val()) || 0;
        var grand = subtotal + shipping;

        $('#coc-co-subtotal').text(subtotal.toFixed(2));
        $('#coc-co-ship-display').text(shipping.toFixed(2));
        $('#coc-co-grand-total').text(grand.toFixed(2));
    }

    $('#coc_co_shipping').on('input change', recalc);

    /* ── Submit order ───────────────────────────────────── */
    $('#coc-co-form').on('submit', function (e) {
        e.preventDefault();

        if (!products.length) {
            alert('Please add at least one product.');
            return;
        }

        var $btn = $('#coc-co-submit');
        var $spinner = $('#coc-co-spinner');
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $('#coc-co-result').hide();

        var items = [];
        $.each(products, function (i, p) {
            items.push({ id: p.id, qty: p.qty });
        });

        $.ajax({
            url: COC_CO.ajax_url,
            method: 'POST',
            data: {
                action:   'coc_create_order',
                nonce:    COC_CO.nonce,
                name:     $('#coc_co_name').val(),
                phone:    $('#coc_co_phone').val(),
                address:  $('#coc_co_address').val(),
                city:     $('#coc_co_city').val(),
                postcode: $('#coc_co_postcode').val(),
                shipping: $('#coc_co_shipping').val(),
                payment:  $('#coc_co_payment').val(),
                items:    items
            },
            success: function (res) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                var $result = $('#coc-co-result');

                if (res.success) {
                    $result
                        .html('<div class="notice notice-success" style="margin:0;padding:12px 16px;">' +
                            '<strong>' + res.data.message + '</strong> &nbsp; ' +
                            '<a href="' + res.data.edit_url + '" class="button button-small" target="_blank">View Order</a>' +
                            '</div>')
                        .show();
                    // Reset form
                    products = [];
                    renderProducts();
                    $('#coc-co-form')[0].reset();
                    recalc();
                } else {
                    $result
                        .html('<div class="notice notice-error" style="margin:0;padding:12px 16px;">' + (res.data || 'Error creating order.') + '</div>')
                        .show();
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                $('#coc-co-result')
                    .html('<div class="notice notice-error" style="margin:0;padding:12px 16px;">Server error. Please try again.</div>')
                    .show();
            }
        });
    });

    /* ── Helpers ─────────────────────────────────────────── */
    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

})(jQuery);
