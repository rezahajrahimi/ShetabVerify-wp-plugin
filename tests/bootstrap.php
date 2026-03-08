<?php
// Test bootstrap for WebDide_CV

// Adjust path as needed for PHPUnit WP tests.
if ( file_exists( __DIR__ . '/../WebDide_CV.php' ) ) {
    require_once __DIR__ . '/../WebDide_CV.php';
}

// Ensure DB tables exist for tests
if ( class_exists( 'WebDide_CV_Activator' ) ) {
    WebDide_CV_Activator::activate();
}







