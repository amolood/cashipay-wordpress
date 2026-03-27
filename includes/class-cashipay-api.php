<?php
defined('ABSPATH') || exit;

class CashiPay_API {

    private string $base_url;
    private string $api_key;
    private int    $timeout;

    public function __construct(string $environment, string $staging_key, string $production_key, int $timeout = 30) {
        if ($environment === 'production') {
            $this->base_url = 'https://cashi-services.alsoug.com/cashipay';
            $this->api_key  = $production_key;
        } else {
            $this->base_url = 'https://stg-cashi-services.alsoug.com/cashipay';
            $this->api_key  = $staging_key;
        }
        $this->timeout = $timeout;
    }

    public function create_payment(array $data): array {
        return $this->request('POST', '/payment-requests', $data);
    }

    public function get_payment(string $reference): array {
        return $this->request('GET', "/payment-requests/{$reference}");
    }

    public function cancel_payment(string $reference): array {
        return $this->request('POST', "/payment-requests/{$reference}/cancel");
    }

    public function confirm_otp(string $reference, float $amount, string $otp, string $wallet): array {
        return $this->request('POST', '/payment-requests/payment-confirm', [
            'referenceNumber'     => $reference,
            'amount'              => $amount,
            'otp'                 => $otp,
            'walletAccountNumber' => $wallet,
        ]);
    }

    /**
     * Returns true if the stored key is non-empty (used by the admin settings test).
     */
    public function has_api_key(): bool {
        return !empty($this->api_key);
    }

    private function request(string $method, string $path, array $body = []): array {
        // Never call home without a key — fail fast with a clear message.
        if (empty($this->api_key)) {
            return [
                'success' => false,
                'message' => 'CashiPay API key is not configured.',
            ];
        }

        $args = [
            'method'  => $method,
            'timeout' => $this->timeout,
            'headers' => [
                // Key is sent here but never logged; callers must not log the
                // full $args array.
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
        ];

        if ($method === 'POST' && !empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($this->base_url . $path, $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code   = (int) wp_remote_retrieve_response_code($response);
        $parsed = json_decode(wp_remote_retrieve_body($response), true) ?? [];

        return array_merge(
            ['success' => $code >= 200 && $code < 300, 'http_status' => $code],
            $parsed
        );
    }
}
