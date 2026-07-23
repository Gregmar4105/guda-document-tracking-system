<?php
session_start();
if (!isset($_SESSION['logged_in'])) { header("Location: login.php"); exit(); }

require_once 'db_connect.php';

// GET CURRENT USER ID (FIX: This needs to be available for both GET and POST)
$current_username = $_SESSION['username'];
$id_stmt = $conn->prepare("SELECT user_id, role, is_head FROM users WHERE username = ?");
$id_stmt->bind_param("s", $current_username);
$id_stmt->execute();
$id_result = $id_stmt->get_result();
if ($id_result->num_rows > 0) {
    $user_data = $id_result->fetch_assoc();
    $requestor_id = $user_data['user_id'];
    $requestor_role = $user_data['role'];
    $requestor_is_head = $user_data['is_head'];
} else {
    die("Error: Logged in user not found in the database.");
}
$id_stmt->close();

// Fetch signatory departments for the workflow builder
$signatory_departments = [];
$depts_res = $conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1 ORDER BY name ASC");
while ($dept_row = $depts_res->fetch_assoc()) {
    $signatory_departments[] = $dept_row['name'];
}

// Fetch document types for the dropdown
$document_types = [];
$types_res = $conn->query("SELECT id, name FROM document_types WHERE is_active = 1 ORDER BY name ASC");
while ($type_row = $types_res->fetch_assoc()) { // Add arta_level to this fetch
    $document_types[] = $type_row;
}

// Fetch financial voucher types for the dynamic dropdown
$voucher_types = [];
$v_types_res = $conn->query("SELECT id, name, arta_level, requirements, default_workflow FROM voucher_types WHERE is_active = 1 ORDER BY name ASC");
while ($v_type_row = $v_types_res->fetch_assoc()) {
    $voucher_types[] = $v_type_row;
}

// Fetch ARTA levels for the dropdown
$all_arta_levels = [];
$arta_levels_map = []; // For JS
$arta_res = $conn->query("SELECT level_name, processing_days FROM arta_levels ORDER BY processing_days ASC");
while ($arta_row = $arta_res->fetch_assoc()) {
    $all_arta_levels[] = $arta_row['level_name'];
    $arta_levels_map[$arta_row['level_name']] = $arta_row['processing_days'];
}

// Get the ID of the default financial doc type
$default_financial_doc_type_id = null;
$fin_type_stmt = $conn->query("SELECT id FROM document_types WHERE is_system_default = 1 AND name = 'Financial Voucher' LIMIT 1");
if ($fin_type_stmt && $fin_type_row = $fin_type_stmt->fetch_assoc()) {
    $default_financial_doc_type_id = $fin_type_row['id'];
}

$db_error = "";
$success_msg = "";
$show_modal = false;
$new_voucher_id = "";
$tracking_url = "";
$edit_mode = false;
$original_voucher_id = "";

