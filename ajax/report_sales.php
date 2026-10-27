<?php
/**
 * ajax/report_sales.php — Sales Report AJAX Handler
 * Returns filtered sales report as JSON or HTML table
 * Rebuild Step 7
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLogin();

$db        = db();
$from      = $_GET['from_date']      ?? date('Y-m-01');
$to        = $_GET['to_date']        ?? date('Y-m-d');
$method    = $_GET['payment_method'] ?? '';
$status    = $_GET['payment_status'] ?? '';
$format    = $_GET['format']         ?? 'json'; // json | html | csv

if (!strtotime($from)) $from = date('Y-m-01');
if (!strtotime($to))   $to   = date('Y-m-d');

$where  = "DATE(s.sale_date) BETWEEN :from AND :to AND s.status != 'voided'";
$params = [':from' => $from, ':to' => $to];
if ($method) { $where .= " AND s.payment_method = :pm"; $params[':pm'] = $method; }
if ($status) { $where .= " AND s.payment_status = :ps"; $params[':ps'] = $status; }

$sql = "SELECT s.id, s.invoice_no, s.customer_name, s.customer_phone,
               s.sale_date, s.total, s.paid_amount, s.payment_method,
               s.payment_status, s.is_credit,
               COALESCE(SUM(sr.total_amount), 0) AS total_returned
        FROM sales s
        LEFT JOIN sale_returns sr ON sr.sale_id = s.id
        WHERE $where
        GROUP BY s.id
        ORDER BY s.sale_date DESC, s.id DESC
        LIMIT 500";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Try via getAll wrapper
    try { $rows = $db->getAll($sql, $params); } catch (Throwable $e2) { $rows = []; }
}

// Totals
$gross = $net = $returned = 0;
foreach ($rows as &$r) {
    $r['net_amount'] = $r['total'] - $r['total_returned'];
    $gross    += $r['total'];
    $returned += $r['total_returned'];
    $net      += $r['net_amount'];
}

$stray = ob_get_clean();
echo json_encode([
    'success'  => true,
    'rows'     => $rows,
    'summary'  => [
        'total_records'  => count($rows),
        'gross_sales'    => round($gross, 2),
        'total_returned' => round($returned, 2),
        'net_sales'      => round($net, 2),
        'from_date'      => $from,
        'to_date'        => $to,
    ],
]);
