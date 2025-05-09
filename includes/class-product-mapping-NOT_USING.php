<?php
/**
 * Product Mapping class for WooCommerce PayPal Proxy Client
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce PayPal Proxy Client Product Mapping Class
 */
class WPPPC_Product_Mapping {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Add AJAX handlers
        add_action('wp_ajax_wpppc_save_product_mapping', array($this, 'save_product_mapping'));
        add_action('wp_ajax_wpppc_delete_product_mapping', array($this, 'delete_product_mapping'));
        add_action('wp_ajax_wpppc_get_mappings', array($this, 'get_mappings'));
        
        // Add meta box to product edit screen
        add_action('add_meta_boxes', array($this, 'add_product_mapping_meta_box'));
        add_action('save_post_product', array($this, 'save_product_mapping_meta_box'), 10, 2);
        add_action('wp_ajax_wpppc_save_all_mappings', array($this, 'save_all_mappings'));

    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('PayPal Proxy Product Mapping', 'woo-paypal-proxy-client'),
            __('PayPal Proxy Mapping', 'woo-paypal-proxy-client'),
            'manage_woocommerce',
            'wpppc-product-mapping',
            array($this, 'product_mapping_page')
        );
    }
    
/**
 * Product mapping page with pagination
 */
public function product_mapping_page() {
    // Get current page
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 20; // Products per page
    
    // Get products with pagination
    $products = wc_get_products(array(
        'limit' => $per_page,
        'page' => $current_page,
        'status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC',
    ));
    
    // Get total product count for pagination
    $total_products = wp_count_posts('product')->publish;
    $total_pages = ceil($total_products / $per_page);
    
    // Get existing mappings
    $mappings = $this->get_all_product_mappings();
    
    // Create lookup array for easier access
    $mappings_lookup = array();
    foreach ($mappings as $mapping) {
        $mappings_lookup[$mapping->product_id] = $mapping->server_product_id;
    }
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="notice notice-info">
            <p><?php _e('Map your products to products on the PayPal Proxy server. This allows the server to use its own product details when processing payments.', 'woo-paypal-proxy-client'); ?></p>
        </div>
        
        <div id="wpppc-product-mapping-app">
            <div class="wpppc-toolbar">
                <div class="wpppc-search-container">
                    <input type="text" id="wpppc-search" placeholder="<?php _e('Search products...', 'woo-paypal-proxy-client'); ?>" class="regular-text">
                </div>
                <div class="wpppc-button-container">
                    <button type="button" id="wpppc-save-all" class="button button-primary"><?php _e('Save All Mappings', 'woo-paypal-proxy-client'); ?></button>
                    <span id="wpppc-save-all-status" class="wpppc-status"></span>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Product', 'woo-paypal-proxy-client'); ?></th>
                        <th><?php _e('SKU', 'woo-paypal-proxy-client'); ?></th>
                        <th><?php _e('Server Product ID', 'woo-paypal-proxy-client'); ?></th>
                        <th><?php _e('Actions', 'woo-paypal-proxy-client'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product) : ?>
                        <tr data-product-id="<?php echo esc_attr($product->get_id()); ?>">
                            <td>
                                <strong><?php echo esc_html($product->get_name()); ?></strong>
                                <div class="row-actions">
                                    <span class="id">ID: <?php echo esc_html($product->get_id()); ?></span>
                                </div>
                            </td>
                            <td><?php echo esc_html($product->get_sku()); ?></td>
                            <td>
                                <input type="number" class="server-product-id" name="server_product_id[<?php echo esc_attr($product->get_id()); ?>]" value="<?php echo isset($mappings_lookup[$product->get_id()]) ? esc_attr($mappings_lookup[$product->get_id()]) : ''; ?>" min="1">
                            </td>
                            <td>
                                <button type="button" class="button save-mapping" data-product-id="<?php echo esc_attr($product->get_id()); ?>"><?php _e('Save', 'woo-paypal-proxy-client'); ?></button>
                                <?php if (isset($mappings_lookup[$product->get_id()])) : ?>
                                    <button type="button" class="button delete-mapping" data-product-id="<?php echo esc_attr($product->get_id()); ?>"><?php _e('Delete', 'woo-paypal-proxy-client'); ?></button>
                                <?php endif; ?>
                                <span class="wpppc-status" id="status-<?php echo esc_attr($product->get_id()); ?>"></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(_n('%s item', '%s items', $total_products, 'woo-paypal-proxy-client'), number_format_i18n($total_products)); ?>
                    </span>
                    <span class="pagination-links">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page,
                        ));
                        ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
            jQuery(document).ready(function($) {
                // Handle search
                $('#wpppc-search').on('keyup', function() {
                    var value = $(this).val().toLowerCase();
                    $('#wpppc-product-mapping-app tbody tr').filter(function() {
                        $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
                    });
                });
                
                // Handle save mapping
                $('.save-mapping').on('click', function() {
                    var $button = $(this);
                    var productId = $button.data('product-id');
                    saveMapping($button, productId);
                });
                
                // Handle delete mapping
                $('.delete-mapping').on('click', function() {
                    handleDeleteMapping($(this));
                });
                
                // Handle save all button
                $('#wpppc-save-all').on('click', function() {
                    var $button = $(this);
                    var $status = $('#wpppc-save-all-status');
                    var mappings = [];
                    
                    // Collect all visible mappings (important for search filtering)
                    $('#wpppc-product-mapping-app tbody tr:visible').each(function() {
                        var productId = $(this).data('product-id');
                        var serverId = $(this).find('.server-product-id').val();
                        
                        if (serverId) {
                            mappings.push({
                                product_id: productId,
                                server_product_id: serverId
                            });
                        }
                    });
                    
                    if (mappings.length === 0) {
                        $status.text('No mappings to save').addClass('error').fadeIn();
                        setTimeout(function() {
                            $status.fadeOut(function() {
                                $status.removeClass('error');
                            });
                        }, 3000);
                        return;
                    }
                    
                    $button.prop('disabled', true).text('<?php _e('Saving...', 'woo-paypal-proxy-client'); ?>');
                    $status.text('').removeClass('success error').hide();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpppc_save_all_mappings',
                            nonce: '<?php echo wp_create_nonce('wpppc-admin-nonce'); ?>',
                            mappings: mappings
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.text('All mappings saved successfully').addClass('success').fadeIn();
                                
                                // Add delete buttons where needed
                                mappings.forEach(function(mapping) {
                                    var $row = $('tr[data-product-id="' + mapping.product_id + '"]');
                                    var $saveBtn = $row.find('.save-mapping');
                                    
                                    if ($saveBtn.siblings('.delete-mapping').length === 0) {
                                        $saveBtn.after(' <button type="button" class="button delete-mapping" data-product-id="' + mapping.product_id + '">Delete</button>');
                                        
                                        // Attach event handler to new button
                                        $saveBtn.siblings('.delete-mapping').on('click', function() {
                                            handleDeleteMapping($(this));
                                        });
                                    }
                                });
                            } else {
                                $status.text(response.data.message || 'Failed to save mappings').addClass('error').fadeIn();
                            }
                        },
                        error: function() {
                            $status.text('An error occurred. Please try again.').addClass('error').fadeIn();
                        },
                        complete: function() {
                            $button.prop('disabled', false).text('<?php _e('Save All Mappings', 'woo-paypal-proxy-client'); ?>');
                            
                            setTimeout(function() {
                                $status.fadeOut(function() {
                                    $status.removeClass('success error');
                                });
                            }, 3000);
                        }
                    });
                });
                
                function saveMapping($button, productId) {
                    var serverProductId = $('input[name="server_product_id[' + productId + ']"]').val();
                    var $status = $('#status-' + productId);
                    
                    if (!serverProductId) {
                        $status.text('Please enter an ID').addClass('error').fadeIn();
                        setTimeout(function() {
                            $status.fadeOut(function() {
                                $status.removeClass('error');
                            });
                        }, 3000);
                        return;
                    }
                    
                    $button.prop('disabled', true).text('<?php _e('Saving...', 'woo-paypal-proxy-client'); ?>');
                    $status.text('').removeClass('success error').hide();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpppc_save_product_mapping',
                            nonce: '<?php echo wp_create_nonce('wpppc-admin-nonce'); ?>',
                            product_id: productId,
                            server_product_id: serverProductId
                        },
                        success: function(response) {
                            if (response.success) {
                                // Add delete button if it doesn't exist
                                if ($button.siblings('.delete-mapping').length === 0) {
                                    $button.after(' <button type="button" class="button delete-mapping" data-product-id="' + productId + '">Delete</button>');
                                    
                                    // Attach event handler to new button
                                    $button.siblings('.delete-mapping').on('click', function() {
                                        handleDeleteMapping($(this));
                                    });
                                }
                                
                                $status.text('Saved').addClass('success').fadeIn();
                            } else {
                                $status.text(response.data.message || 'Failed').addClass('error').fadeIn();
                            }
                        },
                        error: function() {
                            $status.text('Error').addClass('error').fadeIn();
                        },
                        complete: function() {
                            $button.prop('disabled', false).text('<?php _e('Save', 'woo-paypal-proxy-client'); ?>');
                            
                            setTimeout(function() {
                                $status.fadeOut(function() {
                                    $status.removeClass('success error');
                                });
                            }, 3000);
                        }
                    });
                }
                
                function handleDeleteMapping($button) {
                    var productId = $button.data('product-id');
                    var $status = $('#status-' + productId);
                    
                    $button.prop('disabled', true).text('<?php _e('Deleting...', 'woo-paypal-proxy-client'); ?>');
                    $status.text('').removeClass('success error').hide();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpppc_delete_product_mapping',
                            nonce: '<?php echo wp_create_nonce('wpppc-admin-nonce'); ?>',
                            product_id: productId
                        },
                        success: function(response) {
                            if (response.success) {
                                // Clear input field
                                $('input[name="server_product_id[' + productId + ']"]').val('');
                                
                                // Remove delete button
                                $button.remove();
                                
                                $status.text('Deleted').addClass('success').fadeIn();
                            } else {
                                $status.text(response.data.message || 'Failed').addClass('error').fadeIn();
                                $button.prop('disabled', false).text('<?php _e('Delete', 'woo-paypal-proxy-client'); ?>');
                            }
                        },
                        error: function() {
                            $status.text('Error').addClass('error').fadeIn();
                            $button.prop('disabled', false).text('<?php _e('Delete', 'woo-paypal-proxy-client'); ?>');
                        },
                        complete: function() {
                            setTimeout(function() {
                                $status.fadeOut(function() {
                                    $status.removeClass('success error');
                                });
                            }, 3000);
                        }
                    });
                }
            });
        </script>
        
        <style>
            .wpppc-toolbar {
                margin: 15px 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .wpppc-search-container {
                flex: 1;
            }
            
            .wpppc-button-container {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .server-product-id {
                width: 100px;
            }
            
            .delete-mapping {
                margin-left: 5px !important;
            }
            
            .wpppc-status {
                display: inline-block;
                margin-left: 10px;
                font-weight: 500;
                padding: 3px 8px;
                border-radius: 3px;
            }
            
            .wpppc-status.success {
                color: #0a6b18;
                background-color: #d1e7dd;
            }
            
            .wpppc-status.error {
                color: #842029;
                background-color: #f8d7da;
            }
            
            #wpppc-save-all-status {
                min-width: 200px;
            }
            
            /* Pagination styling */
            .tablenav-pages {
                float: right;
                margin: 1em 0;
            }
            
            .pagination-links {
                display: inline-block;
            }
            
            .pagination-links a,
            .pagination-links span.current {
                display: inline-block;
                padding: 4px 10px;
                margin: 0 3px;
                border: 1px solid #ddd;
                background: #f7f7f7;
                color: #333;
                text-decoration: none;
            }
            
            .pagination-links span.current {
                background: #0073aa;
                border-color: #0073aa;
                color: #fff;
                font-weight: bold;
            }
            
            .displaying-num {
                margin-right: 10px;
                font-style: italic;
                color: #666;
            }
        </style>
    </div>
    <?php
}
    
    /**
     * Add meta box to product edit screen
     */
    public function add_product_mapping_meta_box() {
        add_meta_box(
            'wpppc_product_mapping_meta_box',
            __('PayPal Proxy Mapping', 'woo-paypal-proxy-client'),
            array($this, 'render_product_mapping_meta_box'),
            'product',
            'side',
            'default'
        );
    }
    
    /**
     * Render meta box
     */
    public function render_product_mapping_meta_box($post) {
    // Get current mapping
    $server_product_id = $this->get_product_mapping($post->ID);
    
    // Check if this is a variable product
    $product = wc_get_product($post->ID);
    $is_variable = $product && $product->is_type('variable');
    
    // Add nonce for security
    wp_nonce_field('wpppc_product_mapping_meta_box', 'wpppc_product_mapping_meta_box_nonce');
    
    ?>
    <p>
        <label for="wpppc_server_product_id"><?php _e('Server Product ID:', 'woo-paypal-proxy-client'); ?></label>
        <input type="number" id="wpppc_server_product_id" name="wpppc_server_product_id" value="<?php echo esc_attr($server_product_id); ?>" min="1" class="widefat">
    </p>
    <p class="description">
        <?php _e('Map this product to a product on the PayPal Proxy server.', 'woo-paypal-proxy-client'); ?>
        <?php if ($is_variable): ?>
            <br>
            <strong><?php _e('Note:', 'woo-paypal-proxy-client'); ?></strong> <?php _e('This mapping will apply to all variations of this product.', 'woo-paypal-proxy-client'); ?>
        <?php endif; ?>
    </p>
    <?php
    }
    
    /**
 * AJAX handler for saving all product mappings
 */
