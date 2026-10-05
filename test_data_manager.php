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
        $display_name = $entity === 'document_type' ? 'Document type' : 'Financial voucher type';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!is_string($csrf_token) || !hash_equals($_SESSION['test_data_manager_csrf'], $csrf_token)) {
        $_SESSION['test_data_manager_notice'] = ['type' => 'error', 'message' => 'The request expired. Reload the page and try again.'];
        header('Location: test_data_manager.php');
        exit();
    }

    $transaction_started = false;
    try {
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
            'message' => count($record_ids) . ' selected ' . ($entity === 'voucher' ? 'voucher(s)' : 'record(s)') . ' deleted successfully.'
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
$load_error = '';

try {
    $result = $conn->query("
        SELECT v.voucher_code, v.document_title, v.status, v.date_submitted, u.full_name AS requestor_name
        FROM vouchers v
        LEFT JOIN users u ON u.user_id = v.requestor_id
        ORDER BY v.date_submitted DESC
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
        .muted { color: #64748b; }
        @media (max-width: 768px) { .main-content { padding: 24px 12px; } }
    </style>
</head>
<body>
<div class="main-content">
    <header class="page-header">
        <h1>Temporary Test Data Manager</h1>
        <p class="muted">Direct-URL, MIS-admin-only maintenance page; no sidebar link is provided.</p>
    </header>

    <div class="warning">
        <strong>Use only in a test environment.</strong> Deletions are permanent. Select multiple eligible records within a category and use “Delete selected.” Up to 200 newest live vouchers are listed at once; archived vouchers are not shown or deleted. Types and users linked to live or archived vouchers are protected; system-default document types and MIS administrator accounts are protected.
    </div>

    <?php if ($notice): ?>
        <div class="notice <?php echo $escape($notice['type']); ?>"><?php echo $escape($notice['message']); ?></div>
    <?php endif; ?>
    <?php if ($load_error !== ''): ?>
        <div class="notice error"><?php echo $escape($load_error); ?></div>
    <?php endif; ?>

    <?php
    $sections = [
        ['Live vouchers', $vouchers, 'voucher', static function ($row) use ($escape) {
            return [
                $escape($row['voucher_code']),
                $escape($row['document_title']),
                $escape($row['requestor_name'] ?? 'Unknown'),
                $escape($row['status']),
                $escape($row['date_submitted'])
            ];
        }, ['Voucher ID', 'Title', 'Requestor', 'Status', 'Submitted']],
        ['Document types', $document_types, 'document_type', static function ($row) use ($escape) {
            return [
                $escape($row['id']),
                $escape($row['name']),
                $escape($row['is_active'] ? 'Active' : 'Inactive'),
                $escape((int)$row['live_references'] + (int)$row['archived_references'])
            ];
        }, ['ID', 'Name', 'Status', 'Voucher references']],
        ['Financial voucher types', $voucher_types, 'voucher_type', static function ($row) use ($escape) {
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
        }, ['ID', 'Full name', 'Username', 'Role', 'Submitted vouchers']]
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
