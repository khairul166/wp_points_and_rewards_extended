<?php

require_once(get_template_directory() . '/reward-point/custom-point-adjustment.php');
require_once(get_template_directory() . '/reward-point/enque.php');
require_once(get_template_directory() . '/reward-point/excel/src/SimpleXLSXGen.php');



// Start session if not already started
function start_session() {
    if (!session_id()) {
        session_start();
    }
}
add_action('init', 'start_session', 1);





// Function to send an email and return JSON response
function send_email_callback()
{
    // Generate a new verification code
    $new_verification_code = rand(100000, 999999);

    // Store the new verification code temporarily in a session variable
    $_SESSION['verification_code'] = $new_verification_code;

    // Prepare the email content
    $to = get_bloginfo('admin_email');
    $subject = 'Verification Code for Admin Point Adjustment';
    $message = 'Your verification code is: ' . $new_verification_code;

    // Send the email using wp_mail
    if (wp_mail($to, $subject, $message)) {
        $response = array(
            'success' => true,
            'newVerificationCode' => $new_verification_code,
        );
    } else {
        $response = array(
            'success' => false,
            'message' => 'Email could not be sent.',
        );
    }

    // Return the JSON response
    wp_send_json($response);
}

// Hook the callback function to both logged in and non-logged in users
add_action('wp_ajax_send_email', 'send_email_callback');
add_action('wp_ajax_nopriv_send_email', 'send_email_callback');






// Function to check and create the wp_point_log table and columns
function create_point_log_table()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'point_log';

    // Check if the table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();

        // SQL query to create the table
        $sql = "CREATE TABLE $table_name (
            id INT NOT NULL AUTO_INCREMENT,
            log_date DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            points FLOAT NOT NULL,
            point_source VARCHAR(255) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            order_id BIGINT UNSIGNED,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Create the table
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    } else {
        // Check if the columns exist
        $columns = $wpdb->get_col("DESC $table_name", 0);
        $required_columns = array('log_date', 'user_id', 'points', 'point_source', 'reason', 'order_id');

        // Compare the required columns with the existing columns
        $missing_columns = array_diff($required_columns, $columns);

        // If any required columns are missing, add them
        if (!empty($missing_columns)) {
            foreach ($missing_columns as $column) {
                $sql = "ALTER TABLE $table_name ADD $column";
                $wpdb->query($sql);
            }
        }
    }
}

// Hook the function to run during the theme's activation
add_action('after_switch_theme', 'create_point_log_table');

/**
 * Function to set up points for a user
 *
 * @param int $user_id The ID of the user
 * @param int $points The points to be set for the user
 */
function set_user_points($user_id, $points)
{
    // Retrieve the user's current points balance from the custom table
    global $wpdb;
    $table_name = $wpdb->prefix . 'point_log';
    $current_points = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT points FROM $table_name WHERE user_id = %d",
            $user_id
        )
    );

    // If the user's entry exists in the table, update the points balance
    if ($current_points !== null) {
        $updated_points = $current_points + $points;
        $wpdb->update(
            $table_name,
            array('points' => $updated_points),
            array('user_id' => $user_id)
        );
    } else {
        // If the user's entry doesn't exist, insert a new row with the points balance
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'points' => $points
            )
        );
    }
}

/**
 * Add a sub-menu under the WooCommerce menu item for Points and Rewards
 */
function add_points_rewards_submenu()
{
    add_submenu_page(
        'woocommerce',
        'Points and Rewards',
        'Points and Rewards',
        'manage_woocommerce',
        'points-rewards',
        'points_rewards_submenu_callback'
    );
}
add_action('admin_menu', 'add_points_rewards_submenu');


