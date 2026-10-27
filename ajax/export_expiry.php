<?php
/**
 * ajax/export_expiry.php — CSV Export for expiry
 * Rebuild Step 7
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLogin();
$from   = $_GET['from_date'] ?? date('Y-m-01');
$to     = $_GET['to_date']   ?? date('Y-m-d');
$format = $_GET['format']    ?? 'csv';
if (!strtotime($from)) $from = date('Y-m-01');
if (!strtotime($to))   $to   = date('Y-m-d');

// Redirect to existing report with export param
$params = http_build_query(['from_date' => $from, 'to_date' => $to, 'export' => $format]);
header('Location: ' . BASE_URL . 'reports/export_excel.php?' . $params . '&report_type=expiry');
exit;
