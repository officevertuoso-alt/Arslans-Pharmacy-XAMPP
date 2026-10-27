<?php
/**
 * sales/customer_view.php
 * Complete customer profile + transaction history
 * Step 4 — NEW FILE
 */

require_once '../config/config.php';
require_once '../includes/sync_engine.php';

requireLogin();

$phone = trim($_GET['phone'] ?? '');
if (!$phone) {
    setFlash('error', 'Customer phone number required.', 'danger');
    header('Location: customers.php');
    exit;
}

$db = db();

// ── AJAX: Pay Credit ────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'pay_credit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    $credit_id      = (int)($_POST['credit_id'] ?? 0);
    $amount         = (float)($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'cash');
    $note           = trim($_POST['note'] ?? '');

    if (!in_array($payment_method, ['cash','card','online'], true)) $payment_method = 'cash';
    if ($credit_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid credit ID']); exit; }
    if ($amount <= 0)    { echo json_encode(['success'=>false,'message'=>'Amount must be > 0']); exit; }

    $credit = $db->getOne(
        "SELECT cc.*, s.total AS sale_total, s.paid_amount AS sale_upfront, s.invoice_no
         FROM customer_credits cc LEFT JOIN sales s ON s.id = cc.sale_id
         WHERE cc.id = :id AND cc.status = 'active'",
        [':id' => $credit_id]
    );
    if (!$credit) { echo json_encode(['success'=>false,'message'=>'Credit not found or already paid']); exit; }

    $paidRow      = $db->getOne(
        "SELECT COALESCE(SUM(amount),0) AS paid FROM credit_payments WHERE credit_id = :cid
           AND (note IS NULL OR (note NOT LIKE 'Upfront%' AND note NOT LIKE 'upfront%'))",
        [':cid' => $credit_id]
    );
    $saleTotal    = (float)($credit['sale_total']   ?? $credit['total_amount'] ?? 0);
    $saleUpfront  = (float)($credit['sale_upfront'] ?? 0);
    $postSalePaid = (float)($paidRow['paid']        ?? 0);
    $remaining    = max(0, $saleTotal - $saleUpfront - $postSalePaid);

    if ($amount > $remaining + 0.01) {
        echo json_encode(['success'=>false,'message'=>'Amount exceeds remaining Rs '.number_format($remaining,2)]);
        exit;
    }

    $db->beginTransaction();
    try {
        $db->query(
            "INSERT INTO credit_payments (credit_id,amount,payment_method,note,payment_date) VALUES (?,?,?,?,NOW())",
            [$credit_id, round($amount,2), $payment_method, $note]
        );
        $paymentId    = $db->lastId();
        $newPostPaid  = $postSalePaid + $amount;
        $newRemaining = max(0, $saleTotal - $saleUpfront - $newPostPaid);
        $nowFullyPaid = $newRemaining < 0.01;

        if ($nowFullyPaid) {
            $db->query("UPDATE customer_credits SET status='paid' WHERE id=?", [$credit_id]);
        }

        // Update linked sale
        if (!empty($credit['sale_id'])) {
            $saleRow = $db->getOne("SELECT paid_amount, total FROM sales WHERE id=:sid", [':sid'=>$credit['sale_id']]);
            if ($saleRow) {
                $newSalePaid = round((float)$saleRow['paid_amount'] + $amount, 2);
                $saleGT      = (float)$saleRow['total'];
                $payStat     = ($newSalePaid >= $saleGT) ? 'paid' : ($newSalePaid > 0 ? 'partial' : 'pending');
                $db->query("UPDATE sales SET paid_amount=?, payment_status=? WHERE id=?",
                    [$newSalePaid, $payStat, $credit['sale_id']]);
            }
        }

        $db->commit();

        // Cashbook entry — direct, no nested transaction
        if (function_exists('ensureCashbookEntry')) {
            ensureCashbookEntry(
                'credit_received', (int)$paymentId, 'credit_payment',
                'Credit payment from ' . ($credit['customer_name'] ?? 'Customer') .
                    (!empty($credit['invoice_no']) ? ' (Invoice: '.$credit['invoice_no'].')' : ''),
                0, round($amount,2), $payment_method, date('Y-m-d')
            );
        }

        if (function_exists('clearAllCaches'))          clearAllCaches();
        if (function_exists('invalidateDashboardCache')) invalidateDashboardCache();
        if (function_exists('logActivity')) {
            logActivity('Credit Payment', "Rs ".number_format($amount,2)." from ".($credit['customer_name']??'')." (Credit #{$credit_id})".($nowFullyPaid?' — FULLY PAID':''));
        }

        echo json_encode([
            'success'       => true,
            'fully_paid'    => $nowFullyPaid,
            'new_balance'   => round($newRemaining, 2),
            'new_remaining' => round($newRemaining, 2),
            'message'       => $nowFullyPaid ? 'Credit fully paid!' : 'Payment of Rs '.number_format($amount,2).' recorded',
        ]);
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
    exit;
}

// ── Load customer profile (customers table if exists) ───────────────────────
$customer = null;
try {
    $customer = $db->getOne(
        "SELECT * FROM customers WHERE phone = :phone LIMIT 1",
        [':phone' => $phone]
    );
} catch (Exception $e) { /* table may not exist */ }

// Fallback: get name from customer_credits or sales
if (!$customer) {
    $fallback = $db->getOne(
        "SELECT customer_name AS name, customer_phone AS phone FROM customer_credits
         WHERE customer_phone = :phone LIMIT 1",
        [':phone' => $phone]
    );
    if (!$fallback) {
        $fallback = $db->getOne(
            "SELECT customer_name AS name, customer_phone AS phone FROM sales
             WHERE customer_phone = :phone AND status != 'voided' LIMIT 1",
            [':phone' => $phone]
        );
    }
    if ($fallback) {
        $customer = ['name' => $fallback['name'], 'phone' => $phone, 'address' => null, 'notes' => null, 'id' => null];
    }
}

if (!$customer) {
    setFlash('error', 'Customer not found for phone: ' . htmlspecialchars($phone), 'danger');
    header('Location: customers.php');
    exit;
}

// ── Summary stats ────────────────────────────────────────────────────────────
$statSales = $db->getOne(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS total_amount
     FROM sales WHERE customer_phone = :phone AND status != 'voided'",
    [':phone' => $phone]
);
$statCredits = $db->getOne(
    "SELECT COALESCE(SUM(total_amount),0) AS total_credit,
            COALESCE(SUM(CASE WHEN status='active' THEN total_amount ELSE 0 END),0) AS outstanding
     FROM customer_credits WHERE customer_phone = :phone",
    [':phone' => $phone]
);
$statPayments = $db->getOne(
    "SELECT COALESCE(SUM(cp.amount),0) AS total_paid
     FROM credit_payments cp
     WHERE cp.credit_id IN (SELECT id FROM customer_credits WHERE customer_phone = :phone)",
    [':phone' => $phone]
);

$totalSalesAmt  = (float)($statSales['total_amount']    ?? 0);
$totalSalesCnt  = (int)($statSales['cnt']               ?? 0);
$totalCredit    = (float)($statCredits['total_credit']   ?? 0);
$outstanding    = (float)($statCredits['outstanding']    ?? 0);
$totalPaid      = (float)($statPayments['total_paid']    ?? 0);

// ── Sales history ─────────────────────────────────────────────────────────────
$sales = $db->getAll(
    "SELECT id, invoice_no, sale_date, total, paid_amount, payment_method, payment_status, is_credit
     FROM sales WHERE customer_phone = :phone AND status != 'voided'
     ORDER BY sale_date DESC LIMIT 50",
    [':phone' => $phone]
);

// ── Credit history ────────────────────────────────────────────────────────────
$credits = $db->getAll(
    "SELECT cc.*, s.invoice_no,
            COALESCE(s.total, cc.total_amount) AS display_total,
            COALESCE(s.paid_amount, 0) AS sale_upfront,
            COALESCE((SELECT SUM(amount) FROM credit_payments
                      WHERE credit_id = cc.id
                        AND (note IS NULL OR (note NOT LIKE 'Upfront%' AND note NOT LIKE 'upfront%'))), 0) AS post_paid
     FROM customer_credits cc
     LEFT JOIN sales s ON s.id = cc.sale_id
     WHERE cc.customer_phone = :phone
     ORDER BY cc.status ASC, cc.due_date ASC",
    [':phone' => $phone]
);

// Compute remaining per credit row
foreach ($credits as &$cr) {
    $cr['remaining'] = max(0, (float)$cr['display_total'] - (float)$cr['sale_upfront'] - (float)$cr['post_paid']);
    $cr['paid_total'] = (float)$cr['sale_upfront'] + (float)$cr['post_paid'];
}
unset($cr);

// ── Return history ───────────────────────────────────────────────────────────
// FIX: The original query used OR with duplicate named params (:phone and :phone2)
// which caused a silent PDO error, returning empty results.
// Also, sale_returns table may not have a customer_phone column.
// Solution: JOIN via sales table only, using a single :phone parameter.
$returns = [];
try {
    $returns = $db->getAll(
        "SELECT sr.id, sr.return_no, sr.created_at, sr.total_amount, sr.refund_method, sr.reason,
                sr.sale_id,
                COALESCE(s.invoice_no, '') AS invoice_no,
                COALESCE(sri.product_name, '') AS product_name,
                COALESCE(sri.quantity, 0) AS quantity
         FROM sale_returns sr
         INNER JOIN sales s ON s.id = sr.sale_id
         LEFT JOIN sale_return_items sri ON sri.return_id = sr.id
         WHERE s.customer_phone = :phone
           AND s.status != 'voided'
         ORDER BY sr.created_at DESC
         LIMIT 50",
        [':phone' => $phone]
    );
} catch (Exception $e) {
    // If sale_returns table structure differs, try minimal fallback
    try {
        $returns = $db->getAll(
            "SELECT sr.id, sr.return_no, sr.created_at, sr.total_amount, sr.refund_method, sr.reason,
                    sr.sale_id,
                    COALESCE(s.invoice_no, '') AS invoice_no,
                    '' AS product_name,
                    0 AS quantity
             FROM sale_returns sr
             INNER JOIN sales s ON s.id = sr.sale_id
             WHERE s.customer_phone = :phone
               AND s.status != 'voided'
             ORDER BY sr.created_at DESC
             LIMIT 50",
            [':phone' => $phone]
        );
    } catch (Exception $e2) {
        $returns = [];
    }
}

$pageTitle = 'Customer: ' . htmlspecialchars($customer['name'] ?? $phone);
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
<style>
body { background: var(--pharmacy-body-bg, #f0f2f5); color: var(--pharmacy-text, #444); }
.main-content { margin-left: var(--sb-w, 260px); transition: margin-left .25s ease; padding: 20px; }
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

/* Summary cards */
.stat-card { border: none; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.07); transition: transform .15s; background: var(--pharmacy-card-bg, #fff) !important; color: var(--pharmacy-text, #444) !important; }
.stat-card:hover { transform: translateY(-2px); }
.stat-card .icon-box { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
.stat-card .stat-val { font-size: 1.4rem; font-weight: 700; letter-spacing: -.5px; color: var(--pharmacy-heading, #111); }

/* Profile card */
.profile-card { border: none; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.07); background: var(--pharmacy-card-bg, #fff) !important; }
.avatar-circle { width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, var(--pharmacy-primary, #1a56db), var(--pharmacy-accent, #6366f1)); display: flex; align-items: center; justify-content: center; font-size: 1.6rem; color: #fff; font-weight: 700; flex-shrink: 0; }

/* Tables */
.data-table thead th { background: var(--pharmacy-sidebar, #2c3e50); color: #fff; font-size: .80rem; text-transform: uppercase; letter-spacing: .4px; border: none; white-space: nowrap; }
.data-table td { vertical-align: middle; font-size: .875rem; color: var(--pharmacy-text, #444); border-color: var(--pharmacy-border, #dee2e6) !important; }
.data-table tbody tr:hover { background: color-mix(in srgb, var(--pharmacy-primary, #1a56db) 5%, var(--pharmacy-card-bg, #fff)); }

/* Cards globally */
.card { background: var(--pharmacy-card-bg, #fff) !important; border-color: var(--pharmacy-border, #dee2e6) !important; color: var(--pharmacy-text, #444) !important; }
.card-header { background: var(--pharmacy-card-bg, #fff) !important; border-color: var(--pharmacy-border, #dee2e6) !important; color: var(--pharmacy-text, #444) !important; }
.card-body { color: var(--pharmacy-text, #444) !important; }

/* Progress bar */
.prog-bar { height: 6px; border-radius: 3px; background: var(--pharmacy-border, #e9ecef); overflow: hidden; }
.prog-bar-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #198754, #20c997); transition: width .4s; }

/* Status badges */
.badge-paid    { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.badge-active  { background:#fff3cd; color:#856404; border:1px solid #ffeeba; }
.badge-partial { background:#cce5ff; color:#004085; border:1px solid #b8daff; }
.badge-overdue { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.badge-pending { background:#e2e3e5; color:#383d41; border:1px solid #d6d8db; }
</style>

</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">
<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">

    <!-- Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <a href="customers.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
            <div>
                <h4 class="mb-0 fw-bold"><i class="bi bi-person-circle text-primary me-2"></i><?= htmlspecialchars($customer['name'] ?? $phone) ?></h4>
                <small class="text-muted">Customer Profile & Transaction History</small>
            </div>
        </div>
        <?php if ($customer['id']): ?>
            <?php if ($outstanding > 0): ?>
            <button class="btn btn-outline-secondary btn-sm" disabled title="Pending dues hain — edit allowed nahi">
                <i class="bi bi-pencil me-1"></i> Edit Customer
            </button>
            <?php else: ?>
            <button class="btn btn-outline-primary btn-sm" data-requires-perm="customers.edit" onclick="openEditModal()">
                <i class="bi bi-pencil me-1"></i> Edit Customer
            </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Flash messages (Phase 12: unified) -->
    <?php echo renderFlashAll(); ?>

    <!-- Profile Card -->
    <div class="card profile-card mb-4">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="avatar-circle">
                    <?= mb_strtoupper(mb_substr($customer['name'] ?? '?', 0, 1)) ?>
                </div>
                <div class="flex-grow-1">
                    <h5 class="mb-1 fw-bold"><?= htmlspecialchars($customer['name'] ?? '—') ?></h5>
                    <div class="d-flex flex-wrap gap-3 text-muted small">
                        <span><i class="bi bi-telephone me-1 text-primary"></i><?= htmlspecialchars($phone) ?></span>
                        <?php if (!empty($customer['address'])): ?>
                        <span><i class="bi bi-geo-alt me-1 text-danger"></i><?= htmlspecialchars($customer['address']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($customer['notes'])): ?>
                        <span><i class="bi bi-sticky me-1 text-warning"></i><?= htmlspecialchars($customer['notes']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-end">
                    <span class="badge bg-<?= $outstanding > 0 ? 'danger' : 'success' ?> fs-6 px-3 py-2">
                        <?= $outstanding > 0 ? 'Rs '.number_format($outstanding,2).' Outstanding' : 'No Dues' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="icon-box bg-primary bg-opacity-10 text-primary"><i class="bi bi-receipt"></i></div>
                    <div>
                        <div class="text-muted small">Total Sales</div>
                        <div class="stat-val"><?= formatCurrency($totalSalesAmt) ?></div>
                        <div class="text-muted" style="font-size:.75rem"><?= $totalSalesCnt ?> invoice(s)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="icon-box bg-warning bg-opacity-10 text-warning"><i class="bi bi-credit-card"></i></div>
                    <div>
                        <div class="text-muted small">Total Credit Given</div>
                        <div class="stat-val"><?= formatCurrency($totalCredit) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="icon-box bg-success bg-opacity-10 text-success"><i class="bi bi-cash-coin"></i></div>
                    <div>
                        <div class="text-muted small">Total Payments Made</div>
                        <div class="stat-val text-success"><?= formatCurrency($totalPaid) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="icon-box bg-danger bg-opacity-10 text-danger"><i class="bi bi-exclamation-circle"></i></div>
                    <div>
                        <div class="text-muted small">Outstanding Balance</div>
                        <div class="stat-val <?= $outstanding > 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($outstanding) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- ── Sales History ── -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center py-3">
                    <span class="fw-semibold"><i class="bi bi-bag-check text-primary me-2"></i>Sales History</span>
                    <span class="badge bg-primary rounded-pill"><?= count($sales) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($sales)): ?>
                    <div class="text-center text-muted py-5"><i class="bi bi-inbox" style="font-size:2.5rem"></i><p class="mt-2">No sales found</p></div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table data-table table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Invoice</th>
                                    <th>Date</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">Paid</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sales as $i => $s):
                                $statusClass = match($s['payment_status']) {
                                    'paid'    => 'badge-paid',
                                    'partial' => 'badge-partial',
                                    'pending' => 'badge-pending',
                                    default   => 'badge-active',
                                };
                            ?>
                            <tr>
                                <td class="text-muted small"><?= $i+1 ?></td>
                                <td>
                                    <span class="fw-semibold text-primary"><?= htmlspecialchars($s['invoice_no'] ?? '—') ?></span>
                                    <?php if ($s['is_credit']): ?><span class="badge bg-warning text-dark ms-1" style="font-size:9px">Credit</span><?php endif; ?>
                                </td>
                                <td class="text-muted small"><?= date('d M Y', strtotime($s['sale_date'])) ?></td>
                                <td class="text-end fw-semibold"><?= formatCurrency($s['total']) ?></td>
                                <td class="text-end text-success"><?= formatCurrency($s['paid_amount']) ?></td>
                                <td><span class="badge bg-secondary"><?= ucfirst($s['payment_method']) ?></span></td>
                                <td><span class="badge rounded-pill <?= $statusClass ?>"><?= ucfirst($s['payment_status']) ?></span></td>
                                <td class="text-center">
                                    <a href="view.php?id=<?= $s['id'] ?>" class="btn btn-outline-primary btn-sm py-0 px-2" title="View Invoice">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Credit History ── -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center py-3">
                    <span class="fw-semibold"><i class="bi bi-wallet2 text-warning me-2"></i>Credit History</span>
                    <span class="badge bg-warning text-dark rounded-pill"><?= count($credits) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($credits)): ?>
                    <div class="text-center text-muted py-5"><i class="bi bi-check-circle text-success" style="font-size:2.5rem"></i><p class="mt-2">No credit records — customer has no dues</p></div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table data-table table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Invoice</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">Paid</th>
                                    <th class="text-end">Balance</th>
                                    <th>Due Date</th>
                                    <th>Progress</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($credits as $i => $cr):
                                $total    = (float)$cr['display_total'];
                                $paid     = (float)$cr['paid_total'];
                                $rem      = (float)$cr['remaining'];
                                $pct      = $total > 0 ? min(100, ($paid/$total)*100) : 0;
                                $isOverdue = $cr['status'] === 'active' && strtotime($cr['due_date']) < time();
                                $statusLabel = $cr['status'] === 'paid' ? 'paid' : ($isOverdue ? 'overdue' : $cr['status']);
                                $statusClass = match($statusLabel) {
                                    'paid'    => 'badge-paid',
                                    'overdue' => 'badge-overdue',
                                    'active'  => 'badge-active',
                                    default   => 'badge-pending',
                                };
                                $canPay = $cr['status'] === 'active' && $rem > 0.005;
                            ?>
                            <tr data-credit-id="<?= $cr['id'] ?>">
                                <td class="text-muted small"><?= $i+1 ?></td>
                                <td>
                                    <?php if (!empty($cr['invoice_no'])): ?>
                                    <a href="view.php?id=<?= $cr['sale_id'] ?>" class="text-primary fw-semibold text-decoration-none">
                                        <i class="bi bi-receipt me-1" style="font-size:10px"></i><?= htmlspecialchars($cr['invoice_no']) ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted"><i class="bi bi-hand-index me-1"></i>Manual</span>
                                    <?php endif; ?>
                                    <div class="text-muted" style="font-size:10px"><?= substr($cr['created_at'] ?? '', 0, 10) ?></div>
                                </td>
                                <td class="text-end fw-semibold"><?= formatCurrency($total) ?></td>
                                <td class="text-end text-success"><?= formatCurrency($paid) ?></td>
                                <td class="text-end fw-bold remaining-cell <?= $rem > 0.005 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($rem) ?></td>
                                <td class="<?= $isOverdue ? 'text-danger fw-semibold' : 'text-muted' ?> small">
                                    <?= $cr['due_date'] ?? '—' ?>
                                    <?php if ($isOverdue): ?><br><span style="font-size:9px">⚠ Overdue</span><?php endif; ?>
                                </tr>
                                <td style="min-width:90px">
                                    <div class="prog-bar"><div class="prog-bar-fill" style="width:<?= number_format($pct,1) ?>%"></div></div>
                                    <small class="text-muted" style="font-size:10px"><?= number_format($pct,0) ?>% paid</small>
                                </td>
                                <td class="status-cell"><span class="badge rounded-pill <?= $statusClass ?>"><?= ucfirst($statusLabel) ?></span></td>
                                <td class="text-center">
                                    <?php if ($canPay): ?>
                                    <button class="btn btn-success btn-sm py-0 px-2"
                                            data-requires-perm="credit.pay"
                                            onclick="openPayModal(<?= $cr['id'] ?>, '<?= htmlspecialchars($cr['invoice_no'] ?? 'Manual', ENT_QUOTES) ?>', <?= $rem ?>)"
                                            title="Pay Credit">
                                        <i class="bi bi-cash"></i> Pay
                                    </button>
                                    <?php else: ?>
                                    <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Return History ── -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center py-3">
                    <span class="fw-semibold"><i class="bi bi-arrow-return-left text-danger me-2"></i>Return History</span>
                    <span class="badge bg-danger rounded-pill"><?= count($returns) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($returns)): ?>
                    <div class="text-center text-muted py-5"><i class="bi bi-check-circle text-success" style="font-size:2.5rem"></i><p class="mt-2">No returns found for this customer</p></div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table data-table table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Return No</th>
                                    <th>Date</th>
                                    <th>Invoice</th>
                                    <th>Product</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Amount</th>
                                    <th>Refund Method</th>
                                    <th>Reason</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($returns as $i => $ret): ?>
                            <tr>
                                <td class="text-muted small"><?= $i+1 ?></td>
                                <td><strong><?= htmlspecialchars($ret['return_no'] ?? '—') ?></strong></td>
                                <td class="text-muted small"><?= date('d M Y', strtotime($ret['created_at'])) ?></td>
                                <td>
                                    <?php if (!empty($ret['invoice_no'])): ?>
                                    <a href="view.php?id=<?= $ret['sale_id'] ?>" class="text-primary fw-semibold text-decoration-none">
                                        <i class="bi bi-receipt me-1" style="font-size:10px"></i><?= htmlspecialchars($ret['invoice_no']) ?>
                                    </a>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($ret['product_name'] ?? '—') ?></td>
                                <td class="text-center"><span class="badge bg-warning text-dark"><?= (int)($ret['quantity'] ?? 0) ?> pcs</span></td>
                                <td class="text-end text-danger fw-bold"><?= formatCurrency($ret['total_amount'] ?? 0) ?></td>
                                <td><span class="badge bg-secondary"><?= ucfirst($ret['refund_method'] ?? '—') ?></span></td>
                                <td class="text-muted small"><?= htmlspecialchars($ret['reason'] ?? '—') ?></td>
                                <td class="text-center">
                                    <a href="../returns/sale_return_view.php?id=<?= $ret['id'] ?>" class="btn btn-outline-danger btn-sm py-0 px-2" title="View Return">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /row -->
</div><!-- /container -->
</div><!-- /main-content -->

<!-- Pay Credit Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title mb-0"><i class="bi bi-cash-coin me-2"></i>Record Payment</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="payCreditId">
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Invoice</label>
                    <div id="payInvoiceLabel" class="text-muted small"></div>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Remaining</label>
                    <div id="payRemainingLabel" class="text-danger fw-bold"></div>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Amount (Rs) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" id="payAmount" class="form-control form-control-sm" min="0.01">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Payment Method</label>
                    <select id="payMethod" class="form-select form-select-sm">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="online">Online</option>
                    </select>
                </div>
                <div class="mb-1">
                    <label class="form-label small fw-semibold">Note</label>
                    <input type="text" id="payNote" class="form-control form-control-sm" placeholder="Optional">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" id="paySubmitBtn" onclick="submitPayment()">
                    <i class="bi bi-check-lg me-1"></i>Record Payment
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF   = '<?= $csrfToken ?>';
const SELF   = '<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES) ?>?phone=<?= urlencode($phone) ?>&ajax=pay_credit';
const CREDIT_PAY_URL = '<?= BASE_URL ?>credit/list.php?ajax=pay_credit';
let _maxRem  = 0;
let _currentCreditId = 0;

function openPayModal(creditId, invoice, remaining) {
    _maxRem = remaining;
    _currentCreditId = creditId;
    $('#payCreditId').val(creditId);
    $('#payInvoiceLabel').text(invoice || 'Manual Credit');
    $('#payRemainingLabel').text('Rs ' + parseFloat(remaining).toFixed(2));
    $('#payAmount').val(parseFloat(remaining).toFixed(2)).attr('max', remaining);
    $('#payMethod').val('cash');
    $('#payNote').val('');
    new bootstrap.Modal(document.getElementById('payModal')).show();
}

function submitPayment() {
    const amt = parseFloat($('#payAmount').val());
    if (isNaN(amt) || amt <= 0) { Notify.warning('Enter a valid amount.'); return; }
    if (amt > _maxRem + 0.01)   { Notify.warning('Amount exceeds remaining balance (Rs ' + _maxRem.toFixed(2) + ')'); return; }

    const $btn = $('#paySubmitBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');

    $.post(CREDIT_PAY_URL, {
        credit_id:      $('#payCreditId').val(),
        amount:         amt,
        payment_method: $('#payMethod').val(),
        note:           $('#payNote').val().trim()
    }, function(r) {
        $btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i>Record Payment');
        if (r.success) {
            bootstrap.Modal.getInstance(document.getElementById('payModal')).hide();

            const newRem    = parseFloat(r.new_balance ?? r.new_remaining ?? 0);
            const fullyPaid = r.fully_paid;

            // ── Broadcast to other open tabs ──
            try {
                var bc = new BroadcastChannel('credit_sync');
                bc.postMessage({event:'payment', credit_id: _currentCreditId, new_remaining: newRem, fully_paid: fullyPaid, amount: amt});
                bc.close();
            } catch(e) {}

            // ── Realtime update this page without full reload ──
            updateCreditRow(_currentCreditId, newRem, fullyPaid, amt);

        } else {
            Notify.error(r.message || 'Payment failed.');
        }
    }, 'json').fail(function(xhr) {
        $btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i>Record Payment');
        var msg = 'Server error. Please try again.';
        try { var j = JSON.parse(xhr.responseText); if(j && j.message) msg = j.message; } catch(e) {}
        Notify.error(msg);
    });
}

function updateCreditRow(creditId, newRem, fullyPaid, paidAmt) {
    // Find the row in the credits table by data-credit-id attribute
    var $row = $('tr[data-credit-id="' + creditId + '"]');

    if (fullyPaid) {
        if ($row.length) {
            $row.find('.remaining-cell').html('<span style="color:#057a55;font-weight:700">Rs 0.00</span>');
            $row.find('.status-cell').html('<span style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:20px;padding:2px 8px;font-size:10px;font-weight:600;">✓ Paid</span>');
            $row.find('.pay-btn-cell').html('<span class="text-muted" style="font-size:10px;">Settled</span>');
            $row.find('.prog-bar-fill').css('width','100%');
            $row.find('.progress-pct').text('100% paid');
        }
        // Update outstanding badge in header
        var $badge = $('#outstandingAmount');
        if ($badge.length) {
            var cur = parseFloat($badge.text().replace(/[^0-9.]/g,'')) || 0;
            var updated = Math.max(0, cur - (parseFloat($('#payRemainingLabel').text().replace('Rs ','')) || 0));
            $badge.text('Rs ' + updated.toFixed(2));
        }
    } else {
        if ($row.length) {
            $row.find('.remaining-cell').html('<span style="color:#e02424;font-weight:700">Rs ' + newRem.toFixed(2) + '</span>');
            // Update pay button remaining
            $row.find('.pay-btn-cell button').attr('onclick', "openPayModal("+creditId+",'"+$row.find('.pay-btn-cell button').attr('onclick').match(/'([^']+)'/)?.[1]+"',"+newRem+")");
        }
    }

    // Add a new row in payment history table if visible
    var $histTable = $('#creditPaymentsTbody');
    if ($histTable.length) {
        var now = new Date();
        var dateStr = now.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'});
        var method = $('#payMethod').val();
        var note   = $('#payNote').val().trim();
        var newRow = '<tr><td class="text-muted small">' + dateStr + '</td>'
            + '<td class="text-success fw-bold">Rs ' + paidAmt.toFixed(2) + '</td>'
            + '<td><span class="badge bg-secondary">' + (method||'cash') + '</span></td>'
            + '<td class="text-muted small">' + (note||'—') + '</td></tr>';
        $histTable.prepend(newRow);
    }

    // Show inline success toast
    var toastHtml = '<div class="toast align-items-center text-white bg-success border-0 mb-2 show" role="alert">'
        + '<div class="d-flex"><div class="toast-body">Payment of Rs ' + paidAmt.toFixed(2) + ' recorded!</div>'
        + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>'
        + '</div></div>';
    var $tc = $('.toast-container');
    if (!$tc.length) { $('body').append('<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999"></div>'); $tc = $('.toast-container'); }
    var $t = $(toastHtml).appendTo($tc);
    setTimeout(function(){ $t.remove(); }, 3500);
}

/* ── Cross-tab: listen for payments from other pages ── */
try {
    var _bcView = new BroadcastChannel('credit_sync');
    _bcView.onmessage = function(e){
        if(e.data && e.data.event === 'payment'){
            updateCreditRow(e.data.credit_id, parseFloat(e.data.new_remaining)||0, e.data.fully_paid, parseFloat(e.data.amount)||0);
        }
    };
} catch(e) {}

// Auto-dismiss flash alerts
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(el => bootstrap.Alert.getOrCreateInstance(el).close());
}, 4000);

function openEditModal() {
    new bootstrap.Modal(document.getElementById('editCustomerModal')).show();
}

function submitEditCustomer() {
    var id      = $('#editCustomerId').val();
    var name    = $('#editName').val().trim();
    var phone   = $('#editPhone').val().trim();
    var address = $('#editAddress').val().trim();
    var notes   = $('#editNotes').val().trim();
    if (!name) { Notify.warning('Name is required'); return; }
    $('#editCustomerBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving…');
    $.post('customers.php?ajax=save_customer', {
        id: id, name: name, phone: phone, address: address, notes: notes
    }, function(r) {
        $('#editCustomerBtn').prop('disabled', false).html('<i class="bi bi-save me-1"></i>Save Changes');
        if (r.success) {
            bootstrap.Modal.getInstance(document.getElementById('editCustomerModal')).hide();
            location.reload();
        } else { Notify.error(r.message || 'Failed to save.'); }
    }, 'json').fail(function() {
        $('#editCustomerBtn').prop('disabled', false).html('<i class="bi bi-save me-1"></i>Save Changes');
        Notify.error('Server error. Please try again.');
    });
}
</script>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h6 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Customer</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editCustomerId" value="<?= $customer['id'] ?>">
                <div class="mb-2"><label class="form-label form-label-sm fw-semibold">Name <span class="text-danger">*</span></label><input type="text" id="editName" class="form-control form-control-sm" value="<?= htmlspecialchars($customer['name'] ?? '') ?>"></div>
                <div class="mb-2"><label class="form-label form-label-sm fw-semibold">Phone</label><input type="text" id="editPhone" class="form-control form-control-sm" value="<?= htmlspecialchars($phone) ?>" readonly></div>
                <div class="mb-2"><label class="form-label form-label-sm fw-semibold">Address</label><input type="text" id="editAddress" class="form-control form-control-sm" value="<?= htmlspecialchars($customer['address'] ?? '') ?>"></div>
                <div class="mb-1"><label class="form-label form-label-sm fw-semibold">Notes</label><textarea id="editNotes" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($customer['notes'] ?? '') ?></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning btn-sm" id="editCustomerBtn" onclick="submitEditCustomer()"><i class="bi bi-save me-1"></i>Save Changes</button>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
