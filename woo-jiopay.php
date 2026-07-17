<?php
/**
 * Plugin Name: Jio Payments Solutions Ltd.
 * Description: The Jio Payment Solutions Ltd. Checkout plugin enables online payments on your WooCommerce store with seamless support for Cards, NetBanking, UPI QR, UPI Intent, and UPI VPA.
 * Version: 1.2.6
 * Author: Jio Pay
 * Author URI: https://github.com/jio-pay-wp
 * Plugin URI: https://github.com/jio-pay-wp/woocommerce-plugin
 * Text Domain: woo-jiopay
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 3.0
 * WC tested up to: 8.3
 * License: GPL2 - GNU General Public License v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/jio-pay-wp/woocommerce-plugin
 * Network: false
*/


if (!defined('ABSPATH'))
    exit;


// Plugin constants
define('JIO_PAY_VERSION', '1.2.6');
define('JIO_PAY_PLUGIN_FILE', __FILE__);
define('JIO_PAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JIO_PAY_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check if WooCommerce HPOS is enabled
 */
function jio_pay_is_hpos_enabled()
{
    if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
    return false;
}

/**
 * Get order using HPOS-compatible method
*/
function jio_pay_get_order($order_id)
{
    if (function_exists('wc_get_order')) {
        return wc_get_order($order_id);
    }
    return false;
}

/**
 * Declare compatibility with WooCommerce features
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Declare HPOS compatibility
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);

        // Declare Cart & Checkout Blocks compatibility
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Check WooCommerce compatibility on activation
 */
register_activation_hook(__FILE__, function () {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('WooCommerce Jio Pay Gateway requires WooCommerce to be installed and active.', 'woo-jiopay'));
    }

    // Check minimum WooCommerce version
    if (version_compare(WC_VERSION, '3.0', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('WooCommerce Jio Pay Gateway requires WooCommerce version 3.0 or higher.', 'woo-jiopay'));
    }
});

/**
 * Initialize the update checker
*/
add_action('init', function () {
    // Include update checker
    if (!class_exists('Jio_Pay_Update_Checker')) {
        require_once JIO_PAY_PLUGIN_DIR . 'includes/class-jio-pay-update-checker.php';
    }

    // Initialize update checker
    $update_checker = new Jio_Pay_Update_Checker(
        JIO_PAY_PLUGIN_FILE,
        JIO_PAY_VERSION,
        'https://api.github.com/repos/jio-pay-wp/woocommerce-plugin/releases/latest'
    );

    // Include admin class
    if (is_admin() && !class_exists('Jio_Pay_Admin')) {
        require_once JIO_PAY_PLUGIN_DIR . 'includes/class-jio-pay-admin.php';
        $admin = new Jio_Pay_Admin();
        $admin->set_update_checker($update_checker);
    }
});

/**
 * Add admin styles for Jio Pay settings page
 */
add_action('admin_head', function() {
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, 'wc-settings') !== false && isset($_GET['section']) && $_GET['section'] === 'jio_pay') {
        ?>
        <style>
            .woocommerce table.form-table th[scope="row"] {
                padding: 15px 0;
            }
            .woocommerce table.form-table tr[valign="top"] > th[colspan="2"] {
                background: #f0f0f1;
                padding: 12px 15px;
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
            }
            .woocommerce table.form-table tr[valign="top"] > th[colspan="2"] + td {
                padding: 0;
            }
            input[type="password"] {
                font-family: 'Courier New', monospace;
            }
        </style>
        <?php
    }
});

/**
 * Load the payment gateway
 */
add_action('plugins_loaded', function () {
    error_log('=== WooCommerce Jio Pay Gateway Plugin Loaded ===');

    // Check if WooCommerce is active
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>WooCommerce Jio Pay Gateway requires WooCommerce to be active.</p></div>';
        });
        return;
    }

    // Include the gateway class
    if (!class_exists('WC_Jio_Pay_Gateway')) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-jio-pay-gateway.php';
    }

    // Instantiate the gateway class to register AJAX hooks
    $jio_pay_gateway = new WC_Jio_Pay_Gateway();
    error_log('=== WooCommerce Jio Pay Gateway Class Instantiated ===');

    // Register gateway
    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Jio_Pay_Gateway';
        return $gateways;
    });
});

