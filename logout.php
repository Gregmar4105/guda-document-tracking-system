<?php
session_start();

// Enable exception reporting for mysqli
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once 'db_connect.php'; // Use the standard database connection
    
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        // Check if the session token in the DB matches the current session_id before clearing it
        $stmt = $conn->prepare("UPDATE users SET session_token = NULL WHERE user_id = ? AND session_token = ?");
        $current_session_id = session_id();
        $stmt->bind_param("is", $user_id, $current_session_id);
        $stmt->execute();
        if ($stmt) {
            $stmt->close();
        }
    }
} catch (mysqli_sql_exception $e) {
    // Database connection failed. Log the error or handle it silently.
    // The user will still be logged out by the code below, which is the most important part.
    error_log("Database operation failed during logout: " . $e->getMessage());
}

// Destroy all session data
session_unset();
session_destroy();

// Redirect back to landing page
header("Location: index.php");
exit();
?>
