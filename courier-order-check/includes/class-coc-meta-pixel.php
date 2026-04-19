<?php
defined( 'ABSPATH' ) || exit;

/**
 * Meta Pixel (browser-side) + Meta Conversions API (server-side, your own server)
 *
 * Events:
 *   PageView, ViewContent, ViewCategory, AddToCart,
 *   InitiateCheckout, AddPaymentInfo, Purchase
 *
 * Each event fires from both the browser pixel and your server via CAPI
 * with matching event_ids for proper deduplication.
 */
class COC_Meta_Pixel {

    /** event_id for the current AJAX add-to-cart — passed to browser via WC fragment. */
    private static $last_atc_eid = '';

    /** PageView event_id — generated at head injection, consumed in fire_page_events for CAPI dedup. */
    private static $page_view_eid = '';

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        if ( empty( trim( get_option( 'coc_pixel_id', '' ) ) ) ) {
            return;
        }

        // Persist fbclid from landing page URL into WC session.
        add_action( 'wp', [ __CLASS__, 'persist_fbclid_to_session' ] );

        // Base snippet.
        add_action( 'wp_head', [ __CLASS__, 'inject_head' ], 1 );

        // Page-level events (both browser inline script + CAPI).
        add_action( 'wp_footer', [ __CLASS__, 'fire_page_events' ], 5 );

