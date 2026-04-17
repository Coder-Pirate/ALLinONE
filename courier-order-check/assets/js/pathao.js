/* global jQuery */
( function ( $ ) {
    'use strict';

    $( function () {

        var $panel = $( '#coc-pathao-panel' );
        if ( ! $panel.length ) { return; }

        var ajaxUrl      = window.ajaxurl || '';
        var nonce        = $panel.data( 'nonce' );
        var orderId      = $panel.data( 'order-id' );
        var defaultStore = String( $panel.data( 'default-store' ) || '' );

        var $msg         = $( '#coc-pathao-msg' );

        /* ----------------------------------------------------------------
           Helpers
        ---------------------------------------------------------------- */

        function showMsg( text, type ) {
            $msg.removeClass( 'coc-pathao-msg--ok coc-pathao-msg--err' )
                .addClass( type === 'ok' ? 'coc-pathao-msg--ok' : 'coc-pathao-msg--err' )
                .text( text )
                .show();
        }

        function hideMsg() { $msg.hide().text( '' ); }

        function fillSelect( $sel, items, valueKey, labelKey, prompt ) {
            $sel.empty().append( $( '<option>', { value: '', text: prompt } ) );
            $.each( items, function ( i, item ) {
                $sel.append( $( '<option>', { value: item[ valueKey ], text: item[ labelKey ] } ) );
            } );
        }

        function post( action, data, done, fail ) {
            data = $.extend( { action: action, nonce: nonce, order_id: orderId }, data );
            $.post( ajaxUrl, data )
                .done( function ( r ) {
                    if ( r && r.success ) { done( r.data ); }
                    else { fail( ( r && r.data && r.data.message ) || 'Error.' ); }
                } )
                .fail( function () { fail( 'Request failed.' ); } );
        }

        /* ================================================================
           CREATE ORDER FORM
        ================================================================ */

        if ( ! $panel.find( '.coc-pathao-form' ).length ) {
            // Panel is in "already sent" mode — only wire refresh button.
            wireRefresh();
            return;
        }

        var $storeSelect  = $( '#coc-pathao-store' );
        var $deliveryType = $( '#coc-pathao-delivery-type' );
        var $itemType     = $( '#coc-pathao-item-type' );
        var $weight       = $( '#coc-pathao-weight' );
        var $qty          = $( '#coc-pathao-qty' );
        var $recipName    = $( '#coc-pathao-recipient-name' );
        var $recipPhone   = $( '#coc-pathao-recipient-phone' );
        var $recipAddr    = $( '#coc-pathao-recipient-address' );
        var $cod          = $( '#coc-pathao-cod' );
        var $instruction  = $( '#coc-pathao-instruction' );
        var $description  = $( '#coc-pathao-description' );
        var $priceBtn     = $( '#coc-pathao-price-btn' );
        var $submitBtn    = $( '#coc-pathao-submit-btn' );
        var $priceResult  = $( '#coc-pathao-price-result' );
        var $priceValue   = $( '#coc-pathao-price-value' );

        /* ---- Load stores ---- */
        post( 'coc_pathao_get_stores', {}, function ( stores ) {
            fillSelect( $storeSelect, stores, 'store_id', 'store_name', '— Select store —' );
            if ( defaultStore ) { $storeSelect.val( defaultStore ); }
        }, function ( err ) {
            $storeSelect.html( '<option value="">— ' + err + ' —</option>' );
        } );

        /* ---- Check Price ---- */
        $priceBtn.on( 'click', function () {
            var storeId = $storeSelect.val();

            if ( ! storeId ) {
                showMsg( 'Please select a store.', 'err' );
                return;
            }
            hideMsg();
            $priceBtn.prop( 'disabled', true ).text( 'Checking…' );

            post( 'coc_pathao_price_plan', {
                store_id      : storeId,
                item_type     : $itemType.val(),
                delivery_type : $deliveryType.val(),
                item_weight   : $weight.val(),
            }, function ( data ) {
                $priceValue.text( '৳ ' + data.final_price );
                $priceResult.show();
                $priceBtn.prop( 'disabled', false ).text( 'Check Price' );
            }, function ( err ) {
                showMsg( err, 'err' );
                $priceBtn.prop( 'disabled', false ).text( 'Check Price' );
            } );
        } );

        /* ---- Create Order ---- */
        $submitBtn.on( 'click', function () {
            hideMsg();

            var storeId = $storeSelect.val();
            var name    = $.trim( $recipName.val() );
            var phone   = $.trim( $recipPhone.val() );
            var addr    = $.trim( $recipAddr.val() );

            if ( ! storeId )  { showMsg( 'Please select a store.', 'err' ); return; }
            if ( ! name )     { showMsg( 'Recipient name is required.', 'err' ); return; }
            if ( ! phone )    { showMsg( 'Recipient phone is required.', 'err' ); return; }
            if ( ! addr )     { showMsg( 'Recipient address is required.', 'err' ); return; }

            $submitBtn.prop( 'disabled', true ).text( 'Creating…' );

            post( 'coc_pathao_create_order', {
                store_id            : storeId,
                merchant_order_id   : String( orderId ),
                recipient_name      : name,
                recipient_phone     : phone,
                recipient_address   : addr,
                delivery_type       : $deliveryType.val(),
                item_type           : $itemType.val(),
                item_quantity       : $qty.val(),
                item_weight         : $weight.val(),
                amount_to_collect   : $cod.val(),
                special_instruction : $instruction.val(),
                item_description    : $description.val(),
            }, function ( data ) {
                // Replace form with success info without full page reload.
                var html =
                    '<div class="coc-pathao-info-grid">' +
                    '<div class="coc-pathao-info-row">' +
                        '<span class="coc-pathao-label">Consignment ID</span>' +
                        '<span class="coc-pathao-value"><code>' + esc( data.consignment_id ) + '</code></span>' +
                    '</div>' +
                    '<div class="coc-pathao-info-row">' +
                        '<span class="coc-pathao-label">Status</span>' +
                        '<span class="coc-pathao-value coc-pathao-status">' + esc( data.order_status ) + '</span>' +
                    '</div>' +
                    ( data.delivery_fee ? '<div class="coc-pathao-info-row"><span class="coc-pathao-label">Delivery Fee</span><span class="coc-pathao-value">৳ ' + esc( data.delivery_fee ) + '</span></div>' : '' ) +
                    '</div>' +
                    '<div class="coc-pathao-actions">' +
                        '<button type="button" class="button" id="coc-pathao-refresh-btn" data-consignment="' + esc( data.consignment_id ) + '">↻ Refresh Status</button>' +
                    '</div>' +
                    '<div class="coc-pathao-msg coc-pathao-msg--ok" style="margin-top:8px;">' + esc( data.message || 'Order Created Successfully' ) + '</div>';

                $panel.find( '.coc-pathao-title' ).append( '<span class="coc-pathao-badge coc-pathao-badge--sent">Order Sent</span>' );
                $panel.find( '#coc-pathao-msg' ).hide();
                $panel.find( '.coc-pathao-form' ).replaceWith( html );
                wireRefresh();
            }, function ( err ) {
                showMsg( err, 'err' );
                $submitBtn.prop( 'disabled', false ).text( '🚀 Create Pathao Order' );
            } );
        } );

        /* ================================================================
           REFRESH STATUS (already-sent mode + after create)
        ================================================================ */

        function wireRefresh() {
            $( document ).off( 'click', '#coc-pathao-refresh-btn' ).on( 'click', '#coc-pathao-refresh-btn', function () {
                var $btn           = $( this );
                var consignment_id = $btn.data( 'consignment' );

                $btn.prop( 'disabled', true ).text( 'Refreshing…' );
                $msg.hide();

                post( 'coc_pathao_get_order_info', { consignment_id: consignment_id }, function ( data ) {
                    var status = data.order_status_slug || data.order_status || '—';
                    $( '#coc-pathao-order-status' ).text( status );
                    $btn.prop( 'disabled', false ).text( '↻ Refresh Status' );
                    if ( $msg.length ) {
                        $msg.removeClass( 'coc-pathao-msg--err' )
                            .addClass( 'coc-pathao-msg--ok' )
                            .text( 'Status updated: ' + status )
                            .show();
                    }
                }, function ( err ) {
                    $btn.prop( 'disabled', false ).text( '↻ Refresh Status' );
                    if ( $msg.length ) { showMsg( err, 'err' ); }
                } );
            } );
        }

        wireRefresh();

        function esc( str ) { return $( '<span>' ).text( String( str ) ).html(); }

    } );

}( jQuery ) );
