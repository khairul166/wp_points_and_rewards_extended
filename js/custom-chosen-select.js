jQuery(document).ready(function($) {

    $('.chosen-select').chosen({
        placeholder_text_single: "Select categories", // Placeholder text for single select
        no_results_text: "No categories found", // Text when no results found
        width: "100%"  // Set the width of the Chosen box
    });
    // Trigger the AJAX request on page load
    sendAjaxRequest();  // You may already have this functionality
    
    // Add an event listener to the input field (e.g., keyup, change)
    $('#your-input-id').on('input', function() {
        console.log('Input detected, sending AJAX request...');
        sendAjaxRequest();  // Call the function to send the AJAX request
    });

    // Your AJAX request function
    function sendAjaxRequest() {
        console.log('AJAX URL:', ajax_object.ajax_url);  // Log the AJAX URL
        console.log('Nonce:', ajax_object.nonce);  // Log the nonce value

        var requestData = {
            action: 'fetch_product_categories',
            nonce: ajax_object.nonce // Send the nonce
        };

        $.ajax({
            url: ajax_object.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: requestData,
            success: function(data) {
                console.log('Success response:', data);  // Log success response
                // Do something with the response (e.g., update the select options)
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX error:', textStatus, errorThrown);  // Log error details
                console.error('Response:', jqXHR.responseText);  // Log the full response
            }
        });
    }
});
