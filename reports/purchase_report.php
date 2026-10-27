<?php
/**
 * reports/purchase_report.php
 * Purchase Report – Enhanced with Returns, Cashbook Sync, Net Purchase Value
 * Step 13: Design System Enhanced
 */
require_once '../config/config.php';
require_once '../includes/sync_engine.php';

requireLogin();

if (!canDo('reports', 'view')) {
    if (function_exists('setFlash')) setFlash('error', 'You do not have permission to view reports.');
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '../') . 'dashboard.php');
    exit;
}
// ── Step 7A: AJAX filter handler ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'filter') {
    $from_date = $_GET['from_date'] ?? date('Y-m-01');
    $to_date   = $_GET['to_date']   ?? date('Y-m-d');
    $supplier_id    = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
    $payment_status = $_GET['payment_status'] ?? '';
    if (!strtotime($from_date)) $from_date = date('Y-m-01');
    if (!strtotime($to_date))   $to_date   = date('Y-m-d');
    $db = db();
    $where  = "DATE(p.purchase_date) BETWEEN :from AND :to";
    $params = [':from' => $from_date, ':to' => $to_date];
    if ($supplier_id > 0)       { $where .= " AND p.supplier_id = :sid"; $params[':sid'] = $supplier_id; }
    if ($payment_status !== '') { $where .= " AND p.payment_status = :ps"; $params[':ps'] = $payment_status; }
    $purchases = $db->getAll(
        "SELECT p.*, s.supplier_name, s.phone AS supplier_phone,
                COALESCE(SUM(pr.total_amount), 0) AS total_returned,
                (p.total - COALESCE(SUM(pr.total_amount), 0)) AS net_purchase
         FROM purchases p
         JOIN suppliers s ON s.id = p.supplier_id
         LEFT JOIN purchase_returns pr ON pr.purchase_id = p.id
         WHERE $where
         GROUP BY p.id
         ORDER BY p.purchase_date DESC, p.id DESC",
        $params
    );
    $totals = $db->getOne(
        "SELECT COALESCE(SUM(p.total), 0) AS total_amount, COALESCE(SUM(p.paid_amount), 0) AS total_paid,
                COALESCE(SUM(p.due_amount), 0) AS total_due, COALESCE(SUM(pr.total_amount), 0) AS total_returns,
                COALESCE(SUM(p.total - COALESCE(pr.total_amount,0)), 0) AS net_purchase_value
         FROM purchases p LEFT JOIN purchase_returns pr ON pr.purchase_id = p.id WHERE $where",
        $params
    );
    if (!function_exists('formatCurrency')) { function formatCurrency($a) { return 'Rs ' . number_format((float)$a, 2); } }
    if (!function_exists('formatDate'))     { function formatDate($d) { return empty($d) ? '' : date('d-m-Y', strtotime($d)); } }
    ob_start();
    if (empty($purchases)) {
        echo '<tr><td colspan="10" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>No records found for selected period</td></tr>';
    } else {
        $sn = 1;
        foreach ($purchases as $p) {
            echo '<tr>';
            echo '<td class="serial-no">' . $sn++ . '</td>';
            echo '<td class="fw-semibold">' . htmlspecialchars($p['purchase_invoice_no']) . '</td>';
            echo '<td><small class="text-muted">' . formatDate($p['purchase_date']) . '</small></td>';
            echo '<td><span class="fw-semibold">' . htmlspecialchars($p['supplier_name']) . '</span>';
            if ($p['supplier_phone']) echo '<br><small class="text-muted">' . $p['supplier_phone'] . '</small>';
            echo '</td>';
            echo '<td class="text-end fw-semibold">' . formatCurrency($p['total']) . '</td>';
            echo '<td class="text-end text-success">' . formatCurrency($p['paid_amount']) . '</td>';
            echo '<td class="text-end text-danger">' . formatCurrency($p['due_amount']) . '</td>';
            echo '<td class="text-end text-warning">' . formatCurrency($p['total_returned']) . '</td>';
            echo '<td class="text-end">' . formatCurrency($p['net_purchase']) . '</td>';
            echo '<td><span class="status-badge status-' . $p['payment_status'] . '">' . ucfirst($p['payment_status']) . '</span></td>';
            echo '</tr>';
        }
        echo '<tr class="table-dark fw-bold" style="border-top:2px solid var(--pharmacy-border,#dee2e6);">';
        echo '<td colspan="4" class="text-end fs-6">TOTAL</td>';
        echo '<td class="text-end fs-6">' . formatCurrency($totals['total_amount']) . '</td>';
        echo '<td class="text-end fs-6 text-success">' . formatCurrency($totals['total_paid']) . '</td>';
        echo '<td class="text-end fs-6 text-danger">' . formatCurrency($totals['total_due']) . '</td>';
        echo '<td class="text-end fs-6 text-warning">' . formatCurrency($totals['total_returns']) . '</td>';
        echo '<td class="text-end fs-6">' . formatCurrency($totals['net_purchase_value']) . '</td>';
        echo '<td></td></tr>';
    }
    $tableHtml = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html'    => $tableHtml,
        'total'   => count($purchases),
        'stats'   => [
            'total_amount'       => formatCurrency($totals['total_amount']),
            'total_paid'         => formatCurrency($totals['total_paid']),
            'total_due'          => formatCurrency($totals['total_due']),
            'total_returns'      => formatCurrency($totals['total_returns']),
            'net_purchase_value' => formatCurrency($totals['net_purchase_value']),
        ],
    ]);
    exit;
}

