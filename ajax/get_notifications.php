<?php
/**
 * AJAX — Navbar Bell Notifications (Enhanced Returns & Cashbook Aware)
 * File: ajax/get_notifications.php
 *
 * Returns JSON with grouped alerts:
 *   - expired
 *   - near_expiry
 *   - low_stock
 *   - overdue_credits
 *   - pending_supplier_payments
 *   - unprocessed_returns
 *   - cashbook_gaps
 *   - summary (total_alerts, critical_count)
 */

// ── Buffer ALL output so any PHP warning/notice/error doesn't corrupt JSON ──
ob_start();

// ── Suppress display of errors for this AJAX endpoint (log them instead) ────
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';

// ── Discard anything that leaked before we set the header ───────────────────
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthenticated.']);
    exit;
}

// Constants
define('NOTIF_NEAR_EXPIRY_DAYS', 30);
define('NOTIF_MAX_ITEMS_PER_CAT', 10);

$response = [
    'expired'                  => ['count' => 0, 'items' => []],
    'near_expiry'              => ['count' => 0, 'items' => []],
    'low_stock'                => ['count' => 0, 'items' => []],
    'overdue_credits'          => ['count' => 0, 'total_amount' => 0, 'items' => []],
    'pending_supplier_payments'=> ['count' => 0, 'total_amount' => 0, 'items' => []],
    'unprocessed_returns'      => ['count' => 0, 'items' => []],
    'cashbook_gaps'            => ['count' => 0, 'items' => []],
    'summary'                  => ['total_alerts' => 0, 'critical_count' => 0],
];

// Helper to safely format currency
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return 'Rs ' . number_format($amount, 2);
    }
}

// ─────────────────────────────────────────────────────────────
// 1. EXPIRED BATCHES (remaining > 0)
// ─────────────────────────────────────────────────────────────
try {
    $expired = getAll(
        "SELECT p.product_name, b.batch_number, b.expiry_date, b.remaining_quantity
         FROM batches b
         JOIN products p ON p.id = b.product_id
         WHERE b.expiry_date < CURDATE()
           AND b.remaining_quantity > 0
           AND b.is_expired = 1
         ORDER BY b.expiry_date ASC
         LIMIT " . NOTIF_MAX_ITEMS_PER_CAT,
        []
    );
    $response['expired']['count'] = count($expired);
    $response['expired']['items'] = array_map(function($row) {
        return [
            'product' => htmlspecialchars($row['product_name']),
            'batch'   => htmlspecialchars($row['batch_number']),
            'expiry'  => formatDate($row['expiry_date']),
            'qty'     => (int)$row['remaining_quantity'],
            'link'    => '/pharmacy_software/reports/expiry_report.php?status=expired',
        ];
    }, $expired);
} catch (Exception $e) { error_log("Expired notif error: " . $e->getMessage()); }

// ─────────────────────────────────────────────────────────────
// 2. NEAR EXPIRY (expiry between today and next 30 days)
// ─────────────────────────────────────────────────────────────
try {
    $near = getAll(
        "SELECT p.product_name, b.batch_number, b.expiry_date, b.remaining_quantity,
                DATEDIFF(b.expiry_date, CURDATE()) AS days_left
         FROM batches b
         JOIN products p ON p.id = b.product_id
         WHERE b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
           AND b.remaining_quantity > 0
           AND b.is_expired = 0
         ORDER BY b.expiry_date ASC
         LIMIT " . NOTIF_MAX_ITEMS_PER_CAT,
        [':days' => NOTIF_NEAR_EXPIRY_DAYS]
    );
    $response['near_expiry']['count'] = count($near);
    $response['near_expiry']['items'] = array_map(function($row) {
        return [
            'product'  => htmlspecialchars($row['product_name']),
            'batch'    => htmlspecialchars($row['batch_number']),
            'days'     => (int)$row['days_left'],
            'qty'      => (int)$row['remaining_quantity'],
            'expiry'   => formatDate($row['expiry_date']),
            'link'     => '/pharmacy_software/reports/expiry_report.php?status=near',
        ];
    }, $near);
} catch (Exception $e) { error_log("Near expiry notif error: " . $e->getMessage()); }

