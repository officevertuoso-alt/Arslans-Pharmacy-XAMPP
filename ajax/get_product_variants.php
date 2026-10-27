<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/purchase_functions.php';

requireLogin();

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$variants = getProductVariants($productId);

header('Content-Type: application/json');
echo json_encode($variants);
?>