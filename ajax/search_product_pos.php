<?php
// ISS-012 NOTICE: Duplicate search endpoint
// PHASE-24 AUDIT: LIMIT raised 500→1000 (live mode) and 80→200 (HTML mode) — consolidate to ajax/search_products.php
// This file is preserved for backward compatibility. Do not add new features here.
/**
 * AJAX: Search products for POS
 * 
 * FIXES:
 *  1. Stock discrepancy: Both POS and products/list use identical expiry condition
 *     (expiry_date > CURDATE()  — NOT >=).  The list uses > CURDATE() in
 *     getFilteredProducts(); the old POS used >= CURDATE(), counting today-expired
 *     batches as in-stock.  Now aligned: > CURDATE() everywhere.
 *  2. Excludes soft-deleted products (deleted_at IS NULL).
 *  3. near_expiry_days now read from company_settings (not hardcoded 30).
 *  4. currency_symbol returned in payload for POS JS to use.
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/sale_functions.php';
require_once '../includes/settings_functions.php';

requireLogin();

$search  = isset($_GET['search'])  ? trim($_GET['search']) : '';
$live    = isset($_GET['live'])    ? (int)$_GET['live']    : 0;
$instock = isset($_GET['instock']) ? (int)$_GET['instock'] : 1;
$all     = isset($_GET['all'])     ? (int)$_GET['all']     : 0;
$debug   = isset($_GET['debug'])   ? trim($_GET['debug'])  : '';

$db = db();

// Load company settings for near_expiry_days and currency
$_co           = getCompanySettings();
$nearExpiryDaysThreshold = (int)($_co['near_expiry_days'] ?? 30);
$currencySymbol          = $_co['currency_symbol'] ?? 'Rs';

// ==========================================================================
// DEBUG MODE: ?debug=productname  →  shows raw batch rows for that product
// ==========================================================================
if ($debug !== '') {
    header('Content-Type: application/json');
    $pdo = $db->getConnection();
    $stmt = $pdo->prepare("
        SELECT b.id, b.batch_number, b.remaining_quantity, b.total_pieces,
               b.units_per_pack, b.quantity_of_packs, b.selling_price,
               b.expiry_date, b.is_expired, b.purchase_date,
               p.id AS product_id, p.product_name, p.units_per_pack AS product_upp
        FROM batches b
        JOIN products p ON p.id = b.product_id
        WHERE p.product_name LIKE :s
          AND p.status = 1
          AND p.deleted_at IS NULL
        ORDER BY p.product_name, b.expiry_date ASC, b.id ASC
    ");
    $stmt->execute([':s' => '%' . $debug . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totals = [];
    foreach ($rows as $r) {
        $pid = $r['product_id'];
        if (!isset($totals[$pid])) {
            $totals[$pid] = [
                'product_name'   => $r['product_name'],
                'product_upp'    => $r['product_upp'],
                'sum_remaining'  => 0,
                'active_batches' => [],
            ];
        }
        // FIXED: use > CURDATE() logic (strictly after today)
        $expired = $r['is_expired'] || ($r['expiry_date'] && $r['expiry_date'] <= date('Y-m-d'));
        if (!$expired && $r['remaining_quantity'] > 0) {
            $totals[$pid]['sum_remaining'] += (int)$r['remaining_quantity'];
            $totals[$pid]['active_batches'][] = [
                'batch_id'           => $r['id'],
                'remaining_quantity' => (int)$r['remaining_quantity'],
                'units_per_pack'     => (int)$r['units_per_pack'],
                'expiry_date'        => $r['expiry_date'],
                'is_expired'         => (int)$r['is_expired'],
            ];
        }
    }
    echo json_encode(
        ['raw_batches' => $rows, 'computed_totals' => array_values($totals)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ==========================================================================
// AJAX JSON RESPONSE (live=1) — used by POS frontend
// ==========================================================================
if ($live == 1) {

    $having = $instock == 1 ? "HAVING total_stock > 0" : "";

    // CRITICAL FIX: Use > CURDATE() (strictly after today) everywhere to match
    // products/list.php getFilteredProducts() which uses expiry_date > CURDATE().
    // Old code used >= CURDATE() which counted today-expiring batches as in-stock,
    // causing the POS to show higher stock than the products list.

    $sql = "
        SELECT
            p.id,
            p.product_code,
            p.product_name,
            p.generic_name,
            p.brand_name,
            p.category_id,
            p.strength,
            p.dosage_form,
            p.size_value,
            p.unit_type,
            p.units_per_pack AS product_units_per_pack,
            
            /* FIFO batch: earliest expiry with stock (strictly > today) */
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
            
            (SELECT COUNT(*)
             FROM batches b2
             WHERE b2.product_id = p.id
               AND b2.remaining_quantity > 0
               AND b2.is_expired = 0
               AND (b2.expiry_date IS NULL OR b2.expiry_date > CURDATE())
            ) AS active_batch_count,
            
            /* FIXED: use > CURDATE() to match products/list.php */
            COALESCE((
                SELECT SUM(b3.remaining_quantity)
                FROM batches b3
                WHERE b3.product_id = p.id
                  AND b3.remaining_quantity > 0
                  AND b3.is_expired = 0
                  AND (b3.expiry_date IS NULL OR b3.expiry_date > CURDATE())
            ), 0) AS total_stock,
            
            /* Near-expiry: formatted date */
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
        WHERE p.status = 1
          AND p.deleted_at IS NULL
        GROUP BY p.id
        $having
        ORDER BY p.product_name ASC
        LIMIT 1000
    ";

    try {
        $stmt = $db->getConnection()->prepare($sql);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }

    $out = [];
    foreach ($products as $p) {
        $unitsPerPack = $p['fifo_units_per_pack'] !== null
            ? (int)$p['fifo_units_per_pack']
            : (int)($p['product_units_per_pack'] ?? 1);
        if ($unitsPerPack < 1) $unitsPerPack = 1;

        $nearExpiryDays   = ($p['near_expiry_days'] !== null) ? (int)$p['near_expiry_days'] : null;
        $nearExpiryPrice  = ($p['sale_price']       !== null) ? (float)$p['sale_price']      : null;
        $nearExpiryDate   = $p['near_expiry_date']  ?? null;
        $activeBatchCount = (int)($p['active_batch_count'] ?? 0);
        $pid              = (int)$p['id'];
        $totalStock       = (int)$p['total_stock'];

        // Flag as near-expiry based on company settings threshold
        $isNearExpiry = ($nearExpiryDays !== null && $nearExpiryDays <= $nearExpiryDaysThreshold);

        $out[] = [
            'id'                 => $pid,
            'product_code'       => $p['product_code']  ?? '',
            'product_name'       => $p['product_name']  ?? '',
            'generic_name'       => $p['generic_name']  ?? '',
            'brand_name'         => $p['brand_name']    ?? '',
            'category_id'        => (int)$p['category_id'],
            'strength'           => $p['strength']      ?? '',
            'dosage_form'        => $p['dosage_form']   ?? '',
            'size_value'         => $p['size_value']    ?? '',
            'unit_type'          => $p['unit_type']     ?? '',
            'units_per_pack'     => $unitsPerPack,
            'sale_price'         => (float)($p['sale_price'] ?? 0),
            'price'              => (float)($p['sale_price'] ?? 0),
            'near_expiry_price'  => $nearExpiryPrice,
            'near_expiry_date'   => $nearExpiryDate,
            'near_expiry_days'   => $nearExpiryDays,
            'is_near_expiry'     => $isNearExpiry,
            'total_stock'        => $totalStock,
            'active_batch_count' => $activeBatchCount,
            'currency_symbol'    => $currencySymbol,
        ];
    }

    header('Content-Type: application/json');
    echo json_encode(array_values($out));
    exit;
}