// Callback function for the Points and Rewards sub-menu page
function points_rewards_submenu_callback() {
    global $wpdb;

    // Fetch filters from GET request
    $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
    $selected_sources = isset($_GET['point_sources']) ? array_map('sanitize_text_field', $_GET['point_sources']) : [];

    // Base query to retrieve logs
    $query = "SELECT * FROM {$wpdb->prefix}point_log WHERE 1=1";

    // Apply filters (same as used in the display logic)
    if (!empty($search_query)) {
        $query .= $wpdb->prepare(
            " AND user_id IN (
                SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s
            )",
            '%' . $search_query . '%'
        );
    }

    if (!empty($start_date) && !empty($end_date)) {
        $query .= $wpdb->prepare(
            " AND DATE(log_date) BETWEEN %s AND %s",
            $start_date, $end_date
        );
    } elseif (!empty($start_date)) {
        $query .= $wpdb->prepare(
            " AND DATE(log_date) >= %s",
            $start_date
        );
    } elseif (!empty($end_date)) {
        $query .= $wpdb->prepare(
            " AND DATE(log_date) <= %s",
            $end_date
        );
    }

    if (!empty($selected_sources)) {
        $placeholders = implode(', ', array_fill(0, count($selected_sources), '%s'));
        $query .= $wpdb->prepare(
            " AND point_source IN ($placeholders)",
            ...$selected_sources
        );
    }
    $query .= " ORDER BY `id` DESC";

    // Fetch the filtered data
    $logs = $wpdb->get_results($query);

    // Check if PDF export is requested
    if (isset($_POST['export_pdf'])) {
        // Start output buffering to prevent premature output
        ob_end_clean(); // Clean the previous buffer if any

        // Include the FPDF library
        require_once get_template_directory() . '/reward-point/fpdf/fpdf.php';

        // Check if logs are empty
        if (empty($logs)) {
            echo 'No data available for export.';
            exit;
        }

        // Create a new PDF instance
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 10);

        // Add table headers
        $pdf->Cell(10, 10, 'SL', 1);
        $pdf->Cell(30, 10, 'Username', 1);
        $pdf->Cell(30, 10, 'Name', 1);
        $pdf->Cell(30, 10, 'Role', 1);
        $pdf->Cell(30, 10, 'Point Source', 1);
        $pdf->Cell(30, 10, 'Date', 1);
        $pdf->Cell(20, 10, 'Points', 1);
        $pdf->Ln();

        // Add data to the PDF
        $serial_number = 1;
        foreach ($logs as $log) {
            $user_info = get_userdata($log->user_id);

            // Ensure $user_info is valid
            if ($user_info) {
                $pdf->Cell(10, 10, $serial_number++, 1);
                $pdf->Cell(30, 10, $user_info->user_login, 1);
                $pdf->Cell(30, 10, $user_info->display_name, 1);
                $pdf->Cell(30, 10, implode(', ', $user_info->roles), 1);
                $pdf->Cell(30, 10, $log->point_source, 1);
                $pdf->Cell(30, 10, date('Y-m-d', strtotime($log->log_date)), 1);
                $pdf->Cell(20, 10, $log->points, 1);
                $pdf->Ln();
            }
        }

        // Clean any buffer before sending PDF
        ob_clean();

        // Set headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="point_log_' . time() . '.pdf"');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        // Output the PDF
        $pdf->Output('D', 'point_log_' . time() . '.pdf');

        // End the buffering and exit
        exit;
    }

    // Proceed with rendering the HTML page if no export is requested
    if (isset($_GET['tab'])) {
        $active_tab = sanitize_text_field($_GET['tab']);
    } else {
        $active_tab = 'manage-points'; // Set default tab to "Manage Points"
    }

    echo '<div class="ptn-wrap">';
    echo '<h1 class="ptn-head">Points and Rewards <span class="devtext">Developed by <a href="https://www.linkedin.com/in/khirul166">Khairul</a></span></h1>';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=points-rewards&tab=manage-points" class="nav-tab ' . (($active_tab === 'manage-points') ? 'nav-tab-active' : '') . '">Manage Points</a>';
    echo '<a href="?page=points-rewards&tab=point-log" class="nav-tab ' . (($active_tab === 'point-log') ? 'nav-tab-active' : '') . '">Point Log</a>';
    echo '<a href="?page=points-rewards&tab=point-settings" class="nav-tab ' . (($active_tab === 'point-settings') ? 'nav-tab-active' : '') . '">Point Settings</a>';
    echo '</h2>';

    switch ($active_tab) {
        case 'manage-points':


            // Include necessary WordPress files
            require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
            require_once(get_template_directory() . '/reward-point/custom-user-list-table.php'); // Replace with the actual file path

            // Create an instance of your custom user list table
            $user_list_table = new Custom_User_List_Table();

            // Handle the search query
            $search_query = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';

            // Prepare the data for the user list table
            $user_list_table->prepare_items();

            global $wpdb;
            $table_name = $wpdb->prefix . 'point_log';
            ?>

                        <div class="wrap">
                            <h2>Customer Point List</h2>
                            <p class="search-box">
                            <form method="get" action="" style="float: right; margin: 0;">
                                <input type="hidden" name="page" value="points-rewards">
                                <input type="hidden" name="tab" value="manage-points">
                                <input type="text" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Search User">
                                <input type="submit" value="Search" class="button">
                            </form>
                            </p>
                            <?php $user_list_table->display(); ?>
                        </div>


                        <?php
                        break;
        case 'point-log':

            //points_page_callback();
            //==================================================================

            // Retrieve the user's point log
            global $wpdb;
            $table_name = $wpdb->prefix . 'point_log';

            // Get the search query if submitted
            $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

            // Get the start and end dates from the form
            $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
            $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

            // Get the selected point sources from the form (multiple select)
            $selected_sources = isset($_GET['point_sources']) ? array_map('sanitize_text_field', $_GET['point_sources']) : [];

            // Base query to retrieve logs
            $query = "SELECT * FROM {$table_name} WHERE 1=1";

            // If a search query is provided, add the WHERE clause to filter logs by user ID
            if (!empty($search_query)) {
                $query .= $wpdb->prepare(
                    " AND user_id IN (
                        SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s
                    )",
                    '%' . $search_query . '%'
                );
            }

            // Add conditions for date filtering
            if (!empty($start_date) && !empty($end_date)) {
                // Filter by date range if both start and end dates are provided
                $query .= $wpdb->prepare(
                    " AND DATE(log_date) BETWEEN %s AND %s",
                    $start_date,
                    $end_date
                );
            } elseif (!empty($start_date)) {
                // Filter from start date onwards if only start date is provided
                $query .= $wpdb->prepare(
                    " AND DATE(log_date) >= %s",
                    $start_date
                );
            } elseif (!empty($end_date)) {
                // Filter up to the end date if only end date is provided
                $query .= $wpdb->prepare(
                    " AND DATE(log_date) <= %s",
                    $end_date
                );
            }

            // If point sources are selected, filter logs by point source
            if (!empty($selected_sources)) {
                $placeholders = implode(', ', array_fill(0, count($selected_sources), '%s'));
                $query .= $wpdb->prepare(
                    " AND point_source IN ($placeholders)",
                    ...$selected_sources
                );
            }

            // Add the ORDER BY clause
            $query .= " ORDER BY `id` DESC";

            // Pagination variables
            $per_page = 20;
            $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
            $offset = ($current_page - 1) * $per_page;
            $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM ({$query}) AS total_logs");
            $total_pages = ceil($total_logs / $per_page);

            // Add the LIMIT clause for pagination
            $query .= " LIMIT {$per_page} OFFSET {$offset}";

            // Retrieve the logs
            $logs = $wpdb->get_results($query);


            // Display the point log
            if ($logs) {
                $point_and_reward = get_option('point_and_reward', 0);
                echo '<div class="wrap">';
                echo '<h2>Point Log</h2>';
                echo '<p class="search-box" style="float: right; margin: 0;">';





// Excel Export
if (isset($_POST['export_excel'])) {
    // Include SimpleXLSXGen library
    require_once(get_template_directory() . '/reward-point/excel/src/SimpleXLSXGen.php');


    global $wpdb;

    // Fetch data
    $logs = $wpdb->get_results("SELECT * FROM wp_point_log");

    // Check if data exists
    if (empty($logs)) {
        echo 'No data available for export.';
        return;
    }

    // Prepare data for Excel
    $xlsxData = [['SL', 'Username', 'Name', 'Role', 'Point Source', 'Date', 'Points']];
    $serial_number = 1;

    foreach ($logs as $log) {
        $user_info = get_userdata($log->user_id);

        if ($user_info) {
            $xlsxData[] = [
                $serial_number++,
                $user_info->user_login,
                $user_info->display_name,
                implode(', ', $user_info->roles),
                $log->point_source,
                date('Y-m-d', strtotime($log->log_date)),
                $log->points
            ];
        }
    }

    // Generate Excel file
    $xlsx = SimpleXLSXGen::fromArray($xlsxData);

    // Download the Excel file
    $xlsx->downloadAs('point_log_' . time() . '.xlsx');
    exit;
}

// if (class_exists('SimpleXLSXGen')) {
//     echo '<br/>Class found!';
// } else {
//     echo '<br/>Class not found!';
// }


                                               
                echo '<div class="form-container"><form method="get" action="" class="form1">';

                // Add a multiple select dropdown for point sources
                echo '<label>Point Source: </label>'; ?>

                            <select class="tag-select" name="point_sources[]">
                                <option value="" disabled selected>Select Point Source</option>
                                <option value=""  <?php echo isset($_GET['point_sources']) && in_array('', (array) $_GET['point_sources']) ? 'selected' : ''; ?>>Unknown Source</option>
                                <option value="purchase" <?php echo isset($_GET['point_sources']) && in_array('purchase', (array) $_GET['point_sources']) ? 'selected' : ''; ?>>Purchase</option>
                                <option value="admin_adjustment" <?php echo isset($_GET['point_sources']) && in_array('admin_adjustment', (array) $_GET['point_sources']) ? 'selected' : ''; ?>>Admin Adjustment</option>
                                <option value="redeem" <?php echo isset($_GET['point_sources']) && in_array('redeem', (array) $_GET['point_sources']) ? 'selected' : ''; ?>>Redeem</option>
                                <option value="signup_bonus" <?php echo isset($_GET['point_sources']) && in_array('signup_bonus', (array) $_GET['point_sources']) ? 'selected' : ''; ?>>Signup Bonus</option>
                                <option value="signup_ref" <?php echo isset($_GET['point_sources']) && in_array('signup_ref', (array) $_GET['point_sources']) ? 'selected' : ''; ?>>Referral Bonus</option>
                                <option value="ref_signup" <?php echo isset($_GET['point_sources']) && in_array('ref_signup', (array) $_GET['point_sources']) ? 'selected' : ''; ?>>Signup Referral Bonus</option>
                            </select>

                               <?php // Add Date range
                                               echo '<label>Date Range: </label>';
                                               echo '<input type="date" name="start_date" value="' . esc_attr($_GET['start_date'] ?? '') . '" placeholder="Start Date">';
                                               echo ' - ';
                                               echo '<input type="date" name="end_date" value="' . esc_attr($_GET['end_date'] ?? '') . '" placeholder="End Date">';




                                               echo '<input type="hidden" name="page" value="points-rewards">';
                                               echo '<input type="hidden" name="tab" value="point-log">';
                                               echo '<input type="text" name="search" value="' . esc_attr($search_query) . '" placeholder="Search user by username">';
                                               echo '<input type="submit" class="button" value="Filter">';
                                               echo '</form>';
                                               // Include FPDF library and other necessary files
                                               echo '<form method="post" action="" class="form2">';
                                               echo '<input type="submit" name="export_pdf" class="button" value="Export to PDF">';
                                               echo '<input type="submit" name="export_excel" class="button" value="Export to Excel">';
                                               echo '</form></div>';
                                               //print_r($logs);
                               
                                               echo '<table class="wp-list-table widefat striped">';
                                               echo '<thead><tr><th>SL</th><th>Username</th><th>Name</th><th>Role</th><th>Point Source</th><th>Date</th><th>Points</th></tr></thead><tbody>';
                                               $serial_number = $offset + 1;
                                               foreach ($logs as $log) {
                                                   $log_date = strtotime($log->log_date);
                                                   $user_id = $log->user_id;
                                                   $user_info = get_userdata($user_id);

                                                   // Check if user_info is not false and is an object before accessing its properties
                                                   if ($user_info && is_object($user_info)) {
                                                       $user_login = $user_info->user_login;
                                                       $display_name = $user_info->display_name;
                                                       $user_roles = $user_info->roles;
                                                   } else {
                                                       $user_login = 'N/A'; // Default value if user_info is false or not an object
                                                       $display_name = 'N/A'; // Default value if user_info is false or not an object
                                                       $user_roles = array(); // Default empty array if user_info is false or not an object
                                                   }

                                                   $current_time = current_time('timestamp');

                                                   if (date('Y-m-d', $log_date) === date('Y-m-d', $current_time)) {
                                                       $human_date = human_time_diff($log_date, $current_time) . ' ago';
                                                   } else {
                                                       $human_date = date('j F, Y \a\t g:i A', $log_date);
                                                   }

                                                   $point_source = $log->point_source;
                                                   $reason = $log->reason;
                                                   if (!$reason) {
                                                       $reason = 'for unknown reason';
                                                   } else {
                                                       $reason = 'for ' . $reason;
                                                   }
                                                   $log_order_id = $log->order_id;
                                                   $order = wc_get_order($log_order_id);
                                                   $view_order_url = admin_url('post.php?post=' . $log_order_id . '&action=edit');
                                                   if ($point_source === 'purchase') {
                                                       $point_source_text = 'Earned for Purchase <a href="' . $view_order_url . '">#' . $log_order_id . '</a>';
                                                   } elseif ($point_source === 'admin_adjustment') {
                                                       $point_source_text = 'Point Adjusted by Admin ' . $reason;
                                                   } elseif ($point_source === 'redeem') {
                                                       $point_source_text = 'Deducted for Redeeming <a href="' . $view_order_url . '">#' . $log_order_id . '</a>';

                                                   } elseif ($point_source === 'signup_bonus') {
                                                       $point_source_text = 'Signup Bonus';
                                                   } elseif ($point_source === 'signup_ref') {
                                                       $point_source_text = 'Referral Bonus';
                                                   } elseif ($point_source === 'ref_signup') {
                                                       $point_source_text = 'Signup Referral Bonus';
                                                   } else {
                                                       $point_source_text = 'Unknown Source';
                                                   }

                                                   // Check if $user_roles is an array before using implode()
                                                   $user_roles_text = is_array($user_roles) ? implode(', ', $user_roles) : 'N/A';

                                                   // Display the table row
                                                   echo '<tr>';
                                                   echo '<td>' . $serial_number . '.</td>';
                                                   echo '<td><a href="' . esc_url(get_edit_user_link($log->user_id)) . '">' . esc_html($user_login) . '</a></td>';
                                                   echo '<td>' . esc_html($display_name) . '</td>';
                                                   echo '<td>' . esc_html($user_roles_text) . '</td>';
                                                   echo '<td>' . $point_source_text . '</td>';
                                                   echo '<td>' . esc_html($human_date) . '</td>';
                                                   echo '<td>' . esc_html($log->points) . '</td>';
                                                   echo '</tr>';

                                                   // Increment the serial number for the next row
                                                   $serial_number++;
                                               }

                                               echo '<tfoot><tr><th>SL</th><th>Username</th><th>Name</th><th>Role</th><th>Point Source</th><th>Date</th><th>Points</th></tr></tfoot><tbody>';
                                               echo '</tbody></table>';

                                               $pagination = paginate_links(
                                                   array(
                                                       'base' => add_query_arg('paged', '%#%'),
                                                       'format' => '&paged=%#%',
                                                       'current' => max(1, $current_page),
                                                       'total' => $total_pages,
                                                       'prev_text' => '&laquo;',
                                                       'next_text' => '&raquo;',
                                                       'type' => 'array',
                                                   )
                                               );

                                               if (!empty($pagination)) {
                                                   $output = '<div class="tablenav-pages" style="float: right; margin: 6px 0px 0px 0px;">';
                                                   $output .= '<span class="displaying-num">' . number_format_i18n($total_logs) . ' items </span>';

                                                   // First page link
                                                   $output .= '<a class="button first-page ' . ($current_page === 1 ? 'disabled' : '') . '" href="' . esc_url(add_query_arg('paged', '1', get_pagenum_link(1, false))) . '">&laquo;</a>';

                                                   // Previous page link
                                                   if ($current_page > 1) {
                                                       $output .= ' <a class="button prev-page" href="' . esc_url(add_query_arg('paged', $current_page - 1, get_pagenum_link($current_page - 1, false))) . '">&lsaquo;</a> ';
                                                   } else {
                                                       $output .= ' <a class="button prev-page disabled" href="#">&lsaquo;</a> ';
                                                   }

                                                   // Page input box
                                                   $output .= '<span class="paging-input">';
                                                   $output .= '<label for="current-page-selector" class="screen-reader-text">Current Page</label>';
                                                   $output .= '<input class="current-page" id="current-page-selector" type="number" name="paged" min="1" max="' . $total_pages . '" value="' . $current_page . '" size="1" aria-describedby="table-paging" />';
                                                   $output .= '<span class="tablenav-paging-text"> of <span class="total-pages">' . $total_pages . '</span></span> ';
                                                   $output .= '</span>';

                                                   // Next page link
                                                   if ($current_page < $total_pages) {
                                                       $output .= '<a class="button next-page" href="' . esc_url(add_query_arg('paged', $current_page + 1, get_pagenum_link($current_page + 1, false))) . '">&rsaquo;</a>';
                                                   } else {
                                                       $output .= '<a class="button next-page disabled" href="#">&rsaquo;</a>';
                                                   }

                                                   // Last page link
                                                   if ($current_page >= $total_pages) {
                                                       $output .= ' <a class="button last-page disabled" href="#">&raquo</a>';
                                                   } else {
                                                       $output .= ' <a class="button last-page" href="' . esc_url(add_query_arg('paged', $total_pages, get_pagenum_link($total_pages, false))) . '">&raquo;</a>';
                                                   }


                                                   // Pagination links
                                                   $output .= '<span class="pagination-links">';

                                                   $output .= '</span>';

                                                   $output .= '</div>';
                                                   echo $output;
                                               }
                                               ?>
                                <script>
                                    // JavaScript to handle form submission when the user enters a page number and hits Enter
                                    document.addEventListener('DOMContentLoaded', function () {
                                        const pageInput = document.querySelector('.current-page');
                                        pageInput.addEventListener('keydown', function (event) {
                                            if (event.keyCode === 13) {
                                                event.preventDefault();
                                                const page = parseInt(pageInput.value);
                                                const totalPages = parseInt(document.querySelector('.total-pages').textContent);
                                                if (page >= 1 && page <= totalPages) {
                                                    // Get the current URL
                                                    const currentURL = new URL(window.location.href);
                                                    // Update the 'paged' parameter in the query string
                                                    currentURL.searchParams.set('paged', page);
                                                    // Navigate to the updated URL
                                                    window.location.href = currentURL.toString();
                                                }
                                            }
                                        });
                                    });
                                </script>

                                <?php

                                echo '</div>';
            } else {
                echo '<div class="wrap">';
                echo '<h2>Point Log</h2>';
                echo '<div class="form-container"><form method="get" action="" class="form1">';

                // Add a multiple select dropdown for point sources
                echo '<label>Point Source: </label>';
                echo '<select class="tag-select" name="point_sources[]" Placeholder="Select Point Source">
                 <option value="">Select Point Source</option>
                 <option value="purchase">Purchase</option>
                 <option value="admin_adjustment">Admin Adjustment</option>
                 <option value="redeem">Redeem</option>
                 <option value="signup_bonus">Signup Bonus</option>
                 <option value="signup_ref">Referral Bonus</option>
                 <option value="ref_signup">Signup Referral Bonus</option>
                 </select>';


                echo '<label>Date Range: </label>';
                echo '<input type="date" name="start_date" value="' . esc_attr($_GET['start_date'] ?? '') . '" placeholder="Start Date">';
                echo ' - ';
                // echo '<label>End Date:</label>';
                echo '<input type="date" name="end_date" value="' . esc_attr($_GET['end_date'] ?? '') . '" placeholder="End Date">';

                echo '<input type="hidden" name="page" value="points-rewards">';
                echo '<input type="hidden" name="tab" value="point-log">';
                echo '<input type="text" name="search" value="' . esc_attr($search_query) . '" placeholder="Search user by username">';
                echo '<input type="submit" class="button" value="Search">';
                echo '</form>';
                // Include FPDF library and other necessary files
                echo '<form method="post" action="" class="form2">';
                echo '<input type="submit" name="export_pdf" class="button" value="Export to PDF">';
                echo '<input type="submit" name="export_excel" class="button" value="Export to Excel">';
                echo '</form></div>';

                echo '<table class="wp-list-table widefat striped">';
                echo '<thead><tr><th>SL</th><th>Username</th><th>Name</th><th>Role</th><th>Point Source</th><th>Date</th><th>Points</th></tr></thead><tbody>';
                echo '<tr><td>No Log Found</td></tr>';
                echo '<tfoot><tr><th>SL</th><th>Username</th><th>Name</th><th>Role</th><th>Point Source</th><th>Date</th><th>Points</th></tr></tfoot><tbody>';
                echo '</tbody></table>';
                echo '<div class="tablenav-pages" style="float: right; margin: 6px 0px 0px 0px;">';
                echo '<span class="displaying-num">' . number_format_i18n($total_logs) . ' items </span></div>';
            }


            //=======================================================
            break;
        case 'point-settings':
            // Add your code for Point Settings tab
            if (isset($_POST['save_point_settings'])) {
                // Process and save the form data
                $point_and_reward = isset($_POST['point_and_reward']) ? 1 : 0;
                $point_conversation_rate_point = isset($_POST['point_conversation_rate_point']) ? sanitize_text_field($_POST['point_conversation_rate_point']) : '';
                $point_conversation_rate_taka = isset($_POST['point_conversation_rate_taka']) ? sanitize_text_field($_POST['point_conversation_rate_taka']) : '';
                $point_redemption = isset($_POST['point_redemption']) ? 1 : 0;
                $redemption_conversation_rate_point = isset($_POST['redemption_conversation_rate_point']) ? sanitize_text_field($_POST['redemption_conversation_rate_point']) : '';
                $redemption_conversation_rate_taka = isset($_POST['redemption_conversation_rate_taka']) ? sanitize_text_field($_POST['redemption_conversation_rate_taka']) : '';
                $total_purchase_point = isset($_POST['total_purchase_point']) ? 1 : 0; // Ensure it's stored as a boolean
                $signup_point = isset($_POST['signup_point']) ? 1 : 0; // Ensure it's stored as a boolean
                $admin_point_adjust = isset($_POST['admin_point_adjust']) ? 1 : 0; // Ensure it's stored as a boolean
                $ref_system = isset($_POST['ref_system']) ? 1 : 0; // Ensure it's stored as a boolean
                $signup_points_box = isset($_POST['signup_points_box']) ? sanitize_text_field($_POST['signup_points_box']) : '';
                $ref_user_points_box = isset($_POST['ref_user_points_box']) ? sanitize_text_field($_POST['ref_user_points_box']) : '';
                $referrer_points_box = isset($_POST['referrer_points_box']) ? sanitize_text_field($_POST['referrer_points_box']) : '';
                $point_massage = isset($_POST['point_massage']) ? 1 : 0; // Ensure it's stored as a boolean
                // Save the point and reward status, conversation rates, and point redemption to the database or perform any other necessary actions
                update_option('point_and_reward', $point_and_reward);
                update_option('point_conversation_rate_point', $point_conversation_rate_point);
                update_option('point_conversation_rate_taka', $point_conversation_rate_taka);
                update_option('point_redemption', $point_redemption);
                update_option('redemption_conversation_rate_point', $redemption_conversation_rate_point);
                update_option('redemption_conversation_rate_taka', $redemption_conversation_rate_taka);
                update_option('total_purchase_point', $total_purchase_point);
                update_option('signup_point', $signup_point);
                update_option('admin_point_adjust', $admin_point_adjust);
                update_option('signup_points_box', $signup_points_box);
                update_option('ref_system', $ref_system);
                update_option('ref_user_points_box', $ref_user_points_box);
                update_option('referrer_points_box', $referrer_points_box);
                update_option('point_massage', $point_massage);

                //echo '<div class="notice notice-success"><p><strong>Point settings saved.</strong></p></div>';
                echo '<div class="success-notice"><p><strong>Point settings saved.</strong></p></div>';

            }
            // Get the current point and reward status, conversation rates, and point redemption from the database
            $point_and_reward = get_option('point_and_reward', 0);
            $point_conversation_rate_point = get_option('point_conversation_rate_point', '');
            $point_conversation_rate_taka = get_option('point_conversation_rate_taka', '');
            $point_redemption = get_option('point_redemption', 0);
            $redemption_conversation_rate_point = get_option('redemption_conversation_rate_point', '');
            $redemption_conversation_rate_taka = get_option('redemption_conversation_rate_taka', '');
            $total_purchase_point = get_option('total_purchase_point', 0);
            $signup_point = get_option('signup_point', 0);
            $admin_point_adjust = get_option('admin_point_adjust', 0);
            $signup_points_box = get_option('signup_points_box', 0);
            $ref_system = get_option('ref_system', 0);
            $ref_user_points_box = get_option('ref_user_points_box', 0);
            $referrer_points_box = get_option('referrer_points_box', 0);
            $point_massage = get_option('point_massage', 0);
            ?>

                        <div id="point-settings" class="wrap">
                            <form method="post" action="">
               
                            <div class="container tbl-group">
                                <div class="full-width-div">Point Settings</div>
                                <div class="left-width-div">
                                    <label for="point_and_reward">Enable Points Reward:</label>
                                    <span class="custom-tooltip" tabindex="0" aria-label="Toggle this to enable this feature">
                <span class="tooltip-icon">?</span>
            </span>
                            </div>
                                <div class="right-width-div right-div-ht">
                                    <div class="toggle-switch">
                                                                <input type="checkbox" class="toggle" id="point_and_reward" name="point_and_reward" <?php echo checked($point_and_reward, 1); ?>>
                                                    <label class="toggle-slider" for="point_and_reward"></label>
                                                </div>
                                            </div>
                                            <div class="left-width-div">
                                                <label for="point_conversation_rate_point">Earn Point Conversation Rate:</label>
                                                <span class="custom-tooltip" tabindex="0" aria-label="Enter Point Earning rate">
                <span class="tooltip-icon">?</span>
            </span>
                                            </div>
                                            <div class="right-width-div"><input type="number" id="point_conversation_rate_point"
                                                    name="point_conversation_rate_point" placeholder="Point"
                                                    value="<?php echo esc_attr($point_conversation_rate_point); ?>" class="pts-input" required>
                                                <label for="point_conversation_rate_taka"> Point(s) on every </label>
                                                <input type="number" id="point_conversation_rate_taka" name="point_conversation_rate_taka" placeholder="Taka"
                                                    value="<?php echo esc_attr($point_conversation_rate_taka); ?>" class="pts-input" required>
                                                <label for="point_conversation_rate_taka"> Taka Purchase </label>
                                            </div>
                                        </div>



                                        <div class="container tbl-group">
                                <div class="full-width-div">Point Redemption Settings</div>
                                <div class="left-width-div">
                                    <label for="point_and_reward">Enable Points Redemption:</label>
                                    <span class="custom-tooltip" tabindex="0" aria-label="Toggle this to enable this feature">
                <span class="tooltip-icon">?</span>
            </span>
                            </div>
                                <div class="right-width-div right-div-ht">
                                <div class="toggle-switch">
                                                                                                    <input type="checkbox" class="toggle" id="point_redemption" name="point_redemption" <?php echo checked($point_redemption, 1); ?>>
                                                    <label class="toggle-slider" for="point_redemption"></label>
                                                </div>
                                                        </div>
                                                        <div class="left-width-div">
                                                        <label for="redemption_conversation_rate_taka">Redemption Conversation Rate:</label>
                                                        <span class="custom-tooltip" tabindex="0" aria-label="Enter Point Redeemption rate">
                <span class="tooltip-icon">?</span>
            </span>
                                                        </div>
                                                        <div class="right-width-div">
                                                        <input type="number" class="pts-input" id="redemption_conversation_rate_point"
                                                    name="redemption_conversation_rate_point"
                                                    value="<?php echo esc_attr($redemption_conversation_rate_point); ?>" <?php if ($point_redemption == 1) {
                                                           echo 'required';
                                                       } else {
                                                           echo '';
                                                       } ?>><label
                                                    for="redemption_conversation_rate_taka"> Point(s)= </label><input type="number"
                                                    class="pts-input" id="redemption_conversation_rate_taka"
                                                    name="redemption_conversation_rate_taka"
                                                    value="<?php echo esc_attr($redemption_conversation_rate_taka); ?>" <?php if ($point_redemption == 1) {
                                                           echo 'required';
                                                       } else {
                                                           echo '';
                                                       } ?>><label
                                                    for="redemption_conversation_rate_taka"> Taka</label>
                                                        </div>
                                                    </div>

                                                    <div class="container tbl-group">
                                                        <div class="full-width-div">Signup Point Setting</div>
                                                            <div class="left-width-div">
                                                                <label for="signup_point">Enable Point Earn by Signup</label>
                                                                    <span class="custom-tooltip" tabindex="0" aria-label="Toggle this to enable this feature">
                                                                        <span class="tooltip-icon">?</span>
                                                                    </span>
                                                            </div>
                                                            <div class="right-width-div right-div-ht">
                                                                <div class="toggle-switch">
                                                                    <input type="checkbox" class="toggle" id="signup_point" name="signup_point" <?php echo checked($signup_point, 1); ?>>
                                                                    <label class="toggle-slider" for="signup_point"></label>
                                                                </div>
                                                            </div>
                                                

                                                            <div class="left-width-div">
                                                        <label for="signup_points_box">Signup Points:</label>
                                                        <span class="custom-tooltip" tabindex="0" aria-label="Customer Signup Bonus Points">
                <span class="tooltip-icon">?</span>
            </span>
                                                        </div>
                                                        <div class="right-width-div">
                                                        <input type="number" class="pts-input" id="signup_points_box"
                                                    name="signup_points_box"
                                                    value="<?php echo esc_attr($signup_points_box); ?>" <?php if ($signup_point == 1) {
                                                           echo 'required';
                                                       } else {
                                                           echo '';
                                                       } ?>><label
                                                    for="signup_points_box"> Point(s) </label>
                                                        </div>

                                                    </div>

            <div class="container tbl-group">
                                                        <div class="full-width-div">Referral Setting</div>
                                                            <div class="left-width-div">
                                                                <label for="ref_system">Enable Referral System</label>
                                                                    <span class="custom-tooltip" tabindex="0" aria-label="Toggle this to enable this feature">
                                                                        <span class="tooltip-icon">?</span>
                                                                    </span>
                                                            </div>
                                                            <div class="right-width-div right-div-ht">
                                                                <div class="toggle-switch">
                                                                    <input type="checkbox" class="toggle" id="ref_system" name="ref_system" <?php echo checked($ref_system, 1); ?>>
                                                                    <label class="toggle-slider" for="ref_system"></label>
                                                                </div>
                                                            </div>
                                                

                                                            <div class="left-width-div">
                                                        <label for="referrer_points_box">Referrer will get:</label>
                                                        <span class="custom-tooltip" tabindex="0" aria-label="Customer Signup Bonus Points">
                <span class="tooltip-icon">?</span>
            </span>
                                                        </div>
                                                        <div class="right-width-div">
                                                        <input type="number" class="pts-input" id="referrer_points_box"
                                                    name="referrer_points_box"
                                                    value="<?php echo esc_attr($referrer_points_box); ?>" <?php if ($ref_system == 1) {
                                                           echo 'required';
                                                       } else {
                                                           echo '';
                                                       } ?>><label
                                                    for="referrer_points_box"> Point(s) </label>
                                                        </div>







                                                        <div class="left-width-div">
                                                        <label for="ref_user_points_box">Referrered user will get:</label>
                                                        <span class="custom-tooltip" tabindex="0" aria-label="Customer Signup Bonus Points">
                <span class="tooltip-icon">?</span>
            </span>
                                                        </div>
                                                        <div class="right-width-div">
                                                        <input type="number" class="pts-input" id="ref_user_points_box"
                                                    name="ref_user_points_box"
                                                    value="<?php echo esc_attr($ref_user_points_box); ?>" <?php if ($ref_system == 1) {
                                                           echo 'required';
                                                       } else {
                                                           echo '';
                                                       } ?>><label
                                                    for="ref_user_points_box"> Point(s) </label>
                                                        </div>

                                                    </div>
                                                    <div class="container tbl-group">
                                <div class="full-width-div">Other Settings</div>

                                <div class="left-width-div">
                                    <label for="total_purchase_point">Display user level in order
                                                    page:</label>
                                                    <span class="custom-tooltip" tabindex="0" aria-label="Toggle this to enable this feature">
                <span class="tooltip-icon">?</span>
            </span>
                            </div>
                                <div class="right-width-div right-div-ht">
                                <div class="toggle-switch">
                                                    <input type="checkbox" class="toggle" id="total_purchase_point" name="total_purchase_point"
                                                        <?php echo checked($total_purchase_point, 1); ?>>
                                                    <label class="toggle-slider" for="total_purchase_point"></label>
                                                </div>
                                                        </div>
                                                        <div class="left-width-div">
                                    <label for="point_and_reward">Enable admin point Adjust:</label>
                                    <span class="custom-tooltip" tabindex="0" aria-label="Toggle this to enable this feature">
                <span class="tooltip-icon">?</span>
            </span>
                            </div>
                            <div class="right-width-div right-div-ht">
                            <div class="toggle-switch">
                                                    <input type="checkbox" class="toggle" id="admin_point_adjust" name="admin_point_adjust"
                                                        <?php echo checked($admin_point_adjust, 1); ?>>
                                                    <label class="toggle-slider" for="admin_point_adjust"></label>
                                                </div>
                                                        </div>

                                                        <div class="left-width-div">
                                    <label for="points_massage">Show points massage in single products in cart and product page:</label>
                                    <span class="custom-tooltip" tabindex="0" aria-label="Toggle this to enable this feature">
                <span class="tooltip-icon">?</span>
            </span>
                            </div>
                            <div class="right-width-div right-div-ht">
                            <div class="toggle-switch">
                                                    <input type="checkbox" class="toggle" id="point_massage" name="point_massage"
                                                        <?php echo checked($point_massage, 1); ?>>
                                                    <label class="toggle-slider" for="point_massage"></label>
                                                </div>
                                                        </div>
                                                    </div>


                                                    <div class="container tbl-group">
                                <div class="full-width-div">Shortcodes</div>
                                <div class="left-width-div"><label for="admin_point_adjust">Point Log Shortcode:</label>
                                <span class="custom-tooltip" tabindex="0" aria-label="Paste [point_log] Shortcode to in a page to view point log.">
                <span class="tooltip-icon">?</span>
            </span>
                            </div>
                                <div class="right-width-div">
                                Create a New Page and add the [point_log] shortcode to display the user point Log.</div>
                                                    </div>
                                        

                                                    <div class="container">
                                <input type="submit" name="save_point_settings" value="Save Settings"
                                                        class="ptn-submit"></div>
                                    

                                <?php wp_nonce_field('save_point_settings', 'point_settings_nonce'); ?>
                            </form>
                        </div>
                        <?php
                        break;

    }
    echo '</div>';
}

