<?php
/**
 * returns/sale_return_list.php
 *
 * Enhanced with:
 * - Cashbook sync status column
 * - Missing entry fix button (AJAX)
 * - Summary stat cards (today, week, month)
 * - Filters: date range, refund method
 * - Export to CSV
 * - Link to original sale and credit details
 * - Design system: CSS variables, table-card, status badges, smooth transitions
 */

require_once '../config/config.php';
require_once '../includes/return_functions.php';
require_once '../includes/sync_engine.php';
require_once '../includes/cashbook_functions.php';

requireLogin();

$page_title = 'Sale Returns History';

// Filters
$page         = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$search       = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$fromDate     = isset($_GET['from_date']) ? sanitize($_GET['from_date']) : '';
$toDate       = isset($_GET['to_date']) ? sanitize($_GET['to_date']) : '';
$refundMethod = isset($_GET['refund_method']) ? sanitize($_GET['refund_method']) : '';
$export       = isset($_GET['export']) && $_GET['export'] === 'csv';

$filters = [
    'customer'      => $search,
    'from_date'     => $fromDate,
    'to_date'       => $toDate,
    'refund_method' => $refundMethod,
];

$returns = getAllSaleReturns($filters, $page, 20);

// Summary stats
$today      = date('Y-m-d');
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-d', strtotime('first day of this month'));

$db = db();
$summary = [
    'today' => $db->getOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total, COUNT(*) as count
         FROM sale_returns WHERE DATE(created_at) = :date",
        [':date' => $today]
    ),
    'week' => $db->getOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total, COUNT(*) as count
         FROM sale_returns WHERE DATE(created_at) >= :start",
        [':start' => $weekStart]
    ),
    'month' => $db->getOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total, COUNT(*) as count
         FROM sale_returns WHERE DATE(created_at) >= :start",
        [':start' => $monthStart]
    ),
];

// Handle AJAX fix request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fix_cashbook') {
    header('Content-Type: application/json');
    $return_id = (int)$_POST['return_id'];

    $return = getSaleReturnDetails($return_id);
    if (!$return) {
        echo json_encode(['success' => false, 'message' => 'Return not found']);
        exit;
    }

    $exists = $db->getOne(
        "SELECT id FROM cashbook WHERE reference_id = :rid AND reference_type = 'sale_return'",
        [':rid' => $return_id]
    );
    if ($exists) {
        echo json_encode(['success' => false, 'message' => 'Cashbook entry already exists']);
        exit;
    }

    $entry_id = addCashbookEntry(
        entryType      : 'sale_return',
        referenceId    : $return_id,
        referenceType  : 'sale_return',
        description    : "Sale Return {$return['return_no']} - Refund to Customer",
        debit          : (float)$return['total_amount'],
        credit         : 0.00,
        paymentMethod  : $return['refund_method'],
        userId         : $_SESSION['user_id'],
        entryDate      : date('Y-m-d', strtotime($return['created_at'])),
        preventDuplicate: true
    );

    echo json_encode($entry_id
        ? ['success' => true,  'message' => 'Cashbook entry created', 'entry_id' => $entry_id]
        : ['success' => false, 'message' => 'Failed to create cashbook entry']
    );
    exit;
}

