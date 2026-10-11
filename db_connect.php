<?php
// 1. DATABASE CONNECTION

// Set the default timezone to ensure consistency between PHP and MySQL.
date_default_timezone_set('Asia/Manila');

// Read database configuration from environment variables with fallback to defaults
$host = getenv('DB_HOST') ?: "lc1q06oqxhucdtxiejsvdf66";
$db_user = getenv('DB_USER') ?: "mysql";
$db_pass = getenv('DB_PASSWORD') ?: "guda-mysql";
$db_name = getenv('DB_NAME') ?: "default";
$port = (int)(getenv('DB_PORT') ?: 3306);

$conn = new mysqli($host, $db_user, $db_pass, $db_name, $port);
if ($conn->connect_error) { die("Database Connection Failed: " . $conn->connect_error); }

// Set character set to utf8mb4 to support a wider range of characters and prevent encoding issues.
$conn->set_charset("utf8mb4");

function ensureDocumentTypeApprovalColumn($conn) {
    $result = $conn->query("SHOW COLUMNS FROM `document_types` LIKE 'is_approved'");
    if (!$result) {
        throw new RuntimeException('Could not check document type approval schema: ' . $conn->error);
    }
    if ($result->num_rows === 0) {
        if (!$conn->query("ALTER TABLE `document_types` ADD COLUMN `is_approved` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_active`")) {
            throw new RuntimeException('Could not add document type approval column: ' . $conn->error);
        }
    }
}

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
    $user_id = (int)$user_id;
    if (!$conn || $user_id < 1 || trim((string)$message) === '') {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
    if (!$stmt) {
        error_log('Notification insert preparation failed: ' . $conn->error);
        return false;
    }
    $message = (string)$message;
    $link = (string)$link;
    $stmt->bind_param("iss", $user_id, $message, $link);
    $success = $stmt->execute();
    if (!$success) {
        error_log('Notification insert failed for user ' . $user_id . ': ' . $stmt->error);
    }
    $stmt->close();
    return $success;
}

/**
 * Returns the users assigned to a workflow stage for notification delivery.
 *
 * @param mysqli $conn
 * @param string $stage
 * @param int|null $requestor_id
 * @return array<int>
 */
function get_notification_recipients_for_route($conn, $stage, $requestor_id = null) {
    if (!$conn || !is_string($stage) || trim($stage) === '') {
        return [];
    }

    $stage = trim($stage);
    if (preg_match('/^ACCOUNT:(\d+)$/i', $stage, $account_match)) {
        $user_id = (int)$account_match[1];
        return $user_id > 0 ? [$user_id] : [];
    }

    if ($stage === 'Department Head') {
        $requestor_id = (int)$requestor_id;
        if ($requestor_id < 1) {
            return [];
        }
        $stmt = $conn->prepare('SELECT role FROM users WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            error_log('Could not prepare requestor department lookup for notification: ' . $conn->error);
            return [];
        }
        $stmt->bind_param('i', $requestor_id);
        if (!$stmt->execute()) {
            error_log('Could not load requestor department for notification: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $department = $stmt->get_result()->fetch_assoc()['role'] ?? null;
        $stmt->close();
        if (!$department) {
            return [];
        }
        $stmt = $conn->prepare('SELECT user_id FROM users WHERE role = ? AND is_head = 1');
        if (!$stmt) {
            error_log('Could not prepare department-head notification lookup: ' . $conn->error);
            return [];
        }
        $stmt->bind_param('s', $department);
    } else {
        $stmt = prepare_notification_statement_for_department($conn, $stage);
        if (!$stmt) {
            error_log('Could not prepare routing notification lookup for stage "' . $stage . '".');
            return [];
        }
    }

    if (!$stmt->execute()) {
        error_log('Could not load routing notification recipients for stage "' . $stage . '": ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $recipients = [];
    while ($row = $result->fetch_assoc()) {
        $recipient_id = (int)($row['user_id'] ?? 0);
        if ($recipient_id > 0) {
            $recipients[$recipient_id] = $recipient_id;
        }
    }
    $stmt->close();
    return array_values($recipients);
}

/**
 * Notifies all users assigned to a workflow stage without sending duplicates.
 *
 * @param mysqli $conn
 * @param string $stage
 * @param int|null $requestor_id
 * @param string $message
 * @param string $link
 * @param array<int> $exclude_user_ids
 * @return int Number of notifications successfully created.
 */
function notify_routing_stage($conn, $stage, $requestor_id, $message, $link, $exclude_user_ids = []) {
    $excluded = array_fill_keys(array_map('intval', $exclude_user_ids), true);
    $sent = 0;
    $failed = false;
    foreach (get_notification_recipients_for_route($conn, $stage, $requestor_id) as $recipient_id) {
        if (isset($excluded[$recipient_id])) {
            continue;
        }
        if (create_notification($conn, $recipient_id, $message, $link)) {
            $sent++;
        } else {
            $failed = true;
        }
    }
    return $failed ? 0 : $sent;
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
        if (!$stmt) {
            error_log('Could not prepare department-head notification statement: ' . $conn->error);
            return null;
        }
        $stmt->bind_param("s", $dept_name);
        return $stmt;
    }

    if ($dept_for_query === 'Human Resources') { // This remains for the specific 'Human Resources' role name
        // HR is a special case; only notify the department head. This is a fallback.
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'Human Resources' AND is_head = 1");
    } else {
        // For all other departments, notify all users.
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE role = ?");
        if (!$stmt) {
            error_log('Could not prepare department notification statement: ' . $conn->error);
            return null;
        }
        $stmt->bind_param("s", $dept_for_query);
        return $stmt;
    }
    if (!$stmt) {
        error_log('Could not prepare Human Resources notification statement: ' . $conn->error);
        return null;
    }
    return $stmt;
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

function display_document_title($title) {
    $title = (string)$title;
    return strcasecmp(trim($title), 'Financial Voucher') === 0
        ? 'Disbursement Voucher'
        : $title;
}