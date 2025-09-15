<?php
/**
 * Advanced Credit Card Gateway for WooCommerce
 * Uses iframe with PayPal CardFields only
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WPPPC_Advanced_Card_Gateway extends WC_Payment_Gateway {
    
    private $api_handler;
    
    public function __construct() {
        $this->id                 = 'paypal_advanced_card';
        $this->icon               = apply_filters('woocommerce_paypal_advanced_card_icon', WPPPC_PLUGIN_URL . 'assets/images/paypal.svg');
        $this->has_fields         = true;
        $this->method_title       = __('Credit Card (PayPal)', 'woo-paypal-proxy-client');
        $this->method_description = __('Accept credit card payments securely through PayPal CardFields.', 'woo-paypal-proxy-client');
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define properties
        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->enabled      = $this->get_option('enabled');
        
        // Initialize API handler
        $this->api_handler = new WPPPC_API_Handler();
        
        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        
     
    }
    
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'woo-paypal-proxy-client'),
                'type'        => 'checkbox',
                'label'       => __('Enable Advanced Credit Card', 'woo-paypal-proxy-client'),
                'default'     => 'yes'
            ),
            'title' => array(
                'title'       => __('Title', 'woo-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woo-paypal-proxy-client'),
                'default'     => __('Credit/Debit Card(Direct)', 'woo-paypal-proxy-client'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'woo-paypal-proxy-client'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woo-paypal-proxy-client'),
                'default'     => __('Pay securely with your credit/debit card.', 'woo-paypal-proxy-client'),
                'desc_tip'    => true,
            ),
        );
    }
    
    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        
        // Load CardFields iframe
        $iframe_url = $this->api_handler->generate_card_iframe_url();
        
        ?>
        <div class="wpppc-card-fields-container" style="min-height:200px;">
            <div class="wpppc-iframe-wrapper">
                <iframe 
                    id="paypal-card-iframe" 
                    src="<?php echo esc_url($iframe_url); ?>" 
                    frameborder="0" 
                    allowtransparency="true"
                    scrolling="no"
                    sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-same-origin"
                    style="width: 100%; height: 310px !important; border: none; overflow: hidden;"
                ></iframe>
            </div>
            <div id="wpppc-card-message" class="wpppc-message" style="display: none;"></div>
            <div id="wpppc-card-error" class="wpppc-error" style="display: none;"></div>
        </div>
        <?php
    }
    
    public function process_payment($order_id) {
        // This method will be called when the order is created
        // The actual payment processing happens via AJAX
        
        $order = wc_get_order($order_id);
        
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }
    
    /**
 * AJAX handler for creating order AFTER PayPal payment succeeds
 */
public function ajax_create_order_after_payment() {
    check_ajax_referer('wpppc-card-nonce', 'nonce');
    
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    $transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field($_POST['transaction_id']) : '';
    
    if (empty($paypal_order_id)) {
        wp_send_json_error(array(
            'message' => 'Invalid PayPal order ID'
        ));
        wp_die();
    }
    
    try {
        // Set payment method for the order creation
        $_POST['payment_method'] = 'paypal_advanced_card';
        
        // Create WooCommerce order using existing checkout process
        $checkout = WC()->checkout();
        
        // Process checkout and create order
        $order_id = $checkout->create_order($_POST);
        
        if (is_wp_error($order_id)) {
            throw new Exception($order_id->get_error_message());
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            throw new Exception('Failed to create order');
        }
        
        // Complete the payment immediately since PayPal already processed it
        $order->payment_complete($transaction_id);
        
        // Add order notes
        $order->add_order_note(
            sprintf(__('PayPal payment completed. PayPal Order ID: %s, Transaction ID: %s', 'woo-paypal-proxy-client'),
                $paypal_order_id,
                $transaction_id
            )
        );
        
        // Store PayPal transaction details
        update_post_meta($order_id, '_paypal_order_id', $paypal_order_id);
        update_post_meta($order_id, '_paypal_transaction_id', $transaction_id);
        
        // Update status to processing
        $order->update_status('processing');
        
        // Empty cart
        WC()->cart->empty_cart();
        
        // Return success with redirect URL
        wp_send_json_success(array(
            'order_id' => $order_id,
            'redirect' => $order->get_checkout_order_received_url()
        ));
        
    } catch (Exception $e) {
        error_log('PayPal Card - Error creating order after payment: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Error creating order: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}
    
    // AJAX validation handler
    public function ajax_validate_checkout() {
        check_ajax_referer('wpppc-card-nonce', 'nonce');
        
        // Reuse existing validation logic
        $gateway = new WPPPC_PayPal_Gateway();
        $gateway->ajax_validate_checkout();
    }
    
    // AJAX order creation handler
    public function ajax_create_order() {
        check_ajax_referer('wpppc-card-nonce', 'nonce');
        
        // Reuse existing order creation but mark as card payment
        $_POST['payment_method'] = 'paypal_advanced_card';
        
        // Call existing order creation handler
        wpppc_create_order_handler();
    }
    
    // AJAX completion handler
    public function ajax_complete_order() {
        check_ajax_referer('wpppc-card-nonce', 'nonce');
        
        // Reuse existing completion logic
        wpppc_complete_order_handler();
    }
}