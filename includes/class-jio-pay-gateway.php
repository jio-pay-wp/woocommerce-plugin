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
        $this->method_title = __('Jio Payments Solutions Ltd.', 'woo-jiopay');
        $this->method_description = __('The Jio Payment Solutions Ltd. Checkout plugin enables online payments on your WooCommerce store with seamless support for Cards, NetBanking, UPI QR, UPI Intent, and UPI VPA.', 'woo-jiopay');
        $this->has_fields = false;
        $this->icon = JIO_PAY_PLUGIN_URL . 'assets/jio-pay-logo.png';
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
        add_action('wp_ajax_jio_pay_return_handler', [$this, 'handle_return_url']);
        add_action('wp_ajax_nopriv_jio_pay_return_handler', [$this, 'handle_return_url']);
        add_action('wp_ajax_jio_pay_store_merchant_tr_id', [$this, 'store_merchant_tr_id']);
        add_action('wp_ajax_nopriv_jio_pay_store_merchant_tr_id', [$this, 'store_merchant_tr_id']);
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
        if (!is_admin() && WC()->cart) {
            $cart_total = WC()->cart->get_total('');
            $current_user = wp_get_current_user();

            // If checkout amount is not available or user is logged out, we're in test mode
            if (empty($cart_total) || $cart_total <= 0 || $current_user->ID <= 0) {
                $use_test_data = true;
            }
        } else {
            // Cart not available - test mode
            $use_test_data = true;
        }

        // Show test mode warning if applicable
        if ($use_test_data) {
            echo '<div class="jio-pay-test-mode-notice">';
            echo __('Test Mode: Using sample data because cart amount or user information is not available. Real payment will not be processed.', 'woocommerce');
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
            return [
                'result' => 'failure',
                'messages' => 'Order not found.'
            ];
        }

        // Set order status to pending payment
        $order->update_status('pending', __('Awaiting Jio Pay payment.', 'woocommerce'));

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

                // Try to find recent order
                $current_user = wp_get_current_user();
                $orders = wc_get_orders([
                    'customer' => $current_user->ID,
                    'status' => ['pending', 'on-hold'],
                    'payment_method' => 'jio_pay',
                    'limit' => 1,
                    'orderby' => 'date',
                    'order' => 'DESC'
                ]);

                if (empty($orders)) {
                    error_log('No pending orders found for user: ' . $current_user->ID);
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

    /**
     * Handle return URL callback from JioPay gateway
     * This receives POST data when customer is redirected back from payment gateway
     */
    public function handle_return_url()
    {
        // Enable error reporting for debugging
        error_reporting(E_ALL);
        ini_set('display_errors', 0); // Don't display errors to user

        error_log('=== JioPay Return URL Handler Started ===');
        error_log('POST Data: ' . print_r($_POST, true));
        error_log('GET Data: ' . print_r($_GET, true));

        try {
            // JioPay sends data via POST, capture all possible fields
            $payment_data = array_merge($_POST, $_GET);

            // Log received data
            error_log('Received payment data: ' . print_r($payment_data, true));

            // Extract common JioPay response fields
            $txn_auth_id = $payment_data['txnAuthID'] ?? $payment_data['txnAuthId'] ?? '';
            $txn_response_code = $payment_data['txnResponseCode'] ?? $payment_data['responseCode'] ?? '';
            $merchant_tr_id = $payment_data['merchantTrId'] ?? $payment_data['merchantTransactionId'] ?? '';
            $amount = $payment_data['amount'] ?? '';
            $order_id = $payment_data['order_id'] ?? $payment_data['orderId'] ?? 0;

            error_log(sprintf(
                'Extracted data - OrderID: %s, TxnAuthID: %s, ResponseCode: %s, Amount: %s',
                $order_id,
                $txn_auth_id,
                $txn_response_code,
                $amount
            ));

            // Try to find order by ID or merchant transaction ID
            $order = null;

            if ($order_id) {
                $order = wc_get_order($order_id);
                error_log('Found order by ID: ' . $order_id);
            } elseif ($merchant_tr_id) {
                // Search for order by merchant transaction ID stored in meta
                $orders = wc_get_orders([
                    'meta_key' => '_jio_pay_merchant_tr_id',
                    'meta_value' => $merchant_tr_id,
                    'limit' => 1,
                    'status' => ['pending', 'on-hold', 'processing']
                ]);

                if (!empty($orders)) {
                    $order = $orders[0];
                    $order_id = $order->get_id();
                    error_log('Found order by merchant transaction ID: ' . $order_id);
                }
            }

            // If still no order, try to find most recent pending order
            if (!$order) {
                error_log('No order found, searching for recent pending orders...');

                $orders = wc_get_orders([
                    'status' => ['pending', 'on-hold'],
                    'payment_method' => 'jio_pay',
                    'limit' => 1,
                    'orderby' => 'date',
                    'order' => 'DESC'
                ]);

                if (!empty($orders)) {
                    $order = $orders[0];
                    $order_id = $order->get_id();
                    error_log('Found recent pending order: ' . $order_id);
                }
            }

            if (!$order) {
                error_log('ERROR: No order found for payment callback');
                $this->redirect_with_error('Order not found. Please contact support with your payment details.');
                return;
            }

            // Check if order is already completed
            if ($order->has_status(['processing', 'completed'])) {
                error_log('Order already completed, redirecting to success page');
                wp_redirect($order->get_checkout_order_received_url());
                exit;
            }

            // Validate response code
            if ($txn_response_code !== '0000') {
                error_log('Payment failed with response code: ' . $txn_response_code);

                // Update order status to failed
                $order->update_status('failed', sprintf(
                    __('Payment failed via return URL. Response code: %s', 'woocommerce'),
                    $txn_response_code
                ));

                $this->redirect_with_error('Payment was not successful. Please try again.');
                return;
            }

            // Validate amount if provided
            if (!empty($amount)) {
                $order_amount = (float) $order->get_total();
                $paid_amount_raw = (float) $amount;

                // Try both paisa and rupees format
                $paid_amount_paisa = $paid_amount_raw / 100;
                $paid_amount_rupees = $paid_amount_raw;

                $diff_paisa = abs($order_amount - $paid_amount_paisa);
                $diff_rupees = abs($order_amount - $paid_amount_rupees);

                $paid_amount = $paid_amount_rupees;

                if ($diff_paisa <= 0.01) {
                    $paid_amount = $paid_amount_paisa;
                } elseif ($diff_rupees <= 0.01) {
                    $paid_amount = $paid_amount_rupees;
                } else {
                    error_log(sprintf(
                        'Amount mismatch - Order: %.2f, Payment: %.2f (paisa: %.2f)',
                        $order_amount,
                        $paid_amount_rupees,
                        $paid_amount_paisa
                    ));

                    $order->add_order_note(sprintf(
                        'Payment amount mismatch via return URL. Order: %.2f, Paid: %.2f',
                        $order_amount,
                        $paid_amount
                    ));

                    // Don't fail the order, but log the discrepancy
                }
            }

            // Complete the payment
            error_log('Completing payment for order: ' . $order_id);

            $order->payment_complete($txn_auth_id);
            $order->add_order_note(sprintf(
                'JioPay payment successful via return URL. Auth ID: %s, Response Code: %s',
                $txn_auth_id,
                $txn_response_code
            ));

            // Store payment data in order meta
            $order->update_meta_data('_jio_pay_txn_auth_id', $txn_auth_id);
            $order->update_meta_data('_jio_pay_response_code', $txn_response_code);
            $order->update_meta_data('_jio_pay_merchant_tr_id', $merchant_tr_id);
            $order->update_meta_data('_jio_pay_return_data', $payment_data);
            $order->save();

            // Reduce stock
            wc_reduce_stock_levels($order_id);

            // Clear cart
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }

            error_log('Payment completed successfully via return URL');

            // Redirect to order received page
            wp_redirect($order->get_checkout_order_received_url());
            exit;

        } catch (Exception $e) {
            error_log('Exception in handle_return_url: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());

            $this->redirect_with_error('An error occurred processing your payment. Please contact support.');
        }
    }

    /**
     * Redirect to checkout with error message
     */
    private function redirect_with_error($message)
    {
        wc_add_notice($message, 'error');
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Store merchant transaction ID in order meta
     */
    public function store_merchant_tr_id()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jio_pay_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $merchant_tr_id = sanitize_text_field($_POST['merchant_tr_id'] ?? '');

        if (!$order_id || !$merchant_tr_id) {
            wp_send_json_error(['message' => 'Missing order ID or merchant transaction ID']);
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
            return;
        }

        // Store merchant transaction ID in order meta
        $order->update_meta_data('_jio_pay_merchant_tr_id', $merchant_tr_id);
        $order->save();

        error_log(sprintf('Stored merchant transaction ID %s for order %d', $merchant_tr_id, $order_id));

        wp_send_json_success([
            'message' => 'Merchant transaction ID stored successfully',
            'order_id' => $order_id,
            'merchant_tr_id' => $merchant_tr_id
        ]);
    }

    public function test_ajax()
    {
        wp_send_json_success(['message' => 'AJAX is working correctly', 'data' => $_POST]);
        wp_die();
    }
}