public function save_all_mappings() {
    check_ajax_referer('wpppc-admin-nonce', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array(
            'message' => __('Permission denied.', 'woo-paypal-proxy-client')
        ));
        wp_die();
    }
    
    $mappings = isset($_POST['mappings']) ? $_POST['mappings'] : array();
    
    if (empty($mappings) || !is_array($mappings)) {
        wp_send_json_error(array(
            'message' => __('No valid mappings provided.', 'woo-paypal-proxy-client')
        ));
        wp_die();
    }
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($mappings as $mapping) {
        if (empty($mapping['product_id']) || empty($mapping['server_product_id'])) {
            continue;
        }
        
        $product_id = intval($mapping['product_id']);
        $server_product_id = intval($mapping['server_product_id']);
        
        $result = $this->save_mapping($product_id, $server_product_id);
        
        if ($result) {
            $success_count++;
        } else {
            $error_count++;
        }
    }
    
    if ($error_count > 0) {
        wp_send_json_error(array(
            'message' => sprintf(__('%d mappings saved, %d failed.', 'woo-paypal-proxy-client'), $success_count, $error_count)
        ));
    } else {
        wp_send_json_success(array(
            'message' => sprintf(__('%d mappings saved successfully.', 'woo-paypal-proxy-client'), $success_count)
        ));
    }
    
    wp_die();
}
    
    
    /**
     * Save meta box data
     */
    public function save_product_mapping_meta_box($post_id, $post) {
        // Check if nonce is set
        if (!isset($_POST['wpppc_product_mapping_meta_box_nonce'])) {
            return $post_id;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['wpppc_product_mapping_meta_box_nonce'], 'wpppc_product_mapping_meta_box')) {
            return $post_id;
        }
        
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }
        
        // Check permissions
        if ('product' !== $post->post_type || !current_user_can('edit_post', $post_id)) {
            return $post_id;
        }
        
        // Save the mapping
        if (isset($_POST['wpppc_server_product_id']) && !empty($_POST['wpppc_server_product_id'])) {
            $server_product_id = intval($_POST['wpppc_server_product_id']);
            $this->save_mapping($post_id, $server_product_id);
        } else {
            $this->delete_mapping($post_id);
        }
        
        return $post_id;
    }
    
    /**
     * AJAX handler for saving product mapping
     */
    public function save_product_mapping() {
        check_ajax_referer('wpppc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $server_product_id = isset($_POST['server_product_id']) ? intval($_POST['server_product_id']) : 0;
        
        if (!$product_id || !$server_product_id) {
            wp_send_json_error(array(
                'message' => __('Invalid product ID or server product ID.', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        $result = $this->save_mapping($product_id, $server_product_id);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Mapping saved successfully.', 'woo-paypal-proxy-client')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to save mapping.', 'woo-paypal-proxy-client')
            ));
        }
        
        wp_die();
    }
    
    /**
     * AJAX handler for deleting product mapping
     */
    public function delete_product_mapping() {
        check_ajax_referer('wpppc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error(array(
                'message' => __('Invalid product ID.', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        $result = $this->delete_mapping($product_id);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Mapping deleted successfully.', 'woo-paypal-proxy-client')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to delete mapping.', 'woo-paypal-proxy-client')
            ));
        }
        
        wp_die();
    }
    
    /**
     * AJAX handler for getting all mappings
     */
    public function get_mappings() {
        check_ajax_referer('wpppc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        $mappings = $this->get_all_product_mappings();
        
        wp_send_json_success(array(
            'mappings' => $mappings
        ));
        
        wp_die();
    }
    
    /**
     * Save product mapping
     */
    public function save_mapping($product_id, $server_product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpppc_product_mappings';
        
        // Check if mapping already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE product_id = %d",
            $product_id
        ));
        
        if ($existing) {
            // Update existing mapping
            $result = $wpdb->update(
                $table_name,
                array(
                    'server_product_id' => $server_product_id
                ),
                array('product_id' => $product_id)
            );
        } else {
            // Insert new mapping
            $result = $wpdb->insert(
                $table_name,
                array(
                    'product_id' => $product_id,
                    'server_product_id' => $server_product_id,
                    'created_at' => current_time('mysql')
                )
            );
        }
        
        return $result !== false;
    }
    
    /**
     * Delete product mapping
     */
    public function delete_mapping($product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpppc_product_mappings';
        
        $result = $wpdb->delete(
            $table_name,
            array('product_id' => $product_id)
        );
        
        return $result !== false;
    }
    
    /**
     * Get product mapping
     */
    public function get_product_mapping($product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpppc_product_mappings';
        
        // First, try to get direct mapping for this product ID
        $mapping = $wpdb->get_var($wpdb->prepare(
            "SELECT server_product_id FROM $table_name WHERE product_id = %d",
            $product_id
        ));
        
        // If no mapping found, check if it's a variation and get parent mapping
        if (!$mapping) {
            $product = wc_get_product($product_id);
            if ($product && $product->is_type('variation')) {
                $parent_id = $product->get_parent_id();
                $mapping = $wpdb->get_var($wpdb->prepare(
                    "SELECT server_product_id FROM $table_name WHERE product_id = %d",
                    $parent_id
                ));
            }
        }
        
        return $mapping ? $mapping : '';
    }
    
    /**
     * Get all product mappings
     */
    public function get_all_product_mappings() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpppc_product_mappings';
        
        return $wpdb->get_results("SELECT * FROM $table_name ORDER BY product_id ASC");
    }
    
    /**
     * Get product mappings as an associative array
     */
    public function get_product_mappings_array() {
        $mappings = $this->get_all_product_mappings();
        $result = array();
        
        foreach ($mappings as $mapping) {
            $result[$mapping->product_id] = $mapping->server_product_id;
        }
        
        return $result;
    }
}