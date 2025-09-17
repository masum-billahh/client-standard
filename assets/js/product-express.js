jQuery(document).ready(function($) {
    //express product page
    // Global flag to track business mode
    var isBusinessMode = false;
    var expressCheckoutInitialized = false;
    var wcOrderId = null;
    var paypalOrderId = null;
        
    var productExpress = {
        
        init: function() {
            this.bindEvents();
            this.initCountrySelect();
            this.initShippingCalculation();
            this.initFloatingLabels();
            this.originalButtonHtml = $('#wpppc-product-express-button').html();
            this.modalAutocompleteInitialized = false;
            this.isProcessing = false; 
            const style = document.createElement('style');
            style.innerHTML = `
                .express-paypal-iframe-expanded {
                    position: fixed !important;
                    top: 0 !important;
                    left: 0 !important;
                    width: 100vw !important;
                    height: 100vh !important;
                    z-index: 9999 !important;
                }
            `;
            document.head.appendChild(style);
                        
            // Check if this is business mode
            if (typeof wpppc_server_mode !== 'undefined' && wpppc_server_mode.is_business_mode) {
                isBusinessMode = true;
                this.initBusinessMode();
            } else {
                isBusinessMode = false;
            }
        },
        
        
        // Express checkout iframe functions for business mode
createExpressButtonIframe: function(target) {
    console.log('Creating Express Checkout button iframe on ' + target);
    
    // Create iframe element
    var iframe = document.createElement('iframe');
    iframe.id = 'paypal-express-iframe-' + target.replace('#', '');
    
    // Use iframe URL from localized data
    if (typeof wpppc_express_params !== 'undefined' && wpppc_express_params.iframe_url) {
        iframe.src = wpppc_express_params.iframe_url;
    } else {
        console.error('No iframe URL available');
        return;
    }
    
    iframe.frameBorder = 0;
    iframe.scrolling = 'no';
    iframe.style.width = '100%';
    iframe.style.minHeight = '45px';
    iframe.style.height = '56px';
    iframe.style.overflow = 'hidden';
    iframe.style.border = 'none';
    iframe.referrerPolicy = 'no-referrer';
    iframe.setAttribute('loading', 'lazy');
    
    // Set sandbox attributes for security
    iframe.setAttribute('sandbox', 'allow-scripts allow-forms allow-popups allow-same-origin allow-top-navigation allow-popups-to-escape-sandbox');
    
    // Append iframe to container
    $(target).html('');
    $(target).append(iframe);
    
    console.log('Iframe created for Express Checkout button on ' + target);
},

handleIframeMessages: function(event) {
    // Validate message
    if (!event.data || !event.data.action || event.data.source !== 'paypal-express-proxy') {
        return;
    }
    
    console.log('Received message from iframe:', event.data);
    
    var self = this;
    
    // Handle different actions
    switch (event.data.action) {
        case 'button_loaded':
            console.log('PayPal Express button loaded');
            break;
            
        case 'button_clicked':
            console.log('PayPal Express button clicked - intercepting');
            // Tell iframe to wait while we add to cart
            self.sendMessageToIframe({
                action: 'pause_checkout',
                message: 'Adding product to cart...'
            });
            self.handleBusinessModeClick();
            break;
            
        case 'payment_approved':
            console.log('Payment approved in PayPal', event.data.payload);
            self.completeExpressCheckout(event.data.payload);
            break;
            
        case 'payment_cancelled':
            console.log('Payment cancelled by user');
            self.showExpressError('Payment cancelled. You can try again when ready.');
            self.isProcessing = false;
            self.createExpressButtonIframe('#wpppc-product-express-iframe-container');
            break;
            
        case 'payment_error':
            console.log('Payment error:', event.data.error);
            self.showExpressError('Error processing payment: ' + (event.data.error.message || 'Unknown error'));
            self.isProcessing = false;
            self.createExpressButtonIframe('#wpppc-product-express-iframe-container');
            break;
            
        case 'expand_iframe':
            $('#wpppc-product-express-iframe-container').addClass('express-paypal-iframe-expanded');
            var iframe = document.getElementById("paypal-express-iframe-wpppc-product-express-iframe-container");
            if (iframe) {
                iframe.style.height = "100%";
            }
            // Remove any padding from container
            var container = document.querySelector('#wpppc-product-express-iframe-container');
            if (container) {
                container.style.padding = '0';
            }
            break;
            
        case 'resize_iframe_normal':
            $('#wpppc-product-express-iframe-container').removeClass('express-paypal-iframe-expanded');
            var iframe = document.getElementById("paypal-express-iframe-wpppc-product-express-iframe-container");
            if (iframe) {
                iframe.style.height = "56px";
            }
            // Restore padding
            var container = document.querySelector('#wpppc-product-express-iframe-container');
            break;
            
        case 'resize_iframe':
            if (event.data.height) {
                $('#' + event.data.iframeId).css('height', event.data.height + 'px');
                console.log('Resized iframe to ' + event.data.height + 'px');
            }
            break;
            
        case 'validate_before_paypal':
            var $productForm = $('form.cart');
             var productId = $productForm.find('button[name="add-to-cart"]').val() || 
                   $productForm.find('input[name="add-to-cart"]').val() ||
                   $productForm.data('product_id') ||
                   $('#product_id').val();
                  
            
            // Use backend validation via AJAX
            var formData = new FormData($productForm[0]);
            if (productId) {
                formData.append('add-to-cart', productId);
            }
            formData.append('action', 'wpppc_validate_product');
            formData.append('nonce', wpppc_product_express.nonce);
            
            $.ajax({
                url: wpppc_product_express.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        self.sendMessageToIframe({ action: 'validation_passed' });
                    } else {
                        alert(response.data.error_message || wpppc_product_express.i18n.select_options);
                        self.sendMessageToIframe({ action: 'validation_failed' });
                        self.createExpressButtonIframe('#wpppc-product-express-iframe-container');
                    }
                },
                error: function() {
                    alert('Validation error occurred');
                    self.sendMessageToIframe({ action: 'validation_failed' });
                    self.createExpressButtonIframe('#wpppc-product-express-iframe-container');
                }
            });
            break;
    }
},

completeExpressCheckout: function(paymentData) {
    console.log('Completing express checkout with payment data', paymentData);
    var self = this;
    var container = '#wpppc-product-express-iframe-container';
    
    if (!this.wcOrderId || !this.paypalOrderId) {
        console.error('Missing order IDs for completion');
        self.showExpressError('Missing order information. Please try again.');
        return;
    }
    
    // Show loading
    self.showExpressLoading('Fetching order details...', container);
    
    $.ajax({
        url: wpppc_express_params.ajax_url,
        type: 'POST',
        data: {
            action: 'wpppc_fetch_paypal_order_details',
            nonce: wpppc_express_params.nonce,
            order_id: self.wcOrderId,
            paypal_order_id: self.paypalOrderId
        },
        success: function(detailsResponse) {
            console.log('Got PayPal order details:', detailsResponse);
            finalizePayment();
        },
        error: function(xhr, status, error) {
            console.error('Error fetching PayPal order details:', error);
            finalizePayment();
        }
    });
    
    // Finalize payment
    function finalizePayment() {
        self.showExpressLoading('Finalizing your order...', container);
        
        $.ajax({
            url: wpppc_express_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wpppc_complete_express_order',
                nonce: wpppc_express_params.nonce,
                order_id: self.wcOrderId,
                paypal_order_id: self.paypalOrderId
            },
            success: function(response) {
                self.hideExpressLoading(container);
                
                if (response.success) {
                    console.log('Order completed successfully, redirecting to:', response.data.redirect);
                    self.showExpressMessage('Payment successful! Redirecting to order confirmation...', container);
                    
                    setTimeout(function() {
                        window.location.href = response.data.redirect;
                    }, 1000);
                } else {
                    self.showExpressError(response.data.message || 'Failed to complete order', container);
                }
            },
            error: function() {
                self.hideExpressLoading(container);
                self.showExpressError('Error communicating with the server', container);
            }
        });
    }
},



