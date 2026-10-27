<?php
/**
 * ajax/process_return.php
 *
 * Unified AJAX endpoint for Purchase Returns and Sale Returns.
 * Called by purchase_return.php / sale_return.php via $.ajax POST.
 *
 * FULLY SYNCHRONIZED WITH:
 *   - purchase_returns / sale_returns tables
 *   - batches.remaining_quantity (stock)
 *   - stock_movements (audit trail)
 *   - purchases.due_amount / payment_status
 *   - suppliers.current_balance (reduced by return amount)
 *   - cashbook (credit for purchase return, debit for sale return)
 *   - sync_engine cache busted
 *   - [FIX] Sale return now calls syncAfterSaleReturn() to adjust customer credit
 *
 * Returns JSON: { success, return_no, return_amount, stock_removed[], supplier_new_balance }
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
requireLogin();
if (!canDo('returns', 'add')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
require_once __DIR__ . '/../includes/purchase_functions.php';  // _pdoOne, _pdoAll, _pdoRun, _getPDO
require_once __DIR__ . '/../includes/return_functions.php';
require_once __DIR__ . '/../includes/sync_engine.php';

// Always respond with JSON
header('Content-Type: application/json; charset=utf-8');

// ── Auth ───────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$csrfToken = trim($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$type          = trim($_POST['type']           ?? 'purchase');   // 'purchase' | 'sale'
$parentId      = (int)($_POST['parent_id']     ?? 0);
$itemsJson     = trim($_POST['items']          ?? '[]');
$reason        = trim($_POST['overall_reason'] ?? '');
$refundMethod  = trim($_POST['refund_method']  ?? 'credit_note');
$creditNoteRef = trim($_POST['credit_note_ref'] ?? '');
$userId        = (int)$_SESSION['user_id'];

// Validate
if (!$parentId) {
    echo json_encode(['success' => false, 'message' => 'Invalid purchase / sale ID.']);
    exit;
}
if (empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Return reason is required.']);
    exit;
}

$items = json_decode($itemsJson, true);
if (!is_array($items) || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'No items selected for return.']);
    exit;
}

// Sanitize items
$items = array_filter($items, fn($i) => isset($i['item_id']) && (int)($i['quantity'] ?? 0) > 0);
if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'All item quantities are zero.']);
    exit;
}

// ── Allowed refund methods ────────────────────────────────────────────────────
$allowedMethods = ['cash', 'credit_note', 'bank', 'online', 'cheque', 'adjust'];
if (!in_array($refundMethod, $allowedMethods)) {
    $refundMethod = 'credit_note';
}

// ── Route to processor ────────────────────────────────────────────────────────
if ($type === 'purchase') {
    processPurchaseReturnRequest($parentId, $items, $reason, $refundMethod, $creditNoteRef, $userId);
} elseif ($type === 'sale') {
    processSaleReturnRequest($parentId, $items, $reason, $refundMethod, $userId);
} else {
    echo json_encode(['success' => false, 'message' => "Unknown return type: {$type}"]);
}
exit;


// ══════════════════════════════════════════════════════════════════════════════
// PURCHASE RETURN PROCESSOR
// ══════════════════════════════════════════════════════════════════════════════
function processPurchaseReturnRequest(
    int    $purchaseId,
    array  $items,
    string $reason,
    string $refundMethod,
    string $creditNoteRef,
    int    $userId
): void {

    // 1. Load purchase + supplier
    $purchase = _pdoOne(
        "SELECT p.*, s.supplier_name, s.current_balance
         FROM purchases p
         JOIN suppliers s ON p.supplier_id = s.id
         WHERE p.id = :id",
        [':id' => $purchaseId]
    );
    if (!$purchase) {
        echo json_encode(['success' => false, 'message' => "Purchase #{$purchaseId} not found."]);
        return;
    }
    $supplierId = (int)$purchase['supplier_id'];

    // 2. Generate return number (PR-YYYYMMDD-XXXX, unique)
    $returnNo = generateReturnNo('PR');

    // 3. Auto-add missing columns (safe, outside transaction)
    $pdo = _getPDO();
    ensurePurchaseReturnColumns($pdo);

    // 4. Begin transaction
    $pdo->beginTransaction();
    try {
        $totalReturnAmount = 0.0;
        $stockRemoved      = [];
        $returnHeaderId    = null;

        foreach ($items as $item) {
            $purchaseItemId = (int)($item['item_id']  ?? 0);
            $returnQty      = (int)($item['quantity'] ?? 0);

            if ($purchaseItemId <= 0 || $returnQty <= 0) continue;

            // Load purchase item
            $pi = _pdoOne(
                "SELECT pi.*, b.remaining_quantity as batch_remaining, b.batch_number,
                        b.total_pieces, p2.product_name
                 FROM purchase_items pi
                 JOIN batches b  ON pi.batch_id    = b.id
                 JOIN products p2 ON pi.product_id = p2.id
                 WHERE pi.id = :id AND pi.purchase_id = :pid",
                [':id' => $purchaseItemId, ':pid' => $purchaseId]
            );
            if (!$pi) throw new Exception("Purchase item #{$purchaseItemId} not found in this purchase.");

            // FIX M-11: Only allow return of remaining (unsold) quantity
            // batch_remaining = total_pieces - quantity_already_sold
            $batchRemaining = (int)$pi['batch_remaining'];
            $maxReturnable  = max(0, $batchRemaining); // can only return what hasn't been sold

            if ($returnQty > $maxReturnable) {
                $alreadySold = (int)$pi['total_pieces'] - $batchRemaining;
                throw new Exception(
                    "Cannot return {$returnQty} of {$pi['product_name']}. " .
                    "Only {$maxReturnable} units are available for return ({$alreadySold} already sold)."
                );
            }

            $unitPrice   = (float)$pi['unit_price'];
            $lineReturn  = round($returnQty * $unitPrice, 2);
            $totalReturnAmount += $lineReturn;
            $newBatchQty = $batchRemaining - $returnQty;

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
                ':prev'  => $batchRemaining,
                ':new'   => $newBatchQty,
                ':notes' => "Purchase return {$returnNo}: {$reason}",
                ':uid'   => $userId,
            ]);

            $stockRemoved[] = [
                'product_name'   => $pi['product_name'],
                'batch_no'       => $pi['batch_number'],
                'qty_removed'    => $returnQty,
                'new_batch_qty'  => $newBatchQty,
            ];
        }

        if ($totalReturnAmount <= 0) {
            throw new Exception("Total return amount is zero. Please check item quantities.");
        }

        // 5. Update purchase: reduce due_amount, update payment_status
        $purch = _pdoOne(
            "SELECT total, paid_amount, due_amount, payment_status FROM purchases WHERE id = :id",
            [':id' => $purchaseId]
        );
        $grossTotal  = (float)$purch['total'];
        $paidAmount  = (float)$purch['paid_amount'];

        // Total returns after this one
        $totalReturnedSoFar = (float)(_pdoOne(
            "SELECT COALESCE(SUM(total_amount), 0) AS t FROM purchase_returns WHERE purchase_id = :pid",
            [':pid' => $purchaseId]
        )['t'] ?? 0);

        $netTotal   = max(0, $grossTotal - $totalReturnedSoFar);
        $newDue     = max(0, $netTotal - $paidAmount);
        $newStatus  = ($newDue <= 0.009) ? 'paid' : ($paidAmount > 0 ? 'partial' : 'pending');

        $pdo->prepare(
            "UPDATE purchases SET due_amount = :due, payment_status = :status WHERE id = :id"
        )->execute([':due' => $newDue, ':status' => $newStatus, ':id' => $purchaseId]);

        // 6. Reduce supplier current_balance
        $pdo->prepare(
            "UPDATE suppliers SET current_balance = GREATEST(0, current_balance - :amt) WHERE id = :sid"
        )->execute([':amt' => $totalReturnAmount, ':sid' => $supplierId]);

        // 7. Cashbook: CREDIT entry (money/credit coming back from supplier)
        $cbCols   = $pdo->query("SHOW COLUMNS FROM cashbook")->fetchAll(PDO::FETCH_COLUMN);
        $hasVoided   = in_array('voided',     $cbCols);
        $hasCreatedBy = in_array('created_by', $cbCols);

        $lastBalRow = _pdoOne(
            "SELECT balance FROM cashbook " . ($hasVoided ? "WHERE voided = 0 " : "") . "ORDER BY id DESC LIMIT 1"
        );
        $prevBal = (float)($lastBalRow['balance'] ?? 0);
        $newBal  = round($prevBal + $totalReturnAmount, 2);  // credit = money in

        $cbDesc = "Purchase Return {$returnNo} — {$purchase['supplier_name']}. {$reason}"
                . ($creditNoteRef ? " (Credit Note: {$creditNoteRef})" : '');

        if ($hasCreatedBy) {
            $pdo->prepare(
                "INSERT INTO cashbook
                    (entry_date, entry_type, reference_id, reference_type,
                     description, debit, credit, balance, payment_method, created_by)
                 VALUES
                    (:date, 'purchase_return', :rid, 'purchase_return',
                     :desc, 0, :credit, :bal, :method, :uid)"
            )->execute([
                ':date'   => date('Y-m-d'),
                ':rid'    => $returnHeaderId,
                ':desc'   => $cbDesc,
                ':credit' => $totalReturnAmount,
                ':bal'    => $newBal,
                ':method' => $refundMethod,
                ':uid'    => $userId,
            ]);
        } else {
            $pdo->prepare(
                "INSERT INTO cashbook
                    (entry_date, entry_type, reference_id, reference_type,
                     description, debit, credit, balance, payment_method)
                 VALUES
                    (:date, 'purchase_return', :rid, 'purchase_return',
                     :desc, 0, :credit, :bal, :method)"
            )->execute([
                ':date'   => date('Y-m-d'),
                ':rid'    => $returnHeaderId,
                ':desc'   => $cbDesc,
                ':credit' => $totalReturnAmount,
                ':bal'    => $newBal,
                ':method' => $refundMethod,
            ]);
        }

        $pdo->commit();

        // 8. Bust caches
        if (function_exists('clearAllCaches'))           clearAllCaches();
        if (function_exists('invalidateDashboardCache')) invalidateDashboardCache();
        if (function_exists('logActivity')) {
            logActivity('purchase_return', "Return {$returnNo} — Rs " . number_format($totalReturnAmount, 2)
                . " to {$purchase['supplier_name']}");
        }

        // 9. Get fresh supplier balance
        $supplierFresh = _pdoOne("SELECT current_balance FROM suppliers WHERE id = :id", [':id' => $supplierId]);

        echo json_encode([
            'success'              => true,
            'return_no'            => $returnNo,
            'return_amount'        => $totalReturnAmount,
            'stock_removed'        => $stockRemoved,
            'supplier_new_balance' => (float)($supplierFresh['current_balance'] ?? 0),
            'message'              => "Return {$returnNo} processed successfully.",
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("process_return.php [purchase] ERROR: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// SALE RETURN PROCESSOR
// ══════════════════════════════════════════════════════════════════════════════
function processSaleReturnRequest(
    int    $saleId,
    array  $items,
    string $reason,
    string $refundMethod,
    int    $userId
): void {

    $sale = _pdoOne(
        "SELECT * FROM sales WHERE id = :id",
        [':id' => $saleId]
    );
    if (!$sale) {
        echo json_encode(['success' => false, 'message' => "Sale #{$saleId} not found."]);
        return;
    }

    $returnNo = generateReturnNo('SR');
    $pdo = _getPDO();
    ensureSaleReturnColumns($pdo);
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
                "SELECT si.*, b.remaining_quantity as batch_remaining, b.batch_number, p.product_name
                 FROM sale_items si
                 JOIN batches b  ON si.batch_id   = b.id
                 JOIN products p ON si.product_id = p.id
                 WHERE si.id = :id AND si.sale_id = :sid",
                [':id' => $saleItemId, ':sid' => $saleId]
            );
            if (!$si) throw new Exception("Sale item #{$saleItemId} not found.");

            $alreadyRet = (int)(_pdoOne(
                "SELECT COALESCE(SUM(quantity), 0) AS q FROM sale_returns WHERE sale_item_id = :id",
                [':id' => $saleItemId]
            )['q'] ?? 0);

            $maxReturnable = max(0, (int)$si['quantity'] - $alreadyRet);
            if ($returnQty > $maxReturnable) {
                throw new Exception(
                    "Return qty ({$returnQty}) for {$si['product_name']} exceeds returnable ({$maxReturnable})."
                );
            }

            $unitPrice   = (float)$si['unit_price'];
            $lineReturn  = round($returnQty * $unitPrice, 2);
            $totalReturnAmount += $lineReturn;
            $newBatchQty = (int)$si['batch_remaining'] + $returnQty; // stock restored

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
            $prevQty = (int)$si['batch_remaining'];
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

        // Update sale - reduce net_amount; also recalc payment_status
        $saleRow = _pdoOne("SELECT total, paid_amount, COALESCE(net_amount, total) as net_amount FROM sales WHERE id = :sid", [':sid' => $saleId]);
        $grossTotal = (float)($saleRow['total'] ?? 0);
        $paidAmount = (float)($saleRow['paid_amount'] ?? 0);

        // Total returned so far (including this return just inserted)
        $totalReturnedSoFar2 = (float)(_pdoOne(
            "SELECT COALESCE(SUM(total_amount), 0) AS t FROM sale_returns WHERE sale_id = :sid",
            [':sid' => $saleId]
        )['t'] ?? 0);

        $netTotal  = max(0, $grossTotal - $totalReturnedSoFar2);
        $newStatus = ($netTotal <= 0.009 || $paidAmount >= $netTotal - 0.009) ? 'paid'
                   : ($paidAmount > 0 ? 'partial' : 'pending');

        // Update net_amount if column exists, otherwise just update payment_status
        $saleCols = $pdo->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('net_amount', $saleCols)) {
            $pdo->prepare(
                "UPDATE sales SET net_amount = :net, payment_status = :status WHERE id = :sid"
            )->execute([':net' => $netTotal, ':status' => $newStatus, ':sid' => $saleId]);
        } else {
            $pdo->prepare(
                "UPDATE sales SET payment_status = :status WHERE id = :sid"
            )->execute([':status' => $newStatus, ':sid' => $saleId]);
        }

        // Cashbook: DEBIT (cash paid back to customer)
        $cbCols = $pdo->query("SHOW COLUMNS FROM cashbook")->fetchAll(PDO::FETCH_COLUMN);
        $hasVoided    = in_array('voided',     $cbCols);
        $hasCreatedBy = in_array('created_by', $cbCols);

        $lastBalRow = _pdoOne(
            "SELECT balance FROM cashbook " . ($hasVoided ? "WHERE voided = 0 " : "") . "ORDER BY id DESC LIMIT 1"
        );
        $prevBal = (float)($lastBalRow['balance'] ?? 0);
        $newBal  = round($prevBal - $totalReturnAmount, 2);

        $cbDesc = "Sale Return {$returnNo} — Refund to customer. {$reason}";

        if ($hasCreatedBy) {
            $pdo->prepare(
                "INSERT INTO cashbook
                    (entry_date, entry_type, reference_id, reference_type,
                     description, debit, credit, balance, payment_method, created_by)
                 VALUES
                    (:date, 'sale_return', :rid, 'sale_return',
                     :desc, :debit, 0, :bal, :method, :uid)"
            )->execute([
                ':date'   => date('Y-m-d'),
                ':rid'    => $returnId,
                ':desc'   => $cbDesc,
                ':debit'  => $totalReturnAmount,
                ':bal'    => $newBal,
                ':method' => $refundMethod,
                ':uid'    => $userId,
            ]);
        } else {
            $pdo->prepare(
                "INSERT INTO cashbook
                    (entry_date, entry_type, reference_id, reference_type,
                     description, debit, credit, balance, payment_method)
                 VALUES
                    (:date, 'sale_return', :rid, 'sale_return',
                     :desc, :debit, 0, :bal, :method)"
            )->execute([
                ':date'   => date('Y-m-d'),
                ':rid'    => $returnId,
                ':desc'   => $cbDesc,
                ':debit'  => $totalReturnAmount,
                ':bal'    => $newBal,
                ':method' => $refundMethod,
            ]);
        }

        $pdo->commit();

        // ── FIX: Sync customer credit after sale return ──
        if (function_exists('syncAfterSaleReturn')) {
            syncAfterSaleReturn($returnId, $saleId, $totalReturnAmount, $refundMethod, $returnNo);
        }

        if (function_exists('clearAllCaches'))           clearAllCaches();
        if (function_exists('invalidateDashboardCache')) invalidateDashboardCache();

        echo json_encode([
            'success'       => true,
            'return_no'     => $returnNo,
            'return_amount' => $totalReturnAmount,
            'stock_removed' => $stockRestored,
            'message'       => "Return {$returnNo} processed successfully.",
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("process_return.php [sale] ERROR: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Generate a unique sequential return number: PR-YYYYMMDD-0001
 */