$arta_deadline = null; // Initialize ARTA deadline
$return_remarks = ""; // Initialize return remarks
// Initialize form variables
$doc_title = "";
$doc_type_id = null;
$reference_number = "";
$tags = "";
$purpose = "";
$amount = null;
$budget_code = "";
$voucher_type_id = null;
$custom_workflow_json = "[]";
$custom_workflow_arr = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 3. CAPTURE FORM DATA
    $original_voucher_id = $_POST['original_voucher_id'] ?? 'N/A';
    $doc_title = trim($_POST['document_title'] ?? '');
    $doc_type_id = $_POST['doc_type_id'] ?? null;
    $arta_level = null; // Will be determined below
    $reference_number = trim($_POST['reference_number'] ?? null);
    $tags = trim($_POST['tags'] ?? null);
    $purpose = trim($_POST['purpose'] ?? '');
    
    // Financial fields
    $amount = isset($_POST['has_financial']) ? ($_POST['amount'] ?? null) : null;
    $voucher_type_id = isset($_POST['has_financial']) ? ($_POST['voucher_type_id'] ?? null) : null;
    $budget_code = isset($_POST['has_financial']) ? trim($_POST['budget_code'] ?? null) : null;

    $custom_workflow_json = $_POST['custom_workflow'] ?? '[]';
    $custom_workflow_arr = json_decode($custom_workflow_json, true) ?? [];
    
    // Fetch ARTA level for the selected document type
    if ($doc_type_id !== null) {
        $arta_level_stmt = $conn->prepare("SELECT arta_level FROM document_types WHERE id = ?");
        $arta_level_stmt->bind_param("i", $doc_type_id);
        $arta_level_stmt->execute();
        $arta_level = $arta_level_stmt->get_result()->fetch_assoc()['arta_level'] ?? 'Simple';
        $arta_level_stmt->close();
    }

    // If it's a financial transaction, use the ARTA level from the selected voucher type
    if (isset($_POST['has_financial']) && $voucher_type_id) {
        $fin_arta_stmt = $conn->prepare("SELECT arta_level FROM voucher_types WHERE id = ?");
        $fin_arta_stmt->bind_param("i", $voucher_type_id);
        $fin_arta_stmt->execute();
        if ($fin_arta_res = $fin_arta_stmt->get_result()) {
            if ($fin_arta_row = $fin_arta_res->fetch_assoc()) { $arta_level = $fin_arta_row['arta_level']; }
        }
        $fin_arta_stmt->close();
        $arta_deadline = calculateARTADeadline(date('Y-m-d'), $arta_level, $conn); // Recalculate with specific financial ARTA
    }
    $arta_deadline = calculateARTADeadline(date('Y-m-d'), $arta_level, $conn);

    // Get processing days for the modal
    $processing_days = 0;
    if ($arta_level) {
        $arta_days_stmt = $conn->prepare("SELECT processing_days FROM arta_levels WHERE level_name = ?");
        $arta_days_stmt->bind_param("s", $arta_level);
        $arta_days_stmt->execute();
        if ($arta_days_res = $arta_days_stmt->get_result()) {
            if ($arta_days_row = $arta_days_res->fetch_assoc()) { $processing_days = $arta_days_row['processing_days']; }
        }
        $arta_days_stmt->close();
    }

    // 4. GENERATE NEW DOCUMENT ID FOR RESUBMISSION
    $new_voucher_id = "NAAP-" . date("Y") . "-" . rand(1000, 9999);

    // 5. SAVE NEW DOCUMENT TO DATABASE
    $insert_stmt = $conn->prepare("INSERT INTO vouchers (voucher_code, requestor_id, document_title, doc_type_id, voucher_type_id, reference_number, tags, amount, budget_code, purpose, status, current_stage_index, custom_workflow, arta_deadline) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Review', 1, ?, ?)");
    $insert_stmt->bind_param("sisiissdssss", $new_voucher_id, $requestor_id, $doc_title, $doc_type_id, $voucher_type_id, $reference_number, $tags, $amount, $budget_code, $purpose, $custom_workflow_json, $arta_deadline);
    
    if($insert_stmt->execute()) {
        $insert_stmt->close(); // Close the statement early

        // --- AUTO-SKIP LOGIC ---
        // If the first step of the workflow is the requestor's own department, auto-skip it.
        if (!empty($custom_workflow_arr) && $custom_workflow_arr[0] === 'Department Head' && $requestor_is_head == 1) {
            // Increment the stage index
            $skip_stmt = $conn->prepare("UPDATE vouchers SET current_stage_index = current_stage_index + 1 WHERE voucher_code = ?");
            $skip_stmt->bind_param("s", $new_voucher_id);
            $skip_stmt->execute();
            $skip_stmt->close();

            // Log this automatic action for a clear audit trail. The department is the requestor's actual department.
            $log_skip_stmt = $conn->prepare("INSERT INTO audit_logs (voucher_code, department, action_taken, remarks, processed_by_user_id) VALUES (?, ?, 'AUTO-SKIPPED', 'Requestor is a Department Head, auto-skipping first approval step.', ?)");
            $log_skip_stmt->bind_param("ssi", $new_voucher_id, $requestor_role, $requestor_id);
            $log_skip_stmt->execute();
            $log_skip_stmt->close();
        }
        
        // 7. LOG RESUBMISSION ACTION
        $log_stmt = $conn->prepare("INSERT INTO audit_logs (voucher_code, department, action_taken, remarks, processed_by_user_id) VALUES (?, 'Requestor', 'RESUBMITTED', ?, ?)");
        $resubmit_remark = "Resubmitted after return (Original ID: " . $original_voucher_id . ")";
        $log_stmt->bind_param("ssi", $new_voucher_id, $resubmit_remark, $requestor_id);
        $log_stmt->execute();
        $log_stmt->close();

        // 8. UPDATE ORIGINAL DOCUMENT'S STATUS TO 'Resubmitted'
        // This prevents it from being resubmitted again.
        $update_orig_stmt = $conn->prepare("UPDATE vouchers SET status = 'Resubmitted' WHERE voucher_code = ?");
        $update_orig_stmt->bind_param("s", $original_voucher_id);
        $update_orig_stmt->execute();
        $update_orig_stmt->close();
        
        // Create a notification for the requestor
        $notif_message = "Your document " . $new_voucher_id . " has been successfully resubmitted and is now pending review.";
        $notif_link = "track.php?track_id=" . urlencode($new_voucher_id);
        create_notification($conn, $requestor_id, $notif_message, $notif_link);

        // Notify the first signatory department
        if (!empty($custom_workflow_arr)) {
            $next_stage_index_0_based = 0;
            // If the first step was auto-skipped, notify the *second* step
            if ($custom_workflow_arr[0] === 'Department Head' && $requestor_is_head == 1) {
                $next_stage_index_0_based = 1;
            }

            if (isset($custom_workflow_arr[$next_stage_index_0_based])) {
                $next_dept = $custom_workflow_arr[$next_stage_index_0_based];

                $users_to_notify_stmt = null;

                if ($next_dept === 'Department Head') {
                    // Notify only the head of the requestor's department
                    $users_to_notify_stmt = $conn->prepare("SELECT user_id FROM users WHERE role = ? AND is_head = 1");
                    $users_to_notify_stmt->bind_param("s", $requestor_role);
                } else {
                    // Use the global helper function to get the correct notification statement.
                    $users_to_notify_stmt = prepare_notification_statement_for_department($conn, $next_dept);
                }

                if ($users_to_notify_stmt) {
                    $users_to_notify_stmt->execute();
                    $users_res = $users_to_notify_stmt->get_result();
                    $signatory_notif_message = "Heads up! A new document (" . $new_voucher_id . ") from " . $_SESSION['full_name'] . " is en route to your office.";
                    $signatory_notif_link = "queue.php";
                    while ($user_row = $users_res->fetch_assoc()) { create_notification($conn, $user_row['user_id'], $signatory_notif_message, $signatory_notif_link); }
                    $users_to_notify_stmt->close();
                }
            }
        }
        
        // Redirect to the new document's tracking page
        header("Location: track.php?track_id=" . urlencode($new_voucher_id) . "&resubmitted=true");
        exit();
    } else {
        $db_error = "Database Error: " . $conn->error;
    }
}

