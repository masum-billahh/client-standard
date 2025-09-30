<?php
/**
 * Product Page Express Checkout for PayPal Standard
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPPPC_Product_Page_Express {
    private $map_api_key;
    
    public function __construct() {
        
        $this->map_api_key = get_option('wpppc_google_maps_api_key', '');
        // Add PayPal button to product page
        add_action('woocommerce_after_add_to_cart_form', array($this, 'render_paypal_button'), 5);
        //add_action('wp_footer', array($this, 'add_modal_autocomplete_script'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_wpppc_process_product_express', array($this, 'process_product_express'));
        add_action('wp_ajax_nopriv_wpppc_process_product_express', array($this, 'process_product_express'));
        
        // fallback add-to-cart handler in case WC's doesn't work
        add_action('wp_ajax_wpppc_add_to_cart_fallback', array($this, 'add_to_cart_fallback'));
        add_action('wp_ajax_nopriv_wpppc_add_to_cart_fallback', array($this, 'add_to_cart_fallback'));
        
        // AJAX handler for shipping calculation
        add_action('wp_ajax_wpppc_calculate_shipping', array($this, 'calculate_shipping_methods'));
        add_action('wp_ajax_nopriv_wpppc_calculate_shipping', array($this, 'calculate_shipping_methods'));
        
        add_action('wp_ajax_wpppc_update_totals_only', array($this, 'update_totals_only'));
        add_action('wp_ajax_nopriv_wpppc_update_totals_only', array($this, 'update_totals_only'));
        
        // AJAX handler for getting states by country
        add_action('wp_ajax_wpppc_get_states', array($this, 'get_states_by_country'));
        add_action('wp_ajax_nopriv_wpppc_get_states', array($this, 'get_states_by_country'));
    
        // AJAX handler for getting cart totals
        add_action('wp_ajax_wpppc_get_cart_totals', array($this, 'get_cart_totals'));
        add_action('wp_ajax_nopriv_wpppc_get_cart_totals', array($this, 'get_cart_totals'));
        add_action('wp_ajax_wpppc_validate_product', array($this, 'validate_product'));
        add_action('wp_ajax_nopriv_wpppc_validate_product', array($this, 'validate_product'));
    }
    
    public function update_totals_only() {
        check_ajax_referer('wpppc-product-express', 'nonce');
        
        if (WC()->cart->is_empty()) {
            wp_send_json_error(array('message' => __('Cart is empty.', 'woo-paypal-proxy-client')));
        }
        
        $shipping_method = sanitize_text_field($_POST['shipping_method']);
        
        if ($shipping_method) {
            $chosen_shipping_methods = array($shipping_method);
            WC()->session->set('chosen_shipping_methods', $chosen_shipping_methods);
        }
        
        // Recalculate with new shipping method
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();
        
        // Get updated totals
        $totals = array(
            'subtotal' => WC()->cart->get_subtotal(),
            'subtotal_formatted' => wc_price(WC()->cart->get_subtotal()),
            'shipping_total' => WC()->cart->get_shipping_total(),
            'shipping_formatted' => wc_price(WC()->cart->get_shipping_total()),
            'tax_total' => WC()->cart->get_total_tax(),
            'tax_formatted' => wc_price(WC()->cart->get_total_tax()),
            'total' => WC()->cart->get_total('edit'),
            'total_formatted' => wc_price(WC()->cart->get_total('edit'))
        );
        
        wp_send_json_success(array('totals' => $totals));
    }
    
    
  

/**
 * Get states for a given country and determine field type
 */
public function get_states_by_country() {
    // Check nonce
    if (!wp_verify_nonce($_POST['nonce'], 'wpppc-product-express')) {
        wp_send_json_error(array('message' => 'Security check failed'));
        return;
    }
    
    $country = sanitize_text_field($_POST['country']);
    
    if (empty($country)) {
        wp_send_json_error(array('message' => 'No country provided'));
        return;
    }
    
    try {
        // Get states from WooCommerce
        $states = WC()->countries->get_states($country);
        
        // Determine if state is required for this country
        $state_required = false;
        if (method_exists(WC()->countries, 'state_is_required')) {
            $state_required = WC()->countries->state_is_required($country);
        } else {
            $state_required = !empty($states);
        }
        
        $state_label = 'State';
        if (method_exists(WC()->countries, 'get_state_label')) {
            $state_label = WC()->countries->get_state_label($country);
        }
        
        if (empty($state_label)) {
            $state_label = 'State / County';
        }
        
        $has_states = !empty($states);
        
        if ($has_states) {
            $field_type = 'select';
        } else if ($state_required) {
            $field_type = 'text_required';
        } else {
            $field_type = 'text_optional';
        }
        
        wp_send_json_success(array(
            'states' => $states ?: array(),
            'field_type' => $field_type,
            'required' => $state_required,
            'label' => $state_label,
            'has_states' => $has_states
        ));
        
    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => 'Error processing request: ' . $e->getMessage()
        ));
    }
}  
    /**
     * Render PayPal button on product page
     */
  public function render_paypal_button() {
    global $product;
    
    if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
        return;
    }
    
    // Get server and check if PayPal is enabled
    $server_manager = WPPPC_Server_Manager::get_instance();
    $server = $server_manager->get_selected_server();
    
    if (!$server) {
        return;
    }
    
    $payment_gateways = WC()->payment_gateways()->payment_gateways();
    $show_button = false;

    // First check if gateway exists and is enabled
    if (isset($payment_gateways['paypal_proxy'])) {
        $gateway = $payment_gateways['paypal_proxy'];
        
        // Check if gateway is enabled
        if ($gateway->enabled !== 'yes') {
            return;
        }
        
        // Check mobile restrictions
        if ($gateway->get_option('mobile_only') === 'yes' && 
            method_exists($gateway, 'is_real_mobile_device') &&
            !$gateway->is_real_mobile_device()) {
            return; // Exit early if mobile required but not on mobile
        }
        
        //check server mode
        if (!empty($server->is_personal) && !empty($server->personal_express)) {
            $show_button = true; // Personal mode
        } elseif (empty($server->is_personal) && !empty($server->personal_express)) {
            $show_button = true; // Business mode
        } else {
            $show_button = false;
        }
    }
        
    if (!$show_button) {
        return;
    }
    
    $is_business_mode = empty($server->is_personal);
    
    
    ?>
