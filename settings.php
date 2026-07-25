<?php
session_start();
// ACCESS CONTROL: MIS and Accounting Head can access
$is_mis = ($_SESSION['role'] ?? '') === 'Management Information System Office';
$is_acct_head = (($_SESSION['role'] ?? '') === 'Accounting Office' && ($_SESSION['is_head'] ?? 0) == 1);

if (!isset($_SESSION['logged_in']) || !($is_mis || $is_acct_head)) {
    header("Location: home.php");
    exit();
}

require_once 'db_connect.php';
require_once __DIR__ . '/GoogleAuthenticator.php';
$success_msg = "";
$error_msg = "";

// Check if an MIS admin already exists for UI controls
$mis_exists_stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'Management Information System Office'");
$mis_admin_exists = ($mis_exists_stmt->fetch_assoc()['count'] > 0);
$mis_exists_stmt->close();

// Get current admin user ID and head status to prevent self-deletion and for role checks
$admin_username = $_SESSION['username'];
$admin_id_stmt = $conn->prepare("SELECT user_id, is_head FROM users WHERE username = ?");
$admin_id_stmt->bind_param("s", $admin_username);
$admin_id_stmt->execute();
$admin_user_data = $admin_id_stmt->get_result()->fetch_assoc();
$admin_user_id = $admin_user_data['user_id'];
$current_user_is_head = $admin_user_data['is_head'];
$admin_id_stmt->close();

