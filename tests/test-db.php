<?php

class Test_ShetabVerify_DB extends WP_UnitTestCase {

    public function test_cleanup_expired_transactions_marks_expired() {
        global $wpdb;

        $transactions_table = $wpdb->prefix . 'shetab_transactions';

        $order_id = 555111;
        $unique_amount = 99999;
        $expired_at = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 60 );

        $txn_id = ShetabVerify_DB::create_transaction( array(
            'order_id' => $order_id,
            'original_amount' => 99900,
            'unique_amount' => $unique_amount,
            'status' => 'pending',
            'expires_at' => $expired_at,
        ) );

        // run cleanup
        ShetabVerify_DB::cleanup_expired_transactions();

        $txn = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$transactions_table} WHERE id = %d", $txn_id ) );
        $this->assertEquals( 'expired', $txn->status );
    }

    public function test_card_usage_and_limits() {
        global $wpdb;

        // create a card with limits
        $card_id = ShetabVerify_DB::insert_card( array(
            'label' => 'Test Card',
            'encrypted_number' => 'enc',
            'masked_number' => '**** **** **** 1111',
            'max_deposits_count' => 2,
            'max_total_amount' => 5000,
            'reset_period' => 'none',
            'active' => 1,
        ) );

        // insert two transactions for this card
        ShetabVerify_DB::create_transaction( array(
            'order_id' => 1,
            'card_id' => $card_id,
            'original_amount' => 2000,
            'unique_amount' => 2000,
            'status' => 'confirmed',
            'created_at' => current_time( 'mysql' ),
        ) );
        ShetabVerify_DB::create_transaction( array(
            'order_id' => 2,
            'card_id' => $card_id,
            'original_amount' => 2000,
            'unique_amount' => 2000,
            'status' => 'confirmed',
            'created_at' => current_time( 'mysql' ),
        ) );

        $usage = ShetabVerify_DB::get_card_usage( $card_id, 'none' );
        $this->assertEquals( 2, $usage['count'] );
        $this->assertEquals( 4000, $usage['total'] );

        // adding one more should exceed max_deposits_count (2)
        $this->assertFalse( ( $usage['count'] < 2 ) );

        // but total (4000) is still below 5000
        $this->assertTrue( $usage['total'] < 5000 );
    }
}