<div id="wpppc-product-express-container" style="margin-top: 20px;">
    <?php if ($is_business_mode && $show_button): ?>
        <!-- Business Mode: Express Checkout Iframe -->
        <div id="wpppc-product-express-iframe-container" class="wpppc-express-paypal-button"></div>
    <?php else: ?>
        <!-- Personal Mode: Standard Button -->
        <button type="button" id="wpppc-product-express-button" class="button alt">
            <img src="<?php echo WPPPC_PLUGIN_URL; ?>assets/images/paypal.svg" alt="PayPal" style="height: 30px; width: 100%; cursor: pointer;" />
        </button>
    <?php endif; ?>
</div>
    
    <!-- Address Form Modal -->
    <div id="wpppc-express-modal" class="wpppc-modal" style="display:none;">
        <div class="wpppc-modal-content">
            <span class="wpppc-modal-close">&times;</span>
            <h2><?php _e('Confirm your address', 'woo-paypal-proxy-client'); ?></h2>
            <form id="wpppc-express-form">
                <div class="wpppc-modern-form">
                    <!-- Email Field -->
                    <div class="wpppc-field-container">
                        <input type="email" id="billing_email" name="billing_email" required>
                        <label for="billing_email"><?php _e('Email', 'woo-paypal-proxy-client'); ?></label>
                    </div>

                    <!-- Shipping Address Section -->
                    <h3 class="wpppc-section-title"><?php _e('Shipping address', 'woo-paypal-proxy-client'); ?></h3>
                   

                    <!-- First Name, Last Name -->
                    <div class="wpppc-field-row">
                        <div class="wpppc-field-container wpppc-field-half">
                            <input type="text" id="billing_first_name" name="billing_first_name" required>
                            <label for="billing_first_name"><?php _e('First name', 'woo-paypal-proxy-client'); ?></label>
                        </div>
                        <div class="wpppc-field-container wpppc-field-half">
                            <input type="text" id="billing_last_name" name="billing_last_name" required>
                            <label for="billing_last_name"><?php _e('Last name', 'woo-paypal-proxy-client'); ?></label>
                        </div>
                    </div>

                    <!-- Address -->
                    <div class="wpppc-field-container">
                        <input type="text" id="billing_address_1" name="billing_address_1" required>
                        <label for="billing_address_1"><?php _e('Address', 'woo-paypal-proxy-client'); ?></label>
                    </div>
                    

                    <!-- Add Address Line Toggle -->
                    <div class="wpppc-add-address-toggle">
                        <span class="wpppc-add-icon">+</span>
                        <span class="wpppc-add-text"><?php _e('Add address suite, apartment etc', 'woo-paypal-proxy-client'); ?></span>
                    </div>

                    <!-- Optional Address Line 2 (Hidden by default) -->
                    <div class="wpppc-field-container wpppc-address-line-2" style="display: none;">
                        <input type="text" id="billing_address_2" name="billing_address_2">
                        <label for="billing_address_2"><?php _e('Apartment, suite, etc.', 'woo-paypal-proxy-client'); ?></label>
                    </div>
                    
                     
                    <!-- Country -->
                    <div class="wpppc-field-container wpppc-select-container">
                        <select id="billing_country" name="billing_country" required>
                            <option value=""><?php _e('Select Country', 'woo-paypal-proxy-client'); ?></option>
                            <?php
                            $countries = WC()->countries->get_allowed_countries();
                            foreach ($countries as $code => $name) {
                                $selected = ($code === 'US') ? 'selected' : '';
                                echo '<option value="' . esc_attr($code) . '" ' . $selected . '>' . esc_html($name) . '</option>';
                            }
                            ?>
                        </select>
                        <label for="billing_country"><?php _e('Country/Region', 'woo-paypal-proxy-client'); ?></label>
                    </div>

                    <!-- City, State -->
                    <div class="wpppc-field-row">
                        <div class="wpppc-field-container wpppc-field-half">
                            <input type="text" id="billing_city" name="billing_city" required>
                            <label for="billing_city"><?php _e('City', 'woo-paypal-proxy-client'); ?></label>
                        </div>
                        <div class="wpppc-field-container wpppc-field-half wpppc-select-container">
                            <select id="billing_state" name="billing_state" required>
                                <option value=""><?php _e('Select State', 'woo-paypal-proxy-client'); ?></option>
                            </select>
                            <label for="billing_state"><?php _e('State', 'woo-paypal-proxy-client'); ?></label>
                        </div>
                    </div>

                    <!-- ZIP Code, Phone -->
                    <div class="wpppc-field-row">
                        <div class="wpppc-field-container wpppc-field-half">
                            <input type="text" id="billing_postcode" name="billing_postcode" required>
                            <label for="billing_postcode"><?php _e('ZIP code', 'woo-paypal-proxy-client'); ?></label>
                        </div>
                        <div class="wpppc-field-container wpppc-field-half">
                            <input type="tel" id="billing_phone" name="billing_phone">
                            <label for="billing_phone"><?php _e('Phone', 'woo-paypal-proxy-client'); ?></label>
                        </div>
                    </div>

                    <!-- Hidden fields for proper form processing -->
                    <input type="hidden" name="billing_company" value="">
                    <input type="hidden" name="ship_to_different_address" value="">

                    <!-- Shipping Options Section -->
                    <h3 class="wpppc-section-title"><?php _e('Shipping options', 'woo-paypal-proxy-client'); ?></h3>
                    <div id="shipping-methods-container">
                        <p class="shipping-notice"><?php _e('Please enter your address to see shipping options.', 'woo-paypal-proxy-client'); ?></p>
                    </div>
                    
                    <div id="order-totals" style="margin-top: 15px; padding: 10px; border: 1px solid #ddd; display: none;">
                        <div class="total-line"><span><?php _e('Subtotal:', 'woo-paypal-proxy-client'); ?></span> <span id="subtotal-amount">-</span></div>
                        <div class="total-line"><span><?php _e('Shipping:', 'woo-paypal-proxy-client'); ?></span> <span id="shipping-amount">-</span></div>
                        <div class="total-line"><span><?php _e('Tax:', 'woo-paypal-proxy-client'); ?></span> <span id="tax-amount">-</span></div>
                        <div class="total-line total-final"><strong><span><?php _e('Total:', 'woo-paypal-proxy-client'); ?></span> <span id="total-amount">-</span></strong></div>
                    </div>
                </div>
                
                <div class="wpppc-form-errors" style="display:none;"></div>
                <div class="wpppc-form-actions">
                    <button type="submit" class="button alt proceed-ppl"><?php _e('Confirm', 'woo-paypal-proxy-client'); ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php
}
    
    /**
 * Calculate shipping methods for given address
 */