// Filters
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date   = $_GET['to_date']   ?? date('Y-m-d');
$supplier_id    = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$payment_status = $_GET['payment_status'] ?? '';

if (!strtotime($from_date)) $from_date = date('Y-m-01');
if (!strtotime($to_date))   $to_date   = date('Y-m-d');

$db = db();

// Suppliers dropdown
$suppliers = $db->getAll("SELECT id, supplier_name FROM suppliers WHERE status = 1 ORDER BY supplier_name");

// WHERE clause
$where  = "DATE(p.purchase_date) BETWEEN :from AND :to";
$params = [':from' => $from_date, ':to' => $to_date];
if ($supplier_id > 0)   { $where .= " AND p.supplier_id = :sid"; $params[':sid'] = $supplier_id; }
if ($payment_status !== '') { $where .= " AND p.payment_status = :ps"; $params[':ps'] = $payment_status; }

// Main purchases query with return totals and cashbook sync flag
$purchases = $db->getAll(
    "SELECT p.*, s.supplier_name, s.phone AS supplier_phone,
            COALESCE(SUM(pr.total_amount), 0) AS total_returned,
            (p.total - COALESCE(SUM(pr.total_amount), 0)) AS net_purchase,
            (SELECT COUNT(*) FROM cashbook WHERE reference_id = p.id AND reference_type = 'purchase' AND entry_type = 'purchase_payment') AS has_cashbook_entry
     FROM purchases p
     JOIN suppliers s ON s.id = p.supplier_id
     LEFT JOIN purchase_returns pr ON pr.purchase_id = p.id
     WHERE $where
     GROUP BY p.id
     ORDER BY p.purchase_date DESC, p.id DESC",
    $params
);

// Totals
$totals = $db->getOne(
    "SELECT
        COALESCE(SUM(p.total), 0) AS total_amount,
        COALESCE(SUM(p.paid_amount), 0) AS total_paid,
        COALESCE(SUM(p.due_amount), 0) AS total_due,
        COALESCE(SUM(pr.total_amount), 0) AS total_returns,
        COALESCE(SUM(p.total - COALESCE(pr.total_amount,0)), 0) AS net_purchase_value
     FROM purchases p
     LEFT JOIN purchase_returns pr ON pr.purchase_id = p.id
     WHERE $where",
    $params
);

