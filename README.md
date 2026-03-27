# CashiPay Payment Gateway for WooCommerce

WooCommerce payment gateway for the **CashiPay** digital wallet — QR-code scanning, OTP confirmation, real-time order updates, and per-payment webhook authentication.

**Supports:** WordPress 5.8+ · WooCommerce 6.0+ · PHP 7.4+

---

## Features

- **QR Code payments** — customer scans with the CashiPay mobile app; no manual data entry
- **OTP payments** — customer enters their wallet number at checkout and confirms with a one-time password
- **Dual mode** — offer both simultaneously and let the customer choose
- **Per-payment webhook keys** — each order gets a unique callback URL; no shared secret needed
- **Replay protection** — processed webhooks are permanently marked and silently ignored
- **Concurrency lock** — prevents duplicate order completion under parallel webhook delivery
- **Real-time status polling** — payment page polls every 5 seconds and redirects automatically on confirmation
- **Structured logging** — full diagnostic log under WooCommerce → Status → Logs → `cashipay`
- **Refund support** — pending payments can be cancelled via the WooCommerce refund interface
- **HPOS compatible** — supports WooCommerce High-Performance Order Storage

---

## Installation

### From WordPress admin

1. Go to **Plugins → Add New** and search for **CashiPay Payment Gateway**
2. Click **Install Now**, then **Activate**
3. Go to **WooCommerce → Settings → Payments → CashiPay**
4. Enter your API key, select your environment, and save

### Manual

1. Download or clone this repository
2. Upload the `cashipay-payment-gateway` folder to `/wp-content/plugins/`
3. Activate via **Plugins → Installed Plugins**
4. Configure under **WooCommerce → Settings → Payments → CashiPay**

---

## Configuration

| Setting | Description |
|---|---|
| **Environment** | `Staging` for testing, `Production` for live transactions |
| **Staging API Key** | Your CashiPay test API key |
| **Production API Key** | Your CashiPay live API key — never share or commit this |
| **Currency Code** | ISO 4217 currency code sent with each request (default: `SDG`) |
| **Payment Mode** | `QR Code only`, `OTP only`, or `QR Code & OTP` |

---

## Payment Flows

### QR Code

1. Customer selects CashiPay at checkout and places the order
2. A unique QR code is displayed on the payment page
3. Customer scans with the CashiPay app and approves in-app
4. The order status updates to **Processing** automatically

### OTP

1. Customer enters their wallet number at checkout and places the order
2. An OTP is sent to the wallet
3. Customer enters the OTP on the payment page and clicks **Confirm Payment**
4. The order status updates to **Processing** immediately

---

## Webhook

The plugin registers a REST API endpoint automatically:

```
POST https://yourstore.com/wp-json/cashipay/v1/webhook/{per-payment-key}
```

Each order gets its own unique key embedded in the URL. No webhook URL configuration is required in the CashiPay merchant dashboard.

---

## Developer Hooks

Extend or react to payment events without modifying plugin files:

```php
// Fires when CashiPay confirms a successful payment.
add_action( 'cashipay_payment_completed', function( WC_Order $order, array $payload ) {
    $reference = $payload['referenceNumber'] ?? '';
    // your logic here
}, 10, 2 );

// Fires when a payment fails, expires, or is cancelled.
// $status is one of: FAILED, EXPIRED, CANCELLED
add_action( 'cashipay_payment_failed', function( WC_Order $order, array $payload, string $status ) {
    // your logic here
}, 10, 3 );

// Fires on every inbound webhook regardless of status — useful for auditing.
add_action( 'cashipay_webhook_received', function( WC_Order $order, array $payload ) {
    // your logic here
}, 10, 2 );
```

---

## Debugging

All gateway activity is logged to:

**WooCommerce → Status → Logs → cashipay**

Logged events include payment creation, webhook receipt, order status transitions, and API errors. API keys and OTPs are never written to logs.

---

## Refunds

For **pending** payments, click **Refund** on the WooCommerce order page — the plugin sends a cancellation request to CashiPay automatically.

For **completed** payments, process the refund manually in your CashiPay merchant dashboard. The CashiPay API does not expose a programmatic refund endpoint for settled transactions.

---

## Changelog

### 1.0.1
- Security: nonce + order-key ownership verification on all AJAX endpoints
- Security: raw API errors no longer shown to customers; logged internally instead
- Security: replay protection — processed webhooks are marked and ignored on redelivery
- Security: transient lock prevents duplicate `payment_complete()` on parallel delivery
- Fix: OTP confirmation now calls `payment_complete()` immediately on API success
- Fix: receipt page redirects to order confirmation if the order is already paid
- Fix: polling timeout now shows a clear message and stops the spinner after 10 minutes
- Fix: `CANCELLED` maps to WooCommerce `cancelled`; `FAILED`/`EXPIRED` map to `failed`
- Improvement: `merchantOrderId` uses the public order number, not the internal post ID
- Improvement: currency code is normalized before sending to the API
- Improvement: added `process_refund()` for pending payment cancellation
- Improvement: full structured logging via `wc_get_logger()`

### 1.0.0
- Initial release

---

## License

MIT — see [LICENSE](LICENSE) for details.
