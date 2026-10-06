<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WebDide_CV_Activator {
    public static function activate() {
        // create DB tables
        if ( ! class_exists( 'WebDide_CV_DB' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-db.php';
        }
        WebDide_CV_DB::create_tables();

        // schedule cleanup cron (every 5 minutes) if not scheduled
        if ( ! wp_next_scheduled( 'wdcv_cleanup_expired' ) ) {
            wp_schedule_event( time(), 'wdcv_every_5min', 'wdcv_cleanup_expired' );
        }

        // ensure WooCommerce gateway settings exist and are enabled by default
        $option_name = 'woocommerce_wdcv_settings';
        if ( false === get_option( $option_name ) ) {
            $defaults = array(
                'enabled' => 'yes',
                'title'   => __( 'Bank transfer (WebDide_CV)', 'webdide-card-to-card-verification' ),
            );
            add_option( $option_name, $defaults );
        }

        // Generate a secret on first install so the admin page is ready to use immediately.
        if ( ! WebDide_CV_Utils::get_api_secret() ) {
            WebDide_CV_Utils::set_api_secret( wp_generate_password( 32, false, false ) );
        }
    }
}








