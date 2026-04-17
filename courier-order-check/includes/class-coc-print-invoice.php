<?php
defined( 'ABSPATH' ) || exit;

/**
 * Print Invoice feature.
 *
 * Adds a "Print Invoice" button to every WooCommerce order edit page
 * (both classic screens and HPOS) and renders a clean, printable
 * invoice at admin.php?page=coc-invoice.
 */
class COC_Print_Invoice {

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        add_action( 'admin_menu',                                         [ __CLASS__, 'register_invoice_page' ] );
        add_action( 'add_meta_boxes',          [ __CLASS__, 'register_meta_box' ] );
        add_action( 'admin_enqueue_scripts',   [ __CLASS__, 'enqueue_assets' ] );
    }

    /* ------------------------------------------------------------------
     * Hidden admin page for the invoice
     * ------------------------------------------------------------------ */

    public static function register_invoice_page() {
        add_submenu_page(
            null,                    // hidden – no parent menu
            'Invoice',
            'Invoice',
            'edit_shop_orders',
            'coc-invoice',
            [ __CLASS__, 'render_invoice_page' ]
        );
    }

    /* ------------------------------------------------------------------
     * Meta box (sidebar button)
     * ------------------------------------------------------------------ */

    public static function register_meta_box() {
        // Classic screen: shop_order post type
        // HPOS screen: woocommerce_page_wc-orders
        $screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
        foreach ( $screens as $screen ) {
            add_meta_box(
                'coc-print-invoice',
                __( 'Print Invoice', 'courier-order-check' ),
                [ __CLASS__, 'render_meta_box' ],
                $screen,
                'side',
                'high'
            );
        }
    }

    public static function render_meta_box( $post_or_order ) {
        if ( $post_or_order instanceof WP_Post ) {
            $order_id = $post_or_order->ID;
        } elseif ( is_a( $post_or_order, 'WC_Abstract_Order' ) ) {
            $order_id = $post_or_order->get_id();
        } else {
            return;
        }
        $nonce = wp_create_nonce( 'coc_invoice_' . $order_id );
        $url   = add_query_arg( [
            'page'     => 'coc-invoice',
            'order_id' => $order_id,
            'nonce'    => $nonce,
        ], admin_url( 'admin.php' ) );
        ?>
        <a href="<?php echo esc_url( $url ); ?>"
           target="_blank"
           class="button button-primary coc-invoice-btn">
            🖨 <?php esc_html_e( 'Print Invoice', 'courier-order-check' ); ?>
        </a>
        <?php
    }

    /* ------------------------------------------------------------------
     * Assets — small button style on order pages only
     * ------------------------------------------------------------------ */

    public static function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) {
            return;
        }
        wp_add_inline_style( 'coc-admin', '
            .coc-invoice-btn { width:100%; text-align:center; display:block; margin-top:2px !important; }
        ' );
    }

    /* ------------------------------------------------------------------
     * Invoice page renderer
     * ------------------------------------------------------------------ */

    public static function render_invoice_page() {
        $order_id = absint( $_GET['order_id'] ?? 0 );
        $nonce    = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );

        if ( ! $order_id || ! wp_verify_nonce( $nonce, 'coc_invoice_' . $order_id ) ) {
            wp_die( esc_html__( 'Invalid or expired invoice link.', 'courier-order-check' ) );
        }
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( esc_html__( 'You do not have permission to view invoices.', 'courier-order-check' ) );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( esc_html__( 'Order not found.', 'courier-order-check' ) );
        }

        self::output_invoice( $order );
        exit;
    }

    /* ------------------------------------------------------------------
     * Invoice HTML output
     * ------------------------------------------------------------------ */

    private static function output_invoice( WC_Order $order ) {
        $shop_name    = get_bloginfo( 'name' );
        $shop_url     = get_bloginfo( 'url' );
        $shop_address = implode( ', ', array_filter( [
            get_option( 'woocommerce_store_address' ),
            get_option( 'woocommerce_store_city' ),
            get_option( 'woocommerce_store_postcode' ),
            WC()->countries->countries[ get_option( 'woocommerce_default_country', '' ) ] ?? '',
        ] ) );
        $shop_email   = get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );

        $order_id     = $order->get_id();
        $order_number = $order->get_order_number();
        $order_date   = wc_format_datetime( $order->get_date_created() );
        $status_label = wc_get_order_status_name( $order->get_status() );
        $payment      = $order->get_payment_method_title();
        $order_note   = $order->get_customer_note();

        // Billing
        $bill_name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $bill_company = $order->get_billing_company();
        $bill_addr1   = $order->get_billing_address_1();
        $bill_addr2   = $order->get_billing_address_2();
        $bill_city    = $order->get_billing_city();
        $bill_state   = $order->get_billing_state();
        $bill_post    = $order->get_billing_postcode();
        $bill_country = $order->get_billing_country();
        $bill_phone   = $order->get_billing_phone();
        $bill_email   = $order->get_billing_email();

        // Shipping
        $ship_name    = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        $ship_company = $order->get_shipping_company();
        $ship_addr1   = $order->get_shipping_address_1();
        $ship_addr2   = $order->get_shipping_address_2();
        $ship_city    = $order->get_shipping_city();
        $ship_state   = $order->get_shipping_state();
        $ship_post    = $order->get_shipping_postcode();
        $ship_country = $order->get_shipping_country();
        $has_shipping = ( $ship_name || $ship_addr1 || $ship_city );

        // Items
        $items = $order->get_items();

        // Totals
        $subtotal        = $order->get_subtotal();
        $shipping_total  = (float) $order->get_shipping_total();
        $discount_total  = (float) $order->get_discount_total();
        $tax_total       = (float) $order->get_total_tax();
        $grand_total     = (float) $order->get_total();
        $currency_symbol = get_woocommerce_currency_symbol( $order->get_currency() );

        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Invoice #<?php echo esc_html( $order_number ); ?> — <?php echo esc_html( $shop_name ); ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 13px;
    color: #1a1a2e;
    background: #f0f4f8;
}

