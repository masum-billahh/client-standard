jQuery(document).ready(function($) {
    
    var productExpress = {
        
        init: function() {
            this.bindEvents();
            this.initCountrySelect();
            this.initShippingCalculation();
            this.originalButtonHtml = $('#wpppc-product-express-button').html();
            this.isProcessing = false; // Add flag to prevent double submission
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
            
            // Close modal on outside click
            $(window).on('click', function(e) {
                if ($(e.target).hasClass('wpppc-modal')) {
                    productExpress.closeModal();
                }
            });
            
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
        
        initCountrySelect: function() {
            // Initialize Select2 for country dropdowns if available
            if ($.fn.select2) {
                $('#billing_country, #shipping_country').select2();
                $('#billing_state, #shipping_state').select2();
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
    if (!country || !postcode) {
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
            this.addToCartViaAjax($productForm, function(success) {
                // Reset processing flag
                self.isProcessing = false;
                
                if (success) {
                    // Product successfully added to cart, now show modal
                    self.showModal();
                } else {
                    alert('Failed to add product to cart. Please try again.');
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
                callback(false);
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
                        
                        callback(true);
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
                        callback(false);
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
                    callback(false);
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
            // Check if we have pre-populated address data
            var billingCountry = $('#billing_country').val();
            var billingPostcode = $('#billing_postcode').val();
            
            if (billingCountry && billingPostcode) {
                //console.log('Pre-populated address found, calculating shipping...');
                self.calculateShipping();
            }
        }, 500); // 500ms delay to ensure cart is ready
    },
        
        closeModal: function() {
            $('#wpppc-express-modal').fadeOut();
            $('body').removeClass('wpppc-modal-open');
            $('.wpppc-form-errors').hide().empty();
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