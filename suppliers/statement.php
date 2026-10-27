<?php
// suppliers/statement.php
/**
 * Supplier Ledger Statement – Enhanced with Purchase Returns & Overdue Invoices
 * FIXED: Removed duplicate formatDate() function
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/purchase_functions.php';

requireLogin();

$page_title = 'Supplier Ledger Statement';
$error = '';
$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date   = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

if (!$supplier_id) {
    header('Location: ../suppliers/list.php');
    exit;
}

$supplier = getSupplierById($supplier_id);
if (!$supplier) {
    header('Location: ../suppliers/list.php');
    exit;
}

// Helper function for net due (returns accounted) - NOT redeclaring formatDate
function getPurchaseNetDueForStatement(int $purchaseId): array {
    try {
        $p = _pdoOne(
            "SELECT total, paid_amount FROM purchases WHERE id = :id",
            [':id' => $purchaseId]
        );
        if (!$p) return ['net_due' => 0, 'total_returned' => 0];
        
        $totalReturned = (float)(_pdoOne(
            "SELECT COALESCE(SUM(total_amount), 0) as total FROM purchase_returns WHERE purchase_id = :pid AND status = 'approved'",
            [':pid' => $purchaseId]
        )['total'] ?? 0);
        
        $netTotal = (float)$p['total'] - $totalReturned;
        $netDue = max(0, $netTotal - (float)$p['paid_amount']);
        
        return [
            'net_due' => $netDue,
            'total_returned' => $totalReturned,
            'net_total' => $netTotal
        ];
    } catch (Exception $e) {
        error_log("getPurchaseNetDueForStatement error: " . $e->getMessage());
        return ['net_due' => 0, 'total_returned' => 0, 'net_total' => 0];
    }
}

function getSupplierLedger($supplier_id, $start_date, $end_date) {
    try {
        // Opening balance: purchases before start_date minus payments and returns before start_date
        $purchases_before = (float)(_pdoOne(
            "SELECT COALESCE(SUM(total), 0) as total FROM purchases 
             WHERE supplier_id = :sid AND purchase_date < :start",
            [':sid' => $supplier_id, ':start' => $start_date]
        )['total'] ?? 0);

        $payments_before = (float)(_pdoOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM supplier_payments 
             WHERE supplier_id = :sid AND payment_date < :start",
            [':sid' => $supplier_id, ':start' => $start_date]
        )['total'] ?? 0);

        // Get returns before start date (using created_at or return_date)
        $returns_before = (float)(_pdoOne(
            "SELECT COALESCE(SUM(pr.total_amount), 0) as total 
             FROM purchase_returns pr
             JOIN purchases p ON p.id = pr.purchase_id
             WHERE p.supplier_id = :sid AND DATE(pr.created_at) < :start AND pr.status = 'approved'",
            [':sid' => $supplier_id, ':start' => $start_date]
        )['total'] ?? 0);

        $opening_balance = $purchases_before - $payments_before - $returns_before;

        // Transactions within date range - Use separate queries to avoid parameter binding issues
        $transactions = [];
        
        // Get purchases
        $purchases = _pdoAll(
            "SELECT 
                purchase_date as trans_date,
                'Purchase' as trans_type,
                purchase_invoice_no as reference,
                id as ref_id,
                total as credit,
                0 as debit
            FROM purchases
            WHERE supplier_id = :sid AND purchase_date BETWEEN :start AND :end",
            [':sid' => $supplier_id, ':start' => $start_date, ':end' => $end_date]
        );
        
        foreach ($purchases as $p) {
            $transactions[] = $p;
        }
        
        // Get payments
        $payments = _pdoAll(
            "SELECT 
                payment_date as trans_date,
                'Payment' as trans_type,
                CONCAT('Payment #', id) as reference,
                id as ref_id,
                0 as credit,
                amount as debit
            FROM supplier_payments
            WHERE supplier_id = :sid AND payment_date BETWEEN :start AND :end",
            [':sid' => $supplier_id, ':start' => $start_date, ':end' => $end_date]
        );
        
        foreach ($payments as $p) {
            $transactions[] = $p;
        }
        
        // Get returns
        $returns = _pdoAll(
            "SELECT 
                DATE(pr.created_at) as trans_date,
                'Purchase Return' as trans_type,
                pr.return_no as reference,
                pr.id as ref_id,
                0 as credit,
                pr.total_amount as debit
            FROM purchase_returns pr
            JOIN purchases p ON p.id = pr.purchase_id
            WHERE p.supplier_id = :sid AND DATE(pr.created_at) BETWEEN :start AND :end AND pr.status = 'approved'",
            [':sid' => $supplier_id, ':start' => $start_date, ':end' => $end_date]
        );
        
        foreach ($returns as $r) {
            $transactions[] = $r;
        }
        
        // Sort transactions by date
        usort($transactions, function($a, $b) {
            return strtotime($a['trans_date']) - strtotime($b['trans_date']);
        });
        
        // Calculate running balance
        $balance = $opening_balance;
        foreach ($transactions as &$t) {
            if ($t['trans_type'] === 'Purchase') {
                $balance += (float)$t['credit'];
                $t['change'] = (float)$t['credit'];
            } elseif ($t['trans_type'] === 'Payment') {
                $balance -= (float)$t['debit'];
                $t['change'] = -(float)$t['debit'];
            } elseif ($t['trans_type'] === 'Purchase Return') {
                $balance -= (float)$t['debit'];
                $t['change'] = -(float)$t['debit'];
            }
            $t['balance'] = $balance;
        }
        unset($t);

        // Overdue purchases (30+ days old, not fully paid)
        $overdue = _pdoAll(
            "SELECT id, purchase_invoice_no, total, paid_amount, (total - paid_amount) as due, purchase_date as due_date
             FROM purchases
             WHERE supplier_id = :sid AND payment_status != 'paid' AND purchase_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             ORDER BY purchase_date ASC",
            [':sid' => $supplier_id]
        );
        
        // Filter overdue to show only those with net due > 0
        $filteredOverdue = [];
        foreach ($overdue as $inv) {
            $info = getPurchaseNetDueForStatement((int)$inv['id']);
            if ($info['net_due'] > 0) {
                $inv['due'] = $info['net_due'];
                $inv['total_returned'] = $info['total_returned'];
                $filteredOverdue[] = $inv;
            }
        }

        return [
            'opening_balance' => $opening_balance,
            'transactions'    => $transactions,
            'overdue'         => $filteredOverdue
        ];
    } catch (Exception $e) {
        error_log("getSupplierLedger error: " . $e->getMessage());
        return [
            'opening_balance' => 0,
            'transactions'    => [],
            'overdue'         => []
        ];
    }
}

$ledger = getSupplierLedger($supplier_id, $start_date, $end_date);
$opening = $ledger['opening_balance'];
$transactions = $ledger['transactions'];
$overdue = $ledger['overdue'];

// Closing balance is last transaction's balance (or opening if no tx)
$closing = $opening;
if (!empty($transactions)) {
    $lastTx = end($transactions);
    $closing = $lastTx['balance'] ?? $opening;
}

// Summary stats
$total_purchases = array_sum(array_column(array_filter($transactions, fn($t) => $t['trans_type'] === 'Purchase'), 'credit'));
$total_payments  = array_sum(array_column(array_filter($transactions, fn($t) => $t['trans_type'] === 'Payment'), 'debit'));
$total_returns   = array_sum(array_column(array_filter($transactions, fn($t) => $t['trans_type'] === 'Purchase Return'), 'debit'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $page_title; ?></title>
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>


<style>
:root {
    --pharmacy-primary: #0d6efd;
    --pharmacy-success: #198754;
    --pharmacy-danger: #dc3545;
    --pharmacy-warning: #ffc107;
    --pharmacy-text: #212529;
    --pharmacy-border: #dee2e6;
    --pharmacy-heading: #1a1a2e;
    --pharmacy-table-th: #f8f9fa;
    --sb-w: 260px;
    --card-border-radius: 12px;
}
body { background: #f0f2f5; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
.main-content { margin-left: var(--sb-w, 260px); padding: 20px; min-height: 100vh; }
.content-area { max-width: 1400px; margin: 0 auto; }
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
.card { border-radius: var(--card-border-radius, 12px); border: 1px solid var(--pharmacy-border); box-shadow: 0 2px 8px rgba(0,0,0,0.05); background: #fff; }
.card-header { background: var(--pharmacy-table-th); border-bottom: 1px solid var(--pharmacy-border); padding: 14px 18px; font-weight: 600; border-radius: calc(var(--card-border-radius, 12px) - 1px) calc(var(--card-border-radius, 12px) - 1px) 0 0; }
.stat-card { background: #fff; border-radius: 12px; padding: 15px; border-left: 4px solid var(--pharmacy-primary); box-shadow: 0 1px 3px rgba(0,0,0,.05); margin-bottom: 0; }
.stat-value { font-size: 22px; font-weight: 700; }
.stat-label { font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
.status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-instock { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.badge-expiring { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
.filter-bar { background: #fff; border-radius: var(--card-border-radius, 12px); padding: 15px 20px; border: 1px solid var(--pharmacy-border); }
.table-card { background: #fff; border-radius: var(--card-border-radius, 12px); border: 1px solid var(--pharmacy-border); overflow: hidden; }
.table th { background: var(--pharmacy-table-th); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 2px solid var(--pharmacy-border); }
.table td { font-size: 13px; vertical-align: middle; }
.btn-sm { padding: 5px 12px; font-size: 12px; }
@media print {
    .no-print, .btn, .navbar, .sidebar, .filter-bar, .action-buttons { display: none !important; }
    .main-content { margin-left: 0 !important; padding: 0 !important; }
    .card, .table-card { box-shadow: none !important; border: 1px solid #ccc !important; break-inside: avoid; }
    body { background: #fff !important; }
    @page { margin: 1.5cm; }
}
</style>
</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">
<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="content-area">

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-start mb-4 no-print flex-wrap gap-2">
            <div>
                <h4 class="mb-1 fw-bold" style="color: var(--pharmacy-heading);">
                    <i class="fas fa-file-alt me-2" style="color: var(--pharmacy-primary);"></i>
                    Supplier Ledger Statement
                </h4>
                <p class="mb-0" style="color: #6c757d; font-size: 0.9rem;">
                    <i class="fas fa-building me-1"></i>
                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                    <span class="ms-2 px-2 py-0 rounded" style="background: var(--pharmacy-table-th); font-size: 0.78rem; font-weight: 600;">
                        <?php echo htmlspecialchars($supplier['supplier_code'] ?? 'SUP-' . str_pad($supplier_id, 3, '0', STR_PAD_LEFT)); ?>
                    </span>
                </p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="pay.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-sm btn-warning">
                    <i class="fas fa-money-bill-wave me-1"></i> Make Payment
                </a>
                <button onclick="window.print()" class="btn btn-sm btn-secondary">
                    <i class="fas fa-print me-1"></i> Print
                </button>
                <button onclick="exportToExcel()" class="btn btn-sm btn-success">
                    <i class="fas fa-file-excel me-1"></i> Export Excel
                </button>
                <a href="list.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>

        <!-- Overdue Alert -->
        <?php if (!empty($overdue)): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-4 no-print" style="border-radius: var(--card-border-radius, 12px); border-left: 4px solid #f59e0b;">
            <i class="fas fa-exclamation-triangle fa-lg text-warning"></i>
            <div>
                <strong>Overdue Invoices!</strong>
                This supplier has <strong><?php echo count($overdue); ?></strong> overdue purchase(s) with total due of
                <strong>Rs <?php echo number_format(array_sum(array_column($overdue, 'due')), 2); ?></strong>.
                <a href="pay.php?supplier_id=<?php echo $supplier_id; ?>" class="alert-link ms-1">Pay now →</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary Stat Cards -->
        <div class="row g-3 mb-4 no-print">
            <div class="col-6 col-md-3">
                <div class="stat-card" style="border-left-color: var(--pharmacy-primary);">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total Purchases</div>
                            <div class="stat-value" style="font-size: 1.3rem;">Rs <?php echo number_format($total_purchases, 0); ?></div>
                        </div>
                        <i class="fas fa-shopping-cart stat-icon" style="color: var(--pharmacy-primary);"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card" style="border-left-color: #10b981;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total Payments</div>
                            <div class="stat-value" style="font-size: 1.3rem; color: #10b981;">Rs <?php echo number_format($total_payments, 0); ?></div>
                        </div>
                        <i class="fas fa-check-circle stat-icon" style="color: #10b981;"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card" style="border-left-color: #f59e0b;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total Returns</div>
                            <div class="stat-value" style="font-size: 1.3rem; color: #f59e0b;">Rs <?php echo number_format($total_returns, 0); ?></div>
                        </div>
                        <i class="fas fa-undo stat-icon" style="color: #f59e0b;"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card" style="border-left-color: <?php echo $closing > 0 ? '#ef4444' : '#10b981'; ?>;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Closing Balance</div>
                            <div class="stat-value" style="font-size: 1.3rem; color: <?php echo $closing > 0 ? '#ef4444' : '#10b981'; ?>;">
                                Rs <?php echo number_format(abs($closing), 0); ?>
                            </div>
                            <div style="font-size: 0.72rem; font-weight: 600; color: <?php echo $closing > 0 ? '#ef4444' : '#10b981'; ?>;">
                                <?php echo $closing > 0 ? 'Payable to Supplier' : ($closing < 0 ? 'Supplier owes you' : 'Settled'); ?>
                            </div>
                        </div>
                        <i class="fas fa-balance-scale stat-icon" style="color: <?php echo $closing > 0 ? '#ef4444' : '#10b981'; ?>;"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar no-print mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="supplier_id" value="<?php echo $supplier_id; ?>">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold" style="color: var(--pharmacy-text);">
                        <i class="fas fa-calendar-alt me-1" style="color: var(--pharmacy-primary);"></i> Start Date
                    </label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold" style="color: var(--pharmacy-text);">
                        <i class="fas fa-calendar-check me-1" style="color: var(--pharmacy-primary);"></i> End Date
                    </label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i> Apply Filter
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="statement.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-sync me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Supplier Info Card -->
        <div class="card mb-4">
            <div class="card-header">
                <span class="fw-bold">
                    <i class="fas fa-id-card me-2" style="color: var(--pharmacy-primary);"></i>Supplier Information
                </span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3 d-flex align-items-center gap-2">
                        <span style="color: var(--pharmacy-primary);"><i class="fas fa-phone-alt"></i></span>
                        <div>
                            <div class="small text-muted">Phone</div>
                            <div class="fw-medium"><?php echo htmlspecialchars($supplier['phone'] ?? '-'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3 d-flex align-items-center gap-2">
                        <span style="color: var(--pharmacy-primary);"><i class="fas fa-envelope"></i></span>
                        <div>
                            <div class="small text-muted">Email</div>
                            <div class="fw-medium"><?php echo htmlspecialchars($supplier['email'] ?? '-'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3 d-flex align-items-center gap-2">
                        <span style="color: var(--pharmacy-primary);"><i class="fas fa-user"></i></span>
                        <div>
                            <div class="small text-muted">Contact Person</div>
                            <div class="fw-medium"><?php echo htmlspecialchars($supplier['contact_person'] ?? '-'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3 d-flex align-items-center gap-2">
                        <span style="color: var(--pharmacy-primary);"><i class="fas fa-map-marker-alt"></i></span>
                        <div>
                            <div class="small text-muted">Address</div>
                            <div class="fw-medium"><?php echo htmlspecialchars($supplier['address'] ?? '-'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Overdue Invoices Section -->
        <?php if (!empty($overdue)): ?>
        <div class="card mb-4" style="border-color: #fcd34d;">
            <div class="card-header d-flex justify-content-between align-items-center" style="background: #fef9c3; border-bottom-color: #fcd34d;">
                <span class="fw-bold" style="color: #92400e;">
                    <i class="fas fa-exclamation-circle me-2 text-warning"></i>Overdue Invoices
                </span>
                <span class="status-badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;">
                    <?php echo count($overdue); ?> overdue
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Due Date</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Returns</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end">Outstanding</th>
                                <th class="no-print text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overdue as $inv): ?>
                            <tr>
                                <td>
                                    <a href="../purchases/view.php?id=<?php echo $inv['id']; ?>" 
                                       style="color: var(--pharmacy-primary); text-decoration: none; font-weight: 500;">
                                        <?php echo htmlspecialchars($inv['purchase_invoice_no']); ?>
                                    </a>
                                 </td>
                                <td>
                                    <span style="color: #ef4444; font-weight: 500;">
                                        <i class="fas fa-clock me-1"></i><?php echo date('d M Y', strtotime($inv['due_date'])); ?>
                                    </span>
                                 </td>
                                <td class="text-end">Rs <?php echo number_format($inv['total'], 2); ?></td>
                                <td class="text-end text-warning">Rs <?php echo number_format($inv['total_returned'] ?? 0, 2); ?></td>
                                <td class="text-end text-success">Rs <?php echo number_format($inv['paid_amount'], 2); ?></td>
                                <td class="text-end">
                                    <span class="fw-bold text-danger">Rs <?php echo number_format($inv['due'], 2); ?></span>
                                 </td>
                                <td class="no-print text-center">
                                    <a href="pay.php?supplier_id=<?php echo $supplier_id; ?>&purchase_id=<?php echo $inv['id']; ?>" 
                                       class="btn btn-sm btn-warning">
                                        <i class="fas fa-money-bill me-1"></i>Pay
                                    </a>
                                 </td>
                             </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Main Ledger Table -->
        <div class="table-card" id="ledger-table-container">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold">
                    <i class="fas fa-list-alt me-2" style="color: var(--pharmacy-primary);"></i>
                    Statement: <?php echo date('d M Y', strtotime($start_date)); ?> → <?php echo date('d M Y', strtotime($end_date)); ?>
                </span>
                <span class="small text-muted">
                    <?php echo count($transactions); ?> transaction(s)
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="ledgerTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Reference</th>
                            <th class="text-end">Debit<br><small class="text-muted fw-normal" style="font-size: 0.68rem;">(Payment / Return)</small></th>
                            <th class="text-end">Credit<br><small class="text-muted fw-normal" style="font-size: 0.68rem;">(Purchase)</small></th>
                            <th class="text-end">Running Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Opening Balance Row -->
                        <tr style="background: #f8f9fa;">
                            <td colspan="5">
                                <strong>
                                    <i class="fas fa-flag me-1" style="color: var(--pharmacy-primary);"></i>
                                    Opening Balance <span style="font-weight: 400; font-size: 0.85rem;">(as on <?php echo date('d M Y', strtotime($start_date)); ?>)</span>
                                </strong>
                              </td>
                            <td class="text-end">
                                <span class="fw-bold" style="color: <?php echo $opening > 0 ? '#ef4444' : '#10b981'; ?>;">
                                    Rs <?php echo number_format(abs($opening), 2); ?>
                                    <small class="ms-1" style="font-size: 0.72rem;"><?php echo $opening > 0 ? '(Payable)' : ($opening < 0 ? '(Receivable)' : ''); ?></small>
                                </span>
                              </td>
                          </tr>

                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-30"></i>
                                    No transactions found in this period
                                  </td>
                             </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $row): ?>
                            <tr>
                                <td style="white-space: nowrap;">
                                    <?php echo date('d-m-Y', strtotime($row['trans_date'])); ?>
                                  </td>
                                <td>
                                    <?php if ($row['trans_type'] === 'Purchase'): ?>
                                        <span class="status-badge" style="background: #e3f2fd; color: #1976d2; border: 1px solid #bbdef5;">
                                            <i class="fas fa-shopping-cart me-1" style="font-size: 0.65rem;"></i>Purchase
                                        </span>
                                    <?php elseif ($row['trans_type'] === 'Payment'): ?>
                                        <span class="status-badge badge-instock">
                                            <i class="fas fa-check me-1" style="font-size: 0.65rem;"></i>Payment
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge badge-expiring">
                                            <i class="fas fa-undo me-1" style="font-size: 0.65rem;"></i>Return
                                        </span>
                                    <?php endif; ?>
                                  </td>
                                <td>
                                    <?php if ($row['trans_type'] === 'Purchase'): ?>
                                        <a href="../purchases/view.php?id=<?php echo $row['ref_id']; ?>"
                                           style="color: var(--pharmacy-primary); text-decoration: none; font-weight: 500;">
                                            <i class="fas fa-external-link-alt me-1" style="font-size: 0.65rem;"></i>
                                            <?php echo htmlspecialchars($row['reference']); ?>
                                        </a>
                                    <?php elseif ($row['trans_type'] === 'Purchase Return'): ?>
                                        <a href="../returns/purchase_return_view.php?id=<?php echo $row['ref_id']; ?>"
                                           style="color: var(--pharmacy-primary); text-decoration: none; font-weight: 500;">
                                            <i class="fas fa-external-link-alt me-1" style="font-size: 0.65rem;"></i>
                                            <?php echo htmlspecialchars($row['reference']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #6c757d;"><?php echo htmlspecialchars($row['reference']); ?></span>
                                    <?php endif; ?>
                                  </td>
                                <td class="text-end" style="color: <?php echo ($row['debit'] ?? 0) > 0 ? '#10b981' : '#6c757d'; ?>; font-weight: <?php echo ($row['debit'] ?? 0) > 0 ? '600' : '400'; ?>;">
                                    <?php echo ($row['debit'] ?? 0) > 0 ? 'Rs ' . number_format($row['debit'], 2) : '—'; ?>
                                  </td>
                                <td class="text-end" style="color: <?php echo ($row['credit'] ?? 0) > 0 ? '#ef4444' : '#6c757d'; ?>; font-weight: <?php echo ($row['credit'] ?? 0) > 0 ? '600' : '400'; ?>;">
                                    <?php echo ($row['credit'] ?? 0) > 0 ? 'Rs ' . number_format($row['credit'], 2) : '—'; ?>
                                  </td>
                                <td class="text-end">
                                    <span class="fw-bold" style="color: <?php echo ($row['balance'] ?? 0) > 0 ? '#ef4444' : ($row['balance'] < 0 ? '#10b981' : '#6c757d'); ?>;">
                                        Rs <?php echo number_format(abs($row['balance'] ?? 0), 2); ?>
                                    </span>
                                    <br>
                                    <small style="font-size: 0.68rem; font-weight: 600; color: <?php echo ($row['balance'] ?? 0) > 0 ? '#ef4444' : ($row['balance'] < 0 ? '#10b981' : '#6c757d'); ?>;">
                                        <?php echo ($row['balance'] ?? 0) > 0 ? 'Payable' : (($row['balance'] ?? 0) < 0 ? 'Receivable' : 'Settled'); ?>
                                    </small>
                                  </td>
                             </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Closing Balance Row -->
                        <tr style="background: var(--pharmacy-table-th); border-top: 2px solid var(--pharmacy-border);">
                            <td colspan="5" class="text-end">
                                <strong>
                                    <i class="fas fa-flag-checkered me-1"></i> Closing Balance
                                </strong>
                              </td>
                            <td class="text-end">
                                <span class="fw-bold" style="font-size: 1rem; color: <?php echo $closing > 0 ? '#ef4444' : ($closing < 0 ? '#10b981' : '#6c757d'); ?>;">
                                    Rs <?php echo number_format(abs($closing), 2); ?>
                                </span>
                                <br>
                                <small style="font-size: 0.72rem; font-weight: 700; color: <?php echo $closing > 0 ? '#ef4444' : ($closing < 0 ? '#10b981' : '#6c757d'); ?>;">
                                    <?php echo $closing > 0 ? '(Payable to Supplier)' : ($closing < 0 ? '(Supplier owes you)' : '(Settled)'); ?>
                                </small>
                              </td>
                         </tr>
                    </tbody>
                 </table>
            </div>
        </div>

    </div>
</div>

<script>
function exportToExcel() {
    let table = document.getElementById('ledgerTable');
    let html = table.outerHTML;
    let blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    let link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'supplier_ledger_<?php echo htmlspecialchars($supplier['supplier_code'] ?? 'supplier_' . $supplier_id); ?>.xls';
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>