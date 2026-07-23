<?php
session_start();
if (!isset($_SESSION['logged_in'])) { header("Location: login.php"); exit(); }
$my_role = $_SESSION['role'];

require_once 'db_connect.php';

// Determine view mode (for MIS admin)
$view_mode = $_GET['view'] ?? '';
if ($my_role !== 'Management Information System Office') {
    $final_view_mode = 'my'; // Non-admins can only see their own documents
} else {
    // For MIS, default to 'all' unless 'my' is explicitly requested
    $final_view_mode = ($view_mode === 'my') ? 'my' : 'all';
}
$page_title = ($final_view_mode === 'all') ? 'Central Document Repository' : 'My Document History';

// Get current user's ID for filtering "My Document History"
$user_id = 0;
if ($final_view_mode === 'my') {
    $id_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $id_stmt->bind_param("s", $_SESSION['username']);
    $id_stmt->execute();
    $id_result = $id_stmt->get_result();
    if ($id_result->num_rows > 0) {
        $user_id = $id_result->fetch_assoc()['user_id'];
    }
    $id_stmt->close();
}

// FILTER INPUTS
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'All';

$viewable_vouchers = []; // Initialize array to hold results for both views

if ($final_view_mode === 'all') {
    /************************************************
     * CENTRAL DOCUMENT REPOSITORY (for MIS Admin)
     ************************************************/
    $sql_base = "
        SELECT * FROM (
            SELECT 
                v.voucher_code, v.document_title, v.date_submitted, v.status, v.reference_number, v.tags,
                COALESCE(vt.name, dt.name) as doc_type_name,
                u.full_name as requestor_name, u.role as origin_office, v.arta_deadline
            FROM vouchers v
            LEFT JOIN users u ON v.requestor_id = u.user_id 
            LEFT JOIN document_types dt ON v.doc_type_id = dt.id
            LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
            
            UNION ALL
            
            SELECT 
                va.voucher_code, va.document_title, va.date_submitted, va.status, va.reference_number, va.tags,
                COALESCE(vta.name, dta.name) as doc_type_name,
                ua.full_name as requestor_name, ua.role as origin_office, va.arta_deadline
            FROM vouchers_archive va
            LEFT JOIN users ua ON va.requestor_id = ua.user_id
            LEFT JOIN document_types dta ON va.doc_type_id = dta.id
            LEFT JOIN voucher_types vta ON va.voucher_type_id = vta.id
        ) as all_vouchers
    ";

    $sql_where = " WHERE 1=1";
    $types = "";
    $params = [];

    if ($status_filter !== 'All') {
        $sql_where .= " AND status = ?";
        $types .= "s";
        $params[] = $status_filter;
    }

    if ($search_query !== '') {
        $sql_where .= " AND (voucher_code LIKE ? OR document_title LIKE ? OR requestor_name LIKE ? OR origin_office LIKE ? OR reference_number LIKE ? OR tags LIKE ?)";
        $types .= "ssssss";
        $search_param = "%" . $search_query . "%";
        for ($i=0; $i<6; $i++) $params[] = $search_param;
    }

    $sql = $sql_base . $sql_where . " ORDER BY date_submitted DESC";

} else {
    /************************************************
     * MY DOCUMENT HISTORY (for all users)
     ************************************************/
    // This is the existing logic
    $sql = "SELECT 
                v.voucher_code, 
                v.document_title,
                v.date_submitted, 
                v.status,
                COALESCE(vt.name, dt.name) as doc_type_name,
                u.full_name as requestor_name,
                u.role as origin_office,
                v.arta_deadline
            FROM vouchers v
            LEFT JOIN users u ON v.requestor_id = u.user_id 
            LEFT JOIN document_types dt ON v.doc_type_id = dt.id
            LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
            WHERE 1=1";

    $types = "";
    $params = [];

    // This is the key difference
    $sql .= " AND v.requestor_id = ?";
    $types .= "i";
    $params[] = $user_id;

    if ($status_filter !== 'All') {
        $sql .= " AND v.status = ?";
        $types .= "s";
        $params[] = $status_filter;
    }

    if ($search_query !== '') {
        $sql .= " AND (v.document_title LIKE ? OR v.voucher_code LIKE ? OR v.reference_number LIKE ? OR v.tags LIKE ?)";
        $types .= "ssss";
        $search_param = "%" . $search_query . "%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    $sql .= " ORDER BY v.date_submitted DESC";

}

// --- EXECUTE THE BUILT QUERY ---
$stmt = $conn->prepare($sql);
if ($stmt && $types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $viewable_vouchers[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Voucher Repository - NAAP</title>
<link rel="stylesheet" href="sidebar.css?v=<?php echo filemtime('sidebar.css'); ?>">
<link rel="stylesheet" href="list.css">
</head>

<body>

<?php include('sidebar.php'); ?>

<div class="main-content">
<div class="container">

<div class="page-header">
<h1><?php echo $page_title; ?></h1>
</div>

<!-- The filter form and table are now the same for both 'my' and 'all' views -->
    <form class="filter-form" method="GET">
        <input type="hidden" name="view" value="<?php echo $final_view_mode; ?>">
        <input type="text" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search_query); ?>">
        <select name="status">
            <option value="All" <?php if ($status_filter == 'All') echo 'selected'; ?>>All Statuses</option>
            <option value="Pending Review" <?php if ($status_filter == 'Pending Review') echo 'selected'; ?>>Pending Review</option>
            <option value="In Transit" <?php if ($status_filter == 'In Transit') echo 'selected'; ?>>In Transit</option>
            <option value="Processing" <?php if ($status_filter == 'Processing') echo 'selected'; ?>>Processing</option>
            <option value="Returned" <?php if ($status_filter == 'Returned') echo 'selected'; ?>>Returned</option>
            <option value="Rejected" <?php if ($status_filter == 'Rejected') echo 'selected'; ?>>Rejected</option>
            <option value="Ready for Release" <?php if ($status_filter == 'Ready for Release') echo 'selected'; ?>>Ready for Release</option>
            <option value="Paid" <?php if ($status_filter == 'Paid') echo 'selected'; ?>>Paid</option>
            <option value="Approved" <?php if ($status_filter == 'Approved') echo 'selected'; ?>>Approved</option>
            <option value="Lapsed" <?php if ($status_filter == 'Lapsed') echo 'selected'; ?>>Lapsed (Overdue)</option>
            <option value="Received" <?php if ($status_filter == 'Received') echo 'selected'; ?>>Received</option>
        </select>
        <button class="btn-filter">Filter</button>
        <a href="list.php?view=<?php echo $final_view_mode; ?>" class="btn-reset">Reset</a>
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Document Title</th>
                    <th>Type</th>
                    <th>Created By</th>
                    <th>Origin</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($viewable_vouchers as $v): 
    $row_class = '';
    $deadline_text = '';
    $is_active = in_array($v['status'], ['Pending Review', 'Processing', 'In Transit']);

    if ($is_active && !empty($v['arta_deadline'])) {
        $today = new DateTime();
        $today->setTime(0,0,0); // Compare dates only
        $deadline = new DateTime($v['arta_deadline']);
        $diff = $today->diff($deadline);
        $days_left = $diff->days;

        if ($today > $deadline) {
            $row_class = 'status-overdue';
            $deadline_text = 'Overdue by ' . ($days_left + 1) . ' day(s)';
        } elseif ($days_left <= 2) { // Flag if 2 days or less remain
            $row_class = 'status-at-risk';
            $deadline_text = 'Due in ' . $days_left . ' day(s)';
        }
    }
?>
<tr class="<?php echo $row_class; ?>">
<td><?php echo $v['voucher_code']; ?></td>
<td>
    <?php echo htmlspecialchars($v['document_title']); ?>
    <?php if ($deadline_text): ?>
        <span class="deadline-flag"><?php echo $deadline_text; ?></span>
    <?php endif; ?>
</td>
<td><?php echo htmlspecialchars($v['doc_type_name'] ?? 'N/A'); ?></td>
<td><?php echo htmlspecialchars($v['requestor_name']); ?></td>
<td><?php echo htmlspecialchars($v['origin_office']); ?></td>
<td><?php echo format_db_timestamp($v['date_submitted'], 'M d, Y'); ?></td>
<td><span class="badge status-<?php echo explode(' ', $v['status'])[0]; ?>"><?php echo $v['status']; ?></span></td>
                <td>
                    <a href="track.php?track_id=<?php echo $v['voucher_code']; ?>" class="btn-track">Track</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>
</div>

<?php
$conn->close();
?>
</body>
</html>