=== ShetabVerify ===
Contributors: rezahajrahimi
Tags: woocommerce, payment, shetab, bank transfer, auto-verification, card-to-card, iran
Requires at least: 5.0
Tested up to: 6.4.3
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automate bank transfer (Card-to-Card) confirmations in WooCommerce using unique amount suffixes and a mobile app.

== Description ==

**ShetabVerify** is a sophisticated solution for WooCommerce stores in Iran that handle "Card-to-Card" payments. Instead of manual verification of transaction slips, this plugin automates the process using a specialized mobile application.

### Key Features:
*   **Unique Suffix Generation**: Generates a small unique suffix (e.g., 0-99 Tomans) for each order to distinguish between multiple transfers of the same base amount.
*   **Auto-Confirmation**: Connects to the Shetab mobile app to verify incoming transfer notifications automatically.
*   **Order Management**: Automatically changes order status from "On Hold" to "Processing" upon successful verification.
*   **Manual Fallback**: Support for manual slip upload and admin confirmation if needed.
*   **Security**: Uses encrypted storage for card numbers and secure API secrets for communication.
*   **WooCommerce Blocks Support**: Fully compatible with the modern WooCommerce Checkout Block.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/shetab-verify` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to **WooCommerce -> Settings -> Payments** and enable the **ShetabVerify** gateway.
4. Navigate to the **Shetab Management** menu in your WordPress dashboard to configure your API secret and destination cards.
5. Download and configure the companion Android app from [Cafe Bazaar](https://cafebazaar.ir/app/com.example.shetab_verification).

== Frequently Asked Questions ==

= Do I need a specific bank account? =
No, ShetabVerify works with any Iranian bank account that supports SMS notifications or specific app notifications supported by our companion app.

= Is it secure? =
Yes, all communication between the plugin and the mobile app is secured via a private API Secret. Card numbers are stored in an encrypted format.

= What happens if the app is offline? =
Orders will remain "On Hold". You can still manually verify payments via the order management screen in WooCommerce.

== Screenshots ==

1. Settings page with API configuration and card management.
2. Checkout page showing the ShetabVerify payment method.
3. Administrative interface for destination bank cards.

== Changelog ==

= 0.1.0 =
* Initial release.
* Automated Card-to-Card verification.
* Support for unique suffixes per order.
* WooCommerce Blocks integration.
* Admin management dashboard.
