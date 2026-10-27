<?php
/**
 * products/inventory_all_products.php  — STANDALONE VERSION
 *
 * Koi bhi external function require nahi — seedha DB query karta hai.
 * Yeh version 100% kaam karega chahe product_functions.php purana ho ya naya.
 *
 *   ?trash=1  → deleted products (status = 0)  — Trash Items modal
 *   default   → active  products (status = 1)  — inventory lookups
 */
require_once '../config/config.php';

// config.php already handles session & auth — do NOT include auth.php separately
if (function_exists('requireLogin')) {
    requireLogin();
} elseif (function_exists('requirePharmacist')) {
    requirePharmacist();
}

header('Content-Type: application/json; charset=utf-8');

$trashMode    = isset($_GET['trash']) && $_GET['trash'] == '1';
$statusFilter = $trashMode ? 0 : 1;

try {
    $db = db();

    // Try positional param first, fallback to named param
    $sql = "SELECT id, product_name, product_code, category_id, brand_name,
                   generic_name, strength, dosage_form,
                   size_value, unit_type, sale_price, purchase_price, status
            FROM   products
            WHERE  status = :st
            ORDER  BY product_name ASC";

    $db->query($sql);
    $db->bind(':st', $statusFilter);

    $products = $db->resultSet();
    if (!is_array($products)) $products = [];

    echo json_encode($products, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => $e->getMessage()]);
}
exit;
