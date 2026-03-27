<?php
/**
 * CashiPay payment receipt page.
 *
 * Variables provided by receipt_page():
 *   WC_Order $order, int $order_id, string $reference, string $qr_url,
 *   string $wallet, float $amount, string $mode, string $return_url
 */
defined('ABSPATH') || exit;

$show_qr  = !empty($qr_url) && in_array($mode, ['qr', 'both'], true);
$show_otp = !empty($wallet) && in_array($mode, ['otp', 'both'], true);
?>
<div class="cashipay-payment-wrap"
     data-order-id="<?php echo esc_attr($order_id); ?>"
     data-order-key="<?php echo esc_attr($order->get_order_key()); ?>"
     data-return-url="<?php echo esc_url($return_url); ?>">

    <div class="cashipay-header">
        <h2 class="cashipay-title"><?php esc_html_e('Complete Your Payment', 'cashipay'); ?></h2>
        <p class="cashipay-amount"><?php echo wp_kses_post(wc_price($amount)); ?></p>
        <p class="cashipay-reference">
            <?php
            // Safe: format string is translated and trusted; $reference is escaped.
            echo wp_kses(
                sprintf(
                    /* translators: %s: CashiPay payment reference number */
                    __('Reference: %s', 'cashipay'),
                    '<code>' . esc_html($reference) . '</code>'
                ),
                ['code' => []]
            );
            ?>
        </p>
    </div>

    <?php if ($show_qr): ?>
    <div class="cashipay-section cashipay-qr-section">
        <h3><?php esc_html_e('Scan with CashiPay App', 'cashipay'); ?></h3>
        <p><?php esc_html_e('Open your CashiPay wallet app and scan the QR code below.', 'cashipay'); ?></p>
        <div class="cashipay-qr-code">
            <img src="<?php echo esc_attr($qr_url); ?>"
                 alt="<?php esc_attr_e('CashiPay QR Code', 'cashipay'); ?>"
                 width="220" height="220" />
        </div>
    </div>
    <?php endif; ?>

    <?php if ($show_qr && $show_otp): ?>
    <div class="cashipay-divider"><span><?php esc_html_e('OR', 'cashipay'); ?></span></div>
    <?php endif; ?>

    <?php if ($show_otp): ?>
    <div class="cashipay-section cashipay-otp-section">
        <h3><?php esc_html_e('Enter OTP', 'cashipay'); ?></h3>
        <p>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s: wallet account number */
                    __('Enter the OTP sent to your CashiPay wallet (%s).', 'cashipay'),
                    $wallet
                )
            );
            ?>
        </p>
        <form id="cashipay-otp-form" autocomplete="off">
            <?php
            // order_id and order_key are read by JS; the nonce comes from cashipayData.nonce.
            ?>
            <input type="hidden" name="order_id"  value="<?php echo esc_attr($order_id); ?>" />
            <input type="hidden" name="order_key" value="<?php echo esc_attr($order->get_order_key()); ?>" />
            <p class="form-row">
                <input type="text"
                       id="cashipay-otp-input"
                       name="otp"
                       inputmode="numeric"
                       pattern="[0-9]{4,8}"
                       maxlength="8"
                       placeholder="<?php esc_attr_e('Enter OTP', 'cashipay'); ?>"
                       class="input-text"
                       autocomplete="one-time-code"
                       required />
            </p>
            <button type="submit" id="cashipay-otp-submit" class="button alt wp-element-button">
                <?php esc_html_e('Confirm Payment', 'cashipay'); ?>
            </button>
        </form>
        <div id="cashipay-otp-message" class="cashipay-message" hidden></div>
    </div>
    <?php endif; ?>

    <div class="cashipay-status-bar">
        <div class="cashipay-spinner" aria-hidden="true"></div>
        <p id="cashipay-status-text"><?php esc_html_e('Waiting for payment confirmation…', 'cashipay'); ?></p>
    </div>

</div>