function generateReturnNo(string $prefix = 'PR'): string {
    $date  = date('Ymd');
    $table = ($prefix === 'SR') ? 'sale_returns' : 'purchase_returns';

    $lastNo = _pdoOne(
        "SELECT return_no FROM {$table}
         WHERE return_no LIKE :pattern
         ORDER BY id DESC LIMIT 1",
        [':pattern' => "{$prefix}-{$date}-%"]
    );

    $seq = 1;
    if ($lastNo && preg_match('/-(\d+)$/', $lastNo['return_no'], $m)) {
        $seq = (int)$m[1] + 1;
    }
    return sprintf('%s-%s-%04d', $prefix, $date, $seq);
}

/**
 * Auto-add any missing columns to sale_returns (and sales) to prevent SQL errors.
 */
function ensureSaleReturnColumns(\PDO $pdo): void {
    // sale_returns table
    $cols = $pdo->query("SHOW COLUMNS FROM sale_returns")->fetchAll(\PDO::FETCH_COLUMN);
    if (!in_array('refund_method', $cols)) {
        $pdo->exec("ALTER TABLE sale_returns ADD COLUMN refund_method VARCHAR(30) NOT NULL DEFAULT 'cash'");
    }
    if (!in_array('returned_by', $cols)) {
        $pdo->exec("ALTER TABLE sale_returns ADD COLUMN returned_by INT NULL DEFAULT NULL");
    }

    // sales table — net_amount may not exist on older installs
    $salesCols = $pdo->query("SHOW COLUMNS FROM sales")->fetchAll(\PDO::FETCH_COLUMN);
    if (!in_array('net_amount', $salesCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN net_amount DECIMAL(10,2) NULL DEFAULT NULL");
        // Back-fill existing rows
        $pdo->exec("UPDATE sales SET net_amount = total WHERE net_amount IS NULL");
    }

    // stock_movements table — notes and created_by may be missing
    $smCols = $pdo->query("SHOW COLUMNS FROM stock_movements")->fetchAll(\PDO::FETCH_COLUMN);
    if (!in_array('notes', $smCols)) {
        $pdo->exec("ALTER TABLE stock_movements ADD COLUMN notes TEXT NULL DEFAULT NULL");
    }
    if (!in_array('created_by', $smCols)) {
        $pdo->exec("ALTER TABLE stock_movements ADD COLUMN created_by INT NULL DEFAULT NULL");
    }
}

/**
 * Auto-add any missing columns to purchase_returns to prevent SQL errors.
 */
function ensurePurchaseReturnColumns(\PDO $pdo): void {
    $cols = $pdo->query("SHOW COLUMNS FROM purchase_returns")->fetchAll(\PDO::FETCH_COLUMN);
    if (!in_array('credit_note_ref', $cols)) {
        $pdo->exec("ALTER TABLE purchase_returns ADD COLUMN credit_note_ref VARCHAR(100) NULL DEFAULT NULL");
    }
    if (!in_array('returned_by', $cols)) {
        $pdo->exec("ALTER TABLE purchase_returns ADD COLUMN returned_by INT NULL DEFAULT NULL");
    }
    if (!in_array('refund_method', $cols)) {
        $pdo->exec("ALTER TABLE purchase_returns ADD COLUMN refund_method VARCHAR(30) NOT NULL DEFAULT 'credit_note'");
    }
}