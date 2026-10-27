<?php
/**
 * products/list.php — Batch-Level Products List
 * Updated (Step 7 UI/UX Sync):
 * - Design system CSS variables applied throughout (no hardcoded colors)
 * - .table-card wrapper with hover effects & row separators
 * - .filter-pill redesign with count badges
 * - .filter-bar search container
 * - .status-badge classes for stock/expiry status
 * - Action buttons grouped with tooltips
 * - Pagination styled consistently
 * - Smooth transitions on all interactive elements
 * - Page fade-in animation (.main-content)
 * - Breadcrumb: Dashboard > Products > Product List
 * - All existing PHP logic, AJAX, modals, JS preserved as-is
 */

require_once '../config/config.php';
require_once '../includes/product_functions.php';

if (!defined('BASE_URL')) define('BASE_URL', 'http://localhost/pharmacy_software/');
if (!function_exists('sanitize')) {
    function sanitize($v) { return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8'); }
}
requirePharmacist();

// ── AJAX: table_refresh — returns FULL tableWrapper HTML + counts as JSON ────────
// BUG FIX: Previously returned only bare <tr> rows. ajaxTableRefresh() replaces
// the entire #tableWrapper innerHTML, so we must return the complete structure:
// table-responsive > table > thead + tbody, plus the pagination block.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'table_refresh') {
    $page        = max(1, (int)($_GET['page']         ?? 1));
    $search      = sanitize($_GET['search']            ?? '');
    $category    = sanitize($_GET['category']          ?? '');
    $searchType  = sanitize($_GET['search_type']       ?? 'all');
    $stockFilter = sanitize($_GET['stock_filter']      ?? 'in_stock');
    $expFilter   = sanitize($_GET['expiry_filter']     ?? 'all');
    $sortBy      = sanitize($_GET['sort_by']           ?? 'expiry');
    $sortDir     = sanitize($_GET['sort_dir']          ?? 'asc');
    $perPageRaw  = sanitize($_GET['per_page']          ?? '20');
    $perPage     = in_array($perPageRaw, ['20','50','100','all'], true) ? $perPageRaw : '20';
    $perPageInt  = ($perPage === 'all') ? 99999 : (int)$perPage;

    $allowed_sorts = ['expiry','name','brand','strength','stock','price','units','newest'];
    if (!in_array($sortBy, $allowed_sorts, true)) $sortBy = 'expiry';
    if (!in_array($sortDir, ['asc','desc'], true)) $sortDir = 'asc';

    $result     = getFilteredProducts($search, $category, $page, $perPageInt, $searchType, $stockFilter, $expFilter, $sortBy, $sortDir);
    $batches    = $result['data']       ?? [];
    $totalRows  = $result['total']      ?? 0;
    $totalPages = $result['totalPages'] ?? 1;
    $counts     = getProductPillCounts($search, $category, $searchType);

    // Build sortIcon helper inline for AJAX context
    $sortIconFn = function(string $col) use ($sortBy, $sortDir): string {
        if ($sortBy !== $col) return ' <i class="fas fa-sort" style="opacity:.3;font-size:10px"></i>';
        return $sortDir === 'asc'
            ? ' <i class="fas fa-sort-up" style="color:var(--pharmacy-primary);font-size:10px"></i>'
            : ' <i class="fas fa-sort-down" style="color:var(--pharmacy-primary);font-size:10px"></i>';
    };

    ob_start();
    // ── Full tableWrapper inner HTML ──────────────────────────────────────
    echo '<div class="table-responsive">';
    echo '<table class="table tbl tbl-hover align-middle mb-0" id="productsTable">';
    echo '<thead><tr>';
    echo '<th width="35"><input type="checkbox" class="form-check-input" id="checkAll"></th>';
    echo '<th width="38">#</th>';
    echo '<th width="50">Image</th>';
    echo '<th><a href="#" class="sort-link" onclick="ajaxSort(\'name\');return false;">Product Name'.$sortIconFn('name').'</a></th>';
    echo '<th><a href="#" class="sort-link" onclick="ajaxSort(\'strength\');return false;">Form / Strength / Size'.$sortIconFn('strength').'</a></th>';
    echo '<th><a href="#" class="sort-link" onclick="ajaxSort(\'units\');return false;">Units/Pack'.$sortIconFn('units').'</a></th>';
    echo '<th><a href="#" class="sort-link" onclick="ajaxSort(\'stock\');return false;">Stock'.$sortIconFn('stock').'</a></th>';
    echo '<th><a href="#" class="sort-link" onclick="ajaxSort(\'price\');return false;">Sale Price/Unit'.$sortIconFn('price').'</a></th>';
    echo '<th>Sale Price/Pack</th>';
    echo '<th><a href="#" class="sort-link" onclick="ajaxSort(\'expiry\');return false;">Expiry'.$sortIconFn('expiry').'</a></th>';
    echo '<th>Batches</th>';
    echo '<th width="140">Actions</th>';
    echo '</tr></thead>';
    echo '<tbody id="tableBody">';

    if (!empty($batches)):
        $srNo = (($page - 1) * $perPageInt) + 1;
        $batchSummaryCache = [];
        foreach ($batches as $b):
            $batchId      = (int)$b['batch_id'];
            $productId    = (int)$b['product_id'];
            $catId        = (int)$b['category_id'];
            $currentStock = (int)$b['current_stock'];
            $isExpired    = !empty($b['is_expired']);
            $expiryDate   = $b['expiry_date'] ?? null;
            $unitsPerPack = max(1, (int)$b['units_per_pack']);
            $unitType     = $b['unit_type'] ?? 'Pcs';
            $dosageForm   = $b['dosage_form'] ?? '';
            $reorderLevel = (int)($b['reorder_level'] ?? 10);
            $hasActive    = (bool)($b['has_active_batch'] ?? false);
            $salePrice    = (float)($b['sale_price'] ?? $b['selling_price'] ?? 0);
            $salePerPack  = $salePrice * $unitsPerPack;

            // Lock = product has at least one active (non-expired, qty>0) batch.
            // Delete only appears when ALL batches are expired/sold-out (no active stock anywhere).
            $showLock = $hasActive;

            // FIX: Use filter-aware helpers for correct stock number and badge
            $stockForDisplay = getStockForDisplay($b, $stockFilter);
            [$badgeClass, $badgeLabel, $badgeIcon] = getStatusBadgeForFilter($b, $stockFilter);

            $isExpired  = $expiryDate && (new DateTime('today') > new DateTime($expiryDate));
            $isOOS      = ((int)($b['total_active_stock'] ?? 0) <= 0 && $stockFilter !== 'expired');
            $rowClass   = $isExpired ? 'row-expired' : ($isOOS ? 'row-oos' : '');

            // Form/Strength/Size cell
            if ($catId === 1) {
                $df = trim($dosageForm);
                $st = trim($b['strength'] ?? '');
                if ($df && $st)   $formStr = '<span class="fw-semibold">'.esc($df).'</span><br><small style="color:color-mix(in srgb,var(--pharmacy-text) 60%,transparent)">'.esc($st).'</small>';
                elseif ($df)      $formStr = esc($df);
                elseif ($st)      $formStr = esc($st);
                else              $formStr = '<span style="color:color-mix(in srgb,var(--pharmacy-text) 40%,transparent)">—</span>';
            } else {
                $sv = trim($b['size_value'] ?? '');
                $formStr = $sv !== '' ? esc($sv) : '<span style="color:color-mix(in srgb,var(--pharmacy-text) 40%,transparent)">—</span>';
            }

            $unitsLabel = formatUnitsPerPackDisplay($unitsPerPack, $unitType, $dosageForm);
            $stockHtml  = formatStockDisplay($stockForDisplay, $unitsPerPack, $unitType, $dosageForm);
            $expiryHtml = getExpiryDisplay($expiryDate);
            $imgHtml    = getProductImageHtml($b['image_path'] ?? '', $b['product_name'], $productId);

            if (!isset($batchSummaryCache[$productId])) {
                $batchSummaryCache[$productId] = getBatchSummary($productId);
            }
            $bs = $batchSummaryCache[$productId];

            $pname = htmlspecialchars($b['product_name']);
            $pnameJs = addslashes(esc($b['product_name']));

            echo '<tr class="'.htmlspecialchars($rowClass).'" data-batch-id="'.$batchId.'" data-product-id="'.$productId.'" data-has-active="'.($hasActive?'1':'0').'">';
            echo '<td><input type="checkbox" class="form-check-input row-check" value="'.$productId.'"></td>';
            echo '<td style="color:color-mix(in srgb,var(--pharmacy-text) 55%,transparent)">'.$srNo++.'</td>';
            echo '<td>'.$imgHtml.'</td>';
            echo '<td>';
            echo '<div class="pname-main">'.$pname.'</div>';
            if ($catId === 1 && !empty($b['generic_name'])) echo '<div class="pname-generic">('.htmlspecialchars($b['generic_name']).')</div>';
            echo '<div class="pname-brand">'.htmlspecialchars($b['brand_name'] ?? '').'</div>';
            echo '<div class="pname-code"><span class="badge bg-light text-dark border">'.htmlspecialchars($b['product_code']).'</span></div>';
            echo '</td>';
            echo '<td>'.$formStr.'</td>';
            echo '<td class="text-nowrap">'.esc($unitsLabel).'</td>';
            echo '<td>'.$stockHtml.'<div class="mt-1"><span class="status-badge '.$badgeClass.'"><i class="fas '.$badgeIcon.' me-1" style="font-size:10px"></i>'.$badgeLabel.'</span></div></td>';
            echo '<td class="price-col" id="price-display-'.$batchId.'">Rs '.number_format($salePrice, 2);
            if ($currentStock > 0 && !$isExpired) {
                echo ' <button class="btn btn-link btn-sm p-0 ms-1" title="Edit batch sale price" onclick="openPriceModal('.$batchId.', '.$productId.', \'\''.$pnameJs.'\'\'\', '.$salePrice.')"><i class="fas fa-pen" style="color:var(--pharmacy-primary);font-size:11px"></i></button>';
            }
            echo '</td>';
            echo '<td class="fw-semibold" style="color:var(--pharmacy-primary)" id="pack-price-'.$batchId.'">Rs '.number_format($salePerPack, 2).'</td>';
            echo '<td>'.$expiryHtml.'</td>';
            echo '<td><div class="batch-sum">';
            echo '<span style="color:#198754;font-weight:600;">Active='.str_pad($bs['active'],2,'0',STR_PAD_LEFT).'</span><br>';
            echo '<span style="color:#dc3545;">Expired='.str_pad($bs['expired'],2,'0',STR_PAD_LEFT).'</span><br>';
            echo '<span style="color:color-mix(in srgb,var(--pharmacy-text) 55%,transparent)">OOS='.str_pad($bs['out_of_stock'],2,'0',STR_PAD_LEFT).'</span><br>';
            echo '<span style="color:var(--pharmacy-primary)">Total='.str_pad($bs['total'],2,'0',STR_PAD_LEFT).'</span>';
            echo '</div></td>';
            echo '<td><div class="btn-group btn-group-sm">';
            echo '<a href="view.php?id='.$productId.'" class="btn btn-outline-info" title="View Product"><i class="fas fa-eye"></i></a>';
            if ($showLock) {
                if ($currentStock > 0 && !$isExpired) {
                    echo '<button class="btn btn-outline-dark" title="Print Barcode" onclick="printBarcode('.$batchId.')"><i class="fas fa-barcode"></i></button>';
                }
                echo '<button class="btn btn-outline-secondary" title="Cannot delete — active stock exists. Sell or return all stock first." onclick="showStockProtected(\''.$pnameJs.'\')"><i class="fas fa-lock"></i></button>';
            } else {
                if ($hasActive && $currentStock > 0 && !$isExpired) {
                    echo '<button class="btn btn-outline-dark" title="Print Barcode" onclick="printBarcode('.$batchId.')"><i class="fas fa-barcode"></i></button>';
                }
                echo '<a href="edit.php?id='.$productId.'" class="btn btn-outline-primary" title="Edit Product"><i class="fas fa-edit"></i></a>';
                echo '<button class="btn btn-outline-danger" title="Move to Trash" onclick="confirmDelete('.$productId.', \''.$pnameJs.'\')"><i class="fas fa-trash"></i></button>';
            }
            echo '</div></td>';
            echo '</tr>';
        endforeach;
    else:
        echo '<tr><td colspan="12" class="text-center py-5">';
        echo '<i class="fas fa-box-open fa-3x d-block mb-3" style="color:color-mix(in srgb,var(--pharmacy-text) 35%,transparent)"></i>';
        echo '<span style="color:color-mix(in srgb,var(--pharmacy-text) 55%,transparent)">No products found for the selected filter.</span><br>';
        echo '<a href="list.php" class="btn btn-sm btn-outline-secondary mt-2"><i class="fas fa-sync-alt me-1"></i>Reset Filters</a>';
        echo '</td></tr>';
    endif;

    echo '</tbody></table></div>';

    // Pagination
    if ($totalPages > 1):
        echo '<div class="d-flex justify-content-between align-items-center px-3 py-2" style="border-top:1px solid var(--pharmacy-border)">';
        echo '<small style="color:color-mix(in srgb,var(--pharmacy-text) 60%,transparent)">Page '.$page.' of '.$totalPages.'</small>';
        echo '<nav><ul class="pagination pagination-sm mb-0">';
        if ($page > 1) echo '<li class="page-item"><a class="page-link" href="#" onclick="ajaxGoPage('.($page-1).');return false;">&laquo;</a></li>';
        $s = max(1,$page-3); $e = min($totalPages,$page+3);
        for ($i=$s;$i<=$e;$i++) {
            $active = ($i===$page) ? ' active' : '';
            echo '<li class="page-item'.$active.'"><a class="page-link" href="#" onclick="ajaxGoPage('.$i.');return false;">'.$i.'</a></li>';
        }
        if ($page < $totalPages) echo '<li class="page-item"><a class="page-link" href="#" onclick="ajaxGoPage('.($page+1).');return false;">&raquo;</a></li>';
        echo '</ul></nav></div>';
    endif;

    $tableHtml = ob_get_clean();

    // BUG FIX: "All Products" pill uses data-pill="all" but counts array had key "total".
    // Add 'all' alias so the AJAX pill-count update loop finds it correctly.
    $counts['all'] = $counts['total'];

    header('Content-Type: application/json');
    echo json_encode([
        'success'    => true,
        'html'       => $tableHtml,
        'counts'     => $counts,
        'total'      => $totalRows,
        'totalPages' => $totalPages,
        'page'       => $page,
    ]);
    exit;
}