// 9. LOAD ORIGINAL DOCUMENT DATA (if editing a returned document)
if (isset($_GET['edit_id']) && !empty($_GET['edit_id'])) {
    $edit_mode = true;
    $original_voucher_id = $_GET['edit_id'];
    
    $load_stmt = $conn->prepare("SELECT * FROM vouchers WHERE voucher_code = ? AND status = 'Returned'");
    $load_stmt->bind_param("s", $original_voucher_id);
    $load_stmt->execute();
    $load_result = $load_stmt->get_result();
    
    if ($load_result->num_rows > 0) {
        $original_data = $load_result->fetch_assoc();
        $doc_title = $original_data['document_title'];
        $doc_type_id = $original_data['doc_type_id'];
        // If the original doc_type_id was null (financial voucher), set it to the system's financial doc type ID.
        // This allows the doc_type_select to correctly display 'Financial Voucher' as a disabled option.
        if ($doc_type_id === null && $original_data['voucher_type_id'] !== null && $default_financial_doc_type_id !== null) {
            $doc_type_id = $default_financial_doc_type_id;
        }

        $reference_number = $original_data['reference_number'];
        $tags = $original_data['tags'];
        $purpose = $original_data['purpose'];
        // Format amount if it's not null, otherwise set to empty string
        $amount = $original_data['amount'] !== null ? number_format($original_data['amount'], 2, '.', '') : '';

        $budget_code = $original_data['budget_code'];
        $voucher_type_id = $original_data['voucher_type_id'];
        $custom_workflow_json = $original_data['custom_workflow'];
        $arta_deadline = $original_data['arta_deadline']; // Load original deadline
        $custom_workflow_arr = json_decode($custom_workflow_json, true) ?? [];

        // If it's a financial transaction, use the ARTA level from the selected voucher type
        if ($original_data['voucher_type_id']) {
            $fin_arta_stmt = $conn->prepare("SELECT arta_level FROM voucher_types WHERE id = ?");
            $fin_arta_stmt->bind_param("i", $original_data['voucher_type_id']);
            $fin_arta_stmt->execute();
            if ($fin_arta_res = $fin_arta_stmt->get_result()) {
                if ($fin_arta_row = $fin_arta_res->fetch_assoc()) { $arta_level = $fin_arta_row['arta_level']; }
            }
            $fin_arta_stmt->close();
            $processing_days = $arta_levels_map[$arta_level] ?? 0; // Get processing days for modal
        }

        // PRIVATE ACCOUNT PER USER: Check if the current user is the requestor or MIS
        if ($_SESSION['role'] !== 'MIS' && $original_data['requestor_id'] !== $requestor_id) {
            die("Error: You are not authorized to resubmit this document.");
        }

        // Fetch the latest return remarks
        $remarks_stmt = $conn->prepare("SELECT remarks FROM audit_logs WHERE voucher_code = ? AND action_taken = 'RETURNED' ORDER BY created_at DESC LIMIT 1");
        $remarks_stmt->bind_param("s", $original_voucher_id);
        $remarks_stmt->execute();
        $remarks_res = $remarks_stmt->get_result();
        if ($remarks_row = $remarks_res->fetch_assoc()) {
            $return_remarks = $remarks_row['remarks'];
        }
        $remarks_stmt->close();
    } else {
        die("Error: Returned document not found or is not eligible for resubmission.");
    }
    $load_stmt->close();
}

