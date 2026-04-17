/* global COC_GTM, jQuery */
( function ( $ ) {
    'use strict';

    var dL = window.dataLayer = window.dataLayer || [];

    function push( event, ecommerce ) {
        dL.push( { ecommerce: null } ); // Clear previous ecommerce object.
        dL.push( { event: event, ecommerce: ecommerce } );
    }

    function round2( n ) {
        return Math.round( n * 100 ) / 100;
    }

    /* ============================================================
       add_to_cart — AJAX (shop / category / single pages)
       WooCommerce fires "added_to_cart" after successful AJAX add.
       ============================================================ */
    $( document.body ).on( 'added_to_cart', function ( e, fragments, hash, $btn ) {
        if ( ! $btn || ! $btn.length ) { return; }

        var pid  = String( $btn.data( 'product_id' ) );
        var item = COC_GTM.products[ pid ];
        if ( ! item ) { return; }

        var qty  = parseInt( $btn.data( 'quantity' ) || 1, 10 );
        var copy = $.extend( {}, item, { quantity: qty } );

        push( 'add_to_cart', {
            currency : COC_GTM.currency,
            value    : round2( copy.price * qty ),
            items    : [ copy ],
        } );
    } );

    /* ============================================================
       remove_from_cart
       ============================================================ */
    $( document.body ).on( 'click', '.remove_from_cart_button', function () {
        var key  = $( this ).data( 'cart_item_key' );
        var item = COC_GTM.cart_items && COC_GTM.cart_items[ key ];
        if ( ! item ) { return; }

        push( 'remove_from_cart', {
            currency : COC_GTM.currency,
            value    : round2( item.price * item.quantity ),
            items    : [ $.extend( {}, item ) ],
        } );
    } );

    /* ============================================================
       add_shipping_info — when a shipping method is selected
       ============================================================ */
    $( document.body ).on( 'change', 'input[name^="shipping_method"]', function () {
        if ( ! COC_GTM.checkout ) { return; }

        push( 'add_shipping_info', $.extend( {}, COC_GTM.checkout, {
            shipping_tier: $( 'input[name^="shipping_method"]:checked' ).val() || '',
        } ) );
    } );

    /* ============================================================
       add_payment_info — when a payment method is selected
       ============================================================ */
    $( document.body ).on( 'change', 'input[name="payment_method"]', function () {
        if ( ! COC_GTM.checkout ) { return; }

        push( 'add_payment_info', $.extend( {}, COC_GTM.checkout, {
            payment_type: $( this ).val() || '',
        } ) );
    } );

}( jQuery ) );
