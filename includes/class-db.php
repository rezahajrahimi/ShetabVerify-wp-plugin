<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ShetabVerify_DB {
    const VERSION = '0.1';

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $cards_table        = $wpdb->prefix . 'shetab_cards';
        $transactions_table = $wpdb->prefix . 'shetab_transactions';

        $sql = "
        CREATE TABLE {$cards_table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            label varchar(191) NOT NULL,
            encrypted_number text NOT NULL,
            masked_number varchar(32) NOT NULL,
            max_deposits_count int NOT NULL DEFAULT 0,
            max_total_amount bigint NOT NULL DEFAULT 0,
            reset_period varchar(20) NOT NULL DEFAULT 'none',
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset_collate};

        CREATE TABLE {$transactions_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            card_id mediumint(9) DEFAULT NULL,
            original_amount bigint NOT NULL,
            unique_amount bigint NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            expires_at datetime DEFAULT NULL,
            remote_ref varchar(191) DEFAULT NULL,
            raw_payload longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY unique_amount (unique_amount),
            KEY status (status)
        ) {$charset_collate};
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        add_option( 'shetab_db_version', self::VERSION );
    }

    /* Cards CRUD */
    public static function get_cards() {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_cards';
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
    }

    public static function get_active_cards() {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_cards';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE active = %d ORDER BY id ASC", 1 ) );
    }

    public static function insert_card( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_cards';
        $wpdb->insert( $table, array(
            'label' => sanitize_text_field( $data['label'] ),
            'encrypted_number' => $data['encrypted_number'],
            'masked_number' => sanitize_text_field( $data['masked_number'] ),
            'max_deposits_count' => absint( $data['max_deposits_count'] ),
            'max_total_amount' => absint( $data['max_total_amount'] ),
            'reset_period' => sanitize_text_field( $data['reset_period'] ),
            'active' => ! empty( $data['active'] ) ? 1 : 0,
        ) );
        return $wpdb->insert_id;
    }

    public static function delete_card( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_cards';
        return $wpdb->delete( $table, array( 'id' => absint( $id ) ), array( '%d' ) );
    }

    /* Transactions */
    public static function create_transaction( $args ) {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_transactions';
        $defaults = array(
            'order_id' => 0,
            'card_id' => null,
            'original_amount' => 0,
            'unique_amount' => 0,
            'status' => 'pending',
            'expires_at' => null,
            'remote_ref' => null,
            'raw_payload' => null,
        );
        $data = wp_parse_args( $args, $defaults );
        $wpdb->insert( $table, $data );
        return $wpdb->insert_id;
    }

    public static function get_transaction_by_order_id( $order_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_transactions';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1", absint( $order_id ) ) );
    }

    public static function get_pending_transaction_by_order_and_amount( $order_id, $amount ) {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_transactions';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND unique_amount = %d AND status = 'pending' LIMIT 1", absint( $order_id ), absint( $amount ) ) );
    }

    public static function get_pending_transaction_by_amount( $amount ) {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_transactions';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE unique_amount = %d AND status = 'pending' ORDER BY id DESC LIMIT 1", absint( $amount ) ) );
    }

    public static function get_card_usage( $card_id, $period = 'none' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_transactions';

        $where_date = "1=1";
        if ( $period === 'daily' ) {
            $start = date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
            $where_date = $wpdb->prepare( "created_at >= %s", $start );
        } elseif ( $period === 'monthly' ) {
            $start = date( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );
            $where_date = $wpdb->prepare( "created_at >= %s", $start );
        }

        // Only count 'confirmed' for usage tracking (actual money received)
        // Fix: Use direct interpolation for $where_date because $wpdb->prepare wraps %s in quotes
        $sql = $wpdb->prepare( "SELECT order_id, unique_amount, confirmed_at FROM {$table} WHERE card_id = %d AND status = 'confirmed' AND {$where_date}", absint( $card_id ) );
        $rows = $wpdb->get_results( $sql );
        
        $total_amount = 0;
        $order_details = array();
        foreach ( $rows as $r ) {
            $total_amount += $r->unique_amount;
            $order_details[] = array(
                'id' => (int) $r->order_id,
                'amount' => (int) $r->unique_amount,
                'date' => $r->confirmed_at
            );
        }

        return array( 
            'count' => count( $rows ), 
            'total' => (int) $total_amount,
            'orders' => $order_details
        );
    }

    public static function mark_transaction_confirmed( $transaction_id, $remote_ref = '', $raw_payload = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_transactions';
        $update = array(
            'status' => 'confirmed',
            'remote_ref' => sanitize_text_field( $remote_ref ),
            'confirmed_at' => current_time( 'mysql' ),
        );
        $formats = array( '%s', '%s', '%s' );

        if ( $raw_payload ) {
            $update['raw_payload'] = maybe_serialize( $raw_payload );
            $formats[] = '%s';
        }

        $wpdb->update( $table, $update, array( 'id' => absint( $transaction_id ) ), $formats, array( '%d' ) );
    }

    public static function cleanup_expired_transactions() {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_transactions';
        $now   = current_time( 'mysql' );

        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' AND expires_at <= %s", $now ) );
        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $r ) {
            $wpdb->update( $table, array( 'status' => 'expired' ), array( 'id' => $r->id ), array( '%s' ), array( '%d' ) );

            // cancel WooCommerce order if still pending/on-hold
            if ( function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( $r->order_id );
                if ( $order && ! in_array( $order->get_status(), array( 'cancelled', 'processing', 'completed' ), true ) ) {
                    $order->update_status( 'cancelled', __( 'Payment expired (ShetabVerify).', 'shetabverify' ) );
                }
            }
        }
    }
}

