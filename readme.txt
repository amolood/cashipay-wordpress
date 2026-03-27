=== CashiPay Payment Gateway ===
Contributors: cashipay
Tags: woocommerce, payment gateway, cashipay, wallet, qr code, otp, sudan
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept payments through the CashiPay digital wallet directly in WooCommerce — QR-code scanning, OTP confirmation, and real-time order updates.

== Description ==

**CashiPay Payment Gateway** integrates the CashiPay digital wallet into your WooCommerce store, giving customers a fast, familiar checkout experience using their existing CashiPay wallet balance.

Customers can pay by scanning a QR code with the CashiPay mobile app, or by entering their wallet number at checkout and confirming with a one-time password (OTP). Both methods work in parallel — you choose which to offer.

Orders update automatically the moment payment is confirmed, cancelled, or expired, with no manual reconciliation required.

= Key Features =

**Payment methods**

* **QR Code** — customer scans the code with the CashiPay app; no manual data entry required
* **OTP** — customer enters their wallet number at checkout and approves with a 4–8 digit OTP
* **Dual mode** — offer both simultaneously and let the customer choose

**Security**

* Per-payment webhook keys — each order gets a unique, unguessable callback URL; no shared secret is needed
* Replay protection — processed webhooks are permanently marked and silently ignored on repeat delivery
* Concurrency lock — a transient mutex prevents duplicate order completion under parallel webhook delivery
* Order ownership verification — every AJAX request is bound to the order key, preventing cross-order enumeration

**Reliability**

* Real-time status polling every 5 seconds while the customer is on the payment page
* Automatic fallback redirect after 10 minutes if no webhook is received
* Detailed WooCommerce order notes and a structured log under **WooCommerce → Status → Logs → cashipay**
* Failed, expired, and cancelled payments are handled separately with the correct WooCommerce status

**Developer friendly**

* Three action hooks for custom integrations (see below)
* Clean separation between API client, gateway, and webhook handler
* HPOS (High-Performance Order Storage) compatible
* Staging and production environments, switchable from the settings screen

= Action Hooks =

Extend or react to payment events without modifying plugin files:

`do_action( 'cashipay_payment_completed', WC_Order $order, array $payload )`
Fires when CashiPay confirms a successful payment.

`do_action( 'cashipay_payment_failed', WC_Order $order, array $payload, string $status )`
Fires when a payment is marked as failed or expired. `$status` is one of `FAILED`, `EXPIRED`, or `CANCELLED`.

`do_action( 'cashipay_webhook_received', WC_Order $order, array $payload )`
Fires on every inbound webhook, regardless of status. Useful for logging and auditing.

= Example: Send a custom notification on payment completion =

    add_action( 'cashipay_payment_completed', function( $order, $payload ) {
        // $order is a fully hydrated WC_Order object.
        $reference = $payload['referenceNumber'] ?? '';
        // Your custom logic here.
    }, 10, 2 );

== Installation ==

= Minimum Requirements =

* WordPress 5.8 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher
* An active CashiPay merchant account with a valid API key

= From the WordPress admin =

1. Go to **Plugins → Add New** and search for **CashiPay Payment Gateway**.
2. Click **Install Now**, then **Activate**.
3. Go to **WooCommerce → Settings → Payments → CashiPay**.
4. Enter your API key, select your environment, and save.

= Manual installation =

1. Download the plugin zip and extract it.
2. Upload the `cashipay-payment-gateway` folder to `/wp-content/plugins/`.
3. Activate via **Plugins → Installed Plugins**.
4. Configure under **WooCommerce → Settings → Payments → CashiPay**.

= Configuration =

| Setting | Description |
|---|---|
| **Environment** | `Staging` for development and testing; `Production` for live transactions. |
| **Staging API Key** | Your CashiPay test API key. Used when environment is set to Staging. |
| **Production API Key** | Your CashiPay live API key. Never share or commit this value. |
| **Currency Code** | ISO 4217 currency code sent to CashiPay with each payment request (default: `SDG`). |
| **Payment Mode** | `QR Code only`, `OTP only`, or `QR Code & OTP` (customer chooses at checkout). |

= Webhook URL =

The plugin generates a unique callback URL per order automatically:

    https://yourstore.com/wp-json/cashipay/v1/webhook/{per-payment-key}

