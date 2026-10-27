<?php
/**
 * AJAX: Get Stock Data
 * ajax/get_stock_data.php
 *
 * Method : GET
 * Params :
 *   category_id   (int,  optional) – filter by category
 *   low_stock_only (bool, optional) – only products below low_stock_threshold
 *   expired_only   (bool, optional) – only expired batches
 *
 * Returns: JSON array of products with stock summary + batch details
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('stock', 'view')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
require_once '../config/database.php';

// ── Always return JSON ────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ── Auth check ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
    exit;
}

// ── Input parameters ──────────────────────────────────────────────────────────
$category_id    = isset($_GET['category_id'])    && $_GET['category_id']    !== ''
    ? (int)$_GET['category_id'] : 0;

$low_stock_only = isset($_GET['low_stock_only'])
    && in_array($_GET['low_stock_only'], ['1', 'true', 'yes'], true);

$expired_only   = isset($_GET['expired_only'])
    && in_array($_GET['expired_only'], ['1', 'true', 'yes'], true);

// ── Fetch system low-stock threshold ─────────────────────────────────────────
$prefs = getOne("SELECT low_stock_threshold FROM system_preferences LIMIT 1", []);
$low_stock_threshold = (int)($prefs['low_stock_threshold'] ?? 10);

// ── Build dynamic conditions ───────────────────────────────────────────────────
$product_conditions = ["p.status = 1"];
$product_params     = [];

if ($category_id > 0) {
    $product_conditions[] = "p.category_id = :category_id";
    $product_params[':category_id'] = $category_id;
}

$batch_conditions = ["b.is_blocked = 0"];

if ($expired_only) {
    $batch_conditions[] = "(b.is_expired = 1 OR (b.expiry_date != '0000-00-00' AND b.expiry_date < CURDATE()))";
}

$product_where = implode(' AND ', $product_conditions);
$batch_where   = implode(' AND ', $batch_conditions);

// ── Main query: product-level stock summary ───────────────────────────────────
$sql = "
    SELECT
        p.id                              AS product_id,
        p.product_code,
        p.product_name,
        p.generic_name,
        p.brand_name,
        p.strength,
        p.dosage_form,
        p.unit_type,
        p.pack_quantity,
        p.category_id,
        c.category_name,
        p.low_stock_alert,
        p.min_stock_level,
        p.selling_price                   AS default_selling_price,
        p.purchase_price                  AS default_purchase_price,

        -- Aggregate stock from non-blocked batches
        COALESCE(SUM(CASE WHEN {$batch_where} THEN b.remaining_quantity ELSE 0 END), 0)
                                          AS total_stock,
        COUNT(CASE WHEN {$batch_where} AND b.remaining_quantity > 0 THEN 1 END)
                                          AS active_batch_count,
        COUNT(CASE WHEN b.is_expired = 1 OR
                        (b.expiry_date != '0000-00-00' AND b.expiry_date < CURDATE())
                   THEN 1 END)            AS expired_batch_count,
        MIN(CASE WHEN {$batch_where} AND b.remaining_quantity > 0
                      AND b.expiry_date != '0000-00-00'
                 THEN b.expiry_date END)  AS nearest_expiry,

        -- Stock value = remaining_qty × purchase_price per piece
        COALESCE(SUM(CASE WHEN {$batch_where}
                     THEN b.remaining_quantity * b.purchase_price
                     ELSE 0 END), 0)       AS stock_value

    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN batches    b ON b.product_id = p.id
    WHERE {$product_where}
    GROUP BY
        p.id, p.product_code, p.product_name, p.generic_name, p.brand_name,
        p.strength, p.dosage_form, p.unit_type, p.pack_quantity,
        p.category_id, c.category_name, p.low_stock_alert, p.min_stock_level,
        p.selling_price, p.purchase_price
    HAVING 1 = 1
";

// Apply low-stock HAVING filter
if ($low_stock_only) {
    $sql .= " HAVING total_stock <= :low_threshold AND total_stock >= 0";
    $product_params[':low_threshold'] = $low_stock_threshold;
}

$sql .= " ORDER BY p.product_name ASC";

try {
    $products = getAll($sql, $product_params);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error. Please contact administrator.',
    ]);
    exit;
}

// ── Bail early if no products match ──────────────────────────────────────────
if (empty($products)) {
    echo json_encode([
        'success'   => true,
        'total'     => 0,
        'products'  => [],
        'summary'   => [
            'total_products'  => 0,
            'total_stock'     => 0,
            'total_value'     => 0,
            'low_stock_count' => 0,
            'expired_count'   => 0,
        ],
    ]);
    exit;
}

// ── Fetch batch details for each product ─────────────────────────────────────
$product_ids      = array_column($products, 'product_id');
$placeholders     = implode(',', array_fill(0, count($product_ids), '?'));

$batch_sql = "
    SELECT
        b.id                AS batch_id,
        b.batch_number,
        b.product_id,
        b.variant_id,
        pv.variant_value   AS variant_name,
        b.supplier_id,
        s.supplier_name,
        b.purchase_price,
        b.selling_price,
        b.pack_quantity,
        b.pieces_per_pack,
        b.units_per_pack,
        b.quantity_of_packs,
        b.total_pieces,
        b.remaining_quantity,
        b.manufacture_date,
        b.expiry_date,
        b.purchase_date,
        b.is_expired,
        b.is_blocked,
        -- Days until expiry (NULL if no valid date)
        CASE
            WHEN b.expiry_date = '0000-00-00' THEN NULL
            ELSE DATEDIFF(b.expiry_date, CURDATE())
        END                AS days_to_expiry,
        -- Batch stock value
        (b.remaining_quantity * b.purchase_price) AS batch_stock_value
    FROM batches b
    LEFT JOIN product_variants pv ON pv.id = b.variant_id
    LEFT JOIN suppliers        s  ON s.id  = b.supplier_id
    WHERE b.product_id IN ({$placeholders})
      AND b.is_blocked = 0
    ORDER BY b.product_id, b.expiry_date ASC, b.purchase_date ASC
";

try {
    $all_batches = getAll($batch_sql, $product_ids);
} catch (Exception $e) {
    $all_batches = [];
}

// ── Group batches by product_id ───────────────────────────────────────────────
$batches_by_product = [];
foreach ($all_batches as $batch) {
    $batches_by_product[(int)$batch['product_id']][] = $batch;
}

// ── Build final response ──────────────────────────────────────────────────────
$result_products  = [];
$summary_total_stock = 0;
$summary_total_value = 0;
$low_stock_count     = 0;
$expired_count       = 0;

foreach ($products as $product) {
    $pid        = (int)$product['product_id'];
    $batches    = $batches_by_product[$pid] ?? [];
    $total_stock = (int)$product['total_stock'];
    $stock_value = (float)$product['stock_value'];

    // Stock status
    $effective_threshold = $product['min_stock_level'] > 0
        ? (int)$product['min_stock_level']
        : $low_stock_threshold;

    if ($total_stock <= 0) {
        $stock_status = 'out_of_stock';
    } elseif ($total_stock <= $effective_threshold) {
        $stock_status = 'low';
        $low_stock_count++;
    } else {
        $stock_status = 'ok';
    }

    // Expired batch count for this product
    $prod_expired = (int)($product['expired_batch_count'] ?? 0);
    if ($prod_expired > 0) {
        $expired_count++;
    }

    // Apply expired_only filter at PHP level (belt-and-suspenders)
    if ($expired_only && $prod_expired === 0) {
        continue;
    }

    $summary_total_stock += $total_stock;
    $summary_total_value += $stock_value;

    $result_products[] = [
        // ── Product info ──────────────────────────────────────────────────────
        'product_id'            => $pid,
        'product_code'          => $product['product_code'],
        'product_name'          => $product['product_name'],
        'generic_name'          => $product['generic_name'],
        'brand_name'            => $product['brand_name'],
        'strength'              => $product['strength'],
        'dosage_form'           => $product['dosage_form'],
        'unit_type'             => $product['unit_type'],
        'pack_quantity'         => (int)$product['pack_quantity'],
        'category_id'           => (int)$product['category_id'],
        'category_name'         => $product['category_name'],
        'default_selling_price' => (float)$product['default_selling_price'],
        'default_purchase_price'=> (float)$product['default_purchase_price'],
        'min_stock_level'       => (int)($product['min_stock_level'] ?? 0),
        'low_stock_threshold'   => $effective_threshold,

        // ── Stock summary ─────────────────────────────────────────────────────
        'total_stock'           => $total_stock,
        'active_batch_count'    => (int)$product['active_batch_count'],
        'expired_batch_count'   => $prod_expired,
        'nearest_expiry'        => $product['nearest_expiry'],
        'stock_value'           => round($stock_value, 2),
        'stock_status'          => $stock_status,   // ok | low | out_of_stock

        // ── Batch details ─────────────────────────────────────────────────────
        'batches' => array_map(function ($b) {
            return [
                'batch_id'         => (int)$b['batch_id'],
                'batch_number'     => $b['batch_number'],
                'variant_name'     => $b['variant_name'],
                'supplier_name'    => $b['supplier_name'],
                'purchase_price'   => (float)$b['purchase_price'],
                'selling_price'    => (float)$b['selling_price'],
                'pack_quantity'    => (int)$b['pack_quantity'],
                'pieces_per_pack'  => (int)$b['pieces_per_pack'],
                'units_per_pack'   => (int)$b['units_per_pack'],
                'quantity_of_packs'=> (int)$b['quantity_of_packs'],
                'total_pieces'     => (int)$b['total_pieces'],
                'remaining_quantity'=> (int)$b['remaining_quantity'],
                'manufacture_date' => $b['manufacture_date'],
                'expiry_date'      => $b['expiry_date'] !== '0000-00-00' ? $b['expiry_date'] : null,
                'purchase_date'    => $b['purchase_date'],
                'is_expired'       => (bool)$b['is_expired'],
                'days_to_expiry'   => $b['days_to_expiry'] !== null ? (int)$b['days_to_expiry'] : null,
                'batch_stock_value'=> round((float)$b['batch_stock_value'], 2),
            ];
        }, $batches),
    ];
}

// ── Overall summary ───────────────────────────────────────────────────────────
$response = [
    'success' => true,
    'total'   => count($result_products),
    'summary' => [
        'total_products'    => count($result_products),
        'total_stock_units' => $summary_total_stock,
        'total_stock_value' => round($summary_total_value, 2),
        'low_stock_count'   => $low_stock_count,
        'expired_count'     => $expired_count,
        'low_stock_threshold' => $low_stock_threshold,
    ],
    'filters' => [
        'category_id'    => $category_id,
        'low_stock_only' => $low_stock_only,
        'expired_only'   => $expired_only,
    ],
    'products' => $result_products,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;