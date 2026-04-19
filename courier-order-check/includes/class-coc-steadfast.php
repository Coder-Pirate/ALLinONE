<?php
defined( 'ABSPATH' ) || exit;

/**
 * Steadfast Courier API integration.
 *
 * Uses Api-Key + Secret-Key header authentication (no OAuth).
 *
 * WP options used:
 *   coc_sf_api_key         API Key from Steadfast portal
 *   coc_sf_secret_key      Secret Key from Steadfast portal
 *   coc_sf_webhook_secret  Bearer token to verify incoming webhooks
 *
 * Order meta used:
 *   _coc_sf_consignment_id
 *   _coc_sf_tracking_code
 *   _coc_sf_invoice
 *   _coc_sf_status
 *   _coc_sf_delivery_charge (set by webhook)
 *   _coc_sf_last_tracking_msg (set by webhook)
 */
class COC_Steadfast {

    const BASE_URL = 'https://portal.packzy.com/api/v1';

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        // Assets for order edit pages.
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_order_assets' ] );

        // Webhook endpoint.
        add_action( 'rest_api_init', [ __CLASS__, 'register_webhook' ] );

        // AJAX handlers — admin-only.
        foreach ( [ 'coc_sf_create_order', 'coc_sf_get_status', 'coc_sf_get_balance', 'coc_sf_create_return' ] as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, 'ajax_' . $action ] );
        }
    }

    /* ------------------------------------------------------------------
     * API helpers
     * ------------------------------------------------------------------ */

    private static function headers() {
        return [
            'Api-Key'      => get_option( 'coc_sf_api_key',    '' ),
            'Secret-Key'   => get_option( 'coc_sf_secret_key', '' ),
            'Content-Type' => 'application/json',
        ];
    }

    private static function api_get( $endpoint ) {
        $resp = wp_remote_get(
            self::BASE_URL . $endpoint,
            [ 'headers' => self::headers(), 'timeout' => 15 ]
        );
        return self::parse( $resp );
    }

    private static function api_post( $endpoint, array $body ) {
        $resp = wp_remote_post(
            self::BASE_URL . $endpoint,
            [
                'headers'     => self::headers(),
                'body'        => wp_json_encode( $body ),
                'timeout'     => 15,
                'data_format' => 'body',
            ]
        );
        return self::parse( $resp );
    }

    private static function parse( $resp ) {
        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'message' => $resp->get_error_message(), 'data' => null ];
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );

        // Check both HTTP status and body-level status (Steadfast may return HTTP 200 with error in body).
        $http_ok     = in_array( $code, [ 200, 201 ], true );
        $body_status = isset( $body['status'] ) ? (int) $body['status'] : $code;
        $ok          = $http_ok && in_array( $body_status, [ 200, 201 ], true );

        // Always prefer the message from the API response body.
        $msg = $body['message'] ?? ( $ok ? 'OK' : 'Error ' . $code );

        // Append validation errors if present.
        if ( ! $ok && isset( $body['errors'] ) && is_array( $body['errors'] ) ) {
            $parts = [];
            foreach ( $body['errors'] as $field => $errors ) {
                $parts[] = implode( ', ', (array) $errors );
            }
            if ( $parts ) {
                $msg .= ' — ' . implode( '; ', $parts );
            }
        }

        return [ 'ok' => $ok, 'code' => $code, 'data' => $body, 'message' => $msg ];
    }

    public static function is_connected() {
        return ! empty( get_option( 'coc_sf_api_key', '' ) )
            && ! empty( get_option( 'coc_sf_secret_key', '' ) );
    }

    /* ------------------------------------------------------------------
     * API methods
     * ------------------------------------------------------------------ */

    public static function create_order( array $data ) {
        return self::api_post( '/create_order', $data );
    }

    public static function get_balance() {
        return self::api_get( '/get_balance' );
    }

    /**
     * Check balance with explicitly-supplied credentials (no save required first).
     */
    public static function get_balance_with( $api_key, $secret_key ) {
        $resp = wp_remote_get(
            self::BASE_URL . '/get_balance',
            [
                'headers' => [
                    'Api-Key'      => $api_key,
                    'Secret-Key'   => $secret_key,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 15,
            ]
        );
        return self::parse( $resp );
    }

    public static function status_by_cid( $cid ) {
        return self::api_get( '/status_by_cid/' . rawurlencode( $cid ) );
    }

    public static function status_by_invoice( $invoice ) {
        return self::api_get( '/status_by_invoice/' . rawurlencode( $invoice ) );
    }

    public static function status_by_trackingcode( $code ) {
        return self::api_get( '/status_by_trackingcode/' . rawurlencode( $code ) );
    }

    public static function create_return_request( array $data ) {
        return self::api_post( '/create_return_request', $data );
    }

    public static function get_return_requests() {
        return self::api_get( '/get_return_requests' );
    }

    public static function get_return_request( $id ) {
        return self::api_get( '/get_return_request/' . absint( $id ) );
    }

    public static function get_police_stations() {
        return self::api_get( '/police_stations' );
    }

    public static function get_payments() {
        return self::api_get( '/payments' );
    }

    public static function get_payment( $payment_id ) {
        return self::api_get( '/payments/' . absint( $payment_id ) );
    }

    /* ------------------------------------------------------------------
     * AJAX helpers
     * ------------------------------------------------------------------ */

    private static function verify_ajax() {
        if ( ! check_ajax_referer( 'coc_steadfast', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }
    }

    /* ------------------------------------------------------------------
     * AJAX handlers
     * ------------------------------------------------------------------ */

    public static function ajax_coc_sf_create_order() {
        self::verify_ajax();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Invalid WooCommerce order.' ] );
        }

        $data = [
            'invoice'          => sanitize_text_field( $_POST['invoice']           ?? '' ),
            'recipient_name'   => sanitize_text_field( $_POST['recipient_name']    ?? '' ),
            'recipient_phone'  => sanitize_text_field( $_POST['recipient_phone']   ?? '' ),
            'recipient_address'=> sanitize_text_field( $_POST['recipient_address'] ?? '' ),
            'cod_amount'       => (float) ( $_POST['cod_amount'] ?? 0 ),
            'delivery_type'    => (int) ( $_POST['delivery_type'] ?? 0 ),
        ];

        foreach ( [ 'note', 'item_description' ] as $field ) {
            $v = sanitize_text_field( $_POST[ $field ] ?? '' );
            if ( $v !== '' ) {
                $data[ $field ] = $v;
            }
        }

        if ( ! $data['invoice'] || ! $data['recipient_name'] || ! $data['recipient_phone'] || ! $data['recipient_address'] ) {
            wp_send_json_error( [ 'message' => 'invoice, recipient_name, recipient_phone, and recipient_address are required.' ] );
        }

        $r = self::create_order( $data );

        if ( $r['ok'] && isset( $r['data']['consignment']['consignment_id'] ) ) {
            $c = $r['data']['consignment'];
            $order->update_meta_data( '_coc_sf_consignment_id', sanitize_text_field( (string) $c['consignment_id'] ) );
            $order->update_meta_data( '_coc_sf_tracking_code',  sanitize_text_field( $c['tracking_code'] ?? '' ) );
            $order->update_meta_data( '_coc_sf_invoice',        sanitize_text_field( $c['invoice']       ?? '' ) );
            $order->update_meta_data( '_coc_sf_status',         sanitize_text_field( $c['status']        ?? '' ) );
            $order->save();

            wp_send_json_success( [
                'consignment_id' => $c['consignment_id'],
                'tracking_code'  => $c['tracking_code'] ?? '',
                'status'         => $c['status']        ?? '',
                'message'        => $r['data']['message'] ?? 'Consignment has been created successfully.',
            ] );
        } else {
            $err = $r['message'] ?: ( $r['data']['message'] ?? 'Order creation failed.' );
            wp_send_json_error( [ 'message' => $err, 'api_response' => $r['data'] ] );
        }
    }

    public static function ajax_coc_sf_get_status() {
        self::verify_ajax();

        $cid = sanitize_text_field( $_POST['consignment_id'] ?? '' );
        if ( ! $cid ) {
            wp_send_json_error( [ 'message' => 'consignment_id is required.' ] );
        }

        $r = self::status_by_cid( $cid );
        if ( $r['ok'] ) {
            wp_send_json_success( [ 'status' => $r['data']['delivery_status'] ?? 'unknown' ] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    public static function ajax_coc_sf_get_balance() {
        self::verify_ajax();

        $r = self::get_balance();
        if ( $r['ok'] ) {
            wp_send_json_success( [ 'balance' => $r['data']['current_balance'] ?? 0 ] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    public static function ajax_coc_sf_create_return() {
        self::verify_ajax();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Invalid WooCommerce order.' ] );
        }

        $cid = sanitize_text_field( $order->get_meta( '_coc_sf_consignment_id' ) );
        if ( ! $cid ) {
            wp_send_json_error( [ 'message' => 'No Steadfast consignment found for this order.' ] );
        }

        $data = [ 'consignment_id' => $cid ];
        $reason = sanitize_text_field( $_POST['reason'] ?? '' );
        if ( $reason ) {
            $data['reason'] = $reason;
        }

        $r = self::create_return_request( $data );
        if ( $r['ok'] ) {
            wp_send_json_success( [ 'message' => 'Return request submitted successfully.', 'data' => $r['data'] ] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ?: 'Return request failed.' ] );
        }
    }

    /* ------------------------------------------------------------------
     * Webhook
     * ------------------------------------------------------------------ */

    public static function register_webhook() {
        register_rest_route( 'coc/v1', '/sf-webhook', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_webhook' ],
            'permission_callback' => [ __CLASS__, 'verify_webhook_auth' ],
        ] );
    }

    public static function verify_webhook_auth( WP_REST_Request $request ) {
        $secret = get_option( 'coc_sf_webhook_secret', '' );
        if ( empty( $secret ) ) {
            return false;
        }
        $auth  = $request->get_header( 'Authorization' );
        $token = '';
        if ( preg_match( '/^Bearer\s+(.+)$/i', trim( $auth ), $m ) ) {
            $token = $m[1];
        }
        return hash_equals( $secret, $token );
    }

    public static function handle_webhook( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        if ( ! is_array( $payload ) ) {
            return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Invalid payload.' ], 400 );
        }

        $type = sanitize_text_field( $payload['notification_type'] ?? '' );

        if ( in_array( $type, [ 'delivery_status', 'tracking_update' ], true ) ) {
            $cid    = absint( $payload['consignment_id'] ?? 0 );
            $status = sanitize_text_field( $payload['status'] ?? '' );
            $msg    = sanitize_text_field( $payload['tracking_message'] ?? '' );

            if ( $cid ) {
                $orders = wc_get_orders( [
                    'meta_key'   => '_coc_sf_consignment_id',
                    'meta_value' => (string) $cid,
                    'limit'      => 1,
                ] );
                if ( ! empty( $orders ) ) {
                    $order = $orders[0];
                    if ( $status ) {
                        $order->update_meta_data( '_coc_sf_status', $status );
                    }
                    if ( $msg ) {
                        $order->update_meta_data( '_coc_sf_last_tracking_msg', $msg );
                    }
                    if ( $type === 'delivery_status' && isset( $payload['delivery_charge'] ) ) {
                        $order->update_meta_data( '_coc_sf_delivery_charge', (float) $payload['delivery_charge'] );
                    }
                    $order->save();
                }
            }
        }

        return new WP_REST_Response( [ 'status' => 'success', 'message' => 'Webhook received successfully.' ], 200 );
    }

    /* ------------------------------------------------------------------
     * Order assets
     * ------------------------------------------------------------------ */

    public static function enqueue_order_assets( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) {
            return;
        }
        if ( ! self::is_connected() ) {
            return;
        }
        $ver = file_exists( COC_PLUGIN_DIR . 'assets/js/steadfast.js' )
            ? filemtime( COC_PLUGIN_DIR . 'assets/js/steadfast.js' )
            : COC_VERSION;
        wp_enqueue_script(
            'coc-steadfast',
            COC_PLUGIN_URL . 'assets/js/steadfast.js',
            [ 'jquery' ],
            $ver,
            true
        );
    }

    /* ------------------------------------------------------------------
     * Order panel
     * ------------------------------------------------------------------ */

    public static function render_order_panel( $order ) {
        if ( ! self::is_connected() ) {
            return;
        }

        $cid             = $order->get_meta( '_coc_sf_consignment_id' );
        $tracking_code   = $order->get_meta( '_coc_sf_tracking_code' );
        $sf_invoice      = $order->get_meta( '_coc_sf_invoice' );
        $sf_status       = $order->get_meta( '_coc_sf_status' );
        $delivery_charge = $order->get_meta( '_coc_sf_delivery_charge' );
        $tracking_msg    = $order->get_meta( '_coc_sf_last_tracking_msg' );

        $order_id       = $order->get_id();
        $nonce          = wp_create_nonce( 'coc_steadfast' );

        $default_name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $default_phone   = $order->get_billing_phone();
        $default_address = trim( implode( ', ', array_filter( [
            $order->get_billing_address_1(),
            $order->get_billing_address_2(),
            $order->get_billing_city(),
        ] ) ) );
        $default_cod     = (int) round( $order->get_total() );
        $default_invoice = $order->get_order_number();
        ?>
        <div class="coc-courier-panel coc-sf-wrapper"
             id="coc-sf-panel"
             data-order-id="<?php echo esc_attr( $order_id ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>">

            <div class="coc-courier-title coc-sf-title-bar">
                <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                    <path d="M8 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm7 0a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM2 2h1.22l.4 2H17l-1.68 7H5.48L4.14 4H2V2z"/>
                </svg>
                Steadfast Courier
                <?php if ( $cid ) : ?><span class="coc-courier-badge">Order Sent</span><?php endif; ?>
            </div>

            <?php if ( $cid ) : ?>

                <div class="coc-sf-info-grid">
                    <div class="coc-sf-info-row">
                        <span class="coc-sf-label">Consignment ID</span>
                        <span class="coc-sf-value"><?php echo esc_html( $cid ); ?></span>
                    </div>
                    <div class="coc-sf-info-row">
                        <span class="coc-sf-label">Tracking Code</span>
                        <span class="coc-sf-value"><?php echo esc_html( $tracking_code ); ?></span>
                    </div>
                    <div class="coc-sf-info-row">
                        <span class="coc-sf-label">Invoice</span>
                        <span class="coc-sf-value"><?php echo esc_html( $sf_invoice ); ?></span>
                    </div>
                    <?php if ( $delivery_charge !== '' && $delivery_charge !== false ) : ?>
                    <div class="coc-sf-info-row">
                        <span class="coc-sf-label">Delivery Charge</span>
                        <span class="coc-sf-value">৳<?php echo esc_html( $delivery_charge ); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="coc-sf-info-row">
                        <span class="coc-sf-label">Status</span>
                        <span class="coc-sf-value coc-sf-status" id="coc-sf-status"><?php echo esc_html( $sf_status ?: 'in_review' ); ?></span>
                    </div>
                    <?php if ( $tracking_msg ) : ?>
                    <div class="coc-sf-info-row">
                        <span class="coc-sf-label">Last Update</span>
                        <span class="coc-sf-value"><?php echo esc_html( $tracking_msg ); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="coc-sf-actions-row">
                    <button type="button" class="button" id="coc-sf-refresh-btn"
                            data-cid="<?php echo esc_attr( $cid ); ?>">
                        ↻ Refresh Status
                    </button>
                    <button type="button" class="button" id="coc-sf-return-btn">
                        Return Request
                    </button>
                </div>

                <div id="coc-sf-return-form" class="coc-sf-return-form" style="display:none;">
                    <div class="coc-sf-row">
                        <label class="coc-sf-label">Reason (optional)</label>
                        <textarea id="coc-sf-return-reason" class="widefat" rows="2"></textarea>
                    </div>
                    <div class="coc-sf-actions-row">
                        <button type="button" class="button button-primary" id="coc-sf-submit-return">Submit Return</button>
                    </div>
                    <div id="coc-sf-return-msg" class="coc-sf-msg" style="display:none;"></div>
                </div>

                <div id="coc-sf-msg" class="coc-sf-msg" style="display:none;"></div>

            <?php else : ?>

                <form class="coc-sf-form" id="coc-sf-form" onsubmit="return false;">

                    <div class="coc-sf-row coc-sf-row--2">
                        <div>
                            <label class="coc-sf-label">Invoice <span class="required">*</span></label>
                            <input type="text" id="coc-sf-invoice" class="widefat"
                                   value="<?php echo esc_attr( $default_invoice ); ?>" />
                        </div>
                        <div>
                            <label class="coc-sf-label">COD Amount (৳) <span class="required">*</span></label>
                            <input type="number" id="coc-sf-cod" class="widefat"
                                   value="<?php echo esc_attr( $default_cod ); ?>" min="0" step="1" />
                        </div>
                    </div>

                    <div class="coc-sf-row coc-sf-row--2">
                        <div>
                            <label class="coc-sf-label">Recipient Name <span class="required">*</span></label>
                            <input type="text" id="coc-sf-name" class="widefat"
                                   value="<?php echo esc_attr( $default_name ); ?>" />
                        </div>
                        <div>
                            <label class="coc-sf-label">Phone <span class="required">*</span></label>
                            <input type="text" id="coc-sf-phone" class="widefat"
                                   value="<?php echo esc_attr( $default_phone ); ?>" maxlength="11" />
                        </div>
                    </div>

                    <div class="coc-sf-row">
                        <label class="coc-sf-label">Recipient Address <span class="required">*</span></label>
                        <textarea id="coc-sf-address" class="widefat" rows="2"><?php echo esc_textarea( $default_address ); ?></textarea>
                    </div>

                    <div class="coc-sf-row coc-sf-row--2">
                        <div>
                            <label class="coc-sf-label">Delivery Type</label>
                            <select id="coc-sf-delivery-type" class="widefat">
                                <option value="0">Home Delivery</option>
                                <option value="1">Point Delivery / Hub Pickup</option>
                            </select>
                        </div>
                        <div>
                            <label class="coc-sf-label">Note</label>
                            <input type="text" id="coc-sf-note" class="widefat"
                                   placeholder="e.g. Deliver within 3 PM" />
                        </div>
                    </div>

                    <div class="coc-sf-row">
                        <label class="coc-sf-label">Item Description</label>
                        <input type="text" id="coc-sf-item-desc" class="widefat" placeholder="Optional" />
                    </div>

                    <div class="coc-sf-actions-row">
                        <button type="button" class="button button-primary" id="coc-sf-submit-btn">
                            Create Steadfast Order
                        </button>
                    </div>

                    <div id="coc-sf-msg" class="coc-sf-msg" style="display:none;"></div>

                </form>

            <?php endif; ?>

        </div>
        <?php
    }
}
