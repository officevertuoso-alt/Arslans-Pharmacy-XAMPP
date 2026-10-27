<?php
/**
 * purchases/list.php — Purchase History with Returns, Net Amounts, No N+1
 *
 * FIXES (original, preserved as-is):
 * 1. Serial number (#) column added
 * 2. Invoice number now uses purchase_invoice_no (e.g. PUR-20260521-0001) consistently
 * 3. Invoice search now works — searches purchase_invoice_no directly
 * 4. Summary totals account for returns correctly
 * 5. Return button links to purchase_return.php?invoice_no= (consistent with view.php)
 *
 * STEP 10 UI CHANGES:
 * - CSS variables throughout (var(--pharmacy-*)) — no hardcoded colors
 * - .table-card wrapper with hover effects
 * - .filter-bar styled filter section
 * - .status-badge with .badge-paid / .badge-partial / .badge-pending classes
 * - Summary stat cards using design system stat-card style
 * - AJAX filter update (date/status change refreshes table without full reload)
 * - Smooth transitions on all interactive elements
 * - Proper breadcrumb: Dashboard > Purchases > Purchase List
 * - All existing PHP logic, queries, pagination 100% preserved
 */

require_once '../config/config.php';
require_once '../includes/purchase_functions.php';
require_once '../includes/return_functions.php';

requirePharmacist();

$page_title   = 'Purchase History';
$page         = isset($_GET['page'])      ? (int)$_GET['page']           : 1;
$search       = isset($_GET['search'])    ? sanitize($_GET['search'])     : '';
$statusFilter = isset($_GET['status'])    ? sanitize($_GET['status'])     : '';
$fromDate     = isset($_GET['from_date']) ? sanitize($_GET['from_date'])  : '';
$toDate       = isset($_GET['to_date'])   ? sanitize($_GET['to_date'])    : '';

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where  = "1=1";
$params = [];
if (!empty($search)) {
    $where .= " AND (p.purchase_invoice_no LIKE :search OR s.supplier_name LIKE :search)";
    $params[':search'] = "%$search%";
}
if (!empty($fromDate)) {
    $where .= " AND DATE(p.purchase_date) >= :from";
    $params[':from'] = $fromDate;
}
if (!empty($toDate)) {
    $where .= " AND DATE(p.purchase_date) <= :to";
    $params[':to'] = $toDate;
}
if (!empty($statusFilter)) {
    $where .= " AND p.payment_status = :status";
    $params[':status'] = $statusFilter;
}

// ── Main query ────────────────────────────────────────────────────────────────
$offset    = ($page - 1) * 20;
$purchases = _pdoAll(
    "SELECT p.*, s.supplier_name,
            COALESCE((
                SELECT SUM(total_amount) FROM purchase_returns WHERE purchase_id = p.id
            ), 0) AS total_returned,
            (p.total - COALESCE((
                SELECT SUM(total_amount) FROM purchase_returns WHERE purchase_id = p.id
            ), 0)) AS net_amount
     FROM purchases p
     JOIN suppliers s ON s.id = p.supplier_id
     WHERE $where
     ORDER BY p.purchase_date DESC, p.id DESC
     LIMIT $offset, 20",
    $params
);

// ── Count total ───────────────────────────────────────────────────────────────
$countRow   = _pdoOne("SELECT COUNT(*) AS total FROM purchases p JOIN suppliers s ON s.id = p.supplier_id WHERE $where", $params);
$total      = (int)($countRow['total'] ?? 0);
$totalPages = (int)ceil($total / 20);

// ── Bulk item counts ──────────────────────────────────────────────────────────
$itemCounts = [];
if (!empty($purchases)) {
    $purchaseIds       = array_column($purchases, 'id');
    $namedPlaceholders = [];
    $idParams          = [];
    foreach ($purchaseIds as $i => $pid) {
        $key                   = ':id' . $i;
        $namedPlaceholders[]   = $key;
        $idParams[$key]        = $pid;
    }
    $items = _pdoAll(
        "SELECT purchase_id, COUNT(*) AS item_count
         FROM purchase_items
         WHERE purchase_id IN (" . implode(',', $namedPlaceholders) . ")
         GROUP BY purchase_id",
        $idParams
    );
    foreach ($items as $it) {
        $itemCounts[$it['purchase_id']] = $it['item_count'];
    }
}

