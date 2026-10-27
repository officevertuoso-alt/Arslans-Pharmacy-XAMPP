<?php
/**
 * Cashbook Report — Date Range Summary + Chart + Export
 * cashbook/report.php
 * Step 12 — Cashbook Module
 */

require_once '../config/config.php';
require_once '../includes/cashbook_functions.php';

requireLogin();

if (!canView('cashbook')) {
    $_SESSION['flash']['error'] = [
        'message' => '<i class="bi bi-lock-fill me-2"></i>You do not have permission to view the Cashbook Report. Contact your administrator.',
        'type'    => 'warning'
    ];
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

// Date range defaults — pichle 30 din
$toDate   = $_GET['to']   ?? date('Y-m-d');
$fromDate = $_GET['from'] ?? date('Y-m-d', strtotime('-29 days'));

// Validate
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = date('Y-m-d');

// Excel export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $report = getCashbookReport($fromDate, $toDate);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cashbook_' . $fromDate . '_to_' . $toDate . '.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

    fputcsv($out, ['Date', 'Opening Balance', 'Total In (Credit)', 'Total Out (Debit)', 'Closing Balance', 'Net Flow']);
    foreach ($report as $row) {
        fputcsv($out, [
            $row['date'],
            number_format($row['opening'],  2, '.', ''),
            number_format($row['total_in'], 2, '.', ''),
            number_format($row['total_out'],2, '.', ''),
            number_format($row['closing'],  2, '.', ''),
            number_format($row['net_flow'], 2, '.', ''),
        ]);
    }
    fclose($out);
    exit;
}

// Normal page load
$report    = getCashbookReport($fromDate, $toDate);
$pageTitle = 'Cashbook Report';

// Chart data prepare
$chartLabels  = array_column($report, 'date');
$chartIn      = array_column($report, 'total_in');
$chartOut     = array_column($report, 'total_out');
$chartClosing = array_column($report, 'closing');

