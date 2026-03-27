<?php
/**
 * Plugin Name: CashiPay Payment Gateway
 * Description: CashiPay wallet payment gateway for WooCommerce — QR-code & OTP payments with per-payment webhook authentication.
 * Version:     1.0.0
 * Author:      CashiPay
 * License:     MIT
 * Text Domain: cashipay
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 */

defined('ABSPATH') || exit;

define('CASHIPAY_VERSION',    '1.0.0');
define('CASHIPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CASHIPAY_PLUGIN_URL', plugin_dir_url(__FILE__));

// Declare WooCommerce HPOS compatibility.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>'
                . esc_html__('CashiPay requires WooCommerce to be installed and active.', 'cashipay')
                . '</p></div>';
        });
        return;
    }

    require_once CASHIPAY_PLUGIN_DIR . 'includes/class-cashipay-api.php';
    require_once CASHIPAY_PLUGIN_DIR . 'includes/class-cashipay-webhook.php';
    require_once CASHIPAY_PLUGIN_DIR . 'includes/class-cashipay-gateway.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_CashiPay_Gateway';
        return $gateways;
    });

    add_action('rest_api_init', ['CashiPay_Webhook', 'register_routes']);
});
