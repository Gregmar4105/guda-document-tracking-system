<?php
session_start();
if (!isset($_SESSION['logged_in'])) { header("Location: login.php"); exit(); }

require_once 'db_connect.php';

// 2. GET CURRENT USER'S ID
$current_username = $_SESSION['username'];
$id_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
$id_stmt->bind_param("s", $current_username);
$id_stmt->execute();
$id_result = $id_stmt->get_result();
$user_id = ($id_result->num_rows > 0) ? $id_result->fetch_assoc()['user_id'] : 0;
$id_stmt->close();

// 3. GET FILTER PARAMETERS
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'monthly';
$include_archived = isset($_GET['include_archived']) ? 1 : 0;

// 4. FETCH FILTERED DOCUMENTS
$my_documents = [];
$status_count = [
    'Approved' => 0, 'Paid' => 0, 'Pending' => 0, 'Pending Review' => 0,
    'Processing' => 0, 'Rejected' => 0, 'Returned' => 0, 'Ready for Release' => 0,
    'Received' => 0, 'Lapsed' => 0
];

$base_select = "SELECT requestor_id, voucher_code, document_title, status, date_submitted, current_stage_index FROM %s";
$live_table = "vouchers";
$archive_table = "vouchers_archive";

$sql = sprintf($base_select, $live_table);
if ($include_archived) {
    $sql .= " UNION ALL " . sprintf($base_select, $archive_table);
}

$sql = "SELECT * FROM ($sql) as combined_vouchers";

$where_clauses = [];
$params = [];
$types = "";

$where_clauses[] = "requestor_id = ?";
$params[] = $user_id;
$types .= "i";

if ($filter_type === 'monthly') {
    $start_date = "$selected_year-" . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . "-01";
    $end_date = date('Y-m-t', strtotime($start_date));
    $where_clauses[] = "DATE(date_submitted) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
} else { // yearly
    $where_clauses[] = "YEAR(date_submitted) = ?";
    $params[] = $selected_year;
    $types .= "i";
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY date_submitted DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $documents_res = $stmt->get_result();
} else {
    die("Error preparing statement: " . $conn->error);
}

while($doc_row = $documents_res->fetch_assoc()) {
    $my_documents[] = $doc_row;
    
    $status = $doc_row['status'];
    if (isset($status_count[$status])) {
        $status_count[$status]++;
    }
}
$stmt->close();

// AGGREGATE COUNTS FOR DISPLAY
$in_progress_count = ($status_count['Pending'] ?? 0) + ($status_count['Pending Review'] ?? 0) + ($status_count['Processing'] ?? 0);
$approved_vouchers_count = ($status_count['Approved'] ?? 0) + ($status_count['Ready for Release'] ?? 0) + ($status_count['Received'] ?? 0);
$paid_vouchers_count = ($status_count['Paid'] ?? 0) + ($status_count['Ready for Release'] ?? 0);

// 5. WORKFLOW STAGES DYNAMICALLY
$workflow_sequence = [];
$seq_res = $conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1 ORDER BY name ASC");
while ($row = $seq_res->fetch_assoc()) {
    $workflow_sequence[] = $row['name'];
}

$stages = [["name" => "Requestor"]]; // Always begins with Requestor
foreach ($workflow_sequence as $role) {
    $stages[] = ["name" => $role];
}

// 6. STATUS BADGE COLORS
$status_colors = [
    'Approved' => '#10b981',
    'Ready for Release' => '#10b981',
    'Rejected' => '#ef4444',
    'Paid' => '#06b6d4',
    'Pending' => '#f59e0b', // Legacy
    'Pending Review' => '#f59e0b',
    'Returned' => '#8b5cf6',
    'Processing' => '#3b82f6',
    'Received' => '#3b82f6', // Same as processing
    'Lapsed' => '#581c87'
];