// NEW: Parse remarks to find missing requirements for a dedicated display
$missing_requirements_list = [];
if (strpos($return_remarks, '--- MISSING/INCOMPLETE REQUIREMENTS ---') !== false) {
    // Use regex to find the block of text under the "MISSING/INCOMPLETE" header
    if (preg_match('/--- MISSING\/INCOMPLETE REQUIREMENTS ---\s*(.*?)(---|$)/s', $return_remarks, $missing_matches)) {
        $missing_block = trim($missing_matches[1]);
        $lines = preg_split('/\r\n|\r|\n/', $missing_block);
        foreach ($lines as $line) {
            // Check for lines that start with '- '
            if (strpos(trim($line), '- ') === 0) {
                $missing_requirements_list[] = trim(substr(trim($line), 2));
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NAAP - Resubmit Returned Document</title>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo filemtime('sidebar.css'); ?>">
    <link rel="stylesheet" href="request.css">
    <link rel="stylesheet" href="resubmit.css">
    <style>
        .requirements-list {
            list-style: none;
            padding: 0;
            margin: 10px 0 0 0;
            background: #f8fafc;
            border: 1px solid var(--border-light);
            border-radius: 6px;
        }
        .requirements-list li {
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-light);
            font-size: 0.9rem;
            color: var(--text-dark);
        }
        .requirements-list li:last-child { border-bottom: none; }
        .requirements-list li::before {
            content: '☐';
            margin-right: 10px;
            color: var(--naap-gold);
        }
    </style>
</head>
<body>

<?php include('sidebar.php'); ?>

<div class="main-content">
    <div class="page-header">
        <h1>
            ✏️ Resubmit Returned Document 
                <span class="badge">Original ID: <?php echo htmlspecialchars($original_voucher_id); ?></span>
        </h1>
        <?php if($edit_mode): ?>
            <div class="alert-warning">
                <strong>⚠️ RESUBMITTING:</strong> This will create a <strong>NEW document request</strong> with a fresh tracking ID and QR code. 
                Your original document (<?php echo htmlspecialchars($original_voucher_id); ?>) will remain marked as "Returned" for audit purposes.
            </div>
        <?php endif; ?>
        <p>Fix any issues noted by the signatory, adjust the routing if needed, and generate a new QR code for scanning.</p>
    </div>

    <?php if (!empty($missing_requirements_list)): ?>
        <div class="missing-reqs-panel">
            <strong>❌ Missing / Incomplete Requirements</strong>
            <p style="font-size: 0.9rem; margin-top: -5px; margin-bottom: 15px;">The signatory noted the following items were missing or incomplete:</p>
            <ul>
                <?php foreach ($missing_requirements_list as $req): ?>
                    <li><?php echo htmlspecialchars($req); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($return_remarks): ?>
        <div class="return-remarks-panel">
            <strong>💬 Full Remarks from Signatory</strong>
            <pre><?php echo htmlspecialchars($return_remarks); ?></pre>
        </div>
    <?php endif; ?>

    <?php if($success_msg): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>

    <?php if($db_error): ?>
        <div class="alert-error"><?php echo $db_error; ?></div>
    <?php endif; ?>

    <form method="POST" class="form-container" onsubmit="return validateWorkflow()">
        <input type="hidden" name="original_voucher_id" value="<?php echo htmlspecialchars($original_voucher_id); ?>">
        
        <div>
            <h3 class="section-title">Document Details</h3>
            
            <div class="input-group">
                <label>Document Title / Subject</label>
                <input type="text" name="document_title" value="<?php echo htmlspecialchars($doc_title); ?>" placeholder="e.g. Employee Leave Form, Event Proposal" required readonly>
            </div>
            
            <div class="input-group" id="doc_type_group">
                <label>Document Type</label>
                <select name="doc_type_id_display" id="doc_type_select" disabled>
                    <option value="" disabled>-- Select a document type --</option>
                    <?php foreach($document_types as $type): ?>
                        <option value="<?php echo $type['id']; ?>" <?php if($doc_type_id == $type['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($type['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="doc_type_id" value="<?php echo htmlspecialchars($doc_type_id); ?>">
            </div>

            <div class="input-group">
                <label>Reference Number / External ID (Optional)</label>
                <input type="text" name="reference_number" value="<?php echo htmlspecialchars($reference_number); ?>" placeholder="e.g., PO-12345, Project Code, Previous Doc ID" readonly>
            </div>

            <div class="input-group">
                <label>Purpose / Description</label>
                <textarea name="purpose" rows="5" placeholder="Provide context or details about this document..." required readonly><?php echo htmlspecialchars($purpose); ?></textarea>
            </div>

            <div class="input-group">
                <label>Tags / Keywords (Optional, comma-separated)</label>
                <input type="text" name="tags" value="<?php echo htmlspecialchars($tags); ?>" placeholder="e.g., Q4-Budget, HR-Policy, Event" readonly>
            </div>

            <div class="input-group" style="background: #fffbeb; padding: 15px; border-radius: 6px; border: 1px solid #fde68a;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" id="has_financial_display" name="has_financial_display" style="width: 20px; height: 20px;" <?php if(!empty($amount)) echo 'checked'; ?> disabled>
                    <?php if(!empty($amount)): ?><input type="hidden" name="has_financial" value="on"><?php endif; ?>
                    <label for="has_financial" style="margin: 0; font-weight: bold; color: #92400e; cursor: pointer;">This request involves a financial transaction</label>
                </div>
            </div>

            <div id="financial_fields" style="display: <?php echo !empty($amount) ? 'block' : 'none'; ?>; margin-top: 15px; padding-top: 15px; border-top: 1px dashed var(--border-light);">
                <div class="input-group">
                    <label>Financial Voucher Type</label>
                    <select name="voucher_type_id_display" id="voucher_type_select" disabled>
                        <option value="">-- Select a voucher type --</option>
                        <?php foreach($voucher_types as $v_type): ?>
                            <option value="<?php echo $v_type['id']; ?>" <?php if($voucher_type_id == $v_type['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($v_type['name']); ?>                               
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="voucher_type_id" value="<?php echo htmlspecialchars($voucher_type_id); ?>">
                </div>
                <div id="requirements_panel" class="input-group" style="display: none; background: #f0f9ff; padding: 20px; border-radius: 8px; border: 1px solid #bae6fd;">
                    <!-- JS will populate this -->
                </div>
                <div class="input-group">
                    <label>Amount (PHP)</label>
                    <input type="number" name="amount" step="0.01" value="<?php echo htmlspecialchars($amount); ?>" placeholder="0.00" readonly>
                </div>
                <div class="input-group">
                    <label>Budget Code (Optional)</label>
                    <input type="text" name="budget_code" value="<?php echo htmlspecialchars($budget_code); ?>" placeholder="e.g., MOOE-2024-01" readonly>
                </div>
            </div>
        </div>

        <div class="workflow-container" style="background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid var(--border-light); height: 100%; box-sizing: border-box;">
            <h3 class="section-title">Custom Routing Sequence</h3>
            
            <div id="customization_panel">
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 15px;">This document follows a fixed routing sequence.</p>
                <div style="display: none; gap: 10px; margin-bottom: 15px;"> <!-- Hidden for resubmit -->
                <select id="officeSelect" style="flex: 1; padding: 10px; border: 1px solid var(--border-light); border-radius: 6px;">
                    <option value="Department Head">Department Head (of Requestor)</option>
                    <?php foreach($signatory_departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                        <option value="<?php echo htmlspecialchars($dept . ' (Head)'); ?>"><?php echo htmlspecialchars($dept . ' (Head)'); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="addOffice()" style="padding: 0 15px; background: var(--naap-gold); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">+ Add</button>
                </div>
            </div>

            <ul id="routeList" style="list-style: none; padding: 0; margin: 0; background: white; border: 1px solid var(--border-light); border-radius: 6px; min-height: 100px;">
                <!-- JS Populates this -->
            </ul>
            
            <input type="hidden" name="custom_workflow" id="customWorkflowInput" value="<?php echo htmlspecialchars($custom_workflow_json); ?>">
        </div>

        <button type="submit" class="btn-submit">
            ✅ RESUBMIT AS NEW DOCUMENT
        </button>
    </form>
</div>

<?php if ($show_modal): ?>
<div class="modal-overlay">
    <div class="print-card">
        <h2 style="color: var(--naap-navy); margin: 0; font-size: 1.3rem; letter-spacing: 1px;">✅ RESUBMISSION SUCCESSFUL</h2>
        <p style="color: var(--text-muted); font-size: 0.8rem; margin: 5px 0;">New Tracking ID:</p>
        <p style="font-weight: 800; font-size: 1.3rem; margin: 0 0 5px 0; color: var(--naap-navy);"><?php echo $new_voucher_id; ?></p>
        
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?php echo urlencode($new_voucher_id); ?>" style="margin: 10px 0; width: 130px; height: 130px; border: 3px solid var(--border-light); border-radius: 8px;">
        
        <?php if($original_voucher_id): ?>
            <div style="background: #f0f9ff; padding: 10px; border-radius: 6px; border-left: 4px solid var(--naap-gold); margin: 10px 0; font-size: 0.85rem;">
                <strong>📋 Original Voucher:</strong><br><?php echo htmlspecialchars($original_voucher_id); ?>
            </div>
        <?php endif; ?>
        
        <div class="print-checklist">
            <p style="font-weight: bold; margin: 0 0 5px 0; font-size: 0.8rem; border-bottom: 1px solid var(--border-light); padding-bottom: 5px;">ROUTING SEQUENCE:</p>
            <ul style="list-style-type: none; padding: 0; margin: 0;">
                <?php foreach ($custom_workflow_arr as $index => $office): ?>
                    <li><span><?php echo $index + 1; ?>.</span> <?php echo htmlspecialchars($office); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="print-checklist" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border-light);">
            <p style="font-weight: bold; margin: 0 0 5px 0; font-size: 0.8rem;">PROCESSING TIME:</p>
            <p style="margin:0; font-size: 0.9rem;"><?php echo htmlspecialchars($arta_level); ?>: <strong><?php echo $processing_days; ?> Working Days</strong></p>
        </div>
        
        <button class="btn-print" onclick="window.print()">🖨️ Print New Slip</button>
        <button class="btn-close" onclick="window.location.href='list.php'">View Document List</button>
    </div>
</div>
<?php endif; ?>

<script>
    let currentRoute = <?php echo $custom_workflow_json; ?>;
    let isCustomMode = false; // Resubmit page should always have a fixed route
    const voucherTypesData = <?php echo json_encode($voucher_types); ?>;
    const artaLevelsMap = <?php echo json_encode($arta_levels_map); ?>; // arta_level => processing_days

    function toggleFinancial() {
        const checkbox = document.getElementById('has_financial');
        const fieldsDiv = document.getElementById('financial_fields');
        const amountInput = fieldsDiv.querySelector('input[name="amount"]');
        const docTypeGroup = document.getElementById('doc_type_group');
        const docTypeSelect = document.getElementById('doc_type_select');
        const artaInfoPanel = document.getElementById('arta_info_panel');
        const customizationPanel = document.getElementById('customization_panel');

        if (checkbox.checked) {
            fieldsDiv.style.display = 'block';
            amountInput.required = true;
            docTypeGroup.style.display = 'none';
            docTypeSelect.required = false;
            // Let the voucher type change handler decide if the panel is shown
            handleVoucherTypeChange(); 
            artaInfoPanel.style.display = 'block'; // Ensure ARTA panel is visible for financial
        } else {
            fieldsDiv.style.display = 'none';
            amountInput.required = false;
            docTypeGroup.style.display = 'block';
            docTypeSelect.required = true;
            // When un-checking, always allow custom routing
            isCustomMode = true;
            customizationPanel.style.display = 'block';
            // You might want to restore the original route here if needed
            // currentRoute = <?php echo $custom_workflow_json; ?>;
            updateRouteUI();
            artaInfoPanel.style.display = 'none'; // Hide ARTA panel if no financial
        }
    }

    function addOffice() {
        const select = document.getElementById("officeSelect");
        const office = select.value;
        
        if (currentRoute.length > 0 && currentRoute[currentRoute.length - 1] === office) {
            alert("⚠️ This office is already the current last step.");
            return;
        }

        currentRoute.push(office);
        updateRouteUI();
    }

    function removeOffice(index) {
        currentRoute.splice(index, 1);
        updateRouteUI();
    }

    function updateRouteUI() {
        const list = document.getElementById("routeList");
        list.innerHTML = "";

        if (currentRoute.length === 0) {
            let msg = "No offices added. Start adding to build the route.";
            if (document.getElementById('has_financial').checked) {
                msg = "Please select a Financial Voucher Type to load its mandatory route.";
            }
            list.innerHTML = `<li style='padding: 15px; color: var(--text-muted); text-align: center; font-style: italic; font-size: 0.9rem;'>${msg}</li>`;
        } else {
            currentRoute.forEach((office, index) => {
                const li = document.createElement("li");
                li.style.padding = "10px 15px";
                li.style.borderBottom = (index === currentRoute.length - 1) ? "none" : "1px solid var(--border-light)";
                li.style.display = "flex";
                li.style.justifyContent = "space-between";
                li.style.alignItems = "center";
                li.style.fontSize = "0.9rem";

                li.innerHTML = `
                    <span><strong style="color: var(--naap-navy); margin-right: 10px;">Step ${index + 1}</strong> ${office}</span>
                    ${isCustomMode ? `<button type="button" onclick="removeOffice(${index})" style="color: #ef4444; background: none; border: none; cursor: pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;">&times;</button>` : ''}
                `;
                list.appendChild(li);
            });
        }
        
        document.getElementById("customWorkflowInput").value = JSON.stringify(currentRoute);
    }

    function validateWorkflow() {
        if(currentRoute.length === 0) {
            alert("⚠️ Rule Violation: You must add at least one office to the routing sequence before submitting.");
            return false;
        }
        return true;
    }

    function handleVoucherTypeChange() {
        const voucherTypeSelect = document.getElementById('voucher_type_select');
        const requirementsPanel = document.getElementById('requirements_panel');
        const selectedId = voucherTypeSelect.value;
        const artaInfoPanel = document.getElementById('arta_info_panel');
        const customizationPanel = document.getElementById('customization_panel');
        const selectedType = voucherTypesData.find(vt => vt.id == selectedId);

        if (selectedType && selectedType.requirements) {
            try {
                const requirements = JSON.parse(selectedType.requirements);
                if (requirements && requirements.length > 0) {
                    let listHtml = '<strong style="color: var(--naap-navy); font-size: 0.9rem; text-transform: uppercase; display: block; margin-bottom: 10px;">Checklist / Requirements</strong><ul class="requirements-list">';
                    requirements.forEach(req => {
                        listHtml += `<li>${req}</li>`;
                    });
                    listHtml += '</ul>';
                    requirementsPanel.innerHTML = listHtml;
                    requirementsPanel.style.display = 'block';
                } else {
                    requirementsPanel.style.display = 'none';
                }
            } catch (e) {
                console.error("Error parsing requirements JSON", e);
                requirementsPanel.style.display = 'none';
            }
            updateArtaInfoPanel(selectedType.arta_level); // Update ARTA info for selected financial type
        } else {
            requirementsPanel.style.display = 'none';
        }

        // --- NEW: Handle Automatic Routing ---
        const defaultFinancialRoute = ["Budgeting", "VP for Admin & Finance", "Accounting", "Opres", "Disbursing"];

        if (selectedType) { // If a valid financial type is selected
            let workflow = [];
            let hasWorkflow = false;
            if (selectedType.default_workflow) {
                try {
                    workflow = JSON.parse(selectedType.default_workflow);
                    if (workflow && workflow.length > 0) {
                        hasWorkflow = true;
                    }
                } catch(e) {
                    console.error("Could not parse workflow for voucher type:", selectedType.name);
                }
            }

            if (hasWorkflow) {
                currentRoute = workflow;
            } else {
                // Fallback to the standard financial route if none is defined in the DB
                currentRoute = defaultFinancialRoute;
            }
            isCustomMode = false; // Financial routes are always locked
            customizationPanel.style.display = 'none';
        } else {
            // No type selected
            currentRoute = [];
            isCustomMode = true; // Allow custom if no type is selected
            customizationPanel.style.display = 'block';
        }

        // Update ARTA info panel based on selected financial type
        if (selectedType) {
            updateArtaInfoPanel(selectedType.arta_level);
        } else {
            artaInfoPanel.style.display = 'none'; // Hide ARTA panel if no financial type selected
        }
        updateRouteUI();
    }

    // Initialize UI on load
    document.addEventListener("DOMContentLoaded", function() {
        updateRouteUI();
        
        // Trigger change handler if a voucher type is pre-selected on page load
        const voucherSelect = document.getElementById('voucher_type_select');
        if (voucherSelect.value) {
            voucherSelect.dispatchEvent(new Event('change'));
        }
    });

    function updateArtaInfoPanel(artaLevel) {
        const artaInfoPanel = document.getElementById('arta_info_panel');
        const processingDays = artaLevelsMap[artaLevel] || 'N/A';
        artaInfoPanel.innerHTML = `<span>&#128337;</span> ARTA Process Time: <strong>${artaLevel} (${processingDays} Days)</strong>`;
        artaInfoPanel.style.display = 'block';
    }
</script>

<?php
$conn->close(); // Close the connection once, at the very end.
?>
</body>
</html>