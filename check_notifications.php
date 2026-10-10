<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SESSION['logged_in'] ?? false) !== true || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

require_once 'db_connect.php';

$user_id = (int)$_SESSION['user_id'];
$after_id = filter_input(INPUT_GET, 'after_id', FILTER_VALIDATE_INT);
if ($after_id === false || $after_id === null || $after_id < 0) {
    $after_id = 0;
}

try {
    $count_stmt = $conn->prepare('SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = 0');
    if (!$count_stmt) {
        throw new RuntimeException('Could not prepare the unread notification count query.');
    }
    $count_stmt->bind_param('i', $user_id);
    if (!$count_stmt->execute()) {
        throw new RuntimeException('Could not load the unread notification count.');
    }
    $unread_count = (int)$count_stmt->get_result()->fetch_assoc()['unread_count'];
    $count_stmt->close();

    $notifications_stmt = $conn->prepare('
        SELECT id, message, link, is_read, created_at
        FROM notifications
        WHERE user_id = ? AND id > ?
        ORDER BY id ASC
        LIMIT 25
    ');
    if (!$notifications_stmt) {
        throw new RuntimeException('Could not prepare the recent notifications query.');
    }
    $notifications_stmt->bind_param('ii', $user_id, $after_id);
    if (!$notifications_stmt->execute()) {
        throw new RuntimeException('Could not load recent notifications.');
    }
    $result = $notifications_stmt->get_result();
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['is_read'] = (bool)$row['is_read'];
        $notifications[] = $row;
    }
    $notifications_stmt->close();

    $conn->close();
    echo json_encode([
        'unread_count' => $unread_count,
        'notifications' => $notifications,
        'next_after_id' => !empty($notifications) ? $notifications[count($notifications) - 1]['id'] : $after_id
    ]);
} catch (Throwable $error) {
    error_log('Notification polling failed: ' . $error->getMessage());
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Notifications could not be loaded.']);
}
?>