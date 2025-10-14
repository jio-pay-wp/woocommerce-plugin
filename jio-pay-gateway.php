<?php
/**
 * Plugin Name: Jio Pay Gateway
 * Description: Accept payments via Jio Pay SDK popup during WooCommerce checkout.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL2
 */

if (!defined('ABSPATH')) exit;

/**
 * Load the payment gateway
 */
add_action('plugins_loaded', function() {
    // Check if WooCommerce is active
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>Jio Pay Gateway requires WooCommerce to be active.</p></div>';
        });
        return;
    }

    // Include the gateway class
    if (!class_exists('WC_Jio_Pay_Gateway')) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-jio-pay-gateway.php';
    }

    // Register gateway
    add_filter('woocommerce_payment_gateways', function($gateways) {
        $gateways[] = 'WC_Jio_Pay_Gateway';
        return $gateways;
    });
});

/**
 * Add support for WooCommerce Blocks
 */
add_action('woocommerce_blocks_loaded', function() {
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-jio-pay-blocks.php';
        
        add_action('woocommerce_blocks_payment_method_type_registration', function($payment_method_registry) {
            $payment_method_registry->register(new WC_Jio_Pay_Blocks_Support());
        });
    }
});

/**
 * Enqueue SDK and Integration JS on Checkout page
 */
add_action('wp_enqueue_scripts', function() {
    if (is_checkout()) {
        wp_enqueue_script(
            'jio-pay-sdk',
            plugin_dir_url(__FILE__) . 'assets/jio-pay-sdk.js',
            [],
            '1.0.0',
            true
        );

        wp_enqueue_script(
            'jio-pay-integration',
            plugin_dir_url(__FILE__) . 'assets/jio-pay-integration.js',
            ['jquery', 'jio-pay-sdk'],
            '1.0.0',
            true
        );

        // Get plugin settings
        $options = get_option('woocommerce_jio_pay_settings', []);
        
        // Get cart/order data for payment
        $total = '1.00';
        $customer_email = 'test@example.com';
        $customer_name = 'Test User';
        
        if (!is_admin() && WC()->cart) {
            $total = WC()->cart->get_total('');
            $current_user = wp_get_current_user();
            $customer_email = $current_user->user_email ?: 'test@example.com';
            $customer_name = $current_user->display_name ?: 'Test User';
        }

        wp_localize_script('jio-pay-integration', 'jioPayVars', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('jio_pay_nonce'),
            'merchant_id'   => $options['merchant_id'] ?? '',
            'environment'   => $options['environment'] ?? 'uat',
            'amount'        => $total,
            'customer_email' => $customer_email,
            'customer_name' => $customer_name,
            'merchant_name' => get_bloginfo('name'),
            'return_url'    => home_url('/checkout/order-received/')
        ]);
    }
});