<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
if (!isset($_SESSION['logged_in'])) { header("Location: login.php"); exit(); }

// Include database connection
require_once 'db_connect.php';

// ACCESS CONTROL: Only admins can access the general search page.
// Other users can only access this page if a specific document ID is provided in the URL.
$user_role = $_SESSION['role'] ?? 'Guest';
$is_mis = ($user_role === 'Management Information System Office');
$has_track_id = (isset($_GET['track_id']) && !empty($_GET['track_id'])) || (isset($_GET['track_id_manual']) && !empty($_GET['track_id_manual']));

if (!$has_track_id && !$is_mis) {
    header("Location: home.php");
    exit();
}

$success_msg = "";
$error_msg = "";

// --- ADMIN ACTION: CANCEL DOCUMENT ---
if ($is_mis && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cancel_document'])) {
    $voucher_to_cancel = $_POST['voucher_id_to_cancel'];
    $cancellation_remark = trim($_POST['cancellation_remark']);
    $current_admin_id = $_SESSION['user_id'];

    if (empty($cancellation_remark)) {
        $error_msg = "A reason is required to cancel a document.";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Update voucher status to 'Cancelled'
            $update_stmt = $conn->prepare("UPDATE vouchers SET status = 'Cancelled' WHERE voucher_code = ?");
            $update_stmt->bind_param("s", $voucher_to_cancel);
            $update_stmt->execute();
            $update_stmt->close();

            // 2. Log the cancellation action
            $log_stmt = $conn->prepare("INSERT INTO audit_logs (voucher_code, department, action_taken, remarks, processed_by_user_id) VALUES (?, ?, 'CANCELLED_BY_ADMIN', ?, ?)");
            $log_stmt->bind_param("sssi", $voucher_to_cancel, $user_role, $cancellation_remark, $current_admin_id);
            $log_stmt->execute();
            $log_stmt->close();

            // 3. Notify the original requestor
            $req_id_stmt = $conn->prepare("SELECT requestor_id FROM vouchers WHERE voucher_code = ?");
            $req_id_stmt->bind_param("s", $voucher_to_cancel);
            $req_id_stmt->execute();
            $requestor_id_for_notif = $req_id_stmt->get_result()->fetch_assoc()['requestor_id'] ?? 0;
            $req_id_stmt->close();

            if ($requestor_id_for_notif > 0) {
                $notif_message = "Your document " . $voucher_to_cancel . " has been cancelled by an administrator. Reason: " . $cancellation_remark;
                $notif_link = "track.php?track_id=" . urlencode($voucher_to_cancel);
                create_notification($conn, $requestor_id_for_notif, $notif_message, $notif_link);
            }

            $conn->commit();
            header("Location: track.php?track_id=" . urlencode($voucher_to_cancel) . "&cancelled=true");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "An error occurred during cancellation: " . $e->getMessage();
        }
    }
}

