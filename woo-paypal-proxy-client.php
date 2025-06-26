<?php
/**
 * Plugin Name: WooCommerce PayPal Proxy Client
 * Plugin URI: https://www.upwork.com/freelancers/~01a6e65817b86d4589
 * Description: Connects to multiple PayPal proxy servers with load balancing
 * Version: 1.1.0
 * Author: Masum Billah
 * Author URI: https://www.upwork.com/freelancers/~01a6e65817b86d4589
 * Text Domain: woo-paypal-proxy-client
 * Domain Path: /languages
 * WC requires at least: 5.0.0
 * WC tested up to: 8.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
add_action('plugins_loaded', 'wpppc_check_decimal_schema', 5); // Run before main init


// Define plugin constants
define('WPPPC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPPPC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPPPC_VERSION', '1.1.0');

/**
 * Check if WooCommerce is active
 */
function wpppc_check_woocommerce_active() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wpppc_woocommerce_missing_notice');
        return false;
    }
    return true;
}

add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Display WooCommerce missing notice
 */
function wpppc_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('WooCommerce PayPal Proxy Client requires WooCommerce to be installed and active.', 'woo-paypal-proxy-client'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function wpppc_init() {
    if (!wpppc_check_woocommerce_active()) {
        return;
    }
    
    // Load required files - make sure server manager comes first
    require_once WPPPC_PLUGIN_DIR . 'includes/class-server-manager.php';
    require_once WPPPC_PLUGIN_DIR . 'includes/class-api-handler.php';
    require_once WPPPC_PLUGIN_DIR . 'includes/class-woo-paypal-gateway.php';
    //require_once WPPPC_PLUGIN_DIR . 'includes/class-admin.php';
    //require_once WPPPC_PLUGIN_DIR . 'includes/class-product-mapping.php';
    
    require_once WPPPC_PLUGIN_DIR . 'includes/class-express-checkout.php';
    require_once WPPPC_PLUGIN_DIR . 'includes/class-paypal-standard-proxy-gateway.php';
require_once WPPPC_PLUGIN_DIR . 'includes/class-standard-proxy-client.php';

//plugin C file
require_once WPPPC_PLUGIN_DIR . 'includes/Redirect-handler-C.php';

// Initialize standard proxy
$standard_proxy = new WPPPC_Standard_Proxy_Client();

// Initialize Express Checkout (will be auto-initialized in its constructor)
new WPPPC_Express_Checkout();
    
    // Initialize classes
    WPPPC_Server_Manager::get_instance(); // Use singleton
    $api_handler = new WPPPC_API_Handler();
    //$admin = new WPPPC_Admin();
    //$product_mapping = new WPPPC_Product_Mapping();
    
    // Add payment gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'wpppc_add_gateway');
    
    // Add scripts and styles
    add_action('wp_enqueue_scripts', 'wpppc_enqueue_scripts');
}
add_action('plugins_loaded', 'wpppc_init');

/**
 * Add PayPal Proxy Gateway to WooCommerce
 */
function wpppc_add_gateway($gateways) {
    $gateways[] = 'WPPPC_PayPal_Gateway';
    return $gateways;
}

/**
 * Enqueue scripts and styles
 */
function wpppc_enqueue_scripts() {
    if (is_checkout()) {
        wp_enqueue_style('wpppc-checkout-style', WPPPC_PLUGIN_URL . 'assets/css/checkout.css', array(), WPPPC_VERSION);
        wp_enqueue_script('wpppc-checkout-script', WPPPC_PLUGIN_URL . 'assets/js/checkout.js', array('jquery'), WPPPC_VERSION, true);
        
        // Add localized data for the script
        wp_localize_script('wpppc-checkout-script', 'wpppc_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpppc-nonce'),
        ));
    }
}

/**
 * Update database schema to fix missing columns
 */
function wpppc_update_db_schema() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpppc_proxy_servers';
    
    // Check if table exists
    if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        // Check if columns exist
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
        $column_names = array_map(function($col) { return $col->Field; }, $columns);
        
        $missing_columns = [];
        
        // Check for required columns
        if(!in_array('is_active', $column_names)) {
            $missing_columns[] = "ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1";
        }
        
        if(!in_array('current_usage', $column_names)) {
            $missing_columns[] = "ADD COLUMN `current_usage` int(11) NOT NULL DEFAULT 0";
        }
        
        if(!in_array('capacity_limit', $column_names)) {
            $missing_columns[] = "ADD COLUMN `capacity_limit` int(11) NOT NULL DEFAULT 1000";
        }
        
        if(!in_array('priority', $column_names)) {
            $missing_columns[] = "ADD COLUMN `priority` int(11) NOT NULL DEFAULT 0";
        }
        
        if(!in_array('last_used', $column_names)) {
            $missing_columns[] = "ADD COLUMN `last_used` timestamp NULL DEFAULT NULL";
        }
        
        if(!in_array('is_selected', $column_names)) {
            $missing_columns[] = "ADD COLUMN `is_selected` tinyint(1) NOT NULL DEFAULT 0";
        }
        
        // Check if is_personal column exists
        if(!in_array('is_personal', $column_names)) {
            $missing_columns[] = "ADD COLUMN `is_personal` tinyint(1) NOT NULL DEFAULT 0";
        }
        
        // Add missing columns if any
        if(!empty($missing_columns)) {
            $wpdb->query("ALTER TABLE $table_name " . implode(", ", $missing_columns));
            
            // If we added the is_selected column, make sure one server is selected
            if(!in_array('is_selected', $column_names)) {
                // Select the first active server
                $first_server = $wpdb->get_var("SELECT id FROM $table_name WHERE is_active = 1 ORDER BY priority ASC, id ASC LIMIT 1");
                if($first_server) {
                    $wpdb->update($table_name, array('is_selected' => 1), array('id' => $first_server));
                } else {
                    // If no active server, select any server
                    $any_server = $wpdb->get_var("SELECT id FROM $table_name ORDER BY id ASC LIMIT 1");
                    if($any_server) {
                        $wpdb->update($table_name, array('is_selected' => 1), array('id' => $any_server));
                    }
                }
            }
        }
    }
}

/**
 * AJAX handler for validating checkout fields
 */
