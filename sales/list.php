<?php
/**
 * sales/list.php — Sales History (Phase 17: Full AJAX)
 *
 * AJAX FEATURES:
 * - Search debounce (400ms) + date filters — no page reload
 * - AJAX pagination via ajax-page-link class
 * - Edit Sale modal (customer name, phone) via AJAX
 * - Void Sale modal with reason, stock restore, cashbook sync
 * - Process Return modal — per-item qty selection
 * - Invoice Preview modal (iframe) + Print button
 * - Credit Payment modal — 100% original logic preserved
 * - Toast notifications for all actions
 */

require_once '../config/config.php';
require_once '../includes/sale_functions.php';
requireLogin();

$page_title = 'Sales History';
$page     = isset($_GET['page'])      ? (int)$_GET['page']          : 1;
$search   = isset($_GET['search'])    ? sanitize($_GET['search'])    : '';
$fromDate = isset($_GET['from_date']) ? sanitize($_GET['from_date']) : '';
$toDate   = isset($_GET['to_date'])   ? sanitize($_GET['to_date'])   : '';

$sales = getAllSales($page, 20, $search, $fromDate, $toDate);

// Pre-fetch credit info (batch query — no N+1)
$db        = db();
$creditMap = [];
if (!empty($sales['data'])) {
    $saleIds  = array_column($sales['data'], 'id');
    $namedPh  = [];
    $idParams = [];
    foreach ($saleIds as $i => $sid) {
        $k = ':s' . $i;
        $namedPh[]    = $k;
        $idParams[$k] = $sid;
    }
    $crRows = $db->getAll(
        "SELECT cc.sale_id, cc.id AS credit_id, cc.total_amount, cc.status,
                COALESCE(SUM(CASE WHEN (cp.note IS NULL OR (cp.note NOT LIKE 'Upfront%' AND cp.note NOT LIKE 'upfront%')) THEN cp.amount ELSE 0 END), 0) AS post_sale_paid
         FROM customer_credits cc
         LEFT JOIN credit_payments cp ON cp.credit_id = cc.id
         WHERE cc.sale_id IN (" . implode(',', $namedPh) . ")
         GROUP BY cc.sale_id, cc.id",
        $idParams
    );
    foreach ($crRows as $cr) {
        $creditMap[$cr['sale_id']] = $cr;
    }
}

// Item counts batch
$itemCountsCache = [];
if (!empty($saleIds ?? [])) {
    $icRows = $db->getAll(
        "SELECT sale_id, COUNT(*) AS cnt FROM sale_items WHERE sale_id IN (" . implode(',', $namedPh ?? []) . ") GROUP BY sale_id",
        $idParams ?? []
    );
    foreach ($icRows as $ir) {
        $itemCountsCache[$ir['sale_id']] = $ir['cnt'];
    }
}

