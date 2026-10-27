<?php
/**
 * Expenses List Page – Enhanced with Cashbook Sync Status, Fix Button, Export
 */

require_once '../config/config.php';
require_once '../includes/expense_functions.php';
require_once '../includes/sync_engine.php';

requireLogin();

if (!canView('expenses')) {
    $_SESSION['flash']['error'] = [
        'message' => '<i class="bi bi-lock-fill me-2"></i>You do not have permission to view Expenses. Contact your administrator.',
        'type'    => 'warning'
    ];
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

// ─── AJAX Handlers for fixing missing cashbook entries ───────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fix_cashbook' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $expenseId = (int)$_GET['id'];
    $expense = getOne("SELECT * FROM expenses WHERE id = :id ", [':id' => $expenseId]);
    if (!$expense) {
        echo json_encode(['success' => false, 'message' => 'Expense not found']);
        exit;
    }
    // Check if cashbook entry already exists
    $existing = getOne("SELECT id FROM cashbook WHERE reference_id = :rid AND reference_type = 'expense'", [':rid' => $expenseId]);
    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'Cashbook entry already exists']);
        exit;
    }
    // Create missing entry
    $entryId = ensureCashbookEntry(
        entry_type: 'expense',
        reference_id: $expenseId,
        reference_type: 'expense',
        description: 'Expense — ' . $expense['category'] . ': ' . $expense['description'],
        debit: (float)$expense['amount'],
        credit: 0,
        payment_method: $expense['payment_method'],
        entry_date: $expense['expense_date']
    );
    if ($entryId) {
        echo json_encode(['success' => true, 'message' => 'Cashbook entry created successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create cashbook entry']);
    }
    exit;
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $fromDate = $_GET['from_date'] ?? date('Y-m-01');
    $toDate = $_GET['to_date'] ?? date('Y-m-d');
    $category = $_GET['category'] ?? '';
    $expenses = getExpensesByDateRange($fromDate, $toDate, $category);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="expenses_' . date('Ymd') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date', 'Category', 'Description', 'Amount', 'Payment Method', 'Receipt Note', 'Cashbook Synced']);
    foreach ($expenses as $e) {
        fputcsv($output, [
            $e['id'],
            $e['expense_date'],
            $e['category'],
            $e['description'],
            $e['amount'],
            $e['payment_method'],
            $e['receipt_note'],
            $e['has_cashbook'] ? 'Yes' : 'No'
        ]);
    }
    fclose($output);
    exit;
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$fromDate = isset($_GET['from_date']) ? sanitize($_GET['from_date']) : date('Y-m-01');
$toDate   = isset($_GET['to_date'])   ? sanitize($_GET['to_date'])   : date('Y-m-d');
$category = isset($_GET['category'])  ? sanitize($_GET['category'])  : '';

$filters = [
    'from_date' => $fromDate,
    'to_date'   => $toDate,
    'category'  => $category,
];

$expenses   = getExpenses($filters);
$summary    = getExpenseSummary($fromDate, $toDate);
$todaySummary = getExpenseSummaryToday();
$categories = getExpenseCategories();
$monthlyTotals = getMonthlyExpenseTotals();
$grandTotal = array_sum(array_column($expenses, 'amount'));

$flashSuccess = getFlash('success');
$flashError   = getFlash('error');

