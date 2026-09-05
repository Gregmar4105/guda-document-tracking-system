<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

require_once 'db_connect.php';

$my_role = $_SESSION['role'];
$my_username = $_SESSION['username'];
$my_full_name = $_SESSION['full_name'];
$my_job_title = ''; // Default
$my_email = ''; // Default
$my_user_id = 0; // Default

// Get current user's job title and email
$user_info_stmt = $conn->prepare("SELECT user_id, job_title, email FROM users WHERE username = ?");
$user_info_stmt->bind_param("s", $my_username);
$user_info_stmt->execute();
$user_info_res = $user_info_stmt->get_result();
if ($user_info_row = $user_info_res->fetch_assoc()) {
    $my_user_id = $user_info_row['user_id'];
    $my_job_title = $user_info_row['job_title'];
    $my_email = $user_info_row['email'];
}
$user_info_stmt->close();

// Get head status and calculate base role for accurate checks
$is_head = $_SESSION['is_head'] ?? 0;
$base_dept_role = $my_role;
if ($is_head) {
    $base_dept_role = trim(preg_replace('/\s*\(Head\)$/i', '', $base_dept_role));
}
// Also normalize dashes for consistency in comparisons
$base_dept_role = str_replace(['–', '—'], '-', $base_dept_role);

// --- NEW CALENDAR HELPER FUNCTION ---
function build_calendar($month, $year, $highlights = []) {
    $daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $firstDayOfMonth = mktime(0, 0, 0, $month, 1, $year);
    $numberDays = date('t', $firstDayOfMonth);
    $dateComponents = getdate($firstDayOfMonth);
    $monthName = $dateComponents['month'];
    $dayOfWeek = $dateComponents['wday'];
    
    $prev_month = $month - 1; $prev_year = $year;
    if ($prev_month == 0) { $prev_month = 12; $prev_year = $year - 1; }
    $next_month = $month + 1; $next_year = $year;
    if ($next_month == 13) { $next_month = 1; $next_year = $year + 1; }
    
    $calendar = "<div class='calendar-nav'>";
    $calendar .= "<a href='?month=$prev_month&year=$prev_year'>&laquo; Prev</a>";
    $calendar .= "<h2>$monthName $year</h2>";
    $calendar .= "<a href='?month=$next_month&year=$next_year'>Next &raquo;</a>";
    $calendar .= "</div>";
    
    $calendar .= "<table class='calendar-table'><tr>";
    foreach ($daysOfWeek as $day) { $calendar .= "<th class='header'>$day</th>"; }
    $calendar .= "</tr><tr>";
    
    if ($dayOfWeek > 0) { for ($k = 0; $k < $dayOfWeek; $k++) { $calendar .= "<td class='empty'></td>"; } }
    
    $currentDay = 1;
    while ($currentDay <= $numberDays) {
        if ($dayOfWeek == 7) { $dayOfWeek = 0; $calendar .= "</tr><tr>"; }
        $currentDateStr = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($currentDay, 2, '0', STR_PAD_LEFT);
        $class = '';
        if (isset($highlights[$currentDateStr])) {
            $class .= ' highlight-' . $highlights[$currentDateStr]['color'];
        }
        if ($currentDateStr == date('Y-m-d')) { $class .= ' today'; } // Current day highlight
        
        $cell_content = "<span class='day-number'>$currentDay</span>";

        if (isset($highlights[$currentDateStr]['documents'])) {
            $doc_count = count($highlights[$currentDateStr]['documents']);
            if ($doc_count > 0) {
                $cell_content .= "<div class='doc-count'>+$doc_count</div>";

                $tooltip = "<div class='tooltip'>";
                foreach ($highlights[$currentDateStr]['documents'] as $doc) {
                    $voucher_code_html = htmlspecialchars($doc['voucher_code']);
                    $document_title_html = htmlspecialchars($doc['document_title']);
                    $track_url = 'track.php?code=' . urlencode($doc['voucher_code']);

                    $tooltip .= "<div><a href='{$track_url}' target='_blank'><strong>{$voucher_code_html}</strong></a><br><small>{$document_title_html}</small></div>";
                }
                $tooltip .= "</div>";

                $cell_content .= $tooltip;
            }
        }

        $calendar .= "<td class='day $class' rel='$currentDateStr'>$cell_content</td>";
        $currentDay++; $dayOfWeek++;
    }
    
    if ($dayOfWeek != 7) {
        $remainingDays = 7 - $dayOfWeek;
        for ($i = 0; $i < $remainingDays; $i++) { $calendar .= "<td class='empty'></td>"; }
    }
    $calendar .= "</tr></table>";
    return $calendar;
}
 
