<?php
// Test bootstrap for ShetabVerify

// Adjust path as needed for PHPUnit WP tests.
if ( file_exists( __DIR__ . '/../shetabverify.php' ) ) {
    require_once __DIR__ . '/../shetabverify.php';
}

// Ensure DB tables exist for tests
if ( class_exists( 'ShetabVerify_Activator' ) ) {
    ShetabVerify_Activator::activate();
}

