<?php
/**
 * Plugin Name: WebDide Card-to-Card Payment Verification for Shetab
 * Plugin URI:  http://verify.webdide.ir/
 * Description: Payment gateway — Automated Card-to-Card transaction confirmation via mobile app.
 * Version:     0.1.0
 * Author:      Reza HajRahimi
 * Author URI:  http://webdide.ir/
 * Text Domain: webdide-card-to-card-verification
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/shetab-verify.php';