// 3. FETCH DASHBOARD METRICS
// First, get the list of signatory roles, as it's needed for queue logic
$signatory_roles = [];
$seq_res = $conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1 ORDER BY name ASC");
while ($row = $seq_res->fetch_assoc()) {
    $signatory_roles[] = $row['name'];
}

$total_submitted_by_me = 0;
$pending_my_action = 0;
$pending_breakdown = []; // For head's tooltip
$is_signatory = in_array($base_dept_role, $signatory_roles);

// Metric 1: Total documents submitted by the current user
$total_submitted_stmt = $conn->prepare("SELECT COUNT(*) as total FROM vouchers WHERE requestor_id = ?");
$total_submitted_stmt->bind_param("i", $my_user_id);
$total_submitted_stmt->execute();
$total_submitted_res = $total_submitted_stmt->get_result();
if ($total_submitted_row = $total_submitted_res->fetch_assoc()) {
    $total_submitted_by_me = $total_submitted_row['total'] ?? 0;
}
$total_submitted_stmt->close();

// --- NEW: Metric for documents "En Route" to the current user ---
$en_route_to_me = 0;
$en_route_breakdown = []; // New array for tooltip data
if ($is_signatory) {
    $en_route_sql = <<<'SQL'
        SELECT
            v.voucher_code,
            v.document_title,
            u_req.full_name as requestor_name,
            v.custom_workflow,
            v.current_stage_index
        FROM vouchers v
        LEFT JOIN users u_req ON v.requestor_id = u_req.user_id
        WHERE
            v.status IN ('Pending Review', 'Processing', 'In Transit')
            AND NOT EXISTS ( -- Exclude documents already received by the current user's DEPARTMENT
                SELECT 1 FROM audit_logs al
                WHERE al.voucher_code = v.voucher_code
                AND al.action_taken = 'Scan-to-Receive'
                AND al.department LIKE ?
            )
            AND (
                -- Case 1: Custom workflow step matches user's department
                (JSON_LENGTH(v.custom_workflow) > 0 AND REPLACE(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))), '–', '-'), '—', '-') = ?)
 
                -- Case 2: Custom workflow step is 'Department Head' AND the user is the head of the requestor's department
                OR (
                    JSON_LENGTH(v.custom_workflow) > 0 
                    AND JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))) = 'Department Head'
                    AND REPLACE(REPLACE(u_req.role, '–', '-'), '—', '-') = ? -- The requestor's department is the same as the current user's department
                    AND ? = 1 -- The current user is a head
                )
 
                -- Case 3: Custom workflow step is for a specific department head, e.g., "Accounting (Head)"
                OR (
                    JSON_LENGTH(v.custom_workflow) > 0
                    AND JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))) LIKE '% (Head)'
                    AND ? = 1 -- The current user must be a head
                    AND ? = REPLACE(REPLACE(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))), ' (Head)', 1), '–', '-'), '—', '-') -- The user's base role must match the department name part
                )
            )
SQL;
    $en_route_stmt = $conn->prepare($en_route_sql);
    $like_param = $base_dept_role . '%';
    $en_route_stmt->bind_param("sssiis", $like_param, $base_dept_role, $base_dept_role, $is_head, $is_head, $base_dept_role);
    $en_route_stmt->execute();
    $en_route_res = $en_route_stmt->get_result();
    while ($row = $en_route_res->fetch_assoc()) {
        $en_route_breakdown[] = $row;
    }
    $en_route_to_me = count($en_route_breakdown);
    $en_route_stmt->close();
}

