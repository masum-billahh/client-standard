<?php
/**
 * PayPal Standard Proxy Client
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WPPPC_Standard_Proxy_Client {
    
    public function __construct() {
        // Hook into WooCommerce checkout process
        add_action('woocommerce_checkout_process', array($this, 'checkout_process'));
        add_action('woocommerce_checkout_order_processed', array($this, 'checkout_order_processed'), 10, 3);
        
        // Register handlers for return and IPN
        add_action('woocommerce_api_wpppc-standard-return', array($this, 'handle_return'));
        add_action('woocommerce_api_wpppc-standard-cancel', array($this, 'handle_cancel'));
        add_action('woocommerce_api_wpppc-standard-ipn', array($this, 'handle_ipn'));
        
        // Alter available payment gateways based on settings
        add_filter('woocommerce_available_payment_gateways', array($this, 'manage_payment_gateways'));
		
		 // ppl custom button handling
		add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_scripts'));
    	add_action('woocommerce_review_order_before_submit', array($this, 'add_paypal_button_html'));
    }
    
    
    
   public function manage_payment_gateways($gateways) {
    // Get our proxy gateway
    $proxy_gateway = WC()->payment_gateways->payment_gateways()['paypal_proxy'] ?? null;
    
    if (!$proxy_gateway) {
        return $gateways;
    }
    
    // Get the selected server to check mode
    $server_manager = WPPPC_Server_Manager::get_instance();
    $server = $server_manager->get_selected_server();
    
    if (!$server) {
        $server = $server_manager->get_next_available_server();
    }
    
    if (!$server) {
        return $gateways;
    }
    
   // Check if this server is set to Personal mode
    $use_standard_mode = !empty($server->is_personal);
    
    // Check mobile_only setting
    $mobile_only = $proxy_gateway->get_option('mobile_only') === 'yes';
    
    // If mobile_only is enabled, check if we're on a mobile device
    if ($mobile_only && method_exists($proxy_gateway, 'is_real_mobile_device')) {
        $is_mobile = $proxy_gateway->is_real_mobile_device();
        
        // If mobile_only is enabled and this is NOT a mobile device, 
        // don't show either gateway
        if (!$is_mobile) {
            if (isset($gateways['paypal_proxy'])) {
                unset($gateways['paypal_proxy']);
            }
            if (isset($gateways['paypal_standard_proxy'])) {
                unset($gateways['paypal_standard_proxy']);
            }
            wpppc_log("PayPal gateways hidden - mobile_only enabled but not on mobile device");
            return $gateways;
        }
    }
    
 if ($use_standard_mode) {
        // Preserve the position of the original gateway
        // Find position of paypal_proxy in the gateways array
        $gateway_ids = array_keys($gateways);
        $proxy_position = array_search('paypal_proxy', $gateway_ids);
        
        // Create our standard gateway
        $standard_gateway = new WPPPC_PayPal_Standard_Proxy_Gateway();
        
        if ($proxy_position !== false) {
            // Remove original gateway
            unset($gateways['paypal_proxy']);
            
            // Insert standard gateway at the same position
            $new_gateways = array();
            $i = 0;
            
            foreach ($gateway_ids as $gateway_id) {
                if ($i === $proxy_position) {
                    $new_gateways['paypal_standard_proxy'] = $standard_gateway;
                }
                
                if ($gateway_id !== 'paypal_proxy') {
                    $new_gateways[$gateway_id] = $gateways[$gateway_id];
                }
                
                $i++;
            }
            
            // If the PayPal gateway was last, add standard gateway at the end
            if ($proxy_position === count($gateway_ids) - 1) {
                $new_gateways['paypal_standard_proxy'] = $standard_gateway;
            }
            
            wpppc_log("Using PayPal Standard mode - preserved position of original gateway");
            return $new_gateways;
        } else {
            // Original gateway not found, just add standard gateway
            $gateways['paypal_standard_proxy'] = $standard_gateway;
            //wpppc_log("Using PayPal Standard mode - original gateway not found");
        }
        
        // Remove regular proxy gateway if it exists
        if (isset($gateways['paypal_proxy'])) {
            unset($gateways['paypal_proxy']);
        }
    } else {
        //wpppc_log("Using PayPal Business mode because server ID {$server->id} is set to Business mode");
    }
    
    return $gateways;
}
    
	
	/**
 * Enqueue scripts and styles for checkout page
 */
