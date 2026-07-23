<?php
// This script should be run periodically (e.g., once a day via a cron job)
// to send deadline warnings.

// Set a long execution time in case of many documents
set_time_limit(300); 

require_once 'db_connect.php';

echo "<h1>Processing Deadline Warnings...</h1>";

// --- 1. Define Warning Threshold ---
$warning_days = 2; // Notify if deadline is in 2 days or less

// --- 2. Get Default Workflow Sequence (Fallback) ---
$workflow_sequence = [];
$seq_res = $conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1 ORDER BY name ASC");
while ($row = $seq_res->fetch_assoc()) {
    $workflow_sequence[] = $row['name'];
}

// --- 3. Find At-Risk Documents (Nearing Deadline) ---
$at_risk_stmt = $conn->prepare("
    SELECT voucher_code, document_title, current_stage_index, custom_workflow, arta_deadline, requestor_id
    FROM vouchers
    WHERE 
        status IN ('Pending Review', 'Processing', 'In Transit')
        AND arta_deadline IS NOT NULL
        AND DATEDIFF(arta_deadline, CURDATE()) <= ?
        AND deadline_warning_sent = 0
");
$at_risk_stmt->bind_param("i", $warning_days);
$at_risk_stmt->execute();
$at_risk_docs = $at_risk_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$at_risk_stmt->close();

if (empty($at_risk_docs)) {
    echo "<p>No documents are currently nearing their deadline. All clear!</p>";
    $conn->close();
    exit();
}

echo "<p>Found " . count($at_risk_docs) . " document(s) nearing their deadline. Sending notifications...</p>";

// --- 4. Process Each At-Risk Document (Send Warnings) ---
$notifications_sent = 0;
foreach ($at_risk_docs as $doc) {
    $voucher_code = $doc['voucher_code'];
    $requestor_id = $doc['requestor_id'];
    
    // Determine the current signatory department
    $doc_workflow = json_decode($doc['custom_workflow'], true);
    if (empty($doc_workflow)) { $doc_workflow = $workflow_sequence; }
    
    $current_stage_0_indexed = $doc['current_stage_index'] - 1;
    $current_dept = $doc_workflow[$current_stage_0_indexed] ?? null;

    if (!$current_dept) {
        echo "<p style='color: orange;'>Warning: Could not determine current department for voucher {$voucher_code}. Skipping.</p>";
        continue;
    }

    $users_to_notify_stmt = null;

    if ($current_dept === 'Department Head') {
        // Get the requestor's department
        $req_dept_stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
        $req_dept_stmt->bind_param("i", $requestor_id);
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
        $users_to_notify_stmt = prepare_notification_statement_for_department($conn, $current_dept);
    }

    if ($users_to_notify_stmt) {
        $users_to_notify_stmt->execute();
        $users_res = $users_to_notify_stmt->get_result();

        if ($users_res->num_rows > 0) {
            $days_left = (new DateTime())->diff(new DateTime($doc['arta_deadline']))->days;
            $deadline_date_formatted = date('F d, Y', strtotime($doc['arta_deadline']));
            
            $notif_message = "URGENT: Document {$voucher_code} ('" . htmlspecialchars($doc['document_title']) . "') is due on {$deadline_date_formatted} ({$days_left} day/s left). Please process immediately.";
            $notif_link = "queue.php?select_id=" . urlencode($voucher_code);

            while ($user_row = $users_res->fetch_assoc()) {
                create_notification($conn, $user_row['user_id'], $notif_message, $notif_link);
                $notifications_sent++;
            }
            
            // Mark the voucher as having had its warning sent
            $update_voucher_stmt = $conn->prepare("UPDATE vouchers SET deadline_warning_sent = 1 WHERE voucher_code = ?");
            $update_voucher_stmt->bind_param("s", $voucher_code);
            $update_voucher_stmt->execute();
            $update_voucher_stmt->close();
            
            echo "<p style='color: green;'>Sent deadline warning for {$voucher_code} to users in the {$current_dept} department.</p>";
        }
        $users_to_notify_stmt->close();
    }
}

echo "<h2>Deadline Warning Process Complete.</h2>";
echo "<p>Total warning notifications sent: {$notifications_sent}</p>";

echo "<hr><h1>Processing Overdue ARTA Documents...</h1>";

// --- 5. Find and Process Overdue Documents ---
$overdue_stmt = $conn->prepare("
    SELECT voucher_code, document_title, current_stage_index, custom_workflow, arta_deadline, requestor_id
    FROM vouchers
    WHERE 
        status IN ('Pending Review', 'Processing', 'In Transit')
        AND arta_deadline IS NOT NULL
        AND arta_deadline < CURDATE()
");
$overdue_stmt->execute();
$overdue_docs = $overdue_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$overdue_stmt->close();

if (empty($overdue_docs)) {
    echo "<p>No documents are currently overdue. All clear!</p>";
    $conn->close();
    exit();
}

echo "<p>Found " . count($overdue_docs) . " overdue document(s). Taking automated action...</p>";

$lapsed_count = 0;
foreach ($overdue_docs as $doc) {
    $voucher_code = $doc['voucher_code'];
    
    // Determine the delinquent department
    $doc_workflow = json_decode($doc['custom_workflow'], true);
    if (empty($doc_workflow)) { $doc_workflow = $workflow_sequence; }
    $current_stage_0_indexed = $doc['current_stage_index'] - 1;
    $delinquent_dept = $doc_workflow[$current_stage_0_indexed] ?? 'Unknown';

    // 1. Update status to 'Lapsed'
    $update_stmt = $conn->prepare("UPDATE vouchers SET status = 'Lapsed' WHERE voucher_code = ?");
    $update_stmt->bind_param("s", $voucher_code);
    $update_stmt->execute();
    $update_stmt->close();

    // 2. Create a system-generated audit log
    $log_stmt = $conn->prepare("INSERT INTO audit_logs (voucher_code, department, action_taken, remarks, processed_by_user_id) VALUES (?, ?, 'ARTA_LAPSED', 'Document exceeded the mandated processing time.', NULL)");
    $log_stmt->bind_param("ss", $voucher_code, $delinquent_dept);
    $log_stmt->execute();
    $log_stmt->close();

    // 3. Send escalation notifications
    $notif_message = "ALERT: Document {$voucher_code} ('" . htmlspecialchars($doc['document_title']) . "') has LAPSED its ARTA deadline in the {$delinquent_dept} queue.";
    $notif_link = "track.php?track_id=" . urlencode($voucher_code);

    // Notify MIS Admin
    $mis_stmt = $conn->query("SELECT user_id FROM users WHERE role = 'Management Information System Office'");
    if ($mis_user = $mis_stmt->fetch_assoc()) {
        create_notification($conn, $mis_user['user_id'], $notif_message, $notif_link);
    }
    $mis_stmt->close();

    // Notify Head of the delinquent department
    $head_stmt = $conn->prepare("SELECT user_id FROM users WHERE role = ? AND is_head = 1");
    $head_stmt->bind_param("s", $delinquent_dept);
    $head_stmt->execute();
    $head_res = $head_stmt->get_result();
    while ($head_user = $head_res->fetch_assoc()) {
        create_notification($conn, $head_user['user_id'], $notif_message, $notif_link);
    }
    $head_stmt->close();

    $lapsed_count++;
    echo "<p style='color: red;'>Marked {$voucher_code} as LAPSED in department '{$delinquent_dept}'. Escalation notices sent.</p>";
}

echo "<h2>Overdue Process Complete.</h2>";
echo "<p>Total documents marked as Lapsed: {$lapsed_count}</p>";

$conn->close();
?>