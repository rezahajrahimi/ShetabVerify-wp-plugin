<?php

class Test_ShetabVerify_Utils extends WP_UnitTestCase {

    public function test_generate_unique_amount_returns_valid_value() {
        $original = 12000;
        $unique = ShetabVerify_Utils::generate_unique_amount( $original );
        $this->assertIsInt( $unique );
        $this->assertGreaterThanOrEqual( $original, $unique );
        $this->assertLessThan( $original + 1000, $unique + 1000 ); // should be within same thousand base range
    }

    public function test_generate_unique_amount_avoids_existing_pending() {
        global $wpdb;

        $original = 13000;
        $transactions_table = $wpdb->prefix . 'shetab_transactions';

        // insert a pending transaction with a possible conflicting unique_amount
        $conflict_amount = floor( $original / 1000 ) * 1000 + 5; // e.g. 13005
        $wpdb->insert( $transactions_table, array(
            'order_id' => 999999,
            'original_amount' => $original,
            'unique_amount' => $conflict_amount,
            'status' => 'pending',
            'created_at' => current_time( 'mysql' ),
        ) );

        // run generator multiple times — it should not return the conflicted amount
        for ( $i = 0; $i < 5; $i++ ) {
            $res = ShetabVerify_Utils::generate_unique_amount( $original );
            $this->assertNotEquals( $conflict_amount, $res );
            $this->assertGreaterThanOrEqual( $original, $res );
        }
    }
}
