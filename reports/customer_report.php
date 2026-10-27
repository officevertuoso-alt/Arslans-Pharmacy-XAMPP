<?php
/**
 * reports/customer_report.php
 * Step 13 — Customer-wise Sales & Credit Report (UI/UX Enhanced)
 */
require_once '../config/config.php';
if (file_exists('../includes/sync_engine.php')) {
    require_once '../includes/sync_engine.php';
}

requireLogin();

if (!canDo('reports', 'view')) {
    if (function_exists('setFlash')) setFlash('error', 'You do not have permission to view reports.');
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '../') . 'dashboard.php');
    exit;
}
if (!defined('APP_NAME')) {
    define('APP_NAME', defined('SITE_NAME') ? SITE_NAME : 'Pharmacy');
}

// ── Step 7A: AJAX filter handler ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'filter') {
    $dateFrom = isset($_GET['from']) && $_GET['from'] ? $_GET['from'] : date('Y-m-01');
    $dateTo   = isset($_GET['to'])   && $_GET['to']   ? $_GET['to']   : date('Y-m-d');
    $search   = isset($_GET['search']) ? trim($_GET['search']) : '';
    if (!function_exists('_getPDO')) {
        function _getPDO(): PDO {
            static $pdo = null;
            if ($pdo === null) {
                if (function_exists('db')) {
                    $dbObj = db();
                    if (is_object($dbObj) && property_exists($dbObj, 'pdo'))          { $pdo = $dbObj->pdo; return $pdo; }
                    if (is_object($dbObj) && method_exists($dbObj, 'getPDO'))         { $pdo = $dbObj->getPDO(); return $pdo; }
                    if (is_object($dbObj) && method_exists($dbObj, 'getConnection'))  { $pdo = $dbObj->getConnection(); return $pdo; }
                }
                $pdo = new PDO("mysql:host=".(defined('DB_HOST')?DB_HOST:'localhost').";dbname=".(defined('DB_NAME')?DB_NAME:'').";charset=utf8mb4",defined('DB_USER')?DB_USER:'root',defined('DB_PASS')?DB_PASS:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            }
            return $pdo;
        }
    }
    if (!function_exists('_pdoAll')) {
        function _pdoAll(string $sql, array $p = []): array { $s = _getPDO()->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    }
    $params = [':from' => $dateFrom, ':to' => $dateTo];
    $searchWhere = '';
    if ($search !== '') {
        $searchWhere = " AND (s.customer_name LIKE :search OR s.customer_phone LIKE :search2)";
        $params[':search']  = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }
    $customers = _pdoAll(
        "SELECT COALESCE(s.customer_name, cc.customer_name) AS name, COALESCE(s.customer_phone, cc.customer_phone) AS phone,
                COUNT(DISTINCT s.id) AS sale_count, COALESCE(SUM(DISTINCT s.total), 0) AS total_sales,
                COALESCE((SELECT SUM(cc2.total_amount) FROM customer_credits cc2 WHERE cc2.customer_phone COLLATE utf8mb4_general_ci = COALESCE(s.customer_phone, cc.customer_phone) COLLATE utf8mb4_general_ci), 0) AS total_credit,
                COALESCE((SELECT SUM(cp.amount) FROM credit_payments cp JOIN customer_credits cc3 ON cc3.id = cp.credit_id WHERE cc3.customer_phone COLLATE utf8mb4_general_ci = COALESCE(s.customer_phone, cc.customer_phone) COLLATE utf8mb4_general_ci), 0) AS credit_paid,
                COALESCE((SELECT SUM(cc4.total_amount) FROM customer_credits cc4 WHERE cc4.customer_phone COLLATE utf8mb4_general_ci = COALESCE(s.customer_phone, cc.customer_phone) COLLATE utf8mb4_general_ci AND cc4.status IN ('active','overdue')), 0) AS outstanding,
                MAX(s.sale_date) AS last_purchase
         FROM sales s
         LEFT JOIN customer_credits cc ON cc.customer_phone COLLATE utf8mb4_general_ci = s.customer_phone COLLATE utf8mb4_general_ci
         WHERE DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided' $searchWhere
         GROUP BY COALESCE(s.customer_phone COLLATE utf8mb4_general_ci, cc.customer_phone COLLATE utf8mb4_general_ci)
         ORDER BY outstanding DESC, total_sales DESC",
        $params
    );
    $fc = function_exists('formatCurrency') ? 'formatCurrency' : function($a) { return 'Rs '.number_format((float)$a,2); };
    $fd = function_exists('formatDate') ? 'formatDate' : function($d) { return empty($d)?'':date('d-m-Y',strtotime($d)); };
    ob_start();
    if (empty($customers)) {
        echo '<tr><td colspan="10" class="text-center py-5" style="color:var(--pharmacy-text)"><i class="bi bi-inbox fs-3 d-block mb-2 opacity-50"></i>No customers found for the selected period.</td></tr>';
    } else {
        $i = 1;
        foreach ($customers as $c) {
            $outstanding = (float)$c['outstanding'];
            $creditPaid  = (float)$c['credit_paid'];
            $creditGiven = (float)$c['total_credit'];
            echo '<tr>';
            echo '<td class="ps-3" style="color:var(--pharmacy-text);opacity:.6">' . $i++ . '</td>';
            echo '<td><strong style="color:var(--pharmacy-heading)">' . htmlspecialchars($c['name'] ?? '—') . '</strong>';
            if ($outstanding > 0) echo ' <span class="status-badge badge-outstanding ms-1">Due</span>';
            echo '</td>';
            echo '<td><small style="color:var(--pharmacy-text);opacity:.7">' . htmlspecialchars($c['phone'] ?? '—') . '</small></td>';
            echo '<td class="text-center"><span class="badge bg-secondary">' . (int)$c['sale_count'] . '</span></td>';
            echo '<td class="text-end fw-semibold" style="color:var(--pharmacy-heading)">' . $fc($c['total_sales']) . '</td>';
            echo '<td class="text-end">' . ($creditGiven > 0 ? $fc($creditGiven) : '<span style="opacity:.4">—</span>') . '</td>';
            echo '<td class="text-end" style="color:var(--pharmacy-success,#198754)">' . ($creditPaid > 0 ? $fc($creditPaid) : '<span style="opacity:.4">—</span>') . '</td>';
            echo '<td class="text-end ' . ($outstanding > 0 ? 'fw-bold' : '') . '" style="color:' . ($outstanding > 0 ? 'var(--pharmacy-danger,#dc3545)' : 'var(--pharmacy-text)') . ';opacity:' . ($outstanding > 0 ? '1' : '.5') . '">' . ($outstanding > 0 ? $fc($outstanding) : '—') . '</td>';
            echo '<td><small style="color:var(--pharmacy-text);opacity:.7">' . (!empty($c['last_purchase']) ? $fd($c['last_purchase']) : '—') . '</small></td>';
            echo '<td class="no-print text-center">';
            if (!empty($c['phone'])) echo '<a href="' . BASE_URL . 'sales/customer_view.php?phone=' . urlencode($c['phone']) . '" class="btn btn-primary btn-sm"><i class="bi bi-eye me-1"></i>View</a>';
            else echo '<span style="opacity:.4;font-size:.8rem">—</span>';
            echo '</td></tr>';
        }
    }
    $tableHtml = ob_get_clean();
    $totalRevenue     = array_sum(array_column($customers, 'total_sales'));
    $totalCreditGiven = array_sum(array_column($customers, 'total_credit'));
    $totalCreditPaid  = array_sum(array_column($customers, 'credit_paid'));
    $totalOutstanding = array_sum(array_column($customers, 'outstanding'));
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html'    => $tableHtml,
        'total'   => count($customers),
        'stats'   => [
            'total_revenue'     => $fc($totalRevenue),
            'total_credit_given'=> $fc($totalCreditGiven),
            'total_credit_paid' => $fc($totalCreditPaid),
            'total_outstanding' => $fc($totalOutstanding),
            'customer_count'    => count($customers),
        ],
    ]);
    exit;
}