// Handle manual points addition form submission
function handle_manual_points_addition()
{
    // Check if the form is submitted and the user has the required capabilities
    if (isset($_POST['action']) && $_POST['action'] === 'add_points_manually' && current_user_can('manage_options')) {
        // Verify the nonce for security
        if (!isset($_POST['add_points_manually_nonce']) || !wp_verify_nonce($_POST['add_points_manually_nonce'], 'add_points_manually')) {
            wp_die('Invalid nonce');
        }

        // Get the submitted data
        $user_id = sanitize_text_field($_POST['user_id']);
        $points = intval($_POST['points']);
        $reason = sanitize_text_field($_POST['reason']);
        $point_source = sanitize_text_field($_POST['point_source']);

        // Save the points to the custom table
        save_points_to_database($user_id, $points, $reason, $point_source);

        // Redirect back to the previous page
        wp_safe_redirect(wp_get_referer());
        exit;
    }
}
add_action('admin_post_add_points_manually', 'handle_manual_points_addition');


// Save points, reason, and point source to the custom table
function save_points_to_database($user_id, $points, $reason = '', $point_source = '')
{
    global $wpdb;

    // Define the table name
    $table_name = $wpdb->prefix . 'point_log';

    // Get the current date and time
    $current_datetime = current_time('mysql');

    // Prepare the data to be inserted
    $data = array(
        'log_date' => $current_datetime,
        'user_id' => $user_id,
        'points' => $points,
        'reason' => $reason,
        'point_source' => $point_source
    );

    // Insert the data into the table
    $wpdb->insert($table_name, $data);
}