// --- ADMIN ACTION: ARCHIVE DOCUMENT ---
if ($is_mis && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['archive_document'])) {
    $voucher_to_archive = $_POST['voucher_id_to_archive'];
    $current_admin_id = $_SESSION['user_id'];

    $conn->begin_transaction();
    try {
        // 1. Find the voucher to archive
        $select_voucher_stmt = $conn->prepare("SELECT * FROM vouchers WHERE voucher_code = ?");
        $select_voucher_stmt->bind_param("s", $voucher_to_archive);
        $select_voucher_stmt->execute();
        $voucher_result = $select_voucher_stmt->get_result();
        
        if ($voucher_result->num_rows > 0) {
            $voucher_data = $voucher_result->fetch_assoc();
            $select_voucher_stmt->close();

            // 2. Insert into vouchers_archive
            $columns = array_keys($voucher_data);
            $placeholders = array_fill(0, count($columns), '?');
            $insert_voucher_sql = "INSERT INTO vouchers_archive (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            $insert_voucher_stmt = $conn->prepare($insert_voucher_sql);
            
            $types = "";
            foreach ($voucher_data as $value) {
                if (is_int($value)) $types .= 'i';
                elseif (is_float($value)) $types .= 'd';
                else $types .= 's';
            }
            $insert_voucher_stmt->bind_param($types, ...array_values($voucher_data));
            $insert_voucher_stmt->execute();
            $insert_voucher_stmt->close();

            // 3. Move audit logs
            $select_logs_stmt = $conn->prepare("SELECT * FROM audit_logs WHERE voucher_code = ?");
            $select_logs_stmt->bind_param("s", $voucher_to_archive);
            $select_logs_stmt->execute();
            $logs_result = $select_logs_stmt->get_result();
            
            if ($logs_result->num_rows > 0) {
                $logs_data = $logs_result->fetch_all(MYSQLI_ASSOC);
                $select_logs_stmt->close();

                $log_columns = array_keys($logs_data[0]);
                $log_placeholders = array_fill(0, count($log_columns), '?');
                $insert_log_sql = "INSERT INTO audit_logs_archive (`" . implode('`, `', $log_columns) . "`) VALUES (" . implode(', ', $log_placeholders) . ")";
                $insert_log_stmt = $conn->prepare($insert_log_sql);
                
                $log_types = str_repeat('s', count($log_columns)); // Simpler to use string for all log types

                foreach ($logs_data as $log_row) {
                    $insert_log_stmt->bind_param($log_types, ...array_values($log_row));
                    $insert_log_stmt->execute();
                }
                $insert_log_stmt->close();

                $delete_logs_stmt = $conn->prepare("DELETE FROM audit_logs WHERE voucher_code = ?");
                $delete_logs_stmt->bind_param("s", $voucher_to_archive);
                $delete_logs_stmt->execute();
                $delete_logs_stmt->close();
            } else { $select_logs_stmt->close(); }

            $delete_voucher_stmt = $conn->prepare("DELETE FROM vouchers WHERE voucher_code = ?");
            $delete_voucher_stmt->bind_param("s", $voucher_to_archive);
            $delete_voucher_stmt->execute();
            $delete_voucher_stmt->close();

            $conn->commit();
            header("Location: list.php?view=all&archived=" . urlencode($voucher_to_archive));
            exit();
        } else { throw new Exception("Voucher not found in the active table."); }
    } catch (Exception $e) { $conn->rollback(); $error_msg = "An error occurred during archival: " . $e->getMessage(); }
}

// DEFINE WORKFLOW STAGES DYNAMICALLY
$workflow_sequence = [];
$seq_res = $conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1 ORDER BY name ASC");
while ($row = $seq_res->fetch_assoc()) {
    $workflow_sequence[] = $row['name'];
}

// 2. GET CURRENT USER'S ID
$current_username = $_SESSION['username'];
$id_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
$id_stmt->bind_param("s", $current_username);
$id_stmt->execute();
$id_result = $id_stmt->get_result();
$user_id = ($id_result->num_rows > 0) ? $id_result->fetch_assoc()['user_id'] : 0;
$id_stmt->close();

// 3. FETCH USER'S OWN VOUCHERS FOR THE DROPDOWN
$my_vouchers_list = [];
$dropdown_stmt = $conn->prepare("SELECT voucher_code, document_title, status FROM vouchers WHERE requestor_id = ? ORDER BY date_submitted DESC");
$dropdown_stmt->bind_param("i", $user_id); 
$dropdown_stmt->execute();
$dropdown_res = $dropdown_stmt->get_result();

while($row = $dropdown_res->fetch_assoc()) {
    $my_vouchers_list[$row['voucher_code']] = $row['document_title'] . " (" . $row['status'] . ")";
}
$dropdown_stmt->close();

// 4. DETERMINE WHICH VOUCHER TO DISPLAY (Supports Manual Search for Signatories)
$selected_id = '';
if (isset($_GET['track_id_manual']) && trim($_GET['track_id_manual']) !== '') {
    $selected_id = strtoupper(trim($_GET['track_id_manual']));
} elseif (isset($_GET['track_id']) && !empty($_GET['track_id'])) {
    $selected_id = $_GET['track_id'];
}