.invoice-page {
    max-width: 820px;
    margin: 32px auto;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 30px rgba(0,0,0,.12);
    overflow: hidden;
}

/* ── Header ─────────────────────────────────────────────── */
.inv-header {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
    color: #fff;
    padding: 32px 40px 28px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
}

.inv-shop h1 {
    font-size: 24px;
    font-weight: 800;
    letter-spacing: .5px;
    margin-bottom: 6px;
}
.inv-shop p {
    font-size: 12px;
    opacity: .82;
    line-height: 1.7;
}

.inv-meta { text-align: right; }
.inv-meta .inv-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .1em;
    opacity: .7;
    margin-bottom: 2px;
}
.inv-meta .inv-number {
    font-size: 26px;
    font-weight: 900;
    line-height: 1;
    margin-bottom: 12px;
}
.inv-meta table { margin-left: auto; }
.inv-meta table td { padding: 2px 0 2px 14px; font-size: 12px; }
.inv-meta table td:first-child { opacity: .75; text-align: left; }
.inv-meta table td:last-child  { font-weight: 600; }

/* ── Status ribbon ───────────────────────────────────────── */
.inv-status-bar {
    background: #f1f5ff;
    border-bottom: 1px solid #dbe4ff;
    padding: 8px 40px;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #374151;
}
.inv-status-pill {
    display: inline-block;
    padding: 2px 12px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .06em;
    background: #2563eb;
    color: #fff;
}

/* ── Address block ───────────────────────────────────────── */
.inv-addresses {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    border-bottom: 1px solid #e8edf3;
}
.inv-addr-block {
    padding: 22px 40px;
}
.inv-addr-block + .inv-addr-block {
    border-left: 1px solid #e8edf3;
}
.inv-addr-title {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .12em;
    color: #2563eb;
    font-weight: 800;
    margin-bottom: 8px;
}
.inv-addr-block h3 {
    font-size: 14px;
    font-weight: 700;
    color: #1a1a2e;
    margin-bottom: 4px;
}
.inv-addr-block p {
    font-size: 12px;
    color: #4b5563;
    line-height: 1.7;
}

/* ── Items table ─────────────────────────────────────────── */
.inv-items { padding: 0 40px 24px; }

.inv-items-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 22px;
}
.inv-items-table thead tr {
    background: #1e3a5f;
    color: #fff;
}
.inv-items-table th {
    padding: 10px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
}
.inv-items-table th:last-child,
.inv-items-table td:last-child { text-align: right; }

