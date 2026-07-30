<?php
// c:\xampp\htdocs\DocumentTrackingSystem\migrate.php
// This script manages database schema changes. Run it once after any code update
// that introduces new tables or columns.

echo "<h1>NAAP Document System - Database Migrations</h1>";

// 1. DATABASE CONNECTION
$host = "larable-mysql-service-larablenetwork-2db5.f.aivencloud.com";
$db_user = "guda_database";
$db_pass = "password123";
$db_name = "gudaDB";
$port = 20707;

$conn = new mysqli($host, $db_user, $db_pass, $db_name, $port);
if ($conn->connect_error) {
    die("<p style='color: red;'>Database Connection Failed: " . $conn->connect_error . "</p>");
}

echo "<p>Database connected successfully. Checking for migrations...</p>";

// 2. CREATE MIGRATIONS TABLE (if it doesn't exist)
$conn->query("CREATE TABLE IF NOT EXISTS `migrations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `migration_name` VARCHAR(255) NOT NULL UNIQUE,
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

/**
 * Function to apply a migration
 * @param string $name The name of the migration
 * @param array $queries An array of SQL queries to execute
 * @param mysqli $conn The database connection object
 */
function applyMigration($name, $queries, $conn) {
    // Check if migration has already been applied
    $check_stmt = $conn->prepare("SELECT id FROM migrations WHERE migration_name = ?");
    $check_stmt->bind_param("s", $name);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        echo "<p style='color: gray;'>Migration '$name' already applied. Skipping.</p>";
        $check_stmt->close();
        return;
    }
    $check_stmt->close();

    echo "<p style='color: blue;'>Applying migration: '$name'...</p>";
    $conn->begin_transaction();
    try {
        foreach ($queries as $query) {
            if (!$conn->query($query)) {
                throw new Exception("SQL Error in '$name': " . $conn->error . " Query: " . $query);
            }
        }
        // Record migration as applied
        $insert_stmt = $conn->prepare("INSERT INTO migrations (migration_name) VALUES (?)");
        $insert_stmt->bind_param("s", $name);
        $insert_stmt->execute();
        $insert_stmt->close();

        $conn->commit();
        echo "<p style='color: green;'>Migration '$name' applied successfully.</p>";
    } catch (Exception $e) {
        $conn->rollback();
        echo "<p style='color: red;'>Failed to apply migration '$name': " . $e->getMessage() . "</p>";
    }
}

// 3. DEFINE AND APPLY MIGRATIONS
// Migration 1: Initial System Setup (Consolidates all existing schema changes)
applyMigration('initial_system_setup_20240101', [
    // Core Tables
    "CREATE TABLE IF NOT EXISTS `system_settings` (`setting_key` VARCHAR(50) PRIMARY KEY, `setting_value` TEXT)",
    // Populate default system settings if table is empty
    "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('setting_qr', '1')",
    "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('setting_rule', '1')",
    "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('setting_email', '0')",
    "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('setting_audit', '1')",
    "CREATE TABLE IF NOT EXISTS `voucher_types` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(255) NOT NULL, `requirements` JSON, `default_workflow` JSON NULL, `is_active` TINYINT(1) NOT NULL DEFAULT 1)",
    "CREATE TABLE IF NOT EXISTS `holidays` (`id` INT AUTO_INCREMENT PRIMARY KEY, `holiday_date` DATE NOT NULL, `description` VARCHAR(255) NOT NULL, UNIQUE KEY `unique_date` (`holiday_date`))",
    "CREATE TABLE IF NOT EXISTS `departments` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL UNIQUE, `is_signatory` TINYINT(1) NOT NULL DEFAULT 0, `is_active` TINYINT(1) NOT NULL DEFAULT 1)",
    "CREATE TABLE IF NOT EXISTS `job_titles` (`id` INT AUTO_INCREMENT PRIMARY KEY, `department_name` VARCHAR(100) NOT NULL, `title_name` VARCHAR(100) NOT NULL)",
    // Columns for 'vouchers' table
    "ALTER TABLE `vouchers` ADD COLUMN IF NOT EXISTS `document_title` VARCHAR(255) AFTER `requestor_id`",
    "ALTER TABLE `vouchers` ADD COLUMN IF NOT EXISTS `custom_workflow` JSON AFTER `current_stage_index`",
    "ALTER TABLE `vouchers` ADD COLUMN IF NOT EXISTS `workflow_type` VARCHAR(50) DEFAULT 'Approval' AFTER `status`",
    "ALTER TABLE `vouchers` ADD COLUMN IF NOT EXISTS `arta_deadline` DATE NULL DEFAULT NULL AFTER `custom_workflow`",
    "ALTER TABLE `vouchers` ADD COLUMN IF NOT EXISTS `voucher_type_id` INT NULL DEFAULT NULL AFTER `doc_type_id`",
    // Columns for 'document_types' table
    "ALTER TABLE `document_types` ADD COLUMN IF NOT EXISTS `default_workflow` JSON NULL AFTER `arta_level`",
    "ALTER TABLE `document_types` ADD COLUMN IF NOT EXISTS `workflow_type` VARCHAR(50) NOT NULL DEFAULT 'Approval' AFTER `arta_level`",
    "ALTER TABLE `document_types` ADD COLUMN IF NOT EXISTS `final_status_text` VARCHAR(100) NULL AFTER `workflow_type`",
    "ALTER TABLE `document_types` ADD COLUMN IF NOT EXISTS `created_by_user_id` INT NULL AFTER `default_workflow`",
    "ALTER TABLE `document_types` ADD COLUMN IF NOT EXISTS `arta_level` ENUM('Simple', 'Complex', 'Highly Technical') NOT NULL DEFAULT 'Simple' AFTER `name`",
    // Columns for 'users' table
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `is_head` TINYINT(1) NOT NULL DEFAULT 0 AFTER `job_title`"
], $conn);

