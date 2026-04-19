/* global jQuery, ajaxurl */
( function ( $ ) {
    'use strict';

    $( function () {

        var $panel = $( '#coc-redx-panel' );
        if ( ! $panel.length ) { return; }

        var orderId      = $panel.data( 'order-id' );
        var nonce        = $panel.data( 'nonce' );
        var defaultStore = String( $panel.data( 'default-store' ) || '' );
        var $msg         = $( '#coc-redx-msg' );
        var pickupAreaId = 0;

        /* ----------------------------------------------------------------
           Helpers
        ---------------------------------------------------------------- */

        function showMsg( text, isErr ) {
            $msg.removeClass( 'coc-sf-msg--ok coc-sf-msg--err' )
                .addClass( isErr ? 'coc-sf-msg--err' : 'coc-sf-msg--ok' )
                .text( text )
                .show();
        }

        function post( action, data, done, fail ) {
            data = $.extend( { action: action, nonce: nonce, order_id: orderId }, data );
            $.post( ajaxurl, data )
                .done( function ( r ) {
                    if ( r && r.success ) { done( r.data ); }
                    else { fail( ( r && r.data && r.data.message ) || 'Error.' ); }
                } )
                .fail( function () { fail( 'Request failed.' ); } );
        }

        /* ----------------------------------------------------------------
           Load pickup stores on page load
        ---------------------------------------------------------------- */

        var $storeSelect = $( '#coc-redx-store' );
        if ( $storeSelect.length ) {
            post( 'coc_redx_get_stores', {}, function ( stores ) {
                $storeSelect.empty().append( '<option value="">— Select pickup store —</option>' );
                if ( Array.isArray( stores ) ) {
                    $.each( stores, function ( i, s ) {
                        var storeId = s.id || s.pickup_store_id;
                        var opt = $( '<option>', {
                            value : storeId,
                            text  : ( s.name || s.pickup_store_name || ( 'Store ' + storeId ) ),
                        } );
                        opt.data( 'area-id', s.area_id || 0 );
                        $storeSelect.append( opt );
                    } );
                }
                if ( defaultStore ) {
                    $storeSelect.val( defaultStore );
                    pickupAreaId = $storeSelect.find( ':selected' ).data( 'area-id' ) || 0;
                }
            }, function () {
                $storeSelect.html( '<option value="">— Could not load stores —</option>' );
            } );

            $storeSelect.on( 'change', function () {
                pickupAreaId = $( this ).find( ':selected' ).data( 'area-id' ) || 0;
            } );
        }

        /* ----------------------------------------------------------------
           Area search by district name
        ---------------------------------------------------------------- */

        var $areaSearch = $( '#coc-redx-area-search' );
        var $areaSelect = $( '#coc-redx-area-select' );
        var $areaId     = $( '#coc-redx-area-id' );
        var $areaName   = $( '#coc-redx-area-name' );

        $( '#coc-redx-area-search-btn' ).on( 'click', function () {
            var query = $.trim( $areaSearch.val() );
            if ( ! query ) { showMsg( 'Enter a district name to search.', true ); return; }

            var $btn = $( this );
            $btn.prop( 'disabled', true ).text( 'Searching…' );
            $msg.hide();

            post( 'coc_redx_get_areas', { district_name: query }, function ( areas ) {
                $btn.prop( 'disabled', false ).text( 'Search' );
                if ( ! areas || ! areas.length ) {
                    showMsg( 'No areas found for "' + query + '". Try a different district name.', true );
                    return;
                }
                $areaSelect.empty().append( '<option value="">— Select area —</option>' );
                $.each( areas, function ( i, a ) {
                    var areaId   = a.id || a.area_id;
                    var areaName = a.name || a.area_name;
                    var label    = areaName;
                    if ( a.post_code )      { label += ' (' + a.post_code + ')'; }
                    if ( a.division_name )  { label += ', ' + a.division_name; }
                    var opt = $( '<option>', { value: areaId, text: label } );
                    opt.data( 'area-name', areaName );
                    $areaSelect.append( opt );
                } );
                $areaSelect.show();
                $areaId.val( '' );
                $areaName.val( '' );
            }, function ( err ) {
                $btn.prop( 'disabled', false ).text( 'Search' );
                showMsg( err, true );
            } );
        } );

        $areaSearch.on( 'keydown', function ( e ) {
            if ( e.which === 13 ) { $( '#coc-redx-area-search-btn' ).trigger( 'click' ); }
        } );

        $areaSelect.on( 'change', function () {
            var selected = $( this ).find( ':selected' );
            $areaId.val( selected.val() );
            $areaName.val( selected.data( 'area-name' ) || selected.text().split( ' (' )[0] );
        } );

        /* ----------------------------------------------------------------
           Calculate charge
        ---------------------------------------------------------------- */

        $( '#coc-redx-charge-btn' ).on( 'click', function () {
            var deliveryAreaId = $areaId.val();
            var $btn           = $( this );

            if ( ! deliveryAreaId ) { showMsg( 'Search and select a delivery area first.', true ); return; }
            if ( ! pickupAreaId )   { showMsg( 'Select a pickup store first.', true ); return; }

            $btn.prop( 'disabled', true ).text( 'Calculating…' );
            $msg.hide();

            post( 'coc_redx_calculate_charge', {
                delivery_area_id : deliveryAreaId,
                pickup_area_id   : pickupAreaId,
                cod              : $( '#coc-redx-cod' ).val(),
                weight           : $( '#coc-redx-weight' ).val(),
            }, function ( data ) {
                $btn.prop( 'disabled', false ).text( 'Calculate Charge' );
                var charge = data.total_charge !== undefined ? data.total_charge
                           : data.charge       !== undefined ? data.charge
                           : '—';
                $( '#coc-redx-charge-result' ).text( '৳ ' + charge );
            }, function ( err ) {
                $btn.prop( 'disabled', false ).text( 'Calculate Charge' );
                showMsg( err, true );
            } );
        } );

        /* ----------------------------------------------------------------
           Create parcel
        ---------------------------------------------------------------- */

        $( '#coc-redx-submit-btn' ).on( 'click', function () {
            var $btn         = $( this );
            var storeId      = $storeSelect.val();
            var name         = $.trim( $( '#coc-redx-name' ).val() );
            var phone        = $.trim( $( '#coc-redx-phone' ).val() );
            var address      = $.trim( $( '#coc-redx-address' ).val() );
            var deliveryArea = $areaName.val();
            var areaId       = $areaId.val();

            if ( ! name )         { showMsg( 'Customer name is required.', true ); return; }
            if ( ! phone )        { showMsg( 'Customer phone is required.', true ); return; }
            if ( ! address )      { showMsg( 'Delivery address is required.', true ); return; }
            if ( ! areaId )       { showMsg( 'Please search and select a delivery area.', true ); return; }

            $btn.prop( 'disabled', true ).text( 'Creating…' );
            $msg.hide();

            post( 'coc_redx_create_parcel', {
                pickup_store_id         : storeId,
                customer_name           : name,
                customer_phone          : phone,
                customer_address        : address,
                delivery_area           : deliveryArea,
                delivery_area_id        : areaId,
                cash_collection_amount  : $( '#coc-redx-cod' ).val(),
                parcel_weight           : $( '#coc-redx-weight' ).val(),
                value                   : $( '#coc-redx-cod' ).val(),
                merchant_invoice_id     : $( '#coc-redx-invoice' ).val(),
                instruction             : $( '#coc-redx-instruction' ).val(),
            }, function () {
                // Reload the page to show the tracking info view.
                window.location.reload();
            }, function ( err ) {
                $btn.prop( 'disabled', false ).text( 'Create RedX Parcel' );
                showMsg( err, true );
            } );
        } );

        /* ----------------------------------------------------------------
           Refresh status
        ---------------------------------------------------------------- */

        $( '#coc-redx-refresh-btn' ).on( 'click', function () {
            var $btn       = $( this );
            var trackingId = $btn.data( 'tracking-id' );

            $btn.prop( 'disabled', true ).text( 'Refreshing…' );
            $msg.hide();

            post( 'coc_redx_get_status', { tracking_id: trackingId }, function ( data ) {
                $btn.prop( 'disabled', false ).text( '↻ Refresh Status' );
                $( '#coc-redx-status' ).text( data.status );
                showMsg( 'Status updated: ' + data.status, false );
            }, function ( err ) {
                $btn.prop( 'disabled', false ).text( '↻ Refresh Status' );
                showMsg( err, true );
            } );
        } );

        /* ----------------------------------------------------------------
           Cancel parcel
        ---------------------------------------------------------------- */

        $( '#coc-redx-cancel-btn' ).on( 'click', function () {
            if ( ! window.confirm( 'Are you sure you want to cancel this RedX parcel?' ) ) { return; }

            var $btn       = $( this );
            var trackingId = $btn.data( 'tracking-id' );

            $btn.prop( 'disabled', true ).text( 'Cancelling…' );
            $msg.hide();

            post( 'coc_redx_cancel_parcel', { tracking_id: trackingId }, function ( data ) {
                $btn.prop( 'disabled', false ).text( 'Cancel Parcel' );
                $( '#coc-redx-status' ).text( 'cancelled' );
                showMsg( data.message || 'Parcel cancelled.', false );
            }, function ( err ) {
                $btn.prop( 'disabled', false ).text( 'Cancel Parcel' );
                showMsg( err, true );
            } );
        } );

    } );

}( jQuery ) );
