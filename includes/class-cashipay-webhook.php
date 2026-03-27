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
        $key     = sanitize_text_field($request->get_param('key'));
        $payload = $request->get_json_params() ?? [];

        $logger = wc_get_logger();

        if (empty($key)) {
            $logger->warning('CashiPay webhook received with empty key.', ['source' => 'cashipay']);
            return new WP_REST_Response(['received' => true], 200);
        }

        $orders = wc_get_orders([
            'meta_key'   => '_cashipay_webhook_key',
            'meta_value' => $key,
            'limit'      => 1,
        ]);

        if (empty($orders)) {
            $logger->warning(
                sprintf('CashiPay webhook: no order found for key %s.', $key),
                ['source' => 'cashipay']
            );
            // Always 200 — we don't want the provider to keep retrying for unknown keys.
            return new WP_REST_Response(['received' => true], 200);
        }

        /** @var WC_Order $order */
        $order = $orders[0];

        // ── Replay protection ──────────────────────────────────────────────────
        if ($order->get_meta('_cashipay_webhook_processed')) {
            $logger->debug(
                sprintf('CashiPay webhook already processed for order #%s. Ignoring.', $order->get_id()),
                ['source' => 'cashipay']
            );
            return new WP_REST_Response(['received' => true], 200);
        }

        $status    = strtoupper($payload['status'] ?? $payload['event'] ?? '');
        $reference = sanitize_text_field($payload['referenceNumber'] ?? $payload['reference_number'] ?? '');

        $logger->info(
            sprintf(
                'CashiPay webhook received for order #%s — status: %s, reference: %s.',
                $order->get_id(),
                $status,
                $reference ?: '(none)'
            ),
            ['source' => 'cashipay']
        );

        if (in_array($status, ['COMPLETED', 'PAID', 'SUCCESS', 'APPROVED'], true)) {

            // ── Idempotency / race-condition lock ──────────────────────────────
            $lock = 'cashipay_completing_' . md5($key);
            if (get_transient($lock)) {
                $logger->debug(
                    sprintf('CashiPay: concurrent completion lock active for order #%s.', $order->get_id()),
                    ['source' => 'cashipay']
                );
                return new WP_REST_Response(['received' => true], 200);
            }
            set_transient($lock, 1, 60);

            try {
                if (!$order->is_paid()) {
                    $order->payment_complete($reference);
                    $order->add_order_note(
                        sprintf(
                            /* translators: %s: CashiPay payment reference number */
                            __('CashiPay payment completed. Reference: %s', 'cashipay'),
                            $reference
                        )
                    );
                    // Mark as processed so replays are ignored.
                    $order->update_meta_data('_cashipay_webhook_processed', 1);
                    $order->save();

                    do_action('cashipay_payment_completed', $order, $payload);

                    $logger->info(
                        sprintf('CashiPay order #%s marked as paid.', $order->get_id()),
                        ['source' => 'cashipay']
                    );
                } else {
                    $logger->debug(
                        sprintf('CashiPay webhook: order #%s already paid. No state change.', $order->get_id()),
                        ['source' => 'cashipay']
                    );
                }
            } finally {
                delete_transient($lock);
            }

        } elseif (in_array($status, ['FAILED', 'EXPIRED', 'CANCELLED'], true)) {

            // Map CANCELLED to WC 'cancelled', FAILED/EXPIRED to 'failed'.
            $wc_status = ($status === 'CANCELLED') ? 'cancelled' : 'failed';

            $order->update_status(
                $wc_status,
                sprintf(
                    /* translators: %s: payment status e.g. expired, cancelled, failed */
                    __('CashiPay payment %s.', 'cashipay'),
                    strtolower($status)
                )
            );
            // Mark processed so the same webhook can't flip status again.
            $order->update_meta_data('_cashipay_webhook_processed', 1);
            $order->save();

            do_action('cashipay_payment_failed', $order, $payload, $status);

            $logger->info(
                sprintf('CashiPay order #%s set to %s.', $order->get_id(), $wc_status),
                ['source' => 'cashipay']
            );

        } else {
            $logger->warning(
                sprintf('CashiPay webhook: unrecognised status "%s" for order #%s.', $status, $order->get_id()),
                ['source' => 'cashipay']
            );
        }

        do_action('cashipay_webhook_received', $order, $payload);

        return new WP_REST_Response(['received' => true], 200);
    }
}
