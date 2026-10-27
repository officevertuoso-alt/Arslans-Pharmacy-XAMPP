<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/sale_functions.php';

requireLogin();

$db = db();

// Get all products with stock and variants
$sql = "SELECT 
            p.id as product_id,
            p.product_name,
            p.generic_name,
            p.brand_name,
            p.strength,
            p.strength_unit,
            p.dosage_form,
            p.unit_type,
            NULL as variant_id,
            NULL as variant_name,
            NULL as variant_value,
            COALESCE(SUM(CASE WHEN b.expiry_date > CURDATE() AND b.is_expired = 0 THEN b.remaining_quantity ELSE 0 END), 0) as total_stock,
            MIN(b.selling_price) as price
        FROM products p
        LEFT JOIN batches b ON p.id = b.product_id AND b.variant_id IS NULL
        WHERE p.status = 1
        GROUP BY p.id
        HAVING total_stock > 0
        
        UNION ALL
        
        SELECT 
            p.id as product_id,
            p.product_name,
            p.generic_name,
            p.brand_name,
            p.strength,
            p.strength_unit,
            p.dosage_form,
            p.unit_type,
            pv.id as variant_id,
            pv.variant_name,
            pv.variant_value,
            COALESCE(SUM(CASE WHEN b.expiry_date > CURDATE() AND b.is_expired = 0 AND b.variant_id = pv.id THEN b.remaining_quantity ELSE 0 END), 0) as total_stock,
            MIN(b.selling_price) as price
        FROM products p
        JOIN product_variants pv ON p.id = pv.product_id
        LEFT JOIN batches b ON p.id = b.product_id AND b.variant_id = pv.id
        WHERE p.status = 1
        GROUP BY p.id, pv.id
        HAVING total_stock > 0
        
        ORDER BY product_name ASC";

$db->query($sql);
$products = $db->resultSet();

if (count($products) > 0) {
    foreach ($products as $product) {
        $displayName = htmlspecialchars($product['product_name']);
        if (!empty($product['variant_name']) && !empty($product['variant_value'])) {
            $displayName .= ' <small class="text-muted">(' . htmlspecialchars($product['variant_name']) . ': ' . htmlspecialchars($product['variant_value']) . ')</small>';
        }
        
        $stockClass = $product['total_stock'] <= 0 ? 'stock-out' : ($product['total_stock'] <= 10 ? 'stock-low' : 'stock-normal');
        $strengthText = '';
        if (!empty($product['strength'])) {
            $strengthText = htmlspecialchars($product['strength'] . ' ' . $product['strength_unit']);
        } elseif (!empty($product['variant_value'])) {
            $strengthText = 'Size: ' . htmlspecialchars($product['variant_value']);
        } else {
            $strengthText = '-';
        }
        
        echo '<div class="product-row">
                <div class="product-col col-name"><strong>' . $displayName . '</strong></div>
                <div class="product-col col-brand">'