<?php
// 1. DATABASE CONNECTION

// Set the default timezone to ensure consistency between PHP and MySQL.
date_default_timezone_set('Asia/Manila');

$host = "sql203.infinityfree.com";
$db_user = "if0_42343630";
$db_pass = "AndrioGuda123";
$db_name = "if0_42343630_dts";

$conn = new mysqli($host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) { die("Database Connection Failed: " . $conn->connect_error); }

// Set character set to utf8mb4 to support a wider range of characters and prevent encoding issues.
$conn->set_charset("utf8mb4");

/**
 * Calculates the ARTA deadline for a document, considering weekends and holidays.
 *
 * @param string $submission_date The date the document was submitted (YYYY-MM-DD).
 * @param string $arta_level The ARTA level ('Simple', 'Complex', 'Highly Technical').
 * @param mysqli $conn The database connection object.
 * @return string|null The calculated deadline date (YYYY-MM-DD) or null if ARTA calculation is disabled or level is invalid.
 */
function calculateARTADeadline($submission_date, $arta_level, $conn) {
    // Check if ARTA calculation is enabled in system settings
    $settings_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'setting_rule'");
    $arta_enabled = ($settings_res && $settings_row = $settings_res->fetch_assoc()) ? ($settings_row['setting_value'] === '1') : false;
    $settings_res->close();

    if (!$arta_enabled) {
        return null; // ARTA calculation is disabled
    }

    // Fetch processing days from the database
    $stmt = $conn->prepare("SELECT processing_days FROM arta_levels WHERE level_name = ?");
    $stmt->bind_param("s", $arta_level);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $working_days_required = (int)$result->fetch_assoc()['processing_days'];
    } else {
        return null; // Invalid or non-existent ARTA level
    }
    $stmt->close();

    $current_date = new DateTime($submission_date);
    $days_counted = 0;

    while ($days_counted < $working_days_required) {
        $current_date->modify('+1 day'); // Move to the next day
        $day_of_week = (int)$current_date->format('N'); // 1 (for Monday) through 7 (for Sunday)
        $is_weekend = ($day_of_week == 6 || $day_of_week == 7); // Saturday or Sunday
        $is_holiday = $conn->query("SELECT COUNT(*) FROM holidays WHERE holiday_date = '" . $current_date->format('Y-m-d') . "'")->fetch_row()[0] > 0;

        if (!$is_weekend && !$is_holiday) {
            $days_counted++;
        }
    }
    return $current_date->format('Y-m-d');
}

/**
 * Creates a new notification for a user.
 *
 * @param mysqli $conn The database connection object.
 * @param int $user_id The ID of the user to notify.
 * @param string $message The notification message.
 * @param string $link The URL the notification should link to.
 */
function create_notification($conn, $user_id, $message, $link = '#') {
    if (!$conn || empty($user_id) || empty($message)) return;
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $user_id, $message, $link);
    $stmt->execute();
    $stmt->close();
}

/**
 * Prepares a statement to select users for notification based on department rules.
 *
 * @param mysqli $conn The database connection object.
 * @param string $department The name of the destination department.
 * @return mysqli_stmt|null The prepared statement, or null on error.
 */
function prepare_notification_statement_for_department($conn, $department) {
    if (!$conn || empty($department)) return null;

    // Handle 'HR Office' vs 'Human Resources' data inconsistency.
    $dept_for_query = ($department === 'HR Office') ? 'Human Resources' : $department;

    // NEW: Handle specific head routing, e.g., "Accounting (Head)"
    if (preg_match('/^(.*) \(Head\)$/', $dept_for_query, $matches)) {
        $dept_name = trim($matches[1]);
        // Notify only the head of the specified department.
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE role = ? AND is_head = 1");
        $stmt->bind_param("s", $dept_name);
        return $stmt;
    }

    if ($dept_for_query === 'Human Resources') { // This remains for the specific 'Human Resources' role name
        // HR is a special case; only notify the department head. This is a fallback.
        return $conn->prepare("SELECT user_id FROM users WHERE role = 'Human Resources' AND is_head = 1");
    } else {
        // For all other departments, notify all users.
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE role = ?");
        $stmt->bind_param("s", $dept_for_query);
        return $stmt;
    }
}

/**
 * Formats a UTC timestamp from the database into the application's local timezone ('Asia/Manila').
 *
 * @param string|null $utc_timestamp The timestamp string from the database (e.g., from a TIMESTAMP or DATETIME column).
 * @param string $format The desired output format for the date/time, compatible with PHP's date().
 * @return string The formatted date/time string, or 'N/A' if the input is empty.
 */
function format_db_timestamp($utc_timestamp, $format = 'M d, Y h:i A') {
    if (empty($utc_timestamp)) {
        return 'N/A';
    }
    try {
        $date = new DateTime($utc_timestamp, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format($format);
    } catch (Exception $e) {
        return 'Invalid Date'; // Return a clear error message on failure
    }
}