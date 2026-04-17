<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles all communication with the Growever Courier Check API.
 */
class COC_API {

    const BASE_URL = 'https://app.growever.bd/api';

    /**
     * Build the common HTTP headers for every request.
     *
     * @return array|WP_Error Array of headers, or WP_Error if not configured.
     */
    private static function get_headers() {
        $api_key = get_option( 'coc_api_key', '' );
        $domain  = get_option( 'coc_domain',  '' );

        if ( empty( $api_key ) ) {
            return new WP_Error( 'coc_missing_key', __( 'API key is not configured.', 'courier-order-check' ) );
        }

        if ( empty( $domain ) ) {
            return new WP_Error( 'coc_missing_domain', __( 'Domain is not configured.', 'courier-order-check' ) );
        }

        return [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'X-Domain'      => sanitize_text_field( $domain ),
        ];
    }

    /**
     * Test the API connection.
     *
     * @return array|WP_Error Decoded response array or WP_Error on failure.
     */
    public static function check_connection() {
        $headers = self::get_headers();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        $response = wp_remote_get(
            self::BASE_URL . '/check-connection',
            [
                'headers' => $headers,
                'timeout' => 15,
            ]
        );

        return self::parse_response( $response );
    }

    /**
     * Retrieve courier order statistics for a phone number.
     *
     * @param  string          $phone BD phone number (01XXXXXXXXX).
     * @return array|WP_Error  Decoded response array or WP_Error on failure.
     */
    public static function courier_check( $phone ) {
        $headers = self::get_headers();
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        // Sanitize phone – keep only digits and leading +.
        $phone = preg_replace( '/[^\d+]/', '', $phone );

        if ( empty( $phone ) ) {
            return new WP_Error( 'coc_invalid_phone', __( 'Phone number is empty or invalid.', 'courier-order-check' ) );
        }

        $response = wp_remote_post(
            self::BASE_URL . '/courier-check',
            [
                'headers' => $headers,
                'body'    => wp_json_encode( [ 'phone' => $phone ] ),
                'timeout' => 15,
            ]
        );

        return self::parse_response( $response );
    }

    /**
     * Parse a WP_HTTP response into a PHP array.
     *
     * @param  array|WP_Error $response wp_remote_* return value.
     * @return array|WP_Error
     */
    private static function parse_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'coc_json_error', __( 'Invalid JSON received from API.', 'courier-order-check' ) );
        }

        if ( $http_code === 401 ) {
            return new WP_Error( 'coc_unauthorized', __( 'Missing or invalid API key (401).', 'courier-order-check' ) );
        }

        if ( $http_code === 403 ) {
            $reason = isset( $data['message'] ) ? $data['message'] : __( 'Domain mismatch or account inactive (403).', 'courier-order-check' );
            return new WP_Error( 'coc_forbidden', $reason );
        }

        if ( $http_code === 422 ) {
            $reason = isset( $data['message'] ) ? $data['message'] : __( 'Validation error (422).', 'courier-order-check' );
            return new WP_Error( 'coc_validation', $reason );
        }

        if ( $http_code < 200 || $http_code >= 300 ) {
            /* translators: %d: HTTP status code */
            return new WP_Error( 'coc_http_error', sprintf( __( 'Unexpected HTTP status: %d', 'courier-order-check' ), $http_code ) );
        }

        return $data;
    }
}
