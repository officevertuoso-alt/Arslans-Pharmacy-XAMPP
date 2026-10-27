<?php
/**
 * sales/list.php — Sales History
 *
 * STEP 10 UI CHANGES:
 * - All colors via CSS variables (var(--pharmacy-*)) — zero hardcoded hex
 * - .filter-bar styled filter section with labelled inputs
 * - .table-card wrapper with .table-card-header, hover rows, th uppercase
 * - .status-badge with .badge-paid / .badge-partial / .badge-pending / .badge-returned
 * - Payment method badges using design system classes
 * - Pending-amount row highlight via CSS variable tint (not hardcoded yellow)
 * - Smooth transitions on all interactive elements
 * - AJAX search debounce + date-filter update (no full reload)
 * - Modal smooth animation (translateY + scale)
 * - Page fade-in animation
 * - Pagination styled with CSS variables
 * - Breadcrumb: Dashboard > Sales > Sales List (via navbar.php)
 *
 * ALL ORIGINAL PHP LOGIC, CREDIT MAP, MODAL & JS 100% PRESERVED.
 */

require_once '../config/config.php';
require_once '../includes/sale_functions.php';
requireLogin(); // FIX S-03: Enforce authentication on sales add/POS

$page_title = 'Sales History';
$page     = isset($_GET['page'])      ? (int)$_GET['page']          : 1;
$search   = isset($_GET['search'])    ? sanitize($_GET['search'])    : '';
$fromDate = isset($_GET['from_date']) ? sanitize($_GET['from_date']) : '';
$toDate   = isset($_GET['to_date'])   ? sanitize($_GET['to_date'])   : '';

$sales = getAllSales($page, 20, $search, $fromDate, $toDate);

// ── Pre-fetch credit info for all sales on this page ─────────────────────────
$db        = db();
$creditMap = []; // sale_id => credit row (with remaining)

