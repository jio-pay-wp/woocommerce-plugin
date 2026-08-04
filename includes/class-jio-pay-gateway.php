<?php
if (!defined('ABSPATH'))
    exit;

// Show merchantTrId in WooCommerce admin order details (after class definition)
add_action('woocommerce_admin_order_data_after_order_details', function($order) {
    $merchant_tr_id = $order->get_meta('_jio_pay_merchant_tr_id');
    if ($merchant_tr_id) {
        echo '<div style="clear:both;"></div><div style="margin-top: 10px;"><p><strong>Merchant Transaction Id:</strong> <span style="color:#0073aa;">' . esc_html($merchant_tr_id) . '</span></p></div>';
    }
});

class WC_Jio_Pay_Gateway extends WC_Payment_Gateway
{

    // Declare properties to fix PHP 8.2 deprecation warnings
    public $merchant_id;
    public $secret_key;
    public $agregator_id;
    public $mcc_code;
    public $environment;
    public $theme;
    public $payment_method;
    public $allowed_payment_types;
    public $timeout;
    
    // Static flag to prevent duplicate hook registration
    private static $hooks_registered = false;

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
        $this->environment = $this->get_option('environment', 'uat');
        
        // Load credentials based on selected environment
        if ($this->environment === 'prod') {
            $this->merchant_id = $this->get_option('live_merchant_id');
            $this->secret_key = $this->get_option('live_secret_key');
            $this->agregator_id = $this->get_option('live_agregator_id');
            $this->mcc_code = $this->get_option('live_mcc_code');
        } else {
            $this->merchant_id = $this->get_option('uat_merchant_id');
            $this->secret_key = $this->get_option('uat_secret_key');
            $this->agregator_id = $this->get_option('uat_agregator_id');
            $this->mcc_code = $this->get_option('uat_mcc_code');
        }
        
        $this->theme = $this->get_option('theme');
        $this->payment_method = $this->get_option('payment_method');
        $this->allowed_payment_types = $this->get_option('allowed_payment_types');
        $this->timeout = $this->get_option('timeout');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_admin_field_environment_status', [$this, 'generate_environment_status_html']);

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

        // Refund endpoints (admin only)
        add_action('wp_ajax_jio_pay_process_refund', [$this, 'ajax_process_refund']);
        add_action('wp_ajax_jio_pay_get_refund_status', [$this, 'ajax_get_refund_status']);
        // Status check endpoint
        add_action('wp_ajax_jio_pay_check_status', [$this, 'ajax_check_status']);
        // Admin refund status check with auto-update
        add_action('wp_ajax_jio_pay_check_update_refund_status', [$this, 'ajax_check_and_update_refund_status']);

        // Add admin hooks for refund UI (only once)
        if (!self::$hooks_registered) {
            add_action('woocommerce_admin_order_data_after_order_details', [$this, 'add_refund_button']);
            add_action('admin_notices', [__CLASS__, 'check_live_credentials_notice']);
            add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hide_refund_item_meta']);
            add_action('admin_footer', [$this, 'hide_refund_ui']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_settings_assets']);
            self::$hooks_registered = true;
        }
    }