applyMigration('remove_handler_status_column_20240216', [
    "ALTER TABLE `users` DROP COLUMN IF EXISTS `handler_status`"
], $conn);

// Migration 3: Add database-driven ARTA levels
applyMigration('add_arta_levels_table_20240217', [
    "CREATE TABLE IF NOT EXISTS `arta_levels` (`id` INT AUTO_INCREMENT PRIMARY KEY, `level_name` VARCHAR(50) NOT NULL UNIQUE, `processing_days` INT NOT NULL DEFAULT 3)",
    "INSERT IGNORE INTO `arta_levels` (`level_name`, `processing_days`) VALUES ('Simple', 3), ('Complex', 7), ('Highly Technical', 20)",
    "ALTER TABLE `document_types` CHANGE `arta_level` `arta_level` VARCHAR(50) NOT NULL DEFAULT 'Simple'"
], $conn);

// Migration 4: Add system default financial doc type
applyMigration('add_financial_doc_type_20240218', [
    "ALTER TABLE `document_types` ADD COLUMN IF NOT EXISTS `is_system_default` TINYINT(1) NOT NULL DEFAULT 0",
    "INSERT IGNORE INTO `document_types` (`name`, `arta_level`, `workflow_type`, `is_system_default`, `is_active`) VALUES ('Financial Voucher', 'Complex', 'Approval', 1, 1)"
], $conn);

// Migration 5: Add ARTA level to financial voucher types
applyMigration('add_arta_to_voucher_types_20240219', [
    "ALTER TABLE `voucher_types` ADD COLUMN IF NOT EXISTS `arta_level` VARCHAR(50) NOT NULL DEFAULT 'Complex' AFTER `name`"
], $conn);

// Migration 6: Add session token for concurrent login control
applyMigration('add_session_token_to_users_20240220', [
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `session_token` VARCHAR(255) NULL DEFAULT NULL AFTER `is_head`"
], $conn);

// Migration 7: Add notifications table
applyMigration('add_notifications_table_20240405', [
    "CREATE TABLE IF NOT EXISTS `notifications` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `message` TEXT NOT NULL,
        `link` VARCHAR(255) NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )"
], $conn);

// Migration 8: Add link column to notifications if it was missed
applyMigration('add_link_to_notifications_20240406', [
    "ALTER TABLE `notifications` ADD COLUMN IF NOT EXISTS `link` VARCHAR(255) NULL AFTER `message`"
], $conn);

