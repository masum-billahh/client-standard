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
    
    if (!empty($cart_data)) {
        foreach ($cart_data as $item) {
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
                
                // Add to cart
                if ($local_variation_id > 0) {
                    WC()->cart->add_to_cart($local_product_id, $quantity, $local_variation_id);
                } else {
                    WC()->cart->add_to_cart($local_product_id, $quantity);
                }
            }
        }
    }
    
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