<?php
// ISS-012 NOTICE: Duplicate search endpoint — consolidate to ajax/search_products.php
// This file is preserved for backward compatibility. Do not add new features here.
/**
 * products/live_search_products.php — FINAL FIXED VERSION
 *
 * ROOT CAUSE FOUND (via debug):
 *   The is_variant filter "AND NOT (COALESCE(p.is_variant,0) = 1 AND (p.parent_id IS NULL OR p.parent_id = 0))"
 *   was blocking ALL real active products (ids 121,122,123) because they are stored
 *   as variants (is_variant=1) without a parent_id — the filter wrongly treats them
 *   as "orphaned variants" and excludes them, leaving 0 results.
 *
 * FIX:
 *   Changed variant filter logic: only exclude if is_variant=1 AND parent_id IS NOT NULL
 *   (i.e. only hide child variants that have a real parent, not standalone products).
 *   This way top-level products always show regardless of is_variant flag.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/config.php';
require_once '../includes/product_functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

header('Content-Type: text/html; charset=utf-8');

$q            = trim($_GET['q']             ?? '');
$searchType   = trim($_GET['type']          ?? 'all');
$stockFilter  = trim($_GET['stock_filter']  ?? 'all');
$expiryFilter = trim($_GET['expiry_filter'] ?? 'all');

if (strlen($q) < 2) {
    echo '<div class="p-3 text-center text-muted small">Type at least 2 characters…</div>';
    exit;
}

$like = '%' . $q . '%';

// ── Get raw PDO via Reflection (confirmed working) ────────────────────────────
$dbWrapper = db();
$pdo = null;

foreach (['pdo','dbh','conn','connection','db','_pdo','link'] as $prop) {
    if (isset($dbWrapper->$prop) && $dbWrapper->$prop instanceof PDO) {
        $pdo = $dbWrapper->$prop;
        break;
    }
}

if (!$pdo) {
    try {
        $ref = new ReflectionObject($dbWrapper);
        foreach ($ref->getProperties() as $rp) {
            $rp->setAccessible(true);
            $val = $rp->getValue($dbWrapper);
            if ($val instanceof PDO) { $pdo = $val; break; }
        }
    } catch (Exception $e) {}
}

if (!$pdo) {
    $host = defined('DB_HOST') ? DB_HOST : (defined('DATABASE_HOST') ? DATABASE_HOST : 'localhost');
    $name = defined('DB_NAME') ? DB_NAME : (defined('DATABASE_NAME') ? DATABASE_NAME : '');
    $user = defined('DB_USER') ? DB_USER : (defined('DB_USERNAME')   ? DB_USERNAME   : 'root');
    $pass = defined('DB_PASS') ? DB_PASS : (defined('DB_PASSWORD')   ? DB_PASSWORD   : '');
    try {
        $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        echo '<div class="p-3 text-danger small">DB Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        exit;
    }
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ── is_variant filter — FIXED LOGIC ──────────────────────────────────────────
// OLD (BROKEN): excluded products where is_variant=1 AND parent_id IS NULL
//   → this blocked standalone/top-level products that happen to have is_variant=1
//
// NEW (FIXED):  only exclude products where is_variant=1 AND parent_id IS NOT NULL
//   → only hides actual child variants (those that belong to a parent product)
//   → standalone products always show, even if is_variant=1
$variantCond = '';
try {
    $ck = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME   = 'products'
                          AND COLUMN_NAME  = 'is_variant'");
    if ((int)$ck->fetchColumn() > 0) {
        // Only hide child variants (those WITH a real parent), never hide top-level products
        $variantCond = "AND NOT (COALESCE(p.is_variant,0) = 1 AND p.parent_id IS NOT NULL AND p.parent_id > 0)";
    }
} catch (Exception $e) {}

// ── Search condition ──────────────────────────────────────────────────────────
$params = [];
if ($searchType === 'name') {
    $searchCond = "p.product_name LIKE ?";
    $params[]   = $like;
} elseif ($searchType === 'brand') {
    $searchCond = "p.brand_name LIKE ?";
    $params[]   = $like;
} elseif ($searchType === 'generic') {
    $searchCond = "p.generic_name LIKE ?";
    $params[]   = $like;
} else {
    $searchCond = "(p.product_name LIKE ? OR p.brand_name LIKE ? OR p.generic_name LIKE ? OR p.product_code LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

// ── Expired extra WHERE ───────────────────────────────────────────────────────
$expiredWhere = '';
if ($expiryFilter === 'expired') {
    $expiredWhere = "AND EXISTS (
        SELECT 1 FROM batches bx
        WHERE bx.product_id = p.id
          AND bx.expiry_date < CURDATE()
          AND bx.remaining_quantity > 0
    )";
}

// ── HAVING ────────────────────────────────────────────────────────────────────
$havingParts = [];
if ($expiryFilter === 'near_expiry') {
    $havingParts[] = "nearest_expiry IS NOT NULL AND DATEDIFF(nearest_expiry, CURDATE()) BETWEEN 0 AND 30";
}
if ($stockFilter === 'in_stock')         $havingParts[] = "total_stock > 0";
elseif ($stockFilter === 'low_stock')    $havingParts[] = "total_stock > 0 AND total_stock <= reorder_level";
elseif ($stockFilter === 'out_of_stock') $havingParts[] = "total_stock <= 0";
$havingSQL = $havingParts ? "HAVING " . implode(' AND ', $havingParts) : '';

// ── Main SQL ──────────────────────────────────────────────────────────────────
$sql = "
    SELECT
        p.id,
        p.product_name,
        p.product_code,
        p.category_id,
        p.brand_name,
        p.generic_name,
        p.strength,
        p.dosage_form,
        p.size_value,
        p.unit_type,
        p.units_per_pack,
        p.reorder_level,
        p.image_path,
        p.sale_price AS base_sale_price,
        COALESCE((
            SELECT selling_price FROM batches
            WHERE product_id = p.id
              AND remaining_quantity > 0
              AND expiry_date >= CURDATE()
            ORDER BY expiry_date ASC LIMIT 1
        ), p.sale_price) AS sale_price,
        COALESCE(SUM(
            CASE WHEN b.expiry_date >= CURDATE() AND b.is_expired = 0
                 THEN b.remaining_quantity ELSE 0 END
        ), 0) AS total_stock,
        MIN(CASE WHEN b.expiry_date >= CURDATE() AND b.is_expired = 0
                 THEN b.expiry_date END) AS nearest_expiry
    FROM products p
    LEFT JOIN batches b ON p.id = b.product_id
    WHERE p.status = 1
      {$variantCond}
      AND {$searchCond}
      {$expiredWhere}
    GROUP BY p.id
    {$havingSQL}
    ORDER BY p.product_name ASC
    LIMIT 15
";

// ── Execute ───────────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    echo '<div class="p-3 text-danger small"><i class="fas fa-exclamation-triangle me-1"></i> Query error: '
        . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

// ── No results ────────────────────────────────────────────────────────────────
if (empty($products)) {
    echo '<div class="p-3 text-center text-muted small">
            <i class="fas fa-search me-1"></i>
            No products found matching "<strong>' . htmlspecialchars($q) . '</strong>".
          </div>';
    exit;
}

// ── Image helper ──────────────────────────────────────────────────────────────
function getLiveSearchImageHtml(string $imagePath, string $productName): string {
    if (empty($imagePath)) return '<div class="live-search-img-placeholder"><i class="fas fa-capsules"></i></div>';
    $clean  = ltrim(preg_replace('#^(\./|\.\./)#', '', str_replace('\\', '/', preg_replace('#^[A-Za-z]:/#', '', $imagePath))), '/');
    $base   = defined('BASE_URL') ? BASE_URL : 'http://localhost/pharmacy_software/';
    $imgUrl = $base . $clean;
    $local  = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] . '/pharmacy_software/' . $clean);
    if (!file_exists($local)) return '<div class="live-search-img-placeholder"><i class="fas fa-image"></i></div>';
    return '<img src="' . htmlspecialchars($imgUrl) . '" class="live-search-img" alt="' . htmlspecialchars($productName) . '">';
}

// ── Render results ────────────────────────────────────────────────────────────
foreach ($products as $p) {
    $catId     = (int)$p['category_id'];
    $stock     = (int)$p['total_stock'];
    $unitType  = $p['unit_type']    ?? 'Pcs';
    $salePrice = (float)$p['sale_price'];
    $reorder   = (int)($p['reorder_level'] ?? 10);

    if ($stock <= 0)            $stockHtml = '<span class="badge bg-danger">Out of Stock</span>';
    elseif ($stock <= $reorder) $stockHtml = '<span class="badge bg-warning text-dark">Low Stock (' . $stock . ' ' . htmlspecialchars($unitType) . ')</span>';
    else                        $stockHtml = '<span class="badge bg-success">In Stock (' . $stock . ' ' . htmlspecialchars($unitType) . ')</span>';

    $catLabel = $catId === 1
        ? '<span class="badge bg-info">Medicine</span>'
        : '<span class="badge bg-secondary">Non-Medicine</span>';

    $pills = [];
    if ($catId === 1) {
        if (!empty($p['brand_name']))   $pills[] = '<span class="live-search-pill"><i class="fas fa-tag"></i> '                 . htmlspecialchars($p['brand_name'])   . '</span>';
        if (!empty($p['generic_name'])) $pills[] = '<span class="live-search-pill"><i class="fas fa-prescription-bottle"></i> ' . htmlspecialchars($p['generic_name']) . '</span>';
        if (!empty($p['strength']))     $pills[] = '<span class="live-search-pill"><i class="fas fa-weight-hanging"></i> '      . htmlspecialchars($p['strength'])     . '</span>';
        if (!empty($p['dosage_form']))  $pills[] = '<span class="live-search-pill"><i class="fas fa-tablets"></i> '             . htmlspecialchars($p['dosage_form'])  . '</span>';
    } else {
        if (!empty($p['brand_name']))   $pills[] = '<span class="live-search-pill"><i class="fas fa-tag"></i> '                 . htmlspecialchars($p['brand_name'])   . '</span>';
        if (!empty($p['size_value']))   $pills[] = '<span class="live-search-pill"><i class="fas fa-arrows-alt"></i> '          . htmlspecialchars($p['size_value'])   . '</span>';
    }
    if (!empty($p['units_per_pack']) && (int)$p['units_per_pack'] > 1) {
        $pills[] = '<span class="live-search-pill"><i class="fas fa-box"></i> ' . (int)$p['units_per_pack'] . '/' . htmlspecialchars($unitType) . ' per pack</span>';
    }

    $imgHtml   = getLiveSearchImageHtml($p['image_path'] ?? '', $p['product_name']);
    $priceHtml = '<span class="live-search-price">Rs ' . number_format($salePrice, 2) . '</span>';

    echo '<div class="live-search-result-item" data-id="' . (int)$p['id'] . '">';
    echo  '<div class="d-flex">';
    echo    '<div class="flex-shrink-0 me-3">' . $imgHtml . '</div>';
    echo    '<div class="flex-grow-1">';
    echo      '<div class="d-flex justify-content-between align-items-start flex-wrap">';
    echo        '<div>';
    echo          '<span class="fw-bold">' . htmlspecialchars($p['product_name']) . '</span> ' . $catLabel;
    echo          '<br><small class="text-muted">' . htmlspecialchars($p['product_code'] ?? '') . '</small>';
    echo        '</div>';
    echo        '<div>' . $stockHtml . '</div>';
    echo      '</div>';
    if (!empty($pills)) echo '<div class="mt-1 mb-1 d-flex flex-wrap gap-1">' . implode('', $pills) . '</div>';
    echo      '<div class="mt-1">' . $priceHtml . '</div>';
    echo    '</div>';
    echo  '</div>';
    echo '</div>';
}