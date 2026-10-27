<?php
/**
 * reports/cashbook_report.php
 * Phase 22 – Cashbook Report (standardized, in reports module)
 * Features: Date-range filter, AJAX reload, PDF/Excel/Print export
 */
require_once '../config/config.php';
require_once '../includes/cashbook_functions.php';
require_once '../includes/sync_engine.php';

requireLogin();

if (!canDo('reports', 'view') && !canView('cashbook')) {
    if (function_exists('setFlash')) setFlash('error', 'You do not have permission to view Cashbook.');
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

// ── AJAX handler ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'filter') {
    header('Content-Type: application/json');

    $from_date = $_GET['from_date'] ?? date('Y-m-01');
    $to_date   = $_GET['to_date']   ?? date('Y-m-d');
    if (!strtotime($from_date)) $from_date = date('Y-m-01');
    if (!strtotime($to_date))   $to_date   = date('Y-m-d');

    $report   = function_exists('getCashbookReport') ? getCashbookReport($from_date, $to_date) : [];
    $totalIn  = array_sum(array_column($report, 'total_in'));
    $totalOut = array_sum(array_column($report, 'total_out'));

    if (!function_exists('fc2')) {
        $cs2 = function_exists('getCompanySettings') ? getCompanySettings() : [];
        $cur = $cs2['currency_symbol'] ?? 'Rs';
        function fc2($a) { global $cur; return $cur . ' ' . number_format((float)$a, 2); }
    }

    ob_start();
    if (empty($report)) {
        echo '<tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>No cashbook entries found</td></tr>';
    } else {
        foreach ($report as $row) {
            $nf = (float)($row['net_flow'] ?? ((float)$row['total_in'] - (float)$row['total_out']));
            $rowClass = $nf >= 0 ? 'table-success-subtle' : 'table-danger-subtle';
            echo '<tr class="' . $rowClass . '">';
            echo '<td>' . htmlspecialchars(date('d-m-Y', strtotime($row['date']))) . '</td>';
            echo '<td class="text-end">' . fc2($row['opening'] ?? 0) . '</td>';
            echo '<td class="text-end text-success fw-semibold">' . fc2($row['total_in']) . '</td>';
            echo '<td class="text-end text-danger fw-semibold">' . fc2($row['total_out']) . '</td>';
            echo '<td class="text-end fw-bold">' . fc2($row['closing'] ?? 0) . '</td>';
            echo '<td class="text-end ' . ($nf >= 0 ? 'text-success' : 'text-danger') . ' fw-bold">' . fc2($nf) . '</td>';
            echo '</tr>';
        }
        // Totals row
        echo '<tr class="table-dark fw-bold" style="border-top:2px solid var(--pharmacy-border,#dee2e6);">';
        echo '<td>PERIOD TOTAL</td>';
        echo '<td></td>';
        echo '<td class="text-end fs-6">' . fc2($totalIn) . '</td>';
        echo '<td class="text-end fs-6">' . fc2($totalOut) . '</td>';
        echo '<td></td>';
        echo '<td class="text-end fs-6 ' . ($totalIn - $totalOut >= 0 ? 'text-success' : 'text-danger') . '">' . fc2($totalIn - $totalOut) . '</td>';
        echo '</tr>';
    }
    $tableHtml = ob_get_clean();

    echo json_encode([
        'success' => true,
        'html'    => $tableHtml,
        'stats'   => [
            'total_in'  => fc2($totalIn),
            'total_out' => fc2($totalOut),
            'net_flow'  => fc2($totalIn - $totalOut),
            'days'      => count($report),
        ],
    ]);
    exit;
}

// ── Normal load ───────────────────────────────────────────────────────────────
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date   = $_GET['to_date']   ?? date('Y-m-d');
if (!strtotime($from_date)) $from_date = date('Y-m-01');
if (!strtotime($to_date))   $to_date   = date('Y-m-d');

$report   = function_exists('getCashbookReport') ? getCashbookReport($from_date, $to_date) : [];
$totalIn  = array_sum(array_column($report, 'total_in'));
$totalOut = array_sum(array_column($report, 'total_out'));
$netFlow  = $totalIn - $totalOut;

$cs       = function_exists('getCompanySettings') ? getCompanySettings() : [];
$currency = $cs['currency_symbol'] ?? 'Rs';
function fc($a) { global $currency; return $currency . ' ' . number_format((float)$a, 2); }

