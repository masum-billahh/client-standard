<?php
//endpoint to receive cart data and create products if needed
add_action('wp_ajax_nopriv_receive_external_cart', 'handle_external_cart_data');
add_action('wp_ajax_receive_external_cart', 'handle_external_cart_data');

function handle_external_cart_data() {
    // Verify request
    if (!isset($_POST['cart_data']) || !isset($_POST['redirect_token'])) {
        wp_die('Invalid request');
    }
    
    // Clear existing cart
    WC()->cart->empty_cart();
    
    // Process cart data
    $cart_data = json_decode(stripslashes($_POST['cart_data']), true);
    error_log('Raw cart_data: ' . print_r($_POST['cart_data'], true));
    error_log('Decoded cart_data: ' . print_r($cart_data, true));
    
    if (!empty($cart_data['currency'])) {
        WC()->session->set('external_currency', sanitize_text_field($cart_data['currency']));
    }
    
    // Initialize external pricing data storage
    $external_pricing_data = array();
    
    if (!empty($cart_data) && isset($cart_data['items'])) {
        foreach ($cart_data['items'] as $item) {
            $external_product_id = intval($item['product_id']);
            $quantity = intval($item['quantity']);
            $external_variation_id = isset($item['variation_id']) ? intval($item['variation_id']) : 0;
            
            // Try to find existing product by external ID or SKU
            $local_product_id = find_or_create_product($item, $external_product_id);
            
            if ($local_product_id) {
                // Handle variations
                $local_variation_id = 0;
                if ($external_variation_id > 0 && isset($item['variation_data'])) {
                    $local_variation_id = find_or_create_variation($local_product_id, $item, $external_variation_id);
                }
                
                // Store external pricing data for this cart item
                $cart_item_key = '';
                
                // Add to cart
                if ($local_variation_id > 0) {
                    $cart_item_key = WC()->cart->add_to_cart($local_product_id, $quantity, $local_variation_id);
                    // Store external pricing for variation
                    $external_pricing_data[$cart_item_key] = array(
                        'regular_price' => !empty($item['regular_price']) ? floatval($item['regular_price']) : '',
                        'sale_price' => !empty($item['sale_price']) ? floatval($item['sale_price']) : '',
                        'price' => !empty($item['price']) ? floatval($item['price']) : floatval($item['regular_price']),
                        'is_variation' => true,
                        'variation_id' => $local_variation_id
                    );
                } else {
                    $cart_item_key = WC()->cart->add_to_cart($local_product_id, $quantity);
                    // Store external pricing for simple product
                    $external_pricing_data[$cart_item_key] = array(
                        'regular_price' => !empty($item['regular_price']) ? floatval($item['regular_price']) : '',
                        'sale_price' => !empty($item['sale_price']) ? floatval($item['sale_price']) : '',
                        'price' => !empty($item['price']) ? floatval($item['price']) : floatval($item['regular_price']),
                        'is_variation' => false,
                        'product_id' => $local_product_id
                    );
                }
            }
        }
    }
    
    // Store external pricing data in session
    WC()->session->set('external_pricing_data', $external_pricing_data);
    
    // Store user data if provided
    if (isset($_POST['user_data'])) {
        $user_data = json_decode(stripslashes($_POST['user_data']), true);
        WC()->session->set('external_user_data', $user_data);
    }
    
    // Store source site info
    if (isset($_POST['source_site'])) {
        WC()->session->set('source_site', sanitize_text_field($_POST['source_site']));
    }
    
    // Redirect to checkout
    wp_redirect(wc_get_checkout_url());
    exit;
}

// Override product prices in cart with external prices
add_filter('woocommerce_product_get_price', 'override_external_product_price', 10, 2);
add_filter('woocommerce_product_get_regular_price', 'override_external_product_regular_price', 10, 2);
add_filter('woocommerce_product_get_sale_price', 'override_external_product_sale_price', 10, 2);

