-- Document Processing Bottleneck Detection System
-- Migration file to add necessary database tables and views
-- This enhancement adds workflow performance monitoring to your DSS

-- Table to track processing time baselines per department/document type
-- Allows the system to compare actual times against expected baselines
CREATE TABLE IF NOT EXISTS `processing_baselines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department` varchar(100) NOT NULL,
  `document_type` varchar(100) DEFAULT NULL,
  `expected_hours` decimal(10, 2) NOT NULL,
  `warning_threshold_hours` decimal(10, 2) NOT NULL COMMENT 'When to flag as at-risk',
  `critical_threshold_hours` decimal(10, 2) NOT NULL COMMENT 'When to flag as bottleneck',
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `dept_doctype` (`department`, `document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default processing baselines
-- These can be adjusted based on your organization's workflows
INSERT IGNORE INTO `processing_baselines` 
(`department`, `document_type`, `expected_hours`, `warning_threshold_hours`, `critical_threshold_hours`, `description`)
VALUES
('Accounting Office', NULL, 24, 36, 48, 'Standard accounting review and approval'),
('Budget Office', NULL, 24, 36, 48, 'Budget review and allocation'),
('Cash Services – Collecting Office', NULL, 8, 12, 16, 'Quick cash handling process'),
('Human Resource Management Services Division', NULL, 24, 36, 48, 'HR processing standard'),
('Management Information System Office', NULL, 24, 36, 48, 'IT and system review'),
('VPAA', NULL, 12, 18, 24, 'Executive administrative review'),
('VPAF – Vice President for Administration and Finance', NULL, 12, 18, 24, 'Finance executive review'),
('Registrar\'s Office', NULL, 24, 36, 48, 'Registration and enrollment processing');

-- Table to track bottleneck alerts and management actions
-- Enables monitoring of interventions and their effectiveness
CREATE TABLE IF NOT EXISTS `bottleneck_alerts` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table to track processing time improvements over time
-- Used to measure the impact of management interventions
CREATE TABLE IF NOT EXISTS `processing_metrics_history` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- View to get current bottleneck status across all departments
-- Used for dashboards and reporting
CREATE OR REPLACE VIEW `v_current_bottleneck_status` AS
SELECT 
    al.department,
    COUNT(DISTINCT al.voucher_code) as total_documents,
    COUNT(DISTINCT CASE 
        WHEN al.action_taken IN ('Accepted', 'RETURNED', 'DECLINED', 'AUTO-SKIPPED')
        THEN al.voucher_code 
    END) as completed_documents,
    COUNT(DISTINCT CASE 
        WHEN al.action_taken NOT IN ('Accepted', 'RETURNED', 'DECLINED', 'AUTO-SKIPPED')
        THEN al.voucher_code 
    END) as pending_documents,
    ROUND(AVG(TIMESTAMPDIFF(HOUR, 
        (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
        (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
    )), 2) as avg_stay_hours,
    ROUND((COUNT(DISTINCT CASE 
        WHEN TIMESTAMPDIFF(HOUR, 
            (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
            (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
        ) > 24 THEN al.voucher_code 
    END) / COUNT(DISTINCT al.voucher_code)) * 100, 2) as delay_percentage,
    pb.expected_hours,
    pb.warning_threshold_hours,
    pb.critical_threshold_hours
FROM audit_logs al
LEFT JOIN processing_baselines pb ON al.department = pb.department AND pb.document_type IS NULL
WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY al.department
ORDER BY avg_stay_hours DESC;

-- View to track delays per document as it moves through departments
CREATE OR REPLACE VIEW `v_document_processing_timeline` AS
SELECT 
    al.voucher_code,
    al.department,
    MIN(CASE WHEN al.action_taken = 'Scan-to-Receive' THEN al.created_at END) as received_at,
    MAX(CASE WHEN al.action_taken = 'Accepted' THEN al.created_at END) as completed_at,
    TIMESTAMPDIFF(HOUR,
        MIN(CASE WHEN al.action_taken = 'Scan-to-Receive' THEN al.created_at END),
        MAX(CASE WHEN al.action_taken = 'Accepted' THEN al.created_at END)
    ) as processing_hours,
    CASE 
        WHEN TIMESTAMPDIFF(HOUR,
            MIN(CASE WHEN al.action_taken = 'Scan-to-Receive' THEN al.created_at END),
            MAX(CASE WHEN al.action_taken = 'Accepted' THEN al.created_at END)
        ) > 48 THEN 'CRITICAL'
        WHEN TIMESTAMPDIFF(HOUR,
            MIN(CASE WHEN al.action_taken = 'Scan-to-Receive' THEN al.created_at END),
            MAX(CASE WHEN al.action_taken = 'Accepted' THEN al.created_at END)
        ) > 24 THEN 'HIGH'
        WHEN TIMESTAMPDIFF(HOUR,
            MIN(CASE WHEN al.action_taken = 'Scan-to-Receive' THEN al.created_at END),
            MAX(CASE WHEN al.action_taken = 'Accepted' THEN al.created_at END)
        ) > 12 THEN 'MEDIUM'
        ELSE 'LOW'
    END as delay_level
FROM audit_logs al
WHERE al.action_taken IN ('Scan-to-Receive', 'Accepted')
GROUP BY al.voucher_code, al.department
ORDER BY processing_hours DESC;

-- Table to log RBAC access to analytics (for audit trail)
CREATE TABLE IF NOT EXISTS `analytics_access_log` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Stored procedure to generate bottleneck alerts automatically
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS `sp_generate_bottleneck_alerts`(
    IN p_days INT DEFAULT 30
)
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_department VARCHAR(100);
    DECLARE v_avg_hours DECIMAL(10, 2);
    DECLARE v_delayed_count INT;
    DECLARE v_delay_pct DECIMAL(5, 2);
    DECLARE v_pending INT;
    DECLARE v_severity VARCHAR(20);
    DECLARE v_alert_type VARCHAR(20);
    
    DECLARE dept_cursor CURSOR FOR
        SELECT 
            al.department,
            ROUND(AVG(TIMESTAMPDIFF(HOUR, 
                (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
                (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
            )), 2) as avg_hours,
            COUNT(DISTINCT CASE 
                WHEN TIMESTAMPDIFF(HOUR, 
                    (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
                    (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
                ) > 24 THEN al.voucher_code 
            END) as delayed_count,
            ROUND((COUNT(DISTINCT CASE 
                WHEN TIMESTAMPDIFF(HOUR, 
                    (SELECT MIN(created_at) FROM audit_logs al2 WHERE al2.voucher_code = al.voucher_code AND al2.department = al.department AND al2.action_taken = 'Scan-to-Receive'),
                    (SELECT MAX(created_at) FROM audit_logs al3 WHERE al3.voucher_code = al.voucher_code AND al3.department = al.department AND al3.action_taken = 'Accepted')
                ) > 24 THEN al.voucher_code 
            END) / COUNT(DISTINCT al.voucher_code)) * 100, 2) as delay_pct,
            COUNT(DISTINCT CASE 
                WHEN al.action_taken NOT IN ('Accepted', 'RETURNED', 'DECLINED', 'AUTO-SKIPPED')
                THEN al.voucher_code 
            END) as pending
        FROM audit_logs al
        WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL p_days DAY)
        GROUP BY al.department
        HAVING avg_hours > 24 OR delay_pct > 10;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN dept_cursor;
    read_loop: LOOP
        FETCH dept_cursor INTO v_department, v_avg_hours, v_delayed_count, v_delay_pct, v_pending;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Determine severity
        SET v_severity = 'LOW';
        SET v_alert_type = 'AT_RISK';
        
        IF v_avg_hours > 48 AND v_delay_pct > 30 THEN
            SET v_severity = 'CRITICAL';
            SET v_alert_type = 'CRITICAL';
        ELSEIF v_avg_hours > 36 AND v_delay_pct > 20 THEN
            SET v_severity = 'HIGH';
        ELSEIF v_avg_hours > 24 THEN
            SET v_severity = 'MEDIUM';
        END IF;
        
        -- Insert alert
        INSERT INTO bottleneck_alerts 
        (department, alert_type, severity, avg_processing_hours, delayed_document_count, delay_percentage, pending_documents, created_at)
        VALUES
        (v_department, v_alert_type, v_severity, v_avg_hours, v_delayed_count, v_delay_pct, v_pending, NOW())
        ON DUPLICATE KEY UPDATE updated_at = NOW();
        
    END LOOP;
    CLOSE dept_cursor;
END //

DELIMITER ;

-- Stored procedure to log analytics access for audit trail
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS `sp_log_analytics_access`(
    IN p_user_id INT,
    IN p_user_role VARCHAR(100),
    IN p_report VARCHAR(100),
    IN p_department VARCHAR(100),
    IN p_filters JSON
)
BEGIN
    INSERT INTO analytics_access_log 
    (user_id, user_role, accessed_report, department_viewed, filters_used, access_timestamp)
    VALUES
    (p_user_id, p_user_role, p_report, p_department, p_filters, NOW());
END //

DELIMITER ;

-- Index for performance optimization on audit_logs
ALTER TABLE `audit_logs` ADD INDEX `idx_dept_action_date` (`department`, `action_taken`, `created_at`);
ALTER TABLE `audit_logs` ADD INDEX `idx_voucher_date` (`voucher_code`, `created_at`);

-- Add columns to users table to track analytics access permissions if not present
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `can_view_analytics` tinyint(1) DEFAULT 0 COMMENT 'Permission to view bottleneck analytics';
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `can_edit_baselines` tinyint(1) DEFAULT 0 COMMENT 'Permission to edit processing baselines';

-- Grant analytics permissions to MIS and Admin roles
UPDATE `users` SET `can_view_analytics` = 1 WHERE `role` IN ('MIS', 'Admin');

-- Set default baselines for analytics (can be customized per organization)
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `description`) 
VALUES 
('bottleneck_critical_threshold_pct', '30', 'Percentage of delayed documents to flag as critical'),
('bottleneck_high_threshold_pct', '20', 'Percentage of delayed documents to flag as high risk'),
('bottleneck_warning_threshold_pct', '10', 'Percentage of delayed documents to flag as warning'),
('processing_delay_hours', '24', 'Default processing time threshold in hours to consider a document delayed'),
('bottleneck_detection_enabled', '1', 'Enable or disable bottleneck detection system');

COMMIT;