// 6. FETCH REAL VOUCHER DATA
$v_data = [
    "ID" => $selected_id, 
    "Document_Title" => "Select a document", 
    "Date_Submitted" => date('Y-m-d'),
    "Status" => "Pending", 
    "Current_Stage_Index" => 0, 
    "Logs" => []
];
$stages = [["name" => "Requestor"]]; // Initialize with Requestor

if (!empty($selected_id)) {
    $v_stmt = $conn->prepare("
        SELECT v.*, vt.name as voucher_type_name, dt.arta_level, al.processing_days, u.full_name as requestor_full_name
        FROM vouchers v 
        LEFT JOIN users u ON v.requestor_id = u.user_id
        LEFT JOIN document_types dt ON v.doc_type_id = dt.id
        LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
        LEFT JOIN arta_levels al ON al.level_name = COALESCE(vt.arta_level, dt.arta_level)
        WHERE v.voucher_code = ?
    ");
    $v_stmt->bind_param("s", $selected_id);
    $v_stmt->execute();
    $v_res = $v_stmt->get_result();

    if ($v_res->num_rows > 0) {
        $row = $v_res->fetch_assoc();

        // PRIVATE ACCOUNT PER USER: Check if the current user is the requestor or MIS
        if (!$is_mis && $row['requestor_id'] != $user_id) {
            $v_data['Status'] = "Unauthorized Access";
        } else {
            // DYNAMICALLY SET STAGES from the document's own workflow
            $doc_workflow = json_decode($row['custom_workflow'], true);
            if (empty($doc_workflow)) { $doc_workflow = $workflow_sequence; } // Fallback

            foreach ($doc_workflow as $role) {
                $stages[] = ["name" => $role];
            }
            
            $v_data['ID'] = $row['voucher_code']; // This is the tracking ID
            $v_data['Document_Title'] = $row['document_title']; 
            $v_data['Date_Submitted'] = $row['date_submitted'];
            $v_data['Status'] = $row['status'];
            $v_data['Current_Stage_Index'] = (int)$row['current_stage_index'];
            // Add new fields
            $v_data['Reference_Number'] = $row['reference_number'];
            $v_data['Tags'] = $row['tags'];
            $v_data['Amount'] = $row['amount'];
            $v_data['Budget_Code'] = $row['budget_code'];
            $v_data['Voucher_Type_Name'] = $row['voucher_type_name'];
            $v_data['Arta_Level'] = $row['arta_level']; // This will be updated below
            $v_data['Processing_Days'] = $row['processing_days'];
            $v_data['Requestor_Name'] = $row['requestor_full_name'];
            
            // NEW: Fetch return remarks if status is 'Returned'
            $return_remarks = "";
            if ($v_data['Status'] === 'Returned') {
                $remarks_stmt = $conn->prepare("SELECT remarks FROM audit_logs WHERE voucher_code = ? AND action_taken = 'RETURNED' ORDER BY log_id DESC LIMIT 1");
                $remarks_stmt->bind_param("s", $selected_id);
                $remarks_stmt->execute();
                $remarks_res = $remarks_stmt->get_result();
                if ($remarks_row = $remarks_res->fetch_assoc()) {
                    $return_remarks = $remarks_row['remarks'];
                }
                $remarks_stmt->close();
            }
            
            $v_data['Logs'][] = [
                "Dept" => "Requestor", 
                "TimeIn" => format_db_timestamp($row['date_submitted']),
                "TimeOut" => format_db_timestamp($row['date_submitted']),
                "Action" => "Submitted", 
                "Remarks" => "Voucher Initial Submission",
                "StayTime" => "0h 0m"
            ];

            // Re-fetch ARTA level and processing days using COALESCE for accuracy
            $arta_info_stmt = $conn->prepare("
                SELECT al.processing_days, COALESCE(vt.arta_level, dt.arta_level) AS effective_arta_level
                FROM vouchers v
                LEFT JOIN document_types dt ON v.doc_type_id = dt.id
                LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
                LEFT JOIN arta_levels al ON al.level_name = COALESCE(vt.arta_level, dt.arta_level)
                WHERE v.voucher_code = ?
            ");
            $arta_info_stmt->bind_param("s", $selected_id);
            $arta_info_stmt->execute();
            $arta_info_res = $arta_info_stmt->get_result();
            if ($arta_info_row = $arta_info_res->fetch_assoc()) {
                $v_data['Arta_Level'] = $arta_info_row['effective_arta_level'];
                $v_data['Processing_Days'] = $arta_info_row['processing_days'];
            }
            $arta_info_stmt->close();

            $prev_time = $row['date_submitted']; 
            $audit_stmt = $conn->prepare("SELECT * FROM audit_logs WHERE voucher_code = ? ORDER BY log_id ASC");
            
            $is_received = false;
            $current_stage_name = $stages[$v_data['Current_Stage_Index']]['name'] ?? '';
            
            if ($audit_stmt) {
                $audit_stmt->bind_param("s", $selected_id);
                $audit_stmt->execute();
                $audit_res = $audit_stmt->get_result();
                
                while($a_row = $audit_res->fetch_assoc()) {
                    $time_in = $prev_time;
                    $time_out = date('Y-m-d H:i:s'); // Fallback
                    if (isset($a_row['created_at'])) $time_out = $a_row['created_at'];
                    elseif (isset($a_row['timestamp'])) $time_out = $a_row['timestamp'];
                    elseif (isset($a_row['date_processed'])) $time_out = $a_row['date_processed'];

                    $diff = strtotime($time_out) - strtotime($time_in);
                    $hours = floor($diff / 3600);
                    $minutes = floor(($diff / 60) % 60);
                    
                    $v_data['Logs'][] = [
                        "Dept" => $a_row['department'],
                        "TimeIn" => format_db_timestamp($time_in),
                        "TimeOut" => format_db_timestamp($time_out),
                        "Action" => $a_row['action_taken'],
                        "Remarks" => $a_row['remarks'],
                        "StayTime" => "{$hours}h {$minutes}m"
                    ];
                    
                    $prev_time = $time_out;

                    // Check if the current stage has officially received the physical document
                    if ($a_row['action_taken'] === 'Scan-to-Receive' && $a_row['department'] === $current_stage_name) {
                        $is_received = true;
                    }
                }
                $audit_stmt->close();
            }

            if (!in_array($v_data['Status'], ['Approved', 'Paid', 'Returned', 'Rejected', 'Ready for Release', 'Received'])) {
                $current_stage_name = $stages[$v_data['Current_Stage_Index']]['name'] ?? 'Unknown';
                $diff = time() - strtotime($prev_time);
                $hours = floor($diff / 3600);
                $minutes = floor(($diff / 60) % 60);

                $v_data['Logs'][] = [
                    "Dept" => $current_stage_name,
                    "TimeIn" => format_db_timestamp($prev_time),
                    "TimeOut" => "Pending...",
                    "Action" => "Under Review",
                    "Remarks" => "Currently processing...",
                    "StayTime" => "{$hours}h {$minutes}m (Running)"
                ];
            }
        }
    } else {
        $v_data['Status'] = "Not Found";
    }
    $v_stmt->close();
}

$active_color = "#10b981";
if($v_data['Status'] == 'Returned' || $v_data['Status'] == 'Rejected') $active_color = "#ef4444"; 
?>

<?php
$is_final_success_state = in_array($v_data['Status'], ['Approved', 'Paid', 'Ready for Release', 'Received']);
$progress_width = 0;
if (count($stages) > 1) {
    $progress_width = ($v_data['Current_Stage_Index'] / (count($stages) - 1)) * 100;
}
if ($is_final_success_state) {
    $progress_width = 100;
}

// --- NEW: Dynamic line calculation for better layout ---
$num_stages = count($stages);
$line_margin_percent = 0;
if ($num_stages > 1) {
    // The margin is half the width of one step's container (1/N * 1/2)
    $line_margin_percent = (1 / $num_stages / 2) * 100;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title>NAAP - Track & Print Voucher</title>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo filemtime('sidebar.css'); ?>">
    <link rel="stylesheet" href="track.css?v=<?php echo filemtime('track.css'); ?>">
    <style>
        .admin-actions-card {
            background-color: #fffbeb;
            border: 1px solid #fde68a;
            border-left: 5px solid #f59e0b;
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
        }
        .admin-actions-card h3 { margin-top: 0; color: #92400e; }
        .admin-actions-card p { font-size: 0.9rem; color: #b45309; margin-bottom: 15px; }
        .admin-actions-card textarea { width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #fcd34d; margin-bottom: 10px; }
        .btn-cancel-doc {
            background-color: #ef4444;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
        }
        .btn-archive-doc {
            background-color: #8b5cf6; /* Purple */
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
        }
    </style>
</head>
<body>

<?php include('sidebar.php'); ?>

<div class="main-content">
    <div id="print-header" class="print-header">
        <h1>NATIONAL AVIATION ACADEMY OF THE PHILIPPINES</h1>
        <h2 id="print-title"></h2>
        <p id="print-subtitle"></p>
    </div>

    <div class="page-header">
        <h1>Voucher Tracker</h1>
        <p>Review history or reprint QR codes for physical document attachment.</p>
    </div>

    <form class="selection-card" method="GET">
        <div style="flex: 1;">
            <label style="font-weight: 600; display: block; margin-bottom: 8px; font-size: 0.9rem; color: var(--naap-navy); text-transform: uppercase;">Select from your requests</label>
            <select name="track_id">
                <option value="">-- Choose Voucher --</option>
                <?php foreach($my_vouchers_list as $id => $desc): ?>
                    <option value="<?php echo $id; ?>" <?php if($selected_id == $id && empty($_GET['track_id_manual'])) echo 'selected'; ?>>
                        <?php echo $id . " - " . htmlspecialchars($desc); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="or-divider" style="font-weight: 800; color: var(--border-light); font-size: 1.2rem;">OR</div>
        
        <div style="flex: 1;">
            <label style="font-weight: 600; display: block; margin-bottom: 8px; font-size: 0.9rem; color: var(--naap-navy); text-transform: uppercase;">Search by ID (Signatory Search)</label>
            <input type="text" name="track_id_manual" placeholder="e.g. NAAP-YYYY-XXXX" value="<?php echo isset($_GET['track_id_manual']) ? htmlspecialchars(strtoupper(trim($_GET['track_id_manual']))) : ''; ?>">
        </div>

        <button type="submit" style="align-self: flex-end;">🔍 Track</button>
    </form>

    <?php if(!empty($selected_id) && $v_data['Status'] !== 'Not Found'): ?>
        
        <?php if($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>
        <?php if($error_msg): ?> <div class="alert alert-error"><?php echo $error_msg; ?></div> <?php endif; ?>
        
        <div class="qr-print-card" id="printable-qr">
            <div id="qr-card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-light); padding-bottom: 15px; margin-bottom: 20px;">
                <div>
                    <h2 style="margin: 0; color: var(--naap-navy); font-size: 1.4rem;">VOUCHER ROUTING SLIP</h2>
                    <p style="margin: 5px 0 0 0; color: var(--text-muted); font-size: 0.9rem;">Attach this slip to your physical documents for scanning.</p>
                </div>
                <button onclick="printQR()" class="btn-print-qr">🖨️ Print Slip</button>
            </div>
            
            <div class="qr-flex-container" style="display: flex; align-items: center; justify-content: space-between; gap: 20px;">
                <div style="flex: 1; line-height: 1.6;">
                    <div style="font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase; font-weight: bold;">Voucher ID</div>
                    <div style="font-size: 1.8rem; color: var(--naap-navy); font-weight: 900; margin-bottom: 15px; letter-spacing: 1px;"><?php echo $v_data['ID']; ?></div>

                    <div class="qr-details-grid">
                        <div>
                            <span style="color: var(--text-muted); font-size: 0.85rem; font-weight: bold; text-transform: uppercase;">Document Title</span><br>
                            <span style="font-weight: 600; color: var(--text-dark); font-size: 1.05rem;"><?php echo htmlspecialchars($v_data['Document_Title']); ?></span>
                        </div>
                        <div>
                            <span style="color: var(--text-muted); font-size: 0.85rem; font-weight: bold; text-transform: uppercase;">Requested By</span><br>
                            <span style="font-weight: 600; color: var(--text-dark); font-size: 1.05rem;"><?php echo htmlspecialchars($v_data['Requestor_Name'] ?? 'N/A'); ?></span>
                        </div>
                        <?php if (!empty($v_data['Arta_Level'])): ?>
                        <div>
                            <span style="color: var(--text-muted); font-size: 0.85rem; font-weight: bold; text-transform: uppercase;">ARTA Process Time</span><br>
                            <span style="font-weight: 600; color: var(--text-dark); font-size: 1.05rem;"><?php echo htmlspecialchars($v_data['Arta_Level']); ?> (<?php echo $v_data['Processing_Days']; ?> Days)</span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($v_data['Voucher_Type_Name'])): ?>
                        <div>
                            <span style="color: var(--text-muted); font-size: 0.85rem; font-weight: bold; text-transform: uppercase;">Voucher Type</span><br>
                            <span style="font-weight: 600; color: var(--text-dark); font-size: 1.05rem;"><?php echo htmlspecialchars($v_data['Voucher_Type_Name']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($v_data['Reference_Number'])): ?>
                        <div>
                            <span style="color: var(--text-muted); font-size: 0.85rem; font-weight: bold; text-transform: uppercase;">Reference #</span><br>
                            <span style="font-weight: 600; color: var(--text-dark); font-size: 1.05rem;"><?php echo htmlspecialchars($v_data['Reference_Number']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($v_data['Tags'])): ?>
                        <div>
                            <span style="color: var(--text-muted); font-size: 0.85rem; font-weight: bold; text-transform: uppercase;">Tags</span><br>
                            <span style="font-weight: 600; color: var(--text-dark); font-size: 1.05rem;"><?php echo htmlspecialchars($v_data['Tags']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($v_data['Amount'])): ?>
                        <div>
                            <span style="color: var(--text-muted); font-size: 0.85rem; font-weight: bold; text-transform: uppercase;">Amount</span><br>
                            <span style="font-weight: 800; color: #10b981; font-size: 1.2rem;">₱<?php echo number_format($v_data['Amount'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <div>
                            <span style="color: var(--text-muted); font-size: 0.85rem; font-weight: bold; text-transform: uppercase;">Date Created</span><br>
                            <span style="font-weight: 600; color: var(--text-dark); font-size: 1.05rem;"><?php echo format_db_timestamp($v_data['Date_Submitted'], 'F d, Y'); ?></span>
                        </div>
                    </div>
                </div>
                <div style="text-align: center; background: #f8fafc; padding: 15px; border: 2px dashed #cbd5e1; border-radius: 12px; width: 150px;">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($v_data['ID']); ?>" alt="QR Code" style="width: 100%; height: auto; display: block;">
                    <div style="font-size: 0.85rem; font-weight: 800; margin-top: 10px; color: var(--naap-navy); letter-spacing: 0.5px;">SCAN TO RECEIVE</div>
                </div>
            </div>
        </div>

        <?php 
        $is_cancellable = !in_array($v_data['Status'], ['Approved', 'Paid', 'Rejected', 'Returned', 'Resubmitted', 'Lapsed', 'Cancelled', 'Received']);
        if ($is_mis && $is_cancellable): ?>
        <div class="admin-actions-card">
            <h3>Administrator Actions</h3>
            <p>As an MIS administrator, you can force-cancel this document if it was created in error or is a duplicate. This action is irreversible and will be logged.</p>
            <form method="POST" onsubmit="return confirm('Are you sure you want to permanently cancel this document? This action cannot be undone.');">
                <input type="hidden" name="voucher_id_to_cancel" value="<?php echo htmlspecialchars($v_data['ID']); ?>">
                <div class="input-group">
                    <label for="cancellation_remark" style="font-weight: bold; color: #92400e;">Reason for Cancellation (Required)</label>
                    <textarea id="cancellation_remark" name="cancellation_remark" rows="3" required placeholder="e.g., Duplicate submission, created for testing."></textarea>
                </div>
                <button type="submit" name="cancel_document" class="btn-cancel-doc">Force-Cancel Document</button>
            </form>
        </div>
        <?php endif; ?>

        <?php 
        $is_archivable = in_array($v_data['Status'], ['Approved', 'Paid', 'Rejected', 'Returned', 'Lapsed', 'Cancelled', 'Received']);
        if ($is_mis && $is_archivable): ?>
        <div class="admin-actions-card" style="border-left-color: #8b5cf6; background-color: #f5f3ff;">
            <h3 style="color: #5b21b6;">Archive Document</h3>
            <p style="color: #6d28d9;">Archiving removes the document from all active queues and lists, moving it to the Central Repository's historical records. This is for housekeeping and should be used on completed or old documents. This action is irreversible.</p>
            <form method="POST" onsubmit="return confirm('Are you sure you want to archive this document? It will be removed from active lists and moved to the historical archive.');">
                <input type="hidden" name="voucher_id_to_archive" value="<?php echo htmlspecialchars($v_data['ID']); ?>">
                <button type="submit" name="archive_document" class="btn-archive-doc">Archive Document</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="tracker-container">
            <div class="stepper-wrapper">
                <div class="stepper-line-bg" style="left: <?php echo $line_margin_percent; ?>%; right: <?php echo $line_margin_percent; ?>%;">
                    <div class="stepper-line-progress" style="background: <?php echo $active_color; ?>; width: <?php echo $progress_width ?? 0; ?>%;"></div>
                </div>
                <?php foreach ($stages as $index => $stage): 
                    $step_class = 'pending';
                    if ($is_final_success_state) {
                        // If the document is fully approved/completed, all steps are 'completed'.
                        $step_class = 'completed';
                    } else {
                        // Original logic for in-progress documents
                        if ($index < $v_data['Current_Stage_Index']) {
                            $step_class = 'completed';
                        } elseif ($index == $v_data['Current_Stage_Index']) {
                            $step_class = 'current';
                            if (isset($is_received) && $is_received) {
                                $step_class .= ' received';
                            }
                        }
                        // Special case for returned/rejected/cancelled/lapsed
                        if (in_array($v_data['Status'], ['Returned', 'Rejected', 'Cancelled', 'Lapsed']) && $index == $v_data['Current_Stage_Index']) {
                            $step_class .= ' error';
                            $step_class = str_replace(' received', '', $step_class); // Clear received class on error
                        }
                    }
                ?>
                    <div class="step <?php echo $step_class; ?>">
                        <div class="step-icon"><!-- Icon is now handled by CSS --></div>
                        <div class="step-label"><?php echo $stage['name']; ?></div>
                        <?php 
                        // Show status text for the final completed step or any current/error step.
                        $is_last_completed_step = ($is_final_success_state && $index == $v_data['Current_Stage_Index']);
                        if(strpos($step_class, 'current') !== false || strpos($step_class, 'error') !== false || $is_last_completed_step): 
                        ?>
                            <div class="step-status">
                                <?php 
                                if ($v_data['Status'] == 'Returned') { echo '⚠️ ACTION REQUIRED'; }
                                elseif (in_array($v_data['Status'], ['Rejected', 'Cancelled', 'Lapsed'])) { echo '❌ TERMINATED'; }
                                elseif ($is_final_success_state) { echo '✔️ COMPLETED'; }
                                else { echo (isset($is_received) && $is_received) ? '● RECEIVED' : '● IN TRANSIT'; }
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if($v_data['Status'] == 'Returned'): ?>
        <div class="alert-returned">
            <strong>⚠️ VOUCHER RETURNED:</strong> This voucher has been sent back for corrections.
            <?php if (!empty($return_remarks)): ?>
                <div class="return-remarks-box">
                    <strong>Signatory's Remarks:</strong>
                    <pre><?php echo htmlspecialchars($return_remarks); ?></pre>
                </div>
            <?php else: ?>
                <p>Please see the "Remarks" column in the activity log below for details from the signatory.</p>
            <?php endif; ?>
        </div>
        <?php // Only show the resubmit button if the current user is the original requestor
        if ($v_data['Requestor_Name'] === $_SESSION['full_name']): ?>
        <div style="text-align: center; margin-top: 20px;">
            <a href="resubmit.php?edit_id=<?php echo urlencode($v_data['ID']); ?>" class="btn-resubmit">✏️ Resubmit Document</a>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <h3 class="history-title" style="margin-top: 40px; color: var(--naap-navy);">⏱️ Activity & Stay-Time Logs</h3>
        <div class="table-responsive">
            <table class="log-table">
                <thead>
                    <tr>
                        <th style="width: 15%;">Office / Dept</th>
                        <th style="width: 15%;">Time In</th>
                        <th style="width: 15%;">Time Out</th>
                        <th style="width: 15%;">Stay Time</th>
                        <th style="width: 12%;">Action</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($v_data['Logs'])): ?>
                        <?php foreach ($v_data['Logs'] as $log): ?>
                        <tr style="<?php if($log['Action'] == 'RETURNED') echo 'background-color: #fef2f2;'; elseif($log['Action'] == 'Under Review') echo 'background-color: #f0fdf4;'; ?>">
                            <td style="font-weight: bold;"><?php echo $log['Dept']; ?></td>
                            <td><span class="time-badge"><?php echo $log['TimeIn']; ?></span></td>
                            <td><span class="time-badge"><?php echo $log['TimeOut']; ?></span></td>
                            <td><span class="stay-badge"><?php echo $log['StayTime']; ?></span></td>
                            <td>
                                <?php 
                                $color = 'var(--text-dark)';
                                if(strpos($log['Action'], 'Approved') !== false) $color = '#10b981'; 
                                if(strpos($log['Action'], 'Submitted') !== false) $color = 'var(--naap-navy)'; 
                                if(strpos($log['Action'], 'RETURNED') !== false || strpos($log['Action'], 'REJECTED') !== false) $color = '#ef4444'; 
                                echo "<span style='color: $color; font-weight: 800;'>{$log['Action']}</span>";
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($log['Remarks']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No logs available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="print-history-btn-container" style="margin-top: 25px; text-align: right;">
            <button onclick="printHistory()" style="padding: 12px 25px; border: 1px solid var(--border-light); border-radius: 6px; background: white; cursor: pointer; color: var(--text-dark); font-weight: bold; transition: 0.2s;">🖨️ Print Detailed History</button>
        </div>

    <?php elseif(!empty($selected_id) && $v_data['Status'] === 'Not Found'): ?>
        <div class="alert-returned" style="border-left-color: var(--naap-gold); background: var(--bg-gray); color: var(--text-dark);">
            Cannot find tracking data for the entered ID. Please verify the Voucher Code.
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 40px 20px; background: white; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 5px solid var(--naap-navy);">
            <h2 style="color: var(--naap-navy); margin-top: 0;">Ready to Track</h2>
            <p style="color: var(--text-muted); max-width: 500px; margin: 0 auto;">Please select one of your submitted vouchers from the dropdown menu above, or enter a Voucher ID manually to view its tracking history and status.</p>
        </div>
    <?php endif; ?>

</div>

<!-- Include external scripts -->
<script>
    function printQR() {
        const printTitle = document.getElementById('print-title');
        const printSubtitle = document.getElementById('print-subtitle');
        if (printTitle) printTitle.textContent = 'Voucher Routing Slip';
        if (printSubtitle) printSubtitle.innerHTML = 'Voucher ID: <strong><?php echo htmlspecialchars($v_data['ID']); ?></strong> | Attach to physical documents.';
        document.body.classList.add('print-qr-only');
        window.print();
        setTimeout(() => document.body.classList.remove('print-qr-only'), 500);
    }

    function printHistory() {
        document.getElementById('print-title').textContent = 'Document Tracking History';
        document.getElementById('print-subtitle').innerHTML = 'Voucher ID: <strong><?php echo htmlspecialchars($v_data['ID']); ?></strong> | Title: <strong><?php echo htmlspecialchars($v_data['Document_Title']); ?></strong>';
        document.body.classList.add('print-history-only');
        window.print();
        setTimeout(() => document.body.classList.remove('print-history-only'), 500);
    }
</script>

<?php
$conn->close();
?>
</body>
</html>