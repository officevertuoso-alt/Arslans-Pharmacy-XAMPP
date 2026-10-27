<?php
/**
 * suppliers/pay.php — Record Supplier Payment – FULL SYNC INTEGRATION
 * 
 * FIXED VERSION:
 * 1. Properly accounts for purchase returns in net due calculation
 * 2. Fixed opening balance calculation
 * 3. Added due_date column migration if missing
 * 4. Fixed payment distribution logic
 * 5. Added proper error handling and validation
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/purchase_functions.php';
require_once '../includes/sync_engine.php';
require_once '../includes/cashbook_functions.php';

requirePharmacist();

$page_title = 'Record Supplier Payment';
$error   = '';
$success = '';

// Ensure purchases table has due_date column
function ensureDueDateColumn(): void {
    try {
        $pdo = _getPDO();
        $cols = $pdo->query("SHOW COLUMNS FROM purchases")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('due_date', $cols)) {
            $pdo->exec("ALTER TABLE purchases ADD COLUMN due_date DATE DEFAULT NULL AFTER purchase_date");
            // Update existing purchases: set due_date = purchase_date + 30 days
            $pdo->exec("UPDATE purchases SET due_date = DATE_ADD(purchase_date, INTERVAL 30 DAY) WHERE due_date IS NULL");
            error_log("Added due_date column to purchases table");
        }
    } catch (Exception $e) {
        error_log("ensureDueDateColumn: " . $e->getMessage());
    }
}
ensureDueDateColumn();

// Accept ?supplier_id= (suppliers pages) OR ?id= (purchases/view.php Make Payment button)
$supplier_id = (int)($_POST['supplier_id'] ?? $_GET['supplier_id'] ?? $_GET['id'] ?? 0);
$purchase_id = (int)($_POST['purchase_id'] ?? $_GET['purchase_id'] ?? 0);

// If called with a purchase id but no supplier_id, resolve supplier from purchase
if (!$supplier_id && $purchase_id) {
    $row = _pdoOne("SELECT supplier_id FROM purchases WHERE id = :id", [':id' => $purchase_id]);
    if ($row) $supplier_id = (int)$row['supplier_id'];
}

if (!$supplier_id) {
    if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Supplier ID missing.']);
        exit;
    }
    header('Location: ../suppliers/list.php');
    exit;
}

$supplier = getSupplierById($supplier_id);
if (!$supplier) { 
    header('Location: ../suppliers/list.php'); 
    exit; 
}

// ── FIXED: Helper: get net due for a purchase (total minus returns minus paid) ──────
function getPurchaseNetDue(int $purchaseId): array {
    try {
        $p = _pdoOne(
            "SELECT id, total, paid_amount, due_amount, payment_status, purchase_invoice_no, purchase_date 
             FROM purchases WHERE id = :id",
            [':id' => $purchaseId]
        );
        if (!$p) return ['net_due' => 0, 'total_returned' => 0, 'net_total' => 0, 'error' => 'Purchase not found'];

        // Get total returns for this purchase
        $totalReturned = (float)(_pdoOne(
            "SELECT COALESCE(SUM(total_amount), 0) AS t FROM purchase_returns WHERE purchase_id = :pid AND status = 'approved'",
            [':pid' => $purchaseId]
        )['t'] ?? 0);

        $grossTotal = (float)$p['total'];
        $netTotal = $grossTotal - $totalReturned;
        $paidAmount = (float)$p['paid_amount'];
        $netDue = max(0, $netTotal - $paidAmount);

        return [
            'net_due'        => $netDue,
            'total_returned' => $totalReturned,
            'net_total'      => $netTotal,
            'paid_amount'    => $paidAmount,
            'gross_total'    => $grossTotal,
            'payment_status' => $p['payment_status'],
            'invoice_no'     => $p['purchase_invoice_no'],
            'purchase_date'  => $p['purchase_date'],
        ];
    } catch (Exception $e) {
        error_log("getPurchaseNetDue error: " . $e->getMessage());
        return ['net_due' => 0, 'total_returned' => 0, 'net_total' => 0, 'error' => $e->getMessage()];
    }
}

// ── FIXED: Get pending purchases with accurate net due ─────────────────────────────
function getSupplierPendingPurchasesWithNetDue(int $supplierId): array {
    try {
        $rows = _pdoAll(
            "SELECT p.*, s.supplier_name
             FROM purchases p
             JOIN suppliers s ON s.id = p.supplier_id
             WHERE p.supplier_id = :sid 
               AND p.payment_status != 'paid'
             ORDER BY p.purchase_date ASC",
            [':sid' => $supplierId]
        );

        $result = [];
        foreach ($rows as $row) {
            $info = getPurchaseNetDue((int)$row['id']);
            if (isset($info['error'])) continue;
            if ($info['net_due'] > 0.009) {  // Only show if net due > 0
                $row['net_due']        = $info['net_due'];
                $row['net_total']      = $info['net_total'];
                $row['total_returned'] = $info['total_returned'];
                $result[] = $row;
            }
        }
        return $result;
    } catch (Exception $e) {
        error_log("getSupplierPendingPurchasesWithNetDue error: " . $e->getMessage());
        return [];
    }
}

// ── Get supplier total outstanding balance (net after returns) ────────────────────
function getSupplierTotalOutstanding(int $supplierId): float {
    $purchases = getSupplierPendingPurchasesWithNetDue($supplierId);
    $total = 0;
    foreach ($purchases as $p) {
        $total += (float)($p['net_due'] ?? 0);
    }
    return $total;
}

$pendingPurchases = getSupplierPendingPurchasesWithNetDue($supplier_id);
$payments = getSupplierPayments($supplier_id);
$totalOutstanding = getSupplierTotalOutstanding($supplier_id);

// Update supplier current_balance to match actual outstanding
function updateSupplierBalance(int $supplierId): void {
    try {
        $pdo = _getPDO();
        $outstanding = getSupplierTotalOutstanding($supplierId);
        $pdo->prepare("UPDATE suppliers SET current_balance = :bal WHERE id = :sid")
            ->execute([':bal' => $outstanding, ':sid' => $supplierId]);
    } catch (Exception $e) {
        error_log("updateSupplierBalance error: " . $e->getMessage());
    }
}
updateSupplierBalance($supplier_id);
$supplier = getSupplierById($supplier_id); // Refresh supplier data

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ── Handle AJAX Payment (from view.php modal) ─────────────────────────────────── */
$isAjax = isset($_POST['ajax']) && $_POST['ajax'] == 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $amount  = round((float)($_POST['amount'] ?? 0), 2);
    $method  = $_POST['payment_method'] ?? 'cash';
    $payDate = $_POST['payment_date'] ?? date('Y-m-d');
    $notes   = trim($_POST['notes'] ?? '');
    $refNo   = trim($_POST['reference_no'] ?? '');

    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero.']);
        exit;
    }

    // If a specific purchase_id was sent (e.g. from view.php Make Payment modal),
    // pay against ONLY that purchase. Otherwise fall back to FIFO across all pending.
    $specificPid = (int)($_POST['purchase_id'] ?? 0);

    if ($specificPid > 0) {
        // Single-purchase targeted payment
        $row = _pdoOne(
            "SELECT id, purchase_invoice_no, total, paid_amount, due_amount, payment_status
             FROM purchases WHERE id = :pid AND supplier_id = :sid",
            [':pid' => $specificPid, ':sid' => $supplier_id]
        );
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Purchase not found for this supplier.']);
            exit;
        }
        $info = getPurchaseNetDue($specificPid);
        if (isset($info['error'])) {
            echo json_encode(['success' => false, 'message' => 'Could not calculate due: ' . $info['error']]);
            exit;
        }
        $purchaseDues = [array_merge($row, $info)];
        $totalNetDue  = $info['net_due'];
    } else {
        // FIFO: Get all pending purchases ordered by date (oldest first)
        $pendingRows = _pdoAll(
            "SELECT id, purchase_invoice_no, total, paid_amount, due_amount, payment_status
             FROM purchases
             WHERE supplier_id = :sid AND payment_status != 'paid'
             ORDER BY purchase_date ASC, id ASC",
            [':sid' => $supplier_id]
        );
        $purchaseDues = [];
        $totalNetDue  = 0;
        foreach ($pendingRows as $row) {
            $info = getPurchaseNetDue((int)$row['id']);
            if (isset($info['error'])) continue;
            if ($info['net_due'] > 0.009) {
                $purchaseDues[] = array_merge($row, $info);
                $totalNetDue   += $info['net_due'];
            }
        }
    }

    if ($totalNetDue < 0.01) {
        echo json_encode(['success' => false, 'message' => 'No outstanding balance to pay.']);
        exit;
    }

    if ($amount > $totalNetDue + 0.01) {
        echo json_encode(['success' => false, 'message' => sprintf(
            'Amount (Rs %.2f) exceeds the due amount (Rs %.2f) for this purchase.', $amount, $totalNetDue
        )]);
        exit;
    }

    $pdo = _getPDO();

    // Ensure columns exist
    $spCols = $pdo->query("SHOW COLUMNS FROM supplier_payments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('created_by', $spCols)) {
        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN created_by INT NULL DEFAULT NULL");
        $spCols[] = 'created_by';
    }
    if (!in_array('reference_no', $spCols)) {
        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN reference_no VARCHAR(100) NULL DEFAULT NULL");
        $spCols[] = 'reference_no';
    }
    if (!in_array('payment_method', $spCols)) {
        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN payment_method VARCHAR(50) NULL DEFAULT 'cash'");
        $spCols[] = 'payment_method';
    }
    $spHasPaymentMethod = in_array('payment_method', $spCols);

    $cbCols = $pdo->query("SHOW COLUMNS FROM cashbook")->fetchAll(PDO::FETCH_COLUMN);
    // Detect actual column names — cashbook may use 'added_by' instead of 'created_by'
    // and may not have a 'payment_method' column at all
    $cbUserCol      = in_array('added_by', $cbCols) ? 'added_by' : (in_array('created_by', $cbCols) ? 'created_by' : null);
    $cbHasPayMethod = in_array('payment_method', $cbCols);
    if (!in_array('voided', $cbCols)) {
        $pdo->exec("ALTER TABLE cashbook ADD COLUMN voided TINYINT(1) NOT NULL DEFAULT 0");
    }

    $pdo->beginTransaction();
    try {
        $userId = (int)($_SESSION['user_id'] ?? 1);
        $remaining = $amount;
        $paymentsRecorded = [];

        foreach ($purchaseDues as $pur) {
            if ($remaining <= 0.009) break;

            $pid = (int)$pur['id'];
            $netDue = (float)$pur['net_due'];
            $netTotal = (float)$pur['net_total'];
            $thisAmt = min($remaining, $netDue);
            $thisAmt = round($thisAmt, 2);
            $invoiceNo = $pur['purchase_invoice_no'] ?? "Purchase #{$pid}";

            // Insert supplier_payments record
            if ($spHasPaymentMethod) {
                $pdo->prepare(
                    "INSERT INTO supplier_payments
                        (supplier_id, purchase_id, amount, payment_date, payment_method, notes, created_by, reference_no)
                     VALUES (:sid, :pid, :amt, :date, :method, :notes, :user, :refno)"
                )->execute([
                    ':sid'    => $supplier_id,
                    ':pid'    => $pid,
                    ':amt'    => $thisAmt,
                    ':date'   => $payDate,
                    ':method' => $method,
                    ':notes'  => $notes ?: null,
                    ':user'   => $userId,
                    ':refno'  => $refNo ?: $invoiceNo,
                ]);
            } else {
                $pdo->prepare(
                    "INSERT INTO supplier_payments
                        (supplier_id, purchase_id, amount, payment_date, notes, created_by, reference_no)
                     VALUES (:sid, :pid, :amt, :date, :notes, :user, :refno)"
                )->execute([
                    ':sid'    => $supplier_id,
                    ':pid'    => $pid,
                    ':amt'    => $thisAmt,
                    ':date'   => $payDate,
                    ':notes'  => $notes ?: null,
                    ':user'   => $userId,
                    ':refno'  => $refNo ?: $invoiceNo,
                ]);
            }
            $paymentId = (int)$pdo->lastInsertId();
            $paymentsRecorded[] = $paymentId;

            // Update purchase paid/due/status
            $newPaid = (float)$pur['paid_amount'] + $thisAmt;
            $newNetDue = max(0, $netTotal - $newPaid);
            $newStatus = ($newNetDue <= 0.009) ? 'paid' : ($newPaid > 0 ? 'partial' : 'pending');

            $pdo->prepare(
                "UPDATE purchases SET paid_amount = :paid, due_amount = :due, payment_status = :status WHERE id = :id"
            )->execute([':paid' => $newPaid, ':due' => $newNetDue, ':status' => $newStatus, ':id' => $pid]);

            // ── Cashbook entry: build INSERT dynamically to match actual schema ──
            $lastBal = $pdo->query("SELECT balance FROM cashbook WHERE voided = 0 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $prevBal = (float)($lastBal['balance'] ?? 0);
            $newBal  = round($prevBal - $thisAmt, 2);
            $desc    = "Payment to {$supplier['supplier_name']} for {$invoiceNo}" . ($notes ? " — {$notes}" : '');

            $cbFields = ['entry_date', 'entry_type', 'reference_id', 'reference_type', 'description', 'debit', 'credit', 'balance'];
            $cbPlaces = [':cbdate', "'purchase_payment'", ':cbrid', "'purchase'", ':cbdesc', ':cbdebit', '0', ':cbbal'];
            $cbParams = [':cbdate' => $payDate, ':cbrid' => $pid, ':cbdesc' => $desc, ':cbdebit' => $thisAmt, ':cbbal' => $newBal];
            if ($cbHasPayMethod) {
                $cbFields[] = 'payment_method'; $cbPlaces[] = ':cbmethod'; $cbParams[':cbmethod'] = $method;
            }
            if ($cbUserCol) {
                $cbFields[] = $cbUserCol; $cbPlaces[] = ':cbuser'; $cbParams[':cbuser'] = $userId;
            }
            $pdo->prepare("INSERT INTO cashbook (" . implode(', ', $cbFields) . ") VALUES (" . implode(', ', $cbPlaces) . ")")
                ->execute($cbParams);
            // ── End cashbook entry ─────────────────────────────────────────────

            $remaining -= $thisAmt;
        }

        // Update supplier balance inside transaction using pre-computed values
        // (avoid calling getSupplierTotalOutstanding() before commit — reads uncommitted rows)
        $pdo->prepare("UPDATE suppliers SET current_balance = :b WHERE id = :sid")
            ->execute([':b' => max(0, $totalNetDue - $amount), ':sid' => $supplier_id]);

        $pdo->commit();

        // Post-commit: accurate recalculation now all rows are committed
        $newOutstanding = getSupplierTotalOutstanding($supplier_id);
        $pdo->prepare("UPDATE suppliers SET current_balance = :b WHERE id = :sid")
            ->execute([':b' => $newOutstanding, ':sid' => $supplier_id]);

        if (function_exists('clearAllCaches')) clearAllCaches();
        if (function_exists('invalidateDashboardCache')) invalidateDashboardCache();
        if (function_exists('logActivity')) {
            logActivity('supplier_payment', "Rs " . number_format($amount, 2) . " paid to {$supplier['supplier_name']}");
        }

        // Determine new payment status for the specific purchase (for view.php badge update)
        $newStatus = 'partial';
        if ($specificPid > 0 && !empty($purchaseDues)) {
            $pur0      = $purchaseDues[0];
            $newPaid0  = (float)$pur0['paid_amount'] + min($amount, (float)$pur0['net_due']);
            $newDue0   = max(0, (float)$pur0['net_total'] - $newPaid0);
            $newStatus = ($newDue0 <= 0.009) ? 'paid' : ($newPaid0 > 0 ? 'partial' : 'pending');
        }

        echo json_encode([
            'success'     => true,
            'message'     => 'Payment of Rs ' . number_format($amount, 2) . ' recorded successfully.',
            'new_balance' => $newOutstanding,
            'new_status'  => $newStatus,
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("pay.php AJAX error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to record payment: ' . $e->getMessage()]);
        exit;
    }
}

/* ── Handle Regular Form Payment Submission ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $amount  = round((float)($_POST['amount'] ?? 0), 2);
        $payDate = $_POST['payment_date'] ?? date('Y-m-d');
        $method  = $_POST['payment_method'] ?? 'cash';
        $notes   = trim($_POST['notes'] ?? '');
        $pid     = (int)($_POST['purchase_id'] ?? 0);

        if ($amount <= 0) {
            $error = 'Payment amount must be greater than zero.';
        } elseif (!$pid) {
            $error = 'Please select a purchase to pay against.';
        } else {
            $dueInfo = getPurchaseNetDue($pid);
            if (isset($dueInfo['error'])) {
                $error = $dueInfo['error'];
            } else {
                $netDue = $dueInfo['net_due'];

                if ($amount > $netDue + 0.01) {
                    $error = sprintf(
                        'Payment amount (Rs %.2f) exceeds net due amount (Rs %.2f) after returns.',
                        $amount,
                        $netDue
                    );
                } else {
                    $pdo = _getPDO();

                    // Ensure columns exist
                    $spCols = $pdo->query("SHOW COLUMNS FROM supplier_payments")->fetchAll(PDO::FETCH_COLUMN);
                    if (!in_array('created_by', $spCols)) {
                        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN created_by INT NULL DEFAULT NULL");
                        $spCols[] = 'created_by';
                    }
                    if (!in_array('reference_no', $spCols)) {
                        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN reference_no VARCHAR(100) NULL DEFAULT NULL");
                        $spCols[] = 'reference_no';
                    }
                    if (!in_array('payment_method', $spCols)) {
                        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN payment_method VARCHAR(50) NULL DEFAULT 'cash'");
                        $spCols[] = 'payment_method';
                    }
                    $spHasPaymentMethod = in_array('payment_method', $spCols);

                    $cbCols = $pdo->query("SHOW COLUMNS FROM cashbook")->fetchAll(PDO::FETCH_COLUMN);
                    // Detect actual column names — cashbook may use 'added_by' not 'created_by'
                    // and may not have a 'payment_method' column
                    $cbUserCol      = in_array('added_by', $cbCols) ? 'added_by' : (in_array('created_by', $cbCols) ? 'created_by' : null);
                    $cbHasPayMethod = in_array('payment_method', $cbCols);
                    if (!in_array('voided', $cbCols)) {
                        $pdo->exec("ALTER TABLE cashbook ADD COLUMN voided TINYINT(1) NOT NULL DEFAULT 0");
                    }

                    $pdo->beginTransaction();
                    try {
                        $userId = (int)($_SESSION['user_id'] ?? 1);
                        $invoiceNo = $dueInfo['invoice_no'] ?? "Purchase #{$pid}";

                        // 1. Insert supplier_payments record
                        if ($spHasPaymentMethod) {
                            $pdo->prepare(
                                "INSERT INTO supplier_payments
                                    (supplier_id, purchase_id, amount, payment_date, payment_method, notes, created_by, reference_no)
                                 VALUES (:sid, :pid, :amt, :date, :method, :notes, :user, :refno)"
                            )->execute([
                                ':sid'    => $supplier_id,
                                ':pid'    => $pid,
                                ':amt'    => $amount,
                                ':date'   => $payDate,
                                ':method' => $method,
                                ':notes'  => $notes ?: null,
                                ':user'   => $userId,
                                ':refno'  => $invoiceNo,
                            ]);
                        } else {
                            $pdo->prepare(
                                "INSERT INTO supplier_payments
                                    (supplier_id, purchase_id, amount, payment_date, notes, created_by, reference_no)
                                 VALUES (:sid, :pid, :amt, :date, :notes, :user, :refno)"
                            )->execute([
                                ':sid'    => $supplier_id,
                                ':pid'    => $pid,
                                ':amt'    => $amount,
                                ':date'   => $payDate,
                                ':notes'  => $notes ?: null,
                                ':user'   => $userId,
                                ':refno'  => $invoiceNo,
                            ]);
                        }
                        $paymentId = (int)$pdo->lastInsertId();
                        if (!$paymentId) throw new Exception("Failed to insert payment record.");

                        // 2. Update purchase: paid_amount, due_amount, payment_status
                        $newPaid   = $dueInfo['paid_amount'] + $amount;
                        $newNetDue = max(0, $dueInfo['net_total'] - $newPaid);
                        $newStatus = ($newNetDue <= 0.009) ? 'paid' : ($newPaid > 0 ? 'partial' : 'pending');

                        $pdo->prepare(
                            "UPDATE purchases SET paid_amount = :paid, due_amount = :due, payment_status = :status WHERE id = :id"
                        )->execute([':paid' => $newPaid, ':due' => $newNetDue, ':status' => $newStatus, ':id' => $pid]);

                        // 3. Cashbook entry — build INSERT dynamically to match actual schema
                        $lastBal = $pdo->query("SELECT balance FROM cashbook WHERE voided = 0 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                        $prevBal = (float)($lastBal['balance'] ?? 0);
                        $newBal  = round($prevBal - $amount, 2);
                        $desc    = "Payment to {$supplier['supplier_name']} for {$invoiceNo}" . ($notes ? " — {$notes}" : '');

                        $cbFields = ['entry_date', 'entry_type', 'reference_id', 'reference_type', 'description', 'debit', 'credit', 'balance'];
                        $cbPlaces = [':cbdate', "'purchase_payment'", ':cbrid', "'purchase'", ':cbdesc', ':cbdebit', '0', ':cbbal'];
                        $cbParams = [':cbdate' => $payDate, ':cbrid' => $pid, ':cbdesc' => $desc, ':cbdebit' => $amount, ':cbbal' => $newBal];
                        if ($cbHasPayMethod) {
                            $cbFields[] = 'payment_method'; $cbPlaces[] = ':cbmethod'; $cbParams[':cbmethod'] = $method;
                        }
                        if ($cbUserCol) {
                            $cbFields[] = $cbUserCol; $cbPlaces[] = ':cbuser'; $cbParams[':cbuser'] = $userId;
                        }
                        $pdo->prepare("INSERT INTO cashbook (" . implode(', ', $cbFields) . ") VALUES (" . implode(', ', $cbPlaces) . ")")
                            ->execute($cbParams);

                        // 4. Update supplier balance (rough estimate inside tx)
                        $pdo->prepare("UPDATE suppliers SET current_balance = :b WHERE id = :sid")
                            ->execute([':b' => max(0, $netDue - $amount), ':sid' => $supplier_id]);

                        $pdo->commit();

                        // Post-commit: accurate recalculation now rows are committed
                        $newOutstanding = getSupplierTotalOutstanding($supplier_id);
                        _pdoRun("UPDATE suppliers SET current_balance = :b WHERE id = :sid",
                            [':b' => $newOutstanding, ':sid' => $supplier_id]);

                        // Post-commit sync
                        if (function_exists('syncAfterSupplierPayment')) {
                            syncAfterSupplierPayment($paymentId, $pid, $supplier_id, $amount, $method);
                        }

                        if (function_exists('clearAllCaches')) clearAllCaches();
                        if (function_exists('invalidateDashboardCache')) invalidateDashboardCache();

                        if (function_exists('logActivity')) {
                            logActivity('supplier_payment', "Rs " . number_format($amount, 2) . " paid to {$supplier['supplier_name']} for {$invoiceNo}");
                        }

                        $_SESSION['last_payment'] = [
                            'supplier'  => $supplier['supplier_name'],
                            'amount'    => $amount,
                            'date'      => $payDate,
                            'method'    => $method,
                            'reference' => $invoiceNo,
                            'notes'     => $notes,
                        ];

                        setFlash('success', "Payment of Rs " . number_format($amount, 2) . " recorded for {$supplier['supplier_name']}!", 'success');
                        header("Location: statement.php?supplier_id={$supplier_id}");
                        exit;
                        
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $error = 'Failed to record payment: ' . $e->getMessage();
                        error_log("pay.php error: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

// Get fresh data for display
$pendingPurchases = getSupplierPendingPurchasesWithNetDue($supplier_id);
$totalOutstanding = getSupplierTotalOutstanding($supplier_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?php echo $page_title; ?></title>
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>


<style>
:root {
    --pharmacy-primary: #0d6efd;
    --pharmacy-success: #198754;
    --pharmacy-danger: #dc3545;
    --pharmacy-warning: #ffc107;
    --pharmacy-text: #212529;
    --pharmacy-border: #dee2e6;
}
body { background: #f0f2f5; }
.main-content { margin-left: var(--sb-w, 260px); transition: margin-left .25s ease; padding: 20px; min-height: 100vh; }
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
.card-custom { background: #fff; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,.05); margin-bottom: 20px; overflow: hidden; }
.card-header-custom { background: #fff; border-bottom: 2px solid #eef2f6; padding: 15px 20px; font-weight: 600; }
.balance-badge { font-size: 28px; font-weight: 700; color: #dc3545; }
.btn-pay { background: linear-gradient(135deg,#28a745,#20c997); color: #fff; border: none; }
.btn-pay:hover { background: linear-gradient(135deg,#1e7e34,#1aa179); color: #fff; }
.purchase-card { border: 1px solid #dee2e6; border-radius: 10px; padding: 12px 16px; margin-bottom: 10px; cursor: pointer; transition: all .2s; }
.purchase-card:hover, .purchase-card.selected { border-color: #28a745; background: #f0fff4; }
.purchase-card.selected { border-width: 2px; background: #e8f5e9; }
.method-btn { border: 2px solid #dee2e6; border-radius: 8px; padding: 8px 16px; cursor: pointer; transition: all .2s; background: #fff; display: inline-flex; align-items: center; }
.method-btn.active { border-color: #28a745; background: #f0fff4; color: #155724; }
.return-badge { background: #fff3cd; color: #856404; font-size: 0.78em; padding: 2px 6px; border-radius: 4px; }
.net-due { font-size: 14px; font-weight: 700; color: #dc3545; }
.stat-card { background: #fff; border-radius: 12px; padding: 15px; border-left: 4px solid var(--pharmacy-primary); box-shadow: 0 1px 3px rgba(0,0,0,.05); }
.stat-value { font-size: 22px; font-weight: 700; }
.stat-label { font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
@media print { .no-print, .btn, .navbar, .sidebar, .action-buttons { display: none !important; } }
</style>
</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">
<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div>
        <h4 class="mb-1"><i class="fas fa-money-bill-wave me-2 text-success"></i>Record Supplier Payment</h4>
        <p class="text-muted mb-0">Pay against supplier outstanding invoices (returns are automatically deducted)</p>
    </div>
    <div class="d-flex gap-2">
        <a href="../purchases/list.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-shopping-cart me-1"></i> Purchases
        </a>
        <a href="../suppliers/list.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Suppliers
        </a>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4 no-print">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-label">Total Outstanding</div>
            <div class="stat-value text-danger">Rs <?php echo number_format($totalOutstanding, 2); ?></div>
            <small class="text-muted">After deducting returns</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card" style="border-left-color: #17a2b8;">
            <div class="stat-label">Pending Invoices</div>
            <div class="stat-value text-info"><?php echo count($pendingPurchases); ?></div>
            <small class="text-muted">Awaiting payment</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card" style="border-left-color: #28a745;">
            <div class="stat-label">Total Paid (History)</div>
            <div class="stat-value text-success">Rs <?php echo number_format(array_sum(array_column($payments, 'amount')), 2); ?></div>
            <small class="text-muted">All time payments</small>
        </div>
    </div>
</div>

<div class="row">
    <!-- Left: Supplier Info + Payment Form -->
    <div class="col-lg-7">

        <!-- Supplier Card -->
        <div class="card-custom p-4 mb-4">
            <div class="d-flex align-items-center gap-3">
                <div style="width:60px;height:60px;background:#d4edda;border-radius:50%;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-building fa-lg text-success"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="fs-5 fw-bold"><?php echo htmlspecialchars($supplier['supplier_name']); ?></div>
                    <div class="text-muted small">
                        <?php if (!empty($supplier['phone'])): ?>
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($supplier['phone']); ?>&nbsp;&nbsp;
                        <?php endif; ?>
                        <?php if (!empty($supplier['contact_person'])): ?>
                        <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($supplier['contact_person']); ?>
                        <?php endif; ?>
                        <?php if (!empty($supplier['supplier_code'])): ?>
                        <br><i class="fas fa-barcode me-1"></i><?php echo htmlspecialchars($supplier['supplier_code']); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-end">
                    <div class="text-muted small">Current Balance</div>
                    <div class="balance-badge">Rs <?php echo number_format($supplier['current_balance'] ?? $totalOutstanding, 2); ?></div>
                    <?php if (($supplier['current_balance'] ?? 0) <= 0): ?>
                    <span class="badge bg-success mt-1">✓ Fully Paid</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Select Purchase -->
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-file-invoice me-2 text-warning"></i>
                Outstanding Purchases <span class="badge bg-warning text-dark ms-2"><?php echo count($pendingPurchases); ?></span>
                <small class="text-muted float-end">Click to select</small>
            </div>
            <div class="card-body p-4">
                <?php if (empty($pendingPurchases)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-check-circle fa-3x text-success mb-3 d-block"></i>
                    <strong>All purchases are paid!</strong><br>No outstanding balance.
                    <div class="mt-3">
                        <a href="../purchases/add.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus me-1"></i> Add New Purchase
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <p class="text-muted small mb-3">
                    <i class="fas fa-info-circle me-1"></i> 
                    Due amounts shown below are <strong>NET of returns</strong>.
                </p>
                <?php foreach ($pendingPurchases as $p):
                    $netDue = (float)($p['net_due'] ?? 0);
                    $sel = ($purchase_id == $p['id']) ? 'selected' : '';
                ?>
                <div class="purchase-card <?php echo $sel; ?>"
                     data-pid="<?php echo $p['id']; ?>"
                     data-due="<?php echo $netDue; ?>"
                     data-invoice="<?php echo htmlspecialchars($p['purchase_invoice_no']); ?>"
                     onclick="selectPurchase(<?php echo $p['id']; ?>, <?php echo $netDue; ?>, '<?php echo htmlspecialchars($p['purchase_invoice_no']); ?>', this)">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong class="text-primary"><?php echo htmlspecialchars($p['purchase_invoice_no']); ?></strong>
                            <span class="ms-2 badge bg-<?php echo $p['payment_status'] === 'partial' ? 'warning text-dark' : 'danger'; ?>">
                                <?php echo ucfirst($p['payment_status']); ?>
                            </span>
                            <?php if (($p['total_returned'] ?? 0) > 0): ?>
                            <span class="ms-1 return-badge">
                                <i class="fas fa-undo me-1"></i>Returns: Rs <?php echo number_format($p['total_returned'], 2); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <div class="small text-muted"><?php echo date('d M Y', strtotime($p['purchase_date'])); ?></div>
                            <div class="net-due">Due: Rs <?php echo number_format($netDue, 2); ?></div>
                        </div>
                    </div>
                    <div class="small text-muted mt-1">
                        Gross: Rs <?php echo number_format($p['total'], 2); ?>
                        &nbsp;|&nbsp;Paid: Rs <?php echo number_format($p['paid_amount'], 2); ?>
                        <?php if (($p['total_returned'] ?? 0) > 0): ?>
                        &nbsp;|&nbsp;<span class="text-warning">Net: Rs <?php echo number_format($p['net_total'], 2); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Form -->
        <?php if (!empty($pendingPurchases)): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-money-bill-wave me-2 text-success"></i> Payment Details
            </div>
            <div class="card-body p-4">
                <form method="POST" action="pay.php?supplier_id=<?php echo $supplier_id; ?>" id="paymentForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="purchase_id" id="selected_purchase_id" value="<?php echo $purchase_id; ?>">
                    <input type="hidden" name="record_payment" value="1">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Selected Invoice</label>
                            <div class="form-control bg-light" id="selectedInvoiceDisplay">
                                <?php if ($purchase_id): ?>
                                    <?php 
                                    $selected = array_filter($pendingPurchases, fn($p) => $p['id'] == $purchase_id);
                                    $selected = reset($selected);
                                    echo htmlspecialchars($selected['purchase_invoice_no'] ?? 'Select an invoice');
                                    ?>
                                <?php else: ?>
                                    No invoice selected
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Payment Amount (Rs) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount" id="pay_amount"
                                   class="form-control form-control-lg"
                                   placeholder="0.00" min="0.01" required>
                            <div class="form-text" id="due_hint">
                                Select a purchase above to see due amount.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" class="form-control"
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php foreach (['cash' => 'fa-money-bill', 'bank' => 'fa-university', 'cheque' => 'fa-file-alt', 'online' => 'fa-mobile-alt'] as $m => $icon): ?>
                                <label class="method-btn <?php echo $m === 'cash' ? 'active' : ''; ?>" id="meth_<?php echo $m; ?>">
                                    <input type="radio" name="payment_method" value="<?php echo $m; ?>"
                                           <?php echo $m === 'cash' ? 'checked' : ''; ?>
                                           class="d-none" onchange="setMethod('<?php echo $m; ?>')">
                                    <i class="fas <?php echo $icon; ?> me-1"></i><?php echo ucfirst($m); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Reference / Notes</label>
                            <input type="text" name="notes" class="form-control"
                                   placeholder="Cheque no, transaction ID, or notes">
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-3">
                        <button type="button" class="btn btn-pay btn-lg px-4" id="btnRecordPayment" onclick="submitPayment()">
                            <i class="fas fa-check me-2"></i> Record Payment
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-lg" id="btnPayFull" onclick="fillDueAmount()">
                            <i class="fas fa-fill me-2"></i> Pay Full Due
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: Payment History -->
    <div class="col-lg-5">
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-history me-2 text-primary"></i> Payment History
                <span class="badge bg-secondary float-end"><?php echo count($payments); ?> payments</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($payments)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-receipt fa-3x mb-3 d-block opacity-50"></i>
                    No payments recorded yet.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Invoice</th>
                                <th>Method</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td>
                                    <strong><?php echo date('d M Y', strtotime($pay['payment_date'])); ?></strong>
                                    <?php if (!empty($pay['notes'])): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($pay['notes']); ?></small>
                                    <?php endif; ?>
                                 </td>
                                 <td>
                                    <?php if ($pay['purchase_id']): ?>
                                    <a href="../purchases/view.php?id=<?php echo $pay['purchase_id']; ?>"
                                       class="text-decoration-none small">
                                        <?php echo htmlspecialchars($pay['purchase_invoice_no'] ?? '#' . str_pad($pay['purchase_id'], 6, '0', STR_PAD_LEFT)); ?>
                                    </a>
                                    <?php else: ?>—<?php endif; ?>
                                 </td>
                                 <td>
                                    <span class="badge bg-secondary"><?php echo ucfirst($pay['payment_method'] ?? 'cash'); ?></span>
                                 </td>
                                 <td class="text-end">
                                    <strong class="text-success">Rs <?php echo number_format($pay['amount'], 2); ?></strong>
                                 </td>
                             </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="3" class="text-end fw-bold">Total Paid:</td>
                                <td class="text-end fw-bold text-success">Rs <?php echo number_format(array_sum(array_column($payments, 'amount')), 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>
</div>

<!-- Receipt (hidden, for printing) -->
<div id="receiptContainer" style="display:none;">
    <div id="receiptContent" style="width:300px;margin:0 auto;font-family:monospace;padding:20px;">
        <?php if (isset($_SESSION['last_payment'])): $pay = $_SESSION['last_payment']; ?>
        <h3 style="text-align:center;">PAYMENT RECEIPT</h3>
        <hr>
        <p><strong>Supplier:</strong> <?php echo htmlspecialchars($pay['supplier']); ?></p>
        <p><strong>Amount:</strong> Rs <?php echo number_format($pay['amount'], 2); ?></p>
        <p><strong>Date:</strong> <?php echo date('d-m-Y', strtotime($pay['date'])); ?></p>
        <p><strong>Method:</strong> <?php echo ucfirst($pay['method']); ?></p>
        <p><strong>Reference:</strong> <?php echo htmlspecialchars($pay['reference']); ?></p>
        <?php if ($pay['notes']): ?>
        <p><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($pay['notes'])); ?></p>
        <?php endif; ?>
        <hr>
        <p style="text-align:center;">Thank you!</p>
        <?php unset($_SESSION['last_payment']); ?>
        <?php endif; ?>
    </div>
</div>



<script>
var selectedDue = 0;
var selectedPid = <?php echo $purchase_id ?: 0; ?>;
var selectedInvoice = '';

// On load: pre-select purchase from URL if available
window.addEventListener('DOMContentLoaded', function() {
    var cards = document.querySelectorAll('.purchase-card');

    <?php if ($purchase_id && !empty($pendingPurchases)): ?>
    // Pre-select the purchase passed in URL
    <?php foreach ($pendingPurchases as $p): if ((int)$p['id'] === $purchase_id): ?>
    doSelect(<?php echo (int)$p['id']; ?>, <?php echo (float)$p['net_due']; ?>, '<?php echo addslashes($p['purchase_invoice_no']); ?>');
    var c = document.querySelector('.purchase-card[data-pid="<?php echo (int)$p['id']; ?>"]');
    if (c) c.classList.add('selected');
    <?php break; endif; endforeach; ?>
    <?php else: ?>
    // Auto-select first card if only one pending purchase
    if (cards.length === 1) {
        var pid = parseInt(cards[0].dataset.pid);
        var due = parseFloat(cards[0].dataset.due);
        var inv = cards[0].dataset.invoice || '';
        doSelect(pid, due, inv);
        cards[0].classList.add('selected');
    }
    <?php endif; ?>
});

function doSelect(pid, due, invoice) {
    selectedPid = pid;
    selectedDue = due;
    selectedInvoice = invoice;
    document.getElementById('selected_purchase_id').value = pid;
    document.getElementById('selectedInvoiceDisplay').innerHTML = invoice;
    document.getElementById('pay_amount').value = due.toFixed(2);
    document.getElementById('due_hint').innerHTML = 
        '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Net due after returns: Rs ' + due.toFixed(2) + '</span>';
}

function selectPurchase(pid, netDue, invoice, el) {
    document.querySelectorAll('.purchase-card').forEach(function(c) { 
        c.classList.remove('selected'); 
    });
    el.classList.add('selected');
    doSelect(pid, netDue, invoice);
}

function fillDueAmount() {
    if (selectedPid <= 0) {
        // Try to auto-select first card
        var first = document.querySelector('.purchase-card');
        if (first) {
            var pid = parseInt(first.dataset.pid);
            var due = parseFloat(first.dataset.due);
            var inv = first.dataset.invoice || '';
            doSelect(pid, due, inv);
            document.querySelectorAll('.purchase-card').forEach(function(c){ c.classList.remove('selected'); });
            first.classList.add('selected');
        } else {
            Notify.info('No pending purchases found.');
        }
        return;
    }
    document.getElementById('pay_amount').value = selectedDue.toFixed(2);
}

function submitPayment() {
    if (selectedPid <= 0) {
        Notify.warning('Please select a purchase first by clicking on one of the invoices above.');
        return;
    }
    var amt = parseFloat(document.getElementById('pay_amount').value);
    if (!amt || amt <= 0) {
        Notify.warning('Please enter a valid payment amount.');
        return;
    }
    if (amt > selectedDue + 0.01) {
        Notify.warning('Payment amount cannot exceed the net due amount of Rs ' + selectedDue.toFixed(2));
        return;
    }
    document.getElementById('selected_purchase_id').value = selectedPid;
    document.getElementById('paymentForm').submit();
}

function setMethod(m) {
    document.querySelectorAll('.method-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('meth_' + m).classList.add('active');
}

function printReceipt() {
    var receiptHtml = document.getElementById('receiptContent').outerHTML;
    var w = window.open('', '_blank', 'width=400,height=600');
    w.document.write('<html><head><title>Payment Receipt</title>');
    w.document.write('<style>body{font-family:monospace;padding:20px;}</style>');
    w.document.write('</head><body>');
    w.document.write(receiptHtml);
    w.document.write('</body></html>');
    w.document.close();
    w.print();
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>