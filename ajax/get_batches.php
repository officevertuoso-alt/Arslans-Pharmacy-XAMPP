<?php
/**
 * AJAX — Get Available Batches for a Product
 * File: ajax/get_batches.php
 *
 * Method : GET
 * Params : product_id (required), variant_id (optional)
 * Returns: JSON — batch list + total_stock
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
$variantId = isset($_GET['variant_id']) && $_GET['variant_id'] !== ''
    ? (int)$_GET['variant_id']
    : null;

if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid product_id is required.']);
    exit;
}

// ── Near-expiry threshold (days) ──────────────────────────────────────────────
define('NEAR_EXPIRY_DAYS', 30);

// ── Build query ───────────────────────────────────────────────────────────────
$sql = '
    SELECT
        b.id                                         AS batch_id,
        b.batch_number,
        b.expiry_date,
        b.remaining_quantity,
        b.purchase_price,
        b.selling_price,
        DATEDIFF(b.expiry_date, CURDATE())           AS days_to_expiry
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

$sql .= ' ORDER BY b.expiry_date ASC';   // FIFO — oldest expiry first

// ── Execute ───────────────────────────────────────────────────────────────────
try {
    $rows = getAll($sql, $params);
} catch (Exception $e) {
    error_log('get_batches.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}

// ── Build response ────────────────────────────────────────────────────────────
$batches    = [];
$totalStock = 0;

foreach ($rows as $row) {
    $days = (int)$row['days_to_expiry'];

    if ($days <= 0) {
        $status = 'expired';
    } elseif ($days <= NEAR_EXPIRY_DAYS) {
        $status = 'near_expiry';
    } else {
        $status = 'good';
    }

    $batches[] = [
        'batch_id'          => (int)$row['batch_id'],
        'batch_number'      => $row['batch_number'],
        'expiry_date'       => $row['expiry_date'],           // YYYY-MM-DD (machine-readable)
        'expiry_formatted'  => formatDate($row['expiry_date']), // DD-MM-YYYY (display)
        'remaining_quantity'=> (int)$row['remaining_quantity'],
        'purchase_price'    => (float)$row['purchase_price'],
        'selling_price'     => (float)$row['selling_price'],
        'days_to_expiry'    => $days,
        'status'            => $status,
    ];

    $totalStock += (int)$row['remaining_quantity'];
}

echo json_encode([
    'success'     => true,
    'batches'     => $batches,
    'total_stock' => $totalStock,
]);