No webhook URL configuration is required in your CashiPay merchant dashboard. The per-payment key in the URL acts as the authentication token for each transaction.

== Frequently Asked Questions ==

= Where do I get my API key? =

Log in to your CashiPay merchant dashboard and navigate to **API Keys** or **Developer Settings**. Copy the key for the environment you want to use (staging or production).

= Do I need to configure a webhook URL in my CashiPay dashboard? =

No. Each payment request embeds a unique, unguessable key in its callback URL. The plugin authenticates incoming webhooks using that key — no shared secret or dashboard configuration is required.

= The QR code is not appearing on the payment page. =

Check the following:

1. Confirm the correct API key is entered for the active environment under **WooCommerce → Settings → Payments → CashiPay**.
2. Check **WooCommerce → Status → Logs → cashipay** for any API errors returned during payment creation.
3. Verify that your server can reach the CashiPay API over HTTPS (outbound port 443).

= Payment is confirmed in CashiPay but the WooCommerce order is still pending. =

This usually means the webhook did not reach your store. Check:

1. Your site is publicly accessible (not behind a firewall or in local development).
2. **WooCommerce → Status → Logs → cashipay** for any errors during webhook processing.
3. The WordPress REST API is enabled — visit `https://yourstore.com/wp-json/` to verify.

= Does this plugin support refunds? =

Partially. For orders that are still pending payment, the plugin can send a cancellation request to CashiPay via **WooCommerce → Orders → Refund**. For completed payments, the refund must be processed manually in your CashiPay merchant dashboard, as the CashiPay API does not expose a programmatic refund endpoint for settled transactions.

= Is the plugin compatible with the WooCommerce block checkout? =

The current version supports the classic WooCommerce checkout only. Block checkout compatibility is planned for a future release.

= How do I test the integration before going live? =

1. Set **Environment** to `Staging` and enter your staging API key.
2. Place a test order on your store.
3. Use the CashiPay test app or sandbox tools to complete or decline the payment.
4. Verify the WooCommerce order status updates correctly and check the `cashipay` log for details.
5. Once satisfied, switch **Environment** to `Production` and enter your live API key.

= Can I use this plugin on a multisite installation? =

Yes. The plugin is network-activatable. Each subsite manages its own gateway settings independently.

== Screenshots ==

1. CashiPay gateway settings page in WooCommerce.
2. Checkout page showing the wallet number field (OTP mode).
3. Payment page showing the QR code for scanning.
4. Payment page showing the OTP confirmation form.
5. WooCommerce order with CashiPay payment notes and reference number.

== Changelog ==

= 1.0.1 =
* Security: added nonce verification and order-key ownership check to all AJAX endpoints.
* Security: raw API error messages are no longer shown to customers; they are logged internally.
* Security: webhook replay protection — processed webhooks are marked and silently ignored.
* Security: transient lock prevents duplicate `payment_complete()` calls under parallel delivery.
* Fix: OTP confirmation now calls `payment_complete()` immediately on API success, without waiting for the webhook.
* Fix: receipt page now redirects to the order confirmation screen if the order is already paid.
* Fix: `process_payment()` now guards against `wc_get_order()` returning false.
* Fix: polling timeout now shows a clear message and stops the spinner after 10 minutes.
* Fix: scripts and nonces are only enqueued for CashiPay orders on the pay page.
* Fix: `CANCELLED` payments now map to WooCommerce `cancelled` status; only `FAILED`/`EXPIRED` map to `failed`.
* Improvement: `merchantOrderId` now uses the public order number instead of the internal post ID.
* Improvement: currency code is normalized (uppercased, trimmed) before sending to the API.
* Improvement: added `process_refund()` support for pending payment cancellation via the API.
* Improvement: full structured logging via `wc_get_logger()` under the `cashipay` source.
* Improvement: added Settings link on the Plugins page.
* Improvement: added `Domain Path`, `Plugin URI`, and `Author URI` to the plugin header.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.1 =
Recommended security update. Adds nonce and ownership verification to AJAX endpoints, replay protection on webhooks, and fixes the OTP payment completion path. Upgrade before processing live transactions.

= 1.0.0 =
Initial release — no upgrade steps required.
