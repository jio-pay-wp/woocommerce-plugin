<?php
/**
 * Jio Pay Gateway Update Checker
 * 
 * Handles plugin update notifications and automatic updates
 */

if (!defined('ABSPATH')) exit;

class Jio_Pay_Update_Checker {
    
    private $plugin_slug;
    private $plugin_file;
    private $version;
    private $cache_key;
    private $cache_allowed;
    private $update_server_url;
    
    public function __construct($plugin_file, $version, $update_server_url = '') {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->version = $version;
        $this->cache_key = 'jio_pay_update_' . md5($this->plugin_slug);
        $this->cache_allowed = true;
        $this->update_server_url = $update_server_url ?: 'https://api.github.com/repos/jio-pay-wp/woocommerce-plugin/releases/latest';
        
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        
        // Add update row to plugins page
        add_action('after_plugin_row_' . $this->plugin_slug, array($this, 'show_update_row'), 10, 2);
        
        // Add plugin action links
        add_filter('plugin_action_links_' . $this->plugin_slug, array($this, 'plugin_action_links'));
        
        // Add custom update server if provided
        if (!empty($update_server_url)) {
            add_action('admin_init', array($this, 'show_update_notifications'));
        }
    }
    
    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Get remote version info
        $remote_info = $this->get_remote_version();
        
        if ($remote_info && version_compare($this->version, $remote_info['version'], '<')) {
            $transient->response[$this->plugin_slug] = (object) array(
                'slug' => dirname($this->plugin_slug),
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_info['version'],
                'url' => $remote_info['url'],
                'package' => $remote_info['download_url'],
                'tested' => $remote_info['tested'],
                'requires_php' => $remote_info['requires_php'] ?? '7.4',
                'compatibility' => new stdClass(),
                'upgrade_notice' => $remote_info['upgrade_notice'] ?? ''
            );
        }
        
