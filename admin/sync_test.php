<?php
/**
 * ADMIN SYNC TEST & REPAIR TOOL
 * 
 * Checks 15 integrity rules. Provides auto-fix buttons.
 * Admin only — requires isAdmin() or canView('admin')
 */

require_once '../config/config.php';
require_once '../includes/sync_engine.php';
require_once '../includes/cashbook_functions.php';

// Admin permission check
if (!isAdmin()) {
    header('HTTP/1.1 403 Forbidden');
    die('Access denied. Admin only.');
}

$db = db();

// ==========================================================================
// AJAX HANDLERS (run fixes)
// ==========================================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $result = ['success' => false, 'message' => 'Unknown action'];

    // Helper to execute fix and return affected rows
    function fix_check1() {
        $db = db();
        $affected = 0;
        $missing = $db->getAll(
            "SELECT s.id, s.invoice_no, s.grand_total, s.payment_method, DATE(s.sale_date) as sale_date
             FROM sales s
             LEFT JOIN cashbook c ON c.reference_id = s.id AND c.reference_type = 'sale' AND c.entry_type = 'sale'
             WHERE DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
               AND s.payment_method IN ('cash','card','online')
               AND c.id IS NULL
               AND s.status != 'voided'"
        );
        foreach ($missing as $row) {
            ensureCashbookEntry(
                entry_type: 'sale',
                reference_id: $row['id'],
                reference_type: 'sale',
                description: 'Sale Invoice: ' . $row['invoice_no'],
                debit: 0,
                credit: (float)$row['grand_total'],
                payment_method: $row['payment_method'],
                entry_date: $row['sale_date']
            );
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check2() {
        $db = db();
        $affected = 0;
        $missing = $db->getAll(
            "SELECT cp.id, cp.amount, cp.payment_method, cp.payment_date, cc.customer_name
             FROM credit_payments cp
             JOIN customer_credits cc ON cc.id = cp.credit_id
             LEFT JOIN cashbook c ON c.reference_id = cp.id AND c.reference_type = 'credit_payment'
             WHERE c.id IS NULL"
        );
        foreach ($missing as $row) {
            ensureCashbookEntry(
                entry_type: 'credit_received',
                reference_id: $row['id'],
                reference_type: 'credit_payment',
                description: 'Credit payment from ' . $row['customer_name'],
                debit: 0,
                credit: (float)$row['amount'],
                payment_method: $row['payment_method'],
                entry_date: $row['payment_date']
            );
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check3() {
        $db = db();
        $affected = 0;
        $missing = $db->getAll(
            "SELECT p.id, p.purchase_invoice_no, p.paid_amount, p.payment_method, p.purchase_date
             FROM purchases p
             LEFT JOIN cashbook c ON c.reference_id = p.id AND c.reference_type = 'purchase' AND c.entry_type = 'purchase_payment'
             WHERE p.payment_status IN ('paid','partial')
               AND c.id IS NULL"
        );
        foreach ($missing as $row) {
            ensureCashbookEntry(
                entry_type: 'purchase_payment',
                reference_id: $row['id'],
                reference_type: 'purchase',
                description: 'Purchase payment: ' . $row['purchase_invoice_no'],
                debit: (float)$row['paid_amount'],
                credit: 0,
                payment_method: $row['payment_method'],
                entry_date: $row['purchase_date']
            );
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check4() {
        $db = db();
        $affected = 0;
        $missing = $db->getAll(
            "SELECT e.id, e.amount, e.payment_method, e.date, e.description
             FROM expenses e
             LEFT JOIN cashbook c ON c.reference_id = e.id AND c.reference_type = 'expense'
             WHERE c.id IS NULL AND e.is_deleted = 0"
        );
        foreach ($missing as $row) {
            ensureCashbookEntry(
                entry_type: 'expense',
                reference_id: $row['id'],
                reference_type: 'expense',
                description: $row['description'],
                debit: (float)$row['amount'],
                credit: 0,
                payment_method: $row['payment_method'],
                entry_date: $row['date']
            );
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check5() {
        $db = db();
        $affected = 0;
        $missing = $db->getAll(
            "SELECT sr.id, sr.return_no, sr.total_amount, sr.refund_method, sr.created_at
             FROM sale_returns sr
             LEFT JOIN cashbook c ON c.reference_id = sr.id AND c.reference_type = 'sale_return'
             WHERE sr.refund_method IN ('cash','bank')
               AND c.id IS NULL"
        );
        foreach ($missing as $row) {
            ensureCashbookEntry(
                entry_type: 'sale_return',
                reference_id: $row['id'],
                reference_type: 'sale_return',
                description: 'Sale Return ' . $row['return_no'],
                debit: (float)$row['total_amount'],
                credit: 0,
                payment_method: $row['refund_method'],
                entry_date: $row['created_at']
            );
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check6() {
        $db = db();
        $affected = 0;
        $missing = $db->getAll(
            "SELECT pr.id, pr.return_no, pr.total_amount, pr.refund_method, pr.created_at
             FROM purchase_returns pr
             LEFT JOIN cashbook c ON c.reference_id = pr.id AND c.reference_type = 'purchase_return'
             WHERE pr.refund_method IN ('cash','bank')
               AND c.id IS NULL"
        );
        foreach ($missing as $row) {
            ensureCashbookEntry(
                entry_type: 'purchase_return',
                reference_id: $row['id'],
                reference_type: 'purchase_return',
                description: 'Purchase Return ' . $row['return_no'],
                debit: 0,
                credit: (float)$row['total_amount'],
                payment_method: $row['refund_method'],
                entry_date: $row['created_at']
            );
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check7() {
        $db = db();
        $affected = 0;
        $negative = $db->getAll("SELECT id, batch_number, remaining_quantity FROM batches WHERE remaining_quantity < 0");
        foreach ($negative as $row) {
            $db->run("UPDATE batches SET remaining_quantity = 0 WHERE id = :id", [':id' => $row['id']]);
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check8() {
        $db = db();
        $affected = 0;
        $credits = $db->getAll(
            "SELECT cc.id, cc.total_amount,
                    COALESCE(SUM(cp.amount), 0) AS total_paid
             FROM customer_credits cc
             LEFT JOIN credit_payments cp ON cp.credit_id = cc.id
             GROUP BY cc.id
             HAVING (cc.status = 'active' AND total_paid >= total_amount - 0.01)
                OR (cc.status = 'paid' AND total_paid < total_amount - 0.01)"
        );
        foreach ($credits as $cr) {
            $newStatus = ((float)$cr['total_paid'] >= (float)$cr['total_amount'] - 0.01) ? 'paid' : 'active';
            $db->run("UPDATE customer_credits SET status = :status WHERE id = :id", [':status' => $newStatus, ':id' => $cr['id']]);
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check9() {
        $db = db();
        $affected = 0;
        $sales = $db->getAll(
            "SELECT id, grand_total, paid_amount, payment_status
             FROM sales
             WHERE (paid_amount >= grand_total - 0.01 AND payment_status != 'paid')
                OR (paid_amount < grand_total - 0.01 AND paid_amount > 0 AND payment_status != 'partial')
                OR (paid_amount = 0 AND payment_status != 'pending' AND is_credit = 0)"
        );
        foreach ($sales as $s) {
            $newStatus = ($s['paid_amount'] >= $s['grand_total'] - 0.01) ? 'paid' : (($s['paid_amount'] > 0) ? 'partial' : 'pending');
            $db->run("UPDATE sales SET payment_status = :status WHERE id = :id", [':status' => $newStatus, ':id' => $s['id']]);
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check10() {
        $db = db();
        $affected = 0;
        $sales = $db->getAll(
            "SELECT s.id, s.grand_total,
                    COALESCE(SUM(sr.total_amount), 0) AS total_returned
             FROM sales s
             LEFT JOIN sale_returns sr ON sr.sale_id = s.id
             GROUP BY s.id
             HAVING ABS((s.grand_total - COALESCE(SUM(sr.total_amount), 0)) - COALESCE(s.net_amount, 0)) > 0.01"
        );
        foreach ($sales as $s) {
            $newNet = (float)$s['grand_total'] - (float)$s['total_returned'];
            $db->run("UPDATE sales SET net_amount = :net WHERE id = :id", [':net' => $newNet, ':id' => $s['id']]);
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check11() {
        $db = db();
        $affected = 0;
        $purchases = $db->getAll(
            "SELECT id, total, paid_amount
             FROM purchases
             WHERE ABS((total - paid_amount) - COALESCE(due_amount, 0)) > 0.01"
        );
        foreach ($purchases as $p) {
            $newDue = max(0, (float)$p['total'] - (float)$p['paid_amount']);
            $db->run("UPDATE purchases SET due_amount = :due WHERE id = :id", [':due' => $newDue, ':id' => $p['id']]);
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check13() {
        $db = db();
        $affected = 0;
        $orphans = $db->getAll(
            "SELECT si.id FROM sale_items si
             LEFT JOIN sales s ON s.id = si.sale_id
             WHERE s.id IS NULL"
        );
        foreach ($orphans as $o) {
            $db->run("DELETE FROM sale_items WHERE id = :id", [':id' => $o['id']]);
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check14() {
        $db = db();
        $affected = 0;
        $suppliers = $db->getAll(
            "SELECT s.id, s.current_balance,
                    COALESCE(SUM(p.total - p.paid_amount), 0) AS calculated_balance
             FROM suppliers s
             LEFT JOIN purchases p ON p.supplier_id = s.id AND p.payment_status != 'paid'
             GROUP BY s.id
             HAVING ABS(s.current_balance - COALESCE(SUM(p.total - p.paid_amount), 0)) > 0.01"
        );
        foreach ($suppliers as $sup) {
            $db->run("UPDATE suppliers SET current_balance = :bal WHERE id = :id", [':bal' => $sup['calculated_balance'], ':id' => $sup['id']]);
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    function fix_check15() {
        $db = db();
        $affected = 0;
        for ($i = 0; $i <= 7; $i++) {
            $date = date('Y-m-d', strtotime("-$i days"));
            _recalculateDailyBalance($date);
            $affected++;
        }
        return ['success' => true, 'fixed' => $affected];
    }

    // Execute fix based on action
    switch ($action) {
        case 'fix1': $result = fix_check1(); break;
        case 'fix2': $result = fix_check2(); break;
        case 'fix3': $result = fix_check3(); break;
        case 'fix4': $result = fix_check4(); break;
        case 'fix5': $result = fix_check5(); break;
        case 'fix6': $result = fix_check6(); break;
        case 'fix7': $result = fix_check7(); break;
        case 'fix8': $result = fix_check8(); break;
        case 'fix9': $result = fix_check9(); break;
        case 'fix10': $result = fix_check10(); break;
        case 'fix11': $result = fix_check11(); break;
        case 'fix13': $result = fix_check13(); break;
        case 'fix14': $result = fix_check14(); break;
        case 'fix15': $result = fix_check15(); break;
        case 'fix_all':
            // Run all fixes sequentially
            $all = [];
            for ($i=1; $i<=15; $i++) {
                if ($i==12) continue; // skip duplicate check
                $func = "fix_check$i";
                if (function_exists($func)) {
                    $all[] = $func();
                }
            }
            $result = ['success' => true, 'message' => 'All fixes applied'];
            break;
    }
    echo json_encode($result);
    exit;
}

// ==========================================================================
// FUNCTION TO RUN CHECKS AND RETURN STATUS (for page load)
// ==========================================================================
function runAllChecks() {
    $db = db();
    $results = [];

    // Check 1: Sales missing cashbook entries
    $check1_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM sales s
         LEFT JOIN cashbook c ON c.reference_id = s.id AND c.reference_type = 'sale' AND c.entry_type = 'sale'
         WHERE DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
           AND s.payment_method IN ('cash','card','online')
           AND c.id IS NULL
           AND s.status != 'voided'"
    )['cnt'] ?? 0;
    $results['check1'] = ['passed' => $check1_count == 0, 'count' => $check1_count, 'message' => "$check1_count sales missing cashbook entries"];

    // Check 2: Credit payments missing cashbook
    $check2_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM credit_payments cp
         LEFT JOIN cashbook c ON c.reference_id = cp.id AND c.reference_type = 'credit_payment'
         WHERE c.id IS NULL"
    )['cnt'] ?? 0;
    $results['check2'] = ['passed' => $check2_count == 0, 'count' => $check2_count, 'message' => "$check2_count credit payments missing cashbook"];

    // Check 3: Paid/partial purchases missing cashbook
    $check3_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM purchases p
         LEFT JOIN cashbook c ON c.reference_id = p.id AND c.reference_type = 'purchase' AND c.entry_type = 'purchase_payment'
         WHERE p.payment_status IN ('paid','partial')
           AND c.id IS NULL"
    )['cnt'] ?? 0;
    $results['check3'] = ['passed' => $check3_count == 0, 'count' => $check3_count, 'message' => "$check3_count purchases missing cashbook"];

    // Check 4: Expenses missing cashbook
    $check4_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM expenses e
         LEFT JOIN cashbook c ON c.reference_id = e.id AND c.reference_type = 'expense'
         WHERE c.id IS NULL AND e.is_deleted = 0"
    )['cnt'] ?? 0;
    $results['check4'] = ['passed' => $check4_count == 0, 'count' => $check4_count, 'message' => "$check4_count expenses missing cashbook"];

    // Check 5: Sale returns with cash refund missing cashbook debit
    $check5_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM sale_returns sr
         LEFT JOIN cashbook c ON c.reference_id = sr.id AND c.reference_type = 'sale_return'
         WHERE sr.refund_method IN ('cash','bank')
           AND c.id IS NULL"
    )['cnt'] ?? 0;
    $results['check5'] = ['passed' => $check5_count == 0, 'count' => $check5_count, 'message' => "$check5_count sale returns missing cashbook debit"];

    // Check 6: Purchase returns with cash refund missing cashbook credit
    $check6_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM purchase_returns pr
         LEFT JOIN cashbook c ON c.reference_id = pr.id AND c.reference_type = 'purchase_return'
         WHERE pr.refund_method IN ('cash','bank')
           AND c.id IS NULL"
    )['cnt'] ?? 0;
    $results['check6'] = ['passed' => $check6_count == 0, 'count' => $check6_count, 'message' => "$check6_count purchase returns missing cashbook credit"];

    // Check 7: Negative batch quantities
    $check7_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM batches WHERE remaining_quantity < 0"
    )['cnt'] ?? 0;
    $results['check7'] = ['passed' => $check7_count == 0, 'count' => $check7_count, 'message' => "$check7_count batches with negative stock"];

    // Check 8: Credit status mismatch
    $check8_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM (
            SELECT cc.id,
                   COALESCE(SUM(cp.amount), 0) AS total_paid
            FROM customer_credits cc
            LEFT JOIN credit_payments cp ON cp.credit_id = cc.id
            GROUP BY cc.id
            HAVING (cc.status = 'active' AND total_paid >= total_amount - 0.01)
               OR (cc.status = 'paid' AND total_paid < total_amount - 0.01)
        ) AS tmp"
    )['cnt'] ?? 0;
    $results['check8'] = ['passed' => $check8_count == 0, 'count' => $check8_count, 'message' => "$check8_count credits with wrong status"];

    // Check 9: Sales payment_status mismatch
    $check9_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM sales
         WHERE (paid_amount >= grand_total - 0.01 AND payment_status != 'paid')
            OR (paid_amount < grand_total - 0.01 AND paid_amount > 0 AND payment_status != 'partial')
            OR (paid_amount = 0 AND payment_status != 'pending' AND is_credit = 0)"
    )['cnt'] ?? 0;
    $results['check9'] = ['passed' => $check9_count == 0, 'count' => $check9_count, 'message' => "$check9_count sales with mismatched payment_status"];

    // Check 10: sales.net_amount mismatch
    $check10_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM (
            SELECT s.id, s.grand_total,
                   COALESCE(SUM(sr.total_amount), 0) AS total_returned
            FROM sales s
            LEFT JOIN sale_returns sr ON sr.sale_id = s.id
            GROUP BY s.id
            HAVING ABS((s.grand_total - COALESCE(SUM(sr.total_amount), 0)) - COALESCE(s.net_amount, 0)) > 0.01
        ) AS tmp"
    )['cnt'] ?? 0;
    $results['check10'] = ['passed' => $check10_count == 0, 'count' => $check10_count, 'message' => "$check10_count sales with incorrect net_amount"];

    // Check 11: purchases.due_amount mismatch
    $check11_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM purchases
         WHERE ABS((total - paid_amount) - COALESCE(due_amount, 0)) > 0.01"
    )['cnt'] ?? 0;
    $results['check11'] = ['passed' => $check11_count == 0, 'count' => $check11_count, 'message' => "$check11_count purchases with incorrect due_amount"];

    // Check 12: Duplicate invoice numbers
    $check12_dupes = $db->getAll(
        "SELECT invoice_no, COUNT(*) as cnt FROM sales GROUP BY invoice_no HAVING cnt > 1"
    );
    $results['check12'] = ['passed' => empty($check12_dupes), 'count' => count($check12_dupes), 'message' => count($check12_dupes) . " duplicate invoice numbers exist", 'details' => $check12_dupes];

    // Check 13: Orphan sale_items
    $check13_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM sale_items si
         LEFT JOIN sales s ON s.id = si.sale_id
         WHERE s.id IS NULL"
    )['cnt'] ?? 0;
    $results['check13'] = ['passed' => $check13_count == 0, 'count' => $check13_count, 'message' => "$check13_count orphan sale_items"];

    // Check 14: Supplier balance accuracy
    $check14_count = $db->getOne(
        "SELECT COUNT(*) as cnt FROM (
            SELECT s.id, s.current_balance,
                   COALESCE(SUM(p.total - p.paid_amount), 0) AS calculated_balance
            FROM suppliers s
            LEFT JOIN purchases p ON p.supplier_id = s.id AND p.payment_status != 'paid'
            GROUP BY s.id
            HAVING ABS(s.current_balance - COALESCE(SUM(p.total - p.paid_amount), 0)) > 0.01
        ) AS tmp"
    )['cnt'] ?? 0;
    $results['check14'] = ['passed' => $check14_count == 0, 'count' => $check14_count, 'message' => "$check14_count suppliers with incorrect current_balance"];

    // Check 15: Cashbook running balance (last 7 days)
    $check15_errors = 0;
    for ($i = 0; $i <= 7; $i++) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $entries = $db->getAll("SELECT id, debit, credit, balance FROM cashbook WHERE entry_date = :date ORDER BY id ASC", [':date' => $date]);
        $running = $db->getOne("SELECT balance FROM cashbook WHERE entry_date < :date ORDER BY entry_date DESC, id DESC LIMIT 1", [':date' => $date])['balance'] ?? 0;
        foreach ($entries as $e) {
            $running += (float)$e['credit'] - (float)$e['debit'];
            if (abs($running - (float)$e['balance']) > 0.01) $check15_errors++;
        }
    }
    $results['check15'] = ['passed' => $check15_errors == 0, 'count' => $check15_errors, 'message' => "$check15_errors cashbook balance errors in last 7 days"];

    return $results;
}

$checks = runAllChecks();
$passedCount = 0;
foreach ($checks as $c) {
    if ($c['passed']) $passedCount++;
}
$healthScore = $passedCount;
$healthColor = $healthScore >= 12 ? 'success' : ($healthScore >= 8 ? 'warning' : 'danger');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Health Check & Repair</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .main-content { margin-left: 260px; padding: 20px; }
        .card { border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .health-score { font-size: 48px; font-weight: bold; }
        .check-item { padding: 10px 15px; border-bottom: 1px solid #eef2f6; }
        .check-item:last-child { border-bottom: none; }
        .badge-pass { background: #d4edda; color: #155724; }
        .badge-fail { background: #f8d7da; color: #721c24; }
        .btn-fix { padding: 2px 8px; font-size: 11px; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
    </style>
<?php if (function_exists("generateDynamicCSS")) echo generateDynamicCSS(); ?>
</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">
<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>
<div class="main-content">
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-stethoscope me-2 text-primary"></i>Sync Health Check & Repair</h4>
            <p class="text-muted mb-0">15 integrity checks — auto-fix available</p>
        </div>
        <div>
            <button class="btn btn-danger btn-sm" id="fixAllBtn"><i class="fas fa-tools me-1"></i> Run ALL Fixes</button>
            <button class="btn btn-outline-secondary btn-sm ms-2" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
        </div>
    </div>

    <!-- Health Score Card -->
    <div class="card text-center p-4 bg-<?php echo $healthColor; ?> text-white">
        <div class="health-score"><?php echo $healthScore; ?>/15</div>
        <div class="small">Database Integrity Score</div>
        <div class="mt-2"><?php echo $passedCount; ?> checks passed, <?php echo 15 - $passedCount; ?> failed</div>
    </div>

    <!-- Checks List -->
    <div class="card">
        <div class="card-header bg-white fw-semibold">Integrity Checks</div>
        <div class="card-body p-0">
            <?php
            $checkLabels = [
                1 => 'Cash/Card/Online sales without cashbook entries',
                2 => 'Credit payments without cashbook entries',
                3 => 'Paid/partial purchases without cashbook entries',
                4 => 'Expenses without cashbook entries',
                5 => 'Sale returns (cash/bank) without cashbook debit',
                6 => 'Purchase returns (cash/bank) without cashbook credit',
                7 => 'Batches with negative quantity',
                8 => 'Customer credits with wrong status',
                9 => 'Sales payment_status mismatch',
                10 => 'Sales net_amount mismatch (grand_total - returns)',
                11 => 'Purchases due_amount mismatch (total - paid)',
                12 => 'Duplicate invoice numbers',
                13 => 'Orphan sale_items (no parent sale)',
                14 => 'Supplier current_balance inaccurate',
                15 => 'Cashbook running balance errors (last 7 days)',
            ];
            for ($i = 1; $i <= 15; $i++):
                $check = $checks["check$i"];
                $passed = $check['passed'];
                $count = $check['count'] ?? 0;
                $statusClass = $passed ? 'badge-pass' : 'badge-fail';
                $statusText = $passed ? 'PASS' : 'FAIL';
            ?>
            <div class="check-item d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge <?php echo $statusClass; ?> me-2"><?php echo $statusText; ?></span>
                    <strong>Check <?php echo $i; ?>:</strong> <?php echo $checkLabels[$i]; ?>
                    <div class="small text-muted mt-1"><?php echo $check['message']; ?></div>
                    <?php if ($i == 12 && !empty($check['details'])): ?>
                        <div class="small mt-1">Duplicates: <?php foreach ($check['details'] as $d) echo $d['invoice_no'] . ' (' . $d['cnt'] . ' times) '; ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!$passed && $i != 12): ?>
                <button class="btn btn-sm btn-primary btn-fix fix-btn" data-check="<?php echo $i; ?>"><i class="fas fa-wrench me-1"></i>Fix</button>
                <?php elseif (!$passed && $i == 12): ?>
                <span class="text-muted small">Manual review required</span>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- DB Summary Card -->
    <div class="card">
        <div class="card-header bg-white fw-semibold">Database Summary</div>
        <div class="card-body">
            <?php
            $summary = [];
            $summary['sales'] = $db->getOne("SELECT COUNT(*) as cnt FROM sales")['cnt'] ?? 0;
            $summary['purchases'] = $db->getOne("SELECT COUNT(*) as cnt FROM purchases")['cnt'] ?? 0;
            $summary['batches'] = $db->getOne("SELECT COUNT(*) as cnt FROM batches")['cnt'] ?? 0;
            $summary['cashbook'] = $db->getOne("SELECT COUNT(*) as cnt FROM cashbook")['cnt'] ?? 0;
            $summary['credits'] = $db->getOne("SELECT COUNT(*) as cnt FROM customer_credits")['cnt'] ?? 0;
            $summary['suppliers'] = $db->getOne("SELECT COUNT(*) as cnt FROM suppliers")['cnt'] ?? 0;
            $summary['products'] = $db->getOne("SELECT COUNT(*) as cnt FROM products WHERE status=1")['cnt'] ?? 0;
            ?>
            <div class="row text-center">
                <div class="col-md-3 col-6 mb-2"><strong>Sales:</strong> <?php echo number_format($summary['sales']); ?></div>
                <div class="col-md-3 col-6 mb-2"><strong>Purchases:</strong> <?php echo number_format($summary['purchases']); ?></div>
                <div class="col-md-3 col-6 mb-2"><strong>Batches:</strong> <?php echo number_format($summary['batches']); ?></div>
                <div class="col-md-3 col-6 mb-2"><strong>Cashbook Entries:</strong> <?php echo number_format($summary['cashbook']); ?></div>
                <div class="col-md-3 col-6 mb-2"><strong>Customer Credits:</strong> <?php echo number_format($summary['credits']); ?></div>
                <div class="col-md-3 col-6 mb-2"><strong>Suppliers:</strong> <?php echo number_format($summary['suppliers']); ?></div>
                <div class="col-md-3 col-6 mb-2"><strong>Active Products:</strong> <?php echo number_format($summary['products']); ?></div>
            </div>
        </div>
    </div>

</div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function() {
    // Individual fix buttons
    $('.fix-btn').on('click', function() {
        let $btn = $(this);
        let check = $btn.data('check');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Fixing...');
        $.get(window.location.href + '?action=fix' + check, function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert('Fix failed: ' + (res.message || 'Unknown error'));
                $btn.prop('disabled', false).html('<i class="fas fa-wrench me-1"></i>Fix');
            }
        }, 'json').fail(function() {
            alert('Server error');
            $btn.prop('disabled', false).html('<i class="fas fa-wrench me-1"></i>Fix');
        });
    });

    // Fix all
    $('#fixAllBtn').on('click', function() {
        if (!confirm('Run ALL fixes? This will attempt to repair all detected issues.')) return;
        let $btn = $(this);
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Running all fixes...');
        $.get(window.location.href + '?action=fix_all', function(res) {
            location.reload();
        }, 'json').fail(function() {
            alert('Server error while running fixes');
            $btn.prop('disabled', false).html('<i class="fas fa-tools me-1"></i> Run ALL Fixes');
        });
    });
});
</script>
</body>
</html>