$pageTitle = 'Expenses';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        body { background: var(--pharmacy-body-bg, #f8fafc); color: var(--pharmacy-text, #444); }
        .stat-card { border-left: 4px solid var(--pharmacy-primary, #2563eb); background: var(--pharmacy-card-bg, #fff); border-radius: .5rem; padding: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,.05); color: var(--pharmacy-text, #444); }
        .stat-card strong, .stat-card h5, .stat-card h4 { color: var(--pharmacy-heading, #111); }
        .category-badge { font-size: .75rem; }
        .filter-card { background: var(--pharmacy-card-bg, #fff); border-radius: .5rem; border: 1px solid var(--pharmacy-border, #dee2e6); }
        .synced-badge { background: #d4edda; color: #155724; }
        .missing-badge { background: #f8d7da; color: #721c24; }
        .btn-fix { padding: 2px 8px; font-size: .7rem; }
        .card { background: var(--pharmacy-card-bg, #fff) !important; border-color: var(--pharmacy-border, #dee2e6) !important; color: var(--pharmacy-text, #444) !important; }
        .card-header { background: var(--pharmacy-card-bg, #fff) !important; border-color: var(--pharmacy-border, #dee2e6) !important; color: var(--pharmacy-text, #444) !important; }
        .table { color: var(--pharmacy-text, #444) !important; }
        .table thead th { background: var(--pharmacy-table-th, #f8f9fa) !important; color: var(--pharmacy-heading, #1a1d23) !important; border-color: var(--pharmacy-border, #dee2e6) !important; }
        .table td { border-color: var(--pharmacy-border, #dee2e6) !important; color: var(--pharmacy-text, #444) !important; }
        .main-content { margin-left: var(--sb-w, 260px); padding: 24px; min-height: 100vh; transition: margin-left .25s ease; animation: fadeInPage 0.28s ease; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
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

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-cash-stack text-primary me-2"></i>Expenses</h4>
            <small class="text-muted">Apne pharmacy ke tamam kharchay yahan manage karein</small>
        </div>
        <?php if (canAdd('expenses')): ?>
        <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Naya Expense</a>
        <?php endif; ?>
    </div>

    <!-- Flash Messages -->
    <?php if ($flashSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-1"></i> <?= htmlspecialchars($flashSuccess) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-1"></i> <?= htmlspecialchars($flashError) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- Today's Expense Summary Card -->
    <div class="row g-3 mb-4">
        <div class="col-md-6 col-lg-3">
            <div class="stat-card">
                <div class="text-muted small">Today's Total Expense</div>
                <div class="fs-3 fw-bold text-danger"><?= formatCurrency($todaySummary['total']) ?></div>
                <small class="text-muted"><?= $todaySummary['total_count'] ?> entries | <?= $todaySummary['synced_count'] ?> synced</small>
            </div>
        </div>
        <?php foreach (array_slice($todaySummary['by_category'], 0, 3) as $cat): ?>
        <div class="col-md-6 col-lg-3">
            <div class="stat-card border-start border-warning">
                <div class="text-muted small"><?= htmlspecialchars($cat['category']) ?></div>
                <div class="fs-4 fw-bold"><?= formatCurrency($cat['total']) ?></div>
                <small><?= $cat['count'] ?> expense(s)</small>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter Card -->
    <div class="card filter-card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="index.php" class="row g-3 align-items-end">
                <div class="col-md-3"><label class="form-label fw-semibold">From Date</label><input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($fromDate) ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">To Date</label><input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Category</label><select name="category" class="form-select"><option value="">— Sab Categories —</option><?php foreach ($categories as $cat): ?><option value="<?= htmlspecialchars($cat['value']) ?>" <?= $category === $cat['value'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['label']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3 d-flex gap-2"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i> Filter</button><a href="index.php" class="btn btn-outline-secondary w-100"><i class="bi bi-x-circle me-1"></i> Reset</a><a href="?export=csv&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>&category=<?= urlencode($category) ?>" class="btn btn-success w-100"><i class="bi bi-file-excel me-1"></i> CSV</a></div>
            </form>
        </div>
    </div>

    <!-- Category Summary Cards (for selected range) -->
    <div class="row g-3 mb-4">
        <?php foreach ($summary as $row): $pct = $grandTotal > 0 ? round(($row['total'] / $grandTotal) * 100, 1) : 0; ?>
        <div class="col-md-4 col-lg-3">
            <div class="stat-card">
                <div class="text-muted small"><?= htmlspecialchars($row['category']) ?></div>
                <div class="fs-4 fw-bold text-danger"><?= formatCurrency($row['total']) ?></div>
                <div class="progress mt-2" style="height:4px;"><div class="progress-bar bg-primary" style="width: <?= $pct ?>%"></div></div>
                <small><?= $row['count'] ?> entries (<?= $pct ?>%)</small>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($summary)): ?>
        <div class="col-12"><div class="alert alert-info">No expenses in selected period.</div></div>
        <?php endif; ?>
    </div>

    <!-- Monthly Totals -->
    <?php if (!empty($monthlyTotals)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-calendar3 text-success me-1"></i> Monthly Totals — <?= date('Y') ?></div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0"><thead class="table-light"><tr><?php foreach ($monthlyTotals as $mt): ?><th class="text-center"><?= date('M', mktime(0,0,0,(int)substr($mt['month'],5,2),1)) ?></th><?php endforeach; ?></tr></thead><tbody><tr><?php foreach ($monthlyTotals as $mt): ?><td class="text-center fw-semibold text-danger"><?= formatCurrency($mt['total']) ?></td><?php endforeach; ?></tr></tbody></table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Expenses Table with Cashbook Sync Status -->
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-list-ul text-primary me-1"></i> Expense Entries <span class="badge bg-secondary ms-1"><?= count($expenses) ?></span></span>
            <span class="text-muted small">Period Total: <strong class="text-danger"><?= formatCurrency($grandTotal) ?></strong></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($expenses)): ?>
            <div class="text-center py-5 text-muted"><i class="bi bi-inbox fs-2 d-block mb-2"></i>No records found.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0" id="expensesTable">
                    <thead class="table-light">
                        <tr>
                            <th>#</th><th>Date</th><th>Category</th><th>Description</th><th>Payment</th><th class="text-end">Amount</th><th>Cashbook</th><th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $i => $exp): ?>
                        <tr>
                            <td class="text-muted small"><?= $i+1 ?></td>
                            <td><?= formatDate($exp['expense_date']) ?></td>
                            <td><span class="badge bg-primary bg-opacity-10 text-primary category-badge"><?= htmlspecialchars($exp['category']) ?></span></td>
                            <td><?= htmlspecialchars($exp['description']) ?> <small class="text-muted"><?= $exp['receipt_note'] ? '(' . htmlspecialchars(mb_strimwidth($exp['receipt_note'],0,30,'…')) . ')' : '' ?></small></td>
                            <td><span class="badge bg-success bg-opacity-10 text-success"><?= htmlspecialchars($exp['payment_method']) ?></span></td>
                            <td class="text-end fw-semibold text-danger"><?= formatCurrency($exp['amount']) ?></td>
                            <td>
                                <?php if ($exp['has_cashbook']): ?>
                                    <span class="badge synced-badge"><i class="bi bi-check-circle-fill me-1"></i> Synced</span>
                                <?php else: ?>
                                    <span class="badge missing-badge"><i class="bi bi-exclamation-triangle-fill me-1"></i> Missing</span>
                                    <button class="btn btn-sm btn-warning btn-fix fix-cashbook" data-id="<?= $exp['id'] ?>">Fix</button>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if (canEdit('expenses')): ?>
                                <a href="edit.php?id=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <?php if (canDelete('expenses')): ?>
                                <button class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= $exp['id'] ?>" data-desc="<?= htmlspecialchars($exp['description']) ?>"><i class="bi bi-trash"></i></button>
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

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>Expense Delete Karein?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">Kya aap yaqeen se <strong id="deleteDesc"></strong> expense delete karna chahte hain?<br><small class="text-muted">Cashbook entry bhi remove ho jayegi.</small></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><a href="#" id="deleteConfirmBtn" class="btn btn-danger"><i class="bi bi-trash me-1"></i> Delete Karein</a></div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Delete modal
    $('.btn-delete').on('click', function() {
        let id = $(this).data('id');
        let desc = $(this).data('desc');
        $('#deleteDesc').text(desc);
        $('#deleteConfirmBtn').attr('href', 'delete.php?id=' + id);
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    });

    // Fix missing cashbook entries
    $('.fix-cashbook').on('click', function() {
        let $btn = $(this);
        let id = $btn.data('id');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
        $.get(window.location.href + '?ajax=fix_cashbook&id=' + id, function(res) {
            if (res.success) {
                $btn.closest('td').html('<span class="badge synced-badge"><i class="bi bi-check-circle-fill me-1"></i> Synced</span>');
            } else {
                Notify.warning(res.message);
                $btn.prop('disabled', false).html('Fix');
            }
        }, 'json').fail(function() {
            Notify.error('Server error');
            $btn.prop('disabled', false).html('Fix');
        });
    });
});
</script>
</div><!-- /container-fluid -->
</div><!-- /main-content -->
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    UniversalTable.init('expensesTable', {
        title: 'Expenses',
        pageSize: 0,
        searchable: true,
        sortable: true,
        exportExcel: true,
        exportPdf: true,
        printable: true,
        excludeCols: [5]
    });
});
</script>
</body>
</html>