sendMessageToIframe: function(message) {
    var iframe = document.getElementById('paypal-express-iframe-wpppc-product-express-iframe-container');
    
    if (!iframe || !iframe.contentWindow) {
        console.log('Cannot find PayPal Express iframe');
        return;
    }
    
    // Add source identifier
    message.source = 'woocommerce-client';
    
    console.log('Sending message to iframe', message);
    
    // Send message to iframe
    iframe.contentWindow.postMessage(message, '*');
},

showExpressLoading: function(message, container) {
    var targetContainer = container || '#wpppc-product-express-iframe-container';
    var loadingHtml = '<div class="wpppc-express-loading"><div class="wpppc-express-spinner"></div><span>' + message + '</span></div>';
    
    $(targetContainer).find('.wpppc-express-loading').remove();
    $(targetContainer).append(loadingHtml);
    $(targetContainer).find('.wpppc-express-loading').show();
    
    console.log('Loading: ' + message);
},

hideExpressLoading: function(container) {
    var targetContainer = container || '#wpppc-product-express-iframe-container';
    $(targetContainer).find('.wpppc-express-loading').hide();
},

showExpressError: function(message, container) {
    var targetContainer = container || '#wpppc-product-express-iframe-container';
    
    // Create error message element if it doesn't exist
    if ($(targetContainer).find('#wpppc-express-error').length === 0) {
        $(targetContainer).append('<div id="wpppc-express-error" style="display:none; color: #e74c3c; background: #fdf2f2; padding: 10px; border: 1px solid #e74c3c; border-radius: 4px; margin: 10px 0;"></div>');
    }
    
    $(targetContainer).find('#wpppc-express-error').text(message).show();
    $(targetContainer).find('#wpppc-express-message').hide();
    
    // Scroll to the message
    $('html, body').animate({
        scrollTop: $(targetContainer).offset().top - 100
    }, 300);
    
    console.error('Express Error: ' + message);
},

