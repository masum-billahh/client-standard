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
            style="width: 100%; height: 50px; border: none; overflow: hidden;"
        ></iframe>
    </div>
    <div id="wpppc-paypal-message" class="wpppc-message" style="display: none;"></div>
    <div id="wpppc-paypal-error" class="wpppc-error" style="display: none;"></div>
    <div id="wpppc-paypal-loading" class="wpppc-loading" style="display: none;">
        <div class="wpppc-spinner"></div>
        <div class="wpppc-loading-text"><?php _e('Processing payment...', 'woo-paypal-proxy-client'); ?></div>
    </div>
</div>


<script>
jQuery(function ($) {
    let iframe = $('#paypal-proxy-iframe');
    let something = $('#paypal-buttons-container');

    // Success: iframe loads
    iframe.on('load', function () {
        if (something.length) {
            iframe.css('height', '150px');
            console.log('Content available, height increased.');
        } 
    });

    // Fallback: if not loaded in 1 seconds
    setTimeout(function () {
        if (!iframe[0].contentWindow || iframe[0].contentWindow.length === 0) {
            console.log('Iframe failed or blocked.');
            iframe.css('height', '50px'); 
            $('<div style="color:red; padding-top:10px; text-align:center;">Sorry, something went wrong. Please refresh the page or contact the site owner.</div>').insertAfter(iframe);
        }
    }, 2000);
});
</script>