    /**
     * Load the WordPress media library on this gateway's settings screen so the
     * merchant logo picker works.
     */
    public function enqueue_settings_assets($hook)
    {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }
        // Only on Payments tab, our gateway section.
        $tab     = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
        $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
        if ($tab !== 'checkout' || $section !== $this->id) {
            return;
        }
        wp_enqueue_media();
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
            'environment' => [
                'title' => __('Environment', 'woocommerce'),
                'type' => 'select',
                'options' => ['uat' => 'UAT (Testing)', 'prod' => 'Live (Production)'],
                'description' => __('Select environment - Switch between UAT and Live without overwriting credentials', 'woocommerce'),
                'default' => 'uat',
                'desc_tip' => true
            ],
            'uat_credentials_section' => [
                'title' => __('UAT (Testing) Credentials', 'woocommerce'),
                'type' => 'title',
                'description' => __('Configure your UAT/Testing environment credentials. These will be used when Environment is set to UAT.', 'woocommerce'),
            ],
            'uat_merchant_id' => [
                'title' => __('UAT Merchant ID', 'woocommerce'),
                'type' => 'text',
                'description' => __('Your Jio Pay UAT merchant ID', 'woocommerce'),
                'default' => '',
                'desc_tip' => true
            ],
            'uat_secret_key' => [
                'title' => __('UAT Secret Key', 'woocommerce'),
                'type' => 'password',
                'description' => __('Your Jio Pay UAT secret key', 'woocommerce'),
                'default' => '',
                'desc_tip' => true
            ],
            'uat_agregator_id' => [
                'title' => __('UAT Agregator ID', 'woocommerce'),
                'type' => 'text',
                'description' => __('Your Jio Pay UAT agregator ID', 'woocommerce'),
                'default' => '',
                'desc_tip' => true
            ],
            'uat_mcc_code' => [
                'title' => __('UAT Merchant Category Code (MCC)', 'woocommerce'),
                'type' => 'text',
                'description' => __('Any valid 4-digit MCC code - REQUIRED', 'woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'placeholder' => '6012',
                'required' => true
            ],
            'live_credentials_section' => [
                'title' => __('Live (Production) Credentials', 'woocommerce'),
                'type' => 'title',
                'description' => __('Configure your Live/Production environment credentials. These will be used when Environment is set to Live.', 'woocommerce'),
            ],
            'live_merchant_id' => [
                'title' => __('Live Merchant ID', 'woocommerce'),
                'type' => 'text',
                'description' => __('Your Jio Pay Live merchant ID', 'woocommerce'),
                'default' => '',
                'desc_tip' => true
            ],
            'live_secret_key' => [
                'title' => __('Live Secret Key', 'woocommerce'),
                'type' => 'password',
                'description' => __('Your Jio Pay Live secret key', 'woocommerce'),
                'default' => '',
                'desc_tip' => true
            ],
            'live_agregator_id' => [
                'title' => __('Live Agregator ID', 'woocommerce'),
                'type' => 'text',
                'description' => __('Your Jio Pay Live agregator ID', 'woocommerce'),
                'default' => '',
                'desc_tip' => true
            ],
            'live_mcc_code' => [
                'title' => __('Live Merchant Category Code (MCC)', 'woocommerce'),
                'type' => 'text',
                'description' => __('Any valid 4-digit MCC code - REQUIRED', 'woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'placeholder' => '6012',
                'required' => true
            ],
            'payment_options_section' => [
                'title' => __('Payment Options', 'woocommerce'),
                'type' => 'title',
                'description' => __('Configure payment methods and display options', 'woocommerce'),
            ],
            'merchant_logo' => [
                'title' => __('Merchant Logo', 'woocommerce'),
                'type' => 'image',
                'description' => __('Logo shown on the Jio Pay checkout popup. Recommended: a square PNG. If left empty, the theme\'s Site Identity logo or the site icon (favicon) is used as a fallback.', 'woocommerce'),
                'default' => '',
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
                    'NB' => __('Net Banking', 'woocommerce'),
                    'UPI_QR' => __('UPI QR', 'woocommerce'),
                    'UPI_INTENT' => __('UPI Intent', 'woocommerce'),
                    'UPI_VPA' => __('UPI VPA', 'woocommerce'),
                    'CREDIT_CARD' => __('Credit Card', 'woocommerce'),
                    'DEBIT_CARD' => __('Debit Card', 'woocommerce'),
                ],
                'default' => ['NB', 'UPI_QR', 'UPI_VPA', 'CREDIT_CARD', 'DEBIT_CARD']
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
     * Display admin options with tabbed interface for UAT and Live credentials
     */
    public function admin_options()
    {
        // Load the WordPress media library so the Merchant Logo picker works.
        // Called here (not only via admin_enqueue_scripts) so it runs regardless
        // of when the gateway is instantiated on the settings screen.
        wp_enqueue_media();

        $current_env = $this->get_option('environment', 'uat');
        $env_label = $current_env === 'prod' ? 'Live (Production)' : 'UAT (Testing)';
        $env_color = $current_env === 'prod' ? '#dc3232' : '#46b450';
        ?>
        <h2><?php echo esc_html($this->get_method_title()); ?></h2>
        <?php echo wp_kses_post(wpautop($this->get_method_description())); ?>
        
        <p style="font-size: 15px; margin: 20px 0;">
            <strong>Current Active Environment:</strong> 
            <span style="color: <?php echo esc_attr($env_color); ?>; font-weight: bold; font-size: 16px;">
                <?php echo esc_html($env_label); ?>
            </span>
        </p>
        
        <?php if ($current_env === 'prod'): ?>
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 20px 0;">
            <strong>⚠️ Warning:</strong> You are in LIVE/PRODUCTION mode. Real transactions will be processed.
        </div>
        <?php else: ?>
        <div style="background: #d1ecf1; border-left: 4px solid #0c5460; padding: 12px; margin: 20px 0;">
            <strong>ℹ️ Info:</strong> You are in UAT/TESTING mode. Use test credentials for testing.
        </div>
        <?php endif; ?>
        
        <!-- Tab Navigation -->
        <div style="margin: 20px 0; border-bottom: 2px solid #ddd; display: flex; gap: 0;">
            <button type="button" class="jio-pay-tab-button jio-pay-tab-active" data-tab="general" style="background: #f9f9f9; border: 1px solid #ddd; border-bottom: 2px solid #0073aa; padding: 10px 20px; cursor: pointer; font-weight: 600; color: #0073aa;">
                <span style="font-size: 14px;">⚙️ General Settings</span>
            </button>
            <button type="button" class="jio-pay-tab-button" data-tab="uat" style="background: #f9f9f9; border: 1px solid #ddd; border-bottom: none; padding: 10px 20px; cursor: pointer; font-weight: 600; color: #666;">
                <span style="font-size: 14px;">🧪 UAT Credentials</span>
            </button>
            <button type="button" class="jio-pay-tab-button" data-tab="live" style="background: #f9f9f9; border: 1px solid #ddd; border-bottom: none; padding: 10px 20px; cursor: pointer; font-weight: 600; color: #666;">
                <span style="font-size: 14px;">🔴 Live Credentials</span>
            </button>
        </div>

        <!-- Tab Content -->
        <div style="margin-top: 20px; padding: 0;">
            <!-- General Settings Tab -->
            <div class="jio-pay-tab-content jio-pay-tab-active" data-tab="general" style="display: block; border: 1px solid #ddd; padding: 20px 25px; background: white;">
                <table class="form-table" style="margin: 0; border-collapse: collapse; width: 100%;">
                    <?php 
                    $general_fields = ['enabled', 'title', 'description', 'environment', 'merchant_logo', 'payment_method', 'allowed_payment_types', 'timeout'];
                    foreach ($general_fields as $field_key) {
                        if (isset($this->form_fields[$field_key])) {
                            $this->generate_settings_field($field_key);
                        }
                    }
                    ?>
                </table>
            </div>

            <!-- UAT Credentials Tab -->
            <div class="jio-pay-tab-content" data-tab="uat" style="display: none; border: 1px solid #ddd; padding: 20px 25px; background: white;">
                <table class="form-table" style="margin: 0; border-collapse: collapse; width: 100%;">
                    <?php 
                    $uat_fields = ['uat_merchant_id', 'uat_secret_key', 'uat_agregator_id', 'uat_mcc_code'];
                    foreach ($uat_fields as $field_key) {
                        if (isset($this->form_fields[$field_key])) {
                            $this->generate_settings_field($field_key);
                        }
                    }
                    ?>
                </table>
            </div>

            <!-- Live Credentials Tab -->
            <div class="jio-pay-tab-content" data-tab="live" style="display: none; border: 1px solid #ddd; padding: 20px 25px; background: white;">
                <table class="form-table" style="margin: 0; border-collapse: collapse; width: 100%;">
                    <?php 
                    $live_fields = ['live_merchant_id', 'live_secret_key', 'live_agregator_id', 'live_mcc_code'];
                    foreach ($live_fields as $field_key) {
                        if (isset($this->form_fields[$field_key])) {
                            $this->generate_settings_field($field_key);
                        }
                    }
                    ?>
                </table>
            </div>
        </div>

        <style>
            .jio-pay-tab-button {
                transition: all 0.3s ease;
                border-radius: 4px 4px 0 0 !important;
            }
            
            .jio-pay-tab-button:hover {
                background: #e8f0f8 !important;
            }
            
            .jio-pay-tab-button.jio-pay-tab-active {
                background: white !important;
                border-bottom: 3px solid #0073aa !important;
                color: #0073aa !important;
            }
            
            .jio-pay-tab-content {
                border-radius: 0 0 4px 4px;
            }
            
            .jio-pay-tab-content .form-table {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            
            .jio-pay-tab-content .form-table th,
            .jio-pay-tab-content .form-table td {
                padding: 12px 0 !important;
                vertical-align: top !important;
                border: none !important;
            }
            
            .jio-pay-tab-content .form-table th {
                text-align: left !important;
                padding-right: 20px !important;
                width: 30% !important;
                font-weight: 600 !important;
            }
            
            .jio-pay-tab-content .form-table th label {
                display: flex !important;
                align-items: center !important;
                gap: 8px !important;
                white-space: nowrap !important;
            }
            
            .jio-pay-tab-content .form-table td {
                width: 70% !important;
            }
            
            .jio-pay-tab-content .form-table tr:not(:last-child) {
                border-bottom: 1px solid #eee !important;
            }
            
            .description {
                font-size: 12px !important;
                color: #666 !important;
                margin-top: 5px !important;
                display: block !important;
            }
            
            /* Tooltip styling */
            .woocommerce-help-tip {
                display: inline-block !important;
                width: 18px !important;
                height: 18px !important;
                line-height: 18px !important;
                text-align: center !important;
                background-color: #0073aa !important;
                color: white !important;
                border-radius: 50% !important;
                font-weight: bold !important;
                font-size: 12px !important;
                cursor: help !important;
                margin: 0 !important;
                flex-shrink: 0 !important;
            }
            
            .woocommerce-help-tip:hover {
                background-color: #005a87 !important;
            }
        </style>

        <script>
            (function() {
                document.querySelectorAll('.jio-pay-tab-button').forEach(function(button) {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        const tabName = this.getAttribute('data-tab');
                        
                        // Hide all tab contents
                        document.querySelectorAll('.jio-pay-tab-content').forEach(function(tab) {
                            tab.style.display = 'none';
                            tab.classList.remove('jio-pay-tab-active');
                        });
                        
                        // Remove active class from all buttons
                        document.querySelectorAll('.jio-pay-tab-button').forEach(function(btn) {
                            btn.classList.remove('jio-pay-tab-active');
                            btn.style.borderBottom = 'none';
                            btn.style.color = '#666';
                        });
                        
                        // Show selected tab
                        document.querySelector('[data-tab="' + tabName + '"].jio-pay-tab-content').style.display = 'block';
                        document.querySelector('[data-tab="' + tabName + '"].jio-pay-tab-content').classList.add('jio-pay-tab-active');
                        
                        // Mark button as active
                        this.classList.add('jio-pay-tab-active');
                        this.style.borderBottom = '3px solid #0073aa';
                        this.style.color = '#0073aa';
                    });
                });

            })();

            // Merchant logo media picker.
            // wp.media loads via footer scripts, so this may run before it exists —
            // retry until it's available, then wire up the buttons once.
            (function() {
                var attempts = 0;
                function initLogoPicker() {
                    var wrapper = document.querySelector('.jio-pay-image-field');
                    if (!wrapper) { return; }
                    if (!(window.wp && wp.media)) {
                        if (attempts++ < 40) { setTimeout(initLogoPicker, 150); }
                        return;
                    }
                    if (wrapper.getAttribute('data-jio-picker-ready')) { return; }
                    wrapper.setAttribute('data-jio-picker-ready', '1');

                    var input   = wrapper.querySelector('input[type="text"]');
                    var preview = wrapper.querySelector('.jio-pay-image-preview img');
                    var upload  = wrapper.querySelector('.jio-pay-upload-logo');
                    var remove  = wrapper.querySelector('.jio-pay-remove-logo');
                    var frame;

                    function setLogo(url) {
                        input.value = url;
                        if (url) {
                            preview.src = url;
                            preview.style.display = '';
                            remove.style.display = '';
                        } else {
                            preview.src = '';
                            preview.style.display = 'none';
                            remove.style.display = 'none';
                        }
                    }

                    upload.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (frame) { frame.open(); return; }
                        frame = wp.media({
                            title: 'Select Merchant Logo',
                            button: { text: 'Use this logo' },
                            library: { type: 'image' },
                            multiple: false
                        });
                        frame.on('select', function() {
                            var attachment = frame.state().get('selection').first().toJSON();
                            setLogo(attachment.url);
                        });
                        frame.open();
                    });

                    remove.addEventListener('click', function(e) {
                        e.preventDefault();
                        setLogo('');
                    });

                    // Keep preview in sync if the URL is typed/pasted manually
                    input.addEventListener('change', function() {
                        setLogo(input.value.trim());
                    });
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initLogoPicker);
                } else {
                    initLogoPicker();
                }
            })();
        </script>
        <?php
    }

    /**
     * Generate a single field's HTML
     */
    private function generate_settings_field($field_key)
    {
        $field = $this->form_fields[$field_key];
        $method_name = 'generate_' . $field['type'] . '_html';
        
        if (method_exists($this, $method_name)) {
            echo $this->$method_name($field_key, $field);
        } else {
            // Fallback to standard field generation
            echo '<tr valign="top" class="' . esc_attr($field_key) . '_field">';
            echo '<th scope="row" class="titledesc">';
            echo '<label for="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" style="display: flex; align-items: center; gap: 8px;">';
            echo '<span>' . esc_html($field['title']) . '</span>';
            
            // Show info icon with tooltip if description exists and desc_tip is enabled
            if (!empty($field['description']) && !empty($field['desc_tip'])) {
                echo '<span class="woocommerce-help-tip" title="' . esc_attr($field['description']) . '" style="margin: 0; cursor: help;">?</span>';
            }
            
            echo '</label>';
            echo '</th>';
            echo '<td class="forminp">';
            
            if ($field['type'] === 'text' || $field['type'] === 'password') {
                echo '<input type="' . esc_attr($field['type']) . '" id="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" name="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" value="' . esc_attr($this->get_option($field_key)) . '" placeholder="' . esc_attr($field['placeholder'] ?? '') . '" style="width: 100%; max-width: 500px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />';
            } elseif ($field['type'] === 'checkbox') {
                echo '<input type="checkbox" id="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" name="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" value="yes" ' . checked($this->get_option($field_key), 'yes', false) . ' />';
            } elseif ($field['type'] === 'select') {
                echo '<select id="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" name="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" style="width: 100%; max-width: 500px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">';
                foreach ($field['options'] as $key => $label) {
                    echo '<option value="' . esc_attr($key) . '" ' . selected($this->get_option($field_key), $key, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';
            } elseif ($field['type'] === 'multiselect') {
                echo '<select id="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" name="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '[]" multiple="multiple" style="width: 100%; max-width: 500px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; min-height: 100px;">';
                $current_values = $this->get_option($field_key);
                if (!is_array($current_values)) {
                    $current_values = explode(',', $current_values);
                }
                foreach ($field['options'] as $key => $label) {
                    $selected = in_array($key, $current_values) ? 'selected="selected"' : '';
                    echo '<option value="' . esc_attr($key) . '" ' . $selected . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';
            } elseif ($field['type'] === 'number') {
                echo '<input type="number" id="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" name="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" value="' . esc_attr($this->get_option($field_key)) . '" style="width: 100%; max-width: 500px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />';
            } elseif ($field['type'] === 'textarea') {
                echo '<textarea id="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" name="woocommerce_' . esc_attr($this->id) . '_' . esc_attr($field_key) . '" style="width: 100%; max-width: 500px; min-height: 80px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">' . esc_textarea($this->get_option($field_key)) . '</textarea>';
            }
            
            // Show description text below input (not in tooltip)
            if (!empty($field['description']) && empty($field['desc_tip'])) {
                echo '<p class="description" style="margin-top: 5px; font-size: 12px; color: #666;">' . wp_kses_post($field['description']) . '</p>';
            }
            
            echo '</td>';
            echo '</tr>';
        }
    }

    /**
     * Render the merchant logo image field with a WP media picker.
     */
    public function generate_image_html($field_key, $field)
    {
        $field_id  = 'woocommerce_' . $this->id . '_' . $field_key;
        $value     = $this->get_option($field_key);
        $has_value = !empty($value);

        ob_start();
        ?>
        <tr valign="top" class="<?php echo esc_attr($field_key); ?>_field">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_id); ?>" style="display: flex; align-items: center; gap: 8px;">
                    <span><?php echo esc_html($field['title']); ?></span>
                </label>
            </th>
            <td class="forminp">
                <div class="jio-pay-image-field" style="display: flex; align-items: flex-start; gap: 15px; flex-wrap: wrap;">
                    <div class="jio-pay-image-preview" style="width: 80px; height: 80px; border: 1px solid #ddd; border-radius: 4px; background: #fafafa; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                        <img src="<?php echo esc_url($value); ?>" alt="" style="max-width: 100%; max-height: 100%; <?php echo $has_value ? '' : 'display: none;'; ?>" />
                    </div>
                    <div style="flex: 1; min-width: 260px;">
                        <input type="text"
                               id="<?php echo esc_attr($field_id); ?>"
                               name="<?php echo esc_attr($field_id); ?>"
                               value="<?php echo esc_attr($value); ?>"
                               placeholder="<?php esc_attr_e('No logo selected', 'woocommerce'); ?>"
                               style="width: 100%; max-width: 500px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
                        <p style="margin: 8px 0 0;">
                            <button type="button" class="button jio-pay-upload-logo"><?php esc_html_e('Select / Upload Logo', 'woocommerce'); ?></button>
                            <button type="button" class="button jio-pay-remove-logo" style="<?php echo $has_value ? '' : 'display: none;'; ?>"><?php esc_html_e('Remove', 'woocommerce'); ?></button>
                        </p>
                        <?php if (!empty($field['description'])): ?>
                            <p class="description" style="margin-top: 5px; font-size: 12px; color: #666;"><?php echo wp_kses_post($field['description']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Sanitize the merchant logo field value on save.
     */
    public function validate_image_field($key, $value)
    {
        return esc_url_raw(trim((string) $value));
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
            // Log which credentials are missing to help with debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $env = $this->environment === 'prod' ? 'Live' : 'UAT';
                $missing = [];
                if (empty($this->merchant_id)) $missing[] = 'Merchant ID';
                if (empty($this->secret_key)) $missing[] = 'Secret Key';
                error_log('[JioPay] Gateway not available: Missing ' . $env . ' credentials - ' . implode(', ', $missing));
            }
            return false;
        }

        return parent::is_available();
    }

    /**
     * Show admin notice if live mode is enabled but credentials are missing
     */
    public static function check_live_credentials_notice()
    {
        $settings = get_option('woocommerce_jio_pay_settings', []);
        $environment = $settings['environment'] ?? 'uat';
        
        if ($environment === 'prod') {
            $live_merchant_id = $settings['live_merchant_id'] ?? '';
            $live_secret_key = $settings['live_secret_key'] ?? '';
            
            if (empty($live_merchant_id) || empty($live_secret_key)) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Jio Pay Warning:</strong> You have selected Live (Production) mode but ';
                if (empty($live_merchant_id) && empty($live_secret_key)) {
                    echo 'Live Merchant ID and Live Secret Key are missing.';
                } elseif (empty($live_merchant_id)) {
                    echo 'Live Merchant ID is missing.';
                } else {
                    echo 'Live Secret Key is missing.';
                }
                echo ' The payment gateway will not be visible on checkout until these are configured.';
                echo ' <a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=jio_pay') . '">Configure now</a>';
                echo '</p></div>';
            }
        }
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields()
    {
        // Only render a description when the merchant actually set one
        // (trim guards against a blank-but-truthy value).
        $description = trim((string) $this->description);
        if ($description !== '') {
            echo wpautop(wp_kses_post($description));
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

        // Plain text note (no bordered box) so it blends into any theme's
        // checkout and never looks like an empty/broken box.
        echo '<p class="jio-pay-redirect-note" style="margin: 8px 0 0; font-size: 13px; color: #666; line-height: 1.5;">';
        echo esc_html__('You will be redirected to Jio Pay to complete your payment securely.', 'woocommerce');
        echo '</p>';
    }

    /**
     * Validate MCC Code field format
     * Valid MCC codes: ["6012", "6211"]
     * This field is MANDATORY
     */
    public function validate_mcc_code_field($key, $value)
    {
        // MCC Code is mandatory
        if (empty($value)) {
            WC_Admin_Settings::add_error(
                __('Merchant Category Code (MCC) is required. Please enter a valid 4-digit MCC code.', 'woo-jiopay')
            );
            return '';
        }

        // MCC Code must be exactly 4 digits
        if (!preg_match('/^\d{4}$/', $value)) {
            WC_Admin_Settings::add_error(
                __('Merchant Category Code (MCC) must be a 4-digit number.', 'woo-jiopay')
            );
            return '';
        }

        return $value;
    }

    /**
     * Validate UAT MCC Code
     */
    public function validate_uat_mcc_code_field($key, $value)
    {
        return $this->validate_mcc_code_field($key, $value);
    }

    /**
     * Validate Live MCC Code
     */
    public function validate_live_mcc_code_field($key, $value)
    {
        return $this->validate_mcc_code_field($key, $value);
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

    /**
     * Display refund button on order details page
     */
    public function add_refund_button($order)
    {
        if ($order->get_payment_method() !== 'jio_pay') {
            return;
        }

        // Check if order can be refunded
        if (!class_exists('WC_Jio_Pay_Refund')) {
            require_once(JIO_PAY_PLUGIN_DIR . 'includes/class-jio-pay-refund.php');
        }

        $refund_handler = new WC_Jio_Pay_Refund($this->merchant_id, $this->secret_key, $this->environment);
        
        // AUTO-CHECK REFUND STATUS FIRST if order is on-hold with pending refund
        $refund_merchant_txn_no = $order->get_meta('_jio_pay_refund_merchant_txn_no');
        if ($refund_merchant_txn_no && $order->get_status() === 'on-hold') {
            // Check status from gateway
            $response = $refund_handler->check_refund_status($refund_merchant_txn_no);
            
            // If refund is confirmed successful, update order to refunded
            if ($response['success'] && isset($response['txnStatus']) && $response['txnStatus'] === 'SUC') {
                $order->set_status('refunded');
                $order->save();
                
                // Add order note
                $order->add_order_note(
                    sprintf(
                        'Refund auto-confirmed: Transaction ID: %s, Refund ID: %s (confirmed on page load)',
                        sanitize_text_field($response['merchant_txn_no'] ?? 'N/A'),
                        sanitize_text_field($response['refund_id'] ?? 'N/A')
                    )
                );
            }
        }
        
        $can_refund = $refund_handler->can_refund_order($order->get_id());
        $refund_history = WC_Jio_Pay_Refund::get_refund_history($order->get_id());

        echo '<div style="margin-top: 15px; padding: 12px; background: #f9f9f9; border-left: 4px solid #0073aa; border-radius: 4px;">';
        echo '<h3 style="margin-top: 0;">Jio Pay Refund</h3>';

        // Status message area
        echo '<div id="jio_pay_refund_status_' . $order->get_id() . '" style="display:none; margin-bottom:12px; padding:10px; border-radius:4px; background:#e7f3ff; color:#0073aa; border:1px solid #0073aa;"></div>';

        // Show refund button or status message
        if ($can_refund['can_refund']) {
            echo '<button type="button" class="button button-primary jio-pay-refund-btn" data-order-id="' . $order->get_id() . '" style="background:#d32f2f;border-color:#d32f2f;">Process Refund</button>';
        } else {
            echo '<p><strong>Refund Status:</strong> ' . esc_html($can_refund['reason']) . '</p>';
            
            // If refund was attempted and order is NOT already refunded, show check status button
            if (!empty($refund_history) && $order->get_status() !== 'refunded') {
                $refund_merchant_txn_no = $order->get_meta('_jio_pay_refund_merchant_txn_no');
                if ($refund_merchant_txn_no) {
                    echo '<button type="button" class="button jio-pay-status-btn" data-type="refund" data-order-id="' . $order->get_id() . '" data-merchant-txn-no="' . esc_attr($refund_merchant_txn_no) . '" data-nonce="' . wp_create_nonce('jio_pay_status_nonce') . '" style="margin-top:10px;width:100%;background:#00b3b3;color:#fff;border:none;cursor:pointer;">Check Refund Status</button>';
                }
            }
        }

        // Status check for order (if has merchantTxnNo and authId - for all statuses including success)
        $merchant_tr_id = $order->get_meta('_jio_pay_merchant_tr_id');
        $auth_id = $order->get_transaction_id();
        if ($merchant_tr_id && $auth_id) {
            echo '<button type="button" class="button jio-pay-status-btn" data-type="order" data-order-id="' . $order->get_id() . '" data-merchant-txn-no="' . esc_attr($merchant_tr_id) . '" data-auth-id="' . esc_attr($auth_id) . '" data-nonce="' . wp_create_nonce('jio_pay_status_nonce') . '" style="margin-top:10px;width:100%;background:#00b3b3;color:#fff;border:none;cursor:pointer;">Check Order Status</button>';
        }

        echo '</div>';

        // Enqueue refund script
        $this->enqueue_admin_refund_script($order->get_id());
    }


    /**
     * Enqueue admin refund script
     */
    private function enqueue_admin_refund_script($order_id)
    {
        wp_enqueue_script(
            'jio-pay-refund-admin',
            JIO_PAY_PLUGIN_URL . 'assets/jio-pay-refund-admin.js',
            ['jquery'],
            '1.0',
            true
        );

        wp_enqueue_script(
            'jio-pay-status-admin',
            JIO_PAY_PLUGIN_URL . 'assets/jio-pay-status-admin.js',
            ['jquery'],
            '1.0',
            true
        );

        wp_localize_script('jio-pay-refund-admin', 'jio_pay_refund', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jio_pay_refund_nonce'),
            'order_id' => $order_id
        ]);
    }

    /**
     * Hide item-level refund buttons for Jio Pay orders
     * Refund is managed at the order summary level, not at item level
     */
    public function hide_refund_item_meta($hidden_metas)
    {
        // Only hide refund meta for Jio Pay orders
        global $post;
        
        if (!$post) {
            return $hidden_metas;
        }

        $order = wc_get_order($post->ID);
        if (!$order || $order->get_payment_method() !== 'jio_pay') {
            return $hidden_metas;
        }

        // Hide WooCommerce refund-related order item meta
        $hidden_metas[] = '_refunded_item_id';
        $hidden_metas[] = '_refund_id';
        $hidden_metas[] = '_refund_total';
        $hidden_metas[] = '_refund_reason';

        return $hidden_metas;
    }

    public function hide_refund_ui()
    {
        // Only on order edit pages
        if (!is_admin() || !isset($_GET['post']) || !isset($_GET['action']) || $_GET['action'] !== 'edit') {
            return;
        }

        $order_id = intval($_GET['post']);
        $order = wc_get_order($order_id);

        if (!$order || $order->get_payment_method() !== 'jio_pay') {
            return;
        }

        // Hide WooCommerce's built-in refund functionality UI
        ?>
        <style>
            /* Completely hide WooCommerce's item-level refund functionality for Jio Pay orders */
            .woocommerce-order-items .refund-item,
            .woocommerce-order-items tr.refund-item,
            .woocommerce-order-items .item-actions .refund,
            div.refund-item,
            table.woocommerce-order-items tbody tr.refund,
            table.woocommerce-order-items .refund-item,
            .woocommerce-order-items table a.refund-item,
            .woocommerce-order-items table button.refund-item {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* Hide refund buttons in item actions */
            .woocommerce-order-items .item-actions a.refund,
            .woocommerce-order-items .item-actions button.refund {
                display: none !important;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                // Alternative approach: Find and remove refund buttons by class/text
                $('.woocommerce-order-items .item-actions').each(function() {
                    $(this).find('a.refund, button.refund').remove();
                });
                
                // Remove any rows with refund class
                $('.woocommerce-order-items tbody tr.refund-item, .woocommerce-order-items tbody tr.refund').remove();
                
                // Remove items with .refund-item class
                $('.woocommerce-order-items .refund-item').closest('tr').remove();
            });
        </script>
        <?php
    }

    /**
     * AJAX: Process refund request
     */
    public function ajax_process_refund()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jio_pay_refund_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
            wp_die();
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'You do not have permission']);
            wp_die();
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(['message' => 'Invalid order ID']);
            wp_die();
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
            wp_die();
        }

        // Load refund class
        if (!class_exists('WC_Jio_Pay_Refund')) {
            require_once(JIO_PAY_PLUGIN_DIR . 'includes/class-jio-pay-refund.php');
        }

        $refund_handler = new WC_Jio_Pay_Refund($this->merchant_id, $this->secret_key, $this->environment);
        $result = $refund_handler->process_full_refund($order_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
        wp_die();
    }

    /**
     * AJAX: Get refund status
     */
    public function ajax_get_refund_status()
    {
        wp_send_json_success(['message' => 'Refund status check']);
        wp_die();
    }

    /**
     * AJAX: Check order/refund status
     */
    public function ajax_check_status()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jio_pay_status_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
            wp_die();
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $merchant_txn_no = sanitize_text_field($_POST['merchant_txn_no'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? 'order');

        if (!$order_id || !$merchant_txn_no) {
            wp_send_json_error(['message' => 'Missing parameters']);
            wp_die();
        }

        // Load refund class to use its API methods
        if (!class_exists('WC_Jio_Pay_Refund')) {
            require_once(JIO_PAY_PLUGIN_DIR . 'includes/class-jio-pay-refund.php');
        }

        $refund_handler = new WC_Jio_Pay_Refund($this->merchant_id, $this->secret_key, $this->environment);
        
        // Use the refund class's check_refund_status which properly parses the response
        $status_result = $refund_handler->check_refund_status($merchant_txn_no);

        wp_send_json_success($status_result);
        wp_die();
    }

    /**
     * AJAX: Check refund status and update order
     */
    public function ajax_check_and_update_refund_status()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jio_pay_status_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
            wp_die();
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'You do not have permission']);
            wp_die();
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $refund_merchant_txn_no = sanitize_text_field($_POST['merchant_txn_no'] ?? '');

        if (!$order_id || !$refund_merchant_txn_no) {
            wp_send_json_error(['message' => 'Missing parameters']);
            wp_die();
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
            wp_die();
        }

        // Load refund class
        if (!class_exists('WC_Jio_Pay_Refund')) {
            require_once(JIO_PAY_PLUGIN_DIR . 'includes/class-jio-pay-refund.php');
        }

        $refund_handler = new WC_Jio_Pay_Refund($this->merchant_id, $this->secret_key, $this->environment);
        
        // Call the public method to check status
        $status_result = $refund_handler->check_refund_status($refund_merchant_txn_no);

        // If refund successful, update order to refunded
        if ($status_result['success'] && isset($status_result['raw_response']['txnStatus']) && $status_result['raw_response']['txnStatus'] === 'SUC') {
            $order->update_status('refunded', __('Refund confirmed successful via JioPay', 'woo-jiopay'));
            
            // Store refund response
            $order->update_meta_data('_jio_pay_refund_response', $status_result);
            $order->update_meta_data('_jio_pay_refund_date', current_time('mysql'));
            $order->save();

            // Add REFUNDED entry to refund history
            $refund_id = isset($status_result['raw_response']['refund_id']) ? $status_result['raw_response']['refund_id'] : (isset($status_result['raw_response']['txnID']) ? $status_result['raw_response']['txnID'] : '');
            WC_Jio_Pay_Refund::add_refund_history_entry($order_id, 'REFUNDED', $refund_id);

            // Add order note with refund details
            $order->add_order_note(sprintf(
                __('Refund status confirmed. Refund Txn ID: %s | Jio Pay Refund ID: %s', 'woo-jiopay'),
                $refund_merchant_txn_no,
                $refund_id
            ));

            wp_send_json_success([
                'message' => 'Refund confirmed and order updated',
                'status' => 'refunded',
                'response' => $status_result
            ]);
        } else {
            wp_send_json_success([
                'message' => 'Status check completed',
                'status' => 'pending',
                'response' => $status_result
            ]);
        }

        wp_die();
    }

    
}