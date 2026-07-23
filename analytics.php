<?php
session_start();
// ACCESS CONTROL: ONLY MIS and HR Head CAN ACCESS
$is_mis = ($_SESSION['role'] ?? '') === 'MIS';
$is_hr_head = (($_SESSION['role'] ?? '') === 'Human Resource Management Services Division' && ($_SESSION['is_head'] ?? 0) == 1);

if (!isset($_SESSION['logged_in']) || !($is_mis || $is_hr_head)) {
    header("Location: home.php");
    exit();
}

require_once 'db_connect.php';

// --- 1. ARTA COMPLIANCE RATE ---
$arta_stats = ['on_time' => 0, 'at_risk' => 0, 'overdue' => 0];

// Get active documents
$active_stmt = $conn->query("
    SELECT arta_deadline 
    FROM vouchers 
    WHERE status IN ('Pending Review', 'Processing', 'In Transit') AND arta_deadline IS NOT NULL
");
if ($active_stmt) {
    while ($row = $active_stmt->fetch_assoc()) {
        $deadline = new DateTime($row['arta_deadline']);
        $today = new DateTime();
        $today->setTime(0,0,0);

        if ($today > $deadline) {
            $arta_stats['overdue']++;
        } else {
            $diff = $today->diff($deadline);
            if ($diff->days <= 2) {
                $arta_stats['at_risk']++;
            } else {
                $arta_stats['on_time']++;
            }
        }
    }
    $active_stmt->close();
}

// Get completed documents
$completed_stmt = $conn->query("
    SELECT v.arta_deadline, final_logs.completion_date
    FROM vouchers v
    JOIN (
        SELECT voucher_code, MAX(created_at) as completion_date
        FROM audit_logs
        WHERE action_taken IN ('Accepted', 'RETURNED', 'DECLINED', 'AUTO-SKIPPED')
        GROUP BY voucher_code
    ) as final_logs ON v.voucher_code = final_logs.voucher_code
    WHERE v.status NOT IN ('Pending Review', 'Processing', 'In Transit') AND v.arta_deadline IS NOT NULL
");
if ($completed_stmt) {
    while ($row = $completed_stmt->fetch_assoc()) {
        $deadline = new DateTime($row['arta_deadline']);
        $completion_date = new DateTime($row['completion_date']);
        if ($completion_date > $deadline) {
            $arta_stats['overdue']++;
        } else {
            $arta_stats['on_time']++;
        }
    }
    $completed_stmt->close();
}

// --- 2. AVERAGE DOCUMENT LIFECYCLE ---
$avg_lifecycle_seconds = 0;
$lifecycle_res = $conn->query("
    SELECT AVG(TIMESTAMPDIFF(SECOND, v.date_submitted, final_logs.completion_date)) as avg_seconds
    FROM vouchers v
    JOIN (
        SELECT voucher_code, MAX(created_at) as completion_date
        FROM audit_logs
        GROUP BY voucher_code
    ) as final_logs ON v.voucher_code = final_logs.voucher_code
    WHERE v.status NOT IN ('Pending Review', 'Processing', 'In Transit')
");
if ($lifecycle_res && $lifecycle_row = $lifecycle_res->fetch_assoc()) {
    $avg_lifecycle_seconds = (int)$lifecycle_row['avg_seconds'];
}
// Format for display
$days = floor($avg_lifecycle_seconds / 86400);
$hours = floor(($avg_lifecycle_seconds % 86400) / 3600);
$minutes = floor(($avg_lifecycle_seconds % 3600) / 60);
$avg_lifecycle_formatted = "{$days}d {$hours}h {$minutes}m";


// --- 3. THROUGHPUT ---
$throughput_period = $_GET['throughput'] ?? '7day';
$interval_sql = 'INTERVAL 7 DAY';
$period_label = 'Last 7 Days';
if ($throughput_period === '30day') {
    $interval_sql = 'INTERVAL 30 DAY';
    $period_label = 'Last 30 Days';
} elseif ($throughput_period === '90day') {
    $interval_sql = 'INTERVAL 90 DAY';
    $period_label = 'Last 90 Days';
}

$throughput = 0;
$throughput_res = $conn->query("
    SELECT COUNT(v.voucher_code) as throughput
    FROM vouchers v
    JOIN (
        SELECT voucher_code, MAX(created_at) as completion_date
        FROM audit_logs
        GROUP BY voucher_code
    ) as final_logs ON v.voucher_code = final_logs.voucher_code
    WHERE v.status NOT IN ('Pending Review', 'Processing', 'In Transit')
    AND final_logs.completion_date >= DATE_SUB(NOW(), $interval_sql)
");
if ($throughput_res && $throughput_row = $throughput_res->fetch_assoc()) {
    $throughput = (int)$throughput_row['throughput'];
}

// --- NEW: LAPSED DOCUMENT COUNT ---
$lapsed_count = 0;
$lapsed_res = $conn->query("SELECT COUNT(*) as count FROM vouchers WHERE status = 'Lapsed'");
if ($lapsed_res && $lapsed_row = $lapsed_res->fetch_assoc()) {
    $lapsed_count = (int)$lapsed_row['count'];
}


// --- 4. AVERAGE "STAY TIME" PER DEPARTMENT ---
$stay_times_per_dept = []; // department => [total_seconds, count]

$audit_logs_res = $conn->query("
    SELECT voucher_code, department, action_taken, created_at
    FROM audit_logs
    ORDER BY voucher_code, created_at ASC
");

if ($audit_logs_res) {
    $voucher_processing_data = []; // voucher_code => department => {time_in, time_out}

    while ($log = $audit_logs_res->fetch_assoc()) {
        $vc = $log['voucher_code'];
        $dept = $log['department'];
        $action = $log['action_taken'];
        $time = new DateTime($log['created_at']);

        if ($action === 'Scan-to-Receive') {
            $voucher_processing_data[$vc][$dept]['time_in'] = $time;
        } elseif (in_array($action, ['Accepted', 'RETURNED', 'DECLINED'])) {
            if (isset($voucher_processing_data[$vc][$dept]['time_in'])) {
                $time_in = $voucher_processing_data[$vc][$dept]['time_in'];
                $time_out = $time;
                $duration = $time_out->getTimestamp() - $time_in->getTimestamp();

                if (!isset($stay_times_per_dept[$dept])) {
                    $stay_times_per_dept[$dept] = ['total_seconds' => 0, 'count' => 0];
                }
                $stay_times_per_dept[$dept]['total_seconds'] += $duration;
                $stay_times_per_dept[$dept]['count']++;

                unset($voucher_processing_data[$vc][$dept]['time_in']);
            }
        }
    }
    $audit_logs_res->close();
}

$avg_stay_times_formatted = [];
$avg_stay_times_seconds = []; // For Chart.js
foreach ($stay_times_per_dept as $dept => $data) {
    if ($data['count'] > 0) {
        $avg_seconds = $data['total_seconds'] / $data['count'];
        $days = floor($avg_seconds / 86400);
        $hours = floor(($avg_seconds % 86400) / 3600);
        $minutes = floor(($avg_seconds % 3600) / 60);
        $avg_stay_times_formatted[$dept] = "{$days}d {$hours}h {$minutes}m";
        $avg_stay_times_seconds[$dept] = round($avg_seconds / 3600, 2); // Store in hours for chart
    } else {
        $avg_stay_times_formatted[$dept] = "N/A";
        $avg_stay_times_seconds[$dept] = 0;
    }
}
arsort($avg_stay_times_seconds); // Sort by average time (longest first)

// --- 5. RETURN/REJECTION RATE PER DEPARTMENT ---
$dept_action_stats = []; // department => {total_processed, total_returned_declined}

$audit_logs_res = $conn->query("
    SELECT department, action_taken
    FROM audit_logs
    WHERE action_taken IN ('Accepted', 'RETURNED', 'DECLINED')
");

if ($audit_logs_res) {
    while ($log = $audit_logs_res->fetch_assoc()) {
        $dept = $log['department'];
        $action = $log['action_taken'];

        if (!isset($dept_action_stats[$dept])) {
            $dept_action_stats[$dept] = ['total_processed' => 0, 'total_returned_declined' => 0];
        }
        $dept_action_stats[$dept]['total_processed']++;
        if (in_array($action, ['RETURNED', 'DECLINED'])) {
            $dept_action_stats[$dept]['total_returned_declined']++;
        }
    }
    $audit_logs_res->close();
}

$return_rejection_rates = [];
foreach ($dept_action_stats as $dept => $data) {
    if ($data['total_processed'] > 0) {
        $return_rejection_rates[$dept] = round(($data['total_returned_declined'] / $data['total_processed']) * 100, 2);
    } else {
        $return_rejection_rates[$dept] = 0;
    }
}
arsort($return_rejection_rates); // Sort by rate (highest first)

// --- HR-SPECIFIC ANALYTICS ---
$hr_avg_stay_times_seconds = [];
$hr_return_reasons_counts = [];

if ($is_hr_head) {
    // HR-Specific: AVERAGE "STAY TIME" PER DEPARTMENT for HR documents
    $hr_stay_times_per_dept = []; // department => [total_seconds, count]

    $hr_audit_logs_res = $conn->query("
        SELECT 
            al.voucher_code, al.department, al.action_taken, al.created_at
        FROM audit_logs al
        JOIN vouchers v ON al.voucher_code = v.voucher_code
        LEFT JOIN document_types dt ON v.doc_type_id = dt.id
        LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
        WHERE COALESCE(dt.category, vt.category) = 'HR'
        ORDER BY al.voucher_code, al.created_at ASC
    ");

    if ($hr_audit_logs_res) {
        $hr_voucher_processing_data = []; // voucher_code => department => {time_in, time_out}

        while ($log = $hr_audit_logs_res->fetch_assoc()) {
            $vc = $log['voucher_code'];
            $dept = $log['department'];
            $action = $log['action_taken'];
            $time = new DateTime($log['created_at']);

            if ($action === 'Scan-to-Receive') {
                $hr_voucher_processing_data[$vc][$dept]['time_in'] = $time;
            } elseif (in_array($action, ['Accepted', 'RETURNED', 'DECLINED'])) {
                if (isset($hr_voucher_processing_data[$vc][$dept]['time_in'])) {
                    $time_in = $hr_voucher_processing_data[$vc][$dept]['time_in'];
                    $time_out = $time;
                    $duration = $time_out->getTimestamp() - $time_in->getTimestamp();

                    if (!isset($hr_stay_times_per_dept[$dept])) {
                        $hr_stay_times_per_dept[$dept] = ['total_seconds' => 0, 'count' => 0];
                    }
                    $hr_stay_times_per_dept[$dept]['total_seconds'] += $duration;
                    $hr_stay_times_per_dept[$dept]['count']++;

                    unset($hr_voucher_processing_data[$vc][$dept]['time_in']);
                }
            }
        }
        $hr_audit_logs_res->close();
    }

    foreach ($hr_stay_times_per_dept as $dept => $data) {
        if ($data['count'] > 0) {
            $avg_seconds = $data['total_seconds'] / $data['count'];
            $hr_avg_stay_times_seconds[$dept] = round($avg_seconds / 3600, 2); // Store in hours for chart
        } else {
            $hr_avg_stay_times_seconds[$dept] = 0;
        }
    }
    arsort($hr_avg_stay_times_seconds); // Sort by average time (longest first)

    // HR-Specific: Granular Return-Reason Analysis for HR documents
    $hr_return_reasons_res = $conn->query("
        SELECT 
            al.remarks
        FROM audit_logs al
        JOIN vouchers v ON al.voucher_code = v.voucher_code
        LEFT JOIN document_types dt ON v.doc_type_id = dt.id
        LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
        WHERE al.action_taken = 'RETURNED' AND COALESCE(dt.category, vt.category) = 'HR' AND al.remarks LIKE '%--- MISSING/INCOMPLETE REQUIREMENTS ---%'
    ");

    if ($hr_return_reasons_res) {
        while ($row = $hr_return_reasons_res->fetch_assoc()) {
            $remarks = $row['remarks'];
            if (preg_match('/--- MISSING\/INCOMPLETE REQUIREMENTS ---\s*(.*?)(---|$)/s', $remarks, $matches)) {
                $missing_block = trim($matches[1]);
                $lines = preg_split('/\r\n|\r|\n/', $missing_block);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, '- ') === 0) {
                        $reason = trim(substr($line, 2));
                        if (!empty($reason)) {
                            $hr_return_reasons_counts[$reason] = ($hr_return_reasons_counts[$reason] ?? 0) + 1;
                        }
                    }
                }
            }
        }
        $hr_return_reasons_res->close();
    }
    arsort($hr_return_reasons_counts); // Sort by count (highest first)
}

// --- HR-Specific: Live Document Performance Monitoring ---
if ($is_hr_head) {
    $live_documents = [];
    $live_docs_res = $conn->query("
        SELECT
            v.voucher_code,
            v.document_title,
            v.current_stage_index,
            v.custom_workflow,
            v.arta_deadline,
            last_log.last_action_time
        FROM vouchers v
        JOIN (
            SELECT voucher_code, MAX(created_at) as last_action_time
            FROM audit_logs
            GROUP BY voucher_code
        ) as last_log ON v.voucher_code = last_log.voucher_code
        WHERE v.status IN ('Pending Review', 'Processing', 'In Transit')
        ORDER BY last_log.last_action_time ASC -- Show oldest (most stuck) first
    ");

    if ($live_docs_res) {
        // Get default workflow as a fallback
        $default_workflow_res = $conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1 ORDER BY name ASC");
        $default_workflow = [];
        while ($row = $default_workflow_res->fetch_assoc()) {
            $default_workflow[] = $row['name'];
        }

        while ($doc = $live_docs_res->fetch_assoc()) {
            // Determine current department
            $workflow = json_decode($doc['custom_workflow'], true);
            if (empty($workflow)) { $workflow = $default_workflow; }
            $current_dept = $workflow[$doc['current_stage_index'] - 1] ?? 'Unknown Stage';

            // Calculate time in queue
            $time_in_queue = (new DateTime())->getTimestamp() - (new DateTime($doc['last_action_time']))->getTimestamp();
            $days = floor($time_in_queue / 86400);
            $hours = floor(($time_in_queue % 86400) / 3600);
            $doc['time_in_queue_formatted'] = "{$days}d {$hours}h";
            $doc['current_department'] = $current_dept;
            $live_documents[] = $doc;
        }
        $live_docs_res->close();
    }
}





// --- 6. MOST COMMON WORKFLOW PATH ---
$top_workflows = [];
$workflow_res = $conn->query("
    SELECT 
        COALESCE(
            NULLIF(v.custom_workflow, '[]'), 
            dt.default_workflow, 
            vt.default_workflow
        ) as effective_workflow,
        COUNT(*) as workflow_count
    FROM vouchers v
    LEFT JOIN document_types dt ON v.doc_type_id = dt.id
    LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
    WHERE 
        COALESCE(
            NULLIF(v.custom_workflow, '[]'), 
            dt.default_workflow, 
            vt.default_workflow
        ) IS NOT NULL
    GROUP BY effective_workflow
    ORDER BY workflow_count DESC
    LIMIT 5
");

if ($workflow_res) {
    while ($row = $workflow_res->fetch_assoc()) {
        $workflow_arr = json_decode($row['effective_workflow'], true);
        if (is_array($workflow_arr) && !empty($workflow_arr)) {
            $top_workflows[] = [
                'path' => implode(' &rarr; ', $workflow_arr), 
                'count' => $row['workflow_count']
            ];
        }
    }
    $workflow_res->close();
}

// --- 7. MOST ACTIVE REQUESTORS ---
$top_requestors = [];
$requestors_res = $conn->query("
    SELECT u.full_name, COUNT(v.voucher_code) as submission_count
    FROM vouchers v
    JOIN users u ON v.requestor_id = u.user_id
    GROUP BY v.requestor_id
    ORDER BY submission_count DESC
    LIMIT 5
");
if ($requestors_res) {
    while ($row = $requestors_res->fetch_assoc()) {
        $top_requestors[] = $row;
    }
    $requestors_res->close();
}

// --- 8. DOCUMENT TYPES WITH HIGHEST RETURN/REJECTION ---
$top_returned_doctypes = [];
$returned_res = $conn->query("
    SELECT 
        dt.name as doc_type_name, 
        COUNT(al.log_id) as return_rejection_count
    FROM audit_logs al
    JOIN vouchers v ON al.voucher_code = v.voucher_code
    JOIN document_types dt ON v.doc_type_id = dt.id
    WHERE al.action_taken IN ('RETURNED', 'DECLINED')
    GROUP BY v.doc_type_id
    ORDER BY return_rejection_count DESC
    LIMIT 5
");
if ($returned_res) {
    while ($row = $returned_res->fetch_assoc()) {
        $top_returned_doctypes[] = $row;
    }
    $returned_res->close();
}

// --- 9. LIVE DOCUMENT STATUS OVERVIEW ---
$live_status_stats = [
    'Pending Review' => 0,
    'Processing' => 0,
    'In Transit' => 0
];
$live_status_res = $conn->query("
    SELECT status, COUNT(*) as status_count
    FROM vouchers
    WHERE status IN ('Pending Review', 'Processing', 'In Transit')
    GROUP BY status
");
if ($live_status_res) {
    while ($row = $live_status_res->fetch_assoc()) {
        // Ensure the key exists before assigning
        if (array_key_exists($row['status'], $live_status_stats)) {
            $live_status_stats[$row['status']] = $row['status_count'];
        }
    }
    $live_status_res->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Analytics - NAAP</title>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo filemtime('sidebar.css'); ?>">
    <link rel="stylesheet" href="analytics.css?v=<?php echo @filemtime('analytics.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Styles for the new Live Performance Monitoring table */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: white;
        }
        .table-responsive table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .table-responsive th, .table-responsive td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .table-responsive th { background-color: #f8fafc; font-weight: 600; color: #64748b; font-size: 0.8rem; text-transform: uppercase; }
        .table-responsive td a { color: var(--naap-navy, #1E3A8A); font-weight: bold; text-decoration: none; }
        .table-responsive td a:hover { text-decoration: underline; }
        .deadline-flag {
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
        }
        .status-at-risk .deadline-flag { color: #92400e; background-color: #fef3c7; }
        .status-overdue .deadline-flag { color: #991b1b; background-color: #fee2e2; }
        .status-overdue {
            background-color: #fff1f2 !important; /* Use important to override other styles if needed */
        }
        .status-at-risk {
            background-color: #fffbeb !important;
        }
        tr.status-overdue:hover, tr.status-at-risk:hover {
            background-color: #feecdc !important; /* A slightly different hover for highlighted rows */
        }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="container">
        <div class="page-header">
            <h1>System Analytics</h1>
            <p>High-level overview of system performance and compliance.</p>
        </div>

        <div class="stats-grid">
            <!-- ARTA Compliance Rate Card -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>ARTA Compliance Rate</h3>
                </div>
                <div class="chart-container">
                    <canvas id="artaChart"></canvas>
                </div>
            </div>

            <!-- Average Lifecycle Card -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Average Document Lifecycle</h3>
                </div>
                <div class="stat-value"><?php echo $avg_lifecycle_formatted; ?></div>
                <p class="stat-label">From submission to final status.</p>
            </div>

            <!-- Throughput Card -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Throughput</h3>
                    <form method="GET" id="throughputForm">
                        <select name="throughput" onchange="document.getElementById('throughputForm').submit()">
                            <option value="7day" <?php if($throughput_period === '7day') echo 'selected'; ?>>Last 7 Days</option>
                            <option value="30day" <?php if($throughput_period === '30day') echo 'selected'; ?>>Last 30 Days</option>
                            <option value="90day" <?php if($throughput_period === '90day') echo 'selected'; ?>>Last 90 Days</option>
                        </select>
                    </form>
                </div>
                <div class="stat-value"><?php echo $throughput; ?></div>
                <p class="stat-label">Documents completed in <?php echo $period_label; ?>.</p>
            </div>

            <!-- Lapsed Documents Card -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Lapsed by ARTA</h3>
                </div>
                <div class="stat-value" style="color: #9333ea;"><?php echo $lapsed_count; ?></div>
                <p class="stat-label">Documents that exceeded their deadline.</p>
            </div>

        </div>

        <div class="page-header" style="margin-top: 60px;">
            <h1>Departmental & Workflow Bottleneck Analysis</h1>
            <p>Insights into departmental performance and common routing paths.</p>
        </div>

        <div class="stats-grid">
            <!-- Average Stay Time per Department Card -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Average Stay Time per Department</h3>
                </div>
                <div class="chart-container">
                    <canvas id="avgStayTimeChart"></canvas>
                </div>
            </div>

            <!-- Return/Rejection Rate per Department Card -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Return/Rejection Rate per Department</h3>
                </div>
                <div class="chart-container">
                    <canvas id="returnRejectionChart"></canvas>
                </div>
            </div>

            <!-- Most Common Workflow Path Card -->
            <div class="stat-card list-card">
                <div class="stat-card-header">
                    <h3>Most Common Workflow Paths</h3>
                </div>
                <?php if (!empty($top_workflows)): ?>
                    <ul>
                        <?php foreach ($top_workflows as $workflow): ?>
                            <li>
                                <span><?php echo $workflow['path']; ?></span>
                                <span class="count"><?php echo $workflow['count']; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-muted); font-style: italic;">No workflow data available.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="page-header" style="margin-top: 60px;">
            <h1>User & Document Type Analysis</h1>
            <p>Insights into user behavior and document-specific performance.</p>
        </div>

        <div class="stats-grid">
            <!-- Most Active Requestors Card -->
            <div class="stat-card list-card">
                <div class="stat-card-header">
                    <h3>Most Active Requestors</h3>
                </div>
                <?php if (!empty($top_requestors)): ?>
                    <ul>
                        <?php foreach ($top_requestors as $requestor): ?>
                            <li>
                                <span><?php echo htmlspecialchars($requestor['full_name']); ?></span>
                                <span class="count"><?php echo $requestor['submission_count']; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-muted); font-style: italic;">No submission data available.</p>
                <?php endif; ?>
            </div>

            <!-- Document Types with Highest Returns Card -->
            <div class="stat-card list-card">
                <div class="stat-card-header">
                    <h3>Doc Types with Most Returns/Rejections</h3>
                </div>
                <?php if (!empty($top_returned_doctypes)): ?>
                    <ul>
                        <?php foreach ($top_returned_doctypes as $doctype): ?>
                            <li>
                                <span><?php echo htmlspecialchars($doctype['doc_type_name']); ?></span>
                                <span class="count"><?php echo $doctype['return_rejection_count']; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-muted); font-style: italic;">No return/rejection data available.</p>
                <?php endif; ?>
            </div>

            <!-- Live Document Status Overview Card -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Live Document Status</h3>
                </div>
                <div class="chart-container">
                    <canvas id="liveStatusChart"></canvas>
                </div>
            </div>
        </div>

        <?php if ($is_hr_head): ?>
        <div class="page-header" style="margin-top: 60px;">
            <h1>Human Resources Process Analytics</h1>
            <p>Targeted insights for HR document processing across departments.</p>
        </div>

        <div class="stats-grid">
            <!-- HR-Specific Average Stay Time per Department Card -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Avg. Stay Time for HR Docs (Hours)</h3>
                </div>
                <div class="chart-container">
                    <canvas id="hrAvgStayTimeChart"></canvas>
                </div>
            </div>

            <!-- HR-Specific Granular Return Reasons Card -->
            <div class="stat-card list-card">
                <div class="stat-card-header">
                    <h3>Top HR Document Return Reasons</h3>
                </div>
                <?php if (!empty($hr_return_reasons_counts)): ?>
                    <ul>
                        <?php foreach ($hr_return_reasons_counts as $reason => $count): ?>
                            <li>
                                <span><?php echo htmlspecialchars($reason); ?></span>
                                <span class="count"><?php echo $count; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-muted); font-style: italic;">No HR documents returned with specific reasons.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- NEW LIVE MONITORING TABLE -->
        <div class="stats-grid" style="grid-template-columns: 1fr; margin-top: 30px;">
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Live Document Performance Monitoring</h3>
                </div>
                <div class="table-responsive" style="margin-top: 20px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Voucher ID</th>
                                <th>Document Title</th>
                                <th>Current Department</th>
                                <th>Time in Queue</th>
                                <th>ARTA Deadline</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($live_documents)): ?>
                                <tr><td colspan="6" style="text-align: center; padding: 20px; color: var(--text-muted);">No active documents are currently in processing queues.</td></tr>
                            <?php else: ?>
                                <?php foreach ($live_documents as $doc): ?>
                                    <?php
                                        $status_class = '';
                                        $status_text = 'On-Time';
                                        if (!empty($doc['arta_deadline'])) {
                                            $deadline = new DateTime($doc['arta_deadline']);
                                            $today = new DateTime();
                                            $today->setTime(0,0,0);
                                            if ($today > $deadline) {
                                                $status_class = 'status-overdue';
                                                $status_text = 'Overdue';
                                            } elseif ($today->diff($deadline)->days <= 2) {
                                                $status_class = 'status-at-risk';
                                                $status_text = 'At-Risk';
                                            }
                                        }
                                    ?>
                                    <tr class="<?php echo $status_class; ?>">
                                        <td><a href="track.php?track_id=<?php echo urlencode($doc['voucher_code']); ?>" target="_blank"><?php echo htmlspecialchars($doc['voucher_code']); ?></a></td>
                                        <td><?php echo htmlspecialchars($doc['document_title']); ?></td>
                                        <td style="font-weight: 600;"><?php echo htmlspecialchars($doc['current_department']); ?></td>
                                        <td><?php echo $doc['time_in_queue_formatted']; ?></td>
                                        <td><?php echo !empty($doc['arta_deadline']) ? date('M d, Y', strtotime($doc['arta_deadline'])) : 'N/A'; ?></td>
                                        <td><span class="deadline-flag"><?php echo $status_text; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- General System Analytics Charts ---
    const ctx = document.getElementById('artaChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['On-Time', 'At-Risk', 'Overdue'],
                datasets: [{
                    label: 'Document Status',
                    data: [
                        <?php echo $arta_stats['on_time']; ?>,
                        <?php echo $arta_stats['at_risk']; ?>,
                        <?php echo $arta_stats['overdue']; ?>
                    ],
                    backgroundColor: [
                        '#10b981', // On-Time (Green)
                        '#f59e0b', // At-Risk (Amber)
                        '#ef4444'  // Overdue (Red)
                    ],
                    borderColor: '#ffffff',
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed !== null) {
                                    label += context.parsed;
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }
});

    // General: Average Stay Time per Department Chart
    const avgStayTimeCtx = document.getElementById('avgStayTimeChart');
    if (avgStayTimeCtx) {
        new Chart(avgStayTimeCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($avg_stay_times_seconds)); ?>,
                datasets: [{
                    label: 'Average Stay Time (Hours)',
                    data: <?php echo json_encode(array_values($avg_stay_times_seconds)); ?>,
                    backgroundColor: 'rgba(30, 58, 138, 0.7)', // naap-navy
                    borderColor: 'rgba(30, 58, 138, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: 'Average Time Documents Spend in Department (Hours)'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Hours'
                        }
                    }
                }
            }
        });
    }

    // General: Return/Rejection Rate per Department Chart
    const returnRejectionCtx = document.getElementById('returnRejectionChart');
    if (returnRejectionCtx) {
        new Chart(returnRejectionCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($return_rejection_rates)); ?>,
                datasets: [{
                    label: 'Return/Rejection Rate (%)',
                    data: <?php echo json_encode(array_values($return_rejection_rates)); ?>,
                    backgroundColor: 'rgba(245, 158, 11, 0.7)', // naap-gold
                    borderColor: 'rgba(245, 158, 11, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: 'Return/Rejection Rate per Department (%)'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Percentage (%)'
                        }
                    }
                }
            }
        });
    }

    // General: Live Document Status Chart
    const liveStatusCtx = document.getElementById('liveStatusChart');
    if (liveStatusCtx) {
        new Chart(liveStatusCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_keys($live_status_stats)); ?>,
                datasets: [{
                    label: 'Live Documents',
                    data: <?php echo json_encode(array_values($live_status_stats)); ?>,
                    backgroundColor: [
                        '#f59e0b', // Pending Review (Amber)
                        '#3b82f6', // Processing (Blue)
                        '#6366f1', // In Transit (Indigo)
                    ],
                    borderColor: '#ffffff',
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });
    }

    // --- HR-Specific Analytics Charts ---
    <?php if ($is_hr_head): ?>
    // HR: Average Stay Time per Department Chart for HR Documents
    const hrAvgStayTimeCtx = document.getElementById('hrAvgStayTimeChart');
    if (hrAvgStayTimeCtx) {
        new Chart(hrAvgStayTimeCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($hr_avg_stay_times_seconds)); ?>,
                datasets: [{
                    label: 'Average Stay Time (Hours)',
                    data: <?php echo json_encode(array_values($hr_avg_stay_times_seconds)); ?>,
                    backgroundColor: 'rgba(245, 158, 11, 0.7)', // naap-gold
                    borderColor: 'rgba(245, 158, 11, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y', // Horizontal bar chart
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: 'Average Hours' } },
                    y: { title: { display: true, text: 'Department' } }
                }
            }
        });
    }
    <?php endif; ?>

</script>

<?php
$conn->close();
?>
</body>
</html>