$dateFrom = isset($_GET['from']) && $_GET['from'] ? $_GET['from'] : date('Y-m-01');
$dateTo   = isset($_GET['to'])   && $_GET['to']   ? $_GET['to']   : date('Y-m-d');
$search   = isset($_GET['search']) ? trim($_GET['search']) : '';

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

$params = [':from' => $dateFrom, ':to' => $dateTo];
$searchWhere = '';
if ($search !== '') {
    $searchWhere = " AND (s.customer_name LIKE :search OR s.customer_phone LIKE :search2)";
    $params[':search']  = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
}

$customers = _pdoAll(
    "SELECT
        COALESCE(s.customer_name, cc.customer_name)   AS name,
        COALESCE(s.customer_phone, cc.customer_phone) AS phone,
        COUNT(DISTINCT s.id)                           AS sale_count,
        COALESCE(SUM(DISTINCT s.total), 0)             AS total_sales,
        COALESCE((
            SELECT SUM(cc2.total_amount)
            FROM customer_credits cc2
            WHERE cc2.customer_phone COLLATE utf8mb4_general_ci
                = COALESCE(s.customer_phone, cc.customer_phone) COLLATE utf8mb4_general_ci
        ), 0) AS total_credit,
        COALESCE((
            SELECT SUM(cp.amount)
            FROM credit_payments cp
            JOIN customer_credits cc3 ON cc3.id = cp.credit_id
            WHERE cc3.customer_phone COLLATE utf8mb4_general_ci
                = COALESCE(s.customer_phone, cc.customer_phone) COLLATE utf8mb4_general_ci
        ), 0) AS credit_paid,
        COALESCE((
            SELECT SUM(cc4.total_amount)
            FROM customer_credits cc4
            WHERE cc4.customer_phone COLLATE utf8mb4_general_ci
                = COALESCE(s.customer_phone, cc.customer_phone) COLLATE utf8mb4_general_ci
              AND cc4.status IN ('active','overdue')
        ), 0) AS outstanding,
        MAX(s.sale_date) AS last_purchase
     FROM sales s
     LEFT JOIN customer_credits cc
            ON cc.customer_phone COLLATE utf8mb4_general_ci
             = s.customer_phone  COLLATE utf8mb4_general_ci
     WHERE DATE(s.sale_date) BETWEEN :from AND :to
       AND s.status != 'voided'
       $searchWhere
     GROUP BY COALESCE(s.customer_phone COLLATE utf8mb4_general_ci,
                       cc.customer_phone COLLATE utf8mb4_general_ci)
     ORDER BY outstanding DESC, total_sales DESC",
    $params
);

