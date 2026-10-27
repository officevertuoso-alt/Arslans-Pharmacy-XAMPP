<?php
/**
 * ajax/get_product_by_barcode.php  — Phase 24 POS Audit Fix
 *
 * BUGS FIXED:
 *  1. Used p.is_active = 1  → products table has no is_active column (uses p.status = 1).
 *     This caused ALL barcode lookups to silently return 0 rows.
 *  2. Missing p.deleted_at IS NULL guard (soft-delete safety).
 *  3. Missing FIFO batch data: sale_price was read from products.sale_price (often 0)
 *     instead of the earliest valid batch's selling_price.
 *  4. Missing near_expiry_price / near_expiry_days / near_expiry_date fields
 *     that pos.php JS expects for add-to-cart.
 *  5. Missing currency_symbol in response (POS JS uses it for display).
 *  6. Barcode looked up in product_barcodes table which does NOT EXIST in schema.
 *     Products have product_code which is the barcode used at POS.
 *     Fixed: search products.product_code first, then product_barcodes if table exists.
 *
 * INPUT  (GET): barcode=XXXXXXXXX
 * OUTPUT (JSON): { success, product } — same shape as search_product_pos.php rows
 */
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/settings_functions.php';

requireLogin();
header('Content-Type: application/json');

/* ── Input validation ── */
$barcode = trim($_GET['barcode'] ?? '');

if ($barcode === '' || strlen($barcode) < 3) {
    echo json_encode(['success' => false, 'message' => 'Barcode too short or missing']);
    exit();
}

