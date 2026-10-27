<?php
/**
 * AJAX: Get product details for Add Purchase page and POS
 * Returns JSON with product info + FIFO batch data (nearest expiry first)
 * 
 * COMPLETE UPDATE v2:
 *  1. Returns all active batches for multi-batch POS support
 *  2. Includes per-batch reorder level
 *  3. Shows stock in pieces for accurate calculations
 *  4. FIFO ordering by expiry date
 *  5. Full product details with variants support
 */

require_once '../config/config.php';
require_once '../includes/product_functions.php';
require_once '../includes/auth.php';

// ISS-016 FIX: Require authentication — these endpoints expose product/pricing data
requireLogin();


header('Content-Type: application/json');

$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
if (!$product_id) {
    echo json_encode(['error' => 'Invalid product ID']);
    exit;
}

try {
    $pdo = _getPDO();
    
    // Get product basic info
    $product = _pdoOne("
        SELECT 
            p.id,
            p.product_name,
            p.product_code,
            p.brand_name,
            p.generic_name,
            p.category_id,
            p.strength,
            p.dosage_form,
            p.size_value,
            p.unit_type,
            p.units_per_pack,
            p.pack_type,
            p.purchase_price AS default_purchase_price,
            p.sale_price AS default_sale_price,
            p.barcode,
            p.image_path,
            p.parent_id,
            p.is_variant
        FROM products p
        WHERE p.id = :id AND p.status = 1
    ", [':id' => $product_id]);
    
    if (!$product) {
        echo json_encode(['error' => 'Product not found']);
        exit;
    }
    
    // Get ALL active batches (for multi-batch POS support) - FIFO order
    $batches = _pdoAll("
        SELECT 
            b.id AS batch_id,
            b.batch_number,
            b.remaining_quantity,
            b.purchase_price,
            b.selling_price,
            b.units_per_pack,
            b.quantity_of_packs,
            b.expiry_date,
            b.reorder_level,
            b.purchase_date,
            s.supplier_name,
            DATEDIFF(b.expiry_date, CURDATE()) AS days_until_expiry
        FROM batches b
        LEFT JOIN suppliers s ON b.supplier_id = s.id
        WHERE b.product_id = :pid
          AND b.remaining_quantity > 0
          AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
          AND COALESCE(b.is_expired, 0) = 0
        ORDER BY b.expiry_date ASC, b.id ASC
    ", [':pid' => $product_id]);
    
    // Get total stock from active batches
    $totalStock = 0;
    $activeBatchCount = 0;
    $fifoBatch = null;
    $nearestExpiry = null;
    $lowestReorderLevel = PHP_INT_MAX;
    
    foreach ($batches as $idx => $batch) {
        $totalStock += (int)$batch['remaining_quantity'];
        $activeBatchCount++;
        
        if ($idx === 0) {
            $fifoBatch = $batch;
        }
        
        if ($nearestExpiry === null || $batch['expiry_date'] < $nearestExpiry) {
            $nearestExpiry = $batch['expiry_date'];
        }
        
        $reorderLevel = (int)($batch['reorder_level'] ?? 10);
        if ($reorderLevel < $lowestReorderLevel) {
            $lowestReorderLevel = $reorderLevel;
        }
        
        // Format stock display for each batch
        $batches[$idx]['stock_display'] = formatStockDisplay(
            (int)$batch['remaining_quantity'],
            (int)($batch['units_per_pack'] ?? $product['units_per_pack'] ?? 1),
            $product['unit_type'],
            $product['dosage_form']
        );
        $batches[$idx]['expiry_display'] = formatExpiryDateForDisplay($batch['expiry_date']);
        $batches[$idx]['is_low_stock'] = ((int)$batch['remaining_quantity'] > 0 && 
                                          (int)$batch['remaining_quantity'] <= (int)($batch['reorder_level'] ?? 10));
    }
    
    // Get FIFO batch sale price (for default display)
    $fifoSellingPrice = $fifoBatch ? (float)$fifoBatch['selling_price'] : (float)($product['default_sale_price'] ?? 0);
    $fifoPurchasePrice = $fifoBatch ? (float)$fifoBatch['purchase_price'] : (float)($product['default_purchase_price'] ?? 0);
    $fifoUnitsPerPack = $fifoBatch ? (int)$fifoBatch['units_per_pack'] : (int)($product['units_per_pack'] ?? 1);
    
    // Get variants if this is a parent product
    $variants = [];
    if (($product['parent_id'] ?? 0) == 0) {
        $variants = _pdoAll("
            SELECT 
                v.id,
                v.product_name,
                v.product_code,
                v.strength,
                v.dosage_form,
                v.size_value,
                v.unit_type,
                v.units_per_pack,
                v.purchase_price,
                v.sale_price,
                COALESCE(SUM(b.remaining_quantity), 0) AS total_stock
            FROM products v
            LEFT JOIN batches b ON b.product_id = v.id AND b.remaining_quantity > 0 AND b.expiry_date >= CURDATE()
            WHERE v.parent_id = :pid AND v.status = 1
            GROUP BY v.id
        ", [':pid' => $product_id]);
    }
    
    // Check if product has variants (for purchase form)
    $hasVariants = count($variants) > 0;
    
    // Prepare response
    $response = [
        'id' => (int)$product['id'],
        'product_name' => $product['product_name'],
        'product_code' => $product['product_code'],
        'brand_name' => $product['brand_name'],
        'generic_name' => $product['generic_name'],
        'category_id' => (int)$product['category_id'],
        'strength' => $product['strength'],
        'dosage_form' => $product['dosage_form'],
        'size_value' => $product['size_value'],
        'unit_type' => $product['unit_type'],
        'units_per_pack' => (int)($product['units_per_pack'] ?? 1),
        'pack_type' => $product['pack_type'],
        'purchase_price' => $fifoPurchasePrice,
        'sale_price' => $fifoSellingPrice,
        'barcode' => $product['barcode'],
        'image_path' => $product['image_path'],
        'total_stock' => $totalStock,
        'active_batch_count' => $activeBatchCount,
        'nearest_expiry' => $nearestExpiry,
        'lowest_reorder_level' => ($lowestReorderLevel != PHP_INT_MAX) ? $lowestReorderLevel : 10,
        'has_stock' => $totalStock > 0,
        'has_variants' => $hasVariants,
        'variants' => $variants,
        'batches' => $batches,  // All active batches for multi-batch POS
        'fifo_batch' => $fifoBatch,
        'stock_display' => formatStockDisplay($totalStock, $fifoUnitsPerPack, $product['unit_type'], $product['dosage_form']),
        'expiry_display' => formatExpiryDateForDisplay($nearestExpiry),
        'units_per_pack_display' => formatUnitsPerPackDisplay($fifoUnitsPerPack, $product['unit_type'], $product['dosage_form'], $product['pack_type'])
    ];
    
    // Add variant info if this is a variant itself
    if (!empty($product['parent_id'])) {
        $parent = _pdoOne("SELECT product_name FROM products WHERE id = :id", [':id' => $product['parent_id']]);
        $response['parent_product_name'] = $parent['product_name'] ?? '';
        $response['is_variant'] = true;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("[get_product_details] Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>