        // WooCommerce hooks for immediate CAPI sends.
        add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'hook_add_to_cart' ], 10, 6 );

        // Always persist attribution cookies at checkout (customer's browser session).
        // Required even in "purchase on complete" mode — hook_purchase_on_complete fires
        // in admin context where no customer cookies are available, so fbp/fbc must
        // already be in order meta by the time the admin triggers the conversion.
        add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'hook_save_attribution' ], 5, 3 );

        // Purchase hook: fires at checkout OR when admin marks order Completed.
        if ( get_option( 'coc_purchase_on_complete', '' ) ) {
            add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'hook_purchase_on_complete' ], 10, 2 );
            add_action( 'coc_pixel_async_purchase', [ __CLASS__, 'process_async_purchase' ] );
        } else {
            add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'hook_purchase' ], 10, 3 );
        }

        // Pass AJAX add-to-cart event_id back to browser via WC cart fragment.
        add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'atc_fragment' ] );

        // Client-side interaction events (AJAX add-to-cart, AddPaymentInfo).
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
    }

    /* ------------------------------------------------------------------
     * Base pixel snippet — injected in <head>
     * ------------------------------------------------------------------ */

    public static function inject_head() {
        $pid  = esc_js( trim( get_option( 'coc_pixel_id', '' ) ) );
        $apid = esc_attr( trim( get_option( 'coc_pixel_id', '' ) ) );
        // Generate PageView event_id now so browser pixel and CAPI share the same id.
        self::$page_view_eid = self::make_eid( 'pv_' );
        $eid_js = esc_js( self::$page_view_eid );

        // Advanced Matching — pass user data to fbq('init') on checkout / thank-you
        // pages so browser-side events also carry email, phone, external_id, etc.
        $am    = self::advanced_matching_data();
        $am_js = ! empty( $am ) ? ',' . wp_json_encode( $am ) : '';

        // phpcs:disable
        echo "<!-- Meta Pixel Code -->\n";
        echo "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$pid}'{$am_js});fbq('track','PageView',{},{eventID:'{$eid_js}'});</script>\n";
        echo "<noscript><img height=\"1\" width=\"1\" style=\"display:none\" src=\"https://www.facebook.com/tr?id={$apid}&amp;ev=PageView&amp;noscript=1\"/></noscript>\n";
        echo "<!-- End Meta Pixel Code -->\n";
        // phpcs:enable
    }

    /* ------------------------------------------------------------------
     * Inline browser fbq() call with event_id (for deduplication)
     * ------------------------------------------------------------------ */

    private static function browser_event( $event_name, array $cdata, $eid ) {
        echo '<script>if(typeof fbq!=="undefined"){fbq("track",' .
             wp_json_encode( $event_name ) . ',' .
             wp_json_encode( $cdata ) . ',{eventID:' .
             wp_json_encode( $eid ) . '});}</script>' . "\n";
    }

    /* ------------------------------------------------------------------
     * Page-level events (wp_footer, priority 5)
     * ------------------------------------------------------------------ */

    public static function fire_page_events() {
        // Server-side PageView — same event_id as browser pixel (deduplication).
        $pv_eid = self::$page_view_eid ?: self::make_eid( 'pv_' );
        self::send_capi( 'PageView', [], $pv_eid );

        // Flush any queued browser events from non-AJAX add-to-cart.
        self::flush_browser_queue();

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
        $pd    = self::product_data( $product, 1 );
        $cdata = [
            'content_name' => $product->get_name(),
            'content_ids'  => [ $pd['id'] ],
            'contents'     => [ $pd ],
            'content_type' => 'product',
            'value'        => (float) wc_get_price_excluding_tax( $product ),
            'currency'     => get_woocommerce_currency(),
        ];

        self::send_capi( 'ViewContent', $cdata, $eid );
        self::browser_event( 'ViewContent', $cdata, $eid );
    }

    private static function event_view_category() {
        $name = is_shop()
            ? (string) get_option( 'woocommerce_shop_page_title', 'Shop' )
            : (string) single_term_title( '', false );

        $eid   = self::make_eid( 'vcat_' );
        $cdata = [ 'content_category' => $name, 'content_type' => 'product' ];

        self::send_capi( 'ViewCategory', $cdata, $eid );
        self::browser_event( 'ViewCategory', $cdata, $eid );
    }

    private static function event_initiate_checkout() {
        if ( ! WC()->cart ) {
            return;
        }

        $eid = self::make_eid( 'ic_' );
        list( $items, $ids, $value ) = self::cart_data();
        $cdata = [
            'content_ids' => $ids,
            'contents'    => $items,
            'num_items'   => count( $items ),
            'value'       => $value,
            'currency'    => get_woocommerce_currency(),
        ];

        self::send_capi( 'InitiateCheckout', $cdata, $eid );
        self::browser_event( 'InitiateCheckout', $cdata, $eid );
    }

    /** Output browser-side Purchase event on the thank-you page. */
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

        // Reuse the event_id stored when CAPI was sent at checkout.
        $eid = (string) $order->get_meta( '_coc_pixel_purchase_eid' );
        if ( ! $eid ) {
            return;
        }

        $items = [];
        $ids   = [];
        foreach ( $order->get_items() as $oi ) {
            $prod = $oi->get_product();
            if ( ! $prod ) {
                continue;
            }
            $d       = self::product_data( $prod, $oi->get_quantity() );
            $items[] = $d;
            $ids[]   = $d['id'];
        }

        $cdata = [
            'content_ids'  => $ids,
            'contents'     => $items,
            'content_type' => 'product',
            'num_items'    => count( $items ),
            'value'        => (float) $order->get_total(),
            'currency'     => $order->get_currency(),
        ];

        self::browser_event( 'Purchase', $cdata, $eid );
    }

    /* ------------------------------------------------------------------
     * WooCommerce hooks
     * ------------------------------------------------------------------ */

    /**
     * Fires for every add-to-cart action (AJAX and non-AJAX).
     * Sends CAPI immediately; queues browser event for dedup.
     */
    public static function hook_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        $product = wc_get_product( $variation_id ?: $product_id );
        if ( ! $product ) {
            return;
        }

        $eid   = self::make_eid( 'atc_' );
        $pd    = self::product_data( $product, (int) $quantity );
        $cdata = [
            'content_ids'  => [ $pd['id'] ],
            'contents'     => [ $pd ],
            'content_type' => 'product',
            'value'        => round( (float) $pd['item_price'] * (int) $quantity, 2 ),
            'currency'     => get_woocommerce_currency(),
        ];

        self::send_capi( 'AddToCart', $cdata, $eid );

        if ( wp_doing_ajax() ) {
            // AJAX: pass event_id back to browser JS via WC cart fragment.
            self::$last_atc_eid = $eid;
        } else {
            // Non-AJAX (form submit): queue browser pixel event for next page load.
            if ( WC()->session ) {
                $q   = WC()->session->get( 'coc_pixel_q', [] );
                $q[] = [ 'event' => 'AddToCart', 'cdata' => $cdata, 'eid' => $eid ];
                WC()->session->set( 'coc_pixel_q', $q );
            }
        }
    }

    /** Attaches the AJAX add-to-cart event_id to the WC cart fragments response. */
    public static function atc_fragment( $fragments ) {
        if ( self::$last_atc_eid ) {
            $fragments['div#coc-pixel-atc-eid'] =
                '<div id="coc-pixel-atc-eid" style="display:none" data-eid="' .
                esc_attr( self::$last_atc_eid ) .
                '"></div>';
            self::$last_atc_eid = '';
        }
        return $fragments;
    }

    /** Outputs queued non-AJAX add-to-cart browser pixel calls on the next page. */
    private static function flush_browser_queue() {
        if ( ! WC()->session ) {
            return;
        }
        $q = WC()->session->get( 'coc_pixel_q', [] );
        if ( empty( $q ) ) {
            return;
        }
        WC()->session->set( 'coc_pixel_q', [] );
        foreach ( $q as $ev ) {
            self::browser_event( $ev['event'], $ev['cdata'], $ev['eid'] );
        }
    }

    /**
     * Saves attribution cookies to order meta immediately at checkout.
     * Registered at priority 5 on woocommerce_checkout_order_processed so it runs
     * before hook_purchase (priority 10) in default mode, and is the only checkout
     * hook in "purchase on complete" mode — ensuring fbp/fbc are always available
     * when hook_purchase_on_complete fires later in admin context.
     */
    public static function hook_save_attribution( $order_id, $posted_data, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        self::save_attribution_to_order( $order );
        $order->save();
    }

    /**
     * Fires when admin marks the order as Completed ("purchase on complete" mode).
     * Saves meta and schedules async processing to avoid PHP timeout during bulk operations.
     */
    public static function hook_purchase_on_complete( $order_id, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        if ( $order->get_meta( '_coc_pixel_purchase_eid' ) ) {
            return; // idempotency guard.
        }

        $eid = self::make_eid( 'pur_' );
        $order->update_meta_data( '_coc_pixel_purchase_eid', $eid );
        $order->save();

        // Schedule async processing so bulk status changes don't cause PHP timeout.
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time(), 'coc_pixel_async_purchase', [ $order_id ], 'coc' );
        } else {
            self::process_async_purchase( $order_id );
        }
    }

    /**
     * Processes the Purchase CAPI call asynchronously via Action Scheduler.
     * Runs in its own PHP request so it never contributes to admin page timeout.
     */
    public static function process_async_purchase( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $eid = (string) $order->get_meta( '_coc_pixel_purchase_eid' );
        if ( ! $eid ) {
            return;
        }

        $items = [];
        $ids   = [];
        foreach ( $order->get_items() as $oi ) {
            $prod = $oi->get_product();
            if ( ! $prod ) {
                continue;
            }
            $d       = self::product_data( $prod, $oi->get_quantity() );
            $items[] = $d;
            $ids[]   = $d['id'];
        }

        $cdata = [
            'content_ids'  => $ids,
            'contents'     => $items,
            'content_type' => 'product',
            'num_items'    => count( $items ),
            'value'        => (float) $order->get_total(),
            'currency'     => $order->get_currency(),
        ];

        self::send_capi( 'Purchase', $cdata, $eid, self::user_data_from_order( $order ), $order->get_checkout_order_received_url() );
    }

    /**
     * Fires right when order is created during checkout.
     * Sends CAPI Purchase and stores event_id for browser dedup on thank-you page.
     */
    public static function hook_purchase( $order_id, $posted_data, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        // Idempotency: don't fire twice if hook runs more than once.
        if ( $order->get_meta( '_coc_pixel_purchase_eid' ) ) {
            return;
        }

        $eid = self::make_eid( 'pur_' );
        $order->update_meta_data( '_coc_pixel_purchase_eid', $eid );
        self::save_attribution_to_order( $order );
        $order->save();

        $items = [];
        $ids   = [];
        foreach ( $order->get_items() as $oi ) {
            $prod = $oi->get_product();
            if ( ! $prod ) {
                continue;
            }
            $d       = self::product_data( $prod, $oi->get_quantity() );
            $items[] = $d;
            $ids[]   = $d['id'];
        }

        $cdata = [
            'content_ids'  => $ids,
            'contents'     => $items,
            'content_type' => 'product',
            'num_items'    => count( $items ),
            'value'        => (float) $order->get_total(),
            'currency'     => $order->get_currency(),
        ];

        self::send_capi( 'Purchase', $cdata, $eid, self::user_data_from_order( $order ) );
    }

    /* ------------------------------------------------------------------
     * Client-side interaction script
     * ------------------------------------------------------------------ */

    public static function enqueue_scripts() {
        $is_wc = is_shop() || is_product_category() || is_product_tag() ||
                 is_product_taxonomy() || is_product() || is_cart() || is_checkout();
        if ( ! $is_wc ) {
            return;
        }

        $js_file = COC_PLUGIN_DIR . 'assets/js/pixel.js';
        wp_enqueue_script(
            'coc-pixel',
            COC_PLUGIN_URL . 'assets/js/pixel.js',
            [ 'jquery' ],
            file_exists( $js_file ) ? filemtime( $js_file ) : COC_VERSION,
            true
        );

        // Product data for AJAX add-to-cart handler.
        $products = [];
        if ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) {
            global $wp_query;
            foreach ( $wp_query->posts as $p ) {
                $product = wc_get_product( $p->ID );
                if ( $product ) {
                    $products[ $product->get_id() ] = self::product_data( $product, 1 );
                }
            }
        }
        if ( is_product() ) {
            global $post;
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $products[ $product->get_id() ] = self::product_data( $product, 1 );
            }
        }

        // Cart items for remove_from_cart (informational — not a standard Meta event
        // but sometimes tracked; also used for checkout payment method changes).
        $cart_items = [];
        if ( ( is_cart() || is_checkout() ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $key => $ci ) {
                $d = self::product_data( $ci['data'], (int) $ci['quantity'] );
                if ( $d ) {
                    $cart_items[ $key ] = $d;
                }
            }
        }

        wp_localize_script( 'coc-pixel', 'COC_PIXEL', [
            'currency'   => get_woocommerce_currency(),
            'products'   => $products,
            'cart_items' => $cart_items,
        ] );
    }

    /* ------------------------------------------------------------------
     * Conversions API (server-side, own server → Meta Graph API)
     * ------------------------------------------------------------------ */

    private static function send_capi( $event_name, array $custom_data, $eid, array $user_data = [], $source_url = '' ) {
        $pixel_id = trim( get_option( 'coc_pixel_id', '' ) );
        $token    = trim( get_option( 'coc_pixel_token', '' ) );
        if ( empty( $pixel_id ) || empty( $token ) ) {
            return;
        }

        $merged_user = array_merge( self::current_user_data(), $user_data );

        // In admin context the request URI is a wp-admin URL — use the passed
        // source URL (order permalink) or fall back to the store home URL.
        if ( ! $source_url ) {
            if ( is_admin() ) {
                $source_url = home_url( '/' );
            } else {
                $source_url = ( is_ssl() ? 'https' : 'http' ) . '://' .
                              sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) .
                              sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
            }
        }

        $event = [
            'event_name'       => $event_name,
            'event_time'       => time(),
            'event_id'         => $eid,
            'event_source_url' => $source_url,
            'action_source'    => 'website',
            'user_data'        => $merged_user,
        ];

        if ( ! empty( $custom_data ) ) {
            $event['custom_data'] = $custom_data;
        }

        $body = [
            'data'         => [ $event ],
            'access_token' => $token,
        ];

        $test_code = trim( get_option( 'coc_pixel_test_code', '' ) );
        if ( $test_code ) {
            $body['test_event_code'] = $test_code;
        }

        $endpoint = 'https://graph.facebook.com/v19.0/' . rawurlencode( $pixel_id ) . '/events';

        // Non-blocking: fire-and-forget so it never causes PHP timeout.
        wp_remote_post( $endpoint, [
            'headers'     => [ 'Content-Type' => 'application/json' ],
            'body'        => wp_json_encode( $body ),
            'timeout'     => 10,
            'blocking'    => false,
            'data_format' => 'body',
        ] );
    }

    /* ------------------------------------------------------------------
     * User data helpers (SHA-256 hashed PII for CAPI)
     * ------------------------------------------------------------------ */

    /** Collects user data available on the current request. */
    private static function current_user_data() {
        $ud = [];

        // Real client IP — respects Cloudflare, load balancers.
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
            $ud['client_ip_address'] = $ip;
        }

        // User agent.
        if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $ud['client_user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
        }

        // Meta-set cookies for attribution.
        if ( isset( $_COOKIE['_fbp'] ) ) {
            $ud['fbp'] = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
        }
        if ( isset( $_COOKIE['_fbc'] ) ) {
            $ud['fbc'] = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        }
        // Construct fbc from fbclid URL param when _fbc cookie not yet written by pixel JS.
        // This happens on the very first page load after clicking a Facebook ad.
        if ( empty( $ud['fbc'] ) && ! empty( $_GET['fbclid'] ) ) {
            $ud['fbc'] = 'fb.1.' . ( (string) ( time() * 1000 ) ) . '.' .
                         sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
        }
        // Fallback: retrieve fbclid saved to WC session from the landing page.
        if ( empty( $ud['fbc'] ) && function_exists( 'WC' ) && WC()->session ) {
            $stored_fbclid = WC()->session->get( 'coc_fbclid', '' );
            if ( $stored_fbclid ) {
                $ud['fbc'] = 'fb.1.' . ( (string) ( time() * 1000 ) ) . '.' .
                             sanitize_text_field( $stored_fbclid );
            }
        }

        // Logged-in WP user data.
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) {
                $ud['em']          = [ self::sha256( $user->user_email ) ];
                $ud['external_id'] = [ self::sha256( (string) $user->ID ) ];
            }
        }

        return $ud;
    }

    /** Enriches user data with billing info from a completed order. */
    private static function user_data_from_order( WC_Order $order ) {
        $ud = self::current_user_data();

        $map = [
            'em'      => $order->get_billing_email(),
            'fn'      => $order->get_billing_first_name(),
            'ln'      => $order->get_billing_last_name(),
            'ct'      => $order->get_billing_city(),
            'st'      => $order->get_billing_state(),
            'zp'      => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
        ];

        foreach ( $map as $key => $val ) {
            if ( $val ) {
                $ud[ $key ] = [ self::sha256( $val ) ];
            }
        }

        // Phone normalized to E.164 format before hashing (improves event match quality).
        $phone = self::normalize_phone( $order->get_billing_phone(), $order->get_billing_country() );
        if ( $phone ) {
            $ud['ph'] = [ self::sha256( $phone ) ];
        }

        // external_id: registered customer ID, or billing email hash for guests.
        if ( $order->get_customer_id() ) {
            $ud['external_id'] = [ self::sha256( (string) $order->get_customer_id() ) ];
        } elseif ( $order->get_billing_email() ) {
            $ud['external_id'] = [ self::sha256( $order->get_billing_email() ) ];
        }

        // Attribution cookies persisted at checkout — used in admin context.
        $fbp = (string) $order->get_meta( '_coc_fbp' );
        if ( $fbp && empty( $ud['fbp'] ) ) {
            $ud['fbp'] = $fbp;
        }
        $fbc = (string) $order->get_meta( '_coc_fbc' );
        if ( $fbc && empty( $ud['fbc'] ) ) {
            $ud['fbc'] = $fbc;
        }

        return $ud;
    }

    /* ------------------------------------------------------------------
     * fbclid session persistence
     * ------------------------------------------------------------------ */

    /**
     * Persist fbclid from the landing-page URL into WC session.
     * Ensures the click ID is still available at checkout time even if the
     * _fbc cookie was blocked or expired during the browsing session.
     */
    public static function persist_fbclid_to_session() {
        if ( empty( $_GET['fbclid'] ) || ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }
        $fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
        WC()->session->set( 'coc_fbclid', $fbclid );
    }

    /* ------------------------------------------------------------------
     * Advanced Matching (browser-side user data in fbq init)
     * ------------------------------------------------------------------ */

    /**
     * Collects unhashed user data for Meta's Advanced Matching on checkout
     * and thank-you pages.  This data is passed to fbq('init') so the
     * browser-side pixel events also carry email, phone, external_id, etc.,
     * significantly improving Event Match Quality for Purchase events.
     *
     * @return array Unhashed user data keyed by Meta parameter name.
     */
    private static function advanced_matching_data() {
        $am = [];

        // Thank-you page — rich order data available.
        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
            $order_id = isset( $GLOBALS['wp']->query_vars['order-received'] )
                ? absint( $GLOBALS['wp']->query_vars['order-received'] )
                : 0;
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $email = $order->get_billing_email();
                    if ( $email ) {
                        $am['em'] = strtolower( trim( $email ) );
                    }
                    $fn = $order->get_billing_first_name();
                    if ( $fn ) {
                        $am['fn'] = strtolower( trim( $fn ) );
                    }
                    $ln = $order->get_billing_last_name();
                    if ( $ln ) {
                        $am['ln'] = strtolower( trim( $ln ) );
                    }
                    $phone = $order->get_billing_phone();
                    if ( $phone ) {
                        $am['ph'] = preg_replace( '/[^0-9]/', '', $phone );
                    }
                    $ct = $order->get_billing_city();
                    if ( $ct ) {
                        $am['ct'] = strtolower( trim( $ct ) );
                    }
                    $st = $order->get_billing_state();
                    if ( $st ) {
                        $am['st'] = strtolower( trim( $st ) );
                    }
                    $zp = $order->get_billing_postcode();
                    if ( $zp ) {
                        $am['zp'] = trim( $zp );
                    }
                    $country = $order->get_billing_country();
                    if ( $country ) {
                        $am['country'] = strtolower( trim( $country ) );
                    }
                    if ( $order->get_customer_id() ) {
                        $am['external_id'] = (string) $order->get_customer_id();
                    } elseif ( $email ) {
                        $am['external_id'] = strtolower( trim( $email ) );
                    }
                }
            }
            return $am;
        }

        // Checkout page — use WC customer session data.
        if ( function_exists( 'is_checkout' ) && is_checkout() && function_exists( 'WC' ) && WC()->customer ) {
            $customer = WC()->customer;
            $email    = $customer->get_billing_email();
            if ( $email ) {
                $am['em'] = strtolower( trim( $email ) );
            }
            $fn = $customer->get_billing_first_name();
            if ( $fn ) {
                $am['fn'] = strtolower( trim( $fn ) );
            }
            $ln = $customer->get_billing_last_name();
            if ( $ln ) {
                $am['ln'] = strtolower( trim( $ln ) );
            }
            $phone = $customer->get_billing_phone();
            if ( $phone ) {
                $am['ph'] = preg_replace( '/[^0-9]/', '', $phone );
            }
            if ( ! empty( $am ) ) {
                return $am;
            }
        }

        // Fallback: logged-in user data on any page.
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) {
                $am['em']          = strtolower( trim( $user->user_email ) );
                $am['external_id'] = (string) $user->ID;
            }
            if ( $user->first_name ) {
                $am['fn'] = strtolower( trim( $user->first_name ) );
            }
            if ( $user->last_name ) {
                $am['ln'] = strtolower( trim( $user->last_name ) );
            }
        }

        return $am;
    }

    /* ------------------------------------------------------------------
     * Data helpers
     * ------------------------------------------------------------------ */

    private static function product_data( $product, $quantity = 1 ) {
        if ( ! ( $product instanceof WC_Product ) ) {
            $product = wc_get_product( $product );
        }
        if ( ! $product ) {
            return [];
        }
        return [
            'id'         => $product->get_sku() ?: (string) $product->get_id(),
            'quantity'   => (int) $quantity,
            'item_price' => (float) wc_get_price_excluding_tax( $product ),
        ];
    }

    private static function cart_data() {
        $items = [];
        $ids   = [];
        $value = 0.0;
        if ( ! WC()->cart ) {
            return [ $items, $ids, $value ];
        }
        foreach ( WC()->cart->get_cart() as $ci ) {
            $d = self::product_data( $ci['data'], (int) $ci['quantity'] );
            if ( ! $d ) {
                continue;
            }
            $items[] = $d;
            $ids[]   = $d['id'];
            $value  += $d['item_price'] * $d['quantity'];
        }
        return [ $items, $ids, round( $value, 2 ) ];
    }

    private static function make_eid( $prefix = '' ) {
        return $prefix . uniqid( '', true );
    }

    /**
     * Persist Meta attribution cookies to order meta at checkout time.
     * Called during hook_purchase (customer's browser request) so values remain
     * available when hook_purchase_on_complete fires in admin context.
     */
    private static function save_attribution_to_order( WC_Order $order ) {
        if ( isset( $_COOKIE['_fbp'] ) ) {
            $order->update_meta_data( '_coc_fbp', sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) );
        }
        // Build _fbc from the fbclid URL parameter or WC session when the cookie isn't set yet.
        $fbc = '';
        if ( isset( $_COOKIE['_fbc'] ) ) {
            $fbc = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        } elseif ( ! empty( $_GET['fbclid'] ) ) {
            $fbc = 'fb.1.' . ( time() * 1000 ) . '.' . sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
        } elseif ( WC()->session && WC()->session->get( 'coc_fbclid' ) ) {
            $fbc = 'fb.1.' . ( time() * 1000 ) . '.' . sanitize_text_field( WC()->session->get( 'coc_fbclid' ) );
        }
        if ( $fbc ) {
            $order->update_meta_data( '_coc_fbc', $fbc );
        }
    }

    /**
     * Normalize a raw phone number to E.164 format for hashing.
     *
     * Strips formatting characters (spaces, dashes, parentheses).
     * Preserves a leading '+' so country code is retained when already present.
     * Falls back to a billing_country lookup to prepend the country calling code
     * when the number uses a local trunk format (e.g. 01XXXXXXXX → +8801XXXXXXXX).
     *
     * @param string $raw            Raw phone from WooCommerce billing field.
     * @param string $billing_country ISO-3166-1 alpha-2 country code (e.g. 'BD').
     * @return string E.164 string (e.g. '+8801711000000') or digits-only fallback.
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
            // Already carries a country code — return normalised E.164.
            return '+' . $digits;
        }
        if ( $billing_country ) {
            $code = self::country_calling_code( $billing_country );
            if ( $code ) {
                // Strip trunk digit (leading 0) used in many countries' local format.
                $local = ltrim( $digits, '0' );
                if ( '' !== $local ) {
                    return '+' . $code . $local;
                }
            }
        }
        // Fallback: digits only (country code unknown).
        return $digits;
    }

    /**
     * Map ISO-3166-1 alpha-2 country code → ITU country calling code (digits only).
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
