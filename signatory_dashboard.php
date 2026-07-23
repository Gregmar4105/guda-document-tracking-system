<?php
// signatory_dashboard.php

// --- Example DB Connection (replace with your actual connection) ---
$host = '127.0.0.1';
$db   = 'naap_document_system'; // Changed to specified database name
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
// --- End Example DB Connection ---


// In a real application, you would get the logged-in user's ID from the session.
// For this example, we'll hardcode it. Change this ID to test different signatories.
$current_user_id = 1; // Assuming the current user is Alice (ID 1)

// This is the key query to find documents waiting for this user's signature
// where it is their turn in the sequence.
$sql = "SELECT
            d.id AS document_id,
            d.requestor_name,
            d.document_type,
            d.reason,
            ds.id AS signatory_record_id
        FROM documents d
        JOIN document_signatories ds ON d.id = ds.document_id
        WHERE
            ds.user_id = :current_user_id
            AND ds.status = 'pending'
            AND d.status NOT IN ('declined', 'returned') -- Hide already actioned documents
            AND ds.sequence_order = (
                SELECT MIN(sequence_order)
                FROM document_signatories
                WHERE document_id = d.id AND status = 'pending'
            )";

$stmt = $pdo->prepare($sql);
$stmt->execute(['current_user_id' => $current_user_id]);
$pending_documents = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Signatory Dashboard</title>
    <style>
        body { font-family: sans-serif; max-width: 800px; margin: auto; padding: 20px; }
        .document-card { border: 1px solid #ccc; border-radius: 5px; padding: 15px; margin-bottom: 20px; }
        .document-card h3 { margin-top: 0; }
        .actions { margin-top: 15px; }
        .actions textarea { width: 100%; margin-bottom: 10px; }
        .actions button { padding: 10px 15px; margin-right: 10px; cursor: pointer; }
        .actions button.accept { background-color: #28a745; color: white; border: none; }
        .actions button.decline { background-color: #dc3545; color: white; border: none; }
        .actions button.return { background-color: #ffc107; color: black; border: none; }
    </style>
</head>
<body>

    <h1>Pending Your Signature</h1>

    <?php if (empty($pending_documents)): ?>
        <p>You have no documents waiting for your signature.</p>
    <?php else: ?>
        <?php foreach ($pending_documents as $doc): ?>
            <div class="document-card">
                <h3>Document Type: <?= htmlspecialchars($doc['document_type']) ?></h3>
                <p><strong>Requestor:</strong> <?= htmlspecialchars($doc['requestor_name']) ?></p>
                <p><strong>Reason:</strong> <?= htmlspecialchars($doc['reason']) ?></p>

                <form class="actions" action="process_signature.php" method="POST">
                    <input type="hidden" name="document_id" value="<?= $doc['document_id'] ?>">
                    <input type="hidden" name="signatory_record_id" value="<?= $doc['signatory_record_id'] ?>">

                    <label for="comments_<?= $doc['document_id'] ?>">Comments (optional for accept, required for decline/return):</label>
                    <textarea id="comments_<?= $doc['document_id'] ?>" name="comments" rows="3"></textarea>

                    <button type="submit" name="action" value="accept" class="accept">Accept</button>
                    <button type="submit" name="action" value="decline" class="decline">Decline</button>
                    <button type="submit" name="action" value="return" class="return">Return to Sender</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</body>
</html>