// Calculate the points earned for a purchase
function calculate_points_for_purchase($order_id)
{

    // Calculate the points based on the order total or any other logic you have
    $order = wc_get_order($order_id);
    $total_amount = $order->get_total();

    $point_conversation_rate_point = get_option('point_conversation_rate_point', '');
    $point_conversation_rate_taka = get_option('point_conversation_rate_taka', '');

    // Calculate the points based on the total amount spent
    $points = round($total_amount * $point_conversation_rate_point) / $point_conversation_rate_taka;

    // Return the calculated points
    return $points;


}

add_action('woocommerce_order_status_completed', 'handle_points_for_purchase');

/**
 * Function to handle points calculation and saving after a purchase
 *
 * @param int $order_id The ID of the order
 */
function handle_points_for_purchase($order_id)
{
    $point_and_reward = get_option('point_and_reward', 0);
    if ($point_and_reward) {
        // Get the user ID from the order
        $order = wc_get_order($order_id);
        $user_id = $order->get_customer_id();

        // Check if points for this order have already been saved
        $points_saved = get_post_meta($order_id, '_points_saved', true);
        if ($points_saved) {
            return;
        }

        // Calculate the points earned for the purchase
        $points = round(calculate_points_for_purchase($order_id));

        // Save the points to the custom table
        if($points !=0){
            add_point_log_entry($user_id, $points, 'purchase', '', $order_id);
        }
        

        // Mark the points as saved for this order
        update_post_meta($order_id, '_points_saved', true);
    }



}






