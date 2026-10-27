<?php
/**
 * products/inventory_all_products.php
 *
 * Returns JSON array of products.
 *   ?trash=1  → deleted products  (status = 0)  ← Trash Items modal
 *   default   → active  products  (status = 1)  ← inventory lookups
 *
 * FIXED:
 *  - No duplicate auth.php include (config.php already handles auth)
 *  - Uses getTrashedProducts() for trash mode (reliable, tested function)
 *  - Full error handling — always returns valid JSON, never blank
 */
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/product_functions.php';

// config.php already calls session_start and loads auth — don't include auth.php again
requireLogin();
require_once '../includes/security.php';
requireCSRF();

header('Content-Type: application/json; charset=utf-8');

try {
    $trashMode = isset($_GET['trash']) && $_GET['trash'] == '1';

    if ($trashMode) {
        // Use dedicated function — reliably returns status=0 products
        $products = getTrashedProducts();
    } else {
        // Active products for inventory lookups
        $db = db();
        $db->query("
            SELECT id, product_name, product_code, category_id, brand_name,
                   generic_name, strength, strength_unit, dosage_form,
                   size_value, unit_type, sale_price, purchase_price, status
            FROM   products
            WHERE  status = 1
            ORDER  BY product_name ASC
        ");
        $products = $db->resultSet() ?: [];
    }

    echo json_encode($products, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => true,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
exit;