// Migration 9: Add requirements to document_types table
applyMigration('add_requirements_to_doc_types_20240407', [
    "ALTER TABLE `document_types` ADD COLUMN IF NOT EXISTS `requirements` JSON NULL AFTER `name`"
], $conn);

// Migration 10: Add flag to vouchers for deadline warnings to prevent spam
applyMigration('add_deadline_warning_flag_20240408', [
    "ALTER TABLE `vouchers` ADD COLUMN IF NOT EXISTS `deadline_warning_sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `arta_deadline`"
], $conn);

// Migration 11: Add min/max amount guidelines for DSS on voucher types
applyMigration('add_min_max_to_voucher_types_20240409', [
    "ALTER TABLE `voucher_types` ADD COLUMN IF NOT EXISTS `min_amount` DECIMAL(15, 2) NULL DEFAULT NULL AFTER `default_workflow`",
    "ALTER TABLE `voucher_types` ADD COLUMN IF NOT EXISTS `max_amount` DECIMAL(15, 2) NULL DEFAULT NULL AFTER `min_amount`"
], $conn);

// Migration 12: Add general min/max amount guidelines for DSS
applyMigration('add_general_min_max_settings_20240410', [
    "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('general_min_amount', '')",
    "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('general_max_amount', '')"
], $conn);

// Migration 13: Add 2FA secret to users table for Google Authenticator
applyMigration('add_2fa_secret_to_users_20240521', [
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `google_auth_secret` VARCHAR(255) NULL DEFAULT NULL AFTER `session_token`"
], $conn);

// Migration 14: Standardize 'VPAF' to 'VP for Admin & Finance'
applyMigration('standardize_vpaf_to_vp_for_admin_finance_20240522', [
    // Rename department in 'departments' table
    "UPDATE `departments` SET `name` = 'VP for Admin & Finance' WHERE `name` = 'VPAF'",
    // Ensure the department is marked as a signatory
    "UPDATE `departments` SET `is_signatory` = 1 WHERE `name` = 'VP for Admin & Finance'",
    // Update user roles in 'users' table
    "UPDATE `users` SET `role` = 'VP for Admin & Finance' WHERE `role` = 'VPAF'",
    // Update job titles in 'job_titles' table
    "UPDATE `job_titles` SET `department_name` = 'VP for Admin & Finance' WHERE `department_name` = 'VPAF'",
    // Update custom_workflow in 'vouchers' table for existing documents
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"VPAF\"', '\"VP for Admin & Finance\"') WHERE `custom_workflow` LIKE '%\"VPAF\"%'",
    // Update default_workflow in 'document_types' table
    "UPDATE `document_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"VPAF\"', '\"VP for Admin & Finance\"') WHERE `default_workflow` LIKE '%\"VPAF\"%'",
    // Update default_workflow in 'voucher_types' table
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"VPAF\"', '\"VP for Admin & Finance\"') WHERE `default_workflow` LIKE '%\"VPAF\"%'"
], $conn);

// Migration 15: Standardize 'Disbursing' department name and ensure signatory status
applyMigration('standardize_disbursing_department_20240523', [
    // Rename department in 'departments' table (if there's an old variant like 'Disbursing Office')
    "UPDATE `departments` SET `name` = 'Disbursing' WHERE `name` LIKE 'Disbursing%'",
    // Ensure the department is marked as a signatory
    "UPDATE `departments` SET `is_signatory` = 1 WHERE `name` = 'Disbursing'",
    // Update user roles in 'users' table
    "UPDATE `users` SET `role` = 'Disbursing' WHERE `role` LIKE 'Disbursing%'",
    // Update job titles in 'job_titles' table
    "UPDATE `job_titles` SET `department_name` = 'Disbursing' WHERE `department_name` LIKE 'Disbursing%'",
    // Update custom_workflow in 'vouchers' table for existing documents (replace old names if any)
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Disbursing Office\"', '\"Disbursing\"') WHERE `custom_workflow` LIKE '%\"Disbursing Office\"%'",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Disbursing Dept\"', '\"Disbursing\"') WHERE `custom_workflow` LIKE '%\"Disbursing Dept\"%'"
], $conn);