public function calculate_shipping_methods() {
    check_ajax_referer('wpppc-product-express', 'nonce');
    
    if (WC()->cart->is_empty()) {
        wp_send_json_error(array('message' => __('Cart is empty.', 'woo-paypal-proxy-client')));
    }
    
    $posted_data = $_POST;

    // Set customer location for shipping calculation
    $country = sanitize_text_field($posted_data['country']);
    $state = sanitize_text_field($posted_data['state']);
    $postcode = sanitize_text_field($posted_data['postcode']);
    $city = sanitize_text_field($posted_data['city']);
    
    // Set the customer location
    WC()->customer->set_shipping_country($country);
    WC()->customer->set_shipping_state($state);
    WC()->customer->set_shipping_postcode($postcode);
    WC()->customer->set_shipping_city($city);
    
    // Also set billing if shipping address is same
    if (empty($posted_data['different_address'])) {
        WC()->customer->set_billing_country($country);
        WC()->customer->set_billing_state($state);
        WC()->customer->set_billing_postcode($postcode);
        WC()->customer->set_billing_city($city);
    }
    
    // Set customer addresses for proper shipping calculation
    $customer = WC()->customer;
    $customer->set_billing_address_1(sanitize_text_field($posted_data['billing_address_1'] ?? ''));
    $customer->set_billing_city(sanitize_text_field($posted_data['billing_city'] ?? $city));
    $customer->set_billing_state(sanitize_text_field($posted_data['billing_state'] ?? $state));
    $customer->set_billing_postcode(sanitize_text_field($posted_data['billing_postcode'] ?? $postcode));
    $customer->set_billing_country(sanitize_text_field($posted_data['billing_country'] ?? $country));
    
    if (!empty($posted_data['ship_to_different_address'])) {
        $customer->set_shipping_address_1(sanitize_text_field($posted_data['shipping_address_1'] ?? ''));
        $customer->set_shipping_city(sanitize_text_field($posted_data['shipping_city'] ?? ''));
        $customer->set_shipping_state(sanitize_text_field($posted_data['shipping_state'] ?? ''));
        $customer->set_shipping_postcode(sanitize_text_field($posted_data['shipping_postcode'] ?? ''));
        $customer->set_shipping_country(sanitize_text_field($posted_data['shipping_country'] ?? ''));
    } else {
        $customer->set_shipping_address_1(sanitize_text_field($posted_data['billing_address_1'] ?? ''));
        $customer->set_shipping_city(sanitize_text_field($posted_data['billing_city'] ?? $city));
        $customer->set_shipping_state(sanitize_text_field($posted_data['billing_state'] ?? $state));
        $customer->set_shipping_postcode(sanitize_text_field($posted_data['billing_postcode'] ?? $postcode));
        $customer->set_shipping_country(sanitize_text_field($posted_data['billing_country'] ?? $country));
    }
    
    // Set selected shipping method
    if (!empty($posted_data['shipping_method'])) {
        $chosen_shipping_methods = array($posted_data['shipping_method']);
        WC()->session->set('chosen_shipping_methods', $chosen_shipping_methods);
    }
    
    // Calculate totals since cart already has items
    WC()->cart->calculate_shipping();
    WC()->cart->calculate_totals();
    
    // Get available shipping methods
    $packages = WC()->shipping()->get_packages();
    $shipping_methods = array();
    
    foreach ($packages as $package_key => $package) {
        if (empty($package['rates'])) {
            continue;
        }
        
        foreach ($package['rates'] as $rate_id => $rate) {
            $shipping_methods[] = array(
                'id' => $rate_id,
                'label' => $rate->get_label(),
                'cost' => $rate->get_cost(),
                'formatted_cost' => wc_price($rate->get_cost()),
                'method_id' => $rate->get_method_id(),
                'instance_id' => $rate->get_instance_id()
            );
        }
    }
    
    // Get cart totals
    $totals = array(
        'subtotal' => WC()->cart->get_subtotal(),
        'subtotal_formatted' => wc_price(WC()->cart->get_subtotal()),
        'shipping_total' => WC()->cart->get_shipping_total(),
        'shipping_formatted' => wc_price(WC()->cart->get_shipping_total()),
        'tax_total' => WC()->cart->get_total_tax(),
        'tax_formatted' => wc_price(WC()->cart->get_total_tax()),
        'total' => WC()->cart->get_total('edit'),
        'total_formatted' => wc_price(WC()->cart->get_total('edit'))
    );
    
    wp_send_json_success(array(
        'shipping_methods' => $shipping_methods,
        'totals' => $totals
    ));
}
    
    /**
     * Render checkout fields
     */
 private function render_checkout_fields($type) {
        $checkout = WC()->checkout();
        $fields = $checkout->get_checkout_fields($type);

        foreach ($fields as $key => $field) {
            woocommerce_form_field($key, $field, $checkout->get_value($key));
        }
    }
    
    /**
     * Enqueue scripts for product page
     */
    public function enqueue_scripts() {
        if (!is_product()) {
            return;
        }
        
         $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        // Ensure WooCommerce scripts are loaded
        wp_enqueue_script('wc-add-to-cart');
        wp_enqueue_script('wc-cart-fragments');
        
        wp_enqueue_script(
            'wpppc-product-express',
            WPPPC_PLUGIN_URL . 'assets/js/product-express.js',
            array('jquery', 'wc-checkout', 'wc-add-to-cart', 'wc-cart-fragments'),
            time(),
            true
        );
        
         wp_enqueue_script(
                'google-maps-api',
                'https://maps.googleapis.com/maps/api/js?key=' . $this->map_api_key . '&libraries=places&loading=async',
                array('jquery'),
                '4.1',
                true
        );
        
        wp_enqueue_script(
            'wpppc-google-autocomplete',
            WPPPC_PLUGIN_URL . 'assets/js/google-autocomplete.js',
            array('jquery'),
            '4.1',
            true
        );
        
        wp_enqueue_style(
            'wpppc-product-express',
            WPPPC_PLUGIN_URL . 'assets/css/product-express.css',
            array(),
            time()
        );
        
        wp_localize_script('wpppc-product-express', 'wpppc_product_express', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpppc-product-express'),
            'is_variable' => is_product() && wc_get_product()->is_type('variable'),
            'wc_ajax_url' => WC_AJAX::get_endpoint('%%endpoint%%'),
            'product_id' => is_product() ? get_the_ID() : 0,
            'i18n' => array(
                'select_options' => __('Please select product options before proceeding.', 'woo-paypal-proxy-client'),
                'processing' => __('Processing...', 'woo-paypal-proxy-client'),
            )
        ));
        
        // Get server and prepare data for product express
        if ($server && empty($server->is_personal)) {
            // Business mode - get iframe URL for product
            $api_handler = new WPPPC_API_Handler();
            $iframe_url = $this->generate_product_iframe_url($api_handler);
            
            // Localize both express and server data to product-express.js
            wp_localize_script('wpppc-product-express', 'wpppc_express_params', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wpppc-product-express'),
                'iframe_url' => $iframe_url,
                'cart_total' => '0',
                'currency' => get_woocommerce_currency(),
                'shipping_required' => false,
                'is_checkout_page' => false,
                'is_cart_page' => false,
                'is_product_page' => true,
                'debug_mode' => true
            ));
            
            wp_localize_script('wpppc-product-express', 'wpppc_server_mode', array(
                'is_business_mode' => true,
                'iframe_url' => $iframe_url
            ));
        } else {
            wp_localize_script('wpppc-product-express', 'wpppc_server_mode', array(
                'is_business_mode' => false
            ));
        }
    }
    
