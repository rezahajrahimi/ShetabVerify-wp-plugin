<?php

class Test_WebDide_CV_Diagnostics extends WP_UnitTestCase {

    public function test_run_diagnostics_sets_transient() {
        $res = wdcv_run_diagnostics();
        $this->assertIsArray( $res );

        $diag = get_transient( 'wdcv_debug_available' );
        $this->assertNotFalse( $diag );
        $this->assertArrayHasKey( 'time', $diag );
        $this->assertArrayHasKey( 'found', $diag );
    }
}