// Migration 16: Standardize 'Disbursing' in workflow templates and re-check existing vouchers
applyMigration('standardize_disbursing_in_templates_and_vouchers_20240524', [
    // Update default_workflow in 'document_types' table
    "UPDATE `document_types` SET `default_workflow` = REPLACE(default_workflow, '\"Disbursing Office\"', '\"Disbursing\"') WHERE `default_workflow` LIKE '%\"Disbursing Office\"%'",
    "UPDATE `document_types` SET `default_workflow` = REPLACE(default_workflow, '\"Disbursing Dept\"', '\"Disbursing\"') WHERE `default_workflow` LIKE '%\"Disbursing Dept\"%'",
    // Update default_workflow in 'voucher_types' table
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(default_workflow, '\"Disbursing Office\"', '\"Disbursing\"') WHERE `default_workflow` LIKE '%\"Disbursing Office\"%'",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(default_workflow, '\"Disbursing Dept\"', '\"Disbursing\"') WHERE `default_workflow` LIKE '%\"Disbursing Dept\"%'",
    // Re-run update on existing vouchers' custom_workflow to catch any created from old templates
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(custom_workflow, '\"Disbursing Office\"', '\"Disbursing\"') WHERE `custom_workflow` LIKE '%\"Disbursing Office\"%'",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(custom_workflow, '\"Disbursing Dept\"', '\"Disbursing\"') WHERE `custom_workflow` LIKE '%\"Disbursing Dept\"%'"
], $conn);

// Migration 17: Add Data Retention and Archiving Features
applyMigration('add_data_retention_and_archiving_20240525', [
    // Add settings for retention policies
    "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('notification_retention_days', '90')",
    "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('archive_retention_days', '365')",
    // Create archive tables with identical structure to their live counterparts
    "CREATE TABLE IF NOT EXISTS `vouchers_archive` LIKE `vouchers`",
    "CREATE TABLE IF NOT EXISTS `audit_logs_archive` LIKE `audit_logs`"
], $conn);

// Migration 18: Standardize 'VPAA' department name
applyMigration('standardize_vpaa_department_name_20240710', [
    // Rename department in 'departments' table
    "UPDATE `departments` SET `name` = 'VPAA' WHERE `name` LIKE 'VPAA%'",
    // Ensure the department is marked as a signatory
    "UPDATE `departments` SET `is_signatory` = 1 WHERE `name` = 'VPAA'",
    // Update user roles in 'users' table
    "UPDATE `users` SET `role` = 'VPAA' WHERE `role` LIKE 'VPAA%'",
    // Update job titles in 'job_titles' table
    "UPDATE `job_titles` SET `department_name` = 'VPAA' WHERE `department_name` LIKE 'VPAA%'",
    // Update custom_workflow in 'vouchers' table for existing documents
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"VPAA – Vice President for Academic Affairs\"', '\"VPAA\"') WHERE `custom_workflow` LIKE '%\"VPAA – Vice President for Academic Affairs\"%'",
    // Update default_workflow in 'document_types' and 'voucher_types' tables
    "UPDATE `document_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"VPAA – Vice President for Academic Affairs\"', '\"VPAA\"') WHERE `default_workflow` LIKE '%\"VPAA – Vice President for Academic Affairs\"%'",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"VPAA – Vice President for Academic Affairs\"', '\"VPAA\"') WHERE `default_workflow` LIKE '%\"VPAA – Vice President for Academic Affairs\"%'"
], $conn);