// Metric 2: Documents pending my action (depends on role)
if ($my_role === 'Requestor') {
    // For Requestors: Documents they submitted that are still in progress
    $pending_stmt = $conn->prepare("SELECT COUNT(*) as pending FROM vouchers WHERE requestor_id = ? AND status IN ('Pending Review', 'Processing', 'In Transit')");
    $pending_stmt->bind_param("i", $my_user_id);
    $pending_stmt->execute();
    $pending_res = $pending_stmt->get_result();
    if ($pending_row = $pending_res->fetch_assoc()) {
        $pending_my_action = $pending_row['pending'] ?? 0;
    }
    $pending_stmt->close();
} else { // Signatory (including MIS, if they act as one)
    // For Signatories: Documents in their queue (logic similar to queue.php)
    $my_stage_index_for_queue = -1;
    $found_index_for_queue = array_search($my_role, $signatory_roles);
    if ($found_index_for_queue !== false) {
        $my_stage_index_for_queue = $found_index_for_queue + 1; // 1-based index
    }

    if ($is_head && $my_role !== 'Management Information System Office') {
        // --- DEPARTMENT HEAD LOGIC ---
        // Fetches breakdown for tooltip and calculates total.
        // This query is now more robust to handle all custom workflow routing cases.
        $sql = <<<'SQL'
            SELECT u.full_name, COUNT(DISTINCT v.voucher_code) as user_pending_count
            FROM vouchers v
            INNER JOIN audit_logs al ON v.voucher_code = al.voucher_code AND al.action_taken = 'Scan-to-Receive' AND al.department LIKE ?
            INNER JOIN users u ON al.processed_by_user_id = u.user_id
            LEFT JOIN users u_req ON v.requestor_id = u_req.user_id
            WHERE
                (
                    -- Case 1: Custom workflow step matches user's department
                    (JSON_LENGTH(v.custom_workflow) > 0 AND REPLACE(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))), '–', '-'), '—', '-') = ?)

                    -- Case 2: Custom workflow step is 'Department Head' AND the user is the head of the requestor's department
                    OR (
                        JSON_LENGTH(v.custom_workflow) > 0 
                        AND JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))) = 'Department Head'
                        AND REPLACE(REPLACE(u_req.role, '–', '-'), '—', '-') = ? -- The requestor's department is the same as the current user's department
                        AND ? = 1 -- The current user is a head
                    )

                    -- NEW Case 2.5: Custom workflow step is for a specific department head, e.g., "Accounting (Head)"
                    OR (
                        JSON_LENGTH(v.custom_workflow) > 0
                        AND JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))) LIKE '% (Head)'
                        AND ? = 1 -- The current user must be a head
                        AND ? = REPLACE(REPLACE(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))), ' (Head)', 1), '–', '-'), '—', '-') -- The user's role must match the department name part
                    )

                    -- Case 3: Fallback for default workflow (no JSON)
                    OR ((v.custom_workflow IS NULL OR JSON_LENGTH(v.custom_workflow) = 0) AND v.current_stage_index = ?)
                )
                AND v.status IN ('Pending Review', 'Processing', 'In Transit')
                AND NOT EXISTS (
                    SELECT 1 FROM audit_logs al2 
                    WHERE al2.voucher_code = v.voucher_code 
                    AND al2.department LIKE ?
                    AND al2.action_taken IN ('Accepted', 'RETURNED', 'DECLINED')
                )
            GROUP BY al.processed_by_user_id, u.full_name
            ORDER BY user_pending_count DESC
