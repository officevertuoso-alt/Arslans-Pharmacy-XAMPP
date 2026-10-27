<?php
/**
 * ajax/save_stock_transfer.php — Phase 15: Inventory AJAX
 *
 * Processes a stock transfer between two batches of the same product.
 *
 * Synchronized with:
 *   batches.remaining_quantity  — from_batch -=qty, to_batch +=qty
 *   stock_movements             — audit trail for both sides
 *   stock_transfers             — transfer record
 *   products.stock_quantity     — recalculated after commit
 *   sync_engine                 — clearAllCaches() on success
 *
 * Method : POST
 * Params :
 *   csrf_token      (string, required)
 *   product_id      (int,    required)
 *   from_batch_id   (int,    required)
 *   to_batch_id     (int,    required)
 *   quantity        (int,    required, > 0)
 *   reason          (string, optional)
 *
 * Returns: JSON { success, message }
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('stock', 'add')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
require_once '../includes/sync_engine.php';
require_once '../includes/purchase_functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (function_exists('canAdd') && !canAdd('stock')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$productId    = (int)($_POST['product_id']    ?? 0);
$fromBatchId  = (int)($_POST['from_batch_id'] ?? 0);
$toBatchId    = (int)($_POST['to_batch_id']   ?? 0);
$quantity     = (int)($_POST['quantity']      ?? 0);
$reason       = trim(substr(strip_tags($_POST['reason'] ?? ''), 0, 255));
$userId       = (int)($_SESSION['user_id'] ?? 1);

// ── Validation ────────────────────────────────────────────────────────────────
if (!$productId)                   { echo json_encode(['success'=>false,'message'=>'Please select a product.']); exit; }
if (!$fromBatchId)                 { echo json_encode(['success'=>false,'message'=>'Please select the source batch.']); exit; }
if (!$toBatchId)                   { echo json_encode(['success'=>false,'message'=>'Please select the destination batch.']); exit; }
if ($fromBatchId === $toBatchId)   { echo json_encode(['success'=>false,'message'=>'Source and destination batches must be different.']); exit; }
if ($quantity <= 0)                { echo json_encode(['success'=>false,'message'=>'Quantity must be greater than zero.']); exit; }

// ── Fetch both batches ────────────────────────────────────────────────────────
$pdo = _getPDO();

try {
    $fromBatch = getOne(
        "SELECT b.*, p.product_name, p.product_code
         FROM batches b
         JOIN products p ON p.id = b.product_id
         WHERE b.id = :id AND b.product_id = :pid AND b.is_blocked = 0",
        [':id' => $fromBatchId, ':pid' => $productId]
    );
    $toBatch = getOne(
        "SELECT b.* FROM batches b
         WHERE b.id = :id AND b.product_id = :pid AND b.is_blocked = 0",
        [':id' => $toBatchId, ':pid' => $productId]
    );
} catch (Exception $e) {
    error_log('save_stock_transfer fetch: ' . $e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Error fetching batch data.']);
    exit;
}

if (!$fromBatch) { echo json_encode(['success'=>false,'message'=>'Source batch not found or not available.']); exit; }
if (!$toBatch)   { echo json_encode(['success'=>false,'message'=>'Destination batch not found or not available.']); exit; }

if ($quantity > (int)$fromBatch['remaining_quantity']) {
    echo json_encode([
        'success' => false,
        'message' => 'Cannot transfer ' . $quantity . ' units — only ' . $fromBatch['remaining_quantity'] . ' available in source batch.'
    ]);
    exit;
}

// ── Execute transfer in transaction ───────────────────────────────────────────
$pdo->beginTransaction();
try {
    // Ensure stock_transfers table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_transfers` (
        `id`              int(11)      NOT NULL AUTO_INCREMENT,
        `from_batch_id`   int(11)      NOT NULL,
        `to_batch_id`     int(11)      NOT NULL,
        `product_id`      int(11)      NOT NULL,
        `quantity`        int(11)      NOT NULL,
        `reason`          varchar(255) NOT NULL DEFAULT '',
        `created_by`      int(11)      NOT NULL,
        `created_at`      timestamp    NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_product` (`product_id`),
        KEY `idx_from`    (`from_batch_id`),
        KEY `idx_to`      (`to_batch_id`),
        KEY `idx_date`    (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // 1. Deduct from source batch
    _pdoRun(
        "UPDATE batches SET remaining_quantity = remaining_quantity - :q WHERE id = :id",
        [':q' => $quantity, ':id' => $fromBatchId]
    );

    // 2. Add to destination batch
    _pdoRun(
        "UPDATE batches SET remaining_quantity = remaining_quantity + :q WHERE id = :id",
        [':q' => $quantity, ':id' => $toBatchId]
    );

    // 3. stock_movements — out from source
    _pdoRun(
        "INSERT INTO stock_movements (batch_id, product_id, movement_type, quantity, created_by, notes, created_at)
         VALUES (:bid, :pid, 'transfer_out', :qty, :uid, :notes, NOW())",
        [
            ':bid'   => $fromBatchId,
            ':pid'   => $productId,
            ':qty'   => $quantity,
            ':uid'   => $userId,
            ':notes' => 'TRANSFER OUT to Batch #' . $fromBatch['batch_number'] . ' → Batch ' .
                        (getOne("SELECT batch_number FROM batches WHERE id = :id", [':id'=>$toBatchId])['batch_number'] ?? $toBatchId) .
                        ($reason ? ': ' . $reason : ''),
        ]
    );

    // 4. stock_movements — in to destination
    _pdoRun(
        "INSERT INTO stock_movements (batch_id, product_id, movement_type, quantity, created_by, notes, created_at)
         VALUES (:bid, :pid, 'transfer_in', :qty, :uid, :notes, NOW())",
        [
            ':bid'   => $toBatchId,
            ':pid'   => $productId,
            ':qty'   => $quantity,
            ':uid'   => $userId,
            ':notes' => 'TRANSFER IN from Batch ' . $fromBatch['batch_number'] .
                        ($reason ? ': ' . $reason : ''),
        ]
    );

    // 5. Insert stock_transfers record
    _pdoRun(
        "INSERT INTO stock_transfers (from_batch_id, to_batch_id, product_id, quantity, reason, created_by, created_at)
         VALUES (:fbid, :tbid, :pid, :qty, :reason, :uid, NOW())",
        [
            ':fbid'   => $fromBatchId,
            ':tbid'   => $toBatchId,
            ':pid'    => $productId,
            ':qty'    => $quantity,
            ':reason' => $reason,
            ':uid'    => $userId,
        ]
    );

    $pdo->commit();

    // 6. Refresh product stock (outside transaction)
    if (function_exists('updateProductStockAndPrice')) {
        updateProductStockAndPrice($productId);
    }

    // 7. Clear caches
    if (function_exists('clearAllCaches')) {
        clearAllCaches();
    }

    echo json_encode([
        'success' => true,
        'message' => number_format($quantity) . ' unit(s) transferred successfully from Batch ' .
                     htmlspecialchars($fromBatch['batch_number']) . ' to destination batch.',
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('save_stock_transfer error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error during transfer. Please try again.']);
}
exit;
