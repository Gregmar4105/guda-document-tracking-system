<?php
// process_signature.php
session_start();
if (!isset($_SESSION['logged_in'])) { header("Location: login.php"); exit(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: queue.php"); // Redirect to the actual queue page
    exit();
}

require_once 'db_connect.php';

$dept_role = $_SESSION['role'];
$username = $_SESSION['username'];

// Get User ID for logging
$user_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
$user_stmt->bind_param("s", $username);
$user_stmt->execute();
$user_id = $user_stmt->get_result()->fetch_assoc()['user_id'];
$user_stmt->close();

// Get POST data - This assumes the form now sends 'voucher_id' to align with the app
$processed_id = $_POST['voucher_id'] ?? null;
$action = $_POST['action'] ?? null; // 'Accept', 'Return', 'Decline'
$remarks = trim($_POST['remarks'] ?? '');

// Validation
if (!$processed_id || !$action) {
    die("Error: Missing required form data.");
}
if (in_array($action, ['Return', 'Decline']) && empty($remarks)) {
    die("Error: Comments are required when returning or declining a document.");
}

// Start transaction
$conn->autocommit(FALSE);

try {
    // 1. Verify the voucher is at the correct stage for this user's department
    $verify_stmt = $conn->prepare("SELECT current_stage_index, custom_workflow, workflow_type FROM vouchers WHERE voucher_code = ? FOR UPDATE");
    $verify_stmt->bind_param("s", $processed_id);
    $verify_stmt->execute();
    $verify_res = $verify_stmt->get_result();

    if ($verify_res->num_rows === 0) {
        throw new Exception("Voucher not found.");
    }
    $voucher = $verify_res->fetch_assoc();
    $verify_stmt->close();

    // Get the document's specific workflow or fallback to the global one
    $doc_workflow = json_decode($voucher['custom_workflow'], true);
    if (empty($doc_workflow)) {
        $workflow_sequence = [];
        $seq_res = $conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1 ORDER BY name ASC");
        while ($row = $seq_res->fetch_assoc()) {
            $workflow_sequence[] = $row['name'];
        }
        $doc_workflow = $workflow_sequence;
    }

    // Get the expected department from the workflow (0-indexed array)
    $current_stage_0_indexed = $voucher['current_stage_index'] - 1;
    $expected_dept = $doc_workflow[$current_stage_0_indexed] ?? null;

    // --- NEW ROBUST AUTHORIZATION CHECK ---
    $is_authorized_to_process = false;
    $current_user_is_head = ($_SESSION['is_head'] ?? 0) == 1;

    // Normalize the expected department name from the workflow
    $normalized_expected_dept = str_replace(['–', '—'], '-', (string)$expected_dept);

    // Get the user's base role (without '(Head)') and normalize it
    $user_base_role = $dept_role;
    if ($current_user_is_head) {
        $user_base_role = trim(preg_replace('/\s*\(Head\)$/i', '', $user_base_role));
    }
    $normalized_user_base_role = str_replace(['–', '—'], '-', (string)$user_base_role);

    // Case 0: Route targets a specific account like "ACCOUNT:<user_id>"
    if (preg_match('/^ACCOUNT:(\d+)$/i', $normalized_expected_dept, $acct_match)) {
        $acct_target = intval($acct_match[1]);
        if ($acct_target > 0 && $acct_target === (int)$user_id) {
            $is_authorized_to_process = true;
        }
    // Case 1: Route is for a specific head, e.g., "Accounting Office (Head)"
    } elseif (preg_match('/^(.*) \(Head\)$/', $normalized_expected_dept, $matches)) {
        $dept_name_for_head_check = trim($matches[1]);
        if ($normalized_user_base_role === $dept_name_for_head_check && $current_user_is_head) {
            $is_authorized_to_process = true;
        }
    // Case 2: Route is for the generic "Department Head"
    } elseif ($normalized_expected_dept === 'Department Head') {
        // Must be the head of the requestor's department.
        $req_id_stmt = $conn->prepare("SELECT requestor_id FROM vouchers WHERE voucher_code = ?");
        $req_id_stmt->bind_param("s", $processed_id);
        $req_id_stmt->execute();
        $requestor_id = $req_id_stmt->get_result()->fetch_assoc()['requestor_id'] ?? 0;
        $req_id_stmt->close();

        $req_dept_stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
        $req_dept_stmt->bind_param("i", $requestor_id);
        $req_dept_stmt->execute();
        $requestor_department = $req_dept_stmt->get_result()->fetch_assoc()['role'] ?? null;
        $req_dept_stmt->close();

        $normalized_requestor_dept = str_replace(['–', '—'], '-', (string)$requestor_department);

        if ($requestor_department && $normalized_user_base_role === $normalized_requestor_dept && $current_user_is_head) {
            $is_authorized_to_process = true;
        }
    // Case 3: Standard department route
    } elseif ($normalized_expected_dept === $normalized_user_base_role) {
        $is_authorized_to_process = true;
    }

    if (!$is_authorized_to_process) {
        throw new Exception("Validation Failed: This document is not currently in your department's queue.");
    }

    // 2. Update the voucher based on the action
    $upd_stmt = null;
    if ($action === "Accept") {
        // Check if this is the final stage
        if ($voucher['current_stage_index'] >= count($doc_workflow)) {
            // This is the final stage. Determine the correct final status.
            $final_status_to_use = 'Ready for Release'; // Default for approval workflows

            if ($voucher['workflow_type'] === 'Transfer') {
                $final_status_to_use = 'Received';
            } else { // It's an 'Approval' workflow
                // Get the custom final status text from the document type, if it exists.
                $doc_type_id_stmt = $conn->prepare("SELECT dt.final_status_text FROM vouchers v LEFT JOIN document_types dt ON v.doc_type_id = dt.id WHERE v.voucher_code = ?");
                $doc_type_id_stmt->bind_param("s", $processed_id);
                $doc_type_id_stmt->execute();
                $doc_type_data = $doc_type_id_stmt->get_result()->fetch_assoc();
                $doc_type_id_stmt->close();
                
                if ($doc_type_data && !empty($doc_type_data['final_status_text'])) {
                    $final_status_to_use = $doc_type_data['final_status_text'];
                }
            }

            $upd_stmt = $conn->prepare("UPDATE vouchers SET status = ? WHERE voucher_code = ?");
            $upd_stmt->bind_param("ss", $final_status_to_use, $processed_id);
        } else {
            $upd_stmt = $conn->prepare("UPDATE vouchers SET status = 'Processing', current_stage_index = current_stage_index + 1 WHERE voucher_code = ?");
            $upd_stmt->bind_param("s", $processed_id);
        }
    } elseif ($action === "Return") {
        $upd_stmt = $conn->prepare("UPDATE vouchers SET status = 'Returned' WHERE voucher_code = ?");
        $upd_stmt->bind_param("s", $processed_id);
    } elseif ($action === "Decline") {
        $upd_stmt = $conn->prepare("UPDATE vouchers SET status = 'Rejected' WHERE voucher_code = ?");
        $upd_stmt->bind_param("s", $processed_id);
    } else {
        throw new Exception("Invalid action specified.");
    }

    if (!$upd_stmt->execute()) {
        throw new Exception("Failed to update voucher status.");
    }
    $upd_stmt->close();

    // 3. Log the action in the audit trail
    $log_action = strtoupper($action);
    if ($action === 'Accept') $log_action = 'Accepted';
    if ($action === 'Return') $log_action = 'RETURNED';
    if ($action === 'Decline') $log_action = 'DECLINED';

    $log_stmt = $conn->prepare("INSERT INTO audit_logs (voucher_code, department, action_taken, remarks, processed_by_user_id) VALUES (?, ?, ?, ?, ?)");
    $log_stmt->bind_param("ssssi", $processed_id, $dept_role, $log_action, $remarks, $user_id);
    if (!$log_stmt->execute()) {
        throw new Exception("Failed to record action in audit log.");
    }
    $log_stmt->close();

    // --- NEW: Notify the next recipient if the workflow advanced to another step ---
    try {
        $next_stmt = $conn->prepare("SELECT current_stage_index, custom_workflow, workflow_type FROM vouchers WHERE voucher_code = ? LIMIT 1");
        $next_stmt->bind_param("s", $processed_id);
        $next_stmt->execute();
        $next_res = $next_stmt->get_result();
        $next_row = $next_res->fetch_assoc();
        $next_stmt->close();

        if ($next_row) {
            $next_current_index = (int)$next_row['current_stage_index'];
            $next_custom_workflow = json_decode($next_row['custom_workflow'], true) ?? [];
            $next_workflow_type = $next_row['workflow_type'] ?? 'Approval';

            // Determine the workflow array to use
            $effective_workflow = !empty($next_custom_workflow) ? $next_custom_workflow : $doc_workflow;

            $next_stage_idx_0 = $next_current_index - 1; // 0-based
            $next_step = $effective_workflow[$next_stage_idx_0] ?? null;

            if (!empty($next_step)) {
                // If the next step is an account (ACCOUNT:<id>), notify only that user
                if (is_string($next_step) && strpos($next_step, 'ACCOUNT:') === 0) {
                    $acct_id = intval(substr($next_step, strlen('ACCOUNT:')));
                    if ($acct_id > 0) {
                        $notif_message = "Heads up! A document (" . $processed_id . ") has been routed to your account.";
                        $notif_link = "queue.php";
                        create_notification($conn, $acct_id, $notif_message, $notif_link);
                    }
                } else {
                    // Otherwise, notify according to department rules (heads or everyone)
                    $users_to_notify_stmt = prepare_notification_statement_for_department($conn, $next_step);
                    if ($users_to_notify_stmt) {
                        $users_to_notify_stmt->execute();
                        $users_res = $users_to_notify_stmt->get_result();
                        $signatory_notif_message = "Heads up! A document (" . $processed_id . ") is en route to your office.";
                        $signatory_notif_link = "queue.php";
                        while ($user_row = $users_res->fetch_assoc()) {
                            create_notification($conn, $user_row['user_id'], $signatory_notif_message, $signatory_notif_link);
                        }
                        $users_to_notify_stmt->close();
                    }
                }
            } else {
                // No next step (could be final); if this was a Transfer and its destination was an account, ensure it's notified
                if ($next_workflow_type === 'Transfer') {
                    // For Transfers, the destination is stored in custom_workflow[0]
                    $dest = $next_custom_workflow[0] ?? ($doc_workflow[0] ?? null);
                    if (is_string($dest) && strpos($dest, 'ACCOUNT:') === 0) {
                        $acct_id = intval(substr($dest, strlen('ACCOUNT:')));
                        if ($acct_id > 0) {
                            $notif_message = "Heads up! A document (" . $processed_id . ") has been sent to your account.";
                            $notif_link = "queue.php";
                            create_notification($conn, $acct_id, $notif_message, $notif_link);
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Non-fatal: log and continue (notification failures should not block the workflow)
        error_log('Notification error in process_signature: ' . $e->getMessage());
    }

    // If all good, commit
    $conn->commit();

    // Redirect back to the queue with a success message
    header("Location: queue.php?message=Action+successful");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    error_log($e->getMessage());
    die("An error occurred: " . $e->getMessage() . " Please try again.");
} finally {
    $conn->autocommit(TRUE);
    $conn->close();
}
?>