<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin settings page under WooCommerce > Courier Check.
 */
class COC_Admin {

    public static function init() {
        add_action( 'admin_menu',           [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init',           [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts',[ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_coc_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
    }

    /* ------------------------------------------------------------------
     * Menu
     * ------------------------------------------------------------------ */

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Courier Order Check', 'courier-order-check' ),
            __( 'Courier Check', 'courier-order-check' ),
            'manage_woocommerce',
            'courier-order-check',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    /* ------------------------------------------------------------------
     * Settings registration
     * ------------------------------------------------------------------ */

    public static function register_settings() {
        register_setting(
            'coc_settings_group',
            'coc_api_key',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        register_setting(
            'coc_settings_group',
            'coc_domain',
            [
                'type'              => 'string',
                'sanitize_callback' => [ __CLASS__, 'sanitize_domain' ],
                'default'           => '',
            ]
        );

        add_settings_section(
            'coc_main_section',
            __( 'API Configuration', 'courier-order-check' ),
            '__return_false',
            'courier-order-check'
        );

        add_settings_field(
            'coc_api_key',
            __( 'API Key', 'courier-order-check' ),
            [ __CLASS__, 'render_api_key_field' ],
            'courier-order-check',
            'coc_main_section'
        );

        add_settings_field(
            'coc_domain',
            __( 'Your Domain', 'courier-order-check' ),
            [ __CLASS__, 'render_domain_field' ],
            'courier-order-check',
            'coc_main_section'
        );
    }

    /**
     * Strip protocol and trailing slashes, keep only the bare host.
     */
    public static function sanitize_domain( $value ) {
        $value = sanitize_text_field( $value );
        $value = preg_replace( '#^https?://#i', '', $value );
        $value = rtrim( $value, '/' );
        return $value;
    }

    /* ------------------------------------------------------------------
     * Field renderers
     * ------------------------------------------------------------------ */

    public static function render_api_key_field() {
        $val = esc_attr( get_option( 'coc_api_key', '' ) );
        echo '<input type="password" id="coc_api_key" name="coc_api_key"
                     class="regular-text" value="' . $val . '"
                     autocomplete="new-password" />';
        echo '<p class="description">' .
             esc_html__( 'Your Bearer token from the Growever dashboard.', 'courier-order-check' ) .
             '</p>';
    }

    public static function render_domain_field() {
        $val = esc_attr( get_option( 'coc_domain', '' ) );
        echo '<input type="text" id="coc_domain" name="coc_domain"
                     class="regular-text" value="' . $val . '"
                     placeholder="example.com" />';
        echo '<p class="description">' .
             esc_html__( 'The domain registered with Growever (no protocol, no trailing slash).', 'courier-order-check' ) .
             '</p>';
    }

    /* ------------------------------------------------------------------
     * Settings page HTML
     * ------------------------------------------------------------------ */

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        ?>
        <div class="wrap coc-settings-wrap">
            <h1><?php esc_html_e( 'Courier Order Check', 'courier-order-check' ); ?></h1>
            <p class="coc-intro">
                <?php esc_html_e( 'Configure your Growever API credentials. The plugin will display courier order success ratios on WooCommerce order detail pages.', 'courier-order-check' ); ?>
            </p>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'coc_settings_group' );
                do_settings_sections( 'courier-order-check' );
                submit_button( __( 'Save Settings', 'courier-order-check' ) );
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Test API Connection', 'courier-order-check' ); ?></h2>
            <p><?php esc_html_e( 'Verify that your API key and domain are correctly configured before using the plugin.', 'courier-order-check' ); ?></p>
            <button id="coc-test-btn" class="button button-secondary">
                <?php esc_html_e( 'Test Connection', 'courier-order-check' ); ?>
            </button>
            <span id="coc-test-spinner" class="spinner" style="float:none; margin-top:0;"></span>
            <div id="coc-test-result" class="coc-test-result" style="display:none;"></div>

            <hr />

            <h2><?php esc_html_e( 'IP Blocklist', 'courier-order-check' ); ?></h2>
            <p><?php esc_html_e( 'Block specific IP addresses to prevent fake or fraudulent orders. Blocked IPs cannot complete checkout.', 'courier-order-check' ); ?></p>

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
                    <?php
                    $blocked = COC_IP_Blocker::get_blocked_ips();
                    if ( empty( $blocked ) ) :
                    ?>
                        <tr id="coc-ip-empty"><td colspan="2" class="coc-ip-empty"><?php esc_html_e( 'No IPs blocked yet.', 'courier-order-check' ); ?></td></tr>
                    <?php
                    else :
                        foreach ( $blocked as $ip ) :
                    ?>
                        <tr data-ip="<?php echo esc_attr( $ip ); ?>">
                            <td><code class="coc-ip-code"><?php echo esc_html( $ip ); ?></code></td>
                            <td><button type="button" class="button button-small coc-unblock-btn" data-ip="<?php echo esc_attr( $ip ); ?>"><?php esc_html_e( 'Unblock', 'courier-order-check' ); ?></button></td>
                        </tr>
                    <?php
                        endforeach;
                    endif;
                    ?>
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

        // Settings page.
        if ( 'woocommerce_page_courier-order-check' === $hook ) {
            wp_enqueue_style( 'coc-admin', COC_PLUGIN_URL . 'assets/css/admin.css', [], $css_ver );
            wp_enqueue_script( 'coc-admin', COC_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], $js_ver, true );
            wp_localize_script( 'coc-admin', 'COC', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'coc_test_connection' ),
                'ip_nonce' => wp_create_nonce( 'coc_ip_block' ),
                'l10n'     => [
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
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }
}
