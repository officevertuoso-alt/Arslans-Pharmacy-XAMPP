<?php
// ISS-012 NOTICE: Duplicate search endpoint — consolidate to ajax/search_products.php
// This file is preserved for backward compatibility. Do not add new features here.
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/product_functions.php';

requireLogin();

$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$category = isset($_GET['category']) ? sanitize($_GET['category']) : '';

if (strlen($search) < 1) {
    echo json_encode([]);
    exit();
}

$db = db();

$where = "p.status = 1";
$params = [];

// Search condition
$where .= " AND (p.product_name LIKE :search OR pv.variant_value LIKE :search2 OR p.generic_name LIKE :search3 OR p.brand_name LIKE :search4)";
$params[':search'] = "%$search%";
$params[':search2'] = "%$search%";
$params[':search3'] = "%$search%";
$params[':search4'] = "%$search%";

// Category filter
if ($category == '1' || $category == '2') {
    $where .= " AND p.category_id = :category";
    $params[':category'] = $category;
}

// Query products with variants
$sql = "SELECT 
            p.id,
            p.product_name,
            p.generic_name,
            p.brand_name,
            NULL as variant_id,
            NULL as variant_name,
            NULL as variant_value,
            COALESCE(SUM(CASE WHEN b.expiry_date > CURDATE() AND b.is_expired = 0 AND b.variant_id IS NULL THEN b.remaining_quantity ELSE 0 END), 0) as total_stock,
            MIN(b.selling_price) as price
        FROM products p
        LEFT JOIN batches b ON p.id = b.product_id AND b.variant_id IS NULL
        WHERE $where
        GROUP BY p.id
        HAVING total_stock > 0
        
        UNION ALL
        
        SELECT 
            p.id,
            p.product_name,
            p.generic_name,
            p.brand_name,
            pv.id as variant_id,
            pv.variant_name,
            pv.variant_value,
            COALESCE(SUM(CASE WHEN b.expiry_date > CURDATE() AND b.is_expired = 0 AND b.variant_id = pv.id THEN b.remaining_quantity ELSE 0 END), 0) as total_stock,
            MIN(b.selling_price) as price
        FROM products p
        JOIN product_variants pv ON p.id = pv.product_id
        LEFT JOIN batches b ON p.id = b.product_id AND b.variant_id = pv.id
        WHERE $where
        GROUP BY p.id, pv.id
        HAVING total_stock > 0
        
        ORDER BY product_name ASC, variant_value ASC
        LIMIT 20";

$db->query($sql);
foreach ($params as $key => $value) {
    $db->bind($key, $value);
}
$products = $db->resultSet();

header('Content-Type: application/json');
echo json_encode($products);
?>