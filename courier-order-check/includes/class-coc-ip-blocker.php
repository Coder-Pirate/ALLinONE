<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manages a blocklist of IP addresses to prevent fake/fraudulent orders.
 */
class COC_IP_Blocker {

    const OPTION_KEY = 'coc_blocked_ips';

    public static function init() {
        // Block the checkout process if the visitor's IP is on the blocklist.
        add_action( 'woocommerce_checkout_process',    [ __CLASS__, 'block_checkout' ] );
        add_action( 'template_redirect',               [ __CLASS__, 'block_checkout_page' ] );

        // AJAX handlers (admin only).
        add_action( 'wp_ajax_coc_block_ip',   [ __CLASS__, 'ajax_block_ip' ] );
        add_action( 'wp_ajax_coc_unblock_ip', [ __CLASS__, 'ajax_unblock_ip' ] );
    }

    /* ------------------------------------------------------------------
     * Data accessors
     * ------------------------------------------------------------------ */

    public static function get_blocked_ips() {
        $ips = get_option( self::OPTION_KEY, [] );
        return is_array( $ips ) ? array_values( $ips ) : [];
    }

    public static function is_blocked( $ip ) {
        return in_array( sanitize_text_field( $ip ), self::get_blocked_ips(), true );
    }

    public static function block_ip( $ip ) {
        $ip  = sanitize_text_field( $ip );
        $ips = self::get_blocked_ips();
        if ( ! in_array( $ip, $ips, true ) ) {
            $ips[] = $ip;
            update_option( self::OPTION_KEY, $ips );
        }
    }

    public static function unblock_ip( $ip ) {
        $ip  = sanitize_text_field( $ip );
        $ips = self::get_blocked_ips();
        $ips = array_values( array_filter( $ips, function ( $i ) use ( $ip ) {
            return $i !== $ip;
        } ) );
        update_option( self::OPTION_KEY, $ips );
    }

    /**
     * Get the current visitor's IP address.
     */
    public static function get_current_ip() {
        $ip = '';
        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip    = trim( $parts[0] );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field( $ip );
    }

    /* ------------------------------------------------------------------
     * Blocking hooks
     * ------------------------------------------------------------------ */

    /**
     * Called during woocommerce_checkout_process — adds an error notice
     * which WooCommerce uses to abort the order.
     */
    public static function block_checkout() {
        if ( self::is_blocked( self::get_current_ip() ) ) {
            wc_add_notice(
                __( 'Your order could not be placed. Please contact us for assistance.', 'courier-order-check' ),
                'error'
            );
        }
    }

    /**
     * Called on template_redirect — shows an error on the checkout page
     * if the visitor's IP is blocked, making it clear they cannot proceed.
     */
    public static function block_checkout_page() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }
        if ( self::is_blocked( self::get_current_ip() ) ) {
            wc_add_notice(
                __( 'Checkout is not available from your network. Please contact us for assistance.', 'courier-order-check' ),
                'error'
            );
        }
    }

    /* ------------------------------------------------------------------
     * AJAX handlers
     * ------------------------------------------------------------------ */

    public static function ajax_block_ip() {
        check_ajax_referer( 'coc_ip_block', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'courier-order-check' ) ] );
        }

        $ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';

        if ( empty( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid IP address.', 'courier-order-check' ) ] );
        }

        self::block_ip( $ip );

        wp_send_json_success( [
            /* translators: %s: IP address */
            'message' => sprintf( __( '%s has been blocked.', 'courier-order-check' ), esc_html( $ip ) ),
            'ips'     => self::get_blocked_ips(),
            'ip'      => $ip,
        ] );
    }

    public static function ajax_unblock_ip() {
        check_ajax_referer( 'coc_ip_block', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'courier-order-check' ) ] );
        }

        $ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';

        if ( empty( $ip ) ) {
            wp_send_json_error( [ 'message' => __( 'IP address is required.', 'courier-order-check' ) ] );
        }

        self::unblock_ip( $ip );

        wp_send_json_success( [
            /* translators: %s: IP address */
            'message' => sprintf( __( '%s has been unblocked.', 'courier-order-check' ), esc_html( $ip ) ),
            'ips'     => self::get_blocked_ips(),
            'ip'      => $ip,
        ] );
    }
}