$totalCustomers   = count($customers);
$totalRevenue     = array_sum(array_column($customers, 'total_sales'));
$totalCreditGiven = array_sum(array_column($customers, 'total_credit'));
$totalOutstanding = array_sum(array_column($customers, 'outstanding'));

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customer_report_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Customer Name','Phone','Total Sales','Total Amount (Rs)','Credit Given (Rs)','Credit Paid (Rs)','Outstanding (Rs)','Last Purchase']);
    foreach ($customers as $c) {
        fputcsv($out, [
            $c['name']        ?? '—',
            $c['phone']       ?? '—',
            $c['sale_count'],
            number_format($c['total_sales'],  2, '.', ''),
            number_format($c['total_credit'], 2, '.', ''),
            number_format($c['credit_paid'],  2, '.', ''),
            number_format($c['outstanding'],  2, '.', ''),
            $c['last_purchase'] ? date('d-m-Y', strtotime($c['last_purchase'])) : '—',
        ]);
    }
    fclose($out);
    exit;
}

// Top 10 for chart
$top10 = array_slice($customers, 0, 10);
usort($top10, fn($a, $b) => $b['total_sales'] <=> $a['total_sales']);
$chartLabels      = array_map(fn($c) => $c['name'] ?? $c['phone'] ?? 'Unknown', $top10);
$chartSales       = array_map(fn($c) => round((float)$c['total_sales'], 2), $top10);
$chartOutstanding = array_map(fn($c) => round((float)$c['outstanding'], 2), $top10);

