<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WebDide_CV_Deactivator {
    public static function deactivate() {
        // cleanup scheduled hooks if any were added later
        $timestamp = wp_next_scheduled( 'wdcv_cleanup_expired' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wdcv_cleanup_expired' );
        }
    }
}







