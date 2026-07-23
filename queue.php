<?php
session_start();
if (!isset($_SESSION['logged_in'])) { header("Location: login.php"); exit(); }

require_once 'db_connect.php';

$voucher_found = null;
$search_error = "";
$success_msg = "";
$dept_role = $_SESSION['role'];
$username = $_SESSION['username'];
$is_head = $_SESSION['is_head'] ?? 0; // Get head status from session

// Get actual User ID for Audit Logs
$user_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
$user_stmt->bind_param("s", $username);
$user_stmt->execute();
$user_res = $user_stmt->get_result();
$user_id = ($user_res->num_rows > 0) ? $user_res->fetch_assoc()['user_id'] : NULL;
$user_stmt->close();

// NEW: Get base department role for heads to match against workflow steps
$base_dept_role = $dept_role;
if ($is_head) {
    $base_dept_role = trim(preg_replace('/\s*\(Head\)$/i', '', $base_dept_role));
}
// Also normalize dashes for consistency in comparisons
$base_dept_role = str_replace(['–', '—'], '-', $base_dept_role);

// NEW: Fetch DSS settings from the database
$dss_settings_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dss_%'");
$dss_settings = [];
while ($row = $dss_settings_res->fetch_assoc()) {
    $dss_settings[$row['setting_key']] = $row['setting_value'];
}

// 2. DETERMINE WORKFLOW INDEX DYNAMICALLY
$workflow_sequence = [];
$seq_res = $conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1 ORDER BY name ASC");
while ($row = $seq_res->fetch_assoc()) {
    $workflow_sequence[] = $row['name'];
}

$my_stage_index = -1;
$found_index = array_search($dept_role, $workflow_sequence);
if ($found_index !== false) {
    $my_stage_index = $found_index + 1; // 1-based index for stages
}
$total_stages = count($workflow_sequence);

// 3. FETCH ALL VOUCHERS PENDING APPROVAL IN CURRENT DEPARTMENT
$pending_vouchers = [];

if ($dept_role === 'MIS') {
    // MIS can see any document scanned into its queue, regardless of workflow stage, to allow for administrative override.
    // Fetch ARTA info using COALESCE for either document_type or voucher_type
    $sql = "
        SELECT DISTINCT 
            v.voucher_code, v.purpose, v.current_stage_index, v.status, v.date_submitted, v.custom_workflow, v.document_title,
            COALESCE(vt.arta_level, dt.arta_level) AS effective_arta_level,
            al_arta.processing_days,
            COALESCE(vt.name, dt.name) as effective_doc_type_name,
            u.full_name as requestor_name,
            u.role as origin_office
        FROM vouchers v 
        INNER JOIN audit_logs al ON v.voucher_code = al.voucher_code AND al.action_taken = 'Scan-to-Receive' AND al.department = ? AND al.processed_by_user_id = ?
        LEFT JOIN users u ON v.requestor_id = u.user_id
        LEFT JOIN document_types dt ON v.doc_type_id = dt.id
        LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
        LEFT JOIN arta_levels al_arta ON al_arta.level_name = COALESCE(vt.arta_level, dt.arta_level)
        WHERE
            v.status NOT IN ('Returned', 'Rejected', 'Paid', 'Ready for Release')
            AND NOT EXISTS (
                SELECT 1 FROM audit_logs al2 
                WHERE al2.voucher_code = v.voucher_code 
                AND al2.department = ?
                AND al2.action_taken IN ('Accepted', 'RETURNED', 'DECLINED')
            )
        ORDER BY al.log_id DESC
    ";
    $pending_stmt = $conn->prepare($sql);
    $pending_stmt->bind_param("sis", $dept_role, $user_id, $dept_role);
} else {
    // Regular signatories must follow the workflow sequence.
    // Fetch ARTA info using COALESCE for either document_type or voucher_type (using Nowdoc to prevent PHP parse errors)
    $sql = <<<'SQL'
        SELECT DISTINCT 
            v.voucher_code, v.purpose, v.current_stage_index, v.status, v.date_submitted, v.custom_workflow, v.document_title,
            COALESCE(vt.arta_level, dt.arta_level) AS effective_arta_level,
            al_arta.processing_days,
            COALESCE(vt.name, dt.name) as effective_doc_type_name,
            u.full_name as requestor_name,
            u.role as origin_office
        FROM vouchers v
        INNER JOIN audit_logs al ON v.voucher_code = al.voucher_code AND al.action_taken = 'Scan-to-Receive' AND al.department = ? AND al.processed_by_user_id = ?
        LEFT JOIN users u ON v.requestor_id = u.user_id
        LEFT JOIN document_types dt ON v.doc_type_id = dt.id
        LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
        LEFT JOIN arta_levels al_arta ON al_arta.level_name = COALESCE(vt.arta_level, dt.arta_level)
        WHERE
            (
                -- Case 1: Custom workflow step matches user's department
                (JSON_LENGTH(v.custom_workflow) > 0 AND REPLACE(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))), '–', '-'), '—', '-') = ?)

                -- Case 2: Custom workflow step is 'Department Head' AND the user is the head of the requestor's department
                OR (
                    JSON_LENGTH(v.custom_workflow) > 0 
                    AND JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))) = 'Department Head'
                    AND REPLACE(REPLACE(u.role, '–', '-'), '—', '-') = ? -- The requestor's department is the same as the current user's department
                    AND ? = 1 -- The current user is a head
                )

                -- NEW Case 2.5: Custom workflow step is for a specific department head, e.g., "Accounting (Head)"
                OR (
                    JSON_LENGTH(v.custom_workflow) > 0
                    AND JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))) LIKE '% (Head)'
                    AND ? = 1 -- The current user must be a head
                    AND ? = REPLACE(REPLACE(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))), ' (Head)', 1), '–', '-'), '—', '-') -- The user's role must match the department name part
                )

                -- Case 3: Fallback for default workflow (no JSON)
                OR ((v.custom_workflow IS NULL OR JSON_LENGTH(v.custom_workflow) = 0) AND v.current_stage_index = ?)
            )
            AND v.status NOT IN ('Returned', 'Rejected', 'Paid', 'Ready for Release')
            AND NOT EXISTS (
                SELECT 1 FROM audit_logs al2 
                WHERE al2.voucher_code = v.voucher_code 
                AND al2.department = ?
                AND al2.action_taken IN ('Accepted', 'RETURNED', 'DECLINED')
            )
        ORDER BY al.log_id DESC