// ── Summary totals ────────────────────────────────────────────────────────────
$summaryParams = $params;
$summary = _pdoOne(
    "SELECT
        COALESCE(SUM(p.total), 0) AS total_gross,
        COALESCE(SUM((SELECT SUM(r.total_amount) FROM purchase_returns r WHERE r.purchase_id = p.id)), 0) AS total_returns,
        COALESCE(SUM(p.total - COALESCE((SELECT SUM(r.total_amount) FROM purchase_returns r WHERE r.purchase_id = p.id), 0)), 0) AS total_net,
        COALESCE(SUM(p.paid_amount), 0) AS total_paid,
        COALESCE(SUM(p.total - COALESCE((SELECT SUM(r.total_amount) FROM purchase_returns r WHERE r.purchase_id = p.id), 0) - p.paid_amount), 0) AS total_due
     FROM purchases p
     JOIN suppliers s ON s.id = p.supplier_id
     WHERE $where",
    $summaryParams
);
$summary                  = $summary ?? [];
$summary['total_gross']   = $summary['total_gross']   ?? 0;
$summary['total_returns'] = $summary['total_returns'] ?? 0;
$summary['total_net']     = $summary['total_net']     ?? 0;
$summary['total_paid']    = $summary['total_paid']    ?? 0;
$summary['total_due']     = $summary['total_due']     ?? 0;

