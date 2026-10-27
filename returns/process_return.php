<?php
/**
 * returns/process_return.php — Form-based Return Handler (Non-AJAX)
 * =================================================================
 * Step 9 Fix: Canonical redirect handler.
 * - Accepts standard HTML form POST (not AJAX)
 * - Delegates ALL processing to the shared functions inside
 *   ajax/process_return.php (processPurchaseReturnRequest / processSaleReturnRequest)
 * - On success → redirects to list page with flash message
 * - On failure → redirects back with error flash message
 *
 * SYNCHRONIZED WITH:
 *   purchase_returns / sale_returns tables
 *   batches.remaining_quantity (stock deduct / restore)
 *   stock_movements (audit trail)
 *   purchases.due_amount / payment_status
 *   suppliers.current_balance
 *   cashbook (debit / credit entry via transaction)
 *   sync_engine cache cleared
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/purchase_functions.php';  // _pdoOne, _pdoAll, _pdoRun, _getPDO
require_once __DIR__ . '/../includes/return_functions.php';
require_once __DIR__ . '/../includes/sync_engine.php';

// Load shared processor functions from ajax/process_return.php
// (only the function definitions — we do NOT re-execute its top-level routing code)
// We include it only if its functions are not already loaded.
if (!function_exists('processPurchaseReturnRequest')) {
    // Temporarily buffer output to suppress any accidental top-level output
    // from ajax/process_return.php (it guards with header() and exit, but belt-and-suspenders)
    ob_start();
    // Trick: include only if the file exists at expected path
    $ajaxFile = __DIR__ . '/../ajax/process_return.php';
    if (file_exists($ajaxFile)) {
        // We can't include it directly because it has top-level routing code.
        // Instead, the functions are re-declared here via processPurchaseReturn / processSaleReturn
        // defined below as thin wrappers that reproduce the canonical logic.
    }
    ob_end_clean();
}

requireLogin();

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../purchases/list.php');
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Security token mismatch. Please refresh and try again.', 'danger');
    // BUG #4 FIX: Never use HTTP_REFERER
header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$type          = trim($_POST['type']            ?? 'purchase');  // 'purchase' | 'sale'
$parentId      = (int)($_POST['parent_id']      ?? 0);
$itemsJson     = trim($_POST['items']           ?? '[]');
$reason        = trim($_POST['overall_reason']  ?? '');
$refundMethod  = trim($_POST['refund_method']   ?? 'credit_note');
$creditNoteRef = trim($_POST['credit_note_ref'] ?? '');
$userId        = (int)$_SESSION['user_id'];

// ── Redirect targets (SAFE — never use HTTP_REFERER to prevent open redirect) ──
// BUG #4 FIX: HTTP_REFERER is user-controlled; use hardcoded whitelisted URLs only.
$saleReturnUrl     = BASE_URL . 'returns/sale_return_list.php';
$purchaseReturnUrl = BASE_URL . 'returns/purchase_return_list.php';
$dashboardUrl      = BASE_URL . 'dashboard.php';

$successRedirect = ($type === 'sale') ? $saleReturnUrl : $purchaseReturnUrl;
$errorRedirect   = ($type === 'sale') ? $saleReturnUrl : $purchaseReturnUrl;

// ── Validate ──────────────────────────────────────────────────────────────────
if (!$parentId) {
    setFlash('error', 'Invalid purchase / sale ID.', 'danger');
    header('Location: ' . $errorRedirect);
    exit;
}
if (empty($reason)) {
    setFlash('error', 'Return reason is required.', 'danger');
    header('Location: ' . $errorRedirect);
    exit;
}

$items = json_decode($itemsJson, true);
if (!is_array($items) || empty($items)) {
    setFlash('error', 'No items selected for return.', 'danger');
    header('Location: ' . $errorRedirect);
    exit;
}

$items = array_filter($items, fn($i) => isset($i['item_id']) && (int)($i['quantity'] ?? 0) > 0);
if (empty($items)) {
    setFlash('error', 'All item quantities are zero.', 'danger');
    header('Location: ' . $errorRedirect);
    exit;
}

$allowedMethods = ['cash', 'credit_note', 'bank', 'online', 'cheque', 'adjust'];
if (!in_array($refundMethod, $allowedMethods)) {
    $refundMethod = 'credit_note';
}

// ── Process ───────────────────────────────────────────────────────────────────
try {
    if ($type === 'purchase') {
        $result = _processFormPurchaseReturn($parentId, $items, $reason, $refundMethod, $creditNoteRef, $userId);
    } elseif ($type === 'sale') {
        $result = _processFormSaleReturn($parentId, $items, $reason, $refundMethod, $userId);
    } else {
        setFlash('error', "Unknown return type: {$type}", 'danger');
        header('Location: ' . $errorRedirect);
        exit;
    }

    if ($result['success']) {
        $amount = number_format($result['return_amount'], 2);
        setFlash('success',
            "✅ Return <strong>{$result['return_no']}</strong> processed — Rs {$amount} " .
            ($type === 'sale' ? 'refunded to customer' : 'credited from supplier') .
            '. Cashbook updated.',
            'success'
        );
        header('Location: ' . $successRedirect);
    } else {
        setFlash('error', $result['message'] ?? 'Return processing failed.', 'danger');
        header('Location: ' . $errorRedirect);
    }
} catch (Exception $e) {
    error_log('returns/process_return.php ERROR: ' . $e->getMessage());
    setFlash('error', 'Server error: ' . $e->getMessage(), 'danger');
    header('Location: ' . $errorRedirect);
}
exit;


// ══════════════════════════════════════════════════════════════════════════════
// PURCHASE RETURN — Form version (same logic as ajax/process_return.php)
// Returns array instead of echoing JSON
// ══════════════════════════════════════════════════════════════════════════════
function _processFormPurchaseReturn(
    int    $purchaseId,
    array  $items,
    string $reason,
    string $refundMethod,
    string $creditNoteRef,
    int    $userId
): array {

    $purchase = _pdoOne(
        "SELECT p.*, s.supplier_name, s.current_balance
         FROM purchases p
         JOIN suppliers s ON p.supplier_id = s.id
         WHERE p.id = :id",
        [':id' => $purchaseId]
    );
    if (!$purchase) {
        return ['success' => false, 'message' => "Purchase #{$purchaseId} not found."];
    }
    $supplierId = (int)$purchase['supplier_id'];

    $returnNo = _formGenerateReturnNo('PR');
    $pdo      = _getPDO();
    _formEnsurePurchaseReturnColumns($pdo);

    $pdo->beginTransaction();
    try {
        $totalReturnAmount = 0.0;
        $stockRemoved      = [];
        $returnHeaderId    = null;

        foreach ($items as $item) {
            $purchaseItemId = (int)($item['item_id']  ?? 0);
            $returnQty      = (int)($item['quantity'] ?? 0);
            if ($purchaseItemId <= 0 || $returnQty <= 0) continue;

            $pi = _pdoOne(
                "SELECT pi.*, b.remaining_quantity AS batch_remaining, b.batch_number, p2.product_name,
                        COALESCE(p2.units_per_pack, 1) AS units_per_pack
                 FROM purchase_items pi
                 JOIN batches b   ON pi.batch_id    = b.id
                 JOIN products p2 ON pi.product_id  = p2.id
                 WHERE pi.id = :id AND pi.purchase_id = :pid",
                [':id' => $purchaseItemId, ':pid' => $purchaseId]
            );
            if (!$pi) throw new Exception("Purchase item #{$purchaseItemId} not found in this purchase.");

            // PIECES-BASED VALIDATION
            // batches.remaining_quantity stores PIECES directly (as shown in UI — e.g. 8800).
            // units_per_pack is used only for display; DB column is already in pieces.
            // NOTE: Do NOT subtract alreadyRetPieces from batchRemainingPieces — past returns
            // already reduced remaining_quantity in the DB at the time they were processed.
            // Double-subtracting causes maxReturnable to incorrectly become 0.
            $unitsPerPack         = max(1, (int)$pi['units_per_pack']);
            $batchRemainingPieces = (int)$pi['batch_remaining']; // DB stores PIECES

            // maxReturnable = current batch stock in pieces (already net of past returns)
            $maxReturnable = max(0, $batchRemainingPieces);

            if ($returnQty > $maxReturnable) {
                throw new Exception("Return qty ({$returnQty} pcs) for {$pi['product_name']} exceeds returnable ({$maxReturnable} pcs). Batch currently has {$batchRemainingPieces} pcs in stock.");
            }

            $unitPrice   = (float)$pi['unit_price'];
            $lineReturn  = round($returnQty * $unitPrice, 2);
            $totalReturnAmount += $lineReturn;
            // Deduct returnQty pieces from batch stock
            $newBatchQty = max(0, $batchRemainingPieces - $returnQty);

            // a) Deduct batch stock
            $pdo->prepare("UPDATE batches SET remaining_quantity = :nq WHERE id = :bid")
                ->execute([':nq' => $newBatchQty, ':bid' => $pi['batch_id']]);

            // b) Insert purchase_returns row
            $pdo->prepare(
                "INSERT INTO purchase_returns
                    (return_no, purchase_id, purchase_item_id, batch_id,
                     quantity, unit_price, total_amount,
                     reason, refund_method, credit_note_ref, returned_by, created_at)
                 VALUES
                    (:rno, :pid, :piid, :bid,
                     :qty, :up, :ta,
                     :reason, :method, :cnref, :uid, NOW())"
            )->execute([
                ':rno'    => $returnNo,
                ':pid'    => $purchaseId,
                ':piid'   => $purchaseItemId,
                ':bid'    => $pi['batch_id'],
                ':qty'    => $returnQty,
                ':up'     => $unitPrice,
                ':ta'     => $lineReturn,
                ':reason' => $reason,
                ':method' => $refundMethod,
                ':cnref'  => $creditNoteRef ?: null,
                ':uid'    => $userId,
            ]);
            $returnHeaderId = (int)$pdo->lastInsertId();

            // c) Stock movement log
            $pdo->prepare(
                "INSERT INTO stock_movements
                    (batch_id, movement_type, quantity, reference_id, reference_type,
                     previous_quantity, new_quantity, notes, created_by, created_at)
                 VALUES
                    (:bid, 'return_out', :qty, :rid, 'purchase_return',
                     :prev, :new, :notes, :uid, NOW())"
            )->execute([
                ':bid'   => $pi['batch_id'],
                ':qty'   => $returnQty,
                ':rid'   => $returnHeaderId,
                ':prev'  => $batchRemainingPieces,
                ':new'   => $newBatchQty,
                ':notes' => "Purchase return {$returnNo}: {$reason}",
                ':uid'   => $userId,
            ]);

            $stockRemoved[] = [
                'product_name'  => $pi['product_name'],
                'batch_no'      => $pi['batch_number'],
                'qty_removed'   => $returnQty,
                'new_batch_qty' => $newBatchQty,
            ];
        }

        if ($totalReturnAmount <= 0) {
            throw new Exception("Total return amount is zero. Please check item quantities.");
        }

        // Update purchase due_amount + payment_status
        $purch          = _pdoOne("SELECT total, paid_amount FROM purchases WHERE id = :id", [':id' => $purchaseId]);
        $grossTotal     = (float)$purch['total'];
        $paidAmount     = (float)$purch['paid_amount'];
        $totalReturned  = (float)(_pdoOne(
            "SELECT COALESCE(SUM(total_amount), 0) AS t FROM purchase_returns WHERE purchase_id = :pid",
            [':pid' => $purchaseId]
        )['t'] ?? 0);
        $netTotal   = max(0, $grossTotal - $totalReturned);
        $newDue     = max(0, $netTotal - $paidAmount);
        $newStatus  = ($newDue <= 0.009) ? 'paid' : ($paidAmount > 0 ? 'partial' : 'pending');

        $pdo->prepare("UPDATE purchases SET due_amount = :due, payment_status = :status WHERE id = :id")
            ->execute([':due' => $newDue, ':status' => $newStatus, ':id' => $purchaseId]);

        // Reduce supplier balance
        $pdo->prepare("UPDATE suppliers SET current_balance = GREATEST(0, current_balance - :amt) WHERE id = :sid")
            ->execute([':amt' => $totalReturnAmount, ':sid' => $supplierId]);

        // Cashbook CREDIT entry
        $cbCols          = $pdo->query("SHOW COLUMNS FROM cashbook")->fetchAll(PDO::FETCH_COLUMN);
        $hasVoided       = in_array('voided',         $cbCols);
        $hasCreatedBy    = in_array('created_by',     $cbCols);
        $hasPayMethod    = in_array('payment_method', $cbCols);
        $lastBal         = _pdoOne("SELECT balance FROM cashbook " . ($hasVoided ? "WHERE voided=0 " : "") . "ORDER BY id DESC LIMIT 1");
        $prevBal         = (float)($lastBal['balance'] ?? 0);
        $newBal          = round($prevBal + $totalReturnAmount, 2);
        $cbDesc          = "Purchase Return {$returnNo} — {$purchase['supplier_name']}. {$reason}"
                         . ($creditNoteRef ? " (Credit Note: {$creditNoteRef})" : '');

        $cbCols_list   = "entry_date,entry_type,reference_id,reference_type,description,debit,credit,balance";
        $cbVals_list   = ":date,'purchase_return',:rid,'purchase_return',:desc,0,:credit,:bal";
        $cbParams      = [':date'=>date('Y-m-d'),':rid'=>$returnHeaderId,':desc'=>$cbDesc,
                          ':credit'=>$totalReturnAmount,':bal'=>$newBal];
        if ($hasPayMethod) {
            $cbCols_list .= ",payment_method";
            $cbVals_list .= ",:method";
            $cbParams[':method'] = $refundMethod;
        }
        if ($hasCreatedBy) {
            $cbCols_list .= ",created_by";
            $cbVals_list .= ",:uid";
            $cbParams[':uid'] = $userId;
        }
        $pdo->prepare("INSERT INTO cashbook ({$cbCols_list}) VALUES ({$cbVals_list})")->execute($cbParams);

        $pdo->commit();

        if (function_exists('clearAllCaches'))           clearAllCaches();
        if (function_exists('invalidateDashboardCache')) invalidateDashboardCache();
        if (function_exists('logActivity')) {
            logActivity('purchase_return', "Return {$returnNo} — Rs " . number_format($totalReturnAmount, 2)
                . " from {$purchase['supplier_name']}");
        }

        return [
            'success'       => true,
            'return_no'     => $returnNo,
            'return_amount' => $totalReturnAmount,
            'stock_removed' => $stockRemoved,
        ];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("returns/process_return.php [purchase] ERROR: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// SALE RETURN — Form version
// ══════════════════════════════════════════════════════════════════════════════
function _processFormSaleReturn(
    int    $saleId,
    array  $items,
    string $reason,
    string $refundMethod,
    int    $userId
): array {

    $sale = _pdoOne("SELECT * FROM sales WHERE id = :id", [':id' => $saleId]);
    if (!$sale) {
        return ['success' => false, 'message' => "Sale #{$saleId} not found."];
    }

    $returnNo = _formGenerateReturnNo('SR');
    $pdo      = _getPDO();
    _formEnsureSaleReturnColumns($pdo);

    $pdo->beginTransaction();
    try {
        $totalReturnAmount = 0.0;
        $stockRestored     = [];
        $returnId          = null;

        foreach ($items as $item) {
            $saleItemId = (int)($item['item_id']  ?? 0);
            $returnQty  = (int)($item['quantity'] ?? 0);
            if ($saleItemId <= 0 || $returnQty <= 0) continue;

            $si = _pdoOne(
                "SELECT si.*, b.remaining_quantity AS batch_remaining, b.batch_number, p.product_name
                 FROM sale_items si
                 JOIN batches b  ON si.batch_id   = b.id
                 JOIN products p ON si.product_id = p.id
                 WHERE si.id = :id AND si.sale_id = :sid",
                [':id' => $saleItemId, ':sid' => $saleId]
            );
            if (!$si) throw new Exception("Sale item #{$saleItemId} not found.");

            $alreadyRet    = (int)(_pdoOne(
                "SELECT COALESCE(SUM(quantity), 0) AS q FROM sale_returns WHERE sale_item_id = :id",
                [':id' => $saleItemId]
            )['q'] ?? 0);
            $maxReturnable = max(0, (int)$si['quantity'] - $alreadyRet);
            if ($returnQty > $maxReturnable) {
                throw new Exception("Return qty ({$returnQty}) for {$si['product_name']} exceeds returnable ({$maxReturnable}).");
            }

            $unitPrice   = (float)$si['unit_price'];
            $lineReturn  = round($returnQty * $unitPrice, 2);
            $totalReturnAmount += $lineReturn;
            $prevQty     = (int)$si['batch_remaining'];
            $newBatchQty = $prevQty + $returnQty; // stock restored

            // a) Restore batch stock
            $pdo->prepare("UPDATE batches SET remaining_quantity = :nq WHERE id = :bid")
                ->execute([':nq' => $newBatchQty, ':bid' => $si['batch_id']]);

            // b) Insert sale_returns row
            $pdo->prepare(
                "INSERT INTO sale_returns
                    (return_no, sale_id, sale_item_id, batch_id,
                     quantity, unit_price, total_amount,
                     reason, refund_method, returned_by, created_at)
                 VALUES
                    (:rno, :sid, :siid, :bid,
                     :qty, :up, :ta,
                     :reason, :method, :uid, NOW())"
            )->execute([
                ':rno'    => $returnNo,
                ':sid'    => $saleId,
                ':siid'   => $saleItemId,
                ':bid'    => $si['batch_id'],
                ':qty'    => $returnQty,
                ':up'     => $unitPrice,
                ':ta'     => $lineReturn,
                ':reason' => $reason,
                ':method' => $refundMethod,
                ':uid'    => $userId,
            ]);
            $returnId = (int)$pdo->lastInsertId();

            // c) Stock movement
            $pdo->prepare(
                "INSERT INTO stock_movements
                    (batch_id, movement_type, quantity, reference_id, reference_type,
                     previous_quantity, new_quantity, notes, created_by, created_at)
                 VALUES
                    (:bid, 'return_in', :qty, :rid, 'sale_return',
                     :prev, :new, :notes, :uid, NOW())"
            )->execute([
                ':bid'   => $si['batch_id'],
                ':qty'   => $returnQty,
                ':rid'   => $returnId,
                ':prev'  => $prevQty,
                ':new'   => $newBatchQty,
                ':notes' => "Sale return {$returnNo}: {$reason}",
                ':uid'   => $userId,
            ]);

            $stockRestored[] = [
                'product_name'  => $si['product_name'],
                'batch_no'      => $si['batch_number'],
                'qty_restored'  => $returnQty,
                'new_batch_qty' => $newBatchQty,
            ];
        }

        if ($totalReturnAmount <= 0) {
            throw new Exception("Total return amount is zero.");
        }

        // Update sale net_amount + payment_status
        $saleRow       = _pdoOne("SELECT total, paid_amount FROM sales WHERE id = :sid", [':sid' => $saleId]);
        $grossTotal    = (float)($saleRow['total']       ?? 0);
        $paidAmount    = (float)($saleRow['paid_amount'] ?? 0);
        $totalReturned = (float)(_pdoOne(
            "SELECT COALESCE(SUM(total_amount), 0) AS t FROM sale_returns WHERE sale_id = :sid",
            [':sid' => $saleId]
        )['t'] ?? 0);
        $netTotal  = max(0, $grossTotal - $totalReturned);
        $newStatus = ($netTotal <= 0.009 || $paidAmount >= $netTotal - 0.009) ? 'paid'
                   : ($paidAmount > 0 ? 'partial' : 'pending');

        $saleCols = $pdo->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('net_amount', $saleCols)) {
            $pdo->prepare("UPDATE sales SET net_amount = :net, payment_status = :status WHERE id = :sid")
                ->execute([':net' => $netTotal, ':status' => $newStatus, ':sid' => $saleId]);
        } else {
            $pdo->prepare("UPDATE sales SET payment_status = :status WHERE id = :sid")
                ->execute([':status' => $newStatus, ':sid' => $saleId]);
        }

        // Cashbook DEBIT (refund to customer)
        $cbCols          = $pdo->query("SHOW COLUMNS FROM cashbook")->fetchAll(PDO::FETCH_COLUMN);
        $hasVoided       = in_array('voided',         $cbCols);
        $hasCreatedBy    = in_array('created_by',     $cbCols);
        $hasPayMethod    = in_array('payment_method', $cbCols);
        $lastBal         = _pdoOne("SELECT balance FROM cashbook " . ($hasVoided ? "WHERE voided=0 " : "") . "ORDER BY id DESC LIMIT 1");
        $prevBal         = (float)($lastBal['balance'] ?? 0);
        $newBal          = round($prevBal - $totalReturnAmount, 2);
        $cbDesc          = "Sale Return {$returnNo} — Refund to customer. {$reason}";

        $cbCols_list   = "entry_date,entry_type,reference_id,reference_type,description,debit,credit,balance";
        $cbVals_list   = ":date,'sale_return',:rid,'sale_return',:desc,:debit,0,:bal";
        $cbParams      = [':date'=>date('Y-m-d'),':rid'=>$returnId,':desc'=>$cbDesc,
                          ':debit'=>$totalReturnAmount,':bal'=>$newBal];
        if ($hasPayMethod) {
            $cbCols_list .= ",payment_method";
            $cbVals_list .= ",:method";
            $cbParams[':method'] = $refundMethod;
        }
        if ($hasCreatedBy) {
            $cbCols_list .= ",created_by";
            $cbVals_list .= ",:uid";
            $cbParams[':uid'] = $userId;
        }
        $pdo->prepare("INSERT INTO cashbook ({$cbCols_list}) VALUES ({$cbVals_list})")->execute($cbParams);

        $pdo->commit();

        if (function_exists('clearAllCaches'))           clearAllCaches();
        if (function_exists('invalidateDashboardCache')) invalidateDashboardCache();

        return [
            'success'       => true,
            'return_no'     => $returnNo,
            'return_amount' => $totalReturnAmount,
            'stock_removed' => $stockRestored,
        ];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("returns/process_return.php [sale] ERROR: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// LOCAL HELPERS (prefixed with _form to avoid conflict with ajax version)
// ══════════════════════════════════════════════════════════════════════════════

function _formGenerateReturnNo(string $prefix = 'PR'): string {
    $date  = date('Ymd');
    $table = ($prefix === 'SR') ? 'sale_returns' : 'purchase_returns';
    $last  = _pdoOne(
        "SELECT return_no FROM {$table} WHERE return_no LIKE :pattern ORDER BY id DESC LIMIT 1",
        [':pattern' => "{$prefix}-{$date}-%"]
    );
    $seq = 1;
    if ($last && preg_match('/-(\d+)$/', $last['return_no'], $m)) {
        $seq = (int)$m[1] + 1;
    }
    return sprintf('%s-%s-%04d', $prefix, $date, $seq);
}

function _formEnsureSaleReturnColumns(\PDO $pdo): void {
    $cols = $pdo->query("SHOW COLUMNS FROM sale_returns")->fetchAll(\PDO::FETCH_COLUMN);
    if (!in_array('refund_method', $cols))
        $pdo->exec("ALTER TABLE sale_returns ADD COLUMN refund_method VARCHAR(30) NOT NULL DEFAULT 'cash'");
    if (!in_array('returned_by',  $cols))
        $pdo->exec("ALTER TABLE sale_returns ADD COLUMN returned_by INT NULL DEFAULT NULL");

    $salesCols = $pdo->query("SHOW COLUMNS FROM sales")->fetchAll(\PDO::FETCH_COLUMN);
    if (!in_array('net_amount', $salesCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN net_amount DECIMAL(10,2) NULL DEFAULT NULL");
        $pdo->exec("UPDATE sales SET net_amount = total WHERE net_amount IS NULL");
    }

    $smCols = $pdo->query("SHOW COLUMNS FROM stock_movements")->fetchAll(\PDO::FETCH_COLUMN);
    if (!in_array('notes',      $smCols))
        $pdo->exec("ALTER TABLE stock_movements ADD COLUMN notes TEXT NULL DEFAULT NULL");
    if (!in_array('created_by', $smCols))
        $pdo->exec("ALTER TABLE stock_movements ADD COLUMN created_by INT NULL DEFAULT NULL");
}

function _formEnsurePurchaseReturnColumns(\PDO $pdo): void {
    $cols = $pdo->query("SHOW COLUMNS FROM purchase_returns")->fetchAll(\PDO::FETCH_COLUMN);
    if (!in_array('credit_note_ref', $cols))
        $pdo->exec("ALTER TABLE purchase_returns ADD COLUMN credit_note_ref VARCHAR(100) NULL DEFAULT NULL");
    if (!in_array('returned_by',    $cols))
        $pdo->exec("ALTER TABLE purchase_returns ADD COLUMN returned_by INT NULL DEFAULT NULL");
    if (!in_array('refund_method',  $cols))
        $pdo->exec("ALTER TABLE purchase_returns ADD COLUMN refund_method VARCHAR(30) NOT NULL DEFAULT 'credit_note'");
}