$page_title  = 'Products List — Pharmacy Management System';
$page        = max(1, (int)($_GET['page']         ?? 1));
$search      = sanitize($_GET['search']            ?? '');
$category    = sanitize($_GET['category']          ?? '');
$searchType  = sanitize($_GET['search_type']       ?? 'all');
$stockFilter = sanitize($_GET['stock_filter']      ?? 'in_stock');
$expFilter   = sanitize($_GET['expiry_filter']     ?? 'all');
$sortBy      = sanitize($_GET['sort_by']           ?? 'expiry');
$sortDir     = sanitize($_GET['sort_dir']          ?? 'asc');
$perPageRaw  = sanitize($_GET['per_page']          ?? '20');
$perPage     = in_array($perPageRaw, ['20','50','100','all'], true) ? $perPageRaw : '20';
$perPageInt  = ($perPage === 'all') ? 99999 : (int)$perPage;

$allowed_sorts = ['expiry','name','brand','strength','stock','price','units','newest'];
if (!in_array($sortBy, $allowed_sorts, true)) $sortBy = 'expiry';
if (!in_array($sortDir, ['asc','desc'],   true)) $sortDir = 'asc';

$result      = getFilteredProducts($search, $category, $page, $perPageInt, $searchType, $stockFilter, $expFilter, $sortBy, $sortDir);
$batches     = $result['data']       ?? [];
$totalRows   = $result['total']      ?? 0;
$totalPages  = $result['totalPages'] ?? 1;

// Real-time pill counts — single query (replaces old 6-call _pillCount pattern)
$counts = getProductPillCounts($search, $category, $searchType);

// Trash count
$trashCount = 0;
try {
    $trashRow   = _pdoOne("SELECT COUNT(*) AS cnt FROM products WHERE status = 0");
    $trashCount = (int)($trashRow['cnt'] ?? 0);
} catch (\Throwable $e) {}

$csrf = generateCSRFToken();

// ── URL helpers ────────────────────────────────────────────────────────────
function filterUrl(array $override = []): string {
    global $search, $category, $searchType, $stockFilter, $expFilter, $page, $sortBy, $sortDir, $perPage;
    return '?' . http_build_query(array_merge([
        'search'       => $search,
        'category'     => $category,
        'search_type'  => $searchType,
        'stock_filter' => $stockFilter,
        'expiry_filter'=> $expFilter,
        'sort_by'      => $sortBy,
        'sort_dir'     => $sortDir,
        'per_page'     => $perPage,
        'page'         => $page,
    ], $override));
}

function sortUrl(string $col): string {
    global $search,$category,$searchType,$stockFilter,$expFilter,$sortBy,$sortDir,$perPage;
    $newDir = ($sortBy === $col && $sortDir === 'asc') ? 'desc' : 'asc';
    return '?' . http_build_query(['search'=>$search,'category'=>$category,'search_type'=>$searchType,
        'stock_filter'=>$stockFilter,'expiry_filter'=>$expFilter,'sort_by'=>$col,'sort_dir'=>$newDir,'per_page'=>$perPage,'page'=>1]);
}

function sortIcon(string $col): string {
    global $sortBy, $sortDir;
    if ($sortBy !== $col) return ' <i class="fas fa-sort text-muted" style="opacity:.4;font-size:10px"></i>';
    return $sortDir === 'asc'
        ? ' <i class="fas fa-sort-up" style="color:var(--pharmacy-primary)"></i>'
        : ' <i class="fas fa-sort-down" style="color:var(--pharmacy-primary)"></i>';
}

function getProductImageHtml(?string $imagePath, string $productName, int $productId): string {
    if (empty($imagePath)) {
        return '<span class="prod-thumb-ph" onclick="openImageModal('.$productId.',\''.addslashes(htmlspecialchars($productName)).'\',\'\')"><i class="fas fa-image"></i></span>';
    }
    $url = BASE_URL . ltrim(str_replace('\\','/',$imagePath),'/');
    return '<img src="'.htmlspecialchars($url).'" class="prod-thumb" alt="'.htmlspecialchars($productName).'" onclick="openImageModal('.$productId.',\''.addslashes(htmlspecialchars($productName)).'\',\''.addslashes(htmlspecialchars($url)).'\')">';
}

function esc(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>


<style>
/* ══════════════════════════════════════════════════════════════════
   products/list.php — Step 7 Design System
   All colors via CSS variables only — no hardcoded values
   ══════════════════════════════════════════════════════════════════ */

/* ── Page fade-in ── */
@keyframes fadeInPage {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: translateY(0); }
}
.main-content {
  margin-left: var(--sb-w, 260px); transition: margin-left .25s ease;
  padding: 20px;
  animation: fadeInPage 0.28s ease;
}
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
@media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

