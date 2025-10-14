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
        $asset_file = include plugin_dir_path( __DIR__ ) . 'assets/jio-pay-blocks.asset.php';
        
        wp_register_script(
            'jio-pay-blocks',
            plugin_dir_url( __DIR__ ) . 'assets/jio-pay-blocks.js',
            $asset_file['dependencies'],
            $asset_file['version'],
            true
        );

        return [ 'jio-pay-blocks' ];
    }

    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'supports'    => array_filter( ['products'] )
        ];
    }
}