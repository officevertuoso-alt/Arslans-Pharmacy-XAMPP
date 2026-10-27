<?php
/**
 * returns/sale_return.php
 *
 * Enhanced with:
 * - Search by invoice OR customer phone
 * - Credit sale warning
 * - Return history tab
 * - Net amount validation
 * - Stock restored display
 * - Refund method auto-suggestion
 * - Design system: CSS variables, table-card, status badges, smooth transitions
 */

require_once '../config/config.php';
require_once '../includes/return_functions.php';
require_once '../includes/sync_engine.php'; // for cache clearing after return

requireLogin();

$page_title = 'Sale Return';
$error  = '';
$sale   = null;
$saleItems = [];
$returnHistory = [];
$currentNet = 0;
$totalReturnedSoFar = 0;

// Get search term: can be invoice_no OR customer_phone
$searchTerm    = trim($_GET['search'] ?? '');
$invoiceNo     = trim($_GET['invoice_no'] ?? '');
$customerPhone = trim($_GET['customer_phone'] ?? '');

if (!empty($searchTerm)) {
    $db = db();
    $saleMeta = $db->getOne(
        "SELECT s.invoice_no, s.customer_phone
         FROM sales s
         WHERE (s.invoice_no = :term OR s.customer_phone = :term)
           AND s.payment_status IN ('paid', 'partial')
           AND s.status != 'voided'
         LIMIT 1",
        [':term' => $searchTerm]
    );
    if ($saleMeta) {
        $invoiceNo     = $saleMeta['invoice_no'];
        $customerPhone = $saleMeta['customer_phone'];
        $sale = getSaleForReturn($invoiceNo, false);
    }
} elseif (!empty($invoiceNo)) {
    $sale = getSaleForReturn($invoiceNo, false);
} elseif (!empty($customerPhone)) {
    $db = db();
    $saleMeta = $db->getOne(
        "SELECT s.invoice_no
         FROM sales s
         WHERE s.customer_phone = :phone
           AND s.payment_status IN ('paid', 'partial')
           AND s.status != 'voided'
         LIMIT 1",
        [':phone' => $customerPhone]
    );
    if ($saleMeta) {
        $invoiceNo = $saleMeta['invoice_no'];
        $sale = getSaleForReturn($invoiceNo, false);
    }
}

