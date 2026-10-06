<?php
/**
 * Plugin Name:       WebDide Card-to-Card Payment Verification for Shetab
 * Plugin URI:        http://verify.webdide.ir/
 * Description:       Automate WooCommerce card-to-card (کارت به کارت) payment verification via mobile app, receipt upload, and Telegram/Bale admin bots.
 * Version:           0.3.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Reza HajRahimi
 * Author URI:        http://webdide.ir/
 * Text Domain:       webdide-card-to-card-verification
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WDCV_MAIN_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/shetab-verify.php';

register_activation_hook( WDCV_MAIN_PLUGIN_FILE, array( 'WebDide_CV_Activator', 'activate' ) );
register_deactivation_hook( WDCV_MAIN_PLUGIN_FILE, array( 'WebDide_CV_Deactivator', 'deactivate' ) );
