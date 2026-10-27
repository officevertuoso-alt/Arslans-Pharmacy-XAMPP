<?php
/**
 * reports/profit_loss_report.php
 * Profit & Loss Report – Enhanced with Returns, Cashbook Reconciliation, Credit Outstanding
 * Step 13: Design System Enhanced
 */
require_once '../config/config.php';
require_once '../includes/sync_engine.php';
require_once '../includes/cashbook_functions.php';

requireLogin();

if (!canDo('reports', 'view')) {
    if (function_exists('setFlash')) setFlash('error', 'You do not have permission to view reports.');
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '../') . 'dashboard.php');
    exit;
}
$from_date = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? sanitize($_GET['from_date']) : date('Y-m-01');
$to_date   = isset($_GET['to_date'])   && $_GET['to_date'] !== ''   ? sanitize($_GET['to_date'])   : date('Y-m-d');
if (!strtotime($from_date)) $from_date = date('Y-m-01');
if (!strtotime($to_date))   $to_date   = date('Y-m-d');

$db = db();

// [PERF-01 / PERF-06 / PERF-07] PHASE 27: Replace 8 serial scalar getOne() calls
// with sp_profit_loss_summary (1 procedure = 4 indexed range scans).
// Also fixes non-sargable DATE(col) wrapping — proc uses col BETWEEN x AND y.
$pdo = db()->getConnection();
$stmt = $pdo->prepare("CALL sp_profit_loss_summary(
    :from, :to,
    @gross_revenue, @sale_returns, @net_revenue,
    @cogs_gross, @cogs_returns, @cogs,
    @purchase_returns, @expenses,
    @gross_profit, @net_profit, @margin_pct
)");
$stmt->execute([':from' => $from_date, ':to' => $to_date]);
$stmt->closeCursor();
$row = $pdo->query("SELECT
    @gross_revenue AS gross_revenue, @sale_returns AS sale_returns,
    @net_revenue AS net_revenue, @cogs_gross AS cogs_gross,
    @cogs_returns AS cogs_returns, @cogs AS cogs,
    @purchase_returns AS purchase_returns, @expenses AS expenses,
    @gross_profit AS gross_profit, @net_profit AS net_profit,
    @margin_pct AS margin_pct")->fetch(PDO::FETCH_ASSOC);

$gross_revenue    = (float)($row['gross_revenue']   ?? 0);
$sale_returns     = (float)($row['sale_returns']    ?? 0);
$net_revenue      = (float)($row['net_revenue']     ?? 0);
$cogs_gross       = (float)($row['cogs_gross']      ?? 0);
$cogs_returns     = (float)($row['cogs_returns']    ?? 0);
$cogs             = (float)($row['cogs']            ?? 0);
$purchase_returns = (float)($row['purchase_returns']?? 0);
$expenses         = (float)($row['expenses']        ?? 0);
$gross_profit     = (float)($row['gross_profit']    ?? 0);
$net_profit       = (float)($row['net_profit']      ?? 0);
$margin_pct       = (float)($row['margin_pct']      ?? 0);

// Credit Outstanding — [PERF-03] pre-aggregated JOIN (no correlated subquery)
$credit_outstanding = (float)($db->getOne(
    "SELECT COALESCE(SUM(cc.total_amount) - COALESCE(SUM(cp_agg.paid), 0), 0) AS total
     FROM customer_credits cc
     LEFT JOIN (SELECT credit_id, SUM(amount) AS paid FROM credit_payments GROUP BY credit_id) cp_agg
            ON cp_agg.credit_id = cc.id
     WHERE cc.status IN ('active', 'overdue')"
)['total'] ?? 0);

// Cashbook Reconciliation — [PERF-06] range predicate (no DATE() wrap)
$cashbook_in = (float)($db->getOne(
    "SELECT COALESCE(SUM(credit), 0) AS total FROM cashbook
     WHERE entry_date BETWEEN :from AND :to
       AND voided = 0
       AND entry_type IN ('sale', 'credit_received', 'purchase_return')",
    [':from' => $from_date, ':to' => $to_date]
)['total'] ?? 0);
$cashbook_discrepancy = $cashbook_in - $gross_revenue;

// Daily breakdown — [PERF-07] use vw_daily_profit_loss (pre-aggregated JOIN, no
// correlated subquery per row). Range predicate on indexed column.
$daily_data = $db->getAll(
    "SELECT day, revenue, cogs, gross_profit, expenses_day, net_profit_day
     FROM vw_daily_profit_loss
     WHERE day BETWEEN :from AND :to
     ORDER BY day ASC",
    [':from' => $from_date, ':to' => $to_date]
);

// Monthly breakdown
$monthly_data = $db->getAll(
    "SELECT DATE_FORMAT(s.sale_date, '%Y-%m') AS month,
            COALESCE(SUM(si.quantity * si.unit_price), 0) AS revenue,
            COALESCE(SUM(si.quantity * b.purchase_price), 0) AS cogs,
            (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date) BETWEEN DATE_FORMAT(s.sale_date,'%Y-%m-01') AND LAST_DAY(s.sale_date)) AS expenses_month
     FROM sales s LEFT JOIN sale_items si ON si.sale_id = s.id LEFT JOIN batches b ON b.id = si.batch_id
     WHERE DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided'
     GROUP BY month ORDER BY month ASC",
    [':from' => $from_date, ':to' => $to_date]
);

