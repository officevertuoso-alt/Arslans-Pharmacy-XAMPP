<?php
/**
 * ajax/get_product_batches.php
 * ────────────────────────────────────────────────────────────────
 * Returns ALL batches for a given product_id as JSON.
 * Called by the "View Batches" modal in products/list.php.
 *
 * GET params:
 *   product_id  (int)  — required
 *   csrf_token  (str)  — validated
 *
 * Returns JSON array of batch rows.
 * ────────────────────────────────────────────────────────────────
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('products', 'view')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}

header('Content-Type: application/json');

// ── CSRF guard ──────────────────────────────────────────────────
if (!isset($_GET['csrf_token']) || !validateCSRFToken($_GET['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token.']);
    exit;
}

// ── Auth guard ──────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised.']);
    exit;
}

// ── Input ───────────────────────────────────────────────────────
$productId = max(0, (int)($_GET['product_id'] ?? 0));
if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid product_id.']);
    exit;
}

// ── Query: ALL batches for this product (active + expired + exhausted) ──
try {
    $db = db();
    $db->query(
        "SELECT
             b.id,
             b.batch_number,
             b.purchase_price,
             b.selling_price,
             b.remaining_quantity,
             b.expiry_date,
             b.barcode,
             b.is_expired,
             b.created_at,
             s.supplier_name
         FROM batches b
         LEFT JOIN suppliers s ON b.supplier_id = s.id
         WHERE b.product_id = :pid
         ORDER BY
             -- Active non-expired first (FIFO: oldest expiry first),
             -- then expired, then exhausted
             CASE
                 WHEN b.remaining_quantity > 0
                      AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE()) THEN 0
                 WHEN b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()   THEN 1
                 ELSE 2
             END ASC,
             b.expiry_date ASC,
             b.id ASC"
    );
    $db->bind(':pid', $productId);
    $batches = $db->resultSet();
    echo json_encode($batches ?: []);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}