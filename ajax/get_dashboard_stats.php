<?php
/**
 * ajax/get_dashboard_stats.php
 * Secondary AJAX endpoint for dashboard stats refresh.
 * Called by js/script.js when auto-refreshing dashboard widgets.
 * Primary endpoint is dashboard.php?ajax=refresh_stats (inline).
 */
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/cashbook_functions.php';
require_once '../includes/sync_engine.php';

requireLogin();
header('Content-Type: application/json');

// Force fresh data — clear SESSION cache
unset($_SESSION['dashboard_stats'], $_SESSION['dashboard_stats_exp']);

$stats = function_exists('getDashboardStatsLive')
    ? getDashboardStatsLive()
    : getDashboardStats(true);

// Ensure expired_stock_loss is always present
if (!isset($stats['expired_stock_loss'])) {
    $db  = db();
    $row = $db->getOne(
        "SELECT COALESCE(SUM(b.remaining_quantity * b.purchase_price), 0) AS expired_loss
         FROM batches b
         WHERE b.expiry_date < CURDATE() AND b.remaining_quantity > 0",
        []
    );
    $stats['expired_stock_loss'] = $row ? (float)$row['expired_loss'] : 0;
}

echo json_encode($stats);
exit;
