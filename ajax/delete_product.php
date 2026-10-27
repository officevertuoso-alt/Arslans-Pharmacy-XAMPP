<?php
/**
 * ajax/delete_product.php - AJAX endpoint for soft delete
 */

require_once '../config/config.php';
require_once '../includes/product_functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF check
$token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
    exit;
}

$id = (int)($_POST['product_id'] ?? $_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID.']);
    exit;
}

// Fetch product
$product = _pdoOne("SELECT id, product_name, status FROM products WHERE id = :id", [':id' => $id]);

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

// Stock protection check
$stockCheck = _pdoOne(
    "SELECT COALESCE(SUM(remaining_quantity), 0) AS total_qty
     FROM batches
     WHERE product_id = :pid
       AND remaining_quantity > 0
       AND expiry_date >= CURDATE()",
    [':pid' => $id]
);

$activeStock = (int)($stockCheck['total_qty'] ?? 0);

if ($activeStock > 0) {
    echo json_encode([
        'success' => false,
        'blocked' => true,
        'stock' => $activeStock,
        'message' => "Cannot delete \"{$product['product_name']}\" — it has {$activeStock} unit(s) of active stock."
    ]);
    exit;
}

// Soft delete
try {
    _pdoRun(
        "UPDATE products SET status = 0, deleted_at = NOW(), updated_at = NOW() 
         WHERE id = :id AND status = 1",
        [':id' => $id]
    );
    
    if (function_exists('logActivity')) {
        logActivity('soft_delete_product', "Moved product #{$id} to Trash: {$product['product_name']}");
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Product '{$product['product_name']}' moved to Trash Items."
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>