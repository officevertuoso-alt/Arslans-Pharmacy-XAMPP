<?php
/**
 * ajax/get_product_form_data.php
 * Returns product fields as JSON for the edit modal.
 *
 * GET: ?product_id=N
 * Returns: { success, product: { id, category_id, product_name, brand_name,
 *             unit_type, units_per_pack, barcode, generic_name, dosage_form,
 *             strength, size_value, image_path, image_url, is_editable } }
 */

// BUG FIX: Buffer output to prevent PHP notices from corrupting JSON
ob_start();

require_once '../config/config.php';
require_once '../includes/product_functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Auth check
if (function_exists('requireLogin'))           requireLogin();
elseif (function_exists('requirePharmacist'))  requirePharmacist();

// BUG FIX: Clean buffer and set JSON header
ob_clean();
header('Content-Type: application/json; charset=utf-8');

// Permission check - canDo may not exist in all versions, fallback gracefully
if (function_exists('canDo') && !canDo('products', 'view')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}

$productId = (int)($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID.']);
    exit;
}

try {
    $product = _pdoOne(
        "SELECT * FROM products WHERE id = :id AND status != -1 LIMIT 1",
        [':id' => $productId]
    );
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

// Check editability
$activeCount = 0;
try {
    $row = _pdoOne(
        "SELECT COUNT(*) AS cnt FROM batches
         WHERE product_id = :pid
           AND remaining_quantity > 0
           AND COALESCE(is_expired, 0) = 0
           AND (expiry_date IS NULL OR expiry_date >= CURDATE())",
        [':pid' => $productId]
    );
    $activeCount = (int)($row['cnt'] ?? 0);
} catch (\Throwable $e) {}

$isEditable = ($activeCount === 0);

// Build image URL
$imageUrl = '';
if (!empty($product['image_path'])) {
    $imageUrl = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/' . ltrim(str_replace('\\', '/', $product['image_path']), '/');
}

echo json_encode([
    'success' => true,
    'product' => [
        'id'            => (int)$product['id'],
        'category_id'   => (int)($product['category_id'] ?? 1),
        'product_name'  => $product['product_name']  ?? '',
        'brand_name'    => $product['brand_name']    ?? '',
        'unit_type'     => $product['unit_type']     ?? '',
        'units_per_pack'=> (int)($product['units_per_pack'] ?? 1),
        'barcode'       => $product['barcode']       ?? '',
        'generic_name'  => $product['generic_name']  ?? '',
        'dosage_form'   => $product['dosage_form']   ?? '',
        'strength'      => $product['strength']      ?? '',
        'size_value'    => $product['size_value']    ?? '',
        'image_path'    => $product['image_path']    ?? '',
        'image_url'     => $imageUrl,
        'is_editable'   => $isEditable,
        'active_batches'=> $activeCount,
    ],
]);
?>