/**
 * Add support for WooCommerce Blocks
 */
add_action('woocommerce_blocks_loaded', function () {
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-jio-pay-blocks.php';

        add_action('woocommerce_blocks_payment_method_type_registration', function ($payment_method_registry) {
            $payment_method_registry->register(new WC_Jio_Pay_Blocks_Support());
        });
    }
});

/**
 * Enqueue SDK and Integration JS on Checkout page
 */
add_action('wp_enqueue_scripts', function () {
    // Enqueue on all payment-related pages: checkout, order-pay, pay-for-order, and payment methods
    if (is_checkout() || is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('pay') || is_wc_endpoint_url('add-payment-method')) {
        wp_enqueue_script(
            'jio-pay-sdk',
            plugin_dir_url(__FILE__) . 'assets/jio-pay-sdk.js',
            [],
            '1.2.6',
            true
        );

        // Dependencies for integration script
        // Include 'wc-checkout' for classic checkout compatibility
        $integration_deps = ['jquery', 'jio-pay-sdk'];
        if (wp_script_is('wc-checkout', 'registered') || !wp_doing_ajax()) {
            $integration_deps[] = 'wc-checkout';
        }
        
        wp_enqueue_script(
            'jio-pay-integration',
            plugin_dir_url(__FILE__) . 'assets/jio-pay-integration.js',
            $integration_deps,
            '1.2.6',
            true
        );

        // Add inline CSS for test mode notification and SDK overlay fixes
        wp_add_inline_style('woocommerce-general', '
            /* Jio Pay payment icon size control */
            .payment_method_jio_pay img,
            .wc_payment_method.payment_method_jio_pay img,
            .payment_method_jio_pay label img,
            li.payment_method_jio_pay img,
            #payment .payment_methods .payment_method_jio_pay img,
            .woocommerce-checkout #payment ul.payment_methods li.payment_method_jio_pay img {
                max-height: 25px !important;
                max-width: 80px !important;
                height: auto !important;
                width: auto !important;
                vertical-align: middle !important;
                margin-left: 8px !important;
                display: inline-block !important;
            }
            
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
            
            /* Fix JioPay SDK overlay - ensure it does not show until payment is initiated */
            .popup-wrapper,
            .exit-popup-wrapper,
            .main-container:not(.jiopay-active) {
                display: none !important;
            }
            
            /* Ensure SDK elements do not affect page layout when not active */
            body > .popup-wrapper:empty,
            body > .exit-popup-wrapper:empty,
            body > div[style*="position: fixed"][style*="z-index"]:empty {
                display: none !important;
            }
            
            /* ========================================
               DEFENSIVE STYLES TO PREVENT SDK FROM
               AFFECTING CHECKOUT PAGE DESIGN
               ======================================== */
            
            /* Reset any global underlines that SDK might apply */
            .woocommerce-checkout a,
            .woocommerce-checkout label,
            .woocommerce-checkout p,
            .woocommerce-checkout span,
            .woocommerce-checkout div,
            .wc-block-checkout a,
            .wc-block-checkout label,
            .wc-block-checkout p,
            .wc-block-checkout span,
            .wc-block-checkout div,
            form.checkout a,
            form.checkout label,
            form.checkout p,
            form.checkout span {
                text-decoration: inherit;
            }
            
            /* Ensure checkout inputs maintain proper styling */
            .woocommerce-checkout input[type="text"],
            .woocommerce-checkout input[type="email"],
            .woocommerce-checkout input[type="tel"],
            .woocommerce-checkout input[type="number"],
            .woocommerce-checkout input[type="password"],
            .woocommerce-checkout select,
            .woocommerce-checkout textarea,
            .wc-block-checkout input[type="text"],
            .wc-block-checkout input[type="email"],
            .wc-block-checkout input[type="tel"],
            .wc-block-checkout input[type="number"],
            .wc-block-checkout select,
            .wc-block-checkout textarea,
            form.checkout input[type="text"],
            form.checkout input[type="email"],
            form.checkout input[type="tel"],
            form.checkout input[type="number"],
            form.checkout select,
            form.checkout textarea {
                text-decoration: none !important;
                font-style: normal !important;
            }
            
            /* Ensure labels are not underlined */
            .woocommerce-checkout label,
            .wc-block-checkout label,
            form.checkout label,
            #payment label {
                text-decoration: none !important;
            }
            
            /* Force light mode for checkout page - prevent SDK dark mode from affecting page */
            @media (prefers-color-scheme: dark) {
                .woocommerce-checkout,
                .woocommerce-cart,
                .wc-block-checkout,
                .wc-block-cart,
                #payment,
                .woocommerce-checkout-payment,
                form.checkout,
                form#order_review {
                    color-scheme: light !important;
                    background-color: inherit !important;
                    color: inherit !important;
                }
                
                /* Ensure Jio Pay payment option area stays light */
                .payment_method_jio_pay,
                .wc-block-components-radio-control-accordion-option[value="jio_pay"],
                #jio-pay-payment-data {
                    background-color: #f9f9f9 !important;
                    color: #333 !important;
                }
            }
        ');

        // Get plugin settings
        $options = get_option('woocommerce_jio_pay_settings', []);
        
        // Determine which environment credentials to use
        $environment = $options['environment'] ?? 'uat';
        if ($environment === 'prod') {
            $merchant_id = $options['live_merchant_id'] ?? '';
            $secret_key = $options['live_secret_key'] ?? '';
            $agregator_id = $options['live_agregator_id'] ?? '';
            $mcc_code = $options['live_mcc_code'] ?? '';
        } else {
            $merchant_id = $options['uat_merchant_id'] ?? '';
            $secret_key = $options['uat_secret_key'] ?? '';
            $agregator_id = $options['uat_agregator_id'] ?? '';
            $mcc_code = $options['uat_mcc_code'] ?? '';
        }

        // Get cart/order data for payment
        $total = '0.00';
        $customer_email = '';
        $customer_name = '';
        $use_test_data = false;

        // Check if cart and user info are available
        if (!is_admin() && WC()->cart) {
            $cart_total = WC()->cart->get_total('');
            $current_user = wp_get_current_user();

            // Check if we have valid cart amount
            if (!empty($cart_total) && $cart_total > 0) {
                $total = $cart_total;
                
                // For logged-in users, use their info
                if ($current_user->ID > 0) {
                    $customer_email = $current_user->user_email;
                    $customer_name = $current_user->display_name;
                } else {
                    // For guest users, use placeholder (will be collected at checkout)
                    $customer_email = 'guest@checkout.com';
                    $customer_name = 'Guest User';
                }
            } else {
                // Use test data if checkout amount is not available
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
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jio_pay_nonce'),
            'merchant_id' => $merchant_id,
            'environment' => $environment,
            'agregator_id' => $agregator_id,
            'mcc_code' => $mcc_code,
            'theme' => $options['theme'] ?? 'light',
            'payment_method' => $options['payment_method'] ?? 'all',
            'allowed_payment_types' => $options['allowed_payment_types'] ?? 'all',
            'timeout' => intval($options['timeout'] ?? 30000),
            'secret_key' => $secret_key,
            'amount' => $total,
            'customer_email' => $customer_email,
            'customer_name' => $customer_name,
            'use_test_data' => $use_test_data,
            'merchant_name' => get_bloginfo('name'),
            'merchantLogo' => (function() use ($options) {
                // 1. Plugin's own Merchant Logo setting (theme-independent).
                if (!empty($options['merchant_logo'])) {
                    return $options['merchant_logo'];
                }
                // 2. Theme's Site Identity logo (custom_logo).
                $custom_logo_id = get_theme_mod('custom_logo');
                if ($custom_logo_id) {
                    $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
                    if ($logo && isset($logo[0])) {
                        return $logo[0];
                    }
                }
                // 3. Site icon / favicon as a last resort.
                $icon = get_site_icon_url();
                return $icon ? $icon : '';
            })(),
            'return_url' => add_query_arg('action', 'jio_pay_return_handler', admin_url('admin-ajax.php'))
        ]);
    }
});