logActivity('View Report', "Purchase Report: {$from_date} to {$to_date}");
$page_title = 'Purchase Report';
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

        .summary-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e9ecef);
            border-radius: var(--card-border-radius, 12px);
            padding: 16px 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            border-left: 4px solid;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .summary-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,0.13); }
        .summary-card .card-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 70%, transparent); margin-bottom: 4px; }
        .summary-card .card-value { font-size: 1.5rem; font-weight: 700; color: var(--pharmacy-heading, #212529); }
        .summary-card .card-sub { font-size: 0.75rem; color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 70%, transparent); margin-top: 2px; }

        .filter-bar {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e9ecef);
            border-radius: var(--card-border-radius, 12px);
            box-shadow: 0 1px 6px rgba(0,0,0,0.06);
            margin-bottom: 16px;
        }
        .filter-bar .card-header {
            background: color-mix(in srgb, var(--pharmacy-table-th, #f8f9fa) 100%, transparent) !important;
            border-bottom: 1px solid var(--pharmacy-border, #e9ecef) !important;
            border-radius: var(--card-border-radius, 12px) var(--card-border-radius, 12px) 0 0 !important;
            padding: 12px 18px; font-weight: 600; font-size: 0.85rem;
            color: var(--pharmacy-heading, #212529) !important;
        }

        .table-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e9ecef);
            border-radius: var(--card-border-radius, 12px);
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
        }
        .table-card .card-header {
            background: var(--pharmacy-table-th, #f8f9fa) !important;
            border-bottom: 2px solid var(--pharmacy-border, #e9ecef) !important;
            padding: 14px 18px; font-weight: 600;
            color: var(--pharmacy-heading, #212529) !important;
        }
        .table th {
            background: var(--pharmacy-table-th, #343a40) !important;
            color: var(--pharmacy-heading, #fff) !important;
            font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700;
            padding: 11px 14px; border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important; white-space: nowrap;
        }
        .table td {
            padding: 10px 14px; vertical-align: middle;
            color: var(--pharmacy-text, #212529) !important;
            border-color: var(--pharmacy-border, #dee2e6) !important;
        }
        .table-hover tbody tr { transition: background 0.15s; }
        .table-hover tbody tr:hover td { background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 6%, var(--pharmacy-card-bg, #fff)) !important; }

        .status-badge { padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; display: inline-block; }
        .status-paid    { background: #d1e7dd; color: #0f5132; }
        .status-partial { background: #fff3cd; color: #664d03; }
        .status-pending { background: #f8d7da; color: #842029; }

        .serial-no { width: 50px; text-align: center; font-weight: 500; color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 70%, transparent); }

        @media print { .no-print { display: none !important; } .main-content { margin-left: 0; } }
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
            <h4 class="fw-bold mb-1" style="color:var(--pharmacy-heading,#212529);">
                <i class="bi bi-cart-check me-2 text-primary"></i><?= $page_title ?>
            </h4>
            <small class="text-muted"><?= formatDate($from_date) ?> — <?= formatDate($to_date) ?></small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="export_pdf.php?report=purchase&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&supplier_id=<?= $supplier_id ?>&payment_status=<?= urlencode($payment_status) ?>" target="_blank" class="btn btn-danger btn-sm">
                <i class="fas fa-file-pdf me-1"></i> PDF
            </a>
            <a href="export_excel.php?report=purchase&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&supplier_id=<?= $supplier_id ?>&payment_status=<?= urlencode($payment_status) ?>" class="btn btn-success btn-sm">
                <i class="bi bi-file-excel me-1"></i> Excel
            </a>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <a href="index.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
        </div>
    </div>

    <!-- Filter -->
    <div class="filter-bar no-print">
        <div class="card-header"><i class="bi bi-funnel me-2"></i>Filter Options</div>
        <div class="p-3">
            <form method="GET" class="row g-3" id="filterForm">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">From Date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= $from_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">To Date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= $to_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Supplier</label>
                    <select name="supplier_id" class="form-select form-select-sm">
                        <option value="">All Suppliers</option>
                        <?php foreach($suppliers as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $supplier_id==$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Payment Status</label>
                    <select name="payment_status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="paid"    <?= $payment_status=='paid'    ? 'selected' : '' ?>>Paid</option>
                        <option value="partial" <?= $payment_status=='partial' ? 'selected' : '' ?>>Partial</option>
                        <option value="pending" <?= $payment_status=='pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#0d6efd;">
                <div class="card-label">Total Purchases</div>
                <div class="card-value"><?= formatCurrency($totals['total_amount']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#198754;">
                <div class="card-label">Total Paid</div>
                <div class="card-value text-success"><?= formatCurrency($totals['total_paid']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#dc3545;">
                <div class="card-label">Total Pending</div>
                <div class="card-value text-danger"><?= formatCurrency($totals['total_due']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#fd7e14;">
                <div class="card-label">Total Returns</div>
                <div class="card-value text-warning"><?= formatCurrency($totals['total_returns']) ?></div>
                <div class="card-sub">Net Purchase: <?= formatCurrency($totals['net_purchase_value']) ?></div>
            </div>
        </div>
    </div>

    <!-- Purchases Table -->
    <div class="table-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-receipt me-2"></i>Purchase Invoices</span>
            <span class="badge bg-primary rounded-pill"><?= count($purchases) ?> Invoices</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="reportTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Supplier</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Due</th>
                        <th class="text-end">Returns</th>
                        <th class="text-end">Net Purchase</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="reportTableBody">
                    <?php if (empty($purchases)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                No records found for selected period
                            </td>
                        </tr>
                    <?php else:
                        $sn = 1;
                        foreach ($purchases as $p):
                    ?>
                    <tr>
                        <td class="serial-no"><?= $sn++ ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($p['purchase_invoice_no']) ?></td>
                        <td><small class="text-muted"><?= formatDate($p['purchase_date']) ?></small></td>
                        <td>
                            <span class="fw-semibold"><?= htmlspecialchars($p['supplier_name']) ?></span>
                            <?php if ($p['supplier_phone']): ?>
                            <br><small class="text-muted"><?= $p['supplier_phone'] ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold"><?= formatCurrency($p['total']) ?></td>
                        <td class="text-end text-success"><?= formatCurrency($p['paid_amount']) ?></td>
                        <td class="text-end text-danger"><?= formatCurrency($p['due_amount']) ?></td>
                        <td class="text-end text-warning"><?= formatCurrency($p['total_returned']) ?></td>
                        <td class="text-end"><?= formatCurrency($p['net_purchase']) ?></td>
                        <td>
                            <span class="status-badge status-<?= $p['payment_status'] ?>"><?= ucfirst($p['payment_status']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-dark fw-bold" style="border-top:2px solid var(--pharmacy-border,#dee2e6);">
                        <td colspan="4" class="text-end fs-6">TOTAL</td>
                        <td class="text-end fs-6"><?= formatCurrency($totals['total_amount']) ?></td>
                        <td class="text-end fs-6 text-success"><?= formatCurrency($totals['total_paid']) ?></td>
                        <td class="text-end fs-6 text-danger"><?= formatCurrency($totals['total_due']) ?></td>
                        <td class="text-end fs-6 text-warning"><?= formatCurrency($totals['total_returns']) ?></td>
                        <td class="text-end fs-6"><?= formatCurrency($totals['net_purchase_value']) ?></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>

<script>
// ── Step 7B: AJAX debounced filtering ─────────────────────────────────────────
let reportFilterTimer = null;
function applyReportFilters() {
    clearTimeout(reportFilterTimer);
    reportFilterTimer = setTimeout(() => {
        const form = document.getElementById('filterForm');
        const params = new URLSearchParams(new FormData(form));
        params.set('ajax', 'filter');
        document.getElementById('reportTable').style.opacity = '0.5';
        fetch('?' + params.toString(), {credentials:'same-origin'})
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('reportTableBody').innerHTML = data.html;
                    if (data.stats) updateReportStats(data.stats);
                    if (data.total !== undefined) {
                        document.querySelectorAll('.badge.bg-primary.rounded-pill').forEach(el => {
                            el.textContent = data.total + ' Invoices';
                        });
                    }
                    history.replaceState({}, '', '?' + params.toString().replace('&ajax=filter',''));
                }
            })
            .finally(() => { document.getElementById('reportTable').style.opacity = '1'; });
    }, 400);
}

function updateReportStats(stats) {
    const cards = document.querySelectorAll('.summary-card .card-value');
    const keys = ['total_amount','total_paid','total_due','total_returns'];
    keys.forEach((key, idx) => {
        if (cards[idx] && stats[key] !== undefined) cards[idx].textContent = stats[key];
    });
    const subEl = document.querySelector('.summary-card .card-sub');
    if (subEl && stats.net_purchase_value) subEl.textContent = 'Net Purchase: ' + stats.net_purchase_value;
}

document.querySelectorAll('#filterForm input, #filterForm select').forEach(el => {
    el.addEventListener('change', applyReportFilters);
});
document.getElementById('searchInput')?.addEventListener('input', applyReportFilters);
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('reportTable')) {
        UniversalTable.init('reportTable', {
            title      : 'Purchase Report',
            pageSize   : 25,
            excludeCols: []
        });
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
