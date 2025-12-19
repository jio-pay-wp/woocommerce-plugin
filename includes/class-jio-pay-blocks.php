<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_Jio_Pay_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'jio_pay';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_jio_pay_settings', [] );
    }

    public function is_active() {
        return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        $asset_path = plugin_dir_path( __DIR__ ) . 'assets/jio-pay-blocks.asset.php';
        $asset_file = file_exists( $asset_path ) ? include $asset_path : array(
            'dependencies' => array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-i18n'
            ),
            'version'      => '1.0.0'
        );
        
        wp_register_script(
            'jio-pay-blocks',
            plugin_dir_url( __DIR__ ) . 'assets/jio-pay-blocks.js',
            $asset_file['dependencies'],
            $asset_file['version'],
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'jio-pay-blocks', 'woo-jiopay', plugin_dir_path( __DIR__ ) . 'languages' );
        }

        return [ 'jio-pay-blocks' ];
    }

    public function get_payment_method_data() {
        $data = [
            'title'       => $this->get_setting( 'title', __( 'Jio Pay Gateway', 'woo-jiopay' ) ),
            'description' => $this->get_setting( 'description', __( 'Pay with Jio Pay - Simple, Fast & Secure', 'woo-jiopay' ) ),
            'supports'    => array( 'products' ),
            'logo_url'    => '', // Add logo URL if you have one
        ];
        
        // Debug log
        error_log('Jio Pay Blocks - Payment Method Data: ' . print_r($data, true));
        
        return $data;
    }
}