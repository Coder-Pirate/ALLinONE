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
        // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
        ?>
<!-- TikTok Pixel Code -->
<script>
!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};n=document.createElement("script");n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)};
ttq.load('<?php echo $pid; ?>');
ttq.page();
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
        // CAPI PageView for every page.
        self::send_events_api( 'PageView', [], self::make_eid( 'pv_' ) );

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

        $page_url = ( is_ssl() ? 'https' : 'http' ) . '://' .
                    sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) .
                    sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

        $referrer = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) );

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
                'timeout'     => 5,
                'blocking'    => false,
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
        // URL-level ttclid parameter (carried in session/cookie by pixel JS automatically).
        if ( isset( $_COOKIE['ttclid'] ) ) {
            $ud['ttclid'] = sanitize_text_field( wp_unslash( $_COOKIE['ttclid'] ) );
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

        $phone = preg_replace( '/[^0-9]/', '', $order->get_billing_phone() );
        if ( $phone ) {
            $ud['phone_number'] = self::sha256( $phone );
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

    private static function sha256( $value ) {
        return hash( 'sha256', strtolower( trim( (string) $value ) ) );
    }
}