/**
 * Generate iframe URL for product page with product price
 */
private function generate_product_iframe_url($api_handler) {
    global $product;

    // Ensure we have a valid product object
    if (!$product instanceof WC_Product) {
        $product = wc_get_product(get_the_ID());
    }

    if (!$product) {
        return '';
    }

    // Get product price safely
    $price = $product->get_price();

    if (empty($price) && $product->is_type('variable')) {
        $price = $product->get_variation_price('min');
    }
    
    $server = $api_handler->get_server();
    if (!$server) {
        return '';
    }
    
    // Get callback URL
    $callback_url = WC()->api_request_url('wpppc_shipping');
    
    // Generate a hash for security
    $timestamp = time();
    $hash_data = $timestamp . 'express_checkout' . $server->api_key;
    $hash = hash_hmac('sha256', $hash_data, $server->api_secret);
    
    // Build the iframe URL with product price
    $params = array(
        'rest_route'       => '/wppps/v1/express-paypal-buttons',
        'amount'           => $price,
        'currency'         => get_woocommerce_currency(),
        'api_key'          => $server->api_key,
        'timestamp'        => $timestamp,
        'hash'             => $hash,
        'callback_url'     => base64_encode($callback_url),
        'site_url'         => base64_encode(get_site_url()),
        'server_id'        => $server->id,
        'needs_shipping'   => 'unknown', // Will be determined after add to cart
        'express'          => 'yes',
        'product_page'     => 'yes'
    );
    
    return $server->url . '?' . http_build_query($params);
}

/**
 * AJAX handler to get current cart totals
 */
public function get_cart_totals() {
    check_ajax_referer('wpppc-product-express', 'nonce');
    
    if (WC()->cart->is_empty()) {
        wp_send_json_error(array('message' => __('Cart is empty.', 'woo-paypal-proxy-client')));
    }
    
    // Calculate totals
    WC()->cart->calculate_totals();
    
    $totals = array(
        'total' => floatval(WC()->cart->get_total('edit')),
        'subtotal' => floatval(WC()->cart->get_subtotal()),
        'shipping' => floatval(WC()->cart->get_shipping_total()),
        'tax' => floatval(WC()->cart->get_total_tax()),
        'shipping_method' => '', // Will be determined later
        'shipping_method_label' => ''
    );
    
    wp_send_json_success($totals);
}
    
public function validate_product() {
    if (isset($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'wpppc-product-express')) {
        wp_send_json_error(array('error_message' => 'Security check failed'));
    }
    
    // Clear any existing notices first
    wc_clear_notices();
    
    $product_id = isset($_POST['add-to-cart']) ? absint($_POST['add-to-cart']) : 0;
    $quantity = isset($_POST['quantity']) ? wc_stock_amount($_POST['quantity']) : 1;
    $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
    $variation = array();
    
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'attribute_') === 0) {
            $variation[sanitize_title($key)] = sanitize_text_field($value);
        }
    }
    
    if (!$product_id) {
        wp_send_json_error(array('error_message' => 'No product specified'));
    }
    
    $product = wc_get_product($variation_id ? $variation_id : $product_id);
    if (!$product || !$product->is_purchasable()) {
        wp_send_json_error(array('error_message' => 'Product not available'));
    }
    
    $_REQUEST = $_POST;
    $cart_item_data = array();
    $passed_validation = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variation, $cart_item_data);
    
    if (!$passed_validation) {
        $error_messages = array();
        $notices = wc_get_notices('error');
        foreach ($notices as $notice) {
            $message = is_string($notice) ? $notice : $notice['notice'];
            $message = wp_specialchars_decode($message, ENT_QUOTES);
            if (!in_array($message, $error_messages)) {
                $error_messages[] = $message;
            }
        }
        wc_clear_notices();
        
        $error_message = !empty($error_messages) ? implode(', ', $error_messages) : 'Product validation failed';
        wp_send_json_error(array('error_message' => $error_message));
    }
    
    wp_send_json_success();
} 
    