showExpressMessage: function(message, container) {
    var targetContainer = container || '#wpppc-product-express-iframe-container';
    
    // Create success message element if it doesn't exist
    if ($(targetContainer).find('#wpppc-express-message').length === 0) {
        $(targetContainer).append('<div id="wpppc-express-message" style="display:none; color: #27ae60; background: #f0f9f0; padding: 10px; border: 1px solid #27ae60; border-radius: 4px; margin: 10px 0;"></div>');
    }
    
    $(targetContainer).find('#wpppc-express-message').text(message).show();
    $(targetContainer).find('#wpppc-express-error').hide();
    
    console.log('Express Message: ' + message);
},

updateIframeUrlWithTotals: function(baseUrl, totals) {
    var url = new URL(baseUrl);
    
    // Update amount parameter
    url.searchParams.set('amount', totals.total.toFixed(2));
    
    // Add breakdown parameters
    url.searchParams.set('subtotal', totals.subtotal.toFixed(2));
    url.searchParams.set('shipping', totals.shipping.toFixed(2));
    url.searchParams.set('tax', totals.tax.toFixed(2));
    url.searchParams.set('shipping_method', totals.shipping_method || '');
    
    console.log('Updated iframe URL with totals:', url.toString());
    return url.toString();
},

createExpressOrderFromCart: function() {
    var self = this;
    
    console.log('Creating express order from cart...');
    
    // First get current cart totals
    $.ajax({
        url: wpppc_express_params.ajax_url,
        type: 'POST',
        data: {
            action: 'wpppc_get_cart_totals',
            nonce: wpppc_express_params.nonce
        },
        success: function(response) {
            if (response.success) {
                var totals = response.data;
                console.log('Got cart totals:', totals);
                
                // Create WooCommerce express order via AJAX (same as handleExpressCheckoutStart)
                $.ajax({
                    url: wpppc_express_params.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wpppc_create_express_order',
                        nonce: wpppc_express_params.nonce,
                        current_totals: totals
                    },
                    success: function(orderResponse) {
                        if (orderResponse.success) {
                            // Store the order IDs
                            self.wcOrderId = orderResponse.data.order_id;
                            self.paypalOrderId = orderResponse.data.paypal_order_id;
                            
                            console.log('Express order created. WC Order ID: ' + self.wcOrderId + ', PayPal Order ID: ' + self.paypalOrderId);
                            
                            // Send order data to iframe to proceed with PayPal
                            self.sendMessageToIframe({
                                action: 'create_paypal_order',
                                order_id: self.wcOrderId,
                                paypal_order_id: self.paypalOrderId
                            });
                            
                            self.isProcessing = false;
                        } else {
                            self.isProcessing = false;
                            console.error('Failed to create express order:', orderResponse.data.message);
                            self.showExpressError(orderResponse.data.message || 'Failed to create order');
                        }
                    },
                    error: function() {
                        self.isProcessing = false;
                        console.error('Error creating express order');
                        self.showExpressError('Error creating order. Please try again.');
                    }
                });
            } else {
                self.isProcessing = false;
                console.error('Failed to get cart totals');
                self.showExpressError('Error getting cart totals. Please try again.');
            }
        },
        error: function() {
            self.isProcessing = false;
            console.error('Error getting cart totals');
            self.showExpressError('Error communicating with server. Please try again.');
        }
    });
},
        
        initBusinessMode: function() {
            var self = this;
            
            // Initialize express checkout iframe for product page
            self.createExpressButtonIframe('#wpppc-product-express-iframe-container');
            expressCheckoutInitialized = true;
            
            // Listen for iframe messages
            window.addEventListener('message', function(event) {
                self.handleIframeMessages(event);
            });
        },
            
        bindEvents: function() {
            // PayPal button click
            $('#wpppc-product-express-button').on('click', this.handlePayPalClick.bind(this));
            
            // Modal close
            $('.wpppc-modal-close').on('click', this.closeModal.bind(this));
            
            // Ship to different address
            $('#ship_to_different_address').on('change', this.toggleShippingFields);
            
            // Form submission
            $('#wpppc-express-form').on('submit', this.handleFormSubmit.bind(this));
            
            /* Close modal on outside click
            $(window).on('click', function(e) {
                if ($(e.target).hasClass('wpppc-modal')) {
                    productExpress.closeModal();
                }
            });
            */
            
            // Prevent form submission when our button is processing
            $('form.cart').on('submit', function(e) {
                if (productExpress.isProcessing) {
                    //console.log('Preventing form submission - PayPal button is processing');
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            });
        },
        
        handleBusinessModeClick: function() {
    var self = this;
    var $productForm = $('form.cart');
    
    // Prevent double processing
    if (this.isProcessing) {
        return;
    }
    
    console.log('Business mode PayPal button clicked, adding to cart first...');
    
    // For variable products, check if variation is selected
    if (wpppc_product_express.is_variable) {
        var $form = $('form.variations_form');
        var variation_id = $form.find('input[name="variation_id"]').val();
        
        if (!variation_id || variation_id == '0') {
            alert(wpppc_product_express.i18n.select_options);
            return;
        }
    }
    
    // Validate input fields
    if (!this.validateInputFieldsOnly($productForm)) {
        return;
    }
    
    this.isProcessing = true;
    
    // Add to cart first
    this.addToCartViaAjax($productForm, function(success) {
        if (success) {
            console.log('Product added to cart, now creating express order...');
            self.createExpressOrderFromCart();
        } else {
            self.isProcessing = false;
            self.showExpressError('Failed to add product to cart. Please try again.');

        }
    });
},

updateIframeWithCartTotals: function() {
    var self = this;
    
    // Get current cart totals via AJAX
    $.ajax({
        url: wpppc_product_express.ajax_url,
        type: 'POST',
        data: {
            action: 'wpppc_get_cart_totals',
            nonce: wpppc_product_express.nonce
        },
        success: function(response) {
            if (response.success) {  
                var totals = response.data || response.success;
                console.log('Got cart totals:', response.data);
                
                // Update iframe URL with real cart totals
                if (typeof updateIframeUrlWithTotals === 'function' && wpppc_server_mode.iframe_url) {
                    var updatedUrl = updateIframeUrlWithTotals(wpppc_server_mode.iframe_url, response.data);
                    
                    // Update the iframe src
                    var iframe = document.querySelector('#wpppc-product-express-iframe-container iframe');
                    if (iframe) {
                        iframe.src = updatedUrl;
                        console.log('Updated iframe URL with cart totals');
                    }
                }
                
                // Continue with express checkout
                self.continueExpressCheckout();
            } else {
                self.isProcessing = false;
                console.error('Failed to get cart totals');
            }
        },
        error: function() {
            self.isProcessing = false;
            console.error('Error getting cart totals');
        }
    });
},

continueExpressCheckout: function() {
    var self = this;
    
    // Send message to iframe to continue with PayPal flow
    var iframe = document.querySelector('#wpppc-product-express-iframe-container iframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.postMessage({
            action: 'continue_checkout',
            source: 'woocommerce-client'
        }, '*');
    }
    
    self.isProcessing = false;
},
        
        initCountrySelect: function() {
            var self = this; 
            // Initialize Select2 for country dropdowns if available
            if ($.fn.select2) {
                $('#billing_country, #shipping_country').select2();
                $('#billing_state, #shipping_state').select2();
            }
            if ($('#billing_country').val()) {
                var $country = $('#billing_country').val();
                var $stateSelect = $('#billing_state');
                self.loadStatesForCountry($country, $stateSelect);
            }
        },
        
      initShippingCalculation: function() {
        var self = this;
        
        // Trigger shipping calculation when relevant fields change
        $(document).on('change blur', '#billing_country, #billing_state, #billing_postcode, #billing_city, #shipping_country, #shipping_state, #shipping_postcode, #shipping_city', function() {
            self.calculateShipping();
        });
        
        // Handle shipping method selection - ONLY update totals, don't recalculate everything
        $(document).on('change', 'input[name="shipping_method"]', function() {
            self.updateTotalsOnly(); // New function - only update totals
        });
    },


