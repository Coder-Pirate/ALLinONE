/* global COC_PIXEL, jQuery, fbq */
( function ( $ ) {
    'use strict';

    if ( typeof fbq === 'undefined' ) {
        return;
    }

    var currency = ( COC_PIXEL && COC_PIXEL.currency ) ? COC_PIXEL.currency : 'USD';

    function ts() {
        return String( Date.now() );
    }

    /* ============================================================
       AddToCart — AJAX
       WooCommerce fires "added_to_cart" after a successful AJAX add.
       We read the event_id from the WC cart fragment to match the
       server-side CAPI event (proper deduplication).
       ============================================================ */
    $( document.body ).on( 'added_to_cart', function ( e, fragments, hash, $btn ) {
        if ( ! $btn || ! $btn.length ) { return; }

        var pid     = String( $btn.data( 'product_id' ) );
        var product = COC_PIXEL.products && COC_PIXEL.products[ pid ];
        if ( ! product ) { return; }

        var qty   = parseInt( $btn.data( 'quantity' ) || 1, 10 );
        var value = Math.round( product.item_price * qty * 100 ) / 100;

        // Read event_id injected by server into the WC fragments response.
        var eid = ts(); // fallback if fragment isn't present.
        if ( fragments && fragments[ 'div#coc-pixel-atc-eid' ] ) {
            var $frag = $( fragments[ 'div#coc-pixel-atc-eid' ] );
            if ( $frag.length && $frag.data( 'eid' ) ) {
                eid = String( $frag.data( 'eid' ) );
            }
        }

        fbq( 'track', 'AddToCart', {
            content_ids  : [ product.id ],
            contents     : [ { id: product.id, quantity: qty, item_price: product.item_price } ],
            content_type : 'product',
            value        : value,
            currency     : currency,
        }, { eventID: eid } );
    } );

    /* ============================================================
       AddPaymentInfo — payment method selected on checkout
       Pure client-side event (no matching CAPI event needed).
       ============================================================ */
    $( document.body ).on( 'change', 'input[name="payment_method"]', function () {
        fbq( 'track', 'AddPaymentInfo', {
            payment_type: $( this ).val() || '',
        }, { eventID: ts() } );
    } );

}( jQuery ) );
