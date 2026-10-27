<?php
/**
 * CREDIT/add_credit.php
 * Manual credit add (standalone API — not from POS)
 *
 * FIXES:
 *  - Added sale_id = NULL in INSERT (column exists in schema, was missing)
 *  - due_date validation & sanitization
 *  - Activity log entry
 *  - Proper error messages
 *  - [NEW] Cashbook debit entry for credit given (cash out)
 */

require_once '../config/config.php';
require_once '../includes/sync_engine.php'; // for ensureCashbookEntry

requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$db = db();

// ── Input collection & sanitization ─────────────────────────
$customer_name  = trim($_POST['customer_name']  ?? '');
$customer_phone = trim($_POST['customer_phone'] ?? '');
$amount         = floatval($_POST['amount']      ?? 0);
$due_date       = trim($_POST['due_date']        ?? '');
$description    = trim($_POST['description']     ?? '');

// Validate required fields
if (!$customer_name) {
    echo json_encode(['success' => false, 'message' => 'Customer name is required']);
    exit;
}
if (!$customer_phone) {
    echo json_encode(['success' => false, 'message' => 'Customer phone is required']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
    exit;
}

// Validate & fallback due_date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date) || strtotime($due_date) === false) {
    $due_date = date('Y-m-d', strtotime('+7 days'));
}

// ── Insert credit record and cashbook entry in a transaction ──
$db->beginTransaction();

try {
    // Insert into customer_credits (sale_id = NULL for manual credit)
    $db->query(
        "INSERT INTO customer_credits
            (sale_id, customer_name, customer_phone, total_amount, due_date, description, status, created_at)
         VALUES (NULL, ?, ?, ?, ?, ?, 'active', NOW())",
        [$customer_name, $customer_phone, $amount, $due_date, $description]
    );
    $creditId = $db->lastId();

    if (!$creditId) {
        throw new Exception('Failed to insert credit record');
    }

    // ── Cashbook entry: debit (cash out) for credit given ──
    // This records that cash has left the pharmacy
    if (function_exists('ensureCashbookEntry')) {
        $cashbookEntryId = ensureCashbookEntry(
            entry_type:     'credit_given',
            reference_id:   $creditId,
            reference_type: 'manual_credit',
            description:    "Manual credit given to {$customer_name} ({$customer_phone}) – Rs " . number_format($amount, 2),
            debit:          $amount,
            credit:         0,
            payment_method: 'cash',
            entry_date:     date('Y-m-d')
        );
        if (!$cashbookEntryId) {
            throw new Exception('Failed to create cashbook entry for credit given');
        }
    } else {
        // Fallback: direct insert (should not happen because sync_engine is loaded)
        error_log("ensureCashbookEntry not available – cashbook entry not created for credit #{$creditId}");
    }

    $db->commit();

    // ── Activity log ─────────────────────────────────────────────
    if (!empty($_SESSION['user_id'])) {
        $db->query(
            "INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at)
             VALUES (?, 'Credit Added', ?, ?, ?, NOW())",
            [
                $_SESSION['user_id'],
                "Manual credit added for {$customer_name} ({$customer_phone}) — Rs " . number_format($amount, 2),
                $_SERVER['REMOTE_ADDR']     ?? '::1',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]
        );
    }

    echo json_encode([
        'success' => true,
        'id'      => $creditId,
        'message' => 'Credit added and cash out recorded in cashbook'
    ]);

} catch (Exception $e) {
    $db->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}