public function enqueue_checkout_scripts() {
    if (is_checkout()) {
        wp_enqueue_script(
            'wpppc-checkout-custom', 
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/standard-img-handle.js', 
            array('jquery'), 
            '4.2.0', 
            true
        );
        
        wp_enqueue_style(
            'wpppc-checkout-custom', 
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/standard.css', 
            array(), 
            time()
        );
        
        // Pass data to JavaScript
        wp_localize_script('wpppc-checkout-custom', 'wpppc_checkout', array(
            'paypal_button_url' => plugin_dir_url(dirname(__FILE__)) . 'assets/images/ppl-button-standard.png',
            'gateway_id' => 'paypal_standard_proxy'
        ));
    }
}

/**
 * Add hidden PayPal button HTML
 */
public function add_paypal_button_html() {
    ?>
    <div id="wpppc-paypal-button" style="display: none;">
        <img src="<?php echo WPPPC_PLUGIN_URL; ?>assets/images/paypal.svg" 
             alt="Pay with PayPal" 
             style="width: 100%; cursor: pointer;" 
             id="wpppc-paypal-btn-img" />
    </div>
    <?php
}
    /**
     * Process checkout for standard mode
     */
    public function checkout_process() {
        // Only handle PayPal Standard Proxy
        if (!isset($_POST['payment_method']) || $_POST['payment_method'] !== 'paypal_standard_proxy') {
            return;
        }
        
        // Validation happens in WooCommerce core
    }
    
  /**
 * Process order after checkout for standard mode redirect
 */
public function checkout_order_processed($order_id, $posted_data, $order) {
    // Only process for our gateway
    if ($order->get_payment_method() !== 'paypal_standard_proxy') {
        return;
    }
    
    // Get server from server manager
    $server_manager = WPPPC_Server_Manager::get_instance();
    $server = $server_manager->get_selected_server();
    
    if (!$server) {
        $server = $server_manager->get_next_available_server();
    }
    
    if (!$server) {
        wc_add_notice(__('No PayPal server available. Please try again or contact support.', 'woo-paypal-proxy-client'), 'error');
        return;
    }
    
    // Store server ID in order
    update_post_meta($order_id, '_wpppc_server_id', $server->id);
    
    update_post_meta($order->get_id(), '_wpppc_funding_source', 'Standard');
    
       
    
    // Generate security token
    $token = wp_create_nonce('wpppc-standard-order-' . $order_id);
    update_post_meta($order_id, '_wpppc_standard_token', $token);
    
    // Generate session ID for tracking
    $session_id = $this->store_paypal_session($order_id);
    update_post_meta($order_id, '_wpppc_session_id', $session_id);
    
    // Prepare order items for PayPal
    $items = array();
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $items[] = array(
            'name' => $item->get_name(),
            'price' => $order->get_item_subtotal($item, false),
            'quantity' => $item->get_quantity(),
            'product_id' => $product ? $product->get_id() : 0
        );
    }
    
     // Check if this is a personal server
        $is_personal = $server->is_personal ?? 0;
        
        // If this is a personal server, use product mapping for line items
        if ($is_personal) {
            // Apply product mapping to line items
            if (function_exists('add_product_mappings_to_items')) {
                $items = add_product_mappings_to_items($items, $server->id);
                wpppc_log("Standard Checkout: Product mapping applied to line items for personal server");
            }
        }
    
    // Build redirect URL
    $redirect_url = $server->url . '/wp-json/wppps/v1/standard-bridge';
    
  

// Get billing/shipping address from order
$billing_address = array(
    'first_name' => $order->get_billing_first_name(),
    'last_name'  => $order->get_billing_last_name(),
    'company'    => $order->get_billing_company(),
    'address_1'  => $order->get_billing_address_1(),
    'address_2'  => $order->get_billing_address_2(),
    'city'       => $order->get_billing_city(),
    'state'      => $order->get_billing_state(),
    'postcode'   => $order->get_billing_postcode(),
    'country'    => $order->get_billing_country(),
    'email'      => $order->get_billing_email(),
    'phone'      => $order->get_billing_phone()
);

