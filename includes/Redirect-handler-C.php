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
    // Get the additional product IDs sent from site A
    $additional_product_ids = isset($cart_data['additional_product_ids']) ? $cart_data['additional_product_ids'] : array();
    
    // Handle mismatched quantities between cart items and product IDs
    $processed_product_ids = array();
    
    foreach ($cart_data['items'] as $index => $item) {
        $external_product_id = intval($item['product_id']);
        $quantity = intval($item['quantity']);
        $external_variation_id = intval($item['variation_id']);

        $local_product_id = null;
        
        // Strategy 1: Try to use corresponding product ID by index
        if (isset($additional_product_ids[$index])) {
            $local_product_id = intval($additional_product_ids[$index]);
        }
        // Strategy 2: If no corresponding ID, try to reuse the first available ID
        elseif (!empty($additional_product_ids)) {
            $local_product_id = intval($additional_product_ids[0]);
        }
        // Strategy 3: If still no ID, try to reuse any previously used ID
        elseif (!empty($processed_product_ids)) {
            $local_product_id = $processed_product_ids[0];
        }
        
        if ($local_product_id) {
            // Verify the product exists locally
            $product = wc_get_product($local_product_id);
            if (!$product || !$product->exists()) {
                error_log("Product ID {$local_product_id} does not exist locally for item index {$index}");
                continue; // Skip this item if product doesn't exist
            }
            
            // Track used product IDs
            if (!in_array($local_product_id, $processed_product_ids)) {
                $processed_product_ids[] = $local_product_id;
            }
            
            // Prepare cart item data with custom fields
            $cart_item_data = array();
            
            // Add a unique identifier to distinguish items using the same product
            $cart_item_data['external_item_index'] = $index;
            $cart_item_data['external_product_id'] = $external_product_id;
            $cart_item_data['external_variation_id'] = $external_variation_id;
            
            // Add image_url to cart item data if provided
            if (isset($item['image_url'])) {
                $cart_item_data['image_url'] = $item['image_url'];
            }
            
            // Modify product name if reusing the same product ID
            $reuse_count = 0;
            foreach ($processed_product_ids as $used_id) {
                if ($used_id == $local_product_id) {
                    $reuse_count++;
                }
            }
            
            if ($reuse_count > 1) {
                $cart_item_data['custom_name'] = $item['name'] . ' (Item #' . ($index + 1) . ')';
            } else {
                $cart_item_data['custom_name'] = $item['name'];
            }
            
            
            
            // Add simplified WAPF data if present
            if (isset($item['meta_data']['wapf'])) {
                $simplified_wapf = array();
                foreach ($item['meta_data']['wapf'] as $field) {
                    $field_data = array(
                        'label' => isset($field['label']) ? $field['label'] : '',
                        'value' => isset($field['values'][0]['label']) ? $field['values'][0]['label'] : ''
                    );
                    $simplified_wapf[] = $field_data;
                }
                $cart_item_data['wapf'] = $simplified_wapf;
            }
            
            // Fields to exclude
            $exclude_fields = array('wapf_key', 'wapf_field_groups', 'variation', 'wapf_item_price', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax','tmcartepo', 'tmcartfee',
                                'tmpost_data', 'tmdata', 'tmhasepo', 'addons',
                                'tm_cart_item_key', 'tm_epo_product_original_price',
                                'tm_epo_options_prices', 'tm_epo_product_price_with_options',
                                'tm_epo_options_static_prices', 'associated_products_price',
                                'tm_epo_options_total_for_cumulative', 'tm_epo_options_static_prices_first',
                                'tm_epo_set_product_price_with_options');
            
            // Add other custom fields (excluding system fields and cost fields)
            foreach ($item['meta_data'] as $key => $value) {
                if (!in_array($key, $exclude_fields) && $key !== 'wapf' && strpos($key, 'cost') === false) {
                    $cart_item_data[$key] = $value;
                    error_log("Added custom field: $key = " . print_r($value, true));
                }
            }
            
            // **Add tm_options to cart item data**
                if (isset($item['tm_options']) && is_array($item['tm_options'])) {
                    $cart_item_data['tm_options'] = $item['tm_options'];
                    error_log("Added tm_options: " . print_r($item['tm_options'], true));
                }

            $cart_item_key = '';

            // Add to cart - no variations, just use the mapped product ID
            $cart_item_key = WC()->cart->add_to_cart($local_product_id, $quantity, 0, array(), $cart_item_data);
           

            // Price calculation - Updated logic with FOX detection
            $base_price = 0;
            $options_total = 0;
            $final_price = 0;
            
            // Check if FOX currency converter is active
            $is_fox_active = is_fox_currency_active();
            
            if (!$is_fox_active) {
                // FOX is NOT active, use line_total (already converted price)
               $final_price = floatval($item['meta_data']['line_total']) / $quantity;
                $base_price = $final_price;
                
                 // Get options total if available
                if (!empty($item['meta_data']['wapf_item_price']['options_total'])) {
                    $options_total = floatval($item['meta_data']['wapf_item_price']['options_total']);
                }
                
                error_log('FOX inactive - Using line_total: ' . $item['meta_data']['line_total'] . 
                      ' for quantity: ' . $quantity . 
                      ' = per item: ' . $final_price . 
                      ' | options_total: ' . $options_total);
                
            } else {
                // FOX is active OR line_total not available, use existing logic
                // Get base price from available sources
                if (!empty($item['meta_data']['wapf_item_price']['base'])) {
                    $base_price = floatval($item['meta_data']['wapf_item_price']['base']);
                } elseif (!empty($item['meta_data']['base_price'])) {
                    $base_price = floatval($item['meta_data']['base_price']);
                } elseif (!empty($item['base_price'])) {
                    $base_price = floatval($item['base_price']);
                } elseif (!empty($item['price'])) {
                    $base_price = floatval($item['price']);
                } elseif (!empty($item['regular_price'])) {
                    $base_price = floatval($item['regular_price']);
                }
                
                // Get options total if available
                if (!empty($item['meta_data']['wapf_item_price']['options_total'])) {
                    $options_total = floatval($item['meta_data']['wapf_item_price']['options_total']);
                }
                
                // Calculate final price
                $final_price = $base_price + $options_total;
                
                error_log('FOX active or line_total unavailable - Using calculated price: ' . $final_price);
            }
            
             
            error_log("Item $index: Product ID $local_product_id -> Cart Key: $cart_item_key -> Price: $final_price");

            
            // Store pricing
            $external_pricing_data[$cart_item_key] = array(
                'regular_price' =>  !empty($item['regular_price']) ? floatval($item['regular_price']) : '',
                'sale_price'    => !empty($item['sale_price']) ? floatval($item['sale_price']) : '',
                'price'         => $final_price,
                'base_price'    => $base_price,
                'options_total' => $options_total,
                'is_variation'  => false,
                'product_id'    => $local_product_id,
                'fox_active'    => $is_fox_active,
                'original_meta_data' => $item['meta_data'],
                'external_item_index' => $index,
                'external_product_id' => $external_product_id,
                'external_variation_id' => $external_variation_id,
                'original_local_product_id' => $local_product_id
            );
            error_log("Cart item key: $cart_item_key with custom data: " . print_r($cart_item_data, true));
        } else {
            error_log("No product ID available for item index {$index} - skipping item");
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



add_filter('woocommerce_get_item_data', 'show_all_custom_cart_item_data', 10, 2);

function show_all_custom_cart_item_data($item_data, $cart_item) {
    $exclude_keys = array('product_id', 'variation_id', 'wapf', 'key', 'data_hash', 'quantity', 'external_product_id', 'external_variation_id', 'image_url', 'custom_name', 'external_item_index','tmcartepo', 'tmcartfee',
                                'tmpost_data', 'tmdata', 'tmhasepo', 'addons',
                                'tm_cart_item_key', 'tm_epo_product_original_price',
                                'tm_epo_options_prices', 'tm_epo_product_price_with_options',
                                'tm_epo_options_static_prices', 'associated_products_price',
                                'tm_epo_options_total_for_cumulative', 'tm_epo_options_static_prices_first',
                                'tm_epo_set_product_price_with_options'); // exclude Woo fields
    $exclude_labels = array(
        'Unique Key',
        'Key',
        'Quantity',
        'Data Hash',
        'Line Subtotal',
        'Line Subtotal Tax',
        'Line Total',
        'Line Tax',
        'external_product_id',   
        'external_variation_id', 
        'image_url',
        'tmcartepo', 'tmcartfee',
                                'tmpost_data', 'tmdata', 'tmhasepo', 'addons',
                                'tm_cart_item_key', 'tm_epo_product_original_price',
                                'tm_epo_options_prices', 'tm_epo_product_price_with_options',
                                'tm_epo_options_static_prices', 'associated_products_price',
                                'tm_epo_options_total_for_cumulative', 'tm_epo_options_static_prices_first',
                                'tm_epo_set_product_price_with_options'
    );

    // Handle WAPF fields (structured array) - only if they have actual values
    if (!empty($cart_item['wapf']) && is_array($cart_item['wapf'])) {
        foreach ($cart_item['wapf'] as $field) {
            $label = sanitize_text_field($field['label']);
            $value = sanitize_text_field($field['value']);
            
            // Only add if both label and value are not empty
            if (!empty($label) && !empty($value) && !in_array($label, $exclude_labels)) {
                $item_data[] = array(
                    'name'  => $label, // Remove "Wapf" prefix
                    'value' => $value,
                );
            }
        }
    }
    
    if (isset($cart_item['tm_options']) && is_array($cart_item['tm_options'])) {
        foreach ($cart_item['tm_options'] as $option) {
            $item_data[] = array(
                'key'   => $option['label'],
                'value' => $option['value'],
            );
        }
    }

    // Loop through all custom cart item fields - only add if they have values
    foreach ($cart_item as $key => $value) {
        if (in_array($key, $exclude_keys)) continue;
        if (is_array($value) || is_object($value)) continue;
        if (empty($value)) continue; // Skip empty values

        $label = ucwords(str_replace('_', ' ', $key));
        if (in_array($label, $exclude_labels)) continue;

        $item_data[] = array(
            'name'  => $label,
            'value' => sanitize_text_field($value),
        );
    }

    return $item_data;
}





add_action('woocommerce_checkout_create_order_line_item', 'save_custom_cart_data_to_order_item', 10, 4);


function save_custom_cart_data_to_order_item($item, $cart_item_key, $values, $order) {
    $exclude_keys = array(
        'product_id', 
        'variation_id', 
        'key', 
        'data_hash', 
        'line_subtotal', 
        'line_subtotal_tax', 
        'line_total', 
        'line_tax',
        'quantity',
        'tmcartepo', 'tmcartfee',
                                'tmpost_data', 'tmdata', 'tmhasepo', 'addons',
                                'tm_cart_item_key', 'tm_epo_product_original_price',
                                'tm_epo_options_prices', 'tm_epo_product_price_with_options',
                                'tm_epo_options_static_prices', 'associated_products_price',
                                'tm_epo_options_total_for_cumulative', 'tm_epo_options_static_prices_first',
                                'tm_epo_set_product_price_with_options'
    );
    
    // Save WAPF fields if present
    if (!empty($values['wapf']) && is_array($values['wapf'])) {
        foreach ($values['wapf'] as $index => $field) {
            if (!empty($field['label']) && !empty($field['value'])) {
                $clean_label = sanitize_text_field($field['label']);
                $item->add_meta_data($clean_label, sanitize_text_field($field['value']));
            }
        }
    }
    
    if (isset($values['tm_options']) && is_array($values['tm_options'])) {
        foreach ($values['tm_options'] as $option) {
            $item->add_meta_data($option['label'], $option['value']);
        }
    }
    
    // Save other custom fields
    foreach ($values as $key => $value) {
        if (in_array($key, $exclude_keys)) continue;
        if ($key === 'wapf') continue;
        if (is_array($value) || is_object($value)) continue;
        if (strpos($key, 'cost') !== false) continue;
        if (empty($value)) continue;
        
        // Special handling for external_product_id and external_variation_id
        if ($key === 'external_product_id') {
            $item->add_meta_data('_external_product_id', $value);
        } elseif ($key === 'external_variation_id') {
            $item->add_meta_data('_external_variation_id', $value);
        } else {
            $display_key = ucwords(str_replace('_', ' ', $key));
            $item->add_meta_data($display_key, sanitize_text_field($value));
        }
    }
}
add_filter('woocommerce_cart_item_name', 'override_cart_item_name', 10, 3);
function override_cart_item_name($product_name, $cart_item, $cart_item_key) {
    if (isset($cart_item['custom_name'])) {
        return esc_html($cart_item['custom_name']);
    }
    return $product_name;
}

add_filter('woocommerce_order_item_display_meta_key', 'customize_order_item_meta_key_display', 10, 3);
function customize_order_item_meta_key_display($display_key, $meta, $item) {
    // Hide certain meta keys from display
    $hidden_keys = array(
        'Unique Key',
        'Key', 
        'Quantity',
        'Data Hash',
        '_wapf_data',
        '_external_product_id',   
        '_external_variation_id', 
        '_image_url',             
        'Image Url',              
        '_external_item_index',   
        'External Item Index',
        'External Product Id',
        'External Variation Id',
        'Custom Name'
    );
    
    if (in_array($display_key, $hidden_keys)) {
        return false; // Hide this meta key
    }
    
    // Clean up display of custom fields
    if (strpos($display_key, '_custom_') === 0) {
        return ucwords(str_replace(array('_custom_', '_'), array('', ' '), $display_key));
    }
    if (strpos($display_key, 'wapf_') === 0) {
        return str_replace('wapf_', '', ucwords(str_replace('_', ' ', $display_key)));
    }
    
    return $display_key;
}

add_filter('woocommerce_order_item_get_formatted_meta_data', 'filter_order_item_meta_data', 10, 2);
function filter_order_item_meta_data($formatted_meta, $item) {
    $hidden_keys = array(
        'Unique Key',
        'Key',
        'Quantity',
        'Data Hash',
        '_wapf_data',
        '_external_product_id',
        '_external_variation_id',
        '_image_url',
        'Image Url',
        '_external_item_index',
        'External Item Index',
        'External Product Id',
        'External Variation Id',
        'Custom Name'
    );
    
    $filtered_meta = array();
    
    foreach ($formatted_meta as $meta_id => $meta) {
        // Skip if key is in hidden list
        if (in_array($meta->display_key, $hidden_keys)) {
            continue;
        }
        
        // Skip if value is empty
        if (empty($meta->display_value) || $meta->display_value === '' || $meta->display_value === ':') {
            continue;
        }
        
        $filtered_meta[$meta_id] = $meta;
    }
    
    return $filtered_meta;
}

// filter to hide unwanted meta from order item display
add_filter('woocommerce_order_item_display_meta_value', 'hide_unwanted_order_meta', 10, 3);
function hide_unwanted_order_meta($display_value, $meta, $item) {
    // List of meta keys to hide completely
    $hidden_keys = array(
        'Unique Key',
        'Key',
        'Quantity', 
        'Data Hash',
        '_wapf_data',
         '_external_product_id',
        '_external_variation_id', 
        '_image_url',             
        'Image Url',              
        '_external_item_index',   
        'External Item Index',
        'External Product Id',
        'External Variation Id',
        'Custom Name'
    );
    
    if (in_array($meta->key, $hidden_keys)) {
        return false; // Don't display this meta
    }
    
    return $display_value;
}

//testing//////////////////////////////////////////////////

function is_fox_currency_active() {
    return class_exists('WOOCS_STARTER');
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
    if (WC()->session && method_exists(WC()->session, 'get')) {
        $user_data = WC()->session->get('external_user_data');

        if (!empty($user_data)) {
            foreach ($user_data as $key => $value) {
                if (!empty($value)) {
                    $_POST[$key] = $value;
                }
            }

            WC()->session->set('external_user_data', null);
        }
    }
}



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
        'shipping_method' => $order->get_shipping_method(),
        'currency' => $order->get_currency(),
        'date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
        'payment_method' => $order->get_payment_method(),
        'payment_method_title' => $order->get_payment_method_title(),
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
        'items' => array()
    );
    
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        
        // Get the external product ID and variation ID from the order item meta
        $external_product_id = $item->get_meta('_external_product_id');
        $external_variation_id = $item->get_meta('_external_variation_id');
        
        // Fallback to local IDs if meta is missing
        if (empty($external_product_id)) {
            $external_product_id = $item->get_product_id();
        }
        if (empty($external_variation_id)) {
            $external_variation_id = $item->get_variation_id();
        }
        
        // Get custom fields
        $item_meta = array();
        $meta_data = $item->get_meta_data();
        foreach ($meta_data as $meta) {
            $key = $meta->key;
            $value = $meta->value;
            if (strpos($key, '_') !== 0 && stripos($key, 'key') === false) {
                $item_meta[$key] = $value;
            }
        }
        
        $order_data['items'][] = array(
            'name' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'price' => $item->get_total(),
            'external_product_id' => $external_product_id,
            'external_variation_id' => $external_variation_id,
            'sku' => $product ? $product->get_sku() : '',
            'custom_fields' => $item_meta,
            'item_id' => $item->get_id()
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

add_filter('woocommerce_cart_item_thumbnail', 'override_cart_item_thumbnail', 10, 3);

function override_cart_item_thumbnail($thumbnail, $cart_item, $cart_item_key) {
    // Check if the cart item has a custom image_url
    if (isset($cart_item['image_url'])) {
        // Return an img tag with the custom image_url
        return '<img src="' . esc_url($cart_item['image_url']) . '" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" alt="">';
    }
    // Fallback to the default product thumbnail if no custom image_url
    return $thumbnail;
}

add_action('woocommerce_checkout_create_order_line_item', 'save_custom_cart_data_to_order', 10, 4);

function save_custom_cart_data_to_order($item, $cart_item_key, $values, $order) {
    if (isset($values['image_url'])) {
        $item->add_meta_data('_image_url', $values['image_url']);
    }
}

add_action('woocommerce_before_calculate_totals', 'set_custom_cart_prices', 10, 1);

function set_custom_cart_prices($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (did_action('woocommerce_before_calculate_totals') >= 2) return;

    $external_pricing_data = WC()->session->get('external_pricing_data');

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (isset($external_pricing_data[$cart_item_key]['price'])) {
            $custom_price = $external_pricing_data[$cart_item_key]['price'];
            $cart_item['data']->set_price($custom_price);
        }
    }
}


//cart-specific hooks 
add_filter('woocommerce_cart_item_price', 'override_cart_item_price', 10, 3);
add_filter('woocommerce_cart_item_subtotal', 'override_cart_item_subtotal', 10, 3);

function override_cart_item_price($price, $cart_item, $cart_item_key) {
    $external_pricing_data = WC()->session->get('external_pricing_data');
    
    if (isset($external_pricing_data[$cart_item_key]['price'])) {
        error_log("Overriding price for cart key: $cart_item_key with price: " . $external_pricing_data[$cart_item_key]['price']);
        return wc_price($external_pricing_data[$cart_item_key]['price']);
    }
    
    return $price;
}

function override_cart_item_subtotal($subtotal, $cart_item, $cart_item_key) {
    $external_pricing_data = WC()->session->get('external_pricing_data');
    
    if (isset($external_pricing_data[$cart_item_key]['price'])) {
        $custom_price = $external_pricing_data[$cart_item_key]['price'];
        $quantity = $cart_item['quantity'];
        $line_total = $custom_price * $quantity;
        
        error_log("Overriding subtotal for cart key: $cart_item_key - Price: $custom_price x Qty: $quantity = $line_total");
        return wc_price($line_total);
    }
    
    return $subtotal;
}

//api to validate product exist
add_action('rest_api_init', function () {
    register_rest_route('cart-redirector/v1', '/validate-products', [
        'methods'  => 'POST',
        'callback' => 'validate_cart_redirector_product_ids',
        'permission_callback' => '__return_true',
    ]);
});

function validate_cart_redirector_product_ids($request) {
    $params = $request->get_json_params();
    $ids = isset($params['product_ids']) ? array_map('intval', $params['product_ids']) : [];

    if (empty($ids)) {
        return new WP_REST_Response([
            'valid' => false,
            'error' => 'No product IDs provided.',
        ], 400);
    }

    $missing = [];

    foreach ($ids as $id) {
        $post = get_post($id);
        if (
            !$post ||
            $post->post_type !== 'product' ||
            $post->post_status !== 'publish'
        ) {
            $missing[] = $id;
        }
    }

    if (!empty($missing)) {
        return new WP_REST_Response([
            'valid'       => false,
            'missing_ids' => $missing,
            'error'       => 'Some product IDs are invalid or not published in A site.',
        ], 200);
    }

    return new WP_REST_Response(['valid' => true], 200);
}


add_filter('woocommerce_order_item_name', 'override_order_item_name_in_admin', 10, 3);

function override_order_item_name_in_admin($item_name, $item, $is_visible) {
    // Check if in admin context
    if (is_admin()) {
        // Get the custom name from order item meta
        $custom_name = $item->get_meta('Custom Name');
        if (!empty($custom_name)) {
            return esc_html($custom_name);
        }
    }
    return $item_name;
}


