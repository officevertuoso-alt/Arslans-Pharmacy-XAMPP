<?php
/**
 * AJAX — FIFO Batch Suggestion
 * File: ajax/get_batch_fifo.php
 *
 * Method : GET
 * Params : product_id (required), quantity (required), variant_id (optional)
 * Returns: JSON — ordered list of batches needed to fulfill the quantity
 *
 * Logic  : Loop batches (oldest expiry first). Take as much as possible
 *          from each until the requested quantity is fulfilled.
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('products', 'view')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
require_once '../config/database.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthenticated.']);
    exit;
}

// ── Input validation ──────────────────────────────────────────────────────────
$productId = (int)($_GET['product_id'] ?? 0);
$requested = (int)($_GET['quantity']   ?? 0);
$variantId = isset($_GET['variant_id']) && $_GET['variant_id'] !== ''
    ? (int)$_GET['variant_id']
    : null;

if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid product_id is required.']);
    exit;
}

if ($requested <= 0) {
    echo json_encode(['success' => false, 'message' => 'quantity must be a positive integer.']);
    exit;
}

// ── Fetch batches (FIFO — oldest expiry first) ────────────────────────────────
$sql = '
    SELECT
        b.id                 AS batch_id,
        b.batch_number,
        b.expiry_date,
        b.remaining_quantity AS available,
        b.selling_price
    FROM  batches b
    WHERE b.product_id        = :product_id
      AND b.remaining_quantity > 0
      AND b.is_expired         = 0
      AND b.expiry_date       > CURDATE()
';

$params = [':product_id' => $productId];

if ($variantId !== null) {
    $sql           .= ' AND b.variant_id = :variant_id';
    $params[':variant_id'] = $variantId;
}

$sql .= ' ORDER BY b.expiry_date ASC';   // FIFO

try {
    $rows = getAll($sql, $params);
} catch (Exception $e) {
    error_log('get_batch_fifo.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}

// ── FIFO allocation logic ─────────────────────────────────────────────────────
$batchesNeeded  = [];
$totalAvailable = 0;
$remaining      = $requested;       // how many units still need to be allocated

foreach ($rows as $row) {
    $available       = (int)$row['available'];
    $totalAvailable += $available;

    if ($remaining <= 0) {
        // Quantity already satisfied — stop allocating but keep counting totalAvailable
        continue;
    }

    // Take as much as this batch can give (but no more than still needed)
    $take      = min($available, $remaining);
    $remaining -= $take;

    $batchesNeeded[] = [
        'batch_id'     => (int)$row['batch_id'],
        'batch_number' => $row['batch_number'],
        'take_quantity'=> $take,
        'available'    => $available,
        'selling_price'=> (float)$row['selling_price'],
        'expiry_date'  => $row['expiry_date'],              // YYYY-MM-DD
        'expiry_formatted' => formatDate($row['expiry_date']), // DD-MM-YYYY
    ];
}

$canFulfill = ($remaining === 0);   // true only when all requested units allocated

echo json_encode([
    'success'        => true,
    'can_fulfill'    => $canFulfill,
    'batches_needed' => $batchesNeeded,
    'total_available'=> $totalAvailable,
    'requested'      => $requested,
    'shortfall'      => $canFulfill ? 0 : $remaining,   // units still missing
]);