$serialStart = ($page - 1) * 20 + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        /* ── Phase 17 — Sales List Styles (CSS-variable-only) ── */
        @keyframes fadeInPage {
            from { opacity:0; transform:translateY(10px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .main-content {
            margin-left: var(--sb-w, 260px);
            padding: 24px 28px;
            animation: fadeInPage .28s ease;
            transition: margin-left .3s ease;
        }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width:991px) { .main-content { margin-left:0 !important; } }

        .page-heading h4 { font-size:1.25rem; font-weight:700; color:var(--pharmacy-heading,#111827); margin-bottom:3px; }
        .page-heading p  { font-size:.82rem; color:color-mix(in srgb,var(--pharmacy-text,#374151) 60%,transparent); margin:0; }

        /* Summary cards */
        .summary-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:18px; }
        .stat-card { background:var(--pharmacy-card-bg,#fff); border:1px solid var(--pharmacy-border,#e5e7eb); border-radius:10px; padding:14px 16px; box-shadow:0 1px 5px rgba(0,0,0,.04); transition:box-shadow .2s; }
        .stat-card:hover { box-shadow:0 3px 12px rgba(0,0,0,.08); }
        .stat-card .label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:color-mix(in srgb,var(--pharmacy-text,#374151) 55%,transparent); margin-bottom:5px; }
        .stat-card .value { font-size:1.05rem; font-weight:800; color:var(--pharmacy-heading,#111827); }
        .stat-card .sub   { font-size:.72rem; color:color-mix(in srgb,var(--pharmacy-text,#374151) 60%,transparent); margin-top:3px; }

        /* Filter bar */
        .filter-bar { background:var(--pharmacy-card-bg,#fff); border:1px solid var(--pharmacy-border,#e5e7eb); border-radius:10px; padding:16px 20px; margin-bottom:18px; box-shadow:0 1px 6px rgba(0,0,0,.05); }
        .filter-bar .form-label { font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:color-mix(in srgb,var(--pharmacy-text,#374151) 65%,transparent); margin-bottom:4px; }
        .filter-bar .form-control, .filter-bar .form-select { border-color:var(--pharmacy-border,#e5e7eb); background:var(--pharmacy-input-bg,#fff); color:var(--pharmacy-text,#374151); font-size:.85rem; }

        /* Table card */
        .table-card { background:var(--pharmacy-card-bg,#fff); border:1px solid var(--pharmacy-border,#e5e7eb); border-radius:var(--card-border-radius,12px); overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.05); }
        .table-card-header { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; background:var(--pharmacy-table-th,#f8fafc); border-bottom:2px solid var(--pharmacy-border,#e5e7eb); }
        .table-card-header .table-title { font-weight:700; font-size:.85rem; color:var(--pharmacy-heading,#111827); text-transform:uppercase; letter-spacing:.4px; }
        .table-card-header .record-count { font-size:.78rem; color:color-mix(in srgb,var(--pharmacy-text,#374151) 60%,transparent); }

        .table th { background:var(--pharmacy-table-th,#f8fafc) !important; color:var(--pharmacy-heading,#111827) !important; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; font-weight:700; padding:11px 14px; border-bottom:2px solid var(--pharmacy-border,#e5e7eb) !important; white-space:nowrap; }
        .table td { padding:11px 14px; vertical-align:middle; color:var(--pharmacy-text,#374151) !important; border-color:var(--pharmacy-border,#e5e7eb) !important; font-size:.875rem; }
        .table tbody tr { transition:background .15s ease; }
        .table-hover tbody tr:hover td { background:color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 5%,var(--pharmacy-card-bg,#fff)) !important; }

        .row-pending td { background:color-mix(in srgb,var(--pharmacy-warning,#fd7e14) 8%,var(--pharmacy-card-bg,#fff)) !important; }
        .row-pending:hover td { background:color-mix(in srgb,var(--pharmacy-warning,#fd7e14) 14%,var(--pharmacy-card-bg,#fff)) !important; }

        /* Badges */
        .status-badge { padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; }
        .badge-paid    { background:color-mix(in srgb,var(--pharmacy-success,#198754) 12%,transparent); color:var(--pharmacy-success,#198754); }
        .badge-partial { background:color-mix(in srgb,var(--pharmacy-warning,#fd7e14) 14%,transparent); color:var(--pharmacy-warning,#fd7e14); }
        .badge-pending { background:color-mix(in srgb,var(--pharmacy-danger,#dc3545) 12%,transparent);  color:var(--pharmacy-danger,#dc3545); }
        .badge-returned{ background:color-mix(in srgb,var(--pharmacy-secondary,#6c757d) 15%,transparent); color:var(--pharmacy-secondary,#6c757d); }

        .pay-method-badge { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:6px; font-size:.75rem; font-weight:600; background:color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 10%,transparent); color:var(--pharmacy-primary,#0d6efd); }
        .credit-badge  { background:color-mix(in srgb,var(--pharmacy-warning,#fd7e14) 12%,transparent); color:var(--pharmacy-warning,#fd7e14); }
        .partial-badge { background:color-mix(in srgb,var(--pharmacy-warning,#fd7e14) 14%,transparent); color:var(--pharmacy-warning,#fd7e14); }

        .pending-amount { font-size:.7rem; color:var(--pharmacy-danger,#dc3545); font-weight:700; }

        /* Action buttons */
        .btn-action { width:30px; height:30px; padding:0 !important; display:inline-flex; align-items:center; justify-content:center; font-size:.75rem; border-radius:7px; }
        .btn-record-pay { background:linear-gradient(135deg,var(--pharmacy-success,#198754),color-mix(in srgb,var(--pharmacy-success,#198754) 70%,transparent)); color:#fff; border:none; border-radius:7px; font-size:.72rem; font-weight:700; padding:4px 10px; white-space:nowrap; transition:all .2s ease; }
        .btn-record-pay:hover { opacity:.87; color:#fff; transform:translateY(-1px); }

        .invoice-link { font-weight:700; font-size:.82rem; color:var(--pharmacy-primary,#0d6efd); text-decoration:none; cursor:pointer; }
        .invoice-link:hover { text-decoration:underline; }

        /* Pagination */
        .pagination-wrapper { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-top:1px solid var(--pharmacy-border,#e5e7eb); background:var(--pharmacy-table-th,#f8fafc); flex-wrap:wrap; gap:10px; }
        .pagination-info { font-size:.78rem; color:color-mix(in srgb,var(--pharmacy-text,#374151) 65%,transparent); }
        .page-link { color:var(--pharmacy-primary,#0d6efd); border-color:var(--pharmacy-border,#e5e7eb); background:var(--pharmacy-card-bg,#fff); font-size:.8rem; }
        .page-item.active .page-link { background:var(--pharmacy-primary,#0d6efd); border-color:var(--pharmacy-primary,#0d6efd); color:#fff; }

        /* AJAX loading overlay */
        #table-container { position:relative; min-height:80px; }
        .table-overlay { position:absolute; inset:0; background:color-mix(in srgb,var(--pharmacy-card-bg,#fff) 80%,transparent); display:flex; align-items:center; justify-content:center; border-radius:var(--card-border-radius,12px); z-index:10; pointer-events:none; opacity:0; transition:opacity .2s; }
        #table-container.loading .table-overlay { opacity:1; pointer-events:auto; }

        .empty-state { text-align:center; padding:48px 20px; color:color-mix(in srgb,var(--pharmacy-text,#374151) 55%,transparent); font-size:.9rem; }
        .empty-state i { font-size:2.5rem; margin-bottom:12px; display:block; opacity:.35; }

        /* Pay modal */
        .pay-summary-box { background:var(--pharmacy-table-th,#f8fafc); border-radius:8px; padding:12px 16px; margin-bottom:16px; border:1px solid var(--pharmacy-border,#e5e7eb); border-left:4px solid var(--pharmacy-danger,#dc3545); }
        .pay-summary-box .label { font-size:.68rem; color:color-mix(in srgb,var(--pharmacy-text,#374151) 60%,transparent); font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
        .pay-summary-box .val   { font-size:.95rem; font-weight:700; color:var(--pharmacy-heading,#111827); }
        .pay-method-btn { border:2px solid var(--pharmacy-border,#dee2e6); border-radius:8px; padding:8px 14px; cursor:pointer; transition:all .2s ease; font-size:.82rem; background:var(--pharmacy-card-bg,#fff); color:var(--pharmacy-text,#374151); }
        .pay-method-btn.active { border-color:var(--pharmacy-success,#198754); background:color-mix(in srgb,var(--pharmacy-success,#198754) 10%,transparent); color:var(--pharmacy-success,#198754); font-weight:700; }
        .pay-success-check { font-size:3rem; color:var(--pharmacy-success,#198754); }

        .btn-pos { background:linear-gradient(135deg,var(--pharmacy-success,#28a745),color-mix(in srgb,var(--pharmacy-success,#28a745) 60%,var(--pharmacy-accent,#20c997))); color:#fff; border:none; transition:all .2s ease; }
        .btn-pos:hover { opacity:.88; color:#fff; transform:translateY(-1px); }
    </style>
</head>

<?php $__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : ""; ?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div class="page-heading">
            <h4><i class="fas fa-receipt me-2" style="opacity:.7;color:var(--pharmacy-primary,#0d6efd)"></i>Sales History</h4>
            <p>View and manage all sale transactions</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?php echo BASE_URL; ?>CREDIT/index.php" class="btn btn-outline-danger btn-sm px-3">
                <i class="fas fa-file-invoice-dollar me-2"></i>Credit Management
            </a>
            <a href="pos.php" class="btn btn-pos btn-sm px-3">
                <i class="fas fa-cash-register me-2"></i>New Sale (POS)
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards mb-3" id="summaryCards">
        <?php $todaySummary = getTodaySalesSummary(); ?>
        <div class="stat-card">
            <div class="label"><i class="fas fa-file-invoice me-1"></i>Today's Sales</div>
            <div class="value"><?php echo number_format($todaySummary['total_invoices']); ?></div>
            <div class="sub">Invoices</div>
        </div>
        <div class="stat-card">
            <div class="label"><i class="fas fa-coins me-1"></i>Today's Revenue</div>
            <div class="value">Rs <?php echo number_format($todaySummary['total_amount'], 2); ?></div>
            <div class="sub">Gross</div>
        </div>
        <div class="stat-card">
            <div class="label"><i class="fas fa-money-bill-wave me-1"></i>Cash</div>
            <div class="value">Rs <?php echo number_format($todaySummary['cash_amount'], 2); ?></div>
            <div class="sub">Today</div>
        </div>
        <div class="stat-card">
            <div class="label"><i class="fas fa-file-invoice-dollar me-1"></i>Credit Due</div>
            <div class="value">Rs <?php echo number_format($todaySummary['credit_amount'], 2); ?></div>
            <div class="sub"><?php echo $todaySummary['credit_sale_count']; ?> credit sales</div>
        </div>
        <div class="stat-card">
            <div class="label"><i class="fas fa-undo me-1"></i>Returns</div>
            <div class="value">Rs <?php echo number_format($todaySummary['total_returns_today'], 2); ?></div>
            <div class="sub">Today</div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" id="filterForm" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text" style="background:var(--pharmacy-table-th,#f8fafc);border-color:var(--pharmacy-border,#e5e7eb)">
                        <i class="fas fa-search" style="font-size:.8rem"></i>
                    </span>
                    <input type="text" name="search" id="searchInput" class="form-control form-control-sm"
                           placeholder="Invoice, customer, phone…"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="from_date" id="fromDate" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($fromDate); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="to_date" id="toDate" class="form-control form-control-sm"
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

    <!-- Sales Table (AJAX Container) -->
    <div id="table-container">
        <div class="table-overlay">
            <div class="spinner-border text-primary" role="status" style="width:1.6rem;height:1.6rem">
                <span class="visually-hidden">Loading…</span>
            </div>
        </div>
        <?php include __DIR__ . '/list_fragment.php'; ?>
    </div>

</div><!-- /container-fluid -->
</div><!-- /main-content -->


<!-- ════════════════ EDIT SALE MODAL ════════════════ -->
<div class="modal fade" id="editSaleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--pharmacy-card-bg,#fff);border:1px solid var(--pharmacy-border,#e5e7eb)">
      <div class="modal-header" style="border-color:var(--pharmacy-border,#e5e7eb)">
        <h5 class="modal-title" style="font-weight:700;font-size:.95rem;color:var(--pharmacy-heading,#111827)">
          <i class="fas fa-edit me-2" style="color:var(--pharmacy-primary,#0d6efd)"></i>Edit Sale
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="editAlert" class="alert d-none"></div>
        <div class="mb-3">
          <label class="form-label" style="font-size:.78rem;font-weight:700;text-transform:uppercase">Invoice</label>
          <input type="text" class="form-control form-control-sm" id="editInvoiceDisplay" readonly
                 style="background:var(--pharmacy-table-th,#f8fafc)">
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:.78rem;font-weight:700;text-transform:uppercase">Customer Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control form-control-sm" id="editCustomerName" placeholder="Customer name" autocomplete="off">
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:.78rem;font-weight:700;text-transform:uppercase">Phone</label>
          <input type="text" class="form-control form-control-sm" id="editCustomerPhone" placeholder="Customer phone">
        </div>
        <input type="hidden" id="editSaleId">
      </div>
      <div class="modal-footer" style="border-color:var(--pharmacy-border,#e5e7eb)">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="editSaveBtn">
          <span id="editBtnText"><i class="fas fa-save me-1"></i>Save Changes</span>
          <span id="editBtnSpinner" class="d-none spinner-border spinner-border-sm"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════ VOID SALE MODAL ════════════════ -->
<div class="modal fade" id="voidSaleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:var(--pharmacy-card-bg,#fff);border:1px solid var(--pharmacy-border,#e5e7eb)">
      <div class="modal-header" style="border-color:var(--pharmacy-border,#e5e7eb)">
        <h5 class="modal-title" style="font-weight:700;font-size:.95rem;color:var(--pharmacy-danger,#dc3545)">
          <i class="fas fa-ban me-2"></i>Void Sale
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="voidAlert" class="alert d-none"></div>
        <p style="font-size:.875rem;color:var(--pharmacy-text,#374151)">
          You are about to void sale <strong id="voidInvoiceDisplay">—</strong>.<br>
          This will <strong>restore stock</strong> and mark the cashbook entry as voided.
        </p>
        <div class="mb-3">
          <label class="form-label" style="font-size:.78rem;font-weight:700;text-transform:uppercase">Void Reason <span class="text-danger">*</span></label>
          <textarea class="form-control form-control-sm" id="voidReason" rows="2" placeholder="Enter reason for voiding…"></textarea>
        </div>
        <input type="hidden" id="voidSaleId">
      </div>
      <div class="modal-footer" style="border-color:var(--pharmacy-border,#e5e7eb)">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger btn-sm" id="voidConfirmBtn">
          <span id="voidBtnText"><i class="fas fa-ban me-1"></i>Void Sale</span>
          <span id="voidBtnSpinner" class="d-none spinner-border spinner-border-sm"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════ RETURNS MODAL ════════════════ -->
<div class="modal fade" id="returnModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="background:var(--pharmacy-card-bg,#fff);border:1px solid var(--pharmacy-border,#e5e7eb)">
      <div class="modal-header" style="border-color:var(--pharmacy-border,#e5e7eb)">
        <h5 class="modal-title" style="font-weight:700;font-size:.95rem;color:var(--pharmacy-heading,#111827)">
          <i class="fas fa-undo me-2" style="color:var(--pharmacy-warning,#fd7e14)"></i>Process Return
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="returnModalBody">
        <div class="text-center py-4"><div class="spinner-border text-warning"></div></div>
      </div>
      <div class="modal-footer d-none" id="returnModalFooter" style="border-color:var(--pharmacy-border,#e5e7eb)">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning btn-sm" id="returnSubmitBtn">
          <span id="returnBtnText"><i class="fas fa-undo me-1"></i>Process Return</span>
          <span id="returnBtnSpinner" class="d-none spinner-border spinner-border-sm"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════ INVOICE PREVIEW MODAL ════════════════ -->
<div class="modal fade" id="invoiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="background:var(--pharmacy-card-bg,#fff);border:1px solid var(--pharmacy-border,#e5e7eb)">
      <div class="modal-header" style="border-color:var(--pharmacy-border,#e5e7eb)">
        <h5 class="modal-title" style="font-weight:700;font-size:.95rem">
          <i class="fas fa-file-invoice me-2" style="color:var(--pharmacy-primary,#0d6efd)"></i>Invoice Preview
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" id="invoicePreviewBody">
        <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
      </div>
      <div class="modal-footer" style="border-color:var(--pharmacy-border,#e5e7eb)">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-outline-primary btn-sm" id="invoiceA4Btn">
          <i class="fas fa-file-invoice me-1"></i>A4 Invoice
        </button>
        <button type="button" class="btn btn-outline-danger btn-sm" id="invoicePdfBtn">
          <i class="fas fa-file-pdf me-1"></i>PDF
        </button>
        <button type="button" class="btn btn-primary btn-sm" id="invoicePrintBtn">
          <i class="fas fa-print me-1"></i>Thermal Print
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════ CREDIT PAYMENT MODAL ════════════════ -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content" style="background:var(--pharmacy-card-bg,#fff);border:1px solid var(--pharmacy-border,#e5e7eb);border-radius:12px">
      <div class="modal-header" style="border-color:var(--pharmacy-border,#e5e7eb)">
        <h5 class="modal-title" style="font-weight:700;font-size:.95rem;color:var(--pharmacy-heading,#111827)">
          <i class="fas fa-money-bill-wave me-2" style="color:var(--pharmacy-success,#198754)"></i>Record Credit Payment
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="payModalBody">
        <div id="pmError"   class="alert alert-danger d-none" role="alert"></div>
        <div id="pmSuccess" class="alert alert-success d-none" role="alert">
          <div class="text-center pay-success-check">✓</div>
          <div id="pmSuccessMsg"     class="text-center fw-bold mt-2"></div>
          <div id="pmSuccessBalance" class="text-center mt-1" style="font-size:.85rem"></div>
        </div>
        <div class="pay-summary-box mb-3">
          <div class="row g-2">
            <div class="col-6">
              <div class="label">Invoice</div>
              <div class="val" id="pmInvoice">—</div>
            </div>
            <div class="col-6">
              <div class="label">Customer</div>
              <div class="val" id="pmCustomer" style="font-size:.85rem">—</div>
            </div>
          </div>
          <div class="mt-2">
            <div class="label">Remaining Balance</div>
            <div class="val text-danger" id="pmRemaining">—</div>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.4px">
            Amount
            <a href="#" id="pmPayFullLink" class="ms-2" style="font-size:.75rem;text-transform:none">(Pay full)</a>
          </label>
          <input type="number" class="form-control" id="pmAmount" step="0.01" min="0.01" placeholder="0.00">
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.4px">Payment Method</label>
          <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="pay-method-btn active" data-method="cash"><i class="fas fa-money-bill-wave me-1"></i>Cash</button>
            <button type="button" class="pay-method-btn"        data-method="card"><i class="fas fa-credit-card me-1"></i>Card</button>
            <button type="button" class="pay-method-btn"        data-method="online"><i class="fas fa-mobile-alt me-1"></i>Online</button>
          </div>
          <input type="hidden" id="pmMethod" value="cash">
        </div>
        <div class="mb-2">
          <label class="form-label fw-bold" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.4px">Note (optional)</label>
          <input type="text" class="form-control form-control-sm" id="pmNote" placeholder="Payment note…">
        </div>
      </div>
      <div class="modal-footer" id="payModalFooter" style="border-color:var(--pharmacy-border,#e5e7eb)">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success btn-sm px-4" id="pmSubmitBtn">
          <span id="pmBtnText">Record Payment</span>
          <span id="pmBtnSpinner" class="d-none spinner-border spinner-border-sm"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
/* ════════════════════════════════════════════════════════════════
   Sales List — Phase 17 AJAX Controller
   All features: search, filter, pagination, edit, void, return,
   invoice preview/print, credit payment
════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  const AJAX_URL = 'ajax_handler.php';
  const PAY_URL  = '<?php echo BASE_URL; ?>SALES/pay_credit.php';
  const CSRF     = '<?php echo generateCSRFToken(); ?>';

  // Tooltips
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    new bootstrap.Tooltip(el, { trigger: 'hover' });
  });

  /* ═══ AJAX TABLE REFRESH ═════════════════════════════════════════════ */
  const filterForm     = document.getElementById('filterForm');
  const tableContainer = document.getElementById('table-container');
  let   currentPage    = <?php echo (int)$page; ?>;

  function getFilterParams(page) {
    var p = new URLSearchParams(new FormData(filterForm));
    p.set('page', page || currentPage);
    return p;
  }

  function ajaxLoadTable(page) {
    if (!tableContainer) return;
    page = page || 1;
    currentPage = page;

    var params = getFilterParams(page);
    params.set('action', 'get_list');

    tableContainer.classList.add('loading');

    fetch(AJAX_URL + '?' + params.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var overlay = tableContainer.querySelector('.table-overlay');
        tableContainer.innerHTML = '';
        if (overlay) tableContainer.appendChild(overlay);

        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        while (tmp.firstChild) { tableContainer.appendChild(tmp.firstChild); }

        tableContainer.classList.remove('loading');
        history.replaceState(null, '', '?' + getFilterParams(page).toString());
        rebindTooltips();
        rebindPaginationLinks();
      })
      .catch(function () { tableContainer.classList.remove('loading'); });
  }

  function rebindTooltips() {
    tableContainer.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      if (!bootstrap.Tooltip.getInstance(el)) {
        new bootstrap.Tooltip(el, { trigger: 'hover' });
      }
    });
  }

  function rebindPaginationLinks() {
    document.querySelectorAll('.ajax-page-link').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        ajaxLoadTable(parseInt(this.dataset.page) || 1);
      });
    });
  }

  rebindPaginationLinks();

  ['fromDate', 'toDate'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', function () { ajaxLoadTable(1); });
  });

  var searchTimer;
  var searchInput = document.getElementById('searchInput');
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { ajaxLoadTable(1); }, 400);
    });
  }

  if (filterForm) {
    filterForm.addEventListener('submit', function (e) { e.preventDefault(); ajaxLoadTable(1); });
  }

  /* ═══ EDIT SALE MODAL ════════════════════════════════════════════════ */
  const editModal   = new bootstrap.Modal(document.getElementById('editSaleModal'));
  const editAlert   = document.getElementById('editAlert');
  const editSaveBtn = document.getElementById('editSaveBtn');

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-edit-sale');
    if (!btn) return;
    document.getElementById('editSaleId').value         = btn.dataset.saleId;
    document.getElementById('editInvoiceDisplay').value = btn.dataset.invoice;
    document.getElementById('editCustomerName').value   = btn.dataset.customer;
    document.getElementById('editCustomerPhone').value  = btn.dataset.phone || '';
    editAlert.classList.add('d-none');
    editModal.show();
  });

  editSaveBtn.addEventListener('click', function () {
    var name = document.getElementById('editCustomerName').value.trim();
    if (!name) { showModalAlert(editAlert, 'danger', 'Customer name is required.'); return; }

    setSpinner(editSaveBtn, 'editBtnText', 'editBtnSpinner', true);
    editAlert.classList.add('d-none');

    var fd = new FormData();
    fd.append('action',         'update_sale');
    fd.append('csrf_token',      CSRF);
    fd.append('sale_id',         document.getElementById('editSaleId').value);
    fd.append('customer_name',   name);
    fd.append('customer_phone',  document.getElementById('editCustomerPhone').value);

    fetch(AJAX_URL, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        setSpinner(editSaveBtn, 'editBtnText', 'editBtnSpinner', false);
        if (data.success) {
          editModal.hide();
          var saleId = document.getElementById('editSaleId').value;
          var tr = document.querySelector('tr[data-sale-id="' + saleId + '"]');
          if (tr) {
            // Update customer cell (index 2)
            var phone = document.getElementById('editCustomerPhone').value;
            tr.cells[2].innerHTML = escHtml(name) + (phone ? '<br><small style="font-size:.72rem;opacity:.6">' + escHtml(phone) + '</small>' : '');
            var eb = tr.querySelector('.btn-edit-sale');
            if (eb) { eb.dataset.customer = name; eb.dataset.phone = phone; }
            var pb = tr.querySelector('.btn-open-pay-modal');
            if (pb) pb.dataset.customer = name;
          }
          showToast('Sale updated successfully.', 'success');
        } else {
          showModalAlert(editAlert, 'danger', data.message || 'Failed to update sale.');
        }
      })
      .catch(function () {
        setSpinner(editSaveBtn, 'editBtnText', 'editBtnSpinner', false);
        showModalAlert(editAlert, 'danger', 'Network error. Please try again.');
      });
  });

  /* ═══ VOID SALE MODAL ═════════════════════════════════════════════════ */
  const voidModal      = new bootstrap.Modal(document.getElementById('voidSaleModal'));
  const voidAlert      = document.getElementById('voidAlert');
  const voidConfirmBtn = document.getElementById('voidConfirmBtn');

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-void-sale');
    if (!btn) return;
    document.getElementById('voidSaleId').value               = btn.dataset.saleId;
    document.getElementById('voidInvoiceDisplay').textContent = btn.dataset.invoice;
    document.getElementById('voidReason').value               = '';
    voidAlert.classList.add('d-none');
    voidModal.show();
  });

  voidConfirmBtn.addEventListener('click', function () {
    var reason = document.getElementById('voidReason').value.trim();
    if (!reason) { showModalAlert(voidAlert, 'danger', 'Please enter a void reason.'); return; }

    setSpinner(voidConfirmBtn, 'voidBtnText', 'voidBtnSpinner', true);
    voidAlert.classList.add('d-none');

    var fd = new FormData();
    fd.append('action',     'delete_sale');
    fd.append('csrf_token',  CSRF);
    fd.append('sale_id',     document.getElementById('voidSaleId').value);
    fd.append('reason',      reason);

    fetch(AJAX_URL, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        setSpinner(voidConfirmBtn, 'voidBtnText', 'voidBtnSpinner', false);
        if (data.success) {
          voidModal.hide();
          var saleId = document.getElementById('voidSaleId').value;
          var tr = document.querySelector('tr[data-sale-id="' + saleId + '"]');
          if (tr) {
            tr.style.transition = 'all .35s ease';
            tr.style.opacity    = '0';
            tr.style.transform  = 'translateX(-20px)';
            setTimeout(function () { if (tr.parentNode) tr.remove(); }, 350);
          }
          showToast('Sale voided. Stock has been restored.', 'success');
        } else {
          showModalAlert(voidAlert, 'danger', data.message || 'Failed to void sale.');
        }
      })
      .catch(function () {
        setSpinner(voidConfirmBtn, 'voidBtnText', 'voidBtnSpinner', false);
        showModalAlert(voidAlert, 'danger', 'Network error. Please try again.');
      });
  });

  /* ═══ RETURNS MODAL ═══════════════════════════════════════════════════ */
  const returnModal     = new bootstrap.Modal(document.getElementById('returnModal'));
  const returnModalBody = document.getElementById('returnModalBody');
  const returnModalFoot = document.getElementById('returnModalFooter');
  const returnSubmitBtn = document.getElementById('returnSubmitBtn');
  let   currentReturnSaleId = 0;

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-return-sale');
    if (!btn) return;
    currentReturnSaleId = btn.dataset.saleId;
    returnModalBody.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-warning"></div></div>';
    returnModalFoot.classList.add('d-none');
    returnModal.show();
    loadReturnItems(currentReturnSaleId);
  });

  function loadReturnItems(saleId) {
    fetch(AJAX_URL + '?action=get_return_items&sale_id=' + saleId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          returnModalBody.innerHTML = '<div class="alert alert-danger">' + escHtml(data.message || 'Error loading items.') + '</div>';
          return;
        }
        renderReturnForm(data.sale, data.items);
        returnModalFoot.classList.remove('d-none');
      })
      .catch(function () {
        returnModalBody.innerHTML = '<div class="alert alert-danger">Network error loading return items.</div>';
      });
  }

  function renderReturnForm(sale, items) {
    if (!items || items.length === 0) {
      returnModalBody.innerHTML = '<div class="alert alert-warning">No returnable items found for this sale.</div>';
      returnModalFoot.classList.add('d-none');
      return;
    }
    var rows = '';
    items.forEach(function (item) {
      var max = parseInt(item.returnable_qty || item.quantity || 0);
      rows += '<tr>' +
        '<td><input type="checkbox" class="form-check-input return-item-check" data-id="' + item.sale_item_id + '" data-max="' + max + '"></td>' +
        '<td>' + escHtml(item.product_name || '—') + '</td>' +
        '<td class="text-center">' + item.quantity + '</td>' +
        '<td class="text-center">' + (item.returned_qty || 0) + '</td>' +
        '<td class="text-center">' + max + '</td>' +
        '<td><input type="number" class="form-control form-control-sm return-qty-input" min="1" max="' + max + '" value="1" disabled style="width:70px"></td>' +
        '</tr>';
    });

    returnModalBody.innerHTML =
      '<div class="alert alert-info py-2 mb-3" style="font-size:.83rem"><strong>Invoice:</strong> ' + escHtml(sale.invoice_no) +
      ' &nbsp;|&nbsp; <strong>Customer:</strong> ' + escHtml(sale.customer_name) + '</div>' +
      '<div id="returnAlert" class="alert d-none"></div>' +
      '<div class="mb-3"><label class="form-label fw-bold" style="font-size:.8rem;text-transform:uppercase">Reason <span class="text-danger">*</span></label>' +
      '<input type="text" class="form-control form-control-sm" id="returnReasonInput" placeholder="Reason for return…"></div>' +
      '<div class="table-responsive"><table class="table table-sm">' +
      '<thead><tr><th style="width:36px"></th><th>Product</th><th class="text-center">Sold</th><th class="text-center">Returned</th><th class="text-center">Available</th><th>Qty</th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table></div>';

    returnModalBody.querySelectorAll('.return-item-check').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var qtyInput = this.closest('tr').querySelector('.return-qty-input');
        qtyInput.disabled = !this.checked;
      });
    });
  }

  returnSubmitBtn.addEventListener('click', function () {
    var reasonInput = document.getElementById('returnReasonInput');
    var returnAlert = document.getElementById('returnAlert');
    if (!reasonInput) return;
    var reason = reasonInput.value.trim();
    if (!reason) {
      showModalAlert(returnAlert, 'danger', 'Please enter a return reason.'); return;
    }
    var checked = returnModalBody.querySelectorAll('.return-item-check:checked');
    if (checked.length === 0) {
      showModalAlert(returnAlert, 'danger', 'Please select at least one item to return.'); return;
    }
    var items = [];
    var valid = true;
    checked.forEach(function (cb) {
      var qtyIn = cb.closest('tr').querySelector('.return-qty-input');
      var qty   = parseInt(qtyIn.value) || 0;
      var max   = parseInt(cb.dataset.max) || 0;
      if (qty <= 0 || qty > max) { valid = false; }
      items.push({ sale_item_id: cb.dataset.id, quantity: qty });
    });
    if (!valid) {
      showModalAlert(returnAlert, 'danger', 'Invalid return quantities.'); return;
    }

    returnAlert.classList.add('d-none');
    setSpinner(returnSubmitBtn, 'returnBtnText', 'returnBtnSpinner', true);

    var fd = new FormData();
    fd.append('action',     'process_return');
    fd.append('csrf_token',  CSRF);
    fd.append('sale_id',     currentReturnSaleId);
    fd.append('reason',      reason);
    fd.append('items',       JSON.stringify(items));

    fetch(AJAX_URL, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        setSpinner(returnSubmitBtn, 'returnBtnText', 'returnBtnSpinner', false);
        if (data.success) {
          returnModal.hide();
          showToast('Return processed. Stock restored.', 'success');
          setTimeout(function () { ajaxLoadTable(currentPage); }, 800);
        } else {
          showModalAlert(returnAlert, 'danger', data.message || 'Failed to process return.');
        }
      })
      .catch(function () {
        setSpinner(returnSubmitBtn, 'returnBtnText', 'returnBtnSpinner', false);
        showModalAlert(returnAlert, 'danger', 'Network error. Please try again.');
      });
  });

  /* ═══ INVOICE PREVIEW MODAL ═══════════════════════════════════════════ */
  const invoiceModal    = new bootstrap.Modal(document.getElementById('invoiceModal'));
  const invoiceBody     = document.getElementById('invoicePreviewBody');
  const invoicePrintBtn = document.getElementById('invoicePrintBtn');
  let   currentInvoiceSaleId = 0;

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-preview-invoice');
    if (!btn) return;
    e.preventDefault();
    currentInvoiceSaleId = btn.dataset.saleId;
    invoiceBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    invoiceModal.show();

    var iframe = document.createElement('iframe');
    iframe.src   = 'print_invoice.php?id=' + currentInvoiceSaleId;
    iframe.style.cssText = 'width:100%;height:520px;border:none';
    invoiceBody.innerHTML = '';
    invoiceBody.appendChild(iframe);
  });

  invoicePrintBtn.addEventListener('click', function () {
    if (currentInvoiceSaleId) {
      window.open('print_invoice.php?id=' + currentInvoiceSaleId + '&print=1', '_blank');
    }
  });

  /* Phase 25: A4 and PDF buttons in invoice modal */
  var invoiceA4Btn  = document.getElementById('invoiceA4Btn');
  var invoicePdfBtn = document.getElementById('invoicePdfBtn');
  if (invoiceA4Btn) {
    invoiceA4Btn.addEventListener('click', function () {
      if (currentInvoiceSaleId) {
        window.open('invoice_pdf.php?id=' + currentInvoiceSaleId + '&view=1', '_blank');
      }
    });
  }
  if (invoicePdfBtn) {
    invoicePdfBtn.addEventListener('click', function () {
      if (currentInvoiceSaleId) {
        window.open('invoice_pdf.php?id=' + currentInvoiceSaleId, '_blank');
      }
    });
  }

  /* ═══ CREDIT PAYMENT MODAL ════════════════════════════════════════════ */
  let currentCreditId  = 0;
  let currentSaleId    = 0;
  let currentRemaining = 0;

  const payModal    = new bootstrap.Modal(document.getElementById('payModal'));
  const $pmError    = document.getElementById('pmError');
  const $pmSuccess  = document.getElementById('pmSuccess');
  const $pmFooter   = document.getElementById('payModalFooter');
  const $pmSubmit   = document.getElementById('pmSubmitBtn');

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-open-pay-modal');
    if (!btn) return;
    currentCreditId  = parseInt(btn.dataset.creditId)    || 0;
    currentSaleId    = parseInt(btn.dataset.saleId)      || 0;
    currentRemaining = parseFloat(btn.dataset.remainingRaw) || 0;

    document.getElementById('pmInvoice').textContent   = btn.dataset.invoice  || '—';
    document.getElementById('pmCustomer').textContent  = btn.dataset.customer || '—';
    document.getElementById('pmRemaining').textContent = 'Rs ' + currentRemaining.toFixed(2);
    document.getElementById('pmAmount').value = '';
    document.getElementById('pmNote').value   = '';
    document.getElementById('pmMethod').value = 'cash';
    document.querySelectorAll('.pay-method-btn').forEach(function (b) {
      b.classList.toggle('active', b.dataset.method === 'cash');
    });
    $pmError.classList.add('d-none');
    $pmSuccess.classList.add('d-none');
    $pmFooter.classList.remove('d-none');
    $pmSubmit.disabled = false;
    setSpinner($pmSubmit, 'pmBtnText', 'pmBtnSpinner', false);
    payModal.show();
  });

  document.getElementById('pmPayFullLink').addEventListener('click', function (e) {
    e.preventDefault();
    document.getElementById('pmAmount').value = currentRemaining.toFixed(2);
  });
  document.querySelectorAll('.pay-method-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.pay-method-btn').forEach(function (b) { b.classList.remove('active'); });
      this.classList.add('active');
      document.getElementById('pmMethod').value = this.dataset.method;
    });
  });
  $pmSubmit.addEventListener('click', submitPayment);

  function submitPayment() {
    var amount = parseFloat(document.getElementById('pmAmount').value);
    if (!amount || amount <= 0) { showPayError('Please enter a valid payment amount.'); return; }
    if (amount > currentRemaining + 0.01) { showPayError('Amount exceeds remaining balance of Rs ' + currentRemaining.toFixed(2)); return; }

    $pmError.classList.add('d-none');
    setSpinner($pmSubmit, 'pmBtnText', 'pmBtnSpinner', true);
    $pmSubmit.disabled = true;

    var fd = new FormData();
    fd.append('csrf_token',     CSRF);
    fd.append('credit_id',      currentCreditId);
    fd.append('sale_id',        currentSaleId);
    fd.append('amount',         amount.toFixed(2));
    fd.append('payment_method', document.getElementById('pmMethod').value);
    fd.append('note',           document.getElementById('pmNote').value);

    fetch(PAY_URL, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        setSpinner($pmSubmit, 'pmBtnText', 'pmBtnSpinner', false);
        if (data.success) {
          onPaymentSuccess(data, amount);
        } else {
          $pmSubmit.disabled = false;
          showPayError(data.message || 'Payment failed. Please try again.');
        }
      })
      .catch(function () {
        setSpinner($pmSubmit, 'pmBtnText', 'pmBtnSpinner', false);
        $pmSubmit.disabled = false;
        showPayError('Network error. Please check your connection.');
      });
  }

  function onPaymentSuccess(data, paidAmount) {
    var newBal = parseFloat(data.new_balance || 0);
    document.getElementById('pmSuccessMsg').textContent     = 'Payment of Rs ' + paidAmount.toFixed(2) + ' recorded!';
    document.getElementById('pmSuccessBalance').textContent = data.fully_paid ? 'Credit fully settled ✓' : 'Remaining: Rs ' + newBal.toFixed(2);
    $pmSuccess.classList.remove('d-none');
    $pmFooter.classList.add('d-none');
    updateCreditRow(currentSaleId, newBal, data.fully_paid);
    setTimeout(function () { payModal.hide(); }, 1800);
  }

  function updateCreditRow(saleId, newBal, fullyPaid) {
    var btn = document.querySelector('.btn-open-pay-modal[data-sale-id="' + saleId + '"]');
    if (!btn) return;
    var tr = btn.closest('tr');
    if (!tr) return;
    if (fullyPaid) {
      tr.classList.remove('row-pending');
      btn.remove();
      var ps = tr.querySelector('.pending-amount');
      if (ps) ps.remove();
      var sb = tr.querySelector('.status-badge');
      if (sb) { sb.className = 'status-badge badge-paid'; sb.textContent = 'Paid'; }
    } else {
      var ps2 = tr.querySelector('.pending-amount');
      if (ps2) ps2.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i>Due: Rs ' + newBal.toFixed(2);
      btn.dataset.remainingRaw = newBal.toFixed(2);
      btn.dataset.remaining    = newBal.toFixed(2);
    }
  }

  function showPayError(msg) {
    $pmError.textContent = msg;
    $pmError.classList.remove('d-none');
  }

  /* ═══ UTILITIES ════════════════════════════════════════════════════════ */
  function setSpinner(btn, textId, spinnerId, on) {
    document.getElementById(textId).classList.toggle('d-none', on);
    document.getElementById(spinnerId).classList.toggle('d-none', !on);
    btn.disabled = on;
  }

  function showModalAlert(el, type, msg) {
    el.className = 'alert alert-' + type;
    el.textContent = msg;
  }

  function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
  }

  function showToast(message, type) {
    var old = document.getElementById('saleToast');
    if (old) old.remove();
    var t = document.createElement('div');
    t.id = 'saleToast';
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;min-width:280px;max-width:380px';
    t.innerHTML = '<div class="alert alert-' + type + ' shadow-lg d-flex align-items-center gap-2 mb-0" style="border-radius:10px;font-size:.875rem">' +
      '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'info-circle') + '"></i>' + message + '</div>';
    document.body.appendChild(t);
    setTimeout(function () {
      t.style.transition = 'opacity .4s';
      t.style.opacity    = '0';
      setTimeout(function () { if (t.parentNode) t.remove(); }, 400);
    }, 3500);
  }

})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
