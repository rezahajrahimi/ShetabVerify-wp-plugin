<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ShetabVerify_Deactivator {
    public static function deactivate() {
        // cleanup scheduled hooks if any were added later
        $timestamp = wp_next_scheduled( 'shetab_verify_cleanup_expired' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'shetab_verify_cleanup_expired' );
        }
    }
}