// Export to CSV
if ($export) {
    $allReturns = getAllSaleReturns($filters, 1, 10000);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sale_returns_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Return No', 'Date', 'Invoice No', 'Customer', 'Product', 'Quantity', 'Amount', 'Refund Method', 'Reason', 'Returned By', 'Cashbook Synced']);
    foreach ($allReturns['data'] as $row) {
        fputcsv($output, [
            $row['return_no'],
            $row['created_at'],
            $row['invoice_no'],
            $row['customer_name'],
            $row['product_name'],
            $row['quantity'],
            $row['total_amount'],
            $row['refund_method'],
            $row['reason'],
            $row['returned_by'],
            $row['has_cashbook'] ? 'Yes' : 'No',
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        /* ── Page fade-in ── */
        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .main-content {
            margin-left : var(--sb-w, 260px);
            padding     : 24px;
            min-height  : 100vh;
            animation   : fadeInPage 0.28s ease;
            background  : var(--pharmacy-bg, #f0f2f5);
            transition  : margin-left 0.3s ease;
        }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } } }

        /* ── Cards ── */
        .card-custom {
            background    : var(--pharmacy-card-bg, #fff);
            border        : 1px solid var(--pharmacy-border, #e9ecef);
            border-radius : var(--card-border-radius, 12px);
            box-shadow    : 0 2px 12px rgba(0,0,0,0.07);
            margin-bottom : 20px;
            transition    : box-shadow 0.2s ease;
        }
        .card-custom:hover { box-shadow: 0 6px 24px rgba(0,0,0,0.11); }

        /* ── Stat Cards ── */
        .stat-card {
            background    : var(--pharmacy-card-bg, #fff);
            border        : 1px solid var(--pharmacy-border, #e9ecef);
            border-left   : 4px solid var(--pharmacy-primary, #0d6efd);
            border-radius : var(--card-border-radius, 12px);
            box-shadow    : 0 2px 12px rgba(0,0,0,0.07);
            padding       : 20px 24px;
            transition    : transform 0.2s ease, box-shadow 0.2s ease;
            height        : 100%;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,0.13); }
        .stat-card .stat-value {
            font-size   : 1.8rem;
            font-weight : 700;
            color       : var(--pharmacy-heading, #212529);
            line-height : 1.2;
        }
        .stat-card .stat-label {
            font-size      : 0.72rem;
            color          : color-mix(in srgb, var(--pharmacy-text, #495057) 65%, transparent);
            text-transform : uppercase;
            letter-spacing : 0.5px;
            font-weight    : 600;
        }
        .stat-card .stat-meta { font-size: 0.8rem; color: var(--pharmacy-text, #6c757d); margin-top: 4px; }
        .stat-card .stat-icon { font-size: 2rem; opacity: 0.14; }
        .stat-card.accent-danger   { border-left-color: #dc3545; }
        .stat-card.accent-warning  { border-left-color: #ffc107; }
        .stat-card.accent-success  { border-left-color: #198754; }
        .stat-card.accent-info     { border-left-color: #0dcaf0; }

        /* ── Card header ── */
        .card-header-custom {
            background    : var(--pharmacy-table-th, #f8f9fa);
            border-bottom : 2px solid var(--pharmacy-border, #e9ecef);
            padding       : 14px 20px;
            border-radius : var(--card-border-radius, 12px) var(--card-border-radius, 12px) 0 0;
            font-weight   : 600;
            color         : var(--pharmacy-heading, #212529);
            display       : flex;
            align-items   : center;
            gap           : 8px;
        }

        /* ── Filter bar ── */
        .filter-bar {
            background    : var(--pharmacy-card-bg, #fff);
            border        : 1px solid var(--pharmacy-border, #e9ecef);
            border-radius : var(--card-border-radius, 12px);
            padding       : 18px 20px;
            margin-bottom : 20px;
            box-shadow    : 0 1px 6px rgba(0,0,0,0.06);
        }
        .filter-bar .form-label { color: var(--pharmacy-text, #495057); font-weight: 600; }
        .filter-bar .form-control,
        .filter-bar .form-select {
            border-color : var(--pharmacy-border, #dee2e6);
            color        : var(--pharmacy-text, #495057);
            background   : var(--pharmacy-card-bg, #fff);
            transition   : border-color 0.2s, box-shadow 0.2s;
        }
        .filter-bar .form-control:focus,
        .filter-bar .form-select:focus {
            border-color : var(--pharmacy-primary, #0d6efd);
            box-shadow   : 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 15%, transparent);
        }

        /* ── Table card ── */
        .table-card {
            border-radius : var(--card-border-radius, 12px);
            overflow      : hidden;
            border        : 1px solid var(--pharmacy-border, #e9ecef);
            box-shadow    : 0 2px 8px rgba(0,0,0,0.05);
        }
        .table th {
            background     : var(--pharmacy-table-th, #f8f9fa) !important;
            color          : var(--pharmacy-heading, #212529) !important;
            font-size      : 0.75rem;
            text-transform : uppercase;
            letter-spacing : 0.4px;
            font-weight    : 700;
            padding        : 12px 16px;
            border-bottom  : 2px solid var(--pharmacy-border, #e9ecef) !important;
        }
        .table td {
            padding        : 11px 16px;
            vertical-align : middle;
            color          : var(--pharmacy-text, #495057) !important;
            border-color   : var(--pharmacy-border, #e9ecef) !important;
        }
        .table tbody tr { transition: background 0.15s ease; }
        .table-hover tbody tr:hover td {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 6%, var(--pharmacy-card-bg, #fff)) !important;
        }
        .table tbody tr:not(:last-child) td {
            border-bottom: 1px solid var(--pharmacy-border, #e9ecef) !important;
        }

        /* ── Status badges ── */
        .status-badge {
            padding       : 3px 10px;
            border-radius : 20px;
            font-size     : 0.72rem;
            font-weight   : 600;
            display       : inline-block;
        }
        .badge-synced  { background: #d1fae5; color: #065f46; }
        .badge-missing { background: #fee2e2; color: #991b1b; }
        .badge-cash    { background: #dcfce7; color: #166534; }
        .badge-bank    { background: #dbeafe; color: #1e40af; }
        .badge-credit-note { background: #fef3c7; color: #92400e; }
        .badge-method  { background: var(--pharmacy-table-th, #f1f5f9); color: var(--pharmacy-text, #475569); }

        /* ── Customer link ── */
        .customer-link { color: var(--pharmacy-primary, #1a56db); transition: color 0.15s; }
        .customer-link:hover { color: color-mix(in srgb, var(--pharmacy-primary, #1a56db) 70%, #000); text-decoration: underline !important; }

        /* ── Pagination ── */
        .pagination .page-link {
            border-color : var(--pharmacy-border, #dee2e6);
            color        : var(--pharmacy-primary, #0d6efd);
            transition   : all 0.2s;
        }
        .pagination .page-item.active .page-link {
            background   : var(--pharmacy-primary, #0d6efd);
            border-color : var(--pharmacy-primary, #0d6efd);
            color        : #fff;
        }

        /* ── Buttons ── */
        .btn { transition: all 0.2s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        /* ── Page header ── */
        .page-header h4 { color: var(--pharmacy-heading, #212529); font-weight: 700; }

        /* ── Empty state ── */
        .empty-state { padding: 60px 20px; text-align: center; color: var(--pharmacy-text, #6c757d); }
        .empty-state i { font-size: 3rem; opacity: 0.3; margin-bottom: 12px; }
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
        <div class="d-flex justify-content-between align-items-start mb-4 page-header">
            <div>
                <h4 class="mb-1"><i class="fas fa-history me-2 text-primary"></i>Sale Returns History</h4>
                <p class="text-muted mb-0 small">Track all customer returns with cashbook sync status</p>
            </div>
            <div class="d-flex gap-2">
                <a href="sale_return.php" class="btn btn-primary">
                    <i class="fas fa-undo-alt me-2"></i>New Return
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline-success">
                    <i class="fas fa-file-excel me-2"></i>Export CSV
                </a>
            </div>
        </div>

        <!-- Summary Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4 col-sm-6">
                <div class="stat-card accent-danger">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Today's Returns</div>
                            <div class="stat-value"><?php echo formatCurrency($summary['today']['total'] ?? 0); ?></div>
                            <div class="stat-meta"><?php echo $summary['today']['count'] ?? 0; ?> transaction(s)</div>
                        </div>
                        <i class="fas fa-calendar-day stat-icon text-danger"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stat-card accent-warning">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">This Week</div>
                            <div class="stat-value"><?php echo formatCurrency($summary['week']['total'] ?? 0); ?></div>
                            <div class="stat-meta"><?php echo $summary['week']['count'] ?? 0; ?> transaction(s)</div>
                        </div>
                        <i class="fas fa-calendar-week stat-icon text-warning"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div class="stat-card accent-info">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">This Month</div>
                            <div class="stat-value"><?php echo formatCurrency($summary['month']['total'] ?? 0); ?></div>
                            <div class="stat-meta"><?php echo $summary['month']['count'] ?? 0; ?> transaction(s)</div>
                        </div>
                        <i class="fas fa-calendar-alt stat-icon text-info"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-3 col-sm-6">
                    <label class="form-label small">From Date</label>
                    <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div class="col-md-3 col-sm-6">
                    <label class="form-label small">To Date</label>
                    <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($toDate); ?>">
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label small">Refund Method</label>
                    <select name="refund_method" class="form-select">
                        <option value="">All Methods</option>
                        <option value="cash"        <?php echo $refundMethod === 'cash'        ? 'selected' : ''; ?>>Cash</option>
                        <option value="credit_note" <?php echo $refundMethod === 'credit_note' ? 'selected' : ''; ?>>Credit Note</option>
                        <option value="bank"        <?php echo $refundMethod === 'bank'        ? 'selected' : ''; ?>>Bank Transfer</option>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6">
                    <label class="form-label small">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Return #, Invoice #, Customer…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-1 col-sm-12">
                    <button type="submit" class="btn btn-primary w-100" data-bs-toggle="tooltip" title="Apply Filters">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
                <div class="col-auto">
                    <a href="sale_return_list.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-1"></i>Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Returns Table -->
        <div class="card-custom" id="table-container">
            <div class="card-header-custom">
                <i class="fas fa-table text-primary"></i>
                Return Records
                <span class="ms-auto badge rounded-pill" style="background:var(--pharmacy-primary,#0d6efd);font-size:0.72rem;">
                    <?php echo $returns['total'] ?? 0; ?> total
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="saleReturnTable">
                                <th>Invoice No.</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Refund Method</th>
                                <th class="text-center">Cashbook</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($returns['data']) > 0): ?>
                                <?php foreach ($returns['data'] as $return): ?>
                                <tr>
                                    <td>
                                        <a href="sale_return_view.php?id=<?php echo $return['id']; ?>"
                                           class="fw-semibold text-decoration-none"
                                           style="color:var(--pharmacy-primary,#0d6efd);">
                                            <?php echo htmlspecialchars($return['return_no']); ?>
                                        </a>
                                    </td>
                                    <td class="small text-muted"><?php echo formatDate($return['created_at'], true); ?></td>
                                    <td>
                                        <a href="../sales/view.php?id=<?php echo $return['sale_id']; ?>"
                                           class="text-decoration-none small customer-link">
                                            <?php echo htmlspecialchars($return['invoice_no']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if (!empty($return['customer_phone'])): ?>
                                            <a href="../sales/customer_view.php?phone=<?php echo urlencode($return['customer_phone']); ?>"
                                               class="customer-link d-block fw-semibold text-decoration-none">
                                                <i class="fas fa-user-circle me-1 text-primary" style="font-size:11px;"></i>
                                                <?php echo htmlspecialchars($return['customer_name'] ?? '—'); ?>
                                            </a>
                                            <a href="../sales/customer_view.php?phone=<?php echo urlencode($return['customer_phone']); ?>"
                                               class="text-muted text-decoration-none" style="font-size:11px;">
                                                <i class="fas fa-phone me-1" style="font-size:10px;"></i>
                                                <?php echo htmlspecialchars($return['customer_phone']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span><?php echo htmlspecialchars($return['customer_name'] ?? '—'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?php echo htmlspecialchars($return['product_name']); ?></td>
                                    <td class="text-center">
                                        <span class="status-badge" style="background:var(--pharmacy-table-th,#f1f5f9);color:var(--pharmacy-text,#475569);">
                                            <?php echo $return['quantity']; ?> pcs
                                        </span>
                                    </td>
                                    <td class="text-end fw-bold" style="color:#dc3545;">
                                        <?php echo formatCurrency($return['total_amount']); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $method = strtolower($return['refund_method'] ?? 'cash');
                                        $methodClass = match($method) {
                                            'cash'        => 'badge-cash',
                                            'bank'        => 'badge-bank',
                                            'credit_note' => 'badge-credit-note',
                                            default       => 'badge-method',
                                        };
                                        ?>
                                        <span class="status-badge <?php echo $methodClass; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $return['refund_method'] ?? 'cash')); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($return['has_cashbook']): ?>
                                            <span class="status-badge badge-synced" data-bs-toggle="tooltip" title="Cashbook entry exists">
                                                <i class="fas fa-check-circle me-1"></i>Synced
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge badge-missing" data-bs-toggle="tooltip" title="No cashbook entry found">
                                                <i class="fas fa-exclamation-triangle me-1"></i>Missing
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center">
                                            <?php if (!$return['has_cashbook']): ?>
                                                <button class="btn btn-sm btn-warning btn-fix-cashbook"
                                                        data-id="<?php echo $return['id']; ?>"
                                                        data-type="sale"
                                                        data-bs-toggle="tooltip" title="Fix missing cashbook entry">
                                                    <i class="fas fa-sync-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                            <a href="sale_return_view.php?id=<?php echo $return['id']; ?>"
                                               class="btn btn-sm btn-outline-primary"
                                               data-bs-toggle="tooltip" title="View details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="empty-state">
                                            <i class="fas fa-search d-block"></i>
                                            <h6 class="mt-2 mb-1">No returns found</h6>
                                            <p class="small mb-0">Try adjusting your filters or date range.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if (($returns['totalPages'] ?? 1) > 1): ?>
                <div class="d-flex justify-content-between align-items-center p-3" style="border-top:1px solid var(--pharmacy-border,#e9ecef);">
                    <div class="text-muted small">
                        Showing <strong><?php echo count($returns['data']); ?></strong> of
                        <strong><?php echo $returns['total']; ?></strong> returns
                    </div>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $returns['totalPages']; $i++): ?>
                                <?php if ($i == 1 || $i == $returns['totalPages'] || abs($i - $page) <= 2): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php elseif (abs($i - $page) == 3): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
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
</div>

<!-- Toast container -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1100;" id="toastContainer"></div>

<script>
// Toast helper
function showToast(message, type = 'success') {
    let bg = type === 'success' ? 'bg-success' : (type === 'danger' ? 'bg-danger' : 'bg-warning');
    let html = `<div class="toast align-items-center text-white ${bg} border-0" role="alert" data-bs-autohide="true" data-bs-delay="4500">
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>`;
    $('#toastContainer').append(html);
    let el = $('#toastContainer .toast').last();
    new bootstrap.Toast(el).show();
    el.on('hidden.bs.toast', function() { $(this).remove(); });
}

// Fix cashbook entry
$(document).on('click', '.btn-fix-cashbook', function () {
    let $btn     = $(this);
    let returnId = $btn.data('id');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

    $.ajax({
        url    : window.location.href,
        method : 'POST',
        data   : { action: 'fix_cashbook', return_id: returnId, return_type: 'sale' },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                showToast('<i class="fas fa-check-circle me-1"></i>' + res.message, 'success');
                // Update cashbook cell
                $btn.closest('tr').find('.badge-missing')
                    .removeClass('badge-missing')
                    .addClass('badge-synced')
                    .html('<i class="fas fa-check-circle me-1"></i>Synced');
                $btn.remove();
            } else {
                showToast(res.message, 'danger');
                $btn.prop('disabled', false).html('<i class="fas fa-sync-alt"></i>');
            }
        },
        error: function () {
            showToast('Server error. Please try again.', 'danger');
            $btn.prop('disabled', false).html('<i class="fas fa-sync-alt"></i>');
        }
    });
});

// Init tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));

// Auto-dismiss alerts
document.querySelectorAll('.alert:not(.alert-permanent)').forEach(el => {
    setTimeout(() => {
        const a = bootstrap.Alert.getOrCreateInstance(el);
        if (a) a.close();
    }, 5000);
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    UniversalTable.init('saleReturnTable', {
        title      : 'Sale Returns',
        pageSize   : 25,
        excludeCols: [9]
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
