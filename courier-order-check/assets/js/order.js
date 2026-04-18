/* global COC_ORDER, jQuery */
( function ( $ ) {
    'use strict';

    $( function () {

        /* ============================================================
           Courier search panel
           ============================================================ */

        var $panel  = $( '.coc-check-panel' );

        if ( $panel.length ) {
            var $input   = $( '#coc-phone-input' );
            var $btn     = $( '#coc-search-btn' );
            var $loading = $( '#coc-loading' );
            var $error   = $( '#coc-error-msg' );
            var $results = $( '#coc-results' );
            var $tbody   = $( '#coc-table-body' );
            var $badges  = $( '#coc-badges-row' );
            var $bar     = $( '#coc-ratio-full-bar' );

            function doSearch( phone ) {
                phone = $.trim( phone );
                if ( ! phone ) { return; }

                $error.hide().text( '' );
                $results.hide();
                $loading.show();
                $btn.prop( 'disabled', true );

                $.post(
                    COC_ORDER.ajax_url,
                    { action: 'coc_courier_check', nonce: COC_ORDER.nonce, phone: phone },
                    function ( response ) {
                        $loading.hide();
                        $btn.prop( 'disabled', false );

                    if ( ! response.success ) {
                            // If the server signals the account is inactive/disconnected,
                            // reload so the panel is removed immediately.
                            if ( response.data && response.data.reload ) {
                                window.location.reload();
                                return;
                            }
                            $error.text( ( response.data && response.data.message ) || 'An error occurred.' ).show();
                            return;
                        }
                        renderResults( response.data );
                    }
                ).fail( function () {
                    $loading.hide();
                    $btn.prop( 'disabled', false );
                    $error.text( 'Request failed. Please try again.' ).show();
                } );
            }

            function esc( str ) { return $( '<span>' ).text( String( str ) ).html(); }

            function renderResults( data ) {
                if ( ! data || ! data.data ) {
                    $error.text( 'Unexpected response format.' ).show();
                    return;
                }
                var couriers = data.data;
                var summary  = couriers.summary || null;
                var rows     = '';

                $.each( couriers, function ( key, c ) {
                    if ( key === 'summary' ) { return; }
                    rows +=
                        '<tr>' +
                        '<td>' + esc( c.name || key ) + '</td>' +
                        '<td>' + esc( c.total_parcel || 0 ) + '</td>' +
                        '<td class="coc-td-success">' + esc( c.success_parcel || 0 ) + '</td>' +
                        '<td class="coc-td-cancel">'  + esc( c.cancelled_parcel || 0 ) + '</td>' +
                        '</tr>';
                } );
                $tbody.html( rows );

                if ( summary ) {
                    $badges.html(
                        '<span class="coc-badge coc-badge-total">Total : '    + esc( summary.total_parcel     ) + '</span>' +
                        '<span class="coc-badge coc-badge-success">Success : ' + esc( summary.success_parcel   ) + '</span>' +
                        '<span class="coc-badge coc-badge-cancel">Cancel : '   + esc( summary.cancelled_parcel ) + '</span>'
                    );
                    var sr       = parseFloat( summary.success_ratio ) || 0;
                    var cr       = parseFloat( ( 100 - sr ).toFixed( 2 ) );
                    var barClass = sr >= 80 ? 'coc-bar-green' : ( sr >= 60 ? 'coc-bar-orange' : 'coc-bar-red' );
                    $bar.text( sr.toFixed( 0 ) + '% Success / ' + cr + '% Cancel' )
                        .removeClass( 'coc-bar-green coc-bar-orange coc-bar-red' )
                        .addClass( barClass );
                } else {
                    $badges.empty();
                    $bar.text( '' );
                }
                $results.show();
            }

            $btn.on( 'click', function () { doSearch( $input.val() ); } );
            $input.on( 'keydown', function ( e ) { if ( e.which === 13 ) { doSearch( $input.val() ); } } );

            var defaultPhone = $panel.data( 'phone' );
            if ( defaultPhone ) { doSearch( defaultPhone ); }
        }

        /* ============================================================
           IP Block / Unblock — order page button
           ============================================================ */

        $( document ).on( 'click', '.coc-ip-toggle-btn', function () {
            var $btn    = $( this );
            var $bar    = $btn.closest( '.coc-ip-bar' );
            var ip      = $btn.data( 'ip' );
            var nonce   = $bar.data( 'nonce' );
            var isBlock = $btn.hasClass( 'coc-ip-toggle-btn--block' );
            var action  = isBlock ? 'coc_block_ip' : 'coc_unblock_ip';

            $btn.prop( 'disabled', true ).text( isBlock ? 'Blocking...' : 'Unblocking...' );

            $.post(
                COC_ORDER.ajax_url,
                { action: action, nonce: nonce, ip: ip },
                function ( response ) {
                    if ( ! response.success ) {
                        alert( ( response.data && response.data.message ) || 'Error.' );
                        $btn.prop( 'disabled', false );
                        return;
                    }

                    if ( isBlock ) {
                        $bar.addClass( 'coc-ip-bar--blocked' );
                        $btn.replaceWith(
                            '<span class="coc-ip-status coc-ip-status--blocked">BLOCKED</span>' +
                            '<button type="button" class="coc-ip-toggle-btn coc-ip-toggle-btn--unblock" data-ip="' + ip + '">Unblock IP</button>'
                        );
                    } else {
                        $bar.removeClass( 'coc-ip-bar--blocked' );
                        $bar.find( '.coc-ip-status' ).remove();
                        $btn.replaceWith(
                            '<button type="button" class="coc-ip-toggle-btn coc-ip-toggle-btn--block" data-ip="' + ip + '">\uD83D\uDEAB Block IP</button>'
                        );
                    }
                }
            ).fail( function () {
                alert( 'Request failed. Please try again.' );
                $btn.prop( 'disabled', false );
            } );
        } );

        // Orders list ratio bars are populated server-side from cached transient data only.
        // No auto-AJAX here — data is fetched exclusively when admin visits an order detail page.

    } );
}( jQuery ) );
