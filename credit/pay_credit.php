<?php
error_log("[PAY_CREDIT] Called with: credit_id=" . ($_POST['credit_id'] ?? 'missing') . " amount=" . ($_POST['amount'] ?? 'missing'));
/**
 * credit/pay_credit.php
 * AJAX POST endpoint — receive payment against an active customer credit
 *
 * FIXES (Step 2):
 *  - CSRF token verification added
 *  - syncAfterCreditPayment() called with correct 3-arg signature
 *  - Fallback to ensureCashbookEntry() if sync function absent
 *  - clearAllCaches() + invalidateDashboardCache() called after all ops
 *  - Over-payment guard retained
 *  - Response includes new_balance field
 */

require_once '../config/config.php';
require_once '../includes/sync_engine.php';
require_once '../includes/cashbook_functions.php';

requireLogin();
header('Content-Type: application/json');

// ── Method guard ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// ── CSRF verification (Step 2 requirement) ───────────────────────────────────
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']);
    exit;
}

$db = db();

// ── Input sanitisation ───────────────────────────────────────────────────────
$credit_id      = (int)($_POST['credit_id']     ?? 0);
$amount         = (float)($_POST['amount']       ?? 0);
$payment_method = trim($_POST['payment_method']  ?? 'cash');
$note           = trim($_POST['note']            ?? '');

if (!in_array($payment_method, ['cash', 'card', 'online'], true)) {
    $payment_method = 'cash';
}

// ── Basic validation ─────────────────────────────────────────────────────────
if ($credit_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid credit ID']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Payment amount must be greater than zero']);
    exit;
}

// ── Fetch active credit + linked sale totals ─────────────────────────────────
$credit = $db->getOne(
    "SELECT cc.*, s.invoice_no, s.total AS sale_total, s.paid_amount AS sale_upfront
     FROM customer_credits cc
     LEFT JOIN sales s ON s.id = cc.sale_id
     WHERE cc.id = :id AND cc.status = 'active'",
    [':id' => $credit_id]
);

if (!$credit) {
    echo json_encode(['success' => false, 'message' => 'Credit not found or already fully paid']);
    exit;
}

// ── Calculate remaining balance (ground truth) ───────────────────────────────
// sale.total - sale.paid_amount (upfront) - post-sale credit_payments
$paidRow = $db->getOne(
    "SELECT COALESCE(SUM(amount), 0) AS paid
     FROM credit_payments
     WHERE credit_id = :cid
       AND (note IS NULL OR (note NOT LIKE 'Upfront%' AND note NOT LIKE 'upfront%'))",
    [':cid' => $credit_id]
);
$postSalePaid = (float)($paidRow['paid'] ?? 0);
$saleTotal    = (float)($credit['sale_total']   ?? $credit['total_amount'] ?? 0);
$upfrontPaid  = (float)($credit['sale_upfront'] ?? 0);
$remaining    = max(0, $saleTotal - $upfrontPaid - $postSalePaid);

// ── Over-payment guard ───────────────────────────────────────────────────────
if ($amount > $remaining + 0.01) {
    echo json_encode([
        'success'     => false,
        'message'     => 'Payment amount (Rs ' . number_format($amount, 2) .
                         ') exceeds remaining balance (Rs ' . number_format($remaining, 2) . ')',
        'new_balance' => $remaining,
    ]);
    exit;
}

// ── Begin DB transaction ─────────────────────────────────────────────────────
$db->beginTransaction();

