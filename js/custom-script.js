jQuery(document).ready(function ($) {
    // Initialize totalPointsApplied from localStorage or set to 0 if not present
    var totalPointsApplied = parseFloat(localStorage.getItem('totalPointsApplied')) || 0;

    // Function to apply points redemption
    function applyPointsRedemption(points) {
        var totalPointsToApply = points + totalPointsApplied; // Total points including previously applied ones

        var data = {
            action: 'apply_points_redemption',
            nonce: custom_script_params.nonce,
            points: totalPointsToApply, // Send the total points to be applied
            update_cart_only: true // Flag to indicate cart update without deduction
        };
        $('#apply-points-spinner').removeClass('hidden');
        $('#overlay').show();
        // Send the AJAX request
        $.post(custom_script_params.ajax_url, data, function (response) {
            $('#apply-points-spinner').addClass('hidden');
            $('#overlay').hide();
            if (response.success) {
                totalPointsApplied = totalPointsToApply; // Update the total points applied
                localStorage.setItem('totalPointsApplied', totalPointsApplied); // Store the updated total in localStorage

                // Parse the response data
                var discountAmount = $('<div>' + response.discount_amount + '</div>').text();
                var pointsEarned = response.points_earned;

                // Update the cart totals and discount amount
                $('.fee td').html('-' + discountAmount);
                $('.order-total td').html(response.total_amount);
                $('.points-earned td').html(pointsEarned + ' Points');

                if (response.points_redemption_amount === 0) {
                    $('.fee').hide();
                } else {
                    $('.fee').show();
                }

                // Trigger the cart recalculation
                $('body').trigger('update_checkout');
                $('.woocommerce-message, .woocommerce-error').remove();

                // Show the applied points message
                var pointText = Math.floor(points) + ' Points More Added. Total ' + Math.floor(totalPointsApplied) + ' Points Applied.';
                $('.woocommerce-cart-form').before('<div class="woocommerce-message" role="alert">' + pointText + '</div>');

            } else {
                $('.woocommerce-message, .woocommerce-error').remove();
                $('.woocommerce-cart-form').before('<div class="woocommerce-error" role="alert">Oops!! You don\'t have ' + points + ' points for redemption.</div>');
            }
        }).fail(function () {
            // Hide the spinner and overlay if there is an error
            $('#apply-points-spinner').addClass('hidden');
            $('#overlay').hide();
            alert('Error processing the request.');
        });
    }

    // Event listener for the "Apply Points" button
    $('#apply_points_btn').on('click', function () {
        var points = parseFloat($('#points_redemption').val());
        var cartTotal = parseFloat($('.cart-subtotal td').text().replace(/[^\d.]/g, ''));

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

    // Reset the points after checkout is completed
    $(document.body).on('checkout_order_processed', function () {
        localStorage.removeItem('totalPointsApplied');
    });

    // Clear the points when the user starts a new order
    $('.woocommerce-cart').on('click', '.checkout-button', function () {
        localStorage.removeItem('totalPointsApplied');
    });
});