// Check if points for an order have already been saved
function check_points_saved($order_id)
{
    global $wpdb;

    // Define the table name
    $table_name = $wpdb->prefix . 'point_log';

    // Check if there is a row in the table for this order ID
    $row_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE order_id = %d",
            $order_id
        )
    );

    // Return true if points are already saved, false otherwise
    return $row_exists > 0;
}

// Update the point source column value for a transaction
function update_point_source_column($order_id, $point_source)
{
    global $wpdb;

    // Define the table name
    $table_name = $wpdb->prefix . 'point_log';

    // Update the point source column
    $wpdb->update(
        $table_name,
        array('point_source' => $point_source),
        array('order_id' => $order_id)
    );
}


// Function to handle points deduction and saving after a redemption
function handle_points_for_redemption($user_id, $redeemed_points)
{
    $point_redemption = get_option('point_redemption', 0);
    if ($point_redemption) {
        // Deduct the redeemed points from the user's total points
        deduct_points_from_user($user_id, $redeemed_points);

        // Save the points to the custom table with point_source as 'redeem'
        save_points_to_database($user_id, -$redeemed_points, 'redeem');
    }

}


//======================================================================================
//======================================================================================
/**
 * Add Points Link to WooCommerce My Account Navigation
 */
function add_points_link_to_my_account_menu($items)
{

    $user_id = get_current_user_id();
    $total_points = calculate_total_user_points($user_id);
    $points_label = 'Points';
    // if ($total_points > 0) {
    //     $points_label .= ' (' . $total_points . ')';
    // }
    $items['points'] = $points_label;

    return $items;

}

add_filter('woocommerce_account_menu_items', 'add_points_link_to_my_account_menu', 10, 1);




/**
 * Move points navigation after Account Details in My Account menu
 *
 * @param array $items My Account menu items
 * @return array Modified menu items
 */
function move_points_navigation($items)
{
    // Store the points menu item and remove it from the array
    $points_item = $items['points'];
    unset($items['points']);

    // Find the Account Details menu item position
    $account_details_position = array_search('edit-account', array_keys($items));

    // Insert the points menu item after the Account Details menu item
    $items = array_slice($items, 0, $account_details_position + 1, true) +
        array('points' => $points_item) +
        array_slice($items, $account_details_position + 1, null, true);

    return $items;

}

add_filter('woocommerce_account_menu_items', 'move_points_navigation');




/**
 * Register "points" endpoint for My Account page
 */
function add_points_endpoint()
{
    add_rewrite_endpoint('points', EP_ROOT | EP_PAGES);
}
add_action('init', 'add_points_endpoint');



/**
 * point page log list
 */
function points_page_content()
{
    echo '<h2>Points</h2>';
    $user_id = get_current_user_id();
    $total_points = calculate_total_user_points($user_id);
    echo '<p>Your current points balance: ' . esc_html($total_points) . '</p>';

    echo '<h2>Last 50 Point Logs</h2>';

    // Pagination variables
    $per_page = 50;
    $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Retrieve the user's point log with pagination
    global $wpdb;
    $table_name = $wpdb->prefix . 'point_log';

    // Query to retrieve logs count
    $logs_count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
            $user_id
        )
    );

    $total_pages = ceil($logs_count / $per_page);

    // Retrieve the logs for the current page
    $logs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d ORDER BY log_date DESC LIMIT %d, %d",
            $user_id,
            $offset,
            $per_page
        )
    );
    // Display the point log
    if ($logs) {
        echo '<table>';
        echo '<tr><th>SL</th><th>Point Source</th><th>Date</th><th>Points</th></tr>';
        $serial_number = $offset + 1;
        foreach ($logs as $log) {
            $log_date = strtotime($log->log_date);
            $current_time = current_time('timestamp');
            if (date('Y-m-d', $log_date) === date('Y-m-d', $current_time)) {
                $human_date = human_time_diff($log_date, $current_time) . ' ago';
            } else {
                $human_date = date('j M, Y \a\t g:i A', $log_date);
            }

            $point_source = $log->point_source;
            $log_reason = $log->reason;
            $log_order_id = $log->order_id;
            $my_account_permalink = get_permalink(get_option('woocommerce_myaccount_page_id'));
            $view_order_url = $my_account_permalink . 'view-order/' . $log_order_id . '/';

            if ($point_source === 'purchase') {
                $point_source_text = 'Earned for Purchase <a href="' . $view_order_url . '">#' . $log_order_id . '</a>';
            } elseif ($point_source === 'admin_adjustment') {
                $point_source_text = 'Point Adjusted by Easy';
                if ($log_reason) {
                    $point_source_text = 'for' . $log_reason;
                }
            } elseif ($point_source === 'redeem') {
                $point_source_text = 'Deducted for Redeeming <a href="'.$view_order_url . '">#' . $log_order_id . '</a>';
            } elseif ($point_source === 'signup_bonus'){
                $point_source_text= 'Signup Bonus';
            } elseif ($point_source === 'signup_ref'){
                $point_source_text= 'Referral Bonus';
            } elseif ($point_source === 'ref_signup'){
                $point_source_text= 'Signup Referral Bonus';
            } else {
                $point_source_text = 'Unknown Source';
            }

            echo '<tr>';
            echo '<td>' . esc_html($serial_number . '.') . '</td>';
            echo '<td>' . $point_source_text . '</td>';
            echo '<td>' . esc_html($human_date) . '</td>';
            echo '<td>' . esc_html($log->points) . '</td>';
            echo '</tr>';
            $serial_number++;
        }

        echo '</table>';



        // // Pagination links
        // if ($total_pages > 1) {
        //     echo '<nav class="woocommerce-pagination"><ul class="page-numbers">';
        //     echo paginate_links(
        //         array(
        //             'base' => add_query_arg('paged', '%#%'),
        //             'format' => '',
        //             'total' => $total_pages,
        //             'current' => $current_page,
        //             'prev_text' => '&laquo;',
        //             'next_text' => '&raquo;',
        //         )
        //     );
        //     echo '</ul></nav>';
        // }
    } else {
        echo 'No point log entries found.';
    }

}
add_action('woocommerce_account_points_endpoint', 'points_page_content');



// Custom filter to exclude empty list items from pagination
function custom_exclude_empty_pagination_items($output)
{
    // Remove empty list items from pagination output
    $output = preg_replace('/<li[^>]*><\/li>/', '', $output);

    return $output;

}


//====================== Point Log

/**
 * Load the content for the "points" endpoint
 */
function load_points_endpoint_content()
{
    if (function_exists('wc_get_product')) {
        if (is_wc_endpoint_url('points')) {
            points_page_callback();
            exit; // Prevent other content from being loaded
        }
    }

}

// Add the endpoint and load content only if it hasn't been registered before
if (!array_key_exists('points', $wp_rewrite->endpoints)) {
    add_action('init', 'add_points_endpoint');
    add_action('template_redirect', 'load_points_endpoint_content');
}



/**
 * Callback function for the "points" endpoint
 */