SQL;
    $pending_stmt = $conn->prepare($sql);
    $pending_stmt->bind_param("sissiisis", $dept_role, $user_id, $base_dept_role, $base_dept_role, $is_head, $is_head, $base_dept_role, $my_stage_index, $dept_role);
}

$pending_stmt->execute();
$pending_res = $pending_stmt->get_result();

while($row = $pending_res->fetch_assoc()) {
    $pending_vouchers[] = $row;
}
$pending_stmt->close();

// 4. HANDLE VOUCHER SELECTION FROM QUEUE
if (isset($_GET['select_id']) && !empty($_GET['select_id'])) {
    $select_id = trim($_GET['select_id']);
    $v_stmt = $conn->prepare("
        SELECT 
            v.*, 
            u.full_name as requestor_full_name,
            u.role as origin_office,
            COALESCE(vt.name, dt.name) as effective_doc_type_name,
            COALESCE(vt.requirements, dt.requirements) as effective_requirements,
            COALESCE(vt.arta_level, dt.arta_level) AS effective_arta_level,
            al.processing_days
        FROM vouchers v
        LEFT JOIN users u ON v.requestor_id = u.user_id
        LEFT JOIN document_types dt ON v.doc_type_id = dt.id
        LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
        LEFT JOIN arta_levels al ON al.level_name = COALESCE(vt.arta_level, dt.arta_level)
        WHERE v.voucher_code = ?
    ");
    $v_stmt->bind_param("s", $select_id);
    $v_stmt->execute();
    $v_res = $v_stmt->get_result();
    $voucher_found = $v_res->fetch_assoc();
    $v_stmt->close();

    if (!$voucher_found) {
        $search_error = "Voucher ID not found.";
    }

    // --- DSS (Decision Support System) LOGIC ---
    $dss_suggestions = [];
    $dss_anomalies = [];
    $dss_history_notice = null; // NEW: Dedicated variable for the history notice

    if ($voucher_found) {
        $d_voucher_code = $voucher_found['voucher_code']; // Get current voucher code
        $d_requestor_id = $voucher_found['requestor_id'];
        $d_doc_type_id = $voucher_found['doc_type_id'];
        $d_voucher_type_id = $voucher_found['voucher_type_id'];
        $d_amount = $voucher_found['amount'];

        // 1. Suggestion based on historical approval rate for this document type
        $hist_stmt_sql = "SELECT status FROM vouchers WHERE ";
        $hist_params = [];
        $hist_types = "";
        if ($d_voucher_type_id) {
            $hist_stmt_sql .= "voucher_type_id = ?";
            $hist_params[] = $d_voucher_type_id;
            $hist_types .= "i";
        } elseif ($d_doc_type_id) {
            $hist_stmt_sql .= "doc_type_id = ?";
            $hist_params[] = $d_doc_type_id;
            $hist_types .= "i";
        }
        $hist_stmt_sql .= " AND status IN ('Approved', 'Paid', 'Ready for Release', 'Returned', 'Rejected')";
        
        $hist_stmt = $conn->prepare($hist_stmt_sql);
        if ($hist_stmt && !empty($hist_params)) {
            $hist_stmt->bind_param($hist_types, ...$hist_params);
            $hist_stmt->execute();
            $hist_res = $hist_stmt->get_result();
            $total_historical = $hist_res->num_rows;
            $approved_count = 0;
            while ($row = $hist_res->fetch_assoc()) {
                if (!in_array($row['status'], ['Returned', 'Rejected'])) {
                    $approved_count++;
                }
            }
            if ($total_historical > 0) { // Show suggestion even with one historical doc
                $approval_rate = round(($approved_count / $total_historical) * 100);
                $suggestion_text = "Historically, <strong>{$approval_rate}%</strong> of similar documents have been approved.";
                if ($total_historical < 5) {
                    $suggestion_text .= " <small>(Note: Based on a small sample size of {$total_historical} documents.)</small>";
                }
                $dss_suggestions[] = $suggestion_text;
            }
            $hist_stmt->close();
        }

        // 2. Anomaly: Amount check (for financial vouchers)
        if ($d_voucher_type_id && $d_amount > 0) {
            // Corrected query to exclude the current document from historical analysis
            $amt_stmt = $conn->prepare("SELECT AVG(amount) as avg_amt, STDDEV(amount) as std_dev, COUNT(*) as count FROM vouchers WHERE voucher_type_id = ? AND amount > 0 AND voucher_code != ?");
            $amt_stmt->bind_param("is", $d_voucher_type_id, $d_voucher_code);
            $amt_stmt->execute();
            $amt_res = $amt_stmt->get_result()->fetch_assoc();
            
            if ($amt_res && $amt_res['avg_amt'] > 0) {
                if ($amt_res['count'] >= 2 && $amt_res['std_dev'] !== null) {
                    // Standard deviation logic (for 2 or more historical docs)
                    if ($d_amount > ($amt_res['avg_amt'] + (2 * $amt_res['std_dev']))) {
                        $dss_anomalies[] = "The amount (<strong>₱" . number_format($d_amount, 2) . "</strong>) is significantly higher than the average (<strong>₱" . number_format($amt_res['avg_amt'], 2) . "</strong>) for this voucher type.";
                    }
                } elseif ($amt_res['count'] == 1) {
                    // Simple multiplier logic (for exactly 1 historical doc)
                    if ($d_amount > ($amt_res['avg_amt'] * 5)) { // e.g., if it's 5x larger
                        $dss_anomalies[] = "The amount (<strong>₱" . number_format($d_amount, 2) . "</strong>) is unusually high compared to the single previous transaction of <strong>₱" . number_format($amt_res['avg_amt'], 2) . "</strong>.";
                    }
                }
            }
            $amt_stmt->close();
        }

        // 3. UPGRADED: Requestor's return/rejection rate from configurable settings
        $dss_history_enabled = $dss_settings['dss_history_enabled'] ?? 0;
        if ($dss_history_enabled == 1) {
            $rejection_threshold = (int)($dss_settings['dss_rejection_count'] ?? 3);
            $submission_window = (int)($dss_settings['dss_submission_count'] ?? 10);

            // Query for the last N submissions to get the number of returned/rejected ones
            $req_hist_stmt = $conn->prepare(
                "SELECT SUM(CASE WHEN status IN ('Returned', 'Rejected') THEN 1 ELSE 0 END) as returned_rejected_count 
                 FROM (SELECT status FROM vouchers WHERE requestor_id = ? AND voucher_code != ? ORDER BY date_submitted DESC LIMIT ?) as recent_vouchers"
            );
            $req_hist_stmt->bind_param("isi", $d_requestor_id, $d_voucher_code, $submission_window);
            $req_hist_stmt->execute();
            $returned_count = $req_hist_stmt->get_result()->fetch_assoc()['returned_rejected_count'] ?? 0;
            $req_hist_stmt->close();

            if ($returned_count >= $rejection_threshold) {
                $dss_history_notice = "For your information, this user has had <strong>{$returned_count} of their last {$submission_window}</strong> submissions returned for corrections. Please pay close attention to the attached documents and ensure they match the requirements checklist.";
            }
        }

        // 4. Anomaly: Amount outside of general financial guidelines
        if ($d_amount > 0) {
            // Fetch general guidelines from system_settings
            $guidelines_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('general_min_amount', 'general_max_amount')");
            $guidelines = [];
            while ($row = $guidelines_res->fetch_assoc()) {
                $guidelines[$row['setting_key']] = $row['setting_value'];
            }
            
            $general_min = $guidelines['general_min_amount'] ?? null;
            $general_max = $guidelines['general_max_amount'] ?? null;

            if (!empty($general_min) && is_numeric($general_min) && $d_amount < $general_min) {
                $dss_anomalies[] = "The amount (<strong>₱" . number_format($d_amount, 2) . "</strong>) is below the general guideline minimum of <strong>₱" . number_format($general_min, 2) . "</strong>.";
            }
            if (!empty($general_max) && is_numeric($general_max) && $d_amount > $general_max) {
                $dss_anomalies[] = "The amount (<strong>₱" . number_format($d_amount, 2) . "</strong>) exceeds the general guideline maximum of <strong>₱" . number_format($general_max, 2) . "</strong>.";
            }
        }
    }
}

// 5. APPROVAL LOGIC (Backend Verification & TIME OUT)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    $processed_id = $_POST['voucher_id'];
    $remarks = trim($_POST['remarks']);

        // --- START: REQUIREMENT CHECKLIST VALIDATION ---
        if ($action === 'Accept') {
            $all_reqs_array = isset($_POST['all_reqs']) ? json_decode($_POST['all_reqs'], true) : [];
            $checked_reqs_array = $_POST['checked_reqs'] ?? [];

            // This validation runs only if the document actually has requirements.
            if (!empty($all_reqs_array) && count($checked_reqs_array) < count($all_reqs_array)) {
                $search_error = "Validation Failed: All requirements must be checked before accepting the document.";
            }
        }
        // --- END: REQUIREMENT CHECKLIST VALIDATION ---

        // Only proceed if there are no validation errors.
        if (empty($search_error)) {
            // --- NEW: Build detailed remarks from checklist for Return/Decline actions ---
            if (in_array($action, ['Return', 'Decline']) && isset($_POST['all_reqs']) && !empty($_POST['all_reqs'])) {
                $all_reqs_array = json_decode($_POST['all_reqs'], true) ?? [];
                $checked_reqs_array = $_POST['checked_reqs'] ?? [];
                $missing_reqs_array = array_diff($all_reqs_array, $checked_reqs_array);

                $feedback = "";
                if (!empty($checked_reqs_array)) {
                    $feedback .= "--- COMPLETED REQUIREMENTS ---\n";
                    foreach ($checked_reqs_array as $item) { $feedback .= " - " . trim($item) . "\n"; }
                }
                if (!empty($missing_reqs_array)) {
                    $feedback .= "\n--- MISSING/INCOMPLETE REQUIREMENTS ---\n";
                    foreach ($missing_reqs_array as $item) { $feedback .= " - " . trim($item) . "\n"; }
                }

                // Prepend feedback to manual remarks
                if (!empty($feedback)) {
                    $feedback .= "\n--- ADDITIONAL NOTES ---\n";
                    $remarks = $feedback . $remarks;
                }
            }
            
            $verify_stmt = $conn->prepare("SELECT current_stage_index, custom_workflow FROM vouchers WHERE voucher_code = ?");
            // The previous line was redundant and overwritten. The correct statement is below.
            $verify_stmt = $conn->prepare("SELECT current_stage_index, custom_workflow, workflow_type FROM vouchers WHERE voucher_code = ? FOR UPDATE");
            $verify_stmt->bind_param("s", $processed_id); // ADDED: Bind parameter for the voucher_code
            $verify_stmt->execute();
            $verify_res = $verify_stmt->get_result();
            $verify_row = $verify_res->fetch_assoc();
            $verify_stmt->close();

            // Get the requestor's ID for notifications
            $req_id_stmt = $conn->prepare("SELECT requestor_id FROM vouchers WHERE voucher_code = ?");
            $req_id_stmt->bind_param("s", $processed_id);
            $req_id_stmt->execute();
            $requestor_id_for_notif = $req_id_stmt->get_result()->fetch_assoc()['requestor_id'] ?? 0;
            $req_id_stmt->close();


            // Get custom workflow or fallback to global settings
            $custom_workflow = json_decode($verify_row['custom_workflow'], true);
            if (empty($custom_workflow)) { $custom_workflow = $workflow_sequence; }
            
            // Get the expected department from the workflow (0-indexed array)
            $current_stage_0_indexed = $verify_row['current_stage_index'] - 1;
            $expected_dept = isset($custom_workflow[$current_stage_0_indexed]) ? $custom_workflow[$current_stage_0_indexed] : null;
            
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
                // $requestor_id_for_notif is already available from a few lines above.
                $req_dept_stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
                $req_dept_stmt->bind_param("i", $requestor_id_for_notif);
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

            if (!$verify_row || !$is_authorized_to_process) {
                $search_error = "Validation Failed: Voucher is no longer in your department's queue.";
            } else {
                $new_status = "";
                $log_action = "";
                
                if ($action == "Accept") {
                    $log_action = "Accepted";
                    
                    if ($verify_row['current_stage_index'] >= count($custom_workflow)) {
                        // This is the final stage. Get the custom final status text from the document type.
                        // We need to fetch the doc_type_id from the voucher itself to get its final_status_text
                        $doc_type_id_stmt = $conn->prepare("SELECT v.doc_type_id, dt.final_status_text FROM vouchers v LEFT JOIN document_types dt ON v.doc_type_id = dt.id WHERE v.voucher_code = ?");
                        $doc_type_id_stmt->bind_param("s", $processed_id);
                        $doc_type_id_stmt->execute();
                        $doc_type_data = $doc_type_id_stmt->get_result()->fetch_assoc();
                        $doc_type_id_stmt->close();

                        $final_status_to_use = 'Ready for Release'; // Default for approval workflows
                        if ($verify_row['workflow_type'] === 'Transfer') {
                            $final_status_to_use = 'Received';
                        } elseif ($doc_type_data && !empty($doc_type_data['final_status_text'])) {
                            $final_status_to_use = $doc_type_data['final_status_text'];
                        }

                        $upd_stmt = $conn->prepare("UPDATE vouchers SET status = ? WHERE voucher_code = ?");
                        $upd_stmt->bind_param("ss", $final_status_to_use, $processed_id);

                        // Create notification for final approval
                        $notif_message = "Good news! Your document " . $processed_id . " has been fully approved and is now " . $final_status_to_use . ".";
                        $notif_link = "track.php?track_id=" . urlencode($processed_id);
                        create_notification($conn, $requestor_id_for_notif, $notif_message, $notif_link);
                    } else {
                        $upd_stmt = $conn->prepare("UPDATE vouchers SET status = 'Processing', current_stage_index = current_stage_index + 1 WHERE voucher_code = ?"); // Increment stage
                        $upd_stmt->bind_param("s", $processed_id);

                        // --- START: NOTIFY NEXT SIGNATORY ---
                        // The next stage index is the current one (since we're about to increment it in the DB)
                        $next_stage_index_0_based = $verify_row['current_stage_index']; 
                        if (isset($custom_workflow[$next_stage_index_0_based])) {
                            $next_dept = $custom_workflow[$next_stage_index_0_based];
                            $users_to_notify_stmt = null;

                            if ($next_dept === 'Department Head') {
                                // Get the requestor's department to find the correct head
                                $req_dept_stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
                                $req_dept_stmt->bind_param("i", $requestor_id_for_notif);
                                $req_dept_stmt->execute();
                                $requestor_department = $req_dept_stmt->get_result()->fetch_assoc()['role'] ?? null;
                                $req_dept_stmt->close();

                                if ($requestor_department) {
                                    // Notify only the head of the requestor's department
                                    $users_to_notify_stmt = $conn->prepare("SELECT user_id FROM users WHERE role = ? AND is_head = 1");
                                    $users_to_notify_stmt->bind_param("s", $requestor_department);
                                }
                            } else {
                                // Use the global helper function to get the correct notification statement.
                                $users_to_notify_stmt = prepare_notification_statement_for_department($conn, $next_dept);
                            }

                            if ($users_to_notify_stmt) {
                                $users_to_notify_stmt->execute();
                                $users_res = $users_to_notify_stmt->get_result();
                                $signatory_notif_message = "Heads up! Document " . $processed_id . " has been processed by " . $dept_role . " and is now en route to your office.";
                                $signatory_notif_link = "queue.php";
                                while ($user_row = $users_res->fetch_assoc()) { create_notification($conn, $user_row['user_id'], $signatory_notif_message, $signatory_notif_link); }
                                $users_to_notify_stmt->close();
                            }
                        }
                        // --- END: NOTIFY NEXT SIGNATORY ---
                    }
                } elseif ($action == "Return") {
                    $new_status = "Returned";
                    $log_action = "RETURNED";
                    
                    $upd_stmt = $conn->prepare("UPDATE vouchers SET status = ? WHERE voucher_code = ?");
                    $upd_stmt->bind_param("ss", $new_status, $processed_id);

                    // Create notification for returned document
                    $notif_message = "Your document " . $processed_id . " was returned by the " . $dept_role . " office. Click to view remarks and resubmit.";
                    $notif_link = "resubmit.php?edit_id=" . urlencode($processed_id);
                    create_notification($conn, $requestor_id_for_notif, $notif_message, $notif_link);
                } elseif ($action == "Decline") { // New 'Decline' action
                    $new_status = "Rejected"; // 'Rejected' is the status for a permanent decline
                    $log_action = "DECLINED";
                    
                    $upd_stmt = $conn->prepare("UPDATE vouchers SET status = ? WHERE voucher_code = ?");
                    $upd_stmt->bind_param("ss", $new_status, $processed_id);

                    // Create notification for rejected document
                    $notif_message = "Your document " . $processed_id . " has been rejected by the " . $dept_role . " office. The workflow has been terminated.";
                    $notif_link = "track.php?track_id=" . urlencode($processed_id);
                    create_notification($conn, $requestor_id_for_notif, $notif_message, $notif_link);
                }
                
                if ($upd_stmt->execute()) {
                    // Insert the final action log. This stamps the exact TIME OUT.
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs (voucher_code, department, action_taken, remarks, processed_by_user_id) VALUES (?, ?, ?, ?, ?)");
                    if ($log_stmt) {
                        $log_stmt->bind_param("ssssi", $processed_id, $dept_role, $log_action, $remarks, $user_id);
                        $log_stmt->execute();
                        $log_stmt->close();
                    }
                    $success_msg = "<strong>TIME-OUT RECORDED:</strong> Voucher has been successfully processed as <strong>" . strtoupper($action) . "</strong>.";
                    header("Refresh: 2; URL=queue.php"); 
                } else {
                    $search_error = "Database Error: Could not update voucher.";
                }
                $upd_stmt->close();
            }
            $voucher_found = null; 
        }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAAP - Approval Queue</title>
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="queue.css">
</head>
<body>

<?php include('sidebar.php'); ?>

<div class="main-content">
    <div class="page-header">
        <h1>Decision Board / Approval Queue</h1>
        <p>Signatory Station: <strong style="color: var(--naap-navy);"><?php echo htmlspecialchars($dept_role); ?></strong></p>
    </div>

    <?php if($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>
    <?php if($search_error): ?> <div class="alert alert-error"><?php echo $search_error; ?></div> <?php endif; ?>

    <?php if(!$voucher_found): ?>
    
    <div style="margin-bottom: 30px;">
        <h2 style="color: var(--naap-navy); margin-bottom: 20px;">Pending Approval Queue</h2>
        <p style="color: var(--text-muted); margin-bottom: 25px;">These vouchers have been received and are awaiting your approval. Click any item to review and process.</p>
        
        <?php if(count($pending_vouchers) > 0): ?>
            <div>
                <?php foreach($pending_vouchers as $pv): ?>
                <a href="queue.php?select_id=<?php echo urlencode($pv['voucher_code']); ?>" style="text-decoration: none;">
                    <div class="pending-card">
                        <div class="pending-card-main">
                            <div class="pending-card-id"><?php echo htmlspecialchars($pv['voucher_code']); ?></div>
                            <div class="pending-card-title"><?php echo htmlspecialchars($pv['document_title']); ?></div>
                        </div>
                        <div class="pending-card-details">
                            <div><span>Type:</span> <?php echo htmlspecialchars($pv['effective_doc_type_name'] ?? 'N/A'); ?></div>
                            <div><span>From:</span> <?php echo htmlspecialchars($pv['requestor_name'] ?? 'N/A'); ?> (<?php echo htmlspecialchars($pv['origin_office'] ?? 'N/A'); ?>)</div>
                            <div><span>Submitted:</span> <?php echo format_db_timestamp($pv['date_submitted'], 'M d, Y'); ?></div>
                        </div>
                        <div class="pending-card-action">
                            Click to Review &rarr;
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="background: #f0fdf4; border: 2px dashed #10b981; border-radius: 8px; padding: 40px; text-align: center;">
                <h3 style="color: #059669; margin-top: 0;">Queue is Empty</h3>
                <p style="color: #667e22; margin-bottom: 0;">No vouchers awaiting your approval at this time. They will appear here once received by the Receiving Station.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <?php endif; ?>

    <?php if($voucher_found): ?>
    <div style="margin-bottom: 15px;">
        <a href="queue.php" style="color: var(--text-muted); text-decoration: none; font-weight: bold; font-size: 1.1rem;">&larr; Back to Queue</a>
    </div>

    <div class="queue-container">
        <div class="details-panel">
            <h3 style="color: var(--naap-navy); margin-top: 0;">Voucher Review</h3>

            <?php if ($dss_history_notice): ?>
                <div class="dss-history-box">
                    <strong>Decision Support: User History</strong>
                    <p><?php echo $dss_history_notice; ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($dss_anomalies)): ?>
                <div class="dss-warning-box">
                    <strong>⚠️ Anomaly Detected: Please review with extra care.</strong>
                    <ul>
                        <?php foreach ($dss_anomalies as $anomaly): ?>
                            <li><?php echo $anomaly; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($dss_suggestions)): ?>
                <div class="dss-suggestion-box">
                    <strong>Decision Support:</strong>
                    <?php foreach ($dss_suggestions as $suggestion): ?>
                        <p><?php echo $suggestion; ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="details-grid">
                <div class="info-row">
                    <label>Voucher ID</label>
                    <span style="color: var(--naap-navy);"><?php echo htmlspecialchars($voucher_found['voucher_code']); ?></span>
                </div>
                <div class="info-row">
                    <label>Document Type</label>
                    <span><?php echo htmlspecialchars($voucher_found['effective_doc_type_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <label>Created By</label>
                    <span style="font-weight: 600;"><?php echo htmlspecialchars($voucher_found['requestor_full_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <label>Origin Office</label>
                    <span><?php echo htmlspecialchars($voucher_found['origin_office'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <label>Date Submitted</label>
                    <span><?php echo format_db_timestamp($voucher_found['date_submitted'], 'Y-m-d'); ?></span>
                </div>
                <?php if (!empty($voucher_found['effective_arta_level'])): ?>
                <div class="info-row">
                    <label>ARTA Process Time</label>
                    <span><?php echo htmlspecialchars($voucher_found['effective_arta_level'] ?? 'N/A'); ?> (<?php echo $voucher_found['processing_days'] ?? '0'; ?> Days)</span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="info-row">
                <label>Document Title</label>
                <span><?php echo htmlspecialchars($voucher_found['document_title']); ?></span>
            </div>

            <?php if (!empty($voucher_found['reference_number'])): ?>
            <div class="info-row">
                <label>Reference #</label>
                <span><?php echo htmlspecialchars($voucher_found['reference_number']); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($voucher_found['tags'])): ?>
            <div class="info-row">
                <label>Tags</label>
                <span><?php echo htmlspecialchars($voucher_found['tags']); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($voucher_found['amount'])): ?>
            <div class="info-row">
                <label>Amount</label>
                <span style="font-weight: bold; color: #10b981;">PHP <?php echo number_format($voucher_found['amount'], 2); ?></span>
            </div>
            <?php endif; ?>

            <div class="info-row" style="border-bottom: none;">
                <label>Purpose / Description
                </label>
                <p style="margin-top: 5px; line-height: 1.5; color: var(--text-dark); background: var(--bg-gray); padding: 15px; border-radius: 6px; border: 1px solid var(--border-light);"><?php echo htmlspecialchars($voucher_found['purpose']); ?></p>
            </div>

            <?php
            // Corrected logic for displaying requirements checklist
            $requirements = !empty($voucher_found['effective_requirements']) ? json_decode($voucher_found['effective_requirements'], true) : [];
            if (is_array($requirements) && !empty($requirements)) {
            ?>
            <div class="info-row requirements-checklist">
                <label>Requirements Checklist</label>
                <div class="checklist-items">
                    <?php foreach ($requirements as $req): 
                        // Use a unique ID to avoid collisions
                        $req_id = 'req_' . md5($voucher_found['voucher_code'] . $req);
                    ?>
                        <div class="check-item">
                            <input type="checkbox" name="checked_reqs[]" value="<?php echo htmlspecialchars($req); ?>" id="<?php echo $req_id; ?>" form="decisionForm">
                            <label for="<?php echo $req_id; ?>"><?php echo htmlspecialchars($req); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="all_reqs" value='<?php echo htmlspecialchars(json_encode($requirements)); ?>' form="decisionForm">
            </div>
            <?php 
            } else { 
            ?>
                <div class="info-row"><label>Requirements Checklist</label><p style="margin: 5px 0; color: var(--text-muted);">No specific requirements defined for this document type.</p></div>
            <?php 
            } 
            ?>
        </div>

        <div class="action-panel">
            <h3 style="color: var(--naap-navy); margin-top: 0;">Release Document</h3>
            
            <?php if ($voucher_found['requestor_id'] == $user_id): ?>
                <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 20px; text-align: center; border-radius: 4px;">
                    <strong style="color: #92400e;">Conflict of Interest</strong>
                    <p style="color: #92400e; font-size: 0.9rem; margin: 5px 0 0 0;">You cannot process a document that you submitted.</p>
                </div>
            <?php else: ?>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">
                    Acting as: <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Signatory'); ?></strong>. Submitting a decision will record your <strong>Time Out</strong>.
                </p>
                <form method="POST" id="decisionForm">
                    <input type="hidden" name="voucher_id" value="<?php echo htmlspecialchars($voucher_found['voucher_code']); ?>">
                    <label style="font-weight: 600; font-size: 0.9rem; display: block; margin-bottom: 5px; color: var(--text-dark);">Remarks / Notes</label>
                    <textarea name="remarks" id="remarksTextarea" rows="4" placeholder="Required if returning or declining..."></textarea>
                    <button type="submit" name="action" value="Accept" id="acceptBtn" class="btn-action btn-approve" <?php if (!empty($requirements)) echo 'disabled'; ?>>Accept & Release</button>
                    <button type="submit" name="action" value="Return" id="returnBtn" class="btn-action btn-return" disabled>Return to Sender</button>
                    <button type="submit" name="action" value="Decline" id="declineBtn" class="btn-action btn-reject" disabled>Decline</button>
                </form>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>

</div>

<?php
$conn->close();
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Only run this script if we are in the voucher review view
    const decisionForm = document.getElementById('decisionForm');
    if (!decisionForm) return;

    const workflowType = '<?php echo $voucher_found['workflow_type'] ?? 'Approval'; ?>';
    const remarksTextarea = document.getElementById('remarksTextarea');
    const acceptBtn = document.getElementById('acceptBtn');
    const returnBtn = document.getElementById('returnBtn');
    const declineBtn = document.getElementById('declineBtn');
    const requirementCheckboxes = document.querySelectorAll('.requirements-checklist input[type="checkbox"]');
    const totalRequirements = requirementCheckboxes.length;

    function validateFormState() {
        const hasRemarks = remarksTextarea.value.trim().length > 0;

        // For 'Approval' workflows, remarks are required for Return/Decline.
        // For 'Transfer' workflows, they are not.
        if (workflowType === 'Transfer') {
            returnBtn.disabled = false;
            declineBtn.disabled = false;
        } else {
            returnBtn.disabled = !hasRemarks;
            declineBtn.disabled = !hasRemarks;
        }

        // Validate Accept button based on checklist requirements
        if (totalRequirements > 0) {
            const checkedRequirements = document.querySelectorAll('.requirements-checklist input[type="checkbox"]:checked').length;
            acceptBtn.disabled = (checkedRequirements !== totalRequirements);
        }
    }

    // Add event listeners
    remarksTextarea.addEventListener('input', validateFormState);
    requirementCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', validateFormState);
    });

    // Initial validation on page load to set the correct state
    validateFormState();
});
</script>

</body>
</html>