.inv-items-table tbody tr {
    border-bottom: 1px solid #f0f4f8;
}
.inv-items-table tbody tr:last-child { border-bottom: none; }
.inv-items-table tbody tr:nth-child(even) { background: #f8faff; }
.inv-items-table td {
    padding: 11px 14px;
    font-size: 13px;
    color: #374151;
    vertical-align: top;
}
.inv-items-table td:first-child { font-weight: 600; color: #1a1a2e; }
.inv-items-table td small {
    display: block;
    color: #9ca3af;
    font-weight: 400;
    font-size: 11px;
    margin-top: 2px;
}

/* ── Totals ──────────────────────────────────────────────── */
.inv-totals-wrap {
    padding: 0 40px 28px;
    display: flex;
    justify-content: flex-end;
}
.inv-totals {
    width: 280px;
    border: 1px solid #e8edf3;
    border-radius: 8px;
    overflow: hidden;
}
.inv-totals table { width: 100%; border-collapse: collapse; }
.inv-totals td {
    padding: 9px 16px;
    font-size: 13px;
    color: #374151;
    border-bottom: 1px solid #f0f4f8;
}
.inv-totals td:last-child { text-align: right; font-weight: 600; }
.inv-totals tr:last-child td { border-bottom: none; }

.inv-total-row td {
    background: #1e3a5f;
    color: #fff !important;
    font-size: 15px !important;
    font-weight: 800 !important;
    padding: 12px 16px !important;
}

/* ── Note + Payment ──────────────────────────────────────── */
.inv-footer-info {
    margin: 0 40px;
    padding: 18px 0;
    border-top: 1px solid #e8edf3;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.inv-footer-info-block h4 {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: #2563eb;
    font-weight: 800;
    margin-bottom: 6px;
}
.inv-footer-info-block p {
    font-size: 12px;
    color: #4b5563;
    line-height: 1.7;
}

/* ── Footer bar ──────────────────────────────────────────── */
.inv-footer {
    background: #f8faff;
    border-top: 1px solid #e8edf3;
    text-align: center;
    padding: 16px 40px;
    font-size: 12px;
    color: #6b7280;
}
.inv-footer strong { color: #1e3a5f; }

/* ── Print button ────────────────────────────────────────── */
.inv-print-bar {
    text-align: center;
    padding: 20px;
    background: #f0f4f8;
}
.inv-print-btn {
    display: inline-block;
    padding: 11px 32px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    letter-spacing: .02em;
    box-shadow: 0 2px 8px rgba(37,99,235,.35);
}
.inv-print-btn:hover { background: #1d4ed8; }

/* ── Print media ─────────────────────────────────────────── */
@media print {
    body { background: #fff !important; }
    .inv-print-bar { display: none !important; }
    .invoice-page {
        margin: 0 !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        max-width: 100% !important;
    }
}
</style>
</head>
<body>

<div class="inv-print-bar">
    <button class="inv-print-btn" onclick="window.print()">🖨 Print Invoice</button>
</div>

<div class="invoice-page">

    <!-- Header -->
    <div class="inv-header">
        <div class="inv-shop">
            <h1><?php echo esc_html( $shop_name ); ?></h1>
            <?php if ( $shop_address ) : ?>
                <p><?php echo esc_html( $shop_address ); ?></p>
            <?php endif; ?>
            <?php if ( $shop_email ) : ?>
                <p><?php echo esc_html( $shop_email ); ?></p>
            <?php endif; ?>
        </div>
        <div class="inv-meta">
            <div class="inv-label">Invoice</div>
            <div class="inv-number">#<?php echo esc_html( $order_number ); ?></div>
            <table>
                <tr><td>Date</td><td><?php echo esc_html( $order_date ); ?></td></tr>
                <tr><td>Status</td><td><?php echo esc_html( $status_label ); ?></td></tr>
                <?php if ( $payment ) : ?>
                <tr><td>Payment</td><td><?php echo esc_html( $payment ); ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- Addresses -->
    <div class="inv-addresses">
        <!-- Bill To -->
        <div class="inv-addr-block">
            <div class="inv-addr-title">Bill To</div>
            <?php if ( $bill_name ) : ?><h3><?php echo esc_html( $bill_name ); ?></h3><?php endif; ?>
            <p>
                <?php
                $parts = array_filter( [
                    $bill_company,
                    $bill_addr1,
                    $bill_addr2,
                    implode( ' ', array_filter( [ $bill_city, $bill_state, $bill_post ] ) ),
                    WC()->countries->countries[ $bill_country ] ?? $bill_country,
                ] );
                echo nl2br( esc_html( implode( "\n", $parts ) ) );
                ?>
            </p>
            <?php if ( $bill_phone ) : ?><p style="margin-top:6px;">📞 <?php echo esc_html( $bill_phone ); ?></p><?php endif; ?>
            <?php if ( $bill_email ) : ?><p>✉ <?php echo esc_html( $bill_email ); ?></p><?php endif; ?>
        </div>

        <!-- Ship To -->
        <div class="inv-addr-block">
            <div class="inv-addr-title">Ship To</div>
            <?php if ( $has_shipping ) : ?>
                <?php if ( $ship_name ) : ?><h3><?php echo esc_html( $ship_name ); ?></h3><?php endif; ?>
                <p>
                    <?php
                    $sparts = array_filter( [
                        $ship_company,
                        $ship_addr1,
                        $ship_addr2,
                        implode( ' ', array_filter( [ $ship_city, $ship_state, $ship_post ] ) ),
                        WC()->countries->countries[ $ship_country ] ?? $ship_country,
                    ] );
                    echo nl2br( esc_html( implode( "\n", $sparts ) ) );
                    ?>
                </p>
            <?php else : ?>
                <h3><?php echo esc_html( $bill_name ); ?></h3>
                <p><em style="color:#9ca3af;">Same as billing address</em></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Items -->
    <div class="inv-items">
        <table class="inv-items-table">
            <thead>
                <tr>
                    <th style="width:45%">Product</th>
                    <th>SKU</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $item ) :
                    $product    = $item->get_product();
                    $item_name  = $item->get_name();
                    $qty        = $item->get_quantity();
                    $line_total = $item->get_total();
                    $unit_price = $qty > 0 ? $line_total / $qty : 0;
                    $sku        = ( $product && $product->get_sku() ) ? $product->get_sku() : '—';
                    // Variation meta
                    $meta_data  = $item->get_formatted_meta_data( '_', true );
                    ?>
                    <tr>
                        <td>
                            <?php echo esc_html( $item_name ); ?>
                            <?php if ( ! empty( $meta_data ) ) : ?>
                                <small>
                                    <?php foreach ( $meta_data as $meta ) :
                                        echo esc_html( wp_strip_all_tags( $meta->display_key ) ) . ': ' . esc_html( wp_strip_all_tags( $meta->display_value ) ) . '  ';
                                    endforeach; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $sku ); ?></td>
                        <td><?php echo esc_html( $qty ); ?></td>
                        <td><?php echo esc_html( $currency_symbol . number_format( $unit_price, 2 ) ); ?></td>
                        <td><?php echo esc_html( $currency_symbol . number_format( $line_total, 2 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Totals -->
    <div class="inv-totals-wrap">
        <div class="inv-totals">
            <table>
                <tr>
                    <td>Subtotal</td>
                    <td><?php echo esc_html( $currency_symbol . number_format( $subtotal, 2 ) ); ?></td>
                </tr>
                <?php if ( $discount_total > 0 ) : ?>
                <tr>
                    <td>Discount</td>
                    <td style="color:#16a34a;">−<?php echo esc_html( $currency_symbol . number_format( $discount_total, 2 ) ); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ( $shipping_total > 0 ) : ?>
                <tr>
                    <td>Shipping</td>
                    <td><?php echo esc_html( $currency_symbol . number_format( $shipping_total, 2 ) ); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ( $tax_total > 0 ) : ?>
                <tr>
                    <td>Tax</td>
                    <td><?php echo esc_html( $currency_symbol . number_format( $tax_total, 2 ) ); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="inv-total-row">
                    <td>Total</td>
                    <td><?php echo esc_html( $currency_symbol . number_format( $grand_total, 2 ) ); ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Footer info -->
    <?php if ( $payment || $order_note ) : ?>
    <div class="inv-footer-info">
        <?php if ( $payment ) : ?>
        <div class="inv-footer-info-block">
            <h4>Payment Method</h4>
            <p><?php echo esc_html( $payment ); ?></p>
        </div>
        <?php endif; ?>
        <?php if ( $order_note ) : ?>
        <div class="inv-footer-info-block">
            <h4>Order Note</h4>
            <p><?php echo esc_html( $order_note ); ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Footer bar -->
    <div class="inv-footer">
        Thank you for your order! &nbsp;|&nbsp;
        <strong><?php echo esc_html( $shop_name ); ?></strong> &nbsp;|&nbsp;
        <a href="<?php echo esc_url( $shop_url ); ?>" style="color:#2563eb;"><?php echo esc_html( $shop_url ); ?></a>
    </div>

</div>

<div class="inv-print-bar">
    <button class="inv-print-btn" onclick="window.print()">🖨 Print Invoice</button>
</div>

<script>
// Auto-open print dialog when loaded directly (skip if user clicked Print button).
window.addEventListener( 'load', function () {
    if ( window.location.search.indexOf( 'autoprint=1' ) !== -1 ) {
        window.print();
    }
} );
</script>
</body>
</html>
<?php
    }
}