// 7. GENERATE REPORT TITLE AND DATE RANGE
if ($filter_type === 'monthly') {
    $month_name = date('F', mktime(0, 0, 0, $selected_month, 1));
    $report_title = "$month_name $selected_year Monthly Document Records";
    $start_date = "$selected_year-" . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . "-01"; // Re-define for report period
    $end_date = date('Y-m-t', strtotime($start_date)); // Re-define for report period
    $report_period = "From " . date('F 1, Y', strtotime($start_date)) . " to " . date('F d, Y', strtotime($end_date));
} else {
    $report_title = "$selected_year Annual Document Records";
    $report_period = "January 1 - December 31, $selected_year"; // This is fine for yearly
}

// 8. GET AVAILABLE YEARS FOR DROPDOWN
$available_years = [];
$year_sql = "
    SELECT DISTINCT year FROM (
        SELECT YEAR(date_submitted) as year FROM vouchers WHERE requestor_id = ?
        UNION
        SELECT YEAR(date_submitted) as year FROM vouchers_archive WHERE requestor_id = ?
    ) as all_years ORDER BY year DESC
";
$year_stmt = $conn->prepare($year_sql);
$year_stmt->bind_param("ii", $user_id, $user_id);
$year_stmt->execute();
$year_res = $year_stmt->get_result();
while ($year_row = $year_res->fetch_assoc()) {
    $available_years[] = (int)$year_row['year'];
}
$year_stmt->close();

