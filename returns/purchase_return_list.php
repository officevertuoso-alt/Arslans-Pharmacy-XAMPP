<?php
// returns/purchase_return_list.php
/**
 * Purchase Return History — fully synchronized with return_functions.php
 * ALL PHP logic preserved. UI updated: design system vars, stat cards, table-card, filter-bar.
 */

require_once '../config/config.php';
require_once '../includes/return_functions.php';

if (file_exists(__DIR__ . '/../includes/sync_engine.php')) {
    require_once __DIR__ . '/../includes/sync_engine.php';
}
if (file_exists(__DIR__ . '/../includes/cashbook_functions.php')) {
    require_once __DIR__ . '/../includes/cashbook_functions.php';
}

requireLogin();

// ── AJAX: Fix missing cashbook entry — handle FIRST before any output ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fix_cashbook') {
    header('Content-Type: application/json');
    $return_id = (int)($_POST['return_id'] ?? 0);

    if (!$return_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid return ID']);
        exit;
    }

    $ret = getPurchaseReturnDetails($return_id);
    if (!$ret) {
        echo json_encode(['success' => false, 'message' => 'Return not found']);
        exit;
    }

    $exists = _pdoOne(
        "SELECT id FROM cashbook WHERE reference_id = :rid AND reference_type = 'purchase_return'",
        [':rid' => $return_id]
    );
    if ($exists) {
        echo json_encode(['success' => false, 'message' => 'Cashbook entry already exists']);
        exit;
    }

    if (!function_exists('addCashbookEntry')) {
        echo json_encode(['success' => false, 'message' => 'Cashbook functions not available']);
        exit;
    }

    $entry_id = addCashbookEntry(
        entryType: 'purchase_return',
        referenceId: $return_id,
        referenceType: 'purchase_return',
        description: "Purchase Return {$ret['return_no']} - Refund from Supplier",
        debit: 0.00,
        credit: (float)$ret['total_amount'],
        paymentMethod: $ret['refund_method'] ?? 'credit_note',
        userId: $_SESSION['user_id'] ?? 1,
        entryDate: date('Y-m-d', strtotime($ret['created_at'])),
        preventDuplicate: true
    );

    echo $entry_id
        ? json_encode(['success' => true,  'message' => 'Cashbook entry created', 'entry_id' => $entry_id])
        : json_encode(['success' => false, 'message' => 'Failed to create cashbook entry']);
    exit;
}

// ── Filters ────────────────────────────────────────────────────────────────
$page         = max(1, (int)($_GET['page']          ?? 1));
$search       = isset($_GET['search'])       ? sanitize($_GET['search'])       : '';
$fromDate     = isset($_GET['from_date'])    ? sanitize($_GET['from_date'])    : '';
$toDate       = isset($_GET['to_date'])      ? sanitize($_GET['to_date'])      : '';
$refundMethod = isset($_GET['refund_method'])? sanitize($_GET['refund_method']): '';
$export       = isset($_GET['export']) && $_GET['export'] === 'csv';

// ── Export CSV ─────────────────────────────────────────────────────────────
if ($export) {
    $allReturns = getAllPurchaseReturns(1, 10000, $search, $fromDate, $toDate, $refundMethod);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="purchase_returns_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Return No', 'Date', 'Purchase Invoice', 'Supplier', 'Product',
                   'Quantity', 'Amount', 'Refund Method', 'Credit Note Ref', 'Reason',
                   'Returned By', 'Cashbook Synced']);
    foreach ($allReturns['data'] as $row) {
        fputcsv($out, [
            $row['return_no'],
            $row['created_at'],
            $row['purchase_invoice_no'],
            $row['supplier_name'],
            $row['product_name'] ?? '',
            $row['quantity'],
            $row['total_amount'],
            $row['refund_method'] ?? '',
            $row['credit_note_ref'] ?? '',
            $row['reason'] ?? '',
            $row['returned_by'] ?? '',
            !empty($row['has_cashbook']) ? 'Yes' : 'No',
        ]);
    }
    fclose($out);
    exit;
}

// ── Main data ──────────────────────────────────────────────────────────────
$returns = getAllPurchaseReturns($page, 20, $search, $fromDate, $toDate, $refundMethod);

// ── Summary stats ──────────────────────────────────────────────────────────
$today      = date('Y-m-d');
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-d', strtotime('first day of this month'));

$safe = fn($row) => $row ?? ['total' => 0, 'count' => 0];

$summary = [
    'today' => $safe(_pdoOne("SELECT COALESCE(SUM(total_amount),0) as total, COUNT(*) as count FROM purchase_returns WHERE DATE(created_at) = :d", [':d' => $today])),
    'week'  => $safe(_pdoOne("SELECT COALESCE(SUM(total_amount),0) as total, COUNT(*) as count FROM purchase_returns WHERE DATE(created_at) >= :s", [':s' => $weekStart])),
    'month' => $safe(_pdoOne("SELECT COALESCE(SUM(total_amount),0) as total, COUNT(*) as count FROM purchase_returns WHERE DATE(created_at) >= :s", [':s' => $monthStart])),
];

