=== CashiPay Payment Gateway ===
Contributors: cashipay
Tags: woocommerce, payment gateway, cashipay, wallet, qr code, otp, sudan
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

CashiPay wallet payment gateway for WooCommerce — QR-code & OTP payments with per-payment webhook authentication.

== Description ==

Integrates the CashiPay digital wallet payment gateway with WooCommerce. Customers can pay using a QR code (scanned with the CashiPay app) or via OTP sent to their wallet number.

**Features**

* QR code payments — customer scans with the CashiPay app at checkout
* OTP payments — customer enters their wallet number, receives an OTP
* Both modes can be offered simultaneously; customer chooses
* Per-payment webhook authentication — no shared webhook secret required
* Real-time payment status polling (every 5 seconds)
* Automatic WooCommerce order status updates on payment events
* Staging and production environment support
* HPOS (High-Performance Order Storage) compatible

**Webhook actions (for developers)**

* `cashipay_payment_completed` — fires when a payment is confirmed
* `cashipay_payment_failed` — fires when a payment expires, is cancelled, or fails
* `cashipay_webhook_received` — fires on every incoming webhook

== Installation ==

1. Upload the `cashipay-payment-gateway` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins → Installed Plugins**.
3. Go to **WooCommerce → Settings → Payments → CashiPay** and configure:
   - Select **Environment** (Staging for testing, Production for live).
   - Paste your **API Key** for the chosen environment.
   - Choose a **Payment Mode** (QR, OTP, or both).
4. Save settings. CashiPay will now appear as a payment option at checkout.

**Webhook URL**

CashiPay will send payment notifications to:

    https://yoursite.com/wp-json/cashipay/v1/webhook/{per-payment-key}

This URL is generated automatically per order — no manual configuration needed.

== Frequently Asked Questions ==

= Where do I get my API key? =

Log in to your CashiPay merchant dashboard and copy the API key for the desired environment.

= Do I need to configure a webhook URL in my CashiPay dashboard? =

No. The plugin uses a unique per-payment key embedded in the callback URL, so no shared webhook secret is required.

= The QR code image is not showing. =

Make sure your staging/production API key is correct. The QR `dataUrl` is returned by the CashiPay API in the payment creation response.

= Can I hook into payment events? =

Yes. Use the `cashipay_payment_completed`, `cashipay_payment_failed`, and `cashipay_webhook_received` action hooks.

== Screenshots ==

1. Gateway settings screen in WooCommerce.
2. QR code payment page shown to the customer.
3. OTP payment form shown to the customer.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
