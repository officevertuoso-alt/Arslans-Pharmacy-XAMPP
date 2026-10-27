<?php
/**
 * ajax/get_notifications_count.php
 * Returns notification badge counts for navbar bell icon.
 * Called by js/script.js on page load and periodic refresh.
 */
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';

requireLogin();
header('Content-Type: application/json');

$db = db();

$lowStock       = 0;
$expiringBatch  = 0;
$pendingCredit  = 0;
$pendingDues    = 0;

// 1. Low stock products (stock_quantity <= reorder_level)
try {
    $row = $db->getOne(
        "SELECT COUNT(*) AS cnt
         FROM products
         WHERE stock_quantity <= reorder_level
           AND status = 1
           AND stock_quantity > 0",
        []
    );
    $lowStock = $row ? (int)$row['cnt'] : 0;
} catch (Exception $e) {
    error_log('notifications low_stock: ' . $e->getMessage());
}

// 2. Expiring within 30 days (non-expired batches with stock)
try {
    $row = $db->getOne(
        "SELECT COUNT(*) AS cnt
         FROM batches
         WHERE is_expired = 0
           AND expiry_date IS NOT NULL
           AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           AND remaining_quantity > 0",
        []
    );
    $expiringBatch = $row ? (int)$row['cnt'] : 0;
} catch (Exception $e) {
    error_log('notifications expiring: ' . $e->getMessage());
}

// 3. Pending / partial customer credits
try {
    $row = $db->getOne(
        "SELECT COUNT(*) AS cnt
         FROM customer_credits
         WHERE status IN ('pending', 'partial')",
        []
    );
    $pendingCredit = $row ? (int)$row['cnt'] : 0;
} catch (Exception $e) {
    error_log('notifications pending_credit: ' . $e->getMessage());
}

// 4. Overdue supplier payments (due_date passed, not paid)
try {
    $row = $db->getOne(
        "SELECT COUNT(*) AS cnt
         FROM purchases
         WHERE payment_status != 'paid'
           AND due_amount > 0
           AND due_date IS NOT NULL
           AND due_date < CURDATE()",
        []
    );
    $pendingDues = $row ? (int)$row['cnt'] : 0;
} catch (Exception $e) {
    // due_date column might not exist in all installs — ignore
}

$total = $lowStock + $expiringBatch + $pendingCredit + $pendingDues;

echo json_encode([
    'success'         => true,
    'total'           => $total,
    'low_stock'       => $lowStock,
    'expiring_soon'   => $expiringBatch,
    'pending_credits' => $pendingCredit,
    'overdue_dues'    => $pendingDues,
]);
exit;
