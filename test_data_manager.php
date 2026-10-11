<?php
session_start();
if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'Management Information System Office') {
    header("Location: home.php");
    exit();
}

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
require_once 'db_connect.php';

if (empty($_SESSION['test_data_manager_csrf'])) {
    $_SESSION['test_data_manager_csrf'] = bin2hex(random_bytes(32));
}

$notice = $_SESSION['test_data_manager_notice'] ?? null;
$created_test_code = $notice['track_code'] ?? null;
unset($_SESSION['test_data_manager_notice']);

function test_data_manager_count($conn, $table, $column, $id) {
    $allowed_tables = ['vouchers', 'vouchers_archive'];
    $allowed_columns = ['doc_type_id', 'voucher_type_id', 'requestor_id'];
    if (!in_array($table, $allowed_tables, true) || !in_array($column, $allowed_columns, true)) {
        throw new RuntimeException('Invalid reference check.');
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS row_count FROM `$table` WHERE `$column` = ?");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare the reference check.');
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not check record references.');
    }
    $count = (int)$stmt->get_result()->fetch_assoc()['row_count'];
    $stmt->close();
    return $count;
}

function test_data_manager_delete_record($conn, $entity, $record_id) {
    if ($entity === 'notification') {
        if (!ctype_digit($record_id) || (int)$record_id < 1) {
            throw new RuntimeException('Invalid notification ID.');
        }
        $notification_id = (int)$record_id;
        $stmt = $conn->prepare('DELETE FROM notifications WHERE id = ?');
        $stmt->bind_param('i', $notification_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Could not delete notification ' . $notification_id . '.');
        }
        $stmt->close();
        return;
    }

    if ($entity === 'voucher') {
        $stmt = $conn->prepare('SELECT voucher_code FROM vouchers WHERE voucher_code = ? FOR UPDATE');
        $stmt->bind_param('s', $record_id);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$found) {
            throw new RuntimeException('Live voucher ' . $record_id . ' was not found.');
        }

        $stmt = $conn->prepare('DELETE FROM notifications WHERE voucher_code = ?');
        $stmt->bind_param('s', $record_id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Could not delete notifications for voucher ' . $record_id . '.');
        }
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM audit_logs WHERE voucher_code = ?');
        $stmt->bind_param('s', $record_id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Could not delete audit history for voucher ' . $record_id . '.');
        }
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM vouchers WHERE voucher_code = ?');
        $stmt->bind_param('s', $record_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Could not delete live voucher ' . $record_id . '.');
        }
        $stmt->close();
        return;
    }

    if ($entity === 'document_type' || $entity === 'voucher_type') {
        if (!ctype_digit($record_id) || (int)$record_id < 1) {
            throw new RuntimeException('Invalid type ID.');
        }
        $type_id = (int)$record_id;
        $table = $entity === 'document_type' ? 'document_types' : 'voucher_types';
        $reference_column = $entity === 'document_type' ? 'doc_type_id' : 'voucher_type_id';
        $display_name = $entity === 'document_type' ? 'Document type' : 'Disbursement voucher type';

        if ($entity === 'document_type') {
            $stmt = $conn->prepare('SELECT is_system_default FROM document_types WHERE id = ? FOR UPDATE');
        } else {
            $stmt = $conn->prepare('SELECT id FROM voucher_types WHERE id = ? FOR UPDATE');
        }
        $stmt->bind_param('i', $type_id);
        $stmt->execute();
        $type_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$type_row) {
            throw new RuntimeException($display_name . ' ' . $type_id . ' was not found.');
        }
        if ($entity === 'document_type' && !empty($type_row['is_system_default'])) {
            throw new RuntimeException('System-default document types cannot be deleted.');
        }

        $references = test_data_manager_count($conn, 'vouchers', $reference_column, $type_id)
            + test_data_manager_count($conn, 'vouchers_archive', $reference_column, $type_id);
        if ($references > 0) {
            throw new RuntimeException($display_name . ' ' . $type_id . ' is still referenced by ' . $references . ' live or archived voucher(s).');
        }

        $stmt = $conn->prepare("DELETE FROM `$table` WHERE id = ?");
        $stmt->bind_param('i', $type_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Could not delete ' . strtolower($display_name) . ' ' . $type_id . '.');
        }
        $stmt->close();
        return;
    }

    if ($entity === 'user') {
        if (!ctype_digit($record_id) || (int)$record_id < 1) {
            throw new RuntimeException('Invalid user ID.');
        }
        $user_id = (int)$record_id;
        if ($user_id === (int)($_SESSION['user_id'] ?? 0)) {
            throw new RuntimeException('You cannot delete the account currently being used.');
        }

        $stmt = $conn->prepare('SELECT role FROM users WHERE user_id = ? FOR UPDATE');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $user_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user_row) {
            throw new RuntimeException('User ' . $user_id . ' was not found.');
        }
        if ($user_row['role'] === 'Management Information System Office') {
            throw new RuntimeException('MIS administrator accounts cannot be deleted here.');
        }

        $references = test_data_manager_count($conn, 'vouchers', 'requestor_id', $user_id)
            + test_data_manager_count($conn, 'vouchers_archive', 'requestor_id', $user_id);
        if ($references > 0) {
            throw new RuntimeException('User ' . $user_id . ' is linked to ' . $references . ' live or archived voucher(s).');
        }

        $stmt = $conn->prepare('UPDATE audit_logs_archive SET processed_by_user_id = NULL WHERE processed_by_user_id = ?');
        $stmt->bind_param('i', $user_id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Could not preserve archived audit records for user ' . $user_id . '.');
        }
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM users WHERE user_id = ?');
        $stmt->bind_param('i', $user_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Could not delete user ' . $user_id . '.');
        }
        $stmt->close();
        return;
    }

    throw new RuntimeException('Unknown record type.');
}

