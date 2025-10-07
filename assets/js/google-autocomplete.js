    jQuery(document).ready(function($) {
        function initializeModalAutocomplete() {
            if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
                setTimeout(initializeModalAutocomplete, 100);
                return;
            }
            
            setupModalPlaceAutocomplete('billing_address_1');
        }
        
     function setupModalPlaceAutocomplete(inputId) {
        var inputElement = document.getElementById(inputId);
        if (!inputElement) return;
    
        var selectedStateValue = '';
        var currentAutocomplete = null;
    
        function createAutocomplete() {
            // Remove any existing autocomplete
            var existing = inputElement.parentNode.querySelector('gmp-place-autocomplete');
            if (existing) {
                existing.remove();
            }
            
            var autocomplete = document.createElement('gmp-place-autocomplete');
            autocomplete.setAttribute('for-map', 'false');
            autocomplete.setAttribute('type', 'address');
            autocomplete.setAttribute('country-code', 'US');
            autocomplete.style.width = '100%';
            autocomplete.style.height = '42px';
            autocomplete.className = inputElement.className;
    
            // Insert before input
            inputElement.parentNode.insertBefore(autocomplete, inputElement);
            inputElement.style.display = 'none';
            
            // Store reference to current autocomplete
            currentAutocomplete = autocomplete;
            
            inputElement.addEventListener('click', function() {
				setTimeout(() => autocomplete.focus(), 10);
			});

    
            // If input already has a value, set it back after autocomplete removed
            if (inputElement.value) {
                autocomplete.addEventListener('gmp-select', function() {
                    inputElement.value = inputElement.value;
                });
            }
    
            autocomplete.addEventListener('gmp-select', async function(event) {
                var place = event.placePrediction.toPlace();
                if (!place) return;
    
                await place.fetchFields({ fields: ['displayName', 'formattedAddress', 'addressComponents', 'location'] });
    
                var addressData = { streetNumber:'', route:'', city:'', state:'', postalCode:'', country:'' };
                place.addressComponents.forEach(function(c) {
                    if (c.types.includes('street_number')) addressData.streetNumber = c.longText;
                    if (c.types.includes('route')) addressData.route = c.longText;
                    if (c.types.includes('locality') || c.types.includes('sublocality')) addressData.city = c.longText;
                    if (c.types.includes('administrative_area_level_1')) addressData.state = c.shortText;
                    if (c.types.includes('postal_code')) addressData.postalCode = c.longText;
                    if (c.types.includes('country')) addressData.country = c.shortText;
                });
    
                var street = place.displayName;
                if (addressData.route && addressData.route !== place.displayName) {
                    street += ', ' + addressData.route;
                }
    
                $('#billing_address_1').val(street).addClass('has-value');
                $('#billing_city').val(addressData.city).addClass('has-value');
                $('#billing_postcode').val(addressData.postalCode).addClass('has-value');
                $('#billing_country').val(addressData.country).addClass('has-value').trigger('change');
    
                inputElement.value = street;
                selectedStateValue = addressData.state;
                window.selectedStateValue = addressData.state;
    
                removeAutocompleteShowInput();
            });

            // Handle blur event on autocomplete
            const shouldShow = window.innerWidth <= 768;
            const keyboardOpen = window.innerHeight < screen.height * 0.75;
        
            if (!shouldShow && !keyboardOpen) {
                autocomplete.addEventListener('blur', function() {
                    setTimeout(removeAutocompleteShowInput, 150);
                });
            }
        }

        function removeAutocompleteShowInput() {
            if (currentAutocomplete) {
                currentAutocomplete.remove();
                currentAutocomplete = null;
            }
            inputElement.style.display = 'block';
            // Preserve the existing value
            if (inputElement.value) {
                inputElement.value = inputElement.value;
            }
        }
    
        // Create autocomplete initially
        createAutocomplete();
    
        // Re-open autocomplete on focus
        inputElement.addEventListener('focus', function() {
            createAutocomplete();
            
            if (inputElement.value) { 
                inputElement.style.display = 'block'; 
                inputElement.value = inputElement.value; 
            }
        });

        // Handle blur on the original input (when it's visible)
        inputElement.addEventListener('blur', function() {
            // This ensures the input stays visible when losing focus
            // and preserves its value
        });
    }



        
        // Set state after country states are loaded
        function setStateValue() {
            // Access the stored state value from the outer scope
            var stateValue = window.selectedStateValue || '';
            
            var stateField = $('#billing_state');
            if (stateField.length && stateValue && stateField.find('option[value="' + stateValue + '"]').length > 0) {
                // State options are loaded and our state exists
                if (stateField.is('select')) {
                    stateField.val(stateValue).addClass('has-value').trigger('change');
                } else {
                    stateField.val(stateValue).addClass('has-value');
                }
            } else if (stateField.length && stateValue && !stateField.is('select')) {
                // It's a text field, set directly
                stateField.val(stateValue).addClass('has-value');
            } else if (stateValue) {
                // State options not loaded yet, wait and try again
                setTimeout(setStateValue, 100);
            }
        }
        
        $(document).on('statesLoaded', function() {
            setTimeout(function() {
                setStateValue();
            }, 100);
        });
                
        // Initialize when modal is shown
        $(document).on('modalShown', function() {
            setTimeout(function() {
                initializeModalAutocomplete();
            }, 100);
        });
    });