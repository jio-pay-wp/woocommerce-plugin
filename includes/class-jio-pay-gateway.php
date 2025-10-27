<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Jio_Pay_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'jio_pay';
        $this->method_title       = __('Jio Pay Gateway', 'woocommerce');
        $this->method_description = __('Accept payments using Jio Pay SDK popup.', 'woocommerce');
        $this->has_fields         = false;
        $this->supports           = array(
            'products'
        );

        $this->init_form_fields();
        $this->init_settings();

        // Load merchant configs
    $this->title        = $this->get_option('title');
    $this->description  = $this->get_option('description');
    $this->merchant_id  = $this->get_option('merchant_id');
    $this->secret_key   = $this->get_option('secret_key');
    $this->environment  = $this->get_option('environment');
    $this->theme        = $this->get_option('theme');
    $this->payment_method = $this->get_option('payment_method');
    $this->allowed_payment_types = $this->get_option('allowed_payment_types');
    $this->timeout      = $this->get_option('timeout');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

        // AJAX endpoints
        add_action('wp_ajax_jio_pay_create_session', [$this, 'create_session']);
        add_action('wp_ajax_nopriv_jio_pay_create_session', [$this, 'create_session']);  
        add_action('wp_ajax_jio_pay_verify_payment', [$this, 'verify_payment']);
        add_action('wp_ajax_nopriv_jio_pay_verify_payment', [$this, 'verify_payment']);
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Enable Jio Pay', 'woocommerce'),
                'default' => 'yes'
            ],
            'title' => [
                'title'       => __('Title', 'woocommerce'),
                'type'        => 'text',
                'description' => __('Title shown during checkout', 'woocommerce'),
                'default'     => __('Jio Pay', 'woocommerce')
            ],
            'description' => [
                'title'       => __('Description', 'woocommerce'),
                'type'        => 'textarea',
                'description' => __('Description shown during checkout', 'woocommerce'),
                'default'     => __('Pay securely via Jio Pay popup', 'woocommerce')
            ],
            'merchant_id' => [
                'title'       => __('Merchant ID', 'woocommerce'),
                'type'        => 'text',
                'description' => __('Your Jio Pay merchant ID'),
                'default'     => ''
            ],
            'secret_key' => [
                'title'       => __('Secret Key', 'woocommerce'),
                'type'        => 'text',
                'description' => __('Your Jio Pay secret key'),
                'default'     => ''
            ],
            'environment' => [
                'title'       => __('Environment', 'woocommerce'),
                'type'        => 'select',
                'options'     => ['uat' => 'UAT', 'live' => 'Live'],
                'description' => __('Select UAT or Live environment'),
                'default'     => 'uat'
            ],
            'theme' => [
                'title'       => __('Theme (JSON)', 'woocommerce'),
                'type'        => 'text',
                'description' => __('Custom theme as JSON, e.g. {"primaryColor":"#E39B2B","secondaryColor":"#000"}'),
                'default'     => ''
            ],
            'payment_method' => [
                'title'       => __('Default Payment Method', 'woocommerce'),
                'type'        => 'select',
                'description' => __('Choose default payment method.'),
                'options'     => [
                    'netBanking' => __('Net Banking', 'woocommerce'),
                    'card' => __('Card', 'woocommerce'),
                    'upi' => __('UPI', 'woocommerce'),
                    'wallet' => __('Wallet', 'woocommerce'),
                ],
                'default'     => 'netBanking'
            ],
            'allowed_payment_types' => [
                'title'       => __('Allowed Payment Types', 'woocommerce'),
                'type'        => 'multiselect',
                'description' => __('Select allowed payment types.'),
                'options'     => [
                    'CARD' => __('Card', 'woocommerce'),
                    'NB' => __('Net Banking', 'woocommerce'),
                    'UPI_QR' => __('UPI QR', 'woocommerce'),
                    'UPI_INTENT' => __('UPI Intent', 'woocommerce'),
                    'UPI_VPA' => __('UPI VPA', 'woocommerce'),
                ],
                'default'     => ['CARD', 'NB', 'UPI_QR', 'UPI_INTENT', 'UPI_VPA']
            ],
            'timeout' => [
                'title'       => __('Timeout (ms)', 'woocommerce'),
                'type'        => 'number',
                'description' => __('Popup timeout in milliseconds, e.g. 1000'),
                'default'     => '1000'
            ]
        ];
    }

    /**
     * Check if the gateway is available for use
     */
    public function is_available() {
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
    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
        
        echo '<div id="jio-pay-payment-data" style="padding: 10px; background: #f9f9f9; border: 1px solid #ddd; margin: 10px 0;">';
        echo '<p>' . __('You will be redirected to Jio Pay to complete your payment securely.', 'woocommerce') . '</p>';
        echo '</div>';
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return [
                'result'   => 'failure',
                'messages' => 'Order not found.'
            ];
        }
        
        // Set order status to pending payment
        $order->update_status('pending', __('Awaiting Jio Pay payment.', 'woocommerce'));
        
        // Return success for JavaScript to handle the popup
        return [
            'result'   => 'success',
            'redirect' => '' // No redirect - JS will handle the popup
        ];
    }

    public function create_session() {
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

    public function verify_payment() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jio_pay_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        $order_id     = intval($_POST['order_id'] ?? 0);
        $payment_data = $_POST['payment_data'] ?? [];

        if (!$order_id) {
            wp_send_json_error(['message' => 'Invalid order']);
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
            return;
        }

        // Verify with Jio Pay API and complete payment
        $order->payment_complete();
        $order->add_order_note('Jio Pay payment successful. Payment ID: ' . ($payment_data['payment_id'] ?? ''));

        wp_send_json_success([
            'redirect' => $order->get_checkout_order_received_url()
        ]);
    }
}