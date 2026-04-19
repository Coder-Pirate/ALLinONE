<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Tag Manager integration.
 * Injects the GTM snippet and pushes GA4 ecommerce events for WooCommerce:
 *   view_item_list, view_item, add_to_cart, remove_from_cart,
 *   view_cart, begin_checkout, add_shipping_info, add_payment_info, purchase.
 */
class COC_GTM {

    /** Prevent the noscript tag from printing twice. */
    private static $body_injected = false;

    public static function init() {
        $id = trim( get_option( 'coc_gtm_id', '' ) );
        if ( empty( $id ) ) {
            return;
        }

        // GTM snippet.
        add_action( 'wp_head',       [ __CLASS__, 'inject_head' ], 1 );
        add_action( 'wp_body_open',  [ __CLASS__, 'inject_body' ], 1 );
        add_action( 'wp_footer',     [ __CLASS__, 'inject_body' ], 1 ); // fallback for older themes.

        // Non-AJAX add_to_cart: queue event in WC session and output after redirect.
        add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'queue_add_to_cart' ], 10, 6 );

        // Server-side dataLayer pushes (all in wp_footer so page data is ready).
        add_action( 'wp_footer', [ __CLASS__, 'push_pending_events' ],  2 );
        add_action( 'wp_footer', [ __CLASS__, 'push_view_item' ],       3 );
        add_action( 'wp_footer', [ __CLASS__, 'push_view_item_list' ],  3 );
        add_action( 'wp_footer', [ __CLASS__, 'push_view_cart' ],       3 );
        add_action( 'wp_footer', [ __CLASS__, 'push_begin_checkout' ],  3 );
        add_action( 'wp_footer', [ __CLASS__, 'push_purchase' ],        3 );

        // Client-side scripts for interaction events.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
    }

    /* ------------------------------------------------------------------
     * GTM snippet injection
     * ------------------------------------------------------------------ */

    public static function inject_head() {
        $id = esc_js( get_option( 'coc_gtm_id', '' ) );
        // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
        ?>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','<?php echo $id; ?>');</script>
<!-- End Google Tag Manager -->
        <?php
        // phpcs:enable
    }

    public static function inject_body() {
        if ( self::$body_injected ) {
            return;
        }
        self::$body_injected = true;
        $id = esc_attr( get_option( 'coc_gtm_id', '' ) );
        ?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo $id; ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
        <?php
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Build a GA4 items[] entry from a WC_Product.
     *
     * @param  WC_Product|int $product
     * @param  int            $quantity
     * @return array
     */
    private static function get_item_data( $product, $quantity = 1 ) {
        if ( ! ( $product instanceof WC_Product ) ) {
            $product = wc_get_product( $product );
        }
        if ( ! $product ) {
            return [];
        }

        // Use parent ID for categories so variations inherit correctly.
        $parent_id  = $product->get_parent_id() ?: $product->get_id();
        $categories = get_the_terms( $parent_id, 'product_cat' );
        $cat_names  = [];
        if ( $categories && ! is_wp_error( $categories ) ) {
            foreach ( $categories as $cat ) {
                $cat_names[] = $cat->name;
            }
        }

        $item = [
            'item_id'   => $product->get_sku() ?: (string) $product->get_id(),
            'item_name' => $product->get_name(),
            'price'     => (float) wc_get_price_excluding_tax( $product ),
            'quantity'  => (int) $quantity,
        ];

        if ( ! empty( $cat_names ) ) {
            $item['item_category'] = $cat_names[0];
        }
        if ( isset( $cat_names[1] ) ) {
            $item['item_category2'] = $cat_names[1];
        }

        // item_variant for variable products.
        if ( $product->is_type( 'variation' ) ) {
            $attrs = $product->get_variation_attributes( false );
            if ( ! empty( $attrs ) ) {
                $item['item_variant'] = implode( ' / ', array_filter( array_map( 'ucfirst', $attrs ) ) );
            }
        }

        return $item;
    }

    /**
     * Output an inline <script> that pushes one GA4 ecommerce event.
     *
     * @param string $event_name
     * @param array  $ecommerce
     */
    private static function push_datalayer( $event_name, array $ecommerce ) {
        echo '<script>' .
             'window.dataLayer=window.dataLayer||[];' .
             'window.dataLayer.push({ecommerce:null});' .
             'window.dataLayer.push(' . wp_json_encode( [ 'event' => $event_name, 'ecommerce' => $ecommerce ] ) . ');' .
             '</script>' . "\n";
    }

    /**
     * Collect items and total value from the current WC cart.
     *
     * @return array [ items[], value ]
     */
    private static function get_cart_data() {
        $items = [];
        $value = 0.0;
        if ( ! WC()->cart ) {
            return [ $items, $value ];
        }
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            $qty     = (int) $cart_item['quantity'];
            $item    = self::get_item_data( $product, $qty );
            if ( empty( $item ) ) {
                continue;
            }
            $items[] = $item;
            $value  += $item['price'] * $qty;
        }
        return [ $items, round( $value, 2 ) ];
    }

    /* ------------------------------------------------------------------
     * Server-side ecommerce events
     * ------------------------------------------------------------------ */

    public static function push_view_item() {
        if ( ! is_product() ) {
            return;
        }
        global $post;
        $product = wc_get_product( $post->ID );
        if ( ! $product ) {
            return;
        }
        $item = self::get_item_data( $product, 1 );
        self::push_datalayer( 'view_item', [
            'currency' => get_woocommerce_currency(),
            'value'    => $item['price'],
            'items'    => [ $item ],
        ] );
    }

    public static function push_view_item_list() {
        if ( ! ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) ) {
            return;
        }
        global $wp_query;
        $list_name = is_shop()
            ? (string) get_option( 'woocommerce_shop_page_title', 'Shop' )
            : (string) single_term_title( '', false );

        $items = [];
        $index = 0;
        foreach ( $wp_query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( ! $product ) {
                continue;
            }
            $item                   = self::get_item_data( $product, 1 );
            $item['index']          = $index;
            $item['item_list_name'] = $list_name;
            $items[]                = $item;
            $index++;
        }
        if ( empty( $items ) ) {
            return;
        }
        self::push_datalayer( 'view_item_list', [
            'item_list_name' => $list_name,
            'items'          => $items,
        ] );
    }

    public static function push_view_cart() {
        if ( ! is_cart() ) {
            return;
        }
        list( $items, $value ) = self::get_cart_data();
        if ( empty( $items ) ) {
            return;
        }
        self::push_datalayer( 'view_cart', [
            'currency' => get_woocommerce_currency(),
            'value'    => $value,
            'items'    => $items,
        ] );
    }

    public static function push_begin_checkout() {
        if ( ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
            return;
        }
        list( $items, $value ) = self::get_cart_data();
        if ( empty( $items ) ) {
            return;
        }
        self::push_datalayer( 'begin_checkout', [
            'currency' => get_woocommerce_currency(),
            'value'    => $value,
            'coupon'   => implode( ',', array_keys( WC()->cart->get_applied_coupons() ) ),
            'items'    => $items,
        ] );
    }

    public static function push_purchase() {
        if ( ! is_wc_endpoint_url( 'order-received' ) ) {
            return;
        }
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
        // When "purchase on complete" mode is active, only fire once order is completed.
        if ( get_option( 'coc_purchase_on_complete', '' ) && $order->get_status() !== 'completed' ) {
            return;
        }

        // Prevent duplicate fires when customer refreshes the thank-you page.
        if ( $order->get_meta( '_coc_gtm_purchase_fired' ) ) {
            return;
        }
        $order->update_meta_data( '_coc_gtm_purchase_fired', '1' );
        $order->save();

        $items = [];
        foreach ( $order->get_items() as $order_item ) {
            $product = $order_item->get_product();
            if ( ! $product ) {
                continue;
            }
            $item          = self::get_item_data( $product, $order_item->get_quantity() );
            $item['price'] = (float) $order->get_item_subtotal( $order_item, false, false );
            $items[]       = $item;
        }

        // Include persisted attribution data so server-side GTM tags can forward
        // fbp/fbc to Meta CAPI, ttp to TikTok Events API, ga_client_id + ga_session_id
        // to GA4 MP, and gclid/gbraid/wbraid to Google Ads Conversion Measurement.
        $attribution = array_filter( [
            'fbp'            => (string) $order->get_meta( '_coc_fbp' ),
            'fbc'            => (string) $order->get_meta( '_coc_fbc' ),
            'ttp'            => (string) $order->get_meta( '_coc_ttp' ),
            'ga_client_id'   => (string) $order->get_meta( '_coc_ga_client_id' ),
            'ga_session_id'  => (string) $order->get_meta( '_coc_ga_session_id' ),
            'gclid'          => (string) $order->get_meta( '_coc_gads_gclid' ),
            'gbraid'         => (string) $order->get_meta( '_coc_gads_gbraid' ),
            'wbraid'         => (string) $order->get_meta( '_coc_gads_wbraid' ),
        ] );

        $ecommerce = [
            'transaction_id' => (string) $order->get_order_number(),
            'value'          => (float) $order->get_total(),
            'tax'            => (float) $order->get_total_tax(),
            'shipping'       => (float) $order->get_shipping_total(),
            'currency'       => $order->get_currency(),
            'coupon'         => implode( ',', $order->get_coupon_codes() ),
            'items'          => $items,
        ];

        if ( ! empty( $attribution ) ) {
            $ecommerce['attribution'] = $attribution;
        }

        self::push_datalayer( 'purchase', $ecommerce );
    }

    /* ------------------------------------------------------------------
     * Non-AJAX add_to_cart via WC session
     * ------------------------------------------------------------------ */

    public static function queue_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        // AJAX add_to_cart is handled client-side via the added_to_cart JS event.
        if ( wp_doing_ajax() ) {
            return;
        }
        if ( ! WC()->session ) {
            return;
        }
        $product = wc_get_product( $variation_id ?: $product_id );
        if ( ! $product ) {
            return;
        }
        $item    = self::get_item_data( $product, $quantity );
        $pending = WC()->session->get( 'coc_gtm_pending', [] );
        $pending[] = [
            'event'     => 'add_to_cart',
            'ecommerce' => [
                'currency' => get_woocommerce_currency(),
                'value'    => round( $item['price'] * $quantity, 2 ),
                'items'    => [ $item ],
            ],
        ];
        WC()->session->set( 'coc_gtm_pending', $pending );
    }

    public static function push_pending_events() {
        if ( ! WC()->session ) {
            return;
        }
        $pending = WC()->session->get( 'coc_gtm_pending', [] );
        if ( empty( $pending ) ) {
            return;
        }
        WC()->session->set( 'coc_gtm_pending', [] );

        echo '<script>window.dataLayer=window.dataLayer||[];';
        foreach ( $pending as $ev ) {
            echo 'window.dataLayer.push({ecommerce:null});';
            echo 'window.dataLayer.push(' . wp_json_encode( $ev ) . ');';
        }
        echo '</script>' . "\n";
    }

    /* ------------------------------------------------------------------
     * Frontend script (add_to_cart AJAX, remove_from_cart, shipping,
     * payment events handled in JS)
     * ------------------------------------------------------------------ */

    public static function enqueue_scripts() {
        $pages = is_shop() || is_product_category() || is_product_tag() ||
                 is_product_taxonomy() || is_product() || is_cart() || is_checkout();
        if ( ! $pages ) {
            return;
        }

        $js_file = COC_PLUGIN_DIR . 'assets/js/gtm.js';
        wp_enqueue_script(
            'coc-gtm',
            COC_PLUGIN_URL . 'assets/js/gtm.js',
            [ 'jquery' ],
            file_exists( $js_file ) ? filemtime( $js_file ) : COC_VERSION,
            true
        );

        $data = [
            'currency'   => get_woocommerce_currency(),
            'products'   => [],   // keyed by product ID — used for AJAX add_to_cart.
            'cart_items' => [],   // keyed by cart item key — used for remove_from_cart.
            'checkout'   => null, // used for add_shipping_info / add_payment_info.
        ];

        // Products on listing/single pages (for AJAX add_to_cart button handler).
        if ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) {
            global $wp_query;
            foreach ( $wp_query->posts as $post ) {
                $product = wc_get_product( $post->ID );
                if ( $product ) {
                    $data['products'][ $product->get_id() ] = self::get_item_data( $product, 1 );
                }
            }
        }

        if ( is_product() ) {
            global $post;
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $data['products'][ $product->get_id() ] = self::get_item_data( $product, 1 );
            }
        }

        // Cart items for remove_from_cart & checkout events.
        if ( ( is_cart() || is_checkout() ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $key => $cart_item ) {
                $item = self::get_item_data( $cart_item['data'], (int) $cart_item['quantity'] );
                if ( ! empty( $item ) ) {
                    $data['cart_items'][ $key ] = $item;
                }
            }
        }

        // Checkout ecommerce data for shipping/payment events.
        if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && WC()->cart ) {
            list( $items, $value ) = self::get_cart_data();
            $data['checkout'] = [
                'currency' => get_woocommerce_currency(),
                'value'    => $value,
                'coupon'   => implode( ',', array_keys( WC()->cart->get_applied_coupons() ) ),
                'items'    => $items,
            ];
        }

        wp_localize_script( 'coc-gtm', 'COC_GTM', $data );
    }
}
