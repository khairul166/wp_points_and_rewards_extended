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
                $('.fee td').html('-' + discountAmount + ' <a href="#" class="remove-points">[remove]</a>');
                $('.order-total td').html(response.total_amount);
                $('.points-earned td').html(pointsEarned + ' Points');

                var adddiscountAmount = parseFloat(discountAmount.replace(/[^\d.-]/g, ''));
                if (adddiscountAmount === 0 || isNaN(adddiscountAmount)) {
                    $('.fee').hide(); // Hide the .fee row if adddiscountAmount is 0 or NaN
                } else {
                    $('.fee').show(); // Show the .fee row if adddiscountAmount is not 0
                }

                // Trigger the cart recalculation
                $('body').trigger('update_checkout');
                $('.woocommerce-message, .woocommerce-error').remove();

                // Show the applied points message on both the cart and checkout pages
                var pointText = Math.floor(points) + ' Points More Added. Total ' + Math.floor(totalPointsApplied) + ' Points Applied.';
                $('.woocommerce-cart-form, .woocommerce-form-coupon-toggle').before('<div class="woocommerce-message" role="alert">' + pointText + '</div>');

            } else {
                $('.woocommerce-message, .woocommerce-error').remove();
                $('.woocommerce-cart-form, .woocommerce-form-coupon-toggle').before('<div class="woocommerce-error" role="alert">Oops!! You don\'t have ' + points + ' points for redemption.</div>');
            }
        }).fail(function () {
            // Hide the spinner and overlay if there is an error
            $('#apply-points-spinner').addClass('hidden');
            $('#overlay').hide();
            alert('Error processing the request.');
        });
    }

    // Function to remove points redemption
    function removePointsRedemption() {
        totalPointsApplied = 0; // Reset the applied points
        localStorage.removeItem('totalPointsApplied'); // Clear the localStorage

        var data = {
            action: 'apply_points_redemption',
            nonce: custom_script_params.nonce,
            points: 0, // Reset points on the server
            update_cart_only: true // Flag to indicate cart update without deduction
        };

        $('#apply-points-spinner').removeClass('hidden');
        $('#overlay').show();

        // Send the AJAX request to remove points
        $.post(custom_script_params.ajax_url, data, function (response) {
            $('#apply-points-spinner').addClass('hidden');
            $('#overlay').hide();
            if (response.success) {
                // Update the cart totals and discount amount
                $('.fee td').html(response.discount_amount + ' <a href="#" class="remove-points">[remove]</a>');
                $('.order-total td').html(response.total_amount);
                $('.points-earned td').html(response.points_earned + ' Points');

                var remdiscountAmount = parseFloat(response.discount_amount.replace(/[^\d.-]/g, ''));
                if (remdiscountAmount === 0 || isNaN(remdiscountAmount)) {
                    $('.fee').hide();
                }

                $('.woocommerce-message, .woocommerce-error').remove();
                $('.woocommerce-cart-form, .woocommerce-form-coupon-toggle').before('<div class="woocommerce-message" role="alert">Points removed.</div>');

                $('body').trigger('update_checkout');
            }
        }).fail(function () {
            $('#apply-points-spinner').addClass('hidden');
            $('#overlay').hide();
            alert('Error processing the request.');
        });
    }

    // Delegate click event to remove points link, works for dynamic content
    $(document).on('click', '.remove-points', function (e) {
        e.preventDefault(); // Prevent the default anchor behavior
        removePointsRedemption(); // Call the function to remove points
    });

    // Re-run the function when WooCommerce updates the checkout via AJAX
    $(document.body).on('updated_checkout', function () {
        // Ensure remove link stays after checkout update
        var feeRow = $('tr.fee');
        if (feeRow.length && !feeRow.find('.remove-points').length) {
            feeRow.find('.woocommerce-Price-amount').append(' <a href="#" class="remove-points">[remove]</a>');
        }
    });

    // Event listener for the "Apply Points" button
    $('#apply_points_btn').on('click', function () {
        var points = parseFloat($('#points_redemption').val());
        var cartTotal = parseFloat($('.cart-subtotal td').text().replace(/[^\d.]/g, ''));

        if (points + totalPointsApplied <= cartTotal) {
            if (points >= 1) {
                applyPointsRedemption(points);
            } else {
                $('.woocommerce-message, .woocommerce-error').remove();
                $('.woocommerce-cart-form, .woocommerce-form-coupon-toggle').before('<div class="woocommerce-error" role="alert">Enter a valid point value to redeem.</div>');
            }
        } else {
            $('.woocommerce-message, .woocommerce-error').remove();
            $('.woocommerce-cart-form, .woocommerce-form-coupon-toggle').before('<div class="woocommerce-error" role="alert">Please enter a total of less than or equal to ' + cartTotal + ' Points to redeem.</div>');
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

    // Ensure the remove link is always appended on page load
    function ensureRemoveLink() {
        $('.fee').each(function () {
            var feeText = $(this).find('.amount').text();
            if (feeText && feeText.indexOf('[remove]') === -1) {
                $(this).find('.amount').append(' <a href="#" class="remove-points">[remove]</a>');
            }
        });
    }

    ensureRemoveLink(); // Run on page load
    $(document).on('updated_cart_totals', ensureRemoveLink); // Run after cart totals update

    // Ensure the fee row visibility on page load and after update
    function checkFeeRow() {
        var feeRow = document.querySelector('tr.fee');
        if (feeRow) {
            var feeamttext = feeRow.querySelector('.woocommerce-Price-amount').textContent.replace(/[^\d.]/g, '');
            var pointsRedemptionAmountElement = parseFloat(feeamttext.replace(',', ''));

            if (isNaN(pointsRedemptionAmountElement) || pointsRedemptionAmountElement === 0) {
                $('.fee').hide();
            } else {
                $('.fee').show();
            }
        }
    }

    // Run when the page is initially loaded
    checkFeeRow();

    // Re-run the function whenever WooCommerce updates the checkout page
    $(document.body).on('updated_checkout', function () {
        checkFeeRow();
    });

    // Initially hide the points redemption box
    $('.points-redemption-checkout').hide();

    // When the "Click here to add Points" link is clicked
    $('#showpoints').on('click', function (e) {
        e.preventDefault();

        // Toggle the visibility of the points redemption box
        $('.points-redemption-checkout').slideToggle();
    });
});
