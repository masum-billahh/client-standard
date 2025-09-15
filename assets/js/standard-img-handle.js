jQuery(document).ready(function($) {
    
    // Function to toggle between default and PayPal button
    function togglePayPalButton() {
        var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
        var placeOrderButton = $('#place_order');
        var paypalButton = $('#wpppc-paypal-button');
        
        if (selectedPaymentMethod === wpppc_checkout.gateway_id) {
            // Hide default place order button
            placeOrderButton.hide();
            // Show PayPal button
            paypalButton.show();
        } else {
            // Show default place order button
            placeOrderButton.show();
            // Hide PayPal button
            paypalButton.hide();
        }
    }
    
    // Initial check
    togglePayPalButton();
    
    // Listen for payment method changes
    $(document.body).on('change', 'input[name="payment_method"]', function() {
        togglePayPalButton();
    });
    
    // Handle PayPal button click
    $('#wpppc-paypal-button').on('click', function(e) {
        e.preventDefault();
        
        // Trigger the original place order button click
        $('#place_order').trigger('click');
    });
    
    // Handle checkout update events (when checkout form is updated)
    $(document.body).on('updated_checkout', function() {
        togglePayPalButton();
        
        // Re-bind click event after checkout update
        $('#wpppc-paypal-button').off('click').on('click', function(e) {
            e.preventDefault();
            $('#place_order').trigger('click');
        });
    });
    
    //Add loading state to PayPal button during checkout process
    $(document.body).on('checkout_place_order_paypal_standard_proxy', function() {
        $('#wpppc-paypal-button').css('opacity', '0.5').css('cursor', 'not-allowed');
        return true; // Allow the checkout to proceed
    });
    
    // Reset button state if checkout fails
    $(document.body).on('checkout_error', function() {
        $('#wpppc-paypal-button').css('opacity', '1').css('cursor', 'pointer');
    });
    
});