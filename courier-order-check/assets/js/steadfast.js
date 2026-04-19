/* global jQuery, ajaxurl */
( function ( $ ) {
    'use strict';

    $( function () {
        var $panel = $( '#coc-sf-panel' );
        if ( ! $panel.length ) return;

        var orderId = $panel.data( 'order-id' );
        var nonce   = $panel.data( 'nonce' );

        function showMsg( $el, msg, isErr ) {
            $el.removeClass( 'coc-pathao-msg--ok coc-pathao-msg--err' )
               .addClass( isErr ? 'coc-pathao-msg--err' : 'coc-pathao-msg--ok' )
               .text( msg )
               .show();
        }

        /* ── Create Order ─────────────────────────────────────── */

        $( '#coc-sf-submit-btn' ).on( 'click', function () {
            var $btn     = $( this );
            var invoice  = $.trim( $( '#coc-sf-invoice' ).val() );
            var name     = $.trim( $( '#coc-sf-name' ).val() );
            var phone    = $.trim( $( '#coc-sf-phone' ).val() );
            var address  = $.trim( $( '#coc-sf-address' ).val() );
            var cod      = $( '#coc-sf-cod' ).val();
            var note     = $.trim( $( '#coc-sf-note' ).val() );
            var itemDesc = $.trim( $( '#coc-sf-item-desc' ).val() );
            var delType  = $( '#coc-sf-delivery-type' ).val();
            var $msg     = $( '#coc-sf-msg' );

            if ( ! invoice || ! name || ! phone || ! address ) {
                showMsg( $msg, 'Invoice, name, phone and address are required.', true );
                return;
            }

            $btn.prop( 'disabled', true ).text( 'Creating…' );
            $msg.hide();

            $.post(
                ajaxurl,
                {
                    action            : 'coc_sf_create_order',
                    nonce             : nonce,
                    order_id          : orderId,
                    invoice           : invoice,
                    recipient_name    : name,
                    recipient_phone   : phone,
                    recipient_address : address,
                    cod_amount        : cod,
                    note              : note,
                    item_description  : itemDesc,
                    delivery_type     : delType,
                },
                function ( r ) {
                    $btn.prop( 'disabled', false ).text( 'Create Steadfast Order' );
                    if ( r && r.success ) {
                        location.reload();
                    } else {
                        var errMsg = ( r && r.data && r.data.message ) || 'Order creation failed.';
                        showMsg( $msg, errMsg, true );
                    }
                }
            ).fail( function () {
                $btn.prop( 'disabled', false ).text( 'Create Steadfast Order' );
                showMsg( $msg, 'Request failed. Please try again.', true );
            } );
        } );

        /* ── Refresh Status ───────────────────────────────────── */

        $( '#coc-sf-refresh-btn' ).on( 'click', function () {
            var $btn = $( this );
            var cid  = $btn.data( 'cid' );
            var $msg = $( '#coc-sf-msg' );

            $btn.prop( 'disabled', true ).text( 'Refreshing…' );
            $msg.hide();

            $.post(
                ajaxurl,
                { action: 'coc_sf_get_status', nonce: nonce, consignment_id: cid },
                function ( r ) {
                    $btn.prop( 'disabled', false ).text( '↻ Refresh Status' );
                    if ( r && r.success ) {
                        $( '#coc-sf-status' ).text( r.data.status );
                        showMsg( $msg, 'Status updated.', false );
                    } else {
                        var errMsg = ( r && r.data && r.data.message ) || 'Could not fetch status.';
                        showMsg( $msg, errMsg, true );
                    }
                }
            ).fail( function () {
                $btn.prop( 'disabled', false ).text( '↻ Refresh Status' );
                showMsg( $msg, 'Request failed.', true );
            } );
        } );

        /* ── Return Request Toggle ────────────────────────────── */

        $( '#coc-sf-return-btn' ).on( 'click', function () {
            $( '#coc-sf-return-form' ).slideToggle( 200 );
        } );

        /* ── Submit Return ────────────────────────────────────── */

        $( '#coc-sf-submit-return' ).on( 'click', function () {
            var $btn   = $( this );
            var reason = $.trim( $( '#coc-sf-return-reason' ).val() );
            var $msg   = $( '#coc-sf-return-msg' );

            $btn.prop( 'disabled', true ).text( 'Submitting…' );
            $msg.hide();

            $.post(
                ajaxurl,
                { action: 'coc_sf_create_return', nonce: nonce, order_id: orderId, reason: reason },
                function ( r ) {
                    $btn.prop( 'disabled', false ).text( 'Submit Return' );
                    if ( r && r.success ) {
                        showMsg( $msg, 'Return request submitted successfully.', false );
                        $( '#coc-sf-return-form' ).slideUp( 200 );
                    } else {
                        var errMsg = ( r && r.data && r.data.message ) || 'Return request failed.';
                        showMsg( $msg, errMsg, true );
                    }
                }
            ).fail( function () {
                $btn.prop( 'disabled', false ).text( 'Submit Return' );
                showMsg( $msg, 'Request failed.', true );
            } );
        } );

    } );
}( jQuery ) );
