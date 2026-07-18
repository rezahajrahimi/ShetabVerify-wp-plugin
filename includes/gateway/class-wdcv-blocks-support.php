<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
    return;
}

/**
 * Class WebDide_CV_Blocks_Support
 * Provides WooCommerce Blocks support for WebDide_CV payment gateway.
 */
class WebDide_CV_Blocks_Support extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

    /**
     * The gateway instance.
     *
     * @var WC_Gateway_WDCV
     */
    private $gateway;

    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = 'wdcv';

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_wdcv_settings', array() );
        $this->gateway  = new WC_Gateway_WDCV();
        set_transient( 'wdcv_blocks_initialized', current_time( 'mysql' ), 5 * MINUTE_IN_SECONDS );
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of script handles to enqueue for this payment method in
     * the frontend context
     *
     * @return string[]
     */
    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc-WebDide_CV-blocks-integration',
            plugins_url( 'public/js/blocks.js', WDCV_PLUGIN_FILE ),
            array( 'wp-element', 'wp-html-entities', 'wc-blocks-registry', 'wc-settings' ),
            '1.0.0',
            true
        );
        return array( 'wc-WebDide_CV-blocks-integration' );
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return array(
            'title'       => $this->gateway->title,
            'description' => $this->gateway->description,
            'supports'    => $this->gateway->supports,
            'logo_url'    => $this->gateway->icon,
        );
    }
}

if ( ! class_exists( 'WC_WebDide_CV_Blocks_Support' ) ) {
    class_alias( 'WebDide_CV_Blocks_Support', 'WC_WebDide_CV_Blocks_Support' );
}