function test_data_manager_create_document($conn, $post) {
    $document_type_id = filter_var($post['document_type_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$document_type_id || $document_type_id < 1) {
        throw new RuntimeException('Select a valid document type.');
    }

    $title = trim((string)($post['document_title'] ?? ''));
    if ($title === '' || strlen($title) > 230) {
        throw new RuntimeException('Enter a document title of no more than 230 characters.');
    }
    if (stripos($title, '[TEST]') !== 0) {
        $title = '[TEST] ' . $title;
    }

    $submitted_date = (string)($post['submitted_date'] ?? '');
    $deadline = (string)($post['arta_deadline'] ?? '');
    $parse_date = static function ($date) {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    };
    if (!$parse_date($submitted_date) || $submitted_date > date('Y-m-d')) {
        throw new RuntimeException('Choose a valid document date that is not in the future.');
    }
    if (!$parse_date($deadline)) {
        throw new RuntimeException('Choose a valid ARTA deadline.');
    }

    $stmt = $conn->prepare('SELECT name, workflow_type FROM document_types WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $document_type_id);
    $stmt->execute();
    $type_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$type_row) {
        throw new RuntimeException('The selected document type was not found.');
    }

    $available_routes = [];
    $departments_result = $conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1");
    while ($department = $departments_result->fetch_assoc()) {
        $name = $department['name'];
        $available_routes[] = $name;
        $available_routes[] = $name . ' (Head)';
    }
    $available_routes[] = 'Department Head';
    $account_result = $conn->query('SELECT user_id FROM users');
    while ($account = $account_result->fetch_assoc()) {
        $available_routes[] = 'ACCOUNT:' . (int)$account['user_id'];
    }

    $route_steps = $post['route_steps'] ?? [];
    if (!is_array($route_steps) || count($route_steps) < 1 || count($route_steps) > 30) {
        throw new RuntimeException('Add between 1 and 30 routing stages.');
    }
    $route_steps = array_values(array_map(static function ($step) {
        return is_scalar($step) ? trim((string)$step) : '';
    }, $route_steps));
    foreach ($route_steps as $step) {
        if ($step === '' || !in_array($step, $available_routes, true)) {
            throw new RuntimeException('The routing sequence contains an invalid or unavailable stage.');
        }
    }

    $workflow_type = ($type_row['workflow_type'] === 'Transfer') ? 'Transfer' : 'Approval';
    if ($workflow_type === 'Transfer' && count($route_steps) !== 1) {
        throw new RuntimeException('Transfer document types require exactly one routing destination.');
    }

    $stage_index = filter_var($post['current_stage_index'] ?? null, FILTER_VALIDATE_INT);
    if (!$stage_index || $stage_index < 1 || $stage_index > count($route_steps)) {
        throw new RuntimeException('Select a current routing stage that exists in the route.');
    }

    $status = (string)($post['status'] ?? '');
    $valid_statuses = $workflow_type === 'Transfer'
        ? ['In Transit']
        : ['Pending Review', 'Processing'];
    if (!in_array($status, $valid_statuses, true)) {
        throw new RuntimeException('Choose a status that matches the document type workflow.');
    }

    $requestor_id = (int)($_SESSION['user_id'] ?? 0);
    if ($requestor_id < 1) {
        throw new RuntimeException('Could not identify the administrator creating this test document.');
    }
    $custom_workflow = json_encode($route_steps);
    if ($custom_workflow === false) {
        throw new RuntimeException('Could not encode the routing sequence.');
    }

    $voucher_code = 'TEST-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $submitted_at = $submitted_date . ' 12:00:00';
    $stmt = $conn->prepare("
        INSERT INTO vouchers
            (voucher_code, requestor_id, document_title, doc_type_id, voucher_type_id,
             date_submitted, status, workflow_type, current_stage_index, custom_workflow, arta_deadline)
        VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'sisisssiss',
        $voucher_code,
        $requestor_id,
        $title,
        $document_type_id,
        $submitted_at,
        $status,
        $workflow_type,
        $stage_index,
        $custom_workflow,
        $deadline
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not create the test document. Check the application error log.');
    }
    $stmt->close();
    return $voucher_code;
}

function test_data_manager_create_hr_dss_demo($conn) {
    $department = 'Human Resource Management Services Division';

    $stmt = $conn->prepare('SELECT id FROM departments WHERE name = ? AND is_signatory = 1 AND is_active = 1 LIMIT 1 FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare the HR department check.');
    }
    $stmt->bind_param('s', $department);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not verify the HR department.');
    }
    $department_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$department_row) {
        throw new RuntimeException('The active HR division signatory station was not found.');
    }

    $stmt = $conn->prepare("SELECT user_id FROM users WHERE role = ? AND is_head = 1 ORDER BY user_id LIMIT 1 FOR UPDATE");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare the HR Head check.');
    }
    $stmt->bind_param('s', $department);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not verify the HR Head account.');
    }
    $hr_head = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$hr_head) {
        throw new RuntimeException('An HR Head account is required to create the DSS demonstration data.');
    }
    $hr_head_id = (int)$hr_head['user_id'];

    $hr_document_type_id = null;
    $type_result = $conn->query("SELECT id FROM document_types WHERE category = 'HR' AND is_active = 1 ORDER BY id LIMIT 1");
    if (!$type_result) {
        throw new RuntimeException('Could not check for an active HR document type.');
    }
    if ($type_result && $type_row = $type_result->fetch_assoc()) {
        $hr_document_type_id = (int)$type_row['id'];
    }
    $type_result->close();

    $today = new DateTimeImmutable('today');
    $deadline = $today->modify('-5 days');
    $received_at = $deadline->modify('-1 day')->format('Y-m-d') . ' 09:00:00';
    $submitted_at = $deadline->modify('-3 days')->format('Y-m-d') . ' 09:00:00';
    $route = json_encode([$department . ' (Head)']);
    if ($route === false) {
        throw new RuntimeException('Could not prepare the HR DSS demonstration route.');
    }
    $requestor_id = (int)($_SESSION['user_id'] ?? 0);
    if ($requestor_id < 1) {
        throw new RuntimeException('Could not identify the administrator creating the HR DSS demonstration data.');
    }
    $deadline_date = $deadline->format('Y-m-d');

    $voucher_stmt = $conn->prepare("
        INSERT INTO vouchers
            (voucher_code, requestor_id, document_title, doc_type_id, voucher_type_id,
             date_submitted, status, workflow_type, current_stage_index, custom_workflow, arta_deadline)
        VALUES (?, ?, ?, ?, NULL, ?, 'Pending Review', 'Approval', 1, ?, ?)
    ");
    if (!$voucher_stmt) {
        throw new RuntimeException('Could not prepare the HR DSS demonstration documents.');
    }

    $log_stmt = $conn->prepare("
        INSERT INTO audit_logs
            (voucher_code, department, action_taken, remarks, processed_by_user_id, created_at)
        VALUES (?, ?, 'Scan-to-Receive', ?, ?, ?)
    ");
    if (!$log_stmt) {
        $voucher_stmt->close();
        throw new RuntimeException('Could not prepare the HR DSS demonstration receipt history.');
    }

    $titles = [
        '[TEST] DSS Demo - HR Leave Application',
        '[TEST] DSS Demo - Personnel Action Form',
        '[TEST] DSS Demo - Staff Training Request'
    ];
    $created_codes = [];
    foreach ($titles as $title) {
        $voucher_code = 'TEST-DSS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $doc_type_id = $hr_document_type_id;
        $voucher_stmt->bind_param(
            'sisisss',
            $voucher_code,
            $requestor_id,
            $title,
            $doc_type_id,
            $submitted_at,
            $route,
            $deadline_date
        );
        if (!$voucher_stmt->execute()) {
            $voucher_stmt->close();
            $log_stmt->close();
            throw new RuntimeException('Could not create an HR DSS demonstration document.');
        }

        $remarks = 'TEST DATA: intentionally overdue HR DSS demonstration item; received before the ARTA deadline and still pending.';
        $log_stmt->bind_param('sssis', $voucher_code, $department, $remarks, $hr_head_id, $received_at);
        if (!$log_stmt->execute()) {
            $voucher_stmt->close();
            $log_stmt->close();
            throw new RuntimeException('Could not create receipt history for an HR DSS demonstration document.');
        }
        $created_codes[] = $voucher_code;
    }

    $voucher_stmt->close();
    $log_stmt->close();
    return $created_codes;
}

function test_data_manager_add_business_days($date, $days, $holidays) {
    $current = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$current) {
        throw new RuntimeException('Could not calculate a simulated business-day date.');
    }

    $added = 0;
    while ($added < $days) {
        $current = $current->modify('+1 day');
        $weekday = (int)$current->format('N');
        if ($weekday < 6 && !isset($holidays[$current->format('Y-m-d')])) {
            $added++;
        }
    }
    return $current->format('Y-m-d');
}

