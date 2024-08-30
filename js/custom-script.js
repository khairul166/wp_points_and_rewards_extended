jQuery(document).ready(function ($) {
    // Initialize totalPointsApplied from localStorage or set to 0 if not present
    var totalPointsApplied = parseFloat(localStorage.getItem('totalPointsApplied')) || 0;

    // Function to apply points redemption
    function applyPointsRedemption(points) {
        // We now send only the newly added points to the server
        var data = {
            action: 'apply_points_redemption',
            nonce: custom_script_params.nonce,
            points: points, // Send only the newly added points
        };

        // Send the AJAX request
        $.post(custom_script_params.ajax_url, data, function (response) {
            if (response.success) {
                totalPointsApplied += points; // Update the total points applied
                localStorage.setItem('totalPointsApplied', totalPointsApplied); // Store the updated total in localStorage

                // Parse the response data
                var discountAmount = $('<div>' + response.discount_amount + '</div>').text();
                var pointsEarned = response.points_earned;

                // Update the cart totals and discount amount
                $('.fee td').html('-' + discountAmount);
                $('.order-total td').html(response.total_amount);
                // Update the "Total Points" display on the cart and checkout pages
                $('.points-earned td').html(pointsEarned + ' Points');
                
                var pointsRedemptionAmount = parseFloat(discountAmount.replace(/[^\d.-]/g, ''));
                console.log('Points Redemption amount:', pointsRedemptionAmount);

                if(pointsRedemptionAmount === 0){
                    $('.fee').hide();
                }else{
                    $('.fee').show();
                }

                // Trigger the cart recalculation
                $('body').trigger('update_checkout');
                $('.woocommerce-message, .woocommerce-error').remove();
                
                // Show the applied points message
                var pointText = Math.floor(points) + ' Points More Added. Total ' + Math.floor(totalPointsApplied) + ' Points Applied.';
                $('.woocommerce-cart-form').before('<div class="woocommerce-message" role="alert">' + pointText + '</div>');
                
            } else {
                // Display the error message for insufficient points
                $('.woocommerce-message, .woocommerce-error').remove();
                $('.woocommerce-cart-form').before('<div class="woocommerce-error" role="alert">Oops!! You don\'t have ' + points + ' points for redemption.</div>');
            }
        }).fail(function () {
            alert('Error processing the request.');
        });
    }

    // Event listener for the "Apply Points" button
    function bindApplyPointsButton() {
        $('#apply_points_btn').off('click').on('click', function () {
            var points = parseFloat($('#points_redemption').val());
            var cartTotal = parseFloat($('.cart-subtotal td').text().replace(/[^\d.]/g, ''));
            console.log('Entered Points:', points);
            console.log('Cart Total:', cartTotal);
            
            if (points + totalPointsApplied <= cartTotal) {
                if (points >= 1) {
                    applyPointsRedemption(points);
                } else {
                    $('.woocommerce-message, .woocommerce-error').remove();
                    $('.woocommerce-cart-form').before('<div class="woocommerce-error" role="alert">Enter a valid point value to redeem.</div>');
                }
            } else {
                $('.woocommerce-message, .woocommerce-error').remove();
                $('.woocommerce-cart-form').before('<div class="woocommerce-error" role="alert">Please enter a total of less than or equal to ' + cartTotal + ' Points to redeem.</div>');
            }
        });
    }

    // Bind the Apply Points button initially
    bindApplyPointsButton();

    // Re-bind the Apply Points button after the cart is updated
    $(document.body).on('updated_cart_totals', function () {
        console.log('Cart updated, re-binding the Apply Points button');
        bindApplyPointsButton();

        // Reapply points if any were already applied
        if (totalPointsApplied > 0) {
            applyPointsRedemption(0); // Reapply the already applied points
        }
    });

    // Reset the points after checkout is completed
    $(document.body).on('checkout_order_processed', function () {
        localStorage.removeItem('totalPointsApplied');
    });

    // Alternatively, clear the points when the user starts a new order (if the above event doesn't fire)
    $('.woocommerce-cart').on('click', '.checkout-button', function () {
        localStorage.removeItem('totalPointsApplied');
    });

    // Hide the fee row if points redemption amount is zero
    var feeRow = document.querySelector('tr.fee');
    if(feeRow){
        var feeamttext = feeRow.querySelector('.woocommerce-Price-amount').textContent.replace(/[^\d.]/g, '');
        var pointsRedemptionAmountElement = parseFloat(feeamttext.replace(',', ''));
        
        if (isNaN(pointsRedemptionAmountElement) || pointsRedemptionAmountElement === 0) {
            $('.fee').hide();
        }
    }
});