$page_title = 'Purchase Returns History';
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<?php include '../includes/navbar.php'; ?>
<div class="main-content">
    <div class="content-area">

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h4 class="mb-1 fw-bold" style="color: var(--pharmacy-heading);">
                    <i class="fas fa-history me-2" style="color: var(--pharmacy-primary);"></i>Purchase Returns History
                </h4>
                <p class="mb-0" style="color: color-mix(in srgb, var(--pharmacy-text) 60%, transparent); font-size: 0.88rem;">
                    Track all supplier returns with sync status
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="purchase_return.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-exchange-alt me-1"></i> New Return
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i> Export CSV
                </a>
            </div>
        </div>

        <!-- Summary Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card" style="border-left-color: var(--pharmacy-primary);">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Today's Returns</div>
                            <div class="stat-value">Rs <?php echo number_format((float)$summary['today']['total'], 0); ?></div>
                            <div class="stat-label mt-1"><?php echo (int)$summary['today']['count']; ?> return(s)</div>
                        </div>
                        <i class="fas fa-calendar-day stat-icon" style="color: var(--pharmacy-primary);"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="border-left-color: #f59e0b;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">This Week</div>
                            <div class="stat-value" style="color: #f59e0b;">Rs <?php echo number_format((float)$summary['week']['total'], 0); ?></div>
                            <div class="stat-label mt-1"><?php echo (int)$summary['week']['count']; ?> return(s)</div>
                        </div>
                        <i class="fas fa-calendar-week stat-icon" style="color: #f59e0b;"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="border-left-color: #10b981;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">This Month</div>
                            <div class="stat-value" style="color: #10b981;">Rs <?php echo number_format((float)$summary['month']['total'], 0); ?></div>
                            <div class="stat-label mt-1"><?php echo (int)$summary['month']['count']; ?> return(s)</div>
                        </div>
                        <i class="fas fa-calendar-alt stat-icon" style="color: #10b981;"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar mb-4">
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold" style="color: var(--pharmacy-text);">From Date</label>
                    <input type="date" name="from_date" class="form-control"
                           value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold" style="color: var(--pharmacy-text);">To Date</label>
                    <input type="date" name="to_date" class="form-control"
                           value="<?php echo htmlspecialchars($toDate); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold" style="color: var(--pharmacy-text);">Refund Method</label>
                    <select name="refund_method" class="form-select">
                        <option value="">All Methods</option>
                        <option value="cash"        <?php echo $refundMethod==='cash'        ?'selected':''; ?>>Cash</option>
                        <option value="credit_note" <?php echo $refundMethod==='credit_note' ?'selected':''; ?>>Credit Note</option>
                        <option value="bank"        <?php echo $refundMethod==='bank'        ?'selected':''; ?>>Bank</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold" style="color: var(--pharmacy-text);">Search</label>
                    <input type="text" name="search" class="form-control"
                           placeholder="Return #, Invoice #, Supplier name..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <div class="col-md-1">
                    <a href="purchase_return_list.php" class="btn btn-outline-secondary w-100" title="Reset">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Returns Table -->
        <div class="table-card" id="table-container">
            <div class="card-header d-flex justify-content-between align-items-center" style="background: var(--pharmacy-table-th); border-bottom: 2px solid var(--pharmacy-border); padding: 14px 18px;">
                <span class="fw-bold" style="color: var(--pharmacy-heading);">
                    <i class="fas fa-list me-2" style="color: var(--pharmacy-primary);"></i>Return Records
                </span>
                <span style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent); font-size: 0.82rem;">
                    <?php echo $returns['total']; ?> total record(s)
                    <?php if ($search || $fromDate || $toDate || $refundMethod): ?>
                        <span class="status-badge ms-2" style="background: color-mix(in srgb, var(--pharmacy-primary) 15%, transparent); color: var(--pharmacy-primary);">Filtered</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="purchaseReturnTable">
                    <thead>
                        <tr>
                            <th>Return No.</th>
                            <th>Date</th>
                            <th>Purchase Invoice</th>
                            <th>Supplier</th>
                            <th>Product</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Amount</th>
                            <th>Refund Method</th>
                            <th>Credit Note</th>
                            <th class="text-center">Cashbook</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($returns['data'])): ?>
                            <?php foreach ($returns['data'] as $row): ?>
                            <tr>
                                <td><strong style="color: #ef4444;"><?php echo htmlspecialchars($row['return_no']); ?></strong></td>
                                <td style="color: var(--pharmacy-text);">
                                    <?php echo !empty($row['created_at']) ? date('d M Y', strtotime($row['created_at'])) : '—'; ?>
                                    <br><span style="font-size:0.72rem; color: color-mix(in srgb, var(--pharmacy-text) 50%, transparent);">
                                        <?php echo !empty($row['created_at']) ? date('h:i A', strtotime($row['created_at'])) : ''; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="../purchases/view.php?id=<?php echo (int)$row['purchase_id']; ?>"
                                       style="color: var(--pharmacy-primary); text-decoration: none;" target="_blank">
                                        <?php echo htmlspecialchars($row['purchase_invoice_no']); ?>
                                        <i class="fas fa-external-link-alt fa-xs ms-1" style="opacity:0.6;"></i>
                                    </a>
                                </td>
                                <td style="color: var(--pharmacy-text);"><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                                <td style="color: var(--pharmacy-text); font-size: 0.88rem;"><?php echo htmlspecialchars($row['product_name'] ?? '—'); ?></td>
                                <td class="text-center">
                                    <span class="status-badge" style="background: var(--pharmacy-table-th); color: var(--pharmacy-text);"><?php echo (int)$row['quantity']; ?></span>
                                </td>
                                <td class="text-end fw-bold" style="color: #ef4444;">
                                    Rs <?php echo number_format((float)$row['total_amount'], 2); ?>
                                </td>
                                <td>
                                    <?php
                                    $rm = $row['refund_method'] ?? 'credit_note';
                                    if ($rm === 'cash') echo '<span class="status-badge badge-instock">Cash</span>';
                                    elseif ($rm === 'bank') echo '<span class="status-badge" style="background: color-mix(in srgb, var(--pharmacy-primary) 15%, transparent); color: var(--pharmacy-primary);">Bank</span>';
                                    else echo '<span class="status-badge badge-expiring">Credit Note</span>';
                                    ?>
                                </td>
                                <td style="color: color-mix(in srgb, var(--pharmacy-text) 60%, transparent); font-size: 0.85rem;">
                                    <?php echo htmlspecialchars($row['credit_note_ref'] ?? '—'); ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($row['has_cashbook'])): ?>
                                        <span class="status-badge badge-instock">
                                            <i class="fas fa-check-circle me-1"></i>Synced
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge badge-oos">
                                            <i class="fas fa-exclamation-triangle me-1"></i>Missing
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex gap-1 justify-content-center">
                                        <a href="purchase_return_view.php?id=<?php echo (int)$row['id']; ?>"
                                           class="btn btn-sm btn-info" data-bs-toggle="tooltip" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (empty($row['has_cashbook'])): ?>
                                        <button class="btn btn-sm btn-warning btn-fix-cashbook"
                                                data-id="<?php echo (int)$row['id']; ?>"
                                                data-bs-toggle="tooltip" title="Fix Cashbook Entry">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center py-5" style="color: color-mix(in srgb, var(--pharmacy-text) 50%, transparent);">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                                    No returns found
                                    <?php if ($search || $fromDate || $toDate || $refundMethod): ?>
                                        matching your filters. <a href="purchase_return_list.php" class="d-block mt-1" style="color: var(--pharmacy-primary);">Clear filters</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($returns['totalPages'] > 1): ?>
            <div class="d-flex justify-content-between align-items-center px-3 py-2" style="border-top: 1px solid var(--pharmacy-border);">
                <div style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent); font-size: 0.82rem;">
                    Showing <?php echo count($returns['data']); ?> of <?php echo $returns['total']; ?> records
                    &nbsp;|&nbsp; Page <?php echo $page; ?> of <?php echo $returns['totalPages']; ?>
                </div>
                <nav>
                    <ul class="pagination mb-0 pagination-sm">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <?php
                        $start = max(1, $page - 2);
                        $end   = min($returns['totalPages'], $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $returns['totalPages'] ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
function showToast(message, type) {
    type = type || 'success';
    let bg = type === 'success' ? 'bg-success' : (type === 'danger' ? 'bg-danger' : 'bg-warning');
    let container = document.querySelector('.toast-container');
    if (!container) return;
    let el = document.createElement('div');
    el.innerHTML = `<div class="toast align-items-center text-white ${bg} border-0" role="alert" data-bs-autohide="true" data-bs-delay="4000">
        <div class="d-flex"><div class="toast-body">${message}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`;
    container.appendChild(el.firstChild);
    let toastEl = container.lastChild;
    new bootstrap.Toast(toastEl).show();
    toastEl.addEventListener('hidden.bs.toast', function() { this.remove(); });
}

document.querySelectorAll('.btn-fix-cashbook').forEach(function(btn) {
    btn.addEventListener('click', function() {
        let returnId = this.dataset.id;
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        let _btn = this;

        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'fix_cashbook', return_id: returnId })
        })
        .then(r => r.json())
        .then(function(res) {
            if (res.success) {
                showToast(res.message, 'success');
                let row = _btn.closest('tr');
                row.querySelector('.badge-oos')?.replaceWith((() => {
                    let s = document.createElement('span');
                    s.className = 'status-badge badge-instock';
                    s.innerHTML = '<i class="fas fa-check-circle me-1"></i>Synced';
                    return s;
                })());
                _btn.remove();
            } else {
                showToast(res.message, 'danger');
                _btn.disabled = false;
                _btn.innerHTML = '<i class="fas fa-sync-alt"></i>';
            }
        })
        .catch(function() {
            showToast('Server error. Please try again.', 'danger');
            _btn.disabled = false;
            _btn.innerHTML = '<i class="fas fa-sync-alt"></i>';
        });
    });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    UniversalTable.init('purchaseReturnTable', {
        title      : 'Purchase Returns',
        pageSize   : 25,
        excludeCols: [10]
    });
});
</script>