try {
    $db  = db();
    $pdo = $db->getConnection();

    // Load company settings
    $_co            = getCompanySettings();
    $nearExpiryDays = (int)($_co['near_expiry_days'] ?? 30);
    $currencySymbol = $_co['currency_symbol'] ?? 'Rs';

    /*
     * SEARCH STRATEGY
     * 1. Match products.product_code  (primary — this IS the barcode in this schema)
     * 2. If product_barcodes table exists, also search there
     *
     * We build the same rich column set that search_product_pos.php returns
     * so that pos.php addToCart() / handleBarcodeLookup() works identically.
     */
    $sql = "
        SELECT
            p.id,
            p.product_code,
            p.product_name,
            p.generic_name,
            p.brand_name,
            p.category_id,
            p.unit_type,
            p.units_per_pack       AS product_units_per_pack,
            p.dosage_form,
            p.strength,
            p.strength_unit,
            p.size_value,

            /* FIFO: earliest valid batch */
            (SELECT b2.units_per_pack
             FROM batches b2
             WHERE b2.product_id = p.id
               AND b2.remaining_quantity > 0
               AND b2.is_expired = 0
               AND (b2.expiry_date IS NULL OR b2.expiry_date > CURDATE())
             ORDER BY b2.expiry_date ASC, b2.id ASC
             LIMIT 1) AS fifo_units_per_pack,

            (SELECT b2.selling_price
             FROM batches b2
             WHERE b2.product_id = p.id
               AND b2.remaining_quantity > 0
               AND b2.is_expired = 0
               AND (b2.expiry_date IS NULL OR b2.expiry_date > CURDATE())
             ORDER BY b2.expiry_date ASC, b2.id ASC
             LIMIT 1) AS sale_price,

            /* Total in-stock pieces (strictly > today) */
            COALESCE((
                SELECT SUM(b3.remaining_quantity)
                FROM batches b3
                WHERE b3.product_id = p.id
                  AND b3.remaining_quantity > 0
                  AND b3.is_expired = 0
                  AND (b3.expiry_date IS NULL OR b3.expiry_date > CURDATE())
            ), 0) AS total_stock,

            /* Near-expiry date (earliest expiring batch) */
            (SELECT DATE_FORMAT(b4.expiry_date, '%d-%b-%Y')
             FROM batches b4
             WHERE b4.product_id = p.id
               AND b4.remaining_quantity > 0
               AND b4.is_expired = 0
               AND b4.expiry_date IS NOT NULL
               AND b4.expiry_date > CURDATE()
             ORDER BY b4.expiry_date ASC, b4.id ASC
             LIMIT 1) AS near_expiry_date,

            /* Days until nearest expiry */
            (SELECT DATEDIFF(b5.expiry_date, CURDATE())
             FROM batches b5
             WHERE b5.product_id = p.id
               AND b5.remaining_quantity > 0
               AND b5.is_expired = 0
               AND b5.expiry_date IS NOT NULL
               AND b5.expiry_date > CURDATE()
             ORDER BY b5.expiry_date ASC, b5.id ASC
             LIMIT 1) AS near_expiry_days

        FROM products p
        WHERE p.product_code = :barcode
          AND p.status = 1
          AND p.deleted_at IS NULL
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':barcode' => $barcode]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    /* If not found by product_code, try product_barcodes table (if it exists) */
    if (!$product) {
        // Check if product_barcodes table exists
        $chk = $pdo->query("SHOW TABLES LIKE 'product_barcodes'")->fetchColumn();
        if ($chk) {
            $sql2 = "
                SELECT
                    p.id, p.product_code, p.product_name, p.generic_name, p.brand_name,
                    p.category_id, p.unit_type, p.units_per_pack AS product_units_per_pack,
                    p.dosage_form, p.strength, p.strength_unit, p.size_value,
                    (SELECT b2.units_per_pack FROM batches b2
                     WHERE b2.product_id = p.id AND b2.remaining_quantity > 0
                       AND b2.is_expired = 0 AND (b2.expiry_date IS NULL OR b2.expiry_date > CURDATE())
                     ORDER BY b2.expiry_date ASC, b2.id ASC LIMIT 1) AS fifo_units_per_pack,
                    (SELECT b2.selling_price FROM batches b2
                     WHERE b2.product_id = p.id AND b2.remaining_quantity > 0
                       AND b2.is_expired = 0 AND (b2.expiry_date IS NULL OR b2.expiry_date > CURDATE())
                     ORDER BY b2.expiry_date ASC, b2.id ASC LIMIT 1) AS sale_price,
                    COALESCE((SELECT SUM(b3.remaining_quantity) FROM batches b3
                     WHERE b3.product_id = p.id AND b3.remaining_quantity > 0
                       AND b3.is_expired = 0 AND (b3.expiry_date IS NULL OR b3.expiry_date > CURDATE())), 0) AS total_stock,
                    (SELECT DATE_FORMAT(b4.expiry_date,'%d-%b-%Y') FROM batches b4
                     WHERE b4.product_id = p.id AND b4.remaining_quantity > 0 AND b4.is_expired = 0
                       AND b4.expiry_date IS NOT NULL AND b4.expiry_date > CURDATE()
                     ORDER BY b4.expiry_date ASC, b4.id ASC LIMIT 1) AS near_expiry_date,
                    (SELECT DATEDIFF(b5.expiry_date, CURDATE()) FROM batches b5
                     WHERE b5.product_id = p.id AND b5.remaining_quantity > 0 AND b5.is_expired = 0
                       AND b5.expiry_date IS NOT NULL AND b5.expiry_date > CURDATE()
                     ORDER BY b5.expiry_date ASC, b5.id ASC LIMIT 1) AS near_expiry_days
                FROM products p
                INNER JOIN product_barcodes pb ON pb.product_id = p.id
                WHERE pb.barcode = :barcode
                  AND p.status = 1
                  AND p.deleted_at IS NULL
                LIMIT 1
            ";
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute([':barcode' => $barcode]);
            $product = $stmt2->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$product) {
        echo json_encode([
            'success' => false,
            'message' => 'No product found for barcode: ' . htmlspecialchars($barcode)
        ]);
        exit();
    }

    /* Normalise units_per_pack */
    $upp = $product['fifo_units_per_pack'] !== null
        ? (int)$product['fifo_units_per_pack']
        : (int)($product['product_units_per_pack'] ?? 1);
    if ($upp < 1) $upp = 1;

    $nearDays  = $product['near_expiry_days'] !== null ? (int)$product['near_expiry_days'] : null;
    $salePrice = (float)($product['sale_price'] ?? 0);

    echo json_encode([
        'success' => true,
        'product' => [
            'id'                 => (int)$product['id'],
            'product_code'       => $product['product_code']  ?? '',
            'product_name'       => $product['product_name']  ?? '',
            'generic_name'       => $product['generic_name']  ?? '',
            'brand_name'         => $product['brand_name']    ?? '',
            'category_id'        => (int)$product['category_id'],
            'unit_type'          => $product['unit_type']     ?? 'Pcs',
            'units_per_pack'     => $upp,
            'dosage_form'        => $product['dosage_form']   ?? '',
            'strength'           => $product['strength']      ?? '',
            'strength_unit'      => $product['strength_unit'] ?? '',
            'size_value'         => $product['size_value']    ?? '',
            'sale_price'         => $salePrice,
            'price'              => $salePrice,
            'near_expiry_price'  => $salePrice,
            'near_expiry_date'   => $product['near_expiry_date']  ?? null,
            'near_expiry_days'   => $nearDays,
            'is_near_expiry'     => ($nearDays !== null && $nearDays <= $nearExpiryDays),
            'total_stock'        => (int)$product['total_stock'],
            'currency_symbol'    => $currencySymbol,
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
