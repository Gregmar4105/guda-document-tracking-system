<?php
session_start();

// ACCESS CONTROL: MIS, Management, and Department Heads
$is_mis = ($_SESSION['role'] ?? '') === 'MIS';
$is_admin = ($_SESSION['role'] ?? '') === 'Admin';
$is_head = ($_SESSION['is_head'] ?? 0) == 1;

if (!isset($_SESSION['logged_in']) || !($is_mis || $is_admin || $is_head)) {
    header("Location: home.php");
    exit();
}

require_once 'db_connect.php';
require_once 'bottleneck_analyzer.php';

// Convert PHP errors to Exceptions so they can be caught and shown instead of blank page
set_error_handler(function($severity, $message, $file, $line) {
    // Respect error_reporting level
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Shutdown handler to catch fatal errors and display a message
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        http_response_code(500);
        // Try to render a simple HTML error (avoid depending on other app pieces)
        echo "\n<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Analytics Error</title></head><body>";
        echo "<div style='margin:40px;padding:20px;border:1px solid #ddd;background:#fff;color:#900;'>";
        echo "<h2>Analytics module error</h2>";
        echo "<pre style='white-space:pre-wrap;'>" . htmlspecialchars($err['message'] . "\nFile: " . $err['file'] . " on line " . $err['line']) . "</pre>";
        echo "</div></body></html>";
        error_log('Shutdown error in analytics_bottleneck.php: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    }
});

$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? '';

// Initialize analyzer
$analyzer = new BottleneckAnalyzer($conn, $user_id, $user_role, $is_head, $is_admin || $is_mis);

// Get parameters
$view = $_GET['view'] ?? 'overview';
$department = $_GET['dept'] ?? null;
$days = intval($_GET['days'] ?? 30);

// For department heads, restrict to their department
if ($is_head && !$is_mis && !$is_admin) {
    $department = $user_role;
}

// Prepare data based on view
$data = [];
$bottlenecks = [];
$department_stats = [];
$delayed_docs = [];
$trend_data = [];
$error_message = null;

try {
    switch ($view) {
        case 'bottlenecks':
            $bottlenecks = $analyzer->identifyBottlenecks($days, 75);
            break;
        case 'department':
            if ($department) {
                $delayed_docs = $analyzer->getDelayedDocuments($department, 24, 50);
                $trend_data = $analyzer->getProcessingTimeTrend($department, 60);
            }
            break;
        case 'overview':
        default:
            $department_stats = $analyzer->getDepartmentStats($department, $days);
            $bottlenecks = $analyzer->identifyBottlenecks($days, 75);
            break;
    }
} catch (Exception $e) {
    $error_message = 'Analytics error: ' . $e->getMessage();
    error_log('Analytics exception: ' . $e->getMessage());
}

// Get list of departments for filter (only if MIS or Admin)
$departments = [];
if ($is_mis || $is_admin) {
    $dept_result = $conn->query("SELECT DISTINCT name FROM departments WHERE is_active = 1 ORDER BY name");
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row['name'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Processing Analytics - Bottleneck Detection</title>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo filemtime('sidebar.css'); ?>">
    <link rel="stylesheet" href="layout.css">
    <link rel="stylesheet" href="analytics.css">
    <style>
        .analytics-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
            padding: 2rem;
        }

        .metric-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #2196F3;
        }

        .metric-card.critical {
            border-left-color: #d32f2f;
            background: #ffebee;
        }

        .metric-card.high {
            border-left-color: #f57c00;
            background: #fff3e0;
        }

        .metric-card.medium {
            border-left-color: #fbc02d;
            background: #fffde7;
        }

        .metric-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: #1565c0;
            margin: 0.5rem 0;
        }

        .metric-label {
            font-size: 0.9rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .metric-small {
            font-size: 0.85rem;
            color: #999;
            margin-top: 0.5rem;
        }

        .bottleneck-alert {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .bottleneck-alert.critical {
            background: #f8d7da;
            border-color: #f5c6cb;
        }

        .recommendation {
            background: #e3f2fd;
            border-left: 3px solid #2196F3;
            padding: 0.75rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: #1565c0;
        }

        .chart-container {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-top: 1rem;
        }

        .filters {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .filter-group label {
            font-weight: 600;
            color: #333;
        }

        .filter-group select,
        .filter-group input {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: #2196F3;
            color: white;
            padding: 0.5rem 1.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: #1976D2;
        }

        .table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow-x: auto;
            margin-top: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f5f5f5;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #ddd;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #eee;
        }

        tr:hover {
            background: #f9f9f9;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-critical {
            background: #d32f2f;
            color: white;
        }

        .status-high {
            background: #f57c00;
            color: white;
        }

        .status-medium {
            background: #fbc02d;
            color: #333;
        }

        .status-low {
            background: #388e3c;
            color: white;
        }

        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            background: white;
            padding: 0.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: #f5f5f5;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            color: #666;
            transition: all 0.3s ease;
        }

        .tab-btn.active {
            background: #2196F3;
            color: white;
        }

        .tab-btn:hover {
            background: #e0e0e0;
        }

        .tab-btn.active:hover {
            background: #1976D2;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #999;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .risk-level-high {
            color: #d32f2f;
            font-weight: bold;
        }

        .risk-level-medium {
            color: #f57c00;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php require_once 'sidebar.php'; ?>
    
    <div class="main-container">
        <div class="top-nav">
            <h1>Document Processing Analytics</h1>
            <p>Bottleneck Detection & Performance Monitoring</p>
        </div>

        <div class="analytics-container">
            <!-- Information Box -->
            <?php if (!empty($error_message)): ?>
                <div style="background:#fdecea;border:1px solid #f5c6cb;padding:16px;border-radius:8px;color:#611a15;margin-bottom:1rem;">
                    <strong>Analytics error:</strong>
                    <div style="margin-top:8px;"><?php echo htmlspecialchars($error_message); ?></div>
                </div>
            <?php endif; ?>
            <div style="background: #e3f2fd; border: 1px solid #2196F3; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                <h3 style="margin: 0 0 0.5rem 0; color: #1565c0;">ℹ️ How This Works</h3>
                <p style="margin: 0; color: #0d47a1; font-size: 0.95rem;">
                    This system tracks document processing times across departments to identify potential bottlenecks. 
                    Rather than assigning blame, it provides management with objective operational data to investigate causes 
                    and take appropriate action. The system monitors improvement over time.
                </p>
            </div>

            <!-- Filters -->
            <div class="filters">
                <div class="filter-group">
                    <label>Time Period:</label>
                    <form method="GET" style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                        <select name="days" onchange="this.form.submit()">
                            <option value="7" <?php echo $days == 7 ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="14" <?php echo $days == 14 ? 'selected' : ''; ?>>Last 14 Days</option>
                            <option value="30" <?php echo $days == 30 ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="60" <?php echo $days == 60 ? 'selected' : ''; ?>>Last 60 Days</option>
                            <option value="90" <?php echo $days == 90 ? 'selected' : ''; ?>>Last 90 Days</option>
                        </select>
                    </form>
                </div>

                <?php if ($is_mis || $is_admin): ?>
                <div class="filter-group">
                    <label>Department:</label>
                    <form method="GET" style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                        <input type="hidden" name="days" value="<?php echo $days; ?>">
                        <select name="dept" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" 
                                    <?php echo $department === $dept ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- View Tabs -->
            <div class="tabs">
                <button class="tab-btn <?php echo $view == 'overview' ? 'active' : ''; ?>" 
                    onclick="window.location.href='?view=overview&days=<?php echo $days; ?><?php echo $department ? '&dept=' . urlencode($department) : ''; ?>'">
                    📊 Overview
                </button>
                <button class="tab-btn <?php echo $view == 'bottlenecks' ? 'active' : ''; ?>" 
                    onclick="window.location.href='?view=bottlenecks&days=<?php echo $days; ?><?php echo $department ? '&dept=' . urlencode($department) : ''; ?>'">
                    ⚠️ Bottleneck Analysis
                </button>
                <?php if (!$is_head || $is_mis || $is_admin): ?>
                <button class="tab-btn <?php echo $view == 'department' ? 'active' : ''; ?>" 
                    onclick="window.location.href='?view=department&days=<?php echo $days; ?><?php echo $department ? '&dept=' . urlencode($department) : ''; ?>'">
                    📈 Department Details
                </button>
                <?php endif; ?>
            </div>

            <!-- OVERVIEW VIEW -->
            <?php if ($view == 'overview'): ?>
                <?php if (empty($department_stats)): ?>
                    <div class="empty-state">
                        <p>No data available for the selected period.</p>
                    </div>
                <?php else: ?>
                    <div class="summary-grid">
                        <?php foreach ($department_stats as $stat): ?>
                            <div class="metric-card">
                                <div class="metric-label">Department</div>
                                <div class="metric-value" style="font-size: 1.5rem; color: #333;">
                                    <?php echo htmlspecialchars($stat['department']); ?>
                                </div>
                                <hr style="border: none; border-top: 1px solid #ddd; margin: 1rem 0;">
                                <div style="margin: 0.75rem 0;">
                                    <strong>Avg Processing Time:</strong> 
                                    <span class="risk-level-<?php echo $stat['avg_stay_hours'] > 24 ? 'high' : 'medium'; ?>">
                                        <?php echo round($stat['avg_stay_hours'], 1); ?> hours
                                    </span>
                                </div>
                                <div style="margin: 0.5rem 0;">
                                    <strong>Total Documents:</strong> <?php echo $stat['total_documents']; ?>
                                </div>
                                <div style="margin: 0.5rem 0;">
                                    <strong>Pending:</strong> <?php echo $stat['pending_documents']; ?>
                                </div>
                                <a href="?view=department&dept=<?php echo urlencode($stat['department']); ?>" 
                                   style="display: inline-block; margin-top: 1rem; color: #2196F3; text-decoration: none; font-weight: 600;">
                                    View Details →
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($bottlenecks)): ?>
                        <div style="margin-top: 2rem;">
                            <h2 style="color: #d32f2f; margin-top: 0;">⚠️ Identified Bottlenecks</h2>
                            <p style="color: #666; margin-bottom: 1rem;">
                                These departments show processing delays that may warrant management investigation.
                            </p>
                            
                            <?php foreach ($bottlenecks as $bottleneck): ?>
                                <div class="bottleneck-alert <?php echo strtolower($bottleneck['risk_level']); ?>">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div>
                                            <h4 style="margin: 0 0 0.5rem 0;">
                                                <?php echo htmlspecialchars($bottleneck['department']); ?>
                                                <span class="status-badge status-<?php echo strtolower($bottleneck['risk_level']); ?>">
                                                    <?php echo $bottleneck['risk_level']; ?>
                                                </span>
                                            </h4>
                                            <p style="margin: 0.5rem 0; font-size: 0.9rem;">
                                                <strong>Average Processing Time:</strong> <?php echo round($bottleneck['avg_stay_hours'], 1); ?> hours<br>
                                                <strong>Delayed Documents:</strong> <?php echo $bottleneck['delayed_documents']; ?> / <?php echo $bottleneck['total_documents']; ?> 
                                                (<?php echo round($bottleneck['delay_percentage'], 1); ?>%)<br>
                                                <strong>Pending Documents:</strong> <?php echo $bottleneck['pending_documents']; ?>
                                            </p>
                                            <div class="recommendation">
                                                <strong>🔍 Management Insight:</strong> <?php echo htmlspecialchars($bottleneck['recommendation']); ?>
                                            </div>
                                        </div>
                                        <a href="?view=department&dept=<?php echo urlencode($bottleneck['department']); ?>" 
                                           style="white-space: nowrap; margin-left: 1rem; color: #2196F3; text-decoration: none; font-weight: 600;">
                                            View Details →
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <!-- BOTTLENECK ANALYSIS VIEW -->
            <?php if ($view == 'bottlenecks'): ?>
                <?php if (empty($bottlenecks)): ?>
                    <div class="empty-state">
                        <h3>✅ No Bottlenecks Detected</h3>
                        <p>All departments are processing documents within acceptable time ranges.</p>
                    </div>
                <?php else: ?>
                    <h2>Bottleneck Analysis</h2>
                    <?php foreach ($bottlenecks as $bottleneck): ?>
                        <div class="bottleneck-alert <?php echo strtolower($bottleneck['risk_level']); ?>">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <div style="flex: 1;">
                                    <h4 style="margin: 0 0 1rem 0;">
                                        <?php echo htmlspecialchars($bottleneck['department']); ?>
                                        <span class="status-badge status-<?php echo strtolower($bottleneck['risk_level']); ?>">
                                            <?php echo $bottleneck['risk_level']; ?>
                                        </span>
                                    </h4>
                                    
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                                        <div>
                                            <strong style="color: #666;">Average Processing Time</strong><br>
                                            <span style="font-size: 1.5rem; color: #1565c0; font-weight: bold;">
                                                <?php echo round($bottleneck['avg_stay_hours'], 1); ?> hours
                                            </span>
                                        </div>
                                        <div>
                                            <strong style="color: #666;">Delayed Documents</strong><br>
                                            <span style="font-size: 1.5rem; color: #d32f2f; font-weight: bold;">
                                                <?php echo $bottleneck['delayed_documents']; ?>/<?php echo $bottleneck['total_documents']; ?>
                                            </span>
                                            <span style="font-size: 0.85rem; color: #999;">
                                                (<?php echo round($bottleneck['delay_percentage'], 1); ?>%)
                                            </span>
                                        </div>
                                        <div>
                                            <strong style="color: #666;">Pending Documents</strong><br>
                                            <span style="font-size: 1.5rem; color: #f57c00; font-weight: bold;">
                                                <?php echo $bottleneck['pending_documents']; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="recommendation">
                                        <strong>🔍 Management Insight:</strong> 
                                        <?php echo htmlspecialchars($bottleneck['recommendation']); ?>
                                    </div>

                                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(0,0,0,0.1);">
                                        <p style="margin: 0; font-size: 0.85rem; color: #666;">
                                            <strong>💡 Investigation Suggestions:</strong><br>
                                            • Review workload and staffing levels for this department<br>
                                            • Check if document completeness is causing delays<br>
                                            • Assess current workflow procedures<br>
                                            • Identify any systemic issues or bottlenecks<br>
                                            • Track improvements after implementing changes
                                        </p>
                                    </div>
                                </div>
                                <a href="?view=department&dept=<?php echo urlencode($bottleneck['department']); ?>&days=<?php echo $days; ?>" 
                                   style="white-space: nowrap; margin-left: 1.5rem; padding: 0.5rem 1rem; background: #2196F3; color: white; text-decoration: none; border-radius: 4px; font-weight: 600; align-self: flex-start;">
                                    View Details
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>

            <!-- DEPARTMENT DETAILS VIEW -->
            <?php if ($view == 'department'): ?>
                <?php if (!$department): ?>
                    <div class="empty-state">
                        <p>Select a department to view detailed analytics.</p>
                    </div>
                <?php else: ?>
                    <h2><?php echo htmlspecialchars($department); ?> - Detailed Analysis</h2>

                    <?php if (!empty($delayed_docs)): ?>
                        <div class="metric-card" style="margin-bottom: 2rem;">
                            <h3 style="margin-top: 0;">Recently Delayed Documents</h3>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Document Code</th>
                                            <th>Received At</th>
                                            <th>Processing Time</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($delayed_docs as $doc): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($doc['voucher_code']); ?></strong></td>
                                                <td><?php echo format_db_timestamp($doc['received_at'], 'M d, Y h:i A'); ?></td>
                                                <td>
                                                    <span style="font-weight: bold; color: #d32f2f;">
                                                        <?php echo round($doc['stay_hours'], 1); ?> hours
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo $doc['is_completed'] ? 
                                                        '<span class="status-badge" style="background: #388e3c; color: white;">Completed</span>' : 
                                                        '<span class="status-badge" style="background: #fbc02d; color: #333;">Pending</span>'; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>No delayed documents found for this department in the selected period.</p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($trend_data)): ?>
                        <div class="chart-container">
                            <h3 style="margin-top: 0;">Processing Time Trend (Last 60 Days)</h3>
                            <p style="color: #666; font-size: 0.9rem;">
                                This chart shows average daily processing time. A downward trend indicates improvements; 
                                upward trends may indicate growing bottlenecks.
                            </p>
                            <canvas id="trendChart" width="400" height="150"></canvas>
                        </div>

                        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
                        <script>
                            const trendData = <?php echo json_encode($trend_data); ?>;
                            const ctx = document.getElementById('trendChart').getContext('2d');
                            
                            new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: trendData.map(d => new Date(d.date).toLocaleDateString()),
                                    datasets: [{
                                        label: 'Average Processing Time (hours)',
                                        data: trendData.map(d => parseFloat(d.avg_stay_hours)),
                                        borderColor: '#2196F3',
                                        backgroundColor: 'rgba(33, 150, 243, 0.1)',
                                        fill: true,
                                        tension: 0.4
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    plugins: {
                                        legend: {
                                            display: true
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
                        </script>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>

    <style>
        .main-container {
            margin-left: 250px;
            padding: 2rem;
        }

        .top-nav {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .top-nav h1 {
            margin: 0 0 0.5rem 0;
            color: #333;
        }

        .top-nav p {
            margin: 0;
            color: #999;
        }

        @media (max-width: 768px) {
            .main-container {
                margin-left: 0;
            }

            .analytics-container {
                grid-template-columns: 1fr;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .filters {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</body>
</html>
<?php $conn->close(); ?>
