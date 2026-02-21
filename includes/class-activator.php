<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ShetabVerify_Activator {
    public static function activate() {
        // create DB tables
        if ( ! class_exists( 'ShetabVerify_DB' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-db.php';
        }
        ShetabVerify_DB::create_tables();

        // schedule cleanup cron (every 5 minutes) if not scheduled
        if ( ! wp_next_scheduled( 'shetab_verify_cleanup_expired' ) ) {
            wp_schedule_event( time(), 'shetab_verify_every_5min', 'shetab_verify_cleanup_expired' );
        }

        // ensure WooCommerce gateway settings exist and are enabled by default
        $option_name = 'woocommerce_shetab_verify_settings';
        if ( false === get_option( $option_name ) ) {
            $defaults = array(
                'enabled' => 'yes',
                'title'   => __( 'Bank transfer (ShetabVerify)', 'shetab-verify' ),
            );
            add_option( $option_name, $defaults );
        }
    }
}
