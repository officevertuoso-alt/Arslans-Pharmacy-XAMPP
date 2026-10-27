<?php
/**
 * ajax/get_stock.php
 * Returns live stock info for a product (by ?product_id=N).
 * Called by js/script.js for inline stock checks on forms.
 */
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';

requireLogin();
header('Content-Type: application/json');

$productId = (int)($_GET['product_id'] ?? 0);

if (!$productId) {
    echo json_encode(['success' => false, 'message' => 'product_id required']);
    exit;
}

$db = db();

// Main product + live batch stock (non-expired, future expiry only)
$product = $db->getOne(
    "SELECT
         p.id,
         p.product_name,
         p.product_code,
         p.generic_name,
         p.strength,
         p.dosage_form,
         p.unit_type,
         p.units_per_pack,
         p.reorder_level,
         p.stock_quantity AS cached_stock,
         COALESCE(SUM(
             CASE WHEN b.is_expired = 0
                   AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
                   AND b.remaining_quantity > 0
             THEN b.remaining_quantity ELSE 0 END
         ), 0) AS live_stock,
         COALESCE((
             SELECT b2.selling_price
             FROM batches b2
             WHERE b2.product_id = p.id
               AND b2.is_expired = 0
               AND b2.remaining_quantity > 0
               AND (b2.expiry_date IS NULL OR b2.expiry_date > CURDATE())
             ORDER BY b2.expiry_date ASC, b2.id ASC
             LIMIT 1
         ), 0) AS fifo_price
     FROM products p
     LEFT JOIN batches b ON b.product_id = p.id
     WHERE p.id = :pid
     GROUP BY p.id",
    [':pid' => $productId]
);

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

// Batch breakdown
$batches = $db->getAll(
    "SELECT
         b.id,
         b.batch_number,
         b.expiry_date,
         b.remaining_quantity,
         b.selling_price,
         b.purchase_price,
         b.is_expired
     FROM batches b
     WHERE b.product_id = :pid
       AND b.remaining_quantity > 0
     ORDER BY b.expiry_date ASC, b.id ASC",
    [':pid' => $productId]
);

echo json_encode([
    'success'      => true,
    'product_id'   => (int)$product['id'],
    'product_name' => $product['product_name'],
    'product_code' => $product['product_code'],
    'generic_name' => $product['generic_name'],
    'strength'     => $product['strength'],
    'dosage_form'  => $product['dosage_form'],
    'unit_type'    => $product['unit_type'],
    'units_per_pack' => (int)$product['units_per_pack'],
    'reorder_level'  => (int)$product['reorder_level'],
    'stock_quantity' => (int)$product['live_stock'],
    'fifo_price'     => (float)$product['fifo_price'],
    'low_stock'      => ((int)$product['live_stock'] <= (int)$product['reorder_level']),
    'batches'        => $batches,
]);
exit;
