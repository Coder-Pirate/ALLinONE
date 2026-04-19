<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Analytics 4 — gtag.js (browser) + Measurement Protocol (server-side, purchase only).
 *
 * GA4 Measurement Protocol does NOT support event_id deduplication for
 * non-purchase events. Sending the same event from both browser (gtag) and
 * server (MP) causes double-counting in GA4 reports.
 *
 * Strategy (matches Google's own recommendation):
 *   Browser (gtag.js)  — ALL events: page_view, view_item, view_item_list,
 *                         add_to_cart, begin_checkout, add_payment_info.
 *   Server (MP only)   — purchase ONLY (reliable even if customer closes tab;
 *                         GA4 deduplicates via transaction_id when the browser
 *                         also fires on the thank-you page).
 *
 * Each key event fires from both the browser (gtag) and your server
 * via the GA4 Measurement Protocol using the same client_id for
 * accurate session stitching and deduplication.
 */
class COC_GAA {

    /** PageView event_id — generated at head injection; stored for future reference. */
    private static $page_view_eid = '';

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        if ( empty( trim( get_option( 'coc_gaa_measurement_id', '' ) ) ) ) {
            return;
        }

        // Base gtag.js snippet.
        add_action( 'wp_head', [ __CLASS__, 'inject_head' ], 1 );

        // Non-AJAX add-to-cart: queue browser event for next page load.
        // AJAX add-to-cart is handled entirely by gaa.js (browser-only, no MP).
        add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'hook_add_to_cart' ], 10, 6 );

        // Purchase hook: fires at checkout OR when admin marks order Completed.
        if ( get_option( 'coc_purchase_on_complete', '' ) ) {
            add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'hook_purchase_on_complete' ], 10, 2 );
        } else {
            add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'hook_purchase' ], 10, 3 );
        }

        // Page-level browser events + purchase MP.
        add_action( 'wp_footer', [ __CLASS__, 'flush_browser_queue' ], 2 );
        add_action( 'wp_footer', [ __CLASS__, 'fire_page_events' ], 5 );

        // Client-side script (AJAX add-to-cart, add_payment_info).
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
    }

    /* ------------------------------------------------------------------
     * gtag.js base snippet — injected in <head>
     * ------------------------------------------------------------------ */

    public static function inject_head() {
        $mid  = trim( get_option( 'coc_gaa_measurement_id', '' ) );
        $amid = esc_attr( $mid );
        $jmid = esc_js( $mid );
        // Generate page_view event_id so gtag and MP can be linked (GA4 uses client_id
        // + session_id for stitching, but storing the eid enables future dedup config).
        self::$page_view_eid = self::make_eid( 'pv_' );
        $pv_js = esc_js( self::$page_view_eid );
        // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
        echo "<!-- Google Analytics 4 -->\n";
        echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $amid . '"></script>' . "\n";
        echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$jmid}',{send_page_view:false});gtag('event','page_view',{page_view_id:'{$pv_js}'});</script>\n";
        echo "<!-- End Google Analytics 4 -->\n";
        // phpcs:enable
    }

    /* ------------------------------------------------------------------
     * Inline browser gtag() event
     * ------------------------------------------------------------------ */

    private static function browser_event( $event_name, array $params ) {
        echo '<script>if(typeof gtag!=="undefined"){gtag("event",' .
             wp_json_encode( $event_name ) . ',' .
             wp_json_encode( $params ) . ');}</script>' . "\n";
    }

    /* ------------------------------------------------------------------
     * Page-level events (wp_footer, priority 5)
     * ------------------------------------------------------------------ */

    public static function fire_page_events() {
        // page_view is handled entirely by the browser (gtag in inject_head).
        // Sending MP page_view simultaneously would double-count in GA4 reports.

        if ( is_product() ) {
            self::event_view_item();
        }

        if ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) {
            self::event_view_item_list();
        }

        if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
            self::event_begin_checkout();
        }

        if ( is_wc_endpoint_url( 'order-received' ) ) {
            self::event_purchase_browser();
        }
    }

    private static function event_view_item() {
        global $post;
        $product = wc_get_product( $post->ID );
        if ( ! $product ) {
            return;
        }

        $item   = self::product_item( $product, 1 );
        $params = [
            'currency' => get_woocommerce_currency(),
            'value'    => (float) wc_get_price_excluding_tax( $product ),
            'items'    => [ $item ],
        ];

        // Browser-only: MP would double-count (GA4 has no event_id dedup for view_item).
        self::browser_event( 'view_item', $params );
    }

    private static function event_view_item_list() {
        $list_name = is_shop()
            ? (string) get_option( 'woocommerce_shop_page_title', 'Shop' )
            : (string) single_term_title( '', false );

        $items = [];
        $index = 0;
        global $wp_query;
        if ( $wp_query && ! empty( $wp_query->posts ) ) {
            foreach ( $wp_query->posts as $p ) {
                $product = wc_get_product( $p->ID );
                if ( $product ) {
                    $item                    = self::product_item( $product, 1 );
                    $item['index']           = $index++;
                    $item['item_list_name']  = $list_name;
                    $items[]                 = $item;
                }
            }
        }

        $params = [
            'item_list_name' => $list_name,
            'items'          => $items,
        ];

        // Browser-only: MP would double-count (GA4 has no event_id dedup for view_item_list).
        self::browser_event( 'view_item_list', $params );
    }

    private static function event_begin_checkout() {
        if ( ! WC()->cart ) {
            return;
        }
        $params = self::cart_params();
        // Browser-only: MP would double-count (GA4 has no event_id dedup for begin_checkout).
        self::browser_event( 'begin_checkout', $params );
    }

    /** Output browser-side purchase event on the thank-you page. */
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

        // Only fire browser event when server-side MP has already been sent.
        if ( ! $order->get_meta( '_coc_gaa_purchase_sent' ) ) {
            return;
        }

        $params = self::order_params( $order );
        self::browser_event( 'purchase', $params );
    }

    /* ------------------------------------------------------------------
     * WooCommerce hooks
     * ------------------------------------------------------------------ */

    /**
     * Fires for every add-to-cart (AJAX and non-AJAX).
     * Browser-only for add_to_cart — GA4 has no event_id dedup for this event,
     * so sending from MP simultaneously would double-count.
     * AJAX add_to_cart is handled entirely by gaa.js.
     * Non-AJAX add_to_cart is queued here and fired by flush_browser_queue on next page.
     */
    public static function hook_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        $product = wc_get_product( $variation_id ?: $product_id );
        if ( ! $product ) {
            return;
        }

        // AJAX add_to_cart: gaa.js fires the gtag event client-side — nothing to do here.
        if ( wp_doing_ajax() ) {
            return;
        }

        // Non-AJAX (form submit): queue browser event so it fires on next page load.
        $item   = self::product_item( $product, (int) $quantity );
        $params = [
            'currency' => get_woocommerce_currency(),
            'value'    => round( (float) $item['price'] * (int) $quantity, 2 ),
            'items'    => [ $item ],
        ];

        if ( WC()->session ) {
            $q   = WC()->session->get( 'coc_gaa_q', [] );
            $q[] = [ 'event' => 'add_to_cart', 'params' => $params ];
            WC()->session->set( 'coc_gaa_q', $q );
        }
    }

    /** Outputs queued non-AJAX add-to-cart browser events on the next page. */
    public static function flush_browser_queue() {
        if ( ! WC()->session ) {
            return;
        }
        $q = WC()->session->get( 'coc_gaa_q', [] );
        if ( empty( $q ) ) {
            return;
        }
        WC()->session->set( 'coc_gaa_q', [] );
        foreach ( $q as $ev ) {
            self::browser_event( $ev['event'], $ev['params'] );
        }
    }

    /**
     * Fires when admin marks the order as Completed ("purchase on complete" mode).
     */
    public static function hook_purchase_on_complete( $order_id, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        if ( $order->get_meta( '_coc_gaa_purchase_sent' ) ) {
            return; // idempotency guard.
        }

        $order->update_meta_data( '_coc_gaa_purchase_sent', '1' );
        $order->save();

        self::send_mp( 'purchase', self::order_params( $order ), $order );
    }

    /**
     * Fires right when the order is created during checkout.
     * Sends MP Purchase and marks order so browser event fires on thank-you page.
     */
    public static function hook_purchase( $order_id, $posted_data, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        if ( $order->get_meta( '_coc_gaa_purchase_sent' ) ) {
            return; // idempotency guard.
        }

        $order->update_meta_data( '_coc_gaa_purchase_sent', '1' );
        self::save_attribution_to_order( $order );
        $order->save();

        self::send_mp( 'purchase', self::order_params( $order ), $order );
    }

    /* ------------------------------------------------------------------
     * Frontend script (AJAX add-to-cart, add_payment_info)
     * ------------------------------------------------------------------ */

    public static function enqueue_scripts() {
        $is_wc = is_shop() || is_product_category() || is_product_tag() ||
                 is_product_taxonomy() || is_product() || is_cart() || is_checkout();
        if ( ! $is_wc ) {
            return;
        }

        $js_file = COC_PLUGIN_DIR . 'assets/js/gaa.js';
        wp_enqueue_script(
            'coc-gaa',
            COC_PLUGIN_URL . 'assets/js/gaa.js',
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
                    $products[ $product->get_id() ] = self::product_item( $product, 1 );
                }
            }
        }
        if ( is_product() ) {
            global $post;
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $products[ $product->get_id() ] = self::product_item( $product, 1 );
            }
        }

        $cart_items = [];
        if ( ( is_cart() || is_checkout() ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $key => $ci ) {
                $cart_items[ $key ] = self::product_item( $ci['data'], (int) $ci['quantity'] );
            }
        }

        wp_localize_script( 'coc-gaa', 'COC_GAA', [
            'currency'   => get_woocommerce_currency(),
            'products'   => $products,
            'cart_items' => $cart_items,
        ] );
    }

    /* ------------------------------------------------------------------
     * GA4 Measurement Protocol (server-side → Google Analytics)
     * ------------------------------------------------------------------ */

    private static function send_mp( $event_name, array $params, $order = null ) {
        $measurement_id = trim( get_option( 'coc_gaa_measurement_id', '' ) );
        $api_secret     = trim( get_option( 'coc_gaa_api_secret', '' ) );
        if ( empty( $measurement_id ) || empty( $api_secret ) ) {
            return;
        }

        // Try persisted client_id from checkout (available in admin/cron context).
        $client_id = $order instanceof WC_Order
            ? (string) $order->get_meta( '_coc_ga_client_id' )
            : '';
        if ( empty( $client_id ) ) {
            $client_id = self::get_ga_client_id();
        }
        if ( empty( $client_id ) ) {
            // Without a real client_id the hit still lands but can't be stitched
            // to a browser session. Generate a server-only id for purchase events.
            $client_id = $order instanceof WC_Order
                ? 'server.' . $order->get_id()
                : 'server.' . uniqid( '', true );
        }

        // GA4 MP requires engagement_time_msec > 0 to process events.
        $params['engagement_time_msec'] = 1;

        // Attach session_id — from order meta first, then live cookie.
        $session_id = $order instanceof WC_Order
            ? (string) $order->get_meta( '_coc_ga_session_id' )
            : '';
        if ( empty( $session_id ) ) {
            $session_id = self::get_ga_session_id( $measurement_id );
        }
        if ( $session_id ) {
            $params['session_id'] = $session_id;
        }

        // user_id improves cross-device identity resolution in GA4.
        $user_id = '';
        if ( $order instanceof WC_Order && $order->get_customer_id() ) {
            $user_id = (string) $order->get_customer_id();
        } elseif ( is_user_logged_in() ) {
            $user_id = (string) get_current_user_id();
        }

        $body = [
            'client_id'            => $client_id,
            'timestamp_micros'     => (string) ( time() * 1000000 ),
            'non_personalized_ads' => false,
            'events'               => [
                [
                    'name'   => $event_name,
                    'params' => $params,
                ],
            ],
        ];

        if ( '' !== $user_id ) {
            $body['user_id'] = $user_id;
        }

        $endpoint = add_query_arg(
            [
                'measurement_id' => rawurlencode( $measurement_id ),
                'api_secret'     => rawurlencode( $api_secret ),
            ],
            'https://www.google-analytics.com/mp/collect'
        );

        // Non-blocking: fire-and-forget so it never slows page load.
        wp_remote_post( $endpoint, [
            'headers'     => [ 'Content-Type' => 'application/json' ],
            'body'        => wp_json_encode( $body ),
            'timeout'     => 5,
            'blocking'    => false,
            'data_format' => 'body',
        ] );
    }

    /* ------------------------------------------------------------------
     * GA cookie helpers
     * ------------------------------------------------------------------ */

    /**
     * Extracts the GA4 client_id from the _ga cookie.
     * Cookie format: GA1.x.XXXXXXXXXX.XXXXXXXXXX
     * client_id = last two segments joined by "."
     */
    private static function get_ga_client_id() {
        if ( ! isset( $_COOKIE['_ga'] ) ) {
            return '';
        }
        $parts = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE['_ga'] ) ) );
        if ( count( $parts ) >= 4 ) {
            return $parts[2] . '.' . $parts[3];
        }
        return '';
    }

    /**
     * Extracts the session_id from the _ga_XXXXXXXXXX session cookie.
     * Cookie format: GS1.1.SESSION_ID.SESSION_COUNT...
     * session_id = third segment (index 2).
     */
    private static function get_ga_session_id( $measurement_id ) {
        $suffix = strtoupper( str_replace( 'G-', '', $measurement_id ) );
        $cookie = '_ga_' . $suffix;
        if ( ! isset( $_COOKIE[ $cookie ] ) ) {
            return '';
        }
        $parts = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) );
        return isset( $parts[2] ) ? $parts[2] : '';
    }

    /* ------------------------------------------------------------------
     * Payload builders
     * ------------------------------------------------------------------ */

    private static function product_item( $product, $quantity = 1 ) {
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

        return [
            'item_id'       => $product->get_sku() ?: (string) $product->get_id(),
            'item_name'     => $product->get_name(),
            'item_category' => $cat,
            'price'         => $price,
            'quantity'      => (int) $quantity,
        ];
    }

    private static function cart_params() {
        if ( ! WC()->cart ) {
            return [];
        }
        $items = [];
        $value = 0.0;
        foreach ( WC()->cart->get_cart() as $ci ) {
            $item    = self::product_item( $ci['data'], (int) $ci['quantity'] );
            if ( ! $item ) {
                continue;
            }
            $items[] = $item;
            $value  += $item['price'] * $item['quantity'];
        }
        return [
            'currency' => get_woocommerce_currency(),
            'value'    => round( $value, 2 ),
            'items'    => $items,
        ];
    }

    private static function order_params( WC_Order $order ) {
        $items = [];
        foreach ( $order->get_items() as $oi ) {
            $prod = $oi->get_product();
            if ( ! $prod ) {
                continue;
            }
            $item          = self::product_item( $prod, $oi->get_quantity() );
            $item['price'] = (float) $order->get_item_subtotal( $oi, false, false );
            $items[]       = $item;
        }
        return [
            'currency'       => $order->get_currency(),
            'value'          => (float) $order->get_total(),
            'transaction_id' => (string) $order->get_order_number(),
            'items'          => $items,
        ];
    }

    private static function make_eid( $prefix = '' ) {
        return $prefix . uniqid( '', true );
    }

    /**
     * Persist GA4 client_id and session_id to order meta at checkout time.
     * Called during hook_purchase (customer's browser request) so values remain
     * available when hook_purchase_on_complete fires in admin context.
     */
    private static function save_attribution_to_order( WC_Order $order ) {
        $mid = trim( get_option( 'coc_gaa_measurement_id', '' ) );

        $client_id = self::get_ga_client_id();
        if ( $client_id ) {
            $order->update_meta_data( '_coc_ga_client_id', $client_id );
        }

        $session_id = $mid ? self::get_ga_session_id( $mid ) : '';
        if ( $session_id ) {
            $order->update_meta_data( '_coc_ga_session_id', $session_id );
        }
    }
}