// --- START: POST Request Handling ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // A. Handle New User Registration
    if (isset($_POST['create_user'])) {
        $new_user = trim($_POST['new_username'] ?? '');
        $new_pass = $_POST['new_password'] ?? '';
        $new_email = trim($_POST['new_email'] ?? '');
        $new_name = trim($_POST['new_fullname'] ?? '');
        $new_title = trim($_POST['new_title'] ?? ''); 
        $new_role = $_POST['new_role'] ?? '';
        $is_head = isset($_POST['is_head']) ? 1 : 0;
        $enable_2fa = isset($_POST['enable_2fa']) ? 1 : 0;
        // Server-side check to enforce single MIS admin rule
        if ($new_role === 'Management Information System Office') {
            $mis_check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'Management Information System Office'");
            $mis_check_stmt->execute();
            if ($mis_check_stmt->get_result()->fetch_assoc()['count'] > 0) {
                $error_msg = "Error: An MIS admin account already exists. Only one is allowed.";
            }
            $mis_check_stmt->close();
        }

        if ($new_role === 'Requestor') {
            $new_dept = 'N/A';
            $new_title = 'Requestor';
        }

        $check_stmt = $conn->prepare("SELECT username FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $new_user);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            if (empty($error_msg)) $error_msg = "Error: Username already exists.";
            $check_stmt->close();
        } else {
            $check_stmt->close();
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);

            $google_auth_secret = null;
            $ga = null; // Initialize
            if ($enable_2fa) {
                $ga = new GoogleAuthenticator();
                $google_auth_secret = $ga->createSecret();
            }

            if (empty($error_msg)) {
                $insert_stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, full_name, email, job_title, is_head, google_auth_secret) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $insert_stmt->bind_param("ssssssis", $new_user, $hash, $new_role, $new_name, $new_email, $new_title, $is_head, $google_auth_secret);
                if ($insert_stmt->execute()) {
                    $new_user_id = $conn->insert_id;
                    $insert_stmt->close();
                    $success_msg = "User account for '$new_name' created successfully.";

                    if ($enable_2fa && $google_auth_secret && $ga) {
                        $show_2fa_modal = true;
                        $new_user_for_modal = $new_user;
                        $new_secret_for_modal = $google_auth_secret;
                        $new_qr_for_modal = $ga->getQRCodeGoogleUrl($new_user, $google_auth_secret, 'NAAP-DTS');
                        $success_msg .= " 2FA has been enabled. Please provide the user with their QR code.";
                    }
                } else {
                    $error_msg = "Database Error: Could not create user.";
                }
            }
        }
    }

    // B. Handle User Deletion
    elseif (isset($_POST['delete_user'])) {
        $user_id_to_delete = $_POST['user_id'];

        // Fetch the role of the user to be deleted to prevent MIS admin deletion
        $role_check_stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
        $role_check_stmt->bind_param("i", $user_id_to_delete);
        $role_check_stmt->execute();
        $user_to_delete = $role_check_stmt->get_result()->fetch_assoc();
        $role_check_stmt->close();

        if ($user_to_delete && $user_to_delete['role'] === 'Management Information System Office') {
            $error_msg = "Error: The primary MIS admin account cannot be deleted.";
        } elseif ($user_id_to_delete == $admin_user_id) {
            $error_msg = "Error: You cannot delete your own account.";
        } else {
            // --- NEW CHECK: Prevent deletion if user has associated records ---
            $can_delete = true;
            
            // Check for associated vouchers
            $check_vouchers_stmt = $conn->prepare("SELECT COUNT(*) FROM vouchers WHERE requestor_id = ?");
            $check_vouchers_stmt->bind_param("i", $user_id_to_delete);
            $check_vouchers_stmt->execute();
            $vouchers_count = $check_vouchers_stmt->get_result()->fetch_row()[0];
            $check_vouchers_stmt->close();

            if ($vouchers_count > 0) {
                $error_msg = "Error: Cannot delete user. This user has submitted " . $vouchers_count . " voucher(s). Associated records must be handled before deletion.";
                $can_delete = false;
            }

            // Check for associated audit logs (only if no vouchers found, to avoid multiple error messages)
            if ($can_delete) {
                $check_audit_stmt = $conn->prepare("SELECT COUNT(*) FROM audit_logs WHERE processed_by_user_id = ?");
                $check_audit_stmt->bind_param("i", $user_id_to_delete);
                $check_audit_stmt->execute();
                $audit_count = $check_audit_stmt->get_result()->fetch_row()[0];
                $check_audit_stmt->close();

                if ($audit_count > 0) {
                    $error_msg = "Error: Cannot delete user. This user has processed " . $audit_count . " audit log entry/entries. Associated records must be handled before deletion.";
                    $can_delete = false;
                }
            }
            // --- END NEW CHECK ---

            if ($can_delete) {
            $delete_stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $delete_stmt->bind_param("i", $user_id_to_delete);
            if ($delete_stmt->execute()) {
                $success_msg = "User account deleted successfully.";
            } else {
                $error_msg = "Database Error: Could not delete user.";
            }
            $delete_stmt->close();
            }
        }
    }

    // C. Handle Document Type Management
    elseif (isset($_POST['add_doc_type'])) {
        $new_name = trim($_POST['doc_type_name']);
        $new_arta = $_POST['doc_type_arta'];
        $new_workflow_type = $_POST['doc_workflow_type'];
        $new_final_status = trim($_POST['doc_final_status'] ?? '');
        $new_workflow_json = $_POST['doc_type_workflow'];

        $insert_type_stmt = $conn->prepare("INSERT INTO document_types (name, arta_level, workflow_type, final_status_text, default_workflow, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        $insert_type_stmt->bind_param("sssss", $new_name, $new_arta, $new_workflow_type, $new_final_status, $new_workflow_json);
        if ($insert_type_stmt->execute()) {
            $success_msg = "Document Type '$new_name' created successfully.";
        } else {
            $error_msg = "Error creating document type: " . $conn->error;
        }
        $insert_type_stmt->close();
    }
    elseif (isset($_POST['delete_single_doc_type'])) {
        $type_id_to_delete = $_POST['doc_type_id'];
        $delete_stmt = $conn->prepare("DELETE FROM document_types WHERE id = ?");
        $delete_stmt->bind_param("i", $type_id_to_delete);
        if ($delete_stmt->execute()) {
            $success_msg = "Document type deleted successfully.";
        } else {
            $error_msg = "Error deleting document type: " . $conn->error;
        }
    }
    elseif (isset($_POST['update_doc_type'])) {
        $type_id = $_POST['doc_type_id'];
        $type_name = trim($_POST['doc_type_name']);
        $type_arta = $_POST['doc_type_arta'];
        $type_workflow_type = $_POST['doc_workflow_type'];
        $type_final_status = trim($_POST['doc_final_status'] ?? '');
        $type_workflow_json = $_POST['doc_type_workflow'];

        $update_type_stmt = $conn->prepare("UPDATE document_types SET name = ?, arta_level = ?, workflow_type = ?, final_status_text = ?, default_workflow = ? WHERE id = ?");
        $update_type_stmt->bind_param("sssssi", $type_name, $type_arta, $type_workflow_type, $type_final_status, $type_workflow_json, $type_id);
        if ($update_type_stmt->execute()) {
            $success_msg = "Document Type '$type_name' updated successfully.";
        } else {
            $error_msg = "Error updating document type: " . $conn->error;
        }
        $update_type_stmt->close();
    }
    elseif (isset($_POST['delete_bulk_doc_types'])) {
        $ids_to_delete = $_POST['doc_type_ids'] ?? [];
        if (isset($_POST['delete_doc_type'])) { // Single delete button
            $ids_to_delete[] = $_POST['doc_type_id'];
        }

        if (!empty($ids_to_delete)) {
            $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
            $types = str_repeat('i', count($ids_to_delete));
            $delete_stmt = $conn->prepare("DELETE FROM document_types WHERE id IN ($placeholders)");
            $delete_stmt->bind_param($types, ...$ids_to_delete);
            $delete_stmt->execute();
            $success_msg = count($ids_to_delete) . " document type(s) deleted successfully.";
        }
    }

    // D. Handle Financial Voucher Type Management
    elseif (isset($_POST['add_voucher_type'])) {
        $name = trim($_POST['voucher_type_name']);
        $req_text = trim($_POST['requirements']);
        $req_array = !empty($req_text) ? array_map('trim', preg_split('/\r\n|\r|\n/', $req_text)) : [];
        $req_json = json_encode($req_array);
        $arta_level = $_POST['voucher_arta_level'];
        $workflow_json = $_POST['voucher_type_workflow'];

        $stmt = $conn->prepare("INSERT INTO voucher_types (name, arta_level, requirements, default_workflow) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $name, $arta_level, $req_json, $workflow_json);
        if ($stmt->execute()) {
            $success_msg = "Financial Voucher Type '$name' created successfully.";
        } else {
            $error_msg = "Error creating voucher type: " . $conn->error;
        }
    }
    elseif (isset($_POST['update_voucher_type'])) {
        $id = $_POST['voucher_type_id'];
        $name = trim($_POST['voucher_type_name']);
        $req_text = trim($_POST['requirements']);
        $req_array = !empty($req_text) ? array_map('trim', preg_split('/\r\n|\r|\n/', $req_text)) : [];
        $req_json = json_encode($req_array);
        $arta_level = $_POST['voucher_arta_level'];
        $workflow_json = $_POST['voucher_type_workflow'];

        $stmt = $conn->prepare("UPDATE voucher_types SET name = ?, arta_level = ?, requirements = ?, default_workflow = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $name, $arta_level, $req_json, $workflow_json, $id);
        if ($stmt->execute()) {
            $success_msg = "Financial Voucher Type '$name' updated successfully.";
        } else {
            $error_msg = "Error updating voucher type: " . $conn->error;
        }
    }
    elseif (isset($_POST['delete_bulk_voucher_types'])) {
        $ids_to_delete = $_POST['voucher_type_ids'] ?? [];
        if (!empty($ids_to_delete)) {
            $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
            $types = str_repeat('i', count($ids_to_delete));
            $delete_stmt = $conn->prepare("DELETE FROM voucher_types WHERE id IN ($placeholders)");
            $delete_stmt->bind_param($types, ...$ids_to_delete);
            if ($delete_stmt->execute()) {
                $success_msg = count($ids_to_delete) . " financial voucher type(s) deleted successfully.";
            } else {
                $error_msg = "Error deleting financial voucher types: " . $conn->error;
            }
            $delete_stmt->close();
        }
    }
    elseif (isset($_POST['delete_voucher_type'])) {
        $id = $_POST['voucher_type_id'];
        $stmt = $conn->prepare("DELETE FROM voucher_types WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $success_msg = "Financial Voucher Type deleted successfully.";
        } else {
            $error_msg = "Error deleting voucher type: " . $conn->error;
        }
    }

    // E. Handle Department & Job Title Management
    elseif (isset($_POST['add_department'])) {
        $dept_name = trim($_POST['department_name']);
        $is_signatory = isset($_POST['is_signatory']) ? 1 : 0;
        if (!empty($dept_name)) {
            // First, check if the department name already exists to prevent a fatal error
            $check_stmt = $conn->prepare("SELECT id FROM departments WHERE name = ?");
            $check_stmt->bind_param("s", $dept_name);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_stmt->close();

            if ($check_result->num_rows > 0) {
                $error_msg = "Error: Department name '$dept_name' already exists.";
            } else {
                // If it doesn't exist, proceed with the insert
                $stmt = $conn->prepare("INSERT INTO departments (name, is_signatory) VALUES (?, ?)");
                $stmt->bind_param("si", $dept_name, $is_signatory);
                if ($stmt->execute()) {
                    $success_msg = "Department '$dept_name' added successfully.";
                } else {
                    $error_msg = "Error adding department: " . $conn->error;
                }
                $stmt->close();
            }
        }
    }
    elseif (isset($_POST['delete_department'])) {
        $dept_id = $_POST['department_id'];
        $stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
        $stmt->bind_param("i", $dept_id);
        if ($stmt->execute()) {
            $success_msg = "Department deleted successfully.";
        } else {
            $error_msg = "Error deleting department.";
        }
        $stmt->close();
    }
    elseif (isset($_POST['add_job_title'])) {
        $dept_name = $_POST['department_name'];
        $title_name = trim($_POST['title_name']);
        if (!empty($dept_name) && !empty($title_name)) {
            $stmt = $conn->prepare("INSERT INTO job_titles (department_name, title_name) VALUES (?, ?)");
            $stmt->bind_param("ss", $dept_name, $title_name);
            $stmt->execute();
            $stmt->close();
        }
    }
    elseif (isset($_POST['delete_job_title'])) {
        $title_id = $_POST['title_id'];
        $stmt = $conn->prepare("DELETE FROM job_titles WHERE id = ?");
        $stmt->bind_param("i", $title_id);
        $stmt->execute();
        $stmt->close();
    }

    // F. Handle Holiday Management
    elseif (isset($_POST['add_holiday'])) {
        $holiday_date = trim($_POST['holiday_date']);
        $description = trim($_POST['description']);
        if (!empty($holiday_date) && !empty($description)) {
            $stmt = $conn->prepare("INSERT INTO holidays (holiday_date, description) VALUES (?, ?)");
            $stmt->bind_param("ss", $holiday_date, $description);
            if ($stmt->execute()) {
                $success_msg = "Holiday '$description' added successfully.";
            } else {
                $error_msg = "Error: Holiday date might already exist or invalid date format.";
            }
            $stmt->close();
        } else {
            $error_msg = "Holiday date and description are required.";
        }
    }
    elseif (isset($_POST['delete_holiday'])) {
        $holiday_id = $_POST['holiday_id'];
        $stmt = $conn->prepare("DELETE FROM holidays WHERE id = ?");
        $stmt->bind_param("i", $holiday_id);
        if ($stmt->execute()) {
            $success_msg = "Holiday deleted successfully.";
        } else {
            $error_msg = "Error deleting holiday.";
        }
        $stmt->close();
    }
    // G. Handle System Settings Update
    elseif (isset($_POST['update_system_settings'])) {
        $setting_qr = isset($_POST['setting_qr']) ? '1' : '0';
        $setting_rule = isset($_POST['setting_rule']) ? '1' : '0';
        $setting_email = isset($_POST['setting_email']) ? '1' : '0';
        $setting_audit = isset($_POST['setting_audit']) ? '1' : '0';

        $conn->begin_transaction();
        try {
            $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('setting_qr', '$setting_qr') ON DUPLICATE KEY UPDATE setting_value = '$setting_qr'");
            $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('setting_rule', '$setting_rule') ON DUPLICATE KEY UPDATE setting_value = '$setting_rule'");
            $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('setting_email', '$setting_email') ON DUPLICATE KEY UPDATE setting_value = '$setting_email'");
            $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('setting_audit', '$setting_audit') ON DUPLICATE KEY UPDATE setting_value = '$setting_audit'");
            $conn->commit();
            $success_msg = "System settings updated successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error updating system settings: " . $e->getMessage();
        }
    }
    // H. Handle General Financial Guidelines
    elseif (isset($_POST['update_financial_guidelines'])) {
        $min_amount = !empty($_POST['general_min_amount']) ? $_POST['general_min_amount'] : '';
        $max_amount = !empty($_POST['general_max_amount']) ? $_POST['general_max_amount'] : '';

        $conn->begin_transaction();
        try {
            $stmt_min = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('general_min_amount', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt_min->bind_param("s", $min_amount);
            $stmt_min->execute();
            $stmt_min->close();

            $stmt_max = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('general_max_amount', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt_max->bind_param("s", $max_amount);
            $stmt_max->execute();
            $stmt_max->close();

            $conn->commit();
            $success_msg = "General financial guidelines updated successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error updating financial guidelines: " . $e->getMessage();
        }
    }
    // I. Handle ARTA Level Management
    elseif (isset($_POST['add_arta_level'])) {
        $level_name = trim($_POST['level_name']);
        $processing_days = (int)$_POST['processing_days'];
        if (!empty($level_name) && $processing_days >= 0) {
            $stmt = $conn->prepare("INSERT INTO arta_levels (level_name, processing_days) VALUES (?, ?)");
            $stmt->bind_param("si", $level_name, $processing_days);
            if (!$stmt->execute()) { $error_msg = "Error: ARTA Level name might already exist."; }
            $stmt->close();
        }
    }
    elseif (isset($_POST['update_arta_level'])) {
        $level_id = (int)$_POST['level_id'];
        $level_name = trim($_POST['level_name']);
        $processing_days = (int)$_POST['processing_days'];
        $stmt = $conn->prepare("UPDATE arta_levels SET level_name = ?, processing_days = ? WHERE id = ?");
        $stmt->bind_param("sii", $level_name, $processing_days, $level_id);
        if (!$stmt->execute()) { $error_msg = "Error updating ARTA Level."; }
        $stmt->close();
    }
    elseif (isset($_POST['delete_arta_level'])) {
        $level_id = (int)$_POST['level_id'];
        $stmt = $conn->prepare("DELETE FROM arta_levels WHERE id = ?");
        $stmt->bind_param("i", $level_id);
        $stmt->execute();
        $stmt->close();
    }
    // J. Handle 2FA Reset
    elseif (isset($_POST['reset_2fa'])) {
        $user_id_to_reset = $_POST['user_id'];
        
        // Fetch user details to display in modal
        $user_stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
        $user_stmt->bind_param("i", $user_id_to_reset);
        $user_stmt->execute();
        $user_to_reset = $user_stmt->get_result()->fetch_assoc();
        $user_stmt->close();

        if ($user_to_reset) {
            $ga = new GoogleAuthenticator();
            $secret = $ga->createSecret();
            
            // Update user's secret in DB
            $update_stmt = $conn->prepare("UPDATE users SET google_auth_secret = ? WHERE user_id = ?");
            $update_stmt->bind_param("si", $secret, $user_id_to_reset);
            
            if ($update_stmt->execute()) {
                // Prepare modal display
                $show_2fa_modal = true;
                $new_user_for_modal = $user_to_reset['username'];
                $new_secret_for_modal = $secret;
                $new_qr_for_modal = $ga->getQRCodeGoogleUrl($user_to_reset['username'], $secret, 'NAAP-DTS');
                $success_msg = "2FA has been reset for user '{$new_user_for_modal}'. Please provide them with the new secret.";
            } else {
                $error_msg = "Database error: Could not reset 2FA secret.";
            }
            $update_stmt->close();
        } else {
            $error_msg = "User not found for 2FA reset.";
        }
    }
    // K. Handle Data Retention Settings
    elseif (isset($_POST['update_retention_settings'])) {
        $notif_days = (int)$_POST['notification_retention_days'];
        $archive_days = (int)$_POST['archive_retention_days'];

        $conn->begin_transaction();
        try {
            $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('notification_retention_days', '$notif_days') ON DUPLICATE KEY UPDATE setting_value = '$notif_days'");
            $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('archive_retention_days', '$archive_days') ON DUPLICATE KEY UPDATE setting_value = '$archive_days'");
            $conn->commit();
            $success_msg = "Data retention policies updated successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error updating retention policies: " . $e->getMessage();
        }
        // This will cause a page reload, which is fine.
    }
    // L. Handle DSS User History Settings
    elseif (isset($_POST['update_dss_history_settings'])) {
        $dss_enabled = isset($_POST['dss_history_enabled']) ? '1' : '0';
        $dss_rejections = (int)$_POST['dss_rejection_count'];
        $dss_submissions = (int)$_POST['dss_submission_count'];

        $conn->begin_transaction();
        try {
            $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('dss_history_enabled', '$dss_enabled') ON DUPLICATE KEY UPDATE setting_value = '$dss_enabled'");
            $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('dss_rejection_count', '$dss_rejections') ON DUPLICATE KEY UPDATE setting_value = '$dss_rejections'");
            $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('dss_submission_count', '$dss_submissions') ON DUPLICATE KEY UPDATE setting_value = '$dss_submissions'");
            
            $conn->commit();
            $success_msg = "Decision Support System (DSS) user history settings updated successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error updating DSS settings: " . $e->getMessage();
        }
        // Force a reload of settings into the $settings array
        $res = $conn->query("SELECT * FROM system_settings");
        while($row = $res->fetch_assoc()) { $settings[$row['setting_key']] = $row['setting_value']; }
    }
    
    // M. Handle Admin Password Reset
    elseif (isset($_POST['reset_password_admin'])) {
        $user_id_to_reset = $_POST['user_id'];
    
        // Generate a random, secure temporary password
        $temp_password = bin2hex(random_bytes(6)); // 12-char hex string
        $hash = password_hash($temp_password, PASSWORD_DEFAULT);
    
        // Update user's password, clear the reset flag, and set the force change flag
        $update_stmt = $conn->prepare("UPDATE users SET password_hash = ?, password_reset_request = 0, password_reset_timestamp = NULL, must_change_password = 1 WHERE user_id = ?");
        $update_stmt->bind_param("si", $hash, $user_id_to_reset);
        
        if ($update_stmt->execute()) {
            // Fetch user's name for the success message
            $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
            $user_stmt->bind_param("i", $user_id_to_reset);
            $user_stmt->execute();
            $user_to_reset = $user_stmt->get_result()->fetch_assoc();
            $user_stmt->close();
    
            $success_msg = "Password for <strong>" . htmlspecialchars($user_to_reset['full_name']) . "</strong> has been reset. Their temporary password is: <strong style='font-family: monospace; background: #eef2ff; padding: 5px 10px; border-radius: 4px; color: #312e81;'>" . $temp_password . "</strong>. Please provide this to them securely.";
        } else {
            $error_msg = "Database error: Could not reset password.";
        }
        $update_stmt->close();
    }
}

// --- END: POST Request Handling ---

$settings = [];
$res = $conn->query("SELECT * FROM system_settings");
while($row = $res->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Fetch all users for management
$all_users = [];
$users_res = $conn->query("SELECT * FROM users ORDER BY role, full_name");
while($user_row = $users_res->fetch_assoc()) {
    $all_users[] = $user_row;
}

// Fetch all document types for management
$all_doc_types = [];
$doc_types_res = $conn->query("SELECT * FROM document_types ORDER BY name ASC");
while($doc_type_row = $doc_types_res->fetch_assoc()) {
    $all_doc_types[] = $doc_type_row;
}

// Fetch all financial voucher types for management
$all_voucher_types = [];
$voucher_types_res = $conn->query("SELECT * FROM voucher_types ORDER BY name ASC");
while($voucher_type_row = $voucher_types_res->fetch_assoc()) {
    $all_voucher_types[] = $voucher_type_row;
}

// Fetch all departments for dropdowns
$all_departments = [];
$dept_res = $conn->query("SELECT id, name, is_signatory FROM departments WHERE is_active = 1 ORDER BY name ASC");
while($dept_row = $dept_res->fetch_assoc()) {
    $all_departments[] = $dept_row;
}

// Define department names array for use in various dropdowns
$department_names = array_column($all_departments, 'name');

// Fetch all holidays for management
$all_holidays = [];
$holidays_res = $conn->query("SELECT id, holiday_date, description FROM holidays ORDER BY holiday_date ASC");
while($holiday_row = $holidays_res->fetch_assoc()) {
    $all_holidays[] = $holiday_row;
}

// Fetch all ARTA levels for management
$all_arta_levels = [];
$arta_res = $conn->query("SELECT * FROM arta_levels ORDER BY processing_days ASC");
while($arta_row = $arta_res->fetch_assoc()) {
    $all_arta_levels[] = $arta_row;
}

// Fetch departments and their job titles for the JS dropdown
$job_titles_by_dept = [];
$job_titles_for_js_dropdown = []; // FIX: Initialize variable for JS formatting
$jt_res = $conn->query("SELECT id, department_name, title_name FROM job_titles ORDER BY department_name, title_name");
while ($jt_row = $jt_res->fetch_assoc()) {
    $job_titles_by_dept[$jt_row['department_name']][] = $jt_row;
    // FIX: Populate the simple array format expected by the JS
    $job_titles_for_js_dropdown[$jt_row['department_name']][] = $jt_row['title_name']; 
}

// Combine departments with their job titles for the new management panel
// FIX: Removed reference (&$dept) to prevent the "Double VPAA / Missing VPAF" UI bug
foreach ($all_departments as $key => $dept) { 
    $all_departments[$key]['job_titles'] = $job_titles_by_dept[$dept['name']] ?? []; 
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Administration - NAAP</title>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo filemtime('sidebar.css'); ?>">
    <link rel="stylesheet" href="settings.css">
    <style>
        .user-name .two-fa-badge {
            font-size: 0.7rem;
            background: #10b981;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-weight: bold;
            vertical-align: middle;
            margin-left: 8px;
        }
        .btn-reset-2fa {
            background-color: #f59e0b; /* amber-500 */
            color: white;
        }
        .btn-reset-2fa:hover {
            background-color: #d97706; /* amber-600 */
        }
        .qr-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .qr-modal-content {
            background: white; padding: 30px; border-radius: 8px; text-align: center; max-width: 400px; width: 90%;
        }
        .qr-modal-content h2 { color: var(--naap-navy); margin-top: 0; }
        .qr-modal-content .secret-code {
            font-family: monospace; background: #eef2ff; padding: 10px; border-radius: 4px; font-size: 1.2rem;
            letter-spacing: 2px; margin: 15px 0; color: #312e81; border: 1px solid #c7d2fe;
        }
        .qr-modal-content .btn-close-modal {
            margin-top: 20px; padding: 10px 20px; background: var(--naap-navy); color: white;
            border: none; border-radius: 5px; cursor: pointer;
        }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="container">
        <div class="page-header">
            <h1>System Administration</h1>
            <p>Institutional configuration, user management, and workflow protocols.</p>
            <?php if ($is_acct_head && !$is_mis): ?><p style="color: var(--naap-gold); font-weight: bold;">Limited view: Managing Financial Voucher Types only.</p><?php endif; ?>
        </div>

        <?php if($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>
        <?php if($error_msg): ?> <div class="alert alert-error"><?php echo $error_msg; ?></div> <?php endif; ?>

        <?php if ($is_mis || $is_acct_head): // This is visible to both MIS and Acct Head ?>
        <div class="settings-section">
            <h2 class="section-heading">Financial Guidelines</h2>
            <div class="card">
                <h3 class="card-title">General Voucher Amount Guidelines</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: -20px; margin-bottom: 25px;">Set system-wide minimum and maximum amount thresholds. Vouchers outside these guidelines will be flagged by the Decision Support System (DSS) for review.</p>
                <form method="POST">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="input-group">
                            <label>General Minimum Amount (Optional)</label>
                            <input type="number" name="general_min_amount" step="0.01" placeholder="e.g., 100.00" value="<?php echo htmlspecialchars($settings['general_min_amount'] ?? ''); ?>">
                        </div>
                        <div class="input-group">
                            <label>General Maximum Amount (Optional)</label>
                            <input type="number" name="general_max_amount" step="0.01" placeholder="e.g., 1000000.00" value="<?php echo htmlspecialchars($settings['general_max_amount'] ?? ''); ?>">
                        </div>
                    </div>
                    <button type="submit" name="update_financial_guidelines" class="btn" style="margin-top: 10px;">Save Guidelines</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_mis): ?>
            <div class="settings-section">
                <h2 class="section-heading">User Management</h2>
                <div class="settings-grid">
                    <div class="card"> <h3 class="card-title">User Account Provisioning</h3>
                        <div class="card-body">
                            <form method="POST">
                                <div class="input-group">
                                    <label>Full Name</label>
                                    <input type="text" name="new_fullname" placeholder="Full Legal Name" required>
                                </div>
                                <div class="input-group">
                                    <label>Email Address</label>
                                    <input type="email" name="new_email" placeholder="Institutional Email" required>
                                </div>
                                <div class="input-group" style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                    <div>
                                        <label>Username</label>
                                        <input type="text" name="new_username" required>
                                    </div>
                                    <div>
                                        <label>Password</label>
                                        <input type="password" name="new_password" required>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <label>Institutional Role (System Permission)</label>
                                    <select name="new_role" id="new_role" onchange="handleRoleChange()" required>
                                        <option value="" disabled selected>Select Role</option>
                                        <?php 
                                        foreach(array_unique(array_merge(['Requestor'], $department_names)) as $dept_name):
                                            $is_mis_option = ($dept_name === 'Management Information System Office');
                                            $disabled = ($is_mis_option && $mis_admin_exists) ? 'disabled' : '';
                                            $label = $dept_name;
                                            if ($is_mis_option && $mis_admin_exists) { $label .= ' (Admin account exists)'; }
                                        ?>
                                            <option value="<?php echo htmlspecialchars($dept_name); ?>" <?php echo $disabled; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div id="job_title_wrapper" class="input-group" style="display:none;">
                                    <div>
                                        <label>Job Title</label>
                                        <select name="new_title" id="new_title" onchange="handleTitleChange()" required>
                                            <option value="" disabled selected>Select Role First...</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div id="is_head_wrapper" class="input-group" style="display:none; background: #fffbeb; padding: 15px; border-radius: 6px; border: 1px solid #fde68a;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <input type="checkbox" id="is_head" name="is_head" value="1" style="width: 20px; height: 20px;">
                                        <label for="is_head" id="is_head_label" style="margin: 0; font-weight: bold; color: #92400e; cursor: pointer;">This user is the Head of their Department</label>
                                    </div>
                                </div>

                                <div class="input-group" style="background: #eef2ff; padding: 15px; border-radius: 6px; border: 1px solid #c7d2fe;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <input type="checkbox" id="enable_2fa" name="enable_2fa" value="1" style="width: 20px; height: 20px;">
                                        <label for="enable_2fa" style="margin: 0; font-weight: bold; color: #312e81; cursor: pointer;">Enable Two-Factor Authentication (2FA)</label>
                                    </div>
                                </div>

                                <button type="submit" name="create_user" class="btn">Register User</button>
                            </form>
                        </div>
                    </div>
                    <div class="card"> <h3 class="card-title">Manage Existing Accounts</h3>
                        <div class="card-body">
                            <div class="user-list">
                                <?php foreach($all_users as $user): ?>
                                    <div class="user-item">
                                        <div class="user-info">
                                            <strong class="user-name">
                                                <?php echo htmlspecialchars($user['full_name']); ?>
                                                <?php if($user['is_head']) echo '<span class="head-badge">★ Head</span>'; ?>
                                                <?php if(!empty($user['google_auth_secret'])) echo '<span class="two-fa-badge">2FA</span>'; ?>
                                                <?php if($user['password_reset_request'] == 1) echo '<span class="reset-req-badge">RESET REQ</span>'; ?>
                                            </strong>
                                            <span class="user-role"><?php echo htmlspecialchars($user['role']); ?></span>
                                            <span class="user-details">
                                                <?php echo htmlspecialchars($user['username']); ?>
                                            </span>
                                        </div>
                                        <div class="user-actions">
                                            <?php if ($user['password_reset_request'] == 1): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to reset the password for this user? A new temporary password will be generated.');">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                    <button type="submit" name="reset_password_admin" class="btn-action btn-reset-pass">Reset Password</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Reset 2FA for this user? They will be given a new QR code.');">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                    <button type="submit" name="reset_2fa" class="btn-action btn-reset-2fa">Reset 2FA</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to permanently delete this user?');">
                                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                <button type="submit" name="delete_user" class="btn-action btn-delete" <?php if ($user['role'] === 'Management Information System Office') echo 'disabled'; ?>>Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($is_mis): ?>
            <div class="settings-section">
                <h2 class="section-heading">Decision Support System (DSS)</h2>
                <div class="card">
                    <h3 class="card-title">User History Analysis</h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: -20px; margin-bottom: 25px;">Configure the system to flag users with a high rate of returned or rejected documents, providing a notice to reviewers.</p>
                    <form method="POST">
                        <div class="setting-item">
                            <div class="setting-item-label">
                                <strong>Enable User History Check</strong>
                                <span>Show a notice to reviewers based on a user's submission history.</span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="dss_history_enabled" value="1" <?php if(($settings['dss_history_enabled'] ?? 0) == 1) echo 'checked'; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="setting-item">
                            <div class="setting-item-label">
                                <strong>History Threshold</strong>
                                <span>Show notice if a user has more than [X] rejections in their last [Y] submissions.</span>
                            </div>
                            <div class="setting-thresholds">
                                <input type="number" name="dss_rejection_count" value="<?php echo htmlspecialchars($settings['dss_rejection_count'] ?? 3); ?>">
                                <span> rejections in last </span>
                                <input type="number" name="dss_submission_count" value="<?php echo htmlspecialchars($settings['dss_submission_count'] ?? 10); ?>">
                                <span> submissions</span>
                            </div>
                        </div>
                        <button type="submit" name="update_dss_history_settings" class="btn" style="margin-top: 20px;">Save DSS Settings</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($is_mis): ?>
            <div class="settings-section">
                <h2 class="section-heading">Institutional Structure</h2>
                <div class="card"> <h3 class="card-title">Departments & Job Titles</h3>
                    <div class="card-body">
                        <div class="doc-type-item" style="background: #f0f9ff; border-color: #bae6fd; margin-bottom: 25px;">
                            <h4 style="margin-top:0; color: #0c4a6e;">Add New Department</h4>
                            <form method="POST" style="display: flex; gap: 15px; align-items: flex-end;">
                                <div class="input-group" style="flex-grow: 1; margin-bottom: 0;">
                                    <label>Department Name</label>
                                    <input type="text" name="department_name" required>
                                </div>
                                <div class="checkbox-group">
                                    <input type="checkbox" id="is_signatory" name="is_signatory" value="1">
                                    <label for="is_signatory" style="margin-bottom: 0; white-space: nowrap;">Is Signatory?</label>
                                </div>
                                <button type="submit" name="add_department" class="btn btn-small">Add</button>
                            </form>
                        </div>

                        <?php foreach($all_departments as $dept): ?>
                            <div class="dept-item">
                                <div class="dept-header">
                                    <strong>
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                        <?php if($dept['is_signatory']) echo '<span class="signatory-badge">SIGNATORY</span>'; ?>
                                    </strong>
                                    <form method="POST" onsubmit="return confirm('Delete this department? This cannot be undone.');">
                                        <input type="hidden" name="department_id" value="<?php echo $dept['id']; ?>">
                                        <button type="submit" name="delete_department" class="btn-delete-subtle">&times;</button>
                                    </form>
                                </div>
                                <div class="job-title-list">
                                    <ul>
                                        <?php foreach($dept['job_titles'] as $title): ?>
                                            <li><span><?php echo htmlspecialchars($title['title_name']); ?></span>
                                                <form method="POST"><input type="hidden" name="title_id" value="<?php echo $title['id']; ?>"><button type="submit" name="delete_job_title" class="btn-delete-subtle">&times;</button></form>
                                            </li>
                                        <?php endforeach; ?>
                                        <?php if(empty($dept['job_titles'])) echo "<li><span style='color: var(--text-muted); font-style: italic;'>No job titles added.</span></li>"; ?>
                                    </ul>
                                    <form method="POST" class="add-title-form"><input type="hidden" name="department_name" value="<?php echo htmlspecialchars($dept['name']); ?>"><input type="text" name="title_name" placeholder="Add new job title..." required><button type="submit" name="add_job_title" class="btn btn-small btn-edit">+</button></form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="settings-section">
            <?php if ($is_mis): ?>
                <h2 class="section-heading">Workflow & Template Management</h2>
            <?php elseif ($is_acct_head): ?>
                <h2 class="section-heading">Financial Template Management</h2>
            <?php endif; ?>
            <div class="settings-grid">
                <?php if ($is_mis): ?>
                    <div class="card"> 
                        <h3 class="card-title">Document Type & Workflow Management</h3>
                        <div class="card-body">
                            <div class="doc-type-item" style="background: #f0f9ff; border-color: #bae6fd;">
                                <h4 style="margin-top:0; color: #0c4a6e;">Add New Template</h4>
                                <form method="POST">
                                    <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 15px;">
                                        <div class="input-group">
                                            <label>Document Name</label>
                                            <input type="text" name="doc_type_name" required>
                                        </div>
                                        <div class="input-group">
                                            <label>ARTA Level</label>
                                            <select name="doc_type_arta" required>
                                                <?php foreach($all_arta_levels as $level): ?><option value="<?php echo htmlspecialchars($level['level_name']); ?>"><?php echo htmlspecialchars($level['level_name']); ?></option><?php endforeach; ?>
                                            </select> 
                                        </div>
                                        <div class="input-group">
                                            <label>Workflow Type</label>
                                            <select name="doc_workflow_type">
                                                <option value="Approval">Approval</option>
                                                <option value="Transfer">Transfer</option>
                                            </select>
                                        </div>
                                    <div class="input-group" style="grid-column: span 3;">
                                        <label>Final Status Text (e.g., "Leave Approved", "Ready for Release")</label>
                                        <input type="text" name="doc_final_status" placeholder="Leave empty for default 'Ready for Release'">
                                    </div>
                                    </div>
                                    <div class="input-group">
                                        <label>Default Routing Sequence</label>
                                        <div class="workflow-builder" data-id="new">
                                            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                                <select class="officeSelect" style="flex: 1;">
                                                    <option value="Department Head">Department Head (of Requestor)</option>
                                                    <?php foreach($department_names as $dept_name): ?><option value="<?php echo htmlspecialchars($dept_name); ?>"><?php echo htmlspecialchars($dept_name); ?></option>
                                                    <option value="<?php echo htmlspecialchars($dept_name . ' (Head)'); ?>"><?php echo htmlspecialchars($dept_name . ' (Head)'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="btn btn-small btn-add-step">+ Add</button>
                                            </div>
                                            <ul class="routeList"></ul>
                                            <input type="hidden" name="doc_type_workflow" class="workflowInput" value="[]">
                                        </div>
                                    </div>
                                    <button type="submit" name="add_doc_type" class="btn btn-small">Save New Template</button>
                                </form>
                            </div>

                            <form method="POST" id="bulkDeleteForm" onsubmit="return confirm('Are you sure you want to delete the selected document types?');">
                                <input type="hidden" name="delete_bulk_doc_types" value="1">
                            </form>
                            <h4 style="margin-top: 30px; display: flex; align-items: center; gap: 10px;">
                                    <input type="checkbox" id="selectAllDocTypes" style="width: 20px; height: 20px;">
                                    <label for="selectAllDocTypes" style="margin-bottom: 0; font-size: 1.1rem; color: var(--text-dark); cursor: pointer;">Select All</label>
                                    Existing Templates
                                </h4>
                                <?php foreach($all_doc_types as $type):
                                    $workflow = json_decode($type['default_workflow'] ?? '[]', true);
                                ?>
                                    <div class="doc-type-item" id="item-<?php echo $type['id']; ?>">
                                        <div class="display-view">
                                            <div style="display: flex; align-items: center; gap: 15px;">
                                                <input type="checkbox" name="doc_type_ids[]" value="<?php echo $type['id']; ?>" form="bulkDeleteForm" style="width: 20px; height: 20px;">
                                                <div class="doc-type-info">
                                                    <strong><?php echo htmlspecialchars($type['name']); ?></strong>
                                                    <small>ARTA: <?php echo $type['arta_level']; ?> | Type: <?php echo $type['workflow_type']; ?></small>
                                                    <ul class="workflow-list">
                                                        <?php foreach($workflow as $step): ?><li><?php echo htmlspecialchars($step); ?></li><?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            </div>
                                            <div class="user-actions">
                                                <button type="button" class="btn btn-small btn-edit" onclick="toggleEditView(<?php echo $type['id']; ?>)">Edit</button>
                                            </div>
                                        </div>
                                        <div class="edit-view" style="position: relative;">
                                            <form method="POST">
                                                <input type="hidden" name="doc_type_id" value="<?php echo $type['id']; ?>">
                                                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 15px;">
                                                    <input type="text" name="doc_type_name" value="<?php echo htmlspecialchars($type['name']); ?>" required>
                                                                <select name="doc_type_arta" required>
                                                                    <?php foreach($all_arta_levels as $level): ?><option value="<?php echo htmlspecialchars($level['level_name']); ?>" <?php if($type['arta_level'] == $level['level_name']) echo 'selected'; ?>><?php echo htmlspecialchars($level['level_name']); ?></option><?php endforeach; ?>
                                                                </select> 
                                                    <select name="doc_workflow_type">
                                                        <option value="Approval" <?php if($type['workflow_type'] == 'Approval') echo 'selected'; ?>>Approval</option>
                                                        <option value="Transfer" <?php if($type['workflow_type'] == 'Transfer') echo 'selected'; ?>>Transfer</option>
                                                    </select>
                                                </div>
                                                <div class="input-group" style="margin-top: 15px;">
                                                    <label>Default Routing Sequence</label>
                                                    <div class="workflow-builder" data-id="<?php echo $type['id']; ?>">
                                                        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                                            <select class="officeSelect" style="flex: 1;"><option value="Department Head">Department Head (of Requestor)</option>
                                                            <?php foreach($department_names as $dept_name): ?><option value="<?php echo htmlspecialchars($dept_name); ?>"><?php echo htmlspecialchars($dept_name); ?></option>
                                                            <option value="<?php echo htmlspecialchars($dept_name . ' (Head)'); ?>"><?php echo htmlspecialchars($dept_name . ' (Head)'); ?></option>
                                                            <?php endforeach; ?>
                                                            </select> 
                                                            <button type="button" class="btn btn-small btn-add-step">+ Add</button>
                                                        </div>
                                                        <ul class="routeList"></ul>
                                                        <input type="hidden" name="doc_type_workflow" class="workflowInput" value="<?php echo htmlspecialchars($type['default_workflow'] ?? '[]', ENT_QUOTES); ?>">
                                                    </div>
                                                </div>
                                                <div class="edit-actions">
                                                    <button type="submit" name="update_doc_type" class="btn btn-small">Save Changes</button>
                                                    <button type="button" class="btn btn-small btn-cancel" onclick="toggleEditView(<?php echo $type['id']; ?>)">Cancel</button>
                                                </div>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete \'<?php echo htmlspecialchars($type['name']); ?>\'? This cannot be undone.');" style="position: absolute; top: 20px; right: 20px;">
                                                <input type="hidden" name="doc_type_id" value="<?php echo $type['id']; ?>">
                                                <button type="submit" name="delete_single_doc_type" class="btn btn-small btn-delete-single">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                        </div>
                        <div class="card-footer">
                                <div class="bulk-actions">
                                    <button type="submit" form="bulkDeleteForm" class="btn btn-small btn-delete">Delete Selected</button>
                                </div>
                        </div>
                    </div>
                    <?php endif; ?>

                <div class="card"> 
                    <h3 class="card-title">Financial Voucher Types & Requirements</h3>
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: -20px; margin-bottom: 25px;">Define types for financial transactions and their required attachments. These appear when a user creates a financial request.</p>
                    <div class="card-body">

                        <div class="doc-type-item" style="background: #fffbeb; border-color: #fde68a;">
                            <h4 style="margin-top:0; color: #92400e;">Add New Financial Type</h4>
                            <form method="POST">
                                <div class="input-group">
                                    <label>Voucher Type Name</label>
                                    <input type="text" name="voucher_type_name" placeholder="e.g., Travel Reimbursement, Cash Advance" required>
                                </div>
                                <div class="input-group">
                                    <label>ARTA Level</label>
                                    <select name="voucher_arta_level" required>
                                        <?php foreach($all_arta_levels as $level): ?><option value="<?php echo htmlspecialchars($level['level_name']); ?>"><?php echo htmlspecialchars($level['level_name']); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="input-group">
                                    <label>Requirements Checklist</label>
                                    <textarea name="requirements" placeholder="Enter one requirement per line..."></textarea>
                                </div>
                                <div class="input-group">
                                    <label>Mandatory Routing Sequence</label>
                                    <div class="workflow-builder" data-id="v-new">
                                        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                            <select class="officeSelect" style="flex: 1;">
                                                <option value="Department Head">Department Head (of Requestor)</option>
                                                <?php foreach($department_names as $dept_name): ?><option value="<?php echo htmlspecialchars($dept_name); ?>"><?php echo htmlspecialchars($dept_name); ?></option>
                                                <option value="<?php echo htmlspecialchars($dept_name . ' (Head)'); ?>"><?php echo htmlspecialchars($dept_name . ' (Head)'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-small btn-add-step">+ Add</button>
                                        </div>
                                        <ul class="routeList"></ul>
                                        <input type="hidden" name="voucher_type_workflow" class="workflowInput" value="[]">
                                    </div>
                                </div>
                                <button type="submit" name="add_voucher_type" class="btn btn-small btn-gold">Save New Financial Type</button>
                            </form>
                        </div>

                        <form method="POST" id="bulkDeleteVoucherTypeForm" onsubmit="return confirm('Are you sure you want to delete the selected financial voucher types?');">
                            <input type="hidden" name="delete_bulk_voucher_types" value="1">
                        </form>
                        <h4 style="margin-top: 30px; display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="selectAllVoucherTypes" style="width: 20px; height: 20px;">
                            <label for="selectAllVoucherTypes" style="margin-bottom: 0; font-size: 1.1rem; color: var(--text-dark); cursor: pointer;">Select All</label>
                            Existing Financial Types
                        </h4>
                        <?php foreach($all_voucher_types as $v_type): 
                            $v_workflow = json_decode($v_type['default_workflow'] ?? '[]', true);
                            $v_reqs = json_decode($v_type['requirements'] ?? '[]', true);
                        ?>
                            <div class="doc-type-item" id="item-v-<?php echo $v_type['id']; ?>">
                                <div class="display-view">
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <input type="checkbox" name="voucher_type_ids[]" value="<?php echo $v_type['id']; ?>" form="bulkDeleteVoucherTypeForm" style="width: 20px; height: 20px;">
                                        <div class="doc-type-info">
                                            <strong><?php echo htmlspecialchars($v_type['name']); ?></strong>
                                            <small>ARTA: <?php echo htmlspecialchars($v_type['arta_level']); ?> | Requirements: <?php echo count($v_reqs); ?> items</small>
                                            <ul class="workflow-list">
                                                <?php foreach($v_workflow as $step): ?><li><?php echo htmlspecialchars($step); ?></li><?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="user-actions">
                                        <button type="button" class="btn btn-small btn-edit" onclick="toggleVoucherEditView(<?php echo $v_type['id']; ?>)">Edit</button>
                                    </div>
                                </div>
                                <div class="edit-view" style="position: relative;">
                                    <form method="POST">
                                        <input type="hidden" name="voucher_type_id" value="<?php echo $v_type['id']; ?>">
                                        <div class="input-group">
                                            <label>Voucher Type Name</label>
                                            <input type="text" name="voucher_type_name" value="<?php echo htmlspecialchars($v_type['name']); ?>" required>
                                        </div>
                                        <div class="input-group">
                                            <label>ARTA Level</label>
                                            <select name="voucher_arta_level" required>
                                                <?php foreach($all_arta_levels as $level): ?><option value="<?php echo htmlspecialchars($level['level_name']); ?>" <?php if($v_type['arta_level'] == $level['level_name']) echo 'selected'; ?>><?php echo htmlspecialchars($level['level_name']); ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="input-group">
                                            <label>Requirements (one per line)</label>
                                            <textarea name="requirements"><?php echo htmlspecialchars(implode("\n", $v_reqs)); ?></textarea>
                                        </div>
                                        <div class="input-group">
                                            <label>Mandatory Routing Sequence</label>
                                            <div class="workflow-builder" data-id="v-<?php echo $v_type['id']; ?>">
                                                <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                                    <select class="officeSelect" style="flex: 1;"><option value="Department Head">Department Head (of Requestor)</option>
                                                    <?php foreach($department_names as $dept_name): ?><option value="<?php echo htmlspecialchars($dept_name); ?>"><?php echo htmlspecialchars($dept_name); ?></option>
                                                    <option value="<?php echo htmlspecialchars($dept_name . ' (Head)'); ?>"><?php echo htmlspecialchars($dept_name . ' (Head)'); ?></option>
                                                    <?php endforeach; ?>
                                                    </select> 
                                                    <button type="button" class="btn btn-small btn-add-step">+ Add</button>
                                                </div>
                                                <ul class="routeList"></ul>
                                                <input type="hidden" name="voucher_type_workflow" class="workflowInput" value="<?php echo htmlspecialchars($v_type['default_workflow'] ?? '[]', ENT_QUOTES); ?>">
                                            </div>
                                        </div>
                                        <div class="edit-actions">
                                            <button type="submit" name="update_voucher_type" class="btn btn-small btn-gold">Save Changes</button>
                                            <button type="button" class="btn btn-small btn-cancel" onclick="toggleVoucherEditView(<?php echo $v_type['id']; ?>)">Cancel</button>
                                        </div>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete \'<?php echo htmlspecialchars($v_type['name']); ?>\'? This cannot be undone.');" style="position: absolute; top: 20px; right: 20px;">
                                        <input type="hidden" name="voucher_type_id" value="<?php echo $v_type['id']; ?>">
                                        <button type="submit" name="delete_voucher_type" class="btn btn-small btn-delete-single">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($all_voucher_types)): ?>
                            <p style="text-align: center; color: var(--text-muted); padding: 20px; background: #f8fafc; border-radius: 6px;">No financial voucher types have been created yet.</p>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <div class="bulk-actions">
                            <button type="submit" form="bulkDeleteVoucherTypeForm" class="btn btn-small btn-delete">Delete Selected</button>
                        </div>
                    </div>
                </div>
                </div>
        </div>

        <?php if ($is_mis): ?>
        <div class="settings-section">
            <h2 class="section-heading">System Configuration</h2>
            <div class="settings-grid">
                <div class="card"> <h3 class="card-title">Holiday Management</h3>
                    <div class="card-body">
                        <div class="doc-type-item" style="background: #f0f9ff; border-color: #bae6fd; margin-bottom: 25px;">
                            <h4 style="margin-top:0; color: #0c4a6e;">Add New Holiday</h4>
                            <form method="POST" style="display: flex; gap: 15px; align-items: flex-end;">
                                <div class="input-group" style="flex-grow: 1; margin-bottom: 0;">
                                    <label>Date</label>
                                    <input type="date" name="holiday_date" required>
                                </div>
                                <div class="input-group" style="flex-grow: 2; margin-bottom: 0;">
                                    <label>Description</label>
                                    <input type="text" name="description" placeholder="e.g., New Year's Day" required>
                                </div>
                                <button type="submit" name="add_holiday" class="btn btn-small">Add</button>
                            </form>
                        </div>

                        <h4 style="margin-top: 30px; color: var(--text-dark);">Existing Holidays</h4>
                        <div style="border: 1px solid var(--border-light); border-radius: 6px;">
                            <?php if (!empty($all_holidays)): ?>
                                <?php foreach($all_holidays as $holiday): ?>
                                    <div class="holiday-item">
                                        <span><strong><?php echo date('M d, Y', strtotime($holiday['holiday_date'])); ?></strong> - <?php echo htmlspecialchars($holiday['description']); ?></span>
                                        <form method="POST" onsubmit="return confirm('Delete this holiday?');">
                                            <input type="hidden" name="holiday_id" value="<?php echo $holiday['id']; ?>">
                                            <button type="submit" name="delete_holiday" class="btn-delete-subtle">&times;</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="text-align: center; color: var(--text-muted); padding: 15px; margin: 0;">No holidays added yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card"> <h3 class="card-title">General System Settings</h3>
                    <div class="card-body">
                        <form method="POST" class="general-settings-form">
                            <div class="checkbox-group input-group"><input type="checkbox" id="setting_qr" name="setting_qr" value="1" <?php if($settings['setting_qr'] == '1') echo 'checked'; ?>><label for="setting_qr">Enable QR Code Scanning</label></div>
                            <div class="checkbox-group input-group"><input type="checkbox" id="setting_rule" name="setting_rule" value="1" <?php if($settings['setting_rule'] == '1') echo 'checked'; ?>><label for="setting_rule">Enable Workflow Rule Validation</label></div>
                            <div class="checkbox-group input-group"><input type="checkbox" id="setting_email" name="setting_email" value="1" <?php if($settings['setting_email'] == '1') echo 'checked'; ?>><label for="setting_email">Enable Email Notifications (Not yet implemented)</label></div>
                            <div class="checkbox-group input-group"><input type="checkbox" id="setting_audit" name="setting_audit" value="1" <?php if($settings['setting_audit'] == '1') echo 'checked'; ?>><label for="setting_audit">Enable Detailed Audit Logging</label></div>
                            <button type="submit" name="update_system_settings" class="btn btn-small" style="margin-top: 15px;">Save Settings</button>
                        </form>
                    </div>
                </div>
                <div class="card"> <h3 class="card-title">ARTA Level Management</h3>
                    <div class="card-body">
                        <div class="doc-type-item" style="background: #f0f9ff; border-color: #bae6fd; margin-bottom: 25px;">
                            <h4 style="margin-top:0; color: #0c4a6e;">Add New ARTA Level</h4>
                            <form method="POST" style="display: flex; gap: 15px; align-items: flex-end;">
                                <div class="input-group" style="flex-grow: 2; margin-bottom: 0;">
                                    <label>Level Name</label>
                                    <input type="text" name="level_name" placeholder="e.g., Simple, Exempt" required>
                                </div>
                                <div class="input-group" style="flex-grow: 1; margin-bottom: 0;">
                                    <label>Processing Days</label>
                                    <input type="number" name="processing_days" min="0" required>
                                </div>
                                <button type="submit" name="add_arta_level" class="btn btn-small">Add</button>
                            </form>
                        </div>

                        <h4 style="margin-top: 30px; color: var(--text-dark);">Existing Levels</h4>
                        <?php foreach($all_arta_levels as $level): ?>
                            <div class="doc-type-item" id="item-arta-<?php echo $level['id']; ?>">
                                <div class="display-view">
                                    <div class="doc-type-info">
                                        <strong><?php echo htmlspecialchars($level['level_name']); ?></strong>
                                        <small>Processing Time: <?php echo $level['processing_days']; ?> working days</small>
                                    </div>
                                    <div class="user-actions">
                                        <button type="button" class="btn btn-small btn-edit" onclick="toggleArtaEditView(<?php echo $level['id']; ?>)">Edit</button>
                                    </div>
                                </div>
                                <div class="edit-view" style="position: relative;">
                                    <form method="POST" style="display: flex; gap: 15px; align-items: flex-end;">
                                        <input type="hidden" name="level_id" value="<?php echo $level['id']; ?>">
                                        <div class="input-group" style="flex-grow: 2; margin-bottom: 0;"><label>Level Name</label><input type="text" name="level_name" value="<?php echo htmlspecialchars($level['level_name']); ?>" required></div>
                                        <div class="input-group" style="flex-grow: 1; margin-bottom: 0;"><label>Days</label><input type="number" name="processing_days" value="<?php echo $level['processing_days']; ?>" min="0" required></div>
                                        <button type="submit" name="update_arta_level" class="btn btn-small">Save</button>
                                        <button type="button" class="btn btn-small btn-cancel" onclick="toggleArtaEditView(<?php echo $level['id']; ?>)">Cancel</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Delete this ARTA level?');" style="position: absolute; top: 0; right: 0;"><input type="hidden" name="level_id" value="<?php echo $level['id']; ?>"><button type="submit" name="delete_arta_level" class="btn-delete-subtle">&times;</button></form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
</div>

<script>
    // DEPENDENT DROPDOWN LOGIC
    const jobTitlesByDept = <?php echo json_encode($job_titles_for_js_dropdown ?? []); ?>;

    function updateJobTitles() {
        const roleSelect = document.getElementById('new_role');
        const titleSelect = document.getElementById('new_title');
        const selectedRole = roleSelect.value;

        // Clear existing options
        titleSelect.innerHTML = '<option value="" disabled selected>Select Job Title...</option>';

        // Populate new options if a valid department is selected
        if (selectedRole && jobTitlesByDept[selectedRole] && jobTitlesByDept[selectedRole].length > 0) {
            jobTitlesByDept[selectedRole].forEach(function(title) {
                let option = document.createElement("option");
                option.value = title;
                option.textContent = title;
                titleSelect.appendChild(option);
            });
        } else if (selectedRole && selectedRole !== 'Requestor') { // Only show this message for non-Requestor roles that are departments
            let option = document.createElement("option");
            option.value = "";
            option.textContent = "No job titles found for this department.";
            option.disabled = true;
            titleSelect.appendChild(option);
        }
    }

    function handleTitleChange() {
        const titleSelect = document.getElementById('new_title');
        const isHeadCheckbox = document.getElementById('is_head');
        const isHeadLabel = document.getElementById('is_head_label');

        if (titleSelect.value === 'Dean') {
            isHeadCheckbox.checked = true;
            isHeadCheckbox.disabled = true;
            isHeadLabel.innerHTML = 'This user is the Head of their Department <small style="display: block; color: #92400e; font-weight: normal;">(Auto-enabled for Deans)</small>';
        } else {
            isHeadCheckbox.disabled = false;
            // Do not uncheck it if the user manually checked it for another title
            isHeadLabel.innerHTML = 'This user is the Head of their Department';
        }
    }

    function handleRoleChange() {
        const roleSelect = document.getElementById('new_role');
        const jobTitleWrapper = document.getElementById('job_title_wrapper');
        const titleSelect = document.getElementById('new_title');
        const isHeadWrapper = document.getElementById('is_head_wrapper');

        if (roleSelect.value === 'Requestor' || roleSelect.value === '') {
            jobTitleWrapper.style.display = 'none';
            titleSelect.required = false;
            isHeadWrapper.style.display = 'none';
        } else {
            jobTitleWrapper.style.display = 'block';
            titleSelect.required = true;
            isHeadWrapper.style.display = 'block';
            updateJobTitles(); // Populate job titles based on the selected role
            handleTitleChange(); // Reset the 'is_head' checkbox state
        }
    }

    // --- DOC TYPE MANAGEMENT SCRIPT ---
    let workflows = {};

    function toggleEditView(id) {
        const item = document.getElementById(`item-${id}`);
        const displayView = item.querySelector('.display-view');
        const editView = item.querySelector('.edit-view');

        if (editView.style.display === 'block') {
            editView.style.display = 'none';
            displayView.style.display = 'flex';
        } else {
            editView.style.display = 'block';
            displayView.style.display = 'none';
            const builder = editView.querySelector('.workflow-builder');
            if (builder) { initializeWorkflow(builder); }
        }
    }

    function toggleVoucherEditView(id) {
        const item = document.getElementById(`item-v-${id}`);
        const displayView = item.querySelector('.display-view');
        const editView = item.querySelector('.edit-view');

        if (editView.style.display === 'block') {
            editView.style.display = 'none';
            displayView.style.display = 'flex';
        } else {
            editView.style.display = 'block';
            displayView.style.display = 'none';
            const builder = editView.querySelector('.workflow-builder');
            if (builder) { initializeWorkflow(builder); }
        }
    }

    function initializeWorkflow(builder) {
        const id = builder.dataset.id;
        const input = builder.querySelector('.workflowInput');
        
        // The input value is HTML-encoded by PHP. We must decode it before parsing as JSON.
        const textarea = document.createElement('textarea');
        textarea.innerHTML = input.value;
        const decodedValue = textarea.value;

        try {
            workflows[id] = JSON.parse(decodedValue || '[]');
        } catch (e) {
            console.error(`Failed to parse workflow for ID ${id}. Value:`, input.value, 'Decoded:', decodedValue, 'Error:', e);
            workflows[id] = []; // Fallback to an empty array on error
        }

        updateWorkflowUI(builder);
    }

    function updateWorkflowUI(builder) {
        const id = builder.dataset.id;
        const list = builder.querySelector('.routeList');
        const input = builder.querySelector('.workflowInput');
        
        // Ensure workflows[id] is an array before proceeding.
        if (!Array.isArray(workflows[id])) {
            workflows[id] = [];
        }

        let listHTML = '';
        if (workflows[id].length > 0) {
            workflows[id].forEach((office, index) => {
                const sanitizedOffice = office.replace(/</g, "&lt;").replace(/>/g, "&gt;");
                listHTML += `<li><span>${index + 1}. ${sanitizedOffice}</span><button type="button" class="btn-remove-step" data-index="${index}" style="background:none; border:none; color:red; cursor:pointer; font-size: 1.2rem; font-weight: bold;">&times;</button></li>`;
            });
        } else {
            listHTML = `<li style='padding: 25px; color: var(--text-muted); text-align: center; font-style: italic; font-size: 0.9rem;'>No routing steps added. Click '+ Add' to begin.</li>`;
        }
        list.innerHTML = listHTML;

        input.value = JSON.stringify(workflows[id]);
    }

    document.addEventListener('DOMContentLoaded', function() {
        // --- SELECT ALL FOR DOC TYPES ---
        const selectAllCheckbox = document.getElementById('selectAllDocTypes');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('input[name="doc_type_ids[]"]');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
            });
            document.querySelectorAll('input[name="doc_type_ids[]"]').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    if (!this.checked) {
                        selectAllCheckbox.checked = false;
                    }
                });
            });
        }

        // --- SELECT ALL FOR VOUCHER TYPES ---
        const selectAllVoucherCheckbox = document.getElementById('selectAllVoucherTypes');
        if (selectAllVoucherCheckbox) {
            selectAllVoucherCheckbox.addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('input[name="voucher_type_ids[]"]');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
            });
            document.querySelectorAll('input[name="voucher_type_ids[]"]').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    if (!this.checked) {
                        selectAllVoucherCheckbox.checked = false;
                    }
                });
            });
        }

        // Initialize all workflow builders on page load
        document.querySelectorAll('.workflow-builder').forEach(initializeWorkflow);

        // Use event delegation for all '+ Add' buttons for reliability.
        // This single listener handles buttons in both "Add New" and "Edit" forms.
        document.querySelector('.main-content').addEventListener('click', function(event) {
            
            // Handle Add step using .closest() for better reliability
            const addBtn = event.target.closest('.btn-add-step');
            if (addBtn) {
                const builder = addBtn.closest('.workflow-builder');
                const id = builder.dataset.id;
                const select = builder.querySelector('.officeSelect');
                const office = select.value;

                if (!Array.isArray(workflows[id])) {
                    workflows[id] = []; // Defensive initialization
                }

                if (workflows[id].length > 0 && workflows[id][workflows[id].length - 1] === office) {
                    alert("This office is already the last step.");
                    return;
                }
                workflows[id].push(office);
                updateWorkflowUI(builder);
                return; // Stop further execution for this click
            }

            // Handle step removal using event delegation
            const removeBtn = event.target.closest('.btn-remove-step');
            if (removeBtn) {
                const builder = removeBtn.closest('.workflow-builder');
                const id = builder.dataset.id;
                const index = parseInt(removeBtn.dataset.index, 10);
                workflows[id].splice(index, 1);
                updateWorkflowUI(builder);
            }
        });
    });

    function toggleArtaEditView(id) {
        const item = document.getElementById(`item-arta-${id}`);
        const displayView = item.querySelector('.display-view');
        const editView = item.querySelector('.edit-view');

        if (editView.style.display === 'block') {
            editView.style.display = 'none';
            displayView.style.display = 'flex';
        } else {
            editView.style.display = 'block';
            displayView.style.display = 'none';
        }
    }
</script>

<?php
$conn->close();
?>

<?php if (isset($show_2fa_modal) && $show_2fa_modal): ?>
<div class="qr-modal" id="twoFAModal">
    <div class="qr-modal-content">
        <h2>2FA Setup for <?php echo htmlspecialchars($new_user_for_modal); ?></h2>
        <p>Scan the QR code with Google Authenticator or a compatible app. Alternatively, manually enter the secret key.</p>
        <img src="<?php echo $new_qr_for_modal; ?>" alt="2FA QR Code">
        <p>Your Secret Key:</p>
        <div class="secret-code"><?php echo htmlspecialchars($new_secret_for_modal); ?></div>
        <button class="btn-close-modal" onclick="document.getElementById('twoFAModal').style.display='none'">Close</button>
    </div>
</div>
<?php endif; ?>

</body>
</html>