function wpppc_validate_checkout_fields() {
    check_ajax_referer('wpppc-nonce', 'nonce');
    
    $errors = array();
    $posted_data = $_POST;
    
    // Get checkout fields
    $fields = WC()->checkout()->get_checkout_fields();
    
    // Check if shipping to a different address
    $ship_to_different_address = !empty($posted_data['ship_to_different_address']);
    
    // Check if creating an account
    $create_account = !empty($posted_data['createaccount']);
    
    // Initialize WooCommerce validation class
    $validation = new WC_Validation();
    
    // Loop through field groups
    foreach ($fields as $fieldset_key => $fieldset) {
        // Skip shipping fields if not shipping to a different address
        if ($fieldset_key === 'shipping' && !$ship_to_different_address) {
            continue;
        }
        
        // Skip account fields if not creating an account
        if ($fieldset_key === 'account' && !$create_account) {
            continue;
        }
        
        foreach ($fieldset as $key => $field) {
            $value = isset($posted_data[$key]) ? trim($posted_data[$key]) : '';
            
            // Check if required and empty
            if (!empty($field['required']) && empty($value)) {
                $errors[$key] = sprintf(__('%s is a required field.', 'woocommerce'), $field['label']);
                continue; // Skip further validation if empty
            }
            
            // If not empty and has validation rules, check them
            if (!empty($value) && !empty($field['validate'])) {
                foreach ($field['validate'] as $validation_type) {
                    switch ($validation_type) {
                        case 'postcode':
                            // Determine the country based on fieldset
                            $country = '';
                            if ($fieldset_key === 'billing') {
                                $country = isset($posted_data['billing_country']) ? $posted_data['billing_country'] : '';
                            } elseif ($fieldset_key === 'shipping') {
                                $country = isset($posted_data['shipping_country']) ? $posted_data['shipping_country'] : '';
                            }
                            if ($country && !$validation->is_postcode($value, $country)) {
                                $errors[$key] = sprintf(__('%s is not a valid postcode / ZIP.', 'woocommerce'), $field['label']);
                            }
                            break;
                        case 'email':
                            if (!$validation->is_email($value)) {
                                $errors[$key] = sprintf(__('%s is not a valid email address.', 'woocommerce'), $field['label']);
                            }
                            break;
                        case 'phone':
                            if (!$validation->is_phone($value)) {
                                $errors[$key] = sprintf(__('%s is not a valid phone number.', 'woocommerce'), $field['label']);
                            }
                            break;
                        // Add other validation types as needed
                    }
                }
            }
        }
    }
    
    if (empty($errors)) {
        wp_send_json_success(array('valid' => true));
    } else {
        wp_send_json_error(array('valid' => false, 'errors' => $errors));
    }
    
    wp_die();
}
add_action('wp_ajax_wpppc_validate_checkout', 'wpppc_validate_checkout_fields');
add_action('wp_ajax_nopriv_wpppc_validate_checkout', 'wpppc_validate_checkout_fields');

/**
 * Add settings link on plugin page
 */
function wpppc_settings_link($links) {
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=paypal_proxy">' . __('Settings', 'woo-paypal-proxy-client') . '</a>';
    $servers_link = '<a href="admin.php?page=wpppc-servers">' . __('Servers', 'woo-paypal-proxy-client') . '</a>';
    array_unshift($links, $settings_link, $servers_link);
    return $links;
}
$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'wpppc_settings_link');

/**
 * Plugin activation hook
 */
function wpppc_activate() {
    
    
    // Create product mapping table
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpppc_product_mappings';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        server_product_id bigint(20) NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY product_id (product_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Clean up old option if it exists
$gateway_settings = get_option('woocommerce_paypal_proxy_settings', array());
if (isset($gateway_settings['enable_standard'])) {
    unset($gateway_settings['enable_standard']);
    update_option('woocommerce_paypal_proxy_settings', $gateway_settings);
}
    
    // Create servers table
    // We need to include the class file explicitly during activation
    require_once WPPPC_PLUGIN_DIR . 'includes/class-server-manager.php';
    WPPPC_Server_Manager::create_table();
    
    // Update schema for existing tables
    wpppc_update_db_schema();
    
    //product pool column to server table
    wpppc_update_server_table_for_product_pool();
}
register_activation_hook(__FILE__, 'wpppc_activate');

/**
 * Update database schema to handle decimal usage amounts
 */
function wpppc_update_db_schema_for_amounts() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpppc_proxy_servers';
    
    // Check if table exists
    if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        // Check current usage column type
        $column_info = $wpdb->get_row("SHOW COLUMNS FROM {$table_name} LIKE 'current_usage'");
        
        // If column exists and needs to be updated to decimal type
        if ($column_info && strpos($column_info->Type, 'decimal') === false) {
            // Change column type from int to decimal to handle money amounts
            $wpdb->query("ALTER TABLE {$table_name} MODIFY COLUMN `current_usage` decimal(10,2) NOT NULL DEFAULT 0");
            
            // Also update capacity_limit to decimal for consistency
            $wpdb->query("ALTER TABLE {$table_name} MODIFY COLUMN `capacity_limit` decimal(10,2) NOT NULL DEFAULT 1000");
            
            //if (WP_DEBUG) {
                //error_log('PayPal Proxy - Updated database schema for decimal usage amounts');
            //}
        }
    }
}

/**
 * Run schema update when plugin is loaded
 * This helps fix issues with existing installations
 */
function wpppc_maybe_update_db() {
    // Check if we need to update the database
    if (get_option('wpppc_db_version') != WPPPC_VERSION) {
        wpppc_update_db_schema();
        wpppc_update_db_schema_for_amounts(); 
        wpppc_update_server_table_for_product_pool();
        update_option('wpppc_db_version', WPPPC_VERSION);
    }
}
add_action('plugins_loaded', 'wpppc_maybe_update_db', 5); // Run before main init

/**
 * AJAX handler for creating a WooCommerce order with detailed line items
 */
