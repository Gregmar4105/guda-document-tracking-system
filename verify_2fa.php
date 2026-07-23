<?php
session_start();

// If a user isn't in the middle of a 2FA check, redirect them to the login page.
if (!isset($_SESSION['2fa_user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'db_connect.php';
require_once __DIR__ . '/GoogleAuthenticator.php';
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $code = $_POST['code'];
    $user_id = $_SESSION['2fa_user_id'];

    // Fetch the user's secret and session token from the database
    $stmt = $conn->prepare("SELECT google_auth_secret, session_token FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user && !empty($user['google_auth_secret'])) {
        // The strict single-device check is removed.
        // The new session token will overwrite any stale token, and the other session
        // will be invalidated on its next page load via the check in sidebar.php.
        $ga = new GoogleAuthenticator();
        $secret = $user['google_auth_secret'];

        // Verify the code with a 2*30sec clock tolerance
        if ($ga->verifyCode($secret, $code, 2)) {
            // Code is correct, complete the login process.
            
            // ONE-SESSION-PER-DEPARTMENT SECURITY
            if ($_SESSION['2fa_role'] !== 'Requestor') {
                $clr_stmt = $conn->prepare("UPDATE users SET session_token = NULL WHERE role = ? AND user_id != ?");
                $clr_stmt->bind_param("si", $_SESSION['2fa_role'], $user_id);
                $clr_stmt->execute();
                $clr_stmt->close();
            }

            // Set active session token for the current user
            $new_session_token = session_id();
            $sess_stmt = $conn->prepare("UPDATE users SET session_token = ? WHERE user_id = ?");
            $sess_stmt->bind_param("si", $new_session_token, $user_id);
            $sess_stmt->execute();
            $sess_stmt->close();

            // Set final session variables to log the user in
            $_SESSION['logged_in'] = true;
            $_SESSION['username'] = $_SESSION['2fa_username'];
            $_SESSION['role'] = $_SESSION['2fa_role'];
            $_SESSION['full_name'] = $_SESSION['2fa_full_name'];
            $_SESSION['user_id'] = $_SESSION['2fa_user_id'];
            $_SESSION['is_head'] = $_SESSION['2fa_is_head'];

            // Clean up temporary 2FA session variables
            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_username'], $_SESSION['2fa_role'], $_SESSION['2fa_full_name'], $_SESSION['2fa_is_head']);

            header("Location: home.php");
            exit();
        } else {
            $error_msg = "❌ Invalid 2FA code. Please try again.";
        }
    } else {
        $error_msg = "Could not find 2FA configuration for your account.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAAP System - Two-Factor Authentication</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>
<div class="login-card">
    <h2>Two-Factor Authentication</h2>
    <div class="info-box">Enter the 6-digit code from your authenticator app.</div>
    <?php if($error_msg): ?> <div class="error-box"><?php echo $error_msg; ?></div> <?php endif; ?>
    <form method="POST">
        <div class="input-group">
            <label>Authentication Code</label>
            <input type="text" name="code" placeholder="123456" required autofocus pattern="\d{6}" maxlength="6" style="text-align: center; font-size: 1.5rem; letter-spacing: 0.5rem;">
        </div>
        <button type="submit" class="btn-submit">Verify Code</button>
    </form>
    <a href="logout.php" class="back-link">← Cancel and Logout</a>
</div>
</body>
</html>