// ==========================================================================
// HTML RESPONSE (live=0) — legacy fallback
// ==========================================================================
$whereParts = ["p.status = 1", "p.deleted_at IS NULL"];
$params     = [];

if ($all != 1 && $search !== '') {
    $whereParts[] = "(
        p.product_name  LIKE :search
        OR p.product_code LIKE :search
        OR p.generic_name LIKE :search
        OR p.brand_name   LIKE :search
        OR p.size_value   LIKE :search
        OR p.strength     LIKE :search
        OR p.dosage_form  LIKE :search
    )";
    $params[':search'] = "%$search%";
}

$where  = implode(' AND ', $whereParts);
$having = $instock == 1 ? "HAVING total_stock > 0" : "";

// FIXED: > CURDATE() here too
$sql = "
    SELECT
        p.id,
        p.product_code,
        p.product_name,
        p.generic_name,
        p.brand_name,
        p.category_id,
        p.strength,
        p.dosage_form,
        p.size_value,
        p.unit_type,
        p.units_per_pack,
        COALESCE((
            SELECT b2.selling_price
            FROM batches b2
            WHERE b2.product_id = p.id
              AND b2.remaining_quantity > 0
              AND b2.is_expired = 0
              AND (b2.expiry_date IS NULL OR b2.expiry_date > CURDATE())
            ORDER BY b2.expiry_date ASC, b2.id ASC
            LIMIT 1
        ), 0) AS sale_price,
        COALESCE((
            SELECT SUM(b3.remaining_quantity)
            FROM batches b3
            WHERE b3.product_id = p.id
              AND b3.remaining_quantity > 0
              AND b3.is_expired = 0
              AND (b3.expiry_date IS NULL OR b3.expiry_date > CURDATE())
        ), 0) AS total_stock
    FROM products p
    WHERE $where
    GROUP BY p.id
    $having
    ORDER BY p.product_name ASC
    LIMIT 200
