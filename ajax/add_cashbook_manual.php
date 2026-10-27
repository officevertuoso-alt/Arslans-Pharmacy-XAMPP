<?php
/**
 * ajax/add_cashbook_manual.php
 * AJAX endpoint — manual cashbook entry from cashbook/index.php modal
 */

declare(strict_types=1);

require_once '../config/config.php';
require_once '../includes/cashbook_functions.php';
require_once '../includes/sync_engine.php';

// ── Auth & CSRF ────────────────────────────────────────────────────────────
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// CSRF check
$csrfToken = trim($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']);
    exit;
}

// ── Input collection & sanitisation ───────────────────────────────────────
$entryType     = trim($_POST['entry_type']     ?? '');
$description   = trim($_POST['description']    ?? '');
$amountRaw     = trim($_POST['amount']         ?? '');
$paymentMethod = trim($_POST['payment_method'] ?? 'cash');
$entryDate     = trim($_POST['entry_date']     ?? '');

// ── Validation ─────────────────────────────────────────────────────────────
$errors = [];

if (!in_array($entryType, ['cash_in', 'cash_out'], true)) {
    $errors[] = 'Entry type must be cash_in or cash_out.';
}

if ($description === '') {
    $errors[] = 'Description is required.';
}

$amount = filter_var($amountRaw, FILTER_VALIDATE_FLOAT);
if ($amount === false || $amount <= 0) {
    $errors[] = 'Amount must be a positive number.';
}

// Validate date — accept YYYY-MM-DD; fall back to today
if ($entryDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
    $errors[] = 'Invalid date format. Use YYYY-MM-DD.';
} elseif ($entryDate === '') {
    $entryDate = date('Y-m-d');
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// ── Determine debit / credit amounts ──────────────────────────────────────
$debit  = 0.0;
$credit = 0.0;

if ($entryType === 'cash_in') {
    $credit = (float) $amount;
} else {
    $debit = (float) $amount;
}

// ── Persist cashbook entry ─────────────────────────────────────────────────
try {
    // Use ensureCashbookEntry() from sync_engine.php — confirmed working.
    // For manual entries we use a unique reference_id (microtime-based)
    // so the duplicate guard inside ensureCashbookEntry() never blocks
    // a second manual entry with the same type.
    $manualRefId = (int)(microtime(true) * 1000) % 2147483647; // unique int, fits INT column

    $entryId = ensureCashbookEntry(
        $entryType,      // entry_type  (cash_in / cash_out)
        $manualRefId,    // unique reference_id per entry
        'manual',        // reference_type
        $description,
        $debit,
        $credit,
        $paymentMethod,
        $entryDate
    );

    if ($entryId === false || $entryId === 0) {
        throw new RuntimeException('addCashbookEntry() returned false — entry was not saved.');
    }

    // ── Post-save cache invalidation ──────────────────────────────────────
    if (function_exists('clearAllCaches')) {
        clearAllCaches();
    }
    if (function_exists('invalidateDashboardCache')) {
        invalidateDashboardCache();
    }

    echo json_encode([
        'success'  => true,
        'message'  => 'Cashbook entry added successfully.',
        'entry_id' => $entryId,
    ]);

} catch (Throwable $e) {
    // Log internally; never expose raw exception to client
    error_log('[add_cashbook_manual] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save cashbook entry. Please try again.',
    ]);
}