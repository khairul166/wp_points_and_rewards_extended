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
     // Enqueue Chart.js from a CDN.
     // Enqueue Chart.js and luxon
wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
wp_enqueue_script('luxon', 'https://cdn.jsdelivr.net/npm/luxon@3.0.1/build/global/luxon.min.js', [], null, true);
wp_enqueue_script('chartjs-adapter-luxon', 'https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon', [], null, true);


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

// function enqueue_select2_script_admin() {
//     wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), '4.0.13', true);
//     wp_enqueue_script('custom-category-select', get_stylesheet_directory_uri() . '/reward-point/js/custom-category-select.js', array('jquery', 'select2'), null, true);

//     wp_localize_script('custom-category-select', 'ajax_object', array(
//         'ajax_url' => admin_url('admin-ajax.php'),
//         'nonce'    => wp_create_nonce('fetch_categories_nonce') // Same key as in PHP check
//     ));
// }
// add_action('admin_enqueue_scripts', 'enqueue_select2_script_admin');


function enqueue_chosen_script_admin() {
    // Chosen CSS and JS
    wp_enqueue_style('chosen-css', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.min.css');
    wp_enqueue_script('chosen-js', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js', array('jquery'), '1.8.7', true);

    // Custom script to initialize Chosen
    wp_enqueue_script('custom-chosen-select', get_stylesheet_directory_uri() . '/reward-point/js/custom-chosen-select.js', array('jquery', 'chosen-js'), null, true);

    // Pass AJAX URL to JS
    wp_localize_script('custom-chosen-select', 'ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('fetch_categories_nonce') // For nonce verification
    ));
}
add_action('admin_enqueue_scripts', 'enqueue_chosen_script_admin');