try {
    // 1. Insert into credit_payments
    $paymentId = insertData('credit_payments', [
        'credit_id'      => $credit_id,
        'amount'         => round($amount, 2),
        'payment_method' => $payment_method,
        'note'           => $note,
        'payment_date'   => date('Y-m-d H:i:s'),
        'created_by'     => (int)($_SESSION['user_id'] ?? 0),
    ]);

    if (!$paymentId) {
        throw new Exception('Failed to insert payment record');
    }

    // 2. Recalculate totals after this payment
    $newPostSalePaid = $postSalePaid + $amount;
    $newRemaining    = max(0, $saleTotal - $upfrontPaid - $newPostSalePaid);
    $nowFullyPaid    = $newRemaining < 0.01;

    // 3. Update customer_credits.status when fully paid
    //    (Step 2 req: SUM(credit_payments.amount) >= customer_credits.total_amount)
    if ($nowFullyPaid) {
        $db->run(
            "UPDATE customer_credits SET status = 'paid' WHERE id = :id",
            [':id' => $credit_id]
        );
    }

    $db->commit();

    // ── 4. Sync to cashbook ───────────────────────────────────────────────────
    // sync_engine.php signature: syncAfterCreditPayment($credit_id, $payment_id, $amount, $payment_method)
    // Fallback: direct cashbook entry if sync function not available
    $cashbookEntryId = null;

    if (function_exists('syncAfterCreditPayment')) {
        // Pass $paymentId as second arg — matches sync_engine.php 4-arg signature exactly
        $syncResult      = syncAfterCreditPayment($credit_id, $paymentId, $amount, $payment_method);
        $cashbookEntryId = $syncResult['synced'][0] ?? ($syncResult['entry_id'] ?? null);
    } else {
        // Fallback: write cashbook entry directly (Step 2 requirement)
        $customerName    = $credit['customer_name'] ?? 'Customer';
        $cashbookEntryId = addCashbookEntry(
            'credit_received',
            $credit_id,
            'credit',
            'Credit payment from ' . $customerName .
                ($credit['invoice_no'] ? ' (Invoice: ' . $credit['invoice_no'] . ')' : ''),
            0,              // debit
            round($amount, 2), // credit
            $payment_method,
            date('Y-m-d')
        );
    }

    // ── 5. Clear all caches (Step 2 requirement) ──────────────────────────────
    if (function_exists('clearAllCaches'))         clearAllCaches();
    if (function_exists('invalidateDashboardCache')) invalidateDashboardCache();

    // ── 6. Optional activity log ──────────────────────────────────────────────
    if (function_exists('logActivity')) {
        logActivity(
            'Credit Payment',
            'Payment of Rs ' . number_format($amount, 2) .
            ' received from ' . ($credit['customer_name'] ?? '') .
            ' (Credit #' . $credit_id . ')' .
            ($nowFullyPaid ? ' — FULLY PAID' : '')
        );
    }

    // ── 7. Fetch updated sale payment_status ──────────────────────────────────
    $salePaymentStatus = null;
    if (!empty($credit['sale_id'])) {
        $sale = $db->getOne(
            "SELECT payment_status FROM sales WHERE id = :id",
            [':id' => $credit['sale_id']]
        );
        $salePaymentStatus = $sale['payment_status'] ?? null;
    }

    // ── 8. Return JSON (includes new_balance per Step 2 spec) ─────────────────
    echo json_encode([
        'success'             => true,
        'payment_id'          => $paymentId,
        'fully_paid'          => $nowFullyPaid,
        'new_balance'         => round($newRemaining, 2),   // Step 2 requirement
        'new_remaining'       => round($newRemaining, 2),
        'new_paid'            => round($newPostSalePaid, 2),
        'new_status'          => $nowFullyPaid ? 'paid' : 'active',
        'cashbook_entry_id'   => $cashbookEntryId,
        'sale_payment_status' => $salePaymentStatus,
        'message'             => $nowFullyPaid
            ? 'Credit fully paid! Cashbook updated.'
            : 'Payment of Rs ' . number_format($amount, 2) . ' recorded successfully.',
    ]);

} catch (Exception $e) {
    $db->rollback();
    error_log('[pay_credit] ' . $e->getMessage());
    echo json_encode([
        'success'     => false,
        'message'     => 'Error: ' . $e->getMessage(),
        'new_balance' => $remaining,
    ]);
}