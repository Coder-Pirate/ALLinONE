<?php
defined( 'ABSPATH' ) || exit;

/**
 * COD Restriction — blocks Cash on Delivery for customers whose billing phone
 * has a courier success rate below the admin-defined threshold.  Forces them
 * to upload advance payment proof before placing an order.
 */
class COC_COD_Restriction {

    public static function init() {
        // Real-time phone check (frontend AJAX).
        add_action( 'wp_ajax_coc_cod_check',        [ __CLASS__, 'ajax_check' ] );
        add_action( 'wp_ajax_nopriv_coc_cod_check', [ __CLASS__, 'ajax_check' ] );

        // Screenshot upload (frontend AJAX).
        add_action( 'wp_ajax_coc_cod_upload',        [ __CLASS__, 'ajax_upload' ] );
        add_action( 'wp_ajax_nopriv_coc_cod_upload', [ __CLASS__, 'ajax_upload' ] );

        // Save advance payment amount to session so cart fee updates live.
        add_action( 'wp_ajax_coc_cod_save_amount',        [ __CLASS__, 'ajax_save_amount' ] );
        add_action( 'wp_ajax_nopriv_coc_cod_save_amount', [ __CLASS__, 'ajax_save_amount' ] );

        // Subtract advance payment from cart totals (updates subtotal/total/button).
        add_action( 'woocommerce_cart_calculate_fees', [ __CLASS__, 'apply_cart_fee' ] );

        // Inject prepayment panel HTML just before the payment block.
        add_action( 'woocommerce_review_order_before_payment', [ __CLASS__, 'render_prepayment_panel' ] );

        // Server-side validation on checkout submit.
        add_action( 'woocommerce_checkout_process', [ __CLASS__, 'validate' ] );

        // Persist screenshot + amount to order meta.
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'save_proof' ] );

        // Show payment proof inside the admin order screen.
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ __CLASS__, 'show_proof_in_admin' ] );

        // Enqueue JS + inline CSS on the checkout page.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function is_enabled() {
        return (bool) get_option( 'coc_cod_restrict_enabled', '' );
    }

    private static function get_threshold() {
        return (int) get_option( 'coc_cod_restrict_threshold', 60 );
    }

    /**
     * Returns true when the given phone number's success ratio is below
     * the configured threshold (i.e. COD should be blocked).
     *
     * Result is cached in a 10-minute transient to avoid repeated API calls.
     */
    public static function is_restricted( $phone ) {
        if ( ! self::is_enabled() ) {
            return false;
        }

        $phone = preg_replace( '/[^\d+]/', '', $phone );
        if ( empty( $phone ) ) {
            return false;
        }

        $transient_key = 'coc_cod_r_' . md5( $phone );
        $cached        = get_transient( $transient_key );
        if ( $cached !== false ) {
            return $cached === '1';
        }

        $result = COC_API::courier_check( $phone );
        if ( is_wp_error( $result ) ) {
            // API unavailable — fail open so legitimate customers are not blocked.
            return false;
        }

        $summary = isset( $result['data']['summary'] ) ? $result['data']['summary'] : null;
        if ( ! $summary ) {
            return false;
        }

        $ratio      = (float) ( isset( $summary['success_ratio'] ) ? $summary['success_ratio'] : 0 );
        $restricted = $ratio < self::get_threshold();

        set_transient( $transient_key, $restricted ? '1' : '0', 10 * MINUTE_IN_SECONDS );

        return $restricted;
    }

    /* ------------------------------------------------------------------
     * AJAX — save advance payment amount to session
     * ------------------------------------------------------------------ */

    public static function ajax_save_amount() {
        check_ajax_referer( 'coc_cod_check', 'nonce' );

        $amount = isset( $_POST['amount'] ) ? absint( $_POST['amount'] ) : 0;

        if ( WC()->session ) {
            WC()->session->set( 'coc_cod_advance_amount', $amount );
        }

        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
     * Cart fee — deduct advance payment from totals
     * ------------------------------------------------------------------ */

    public static function apply_cart_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        if ( ! self::is_enabled() ) {
            return;
        }

        $amount = WC()->session ? (int) WC()->session->get( 'coc_cod_advance_amount', 0 ) : 0;
        if ( $amount < 1 ) {
            return;
        }

        // Only deduct if the customer's phone is restricted.
        $phone = WC()->session ? (string) WC()->session->get( 'billing_phone', '' ) : '';
        if ( ! $phone && ! empty( $_POST['billing_phone'] ) ) {
            $phone = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
        }
        if ( ! self::is_restricted( $phone ) ) {
            return;
        }

        $cart->add_fee(
            __( 'Advance Payment (paid)', 'courier-order-check' ),
            -$amount,
            false
        );
    }

    /* ------------------------------------------------------------------
     * AJAX — real-time phone check
     * ------------------------------------------------------------------ */

    public static function ajax_check() {
        check_ajax_referer( 'coc_cod_check', 'nonce' );

        $phone = isset( $_POST['phone'] )
            ? sanitize_text_field( wp_unslash( $_POST['phone'] ) )
            : '';

        wp_send_json_success( [
            'restricted' => self::is_restricted( $phone ),
        ] );
    }

    /* ------------------------------------------------------------------
     * AJAX — screenshot upload
     * ------------------------------------------------------------------ */

    public static function ajax_upload() {
        check_ajax_referer( 'coc_cod_upload', 'nonce' );

        if ( empty( $_FILES['screenshot']['name'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file received.', 'courier-order-check' ) ] );
        }

        // Validate MIME type before handing to WordPress.
        $allowed_types = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        $file_type     = isset( $_FILES['screenshot']['type'] )
            ? sanitize_mime_type( wp_unslash( $_FILES['screenshot']['type'] ) )
            : '';

        if ( ! in_array( $file_type, $allowed_types, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Only JPG, PNG, GIF or WEBP images are allowed.', 'courier-order-check' ) ] );
        }

        // Max 5 MB.
        if ( ! empty( $_FILES['screenshot']['size'] ) && (int) $_FILES['screenshot']['size'] > 5 * 1024 * 1024 ) {
            wp_send_json_error( [ 'message' => __( 'File size must be under 5 MB.', 'courier-order-check' ) ] );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'screenshot', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
        }

        // Store attachment ID in the WC session with a single-use token.
        $token = wp_generate_password( 32, false );
        if ( WC()->session ) {
            WC()->session->set( 'coc_cod_screenshot', [
                'id'    => $attachment_id,
                'token' => $token,
            ] );
        }

        wp_send_json_success( [
            'token' => $token,
            'url'   => wp_get_attachment_url( $attachment_id ),
        ] );
    }

    /* ------------------------------------------------------------------
     * Checkout panel HTML
     * ------------------------------------------------------------------ */

    public static function render_prepayment_panel() {
        if ( ! self::is_enabled() ) {
            return;
        }

        $payment_number = trim( get_option( 'coc_cod_payment_number', '' ) );
        $direction_text = trim( get_option( 'coc_cod_payment_direction', '' ) );
        $ineligible_msg = trim( get_option( 'coc_cod_ineligible_msg', '' ) );
        $panel_note     = trim( get_option( 'coc_cod_panel_note', '' ) );

        if ( ! $ineligible_msg ) {
            $ineligible_msg = __( 'You are not eligible for Cash on Delivery due to your low delivery success rate. Please make an advance payment to place your order.', 'courier-order-check' );
        }
        if ( ! $panel_note ) {
            $panel_note = __( 'You are not eligible for Cash on Delivery. Please pay in advance using the account below and upload your payment screenshot to complete your order.', 'courier-order-check' );
        }
        ?>
        <div id="coc-cod-restricted-msg" class="coc-cod-restricted-msg" style="display:none;">
            <?php echo esc_html( $ineligible_msg ); ?>
        </div>

        <div id="coc-prepayment-panel" class="coc-prepayment-panel" style="display:none;">

            <p class="coc-pp-panel-note"><?php echo esc_html( $panel_note ); ?></p>

            <?php if ( $payment_number ) : ?>
            <p class="coc-pp-sendto">
                <?php esc_html_e( 'Send to:', 'courier-order-check' ); ?>
                <strong><?php echo esc_html( $payment_number ); ?></strong>
            </p>
            <?php endif; ?>

            <?php if ( $direction_text ) : ?>
            <p class="coc-pp-direction"><?php echo esc_html( $direction_text ); ?></p>
            <?php endif; ?>

            <div class="coc-pp-field">
                <label for="coc-sender-account">
                    <?php esc_html_e( 'Your Account Number', 'courier-order-check' ); ?>
                    <span class="required" aria-hidden="true">*</span>
                </label>
                <input type="text" id="coc-sender-account"
                       name="coc_sender_account"
                       placeholder="01XXXXXXXXX"
                       autocomplete="off" />
                <p style="font-size:12px;color:#6b7280;margin:4px 0 0;"><?php esc_html_e( 'The number you sent payment from.', 'courier-order-check' ); ?></p>
            </div>

            <div class="coc-pp-field">
                <label for="coc-payment-amount">
                    <?php esc_html_e( 'Payment Amount (৳)', 'courier-order-check' ); ?>
                    <span class="required" aria-hidden="true">*</span>
                </label>
                <input type="number" id="coc-payment-amount"
                       name="coc_payment_amount" min="1" step="1" value="" />
            </div>

            <div class="coc-pp-field">
                <label>
                    <?php esc_html_e( 'Payment Screenshot', 'courier-order-check' ); ?>
                    <span class="required" aria-hidden="true">*</span>
                </label>
                <label class="coc-upload-btn" id="coc-upload-label" for="coc-screenshot-input">
                    <span class="dashicons dashicons-upload"></span>
                    <?php esc_html_e( 'Upload screenshot', 'courier-order-check' ); ?>
                </label>
                <input type="file" id="coc-screenshot-input"
                       accept="image/*"
                       style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);" />
                <p id="coc-upload-error" class="coc-upload-error" style="display:none;"></p>
                <img id="coc-screenshot-preview" src="" alt=""
                     style="display:none;max-width:100%;margin-top:10px;border-radius:6px;" />
            </div>

            <input type="hidden" name="coc_cod_screenshot_token"
                   id="coc-screenshot-token" value="" />
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Server-side checkout validation
     * ------------------------------------------------------------------ */

    public static function validate() {
        if ( ! self::is_enabled() ) {
            return;
        }

        $phone = isset( $_POST['billing_phone'] )
            ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) )
            : '';

        if ( ! self::is_restricted( $phone ) ) {
            return;
        }

        // Collect all proof fields.
        $token        = isset( $_POST['coc_cod_screenshot_token'] )
            ? sanitize_text_field( wp_unslash( $_POST['coc_cod_screenshot_token'] ) )
            : '';
        $session_data = WC()->session ? WC()->session->get( 'coc_cod_screenshot' ) : null;
        $has_screenshot = ! empty( $token )
            && ! empty( $session_data['token'] )
            && hash_equals( (string) $session_data['token'], $token );

        $sender_account = isset( $_POST['coc_sender_account'] )
            ? sanitize_text_field( wp_unslash( $_POST['coc_sender_account'] ) )
            : '';

        $amount = isset( $_POST['coc_payment_amount'] ) ? absint( $_POST['coc_payment_amount'] ) : 0;

        // All three proof fields filled — allow the order (COD or any other method).
        if ( $has_screenshot && $sender_account && $amount >= 1 ) {
            return;
        }

        $payment_method = isset( $_POST['payment_method'] )
            ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) )
            : '';

        // COD without proof — always block with the ineligibility message.
        if ( $payment_method === 'cod' ) {
            $msg = trim( get_option( 'coc_cod_ineligible_msg', '' ) );
            if ( ! $msg ) {
                $msg = __( 'You are not eligible for Cash on Delivery. Please make an advance payment to place your order.', 'courier-order-check' );
            }
            wc_add_notice( $msg, 'error' );
            return;
        }

        // Non-COD but missing one or more proof fields — show specific errors.
        if ( ! $has_screenshot ) {
            wc_add_notice( __( 'Payment screenshot is required. Please upload your payment proof.', 'courier-order-check' ), 'error' );
        }
        if ( ! $sender_account ) {
            wc_add_notice( __( 'Please enter your account number (the number you sent payment from).', 'courier-order-check' ), 'error' );
        }
        if ( $amount < 1 ) {
            wc_add_notice( __( 'Please enter the payment amount you sent.', 'courier-order-check' ), 'error' );
        }
    }

    /* ------------------------------------------------------------------
     * Save screenshot + amount to order meta
     * ------------------------------------------------------------------ */

    public static function save_proof( $order ) {
        $session_data = WC()->session ? WC()->session->get( 'coc_cod_screenshot' ) : null;

        if ( ! empty( $session_data['id'] ) ) {
            $attachment_id = (int) $session_data['id'];
            $url           = wp_get_attachment_url( $attachment_id );

            $order->update_meta_data( '_coc_payment_screenshot_id',  $attachment_id );
            $order->update_meta_data( '_coc_payment_screenshot_url', $url );

            wp_update_post( [ 'ID' => $attachment_id, 'post_parent' => $order->get_id() ] );

            WC()->session->set( 'coc_cod_screenshot', null );
        }

        $amount = isset( $_POST['coc_payment_amount'] )
            ? absint( sanitize_text_field( wp_unslash( $_POST['coc_payment_amount'] ) ) )
            : 0;

        if ( $amount > 0 ) {
            $order->update_meta_data( '_coc_payment_amount', $amount );
            // The negative fee is already in the order via woocommerce_cart_calculate_fees.
            // No need to add it again here.
        }

        $sender_account = isset( $_POST['coc_sender_account'] )
            ? sanitize_text_field( wp_unslash( $_POST['coc_sender_account'] ) )
            : '';
        if ( $sender_account ) {
            $order->update_meta_data( '_coc_sender_account', $sender_account );
        }

        $order->save();
    }

    /* ------------------------------------------------------------------
     * Show payment proof in admin order screen
     * ------------------------------------------------------------------ */

    public static function show_proof_in_admin( $order ) {
        $url = $order->get_meta( '_coc_payment_screenshot_url' );
        if ( ! $url ) {
            $id  = $order->get_meta( '_coc_payment_screenshot_id' );
            $url = $id ? wp_get_attachment_url( (int) $id ) : '';
        }
        if ( ! $url ) {
            return;
        }

        $amount = $order->get_meta( '_coc_payment_amount' );
        $sender = $order->get_meta( '_coc_sender_account' );

        echo '<div style="margin-top:16px;padding:14px 16px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;">';
        echo '<p style="margin:0 0 10px;font-weight:700;font-size:13px;color:#15803d;">' . esc_html__( 'Advance Payment Proof', 'courier-order-check' ) . '</p>';
        echo '<p style="margin:0 0 6px;font-size:13px;">';
        if ( $amount ) {
            echo '<strong>' . esc_html__( 'Amount:', 'courier-order-check' ) . '</strong> ';
            echo '<span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;">৳' . esc_html( number_format( (float) $amount, 0 ) ) . '</span>&nbsp;&nbsp;';
        }
        if ( $sender ) {
            echo '<strong>' . esc_html__( 'From:', 'courier-order-check' ) . '</strong> ';
            echo '<span style="background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:4px;">' . esc_html( $sender ) . '</span>';
        }
        echo '</p>';
        echo '<a href="' . esc_url( $url ) . '" target="_blank" style="display:inline-block;margin-top:6px;">';
        echo '<img src="' . esc_url( $url ) . '" style="max-width:300px;border-radius:6px;border:1px solid #d1fae5;display:block;" />';
        echo '</a>';
        echo '<p style="margin:8px 0 0;font-size:11px;color:#6b7280;"><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'Open full size', 'courier-order-check' ) . ' ↗</a></p>';
        echo '</div>';
    }

    /* ------------------------------------------------------------------
     * Enqueue JS + inline CSS on checkout
     * ------------------------------------------------------------------ */

    public static function enqueue() {
        if ( ! self::is_enabled() || ! is_checkout() ) {
            return;
        }

        $js_file = COC_PLUGIN_DIR . 'assets/js/cod-restriction.js';
        wp_enqueue_script(
            'coc-cod-restriction',
            COC_PLUGIN_URL . 'assets/js/cod-restriction.js',
            [ 'jquery' ],
            file_exists( $js_file ) ? filemtime( $js_file ) : COC_VERSION,
            true
        );

        wp_localize_script( 'coc-cod-restriction', 'COC_COD', [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'check_nonce'  => wp_create_nonce( 'coc_cod_check' ),
            'upload_nonce' => wp_create_nonce( 'coc_cod_upload' ),
            'amount_nonce' => wp_create_nonce( 'coc_cod_check' ),
        ] );

        // Inject checkout UI styles inline so no extra HTTP request is needed.
        wp_add_inline_style( 'woocommerce-inline', self::inline_css() );
    }

    /* ---- Inline CSS ------------------------------------------------- */

    private static function inline_css() {
        return '
/* GrowEver — COD Restriction */
.coc-cod-restricted-msg {
    background: #eff6ff;
    border-left: 4px solid #3b82f6;
    color: #1e3a5f;
    padding: 14px 18px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 14px;
    line-height: 1.6;
}
.coc-prepayment-panel {
    background: #f0f6ff;
    border: 1.5px solid #bfdbfe;
    border-radius: 10px;
    padding: 22px 22px 16px;
    margin-bottom: 22px;
}
.coc-pp-panel-note {
    color: #1e3a5f;
    font-size: 13px;
    line-height: 1.6;
    margin: 0 0 16px;
    padding-bottom: 14px;
    border-bottom: 1px solid #bfdbfe;
}
.coc-pp-sendto {
    color: #2563eb;
    font-size: 15px;
    font-weight: 700;
    margin: 0 0 4px;
}
.coc-pp-direction {
    color: #3b82f6;
    font-size: 13px;
    margin: 0 0 16px;
}
.coc-pp-field {
    margin-bottom: 14px;
}
.coc-pp-field > label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #374151;
}
.coc-pp-field input[type="text"],
.coc-pp-field input[type="number"] {
    width: 100%;
    border: 1.5px solid #d1d5db;
    border-radius: 7px;
    padding: 10px 12px;
    font-size: 14px;
    background: #fff;
    box-sizing: border-box;
    outline: none;
}
.coc-pp-field input[type="text"]:focus,
.coc-pp-field input[type="number"]:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59,130,246,.15);
}
.coc-pp-field input[readonly] {
    background: #f9fafb;
    color: #6b7280;
}
.coc-upload-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    width: 100%;
    border: 2px dashed #93c5fd;
    border-radius: 7px;
    padding: 12px 20px;
    cursor: pointer;
    color: #2563eb;
    font-size: 13px;
    font-weight: 600;
    box-sizing: border-box;
    background: #fff;
    transition: border-color .2s, background .2s;
}
.coc-upload-btn:hover { border-color: #3b82f6; background: #eff6ff; }
.coc-upload-btn.uploading { opacity: .65; cursor: wait; }
.coc-upload-error {
    color: #dc2626;
    font-size: 12px;
    margin-top: 6px;
}
        ';
    }
}