if ($sale) {
    if (!isset($db)) {
        $db = db();
    }

    $itemsRaw  = $sale['items'] ?? [];
    $saleItems = [];
    foreach ($itemsRaw as $item) {
        $soldQty         = (int)($item['sold_quantity'] ?? $item['quantity'] ?? 0);
        $alreadyReturned = (int)($item['already_returned'] ?? 0);
        $returnable      = max(0, $soldQty - $alreadyReturned);
        $saleItems[] = [
            'id'               => $item['sale_item_id'] ?? $item['id'],
            'product_name'     => $item['product_name'],
            'generic_name'     => $item['generic_name'] ?? '',
            'variant_value'    => $item['variant_value'] ?? '',
            'batch_number'     => $item['batch_number'] ?? '-',
            'quantity'         => $soldQty,
            'already_returned' => $alreadyReturned,
            'returnable'       => $returnable,
            'unit_price'       => (float)($item['unit_price']),
            'total'            => (float)($item['line_total'] ?? ($soldQty * $item['unit_price'])),
        ];
    }

    $returnHistory = $db->getAll(
        "SELECT sr.return_no, sr.total_amount, sr.reason, sr.created_at
         FROM sale_returns sr
         WHERE sr.sale_id = :sid
         ORDER BY sr.created_at DESC",
        [':sid' => $sale['id']]
    );

    $summary             = getSaleReturnSummary($sale['id']);
    $currentNet          = $summary['net_amount'];
    $totalReturnedSoFar  = $summary['total_returned_amount'];
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

        /* ── Stat / Summary cards ── */
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
            font-size   : 1.7rem;
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
        .stat-card .stat-icon {
            font-size : 2rem;
            opacity   : 0.14;
        }
        .stat-card.accent-danger  { border-left-color: #dc3545; }
        .stat-card.accent-success { border-left-color: #198754; }
        .stat-card.accent-info    { border-left-color: #0dcaf0; }
        .stat-card.accent-warning { border-left-color: #ffc107; }

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

        /* ── Status badges ── */
        .status-badge {
            padding       : 3px 10px;
            border-radius : 20px;
            font-size     : 0.72rem;
            font-weight   : 600;
            display       : inline-block;
        }
        .badge-returnable  { background: #d1fae5; color: #065f46; }
        .badge-returned    { background: #fee2e2; color: #991b1b; }
        .badge-partial     { background: #fff3cd; color: #856404; }
        .badge-zero        { background: #f1f5f9; color: #64748b; }

        /* ── Rows with zero returnable ── */
        .returnable-zero td { opacity: 0.5; background-color: var(--pharmacy-table-th, #f8f9fa) !important; }

        /* ── Credit warning ── */
        .credit-warning {
            background    : #fff3cd;
            border-left   : 4px solid #ffc107;
            padding       : 12px 16px;
            margin-bottom : 15px;
            border-radius : 8px;
            font-size     : 0.88rem;
        }

        /* ── Filter bar ── */
        .filter-bar {
            background    : var(--pharmacy-card-bg, #fff);
            border        : 1px solid var(--pharmacy-border, #e9ecef);
            border-radius : var(--card-border-radius, 12px);
            padding       : 18px 20px;
            margin-bottom : 20px;
            box-shadow    : 0 1px 4px rgba(0,0,0,0.04);
        }
        .filter-bar .form-control,
        .filter-bar .form-select {
            border-color  : var(--pharmacy-border, #dee2e6);
            color         : var(--pharmacy-text, #495057);
            background    : var(--pharmacy-card-bg, #fff);
            transition    : border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .filter-bar .form-control:focus,
        .filter-bar .form-select:focus {
            border-color : var(--pharmacy-primary, #0d6efd);
            box-shadow   : 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 15%, transparent);
        }

        /* ── Nav tabs ── */
        .nav-tabs .nav-link {
            color      : var(--pharmacy-text, #495057);
            transition : all 0.2s ease;
        }
        .nav-tabs .nav-link.active {
            color            : var(--pharmacy-primary, #0d6efd);
            font-weight      : 600;
            border-bottom    : 2px solid var(--pharmacy-primary, #0d6efd);
            background       : transparent;
            border-top       : none;
            border-left      : none;
            border-right     : none;
        }
        .nav-tabs { border-bottom: 2px solid var(--pharmacy-border, #dee2e6); }

        /* ── Return qty input ── */
        .return-qty {
            width       : 80px;
            text-align  : center;
            font-weight : 600;
            border-color: var(--pharmacy-border, #dee2e6);
            transition  : border-color 0.2s, box-shadow 0.2s;
        }
        .return-qty:focus {
            border-color : var(--pharmacy-primary, #0d6efd);
            box-shadow   : 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 15%, transparent);
        }

        /* ── Totals footer row ── */
        .tfoot-total td { background: var(--pharmacy-table-th, #f8f9fa) !important; }

        /* ── Buttons ── */
        .btn { transition: all 0.2s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        /* ── Toast ── */
        .toast-container { position: fixed; bottom: 20px; right: 20px; z-index: 1100; }/* modal animation — see css/style.css */

        /* ── Detail labels ── */
        .detail-label {
            font-size      : 0.7rem;
            text-transform : uppercase;
            color          : color-mix(in srgb, var(--pharmacy-text, #6c757d) 65%, transparent);
            letter-spacing : 0.06em;
            font-weight    : 600;
            margin-bottom  : 2px;
        }

        /* ── Page header ── */
        .page-header h4 { color: var(--pharmacy-heading, #212529); font-weight: 700; }

        /* ── Print ── */
        #return-slip { display: none; }
        @media print {
            body * { visibility: hidden; }
            #return-slip, #return-slip * { visibility: visible; }
            #return-slip { position: fixed; left: 0; top: 0; width: 80mm; font-family: monospace; font-size: 11px; }
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
    <div class="container-fluid">

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-start mb-4 page-header">
            <div>
                <h4 class="mb-1"><i class="fas fa-undo-alt me-2 text-warning"></i>Sale Return</h4>
                <p class="text-muted mb-0 small">Process customer returns and restore stock</p>
            </div>
            <a href="sale_return_list.php" class="btn btn-outline-secondary">
                <i class="fas fa-list me-2"></i>Return History
            </a>
        </div>

        <!-- Alert & Toast area -->
        <div id="alert_area"></div>
        <div class="toast-container" id="toastContainer"></div>

        <!-- Search Sale -->
        <div class="filter-bar">
            <div class="d-flex align-items-center gap-2 mb-3">
                <i class="fas fa-search text-primary"></i>
                <span class="fw-600" style="font-weight:600; color:var(--pharmacy-heading,#212529);">Find Sale Invoice</span>
            </div>
            <form method="GET" action="" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Invoice Number</label>
                    <input type="text" name="invoice_no" class="form-control"
                           placeholder="e.g. INV-0001"
                           value="<?php echo htmlspecialchars($invoiceNo); ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Customer Phone</label>
                    <input type="text" name="customer_phone" class="form-control"
                           placeholder="e.g. 0300-1234567"
                           value="<?php echo htmlspecialchars($customerPhone); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i>Search
                    </button>
                </div>
            </form>
            <small class="text-muted mt-1 d-block"><i class="fas fa-info-circle me-1"></i>Search by invoice number or customer phone number.</small>
        </div>

        <?php if ($sale): ?>

        <!-- Sale Summary Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card accent-success">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Grand Total</div>
                            <div class="stat-value"><?php echo formatCurrency($sale['grand_total']); ?></div>
                        </div>
                        <i class="fas fa-receipt stat-icon text-success"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card accent-info">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Paid Amount</div>
                            <div class="stat-value"><?php echo formatCurrency($sale['paid_amount']); ?></div>
                        </div>
                        <i class="fas fa-check-circle stat-icon text-info"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card accent-warning">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Already Returned</div>
                            <div class="stat-value"><?php echo formatCurrency($totalReturnedSoFar); ?></div>
                        </div>
                        <i class="fas fa-undo stat-icon text-warning"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card accent-danger">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Net Returnable</div>
                            <div class="stat-value"><?php echo formatCurrency($currentNet); ?></div>
                        </div>
                        <i class="fas fa-balance-scale stat-icon text-danger"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sale Details Card -->
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-receipt text-primary"></i>
                Sale Details
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-2 col-sm-4">
                        <div class="detail-label">Invoice No</div>
                        <strong class="text-primary"><?php echo htmlspecialchars($sale['invoice_no']); ?></strong>
                    </div>
                    <div class="col-md-2 col-sm-4">
                        <div class="detail-label">Customer</div>
                        <span><?php echo htmlspecialchars($sale['customer_name']); ?></span>
                    </div>
                    <div class="col-md-2 col-sm-4">
                        <div class="detail-label">Phone</div>
                        <span><?php echo htmlspecialchars($sale['customer_phone']); ?></span>
                    </div>
                    <div class="col-md-2 col-sm-4">
                        <div class="detail-label">Sale Date</div>
                        <span><?php echo formatDate($sale['sale_date']); ?></span>
                    </div>
                    <div class="col-md-2 col-sm-4">
                        <div class="detail-label">Payment Method</div>
                        <span class="status-badge" style="background:var(--pharmacy-table-th,#f8f9fa);color:var(--pharmacy-heading,#212529);">
                            <?php echo ucfirst($sale['payment_method'] ?? 'cash'); ?>
                        </span>
                    </div>
                    <div class="col-md-2 col-sm-4">
                        <div class="detail-label">Status</div>
                        <span class="status-badge badge-returnable">
                            <?php echo ucfirst($sale['payment_status'] ?? 'paid'); ?>
                        </span>
                    </div>
                </div>

                <?php if ($sale['is_credit'] == 1): ?>
                <div class="credit-warning mt-3">
                    <i class="fas fa-exclamation-triangle me-2 text-warning"></i>
                    <strong>Credit Sale Alert:</strong> Returning items will reduce the customer's outstanding credit balance
                    (current credit: <?php echo formatCurrency($sale['credit_total'] ?? 0); ?>).
                    The customer's credit will be adjusted automatically.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabs: Return Form | Return History -->
        <ul class="nav nav-tabs mb-3" id="returnTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="return-form-tab" data-bs-toggle="tab" data-bs-target="#returnForm" type="button" role="tab">
                    <i class="fas fa-undo-alt me-2"></i>Process Return
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#returnHistory" type="button" role="tab">
                    <i class="fas fa-history me-2"></i>Return History
                    <span class="badge rounded-pill ms-1" style="background:var(--pharmacy-primary,#0d6efd);font-size:0.7rem;">
                        <?php echo count($returnHistory); ?>
                    </span>
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Return Form Tab -->
            <div class="tab-pane fade show active" id="returnForm" role="tabpanel">
                <form id="sale_return_form">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="type" value="sale">
                    <input type="hidden" name="parent_id" value="<?php echo (int)$sale['id']; ?>">

                    <!-- Items Table -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <i class="fas fa-boxes text-primary"></i>
                            Select Items to Return
                            <span class="ms-auto small fw-normal text-muted"><?php echo count($saleItems); ?> item(s) in sale</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Product</th>
                                            <th>Batch</th>
                                            <th class="text-center">Qty Sold</th>
                                            <th class="text-center">Returned</th>
                                            <th class="text-center">Returnable</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-end">Line Total</th>
                                            <th class="text-center">Return Qty</th>
                                            <th class="text-end">Return Amt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $sr = 1; foreach ($saleItems as $item):
                                            $fullyReturned = ($item['returnable'] <= 0);
                                        ?>
                                        <tr class="<?php echo $fullyReturned ? 'returnable-zero' : ''; ?>">
                                            <td class="text-muted small"><?php echo $sr++; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                <?php if ($item['generic_name']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($item['generic_name']); ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($item['variant_value'])): ?>
                                                    <br><small class="text-muted">Variant: <?php echo htmlspecialchars($item['variant_value']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><code class="small text-muted"><?php echo htmlspecialchars($item['batch_number'] ?? '-'); ?></code></td>
                                            <td class="text-center fw-semibold"><?php echo $item['quantity']; ?></td>
                                            <td class="text-center">
                                                <?php if ($item['already_returned'] > 0): ?>
                                                    <span class="status-badge badge-returned"><?php echo $item['already_returned']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="status-badge <?php echo $fullyReturned ? 'badge-zero' : 'badge-returnable'; ?>">
                                                    <?php echo $item['returnable']; ?>
                                                </span>
                                            </td>
                                            <td class="text-end"><?php echo formatCurrency($item['unit_price']); ?></td>
                                            <td class="text-end"><?php echo formatCurrency($item['total']); ?></td>
                                            <td class="text-center">
                                                <input type="number" class="form-control form-control-sm return-qty"
                                                       data-item-id="<?php echo $item['id']; ?>"
                                                       data-max="<?php echo $item['returnable']; ?>"
                                                       data-price="<?php echo $item['unit_price']; ?>"
                                                       value="0" min="0" max="<?php echo $item['returnable']; ?>"
                                                       <?php echo $fullyReturned ? 'disabled' : ''; ?>>
                                            </td>
                                            <td class="text-end item-return-total fw-semibold text-danger">—</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="tfoot-total">
                                            <td colspan="9" class="text-end fw-bold py-3">
                                                <i class="fas fa-calculator me-2 text-muted"></i>Total Return Amount:
                                            </td>
                                            <td class="text-end py-3">
                                                <strong id="total_return_amount" class="fs-5 text-danger">Rs 0.00</strong>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Refund Method -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <i class="fas fa-money-bill-wave text-success"></i>
                            Refund Method
                        </div>
                        <div class="card-body">
                            <?php
                            $originalMethod = $sale['payment_method'] ?? 'cash';
                            $suggested = ($originalMethod == 'card' || $originalMethod == 'online') ? 'bank' : 'cash';
                            ?>
                            <div class="d-flex gap-4 flex-wrap">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="refund_method" id="rm_cash" value="cash"
                                           <?php echo ($suggested == 'cash') ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-semibold" for="rm_cash">
                                        <i class="fas fa-money-bill me-1 text-success"></i>Cash Refund
                                        <small class="text-muted fw-normal">(immediate)</small>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="refund_method" id="rm_credit_note" value="credit_note">
                                    <label class="form-check-label fw-semibold" for="rm_credit_note">
                                        <i class="fas fa-file-invoice me-1 text-info"></i>Credit Note
                                        <small class="text-muted fw-normal">(no cash movement)</small>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="refund_method" id="rm_bank" value="bank"
                                           <?php echo ($suggested == 'bank') ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-semibold" for="rm_bank">
                                        <i class="fas fa-university me-1 text-primary"></i>Bank Transfer
                                    </label>
                                </div>
                            </div>
                            <small class="text-muted mt-2 d-block">
                                <i class="fas fa-lightbulb me-1 text-warning"></i>
                                Suggested based on original payment: <strong><?php echo ucfirst($originalMethod); ?></strong>
                            </small>
                        </div>
                    </div>

                    <!-- Return Reason -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <i class="fas fa-comment-alt text-secondary"></i>
                            Return Reason <span class="text-danger ms-1">*</span>
                        </div>
                        <div class="card-body">
                            <textarea name="overall_reason" id="overall_reason" class="form-control" rows="3"
                                      placeholder="Enter reason for return (e.g., Expired product, Customer dissatisfaction, Damaged, Wrong product)"
                                      required></textarea>
                        </div>
                    </div>

                    <div class="text-end mb-2">
                        <button type="button" id="btn_process_return" class="btn btn-warning btn-lg px-5">
                            <i class="fas fa-undo-alt me-2"></i>Process Return
                        </button>
                    </div>
                </form>
            </div>

            <!-- Return History Tab -->
            <div class="tab-pane fade" id="returnHistory" role="tabpanel">
                <div class="card-custom">
                    <div class="card-header-custom">
                        <i class="fas fa-history text-primary"></i>
                        Previous Returns for this Sale
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($returnHistory)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                No previous returns found for this sale.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Return No</th>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($returnHistory as $ret): ?>
                                        <tr>
                                            <td>
                                                <a href="sale_return_view.php?id=<?php echo $ret['id'] ?? ''; ?>" class="fw-semibold text-decoration-none" style="color:var(--pharmacy-primary,#0d6efd);">
                                                    <?php echo htmlspecialchars($ret['return_no']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo formatDate($ret['created_at'], true); ?></td>
                                            <td><span class="fw-semibold text-danger"><?php echo formatCurrency($ret['total_amount']); ?></span></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars($ret['reason']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden print slip -->
        <div id="return-slip"></div>

        <?php else: ?>
        <!-- Empty state when no sale found -->
        <?php if (!empty($invoiceNo) || !empty($customerPhone) || !empty($searchTerm)): ?>
        <div class="card-custom">
            <div class="card-body text-center py-5">
                <i class="fas fa-search fa-3x mb-3 text-muted opacity-50"></i>
                <h5 class="text-muted">No matching sale found</h5>
                <p class="text-muted small">Please check the invoice number or phone number and try again.<br>Only paid or partial sales can be returned.</p>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="returnSuccessModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="fas fa-check-circle me-2"></i>Return Processed</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <h5 id="modal_return_no" class="mb-1">—</h5>
                    <p class="text-muted mb-1">Refund Amount</p>
                    <h3 id="modal_return_amount" class="text-danger fw-bold">Rs 0.00</h3>
                    <hr>
                    <div class="alert alert-info py-2 px-3 mb-2 small text-start">
                        <i class="fas fa-book-open me-1"></i>
                        <strong>Cashbook Updated:</strong> A debit entry has been recorded for this refund.
                    </div>
                    <div id="modal_stock_restored" class="small text-start"></div>
                    <div class="mt-3">
                        <small class="text-muted">Redirecting to Return List in <strong id="redirect_countdown">3</strong>s…</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-center gap-2">
                <button class="btn btn-outline-secondary" id="btn_close_modal" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-outline-primary" id="btn_print_slip"><i class="fas fa-print me-2"></i>Print Receipt</button>
                <button class="btn btn-primary" id="btn_goto_list"><i class="fas fa-list me-2"></i>View All Returns</button>
            </div>
        </div>
    </div>
</div>

<script>
// Live total calculation with net amount validation
$(document).on('input', '.return-qty', function () {
    let $inp  = $(this);
    let max   = parseInt($inp.data('max')) || 0;
    let price = parseFloat($inp.data('price')) || 0;
    let qty   = parseInt($inp.val()) || 0;
    if (qty < 0) qty = 0;
    if (qty > max) qty = max;
    $inp.val(qty);

    let lineTotal = qty * price;
    $inp.closest('tr').find('.item-return-total').text(qty > 0 ? 'Rs ' + lineTotal.toFixed(2) : '—');

    let grand = 0;
    $('.return-qty').each(function () {
        grand += (parseInt($(this).val()) || 0) * (parseFloat($(this).data('price')) || 0);
    });
    $('#total_return_amount').text('Rs ' + grand.toFixed(2));

    let currentNet = <?php echo json_encode($currentNet); ?>;
    if (grand > currentNet) {
        $('#total_return_amount').css('color','#dc3545');
        $('#btn_process_return').prop('disabled', true).attr('title', 'Return amount exceeds net sale amount');
    } else {
        $('#total_return_amount').css('color','');
        $('#btn_process_return').prop('disabled', false).removeAttr('title');
    }
});

// Toast helper
function showToast(message, type = 'success') {
    let bg = type === 'success' ? 'bg-success' : (type === 'danger' ? 'bg-danger' : 'bg-warning');
    let toastHtml = `<div class="toast align-items-center text-white ${bg} border-0" role="alert" data-bs-autohide="true" data-bs-delay="5000">
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>`;
    $('#toastContainer').append(toastHtml);
    let toastEl = $('#toastContainer .toast').last();
    new bootstrap.Toast(toastEl).show();
    toastEl.on('hidden.bs.toast', function() { $(this).remove(); });
}

// AJAX submission
$('#btn_process_return').on('click', function () {
    let reason = $.trim($('#overall_reason').val());
    if (!reason) {
        showToast('Please enter a return reason.', 'danger');
        $('#overall_reason').focus();
        return;
    }

    let items = [];
    $('.return-qty').each(function () {
        let qty = parseInt($(this).val()) || 0;
        if (qty > 0) {
            items.push({ item_id: $(this).data('item-id'), quantity: qty });
        }
    });

    if (items.length === 0) {
        showToast('Please enter a return quantity for at least one item.', 'danger');
        return;
    }

    let totalReturn = parseFloat($('#total_return_amount').text().replace('Rs ', '')) || 0;
    let currentNet  = <?php echo json_encode($currentNet); ?>;
    if (totalReturn > currentNet) {
        showToast('Return amount cannot exceed net sale amount after previous returns.', 'danger');
        return;
    }

    if (!confirm(`Process this return? Total refund: Rs ${totalReturn.toFixed(2)}. Stock will be restored.`)) return;

    let $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Processing…');

    $.ajax({
        url: '../ajax/process_return.php',
        method: 'POST',
        data: {
            csrf_token    : $('input[name="csrf_token"]').val(),
            type          : 'sale',
            parent_id     : $('input[name="parent_id"]').val(),
            items         : JSON.stringify(items),
            overall_reason: reason,
            refund_method : $('input[name="refund_method"]:checked').val() || 'cash'
        },
        dataType: 'json',
        success: function (r) {
            $btn.prop('disabled', false).html('<i class="fas fa-undo-alt me-2"></i>Process Return');
            if (r && r.success) {
                showToast(`✅ Return ${r.return_no} processed. Stock restored. Cashbook updated.`, 'success');
                $('#modal_return_no').text(r.return_no);
                $('#modal_return_amount').text('Rs ' + parseFloat(r.return_amount).toFixed(2));

                let stockHtml = '<strong>Stock Restored:</strong><ul class="mb-0 mt-1">';
                if (r.stock_restored && r.stock_restored.length) {
                    r.stock_restored.forEach(s => {
                        stockHtml += `<li>${s.product_name} (Batch: ${s.batch_no}) — ${s.qty_restored} units → New qty: ${s.new_batch_qty}</li>`;
                    });
                } else {
                    stockHtml += '<li>No stock details returned</li>';
                }
                stockHtml += '</ul>';
                $('#modal_stock_restored').html(stockHtml);

                new bootstrap.Modal(document.getElementById('returnSuccessModal')).show();

                $('#alert_area').html(`<div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i> <strong>${r.return_no}</strong> — Return of <strong>Rs ${parseFloat(r.return_amount).toFixed(2)}</strong> processed successfully.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>`);

                $('#sale_return_form input, #sale_return_form textarea, #btn_process_return').prop('disabled', true);
            } else {
                showToast('Error: ' + (r && r.message ? r.message : 'Unknown error'), 'danger');
            }
        },
        error: function (xhr) {
            $btn.prop('disabled', false).html('<i class="fas fa-undo-alt me-2"></i>Process Return');
            let msg = 'Server error. Please check logs.';
            try { let resp = JSON.parse(xhr.responseText); msg = resp.message || msg; } catch(e) {}
            showToast(msg, 'danger');
        }
    });
});

$('#btn_print_slip').on('click', function () { window.print(); });
$('#btn_goto_list').on('click', function () { window.location.href = 'sale_return_list.php'; });
$('#btn_close_modal').on('click', function () { window.location.href = 'sale_return_list.php'; });

// 3s countdown redirect on modal open
$('#returnSuccessModal').on('shown.bs.modal', function () {
    let secs = 3;
    let timer = setInterval(function () {
        secs--;
        $('#redirect_countdown').text(secs);
        if (secs <= 0) { clearInterval(timer); window.location.href = 'sale_return_list.php'; }
    }, 1000);
});

// Build print slip on modal show
$('#returnSuccessModal').on('shown.bs.modal', function () {
    let returnNo = $('#modal_return_no').text();
    let amount   = $('#modal_return_amount').text().replace('Rs ', '');
    let reason   = $('#overall_reason').val();
    let rows     = '';
    $('.return-qty').each(function() {
        let qty = parseInt($(this).val());
        if (qty > 0) {
            let name  = $(this).closest('tr').find('td:nth-child(2) strong').text();
            let price = parseFloat($(this).data('price'));
            rows += `<tr><td>${name}</td><td style="text-align:center">${qty}</td><td style="text-align:right">Rs ${(qty * price).toFixed(2)}</td></tr>`;
        }
    });
    let now = new Date().toLocaleString('en-PK');
    $('#return-slip').html(`<div style="font-family:monospace;font-size:12px;padding:8px;">
        <div style="text-align:center;border-bottom:1px dashed #999;padding-bottom:6px;margin-bottom:6px;">
        <strong style="font-size:15px;">SALE RETURN</strong><br>${returnNo}<br><small>${now}</small></div>
        <table style="width:100%;border-collapse:collapse;margin:8px 0;font-size:11px;">
        <tr style="border-bottom:1px solid #ddd"><th>Item</th><th>Qty</th><th style="text-align:right">Amount</th></tr>
        ${rows}</table>
        <div style="border-top:1px dashed #999;padding-top:5px;">
        <div style="display:flex;justify-content:space-between;font-weight:bold;font-size:14px">
        <span>REFUND TOTAL:</span><span>${amount}</span></div>
        <div style="margin-top:4px;font-size:10px">Reason: ${reason}</div></div>
        <div style="text-align:center;margin-top:8px;font-size:10px;border-top:1px dashed #999;padding-top:6px;">Thank you</div>
        </div>`);
});

// Tooltip init
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