// Migration 19: Standardize remaining department names in workflows
applyMigration('standardize_department_names_in_workflows_20240711', [
    // Accounting
    "UPDATE `document_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Accounting\"', '\"Accounting Office\"') WHERE `default_workflow` LIKE '%\"Accounting\"%'",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Accounting\"', '\"Accounting Office\"') WHERE `default_workflow` LIKE '%\"Accounting\"%'",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Accounting\"', '\"Accounting Office\"') WHERE `custom_workflow` LIKE '%\"Accounting\"%'",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Accounting\"', '\"Accounting Office\"') WHERE `custom_workflow` LIKE '%\"Accounting\"%'",

    // Human Resources
    "UPDATE `document_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Human Resources\"', '\"Human Resource Management Services Division\"') WHERE `default_workflow` LIKE '%\"Human Resources\"%'",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Human Resources\"', '\"Human Resource Management Services Division\"') WHERE `default_workflow` LIKE '%\"Human Resources\"%'",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Human Resources\"', '\"Human Resource Management Services Division\"') WHERE `custom_workflow` LIKE '%\"Human Resources\"%'",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Human Resources\"', '\"Human Resource Management Services Division\"') WHERE `custom_workflow` LIKE '%\"Human Resources\"%'",

    // Student Affairs
    "UPDATE `document_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Student Affairs Office\"', '\"Office of Student Affairs\"') WHERE `default_workflow` LIKE '%\"Student Affairs Office\"%'",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Student Affairs Office\"', '\"Office of Student Affairs\"') WHERE `default_workflow` LIKE '%\"Student Affairs Office\"%'",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Student Affairs Office\"', '\"Office of Student Affairs\"') WHERE `custom_workflow` LIKE '%\"Student Affairs Office\"%'",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Student Affairs Office\"', '\"Office of Student Affairs\"') WHERE `custom_workflow` LIKE '%\"Student Affairs Office\"%'",

    // Budgeting
    "UPDATE `document_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Budgeting\"', '\"Budget Office\"') WHERE `default_workflow` LIKE '%\"Budgeting\"%'",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Budgeting\"', '\"Budget Office\"') WHERE `default_workflow` LIKE '%\"Budgeting\"%'",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Budgeting\"', '\"Budget Office\"') WHERE `custom_workflow` LIKE '%\"Budgeting\"%'",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Budgeting\"', '\"Budget Office\"') WHERE `custom_workflow` LIKE '%\"Budgeting\"%'",

    // Research
    "UPDATE `document_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Research & Development\"', '\"Research and Development Center\"') WHERE `default_workflow` LIKE '%\"Research & Development\"%'",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Research & Development\"', '\"Research and Development Center\"') WHERE `default_workflow` LIKE '%\"Research & Development\"%'",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Research & Development\"', '\"Research and Development Center\"') WHERE `custom_workflow` LIKE '%\"Research & Development\"%'",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Research & Development\"', '\"Research and Development Center\"') WHERE `custom_workflow` LIKE '%\"Research & Development\"%'",

    // MIS
    "UPDATE `document_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"MIS\"', '\"Management Information System Office\"') WHERE `default_workflow` LIKE '%\"MIS\"%'",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"MIS\"', '\"Management Information System Office\"') WHERE `default_workflow` LIKE '%\"MIS\"%'",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"MIS\"', '\"Management Information System Office\"') WHERE `custom_workflow` LIKE '%\"MIS\"%'",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"MIS\"', '\"Management Information System Office\"') WHERE `custom_workflow` LIKE '%\"MIS\"%'",

    // Procurement
    "UPDATE `document_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Procurement/Logistics\"', '\"Procurement Unit\"') WHERE `default_workflow` LIKE '%\"Procurement/Logistics\"%'",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Procurement/Logistics\"', '\"Procurement Unit\"') WHERE `default_workflow` LIKE '%\"Procurement/Logistics\"%'",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Procurement/Logistics\"', '\"Procurement Unit\"') WHERE `custom_workflow` LIKE '%\"Procurement/Logistics\"%'",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Procurement/Logistics\"', '\"Procurement Unit\"') WHERE `custom_workflow` LIKE '%\"Procurement/Logistics\"%'",

    // General Services
    "UPDATE `document_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"General Services Office\"', '\"General Services Department\"') WHERE `default_workflow` LIKE '%\"General Services Office\"%'",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"General Services Office\"', '\"General Services Department\"') WHERE `default_workflow` LIKE '%\"General Services Office\"%'",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"General Services Office\"', '\"General Services Department\"') WHERE `custom_workflow` LIKE '%\"General Services Office\"%'",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"General Services Office\"', '\"General Services Department\"') WHERE `custom_workflow` LIKE '%\"General Services Office\"%'"
], $conn);

