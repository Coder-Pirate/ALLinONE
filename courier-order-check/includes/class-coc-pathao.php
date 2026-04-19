<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pathao Courier Merchant API integration.
 *
 * Handles OAuth 2.0 token lifecycle, all API calls, AJAX actions,
 * and renders the Pathao order panel on WooCommerce order edit pages.
 *
 * WP options used:
 *   coc_pathao_env             sandbox|production
 *   coc_pathao_client_id
 *   coc_pathao_client_secret
 *   coc_pathao_username
 *   coc_pathao_password
 *   coc_pathao_store_id        default store
 *   coc_pathao_access_token
 *   coc_pathao_refresh_token
 *   coc_pathao_token_expires   unix timestamp
 *
 * Order meta used:
 *   _coc_pathao_consignment_id
 *   _coc_pathao_merchant_order_id
 *   _coc_pathao_order_status
 *   _coc_pathao_delivery_fee
 */
class COC_Pathao {

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        // Admin assets (for order edit page).
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_order_assets' ] );

        // AJAX — all admin-only (no _nopriv needed).
        $ajax_actions = [
            'coc_pathao_connect',
            'coc_pathao_get_cities',
            'coc_pathao_get_zones',
            'coc_pathao_get_areas',
            'coc_pathao_get_stores',
            'coc_pathao_price_plan',
            'coc_pathao_create_order',
            'coc_pathao_get_order_info',
        ];
        foreach ( $ajax_actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, 'ajax_' . $action ] );
        }
    }

    /* ------------------------------------------------------------------
     * API base & headers
     * ------------------------------------------------------------------ */

    private static function base_url() {
        return get_option( 'coc_pathao_env', 'sandbox' ) === 'production'
            ? 'https://api-hermes.pathao.com'
            : 'https://courier-api-sandbox.pathao.com';
    }

    private static function json_headers( $with_auth = false ) {
        $h = [ 'Content-Type' => 'application/json; charset=UTF-8' ];
        if ( $with_auth ) {
            $token = self::get_valid_token();
            if ( $token ) {
                $h['Authorization'] = 'Bearer ' . $token;
            }
        }
        return $h;
    }

    private static function api_get( $endpoint ) {
        $resp = wp_remote_get(
            self::base_url() . $endpoint,
            [ 'headers' => self::json_headers( true ), 'timeout' => 15 ]
        );
        return self::parse_response( $resp );
    }

    private static function api_post( $endpoint, array $body, $with_auth = true ) {
        $resp = wp_remote_post(
            self::base_url() . $endpoint,
            [
                'headers'     => self::json_headers( $with_auth ),
                'body'        => wp_json_encode( $body ),
                'timeout'     => 15,
                'data_format' => 'body',
            ]
        );
        return self::parse_response( $resp );
    }

    private static function parse_response( $resp ) {
        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'message' => $resp->get_error_message(), 'data' => null ];
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        $ok   = in_array( (int) $code, [ 200, 201, 202 ], true );
        $msg  = isset( $body['message'] ) ? $body['message'] : ( $ok ? 'OK' : 'Error ' . $code );
        return [ 'ok' => $ok, 'message' => $msg, 'data' => $body['data'] ?? null, 'raw' => $body ];
    }

    /* ------------------------------------------------------------------
     * Token management
     * ------------------------------------------------------------------ */

    /**
     * Returns a valid access token, refreshing automatically if expired.
     * Returns empty string if not configured.
     */
    public static function get_valid_token() {
        $token   = get_option( 'coc_pathao_access_token', '' );
        $expires = (int) get_option( 'coc_pathao_token_expires', 0 );
        $refresh = get_option( 'coc_pathao_refresh_token', '' );

        if ( empty( $token ) ) {
            return '';
        }

        // Refresh if within 5 min of expiry.
        if ( $expires > 0 && time() > ( $expires - 300 ) && $refresh ) {
            $result = self::issue_token_from_refresh( $refresh );
            if ( $result['ok'] ) {
                return get_option( 'coc_pathao_access_token', '' );
            }
            // Refresh failed — try password again.
            self::issue_token_from_password();
            return get_option( 'coc_pathao_access_token', '' );
        }

        return $token;
    }

    /**
     * Issue token using explicitly-supplied credentials.
     * Falls back to saved options for any empty parameter.
     * Used by the admin "Save & Connect" button so users don't have to
     * save settings before testing.
     */
    public static function issue_token_from_credentials( $client_id, $client_secret, $username, $password, $env = '' ) {
        // Use the env passed from the form; fall back to saved option.
        $effective_env = ( $env === 'production' || $env === 'sandbox' ) ? $env : get_option( 'coc_pathao_env', 'sandbox' );
        $base = $effective_env === 'production'
            ? 'https://api-hermes.pathao.com'
            : 'https://courier-api-sandbox.pathao.com';

        $resp = wp_remote_post(
            $base . '/aladdin/api/v1/issue-token',
            [
                'headers'     => [ 'Content-Type' => 'application/json; charset=UTF-8' ],
                'body'        => wp_json_encode( [
                    'client_id'     => $client_id     ?: get_option( 'coc_pathao_client_id',     '' ),
                    'client_secret' => $client_secret ?: get_option( 'coc_pathao_client_secret', '' ),
                    'grant_type'    => 'password',
                    'username'      => $username      ?: get_option( 'coc_pathao_username',       '' ),
                    'password'      => $password      ?: get_option( 'coc_pathao_password',       '' ),
                ] ),
                'timeout'     => 15,
                'data_format' => 'body',
            ]
        );
        $result = self::parse_response( $resp );

        if ( $result['ok'] && ! empty( $result['raw']['access_token'] ) ) {
            self::save_token( $result['raw'] );
            // Also persist the env that actually worked.
            update_option( 'coc_pathao_env', $effective_env );
        }
        return $result;
    }

    /**
     * Issue token using username + password credentials.
     */
    public static function issue_token_from_password() {
        $result = self::api_post( '/aladdin/api/v1/issue-token', [
            'client_id'     => get_option( 'coc_pathao_client_id',     '' ),
            'client_secret' => get_option( 'coc_pathao_client_secret', '' ),
            'grant_type'    => 'password',
            'username'      => get_option( 'coc_pathao_username',       '' ),
            'password'      => get_option( 'coc_pathao_password',       '' ),
        ], false );

        if ( $result['ok'] && ! empty( $result['raw']['access_token'] ) ) {
            self::save_token( $result['raw'] );
        }
        return $result;
    }

    /**
     * Refresh access token using the stored refresh token.
     */
    private static function issue_token_from_refresh( $refresh_token ) {
        $result = self::api_post( '/aladdin/api/v1/issue-token', [
            'client_id'     => get_option( 'coc_pathao_client_id',     '' ),
            'client_secret' => get_option( 'coc_pathao_client_secret', '' ),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ], false );

        if ( $result['ok'] && ! empty( $result['raw']['access_token'] ) ) {
            self::save_token( $result['raw'] );
        }
        return $result;
    }

    private static function save_token( array $raw ) {
        update_option( 'coc_pathao_access_token',  sanitize_text_field( $raw['access_token']  ?? '' ) );
        update_option( 'coc_pathao_refresh_token', sanitize_text_field( $raw['refresh_token'] ?? '' ) );
        $expires_in = absint( $raw['expires_in'] ?? 432000 );
        update_option( 'coc_pathao_token_expires', time() + $expires_in );
    }

    public static function is_connected() {
        return ! empty( get_option( 'coc_pathao_access_token', '' ) );
    }

    /* ------------------------------------------------------------------
     * API calls
     * ------------------------------------------------------------------ */

    public static function get_cities() {
        return self::api_get( '/aladdin/api/v1/city-list' );
    }

    public static function get_zones( $city_id ) {
        return self::api_get( '/aladdin/api/v1/cities/' . absint( $city_id ) . '/zone-list' );
    }

    public static function get_areas( $zone_id ) {
        return self::api_get( '/aladdin/api/v1/zones/' . absint( $zone_id ) . '/area-list' );
    }

    public static function get_stores() {
        return self::api_get( '/aladdin/api/v1/stores' );
    }

    public static function get_order_info( $consignment_id ) {
        return self::api_get( '/aladdin/api/v1/orders/' . rawurlencode( $consignment_id ) . '/info' );
    }

    public static function price_plan( array $data ) {
        return self::api_post( '/aladdin/api/v1/merchant/price-plan', $data );
    }

    public static function create_order( array $data ) {
        return self::api_post( '/aladdin/api/v1/orders', $data );
    }

    /* ------------------------------------------------------------------
     * AJAX handlers
     * ------------------------------------------------------------------ */

    private static function verify_ajax_nonce() {
        if ( ! check_ajax_referer( 'coc_pathao', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }
    }

    public static function ajax_coc_pathao_connect() {
        self::verify_ajax_nonce();
        $result = self::issue_token_from_password();
        if ( $result['ok'] ) {
            wp_send_json_success( [ 'message' => 'Connected to Pathao successfully.' ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ?: 'Connection failed.' ] );
        }
    }

    public static function ajax_coc_pathao_get_cities() {
        self::verify_ajax_nonce();
        $r = self::get_cities();
        if ( $r['ok'] ) {
            wp_send_json_success( $r['data']['data'] ?? $r['data'] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    public static function ajax_coc_pathao_get_zones() {
        self::verify_ajax_nonce();
        $city_id = absint( $_POST['city_id'] ?? 0 );
        if ( ! $city_id ) {
            wp_send_json_error( [ 'message' => 'city_id required.' ] );
        }
        $r = self::get_zones( $city_id );
        if ( $r['ok'] ) {
            wp_send_json_success( $r['data']['data'] ?? $r['data'] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    public static function ajax_coc_pathao_get_areas() {
        self::verify_ajax_nonce();
        $zone_id = absint( $_POST['zone_id'] ?? 0 );
        if ( ! $zone_id ) {
            wp_send_json_error( [ 'message' => 'zone_id required.' ] );
        }
        $r = self::get_areas( $zone_id );
        if ( $r['ok'] ) {
            wp_send_json_success( $r['data']['data'] ?? $r['data'] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    public static function ajax_coc_pathao_get_stores() {
        self::verify_ajax_nonce();
        $r = self::get_stores();
        if ( $r['ok'] ) {
            wp_send_json_success( $r['data']['data'] ?? $r['data'] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    public static function ajax_coc_pathao_price_plan() {
        self::verify_ajax_nonce();

        $data = [
            'store_id'       => absint( $_POST['store_id']       ?? 0 ),
            'item_type'      => absint( $_POST['item_type']      ?? 2 ),
            'delivery_type'  => absint( $_POST['delivery_type']  ?? 48 ),
            'item_weight'    => (float) ( $_POST['item_weight']   ?? 0.5 ),
        ];

        if ( ! $data['store_id'] ) {
            wp_send_json_error( [ 'message' => 'store_id is required.' ] );
        }

        $r = self::price_plan( $data );
        if ( $r['ok'] ) {
            wp_send_json_success( $r['data'] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    public static function ajax_coc_pathao_create_order() {
        self::verify_ajax_nonce();

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Invalid WooCommerce order.' ] );
        }

        // Build payload.
        $data = [
            'store_id'              => absint( $_POST['store_id']              ?? 0 ),
            'merchant_order_id'     => sanitize_text_field( $_POST['merchant_order_id'] ?? '' ),
            'recipient_name'        => sanitize_text_field( $_POST['recipient_name']        ?? '' ),
            'recipient_phone'       => sanitize_text_field( $_POST['recipient_phone']       ?? '' ),
            'recipient_address'     => sanitize_text_field( $_POST['recipient_address']     ?? '' ),
            'delivery_type'         => absint( $_POST['delivery_type']  ?? 48 ),
            'item_type'             => absint( $_POST['item_type']       ?? 2 ),
            'item_quantity'         => absint( $_POST['item_quantity']   ?? 1 ),
            'item_weight'           => (float) ( $_POST['item_weight']   ?? 0.5 ),
            'amount_to_collect'     => absint( $_POST['amount_to_collect'] ?? 0 ),
        ];

        // Optional fields — only include if non-empty.
        foreach ( [ 'recipient_city', 'recipient_zone', 'recipient_area' ] as $f ) {
            $v = absint( $_POST[ $f ] ?? 0 );
            if ( $v ) {
                $data[ $f ] = $v;
            }
        }

        $instruction = sanitize_text_field( $_POST['special_instruction'] ?? '' );
        if ( $instruction ) {
            $data['special_instruction'] = $instruction;
        }

        $desc = sanitize_text_field( $_POST['item_description'] ?? '' );
        if ( $desc ) {
            $data['item_description'] = $desc;
        }

        if ( ! $data['store_id'] || ! $data['recipient_name'] || ! $data['recipient_phone'] || ! $data['recipient_address'] ) {
            wp_send_json_error( [ 'message' => 'store_id, recipient_name, recipient_phone and recipient_address are required.' ] );
        }

        $r = self::create_order( $data );
        if ( $r['ok'] && isset( $r['data']['consignment_id'] ) ) {
            // Persist on the WC order.
            $order->update_meta_data( '_coc_pathao_consignment_id',      sanitize_text_field( $r['data']['consignment_id'] ) );
            $order->update_meta_data( '_coc_pathao_merchant_order_id',   sanitize_text_field( $r['data']['merchant_order_id'] ?? '' ) );
            $order->update_meta_data( '_coc_pathao_order_status',        sanitize_text_field( $r['data']['order_status'] ?? 'Pending' ) );
            $order->update_meta_data( '_coc_pathao_delivery_fee',        sanitize_text_field( (string) ( $r['data']['delivery_fee'] ?? '' ) ) );
            $order->save();

            wp_send_json_success( [
                'message'          => $r['message'],
                'consignment_id'   => $r['data']['consignment_id'],
                'order_status'     => $r['data']['order_status'] ?? 'Pending',
                'delivery_fee'     => $r['data']['delivery_fee']  ?? '',
            ] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ?: 'Order creation failed.' ] );
        }
    }

    public static function ajax_coc_pathao_get_order_info() {
        self::verify_ajax_nonce();

        $consignment_id = sanitize_text_field( $_POST['consignment_id'] ?? '' );
        if ( ! $consignment_id ) {
            wp_send_json_error( [ 'message' => 'consignment_id required.' ] );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $r        = self::get_order_info( $consignment_id );

        if ( $r['ok'] ) {
            // Update stored status.
            if ( $order_id && isset( $r['data']['order_status_slug'] ) ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $order->update_meta_data( '_coc_pathao_order_status', sanitize_text_field( $r['data']['order_status_slug'] ) );
                    $order->save();
                }
            }
            wp_send_json_success( $r['data'] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    /* ------------------------------------------------------------------
     * Order panel — HTML
     * ------------------------------------------------------------------ */

    public static function render_order_panel( $order ) {
        if ( ! $order instanceof WC_Abstract_Order ) {
            if ( $order instanceof WP_Post ) {
                $order = wc_get_order( $order->ID );
            }
            if ( ! $order ) {
                return;
            }
        }

        $connected      = self::is_connected();
        $consignment_id = (string) $order->get_meta( '_coc_pathao_consignment_id' );
        $pathao_status  = (string) $order->get_meta( '_coc_pathao_order_status' );
        $delivery_fee   = (string) $order->get_meta( '_coc_pathao_delivery_fee' );
        $has_order      = ! empty( $consignment_id );

        // Pre-fill from WC order.
        $billing_name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $billing_phone   = $order->get_billing_phone();
        $billing_address = trim(
            $order->get_billing_address_1() . ' ' .
            $order->get_billing_address_2() . ', ' .
            $order->get_billing_city()
        );
        $cod_amount  = absint( $order->get_total() );
        $default_store = get_option( 'coc_pathao_store_id', '' );
        $nonce         = wp_create_nonce( 'coc_pathao' );

        ?>
        <div class="coc-courier-panel coc-pathao-wrapper" id="coc-pathao-panel"
             data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-default-store="<?php echo esc_attr( $default_store ); ?>">

            <div class="coc-courier-title coc-pathao-title-bar">
                🚚 <?php esc_html_e( 'Pathao Courier', 'courier-order-check' ); ?>
                <?php if ( $has_order ) : ?>
                    <span class="coc-courier-badge"><?php esc_html_e( 'Order Sent', 'courier-order-check' ); ?></span>
                <?php endif; ?>
            </div>

            <?php if ( ! $connected ) : ?>
                <p class="coc-warning">
                    <?php echo wp_kses(
                        sprintf(
                            __( 'Pathao not connected. <a href="%s">Configure credentials</a>.', 'courier-order-check' ),
                            esc_url( admin_url( 'admin.php?page=courier-order-check' ) )
                        ),
                        [ 'a' => [ 'href' => [] ] ]
                    ); ?>
                </p>
            <?php elseif ( $has_order ) : ?>

                <!-- Already sent — show info + status refresh -->
                <div class="coc-pathao-info-grid">
                    <div class="coc-pathao-info-row">
                        <span class="coc-pathao-label"><?php esc_html_e( 'Consignment ID', 'courier-order-check' ); ?></span>
                        <span class="coc-pathao-value"><code><?php echo esc_html( $consignment_id ); ?></code></span>
                    </div>
                    <div class="coc-pathao-info-row">
                        <span class="coc-pathao-label"><?php esc_html_e( 'Status', 'courier-order-check' ); ?></span>
                        <span class="coc-pathao-value coc-pathao-status" id="coc-pathao-order-status">
                            <?php echo esc_html( $pathao_status ?: '—' ); ?>
                        </span>
                    </div>
                    <?php if ( $delivery_fee ) : ?>
                    <div class="coc-pathao-info-row">
                        <span class="coc-pathao-label"><?php esc_html_e( 'Delivery Fee', 'courier-order-check' ); ?></span>
                        <span class="coc-pathao-value">৳ <?php echo esc_html( $delivery_fee ); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="coc-pathao-actions">
                    <button type="button" class="button" id="coc-pathao-refresh-btn"
                            data-consignment="<?php echo esc_attr( $consignment_id ); ?>">
                        ↻ <?php esc_html_e( 'Refresh Status', 'courier-order-check' ); ?>
                    </button>
                </div>
                <div id="coc-pathao-msg" class="coc-pathao-msg" style="display:none;"></div>

            <?php else : ?>

                <!-- Create order form -->
                <div id="coc-pathao-msg" class="coc-pathao-msg" style="display:none;"></div>

                <div class="coc-pathao-form">
                    <!-- Row 1: Store -->
                    <div class="coc-pathao-row">
                        <label for="coc-pathao-store"><?php esc_html_e( 'Store', 'courier-order-check' ); ?> <span class="required">*</span></label>
                        <select id="coc-pathao-store" name="coc_pathao_store">
                            <option value=""><?php esc_html_e( '— Loading stores…', 'courier-order-check' ); ?></option>
                        </select>
                    </div>

                    <!-- Row 2: Recipient info -->
                    <div class="coc-pathao-row coc-pathao-row--3">
                        <div>
                            <label for="coc-pathao-recipient-name"><?php esc_html_e( 'Recipient Name', 'courier-order-check' ); ?> <span class="required">*</span></label>
                            <input type="text" id="coc-pathao-recipient-name" value="<?php echo esc_attr( $billing_name ); ?>" />
                        </div>
                        <div>
                            <label for="coc-pathao-recipient-phone"><?php esc_html_e( 'Phone', 'courier-order-check' ); ?> <span class="required">*</span></label>
                            <input type="text" id="coc-pathao-recipient-phone" value="<?php echo esc_attr( $billing_phone ); ?>" maxlength="11" />
                        </div>
                        <div>
                            <label for="coc-pathao-cod"><?php esc_html_e( 'COD Amount (৳)', 'courier-order-check' ); ?> <span class="required">*</span></label>
                            <input type="number" id="coc-pathao-cod" value="<?php echo esc_attr( $cod_amount ); ?>" min="0" />
                        </div>
                    </div>

                    <!-- Row 3: Address -->
                    <div class="coc-pathao-row">
                        <label for="coc-pathao-recipient-address"><?php esc_html_e( 'Recipient Address', 'courier-order-check' ); ?> <span class="required">*</span></label>
                        <input type="text" id="coc-pathao-recipient-address" value="<?php echo esc_attr( $billing_address ); ?>" />
                    </div>



                    <!-- Row 5: Delivery type, Item type, Weight, Quantity -->
                    <div class="coc-pathao-row coc-pathao-row--4">
                        <div>
                            <label for="coc-pathao-delivery-type"><?php esc_html_e( 'Delivery Type', 'courier-order-check' ); ?></label>
                            <select id="coc-pathao-delivery-type">
                                <option value="48"><?php esc_html_e( 'Normal (48h)', 'courier-order-check' ); ?></option>
                                <option value="12"><?php esc_html_e( 'On Demand (12h)', 'courier-order-check' ); ?></option>
                            </select>
                        </div>
                        <div>
                            <label for="coc-pathao-item-type"><?php esc_html_e( 'Item Type', 'courier-order-check' ); ?></label>
                            <select id="coc-pathao-item-type">
                                <option value="2"><?php esc_html_e( 'Parcel', 'courier-order-check' ); ?></option>
                                <option value="1"><?php esc_html_e( 'Document', 'courier-order-check' ); ?></option>
                            </select>
                        </div>
                        <div>
                            <label for="coc-pathao-weight"><?php esc_html_e( 'Weight (kg)', 'courier-order-check' ); ?></label>
                            <input type="number" id="coc-pathao-weight" value="0.5" min="0.5" max="10" step="0.1" />
                        </div>
                        <div>
                            <label for="coc-pathao-qty"><?php esc_html_e( 'Quantity', 'courier-order-check' ); ?></label>
                            <input type="number" id="coc-pathao-qty" value="1" min="1" />
                        </div>
                    </div>

                    <!-- Row 6: Special instruction + item description -->
                    <div class="coc-pathao-row coc-pathao-row--2">
                        <div>
                            <label for="coc-pathao-instruction"><?php esc_html_e( 'Special Instruction', 'courier-order-check' ); ?></label>
                            <input type="text" id="coc-pathao-instruction" placeholder="<?php esc_attr_e( 'Optional', 'courier-order-check' ); ?>" />
                        </div>
                        <div>
                            <label for="coc-pathao-description"><?php esc_html_e( 'Item Description', 'courier-order-check' ); ?></label>
                            <input type="text" id="coc-pathao-description" placeholder="<?php esc_attr_e( 'Optional', 'courier-order-check' ); ?>" />
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="coc-pathao-row coc-pathao-actions-row">
                        <div class="coc-pathao-btns">
                            <button type="button" class="button button-primary" id="coc-pathao-submit-btn">
                                🚀 <?php esc_html_e( 'Create Pathao Order', 'courier-order-check' ); ?>
                            </button>
                        </div>
                    </div>
                </div><!-- /.coc-pathao-form -->

            <?php endif; ?>

        </div><!-- /.coc-pathao-wrapper -->
        <?php
    }

    /* ------------------------------------------------------------------
     * Enqueue order-page assets
     * ------------------------------------------------------------------ */

    public static function enqueue_order_assets( $hook ) {
        $allowed_hooks = [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) {
            return;
        }
        if ( ! self::is_connected() ) {
            return;
        }

        $js_file = COC_PLUGIN_DIR . 'assets/js/pathao.js';
        wp_enqueue_script(
            'coc-pathao',
            COC_PLUGIN_URL . 'assets/js/pathao.js',
            [ 'jquery' ],
            file_exists( $js_file ) ? filemtime( $js_file ) : COC_VERSION,
            true
        );
        // No wp_localize_script needed; all data is embedded in the HTML panel via data attributes.
    }
}
