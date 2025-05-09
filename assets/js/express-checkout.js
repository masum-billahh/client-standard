/**
 * WooCommerce PayPal Proxy Client Express Checkout JS
 */

(function($) {
    'use strict';
    
    // Variables to track order status
    var paypalOrderId = null;
    var wcOrderId = null;
    var expressCheckoutActive = false;
    
    /**
     * Debug logging helper
     */
    function debug(message, data) {
        if (wpppc_express_params.debug_mode && console && console.log) {
            if (data) {
                console.log('[PayPal Express]', message, data);
            } else {
                console.log('[PayPal Express]', message);
            }
        }
    }
    
    /**
     * Show loading indicator
     */
    function showLoading(container) {
        var loadingHtml = '<div class="wpppc-express-loading"><div class="wpppc-express-spinner"></div><span>Processing...</span></div>';
        $(container).find('.wpppc-express-loading').remove();
        $(container).append(loadingHtml);
        $(container).find('.wpppc-express-loading').show();
    }
    
    /**
     * Hide loading indicator
     */
    function hideLoading(container) {
        $(container).find('.wpppc-express-loading').hide();
    }
    
    /**
     * Show error message
     */
    function showError(message, container) {
        var targetContainer = container || '';
        
        if (targetContainer) {
            $(targetContainer).find('#wpppc-express-error').text(message).show();
            $(targetContainer).find('#wpppc-express-message').hide();
        } else {
            $('#wpppc-express-error').text(message).show();
            $('#wpppc-express-message').hide();
        }
        
        // Scroll to the message
        $('html, body').animate({
            scrollTop: $(targetContainer || '#wpppc-express-error').offset().top - 100
        }, 300);
        
        debug('Error displayed: ' + message);
    }
    
    /**
     * Show success message
     */
    function showMessage(message, container) {
        var targetContainer = container || '';
        
        if (targetContainer) {
            $(targetContainer).find('#wpppc-express-message').text(message).show();
            $(targetContainer).find('#wpppc-express-error').hide();
        } else {
            $('#wpppc-express-message').text(message).show();
            $('#wpppc-express-error').hide();
        }
        
        debug('Message displayed: ' + message);
    }
    
   function createExpressButtonIframe(target) {
    debug('Creating Express Checkout button iframe on ' + target);
    
    // Create iframe element
    var iframe = document.createElement('iframe');
    iframe.id = 'paypal-express-iframe-' + target.replace('#', '');
    
    // Use base iframe URL - we'll update with current totals when button is clicked
    iframe.src = wpppc_express_params.iframe_url;
    iframe.frameBorder = 0;
    iframe.scrolling = 'no';
    iframe.style.width = '100%';
    iframe.style.minHeight = '45px';
    iframe.style.height = '45px';
    iframe.style.overflow = 'hidden';
    iframe.style.border = 'none';
    
    // Set sandbox attributes for security
    iframe.setAttribute('sandbox', 'allow-scripts allow-forms allow-popups allow-same-origin allow-top-navigation');
    
    // Append iframe to container
    $(target).html('');
    $(target).append(iframe);
    
    // Setup message event listener for iframe communication
    window.addEventListener('message', handleIframeMessages);
    
    debug('Iframe created for Express Checkout button on ' + target);
}
    
/**
 * Get current checkout totals with better tax detection
 */
/**
 * Get current checkout totals with better tax detection
 */
function getCurrentCheckoutTotals() {
    var orderReview = $('.woocommerce-checkout-review-order-table');
    
    // Try multiple selectors for tax
    var taxSelectors = [
        '.tax-total .woocommerce-Price-amount',
        '.order-total-tax .woocommerce-Price-amount',
        '.woocommerce-checkout-review-order table tr.tax-rate .woocommerce-Price-amount',
        '.woocommerce-checkout-review-order table tr[class*="tax"] .woocommerce-Price-amount'
    ];
    
    var taxValue = '';
    for (var i = 0; i < taxSelectors.length; i++) {
        taxValue = orderReview.find(taxSelectors[i]).text();
        if (taxValue) break;
    }
    
    // If still no tax found, try to detect from the page structure
    if (!taxValue) {
        // Check for any row with 'tax' in its class or text
        orderReview.find('tr').each(function() {
            var rowText = $(this).text().toLowerCase();
            if (rowText.includes('tax') && rowText !== '') {
                var amount = $(this).find('.woocommerce-Price-amount').text();
                if (amount) taxValue = amount;
            }
        });
    }
    
    var currentData = {
        subtotal: orderReview.find('.cart-subtotal .woocommerce-Price-amount').text(),
        shipping: getSelectedShippingCost(),
        tax: taxValue,
        total: orderReview.find('.order-total .woocommerce-Price-amount').text(),
        shipping_method: $('input[name^="shipping_method"]:checked').val()
    };
    
    debug('Raw captured values:', currentData);
    
    // Parse amounts with proper decimal handlin
    var parsedData = {
        total: parseFloat(currentData.total.replace(/[^0-9.]/g, '')),
        subtotal: parseFloat(currentData.subtotal.replace(/[^0-9.]/g, '')),
        shipping: parseFloat(currentData.shipping.replace(/[^0-9.]/g, '')),
        tax: parseFloat(currentData.tax.replace(/[^0-9.]/g, '')) || 0,
        shipping_method: currentData.shipping_method
    };
    
    debug('Parsed checkout totals:', parsedData);
    
    // Calculate tax by deduction if not found
    if (parsedData.tax === 0) {
        var calculatedTax = parsedData.total - parsedData.subtotal - parsedData.shipping;
        if (calculatedTax > 0) {
            parsedData.tax = calculatedTax;
            debug('Calculated tax by deduction:', calculatedTax);
        }
    }
    
    return parsedData;
}

function cleanAndParseAmount(amount) {
    if (!amount) return 0;
    
    // Remove all non-numeric characters except decimal point
    var cleaned = amount.replace(/[^0-9.]/g, '');
    
    // Ensure only one decimal point
    var parts = cleaned.split('.');
    if (parts.length > 2) {
        cleaned = parts[0] + '.' + parts.slice(1).join('');
    }
    
    // Parse to float and ensure 2 decimal places
    var parsed = parseFloat(cleaned);
    
    // Round to 2 decimal places to avoid floating point errors
    return Math.round(parsed * 100) / 100;
}
    
    /**
     * Update iframe URL with current totals
     */
    function updateIframeUrlWithTotals(baseUrl, totals) {
        var url = new URL(baseUrl);
        
        // Update amount parameter
        url.searchParams.set('amount', totals.total.toFixed(2));
        
        // Add breakdown parameters
        url.searchParams.set('subtotal', totals.subtotal.toFixed(2));
        url.searchParams.set('shipping', totals.shipping.toFixed(2));
        url.searchParams.set('tax', totals.tax.toFixed(2));
        url.searchParams.set('shipping_method', totals.shipping_method || '');
        
        debug('Updated iframe URL with totals:', url.toString());
        return url.toString();
    }
    
    /**
     * Handle messages from the iframe
     */
    function handleIframeMessages(event) {
        // Validate message
        if (!event.data || !event.data.action || event.data.source !== 'paypal-express-proxy') {
            return;
        }
        
        debug('Received message from iframe:', event.data);
        
        var container = '#wpppc-express-paypal-button-cart';
        if (wpppc_express_params.is_checkout_page) {
            container = '#wpppc-express-paypal-button-checkout';
        }
        
        // Handle different actions
        switch (event.data.action) {
            case 'button_loaded':
                debug('PayPal Express button loaded');
                break;
                
            case 'button_clicked':
                debug('PayPal Express button clicked');
                handleExpressCheckoutStart(container);
                break;
                
            case 'payment_approved':
                debug('Payment approved in PayPal', event.data.payload);
                completeExpressCheckout(event.data.payload, container);
                break;
                
            case 'payment_cancelled':
                debug('Payment cancelled by user');
                showError('Payment cancelled. You can try again when ready.', container);
                expressCheckoutActive = false;
                break;
                
            case 'payment_error':
                debug('Payment error:', event.data.error);
                showError('Error processing payment: ' + (event.data.error.message || 'Unknown error'), container);
                expressCheckoutActive = false;
                break;
                
            case 'resize_iframe':
                // Resize the iframe based on content
                if (event.data.height) {
                    $('#' + event.data.iframeId).css('height', event.data.height + 'px');
                    debug('Resized iframe to ' + event.data.height + 'px');
                }
                break;
        }
    }
    
    /**
     * Start Express Checkout process
     */
    function handleExpressCheckoutStart(container) {
    if (expressCheckoutActive) {
        debug('Express checkout already in progress, ignoring click');
        return;
    }
    
    expressCheckoutActive = true;
    showLoading(container);
    
    debug('Starting Express Checkout process');
    
    // Get current checkout totals and selected shippin
    var currentTotals = getCurrentCheckoutTotals();
    
    // Create WooCommerce order via AJAX
    $.ajax({
        url: wpppc_express_params.ajax_url,
        type: 'POST',
        data: {
            action: 'wpppc_create_express_order',
            nonce: wpppc_express_params.nonce,
            current_totals: currentTotals
        },
        success: function(response) {
            if (response.success) {
                wcOrderId = response.data.order_id;
                paypalOrderId = response.data.paypal_order_id;
                
                debug('Express order created. WC Order ID: ' + wcOrderId + ', PayPal Order ID: ' + paypalOrderId);
                
                // Send order data to iframe
                sendMessageToIframe({
                    action: 'create_paypal_order',
                    order_id: wcOrderId,
                    paypal_order_id: paypalOrderId
                });
                
                hideLoading(container);
            } else {
                expressCheckoutActive = false;
                hideLoading(container);
                showError(response.data.message || 'Failed to create order', container);
            }
        },
        error: function() {
            expressCheckoutActive = false;
            hideLoading(container);
            showError('Error communicating with the server', container);
        }
    });
}
    
   /**
 * Complete express checkout
 */
function completeExpressCheckout(paymentData, container) {
    debug('Completing express checkout with payment data', paymentData);
    
    // Show loading indicator
    showLoading(container);
    showMessage('Fetching order details...', container);
    
    // Fetch PayPal order details to get shipping/billing address
    $.ajax({
        url: wpppc_express_params.ajax_url,
        type: 'POST',
        data: {
            action: 'wpppc_fetch_paypal_order_details',
            nonce: wpppc_express_params.nonce,
            order_id: wcOrderId,
            paypal_order_id: paypalOrderId
        },
        success: function(detailsResponse) {
            debug('Got PayPal order details:', detailsResponse);
            
            // Complete the payment
            finalizePayment();
        },
        error: function(xhr, status, error) {
            console.error('Error fetching PayPal order details:', error);
            // Continue with payment completion even if details fetch fails
            finalizePayment();
        }
    });
    
    // Finalize payment
    function finalizePayment() {
        showMessage('Finalizing your order...', container);
        
        // Complete the order via AJAX
        $.ajax({
            url: wpppc_express_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wpppc_complete_express_order',
                nonce: wpppc_express_params.nonce,
                order_id: wcOrderId,
                paypal_order_id: paypalOrderId
            },
            success: function(response) {
                hideLoading(container);
                
                if (response.success) {
                    debug('Order completed successfully, redirecting to:', response.data.redirect);
                    
                    // Show success message before redirect
                    showMessage('Payment successful! Redirecting to order confirmation...', container);
                    
                    // Redirect to thank you page
                    setTimeout(function() {
                        window.location.href = response.data.redirect;
                    }, 1000);
                } else {
                    expressCheckoutActive = false;
                    showError(response.data.message || 'Failed to complete order', container);
                }
            },
            error: function() {
                expressCheckoutActive = false;
                hideLoading(container);
                showError('Error communicating with the server', container);
            }
        });
    }
}
    
    /**
     * Send message to the iframe
     */
    function sendMessageToIframe(message) {
        var iframe;
        
        if (wpppc_express_params.is_checkout_page) {
            iframe = document.getElementById('paypal-express-iframe-wpppc-express-paypal-button-checkout');
        } else {
            iframe = document.getElementById('paypal-express-iframe-wpppc-express-paypal-button-cart');
        }
        
        if (!iframe || !iframe.contentWindow) {
            debug('Cannot find PayPal Express iframe');
            return;
        }
        
        // Add source identifier
        message.source = 'woocommerce-client';
        
        debug('Sending message to iframe', message);
        
        // Send message to iframe
        iframe.contentWindow.postMessage(message, '*');
    }
    
    /**
 * Get selected shipping cost more accurately
 */
function getSelectedShippingCost() {
    var selectedShipping = $('input[name^="shipping_method"]:checked');
    
    // First try: Get from the label
    var shippingCost = selectedShipping.closest('li').find('label .woocommerce-Price-amount').first().text();
    
    // Second try: Look for data attribute
    if (!shippingCost || shippingCost.indexOf('$') === -1) {
        var dataTable = selectedShipping.closest('tr').find('.woocommerce-Price-amount').first().text();
        if (dataTable) shippingCost = dataTable;
    }
    
    // Third try: Get from shipping row in order review
    if (!shippingCost || shippingCost.indexOf('$') === -1) {
        shippingCost = $('.shipping .woocommerce-Price-amount').last().text();
    }
    
    debug('Found shipping cost:', shippingCost);
    
    return shippingCost;
}
    
    /**
     * Initialize Express Checkout
     */
    function initExpressCheckout() {
    debug('Initializing PayPal Express Checkout');
    
    // Create express checkout buttons
    if (wpppc_express_params.is_cart_page) {
        createExpressButtonIframe('#wpppc-express-paypal-button-cart');
    }
    
    if (wpppc_express_params.is_checkout_page) {
        createExpressButtonIframe('#wpppc-express-paypal-button-checkout');
    }
    
    // Listen for checkout updates to refresh totals
    if (wpppc_express_params.is_checkout_page) {
        $('body').on('updated_checkout', function() {
            debug('Checkout updated, recreating iframe...');
            // Recreate iframe with updated totals
            createExpressButtonIframe('#wpppc-express-paypal-button-checkout');
        });
        
        // Also listen for shipping method changes
        $(document).on('change', 'input[name^="shipping_method"]', function() {
            debug('Shipping method changed, will refresh on next update...');
            // Note: The iframe will be recreated on next updated_checkout event
        });
    }
}
    
    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Initialize only if we have PayPal button containers
        if ($('.wpppc-express-paypal-button').length > 0) {
            initExpressCheckout();
        } else {
            debug('No PayPal Express button containers found');
        }
    });
    
})(jQuery);