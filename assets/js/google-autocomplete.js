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
            
            // Remove existing autocomplete
            var existing = inputElement.parentNode.querySelector('gmp-place-autocomplete');
            if (existing) existing.remove();
            
            // Create PlaceAutocompleteElement
            var autocomplete = document.createElement('gmp-place-autocomplete');
            autocomplete.setAttribute('for-map', 'false');
            autocomplete.setAttribute('type', 'address');
            autocomplete.setAttribute('country-code', 'US');
            
            // Style to match modal
            autocomplete.style.width = '100%';
            autocomplete.style.height = '42px';
            autocomplete.className = inputElement.className;
            
            // Replace original input
            inputElement.parentNode.insertBefore(autocomplete, inputElement);
            inputElement.style.display = 'none';
            
            // Handle place selection
            autocomplete.addEventListener('gmp-select', async function(event) {
                var place = event.placePrediction.toPlace();
                if (!place) return;
                
                try {
                    await place.fetchFields({ 
                        fields: ['displayName', 'formattedAddress', 'addressComponents', 'location'] 
                    });
                    
                    var addressComponents = place.addressComponents;
                    console.log(addressComponents);
                    if (!addressComponents) return;
                    
                    var addressData = {
                        streetNumber: '',
                        route: '',
                        city: '',
                        state: '',
                        country: '',
                        postalCode: ''
                    };
                    
                    addressComponents.forEach(function(component) {
                        var types = component.types;
                        
                        if (types.includes('street_number')) {
                            addressData.streetNumber = component.longText;
                        }
                        if (types.includes('route')) {
                            addressData.route = component.longText;
                        }
                        if (types.includes('locality')) {
                            addressData.city = component.longText;
                        }else if(types.includes('sublocality')) {
                            addressData.city = component.longText;
                        }
                        if (types.includes('administrative_area_level_1')) {
                            addressData.state = component.shortText;
                        }
                        if (types.includes('country')) {
                            addressData.country = component.shortText;
                        }
                        if (types.includes('postal_code')) {
                            addressData.postalCode = component.longText;
                        }
                    });
                    
                    // Fill modal form fields
                    var fullAddress = addressData.streetNumber + 
                        (addressData.streetNumber ? ' ' : '') + 
                        addressData.route;
                    
                    $('#billing_address_1').val(fullAddress).addClass('has-value');
                    $('#billing_city').val(addressData.city).addClass('has-value');
                    $('#billing_postcode').val(addressData.postalCode).addClass('has-value');
                    $('#billing_country').val(addressData.country).addClass('has-value').trigger('change');
                   
                    // Update hidden input
                    inputElement.value = fullAddress;
                    
                    selectedStateValue = addressData.state;
                    //store globally for external access
                    window.selectedStateValue = addressData.state;
                    
                } catch (error) {
                    console.error('Error fetching place details:', error);
                }
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