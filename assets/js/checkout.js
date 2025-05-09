/**
 * WooCommerce PayPal Proxy Client Checkout JS
 */

(function($) {
    'use strict';
    
    // PayPal Button Status
    var paypalButtonLoaded = false;
    var creatingOrder = false;
    var orderCreated = false;
    var orderID = null;
    
    // Store error messages
    var errorMessages = {};
    
    // PayPal data received from iframe
    var paypalData = {};
    
    /**
     * Initialize the checkout handlers
     */
    function init() {
        // Listen for messages from iframe
        setupMessageListener();
        
        // Handle checkout form submission
        handleCheckoutSubmission();
        
        // Handle iframe loading event
        $('#paypal-proxy-iframe').on('load', function() {
            paypalButtonLoaded = true;
        });
        
        // Listen for validation errors
        $(document.body).on('checkout_error', function() {
            // Reset order creation flag if validation fails
            creatingOrder = false;
            orderCreated = false;
        });
        
        // Hide the standard "Place Order" button when PayPal is selected
        $('form.checkout').on('change', 'input[name="payment_method"]', function() {
            if ($(this).val() === 'paypal_proxy') {
                $('#place_order').hide();
            } else {
                $('#place_order').show();
            }
        });
        
        // Also check on page load
        if ($('input[name="payment_method"]:checked').val() === 'paypal_proxy') {
            $('#place_order').hide();
        }
        
        // Listen for WooCommerce checkout updates
        $(document.body).on('updated_checkout', function() {
            // Check if PayPal is selected after checkout update
            if ($('input[name="payment_method"]:checked').val() === 'paypal_proxy') {
                $('#place_order').hide();
            }
        });
    }
    
    /**
     * Setup message listener for communication with iframe
     */
    function setupMessageListener() {
        window.addEventListener('message', function(event) {
            // Validate origin
            const iframeUrl = new URL($('#paypal-proxy-iframe').attr('src'));
            if (event.origin !== iframeUrl.origin) {
                return;
            }
            
            const data = event.data;
            
            // Check if message is for us
            if (!data || !data.action || data.source !== 'paypal-proxy') {
                return;
            }
            
            // Handle different actions
            switch (data.action) {
                case 'button_loaded':
                    paypalButtonLoaded = true;
                    break;
                    
                case 'button_clicked':
                    handlePayPalButtonClick();
                    break;
                    
                case 'order_approved':
                    handleOrderApproved(data.payload);
                    break;
                    
                case 'payment_cancelled':
                    handlePaymentCancelled();
                    break;
                    
                case 'payment_error':
                    handlePaymentError(data.error);
                    break;
                    
                 case 'resize_iframe':
                // Handle iframe resizing
                if (data.height) {
                    $('#paypal-proxy-iframe').css('height', data.height + 'px');
                }
                break;
            }
        });
    }
    
function handlePayPalButtonClick() {
    if (creatingOrder || orderCreated) {
        return;
    }
    
    creatingOrder = true;
    
    // Clear previous errors
    clearErrors();
    
    validateCheckoutFields().then(function(validationResult) {
        if (!validationResult.valid) {
            displayErrors(validationResult.errors);
            creatingOrder = false;
            
            $('body').trigger('update_checkout');
            
            // Send a special validation failed message to the iframe
            // PayPal SDK needs to handle this properly
            sendMessageToIframe({
                action: 'order_creation_failed',
                errors: validationResult.errors,
                isValidationError: true, // Add this flag
                message: 'Validation failed'
            });
            
            
            
            return;
        }
        
        // Create WooCommerce order
        createOrder().then(function(orderData) {
            // Order created successfully
            orderID = orderData.order_id;
            orderCreated = true;
            creatingOrder = false;
            
            // Send message to iframe with order info
            sendMessageToIframe({
                action: 'create_paypal_order',
                order_id: orderID,
                order_key: orderData.order_key,
                proxy_data: orderData.proxy_data
            });
        }).catch(function(error) {
            creatingOrder = false;
            
            if (error.errors) {
                displayErrors(error.errors);
            } else {
                displayError('general', error.message || 'Failed to create order. Please try again.');
            }
            
            // Send message to iframe about the failure
            sendMessageToIframe({
                action: 'order_creation_failed',
                message: error.message || 'Failed to create order'
            });
        });
    }).catch(function(error) {
        creatingOrder = false;
    });
}

    
    /**
     * Validate checkout fields via AJAX
     */
    function validateCheckoutFields() {
        return new Promise(function(resolve, reject) {
            // Get form data
            const formData = $('form.checkout').serialize();
            
            // Send AJAX request
            $.ajax({
                type: 'POST',
                url: wpppc_params.ajax_url,
                data: {
                    action: 'wpppc_validate_checkout',
                    nonce: wpppc_params.nonce,
                    ...parseFormData(formData)
                },
                success: function(response) {
                    if (response.success) {
                        resolve({ valid: true });
                    } else {
                        resolve({ valid: false, errors: response.data.errors });
                    }
                },
                error: function(xhr, status, error) {
                    reject({ message: 'Validation request failed', xhr: xhr });
                }
            });
        });
    }
    
    /**
     * Create WooCommerce order via AJAX
     */
    function createOrder() {
    //console.log("Creating order with the following data:");
    //console.log("Form data:", $('form.checkout').serialize());
    
    return new Promise(function(resolve, reject) {
        // Get form data
        const formData = $('form.checkout').serialize();
        
        // Send AJAX request - FIX: Changed action name to match PHP handler
        $.ajax({
            type: 'POST',
            url: wpppc_params.ajax_url,
            data: {
                action: 'wpppc_create_order', // Changed from wpppc_ajax_create_order to match PHP
                nonce: wpppc_params.nonce,
                ...parseFormData(formData)
            },
            success: function(response) {
                //console.log("Order creation response:", response);
                if (response.success) {
                    resolve(response.data);
                } else {
                    reject({ message: response.data.message, errors: response.data.errors });
                }
            },
            error: function(xhr, status, error) {
                //console.error("Order creation error details:", xhr.responseText);
                reject({ message: 'Order creation request failed: ' + error, xhr: xhr });
            }
        });
    });
}
    
    /**
     * Complete payment after PayPal approval
     */
    function completePayment(paymentData) {
    console.log('Making completePayment AJAX request with data:', {
        order_id: orderID,
        paypal_order_id: paymentData.orderID,
        transaction_id: paymentData.transactionID || ''
    });

    return new Promise(function(resolve, reject) {
        $.ajax({
            type: 'POST',
            url: wpppc_params.ajax_url,
            data: {
                action: 'wpppc_complete_order',
                nonce: wpppc_params.nonce,
                order_id: orderID,
                paypal_order_id: paymentData.orderID,
                transaction_id: paymentData.transactionID || ''
            },
            success: function(response) {
                //console.log('Complete payment response:', response);
                if (response.success) {
                    resolve(response.data);
                } else {
                    reject({ message: response.data.message });
                }
            },
            error: function(xhr, status, error) {
                console.error('Complete payment AJAX error:', xhr.responseText);
                reject({ message: 'Payment completion request failed: ' + error, xhr: xhr });
            }
        });
    });
}
    
    /**
     * Handle order approved by PayPal
     */
    function handleOrderApproved(payload) {
    console.log('Order approved with payload:', payload);
    
    if (!orderCreated || !orderID) {
        console.error('No order created or invalid order ID');
        return;
    }
    
    // Store PayPal data
    paypalData = payload;
    
    // Show a loading message to the user
    showLoading('Finalizing your payment...');
    
     var serverId = '';
    if (payload.proxy_data && payload.proxy_data.server_id) {
        serverId = payload.proxy_data.server_id;
        console.log('Using server ID from proxy_data:', serverId);
    }
    
    // Complete the payment directly from Website A (not from the iframe)
    $.ajax({
        type: 'POST',
        url: wpppc_params.ajax_url,
        data: {
            action: 'wpppc_complete_order',
            nonce: wpppc_params.nonce,
            order_id: orderID,
            paypal_order_id: payload.orderID,
            transaction_id: payload.transactionID || '',
            server_id: serverId
        },
        success: function(response) {
            console.log('Payment completion response:', response);
            hideLoading();
            
            if (response.success && response.data && response.data.redirect) {
                // Redirect to thank you page
                window.location.href = response.data.redirect;
            } else {
                displayError('general', (response.data && response.data.message) || 'Failed to complete payment. Please contact customer support.');
                
                // Reset order flags
                orderCreated = false;
                orderID = null;
            }
        },
        error: function(xhr, status, error) {
            console.error('Payment completion error:', xhr.responseText);
            hideLoading();
            displayError('general', 'Failed to complete payment. Please contact customer support with this transaction ID: ' + payload.transactionID);
            
            // Reset order flags
            orderCreated = false;
            orderID = null;
        }
    });
}

function showLoading(message) {
    $('#wpppc-paypal-loading').show();
    $('.wpppc-loading-text').text(message || 'Processing payment...');
}

function hideLoading() {
    $('#wpppc-paypal-loading').hide();
}
    
    /**
     * Handle payment cancelled
     */
    function handlePaymentCancelled() {
        console.log('Payment cancelled by user');
        
        // Reset order flags
        orderCreated = false;
        orderID = null;
    }
    
    /**
     * Handle payment error
     */
    function handlePaymentError(error) {
        console.error('Payment error:', error);
        //displayError('general', 'PayPal error: ' + (error.message || 'Unknown error'));
        
        // Reset order flags
        orderCreated = false;
        orderID = null;
    }
    
    /**
     * Send message to iframe
     */
    function sendMessageToIframe(message) {
    const iframe = document.getElementById('paypal-proxy-iframe');
    if (!iframe || !iframe.contentWindow) {
        console.error('Cannot find PayPal iframe');
        return;
    }
    
    // Add source identifier
    message.source = 'woocommerce-site';
    
    console.log('Sending message to iframe:', message);
    
    // For development/testing, use wildcard origin
    // In production, you should use the actual iframe origin
    const targetOrigin = '*';
    
    try {
        // Send message
        iframe.contentWindow.postMessage(message, targetOrigin);
        console.log('Message sent successfully to iframe');
    } catch (error) {
        console.error('Error sending message to iframe:', error);
    }
}
    
    /**
     * Handle checkout form submission
     */
    function handleCheckoutSubmission() {
        $('form.checkout').on('checkout_place_order_paypal_proxy', function() {
            // If we've already created an order via PayPal, let the form submit normally
            if (orderCreated && orderID) {
                return true;
            }
            
            // Otherwise, prevent form submission and trigger PayPal button click
            if (paypalButtonLoaded) {
                sendMessageToIframe({
                    action: 'trigger_paypal_button'
                });
            }
            
            return false;
        });
    }
    
    /**
     * Display checkout validation errors
     */
    function displayErrors(errors) {
        // Clear previous errors
        clearErrors();
        
        // Store new errors
        errorMessages = errors;
        
        // Add error messages to the page
        $.each(errors, function(field, message) {
            const $field = $('#' + field);
            const $parent = $field.closest('.form-row');
            $parent.addClass('woocommerce-invalid');
            $parent.append('<span class="woocommerce-error">' + message + '</span>');
        });
        
        // Scroll to the first error
        const $firstErrorField = $('.woocommerce-invalid:first');
        if ($firstErrorField.length) {
            $('html, body').animate({
                scrollTop: $firstErrorField.offset().top - 100
            }, 500);
        }
    }
    
    /**
     * Display a single error message
     */
    function displayError(field, message) {
        const errors = {};
        errors[field] = message;
        displayErrors(errors);
    }
    
    /**
     * Clear all error messages
     */
    function clearErrors() {
        $('.woocommerce-error').remove();
        $('.woocommerce-invalid').removeClass('woocommerce-invalid');
        errorMessages = {};
    }
    
    /**
     * Parse form data string into object
     */
    function parseFormData(formData) {
        const data = {};
        const pairs = formData.split('&');
        
        for (let i = 0; i < pairs.length; i++) {
            const pair = pairs[i].split('=');
            const key = decodeURIComponent(pair[0]);
            const value = decodeURIComponent(pair[1] || '');
            
            // Handle array-like names (e.g., shipping_method[0])
            if (key.match(/\[\d*\]$/)) {
                const base = key.replace(/\[\d*\]$/, '');
                if (!data[base]) data[base] = [];
                data[base].push(value);
            } else {
                data[key] = value;
            }
        }
        
        return data;
    }
    
    $('form.checkout').on('change', 'input[name="billing_first_name"], input[name="billing_last_name"], input[name="billing_email"], input[name="billing_address_1"]', function() {
        $('body').trigger('update_checkout');
    });
    
    // Initialize on document ready
    $(document).ready(init);
    
})(jQuery);