function points_page_callback()
{
    $user_id = get_current_user_id();

    // Retrieve the user's point log
    global $wpdb;
    $table_name = $wpdb->prefix . 'point_log';
    $entries_per_page = 5; // Number of entries to display per page
    $page_number = isset($_GET['page_number']) ? intval($_GET['page_number']) : 1;
    $offset = ($page_number - 1) * $entries_per_page;

    $logs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d ORDER BY log_date DESC LIMIT %d, %d",
            $user_id,
            $offset,
            $entries_per_page
        )
    );


    // Display the point log
    echo '<h2>Point Log</h2>';
    if ($logs) {

        echo '<table>';
        echo '<tr><th>Date</th><th>Points</th><th>Source</th></tr>';
        foreach ($logs as $log) {

            //define the point source
            $point_source = $log->point_source;
            $log_order_id = $log->order_id;
            $my_account_permalink = get_permalink(get_option('woocommerce_myaccount_page_id'));
            $view_order_url = $my_account_permalink . 'view-order/' . $log_order_id . '/';
            if ($point_source === 'purchase') {
                $point_source_text = 'Earned for Purchase';
            } elseif ($point_source === 'admin_adjustment') {
                $point_source_text = 'Point Adjusted by Admin';
            } elseif ($point_source === 'redeem') {
                $point_source_text = 'Deducted for Redeeming <a href="'.$view_order_url . '">#' . $log_order_id . '</a>';
            } elseif ($point_source === 'signup_bonus'){
                $point_source_text= 'Signup Bonus';
            } elseif ($point_source === 'signup_ref'){
                $point_source_text= 'Referral Bonus';
            } elseif ($point_source === 'ref_signup'){
                 $point_source_text= 'Signup Referral Bonus';
            } else {
                $point_source_text = 'Unknown Source';
            }

            //define log reson
            $log_reason = $log->reason;

            if (!empty($log_reason)) {
                $log_reason_text = ' for ' . esc_html($log_reason);
            }

            echo '<tr>';
            echo '<td>' . esc_html($point_source_text) . esc_html($log_reason_text) . '</td>';
            echo '<td>' . esc_html($log->points) . '</td>';
            echo '<td>' . esc_html(get_point_source($log)) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo 'No point log entries found.';
    }

    // Display pagination links
    $total_entries = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
            $user_id
        )
    );
    $total_pages = ceil($total_entries / $entries_per_page);

    if ($total_pages > 1) {
        echo '<nav class="woocommerce-pagination"><ul class="page-numbers">';
        for ($i = 1; $i <= $total_pages; $i++) {
            echo '<a href="?page_number=' . $i . '">' . $i . '</a>';
        }
        echo '</ul></nav>';
    }
}



/**
 * Shortcode to display points earned for a product
 *
 * @param array $atts Shortcode attributes
 * @return string Points earned HTML output
 */
function display_product_points_earned($atts)
{
    $atts = shortcode_atts(
        array(
            'product_id' => '',
        ),
        $atts
    );

    // Retrieve the product ID from the shortcode attribute
    $product_id = $atts['product_id'];

    // Get the product object
    $product = wc_get_product($product_id);

    // Check if the product exists and is purchasable
    if ($product && $product->is_purchasable()) {
        // Retrieve the product price
        $product_price = $product->get_price();

        // Calculate the points earned based on the product price and conversion rate
        $point_conversation_rate_point = get_option('point_conversation_rate_point', '');
        $point_conversation_rate_taka = get_option('point_conversation_rate_taka', '');
        $points_earned = round(($product_price * $point_conversation_rate_point) / $point_conversation_rate_taka);

        // Prepare the HTML output
        $output = '<p class="woocommerce-noreviews">You will earn ' . esc_html($points_earned) . ' on every Product. </p>';

        return $output;
    }

    return ''; // Return empty string if the product doesn't exist or is not purchasable
}
$point_and_reward = get_option('point_and_reward', 0);
if($point_and_reward){
    add_shortcode('product_points_earned', 'display_product_points_earned');
}



/**
 * Function to retrieve the point log entries for a user
 *
 * @param int $user_id The ID of the user
 * @return array The point log entries
 */
function get_user_point_log($user_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'point_log';

    $log_entries = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d ORDER BY log_date DESC",
            $user_id
        ),
        ARRAY_A
    );

    return $log_entries ? $log_entries : array();
}




/**
 * Function to redeem points for a user
 *
 * @param int $user_id The ID of the user
 * @param int $points The points to be redeemed
 */
function redeem_user_points($user_id, $points)
{
    $point_redemption = get_option('point_redemption', 0);
    if ($point_redemption) {
        // Retrieve the user's current points balance
        $current_points = (int) get_user_meta($user_id, 'points', true);

        // Ensure the user has enough points to redeem
        if ($current_points >= $points) {
            // Update the user's points balance
            $updated_points = $current_points - $points;
            update_user_meta($user_id, 'points', $updated_points);

            // Add a point log entry for the redeemed points
            add_point_log_entry($user_id, -$points);
        }
    }
}

/**
 * Function to get the current user's points balance
 *
 * @return int The current user's points balance
 */
function get_current_user_points_balance()
{
    $user_id = get_current_user_id();
    $points = (int) get_user_meta($user_id, 'points', true);
    return $points;
}

/**
 * Calculate the total points for a user
 *
 * @param int $user_id The ID of the user
 * @return int The total points for the user
 */
function calculate_total_user_points($user_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'point_log';

    $total_points = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(points) FROM $table_name WHERE user_id = %d",
            $user_id
        )
    );

    return $total_points ? (int) $total_points : 0;
}


/**
 * Function to display the current user's points balance
 *
 * @param array $atts Shortcode attributes
 * @return string The HTML output for displaying the current user's points balance
 */
function display_current_user_points_balance($atts)
{
    $user_id = get_current_user_id();
    $total_points = calculate_total_user_points($user_id);
    $points_label = 'Points';
    if ($total_points > 0) {
        $points_label .= ' (' . $total_points . ')';
    }
    return $points_label;
}
add_shortcode('current_user_points_balance', 'display_current_user_points_balance');


/**
 * Custom shortcode to display point log on My Account page
 */
function display_point_log_shortcode()
{
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $total_points = calculate_total_user_points($user_id);

        echo '<p>Your current points balance: ' . esc_html($total_points) . '</p>';
        echo '<h2>Last 50 Point Logs</h2>';

        // Pagination variables
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $offset = ($current_page - 1) * $per_page;

        // Retrieve the user's point log with pagination
        global $wpdb;
        $table_name = $wpdb->prefix . 'point_log';

        // Query to retrieve logs count
        $logs_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
                $user_id
            )
        );

        $total_pages = ceil($logs_count / $per_page);

        // Retrieve the logs for the current page
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE user_id = %d ORDER BY log_date DESC LIMIT %d, %d",
                $user_id,
                $offset,
                $per_page
            )
        );

        // Display the point log
        ob_start();
        if ($logs) {
            $point_and_reward = get_option('point_and_reward', 0);
            //echo get_template_directory_uri() . '/reward-point/custom-script.js';
            echo '<table>';
            echo '<tr><th>Point Source</th><th>Date</th><th>Points</th></tr>';
            foreach ($logs as $log) {
                $log_date = strtotime($log->log_date);
                $current_time = current_time('timestamp');

                if (date('Y-m-d', $log_date) === date('Y-m-d', $current_time)) {
                    $human_date = human_time_diff($log_date, $current_time) . ' ago';
                } else {
                    $human_date = date('j M, Y \a\t g:i A', $log_date);
                }

                // Define the point source
                $point_source = $log->point_source;
                $log_reason = $log->reason;
                $log_order_id = $log->order_id;
                $order = wc_get_order($log_order_id);
                $view_order_url = $order ? $order->get_view_order_url() : '#';  // Check if $order exists

                if ($point_source === 'purchase') {
                    $point_source_text = 'Earned for Purchase <a href="' . $view_order_url . '">#' . ($log_order_id ? $log_order_id : '') . '</a>';
                } elseif ($point_source === 'admin_adjustment') {
                    $point_source_text = 'Point Adjusted by Admin ' . $log_reason;
                } elseif ($point_source === 'redeem') {
                    $point_source_text = 'Deducted for Redeeming <a href="' . $view_order_url . '">#' . ($log_order_id ? $log_order_id : '') . '</a>';
                } elseif ($point_source === 'signup_bonus') {
                    $point_source_text = 'Signup Bonus';
                } else {
                    $point_source_text = 'Unknown Source';
                }

                echo '<tr>';
                echo '<td>' . $point_source_text . '</td>';
                echo '<td>' . esc_html($human_date) . '</td>';
                echo '<td>' . esc_html($log->points) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        return ob_get_clean();
    } else {
        return 'Please log in to view the point log.';
    }
}
add_shortcode('point_log', 'display_point_log_shortcode');



/**
 * Display total points earned on the checkout page after the order total
 */
function display_total_points_earned()
{
    // Get the current user ID
    $user_id = get_current_user_id();
    if (!$user_id) {
        return; // Return if the user is not logged in
    }

    // Get the current order total
    $cart_total = floatval(WC()->cart->total);

    // Retrieve the conversion rates
    $point_conversation_rate_point = get_option('point_conversation_rate_point', '');
    $point_conversation_rate_taka = get_option('point_conversation_rate_taka', '');

    // Remove the currency symbol from the cart total
    $cart_total = floatval(str_replace(get_woocommerce_currency_symbol(), '', $cart_total));

    // Calculate the points earned based on the cart total and conversion rates
    $points_earned = round(($cart_total * floatval($point_conversation_rate_point)) / floatval($point_conversation_rate_taka));

    // Display the points earned message after the order total
    echo '<tr class="points-earned"><th>' . __('Total Points will Earn:', 'your-theme-textdomain') . '</th><td>' . esc_html($points_earned) . ' Points </td></tr>';
}
$point_and_reward = get_option('point_and_reward', 0);
if ($point_and_reward) {
    add_action('woocommerce_review_order_after_order_total', 'display_total_points_earned');
}



//========================== Display total points earned in cart totals
/**
 * Display total points earned on the checkout page after the order total
 */
function display_total_points_earned_cart()
{
    // Get the current user ID
    $user_id = get_current_user_id();
    if (!$user_id) {
        return; // Return if the user is not logged in
    }

    // Get the current cart total
    $cart_total = floatval(WC()->cart->total);

    // Retrieve the conversion rates
    $point_conversation_rate_point = get_option('point_conversation_rate_point', '');
    $point_conversation_rate_taka = get_option('point_conversation_rate_taka', '');

    // Remove the currency symbol from the cart total
    $cart_total = floatval(str_replace(get_woocommerce_currency_symbol(), '', $cart_total));

    // Calculate the points earned based on the cart total and conversion rates
    $points_earned = round(($cart_total * floatval($point_conversation_rate_point)) / floatval($point_conversation_rate_taka));

    // Display the points earned message after the order total
    echo '<tr class="points-earned"><th>' . __('Total Points:', 'your-theme-textdomain') . '</th><td>' . esc_html($points_earned) . ' Points </td></tr>';

}
$point_and_reward = get_option('point_and_reward', 0);
if ($point_and_reward) {
    add_action('woocommerce_cart_totals_after_order_total', 'display_total_points_earned_cart');
}


