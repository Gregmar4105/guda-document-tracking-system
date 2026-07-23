<?php
session_start();
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'db_connect.php';

$user_id = $_SESSION['user_id'];
$notifications = [];

// Fetch all notifications for the current user
$stmt = $conn->prepare("SELECT id, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt->close();

// The sidebar will be included here, which needs the $conn to be open to count notifications.

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Notifications - NAAP Document System</title>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo filemtime('sidebar.css'); ?>">
    <link rel="stylesheet" href="notifications.css">
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="container">
        <div class="header">
            <h1>🔔 My Notifications</h1>
            <p>All system alerts and updates related to your documents are shown here.</p>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <h2>Recent Activity</h2>
                <div class="notification-list">
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <p>You have no notifications yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <a href="<?php echo htmlspecialchars($notif['link']); ?>" style="text-decoration: none; color: inherit;">
                                <div class="notification-item" style="<?php echo $notif['is_read'] ? '' : 'background-color: #eef2ff; border-left-color: var(--naap-navy);'; ?>">
                                    <div class="notification-header">
                                        <strong>Document Update</strong>
                                        <span class="notification-timestamp"><?php echo format_db_timestamp($notif['created_at']); ?></span>
                                    </div>
                                    <p class="notification-remarks"><?php echo htmlspecialchars($notif['message']); ?></p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Now that the page has been rendered, mark all notifications as read.
$update_stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$update_stmt->bind_param("i", $user_id);
$update_stmt->execute();
$update_stmt->close();

$conn->close();
?>
</body>
</html>