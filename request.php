<?php
session_start();
if (!isset($_SESSION['logged_in'])) { header("Location: login.php"); exit(); }

// To make QR codes scannable as deep links from mobile devices,
// we need a base URL that uses the server's network IP, not 'localhost'.
// This file should be created and configured with the server's actual local IP address.
// Example: define('BASE_URL', 'http://192.168.1.10/DocumentTrackingSystem');
include_once 'config.php';

require_once 'db_connect.php';

// Fetch signatory departments for the workflow builder
$signatory_departments = [];
$depts_res = $conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1 ORDER BY name ASC");
while ($dept_row = $depts_res->fetch_assoc()) {
    $signatory_departments[] = $dept_row['name'];
}

// Fetch document types for the dropdown
$document_types = [];
$types_res = $conn->query("SELECT id, name, arta_level, default_workflow, workflow_type FROM document_types WHERE is_active = 1 ORDER BY name ASC");
while ($type_row = $types_res->fetch_assoc()) {
    $document_types[] = $type_row;
}

// Fetch financial voucher types for the dynamic dropdown
$voucher_types_data = []; // Renamed to avoid conflict with $voucher_types in JS
$v_types_res = $conn->query("SELECT id, name, arta_level, requirements, default_workflow FROM voucher_types WHERE is_active = 1 ORDER BY name ASC");
while ($v_type_row = $v_types_res->fetch_assoc()) {
    $voucher_types_data[] = $v_type_row;
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



$show_modal = false;
$voucher_id = ""; // Still used as the primary tracking ID
$doc_title = "";
$custom_workflow_arr = [];
$tracking_url = "";
$arta_deadline = null; // Initialize ARTA deadline
$workflow_type = "Approval"; // Default
$db_error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Database auto-patching is now handled globally in db_connect.php
    // 2. FETCH USER_ID
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

    // 3. CAPTURE FORM DATA
    $voucher_id = "NAAP-" . date("Y") . "-" . rand(1000, 9999);
    $workflow_type = $_POST['workflow_type'] ?? 'Approval';
    $doc_title = trim($_POST['document_title'] ?? ''); // This is the subject, not the type
    $doc_type_id = $_POST['doc_type_id'] ?? null;
    $arta_level = null; // Will be determined below
    $reference_number = trim($_POST['reference_number'] ?? null);
    $tags = trim($_POST['tags'] ?? null);
    $purpose = trim($_POST['purpose'] ?? '');
    
    // Financial fields (only if checkbox was checked)
    $amount = isset($_POST['has_financial']) ? ($_POST['amount'] ?? null) : null;
    $voucher_type_id = isset($_POST['has_financial']) ? ($_POST['voucher_type_id'] ?? null) : null;
    $budget_code = isset($_POST['has_financial']) ? trim($_POST['budget_code'] ?? null) : null;

    // If a financial voucher type is selected, it overrides the document type and title.
    if (!empty($voucher_type_id)) {
        $doc_type_id = null;
        $doc_title = "Financial Voucher";
    }

    // --- NEW: LEARNING MECHANISM FOR DOCUMENT TYPES ---
    if ($doc_type_id === 'custom' && empty($db_error)) {
        $new_doc_type_name = trim($_POST['new_doc_type_name'] ?? '');
        if (empty($new_doc_type_name)) {
            $db_error = "A name is required for a new custom document type.";
        } else {
            // Check if this type already exists (case-insensitive check to prevent near-duplicates)
            $check_type_stmt = $conn->prepare("SELECT id FROM document_types WHERE name = ?");
            $check_type_stmt->bind_param("s", $new_doc_type_name);
            $check_type_stmt->execute();
            $existing_type_res = $check_type_stmt->get_result();
            if ($existing_type_res->num_rows > 0) {
                // It already exists, just use its ID
                $doc_type_id = $existing_type_res->fetch_assoc()['id'];
                // Fetch its ARTA level
                $arta_level_stmt = $conn->prepare("SELECT arta_level FROM document_types WHERE id = ?");
                $arta_level_stmt->bind_param("i", $doc_type_id);
                $arta_level_stmt->execute();
                $arta_level = $arta_level_stmt->get_result()->fetch_assoc()['arta_level'] ?? 'Simple';
                $arta_level_stmt->close();
            } else {
                // It's a new type, so create it and learn its workflow
                $custom_workflow_for_new_type = $_POST['custom_workflow'] ?? '[]';
                $arta_level_for_new_type = $_POST['new_doc_type_arta'] ?? 'Simple';
                
                // --- NEW: Capture requirements for the new document type ---
                $new_doc_requirements_text = trim($_POST['new_doc_requirements'] ?? '');
                $new_doc_requirements_array = !empty($new_doc_requirements_text) ? array_map('trim', preg_split('/\r\n|\r|\n/', $new_doc_requirements_text)) : [];
                $new_doc_requirements_json = json_encode($new_doc_requirements_array);
                
                // Updated INSERT to include the new requirements JSON
                $insert_type_stmt = $conn->prepare("INSERT INTO document_types (name, requirements, arta_level, default_workflow, created_by_user_id, is_active, workflow_type) VALUES (?, ?, ?, ?, ?, 1, ?)");
                $insert_type_stmt->bind_param("ssssis", $new_doc_type_name, $new_doc_requirements_json, $arta_level_for_new_type, $custom_workflow_for_new_type, $requestor_id, $workflow_type);
                if ($insert_type_stmt->execute()) {
                    $doc_type_id = $conn->insert_id; // Get the ID of the newly created type
                } else {
                    $db_error = "Could not create the new document type in the database.";
                }
                $insert_type_stmt->close();
                $arta_level = $arta_level_for_new_type;
            }
            $check_type_stmt->close();
        }
    } else if ($doc_type_id !== null) {
        // Fetch ARTA level for existing document type
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
            if ($fin_arta_row = $fin_arta_res->fetch_assoc()) {
                if (!empty($fin_arta_row['arta_level'])) {
                    $arta_level = $fin_arta_row['arta_level'];
                }
            }
        }
        $fin_arta_stmt->close();
    }

    // Now that the final ARTA level is determined, calculate the deadline and processing days
    $arta_deadline = calculateARTADeadline(date('Y-m-d'), $arta_level, $conn);

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

    $status = 'Pending Review';
    $custom_workflow_json = '[]';

    if ($workflow_type === 'Transfer') {
        $status = 'In Transit';
        $destination = $_POST['transfer_destination'] ?? null;
        if (empty($destination)) {
            $db_error = "A destination office must be selected for a Simple Transfer.";
        } else {
            $custom_workflow_arr = [$destination];
            $custom_workflow_json = json_encode($custom_workflow_arr);
        }
    } else { // Approval
        $custom_workflow_json = $_POST['custom_workflow'] ?? '[]';
        $custom_workflow_arr = json_decode($custom_workflow_json, true) ?? [];
    }

    // 4. SAVE TO DATABASE
    if (empty($db_error)) {
        $insert_stmt = $conn->prepare("INSERT INTO vouchers (voucher_code, requestor_id, document_title, doc_type_id, voucher_type_id, reference_number, tags, amount, budget_code, purpose, status, workflow_type, current_stage_index, custom_workflow, arta_deadline) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
        $insert_stmt->bind_param("sisiissdssssss", $voucher_id, $requestor_id, $doc_title, $doc_type_id, $voucher_type_id, $reference_number, $tags, $amount, $budget_code, $purpose, $status, $workflow_type, $custom_workflow_json, $arta_deadline);
        
        if($insert_stmt->execute()) {
            // --- AUTO-SKIP LOGIC ---
            // If the first step of the workflow is the requestor's own department, auto-skip it.
            if ($workflow_type === 'Approval' && !empty($custom_workflow_arr) && $custom_workflow_arr[0] === 'Department Head' && $requestor_is_head == 1) {
                // Increment the stage index
                $skip_stmt = $conn->prepare("UPDATE vouchers SET current_stage_index = current_stage_index + 1 WHERE voucher_code = ?");
                $skip_stmt->bind_param("s", $voucher_id);
                $skip_stmt->execute();
                $skip_stmt->close();

                // Log this automatic action for a clear audit trail. The department is the requestor's actual department.
                $log_skip_stmt = $conn->prepare("INSERT INTO audit_logs (voucher_code, department, action_taken, remarks, processed_by_user_id) VALUES (?, ?, 'AUTO-SKIPPED', 'Requestor is a Department Head, auto-skipping first approval step.', ?)");
                $log_skip_stmt->bind_param("ssi", $voucher_id, $requestor_role, $requestor_id);
                $log_skip_stmt->execute();
                $log_skip_stmt->close();
            }

            // Create a notification for the requestor
            $notif_message = "Your document " . $voucher_id . " has been successfully submitted and is now pending review.";
            $notif_link = "track.php?track_id=" . urlencode($voucher_id);
            create_notification($conn, $requestor_id, $notif_message, $notif_link);

            // Notify the first signatory department
            if (!empty($custom_workflow_arr)) {
                $next_stage_index_0_based = 0;
                // If the first step was auto-skipped, notify the *second* step
                if ($workflow_type === 'Approval' && !empty($custom_workflow_arr) && $custom_workflow_arr[0] === 'Department Head' && $requestor_is_head == 1) {
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
                        $signatory_notif_message = "Heads up! A new document (" . $voucher_id . ") from " . $_SESSION['full_name'] . " is en route to your office.";
                        $signatory_notif_link = "queue.php";
                        while ($user_row = $users_res->fetch_assoc()) { create_notification($conn, $user_row['user_id'], $signatory_notif_message, $signatory_notif_link); }
                        $users_to_notify_stmt->close();
                    }
                }
            }

            // Generate a full, mobile-accessible URL for the QR code.
            if (defined('BASE_URL')) {
                $tracking_url = rtrim(BASE_URL, '/') . "/track.php?track_id=" . urlencode($voucher_id);
            } else {
                $tracking_url = $voucher_id;
            }
            
            $show_modal = true;
        } else {
            $db_error = "Database Error: " . $conn->error;
        }
        
        $insert_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAAP - Prepare Document Request</title>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo filemtime('sidebar.css'); ?>">
    <link rel="stylesheet" href="request.css">
    <style>
        .radio-group {
            display: flex;
            gap: 15px;
            border: 1px solid var(--border-light);
            padding: 10px;
            border-radius: 6px;
        }
        .radio-group label {
            flex: 1;
            display: flex;
            align-items: flex-start;
            padding: 15px;
            border-radius: 4px;
            cursor: pointer;
            background: #f8fafc;
            transition: all 0.2s ease-in-out;
            border: 2px solid transparent;
        }
        .radio-group label:hover {
            background: #f0f9ff;
        }
        .radio-group input[type="radio"] {
            margin-top: 4px;
            margin-right: 12px;
            accent-color: var(--naap-navy);
        }
        .radio-group input[type="radio"]:checked + div {
            /* This is a bit tricky without changing the structure */
        }
        .radio-group label.selected {
            background: #eef2ff;
            border-color: var(--naap-navy);
        }
        .indicator {
            margin-bottom: 15px;
            font-size: 0.9rem;
            font-weight: 500;
            padding: 12px 15px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .indicator span { font-size: 1.2rem; }
        .indicator-approval {
            background: #f0fdf4;
            color: #14532d;
            border: 1px solid #bbf7d0;
        }
        .indicator-transfer {
            background: #eef2ff;
            color: #312e81;
            border: 1px solid #c7d2fe;
        }
    </style>
</head>
<body>

<?php include('sidebar.php'); ?>

<div class="main-content">
    <div class="page-header">
        <h1>📄 Prepare New Document Request</h1>
        <p>Define your document type and map out its custom approval routing.</p>
    </div>

    <?php if ($db_error) : ?>
        <div class="alert-error" style="color: red; margin-bottom: 20px;"><?php echo $db_error; ?></div>
    <?php endif; ?>

    <form method="POST" class="form-container" onsubmit="return validateWorkflow()">
        
        <div>
            <h3 class="section-title">Document Details</h3>

            <div class="input-group">
                <label>Workflow Type</label>
                <div class="radio-group">
                    <label for="type_approval">
                        <input type="radio" id="type_approval" name="workflow_type" value="Approval" checked onchange="toggleWorkflowType()">
                        <div>
                            <span style="font-weight: bold; color: var(--naap-navy);">Approval Workflow</span>
                            <small style="display: block; font-weight: normal; color: var(--text-muted); font-size: 0.85rem; margin-top: 4px;">Document requires signatures from a sequence of offices.</small>
                        </div>
                    </label>
                    <label for="type_transfer">
                        <input type="radio" id="type_transfer" name="workflow_type" value="Transfer" onchange="toggleWorkflowType()">
                        <div>
                            <span style="font-weight: bold; color: var(--naap-navy);">Simple Transfer</span>
                            <small style="display: block; font-weight: normal; color: var(--text-muted); font-size: 0.85rem; margin-top: 4px;">Send document to a single destination for filing or information.</small>
                        </div>
                    </label>
                </div>
            </div>
            
            <div class="input-group">
                <label>Document Title / Subject</label>
                <input type="text" name="document_title" id="document_title" placeholder="e.g. Employee Leave Form, Event Proposal" required>
            </div>

            <div class="input-group" id="doc_type_group">
                <label>Document Type</label>
                <select name="doc_type_id" id="doc_type_select" onchange="handleDocTypeChange()" required>
                    <option value="" disabled selected>-- Select a document type --</option>
                    <?php foreach($document_types as $type): ?>
                        <option
                            value="<?php echo $type['id']; ?>" 
                            data-workflow='<?php echo htmlspecialchars($type['default_workflow'] ?? '[]', ENT_QUOTES, 'UTF-8'); ?>'
                            data-workflow-type='<?php echo htmlspecialchars($type['workflow_type'] ?? 'Approval', ENT_QUOTES, 'UTF-8'); ?>'
                            data-arta-level='<?php echo htmlspecialchars($type['arta_level'] ?? 'Simple', ENT_QUOTES, 'UTF-8'); ?>'>
                            <?php echo htmlspecialchars($type['name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="custom">Other (Type a new one)...</option>
                </select>
            </div>
            <div class="input-group" id="new_doc_type_wrapper" style="display: none;">
                <label>New Document Type Name</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" name="new_doc_type_name" id="new_doc_type_name" placeholder="e.g., Special Project Proposal" style="flex: 2;">
                    <select name="new_doc_type_arta" style="flex: 1;" required><?php foreach($all_arta_levels as $level): ?><option value="<?php echo htmlspecialchars($level); ?>"><?php echo htmlspecialchars($level); ?></option><?php endforeach; ?></select> 
                </div>
            </div>
            <div class="input-group" id="new_doc_requirements_wrapper" style="display: none;">
                <label>Requirements for New Document Type (Optional)</label>
                <textarea name="new_doc_requirements" id="new_doc_requirements" rows="4" placeholder="Enter one requirement per line..."></textarea>
            </div>

            <div id="arta_info_panel" class="indicator" style="display: none; margin-top: 15px;">
                <!-- JS will populate this -->
            </div>

            <div class="input-group">
                <label>Reference Number / External ID (Optional)</label>
                <input type="text" name="reference_number" placeholder="e.g., PO-12345, Project Code, Previous Doc ID">
            </div>
            <div class="input-group">
                <label>Purpose / Description</label>
                <textarea name="purpose" id="purpose" rows="5" placeholder="Provide context or details about this document..." required></textarea>
            </div>

            <div class="input-group">
                <label>Tags / Keywords (Optional, comma-separated)</label>
                <input type="text" name="tags" placeholder="e.g., Q4-Budget, HR-Policy, Event">
            </div>

            <div class="input-group" style="background: #fffbeb; padding: 15px; border-radius: 6px; border: 1px solid #fde68a;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" id="has_financial" name="has_financial" style="width: 20px; height: 20px;" onchange="toggleFinancial()">
                    <label for="has_financial" style="margin: 0; font-weight: bold; color: #92400e; cursor: pointer;">This request involves a financial transaction</label>
                </div>
            </div>
            <div id="financial_fields" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px dashed var(--border-light);">
                <div class="input-group">
                    <label>Financial Voucher Type</label>
                    <select name="voucher_type_id" id="voucher_type_select" onchange="handleVoucherTypeChange()">
                        <option value="">-- Select a voucher type --</option>
                        <?php foreach($voucher_types_data as $v_type): ?>
                            <option value="<?php echo $v_type['id']; ?>"><?php echo htmlspecialchars($v_type['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="requirements_panel" class="input-group" style="display: none; background: #f0f9ff; padding: 20px; border-radius: 8px; border: 1px solid #bae6fd;">
                    <!-- JS will populate this -->
                </div>


                <div class="input-group">
                    <label>Amount (PHP)</label>
                    <input type="number" name="amount" step="0.01" placeholder="0.00">
                </div>
                <div class="input-group">
                    <label>Budget Code (Optional)</label>
                    <input type="text" name="budget_code" placeholder="e.g., MOOE-2024-01">
                </div>
            </div>

        </div>

        <div>
            <div class="workflow-container">
                <h3 class="section-title">Routing Sequence</h3>
                <div id="workflow_type_indicator" style="display: none;"></div>
                <div id="customization_panel" style="display: none;">
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 15px;">This is a custom document. Build the exact path it must take.</p>
                    <div id="approval_workflow_section">
                        <div style="display: flex; gap: 10px; margin-bottom: 15px;">
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
                    <div id="transfer_workflow_section" style="display:none;">
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 15px;">Select the single department this document will be sent to.</p>
                        <select name="transfer_destination" style="width: 100%; padding: 10px; border: 1px solid var(--border-light); border-radius: 6px;">
                            <option value="" disabled selected>-- Select a Destination --</option>
                             <?php foreach($signatory_departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                <option value="<?php echo htmlspecialchars($dept . ' (Head)'); ?>"><?php echo htmlspecialchars($dept . ' (Head)'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <ul id="routeList" style="list-style: none; padding: 0; margin: 0; background: #f8fafc; border: 1px solid var(--border-light); border-radius: 6px; min-height: 100px;">
                    <!-- JS Populates this -->
                </ul>
                <input type="hidden" name="custom_workflow" id="customWorkflowInput" value="[]">
            </div>
        </div>
        
        <button type="submit" class="btn-submit">Submit Document & Generate QR</button>
        </form>
</div>

<?php if ($show_modal): ?>
<div class="modal-overlay">
    <div class="print-card">
        <h2 style="color: var(--naap-navy); margin: 0; font-size: 1.3rem; letter-spacing: 1px;">✅ SUBMISSION SUCCESS</h2>
        <p style="color: var(--text-muted); font-size: 0.8rem; margin: 5px 0;">Tracking ID:</p>
        <p style="font-weight: 800; font-size: 1.3rem; margin: 0 0 5px 0; color: var(--naap-navy);"><?php echo $voucher_id; ?></p>
        
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?php echo urlencode($tracking_url); ?>" style="margin: 10px 0; width: 130px; height: 130px; border: 3px solid var(--border-light); border-radius: 8px;">
        
        <div class="print-checklist">
            <?php if ($workflow_type === 'Transfer'): ?>
                <p style="font-weight: bold; margin: 0 0 5px 0; font-size: 0.8rem; border-bottom: 1px solid var(--border-light); padding-bottom: 5px;">DESTINATION:</p>
                <ul style="list-style-type: none; padding: 0; margin: 0;">
                    <li><span>&rarr;</span> <?php echo htmlspecialchars($custom_workflow_arr[0] ?? 'N/A'); ?></li>
                </ul>
            <?php else: ?>
                <p style="font-weight: bold; margin: 0 0 5px 0; font-size: 0.8rem; border-bottom: 1px solid var(--border-light); padding-bottom: 5px;">ROUTING SEQUENCE:</p>
                <ul style="list-style-type: none; padding: 0; margin: 0;">
                    <?php if (empty($custom_workflow_arr)): ?><li>Default Sequence</li><?php endif; ?>
                    <?php foreach ($custom_workflow_arr as $index => $office): ?>
                        <li><span><?php echo $index + 1; ?>.</span> <?php echo htmlspecialchars($office); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="print-checklist" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border-light);">
            <p style="font-weight: bold; margin: 0 0 5px 0; font-size: 0.8rem;">PROCESSING TIME:</p>
            <p style="margin:0; font-size: 0.9rem;"><?php echo htmlspecialchars($arta_level); ?>: <strong><?php echo $processing_days; ?> Working Days</strong></p>
        </div>
        
        <button class="btn-print" onclick="window.print()">🖨️ Print Routing Slip</button>
        <button class="btn-close" onclick="window.location.href='list.php'">Close & Go to List</button>
    </div>
</div>
<?php endif; ?>

<script>
    // --- Existing JavaScript from request.php ---
    let isCustomMode = false;
    const artaLevelsMap = <?php echo json_encode($arta_levels_map); ?>; // arta_level => processing_days
    const defaultFinancialDocTypeId = <?php echo json_encode($default_financial_doc_type_id); ?>;
    let currentRoute = [];
    const voucherTypesData = <?php echo json_encode($voucher_types_data); ?>; // voucher_type_id => {name, arta_level, requirements, default_workflow}


    function toggleWorkflowType() {
        const approvalSection = document.getElementById('approval_workflow_section');
        const transferSection = document.getElementById('transfer_workflow_section');
        const workflowType = document.querySelector('input[name="workflow_type"]:checked').value;

        const approvalRadio = document.getElementById('type_approval').parentElement;
        const transferRadio = document.getElementById('type_transfer').parentElement;

        if (workflowType === 'Approval') {
            approvalSection.style.display = 'block';
            transferSection.style.display = 'none';
            approvalRadio.classList.add('selected');
            transferRadio.classList.remove('selected');
        } else { // Transfer
            approvalSection.style.display = 'none';
            transferSection.style.display = 'block';
            approvalRadio.classList.remove('selected');
            transferRadio.classList.add('selected');
        }
    }

    function toggleFinancial() {
        const checkbox = document.getElementById('has_financial');
        const fieldsDiv = document.getElementById('financial_fields');
        const amountInput = fieldsDiv.querySelector('input[name="amount"]');
        const docTypeGroup = document.getElementById('doc_type_group');
        const docTypeSelect = document.getElementById('doc_type_select');
        const docTitleInput = document.getElementById('document_title');
        const purposeTextarea = document.getElementById('purpose');

        if (checkbox.checked) {
            fieldsDiv.style.display = 'block';
            amountInput.required = true;

            // Automatically set the document title
            docTitleInput.value = 'Financial Voucher';
            docTitleInput.readOnly = true; // Prevent user from changing it
            purposeTextarea.focus();

            // Auto-select the 'Financial Voucher' document type and disable the dropdown
            if (defaultFinancialDocTypeId) {
                docTypeSelect.value = defaultFinancialDocTypeId;
                docTypeSelect.disabled = true;
            }
            handleVoucherTypeChange(); // NEW: Set the context for financial vouchers

            // Reset and lock the workflow until a financial type is chosen
            currentRoute = [];
            updateRouteUI();
        } else {
            fieldsDiv.style.display = 'none';
            amountInput.required = false;
            docTitleInput.value = '';
            docTitleInput.readOnly = false;
            docTypeSelect.value = '';
            docTypeSelect.disabled = false;

            // Re-evaluate the doc type to restore its workflow
            handleDocTypeChange();
        }
    }

    function handleDocTypeChange() {
        const select = document.getElementById('doc_type_select');
        const selectedOption = select.options[select.selectedIndex];
        const customizationPanel = document.getElementById('customization_panel');
        const workflowTypePanel = document.querySelector('.radio-group').parentElement;
        const newDocTypeInputGroup = document.getElementById('new_doc_type_wrapper'); // Corrected ID
        const newDocTypeWrapper = document.getElementById('new_doc_type_wrapper');
        const newDocTypeNameInput = document.getElementById('new_doc_type_name');
        const newDocRequirementsWrapper = document.getElementById('new_doc_requirements_wrapper');
        const workflowTypeIndicator = document.getElementById('workflow_type_indicator');
        const artaInfoPanel = document.getElementById('arta_info_panel');

        // Always hide the indicator initially, then show it if a valid pre-defined type is selected
        workflowTypeIndicator.style.display = 'none'; 
        artaInfoPanel.style.display = 'none';

        if (select.value === 'custom') {
            isCustomMode = true;
            currentRoute = [];
            customizationPanel.style.display = 'block'; // Show workflow builder
            workflowTypePanel.style.display = 'block'; // Show workflow type radio buttons
            newDocTypeInputGroup.style.display = 'block'; // Show new doc type name input
            newDocRequirementsWrapper.style.display = 'block';
            newDocTypeNameInput.required = true;
        } else if (select.value === '') { // If the placeholder "-- Select a document type --" is selected
            isCustomMode = false;
            currentRoute = []; // Clear route
            customizationPanel.style.display = 'none'; // Hide workflow builder
            workflowTypePanel.style.display = 'none';
            newDocRequirementsWrapper.style.display = 'none';
            newDocTypeWrapper.style.display = 'none';
            newDocTypeNameInput.required = false;
        } else {
            isCustomMode = false;
            const workflowData = selectedOption.getAttribute('data-workflow');
            const workflowType = selectedOption.getAttribute('data-workflow-type');
            currentRoute = workflowData ? JSON.parse(workflowData) : [];
            
            customizationPanel.style.display = 'none';
            workflowTypePanel.style.display = 'none'; // Hide for standard routes
            newDocRequirementsWrapper.style.display = 'none';
            newDocTypeWrapper.style.display = 'none';
            newDocTypeNameInput.required = false;
            if (workflowType === 'Transfer') {
                // Update the ARTA info panel
                const artaLevel = selectedOption.getAttribute('data-arta-level');
                const processingDays = artaLevelsMap[artaLevel] || 'N/A';
                artaInfoPanel.innerHTML = `<span>&#128337;</span> ARTA Process Time: <strong>${artaLevel} (${processingDays} Days)</strong>`;
                artaInfoPanel.style.display = 'block';

                document.getElementById('type_transfer').checked = true;
            } else {
                document.getElementById('type_approval').checked = true;
            }
            toggleWorkflowType();

            // Show and style the indicator
            workflowTypeIndicator.style.display = 'block';
            if (workflowType === 'Transfer') {
                workflowTypeIndicator.innerHTML = `<span>&#128441;</span> Workflow Type: <strong>Simple Transfer</strong>`;
                workflowTypeIndicator.className = 'indicator indicator-transfer';
            } else {
                workflowTypeIndicator.innerHTML = `<span>&#9997;</span> Workflow Type: <strong>Approval Sequence</strong>`;
                workflowTypeIndicator.className = 'indicator indicator-approval';
            }

            // Update the ARTA info panel
            const artaLevel = selectedOption.getAttribute('data-arta-level');
            const processingDays = artaLevelsMap[artaLevel] || 'N/A';
            artaInfoPanel.innerHTML = `<span>&#128337;</span> ARTA Process Time: <strong>${artaLevel} (${processingDays} Days)</strong>`;
            artaInfoPanel.style.display = 'block';

        } 
        updateRouteUI();
    }

    function addOffice() {
        const select = document.getElementById("officeSelect");
        const office = select.value;
        
        // Prevent adjacent duplicates
        if (currentRoute.length > 0 && currentRoute[currentRoute.length - 1] === office) {
            alert("⚠️ This office is already the current step.");
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
            let msg = "Please select a Document Type to see its default route, or choose 'Other' to build a custom one.";
            if (document.getElementById('has_financial').checked) {
                msg = "Please select a Financial Voucher Type to load its mandatory route.";
            }
            list.innerHTML = `<li style='padding: 25px; color: var(--text-muted); text-align: center; font-style: italic; font-size: 0.9rem;'>${msg}</li>`;
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
        const docType = document.getElementById('doc_type_select').value;

        // Only validate custom workflows. Standard ones are pre-validated.
        if (docType === 'custom') {
            const newTypeName = document.getElementById('new_doc_type_name').value.trim();
            if (!newTypeName) {
                alert("⚠️ Rule Violation: You must provide a name for the new document type.");
                return false;
            }

            const workflowType = document.querySelector('input[name="workflow_type"]:checked').value;
            if (workflowType === 'Approval') {
                if(currentRoute.length === 0) {
                    alert("⚠️ Rule Violation: You must add at least one office to the routing sequence for a custom approval.");
                    return false;
                }
            }
        } else if (docType === 'custom' && workflowType === 'Transfer') {
            const destination = document.querySelector('select[name="transfer_destination"]').value;
            if (!destination) {
                alert("⚠️ Rule Violation: You must select a destination office for a simple transfer.");
                return false;
            }
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
            updateArtaInfoPanel(selectedType.arta_level);
        } else {
            requirementsPanel.style.display = 'none';
        }

        // --- NEW: Handle Automatic Routing ---
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
                // If no workflow is defined in the database for the voucher type, the route should be empty.
                currentRoute = [];
            }
            isCustomMode = false; // Financial routes are always locked
            customizationPanel.style.display = 'none';
        } else {
            // No type selected
            currentRoute = [];
            isCustomMode = true; // Allow custom if no type is selected
            customizationPanel.style.display = 'none'; // It's hidden anyway by toggleFinancial
        }

        // Update ARTA info panel based on selected financial type
        if (selectedType) {
            updateArtaInfoPanel(selectedType.arta_level);
        } else {
        // If no financial type is selected, hide the ARTA panel.
        artaInfoPanel.style.display = 'none';
        }
        updateRouteUI();
    }


    // Initialize UI on load
    document.addEventListener("DOMContentLoaded", function() { 
        handleDocTypeChange(); 
        toggleWorkflowType(); // Also initialize radio button styles
        
        // If the financial checkbox is checked on page load (e.g., form error), run the toggle function
        if (document.getElementById('has_financial').checked) {
            toggleFinancial();
        }

        // Listener is now on the select element directly via onchange attribute
    });

    function updateArtaInfoPanel(artaLevel) {
        const artaInfoPanel = document.getElementById('arta_info_panel');
        const processingDays = artaLevelsMap[artaLevel] || 'N/A';
        artaInfoPanel.innerHTML = `<span>&#128337;</span> ARTA Process Time: <strong>${artaLevel} (${processingDays} Days)</strong>`;
        artaInfoPanel.style.display = 'block';
    }

</script>

<?php
$conn->close();
?>
</body>
</html>