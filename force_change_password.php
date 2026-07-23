<?php
session_start();

// If a user isn't in the middle of a forced change, redirect them.
if (!isset($_SESSION['force_change_user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'db_connect.php';
$error_msg = "";
$user_id = $_SESSION['force_change_user_id'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($new_password) || empty($confirm_password)) {
        $error_msg = "Both password fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error_msg = "Passwords do not match. Please try again.";
    } else {
        // Passwords match, proceed with update
        $hash = password_hash($new_password, PASSWORD_DEFAULT);

        $update_stmt = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE user_id = ?");
        $update_stmt->bind_param("si", $hash, $user_id);

        if ($update_stmt->execute()) {
            $update_stmt->close();

            // Password updated successfully. Now, complete the login process.
            // Fetch user details to set up the session.
            $user_stmt = $conn->prepare("SELECT username, role, full_name, is_head FROM users WHERE user_id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user = $user_stmt->get_result()->fetch_assoc();
            $user_stmt->close();

            // ONE-SESSION-PER-DEPARTMENT SECURITY
            if ($user['role'] !== 'Requestor') {
                $clr_stmt = $conn->prepare("UPDATE users SET session_token = NULL WHERE role = ? AND user_id != ?");
                $clr_stmt->bind_param("si", $user['role'], $user_id);
                $clr_stmt->execute();
                $clr_stmt->close();
            }

            // Set active session token for the current user
            $new_session_token = session_id();
            $sess_stmt = $conn->prepare("UPDATE users SET session_token = ? WHERE user_id = ?");
            $sess_stmt->bind_param("si", $new_session_token, $user_id);
            $sess_stmt->execute();
            $sess_stmt->close();

            // Set final session variables
            $_SESSION['logged_in'] = true;
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_id'] = $user_id;
            $_SESSION['is_head'] = $user['is_head'];

            // Clean up temporary session variable
            unset($_SESSION['force_change_user_id']);

            header("Location: home.php");
            exit();
        } else {
            $error_msg = "A database error occurred. Could not update password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAAP System - Change Password</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>
<div class="login-card">
    <h2>Change Your Password</h2>
    <div class="info-box">For security, you must change your temporary password before proceeding.</div>
    <?php if($error_msg): ?> <div class="error-box"><?php echo $error_msg; ?></div> <?php endif; ?>
    <form method="POST">
        <div class="input-group">
            <label>New Password</label>
            <input type="password" name="new_password" placeholder="Enter your new secure password" required autofocus>
        </div>
        <div class="input-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" placeholder="Re-enter your new password" required>
        </div>
        <button type="submit" class="btn-submit">Set New Password</button>
    </form>
    <a href="logout.php" class="back-link">← Cancel and Logout</a>
</div>
</body>
</html>