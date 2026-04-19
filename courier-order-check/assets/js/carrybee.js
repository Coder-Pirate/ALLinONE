/* global jQuery, COC_CB */
( function ( $ ) {
    'use strict';

    $( function () {

        var orderId       = $( '#coc-cb-order-id' ).val();
        var consignmentId = $( '#coc-cb-consignment-id' ).val();

        /* ----------------------------------------------------------
           Helper — show message
           ---------------------------------------------------------- */

        function showMsg( text, isErr ) {
            $( '#coc-cb-msg' )
                .text( text )
                .css( 'color', isErr ? '#b91c1c' : '#15803d' )
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
           Area suggestion search
           ---------------------------------------------------------- */

        var $areaSearch    = $( '#coc-cb-area-search' );
        var $areaSearchBtn = $( '#coc-cb-area-search-btn' );
        var $areaSelect    = $( '#coc-cb-area-select' );
        var $cityId        = $( '#coc-cb-city-id' );
        var $zoneId        = $( '#coc-cb-zone-id' );
        var $areaId        = $( '#coc-cb-area-id' );

        function doAreaSearch() {
            var q = $.trim( $areaSearch.val() );
            if ( q.length < 3 ) {
                alert( 'Please type at least 3 characters to search.' );
                return;
            }

            $areaSearchBtn.prop( 'disabled', true ).text( 'Searching…' );

            $.post(
                COC_CB.ajax_url,
                { action: 'coc_cb_search_area', nonce: COC_CB.nonce, search: q },
                function ( r ) {
                    $areaSearchBtn.prop( 'disabled', false ).text( 'Search' );
                    $areaSelect.empty().append(
                        $( '<option>' ).val( '' ).text( '— Select area —' )
                    );

                    if ( r && r.success && r.data && r.data.length ) {
                        $.each( r.data, function ( i, item ) {
                            var label = item.area_name + ' — ' + item.zone_name + ', ' + item.city_name;
                            $areaSelect.append(
                                $( '<option>' )
                                    .val( item.area_id )
                                    .attr( 'data-city-id', item.city_id )
                                    .attr( 'data-zone-id', item.zone_id )
                                    .text( label )
                            );
                        } );
                        $areaSelect.show();
                    } else {
                        $areaSelect.append(
                            $( '<option>' ).val( '' ).text( '— No areas found —' )
                        );
                        $areaSelect.show();
                    }

                    // Clear previously selected IDs.
                    $cityId.val( '' );
                    $zoneId.val( '' );
                    $areaId.val( '' );
                }
            ).fail( function () {
                $areaSearchBtn.prop( 'disabled', false ).text( 'Search' );
                alert( 'Area search failed. Please try again.' );
            } );
        }

        $areaSearchBtn.on( 'click', doAreaSearch );
        $areaSearch.on( 'keypress', function ( e ) {
            if ( e.which === 13 ) { e.preventDefault(); doAreaSearch(); }
        } );

        // Populate hidden city/zone/area fields when an area is selected.
        $areaSelect.on( 'change', function () {
            var $opt = $areaSelect.find( ':selected' );
            $cityId.val( $opt.data( 'city-id' ) || '' );
            $zoneId.val( $opt.data( 'zone-id' ) || '' );
            $areaId.val( $opt.val() || '' );
        } );

        /* ----------------------------------------------------------
           Create order
           ---------------------------------------------------------- */

        $( '#coc-cb-submit-btn' ).on( 'click', function () {
            var $btn = $( this );
            var data = {
                action              : 'coc_cb_create_order',
                nonce               : COC_CB.nonce,
                order_id            : $( '#coc-cb-form input[name="order_id"]' ).val(),
                store_id            : $storeSelect.val(),
                recipient_name      : $( '#coc-cb-name' ).val(),
                recipient_phone     : $( '#coc-cb-phone' ).val(),
                recipient_address   : $( '#coc-cb-address' ).val(),
                city_id             : $cityId.val(),
                zone_id             : $zoneId.val(),
                area_id             : $areaId.val(),
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
            if ( ! data.city_id || ! data.zone_id ) {
                showMsg( 'Please search and select a delivery area.', true );
                return;
            }

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