function test_data_manager_create_simulated_20day_demo($conn) {
    $account_specs = [
        'accounting_head' => ['acct_head', 'Accounting Office'],
        'budget_head' => ['budgethead', 'Budget Office'],
        'cash_head' => ['disbursinghead', 'Cash Services – Collecting Office'],
        'cash_staff' => ['disbursingstaff', 'Cash Services – Collecting Office'],
        'cau_head' => ['Andrio Guda', 'Cultural Affairs Unit'],
        'cau_staff' => ['caustaff', 'Cultural Affairs Unit'],
        'hr_head' => ['hr_head', 'Human Resource Management Services Division']
    ];
    $accounts = [];
    $account_stmt = $conn->prepare('SELECT user_id, username, full_name, role FROM users WHERE username = ? AND role = ? LIMIT 1 FOR UPDATE');
    if (!$account_stmt) {
        throw new RuntimeException('Could not prepare the simulated account checks.');
    }
    foreach ($account_specs as $key => $spec) {
        $account_stmt->bind_param('ss', $spec[0], $spec[1]);
        if (!$account_stmt->execute()) {
            $account_stmt->close();
            throw new RuntimeException('Could not verify the configured test accounts.');
        }
        $account = $account_stmt->get_result()->fetch_assoc();
        if (!$account) {
            $account_stmt->close();
            throw new RuntimeException('The account "' . $spec[0] . '" is unavailable. No simulated records were created.');
        }
        $accounts[$key] = $account;
    }
    $account_stmt->close();

    $types = [];
    $type_result = $conn->query("
        SELECT id, name, arta_level
        FROM document_types
        WHERE is_active = 1 AND workflow_type = 'Approval'
          AND arta_level IN ('Simple', 'Complex', 'Highly Technical')
        ORDER BY arta_level, name
    ");
    if (!$type_result) {
        throw new RuntimeException('Could not load document types for the simulated dataset.');
    }
    while ($type = $type_result->fetch_assoc()) {
        $types[] = $type;
    }
    $type_result->close();
    if (empty($types)) {
        throw new RuntimeException('At least one active Simple or Complex approval document type is required.');
    }

    $holidays = [];
    $holiday_result = $conn->query('SELECT holiday_date FROM holidays');
    if (!$holiday_result) {
        throw new RuntimeException('Could not load holidays for the simulated business-day schedule.');
    }
    while ($holiday = $holiday_result->fetch_assoc()) {
        $holidays[$holiday['holiday_date']] = true;
    }
    $holiday_result->close();

    $last_day = new DateTimeImmutable('yesterday');
    while ((int)$last_day->format('N') >= 6 || isset($holidays[$last_day->format('Y-m-d')])) {
        $last_day = $last_day->modify('-1 day');
    }
    $submission_dates = [];
    $cursor = $last_day;
    while (count($submission_dates) < 20) {
        $date = $cursor->format('Y-m-d');
        if ((int)$cursor->format('N') < 6 && !isset($holidays[$date])) {
            $submission_dates[] = $date;
        }
        $cursor = $cursor->modify('-1 day');
    }
    $submission_dates = array_reverse($submission_dates);
    $period_start = $submission_dates[0];
    $period_end = end($submission_dates);
    $as_of_date = (new DateTimeImmutable('today'))->format('Y-m-d');

    $routes = [
        ['cau_staff', 'hr_head'],
        ['budget_head', 'accounting_head', 'cash_staff'],
        ['cau_head', 'budget_head', 'accounting_head'],
        ['accounting_head', 'cash_head'],
        ['hr_head', 'budget_head', 'cash_head'],
        ['cau_head', 'accounting_head', 'cash_staff']
    ];
    $requestor_keys = ['cau_head', 'cau_head', 'hr_head', 'cau_head', 'cau_staff', 'hr_head'];
    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    if ($current_user_id < 1) {
        throw new RuntimeException('Could not identify the administrator creating the simulated dataset.');
    }

    $voucher_stmt = $conn->prepare("
        INSERT INTO vouchers
            (voucher_code, requestor_id, document_title, doc_type_id, date_submitted,
             purpose, status, workflow_type, current_stage_index, custom_workflow, arta_deadline)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Approval', ?, ?, ?)
    ");
    $log_stmt = $conn->prepare("
        INSERT INTO audit_logs
            (voucher_code, department, action_taken, remarks, processed_by_user_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$voucher_stmt || !$log_stmt) {
        if ($voucher_stmt) {
            $voucher_stmt->close();
        }
        if ($log_stmt) {
            $log_stmt->close();
        }
        throw new RuntimeException('Could not prepare the simulated voucher and activity records.');
    }

    $run_id = date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $created_codes = [];
    $dataset_rows = [];
    try {
        foreach ($submission_dates as $index => $submitted_date) {
            $route_definitions = $routes[$index % count($routes)];
            $route_accounts = [];
            $route_steps = [];
            foreach ($route_definitions as $account_key) {
                $account = $accounts[$account_key];
                $route_accounts[] = $account;
                $route_steps[] = 'ACCOUNT:' . (int)$account['user_id'];
            }

            $desired_level = ($index % 10 === 0)
                ? 'Highly Technical'
                : (($index % 4 === 0) ? 'Complex' : 'Simple');
            $type_pool = array_values(array_filter($types, static function ($type) use ($desired_level) {
                return $type['arta_level'] === $desired_level;
            }));
            if (empty($type_pool)) {
                $type_pool = $types;
            }
            $type = $type_pool[$index % count($type_pool)];
            $deadline = calculateARTADeadline($submitted_date, $type['arta_level'], $conn);
            if (!$deadline) {
                throw new RuntimeException('ARTA deadline calculation is disabled or unavailable. Enable ARTA deadline rules before creating this dataset.');
            }

            $route_count = count($route_accounts);
            $scenario = $index % 7;
            $wants_late_decision = in_array($scenario, [0, 2, 5, 6], true);
            $is_return = $scenario === 2;
            $is_decline = $scenario === 6;
            $late_stage_index = $index % $route_count;
            $late_days = 1 + ($index % 2);
            $late_decision_date = test_data_manager_add_business_days($deadline, $late_days, $holidays);
            $late_final_date = test_data_manager_add_business_days($late_decision_date, $route_count - 1 - $late_stage_index, $holidays);
            $elapsed_business_days = count($submission_dates) - 1 - $index;
            $active_stage_index = min($route_count - 1, $elapsed_business_days);
            $active_received_date = test_data_manager_add_business_days($submitted_date, $active_stage_index, $holidays);
            $use_late_decision = $wants_late_decision
                && $deadline < $as_of_date
                && $late_final_date <= $period_end;
            $is_active = $scenario === 3 || ($wants_late_decision && !$use_late_decision);
            $is_late_active = $is_active && $deadline < $as_of_date && $active_received_date <= $deadline;

            $events = [];
            $processed_stages = 0;
            $status = 'Processing';
            $current_stage_index = $route_count;
            $outcome = 'In progress';
            $completion_date = null;

            if ($use_late_decision) {
                $processed_stages = $is_return || $is_decline ? $late_stage_index + 1 : $route_count;
                $status = $is_return ? 'Returned' : ($is_decline ? 'Rejected' : 'Ready for Release');
                $current_stage_index = $processed_stages;
                $outcome = $is_return ? 'Returned after deadline' : ($is_decline ? 'Declined after deadline' : 'Completed late');
                for ($stage_index = 0; $stage_index < $processed_stages; $stage_index++) {
                    if ($stage_index < $late_stage_index) {
                        $event_date = test_data_manager_add_business_days($submitted_date, $stage_index, $holidays);
                        $received_date = $event_date;
                    } elseif ($stage_index === $late_stage_index) {
                        $event_date = $late_decision_date;
                        $received_date = test_data_manager_add_business_days($submitted_date, $stage_index, $holidays);
                    } else {
                        $event_date = test_data_manager_add_business_days($late_decision_date, $stage_index - $late_stage_index, $holidays);
                        $received_date = $event_date;
                    }
                    $action = ($stage_index === $late_stage_index && $is_return)
                        ? 'RETURNED'
                        : (($stage_index === $late_stage_index && $is_decline) ? 'DECLINED' : 'Accepted');
                    $events[] = [
                        'stage_index' => $stage_index,
                        'received_at' => $received_date . ' 09:00:00',
                        'decision_at' => $event_date . ' 15:00:00',
                        'action' => $action
                    ];
                }
                $completion_date = end($events)['decision_at'];
            } elseif (!$is_active) {
                $final_date = test_data_manager_add_business_days($submitted_date, $route_count - 1, $holidays);
                if ($final_date <= $period_end && $final_date <= $deadline) {
                    $processed_stages = $route_count;
                    $status = 'Ready for Release';
                    $outcome = 'Completed on time';
                    for ($stage_index = 0; $stage_index < $route_count; $stage_index++) {
                        $event_date = test_data_manager_add_business_days($submitted_date, $stage_index, $holidays);
                        $events[] = [
                            'stage_index' => $stage_index,
                            'received_at' => $event_date . ' 09:00:00',
                            'decision_at' => $event_date . ' 15:00:00',
                            'action' => 'Accepted'
                        ];
                    }
                    $completion_date = $final_date . ' 15:00:00';
                } else {
                    $is_active = true;
                    $is_late_active = $deadline < $as_of_date && $active_received_date <= $deadline;
                    $outcome = $is_late_active ? 'Overdue and in progress' : 'In progress within deadline';
                }
            } else {
                $outcome = $is_late_active ? 'Overdue and in progress' : 'In progress within deadline';
            }

            if ($is_active) {
                $current_stage_index = $active_stage_index + 1;
                for ($stage_index = 0; $stage_index < $active_stage_index; $stage_index++) {
                    $event_date = test_data_manager_add_business_days($submitted_date, $stage_index, $holidays);
                    $events[] = [
                        'stage_index' => $stage_index,
                        'received_at' => $event_date . ' 09:00:00',
                        'decision_at' => $event_date . ' 15:00:00',
                        'action' => 'Accepted'
                    ];
                }
                $events[] = [
                    'stage_index' => $active_stage_index,
                    'received_at' => $active_received_date . ' 09:00:00',
                    'decision_at' => null,
                    'action' => null
                ];
            }

            $route_json = json_encode($route_steps);
            if ($route_json === false) {
                throw new RuntimeException('Could not encode a simulated routing sequence.');
            }
            $voucher_code = 'TEST-SIM20-' . $run_id . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
            $title = '[SIMULATED TEST] ' . $type['name'] . ' - Case ' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
            $purpose = 'SIMULATED TEST DATA ONLY — generated for a 20-business-day capstone system demonstration; not an actual NAAP transaction.';
            $submitted_at = $submitted_date . ' 08:30:00';
            $doc_type_id = (int)$type['id'];
            $requestor_id = (int)$accounts[$requestor_keys[$index % count($requestor_keys)]]['user_id'];
            $voucher_stmt->bind_param(
                'sisisssiss',
                $voucher_code,
                $requestor_id,
                $title,
                $doc_type_id,
                $submitted_at,
                $purpose,
                $status,
                $current_stage_index,
                $route_json,
                $deadline
            );
            if (!$voucher_stmt->execute()) {
                throw new RuntimeException('Could not create simulated document ' . ($index + 1) . '.');
            }
            $created_codes[] = $voucher_code;

            $activity_summary = [];
            foreach ($events as $event) {
                $actor = $route_accounts[$event['stage_index']];
                $department = $actor['role'];
                $remarks = 'SIMULATED TEST DATA ONLY — generated activity; not an actual user action.';
                $receive_action = 'Scan-to-Receive';
                $log_stmt->bind_param('ssssis', $voucher_code, $department, $receive_action, $remarks, $actor['user_id'], $event['received_at']);
                if (!$log_stmt->execute()) {
                    throw new RuntimeException('Could not create a simulated receipt activity record.');
                }
                $activity_summary[] = $event['received_at'] . ' received by ' . $actor['full_name'];

                if ($event['action'] !== null) {
                    $log_stmt->bind_param('ssssis', $voucher_code, $department, $event['action'], $remarks, $actor['user_id'], $event['decision_at']);
                    if (!$log_stmt->execute()) {
                        throw new RuntimeException('Could not create a simulated decision activity record.');
                    }
                    $activity_summary[] = $event['decision_at'] . ' ' . $event['action'] . ' by ' . $actor['full_name'];
                }
            }

            $late_days_value = 0;
            if ($completion_date !== null && $completion_date > ($deadline . ' 23:59:59')) {
                $deadline_date = new DateTimeImmutable($deadline);
                $decision_date = new DateTimeImmutable(substr($completion_date, 0, 10));
                $late_days_value = (int)$deadline_date->diff($decision_date)->days;
            } elseif ($is_late_active) {
                $deadline_date = new DateTimeImmutable($deadline);
                $today_date = new DateTimeImmutable($as_of_date);
                $late_days_value = max(1, (int)$deadline_date->diff($today_date)->days);
            }
            $dataset_rows[] = [
                'classification' => 'SIMULATED TEST DATA — NOT ACTUAL NAAP OPERATIONS',
                'tracking_id' => $voucher_code,
                'submission_date' => $submitted_date,
                'document_type' => $type['name'],
                'arta_level' => $type['arta_level'],
                'arta_deadline' => $deadline,
                'requesting_account' => $accounts[$requestor_keys[$index % count($requestor_keys)]]['full_name'],
                'routing_accounts' => implode(' > ', array_map(static function ($account) {
                    return $account['full_name'] . ' (' . $account['role'] . ')';
                }, $route_accounts)),
                'system_status' => $status,
                'test_case_result' => $outcome,
                'last_decision_or_activity' => $completion_date ?: end($events)['received_at'],
                'end_to_end_days_after_deadline' => $late_days_value,
                'activity_log_summary' => implode(' | ', $activity_summary)
            ];
        }
    } finally {
        $voucher_stmt->close();
        $log_stmt->close();
    }

    return [
        'tracking_ids' => $created_codes,
        'rows' => $dataset_rows,
        'period_start' => $period_start,
        'period_end' => $period_end
    ];
}

if (isset($_GET['download_simulated_dataset'])) {
    $dataset = $_SESSION['test_data_manager_simulated_dataset'] ?? null;
    if (!is_array($dataset) || empty($dataset['rows'])) {
        http_response_code(404);
        exit('No simulated dataset is available in this session. Generate it from the test-data manager first.');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="simulated_20_business_day_test_data.csv"');
    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new RuntimeException('Could not open the simulated dataset download stream.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['DATA CLASSIFICATION', 'SIMULATED TEST DATA — NOT ACTUAL NAAP OPERATIONS']);
    fputcsv($output, ['TEST PERIOD', $dataset['period_start'] . ' through ' . $dataset['period_end'] . ' (20 business days)']);
    fputcsv($output, []);
    fputcsv($output, array_keys($dataset['rows'][0]));
    foreach ($dataset['rows'] as $row) {
        fputcsv($output, array_values($row));
    }
    fclose($output);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!is_string($csrf_token) || !hash_equals($_SESSION['test_data_manager_csrf'], $csrf_token)) {
        $_SESSION['test_data_manager_notice'] = ['type' => 'error', 'message' => 'The request expired. Reload the page and try again.'];
        header('Location: test_data_manager.php');
        exit();
    }

    $transaction_started = false;
    try {
        if (($_POST['action'] ?? '') === 'create_simulated_20day_demo') {
            $conn->begin_transaction();
            $transaction_started = true;
            $dataset = test_data_manager_create_simulated_20day_demo($conn);
            $conn->commit();
            $transaction_started = false;
            $_SESSION['test_data_manager_simulated_dataset'] = [
                'rows' => $dataset['rows'],
                'period_start' => $dataset['period_start'],
                'period_end' => $dataset['period_end']
            ];
            $_SESSION['test_data_manager_notice'] = [
                'type' => 'success',
                'message' => 'Created 20 clearly labeled simulated test documents for ' . $dataset['period_start'] . ' through ' . $dataset['period_end'] . '. Activity is marked as generated test data and is not actual account activity.',
                'simulated_dataset_available' => true
            ];
            header('Location: test_data_manager.php');
            exit();
        }

        if (($_POST['action'] ?? '') === 'create_test_document') {
            $conn->begin_transaction();
            $transaction_started = true;
            $voucher_code = test_data_manager_create_document($conn, $_POST);
            $conn->commit();
            $transaction_started = false;
            $_SESSION['test_data_manager_notice'] = [
                'type' => 'success',
                'message' => 'Test document created as ' . $voucher_code . '. No workflow actions or notifications were generated.',
                'track_code' => $voucher_code
            ];
            header('Location: test_data_manager.php');
            exit();
        }

        if (($_POST['action'] ?? '') === 'create_hr_dss_demo') {
            $conn->begin_transaction();
            $transaction_started = true;
            $created_codes = test_data_manager_create_hr_dss_demo($conn);
            $conn->commit();
            $transaction_started = false;
            $_SESSION['test_data_manager_notice'] = [
                'type' => 'success',
                'message' => 'Created 3 overdue HR DSS demo documents: ' . implode(', ', $created_codes) . '. Delete these TEST-DSS vouchers from Live vouchers to remove their records and audit history.'
            ];
            header('Location: test_data_manager.php');
            exit();
        }

        $entity = $_POST['entity'] ?? '';
        $record_ids = $_POST['record_ids'] ?? [];
        if (!is_array($record_ids) || count($record_ids) < 1 || count($record_ids) > 200) {
            throw new RuntimeException('Select between 1 and 200 records to delete.');
        }

        $record_ids = array_values(array_unique(array_map(static function ($id) {
            return is_scalar($id) ? trim((string)$id) : '';
        }, $record_ids)));
        if (in_array('', $record_ids, true)) {
            throw new RuntimeException('One or more selected record IDs are invalid.');
        }

        $conn->begin_transaction();
        $transaction_started = true;
        foreach ($record_ids as $record_id) {
            test_data_manager_delete_record($conn, $entity, $record_id);
        }
        $conn->commit();
        $transaction_started = false;
        $_SESSION['test_data_manager_notice'] = [
            'type' => 'success',
            'message' => count($record_ids) . ' selected ' . ($entity === 'voucher'
                ? 'voucher(s)'
                : ($entity === 'notification' ? 'notification(s)' : 'record(s)')) . ' deleted successfully.'
        ];
    } catch (Throwable $e) {
        if ($transaction_started) {
            $conn->rollback();
        }
        error_log('Test data manager operation failed: ' . $e->getMessage());
        $error_message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'The database operation failed. Check the application error log.';
        $_SESSION['test_data_manager_notice'] = ['type' => 'error', 'message' => $error_message];
    }

    header('Location: test_data_manager.php');
    exit();
}

$vouchers = [];
$document_types = [];
$voucher_types = [];
$users = [];
$notifications = [];
$active_document_types = [];
$route_options = [];
$document_type_defaults = [];
$load_error = '';

try {
    $result = $conn->query("
        SELECT id, name, workflow_type, default_workflow, is_active
        FROM document_types
        ORDER BY name
    ");
    while ($row = $result->fetch_assoc()) {
        $active_document_types[] = $row;
        $default_steps = json_decode($row['default_workflow'] ?? '', true);
        $document_type_defaults[(string)$row['id']] = is_array($default_steps) ? $default_steps : [];
    }

    $result = $conn->query("
        SELECT name FROM departments
        WHERE is_signatory = 1 AND is_active = 1
        ORDER BY name
    ");
    while ($row = $result->fetch_assoc()) {
        $route_options[] = ['value' => $row['name'], 'label' => $row['name']];
        $route_options[] = ['value' => $row['name'] . ' (Head)', 'label' => $row['name'] . ' (Head)'];
    }
    $route_options[] = ['value' => 'Department Head', 'label' => 'Department Head (of requestor)'];

    $result = $conn->query('SELECT user_id, full_name, username, role FROM users ORDER BY full_name');
    while ($row = $result->fetch_assoc()) {
        $route_options[] = [
            'value' => 'ACCOUNT:' . (int)$row['user_id'],
            'label' => 'Account: ' . $row['full_name'] . ' (' . $row['username'] . ') - ' . $row['role']
        ];
    }

    $result = $conn->query("
        SELECT v.voucher_code, v.document_title, v.status, v.date_submitted, u.full_name AS requestor_name
        FROM vouchers v
        LEFT JOIN users u ON u.user_id = v.requestor_id
        ORDER BY (v.voucher_code LIKE 'TEST-%') DESC, v.date_submitted DESC
        LIMIT 200
    ");
    while ($row = $result->fetch_assoc()) {
        $vouchers[] = $row;
    }

    $result = $conn->query("
        SELECT dt.id, dt.name, dt.is_active, dt.is_system_default,
               (SELECT COUNT(*) FROM vouchers v WHERE v.doc_type_id = dt.id) AS live_references,
               (SELECT COUNT(*) FROM vouchers_archive va WHERE va.doc_type_id = dt.id) AS archived_references
        FROM document_types dt
        ORDER BY dt.name
    ");
    while ($row = $result->fetch_assoc()) {
        $document_types[] = $row;
    }

    $result = $conn->query("
        SELECT vt.id, vt.name, vt.is_active,
               (SELECT COUNT(*) FROM vouchers v WHERE v.voucher_type_id = vt.id) AS live_references,
               (SELECT COUNT(*) FROM vouchers_archive va WHERE va.voucher_type_id = vt.id) AS archived_references
        FROM voucher_types vt
        ORDER BY vt.name
    ");
    while ($row = $result->fetch_assoc()) {
        $voucher_types[] = $row;
    }

    $result = $conn->query("
        SELECT u.user_id, u.username, u.full_name, u.role,
               (SELECT COUNT(*) FROM vouchers v WHERE v.requestor_id = u.user_id) AS live_vouchers,
               (SELECT COUNT(*) FROM vouchers_archive va WHERE va.requestor_id = u.user_id) AS archived_vouchers
        FROM users u
        ORDER BY u.full_name
    ");
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    $result = $conn->query("
        SELECT n.id, n.user_id, n.voucher_code, n.message, n.link, n.is_read, n.created_at,
               u.full_name AS recipient_name
        FROM notifications n
        LEFT JOIN users u ON u.user_id = n.user_id
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT 200
    ");
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
} catch (Throwable $e) {
    error_log('Test data manager load failed: ' . $e->getMessage());
    $load_error = 'Records could not be loaded. Check the application error log.';
}

$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Temporary Test Data Manager</title>
    <link rel="stylesheet" href="sidebar.css">
    <style>
        body { margin: 0; background: #f1f5f9; color: #1e293b; font-family: 'Segoe UI', sans-serif; }
        .main-content { max-width: 1400px; margin: 0 auto; padding: 30px; }
        .page-header { border-bottom: 3px solid #f59e0b; margin-bottom: 20px; padding-bottom: 12px; }
        .page-header h1 { color: #1e3a8a; margin: 0; }
        .warning, .notice { padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; }
        .warning { background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; }
        .notice.success { background: #dcfce7; color: #166534; }
        .notice.error { background: #fee2e2; color: #991b1b; }
        .record-section { background: white; padding: 20px; border-radius: 10px; margin: 20px 0; box-shadow: 0 3px 12px rgba(0,0,0,.06); overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; min-width: 700px; }
        th, td { padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { background: #f8fafc; color: #1e3a8a; }
        .delete-button { background: #dc2626; color: white; border: 0; border-radius: 5px; padding: 8px 12px; cursor: pointer; }
        .delete-button:disabled { background: #9ca3af; cursor: not-allowed; }
        .bulk-actions { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .select-record { width: 18px; height: 18px; }
        .test-document-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .test-document-form label { display: block; margin-bottom: 6px; font-weight: 600; color: #334155; }
        .test-document-form input, .test-document-form select { box-sizing: border-box; width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; }
        .test-document-form .full-width { grid-column: 1 / -1; }
        .route-list { display: flex; flex-direction: column; gap: 8px; margin: 10px 0; padding: 0; list-style: none; }
        .route-row { display: flex; gap: 8px; align-items: center; }
        .route-row select { flex: 1; }
        .route-row button, .secondary-button { border: 0; border-radius: 5px; padding: 9px 12px; background: #1e3a8a; color: #fff; cursor: pointer; }
        .secondary-button { background: #64748b; }
        .form-submit { background: #1e3a8a; color: white; border: 0; border-radius: 6px; padding: 11px 16px; cursor: pointer; font-weight: 700; }
        .inline-controls { display: flex; align-items: end; gap: 8px; }
        .muted { color: #64748b; }
        @media (max-width: 768px) { .main-content { padding: 24px 12px; } .test-document-form { grid-template-columns: 1fr; } .test-document-form .full-width { grid-column: auto; } }
    </style>
</head>
<body>
<div class="main-content">
    <header class="page-header">
        <h1>Temporary Test Data Manager</h1>
        <p class="muted">Direct-URL, MIS-admin-only maintenance page; no sidebar link is provided.</p>
    </header>

    <div class="warning">
        <strong>Use only in a test environment.</strong> Deletions are permanent. Test documents are prefixed with [TEST]. The HR DSS demo action below creates three intentionally overdue documents assigned to the HR Head; delete the generated TEST-DSS vouchers from Live vouchers when finished. Other test documents created here do not generate workflow actions or notifications. The deadline processor can mark overdue live documents Lapsed and issue normal alerts. Up to 200 live vouchers are listed (test documents first), and the 200 newest notifications are shown; archived vouchers are not shown or deleted.
    </div>

    <?php if ($notice): ?>
        <div class="notice <?php echo $escape($notice['type']); ?>"><?php echo $escape($notice['message']); ?></div>
        <?php if (!empty($notice['simulated_dataset_available'])): ?>
            <p><a href="test_data_manager.php?download_simulated_dataset=1">Download paper-ready simulated dataset (CSV)</a></p>
        <?php endif; ?>
        <?php if ($created_test_code): ?>
            <p><a href="track.php?track_id=<?php echo urlencode($created_test_code); ?>">Open this test document’s tracking page</a></p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($load_error !== ''): ?>
        <div class="notice error"><?php echo $escape($load_error); ?></div>
    <?php endif; ?>

    <section class="record-section">
        <h2>20-business-day simulated workflow test</h2>
        <p class="muted"><strong>Simulation only—not research observations or actual user activity.</strong> Creates 20 documents dated across 20 recent business days and realistic, backdated receipt/decision logs using the listed head and staff accounts. It uses available Simple, Complex, and Highly Technical document types. Scenarios include on-time completion, late approval, return, rejection, and documents still in progress. Every generated document and activity remark is explicitly labeled simulated. After creation, download a CSV suitable for a clearly identified simulated test-case appendix.</p>
        <form method="POST" onsubmit="return confirm('Create 20 SIMULATED test documents and generated activity records attributed to the configured accounts? Use only a test database; these are not actual NAAP transactions.');">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($_SESSION['test_data_manager_csrf']); ?>">
            <input type="hidden" name="action" value="create_simulated_20day_demo">
            <button class="form-submit" type="submit">Generate 20-day simulated test data</button>
        </form>
        <?php if (!empty($_SESSION['test_data_manager_simulated_dataset']['rows'])): ?>
            <p><a href="test_data_manager.php?download_simulated_dataset=1">Download the latest simulated dataset CSV</a></p>
        <?php endif; ?>
    </section>

    <section class="record-section">
        <h2>HR Head Late Processing DSS demo</h2>
        <p class="muted">Creates three clearly labeled, active test documents that were received by the HR Head before an ARTA deadline five days ago and remain pending. They will trigger the DSS three-document alert threshold for both the HR office and assigned account.</p>
        <form method="POST" onsubmit="return confirm('Create three overdue TEST-DSS documents for the HR Head? Delete the TEST-DSS vouchers afterward to remove them.');">
            <input type="hidden" name="csrf_token" value="<?php echo $escape($_SESSION['test_data_manager_csrf']); ?>">
            <input type="hidden" name="action" value="create_hr_dss_demo">
            <button class="form-submit" type="submit">Create HR DSS demo data</button>
        </form>
    </section>

    <section class="record-section">
        <h2>Create a test document</h2>
        <?php if (empty($active_document_types) || empty($route_options)): ?>
            <p class="muted">Document types or routing destinations are unavailable.</p>
        <?php else: ?>
            <form method="POST" id="create-test-document-form" class="test-document-form">
                <input type="hidden" name="csrf_token" value="<?php echo $escape($_SESSION['test_data_manager_csrf']); ?>">
                <input type="hidden" name="action" value="create_test_document">
                <div>
                    <label for="document-title">Document title</label>
                    <input id="document-title" name="document_title" type="text" maxlength="230" required placeholder="Example: ARTA Overdue Demo">
                </div>
                <div>
                    <label for="document-type">Document type</label>
                    <select id="document-type" name="document_type_id" required>
                        <option value="">Select a document type</option>
                        <?php foreach ($active_document_types as $type): ?>
                            <option value="<?php echo $escape($type['id']); ?>" data-workflow-type="<?php echo $escape($type['workflow_type']); ?>"><?php echo $escape($type['name']); ?> (<?php echo $escape($type['workflow_type']); ?><?php echo empty($type['is_active']) ? ', inactive' : ''; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="submitted-date">Document date</label>
                    <input id="submitted-date" name="submitted_date" type="date" max="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="inline-controls">
                    <div style="flex: 1">
                        <label for="arta-deadline">ARTA deadline</label>
                        <input id="arta-deadline" name="arta_deadline" type="date" required>
                    </div>
                    <button class="secondary-button" type="button" id="set-overdue-deadline">Set to yesterday</button>
                </div>
                <div>
                    <label for="document-status">Current status</label>
                    <select id="document-status" name="status" required>
                        <option value="Pending Review">Pending Review</option>
                        <option value="Processing">Processing</option>
                        <option value="In Transit">In Transit</option>
                    </select>
                </div>
                <div>
                    <label for="current-stage">Current routing stage</label>
                    <select id="current-stage" name="current_stage_index" required></select>
                </div>
                <div class="full-width">
                    <label>Routing sequence (in order)</label>
                    <div id="route-rows" class="route-list"></div>
                    <button class="secondary-button" id="add-route-stage" type="button">Add routing stage</button>
                    <p class="muted">Use the arrows to set the sequence. Transfer document types use one destination.</p>
                </div>
                <div class="full-width">
                    <button class="form-submit" type="submit">Create test document</button>
                    <span class="muted">No notifications or approval/receipt history will be generated.</span>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <?php
    $sections = [
        ['Live vouchers', $vouchers, 'voucher', static function ($row) use ($escape) {
            return [
                $escape($row['voucher_code']),
                $escape(display_document_title($row['document_title'])),
                $escape($row['requestor_name'] ?? 'Unknown'),
                $escape($row['status']),
                $escape($row['date_submitted'])
            ];
        }, ['Voucher ID', 'Title', 'Requestor', 'Status', 'Submitted']],
        ['Document types', $document_types, 'document_type', static function ($row) use ($escape) {
            return [
                $escape($row['id']),
                $escape(!empty($row['is_system_default']) && strcasecmp($row['name'], 'Financial Voucher') === 0 ? 'Disbursement Voucher' : $row['name']),
                $escape($row['is_active'] ? 'Active' : 'Inactive'),
                $escape((int)$row['live_references'] + (int)$row['archived_references'])
            ];
        }, ['ID', 'Name', 'Status', 'Voucher references']],
        ['Disbursement voucher types', $voucher_types, 'voucher_type', static function ($row) use ($escape) {
            return [
                $escape($row['id']),
                $escape($row['name']),
                $escape($row['is_active'] ? 'Active' : 'Inactive'),
                $escape((int)$row['live_references'] + (int)$row['archived_references'])
            ];
        }, ['ID', 'Name', 'Status', 'Voucher references']],
        ['Users', $users, 'user', static function ($row) use ($escape) {
            return [
                $escape($row['user_id']),
                $escape($row['full_name']),
                $escape($row['username']),
                $escape($row['role']),
                $escape((int)$row['live_vouchers'] + (int)$row['archived_vouchers'])
            ];
        }, ['ID', 'Full name', 'Username', 'Role', 'Submitted vouchers']],
        ['Notifications', $notifications, 'notification', static function ($row) use ($escape) {
            return [
                $escape($row['id']),
                $escape($row['recipient_name'] ?? ('User ID ' . $row['user_id'])),
                $escape($row['voucher_code'] ?? ''),
                $escape(str_ireplace('Financial Voucher', 'Disbursement Voucher', $row['message'])),
                $escape($row['is_read'] ? 'Read' : 'Unread'),
                $escape($row['created_at'])
            ];
        }, ['ID', 'Recipient', 'Voucher', 'Message', 'Status', 'Created']]
    ];
    foreach ($sections as $section):
    ?>
        <section class="record-section">
            <h2><?php echo $escape($section[0]); ?></h2>
            <?php if (empty($section[1])): ?>
                <p class="muted">No records.</p>
            <?php else: ?>
                <form method="POST" class="bulk-delete-form" onsubmit="return confirm('Permanently delete all selected records in this section? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo $escape($_SESSION['test_data_manager_csrf']); ?>">
                <input type="hidden" name="entity" value="<?php echo $escape($section[2]); ?>">
                <div class="bulk-actions">
                    <button class="delete-button" type="submit">Delete selected</button>
                    <span class="muted">Select one or more eligible records below. Protected records cannot be selected.</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th><input class="select-record select-all" type="checkbox" aria-label="Select all eligible records"></th>
                            <?php foreach ($section[4] as $heading): ?><th><?php echo $escape($heading); ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($section[1] as $row): ?>
                            <?php
                                $protected = ($section[2] === 'user' && (
                                    (int)$row['user_id'] === (int)($_SESSION['user_id'] ?? 0)
                                    || $row['role'] === 'Management Information System Office'
                                    || (int)$row['live_vouchers'] + (int)$row['archived_vouchers'] > 0
                                )) || ($section[2] === 'document_type' && (
                                    !empty($row['is_system_default'])
                                    || (int)$row['live_references'] + (int)$row['archived_references'] > 0
                                )) || ($section[2] === 'voucher_type' && (
                                    (int)$row['live_references'] + (int)$row['archived_references'] > 0
                                ));
                            ?>
                            <tr>
                                <td>
                                    <input class="select-record record-checkbox" type="checkbox" name="record_ids[]" value="<?php echo $escape($section[2] === 'voucher' ? $row['voucher_code'] : ($section[2] === 'user' ? $row['user_id'] : $row['id'])); ?>" <?php echo $protected ? 'disabled title="This record is protected or still referenced."' : ''; ?> aria-label="Select record">
                                </td>
                                <?php foreach ($section[3]($row) as $cell): ?><td><?php echo $cell; ?></td><?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
<script>
var routeOptions = <?php echo json_encode($route_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
var documentTypeDefaults = <?php echo json_encode($document_type_defaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
var typeSelect = document.getElementById('document-type');
if (typeSelect) {
    var routeRows = document.getElementById('route-rows');
    var currentStage = document.getElementById('current-stage');
    var statusSelect = document.getElementById('document-status');
    var addStageButton = document.getElementById('add-route-stage');

    function refreshRouteStages(selectedStage) {
        var rows = Array.from(routeRows.querySelectorAll('.route-choice'));
        currentStage.innerHTML = '';
        rows.forEach(function (_, index) {
            var option = document.createElement('option');
            option.value = String(index + 1);
            option.textContent = 'Stage ' + (index + 1);
            currentStage.appendChild(option);
        });
        if (rows.length) {
            currentStage.value = String(Math.min(selectedStage || 1, rows.length));
        }
        var isTransfer = typeSelect.selectedOptions[0] &&
            typeSelect.selectedOptions[0].dataset.workflowType === 'Transfer';
        addStageButton.disabled = isTransfer;
        addStageButton.title = isTransfer ? 'Transfer documents have exactly one destination.' : '';
        Array.from(statusSelect.options).forEach(function (option) {
            option.hidden = isTransfer
                ? option.value !== 'In Transit'
                : option.value === 'In Transit';
            option.disabled = option.hidden;
        });
        if (isTransfer) {
            statusSelect.value = 'In Transit';
        } else if (statusSelect.value === 'In Transit') {
            statusSelect.value = 'Pending Review';
        }
    }

    function addRouteRow(value) {
        var row = document.createElement('li');
        row.className = 'route-row';
        var select = document.createElement('select');
        select.className = 'route-choice';
        select.name = 'route_steps[]';
        select.required = true;
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Choose an office or account';
        select.appendChild(placeholder);
        routeOptions.forEach(function (route) {
            var option = document.createElement('option');
            option.value = route.value;
            option.textContent = route.label;
            select.appendChild(option);
        });
        select.value = value || '';

        var upButton = document.createElement('button');
        upButton.type = 'button';
        upButton.textContent = 'Up';
        upButton.setAttribute('aria-label', 'Move stage up');
        upButton.addEventListener('click', function () {
            if (row.previousElementSibling) {
                routeRows.insertBefore(row, row.previousElementSibling);
                refreshRouteStages(currentStage.value);
            }
        });
        var downButton = document.createElement('button');
        downButton.type = 'button';
        downButton.textContent = 'Down';
        downButton.setAttribute('aria-label', 'Move stage down');
        downButton.addEventListener('click', function () {
            if (row.nextElementSibling) {
                routeRows.insertBefore(row.nextElementSibling, row);
                refreshRouteStages(currentStage.value);
            }
        });
        var removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.textContent = 'Remove';
        removeButton.className = 'secondary-button';
        removeButton.addEventListener('click', function () {
            if (routeRows.children.length > 1) {
                row.remove();
                refreshRouteStages(currentStage.value);
            }
        });
        select.addEventListener('change', function () {
            refreshRouteStages(currentStage.value);
        });
        row.append(select, upButton, downButton, removeButton);
        routeRows.appendChild(row);
        refreshRouteStages(currentStage.value);
    }

    typeSelect.addEventListener('change', function () {
        routeRows.innerHTML = '';
        var defaults = documentTypeDefaults[typeSelect.value] || [];
        var validValues = routeOptions.map(function (route) { return route.value; });
        var usableDefaults = defaults.filter(function (route) {
            return typeof route === 'string' && validValues.indexOf(route) !== -1;
        });
        if (typeSelect.selectedOptions[0].dataset.workflowType === 'Transfer') {
            usableDefaults = usableDefaults.slice(0, 1);
        }
        if (!usableDefaults.length) {
            usableDefaults = [''];
        }
        usableDefaults.forEach(addRouteRow);
        refreshRouteStages(1);
    });
    addStageButton.addEventListener('click', function () {
        if (routeRows.children.length < 30) {
            addRouteRow('');
        }
    });
    document.getElementById('set-overdue-deadline').addEventListener('click', function () {
        var yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);
        document.getElementById('arta-deadline').value =
            yesterday.getFullYear() + '-' +
            String(yesterday.getMonth() + 1).padStart(2, '0') + '-' +
            String(yesterday.getDate()).padStart(2, '0');
    });
    document.getElementById('create-test-document-form').addEventListener('submit', function (event) {
        if (routeRows.querySelectorAll('.route-choice').length < 1) {
            event.preventDefault();
            alert('Add at least one routing stage.');
        }
    });
}

document.querySelectorAll('.bulk-delete-form').forEach(function (form) {
    var selectAll = form.querySelector('.select-all');
    var checkboxes = Array.from(form.querySelectorAll('.record-checkbox:not(:disabled)'));
    selectAll.addEventListener('change', function () {
        checkboxes.forEach(function (checkbox) {
            checkbox.checked = selectAll.checked;
        });
    });
    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            selectAll.checked = checkboxes.length > 0 && checkboxes.every(function (item) {
                return item.checked;
            });
        });
    });
    form.addEventListener('submit', function (event) {
        if (!checkboxes.some(function (checkbox) { return checkbox.checked; })) {
            event.preventDefault();
            alert('Select at least one eligible record.');
        }
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
