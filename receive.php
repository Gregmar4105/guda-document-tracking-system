<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
if (!isset($_SESSION['logged_in'])) { header("Location: login.php"); exit(); }

require_once 'db_connect.php';

$dept_role = $_SESSION['role'];
$username = $_SESSION['username'];
$success_msg = "";
$error_msg = "";

// Get user ID
$user_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
$user_stmt->bind_param("s", $username);
$user_stmt->execute();
$user_res = $user_stmt->get_result();
$user_id = ($user_res->num_rows > 0) ? $user_res->fetch_assoc()['user_id'] : NULL;
$user_stmt->close();

// NEW: Get base department role for heads to match against workflow steps and for logging
$is_head = $_SESSION['is_head'] ?? 0;
$base_dept_role = $dept_role;
if ($is_head) {
    $base_dept_role = trim(preg_replace('/\s*\(Head\)$/i', '', $base_dept_role));
}

// Determine Stage Dynamically
$workflow_sequence = [];
$seq_res = $conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1 ORDER BY name ASC");
while ($row = $seq_res->fetch_assoc()) {
    $workflow_sequence[] = $row['name'];
}

$my_stage_index = -1;
$found_index = array_search($dept_role, $workflow_sequence);
if ($found_index !== false) {
    $my_stage_index = $found_index + 1; // 1-based index for stages
}

