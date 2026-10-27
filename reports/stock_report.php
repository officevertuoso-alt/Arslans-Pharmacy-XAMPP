<?php
/**
 * reports/stock_report.php
 * Stock Report - Shows only in-stock products (non-expired)
 * With live AJAX search (no page reload)
 * Step 13: Design System Enhanced
 */
require_once '../config/config.php';
require_once '../includes/report_functions.php';

requireLogin();

if (!canDo('reports', 'view')) {
    if (function_exists('setFlash')) setFlash('error', 'You do not have permission to view reports.');
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '../') . 'dashboard.php');
    exit;
}
$page_title = 'Stock Report - Pharmacy Management System';

// Initial load (no search, all categories)
$search   = '';
$category = '';
$products = getInStockProductsReport($search, $category);

$totalProducts   = count($products);
$totalStockValue = 0;
$totalQuantity   = 0;
foreach ($products as $p) {
    $totalQuantity   += $p['current_stock'];
    $totalStockValue += $p['current_stock'] * $p['purchase_price'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    <style>
        @keyframes fadeInPage { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        body { background: var(--pharmacy-bg, #f0f2f5); }
        .main-content { margin-left: var(--sb-w, 260px); padding: 24px; min-height: 100vh; animation: fadeInPage 0.28s ease; transition: margin-left 0.3s ease; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991px) { .main-content { margin-left: 0 !important; } }

        /* Summary Cards */
        .summary-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e9ecef);
            border-radius: var(--card-border-radius, 12px);
            padding: 16px 20px;
            text-align: center;
            border-left: 4px solid;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .summary-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,0.13); }
        .summary-value { font-size: 1.8rem; font-weight: 700; color: var(--pharmacy-heading, #212529); }
        .summary-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 70%, transparent); margin-bottom: 4px; }

        /* Filter bar */
        .filter-bar {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e9ecef);
            border-radius: var(--card-border-radius, 12px);
            padding: 14px 18px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.06);
            margin-bottom: 16px;
        }

        /* Table card */
        .table-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e9ecef);
            border-radius: var(--card-border-radius, 12px);
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
        }
        .table th {
            background: var(--pharmacy-table-th, #2c3e50) !important;
            color: var(--pharmacy-heading, #fff) !important;
            font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700;
            padding: 12px 16px; border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important;
        }
        .table td {
            padding: 10px 16px; vertical-align: middle;
            color: var(--pharmacy-text, #212529) !important;
            border-color: var(--pharmacy-border, #dee2e6) !important;
        }
        .table-hover tbody tr { transition: background 0.15s; }
        .table-hover tbody tr:hover td { background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 6%, var(--pharmacy-card-bg, #fff)) !important; }

        .price-column { font-weight: 600; color: #198754; }
        .serial-no { width: 50px; text-align: center; font-weight: 500; color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 70%, transparent); }
        .product-name-cell { min-width: 200px; }
        .brand-name   { font-size: 0.78rem; color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 75%, transparent); margin-top: 2px; }
        .generic-name { font-size: 0.75rem; color: #2e7d32; margin-top: 2px; }
        .strength-form{ font-size: 0.78rem; color: #1565c0; }

        /* Footer info bar */
        .footer-summary {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 8%, var(--pharmacy-card-bg, #fff));
            border: 1px solid color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 20%, var(--pharmacy-border, #e9ecef));
            border-radius: var(--card-border-radius, 12px);
            padding: 14px 18px;
            font-size: 0.82rem;
            color: var(--pharmacy-text, #495057);
        }

        @media print {
            .main-content { margin-left: 0; padding: 0; }
            .no-print { display: none !important; }
            .table-card { box-shadow: none; border: 1px solid #ddd; }
            .summary-card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<?php $__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : ""; ?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="container-fluid">

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 no-print flex-wrap gap-2">
            <div>
                <h4 class="mb-1 fw-bold" style="color:var(--pharmacy-heading,#212529);">
                    <i class="fas fa-boxes me-2 text-primary"></i>Stock Report
                </h4>
                <p class="text-muted mb-0 small">Current inventory status — only products with available stock</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="#" id="exportPdfLink" target="_blank" class="btn btn-danger btn-sm">
                    <i class="fas fa-file-pdf me-1"></i> PDF
                </a>
                <a href="#" id="exportLink" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i> Excel
                </a>
                <button onclick="window.print()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-print me-1"></i> Print
                </button>
                <a href="index.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Reports
                </a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="summary-card" style="border-left-color:#0d6efd;">
                    <div class="summary-label">Total Products (In Stock)</div>
                    <div class="summary-value text-primary" id="totalProducts"><?= $totalProducts ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="summary-card" style="border-left-color:#198754;">
                    <div class="summary-label">Total Quantity (Pieces)</div>
                    <div class="summary-value text-success" id="totalQuantity"><?= number_format($totalQuantity) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="summary-card" style="border-left-color:#fd7e14;">
                    <div class="summary-label">Total Stock Value</div>
                    <div class="summary-value text-warning" id="totalStockValue">Rs <?= number_format($totalStockValue, 2) ?></div>
                </div>
            </div>
        </div>

        <!-- Live Search Filters -->
        <div class="filter-bar no-print">
            <div id="filterForm" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Search Product</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="searchInput" class="form-control" placeholder="Search by product name (live)..." autocomplete="off">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Category</label>
                    <select id="categorySelect" class="form-select">
                        <option value="">All Categories</option>
                        <option value="medicine">Medicine</option>
                        <option value="non_medicine">Non-Medicine</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button id="resetBtn" class="btn btn-secondary w-100">
                        <i class="fas fa-undo-alt me-2"></i>Reset
                    </button>
                </div>
            </div>
        </div>

        <!-- Stock Table -->
        <div class="table-card mb-4">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="reportTable">
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th>Product Name</th>
                            <th>Strength / Size</th>
                            <th class="text-end">Quantity (Pieces)</th>
                            <th class="text-end">Purchase Price (Rs)</th>
                            <th class="text-end">Stock Value (Rs)</th>
                        </tr>
                    </thead>
                    <tbody id="stockTableBody">
                        <?php if (empty($products)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">No products found</td></tr>
                        <?php else:
                            $serial = 1;
                            foreach ($products as $p):
                                $currentStock  = (int)$p['current_stock'];
                                $purchasePrice = (float)$p['purchase_price'];
                                $stockValue    = $currentStock * $purchasePrice;
                        ?>
                        <tr>
                            <td class="serial-no"><?= $serial++ ?>.</td>
                            <td class="product-name-cell">
                                <strong><?= htmlspecialchars($p['product_name']) ?></strong>
                                <?php if (!empty($p['brand_name'])): ?>
                                    <div class="brand-name"><i class="fas fa-tag fa-xs me-1"></i><?= htmlspecialchars($p['brand_name']) ?></div>
                                <?php endif; ?>
                                <?php if ($p['category_id'] == 1 && !empty($p['generic_name'])): ?>
                                    <div class="generic-name"><i class="fas fa-pills fa-xs me-1"></i><?= htmlspecialchars($p['generic_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                if ((int)$p['category_id'] === 1) {
                                    $strength = trim(($p['strength'] ?? '') . ' ' . ($p['strength_unit'] ?? ''));
                                    echo !empty($strength) ? '<strong>' . htmlspecialchars($strength) . '</strong>' : '-';
                                    if (!empty($p['dosage_form'])) echo '<div class="strength-form"><i class="fas fa-capsules fa-xs me-1"></i>' . htmlspecialchars($p['dosage_form']) . '</div>';
                                } else {
                                    $size = trim(($p['strength'] ?? '') . ' ' . ($p['strength_unit'] ?? ''));
                                    echo !empty($size) ? '<strong>' . htmlspecialchars($size) . '</strong>' : '-';
                                    if (!empty($p['size_value'])) echo '<div class="strength-form"><i class="fas fa-arrows-alt fa-xs me-1"></i>' . htmlspecialchars($p['size_value']) . '</div>';
                                }
                                ?>
                            </td>
                            <td class="text-end fw-bold"><?= number_format($currentStock) ?></td>
                            <td class="text-end price-column">Rs <?= number_format($purchasePrice, 2) ?></td>
                            <td class="text-end price-column">Rs <?= number_format($stockValue, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-dark fw-bold">
                            <td colspan="3" class="text-end fs-6">TOTAL</td>
                            <td class="text-end fs-6"><?= number_format($totalQuantity) ?></td>
                            <td class="text-end fs-6"></td>
                            <td class="text-end fs-6 text-warning">Rs <?= number_format($totalStockValue, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer Summary -->
        <div class="footer-summary no-print" id="footerSummary">
            <i class="fas fa-info-circle me-2 text-primary"></i>
            <strong>Stock Summary</strong><br>
            Total Products: <strong><?= $totalProducts ?></strong> |
            Total Quantity: <strong><?= number_format($totalQuantity) ?></strong> pieces |
            Total Stock Value: <strong>Rs <?= number_format($totalStockValue, 2) ?></strong>
        </div>

    </div>
</div>



<script>
$(document).ready(function () {
    let searchTimeout;

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m]));
    }

    function fetchStockData() {
        const search   = $('#searchInput').val();
        const category = $('#categorySelect').val();

        $('#exportLink').attr('href', 'export_excel.php?report=stock&search=' + encodeURIComponent(search) + '&category=' + encodeURIComponent(category));
        $('#exportPdfLink').attr('href', 'export_pdf.php?report=stock&search=' + encodeURIComponent(search) + '&category=' + encodeURIComponent(category));

        $.ajax({
            url: 'ajax_stock_search.php',
            type: 'GET',
            data: { search, category },
            dataType: 'json',
            timeout: 10000,
            success: function (response) {
                if (!response.success) {
                    $('#stockTableBody').html('<tr><td colspan="6" class="text-center text-danger py-4">' + (response.error || 'Unknown error') + '</td></tr>');
                    return;
                }
                $('#totalProducts').text(response.totalProducts);
                $('#totalQuantity').text(new Intl.NumberFormat().format(response.totalQuantity));
                $('#totalStockValue').html('Rs ' + new Intl.NumberFormat().format(response.totalStockValue));
                $('#stockTableBody').html(response.tableHtml);

                let footer = `Total Products: <strong>${response.totalProducts}</strong> | Total Quantity: <strong>${new Intl.NumberFormat().format(response.totalQuantity)}</strong> pieces | Total Stock Value: <strong>Rs ${new Intl.NumberFormat().format(response.totalStockValue)}</strong>`;
                if (search) footer += ` <br><em>Filtered by: "${escapeHtml(search)}"</em>`;
                if (category === 'medicine') footer += ` | Category: <strong>Medicine</strong>`;
                else if (category === 'non_medicine') footer += ` | Category: <strong>Non-Medicine</strong>`;
                $('#footerSummary').html('<i class="fas fa-info-circle me-2 text-primary"></i><strong>Stock Summary</strong><br>' + footer);
            },
            error: function (xhr, status, error) {
                console.error("AJAX error:", status, error);
                $('#stockTableBody').html('<tr><td colspan="6" class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle me-2"></i>Error loading data. Please try again.</td></tr>');
            }
        });
    }

    $('#searchInput').on('input', function () {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(fetchStockData, 300);
    });
    $('#categorySelect').on('change', fetchStockData);
    $('#resetBtn').on('click', function () {
        $('#searchInput').val('');
        $('#categorySelect').val('');
        fetchStockData();
    });
});

// ── Step 7B: applyReportFilters bridge ────────────────────────────────────────
let reportFilterTimer = null;
function applyReportFilters() {
    clearTimeout(reportFilterTimer);
    reportFilterTimer = setTimeout(() => {
        document.getElementById('reportTable').style.opacity = '0.5';
        const event = new Event('input', { bubbles: true });
        const searchInput = document.getElementById('searchInput');
        if (searchInput) searchInput.dispatchEvent(event);
        setTimeout(() => { document.getElementById('reportTable').style.opacity = '1'; }, 600);
    }, 400);
}
function updateReportStats(stats) {
    if (stats.totalProducts !== undefined)   { const el = document.getElementById('totalProducts');   if (el) el.textContent = stats.totalProducts; }
    if (stats.totalQuantity !== undefined)   { const el = document.getElementById('totalQuantity');   if (el) el.textContent = new Intl.NumberFormat().format(stats.totalQuantity); }
    if (stats.totalStockValue !== undefined) { const el = document.getElementById('totalStockValue'); if (el) el.innerHTML = 'Rs ' + new Intl.NumberFormat().format(stats.totalStockValue); }
}
// id alias: reportTableBody → stockTableBody
document.getElementById('stockTableBody') && Object.defineProperty(document, 'reportTableBody', { get: () => document.getElementById('stockTableBody') });
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('reportTable')) {
        UniversalTable.init('reportTable', {
            title      : 'Stock Report',
            pageSize   : 25,
            excludeCols: []
        });
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