SQL;
        $like_param = $base_dept_role . '%';
        $pending_stmt = $conn->prepare($sql);
        $pending_stmt->bind_param("sssiisis", $like_param, $base_dept_role, $base_dept_role, $is_head, $is_head, $base_dept_role, $my_stage_index_for_queue, $like_param);
        $pending_stmt->execute();
        $pending_res = $pending_stmt->get_result();
        while ($row = $pending_res->fetch_assoc()) {
            $pending_breakdown[] = $row;
        }
        $pending_my_action = array_sum(array_column($pending_breakdown, 'user_pending_count'));
        $pending_stmt->close();
    } else {
        // --- REGULAR USER OR MIS ADMIN LOGIC ---
        // Fetches a count of documents in the user's departmental queue.
        if ($my_role === 'Management Information System Office') {
            if ($is_head) {
                // MIS HEAD: Counts all documents scanned into the department.
                $sql = <<<'SQL'
                    SELECT COUNT(DISTINCT v.voucher_code) as pending
                    FROM vouchers v
                    INNER JOIN audit_logs al ON v.voucher_code = al.voucher_code AND al.action_taken = 'Scan-to-Receive' AND al.department = ?
                    WHERE v.status IN ('Pending Review', 'Processing', 'In Transit')
                    AND NOT EXISTS (
                        SELECT 1 FROM audit_logs al2 WHERE al2.voucher_code = v.voucher_code AND al2.department = ? AND al2.action_taken IN ('Accepted', 'RETURNED', 'DECLINED')
                    )
SQL;
                $pending_stmt = $conn->prepare($sql);
                $pending_stmt->bind_param("ss", $my_role, $my_role);
            } else {
                // MIS STAFF: Counts only documents they personally scanned.
                $sql = <<<'SQL'
                    SELECT COUNT(DISTINCT v.voucher_code) as pending
                    FROM vouchers v
                    INNER JOIN audit_logs al ON v.voucher_code = al.voucher_code AND al.action_taken = 'Scan-to-Receive' AND al.department = ?
                    WHERE al.processed_by_user_id = ?
                    AND v.status IN ('Pending Review', 'Processing', 'In Transit')
                    AND NOT EXISTS (
                        SELECT 1 FROM audit_logs al2 WHERE al2.voucher_code = v.voucher_code AND al2.department = ? AND al2.action_taken IN ('Accepted', 'RETURNED', 'DECLINED')
                    )
SQL;
                $pending_stmt = $conn->prepare($sql);
                $pending_stmt->bind_param("sis", $my_role, $my_user_id, $my_role);
            }
        } else {
            // This query is now aligned with the robust logic from queue.php to correctly identify actionable items.
            $sql = <<<'SQL'
                SELECT COUNT(DISTINCT v.voucher_code) as pending
                FROM vouchers v
                INNER JOIN audit_logs al ON v.voucher_code = al.voucher_code AND al.action_taken = 'Scan-to-Receive' AND al.department = ?
                LEFT JOIN users u ON v.requestor_id = u.user_id
                WHERE
                    (
                        -- Case 1: Custom workflow step matches user's department
                        (JSON_LENGTH(v.custom_workflow) > 0 AND REPLACE(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))), '–', '-'), '—', '-') = ?)
 
                        -- Case 2: Custom workflow step is 'Department Head' AND the user is the head of the requestor's department
                        OR (
                            JSON_LENGTH(v.custom_workflow) > 0 
                            AND JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))) = 'Department Head'
                            AND REPLACE(REPLACE(u.role, '–', '-'), '—', '-') = ? -- The requestor's department is the same as the current user's department
                            AND ? = 1 -- The current user is a head
                        )
 
                        -- NEW Case 2.5: Custom workflow step is for a specific department head, e.g., "Accounting (Head)"
                        OR (
                            JSON_LENGTH(v.custom_workflow) > 0
                            AND JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))) LIKE '% (Head)'
                            AND ? = 1 -- The current user must be a head
                            AND ? = REPLACE(REPLACE(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))), ' (Head)', 1), '–', '-'), '—', '-') -- The user's role must match the department name part
                        )
 
                        -- Case 3: Fallback for default workflow (no JSON)
                        OR ((v.custom_workflow IS NULL OR JSON_LENGTH(v.custom_workflow) = 0) AND v.current_stage_index = ?)
                    )
                    AND v.status IN ('Pending Review', 'Processing', 'In Transit')
                    AND NOT EXISTS (
                        SELECT 1 FROM audit_logs al2 
                        WHERE al2.voucher_code = v.voucher_code
                        AND al2.department = ? 
                        AND al2.action_taken IN ('Accepted', 'RETURNED', 'DECLINED') 
                    )
SQL;
            $pending_stmt = $conn->prepare($sql);
            // Note: The processed_by_user_id = ? was removed from the JOIN, so the corresponding parameter is removed.
            $pending_stmt->bind_param("sssiisis", $my_role, $base_dept_role, $base_dept_role, $is_head, $is_head, $base_dept_role, $my_stage_index_for_queue, $my_role);
        }
        
        $pending_stmt->execute();
        $pending_res = $pending_stmt->get_result();
        if ($pending_row = $pending_res->fetch_assoc()) {
            $pending_my_action = $pending_row['pending'] ?? 0;
        }
        $pending_stmt->close();
    }
}

