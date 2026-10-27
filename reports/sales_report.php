<?php
/**
 * reports/sales_report.php
 * Sales Report – Enhanced with Returns, Credit Balances, Payment Status
 * Real-time credit remaining linked with credit payments
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
    // Filters (same as normal page)
    $from_date = $_GET['from_date'] ?? date('Y-m-01');
    $to_date   = $_GET['to_date']   ?? date('Y-m-d');
    $payment_method = $_GET['payment_method'] ?? '';
    $payment_status = $_GET['payment_status'] ?? '';
    $is_credit = isset($_GET['is_credit']) && $_GET['is_credit'] !== '' ? (int)$_GET['is_credit'] : null;
    $customer_phone = trim($_GET['customer_phone'] ?? '');
    $customer_name  = trim($_GET['customer_name']  ?? '');
    if (!strtotime($from_date)) $from_date = date('Y-m-01');
    if (!strtotime($to_date))   $to_date   = date('Y-m-d');
    $db = db();
    $where = "DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided'";
    $params = [':from' => $from_date, ':to' => $to_date];
    if ($payment_method) { $where .= " AND s.payment_method = :pm"; $params[':pm'] = $payment_method; }
    if ($payment_status) { $where .= " AND s.payment_status = :ps"; $params[':ps'] = $payment_status; }
    if ($is_credit !== null) { $where .= " AND s.is_credit = :credit"; $params[':credit'] = $is_credit; }
    if ($customer_phone !== '') { $where .= " AND s.customer_phone = :cphone"; $params[':cphone'] = $customer_phone; }
    if ($customer_name !== '') { $where .= " AND s.customer_name LIKE :cname"; $params[':cname'] = '%' . $customer_name . '%'; }
    $creditTableExists = $db->getOne("SHOW TABLES LIKE 'credit_payments'");
    $hasVoidedColumn = false;
    if ($creditTableExists) {
        $columns = $db->getAll("SHOW COLUMNS FROM credit_payments");
        $columnNames = array_column($columns, 'Field');
        $hasVoidedColumn = in_array('voided', $columnNames);
    }
    if ($creditTableExists) {
        if ($hasVoidedColumn) {
            $creditPaidSubquery  = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND voided = 0)";
            $cashPaidSubquery    = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND voided = 0 AND payment_method = 'cash')";
            $cardPaidSubquery    = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND voided = 0 AND payment_method = 'card')";
            $onlinePaidSubquery  = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND voided = 0 AND payment_method = 'online')";
        } else {
            $creditPaidSubquery  = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id)";
            $cashPaidSubquery    = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND payment_method = 'cash')";
            $cardPaidSubquery    = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND payment_method = 'card')";
            $onlinePaidSubquery  = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND payment_method = 'online')";
        }
    } else {
        $creditPaidSubquery = $cashPaidSubquery = $cardPaidSubquery = $onlinePaidSubquery = "0";
    }
    $sql = "SELECT s.id, s.invoice_no, s.customer_name, s.customer_phone, s.sale_date,
                s.total, s.paid_amount, s.payment_method, s.payment_status, s.is_credit, s.net_amount,
                COALESCE(SUM(sr.total_amount), 0) AS total_returned,
                (s.total - COALESCE(SUM(sr.total_amount), 0)) AS net_sales_amount,
                cc.id as credit_id, cc.total_amount AS credit_original, cc.status AS credit_status,
                {$creditPaidSubquery} AS credit_paid,
                {$cashPaidSubquery} AS cash_paid,
                {$cardPaidSubquery} AS card_paid,
                {$onlinePaidSubquery} AS online_paid
         FROM sales s
         LEFT JOIN sale_returns sr ON sr.sale_id = s.id
         LEFT JOIN customer_credits cc ON cc.sale_id = s.id
         WHERE {$where}
         GROUP BY s.id
         ORDER BY s.sale_date DESC, s.id DESC";
    $sales = $db->getAll($sql, $params);
    foreach ($sales as &$sale) {
        if ($sale['is_credit'] && $sale['credit_original'] > 0) {
            $credit_paid = floatval($sale['credit_paid'] ?? 0);
            $credit_original = floatval($sale['credit_original']);
            $sale['credit_remaining'] = max(0, $credit_original - $credit_paid);
            $sale['total_received'] = floatval($sale['paid_amount']) + $credit_paid;
            if ($sale['credit_remaining'] <= 0) $sale['payment_status'] = 'paid';
            elseif ($sale['credit_remaining'] < $credit_original) $sale['payment_status'] = 'partial';
        } else {
            $sale['credit_remaining'] = 0;
            $sale['total_received'] = floatval($sale['paid_amount']);
        }
    }
    $totals = $db->getOne(
        "SELECT COALESCE(SUM(s.total), 0) AS gross_sales,
                COALESCE(SUM(sr.total_amount), 0) AS total_returns,
                COALESCE(SUM(s.total - COALESCE(sr.total_amount,0)), 0) AS net_sales,
                COALESCE(SUM(CASE WHEN s.payment_method = 'cash' THEN s.total - COALESCE(sr.total_amount,0) ELSE 0 END), 0) AS cash_sales,
                COALESCE(SUM(CASE WHEN s.payment_method = 'card' THEN s.total - COALESCE(sr.total_amount,0) ELSE 0 END), 0) AS card_sales,
                COALESCE(SUM(CASE WHEN s.payment_method = 'online' THEN s.total - COALESCE(sr.total_amount,0) ELSE 0 END), 0) AS online_sales,
                COALESCE(SUM(CASE WHEN s.payment_method = 'credit' THEN s.total - COALESCE(sr.total_amount,0) ELSE 0 END), 0) AS credit_sales,
                COALESCE(SUM(CASE WHEN s.is_credit = 1 THEN s.total - s.paid_amount ELSE 0 END), 0) AS credit_outstanding
         FROM sales s LEFT JOIN sale_returns sr ON sr.sale_id = s.id WHERE $where",
        $params
    );
    $totalGross = $totalReturns = $totalNet = $totalPaid = $totalReceived = $totalCreditRemainingSum = 0;
    foreach ($sales as $s) {
        $totalGross   += $s['total'];
        $totalReturns += $s['total_returned'];
        $totalNet     += $s['net_sales_amount'];
        $totalPaid    += $s['paid_amount'];
        $totalReceived+= $s['total_received'];
        $totalCreditRemainingSum += $s['credit_remaining'];
    }
    if (!function_exists('formatCurrency')) { function formatCurrency($a) { return 'Rs ' . number_format((float)$a, 2); } }
    if (!function_exists('formatDate'))     { function formatDate($d) { return empty($d) ? '' : date('d-m-Y', strtotime($d)); } }
    if (!function_exists('formatDateTime')) { function formatDateTime($d) { return empty($d) ? '' : date('d-m-Y h:i A', strtotime($d)); } }
    ob_start();
    if (empty($sales)) {
        echo '<tr><td colspan="12" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>No sales found in selected date range</td></tr>';
    } else {
        $serial = 1;
        foreach ($sales as $s) {
            $credit_remaining = $s['credit_remaining'];
            $received = $s['total_received'];
            $method = strtolower($s['payment_method'] ?? '');
            echo '<tr>';
            echo '<td class="serial-no">' . $serial++ . '.</td>';
            echo '<td class="invoice-no">' . htmlspecialchars($s['invoice_no']) . '</td>';
            echo '<td><small class="text-muted">' . formatDateTime($s['sale_date']) . '</small></td>';
            echo '<td><span class="fw-semibold">' . htmlspecialchars($s['customer_name'] ?? 'Walk-in Customer') . '</span>';
            if (!empty($s['customer_phone'])) echo '<br><small class="text-muted">' . htmlspecialchars($s['customer_phone']) . '</small>';
            echo '</td>';
            echo '<td class="text-end">' . formatCurrency($s['total']) . '</td>';
            echo '<td class="text-end text-danger">' . formatCurrency($s['total_returned']) . '</td>';
            echo '<td class="text-end fw-bold text-success">' . formatCurrency($s['net_sales_amount']) . '</td>';
            echo '<td class="text-end">' . formatCurrency($s['paid_amount']) . '</td>';
            echo '<td class="text-end fw-semibold" style="color:var(--pharmacy-primary,#0d6efd);">' . formatCurrency($received) . '</td>';
            echo '<td><span class="badge-method badge-method-' . $method . '">' . ucfirst($s['payment_method']) . '</span></td>';
            echo '<td><span class="status-badge status-' . $s['payment_status'] . '">' . ucfirst($s['payment_status']) . '</span></td>';
            echo '<td class="text-end ' . ($credit_remaining > 0 ? 'text-danger fw-bold' : 'text-muted') . '">';
            if ($s['is_credit'] && $credit_remaining > 0 && $s['credit_id']) {
                echo '<a href="../credit/view.php?id=' . $s['credit_id'] . '" class="credit-link text-danger" target="_blank">' . formatCurrency($credit_remaining) . ' <i class="bi bi-box-arrow-up-right small ms-1"></i></a>';
            } elseif ($s['is_credit'] && $credit_remaining <= 0) {
                echo '<span class="text-success">' . formatCurrency(0) . '</span>';
            } elseif ($s['is_credit'] && !$s['credit_id']) {
                echo '<span class="text-warning">' . formatCurrency($credit_remaining) . '</span>';
            } else {
                echo '<span class="text-muted">—</span>';
            }
            echo '</td></tr>';
        }
        echo '<tr class="table-dark fw-bold" style="border-top:2px solid var(--pharmacy-border,#dee2e6);">';
        echo '<td colspan="4" class="text-end fs-6">TOTAL FOR SELECTED PERIOD:</td>';
        echo '<td class="text-end fs-6">' . formatCurrency($totalGross) . '</td>';
        echo '<td class="text-end fs-6 text-danger">' . formatCurrency($totalReturns) . '</td>';
        echo '<td class="text-end fs-6 text-success">' . formatCurrency($totalNet) . '</td>';
        echo '<td class="text-end fs-6">' . formatCurrency($totalPaid) . '</td>';
        echo '<td class="text-end fs-6 text-info">' . formatCurrency($totalReceived) . '</td>';
        echo '<td colspan="2"></td>';
        echo '<td class="text-end fs-6 text-warning">' . formatCurrency($totalCreditRemainingSum) . '</td>';
        echo '</tr>';
    }
    $tableHtml = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html'    => $tableHtml,
        'total'   => count($sales),
        'stats'   => [
            'gross_sales'    => formatCurrency($totals['gross_sales'] ?? 0),
            'total_returns'  => formatCurrency($totals['total_returns'] ?? 0),
            'net_sales'      => formatCurrency($totals['net_sales'] ?? 0),
            'credit_outstanding' => formatCurrency($totalCreditRemainingSum),
            'cash_sales'     => formatCurrency($totals['cash_sales'] ?? 0),
            'card_sales'     => formatCurrency($totals['card_sales'] ?? 0),
            'online_sales'   => formatCurrency($totals['online_sales'] ?? 0),
            'credit_sales'   => formatCurrency($totals['credit_sales'] ?? 0),
            'invoice_count'  => count($sales),
        ],
    ]);
    exit;
}

// Filters
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date   = $_GET['to_date']   ?? date('Y-m-d');
$payment_method = $_GET['payment_method'] ?? '';
$payment_status = $_GET['payment_status'] ?? '';
$is_credit = isset($_GET['is_credit']) ? (int)$_GET['is_credit'] : null;
$customer_phone = trim($_GET['customer_phone'] ?? '');
$customer_name  = trim($_GET['customer_name']  ?? '');

if (!strtotime($from_date)) $from_date = date('Y-m-01');
if (!strtotime($to_date))   $to_date   = date('Y-m-d');

$db = db();

// Build WHERE clause
$where = "DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided'";
$params = [':from' => $from_date, ':to' => $to_date];

if ($payment_method) { $where .= " AND s.payment_method = :pm"; $params[':pm'] = $payment_method; }
if ($payment_status) { $where .= " AND s.payment_status = :ps"; $params[':ps'] = $payment_status; }
if ($is_credit !== null) { $where .= " AND s.is_credit = :credit"; $params[':credit'] = $is_credit; }
if ($customer_phone !== '') { $where .= " AND s.customer_phone = :cphone"; $params[':cphone'] = $customer_phone; }
if ($customer_name !== '') { $where .= " AND s.customer_name LIKE :cname"; $params[':cname'] = '%' . $customer_name . '%'; }

// [PERF-05] PHASE 27: Removed SHOW TABLES + SHOW COLUMNS probes.
// credit_payments table guaranteed since Phase 24; voided column present since Phase 25.
// These metadata queries ran on every page load (~10 ms each + metadata lock).
$creditTableExists = true;
$hasVoidedColumn   = true;

if ($creditTableExists) {
    if ($hasVoidedColumn) {
        $creditPaidSubquery  = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND voided = 0)";
        $cashPaidSubquery    = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND voided = 0 AND payment_method = 'cash')";
        $cardPaidSubquery    = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND voided = 0 AND payment_method = 'card')";
        $onlinePaidSubquery  = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND voided = 0 AND payment_method = 'online')";
    } else {
        $creditPaidSubquery  = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id)";
        $cashPaidSubquery    = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND payment_method = 'cash')";
        $cardPaidSubquery    = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND payment_method = 'card')";
        $onlinePaidSubquery  = "(SELECT COALESCE(SUM(amount), 0) FROM credit_payments WHERE credit_id = cc.id AND payment_method = 'online')";
    }
} else {
    $creditPaidSubquery  = "0 AS credit_paid";
    $cashPaidSubquery    = "0 AS cash_paid";
    $cardPaidSubquery    = "0 AS card_paid";
    $onlinePaidSubquery  = "0 AS online_paid";
}

$sql = "SELECT s.id, s.invoice_no, s.customer_name, s.customer_phone, s.sale_date,
            s.total, s.paid_amount, s.payment_method,
            s.payment_status, s.is_credit, s.net_amount,
            COALESCE(SUM(sr.total_amount), 0) AS total_returned,
            (s.total - COALESCE(SUM(sr.total_amount), 0)) AS net_sales_amount,
            cc.id as credit_id,
            cc.total_amount AS credit_original,
            cc.status AS credit_status,
            {$creditPaidSubquery} AS credit_paid,
            {$cashPaidSubquery} AS cash_paid,
            {$cardPaidSubquery} AS card_paid,
            {$onlinePaidSubquery} AS online_paid
     FROM sales s
     LEFT JOIN sale_returns sr ON sr.sale_id = s.id
     LEFT JOIN customer_credits cc ON cc.sale_id = s.id
     WHERE {$where}
     GROUP BY s.id
     ORDER BY s.sale_date DESC, s.id DESC";

$sales = $db->getAll($sql, $params);

foreach ($sales as &$sale) {
    if ($sale['is_credit'] && $sale['credit_original'] > 0) {
        $credit_paid = floatval($sale['credit_paid'] ?? 0);
        $credit_original = floatval($sale['credit_original']);
        $sale['credit_remaining'] = max(0, $credit_original - $credit_paid);
        $sale['total_received'] = floatval($sale['paid_amount']) + $credit_paid;
        if ($sale['credit_remaining'] <= 0) { $sale['payment_status'] = 'paid'; }
        elseif ($sale['credit_remaining'] < $credit_original) { $sale['payment_status'] = 'partial'; }
    } else {
        $sale['credit_remaining'] = 0;
        $sale['total_received'] = floatval($sale['paid_amount']);
    }
}

$totals = $db->getOne(
    "SELECT
        COALESCE(SUM(s.total), 0) AS gross_sales,
        COALESCE(SUM(sr.total_amount), 0) AS total_returns,
        COALESCE(SUM(s.total - COALESCE(sr.total_amount,0)), 0) AS net_sales,
        COALESCE(SUM(CASE WHEN s.payment_method = 'cash' THEN s.total - COALESCE(sr.total_amount,0) ELSE 0 END), 0) AS cash_sales,
        COALESCE(SUM(CASE WHEN s.payment_method = 'card' THEN s.total - COALESCE(sr.total_amount,0) ELSE 0 END), 0) AS card_sales,
        COALESCE(SUM(CASE WHEN s.payment_method = 'online' THEN s.total - COALESCE(sr.total_amount,0) ELSE 0 END), 0) AS online_sales,
        COALESCE(SUM(CASE WHEN s.payment_method = 'credit' THEN s.total - COALESCE(sr.total_amount,0) ELSE 0 END), 0) AS credit_sales,
        COALESCE(SUM(CASE WHEN s.is_credit = 1 THEN s.total - s.paid_amount ELSE 0 END), 0) AS credit_outstanding
     FROM sales s
     LEFT JOIN sale_returns sr ON sr.sale_id = s.id
     WHERE $where",
    $params
);

$totalCreditRemaining = 0;
foreach ($sales as $sale) { $totalCreditRemaining += $sale['credit_remaining']; }

// Customer summary
$customerSummary = null;
if ($customer_phone !== '' || $customer_name !== '') {
    $cSummaryWhere = "DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided'";
    $cSummaryParams = [':from' => $from_date, ':to' => $to_date];
    if ($customer_phone !== '') { $cSummaryWhere .= " AND s.customer_phone = :cphone"; $cSummaryParams[':cphone'] = $customer_phone; }
    elseif ($customer_name !== '') { $cSummaryWhere .= " AND s.customer_name LIKE :cname"; $cSummaryParams[':cname'] = '%' . $customer_name . '%'; }
    $customerSummary = $db->getOne(
        "SELECT MAX(s.customer_name) AS customer_name, MAX(s.customer_phone) AS customer_phone,
                COUNT(DISTINCT s.id) AS total_sales,
                COALESCE(SUM(DISTINCT s.total), 0) AS total_amount,
                COALESCE((SELECT SUM(cc2.total_amount) FROM customer_credits cc2 WHERE cc2.status IN ('active', 'overdue') AND cc2.customer_phone = MAX(s.customer_phone)), 0) AS credit_outstanding,
                COALESCE(SUM(cc.total_amount), 0) AS total_credit_given
         FROM sales s
         LEFT JOIN customer_credits cc ON cc.sale_id = s.id
         WHERE {$cSummaryWhere}",
        $cSummaryParams
    );
}

// Calculate totals for display
$totalGross = 0; $totalReturns = 0; $totalNet = 0; $totalPaid = 0; $totalReceived = 0; $totalCreditRemainingSum = 0;
foreach ($sales as $s) {
    $totalGross   += $s['total'];
    $totalReturns += $s['total_returned'];
    $totalNet     += $s['net_sales_amount'];
    $totalPaid    += $s['paid_amount'];
    $totalReceived+= $s['total_received'];
    $totalCreditRemainingSum += $s['credit_remaining'];
}

logActivity('View Report', "Sales Report: {$from_date} to {$to_date}");
$page_title = 'Sales Report';

if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) { return 'Rs ' . number_format((float)$amount, 2); }
}
if (!function_exists('formatDate')) {
    function formatDate($date) { if (empty($date)) return ''; return date('d-m-Y', strtotime($date)); }
}
if (!function_exists('formatDateTime')) {
    function formatDateTime($date) { if (empty($date)) return ''; return date('d-m-Y h:i A', strtotime($date)); }
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
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            border-left: 4px solid;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .summary-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,0.13); }
        .summary-card .card-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 70%, transparent); }
        .summary-card .card-value { font-size: 1.5rem; font-weight: 700; color: var(--pharmacy-heading, #212529); margin-top: 4px; }

        /* Filter bar */
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
            padding: 12px 18px;
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--pharmacy-heading, #212529) !important;
        }

        /* Table card */
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
            padding: 14px 18px;
            font-weight: 600;
            color: var(--pharmacy-heading, #212529) !important;
        }
        .table th {
            background: var(--pharmacy-table-th, #343a40) !important;
            color: var(--pharmacy-heading, #fff) !important;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 700;
            padding: 11px 14px;
            border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important;
            white-space: nowrap;
        }
        .table td {
            padding: 10px 14px;
            vertical-align: middle;
            color: var(--pharmacy-text, #212529) !important;
            border-color: var(--pharmacy-border, #dee2e6) !important;
        }
        .table-hover tbody tr { transition: background 0.15s; }
        .table-hover tbody tr:hover td { background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 6%, var(--pharmacy-card-bg, #fff)) !important; }

        /* Status badges */
        .status-badge { padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; display: inline-block; }
        .status-paid    { background: #d1e7dd; color: #0f5132; }
        .status-partial { background: #fff3cd; color: #664d03; }
        .status-pending { background: #f8d7da; color: #842029; }

        /* Method badge */
        .badge-method { background: #6c757d; color: #fff; padding: 3px 10px; border-radius: 15px; font-size: 0.72rem; font-weight: 500; }
        .badge-method-cash   { background: #198754; }
        .badge-method-card   { background: #0d6efd; }
        .badge-method-online { background: #0dcaf0; color: #000; }
        .badge-method-credit { background: #dc3545; }

        .serial-no { width: 50px; text-align: center; font-weight: 500; color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 70%, transparent); }
        .invoice-no { font-weight: 600; color: var(--pharmacy-heading, #212529); }
        .credit-link { text-decoration: none; font-weight: 500; }
        .credit-link:hover { text-decoration: underline; }

        /* Customer summary bar */
        .customer-bar {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e9ecef);
            border-left: 4px solid var(--pharmacy-primary, #0d6efd);
            border-radius: var(--card-border-radius, 12px);
            padding: 16px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        /* Alert footer */
        .footer-summary {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 8%, var(--pharmacy-card-bg, #fff));
            border: 1px solid color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 20%, var(--pharmacy-border, #e9ecef));
            border-radius: var(--card-border-radius, 12px);
            padding: 14px 18px;
            font-size: 0.82rem;
            color: var(--pharmacy-text, #495057);
        }

        @media print {
            .no-print { display: none !important; }
            .main-content { margin-left: 0; padding: 10px; }
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
            <h4 class="fw-bold mb-1" style="color:var(--pharmacy-heading,#212529);">
                <i class="bi bi-graph-up me-2 text-primary"></i><?= $page_title ?>
            </h4>
            <small class="text-muted"><?= formatDate($from_date) ?> — <?= formatDate($to_date) ?> &nbsp;|&nbsp; <?= count($sales) ?> invoices</small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="export_pdf.php?report=sales&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&payment_method=<?= urlencode($payment_method) ?>&payment_status=<?= urlencode($payment_status) ?>" target="_blank" class="btn btn-danger btn-sm">
                <i class="fas fa-file-pdf me-1"></i> PDF
            </a>
            <a href="export_excel.php?report=sales&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&payment_method=<?= urlencode($payment_method) ?>&payment_status=<?= urlencode($payment_status) ?>" class="btn btn-success btn-sm">
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

    <!-- Filter Card -->
    <div class="filter-bar no-print">
        <div class="card-header">
            <i class="bi bi-funnel me-2"></i>Filter Options
        </div>
        <div class="p-3">
            <form method="GET" class="row g-3" id="filterForm">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">From Date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= $from_date ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">To Date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= $to_date ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Payment Method</label>
                    <select name="payment_method" class="form-select form-select-sm">
                        <option value="">All Methods</option>
                        <option value="cash"   <?= $payment_method=='cash'   ? 'selected' : '' ?>>Cash</option>
                        <option value="card"   <?= $payment_method=='card'   ? 'selected' : '' ?>>Card</option>
                        <option value="online" <?= $payment_method=='online' ? 'selected' : '' ?>>Online</option>
                        <option value="credit" <?= $payment_method=='credit' ? 'selected' : '' ?>>Credit</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Payment Status</label>
                    <select name="payment_status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option value="paid"    <?= $payment_status=='paid'    ? 'selected' : '' ?>>Paid</option>
                        <option value="partial" <?= $payment_status=='partial' ? 'selected' : '' ?>>Partial</option>
                        <option value="pending" <?= $payment_status=='pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small fw-semibold">Credit Only</label>
                    <select name="is_credit" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="1" <?= $is_credit===1 ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= $is_credit===0 ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Customer Phone</label>
                    <input type="text" name="customer_phone" class="form-control form-control-sm" placeholder="0300xxxxxxx" value="<?= htmlspecialchars($customer_phone) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Customer Name</label>
                    <input type="text" name="customer_name" class="form-control form-control-sm" placeholder="Search by name" value="<?= htmlspecialchars($customer_name) ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                    <?php if ($customer_phone !== '' || $customer_name !== ''): ?>
                    <a href="sales_report.php?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>" class="btn btn-outline-secondary btn-sm" title="Clear">
                        <i class="bi bi-x-lg"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Customer Summary (when filtering by customer) -->
    <?php if ($customerSummary && ($customer_phone !== '' || $customer_name !== '')): ?>
    <div class="customer-bar mb-4 no-print">
        <div class="d-flex flex-wrap align-items-center gap-4">
            <div>
                <div class="text-muted small" style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;">Customer</div>
                <div class="fw-bold fs-5" style="color:var(--pharmacy-heading,#212529);"><?= htmlspecialchars($customerSummary['customer_name'] ?? '—') ?></div>
                <?php if (!empty($customerSummary['customer_phone'])): ?>
                <div class="text-muted small"><?= htmlspecialchars($customerSummary['customer_phone']) ?></div>
                <?php endif; ?>
            </div>
            <div class="vr d-none d-md-block"></div>
            <div class="text-center">
                <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase;">Total Sales</div>
                <div class="fw-bold text-primary"><?= (int)($customerSummary['total_sales'] ?? 0) ?> invoices</div>
            </div>
            <div class="vr d-none d-md-block"></div>
            <div class="text-center">
                <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase;">Total Amount</div>
                <div class="fw-bold text-success"><?= formatCurrency($customerSummary['total_amount'] ?? 0) ?></div>
            </div>
            <div class="vr d-none d-md-block"></div>
            <div class="text-center">
                <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase;">Credit Given</div>
                <div class="fw-bold text-warning"><?= formatCurrency($customerSummary['total_credit_given'] ?? 0) ?></div>
            </div>
            <div class="vr d-none d-md-block"></div>
            <div class="text-center">
                <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase;">Outstanding</div>
                <div class="fw-bold <?= ($customerSummary['credit_outstanding'] ?? 0) > 0 ? 'text-danger' : 'text-muted' ?>">
                    <?= formatCurrency($customerSummary['credit_outstanding'] ?? 0) ?>
                </div>
            </div>
            <?php if (!empty($customerSummary['customer_phone'])): ?>
            <div class="ms-auto">
                <a href="../sales/customer_view.php?phone=<?= urlencode($customerSummary['customer_phone']) ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-person me-1"></i>View Profile
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary Cards Row 1 -->
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#0d6efd;">
                <div class="card-label">Gross Sales</div>
                <div class="card-value text-primary"><?= formatCurrency($totals['gross_sales'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#dc3545;">
                <div class="card-label">Sale Returns</div>
                <div class="card-value text-danger"><?= formatCurrency($totals['total_returns'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#198754;">
                <div class="card-label">Net Sales</div>
                <div class="card-value text-success"><?= formatCurrency($totals['net_sales'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#fd7e14;">
                <div class="card-label">Credit Outstanding</div>
                <div class="card-value text-warning"><?= formatCurrency($totalCreditRemainingSum) ?></div>
            </div>
        </div>
    </div>

    <!-- Summary Cards Row 2 (payment method breakdown) -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#198754;">
                <div class="card-label">Cash Sales</div>
                <div class="fw-bold fs-5 text-success"><?= formatCurrency($totals['cash_sales'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#0d6efd;">
                <div class="card-label">Card Sales</div>
                <div class="fw-bold fs-5 text-primary"><?= formatCurrency($totals['card_sales'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#0dcaf0;">
                <div class="card-label">Online Sales</div>
                <div class="fw-bold fs-5 text-info"><?= formatCurrency($totals['online_sales'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card" style="border-left-color:#dc3545;">
                <div class="card-label">Credit Sales</div>
                <div class="fw-bold fs-5 text-danger"><?= formatCurrency($totals['credit_sales'] ?? 0) ?></div>
            </div>
        </div>
    </div>

    <!-- Sales Table -->
    <div class="table-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-receipt me-2"></i>Sales Invoices</span>
            <span class="badge bg-primary rounded-pill"><?= count($sales) ?> Invoices</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="reportTable">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Invoice #</th>
                        <th>Date &amp; Time</th>
                        <th>Customer</th>
                        <th class="text-end">Gross (Rs)</th>
                        <th class="text-end">Returns (Rs)</th>
                        <th class="text-end">Net (Rs)</th>
                        <th class="text-end">Paid (Rs)</th>
                        <th class="text-end">Received (Rs)</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th class="text-end">Credit Remaining</th>
                    </tr>
                </thead>
                <tbody id="reportTableBody">
                    <?php if (empty($sales)): ?>
                    <tr>
                        <td colspan="12" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                            No sales found in selected date range
                        </td>
                    </tr>
                    <?php else:
                        $serial = 1;
                        foreach ($sales as $s):
                            $credit_remaining = $s['credit_remaining'];
                            $received = $s['total_received'];
                            $method = strtolower($s['payment_method'] ?? '');
                    ?>
                    <tr>
                        <td class="serial-no"><?= $serial++ ?>.</td>
                        <td class="invoice-no"><?= htmlspecialchars($s['invoice_no']) ?></td>
                        <td><small class="text-muted"><?= formatDateTime($s['sale_date']) ?></small></td>
                        <td>
                            <span class="fw-semibold"><?= htmlspecialchars($s['customer_name'] ?? 'Walk-in Customer') ?></span>
                            <?php if (!empty($s['customer_phone'])): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($s['customer_phone']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= formatCurrency($s['total']) ?></td>
                        <td class="text-end text-danger"><?= formatCurrency($s['total_returned']) ?></td>
                        <td class="text-end fw-bold text-success"><?= formatCurrency($s['net_sales_amount']) ?></td>
                        <td class="text-end"><?= formatCurrency($s['paid_amount']) ?></td>
                        <td class="text-end fw-semibold" style="color:var(--pharmacy-primary,#0d6efd);"><?= formatCurrency($received) ?></td>
                        <td>
                            <span class="badge-method badge-method-<?= $method ?>">
                                <?= ucfirst($s['payment_method']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?= $s['payment_status'] ?>"><?= ucfirst($s['payment_status']) ?></span>
                        </td>
                        <td class="text-end <?= $credit_remaining > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                            <?php if ($s['is_credit'] && $credit_remaining > 0 && $s['credit_id']): ?>
                                <a href="../credit/view.php?id=<?= $s['credit_id'] ?>" class="credit-link text-danger" target="_blank">
                                    <?= formatCurrency($credit_remaining) ?>
                                    <i class="bi bi-box-arrow-up-right small ms-1"></i>
                                </a>
                            <?php elseif ($s['is_credit'] && $credit_remaining <= 0): ?>
                                <span class="text-success"><?= formatCurrency(0) ?></span>
                            <?php elseif ($s['is_credit'] && !$s['credit_id']): ?>
                                <span class="text-warning"><?= formatCurrency($credit_remaining) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Grand Total Row -->
                    <tr class="table-dark fw-bold" style="border-top: 2px solid var(--pharmacy-border,#dee2e6);">
                        <td colspan="4" class="text-end fs-6">TOTAL FOR SELECTED PERIOD:</td>
                        <td class="text-end fs-6"><?= formatCurrency($totalGross) ?></td>
                        <td class="text-end fs-6 text-danger"><?= formatCurrency($totalReturns) ?></td>
                        <td class="text-end fs-6 text-success"><?= formatCurrency($totalNet) ?></td>
                        <td class="text-end fs-6"><?= formatCurrency($totalPaid) ?></td>
                        <td class="text-end fs-6 text-info"><?= formatCurrency($totalReceived) ?></td>
                        <td colspan="2"></td>
                        <td class="text-end fs-6 text-warning"><?= formatCurrency($totalCreditRemainingSum) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Summary Footer -->
    <div class="footer-summary no-print">
        <i class="bi bi-info-circle me-2 text-primary"></i>
        <strong>Summary for <?= formatDate($from_date) ?> to <?= formatDate($to_date) ?></strong><br>
        Total Invoices: <strong><?= count($sales) ?></strong> |
        Gross Sales: <strong><?= formatCurrency($totalGross) ?></strong> |
        Net Sales: <strong><?= formatCurrency($totalNet) ?></strong> |
        Returns: <strong><?= formatCurrency($totalReturns) ?></strong> |
        Total Received: <strong><?= formatCurrency($totalReceived) ?></strong> |
        Credit Outstanding: <strong><?= formatCurrency($totalCreditRemainingSum) ?></strong>
        <?php if ($payment_method): ?> | Method: <strong><?= ucfirst($payment_method) ?></strong><?php endif; ?>
        <?php if ($payment_status): ?> | Status: <strong><?= ucfirst($payment_status) ?></strong><?php endif; ?>
        <?php if ($customer_phone !== ''): ?> | Phone: <strong><?= htmlspecialchars($customer_phone) ?></strong><?php endif; ?>
        <?php if ($customer_name !== ''): ?> | Customer: <strong><?= htmlspecialchars($customer_name) ?></strong><?php endif; ?>
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
    const map = {
        'gross_sales':        0,
        'total_returns':      1,
        'net_sales':          2,
        'credit_outstanding': 3,
        'cash_sales':         4,
        'card_sales':         5,
        'online_sales':       6,
        'credit_sales':       7,
    };
    const cards = document.querySelectorAll('.summary-card .card-value, .summary-card .fw-bold.fs-5');
    const keys = Object.keys(map);
    keys.forEach((key, idx) => {
        if (cards[idx] && stats[key] !== undefined) cards[idx].textContent = stats[key];
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
            title      : 'Sales Report',
            pageSize   : 25,
            excludeCols: []
        });
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
