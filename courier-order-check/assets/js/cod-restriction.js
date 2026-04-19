/* global COC_COD, jQuery */
( function ( $ ) {
    'use strict';

    if ( ! window.COC_COD ) { return; }

    var cfg        = window.COC_COD;
    var checkTimer = null;
    var amountTimer = null;
    var lastPhone  = '';
    var restricted = false;
    var isCartFlows = cfg.is_cartflows === '1';

    // Payment method selector — covers standard WooCommerce and CartFlows templates.
    var COD_SELECTOR = '.wc_payment_method.payment_method_cod, li.payment_method_cod, .wcf-payment-method.payment_method_cod';

    /* ================================================================
       Phone restriction check
       ================================================================ */

    function checkPhone( phone ) {
        // Strip non-digits for comparison.
        phone = phone.replace( /\D/g, '' );

        if ( phone === lastPhone ) { return; }
        lastPhone = phone;

        if ( phone.length < 11 ) {
            clearRestriction();
            return;
        }

        $.post( cfg.ajax_url, {
            action: 'coc_cod_check',
            nonce:  cfg.check_nonce,
            phone:  phone
        } ).done( function ( resp ) {
            if ( resp.success && resp.data.restricted ) {
                applyRestriction();
            } else {
                clearRestriction();
            }
        } );
    }

    function applyRestriction() {
        restricted = true;

        // Hide COD payment option.
        $( COD_SELECTOR ).hide();

        // Show ineligibility notice + payment panel.
        $( '#coc-cod-restricted-msg, #coc-prepayment-panel' ).show();

        // If COD was the selected method, switch to the first available alternative.
        if ( $( 'input[name="payment_method"]:checked' ).val() === 'cod' ) {
            var $alt = $( 'input[name="payment_method"]' ).filter( function () {
                return $( this ).val() !== 'cod';
            } ).first();
            if ( $alt.length ) {
                $alt.prop( 'checked', true ).trigger( 'change' );
            }
        }
    }

    function clearRestriction() {
        restricted = false;

        $( COD_SELECTOR ).show();
        $( '#coc-cod-restricted-msg, #coc-prepayment-panel' ).hide();

        // Clear advance amount from session so cart fee is removed.
        saveAmountToSession( 0 );

        // Reset upload state.
        $( '#coc-screenshot-token' ).val( '' );
        $( '#coc-screenshot-preview' ).hide().attr( 'src', '' );
        $( '#coc-upload-error' ).hide().text( '' );
        $( '#coc-upload-label' )
            .removeClass( 'uploading' )
            .html( '<span class="dashicons dashicons-upload"></span> Upload screenshot' );
    }

    /* ================================================================
       Save advance payment amount to session → triggers cart refresh
       ================================================================ */

    function saveAmountToSession( amount ) {
        $.post( cfg.ajax_url, {
            action: 'coc_cod_save_amount',
            nonce:  cfg.amount_nonce,
            amount: amount
        } ).done( function () {
            // Trigger checkout totals refresh for standard WooCommerce.
            $( document.body ).trigger( 'update_checkout' );
            // CartFlows also listens for woocommerce_update_order_review AJAX
            // via update_checkout, but fire its own event too if present.
            if ( isCartFlows ) {
                $( document.body ).trigger( 'wcf_update_checkout' );
            }
        } );
    }

    /* ================================================================
       Watch billing_phone — debounced.
       Uses event delegation from document so it works with both
       WooCommerce and CartFlows dynamically-rendered fields.
       ================================================================ */

    $( document.body ).on( 'input change', '#billing_phone, #wcf-billing_phone, [name="billing_phone"]', function () {
        clearTimeout( checkTimer );
        var phone = $( this ).val();
        checkTimer = setTimeout( function () { checkPhone( phone ); }, 700 );
    } );

    // Re-apply after WooCommerce or CartFlows refreshes checkout fragments.
    $( document.body ).on( 'updated_checkout wc_fragments_refreshed', function () {
        var phone = $( '#billing_phone, #wcf-billing_phone, [name="billing_phone"]' ).first().val();
        if ( phone ) {
            lastPhone = ''; // force re-check after DOM refresh
            checkPhone( phone );
        } else if ( restricted ) {
            applyRestriction();
        }
    } );

    // Initial check if the phone field is pre-filled (logged-in user).
    var $phoneField = $( '#billing_phone, #wcf-billing_phone, [name="billing_phone"]' ).first();
    if ( $phoneField.length && $phoneField.val() ) { checkPhone( $phoneField.val() ); }

    /* ================================================================
       Payment amount → save to session + refresh checkout totals
       ================================================================ */

    $( document.body ).on( 'input change', '#coc-payment-amount', function () {
        clearTimeout( amountTimer );
        var amount = parseInt( $( this ).val(), 10 ) || 0;
        amountTimer = setTimeout( function () {
            saveAmountToSession( amount );
        }, 600 );
    } );

    /* ================================================================
       Screenshot upload
       ================================================================ */

    $( document.body ).on( 'change', '#coc-screenshot-input', function () {
        var file = this.files && this.files[0];
        if ( ! file ) { return; }

        var $label   = $( '#coc-upload-label' );
        var $error   = $( '#coc-upload-error' );
        var $preview = $( '#coc-screenshot-preview' );
        var $token   = $( '#coc-screenshot-token' );

        $label.addClass( 'uploading' ).text( 'Uploading\u2026' );
        $error.hide().text( '' );
        $token.val( '' );
        $preview.hide();

        var fd = new FormData();
        fd.append( 'action',     'coc_cod_upload' );
        fd.append( 'nonce',      cfg.upload_nonce );
        fd.append( 'screenshot', file );

        $.ajax( {
            url:         cfg.ajax_url,
            type:        'POST',
            data:        fd,
            contentType: false,
            processData: false
        } ).done( function ( resp ) {
            if ( resp.success ) {
                $token.val( resp.data.token );
                $preview.attr( 'src', resp.data.url ).show();
                $label
                    .removeClass( 'uploading' )
                    .html( '<span class="dashicons dashicons-yes-alt"></span> Screenshot uploaded' );
            } else {
                var msg = ( resp.data && resp.data.message ) ? resp.data.message : 'Upload failed.';
                $error.text( msg ).show();
                $label
                    .removeClass( 'uploading' )
                    .html( '<span class="dashicons dashicons-upload"></span> Upload screenshot' );
            }
        } ).fail( function () {
            $error.text( 'Upload failed. Please try again.' ).show();
            $label
                .removeClass( 'uploading' )
                .html( '<span class="dashicons dashicons-upload"></span> Upload screenshot' );
        } );
    } );

} )( jQuery );