// For variations
add_filter('woocommerce_product_variation_get_price', 'override_external_product_price', 10, 2);
add_filter('woocommerce_product_variation_get_regular_price', 'override_external_product_regular_price', 10, 2);
add_filter('woocommerce_product_variation_get_sale_price', 'override_external_product_sale_price', 10, 2);

function override_external_product_price($price, $product) {
    // Only override if we have external pricing data and we're in cart/checkout context
    if (!WC()->session || !is_cart_or_checkout_context()) {
        return $price;
    }
    
    $external_pricing_data = WC()->session->get('external_pricing_data');
    if (!$external_pricing_data) {
        return $price;
    }
    
    // Find the cart item that matches this product
    $cart_item_key = find_cart_item_by_product($product, $external_pricing_data);
    
    if ($cart_item_key && isset($external_pricing_data[$cart_item_key]['price'])) {
        return $external_pricing_data[$cart_item_key]['price'];
    }
    
    return $price;
}

function override_external_product_regular_price($price, $product) {
    // Only override if we have external pricing data and we're in cart/checkout context
    if (!WC()->session || !is_cart_or_checkout_context()) {
        return $price;
    }
    
    $external_pricing_data = WC()->session->get('external_pricing_data');
    if (!$external_pricing_data) {
        return $price;
    }
    
    // Find the cart item that matches this product
    $cart_item_key = find_cart_item_by_product($product, $external_pricing_data);
    
    if ($cart_item_key && isset($external_pricing_data[$cart_item_key]['regular_price'])) {
        return $external_pricing_data[$cart_item_key]['regular_price'];
    }
    
    return $price;
}

function override_external_product_sale_price($price, $product) {
    // Only override if we have external pricing data and we're in cart/checkout context
    if (!WC()->session || !is_cart_or_checkout_context()) {
        return $price;
    }
    
    $external_pricing_data = WC()->session->get('external_pricing_data');
    if (!$external_pricing_data) {
        return $price;
    }
    
    // Find the cart item that matches this product
    $cart_item_key = find_cart_item_by_product($product, $external_pricing_data);
    
    if ($cart_item_key && isset($external_pricing_data[$cart_item_key]['sale_price'])) {
        return $external_pricing_data[$cart_item_key]['sale_price'];
    }
    
    return $price;
}

function find_cart_item_by_product($product, $external_pricing_data) {
    $product_id = $product->get_id();
    $parent_id = $product->get_parent_id();
    
    foreach ($external_pricing_data as $cart_item_key => $pricing_data) {
        if ($pricing_data['is_variation'] && isset($pricing_data['variation_id'])) {
            if ($pricing_data['variation_id'] == $product_id) {
                return $cart_item_key;
            }
        } else if (!$pricing_data['is_variation'] && isset($pricing_data['product_id'])) {
            if ($pricing_data['product_id'] == $product_id) {
                return $cart_item_key;
            }
        }
    }
    
    return null;
}

function is_cart_or_checkout_context() {
    // Check if we're in cart, checkout, or AJAX context related to cart
    if (is_cart() || is_checkout() || is_wc_endpoint_url('order-received')) {
        return true;
    }
    
    // Check for AJAX actions related to cart/checkout
    if (wp_doing_ajax()) {
        $ajax_actions = array(
            'woocommerce_get_refreshed_fragments',
            'woocommerce_update_order_review',
            'woocommerce_checkout',
            'woocommerce_remove_from_cart',
            'woocommerce_update_cart'
        );
        
        if (isset($_REQUEST['action']) && in_array($_REQUEST['action'], $ajax_actions)) {
            return true;
        }
    }
    
    return false;
}

// Clear external pricing data after order completion
add_action('woocommerce_thankyou', 'clear_external_pricing_data');
add_action('woocommerce_cart_emptied', 'clear_external_pricing_data');

