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
    error_log('=== Jio Pay Gateway Plugin Loaded ===');
    
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

    // Instantiate the gateway class to register AJAX hooks
    $jio_pay_gateway = new WC_Jio_Pay_Gateway();
    error_log('=== Jio Pay Gateway Class Instantiated ===');

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

        // Add inline CSS for test mode notification
        wp_add_inline_style('woocommerce-general', '
            .jio-pay-test-mode-notice {
                background: #fff3cd !important;
                border: 1px solid #ffeaa7 !important;
                color: #856404 !important;
                padding: 15px !important;
                margin: 10px 0 !important;
                border-radius: 4px !important;
                font-weight: 600 !important;
                display: flex !important;
                align-items: center !important;
                gap: 10px !important;
            }
            .jio-pay-test-mode-notice::before {
                content: "⚠️" !important;
                font-size: 18px !important;
            }
            .jio-pay-test-mode-notice.hidden {
                display: none !important;
            }
        ');

        // Get plugin settings
        $options = get_option('woocommerce_jio_pay_settings', []);
        
        // Get cart/order data for payment
        $total = '0.00';
        $customer_email = '';
        $customer_name = '';
        $use_test_data = false;
        
        // Check if cart and user info are available
        if (!is_admin() && WC()->cart) {
            $cart_total = WC()->cart->get_total('');
            $current_user = wp_get_current_user();
            
            // If checkout amount is available and user is logged in with valid info
            if (!empty($cart_total) && $cart_total > 0 && $current_user->ID > 0) {
                $total = $cart_total;
                $customer_email = $current_user->user_email;
                $customer_name = $current_user->display_name;
            } else {
                // Use test data if checkout amount is not available or user is logged out
                $use_test_data = true;
                $total = '1.00';
                $customer_email = 'test@example.com';
                $customer_name = 'Test User';
            }
        } else {
            // Use test data if cart is not available
            $use_test_data = true;
            $total = '1.00';
            $customer_email = 'test@example.com';
            $customer_name = 'Test User';
        }

        wp_localize_script('jio-pay-integration', 'jioPayVars', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('jio_pay_nonce'),
            'merchant_id'   => $options['merchant_id'] ?? '',
            'environment'   => $options['environment'] ?? 'uat',
            'theme'         => $options['theme'] ?? 'light',
            'payment_method'=> $options['payment_method'] ?? 'all',
            'allowed_payment_types' => $options['allowed_payment_types'] ?? 'all',
            'timeout'       => intval($options['timeout'] ?? 30000),
            'secret_key'   => $options['secret_key'] ?? '',
            'amount'        => $total,
            'customer_email' => $customer_email,
            'customer_name' => $customer_name,
            'use_test_data' => $use_test_data,
            'merchant_name' => get_bloginfo('name'),
            'return_url'    => home_url('/checkout/order-received/')
        ]);
    }
});