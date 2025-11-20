<?php
/**
 * WooCommerce Jio Pay Gateway Admin Settings
 * 
 * Handles admin interface and update management
 */

if (!defined('ABSPATH')) exit;

class Jio_Pay_Admin {
    
    private $update_checker;
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_jio_pay_check_updates', array($this, 'ajax_check_updates'));
        add_action('admin_notices', array($this, 'show_admin_notices'));
        
        // Add test actions for development
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('wp_ajax_jio_pay_test_update', array($this, 'ajax_test_update'));
        }
    }
    
    /**
     * Set update checker instance
     */
    public function set_update_checker($update_checker) {
        $this->update_checker = $update_checker;
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Jio Pay Gateway',
            'Jio Pay Gateway',
            'manage_woocommerce',
            'woo-jiopay',
            array($this, 'admin_page')
        );
        
        // Add debug submenu for testing (only in development)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_submenu_page(
                'woo-jiopay',
                'Update Test',
                'Update Test',
                'manage_options',
                'jio-pay-update-test',
                array($this, 'update_test_page')
            );
        }
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting('jio_pay_settings', 'jio_pay_admin_options');
        
        add_settings_section(
            'jio_pay_update_section',
            'Update Management',
            array($this, 'update_section_callback'),
            'jio_pay_settings'
        );
        
        add_settings_field(
            'auto_updates',
            'Auto Updates',
            array($this, 'auto_updates_callback'),
            'jio_pay_settings',
            'jio_pay_update_section'
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'woo-jiopay') === false) {
            return;
        }
        
        wp_enqueue_script(
            'jio-pay-admin',
            JIO_PAY_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            JIO_PAY_VERSION,
            true
        );
        
        wp_localize_script('jio-pay-admin', 'jio_pay_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jio_pay_admin_nonce'),
            'checking_text' => __('Checking for updates...', 'woo-jiopay'),
            'no_updates_text' => __('No updates available', 'woo-jiopay'),
            'update_available_text' => __('Update available!', 'woo-jiopay')
        ));
        
        wp_enqueue_style(
            'jio-pay-admin',
            JIO_PAY_PLUGIN_URL . 'assets/admin.css',
            array(),
            JIO_PAY_VERSION
        );
    }
    
    /**
     * Admin page content
     */
    public function admin_page() {
        $current_version = JIO_PAY_VERSION;
        $is_update_available = $this->update_checker ? $this->update_checker->is_update_available() : false;
        
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html(get_admin_page_title()); ?>
                <span class="title-count theme-count"><?php echo esc_html($current_version); ?></span>
            </h1>
            
            <div class="notice notice-info">
                <p>
                    <strong><?php _e('WooCommerce Jio Pay Gateway is active and ready to accept payments.', 'woo-jiopay'); ?></strong>
                </p>
                <p>
                    <?php _e('Configure your payment settings in', 'woo-jiopay'); ?> 
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=jio_pay')); ?>">
                        <?php _e('WooCommerce → Settings → Payments', 'woo-jiopay'); ?>
                    </a>
                </p>
            </div>
            
            <div class="card">
                <h2 class="title"><?php _e('Plugin Information', 'woo-jiopay'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Current Version', 'woo-jiopay'); ?></th>
                        <td>
                            <code><?php echo esc_html($current_version); ?></code>
                            <span id="jio-pay-update-status" class="<?php echo $is_update_available ? 'update-available' : 'up-to-date'; ?>">
                                <?php if ($is_update_available): ?>
                                    <span class="dashicons dashicons-update" style="color: #d63638;"></span>
                                    <?php _e('Update available', 'woo-jiopay'); ?>
                                <?php else: ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                                    <?php _e('Up to date', 'woo-jiopay'); ?>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Plugin Status', 'woo-jiopay'); ?></th>
                        <td>
                            <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                            <?php _e('Active', 'woo-jiopay'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('WooCommerce Status', 'woo-jiopay'); ?></th>
                        <td>
                            <?php if (class_exists('WooCommerce')): ?>
                                <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                                <?php printf(__('Active (v%s)', 'woo-jiopay'), WC_VERSION); ?>
                            <?php else: ?>
                                <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                                <?php _e('Not Active', 'woo-jiopay'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('HPOS Compatibility', 'woo-jiopay'); ?></th>
                        <td>
                            <?php if (function_exists('jio_pay_is_hpos_enabled')): ?>
                                <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                                <?php if (jio_pay_is_hpos_enabled()): ?>
                                    <?php _e('HPOS Enabled & Compatible', 'woo-jiopay'); ?>
                                <?php else: ?>
                                    <?php _e('HPOS Compatible (Traditional Storage)', 'woo-jiopay'); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                                <?php _e('Compatible', 'woo-jiopay'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Order Storage', 'woo-jiopay'); ?></th>
                        <td>
                            <?php if (function_exists('jio_pay_is_hpos_enabled') && jio_pay_is_hpos_enabled()): ?>
                                <span class="dashicons dashicons-database" style="color: #2271b1;"></span>
                                <?php _e('High-Performance Order Storage (HPOS)', 'woo-jiopay'); ?>
                            <?php else: ?>
                                <span class="dashicons dashicons-wordpress" style="color: #2271b1;"></span>
                                <?php _e('WordPress Posts Table', 'woo-jiopay'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="button" id="jio-pay-check-updates" class="button button-secondary">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Check for Updates', 'woo-jiopay'); ?>
                    </button>
                    
                    <?php if ($is_update_available): ?>
                        <a href="<?php echo esc_url(wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode(plugin_basename(JIO_PAY_PLUGIN_FILE))), 'upgrade-plugin_' . plugin_basename(JIO_PAY_PLUGIN_FILE))); ?>" class="button button-primary">
                            <span class="dashicons dashicons-download"></span>
                            <?php _e('Update Now', 'woo-jiopay'); ?>
                        </a>
                    <?php endif; ?>
                </p>
            </div>
            
            <div class="card">
                <h2 class="title"><?php _e('Gateway Configuration', 'woo-jiopay'); ?></h2>
                <?php 
                $gateway_settings = get_option('woocommerce_jio_pay_settings', array());
                $merchant_id = !empty($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : __('Not configured', 'woo-jiopay');
                $agregator_id = !empty($gateway_settings['agregator_id']) ? $gateway_settings['agregator_id'] : __('Not configured', 'woo-jiopay');
                $environment = !empty($gateway_settings['environment']) ? ucfirst($gateway_settings['environment']) : __('Not configured', 'woo-jiopay');
                $enabled = isset($gateway_settings['enabled']) && $gateway_settings['enabled'] === 'yes';
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Gateway Status', 'woo-jiopay'); ?></th>
                        <td>
                            <?php if ($enabled): ?>
                                <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                                <?php _e('Enabled', 'woo-jiopay'); ?>
                            <?php else: ?>
                                <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                                <?php _e('Disabled', 'woo-jiopay'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Merchant ID', 'woo-jiopay'); ?></th>
                        <td>
                            <code><?php echo esc_html($merchant_id); ?></code>
                            <?php if ($merchant_id === __('Not configured', 'woo-jiopay')): ?>
                                <span style="color: #d63638; margin-left: 10px;"><?php _e('⚠ Required', 'woo-jiopay'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Agregator ID', 'woo-jiopay'); ?></th>
                        <td>
                            <code><?php echo esc_html($agregator_id); ?></code>
                            <?php if ($agregator_id === __('Not configured', 'woo-jiopay')): ?>
                                <span style="color: #856404; margin-left: 10px;"><?php _e('⚠ Optional', 'woo-jiopay'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Environment', 'woo-jiopay'); ?></th>
                        <td>
                            <span class="badge badge-<?php echo strtolower($environment); ?>" style="
                                background: <?php echo $environment === 'Live' ? '#00a32a' : '#d63638'; ?>;
                                color: white;
                                padding: 3px 8px;
                                border-radius: 4px;
                                font-size: 12px;
                                text-transform: uppercase;
                            ">
                                <?php echo esc_html($environment); ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="card">
                <h2 class="title"><?php _e('Documentation & Support', 'woo-jiopay'); ?></h2>
                <p><?php _e('Need help with setup or troubleshooting? Check out our comprehensive documentation.', 'woo-jiopay'); ?></p>
                
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><a href="https://github.com/jio-pay-wp/woocommerce-plugin/blob/main/README.md" target="_blank"><?php _e('Installation Guide', 'woo-jiopay'); ?></a></li>
                    <li><a href="https://github.com/jio-pay-wp/woocommerce-plugin/blob/main/docs/INTEGRATION_GUIDE.md" target="_blank"><?php _e('Integration Guide', 'woo-jiopay'); ?></a></li>
                    <li><a href="https://github.com/jio-pay-wp/woocommerce-plugin/blob/main/docs/TROUBLESHOOTING_GUIDE.md" target="_blank"><?php _e('Troubleshooting Guide', 'woo-jiopay'); ?></a></li>
                    <li><a href="https://github.com/jio-pay-wp/woocommerce-plugin/blob/main/docs/API_DOCUMENTATION.md" target="_blank"><?php _e('API Documentation', 'woo-jiopay'); ?></a></li>
                    <li><a href="https://github.com/jio-pay-wp/woocommerce-plugin" target="_blank"><?php _e('GitHub Repository', 'woo-jiopay'); ?></a></li>
                </ul>
            </div>
            
            <div class="card">
                <h2 class="title"><?php _e('Quick Actions', 'woo-jiopay'); ?></h2>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=jio_pay')); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php _e('Payment Settings', 'woo-jiopay'); ?>
                    </a>
                    
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-orders')); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-list-view"></span>
                        <?php _e('View Orders', 'woo-jiopay'); ?>
                    </a>
                    
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-reports')); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <?php _e('Payment Reports', 'woo-jiopay'); ?>
                    </a>
                </p>
            </div>
        </div>
        
        <style>
            .jio-pay-admin .card {
                max-width: 800px;
                margin: 20px 0;
            }
            
            .jio-pay-admin .title-count {
                background: #2271b1;
                color: white;
                border-radius: 10px;
                padding: 3px 8px;
                font-size: 12px;
                font-weight: normal;
                margin-left: 10px;
            }
            
            #jio-pay-update-status.update-available {
                color: #d63638;
                font-weight: bold;
            }
            
            #jio-pay-update-status.up-to-date {
                color: #00a32a;
            }
            
            .button .dashicons {
                vertical-align: middle;
                margin-right: 5px;
                margin-top: -2px;
            }
        </style>
        <?php
    }
    
    /**
     * Update section callback
     */
    public function update_section_callback() {
        echo '<p>' . __('Manage automatic updates and version checking.', 'woo-jiopay') . '</p>';
    }
    
    /**
     * Auto updates callback
     */
    public function auto_updates_callback() {
        $options = get_option('jio_pay_admin_options');
        $auto_updates = isset($options['auto_updates']) ? $options['auto_updates'] : 'no';
        
        echo '<label for="auto_updates">';
        echo '<input type="checkbox" id="auto_updates" name="jio_pay_admin_options[auto_updates]" value="yes" ' . checked($auto_updates, 'yes', false) . ' />';
        echo ' ' . __('Enable automatic updates', 'woo-jiopay');
        echo '</label>';
        echo '<p class="description">' . __('When enabled, the plugin will automatically update to the latest version.', 'woo-jiopay') . '</p>';
    }
    
    /**
     * AJAX check for updates
     */
    public function ajax_check_updates() {
        check_ajax_referer('jio_pay_admin_nonce', 'nonce');
        
        if (!current_user_can('update_plugins')) {
            wp_die(__('You do not have sufficient permissions.', 'woo-jiopay'));
        }
        
        if ($this->update_checker) {
            $this->update_checker->force_update_check();
            $is_available = $this->update_checker->is_update_available();
            
            wp_send_json_success(array(
                'update_available' => $is_available,
                'message' => $is_available ? 
                    __('Update available! Refresh the page to see details.', 'woo-jiopay') :
                    __('No updates available. You have the latest version.', 'woo-jiopay')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Update checker not available.', 'woo-jiopay')
            ));
        }
    }
    
    /**
     * Show admin notices
     */
    public function show_admin_notices() {
        $screen = get_current_screen();
        
        if (!$screen || strpos($screen->id, 'jio-pay') === false) {
            return;
        }
        
        // Show success message after update
        if (isset($_GET['updated']) && $_GET['updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible">
                    <p><strong>' . __('WooCommerce Jio Pay Gateway updated successfully!', 'woo-jiopay') . '</strong></p>
                  </div>';
        }
    }
    
    /**
     * Update test page (for development)
     */
    public function update_test_page() {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            wp_die(__('This page is only available in debug mode.', 'woo-jiopay'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Update System Test', 'woo-jiopay'); ?></h1>
            
            <div class="card">
                <h2><?php _e('Test Update Notifications', 'woo-jiopay'); ?></h2>
                <p><?php _e('Use these tools to test the update notification system.', 'woo-jiopay'); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Current Version', 'woo-jiopay'); ?></th>
                        <td><code><?php echo esc_html(JIO_PAY_VERSION); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Test Server URL', 'woo-jiopay'); ?></th>
                        <td><code><?php echo esc_url(JIO_PAY_PLUGIN_URL . 'test-update-server.php'); ?></code></td>
                    </tr>
                </table>
                
                <p>
                    <button type="button" id="test-local-update" class="button button-secondary">
                        <?php _e('Test with Local Server (v1.1.0)', 'woo-jiopay'); ?>
                    </button>
                    
                    <button type="button" id="test-github-update" class="button button-secondary">
                        <?php _e('Test with GitHub API', 'woo-jiopay'); ?>
                    </button>
                    
                    <button type="button" id="clear-update-cache" class="button button-secondary">
                        <?php _e('Clear Update Cache', 'woo-jiopay'); ?>
                    </button>
                </p>
                
                <div id="test-results" style="margin-top: 20px;"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-local-update').on('click', function() {
                testUpdate('<?php echo esc_js(JIO_PAY_PLUGIN_URL . 'test-update-server.php'); ?>');
            });
            
            $('#test-github-update').on('click', function() {
                testUpdate('https://api.github.com/repos/jio-pay-wp/woocommerce-plugin/releases/latest');
            });
            
            $('#clear-update-cache').on('click', function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'jio_pay_test_update',
                        test_action: 'clear_cache',
                        nonce: '<?php echo wp_create_nonce('jio_pay_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        $('#test-results').html('<div class="notice notice-success"><p>Update cache cleared successfully!</p></div>');
                    }
                });
            });
            
            function testUpdate(serverUrl) {
                $('#test-results').html('<div class="notice notice-info"><p>Testing update system...</p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'jio_pay_test_update',
                        test_action: 'test_server',
                        server_url: serverUrl,
                        nonce: '<?php echo wp_create_nonce('jio_pay_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#test-results').html('<div class="notice notice-success"><p><strong>Test successful!</strong><br>' + response.data.message + '</p></div>');
                        } else {
                            $('#test-results').html('<div class="notice notice-error"><p><strong>Test failed:</strong><br>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#test-results').html('<div class="notice notice-error"><p>AJAX request failed</p></div>');
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    /**
     * AJAX test update
     */
    public function ajax_test_update() {
        check_ajax_referer('jio_pay_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'woo-jiopay'));
        }
        
        $test_action = sanitize_text_field($_POST['test_action'] ?? '');
        
        if ($test_action === 'clear_cache') {
            delete_transient('jio_pay_update_' . md5(plugin_basename(JIO_PAY_PLUGIN_FILE)));
            delete_site_transient('update_plugins');
            wp_send_json_success(array('message' => 'Cache cleared successfully'));
        }
        
        if ($test_action === 'test_server') {
            $server_url = esc_url_raw($_POST['server_url'] ?? '');
            
            $response = wp_remote_get($server_url, array('timeout' => 10));
            
            if (is_wp_error($response)) {
                wp_send_json_error(array('message' => $response->get_error_message()));
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (empty($data)) {
                wp_send_json_error(array('message' => 'Invalid response from server'));
            }
            
            $version = isset($data['tag_name']) ? ltrim($data['tag_name'], 'v') : ($data['version'] ?? 'unknown');
            $current_version = JIO_PAY_VERSION;
            
            $message = sprintf(
                'Server responded successfully!<br>Current version: %s<br>Remote version: %s<br>Update available: %s',
                $current_version,
                $version,
                version_compare($current_version, $version, '<') ? 'Yes' : 'No'
            );
            
            wp_send_json_success(array('message' => $message));
        }
        
        wp_send_json_error(array('message' => 'Invalid test action'));
    }
}