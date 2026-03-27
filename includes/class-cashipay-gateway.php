<?php
defined('ABSPATH') || exit;

class WC_CashiPay_Gateway extends WC_Payment_Gateway {

    private CashiPay_API $api;

    public function __construct() {
        $this->id                 = 'cashipay';
        $this->has_fields         = true;
        $this->method_title       = __('CashiPay', 'cashipay');
        $this->method_description = __('Accept payments via CashiPay wallet — QR-code & OTP.', 'cashipay');
        $this->supports           = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title', __('CashiPay Wallet', 'cashipay'));
        $this->description = $this->get_option('description', '');

        $this->api = new CashiPay_API(
            $this->get_option('environment', 'staging'),
            (string) $this->get_option('staging_api_key', ''),
            (string) $this->get_option('production_api_key', '')
        );

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_cashipay_confirm_otp',         [$this, 'ajax_confirm_otp']);
        add_action('wp_ajax_nopriv_cashipay_confirm_otp',  [$this, 'ajax_confirm_otp']);
        add_action('wp_ajax_cashipay_check_status',        [$this, 'ajax_check_status']);
        add_action('wp_ajax_nopriv_cashipay_check_status', [$this, 'ajax_check_status']);
    }

    // -------------------------------------------------------------------------
    // Admin settings
    // -------------------------------------------------------------------------

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable / Disable', 'cashipay'),
                'type'    => 'checkbox',
                'label'   => __('Enable CashiPay Payment Gateway', 'cashipay'),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'cashipay'),
                'type'        => 'text',
                'description' => __('Payment method title shown to customers at checkout.', 'cashipay'),
                'default'     => __('CashiPay Wallet', 'cashipay'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'   => __('Description', 'cashipay'),
                'type'    => 'textarea',
                'default' => __('Pay securely using your CashiPay wallet via QR code or OTP.', 'cashipay'),
            ],
            'environment' => [
                'title'   => __('Environment', 'cashipay'),
                'type'    => 'select',
                'options' => [
                    'staging'    => __('Staging (test)', 'cashipay'),
                    'production' => __('Production (live)', 'cashipay'),
                ],
                'default'     => 'staging',
                'description' => __('Use Staging for testing. Switch to Production when you go live.', 'cashipay'),
                'desc_tip'    => true,
            ],
            'staging_api_key' => [
                'title'       => __('Staging API Key', 'cashipay'),
                'type'        => 'password',
                'description' => __('Your CashiPay staging (test) API key.', 'cashipay'),
                'desc_tip'    => true,
                'default'     => '',
            ],
            'production_api_key' => [
                'title'       => __('Production API Key', 'cashipay'),
                'type'        => 'password',
                'description' => __('Your CashiPay live API key. Keep this secret.', 'cashipay'),
                'desc_tip'    => true,
                'default'     => '',
            ],
            'currency' => [
                'title'       => __('Currency Code', 'cashipay'),
                'type'        => 'text',
                'default'     => 'SDG',
                'description' => __('ISO currency code sent to CashiPay (e.g. SDG).', 'cashipay'),
                'desc_tip'    => true,
            ],
            'payment_mode' => [
                'title'   => __('Payment Mode', 'cashipay'),
                'type'    => 'select',
                'options' => [
                    'both' => __('QR Code & OTP (customer chooses)', 'cashipay'),
                    'qr'   => __('QR Code only', 'cashipay'),
                    'otp'  => __('OTP only', 'cashipay'),
                ],
                'default'     => 'both',
                'description' => __('QR lets customers scan with the CashiPay app. OTP sends a code to the wallet number entered at checkout.', 'cashipay'),
                'desc_tip'    => true,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Checkout fields
    // -------------------------------------------------------------------------

    public function payment_fields(): void {
        $mode = $this->get_option('payment_mode', 'both');

        if ($this->description) {
            echo '<p>' . esc_html($this->description) . '</p>';
        }

        if (in_array($mode, ['otp', 'both'], true)) {
            $required = ($mode === 'otp') ? ' <abbr class="required" title="required">*</abbr>' : '';
            ?>
            <fieldset class="cashipay-checkout-fields">
                <p class="form-row form-row-wide">
                    <label for="cashipay_wallet">
                        <?php esc_html_e('Wallet Number', 'cashipay'); echo wp_kses_post($required); ?>
                    </label>
                    <input type="tel" id="cashipay_wallet" name="cashipay_wallet"
                           placeholder="<?php esc_attr_e('e.g. 249123456789', 'cashipay'); ?>"
                           class="input-text"
                           autocomplete="tel"
                           <?php echo ($mode === 'otp') ? 'required' : ''; ?> />
                    <?php if ($mode === 'both'): ?>
                    <span class="description">
                        <?php esc_html_e('Leave empty to pay by QR code instead.', 'cashipay'); ?>
                    </span>
                    <?php endif; ?>
                </p>
            </fieldset>
            <?php
        }
    }

    public function validate_fields(): bool {
        $mode   = $this->get_option('payment_mode', 'both');
        $wallet = sanitize_text_field($_POST['cashipay_wallet'] ?? '');

        if ($mode === 'otp' && empty($wallet)) {
            wc_add_notice(__('Please enter your CashiPay wallet number.', 'cashipay'), 'error');
            return false;
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Payment processing
    // -------------------------------------------------------------------------

    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);

        // Guard: order must exist (defensive against HPOS edge cases).
        if (!$order) {
            wc_add_notice(__('Order not found. Please try again.', 'cashipay'), 'error');
            return ['result' => 'failure'];
        }

        $mode   = $this->get_option('payment_mode', 'both');
        $wallet = '';

        if (in_array($mode, ['otp', 'both'], true)) {
            $wallet = sanitize_text_field($_POST['cashipay_wallet'] ?? '');
        }

        $webhook_key  = wp_generate_uuid4();
        $callback_url = rest_url("cashipay/v1/webhook/{$webhook_key}");
        $return_url   = $this->get_return_url($order);

        // Normalize currency (I4: guard against lowercase/trailing spaces).
        $currency = strtoupper(trim($this->get_option('currency', 'SDG')));

        $payload = [
            // Use the public-facing order number, not the internal post ID (I3).
            'merchantOrderId' => $order->get_order_number(),
            'amount'          => [
                'value'    => (float) $order->get_total(),
                'currency' => $currency,
            ],
            'description'   => sprintf(
                /* translators: %s: order number */
                __('Order #%s', 'cashipay'),
                $order->get_order_number()
            ),
            'customerEmail' => $order->get_billing_email(),
            'callbackUrl'   => $callback_url,
            'returnUrl'     => $return_url,
        ];

        $phone = $order->get_billing_phone();
        if (!empty($phone)) {
            $payload['customerPhone'] = $phone;
        }

        if (!empty($wallet)) {
            $payload['walletAccountNumber'] = $wallet;
        }

        $response = $this->api->create_payment($payload);

        if (empty($response['success'])) {
            // C3: Log the real error; show only a generic message to the customer.
            $this->log(
                sprintf(
                    'Payment creation failed for order #%s — HTTP %s: %s',
                    $order->get_order_number(),
                    $response['http_status'] ?? 'N/A',
                    $response['message'] ?? 'no message'
                ),
                'error'
            );
            wc_add_notice(
                __('Payment could not be initiated. Please try again or contact support.', 'cashipay'),
                'error'
            );
            return ['result' => 'failure'];
        }

        $reference = $response['referenceNumber'] ?? '';
        $qr_url    = $response['qrCode']['dataUrl'] ?? ($response['qrCode'] ?? '');

        $order->update_meta_data('_cashipay_webhook_key', $webhook_key);
        $order->update_meta_data('_cashipay_reference',   $reference);
        $order->update_meta_data('_cashipay_qr_data_url', $qr_url);
        $order->update_meta_data('_cashipay_wallet',      $wallet);
        $order->update_meta_data('_cashipay_amount',      $order->get_total());
        $order->set_status('pending', __('Awaiting CashiPay payment.', 'cashipay'));
        $order->save();

        $this->log(
            sprintf(
                'Payment request created for order #%s — reference: %s.',
                $order->get_order_number(),
                $reference
            )
        );

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        ];
    }

    // -------------------------------------------------------------------------
    // Refunds (I8)
    // -------------------------------------------------------------------------

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error {
        $order     = wc_get_order($order_id);
        $reference = $order ? $order->get_meta('_cashipay_reference') : '';

        if (!$order || empty($reference)) {
            return new \WP_Error('cashipay_refund', __('No CashiPay payment reference found for this order.', 'cashipay'));
        }

        if ($order->is_paid()) {
            // CashiPay's cancel endpoint targets pending payments only.
            // Completed payments require manual refund via the CashiPay dashboard.
            return new \WP_Error(
                'cashipay_refund',
                __('Completed CashiPay payments cannot be refunded automatically. Please process the refund in your CashiPay merchant dashboard.', 'cashipay')
            );
        }

        $response = $this->api->cancel_payment($reference);

        if (!empty($response['success'])) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: CashiPay payment reference number */
                    __('CashiPay payment cancelled via API. Reference: %s', 'cashipay'),
                    $reference
                )
            );
            $this->log(sprintf('Payment cancelled for order #%s — reference: %s.', $order->get_order_number(), $reference));
            return true;
        }

        $api_error = $response['message'] ?? 'Unknown API error';
        $this->log(
            sprintf('Cancellation failed for order #%s: %s', $order->get_order_number(), $api_error),
            'error'
        );
        return new \WP_Error('cashipay_refund', __('CashiPay cancellation request failed. Please check your CashiPay merchant dashboard.', 'cashipay'));
    }

    // -------------------------------------------------------------------------
    // Receipt / payment page
    // -------------------------------------------------------------------------

    public function receipt_page(int $order_id): void {
        $order = wc_get_order($order_id);

        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'cashipay') . '</p>';
            return;
        }

        // I2: Redirect if the order is already paid.
        if ($order->is_paid()) {
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        $reference  = $order->get_meta('_cashipay_reference');
        $qr_url     = $order->get_meta('_cashipay_qr_data_url');
        $wallet     = $order->get_meta('_cashipay_wallet');
        $amount     = (float) $order->get_meta('_cashipay_amount');
        $mode       = $this->get_option('payment_mode', 'both');
        $return_url = $this->get_return_url($order);

        include CASHIPAY_PLUGIN_DIR . 'templates/receipt.php';
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public function ajax_confirm_otp(): void {
        // C1/C2: Nonce + order-key ownership check.
        check_ajax_referer('cashipay_nonce', 'nonce');

        $order_id  = absint($_POST['order_id'] ?? 0);
        $order_key = sanitize_text_field($_POST['order_key'] ?? '');
        $otp       = sanitize_text_field($_POST['otp'] ?? '');
        $order     = $order_id ? wc_get_order($order_id) : null;

        if (!$order || !hash_equals($order->get_order_key(), $order_key)) {
            wp_send_json_error(['message' => __('Invalid request.', 'cashipay')]);
            return;
        }

        if (!preg_match('/^\d{4,8}$/', $otp)) {
            wp_send_json_error(['message' => __('Invalid OTP format.', 'cashipay')]);
            return;
        }

        $reference = $order->get_meta('_cashipay_reference');
        $wallet    = $order->get_meta('_cashipay_wallet');
        $amount    = (float) $order->get_meta('_cashipay_amount');

        if (empty($reference) || empty($wallet)) {
            wp_send_json_error(['message' => __('Payment session not found. Please return to checkout.', 'cashipay')]);
            return;
        }

        $response = $this->api->confirm_otp($reference, $amount, $otp, $wallet);

        if (!empty($response['success'])) {
            // C6: Update order status when OTP confirmation succeeds.
            $confirmed_status = strtoupper($response['status'] ?? '');
            if (in_array($confirmed_status, ['COMPLETED', 'PAID', 'SUCCESS', 'APPROVED'], true)) {
                if (!$order->is_paid()) {
                    $order->payment_complete($reference);
                    $order->update_meta_data('_cashipay_webhook_processed', 1);
                    $order->add_order_note(__('CashiPay OTP payment confirmed.', 'cashipay'));
                    $order->save();
                    $this->log(sprintf('OTP confirmed — order #%s marked as paid.', $order->get_order_number()));
                }
            }

            wp_send_json_success([
                'status'     => $confirmed_status,
                'return_url' => $this->get_return_url($order),
            ]);
        } else {
            // C3: Log the real error; return a generic message to the customer.
            $this->log(
                sprintf('OTP confirm failed for order #%s: %s', $order->get_order_number(), $response['message'] ?? 'no message'),
                'warning'
            );
            wp_send_json_error([
                'message' => __('OTP confirmation failed. Please check the code and try again.', 'cashipay'),
            ]);
        }
    }

    public function ajax_check_status(): void {
        // C1: Nonce check — prevents unauthenticated order-state enumeration.
        check_ajax_referer('cashipay_nonce', 'nonce');

        $order_id  = absint($_POST['order_id'] ?? 0);
        $order_key = sanitize_text_field($_POST['order_key'] ?? '');
        $order     = $order_id ? wc_get_order($order_id) : null;

        // C2: Order-key ownership check.
        if (!$order || !hash_equals($order->get_order_key(), $order_key)) {
            wp_send_json_error(['message' => __('Invalid request.', 'cashipay')]);
            return;
        }

        if ($order->is_paid()) {
            wp_send_json_success([
                'status'     => 'COMPLETED',
                'return_url' => $this->get_return_url($order),
            ]);
            return;
        }

        $reference = $order->get_meta('_cashipay_reference');
        if (empty($reference)) {
            wp_send_json_error(['message' => __('No payment reference found.', 'cashipay')]);
            return;
        }

        $response = $this->api->get_payment($reference);

        if (empty($response['success']) && !isset($response['status'])) {
            wp_send_json_error(['message' => __('Status check failed.', 'cashipay')]);
            return;
        }

        $status = strtoupper($response['status'] ?? '');
        $data   = ['status' => $status];

        if (in_array($status, ['COMPLETED', 'PAID', 'SUCCESS', 'APPROVED'], true)) {
            $data['return_url'] = $this->get_return_url($order);
        }

        wp_send_json_success($data);
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public function enqueue_scripts(): void {
        if (!is_checkout_pay_page()) {
            return;
        }

        // I7: Only enqueue when this gateway is the active one for the order.
        global $wp;
        $order_id = absint($wp->query_vars['order-pay'] ?? 0);
        if ($order_id) {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== $this->id) {
                return;
            }
        }

        wp_enqueue_style(
            'cashipay',
            CASHIPAY_PLUGIN_URL . 'assets/css/cashipay.css',
            [],
            CASHIPAY_VERSION
        );

        wp_enqueue_script(
            'cashipay',
            CASHIPAY_PLUGIN_URL . 'assets/js/cashipay.js',
            ['jquery'],
            CASHIPAY_VERSION,
            true
        );

        wp_localize_script('cashipay', 'cashipayData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cashipay_nonce'),
            'i18n'    => [
                'confirming'    => __('Confirming OTP…', 'cashipay'),
                'redirecting'   => __('Payment confirmed! Redirecting…', 'cashipay'),
                'error'         => __('An error occurred. Please try again.', 'cashipay'),
                'confirmButton' => __('Confirm Payment', 'cashipay'),
                'timeout'       => __('Payment session expired. Please contact support if you were charged.', 'cashipay'),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function log(string $message, string $level = 'info'): void {
        wc_get_logger()->log($level, $message, ['source' => 'cashipay']);
    }
}