// Totals
$grandIn  = array_sum($chartIn);
$grandOut = array_sum($chartOut);
?>
<!DOCTYPE html>
<html lang="ur" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>

    
    

    <style>
        /* Page fade-in */
        @keyframes fadeInPage{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
        .main-content{ margin-left: var(--sb-w, 260px); padding: 20px; min-height: 100vh; transition: margin-left .25s ease; animation: fadeInPage 0.28s ease; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

        body { background: var(--pharmacy-body-bg, #f0f2f5); }
        .report-card {
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: var(--card-border-radius, 12px);
            box-shadow: 0 2px 10px rgba(0,0,0,.07);
            background: var(--pharmacy-card-bg, #fff);
            transition: box-shadow 0.2s ease;
        }
        .report-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.12); }
        .summary-stat { text-align: center; padding: 16px; }
        .summary-stat .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--pharmacy-heading, #111827); }
        .summary-stat .stat-label { font-size: .8rem; color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 70%, transparent); text-transform: uppercase; letter-spacing: .5px; }
        .report-table thead th {
            background: var(--pharmacy-table-th, #2c3e50);
            color: var(--pharmacy-heading, #fff);
            font-size: .82rem; text-transform: uppercase; letter-spacing: .4px;
            border: none;
            font-weight: 700;
        }
        .report-table td {
            vertical-align: middle;
            font-size: .88rem;
            color: var(--pharmacy-text, #374151);
            border-color: var(--pharmacy-border, #e5e7eb);
        }
        .report-table tbody tr { transition: background 0.15s ease; }
        .report-table tbody tr:hover { background: color-mix(in srgb, var(--pharmacy-primary, #1a56db) 5%, var(--pharmacy-card-bg, #fff)); }
        /* Running balance row tinting */
        .row-net-positive td { background: color-mix(in srgb, #16a34a 6%, transparent) !important; }
        .row-net-negative td { background: color-mix(in srgb, #dc2626 6%, transparent) !important; }
        .closing-positive { color: #198754; font-weight: 700; }
        .closing-negative { color: #dc3545; font-weight: 700; }
        .chart-wrapper { position: relative; height: 320px; }
        .filter-bar {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: var(--card-border-radius, 12px);
            padding: 16px 20px;
            box-shadow: 0 1px 6px rgba(0,0,0,.06);
        }
        .filter-bar .form-control, .filter-bar .form-select {
            border-radius: 8px;
            border-color: var(--pharmacy-border, #d1d5db);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .filter-bar .form-control:focus, .filter-bar .form-select:focus {
            border-color: var(--pharmacy-primary, #1a56db);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #1a56db) 15%, transparent);
        }
        /* Breadcrumb */
        .bc-bar {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: 10px;
            padding: 10px 16px;
            margin-bottom: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,.05);
        }
        .bc-bar .breadcrumb { margin: 0; font-size: 0.82rem; }
        .bc-bar .breadcrumb-item a { color: var(--pharmacy-primary, #1a56db); text-decoration: none; }
        .bc-bar .breadcrumb-item.active { font-weight: 600; }
    </style>

</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content p-4">

    <!-- Breadcrumb -->
    <div class="bc-bar">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="<?php echo BASE_URL; ?>dashboard.php"><i class="bi bi-house me-1"></i>Dashboard</a>
            </li>
            <li class="breadcrumb-item">
                <a href="<?php echo BASE_URL; ?>cashbook/index.php">Cashbook</a>
            </li>
            <li class="breadcrumb-item active fw-semibold">Report</li>
        </ol>
    </div>

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold">
                <i class="bi bi-bar-chart-line text-primary me-2"></i>Cashbook Report
            </h4>
            <small class="text-muted">Date range ka daily cash flow summary</small>
        </div>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Daily View
        </a>
    </div>

    <!-- ── Filter Bar ─────────────────────── -->
    <div class="filter-bar mb-4">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-sm-4 col-md-3">
                <label class="form-label small fw-semibold mb-1">From Date</label>
                <input type="date" class="form-control form-control-sm"
                       name="from" value="<?= htmlspecialchars($fromDate) ?>"
                       max="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-sm-4 col-md-3">
                <label class="form-label small fw-semibold mb-1">To Date</label>
                <input type="date" class="form-control form-control-sm"
                       name="to" value="<?= htmlspecialchars($toDate) ?>"
                       max="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-sm-4 col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
            </div>
            <div class="col-sm-12 col-md-3">
                <a href="?from=<?= $fromDate ?>&to=<?= $toDate ?>&export=excel"
                   class="btn btn-outline-success btn-sm w-100">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export CSV
                </a>
            </div>
        </form>
    </div>

    <!-- ── Grand Summary Cards ─────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-4">
            <div class="report-card card h-100">
                <div class="summary-stat">
                    <div class="stat-value text-success"><?= formatCurrency($grandIn) ?></div>
                    <div class="stat-label"><i class="bi bi-arrow-down-circle me-1"></i>Total Cash In</div>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="report-card card h-100">
                <div class="summary-stat">
                    <div class="stat-value text-danger"><?= formatCurrency($grandOut) ?></div>
                    <div class="stat-label"><i class="bi bi-arrow-up-circle me-1"></i>Total Cash Out</div>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="report-card card h-100">
                <div class="summary-stat">
                    <?php $net = $grandIn - $grandOut; ?>
                    <div class="stat-value <?= $net >= 0 ? 'text-primary' : 'text-danger' ?>">
                        <?= formatCurrency($net) ?>
                    </div>
                    <div class="stat-label"><i class="bi bi-graph-up me-1"></i>Net Flow</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Chart ──────────────────────────── -->
    <?php if (!empty($report)): ?>
    <div class="report-card card mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-semibold">
                <i class="bi bi-graph-up-arrow me-2 text-primary"></i>
                Cash Flow Chart —
                <span class="text-muted fw-normal"><?= formatDate($fromDate) ?> to <?= formatDate($toDate) ?></span>
            </h6>
        </div>
        <div class="card-body">
            <div class="chart-wrapper">
                <canvas id="cashflowChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Daily Summary Table ────────────── -->
    <div class="report-card card">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold">
                <i class="bi bi-table me-2 text-primary"></i>Daily Summary
            </h6>
            <span class="badge bg-primary rounded-pill"><?= count($report) ?> din</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($report)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-calendar-x fs-2 d-block mb-2"></i>
                Is date range mein koi record nahi mila
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table report-table table-bordered mb-0" id="cashbookReportTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-end">Opening Balance</th>
                            <th class="text-end">Total In (Credit)</th>
                            <th class="text-end">Total Out (Debit)</th>
                            <th class="text-end">Net Flow</th>
                            <th class="text-end">Closing Balance</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report as $row):
                            $netFlow = $row['total_in'] - $row['total_out'];
                        ?>
                        <tr class="<?= $netFlow > 0 ? 'row-net-positive' : ($netFlow < 0 ? 'row-net-negative' : '') ?>">
                            <td class="fw-semibold"><?= formatDate($row['date']) ?></td>
                            <td class="text-end"><?= formatCurrency($row['opening']) ?></td>
                            <td class="text-end text-success fw-semibold">
                                <?= formatCurrency($row['total_in']) ?>
                            </td>
                            <td class="text-end text-danger fw-semibold">
                                <?= formatCurrency($row['total_out']) ?>
                            </td>
                            <td class="text-end">
                                <span class="<?= $netFlow >= 0 ? 'text-success' : 'text-danger' ?> fw-semibold">
                                    <?= ($netFlow >= 0 ? '+' : '') . formatCurrency($netFlow) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <span class="<?= $row['closing'] >= 0 ? 'closing-positive' : 'closing-negative' ?>">
                                    <?= formatCurrency($row['closing']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="index.php?date=<?= urlencode($row['date']) ?>"
                                   class="btn btn-outline-primary btn-sm py-0 px-2">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td class="fw-bold">Total (<?= count($report) ?> din)</td>
                            <td class="text-end">—</td>
                            <td class="text-end fw-bold text-success"><?= formatCurrency($grandIn) ?></td>
                            <td class="text-end fw-bold text-danger"><?= formatCurrency($grandOut) ?></td>
                            <td class="text-end fw-bold">
                                <?php $grandNet = $grandIn - $grandOut; ?>
                                <span class="<?= $grandNet >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= ($grandNet >= 0 ? '+' : '') . formatCurrency($grandNet) ?>
                                </span>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /main-content -->


<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<?php if (!empty($report)): ?>
<script>
const labels  = <?= json_encode(array_map('formatDate', $chartLabels)) ?>;
const cashIn  = <?= json_encode(array_map('floatval', $chartIn)) ?>;
const cashOut = <?= json_encode(array_map('floatval', $chartOut)) ?>;
const closing = <?= json_encode(array_map('floatval', $chartClosing)) ?>;

const ctx = document.getElementById('cashflowChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels,
        datasets: [
            {
                label: 'Cash In',
                data: cashIn,
                backgroundColor: 'rgba(25,135,84,.6)',
                borderColor: 'rgba(25,135,84,1)',
                borderWidth: 1,
                borderRadius: 4,
            },
            {
                label: 'Cash Out',
                data: cashOut,
                backgroundColor: 'rgba(220,53,69,.6)',
                borderColor: 'rgba(220,53,69,1)',
                borderWidth: 1,
                borderRadius: 4,
            },
            {
                label: 'Closing Balance',
                data: closing,
                type: 'line',
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13,110,253,.08)',
                pointBackgroundColor: '#0d6efd',
                pointRadius: 3,
                tension: .35,
                fill: true,
                yAxisID: 'y',
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label(ctx) {
                        return ` ${ctx.dataset.label}: Rs ${Number(ctx.raw).toLocaleString('en-PK', {minimumFractionDigits:2})}`;
                    }
                }
            }
        },
        scales: {
            y: {
                ticks: {
                    callback: val => 'Rs ' + val.toLocaleString('en-PK')
                }
            }
        }
    }
});
</script>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('cashbookReportTable')) {
        UniversalTable.init('cashbookReportTable', {
            title   : 'Cashbook Report',
            pageSize: 25
        });
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
