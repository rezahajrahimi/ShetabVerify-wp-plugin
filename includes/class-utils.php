<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WebDide_CV_Utils {
    const CIPHER = 'AES-256-CBC';

    public static function encrypt_card_number( $plain ) {
        if ( empty( $plain ) ) {
            return '';
        }

        $key = hash( 'sha256', AUTH_KEY . AUTH_SALT );
        $iv  = openssl_random_pseudo_bytes( 16 );
        $enc = openssl_encrypt( $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv ) . ':' . base64_encode( $enc );
    }

    public static function decrypt_card_number( $payload ) {
        if ( empty( $payload ) || strpos( $payload, ':' ) === false ) {
            return '';
        }

        list( $iv_b64, $enc_b64 ) = explode( ':', $payload );
        $iv  = base64_decode( $iv_b64 );
        $enc = base64_decode( $enc_b64 );
        $key = hash( 'sha256', AUTH_KEY . AUTH_SALT );

        $dec = openssl_decrypt( $enc, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
        return $dec === false ? '' : $dec;
    }

    public static function mask_card_number( $number ) {
        $num = preg_replace( '/\s+/', '', $number );
        if ( strlen( $num ) <= 4 ) {
            return $num;
        }
        $last4 = substr( $num, -4 );
        return '**** **** **** ' . $last4;
    }

    /**
     * Normalize an Iranian bank account number to digits only.
     *
     * @param string $account Raw account input.
     * @return string
     */
    public static function normalize_account_number( $account ) {
        return preg_replace( '/\D+/', '', (string) $account );
    }

    /**
     * Normalize Sheba/IBAN to IR + 24 digits.
     *
     * @param string $sheba Raw sheba input.
     * @return string Empty string if invalid.
     */
    public static function normalize_sheba( $sheba ) {
        $raw = strtoupper( preg_replace( '/\s+/', '', (string) $sheba ) );
        if ( '' === $raw ) {
            return '';
        }
        if ( 0 === strpos( $raw, 'IR' ) ) {
            $digits = preg_replace( '/\D+/', '', substr( $raw, 2 ) );
        } else {
            $digits = preg_replace( '/\D+/', '', $raw );
        }
        if ( 24 !== strlen( $digits ) ) {
            return '';
        }
        return 'IR' . $digits;
    }

    /**
     * Mask account / sheba showing last 4 characters.
     *
     * @param string $value Digits or IR….
     * @return string
     */
    public static function mask_sensitive_number( $value ) {
        $value = (string) $value;
        if ( strlen( $value ) <= 4 ) {
            return $value;
        }
        return str_repeat( '*', max( 0, strlen( $value ) - 4 ) ) . substr( $value, -4 );
    }

    public static function set_api_secret( $secret ) {
        if ( empty( $secret ) ) {
            return false;
        }
        $hash = wp_hash_password( $secret );
        update_option( 'wdcv_api_secret_hash', $hash );
        
        // Store encrypted version for display as requested by user
        $encrypted = self::encrypt_card_number( $secret );
        return update_option( 'wdcv_api_secret_encrypted', $encrypted );
    }

    public static function get_api_secret() {
        $encrypted = get_option( 'wdcv_api_secret_encrypted' );
        if ( empty( $encrypted ) ) {
            return '';
        }
        return self::decrypt_card_number( $encrypted );
    }

    public static function verify_api_secret( $provided ) {
        $hash = get_option( 'wdcv_api_secret_hash' );
        if ( empty( $hash ) || empty( $provided ) ) {
            return false;
        }
        return wp_check_password( $provided, $hash );
    }

    /**
     * Generate a QR code as a data URI (cached). Uses api.qrserver.com.
     *
     * @param string $data Payload encoded in the QR (e.g. 16-digit card number).
     * @param string $size Width x height, e.g. 160x160.
     * @return string Empty string on failure.
     */
    public static function get_qr_code_data_uri( $data, $size = '150x150' ) {
        $data = (string) $data;
        if ( '' === $data ) {
            return '';
        }

        $cache_key = 'wdcv_qr_' . md5( $size . '|' . $data );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $request_url = add_query_arg(
            array(
                'size' => $size,
                'data' => $data,
            ),
            'https://api.qrserver.com/v1/create-qr-code/'
        );

        $response = wp_remote_get(
            $request_url,
            array(
                'timeout'     => 15,
                'redirection' => 3,
            )
        );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $status_code  = (int) wp_remote_retrieve_response_code( $response );
        $body         = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );

        if ( 200 !== $status_code || empty( $body ) || empty( $content_type ) || 0 !== strpos( $content_type, 'image/' ) ) {
            return '';
        }

        $data_uri = 'data:' . $content_type . ';base64,' . base64_encode( $body );
        set_transient( $cache_key, $data_uri, 6 * HOUR_IN_SECONDS );

        return $data_uri;
    }

    public static function generate_unique_amount( $original_amount ) {
        global $wpdb;
        $transactions_table = $wpdb->prefix . 'shetab_transactions';

        $base = floor( $original_amount / 1000 ) * 1000;
        $tries = 0;
        while ( $tries < 20 ) {
            $suffix = mt_rand( 0, 999 );
            $final  = $base + $suffix;

            if ( $final < $original_amount ) {
                $tries++;
                continue;
            }

            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$transactions_table} WHERE unique_amount = %d AND status = 'pending'", $final ) );
            if ( (int) $exists === 0 ) {
                return $final;
            }

            $tries++;
        }

        return false;
    }
}








