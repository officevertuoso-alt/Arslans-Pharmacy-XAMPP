<?php
/**
 * Export selected products to CSV
 */
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid security token.');
}

$productIds = isset($_POST['product_ids']) && is_array($_POST['product_ids'])
    ? array_map('intval', $_POST['product_ids'])
    : [];

if (empty($productIds)) {
    die('No products selected.');
}

$placeholders = rtrim(str_repeat('?,', count($productIds)), ',');
$db = db();
$db->query("
    SELECT p.product_code, p.product_name, p.generic_name, p.brand_name,
           c.category_name, p.strength, p.strength_unit, p.dosage_form,
           p.unit_type, p.units_per_pack, p.quantity_of_packs,
           p.sale_price, p.purchase_price, p.reorder_level,
           COALESCE(SUM(b.remaining_quantity), 0) AS current_stock
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN batches b ON b.product_id = p.id AND b.is_expired = 0 AND b.is_blocked = 0
    WHERE p.id IN ($placeholders)
    GROUP BY p.id
");
foreach ($productIds as $i => $id) {
    $db->bind($i+1, $id);
}
$products = $db->resultSet();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="exported_products_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// Headers (no BOM)
$headers = [
    'Product Code', 'Product Name', 'Generic Name', 'Brand', 'Category',
    'Strength', 'Unit', 'Dosage Form', 'Unit Type', 'Units/Pack', 'Qty Packs',
    'Sale Price (Rs)', 'Purchase Price (Rs)', 'Reorder Level', 'Current Stock'
];
fputcsv($output, $headers);

foreach ($products as $p) {
    fputcsv($output, [
        $p['product_code'],
        $p['product_name'],
        $p['generic_name'] ?? '',
        $p['brand_name'],
        $p['category_name'] ?? '',
        ($p['strength'] ? $p['strength'] . ' ' . $p['strength_unit'] : ''),
        $p['strength_unit'] ?? '',
        $p['dosage_form'] ?? '',
        $p['unit_type'],
        $p['units_per_pack'],
        $p['quantity_of_packs'],
        number_format($p['sale_price'], 2),
        number_format($p['purchase_price'], 2),
        $p['reorder_level'],
        $p['current_stock']
    ]);
}
fclose($output);
exit;
?>