// ─────────────────────────────────────────────────────────────
// 3. LOW STOCK (total remaining <= reorder_level)
// ─────────────────────────────────────────────────────────────
try {
    $low = getAll(
        "SELECT p.product_name, p.reorder_level, p.id AS product_id,
                SUM(b.remaining_quantity) AS total_stock
         FROM batches b
         JOIN products p ON p.id = b.product_id
         WHERE b.is_expired = 0 AND b.expiry_date > CURDATE()
         GROUP BY p.id
         HAVING total_stock <= p.reorder_level AND total_stock > 0
         ORDER BY total_stock ASC
         LIMIT " . NOTIF_MAX_ITEMS_PER_CAT,
        []
    );
    $response['low_stock']['count'] = count($low);
    $response['low_stock']['items'] = array_map(function($row) {
        return [
            'product' => htmlspecialchars($row['product_name']),
            'stock'   => (int)$row['total_stock'],
            'reorder' => (int)$row['reorder_level'],
            'link'    => '/pharmacy_software/products/view.php?id=' . (int)$row['product_id'],
        ];
    }, $low);
} catch (Exception $e) { error_log("Low stock notif error: " . $e->getMessage()); }

// ─────────────────────────────────────────────────────────────
// 4. OVERDUE CREDITS (due_date < today, status = active)
// ─────────────────────────────────────────────────────────────
try {
    $credits = getAll(
        "SELECT cc.customer_name, cc.total_amount, cc.due_date,
                COALESCE(SUM(cp.amount), 0) AS paid
         FROM customer_credits cc
         LEFT JOIN credit_payments cp ON cp.credit_id = cc.id
         WHERE cc.due_date < CURDATE()
           AND cc.status IN ('active','overdue')
         GROUP BY cc.id
         ORDER BY cc.due_date ASC
         LIMIT " . NOTIF_MAX_ITEMS_PER_CAT,
        []
    );
    $response['overdue_credits']['count'] = count($credits);
    $totalOverdue = 0;
    $response['overdue_credits']['items'] = array_map(function($row) use (&$totalOverdue) {
        $remaining = (float)$row['total_amount'] - (float)$row['paid'];
        $totalOverdue += $remaining;
        return [
            'customer'  => htmlspecialchars($row['customer_name']),
            'amount'    => formatCurrency($remaining),
            'due_date'  => formatDate($row['due_date']),
            'link'      => '/pharmacy_software/credit/index.php?filter=overdue',
        ];
    }, $credits);
    $response['overdue_credits']['total_amount'] = $totalOverdue;
} catch (Exception $e) { error_log("Overdue credits notif error: " . $e->getMessage()); }

// ─────────────────────────────────────────────────────────────
// 5. PENDING SUPPLIER PAYMENTS (purchases with due_amount > 0)
// ─────────────────────────────────────────────────────────────
try {
    $suppliers = getAll(
        "SELECT s.supplier_name, SUM(p.due_amount) AS total_due
         FROM purchases p
         JOIN suppliers s ON s.id = p.supplier_id
         WHERE p.payment_status != 'paid' AND p.due_amount > 0
         GROUP BY p.supplier_id
         ORDER BY total_due DESC
         LIMIT " . NOTIF_MAX_ITEMS_PER_CAT,
        []
    );
    $response['pending_supplier_payments']['count'] = count($suppliers);
    $totalSupplierDue = 0;
    $response['pending_supplier_payments']['items'] = array_map(function($row) use (&$totalSupplierDue) {
        $due = (float)$row['total_due'];
        $totalSupplierDue += $due;
        return [
            'supplier' => htmlspecialchars($row['supplier_name']),
            'amount'   => formatCurrency($due),
            'link'     => '/pharmacy_software/suppliers/list.php',
        ];
    }, $suppliers);
    $response['pending_supplier_payments']['total_amount'] = $totalSupplierDue;
} catch (Exception $e) { error_log("Pending supplier payments notif error: " . $e->getMessage()); }

// ─────────────────────────────────────────────────────────────
// 6. UNPROCESSED RETURNS (sale_returns or purchase_returns with status = 'pending')
// ─────────────────────────────────────────────────────────────
try {
    $saleReturns = getAll(
        "SELECT id, return_no, sale_id FROM sale_returns WHERE status = 'pending' LIMIT " . NOTIF_MAX_ITEMS_PER_CAT,
        []
    );
    $purchaseReturns = getAll(
        "SELECT id, return_no, purchase_id FROM purchase_returns WHERE status = 'pending' LIMIT " . NOTIF_MAX_ITEMS_PER_CAT,
        []
    );
    $totalReturns = count($saleReturns) + count($purchaseReturns);
    $response['unprocessed_returns']['count'] = $totalReturns;
    $items = [];
    foreach ($saleReturns as $r) {
        $items[] = [
            'type' => 'Sale Return',
            'ref'  => htmlspecialchars($r['return_no']),
            'link' => '/pharmacy_software/returns/sale_return_view.php?id=' . $r['id'],
        ];
    }
    foreach ($purchaseReturns as $r) {
        $items[] = [
            'type' => 'Purchase Return',
            'ref'  => htmlspecialchars($r['return_no']),
            'link' => '/pharmacy_software/returns/purchase_return_view.php?id=' . $r['id'],
        ];
    }
    $response['unprocessed_returns']['items'] = array_slice($items, 0, NOTIF_MAX_ITEMS_PER_CAT);
} catch (Exception $e) { error_log("Unprocessed returns notif error: " . $e->getMessage()); }

