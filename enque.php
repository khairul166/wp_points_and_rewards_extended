<?php

function my_theme_enqueue_styles() {
    wp_enqueue_style( 'my_theme_style',  get_template_directory_uri() . '/reward-point/css/main-css.css' );
}
add_action( 'wp_enqueue_scripts', 'my_theme_enqueue_styles' );



function enqueue_custom_admin_style()
{
    // Replace 'your-theme-directory' with the actual path to your CSS file.
    $css_url = get_template_directory_uri() . '/reward-point/css/custom-modal.css';

    // Enqueue the stylesheet.
    wp_enqueue_style('custom-admin-style', $css_url);
}
add_action('admin_enqueue_scripts', 'enqueue_custom_admin_style');




function enqueue_admin_point_adjust_script()
{
    // Check if we are on the correct admin page
    if (isset($_GET['page']) && $_GET['page'] === 'points-rewards' && isset($_GET['tab']) && $_GET['tab'] === 'point-settings') {
        // Enqueue the JavaScript file
        wp_enqueue_script('admin-point-adjust', get_template_directory_uri() . '/reward-point/js/admin-point-adjust.js', array('jquery'), null, true);

        wp_localize_script(
            'admin-point-adjust',
            'adminPointAdjustData',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                // This sets the ajaxurl variable
                'verificationCode' => esc_js(get_option('verification_code', '')),
                'mailSendReport' => '',
            )
        );
    }
}

add_action('admin_enqueue_scripts', 'enqueue_admin_point_adjust_script');

function custom_enqueue_scripts()
{
    wp_enqueue_script('custom-script', get_template_directory_uri() . '/reward-point/js/custom-script.js', array('jquery'), '1.0', true);

    // Define and localize the ajaxurl, redemption rate, and nonce variables
    wp_localize_script(
        'custom-script',
        'custom_script_params',
        array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'redemption_rate' => get_option('point_conversation_rate_taka', ''),
            // Replace 'point_conversation_rate_taka' with the option name for your conversion rate
            'nonce' => wp_create_nonce('apply_points_redemption_nonce'),
            // Create a nonce for the AJAX request
        )
    );
}
add_action('wp_enqueue_scripts', 'custom_enqueue_scripts');


function enqueue_my_custom_scripts() {
    wp_enqueue_script('custom-script', get_template_directory_uri() . '/js/custom-script.js', array('jquery'), '1.0', true);

    // Create the nonce and pass it to the JavaScript
    wp_localize_script('custom-script', 'custom_script_params', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('apply_points_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'enqueue_my_custom_scripts');

// Enqueue the script and pass PHP variables to JavaScript
function enqueue_custom_script() {
    // Enqueue your custom script
    wp_enqueue_script('custom-script', get_template_directory_uri() . '/js/custom-script.js', array('jquery'), null, true);

    // Get the conversion rates from the options
    $point_conversation_rate_point = get_option('point_conversation_rate_point', 1); // Default value 1 if not set
    $point_conversation_rate_taka = get_option('point_conversation_rate_taka', 1); // Default value 1 if not set

    // Localize the script to pass PHP variables to JavaScript
    wp_localize_script('custom-script', 'conversion_rates', array(
        'point_rate' => $point_conversation_rate_point,
        'taka_rate' => $point_conversation_rate_taka
    ));
}
add_action('wp_enqueue_scripts', 'enqueue_custom_script');



function enqueue_datepicker_script() {
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('jquery-ui-datepicker-style', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
}
add_action('admin_enqueue_scripts', 'enqueue_datepicker_script');
