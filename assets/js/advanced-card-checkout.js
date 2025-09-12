/**
 * WooCommerce PayPal Advanced Card Checkout JS
 */

(function($) {
    'use strict';
    
    // Card processing status
    var cardFieldsLoaded = false;
    var creatingOrder = false;
    var orderCreated = false;
    var orderID = null;
    var cardFieldsReady = false;
    
    // Store error messages
    var errorMessages = {};
    
    /**
     * Initialize the advanced card checkout
     */
    function init() {
        // Only initialize if advanced card payment method is selected
        if ($('input[name="payment_method"]:checked').val() !== 'paypal_advanced_card') {
            return;
        }
        
        // Listen for messages from iframe
        setupMessageListener();
        
        // Handle checkout form submission
        handleCheckoutSubmission();
        
        // Handle iframe loading event
        $('#paypal-card-iframe').on('load', function() {
            cardFieldsLoaded = true;
        });
        
        // Listen for payment method changes
        $('form.checkout').on('change', 'input[name="payment_method"]', function() {
            if ($(this).val() === 'paypal_advanced_card') {
                // Initialize card fields when selected
                initializeCardFields();
            }
        });
        
        // Initialize if already selected
        if ($('input[name="payment_method"]:checked').val() === 'paypal_advanced_card') {
            initializeCardFields();
        }
    }
    
    /**
     * Initialize card fields
     */
    function initializeCardFields() {
        if (cardFieldsLoaded) {
            sendMessageToIframe({
                action: 'initialize_card_fields'
            });
        }
    }
    
    /**
     * Setup message listener for communication with iframe
     */
    function setupMessageListener() {
        window.addEventListener('message', function(event) {
            // Validate origin
            const iframeUrl = new URL($('#paypal-card-iframe').attr('src'));
            if (event.origin !== iframeUrl.origin) {
                return;
            }
            
            const data = event.data;
            
            // Check if message is for us
            if (!data || !data.action || data.source !== 'paypal-card-proxy') {
                return;
            }
            
            // Handle different actions
            switch (data.action) {
                case 'card_fields_loaded':
                    cardFieldsLoaded = true;
                    cardFieldsReady = true;
                    break;
                    
                case 'card_fields_ready':
                    cardFieldsReady = true;
                    break;
                    
                case 'get_billing_data':
                    handleBillingDataRequest();
                    break;
                    
                case 'card_validation_error':
                    handleCardValidationError(data.error);
                    $('body').trigger('update_checkout');
                    break;
                    
                case 'order_approved':
                    handleOrderApproved(data.payload);
                    break;
                    
                case 'payment_error':
                    handlePaymentError(data.error);
                    $('body').trigger('update_checkout');
                    break;
                    
                case 'resize_iframe':
                   $('#paypal-card-iframe').css('height', '400px');
                   break;
            }
        });
    }
    
    /**
     * Handle billing data request from iframe
     */
    function handleBillingDataRequest() {
        const billingAddress = {
            addressLine1: $('#billing_address_1').val() || '',
            addressLine2: $('#billing_address_2').val() || '',
            adminArea1: $('#billing_state').val() || '',
            adminArea2: $('#billing_city').val() || '',
            postalCode: $('#billing_postcode').val() || '',
            countryCode: $('#billing_country').val() || 'US',
            firstName: $('#billing_first_name').val() || '',
            lastName: $('#billing_last_name').val() || ''
        };
        
        sendMessageToIframe({
            action: 'billing_data_response',
            billingAddress: billingAddress
        });
    }
    
    /**
     * Handle checkout form submission
     */
   function handleCheckoutSubmission() {
    $(document).on('click', '#place_order', function(e) {
        // Only handle if PayPal Advanced Card is selected
        if ($('input[name="payment_method"]:checked').val() !== 'paypal_advanced_card') {
            return true; // Let normal checkout proceed
        }
        
        // If we've already created an order, let the form submit normally
        if (orderCreated && orderID) {
            return true;
        }
        
        // Prevent the default form submission
        e.preventDefault();
        e.stopImmediatePropagation();
        
        // Process our PayPal card payment
        if (cardFieldsReady) {
            processCardPayment();
        } else {
            displayError('general', 'Card fields are not ready. Please try again.');
        }
        
        return false;
    });
}
    
   /**
 * Process card payment - Fixed version
 */
function processCardPayment() {
    if (creatingOrder || orderCreated) {
        return;
    }
    
    creatingOrder = true;
    clearErrors();
    
    // Validate checkout fields first
    validateCheckoutFields().then(function(validationResult) {
        if (!validationResult.valid) {
            displayErrors(validationResult.errors);
            creatingOrder = false;
            return;
        }
        
        // Don't create WooCommerce order yet!
        // Instead, send cart data to iframe for PayPal processing
        const cartData = {
            billing_address: {
                first_name: $('#billing_first_name').val() || '',
                last_name: $('#billing_last_name').val() || '',
                address_1: $('#billing_address_1').val() || '',
                address_2: $('#billing_address_2').val() || '',
                city: $('#billing_city').val() || '',
                state: $('#billing_state').val() || '',
                postcode: $('#billing_postcode').val() || '',
                country: $('#billing_country').val() || 'US',
                email: $('#billing_email').val() || '',
                phone: $('#billing_phone').val() || ''
            },
            cart_data: parseFormData($('form.checkout').serialize())
        };
        
        creatingOrder = false;
        
        // Send cart data to iframe for PayPal processing
        sendMessageToIframe({
            action: 'process_cart_payment',
            cart_data: cartData
        });
        
    }).catch(function(error) {
        creatingOrder = false;
        displayError('general', 'Validation failed. Please try again.');
    });
}
    
    /**
     * Validate checkout fields via AJAX
     */
    function validateCheckoutFields() {
        return new Promise(function(resolve, reject) {
            const formData = $('form.checkout').serialize();
            
            $.ajax({
                type: 'POST',
                url: wpppc_card_params.ajax_url,
                data: {
                    action: 'wpppc_card_validate_checkout',
                    nonce: wpppc_card_params.nonce,
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
        return new Promise(function(resolve, reject) {
            const formData = $('form.checkout').serialize();
            
            $.ajax({
                type: 'POST',
                url: wpppc_card_params.ajax_url,
                data: {
                    action: 'wpppc_card_create_order',
                    nonce: wpppc_card_params.nonce,
                    ...parseFormData(formData)
                },
                success: function(response) {
                    if (response.success) {
                        resolve(response.data);
                    } else {
                        reject({ message: response.data.message, errors: response.data.errors });
                    }
                },
                error: function(xhr, status, error) {
                    reject({ message: 'Order creation request failed: ' + error, xhr: xhr });
                }
            });
        });
    }
    
    /**
     * Handle card validation error
     */
    function handleCardValidationError(error) {
        displayError('card', error.message || 'Please check your card details and try again.');
    }
    
    /**
 * Handle order approved by PayPal - Now creates WooCommerce order
 */
function handleOrderApproved(payload) {
    console.log('PayPal payment approved, creating WooCommerce order:', payload);
    
    // Now create the WooCommerce order since PayPal payment succeeded
    const formData = $('form.checkout').serialize();
    const parsedData = parseFormData(formData);
    
    $.ajax({
        type: 'POST',
        url: wpppc_card_params.ajax_url,
        data: {
            action: 'wpppc_card_create_order_after_payment',
            nonce: wpppc_card_params.nonce,
            paypal_order_id: payload.orderID,
            transaction_id: payload.transactionID || payload.orderID,
            ...parseFormData(formData)
        },
        success: function(response) {
            if (response.success && response.data && response.data.redirect) {
                window.location.href = response.data.redirect;
            } else {
                displayError('general', response.data && response.data.message || 'Failed to complete order. Please contact customer support.');
            }
        },
        error: function(xhr, status, error) {
            displayError('general', 'Failed to complete order. Please contact customer support.');
        }
    });
}
    /**
     * Handle payment error
     */
    function handlePaymentError(error) {
        console.error('Card payment error:', error);
        displayError('general', error.message || 'Payment failed. Please try again.');
        
        // Reset order flags
        orderCreated = false;
        orderID = null;
    }
    
    /**
     * Send message to iframe
     */
    function sendMessageToIframe(message) {
        const iframe = document.getElementById('paypal-card-iframe');
        if (!iframe || !iframe.contentWindow) {
            console.error('Cannot find PayPal card iframe');
            return;
        }
        
        message.source = 'woocommerce-card-site';
        
        try {
            iframe.contentWindow.postMessage(message, '*');
        } catch (error) {
            console.error('Error sending message to iframe:', error);
        }
    }
    
    /**
     * Display checkout validation errors
     */
    function displayErrors(errors) {
        clearErrors();
        errorMessages = errors;
        
        $.each(errors, function(field, message) {
            const $field = $('#' + field);
            const $parent = $field.closest('.form-row');
            $parent.addClass('woocommerce-invalid');
            $parent.append('<span class="woocommerce-error">' + message + '</span>');
        });
        
        // Scroll to first error
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
    
    // Initialize on document ready
    $(document).ready(init);
    
})(jQuery);