$page_title = 'Cashbook Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        @keyframes fadeInPage { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
        .main-content { margin-left:var(--sb-w,260px); padding:24px; min-height:100vh; animation:fadeInPage 0.28s ease; transition:margin-left 0.3s ease; }
        body.sb-col-body .main-content { margin-left:var(--sb-w-mini,62px); }
        @media (max-width:991.98px){ .main-content { margin-left:0 !important; } }

        .summary-card {
            background:var(--pharmacy-card-bg,#fff);
            border:1px solid var(--pharmacy-border,#e9ecef);
            border-radius:var(--card-border-radius,12px);
            padding:16px 20px; text-align:center; border-left:4px solid;
            box-shadow:0 2px 12px rgba(0,0,0,.07);
            transition:transform .2s ease,box-shadow .2s ease;
        }
        .summary-card:hover { transform:translateY(-3px); box-shadow:0 8px 28px rgba(0,0,0,.13); }
        .summary-value { font-size:1.6rem; font-weight:700; color:var(--pharmacy-heading,#212529); }
        .summary-label { font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; color:var(--pharmacy-text,#6c757d); }

        .filter-card, .table-card {
            background:var(--pharmacy-card-bg,#fff);
            border:1px solid var(--pharmacy-border,#e9ecef);
            border-radius:var(--card-border-radius,12px);
            box-shadow:0 2px 12px rgba(0,0,0,.07);
        }
        .filter-card { padding:14px 18px; margin-bottom:16px; }

        .table-card .table th { background:var(--pharmacy-primary,#0d6efd); color:#fff; border:none; font-size:.8rem; white-space:nowrap; }
        .table-card .table td { vertical-align:middle; font-size:.85rem; }
        .table-success-subtle { background:rgba(25,135,84,.06); }
        .table-danger-subtle  { background:rgba(220,53,69,.06); }

        .page-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .page-actions .btn { border-radius:8px; font-size:.8rem; font-weight:600; }

        @media print {
            .main-content { margin-left:0 !important; padding:0; }
            .no-print { display:none !important; }
            .table-card { box-shadow:none; border:1px solid #dee2e6; }
        }
    </style>
</head>
<?php $dm = !empty($_SESSION['company_settings_cache']['dark_mode']) ? ' dark-mode' : ''; ?>
<body class="<?= trim('bg-light' . $dm) ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">

    <!-- Page Header -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-book-open me-2 text-success"></i>Cashbook Report</h4>
            <p class="text-muted small mb-0">Daily cash flow summary — opening, in, out &amp; closing balances</p>
        </div>
        <div class="page-actions no-print">
            <a href="export_pdf.php?report=cashbook&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>"
               target="_blank" class="btn btn-sm btn-danger">
                <i class="fas fa-file-pdf me-1"></i>PDF
            </a>
            <a href="export_excel.php?report=cashbook&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>"
               class="btn btn-sm btn-success">
                <i class="fas fa-file-excel me-1"></i>Excel
            </a>
            <button type="button" class="btn btn-sm btn-secondary" onclick="window.print()">
                <i class="fas fa-print me-1"></i>Print
            </button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Reports
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4" id="summaryCards">
        <div class="col-md-3 col-6">
            <div class="summary-card" style="border-left-color:#198754;">
                <div class="summary-label">Total Cash In</div>
                <div class="summary-value text-success" id="stat_in"><?= fc($totalIn) ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="summary-card" style="border-left-color:#dc3545;">
                <div class="summary-label">Total Cash Out</div>
                <div class="summary-value text-danger" id="stat_out"><?= fc($totalOut) ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="summary-card" style="border-left-color:<?= $netFlow >= 0 ? '#0d6efd' : '#dc3545' ?>;">
                <div class="summary-label">Net Flow</div>
                <div class="summary-value <?= $netFlow >= 0 ? 'text-primary' : 'text-danger' ?>" id="stat_net"><?= fc($netFlow) ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="summary-card" style="border-left-color:#6f42c1;">
                <div class="summary-label">Days in Period</div>
                <div class="summary-value" id="stat_days"><?= count($report) ?></div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-card no-print">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label form-label-sm fw-semibold">From Date</label>
                <input type="date" class="form-control form-control-sm" name="from_date" id="from_date"
                       value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm fw-semibold">To Date</label>
                <input type="date" class="form-control form-control-sm" name="to_date" id="to_date"
                       value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm px-4" id="filterBtn">
                    <i class="fas fa-search me-1"></i>Filter
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm ms-1" id="resetBtn">
                    <i class="fas fa-undo me-1"></i>Reset
                </button>
            </div>
            <!-- Quick ranges -->
            <div class="col-auto ms-auto d-flex gap-1 flex-wrap">
                <button type="button" class="btn btn-outline-primary btn-sm quick-range" data-range="today">Today</button>
                <button type="button" class="btn btn-outline-primary btn-sm quick-range" data-range="week">This Week</button>
                <button type="button" class="btn btn-outline-primary btn-sm quick-range" data-range="month">This Month</button>
                <button type="button" class="btn btn-outline-primary btn-sm quick-range" data-range="prev_month">Prev Month</button>
            </div>
        </form>
    </div>

    <!-- Table Card -->
    <div class="table-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="cashbookTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Opening Balance</th>
                        <th class="text-end">Cash In <i class="fas fa-arrow-down text-success ms-1"></i></th>
                        <th class="text-end">Cash Out <i class="fas fa-arrow-up text-danger ms-1"></i></th>
                        <th class="text-end">Closing Balance</th>
                        <th class="text-end">Net Flow</th>
                    </tr>
                </thead>
                <tbody id="cashbookBody">
                <?php
                if (empty($report)) {
                    echo '<tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>No cashbook entries found</td></tr>';
                } else {
                    foreach ($report as $row) {
                        $nf = (float)($row['net_flow'] ?? ((float)$row['total_in'] - (float)$row['total_out']));
                        $rc = $nf >= 0 ? 'table-success-subtle' : 'table-danger-subtle';
                        echo "<tr class=\"{$rc}\">";
                        echo "<td>" . date('d-m-Y', strtotime($row['date'])) . "</td>";
                        echo "<td class=\"text-end\">" . fc($row['opening'] ?? 0) . "</td>";
                        echo "<td class=\"text-end text-success fw-semibold\">" . fc($row['total_in']) . "</td>";
                        echo "<td class=\"text-end text-danger fw-semibold\">" . fc($row['total_out']) . "</td>";
                        echo "<td class=\"text-end fw-bold\">" . fc($row['closing'] ?? 0) . "</td>";
                        echo "<td class=\"text-end fw-bold " . ($nf >= 0 ? 'text-success' : 'text-danger') . "\">" . fc($nf) . "</td>";
                        echo "</tr>";
                    }
                    echo '<tr class="table-dark fw-bold" style="border-top:2px solid var(--pharmacy-border,#dee2e6);">';
                    echo '<td>PERIOD TOTAL</td><td></td>';
                    echo '<td class="text-end fs-6">' . fc($totalIn) . '</td>';
                    echo '<td class="text-end fs-6">' . fc($totalOut) . '</td>';
                    echo '<td></td>';
                    $nfClass = $netFlow >= 0 ? 'text-success' : 'text-danger';
                    echo "<td class=\"text-end fs-6 {$nfClass}\">" . fc($netFlow) . "</td>";
                    echo '</tr>';
                }
                ?>
                </tbody>
            </table>
        </div>
    </div><!-- /table-card -->

</div><!-- /container -->
</div><!-- /main-content -->

<?php include '../includes/footer.php'; ?>

<script>
(function () {
    'use strict';

    const form      = document.getElementById('filterForm');
    const tbody     = document.getElementById('cashbookBody');
    const filterBtn = document.getElementById('filterBtn');
    const resetBtn  = document.getElementById('resetBtn');

    // ── Quick date range shortcuts ────────────────────────────
    document.querySelectorAll('.quick-range').forEach(btn => {
        btn.addEventListener('click', function () {
            const r = this.dataset.range;
            const today = new Date();
            let from, to;
            if (r === 'today') {
                from = to = _fmt(today);
            } else if (r === 'week') {
                const day = today.getDay() || 7;
                const mon = new Date(today); mon.setDate(today.getDate() - day + 1);
                from = _fmt(mon); to = _fmt(today);
            } else if (r === 'month') {
                const d = new Date(today.getFullYear(), today.getMonth(), 1);
                from = _fmt(d); to = _fmt(today);
            } else if (r === 'prev_month') {
                const f = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                const t = new Date(today.getFullYear(), today.getMonth(), 0);
                from = _fmt(f); to = _fmt(t);
            }
            document.getElementById('from_date').value = from;
            document.getElementById('to_date').value   = to;
            _doFilter();
        });
    });

    function _fmt(d) {
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    // ── Reset ────────────────────────────────────────────────
    resetBtn.addEventListener('click', function () {
        const today  = new Date();
        const first  = new Date(today.getFullYear(), today.getMonth(), 1);
        document.getElementById('from_date').value = _fmt(first);
        document.getElementById('to_date').value   = _fmt(today);
        _doFilter();
    });

    // ── Form submit ──────────────────────────────────────────
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        _doFilter();
    });

    function _doFilter() {
        const from = document.getElementById('from_date').value;
        const to   = document.getElementById('to_date').value;

        // Update export links with current dates
        _updateExportLinks(from, to);

        filterBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading…';
        filterBtn.disabled  = true;
        tbody.style.opacity = '0.4';

        fetch(`cashbook_report.php?ajax=filter&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    tbody.innerHTML = data.html;
                    // Update summary cards
                    const stats = data.stats;
                    document.getElementById('stat_in').textContent   = stats.total_in;
                    document.getElementById('stat_out').textContent  = stats.total_out;
                    document.getElementById('stat_net').textContent  = stats.net_flow;
                    document.getElementById('stat_days').textContent = stats.days;
                }
            })
            .catch(() => {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Error loading data. Please try again.</td></tr>';
            })
            .finally(() => {
                filterBtn.innerHTML = '<i class="fas fa-search me-1"></i>Filter';
                filterBtn.disabled  = false;
                tbody.style.opacity = '1';
            });
    }

    function _updateExportLinks(from, to) {
        document.querySelectorAll('a[href*="export_pdf.php"], a[href*="export_excel.php"]').forEach(a => {
            const url = new URL(a.href, window.location.href);
            url.searchParams.set('from_date', from);
            url.searchParams.set('to_date', to);
            a.href = url.toString();
        });
    }
})();
</script>
</body>
</html>