// Check if shipping is different from billing
$ship_to_different_address = !empty($_POST['ship_to_different_address']);
if ($ship_to_different_address) {
    $shipping_address = array(
        'first_name' => $order->get_shipping_first_name(),
        'last_name'  => $order->get_shipping_last_name(),
        'company'    => $order->get_shipping_company(),
        'address_1'  => $order->get_shipping_address_1(),
        'address_2'  => $order->get_shipping_address_2(),
        'city'       => $order->get_shipping_city(),
        'state'      => $order->get_shipping_state(),
        'postcode'   => $order->get_shipping_postcode(),
        'country'    => $order->get_shipping_country()
    );
} else {
    // Billing and shipping are the same
    $shipping_address = $billing_address;
}

// Add addresses to params
$params = array(
    'order_id' => $order_id,
    'order_key' => $order->get_order_key(),
    //'return_url' => $order->get_checkout_order_received_url(),
    'checkout_page_url' => wc_get_checkout_url(),
    'token' => $token,
    'api_key' => $server->api_key,
    'currency' => $order->get_currency(),
    'amount' => $order->get_total(),
    'shipping' => $order->get_shipping_total(),
    'tax' => $order->get_total_tax(),
    'discount_total' => $order->get_discount_total(),
    'items' => base64_encode(json_encode($items)),
    'session_id' => $session_id,
    'address_override' => '1', // Tell PayPal to use our address
    'shipping_address' => base64_encode(json_encode($shipping_address)),
    'billing_address' => base64_encode(json_encode($billing_address))
);
    
    $redirect_url = add_query_arg($params, $redirect_url);
    
    // Store the URL in session for WC to use
    WC()->session->set('wpppc_standard_redirect', $redirect_url);
}
    
 /**
 * Handle return from PayPal via server
 */
