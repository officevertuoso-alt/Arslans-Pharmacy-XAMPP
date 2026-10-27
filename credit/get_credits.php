<?php
/**
 * CREDIT/get_credits.php
 * Returns credit data as JSON (used by AJAX calls)
 *
 * FIXES:
 *  - CRITICAL: Removed SQL injection — $status_filter was directly interpolated into query string.
 *    Now uses whitelisted safe comparison only (no user data in SQL string).
 *  - Added LEFT JOIN sales to return invoice_no for each credit (needed by frontend)
 *  - Added sale_id in SELECT so frontend can build invoice links
 *  - Status filter 'overdue' added as new supported value
 *  - payment_date formatted consistently
 */
require_once '../config/config.php';
requireLogin();
header('Content-Type: application/json');

$db = db();

// ══════════════════════════════════════════════════════
//  SINGLE CREDIT — ?id=N
// ══════════════════════════════════════════════════════
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];

    $db->query(
        "SELECT cc.*,
            s.invoice_no,
            COALESCE((SELECT SUM(amount) FROM credit_payments WHERE credit_id = cc.id), 0) AS paid
         FROM customer_credits cc
         LEFT JOIN sales s ON s.id = cc.sale_id
         WHERE cc.id = ?",
        [$id]
    );
    $credit = $db->single();

    if (!$credit) {
        echo json_encode(['success' => false, 'message' => 'Credit not found']);
        exit;
    }

    $credit['remaining'] = $credit['total_amount'] - $credit['paid'];

    // Full payment history
    $db->query(
        "SELECT *,
            DATE_FORMAT(payment_date, '%Y-%m-%d %H:%i:%s') AS payment_date
         FROM credit_payments
         WHERE credit_id = ?
         ORDER BY payment_date DESC",
        [$id]
    );
    $payments = $db->resultSet();

    echo json_encode([
        'success'  => true,
        'credit'   => $credit,
        'payments' => $payments
    ]);
    exit;
}

// ══════════════════════════════════════════════════════
//  ALL CREDITS — with optional status filter & search
// ══════════════════════════════════════════════════════

// FIXED: whitelist filter values, NEVER interpolate user input into SQL
$allowed_statuses = ['active', 'paid', 'all', 'overdue'];
$status_filter    = $_GET['status'] ?? 'active';
if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = 'active';
}

$search = trim($_GET['search'] ?? '');

// Build WHERE clauses using only safe, whitelisted strings
// — no user data is ever placed directly into the SQL string
$where  = [];
$params = [];

switch ($status_filter) {
    case 'active':
        $where[] = "cc.status = 'active'";
        break;
    case 'paid':
        $where[] = "cc.status = 'paid'";
        break;
    case 'overdue':
        $where[] = "cc.status = 'active'";
        $where[] = "cc.due_date < CURDATE()";
        $where[] = "(cc.total_amount - COALESCE((SELECT SUM(amount) FROM credit_payments WHERE credit_id = cc.id), 0)) > 0.005";
        break;
    case 'all':
    default:
        $where[] = "cc.status IN ('active', 'paid', 'cancelled')";
        break;
}

// Optional search — safely parameterised
if ($search !== '') {
    $where[]  = "(cc.customer_name LIKE ? OR cc.customer_phone LIKE ? OR cc.description LIKE ? OR s.invoice_no LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereSQL = count($where) ? implode(' AND ', $where) : '1=1';

$db->query(
    "SELECT cc.*,
        s.invoice_no,
        COALESCE((SELECT SUM(amount) FROM credit_payments WHERE credit_id = cc.id), 0) AS paid,
        (cc.total_amount - COALESCE((SELECT SUM(amount) FROM credit_payments WHERE credit_id = cc.id), 0)) AS remaining
     FROM customer_credits cc
     LEFT JOIN sales s ON s.id = cc.sale_id
     WHERE {$whereSQL}
     ORDER BY
        CASE WHEN cc.status = 'active' AND cc.due_date < CURDATE() THEN 0
             WHEN cc.status = 'active' THEN 1
             ELSE 2
        END,
        cc.due_date ASC",
    $params
);
$credits = $db->resultSet();

echo json_encode(['success' => true, 'credits' => $credits]);