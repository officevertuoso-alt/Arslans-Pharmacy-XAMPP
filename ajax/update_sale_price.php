<?php
/**
 * ajax/update_sale_price.php
 * Quick-update sale_price for a single product (in-stock only).
 */
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('sales', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
requirePharmacist();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// CSRF check
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$productId = (int)($_POST['product_id'] ?? 0);
$salePrice = isset($_POST['sale_price']) ? (float)$_POST['sale_price'] : -1;

if ($productId <= 0 || $salePrice < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit;
}

$db = db();

// Verify product exists and is in stock
$db->query("SELECT p.id, 
            COALESCE(SUM(b.remaining_quantity),0) AS total_stock
            FROM products p
            LEFT JOIN batches b ON b.product_id = p.id
              AND b.remaining_quantity > 0
              AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
            WHERE p.id = :id AND p.status = 1
            GROUP BY p.id");
$db->bind(':id', $productId);
$row = $db->single();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

if ((int)$row['total_stock'] <= 0) {
    echo json_encode(['success' => false, 'message' => 'Product is not in stock.']);
    exit;
}

// Update
$db->query("UPDATE products SET sale_price = :price WHERE id = :id AND status = 1");
$db->bind(':price', round($salePrice, 2));
$db->bind(':id', $productId);
$db->execute();

echo json_encode(['success' => true, 'sale_price' => round($salePrice, 2)]);