";

try {
    $stmt = $db->getConnection()->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo '<div class="text-center py-4 text-danger small">Query error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

if (empty($products)) {
    echo '<div class="text-center py-5 text-muted small"><i class="fas fa-search fa-2x mb-2 d-block"></i>No products found</div>';
    exit;
}

foreach ($products as $p):
    $totalStock   = (int)$p['total_stock'];
    $price        = (float)$p['sale_price'];
    $isMed        = ((int)$p['category_id'] === 1);
    $productCode  = htmlspecialchars($p['product_code'] ?? '');
    $productName  = htmlspecialchars($p['product_name'] ?? '');
    $brandGeneric = $isMed
        ? htmlspecialchars($p['generic_name'] ?? '-')
        : htmlspecialchars($p['brand_name']   ?? '-');

    if ($isMed) {
        $ss = trim(htmlspecialchars($p['strength'] ?? '') . ' ' . htmlspecialchars($p['dosage_form'] ?? ''));
        $strengthSize = $ss ?: '-';
    } else {
        $strengthSize = htmlspecialchars($p['size_value'] ?? '-');
    }

    $unitPack = ($p['units_per_pack'] ?? 0)
        ? $p['units_per_pack'] . '/' . ($p['unit_type'] ?? '')
        : ($p['unit_type'] ?? '');
    $stockCls = $totalStock <= 0 ? 'stock-out' : ($totalStock <= 10 ? 'stock-low' : 'stock-normal');
?>
<div class="product-row">
    <div class="product-col col-name"><strong><?= $productName ?></strong><br><small class="text-muted"><?= $productCode ?></small></div>
    <div class="product-col col-brand"><small><?= $brandGeneric ?></small></div>
    <div class="product-col col-detail"><small><?= $strengthSize ?></small></div>
    <div class="product-col col-unit"><small><?= htmlspecialchars($unitPack) ?></small></div>
    <div class="product-col col-stock <?= $stockCls ?>"><?= $totalStock ?></div>
    <div class="product-col col-price"><?= htmlspecialchars($currencySymbol) ?> <?= number_format($price, 2) ?></div>
</div>
<?php endforeach;