if (empty($available_years)) {
    $available_years = [date('Y')];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Records - NAAP Document System</title>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo filemtime('sidebar.css'); ?>">
    <link rel="stylesheet" href="records.css">
    <style>
        /* Renaming voucher-specific classes to be generic */
        .document-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .document-card {
            background: #f8fafc;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            padding: 20px;
            transition: box-shadow 0.2s;
        }
        .document-card:hover { box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .document-id { font-family: 'Courier New', Courier, monospace; font-weight: bold; color: var(--naap-navy); font-size: 1.1em; margin-bottom: 5px; }
        .document-title { color: var(--text-dark); font-size: 0.95em; }
        /* Fallback for records.css if it uses old names */
        .voucher-list { display: none; }

        .print-only {
            display: none;
        }

        @media print {
            body > *:not(.print-only) {
                display: none !important;
            }
            .print-only {
                display: block;
                padding: 20px;
            }
            .print-only .report-header {
                text-align: center;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 2px solid #333;
            }
            .print-only .report-header h2 { font-size: 16pt; margin: 0; }
            .print-only .report-header p { font-size: 10pt; margin: 5px 0 0 0; }
            .print-only table {
                width: 100%;
                border-collapse: collapse;
                font-size: 9pt;
            }
            .print-only th, .print-only td {
                border: 1px solid #ccc;
                padding: 6px;
                text-align: left;
            }
            .print-only th {
                background-color: #f2f2f2;
                font-weight: bold;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <div class="header">
                <h1>My Document Records</h1>
                <p>View and print your monthly and yearly document submission records</p>
            </div>

            <div class="filter-section">
                <form method="GET" class="filter-controls">
                    <div class="filter-group">
                        <label>Report Type</label>
                        <select name="filter" onchange="document.querySelector('form').submit()">
                            <option value="monthly" <?php echo $filter_type === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                            <option value="yearly" <?php echo $filter_type === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                        </select>
                    </div>

                    <?php if ($filter_type === 'monthly'): ?>
                    <div class="filter-group">
                        <label>Month</label>
                        <select name="month" onchange="document.querySelector('form').submit()">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m === $selected_month ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="filter-group">
                        <label>Year</label>
                        <select name="year" onchange="document.querySelector('form').submit()">
                            <?php foreach ($available_years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo $year === $selected_year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <div style="display: flex; align-items: center; gap: 10px; height: 100%;">
                            <input type="checkbox" name="include_archived" id="include_archived" value="1" <?php if ($include_archived) echo 'checked'; ?> onchange="document.querySelector('form').submit()">
                            <label for="include_archived" style="margin-bottom: 0; cursor: pointer;">Include Archived</label>
                        </div>
                    </div>

                    <div class="button-group" style="margin-left: auto;">
                        <button type="button" class="btn btn-print" onclick="window.print()">
                            Print Report
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="report-header">
                    <h2><?php echo htmlspecialchars($report_title); ?></h2>
                    <p><?php echo htmlspecialchars($report_period); ?></p>
                    <p style="font-size: 0.9em; color: #94a3b8; margin-top: 10px;">Generated on <?php echo date('F d, Y \a\t g:i A'); ?></p>
                </div>

                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-label">Total Documents</div>
                        <div class="stat-number"><?php echo count($my_documents); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">In Progress</div>
                        <div class="stat-number"><?php echo $in_progress_count; ?></div>
                        <div class="stat-data">Pending Approvals</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Action Required</div>
                        <div class="stat-number"><?php echo $status_count['Rejected'] + $status_count['Returned']; ?></div>
                        <div class="stat-data">(<?php echo $status_count['Rejected'] ?? 0; ?> rejected, <?php echo $status_count['Returned'] ?? 0; ?> returned)</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>Detailed Document Records</h2>
                <?php if (count($my_documents) > 0): ?>
                    <div class="document-list">
                        <?php foreach ($my_documents as $index => $document): ?>
                            <div class="document-card">
                                <div style="display: flex; justify-content: space-between; align-items: start; gap: 20px;">
                                    <div style="flex: 1;">
                                        <div class="document-id"><?php echo htmlspecialchars($document['voucher_code']); ?></div>
                                        <div class="document-title">Title: <strong><?php echo htmlspecialchars($document['document_title']); ?></strong></div>
                                    </div>
                                    <span class="status-badge" style="background-color: <?php echo $status_colors[$document['status']] ?? '#64748b'; ?>"><?php echo htmlspecialchars($document['status']); ?></span>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-light); font-size: 0.9em;">
                                    <div>
                                        <span style="color: #64748b; font-weight: 600;">Submitted:</span> <?php echo format_db_timestamp($document['date_submitted'], 'M d, Y'); ?>
                                    </div>
                                    <div>
                                        <span style="color: #64748b; font-weight: 600;">Processing Stage:</span> 
                                        <?php 
                                            // Handle cases where the index might be out of bounds if settings changed mid-flight
                                            $stage_idx = (int)$document['current_stage_index'];
                                            echo isset($stages[$stage_idx]) ? $stages[$stage_idx]['name'] : 'Completed'; 
                                        ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <p style="font-size: 1.1em; margin: 0 0 10px 0;">No Records Found</p>
                        <p style="margin: 0;">No documents found for the selected period. Try selecting a different month or year.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Hidden section for printing -->
    <div class="print-only">
        <div class="report-header">
            <h2><?php echo htmlspecialchars($report_title); ?></h2>
            <p><?php echo htmlspecialchars($report_period); ?></p>
            <p style="font-size: 0.9em; color: #555; margin-top: 10px;">Generated on <?php echo date('F d, Y \a\t g:i A'); ?> by <?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Document Title</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Current Stage</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($my_documents) > 0): ?>
                    <?php foreach ($my_documents as $document): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($document['voucher_code']); ?></td>
                        <td><?php echo htmlspecialchars($document['document_title']); ?></td>
                        <td><?php echo format_db_timestamp($document['date_submitted'], 'M d, Y'); ?></td>
                        <td><?php echo htmlspecialchars($document['status']); ?></td>
                        <td>
                            <?php 
                                $stage_idx = (int)$document['current_stage_index'];
                                echo isset($stages[$stage_idx]) ? htmlspecialchars($stages[$stage_idx]['name']) : 'Completed'; 
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php
$conn->close();
?>
</body>
</html>