function wpppc_create_order_handler() {
    // Log all incoming data for debugging
    if (WP_DEBUG) {
        error_log('PayPal Proxy Client - Incoming AJAX data: ' . print_r($_POST, true));
    }
    
    try {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpppc-nonce')) {
            error_log('PayPal Proxy Client - Invalid nonce');
            wp_send_json_error(array(
                'message' => 'Security check failed'
            ));
            wp_die();
        }
        
        // Get the server manager and use the selected server
        require_once WPPPC_PLUGIN_DIR . 'includes/class-server-manager.php';
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        if (!$server) {
            wp_send_json_error(array(
                'message' => 'No available PayPal proxy server found'
            ));
            wp_die();
        }
        
        // Generate a unique temporary order ID (timestamp + random)
        $temp_order_id = time() . '_' . wp_rand(1000, 9999);
        $temp_order_key = 'wpppc_temp_' . $temp_order_id;
        
        // Get and set complete billing address with all fields
        $complete_billing = array(
            'first_name' => !empty($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : 'Test',
            'last_name'  => !empty($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : 'User',
            'email'      => !empty($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : 'test@example.com',
            'phone'      => !empty($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '',
            'address_1'  => !empty($_POST['billing_address_1']) ? sanitize_text_field($_POST['billing_address_1']) : '',
            'address_2'  => !empty($_POST['billing_address_2']) ? sanitize_text_field($_POST['billing_address_2']) : '',
            'city'       => !empty($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : '',
            'state'      => !empty($_POST['billing_state']) ? sanitize_text_field($_POST['billing_state']) : '',
            'postcode'   => !empty($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '',
            'country'    => !empty($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : '',
        );

        // Check if shipping to different address
        $ship_to_different_address = !empty($_POST['ship_to_different_address']);
        if ($ship_to_different_address) {
            // Use shipping address fields
            $complete_shipping = array(
                'first_name' => !empty($_POST['shipping_first_name']) ? sanitize_text_field($_POST['shipping_first_name']) : $complete_billing['first_name'],
                'last_name'  => !empty($_POST['shipping_last_name']) ? sanitize_text_field($_POST['shipping_last_name']) : $complete_billing['last_name'],
                'address_1'  => !empty($_POST['shipping_address_1']) ? sanitize_text_field($_POST['shipping_address_1']) : $complete_billing['address_1'],
                'address_2'  => !empty($_POST['shipping_address_2']) ? sanitize_text_field($_POST['shipping_address_2']) : $complete_billing['address_2'],
                'city'       => !empty($_POST['shipping_city']) ? sanitize_text_field($_POST['shipping_city']) : $complete_billing['city'],
                'state'      => !empty($_POST['shipping_state']) ? sanitize_text_field($_POST['shipping_state']) : $complete_billing['state'],
                'postcode'   => !empty($_POST['shipping_postcode']) ? sanitize_text_field($_POST['shipping_postcode']) : $complete_billing['postcode'],
                'country'    => !empty($_POST['shipping_country']) ? sanitize_text_field($_POST['shipping_country']) : $complete_billing['country'],
            );
        } else {
            // Copy from billing address
            $complete_shipping = $complete_billing;
        }
        
        // *** SHIPPING HANDLING - IMPORTANT ***
        // Get chosen shipping methods from the session
        $chosen_shipping_methods = WC()->session ? WC()->session->get('chosen_shipping_methods') : array();
        $shipping_total = 0;
        $shipping_tax = 0;
        $shipping_items = array();
        
        // Process shipping methods
        if (!empty($chosen_shipping_methods)) {
            WC()->shipping->calculate_shipping(WC()->cart->get_shipping_packages());
            $packages = WC()->shipping->get_packages();
            
            foreach ($packages as $package_key => $package) {
                if (isset($chosen_shipping_methods[$package_key], $package['rates'][$chosen_shipping_methods[$package_key]])) {
                    $shipping_rate = $package['rates'][$chosen_shipping_methods[$package_key]];
                    
                    // Store shipping data
                    $shipping_items[] = array(
                        'method_title' => $shipping_rate->get_label(),
                        'method_id'    => $shipping_rate->get_method_id(),
                        'total'        => wc_format_decimal($shipping_rate->get_cost()),
                        'taxes'        => $shipping_rate->get_taxes(),
                        'instance_id'  => $shipping_rate->get_instance_id(),
                        'meta_data'    => $shipping_rate->get_meta_data()
                    );
                    
                    $shipping_total += $shipping_rate->get_cost();
                    $shipping_tax += array_sum($shipping_rate->get_taxes());
                }
            }
            
            // Fallback for flat rate shipping
            if (empty($shipping_items) && !empty($chosen_shipping_methods[0]) && strpos($chosen_shipping_methods[0], 'flat_rate') !== false) {
                $shipping_total = WC()->cart->get_shipping_total();
                $shipping_tax = WC()->cart->get_shipping_tax();
                
                if ($shipping_total > 0) {
                    $shipping_items[] = array(
                        'method_title' => 'Flat rate shipping',
                        'method_id'    => 'flat_rate',
                        'total'        => wc_format_decimal($shipping_total),
                        'taxes'        => array('total' => array($shipping_tax)),
                    );
                }
            }
            
            // Special handling for free shipping
            if (empty($shipping_items) && !empty($chosen_shipping_methods[0]) && strpos($chosen_shipping_methods[0], 'free_shipping') !== false) {
                foreach ($packages as $package_key => $package) {
                    foreach ($package['rates'] as $rate_id => $rate) {
                        if ($rate->get_method_id() === 'free_shipping') {
                            $shipping_items[] = array(
                                'method_title' => $rate->get_label(),
                                'method_id'    => 'free_shipping',
                                'total'        => '0.00',
                                'taxes'        => array(),
                                'instance_id'  => $rate->get_instance_id(),
                            );
                            break 2;
                        }
                    }
                }
                
                // Fallback if rate not found
                if (empty($shipping_items)) {
                    $shipping_items[] = array(
                        'method_title' => 'Free Shipping',
                        'method_id'    => 'free_shipping',
                        'total'        => '0.00',
                        'taxes'        => array(),
                    );
                }
            }
        }
        
        // Prepare line items array
        $line_items = array();
        $cart_items = array();
        $cart_subtotal = 0;
        $tax_total = 0;
        
        // Process cart items
        if (WC()->cart->is_empty()) {
            // For testing, add a dummy product if cart is empty
            $line_items[] = array(
                'name' => 'Test Product',
                'quantity' => 1,
                'unit_price' => 10.00,
                'tax_amount' => 0,
                'sku' => 'TEST-1',
                'description' => 'Test product for testing'
            );
            
            $cart_items[] = array(
                'product_id' => 0,
                'variation_id' => 0,
                'quantity' => 1,
                'name' => 'Test Product',
                'subtotal' => 10.00,
                'total' => 10.00,
                'subtotal_tax' => 0,
                'total_tax' => 0,
                'taxes' => array(),
                'variation' => array(),
                'meta_data' => array()
            );
            
            $cart_subtotal = 10.00;
        } else {
            // Process real cart items
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                
                // Get product details
                $name = $product->get_name();
                $quantity = $cart_item['quantity'];
                $price = $cart_item['line_subtotal'] / $quantity;
                $tax = $cart_item['line_tax'] / $quantity;
                
                // Store cart item data
                $cart_items[] = array(
                    'cart_item_key' => $cart_item_key,
                    'product_id' => $product->get_id(),
                    'variation_id' => !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : 0,
                    'quantity' => $quantity,
                    'name' => $product->get_name(),
                    'subtotal' => $cart_item['line_subtotal'],
                    'total' => $cart_item['line_total'],
                    'subtotal_tax' => isset($cart_item['line_subtotal_tax']) ? $cart_item['line_subtotal_tax'] : 0,
                    'total_tax' => isset($cart_item['line_tax']) ? $cart_item['line_tax'] : 0,
                    'taxes' => isset($cart_item['line_tax_data']) ? $cart_item['line_tax_data'] : array(),
                    'variation' => !empty($cart_item['variation']) ? $cart_item['variation'] : array(),
                    'cart_item' => $cart_item // Store full cart item for hooks later
                );
                
                // Add to line items array for PayPal
                $line_items[] = array(
                    'name' => $name,
                    'quantity' => $quantity,
                    'unit_price' => wc_format_decimal($price, 2),
                    'tax_amount' => wc_format_decimal(isset($cart_item['line_tax']) ? $cart_item['line_tax'] : 0, 2),
                    'sku' => $product->get_sku() ? $product->get_sku() : 'SKU-' . $product->get_id(),
                    'description' => $product->get_short_description() ? substr(wp_strip_all_tags($product->get_short_description()), 0, 127) : '',
                    'product_id' => $product->get_id()
                );
                
                $cart_subtotal += $cart_item['line_subtotal'];
                $tax_total += isset($cart_item['line_tax']) ? $cart_item['line_tax'] : 0;
            }
            
            // Pass server ID to the mapping function
            $line_items = add_product_mappings_to_items($line_items, $server->id);
        }
        
        // Calculate totals (mimicking WooCommerce calculation)
        $total_amount = $cart_subtotal + $tax_total + $shipping_total + $shipping_tax;
        
        // Store all order data in transient (expires in 1 hour)
        $order_data = array(
            'temp_order_id' => $temp_order_id,
            'temp_order_key' => $temp_order_key,
            'server_id' => $server->id,
            'billing_address' => $complete_billing,
            'shipping_address' => $complete_shipping,
            'shipping_items' => $shipping_items,
            'chosen_shipping_methods' => $chosen_shipping_methods,
            'cart_items' => $cart_items,
            'line_items' => $line_items,
            'subtotal' => $cart_subtotal,
            'tax_total' => $tax_total,
            'shipping_total' => $shipping_total,
            'shipping_tax' => $shipping_tax,
            'total_amount' => $total_amount,
            'currency' => get_woocommerce_currency(),
            'prices_include_tax' => wc_prices_include_tax(),
            'tax_display_cart' => get_option('woocommerce_tax_display_cart'),
            'tax_display_shop' => get_option('woocommerce_tax_display_shop'),
            'created_time' => current_time('timestamp'),
            'post_data' => $_POST // Store original POST data for any additional processing
        );
        
        // Store in transient (1 hour expiration)
        //set_transient('wpppc_temp_order_' . $temp_order_id, $order_data, HOUR_IN_SECONDS);
        
        $transient_key = 'wpppc_temp_order_' . $temp_order_id;
        $existing_data = get_transient($transient_key);
        
        if (!is_array($existing_data)) $existing_data = [];
        
        $order_data = array_merge($existing_data, $order_data);
        
        set_transient($transient_key, $order_data, HOUR_IN_SECONDS);
        
        // Get server credentials from the selected server object 
        $proxy_url = $server->url;
        $api_key = $server->api_key;
        $api_secret = $server->api_secret;
        $server_id = $server->id;

        // Create order details array with all information needed by PayPal
        $order_details = array(
            'api_key' => $api_key,
            'server_id' => $server_id,
            'order_id' => $temp_order_id, // Use temp order ID
            'test_data' => !empty($_POST['paypal_test_data']) ? 
                sanitize_text_field($_POST['paypal_test_data']) : 'Order #' . $temp_order_id,
            'shipping_address' => $complete_shipping,
            'billing_address' => $complete_billing,
            'line_items' => $line_items,
            'shipping_amount' => $shipping_total,
            'shipping_tax' => $shipping_tax,
            'tax_total' => $tax_total + $shipping_tax,
            'discount_total' => WC()->cart->get_discount_total(),
            'currency' => get_woocommerce_currency(),
            'prices_include_tax' => wc_prices_include_tax(),
            'tax_display_cart' => get_option('woocommerce_tax_display_cart'),
            'tax_display_shop' => get_option('woocommerce_tax_display_shop')
        );

        // Generate security hash for the request
        $timestamp = time();
        $hash_data = $timestamp . $temp_order_id . $api_key;
        $hash = hash_hmac('sha256', $hash_data, $api_secret);

        // Add security parameters
        $order_details['timestamp'] = $timestamp;
        $order_details['hash'] = $hash;

        // Send the order details to the storage endpoint
        if (!empty($proxy_url) && !empty($api_key)) {
            error_log('PayPal Proxy Client - Sending order details to: ' . $proxy_url . '/wp-json/wppps/v1/store-test-data');
            
            $response = wp_remote_post(
                $proxy_url . '/wp-json/wppps/v1/store-test-data',
                array(
                    'method' => 'POST',
                    'timeout' => 30,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode($order_details)
                )
            );
            
            if (is_wp_error($response)) {
                error_log('PayPal Proxy Client - Error sending order details: ' . $response->get_error_message());
            } else {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                error_log('PayPal Proxy Client - Data response: ' . print_r($body, true));
            }
        } else {
            error_log('PayPal Proxy Client - Missing proxy URL or API key, cannot send order details');
        }
        
        if (WP_DEBUG) {
            error_log('PayPal Proxy Client - Temporary order data stored successfully: #' . $temp_order_id);
        }
        
        // Return success with temporary order details (same structure as before)
        wp_send_json_success(array(
            'order_id'   => $temp_order_id,
            'order_key'  => $temp_order_key,
            'proxy_data' => array(
                'server_name' => $server->name,
                'server_id' => $server->id,
                'message' => 'Order data prepared successfully'
            ),
        ));
        
    } catch (Exception $e) {
        error_log('PayPal Proxy Client - Error preparing order: ' . $e->getMessage());
        if (WP_DEBUG) {
            error_log('PayPal Proxy Client - Error trace: ' . $e->getTraceAsString());
        }
        wp_send_json_error(array(
            'message' => 'Failed to prepare order: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}

add_action('wp_ajax_wpppc_create_order', 'wpppc_create_order_handler');
add_action('wp_ajax_nopriv_wpppc_create_order', 'wpppc_create_order_handler');

/**
 * Get product mapping status for a product
 */
function wpppc_get_product_mapping_status($product_id) {
    if (!class_exists('WPPPC_Product_Mapping')) {
        return false;
    }
    
    $product_mapping = new WPPPC_Product_Mapping();
    $mapping = $product_mapping->get_product_mapping($product_id);
    
    if ($mapping) {
        return intval($mapping);
    }
    
    return false;
}


/**
 * Add product mappings to line items
 */

function add_product_mappings_to_items($line_items, $server_id = 0) {
    if (empty($line_items) || !$server_id) {
        return $line_items;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpppc_proxy_servers';
    
    // Get product ID pool for this server
    $product_id_pool = $wpdb->get_var($wpdb->prepare(
        "SELECT product_id_pool FROM {$table_name} WHERE id = %d",
        $server_id
    ));
    
    if (empty($product_id_pool)) {
        wpppc_log("No product ID pool found for server ID: $server_id");
        return $line_items;
    }
    
    // Parse comma-separated list into array and trim whitespace
    $product_ids = array_map('trim', explode(',', $product_id_pool));
    $product_ids = array_filter($product_ids); // Remove empty values
    
    if (empty($product_ids)) {
        wpppc_log("Product ID pool is empty for server ID: $server_id");
        return $line_items;
    }
    
    wpppc_log("Product ID pool for server $server_id: " . print_r($product_ids, true));
    
    // Shuffle the product IDs for randomness
    shuffle($product_ids);
    
    // Count unique products in order
    $unique_products = count($line_items);
    
    // Check if we have enough product IDs in the pool
    $available_ids = count($product_ids);
    $needed_ids = min($available_ids, $unique_products);
    
    wpppc_log("Order has $unique_products unique products, using $needed_ids IDs from pool");
    
    // Get the IDs we'll use (might be fewer than line items if pool is smaller)
    $selected_ids = array_slice($product_ids, 0, $needed_ids);
    
    // If we have more line items than IDs, repeat the IDs
    if ($unique_products > $needed_ids) {
        $additional_needed = $unique_products - $needed_ids;
        
        // Repeat IDs if necessary
        for ($i = 0; $i < $additional_needed; $i++) {
            $selected_ids[] = $product_ids[$i % $available_ids];
        }
    }
    
    // Assign IDs to line items
    foreach ($line_items as $index => &$item) {
        if (isset($selected_ids[$index])) {
            $item['mapped_product_id'] = $selected_ids[$index];
            wpppc_log("Mapped line item " . ($index + 1) . " to product ID: " . $selected_ids[$index]);
        }
    }
    
    return $line_items;
}


/**
 * AJAX handler for completing an order after payment
 */
function wpppc_complete_order_handler() {
    // Log all request data for debugging
    if (WP_DEBUG) {
        error_log('PayPal Proxy - Complete Order Request: ' . print_r($_POST, true));
    }
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpppc-nonce')) {
        error_log('PayPal Proxy - Invalid nonce in complete order request');
        wp_send_json_error(array(
            'message' => 'Security check failed'
        ));
        wp_die();
    }
    
    $temp_order_id = isset($_POST['order_id']) ? sanitize_text_field($_POST['order_id']) : '';
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    $transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field($_POST['transaction_id']) : '';
    $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
    
    wpppc_log("Initial server_id from request: $server_id");
    
    if (!$temp_order_id || !$paypal_order_id) {
        error_log('PayPal Proxy - Invalid order data in completion request');
        wp_send_json_error(array(
            'message' => __('Invalid order data', 'woo-paypal-proxy-client')
        ));
        wp_die();
    }
    
    // Get the stored order data from transient
    $order_data = get_transient('wpppc_temp_order_' . $temp_order_id);
    
    if (!$order_data) {
        error_log('PayPal Proxy - Temporary order data not found or expired: ' . $temp_order_id);
        wp_send_json_error(array(
            'message' => __('Order data not found or expired', 'woo-paypal-proxy-client')
        ));
        wp_die();
    }
    
    if (!$server_id && !empty($order_data['server_id'])) {
        $server_id = $order_data['server_id'];
        wpppc_log("Retrieved server_id from order data: $server_id");
    }
    
    try {
        // Log order details
        if (WP_DEBUG) {
            error_log('PayPal Proxy - Processing temp order: ' . $temp_order_id);
            error_log('PayPal Proxy - Server ID: ' . $server_id);
            error_log('PayPal Proxy - Order total: ' . $order_data['total_amount']);
        }
        
        // NOW CREATE THE ACTUAL WOOCOMMERCE ORDER
        $order = wc_create_order();
        
        // Set addresses
        $order->set_address($order_data['billing_address'], 'billing');
        $order->set_address($order_data['shipping_address'], 'shipping');
        
        // Add shipping items
        if (!empty($order_data['shipping_items'])) {
            foreach ($order_data['shipping_items'] as $shipping_item_data) {
                $item = new WC_Order_Item_Shipping();
                $item->set_props(array(
                    'method_title' => $shipping_item_data['method_title'],
                    'method_id'    => $shipping_item_data['method_id'],
                    'total'        => $shipping_item_data['total'],
                    'taxes'        => $shipping_item_data['taxes'],
                    'instance_id'  => isset($shipping_item_data['instance_id']) ? $shipping_item_data['instance_id'] : '',
                ));
                
                // Add any meta data
                if (!empty($shipping_item_data['meta_data'])) {
                    foreach ($shipping_item_data['meta_data'] as $key => $value) {
                        $item->add_meta_data($key, $value, true);
                    }
                }
                
                $order->add_item($item);
            }
        }
        
        // Store chosen shipping methods for hook reference
        if (!empty($order_data['chosen_shipping_methods'])) {
            $order->update_meta_data('_wpppc_shipping_methods', $order_data['chosen_shipping_methods']);
        }
        
        // Create a session key to store the order ID for hook reference
        $session_order_key = 'wpppc_temp_order_id';
        WC()->session->set($session_order_key, $order->get_id());
        
        // Add cart items with proper hook triggering
        foreach ($order_data['cart_items'] as $cart_item_data) {
            // Create the line item
            $item = new WC_Order_Item_Product();
            
            // Set basic properties
            $item->set_props(array(
                'product_id'   => $cart_item_data['product_id'],
                'variation_id' => $cart_item_data['variation_id'],
                'quantity'     => $cart_item_data['quantity'],
                'subtotal'     => $cart_item_data['subtotal'],
                'total'        => $cart_item_data['total'],
                'subtotal_tax' => $cart_item_data['subtotal_tax'],
                'total_tax'    => $cart_item_data['total_tax'],
                'taxes'        => $cart_item_data['taxes'],
            ));
            
            $item->set_name($cart_item_data['name']);
            
            // Add variation data
            if (!empty($cart_item_data['variation'])) {
                foreach ($cart_item_data['variation'] as $meta_name => $meta_value) {
                    $item->add_meta_data(str_replace('attribute_', '', $meta_name), $meta_value, true);
                }
            }
            
            // CRITICAL: Run the WooCommerce hook that WAPF and other plugins use
            // to add their custom data to order line items
            if (!empty($cart_item_data['cart_item'])) {
                do_action('woocommerce_checkout_create_order_line_item', $item, $cart_item_data['cart_item_key'], $cart_item_data['cart_item'], $order);
            }
            
            // Add the item to the order
            $order->add_item($item);
        }
        
        // Run the hook that WAPF might use after all items are added
        do_action('woocommerce_checkout_create_order', $order, array());
        
        // Trigger the order meta hook that some plugins use
        do_action('woocommerce_checkout_update_order_meta', $order->get_id(), array());
        
        // Clean up session
        WC()->session->__unset($session_order_key);
        
        // Set payment method
        $order->set_payment_method('paypal_proxy');
        
        // Calculate totals
        $order->calculate_shipping();
        $order->calculate_totals();
        
        // Store the server ID in the order meta
        $order->update_meta_data('_wpppc_server_id', $server_id);
        
        // Store the temporary order ID for reference
        $order->update_meta_data('_wpppc_temp_order_id', $temp_order_id);
        
        // Complete the order payment immediately (since payment is already confirmed)
        $order->payment_complete($transaction_id);
        
        $gateway = new WPPPC_PayPal_Gateway();
        $result  = $gateway->get_seller_protection($paypal_order_id, $server_id);
        
        $seller_protection = $result['seller_protection'] ?? 'UNKNOWN';
        $account_status = $result['account_status'] ?? 'UNKNOWN';
        
        error_log('WPPPC Debug: Retrieved seller protection from endpoint: ' . $seller_protection);
        
        // Add order note
        $order->add_order_note(
            sprintf(
                __('PayPal payment completed. PayPal Order ID: %s, Transaction ID: %s, Server ID: %s, Seller Protection: %s, Account Status: %s', 'woo-paypal-proxy-client'),
                $paypal_order_id,
                $transaction_id,
                $server_id ? $server_id : 'N/A',
                $seller_protection,
                $account_status
            )
        );
        
        // Update status to processing
        $order->update_status('processing');
        
        // Store PayPal transaction details
        update_post_meta($order->get_id(), '_paypal_order_id', $paypal_order_id);
        update_post_meta($order->get_id(), '_paypal_transaction_id', $transaction_id);
        update_post_meta($order->get_id(), '_paypal_seller_protection', $seller_protection);
        update_post_meta($order->get_id(), '_paypal_account_status', $account_status);
        
        if (!empty($order_data['funding_source'])) {
            update_post_meta($order->get_id(), '_wpppc_funding_source', sanitize_text_field($order_data['funding_source']));
        }
        
        // Save the order
        $order->save();
        
        // Mirror order to server
        $api_handler = new WPPPC_API_Handler($server_id);
        $mirror_response = $api_handler->mirror_order_to_server($order, $paypal_order_id, $transaction_id);
        
        if ($server_id) {
            // Get the order total amount
            $order_amount = floatval($order->get_total());
            error_log('PayPal Proxy - Order amount to add to usage: ' . $order_amount);
            
            // Get server manager instance
            require_once WPPPC_PLUGIN_DIR . 'includes/class-server-manager.php';
            $server_manager = WPPPC_Server_Manager::get_instance();
            
            // Update usage with order amount
            $result = $server_manager->add_server_usage($server_id, $order_amount);
            error_log('PayPal Proxy - Result of add_server_usage: ' . ($result ? 'success' : 'failed'));
            
            error_log('PayPal Proxy - Added ' . $order_amount . ' to server usage for server ID ' . $server_id);
        } else {
            error_log('PayPal Proxy - No server_id found, cannot add usage');
        }
        
        // Clean up the transient now that we have a real order
        delete_transient('wpppc_temp_order_' . $temp_order_id);
        
        // Empty cart
        WC()->cart->empty_cart();
        
        // Log the success
        if (WP_DEBUG) {
            error_log('PayPal Proxy - Order successfully created and completed: ' . $order->get_id());
        }
        
        // Return success with redirect URL
        $redirect_url = $order->get_checkout_order_received_url();
        wp_send_json_success(array(
            'redirect' => $redirect_url,
            'order_id' => $order->get_id(), // Return real order ID
            'temp_order_id' => $temp_order_id // Also return temp ID for reference
        ));
        
    } catch (Exception $e) {
        error_log('PayPal Proxy - Exception during order completion: ' . $e->getMessage());
        
        // Clean up transient on error
        delete_transient('wpppc_temp_order_' . $temp_order_id);
        
        wp_send_json_error(array(
            'message' => 'Error completing order: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}

// Register the AJAX handlers
add_action('wp_ajax_wpppc_complete_order', 'wpppc_complete_order_handler');
add_action('wp_ajax_nopriv_wpppc_complete_order', 'wpppc_complete_order_handler');

/**
 * Log debug messages
 */
function wpppc_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        if (is_array($message) || is_object($message)) {
            error_log('WPPPC Debug: ' . print_r($message, true));
        } else {
            error_log('WPPPC Debug: ' . $message);
        }
    }
}


function wpppc_check_decimal_schema() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpppc_proxy_servers';
    
    error_log('Schema Check - Checking table structure for ' . $table_name);
    
    // Check if the table exists
    if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
        // Get the current_usage column type
        $column_info = $wpdb->get_row("SHOW COLUMNS FROM $table_name LIKE 'current_usage'");
        
        if($column_info) {
            error_log('Schema Check - current_usage column type: ' . $column_info->Type);
            
            // If it's not decimal, convert it
            if(strpos(strtolower($column_info->Type), 'decimal') === false) {
                error_log('Schema Check - Converting current_usage to decimal type');
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN current_usage decimal(10,2) NOT NULL DEFAULT 0");
                
                // Check if it worked
                $new_column_info = $wpdb->get_row("SHOW COLUMNS FROM $table_name LIKE 'current_usage'");
                error_log('Schema Check - New current_usage column type: ' . $new_column_info->Type);
            }
        } else {
            error_log('Schema Check - current_usage column not found!');
        }
        
        // Also check the capacity_limit column
        $column_info = $wpdb->get_row("SHOW COLUMNS FROM $table_name LIKE 'capacity_limit'");
        
        if($column_info) {
            error_log('Schema Check - capacity_limit column type: ' . $column_info->Type);
            
            // If it's not decimal, convert it
            if(strpos(strtolower($column_info->Type), 'decimal') === false) {
                error_log('Schema Check - Converting capacity_limit to decimal type');
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN capacity_limit decimal(10,2) NOT NULL DEFAULT 1000");
                
                // Check if it worked
                $new_column_info = $wpdb->get_row("SHOW COLUMNS FROM $table_name LIKE 'capacity_limit'");
                error_log('Schema Check - New capacity_limit column type: ' . $new_column_info->Type);
            }
        } else {
            error_log('Schema Check - capacity_limit column not found!');
        }
    } else {
        error_log('Schema Check - Table not found: ' . $table_name);
    }
}


function wpppc_no_servers_notice() {
    if (!current_user_can('manage_woocommerce')) return;
    
    // Check if any servers exist
    $server_manager = WPPPC_Server_Manager::get_instance();
    $servers = $server_manager->get_all_servers();
    
    if (empty($servers)) {
        echo '<div class="error"><p>' . 
             __('PayPal Proxy: No servers configured. Please <a href="admin.php?page=wpppc-servers">add a server</a> to enable PayPal payments.', 'woo-paypal-proxy-client') . 
             '</p></div>';
    }
}
add_action('admin_notices', 'wpppc_no_servers_notice');

function wpppc_update_server_table_for_product_pool() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpppc_proxy_servers';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'product_id_pool'");
        
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN `product_id_pool` TEXT NULL");
            wpppc_log('Added product_id_pool column to server table');
        }
    }
}


/**
 * Format money amount for display
 */
function wpppc_format_money($amount, $currency = '') {
    $currency = $currency ?: get_woocommerce_currency();
    
    if (function_exists('wc_price')) {
        return wc_price($amount, array('currency' => $currency));
    }
    
    return number_format($amount, 2) . ' ' . $currency;
}

/**
 * Calculate shipping methods for address
 */
function wpppc_calculate_shipping_for_address($address) {
    wpppc_log("Calculating shipping for address: " . json_encode($address));
    
    // Create a shipping package
    $package = array(
        'contents' => WC()->cart->get_cart(),
        'contents_cost' => WC()->cart->get_cart_contents_total(),
        'applied_coupons' => WC()->cart->get_applied_coupons(),
        'destination' => array(
            'country' => $address['country'],
            'state' => $address['state'],
            'postcode' => $address['postcode'],
            'city' => $address['city'],
            'address' => $address['address_1'],
            'address_2' => $address['address_2']
        )
    );
    
    // Reset current shipping methods
    WC()->shipping()->reset_shipping();
    
    // Get available shipping methods
    $shipping_methods = WC()->shipping()->calculate_shipping(array($package));
    
    wpppc_log("Found " . count($shipping_methods[0]['rates']) . " shipping methods");
    
    // Format shipping options for PayPal
    $shipping_options = array();
    
    foreach ($shipping_methods[0]['rates'] as $method_id => $method) {
        $shipping_options[] = array(
            'id' => $method_id,
            'label' => $method->get_label(),
            'cost' => $method->get_cost(),
            'tax' => $method->get_shipping_tax(),
            'method_id' => $method->get_method_id(),
            'instance_id' => $method->get_instance_id()
        );
        
        wpppc_log("Added shipping method: {$method->get_label()} ({$method_id}) - Cost: {$method->get_cost()}");
    }
    
    return $shipping_options;
}

/**
 * Add Seller Protection and PayPal Account Status columns to WooCommerce orders list
 */

// Handle both traditional and HPOS order tables
function add_seller_protection_column_handler() {
    // Check if HPOS is enabled
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
        \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
        // HPOS (Custom Order Tables) version
        add_filter('woocommerce_shop_order_list_table_columns', 'add_seller_protection_column_hpos');
        add_action('woocommerce_shop_order_list_table_custom_column', 'display_seller_protection_column_hpos', 10, 2);
    } else {
        // Traditional posts table version
        add_filter('manage_edit-shop_order_columns', 'add_seller_protection_column_traditional');
        add_action('manage_shop_order_posts_custom_column', 'display_seller_protection_column_traditional', 10, 2);
    }
}
add_action('admin_init', 'add_seller_protection_column_handler');

// For HPOS system
function add_seller_protection_column_hpos($columns) {
    $new_columns = array();
    foreach ($columns as $key => $value) {
        if ($key === 'wc_actions') {
            $new_columns['seller_protection'] = __('Seller Protection', 'woo-paypal-proxy-client');
            $new_columns['paypal_account_status'] = __('Account Status', 'woo-paypal-proxy-client');
            $new_columns['gateway'] = __('Gateway', 'woo-paypal-proxy-client');
        }
        $new_columns[$key] = $value;
    }
    return $new_columns;
}

function display_seller_protection_column_hpos($column, $order) {
    if ($column === 'seller_protection') {
        display_seller_protection_status($order);
    } elseif ($column === 'paypal_account_status') {
        display_paypal_account_status($order);
    }elseif ($column === 'gateway') {
        display_gateway_status($order);
    }
}

// For traditional system
function add_seller_protection_column_traditional($columns) {
    $new_columns = array();
    foreach ($columns as $key => $value) {
        if ($key === 'order_actions') {
            $new_columns['seller_protection'] = __('Seller Protection', 'woo-paypal-proxy-client');
            $new_columns['paypal_account_status'] = __('Account Status', 'woo-paypal-proxy-client');
            $new_columns['gateway'] = __('Gateway', 'woo-paypal-proxy-client');
        }
        $new_columns[$key] = $value;
    }
    return $new_columns;
}

function display_seller_protection_column_traditional($column, $post_id) {
    if ($column === 'seller_protection') {
        $order = wc_get_order($post_id);
        if ($order) {
            display_seller_protection_status($order);
        }
    } elseif ($column === 'paypal_account_status') {
        $order = wc_get_order($post_id);
        if ($order) {
            display_paypal_account_status($order);
        }
    }
    elseif ($column === 'gateway') {
        $order = wc_get_order($post_id);
        if ($order) {
            display_gateway_status($order);
        }
    }
}
function display_gateway_status($order) {
    // First try to get from order meta
    $gateway_source = $order->get_meta('_wpppc_funding_source', true);
    
    // If empty, try different method
    if (empty($gateway_source) && is_callable(array($order, 'get_id'))) {
        $gateway_source = get_post_meta($order->get_id(), '_wpppc_funding_source', true);
    }
    
    if (!empty($gateway_source)) {
        echo '<span class="gateway-source">' . esc_html($gateway_source) . '</span>';
    } else {
        // Check if this order was paid with PayPal
        $payment_method = $order->get_payment_method();
        $paypal_order_id = $order->get_meta('_paypal_order_id', true);
        
        if (($payment_method === 'paypal_proxy' || $payment_method === 'paypal_direct') && !empty($paypal_order_id)) {
            // This is a PayPal order but without gateway data
            echo '<span class="gateway-missing">' . esc_html__('Not found', 'woo-paypal-proxy-client') . '</span>';
        } else {
            // Not a PayPal order
            echo '<span class="gateway-na">—</span>';
        }
    }
}

// Shared display function - updated to check order notes as fallback
function display_seller_protection_status($order) {
    // First try to get from order meta
    $seller_protection = $order->get_meta('_paypal_seller_protection', true);
    
    // If empty, try different method
    if (empty($seller_protection) && is_callable(array($order, 'get_id'))) {
        $seller_protection = get_post_meta($order->get_id(), '_paypal_seller_protection', true);
    }
    
    // If still empty, extract from order notes as fallback
    if (empty($seller_protection)) {
        $notes = $order->get_customer_order_notes();
        
        // Order notes are customer-only, so we need to get all notes
        $all_notes = wc_get_order_notes(array(
            'order_id' => $order->get_id(),
            'type' => ''  // Get all notes, not just customer notes
        ));
        
        foreach ($all_notes as $note) {
            if (stripos($note->content, 'Seller Protection:') !== false) {
                // Extract the seller protection status from the note
                if (preg_match('/Seller Protection:\s*([A-Z_]+)/i', $note->content, $matches)) {
                    $seller_protection = trim($matches[1]);
                    break;
                }
            }
        }
    }
    
    if (!empty($seller_protection)) {
        $status_class = '';
        $status_display = '';
        
        switch (strtoupper($seller_protection)) {
            case 'ELIGIBLE':
                $status_class = 'seller-protection-eligible';
                $status_display = __('Eligible', 'woo-paypal-proxy-client');
                break;
            case 'PARTIALLY_ELIGIBLE':
                $status_class = 'seller-protection-partial';
                $status_display = __('Partially Eligible', 'woo-paypal-proxy-client');
                break;
            case 'NOT_ELIGIBLE':
                $status_class = 'seller-protection-not-eligible';
                $status_display = __('Not Eligible', 'woo-paypal-proxy-client');
                break;
            case 'UNKNOWN':
                $status_class = 'seller-protection-unknown';
                $status_display = __('Unknown', 'woo-paypal-proxy-client');
                break;
            default:
                $status_class = 'seller-protection-unknown';
                $status_display = __('Unknown', 'woo-paypal-proxy-client');
                break;
        }
        
        echo '<span class="' . esc_attr($status_class) . '">' . esc_html($status_display) . '</span>';
    } else {
        // Check if this order was paid with PayPal
        $payment_method = $order->get_payment_method();
        $paypal_order_id = $order->get_meta('_paypal_order_id', true);
        
        if (($payment_method === 'paypal_proxy' || $payment_method === 'paypal_direct') && !empty($paypal_order_id)) {
            // This is a PayPal order but without seller protection data
            echo '<span class="seller-protection-missing">' . esc_html__('Not found', 'woo-paypal-proxy-client') . '</span>';
        } else {
            // Not a PayPal order
            echo '<span class="seller-protection-na">—</span>';
        }
    }
}

// Display function for PayPal Account Status
function display_paypal_account_status($order) {
    // First try to get from order meta
    $account_status = $order->get_meta('_paypal_account_status', true);
    
    // If empty, try different method
    if (empty($account_status) && is_callable(array($order, 'get_id'))) {
        $account_status = get_post_meta($order->get_id(), '_paypal_account_status', true);
    }
    
    // If still empty, extract from order notes as fallback
    if (empty($account_status)) {
        $notes = $order->get_customer_order_notes();
        
        // Order notes are customer-only, so we need to get all notes
        $all_notes = wc_get_order_notes(array(
            'order_id' => $order->get_id(),
            'type' => ''  // Get all notes, not just customer notes
        ));
        
        foreach ($all_notes as $note) {
            if (stripos($note->content, 'Account Status:') !== false) {
                // Extract the account status from the note
                if (preg_match('/Account Status:\s*([A-Z_]+)/i', $note->content, $matches)) {
                    $account_status = trim($matches[1]);
                    break;
                }
            }
        }
    }
    
    if (!empty($account_status)) {
        $status_class = '';
        $status_display = '';
        
        switch (strtoupper($account_status)) {
            case 'VERIFIED':
                $status_class = 'account-status-verified';
                $status_display = __('Verified', 'woo-paypal-proxy-client');
                break;
            case 'UNVERIFIED':
                $status_class = 'account-status-unverified';
                $status_display = __('Unverified', 'woo-paypal-proxy-client');
                break;
            case 'UNKNOWN':
                $status_class = 'account-status-unknown';
                $status_display = __('Unknown', 'woo-paypal-proxy-client');
                break;
            default:
                $status_class = 'account-status-unknown';
                $status_display = __('Unknown', 'woo-paypal-proxy-client');
                break;
        }
        
        echo '<span class="' . esc_attr($status_class) . '">' . esc_html($status_display) . '</span>';
    } else {
        // Check if this order was paid with PayPal
        $payment_method = $order->get_payment_method();
        $paypal_order_id = $order->get_meta('_paypal_order_id', true);
        
        if (($payment_method === 'paypal_proxy' || $payment_method === 'paypal_direct') && !empty($paypal_order_id)) {
            // This is a PayPal order but without account status data
            echo '<span class="account-status-missing">' . esc_html__('Not found', 'woo-paypal-proxy-client') . '</span>';
        } else {
            // Not a PayPal order
            echo '<span class="account-status-na">—</span>';
        }
    }
}

// Add CSS styles for the seller protection and account status columns
add_action('admin_head', 'add_seller_protection_column_styles');
function add_seller_protection_column_styles() {
    ?>
    <style>
        .seller-protection-eligible {
            color: #2e7d32;
            font-weight: bold;
        }
        
        .seller-protection-partial {
            color: #f57c00;
            font-weight: bold;
        }
        
        .seller-protection-not-eligible {
            color: #c62828;
            font-weight: bold;
        }
        
        .seller-protection-unknown {
            color: #616161;
        }
        
        .seller-protection-na {
            color: #9e9e9e;
        }
        
        .account-status-verified {
            color: #2e7d32;
            font-weight: bold;
        }
        
        .account-status-unverified {
            color: #c62828;
            font-weight: bold;
        }
        
        .account-status-unknown {
            color: #616161;
        }
        
        .account-status-na {
            color: #9e9e9e;
        }
        
        .account-status-missing {
            color: #ff6f00;
        }
    </style>
    <?php
}

// Prevent WooCommerce from recalculating tax for Express orders
add_filter('woocommerce_calc_tax', function($tax_array) {
    // During Express checkout order creation
    if (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action']) && $_POST['action'] === 'wpppc_create_express_order') {
        // Get current totals from POST
        $current_totals = isset($_POST['current_totals']) ? $_POST['current_totals'] : array();
        if (!empty($current_totals) && floatval($current_totals['tax']) === 0) {
            return array(); // No tax
        }
    }
    return $tax_array;
}, 10, 1);

// Prevent tax display for already adjusted orders
add_filter('woocommerce_order_get_tax_totals', function($tax_totals, $order) {
    if ($order->meta_exists('_wpppc_tax_adjusted') && $order->get_meta('_wpppc_tax_adjusted') === 'yes') {
        $stored_totals = get_post_meta($order->get_id(), '_express_checkout_totals', true);
        if (!empty($stored_totals) && floatval($stored_totals['tax']) === 0) {
            return array(); // No tax totals to show
        }
    }
    return $tax_totals;
}, 10, 2);


// Add a notice on the thank you page for PayPal Standard orders
add_action('woocommerce_thankyou', 'wpppc_thankyou_processing_notice', 10, 1);
function wpppc_thankyou_processing_notice($order_id) {
    $order = wc_get_order($order_id);
    
    // Check if it's a PayPal Standard order awaiting IPN
    if ($order && $order->get_payment_method() === 'paypal_standard_proxy' && 
        get_post_meta($order_id, '_wpppc_awaiting_ipn', true) === 'yes') {
        
        echo '<div class="woocommerce-info">' . 
             __('Thank you for your payment. Your order is being processed and you will receive a confirmation email shortly.', 'woo-paypal-proxy-client') . 
             '</div>';
    }
}

// Add JavaScript to auto-refresh the thank you page for PayPal orders
add_action('wp_footer', 'wpppc_maybe_add_order_refresh_script');
function wpppc_maybe_add_order_refresh_script() {
    if (is_order_received_page()) {
        // Get the order ID
        global $wp;
        $order_id = absint($wp->query_vars['order-received']);
        
        if ($order_id > 0 && get_post_meta($order_id, '_wpppc_awaiting_ipn', true) === 'yes') {
            ?>
            <script type="text/javascript">
            (function($){
                var refreshCount = 0;
                var maxRefreshes = 3;
                
                function checkOrderStatus() {
                    if (refreshCount >= maxRefreshes) return;
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'wpppc_check_order_status',
                            order_id: <?php echo $order_id; ?>,
                            nonce: '<?php echo wp_create_nonce('wpppc-check-order-' . $order_id); ?>'
                        },
                        success: function(response) {
                            if (response.success && response.data.refresh) {
                                // Reload the page if status changed
                                location.reload();
                            } else {
                                refreshCount++;
                                // Try again in 5 seconds
                                setTimeout(checkOrderStatus, 5000);
                            }
                        },
                        error: function() {
                            refreshCount++;
                            // Try again in 5 seconds
                            setTimeout(checkOrderStatus, 5000);
                        }
                    });
                }
                
                // Start checking after 3 seconds
                setTimeout(checkOrderStatus, 3000);
            })(jQuery);
            </script>
            <?php
        }
    }
}

// AJAX handler to check order status
add_action('wp_ajax_wpppc_check_order_status', 'wpppc_check_order_status');
add_action('wp_ajax_nopriv_wpppc_check_order_status', 'wpppc_check_order_status');
function wpppc_check_order_status() {
    // Verify nonce
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    check_ajax_referer('wpppc-check-order-' . $order_id, 'nonce');
    
    $refresh = false;
    
    // Check if the order exists and if the IPN flag is still set
    $awaiting_ipn = get_post_meta($order_id, '_wpppc_awaiting_ipn', true);
    
    if ($awaiting_ipn !== 'yes') {
        // IPN has been processed, refresh the page
        $refresh = true;
    }
    
    wp_send_json_success(array('refresh' => $refresh));
}


//delete abandoned express orders after an hour
if (!wp_next_scheduled('delete_expired_express_checkout_orders')) {
    wp_schedule_event(time(), 'hourly', 'delete_expired_express_checkout_orders');
}

add_action('delete_expired_express_checkout_orders', 'delete_old_express_checkout_orders');

function delete_old_express_checkout_orders() {
    $args = array(
        'limit'        => -1,
        'status'       => 'pending',
        'meta_key'     => '_wpppc_express_checkout',
        'meta_value'   => 'yes',
        'date_created' => '<' . (new DateTime('-1 hour'))->format('Y-m-d H:i:s'),
    );

    $orders = wc_get_orders($args);

    foreach ($orders as $order) {
        wp_delete_post($order->get_id(), true); // true = force delete (bypass trash)
    }
}

//add card_details to orders
add_action('wp_ajax_wpppc_source_card', 'handle_source_card');
add_action('wp_ajax_nopriv_wpppc_source_card', 'handle_source_card');

function handle_source_card() {
    check_ajax_referer('wpppc-nonce', 'nonce');

    $temp_order_id = sanitize_text_field($_POST['order_id'] ?? '');
    $source = sanitize_text_field($_POST['funding_source'] ?? '');

    error_log('Received temp_order_id: ' . $temp_order_id);
    error_log('Received funding_source: ' . $source);

    if ($temp_order_id && !empty($source)) {
        $transient_key = 'wpppc_temp_order_' . $temp_order_id;
        $order_data = get_transient($transient_key);

        if (!is_array($order_data)) $order_data = [];

        $order_data['funding_source'] = $source;

        set_transient($transient_key, $order_data, HOUR_IN_SECONDS);
        wp_send_json_success('Funding source saved to transient.');
    }

    wp_send_json_error('Missing or invalid data');
}



/**
 * Plugin deactivation hook
 */
function wpppc_deactivate() {
    // Cleanup if needed
}
register_deactivation_hook(__FILE__, 'wpppc_deactivate');