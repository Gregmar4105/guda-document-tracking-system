<?php
session_start();
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

require_once 'db_connect.php';

$user_id = $_SESSION['user_id'];
$unread_count = 0;

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $unread_count = (int)$row['count'];
}
$stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode(['unread_count' => $unread_count]);
?>