// 4. FETCH DATA FOR CALENDAR HIGHLIGHTING
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$first_day_of_month = "$year-$month-01";
$last_day_of_month = date('Y-m-t', strtotime($first_day_of_month));

// Fetch all holidays in a buffered range to optimize date calculations
$holidays = [];
$start_buffer = date('Y-m-d', strtotime("$first_day_of_month -1 month"));
$end_buffer = date('Y-m-d', strtotime("$last_day_of_month +1 month"));
$holiday_stmt = $conn->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
$holiday_stmt->bind_param("ss", $start_buffer, $end_buffer);
$holiday_stmt->execute();
$holiday_res = $holiday_stmt->get_result();
while($h_row = $holiday_res->fetch_assoc()) {
    $holidays[] = $h_row['holiday_date'];
}
$holiday_stmt->close();

$my_stage_index = -1; // Default
$documents_for_calendar = [];

// Determine if user is a signatory and get their stage index for the default workflow
$is_signatory = in_array($my_role, $signatory_roles);

if ($is_signatory) {
    $found_index = array_search($my_role, $signatory_roles);
    if ($found_index !== false) {
        $my_stage_index = $found_index + 1; // 1-based index
    }

    // Base SQL for all signatories
    $sql_base = "SELECT v.voucher_code, v.document_title, v.arta_deadline, al_receive.created_at as start_date, al.processing_days 
            FROM vouchers v 
            LEFT JOIN document_types dt ON v.doc_type_id = dt.id 
            LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id 
            LEFT JOIN arta_levels al ON al.level_name = COALESCE(vt.arta_level, dt.arta_level) 
            JOIN audit_logs al_receive ON v.voucher_code = al_receive.voucher_code AND al_receive.action_taken = 'Scan-to-Receive' AND al_receive.department LIKE ? 
            LEFT JOIN users u_req ON v.requestor_id = u_req.user_id
            WHERE v.status IN ('Pending Review', 'Processing', 'In Transit') 
            AND v.arta_deadline IS NOT NULL 
            AND (DATE_ADD(al_receive.created_at, INTERVAL (COALESCE(al.processing_days, 3) + 30) DAY) >= ? AND al_receive.created_at <= ?)";

    $sql_where_stage = " AND (
        -- Case 1: Custom workflow step matches user's department
        (JSON_LENGTH(v.custom_workflow) > 0 AND REPLACE(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))), '–', '-'), '—', '-') = ?)
 
        -- Case 2: Custom workflow step is 'Department Head' AND the user is the head of the requestor's department
        OR (
            JSON_LENGTH(v.custom_workflow) > 0 
            AND JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))) = 'Department Head'
            AND REPLACE(REPLACE(u_req.role, '–', '-'), '—', '-') = ? -- The requestor's department is the same as the current user's department
            AND ? = 1 -- The current user is a head
        )
 
        -- NEW Case 2.5: Custom workflow step is for a specific department head, e.g., \"Accounting (Head)\"
        OR (
            JSON_LENGTH(v.custom_workflow) > 0
            AND JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))) LIKE '% (Head)'
            AND ? = 1 -- The current user must be a head
            AND ? = REPLACE(REPLACE(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.custom_workflow, CONCAT('$[', v.current_stage_index - 1, ']'))), ' (Head)', 1), '–', '-'), '—', '-') -- The user's role must match the department name part
        )
 
        -- Case 3: Fallback for default workflow (no JSON)
        OR ((v.custom_workflow IS NULL OR JSON_LENGTH(v.custom_workflow) = 0) AND v.current_stage_index = ?)
    )";
    $sql_end = " AND NOT EXISTS ( SELECT 1 FROM audit_logs al2 WHERE al2.voucher_code = v.voucher_code AND al2.department LIKE ? AND al2.action_taken IN ('Accepted', 'RETURNED', 'DECLINED') )";

    // MIS has special privileges to see all documents in its queue, regardless of stage.
    if ($my_role === 'MIS') {
        $sql = $sql_base . $sql_end;
        $like_param = $base_dept_role . '%';
        $deadline_stmt = $conn->prepare($sql);
        $deadline_stmt->bind_param("ssss", $like_param, $first_day_of_month, $last_day_of_month, $like_param);
    } else {
        // Regular signatories see documents only at their specific stage.
        $sql = $sql_base . $sql_where_stage . $sql_end;
        $like_param = $base_dept_role . '%';
        $deadline_stmt = $conn->prepare($sql);
        $deadline_stmt->bind_param("sssssiisis", $like_param, $first_day_of_month, $last_day_of_month, $base_dept_role, $base_dept_role, $is_head, $is_head, $base_dept_role, $my_stage_index, $like_param);
    }
} else {
    // Requestors see the deadlines for their own submitted documents.
    $sql = "SELECT v.voucher_code, v.document_title, v.arta_deadline, v.date_submitted as start_date, al.processing_days 
            FROM vouchers v 
            LEFT JOIN document_types dt ON v.doc_type_id = dt.id 
            LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id 
            LEFT JOIN arta_levels al ON al.level_name = COALESCE(vt.arta_level, dt.arta_level) 
            WHERE v.requestor_id = ? 
            AND v.status IN ('Pending Review', 'Processing', 'In Transit') 
            AND v.arta_deadline IS NOT NULL 
            AND (v.arta_deadline >= ? AND v.date_submitted <= ?)";
    $deadline_stmt = $conn->prepare($sql);
    $deadline_stmt->bind_param("iss", $my_user_id, $first_day_of_month, $last_day_of_month);
}

