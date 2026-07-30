<?php
session_start();

$error_msg = "";
$info_msg = "";
$retained_username = "";

// Check for logout reasons, like being kicked out by another session.
if (isset($_GET['reason']) && $_GET['reason'] === 'concurrent_login') {
    $info_msg = "You have been logged out because your account was accessed from another device.";
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once 'db_connect.php';
} catch (mysqli_sql_exception $e) { die("<div style='font-family: sans-serif; padding: 20px; text-align: center;'><h2>Database Connection Failed</h2><p>Please ensure that <b>MySQL</b> is running in your XAMPP Control Panel.</p></div>"); }

// LOGIN FORM SUBMISSION (single phase: username & password)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $retained_username = htmlspecialchars($username); 

    // STRICT Case-Sensitive Username Check (BINARY)
    // Fetch the 2FA secret in the initial query for efficiency and robustness.
    $stmt = $conn->prepare("SELECT user_id, password_hash, role, full_name, is_head, google_auth_secret, session_token, must_change_password FROM users WHERE BINARY username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $db_password = $user['password_hash'];

        if (password_verify($password, $db_password)) {
            // --- NEW: Check for forced password change ---
            if ($user['must_change_password'] == 1) {
                $_SESSION['force_change_user_id'] = $user['user_id'];
                header("Location: force_change_password.php");
                exit();
            }

            // --- Login proceeds. The single-device policy is now enforced by overwriting the old session token ---
            // and letting the other device's session be invalidated on its next page load (see sidebar.php).

            $user_id = $user['user_id']; // Get user ID for all login paths

            // Check for 2FA secret
            if (!empty($user['google_auth_secret'])) {
                // 2FA is enabled, redirect to verification page
                $_SESSION['2fa_user_id'] = $user_id;
                $_SESSION['2fa_username'] = $username;
                $_SESSION['2fa_role'] = $user['role'];
                $_SESSION['2fa_full_name'] = $user['full_name'];
                $_SESSION['2fa_is_head'] = $user['is_head'];
                header("Location: verify_2fa.php");
                exit();
            } else {
                // No 2FA, log in directly
                // ONE-SESSION-PER-DEPARTMENT SECURITY (Kick out others in the same dept)
                if ($user['role'] !== 'Requestor') {
                    $clr_stmt = $conn->prepare("UPDATE users SET session_token = NULL WHERE role = ? AND user_id != ?");
                    $clr_stmt->bind_param("si", $user['role'], $user_id);
                    $clr_stmt->execute();
                    $clr_stmt->close();
                }
    
                // ONE-ACCOUNT-PER-DEPARTMENT SECURITY (Set active session token)
                $new_session_token = session_id();
                $sess_stmt = $conn->prepare("UPDATE users SET session_token = ? WHERE user_id = ?");
                $sess_stmt->bind_param("si", $new_session_token, $user_id);
                $sess_stmt->execute();
                $sess_stmt->close();
    
                // Log in officially
                $_SESSION['logged_in'] = true;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['is_head'] = $user['is_head']; // Store the 'is_head' status
                header("Location: home.php");
                exit();
            }
        } else {
            $error_msg = "❌ Invalid password. Please try again.";
        }
    } else {
        $error_msg = "❌ Username not found or incorrect capitalization.";
    }
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAAP System - Login</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>

<div class="login-card">
    <h2>System Authentication</h2>
    <div class="info-box">Enter your credentials to access the NAAP Accounting Module.</div>
    <?php if($info_msg): ?> <div class="info-box" style="background: #fffbeb; color: #92400e; border: 1px solid #fde68a;"><?php echo $info_msg; ?></div> <?php endif; ?>
    <?php if($error_msg): ?> <div class="error-box"><?php echo $error_msg; ?></div> <?php endif; ?>

    <form method="POST">
        <div class="input-group">
            <label>Username</label>
            <input type="text" name="username" placeholder="Enter your system username" value="<?php echo $retained_username; ?>" required autofocus style="text-align: left; letter-spacing: normal;">
        </div>
        <div class="input-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="Enter your secure password" required style="text-align: left; letter-spacing: normal;">
        </div>
        <button type="submit" class="btn-submit">Login to System</button>
    </form>
    <a href="index.php" class="back-link">← Back to Landing Page</a>
    <a href="request_reset.php" class="back-link" style="margin-top: 10px;">Forgot Password?</a>
</div>

</body>
</html>
