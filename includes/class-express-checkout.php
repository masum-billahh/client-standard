<?php
/**
 * PayPal Express Checkout functionality for Website A (Client)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PayPal Express Checkout Class
 */
class WPPPC_Express_Checkout {
    
    private static $buttons_added_to_cart = false;
    private static $buttons_added_to_checkout = false;
    private $current_order_id = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add PayPal buttons to checkout page before customer details
        add_action('woocommerce_before_checkout_form', array($this, 'add_express_checkout_button_to_checkout'), 10);
        
        // Add scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handler for creating PayPal order for express checkout
        add_action('wp_ajax_wpppc_create_express_order', array($this, 'ajax_create_express_order'));
        add_action('wp_ajax_nopriv_wpppc_create_express_order', array($this, 'ajax_create_express_order'));
        
        // AJAX handler for completing express order
        add_action('wp_ajax_wpppc_complete_express_order', array($this, 'ajax_complete_express_order'));
        add_action('wp_ajax_nopriv_wpppc_complete_express_order', array($this, 'ajax_complete_express_order'));
        
        // AJAX handler for fetching PayPal order details
        add_action('wp_ajax_wpppc_fetch_paypal_order_details', array($this, 'ajax_fetch_paypal_order_details'));
        add_action('wp_ajax_nopriv_wpppc_fetch_paypal_order_details', array($this, 'ajax_fetch_paypal_order_details'));
    }
    
    /**
     * Check if PayPal gateway is enabled
     */
    private function is_gateway_enabled() {
        // Get the PayPal gateway settings
        $gateway_settings = get_option('woocommerce_paypal_proxy_settings', array());
        
        // Check if enabled
        return isset($gateway_settings['enabled']) && $gateway_settings['enabled'] === 'yes';
    }
    
    /**
     * Add Express Checkout button to checkout page
     */
    public function add_express_checkout_button_to_checkout() {
        // Check if gateway is enabled - EXIT if disabled
        if (!$this->is_gateway_enabled()) {
            return;
        }
        
        if (self::$buttons_added_to_checkout) {
            return;
        }
        self::$buttons_added_to_checkout = true;
        
        // Only show if we have a server
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        if (!$server) {
            wpppc_log("Express Checkout: No PayPal server available for checkout buttons");
            return;
        }
        
        echo '<div class="wpppc-express-checkout-container">';
        echo '<h3>' . __('Express Checkout', 'woo-paypal-proxy-client') . '</h3>';
        echo '<p>' . __('Check out faster with PayPal', 'woo-paypal-proxy-client') . '</p>';
        echo '<div id="wpppc-express-paypal-button-checkout" class="wpppc-express-paypal-button"></div>';
        echo '</div>';
        echo '<div class="wpppc-express-separator"><span>' . __('OR', 'woo-paypal-proxy-client') . '</span></div>';
    }
    
    /**
     * Enqueue scripts and styles for Express Checkout
     */
    public function enqueue_scripts() {
        // Check if gateway is enabled - EXIT if disabled
        if (!$this->is_gateway_enabled()) {
            return;
        }
        
        if (!is_cart() && !is_checkout()) {
            return;
        }
        
        // Enqueue custom express checkout styles
        wp_enqueue_style('wpppc-express-checkout', WPPPC_PLUGIN_URL . 'assets/css/express-checkout.css', array(), WPPPC_VERSION);
        
        // Enqueue custom script for Express Checkout
        wp_enqueue_script('wpppc-express-checkout', WPPPC_PLUGIN_URL . 'assets/js/express-checkout.js', array('jquery'), WPPPC_VERSION, true);
        
        // Get server for button URL
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        if (!$server) {
            return;
        }
        
        // Get API handler with server
        $api_handler = new WPPPC_API_Handler();
        
        // Create base button iframe URL (will be updated with current totals when clicked)
        $iframe_url = $api_handler->generate_express_iframe_url();
        
        // Pass data to JavaScript
        wp_localize_script('wpppc-express-checkout', 'wpppc_express_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpppc-express-nonce'),
            'iframe_url' => $iframe_url,
            'cart_total' => WC()->cart->get_total(''),
            'currency' => get_woocommerce_currency(),
            'shipping_required' => WC()->cart->needs_shipping(),
            'is_checkout_page' => is_checkout(),
            'is_cart_page' => is_cart(),
            'debug_mode' => true
        ));
    }
    
