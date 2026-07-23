<?php
session_start();

// Enable exception reporting for mysqli for robust error handling
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once 'db_connect.php';
} catch (mysqli_sql_exception $e) {
    // If the database connection fails, display a user-friendly message and stop execution.
    die("<div style='font-family: sans-serif; padding: 20px; text-align: center;'><h2>Database Connection Failed</h2><p>The system is currently unable to connect to the database. Please try again later or contact an administrator.</p><p><small>Error: " . htmlspecialchars($e->getMessage()) . "</small></p></div>");
}

$error_msg = "";
$success_msg = "";
$retained_username = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $username = trim($_POST['username']);
        $retained_username = htmlspecialchars($username);

        $stmt = $conn->prepare("SELECT user_id, email, full_name FROM users WHERE BINARY username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $user_id = $user['user_id'];
            $user_full_name = $user['full_name'];

            // Check if the required columns exist before attempting the update.
            // This prevents a fatal error if the migration hasn't been run.
            $check_cols_stmt = $conn->query("SHOW COLUMNS FROM `users` LIKE 'password_reset_request'");
            if ($check_cols_stmt->num_rows == 0) {
                $error_msg = "System Error: The password reset feature is not correctly configured in the database. Please contact the administrator and mention 'schema update required for password reset'.";
            } else {
                $update_stmt = $conn->prepare("UPDATE users SET password_reset_request = 1, password_reset_timestamp = NOW() WHERE user_id = ?");
                $update_stmt->bind_param("i", $user_id);
                $update_stmt->execute();
                $success_msg = "A password reset has been requested for your account. Please contact the MIS administrator to receive your temporary password.";
                $update_stmt->close();

                // --- NEW: NOTIFY THE ADMINISTRATOR ---
                // Find the MIS admin to notify
                $admin_stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'Management Information System Office' LIMIT 1");
                $admin_stmt->execute();
                if ($admin_res = $admin_stmt->get_result()) {
                    if ($admin_user = $admin_res->fetch_assoc()) {
                        $admin_id = $admin_user['user_id'];
                        $notif_message = "Password reset requested for user: " . htmlspecialchars($user_full_name) . " (" . htmlspecialchars($username) . "). Please go to System Settings to resolve.";
                        create_notification($conn, $admin_id, $notif_message, "settings.php");
                    }
                }
                $admin_stmt->close();
            }
            $check_cols_stmt->close();
        } else {
            $error_msg = "Username not found. Please ensure you entered it correctly (it is case-sensitive).";
        }
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        error_log("Password Reset Error: " . $e->getMessage()); // Log the actual error for debugging
        $error_msg = "A database error occurred. Please try again or contact an administrator.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAAP System - Request Password Reset</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>
<div class="login-card">
    <h2>Request Password Reset</h2>
    <?php if ($success_msg): ?>
        <div class="info-box" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;"><?php echo $success_msg; ?></div>
        <a href="login.php" class="back-link">← Back to Login</a>
    <?php else: ?>
        <div class="info-box">Enter your username to request a password reset from the administrator.</div>
        <?php if($error_msg): ?> <div class="error-box"><?php echo $error_msg; ?></div> <?php endif; ?>
        <form method="POST">
            <div class="input-group">
                <label>Username</label>
                <input type="text" name="username" placeholder="Enter your system username" value="<?php echo $retained_username; ?>" required autofocus>
            </div>
            <button type="submit" class="btn-submit">Request Reset</button>
        </form>
        <a href="login.php" class="back-link">← Back to Login</a>
    <?php endif; ?>
</div>
</body>
</html>
<?php
// Close the connection at the end of the script
$conn->close();
?>