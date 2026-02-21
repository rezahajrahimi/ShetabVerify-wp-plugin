<?php

class Test_ShetabVerify_Diagnostics extends WP_UnitTestCase {

    public function test_run_diagnostics_sets_transient() {
        $res = shetab_verify_run_diagnostics();
        $this->assertIsArray( $res );

        $diag = get_transient( 'shetab_verify_debug_available' );
        $this->assertNotFalse( $diag );
        $this->assertArrayHasKey( 'time', $diag );
        $this->assertArrayHasKey( 'found', $diag );
    }
}
