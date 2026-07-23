<?php
// archive_documents.php
// This script should be run by a cron job, e.g., once a month.
// Example cron command: 0 4 1 * * /usr/bin/php /path/to/your/htdocs/archive_documents.php

set_time_limit(600); // Allow up to 10 minutes for large archiving jobs
echo "<h1>Archiving old completed documents...</h1>";

require_once 'db_connect.php';

// Get retention period from settings, default to 365 days if not set
$res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'archive_retention_days'");
$retention_days = ($res && $row = $res->fetch_assoc()) ? (int)$row['setting_value'] : 365;

echo "<p>Using an archive retention period of {$retention_days} days for completed documents.</p>";

// Find all voucher codes that are eligible for archiving
$terminal_states = ['Approved', 'Paid', 'Rejected', 'Received', 'Resubmitted', 'Lapsed', 'Cancelled'];
$placeholders = implode(',', array_fill(0, count($terminal_states), '?'));

$sql_select = "SELECT voucher_code FROM vouchers WHERE status IN ($placeholders) AND date_submitted < DATE_SUB(NOW(), INTERVAL ? DAY)";
$stmt_select = $conn->prepare($sql_select);
$types = str_repeat('s', count($terminal_states)) . 'i';
$stmt_select->bind_param($types, ...$terminal_states, $retention_days);
$stmt_select->execute();
$result = $stmt_select->get_result();
$voucher_codes_to_archive = array_column($result->fetch_all(MYSQLI_ASSOC), 'voucher_code');
$stmt_select->close();

if (empty($voucher_codes_to_archive)) {
    echo "<p>No documents are eligible for archiving at this time.</p>";
    $conn->close();
    exit();
}

echo "<p>Found " . count($voucher_codes_to_archive) . " documents to archive. Processing in batches...</p>";

// --- NEW: Process in chunks for better performance and to avoid server limits ---
$chunk_size = 200; // Process 200 documents at a time
$chunks = array_chunk($voucher_codes_to_archive, $chunk_size);
$total_archived = 0;

foreach ($chunks as $index => $chunk) {
    echo "<p>Processing batch " . ($index + 1) . " of " . count($chunks) . " (" . count($chunk) . " documents)...</p>";
    
    $conn->begin_transaction();
    try {
        $codes_placeholder = implode(',', array_fill(0, count($chunk), '?'));
        $codes_types = str_repeat('s', count($chunk));

        // 1. Copy records to archive tables
        $stmt_copy_vouchers = $conn->prepare("INSERT INTO vouchers_archive SELECT * FROM vouchers WHERE voucher_code IN ($codes_placeholder)");
        $stmt_copy_vouchers->bind_param($codes_types, ...$chunk);
        $stmt_copy_vouchers->execute();
        $stmt_copy_vouchers->close();

        $stmt_copy_logs = $conn->prepare("INSERT INTO audit_logs_archive SELECT * FROM audit_logs WHERE voucher_code IN ($codes_placeholder)");
        $stmt_copy_logs->bind_param($codes_types, ...$chunk);
        $stmt_copy_logs->execute();
        $stmt_copy_logs->close();

        // 2. Delete records from live tables
        $stmt_delete_vouchers = $conn->prepare("DELETE FROM vouchers WHERE voucher_code IN ($codes_placeholder)");
        $stmt_delete_vouchers->bind_param($codes_types, ...$chunk);
        $stmt_delete_vouchers->execute();
        $stmt_delete_vouchers->close();

        $stmt_delete_logs = $conn->prepare("DELETE FROM audit_logs WHERE voucher_code IN ($codes_placeholder)");
        $stmt_delete_logs->bind_param($codes_types, ...$chunk);
        $stmt_delete_logs->execute();
        $stmt_delete_logs->close();

        $conn->commit();
        $total_archived += count($chunk);
        echo "<p style='color: #166534;'>Batch " . ($index + 1) . " archived successfully.</p>";

    } catch (Exception $e) {
        $conn->rollback();
        echo "<p style='color: red;'>An error occurred during archiving batch " . ($index + 1) . ": " . $e->getMessage() . ". Transaction for this batch rolled back. Halting process.</p>";
        $conn->close();
        exit(); // Stop processing further chunks if one fails
    }
}

$echo "<h2 style='color: green;'>Archiving process complete. Successfully archived a total of {$total_archived} documents.</h2>";

$conn->close();