initFloatingLabels: function() {
    var self = this;
    
    // Handle floating labels for input fields
    $(document).on('input blur', '.wpppc-field-container input', function() {
        var $input = $(this);
        if ($input.val().trim() !== '') {
            $input.addClass('has-value');
        } else {
            $input.removeClass('has-value');
        }
    });

    // Handle floating labels for select fields
    $(document).on('change', '.wpppc-field-container select', function() {
        var $select = $(this);
        if ($select.val() && $select.val() !== '') {
            $select.addClass('has-value');
        } else {
            $select.removeClass('has-value');
        }
    });

    // Handle country/state relationship with proper state loading
    $(document).on('change', '#billing_country', function() {
        var country = $(this).val();
        var $stateSelect = $('#billing_state');
        var $stateContainer = $stateSelect.closest('.wpppc-field-container');
        
        if (country) {
            // Load states for selected country
            self.loadStatesForCountry(country, $stateSelect);
        } else {
            // Clear states if no country selected
            $stateSelect.empty().append('<option value="">Select State</option>');
            $stateSelect.removeClass('has-value');
        }
    });

    // Handle add address line toggle
    $(document).on('click', '.wpppc-add-address-toggle', function() {
        var $toggle = $(this);
        var $addressLine2 = $('.wpppc-address-line-2');
        
        if ($addressLine2.is(':visible')) {
            $addressLine2.slideUp(300);
            $toggle.removeClass('active');
        } else {
            $addressLine2.slideDown(300);
            $toggle.addClass('active');
            // Focus the input field
            setTimeout(function() {
                $addressLine2.find('input').focus();
            }, 350);
        }
    });
},