        return $transient;
    }
    
    /**
     * Get remote version information
     */
    private function get_remote_version() {
        $request = $this->get_cached_version_info();
        
        if ($request === false) {
            $request = $this->fetch_remote_version();
            
            if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
                $this->set_cached_version_info($request);
            } else {
                return false;
            }
        }
        
        $body = wp_remote_retrieve_body($request);
        $data = json_decode($body, true);
        
        if (empty($data)) {
            return false;
        }
        
        // Parse GitHub API response
        if (isset($data['tag_name'])) {
            return array(
                'version' => ltrim($data['tag_name'], 'v'),
                'url' => $data['html_url'],
                'download_url' => $data['zipball_url'],
                'tested' => '6.4',
                'requires_php' => '7.4',
                'upgrade_notice' => $this->parse_changelog($data['body'] ?? '')
            );
        }
        
        // Parse custom API response
        return array(
            'version' => $data['version'] ?? '',
            'url' => $data['url'] ?? '',
            'download_url' => $data['download_url'] ?? '',
            'tested' => $data['tested'] ?? '6.4',
            'requires_php' => $data['requires_php'] ?? '7.4',
            'upgrade_notice' => $data['upgrade_notice'] ?? ''
        );
    }
    
    /**
     * Fetch remote version from server
     */
    private function fetch_remote_version() {
        return wp_remote_get($this->update_server_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
            )
        ));
    }
    
    /**
     * Get cached version info
     */
    private function get_cached_version_info() {
        if (!$this->cache_allowed) {
            return false;
        }
        
        return get_transient($this->cache_key);
    }
    
    /**
     * Set cached version info
     */
    private function set_cached_version_info($data) {
        set_transient($this->cache_key, $data, 12 * HOUR_IN_SECONDS);
    }
    
    /**
     * Parse changelog for upgrade notice
     */
    private function parse_changelog($changelog) {
        $lines = explode("\n", $changelog);
        $notice = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '**') === 0 || strpos($line, '## ') === 0) {
                $notice .= strip_tags($line) . ' ';
            }
            if (strlen($notice) > 200) {
                break;
            }
        }
        
        return trim($notice);
    }
    
    /**
     * Show plugin information popup
     */
    public function plugin_popup($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== dirname($this->plugin_slug)) {
            return $result;
        }
        
        $remote_info = $this->get_remote_version();
        
        if (!$remote_info) {
            return $result;
        }
        
        return (object) array(
            'name' => 'Jio Pay Gateway',
            'slug' => dirname($this->plugin_slug),
            'version' => $remote_info['version'],
            'author' => '<a href="https://github.com/jio-pay-wp">Jio Pay</a>',
            'author_profile' => 'https://github.com/jio-pay-wp',
            'homepage' => 'https://github.com/jio-pay-wp/woocommerce-plugin',
            'requires' => '5.0',
            'tested' => $remote_info['tested'],
            'requires_php' => $remote_info['requires_php'],
            'download_link' => $remote_info['download_url'],
            'sections' => array(
                'description' => $this->get_plugin_description(),
                'installation' => $this->get_installation_instructions(),
                'changelog' => $this->get_changelog($remote_info),
                'upgrade_notice' => $remote_info['upgrade_notice']
            ),
            'banners' => array(
                'low' => plugin_dir_url(dirname(__FILE__)) . 'assets/banner-772x250.png',
                'high' => plugin_dir_url(dirname(__FILE__)) . 'assets/banner-1544x500.png'
            ),
            'icons' => array(
                '1x' => plugin_dir_url(dirname(__FILE__)) . 'assets/icon-128x128.png',
                '2x' => plugin_dir_url(dirname(__FILE__)) . 'assets/icon-256x256.png'
            )
        );
    }
    
    /**
     * Get plugin description for popup
     */
    private function get_plugin_description() {
        return '<p><strong>Jio Pay Gateway for WooCommerce</strong></p>
                <p>Accept payments via Jio Pay SDK popup during WooCommerce checkout. Secure, fast, and reliable payment processing for Indian businesses.</p>
                <h4>Features:</h4>
                <ul>
                    <li>✅ Secure payment processing with Jio Pay SDK</li>
                    <li>✅ Test mode for development and testing</li>
                    <li>✅ Automatic payment verification</li>
                    <li>✅ Professional notification system</li>
                    <li>✅ Mobile-responsive design</li>
                    <li>✅ Complete error handling and logging</li>
                </ul>';
    }
    
    /**
     * Get installation instructions
     */
    private function get_installation_instructions() {
        return '<ol>
                    <li>Upload the plugin files to <code>/wp-content/plugins/woo-jiopay</code></li>
                    <li>Activate the plugin through the "Plugins" screen in WordPress</li>
                    <li>Go to <strong>WooCommerce → Settings → Payments</strong></li>
                    <li>Configure <strong>Jio Pay Gateway</strong> with your credentials</li>
                    <li>Enable the gateway and save settings</li>
                </ol>
                <p>For detailed setup instructions, see the <a href="https://github.com/jio-pay-wp/woocommerce-plugin/blob/main/README.md" target="_blank">README file</a>.</p>';
    }
    
    /**
     * Get changelog information
     */
    private function get_changelog($remote_info) {
        $changelog = '<h4>Version ' . $remote_info['version'] . '</h4>';
        $changelog .= '<p>' . $remote_info['upgrade_notice'] . '</p>';
        
        // Add current version changelog
        $changelog .= '<h4>Version ' . $this->version . ' (Current)</h4>';
        $changelog .= '<ul>
                        <li>✅ Initial release</li>
                        <li>✅ Jio Pay SDK integration</li>
                        <li>✅ Test mode support</li>
                        <li>✅ Professional notifications</li>
                        <li>✅ Complete documentation</li>
                       </ul>';
        
        return $changelog;
    }
    
    /**
     * Handle post-install actions
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        $install_directory = plugin_dir_path($this->plugin_file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        
        if ($this->cache_allowed) {
            delete_transient($this->cache_key);
        }
        
        return $result;
    }
    
    /**
     * Show custom update notifications in admin
     */
    public function show_update_notifications() {
        if (!current_user_can('update_plugins')) {
            return;
        }
        
        $remote_info = $this->get_remote_version();
        
        if ($remote_info && version_compare($this->version, $remote_info['version'], '<')) {
            add_action('admin_notices', array($this, 'show_update_notice'));
        }
    }
    
    /**
     * Display update notice
     */
    public function show_update_notice() {
        $remote_info = $this->get_remote_version();
        
        if (!$remote_info) {
            return;
        }
        
        $plugin_name = get_plugin_data($this->plugin_file)['Name'];
        $update_url = wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($this->plugin_slug)), 'upgrade-plugin_' . $this->plugin_slug);
        
        echo '<div class="notice notice-warning is-dismissible">
                <p><strong>' . esc_html($plugin_name) . '</strong></p>
                <p>Version <strong>' . esc_html($remote_info['version']) . '</strong> is available. 
                   <a href="' . esc_url($update_url) . '" class="button-primary">Update Now</a> 
                   <a href="' . esc_url(admin_url('plugins.php')) . '">View Details</a>
                </p>
              </div>';
    }
    
    /**
     * Force check for updates (for testing)
     */
    public function force_update_check() {
        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }
    
    /**
     * Get current plugin version
     */
    public function get_current_version() {
        return $this->version;
    }
    
    /**
     * Check if update is available
     */
    public function is_update_available() {
        $remote_info = $this->get_remote_version();
        return $remote_info && version_compare($this->version, $remote_info['version'], '<');
    }
    
    /**
     * Show update row in plugins page
     */
    public function show_update_row($plugin_file, $plugin_data) {
        if (!current_user_can('update_plugins')) {
            return;
        }
        
        $remote_info = $this->get_remote_version();
        
        if (!$remote_info || !version_compare($this->version, $remote_info['version'], '<')) {
            return;
        }
        
        $wp_list_table = _get_list_table('WP_Plugins_List_Table');
        $update_url = wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($this->plugin_slug)), 'upgrade-plugin_' . $this->plugin_slug);
        $details_url = self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . dirname($this->plugin_slug) . '&TB_iframe=true&width=600&height=550');
        
        echo '<tr class="plugin-update-tr jio-pay-update-row" id="' . esc_attr($this->plugin_slug . '-update') . '" data-slug="' . esc_attr(dirname($this->plugin_slug)) . '" data-plugin="' . esc_attr($this->plugin_slug) . '">';
        echo '<td colspan="' . esc_attr($wp_list_table->get_column_count()) . '" class="plugin-update colspanchange">';
        echo '<div class="update-message notice inline notice-warning notice-alt">';
        echo '<p>';
        
        printf(
            __('There is a new version of %1$s available. %2$s or %3$s.', 'woo-jiopay'),
            '<strong>' . esc_html($plugin_data['Name']) . '</strong>',
            '<a href="' . esc_url($details_url) . '" class="thickbox open-plugin-details-modal" aria-label="' . esc_attr(sprintf(__('View %s version %s details'), $plugin_data['Name'], $remote_info['version'])) . '">' . sprintf(__('View version %s details'), $remote_info['version']) . '</a>',
            '<a href="' . esc_url($update_url) . '" class="update-link" aria-label="' . esc_attr(sprintf(__('Update %s now'), $plugin_data['Name'])) . '">' . __('Update now') . '</a>'
        );
        
        echo '</p></div></td></tr>';
        
        // Add custom styling
        echo '<style>
            .jio-pay-update-row .update-message {
                background-color: #fff3cd;
                border-left-color: #856404;
            }
            .jio-pay-update-row .update-message p {
                margin: 0.5em 0;
            }
        </style>';
    }
    
    /**
     * Add plugin action links
     */
    public function plugin_action_links($links) {
        $remote_info = $this->get_remote_version();
        
        if ($remote_info && version_compare($this->version, $remote_info['version'], '<')) {
            $update_url = wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($this->plugin_slug)), 'upgrade-plugin_' . $this->plugin_slug);
            $update_link = '<a href="' . esc_url($update_url) . '" style="color: #d63638; font-weight: bold;">' . __('Update Available', 'woo-jiopay') . '</a>';
            array_unshift($links, $update_link);
        }
        
        // Add settings link
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=woo-jiopay')) . '">' . __('Settings', 'woo-jiopay') . '</a>';
        array_unshift($links, $settings_link);
        
        return $links;
    }
}