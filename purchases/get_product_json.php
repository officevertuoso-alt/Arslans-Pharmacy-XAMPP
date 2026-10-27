<?php
/**
 * purchases/get_product_json.php
 * Returns single product as JSON for add.php prefill (called from view.php → Add Stock/Batch).
 * FIXED: Uses requirePharmacist() instead of requireLogin()+canDo() which may not exist.
 *        Safe column access — no hardcoded columns that may be missing.
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/product_functions.php';

// Use the same auth guard as the rest of the purchases module
requirePharmacist();

header('Content-Type: application/json; charset=utf-8');

$productId = (int)($_GET['id'] ?? 0);

if ($productId <= 0) {
    echo json_encode(['error' => 'Invalid product ID']);
    exit;
}

try {
    $pdo = _getPDO();

    // ── Detect which optional columns exist ──────────────────────────────────
    static $productCols = null;
    if ($productCols === null) {
        $productCols = array_column(
            $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
    }

    // Build safe extra WHERE conditions for soft-delete columns
    $deletedCond = " AND p.status = 1";
    if (in_array('deleted_at', $productCols)) $deletedCond .= " AND p.deleted_at IS NULL";
    if (in_array('is_deleted',  $productCols)) $deletedCond .= " AND p.is_deleted = 0";
    if (in_array('trash',       $productCols)) $deletedCond .= " AND p.trash = 0";

    // ── Safe optional column selects ─────────────────────────────────────────
    // purchase_price / sale_price may not exist on products table in all installs
    $ppSelect  = in_array('purchase_price', $productCols) ? 'p.purchase_price' : 'NULL';
    $spSelect  = in_array('sale_price',     $productCols) ? 'p.sale_price'     : 'NULL';
    $packType  = in_array('pack_type',      $productCols) ? 'p.pack_type'      : "''";

    $sql = "
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
            {$packType}        AS pack_type,
            {$ppSelect}        AS default_purchase_price,
            {$spSelect}        AS default_sale_price,

            -- FIFO: earliest non-expired, in-stock batch sale price
            COALESCE(
                (SELECT b.selling_price
                 FROM batches b
                 WHERE b.product_id = p.id
                   AND b.remaining_quantity > 0
                   AND COALESCE(b.is_expired, 0) = 0
                   AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
                 ORDER BY b.expiry_date ASC, b.id ASC
                 LIMIT 1),
                {$spSelect},
                0
            ) AS fifo_sale_price,

            -- FIFO: earliest non-expired, in-stock batch purchase price
            COALESCE(
                (SELECT b.purchase_price
                 FROM batches b
                 WHERE b.product_id = p.id
                   AND b.remaining_quantity > 0
                   AND COALESCE(b.is_expired, 0) = 0
                   AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
                 ORDER BY b.expiry_date ASC, b.id ASC
                 LIMIT 1),
                {$ppSelect},
                0
            ) AS fifo_purchase_price,

            -- FIFO: units_per_pack from earliest active batch
            COALESCE(
                (SELECT b.units_per_pack
                 FROM batches b
                 WHERE b.product_id = p.id
                   AND b.remaining_quantity > 0
                   AND COALESCE(b.is_expired, 0) = 0
                   AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
                 ORDER BY b.expiry_date ASC, b.id ASC
                 LIMIT 1),
                p.units_per_pack,
                1
            ) AS fifo_units_per_pack,

            -- Total active stock (pieces)
            COALESCE(
                (SELECT SUM(b.remaining_quantity)
                 FROM batches b
                 WHERE b.product_id = p.id
                   AND b.remaining_quantity > 0
                   AND COALESCE(b.is_expired, 0) = 0
                   AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())),
                0
            ) AS total_stock,

            -- Number of active batches
            (SELECT COUNT(*)
             FROM batches b
             WHERE b.product_id = p.id
               AND b.remaining_quantity > 0
               AND COALESCE(b.is_expired, 0) = 0
               AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
            ) AS active_batch_count,

            -- Minimum reorder level across active batches
            (SELECT MIN(b.reorder_level)
             FROM batches b
             WHERE b.product_id = p.id
               AND b.remaining_quantity > 0
               AND COALESCE(b.is_expired, 0) = 0
               AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE())
            ) AS min_reorder_level

        FROM products p
        WHERE p.id = :id
          {$deletedCond}
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {
        // Cast numeric fields so JS gets numbers not strings
        $product['id']                    = (int)$product['id'];
        $product['category_id']           = (int)$product['category_id'];
        $product['units_per_pack']        = (int)$product['units_per_pack'];
        $product['fifo_units_per_pack']   = (int)$product['fifo_units_per_pack'];
        $product['fifo_sale_price']       = (float)$product['fifo_sale_price'];
        $product['fifo_purchase_price']   = (float)$product['fifo_purchase_price'];
        $product['default_sale_price']    = (float)$product['default_sale_price'];
        $product['default_purchase_price']= (float)$product['default_purchase_price'];
        $product['total_stock']           = (int)$product['total_stock'];
        $product['active_batch_count']    = (int)$product['active_batch_count'];
        $product['min_reorder_level']     = (int)($product['min_reorder_level'] ?? 10);

        echo json_encode($product, JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['error' => 'Product not found or has been deleted.']);
    }

} catch (\Throwable $e) {
    error_log("get_product_json.php error: " . $e->getMessage());
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}