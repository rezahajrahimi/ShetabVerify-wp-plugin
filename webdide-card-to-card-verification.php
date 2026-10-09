<?php
/**
 * Plugin Name:       Card to Card for WooCommerce – WebDide Shetab
 * Plugin URI:        http://verify.webdide.ir/
 * Description:       Card to card (کارت به کارت) WooCommerce payments with Shetab auto-verification, receipt upload, and Telegram/Bale bots.
 * Version:           0.3.1
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
