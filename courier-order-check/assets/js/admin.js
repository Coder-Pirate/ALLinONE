/* global COC, jQuery */
( function ( $ ) {
    'use strict';

    $( function () {

        /* ============================================================
           Test API connection
           ============================================================ */

        var $btn     = $( '#coc-test-btn' );
        var $spinner = $( '#coc-test-spinner' );
        var $result  = $( '#coc-test-result' );

        $btn.on( 'click', function () {
            $btn.prop( 'disabled', true ).text( COC.l10n.testing );
            $spinner.addClass( 'is-active' );
            $result.hide().removeClass( 'is-success is-error' ).text( '' );

            $.post(
                COC.ajax_url,
                { action: 'coc_test_connection', nonce: COC.nonce },
                function ( response ) {
                    $spinner.removeClass( 'is-active' );
                    $btn.prop( 'disabled', false ).text( COC.l10n.test_btn );

                    if ( response.success ) {
                        var d   = response.data;
                        var msg = ( d && d.message ) ? d.message : 'Connection successful!';
                        if ( d && d.data && d.data.server_time ) { msg += ' (Server time: ' + d.data.server_time + ')'; }
                        $result.addClass( 'is-success' ).text( msg ).show();
                    } else {
                        var errMsg = ( response.data && response.data.message ) ? response.data.message : 'Connection failed.';
                        $result.addClass( 'is-error' ).text( errMsg ).show();
                    }
                }
            ).fail( function () {
                $spinner.removeClass( 'is-active' );
                $btn.prop( 'disabled', false ).text( COC.l10n.test_btn );
                $result.addClass( 'is-error' ).text( 'Request failed. Please try again.' ).show();
            } );
        } );

        /* ============================================================
           IP Blocklist management (settings page)
           ============================================================ */

        var $ipInput  = $( '#coc-ip-input' );
        var $ipAddBtn = $( '#coc-ip-add-btn' );
        var $ipMsg    = $( '#coc-ip-msg' );
        var $ipList   = $( '#coc-ip-list' );
        var $ipEmpty  = $( '#coc-ip-empty' );

        function showIpMsg( msg, isError ) {
            $ipMsg
                .removeClass( 'coc-ip-msg--ok coc-ip-msg--err' )
                .addClass( isError ? 'coc-ip-msg--err' : 'coc-ip-msg--ok' )
                .text( msg )
                .show();
            setTimeout( function () { $ipMsg.fadeOut(); }, 3000 );
        }

        function buildRow( ip ) {
            return $(
                '<tr data-ip="' + ip + '">' +
                '<td><code class="coc-ip-code">' + ip + '</code></td>' +
                '<td><button type="button" class="button button-small coc-unblock-btn" data-ip="' + ip + '">Unblock</button></td>' +
                '</tr>'
            );
        }

        // Add / Block IP.
        $ipAddBtn.on( 'click', function () {
            var ip = $.trim( $ipInput.val() );
            if ( ! ip ) { showIpMsg( 'Please enter an IP address.', true ); return; }

            $ipAddBtn.prop( 'disabled', true );

            $.post(
                COC.ajax_url,
                { action: 'coc_block_ip', nonce: COC.ip_nonce, ip: ip },
                function ( response ) {
                    $ipAddBtn.prop( 'disabled', false );
                    if ( ! response.success ) {
                        showIpMsg( ( response.data && response.data.message ) || 'Error.', true );
                        return;
                    }
                    $ipInput.val( '' );
                    $ipEmpty.remove();
                    // Only add row if not already present.
                    if ( ! $ipList.find( '[data-ip="' + ip + '"]' ).length ) {
                        $ipList.append( buildRow( ip ) );
                    }
                    showIpMsg( ( response.data && response.data.message ) || 'Blocked.', false );
                }
            ).fail( function () {
                $ipAddBtn.prop( 'disabled', false );
                showIpMsg( 'Request failed.', true );
            } );
        } );

        $ipInput.on( 'keydown', function ( e ) { if ( e.which === 13 ) { $ipAddBtn.trigger( 'click' ); } } );

        // Unblock IP (delegated — works for dynamically added rows too).
        $ipList.on( 'click', '.coc-unblock-btn', function () {
            var $row = $( this ).closest( 'tr' );
            var ip   = $( this ).data( 'ip' );

            if ( ! confirm( 'Unblock ' + ip + '?' ) ) { return; }

            $( this ).prop( 'disabled', true ).text( 'Removing...' );

            $.post(
                COC.ajax_url,
                { action: 'coc_unblock_ip', nonce: COC.ip_nonce, ip: ip },
                function ( response ) {
                    if ( ! response.success ) {
                        showIpMsg( ( response.data && response.data.message ) || 'Error.', true );
                        return;
                    }
                    $row.fadeOut( 300, function () {
                        $row.remove();
                        if ( ! $ipList.find( 'tr' ).length ) {
                            $ipList.append( '<tr id="coc-ip-empty"><td colspan="2" class="coc-ip-empty">No IPs blocked yet.</td></tr>' );
                        }
                    } );
                    showIpMsg( ( response.data && response.data.message ) || 'Unblocked.', false );
                }
            ).fail( function () {
                showIpMsg( 'Request failed.', true );
            } );
        } );

    } );
}( jQuery ) );

            $spinner.addClass( 'is-active' );
            $result.hide().removeClass( 'is-success is-error' ).text( '' );

            $.post(
                COC.ajax_url,
                {
                    action: 'coc_test_connection',
                    nonce:  COC.nonce,
                },
                function ( response ) {
                    $spinner.removeClass( 'is-active' );
                    $btn.prop( 'disabled', false ).text( COC.l10n.test_btn );

                    if ( response.success ) {
                        var d = response.data;
                        var msg = 'Connection successful! ';
                        if ( d && d.message ) {
                            msg = d.message;
                        }
                        if ( d && d.data && d.data.server_time ) {
                            msg += ' (Server time: ' + d.data.server_time + ')';
                        }
                        $result
                            .addClass( 'is-success' )
                            .text( msg )
                            .show();
                    } else {
                        var errMsg = ( response.data && response.data.message )
                            ? response.data.message
                            : 'Connection failed. Check your API key and domain.';
                        $result
                            .addClass( 'is-error' )
                            .text( errMsg )
                            .show();
                    }
                }
            ).fail( function () {
                $spinner.removeClass( 'is-active' );
                $btn.prop( 'disabled', false ).text( COC.l10n.test_btn );
                $result
                    .addClass( 'is-error' )
                    .text( 'Request failed. Please try again.' )
                    .show();
            } );
        } );
    } );
}( jQuery ) );