// ── Row number offset for serial display ─────────────────────────────────────
$serialStart = ($page - 1) * 20 + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    <style>
        /* ═══════════════════════════════════════════════════════
           purchases/list.php — Page-scoped styles
           All colors via CSS variables — dark mode safe
        ═══════════════════════════════════════════════════════ */

        /* ── Page fade-in ────────────────────────────────────── */
        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .main-content { animation: fadeInPage 0.28s ease; }

        /* ── Layout ──────────────────────────────────────────── */
        .main-content {
            margin-left: var(--sb-w, 260px);
            padding: 24px 28px;
            transition: margin-left 0.3s ease;
        }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991px) { .main-content { margin-left: 0 !important; } }

        /* ── Stat cards ──────────────────────────────────────── */
        .stat-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: var(--card-border-radius, 12px);
            padding: 18px 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 28px rgba(0,0,0,.13);
        }
        .stat-card .stat-label {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 60%, transparent);
            margin-bottom: 6px;
        }
        .stat-card .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--pharmacy-heading, #111827);
            line-height: 1.2;
        }
        .stat-card .stat-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 2rem;
            opacity: 0.1;
            color: var(--pharmacy-primary, #0d6efd);
        }
        .stat-card.accent-danger  { border-left: 4px solid var(--pharmacy-danger,  #dc3545); }
        .stat-card.accent-success { border-left: 4px solid var(--pharmacy-success, #198754); }
        .stat-card.accent-primary { border-left: 4px solid var(--pharmacy-primary, #0d6efd); }
        .stat-card.accent-warning { border-left: 4px solid var(--pharmacy-warning, #fd7e14); }
        .stat-card.accent-info    { border-left: 4px solid var(--pharmacy-accent,  #0dcaf0); }
        .stat-value.text-danger-v  { color: var(--pharmacy-danger,  #dc3545) !important; }
        .stat-value.text-success-v { color: var(--pharmacy-success, #198754) !important; }
        .stat-value.text-warning-v { color: var(--pharmacy-warning, #fd7e14) !important; }

        /* ── Filter bar ──────────────────────────────────────── */
        .filter-bar {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 18px;
            box-shadow: 0 1px 6px rgba(0,0,0,.05);
        }
        .filter-bar .form-label {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 65%, transparent);
            margin-bottom: 4px;
        }
        .filter-bar .form-control,
        .filter-bar .form-select {
            border-color: var(--pharmacy-border, #e5e7eb);
            background: var(--pharmacy-input-bg, #fff);
            color: var(--pharmacy-text, #374151);
            font-size: 0.85rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .filter-bar .form-control:focus,
        .filter-bar .form-select:focus {
            border-color: var(--pharmacy-primary, #0d6efd);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 15%, transparent);
        }

        /* ── Status pills (filter tabs, not badges) ──────────── */
        .filter-pill {
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            background: var(--pharmacy-card-bg, #fff);
            color: var(--pharmacy-text, #374151);
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            display: inline-block;
        }
        .filter-pill.active,
        .filter-pill:hover {
            background: var(--pharmacy-primary, #0d6efd);
            color: #fff;
            border-color: var(--pharmacy-primary, #0d6efd);
        }

        /* ── Table card wrapper ──────────────────────────────── */
        .table-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: var(--card-border-radius, 12px);
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
            margin-bottom: 20px;
        }
        .table-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            background: var(--pharmacy-table-th, #f8fafc);
            border-bottom: 2px solid var(--pharmacy-border, #e5e7eb);
        }
        .table-card-header .table-title {
            font-weight: 700;
            font-size: 0.88rem;
            color: var(--pharmacy-heading, #111827);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .table-card-header .record-count {
            font-size: 0.78rem;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 60%, transparent);
        }

        /* ── Table styles ────────────────────────────────────── */
        .table th {
            background: var(--pharmacy-table-th, #f8fafc) !important;
            color: var(--pharmacy-heading, #111827) !important;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            padding: 11px 14px;
            border-bottom: 2px solid var(--pharmacy-border, #e5e7eb) !important;
            white-space: nowrap;
        }
        .table td {
            padding: 11px 14px;
            vertical-align: middle;
            color: var(--pharmacy-text, #374151) !important;
            border-color: var(--pharmacy-border, #e5e7eb) !important;
            font-size: 0.875rem;
        }
        .table tbody tr {
            transition: background 0.15s ease;
        }
        .table-hover tbody tr:hover td {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 5%, var(--pharmacy-card-bg, #fff)) !important;
        }
        .table tbody tr:not(:last-child) td {
            border-bottom: 1px solid var(--pharmacy-border, #e5e7eb) !important;
        }

        /* ── Invoice number ──────────────────────────────────── */
        .invoice-no {
            font-family: 'Courier New', monospace;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--pharmacy-primary, #0d6efd) !important;
            text-decoration: none;
            transition: color 0.15s;
        }
        .invoice-no:hover { text-decoration: underline; }

        /* ── Status badges ───────────────────────────────────── */
        .status-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
            display: inline-block;
            white-space: nowrap;
        }
        .badge-paid {
            background: var(--pharmacy-badge-instock-bg, #d1fae5);
            color: var(--pharmacy-badge-instock-text, #065f46);
        }
        .badge-partial {
            background: var(--pharmacy-badge-expiry-bg, #fff3cd);
            color: var(--pharmacy-badge-expiry-text, #856404);
        }
        .badge-pending {
            background: var(--pharmacy-badge-oos-bg, #fee2e2);
            color: var(--pharmacy-badge-oos-text, #991b1b);
        }

        /* ── Return value indicator ──────────────────────────── */
        .return-amount {
            color: var(--pharmacy-danger, #dc3545);
            font-size: 0.8rem;
        }
        .return-nil {
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 35%, transparent);
        }

        /* ── Action buttons ──────────────────────────────────── */
        .btn-action {
            padding: 4px 8px;
            font-size: 0.78rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        .btn-action:hover { transform: translateY(-1px); }

        /* ── Pagination ──────────────────────────────────────── */
        .pagination .page-link {
            color: var(--pharmacy-primary, #0d6efd);
            border-color: var(--pharmacy-border, #e5e7eb);
            background: var(--pharmacy-card-bg, #fff);
            font-size: 0.82rem;
            padding: 5px 10px;
            transition: all 0.15s ease;
        }
        .pagination .page-item.active .page-link {
            background: var(--pharmacy-primary, #0d6efd);
            border-color: var(--pharmacy-primary, #0d6efd);
            color: #fff;
        }
        .pagination .page-link:hover {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 10%, var(--pharmacy-card-bg, #fff));
        }

        /* ── AJAX loading overlay ────────────────────────────── */
        #table-container {
            position: relative;
            transition: opacity 0.2s ease;
        }
        #table-container.loading { opacity: 0.45; pointer-events: none; }
        .table-spinner {
            display: none;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 10;
        }
        #table-container.loading .table-spinner { display: block; }

        /* ── Page heading ────────────────────────────────────── */
        .page-heading h4 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--pharmacy-heading, #111827);
            margin-bottom: 3px;
        }
        .page-heading p {
            font-size: 0.82rem;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 60%, transparent);
        }

        /* ── Empty state ─────────────────────────────────────── */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 50%, transparent);
        }
        .empty-state i { font-size: 2.5rem; margin-bottom: 12px; display: block; opacity: 0.4; }
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

    <!-- ── Page Header ──────────────────────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="page-heading">
            <h4><i class="fas fa-shopping-cart me-2 text-primary" style="opacity:.7"></i>Purchase History</h4>
            <p class="mb-0">All purchase orders &amp; supplier invoices</p>
        </div>
        <a href="add.php" class="btn btn-success btn-sm px-3"
           data-requires-perm="purchases.add"
           style="transition:all .2s ease">
            <i class="fas fa-plus-circle me-2"></i>New Purchase
        </a>
    </div>

    <!-- ── Summary Stat Cards ───────────────────────────────────────── -->
    <div id="stat-cards-area">
    <div class="row g-3 mb-4">

        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card accent-primary">
                <div class="stat-label">Gross Total</div>
                <div class="stat-value">Rs <?php echo number_format($summary['total_gross'], 0); ?></div>
                <i class="fas fa-coins stat-icon"></i>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card accent-danger">
                <div class="stat-label">Returns</div>
                <div class="stat-value text-danger-v">Rs <?php echo number_format($summary['total_returns'], 0); ?></div>
                <i class="fas fa-undo stat-icon"></i>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card accent-success">
                <div class="stat-label">Net Total</div>
                <div class="stat-value text-success-v">Rs <?php echo number_format($summary['total_net'], 0); ?></div>
                <i class="fas fa-check-circle stat-icon"></i>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card accent-info">
                <div class="stat-label">Paid</div>
                <div class="stat-value">Rs <?php echo number_format($summary['total_paid'], 0); ?></div>
                <i class="fas fa-money-bill-wave stat-icon"></i>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card accent-warning">
                <div class="stat-label">Due</div>
                <div class="stat-value text-warning-v">Rs <?php echo number_format($summary['total_due'], 0); ?></div>
                <i class="fas fa-exclamation-circle stat-icon"></i>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card accent-primary">
                <div class="stat-label">Records</div>
                <div class="stat-value"><?php echo number_format($total); ?></div>
                <i class="fas fa-list stat-icon"></i>
            </div>
        </div>

    </div><!-- /row stat cards -->
    </div><!-- /stat-cards-area -->

    <!-- ── Filter Bar ───────────────────────────────────────────────── -->
    <div class="filter-bar">
        <form method="GET" id="filterForm" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text" style="background:var(--pharmacy-table-th,#f8fafc);border-color:var(--pharmacy-border,#e5e7eb)">
                        <i class="fas fa-search" style="color:var(--pharmacy-text,.5)"></i>
                    </span>
                    <input type="text" name="search" id="searchInput"
                           class="form-control form-control-sm"
                           placeholder="Invoice # or supplier name…"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="from_date" id="fromDate"
                       class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($fromDate); ?>">
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="to_date" id="toDate"
                       class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($toDate); ?>">
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label">Status</label>
                <select name="status" id="statusFilter" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="paid"    <?php echo $statusFilter === 'paid'    ? 'selected' : ''; ?>>Paid</option>
                    <option value="partial" <?php echo $statusFilter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                </select>
            </div>

            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                    <i class="fas fa-filter me-1"></i>Filter
                </button>
                <a href="list.php" class="btn btn-outline-secondary btn-sm px-3" title="Reset filters">
                    <i class="fas fa-sync-alt"></i>
                </a>
            </div>
        </form>
    </div><!-- /filter-bar -->

    <!-- ── Status Quick-Filter Pills ────────────────────────────────── -->
    <div class="d-flex gap-2 mb-3 flex-wrap">
        <?php
        $allCount     = _pdoOne("SELECT COUNT(*) AS c FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE $where", $params)['c'] ?? $total;
        $paidCount    = _pdoOne("SELECT COUNT(*) AS c FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE $where AND p.payment_status='paid'",    $params)['c'] ?? 0;
        $partialCount = _pdoOne("SELECT COUNT(*) AS c FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE $where AND p.payment_status='partial'", $params)['c'] ?? 0;
        $pendingCount = _pdoOne("SELECT COUNT(*) AS c FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE $where AND p.payment_status='pending'", $params)['c'] ?? 0;

        $pillBase = '?search=' . urlencode($search) . '&from_date=' . urlencode($fromDate) . '&to_date=' . urlencode($toDate);
        $pills = [
            ['label' => 'All',     'status' => '',         'count' => $total,        'icon' => 'fa-list'],
            ['label' => 'Paid',    'status' => 'paid',     'count' => $paidCount,    'icon' => 'fa-check-circle'],
            ['label' => 'Partial', 'status' => 'partial',  'count' => $partialCount, 'icon' => 'fa-adjust'],
            ['label' => 'Pending', 'status' => 'pending',  'count' => $pendingCount, 'icon' => 'fa-clock'],
        ];
        foreach ($pills as $pill):
            $active = ($statusFilter === $pill['status']) ? 'active' : '';
        ?>
        <a href="<?php echo $pillBase . '&status=' . urlencode($pill['status']); ?>"
           class="filter-pill <?php echo $active; ?>">
            <i class="fas <?php echo $pill['icon']; ?> me-1"></i>
            <?php echo $pill['label']; ?>
            <span class="badge rounded-pill ms-1"
                  style="background:rgba(<?php echo $active ? '255,255,255,.25' : '0,0,0,.1'; ?>);color:inherit;font-size:.68rem">
                <?php echo $pill['count']; ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- ── Purchases Table ──────────────────────────────────────────── -->
    <div id="table-container">
        <div class="table-spinner">
            <div class="spinner-border text-primary" role="status" style="width:2rem;height:2rem">
                <span class="visually-hidden">Loading…</span>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-header">
                <span class="table-title"><i class="fas fa-table me-2"></i>Purchase Records</span>
                <span class="record-count">
                    <?php if ($total > 0): ?>
                        Showing <?php echo $serialStart; ?>–<?php echo min($serialStart + 19, $total); ?> of <?php echo number_format($total); ?>
                    <?php else: ?>
                        No records
                    <?php endif; ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0" id="purchasesTable">
                    <thead>
                        <tr>
                            <th style="width:42px">#</th>
                            <th>Invoice No.</th>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th class="text-center">Items</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Returns</th>
                            <th class="text-end">Net</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Due</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($purchases)): ?>
                        <tr>
                            <td colspan="12">
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    No purchases found.
                                    <?php if (!empty($search)): ?>
                                    <br><small>No results for "<strong><?php echo htmlspecialchars($search); ?></strong>"</small>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php else:
                            $rowNo = $serialStart;
                            foreach ($purchases as $p):
                                $totalReturned = (float)$p['total_returned'];
                                $net           = (float)$p['net_amount'];
                                $paid          = (float)$p['paid_amount'];
                                $due           = max(0, $net - $paid);
                                $itemCount     = $itemCounts[$p['id']] ?? 0;
                                $badgeClass    = match($p['payment_status']) {
                                    'paid'    => 'badge-paid',
                                    'partial' => 'badge-partial',
                                    default   => 'badge-pending'
                                };
                        ?>
                        <tr>
                            <!-- Serial Number -->
                            <td class="text-muted small"><?php echo $rowNo++; ?></td>

                            <!-- Invoice Number -->
                            <td>
                                <a href="view.php?id=<?php echo $p['id']; ?>"
                                   class="invoice-no">
                                    <?php echo htmlspecialchars($p['purchase_invoice_no']); ?>
                                </a>
                            </td>

                            <!-- Date -->
                            <td style="white-space:nowrap;font-size:.82rem">
                                <?php echo date('d M Y', strtotime($p['purchase_date'])); ?>
                            </td>

                            <!-- Supplier -->
                            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?php echo htmlspecialchars($p['supplier_name']); ?>
                            </td>

                            <!-- Items -->
                            <td class="text-center">
                                <span class="badge rounded-pill"
                                      style="background:color-mix(in srgb,var(--pharmacy-primary,.15) 15%,transparent);color:var(--pharmacy-primary,#0d6efd);font-size:.72rem;font-weight:700">
                                    <?php echo $itemCount; ?>
                                </span>
                            </td>

                            <!-- Total -->
                            <td class="text-end fw-semibold" style="font-size:.85rem">
                                Rs <?php echo number_format($p['total'], 2); ?>
                            </td>

                            <!-- Returns -->
                            <td class="text-end">
                                <?php if ($totalReturned > 0): ?>
                                    <span class="return-amount">
                                        &minus; Rs <?php echo number_format($totalReturned, 2); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="return-nil">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Net -->
                            <td class="text-end fw-bold"
                                style="color:var(--pharmacy-success,#198754);font-size:.875rem">
                                Rs <?php echo number_format($net, 2); ?>
                            </td>

                            <!-- Paid -->
                            <td class="text-end" style="font-size:.85rem">
                                Rs <?php echo number_format($paid, 2); ?>
                            </td>

                            <!-- Due -->
                            <td class="text-end fw-bold" style="font-size:.85rem;
                                color:<?php echo $due > 0 ? 'var(--pharmacy-danger,#dc3545)' : 'color-mix(in srgb,var(--pharmacy-text,#374151) 40%,transparent)'; ?>">
                                Rs <?php echo number_format($due, 2); ?>
                            </td>

                            <!-- Status -->
                            <td class="text-center">
                                <span class="status-badge <?php echo $badgeClass; ?>">
                                    <?php echo ucfirst($p['payment_status']); ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center flex-nowrap">
                                    <!-- View -->
                                    <a href="view.php?id=<?php echo $p['id']; ?>"
                                       class="btn btn-sm btn-outline-primary btn-action"
                                       data-bs-toggle="tooltip" title="View Invoice">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <!-- Edit -->
                                    <a href="edit.php?id=<?php echo $p['id']; ?>"
                                       class="btn btn-sm btn-outline-secondary btn-action"
                                       data-bs-toggle="tooltip" title="Edit Purchase">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <!-- Pay (only if due > 0) -->
                                    <?php if ($due > 0): ?>
                                    <a href="../suppliers/pay.php?supplier_id=<?php echo $p['supplier_id']; ?>&purchase_id=<?php echo $p['id']; ?>"
                                       class="btn btn-sm btn-outline-success btn-action"
                                       data-requires-perm="suppliers.pay"
                                       data-bs-toggle="tooltip" title="Pay Supplier">
                                        <i class="fas fa-money-bill"></i>
                                    </a>
                                    <?php endif; ?>
                                    <!-- Return -->
                                    <a href="../returns/purchase_return.php?invoice_no=<?php echo urlencode($p['purchase_invoice_no']); ?>"
                                       class="btn btn-sm btn-outline-warning btn-action"
                                       data-requires-perm="purchases.return"
                                       data-bs-toggle="tooltip" title="Return to Supplier">
                                        <i class="fas fa-exchange-alt"></i>
                                    </a>
                                    <!-- Void (admin only, only if no payments made) -->
                                    <?php if (function_exists('isAdmin') && isAdmin() && (float)$p['paid_amount'] === 0.0 && empty($p['voided'])): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-danger btn-action void-purchase-btn"
                                            data-id="<?php echo (int)$p['id']; ?>"
                                            data-invoice="<?php echo htmlspecialchars($p['purchase_invoice_no'], ENT_QUOTES); ?>"
                                            data-csrf="<?php echo generateCSRFToken(); ?>"
                                            data-bs-toggle="tooltip"
                                            title="Void Purchase (Admin Only)">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div><!-- /table-responsive -->
        </div><!-- /table-card -->
    </div><!-- /#table-container -->

    <!-- ── Pagination ───────────────────────────────────────────────── -->
    <?php if ($totalPages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="text-muted" style="font-size:.8rem">
            Showing <strong><?php echo $serialStart; ?></strong>–<strong><?php echo min($serialStart + 19, $total); ?></strong>
            of <strong><?php echo number_format($total); ?></strong> purchases
        </div>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&from_date=<?php echo $fromDate; ?>&to_date=<?php echo $toDate; ?>">
                        &laquo;
                    </a>
                </li>
                <?php endif; ?>
                <?php
                $startP = max(1, $page - 3);
                $endP   = min($totalPages, $page + 3);
                for ($i = $startP; $i <= $endP; $i++):
                ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link ajax-page-link"
                       href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&from_date=<?php echo $fromDate; ?>&to_date=<?php echo $toDate; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&from_date=<?php echo $fromDate; ?>&to_date=<?php echo $toDate; ?>">
                        &raquo;
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>

</div><!-- /container-fluid -->
</div><!-- /main-content -->


<script>
(function () {
    'use strict';

    /* ── Tooltip init ─────────────────────────────────────────────── */
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el, { trigger: 'hover' });
    });

    /* ── AJAX filter update — date & status change reload table ───── */
    var filterForm   = document.getElementById('filterForm');
    var tableContainer = document.getElementById('table-container');

    function ajaxUpdateTable() {
        if (!filterForm || !tableContainer) return;
        var params = new URLSearchParams(new FormData(filterForm));
        params.set('page', '1'); // Reset to first page on filter change

        tableContainer.classList.add('loading');

        fetch('?' + params.toString(), { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newContainer = doc.getElementById('table-container');
                if (newContainer) {
                    tableContainer.innerHTML = newContainer.innerHTML;
                    // Re-init tooltips after DOM swap
                    tableContainer.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                        new bootstrap.Tooltip(el, { trigger: 'hover' });
                    });
                }
                tableContainer.classList.remove('loading');
                // Update URL without full reload
                history.replaceState(null, '', '?' + params.toString());
            })
            .catch(function () {
                tableContainer.classList.remove('loading');
            });
    }

    /* Date & status dropdowns trigger AJAX refresh */
    ['fromDate', 'toDate', 'statusFilter'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', ajaxUpdateTable);
    });

    /* Search with 400ms debounce */
    var searchInput = document.getElementById('searchInput');
    var searchTimer;
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(ajaxUpdateTable, 400);
        });
    }

    /* ── Prevent filter form full-page submit if JS is active ────── */
    if (filterForm) {
        filterForm.addEventListener('submit', function (e) {
            e.preventDefault();
            ajaxUpdateTable();
        });
    }

    /* AJAX Pagination: intercept page-link clicks */
    document.addEventListener('click', function(e) {
        var link = e.target.closest('.ajax-page-link');
        if (!link) return;
        e.preventDefault();
        var url    = new URL(link.href, window.location.href);
        var params = url.searchParams;
        if (filterForm) {
            new URLSearchParams(new FormData(filterForm)).forEach(function(v, k) {
                if (k !== 'page') params.set(k, v);
            });
        }
        tableContainer.classList.add('loading');
        fetch('?' + params.toString(), { credentials: 'same-origin' })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newContainer = doc.getElementById('table-container');
                if (newContainer) {
                    tableContainer.innerHTML = newContainer.innerHTML;
                    tableContainer.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                        new bootstrap.Tooltip(el, { trigger: 'hover' });
                    });
                }
                var newStats = doc.getElementById('stat-cards-area');
                var oldStats = document.getElementById('stat-cards-area');
                if (newStats && oldStats) oldStats.innerHTML = newStats.innerHTML;
                tableContainer.classList.remove('loading');
                history.replaceState(null, '', '?' + params.toString());
                window.scrollTo({ top: 0, behavior: 'smooth' });
            })
            .catch(function() { tableContainer.classList.remove('loading'); });
    });


})();
</script>

<script>
/* ── Void Purchase Handler ─────────────────────────────────────── */
(function () {
    'use strict';

    // Void confirm modal — inject once
    var modalHtml = [
        '<div class="modal fade" id="voidPurchaseModal" tabindex="-1" aria-hidden="true">',
        '  <div class="modal-dialog modal-dialog-centered">',
        '    <div class="modal-content">',
        '      <div class="modal-header bg-danger text-white">',
        '        <h5 class="modal-title"><i class="fas fa-ban me-2"></i>Void Purchase</h5>',
        '        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>',
        '      </div>',
        '      <div class="modal-body">',
        '        <div class="alert alert-warning mb-3">',
        '          <i class="fas fa-exclamation-triangle me-2"></i>',
        '          <strong>Warning:</strong> This action cannot be undone.',
        '        </div>',
        '        <p>Voiding purchase <strong id="voidInvoiceLabel"></strong> will:</p>',
        '        <ul class="small">',
        '          <li>Remove all batches added by this purchase</li>',
        '          <li>Reduce supplier balance by purchase total</li>',
        '          <li>Void related cashbook entries</li>',
        '          <li>Recalculate product stock quantities</li>',
        '        </ul>',
        '        <p class="text-danger small fw-semibold">Only allowed if NO payments have been made.</p>',
        '        <input type="hidden" id="voidPurchaseId">',
        '        <input type="hidden" id="voidCsrfToken">',
        '      </div>',
        '      <div class="modal-footer">',
        '        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>',
        '        <button type="button" class="btn btn-danger" id="confirmVoidBtn">',
        '          <i class="fas fa-ban me-1"></i> Confirm Void',
        '        </button>',
        '      </div>',
        '    </div>',
        '  </div>',
        '</div>'
    ].join('');

    document.body.insertAdjacentHTML('beforeend', modalHtml);

    var voidModal = null;

    // Open modal on void button click
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.void-purchase-btn');
        if (!btn) return;

        document.getElementById('voidInvoiceLabel').textContent = btn.dataset.invoice;
        document.getElementById('voidPurchaseId').value = btn.dataset.id;
        document.getElementById('voidCsrfToken').value = btn.dataset.csrf;

        var modalEl = document.getElementById('voidPurchaseModal');
        if (window.bootstrap && bootstrap.Modal) {
            voidModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            voidModal.show();
        } else {
            // Fallback if Bootstrap not loaded yet
            if (confirm('Void purchase ' + btn.dataset.invoice + '? This cannot be undone.')) {
                doVoid(btn.dataset.id, btn.dataset.csrf, btn);
            }
        }
    });

    // Confirm void
    var confirmBtn = document.getElementById('confirmVoidBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            var id   = document.getElementById('voidPurchaseId').value;
            var csrf = document.getElementById('voidCsrfToken').value;
            doVoid(id, csrf, confirmBtn);
        });
    }

    function doVoid(purchaseId, csrfToken, triggerEl) {
        if (triggerEl) {
            triggerEl.disabled = true;
            triggerEl.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Voiding…';
        }

        fetch('delete.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    'purchase_id=' + encodeURIComponent(purchaseId) +
                     '&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (voidModal) voidModal.hide();

            // Show result toast
            var toastBg  = data.success ? '#059669' : '#dc2626';
            var toastMsg = data.message || (data.success ? 'Purchase voided.' : 'Void failed.');
            showToastMsg(toastMsg, toastBg);

            if (data.success) {
                // Fade out the row
                setTimeout(function () {
                    var row = document.querySelector('.void-purchase-btn[data-id="' + purchaseId + '"]');
                    if (row) {
                        var tr = row.closest('tr');
                        if (tr) {
                            tr.style.transition = 'opacity .4s ease';
                            tr.style.opacity    = '0';
                            setTimeout(function () { tr.remove(); }, 450);
                        }
                    }
                }, 800);
            } else {
                if (triggerEl) {
                    triggerEl.disabled = false;
                    triggerEl.innerHTML = '<i class="fas fa-ban me-1"></i> Confirm Void';
                }
            }
        })
        .catch(function (err) {
            if (voidModal) voidModal.hide();
            showToastMsg('Network error. Please try again.', '#dc2626');
            if (triggerEl) {
                triggerEl.disabled = false;
                triggerEl.innerHTML = '<i class="fas fa-ban me-1"></i> Confirm Void';
            }
        });
    }

    function showToastMsg(msg, bg) {
        var t = document.createElement('div');
        t.style.cssText = [
            'position:fixed', 'top:20px', 'right:20px', 'z-index:9999',
            'background:' + bg, 'color:#fff', 'padding:12px 20px',
            'border-radius:10px', 'font-size:.82rem', 'font-weight:700',
            'box-shadow:0 6px 28px rgba(0,0,0,.25)',
            'animation:slideInRight .3s ease',
            'min-width:220px'
        ].join(';');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () {
            t.style.opacity = '0';
            t.style.transition = 'opacity .4s ease';
            setTimeout(function () { t.remove(); }, 450);
        }, 3500);
    }

}());
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    UniversalTable.init('purchasesTable', {
        title      : 'Purchases List',
        pageSize   : 25,
        excludeCols: [7]
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
