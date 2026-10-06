<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WebDide_CV_DB {
    const VERSION = '0.2';

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
            encrypted_account text NULL,
            masked_account varchar(64) NULL,
            encrypted_sheba text NULL,
            masked_sheba varchar(64) NULL,
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

        update_option( 'shetab_db_version', self::VERSION );
    }

    /**
     * Ensure schema is up to date on existing installs.
     */
    public static function maybe_upgrade() {
        $installed = get_option( 'shetab_db_version', '0' );
        if ( version_compare( (string) $installed, self::VERSION, '<' ) ) {
            self::create_tables();
        }
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

    public static function get_card( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_cards';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $id ) ) );
    }

    public static function insert_card( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_cards';
        $wpdb->insert( $table, array(
            'label'              => sanitize_text_field( $data['label'] ),
            'encrypted_number'   => $data['encrypted_number'],
            'masked_number'      => sanitize_text_field( $data['masked_number'] ),
            'encrypted_account'  => isset( $data['encrypted_account'] ) ? $data['encrypted_account'] : null,
            'masked_account'     => isset( $data['masked_account'] ) ? sanitize_text_field( $data['masked_account'] ) : null,
            'encrypted_sheba'    => isset( $data['encrypted_sheba'] ) ? $data['encrypted_sheba'] : null,
            'masked_sheba'       => isset( $data['masked_sheba'] ) ? sanitize_text_field( $data['masked_sheba'] ) : null,
            'max_deposits_count' => absint( $data['max_deposits_count'] ),
            'max_total_amount'   => absint( $data['max_total_amount'] ),
            'reset_period'       => sanitize_text_field( $data['reset_period'] ),
            'active'             => ! empty( $data['active'] ) ? 1 : 0,
        ) );
        return $wpdb->insert_id;
    }

    public static function update_card( $id, $data ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'shetab_cards';
        $update = array(
            'label'              => sanitize_text_field( $data['label'] ),
            'max_deposits_count' => absint( $data['max_deposits_count'] ),
            'max_total_amount'   => absint( $data['max_total_amount'] ),
            'reset_period'       => sanitize_text_field( $data['reset_period'] ),
            'active'             => ! empty( $data['active'] ) ? 1 : 0,
        );
        $formats = array( '%s', '%d', '%d', '%s', '%d' );

        if ( array_key_exists( 'encrypted_number', $data ) ) {
            $update['encrypted_number'] = $data['encrypted_number'];
            $update['masked_number']    = isset( $data['masked_number'] ) ? sanitize_text_field( $data['masked_number'] ) : '';
            $formats[]                  = '%s';
            $formats[]                  = '%s';
        }

        if ( array_key_exists( 'encrypted_account', $data ) ) {
            $update['encrypted_account'] = $data['encrypted_account'];
            $update['masked_account']    = isset( $data['masked_account'] ) ? sanitize_text_field( $data['masked_account'] ) : '';
            $formats[]                   = '%s';
            $formats[]                   = '%s';
        }

        if ( array_key_exists( 'encrypted_sheba', $data ) ) {
            $update['encrypted_sheba'] = $data['encrypted_sheba'];
            $update['masked_sheba']    = isset( $data['masked_sheba'] ) ? sanitize_text_field( $data['masked_sheba'] ) : '';
            $formats[]                 = '%s';
            $formats[]                 = '%s';
        }

        return $wpdb->update( $table, $update, array( 'id' => absint( $id ) ), $formats, array( '%d' ) );
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

    /**
     * Mark a transaction as rejected (e.g. after admin invalidates a slip).
     *
     * @param int    $transaction_id Transaction ID.
     * @param string $remote_ref     Optional source label.
     */
    public static function mark_transaction_rejected( $transaction_id, $remote_ref = '' ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'shetab_transactions';
        $update = array(
            'status' => 'rejected',
        );
        $formats = array( '%s' );

        if ( '' !== $remote_ref ) {
            $update['remote_ref'] = sanitize_text_field( $remote_ref );
            $formats[]            = '%s';
        }

        $wpdb->update( $table, $update, array( 'id' => absint( $transaction_id ) ), $formats, array( '%d' ) );
    }

    /**
     * Aggregate statistics for the admin dashboard.
     *
     * @return array
     */
    public static function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'shetab_transactions';
        $cards_table = $wpdb->prefix . 'shetab_cards';

        $today_start   = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
        $month_start   = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );

        $by_status = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(unique_amount), 0) AS total
             FROM {$table}
             GROUP BY status",
            OBJECT_K
        );

        $statuses = array( 'pending', 'confirmed', 'expired', 'rejected' );
        $summary  = array();
        foreach ( $statuses as $status ) {
            $row = isset( $by_status[ $status ] ) ? $by_status[ $status ] : null;
            $summary[ $status ] = array(
                'count'  => $row ? (int) $row->cnt : 0,
                'amount' => $row ? (int) $row->total : 0,
            );
        }

        $today = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(unique_amount), 0) AS total
                 FROM {$table}
                 WHERE status = 'confirmed' AND confirmed_at >= %s",
                $today_start
            )
        );

        $month = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(unique_amount), 0) AS total
                 FROM {$table}
                 WHERE status = 'confirmed' AND confirmed_at >= %s",
                $month_start
            )
        );

        $by_card = $wpdb->get_results(
            "SELECT c.id, c.label, c.masked_number, c.active,
                    COUNT(t.id) AS confirmed_count,
                    COALESCE(SUM(t.unique_amount), 0) AS confirmed_amount
             FROM {$cards_table} c
             LEFT JOIN {$table} t ON t.card_id = c.id AND t.status = 'confirmed'
             GROUP BY c.id
             ORDER BY confirmed_amount DESC, c.id DESC"
        );

        $recent = $wpdb->get_results(
            "SELECT t.id, t.order_id, t.unique_amount, t.status, t.confirmed_at, t.created_at, t.remote_ref, c.label AS card_label
             FROM {$table} t
             LEFT JOIN {$cards_table} c ON c.id = t.card_id
             ORDER BY COALESCE(t.confirmed_at, t.created_at) DESC
             LIMIT 20"
        );

        $receipt_orders = 0;
        $pm_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                '_shetab_receipts'
            )
        );
        $receipt_orders = max( $receipt_orders, $pm_count );
        $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta_table ) );
        if ( $table_exists === $orders_meta_table ) {
            $hpos_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT order_id) FROM {$orders_meta_table} WHERE meta_key = %s",
                    '_shetab_receipts'
                )
            );
            $receipt_orders = max( $receipt_orders, $hpos_count );
        }

        return array(
            'summary'         => $summary,
            'today'           => array(
                'count'  => $today ? (int) $today->cnt : 0,
                'amount' => $today ? (int) $today->total : 0,
            ),
            'month'           => array(
                'count'  => $month ? (int) $month->cnt : 0,
                'amount' => $month ? (int) $month->total : 0,
            ),
            'by_card'         => $by_card ? $by_card : array(),
            'recent'          => $recent ? $recent : array(),
            'receipt_orders'  => (int) $receipt_orders,
            'cards_total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cards_table}" ),
            'cards_active'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$cards_table} WHERE active = %d", 1 ) ),
        );
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
                    $order->update_status( 'cancelled', __( 'Payment expired (WebDide_CV).', 'webdide-card-to-card-verification' ) );
                }
            }
        }
    }
}









