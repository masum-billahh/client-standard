/**
 * WooCommerce PayPal Proxy Client Checkout JS
 */

(function($) {
    'use strict';
    
    // CSS for expanded iframe
    const style = document.createElement('style');
    style.innerHTML = `
        .paypal-iframe-expanded {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            z-index: 9999 !important;
            
        }
    `;
    document.head.appendChild(style);
    
    // PayPal Button Status
    var paypalButtonLoaded = false;
    var creatingOrder = false;
    var orderCreated = false;
    var orderID = null;
    var fundingSource = null;
    
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
                if (data.fundingSource) {
                    fundingSource = data.fundingSource;
                }
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
               
            case 'expand_iframe':
                $('#paypal-proxy-iframe').addClass('paypal-iframe-expanded');
                break;
                
            case 'resize_iframe_normal':
                $('#paypal-proxy-iframe').removeClass('paypal-iframe-expanded');
                break;
                
            case 'resize_iframe':
                if (data.height && !$('#paypal-proxy-iframe').hasClass('paypal-iframe-expanded')) {
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
    
    validateCheckoutFieldsClient().then(function(validationResult) {
        if (!validationResult.valid) {
            displayErrors(validationResult.errors);
            creatingOrder = false;
            
            $('body').trigger('update_checkout');
            
            // Send a special validation failed message to the iframe
            // PayPal SDK needs to handle this properly
            sendMessageToIframe({
                action: 'order_creation_failed',
                errors: validationResult.errors,
                isValidationError: true, 
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

function validateCheckoutFieldsClient() {
    return new Promise(function(resolve) {
        const errors = {};
        
        // Check all form fields
        $('form.checkout .form-row').each(function() {
            const $row = $(this);
            const $field = $row.find('input, select, textarea').first();
            const fieldName = $field.attr('name');
            const fieldValue = $field.val() ? $field.val().trim() : '';
            const fieldLabel = $row.find('label').text().replace('*', '').trim();
            
            // Skip if no field name
            if (!fieldName) return;
            
            // Check if field is visible and relevant
            const isFieldVisible = $row.is(':visible') && $field.is(':visible');
            const isShippingField = fieldName.includes('shipping_');
            const shipToDifferentAddress = $('input[name="ship_to_different_address"]').is(':checked');
            
            // Skip shipping fields if "ship to different address" is unchecked
            if (isShippingField && !shipToDifferentAddress) {
                return;
            }
            
            // Skip if field is not visible
            if (!isFieldVisible) {
                return;
            }
            
            // Check if field is required
            const isRequired = $row.hasClass('validate-required') || $field.prop('required');
            
            // Check required fields
            if (isRequired && !fieldValue) {
                errors[fieldName] = fieldLabel + ' is a required field.';
                return;
            }
            
            // Skip validation if field is empty and not required
            if (!fieldValue) return;
            
            // Email validation
            if ($row.hasClass('validate-email') || $field.attr('type') === 'email') {
                if (!isValidEmail(fieldValue)) {
                    errors[fieldName] = fieldLabel + ' is not a valid email address.';
                }
            }
            
            // Phone validation
            if ($row.hasClass('validate-phone') || fieldName.includes('phone')) {
                if (!isValidPhone(fieldValue)) {
                    errors[fieldName] = fieldLabel + ' is not a valid phone number.';
                }
            }
            
            // Postcode validation
            if ($row.hasClass('validate-postcode') || fieldName.includes('postcode')) {
                let country = '';
                if (fieldName.includes('billing_')) {
                    country = $('select[name="billing_country"]').val();
                } else if (fieldName.includes('shipping_')) {
                    country = $('select[name="shipping_country"]').val();
                }
                
                if (country && !isValidPostcode(fieldValue, country)) {
                    errors[fieldName] = fieldLabel + ' is not a valid postcode / ZIP.';
                }
            }
        });
        
        // Return validation result
        if (Object.keys(errors).length === 0) {
            resolve({ valid: true });
        } else {
            resolve({ valid: false, errors: errors });
        }
    });
}

function isValidEmail(email) {
    const emailRegex = /^[a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
    return emailRegex.test(email);
}

function isValidPhone(phone) {
    const cleaned = phone.replace(/[\s\#0-9_\-\+\/\(\)\.]/g, '');
    return cleaned.length === 0;
}

function isValidPostcode(postcode, country) {
    if (!postcode || !country) return false;
    
    // First check: remove allowed characters and see if anything remains
    const cleaned = postcode.replace(/[\s\-A-Za-z0-9]/g, '');
    if (cleaned.length > 0) return false;
    
    let valid = false;
    
    switch (country.toUpperCase()) {
        case 'AT':
        case 'BE':
        case 'CH':
        case 'HU':
        case 'NO':
            valid = /^([0-9]{4})$/.test(postcode);
            break;
            
        case 'BA':
            valid = /^([7-8]{1})([0-9]{4})$/.test(postcode);
            break;
            
        case 'BR':
            valid = /^([0-9]{5})([-])?([0-9]{3})$/.test(postcode);
            break;
            
        case 'DE':
            valid = /^([0]{1}[1-9]{1}|[1-9]{1}[0-9]{1})[0-9]{3}$/.test(postcode);
            break;
            
        case 'DK':
            valid = /^(DK-)?([1-24-9]\d{3}|3[0-8]\d{2})$/.test(postcode);
            break;
            
        case 'ES':
        case 'FI':
        case 'EE':
        case 'FR':
        case 'IT':
            valid = /^([0-9]{5})$/i.test(postcode);
            break;
            
        case 'GB':
            valid = isValidGBPostcode(postcode);
            break;
            
        case 'IE':
            // Normalize postcode (remove spaces)
            const normalizedPostcode = postcode.replace(/\s/g, '').toUpperCase();
            valid = /([AC-FHKNPRTV-Y]\d{2}|D6W)[0-9AC-FHKNPRTV-Y]{4}/.test(normalizedPostcode);
            break;
            
        case 'IN':
            valid = /^[1-9]{1}[0-9]{2}\s{0,1}[0-9]{3}$/.test(postcode);
            break;
            
        case 'JP':
            valid = /^([0-9]{3})([-]?)([0-9]{4})$/.test(postcode);
            break;
            
        case 'PT':
            valid = /^([0-9]{4})([-])([0-9]{3})$/.test(postcode);
            break;
            
        case 'PR':
        case 'US':
            valid = /^([0-9]{5})(-[0-9]{4})?$/i.test(postcode);
            break;
            
        case 'CA':
            // CA Postal codes cannot contain D,F,I,O,Q,U and cannot start with W or Z
            valid = /^([ABCEGHJKLMNPRSTVXY]\d[ABCEGHJKLMNPRSTVWXYZ])([\ ])?(\d[ABCEGHJKLMNPRSTVWXYZ]\d)$/i.test(postcode);
            break;
            
        case 'PL':
            valid = /^([0-9]{2})([-])([0-9]{3})$/.test(postcode);
            break;
            
        case 'CZ':
        case 'SE':
        case 'SK':
            const pattern = new RegExp("^(" + country + "-)?([0-9]{3})(\\s?)([0-9]{2})$");
            valid = pattern.test(postcode);
            break;
            
        case 'NL':
            valid = /^([1-9][0-9]{3})(\s?)(?!SA|SD|SS)[A-Z]{2}$/i.test(postcode);
            break;
            
        case 'SI':
            valid = /^([1-9][0-9]{3})$/.test(postcode);
            break;
            
        case 'LI':
            valid = /^(94[8-9][0-9])$/.test(postcode);
            break;
            
        default:
            valid = true; // Allow any format for unknown countries
            break;
    }
    
    return valid;
}

function isValidGBPostcode(postcode) {
    // Permitted letters depend upon their position in the postcode
    const alpha1 = '[abcdefghijklmnoprstuwyz]'; // Character 1
    const alpha2 = '[abcdefghklmnopqrstuvwxy]'; // Character 2
    const alpha3 = '[abcdefghjkpstuw]';         // Character 3
    const alpha4 = '[abehmnprvwxy]';            // Character 4
    const alpha5 = '[abdefghjlnpqrstuwxyz]';    // Character 5
    
    const patterns = [
        // Expression for postcodes: AN NAA, ANN NAA, AAN NAA, and AANN NAA
        new RegExp('^(' + alpha1 + '{1}' + alpha2 + '{0,1}[0-9]{1,2})([0-9]{1}' + alpha5 + '{2})$'),
        
        // Expression for postcodes: ANA NAA
        new RegExp('^(' + alpha1 + '{1}[0-9]{1}' + alpha3 + '{1})([0-9]{1}' + alpha5 + '{2})$'),
        
        // Expression for postcodes: AANA NAA
        new RegExp('^(' + alpha1 + '{1}' + alpha2 + '[0-9]{1}' + alpha4 + ')([0-9]{1}' + alpha5 + '{2})$'),
        
        // Exception for the special postcode GIR 0AA
        /^(gir)(0aa)$/,
        
        // Standard BFPO numbers
        /^(bfpo)([0-9]{1,4})$/,
        
        // c/o BFPO numbers
        /^(bfpo)(c\/o[0-9]{1,3})$/
    ];
    
    // Convert to lowercase and remove spaces
    const cleanPostcode = postcode.toLowerCase().replace(/\s/g, '');
    
    // Check against all patterns
    for (let i = 0; i < patterns.length; i++) {
        if (patterns[i].test(cleanPostcode)) {
            return true;
        }
    }
    
    return false;
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
        
        // Send AJAX request
        $.ajax({
            type: 'POST',
            url: wpppc_params.ajax_url,
            data: {
                action: 'wpppc_create_order', 
                nonce: wpppc_params.nonce,
                funding_source: fundingSource,
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
    //showLoading('Finalizing your payment...');
    
     var serverId = '';
    if (payload.proxy_data && payload.proxy_data.server_id) {
        serverId = payload.proxy_data.server_id;
        console.log('Using server ID from proxy_data:', serverId);
    }
    
    // Complete the payment directly from Website A
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

// the showLoading function
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