/* global COC_GAA, jQuery, gtag */
( function ( $ ) {
    'use strict';

    if ( typeof gtag === 'undefined' ) {
        return;
    }

    var currency = ( COC_GAA && COC_GAA.currency ) ? COC_GAA.currency : 'USD';

    /* ============================================================
       add_to_cart — AJAX
       WooCommerce fires "added_to_cart" after a successful AJAX add.
       We read the event_id from the WC cart fragment to allow
       deduplication with the server-side Measurement Protocol hit.
       ============================================================ */
    $( document.body ).on( 'added_to_cart', function ( e, fragments, hash, $btn ) {
        if ( ! $btn || ! $btn.length ) { return; }

        var pid     = String( $btn.data( 'product_id' ) );
        var product = COC_GAA.products && COC_GAA.products[ pid ];
        if ( ! product ) { return; }

        var qty   = parseInt( $btn.data( 'quantity' ) || 1, 10 );
        var value = Math.round( product.price * qty * 100 ) / 100;

        gtag( 'event', 'add_to_cart', {
            currency : currency,
            value    : value,
            items    : [ {
                item_id       : product.item_id,
                item_name     : product.item_name,
                item_category : product.item_category || '',
                price         : product.price,
                quantity      : qty,
            } ],
        } );
    } );

    /* ============================================================
       add_payment_info — payment method selected on checkout
       Pure client-side event; no server-side counterpart needed.
       ============================================================ */
    $( document.body ).on( 'change', 'input[name="payment_method"]', function () {
        gtag( 'event', 'add_payment_info', {
            payment_type : $( this ).val() || '',
        } );
    } );

}( jQuery ) );