// Migration 20: Standardize 'Disbursing' to 'Cash Services – Collecting Office'
applyMigration('standardize_disbursing_to_cash_services_20240712', [
    // Rename department in 'departments' table if it exists under the old name
    "UPDATE `departments` SET `name` = 'Cash Services – Collecting Office' WHERE `name` = 'Disbursing'",
    // Ensure the correct department is marked as a signatory
    "UPDATE `departments` SET `is_signatory` = 1 WHERE `name` = 'Cash Services – Collecting Office'",
    // Update user roles
    "UPDATE `users` SET `role` = 'Cash Services – Collecting Office' WHERE `role` = 'Disbursing'",
    // Update job titles
    "UPDATE `job_titles` SET `department_name` = 'Cash Services – Collecting Office' WHERE `department_name` = 'Disbursing'",
    // Update workflows in live and archived tables
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Disbursing\"', '\"Cash Services – Collecting Office\"') WHERE `custom_workflow` LIKE '%\"Disbursing\"%'",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(`custom_workflow`, '\"Disbursing\"', '\"Cash Services – Collecting Office\"') WHERE `custom_workflow` LIKE '%\"Disbursing\"%'",
    "UPDATE `document_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Disbursing\"', '\"Cash Services – Collecting Office\"') WHERE `default_workflow` LIKE '%\"Disbursing\"%'",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(`default_workflow`, '\"Disbursing\"', '\"Cash Services – Collecting Office\"') WHERE `default_workflow` LIKE '%\"Disbursing\"%'"
], $conn);

// Migration 21: Standardize all dash characters to hyphens for data consistency
applyMigration('standardize_dash_characters_to_hyphens_20240713', [
    // Replace en-dashes (–) and em-dashes (—) with a standard hyphen (-)
    "UPDATE `departments` SET `name` = REPLACE(REPLACE(`name`, '–', '-'), '—', '-')",
    "UPDATE `users` SET `role` = REPLACE(REPLACE(`role`, '–', '-'), '—', '-')",
    "UPDATE `job_titles` SET `department_name` = REPLACE(REPLACE(`department_name`, '–', '-'), '—', '-')",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(REPLACE(`custom_workflow`, '–', '-'), '—', '-')",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(REPLACE(`custom_workflow`, '–', '-'), '—', '-')",
    "UPDATE `document_types` SET `default_workflow` = REPLACE(REPLACE(`default_workflow`, '–', '-'), '—', '-')",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(REPLACE(`default_workflow`, '–', '-'), '—', '-')"
], $conn);

// Migration 22: Force standardization of VPAA role name (re-run for safety)
applyMigration('force_standardize_vpaa_role_name_20240712', [
    // This is a more explicit update to catch cases the LIKE operator might have missed due to special characters.
    "UPDATE `users` SET `role` = 'VPAA' WHERE `role` = 'VPAA – Vice President for Academic Affairs'",
    // Also ensure the department exists and is a signatory, just in case a previous migration was missed.
    "INSERT IGNORE INTO `departments` (`name`, `is_signatory`, `is_active`) VALUES ('VPAA', 1, 1)"
], $conn);