loadStatesForCountry: function(country, $stateSelect) {
    var self = this;
    var $stateContainer = $stateSelect.closest('.wpppc-field-container');
    var $stateLabel = $stateContainer.find('label');
    
    // Show loading state
    $stateSelect.empty().append('<option value="">Loading...</option>');
    
    // Make AJAX call to get states and field type info
    $.ajax({
        url: wpppc_product_express.ajax_url,
        type: 'POST',
        data: {
            action: 'wpppc_get_states',
            nonce: wpppc_product_express.nonce,
            country: country
        },
        success: function(response) {
            console.log('AJAX Response:', response);
            
            if (response.success) {
                var data = response.data;
                var fieldType = data.field_type;
                var states = data.states;
                var isRequired = data.required;
                var label = data.label;
                
                console.log('Field type for', country, ':', fieldType);
                console.log('States count:', Object.keys(states).length);
                
                // Update label
                $stateLabel.text(label);
                
                // Handle different field types
                switch (fieldType) {
                    case 'select':
                        self.createSelectField($stateContainer, $stateSelect, states, isRequired, label);
                        break;
                        
                    case 'text_required':
                        self.createTextField($stateContainer, true, label);
                        break;
                        
                    case 'text_optional':
                        self.createTextField($stateContainer, false, label);
                        break;
                        
                    case 'hidden':
                    default:
                        self.hideStateField($stateContainer);
                        break;
                }
                
                // Trigger custom event when states are loaded
                $(document).trigger('statesLoaded', [country, fieldType, states]);
                
                // Trigger shipping calculation after state field is updated
                setTimeout(function() {
                    self.calculateShipping();
                }, 100);
                
                
            } else {
                console.error('AJAX Error Response:', response);
                $stateSelect.empty().append('<option value="">Error: ' + (response.data ? response.data.message : 'Unknown error') + '</option>');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Request Failed:', {
                status: status,
                error: error,
                responseText: xhr.responseText,
                xhr: xhr
            });
            
            $stateSelect.empty().append('<option value="">Network error occurred</option>');
        }
    });
},

createSelectField: function($container, $select, states, isRequired, label) {
    // Make sure we have a select field
    if (!$select.length || $select.prop('tagName') !== 'SELECT') {
        // Replace with select field
        var selectHtml = '<select id="billing_state" name="billing_state" class="' + 
                        (isRequired ? 'required' : '') + '"></select>';
        $container.find('input, select').remove();
        $container.prepend(selectHtml);
        $select = $container.find('select');
    }
    
    // Clear and populate
    $select.empty();
    $select.append('<option value="">' + 'Select ' + label + '</option>');
    
    $.each(states, function(code, name) {
        $select.append('<option value="' + code + '">' + name + '</option>');
    });
    
    // Set required attribute
    $select.prop('required', isRequired);
    
    // Show container and remove has-value class
    $container.show();
    $select.removeClass('has-value');
    
    // Re-add select styling classes
    $container.addClass('wpppc-select-container');
},

createTextField: function($container, isRequired, label) {
    // Replace with text input field
    var inputHtml = '<input type="text" id="billing_state" name="billing_state" class="' + 
                   (isRequired ? 'required' : '') + '">';
    
    $container.find('input, select').remove();
    $container.prepend(inputHtml);
    
    var $input = $container.find('input');
    $input.prop('required', isRequired);
    
    // Show container and remove select styling
    $container.show();
    $container.removeClass('wpppc-select-container');
    $input.removeClass('has-value');
},

