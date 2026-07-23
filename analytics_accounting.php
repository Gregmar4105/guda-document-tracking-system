<?php
session_start();

// ACCESS CONTROL: ONLY Accounting Head CAN ACCESS
$is_acct_head = (($_SESSION['role'] ?? '') === 'Accounting Office' && ($_SESSION['is_head'] ?? 0) == 1);
if (!isset($_SESSION['logged_in']) || !$is_acct_head) {
    header("Location: home.php");
    exit();
}

require_once 'db_connect.php';

// --- Year Filter for Monthly Data ---
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Get available years for the filter dropdown
$available_years = [];
$year_res = $conn->query("SELECT DISTINCT YEAR(date_submitted) as year FROM vouchers ORDER BY year DESC");
while ($year_row = $year_res->fetch_assoc()) {
    $available_years[] = (int)$year_row['year'];
}
if (empty($available_years)) { $available_years = [date('Y')]; } // Ensure current year is always an option

// --- 1. Initialize Stats Array ---
$stats_by_office = [];

// Get all departments to ensure every office is listed, even with zero activity
$depts_res = $conn->query("SELECT name FROM departments ORDER BY name ASC");
while ($dept_row = $depts_res->fetch_assoc()) {
    $stats_by_office[$dept_row['name']] = [
        'requested' => 0,
        'approved' => 0,
        'declined' => 0,
        'returned' => 0
    ];
}
// Also add 'Requestor' as a potential origin office
if (!isset($stats_by_office['Requestor'])) {
    $stats_by_office['Requestor'] = ['requested' => 0, 'approved' => 0, 'declined' => 0, 'returned' => 0];
}