// Migration 23: Consolidated Workflow Name Standardization
applyMigration('consolidated_workflow_name_standardization_20240714', [
    // Step 1: Ensure key signatory roles exist in the departments table
    "INSERT IGNORE INTO `departments` (`name`, `is_signatory`, `is_active`) VALUES ('VPAA', 1, 1), ('VP for Admin & Finance', 1, 1), ('Office of the President', 1, 1)",

    // Step 2: Standardize all dash characters to a simple hyphen in the master departments list first.
    "UPDATE `departments` SET `name` = REPLACE(REPLACE(`name`, '–', '-'), '—', '-')",

    // Step 3: Standardize special/abbreviated roles like 'Opres'
    "UPDATE `document_types` SET `default_workflow` = REPLACE(REPLACE(`default_workflow`, 'Opres', 'Office of the President'), 'VPAF', 'VP for Admin & Finance')",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(REPLACE(`default_workflow`, 'Opres', 'Office of the President'), 'VPAF', 'VP for Admin & Finance')",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(REPLACE(`custom_workflow`, 'Opres', 'Office of the President'), 'VPAF', 'VP for Admin & Finance')",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(REPLACE(`custom_workflow`, 'Opres', 'Office of the President'), 'VPAF', 'VP for Admin & Finance')",

    // Step 4: Standardize department names to their official long names
    "UPDATE `document_types` SET `default_workflow` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`default_workflow`, 'Disbursing Office', 'Cash Services - Collecting Office'), 'Disbursing Dept', 'Cash Services - Collecting Office'), 'Disbursing', 'Cash Services - Collecting Office'), 'Accounting', 'Accounting Office'), 'Human Resources', 'Human Resource Management Services Division'), 'Student Affairs Office', 'Office of Student Affairs'), 'Budgeting', 'Budget Office'), 'Research & Development', 'Research and Development Center'), 'VPAA – Vice President for Academic Affairs', 'VPAA')",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`default_workflow`, 'Disbursing Office', 'Cash Services - Collecting Office'), 'Disbursing Dept', 'Cash Services - Collecting Office'), 'Disbursing', 'Cash Services - Collecting Office'), 'Accounting', 'Accounting Office'), 'Human Resources', 'Human Resource Management Services Division'), 'Student Affairs Office', 'Office of Student Affairs'), 'Budgeting', 'Budget Office'), 'Research & Development', 'Research and Development Center'), 'VPAA – Vice President for Academic Affairs', 'VPAA')",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`custom_workflow`, 'Disbursing Office', 'Cash Services - Collecting Office'), 'Disbursing Dept', 'Cash Services - Collecting Office'), 'Disbursing', 'Cash Services - Collecting Office'), 'Accounting', 'Accounting Office'), 'Human Resources', 'Human Resource Management Services Division'), 'Student Affairs Office', 'Office of Student Affairs'), 'Budgeting', 'Budget Office'), 'Research & Development', 'Research and Development Center'), 'VPAA – Vice President for Academic Affairs', 'VPAA')",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`custom_workflow`, 'Disbursing Office', 'Cash Services - Collecting Office'), 'Disbursing Dept', 'Cash Services - Collecting Office'), 'Disbursing', 'Cash Services - Collecting Office'), 'Accounting', 'Accounting Office'), 'Human Resources', 'Human Resource Management Services Division'), 'Student Affairs Office', 'Office of Student Affairs'), 'Budgeting', 'Budget Office'), 'Research & Development', 'Research and Development Center'), 'VPAA – Vice President for Academic Affairs', 'VPAA')",

    "UPDATE `document_types` SET `default_workflow` = REPLACE(REPLACE(`default_workflow`, 'MIS', 'Management Information System Office'), 'Procurement/Logistics', 'Procurement Unit')",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(REPLACE(`default_workflow`, 'MIS', 'Management Information System Office'), 'Procurement/Logistics', 'Procurement Unit')",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(REPLACE(`custom_workflow`, 'MIS', 'Management Information System Office'), 'Procurement/Logistics', 'Procurement Unit')",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(REPLACE(`custom_workflow`, 'MIS', 'Management Information System Office'), 'Procurement/Logistics', 'Procurement Unit')",

    // Step 5: Standardize all dash characters to a simple hyphen across all workflows as a final cleanup
    "UPDATE `document_types` SET `default_workflow` = REPLACE(REPLACE(`default_workflow`, '–', '-'), '—', '-')",
    "UPDATE `voucher_types` SET `default_workflow` = REPLACE(REPLACE(`default_workflow`, '–', '-'), '—', '-')",
    "UPDATE `vouchers` SET `custom_workflow` = REPLACE(REPLACE(`custom_workflow`, '–', '-'), '—', '-')",
    "UPDATE `vouchers_archive` SET `custom_workflow` = REPLACE(REPLACE(`custom_workflow`, '–', '-'), '—', '-')",
], $conn);

// Migration 24: Add Password Reset Feature
applyMigration('add_password_reset_feature_20240715', [
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `password_reset_request` TINYINT(1) NOT NULL DEFAULT 0 AFTER `google_auth_secret`",
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `password_reset_timestamp` DATETIME NULL DEFAULT NULL AFTER `password_reset_request`"
], $conn);

// Migration 25: Add Force Password Change Feature
applyMigration('add_force_password_change_feature_20240716', [
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_reset_timestamp`"
], $conn);

$conn->close();
echo "<p>Migration process complete.</p>";
?>