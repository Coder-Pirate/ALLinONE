/* global COC_GADS, jQuery, gtag */
( function ( $ ) {
    'use strict';

    if ( typeof gtag === 'undefined' ) {
        return;
    }

    var currency   = ( COC_GADS && COC_GADS.currency )         ? COC_GADS.currency         : 'USD';
    var convId     = ( COC_GADS && COC_GADS.conversion_id )    ? COC_GADS.conversion_id    : '';
    var convLabel  = ( COC_GADS && COC_GADS.conversion_label ) ? COC_GADS.conversion_label : '';

    /* ============================================================
       add_to_cart — AJAX remarketing
       WooCommerce fires "added_to_cart" after a successful AJAX add.
       We fire a Google Ads dynamic remarketing event so Google can
       retarget the user with ads for the specific product added.
       ============================================================ */
    $( document.body ).on( 'added_to_cart', function ( e, fragments, hash, $btn ) {
        if ( ! $btn || ! $btn.length ) { return; }

        var pid     = String( $btn.data( 'product_id' ) );
        var product = COC_GADS.products && COC_GADS.products[ pid ];
        if ( ! product ) { return; }

        var qty   = parseInt( $btn.data( 'quantity' ) || 1, 10 );
        var value = Math.round( product.price * qty * 100 ) / 100;

        gtag( 'event', 'add_to_cart', {
            currency : currency,
            value    : value,
            items    : [ {
                item_id                  : product.item_id,
                item_name                : product.item_name,
                item_category            : product.item_category || '',
                price                    : product.price,
                quantity                 : qty,
                google_business_vertical : 'retail',
            } ],
        } );
    } );

    /* ============================================================
       add_payment_info — payment method selected on checkout
       Useful for Google Ads audience segmentation.
       ============================================================ */
    $( document.body ).on( 'change', 'input[name="payment_method"]', function () {
        gtag( 'event', 'add_payment_info', {
            payment_type : $( this ).val() || '',
        } );
    } );

}( jQuery ) );