// ── Step 7A: AJAX filter handler ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'filter') {
    $from_date = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? sanitize($_GET['from_date']) : date('Y-m-01');
    $to_date   = isset($_GET['to_date'])   && $_GET['to_date'] !== ''   ? sanitize($_GET['to_date'])   : date('Y-m-d');
    if (!strtotime($from_date)) $from_date = date('Y-m-01');
    if (!strtotime($to_date))   $to_date   = date('Y-m-d');
    $db = db();
    $gross_revenue   = (float)($db->getOne("SELECT COALESCE(SUM(si.quantity * si.unit_price), 0) AS total FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided'", [':from'=>$from_date,':to'=>$to_date])['total'] ?? 0);
    $sale_returns    = (float)($db->getOne("SELECT COALESCE(SUM(total_amount), 0) AS total FROM sale_returns WHERE DATE(created_at) BETWEEN :from AND :to", [':from'=>$from_date,':to'=>$to_date])['total'] ?? 0);
    $net_revenue     = $gross_revenue - $sale_returns;
    $cogs_gross      = (float)($db->getOne("SELECT COALESCE(SUM(si.quantity * b.purchase_price), 0) AS total FROM sale_items si JOIN batches b ON b.id = si.batch_id JOIN sales s ON s.id = si.sale_id WHERE DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided'", [':from'=>$from_date,':to'=>$to_date])['total'] ?? 0);
    $cogs_returns    = (float)($db->getOne("SELECT COALESCE(SUM(sr.quantity * b.purchase_price), 0) AS total FROM sale_returns sr JOIN batches b ON b.id = sr.batch_id WHERE DATE(sr.created_at) BETWEEN :from AND :to", [':from'=>$from_date,':to'=>$to_date])['total'] ?? 0);
    $cogs            = $cogs_gross - $cogs_returns;
    $expenses        = (float)($db->getOne("SELECT COALESCE(SUM(amount), 0) AS total FROM expenses WHERE DATE(expense_date) BETWEEN :from AND :to", [':from'=>$from_date,':to'=>$to_date])['total'] ?? 0);
    $gross_profit    = $net_revenue - $cogs;
    $net_profit      = $gross_profit - $expenses;
    $margin_pct      = $net_revenue > 0 ? round(($gross_profit / $net_revenue) * 100, 1) : 0;
    $daily_data      = $db->getAll("SELECT DATE(s.sale_date) AS day, COALESCE(SUM(si.quantity * si.unit_price), 0) AS revenue, COALESCE(SUM(si.quantity * b.purchase_price), 0) AS cogs, COALESCE(SUM(si.quantity * si.unit_price), 0) - COALESCE(SUM(si.quantity * b.purchase_price), 0) AS gross_profit, (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date) = DATE(s.sale_date)) AS expenses_day, COALESCE(SUM(si.quantity * si.unit_price), 0) - COALESCE(SUM(si.quantity * b.purchase_price), 0) - (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date) = DATE(s.sale_date)) AS net_profit_day FROM sales s LEFT JOIN sale_items si ON si.sale_id = s.id LEFT JOIN batches b ON b.id = si.batch_id WHERE DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided' GROUP BY day ORDER BY day ASC", [':from'=>$from_date,':to'=>$to_date]);
    if (!function_exists('formatCurrency')) { function formatCurrency($a) { return 'Rs ' . number_format((float)$a, 2); } }
    if (!function_exists('formatDate'))     { function formatDate($d) { return empty($d) ? '' : date('d-m-Y', strtotime($d)); } }
    ob_start();
    foreach ($daily_data as $row) {
        $dRevenue = (float)$row['revenue'];
        $dCogs    = (float)$row['cogs'];
        $dGross   = (float)$row['gross_profit'];
        $dExp     = (float)$row['expenses_day'];
        $dNet     = (float)$row['net_profit_day'];
        $dMargin  = $dRevenue > 0 ? round(($dGross / $dRevenue) * 100, 1) : 0;
        echo '<tr class="' . ($dNet < 0 ? 'table-danger' : '') . '">';
        echo '<td>' . date('d-m-Y', strtotime($row['day'])) . '</td>';
        echo '<td class="text-end text-success">' . formatCurrency($dRevenue) . '</td>';
        echo '<td class="text-end text-danger">' . formatCurrency($dCogs) . '</td>';
        echo '<td class="text-end fw-semibold">' . formatCurrency($dGross) . '</td>';
        echo '<td class="text-end text-warning">' . formatCurrency($dExp) . '</td>';
        echo '<td class="text-end fw-bold ' . ($dNet >= 0 ? 'text-success' : 'text-danger') . '">' . formatCurrency($dNet) . '</td>';
        echo '<td class="text-end"><small>' . $dMargin . '%</small></td>';
        echo '</tr>';
    }
    if (empty($daily_data)) {
        echo '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="bi bi-inbox fs-3 d-block mb-2 opacity-50"></i>No data for selected period</td></tr>';
    }
    $tableHtml = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html'    => $tableHtml,
        'total'   => count($daily_data),
        'stats'   => [
            'gross_revenue' => formatCurrency($gross_revenue),
            'sale_returns'  => formatCurrency($sale_returns),
            'net_revenue'   => formatCurrency($net_revenue),
            'cogs'          => formatCurrency($cogs),
            'gross_profit'  => formatCurrency($gross_profit),
            'expenses'      => formatCurrency($expenses),
            'net_profit'    => formatCurrency($net_profit),
            'margin_pct'    => $margin_pct . '%',
        ],
    ]);
    exit;
}