public function handle_return() {
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    $order_key = isset($_GET['order_key']) ? sanitize_text_field($_GET['order_key']) : '';
    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $timestamp = isset($_GET['timestamp']) ? intval($_GET['timestamp']) : 0;
    $hash = isset($_GET['hash']) ? sanitize_text_field($_GET['hash']) : '';
    
    // Validate basic data
    if (!$order_id || !$order_key || !$timestamp || !$hash) {
        wc_add_notice(__('Invalid return data', 'woo-paypal-proxy-client'), 'error');
        wp_redirect(wc_get_checkout_url());
        exit;
    }
    
    // Get order
    $order = wc_get_order($order_id);
    if (!$order || $order->get_order_key() !== $order_key) {
        wc_add_notice(__('Invalid order', 'woo-paypal-proxy-client'), 'error');
        wp_redirect(wc_get_checkout_url());
        exit;
    }
    
    // Get server data
    $server_id = get_post_meta($order_id, '_wpppc_server_id', true);
    $server_manager = WPPPC_Server_Manager::get_instance();
    $server = $server_manager->get_server($server_id);
    
    if (!$server) {
        wc_add_notice(__('Invalid server configuration', 'woo-paypal-proxy-client'), 'error');
        wp_redirect(wc_get_checkout_url());
        exit;
    }
    
    // Validate hash
    $expected_hash = hash_hmac('sha256', $timestamp . $order_id . $server->api_key, $server->api_secret);
    if (!hash_equals($expected_hash, $hash)) {
        wc_add_notice(__('Security validation failed', 'woo-paypal-proxy-client'), 'error');
        wp_redirect(wc_get_checkout_url());
        exit;
    }
    
   // Process based on status
    if ($status === 'success') {
        // IMPROVED: Mark as processing temporarily, don't wait for IPN
        // This avoids showing "pay now" buttons to the customer
        $order->update_status('processing', __('Customer returned from PayPal.', 'woo-paypal-proxy-client'));
        
        // Get the order total amount
        $order_amount = floatval($order->get_total());
        error_log('PayPal Proxy - Order amount to add to usage for personal: ' . $order_amount);
        
        // Get server manager instance
        require_once WPPPC_PLUGIN_DIR . 'includes/class-server-manager.php';
        $server_manager = WPPPC_Server_Manager::get_instance();
        
        // Update usage with order amount
        $result = $server_manager->add_server_usage($server_id, $order_amount);
        
        // Add a flag indicating we're waiting for IPN
        update_post_meta($order_id, '_wpppc_awaiting_ipn', 'yes');
        
        // Empty cart
        WC()->cart->empty_cart();
        
        // Redirect to thank you page
        wp_redirect($order->get_checkout_order_received_url());
    } else {
        // Redirect to checkout with message
        wc_add_notice(__('Your PayPal payment was not completed. Please try again.', 'woo-paypal-proxy-client'), 'error');
        wp_redirect(wc_get_checkout_url());
    }
    
    exit;
}
    
    /**
     * Handle cancellation from PayPal via server
     */
    public function handle_cancel() {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $order_key = isset($_GET['order_key']) ? sanitize_text_field($_GET['order_key']) : '';
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $timestamp = isset($_GET['timestamp']) ? intval($_GET['timestamp']) : 0;
        $hash = isset($_GET['hash']) ? sanitize_text_field($_GET['hash']) : '';
        
        // Validate basic data
        if (!$order_id || !$order_key || !$timestamp || !$hash) {
            wc_add_notice(__('Invalid return data', 'woo-paypal-proxy-client'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }
        
        // Get order
        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $order_key) {
            wc_add_notice(__('Invalid order', 'woo-paypal-proxy-client'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }
        
        // Get server data
        $server_id = get_post_meta($order_id, '_wpppc_server_id', true);
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_server($server_id);
        
        if (!$server) {
            wc_add_notice(__('Invalid server configuration', 'woo-paypal-proxy-client'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }
        
        // Validate hash
        $expected_hash = hash_hmac('sha256', $timestamp . $order_id . $server->api_key, $server->api_secret);
        if (!hash_equals($expected_hash, $hash)) {
            wc_add_notice(__('Security validation failed', 'woo-paypal-proxy-client'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }
        
        // Update order status to cancelled
        $order->update_status('cancelled', __('Payment cancelled by customer', 'woo-paypal-proxy-client'));
        
        // Add notice for customer
        wc_add_notice(__('Your PayPal payment was cancelled. Please try again.', 'woo-paypal-proxy-client'), 'notice');
        
        // Redirect to checkout
        wp_redirect(wc_get_checkout_url());
        exit;
    }
    
    /**
     * Handle IPN notification from server
     */
    public function handle_ipn() {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $transaction_id = isset($_GET['transaction_id']) ? sanitize_text_field($_GET['transaction_id']) : '';
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $timestamp = isset($_GET['timestamp']) ? intval($_GET['timestamp']) : 0;
        $hash = isset($_GET['hash']) ? sanitize_text_field($_GET['hash']) : '';
        
        // Validate basic data
        if (!$order_id || !$timestamp || !$hash) {
            exit('Invalid IPN data');
        }
        
        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            exit('Invalid order');
        }
        
        // Get server data
        $server_id = get_post_meta($order_id, '_wpppc_server_id', true);
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_server($server_id);
        
        if (!$server) {
            exit('Invalid server configuration');
        }
        
        // Validate hash
        $expected_hash = hash_hmac('sha256', $timestamp . $order_id . $transaction_id . $server->api_key, $server->api_secret);
        if (!hash_equals($expected_hash, $hash)) {
            exit('Security validation failed');
        }
        
         // Process based on status
    if ($status === 'completed') {
        // Mark payment complete
        $order->payment_complete($transaction_id);
         // Add seller protection if provided
        $seller_protection = isset($_GET['seller_protection']) ? sanitize_text_field($_GET['seller_protection']) : 'UNKNOWN';
        update_post_meta($order_id, '_paypal_seller_protection', $seller_protection);
        
        $account_status = isset($_GET['account_status']) ? sanitize_text_field($_GET['account_status']) : 'UNKNOWN';
        update_post_meta($order_id, '_paypal_account_status', $account_status);
        
         // Add order note
    $order->add_order_note(
        sprintf(__('Payment completed via PayPal Standard. Transaction ID: %s, Seller Protection: %s', 'woo-paypal-proxy-client'), 
        $transaction_id, $seller_protection)
    );
        
        // IMPORTANT: Clear the awaiting IPN flag
        delete_post_meta($order_id, '_wpppc_awaiting_ipn');
    }
    
    exit('IPN processed');
}
    
/**
 * Store PayPal session data
 */
private function store_paypal_session($order_id) {
    // Generate a unique session ID
    $session_id = wp_generate_password(32, false);
    
    // Store in WP option for debugging
    update_option('wpppc_last_session_id', $session_id);
    update_option('wpppc_last_session_order', $order_id);
    
    // Store in transient for 1 hour - CRITICAL: use the exact format the return handler expects
    $transient_name = 'wpppc_paypal_session_' . $session_id;
    set_transient($transient_name, $order_id, HOUR_IN_SECONDS);
    
    error_log('PayPal Session: Created session ' . $session_id . ' for order ' . $order_id);
    error_log('PayPal Session: Transient name: ' . $transient_name);
    
    return $session_id;
}

}