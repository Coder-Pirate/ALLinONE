/* global COC_TTK, jQuery, ttq */
( function ( $ ) {
    'use strict';

    if ( typeof ttq === 'undefined' ) {
        return;
    }

    var currency = ( COC_TTK && COC_TTK.currency ) ? COC_TTK.currency : 'USD';

    function ts() {
        return String( Date.now() );
    }

    /* ============================================================
       AddToCart — AJAX
       WooCommerce fires "added_to_cart" after a successful AJAX add.
       We read the server-assigned event_id from the WC cart fragment
       to match the Events API call (deduplication).
       ============================================================ */
    $( document.body ).on( 'added_to_cart', function ( e, fragments, hash, $btn ) {
        if ( ! $btn || ! $btn.length ) { return; }

        var pid     = String( $btn.data( 'product_id' ) );
        var product = COC_TTK.products && COC_TTK.products[ pid ];
        if ( ! product || ! product.contents || ! product.contents.length ) { return; }

        var qty   = parseInt( $btn.data( 'quantity' ) || 1, 10 );
        var price = product.contents[0].price;
        var value = Math.round( price * qty * 100 ) / 100;

        // Read event_id from server-injected WC fragment.
        var eid = ts();
        if ( fragments && fragments[ 'div#coc-ttk-atc-eid' ] ) {
            var $frag = $( fragments[ 'div#coc-ttk-atc-eid' ] );
            if ( $frag.length && $frag.data( 'eid' ) ) {
                eid = String( $frag.data( 'eid' ) );
            }
        }

        var contents = [ {
            content_id   : product.contents[0].content_id,
            content_name : product.contents[0].content_name,
            content_type : 'product',
            quantity     : qty,
            price        : price,
        } ];

        ttq.track( 'AddToCart', {
            contents     : contents,
            value        : value,
            currency     : currency,
            content_type : 'product',
        }, { event_id: eid } );
    } );

    /* ============================================================
       AddPaymentInfo — payment method selected on checkout
       ============================================================ */
    $( document.body ).on( 'change', 'input[name="payment_method"]', function () {
        ttq.track( 'AddPaymentInfo', {
            content_type: 'product',
        }, { event_id: ts() } );
    } );

}( jQuery ) );
