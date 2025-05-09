<?php
/**
 * Template for PayPal Buttons iframe container
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wpppc-paypal-buttons-container">
    <div class="wpppc-iframe-wrapper">
        <iframe 
            id="paypal-proxy-iframe" 
            src="<?php echo esc_url($iframe_url); ?>" 
            frameborder="0" 
            allowtransparency="true"
            scrolling="no"
            sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-same-origin"
            referrerpolicy="no-referrer"
            style="width: 100%; min-height: 150px; border: none; overflow: hidden;"
        ></iframe>
    </div>
    <div id="wpppc-paypal-message" class="wpppc-message" style="display: none;"></div>
    <div id="wpppc-paypal-error" class="wpppc-error" style="display: none;"></div>
    <div id="wpppc-paypal-loading" class="wpppc-loading" style="display: none;">
        <div class="wpppc-spinner"></div>
        <div class="wpppc-loading-text"><?php _e('Processing payment...', 'woo-paypal-proxy-client'); ?></div>
    </div>
</div>