add_filter('woocommerce_get_order_item_totals', 'add_custom_order_totals_row', 30, 3);
function add_custom_order_totals_row($points_total, $order, $tax_display)
{

    $point_conversation_rate_point = get_option('point_conversation_rate_point', '');
    $point_conversation_rate_taka = get_option('point_conversation_rate_taka', '');
    $points = round(($order->get_total() * floatval($point_conversation_rate_point)) / floatval($point_conversation_rate_taka));
    if ($points > 1) {
        $points_earned = round(($order->get_total() * floatval($point_conversation_rate_point)) / floatval($point_conversation_rate_taka)) . ' Points';
    } else {
        $points_earned = round(($order->get_total() * floatval($point_conversation_rate_point)) / floatval($point_conversation_rate_taka)) . ' Point';
    }
    $point_and_reward = get_option('point_and_reward', 0);
    if ($point_and_reward) {
        // Insert a new row
        $points_total['recurr_not'] = array(
            'label' => __('Points will Earn:', 'woocommerce'),
            'value' => $points_earned,
        );

    }
    return $points_total;
}



//================================================ Point Reedemption====================//================================================ Point Reedemption====================


//===============New code for insert a row in the database========================
//================================================================================
/**
 * Function to add a point log entry for a user
 *
 * @param int $user_id The ID of the user
 * @param int $points The points for the log entry (positive for adding, negative for deducting)
 * @param string $point_source The source of the points (e.g., 'redeem', 'purchase', 'admin_adjustment', etc.)
 * @param string $reason The reason for the point adjustment (optional)
 * @param int|null $order_id The ID of the order (optional)
 */
function add_point_log_entry($user_id, $points, $point_source, $reason = '', $order_id = null)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'point_log';

    // Insert the point log entry into the database
    $wpdb->insert(
        $table_name,
        array(
            'user_id' => $user_id,
            'points' => $points,
            'log_date' => current_time('mysql'),
            'point_source' => $point_source,
            'reason' => $reason,
            'order_id' => $order_id,
        )
    );
}



//===============New code for insert a row in the database========================
//================================================================================

// Enqueue your custom script


function display_discount_on_cart($cart) {
    $point_redemption = get_option('point_redemption', 0);
    if ($point_redemption) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Check if the discount amount is set and display the Points Redemption fee
        $discount_amount = 0;
        if (WC()->session->get('points_redemption_discount')) {
            $discount_amount = (float) WC()->session->get('points_redemption_discount');
        }

        // Remove any existing Points Redemption fees
        foreach ($cart->get_fees() as $fee_key => $fee) {
            if ($fee->id === 'points_redemption_discount') {
                $cart->remove_fee($fee_key);
                break;
            }
        }

        // if (is_user_logged_in() && $discount_amount > 0) {
        //     // Add the Points Redemption fee with the amount and remove link
        //     $cart->add_fee(__('Points Redemption', 'your-theme-domain'), -$discount_amount, true, 'points_redemption_discount');
        // }
        if (is_user_logged_in()) {
            // Add the Points Redemption fee with the amount and remove link
            $cart->add_fee(__('Points Redemption', 'your-theme-domain'), -$discount_amount, true, 'points_redemption_discount');
        }
    }
}
add_action('woocommerce_cart_calculate_fees', 'display_discount_on_cart');

// Function to append the remove link to both the cart and checkout pages
function append_remove_link_to_fee($fee_html, $fee) {
    if ($fee->id === 'points_redemption_discount') {
        $remove_link = '<a href="#" class="remove-points">[remove]</a>';
        $fee_html .= ' ' . $remove_link;
    }
    return $fee_html;
}

// Hook into the cart totals fee (cart page)
add_filter('woocommerce_cart_totals_fee_html', 'append_remove_link_to_fee', 10, 2);

// Hook into the order item totals (checkout page)
add_filter('woocommerce_get_order_item_totals', function($total_rows, $order, $tax_display) {
    foreach ($total_rows as $key => $total_row) {
        if ($key === 'fee') {
            $total_rows[$key]['value'] .= ' <a href="#" class="remove-points">[remove]</a>';
        }
    }
    return $total_rows;
}, 10, 3);


function apply_points_redemption()
{
    $point_redemption = get_option('point_redemption', 0);
    if ($point_redemption) {
        if (!is_user_logged_in()) {
            echo json_encode(array('error' => 'User not logged in'));
            exit;
        }

        $user_id = get_current_user_id();
        $points = intval($_POST['points']);

        $current_points = calculate_total_user_points($user_id);

        if ($current_points >= $points) {
            $point_conversation_rate_point = get_option('point_conversation_rate_point', '');
            $point_conversation_rate_taka = get_option('point_conversation_rate_taka', '');
            $redemption_conversation_rate_point = get_option('redemption_conversation_rate_point', '');
            $redemption_conversation_rate_taka = get_option('redemption_conversation_rate_taka', '');
            $point_conversion_rate_taka = $redemption_conversation_rate_taka / $redemption_conversation_rate_point;

            $updated_points = $current_points - $points;
            update_user_meta($user_id, 'points', $updated_points);

            //add_point_log_entry($user_id, -$points, 'redeem');

            // Calculate the discount amount for the current points being applied
            $discount_amount_for_current_points = $points * $point_conversion_rate_taka;

            // Retrieve the total points applied from the front end
            $total_points_applied_so_far = intval($_POST['total_points_applied_so_far']);
            $total_discount_amount = ($total_points_applied_so_far + $points) * $point_conversion_rate_taka;

            $updated_cart_total = WC()->cart->get_cart_contents_total();
            $updated_cart_total = floatval(str_replace(get_woocommerce_currency_symbol(), '', strip_tags($updated_cart_total))) - $discount_amount_for_current_points;

            foreach (WC()->cart->get_fees() as $fee_key => $fee) {
                if ($fee->id === 'points_redemption_discount') {
                    WC()->cart->remove_fee($fee_key);
                    break;
                }
            }

            if ($total_discount_amount >= 0) {
                WC()->cart->add_fee(__('Points Redemption', 'your-theme-domain'), -$total_discount_amount, true, 'points_redemption_discount');
            }

            WC()->session->set('points_redemption_discount', $total_discount_amount);

            $cart_total = WC()->cart->get_cart_contents_total();
            $cart_total = floatval(str_replace(get_woocommerce_currency_symbol(), '', $cart_total));
            $points_earned = round(($updated_cart_total * floatval($point_conversation_rate_point)) / floatval($point_conversation_rate_taka));

            $response = array(
                'success' => true,
                'discount_amount' => wc_price($total_discount_amount),
                'total_amount' => wc_price($updated_cart_total),
                'points_earned' => esc_html($points_earned)
            );

            wp_send_json($response);
        } else {
            $response = array(
                'success' => false,
                'error' => 'Insufficient points for redemption.'
            );
            wp_send_json($response);
        }

        exit;
    }
}


add_action('wp_ajax_apply_points_redemption', 'apply_points_redemption');
add_action('wp_ajax_nopriv_apply_points_redemption', 'apply_points_redemption');


//deduct Points on order recive page
function points_redeem_after_order($order_id)
{
    $user_id = get_current_user_id();
    $order = wc_get_order($order_id);

    $total_points_applied = WC()->session->get('points_redemption_discount');
    if($total_points_applied !=0){
        add_point_log_entry($user_id, -$total_points_applied, 'redeem', '', $order_id);
    }
    
}
add_action('woocommerce_thankyou', 'points_redeem_after_order', 10, 2);


function display_points_redemption_option($discount_amount = 0)
{
    $point_redemption = get_option('point_redemption', 0);
    $user_id = get_current_user_id();
    $total_points = calculate_total_user_points($user_id);
    if ($point_redemption) {
        if (is_user_logged_in() && $total_points>=0) {
            ?>
<tr class="points-redemption">
    <td colspan="6">
        <div class="points-redemption">
            <input type="number" name="points_redemption" id="points_redemption" placeholder="Enter Points" min="0" step="1">
            <button type="button" class="button" id="apply_points_btn">Apply Points</button>
<!-- Spinner (initially hidden) -->
<div id="apply-points-spinner" class="hidden">
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
            </div>

            <!-- White Overlay (initially hidden) -->
            <div id="overlay" class="hidden"></div>
        </div>
    </td>
</tr>



            <?php
        }
    }
}
add_action('woocommerce_cart_totals_after_order_total', 'display_points_redemption_option');


function custom_enqueue_styles()
{
    ?>
    <style type="text/css">
        .points-redemption {
            margin-top: 20px;
        }

        .points-redemption input[type="number"] {
            width: 68%;
            padding: 5px;
        }

        .points-redemption button {
            width: 30%;
            padding: 5px 10px;
            /*background-color: #eeeeee;
                                                                            color: #fff;
                                                                            border: none;
                                                                            cursor: pointer; */
        }

        /* .points-redemption button:hover {
                                                                            background-color: #0052a3;
                                                                        } */
    </style>
    <?php
}
add_action('wp_enqueue_scripts', 'custom_enqueue_styles');

function display_cart_discount()
{
    $point_redemption = get_option('point_redemption', 0);
    if ($point_redemption) {
        $fees = WC()->cart->get_fees();
        foreach ($fees as $fee) {
            if ($fee->name === 'Discount') {
                echo '<tr class="cart-discount">';
                echo '<th>' . esc_html($fee->name) . '</th>';
                echo '<td data-title="' . esc_attr($fee->name) . '">';
                echo wc_price(-$fee->amount);
                echo '</td>';
                echo '</tr>';
            }
        }
    }
}
add_action('woocommerce_cart_totals_after_order_total', 'display_cart_discount');