// PROCESS THE RECEIVED VOUCHER
if (isset($_GET['receive_id']) && !empty($_GET['receive_id'])) {
    $receive_id = trim($_GET['receive_id']);

    $stmt = $conn->prepare("
        SELECT v.current_stage_index, v.status, v.custom_workflow, u.full_name as requestor_name, v.requestor_id
        FROM vouchers v LEFT JOIN users u ON v.requestor_id = u.user_id 
        WHERE v.voucher_code = ?");
    $stmt->bind_param("s", $receive_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $voucher = $result->fetch_assoc();
        $v_stage = (int)$voucher['current_stage_index'];
        $requestor_id = $voucher['requestor_id'];
        $requestor_name = htmlspecialchars($voucher['requestor_name'] ?? 'Unknown User');

        // Get the document's specific workflow or fallback to the global one
        $doc_workflow = json_decode($voucher['custom_workflow'], true);
        if (empty($doc_workflow)) { $doc_workflow = $workflow_sequence; }

        // --- Refactored Validation Logic ---

        // UNIVERSAL RULE 1: Cannot receive a document in a terminal state.
        if (in_array($voucher['status'], ['Returned', 'Rejected', 'Paid', 'Ready for Release'])) {
            $error_msg = "Cannot receive: Document is marked as '" . htmlspecialchars($voucher['status']) . "'.";
        } else {
            // UNIVERSAL RULE 2: Cannot receive a document that's already in this department's queue.
            $check_recv = $conn->prepare("SELECT log_id FROM audit_logs WHERE voucher_code = ? AND department = ? AND action_taken = 'Scan-to-Receive'");
            $check_recv->bind_param("ss", $receive_id, $dept_role);
            $check_recv->execute();
            if ($check_recv->get_result()->num_rows > 0) {
                $error_msg = "This voucher has already been scanned into your department's inbox.";
            }
            $check_recv->close();
        }

        // WORKFLOW RULE: For non-admins, validate the sequence.
        // MIS is exempt from strict sequence validation. VPAA will now follow standard workflow rules.
        if (empty($error_msg) && $dept_role !== 'Management Information System Office') {
            $current_stage_0_indexed = $v_stage - 1;
            $expected_dept_at_this_stage = $doc_workflow[$current_stage_0_indexed] ?? null;

            $is_authorized_to_receive = false;

            // Normalize the expected department name from the workflow
            $normalized_expected_dept = str_replace(['–', '—'], '-', (string)$expected_dept_at_this_stage);
            // The user's base role is already calculated as $base_dept_role
            $normalized_user_base_role = str_replace(['–', '—'], '-', (string)$base_dept_role);

            // Case 1: Route is for a specific head, e.g., "Accounting Office (Head)"
            if (preg_match('/^(.*) \(Head\)$/', $normalized_expected_dept, $matches)) {
                $dept_name_for_head_check = trim($matches[1]);
                if ($normalized_user_base_role === $dept_name_for_head_check && $is_head) {
                    $is_authorized_to_receive = true;
                }
            // Case 2: Route is for the generic "Department Head"
            } elseif ($normalized_expected_dept === 'Department Head') {
                if ($is_head) {
                    // Must be the head of the requestor's department.
                    $req_dept_stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
                    $req_dept_stmt->bind_param("i", $requestor_id);
                    $req_dept_stmt->execute();
                    $requestor_department = $req_dept_stmt->get_result()->fetch_assoc()['role'] ?? null;
                    $req_dept_stmt->close();

                    // Normalize the requestor's department for comparison
                    $normalized_requestor_dept = str_replace(['–', '—'], '-', (string)$requestor_department);

                    if ($requestor_department && $normalized_user_base_role === $normalized_requestor_dept) {
                        $is_authorized_to_receive = true;
                    }
                }
            // Case 3: Standard department route (for any member, head or not)
            } elseif ($normalized_expected_dept === $normalized_user_base_role) {
                $is_authorized_to_receive = true;
            }

            // If after all checks, user is not authorized, set the error message.
            if (empty($error_msg) && !$is_authorized_to_receive) {
                if (strpos($normalized_expected_dept, 'Head') !== false && !$is_head) {
                    $error_msg = "Sequence Error: This document is designated for a Department Head, but you are not registered as one.";
                } else {
                    $error_msg = "Sequence Error: This document is not currently at your stage in the workflow. It is at stage " . $v_stage . " (" . htmlspecialchars($expected_dept_at_this_stage ?? 'N/A') . ").";
                }
            }
        }

        // If all checks pass, proceed with receiving.
        if (empty($error_msg)) {
            $recv_stmt = $conn->prepare("INSERT INTO audit_logs (voucher_code, department, action_taken, remarks, processed_by_user_id) VALUES (?, ?, 'Scan-to-Receive', 'Physical document received at station', ?)");
            $recv_stmt->bind_param("ssi", $receive_id, $base_dept_role, $user_id);
            if ($recv_stmt->execute()) {
                $success_msg = "Voucher <strong>$receive_id</strong> from <strong>$requestor_name</strong> successfully received.<br><span style='font-size: 0.9rem;'>It is now pending in the Approval Queue.</span>";

                // Notify the requestor that their document has been physically received
                if ($requestor_id) {
                    $notif_message = "Your document " . $receive_id . " has been received by the " . $dept_role . " office and is now in their queue.";
                    $notif_link = "track.php?track_id=" . urlencode($receive_id);
                    
                    // Call notification function if it exists
                    if (function_exists('create_notification')) {
                        create_notification($conn, $requestor_id, $notif_message, $notif_link);
                    } else {
                        // Fallback direct insert if function is not globally defined
                        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, voucher_code, message) VALUES (?, ?, ?)");
                        if ($notif_stmt) {
                            $notif_stmt->bind_param("iss", $requestor_id, $receive_id, $notif_message);
                            $notif_stmt->execute();
                            $notif_stmt->close();
                        }
                    }
                }
            }
            $recv_stmt->close();
        }
    } else {
        $error_msg = "Voucher not found in the database.";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receiving Station - NAAP Voucher System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3a8a">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <link rel="stylesheet" href="sidebar.css?v=<?php echo filemtime('sidebar.css'); ?>">
    <link rel="stylesheet" href="receive.css">
    <style>
        .btn-start-scan {
            background-color: var(--naap-navy, #1E3A8A);
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 1.1rem;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
            margin: 10px auto 20px;
            display: inline-block;
            transition: background-color 0.3s;
        }
        .btn-start-scan:hover {
            background-color: #172a6b;
        }
        #qr-reader {
            margin: 0 auto;
            border-radius: 12px;
            overflow: hidden;
        }
    </style>
</head>
<body>

<?php include('sidebar.php'); ?>

<div class="main-content">
    <div class="page-header">
        <h1>Receiving Station (Time-In)</h1>
        <p>Scan physical documents as they arrive at the <strong><?php echo htmlspecialchars($dept_role); ?></strong> office.</p>
    </div>

    <?php if($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>
    <?php if($error_msg): ?> <div class="alert alert-error"><?php echo $error_msg; ?></div> <?php endif; ?>

    <div class="scanner-container">
        <h2>Scan Voucher QR Code</h2>
        
        <div id="scanner-status" style="margin-bottom: 15px; color: var(--text-muted);">
            <div class="status-info">Ready. Click below to activate scanner.</div>
        </div>
        
        <div class="qr-instructions" style="margin-bottom: 20px;">
            <strong>Mobile/Tablet:</strong> Defaults to the rear camera.<br>
            <strong>PC/Desktop:</strong> Defaults to the primary webcam.
        </div>
        
        <button id="start-scan-btn" class="btn-start-scan">Start Scanner</button>
        
        <div id="qr-reader" style="display: none; max-width: 500px;"></div>

        <div class="manual-input" style="margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
            <p style="font-weight: bold; margin-bottom: 10px;">Manual Entry</p>
            <form method="GET" style="display: flex; justify-content: center; flex-wrap: wrap; gap: 10px;">
                <input type="text" name="receive_id" placeholder="NAAP-YYYY-XXXX" autofocus pattern="[A-Z0-9\-]+" required style="padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 1rem; min-width: 250px;">
                <button type="submit" style="padding: 10px 20px; background-color: #F59E0B; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer;">Submit</button>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const startBtn = document.getElementById('start-scan-btn');
        const readerDiv = document.getElementById('qr-reader');
        const statusDiv = document.getElementById('scanner-status');
        
        startBtn.addEventListener('click', function() {
            startBtn.style.display = 'none';
            readerDiv.style.display = 'block';
            statusDiv.innerHTML = '<div class="status-info">Checking for cameras...</div>';

            Html5Qrcode.getCameras().then(cameras => {
                if (cameras && cameras.length) {
                    // Camera(s) found, proceed with scanning
                    statusDiv.innerHTML = '<div class="status-info" style="color: var(--naap-navy); font-weight: bold;">Please grant camera permissions via your browser\'s prompt.</div>';
                    
                    let html5QrcodeScanner = new Html5QrcodeScanner(
                        "qr-reader", 
                        { fps: 10, qrbox: {width: 250, height: 250} },
                        false
                    );

                    function onScanSuccess(decodedText, decodedResult) {
                        html5QrcodeScanner.clear(); 
                        let extractedId = decodedText.trim();
                        if (decodedText.includes('track_id=')) {
                            try {
                                const url = new URL(decodedText);
                                extractedId = url.searchParams.get('track_id');
                            } catch (e) {
                                const urlParams = new URLSearchParams(decodedText.substring(decodedText.indexOf('?')));
                                extractedId = urlParams.get('track_id');
                            }
                        }
                        statusDiv.innerHTML = '<div class="alert alert-success">QR Scanned: <strong>' + extractedId + '</strong><br>Processing...</div>';
                        setTimeout(() => {
                            window.location.href = 'receive.php?receive_id=' + encodeURIComponent(extractedId);
                        }, 800);
                    }

                    html5QrcodeScanner.render(onScanSuccess);

                } else {
                    // No cameras found
                    statusDiv.innerHTML = '<div class="alert alert-error"><strong>No Camera Found.</strong><br>Please ensure a camera is connected and permissions are granted in your browser/system settings. You can use Manual Entry below.</div>';
                    readerDiv.style.display = 'none';
                    startBtn.style.display = 'block';
                    startBtn.textContent = 'Try Again';
                }
            }).catch(err => {
                // Error getting cameras, likely a permissions issue.
                console.error("Error getting cameras:", err);
                statusDiv.innerHTML = '<div class="alert alert-error"><strong>Camera Access Denied.</strong><br>Please allow camera access in your browser settings to use the scanner. You can use Manual Entry below.</div>';
                readerDiv.style.display = 'none';
                startBtn.style.display = 'block';
                startBtn.textContent = 'Try Again';
            });
        });
    });
</script>

<?php
$conn->close();
?>
</body>
</html>