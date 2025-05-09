<?php
/**
 * PayPal Standard Proxy Gateway
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WPPPC_PayPal_Standard_Proxy_Gateway extends WC_Payment_Gateway {
    
    public function __construct() {
        $this->id = 'paypal_standard_proxy';
        $this->icon = apply_filters('woocommerce_paypal_icon', WPPPC_PLUGIN_URL . 'assets/images/paypal.svg');
        $this->has_fields = false;
        $this->method_title = __('PayPal Standard (Proxy)', 'woo-paypal-proxy-client');
        $this->method_description = __('Accept PayPal payments using PayPal Standard through proxy.', 'woo-paypal-proxy-client');
        
        // Load settings from main gateway
        $proxy_settings = get_option('woocommerce_paypal_proxy_settings', array());
        
        // Define properties
        $this->title = isset($proxy_settings['title']) ? $proxy_settings['title'] : __('PayPal', 'woo-paypal-proxy-client');
        $this->description = isset($proxy_settings['description']) ? $proxy_settings['description'] : __('Pay via PayPal.', 'woo-paypal-proxy-client');
        $this->enabled = 'yes';
        
        // Add support for WooCommerce subscriptions if present
        $this->supports = array(
            'products'
        );
    }
    
    /**
     * Process payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        // Return success - redirect happens in the session
        $redirect_url = WC()->session->get('wpppc_standard_redirect');
        
        if (!$redirect_url) {
            // Fallback to order received page
            $redirect_url = $order->get_checkout_order_received_url();
        }
        
        return array(
            'result' => 'success',
            'redirect' => $redirect_url
        );
    }
}