/**
 * Fallback add-to-cart handler
 */
public function add_to_cart_fallback() {
    //error_log('=== WPPPC DEBUG: add_to_cart_fallback START ===');
    
    // Verify nonce if provided
    if (isset($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'wpppc-product-express')) {
        wp_send_json_error(array('error_message' => 'Security check failed'));
    }
    
    // COMPLETELY clear cart first - this removes any products that might have been added by WC's native handler
    WC()->cart->empty_cart();
    
    // Also clear session data to ensure clean slate
    if (WC()->session) {
        WC()->session->set('cart', array());
        WC()->session->set('applied_coupons', array());
        WC()->session->set('coupon_discount_totals', array());
        WC()->session->set('coupon_discount_tax_totals', array());
    }
    
   // error_log('WPPPC DEBUG: Cart completely cleared');
    
    $product_id = isset($_POST['add-to-cart']) ? absint($_POST['add-to-cart']) : 0;
    $quantity = isset($_POST['quantity']) ? wc_stock_amount($_POST['quantity']) : 1;
    $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
    $variation = array();
    
    //error_log('WPPPC DEBUG: Product ID: ' . $product_id . ', Quantity: ' . $quantity . ', Variation ID: ' . $variation_id);
    
    // Extract variation attributes
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'attribute_') === 0) {
            $variation[sanitize_title($key)] = sanitize_text_field($value);
        }
    }
    
    // Log any tm_extra_product_options data in POST
    $tm_data = array();
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'tm_') === 0 || strpos($key, 'tmcp_') === 0 || strpos($key, 'cpf_') === 0) {
            $tm_data[$key] = $value;
        }
    }
    //error_log('WPPPC DEBUG: TM Extra Product Options data in POST: ' . print_r($tm_data, true));
    
    if (!$product_id) {
        wp_send_json_error(array('error_message' => 'No product specified'));
    }
    
    // Check product
    $product = wc_get_product($variation_id ? $variation_id : $product_id);
    if (!$product || !$product->is_purchasable()) {
        wp_send_json_error(array('error_message' => 'Product not available'));
    }
    
    // Set up $_REQUEST for plugins to use
    $_REQUEST = $_POST;
    
    // Fire validation hook
    //error_log('WPPPC DEBUG: About to fire woocommerce_add_to_cart_validation filter');
    $cart_item_data = array(); // Initialize this variable
    $passed_validation = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variation, $cart_item_data);
    //error_log('WPPPC DEBUG: Validation result: ' . ($passed_validation ? 'PASSED' : 'FAILED'));
    
    if (!$passed_validation) {
        wp_send_json_error(array('error_message' => 'Product validation failed'));
    }
    
    // Build cart item data - let plugins add their data
    $cart_item_data = array();
    //error_log('WPPPC DEBUG: Initial cart_item_data: ' . print_r($cart_item_data, true));
    
    //error_log('WPPPC DEBUG: About to fire woocommerce_add_cart_item_data filter');
    $cart_item_data = apply_filters('woocommerce_add_cart_item_data', $cart_item_data, $product_id, $variation_id, $quantity);
    //error_log('WPPPC DEBUG: cart_item_data after woocommerce_add_cart_item_data filter: ' . print_r($cart_item_data, true));
    
    // Log cart contents before add_to_cart
    //error_log('WPPPC DEBUG: Cart contents BEFORE add_to_cart: ' . print_r(WC()->cart->get_cart_contents(), true));
    
    // Add to cart
    //error_log('WPPPC DEBUG: About to call WC()->cart->add_to_cart()');
    $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation, $cart_item_data);
    //error_log('WPPPC DEBUG: add_to_cart returned cart_item_key: ' . $cart_item_key);
    
    if ($cart_item_key) {
        // CRITICAL FIX: Check for and remove duplicates in tm EPO data
        if (isset(WC()->cart->cart_contents[$cart_item_key]['tmcartepo']) && 
            is_array(WC()->cart->cart_contents[$cart_item_key]['tmcartepo'])) {
            
            $tmcartepo = WC()->cart->cart_contents[$cart_item_key]['tmcartepo'];
           // error_log('WPPPC DEBUG: Found tmcartepo with ' . count($tmcartepo) . ' items, checking for duplicates');
            
            // Remove duplicates based on post_name + value combination
            $unique_items = array();
            $seen_items = array();
            
            foreach ($tmcartepo as $item) {
                $identifier = ($item['post_name'] ?? '') . '|' . ($item['value'] ?? '');
                if (!in_array($identifier, $seen_items)) {
                    $unique_items[] = $item;
                    $seen_items[] = $identifier;
                    //error_log('WPPPC DEBUG: Keeping unique item: ' . $identifier);
                } else {
                    //error_log('WPPPC DEBUG: Removing duplicate item: ' . $identifier);
                }
            }
            
            WC()->cart->cart_contents[$cart_item_key]['tmcartepo'] = $unique_items;
            //error_log('WPPPC DEBUG: Reduced tmcartepo from ' . count($tmcartepo) . ' to ' . count($unique_items) . ' items');
        }
        
        // Also fix tmcartepo_data duplicates
        if (isset(WC()->cart->cart_contents[$cart_item_key]['tmdata']['tmcartepo_data']) && 
            is_array(WC()->cart->cart_contents[$cart_item_key]['tmdata']['tmcartepo_data'])) {
            
            $tmcartepo_data = WC()->cart->cart_contents[$cart_item_key]['tmdata']['tmcartepo_data'];

            $unique_data = array();
            $seen_data = array();
            
            foreach ($tmcartepo_data as $data) {
                $identifier = ($data['attribute'] ?? '') . '|' . ($data['key'] ?? '');
                if (!in_array($identifier, $seen_data)) {
                    $unique_data[] = $data;
                    $seen_data[] = $identifier;
                    //error_log('WPPPC DEBUG: Keeping unique data: ' . $identifier);
                } else {
                    //error_log('WPPPC DEBUG: Removing duplicate data: ' . $identifier);
                }
            }
            
            WC()->cart->cart_contents[$cart_item_key]['tmdata']['tmcartepo_data'] = $unique_data;
            //error_log('WPPPC DEBUG: Reduced tmcartepo_data from ' . count($tmcartepo_data) . ' to ' . count($unique_data) . ' items');
        }
        
        // Recalculate totals with clean data
       // error_log('WPPPC DEBUG: About to calculate totals with deduplicated data');
        WC()->cart->calculate_totals();
        
        //error_log('WPPPC DEBUG: Final cart contents after deduplication: ' . print_r(WC()->cart->get_cart_contents(), true));
    }
    
    if ($cart_item_key) {
        // Build response like WooCommerce does
        $data = array(
            'fragments' => apply_filters('woocommerce_add_to_cart_fragments', array()),
            'cart_hash' => WC()->cart->get_cart_hash()
        );
        
        // Trigger the cart updated hooks
        //error_log('WPPPC DEBUG: About to fire woocommerce_add_to_cart action hook');
        do_action('woocommerce_add_to_cart', $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data);
        
        //error_log('WPPPC DEBUG: Success - returning cart data');
        wp_send_json_success($data);
    } else {
        // Get any errors from WooCommerce notices
        $error_messages = array();
        $notices = wc_get_notices('error');
        foreach ($notices as $notice) {
            $error_messages[] = is_string($notice) ? $notice : $notice['notice'];
        }
        wc_clear_notices(); // Clear notices after getting them
        
        $error_message = !empty($error_messages) ? implode(', ', $error_messages) : 'Could not add product to cart';
        error_log('WPPPC DEBUG: Error - ' . $error_message);
        wp_send_json_error(array('error_message' => $error_message));
    }
    
    //error_log('=== WPPPC DEBUG: add_to_cart_fallback END ===');
}    /**
     * Process product express checkout
     * Now works with items already in cart from WC's native add-to-cart
     */
    public function process_product_express() {
        check_ajax_referer('wpppc-product-express', 'nonce');
        
        // Check if cart has items
        if (WC()->cart->is_empty()) {
            wp_send_json_error(array('message' => __('Cart is empty. Please add a product first.', 'woo-paypal-proxy-client')));
        }
        
        // Validate checkout fields
        $errors = array();
        $checkout = WC()->checkout();
        $posted_data = $_POST;

        // Validate billing fields
        $billing_fields = $checkout->get_checkout_fields('billing');
        foreach ($billing_fields as $key => $field) {
            if (!empty($field['required']) && empty($posted_data[$key])) {
                $errors[$key] = sprintf(__('%s is required', 'woo-paypal-proxy-client'), $field['label']);
            }
        }
        
        // Validate shipping fields if needed
        if (!empty($posted_data['ship_to_different_address'])) {
            $shipping_fields = $checkout->get_checkout_fields('shipping');
            foreach ($shipping_fields as $key => $field) {
                if (!empty($field['required']) && empty($posted_data[$key])) {
                    $errors[$key] = sprintf(__('%s is required', 'woo-paypal-proxy-client'), $field['label']);
                }
            }
        }
        
        if (!empty($errors)) {
            wp_send_json_error(array('errors' => $errors));
        }
        
       // Apply selected shipping method and customer address BEFORE calculating totals
// Set customer addresses for shipping calculation
$customer = WC()->customer;
$customer->set_billing_address_1(sanitize_text_field($posted_data['billing_address_1']));
$customer->set_billing_city(sanitize_text_field($posted_data['billing_city']));
$customer->set_billing_state(sanitize_text_field($posted_data['billing_state']));
$customer->set_billing_postcode(sanitize_text_field($posted_data['billing_postcode']));
$customer->set_billing_country(sanitize_text_field($posted_data['billing_country']));

if (!empty($posted_data['ship_to_different_address'])) {
    $customer->set_shipping_address_1(sanitize_text_field($posted_data['shipping_address_1']));
    $customer->set_shipping_city(sanitize_text_field($posted_data['shipping_city']));
    $customer->set_shipping_state(sanitize_text_field($posted_data['shipping_state']));
    $customer->set_shipping_postcode(sanitize_text_field($posted_data['shipping_postcode']));
    $customer->set_shipping_country(sanitize_text_field($posted_data['shipping_country']));
} else {
    $customer->set_shipping_address_1(sanitize_text_field($posted_data['billing_address_1']));
    $customer->set_shipping_city(sanitize_text_field($posted_data['billing_city']));
    $customer->set_shipping_state(sanitize_text_field($posted_data['billing_state']));
    $customer->set_shipping_postcode(sanitize_text_field($posted_data['billing_postcode']));
    $customer->set_shipping_country(sanitize_text_field($posted_data['billing_country']));
}

// Apply selected shipping method to cart
if (!empty($posted_data['shipping_method'])) {
    $chosen_shipping_methods = array($posted_data['shipping_method']);
    WC()->session->set('chosen_shipping_methods', $chosen_shipping_methods);
    //error_log('WPPPC: Applied shipping method to cart: ' . $posted_data['shipping_method']);
}

// NOW calculate totals with the shipping method applied
WC()->cart->calculate_shipping();
WC()->cart->calculate_totals();

        
        // Create real WooCommerce order from existing cart
        $order = wc_create_order(array(
            'status'      => 'pending',
            'customer_id' => get_current_user_id(),
        ));

        if (is_wp_error($order)) {
            wp_send_json_error(array('message' => __('Could not create order', 'woo-paypal-proxy-client')));
        }

        $order_id = $order->get_id();

       // Add items from cart to order using proper WooCommerce hooks
foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
    $product = $cart_item['data'];
    $qty = $cart_item['quantity'];
    
    // Create order item the proper way
    $item = new WC_Order_Item_Product();
    $item->set_props(array(
        'quantity'     => $qty,
        'variation'    => $cart_item['variation'],
        'subtotal'     => $cart_item['line_subtotal'],
        'subtotal_tax' => $cart_item['line_subtotal_tax'],
        'total'        => $cart_item['line_total'],
        'tax'          => $cart_item['line_tax'],
        'tax_data'     => $cart_item['line_tax_data'],
    ));
    $item->set_backorder_meta();
    $item->set_product($product);
    
    // Add item to order and save to get ID
    $order->add_item($item);
    $order->save(); // This assigns the ID to the item
    
    $item_id = $item->get_id();
    
    if (!$item_id) {
        wp_send_json_error(array('message' => __('Could not add item to order', 'woo-paypal-proxy-client')));
    }
    
    do_action('woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $cart_item, $order);
    
    do_action('woocommerce_new_order_item', $item_id, $item, $order->get_id());
}

// Save the order after all items are added
$order->save();

// Fire overall order creation hook
do_action('woocommerce_checkout_create_order', $order, array());

if (!empty($posted_data['shipping_method'])) {
    $selected_shipping_method = $posted_data['shipping_method']; 
    
    $packages = WC()->shipping()->get_packages();
    
    foreach ($packages as $package_key => $package) {
        if (empty($package['rates'])) {
            continue;
        }
        
        foreach ($package['rates'] as $rate_id => $rate) {
            // Check if this is the selected shipping method
            if ($rate_id === $selected_shipping_method) {
                // Create shipping order item
                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_props(array(
                    'method_title' => $rate->get_label(),
                    'method_id'    => $rate->get_method_id(),
                    'instance_id'  => $rate->get_instance_id(),
                    'total'        => $rate->get_cost(),
                    'taxes'        => $rate->get_taxes(),
                ));
                
                // Set any additional shipping method meta data
                foreach ($rate->get_meta_data() as $key => $value) {
                    $shipping_item->add_meta_data($key, $value);
                }
                
                // Add the shipping item to order
                $order->add_item($shipping_item);
                
                break 2; // Exit both loops
            }
        }
    }
} else {
    error_log('WPPPC: No shipping method selected in form data');
}
        // Set billing address
        $billing_address = array(
            'first_name' => sanitize_text_field($posted_data['billing_first_name']),
            'last_name'  => sanitize_text_field($posted_data['billing_last_name']),
            'company'    => sanitize_text_field($posted_data['billing_company'] ?? ''),
            'email'      => sanitize_email($posted_data['billing_email']),
            'phone'      => sanitize_text_field($posted_data['billing_phone']),
            'address_1'  => sanitize_text_field($posted_data['billing_address_1']),
            'address_2'  => sanitize_text_field($posted_data['billing_address_2'] ?? ''),
            'city'       => sanitize_text_field($posted_data['billing_city']),
            'state'      => sanitize_text_field($posted_data['billing_state']),
            'postcode'   => sanitize_text_field($posted_data['billing_postcode']),
            'country'    => sanitize_text_field($posted_data['billing_country'])
        );
        $order->set_address($billing_address, 'billing');

        // Set shipping address
        if (!empty($posted_data['ship_to_different_address'])) {
            $shipping_address = array(
                'first_name' => sanitize_text_field($posted_data['shipping_first_name']),
                'last_name'  => sanitize_text_field($posted_data['shipping_last_name']),
                'company'    => sanitize_text_field($posted_data['shipping_company'] ?? ''),
                'address_1'  => sanitize_text_field($posted_data['shipping_address_1']),
                'address_2'  => sanitize_text_field($posted_data['shipping_address_2'] ?? ''),
                'city'       => sanitize_text_field($posted_data['shipping_city']),
                'state'      => sanitize_text_field($posted_data['shipping_state']),
                'postcode'   => sanitize_text_field($posted_data['shipping_postcode']),
                'country'    => sanitize_text_field($posted_data['shipping_country'])
            );
        } else {
            $shipping_address = $billing_address;
        }
        $order->set_address($shipping_address, 'shipping');

        // Set payment method
        $order->set_payment_method('paypal_standard_proxy');

        // Set totals from cart (which now includes all proper calculations)
        $order->set_shipping_total(WC()->cart->get_shipping_total());
        $order->set_discount_total(WC()->cart->get_discount_total());
        $order->set_discount_tax(WC()->cart->get_discount_tax());
        $order->set_cart_tax(WC()->cart->get_cart_contents_tax());
        $order->set_shipping_tax(WC()->cart->get_shipping_tax());
        $order->set_total(WC()->cart->get_total('edit'));

        // Calculate and save
        $order->calculate_taxes();
        $order->calculate_totals();
        $order->update_status('pending', __('Order created via Product Express Checkout.', 'woo-paypal-proxy-client'));

        // Get server
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }

        if (!$server) {
            wp_send_json_error(array('message' => __('No PayPal server available', 'woo-paypal-proxy-client')));
        }

        // Store server ID in order
        update_post_meta($order_id, '_wpppc_server_id', $server->id);
        update_post_meta($order_id, '_wpppc_funding_source', 'Standard');

        // Generate security token
        $token = wp_create_nonce('wpppc-standard-order-' . $order_id);
        update_post_meta($order_id, '_wpppc_standard_token', $token);

        // Generate session ID
        $session_id = $this->store_paypal_session($order_id);
        update_post_meta($order_id, '_wpppc_session_id', $session_id);

        // Generate redirect URL for PayPal Standard
        $redirect_url = $this->generate_standard_redirect_url($server, $order);

        // Clear cart after successful order creation
        WC()->cart->empty_cart();

        wp_send_json_success(array(
            'redirect_url' => $redirect_url
        ));
    }
    
    /**
     * Generate Standard redirect URL
     */
    private function generate_standard_redirect_url($server, $order) {
        $redirect_url = $server->url . '/wp-json/wppps/v1/standard-bridge';
        
        // Prepare line items
        $line_items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $line_items[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => $order->get_item_subtotal($item, false),
                'product_id' => $product ? $product->get_id() : 0
            );
        }
        
        // Apply product mappings if personal
        if ($server->is_personal) {
            $line_items = add_product_mappings_to_items($line_items, $server->id);
        }
        
        // Get billing/shipping from order
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

        $params = array(
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'token' => get_post_meta($order->get_id(), '_wpppc_standard_token', true),
            'api_key' => $server->api_key,
            'currency' => $order->get_currency(),
            'amount' => $order->get_total(),
            'shipping' => $order->get_shipping_total(),
            'tax' => $order->get_total_tax(),
            'discount_total' => $order->get_discount_total(),
            'items' => base64_encode(json_encode($line_items)),
            'session_id' => get_post_meta($order->get_id(), '_wpppc_session_id', true),
            'billing_address' => base64_encode(json_encode($billing_address)),
            'shipping_address' => base64_encode(json_encode($shipping_address)),
            'address_override' => '1'
        );
        
        return add_query_arg($params, $redirect_url);
    }

    /**
     * Store PayPal session data
     */
    private function store_paypal_session($order_id) {
        $session_id = wp_generate_password(32, false);
        
        update_option('wpppc_last_session_id', $session_id);
        update_option('wpppc_last_session_order', $order_id);
        
        $transient_name = 'wpppc_paypal_session_' . $session_id;
        set_transient($transient_name, $order_id, HOUR_IN_SECONDS);
        return $session_id;
    }
    
    
    public function add_modal_autocomplete_script() {
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        function initializeModalAutocomplete() {
            if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
                setTimeout(initializeModalAutocomplete, 100);
                return;
            }
            
            setupModalPlaceAutocomplete('billing_address_1');
        }
        
        function setupModalPlaceAutocomplete(inputId) {
            var inputElement = document.getElementById(inputId);
            if (!inputElement) return;
            var selectedStateValue = '';
            
            // Remove existing autocomplete
            var existing = inputElement.parentNode.querySelector('gmp-place-autocomplete');
            if (existing) existing.remove();
            
            // Create PlaceAutocompleteElement
            var autocomplete = document.createElement('gmp-place-autocomplete');
            autocomplete.setAttribute('for-map', 'false');
            autocomplete.setAttribute('type', 'address');
            autocomplete.setAttribute('country-code', 'US');
            
            // Style to match modal
            autocomplete.style.width = '100%';
            autocomplete.style.height = '42px';
            autocomplete.className = inputElement.className;
            
            // Replace original input
            inputElement.parentNode.insertBefore(autocomplete, inputElement);
            inputElement.style.display = 'none';
            
            // Handle place selection
            autocomplete.addEventListener('gmp-select', async function(event) {
                var place = event.placePrediction.toPlace();
                if (!place) return;
                
                try {
                    await place.fetchFields({ 
                        fields: ['displayName', 'formattedAddress', 'addressComponents', 'location'] 
                    });
                    
                    var addressComponents = place.addressComponents;
                    console.log(addressComponents);
                    if (!addressComponents) return;
                    
                    var addressData = {
                        streetNumber: '',
                        route: '',
                        city: '',
                        state: '',
                        country: '',
                        postalCode: ''
                    };
                    
                    addressComponents.forEach(function(component) {
                        var types = component.types;
                        
                        if (types.includes('street_number')) {
                            addressData.streetNumber = component.longText;
                        }
                        if (types.includes('route')) {
                            addressData.route = component.longText;
                        }
                        if (types.includes('locality')) {
                            addressData.city = component.longText;
                        }else if(types.includes('sublocality')) {
                            addressData.city = component.longText;
                        }
                        if (types.includes('administrative_area_level_1')) {
                            addressData.state = component.shortText;
                        }
                        if (types.includes('country')) {
                            addressData.country = component.shortText;
                        }
                        if (types.includes('postal_code')) {
                            addressData.postalCode = component.longText;
                        }
                    });
                    
                    // Fill modal form fields
                    var fullAddress = addressData.streetNumber + 
                        (addressData.streetNumber ? ' ' : '') + 
                        addressData.route;
                    
                    $('#billing_address_1').val(fullAddress).addClass('has-value');
                    $('#billing_city').val(addressData.city).addClass('has-value');
                    $('#billing_postcode').val(addressData.postalCode).addClass('has-value');
                    $('#billing_country').val(addressData.country).addClass('has-value').trigger('change');
                   
                    // Update hidden input
                    inputElement.value = fullAddress;
                    
                    selectedStateValue = addressData.state;
                    //store globally for external access
                    window.selectedStateValue = addressData.state;
                    
                } catch (error) {
                    console.error('Error fetching place details:', error);
                }
            });
        }
        
        // Set state after country states are loaded
        function setStateValue() {
            // Access the stored state value from the outer scope
            var stateValue = window.selectedStateValue || '';
            
            var stateField = $('#billing_state');
            if (stateField.length && stateValue && stateField.find('option[value="' + stateValue + '"]').length > 0) {
                // State options are loaded and our state exists
                if (stateField.is('select')) {
                    stateField.val(stateValue).addClass('has-value').trigger('change');
                } else {
                    stateField.val(stateValue).addClass('has-value');
                }
            } else if (stateField.length && stateValue && !stateField.is('select')) {
                // It's a text field, set directly
                stateField.val(stateValue).addClass('has-value');
            } else if (stateValue) {
                // State options not loaded yet, wait and try again
                setTimeout(setStateValue, 100);
            }
        }
        
        $(document).on('statesLoaded', function() {
            setTimeout(function() {
                setStateValue();
            }, 100);
        });
                
        // Initialize when modal is shown
        $(document).on('modalShown', function() {
            setTimeout(function() {
                initializeModalAutocomplete();
            }, 100);
        });
    });
    </script>
    <?php
}
}

// Initialize
new WPPPC_Product_Page_Express();