// ─────────────────────────────────────────────────────────────
// 7. CASHBOOK GAPS (sales missing cashbook entry, purchases missing payment entry)
// ─────────────────────────────────────────────────────────────
try {
    // Sales (non-credit) that should have a 'sale' cashbook entry
    $missingSales = getAll(
        "SELECT s.id, s.invoice_no, s.net_amount
         FROM sales s
         WHERE s.payment_method != 'credit'
           AND s.is_credit = 0
           AND COALESCE(s.status,'active') != 'voided'
           AND NOT EXISTS (
               SELECT 1 FROM cashbook
               WHERE reference_id = s.id
                 AND reference_type = 'sale'
                 AND entry_type = 'sale'
           )
         LIMIT " . NOTIF_MAX_ITEMS_PER_CAT,
        []
    );
    // Purchases (paid or partial) missing purchase_payment cashbook entry
    $missingPurchases = getAll(
        "SELECT p.id, p.purchase_invoice_no, p.paid_amount
         FROM purchases p
         WHERE p.payment_status IN ('paid', 'partial')
           AND NOT EXISTS (
               SELECT 1 FROM cashbook
               WHERE reference_id = p.id
                 AND reference_type = 'purchase'
                 AND entry_type = 'purchase_payment'
           )
         LIMIT " . NOTIF_MAX_ITEMS_PER_CAT,
        []
    );
    $totalGaps = count($missingSales) + count($missingPurchases);
    $response['cashbook_gaps']['count'] = $totalGaps;
    $items = [];
    foreach ($missingSales as $s) {
        $items[] = [
            'type'    => 'Sale',
            'ref'     => htmlspecialchars($s['invoice_no']),
            'amount'  => formatCurrency($s['net_amount']),
            'link'    => '/pharmacy_software/sales/view.php?id=' . $s['id'],
            'fix_url' => '/pharmacy_software/ajax/fix_cashbook_gap.php?type=sale&id=' . $s['id'],
        ];
    }
    foreach ($missingPurchases as $p) {
        $items[] = [
            'type'    => 'Purchase',
            'ref'     => htmlspecialchars($p['purchase_invoice_no']),
            'amount'  => formatCurrency($p['paid_amount']),
            'link'    => '/pharmacy_software/purchases/view.php?id=' . $p['id'],
            'fix_url' => '/pharmacy_software/ajax/fix_cashbook_gap.php?type=purchase&id=' . $p['id'],
        ];
    }
    $response['cashbook_gaps']['items'] = array_slice($items, 0, NOTIF_MAX_ITEMS_PER_CAT);
} catch (Exception $e) { error_log("Cashbook gaps notif error: " . $e->getMessage()); }

// ─────────────────────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────────────────────
$totalAlerts = 0;
$criticalCount = 0;

$totalAlerts += $response['expired']['count'];
if ($response['expired']['count'] > 0) $criticalCount += $response['expired']['count'];

$totalAlerts += $response['near_expiry']['count'];
$totalAlerts += $response['low_stock']['count'];

$totalAlerts += $response['overdue_credits']['count'];
if ($response['overdue_credits']['count'] > 0) $criticalCount += $response['overdue_credits']['count'];

$totalAlerts += $response['pending_supplier_payments']['count'];

$totalAlerts += $response['unprocessed_returns']['count'];
if ($response['unprocessed_returns']['count'] > 0) $criticalCount += $response['unprocessed_returns']['count'];

$totalAlerts += $response['cashbook_gaps']['count'];
if ($response['cashbook_gaps']['count'] > 0) $criticalCount += $response['cashbook_gaps']['count'];

$response['summary'] = [
    'total_alerts'   => $totalAlerts,
    'critical_count' => $criticalCount,
];

echo json_encode(['success' => true] + $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ob_end_flush();