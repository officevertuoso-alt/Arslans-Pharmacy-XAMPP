<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/purchase_functions.php';

requireLogin();

$variant_id = isset($_GET['variant_id']) ? (int)$_GET['variant_id'] : 0;
if (!$variant_id) {
    echo json_encode(['error' => 'Invalid variant ID']);
    exit();
}

$db = db();

$sql = "SELECT 
            id, product_name, unit_type, units_per_pack, quantity_of_packs, purchase_price, sale_price
        FROM products 
        WHERE id = :id AND status = 1";
$db->query($sql);
$db->bind(':id', $variant_id);
$variant = $db->single();

if (!$variant) {
    echo json_encode(['error' => 'Variant not found']);
    exit();
}

header('Content-Type: application/json');
echo json_encode($variant);
?>