/**
 * AJAX handler for creating a PayPal order for Express Checkout
 */
public function ajax_create_express_order() {
    check_ajax_referer('wpppc-express-nonce', 'nonce');
    
    wpppc_log("Express Checkout: Creating order via AJAX");
    
    try {
        // Get current checkout totals
        $current_totals = isset($_POST['current_totals']) ? $_POST['current_totals'] : array();
        
        // Create temporary order
        $order = wc_create_order();
        
        // Mark as express checkout
        $order->add_meta_data('_wpppc_express_checkout', 'yes');
        
        // STORE the checkout totals FIRST
        if (!empty($current_totals)) {
            update_post_meta($order->get_id(), '_express_checkout_totals', $current_totals);
        }
        
// Add cart items to order
foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
    $product = $cart_item['data'];
    $variation_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;
    
    // Add line item
    $item = new WC_Order_Item_Product();
    $item->set_props(array(
        'product_id'   => $product->get_id(),
        'variation_id' => $variation_id,
        'quantity'     => $cart_item['quantity'],
        'subtotal'     => $cart_item['line_subtotal'],
        'total'        => $cart_item['line_total'],
        'subtotal_tax' => $cart_item['line_subtotal_tax'],
        'total_tax'    => $cart_item['line_tax'],
        'taxes'        => $cart_item['line_tax_data']
    ));
    
    $item->set_name($product->get_name());
    
    // Add variation data
    if (!empty($cart_item['variation'])) {
        foreach ($cart_item['variation'] as $meta_name => $meta_value) {
            $item->add_meta_data(str_replace('attribute_', '', $meta_name), $meta_value);
        }
    }
    
    // CRITICAL: Run the WooCommerce hook that WAPF and other plugins use
    do_action('woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $cart_item, $order);
    
    $order->add_item($item);
}

// Run the hook that WAPF might use after all items are added
do_action('woocommerce_checkout_create_order', $order, array());

// Trigger the order meta hook that some plugins use
do_action('woocommerce_checkout_update_order_meta', $order->get_id(), array());
        
        // Add fees
        foreach (WC()->cart->get_fees() as $fee) {
            $fee_item = new WC_Order_Item_Fee();
            $fee_item->set_props(array(
                'name'      => $fee->name,
                'tax_class' => $fee->tax_class,
                'total'     => $fee->amount,
                'total_tax' => $fee->tax,
                'taxes'     => array(
                    'total' => $fee->tax_data,
                ),
            ));
            $order->add_item($fee_item);
        }
        
        // Add coupons
        foreach (WC()->cart->get_coupons() as $code => $coupon) {
            $coupon_item = new WC_Order_Item_Coupon();
            $coupon_item->set_props(array(
                'code'         => $code,
                'discount'     => WC()->cart->get_coupon_discount_amount($code),
                'discount_tax' => WC()->cart->get_coupon_discount_tax_amount($code),
            ));
            
            if (method_exists($coupon_item, 'add_meta_data')) {
                $coupon_item->add_meta_data('coupon_data', $coupon->get_data());
            }
            
            $order->add_item($coupon_item);
        }
        
// Set payment method
$order->set_payment_method('paypal_proxy');

// Add shipping method
if (!empty($current_totals['shipping_method'])) {
    $shipping_method_id = $current_totals['shipping_method'];
    $shipping_method_found = false;
    
    // Get actual shipping method details
    foreach (WC()->shipping->get_packages() as $package_key => $package) {
        if (isset($package['rates'][$shipping_method_id])) {
            $shipping_rate = $package['rates'][$shipping_method_id];
            
            $item = new WC_Order_Item_Shipping();
            $item->set_props(array(
                'method_title' => $shipping_rate->get_label(),
                'method_id'    => $shipping_rate->get_method_id(),
                'instance_id'  => $shipping_rate->get_instance_id(),
                'total'        => $current_totals['shipping'],
                'taxes'        => array(),
            ));
            
            foreach ($shipping_rate->get_meta_data() as $key => $value) {
                $item->add_meta_data($key, $value, true);
            }
            
            $order->add_item($item);
            $shipping_method_found = true;
            
            wpppc_log("Express Checkout: Added shipping method: " . $shipping_rate->get_label());
            break;
        }
    }
    
    // Fallback if method not found
    if (!$shipping_method_found) {
        // Try to get method name from WooCommerce shipping methods
        $all_methods = WC()->shipping->get_shipping_methods();
        if (isset($all_methods['flat_rate'])) {
            $method_title = $all_methods['flat_rate']->get_method_title();
        } else {
            $method_title = 'Flat Rate Shipping';
        }
        
        $item = new WC_Order_Item_Shipping();
        $item->set_props(array(
            'method_title' => $method_title,
            'method_id'    => 'flat_rate',
            'total'        => $current_totals['shipping'],
            'taxes'        => array(),
        ));
        $order->add_item($item);
        
        wpppc_log("Express Checkout: Added fallback shipping method: " . $method_title);
    }
}

// Set initial addresses as empty
$order->set_address(array(), 'billing');
$order->set_address(array(), 'shipping');

// CRITICAL: Disable tax calculation for this order
add_filter('woocommerce_order_get_tax_location', function($location, $tax_class, $customer) use ($order) {
    if ($order->get_id() === $this->current_order_id) {
        // Return invalid location to prevent tax calculation
        return array('', '', '', '');
    }
    return $location;
}, 10, 3);

// Store current order ID for filter
$this->current_order_id = $order->get_id();

// Remove all tax items before calculating
foreach ($order->get_items('tax') as $item_id => $item) {
    $order->remove_item($item_id);
}

// Force zero tax
$order->set_cart_tax(0);
$order->set_shipping_tax(0);

// Calculate totals WITHOUT tax calculation
$order->calculate_totals(false);

// Force exact totals after calculation
$order->set_total($current_totals['total']);
$order->set_shipping_total($current_totals['shipping']);
$order->set_discount_total($order->get_total_discount());

// Ensure tax stays at zero
if (floatval($current_totals['tax']) === 0) {
    $order->set_cart_tax(0);
    $order->set_shipping_tax(0);
    update_post_meta($order->get_id(), '_order_tax', 0);
    update_post_meta($order->get_id(), '_cart_tax', 0);
    update_post_meta($order->get_id(), '_shipping_tax', 0);
}

// Mark to prevent future recalculation
$order->update_meta_data('_wpppc_tax_adjusted', 'yes');

// Set order status
$order->update_status('pending', __('Order created via PayPal Express Checkout', 'woo-paypal-proxy-client'));

// Save the order
$order->save();

// Clean up
$this->current_order_id = null;

wpppc_log("Express Checkout: Order #" . $order->get_id() . " created with:" .
          " Total: " . $order->get_total() .
          ", Shipping: " . $order->get_shipping_total() .
          ", Tax: " . $order->get_total_tax());
          
          
        // Use current totals from checkout page
        $order_total = isset($current_totals['total']) ? $current_totals['total'] : $order->get_total();
        $order_subtotal = isset($current_totals['subtotal']) ? $current_totals['subtotal'] : $order->get_subtotal();
        $shipping_total = isset($current_totals['shipping']) ? $current_totals['shipping'] : $order->get_shipping_total();
        $tax_total = isset($current_totals['tax']) ? $current_totals['tax'] : $order->get_total_tax();
        
        wpppc_log("Express Checkout: Using totals from checkout - Total: $order_total, Subtotal: $order_subtotal, Shipping: $shipping_total, Tax: $tax_total");
        
        // Get server to use
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        if (!$server) {
            throw new Exception(__('No PayPal server available', 'woo-paypal-proxy-client'));
        }
        
        // Store server ID in order
        update_post_meta($order->get_id(), '_wpppc_server_id', $server->id);
        
        // Prepare line items for PayPal with detailed information
        $line_items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if (!$product) continue;
            
            $line_items[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'unit_price' => $order->get_item_subtotal($item, false),
                'tax_amount' => $item->get_total_tax(),
                'sku' => $product ? $product->get_sku() : '',
                'product_id' => $product ? $product->get_id() : 0,
                'description' => $product ? wp_trim_words($product->get_short_description(), 15) : ('Product ID: ' . $product->get_id())
            );
        }
        
        // Apply product mapping to line items
        if (function_exists('add_product_mappings_to_items')) {
            $line_items = add_product_mappings_to_items($line_items, $server->id);
            wpppc_log("Express Checkout: Product mapping applied to line items");
        }
        
        // Get customer information if available
        $customer_info = array();
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $customer_info = array(
                'first_name' => $current_user->first_name,
                'last_name' => $current_user->last_name,
                'email' => $current_user->user_email
            );
        }
        
        // Create order data for proxy server with EXACT checkout totals
        $order_data = array(
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'line_items' => $line_items,
            'cart_total' => $order_subtotal,
            'order_total' => $order_total,
            'tax_total' => $tax_total,
            'shipping_total' => $shipping_total,
            'discount_total' => $order->get_discount_total(),
            'currency' => $order->get_currency(),
            'return_url' => wc_get_checkout_url(),
            'cancel_url' => wc_get_cart_url(),
            'callback_url' => WC()->api_request_url('wpppc_shipping'),
            'needs_shipping' => WC()->cart->needs_shipping(),
            'server_id' => $server->id,
            'customer_info' => $customer_info
        );
        
        // Encode the order data to base64
        $order_data_encoded = base64_encode(json_encode($order_data));
        
        // Generate security hash with the EXACT total
        $timestamp = time();
        $hash_data = $timestamp . $order->get_id() . $order_total . $server->api_key;
        $hash = hash_hmac('sha256', $hash_data, $server->api_secret);
        
        // Create request data with proper format for proxy server
        $request_data = array(
            'api_key' => $server->api_key,
            'timestamp' => $timestamp,
            'hash' => $hash,
            'order_data' => $order_data_encoded
        );
        
        wpppc_log("Express Checkout: Sending properly formatted request to proxy server");
        
        // Send request to proxy server
        $response = wp_remote_post(
            $server->url . '/wp-json/wppps/v1/create-express-checkout',
            array(
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($request_data)
            )
        );
        
        // Check for errors
        if (is_wp_error($response)) {
            wpppc_log("Express Checkout: Error communicating with proxy server: " . $response->get_error_message());
            throw new Exception(__('Error communicating with proxy server: ', 'woo-paypal-proxy-client') . $response->get_error_message());
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            wpppc_log("Express Checkout: Proxy server returned error code: $response_code");
            wpppc_log("Express Checkout: Response body: " . wp_remote_retrieve_body($response));
            throw new Exception(__('Proxy server returned error', 'woo-paypal-proxy-client'));
        }
        
        // Parse response
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$body || !isset($body['success']) || $body['success'] !== true) {
            $error_message = isset($body['message']) ? $body['message'] : __('Unknown error from proxy server', 'woo-paypal-proxy-client');
            wpppc_log("Express Checkout: Proxy server error: $error_message");
            throw new Exception($error_message);
        }
        
        // Store PayPal order ID in WooCommerce order
        $paypal_order_id = isset($body['paypal_order_id']) ? $body['paypal_order_id'] : '';
        if (!empty($paypal_order_id)) {
            update_post_meta($order->get_id(), '_paypal_order_id', $paypal_order_id);
            wpppc_log("Express Checkout: Stored PayPal order ID: $paypal_order_id for order #{$order->get_id()}");
        } else {
            wpppc_log("Express Checkout: No PayPal order ID received from proxy server");
            throw new Exception(__('No PayPal order ID received from proxy server', 'woo-paypal-proxy-client'));
        }
        
        // Return success with PayPal order ID
        wp_send_json_success(array(
            'order_id' => $order->get_id(),
            'paypal_order_id' => $paypal_order_id,
            'approveUrl' => isset($body['approve_url']) ? $body['approve_url'] : ''
        ));
        
    } catch (Exception $e) {
        wpppc_log("Express Checkout: Error creating order: " . $e->getMessage());
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
    
public function ajax_complete_express_order() {
    check_ajax_referer('wpppc-express-nonce', 'nonce');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    
    try {
        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            throw new Exception(__('Order not found', 'woo-paypal-proxy-client'));
        }
        
        // Check if we already have addresses from the fetch operation
        $has_shipping_address = !empty($order->get_shipping_address_1());
        $has_billing_address = !empty($order->get_billing_address_1());
        
        wpppc_log("DEBUG: Order has shipping address: " . ($has_shipping_address ? 'Yes' : 'No'));
        wpppc_log("DEBUG: Order has billing address: " . ($has_billing_address ? 'Yes' : 'No'));
        
        // RESTORE the original checkout totals
        $stored_totals = get_post_meta($order->get_id(), '_express_checkout_totals', true);
        
        if (!empty($stored_totals)) {
            // Set individual amounts
            if (isset($stored_totals['shipping'])) {
                $order->set_shipping_total($stored_totals['shipping']);
            }
            
            if (isset($stored_totals['tax'])) {
                // Use the proper method to set cart tax
                $order->set_cart_tax($stored_totals['tax']);
                
                // Set meta for tax amounts
                update_post_meta($order->get_id(), '_order_tax', $stored_totals['tax']);
                update_post_meta($order->get_id(), '_cart_tax', $stored_totals['tax']);
                
                // Create tax items if they don't exist
                $tax_items = $order->get_items('tax');
                if (empty($tax_items) && $stored_totals['tax'] > 0) {
                    $item = new WC_Order_Item_Tax();
                    $item->set_props(array(
                        'rate_id' => 0,
                        'label' => 'Tax',
                        'compound' => false,
                        'tax_total' => $stored_totals['tax'],
                        'shipping_tax_total' => 0,
                    ));
                    $order->add_item($item);
                }
            }
            
            // Ensure shipping method is properly set
            if (!empty($stored_totals['shipping_method'])) {
                $shipping_items = $order->get_items('shipping');
                if (empty($shipping_items)) {
                    // Add shipping method if missing
                    $item = new WC_Order_Item_Shipping();
                    $item->set_method_id('flat_rate');
                    $item->set_method_title('Shipping');
                    $item->set_total($stored_totals['shipping']);
                    $order->add_item($item);
                }
            }
            
            // Force the order total to use our exact values
            $order->set_total($stored_totals['total']);
            
            // Mark order as having adjusted totals
            $order->update_meta_data('_wpppc_tax_adjusted', 'yes');
            
            // IMPORTANT: Save the order to preserve the addresses
            $order->save();
        }
            
        
        // Get server ID
        $server_id = get_post_meta($order->get_id(), '_wpppc_server_id', true);
        
        // Get server
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_server($server_id);
        
        if (!$server) {
            throw new Exception(__('PayPal server not found', 'woo-paypal-proxy-client'));
        }
        
        // Capture payment
        $api_handler = new WPPPC_API_Handler($server_id);
        
        // Prepare request data with BOTH order ID and PayPal order ID
        $request_data = array(
            'order_id' => $order_id,
            'paypal_order_id' => $paypal_order_id,
            'order_total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'server_id' => $server_id
        );
        
        // Generate security hash
        $timestamp = time();
        $hash_data = $timestamp . $order_id . $paypal_order_id . $server->api_key;
        $hash = hash_hmac('sha256', $hash_data, $server->api_secret);
        
        $response = wp_remote_post(
            $server->url . '/wp-json/wppps/v1/capture-express-payment',
            array(
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode(array(
                    'api_key' => $server->api_key,
                    'hash' => $hash,
                    'timestamp' => $timestamp,
                    'request_data' => base64_encode(json_encode($request_data))
                ))
            )
        );
        
        // Check response
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$body || !isset($body['success']) || $body['success'] !== true) {
            throw new Exception(isset($body['message']) ? $body['message'] : 'Unknown error');
        }
        
        // Get transaction ID
        $transaction_id = isset($body['transaction_id']) ? $body['transaction_id'] : '';
        $seller_protection = isset($body['seller_protection']) ? $body['seller_protection'] : 'UNKNOWN';
        
        // NOW properly complete the payment
        if (!empty($transaction_id)) {
            // Use payment_complete() to properly mark order as paid
            $order->payment_complete($transaction_id);
            
            // Add detailed note
            $order->add_order_note(sprintf(
                __('Payment completed via PayPal Express Checkout. Transaction ID: %s, PayPal Order ID: %s', 'woo-paypal-proxy-client'),
                $transaction_id,
                $paypal_order_id
            ));
            
            // Save additional meta
            $order->update_meta_data('_paypal_transaction_id', $transaction_id);
            $order->update_meta_data('_paypal_seller_protection', $seller_protection);
            $order->save();
        }
        
        // Mirror order to server
        $mirror_response = $api_handler->mirror_order_to_server($order, $paypal_order_id, $transaction_id);
        
        // Empty the cart
        WC()->cart->empty_cart();
        
        // Return success with redirect URL
        wp_send_json_success(array(
            'redirect' => $order->get_checkout_order_received_url()
        ));
        
    } catch (Exception $e) {
        wpppc_log("Express Checkout: Error completing order: " . $e->getMessage());
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
    
   public function ajax_fetch_paypal_order_details() {
    check_ajax_referer('wpppc-express-nonce', 'nonce');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    
    try {
        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            throw new Exception(__('Order not found', 'woo-paypal-proxy-client'));
        }
        
        // Get server ID
        $server_id = get_post_meta($order->get_id(), '_wpppc_server_id', true);
        
        // Get server
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_server($server_id);
        
        if (!$server) {
            throw new Exception(__('PayPal server not found', 'woo-paypal-proxy-client'));
        }
        
        // Call the endpoint to get PayPal order details
        $timestamp = time();
        $hash_data = $timestamp . $paypal_order_id . $server->api_key;
        $hash = hash_hmac('sha256', $hash_data, $server->api_secret);
        
        $response = wp_remote_post(
            $server->url . '/wp-json/wppps/v1/get-paypal-order',
            array(
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode(array(
                    'api_key' => $server->api_key,
                    'paypal_order_id' => $paypal_order_id,
                    'timestamp' => $timestamp,
                    'hash' => $hash
                ))
            )
        );
        
        // Get PayPal order details
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $order_details = isset($body['order_details']) ? $body['order_details'] : null;
        
        if (!$order_details) {
            throw new Exception(__('No order details in response', 'woo-paypal-proxy-client'));
        }
        
        // Process shipping address first
        $shipping_address = array();
        if (!empty($order_details['purchase_units']) && is_array($order_details['purchase_units'])) {
            foreach ($order_details['purchase_units'] as $unit) {
                if (!empty($unit['shipping'])) {
                    // Get name
                    if (!empty($unit['shipping']['name'])) {
                        if (!empty($unit['shipping']['name']['full_name'])) {
                            $name_parts = explode(' ', $unit['shipping']['name']['full_name'], 2);
                            $shipping_address['first_name'] = $name_parts[0];
                            $shipping_address['last_name'] = isset($name_parts[1]) ? $name_parts[1] : '';
                        }
                    }
                    
                    // Get address
                    if (!empty($unit['shipping']['address'])) {
                        $address = $unit['shipping']['address'];
                        $shipping_address['address_1'] = isset($address['address_line_1']) ? $address['address_line_1'] : '';
                        $shipping_address['address_2'] = isset($address['address_line_2']) ? $address['address_line_2'] : '';
                        $shipping_address['city'] = isset($address['admin_area_2']) ? $address['admin_area_2'] : '';
                        $shipping_address['state'] = isset($address['admin_area_1']) ? $address['admin_area_1'] : '';
                        $shipping_address['postcode'] = isset($address['postal_code']) ? $address['postal_code'] : '';
                        $shipping_address['country'] = isset($address['country_code']) ? $address['country_code'] : '';
                    }
                    
                    // Set shipping address
                    if (!empty($shipping_address['first_name']) && !empty($shipping_address['address_1'])) {
                        $order->set_address($shipping_address, 'shipping');
                        
                        // IMPORTANT: For Express checkout, copy shipping to billing
                        $billing_address = $shipping_address;
                        
                        // Add email from payer if available
                        if (!empty($order_details['payer']['email_address'])) {
                            $billing_address['email'] = $order_details['payer']['email_address'];
                        }
                        
                        // Add phone if available from payer
                        if (!empty($order_details['payer']['phone']['phone_number']['national_number'])) {
                            $billing_address['phone'] = $order_details['payer']['phone']['phone_number']['national_number'];
                        }
                        
                        // Set billing address
                        $order->set_address($billing_address, 'billing');
                        
                        // Save the order
                        $order->save();
                        
                        wpppc_log("Express Checkout: Set shipping and billing addresses from PayPal");
                    }
                    
                    break; // We only need the first shipping address
                }
            }
        }
        
        // Return success
        wp_send_json_success(array(
            'message' => 'Order details retrieved and addresses updated',
            'has_billing' => !empty($billing_address),
            'has_shipping' => !empty($shipping_address)
        ));
        
    } catch (Exception $e) {
        wpppc_log("Express Checkout: Error fetching order details: " . $e->getMessage());
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
}

// Initialize Express Checkout
add_action('init', function() {
    new WPPPC_Express_Checkout();
});