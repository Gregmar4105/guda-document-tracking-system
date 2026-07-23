<?php
// purge_notifications.php
// This script should be run by a cron job once per day.
// Example cron command: 0 3 * * * /usr/bin/php /path/to/your/htdocs/purge_notifications.php

echo "<h1>Purging old notifications...</h1>";

require_once 'db_connect.php';

// Get retention period from settings, default to 90 days if not set
$res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'notification_retention_days'");
$retention_days = ($res && $row = $res->fetch_assoc()) ? (int)$row['setting_value'] : 90;

echo "<p>Using a retention period of {$retention_days} days for read notifications.</p>";

$sql = "DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $retention_days);
$stmt->execute();

echo "<p style='color: green;'>Process complete. Purged " . $stmt->affected_rows . " old, read notifications.</p>";

$stmt->close();
$conn->close();