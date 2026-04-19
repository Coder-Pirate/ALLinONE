<?php
defined( 'ABSPATH' ) || exit;

/**
 * Export WooCommerce order data to CSV (Excel / Google Sheets compatible).
 *
 * Features:
 *   - Date-range filter
 *   - Export all orders
 *   - Exports: Order #, Date, Status, Billing Name, Billing Phone,
 *     Billing Address, Shipping Address, Total.
 */
class COC_Export_Orders {

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'add_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_post_coc_export_csv', [ __CLASS__, 'handle_export' ] );
    }

    /* ------------------------------------------------------------------
     * Submenu under Track Cart BD
     * ------------------------------------------------------------------ */

    public static function add_submenu() {
        add_submenu_page(
            'courier-order-check',
            __( 'Export Orders', 'courier-order-check' ),
            __( 'Export Orders', 'courier-order-check' ),
            'manage_woocommerce',
            'coc-export-orders',
            [ __CLASS__, 'render_page' ]
        );
    }

    /* ------------------------------------------------------------------
     * Assets — only on our page
     * ------------------------------------------------------------------ */

    public static function enqueue_assets( $hook ) {
        if ( 'track-cart-bd_page_coc-export-orders' !== $hook ) {
            return;
        }
        $css_ver = file_exists( COC_PLUGIN_DIR . 'assets/css/admin.css' )
            ? filemtime( COC_PLUGIN_DIR . 'assets/css/admin.css' )
            : COC_VERSION;
        wp_enqueue_style( 'coc-admin', COC_PLUGIN_URL . 'assets/css/admin.css', [], $css_ver );
    }

    /* ------------------------------------------------------------------
     * Render settings / preview page
     * ------------------------------------------------------------------ */

    public static function render_page() {
        $date_from  = isset( $_GET['date_from'] )  ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) )  : '';
        $date_to    = isset( $_GET['date_to'] )     ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )    : '';
        $per_page   = 20;
        $paged      = max( 1, absint( $_GET['paged'] ?? 1 ) );

        // Query args
        $args = self::build_query_args( $date_from, $date_to, $per_page, $paged );
        $args['paginate'] = true;
        $results      = wc_get_orders( $args );
        $orders       = $results->orders;
        $total_orders = $results->total;
        $total_pages  = $results->max_num_pages;

        $nonce = wp_create_nonce( 'coc_export_orders' );
        ?>
        <div class="wrap coc-wrap">
            <h1><?php esc_html_e( 'Export Orders', 'courier-order-check' ); ?></h1>

            <!-- Filter bar -->
            <div class="coc-export-bar">
                <form method="get" class="coc-export-filter">
                    <input type="hidden" name="page" value="coc-export-orders" />

                    <label for="date_from"><?php esc_html_e( 'From:', 'courier-order-check' ); ?></label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />

                    <label for="date_to"><?php esc_html_e( 'To:', 'courier-order-check' ); ?></label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />

                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'courier-order-check' ); ?></button>

                    <?php if ( $date_from || $date_to ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=coc-export-orders' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'courier-order-check' ); ?></a>
                    <?php endif; ?>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coc-export-download">
                    <input type="hidden" name="action" value="coc_export_csv" />
                    <input type="hidden" name="coc_export_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
                    <input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
                    <input type="hidden" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
                    <button type="submit" class="button button-primary">
                        📥 <?php esc_html_e( 'Download CSV', 'courier-order-check' ); ?>
                        <?php if ( $date_from || $date_to ) : ?>
                            (<?php echo esc_html( ( $date_from ?: '…' ) . ' → ' . ( $date_to ?: '…' ) ); ?>)
                        <?php else : ?>
                            (<?php esc_html_e( 'All Orders', 'courier-order-check' ); ?>)
                        <?php endif; ?>
                    </button>
                </form>
            </div>

            <p class="description" style="margin:10px 0;">
                <?php
                printf(
                    /* translators: %d total order count */
                    esc_html__( 'Showing %d order(s) matching your filter.', 'courier-order-check' ),
                    $total_orders
                );
                ?>
            </p>

            <!-- Preview table -->
            <table class="widefat striped coc-export-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Order #', 'courier-order-check' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'courier-order-check' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'courier-order-check' ); ?></th>
                        <th><?php esc_html_e( 'Product(s)', 'courier-order-check' ); ?></th>
                        <th><?php esc_html_e( 'Billing Name', 'courier-order-check' ); ?></th>
                        <th><?php esc_html_e( 'Phone', 'courier-order-check' ); ?></th>
                        <th><?php esc_html_e( 'Billing Address', 'courier-order-check' ); ?></th>
                        <th><?php esc_html_e( 'Shipping Address', 'courier-order-check' ); ?></th>
                        <th><?php esc_html_e( 'Total', 'courier-order-check' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $orders ) ) : ?>
                        <tr><td colspan="9" style="text-align:center;"><?php esc_html_e( 'No orders found.', 'courier-order-check' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $orders as $order ) :
                            $bill_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                            $bill_phone = $order->get_billing_phone();
                            $bill_addr  = self::format_address(
                                $order->get_billing_address_1(),
                                $order->get_billing_address_2(),
                                $order->get_billing_city(),
                                $order->get_billing_state(),
                                $order->get_billing_postcode(),
                                $order->get_billing_country()
                            );
                            $ship_addr = self::format_address(
                                $order->get_shipping_address_1(),
                                $order->get_shipping_address_2(),
                                $order->get_shipping_city(),
                                $order->get_shipping_state(),
                                $order->get_shipping_postcode(),
                                $order->get_shipping_country()
                            );
                            if ( ! $ship_addr ) {
                                $ship_addr = $bill_addr;
                            }
                            ?>
                            <tr>
                                <td>#<?php echo esc_html( $order->get_order_number() ); ?></td>
                                <td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '—' ); ?></td>
                                <td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
                                <td><?php echo esc_html( self::get_product_names( $order ) ); ?></td>
                                <td><?php echo esc_html( $bill_name ); ?></td>
                                <td><?php echo esc_html( $bill_phone ); ?></td>
                                <td><?php echo esc_html( $bill_addr ); ?></td>
                                <td><?php echo esc_html( $ship_addr ); ?></td>
                                <td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        $base_url = admin_url( 'admin.php?page=coc-export-orders' );
                        if ( $date_from ) $base_url = add_query_arg( 'date_from', $date_from, $base_url );
                        if ( $date_to )   $base_url = add_query_arg( 'date_to',   $date_to,   $base_url );

                        echo wp_kses_post( paginate_links( [
                            'base'    => add_query_arg( 'paged', '%#%', $base_url ),
                            'format'  => '',
                            'current' => $paged,
                            'total'   => $total_pages,
                            'type'    => 'plain',
                        ] ) );
                        ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Handle CSV download (POST)
     * ------------------------------------------------------------------ */

    public static function handle_export() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'courier-order-check' ) );
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['coc_export_nonce'] ?? '' ) ), 'coc_export_orders' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'courier-order-check' ) );
        }

        $date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
        $date_to   = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );

        // Build filename
        $parts = [ 'orders' ];
        if ( $date_from ) $parts[] = 'from-' . $date_from;
        if ( $date_to )   $parts[] = 'to-'   . $date_to;
        $filename = implode( '_', $parts ) . '.csv';

        // Clear any output buffers to prevent "headers already sent" errors
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        // Raise limits for large exports
        @set_time_limit( 300 );
        wp_raise_memory_limit( 'admin' );

        // Headers
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        // UTF-8 BOM for Excel compatibility
        fwrite( $out, "\xEF\xBB\xBF" );

        // CSV header row
        fputcsv( $out, [
            'Order #',
            'Date',
            'Status',
            'Product(s)',
            'Billing Name',
            'Billing Phone',
            'Billing Address',
            'Shipping Address',
            'Payment Method',
            'Total',
        ] );

        // Process in batches of 100 to avoid memory exhaustion
        $batch_size = 100;
        $page       = 1;

        do {
            $args   = self::build_query_args( $date_from, $date_to, $batch_size, $page );
            $orders = wc_get_orders( $args );

            foreach ( $orders as $order ) {
                $bill_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                $bill_phone = $order->get_billing_phone();
                $bill_addr  = self::format_address(
                    $order->get_billing_address_1(),
                    $order->get_billing_address_2(),
                    $order->get_billing_city(),
                    $order->get_billing_state(),
                    $order->get_billing_postcode(),
                    $order->get_billing_country()
                );
                $ship_addr = self::format_address(
                    $order->get_shipping_address_1(),
                    $order->get_shipping_address_2(),
                    $order->get_shipping_city(),
                    $order->get_shipping_state(),
                    $order->get_shipping_postcode(),
                    $order->get_shipping_country()
                );
                if ( ! $ship_addr ) {
                    $ship_addr = $bill_addr;
                }

                fputcsv( $out, [
                    '#' . $order->get_order_number(),
                    $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
                    wc_get_order_status_name( $order->get_status() ),
                    self::get_product_names( $order ),
                    $bill_name,
                    $bill_phone,
                    $bill_addr,
                    $ship_addr,
                    $order->get_payment_method_title(),
                    wp_strip_all_tags( $order->get_formatted_order_total() ),
                ] );
            }

            // Flush output to browser after each batch
            flush();

            $count = count( $orders );
            unset( $orders );
            $page++;
        } while ( $count === $batch_size );

        fclose( $out );
        exit;
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function build_query_args( $date_from, $date_to, $limit, $paged ) {
        $args = [
            'limit'   => $limit,
            'paged'   => $paged,
            'orderby' => 'date',
            'order'   => 'DESC',
            'type'    => 'shop_order',
        ];

        if ( $date_from ) {
            $args['date_created'] = $date_from . '...' . ( $date_to ?: gmdate( 'Y-m-d' ) );
        } elseif ( $date_to ) {
            $args['date_created'] = '2000-01-01...' . $date_to;
        }

        return $args;
    }

    private static function format_address( $addr1, $addr2, $city, $state, $postcode, $country ) {
        $parts = array_filter( [
            $addr1,
            $addr2,
            implode( ' ', array_filter( [ $city, $state, $postcode ] ) ),
            $country,
        ] );
        return implode( ', ', $parts );
    }

    private static function get_product_names( $order ) {
        $names = [];
        foreach ( $order->get_items() as $item ) {
            $qty  = $item->get_quantity();
            $name = $item->get_name();
            $names[] = $qty > 1 ? $name . ' x' . $qty : $name;
        }
        return implode( ', ', $names );
    }
}
