<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/*
 * NOTE: by default we do not drop plugin tables or remove options to be safe.
 * If you want to remove all data on uninstall, uncomment the lines below.
 */

// delete_option( 'shetab_db_version' );
// global $wpdb;
// $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shetab_transactions" );
// $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shetab_cards" );






