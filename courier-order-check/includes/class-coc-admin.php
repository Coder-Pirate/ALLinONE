<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin settings page under WooCommerce > Track Cart BD.
 */
class COC_Admin {

    public static function init() {
        add_action( 'admin_menu',           [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init',           [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts',[ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_coc_test_connection',           [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_coc_disconnect',                 [ __CLASS__, 'ajax_disconnect' ] );
        add_action( 'wp_ajax_coc_pathao_admin_connect',     [ __CLASS__, 'ajax_pathao_admin_connect' ] );
        add_action( 'wp_ajax_coc_pathao_admin_get_stores',  [ __CLASS__, 'ajax_pathao_admin_get_stores' ] );
        add_action( 'wp_ajax_coc_sf_admin_check',           [ __CLASS__, 'ajax_sf_admin_check' ] );
    }

    /* ------------------------------------------------------------------
     * Menu
     * ------------------------------------------------------------------ */

    public static function add_menu() {
        // Top-level Track Cart BD menu (renders the main Settings page).
        add_menu_page(
            __( 'Track Cart BD', 'courier-order-check' ),
            __( 'Track Cart BD', 'courier-order-check' ),
            'manage_woocommerce',
            'courier-order-check',
            [ __CLASS__, 'render_settings_page' ],
            'dashicons-cart',
            56
        );

        // First submenu entry mirrors the parent (Settings).
        add_submenu_page(
            'courier-order-check',
            __( 'Track Cart BD Settings', 'courier-order-check' ),
            __( 'Settings', 'courier-order-check' ),
            'manage_woocommerce',
            'courier-order-check',
            [ __CLASS__, 'render_settings_page' ]
        );

        // Only show config submenus when connected.
        if ( ! self::is_api_connected() ) {
            return;
        }

        $submenus = [
            [ 'coc-tracking',    __( 'Tracking',          'courier-order-check' ), 'render_page_tracking'    ],
            [ 'coc-meta',        __( 'Meta Pixel',         'courier-order-check' ), 'render_page_meta'        ],
            [ 'coc-tiktok',      __( 'TikTok Pixel',       'courier-order-check' ), 'render_page_tiktok'      ],
            [ 'coc-ga4',         __( 'Google Analytics',   'courier-order-check' ), 'render_page_ga4'         ],
            [ 'coc-google-ads',  __( 'Google Ads',         'courier-order-check' ), 'render_page_google_ads'  ],
            [ 'coc-gtm',         __( 'Google Tag Manager', 'courier-order-check' ), 'render_page_gtm'         ],
            [ 'coc-fb-catalog', __( 'FB Catalog',         'courier-order-check' ), 'render_page_fb_catalog' ],
            [ 'coc-pathao',       __( 'Pathao Courier',     'courier-order-check' ), 'render_page_pathao'        ],
            [ 'coc-steadfast',    __( 'Steadfast Courier',  'courier-order-check' ), 'render_page_steadfast'     ],
            [ 'coc-cod-restrict', __( 'COD Restriction',    'courier-order-check' ), 'render_page_cod_restrict'  ],
        ];

        foreach ( $submenus as [ $slug, $title, $callback ] ) {
            add_submenu_page(
                'courier-order-check',
                $title . ' — Track Cart BD',
                $title,
                'manage_woocommerce',
                $slug,
                [ __CLASS__, $callback ]
            );
        }

        // IP Blocklist.
        add_submenu_page(
            'courier-order-check',
            __( 'IP Blocklist', 'courier-order-check' ),
            __( 'IP Blocklist', 'courier-order-check' ),
            'manage_woocommerce',
            'coc-ip-blocklist',
            [ __CLASS__, 'render_ip_blocklist_page' ]
        );
    }

    /* ------------------------------------------------------------------
     * Settings registration
     * ------------------------------------------------------------------ */

    public static function register_settings() {
        // ── API Configuration (always registered — main Settings page) ──
        register_setting( 'coc_settings_group', 'coc_api_key', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_api_key' ],
            'default'           => '',
        ] );
        register_setting( 'coc_settings_group', 'coc_domain', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_domain' ],
            'default'           => '',
        ] );

        add_settings_section( 'coc_main_section', __( 'API Configuration', 'courier-order-check' ), '__return_false', 'courier-order-check' );
        add_settings_field( 'coc_api_key', __( 'API Key',     'courier-order-check' ), [ __CLASS__, 'render_api_key_field' ], 'courier-order-check', 'coc_main_section' );
        add_settings_field( 'coc_domain',  __( 'Your Domain', 'courier-order-check' ), [ __CLASS__, 'render_domain_field'  ], 'courier-order-check', 'coc_main_section' );

        // Only register config pages when connected.
        if ( ! self::is_api_connected() ) {
            return;
        }

        // ── Tracking Behaviour (coc-tracking page) ───────────────────
        register_setting( 'coc_tracking_group', 'coc_purchase_on_complete',      [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field',   'default' => '' ] );
        register_setting( 'coc_tracking_group', 'coc_order_lock_enabled',        [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field',   'default' => '' ] );
        register_setting( 'coc_tracking_group', 'coc_order_lock_notice_text',    [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ] );
        register_setting( 'coc_tracking_group', 'coc_order_lock_block_text',     [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ] );

        add_settings_section( 'coc_tracking_behaviour_section', __( 'Tracking Behaviour', 'courier-order-check' ), [ __CLASS__, 'render_tracking_behaviour_desc' ], 'coc-tracking' );
        add_settings_field( 'coc_purchase_on_complete',   __( 'Purchase Event Trigger',       'courier-order-check' ), [ __CLASS__, 'render_purchase_on_complete_field'  ], 'coc-tracking', 'coc_tracking_behaviour_section' );
        add_settings_field( 'coc_order_lock_enabled',     __( 'Order Lock',                   'courier-order-check' ), [ __CLASS__, 'render_order_lock_field'            ], 'coc-tracking', 'coc_tracking_behaviour_section' );
        add_settings_field( 'coc_order_lock_notice_text', __( 'Order Lock — Notice Text',     'courier-order-check' ), [ __CLASS__, 'render_order_lock_notice_text_field' ], 'coc-tracking', 'coc_tracking_behaviour_section' );
        add_settings_field( 'coc_order_lock_block_text',  __( 'Order Lock — Error Text',      'courier-order-check' ), [ __CLASS__, 'render_order_lock_block_text_field'  ], 'coc-tracking', 'coc_tracking_behaviour_section' );

        // ── Meta Pixel (coc-meta page) ────────────────────────────────
        register_setting( 'coc_meta_group', 'coc_pixel_id',        [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( 'coc_meta_group', 'coc_pixel_token',     [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( 'coc_meta_group', 'coc_pixel_test_code', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );

        add_settings_section( 'coc_pixel_section', __( 'Meta Pixel & Conversions API', 'courier-order-check' ), [ __CLASS__, 'render_pixel_section_desc' ], 'coc-meta' );
        add_settings_field( 'coc_pixel_id',        __( 'Pixel ID',          'courier-order-check' ), [ __CLASS__, 'render_pixel_id_field'        ], 'coc-meta', 'coc_pixel_section' );
        add_settings_field( 'coc_pixel_token',     __( 'CAPI Access Token', 'courier-order-check' ), [ __CLASS__, 'render_pixel_token_field'     ], 'coc-meta', 'coc_pixel_section' );
        add_settings_field( 'coc_pixel_test_code', __( 'Test Event Code',   'courier-order-check' ), [ __CLASS__, 'render_pixel_test_code_field' ], 'coc-meta', 'coc_pixel_section' );

        // ── TikTok Pixel (coc-tiktok page) ───────────────────────────
        register_setting( 'coc_tiktok_group', 'coc_ttk_pixel_id',     [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( 'coc_tiktok_group', 'coc_ttk_access_token', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( 'coc_tiktok_group', 'coc_ttk_test_code',    [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );

        add_settings_section( 'coc_ttk_section', __( 'TikTok Pixel & Events API', 'courier-order-check' ), [ __CLASS__, 'render_ttk_section_desc' ], 'coc-tiktok' );
        add_settings_field( 'coc_ttk_pixel_id',     __( 'Pixel ID',         'courier-order-check' ), [ __CLASS__, 'render_ttk_pixel_id_field'     ], 'coc-tiktok', 'coc_ttk_section' );
        add_settings_field( 'coc_ttk_access_token', __( 'Events API Token', 'courier-order-check' ), [ __CLASS__, 'render_ttk_access_token_field' ], 'coc-tiktok', 'coc_ttk_section' );
        add_settings_field( 'coc_ttk_test_code',    __( 'Test Event Code',  'courier-order-check' ), [ __CLASS__, 'render_ttk_test_code_field'    ], 'coc-tiktok', 'coc_ttk_section' );

        // ── Google Analytics 4 (coc-ga4 page) ────────────────────────
        register_setting( 'coc_ga4_group', 'coc_gaa_measurement_id', [ 'type' => 'string', 'sanitize_callback' => [ __CLASS__, 'sanitize_gaa_measurement_id' ], 'default' => '' ] );
        register_setting( 'coc_ga4_group', 'coc_gaa_api_secret',     [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );

        add_settings_section( 'coc_gaa_section', __( 'Google Analytics 4 & Measurement Protocol', 'courier-order-check' ), [ __CLASS__, 'render_gaa_section_desc' ], 'coc-ga4' );
        add_settings_field( 'coc_gaa_measurement_id', __( 'Measurement ID', 'courier-order-check' ), [ __CLASS__, 'render_gaa_measurement_id_field' ], 'coc-ga4', 'coc_gaa_section' );
        add_settings_field( 'coc_gaa_api_secret',     __( 'API Secret',     'courier-order-check' ), [ __CLASS__, 'render_gaa_api_secret_field'     ], 'coc-ga4', 'coc_gaa_section' );

        // ── Google Ads (coc-google-ads page) ─────────────────────────
        register_setting( 'coc_gads_group', 'coc_gads_conversion_id', [ 'type' => 'string', 'sanitize_callback' => [ __CLASS__, 'sanitize_gads_conversion_id' ], 'default' => '' ] );
        register_setting( 'coc_gads_group', 'coc_gads_conv_label',    [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );

        add_settings_section( 'coc_gads_section', __( 'Google Ads Conversion & Remarketing', 'courier-order-check' ), [ __CLASS__, 'render_gads_section_desc' ], 'coc-google-ads' );
        add_settings_field( 'coc_gads_conversion_id', __( 'Conversion ID',    'courier-order-check' ), [ __CLASS__, 'render_gads_conversion_id_field' ], 'coc-google-ads', 'coc_gads_section' );
        add_settings_field( 'coc_gads_conv_label',    __( 'Conversion Label', 'courier-order-check' ), [ __CLASS__, 'render_gads_conv_label_field'    ], 'coc-google-ads', 'coc_gads_section' );

        // ── Google Tag Manager (coc-gtm page) ────────────────────────
        register_setting( 'coc_gtm_group', 'coc_gtm_id', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_gtm_id' ],
            'default'           => '',
        ] );

        add_settings_section( 'coc_gtm_section', __( 'Google Tag Manager', 'courier-order-check' ), [ __CLASS__, 'render_gtm_section_desc' ], 'coc-gtm' );
        add_settings_field( 'coc_gtm_id', __( 'Container ID', 'courier-order-check' ), [ __CLASS__, 'render_gtm_id_field' ], 'coc-gtm', 'coc_gtm_section' );

        // ── Facebook Product Catalog (coc-fb-catalog page) ───────────
        register_setting( 'coc_fb_catalog_group', 'coc_fb_catalog_enabled',     [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( 'coc_fb_catalog_group', 'coc_fb_catalog_condition',   [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'new' ] );
        register_setting( 'coc_fb_catalog_group', 'coc_fb_catalog_include_oos', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );

        add_settings_section( 'coc_fb_catalog_section', __( 'Facebook Product Catalog', 'courier-order-check' ), [ __CLASS__, 'render_fb_catalog_section_desc' ], 'coc-fb-catalog' );
        add_settings_field( 'coc_fb_catalog_enabled',     __( 'Enable Feed',          'courier-order-check' ), [ __CLASS__, 'render_fb_catalog_enabled_field'     ], 'coc-fb-catalog', 'coc_fb_catalog_section' );
        add_settings_field( 'coc_fb_catalog_feed_url',    __( 'Feed URL',             'courier-order-check' ), [ __CLASS__, 'render_fb_catalog_feed_url_field'    ], 'coc-fb-catalog', 'coc_fb_catalog_section' );
        add_settings_field( 'coc_fb_catalog_condition',   __( 'Product Condition',    'courier-order-check' ), [ __CLASS__, 'render_fb_catalog_condition_field'   ], 'coc-fb-catalog', 'coc_fb_catalog_section' );
        add_settings_field( 'coc_fb_catalog_include_oos', __( 'Include Out-of-Stock', 'courier-order-check' ), [ __CLASS__, 'render_fb_catalog_include_oos_field' ], 'coc-fb-catalog', 'coc_fb_catalog_section' );

        // ── Pathao Courier (coc-pathao page) ─────────────────────────
        register_setting( 'coc_pathao_group', 'coc_pathao_env',           [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'sandbox' ] );
        register_setting( 'coc_pathao_group', 'coc_pathao_client_id',     [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( 'coc_pathao_group', 'coc_pathao_client_secret', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( 'coc_pathao_group', 'coc_pathao_username',      [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( 'coc_pathao_group', 'coc_pathao_password',      [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( 'coc_pathao_group', 'coc_pathao_store_id',      [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );

        add_settings_section( 'coc_pathao_section', __( 'Pathao Courier', 'courier-order-check' ), [ __CLASS__, 'render_pathao_section_desc' ], 'coc-pathao' );
        add_settings_field( 'coc_pathao_env',           __( 'Environment',      'courier-order-check' ), [ __CLASS__, 'render_pathao_env_field'           ], 'coc-pathao', 'coc_pathao_section' );
        add_settings_field( 'coc_pathao_client_id',     __( 'Client ID',        'courier-order-check' ), [ __CLASS__, 'render_pathao_client_id_field'     ], 'coc-pathao', 'coc_pathao_section' );
        add_settings_field( 'coc_pathao_client_secret', __( 'Client Secret',    'courier-order-check' ), [ __CLASS__, 'render_pathao_client_secret_field' ], 'coc-pathao', 'coc_pathao_section' );
        add_settings_field( 'coc_pathao_username',      __( 'Email / Username', 'courier-order-check' ), [ __CLASS__, 'render_pathao_username_field'      ], 'coc-pathao', 'coc_pathao_section' );
        add_settings_field( 'coc_pathao_password',      __( 'Password',         'courier-order-check' ), [ __CLASS__, 'render_pathao_password_field'      ], 'coc-pathao', 'coc_pathao_section' );
        add_settings_field( 'coc_pathao_connect',       __( 'Connect',          'courier-order-check' ), [ __CLASS__, 'render_pathao_connect_field'       ], 'coc-pathao', 'coc_pathao_section' );
        add_settings_field( 'coc_pathao_store_id',      __( 'Default Store',    'courier-order-check' ), [ __CLASS__, 'render_pathao_store_id_field'      ], 'coc-pathao', 'coc_pathao_section' );

        // ── Steadfast Courier (coc-steadfast page) ────────────────────
        register_setting( 'coc_steadfast_group', 'coc_sf_api_key',        [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( 'coc_steadfast_group', 'coc_sf_secret_key',     [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
        register_setting( 'coc_steadfast_group', 'coc_sf_webhook_secret', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );

        add_settings_section( 'coc_sf_section', __( 'Steadfast Courier', 'courier-order-check' ), [ __CLASS__, 'render_sf_section_desc' ], 'coc-steadfast' );
        add_settings_field( 'coc_sf_api_key',        __( 'API Key',        'courier-order-check' ), [ __CLASS__, 'render_sf_api_key_field'        ], 'coc-steadfast', 'coc_sf_section' );
        add_settings_field( 'coc_sf_secret_key',     __( 'Secret Key',     'courier-order-check' ), [ __CLASS__, 'render_sf_secret_key_field'     ], 'coc-steadfast', 'coc_sf_section' );
        add_settings_field( 'coc_sf_webhook_secret', __( 'Webhook Secret', 'courier-order-check' ), [ __CLASS__, 'render_sf_webhook_secret_field' ], 'coc-steadfast', 'coc_sf_section' );
        add_settings_field( 'coc_sf_check',          __( 'Test / Balance', 'courier-order-check' ), [ __CLASS__, 'render_sf_check_field'         ], 'coc-steadfast', 'coc_sf_section' );

        // ── COD Restriction (coc-cod-restrict page) ───────────────────
        register_setting( 'coc_cod_restrict_group', 'coc_cod_restrict_enabled',   [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field',    'default' => '' ] );
        register_setting( 'coc_cod_restrict_group', 'coc_cod_restrict_threshold', [ 'type' => 'integer', 'sanitize_callback' => 'absint',                'default' => 60 ] );
        register_setting( 'coc_cod_restrict_group', 'coc_cod_payment_number',     [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field',    'default' => '' ] );
        register_setting( 'coc_cod_restrict_group', 'coc_cod_payment_direction',  [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ] );
        register_setting( 'coc_cod_restrict_group', 'coc_cod_ineligible_msg',     [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ] );
        register_setting( 'coc_cod_restrict_group', 'coc_cod_panel_note',         [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ] );

        add_settings_section( 'coc_cod_restrict_section', __( 'COD Restriction Settings', 'courier-order-check' ), [ __CLASS__, 'render_cod_restrict_section_desc' ], 'coc-cod-restrict' );
        add_settings_field( 'coc_cod_restrict_enabled',   __( 'Enable COD Restriction',   'courier-order-check' ), [ __CLASS__, 'render_cod_restrict_enabled_field'   ], 'coc-cod-restrict', 'coc_cod_restrict_section' );
        add_settings_field( 'coc_cod_restrict_threshold', __( 'Success Rate Threshold',   'courier-order-check' ), [ __CLASS__, 'render_cod_restrict_threshold_field' ], 'coc-cod-restrict', 'coc_cod_restrict_section' );
        add_settings_field( 'coc_cod_ineligible_msg',     __( 'Ineligibility Notice',     'courier-order-check' ), [ __CLASS__, 'render_cod_ineligible_msg_field'     ], 'coc-cod-restrict', 'coc_cod_restrict_section' );
        add_settings_field( 'coc_cod_panel_note',         __( 'Payment Panel Note',       'courier-order-check' ), [ __CLASS__, 'render_cod_panel_note_field'         ], 'coc-cod-restrict', 'coc_cod_restrict_section' );
        add_settings_field( 'coc_cod_payment_number',     __( 'Payment Account Number',   'courier-order-check' ), [ __CLASS__, 'render_cod_payment_number_field'     ], 'coc-cod-restrict', 'coc_cod_restrict_section' );
        add_settings_field( 'coc_cod_payment_direction',  __( 'Payment Instructions',     'courier-order-check' ), [ __CLASS__, 'render_cod_payment_direction_field'  ], 'coc-cod-restrict', 'coc_cod_restrict_section' );
    }

    public static function sanitize_gads_conversion_id( $value ) {
        $value = strtoupper( sanitize_text_field( $value ) );
        if ( ! empty( $value ) && ! preg_match( '/^AW-\d+$/', $value ) ) {
            add_settings_error(
                'coc_gads_conversion_id',
                'invalid_gads_conversion_id',
                __( 'Invalid Google Ads Conversion ID. Expected format: AW-XXXXXXXXXXXXXXXXX', 'courier-order-check' )
            );
            return get_option( 'coc_gads_conversion_id', '' );
        }
        return $value;
    }

    public static function sanitize_gtm_id( $value ) {
        $value = strtoupper( sanitize_text_field( $value ) );
        if ( ! empty( $value ) && ! preg_match( '/^GTM-[A-Z0-9]+$/', $value ) ) {
            add_settings_error(
                'coc_gtm_id',
                'invalid_gtm_id',
                __( 'Invalid GTM Container ID. Expected format: GTM-XXXXXXX', 'courier-order-check' )
            );
            return get_option( 'coc_gtm_id', '' );
        }
        return $value;
    }

    public static function sanitize_gaa_measurement_id( $value ) {
        $value = strtoupper( sanitize_text_field( $value ) );
        if ( ! empty( $value ) && ! preg_match( '/^G-[A-Z0-9]+$/', $value ) ) {
            add_settings_error(
                'coc_gaa_measurement_id',
                'invalid_gaa_measurement_id',
                __( 'Invalid GA4 Measurement ID. Expected format: G-XXXXXXXXXX', 'courier-order-check' )
            );
            return get_option( 'coc_gaa_measurement_id', '' );
        }
        return $value;
    }

    public static function render_tracking_behaviour_desc() {
        echo '<p>' . esc_html__( 'Controls when the Purchase conversion event is sent to GTM, Meta, TikTok, GA4, and Google Ads.', 'courier-order-check' ) . '</p>';
    }

    public static function render_purchase_on_complete_field() {
        $checked = get_option( 'coc_purchase_on_complete', '' ) ? 'checked' : '';
        echo '<label>';
        echo '<input type="checkbox" id="coc_purchase_on_complete" name="coc_purchase_on_complete" value="1" ' . $checked . ' />';
        echo ' ' . esc_html__( 'Count Purchase only when order status changes to “Completed” (set by admin)', 'courier-order-check' );
        echo '</label>';
        echo '<p class="description">' . esc_html__( 'When ✔ active: the server-side Purchase event (Meta CAPI, TikTok Events API) fires when the admin marks the order as Completed. GTM / browser pixel fires if the customer revisits the order-received page after that point. When ✘ inactive (default): all platforms fire at the moment the order is placed.', 'courier-order-check' ) . '</p>';
    }
    public static function render_order_lock_field() {
        $checked = get_option( 'coc_order_lock_enabled', '' ) ? 'checked' : '';
        echo '<label>';
        echo '<input type="checkbox" id="coc_order_lock_enabled" name="coc_order_lock_enabled" value="1" ' . $checked . ' />';
        echo ' ' . esc_html__( 'Prevent customers from placing a new order while a previous order is still in &ldquo;Processing&rdquo; status', 'courier-order-check' );
        echo '</label>';
        echo '<p class="description">' . esc_html__( 'When ✔ active: a customer (logged-in or guest by phone) cannot check out if they already have a Processing order. Once an admin changes that order status (to Completed, Cancelled, etc.) the lock is lifted automatically.', 'courier-order-check' ) . '</p>';
    }

    public static function render_order_lock_notice_text_field() {
        $default = 'You have an order (#{order_number}) that is currently being processed. You will be able to place a new order once your current order status is updated by our team.';
        $val     = get_option( 'coc_order_lock_notice_text', '' );
        echo '<textarea id="coc_order_lock_notice_text" name="coc_order_lock_notice_text" rows="3" class="large-text">' . esc_textarea( $val ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Notice shown at the top of the checkout page. Use {order_number} as a placeholder for the existing order number. Leave blank to use the default.', 'courier-order-check' ) . '</p>';
        echo '<p class="description"><strong>' . esc_html__( 'Default:', 'courier-order-check' ) . '</strong> ' . esc_html( $default ) . '</p>';
    }

    public static function render_order_lock_block_text_field() {
        $default = 'Your order (#{order_number}) is still being processed. Please wait until our team updates your order status before placing a new order.';
        $val     = get_option( 'coc_order_lock_block_text', '' );
        echo '<textarea id="coc_order_lock_block_text" name="coc_order_lock_block_text" rows="3" class="large-text">' . esc_textarea( $val ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Error shown when the customer tries to submit the checkout form. Use {order_number} as a placeholder. Leave blank to use the default.', 'courier-order-check' ) . '</p>';
        echo '<p class="description"><strong>' . esc_html__( 'Default:', 'courier-order-check' ) . '</strong> ' . esc_html( $default ) . '</p>';
    }

    public static function render_gtm_section_desc() {
        echo '<p>' . esc_html__( 'Enter your GTM Container ID to enable GA4 ecommerce event tracking across your WooCommerce store.', 'courier-order-check' ) . '</p>';
    }

    public static function render_gtm_id_field() {
        $val = esc_attr( get_option( 'coc_gtm_id', '' ) );
        echo '<input type="text" id="coc_gtm_id" name="coc_gtm_id" class="regular-text"
                     value="' . $val . '" placeholder="GTM-XXXXXXX" />';
        echo '<p class="description">' .
             esc_html__( 'Find this in your GTM workspace. Example: GTM-ABC1234', 'courier-order-check' ) .
             '</p>';
    }

    public static function render_pixel_section_desc() {
        echo '<p>' . esc_html__( 'Browser pixel + server-side Conversions API (sent directly from your server). Both fire with matching event IDs for accurate deduplication.', 'courier-order-check' ) . '</p>';
    }

    public static function render_pixel_id_field() {
        $val = esc_attr( get_option( 'coc_pixel_id', '' ) );
        echo '<input type="text" id="coc_pixel_id" name="coc_pixel_id" class="regular-text" value="' . $val . '" placeholder="123456789012345" />';
        echo '<p class="description">' . esc_html__( 'Your 15-16 digit Meta Pixel ID from Events Manager.', 'courier-order-check' ) . '</p>';
    }

    public static function render_pixel_token_field() {
        $val = esc_attr( get_option( 'coc_pixel_token', '' ) );
        echo '<input type="password" id="coc_pixel_token" name="coc_pixel_token" class="regular-text" value="' . $val . '" autocomplete="new-password" />';
        echo '<p class="description">' . esc_html__( 'Conversions API access token from Meta Events Manager → Settings → Conversions API.', 'courier-order-check' ) . '</p>';
    }

    public static function render_pixel_test_code_field() {
        $val = esc_attr( get_option( 'coc_pixel_test_code', '' ) );
        echo '<input type="text" id="coc_pixel_test_code" name="coc_pixel_test_code" class="regular-text" value="' . $val . '" placeholder="TEST12345" />';
        echo '<p class="description">' . esc_html__( 'Optional. From Meta Events Manager → Test Events tab. Remove after testing.', 'courier-order-check' ) . '</p>';
    }

    public static function render_ttk_section_desc() {
        echo '<p>' . esc_html__( 'Browser pixel + server-side Events API (sent directly from your server to TikTok). Both fire with matching event IDs for accurate deduplication.', 'courier-order-check' ) . '</p>';
    }

    public static function render_ttk_pixel_id_field() {
        $val = esc_attr( get_option( 'coc_ttk_pixel_id', '' ) );
        echo '<input type="text" id="coc_ttk_pixel_id" name="coc_ttk_pixel_id" class="regular-text" value="' . $val . '" placeholder="ABCDE12345" />';
        echo '<p class="description">' . esc_html__( 'Your TikTok Pixel ID from TikTok Ads Manager → Assets → Events → Web Events.', 'courier-order-check' ) . '</p>';
    }

    public static function render_ttk_access_token_field() {
        $val = esc_attr( get_option( 'coc_ttk_access_token', '' ) );
        echo '<input type="password" id="coc_ttk_access_token" name="coc_ttk_access_token" class="regular-text" value="' . $val . '" autocomplete="new-password" />';
        echo '<p class="description">' . esc_html__( 'Events API access token from TikTok Ads Manager → Assets → Events → Web Events → Set Up Web Events → Events API.', 'courier-order-check' ) . '</p>';
    }

    public static function render_ttk_test_code_field() {
        $val = esc_attr( get_option( 'coc_ttk_test_code', '' ) );
        echo '<input type="text" id="coc_ttk_test_code" name="coc_ttk_test_code" class="regular-text" value="' . $val . '" placeholder="TEST_CODE" />';
        echo '<p class="description">' . esc_html__( 'Optional. From TikTok Events Manager → Test Events. Remove after testing.', 'courier-order-check' ) . '</p>';
    }

    /* ------------------------------------------------------------------
     * GA4 field renderers
     * ------------------------------------------------------------------ */

    public static function render_gaa_section_desc() {
        echo '<p>' . esc_html__( 'gtag.js (browser) + GA4 Measurement Protocol (server-side, sent directly from your server). Key events fire from both sides using the same client_id for accurate session stitching.', 'courier-order-check' ) . '</p>';
    }

    public static function render_gaa_measurement_id_field() {
        $val = esc_attr( get_option( 'coc_gaa_measurement_id', '' ) );
        echo '<input type="text" id="coc_gaa_measurement_id" name="coc_gaa_measurement_id" class="regular-text" value="' . $val . '" placeholder="G-XXXXXXXXXX" />';
        echo '<p class="description">' . esc_html__( 'Your GA4 Measurement ID from Google Analytics → Admin → Data Streams → select stream → Measurement ID.', 'courier-order-check' ) . '</p>';
    }

    public static function render_gaa_api_secret_field() {
        $val = esc_attr( get_option( 'coc_gaa_api_secret', '' ) );
        echo '<input type="password" id="coc_gaa_api_secret" name="coc_gaa_api_secret" class="regular-text" value="' . $val . '" autocomplete="new-password" />';
        echo '<p class="description">' . esc_html__( 'Measurement Protocol API Secret from Google Analytics → Admin → Data Streams → select stream → Measurement Protocol → Create.', 'courier-order-check' ) . '</p>';
    }

    /* ------------------------------------------------------------------
     * Google Ads field renderers
     * ------------------------------------------------------------------ */

    public static function render_gads_section_desc() {
        echo '<p>' . esc_html__( 'Browser-side Google Ads tag (gtag.js) + server-side Conversion Measurement. The Purchase conversion fires from both the browser and your server using the gclid captured from the visitor\'s click for accurate attribution.', 'courier-order-check' ) . '</p>';
        echo '<p>' . esc_html__( 'Dynamic Remarketing events (view_item, add_to_cart, begin_checkout) also fire automatically for audience retargeting.', 'courier-order-check' ) . '</p>';
    }

    public static function render_gads_conversion_id_field() {
        $val = esc_attr( get_option( 'coc_gads_conversion_id', '' ) );
        echo '<input type="text" id="coc_gads_conversion_id" name="coc_gads_conversion_id" class="regular-text" value="' . $val . '" placeholder="AW-XXXXXXXXXXXXXXXXX" />';
        echo '<p class="description">' . esc_html__( 'Your Google Ads Conversion ID. Found in Google Ads → Goals → Conversions → select your Purchase action → Tag setup → Use Google Tag. Format: AW-XXXXXXXXXXXXXXXXX', 'courier-order-check' ) . '</p>';
    }

    public static function render_gads_conv_label_field() {
        $val = esc_attr( get_option( 'coc_gads_conv_label', '' ) );
        echo '<input type="text" id="coc_gads_conv_label" name="coc_gads_conv_label" class="regular-text" value="' . $val . '" placeholder="AbCdEfGhIjKlMnOpQr" />';
        echo '<p class="description">' . esc_html__( 'Your Purchase Conversion Label. Found alongside the Conversion ID in your Google Ads tag setup (Tag setup → Use Google Tag → see the gtag snippet).', 'courier-order-check' ) . '</p>';
    }

    /* ------------------------------------------------------------------
     * Facebook Catalog field renderers
     * ------------------------------------------------------------------ */

    public static function render_fb_catalog_section_desc() {
        echo '<p>' . esc_html__( 'Generates a Google Shopping-compatible XML product feed that Facebook Catalog Ads can crawl. After enabling, copy the Feed URL into Meta Commerce Manager → Data Sources → Add Product Feed.', 'courier-order-check' ) . '</p>';
        echo '<p>' . sprintf(
            /* translators: %s: admin URL */
            esc_html__( 'After enabling or disabling the feed, %s.', 'courier-order-check' ),
            '<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'flush permalink/rewrite rules', 'courier-order-check' ) . '</a>'
        ) . '</p>';
    }

    public static function render_fb_catalog_enabled_field() {
        $checked = get_option( 'coc_fb_catalog_enabled', '' ) ? 'checked' : '';
        echo '<label>';
        echo '<input type="checkbox" id="coc_fb_catalog_enabled" name="coc_fb_catalog_enabled" value="1" ' . $checked . ' />';
        echo ' ' . esc_html__( 'Enable the product feed endpoint', 'courier-order-check' );
        echo '</label>';
    }

    public static function render_fb_catalog_feed_url_field() {
        $url = class_exists( 'COC_FB_Catalog' ) ? COC_FB_Catalog::feed_url() : home_url( '/fb-catalog.xml' );
        echo '<code style="user-select:all;">' . esc_url( $url ) . '</code>';
        echo '<button type="button" class="button button-secondary" style="margin-left:8px;" onclick="navigator.clipboard.writeText(\'' . esc_js( $url ) . '\')">' . esc_html__( 'Copy', 'courier-order-check' ) . '</button>';
        echo '<p class="description">' . esc_html__( 'Paste this URL into Meta Commerce Manager → Data Sources → Add Product Feed → Scheduled Feed.', 'courier-order-check' ) . '</p>';
    }

    public static function render_fb_catalog_condition_field() {
        $val = get_option( 'coc_fb_catalog_condition', 'new' );
        $options = [ 'new' => __( 'New', 'courier-order-check' ), 'refurbished' => __( 'Refurbished', 'courier-order-check' ), 'used' => __( 'Used', 'courier-order-check' ) ];
        echo '<select id="coc_fb_catalog_condition" name="coc_fb_catalog_condition">';
        foreach ( $options as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $val, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Default product condition to report to Facebook (applies to all products unless overridden).', 'courier-order-check' ) . '</p>';
    }

    public static function render_fb_catalog_include_oos_field() {
        $checked = get_option( 'coc_fb_catalog_include_oos', '' ) ? 'checked' : '';
        echo '<label>';
        echo '<input type="checkbox" id="coc_fb_catalog_include_oos" name="coc_fb_catalog_include_oos" value="1" ' . $checked . ' />';
        echo ' ' . esc_html__( 'Include out-of-stock products in the feed (shown as \"out of stock\")', 'courier-order-check' );
        echo '</label>';
    }
    /* ------------------------------------------------------------------
     * Pathao Courier field renderers
     * ------------------------------------------------------------------ */

    public static function render_sf_section_desc() {
        echo '<p>' . esc_html__( 'Steadfast Courier integration. Enter your API Key and Secret Key from the Steadfast portal (portal.packzy.com). Save settings, then click Test / Balance to verify.', 'courier-order-check' ) . '</p>';
        $webhook_url = rest_url( 'coc/v1/sf-webhook' );
        echo '<p>' . esc_html__( 'Webhook URL:', 'courier-order-check' ) . ' <code>' . esc_url( $webhook_url ) . '</code></p>';
    }

    public static function render_sf_api_key_field() {
        $val = esc_attr( get_option( 'coc_sf_api_key', '' ) );
        echo '<input type="text" id="coc_sf_api_key" name="coc_sf_api_key" class="regular-text" value="' . $val . '" autocomplete="off" />';
    }

    public static function render_sf_secret_key_field() {
        $val = esc_attr( get_option( 'coc_sf_secret_key', '' ) );
        echo '<input type="password" id="coc_sf_secret_key" name="coc_sf_secret_key" class="regular-text" value="' . $val . '" autocomplete="new-password" />';
    }

    public static function render_sf_webhook_secret_field() {
        $val = esc_attr( get_option( 'coc_sf_webhook_secret', '' ) );
        echo '<input type="text" id="coc_sf_webhook_secret" name="coc_sf_webhook_secret" class="regular-text" value="' . $val . '" autocomplete="off" />';
        echo '<p class="description">' . esc_html__( 'The Bearer token you configure in the Steadfast portal webhook settings. Used to authenticate incoming webhook requests.', 'courier-order-check' ) . '</p>';
    }

    public static function render_sf_check_field() {
        $nonce = wp_create_nonce( 'coc_steadfast' );
        echo '<button type="button" class="button button-secondary" id="coc-sf-check-btn" data-nonce="' . esc_attr( $nonce ) . '">';
        echo esc_html__( 'Test &amp; Check Balance', 'courier-order-check' );
        echo '</button>';
        echo '<span id="coc-sf-check-result" style="margin-left:12px;font-weight:600;"></span>';
        echo '<p class="description">' . esc_html__( 'Save settings first, then click to verify the connection and see your current balance.', 'courier-order-check' ) . '</p>';
    }

    public static function render_pathao_section_desc() {
        $connected = class_exists( 'COC_Pathao' ) && COC_Pathao::is_connected();
        if ( $connected ) {
            echo '<p style="color:#15803d;font-weight:600;">&#10003; ' . esc_html__( 'Connected to Pathao Courier API.', 'courier-order-check' ) . '</p>';
        } else {
            echo '<p>' . esc_html__( 'Enter your Pathao Merchant API credentials and click Connect. Once connected, a Pathao panel will appear on every order edit page.', 'courier-order-check' ) . '</p>';
        }
    }

    public static function render_pathao_env_field() {
        $val = get_option( 'coc_pathao_env', 'sandbox' );
        echo '<select id="coc_pathao_env" name="coc_pathao_env">';
        echo '<option value="sandbox"'    . selected( $val, 'sandbox',    false ) . '>' . esc_html__( 'Sandbox (Test)', 'courier-order-check' ) . '</option>';
        echo '<option value="production"' . selected( $val, 'production', false ) . '>' . esc_html__( 'Production (Live)', 'courier-order-check' ) . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Use Sandbox for testing. Switch to Production when ready to go live.', 'courier-order-check' ) . '</p>';
    }

    public static function render_pathao_client_id_field() {
        $val = esc_attr( get_option( 'coc_pathao_client_id', '' ) );
        echo '<input type="text" id="coc_pathao_client_id" name="coc_pathao_client_id" class="regular-text" value="' . $val . '" autocomplete="off" />';
    }

    public static function render_pathao_client_secret_field() {
        $val = esc_attr( get_option( 'coc_pathao_client_secret', '' ) );
        echo '<input type="password" id="coc_pathao_client_secret" name="coc_pathao_client_secret" class="regular-text" value="' . $val . '" autocomplete="new-password" />';
    }

    public static function render_pathao_username_field() {
        $val = esc_attr( get_option( 'coc_pathao_username', '' ) );
        echo '<input type="email" id="coc_pathao_username" name="coc_pathao_username" class="regular-text" value="' . $val . '" autocomplete="off" />';
    }

    public static function render_pathao_password_field() {
        $val = esc_attr( get_option( 'coc_pathao_password', '' ) );
        echo '<input type="password" id="coc_pathao_password" name="coc_pathao_password" class="regular-text" value="' . $val . '" autocomplete="new-password" />';
        echo '<p class="description">' . esc_html__( 'Password is stored encrypted in the WP options table.', 'courier-order-check' ) . '</p>';
    }

    public static function render_pathao_connect_field() {
        $nonce = wp_create_nonce( 'coc_pathao' );
        echo '<button type="button" class="button button-secondary" id="coc-pathao-connect-btn" data-nonce="' . esc_attr( $nonce ) . '">';
        echo esc_html__( 'Save &amp; Connect', 'courier-order-check' );
        echo '</button>';
        echo '<span id="coc-pathao-connect-result" style="margin-left:12px;font-weight:600;"></span>';
        echo '<p class="description">' . esc_html__( 'Save settings first, then click Connect to issue an access token.', 'courier-order-check' ) . '</p>';
    }

    public static function render_pathao_store_id_field() {
        $val     = esc_attr( get_option( 'coc_pathao_store_id', '' ) );
        $nonce   = wp_create_nonce( 'coc_pathao' );
        echo '<select id="coc_pathao_store_id" name="coc_pathao_store_id">';
        if ( $val ) {
            echo '<option value="' . $val . '" selected>' . esc_html__( '(stored)', 'courier-order-check' ) . ' ID: ' . $val . '</option>';
        } else {
            echo '<option value="">' . esc_html__( '— Connect first, then refresh —', 'courier-order-check' ) . '</option>';
        }
        echo '</select>';
        echo ' <button type="button" class="button" id="coc-pathao-load-stores-btn" data-nonce="' . esc_attr( $nonce ) . '">' . esc_html__( 'Load Stores', 'courier-order-check' ) . '</button>';
        echo '<p class="description">' . esc_html__( 'Selected store becomes the default pickup location on order panels.', 'courier-order-check' ) . '</p>';
    }

    /* ------------------------------------------------------------------
     * Connection gate helper
     * ------------------------------------------------------------------ */

    public static function is_api_connected() {
        return '1' === get_option( 'coc_api_connected', '' );
    }

    /**
     * Check whether the API response body signals an inactive account.
     *
     * @param  array $data Decoded JSON from the API.
     * @return string|false  Error message if inactive, false otherwise.
     */
    private static function detect_inactive_account( $data ) {
        if ( ! is_array( $data ) ) {
            return false;
        }
        $active  = isset( $data['active'] )  ? $data['active']  : null;
        $status  = isset( $data['status'] )  ? $data['status']  : null;
        $success = isset( $data['success'] ) ? $data['success'] : null;
        $message = isset( $data['message'] ) ? $data['message'] : '';

        $body_inactive = ( $active === false || $active === 0 || $active === '0' )
                      || ( $status  !== null && strtolower( (string) $status  ) === 'inactive' )
                      || ( $success === false || $success === 0 )
                      || ( stripos( $message, 'inactive' ) !== false );

        if ( ! $body_inactive ) {
            return false;
        }

        return $message !== ''
            ? $message
            : __( 'Your account is inactive. Please contact an administrator.', 'courier-order-check' );
    }

    /**
     * Re-verify the API connection (throttled to once every 5 minutes).
     * Called synchronously from coc_init() before feature classes are
     * initialised, so an inactive account blocks everything immediately.
     */
    public static function maybe_recheck_connection() {
        if ( get_transient( 'coc_connection_recheck' ) ) {
            return;
        }
        set_transient( 'coc_connection_recheck', 1, 5 * MINUTE_IN_SECONDS );

        $result = COC_API::check_connection();

        if ( is_wp_error( $result ) ) {
            update_option( 'coc_api_connected', '' );
            update_option( 'coc_api_last_error', $result->get_error_message() );
            delete_transient( 'coc_connection_recheck' );
            return;
        }

        $inactive_msg = self::detect_inactive_account( $result );
        if ( $inactive_msg !== false ) {
            update_option( 'coc_api_connected', '' );
            update_option( 'coc_api_last_error', $inactive_msg );
            delete_transient( 'coc_connection_recheck' );
        }
    }

    /* ------------------------------------------------------------------
     * Sanitize callbacks
     * ------------------------------------------------------------------ */

    public static function sanitize_api_key( $value ) {
        // Changing key resets connection status.
        $value = sanitize_text_field( $value );
        if ( $value !== get_option( 'coc_api_key', '' ) ) {
            update_option( 'coc_api_connected', '' );
            delete_option( 'coc_api_last_error' );
            delete_transient( 'coc_connection_recheck' );
        }
        return $value;
    }

    public static function sanitize_domain( $value ) {
        $value = sanitize_text_field( $value );
        $value = preg_replace( '#^https?://#i', '', $value );
        $value = rtrim( $value, '/' );
        if ( $value !== get_option( 'coc_domain', '' ) ) {
            update_option( 'coc_api_connected', '' );
            delete_option( 'coc_api_last_error' );
            delete_transient( 'coc_connection_recheck' );
        }
        return $value;
    }

    /* ------------------------------------------------------------------
     * Field renderers — API config
     * ------------------------------------------------------------------ */

    public static function render_api_key_field() {
        $val = esc_attr( get_option( 'coc_api_key', '' ) );
        echo '<input type="password" id="coc_api_key" name="coc_api_key" class="regular-text" value="' . $val . '" autocomplete="new-password" />';
        echo '<p class="description">' . esc_html__( 'Your Bearer token from the Track Cart BD dashboard.', 'courier-order-check' ) . '</p>';
    }

    public static function render_domain_field() {
        $val = esc_attr( get_option( 'coc_domain', '' ) );
        echo '<input type="text" id="coc_domain" name="coc_domain" class="regular-text" value="' . $val . '" placeholder="example.com" />';
        echo '<p class="description">' . esc_html__( 'The domain registered with Track Cart BD (no protocol, no trailing slash).', 'courier-order-check' ) . '</p>';
    }

    /* ------------------------------------------------------------------
     * Settings page HTML
     * ------------------------------------------------------------------ */

    /** Shared helper — wraps a settings form for a given option group + page slug. */
    private static function render_config_page( $title, $option_group, $page_slug, $submit_label = null ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $submit_label = $submit_label ?: __( 'Save Settings', 'courier-order-check' );
        ?>
        <div class="wrap coc-settings-wrap">
            <h1><?php echo esc_html( $title ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( $option_group );
                do_settings_sections( $page_slug );
                submit_button( $submit_label );
                ?>
            </form>
        </div>
        <?php
    }

    public static function render_page_tracking() {
        self::render_config_page( __( 'Tracking Behaviour', 'courier-order-check' ), 'coc_tracking_group', 'coc-tracking' );
    }

    public static function render_page_meta() {
        self::render_config_page( __( 'Meta Pixel & Conversions API', 'courier-order-check' ), 'coc_meta_group', 'coc-meta' );
    }

    public static function render_page_tiktok() {
        self::render_config_page( __( 'TikTok Pixel & Events API', 'courier-order-check' ), 'coc_tiktok_group', 'coc-tiktok' );
    }

    public static function render_page_ga4() {
        self::render_config_page( __( 'Google Analytics 4', 'courier-order-check' ), 'coc_ga4_group', 'coc-ga4' );
    }

    public static function render_page_google_ads() {
        self::render_config_page( __( 'Google Ads', 'courier-order-check' ), 'coc_gads_group', 'coc-google-ads' );
    }

    public static function render_page_gtm() {
        self::render_config_page( __( 'Google Tag Manager', 'courier-order-check' ), 'coc_gtm_group', 'coc-gtm' );
    }

    public static function render_page_fb_catalog() {
        self::render_config_page( __( 'Facebook Product Catalog', 'courier-order-check' ), 'coc_fb_catalog_group', 'coc-fb-catalog' );
    }

    public static function render_page_pathao() {
        self::render_config_page( __( 'Pathao Courier', 'courier-order-check' ), 'coc_pathao_group', 'coc-pathao' );
    }

    public static function render_page_steadfast() {
        self::render_config_page( __( 'Steadfast Courier', 'courier-order-check' ), 'coc_steadfast_group', 'coc-steadfast' );
    }

    public static function render_page_cod_restrict() {
        self::render_config_page( __( 'COD Restriction', 'courier-order-check' ), 'coc_cod_restrict_group', 'coc-cod-restrict' );
    }

    public static function render_cod_restrict_section_desc() {
        echo '<p>' . esc_html__( 'Automatically disable Cash on Delivery for customers whose courier success rate falls below the threshold. They will be shown a payment panel to upload advance payment proof.', 'courier-order-check' ) . '</p>';
    }

    public static function render_cod_restrict_enabled_field() {
        $checked = get_option( 'coc_cod_restrict_enabled', '' ) ? 'checked' : '';
        echo '<label>';
        echo '<input type="checkbox" id="coc_cod_restrict_enabled" name="coc_cod_restrict_enabled" value="1" ' . $checked . ' />';
        echo ' ' . esc_html__( 'Enable COD Restriction based on courier success rate', 'courier-order-check' );
        echo '</label>';
    }

    public static function render_cod_restrict_threshold_field() {
        $current = (int) get_option( 'coc_cod_restrict_threshold', 60 );
        echo '<select id="coc_cod_restrict_threshold" name="coc_cod_restrict_threshold">';
        for ( $val = 10; $val <= 100; $val += 10 ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $current, $val, false ) . '>' . esc_html( $val ) . '%</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Customers with a success rate below this percentage will be blocked from COD.', 'courier-order-check' ) . '</p>';
    }

    public static function render_cod_payment_number_field() {
        $val = esc_attr( get_option( 'coc_cod_payment_number', '' ) );
        echo '<input type="text" id="coc_cod_payment_number" name="coc_cod_payment_number" class="regular-text" value="' . $val . '" placeholder="01XXXXXXXXX" />';
        echo '<p class="description">' . esc_html__( 'bKash / Nagad / Rocket number customers should send payment to. Displayed prominently in the payment panel.', 'courier-order-check' ) . '</p>';
    }

    public static function render_cod_payment_direction_field() {
        $val = get_option( 'coc_cod_payment_direction', '' );
        echo '<textarea id="coc_cod_payment_direction" name="coc_cod_payment_direction" rows="3" class="large-text">' . esc_textarea( $val ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Sub-text shown below the "Send to:" line. E.g. "Send payment and enter details below:".', 'courier-order-check' ) . '</p>';
    }

    public static function render_cod_ineligible_msg_field() {
        $val     = get_option( 'coc_cod_ineligible_msg', '' );
        $default = 'You are not eligible for Cash on Delivery due to your low delivery success rate. Please make an advance payment to place your order.';
        echo '<textarea id="coc_cod_ineligible_msg" name="coc_cod_ineligible_msg" rows="3" class="large-text">' . esc_textarea( $val ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Notice shown above the checkout form when COD is blocked (orange/red box). Leave blank to use the default.', 'courier-order-check' ) . '</p>';
        echo '<p class="description"><strong>' . esc_html__( 'Default:', 'courier-order-check' ) . '</strong> ' . esc_html( $default ) . '</p>';
    }

    public static function render_cod_panel_note_field() {
        $val     = get_option( 'coc_cod_panel_note', '' );
        $default = 'You are not eligible for Cash on Delivery. Please pay in advance using the account below and upload your payment screenshot to complete your order.';
        echo '<textarea id="coc_cod_panel_note" name="coc_cod_panel_note" rows="3" class="large-text">' . esc_textarea( $val ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Custom text shown inside the blue payment panel. Leave blank to use the default.', 'courier-order-check' ) . '</p>';
        echo '<p class="description"><strong>' . esc_html__( 'Default:', 'courier-order-check' ) . '</strong> ' . esc_html( $default ) . '</p>';
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $connected = self::is_api_connected();
        ?>
        <div class="wrap coc-settings-wrap">
            <h1><?php esc_html_e( 'Track Cart BD', 'courier-order-check' ); ?></h1>

            <!-- ── Track Cart BD Get Started Card ──────────────────────── -->
            <div class="coc-growever-card">
                <div class="coc-growever-card__logo">
                    <span class="dashicons dashicons-cart" style="font-size:36px;width:36px;height:36px;color:#2563eb;"></span>
                </div>
                <div class="coc-growever-card__body">
                    <h2 style="margin:0 0 6px;font-size:18px;color:#1e3a5f;"><?php esc_html_e( 'Get Started with Track Cart BD', 'courier-order-check' ); ?></h2>
                    <p style="margin:0 0 14px;color:#4b5563;">
                        <?php esc_html_e( 'Create a free account on the Track Cart BD platform to get your API key, access courier success ratio data, and unlock all features of this plugin.', 'courier-order-check' ); ?>
                    </p>
                    <div class="coc-growever-card__actions">
                        <a href="https://app.growever.bd/" target="_blank" rel="noopener noreferrer" class="button button-primary coc-growever-btn">
                            &#127758; <?php esc_html_e( 'Create Account / Get API Key', 'courier-order-check' ); ?>
                        </a>
                        <a href="https://wa.me/8801518401677" target="_blank" rel="noopener noreferrer" class="button coc-growever-btn coc-growever-btn--wa">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" style="vertical-align:middle;margin-right:4px;"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            <?php esc_html_e( 'WhatsApp: 01518401677', 'courier-order-check' ); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── API Configuration form (always visible) ─────────── -->
            <form method="post" action="options.php">
                <?php
                settings_fields( 'coc_settings_group' );
                do_settings_sections( 'courier-order-check' );
                submit_button( __( 'Save Settings', 'courier-order-check' ) );
                ?>
            </form>

            <?php if ( ! $connected ) : ?>
            <!-- ── Inactive / disconnected error notice ──────────── -->
            <?php $last_error = get_option( 'coc_api_last_error', '' ); ?>
            <?php if ( $last_error ) : ?>
            <div class="notice notice-error" style="margin:16px 0;padding:12px 16px;">
                <p><strong><?php esc_html_e( 'Track Cart BD API disconnected:', 'courier-order-check' ); ?></strong>
                <?php echo esc_html( $last_error ); ?></p>
                <p style="color:#555;margin:4px 0 0;"><?php esc_html_e( 'All plugin features are disabled. Update your API Key / Domain and click Test Connection to restore access.', 'courier-order-check' ); ?></p>
            </div>
            <?php endif; ?>
            <!-- ── Test Connection (shown when not yet connected) ── -->
            <div class="coc-connect-box">
                <h2 style="margin:0 0 6px;"><?php esc_html_e( 'Verify Connection', 'courier-order-check' ); ?></h2>
                <p style="margin:0 0 12px;color:#4b5563;"><?php esc_html_e( 'Save your API Key and Domain above, then click Test Connection to unlock all plugin features.', 'courier-order-check' ); ?></p>
                <button id="coc-test-btn" class="button button-secondary"><?php esc_html_e( 'Test Connection', 'courier-order-check' ); ?></button>
                <span id="coc-test-spinner" class="spinner" style="float:none;margin-top:0;"></span>
                <div id="coc-test-result" class="coc-test-result" style="display:none;"></div>
            </div>
            <?php else : ?>
            <div class="coc-connected-notice">
                <span class="dashicons dashicons-yes-alt" style="color:#16a34a;font-size:20px;vertical-align:middle;margin-right:6px;"></span>
                <strong style="color:#15803d;"><?php esc_html_e( 'Connected to Track Cart BD API.', 'courier-order-check' ); ?></strong>
                <a href="#" id="coc-disconnect-btn" style="margin-left:14px;color:#dc2626;font-size:12px;"><?php esc_html_e( 'Disconnect', 'courier-order-check' ); ?></a>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * IP Blocklist page HTML
     * ------------------------------------------------------------------ */

    public static function render_ip_blocklist_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $blocked = COC_IP_Blocker::get_blocked_ips();
        ?>
        <div class="wrap coc-settings-wrap">
            <h1><?php esc_html_e( 'IP Blocklist', 'courier-order-check' ); ?></h1>
            <p class="coc-intro"><?php esc_html_e( 'Block specific IP addresses to prevent fake or fraudulent orders. Blocked IPs will see an error message and cannot complete checkout.', 'courier-order-check' ); ?></p>

            <div class="coc-ip-add-row">
                <input type="text" id="coc-ip-input" class="regular-text" placeholder="e.g. 103.73.47.13" />
                <button type="button" id="coc-ip-add-btn" class="button coc-ip-add-btn">
                    <?php esc_html_e( '+ Block IP', 'courier-order-check' ); ?>
                </button>
                <span id="coc-ip-msg" class="coc-ip-msg" style="display:none;"></span>
            </div>

            <table class="coc-ip-table widefat" id="coc-ip-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Blocked IP Address', 'courier-order-check' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'Action', 'courier-order-check' ); ?></th>
                    </tr>
                </thead>
                <tbody id="coc-ip-list">
                    <?php if ( empty( $blocked ) ) : ?>
                        <tr id="coc-ip-empty"><td colspan="2" class="coc-ip-empty"><?php esc_html_e( 'No IPs blocked yet.', 'courier-order-check' ); ?></td></tr>
                    <?php else : foreach ( $blocked as $ip ) : ?>
                        <tr data-ip="<?php echo esc_attr( $ip ); ?>">
                            <td><code class="coc-ip-code"><?php echo esc_html( $ip ); ?></code></td>
                            <td><button type="button" class="button button-small coc-unblock-btn" data-ip="<?php echo esc_attr( $ip ); ?>"><?php esc_html_e( 'Unblock', 'courier-order-check' ); ?></button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------ */

    public static function enqueue_assets( $hook ) {
        $css_ver = file_exists( COC_PLUGIN_DIR . 'assets/css/admin.css' ) ? filemtime( COC_PLUGIN_DIR . 'assets/css/admin.css' ) : COC_VERSION;
        $js_ver  = file_exists( COC_PLUGIN_DIR . 'assets/js/admin.js' )  ? filemtime( COC_PLUGIN_DIR . 'assets/js/admin.js' )  : COC_VERSION;

        // All Track Cart BD admin pages that need CSS + JS.
        $plugin_hooks = [
            'toplevel_page_courier-order-check',
            'track-cart-bd_page_coc-tracking',
            'track-cart-bd_page_coc-meta',
            'track-cart-bd_page_coc-tiktok',
            'track-cart-bd_page_coc-ga4',
            'track-cart-bd_page_coc-google-ads',
            'track-cart-bd_page_coc-gtm',
            'track-cart-bd_page_coc-fb-catalog',
            'track-cart-bd_page_coc-pathao',
            'track-cart-bd_page_coc-steadfast',
            'track-cart-bd_page_coc-ip-blocklist',
        ];

        if ( in_array( $hook, $plugin_hooks, true ) ) {
            wp_enqueue_style( 'coc-admin', COC_PLUGIN_URL . 'assets/css/admin.css', [], $css_ver );
            wp_enqueue_script( 'coc-admin', COC_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], $js_ver, true );
            wp_localize_script( 'coc-admin', 'COC', [
                'ajax_url'     => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'coc_test_connection' ),
                'ip_nonce'     => wp_create_nonce( 'coc_ip_block' ),
                'pathao_nonce' => wp_create_nonce( 'coc_pathao' ),
                'sf_nonce'     => wp_create_nonce( 'coc_steadfast' ),
                'l10n'         => [
                    'testing'  => __( 'Testing…', 'courier-order-check' ),
                    'test_btn' => __( 'Test Connection', 'courier-order-check' ),
                ],
            ] );
        }

        // Order detail pages.
        if ( in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) {
            wp_enqueue_style( 'coc-admin', COC_PLUGIN_URL . 'assets/css/admin.css', [], $css_ver );
        }
    }

    /* ------------------------------------------------------------------
     * AJAX — test connection
     * ------------------------------------------------------------------ */

    public static function ajax_test_connection() {
        check_ajax_referer( 'coc_test_connection', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'courier-order-check' ) ] );
        }
        $result = COC_API::check_connection();
        if ( is_wp_error( $result ) ) {
            update_option( 'coc_api_connected', '' );
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        // Block even HTTP-200 responses that indicate an inactive account.
        $inactive_msg = self::detect_inactive_account( $result );
        if ( $inactive_msg !== false ) {
            update_option( 'coc_api_connected', '' );
            wp_send_json_error( [ 'message' => $inactive_msg ] );
        }
        update_option( 'coc_api_connected', '1' );
        delete_option( 'coc_api_last_error' );
        delete_transient( 'coc_connection_recheck' );
        wp_send_json_success( $result );
    }

    public static function ajax_disconnect() {
        check_ajax_referer( 'coc_test_connection', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }
        update_option( 'coc_api_connected', '' );
        delete_option( 'coc_api_last_error' );
        delete_transient( 'coc_connection_recheck' );
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
     * Pathao — admin settings page AJAX (uses coc_pathao nonce)
     * ------------------------------------------------------------------ */

    public static function ajax_pathao_admin_connect() {
        if ( ! check_ajax_referer( 'coc_pathao', 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }
        if ( ! class_exists( 'COC_Pathao' ) ) {
            wp_send_json_error( [ 'message' => 'Pathao class not loaded.' ] );
        }
        // Accept form values directly so the user doesn't have to save before connecting.
        $client_id     = sanitize_text_field( $_POST['client_id']     ?? '' );
        $client_secret = sanitize_text_field( $_POST['client_secret'] ?? '' );
        $username      = sanitize_text_field( $_POST['username']       ?? '' );
        $password      = sanitize_text_field( $_POST['password']       ?? '' );
        $env           = sanitize_text_field( $_POST['env']            ?? '' );
        $result = COC_Pathao::issue_token_from_credentials( $client_id, $client_secret, $username, $password, $env );
        if ( $result['ok'] ) {
            wp_send_json_success( [ 'message' => 'Connected to Pathao successfully!' ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ?: 'Connection failed.' ] );
        }
    }

    public static function ajax_pathao_admin_get_stores() {
        if ( ! check_ajax_referer( 'coc_pathao', 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }
        if ( ! class_exists( 'COC_Pathao' ) ) {
            wp_send_json_error( [ 'message' => 'Pathao class not loaded.' ] );
        }
        $r = COC_Pathao::get_stores();
        if ( $r['ok'] ) {
            wp_send_json_success( $r['data']['data'] ?? $r['data'] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    /* ------------------------------------------------------------------
     * Steadfast — admin settings page AJAX
     * ------------------------------------------------------------------ */

    public static function ajax_sf_admin_check() {
        if ( ! check_ajax_referer( 'coc_steadfast', 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }
        if ( ! class_exists( 'COC_Steadfast' ) ) {
            wp_send_json_error( [ 'message' => 'Steadfast class not loaded.' ] );
        }
        // Use form values directly so the user doesn't have to save before testing.
        $api_key    = sanitize_text_field( $_POST['api_key']    ?? '' ) ?: get_option( 'coc_sf_api_key', '' );
        $secret_key = sanitize_text_field( $_POST['secret_key'] ?? '' ) ?: get_option( 'coc_sf_secret_key', '' );
        $r = COC_Steadfast::get_balance_with( $api_key, $secret_key );
        if ( $r['ok'] ) {
            // Persist credentials so the order panel becomes visible immediately.
            update_option( 'coc_sf_api_key',    $api_key );
            update_option( 'coc_sf_secret_key', $secret_key );
            wp_send_json_success( [ 'balance' => $r['data']['current_balance'] ?? 0 ] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ?: 'Connection failed.' ] );
        }
    }
}