// --- 2. Get Total Vouchers Requested per Office ---
$requested_res = $conn->query("
    SELECT u.role as office, COUNT(v.voucher_code) as total_requested
    FROM vouchers v
    JOIN users u ON v.requestor_id = u.user_id
    WHERE v.voucher_type_id IS NOT NULL
    GROUP BY u.role
");
if ($requested_res) {
    while ($row = $requested_res->fetch_assoc()) {
        if (isset($stats_by_office[$row['office']])) {
            $stats_by_office[$row['office']]['requested'] = (int)$row['total_requested'];
        }
    }
    $requested_res->close();
}

// --- 3. Get Total Actions (Approved, Declined, Returned) per Office ---
$actions_res = $conn->query("
    SELECT
        al.department as office,
        SUM(CASE WHEN al.action_taken = 'Accepted' THEN 1 ELSE 0 END) as total_approved,
        SUM(CASE WHEN al.action_taken = 'DECLINED' THEN 1 ELSE 0 END) as total_declined,
        SUM(CASE WHEN al.action_taken = 'RETURNED' THEN 1 ELSE 0 END) as total_returned
    FROM audit_logs al
    JOIN vouchers v ON al.voucher_code = v.voucher_code
    WHERE v.voucher_type_id IS NOT NULL
    AND al.department IS NOT NULL AND al.department != ''
    GROUP BY al.department
");
if ($actions_res) {
    while ($row = $actions_res->fetch_assoc()) {
        if (isset($stats_by_office[$row['office']])) {
            $stats_by_office[$row['office']]['approved'] = (int)$row['total_approved'];
            $stats_by_office[$row['office']]['declined'] = (int)$row['total_declined'];
            $stats_by_office[$row['office']]['returned'] = (int)$row['total_returned'];
        }
    }
    $actions_res->close();
}

// --- 4. Calculate Grand Totals ---
$total_approval_actions = array_sum(array_column($stats_by_office, 'approved'));
$grand_totals = [
    'requested' => array_sum(array_column($stats_by_office, 'requested')),
    'approved' => 0, // This will be recalculated to count documents, not actions.
    'declined' => array_sum(array_column($stats_by_office, 'declined')),
    'returned' => array_sum(array_column($stats_by_office, 'returned')),
];

// NEW: Correctly calculate the number of fully approved financial documents
$total_approved_res = $conn->query("
    SELECT COUNT(DISTINCT voucher_code) as total_approved
    FROM vouchers
    WHERE voucher_type_id IS NOT NULL
    AND status IN ('Approved', 'Paid', 'Ready for Release')
");
if ($total_approved_res && $row = $total_approved_res->fetch_assoc()) {
    $grand_totals['approved'] = (int)$row['total_approved'];
}

// --- NEW: Average Processing Time for Financial Vouchers ---
$avg_processing_time_seconds = 0;
$avg_time_res = $conn->query("
    SELECT AVG(TIMESTAMPDIFF(SECOND, v.date_submitted, final_logs.completion_date)) as avg_seconds
    FROM vouchers v
    JOIN (
        SELECT voucher_code, MAX(created_at) as completion_date
        FROM audit_logs
        GROUP BY voucher_code
    ) as final_logs ON v.voucher_code = final_logs.voucher_code
    WHERE v.status IN ('Approved', 'Paid', 'Ready for Release') AND v.voucher_type_id IS NOT NULL
");
if ($avg_time_res && $avg_time_row = $avg_time_res->fetch_assoc()) {
    $avg_processing_time_seconds = (int)$avg_time_row['avg_seconds'];
}
$days = floor($avg_processing_time_seconds / 86400);
$hours = floor(($avg_processing_time_seconds % 86400) / 3600);
$avg_processing_time_formatted = "{$days}d {$hours}h";

// --- NEW: Top Departments by Spending (Value) ---
$spending_by_dept = [];
$spending_res = $conn->query("
    SELECT u.role as office, SUM(v.amount) as total_amount
    FROM vouchers v
    JOIN users u ON v.requestor_id = u.user_id
    WHERE v.voucher_type_id IS NOT NULL AND v.amount > 0
    GROUP BY u.role
    ORDER BY total_amount DESC
    LIMIT 10
");
if ($spending_res) { $spending_by_dept = $spending_res->fetch_all(MYSQLI_ASSOC); }

// --- 5. Get Total Approved Amount Monthly ---
$monthly_approved_amounts = [];
$monthly_approved_res = $conn->prepare("
    SELECT
        YEAR(al.created_at) as year,
        MONTH(al.created_at) as month,
        SUM(v.amount) as total_approved_amount,
        COUNT(DISTINCT v.voucher_code) as total_approved_count
    FROM vouchers v
    JOIN audit_logs al ON v.voucher_code = al.voucher_code
    WHERE
        al.action_taken = 'Accepted'
        AND v.status IN ('Approved', 'Paid', 'Ready for Release')
        AND v.voucher_type_id IS NOT NULL
        AND v.amount IS NOT NULL AND v.amount > 0
        AND YEAR(al.created_at) = ?
    GROUP BY year, month
    ORDER BY year DESC, month DESC
");
$monthly_approved_res->bind_param("i", $selected_year);
$monthly_approved_res->execute();
$monthly_approved_amounts = $monthly_approved_res->get_result()->fetch_all(MYSQLI_ASSOC);
$monthly_approved_res->close();

// --- 6. Prepare Data for Charts ---
// Monthly Approved Amounts Chart
$monthly_chart_labels = [];
$monthly_chart_data_amount = [];
$monthly_chart_data_count = [];
// The data is fetched ordered by month DESC. Reverse it for a chronological chart.
$monthly_approved_amounts_for_chart = array_reverse($monthly_approved_amounts);
foreach ($monthly_approved_amounts_for_chart as $monthly_data) {
    $monthly_chart_labels[] = date('F', mktime(0, 0, 0, $monthly_data['month'], 1));
    $monthly_chart_data_amount[] = $monthly_data['total_approved_amount'];
    $monthly_chart_data_count[] = $monthly_data['total_approved_count'];
}

// Departmental Activity Chart
$dept_chart_labels = [];
$dept_chart_data_requested = [];
$dept_chart_data_approved = [];
$dept_chart_data_returned = [];
$dept_chart_data_declined = [];

// Filter out departments with zero activity to keep the chart clean
$active_departments = array_filter($stats_by_office, function($data) {
    return $data['requested'] > 0 || $data['approved'] > 0 || $data['returned'] > 0 || $data['declined'] > 0;
});

foreach ($active_departments as $office => $data) {
    $dept_chart_labels[] = $office;
    $dept_chart_data_requested[] = $data['requested'];
    $dept_chart_data_approved[] = $data['approved'];
    $dept_chart_data_returned[] = $data['returned'];
    $dept_chart_data_declined[] = $data['declined'];
}

// Top Spending Departments Chart
$spending_chart_labels = [];
$spending_chart_data = [];
foreach ($spending_by_dept as $data) {
    $spending_chart_labels[] = $data['office'];
    $spending_chart_data[] = $data['total_amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Analytics - NAAP</title>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo @filemtime('sidebar.css'); ?>">
    <link rel="stylesheet" href="analytics.css?v=<?php echo @filemtime('analytics.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="print-only-summary">
        <h3>Report Summary</h3>
        <table>
            <thead>
                <tr>
                    <th>Total Requested</th>
                    <th>Total Approved</th>
                    <th>Total Returned</th>
                    <th>Total Declined</th>
                    <th>Avg. Processing Time</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo number_format($grand_totals['requested']); ?></td>
                    <td><?php echo number_format($grand_totals['approved']); ?></td>
                    <td><?php echo number_format($grand_totals['returned']); ?></td>
                    <td><?php echo number_format($grand_totals['declined']); ?></td>
                    <td><?php echo $avg_processing_time_formatted; ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="container">
        <div class="page-header">
            <h1>Financial Analytics</h1>
            <p>A summary of document actions across all departments.</p>
        </div>

        <div class="button-group" style="text-align: right; margin-bottom: 20px;">
            <button type="button" class="btn-print" onclick="window.print()">🖨️ Print Report</button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($grand_totals['requested']); ?></div>
                <div class="stat-label">Total Requested</div>
            </div>
            <div class="stat-card approved">
                <div class="stat-value"><?php echo number_format($grand_totals['approved']); ?></div>
                <div class="stat-label">Total Approved</div>
            </div>
            <div class="stat-card returned">
                <div class="stat-value"><?php echo number_format($grand_totals['returned']); ?></div>
                <div class="stat-label">Total Returned</div>
            </div>
            <div class="stat-card declined">
                <div class="stat-value"><?php echo number_format($grand_totals['declined']); ?></div>
                <div class="stat-label">Total Declined</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $avg_processing_time_formatted; ?></div>
                <div class="stat-label">Avg. Processing Time</div>
            </div>
        </div>

        <div class="stats-grid" style="margin-top: 30px;">
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Financial Voucher Outcomes</h3>
                </div>
                <div class="chart-container">
                    <canvas id="outcomesChart"></canvas>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Monthly Value vs. Volume (<?php echo $selected_year; ?>)</h3>
                </div>
                <div class="chart-container">
                    <canvas id="monthlyAmountsChart"></canvas>
                </div>
            </div>
        </div>

    </div>

    <div class="container" style="margin-top: 50px;">
        <div class="page-header">
            <h1>Monthly Approved Amounts</h1>
            <p>Total value of vouchers approved per month.</p>
        </div>

        <div class="filter-section" style="margin-bottom: 20px;">
            <form method="GET" class="filter-controls">
                <div class="filter-group">
                    <label>Select Year</label>
                    <select name="year" onchange="this.form.submit()">
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $year === $selected_year ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Year</th>
                        <th>Total Approved Amount</th>
                        <th>Vouchers Approved</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($monthly_approved_amounts)): ?>
                        <?php foreach ($monthly_approved_amounts as $monthly_data): ?>
                            <tr>
                                <td><?php echo date('F', mktime(0, 0, 0, $monthly_data['month'], 1)); ?></td>
                                <td><?php echo $monthly_data['year']; ?></td>
                                <td>₱<?php echo number_format($monthly_data['total_approved_amount'], 2); ?></td>
                                <td style="text-align: center;"><?php echo number_format($monthly_data['total_approved_count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No approved vouchers for <?php echo $selected_year; ?>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="container" style="margin-top: 50px;">
        <div class="page-header">
            <h1>Departmental Activity Breakdown</h1>
            <p>A summary of document requests and actions per office.</p>
        </div>

        <div class="stats-grid" style="grid-template-columns: 1fr; margin-bottom: 30px;">
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Departmental Activity</h3>
                </div>
                <div class="chart-container" style="height: 400px;">
                    <canvas id="departmentalActivityChart"></canvas>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Office / Department</th>
                        <th>Requested</th>
                        <th>Approval Actions</th>
                        <th>Returned</th>
                        <th>Declined</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats_by_office as $office => $data): ?>
                        <tr>
                            <td class="office-name"><?php echo htmlspecialchars($office); ?></td>
                            <td><?php echo number_format($data['requested']); ?></td>
                            <td><?php echo number_format($data['approved']); ?></td>
                            <td><?php echo number_format($data['returned']); ?></td>
                            <td><?php echo number_format($data['declined']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Grand Totals</td>
                        <td><?php echo number_format($grand_totals['requested']); ?></td>
                        <td><?php echo number_format($total_approval_actions); ?></td>
                        <td><?php echo number_format($grand_totals['returned']); ?></td>
                        <td><?php echo number_format($grand_totals['declined']); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="container" style="margin-top: 50px;">
        <div class="page-header">
            <h1>Top Departments by Spending</h1>
            <p>Total value of requested financial vouchers per office.</p>
        </div>

        <div class="stats-grid" style="grid-template-columns: 1fr; margin-bottom: 30px;">
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Top 10 Departments by Requested Amount</h3>
                </div>
                <div class="chart-container" style="height: 400px;">
                    <canvas id="topSpendingChart"></canvas>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Office / Department</th>
                        <th>Total Requested Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($spending_by_dept)): ?>
                        <?php foreach ($spending_by_dept as $data): ?>
                            <tr>
                                <td class="office-name"><?php echo htmlspecialchars($data['office']); ?></td>
                                <td>₱<?php echo number_format($data['total_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="2" style="text-align: center; color: var(--text-muted);">No spending data available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$conn->close();
?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Outcomes Doughnut Chart
    const outcomesCtx = document.getElementById('outcomesChart');
    if (outcomesCtx) {
        new Chart(outcomesCtx, {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Returned', 'Declined'],
                datasets: [{
                    label: 'Voucher Outcomes',
                    data: <?php echo json_encode([$grand_totals['approved'], $grand_totals['returned'], $grand_totals['declined']]); ?>,
                    backgroundColor: [
                        '#10b981', // Green
                        '#f59e0b', // Amber
                        '#ef4444'  // Red
                    ],
                    borderColor: '#ffffff',
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    // 2. Monthly Approved Amounts Bar Chart
    const monthlyAmountsCtx = document.getElementById('monthlyAmountsChart');
    if (monthlyAmountsCtx) {
        new Chart(monthlyAmountsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($monthly_chart_labels); ?>,
                datasets: [
                    {
                        label: 'Total Amount (PHP)',
                        data: <?php echo json_encode($monthly_chart_data_amount); ?>,
                        yAxisID: 'y-amount',
                        borderColor: 'rgba(30, 58, 138, 1)',
                        backgroundColor: 'rgba(30, 58, 138, 0.2)',
                        tension: 0.1,
                        fill: true
                    },
                    {
                        label: 'Number of Vouchers',
                        data: <?php echo json_encode($monthly_chart_data_count); ?>,
                        yAxisID: 'y-count',
                        borderColor: 'rgba(245, 158, 11, 1)',
                        backgroundColor: 'rgba(245, 158, 11, 0.2)',
                        tension: 0.1,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { 
                    legend: { position: 'top' },
                    title: { display: false }
                },
                scales: {
                    'y-amount': {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: { display: true, text: 'Total Amount (PHP)' },
                        ticks: {
                            callback: function(value) {
                                return '₱' + new Intl.NumberFormat().format(value);
                            }
                        }
                    },
                    'y-count': {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        beginAtZero: true,
                        title: { display: true, text: 'Number of Vouchers' },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    // 3. Departmental Activity Grouped Bar Chart
    const departmentalActivityCtx = document.getElementById('departmentalActivityChart');
    if (departmentalActivityCtx) {
        new Chart(departmentalActivityCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($dept_chart_labels); ?>,
                datasets: [
                    {
                        label: 'Requested',
                        data: <?php echo json_encode($dept_chart_data_requested); ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.7)', // blue
                    },
                    {
                        label: 'Approval Actions',
                        data: <?php echo json_encode($dept_chart_data_approved); ?>,
                        backgroundColor: 'rgba(16, 185, 129, 0.7)', // green
                    },
                    {
                        label: 'Returned',
                        data: <?php echo json_encode($dept_chart_data_returned); ?>,
                        backgroundColor: 'rgba(245, 158, 11, 0.7)', // amber
                    },
                    {
                        label: 'Declined',
                        data: <?php echo json_encode($dept_chart_data_declined); ?>,
                        backgroundColor: 'rgba(239, 68, 68, 0.7)', // red
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: { display: true, text: 'Document Actions by Department' }
                },
                scales: { x: { stacked: false }, y: { stacked: false, beginAtZero: true } }
            }
        });
    }

    // 4. Top Spending Departments Chart
    const topSpendingCtx = document.getElementById('topSpendingChart');
    if (topSpendingCtx) {
        new Chart(topSpendingCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($spending_chart_labels); ?>,
                datasets: [{
                    label: 'Total Requested Amount (PHP)',
                    data: <?php echo json_encode($spending_chart_data); ?>,
                    backgroundColor: 'rgba(30, 58, 138, 0.7)',
                    borderColor: 'rgba(30, 58, 138, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y', // Horizontal bar chart
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    title: { display: false }
                },
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: 'Amount (PHP)' } }
                }
            }
        });
    }
});
</script>
</body>
</html>