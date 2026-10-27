<?php
/**
 * ajax/update_batch_price.php
 * POST: batch_id, new_price, csrf_token
 * Updates batches.selling_price and refreshes products.sale_price via FIFO
 * Returns JSON: {success, batch_id, new_price, product_new_price}
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
requireLogin();
if (!canDo('products', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
require_once __DIR__ . '/../includes/purchase_functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrfToken = trim($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$batchId  = (int)($_POST['batch_id']  ?? 0);
$newPrice = (float)($_POST['new_price'] ?? 0);

if (!$batchId || $newPrice <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid batch ID or price.']);
    exit;
}

try {
    // Get batch to find product_id
    $batch = _pdoOne("SELECT id, product_id, selling_price FROM batches WHERE id = :id", [':id' => $batchId]);
    if (!$batch) {
        echo json_encode(['success' => false, 'message' => 'Batch not found.']);
        exit;
    }

    // Update batch selling_price
    _pdoRun("UPDATE batches SET selling_price = :price WHERE id = :id", [
        ':price' => $newPrice,
        ':id'    => $batchId,
    ]);

    $productId = (int)$batch['product_id'];

    // Refresh FIFO price on products table
    updateProductStockAndPrice($productId);

    // Get updated FIFO price
    $product = _pdoOne("SELECT sale_price FROM products WHERE id = :id", [':id' => $productId]);
    $productNewPrice = (float)($product['sale_price'] ?? $newPrice);

    if (function_exists('logActivity')) {
        logActivity('batch_price_update', "Batch #{$batchId} sale price updated to Rs " . number_format($newPrice, 2));
    }

    echo json_encode([
        'success'           => true,
        'batch_id'          => $batchId,
        'new_price'         => $newPrice,
        'product_new_price' => $productNewPrice,
        'message'           => 'Price updated successfully.',
    ]);

} catch (\Throwable $e) {
    error_log("update_batch_price.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}