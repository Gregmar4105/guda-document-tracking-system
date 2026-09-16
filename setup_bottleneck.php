<?php
/**
 * Bottleneck System Setup Script
 * Executes all database migrations and permissions
 * 
 * Run this once to set up the system, then delete it
 */

// Database connection
date_default_timezone_set('Asia/Manila');
$host = "sql203.infinityfree.com";
$db_user = "if0_42343630";
$db_pass = "AndrioGuda123";
$db_name = "if0_42343630_dts";

$conn = new mysqli($host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) { 
    die("❌ Database Connection Failed: " . $conn->connect_error); 
}
$conn->set_charset("utf8mb4");

echo "<pre style='background: #f5f5f5; padding: 20px; font-family: monospace; border-radius: 4px;'>";
echo "🚀 BOTTLENECK SYSTEM SETUP\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Step 1: Create processing_baselines table
echo "Step 1: Creating processing_baselines table...\n";
$sql = "CREATE TABLE IF NOT EXISTS `processing_baselines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department` varchar(100) NOT NULL,
  `document_type` varchar(100) DEFAULT NULL,
  `expected_hours` decimal(10, 2) NOT NULL,
  `warning_threshold_hours` decimal(10, 2) NOT NULL,
  `critical_threshold_hours` decimal(10, 2) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `dept_doctype` (`department`, `document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

if ($conn->query($sql)) {
    echo "  ✅ processing_baselines created\n";
} else {
    echo "  ⚠️  " . $conn->error . "\n";
}

// Step 2: Create bottleneck_alerts table
echo "\nStep 2: Creating bottleneck_alerts table...\n";
$sql = "CREATE TABLE IF NOT EXISTS `bottleneck_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department` varchar(100) NOT NULL,
  `alert_type` enum('AT_RISK', 'CRITICAL', 'RESOLVED') NOT NULL,
  `severity` enum('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') NOT NULL,
  `avg_processing_hours` decimal(10, 2) NOT NULL,
  `delayed_document_count` int(11) NOT NULL,
  `delay_percentage` decimal(5, 2) NOT NULL,
  `pending_documents` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `recommendation` text DEFAULT NULL,
  `management_action` text DEFAULT NULL,
  `action_taken_at` timestamp NULL DEFAULT NULL,
  `action_taken_by_user_id` int(11) DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `dept_idx` (`department`),
  INDEX `created_at_idx` (`created_at`),
  INDEX `alert_type_idx` (`alert_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

if ($conn->query($sql)) {
    echo "  ✅ bottleneck_alerts created\n";
} else {
    echo "  ⚠️  " . $conn->error . "\n";
}

// Step 3: Create processing_metrics_history table
echo "\nStep 3: Creating processing_metrics_history table...\n";
$sql = "CREATE TABLE IF NOT EXISTS `processing_metrics_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department` varchar(100) NOT NULL,
  `measurement_date` date NOT NULL,
  `total_documents` int(11) NOT NULL DEFAULT 0,
  `completed_documents` int(11) NOT NULL DEFAULT 0,
  `pending_documents` int(11) NOT NULL DEFAULT 0,
  `avg_processing_hours` decimal(10, 2) NOT NULL,
  `min_processing_hours` decimal(10, 2) DEFAULT NULL,
  `max_processing_hours` decimal(10, 2) DEFAULT NULL,
  `documents_exceeding_threshold` int(11) DEFAULT 0,
  `delay_percentage` decimal(5, 2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `dept_date` (`department`, `measurement_date`),
  INDEX `dept_idx` (`department`),
  INDEX `date_idx` (`measurement_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

if ($conn->query($sql)) {
    echo "  ✅ processing_metrics_history created\n";
} else {
    echo "  ⚠️  " . $conn->error . "\n";
}

// Step 4: Create analytics_access_log table
echo "\nStep 4: Creating analytics_access_log table...\n";
$sql = "CREATE TABLE IF NOT EXISTS `analytics_access_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_role` varchar(100) DEFAULT NULL,
  `accessed_report` varchar(100) NOT NULL,
  `department_viewed` varchar(100) DEFAULT NULL,
  `filters_used` json DEFAULT NULL,
  `access_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `user_idx` (`user_id`),
  INDEX `timestamp_idx` (`access_timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

if ($conn->query($sql)) {
    echo "  ✅ analytics_access_log created\n";
} else {
    echo "  ⚠️  " . $conn->error . "\n";
}

// Step 5: Insert default baselines
echo "\nStep 5: Inserting default processing baselines...\n";
$sql = "INSERT IGNORE INTO `processing_baselines` 
(`department`, `document_type`, `expected_hours`, `warning_threshold_hours`, `critical_threshold_hours`, `description`)
VALUES
('Accounting Office', NULL, 24, 36, 48, 'Standard accounting review and approval'),
('Budget Office', NULL, 24, 36, 48, 'Budget review and allocation'),
('Cash Services – Collecting Office', NULL, 8, 12, 16, 'Quick cash handling process'),
('Human Resource Management Services Division', NULL, 24, 36, 48, 'HR processing standard'),
('Management Information System Office', NULL, 24, 36, 48, 'IT and system review'),
('VPAA', NULL, 12, 18, 24, 'Executive administrative review'),
('VPAF – Vice President for Administration and Finance', NULL, 12, 18, 24, 'Finance executive review'),
('Registrar\'s Office', NULL, 24, 36, 48, 'Registration and enrollment processing');";

if ($conn->query($sql)) {
    echo "  ✅ Default baselines inserted\n";
} else {
    echo "  ⚠️  " . $conn->error . "\n";
}

// Step 6: Add can_view_analytics column to users
echo "\nStep 6: Adding can_view_analytics permission column...\n";
$sql = "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `can_view_analytics` tinyint(1) DEFAULT 0 COMMENT 'Permission to view bottleneck analytics';";
if ($conn->query($sql)) {
    echo "  ✅ Column added\n";
} else {
    echo "  ⚠️  " . $conn->error . "\n";
}

// Step 7: Grant permissions to MIS and heads
echo "\nStep 7: Granting analytics permissions to MIS and department heads...\n";
$sql = "UPDATE `users` SET `can_view_analytics` = 1 WHERE `role` = 'MIS' OR `is_head` = 1;";
if ($conn->query($sql)) {
    echo "  ✅ Permissions granted (" . $conn->affected_rows . " users updated)\n";
} else {
    echo "  ⚠️  " . $conn->error . "\n";
}

// Step 8: Add performance indexes
echo "\nStep 8: Adding performance indexes...\n";
$sql = "ALTER TABLE `audit_logs` ADD INDEX IF NOT EXISTS `idx_dept_action_date` (`department`, `action_taken`, `created_at`);";
if ($conn->query($sql)) {
    echo "  ✅ Index idx_dept_action_date added\n";
} else {
    echo "  ⚠️  " . $conn->error . "\n";
}

$sql = "ALTER TABLE `audit_logs` ADD INDEX IF NOT EXISTS `idx_voucher_date` (`voucher_code`, `created_at`);";
if ($conn->query($sql)) {
    echo "  ✅ Index idx_voucher_date added\n";
} else {
    echo "  ⚠️  " . $conn->error . "\n";
}

// Step 9: Verify setup
echo "\nStep 9: Verifying setup...\n";
$tables = ['processing_baselines', 'bottleneck_alerts', 'processing_metrics_history', 'analytics_access_log'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "  ✅ Table $table exists\n";
    } else {
        echo "  ❌ Table $table missing\n";
    }
}

// Count users with permission
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE can_view_analytics = 1");
$row = $result->fetch_assoc();
echo "  ✅ " . $row['count'] . " users have analytics access\n";

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ SETUP COMPLETE!\n\n";
echo "Next steps:\n";
echo "1. Delete this file (setup_bottleneck.php)\n";
echo "2. Log in to your system\n";
echo "3. Go to sidebar → 'Processing Analytics'\n";
echo "4. View your bottleneck analysis dashboard\n\n";
echo "The system is now ready to use!\n";
echo "</pre>";

$conn->close();
?>