function clear_external_pricing_data() {
    if (WC()->session) {
        WC()->session->__unset('external_pricing_data');
        WC()->session->__unset('external_currency');
        WC()->session->__unset('external_user_data');
        WC()->session->__unset('source_site');
    }
}


add_filter('woocommerce_currency', function($currency) {
    if (WC()->session && WC()->session->get('external_currency')) {
        return WC()->session->get('external_currency');
    }
    return $currency;
});

function find_or_create_product($item_data, $external_product_id) {
    // First, try to find by SKU if available
    if (!empty($item_data['sku'])) {
        $existing_product_id = wc_get_product_id_by_sku($item_data['sku']);
        if ($existing_product_id > 0) {
            return $existing_product_id;
        }
    }
    
    // Try to find by external product ID in meta
    $existing_products = get_posts(array(
        'post_type' => 'product',
        'meta_query' => array(
            array(
                'key' => '_external_product_id',
                'value' => $external_product_id,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    ));
    
    if (!empty($existing_products)) {
        return $existing_products[0]->ID;
    }
    
    // Product doesn't exist, create new one
    return create_new_product($item_data, $external_product_id);
}

function create_new_product($item_data, $external_product_id) {
    // Create new product
    $product = new WC_Product_Simple();
    
    // Set basic product data
    $product->set_name(sanitize_text_field($item_data['name']));
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description(sanitize_textarea_field($item_data['description']));
    $product->set_short_description(sanitize_textarea_field($item_data['description']));
    
    // Set prices (important: use prices from source site)
    if (!empty($item_data['regular_price'])) {
        $product->set_regular_price($item_data['regular_price']);
    }
    if (!empty($item_data['sale_price'])) {
        $product->set_sale_price($item_data['sale_price']);
    }
    if (!empty($item_data['price'])) {
        $product->set_price($item_data['price']);
    }
    
    // Set SKU
    if (!empty($item_data['sku'])) {
        $product->set_sku($item_data['sku']);
    } else {
        $product->set_sku('ext_' . $external_product_id . '_' . time());
    }
    
    // Set weight and dimensions
    if (!empty($item_data['weight'])) {
        $product->set_weight($item_data['weight']);
    }
    
    if (!empty($item_data['dimensions'])) {
        if (!empty($item_data['dimensions']['length'])) {
            $product->set_length($item_data['dimensions']['length']);
        }
        if (!empty($item_data['dimensions']['width'])) {
            $product->set_width($item_data['dimensions']['width']);
        }
        if (!empty($item_data['dimensions']['height'])) {
            $product->set_height($item_data['dimensions']['height']);
        }
    }
    
    // Save product
    $product_id = $product->save();
    
    if ($product_id) {
        // Store external product ID for future reference
        update_post_meta($product_id, '_external_product_id', $external_product_id);
        
        // Download and set product image
        if (!empty($item_data['image_url'])) {
            set_product_image_from_url($product_id, $item_data['image_url']);
        }
        
        // Store any additional meta data
        if (!empty($item_data['meta_data'])) {
            foreach ($item_data['meta_data'] as $meta_key => $meta_value) {
                update_post_meta($product_id, '_external_' . $meta_key, $meta_value);
            }
        }
        
        // Add to external products category
        $external_category = get_or_create_external_category();
        if ($external_category) {
            wp_set_post_terms($product_id, array($external_category), 'product_cat');
        }
    }
    
    return $product_id;
}

function find_or_create_variation($parent_product_id, $item_data, $external_variation_id) {
    // Check if variation already exists
    $existing_variations = get_posts(array(
        'post_type' => 'product_variation',
        'post_parent' => $parent_product_id,
        'meta_query' => array(
            array(
                'key' => '_external_variation_id',
                'value' => $external_variation_id,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    ));
    
    if (!empty($existing_variations)) {
        return $existing_variations[0]->ID;
    }
    
    // Create new variation
    if (!isset($item_data['variation_data'])) {
        return 0;
    }
    
    $variation = new WC_Product_Variation();
    $variation->set_parent_id($parent_product_id);
    
    // Set variation attributes
    if (!empty($item_data['variation_data']['attributes'])) {
        $variation->set_attributes($item_data['variation_data']['attributes']);
    }
    
    // Set prices
    if (!empty($item_data['regular_price'])) {
        $variation->set_regular_price($item_data['regular_price']);
    }
    if (!empty($item_data['sale_price'])) {
        $variation->set_sale_price($item_data['sale_price']);
    }
    if (!empty($item_data['price'])) {
        $variation->set_price($item_data['price']);
    }
    
    // Set SKU
    if (!empty($item_data['sku'])) {
        $variation->set_sku($item_data['sku'] . '_var');
    }
    
    $variation_id = $variation->save();
    
    if ($variation_id) {
        // Store external variation ID
        update_post_meta($variation_id, '_external_variation_id', $external_variation_id);
        
        // Set variation image if different from parent
        if (!empty($item_data['image_url'])) {
            set_product_image_from_url($variation_id, $item_data['image_url']);
        }
    }
    
    return $variation_id;
}

function set_product_image_from_url($product_id, $image_url) {
    if (empty($image_url)) {
        return false;
    }
    
    // Check if image already exists
    $existing_attachment = get_posts(array(
        'post_type' => 'attachment',
        'meta_query' => array(
            array(
                'key' => '_external_image_url',
                'value' => $image_url,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    ));
    
    if (!empty($existing_attachment)) {
        $attachment_id = $existing_attachment[0]->ID;
    } else {
        // Download image
        $attachment_id = download_external_image($image_url, $product_id);
    }
    
    if ($attachment_id) {
        set_post_thumbnail($product_id, $attachment_id);
        return true;
    }
    
    return false;
}

function download_external_image($image_url, $product_id) {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    // Download file to temp location
    $temp_file = download_url($image_url);
    
    if (is_wp_error($temp_file)) {
        return false;
    }
    
    // Get file info
    $file_array = array(
        'name' => basename($image_url),
        'tmp_name' => $temp_file
    );
    
    // Upload file
    $attachment_id = media_handle_sideload($file_array, $product_id);
    
    if (is_wp_error($attachment_id)) {
        @unlink($temp_file);
        return false;
    }
    
    // Store external URL for future reference
    update_post_meta($attachment_id, '_external_image_url', $image_url);
    
    return $attachment_id;
}

function get_or_create_external_category() {
    $category_name = 'External Products';
    $category_slug = 'external-products';
    
    $existing_category = get_term_by('slug', $category_slug, 'product_cat');
    
    if ($existing_category) {
        return $existing_category->term_id;
    }
    
    // Create category
    $category = wp_insert_term($category_name, 'product_cat', array(
        'slug' => $category_slug,
        'description' => 'Products imported from external sites'
    ));
    
    if (is_wp_error($category)) {
        return false;
    }
    
    return $category['term_id'];
}

// Auto-fill checkout form with external user data
add_action('woocommerce_checkout_init', 'prefill_checkout_from_external');
function prefill_checkout_from_external($checkout) {
    $user_data = WC()->session->get('external_user_data');
    
    if (!empty($user_data)) {
        foreach ($user_data as $key => $value) {
            if (!empty($value)) {
                $_POST[$key] = $value;
            }
        }
        
        // Clear the session data after use
        WC()->session->set('external_user_data', null);
    }
}

/*
// Add notice about external source
add_action('woocommerce_before_checkout_form', 'show_external_source_notice');
function show_external_source_notice() {
    $source_site = WC()->session->get('source_site');
    
    if ($source_site) {
        $parsed_url = parse_url($source_site);
        $domain = $parsed_url['host'] ?? $source_site;
        
        echo '<div class="woocommerce-info">';
        echo sprintf(__('Your cart has been transferred from %s. Please review your items before completing checkout.', 'woocommerce'), '<strong>' . esc_html($domain) . '</strong>');
        echo '</div>';
        
        // Clear after showing
        WC()->session->set('source_site', null);
    }
}
*/




// Store the source site URL when cart is received
add_action('wp_ajax_nopriv_receive_external_cart', 'store_source_site_for_redirect', 5);
add_action('wp_ajax_receive_external_cart', 'store_source_site_for_redirect', 5);

function store_source_site_for_redirect() {
    if (isset($_POST['source_site'])) {
        $source_site = sanitize_text_field($_POST['source_site']);
        WC()->session->set('redirect_source_site', $source_site);
    }
}

// Redirect after order completion
add_action('template_redirect', 'redirect_to_source_site_after_order');
function redirect_to_source_site_after_order() {
    if (!is_wc_endpoint_url('order-received') || !isset($_GET['key'])) return;

    $source_site = WC()->session->get('redirect_source_site');
    if (!$source_site) return;

    $order_id = wc_get_order_id_by_order_key(sanitize_text_field($_GET['key']));
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    $order_data = prepare_order_data_for_redirect($order);
    create_order_redirect_form($source_site, $order_data, $order_id);

    WC()->session->set('redirect_source_site', null);
    exit;
}


function prepare_order_data_for_redirect($order) {
    $order_data = array(
        'order_id' => $order->get_id(),
        'order_key' => $order->get_order_key(),
        'order_number' => $order->get_order_number(),
        'status' => $order->get_status(),
        'total' => $order->get_total(),
        'subtotal' => $order->get_subtotal(),
        'tax_total' => $order->get_total_tax(),
        'shipping_total' => $order->get_shipping_total(),
        'currency' => $order->get_currency(),
        'date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
        'payment_method' => $order->get_payment_method(),
        'payment_method_title' => $order->get_payment_method_title(),
        
        // Customer data
        'billing' => array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
        ),
        
        'shipping' => array(
            'first_name' => $order->get_shipping_first_name(),
            'last_name' => $order->get_shipping_last_name(),
            'address_1' => $order->get_shipping_address_1(),
            'address_2' => $order->get_shipping_address_2(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(),
        ),
        
        // Order items
        'items' => array()
    );
    
    // Add order items
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $external_product_id = get_post_meta($item->get_product_id(), '_external_product_id', true);
        $external_variation_id = 0;
        
        if ($item->get_variation_id()) {
            $external_variation_id = get_post_meta($item->get_variation_id(), '_external_variation_id', true);
        }
        
        $order_data['items'][] = array(
            'name' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'price' => $item->get_total(),
            'external_product_id' => $external_product_id,
            'external_variation_id' => $external_variation_id,
            'sku' => $product ? $product->get_sku() : '',
        );
    }
    
    return $order_data;
}

function create_order_redirect_form($source_site, $order_data, $order_id) {
    $endpoint_url = rtrim($source_site, '/') . '/wp-admin/admin-ajax.php';
    $thank_you_url = rtrim($source_site, '/') . '/checkout/order-received/';
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Order Completed - Redirecting...</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .loading { margin: 20px 0; }
        </style>
    </head>
    <body>
        <h2>Order Completed Successfully!</h2>
        <p>You are being redirected back to complete your order...</p>
        <div class="loading">Please wait...</div>
        
        <form id="order_redirect_form" method="POST" action="<?php echo esc_url($endpoint_url); ?>">
            <input type="hidden" name="action" value="receive_order_completion" />
            <input type="hidden" name="order_data" value="<?php echo esc_attr(json_encode($order_data)); ?>" />
            <input type="hidden" name="redirect_token" value="<?php echo wp_create_nonce('order_completion'); ?>" />
            <input type="hidden" name="thank_you_url" value="<?php echo esc_attr($thank_you_url); ?>" />
        </form>
        
        <script>
           document.getElementById('order_redirect_form').submit();
        </script>
    </body>
    </html>
    <?php
}