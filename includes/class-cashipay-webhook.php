<?php
defined('ABSPATH') || exit;

class CashiPay_Webhook {

    public static function register_routes(): void {
        register_rest_route('cashipay/v1', '/webhook/(?P<key>[a-zA-Z0-9_-]+)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response {
        $key     = $request->get_param('key');
        $payload = $request->get_json_params() ?? [];

        if (empty($key)) {
            return new WP_REST_Response(['received' => true], 200);
        }

        $orders = wc_get_orders([
            'meta_key'   => '_cashipay_webhook_key',
            'meta_value' => $key,
            'limit'      => 1,
        ]);

        if (empty($orders)) {
            return new WP_REST_Response(['received' => true], 200);
        }

        /** @var WC_Order $order */
        $order     = $orders[0];
        $status    = strtoupper($payload['status'] ?? $payload['event'] ?? '');
        $reference = $payload['referenceNumber'] ?? $payload['reference_number'] ?? '';

        if (in_array($status, ['COMPLETED', 'PAID', 'SUCCESS', 'APPROVED'], true)) {
            if (!$order->is_paid()) {
                $order->payment_complete($reference);
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: CashiPay payment reference number */
                        __('CashiPay payment completed. Reference: %s', 'cashipay'),
                        $reference
                    )
                );
                do_action('cashipay_payment_completed', $order, $payload);
            }
        } elseif (in_array($status, ['FAILED', 'EXPIRED', 'CANCELLED'], true)) {
            $order->update_status(
                'failed',
                sprintf(
                    /* translators: %s: payment status (e.g. expired, cancelled) */
                    __('CashiPay payment %s.', 'cashipay'),
                    strtolower($status)
                )
            );
            do_action('cashipay_payment_failed', $order, $payload, $status);
        }

        do_action('cashipay_webhook_received', $order, $payload);

        return new WP_REST_Response(['received' => true], 200);
    }
}
