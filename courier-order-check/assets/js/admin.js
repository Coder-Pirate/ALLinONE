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
            $result.hide().removeClass( 'success error' ).text( '' );

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
                        $result.addClass( 'success' ).text( msg ).show();
                        // Reload so the rest of the settings appear.
                        setTimeout( function () { window.location.reload(); }, 1200 );
                    } else {
                        var errMsg = ( response.data && response.data.message ) ? response.data.message : 'Connection failed.';
                        $result.addClass( 'error' ).text( errMsg ).show();
                    }
                }
            ).fail( function () {
                $spinner.removeClass( 'is-active' );
                $btn.prop( 'disabled', false ).text( COC.l10n.test_btn );
                $result.addClass( 'error' ).text( 'Request failed. Please try again.' ).show();
            } );
        } );

        /* Disconnect link */
        $( '#coc-disconnect-btn' ).on( 'click', function ( e ) {
            e.preventDefault();
            if ( ! window.confirm( 'Disconnect from Track Cart BD API? Other settings will be hidden until you reconnect.' ) ) {
                return;
            }
            $.post( COC.ajax_url, { action: 'coc_disconnect', nonce: COC.nonce }, function () {
                window.location.reload();
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

        // Unblock IP (delegated).
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

        /* ============================================================
           Pathao — Settings page: Connect + Load Stores
           ============================================================ */

        var $pathaoConnectBtn    = $( '#coc-pathao-connect-btn' );
        var $pathaoConnectResult = $( '#coc-pathao-connect-result' );
        var $pathaoStoreSelect   = $( '#coc_pathao_store_id' );
        var $pathaoLoadStoresBtn = $( '#coc-pathao-load-stores-btn' );

        if ( $pathaoConnectBtn.length ) {
            $pathaoConnectBtn.on( 'click', function () {
                $pathaoConnectResult.text( 'Connecting…' ).css( 'color', '#555' );
                $pathaoConnectBtn.prop( 'disabled', true );

                $.post(
                    COC.ajax_url,
                    {
                        action        : 'coc_pathao_admin_connect',
                        nonce         : COC.pathao_nonce,
                        env           : $( '#coc_pathao_env' ).val(),
                        client_id     : $( '#coc_pathao_client_id' ).val(),
                        client_secret : $( '#coc_pathao_client_secret' ).val(),
                        username      : $( '#coc_pathao_username' ).val(),
                        password      : $( '#coc_pathao_password' ).val(),
                    },
                    function ( r ) {
                        $pathaoConnectBtn.prop( 'disabled', false );
                        if ( r && r.success ) {
                            $pathaoConnectResult.text( '✓ ' + ( r.data.message || 'Connected!' ) ).css( 'color', '#15803d' );
                        } else {
                            var msg = ( r && r.data && r.data.message ) || 'Connection failed.';
                            $pathaoConnectResult.text( '✗ ' + msg ).css( 'color', '#b91c1c' );
                        }
                    }
                ).fail( function () {
                    $pathaoConnectBtn.prop( 'disabled', false );
                    $pathaoConnectResult.text( '✗ Request failed.' ).css( 'color', '#b91c1c' );
                } );
            } );
        }

        if ( $pathaoLoadStoresBtn.length ) {
            $pathaoLoadStoresBtn.on( 'click', function () {
                $pathaoLoadStoresBtn.prop( 'disabled', true ).text( 'Loading…' );

                $.post(
                    COC.ajax_url,
                    { action: 'coc_pathao_admin_get_stores', nonce: COC.pathao_nonce },
                    function ( r ) {
                        $pathaoLoadStoresBtn.prop( 'disabled', false ).text( 'Load Stores' );
                        if ( r && r.success && r.data ) {
                            var currentVal = $pathaoStoreSelect.val();
                            $pathaoStoreSelect.empty();
                            $pathaoStoreSelect.append( $( '<option>', { value: '', text: '— Select default store —' } ) );
                            $.each( r.data, function ( i, store ) {
                                $pathaoStoreSelect.append( $( '<option>', {
                                    value   : store.store_id,
                                    text    : store.store_name + ' (ID: ' + store.store_id + ')',
                                    selected: String( store.store_id ) === String( currentVal ),
                                } ) );
                            } );
                        } else {
                            alert( ( r && r.data && r.data.message ) || 'Could not load stores.' );
                        }
                    }
                ).fail( function () {
                    $pathaoLoadStoresBtn.prop( 'disabled', false ).text( 'Load Stores' );
                    alert( 'Request failed.' );
                } );
            } );
        }

        /* ============================================================
           Steadfast — Settings page: Test & Check Balance
           ============================================================ */

        var $sfCheckBtn    = $( '#coc-sf-check-btn' );
        var $sfCheckResult = $( '#coc-sf-check-result' );

        if ( $sfCheckBtn.length ) {
            $sfCheckBtn.on( 'click', function () {
                $sfCheckResult.text( 'Checking…' ).css( 'color', '#555' );
                $sfCheckBtn.prop( 'disabled', true );

                $.post(
                    COC.ajax_url,
                    {
                        action     : 'coc_sf_admin_check',
                        nonce      : COC.sf_nonce,
                        api_key    : $( '#coc_sf_api_key' ).val(),
                        secret_key : $( '#coc_sf_secret_key' ).val(),
                    },
                    function ( r ) {
                        $sfCheckBtn.prop( 'disabled', false );
                        if ( r && r.success ) {
                            $sfCheckResult.text( '✓ Balance: ৳' + r.data.balance ).css( 'color', '#15803d' );
                        } else {
                            var msg = ( r && r.data && r.data.message ) || 'Connection failed.';
                            $sfCheckResult.text( '✗ ' + msg ).css( 'color', '#b91c1c' );
                        }
                    }
                ).fail( function () {
                    $sfCheckBtn.prop( 'disabled', false );
                    $sfCheckResult.text( '✗ Request failed.' ).css( 'color', '#b91c1c' );
                } );
            } );
        }

        /* ============================================================
           RedX — Settings page: Test Connection
           ============================================================ */

        var $redxTestBtn    = $( '#coc-redx-test-btn' );
        var $redxTestResult = $( '#coc-redx-test-result' );

        if ( $redxTestBtn.length ) {
            $redxTestBtn.on( 'click', function () {
                $redxTestResult.text( 'Testing…' ).css( 'color', '#555' );
                $redxTestBtn.prop( 'disabled', true );

                $.post(
                    COC.ajax_url,
                    {
                        action : 'coc_redx_admin_test',
                        nonce  : COC.redx_nonce,
                        token  : $( '#coc_redx_token' ).val(),
                        env    : $( '#coc_redx_env' ).val(),
                    },
                    function ( r ) {
                        $redxTestBtn.prop( 'disabled', false );
                        if ( r && r.success ) {
                            $redxTestResult.text( '✓ ' + r.data.message ).css( 'color', '#15803d' );
                        } else {
                            var msg = ( r && r.data && r.data.message ) || 'Connection failed.';
                            $redxTestResult.text( '✗ ' + msg ).css( 'color', '#b91c1c' );
                        }
                    }
                ).fail( function () {
                    $redxTestBtn.prop( 'disabled', false );
                    $redxTestResult.text( '✗ Request failed.' ).css( 'color', '#b91c1c' );
                } );
            } );
        }

        /* ============================================================
           Carrybee — Settings page: Test Connection
           ============================================================ */

        var $cbTestBtn    = $( '#coc-cb-test-btn' );
        var $cbTestResult = $( '#coc-cb-test-result' );

        if ( $cbTestBtn.length ) {
            $cbTestBtn.on( 'click', function () {
                $cbTestResult.text( 'Testing…' ).css( 'color', '#555' );
                $cbTestBtn.prop( 'disabled', true );

                $.post(
                    COC.ajax_url,
                    {
                        action         : 'coc_cb_admin_test',
                        nonce          : COC.cb_nonce,
                        env            : $( '#coc_cb_env' ).val(),
                        client_id      : $( '#coc_cb_client_id' ).val(),
                        client_secret  : $( '#coc_cb_client_secret' ).val(),
                        client_context : $( '#coc_cb_client_context' ).val(),
                    },
                    function ( r ) {
                        $cbTestBtn.prop( 'disabled', false );
                        if ( r && r.success ) {
                            $cbTestResult.text( '✓ ' + r.data.message ).css( 'color', '#15803d' );
                        } else {
                            var msg = ( r && r.data && r.data.message ) || 'Connection failed.';
                            $cbTestResult.text( '✗ ' + msg ).css( 'color', '#b91c1c' );
                        }
                    }
                ).fail( function () {
                    $cbTestBtn.prop( 'disabled', false );
                    $cbTestResult.text( '✗ Request failed.' ).css( 'color', '#b91c1c' );
                } );
            } );
        }

    } );
}( jQuery ) );
