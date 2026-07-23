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

    // Case 1: Route is for a specific head, e.g., "Accounting Office (Head)"
    if (preg_match('/^(.*) \(Head\)$/', $normalized_expected_dept, $matches)) {
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