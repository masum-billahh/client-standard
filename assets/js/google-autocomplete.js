jQuery(document).ready(function($) {
    
    function initGoogleAutocomplete() {
        if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
            console.log('Google Maps not loaded');
            return;
        }
        
        // Initialize for all address fields
        initAutocompleteForField('#billing_address_1');
        initAutocompleteForField('#shipping_address_1');
        
        // Re-initialize when modal opens (for product page)
        $(document).on('DOMNodeInserted', '#wpppc-express-modal', function() {
            setTimeout(function() {
                if ($('#wpppc-express-modal').is(':visible')) {
                    initAutocompleteForField('#billing_address_1');
                }
            }, 500);
        });
    }
    
    function initAutocompleteForField(fieldSelector) {
        var $addressField = $(fieldSelector);
        
        if (!$addressField.length || $addressField.attr('data-autocomplete-initialized')) {
            return;
        }
        
        // Mark as initialized to prevent duplicate initialization
        $addressField.attr('data-autocomplete-initialized', 'true');
        
        try {
            // Use the new PlaceAutocompleteElement if available, fallback to old Autocomplete
            if (typeof google.maps.places.PlaceAutocompleteElement !== 'undefined') {
                initNewAutocomplete($addressField, fieldSelector);
            } else {
                initLegacyAutocomplete($addressField, fieldSelector);
            }
        } catch (error) {
            console.log('Trying legacy autocomplete due to error:', error);
            initLegacyAutocomplete($addressField, fieldSelector);
        }
    }
    
    function initNewAutocomplete($addressField, fieldSelector) {
        console.log('Using new PlaceAutocompleteElement for:', fieldSelector);
        
        // Create the new autocomplete element
        var autocompleteElement = document.createElement('gmp-place-autocomplete');
        autocompleteElement.setAttribute('types', 'address');
        
        // Insert it before the input field
        $addressField.before(autocompleteElement);
        
        // Hide the autocomplete element and sync with our input
        $(autocompleteElement).css({
            'position': 'absolute',
            'opacity': '0',
            'pointer-events': 'none',
            'z-index': '-1'
        });
        
        // Listen for place selection
        autocompleteElement.addEventListener('gmp-placeselect', function(event) {
            var place = event.place;
            handlePlaceSelection(place, fieldSelector);
        });
        
        // Sync input with autocomplete
        $addressField.on('input', function() {
            autocompleteElement.value = this.value;
        });
    }
    
    function initLegacyAutocomplete($addressField, fieldSelector) {
        console.log('Using legacy Autocomplete for:', fieldSelector);
        
        var autocomplete = new google.maps.places.Autocomplete($addressField[0], {
            types: ['address']
        });
        
        // Store autocomplete instance for country restriction updates
        $addressField[0].autocompleteInstance = autocomplete;
        
        autocomplete.addListener('place_changed', function() {
            var place = autocomplete.getPlace();
            handlePlaceSelection(place, fieldSelector);
        });
    }
    
    function handlePlaceSelection(place, fieldSelector) {
        if (!place.geometry && !place.address_components) {
            console.log("No geometry or address info available");
            return;
        }
        
        // Determine which fields to fill based on which address field triggered this
        var isShipping = fieldSelector.includes('shipping');
        var prefix = isShipping ? 'shipping' : 'billing';
        
        // Clear existing values
        $('#' + prefix + '_address_1').val('');
        $('#' + prefix + '_address_2').val('');
        $('#' + prefix + '_city').val('');
        $('#' + prefix + '_state').val('');
        $('#' + prefix + '_postcode').val('');
        
        var address1 = '';
        var city = '';
        var state = '';
        var postcode = '';
        var country = '';
        
        // Parse address components
        var components = place.address_components || place.addressComponents || [];
        for (var i = 0; i < components.length; i++) {
            var component = components[i];
            var types = component.types;
            
            if (types.includes('street_number')) {
                address1 = component.long_name + ' ' + address1;
            } else if (types.includes('route')) {
                address1 += component.long_name;
            } else if (types.includes('locality') || types.includes('sublocality')) {
                city = component.long_name;
            } else if (types.includes('administrative_area_level_1')) {
                state = component.long_name;
            } else if (types.includes('postal_code')) {
                postcode = component.long_name;
            } else if (types.includes('country')) {
                country = component.short_name;
            }
        }
        
        // Fill the form fields
        if (address1) {
            $('#' + prefix + '_address_1').val(address1.trim()).addClass('has-value');
        }
        if (city) {
            $('#' + prefix + '_city').val(city).addClass('has-value');
        }
        if (postcode) {
            $('#' + prefix + '_postcode').val(postcode).addClass('has-value');
        }
        
        // Handle state field (could be select or input)
        if (state) {
            var $stateField = $('#' + prefix + '_state');
            if ($stateField.is('select')) {
                // Try to find matching option
                var $option = $stateField.find('option').filter(function() {
                    return $(this).text().toLowerCase() === state.toLowerCase() ||
                           $(this).val().toLowerCase() === state.toLowerCase();
                });
                if ($option.length) {
                    $stateField.val($option.val()).addClass('has-value');
                }
            } else {
                $stateField.val(state).addClass('has-value');
            }
        }
        
        // Handle country
        if (country) {
            $('#' + prefix + '_country').val(country.toUpperCase()).trigger('change').addClass('has-value');
        }
        
        // Trigger change events
        $('#' + prefix + '_address_1').trigger('change');
        $('#' + prefix + '_city').trigger('change');
        $('#' + prefix + '_state').trigger('change');
        $('#' + prefix + '_postcode').trigger('change');
        
        // If this is in the product modal, trigger shipping calculation
        if ($('#wpppc-express-modal').is(':visible') && typeof productExpress !== 'undefined') {
            setTimeout(function() {
                if (typeof productExpress.calculateShipping === 'function') {
                    productExpress.calculateShipping();
                }
            }, 500);
        }
        
        console.log('Address filled from autocomplete');
    }
    
    // Initialize when Google Maps is ready
    if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
        initGoogleAutocomplete();
    } else {
        // Wait for Google Maps to load
        var checkGoogleMaps = setInterval(function() {
            if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                clearInterval(checkGoogleMaps);
                initGoogleAutocomplete();
            }
        }, 100);
        
        // Stop checking after 10 seconds
        setTimeout(function() {
            clearInterval(checkGoogleMaps);
        }, 10000);
    }
    
    // Update country restrictions when country changes (only for legacy autocomplete)
    $(document).on('change', '#billing_country, #shipping_country', function() {
        var country = $(this).val();
        var fieldId = $(this).attr('id').replace('_country', '_address_1');
        
        // Update autocomplete restriction if it exists (legacy only)
        var addressField = $('#' + fieldId)[0];
        if (addressField && addressField.autocompleteInstance && typeof addressField.autocompleteInstance.setComponentRestrictions === 'function') {
            try {
                addressField.autocompleteInstance.setComponentRestrictions({'country': [country.toLowerCase()]});
                console.log('Updated country restriction to:', country);
            } catch (error) {
                console.log('Could not update country restriction:', error);
            }
        }
    });
});