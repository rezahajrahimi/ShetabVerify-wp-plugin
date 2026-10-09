=== Card to Card for WooCommerce – WebDide Shetab ===
Contributors: rezahajrahimi
Tags: کارت به کارت, card-to-card, woocommerce, bank-transfer, shetab
Requires at least: 5.0
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Card to card (کارت به کارت) WooCommerce payments with Shetab auto-verification, unique amounts, receipt upload, and Telegram/Bale bots.

== Description ==

**Card to card / کارت به کارت** for WooCommerce, with automatic deposit verification through the Shetab companion app.

WebDide Shetab helps Iranian stores accept bank card-to-card transfers without checking every slip by hand:

* Generate a unique payment amount suffix so similar transfers stay distinguishable
* Confirm deposits automatically through the companion Shetab Android app
* Let customers upload a payment slip when needed
* Notify store managers on **Telegram** or **Bale (بله)** with Approve / Reject buttons
* Mark the WooCommerce order paid or failed from the bot or the order screen

### Key Features
* Card to card (کارت به کارت) checkout for WooCommerce
* Unique amount suffixes for matching bank transfers
* Dedicated bank-like intermediate payment page before the thank-you screen
* Auto-confirmation via the Shetab mobile app
* Manual receipt upload and admin review
* Telegram & Bale bot review workflow (approve, or reject with a note)
* Encrypted card storage and API secret authentication
* WooCommerce Blocks checkout support

== External services ==

This plugin uses the following third-party services:

* **QR Code API (api.qrserver.com)**: Generates QR codes for the API Secret and endpoint URLs in the admin settings page, and for destination card / account / Sheba values on the customer payment page (so shoppers can scan them in their bank app).
  * Data sent: API Secret, REST API URLs, and destination card digits, account digits, or Sheba (IR + 24 digits) as URL parameters.
  * Provider: GoQR.me (Digital-Solutions.at).
  * Links: [Legal](https://goqr.me/legal/), [Privacy & Security](https://goqr.me/privacy-safety-security/).

* **Telegram Bot API (api.telegram.org)**: Optional. When enabled, sends uploaded receipt images and order summary to your admin chat, and receives Approve/Reject callbacks.
  * Data sent: Order ID, payment amount, receipt image URL, and button callback data.
  * Provider: Telegram Messenger Inc.
  * Links: [Telegram Bot API](https://core.telegram.org/bots/api), [Privacy Policy](https://telegram.org/privacy).

* **Bale Bot API (tapi.bale.ai)**: Optional. Same receipt review workflow as Telegram for Bale (بله) bots.
  * Data sent: Order ID, payment amount, receipt image URL, and button callback data.
  * Provider: Bale messenger.
  * Links: [Bale platform](https://ble.ir/).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/webdide-card-to-card-verification` directory, or install via Plugins → Add New.
2. Activate the plugin through the Plugins screen.
3. Go to **WooCommerce → Settings → Payments** and enable **Card to Card for WooCommerce – WebDide Shetab**.
4. Open **Shetab Management** to configure the API secret, destination cards, and optional Telegram/Bale bots.
5. Download the companion Android app from [Cafe Bazaar](https://cafebazaar.ir/app/ir.webdide.verify).

== Frequently Asked Questions ==

= Do I need a specific bank account? =
No. It works with Iranian accounts that provide SMS or app notifications supported by the companion app.

= Does it support کارت به کارت / card-to-card? =
Yes. The gateway is built for Iranian card-to-card (کارت به کارت) transfers with unique amount matching.

= Is it secure? =
Communication with the mobile app uses a private API Secret. Card numbers are stored encrypted. Bot webhooks only accept actions from configured chat IDs with signed callback data.

= What happens if the app is offline? =
Orders stay On Hold. Customers can upload a slip; you can confirm from WooCommerce or from Telegram/Bale.

= How do Telegram / Bale bots work? =
Enable a bot in Shetab Management, set the token and allowed chat IDs, then click Set webhook. When a receipt is uploaded, the bot sends the image with Approve / Reject. Reject asks for an optional note.

== Screenshots ==

1. Settings page with API configuration and card management.
2. Checkout page showing the WebDide payment method.
3. Administrative interface for destination bank cards.
4. Telegram/Bale bot settings for receipt review.

== Changelog ==

= 0.3.1 =
* Improve WordPress.org discoverability for card-to-card / کارت به کارت searches (title, tags, short description).

= 0.3.0 =
* Optional destination account number and Sheba (IBAN) on each card.
* Gateway toggles to show card, account, and/or Sheba on the payment page (each with copy + QR).
* Database migration for encrypted/masked account and Sheba fields.
* Tested and compatible with WordPress 7.1.2.

= 0.2.5 =
* Payment page: QR code for the destination card number so customers can scan it with their bank app.

= 0.2.4 =
* Intermediate bank-like payment page before WooCommerce thank-you.
* Fix infinite thank-you refresh after app confirmation.
* Show amount in Rials and Tomans; click-to-copy Rial amount and card number.
* Styled payment UI, receipt upload actions, and “Go to my orders” after slip upload.

= 0.2.0 =
* Tested up to WordPress 7.1.
* Proper English source strings with bundled Persian (fa_IR) translations.
* Improved directory discoverability for card-to-card / کارت به کارت.
* Telegram and Bale bot notifications for uploaded receipts.
* Approve or reject deposits from the bot; rejection supports an optional note.
* Shared approve/reject logic for WooCommerce order screen and bots.

= 0.1.0 =
* Initial release.
* Automated Card-to-Card verification.
* Support for unique suffixes per order.
* WooCommerce Blocks integration.
* Admin management dashboard.

== Upgrade Notice ==

= 0.3.1 =
Directory listing optimized for card-to-card / کارت به کارت discovery. No breaking changes.

= 0.3.0 =
Adds optional account/Sheba on destination cards and payment-page display toggles with copy and QR for each.

= 0.2.5 =
Adds a scannable card-number QR code on the payment page for bank apps.

= 0.2.4 =
Adds a dedicated payment page, fixes the post-confirmation refresh loop, and improves amount/card copy UX.

= 0.2.0 =
Adds Telegram/Bale receipt review bots, WordPress 7.1 compatibility, and improved Persian/English translations for the WordPress.org directory.
