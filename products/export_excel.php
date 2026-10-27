<?php
/**
 * products/export_excel.php
 * Export products to Excel (CSV with BOM — opens natively in Excel)
 */

require_once '../config/config.php';
require_once '../includes/product_functions.php';

if (!defined('BASE_URL')) define('BASE_URL', 'http://localhost/pharmacy_software/');
requirePharmacist();

// ── CSRF ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    die('Security token mismatch. Please refresh and try again.');
}

// ── Params ───────────────────────────────────────────────────────────────────
function _esc(?string $v): string { return htmlspecialchars_decode((string)$v, ENT_QUOTES); }

$search      = trim($_POST['search']       ?? '');
$category    = trim($_POST['category']     ?? '');
$searchType  = trim($_POST['search_type']  ?? 'all');
$stockFilter = trim($_POST['stock_filter'] ?? 'in_stock');
$expFilter   = trim($_POST['expiry_filter']?? 'all');
$sortBy      = trim($_POST['sort_by']      ?? 'expiry');
$sortDir     = trim($_POST['sort_dir']     ?? 'asc');

$rawIds      = $_POST['selected_ids'] ?? [];
$selectedIds = is_array($rawIds) ? array_unique(array_filter(array_map('intval', $rawIds))) : [];

// ── Fetch data ───────────────────────────────────────────────────────────────
$products = getExportProducts(
    $search, $category, $searchType, $stockFilter, $expFilter, $sortBy, $sortDir, $selectedIds
);

if (empty($products)) {
    // Redirect back with a flash message instead of blank page
    $_SESSION['flash_error'] = 'No products found to export with the current filters.';
    header('Location: list.php');
    exit;
}

// ── Build filename ───────────────────────────────────────────────────────────
$filterLabel = match($stockFilter) {
    'in_stock'    => 'InStock',
    'out_of_stock'=> 'OutOfStock',
    'low_stock'   => 'LowStock',
    'expired'     => 'Expired',
    'near_expiry' => 'NearExpiry',
    default       => 'All',
};
$filename = 'Products_' . $filterLabel . '_' . date('Y-m-d_His') . '.csv';

// ── Headers ──────────────────────────────────────────────────────────────────
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens correctly
fwrite($out, "\xEF\xBB\xBF");

// ── Column headers ───────────────────────────────────────────────────────────
$headers = [
    'Sr#',
    'Product Code',
    'Product Name',
    'Category',
    'Brand',
    'Generic Name',
    'Dosage Form',
    'Strength / Size',
    'Unit Type',
    'Units/Pack',
    'Batch #',
    'Stock (Pieces)',
    'Sale Price/Unit (Rs)',
    'Sale Price/Pack (Rs)',
    'Purchase Price (Rs)',
    'Expiry Date',
    'Purchase Date',
    'Supplier',
    'Active Batches',
    'Expired Batches',
    'Total Batches',
    'Reorder Level',
    'Status',
];
fputcsv($out, $headers);

// ── Rows ─────────────────────────────────────────────────────────────────────
$sr = 1;
foreach ($products as $p) {
    $unitsPerPack = max(1, (int)($p['units_per_pack'] ?? 1));
    $salePerUnit  = (float)($p['sale_price_unit'] ?? $p['default_sale_price'] ?? 0);
    $salePerPack  = (float)($p['sale_price_pack'] ?? ($salePerUnit * $unitsPerPack));
    $purchasePrice= (float)($p['purchase_price']  ?? $p['default_purchase_price'] ?? 0);
    $stockPieces  = (int)($p['stock_pieces'] ?? 0);

    // Expiry
    $expiryRaw = $p['expiry_date'] ?? null;
    $expiryStr = $expiryRaw ? date('d-M-Y', strtotime($expiryRaw)) : 'N/A';

    // Purchase date
    $purchaseDateStr = !empty($p['purchase_date']) ? date('d-M-Y', strtotime($p['purchase_date'])) : 'N/A';

    // Category label
    $catLabel = match((int)($p['category_id'] ?? 1)) {
        1 => 'Medicine',
        2 => 'Non-Medicine',
        default => $p['category'] ?? 'Unknown',
    };

    // Stock status
    $status = 'In Stock';
    if ($stockPieces <= 0) {
        $status = 'Out of Stock';
    } elseif ($expiryRaw && strtotime($expiryRaw) < time()) {
        $status = 'Expired';
    } elseif ($expiryRaw && strtotime($expiryRaw) <= strtotime('+30 days')) {
        $status = 'Near Expiry';
    } elseif ($stockPieces <= (int)($p['reorder_level'] ?? 10)) {
        $status = 'Low Stock';
    }

    // Strength/Size
    $strengthSize = '';
    if ((int)($p['category_id'] ?? 1) === 1) {
        $strengthSize = trim(($p['strength'] ?? ''));
    } else {
        $strengthSize = trim(($p['size_value'] ?? ''));
    }

    $row = [
        $sr++,
        $p['product_code']   ?? '',
        $p['product_name']   ?? '',
        $catLabel,
        $p['brand_name']     ?? '',
        $p['generic_name']   ?? '',
        $p['dosage_form']    ?? '',
        $strengthSize,
        $p['unit_type']      ?? '',
        $unitsPerPack,
        $p['batch_number']   ?? 'N/A',
        $stockPieces,
        number_format($salePerUnit,  2, '.', ''),
        number_format($salePerPack,  2, '.', ''),
        number_format($purchasePrice,2, '.', ''),
        $expiryStr,
        $purchaseDateStr,
        $p['supplier_name']  ?? 'N/A',
        (int)($p['active_batch_count']  ?? $p['active_batches']  ?? 0),
        (int)($p['expired_batch_count'] ?? $p['expired_batches'] ?? 0),
        (int)($p['total_batch_count']   ?? 0),
        (int)($p['reorder_level']       ?? 10),
        $status,
    ];

    fputcsv($out, $row);
}

fclose($out);
exit;