hideStateField: function($container) {
    // Hide the entire state field container
    $container.hide();
    
    // Remove required attribute and clear value
    var $field = $container.find('input, select');
    $field.prop('required', false);
    $field.val('');
    $field.removeClass('has-value');
},

    updateTotalsOnly: function() {
        var self = this;
        var selectedShippingMethod = $('input[name="shipping_method"]:checked').val();
        
        if (!selectedShippingMethod) {
            return;
        }
        
        //console.log('Selected shipping method:', selectedShippingMethod);
        
        $.ajax({
            url: wpppc_product_express.ajax_url,
            type: 'POST',
            data: {
                action: 'wpppc_update_totals_only',
                nonce: wpppc_product_express.nonce,
                shipping_method: selectedShippingMethod
            },
            success: function(response) {
                //console.log('Totals update response:', response);
                if (response.success) {
                    self.updateTotalsDisplay(response.data.totals);
                }
            },
            error: function(xhr, status, error) {
                //console.error('Totals update error:', {status, error});
            }
        });
    },
    
    updateTotalsDisplay: function(totals) {
        // Use .html() instead of .text() to render the HTML properly
        $('#subtotal-amount').html(totals.subtotal_formatted);
        $('#shipping-amount').html(totals.shipping_formatted);
        $('#tax-amount').html(totals.tax_formatted);
        $('#total-amount').html(totals.total_formatted);
    },
    
    // Remove the old updateOrderTotals function and replace with:
    updateOrderTotals: function() {
        // This was causing the issue - don't recalculate everything
        // Just update totals for the selected method
        this.updateTotalsOnly();
    },
    
    calculateShipping: function() {
    var self = this;
    
    //console.log('calculateShipping called');
    
    // Determine which address to use for shipping
    var useShippingAddress = $('#ship_to_different_address').is(':checked');
    var country, state, postcode, city;
    
    if (useShippingAddress) {
        country = $('#shipping_country').val();
        state = $('#shipping_state').val();
        postcode = $('#shipping_postcode').val();
        city = $('#shipping_city').val();
    } else {
        country = $('#billing_country').val();
        state = $('#billing_state').val();
        postcode = $('#billing_postcode').val();
        city = $('#billing_city').val();
    }
    
   //console.log('Address data:', {country, state, postcode, city, useShippingAddress});
    
    // Check if we have minimum required fields
    if (!country) {
        //console.log('Missing required fields for shipping calculation');
        return;
    }
    
    var $container = $('#shipping-methods-container');
    $container.html('<p>Calculating shipping...</p>');
    
    $.ajax({
        url: wpppc_product_express.ajax_url,
        type: 'POST',
        data: {
            action: 'wpppc_calculate_shipping',
            nonce: wpppc_product_express.nonce,
            country: country,
            state: state,
            postcode: postcode,
            city: city,
            different_address: useShippingAddress ? '1' : '0',
            // Add all form data for proper address handling
            billing_address_1: $('#billing_address_1').val(),
            billing_city: $('#billing_city').val(),
            billing_state: $('#billing_state').val(),
            billing_postcode: $('#billing_postcode').val(),
            billing_country: $('#billing_country').val(),
            shipping_address_1: $('#shipping_address_1').val(),
            shipping_city: $('#shipping_city').val(),
            shipping_state: $('#shipping_state').val(),
            shipping_postcode: $('#shipping_postcode').val(),
            shipping_country: $('#shipping_country').val(),
            ship_to_different_address: useShippingAddress ? '1' : ''
        },
        success: function(response) {
            //console.log('Shipping calculation response:', response);
            if (response.success) {
                self.displayShippingMethods(response.data.shipping_methods, response.data.totals);
            } else {
                console.error('Shipping calculation failed:', response);
                $container.html('<p>Unable to calculate shipping for this address.</p>');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error:', {status, error, responseText: xhr.responseText});
            $container.html('<p>Error calculating shipping. Please try again.</p>');
        }
    });
},
    
    displayShippingMethods: function(methods, totals) {
        var $container = $('#shipping-methods-container');
        var html = '';
        
        if (methods.length === 0) {
            html = '<p>No shipping methods available for this address.</p>';
        } else {
            html = '<ul class="shipping-methods">';
            $.each(methods, function(index, method) {
                var checked = index === 0 ? 'checked' : ''; // Select first method by default
                html += '<li>';
                html += '<label>';
                html += '<input type="radio" name="shipping_method" value="' + method.id + '" ' + checked + '>';
                html += method.label + ' - ' + method.formatted_cost;
                html += '</label>';
                html += '</li>';
            });
            html += '</ul>';
        }
        
        $container.html(html);
        
        // Update totals
        this.updateTotalsDisplay(totals);
        $('#order-totals').show();
    },
    
    updateTotalsDisplay: function(totals) {
        $('#subtotal-amount').html(totals.subtotal_formatted);
        $('#shipping-amount').html(totals.shipping_formatted);
        $('#tax-amount').html(totals.tax_formatted);
        $('#total-amount').html(totals.total_formatted);
    },
    
    updateOrderTotals: function() {
        // When shipping method changes, recalculate totals
        this.updateTotalsOnly();
    },
        
        handlePayPalClick: function(e) {
            // Only handle personal mode clicks
            if (isBusinessMode) {
                return; // Business mode is handled by iframe
            }
            
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation(); 
            // Prevent double submission
            if (this.isProcessing) {
                //console.log('Already processing, ignoring click');
                return;
            }
            
            var self = this;
            var $button = $(e.currentTarget);
            var $productForm = $('form.cart');
            
            $productForm.off('submit.wc-add-to-cart-form'); 
            $productForm.on('submit.wpppc-prevent', function(formEvent) {
                formEvent.preventDefault();
                formEvent.stopPropagation();
                formEvent.stopImmediatePropagation();
                return false;
            });
            
            // For variable products, check if variation is selected
            if (wpppc_product_express.is_variable) {
                var $form = $('form.variations_form');
                var variation_id = $form.find('input[name="variation_id"]').val();
                
                if (!variation_id || variation_id == '0') {
                    alert(wpppc_product_express.i18n.select_options);
                    return;
                }
            }
            
            // Use simplified validation
            if (!this.validateInputFieldsOnly($productForm)) {
                return;
            }
            
            // Set processing flag
            this.isProcessing = true;
            
            // Now trigger actual WooCommerce add-to-cart
            this.addToCartViaAjax($productForm, function(success, responseData) {
                if (success) {
                    console.log('Product added to cart, now creating express order...');
                    self.showModal();
                } else {
                    self.isProcessing = false;
                    
                    self.showExpressError('Failed to add product to cart. Please try again.');
                }
            });
        },

        /**
         * Add product to cart using WooCommerce's native AJAX add-to-cart
         */
        addToCartViaAjax: function($productForm, callback) {
            var self = this;
            var $button = $('#wpppc-product-express-button');
            
            // Disable all add-to-cart buttons to prevent WC's native handler
            $('button[name="add-to-cart"], input[name="add-to-cart"]').prop('disabled', true);
            
            // Prepare form data
            var formData = new FormData($productForm[0]);
            
            // Ensure we have the product ID
            var productId = $productForm.find('[name="add-to-cart"]').val() || 
                           $productForm.find('button[name="add-to-cart"]').val() ||
                           $productForm.find('input[name="product_id"]').val();
            
            if (!productId) {
                // Try to get from localized script data
                if (typeof wpppc_product_express !== 'undefined' && wpppc_product_express.product_id) {
                    productId = wpppc_product_express.product_id;
                }
            }
            
            if (!productId) {
                // Try to get from global product data
                if (typeof wc_single_product_params !== 'undefined') {
                    productId = wc_single_product_params.product_id;
                }
            }
            
            if (!productId) {
                //console.error('No product ID found');
                alert('Unable to determine product ID. Please try again.');
                callback(false, response);
                return;
            }
            
            // Ensure add-to-cart parameter is set
            formData.set('add-to-cart', productId);
            
            // For variable products, ensure variation_id is set
            if (wpppc_product_express.is_variable) {
                var variationId = $productForm.find('input[name="variation_id"]').val();
                if (variationId && variationId !== '0') {
                    formData.set('variation_id', variationId);
                }
            }
            
            // Determine the correct AJAX URL and action
            var ajaxUrl;
            var useFallback = true; // Set to true to force fallback for testing
            
            if (!useFallback && typeof wpppc_product_express.wc_ajax_url !== 'undefined') {
                ajaxUrl = wpppc_product_express.wc_ajax_url.replace('%%endpoint%%', 'add_to_cart');
                // WC AJAX doesn't need action parameter
            } else if (!useFallback && typeof wc_add_to_cart_params !== 'undefined' && wc_add_to_cart_params.wc_ajax_url) {
                ajaxUrl = wc_add_to_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'add_to_cart');
                // WC AJAX doesn't need action parameter
            } else {
                // Use our custom handler
                ajaxUrl = wpppc_product_express.ajax_url;
                formData.append('action', 'wpppc_add_to_cart_fallback');
                formData.append('nonce', wpppc_product_express.nonce);
                useFallback = true;
            }
            
            
            for (var pair of formData.entries()) {
                
            }
            
            // Make AJAX request to WooCommerce add-to-cart endpoint
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                beforeSend: function() {
                    $button.prop('disabled', true).text(wpppc_product_express.i18n.processing);
                },
                success: function(response) {
                    //console.log('Add to cart response:', response);
                    $button.prop('disabled', false).html(self.originalButtonHtml);
                    
                    // Re-enable all add-to-cart buttons
                    $('button[name="add-to-cart"], input[name="add-to-cart"]').prop('disabled', false);
                    
                    // Handle different response formats
                    var success = false;
                    if (typeof response === 'object' && response.success) {
                        // Our fallback handler returns {success: true, data: {...}}
                        success = true;
                        if (response.data && response.data.fragments) {
                            $.each(response.data.fragments, function(key, value) {
                                $(key).replaceWith(value);
                            });
                        }
                    } else if (typeof response === 'object' && response.fragments) {
                        // WC's native response returns {fragments: {...}, cart_hash: '...'}
                        success = true;
                        $.each(response.fragments, function(key, value) {
                            $(key).replaceWith(value);
                        });
                    } else if (typeof response === 'string' && response.length > 0 && response !== 'false' && response !== '0') {
                        // Some setups return HTML fragments directly
                        success = true;
                    }
                    
                    if (success) {
                        // Trigger cart updated event
                        $(document.body).trigger('wc_fragment_refresh');
                        $(document.body).trigger('added_to_cart', [response.fragments || {}, response.cart_hash || '']);
                        
                        callback(true, response);

                    } else {
                        // Handle add-to-cart errors
                        var errorMsg = '';
                        if (typeof response === 'object' && response.data && response.data.error_message) {
                            errorMsg = response.data.error_message;
                        } else if (typeof response === 'object' && response.error_message) {
                            errorMsg = response.error_message;
                        } else if (typeof response === 'string' && response.length > 0) {
                            errorMsg = 'Server response: ' + response;
                        } else {
                            errorMsg = 'Failed to add product to cart. Please check console for details.';
                        }
                        
                        //console.error('Add to cart failed:', response);
                        alert(errorMsg);
                        callback(false, response);
                    }
                },
                error: function(xhr, status, error) {
                    $button.prop('disabled', false).html(self.originalButtonHtml);
                    
                    // Re-enable all add-to-cart buttons
                    $('button[name="add-to-cart"], input[name="add-to-cart"]').prop('disabled', false);
                    
                    console.error('AJAX error:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        statusCode: xhr.status
                    });
                    alert('Network error occurred. Please check console and try again.');
                    callback(false, response);
                }
            });
        },

        validateInputFieldsOnly: function($productForm) {
            var hasErrors = false;
            
            // Only check actual input/select/textarea elements that are required
            $productForm.find('input[required], select[required], textarea[required]').each(function() {
                var $field = $(this);
                
                // Only check visible fields
                if (!$field.is(':visible')) {
                    return;
                }
                
                var value = $field.val();
                var isEmpty = false;
                
                if ($field.is('select')) {
                    isEmpty = !value || value === '' || value === '0';
                } else if ($field.is(':checkbox') || $field.is(':radio')) {
                    isEmpty = !$field.is(':checked');
                } else {
                    isEmpty = !value || value.trim() === '';
                }
                
                if (isEmpty) {
                    $field.addClass('error');
                    hasErrors = true;
                } else {
                    $field.removeClass('error');
                }
            });
            
            // Also check data-is-required (for plugin compatibility)
            $productForm.find('input[data-is-required="1"], select[data-is-required="1"], textarea[data-is-required="1"]').each(function() {
                var $field = $(this);
                
                if (!$field.is(':visible')) {
                    return;
                }
                
                var value = $field.val();
                var isEmpty = false;
                
                if ($field.is('select')) {
                    isEmpty = !value || value === '' || value === '0';
                } else if ($field.is(':checkbox') || $field.is(':radio')) {
                    isEmpty = !$field.is(':checked');
                } else {
                    isEmpty = !value || value.trim() === '';
                }
                
                if (isEmpty) {
                    $field.addClass('error');
                    hasErrors = true;
                } else {
                    $field.removeClass('error');
                }
            });
            
            if (hasErrors) {
                alert(wpppc_product_express.i18n.select_options);
                return false;
            }
            
            return true;
        },
        
        showModal: function() {
        var self = this;
        $('#wpppc-express-modal').show();
        $('body').addClass('wpppc-modal-open');
        
        // Add a small delay to ensure cart is populated, then calculate shipping
        setTimeout(function() {
            if (!self.modalAutocompleteInitialized) {
                $(document).trigger('modalShown');
                self.modalAutocompleteInitialized = true;
            }
            
            // Check if we have pre-populated address data
            var billingCountry = $('#billing_country').val();
            var billingPostcode = $('#billing_postcode').val();
            
            if (billingCountry) {
                //console.log('Pre-populated address found, calculating shipping...');
                self.calculateShipping();
            }
        }, 500); // 500ms delay to ensure cart is ready
    },
        
        closeModal: function() {
            $('#wpppc-express-modal').fadeOut();
            $('body').removeClass('wpppc-modal-open');
            $('.wpppc-form-errors').hide().empty();
            this.modalAutocompleteInitialized = false;
            // Remove any autocomplete elements
            $('#billing_address_1').show().siblings('gmp-place-autocomplete').remove();
        },
        
        toggleShippingFields: function() {
            if ($(this).is(':checked')) {
                $('#shipping-fields').slideDown();
                $('#shipping-fields').find('[required]').prop('required', true);
            } else {
                $('#shipping-fields').slideUp();
                $('#shipping-fields').find('[required]').prop('required', false);
            }
        },
        
        handleFormSubmit: function(e) {
            e.preventDefault();
            
            var self = this;
            var $form = $(e.currentTarget);
            var $submitBtn = $form.find('button[type="submit"]');
            var $errorsDiv = $('.wpppc-form-errors');
            
            // Clear previous errors
            $errorsDiv.hide().empty();
            $form.find('.error').removeClass('error');
            
            // Collect all form data
            var formData = new FormData($form[0]);
            
            // Add action for processing express checkout
            formData.append('action', 'wpppc_process_product_express');
            formData.append('nonce', wpppc_product_express.nonce);
            
            var selectedShippingMethod = $('input[name="shipping_method"]:checked').val();
            if (selectedShippingMethod) {
                formData.append('shipping_method', selectedShippingMethod);
            }
            
            // Process via AJAX
            $.ajax({
                url: wpppc_product_express.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                beforeSend: function() {
                    $submitBtn.prop('disabled', true).text(wpppc_product_express.i18n.processing);
                },
                success: function(response) {
                    if (response.success) {
                        // Redirect to PayPal
                        window.location.href = response.data.redirect_url;
                    } else {
                        $submitBtn.prop('disabled', false).text('Proceed to PayPal');
                        
                        if (response.data.errors) {
                            var errorHtml = '<ul>';
                            $.each(response.data.errors, function(field, message) {
                                errorHtml += '<li>' + message + '</li>';
                                $('#' + field).addClass('error');
                            });
                            errorHtml += '</ul>';
                            $errorsDiv.html(errorHtml).show();
                        } else {
                            $errorsDiv.html('<p>' + (response.data.message || 'An error occurred') + '</p>').show();
                        }
                    }
                },
                error: function() {
                    $submitBtn.prop('disabled', false).text('Proceed to PayPal');
                    $errorsDiv.html('<p>An error occurred. Please try again.</p>').show();
                }
            });
        }
    };
    

    
    productExpress.init();
});