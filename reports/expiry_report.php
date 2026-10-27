<?php
/**
 * reports/expiry_report.php
 * Expiry Report — All Expired Batches History + Current Expired Stock
 * Step 13: Design System Enhanced — color-coded rows, table-card, filter-pill
 */
require_once '../config/config.php';
require_once '../includes/report_functions.php';
requireLogin();

if (!canDo('reports', 'view')) {
    if (function_exists('setFlash')) setFlash('error', 'You do not have permission to view reports.');
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '../') . 'dashboard.php');
    exit;
}
// ── AJAX: Log expired stock loss to cashbook ──────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'log_to_cashbook') {
    header('Content-Type: application/json');
    try {
        $db = db();
        $today = date('Y-m-d');
        $db->query("SELECT b.remaining_quantity, b.purchase_price, p.product_name, b.batch_number
                    FROM batches b JOIN products p ON b.product_id = p.id
                    WHERE b.expiry_date <= CURDATE() AND b.remaining_quantity > 0");
        $expiredItems = $db->resultSet();
        if (empty($expiredItems)) { echo json_encode(['success' => false, 'message' => 'Koi bhi expired product with remaining stock nahi mila.']); exit; }
        $totalLoss = 0;
        foreach ($expiredItems as $item) { $totalLoss += (int)$item['remaining_quantity'] * (float)$item['purchase_price']; }
        if ($totalLoss <= 0) { echo json_encode(['success' => false, 'message' => 'Expired stock loss zero hai — cashbook entry nahi bani.']); exit; }
        $existingEntry = $db->getOne("SELECT id FROM cashbook WHERE entry_type = 'expired_loss' AND DATE(entry_date) = :today LIMIT 1", [':today' => $today]);
        if ($existingEntry) { echo json_encode(['success' => false, 'message' => 'Aaj ki expired loss entry cashbook mein already mauood hai (ID #' . $existingEntry['id'] . '). Duplicate entry nahi hogi.', 'duplicate' => true]); exit; }
        $description = 'Expired Stock Loss — ' . count($expiredItems) . ' batch(es), Total Qty: ' . array_sum(array_column($expiredItems, 'remaining_quantity')) . ' pcs';
        $db->query("INSERT INTO cashbook (entry_date, entry_type, reference_type, description, debit, credit, payment_method, created_by, created_at) VALUES (:entry_date, 'expired_loss', 'expired_stock', :description, :debit, 0, 'adjustment', :created_by, NOW())");
        $db->bind(':entry_date', $today); $db->bind(':description', $description);
        $db->bind(':debit', $totalLoss); $db->bind(':created_by', $_SESSION['user_id'] ?? 1);
        $db->execute();
        $insertedId = $db->lastInsertId();
        if (function_exists('logActivity')) logActivity('Expired Loss Logged', 'Expired stock loss of Rs ' . number_format($totalLoss, 2) . ' recorded in cashbook. Entry ID: ' . $insertedId);
        echo json_encode(['success' => true, 'message' => 'Cashbook mein expired loss entry successfully add ho gayi! (Rs ' . number_format($totalLoss, 2) . ')', 'amount' => $totalLoss, 'entry_id' => $insertedId]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

$page_title = 'Expired Products Report - Pharmacy Management System';
$filter  = isset($_GET['filter'])  ? trim($_GET['filter'])      : 'all';
$search  = isset($_GET['search'])  ? sanitize($_GET['search'])  : '';
if (!in_array($filter, ['all', 'current'])) $filter = 'all';

$db = db();

$sql = "SELECT b.id, b.batch_number, b.remaining_quantity, b.expiry_date, b.purchase_price,
               p.product_name, p.generic_name, p.brand_name, p.category_id, p.strength,
               p.strength_unit, p.dosage_form, p.size_value, s.supplier_name,
               DATEDIFF(CURDATE(), b.expiry_date) AS days_expired
        FROM batches b
        JOIN products p ON b.product_id = p.id
        LEFT JOIN suppliers s ON b.supplier_id = s.id
        WHERE b.expiry_date IS NOT NULL AND b.expiry_date <= CURDATE()";
if ($filter === 'current') $sql .= " AND b.remaining_quantity > 0";
$params = [];
if (!empty($search)) { $sql .= " AND (p.product_name LIKE :search OR b.batch_number LIKE :search)"; $params[':search'] = "%$search%"; }
$sql .= " ORDER BY b.expiry_date ASC";
$db->query($sql);
foreach ($params as $key => $val) $db->bind($key, $val);
$expiredProducts = $db->resultSet();
$totalProducts   = count($expiredProducts);
$totalQuantity   = 0;
$totalStockValue = 0;
foreach ($expiredProducts as $p) {
    $totalQuantity   += (int)$p['remaining_quantity'];
    $totalStockValue += (int)$p['remaining_quantity'] * (float)$p['purchase_price'];
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
        @media (max-width: 768px) { .main-content { margin-left: 0; } }

        /* Summary Cards */
        .summary-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e9ecef);
            border-radius: var(--card-border-radius, 12px);
            padding: 16px 20px; text-align: center;
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
            padding: 16px 18px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.06);
            margin-bottom: 16px;
        }

        /* Filter pills */
        .filter-pill {
            padding: 6px 16px; border-radius: 20px; font-size: 0.8rem; font-weight: 600;
            border: 1px solid var(--pharmacy-border, #e9ecef);
            background: var(--pharmacy-card-bg, #fff);
            color: var(--pharmacy-text, #495057);
            cursor: pointer; transition: all 0.15s; display: inline-block;
        }
        .filter-pill.active, .filter-pill:hover { background: #dc3545; color: #fff; border-color: #dc3545; }

        /* Table card */
        .table-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e9ecef);
            border-radius: var(--card-border-radius, 12px);
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            position: relative;
        }
        .table-header { background: var(--pharmacy-table-th, #2c3e50) !important; }
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
        .table-hover tbody tr:hover td { background: color-mix(in srgb, #dc3545 6%, var(--pharmacy-card-bg, #fff)) !important; }

        /* Color-coded expired rows */
        .expired-critical { background-color: rgba(220, 53, 69, 0.10) !important; }
        .expired-old      { background-color: rgba(220, 53, 69, 0.05) !important; }
        .expired-cleared  { background-color: rgba(108, 117, 125, 0.06) !important; opacity: 0.85; }

        .price-column { font-weight: 600; color: #dc3545; }
        .serial-no { width: 50px; text-align: center; }
        .product-name-cell { max-width: 220px; }
        .brand-name   { font-size: 0.78rem; color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 75%, transparent); }
        .generic-name { font-size: 0.75rem; color: #6c757d; }
        .strength-form{ font-size: 0.78rem; color: #6c757d; }

        /* Loading overlay */
        #tableLoadingOverlay {
            display: none; position: absolute; inset: 0;
            background: rgba(255,255,255,.65); z-index: 10;
            align-items: center; justify-content: center;
        }

        /* Footer info */
        .footer-summary {
            background: color-mix(in srgb, #dc3545 6%, var(--pharmacy-card-bg, #fff));
            border: 1px solid color-mix(in srgb, #dc3545 20%, var(--pharmacy-border, #e9ecef));
            border-radius: var(--card-border-radius, 12px);
            padding: 14px 18px; font-size: 0.82rem;
            color: var(--pharmacy-text, #495057);
        }

        @media print {
            .no-print { display: none !important; }
            .main-content { margin-left: 0; }
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
                    <i class="fas fa-calendar-times me-2 text-danger"></i>Expired Products Report
                </h4>
                <p class="text-muted mb-0 small">Complete record of all expired batches — full history retained</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button id="logCashbookBtn" class="btn btn-danger btn-sm"
                        title="Record expired stock loss in cashbook"
                        <?= ($totalStockValue <= 0) ? 'disabled' : '' ?>>
                    <i class="fas fa-book me-1"></i>Log Loss to Cashbook
                    <?php if ($totalStockValue > 0): ?>
                        <span class="badge bg-white text-danger ms-1">Rs <?= number_format($totalStockValue, 0) ?></span>
                    <?php endif; ?>
                </button>
                <a href="export_pdf.php?report=expiry&filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>"
                   class="btn btn-danger btn-sm" id="exportPdfBtn" target="_blank">
                    <i class="fas fa-file-pdf me-1"></i> PDF
                </a>
                <a href="export_excel.php?report=expired&filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>"
                   class="btn btn-success btn-sm" id="exportBtn">
                    <i class="fas fa-file-excel me-1"></i> Excel
                </a>
                <button onclick="window.print()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-print me-1"></i>Print
                </button>
                <a href="index.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Reports
                </a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="summary-card" style="border-left-color:#dc3545;">
                    <div class="summary-label">Expired Batches</div>
                    <div class="summary-value text-danger" id="totalBatches"><?= $totalProducts ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="summary-card" style="border-left-color:#fd7e14;">
                    <div class="summary-label">Total Quantity (Remaining)</div>
                    <div class="summary-value text-warning" id="totalQuantity"><?= number_format($totalQuantity) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="summary-card" style="border-left-color:#6c757d;">
                    <div class="summary-label">Total Stock Value</div>
                    <div class="summary-value text-secondary" id="totalValue">Rs <?= number_format($totalStockValue, 2) ?></div>
                </div>
            </div>
        </div>

        <!-- Filters & Search -->
        <div class="filter-bar no-print">
            <div id="filterForm" class="row g-3 align-items-end">
                <!-- Filter Pills -->
                <div class="col-md-3">
                    <label class="form-label small fw-bold mb-2">View Mode</label>
                    <div class="d-flex gap-2">
                        <button class="filter-pill <?= ($filter !== 'current') ? 'active' : '' ?>" id="filterAll">
                            <i class="fas fa-history me-1"></i>All Expired
                        </button>
                        <button class="filter-pill <?= ($filter === 'current') ? 'active' : '' ?>" id="filterCurrent">
                            <i class="fas fa-exclamation-circle me-1"></i>Current
                        </button>
                    </div>
                    <div class="form-text mt-1" style="font-size:0.75rem;">
                        <?php if ($filter === 'current'): ?>
                            <i class="fas fa-info-circle me-1 text-danger"></i>Showing batches still in expired stock
                        <?php else: ?>
                            <i class="fas fa-info-circle me-1 text-muted"></i>Showing complete expired batch history
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Search -->
                <div class="col-md-7">
                    <label class="form-label small fw-bold">Search</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="searchInput" class="form-control"
                               placeholder="Search by product name or batch number..."
                               value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                        <span class="input-group-text text-muted" id="searchSpinner" style="display:none;">
                            <i class="fas fa-spinner fa-spin"></i>
                        </span>
                    </div>
                </div>
                <!-- Reset -->
                <div class="col-md-2">
                    <button id="resetBtn" class="btn btn-secondary w-100">
                        <i class="fas fa-undo-alt me-1"></i>Reset
                    </button>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="table-card mb-4">
            <div id="tableLoadingOverlay">
                <div class="text-center"><i class="fas fa-spinner fa-spin fa-2x text-danger"></i></div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="reportTable">
                    <thead class="table-header">
                        <tr>
                            <th style="width:50px;">#</th>
                            <th>Batch No.</th>
                            <th>Product Name</th>
                            <th>Strength / Size</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Purchase Price</th>
                            <th class="text-end">Stock Value</th>
                            <th>Expiry Date</th>
                            <th class="text-end">Days Expired</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody" data-report-tbody="reportTableBody">
                        <?php
                        if (empty($expiredProducts)) {
                            echo '<tr><td colspan="9" class="text-center py-5 text-muted">'
                               . '<i class="fas fa-check-circle me-2 text-success"></i>No expired products found</td></tr>';
                        } else {
                            $serial = 1;
                            foreach ($expiredProducts as $p) {
                                $qty   = (int)$p['remaining_quantity'];
                                $price = (float)$p['purchase_price'];
                                $value = $qty * $price;
                                $days  = (int)$p['days_expired'];
                                $expiry_ts = !empty($p['expiry_date']) ? strtotime($p['expiry_date']) : false;
                                $expiry_formatted = ($expiry_ts && $expiry_ts > 0) ? date('d-m-Y', $expiry_ts) : '<span class="text-muted fst-italic">N/A</span>';

                                // Color-coded row class based on days and qty
                                if ($qty === 0) $rowClass = 'expired-cleared';
                                elseif ($days <= 30) $rowClass = 'expired-critical';
                                else $rowClass = 'expired-old';

                                // Days label
                                if (!$expiry_ts || $expiry_ts <= 0) $days_label = '<span class="text-muted fst-italic">N/A</span>';
                                elseif ($days === 0) $days_label = '<span class="badge bg-danger">Today</span>';
                                elseif ($days === 1) $days_label = '1 day ago';
                                else $days_label = $days . ' days ago';

                                $qty_display = ($qty === 0) ? '<span class="badge bg-secondary">0 (Cleared)</span>' : number_format($qty);

                                // Strength/Size HTML
                                $strength_html = '';
                                if ((int)$p['category_id'] === 1) {
                                    $str = trim(($p['strength'] ?? '') . ' ' . ($p['strength_unit'] ?? ''));
                                    $strength_html = !empty($str) ? '<strong>' . htmlspecialchars($str) . '</strong>' : '-';
                                    if (!empty($p['dosage_form'])) $strength_html .= '<div class="strength-form"><i class="fas fa-capsules fa-xs me-1"></i>' . htmlspecialchars($p['dosage_form']) . '</div>';
                                } else {
                                    $str = trim(($p['strength'] ?? '') . ' ' . ($p['strength_unit'] ?? ''));
                                    $strength_html = !empty($str) ? '<strong>' . htmlspecialchars($str) . '</strong>' : '-';
                                    if (!empty($p['size_value'])) $strength_html .= '<div class="strength-form"><i class="fas fa-arrows-alt fa-xs me-1"></i>' . htmlspecialchars($p['size_value']) . '</div>';
                                }
                                ?>
                                <tr class="<?= $rowClass ?>">
                                    <td class="serial-no"><?= $serial++ ?>.</td>
                                    <td>
                                        <strong><?= htmlspecialchars($p['batch_number']) ?></strong>
                                        <?php if (!empty($p['supplier_name'])): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($p['supplier_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="product-name-cell">
                                        <strong><?= htmlspecialchars($p['product_name']) ?></strong>
                                        <?php if (!empty($p['brand_name'])): ?>
                                            <div class="brand-name"><i class="fas fa-tag fa-xs me-1"></i><?= htmlspecialchars($p['brand_name']) ?></div>
                                        <?php endif; ?>
                                        <?php if ((int)$p['category_id'] === 1 && !empty($p['generic_name'])): ?>
                                            <div class="generic-name"><i class="fas fa-pills fa-xs me-1"></i><?= htmlspecialchars($p['generic_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $strength_html ?></td>
                                    <td class="text-end fw-bold"><?= $qty_display ?></td>
                                    <td class="text-end price-column">Rs <?= number_format($price, 2) ?></td>
                                    <td class="text-end price-column">Rs <?= number_format($value, 2) ?></td>
                                    <td class="text-danger fw-semibold"><?= $expiry_formatted ?></td>
                                    <td class="text-end fw-bold text-danger"><?= $days_label ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                            <tr class="table-dark fw-bold">
                                <td colspan="4" class="text-end fs-6">TOTAL</td>
                                <td class="text-end fs-6"><?= number_format($totalQuantity) ?></td>
                                <td class="text-end fs-6"></td>
                                <td class="text-end fs-6 text-danger">Rs <?= number_format($totalStockValue, 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Legend + Footer -->
        <div class="d-flex gap-3 flex-wrap no-print mb-3">
            <small><span style="display:inline-block;width:12px;height:12px;background:rgba(220,53,69,0.15);border-radius:3px;border:1px solid #dc3545;"></span> Expired &lt;30 days ago</small>
            <small><span style="display:inline-block;width:12px;height:12px;background:rgba(220,53,69,0.06);border-radius:3px;border:1px solid #dee2e6;"></span> Expired &gt;30 days ago</small>
            <small><span style="display:inline-block;width:12px;height:12px;background:rgba(108,117,125,0.1);border-radius:3px;border:1px solid #dee2e6;"></span> Cleared (qty = 0)</small>
        </div>

        <div class="footer-summary no-print">
            <i class="fas fa-info-circle me-2 text-danger"></i>
            <strong>Expired Stock Summary</strong><br>
            Total Batches: <strong><?= $totalProducts ?></strong> |
            Remaining Quantity: <strong><?= number_format($totalQuantity) ?></strong> pieces |
            Total Stock Value Loss: <strong>Rs <?= number_format($totalStockValue, 2) ?></strong>
        </div>

    </div>
</div>



<script>
$(function () {
    let searchTimeout;
    let currentFilter = '<?= addslashes($filter) ?>';

    function updateHintText() {
        const hint = currentFilter === 'current'
            ? '<i class="fas fa-info-circle me-1 text-danger"></i>Showing batches still in expired stock'
            : '<i class="fas fa-info-circle me-1 text-muted"></i>Showing complete expired batch history';
        $('.filter-bar .form-text').html(hint);
    }

    function fetchData() {
        const search = $('#searchInput').val().trim();
        $('#exportBtn').attr('href', 'export_excel.php?report=expired&filter=' + encodeURIComponent(currentFilter) + '&search=' + encodeURIComponent(search));
        $('#exportPdfBtn').attr('href', 'export_pdf.php?report=expiry&filter=' + encodeURIComponent(currentFilter) + '&search=' + encodeURIComponent(search));
        $('#searchSpinner').show();
        $('#tableLoadingOverlay').css('display', 'flex');

        $.ajax({
            url: 'ajax_expiry_search.php',
            type: 'GET',
            data: { search, filter: currentFilter },
            dataType: 'json',
            timeout: 15000,
            success: function (res) {
                $('#searchSpinner').hide();
                $('#tableLoadingOverlay').hide();
                if (res.success) {
                    $('#tableBody').html(res.tableHtml);
                    $('#totalBatches').text(res.totalProducts);
                    $('#totalQuantity').text(new Intl.NumberFormat('en-PK').format(res.totalQuantity));
                    $('#totalValue').text('Rs ' + new Intl.NumberFormat('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(res.totalStockValue));
                } else {
                    $('#tableBody').html('<tr><td colspan="9" class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle me-2"></i>Error: ' + (res.error || 'Unknown error') + '</td></tr>');
                }
            },
            error: function () {
                $('#searchSpinner').hide();
                $('#tableLoadingOverlay').hide();
                $('#tableBody').html('<tr><td colspan="9" class="text-center text-danger py-4"><i class="fas fa-wifi me-2"></i>Connection error. Please try again.</td></tr>');
            }
        });
    }

    $('#searchInput').on('input', function () { clearTimeout(searchTimeout); searchTimeout = setTimeout(fetchData, 400); });

    $('#filterAll').on('click', function () {
        currentFilter = 'all';
        $('#filterAll').addClass('active'); $('#filterCurrent').removeClass('active');
        updateHintText(); updateUrl(); fetchData();
    });
    $('#filterCurrent').on('click', function () {
        currentFilter = 'current';
        $('#filterCurrent').addClass('active'); $('#filterAll').removeClass('active');
        updateHintText(); updateUrl(); fetchData();
    });
    $('#resetBtn').on('click', function () {
        $('#searchInput').val('');
        currentFilter = 'all';
        $('#filterAll').addClass('active'); $('#filterCurrent').removeClass('active');
        updateHintText(); updateUrl(); fetchData();
    });

    function updateUrl() {
        const newUrl = '?filter=' + encodeURIComponent(currentFilter) + '&search=' + encodeURIComponent($('#searchInput').val().trim());
        window.history.replaceState({}, '', newUrl);
    }

    fetchData();

    // Log expired loss to cashbook
    $('#logCashbookBtn').on('click', function () {
        const totalVal = '<?= number_format($totalStockValue, 2) ?>';
        if (!confirm('Kya aap expired stock loss ko cashbook mein record karna chahte hain?\n\nYeh Rs ' + totalVal + ' ki debit entry add karega.\nNote: Agar aaj ki entry pehle se exist karti hai to duplicate nahi banega.')) return;
        const $btn = $(this);
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Recording...');
        $.ajax({
            url: window.location.pathname, type: 'POST',
            data: { ajax_action: 'log_to_cashbook' }, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    $('<div class="alert alert-success alert-dismissible fade show position-fixed" style="bottom:24px;right:24px;z-index:9999;min-width:320px;" role="alert"><i class="fas fa-check-circle me-2"></i><strong>Cashbook mein entry ho gayi!</strong><br><small>' + res.message + '</small><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>').appendTo('body');
                    $btn.html('<i class="fas fa-check me-2"></i>Logged to Cashbook').removeClass('btn-danger').addClass('btn-success');
                    setTimeout(() => $btn.html('<i class="fas fa-book me-2"></i>Log Loss to Cashbook').removeClass('btn-success').addClass('btn-danger').prop('disabled', false), 4000);
                } else if (res.duplicate) {
                    $('<div class="alert alert-warning alert-dismissible fade show position-fixed" style="bottom:24px;right:24px;z-index:9999;min-width:320px;" role="alert"><i class="fas fa-exclamation-triangle me-2"></i>' + res.message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>').appendTo('body');
                    $btn.prop('disabled', false).html('<i class="fas fa-book me-2"></i>Log Loss to Cashbook');
                } else {
                    Notify.error('Error: ' + (res.message || 'Unknown error'));
                    $btn.prop('disabled', false).html('<i class="fas fa-book me-2"></i>Log Loss to Cashbook');
                }
            },
            error: function () { Notify.error('Server error. Please try again.'); $btn.prop('disabled', false).html('<i class="fas fa-book me-2"></i>Log Loss to Cashbook'); }
        });
    });
});

// ── Step 7B: applyReportFilters bridge (delegates to existing fetchData via jQuery) ──
let reportFilterTimer = null;
function applyReportFilters() {
    clearTimeout(reportFilterTimer);
    reportFilterTimer = setTimeout(() => {
        // Trigger the existing search handler
        document.getElementById('reportTable').style.opacity = '0.5';
        const event = new Event('input', { bubbles: true });
        const searchInput = document.getElementById('searchInput');
        if (searchInput) searchInput.dispatchEvent(event);
        // Opacity restored by fetchData success/error callbacks
        setTimeout(() => { document.getElementById('reportTable').style.opacity = '1'; }, 600);
    }, 400);
}
function updateReportStats(stats) {
    if (stats.totalProducts !== undefined)  document.getElementById('totalBatches') && (document.getElementById('totalBatches').textContent = stats.totalProducts);
    if (stats.totalQuantity !== undefined)  document.getElementById('totalQuantity') && (document.getElementById('totalQuantity').textContent = new Intl.NumberFormat('en-PK').format(stats.totalQuantity));
    if (stats.totalStockValue !== undefined) document.getElementById('totalValue') && (document.getElementById('totalValue').textContent = 'Rs ' + new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(stats.totalStockValue));
}
// Alias reportTableBody → tableBody for cross-report compatibility
Object.defineProperty(document, 'reportTableBody', { get: () => document.getElementById('tableBody') });
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('reportTable')) {
        UniversalTable.init('reportTable', {
            title      : 'Expiry Report',
            pageSize   : 25,
            excludeCols: []
        });
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