$pageTitle = 'Customer Report';
$page_title = $pageTitle;
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
        .stat-card.blue   { border-left-color: var(--pharmacy-primary,   #0d6efd); }
        .stat-card.green  { border-left-color: var(--pharmacy-success,   #198754); }
        .stat-card.orange { border-left-color: var(--pharmacy-warning,   #fd7e14); }
        .stat-card.red    { border-left-color: var(--pharmacy-danger,    #dc3545); }
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
            background: var(--pharmacy-badge-oos-bg,     #fee2e2);
            color:      var(--pharmacy-badge-oos-text,    #991b1b);
        }
        .badge-clear {
            background: var(--pharmacy-badge-instock-bg,  #d1fae5);
            color:      var(--pharmacy-badge-instock-text, #065f46);
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
            <h4 class="mb-0 fw-bold">
                <i class="bi bi-people-fill text-primary me-2"></i>Customer Report
            </h4>
            <small class="text-muted-label">Customer-wise Sales &amp; Credit Analysis
                &nbsp;|&nbsp;
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

    <?php
    if (function_exists('getFlash')) {
        echo renderFlashAll();
    }
    ?>

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
                <label class="form-label fw-semibold small mb-1" style="color:var(--pharmacy-text)">Search Customer</label>
                <input type="text" name="search" id="searchInput" class="form-control form-control-sm"
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
        <!-- Total Customers -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card blue h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Total Customers</div>
                        <div class="stat-value"><?= number_format($totalCustomers) ?></div>
                        <small class="text-muted">In selected period</small>
                    </div>
                    <i class="bi bi-people stat-icon"></i>
                </div>
            </div>
        </div>
        <!-- Total Revenue -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card green h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Total Revenue</div>
                        <div class="stat-value" style="font-size:1.5rem"><?= function_exists('formatCurrency') ? formatCurrency($totalRevenue) : 'Rs '.number_format($totalRevenue, 2) ?></div>
                        <small class="text-muted">Cash + Credit sales</small>
                    </div>
                    <i class="bi bi-currency-rupee stat-icon"></i>
                </div>
            </div>
        </div>
        <!-- Credit Given -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card orange h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Total Credit Given</div>
                        <div class="stat-value" style="font-size:1.5rem"><?= function_exists('formatCurrency') ? formatCurrency($totalCreditGiven) : 'Rs '.number_format($totalCreditGiven, 2) ?></div>
                        <small class="text-muted">All time credits</small>
                    </div>
                    <i class="bi bi-credit-card stat-icon"></i>
                </div>
            </div>
        </div>
        <!-- Outstanding -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card red h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label mb-1">Outstanding Balance</div>
                        <div class="stat-value" style="font-size:1.5rem; color:var(--pharmacy-danger,#dc3545)"><?= function_exists('formatCurrency') ? formatCurrency($totalOutstanding) : 'Rs '.number_format($totalOutstanding, 2) ?></div>
                        <small class="text-muted">Active + Overdue</small>
                    </div>
                    <i class="bi bi-exclamation-triangle stat-icon" style="color:var(--pharmacy-danger,#dc3545)"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Chart: Top 10 Customers ─────────────────────────────────────────── -->
    <?php if (!empty($top10)): ?>
    <div class="table-card mb-4 no-print">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-bar-chart-horizontal text-primary"></i>
            <span class="fw-semibold" style="color:var(--pharmacy-heading)">Top 10 Customers by Sales</span>
        </div>
        <div class="p-3">
            <canvas id="customerChart" style="max-height:320px;"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Customer Table ──────────────────────────────────────────────────── -->
    <div class="table-card mb-4" id="customer-table-container">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold" style="color:var(--pharmacy-heading)">
                <i class="bi bi-table text-primary me-1"></i>Customer-wise Report
            </span>
            <small class="text-muted"><?= number_format($totalCustomers) ?> customer(s) found</small>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="reportTable">
                <thead>
                    <tr>
                        <th class="ps-3">#</th>
                        <th>Customer Name</th>
                        <th>Phone</th>
                        <th class="text-center">Sales</th>
                        <th class="text-end">Total Amount</th>
                        <th class="text-end">Credit Given</th>
                        <th class="text-end">Credit Paid</th>
                        <th class="text-end">Outstanding</th>
                        <th>Last Purchase</th>
                        <th class="no-print text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="reportTableBody">
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5" style="color:var(--pharmacy-text)">
                                <i class="bi bi-inbox fs-3 d-block mb-2 opacity-50"></i>
                                No customers found for the selected period.
                            </td>
                        </tr>
                    <?php else:
                        $i = 1;
                        foreach ($customers as $c):
                            $outstanding = (float)$c['outstanding'];
                            $creditPaid  = (float)$c['credit_paid'];
                            $creditGiven = (float)$c['total_credit'];
                    ?>
                    <tr>
                        <td class="ps-3" style="color:var(--pharmacy-text);opacity:.6"><?= $i++ ?></td>
                        <td>
                            <strong style="color:var(--pharmacy-heading)"><?= htmlspecialchars($c['name'] ?? '—') ?></strong>
                            <?php if ($outstanding > 0): ?>
                                <span class="status-badge badge-outstanding ms-1">Due</span>
                            <?php endif; ?>
                        </td>
                        <td><small style="color:var(--pharmacy-text);opacity:.7"><?= htmlspecialchars($c['phone'] ?? '—') ?></small></td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= (int)$c['sale_count'] ?></span>
                        </td>
                        <td class="text-end fw-semibold" style="color:var(--pharmacy-heading)">
                            <?= function_exists('formatCurrency') ? formatCurrency($c['total_sales']) : 'Rs '.number_format($c['total_sales'],2) ?>
                        </td>
                        <td class="text-end">
                            <?php if ($creditGiven > 0): ?>
                                <?= function_exists('formatCurrency') ? formatCurrency($creditGiven) : 'Rs '.number_format($creditGiven,2) ?>
                            <?php else: ?>
                                <span style="opacity:.4">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end" style="color:var(--pharmacy-success,#198754)">
                            <?php if ($creditPaid > 0): ?>
                                <?= function_exists('formatCurrency') ? formatCurrency($creditPaid) : 'Rs '.number_format($creditPaid,2) ?>
                            <?php else: ?>
                                <span style="opacity:.4">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end <?= $outstanding > 0 ? 'fw-bold' : '' ?>"
                            style="color:<?= $outstanding > 0 ? 'var(--pharmacy-danger,#dc3545)' : 'var(--pharmacy-text)' ?>; opacity:<?= $outstanding > 0 ? '1' : '.5' ?>">
                            <?= $outstanding > 0 ? (function_exists('formatCurrency') ? formatCurrency($outstanding) : 'Rs '.number_format($outstanding,2)) : '—' ?>
                        </td>
                        <td>
                            <small style="color:var(--pharmacy-text);opacity:.7">
                                <?php
                                if ($c['last_purchase']) {
                                    echo function_exists('formatDate')
                                        ? formatDate($c['last_purchase'])
                                        : date('d-m-Y', strtotime($c['last_purchase']));
                                } else {
                                    echo '—';
                                }
                                ?>
                            </small>
                        </td>
                        <td class="no-print text-center">
                            <?php if (!empty($c['phone'])): ?>
                            <a href="<?= BASE_URL ?>sales/customer_view.php?phone=<?= urlencode($c['phone']) ?>"
                               class="btn btn-primary btn-sm"
                               data-bs-toggle="tooltip" title="View Customer">
                                <i class="bi bi-eye me-1"></i>View
                            </a>
                            <?php else: ?>
                            <span style="opacity:.4;font-size:.8rem">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($customers)): ?>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-end ps-3 fw-bold">Totals</td>
                        <td class="text-end fw-bold"><?= function_exists('formatCurrency') ? formatCurrency($totalRevenue) : 'Rs '.number_format($totalRevenue,2) ?></td>
                        <td class="text-end fw-bold"><?= function_exists('formatCurrency') ? formatCurrency($totalCreditGiven) : 'Rs '.number_format($totalCreditGiven,2) ?></td>
                        <td class="text-end fw-bold" style="color:var(--pharmacy-success,#198754)"><?= function_exists('formatCurrency') ? formatCurrency(array_sum(array_column($customers,'credit_paid'))) : 'Rs '.number_format(array_sum(array_column($customers,'credit_paid')),2) ?></td>
                        <td class="text-end fw-bold" style="color:var(--pharmacy-danger,#dc3545)"><?= function_exists('formatCurrency') ? formatCurrency($totalOutstanding) : 'Rs '.number_format($totalOutstanding,2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div><!-- /container-fluid -->
</div><!-- /main-content -->



<?php if (!empty($top10)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    const labels      = <?= json_encode($chartLabels) ?>;
    const salesData   = <?= json_encode($chartSales) ?>;
    const outstanding = <?= json_encode($chartOutstanding) ?>;

    // Try to read CSS variable for primary color, fallback to blue
    const rootStyle   = getComputedStyle(document.documentElement);
    const primaryCol  = rootStyle.getPropertyValue('--pharmacy-primary').trim() || '#0d6efd';
    const dangerCol   = rootStyle.getPropertyValue('--pharmacy-danger').trim()  || '#dc3545';

    new Chart(document.getElementById('customerChart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Total Sales (Rs)',
                    data: salesData,
                    backgroundColor: primaryCol + 'BF',
                    borderColor: primaryCol,
                    borderWidth: 1,
                    borderRadius: 4,
                },
                {
                    label: 'Outstanding (Rs)',
                    data: outstanding,
                    backgroundColor: dangerCol + 'A8',
                    borderColor: dangerCol,
                    borderWidth: 1,
                    borderRadius: 4,
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: ctx => 'Rs ' + ctx.parsed.x.toLocaleString('en-PK', { minimumFractionDigits: 2 })
                    }
                }
            },
            scales: {
                x: {
                    ticks: { callback: val => 'Rs ' + val.toLocaleString('en-PK') }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

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
    if (statCards[0] && stats.customer_count !== undefined) statCards[0].textContent = stats.customer_count;
    if (statCards[1] && stats.total_revenue) statCards[1].textContent = stats.total_revenue;
    if (statCards[2] && stats.total_credit_given) statCards[2].textContent = stats.total_credit_given;
    if (statCards[3] && stats.total_outstanding) statCards[3].textContent = stats.total_outstanding;
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
            title      : 'Customer Report',
            pageSize   : 25,
            excludeCols: [9]
        });
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
