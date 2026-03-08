<?php

class Test_WebDide_CV_Gateway extends WP_UnitTestCase {

    public function test_gateway_settings_default_enabled() {
        $opt = get_option( 'woocommerce_wdcv_settings' );
        $this->assertIsArray( $opt );
        $this->assertArrayHasKey( 'enabled', $opt );
        $this->assertEquals( 'yes', $opt['enabled'] );
    }

    public function test_gateway_filter_registers_class() {
        $registered = apply_filters( 'woocommerce_payment_gateways', array() );
        $this->assertContains( 'WC_Gateway_WDCV', $registered );
    }

    public function test_gateway_available_in_cart() {
        if ( ! function_exists( 'wc_get_product' ) ) {
            $this->markTestSkipped( 'WooCommerce not available in test environment.' );
        }

        // ensure at least one active card exists (plugin requires this to display the gateway)
        WebDide_CV_DB::insert_card( array(
            'label' => 'Unit test card',
            'encrypted_number' => 'enc',
            'masked_number' => '**** **** **** 1111',
            'max_deposits_count' => 0,
            'max_total_amount' => 0,
            'reset_period' => 'none',
            'active' => 1,
        ) );

        // create a simple product and add to cart
        $product_id = $this->factory->product->create( array( 'regular_price' => '10.00' ) );
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart( $product_id );

        $available = WC()->payment_gateways()->get_available_payment_gateways();
        $this->assertArrayHasKey( 'wdcv', $available );
    }

    public function test_is_available_debug_transient_set() {
        if ( ! function_exists( 'wc_get_product' ) ) {
            $this->markTestSkipped( 'WooCommerce not available in test environment.' );
        }

        $gw = new WC_Gateway_WDCV();
        $gw->is_available();

        $dbg = get_transient( 'wdcv_is_available_debug' );
        $this->assertIsArray( $dbg );
        $this->assertArrayHasKey( 'result', $dbg );
    }
}