$deadline_stmt->execute();
$deadline_res = $deadline_stmt->get_result();
$documents_for_calendar = $deadline_res->fetch_all(MYSQLI_ASSOC);
$deadline_stmt->close();

// Process documents to create a highlight map for the calendar
$date_highlights = [];

foreach ($documents_for_calendar as $doc) {
    if (empty($doc['start_date']) || empty($doc['arta_deadline'])) {
        continue;
    }

    $start_date = new DateTime($doc['start_date']);
    $deadline_date = new DateTime($doc['arta_deadline']);
    // The period should include the end date, so we modify it to the next day for the interval.
    $period_end_date = (clone $deadline_date)->modify('+1 day');
    
    $period = new DatePeriod($start_date, new DateInterval('P1D'), $period_end_date);

    foreach ($period as $date) {
        $date_str = $date->format('Y-m-d');
        
        // COLOR LOGIC
        if ($date_str == $start_date->format('Y-m-d')) {
            $color = 'green'; // Day 1
        } elseif ($date_str == $deadline_date->format('Y-m-d')) {
            $color = 'red'; // FINAL DAY (deadline)
        } else {
            $color = 'orange'; // In progress
        }

        // Initialize if not exists
        if (!isset($date_highlights[$date_str])) {
            $date_highlights[$date_str] = [
                'color' => '',
                'documents' => []
            ];
        }

        // PRIORITY: red > orange > green
        $existing = $date_highlights[$date_str]['color'];
        if (
            $color === 'red' ||
            ($color === 'orange' && $existing !== 'red') ||
            ($color === 'green' && empty($existing))
        ) {
            $date_highlights[$date_str]['color'] = $color;
        }

        // STORE DOCUMENT (NO DUPLICATES)
        $voucher_code = $doc['voucher_code'] ?? null;
        if ($voucher_code) {
            $date_highlights[$date_str]['documents'][$voucher_code] = $doc;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - NAAP Document Tracking System</title>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo filemtime('sidebar.css'); ?>">
    <link rel="stylesheet" href="home.css?v=<?php echo filemtime('home.css'); ?>">
    <style>
        .user-name .head-badge {
            font-size: 0.8rem;
            background: var(--naap-gold);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: bold;
            vertical-align: middle;
            margin-left: 10px;
        }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="header">
        <h1>NAAP Document Tracking System</h1>
        <p><?php echo htmlspecialchars($my_role); ?> Station | Current Page: Home</p>
    </div>

    <div class="user-info-card">
        <div class="user-profile-header">
            <div>
                <h2 class="user-name">
                    <?php echo htmlspecialchars($my_full_name); ?>
                    <?php if ($_SESSION['is_head'] ?? 0): ?>
                        <span class="head-badge">★ Department Head</span>
                    <?php endif; ?>
                </h2>
                <div class="user-details-grid">
                    <div>
                        <span class="detail-label">Email Address</span>
                        <span class="detail-value"><?php echo htmlspecialchars($my_email); ?></span>
                    </div>
                    <div>
                        <span class="detail-label">Institutional Role</span>
                        <span class="detail-value"><?php echo htmlspecialchars($my_role); ?></span>
                    </div>
                    <div>
                        <span class="detail-label">Job Title</span>
                        <span class="detail-value"><?php echo htmlspecialchars($my_job_title); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="user-status">
            <span>Availability Status</span>
            <strong>🟢 Online / Active</strong>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="card">
            <h3>My Submissions</h3>
            <div class="value"><?php echo $total_submitted_by_me; ?></div>
        </div>
        <div class="card <?php if (!empty($pending_breakdown)) echo 'metric-card-hover'; ?>">
            <h3><?php echo (!empty($pending_breakdown)) ? 'Department Pending Actions' : 'My Pending Action'; ?></h3>
            <div class="value" style="color: #ffa500;"><?php echo $pending_my_action; ?></div>
            <?php if (!empty($pending_breakdown)): ?>
                <div class="metric-tooltip">
                    <div class="tooltip-header">Pending Items by User</div>
                    <?php if (empty($pending_breakdown)): ?>
                        <div class="tooltip-item"><span>No pending items in the department.</span></div>
                    <?php else: ?>
                        <?php foreach ($pending_breakdown as $item): ?>
                            <div class="tooltip-item">
                                <span><?php echo htmlspecialchars($item['full_name']); ?></span>
                                <span class="tooltip-count"><?php echo $item['user_pending_count']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($is_signatory): ?>
        <div class="card <?php if (!empty($en_route_breakdown)) echo 'metric-card-hover'; ?>">
            <h3>En Route to Me</h3>
            <div class="value" style="color: #3b82f6;"><?php echo $en_route_to_me; ?></div>
            <?php if (!empty($en_route_breakdown)): ?>
                <div class="metric-tooltip">
                    <div class="tooltip-header">Documents En Route</div>
                    <?php foreach ($en_route_breakdown as $item): ?>
                        <div class="tooltip-item-detailed">
                            <div class="tooltip-doc-header">
                                <a href="track.php?code=<?php echo urlencode($item['voucher_code']); ?>" target="_blank">
                                    <strong><?php echo htmlspecialchars($item['voucher_code']); ?></strong>
                                </a>
                                <span><?php echo htmlspecialchars($item['document_title']); ?></span>
                            </div>
                            <div class="tooltip-doc-body">
                                <strong>From:</strong> <?php echo htmlspecialchars($item['requestor_name']); ?>
                            </div>
                            <?php
                                $workflow = json_decode($item['custom_workflow'] ?? '[]', true);
                                $current_stage_idx = (int)$item['current_stage_index'];
                                $previous_steps = array_slice($workflow, 0, $current_stage_idx - 1);
                                if (!empty($previous_steps)):
                            ?>
                                <div class="tooltip-doc-path">
                                    <strong>Path Taken:</strong>
                                    <span><?php echo implode(' &rarr; ', array_map('htmlspecialchars', $previous_steps)); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="calendar-card">
        <?php if ($is_signatory): ?>
            <h3 style="margin-top:0; color: var(--naap-navy);">ARTA Deadline Calendar</h3>
            <p style="font-size: 0.85rem; color: #666; margin-top: -5px; margin-bottom: 20px;">Days with deadlines for documents in your queue are highlighted.</p>
        <?php else: ?>
            <h3 style="margin-top:0; color: var(--naap-navy);">My Document Deadlines</h3>
            <p style="font-size: 0.85rem; color: #666; margin-top: -5px; margin-bottom: 20px;">Days with deadlines for your submitted documents are highlighted.</p>
        <?php endif; ?>

        <?php echo build_calendar($month, $year, $date_highlights); ?>

        <div class="calendar-legend">
            <div class="legend-item"><span class="color-box green"></span> On-Time Start</div>
            <div class="legend-item"><span class="color-box orange"></span> In Progress</div>
            <div class="legend-item"><span class="color-box red"></span> Deadline</div>
        </div>
    </div>

    <div class="info-section">
        <strong>⚠️ System Rule:</strong> All submissions are tracked in real-time. Failure to process documents within the department mandate will be flagged in reports.
    </div>
</div>

</body>
<?php
$conn->close();
?>
</html>