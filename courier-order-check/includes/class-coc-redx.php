<?php
defined( 'ABSPATH' ) || exit;

/**
 * RedX Courier API integration.
 *
 * Uses a single JWT Bearer token in the API-ACCESS-TOKEN header.
 *
 * WP options used:
 *   coc_redx_env              sandbox|production
 *   coc_redx_token            JWT Bearer token from RedX merchant portal
 *   coc_redx_webhook_secret   Optional secret to verify incoming webhooks
 *   coc_redx_pickup_store_id  Default pickup store ID
 *
 * Order meta used:
 *   _coc_redx_tracking_id
 *   _coc_redx_status
 *   _coc_redx_charge
 *   _coc_redx_invoice_id
 *   _coc_redx_delivery_area_id
 *   _coc_redx_last_message
 */
class COC_RedX {

    const BASE_URL_SANDBOX    = 'https://sandbox.redx.com.bd/v1.0.0-beta';
    const BASE_URL_PRODUCTION = 'https://openapi.redx.com.bd/v1.0.0-beta';

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_order_assets' ] );
        add_action( 'rest_api_init',         [ __CLASS__, 'register_webhook' ] );

        foreach ( [
            'coc_redx_create_parcel',
            'coc_redx_get_status',
            'coc_redx_get_areas',
            'coc_redx_get_stores',
            'coc_redx_calculate_charge',
            'coc_redx_cancel_parcel',
        ] as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, 'ajax_dispatch' ] );
        }
    }

    /* ------------------------------------------------------------------
     * API helpers
     * ------------------------------------------------------------------ */

    private static function base_url() {
        return get_option( 'coc_redx_env', 'sandbox' ) === 'production'
            ? self::BASE_URL_PRODUCTION
            : self::BASE_URL_SANDBOX;
    }

    private static function headers() {
        return [
            'API-ACCESS-TOKEN' => 'Bearer ' . get_option( 'coc_redx_token', '' ),
            'Content-Type'     => 'application/json',
        ];
    }

    private static function api_get( $endpoint ) {
        $resp = wp_remote_get(
            self::base_url() . $endpoint,
            [ 'headers' => self::headers(), 'timeout' => 15 ]
        );
        return self::parse( $resp );
    }

    private static function api_post( $endpoint, array $body ) {
        $resp = wp_remote_post(
            self::base_url() . $endpoint,
            [
                'headers'     => self::headers(),
                'body'        => wp_json_encode( $body ),
                'timeout'     => 15,
                'data_format' => 'body',
            ]
        );
        return self::parse( $resp );
    }

    private static function api_patch( $endpoint, array $body ) {
        $resp = wp_remote_request(
            self::base_url() . $endpoint,
            [
                'method'      => 'PATCH',
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
        $ok   = in_array( $code, [ 200, 201 ], true );
        $msg  = $ok ? 'OK' : ( isset( $body['message'] ) ? $body['message'] : 'Error ' . $code );
        return [ 'ok' => $ok, 'code' => $code, 'data' => $body, 'message' => $msg ];
    }

    public static function is_connected() {
        return ! empty( get_option( 'coc_redx_token', '' ) );
    }

    /* ------------------------------------------------------------------
     * API methods
     * ------------------------------------------------------------------ */

    public static function get_areas( $query_args = [] ) {
        $qs = ! empty( $query_args ) ? '?' . http_build_query( $query_args ) : '';
        return self::api_get( '/areas' . $qs );
    }

    public static function get_pickup_stores() {
        return self::api_get( '/pickup/stores' );
    }

    public static function calculate_charge( $delivery_area_id, $pickup_area_id, $cod, $weight = 0.5 ) {
        $qs = '?' . http_build_query( [
            'delivery_area_id'       => $delivery_area_id,
            'pickup_area_id'         => $pickup_area_id,
            'cash_collection_amount' => $cod,
            'weight'                 => $weight,
        ] );
        return self::api_get( '/charge/charge_calculator' . $qs );
    }

    public static function create_parcel( array $data ) {
        return self::api_post( '/parcel', $data );
    }

    public static function get_parcel_info( $tracking_id ) {
        return self::api_get( '/parcel/info/' . rawurlencode( $tracking_id ) );
    }

    public static function track_parcel( $tracking_id ) {
        return self::api_get( '/parcel/track/' . rawurlencode( $tracking_id ) );
    }

    public static function cancel_parcel( $tracking_id ) {
        return self::api_patch( '/parcels', [ 'tracking_id' => $tracking_id, 'status' => 'cancelled' ] );
    }

    /**
     * Test the connection with a given token without saving it first.
     */
    public static function test_connection_with( $token, $env = 'sandbox' ) {
        $base = $env === 'production' ? self::BASE_URL_PRODUCTION : self::BASE_URL_SANDBOX;
        $resp = wp_remote_get(
            $base . '/pickup/stores',
            [
                'headers' => [
                    'API-ACCESS-TOKEN' => 'Bearer ' . $token,
                    'Content-Type'     => 'application/json',
                ],
                'timeout' => 15,
            ]
        );
        return self::parse( $resp );
    }

    /* ------------------------------------------------------------------
     * AJAX helpers
     * ------------------------------------------------------------------ */

    private static function verify_ajax() {
        if ( ! check_ajax_referer( 'coc_redx', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }
    }

    /**
     * Single entry-point for all RedX AJAX actions, avoiding multiple hook registrations.
     */
    public static function ajax_dispatch() {
        $action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
        switch ( $action ) {
            case 'coc_redx_create_parcel':
                self::ajax_coc_redx_create_parcel();
                break;
            case 'coc_redx_get_status':
                self::ajax_coc_redx_get_status();
                break;
            case 'coc_redx_get_areas':
                self::ajax_coc_redx_get_areas();
                break;
            case 'coc_redx_get_stores':
                self::ajax_coc_redx_get_stores();
                break;
            case 'coc_redx_calculate_charge':
                self::ajax_coc_redx_calculate_charge();
                break;
            case 'coc_redx_cancel_parcel':
                self::ajax_coc_redx_cancel_parcel();
                break;
        }
        wp_send_json_error( [ 'message' => 'Unknown action.' ] );
    }

    /* ------------------------------------------------------------------
     * AJAX handlers
     * ------------------------------------------------------------------ */

    private static function ajax_coc_redx_create_parcel() {
        self::verify_ajax();

        $order_id = absint( isset( $_POST['order_id'] ) ? $_POST['order_id'] : 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Invalid WooCommerce order.' ] );
        }

        $name    = sanitize_text_field( isset( $_POST['customer_name'] )      ? $_POST['customer_name']      : '' );
        $phone   = sanitize_text_field( isset( $_POST['customer_phone'] )      ? $_POST['customer_phone']      : '' );
        $area    = sanitize_text_field( isset( $_POST['delivery_area'] )       ? $_POST['delivery_area']       : '' );
        $area_id = absint( isset( $_POST['delivery_area_id'] )                 ? $_POST['delivery_area_id']    : 0 );
        $address = sanitize_textarea_field( isset( $_POST['customer_address'] ) ? $_POST['customer_address'] : '' );
        $cod     = (float) ( isset( $_POST['cash_collection_amount'] )         ? $_POST['cash_collection_amount'] : 0 );
        $weight  = (float) ( isset( $_POST['parcel_weight'] )                  ? $_POST['parcel_weight']       : 0.5 );
        $value   = (float) ( isset( $_POST['value'] )                          ? $_POST['value']               : 0 );

        if ( ! $name || ! $phone || ! $area || ! $area_id || ! $address ) {
            wp_send_json_error( [ 'message' => 'Name, phone, delivery area, and address are required.' ] );
        }

        $invoice = sanitize_text_field( isset( $_POST['merchant_invoice_id'] ) ? $_POST['merchant_invoice_id'] : $order->get_order_number() );

        $body = [
            'customer_name'          => $name,
            'customer_phone'         => $phone,
            'delivery_area'          => $area,
            'delivery_area_id'       => $area_id,
            'customer_address'       => $address,
            'cash_collection_amount' => $cod,
            'parcel_weight'          => max( 0.1, $weight ),
            'value'                  => $value ?: $cod,
            'merchant_invoice_id'    => $invoice,
        ];

        $instruction = sanitize_text_field( isset( $_POST['instruction'] ) ? $_POST['instruction'] : '' );
        if ( $instruction !== '' ) {
            $body['instruction'] = $instruction;
        }

        $pickup_store_id = absint( isset( $_POST['pickup_store_id'] ) ? $_POST['pickup_store_id'] : 0 )
                         ?: absint( get_option( 'coc_redx_pickup_store_id', 0 ) );
        if ( $pickup_store_id ) {
            $body['pickup_store_id'] = $pickup_store_id;
        }

        $r = self::create_parcel( $body );

        if ( $r['ok'] && ! empty( $r['data']['tracking_id'] ) ) {
            $tracking_id = sanitize_text_field( $r['data']['tracking_id'] );
            $order->update_meta_data( '_coc_redx_tracking_id',     $tracking_id );
            $order->update_meta_data( '_coc_redx_status',          'pending' );
            $order->update_meta_data( '_coc_redx_invoice_id',      $invoice );
            $order->update_meta_data( '_coc_redx_delivery_area_id', $area_id );
            $order->save();

            wp_send_json_success( [
                'tracking_id' => $tracking_id,
                'message'     => isset( $r['data']['message'] ) ? $r['data']['message'] : 'Parcel created successfully.',
            ] );
        } else {
            $err_msg = isset( $r['data']['message'] ) ? $r['data']['message'] : ( $r['message'] ?: 'Parcel creation failed.' );
            wp_send_json_error( [ 'message' => $err_msg ] );
        }
    }

    private static function ajax_coc_redx_get_status() {
        self::verify_ajax();

        $tracking_id = sanitize_text_field( isset( $_POST['tracking_id'] ) ? $_POST['tracking_id'] : '' );
        if ( ! $tracking_id ) {
            wp_send_json_error( [ 'message' => 'tracking_id is required.' ] );
        }

        $order_id = absint( isset( $_POST['order_id'] ) ? $_POST['order_id'] : 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;

        $r = self::get_parcel_info( $tracking_id );
        if ( $r['ok'] ) {
            $status = sanitize_text_field( isset( $r['data']['status'] ) ? $r['data']['status'] : 'unknown' );
            if ( $order ) {
                $order->update_meta_data( '_coc_redx_status', $status );
                if ( ! empty( $r['data']['delivery_charge'] ) ) {
                    $order->update_meta_data( '_coc_redx_charge', (float) $r['data']['delivery_charge'] );
                }
                $order->save();
            }
            wp_send_json_success( [ 'status' => $status ] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    private static function ajax_coc_redx_get_areas() {
        self::verify_ajax();

        $district = sanitize_text_field( isset( $_POST['district_name'] ) ? $_POST['district_name'] : '' );
        $postcode = sanitize_text_field( isset( $_POST['post_code'] )      ? $_POST['post_code']      : '' );
        $args     = [];
        if ( $district !== '' ) {
            $args['district_name'] = $district;
        } elseif ( $postcode !== '' ) {
            $args['post_code'] = $postcode;
        }

        $r = self::get_areas( $args );
        if ( $r['ok'] ) {
            // API returns {"areas": [...]}, extract the array.
            $areas = isset( $r['data']['areas'] ) ? $r['data']['areas'] : $r['data'];
            wp_send_json_success( is_array( $areas ) ? $areas : [] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    private static function ajax_coc_redx_get_stores() {
        self::verify_ajax();

        $r = self::get_pickup_stores();
        if ( $r['ok'] ) {
            // API returns {"pickup_stores": [...]}, extract the array.
            $stores = isset( $r['data']['pickup_stores'] ) ? $r['data']['pickup_stores'] : $r['data'];
            wp_send_json_success( is_array( $stores ) ? $stores : [] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    private static function ajax_coc_redx_calculate_charge() {
        self::verify_ajax();

        $delivery_area_id = absint( isset( $_POST['delivery_area_id'] ) ? $_POST['delivery_area_id'] : 0 );
        $pickup_area_id   = absint( isset( $_POST['pickup_area_id'] )   ? $_POST['pickup_area_id']   : 0 );
        $cod              = (float) ( isset( $_POST['cod'] )    ? $_POST['cod']    : 0 );
        $weight           = (float) ( isset( $_POST['weight'] ) ? $_POST['weight'] : 0.5 );

        if ( ! $delivery_area_id || ! $pickup_area_id ) {
            wp_send_json_error( [ 'message' => 'delivery_area_id and pickup_area_id are required.' ] );
        }

        $r = self::calculate_charge( $delivery_area_id, $pickup_area_id, $cod, max( 0.1, $weight ) );
        if ( $r['ok'] ) {
            wp_send_json_success( $r['data'] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    private static function ajax_coc_redx_cancel_parcel() {
        self::verify_ajax();

        $tracking_id = sanitize_text_field( isset( $_POST['tracking_id'] ) ? $_POST['tracking_id'] : '' );
        $order_id    = absint( isset( $_POST['order_id'] ) ? $_POST['order_id'] : 0 );

        if ( ! $tracking_id ) {
            wp_send_json_error( [ 'message' => 'tracking_id is required.' ] );
        }

        $r = self::cancel_parcel( $tracking_id );
        if ( $r['ok'] ) {
            $order = $order_id ? wc_get_order( $order_id ) : null;
            if ( $order ) {
                $order->update_meta_data( '_coc_redx_status', 'cancelled' );
                $order->save();
            }
            wp_send_json_success( [ 'message' => 'Parcel cancelled successfully.' ] );
        } else {
            $err_msg = isset( $r['data']['message'] ) ? $r['data']['message'] : ( $r['message'] ?: 'Cancel failed.' );
            wp_send_json_error( [ 'message' => $err_msg ] );
        }
    }

    /* ------------------------------------------------------------------
     * Webhook
     * ------------------------------------------------------------------ */

    public static function register_webhook() {
        register_rest_route( 'coc/v1', '/redx-webhook', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_webhook' ],
            'permission_callback' => [ __CLASS__, 'verify_webhook_auth' ],
        ] );
    }

    public static function verify_webhook_auth( WP_REST_Request $request ) {
        $secret = get_option( 'coc_redx_webhook_secret', '' );
        // If no secret is configured, allow all (RedX sends from known IPs).
        if ( empty( $secret ) ) {
            return true;
        }
        $auth  = $request->get_header( 'Authorization' );
        $token = '';
        if ( preg_match( '/^Bearer\s+(.+)$/i', trim( (string) $auth ), $m ) ) {
            $token = $m[1];
        }
        return hash_equals( $secret, $token );
    }

    public static function handle_webhook( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        if ( ! is_array( $payload ) ) {
            return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Invalid payload.' ], 400 );
        }

        $tracking_id = sanitize_text_field( isset( $payload['tracking_number'] ) ? $payload['tracking_number'] : '' );
        $status      = sanitize_text_field( isset( $payload['status'] )          ? $payload['status']          : '' );
        $message_en  = sanitize_text_field( isset( $payload['message_en'] )      ? $payload['message_en']      : '' );

        if ( ! $tracking_id || ! $status ) {
            return new WP_REST_Response( [ 'status' => 'ok', 'message' => 'Skipped.' ], 200 );
        }

        $orders = wc_get_orders( [
            'meta_key'   => '_coc_redx_tracking_id',
            'meta_value' => $tracking_id,
            'limit'      => 1,
        ] );

        if ( ! empty( $orders ) ) {
            $order = $orders[0];
            $order->update_meta_data( '_coc_redx_status', $status );
            if ( $message_en !== '' ) {
                $order->update_meta_data( '_coc_redx_last_message', $message_en );
            }
            $order->save();
        }

        return new WP_REST_Response( [ 'status' => 'success' ], 200 );
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
        $ver = file_exists( COC_PLUGIN_DIR . 'assets/js/redx.js' )
            ? filemtime( COC_PLUGIN_DIR . 'assets/js/redx.js' )
            : COC_VERSION;
        wp_enqueue_script(
            'coc-redx',
            COC_PLUGIN_URL . 'assets/js/redx.js',
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

        $tracking_id  = $order->get_meta( '_coc_redx_tracking_id' );
        $status       = $order->get_meta( '_coc_redx_status' );
        $charge       = $order->get_meta( '_coc_redx_charge' );
        $last_msg     = $order->get_meta( '_coc_redx_last_message' );

        $order_id      = $order->get_id();
        $nonce         = wp_create_nonce( 'coc_redx' );
        $default_store = absint( get_option( 'coc_redx_pickup_store_id', 0 ) );

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
        <div class="coc-courier-panel coc-redx-wrapper"
             id="coc-redx-panel"
             data-order-id="<?php echo esc_attr( $order_id ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-default-store="<?php echo esc_attr( $default_store ); ?>">

            <div class="coc-courier-title coc-redx-title-bar">
                <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                    <path d="M8 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm7 0a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM2 2h1.22l.4 2H17l-1.68 7H5.48L4.14 4H2V2z"/>
                </svg>
                RedX Courier
                <?php if ( $tracking_id ) : ?><span class="coc-courier-badge">Order Sent</span><?php endif; ?>
            </div>

            <?php if ( $tracking_id ) : ?>

                <div class="coc-sf-info-grid">
                    <div class="coc-sf-info-row">
                        <span class="coc-sf-label">Tracking ID</span>
                        <span class="coc-sf-value"><code><?php echo esc_html( $tracking_id ); ?></code></span>
                    </div>
                    <div class="coc-sf-info-row">
                        <span class="coc-sf-label">Status</span>
                        <span class="coc-sf-value coc-sf-status" id="coc-redx-status"><?php echo esc_html( $status ?: 'pending' ); ?></span>
                    </div>
                    <?php if ( $charge !== '' && $charge !== false && $charge !== null ) : ?>
                    <div class="coc-sf-info-row">
                        <span class="coc-sf-label">Delivery Charge</span>
                        <span class="coc-sf-value">৳<?php echo esc_html( $charge ); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $last_msg ) : ?>
                    <div class="coc-sf-info-row">
                        <span class="coc-sf-label">Last Update</span>
                        <span class="coc-sf-value"><?php echo esc_html( $last_msg ); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="coc-sf-actions-row">
                    <button type="button" class="button" id="coc-redx-refresh-btn"
                            data-tracking-id="<?php echo esc_attr( $tracking_id ); ?>">
                        ↻ Refresh Status
                    </button>
                    <button type="button" class="button" id="coc-redx-cancel-btn"
                            data-tracking-id="<?php echo esc_attr( $tracking_id ); ?>">
                        Cancel Parcel
                    </button>
                </div>

                <div id="coc-redx-msg" class="coc-sf-msg" style="display:none;"></div>

            <?php else : ?>

                <form class="coc-redx-form" id="coc-redx-form" onsubmit="return false;">

                    <div class="coc-sf-row coc-sf-row--2">
                        <div>
                            <label class="coc-sf-label">Pickup Store <span class="required">*</span></label>
                            <select id="coc-redx-store" class="widefat">
                                <option value="">— Loading stores… —</option>
                            </select>
                        </div>
                        <div>
                            <label class="coc-sf-label">Invoice / Order # <span class="required">*</span></label>
                            <input type="text" id="coc-redx-invoice" class="widefat"
                                   value="<?php echo esc_attr( $default_invoice ); ?>" />
                        </div>
                    </div>

                    <div class="coc-sf-row coc-sf-row--2">
                        <div>
                            <label class="coc-sf-label">Customer Name <span class="required">*</span></label>
                            <input type="text" id="coc-redx-name" class="widefat"
                                   value="<?php echo esc_attr( $default_name ); ?>" />
                        </div>
                        <div>
                            <label class="coc-sf-label">Phone <span class="required">*</span></label>
                            <input type="text" id="coc-redx-phone" class="widefat"
                                   value="<?php echo esc_attr( $default_phone ); ?>" maxlength="14" />
                        </div>
                    </div>

                    <div class="coc-sf-row">
                        <label class="coc-sf-label">Delivery Address <span class="required">*</span></label>
                        <textarea id="coc-redx-address" class="widefat" rows="2"><?php echo esc_textarea( $default_address ); ?></textarea>
                    </div>

                    <div class="coc-sf-row">
                        <label class="coc-sf-label">Delivery Area <span class="required">*</span></label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="text" id="coc-redx-area-search" class="widefat"
                                   placeholder="Type district name (e.g. Dhaka)" />
                            <button type="button" class="button" id="coc-redx-area-search-btn"
                                    style="white-space:nowrap;">Search</button>
                        </div>
                        <select id="coc-redx-area-select" class="widefat" style="margin-top:4px;display:none;">
                            <option value="">— Select area —</option>
                        </select>
                        <input type="hidden" id="coc-redx-area-id" value="" />
                        <input type="hidden" id="coc-redx-area-name" value="" />
                    </div>

                    <div class="coc-sf-row coc-sf-row--2">
                        <div>
                            <label class="coc-sf-label">COD Amount (৳) <span class="required">*</span></label>
                            <input type="number" id="coc-redx-cod" class="widefat"
                                   value="<?php echo esc_attr( $default_cod ); ?>" min="0" step="1" />
                        </div>
                        <div>
                            <label class="coc-sf-label">Weight (kg)</label>
                            <input type="number" id="coc-redx-weight" class="widefat"
                                   value="0.5" min="0.1" step="0.1" />
                        </div>
                    </div>

                    <div class="coc-sf-row">
                        <label class="coc-sf-label">Special Instruction</label>
                        <input type="text" id="coc-redx-instruction" class="widefat"
                               placeholder="Optional delivery note" />
                    </div>

                    <div class="coc-sf-actions-row">
                        <button type="button" class="button" id="coc-redx-charge-btn">
                            Calculate Charge
                        </button>
                        <span id="coc-redx-charge-result" style="margin-left:8px;font-weight:600;"></span>
                    </div>

                    <div class="coc-sf-actions-row">
                        <button type="button" class="button button-primary" id="coc-redx-submit-btn">
                            Create RedX Parcel
                        </button>
                    </div>

                    <div id="coc-redx-msg" class="coc-sf-msg" style="display:none;"></div>

                </form>

            <?php endif; ?>

        </div>
        <?php
    }
}