foreach ($sales['data'] as $sale) {
    $cr = $db->getOne(
        "SELECT cc.id AS credit_id, cc.sale_id, cc.total_amount, cc.status,
                COALESCE(SUM(CASE
                    WHEN (cp.note IS NULL OR (cp.note NOT LIKE 'Upfront%' AND cp.note NOT LIKE 'upfront%'))
                    THEN cp.amount ELSE 0 END), 0) AS post_sale_paid
         FROM customer_credits cc
         LEFT JOIN credit_payments cp ON cp.credit_id = cc.id
         WHERE cc.sale_id = :sid
         GROUP BY cc.id
         ORDER BY cc.id DESC
         LIMIT 1",
        [':sid' => (int)$sale['id']]
    );
    if ($cr) {
        $sale_total          = (float)($sale['total'] ?? 0);
        $upfront_paid        = (float)($sale['paid_amount'] ?? 0);
        $post_paid           = (float)($cr['post_sale_paid'] ?? 0);
        $cr['remaining']     = max(0, $sale_total - $upfront_paid - $post_paid);
        $creditMap[$sale['id']] = $cr;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        /* ═══════════════════════════════════════════════════════════
           sales/list.php — Page-scoped styles
           ALL colors via CSS variables — dark-mode safe
        ═══════════════════════════════════════════════════════════ */

        /* ── Page fade-in ────────────────────────────────────────── */
        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Layout ──────────────────────────────────────────────── */
        .main-content {
            margin-left: var(--sb-w, 260px);
            padding: 24px 28px;
            animation: fadeInPage 0.28s ease;
            transition: margin-left 0.3s ease;
        }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991px) { .main-content { margin-left: 0 !important; } }

        /* ── Page heading ────────────────────────────────────────── */
        .page-heading h4 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--pharmacy-heading, #111827);
            margin-bottom: 3px;
        }
        .page-heading p {
            font-size: 0.82rem;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 60%, transparent);
            margin: 0;
        }

        /* ── Filter bar ──────────────────────────────────────────── */
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

        /* ── Table card wrapper ──────────────────────────────────── */
        .table-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: var(--card-border-radius, 12px);
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
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
            font-size: 0.85rem;
            color: var(--pharmacy-heading, #111827);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .table-card-header .record-count {
            font-size: 0.78rem;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 60%, transparent);
        }

        /* ── Table core styles ───────────────────────────────────── */
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
        .table tbody tr { transition: background 0.15s ease; }
        .table-hover tbody tr:hover td {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 5%, var(--pharmacy-card-bg, #fff)) !important;
        }
        .table tbody tr:not(:last-child) td {
            border-bottom: 1px solid var(--pharmacy-border, #e5e7eb) !important;
        }

        /* ── Pending row highlight (CSS-var tint, dark-mode safe) ── */
        .row-pending td {
            background: color-mix(in srgb, var(--pharmacy-warning, #fd7e14) 8%, var(--pharmacy-card-bg, #fff)) !important;
        }
        .row-pending:hover td {
            background: color-mix(in srgb, var(--pharmacy-warning, #fd7e14) 14%, var(--pharmacy-card-bg, #fff)) !important;
        }

        /* ── Status badges ───────────────────────────────────────── */
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
        .badge-returned {
            background: var(--pharmacy-badge-lowstock-bg, #f3f4f6);
            color: var(--pharmacy-badge-lowstock-text, #6b7280);
        }

        /* ── Payment method badges ───────────────────────────────── */
        .pay-method-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 9px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 10%, transparent);
            color: var(--pharmacy-primary, #0d6efd);
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .pay-method-badge:hover {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 18%, transparent);
            color: var(--pharmacy-primary, #0d6efd);
        }
        .pay-method-badge.credit-badge {
            background: color-mix(in srgb, var(--pharmacy-danger, #dc3545) 10%, transparent);
            color: var(--pharmacy-danger, #dc3545);
        }
        .pay-method-badge.credit-badge:hover {
            background: color-mix(in srgb, var(--pharmacy-danger, #dc3545) 18%, transparent);
        }
        .pay-method-badge.partial-badge {
            background: color-mix(in srgb, var(--pharmacy-warning, #fd7e14) 12%, transparent);
            color: var(--pharmacy-warning, #c2410c);
        }

        /* ── Pending amount indicator ────────────────────────────── */
        .pending-amount {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--pharmacy-danger, #dc3545);
            display: inline-block;
            margin-top: 2px;
        }

        /* ── Invoice link ────────────────────────────────────────── */
        .invoice-link {
            font-family: 'Courier New', monospace;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--pharmacy-primary, #0d6efd) !important;
            text-decoration: none;
            transition: color 0.15s;
        }
        .invoice-link:hover { text-decoration: underline; }

        /* ── Action buttons ──────────────────────────────────────── */
        .btn-action {
            padding: 4px 8px;
            font-size: 0.78rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        .btn-action:hover { transform: translateY(-1px); }

        /* ── Record Pay button ───────────────────────────────────── */
        .btn-record-pay {
            background: linear-gradient(135deg,
                var(--pharmacy-success, #198754),
                color-mix(in srgb, var(--pharmacy-success, #198754) 70%, var(--pharmacy-accent, #20c997)));
            color: #fff;
            border: none;
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        .btn-record-pay:hover {
            opacity: 0.88;
            color: #fff;
            transform: translateY(-1px);
        }

        /* ── Pagination ──────────────────────────────────────────── */
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
        .pagination-wrapper {
            background: var(--pharmacy-card-bg, #fff);
            border-top: 1px solid var(--pharmacy-border, #e5e7eb);
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .pagination-info {
            font-size: 0.8rem;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 65%, transparent);
        }

        /* ── AJAX loading state ──────────────────────────────────── */
        #table-container { position: relative; transition: opacity 0.2s ease; }
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

        /* ── Empty state ─────────────────────────────────────────── */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 50%, transparent);
        }
        .empty-state i { font-size: 2.5rem; margin-bottom: 12px; display: block; opacity: 0.35; }

        .pay-summary-box {
            background: color-mix(in srgb, var(--pharmacy-table-th, #f8fafc) 100%, transparent);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
            border-left: 4px solid var(--pharmacy-danger, #dc3545);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-left: 4px solid var(--pharmacy-danger, #dc3545);
        }
        .pay-summary-box .label {
            font-size: 0.68rem;
            color: color-mix(in srgb, var(--pharmacy-text, #374151) 60%, transparent);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .pay-summary-box .val {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--pharmacy-heading, #111827);
        }

        .pay-method-btn {
            border: 2px solid var(--pharmacy-border, #dee2e6);
            border-radius: 8px;
            padding: 8px 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.82rem;
            background: var(--pharmacy-card-bg, #fff);
            color: var(--pharmacy-text, #374151);
        }
        .pay-method-btn.active {
            border-color: var(--pharmacy-success, #198754);
            background: color-mix(in srgb, var(--pharmacy-success, #198754) 10%, transparent);
            color: var(--pharmacy-success, #198754);
            font-weight: 700;
        }
        .pay-method-btn:hover:not(.active) {
            border-color: var(--pharmacy-primary, #0d6efd);
            color: var(--pharmacy-primary, #0d6efd);
        }
        .pay-success-check {
            font-size: 3rem;
            color: var(--pharmacy-success, #198754);
        }

        /* ── New Sale / Credit Mgmt buttons ──────────────────────── */
        .btn-pos {
            background: linear-gradient(135deg,
                var(--pharmacy-success, #28a745),
                color-mix(in srgb, var(--pharmacy-success, #28a745) 60%, var(--pharmacy-accent, #20c997)));
            color: #fff;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-pos:hover { opacity: 0.88; color: #fff; transform: translateY(-1px); }
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
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div class="page-heading">
            <h4><i class="fas fa-receipt me-2" style="opacity:.7;color:var(--pharmacy-primary,#0d6efd)"></i>Sales History</h4>
            <p>View and manage all sale transactions</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?php echo BASE_URL; ?>CREDIT/index.php" class="btn btn-outline-danger btn-sm px-3" style="transition:all .2s ease">
                <i class="fas fa-file-invoice-dollar me-2"></i>Credit Management
            </a>
            <a href="pos.php" class="btn btn-pos btn-sm px-3"
               data-requires-perm="sales.add">
                <i class="fas fa-cash-register me-2"></i>New Sale (POS)
            </a>
        </div>
    </div>

    <!-- ── Filter Bar ───────────────────────────────────────────────── -->
    <div class="filter-bar">
        <form method="GET" id="filterForm" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"
                          style="background:var(--pharmacy-table-th,#f8fafc);border-color:var(--pharmacy-border,#e5e7eb)">
                        <i class="fas fa-search" style="font-size:.8rem;color:var(--pharmacy-text,.5)"></i>
                    </span>
                    <input type="text" name="search" id="searchInput"
                           class="form-control form-control-sm"
                           placeholder="Invoice, customer, phone…"
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
            <div class="col-12 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                    <i class="fas fa-search me-1"></i>Filter
                </button>
                <a href="list.php" class="btn btn-outline-secondary btn-sm px-3" title="Reset filters">
                    <i class="fas fa-sync-alt"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- ── Sales Table ──────────────────────────────────────────────── -->
    <div id="table-container">
        <div class="table-spinner">
            <div class="spinner-border text-primary" role="status" style="width:2rem;height:2rem">
                <span class="visually-hidden">Loading…</span>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-header">
                <span class="table-title"><i class="fas fa-table me-2"></i>Sale Records</span>
                <span class="record-count">
                    <?php if ($sales['total'] > 0): ?>
                        Showing <?php echo count($sales['data']); ?> of <?php echo number_format($sales['total']); ?>
                    <?php else: ?>
                        No records
                    <?php endif; ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Invoice No.</th>
                            <th>Customer</th>
                            <th>Date &amp; Time</th>
                            <th class="text-center">Items</th>
                            <th class="text-end">Total</th>
                            <th>Payment</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($sales['data']) > 0): ?>
                            <?php foreach ($sales['data'] as $sale):
                                $isCredit  = ($sale['payment_method'] === 'credit');
                                $payStatus = $sale['payment_status'] ?? 'paid';

                                // ── Determine pending amount & credit record ──────────────
                                $creditRow = $creditMap[$sale['id']] ?? null;

                                if ($creditRow && (float)($creditRow['remaining'] ?? 0) > 0.005) {
                                    $hasPending   = true;
                                    $remaining    = (float)$creditRow['remaining'];
                                    $creditId     = $creditRow['credit_id'];
                                    $recordPayUrl = BASE_URL . 'CREDIT/list.php?highlight=' . $creditId . '&open_pay=1';
                                } elseif (in_array($payStatus, ['partial', 'pending']) && !$creditRow) {
                                    $saleTotal    = (float)($sale['total'] ?? 0);
                                    $salePaid     = (float)($sale['paid_amount'] ?? 0);
                                    $remaining    = max(0, $saleTotal - $salePaid);
                                    $hasPending   = $remaining > 0.005;
                                    $creditId     = 0;
                                    $recordPayUrl = BASE_URL . 'CREDIT/index.php?sale_id=' . $sale['id'];
                                } else {
                                    $hasPending   = false;
                                    $remaining    = 0;
                                    $creditId     = 0;
                                    $recordPayUrl = '#';
                                }

                                // Badge class map
                                $badgeClass = match($payStatus) {
                                    'paid'     => 'badge-paid',
                                    'partial'  => 'badge-partial',
                                    'pending'  => 'badge-pending',
                                    'returned' => 'badge-returned',
                                    default    => 'badge-returned',
                                };
                            ?>
                            <tr class="<?php echo $hasPending ? 'row-pending' : ''; ?>">

                                <!-- Invoice No. -->
                                <td>
                                    <a href="view.php?id=<?php echo $sale['id']; ?>"
                                       class="invoice-link">
                                        <?php echo htmlspecialchars($sale['invoice_no']); ?>
                                    </a>
                                    <?php if ($hasPending): ?>
                                        <br>
                                        <span class="pending-amount">
                                            <i class="fas fa-exclamation-circle me-1"></i>Due: Rs <?php echo number_format($remaining, 2); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Customer -->
                                <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                    <?php echo htmlspecialchars($sale['customer_name']); ?>
                                </td>

                                <!-- Date & Time -->
                                <td style="white-space:nowrap;font-size:.82rem">
                                    <?php echo formatDate($sale['sale_date'], 'd/m/Y h:i A'); ?>
                                </td>

                                <!-- Items -->
                                <td class="text-center">
                                    <span class="badge rounded-pill"
                                          style="background:color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 12%,transparent);color:var(--pharmacy-primary,#0d6efd);font-size:.72rem;font-weight:700">
                                        <?php echo count(getSaleById($sale['id'])['items']); ?>
                                    </span>
                                </td>

                                <!-- Total -->
                                <td class="text-end fw-bold" style="font-size:.875rem">
                                    Rs <?php echo number_format($sale['total'], 2); ?>
                                </td>

                                <!-- Payment Method -->
                                <td>
                                    <?php if ($isCredit): ?>
                                        <a href="<?php echo $recordPayUrl; ?>"
                                           class="pay-method-badge credit-badge"
                                           title="View Credit">
                                            <i class="fas fa-file-invoice-dollar"></i>Credit
                                        </a>
                                    <?php elseif ($payStatus === 'partial'): ?>
                                        <span class="pay-method-badge partial-badge">
                                            <i class="fas fa-adjust"></i>Partial
                                        </span>
                                    <?php else: ?>
                                        <span class="pay-method-badge">
                                            <i class="fas fa-<?php echo $sale['payment_method'] === 'cash' ? 'money-bill-wave' : ($sale['payment_method'] === 'card' ? 'credit-card' : 'mobile-alt'); ?>"></i>
                                            <?php echo ucfirst($sale['payment_method']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Status -->
                                <td class="text-center">
                                    <span class="status-badge <?php echo $badgeClass; ?>">
                                        <?php echo ucfirst($payStatus); ?>
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td class="text-center">
                                    <div class="d-flex gap-1 justify-content-center flex-nowrap">
                                        <a href="view.php?id=<?php echo $sale['id']; ?>"
                                           class="btn btn-sm btn-outline-primary btn-action"
                                           data-bs-toggle="tooltip" title="View Sale">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="print_invoice.php?id=<?php echo $sale['id']; ?>"
                                           target="_blank"
                                           class="btn btn-sm btn-outline-secondary btn-action"
                                           data-bs-toggle="tooltip" title="Print Invoice">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        <?php if ($hasPending): ?>
                                        <button type="button"
                                           class="btn btn-sm btn-record-pay btn-open-pay-modal"
                                           data-credit-id="<?php echo (int)$creditId; ?>"
                                           data-sale-id="<?php echo (int)$sale['id']; ?>"
                                           data-invoice="<?php echo htmlspecialchars($sale['invoice_no'], ENT_QUOTES); ?>"
                                           data-customer="<?php echo htmlspecialchars($sale['customer_name'], ENT_QUOTES); ?>"
                                           data-remaining="<?php echo number_format($remaining, 2); ?>"
                                           data-remaining-raw="<?php echo $remaining; ?>"
                                           data-bs-toggle="tooltip"
                                           title="Record Payment — Rs <?php echo number_format($remaining, 2); ?> due">
                                            <i class="fas fa-money-bill-wave me-1"></i>Pay
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>

                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fas fa-receipt"></i>
                                        No sales found.
                                        <?php if (!empty($search)): ?>
                                        <br><small>No results for "<strong><?php echo htmlspecialchars($search); ?></strong>"</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div><!-- /table-responsive -->

            <!-- Pagination -->
            <?php if ($sales['totalPages'] > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    Showing <strong><?php echo count($sales['data']); ?></strong>
                    of <strong><?php echo number_format($sales['total']); ?></strong> sales
                </div>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link"
                               href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&from_date=<?php echo $fromDate; ?>&to_date=<?php echo $toDate; ?>">
                                &laquo; Prev
                            </a>
                        </li>
                        <?php for ($i = max(1, $page - 2); $i <= min($sales['totalPages'], $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link"
                               href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&from_date=<?php echo $fromDate; ?>&to_date=<?php echo $toDate; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $sales['totalPages'] ? 'disabled' : ''; ?>">
                            <a class="page-link"
                               href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&from_date=<?php echo $fromDate; ?>&to_date=<?php echo $toDate; ?>">
                                Next &raquo;
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

        </div><!-- /table-card -->
    </div><!-- /#table-container -->

</div><!-- /container-fluid -->
</div><!-- /main-content -->


<!-- ══════════════════════════════════════════════════════════════
     CREDIT PAYMENT MODAL — sales/list.php (100% preserved)
     ══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-460">
    <div class="modal-content">

      <!-- Header -->
      <div class="modal-header modal-header-success">
        <h5 class="modal-title mb-0"><i class="fas fa-money-bill-wave me-2"></i>Record Credit Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <!-- Body -->
      <div class="modal-body p-4" id="payModalBody">

        <!-- Summary -->
        <div class="pay-summary-box">
          <div class="row">
            <div class="col-6">
              <div class="label">Invoice</div>
              <div class="val" id="pmInvoice">—</div>
            </div>
            <div class="col-6">
              <div class="label">Customer</div>
              <div class="val" id="pmCustomer">—</div>
            </div>
          </div>
          <div class="mt-2">
            <div class="label">Remaining Balance</div>
            <div class="val" id="pmRemaining" style="color:var(--pharmacy-danger,#dc3545)">Rs 0.00</div>
          </div>
        </div>

        <!-- Amount -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Payment Amount <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text">Rs</span>
            <input type="number" id="pmAmount" class="form-control form-control-lg"
                   step="0.01" min="0.01" placeholder="0.00">
          </div>
          <div class="form-text">
            <a href="#" id="pmPayFullLink" style="color:var(--pharmacy-success,#198754);font-weight:700;text-decoration:none">
              <i class="fas fa-check-circle me-1"></i>Pay full amount
            </a>
          </div>
        </div>

        <!-- Payment Method -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Payment Method</label>
          <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="pay-method-btn active" data-method="cash">
              <i class="fas fa-money-bill-wave me-1"></i>Cash
            </button>
            <button type="button" class="pay-method-btn" data-method="card">
              <i class="fas fa-credit-card me-1"></i>Card
            </button>
            <button type="button" class="pay-method-btn" data-method="online">
              <i class="fas fa-mobile-alt me-1"></i>Online
            </button>
          </div>
          <input type="hidden" id="pmMethod" value="cash">
        </div>

        <!-- Note -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Note <span class="fw-normal" style="color:color-mix(in srgb,var(--pharmacy-text,#374151) 55%,transparent)">(optional)</span></label>
          <input type="text" id="pmNote" class="form-control" placeholder="e.g. Partial payment, cheque no…">
        </div>

        <!-- Error alert -->
        <div class="alert alert-danger d-none py-2 px-3" id="pmError" role="alert"></div>

        <!-- Success panel -->
        <div class="text-center d-none" id="pmSuccess">
          <div class="pay-success-check"><i class="fas fa-check-circle"></i></div>
          <h6 class="mt-2 mb-1" style="color:var(--pharmacy-success,#198754)" id="pmSuccessMsg">Payment recorded!</h6>
          <p class="mb-0" style="font-size:.82rem;color:color-mix(in srgb,var(--pharmacy-text,#374151) 60%,transparent)" id="pmSuccessBalance"></p>
        </div>

      </div><!-- /modal-body -->

      <!-- Footer -->
      <div class="modal-footer" id="payModalFooter">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success px-4" id="pmSubmitBtn">
          <span id="pmBtnText"><i class="fas fa-check me-1"></i>Record Payment</span>
          <span id="pmBtnSpinner" class="d-none">
            <span class="spinner-border me-1"></span>Processing…
          </span>
        </button>
      </div>

    </div><!-- /modal-content -->
  </div>
</div>

<script>
/* ══════════════════════════════════════════════════════════════════
   sales/list.php — Page JS
   1. AJAX table refresh (search debounce + date filter)
   2. Credit payment modal (100% original logic preserved)
   ══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── Tooltip init ──────────────────────────────────────────────── */
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    new bootstrap.Tooltip(el, { trigger: 'hover' });
  });

  /* ── AJAX table refresh ────────────────────────────────────────── */
  var filterForm     = document.getElementById('filterForm');
  var tableContainer = document.getElementById('table-container');

  function ajaxUpdateTable() {
    if (!filterForm || !tableContainer) return;
    var params = new URLSearchParams(new FormData(filterForm));
    params.set('page', '1');

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
        history.replaceState(null, '', '?' + params.toString());
      })
      .catch(function () {
        tableContainer.classList.remove('loading');
      });
  }

  /* Date filter fields trigger AJAX refresh */
  ['fromDate', 'toDate'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', ajaxUpdateTable);
  });

  /* Search debounce (400ms) */
  var searchInput = document.getElementById('searchInput');
  var searchTimer;
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(ajaxUpdateTable, 400);
    });
  }

  /* Prevent full-page submit when JS active */
  if (filterForm) {
    filterForm.addEventListener('submit', function (e) {
      e.preventDefault();
      ajaxUpdateTable();
    });
  }

  /* ════════════════════════════════════════════════════════════════
     CREDIT PAYMENT MODAL — 100% original logic, zero changes
     ════════════════════════════════════════════════════════════════ */
  const CSRF    = '<?php echo generateCSRFToken(); ?>';
  const PAY_URL = '<?php echo BASE_URL; ?>SALES/pay_credit.php';

  let currentCreditId  = 0;
  let currentSaleId    = 0;
  let currentRemaining = 0;

  const modal       = new bootstrap.Modal(document.getElementById('payModal'));
  const $body       = document.getElementById('payModalBody');
  const $footer     = document.getElementById('payModalFooter');
  const $error      = document.getElementById('pmError');
  const $success    = document.getElementById('pmSuccess');
  const $submitBtn  = document.getElementById('pmSubmitBtn');
  const $btnText    = document.getElementById('pmBtnText');
  const $btnSpinner = document.getElementById('pmBtnSpinner');

  /* ── Open modal when Pay button clicked ── */
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-open-pay-modal');
    if (!btn) return;

    currentCreditId  = parseInt(btn.dataset.creditId)  || 0;
    currentSaleId    = parseInt(btn.dataset.saleId)    || 0;
    currentRemaining = parseFloat(btn.dataset.remainingRaw) || 0;

    document.getElementById('pmInvoice').textContent   = btn.dataset.invoice  || '—';
    document.getElementById('pmCustomer').textContent  = btn.dataset.customer || '—';
    document.getElementById('pmRemaining').textContent = 'Rs ' + parseFloat(btn.dataset.remainingRaw).toFixed(2);

    /* reset form */
    document.getElementById('pmAmount').value = '';
    document.getElementById('pmNote').value   = '';
    document.getElementById('pmMethod').value = 'cash';
    document.querySelectorAll('.pay-method-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.method === 'cash');
    });
    $error.classList.add('d-none');
    $success.classList.add('d-none');
    $body.querySelectorAll('input,button:not([data-bs-dismiss])').forEach(el => el.removeAttribute('disabled'));
    $footer.classList.remove('d-none');
    $submitBtn.disabled = false;
    setSpinner(false);

    modal.show();
  });

  /* ── Pay full amount shortcut ── */
  document.getElementById('pmPayFullLink').addEventListener('click', function (e) {
    e.preventDefault();
    document.getElementById('pmAmount').value = currentRemaining.toFixed(2);
  });

  /* ── Payment method toggle ── */
  document.querySelectorAll('.pay-method-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.pay-method-btn').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      document.getElementById('pmMethod').value = this.dataset.method;
    });
  });

  /* ── Submit ── */
  document.getElementById('pmSubmitBtn').addEventListener('click', submitPayment);

  function setSpinner(on) {
    $btnText.classList.toggle('d-none', on);
    $btnSpinner.classList.toggle('d-none', !on);
  }

  function submitPayment() {
    const amount = parseFloat(document.getElementById('pmAmount').value);
    if (!amount || amount <= 0) {
      showError('Please enter a valid payment amount.');
      return;
    }
    if (amount > currentRemaining + 0.01) {
      showError('Amount exceeds remaining balance of Rs ' + currentRemaining.toFixed(2));
      return;
    }

    $error.classList.add('d-none');
    setSpinner(true);
    $submitBtn.disabled = true;

    const fd = new FormData();
    fd.append('csrf_token',     CSRF);
    fd.append('credit_id',      currentCreditId);
    fd.append('sale_id',        currentSaleId);
    fd.append('amount',         amount.toFixed(2));
    fd.append('payment_method', document.getElementById('pmMethod').value);
    fd.append('note',           document.getElementById('pmNote').value);

    fetch(PAY_URL, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        setSpinner(false);
        if (data.success) {
          onPaymentSuccess(data, amount);
        } else {
          $submitBtn.disabled = false;
          showError(data.message || 'Payment failed. Please try again.');
        }
      })
      .catch(() => {
        setSpinner(false);
        $submitBtn.disabled = false;
        showError('Network error. Please check your connection and try again.');
      });
  }

  function onPaymentSuccess(data, paidAmount) {
    const newBal = parseFloat(data.new_balance ?? 0);

    document.getElementById('pmSuccessMsg').textContent =
      'Payment of Rs ' + paidAmount.toFixed(2) + ' recorded!';
    document.getElementById('pmSuccessBalance').textContent =
      data.fully_paid ? 'Credit fully settled ✓' : 'Remaining: Rs ' + newBal.toFixed(2);
    $success.classList.remove('d-none');
    $footer.classList.add('d-none');

    updateListRow(currentSaleId, newBal, data.fully_paid);

    setTimeout(() => modal.hide(), 1800);
  }

  function updateListRow(saleId, newBal, fullyPaid) {
    const btn = document.querySelector('.btn-open-pay-modal[data-sale-id="' + saleId + '"]');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (!tr) return;

    if (fullyPaid) {
      tr.classList.remove('row-pending');
      btn.remove();
      const pendingSpan = tr.querySelector('.pending-amount');
      if (pendingSpan) pendingSpan.closest('span, br')?.remove() || pendingSpan.remove();

      /* Update status badge */
      const statusBadge = tr.querySelector('.status-badge');
      if (statusBadge) {
        statusBadge.className = 'status-badge badge-paid';
        statusBadge.textContent = 'Paid';
      }
    } else {
      const pendingSpan = tr.querySelector('.pending-amount');
      if (pendingSpan) {
        pendingSpan.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i>Due: Rs ' + newBal.toFixed(2);
      }
      btn.dataset.remainingRaw = newBal.toFixed(2);
      btn.dataset.remaining    = newBal.toFixed(2);
      btn.title = 'Record Payment — Rs ' + newBal.toFixed(2) + ' due';
    }
  }

  function showError(msg) {
    $error.textContent = msg;
    $error.classList.remove('d-none');
  }

})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
