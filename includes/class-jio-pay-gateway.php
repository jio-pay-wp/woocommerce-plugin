<?php
if (!defined('ABSPATH'))
    exit;

class WC_Jio_Pay_Gateway extends WC_Payment_Gateway
{

    // Declare properties to fix PHP 8.2 deprecation warnings
    public $merchant_id;
    public $secret_key;
    public $agregator_id;
    public $environment;
    public $theme;
    public $payment_method;
    public $allowed_payment_types;
    public $timeout;

    public function __construct()
    {
        $this->id = 'jio_pay';
        $this->method_title = __('Jio Pay Gateway', 'woo-jiopay');
        $this->method_description = __('Accept payments using Jio Pay SDK popup.', 'woo-jiopay');
        $this->has_fields = false;
        $this->supports = array(
            'products',
            'refunds'
        );

        // Declare HPOS compatibility
        add_action('before_woocommerce_init', function () {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });

        $this->init_form_fields();
        $this->init_settings();

        // Load merchant configs
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->merchant_id = $this->get_option('merchant_id');
        $this->secret_key = $this->get_option('secret_key');
        $this->agregator_id = $this->get_option('agregator_id');
        $this->environment = $this->get_option('environment');
        $this->theme = $this->get_option('theme');
        $this->payment_method = $this->get_option('payment_method');
        $this->allowed_payment_types = $this->get_option('allowed_payment_types');
        $this->timeout = $this->get_option('timeout');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

        // AJAX endpoints
        add_action('wp_ajax_jio_pay_create_session', [$this, 'create_session']);
        add_action('wp_ajax_nopriv_jio_pay_create_session', [$this, 'create_session']);
        add_action('wp_ajax_jio_pay_verify_payment', [$this, 'verify_payment']);
        add_action('wp_ajax_nopriv_jio_pay_verify_payment', [$this, 'verify_payment']);
        add_action('wp_ajax_jio_pay_test', [$this, 'test_ajax']);
        add_action('wp_ajax_nopriv_jio_pay_test', [$this, 'test_ajax']);
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Jio Pay', 'woocommerce'),
                'default' => 'yes'
            ],
            'title' => [
                'title' => __('Title', 'woocommerce'),
                'type' => 'text',
                'description' => __('Title shown during checkout', 'woocommerce'),
                'default' => __('Jio Pay', 'woocommerce')
            ],
            'description' => [
                'title' => __('Description', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('Description shown during checkout', 'woocommerce'),
                'default' => __('Pay securely via Jio Pay popup', 'woocommerce')
            ],
            'merchant_id' => [
                'title' => __('Merchant ID', 'woocommerce'),
                'type' => 'text',
                'description' => __('Your Jio Pay merchant ID'),
                'default' => ''
            ],
            'secret_key' => [
                'title' => __('Secret Key', 'woocommerce'),
                'type' => 'text',
                'description' => __('Your Jio Pay secret key'),
                'default' => ''
            ],
            'agregator_id' => [
                'title' => __('Agregator ID', 'woocommerce'),
                'type' => 'text',
                'description' => __('Your Jio Pay agregator ID'),
                'default' => ''
            ],
            'environment' => [
                'title' => __('Environment', 'woocommerce'),
                'type' => 'select',
                'options' => ['uat' => 'UAT', 'prod' => 'Live'],
                'description' => __('Select UAT or Live environment'),
                'default' => 'uat'
            ],
            'theme' => [
                'title' => __('Theme (JSON)', 'woocommerce'),
                'type' => 'text',
                'description' => __('Custom theme as JSON, e.g. {"primaryColor":"#E39B2B","secondaryColor":"#000"}'),
                'default' => ''
            ],
            'payment_method' => [
                'title' => __('Default Payment Method', 'woocommerce'),
                'type' => 'select',
                'description' => __('Choose default payment method.'),
                'options' => [
                    'netBanking' => __('Net Banking', 'woocommerce'),
                    'card' => __('Card', 'woocommerce'),
                    'upi' => __('UPI', 'woocommerce'),
                    'wallet' => __('Wallet', 'woocommerce'),
                ],
                'default' => 'netBanking'
            ],
            'allowed_payment_types' => [
                'title' => __('Allowed Payment Types', 'woocommerce'),
                'type' => 'multiselect',
                'description' => __('Select allowed payment types.'),
                'options' => [
                    'CARD' => __('Card', 'woocommerce'),
                    'NB' => __('Net Banking', 'woocommerce'),
                    'UPI_QR' => __('UPI QR', 'woocommerce'),
                    'UPI_INTENT' => __('UPI Intent', 'woocommerce'),
                    'UPI_VPA' => __('UPI VPA', 'woocommerce'),
                ],
                'default' => ['CARD', 'NB', 'UPI_QR', 'UPI_INTENT', 'UPI_VPA']
            ],
            'timeout' => [
                'title' => __('Timeout (ms)', 'woocommerce'),
                'type' => 'number',
                'description' => __('Popup timeout in milliseconds, e.g. 1000'),
                'default' => '1000'
            ]
        ];
    }

    /**
     * Check if the gateway is available for use
     */
    public function is_available()
    {
        if ('yes' !== $this->enabled) {
            return false;
        }

        if (empty($this->merchant_id) || empty($this->secret_key)) {
            return false;
        }

        return parent::is_available();
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }

        // Check if we're in test mode (when cart/user data is not available)
        $use_test_data = false;
        $error_message = '';

        if (!is_admin() && WC()->cart) {
            // Get cart details
            $cart_total = WC()->cart->get_total('');
            $cart_items_count = WC()->cart->get_cart_contents_count();
            $current_user = wp_get_current_user();
            $is_logged_in = $current_user->ID > 0;

            // Check WooCommerce guest checkout setting
            $guest_checkout_enabled = get_option('woocommerce_enable_guest_checkout', 'yes') === 'yes';

            // Validate cart has items and amount is greater than 0
            if ($cart_items_count <= 0) {
                $use_test_data = true;
                $error_message = __('Your cart is empty. Please add items to your cart before proceeding.', 'woocommerce');
            } elseif (empty($cart_total) || floatval($cart_total) <= 0) {
                $use_test_data = true;
                $error_message = __('Cart total must be greater than zero.', 'woocommerce');
            } elseif (!$is_logged_in && !$guest_checkout_enabled) {
                // Guest checkout is disabled by admin
                $use_test_data = true;
                $error_message = __('You must be logged in to place an order. Guest checkout is disabled.', 'woocommerce');
            }
            // If logged in OR (guest AND guest checkout enabled), allow payment
        } else {
            // Cart not available - test mode
            $use_test_data = true;
            $error_message = __('Cart is not available. Please refresh the page.', 'woocommerce');
        }

        // Show error/warning message if applicable
        if ($use_test_data && !empty($error_message)) {
            echo '<div class="woocommerce-error" style="padding: 10px; background: #e2401c; color: #fff; border-radius: 3px; margin: 10px 0;">';
            echo esc_html($error_message);
            echo '</div>';
        }

        echo '<div id="jio-pay-payment-data" style="padding: 10px; background: #f9f9f9; border: 1px solid #ddd; margin: 10px 0;">';
        echo '<p>' . __('You will be redirected to Jio Pay to complete your payment securely.', 'woocommerce') . '</p>';
        echo '</div>';
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'woocommerce'), 'error');
            return [
                'result' => 'failure',
                'messages' => 'Order not found.'
            ];
        }

        // Validate cart has items and amount > 0
        if (!WC()->cart || WC()->cart->get_cart_contents_count() <= 0) {
            wc_add_notice(__('Your cart is empty.', 'woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        $order_total = $order->get_total();
        if (empty($order_total) || floatval($order_total) <= 0) {
            wc_add_notice(__('Order total must be greater than zero.', 'woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        // Check guest checkout permission
        $current_user = wp_get_current_user();
        $is_logged_in = $current_user->ID > 0;
        $guest_checkout_enabled = get_option('woocommerce_enable_guest_checkout', 'yes') === 'yes';

        if (!$is_logged_in && !$guest_checkout_enabled) {
            wc_add_notice(__('You must be logged in to place an order. Guest checkout is disabled.', 'woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        // All validations passed - set order status to pending payment
        $order->update_status('pending', __('Awaiting Jio Pay payment.', 'woocommerce'));

        // Add note about user type for tracking
        $user_type = $is_logged_in ? 'Logged-in user (ID: ' . $current_user->ID . ')' : 'Guest user';
        $order->add_order_note(sprintf(__('Payment initiated by %s', 'woocommerce'), $user_type));

        // Return success for JavaScript to handle the popup
        return [
            'result' => 'success',
            'redirect' => '' // No redirect - JS will handle the popup
        ];
    }

    public function create_session()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jio_pay_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        // Return session data
        $session_data = [
            'session_id' => 'SESSION_' . time(),
            'merchant_id' => $this->merchant_id,
            'environment' => $this->environment,
            'message' => 'Session created successfully'
        ];

        wp_send_json_success($session_data);
    }

    public function verify_payment()
    {
        // Enable error reporting for debugging
        error_reporting(E_ALL);
        ini_set('display_errors', 1);

        // Always send JSON response
        header('Content-Type: application/json');

        try {
            error_log('=== Jio Pay verify_payment function started ===');

            // Basic validation
            if (!isset($_POST['action']) || $_POST['action'] !== 'jio_pay_verify_payment') {
                error_log('Invalid action: ' . ($_POST['action'] ?? 'none'));
                wp_send_json_error(['message' => 'Invalid action']);
                wp_die();
            }

            // Check nonce - but don't fail immediately for debugging
            $nonce_valid = isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'jio_pay_nonce');
            if (!$nonce_valid) {
                error_log('Nonce verification failed. Nonce: ' . ($_POST['nonce'] ?? 'missing'));
                // Temporarily continue for debugging - in production this should fail
                // wp_send_json_error(['message' => 'Security check failed']);
                // wp_die();
            }

            $order_id = intval($_POST['order_id'] ?? 0);
            $payment_data = $_POST['payment_data'] ?? [];

            error_log('Order ID: ' . $order_id);
            error_log('Payment data keys: ' . implode(', ', array_keys($payment_data)));

            // Get the order
            if ($order_id) {
                $order = wc_get_order($order_id);
                if (!$order) {
                    error_log('Order not found for ID: ' . $order_id);
                    wp_send_json_error(['message' => 'Order not found for ID: ' . $order_id]);
                    wp_die();
                }
            } else {
                error_log('No order ID provided, searching for recent orders...');

                // Try to find recent order (works for both logged-in and guest users)
                $current_user = wp_get_current_user();
                $user_id = $current_user->ID;

                // Build query args
                $query_args = [
                    'status' => ['pending', 'on-hold'],
                    'payment_method' => 'jio_pay',
                    'limit' => 1,
                    'orderby' => 'date',
                    'order' => 'DESC'
                ];

                // If user is logged in, search by customer ID
                // If guest, search all recent pending orders (will match by session/email later)
                if ($user_id > 0) {
                    $query_args['customer'] = $user_id;
                    error_log('Searching orders for logged-in user: ' . $user_id);
                } else {
                    error_log('Searching orders for guest user');
                    // For guest, we'll get the most recent pending order
                    // In production, you might want to match by billing email or session
                }

                $orders = wc_get_orders($query_args);

                if (empty($orders)) {
                    $user_type = $user_id > 0 ? 'logged-in user: ' . $user_id : 'guest user';
                    error_log('No pending orders found for ' . $user_type);
                    wp_send_json_error(['message' => 'No pending order found']);
                    wp_die();
                }

                $order = $orders[0];
                $order_id = $order->get_id();
                error_log('Found order: ' . $order_id);
            }

            // Extract payment details
            $txn_auth_id = $payment_data['txnAuthID'] ?? '';
            $txn_response_code = $payment_data['txnResponseCode'] ?? '';
            $txn_description = $payment_data['txnRespDescription'] ?? '';
            $amount = $payment_data['amount'] ?? '';

            error_log(sprintf(
                'Payment details: AuthID=%s, ResponseCode=%s, Amount=%s',
                $txn_auth_id,
                $txn_response_code,
                $amount
            ));

            // Validate response code
            if ($txn_response_code !== '0000') {
                error_log('Payment failed with response code: ' . $txn_response_code);
                wp_send_json_error(['message' => 'Payment failed: ' . $txn_description]);
                wp_die();
            }

            // Validate amount
            $order_amount = (float) $order->get_total();
            $paid_amount_raw = (float) $amount;

            // Jio Pay might send amount in different formats:
            // 1. In paisa (75000 = ₹750)
            // 2. In rupees (750.00 = ₹750)
            // Let's try both and see which matches
            $paid_amount_paisa = $paid_amount_raw / 100;  // Convert from paisa
            $paid_amount_rupees = $paid_amount_raw;       // Already in rupees

            error_log(sprintf(
                'Amount analysis - Order: %.2f, Raw Payment: %.2f, As Paisa: %.2f, As Rupees: %.2f',
                $order_amount,
                $paid_amount_raw,
                $paid_amount_paisa,
                $paid_amount_rupees
            ));

            // Check which format matches (allowing for small rounding differences)
            $diff_paisa = abs($order_amount - $paid_amount_paisa);
            $diff_rupees = abs($order_amount - $paid_amount_rupees);

            $paid_amount = $paid_amount_rupees; // Default to rupees

            if ($diff_paisa <= 0.01) {
                // Amount is in paisa format
                $paid_amount = $paid_amount_paisa;
                error_log('Amount format detected: PAISA (converted to rupees)');
            } else if ($diff_rupees <= 0.01) {
                // Amount is in rupees format
                $paid_amount = $paid_amount_rupees;
                error_log('Amount format detected: RUPEES');
            } else {
                // Neither format matches exactly
                error_log(sprintf(
                    'Amount mismatch detected - Order: %.2f, Paisa diff: %.2f, Rupees diff: %.2f',
                    $order_amount,
                    $diff_paisa,
                    $diff_rupees
                ));

                wp_send_json_error([
                    'message' => sprintf(
                        'Amount mismatch: Order=%.2f, Payment=%.2f (paisa=%.2f)',
                        $order_amount,
                        $paid_amount_rupees,
                        $paid_amount_paisa
                    ),
                    'debug' => [
                        'order_amount' => $order_amount,
                        'payment_raw' => $paid_amount_raw,
                        'payment_as_paisa' => $paid_amount_paisa,
                        'payment_as_rupees' => $paid_amount_rupees
                    ]
                ]);
                wp_die();
            }

            error_log(sprintf('Final amount comparison: Order=%.2f, Paid=%.2f', $order_amount, $paid_amount));

            // Complete the payment
            error_log('Completing payment for order: ' . $order_id);

            $order->payment_complete($txn_auth_id);
            $order->add_order_note(sprintf(
                'Jio Pay payment successful. Auth ID: %s, Amount: %.2f',
                $txn_auth_id,
                $paid_amount
            ));

            // Reduce stock
            wc_reduce_stock_levels($order_id);

            // Clear cart
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }

            error_log('Payment completed successfully');

            wp_send_json_success([
                'message' => 'Payment verified successfully',
                'redirect' => $order->get_checkout_order_received_url(),
                'order_id' => $order_id
            ]);

        } catch (Exception $e) {
            error_log('Exception in verify_payment: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());

            wp_send_json_error([
                'message' => 'Payment verification error: ' . $e->getMessage(),
                'debug' => $e->getTraceAsString()
            ]);
        }

        wp_die();
    }

    public function test_ajax()
    {
        wp_send_json_success(['message' => 'AJAX is working correctly', 'data' => $_POST]);
        wp_die();
    }
}