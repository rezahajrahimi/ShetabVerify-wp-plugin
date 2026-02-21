<?php

class Test_ShetabVerify_REST extends WP_UnitTestCase {

    public function test_confirm_payment_endpoint_confirms_transaction() {
        // set secret
        $secret = 'test-secret-123';
        ShetabVerify_Utils::set_api_secret( $secret );

        // create a fake transaction
        $order_id = 123456;
        $unique_amount = 14123;
        $txn_id = ShetabVerify_DB::create_transaction( array(
            'order_id' => $order_id,
            'original_amount' => 14100,
            'unique_amount' => $unique_amount,
            'status' => 'pending',
            'expires_at' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 600 ),
        ) );

        // build request
        $request = new WP_REST_Request( 'POST', '/shetab-verify/v1/confirm' );
        $request->set_header( 'x-shetab-secret', $secret );
        $request->set_body_params( array(
            'order_id' => $order_id,
            'amount' => $unique_amount,
            'remote_ref' => 'BANK-REF-1',
        ) );

        $response = ShetabVerify_REST_Controller::confirm_payment( $request );

        $this->assertInstanceOf( 'WP_REST_Response', $response );
        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertEquals( 'confirmed', $data['message'] );

        // verify DB updated
        $txn = ShetabVerify_DB::get_transaction_by_order_id( $order_id );
        $this->assertEquals( 'confirmed', $txn->status );
        $this->assertEquals( 'BANK-REF-1', $txn->remote_ref );
    }
}
