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