/* ── Base ── */
body { font-size: 14px; background: var(--pharmacy-body-bg, #f0f2f5); }

/* ── Page Header ── */
.page-header-title {
  color: var(--pharmacy-heading) !important;
  font-weight: 700;
}
.page-header-sub {
  color: color-mix(in srgb, var(--pharmacy-text) 65%, transparent);
  font-size: 0.82rem;
}

/* ── Header Action Buttons ── */
.btn-add-product {
  background: linear-gradient(135deg, var(--pharmacy-primary, #667eea), var(--pharmacy-accent, #764ba2));
  color: #fff; border: none; border-radius: 10px; padding: 9px 20px;
  transition: all 0.2s ease;
}
.btn-add-product:hover {
  filter: brightness(1.1); color: #fff;
  transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.18);
}
.btn-bulk-import {
  background: linear-gradient(135deg, #11998e, #38ef7d);
  color: #fff; border: none; border-radius: 10px; padding: 9px 20px;
  transition: all 0.2s ease;
}
.btn-bulk-import:hover {
  filter: brightness(1.08); color: #fff;
  transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.15);
}
.btn-trash {
  background: linear-gradient(135deg, #636e72, #2d3436);
  color: #fff; border: none; border-radius: 10px; padding: 9px 20px;
  position: relative; transition: all 0.2s ease;
}
.btn-trash:hover {
  background: linear-gradient(135deg, #2d3436, #000); color: #fff;
  transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.2);
}
.btn-trash .trash-badge {
  position: absolute; top: -6px; right: -6px;
  background: #e74c3c; color: #fff; border-radius: 50%;
  width: 20px; height: 20px; font-size: 11px;
  display: flex; align-items: center; justify-content: center; font-weight: 700;
}
.btn-export-excel {
  background: linear-gradient(135deg, #1d6f42, #207245);
  color: #fff; border: none; border-radius: 10px; padding: 9px 20px;
  transition: all 0.2s ease;
}
.btn-export-excel:hover { filter: brightness(1.08); color: #fff; transform: translateY(-1px); }

/* ── Filter Pills (Design System) ── */
.filter-pills-wrap {
  display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px;
}
.filter-pill {
  padding: 6px 16px; border-radius: 22px; font-size: 0.8rem; font-weight: 600;
  border: 1.5px solid var(--pharmacy-border, #dee2e6);
  background: var(--pharmacy-card-bg, #fff);
  color: var(--pharmacy-text, #343a40);
  cursor: pointer; transition: all 0.18s ease;
  text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
}
.filter-pill:hover {
  background: var(--pharmacy-primary, #0d6efd);
  color: #fff; border-color: var(--pharmacy-primary, #0d6efd);
  transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,0.12);
}
.filter-pill.active {
  background: var(--pharmacy-primary, #0d6efd);
  color: #fff; border-color: var(--pharmacy-primary, #0d6efd);
  box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.filter-pill.active-danger  { background: #dc3545; border-color: #dc3545; color: #fff; }
.filter-pill.active-dark    { background: #343a40; border-color: #343a40; color: #fff; }
.filter-pill.active-warning { background: #ffc107; border-color: #ffc107; color: #212529; }
.filter-pill.active-secondary{ background: #6c757d; border-color: #6c757d; color: #fff; }
.filter-pill .badge-count {
  background: rgba(255,255,255,0.28);
  border-radius: 12px; padding: 1px 7px; font-size: 0.72rem; font-weight: 700;
  min-width: 20px; text-align: center;
}
.filter-pill:not(.active) .badge-count {
  background: color-mix(in srgb, var(--pharmacy-text) 12%, transparent);
  color: var(--pharmacy-text);
}

/* ── Filter Bar (search + dropdowns container) ── */
.filter-bar {
  background: var(--pharmacy-card-bg, #fff);
  border: 1px solid var(--pharmacy-border, #dee2e6);
  border-radius: 10px; padding: 14px 18px; margin-bottom: 16px;
  box-shadow: 0 1px 6px rgba(0,0,0,0.05);
}

/* ── Table Card Wrapper ── */
.table-card {
  background: var(--pharmacy-card-bg, #fff);
  border: 1px solid var(--pharmacy-border, #dee2e6);
  border-radius: 12px; overflow: hidden;
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  transition: box-shadow 0.2s ease;
}
.table-card-header {
  background: var(--pharmacy-table-th, #f8f9fa) !important;
  border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important;
  padding: 12px 16px;
}

/* ── Table Styling ── */
.tbl thead th {
  background: var(--pharmacy-table-th, #f8f9fa) !important;
  color: var(--pharmacy-heading, #212529) !important;
  font-size: 0.75rem; text-transform: uppercase;
  letter-spacing: 0.4px; font-weight: 700;
  padding: 12px 16px; white-space: nowrap;
  border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important;
  position: sticky; top: 0; z-index: 2;
}
.tbl td {
  padding: 11px 16px; vertical-align: middle;
  color: var(--pharmacy-text, #343a40) !important;
  border-color: var(--pharmacy-border, #dee2e6) !important;
  font-size: 13px;
}
.tbl tbody tr {
  transition: background 0.15s ease;
}
.tbl tbody tr:not(:last-child) td {
  border-bottom: 1px solid var(--pharmacy-border, #dee2e6) !important;
}
.tbl-hover tbody tr:hover td {
  background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 6%, var(--pharmacy-card-bg, #fff)) !important;
}

/* ── Sort links ── */
.sort-link {
  color: var(--pharmacy-heading, #212529) !important;
  text-decoration: none; white-space: nowrap;
  transition: color 0.15s;
}
.sort-link:hover { color: var(--pharmacy-primary, #0d6efd) !important; }

/* ── Row tints ── */
.row-expired td { background: color-mix(in srgb, #ff4d4d 7%, var(--pharmacy-card-bg, #fff)) !important; }
.row-oos td     { background: color-mix(in srgb, #ffd700 8%, var(--pharmacy-card-bg, #fff)) !important; }

/* ── Status Badges (Design System) ── */
.status-badge {
  padding: 3px 10px; border-radius: 20px;
  font-size: 0.72rem; font-weight: 600; display: inline-block;
}
.badge-instock  { background: var(--pharmacy-badge-instock-bg,  #d1fae5); color: var(--pharmacy-badge-instock-text,  #065f46); }
.badge-oos      { background: var(--pharmacy-badge-oos-bg,      #fee2e2); color: var(--pharmacy-badge-oos-text,      #991b1b); }
.badge-expiring { background: var(--pharmacy-badge-expiry-bg,   #fff3cd); color: var(--pharmacy-badge-expiry-text,   #856404); }
.badge-expired  { background: var(--pharmacy-badge-expired-bg,  #fce7f3); color: var(--pharmacy-badge-expired-text,  #9d174d); }
.badge-lowstock { background: var(--pharmacy-badge-lowstock-bg, #fff7e6); color: var(--pharmacy-badge-lowstock-text, #c2410c); }

/* ── Product name cell ── */
.pname-main    { font-weight: 700; font-size: 13.5px; color: var(--pharmacy-heading, #212529); }
.pname-generic { font-style: italic; color: color-mix(in srgb, var(--pharmacy-text) 65%, transparent); font-size: 12px; }
.pname-brand   { color: var(--pharmacy-text, #495057); font-size: 12px; }
.pname-code    { font-size: 11px; }

/* ── Price & stock ── */
.price-col  { font-weight: 700; color: var(--pharmacy-primary, #1e7e34); }
.stock-low  { color: #e6a817; font-weight: 600; font-size: 11px; }
.batch-sum  { font-size: 11px; line-height: 1.6; }

/* ── Thumbnails ── */
.prod-thumb {
  width: 40px; height: 40px; object-fit: cover;
  border-radius: 6px; border: 1px solid var(--pharmacy-border, #dee2e6);
  cursor: pointer; transition: transform 0.18s ease, box-shadow 0.18s ease;
}
.prod-thumb:hover { transform: scale(1.08); box-shadow: 0 3px 10px rgba(0,0,0,0.15); }
.prod-thumb-ph {
  width: 40px; height: 40px; border-radius: 6px;
  background: color-mix(in srgb, var(--pharmacy-border, #dee2e6) 40%, var(--pharmacy-card-bg, #fff));
  border: 1px dashed var(--pharmacy-border, #dee2e6);
  display: inline-flex; align-items: center; justify-content: center;
  color: color-mix(in srgb, var(--pharmacy-text) 40%, transparent); font-size: 16px;
  cursor: pointer; transition: background 0.15s ease;
}
.prod-thumb-ph:hover { background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 10%, var(--pharmacy-card-bg, #fff)); }

/* ── Action buttons ── */
.btn-group .btn { transition: all 0.15s ease; }
.btn-group .btn:hover { transform: translateY(-1px); }

/* ── Bulk bar ── */
#bulkActionsBar {
  background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 6%, var(--pharmacy-card-bg, #fff));
  border-top: 1px solid var(--pharmacy-border, #dee2e6);
}

/* ── Pagination ── */
.pagination .page-link {
  color: var(--pharmacy-primary, #0d6efd);
  border-color: var(--pharmacy-border, #dee2e6);
  transition: all 0.15s ease;
}
.pagination .page-item.active .page-link {
  background: var(--pharmacy-primary, #0d6efd);
  border-color: var(--pharmacy-primary, #0d6efd); color: #fff;
}
.pagination .page-link:hover {
  background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 10%, var(--pharmacy-card-bg, #fff));
}

/* ── Search spinner ── */
#searchSpinner {
  display: none; position: absolute; right: 10px;
  top: 50%; transform: translateY(-50%);
  color: color-mix(in srgb, var(--pharmacy-text) 50%, transparent);
}

/* ── Trash Modal ── */
.trash-modal-header { background: linear-gradient(135deg, #636e72, #2d3436); color: #fff; }
.trash-item-row { transition: background 0.15s ease; }
.trash-item-row:hover { background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 5%, var(--pharmacy-card-bg, #fff)) !important; }
.trash-product-name { font-weight: 700; font-size: 13.5px; color: var(--pharmacy-heading, #212529); }
.trash-product-meta { font-size: 12px; color: color-mix(in srgb, var(--pharmacy-text) 65%, transparent); }
.trash-deleted-at   { font-size: 11px; color: #e74c3c; }
.trash-empty { padding: 3rem; text-align: center; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent); }
.restore-btn { border-radius: 20px; font-size: 12px; padding: 4px 14px; transition: all 0.15s ease; }
.trash-search-bar {
  background: var(--pharmacy-table-th, #f8f9fa);
  border-radius: 10px; padding: 12px 16px; margin-bottom: 12px;
}
.cat-badge-med  { background: #e8f0fe; color: #0d6efd; border-radius: 6px; padding: 2px 8px; font-size: 11px; font-weight: 600; }
.cat-badge-nmed { background: #e6f9f3; color: #20c997; border-radius: 6px; padding: 2px 8px; font-size: 11px; font-weight: 600; }/* modal animation — see css/style.css */

/* ── Category badge in table ── */
.cat-pill {
  display: inline-block; padding: 2px 8px; border-radius: 6px;
  font-size: 11px; font-weight: 600;
}

/* ── Tooltip target ── */
[data-bs-toggle="tooltip"] { cursor: help; }
</style>

</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">
<?php include '../includes/sidebar.php'; ?>
<?php if (file_exists('../includes/navbar.php')) include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">

  <!-- Flash messages -->
  <?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success alert-dismissible fade show mb-3">
    <i class="fas fa-check-circle me-2"></i><?= $_SESSION['flash_success'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php unset($_SESSION['flash_success']); endif; ?>

  <!-- ── Page Header ── -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="mb-1 page-header-title">
        <i class="fas fa-boxes me-2" style="color:var(--pharmacy-primary)"></i>Products Management
      </h4>
      <p class="page-header-sub mb-0">One row per product — batch details shown based on active filter (FIFO for in-stock)</p>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <button type="button" class="btn btn-trash" onclick="openTrashModal()" title="View deleted products">
        <i class="fas fa-trash-alt me-2"></i>Trash Items
        <?php if ($trashCount > 0): ?>
        <span class="trash-badge"><?= $trashCount > 99 ? '99+' : $trashCount ?></span>
        <?php endif; ?>
      </button>
      <a href="bulk_import.php" class="btn btn-bulk-import">
        <i class="fas fa-file-import me-2"></i>Bulk Import
      </a>
      <button type="button" class="btn btn-add-product" onclick="window.location.href='add.php'">
        <i class="fas fa-plus-circle me-2"></i>Add New Product
      </button>
    </div>
  </div>

  <!-- ── Filter Pills (Design System) ── -->
  <div class="filter-pills-wrap">
    <?php
    $pills = [
        ['label'=>'In Stock',     'icon'=>'fa-check-circle',        'sf'=>'in_stock',     'ef'=>'all', 'cnt'=>$counts['in_stock'],     'activeClass'=>'active'],
        ['label'=>'Out of Stock', 'icon'=>'fa-times-circle',        'sf'=>'out_of_stock', 'ef'=>'all', 'cnt'=>$counts['out_of_stock'], 'activeClass'=>'active-danger'],
        ['label'=>'Expired',      'icon'=>'fa-skull-crossbones',    'sf'=>'expired',      'ef'=>'all', 'cnt'=>$counts['expired'],      'activeClass'=>'active-dark'],
        ['label'=>'Near Expiry',  'icon'=>'fa-clock',               'sf'=>'near_expiry',  'ef'=>'all', 'cnt'=>$counts['near_expiry'],  'activeClass'=>'active-warning'],
        ['label'=>'Low Stock',    'icon'=>'fa-exclamation-triangle', 'sf'=>'low_stock',   'ef'=>'all', 'cnt'=>$counts['low_stock'],    'activeClass'=>'active-warning'],
        ['label'=>'All Products', 'icon'=>'fa-layer-group',         'sf'=>'all',          'ef'=>'all', 'cnt'=>$counts['total'],        'activeClass'=>'active-secondary'],
    ];
    foreach ($pills as $p):
        $isActive   = ($stockFilter === $p['sf'] && $expFilter === $p['ef']);
        $activeClass = $isActive ? $p['activeClass'] : '';
        $displayCnt  = $isActive ? $totalRows : (int)$p['cnt'];
    ?>
    <a href="#" class="filter-pill <?= $activeClass ?>"
       data-sf="<?= $p['sf'] ?>" data-ef="<?= $p['ef'] ?>"
       onclick="ajaxFilterPill('<?= $p['sf'] ?>','<?= $p['ef'] ?>');return false;">
      <i class="fas <?= $p['icon'] ?>"></i>
      <?= $p['label'] ?>
      <span class="badge-count" data-pill="<?= $p['sf'] ?>"><?= $displayCnt ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- ── Search & Filter Bar ── -->
  <div class="filter-bar mb-3">
    <form method="GET" id="filterForm" class="row g-2 align-items-end">
      <input type="hidden" name="stock_filter"  value="<?= htmlspecialchars($stockFilter) ?>">
      <input type="hidden" name="expiry_filter" value="<?= htmlspecialchars($expFilter) ?>">
      <input type="hidden" name="sort_by"       value="<?= htmlspecialchars($sortBy) ?>">
      <input type="hidden" name="sort_dir"      value="<?= htmlspecialchars($sortDir) ?>">
      <input type="hidden" name="per_page"      value="<?= htmlspecialchars($perPage) ?>">
      <div class="col-12 mb-1">
        <div class="btn-group btn-group-sm">
          <?php foreach(['all'=>'All Fields','name'=>'Name','brand'=>'Brand','generic'=>'Generic','code'=>'Code'] as $k=>$lbl): ?>
          <input type="radio" class="btn-check" name="search_type" id="st_<?= $k ?>" value="<?= $k ?>" <?= $searchType===$k?'checked':'' ?>>
          <label class="btn btn-outline-secondary" for="st_<?= $k ?>"><?= $lbl ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-md-5">
        <div class="input-group position-relative">
          <span class="input-group-text bg-white border-end-0"><i class="fas fa-search" style="color:color-mix(in srgb,var(--pharmacy-text) 50%,transparent)"></i></span>
          <input type="text" name="search" id="searchInput" class="form-control border-start-0"
                 placeholder="Search products…" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
          <span id="searchSpinner"><i class="fas fa-spinner fa-spin"></i></span>
        </div>
      </div>
      <div class="col-md-2">
        <select name="category" class="form-select">
          <option value="">All Categories</option>
          <option value="1" <?= $category==='1'?'selected':'' ?>>Medicine</option>
          <option value="2" <?= $category==='2'?'selected':'' ?>>Non-Medicine</option>
        </select>
      </div>
      <div class="col-md-auto">
        <button type="button" class="btn btn-primary" onclick="ajaxTableRefresh()">
          <i class="fas fa-filter me-1"></i>Filter
        </button>
        <button type="button" class="btn btn-outline-secondary ms-1" onclick="ajaxResetFilters()">
          <i class="fas fa-sync-alt me-1"></i>Reset
        </button>
      </div>
    </form>
  </div>

  <!-- ── Table Card ── -->
  <div class="table-card" id="products-table-container">

    <!-- Table card header: row count + per-page + bulk bar -->
    <div class="table-card-header">
      <div class="d-flex justify-content-between align-items-center">
        <small id="showingCount" style="color:color-mix(in srgb,var(--pharmacy-text) 65%,transparent)">
          Showing <strong style="color:var(--pharmacy-heading)"><?= count($batches) ?></strong>
          of <strong style="color:var(--pharmacy-heading)"><?= $totalRows ?></strong> products
          (Page <?= $page ?> of <?= $totalPages ?>)
        </small>
        <div class="d-flex align-items-center gap-2">
          <label class="mb-0 small" style="color:color-mix(in srgb,var(--pharmacy-text) 65%,transparent)">Rows per page:</label>
          <select class="form-select form-select-sm" style="width:auto;" onchange="ajaxChangePerPage(this.value)">
            <option value="20"  <?= $perPage==='20'  ?'selected':'' ?>>20</option>
            <option value="50"  <?= $perPage==='50'  ?'selected':'' ?>>50</option>
            <option value="100" <?= $perPage==='100' ?'selected':'' ?>>100</option>
            <option value="all" <?= $perPage==='all' ?'selected':'' ?>>All</option>
          </select>
        </div>
      </div>
      <!-- Bulk actions bar (hidden until selection) -->
      <div id="bulkActionsBar" class="d-none align-items-center gap-2 mt-2 pt-2 border-top flex-wrap">
        <i class="fas fa-check-square" style="color:var(--pharmacy-primary);font-size:13px;"></i>
        <span style="color:color-mix(in srgb,var(--pharmacy-text) 70%,transparent);font-size:13px;" id="selectedCount">0 selected</span>
        <button class="btn btn-sm btn-outline-secondary" onclick="clearSelection()">
          <i class="fas fa-times me-1"></i>Deselect All
        </button>
        <button class="btn btn-sm btn-success ms-1" onclick="exportToExcel()" title="Export selected to Excel">
          <i class="fas fa-file-excel me-1"></i>Export Selected
        </button>
        <button class="btn btn-sm btn-warning ms-1" id="bulkTrashBtn" onclick="bulkMoveToTrash()"
                title="Move selected (eligible) products to Trash" style="display:none;">
          <i class="fas fa-trash-alt me-1"></i>Move to Trash
          <span id="bulkTrashCount" class="badge bg-dark ms-1">0</span>
        </button>
        <small id="bulkTrashSkipNote" class="fst-italic" style="display:none;color:color-mix(in srgb,var(--pharmacy-text) 60%,transparent)"></small>
      </div>
    </div>

    <!-- Table -->
    <div id="tableWrapper">
    <div class="table-responsive">
      <table class="table tbl tbl-hover align-middle mb-0" id="productsTable">
        <thead>
          <tr>
            <th width="35"><input type="checkbox" class="form-check-input" id="checkAll"></th>
            <th width="38">#</th>
            <th width="50">Image</th>
            <th><a href="#" class="sort-link" onclick="ajaxSort('name');return false;">Product Name<?= sortIcon('name') ?></a></th>
            <th><a href="#" class="sort-link" onclick="ajaxSort('strength');return false;">Form / Strength / Size<?= sortIcon('strength') ?></a></th>
            <th><a href="#" class="sort-link" onclick="ajaxSort('units');return false;">Units/Pack<?= sortIcon('units') ?></a></th>
            <th><a href="#" class="sort-link" onclick="ajaxSort('stock');return false;">Stock<?= sortIcon('stock') ?></a></th>
            <th><a href="#" class="sort-link" onclick="ajaxSort('price');return false;">Sale Price/Unit<?= sortIcon('price') ?></a></th>
            <th>Sale Price/Pack</th>
            <th><a href="#" class="sort-link" onclick="ajaxSort('expiry');return false;">Expiry<?= sortIcon('expiry') ?></a></th>
            <th>Batches</th>
            <th width="140">Actions</th>
          </tr>
        </thead>
        <tbody id="tableBody">
        <?php if (!empty($batches)):
            $srNo = (($page - 1) * $perPageInt) + 1;
            foreach ($batches as $b):
                $batchId      = (int)$b['batch_id'];
                $productId    = (int)$b['product_id'];
                $catId        = (int)$b['category_id'];
                $currentStock = (int)$b['current_stock'];
                $expiryDate   = $b['expiry_date'] ?? null;
                $unitsPerPack = max(1, (int)$b['units_per_pack']);
                $unitType     = $b['unit_type'] ?? 'Pcs';
                $dosageForm   = $b['dosage_form'] ?? '';
                $reorderLevel = (int)($b['reorder_level'] ?? 10);
                $hasActive    = (bool)($b['has_active_batch'] ?? false);
                $salePrice    = (float)$b['sale_price'];
                $salePerPack  = $salePrice * $unitsPerPack;

                // Lock = product has at least one active (non-expired, qty>0) batch.
                // Delete only appears when ALL batches are expired/sold-out (no active stock anywhere).
                $showLock = $hasActive;

                // FIX: Use filter-aware helpers for correct stock number and badge
                $stockForDisplay = getStockForDisplay($b, $stockFilter);
                [$badgeClass, $badgeLabel, $badgeIcon] = getStatusBadgeForFilter($b, $stockFilter);

                $isExpired  = $expiryDate && (new DateTime('today') > new DateTime($expiryDate));
                $isOOS      = ((int)($b['total_active_stock'] ?? 0) <= 0 && $stockFilter !== 'expired');
                $rowClass   = $isExpired ? 'row-expired' : ($isOOS ? 'row-oos' : '');

                // Form / Strength / Size cell
                if ($catId === 1) {
                    $df = trim($dosageForm);
                    $st = trim($b['strength'] ?? '');
                    if ($df && $st)   $formStr = '<span class="fw-semibold">'.esc($df).'</span><br><small style="color:color-mix(in srgb,var(--pharmacy-text) 60%,transparent)">'.esc($st).'</small>';
                    elseif ($df)      $formStr = esc($df);
                    elseif ($st)      $formStr = esc($st);
                    else              $formStr = '<span style="color:color-mix(in srgb,var(--pharmacy-text) 40%,transparent)">—</span>';
                } else {
                    $sv      = trim($b['size_value'] ?? '');
                    $formStr = $sv !== '' ? esc($sv) : '<span style="color:color-mix(in srgb,var(--pharmacy-text) 40%,transparent)">—</span>';
                }

                $unitsLabel = formatUnitsPerPackDisplay($unitsPerPack, $unitType, $dosageForm);
                $stockHtml  = formatStockDisplay($stockForDisplay, $unitsPerPack, $unitType, $dosageForm);
                $expiryHtml = getExpiryDisplay($expiryDate);
                $imgHtml    = getProductImageHtml($b['image_path'] ?? '', $b['product_name'], $productId);

                static $batchSummaryCache = [];
                if (!isset($batchSummaryCache[$productId])) {
                    $batchSummaryCache[$productId] = getBatchSummary($productId);
                }
                $bs = $batchSummaryCache[$productId];
        ?>
          <tr class="<?= $rowClass ?>" data-batch-id="<?= $batchId ?>" data-product-id="<?= $productId ?>" data-has-active="<?= $hasActive ? '1' : '0' ?>">
            <td><input type="checkbox" class="form-check-input row-check" value="<?= $productId ?>"></td>
            <td style="color:color-mix(in srgb,var(--pharmacy-text) 55%,transparent)"><?= $srNo++ ?></td>
            <td><?= $imgHtml ?></td>

            <td>
              <div class="pname-main"><?= esc($b['product_name']) ?></div>
              <?php if ($catId === 1 && !empty($b['generic_name'])): ?>
              <div class="pname-generic">(<?= esc($b['generic_name']) ?>)</div>
              <?php endif; ?>
              <div class="pname-brand"><?= esc($b['brand_name'] ?? '') ?></div>
              <div class="pname-code">
                <span class="badge bg-light text-dark border"><?= esc($b['product_code']) ?></span>
              </div>
            </td>

            <td><?= $formStr ?></td>
            <td class="text-nowrap"><?= esc($unitsLabel) ?></td>

            <td>
              <?= $stockHtml ?>
              <div class="mt-1">
                <span class="status-badge <?= $badgeClass ?>">
                  <i class="fas <?= $badgeIcon ?> me-1" style="font-size:10px"></i><?= $badgeLabel ?>
                </span>
              </div>
            </td>

            <td class="price-col" id="price-display-<?= $batchId ?>">
              Rs <?= number_format($salePrice, 2) ?>
              <?php if ($currentStock > 0 && !$isExpired): ?>
              <button class="btn btn-link btn-sm p-0 ms-1"
                      title="Edit batch sale price"
                      data-bs-toggle="tooltip"
                      onclick="openPriceModal(<?= $batchId ?>, <?= $productId ?>, '<?= addslashes(esc($b['product_name'])) ?>', <?= $salePrice ?>)">
                <i class="fas fa-pen" style="color:var(--pharmacy-primary);font-size:11px"></i>
              </button>
              <?php endif; ?>
            </td>

            <td class="fw-semibold" style="color:var(--pharmacy-primary)" id="pack-price-<?= $batchId ?>">
              Rs <?= number_format($salePerPack, 2) ?>
            </td>

            <td><?= $expiryHtml ?></td>

            <td>
              <div class="batch-sum">
                <span style="color:#198754;font-weight:600;">Active=<?= str_pad($bs['active'],2,'0',STR_PAD_LEFT) ?></span><br>
                <span style="color:#dc3545;">Expired=<?= str_pad($bs['expired'],2,'0',STR_PAD_LEFT) ?></span><br>
                <span style="color:color-mix(in srgb,var(--pharmacy-text) 55%,transparent)">OOS=<?= str_pad($bs['out_of_stock'],2,'0',STR_PAD_LEFT) ?></span><br>
                <span style="color:var(--pharmacy-primary)">Total=<?= str_pad($bs['total'],2,'0',STR_PAD_LEFT) ?></span>
              </div>
            </td>

            <td>
              <div class="btn-group btn-group-sm">
                <a href="view.php?id=<?= $productId ?>" class="btn btn-outline-info"
                   title="View Product" data-bs-toggle="tooltip">
                  <i class="fas fa-eye"></i>
                </a>
                <?php if ($showLock): ?>
                  <?php if ($currentStock > 0 && !$isExpired): ?>
                  <button class="btn btn-outline-dark" title="Print Barcode" data-bs-toggle="tooltip"
                          onclick="printBarcode(<?= $batchId ?>)">
                    <i class="fas fa-barcode"></i>
                  </button>
                  <?php endif; ?>
                  <button class="btn btn-outline-secondary"
                          title="Cannot delete — active stock exists. Sell or return all stock first." data-bs-toggle="tooltip"
                          onclick="showStockProtected('<?= addslashes(esc($b['product_name'])) ?>')">
                    <i class="fas fa-lock"></i>
                  </button>
                <?php else: ?>
                  <?php if ($hasActive && $currentStock > 0 && !$isExpired): ?>
                  <button class="btn btn-outline-dark" title="Print Barcode" data-bs-toggle="tooltip"
                          onclick="printBarcode(<?= $batchId ?>)">
                    <i class="fas fa-barcode"></i>
                  </button>
                  <?php endif; ?>
                  <a href="edit.php?id=<?= $productId ?>" class="btn btn-outline-primary" title="Edit Product" data-bs-toggle="tooltip">
                    <i class="fas fa-edit"></i>
                  </a>
                  <button class="btn btn-outline-danger" title="Move to Trash" data-bs-toggle="tooltip"
                          onclick="confirmDelete(<?= $productId ?>, '<?= addslashes(esc($b['product_name'])) ?>')">
                    <i class="fas fa-trash"></i>
                  </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach;
        else: ?>
          <tr>
            <td colspan="12" class="text-center py-5">
              <i class="fas fa-box-open fa-3x d-block mb-3" style="color:color-mix(in srgb,var(--pharmacy-text) 35%,transparent)"></i>
              <span style="color:color-mix(in srgb,var(--pharmacy-text) 55%,transparent)">No products found for the selected filter.</span><br>
              <a href="list.php" class="btn btn-sm btn-outline-secondary mt-2">
                <i class="fas fa-sync-alt me-1"></i>Reset Filters
              </a>
            </td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="d-flex justify-content-between align-items-center px-3 py-2"
         style="border-top:1px solid var(--pharmacy-border)">
      <small style="color:color-mix(in srgb,var(--pharmacy-text) 60%,transparent)">
        Page <?= $page ?> of <?= $totalPages ?>
      </small>
      <nav><ul class="pagination pagination-sm mb-0">
        <?php if ($page > 1): ?>
        <li class="page-item">
          <a class="page-link" href="#" onclick="ajaxGoPage(<?= $page-1 ?>);return false;">&laquo;</a>
        </li>
        <?php endif;
        $s = max(1,$page-3); $e = min($totalPages,$page+3);
        for ($i=$s;$i<=$e;$i++): ?>
        <li class="page-item <?= $i===$page?'active':'' ?>">
          <a class="page-link" href="#" onclick="ajaxGoPage(<?= $i ?>);return false;"><?= $i ?></a>
        </li>
        <?php endfor;
        if ($page < $totalPages): ?>
        <li class="page-item">
          <a class="page-link" href="#" onclick="ajaxGoPage(<?= $page+1 ?>);return false;">&raquo;</a>
        </li>
        <?php endif; ?>
      </ul></nav>
    </div>
    <?php endif; ?>

    </div><!-- /#tableWrapper -->

  </div><!-- /.table-card -->

</div><!-- /container -->
</div><!-- /main-content -->

<!-- ══════════════════════════════ MODALS ══════════════════════════════ -->

<!-- ── ADD / EDIT PRODUCT MODAL ── -->
<div class="modal fade" id="productFormModal" tabindex="-1" aria-labelledby="productFormModalLabel">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-3" id="productFormModalHeader"
           style="background:linear-gradient(135deg,var(--pharmacy-primary),var(--pharmacy-accent,#764ba2));color:#fff;">
        <div>
          <h5 class="modal-title mb-0" id="productFormModalLabel">
            <i class="fas fa-plus-circle me-2"></i><span id="productFormModalTitleText">Add New Product</span>
          </h5>
          <small class="opacity-75" id="productFormModalSubtitle">Fill in product details below</small>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">

        <!-- Loading state -->
        <div id="productFormLoading" class="text-center py-5" style="display:none;">
          <div class="spinner-border" style="color:var(--pharmacy-primary)" role="status"></div>
          <p class="mt-2 small" style="color:color-mix(in srgb,var(--pharmacy-text) 55%,transparent)">Loading product details…</p>
        </div>

        <!-- Error state -->
        <div id="productFormError" class="alert alert-danger m-3" style="display:none;"></div>

        <!-- Non-editable notice (edit mode, has active stock) -->
        <div id="productFormLocked" class="alert alert-warning m-3" style="display:none;">
          <i class="fas fa-lock me-2"></i>
          <strong>Cannot Edit:</strong> This product has active stock batches. Sell or expire all stock first to make changes.
        </div>

        <!-- Form -->
        <form id="productAjaxForm" enctype="multipart/form-data" class="p-4" novalidate>
          <input type="hidden" name="action" id="pfAction" value="add">
          <input type="hidden" name="product_id" id="pfProductId" value="0">
          <input type="hidden" name="csrf_token" id="pfCsrfToken" value="<?= htmlspecialchars($csrf) ?>">

          <!-- Category Toggle -->
          <div class="mb-4">
            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading)">Product Category <span class="text-danger">*</span></label>
            <div class="d-flex gap-2">
              <input type="radio" class="btn-check" name="category_id" id="pfCatMed" value="1" checked>
              <label class="btn btn-outline-primary" for="pfCatMed"><i class="fas fa-pills me-1"></i>Medicine</label>
              <input type="radio" class="btn-check" name="category_id" id="pfCatNonMed" value="2">
              <label class="btn btn-outline-success" for="pfCatNonMed"><i class="fas fa-box me-1"></i>Non-Medicine</label>
            </div>
          </div>

          <div class="row g-3">
            <!-- Product Name -->
            <div class="col-md-6">
              <label class="form-label fw-semibold" style="color:var(--pharmacy-heading)">Product Name <span class="text-danger">*</span></label>
              <input type="text" name="product_name" id="pfProductName" class="form-control" placeholder="e.g. Panadol" required>
            </div>

            <!-- Brand Name -->
            <div class="col-md-6">
              <label class="form-label fw-semibold" style="color:var(--pharmacy-heading)">Brand Name <span class="text-danger">*</span></label>
              <input type="text" name="brand_name" id="pfBrandName" class="form-control" placeholder="e.g. GlaxoSmithKline" required>
            </div>

            <!-- Generic Name (medicine only) -->
            <div class="col-md-6 pf-med-field">
              <label class="form-label fw-semibold" style="color:var(--pharmacy-heading)">Generic Name <span class="text-danger">*</span></label>
              <input type="text" name="generic_name" id="pfGenericName" class="form-control" placeholder="e.g. Paracetamol">
            </div>

            <!-- Dosage Form (medicine only) -->
            <div class="col-md-3 pf-med-field">
              <label class="form-label fw-semibold" style="color:var(--pharmacy-heading)">Dosage Form <span class="text-danger">*</span></label>
              <input type="text" name="dosage_form" id="pfDosageForm" class="form-control"
                     list="pfDosageFormList" placeholder="e.g. Tablet">
              <datalist id="pfDosageFormList">
                <?php foreach (['Tablet','Syrup','Capsule','Injection','Cream','Ointment','Drops','Gel','Inhaler','Suppository','Patch','Powder','Suspension','Solution','Lotion','Granules'] as $df): ?>
                <option value="<?= htmlspecialchars($df) ?>">
                <?php endforeach; ?>
              </datalist>
            </div>

            <!-- Strength (medicine only) -->
            <div class="col-md-3 pf-med-field">
              <label class="form-label fw-semibold" style="color:var(--pharmacy-heading)">Strength</label>
              <input type="text" name="strength" id="pfStrength" class="form-control" placeholder="e.g. 500mg">
            </div>

            <!-- Size / Value (non-medicine only) -->
            <div class="col-md-6 pf-nonmed-field" style="display:none;">
              <label class="form-label fw-semibold" style="color:var(--pharmacy-heading)">Size / Value</label>
              <input type="text" name="size_value" id="pfSizeValue" class="form-control" placeholder="e.g. 500ml, 1kg">
            </div>

            <!-- Unit Type -->
            <div class="col-md-3">
              <label class="form-label fw-semibold" style="color:var(--pharmacy-heading)">Unit Type <span class="text-danger">*</span></label>
              <select name="unit_type" id="pfUnitType" class="form-select">
                <?php foreach (['Strip','Bottle','Box','Tube','Sachet','Vial','Ampoule','Pcs','Bag','Roll'] as $ut): ?>
                <option value="<?= $ut ?>"><?= $ut ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Units Per Pack -->
            <div class="col-md-3">
              <label class="form-label fw-semibold" style="color:var(--pharmacy-heading)">Units Per Pack <span class="text-danger">*</span></label>
              <input type="number" name="units_per_pack" id="pfUnitsPerPack" class="form-control" min="1" value="1">
            </div>

            <!-- Barcode -->
            <div class="col-md-6">
              <label class="form-label fw-semibold" style="color:var(--pharmacy-heading)">Barcode <span class="text-muted fw-normal">(optional)</span></label>
              <input type="text" name="barcode" id="pfBarcode" class="form-control" placeholder="e.g. 1234567890123">
            </div>

            <!-- Product Image -->
            <div class="col-12">
              <label class="form-label fw-semibold" style="color:var(--pharmacy-heading)">Product Image <span class="text-muted fw-normal">(optional, max 2MB)</span></label>
              <div class="d-flex align-items-center gap-3">
                <div id="pfImagePreviewWrap" style="display:none;">
                  <img id="pfImagePreview" src="" alt="Preview"
                       style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--pharmacy-border);">
                </div>
                <div class="flex-grow-1">
                  <input type="file" name="product_image" id="pfImageFile" class="form-control" accept="image/*"
                         onchange="previewProductImage(this)">
                </div>
                <div id="pfRemoveImageWrap" style="display:none;">
                  <label class="d-flex align-items-center gap-1 text-danger small" style="cursor:pointer;">
                    <input type="checkbox" name="remove_image" id="pfRemoveImage" value="1" onchange="handleRemoveImage(this)">
                    Remove Image
                  </label>
                </div>
              </div>
            </div>

          </div><!-- /row -->

          <!-- Validation error area -->
          <div id="pfValidationError" class="alert alert-danger mt-3" style="display:none;"></div>

        </form><!-- /productAjaxForm -->
      </div>
      <div class="modal-footer py-2" style="background:var(--pharmacy-table-th)">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="pfSaveBtn" onclick="submitProductForm()">
          <i class="fas fa-save me-1"></i><span id="pfSaveBtnText">Save Product</span>
        </button>
      </div>
    </div>
  </div>
</div><!-- /#productFormModal -->

<!-- ── TRASH ITEMS MODAL ── -->
<div class="modal fade" id="trashModal" tabindex="-1" aria-labelledby="trashModalLabel">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header trash-modal-header py-3">
        <div>
          <h5 class="modal-title mb-0" id="trashModalLabel">
            <i class="fas fa-trash-alt me-2"></i>Trash — Deleted Products
          </h5>
          <small class="opacity-75">Products deleted from the list. Restore anytime — nothing is permanently deleted.</small>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">

        <!-- Trash toolbar -->
        <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom"
             style="background:var(--pharmacy-table-th)">
          <div class="input-group input-group-sm" style="max-width:280px;">
            <span class="input-group-text bg-white"><i class="fas fa-search" style="color:color-mix(in srgb,var(--pharmacy-text) 50%,transparent)"></i></span>
            <input type="text" id="trashSearchInput" class="form-control border-start-0"
                   placeholder="Search trash…" oninput="filterTrash(this.value)">
          </div>
          <div class="ms-auto d-flex gap-2 align-items-center" id="trashBulkBar">
            <span style="color:color-mix(in srgb,var(--pharmacy-text) 65%,transparent);font-size:13px;"
                  id="trashSelectedCount" style="display:none;"></span>
            <button class="btn btn-sm btn-success" id="btnBulkRestore" style="display:none;"
                    onclick="bulkRestoreTrash()">
              <i class="fas fa-undo me-1"></i>Restore Selected
            </button>
            <button class="btn btn-sm btn-outline-secondary" id="btnSelectAllTrash" onclick="selectAllTrash()">
              Select All
            </button>
          </div>
          <div id="trashCountBadge" class="badge bg-secondary">Loading…</div>
        </div>

        <!-- Trash loading spinner -->
        <div id="trashLoading" class="text-center py-5">
          <div class="spinner-border" style="color:var(--pharmacy-primary)" role="status"></div>
          <p class="mt-2 small" style="color:color-mix(in srgb,var(--pharmacy-text) 55%,transparent)">Loading deleted products…</p>
        </div>

        <!-- Trash list (populated by JS) -->
        <div id="trashListContainer" style="display:none;">
          <div id="trashEmpty" class="trash-empty" style="display:none;">
            <i class="fas fa-check-circle fa-3x text-success mb-3 d-block"></i>
            <h6>Trash is Empty</h6>
            <p class="mb-0" style="font-size:13px;color:color-mix(in srgb,var(--pharmacy-text) 55%,transparent)">No deleted products. When you delete a product, it will appear here.</p>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" id="trashTable">
              <thead style="background:var(--pharmacy-table-th);position:sticky;top:0;">
                <tr>
                  <th style="background:var(--pharmacy-table-th);color:var(--pharmacy-heading)" width="30">
                    <input type="checkbox" class="form-check-input" id="trashCheckAll" onchange="trashCheckAllToggle(this)">
                  </th>
                  <th style="background:var(--pharmacy-table-th);color:var(--pharmacy-heading)">#</th>
                  <th style="background:var(--pharmacy-table-th);color:var(--pharmacy-heading)">Product Name</th>
                  <th style="background:var(--pharmacy-table-th);color:var(--pharmacy-heading)">Category</th>
                  <th style="background:var(--pharmacy-table-th);color:var(--pharmacy-heading)">Brand</th>
                  <th style="background:var(--pharmacy-table-th);color:var(--pharmacy-heading)">Generic / Size</th>
                  <th style="background:var(--pharmacy-table-th);color:var(--pharmacy-heading)">Dosage Form</th>
                  <th style="background:var(--pharmacy-table-th);color:var(--pharmacy-heading)">Code</th>
                  <th style="background:var(--pharmacy-table-th);color:var(--pharmacy-heading)">Deleted On</th>
                  <th style="background:var(--pharmacy-table-th);color:var(--pharmacy-heading)">Action</th>
                </tr>
              </thead>
              <tbody id="trashTableBody"></tbody>
            </table>
          </div>
        </div>

      </div>
      <div class="modal-footer py-2" style="background:var(--pharmacy-table-th)">
        <div class="me-auto small" style="color:color-mix(in srgb,var(--pharmacy-text) 65%,transparent)">
          <i class="fas fa-info-circle me-1 text-warning"></i>
          Products in trash are hidden from the main list but all data is preserved.
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Price Edit Modal ── -->
<div class="modal fade" id="priceModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header modal-header-muted py-2">
        <h6 class="modal-title" style="color:var(--pharmacy-heading)">
          <i class="fas fa-coins me-2" style="color:var(--pharmacy-primary)"></i>Update Batch Sale Price
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2 small fw-bold" style="color:color-mix(in srgb,var(--pharmacy-text) 65%,transparent)" id="priceModalLabel"></div>
        <div class="input-group input-group-sm">
          <span class="input-group-text">Rs</span>
          <input type="number" id="newPriceInput" class="form-control" step="0.01" min="0.01">
        </div>
        <div id="priceError" class="text-danger small mt-1" style="display:none"></div>
      </div>
      <div class="modal-footer py-1">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" id="savePriceBtn">
          <i class="fas fa-save me-1"></i>Save
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Stock Protected Modal ── -->
<div class="modal fade" id="protectedModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <i class="fas fa-lock fa-3x text-warning mb-3"></i>
        <h6 style="color:var(--pharmacy-heading)">Cannot Edit / Delete</h6>
        <p class="small mb-0" style="color:color-mix(in srgb,var(--pharmacy-text) 65%,transparent)" id="protectedMsg"></p>
      </div>
      <div class="modal-footer py-1 justify-content-center">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Delete → Move to Trash Confirmation Modal ── -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <i class="fas fa-trash-alt fa-3x text-warning mb-3"></i>
        <h6 style="color:var(--pharmacy-heading)">Move to Trash?</h6>
        <p style="color:color-mix(in srgb,var(--pharmacy-text) 65%,transparent)" id="deleteProductName"></p>
        <p style="color:color-mix(in srgb,var(--pharmacy-text) 55%,transparent);font-size:12px;">This product will be moved to Trash. You can restore it anytime.</p>
      </div>
      <div class="modal-footer py-1 justify-content-center">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-warning" id="deleteConfirmBtn">
          <i class="fas fa-trash-alt me-1"></i>Move to Trash
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Image Modal ── -->
<div class="modal fade" id="imageModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header modal-header-muted py-2">
        <h6 class="modal-title" id="imageModalTitle" style="color:var(--pharmacy-heading)">Product Image</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <img id="imageModalImg" src="" class="img-fluid rounded mb-3" style="max-height:300px;display:none">
        <div id="imageModalPlaceholder" class="py-4" style="color:color-mix(in srgb,var(--pharmacy-text) 50%,transparent)">
          <i class="fas fa-image fa-3x mb-2 d-block"></i>No image uploaded
        </div>
        <form method="POST" enctype="multipart/form-data" id="imageUploadForm">
          <input type="hidden" name="csrf_token" id="imageCsrfToken">
          <input type="hidden" name="action" value="upload">
          <input type="hidden" name="product_id" id="imageProductId">
          <input type="file" name="product_image" id="imageFileInput"
                 class="form-control form-control-sm mb-2" accept="image/*">
          <button type="button" class="btn btn-sm btn-primary w-100" onclick="submitImageUpload()">
            <i class="fas fa-upload me-1"></i>Upload Image
          </button>
          <button type="button" class="btn btn-sm btn-outline-danger w-100 mt-1"
                  id="removeImgBtn" onclick="removeProductImage()" style="display:none;">
            <i class="fas fa-trash me-1"></i>Remove Image
          </button>
          <div id="imageUploadMsg" class="small mt-2"></div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════ SCRIPTS ══════════════════════════════ -->


<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';
const BASE = '<?= rtrim(BASE_URL,'/') ?>';

// ── Bootstrap tooltips init ──────────────────────────────────────────────
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el, { trigger: 'hover' });
});

// ── Modal instances — use lazy getters so Bootstrap.Modal is never stale ────
// BUG FIX: Previously these were created once at page load. After AJAX table
// refresh the old instances still referenced the DOM correctly (modals are
// outside #tableWrapper) but calling .show() on a disposed instance threw
// errors.  Using bootstrap.Modal.getOrCreateInstance() is the safe pattern.
function getModal(id) {
    return bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
}
// Convenience aliases (keep names identical so all call-sites below still work)
const priceModal     = { show(){ getModal('priceModal').show();     }, hide(){ getModal('priceModal').hide();     } };
const protectedModal = { show(){ getModal('protectedModal').show(); }, hide(){ getModal('protectedModal').hide(); } };
const deleteModal    = { show(){ getModal('deleteModal').show();     }, hide(){ getModal('deleteModal').hide();     } };
const imageModal     = { show(){ getModal('imageModal').show();      }, hide(){ getModal('imageModal').hide();      } };
const trashModal     = { show(){ getModal('trashModal').show();      }, hide(){ getModal('trashModal').hide();      } };

// ════════════════════════════════════════════════════════════════════════
// TRASH MODAL LOGIC
// ════════════════════════════════════════════════════════════════════════
let trashData = [];
let trashFiltered = [];

function openTrashModal() {
    trashModal.show();
    loadTrashItems();
}

function loadTrashItems() {
    document.getElementById('trashLoading').style.display      = '';
    document.getElementById('trashListContainer').style.display = 'none';
    document.getElementById('trashCountBadge').textContent      = '…';

    fetch('trash_products.php?trash=1')
        .then(r => r.json())
        .then(data => {
            trashData     = Array.isArray(data) ? data : [];
            trashFiltered = trashData;
            renderTrashTable(trashFiltered);
            document.getElementById('trashLoading').style.display       = 'none';
            document.getElementById('trashListContainer').style.display = '';
            document.getElementById('trashCountBadge').textContent = trashData.length + ' item(s)';
        })
        .catch(err => {
            document.getElementById('trashLoading').innerHTML =
                `<div class="alert alert-danger m-3"><i class="fas fa-exclamation-triangle me-2"></i>Failed to load trash: ${escHtml(err.message)}</div>`;
        });
}

function renderTrashTable(items) {
    const tbody = document.getElementById('trashTableBody');
    const empty = document.getElementById('trashEmpty');

    if (!items || items.length === 0) {
        tbody.innerHTML = '';
        empty.style.display = '';
        document.getElementById('trashCheckAll').checked = false;
        updateTrashBulkBar();
        return;
    }
    empty.style.display = 'none';

    let html = '';
    items.forEach((p, i) => {
        const catBadge = p.category_id == 1
            ? `<span class="cat-badge-med">💊 Medicine</span>`
            : `<span class="cat-badge-nmed">📦 Non-Med</span>`;
        const genericOrSize = p.category_id == 1
            ? escHtml(p.generic_name || '—')
            : escHtml(p.size_value   || '—');
        const deletedOn = p.deleted_at
            ? new Date(p.deleted_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})
            : '—';

        html += `
        <tr class="trash-item-row" data-id="${p.id}" data-name="${escHtml(p.product_name)}">
          <td><input type="checkbox" class="form-check-input trash-check" value="${p.id}" onchange="updateTrashBulkBar()"></td>
          <td class="text-muted">${i + 1}</td>
          <td>
            <div class="trash-product-name">${escHtml(p.product_name)}</div>
            <div class="trash-deleted-at"><i class="fas fa-clock me-1"></i>Deleted: ${deletedOn}</div>
           </td>
          <td>${catBadge}</td>
          <td class="trash-product-meta">${escHtml(p.brand_name || '—')}</td>
          <td class="trash-product-meta">${genericOrSize}</td>
          <td class="trash-product-meta">${escHtml(p.dosage_form || '—')}</td>
          <td><span class="badge bg-light text-dark border" style="font-size:11px;">${escHtml(p.product_code || '—')}</span></td>
          <td><small class="text-danger">${deletedOn}</small></td>
          <td>
            <button class="btn btn-sm btn-success restore-btn" onclick="restoreProduct(${p.id}, '${escHtml(p.product_name)}', this)">
              <i class="fas fa-undo me-1"></i>Restore
            </button>
           </td>
         </tr>`;
    });
    tbody.innerHTML = html;
    updateTrashBulkBar();
}

function filterTrash(query) {
    const q = query.toLowerCase().trim();
    trashFiltered = q === ''
        ? trashData
        : trashData.filter(p =>
            (p.product_name  || '').toLowerCase().includes(q) ||
            (p.brand_name    || '').toLowerCase().includes(q) ||
            (p.generic_name  || '').toLowerCase().includes(q) ||
            (p.product_code  || '').toLowerCase().includes(q)
          );
    renderTrashTable(trashFiltered);
}

function selectAllTrash() {
    const checks = document.querySelectorAll('.trash-check');
    const allChecked = [...checks].every(c => c.checked);
    checks.forEach(c => c.checked = !allChecked);
    document.getElementById('trashCheckAll').checked = !allChecked;
    updateTrashBulkBar();
}

function trashCheckAllToggle(masterCb) {
    document.querySelectorAll('.trash-check').forEach(c => c.checked = masterCb.checked);
    updateTrashBulkBar();
}

function updateTrashBulkBar() {
    const selected = document.querySelectorAll('.trash-check:checked');
    const countEl  = document.getElementById('trashSelectedCount');
    const bulkBtn  = document.getElementById('btnBulkRestore');
    if (selected.length > 0) {
        countEl.textContent  = `${selected.length} selected`;
        countEl.style.display = '';
        bulkBtn.style.display = '';
        document.getElementById('btnSelectAllTrash').textContent = 'Select All';
    } else {
        countEl.style.display = 'none';
        bulkBtn.style.display = 'none';
    }
}

function restoreProduct(productId, productName, btn) {
    if (!confirm(`Restore "${productName}" back to the active products list?`)) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Restoring…';

    const fd = new FormData();
    fd.append('action',     'restore');
    fd.append('product_id', productId);
    fd.append('csrf_token', CSRF);

    fetch('trash_products.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(resp => {
            if (resp.success) {
                trashData     = trashData.filter(p => p.id != productId);
                trashFiltered = trashFiltered.filter(p => p.id != productId);
                renderTrashTable(trashFiltered);
                document.getElementById('trashCountBadge').textContent = trashData.length + ' item(s)';
                updateTrashBadge(-1);
                showToast('✅ ' + productName + ' restored successfully!', 'success');
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-undo me-1"></i>Restore';
                showToast('❌ ' + (resp.message || 'Restore failed.'), 'danger');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-undo me-1"></i>Restore';
            showToast('❌ Network error: ' + err.message, 'danger');
        });
}

function bulkRestoreTrash() {
    const selected = [...document.querySelectorAll('.trash-check:checked')].map(c => c.value);
    if (selected.length === 0) return;
    if (!confirm(`Restore ${selected.length} selected product(s) back to the active list?`)) return;

    const btn = document.getElementById('btnBulkRestore');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Restoring…';

    const fd = new FormData();
    fd.append('action', 'bulk_restore');
    fd.append('csrf_token', CSRF);
    selected.forEach(id => fd.append('product_ids[]', id));

    fetch('trash_products.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(resp => {
            if (resp.success) {
                trashData     = trashData.filter(p => !selected.includes(String(p.id)));
                trashFiltered = trashFiltered.filter(p => !selected.includes(String(p.id)));
                renderTrashTable(trashFiltered);
                document.getElementById('trashCountBadge').textContent = trashData.length + ' item(s)';
                updateTrashBadge(-selected.length);
                showToast('✅ ' + resp.count + ' product(s) restored!', 'success');
            } else {
                showToast('❌ ' + (resp.message || 'Bulk restore failed.'), 'danger');
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-undo me-1"></i>Restore Selected';
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-undo me-1"></i>Restore Selected';
            showToast('❌ Network error: ' + err.message, 'danger');
        });
}

function updateTrashBadge(delta) {
    const badge = document.querySelector('.btn-trash .trash-badge');
    if (!badge) return;
    let current = parseInt(badge.textContent) || 0;
    current = Math.max(0, current + delta);
    badge.textContent = current > 99 ? '99+' : current;
    badge.style.display = current === 0 ? 'none' : '';
}

// ════════════════════════════════════════════════════════════════════════
// DELETE → SOFT DELETE (Move to Trash) - FIXED VERSION
// ════════════════════════════════════════════════════════════════════════
let _deleteProductId = null;

function confirmDelete(productId, name) {
    _deleteProductId = productId;
    document.getElementById('deleteProductName').textContent = '"' + name + '"';
    deleteModal.show();
}

// BUG FIX: Use addEventListener instead of .onclick so it survives AJAX re-renders.
// The modal itself is outside #tableWrapper so it is never re-created, but using
// addEventListener (with { once:false }) is cleaner and avoids double-fire issues.
document.getElementById('deleteConfirmBtn').addEventListener('click', function() {
    if (_deleteProductId) {
        doSoftDelete(_deleteProductId);
    }
});

function doSoftDelete(productId) {
    const btn = document.getElementById('deleteConfirmBtn');
    const productName = document.getElementById('deleteProductName').textContent.replace(/"/g, '');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Moving…';

    // BUG FIX: Was using method:'GET' but building FormData (body ignored on GET).
    // Now properly using POST to send product_id + csrf_token in the request body.
    const fd = new FormData();
    fd.append('product_id', productId);
    fd.append('csrf_token', CSRF);

    fetch('delete.php?soft=1&ajax=1', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(resp => {
        deleteModal.hide();
        _deleteProductId = null; // BUG FIX: clear so stale ID cannot re-fire
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash-alt me-1"></i>Move to Trash';
        
        if (resp.success) {
            // Remove row from table
            const row = document.querySelector(`tr[data-product-id="${productId}"]`);
            if (row) {
                row.style.transition = 'opacity .3s';
                row.style.opacity = '0';
                setTimeout(() => { row.remove(); updateBulkBar(); }, 300);
            }
            updateTrashBadge(+1);
            showToast('🗑️ "' + productName + '" moved to Trash. <a href="#" onclick="openTrashModal();return false;">View Trash</a>', 'warning');
            // AJAX refresh table (no page reload)
            setTimeout(() => ajaxTableRefresh(), 400);
        } else {
            if (resp.blocked) {
                showToast('⚠️ ' + resp.message, 'warning');
            } else {
                showToast('❌ ' + (resp.message || 'Delete failed.'), 'danger');
            }
        }
    })
    .catch(err => {
        deleteModal.hide();
        _deleteProductId = null; // BUG FIX: clear on error too
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash-alt me-1"></i>Move to Trash';
        showToast('❌ Network error: ' + err.message, 'danger');
    });
}

// ════════════════════════════════════════════════════════════════════════
// PRICE MODAL
// ════════════════════════════════════════════════════════════════════════
let _batchId = null, _productId2 = null;

function openPriceModal(batchId, productId, name, currentPrice) {
    _batchId = batchId; _productId2 = productId;
    document.getElementById('priceModalLabel').textContent = name + ' (Batch #' + batchId + ')';
    document.getElementById('newPriceInput').value = currentPrice;
    document.getElementById('priceError').style.display = 'none';
    priceModal.show();
    setTimeout(() => document.getElementById('newPriceInput').focus(), 300);
}

document.getElementById('savePriceBtn').addEventListener('click', function() {
    const price = parseFloat(document.getElementById('newPriceInput').value);
    const err   = document.getElementById('priceError');
    if (!price || price <= 0) { err.textContent = 'Please enter a valid price.'; err.style.display = ''; return; }
    err.style.display = 'none';
    this.disabled = true; this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving…';
    fetch(BASE + '/ajax/update_batch_price.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({batch_id:_batchId, new_price:price, csrf_token:CSRF})
    }).then(r => r.json()).then(d => {
        if (d.success) {
            const el = document.getElementById('price-display-' + _batchId);
            if (el) {
                const btnHtml = el.innerHTML.includes('<button') ? el.innerHTML.substring(el.innerHTML.indexOf('<button')) : '';
                el.innerHTML = 'Rs ' + parseFloat(d.new_price).toFixed(2) + ' ' + btnHtml;
            }
            priceModal.hide();
            showToast('✅ Sale price updated.', 'success');
        } else {
            err.textContent = d.message || 'Update failed.'; err.style.display = '';
        }
    }).catch(() => { err.textContent = 'Network error.'; err.style.display = ''; })
    .finally(() => { this.disabled = false; this.innerHTML = '<i class="fas fa-save me-1"></i>Save'; });
});

// ════════════════════════════════════════════════════════════════════════
// OTHER HELPERS
// ════════════════════════════════════════════════════════════════════════
function showStockProtected(name) {
    document.getElementById('protectedMsg').textContent =
        '"' + name + '" has active stock. Sell or return all stock first to edit or delete.';
    protectedModal.show();
}

function openImageModal(productId, name, imgUrl) {
    document.getElementById('imageModalTitle').textContent = name;
    document.getElementById('imageProductId').value = productId;
    document.getElementById('imageCsrfToken').value = CSRF;
    document.getElementById('imageUploadMsg').textContent = '';
    document.getElementById('imageFileInput').value = '';
    const img = document.getElementById('imageModalImg');
    const ph  = document.getElementById('imageModalPlaceholder');
    const removeBtn = document.getElementById('removeImgBtn');
    if (imgUrl) { img.src = imgUrl; img.style.display = ''; ph.style.display = 'none'; removeBtn.style.display = ''; }
    else        { img.style.display = 'none'; ph.style.display = ''; removeBtn.style.display = 'none'; }
    imageModal.show();
}

function submitImageUpload() {
    const fileInput = document.getElementById('imageFileInput');
    if (!fileInput.files.length) { showImgMsg('Please select an image file.', 'danger'); return; }
    const formData = new FormData(document.getElementById('imageUploadForm'));
    formData.set('action', 'upload');
    fetch(BASE + '/ajax/update_product_image.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showImgMsg('Image uploaded successfully.', 'success');
                if (d.image_path) {
                    const url = BASE + '/' + d.image_path;
                    document.getElementById('imageModalImg').src = url;
                    document.getElementById('imageModalImg').style.display = '';
                    document.getElementById('imageModalPlaceholder').style.display = 'none';
                    document.getElementById('removeImgBtn').style.display = '';
                }
                setTimeout(() => { imageModal.hide(); ajaxTableRefresh(); }, 800);
            } else { showImgMsg(d.message || 'Upload failed.', 'danger'); }
        }).catch(() => showImgMsg('Network error.', 'danger'));
}

function removeProductImage() {
    const productId = document.getElementById('imageProductId').value;
    if (!confirm('Remove product image?')) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('product_id', productId);
    fd.append('action', 'remove');
    fetch(BASE + '/ajax/update_product_image.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                document.getElementById('imageModalImg').style.display = 'none';
                document.getElementById('imageModalPlaceholder').style.display = '';
                document.getElementById('removeImgBtn').style.display = 'none';
                showImgMsg('Image removed.', 'success');
                setTimeout(() => { imageModal.hide(); ajaxTableRefresh(); }, 800);
            } else { showImgMsg(d.message || 'Remove failed.', 'danger'); }
        }).catch(() => showImgMsg('Network error.', 'danger'));
}

function showImgMsg(msg, type) {
    const el = document.getElementById('imageUploadMsg');
    el.className = 'small mt-2 text-' + type;
    el.textContent = msg;
}

function printBarcode(batchId) {
    const f = document.createElement('form');
    f.method = 'POST'; f.action = BASE + '/ajax/print_barcode_labels.php'; f.target = '_blank';
    const csrf = document.createElement('input'); csrf.type = 'hidden'; csrf.name = 'csrf_token'; csrf.value = CSRF;
    f.appendChild(csrf);
    const row = document.querySelector('tr[data-batch-id="' + batchId + '"]');
    const productId = row ? row.getAttribute('data-product-id') : batchId;
    const pi = document.createElement('input'); pi.type = 'hidden'; pi.name = 'product_ids[]'; pi.value = productId;
    f.appendChild(pi);
    const bi = document.createElement('input'); bi.type = 'hidden'; bi.name = 'batch_id'; bi.value = batchId;
    f.appendChild(bi);
    const lm = document.createElement('input'); lm.type = 'hidden'; lm.name = 'label_mode'; lm.value = 'batch_specific';
    f.appendChild(lm);
    document.body.appendChild(f); f.submit(); document.body.removeChild(f);
}

// ════════════════════════════════════════════════════════════════════════
// CHECKBOX & BULK ACTIONS
// ════════════════════════════════════════════════════════════════════════

const checkAllEl = document.getElementById('checkAll');
if (checkAllEl) {
    checkAllEl.addEventListener('change', function() {
        const wantChecked = this.checked;
        document.querySelectorAll('.row-check').forEach(cb => { cb.checked = wantChecked; });
        updateBulkBar();
    });
}

document.querySelectorAll('.row-check').forEach(cb => cb.addEventListener('change', function() {
    updateBulkBar();
    const all = document.querySelectorAll('.row-check');
    const chk = document.querySelectorAll('.row-check:checked');
    const ca  = document.getElementById('checkAll');
    if (!ca) return;
    if (chk.length === 0)              { ca.checked = false; ca.indeterminate = false; }
    else if (chk.length === all.length){ ca.checked = true;  ca.indeterminate = false; }
    else                               { ca.checked = false; ca.indeterminate = true;  }
}));

function updateBulkBar() {
    const allChecks = document.querySelectorAll('.row-check:checked');
    const sel       = allChecks.length;
    const bar       = document.getElementById('bulkActionsBar');
    const cnt       = document.getElementById('selectedCount');
    const trashBtn  = document.getElementById('bulkTrashBtn');
    const trashCnt  = document.getElementById('bulkTrashCount');
    const skipNote  = document.getElementById('bulkTrashSkipNote');

    if (sel > 0) {
        bar.classList.remove('d-none');
        bar.classList.add('d-flex');
        cnt.textContent = sel + ' product' + (sel > 1 ? 's' : '') + ' selected';

        let eligible = 0, skipped = 0;
        allChecks.forEach(cb => {
            const row = cb.closest('tr');
            const hasActive = row && row.getAttribute('data-has-active') === '1';
            if (hasActive) skipped++; else eligible++;
        });

        trashCnt.textContent = eligible;
        if (eligible > 0) {
            trashBtn.style.display = '';
            if (skipped > 0) {
                skipNote.style.display = '';
                skipNote.textContent = '(' + skipped + ' with active stock will be skipped)';
            } else {
                skipNote.style.display = 'none';
            }
        } else {
            trashBtn.style.display = 'none';
            skipNote.style.display = '';
            skipNote.textContent = 'All selected products have active stock — cannot move to Trash.';
        }
    } else {
        bar.classList.add('d-none');
        bar.classList.remove('d-flex');
        trashBtn.style.display = 'none';
        skipNote.style.display = 'none';
    }
}

function clearSelection() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
    const ca = document.getElementById('checkAll');
    if (ca) { ca.checked = false; ca.indeterminate = false; }
    updateBulkBar();
}

// ── Bulk Move to Trash ────────────────────────────────────────────────
function bulkMoveToTrash() {
    const eligible = [];
    document.querySelectorAll('.row-check:checked').forEach(cb => {
        const row = cb.closest('tr');
        const hasActive = row && row.getAttribute('data-has-active') === '1';
        if (!hasActive) eligible.push(cb.value);
    });

    if (eligible.length === 0) {
        showToast('⚠️ No eligible products selected. Products with active stock cannot be moved to Trash.', 'warning');
        return;
    }

    const skipped = document.querySelectorAll('.row-check:checked').length - eligible.length;
    let msg = `Move ${eligible.length} product(s) to Trash?`;
    if (skipped > 0) msg += `\n\n${skipped} product(s) with active stock will be skipped.`;
    if (!confirm(msg)) return;

    const btn = document.getElementById('bulkTrashBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Moving…';

    let movedCount = 0;
    let failedCount = 0;

    async function deletOneByOne() {
        for (const id of eligible) {
            try {
                // BUG FIX: Was using method:'GET' which meant FormData body was
                // ignored — delete.php never received product_id and returned
                // "Invalid product ID".  Now using POST with product_id in body,
                // matching the same pattern as single-item doSoftDelete().
                const fd = new FormData();
                fd.append('product_id', id);
                fd.append('csrf_token', CSRF);
                const response = await fetch('delete.php?soft=1&ajax=1', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const resp = await response.json();
                
                if (resp.success) {
                    movedCount++;
                    const row = document.querySelector(`tr[data-product-id="${id}"]`);
                    if (row) {
                        row.style.transition = 'opacity .3s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 300);
                    }
                } else {
                    failedCount++;
                }
            } catch(e) {
                failedCount++;
            }
        }

        if (movedCount > 0) {
            updateTrashBadge(movedCount);
            clearSelection();
            let msg = `🗑️ ${movedCount} product(s) moved to Trash. <a href="#" onclick="openTrashModal();return false;">View Trash</a>`;
            if (failedCount > 0) msg += ` (${failedCount} failed)`;
            showToast(msg, 'warning');
            setTimeout(() => ajaxTableRefresh(), 400);
        } else {
            showToast('❌ Could not move any products to Trash.', 'danger');
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash-alt me-1"></i>Move to Trash';
    }

    deletOneByOne();
}

// ════════════════════════════════════════════════════════════════════════
// FILTER STATE + UNIFIED AJAX TABLE REFRESH
// ════════════════════════════════════════════════════════════════════════
const _filterState = {
    search:        '<?= addslashes($search) ?>',
    category:      '<?= addslashes($category) ?>',
    search_type:   '<?= addslashes($searchType) ?>',
    stock_filter:  '<?= addslashes($stockFilter) ?>',
    expiry_filter: '<?= addslashes($expFilter) ?>',
    sort_by:       '<?= addslashes($sortBy) ?>',
    sort_dir:      '<?= addslashes($sortDir) ?>',
    per_page:      '<?= addslashes($perPage) ?>',
    page:          <?= $page ?>,
};

let searchTimer = null;

function ajaxTableRefresh(overrides = {}) {
    const p = new URLSearchParams();
    // Base state
    Object.entries(_filterState).forEach(([k, v]) => p.set(k, String(v)));
    // Live form values
    const searchEl = document.getElementById('searchInput');
    if (searchEl) { p.set('search', searchEl.value); }
    const ff = document.getElementById('filterForm');
    const catEl = ff ? ff.querySelector('select[name="category"]') : null;
    if (catEl) p.set('category', catEl.value);
    const stEl = ff ? ff.querySelector('input[name="search_type"]:checked') : null;
    if (stEl) p.set('search_type', stEl.value);
    // Apply overrides
    Object.entries(overrides).forEach(([k, v]) => { p.set(k, String(v)); _filterState[k] = v; });
    p.set('ajax', 'table_refresh');

    const wrapper = document.getElementById('tableWrapper');
    const spinner = document.getElementById('searchSpinner');
    if (wrapper) wrapper.style.opacity = '0.55';
    if (spinner) spinner.style.display = 'inline';

    fetch('?' + p.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (wrapper) wrapper.innerHTML = data.html;
                // BUG FIX: Update the showing count text (lives outside #tableWrapper)
                const sc = document.getElementById('showingCount');
                if (sc && data.total !== undefined) {
                    const shown = wrapper ? wrapper.querySelectorAll('tbody tr[data-product-id]').length : 0;
                    sc.innerHTML = 'Showing <strong style="color:var(--pharmacy-heading)">' + shown + '</strong>'
                        + ' of <strong style="color:var(--pharmacy-heading)">' + data.total + '</strong> products'
                        + ' (Page ' + data.page + ' of ' + data.totalPages + ')';
                }
                Object.entries(data.counts).forEach(([k, v]) => {
                    const el = document.querySelector('[data-pill="' + k + '"]');
                    if (el) el.textContent = v;
                });
                const urlP = new URLSearchParams(p.toString());
                urlP.delete('ajax');
                history.pushState({}, '', window.location.pathname + '?' + urlP.toString());
                if (window.bootstrap) {
                    document.querySelectorAll('#tableWrapper [data-bs-toggle="tooltip"]').forEach(el => {
                        try { new bootstrap.Tooltip(el); } catch(e) {}
                    });
                }
                _rebindCheckboxes();
            }
        })
        .catch(e => console.error('Table refresh error:', e))
        .finally(() => {
            if (wrapper) wrapper.style.opacity = '1';
            if (spinner) spinner.style.display = 'none';
        });
}

function ajaxGoPage(page) {
    ajaxTableRefresh({ page });
}

function ajaxFilterPill(sf, ef) {
    // Update active class on pills immediately (visual feedback)
    document.querySelectorAll('.filter-pill').forEach(pill => {
        const pSf = pill.getAttribute('data-sf');
        const pEf = pill.getAttribute('data-ef');
        const isActive = (pSf === sf && pEf === ef);
        // Remove all active classes
        pill.classList.remove('active','active-danger','active-dark','active-warning','active-secondary');
        if (isActive) {
            // Re-add the right active class based on sf value
            const activeMap = {
                'in_stock':     'active',
                'out_of_stock': 'active-danger',
                'expired':      'active-dark',
                'near_expiry':  'active-warning',
                'low_stock':    'active-warning',
                'all':          'active-secondary',
            };
            pill.classList.add(activeMap[sf] || 'active');
        }
    });
    ajaxTableRefresh({ stock_filter: sf, expiry_filter: ef, page: 1 });
}

function ajaxSort(col) {
    const dir = (_filterState.sort_by === col && _filterState.sort_dir === 'asc') ? 'desc' : 'asc';
    ajaxTableRefresh({ sort_by: col, sort_dir: dir, page: 1 });
}

function ajaxChangePerPage(val) {
    ajaxTableRefresh({ per_page: val, page: 1 });
}

function changePerPage(val) { ajaxChangePerPage(val); }

function ajaxResetFilters() {
    const ff = document.getElementById('filterForm');
    const searchEl = document.getElementById('searchInput');
    if (searchEl) searchEl.value = '';
    const catEl = ff ? ff.querySelector('select[name="category"]') : null;
    if (catEl) catEl.value = '';
    const stEl = ff ? ff.querySelector('input[name="search_type"][value="all"]') : null;
    if (stEl) stEl.checked = true;
    ajaxTableRefresh({ search:'', category:'', search_type:'all', stock_filter:'in_stock', expiry_filter:'all', sort_by:'expiry', sort_dir:'asc', per_page:'20', page:1 });
}

function _rebindCheckboxes() {
    const ca = document.getElementById('checkAll');
    if (ca) {
        const fresh = ca.cloneNode(true);
        ca.parentNode.replaceChild(fresh, ca);
        fresh.addEventListener('change', function() {
            document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
            updateBulkBar();
        });
    }
    document.querySelectorAll('.row-check').forEach(cb => {
        cb.addEventListener('change', function() {
            updateBulkBar();
            const all = document.querySelectorAll('.row-check');
            const chk = document.querySelectorAll('.row-check:checked');
            const cAll = document.getElementById('checkAll');
            if (!cAll) return;
            if (chk.length === 0)              { cAll.checked = false; cAll.indeterminate = false; }
            else if (chk.length === all.length){ cAll.checked = true;  cAll.indeterminate = false; }
            else                               { cAll.checked = false; cAll.indeterminate = true;  }
        });
    });
}

// Search input — debounced
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        const q = this.value.trim();
        if (q.length > 0 && q.length < 2) return;
        searchTimer = setTimeout(() => ajaxTableRefresh(), 350);
    });
}

// Category select — immediate
const catSelect = document.querySelector('#filterForm select[name="category"]');
if (catSelect) {
    catSelect.removeAttribute('onchange');
    catSelect.addEventListener('change', () => ajaxTableRefresh());
}

// Search type radio — immediate
document.querySelectorAll('#filterForm input[name="search_type"]').forEach(el => {
    el.addEventListener('change', () => ajaxTableRefresh());
});

window.addEventListener('popstate', () => window.location.reload());

// ════════════════════════════════════════════════════════════════════════
// ADD / EDIT PRODUCT MODAL (AJAX)
// ════════════════════════════════════════════════════════════════════════
const productFormModal = { show(){ getModal('productFormModal').show(); }, hide(){ getModal('productFormModal').hide(); } };

function openAddProductModal() {
    _resetProductForm();
    document.getElementById('productFormModalTitleText').textContent = 'Add New Product';
    document.getElementById('productFormModalSubtitle').textContent  = 'Fill in product details below';
    document.getElementById('pfAction').value    = 'add';
    document.getElementById('pfProductId').value = '0';
    document.getElementById('pfSaveBtnText').textContent = 'Add Product';
    document.getElementById('productFormLoading').style.display = 'none';
    document.getElementById('productFormError').style.display   = 'none';
    document.getElementById('productFormLocked').style.display  = 'none';
    document.getElementById('productAjaxForm').style.display    = '';
    document.getElementById('pfSaveBtn').style.display          = '';
    productFormModal.show();
    setTimeout(() => document.getElementById('pfProductName').focus(), 300);
}

function openEditProductModal(productId) {
    _resetProductForm();
    document.getElementById('productFormModalTitleText').textContent = 'Edit Product';
    document.getElementById('productFormModalSubtitle').textContent  = 'Loading…';
    document.getElementById('pfAction').value    = 'edit';
    document.getElementById('pfProductId').value = productId;
    document.getElementById('pfSaveBtnText').textContent = 'Save Changes';
    document.getElementById('productFormLoading').style.display = '';
    document.getElementById('productFormError').style.display   = 'none';
    document.getElementById('productFormLocked').style.display  = 'none';
    document.getElementById('productAjaxForm').style.display    = 'none';
    document.getElementById('pfSaveBtn').style.display          = 'none';
    productFormModal.show();

    fetch(BASE + '/ajax/get_product_form_data.php?product_id=' + productId, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            document.getElementById('productFormLoading').style.display = 'none';
            if (!data.success) {
                document.getElementById('productFormError').textContent = data.message || 'Failed to load product.';
                document.getElementById('productFormError').style.display = '';
                return;
            }
            const p = data.product;
            document.getElementById('productFormModalSubtitle').textContent = p.is_editable
                ? 'Editing: ' + escHtml(p.product_name)
                : 'Locked — has active stock';
            _fillProductForm(p);
            if (!p.is_editable) {
                document.getElementById('productFormLocked').style.display = '';
                document.getElementById('productAjaxForm').style.display   = '';
                document.getElementById('productAjaxForm').querySelectorAll('input,select,textarea').forEach(el => el.disabled = true);
            } else {
                document.getElementById('productAjaxForm').style.display = '';
                document.getElementById('pfSaveBtn').style.display        = '';
            }
        })
        .catch(err => {
            document.getElementById('productFormLoading').style.display = 'none';
            document.getElementById('productFormError').textContent     = 'Network error: ' + err.message;
            document.getElementById('productFormError').style.display   = '';
        });
}

function _fillProductForm(p) {
    const ismed = (parseInt(p.category_id) === 1);
    document.getElementById(ismed ? 'pfCatMed' : 'pfCatNonMed').checked = true;
    _updateCategoryFields(ismed);
    document.getElementById('pfProductName').value   = p.product_name   || '';
    document.getElementById('pfBrandName').value     = p.brand_name     || '';
    document.getElementById('pfGenericName').value   = p.generic_name   || '';
    document.getElementById('pfDosageForm').value    = p.dosage_form    || '';
    document.getElementById('pfStrength').value      = p.strength       || '';
    document.getElementById('pfSizeValue').value     = p.size_value     || '';
    document.getElementById('pfBarcode').value       = p.barcode        || '';
    document.getElementById('pfUnitsPerPack').value  = p.units_per_pack || 1;
    const unitSel = document.getElementById('pfUnitType');
    const ut = p.unit_type || 'Pcs';
    let found = false;
    for (let opt of unitSel.options) { if (opt.value === ut) { opt.selected = true; found = true; break; } }
    if (!found) { unitSel.add(new Option(ut, ut, true, true)); }
    const previewWrap = document.getElementById('pfImagePreviewWrap');
    const previewImg  = document.getElementById('pfImagePreview');
    const removeWrap  = document.getElementById('pfRemoveImageWrap');
    if (p.image_url) {
        previewImg.src = p.image_url;
        previewWrap.style.display = '';
        removeWrap.style.display  = '';
    } else {
        previewWrap.style.display = 'none';
        removeWrap.style.display  = 'none';
    }
}

function _resetProductForm() {
    document.getElementById('productAjaxForm').reset();
    document.getElementById('pfCatMed').checked = true;
    _updateCategoryFields(true);
    ['pfProductName','pfBrandName','pfGenericName','pfDosageForm','pfStrength','pfSizeValue','pfBarcode'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.value = ''; el.disabled = false; }
    });
    document.getElementById('pfUnitsPerPack').value = 1;
    document.getElementById('pfUnitsPerPack').disabled = false;
    document.getElementById('pfUnitType').disabled = false;
    document.getElementById('pfImagePreviewWrap').style.display = 'none';
    document.getElementById('pfRemoveImageWrap').style.display  = 'none';
    document.getElementById('pfValidationError').style.display  = 'none';
}

function _updateCategoryFields(isMed) {
    document.querySelectorAll('.pf-med-field').forEach(el => el.style.display = isMed ? '' : 'none');
    document.querySelectorAll('.pf-nonmed-field').forEach(el => el.style.display = isMed ? 'none' : '');
}

document.getElementById('pfCatMed').addEventListener('change', () => _updateCategoryFields(true));
document.getElementById('pfCatNonMed').addEventListener('change', () => _updateCategoryFields(false));

function previewProductImage(input) {
    const previewWrap = document.getElementById('pfImagePreviewWrap');
    const previewImg  = document.getElementById('pfImagePreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { previewImg.src = e.target.result; previewWrap.style.display = ''; };
        reader.readAsDataURL(input.files[0]);
    }
}

function handleRemoveImage(cb) {
    const previewWrap = document.getElementById('pfImagePreviewWrap');
    const fileInput   = document.getElementById('pfImageFile');
    if (cb.checked) { previewWrap.style.display = 'none'; fileInput.value = ''; }
}

function submitProductForm() {
    const errEl   = document.getElementById('pfValidationError');
    const saveBtn = document.getElementById('pfSaveBtn');
    errEl.style.display = 'none';

    const action      = document.getElementById('pfAction').value;
    const productName = document.getElementById('pfProductName').value.trim();
    const brandName   = document.getElementById('pfBrandName').value.trim();
    const unitType    = document.getElementById('pfUnitType').value;
    const upp         = parseInt(document.getElementById('pfUnitsPerPack').value);
    const isMed       = document.getElementById('pfCatMed').checked;
    const genericName = document.getElementById('pfGenericName').value.trim();
    const dosageForm  = document.getElementById('pfDosageForm').value.trim();

    const errs = [];
    if (!productName)      errs.push('Product name is required.');
    if (!brandName)        errs.push('Brand name is required.');
    if (!unitType)         errs.push('Unit type is required.');
    if (!upp || upp < 1)   errs.push('Units per pack must be at least 1.');
    if (isMed && !genericName) errs.push('Generic name is required for medicines.');
    if (isMed && !dosageForm)  errs.push('Dosage form is required for medicines.');

    if (errs.length > 0) {
        errEl.innerHTML = errs.map(e => '<div>⚠️ ' + escHtml(e) + '</div>').join('');
        errEl.style.display = '';
        return;
    }

    const origHtml = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + (action === 'add' ? 'Adding…' : 'Saving…');

    const fd = new FormData(document.getElementById('productAjaxForm'));
    fd.set('csrf_token', CSRF);

    fetch(BASE + '/ajax/save_product.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                productFormModal.hide();
                showToast('✅ ' + escHtml(data.message), 'success');
                ajaxTableRefresh({ page: 1 });
            } else {
                errEl.innerHTML = '❌ ' + escHtml(data.message || 'Save failed.');
                errEl.style.display = '';
            }
        })
        .catch(err => {
            errEl.innerHTML = '❌ Network error: ' + escHtml(err.message);
            errEl.style.display = '';
        })
        .finally(() => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = origHtml;
        });
}


// ── Export to Excel ──────────────────────────────────────────────────────
function exportToExcel() {
    const checked = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
    const form    = document.getElementById('exportForm');
    form.querySelectorAll('input[name="selected_ids[]"]').forEach(el => el.remove());
    checked.forEach(id => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'selected_ids[]'; inp.value = id;
        form.appendChild(inp);
    });
    const ff = document.getElementById('filterForm');
    ['search','search_type','stock_filter','expiry_filter','sort_by','sort_dir','category','per_page'].forEach(name => {
        form.querySelectorAll('[name="'+name+'"]').forEach(el => el.remove());
        const src = ff.querySelector('[name="'+name+'"]');
        if (src) {
            const val = src.type === 'radio' ? (ff.querySelector('[name="'+name+'"]:checked')?.value ?? '') : src.value;
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = name; inp.value = val;
            form.appendChild(inp);
        }
    });
    form.submit();
}

// ── Toast notifications ──────────────────────────────────────────────────
function showToast(message, type = 'success') {
    const id   = 'toast_' + Date.now();
    const bg   = type === 'success' ? 'bg-success' : (type === 'warning' ? 'bg-warning text-dark' : 'bg-danger');
    const html = `
        <div id="${id}" class="toast align-items-center text-white ${bg} border-0 mb-2" role="alert" style="min-width:280px;">
          <div class="d-flex">
            <div class="toast-body small">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
        </div>`;
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;';
        document.body.appendChild(container);
    }
    container.insertAdjacentHTML('beforeend', html);
    const toastEl = new bootstrap.Toast(document.getElementById(id), { delay: 4000 });
    toastEl.show();
    document.getElementById(id).addEventListener('hidden.bs.toast', () => document.getElementById(id)?.remove());
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Auto-open trash modal if redirected with #openTrash ──────────────────
(function() {
    if (window.location.hash === '#openTrash') {
        window.addEventListener('load', function() {
            setTimeout(function() {
                if (typeof openTrashModal === 'function') {
                    openTrashModal();
                    history.replaceState(null, '', window.location.pathname + window.location.search);
                }
            }, 300);
        });
    }
})();
</script>

<!-- Hidden export form -->
<form id="exportForm" method="POST" action="export_excel.php" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
</form>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>