<?php
defined( 'ABSPATH' ) || exit;

/**
 * TikTok Pixel (browser) + TikTok Events API (server-side, own server).
 *
 * Events:
 *   PageView, ViewContent, AddToCart, InitiateCheckout,
 *   AddPaymentInfo, PlaceAnOrder (= Purchase)
 *
 * Each event fires from both the browser pixel and your server via
 * the Events API with matching event_ids for deduplication.
 */
class COC_TikTok_Pixel {

    /** event_id for last AJAX add-to-cart — returned in WC fragment. */
    private static $last_atc_eid = '';

    /** PageView event_id — generated at head injection, consumed in fire_page_events for dedup. */
    private static $page_view_eid = '';

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        if ( empty( trim( get_option( 'coc_ttk_pixel_id', '' ) ) ) ) {
            return;
        }

        add_action( 'wp_head',     [ __CLASS__, 'inject_head' ], 1 );

        // Non-AJAX add-to-cart: send Events API + queue browser event.
        add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'hook_add_to_cart' ], 10, 6 );

        // Purchase hook: fires at checkout OR when admin marks order Completed.
        if ( get_option( 'coc_purchase_on_complete', '' ) ) {
            add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'hook_purchase_on_complete' ], 10, 2 );
        } else {
            add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'hook_purchase' ], 10, 3 );
        }

        // AJAX add-to-cart: pass event_id to browser via WC fragment.
        add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'atc_fragment' ] );

        // Page-level server + browser events.
        add_action( 'wp_footer', [ __CLASS__, 'flush_browser_queue' ], 2 );
        add_action( 'wp_footer', [ __CLASS__, 'fire_page_events' ],    5 );

        // Client-side script (AJAX add-to-cart, AddPaymentInfo).
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
    }

    /* ------------------------------------------------------------------
     * Base pixel snippet
     * ------------------------------------------------------------------ */

    public static function inject_head() {
        $pid = esc_js( trim( get_option( 'coc_ttk_pixel_id', '' ) ) );
        // Generate PageView event_id now so browser and Events API share the same id.
        self::$page_view_eid = self::make_eid( 'pv_' );
        // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
        ?>
<!-- TikTok Pixel Code -->
<script>
!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};n=document.createElement("script");n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)};
ttq.load('<?php echo $pid; ?>');
}(window,document,'ttq');
</script>
<!-- End TikTok Pixel Code -->
        <?php
        // phpcs:enable
    }

    /* ------------------------------------------------------------------
     * Inline browser ttq.track() with event_id (for deduplication)
     * ------------------------------------------------------------------ */

    private static function browser_event( $event_name, array $props, $eid ) {
        echo '<script>if(typeof ttq!=="undefined"){ttq.track(' .
             wp_json_encode( $event_name ) . ',' .
             wp_json_encode( $props ) . ',{event_id:' .
             wp_json_encode( $eid ) . '});}</script>' . "\n";
    }

    /* ------------------------------------------------------------------
     * Page-level events  (wp_footer, priority 5)
     * ------------------------------------------------------------------ */

    public static function fire_page_events() {
        // PageView — same event_id in browser (ttq.track) and Events API (deduplication).
        $pv_eid = self::$page_view_eid ?: self::make_eid( 'pv_' );
        self::send_events_api( 'PageView', [], $pv_eid );
        self::browser_event( 'PageView', [], $pv_eid );

        if ( is_product() ) {
            self::event_view_content();
        }

        if ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) {
            self::event_view_category();
        }

        if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
            self::event_initiate_checkout();
        }

        if ( is_wc_endpoint_url( 'order-received' ) ) {
            self::event_purchase_browser();
        }
    }

    private static function event_view_content() {
        global $post;
        $product = wc_get_product( $post->ID );
        if ( ! $product ) {
            return;
        }
        $eid   = self::make_eid( 'vc_' );
        $props = self::product_props( $product, 1 );

        self::send_events_api( 'ViewContent', $props, $eid );
        self::browser_event( 'ViewContent', $props, $eid );
    }

    private static function event_view_category() {
        $name  = is_shop() ? (string) get_option( 'woocommerce_shop_page_title', 'Shop' ) : (string) single_term_title( '', false );
        $eid   = self::make_eid( 'vcat_' );
        $props = [ 'content_type' => 'product_group', 'description' => $name ];

        self::send_events_api( 'ViewContent', $props, $eid );
        self::browser_event( 'ViewContent', $props, $eid );
    }

    private static function event_initiate_checkout() {
        if ( ! WC()->cart ) {
            return;
        }
        $eid   = self::make_eid( 'ic_' );
        $props = self::cart_props();

        self::send_events_api( 'InitiateCheckout', $props, $eid );
        self::browser_event( 'InitiateCheckout', $props, $eid );
    }

    /** Browser-only Purchase fire on thank-you page using event_id saved at order creation. */
    private static function event_purchase_browser() {
        $order_id = isset( $GLOBALS['wp']->query_vars['order-received'] )
            ? absint( $GLOBALS['wp']->query_vars['order-received'] )
            : 0;
        if ( ! $order_id ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $eid = (string) $order->get_meta( '_coc_ttk_purchase_eid' );
        if ( ! $eid ) {
            return;
        }
        $props = self::order_props( $order );
        self::browser_event( 'PlaceAnOrder', $props, $eid );
    }

    /* ------------------------------------------------------------------
     * WooCommerce hooks
     * ------------------------------------------------------------------ */

    public static function hook_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        $product = wc_get_product( $variation_id ?: $product_id );
        if ( ! $product ) {
            return;
        }

        $eid   = self::make_eid( 'atc_' );
        $props = self::product_props( $product, (int) $quantity );

        self::send_events_api( 'AddToCart', $props, $eid );

        if ( wp_doing_ajax() ) {
            self::$last_atc_eid = $eid;
        } else {
            if ( WC()->session ) {
                $q   = WC()->session->get( 'coc_ttk_q', [] );
                $q[] = [ 'event' => 'AddToCart', 'props' => $props, 'eid' => $eid ];
                WC()->session->set( 'coc_ttk_q', $q );
            }
        }
    }

    public static function atc_fragment( $fragments ) {
        if ( self::$last_atc_eid ) {
            $fragments['div#coc-ttk-atc-eid'] =
                '<div id="coc-ttk-atc-eid" style="display:none" data-eid="' .
                esc_attr( self::$last_atc_eid ) . '"></div>';
            self::$last_atc_eid = '';
        }
        return $fragments;
    }

    public static function flush_browser_queue() {
        if ( ! WC()->session ) {
            return;
        }
        $q = WC()->session->get( 'coc_ttk_q', [] );
        if ( empty( $q ) ) {
            return;
        }
        WC()->session->set( 'coc_ttk_q', [] );
        foreach ( $q as $ev ) {
            self::browser_event( $ev['event'], $ev['props'], $ev['eid'] );
        }
    }

    public static function hook_purchase_on_complete( $order_id, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        if ( $order->get_meta( '_coc_ttk_purchase_eid' ) ) {
            return; // idempotency guard.
        }

        $eid = self::make_eid( 'pur_' );
        $order->update_meta_data( '_coc_ttk_purchase_eid', $eid );
        $order->save();

        self::send_events_api( 'PlaceAnOrder', self::order_props( $order ), $eid, $order );
    }

    public static function hook_purchase( $order_id, $posted_data, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        if ( $order->get_meta( '_coc_ttk_purchase_eid' ) ) {
            return; // idempotency guard.
        }

        $eid = self::make_eid( 'pur_' );
        $order->update_meta_data( '_coc_ttk_purchase_eid', $eid );
        self::save_attribution_to_order( $order );
        $order->save();

        self::send_events_api( 'PlaceAnOrder', self::order_props( $order ), $eid, $order );
    }

    /* ------------------------------------------------------------------
     * Frontend script (AJAX add-to-cart, AddPaymentInfo)
     * ------------------------------------------------------------------ */

    public static function enqueue_scripts() {
        $is_wc = is_shop() || is_product_category() || is_product_tag() ||
                 is_product_taxonomy() || is_product() || is_cart() || is_checkout();
        if ( ! $is_wc ) {
            return;
        }

        $js_file = COC_PLUGIN_DIR . 'assets/js/tiktok.js';
        wp_enqueue_script(
            'coc-tiktok',
            COC_PLUGIN_URL . 'assets/js/tiktok.js',
            [ 'jquery' ],
            file_exists( $js_file ) ? filemtime( $js_file ) : COC_VERSION,
            true
        );

        $products = [];
        if ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) {
            global $wp_query;
            foreach ( $wp_query->posts as $p ) {
                $product = wc_get_product( $p->ID );
                if ( $product ) {
                    $products[ $product->get_id() ] = self::product_props( $product, 1 );
                }
            }
        }
        if ( is_product() ) {
            global $post;
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $products[ $product->get_id() ] = self::product_props( $product, 1 );
            }
        }

        $cart_items = [];
        if ( ( is_cart() || is_checkout() ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $key => $ci ) {
                $cart_items[ $key ] = self::product_props( $ci['data'], (int) $ci['quantity'] );
            }
        }

        wp_localize_script( 'coc-tiktok', 'COC_TTK', [
            'currency'   => get_woocommerce_currency(),
            'products'   => $products,
            'cart_items' => $cart_items,
        ] );
    }

    /* ------------------------------------------------------------------
     * TikTok Events API (server-side, your server → TikTok)
     * ------------------------------------------------------------------ */

    private static function send_events_api( $event_name, array $properties, $eid, $order = null ) {
        $pixel_id = trim( get_option( 'coc_ttk_pixel_id', '' ) );
        $token    = trim( get_option( 'coc_ttk_access_token', '' ) );
        if ( empty( $pixel_id ) || empty( $token ) ) {
            return;
        }

        // In admin context use the store home URL, not the wp-admin URL.
        if ( is_admin() ) {
            $page_url = $order instanceof WC_Order
                ? $order->get_checkout_order_received_url()
                : home_url( '/' );
            $referrer = home_url( '/' );
        } else {
            $page_url = ( is_ssl() ? 'https' : 'http' ) . '://' .
                        sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) .
                        sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
            $referrer = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) );
        }

        $user = $order instanceof WC_Order
            ? self::user_data_from_order( $order )
            : self::current_user_data();

        $event = [
            'event'      => $event_name,
            'event_time' => time(),
            'event_id'   => $eid,
            'page'       => array_filter( [ 'url' => $page_url, 'referrer' => $referrer ] ),
            'user'       => $user,
        ];

        if ( ! empty( $properties ) ) {
            $event['properties'] = $properties;
        }

        $body = [
            'pixel_code'   => $pixel_id,
            'event_source' => 'web',
            'data'         => [ $event ],
        ];

        $test_code = trim( get_option( 'coc_ttk_test_code', '' ) );
        if ( $test_code ) {
            $body['test_event_code'] = $test_code;
        }

        wp_remote_post(
            'https://business-api.tiktok.com/open_api/v1.3/event/track/',
            [
                'headers'     => [
                    'Access-Token' => $token,
                    'Content-Type' => 'application/json',
                ],
                'body'        => wp_json_encode( $body ),
                'timeout'     => 10,
                'blocking'    => is_admin(),
                'data_format' => 'body',
            ]
        );
    }

    /* ------------------------------------------------------------------
     * User data helpers (SHA-256 hashed PII)
     * ------------------------------------------------------------------ */

    private static function current_user_data() {
        $ud = [];

        // Real client IP.
        $ip = '';
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $ip    = sanitize_text_field( trim( $parts[0] ) );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            $ud['ip'] = $ip; // TikTok sends IP unhashed.
        }

        if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $ud['user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
        }

        // TikTok click ID cookie.
        if ( isset( $_COOKIE['_ttp'] ) ) {
            $ud['ttp'] = sanitize_text_field( wp_unslash( $_COOKIE['_ttp'] ) );
        }
        // TikTok click ID: cookie first, then URL param (first-click before JS writes cookie).
        if ( isset( $_COOKIE['ttclid'] ) ) {
            $ud['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        } elseif ( ! empty( $_GET['ttclid'] ) ) {
            $ud['ttclid'] = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
        }

        // Logged-in user.
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) {
                $ud['email']       = self::sha256( $user->user_email );
                $ud['external_id'] = self::sha256( (string) $user->ID );
            }
        }

        return $ud;
    }

    private static function user_data_from_order( WC_Order $order ) {
        $ud = self::current_user_data();

        if ( $order->get_billing_email() ) {
            $ud['email'] = self::sha256( $order->get_billing_email() );
        }

        // Phone normalized to E.164 format before hashing (improves event match quality).
        $phone = self::normalize_phone( $order->get_billing_phone(), $order->get_billing_country() );
        if ( $phone ) {
            $ud['phone_number'] = self::sha256( $phone );
        }

        // external_id for registered customers improves cross-device match quality.
        if ( $order->get_customer_id() && empty( $ud['external_id'] ) ) {
            $ud['external_id'] = self::sha256( (string) $order->get_customer_id() );
        }

        // Attribution cookies persisted at checkout — used in admin context.
        $ttp = (string) $order->get_meta( '_coc_ttp' );
        if ( $ttp && empty( $ud['ttp'] ) ) {
            $ud['ttp'] = $ttp;
        }
        $ttclid = (string) $order->get_meta( '_coc_ttclid' );
        if ( $ttclid && empty( $ud['ttclid'] ) ) {
            $ud['ttclid'] = $ttclid;
        }

        return $ud;
    }

    /* ------------------------------------------------------------------
     * Payload builders
     * ------------------------------------------------------------------ */

    private static function product_props( $product, $quantity = 1 ) {
        if ( ! ( $product instanceof WC_Product ) ) {
            $product = wc_get_product( $product );
        }
        if ( ! $product ) {
            return [];
        }

        $parent_id  = $product->get_parent_id() ?: $product->get_id();
        $categories = get_the_terms( $parent_id, 'product_cat' );
        $cat        = ( $categories && ! is_wp_error( $categories ) ) ? $categories[0]->name : '';
        $price      = (float) wc_get_price_excluding_tax( $product );
        $quantity   = (int) $quantity;

        return [
            'contents'     => [ [
                'content_id'       => $product->get_sku() ?: (string) $product->get_id(),
                'content_name'     => $product->get_name(),
                'content_category' => $cat,
                'content_type'     => 'product',
                'quantity'         => $quantity,
                'price'            => $price,
            ] ],
            'value'        => round( $price * $quantity, 2 ),
            'currency'     => get_woocommerce_currency(),
            'content_type' => 'product',
        ];
    }

    private static function cart_props() {
        if ( ! WC()->cart ) {
            return [];
        }
        $contents = [];
        $value    = 0.0;
        foreach ( WC()->cart->get_cart() as $ci ) {
            $product = $ci['data'];
            $qty     = (int) $ci['quantity'];
            $price   = (float) wc_get_price_excluding_tax( $product );
            $contents[] = [
                'content_id'   => $product->get_sku() ?: (string) $product->get_id(),
                'content_name' => $product->get_name(),
                'content_type' => 'product',
                'quantity'     => $qty,
                'price'        => $price,
            ];
            $value += $price * $qty;
        }
        return [
            'contents'     => $contents,
            'value'        => round( $value, 2 ),
            'currency'     => get_woocommerce_currency(),
            'content_type' => 'product',
        ];
    }

    private static function order_props( WC_Order $order ) {
        $contents = [];
        foreach ( $order->get_items() as $oi ) {
            $prod = $oi->get_product();
            if ( ! $prod ) {
                continue;
            }
            $contents[] = [
                'content_id'   => $prod->get_sku() ?: (string) $prod->get_id(),
                'content_name' => $prod->get_name(),
                'content_type' => 'product',
                'quantity'     => $oi->get_quantity(),
                'price'        => (float) $order->get_item_subtotal( $oi, false, false ),
            ];
        }
        return [
            'contents'       => $contents,
            'value'          => (float) $order->get_total(),
            'currency'       => $order->get_currency(),
            'content_type'   => 'product',
            'order_id'       => (string) $order->get_order_number(),
        ];
    }

    private static function make_eid( $prefix = '' ) {
        return $prefix . uniqid( '', true );
    }

    /**
     * Persist TikTok attribution cookies to order meta at checkout time.
     * Called during hook_purchase (customer's browser request) so values remain
     * available when hook_purchase_on_complete fires in admin context.
     */
    private static function save_attribution_to_order( WC_Order $order ) {
        if ( isset( $_COOKIE['_ttp'] ) ) {
            $order->update_meta_data( '_coc_ttp', sanitize_text_field( wp_unslash( $_COOKIE['_ttp'] ) ) );
        }
        // ttclid from cookie (set by TikTok pixel JS) or direct URL parameter.
        $ttclid = '';
        if ( isset( $_COOKIE['ttclid'] ) ) {
            $ttclid = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
        } elseif ( ! empty( $_GET['ttclid'] ) ) {
            $ttclid = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
        }
        if ( $ttclid ) {
            $order->update_meta_data( '_coc_ttclid', $ttclid );
        }
    }

    /**
     * Normalize a raw phone number to E.164 format for hashing.
     *
     * @param string $raw            Raw phone from WooCommerce billing field.
     * @param string $billing_country ISO-3166-1 alpha-2 country code (e.g. 'BD').
     * @return string E.164 string or digits-only fallback.
     */
    private static function normalize_phone( $raw, $billing_country = '' ) {
        $phone = trim( (string) $raw );
        if ( '' === $phone ) {
            return '';
        }
        $has_plus = ( '+' === substr( $phone, 0, 1 ) );
        $digits   = preg_replace( '/[^0-9]/', '', $phone );
        if ( '' === $digits ) {
            return '';
        }
        if ( $has_plus ) {
            return '+' . $digits;
        }
        if ( $billing_country ) {
            $code = self::country_calling_code( $billing_country );
            if ( $code ) {
                $local = ltrim( $digits, '0' );
                if ( '' !== $local ) {
                    return '+' . $code . $local;
                }
            }
        }
        return $digits;
    }

    /**
     * Map ISO-3166-1 alpha-2 country code → ITU country calling code.
     *
     * @param  string $iso2  e.g. 'BD', 'US', 'GB'
     * @return string Calling code digits, e.g. '880', or '' if unknown.
     */
    private static function country_calling_code( $iso2 ) {
        $map = [
            'AF' => '93',  'AL' => '355', 'DZ' => '213', 'AO' => '244',
            'AR' => '54',  'AU' => '61',  'AT' => '43',  'AZ' => '994',
            'BD' => '880', 'BE' => '32',  'BR' => '55',  'BG' => '359',
            'KH' => '855', 'CM' => '237', 'CA' => '1',   'CL' => '56',
            'CN' => '86',  'CO' => '57',  'HR' => '385', 'CZ' => '420',
            'DK' => '45',  'EG' => '20',  'ET' => '251', 'FI' => '358',
            'FR' => '33',  'GH' => '233', 'DE' => '49',  'GR' => '30',
            'HK' => '852', 'HU' => '36',  'IN' => '91',  'ID' => '62',
            'IR' => '98',  'IQ' => '964', 'IE' => '353', 'IL' => '972',
            'IT' => '39',  'JP' => '81',  'JO' => '962', 'KZ' => '7',
            'KE' => '254', 'KR' => '82',  'KW' => '965', 'LK' => '94',
            'LB' => '961', 'LY' => '218', 'MY' => '60',  'MX' => '52',
            'MA' => '212', 'MM' => '95',  'NP' => '977', 'NL' => '31',
            'NZ' => '64',  'NG' => '234', 'NO' => '47',  'PK' => '92',
            'PH' => '63',  'PL' => '48',  'PT' => '351', 'QA' => '974',
            'RO' => '40',  'RU' => '7',   'SA' => '966', 'SN' => '221',
            'SG' => '65',  'ZA' => '27',  'ES' => '34',  'SE' => '46',
            'CH' => '41',  'TW' => '886', 'TZ' => '255', 'TH' => '66',
            'TR' => '90',  'UA' => '380', 'AE' => '971', 'GB' => '44',
            'US' => '1',   'UG' => '256', 'VN' => '84',  'YE' => '967',
            'ZM' => '260', 'ZW' => '263',
        ];
        return $map[ strtoupper( (string) $iso2 ) ] ?? '';
    }

    private static function sha256( $value ) {
        return hash( 'sha256', strtolower( trim( (string) $value ) ) );
    }
}
