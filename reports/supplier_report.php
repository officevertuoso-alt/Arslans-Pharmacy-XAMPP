<?php
/**
 * reports/supplier_report.php
 * Step 13 — Supplier-wise Purchase & Payment Report (UI/UX Enhanced)
 */
require_once '../config/config.php';
require_once '../includes/purchase_functions.php';
if (file_exists('../includes/sync_engine.php')) {
    require_once '../includes/sync_engine.php';
}

requireLogin();

if (!canDo('reports', 'view')) {
    if (function_exists('setFlash')) setFlash('error', 'You do not have permission to view reports.');
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '../') . 'dashboard.php');
    exit;
}
// ── _getPDO / _pdoAll / _pdoOne fallback ─────────────────────────────────────
if (!function_exists('_getPDO')) {
    function _getPDO(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            if (function_exists('db')) {
                $dbObj = db();
                if (is_object($dbObj) && property_exists($dbObj, 'pdo'))            { $pdo = $dbObj->pdo; return $pdo; }
                if (is_object($dbObj) && method_exists($dbObj, 'getPDO'))           { $pdo = $dbObj->getPDO(); return $pdo; }
                if (is_object($dbObj) && method_exists($dbObj, 'getConnection'))    { $pdo = $dbObj->getConnection(); return $pdo; }
            }
            $pdo = new PDO(
                "mysql:host=" . (defined('DB_HOST') ? DB_HOST : 'localhost') .
                ";dbname=" . (defined('DB_NAME') ? DB_NAME : '') . ";charset=utf8mb4",
                defined('DB_USER') ? DB_USER : 'root',
                defined('DB_PASS') ? DB_PASS : '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        }
        return $pdo;
    }
}
if (!function_exists('_pdoAll')) {
    function _pdoAll(string $sql, array $p = []): array {
        $s = _getPDO()->prepare($sql); $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
if (!function_exists('_pdoOne')) {
    function _pdoOne(string $sql, array $p = []): array|null {
        $s = _getPDO()->prepare($sql); $s->execute($p);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$dateFrom = isset($_GET['from']) && $_GET['from'] ? $_GET['from'] : date('Y-m-01');
$dateTo   = isset($_GET['to'])   && $_GET['to']   ? $_GET['to']   : date('Y-m-d');
$search   = isset($_GET['search']) ? trim($_GET['search']) : '';

if (!strtotime($dateFrom)) $dateFrom = date('Y-m-01');
if (!strtotime($dateTo))   $dateTo   = date('Y-m-d');

// ── Build search WHERE ────────────────────────────────────────────────────────
$searchWhere = '';
$params = [':from' => $dateFrom, ':to' => $dateTo];
if ($search !== '') {
    $searchWhere = " AND (s.supplier_name LIKE :search OR s.phone LIKE :search2)";
    $params[':search']  = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
}

// ── Main query ────────────────────────────────────────────────────────────────
$suppliers = _pdoAll(
    "SELECT
        s.id,
        s.supplier_name,
        s.phone,
        s.email,
        s.current_balance,
        COUNT(DISTINCT p.id)                          AS purchase_count,
        COALESCE(SUM(p.total), 0)                     AS total_purchases,
        COALESCE(SUM(p.paid_amount), 0)               AS total_paid,
        COALESCE(SUM(p.due_amount), 0)                AS outstanding,
        COALESCE((
            SELECT SUM(pr.total_amount)
            FROM purchase_returns pr
            JOIN purchases p2 ON p2.id = pr.purchase_id
            WHERE p2.supplier_id = s.id
              AND DATE(p2.purchase_date) BETWEEN :from2 AND :to2
        ), 0)                                         AS total_returns,
        MAX(p.purchase_date)                          AS last_purchase
     FROM suppliers s
     LEFT JOIN purchases p
            ON p.supplier_id = s.id
           AND DATE(p.purchase_date) BETWEEN :from AND :to
     WHERE s.status = 1
       $searchWhere
     GROUP BY s.id
     ORDER BY outstanding DESC, total_purchases DESC",
    array_merge($params, [':from2' => $dateFrom, ':to2' => $dateTo])
);

// ── Totals ────────────────────────────────────────────────────────────────────
$totalSuppliers  = count($suppliers);
$totalPurchases  = array_sum(array_column($suppliers, 'total_purchases'));
$totalPaid       = array_sum(array_column($suppliers, 'total_paid'));
$totalOutstanding= array_sum(array_column($suppliers, 'outstanding'));
$totalReturns    = array_sum(array_column($suppliers, 'total_returns'));

// ── CSV Export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="supplier_report_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Supplier Name','Phone','Total Purchases','Total Amount (Rs)','Total Paid (Rs)','Outstanding (Rs)','Returns (Rs)','Last Purchase']);
    foreach ($suppliers as $s) {
        fputcsv($out, [
            $s['supplier_name'],
            $s['phone'] ?? '—',
            $s['purchase_count'],
            number_format($s['total_purchases'], 2, '.', ''),
            number_format($s['total_paid'],      2, '.', ''),
            number_format($s['outstanding'],     2, '.', ''),
            number_format($s['total_returns'],   2, '.', ''),
            $s['last_purchase'] ? date('d-m-Y', strtotime($s['last_purchase'])) : '—',
        ]);
    }
    fclose($out);
    exit;
}

// ── Step 7A: AJAX filter handler ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'filter') {
    $dateFrom = isset($_GET['from']) && $_GET['from'] ? $_GET['from'] : date('Y-m-01');
    $dateTo   = isset($_GET['to'])   && $_GET['to']   ? $_GET['to']   : date('Y-m-d');
    $search   = isset($_GET['search']) ? trim($_GET['search']) : '';
    if (!strtotime($dateFrom)) $dateFrom = date('Y-m-01');
    if (!strtotime($dateTo))   $dateTo   = date('Y-m-d');
    $searchWhere = '';
    $params = [':from' => $dateFrom, ':to' => $dateTo];
    if ($search !== '') {
        $searchWhere = " AND (s.supplier_name LIKE :search OR s.phone LIKE :search2)";
        $params[':search']  = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }
    $suppliers = _pdoAll(
        "SELECT s.id, s.supplier_name, s.phone, s.email, s.current_balance,
                COUNT(DISTINCT p.id) AS purchase_count, COALESCE(SUM(p.total), 0) AS total_purchases,
                COALESCE(SUM(p.paid_amount), 0) AS total_paid, COALESCE(SUM(p.due_amount), 0) AS outstanding,
                COALESCE((SELECT SUM(pr.total_amount) FROM purchase_returns pr JOIN purchases p2 ON p2.id = pr.purchase_id WHERE p2.supplier_id = s.id AND DATE(p2.purchase_date) BETWEEN :from2 AND :to2), 0) AS total_returns,
                MAX(p.purchase_date) AS last_purchase
         FROM suppliers s
         LEFT JOIN purchases p ON p.supplier_id = s.id AND DATE(p.purchase_date) BETWEEN :from AND :to
         WHERE s.status = 1 $searchWhere
         GROUP BY s.id
         ORDER BY outstanding DESC, total_purchases DESC",
        array_merge($params, [':from2' => $dateFrom, ':to2' => $dateTo])
    );
    $fc = function_exists('formatCurrency') ? 'formatCurrency' : function($a) { return 'Rs '.number_format((float)$a,2); };
    $fd = function_exists('formatDate') ? 'formatDate' : function($d) { return empty($d)?'':date('d-m-Y',strtotime($d)); };
    ob_start();
    if (empty($suppliers)) {
        echo '<tr><td colspan="10" class="text-center py-5" style="color:var(--pharmacy-text)"><i class="bi bi-inbox fs-3 d-block mb-2 opacity-50"></i>No suppliers found for the selected period.</td></tr>';
    } else {
        $i = 1;
        foreach ($suppliers as $sup) {
            $outstanding   = (float)$sup['outstanding'];
            $totalPurchAmt = (float)$sup['total_purchases'];
            $returns       = (float)$sup['total_returns'];
            echo '<tr>';
            echo '<td class="ps-3" style="color:var(--pharmacy-text);opacity:.6">' . $i++ . '</td>';
            echo '<td><strong style="color:var(--pharmacy-heading)">' . htmlspecialchars($sup['supplier_name']) . '</strong>';
            if ($outstanding > 0) echo ' <span class="status-badge badge-outstanding ms-1">Due</span>';
            if (!empty($sup['email'])) echo '<br><small style="color:var(--pharmacy-text);opacity:.6">' . htmlspecialchars($sup['email']) . '</small>';
            echo '</td>';
            echo '<td><small style="color:var(--pharmacy-text);opacity:.7">' . htmlspecialchars($sup['phone'] ?? '—') . '</small></td>';
            echo '<td class="text-center"><span class="badge bg-secondary">' . (int)$sup['purchase_count'] . '</span></td>';
            echo '<td class="text-end fw-semibold" style="color:var(--pharmacy-heading)">' . $fc($totalPurchAmt) . '</td>';
            echo '<td class="text-end" style="color:var(--pharmacy-success,#198754)">' . $fc($sup['total_paid']) . '</td>';
            echo '<td class="text-end ' . ($outstanding > 0 ? 'fw-bold' : '') . '" style="color:' . ($outstanding > 0 ? 'var(--pharmacy-danger,#dc3545)' : 'var(--pharmacy-text)') . ';opacity:' . ($outstanding > 0 ? '1' : '.5') . '">' . ($outstanding > 0 ? $fc($outstanding) : '—') . '</td>';
            echo '<td class="text-end" style="color:' . ($returns > 0 ? '#6f42c1' : 'var(--pharmacy-text)') . ';opacity:' . ($returns > 0 ? '1' : '.5') . '">' . ($returns > 0 ? $fc($returns) : '—') . '</td>';
            echo '<td><small style="color:var(--pharmacy-text);opacity:.7">' . (!empty($sup['last_purchase']) ? $fd($sup['last_purchase']) : '<span style="opacity:.4">No purchases</span>') . '</small></td>';
            echo '<td class="no-print text-center">';
            echo '<a href="../suppliers/view.php?id=' . (int)$sup['id'] . '" class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" title="View Supplier"><i class="bi bi-eye"></i></a>';
            echo '</td></tr>';
        }
    }
    $tableHtml = ob_get_clean();
    $totalPurchases   = array_sum(array_column($suppliers, 'total_purchases'));
    $totalPaid        = array_sum(array_column($suppliers, 'total_paid'));
    $totalOutstanding = array_sum(array_column($suppliers, 'outstanding'));
    $totalReturns     = array_sum(array_column($suppliers, 'total_returns'));
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html'    => $tableHtml,
        'total'   => count($suppliers),
        'stats'   => [
            'supplier_count'   => count($suppliers),
            'total_purchases'  => $fc($totalPurchases),
            'total_paid'       => $fc($totalPaid),
            'total_outstanding'=> $fc($totalOutstanding),
            'total_returns'    => $fc($totalReturns),
        ],
    ]);
    exit;
}

logActivity('View Report', "Supplier Report: {$dateFrom} to {$dateTo}");
$page_title = 'Supplier Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    
    <style>
        /* ── Page fade-in ─────────────────────────────────────────────────────── */
        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .main-content {
            margin-left: var(--sb-w, 260px);
            padding: 20px;
            animation: fadeInPage 0.28s ease;
            transition: margin-left 0.3s ease;
        }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

        /* ── Stat Cards ────────────────────────────────────────────────────────── */
        .stat-card {
            border-radius: var(--card-border-radius, 12px);
            border-left: 4px solid var(--pharmacy-primary, #0d6efd);
            background: var(--pharmacy-card-bg, #fff);
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            padding: 20px 24px;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 28px rgba(0,0,0,0.13);
        }
        .stat-card.blue   { border-left-color: var(--pharmacy-primary,  #0d6efd); }
        .stat-card.orange { border-left-color: var(--pharmacy-warning,  #fd7e14); }
        .stat-card.green  { border-left-color: var(--pharmacy-success,  #198754); }
        .stat-card.red    { border-left-color: var(--pharmacy-danger,   #dc3545); }
        .stat-card.purple { border-left-color: #6f42c1; }
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--pharmacy-heading, #212529);
        }
        .stat-card .stat-label {
            font-size: 0.72rem;
            color: color-mix(in srgb, var(--pharmacy-text, #495057) 65%, transparent);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card .stat-icon {
            font-size: 2.4rem;
            opacity: 0.13;
            color: var(--pharmacy-primary, #0d6efd);
        }

        /* ── Filter Bar ────────────────────────────────────────────────────────── */
        .filter-bar {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #dee2e6);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 16px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.06);
        }
        .filter-pill {
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid var(--pharmacy-border, #dee2e6);
            background: var(--pharmacy-card-bg, #fff);
            color: var(--pharmacy-text, #495057);
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            display: inline-block;
        }
        .filter-pill.active,
        .filter-pill:hover {
            background: var(--pharmacy-primary, #0d6efd);
            color: #fff;
            border-color: var(--pharmacy-primary, #0d6efd);
        }

        /* ── Table Card ────────────────────────────────────────────────────────── */
        .table-card {
            border-radius: var(--card-border-radius, 12px);
            overflow: hidden;
            border: 1px solid var(--pharmacy-border, #dee2e6);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            background: var(--pharmacy-card-bg, #fff);
        }
        .table-card .card-header {
            background: var(--pharmacy-table-th, #f8f9fa) !important;
            border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important;
            padding: 12px 18px;
        }
        .table-card .table th {
            background: var(--pharmacy-table-th, #f8f9fa) !important;
            color: var(--pharmacy-heading, #212529) !important;
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 700;
            padding: 11px 14px;
            border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important;
            white-space: nowrap;
        }
        .table-card .table td {
            padding: 10px 14px;
            vertical-align: middle;
            color: var(--pharmacy-text, #495057) !important;
            border-color: var(--pharmacy-border, #dee2e6) !important;
        }
        .table-card .table tbody tr {
            transition: background 0.15s ease;
        }
        .table-card .table-hover tbody tr:hover td {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 6%, var(--pharmacy-card-bg, #fff)) !important;
        }
        .table-card tfoot td {
            background: var(--pharmacy-table-th, #f8f9fa) !important;
            color: var(--pharmacy-heading, #212529) !important;
            font-weight: 700;
            border-top: 2px solid var(--pharmacy-border, #dee2e6) !important;
        }

        /* ── Status Badges ─────────────────────────────────────────────────────── */
        .status-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            display: inline-block;
        }
        .badge-outstanding {
            background: var(--pharmacy-badge-oos-bg,      #fee2e2);
            color:      var(--pharmacy-badge-oos-text,     #991b1b);
        }
        .badge-clear {
            background: var(--pharmacy-badge-instock-bg,   #d1fae5);
            color:      var(--pharmacy-badge-instock-text,  #065f46);
        }
        .badge-returns {
            background: #ede9fe;
            color: #5b21b6;
        }

        /* ── Headings ──────────────────────────────────────────────────────────── */
        h1, h2, h3, h4, h5, h6, .card-title, .section-title {
            color: var(--pharmacy-heading, #212529) !important;
        }
        .text-muted-label {
            color: color-mix(in srgb, var(--pharmacy-text, #495057) 65%, transparent);
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ── Cards generic ─────────────────────────────────────────────────────── */
        .card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #dee2e6);
            border-radius: var(--card-border-radius, 12px);
            transition: box-shadow 0.2s ease;
        }
        .card .card-header {
            background: var(--pharmacy-table-th, #f8f9fa) !important;
            border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important;
            color: var(--pharmacy-heading, #212529) !important;
        }

        /* ── Smooth transitions on buttons ─────────────────────────────────────── */
        .btn { transition: all 0.2s ease; }
        .form-control, .form-select { transition: border-color 0.2s ease, box-shadow 0.2s ease; }

        /* ── Print ─────────────────────────────────────────────────────────────── */
        @media print {
            .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; }
        }
    </style>
</head>
<?php $__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : ""; ?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid py-3">

    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 no-print">
        <div>
            <h4 class="fw-bold mb-0">
                <i class="bi bi-building me-2 text-primary"></i><?= htmlspecialchars($page_title) ?>
            </h4>
            <small class="text-muted-label">
                Supplier-wise Purchase &amp; Payment Analysis &nbsp;|&nbsp;
                <?= function_exists('formatDate') ? formatDate($dateFrom) : date('d M Y', strtotime($dateFrom)) ?>
                &mdash;
                <?= function_exists('formatDate') ? formatDate($dateTo)   : date('d M Y', strtotime($dateTo)) ?>
            </small>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-2 mt-sm-0 no-print">
            <a href="?from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>&search=<?= urlencode($search) ?>&export=csv"
               class="btn btn-success btn-sm">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <!-- ── Filters ─────────────────────────────────────────────────────────── -->
    <div class="filter-bar mb-4 no-print">
        <form method="GET" id="filterForm" class="row g-3 align-items-end">
            <div class="col-md-3 col-sm-6">
                <label class="form-label fw-semibold small mb-1" style="color:var(--pharmacy-text)">From Date</label>
                <input type="date" name="from" id="fromDate" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-md-3 col-sm-6">
                <label class="form-label fw-semibold small mb-1" style="color:var(--pharmacy-text)">To Date</label>
                <input type="date" name="to" id="toDate" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-md-4 col-sm-8">
                <label class="form-label fw-semibold small mb-1" style="color:var(--pharmacy-text)">Search Supplier</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Name or phone..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2 col-sm-4">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search me-1"></i>Apply
                </button>
            </div>
        </form>
        <!-- Quick date shortcut pills -->
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <?php
            $shortcuts = [
                'This Month'   => [date('Y-m-01'), date('Y-m-d')],
                'Last Month'   => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                'This Year'    => [date('Y-01-01'), date('Y-m-d')],
                'Last 30 Days' => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
            ];
            foreach ($shortcuts as $label => [$f, $t]):
                $isActive = ($dateFrom === $f && $dateTo === $t);
            ?>
            <a href="?from=<?= $f ?>&to=<?= $t ?>&search=<?= urlencode($search) ?>"
               class="filter-pill <?= $isActive ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Summary Stat Cards ──────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <!-- Total Suppliers -->
        <div class="col-xl col-md-4 col-sm-6">
            <div class="stat-card blue h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Total Suppliers</div>
                        <div class="stat-value"><?= number_format($totalSuppliers) ?></div>
                        <small class="text-muted">Active suppliers</small>
                    </div>
                    <i class="bi bi-building stat-icon"></i>
                </div>
            </div>
        </div>
        <!-- Total Purchases -->
        <div class="col-xl col-md-4 col-sm-6">
            <div class="stat-card orange h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Total Purchases</div>
                        <div class="stat-value" style="font-size:1.4rem"><?= function_exists('formatCurrency') ? formatCurrency($totalPurchases) : 'Rs '.number_format($totalPurchases,2) ?></div>
                        <small class="text-muted">In selected period</small>
                    </div>
                    <i class="bi bi-cart stat-icon" style="color:var(--pharmacy-warning,#fd7e14)"></i>
                </div>
            </div>
        </div>
        <!-- Total Paid -->
        <div class="col-xl col-md-4 col-sm-6">
            <div class="stat-card green h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Total Paid</div>
                        <div class="stat-value" style="font-size:1.4rem; color:var(--pharmacy-success,#198754)"><?= function_exists('formatCurrency') ? formatCurrency($totalPaid) : 'Rs '.number_format($totalPaid,2) ?></div>
                        <small class="text-muted">Payments made</small>
                    </div>
                    <i class="bi bi-check-circle stat-icon" style="color:var(--pharmacy-success,#198754)"></i>
                </div>
            </div>
        </div>
        <!-- Outstanding -->
        <div class="col-xl col-md-4 col-sm-6">
            <div class="stat-card red h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Total Outstanding</div>
                        <div class="stat-value" style="font-size:1.4rem; color:var(--pharmacy-danger,#dc3545)"><?= function_exists('formatCurrency') ? formatCurrency($totalOutstanding) : 'Rs '.number_format($totalOutstanding,2) ?></div>
                        <small class="text-muted">Pending dues</small>
                    </div>
                    <i class="bi bi-exclamation-triangle stat-icon" style="color:var(--pharmacy-danger,#dc3545)"></i>
                </div>
            </div>
        </div>
        <!-- Returns -->
        <div class="col-xl col-md-4 col-sm-6">
            <div class="stat-card purple h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Total Returns</div>
                        <div class="stat-value" style="font-size:1.4rem; color:#6f42c1"><?= function_exists('formatCurrency') ? formatCurrency($totalReturns) : 'Rs '.number_format($totalReturns,2) ?></div>
                        <small class="text-muted">Purchase returns</small>
                    </div>
                    <i class="bi bi-arrow-return-left stat-icon" style="color:#6f42c1"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Supplier Table ──────────────────────────────────────────────────── -->
    <div class="table-card mb-4" id="supplier-table-container">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold" style="color:var(--pharmacy-heading)">
                <i class="bi bi-table text-primary me-1"></i>Supplier-wise Report
            </span>
            <small style="color:var(--pharmacy-text);opacity:.65"><?= number_format($totalSuppliers) ?> supplier(s) found</small>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="reportTable">
                <thead>
                    <tr>
                        <th class="ps-3">#</th>
                        <th>Supplier Name</th>
                        <th>Phone</th>
                        <th class="text-center">Purchases</th>
                        <th class="text-end">Total Amount</th>
                        <th class="text-end">Total Paid</th>
                        <th class="text-end">Outstanding</th>
                        <th class="text-end">Returns</th>
                        <th>Last Purchase</th>
                        <th class="no-print text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="reportTableBody">
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5" style="color:var(--pharmacy-text)">
                                <i class="bi bi-inbox fs-3 d-block mb-2 opacity-50"></i>
                                No suppliers found for the selected period.
                            </td>
                        </tr>
                    <?php else:
                        $i = 1;
                        foreach ($suppliers as $sup):
                            $outstanding   = (float)$sup['outstanding'];
                            $totalPurchAmt = (float)$sup['total_purchases'];
                            $returns       = (float)$sup['total_returns'];
                    ?>
                    <tr>
                        <td class="ps-3" style="color:var(--pharmacy-text);opacity:.6"><?= $i++ ?></td>
                        <td>
                            <strong style="color:var(--pharmacy-heading)"><?= htmlspecialchars($sup['supplier_name']) ?></strong>
                            <?php if ($outstanding > 0): ?>
                                <span class="status-badge badge-outstanding ms-1">Due</span>
                            <?php endif; ?>
                            <?php if (!empty($sup['email'])): ?>
                                <br><small style="color:var(--pharmacy-text);opacity:.6"><?= htmlspecialchars($sup['email']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><small style="color:var(--pharmacy-text);opacity:.7"><?= htmlspecialchars($sup['phone'] ?? '—') ?></small></td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= (int)$sup['purchase_count'] ?></span>
                        </td>
                        <td class="text-end fw-semibold" style="color:var(--pharmacy-heading)">
                            <?= function_exists('formatCurrency') ? formatCurrency($totalPurchAmt) : 'Rs '.number_format($totalPurchAmt,2) ?>
                        </td>
                        <td class="text-end" style="color:var(--pharmacy-success,#198754)">
                            <?= function_exists('formatCurrency') ? formatCurrency($sup['total_paid']) : 'Rs '.number_format($sup['total_paid'],2) ?>
                        </td>
                        <td class="text-end <?= $outstanding > 0 ? 'fw-bold' : '' ?>"
                            style="color:<?= $outstanding > 0 ? 'var(--pharmacy-danger,#dc3545)' : 'var(--pharmacy-text)' ?>; opacity:<?= $outstanding > 0 ? '1' : '.5' ?>">
                            <?= $outstanding > 0 ? (function_exists('formatCurrency') ? formatCurrency($outstanding) : 'Rs '.number_format($outstanding,2)) : '—' ?>
                        </td>
                        <td class="text-end" style="color:<?= $returns > 0 ? '#6f42c1' : 'var(--pharmacy-text)' ?>; opacity:<?= $returns > 0 ? '1' : '.5' ?>">
                            <?= $returns > 0 ? (function_exists('formatCurrency') ? formatCurrency($returns) : 'Rs '.number_format($returns,2)) : '—' ?>
                        </td>
                        <td>
                            <small style="color:var(--pharmacy-text);opacity:.7">
                                <?php
                                if ($sup['last_purchase']) {
                                    echo function_exists('formatDate')
                                        ? formatDate($sup['last_purchase'])
                                        : date('d-m-Y', strtotime($sup['last_purchase']));
                                } else {
                                    echo '<span style="opacity:.4">No purchases</span>';
                                }
                                ?>
                            </small>
                        </td>
                        <td class="no-print text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <a href="<?= BASE_URL ?>suppliers/view.php?id=<?= $sup['id'] ?>"
                                   class="btn btn-primary btn-sm"
                                   data-bs-toggle="tooltip" title="View Supplier">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="<?= BASE_URL ?>suppliers/statement.php?supplier_id=<?= $sup['id'] ?>"
                                   class="btn btn-outline-secondary btn-sm"
                                   data-bs-toggle="tooltip" title="View Statement">
                                    <i class="bi bi-file-text"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($suppliers)): ?>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-end ps-3">Totals</td>
                        <td class="text-end"><?= function_exists('formatCurrency') ? formatCurrency($totalPurchases) : 'Rs '.number_format($totalPurchases,2) ?></td>
                        <td class="text-end" style="color:var(--pharmacy-success,#198754)"><?= function_exists('formatCurrency') ? formatCurrency($totalPaid) : 'Rs '.number_format($totalPaid,2) ?></td>
                        <td class="text-end" style="color:var(--pharmacy-danger,#dc3545)"><?= function_exists('formatCurrency') ? formatCurrency($totalOutstanding) : 'Rs '.number_format($totalOutstanding,2) ?></td>
                        <td class="text-end" style="color:#6f42c1"><?= function_exists('formatCurrency') ? formatCurrency($totalReturns) : 'Rs '.number_format($totalReturns,2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div><!-- /container-fluid -->
</div><!-- /main-content -->



<script>
// ── Tooltip init ─────────────────────────────────────────────────────────────
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el, { trigger: 'hover' });
});

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
                    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                        new bootstrap.Tooltip(el, { trigger: 'hover' });
                    });
                    history.replaceState({}, '', '?' + params.toString().replace('&ajax=filter',''));
                }
            })
            .finally(() => { document.getElementById('reportTable').style.opacity = '1'; });
    }, 400);
}

function updateReportStats(stats) {
    const statCards = document.querySelectorAll('.stat-card .stat-value');
    const keys = ['supplier_count','total_purchases','total_paid','total_outstanding','total_returns'];
    keys.forEach((key, idx) => {
        if (statCards[idx] && stats[key] !== undefined) statCards[idx].textContent = stats[key];
    });
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
            title      : 'Supplier Report',
            pageSize   : 25,
            excludeCols: [9]
        });
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
