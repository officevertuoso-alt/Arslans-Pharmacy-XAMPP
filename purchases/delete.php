<?php
/**
 * purchases/delete.php
 * VOID a purchase order — Admin only.
 *
 * WHY VOID NOT DELETE:
 *   Hard-deleting a purchase destroys audit trail and corrupts stock history.
 *   Voiding adds voided=1 flag, reverses stock via batch removal, reverses
 *   supplier balance, and voids cashbook entries — fully synchronized.
 *
 * RULES:
 *   - Only admin can void
 *   - Cannot void if any payment has been made (use purchase_return instead)
 *   - Cannot void if a return already exists against this purchase
 *   - Batch quantities are restored to 0 (batch deleted)
 *   - Supplier current_balance is reduced by purchase total
 *   - Cashbook entries for this purchase are voided
 *
 * REQUEST: POST (AJAX)
 * RESPONSE: JSON { success, message }
 */
session_start();
require_once '../config/config.php';
require_once '../includes/purchase_functions.php';
require_once '../includes/sync_engine.php';
require_once '../includes/cashbook_functions.php';

requireAdmin();
// Phase 21: allow manager with delete permission
if (!isAdmin() && !canDelete('purchases')) {
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Permission denied.']); exit;
}
header('Content-Type: application/json');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh and try again.']);
    exit;
}

$purchaseId = (int)($_POST['purchase_id'] ?? 0);
$userId     = (int)($_SESSION['user_id'] ?? 0);

if (!$purchaseId) {
    echo json_encode(['success' => false, 'message' => 'Purchase ID is required']);
    exit;
}

$pdo = _getPDO();

// ── Fetch purchase ─────────────────────────────────────────────────────────────
$purchase = _pdoOne(
    "SELECT p.*, s.supplier_name
     FROM purchases p
     LEFT JOIN suppliers s ON s.id = p.supplier_id
     WHERE p.id = :id",
    [':id' => $purchaseId]
);

if (!$purchase) {
    echo json_encode(['success' => false, 'message' => 'Purchase not found']);
    exit;
}

// ── Already voided? ────────────────────────────────────────────────────────────
if (!empty($purchase['voided'])) {
    echo json_encode(['success' => false, 'message' => 'This purchase is already voided']);
    exit;
}

// ── Guard: payments made? ──────────────────────────────────────────────────────
if ((float)$purchase['paid_amount'] > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Cannot void: Rs. ' . number_format($purchase['paid_amount'], 2) .
                     ' has already been paid to supplier. Use Purchase Return instead.',
    ]);
    exit;
}

// ── Guard: returns exist? ──────────────────────────────────────────────────────
$hasReturns = _pdoOne(
    "SELECT COUNT(*) AS cnt FROM purchase_returns WHERE purchase_id = :id",
    [':id' => $purchaseId]
);
if ($hasReturns && (int)$hasReturns['cnt'] > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Cannot void: purchase return(s) already exist against this purchase.',
    ]);
    exit;
}

// ── Get purchase items (for stock reversal) ────────────────────────────────────
$items = _pdoAll(
    "SELECT pi.product_id, pi.quantity, pi.batch_number
     FROM purchase_items pi
     WHERE pi.purchase_id = :pid",
    [':pid' => $purchaseId]
);

$affectedProductIds = array_unique(array_column($items, 'product_id'));

// ── BEGIN TRANSACTION ──────────────────────────────────────────────────────────
$pdo->beginTransaction();

try {
    // 1. Ensure voided columns exist (safe migration)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM purchases")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('voided', $cols)) {
            $pdo->exec("ALTER TABLE purchases ADD COLUMN voided TINYINT(1) NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE purchases ADD COLUMN voided_by INT DEFAULT NULL");
            $pdo->exec("ALTER TABLE purchases ADD COLUMN voided_at TIMESTAMP NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE purchases ADD COLUMN void_reason VARCHAR(255) DEFAULT NULL");
        }
    } catch (Exception $e) {
        // Columns may already exist — ignore
    }

    // 2. Mark purchase as voided
    _pdoRun(
        "UPDATE purchases
         SET voided    = 1,
             voided_by = :uid,
             voided_at = NOW(),
             void_reason = 'Voided by admin'
         WHERE id = :id",
        [':uid' => $userId, ':id' => $purchaseId]
    );

    // 3. Remove batches created by this purchase
    //    (stock_movements records are kept for audit trail but batch is gone)
    $batchIds = _pdoAll(
        "SELECT id FROM batches WHERE purchase_id = :pid",
        [':pid' => $purchaseId]
    );

    if (!empty($batchIds)) {
        $batchIdList = implode(',', array_column($batchIds, 'id'));
        // Insert reversal stock_movements first (audit trail)
        foreach ($batchIds as $batch) {
            $batchRow = _pdoOne(
                "SELECT product_id, remaining_quantity FROM batches WHERE id = :id",
                [':id' => $batch['id']]
            );
            if ($batchRow && $batchRow['remaining_quantity'] > 0) {
                _pdoRun(
                    "INSERT INTO stock_movements
                         (batch_id, product_id, movement_type, quantity, created_by, notes)
                     VALUES (:bid, :pid, 'adjustment_out', :qty, :uid, 'Purchase voided')",
                    [
                        ':bid' => $batch['id'],
                        ':pid' => $batchRow['product_id'],
                        ':qty' => $batchRow['remaining_quantity'],
                        ':uid' => $userId,
                    ]
                );
            }
        }
        // Delete the batches
        $pdo->exec("DELETE FROM batches WHERE purchase_id = {$purchaseId}");
    }

    // 4. Reverse supplier balance
    _pdoRun(
        "UPDATE suppliers
         SET current_balance = current_balance - :amt
         WHERE id = :sid",
        [':amt' => (float)$purchase['total'], ':sid' => $purchase['supplier_id']]
    );

    // 5. Void related cashbook entries
    $cashbookEntries = _pdoAll(
        "SELECT id, entry_date
         FROM cashbook
         WHERE reference_id = :rid
           AND reference_type = 'purchase'
           AND voided = 0",
        [':rid' => $purchaseId]
    );

    foreach ($cashbookEntries as $cb) {
        _pdoRun(
            "UPDATE cashbook
             SET voided = 1, voided_reason = 'Purchase voided by admin'
             WHERE id = :id",
            [':id' => $cb['id']]
        );
        // Recalculate balance for affected date
        if (function_exists('_recalculateDailyBalance')) {
            _recalculateDailyBalance($cb['entry_date']);
        }
    }

    $pdo->commit();

    // 6. Refresh product stock for all affected products (outside transaction)
    foreach ($affectedProductIds as $pid) {
        if (function_exists('updateProductStockAndPrice')) {
            updateProductStockAndPrice($pid);
        }
    }

    // 7. Clear all caches
    if (function_exists('clearAllCaches')) {
        clearAllCaches();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Purchase #' . htmlspecialchars($purchase['purchase_invoice_no']) .
                     ' voided successfully. Stock reversed. Supplier balance adjusted.',
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('Purchase void error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to void purchase. Please try again.',
    ]);
}
exit;