// Function to update cart totals in the checkout process
function update_cart_totals_in_checkout($cart_object)
{
    $point_redemption = get_option('point_redemption', 0);
    if ($point_redemption) {
        // Get the updated cart total from the custom cart session variable
        $updated_cart_total = WC()->session->get('updated_cart_total');

        // If the updated cart total is set, update the cart total in the checkout process
        if ($updated_cart_total !== null) {
            $cart_object->subtotal = $updated_cart_total;
            $cart_object->total = $updated_cart_total;
            $cart_object->subtotal_ex_tax = $updated_cart_total;
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'update_cart_totals_in_checkout');

// Remove existing "Points Redemption" fees before recalculating cart totals
function remove_existing_points_redemption_fees()
{
    $point_redemption = get_option('point_redemption', 0);
    if ($point_redemption) {
        foreach (WC()->cart->get_fees() as $fee_key => $fee) {
            if ($fee->id === 'points_redemption_discount') {
                WC()->cart->remove_fee($fee_key);
                break;
            }
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'remove_existing_points_redemption_fees');


function update_points_redemption_fee_on_order_received($order_id)
{
    // Get the Order object
    $order = wc_get_order($order_id);
    WC()->session->__unset('points_redemption_discount');

}
add_action('woocommerce_thankyou', 'update_points_redemption_fee_on_order_received', 10, 1);

function modify_thankyou_order_received_text($text, $order)
{
    if (is_user_logged_in()) {
        $cart_total = $order->get_total();
        // Retrieve the conversion rates
        $point_conversation_rate_point = get_option('point_conversation_rate_point', '');
        $point_conversation_rate_taka = get_option('point_conversation_rate_taka', '');

        // Calculate the points earned based on the cart total and conversion rates
        $points_earned = round(($cart_total * floatval($point_conversation_rate_point)) / floatval($point_conversation_rate_taka));
        // Customize the thank you text here
        if($points_earned===0){
            $modified_text = 'Thank you. Your order has been received. You will earn <strong>' . $points_earned . '</strong> points after Completing this order.';
        }else{
            $modified_text = 'Thank you. Your order has been received.'; 
        }
        
        return $modified_text;
    } else {
        $modified_text = 'Thank you. Your order has been received.';
        return $modified_text;
    }

}
$point_and_reward = get_option('point_and_reward', 0);
if ($point_and_reward) {
    add_filter('woocommerce_thankyou_order_received_text', 'modify_thankyou_order_received_text', 10, 2);
}






// ===============================display user points to woocommerce Order Page


function add_custom_order_column($columns)
{
    $columns['user_points'] = __('User Level', 'your-text-domain');
    return $columns;
}
$total_purchase_point = get_option('total_purchase_point', 0);
if ($total_purchase_point == 1) {
    add_filter('manage_edit-shop_order_columns', 'add_custom_order_column');
}

/**
 * Calculate the total purchase points for a user
 *
 * @param int $user_id The ID of the user
 * @return int The total purchase points for the user
 */
function calculate_user_total_purchase_point($user_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'point_log';

    $total_purchase_points = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(points) FROM $table_name WHERE user_id = %d AND point_source = 'purchase'",
            $user_id
        )
    );

    return $total_purchase_points ? (int) $total_purchase_points : 0;
}


function display_user_points_column($column, $post_id)
{
    if ($column === 'user_points') {
        $user_id = get_post_meta($post_id, '_customer_user', true);
        if ($user_id) {
            $user_total_purchase_points = calculate_user_total_purchase_point($user_id);

            // Initialize $user_level as an empty string
            $user_level = '';

            if ($user_total_purchase_points < 999) {
                $user_level = 'New user';
            } elseif ($user_total_purchase_points >= 1000 && $user_total_purchase_points <= 2999) {
                $user_level = '&#9733;'; // One star
            } elseif ($user_total_purchase_points >= 3000 && $user_total_purchase_points <= 4999) {
                $user_level = '&#9733;&#9733;'; // Two stars
            } elseif ($user_total_purchase_points >= 5000 && $user_total_purchase_points <= 7999) {
                $user_level = '&#9733;&#9733;&#9733;'; // Three stars
            } elseif ($user_total_purchase_points >= 8000 && $user_total_purchase_points <= 9999) {
                $user_level = '&#9733;&#9733;&#9733;&#9733;'; // Four stars
            } elseif ($user_total_purchase_points >= 10000) {
                $user_level = '&#9733;&#9733;&#9733;&#9733;&#9733;'; // Five stars
            }

            // Output the user level
            echo '<span class="user-points-badge">' . $user_level . '</span>';
        } else {
            echo 'Unregistered User';
        }
    }
}



add_action('manage_shop_order_posts_custom_column', 'display_user_points_column', 10, 2);



//================================display user points to woocommerce Order Page


//===================user refferel system codes

//===========Signup Bouns =============
$signup_point=get_option('signup_point', 0);
function add_signup_bonus_points($user_id) {
    global $wpdb;
    
    $signup_points_box = get_option('signup_points_box', 0);

    // Insert points into the wp_point_log table
    $wpdb->insert(
        'wp_point_log',
        array(
            'log_date'      => current_time('mysql'),
            'user_id'       => $user_id,
            'points'        => $signup_points_box,
            'point_source'  => 'signup_bonus',
            'reason'        => 'Signup Bonus',
            'order_id'      => null, // No order ID since this is a signup bonus
        )
    );
}
if($signup_point){
    add_action('user_register', 'add_signup_bonus_points');
}


//===========Signup Bouns =============


//============== Referrel system code

$ref_system = get_option('ref_system', 0);
// Generate and store referral code for each user
function generate_referral_code($user_id) {
    $referral_code = 'REF' . strtoupper(substr(md5($user_id . time()), 0, 8)); // Generate a unique code
    update_user_meta($user_id, 'referral_code', $referral_code);
}
if($ref_system){
    add_action('user_register', 'generate_referral_code');
}


// Function to display referral link in the points menu
function display_referral_link_in_points_menu() {
    $user_id = get_current_user_id();
    $referral_code = get_user_meta($user_id, 'referral_code', true);

    if ($referral_code) {
        $referral_link = add_query_arg('referral_code', $referral_code, wp_registration_url());

        echo '<div class="referral-link-section">';
        echo '<h2>Referral Program</h2>';
        echo '<p><strong>Your Referral Code:</strong> ' . esc_html($referral_code) . '</p>';
        echo '<p><strong>Your Referral Link:</strong> <a href="' . esc_url($referral_link) . '" target="_blank">' . esc_url($referral_link) . '</a></p>';
        echo '</div>';
    }
}


// Hook into the points menu to insert the referral link before "Last 20 Point Logs" section
function add_referral_link_to_points_menu() {
    display_referral_link_in_points_menu();
}
if($ref_system){
add_action('woocommerce_account_points_endpoint', 'add_referral_link_to_points_menu', 9);
}

// Add referral code input to registration form
function add_referral_code_input_to_registration() {
    ?>
    <p>
        <label for="referral_code"><?php _e('Referral Code', 'your-textdomain'); ?><br/>
        <input type="text" name="referral_code" id="referral_code" class="input" value="<?php echo isset($_GET['referral_code']) ? esc_attr($_GET['referral_code']) : ''; ?>" size="25" /></label>
    </p>
    <?php
}
if($ref_system){
add_action('register_form', 'add_referral_code_input_to_registration');
}

// Save referral code to user meta upon registration
function save_referral_code_on_registration($user_id) {
    if (isset($_POST['referral_code']) && !empty($_POST['referral_code'])) {
        update_user_meta($user_id, 'referral_code_used', sanitize_text_field($_POST['referral_code']));
    }
}
if($ref_system){
add_action('user_register', 'save_referral_code_on_registration');
}

function apply_referral_bonus_points($user_id) {
    if (isset($_POST['referral_code']) && !empty($_POST['referral_code'])) {
        $referral_code = sanitize_text_field($_POST['referral_code']);
        
        // Find the user who referred the new user
        $referrer_query = new WP_User_Query(array(
            'meta_key' => 'referral_code',
            'meta_value' => $referral_code,
            'number' => 1,
            'fields' => 'ID',
        ));
        
        if (!empty($referrer_query->get_results())) {
            $referrer_id = $referrer_query->get_results()[0];
            $ref_user_points_box = get_option('ref_user_points_box', 0);
            $referrer_points_box = get_option('referrer_points_box', 0);
            // Insert 10 points into wp_point_log for the referrer
            global $wpdb;
            $wpdb->insert(
                'wp_point_log',
                array(
                    'log_date'      => current_time('mysql'),
                    'user_id'       => $referrer_id,
                    'points'        => $referrer_points_box,
                    'point_source'  => 'signup_ref',
                    'reason'        => 'Referral Bonus',
                    'order_id'      => null,
                )
            );

            // Insert 10 points into wp_point_log for the referred user
            $wpdb->insert(
                'wp_point_log',
                array(
                    'log_date'      => current_time('mysql'),
                    'user_id'       => $user_id,
                    'points'        => $ref_user_points_box,
                    'point_source'  => 'ref_signup',
                    'reason'        => 'Signup Referral Bonus',
                    'order_id'      => null,
                )
            );
        }
    }
}
if($ref_system){
add_action('user_register', 'apply_referral_bonus_points');
}

function customize_coupon_message() {
    // Modify the coupon message to include "Have Points?"
    return 'Have a coupon? <a href="#" class="showcoupon">Click here to enter your code</a> Or Have Points? <a href="#" id="showpoints">Click here to add Points</a>';
}
add_filter('woocommerce_checkout_coupon_message', 'customize_coupon_message');



function apply_points_box_on_checkout(){ ?>
        <div class="points-redemption-checkout">
            <input type="number" name="points_redemption" id="points_redemption" placeholder="Enter Points" min="0" step="1">
            <button type="button" class="button" id="apply_points_btn">Apply Points</button>
<!-- Spinner (initially hidden) -->
<div id="apply-points-spinner" class="hidden">
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
                <div></div>
            </div>

            <!-- White Overlay (initially hidden) -->
            <div id="overlay" class="hidden"></div>
        </div>
    
    <?php
}
add_action('woocommerce_review_order_before_payment', 'apply_points_box_on_checkout');


function shop_page_product_text(){
    global $product;

    // Output the shortcode for the current product using its ID
    echo do_shortcode('[product_points_earned product_id="' . $product->get_id() . '"]');
}
$point_massage = get_option('point_massage', 0);
$point_and_reward = get_option('point_and_reward', 0);
if($point_massage && $point_and_reward){
    add_action('woocommerce_after_shop_loop_item_title', 'shop_page_product_text');
    add_action('woocommerce_before_add_to_cart_form', 'shop_page_product_text');
}


?>