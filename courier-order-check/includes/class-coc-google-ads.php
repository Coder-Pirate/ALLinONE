<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Ads — gtag.js (browser) + Conversion Measurement (server-side).
 *
 * Browser events:
 *   view_item, add_to_cart, begin_checkout, conversion (purchase)
 *
 * Server-side (your server → Google):
 *   Purchase conversion via https://www.googleadservices.com/pagead/conversion/
 *   with gclid / gbraid / wbraid (three click signals) + Enhanced Conversions
 *   (SHA-256 hashed email + phone) for maximum match rate.
 *
 * Click signals captured from cookies and persisted to order meta so they
 * remain available when the admin fires "purchase on complete" in a different
 * browser session — exactly matching the TikTok / Meta CAPI pattern.
 */
class COC_Google_Ads {

    /** Temporary eid for last AJAX add-to-cart — passed via WC fragment. */
    private static $last_atc_eid = '';

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        if ( empty( trim( get_option( 'coc_gads_conversion_id', '' ) ) ) ) {
            return;
        }

        // Base gtag.js snippet.
        add_action( 'wp_head', [ __CLASS__, 'inject_head' ], 1 );

        // Non-AJAX add-to-cart: send server-side immediately; queue browser event.
        add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'hook_add_to_cart' ], 10, 6 );

        // Purchase hook: fires at checkout OR when admin marks order Completed.
        if ( get_option( 'coc_purchase_on_complete', '' ) ) {
            add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'hook_purchase_on_complete' ], 10, 2 );
        } else {
            add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'hook_purchase' ], 10, 3 );
        }

        // AJAX add-to-cart: pass eid to browser via WC fragment.
        add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'atc_fragment' ] );

        // Page-level browser events.
        add_action( 'wp_footer', [ __CLASS__, 'flush_browser_queue' ], 2 );
        add_action( 'wp_footer', [ __CLASS__, 'fire_page_events' ], 5 );

        // Client-side script (AJAX add-to-cart remarketing).
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
    }

    /* ------------------------------------------------------------------
     * gtag.js base snippet — injected in <head>
     * ------------------------------------------------------------------ */

    public static function inject_head() {
        $conv_id = trim( get_option( 'coc_gads_conversion_id', '' ) );
        $jid     = esc_js( $conv_id );
        $aid     = esc_attr( $conv_id );

        // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
        echo "<!-- Google Ads -->\n";
        echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $aid . '"></script>' . "\n";
        echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$jid}');</script>\n";
        echo "<!-- End Google Ads -->\n";
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
        if ( is_product() ) {
            self::event_view_item();
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

        self::browser_event( 'view_item', $params );
    }

    private static function event_begin_checkout() {
        if ( ! WC()->cart ) {
            return;
        }
        $params = self::cart_params();
        self::browser_event( 'begin_checkout', $params );
    }

    /** Output browser-side conversion event on the thank-you page. */
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

        // Only fire browser conversion event when server-side has already been sent.
        if ( ! $order->get_meta( '_coc_gads_purchase_sent' ) ) {
            return;
        }

        $conv_id    = trim( get_option( 'coc_gads_conversion_id', '' ) );
        $conv_label = trim( get_option( 'coc_gads_conv_label', '' ) );
        $send_to    = $conv_label ? $conv_id . '/' . $conv_label : $conv_id;

        // ── Browser-side Enhanced Conversions user_data ────────────────────────
        // gtag() hashes the values itself — pass raw (unhashed) PII.
        // This runs in the customer's own browser on their thank-you page.
        $user_data = [];

        $email = trim( (string) $order->get_billing_email() );
        if ( $email ) {
            $user_data['email'] = strtolower( $email );
        }

        $phone = self::normalize_phone( $order->get_billing_phone(), $order->get_billing_country() );
        if ( $phone ) {
            $user_data['phone_number'] = $phone;
        }

        $address = array_filter( [
            'first_name'  => trim( (string) $order->get_billing_first_name() ),
            'last_name'   => trim( (string) $order->get_billing_last_name() ),
            'city'        => trim( (string) $order->get_billing_city() ),
            'region'      => trim( (string) $order->get_billing_state() ),
            'postal_code' => trim( (string) $order->get_billing_postcode() ),
            'country'     => trim( (string) $order->get_billing_country() ),
        ] );
        if ( $address ) {
            $user_data['address'] = $address;
        }

        if ( $user_data ) {
            echo '<script>if(typeof gtag!=="undefined"){gtag("set","user_data",' .
                 wp_json_encode( $user_data ) . ');}</script>' . "\n";
        }

        $params = [
            'send_to'        => $send_to,
            'value'          => (float) $order->get_total(),
            'currency'       => $order->get_currency(),
            'transaction_id' => (string) $order->get_order_number(),
        ];

        self::browser_event( 'conversion', $params );
    }

    /* ------------------------------------------------------------------
     * WooCommerce hooks
     * ------------------------------------------------------------------ */

    /**
     * Fires for every add-to-cart (AJAX and non-AJAX).
     * Queues browser remarketing event for next page load.
     */
    public static function hook_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        $product = wc_get_product( $variation_id ?: $product_id );
        if ( ! $product ) {
            return;
        }

        $eid    = self::make_eid( 'atc_' );
        $item   = self::product_item( $product, (int) $quantity );
        $params = [
            'currency' => get_woocommerce_currency(),
            'value'    => round( (float) $item['price'] * (int) $quantity, 2 ),
            'items'    => [ $item ],
        ];

        if ( wp_doing_ajax() ) {
            self::$last_atc_eid = $eid;
        } else {
            if ( WC()->session ) {
                $q   = WC()->session->get( 'coc_gads_q', [] );
                $q[] = [ 'event' => 'add_to_cart', 'params' => $params ];
                WC()->session->set( 'coc_gads_q', $q );
            }
        }
    }

    /** Attaches the AJAX add-to-cart eid to the WC fragments response. */
    public static function atc_fragment( $fragments ) {
        if ( self::$last_atc_eid ) {
            $fragments['div#coc-gads-atc-eid'] =
                '<div id="coc-gads-atc-eid" style="display:none" data-eid="' .
                esc_attr( self::$last_atc_eid ) . '"></div>';
            self::$last_atc_eid = '';
        }
        return $fragments;
    }

    /** Outputs queued non-AJAX add-to-cart browser events on the next page. */
    public static function flush_browser_queue() {
        if ( ! WC()->session ) {
            return;
        }
        $q = WC()->session->get( 'coc_gads_q', [] );
        if ( empty( $q ) ) {
            return;
        }
        WC()->session->set( 'coc_gads_q', [] );
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
        if ( $order->get_meta( '_coc_gads_purchase_sent' ) ) {
            return; // idempotency guard.
        }

        $order->update_meta_data( '_coc_gads_purchase_sent', '1' );
        $order->save();

        self::send_conversion( $order );
    }

    /**
     * Fires right when the order is created during checkout.
     * Sends server-side conversion and marks order so browser event fires on thank-you page.
     */
    public static function hook_purchase( $order_id, $posted_data, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }
        if ( $order->get_meta( '_coc_gads_purchase_sent' ) ) {
            return; // idempotency guard.
        }

        $order->update_meta_data( '_coc_gads_purchase_sent', '1' );
        self::save_attribution_to_order( $order );
        $order->save();

        self::send_conversion( $order );
    }

    /* ------------------------------------------------------------------
     * Frontend script (AJAX add-to-cart remarketing)
     * ------------------------------------------------------------------ */

    public static function enqueue_scripts() {
        $is_wc = is_shop() || is_product_category() || is_product_tag() ||
                 is_product_taxonomy() || is_product() || is_cart() || is_checkout();
        if ( ! $is_wc ) {
            return;
        }

        $js_file = COC_PLUGIN_DIR . 'assets/js/google-ads.js';
        wp_enqueue_script(
            'coc-google-ads',
            COC_PLUGIN_URL . 'assets/js/google-ads.js',
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

        $conv_id    = trim( get_option( 'coc_gads_conversion_id', '' ) );
        $conv_label = trim( get_option( 'coc_gads_conv_label', '' ) );

        wp_localize_script( 'coc-google-ads', 'COC_GADS', [
            'currency'         => get_woocommerce_currency(),
            'products'         => $products,
            'conversion_id'    => $conv_id,
            'conversion_label' => $conv_label,
        ] );
    }

    /* ------------------------------------------------------------------
     * Server-side Conversion Measurement (your server → Google)
     * ------------------------------------------------------------------ */

    /**
     * Sends a server-side conversion ping to Google's Conversion Measurement endpoint.
     *
     * Click signals (three supported, at least one required for attribution):
     *   gclid  — standard Google click ID (_gcl_aw cookie).
     *   gbraid — iOS app-to-web attribution (_gcl_gb cookie).
     *   wbraid — iOS web-to-web attribution, Privacy Sandbox (_gcl_gs cookie).
     *
     * Enhanced Conversions:
     *   em — SHA-256 of lowercase billing email → improves unmatched hit rates.
     *   ph — SHA-256 of E.164-normalised billing phone.
     *
     * Endpoint: https://www.googleadservices.com/pagead/conversion/{numeric_id}/
     */
    private static function send_conversion( WC_Order $order ) {
        $conv_id    = trim( get_option( 'coc_gads_conversion_id', '' ) );
        $conv_label = trim( get_option( 'coc_gads_conv_label', '' ) );

        if ( empty( $conv_id ) || empty( $conv_label ) ) {
            return;
        }

        // Numeric part of AW-XXXXXXXXXX.
        $numeric_id = preg_replace( '/^AW-/i', '', $conv_id );
        if ( ! ctype_digit( $numeric_id ) ) {
            return;
        }

        // ── Click signals — try order meta first (available in admin context). ──
        $gclid = (string) $order->get_meta( '_coc_gads_gclid' );
        if ( empty( $gclid ) ) {
            $gclid = self::get_gclid();
        }

        $gbraid = (string) $order->get_meta( '_coc_gads_gbraid' );
        if ( empty( $gbraid ) ) {
            $gbraid = self::get_gbraid();
        }

        $wbraid = (string) $order->get_meta( '_coc_gads_wbraid' );
        if ( empty( $wbraid ) ) {
            $wbraid = self::get_wbraid();
        }

        // ── Base conversion payload. ──────────────────────────────────────────
        $args = [
            'label'         => $conv_label,
            'value'         => number_format( (float) $order->get_total(), 2, '.', '' ),
            'currency_code' => $order->get_currency(),
            'oid'           => (string) $order->get_order_number(),
            'convtime'      => (string) time(), // accurate timestamp for attribution window.
        ];

        // Click signals.
        if ( $gclid ) {
            $args['gclid']             = $gclid;
            $args['ct_cookie_present'] = '1'; // tells Google the GCL cookie was present.
        }
        if ( $gbraid ) {
            $args['gbraid'] = $gbraid;
        }
        if ( $wbraid ) {
            $args['wbraid'] = $wbraid;
        }

        // ── Enhanced Conversions — hashed PII for improved match rates. ───────
        $email = trim( (string) $order->get_billing_email() );
        if ( $email ) {
            $args['em'] = self::sha256( strtolower( $email ) );
        }

        $phone = self::normalize_phone( $order->get_billing_phone(), $order->get_billing_country() );
        if ( $phone ) {
            $args['ph'] = self::sha256( $phone );
        }

        // Additional Enhanced Conversion signals: name + address fields.
        // Each must be SHA-256 of the lowercase, trimmed value.
        $fn = trim( (string) $order->get_billing_first_name() );
        if ( $fn ) {
            $args['fn'] = self::sha256( strtolower( $fn ) );
        }

        $ln = trim( (string) $order->get_billing_last_name() );
        if ( $ln ) {
            $args['ln'] = self::sha256( strtolower( $ln ) );
        }

        $ct = trim( (string) $order->get_billing_city() );
        if ( $ct ) {
            $args['ct'] = self::sha256( strtolower( $ct ) );
        }

        $st = trim( (string) $order->get_billing_state() );
        if ( $st ) {
            $args['st'] = self::sha256( strtolower( $st ) );
        }

        $zp = trim( (string) $order->get_billing_postcode() );
        if ( $zp ) {
            $args['zp'] = self::sha256( strtolower( $zp ) );
        }

        $country = trim( (string) $order->get_billing_country() );
        if ( $country ) {
            $args['country'] = self::sha256( strtolower( $country ) );
        }

        $url = 'https://www.googleadservices.com/pagead/conversion/' .
               rawurlencode( $numeric_id ) . '/?' .
               http_build_query( $args );

        // Block in admin context so hook_purchase_on_complete can catch errors.
        // Non-blocking on the front-end so checkout latency is unaffected.
        wp_remote_get( $url, [
            'timeout'    => 10,
            'blocking'   => is_admin(),
            'sslverify'  => true,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
        ] );
    }

    /* ------------------------------------------------------------------
     * Click attribution helpers (gclid, gbraid, wbraid from cookies)
     * ------------------------------------------------------------------ */

    /**
     * Extracts gclid from the _gcl_aw cookie.
     * Cookie format: GCL.{timestamp}.{gclid}  →  third segment.
     */
    private static function get_gclid() {
        if ( ! isset( $_COOKIE['_gcl_aw'] ) ) {
            return '';
        }
        $val   = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_aw'] ) );
        $parts = explode( '.', $val, 3 );
        return isset( $parts[2] ) ? $parts[2] : '';
    }

    /**
     * Extracts gbraid from the _gcl_gb cookie (iOS app-to-web / Privacy Sandbox).
     * Cookie format: GCL.{timestamp}.{gbraid}  →  third segment.
     */
    private static function get_gbraid() {
        if ( ! isset( $_COOKIE['_gcl_gb'] ) ) {
            return '';
        }
        $val   = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_gb'] ) );
        $parts = explode( '.', $val, 3 );
        return isset( $parts[2] ) ? $parts[2] : '';
    }

    /**
     * Extracts wbraid from the _gcl_gs cookie (iOS web-to-web / Privacy Sandbox).
     * Cookie format: GCL.{timestamp}.{wbraid}  →  third segment.
     */
    private static function get_wbraid() {
        if ( ! isset( $_COOKIE['_gcl_gs'] ) ) {
            return '';
        }
        $val   = sanitize_text_field( wp_unslash( $_COOKIE['_gcl_gs'] ) );
        $parts = explode( '.', $val, 3 );
        return isset( $parts[2] ) ? $parts[2] : '';
    }

    /* ------------------------------------------------------------------
     * Attribution persistence
     * ------------------------------------------------------------------ */

    /**
     * Persist all three click signals to order meta at checkout time.
     * Called during hook_purchase (customer's browser request) so the values
     * remain available when hook_purchase_on_complete fires in admin context.
     */
    private static function save_attribution_to_order( WC_Order $order ) {
        $gclid = self::get_gclid();
        if ( $gclid ) {
            $order->update_meta_data( '_coc_gads_gclid', $gclid );
        }

        $gbraid = self::get_gbraid();
        if ( $gbraid ) {
            $order->update_meta_data( '_coc_gads_gbraid', $gbraid );
        }

        $wbraid = self::get_wbraid();
        if ( $wbraid ) {
            $order->update_meta_data( '_coc_gads_wbraid', $wbraid );
        }
    }

    /* ------------------------------------------------------------------
     * Enhanced Conversions — hashed PII helpers
     * ------------------------------------------------------------------ */

    /**
     * Returns the lowercase hex SHA-256 hash of a string.
     * Used for Enhanced Conversions (em, ph fields).
     */
    private static function sha256( $value ) {
        return hash( 'sha256', (string) $value );
    }

    /**
     * Normalise a raw phone number to E.164 format before hashing.
     * Identical logic to TikTok/Meta implementations for consistency.
     *
     * @param  string $raw             Raw phone from WooCommerce billing field.
     * @param  string $billing_country ISO-3166-1 alpha-2 country code (e.g. 'BD').
     * @return string  E.164 string (with leading +), or digit-only fallback.
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
     * Map ISO-3166-1 alpha-2 country code → ITU calling code digits.
     * Covers the most common WooCommerce billing countries.
     *
     * @param  string $iso2  e.g. 'BD', 'US', 'GB'.
     * @return string Calling code digits (no +), or '' if unknown.
     */
    private static function country_calling_code( $iso2 ) {
        $map = [
            'AF' => '93',  'AL' => '355', 'DZ' => '213', 'AO' => '244',
            'AR' => '54',  'AU' => '61',  'AT' => '43',  'AZ' => '994',
            'BD' => '880', 'BE' => '32',  'BR' => '55',  'BG' => '359',
            'KH' => '855', 'CM' => '237', 'CA' => '1',   'CL' => '56',
            'CN' => '86',  'CO' => '57',  'CD' => '243', 'CG' => '242',
            'HR' => '385', 'CZ' => '420', 'DK' => '45',  'EG' => '20',
            'ET' => '251', 'FI' => '358', 'FR' => '33',  'DE' => '49',
            'GH' => '233', 'GR' => '30',  'GT' => '502', 'HN' => '504',
            'HK' => '852', 'HU' => '36',  'IN' => '91',  'ID' => '62',
            'IR' => '98',  'IQ' => '964', 'IE' => '353', 'IL' => '972',
            'IT' => '39',  'JM' => '1876','JP' => '81',  'JO' => '962',
            'KZ' => '7',   'KE' => '254', 'KP' => '850', 'KR' => '82',
            'KW' => '965', 'LB' => '961', 'LY' => '218', 'MY' => '60',
            'MX' => '52',  'MA' => '212', 'MM' => '95',  'NP' => '977',
            'NL' => '31',  'NZ' => '64',  'NG' => '234', 'NO' => '47',
            'OM' => '968', 'PK' => '92',  'PA' => '507', 'PH' => '63',
            'PL' => '48',  'PT' => '351', 'QA' => '974', 'RO' => '40',
            'RU' => '7',   'SA' => '966', 'SN' => '221', 'RS' => '381',
            'SG' => '65',  'ZA' => '27',  'ES' => '34',  'LK' => '94',
            'SD' => '249', 'SE' => '46',  'CH' => '41',  'SY' => '963',
            'TW' => '886', 'TZ' => '255', 'TH' => '66',  'TN' => '216',
            'TR' => '90',  'UG' => '256', 'UA' => '380', 'AE' => '971',
            'GB' => '44',  'US' => '1',   'UZ' => '998', 'VE' => '58',
            'VN' => '84',  'YE' => '967', 'ZM' => '260', 'ZW' => '263',
        ];
        return isset( $map[ strtoupper( $iso2 ) ] ) ? $map[ strtoupper( $iso2 ) ] : '';
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
            'item_id'                  => $product->get_sku() ?: (string) $product->get_id(),
            'item_name'                => $product->get_name(),
            'item_category'            => $cat,
            'price'                    => $price,
            'quantity'                 => (int) $quantity,
            'google_business_vertical' => 'retail',
        ];
    }

    private static function cart_params() {
        if ( ! WC()->cart ) {
            return [];
        }
        $items = [];
        $value = 0.0;
        foreach ( WC()->cart->get_cart() as $ci ) {
            $item = self::product_item( $ci['data'], (int) $ci['quantity'] );
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

    private static function make_eid( $prefix = '' ) {
        return $prefix . uniqid( '', true );
    }
}
