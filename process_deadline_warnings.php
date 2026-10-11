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
        AND DATEDIFF(arta_deadline, CURDATE()) BETWEEN 0 AND ?
        AND deadline_warning_sent = 0
");
$at_risk_stmt->bind_param("i", $warning_days);
$at_risk_stmt->execute();
$at_risk_docs = $at_risk_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$at_risk_stmt->close();

if (empty($at_risk_docs)) {
    echo "<p>No documents are currently nearing their deadline.</p>";
} else {
    echo "<p>Found " . count($at_risk_docs) . " document(s) nearing their deadline. Sending notifications...</p>";
}

// --- 4. Process Each At-Risk Document (Send Warnings) ---
$notifications_sent = 0;
foreach ($at_risk_docs as $doc) {
    $voucher_code = $doc['voucher_code'];
    try {
        $conn->begin_transaction();
        $warning_lock_stmt = $conn->prepare("
            SELECT document_title, current_stage_index, custom_workflow, arta_deadline, requestor_id
            FROM vouchers
            WHERE voucher_code = ? AND status IN ('Pending Review', 'Processing', 'In Transit')
              AND deadline_warning_sent = 0
              AND DATEDIFF(arta_deadline, CURDATE()) BETWEEN 0 AND ?
            FOR UPDATE
        ");
        if (!$warning_lock_stmt) {
            throw new RuntimeException('Could not prepare deadline warning lock: ' . $conn->error);
        }
        $warning_lock_stmt->bind_param('si', $voucher_code, $warning_days);
        if (!$warning_lock_stmt->execute()) {
            throw new RuntimeException('Could not load deadline warning record: ' . $warning_lock_stmt->error);
        }
        $warning_doc = $warning_lock_stmt->get_result()->fetch_assoc();
        $warning_lock_stmt->close();
        if (!$warning_doc) {
            $conn->rollback();
            continue;
        }

        $doc_workflow = json_decode($warning_doc['custom_workflow'], true);
        if (!is_array($doc_workflow) || !$doc_workflow) {
            $doc_workflow = $workflow_sequence;
        }
        $current_stage_0_indexed = max(0, (int)$warning_doc['current_stage_index'] - 1);
        $current_dept = $doc_workflow[$current_stage_0_indexed] ?? null;
        if (!$current_dept) {
            throw new RuntimeException('Could not determine the current workflow route.');
        }

        $requestor_id = (int)$warning_doc['requestor_id'];
        $days_left = (new DateTime())->diff(new DateTime($warning_doc['arta_deadline']))->days;
        $deadline_date_formatted = date('F d, Y', strtotime($warning_doc['arta_deadline']));
        $title = display_document_title($warning_doc['document_title']);
        $notif_message = "Reminder: Document {$voucher_code} ('{$title}') is due on {$deadline_date_formatted} ({$days_left} day(s) left). Please process it promptly.";
        $track_link = "track.php?track_id=" . urlencode($voucher_code);
        $queue_link = "queue.php?select_id=" . urlencode($voucher_code);
        $current_recipients = get_notification_recipients_for_route($conn, $current_dept, $requestor_id);
        if (!$current_recipients || $requestor_id < 1) {
            throw new RuntimeException('Could not resolve the requestor and current route recipients.');
        }
        if (!create_notification($conn, $requestor_id, $notif_message, $track_link)) {
            throw new RuntimeException('Could not notify the requestor about the approaching deadline.');
        }
        $sent_for_doc = notify_routing_stage($conn, $current_dept, $requestor_id, $notif_message, $queue_link, [$requestor_id]);
        if (!$sent_for_doc && array_diff($current_recipients, [$requestor_id])) {
            throw new RuntimeException('Could not notify the current route about the approaching deadline.');
        }

        $next_dept = $doc_workflow[$current_stage_0_indexed + 1] ?? null;
        if ($next_dept) {
            $next_recipients = get_notification_recipients_for_route($conn, $next_dept, $requestor_id);
            if (!$next_recipients) {
                throw new RuntimeException('Could not resolve the next routing recipients.');
            }
            $next_sent = notify_routing_stage(
                $conn,
                $next_dept,
                $requestor_id,
                "Upcoming document {$voucher_code} is nearing its ARTA deadline. Prepare for the next routing step.",
                $queue_link,
                array_merge($current_recipients, [$requestor_id])
            );
            $sent_for_doc += $next_sent;
            if (!$next_sent && !array_intersect($next_recipients, array_merge($current_recipients, [$requestor_id]))) {
                throw new RuntimeException('Could not notify the next routing recipients.');
            }
        }

        $update_voucher_stmt = $conn->prepare("UPDATE vouchers SET deadline_warning_sent = 1 WHERE voucher_code = ?");
        if (!$update_voucher_stmt) {
            throw new RuntimeException('Could not prepare the warning delivery marker: ' . $conn->error);
        }
        $update_voucher_stmt->bind_param("s", $voucher_code);
        if (!$update_voucher_stmt->execute()) {
            throw new RuntimeException('Could not mark the warning delivered: ' . $update_voucher_stmt->error);
        }
        $update_voucher_stmt->close();
        $conn->commit();
        $notifications_sent += 1 + $sent_for_doc;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Deadline warning failed for ' . $voucher_code . ': ' . $e->getMessage());
        echo "<p>Warning could not be sent for " . htmlspecialchars($voucher_code, ENT_QUOTES, 'UTF-8') . "; it will be retried.</p>";
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
        status IN ('Pending Review', 'Processing', 'In Transit', 'Lapsed')
        AND arta_deadline IS NOT NULL
        AND arta_deadline < CURDATE()
        AND NOT EXISTS (
            SELECT 1 FROM audit_logs
            WHERE audit_logs.voucher_code = vouchers.voucher_code
              AND audit_logs.action_taken = 'ARTA_LAPSED_NOTIFICATIONS_SENT'
        )
");
$overdue_stmt->execute();
$overdue_docs = $overdue_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$overdue_stmt->close();

if (empty($overdue_docs)) {
    echo "<p>No documents are currently overdue.</p>";
} else {
    echo "<p>Found " . count($overdue_docs) . " overdue document(s). Escalating...</p>";
}

$lapsed_count = 0;
foreach ($overdue_docs as $doc) {
    $voucher_code = $doc['voucher_code'];
    try {
        $conn->begin_transaction();
        $locked_stmt = $conn->prepare("
            SELECT status, requestor_id, document_title, current_stage_index, custom_workflow
            FROM vouchers
            WHERE voucher_code = ? AND arta_deadline < CURDATE()
              AND status IN ('Pending Review', 'Processing', 'In Transit', 'Lapsed')
            FOR UPDATE
        ");
        if (!$locked_stmt) {
            throw new RuntimeException('Could not prepare overdue document lock: ' . $conn->error);
        }
        $locked_stmt->bind_param('s', $voucher_code);
        if (!$locked_stmt->execute()) {
            throw new RuntimeException('Could not load overdue document: ' . $locked_stmt->error);
        }
        $locked_doc = $locked_stmt->get_result()->fetch_assoc();
        $locked_stmt->close();
        if (!$locked_doc) {
            $conn->rollback();
            continue;
        }

        $marker_stmt = $conn->prepare("
            SELECT log_id FROM audit_logs
            WHERE voucher_code = ? AND action_taken = 'ARTA_LAPSED_NOTIFICATIONS_SENT'
            LIMIT 1
        ");
        if (!$marker_stmt) {
            throw new RuntimeException('Could not prepare overdue notification marker lookup: ' . $conn->error);
        }
        $marker_stmt->bind_param('s', $voucher_code);
        if (!$marker_stmt->execute()) {
            throw new RuntimeException('Could not check overdue notification marker: ' . $marker_stmt->error);
        }
        $already_notified = (bool)$marker_stmt->get_result()->fetch_assoc();
        $marker_stmt->close();
        if ($already_notified) {
            $conn->commit();
            continue;
        }

        $doc_workflow = json_decode($locked_doc['custom_workflow'], true);
        if (!is_array($doc_workflow) || !$doc_workflow) {
            $doc_workflow = $workflow_sequence;
        }
        $current_stage_0_indexed = max(0, (int)$locked_doc['current_stage_index'] - 1);
        $current_route = $doc_workflow[$current_stage_0_indexed] ?? null;
        $next_route = $doc_workflow[$current_stage_0_indexed + 1] ?? null;

        if ($locked_doc['status'] !== 'Lapsed') {
            $update_stmt = $conn->prepare("UPDATE vouchers SET status = 'Lapsed' WHERE voucher_code = ?");
            if (!$update_stmt) {
                throw new RuntimeException('Could not prepare overdue status update: ' . $conn->error);
            }
            $update_stmt->bind_param('s', $voucher_code);
            if (!$update_stmt->execute()) {
                throw new RuntimeException('Could not mark document overdue: ' . $update_stmt->error);
            }
            $update_stmt->close();

            $log_stmt = $conn->prepare("
                INSERT INTO audit_logs (voucher_code, department, action_taken, remarks, processed_by_user_id)
                VALUES (?, ?, 'ARTA_LAPSED', 'Document exceeded the mandated processing time.', NULL)
            ");
            if (!$log_stmt) {
                throw new RuntimeException('Could not prepare overdue audit log: ' . $conn->error);
            }
            $department_for_log = $current_route ?: 'Unknown';
            $log_stmt->bind_param('ss', $voucher_code, $department_for_log);
            if (!$log_stmt->execute()) {
                throw new RuntimeException('Could not record overdue audit log: ' . $log_stmt->error);
            }
            $log_stmt->close();
        }

        $requestor_id = (int)$locked_doc['requestor_id'];
        $title = display_document_title($locked_doc['document_title']);
        $current_label = $current_route ?: 'the active workflow stage';
        $message = "ALERT: Document {$voucher_code} ('{$title}') has exceeded its ARTA deadline at {$current_label}.";
        $track_link = 'track.php?track_id=' . urlencode($voucher_code);
        $queue_link = 'queue.php?select_id=' . urlencode($voucher_code);
        $current_recipients = $current_route
            ? get_notification_recipients_for_route($conn, $current_route, $requestor_id)
            : [];

        if ($requestor_id < 1 || !create_notification($conn, $requestor_id, $message, $track_link)) {
            throw new RuntimeException('Could not notify the requestor of the overdue document.');
        }
        if ($current_route && !$current_recipients) {
            throw new RuntimeException('No notification recipient could be resolved for current route ' . $current_route . '.');
        }
        if ($current_route) {
            $current_route_sent = notify_routing_stage($conn, $current_route, $requestor_id, $message, $queue_link, [$requestor_id]);
            if (!$current_route_sent && array_diff($current_recipients, [$requestor_id])) {
                throw new RuntimeException('Could not notify the current route ' . $current_route . '.');
            }
        }
        if ($next_route) {
            $next_message = "Overdue notice: Document {$voucher_code} ('{$title}') has lapsed at the preceding stage. Prepare for the next routing step; it has not yet been routed to your stage.";
            $next_recipients = get_notification_recipients_for_route($conn, $next_route, $requestor_id);
            $already_notified_recipients = array_merge($current_recipients, [$requestor_id]);
            $next_route_sent = notify_routing_stage($conn, $next_route, $requestor_id, $next_message, $queue_link, $already_notified_recipients);
            if (!$next_recipients || (!$next_route_sent && array_diff($next_recipients, $already_notified_recipients))) {
                throw new RuntimeException('Could not notify the next route ' . $next_route . '.');
            }
        }

        $mis_stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'Management Information System Office'");
        if (!$mis_stmt) {
            throw new RuntimeException('Could not prepare MIS escalation lookup: ' . $conn->error);
        }
        if (!$mis_stmt->execute()) {
            throw new RuntimeException('Could not load MIS escalation recipients: ' . $mis_stmt->error);
        }
        $mis_result = $mis_stmt->get_result();
        while ($mis_user = $mis_result->fetch_assoc()) {
            if (!create_notification($conn, (int)$mis_user['user_id'], $message, $track_link)) {
                $mis_stmt->close();
                throw new RuntimeException('Could not notify MIS of overdue document.');
            }
        }
        $mis_stmt->close();

        $marker_stmt = $conn->prepare("
            INSERT INTO audit_logs (voucher_code, department, action_taken, remarks, processed_by_user_id)
            VALUES (?, ?, 'ARTA_LAPSED_NOTIFICATIONS_SENT', 'Overdue notifications sent to requestor, current route, and next route.', NULL)
        ");
        if (!$marker_stmt) {
            throw new RuntimeException('Could not prepare overdue notification marker: ' . $conn->error);
        }
        $department_for_log = $current_route ?: 'Unknown';
        $marker_stmt->bind_param('ss', $voucher_code, $department_for_log);
        if (!$marker_stmt->execute()) {
            throw new RuntimeException('Could not record overdue notification marker: ' . $marker_stmt->error);
        }
        $marker_stmt->close();

        $conn->commit();
        $lapsed_count++;
        echo "<p>Marked " . htmlspecialchars($voucher_code, ENT_QUOTES, 'UTF-8') . " as lapsed and sent escalation notifications.</p>";
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Overdue escalation failed for ' . $voucher_code . ': ' . $e->getMessage());
        echo "<p>Escalation failed for " . htmlspecialchars($voucher_code, ENT_QUOTES, 'UTF-8') . "; it will be retried.</p>";
    }
}

echo "<h2>Overdue Process Complete.</h2>";
echo "<p>Total documents marked as Lapsed: {$lapsed_count}</p>";

$conn->close();
?>