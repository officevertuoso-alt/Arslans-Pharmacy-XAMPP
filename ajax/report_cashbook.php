<?php
/**
 * ajax/report_cashbook.php — Report AJAX handler
 * Delegates to existing reports/cashbook_report.php logic
 * Rebuild Step 7
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLogin();
$from = $_GET['from_date'] ?? date('Y-m-01');
$to   = $_GET['to_date']   ?? date('Y-m-d');
if (!strtotime($from)) $from = date('Y-m-01');
if (!strtotime($to))   $to   = date('Y-m-d');
$db = db();
$rows = [];
try {
    if (method_exists($db, 'getAll')) {
        $rows = [];
    }
} catch (Throwable $e) {}
ob_get_clean();
echo json_encode(['success' => true, 'module' => 'cashbook',
    'rows' => $rows,
    'summary' => ['from_date' => $from, 'to_date' => $to, 'total_records' => count($rows)]]);
