<?php
/**
 * Admin settings for WooCommerce PayPal Proxy Client
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce PayPal Proxy Admin Class
 */
class WPPPC_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add ajax handler for testing connection
        add_action('wp_ajax_wpppc_test_connection', array($this, 'test_connection'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('PayPal Proxy Settings', 'woo-paypal-proxy-client'),
            __('PayPal Proxy', 'woo-paypal-proxy-client'),
            'manage_woocommerce',
            'wpppc-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wpppc_settings', 'wpppc_proxy_url');
        register_setting('wpppc_settings', 'wpppc_api_key');
        register_setting('wpppc_settings', 'wpppc_api_secret');
        
        add_settings_section(
            'wpppc_general_settings',
            __('General Settings', 'woo-paypal-proxy-client'),
            array($this, 'general_settings_callback'),
            'wpppc_settings'
        );
        
        add_settings_field(
            'wpppc_proxy_url',
            __('Proxy Website URL', 'woo-paypal-proxy-client'),
            array($this, 'proxy_url_callback'),
            'wpppc_settings',
            'wpppc_general_settings'
        );
        
        add_settings_field(
            'wpppc_api_key',
            __('API Key', 'woo-paypal-proxy-client'),
            array($this, 'api_key_callback'),
            'wpppc_settings',
            'wpppc_general_settings'
        );
        
        add_settings_field(
            'wpppc_api_secret',
            __('API Secret', 'woo-paypal-proxy-client'),
            array($this, 'api_secret_callback'),
            'wpppc_settings',
            'wpppc_general_settings'
        );
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('wpppc_settings');
                do_settings_sections('wpppc_settings');
                submit_button();
                ?>
            </form>
            
            <hr>
            
            <h2><?php _e('Test Connection', 'woo-paypal-proxy-client'); ?></h2>
            <p><?php _e('Test the connection to the proxy website.', 'woo-paypal-proxy-client'); ?></p>
            <button id="wpppc-test-connection" class="button button-secondary">
                <?php _e('Test Connection', 'woo-paypal-proxy-client'); ?>
            </button>
            <div id="wpppc-test-result" style="margin-top: 10px;"></div>
            
            <script>
                jQuery(document).ready(function($) {
                    $('#wpppc-test-connection').on('click', function(e) {
                        e.preventDefault();
                        
                        var $button = $(this);
                        var $result = $('#wpppc-test-result');
                        
                        $button.prop('disabled', true).text('<?php _e('Testing...', 'woo-paypal-proxy-client'); ?>');
                        $result.html('');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wpppc_test_connection',
                                nonce: '<?php echo wp_create_nonce('wpppc-admin-nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $result.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                                } else {
                                    $result.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                                }
                            },
                            error: function() {
                                $result.html('<div class="notice notice-error inline"><p><?php _e('An error occurred. Please try again.', 'woo-paypal-proxy-client'); ?></p></div>');
                            },
                            complete: function() {
                                $button.prop('disabled', false).text('<?php _e('Test Connection', 'woo-paypal-proxy-client'); ?>');
                            }
                        });
                    });
                });
            </script>
        </div>
        <?php
    }
    
    /**
     * General settings callback
     */
    public function general_settings_callback() {
        echo '<p>' . __('Configure the connection to the PayPal proxy website.', 'woo-paypal-proxy-client') . '</p>';
    }
    
    /**
     * Proxy URL field callback
     */
    public function proxy_url_callback() {
        $value = get_option('wpppc_proxy_url');
        echo '<input type="url" id="wpppc_proxy_url" name="wpppc_proxy_url" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Enter the URL of Website B (PayPal proxy).', 'woo-paypal-proxy-client') . '</p>';
    }
    
    /**
     * API Key field callback
     */
    public function api_key_callback() {
        $value = get_option('wpppc_api_key');
        echo '<input type="text" id="wpppc_api_key" name="wpppc_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Enter the API key provided by Website B.', 'woo-paypal-proxy-client') . '</p>';
    }
    
    /**
     * API Secret field callback
     */
    public function api_secret_callback() {
        $value = get_option('wpppc_api_secret');
        echo '<input type="text" id="wpppc_api_secret" name="wpppc_api_secret" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('This secret is used to secure the communication between websites. Keep it private!', 'woo-paypal-proxy-client') . '</p>';
        echo '<button type="button" id="wpppc-generate-secret" class="button button-secondary">' . __('Generate New Secret', 'woo-paypal-proxy-client') . '</button>';
        ?>
        <script>
            jQuery(document).ready(function($) {
                $('#wpppc-generate-secret').on('click', function() {
                    var length = 32;
                    var chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                    var result = '';
                    
                    for (var i = length; i > 0; --i) {
                        result += chars[Math.floor(Math.random() * chars.length)];
                    }
                    
                    $('#wpppc_api_secret').val(result);
                });
            });
        </script>
        <?php
    }
    
    /**
     * Test connection to Website B
     */
    public function test_connection() {
        check_ajax_referer('wpppc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        $proxy_url = get_option('wpppc_proxy_url');
        $api_key = get_option('wpppc_api_key');
        $api_secret = get_option('wpppc_api_secret');
        
        if (empty($proxy_url) || empty($api_key) || empty($api_secret)) {
            wp_send_json_error(array(
                'message' => __('Please fill in all fields first.', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        // Generate test parameters
        $timestamp = time();
        $hash_data = $timestamp . $api_key;
        $hash = hash_hmac('sha256', $hash_data, $api_secret);
        
        // Build test URL
        $params = array(
            'rest_route' => '/wppps/v1/test-connection',
            'api_key'    => $api_key,
            'timestamp'  => $timestamp,
            'hash'       => $hash,
            'site_url'   => base64_encode(get_site_url()),
        );
        
        $url = $proxy_url . '?' . http_build_query($params);
        
        // Make the request
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WooCommerce PayPal Proxy Client/' . WPPPC_VERSION,
            ),
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => sprintf(__('Connection failed: %s', 'woo-paypal-proxy-client'), $response->get_error_message())
            ));
            wp_die();
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            wp_send_json_error(array(
                'message' => sprintf(__('Connection failed with status code: %s', 'woo-paypal-proxy-client'), $response_code)
            ));
            wp_die();
        }
        
        // Get response body
        $body = wp_remote_retrieve_body($response);
        
        // Parse JSON response
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array(
                'message' => __('Invalid response from proxy website', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        if (isset($data['success']) && $data['success'] === true) {
            wp_send_json_success(array(
                'message' => __('Connection successful!', 'woo-paypal-proxy-client')
            ));
        } else {
            wp_send_json_error(array(
                'message' => isset($data['message']) ? $data['message'] : __('Connection failed', 'woo-paypal-proxy-client')
            ));
        }
        
        wp_die();
    }
}