logActivity('View Report', "Profit & Loss Report: {$from_date} to {$to_date}");
$page_title = 'Profit & Loss Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        @keyframes fadeInPage { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        body { background: var(--pharmacy-bg, #f0f2f5); }
        .main-content { margin-left: var(--sb-w, 260px); padding: 24px; min-height: 100vh; animation: fadeInPage 0.28s ease; transition: margin-left 0.3s ease; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991px) { .main-content { margin-left: 0 !important; } }

        /* KPI Summary Cards */
        .kpi-card {
            border: none; border-radius: var(--card-border-radius, 12px);
            color: #fff; padding: 1.2rem;
            transition: transform .2s, box-shadow .2s;
            display: flex; flex-direction: column; gap: .4rem;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        }
        .kpi-card:hover { transform: translateY(-4px); box-shadow: 0 10px 32px rgba(0,0,0,0.18); }
        .kpi-card .card-top { display: flex; align-items: center; gap: .6rem; }
        .kpi-card .icon { width: 2.8rem; height: 2.8rem; min-width: 2.8rem; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,.18); border-radius: 8px; }
        .kpi-card .icon i { font-size: 1.4rem; line-height: 1; }
        .kpi-card .label { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; opacity: .85; margin: 0; }
        .kpi-card .amount { font-size: 1.35rem; font-weight: 700; line-height: 1.2; }
        .kpi-card .sub { font-size: .72rem; opacity: .75; }
        .card-revenue  { background: linear-gradient(135deg, #198754, #20c997); }
        .card-cost     { background: linear-gradient(135deg, #dc3545, #f06060); }
        .card-gross    { background: linear-gradient(135deg, #0d6efd, #3d9ef7); }
        .card-expense  { background: linear-gradient(135deg, #fd7e14, #ffb347); }
        .card-net      { background: linear-gradient(135deg, #6f42c1, #a078e8); }
        .card-margin   { background: linear-gradient(135deg, #20c997, #52e0f5); color: #1a1a2e; }

        /* Filter bar */
        .filter-bar {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e9ecef);
            border-radius: var(--card-border-radius, 12px);
            padding: 16px 18px;
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
        .table-card .t-header {
            background: var(--pharmacy-table-th, #f8f9fa) !important;
            border-bottom: 2px solid var(--pharmacy-border, #e9ecef);
            padding: 14px 18px; font-weight: 600;
            color: var(--pharmacy-heading, #212529) !important;
        }
        .table th {
            background: var(--pharmacy-table-th, #343a40) !important;
            color: var(--pharmacy-heading, #fff) !important;
            font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700;
            padding: 11px 14px; border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important; white-space: nowrap;
        }
        .table td { padding: 10px 14px; vertical-align: middle; color: var(--pharmacy-text, #212529) !important; border-color: var(--pharmacy-border, #dee2e6) !important; }
        .table-hover tbody tr { transition: background 0.15s; }
        .table-hover tbody tr:hover td { background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 5%, var(--pharmacy-card-bg, #fff)) !important; }

        /* Info cards */
        .info-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e9ecef);
            border-radius: var(--card-border-radius, 12px);
            padding: 16px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .info-card.warning-border { border-color: #fd7e14; }

        @media print { .no-print { display: none !important; } body { background: #fff; } .main-content { margin-left: 0; } }
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
                <i class="bi bi-graph-up-arrow me-2 text-success"></i><?= $page_title ?>
            </h4>
            <small class="text-muted"><?= formatDate($from_date) ?> — <?= formatDate($to_date) ?></small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="export_pdf.php?report=profit_loss&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>" target="_blank" class="btn btn-danger btn-sm">
                <i class="fas fa-file-pdf me-1"></i> PDF
            </a>
            <a href="export_excel.php?report=profit_loss&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1"></i> Excel
            </a>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <a href="index.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
        </div>
    </div>

    <!-- Date Filter -->
    <div class="filter-bar no-print">
        <form method="GET" class="row g-2 align-items-end" id="filterForm">
            <div class="col-sm-4 col-lg-3">
                <label class="form-label small fw-semibold">From Date</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label small fw-semibold">To Date</label>
                <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div class="col-sm-4 col-lg-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-funnel me-1"></i>Apply
                </button>
            </div>
            <div class="col-12">
                <div class="btn-group btn-group-sm" role="group">
                    <?php
                    $quick = [
                        'Today'      => [date('Y-m-d'), date('Y-m-d')],
                        'This Week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
                        'This Month' => [date('Y-m-01'), date('Y-m-d')],
                        'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                        'This Year'  => [date('Y-01-01'), date('Y-m-d')],
                    ];
                    foreach ($quick as $label => [$qFrom, $qTo]):
                    ?>
                    <a href="?from_date=<?= $qFrom ?>&to_date=<?= $qTo ?>"
                       class="btn btn-outline-secondary <?= ($from_date === $qFrom && $to_date === $qTo) ? 'active' : '' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- KPI Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card card-revenue">
                <div class="card-top">
                    <div class="icon"><i class="bi bi-cash-stack"></i></div>
                    <div class="label">Net Revenue</div>
                </div>
                <div class="amount"><?= formatCurrency($net_revenue) ?></div>
                <div class="sub">Gross <?= formatCurrency($gross_revenue) ?> − Returns <?= formatCurrency($sale_returns) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card card-cost">
                <div class="card-top">
                    <div class="icon"><i class="bi bi-box-seam"></i></div>
                    <div class="label">COGS</div>
                </div>
                <div class="amount"><?= formatCurrency($cogs) ?></div>
                <div class="sub">Adj. purchase cost</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card card-gross">
                <div class="card-top">
                    <div class="icon"><i class="bi bi-bar-chart-line"></i></div>
                    <div class="label">Gross Profit</div>
                </div>
                <div class="amount"><?= formatCurrency($gross_profit) ?></div>
                <div class="sub">Net Revenue − COGS</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card card-expense">
                <div class="card-top">
                    <div class="icon"><i class="bi bi-wallet2"></i></div>
                    <div class="label">Expenses</div>
                </div>
                <div class="amount"><?= formatCurrency($expenses) ?></div>
                <div class="sub">Operational</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card card-net">
                <div class="card-top">
                    <div class="icon"><i class="bi bi-graph-up"></i></div>
                    <div class="label">Net Profit</div>
                </div>
                <div class="amount <?= $net_profit < 0 ? 'text-warning' : '' ?>"><?= formatCurrency($net_profit) ?></div>
                <div class="sub">Gross − Expenses</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card <?= $margin_pct >= 20 ? 'card-gross' : ($margin_pct >= 10 ? 'card-expense' : 'card-cost') ?>">
                <div class="card-top">
                    <div class="icon"><i class="bi bi-percent"></i></div>
                    <div class="label">Gross Margin</div>
                </div>
                <div class="amount"><?= $margin_pct ?>%</div>
                <div class="sub">Gross Profit / Net Revenue</div>
            </div>
        </div>
    </div>

    <!-- Returns & Cashbook Reconciliation -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="info-card">
                <h6 class="fw-bold mb-3" style="color:var(--pharmacy-heading,#212529);">
                    <i class="bi bi-arrow-left-right me-2 text-warning"></i>Returns Summary
                </h6>
                <div class="d-flex justify-content-between mb-2">
                    <span>Sale Returns (refunds given):</span>
                    <strong class="text-danger"><?= formatCurrency($sale_returns) ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Purchase Returns (refunds received):</span>
                    <strong class="text-success"><?= formatCurrency($purchase_returns) ?></strong>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="info-card <?= abs($cashbook_discrepancy) > 0.01 ? 'warning-border' : '' ?>">
                <h6 class="fw-bold mb-3" style="color:var(--pharmacy-heading,#212529);">
                    <i class="bi bi-journal-check me-2 text-primary"></i>Cashbook Reconciliation
                </h6>
                <div class="d-flex justify-content-between mb-2">
                    <span>Cashbook Cash In (period):</span>
                    <strong><?= formatCurrency($cashbook_in) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Gross Revenue (from sales):</span>
                    <strong><?= formatCurrency($gross_revenue) ?></strong>
                </div>
                <div class="d-flex justify-content-between border-top pt-2 <?= abs($cashbook_discrepancy) > 0.01 ? 'text-danger' : 'text-success' ?>">
                    <span>Reconciliation Difference:</span>
                    <strong><?= formatCurrency($cashbook_discrepancy) ?>
                        <?php if (abs($cashbook_discrepancy) > 0.01): ?>
                        <i class="bi bi-exclamation-triangle-fill ms-1" title="Cashbook does not match sales revenue."></i>
                        <?php endif; ?>
                    </strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Daily Breakdown Table -->
    <?php if (!empty($daily_data)): ?>
    <div class="table-card mb-4">
        <div class="t-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table me-2 text-primary"></i>Daily Breakdown</span>
            <span class="badge bg-primary rounded-pill"><?= count($daily_data) ?> days</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-bordered mb-0" style="font-size:.85rem;" id="reportTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">COGS</th>
                        <th class="text-end">Gross Profit</th>
                        <th class="text-end">Expenses</th>
                        <th class="text-end">Net Profit</th>
                        <th class="text-end">Margin %</th>
                    </tr>
                </thead>
                <tbody id="reportTableBody">
                    <?php foreach ($daily_data as $row):
                        $dRevenue = (float)$row['revenue'];
                        $dCogs    = (float)$row['cogs'];
                        $dGross   = (float)$row['gross_profit'];
                        $dExp     = (float)$row['expenses_day'];
                        $dNet     = (float)$row['net_profit_day'];
                        $dMargin  = $dRevenue > 0 ? round(($dGross / $dRevenue) * 100, 1) : 0;
                    ?>
                    <tr class="<?= $dNet < 0 ? 'table-danger' : '' ?>">
                        <td class="fw-semibold"><?= date('D, d M Y', strtotime($row['day'])) ?></td>
                        <td class="text-end"><?= formatCurrency($dRevenue) ?></td>
                        <td class="text-end text-danger"><?= formatCurrency($dCogs) ?></td>
                        <td class="text-end <?= $dGross < 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($dGross) ?></td>
                        <td class="text-end text-warning"><?= formatCurrency($dExp) ?></td>
                        <td class="text-end fw-bold <?= $dNet < 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($dNet) ?></td>
                        <td class="text-end">
                            <span class="badge <?= $dMargin >= 20 ? 'bg-success' : ($dMargin >= 10 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                <?= $dMargin ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-secondary fw-bold">
                    <tr>
                        <td>Total</td>
                        <td class="text-end"><?= formatCurrency($gross_revenue) ?></td>
                        <td class="text-end text-danger"><?= formatCurrency($cogs) ?></td>
                        <td class="text-end <?= $gross_profit < 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($gross_profit) ?></td>
                        <td class="text-end text-warning"><?= formatCurrency($expenses) ?></td>
                        <td class="text-end <?= $net_profit < 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($net_profit) ?></td>
                        <td class="text-end"><span class="badge bg-primary"><?= $margin_pct ?>%</span></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Daily Net Profit Chart -->
    <?php if (!empty($daily_data)): ?>
    <div class="table-card mb-4">
        <div class="t-header">
            <i class="bi bi-bar-chart-fill me-2 text-success"></i>Daily Net Profit — <?= formatDate($from_date) ?> to <?= formatDate($to_date) ?>
        </div>
        <div class="p-3"><canvas id="dailyProfitChart" height="90"></canvas></div>
    </div>
    <?php endif; ?>

    <!-- Monthly Chart -->
    <?php if (!empty($monthly_data)): ?>
    <div class="table-card mb-4">
        <div class="t-header">
            <i class="bi bi-bar-chart-steps me-2 text-primary"></i>Monthly P&L Trend
        </div>
        <div class="p-3"><canvas id="monthlyChart" height="100"></canvas></div>
    </div>
    <?php endif; ?>

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
                    history.replaceState({}, '', '?' + params.toString().replace('&ajax=filter',''));
                }
            })
            .finally(() => { document.getElementById('reportTable').style.opacity = '1'; });
    }, 400);
}
function updateReportStats(stats) {
    const kpiCards = document.querySelectorAll('.kpi-card .amount');
    const keys = ['gross_revenue','sale_returns','net_revenue','cogs','gross_profit','expenses','net_profit','margin_pct'];
    keys.forEach((key, idx) => {
        if (kpiCards[idx] && stats[key] !== undefined) kpiCards[idx].textContent = stats[key];
    });
}
document.querySelectorAll('#filterForm input, #filterForm select').forEach(el => {
    el.addEventListener('change', applyReportFilters);
});
document.getElementById('searchInput')?.addEventListener('input', applyReportFilters);
</script>

<script>
<?php if (!empty($monthly_data)): ?>
const months    = <?= json_encode(array_column($monthly_data, 'month')) ?>;
const revenues  = <?= json_encode(array_map(fn($r) => (float)$r['revenue'], $monthly_data)) ?>;
const cogsArr   = <?= json_encode(array_map(fn($r) => (float)$r['cogs'], $monthly_data)) ?>;
const expArr    = <?= json_encode(array_map(fn($r) => (float)$r['expenses_month'], $monthly_data)) ?>;
new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: { labels: months, datasets: [
        { label: 'Net Revenue', data: revenues, backgroundColor: 'rgba(25,135,84,.7)', borderRadius: 4 },
        { label: 'COGS', data: cogsArr, backgroundColor: 'rgba(220,53,69,.7)', borderRadius: 4 },
        { label: 'Expenses', data: expArr, backgroundColor: 'rgba(253,126,20,.7)', borderRadius: 4 }
    ]},
    options: { responsive: true, plugins: { tooltip: { callbacks: { label: ctx => ' Rs ' + ctx.raw.toLocaleString() } } }, scales: { y: { beginAtZero: true, ticks: { callback: v => 'Rs ' + v.toLocaleString() } } } }
});
<?php endif; ?>

<?php if (!empty($daily_data)): ?>
const dailyLabels = <?= json_encode(array_map(fn($r) => date('d M', strtotime($r['day'])), $daily_data)) ?>;
const dailyProfit = <?= json_encode(array_map(fn($r) => round((float)$r['net_profit_day'], 2), $daily_data)) ?>;
const dailyColors = dailyProfit.map(v => v >= 0 ? 'rgba(25,135,84,.75)' : 'rgba(220,53,69,.75)');
new Chart(document.getElementById('dailyProfitChart'), {
    type: 'bar',
    data: { labels: dailyLabels, datasets: [{ label: 'Daily Net Profit', data: dailyProfit, backgroundColor: dailyColors, borderRadius: 4 }] },
    options: { responsive: true, plugins: { tooltip: { callbacks: { label: ctx => ' Rs ' + ctx.raw.toLocaleString() } }, legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: v => 'Rs ' + v.toLocaleString() }, grid: { color: 'rgba(0,0,0,.05)' } } } }
});
<?php endif; ?>
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('reportTable')) {
        UniversalTable.init('reportTable', {
            title      : 'Profit & Loss Report',
            pageSize   : 25,
            excludeCols: []
        });
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
