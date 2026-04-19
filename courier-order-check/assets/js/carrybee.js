/* global jQuery, COC_CB */
( function ( $ ) {
    'use strict';

    $( function () {

        var $panel        = $( '#coc-cb-panel' );
        var orderId       = $panel.data( 'order-id' ) || $( '#coc-cb-order-id' ).val();
        var consignmentId = $( '#coc-cb-consignment-id' ).val();

        /* ----------------------------------------------------------
           Helper — show message
           ---------------------------------------------------------- */

        function showMsg( text, isErr ) {
            $( '#coc-cb-msg' )
                .removeClass( 'coc-pathao-msg--ok coc-pathao-msg--err' )
                .addClass( isErr ? 'coc-pathao-msg--err' : 'coc-pathao-msg--ok' )
                .text( text )
                .show();
        }

        /* ----------------------------------------------------------
           Load pickup stores on page load
           ---------------------------------------------------------- */

        var $storeSelect = $( '#coc-cb-store' );

        if ( $storeSelect.length ) {
            $.post(
                COC_CB.ajax_url,
                { action: 'coc_cb_get_stores', nonce: COC_CB.nonce },
                function ( r ) {
                    $storeSelect.empty();
                    if ( r && r.success && r.data && r.data.length ) {
                        $storeSelect.append( $( '<option>' ).val( '' ).text( '— Select store —' ) );
                        $.each( r.data, function ( i, s ) {
                            var label = s.name;
                            if ( s.is_default_pickup_store ) { label += ' ★'; }
                            var $opt  = $( '<option>' ).val( s.id ).text( label );
                            // Pre-select the saved default store ID, or the API's default pickup store.
                            if ( s.id === COC_CB.default_store_id || s.is_default_pickup_store ) {
                                $opt.prop( 'selected', true );
                            }
                            $storeSelect.append( $opt );
                        } );
                    } else {
                        $storeSelect.append(
                            $( '<option>' ).val( '' ).text( '— No stores found. Create one in Carrybee portal. —' )
                        );
                    }
                }
            ).fail( function () {
                $storeSelect.html( '<option value="">— Failed to load stores —</option>' );
            } );
        }

        /* ----------------------------------------------------------
           Create order
           ---------------------------------------------------------- */

        $( '#coc-cb-submit-btn' ).on( 'click', function () {
            var $btn = $( this );
            var data = {
                action              : 'coc_cb_create_order',
                nonce               : COC_CB.nonce,
                order_id            : orderId,
                store_id            : $storeSelect.val(),
                recipient_name      : $( '#coc-cb-name' ).val(),
                recipient_phone     : $( '#coc-cb-phone' ).val(),
                recipient_address   : $( '#coc-cb-address' ).val(),
                delivery_type       : $( '#coc-cb-delivery-type' ).val(),
                product_type        : $( '#coc-cb-product-type' ).val(),
                item_weight         : $( '#coc-cb-weight' ).val(),
                collectable_amount  : $( '#coc-cb-cod' ).val(),
                special_instruction : $( '#coc-cb-instruction' ).val(),
            };

            if ( ! data.store_id )          { showMsg( 'Please select a pickup store.', true ); return; }
            if ( ! data.recipient_name )    { showMsg( 'Recipient name is required.', true ); return; }
            if ( ! data.recipient_phone )   { showMsg( 'Phone is required.', true ); return; }
            if ( ! data.recipient_address ) { showMsg( 'Address is required.', true ); return; }

            $btn.prop( 'disabled', true ).text( 'Creating…' );
            showMsg( 'Creating order…', false );

            $.post( COC_CB.ajax_url, data, function ( r ) {
                $btn.prop( 'disabled', false ).text( 'Create Carrybee Order' );
                if ( r && r.success ) {
                    showMsg( r.data.message || 'Order created!', false );
                    setTimeout( function () { window.location.reload(); }, 1500 );
                } else {
                    var msg = ( r && r.data && r.data.message ) || 'Order creation failed.';
                    showMsg( msg, true );
                }
            } ).fail( function () {
                $btn.prop( 'disabled', false ).text( 'Create Carrybee Order' );
                showMsg( 'Request failed. Please try again.', true );
            } );
        } );

        /* ----------------------------------------------------------
           Refresh status
           ---------------------------------------------------------- */

        $( '#coc-cb-refresh-btn' ).on( 'click', function () {
            var $btn = $( this );
            $btn.prop( 'disabled', true );
            showMsg( 'Refreshing…', false );

            $.post(
                COC_CB.ajax_url,
                {
                    action         : 'coc_cb_get_status',
                    nonce          : COC_CB.nonce,
                    order_id       : orderId,
                    consignment_id : consignmentId,
                },
                function ( r ) {
                    $btn.prop( 'disabled', false );
                    if ( r && r.success ) {
                        $( '#coc-cb-status' ).text( r.data.status || '' );
                        showMsg( 'Status updated.', false );
                    } else {
                        showMsg( ( r && r.data && r.data.message ) || 'Refresh failed.', true );
                    }
                }
            ).fail( function () {
                $btn.prop( 'disabled', false );
                showMsg( 'Request failed.', true );
            } );
        } );

        /* ----------------------------------------------------------
           Cancel order
           ---------------------------------------------------------- */

        $( '#coc-cb-cancel-btn' ).on( 'click', function () {
            var reason = window.prompt( 'Cancellation reason (optional):' );
            if ( reason === null ) { return; } // User clicked Cancel on the prompt.

            var $btn = $( this );
            $btn.prop( 'disabled', true );
            showMsg( 'Cancelling…', false );

            $.post(
                COC_CB.ajax_url,
                {
                    action         : 'coc_cb_cancel_order',
                    nonce          : COC_CB.nonce,
                    order_id       : orderId,
                    consignment_id : consignmentId,
                    reason         : reason,
                },
                function ( r ) {
                    $btn.prop( 'disabled', false );
                    if ( r && r.success ) {
                        showMsg( r.data.message || 'Order cancelled.', false );
                        setTimeout( function () { window.location.reload(); }, 1500 );
                    } else {
                        showMsg( ( r && r.data && r.data.message ) || 'Cancel failed.', true );
                    }
                }
            ).fail( function () {
                $btn.prop( 'disabled', false );
                showMsg( 'Request failed.', true );
            } );
        } );

    } );
}( jQuery ) );
