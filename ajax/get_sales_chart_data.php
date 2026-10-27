<?php
/**
 * AJAX — Sales & Purchase Chart Data for Dashboard
 * File: ajax/get_sales_chart_data.php
 *
 * Method : GET
 * Params : period — "7days" | "30days" | "6months" | "12months"  (default: 7days)
 * Returns: JSON — labels[], sales_data[], purchase_data[], period
 *
 * Grouping:
 *   7days  / 30days  → GROUP BY DATE  (daily totals)
 *   6months/ 12months → GROUP BY YEAR-MONTH (monthly totals)
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('dashboard', 'view')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
require_once '../config/database.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthenticated.']);
    exit;
}

// ── Validate period ───────────────────────────────────────────────────────────
$allowed = ['7days', '30days', '6months', '12months'];
$period  = sanitize($_GET['period'] ?? '7days');
if (!in_array($period, $allowed, true)) {
    $period = '7days';
}

// ── Resolve date range & grouping ────────────────────────────────────────────
switch ($period) {
    case '30days':
        $intervalExpr = 'INTERVAL 29 DAY';   // today + 29 back = 30 days inclusive
        $groupBy      = 'daily';
        $labelFormat  = 'd M';               // PHP date() format for daily labels
        $sqlDateFmt   = '%d %b';             // MySQL DATE_FORMAT for daily
        break;

    case '6months':
        $intervalExpr = 'INTERVAL 5 MONTH'; // current month + 5 back = 6 months
        $groupBy      = 'monthly';
        $labelFormat  = 'M Y';
        $sqlDateFmt   = '%b %Y';
        break;

    case '12months':
        $intervalExpr = 'INTERVAL 11 MONTH';
        $groupBy      = 'monthly';
        $labelFormat  = 'M Y';
        $sqlDateFmt   = '%b %Y';
        break;

    default: // 7days
        $intervalExpr = 'INTERVAL 6 DAY';
        $groupBy      = 'daily';
        $labelFormat  = 'd M';
        $sqlDateFmt   = '%d %b';
        break;
}

// ─────────────────────────────────────────────────────────────────────────────
// Build the full date/month spine in PHP so zero-revenue days still show up.
// This prevents gaps in the chart when there were no sales on a given day.
// ─────────────────────────────────────────────────────────────────────────────
$spine  = [];   // ordered list of period keys  e.g. "18 May" or "May 2026"
$today  = new DateTimeImmutable('today');

if ($groupBy === 'daily') {
    $days = match ($period) {
        '30days' => 30,
        default  => 7,
    };

    for ($i = $days - 1; $i >= 0; $i--) {
        $dt              = $today->modify("-{$i} days");
        $key             = $dt->format('Y-m-d');    // internal key for merging
        $label           = $dt->format($labelFormat);
        $spine[$key]     = ['label' => $label, 'sales' => 0.0, 'purchases' => 0.0];
    }

} else {
    // monthly
    $months = match ($period) {
        '12months' => 12,
        default    => 6,
    };

    // Start from N-1 months ago (first day of that month)
    $startMonth = $today->modify('-' . ($months - 1) . ' months')->modify('first day of this month');

    for ($i = 0; $i < $months; $i++) {
        $dt    = $startMonth->modify("+{$i} months");
        $key   = $dt->format('Y-m');               // internal key: "2026-04"
        $label = $dt->format($labelFormat);         // "Apr 2026"
        $spine[$key] = ['label' => $label, 'sales' => 0.0, 'purchases' => 0.0];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Fetch SALES totals
// ─────────────────────────────────────────────────────────────────────────────
try {
    if ($groupBy === 'daily') {
        $salesRows = getAll(
            "SELECT DATE_FORMAT(s.sale_date, :fmt)   AS period_label,
                    DATE(s.sale_date)                AS period_key,
                    SUM(s.net_amount)                AS total
             FROM   sales s
             WHERE  DATE(s.sale_date) >= DATE_SUB(CURDATE(), {$intervalExpr})
               AND  s.status != :voided
             GROUP  BY DATE(s.sale_date)
             ORDER  BY DATE(s.sale_date) ASC",
            [':fmt' => $sqlDateFmt, ':voided' => 'voided']
        );
    } else {
        $salesRows = getAll(
            "SELECT DATE_FORMAT(s.sale_date, :fmt)                   AS period_label,
                    DATE_FORMAT(s.sale_date, '%Y-%m')                AS period_key,
                    SUM(s.net_amount)                                AS total
             FROM   sales s
             WHERE  s.sale_date >= DATE_FORMAT(DATE_SUB(CURDATE(), {$intervalExpr}), '%Y-%m-01')
               AND  s.status != :voided
             GROUP  BY DATE_FORMAT(s.sale_date, '%Y-%m')
             ORDER  BY DATE_FORMAT(s.sale_date, '%Y-%m') ASC",
            [':fmt' => $sqlDateFmt, ':voided' => 'voided']
        );
    }
} catch (Exception $e) {
    error_log('get_sales_chart_data.php [sales] error: ' . $e->getMessage());
    $salesRows = [];
}

// Merge sales into spine
foreach ($salesRows as $row) {
    $key = $groupBy === 'daily' ? $row['period_key'] : $row['period_key'];
    if (isset($spine[$key])) {
        $spine[$key]['sales'] = (float)$row['total'];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Fetch PURCHASE totals
// ─────────────────────────────────────────────────────────────────────────────
try {
    if ($groupBy === 'daily') {
        $purchaseRows = getAll(
            "SELECT DATE_FORMAT(p.purchase_date, :fmt)  AS period_label,
                    DATE(p.purchase_date)               AS period_key,
                    SUM(p.net_amount)                   AS total
             FROM   purchases p
             WHERE  DATE(p.purchase_date) >= DATE_SUB(CURDATE(), {$intervalExpr})
               AND  p.status != :cancelled
             GROUP  BY DATE(p.purchase_date)
             ORDER  BY DATE(p.purchase_date) ASC",
            [':fmt' => $sqlDateFmt, ':cancelled' => 'cancelled']
        );
    } else {
        $purchaseRows = getAll(
            "SELECT DATE_FORMAT(p.purchase_date, :fmt)               AS period_label,
                    DATE_FORMAT(p.purchase_date, '%Y-%m')            AS period_key,
                    SUM(p.net_amount)                                AS total
             FROM   purchases p
             WHERE  p.purchase_date >= DATE_FORMAT(DATE_SUB(CURDATE(), {$intervalExpr}), '%Y-%m-01')
               AND  p.status != :cancelled
             GROUP  BY DATE_FORMAT(p.purchase_date, '%Y-%m')
             ORDER  BY DATE_FORMAT(p.purchase_date, '%Y-%m') ASC",
            [':fmt' => $sqlDateFmt, ':cancelled' => 'cancelled']
        );
    }
} catch (Exception $e) {
    error_log('get_sales_chart_data.php [purchases] error: ' . $e->getMessage());
    $purchaseRows = [];
}

// Merge purchases into spine
foreach ($purchaseRows as $row) {
    $key = $row['period_key'];
    if (isset($spine[$key])) {
        $spine[$key]['purchases'] = (float)$row['total'];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Flatten spine into parallel arrays for Chart.js
// ─────────────────────────────────────────────────────────────────────────────
$labels       = [];
$salesData    = [];
$purchaseData = [];

foreach ($spine as $entry) {
    $labels[]       = $entry['label'];
    $salesData[]    = $entry['sales'];
    $purchaseData[] = $entry['purchases'];
}

// ── Summary stats (bonus — useful for dashboard KPI cards) ────────────────────
$totalSales     = array_sum($salesData);
$totalPurchases = array_sum($purchaseData);
$grossProfit    = $totalSales - $totalPurchases;

echo json_encode([
    'success'        => true,
    'period'         => $period,
    'labels'         => $labels,
    'sales_data'     => $salesData,
    'purchase_data'  => $purchaseData,
    'summary'        => [
        'total_sales'     => round($totalSales,     2),
        'total_purchases' => round($totalPurchases, 2),
        'gross_profit'    => round($grossProfit,    2),
    ],
]);