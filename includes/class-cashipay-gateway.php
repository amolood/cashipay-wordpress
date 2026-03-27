<?php
defined('ABSPATH') || exit;

class WC_CashiPay_Gateway extends WC_Payment_Gateway {

    private CashiPay_API $api;

    public function __construct() {
        $this->id                 = 'cashipay';
        $this->has_fields         = true;
        $this->method_title       = __('CashiPay', 'cashipay');
        $this->method_description = __('Accept payments via CashiPay wallet — QR-code & OTP.', 'cashipay');
        $this->supports           = ['products'];

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
        $order  = wc_get_order($order_id);
        $mode   = $this->get_option('payment_mode', 'both');
        $wallet = '';

        if (in_array($mode, ['otp', 'both'], true)) {
            $wallet = sanitize_text_field($_POST['cashipay_wallet'] ?? '');
        }

        $webhook_key  = wp_generate_uuid4();
        $callback_url = rest_url("cashipay/v1/webhook/{$webhook_key}");
        $return_url   = $this->get_return_url($order);

        $payload = [
            'merchantOrderId' => (string) $order_id,
            'amount'          => [
                'value'    => (float) $order->get_total(),
                'currency' => $this->get_option('currency', 'SDG'),
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
            $message = $response['message'] ?? __('Payment could not be initiated.', 'cashipay');
            wc_add_notice($message, 'error');
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

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        ];
    }

    // -------------------------------------------------------------------------
    // Receipt / payment page
    // -------------------------------------------------------------------------

    public function receipt_page(int $order_id): void {
        $order      = wc_get_order($order_id);
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
        check_ajax_referer('cashipay_nonce', 'nonce');

        $order_id = absint($_POST['order_id'] ?? 0);
        $otp      = sanitize_text_field($_POST['otp'] ?? '');
        $order    = $order_id ? wc_get_order($order_id) : null;

        if (!$order || !preg_match('/^\d{4,8}$/', $otp)) {
            wp_send_json_error(['message' => __('Invalid request.', 'cashipay')]);
            return;
        }

        $reference = $order->get_meta('_cashipay_reference');
        $wallet    = $order->get_meta('_cashipay_wallet');
        $amount    = (float) $order->get_meta('_cashipay_amount');

        if (empty($reference) || empty($wallet)) {
            wp_send_json_error(['message' => __('Payment session expired.', 'cashipay')]);
            return;
        }

        $response = $this->api->confirm_otp($reference, $amount, $otp, $wallet);

        if (!empty($response['success'])) {
            wp_send_json_success([
                'status'     => $response['status'] ?? '',
                'return_url' => $this->get_return_url($order),
            ]);
        } else {
            wp_send_json_error([
                'message' => $response['message'] ?? __('OTP confirmation failed. Please try again.', 'cashipay'),
            ]);
        }
    }

    public function ajax_check_status(): void {
        $order_id = absint($_POST['order_id'] ?? 0);
        $order    = $order_id ? wc_get_order($order_id) : null;

        if (!$order) {
            wp_send_json_error(['message' => __('Order not found.', 'cashipay')]);
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

        if (!isset($response['success'])) {
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
            ],
        ]);
    }
}
