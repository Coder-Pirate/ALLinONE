<?php
defined( 'ABSPATH' ) || exit;

/**
 * Prevents a customer from placing a new order while they have an existing
 * order in "processing" status. Once the admin moves the order to any other
 * status the lock is automatically lifted.
 *
 * Works for both logged-in users (matched by user ID) and guests (matched
 * by billing e-mail).
 */
class COC_Order_Lock {

    public static function init() {
        // Show a notice at the top of the checkout page.
        add_action( 'woocommerce_before_checkout_form', [ __CLASS__, 'show_checkout_notice' ], 5 );

        // Block the actual order submission.
        add_action( 'woocommerce_checkout_process', [ __CLASS__, 'block_checkout' ] );

        // Validate Bangladeshi phone number format.
        add_action( 'woocommerce_checkout_process', [ __CLASS__, 'validate_bd_phone' ] );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Find the first "processing" order for the current visitor.
     *
     * @return WC_Order|null
     */
    private static function get_active_order() {
        $args = [
            'status' => [ 'wc-processing' ],
            'limit'  => 1,
            'return' => 'objects',
        ];

        if ( is_user_logged_in() ) {
            $args['customer'] = get_current_user_id();

            $orders = wc_get_orders( $args );
            return ! empty( $orders ) ? $orders[0] : null;
        }

        // Guest: check by billing phone only.
        $phone = '';
        if ( WC()->session ) {
            $phone = (string) WC()->session->get( 'billing_phone', '' );
        }
        if ( ! $phone && ! empty( $_POST['billing_phone'] ) ) {
            $phone = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
        }

        if ( ! $phone ) {
            return null; // no phone — do not block
        }

        $orders = wc_get_orders( array_merge( $args, [ 'billing_phone' => $phone ] ) );
        return ! empty( $orders ) ? $orders[0] : null;
    }

    /* ------------------------------------------------------------------
     * Phone validation
     * ------------------------------------------------------------------ */

    /**
     * Returns true if the given number is a valid Bangladeshi mobile number.
     * Accepts: 01XXXXXXXXX, 8801XXXXXXXXX, +8801XXXXXXXXX (operator 3-9).
     */
    private static function is_valid_bd_phone( $phone ) {
        $stripped = preg_replace( '/[\s\-]/', '', $phone );
        return (bool) preg_match( '/^(?:\+?880|0)1[3-9]\d{8}$/', $stripped );
    }

    public static function validate_bd_phone() {
        $phone = isset( $_POST['billing_phone'] )
            ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) )
            : '';

        if ( $phone === '' ) {
            return; // WooCommerce's own required-field check handles empty.
        }

        if ( ! self::is_valid_bd_phone( $phone ) ) {
            wc_add_notice(
                esc_html__( 'Please enter a valid Bangladeshi mobile number (e.g. 01712345678).', 'courier-order-check' ),
                'error'
            );
        }
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Resolve a message template: use custom admin text if set, otherwise the default.
     * Supports {order_number} placeholder.
     */
    private static function resolve_message( $option_key, $default, $order_number ) {
        $custom = trim( get_option( $option_key, '' ) );
        $text   = $custom !== '' ? $custom : $default;
        return str_replace( '{order_number}', esc_html( $order_number ), esc_html( $text ) );
    }

    /* ------------------------------------------------------------------
     * Checkout notice (visible to the customer)
     * ------------------------------------------------------------------ */

    public static function show_checkout_notice() {
        $order = self::get_active_order();
        if ( ! $order ) {
            return;
        }

        $message = self::resolve_message(
            'coc_order_lock_notice_text',
            'You have an order (#{order_number}) that is currently being processed. You will be able to place a new order once your current order status is updated by our team.',
            $order->get_order_number()
        );

        wc_print_notice( $message, 'notice' );
    }

    /* ------------------------------------------------------------------
     * Checkout validation (blocks form submission)
     * ------------------------------------------------------------------ */

    public static function block_checkout() {
        $order = self::get_active_order();
        if ( ! $order ) {
            return;
        }

        wc_add_notice(
            self::resolve_message(
                'coc_order_lock_block_text',
                'Your order (#{order_number}) is still being processed. Please wait until our team updates your order status before placing a new order.',
                $order->get_order_number()
            ),
            'error'
        );
    }
}
