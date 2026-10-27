<?php
require_once '../config/config.php';
require_once '../includes/purchase_functions.php';

global $pdo;
if (empty($pdo) && !empty($conn)) { $pdo = $conn; }
if (empty($pdo) && !empty($db))   { $pdo = $db; }

requirePharmacist();

$page_title = 'New Purchase - Pharmacy Management System';
$error   = '';
$success = '';
$suppliers = getAllSuppliers();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_purchase'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $items    = [];
    $subtotal = 0;
    $valError = '';

    $product_ids       = $_POST['product_id']        ?? [];
    $batch_numbers     = $_POST['batch_number']      ?? [];
    $units_per_pack    = $_POST['units_per_pack']    ?? [];
    $quantity_of_packs = $_POST['quantity_of_packs'] ?? [];
    $purchase_prices   = $_POST['purchase_price']    ?? [];
    $selling_prices    = $_POST['selling_price']     ?? [];
    $expiry_dates      = $_POST['expiry_date']       ?? [];
    $item_disc_types   = $_POST['item_disc_type']    ?? [];
    $item_disc_vals    = $_POST['item_disc_val']     ?? [];
    $item_tax_types    = $_POST['item_tax_type']     ?? [];
    $item_tax_vals     = $_POST['item_tax_val']      ?? [];

    for ($i = 0; $i < count($product_ids); $i++) {
        if (empty($product_ids[$i])) continue;

        /* ── Validation ── */
        if ((float)$units_per_pack[$i] <= 0) {
            $valError = 'Units per pack must be greater than 0 for all items.'; break;
        }
        if ((float)$quantity_of_packs[$i] <= 0) {
            $valError = 'Quantity of packs must be greater than 0 for all items.'; break;
        }
        if ((float)$purchase_prices[$i] <= 0) {
            $valError = 'Purchase price must be greater than 0 for all items.'; break;
        }
        if ((float)$selling_prices[$i] <= 0) {
            $valError = 'Sale price must be greater than 0 for all items. Please fill sale price.'; break;
        }
        // Expiry date validation (will also be done server-side)
        if (empty($expiry_dates[$i])) {
            $valError = 'Expiry date is required for all products.'; break;
        }
        $expTimestamp = strtotime($expiry_dates[$i]);
        if ($expTimestamp === false || $expTimestamp < strtotime('today')) {
            $valError = 'Expiry date must be today or a future date for all products.'; break;
        }

        $total_units = (float)$units_per_pack[$i] * (float)$quantity_of_packs[$i];
        $item_gross  = (float)$purchase_prices[$i] * $total_units;

        $discType = $item_disc_types[$i] ?? 'rs';
        $discVal  = (float)($item_disc_vals[$i] ?? 0);
        $itemDisc = $discType === 'pct' ? ($item_gross * $discVal / 100) : $discVal;

        $taxType = $item_tax_types[$i] ?? 'rs';
        $taxVal  = (float)($item_tax_vals[$i] ?? 0);
        $itemTax = $taxType === 'pct' ? (($item_gross - $itemDisc) * $taxVal / 100) : $taxVal;

        $item_total = $item_gross - $itemDisc + $itemTax;
        $subtotal  += $item_total;

        $items[] = [
            'product_id'        => $product_ids[$i],
            'batch_number'      => $batch_numbers[$i],
            'units_per_pack'    => $units_per_pack[$i],
            'quantity_of_packs' => $quantity_of_packs[$i],
            'purchase_price'    => $purchase_prices[$i],
            'selling_price'     => $selling_prices[$i],
            'expiry_date'       => $expiry_dates[$i],
            'total_quantity'    => $total_units,
            'total_cost'        => $item_total,
            'item_discount'     => $itemDisc,
            'item_tax'          => $itemTax,
        ];
    }

    if ($valError) {
        $error = $valError;
    } elseif (count($items) === 0) {
        $error = 'Please add at least one product to purchase.';
    } else {
        $paymentStatus = $_POST['payment_status'] ?? 'pending';
        $paidAmount    = 0;

        if ($paymentStatus === 'paid') {
            $odType      = $_POST['overall_disc_type'] ?? 'rs';
            $odVal       = (float)($_POST['overall_disc_val'] ?? 0);
            $overallDisc = $odType === 'pct' ? ($subtotal * $odVal / 100) : $odVal;
            $otType      = $_POST['overall_tax_type'] ?? 'rs';
            $otVal       = (float)($_POST['overall_tax_val'] ?? 0);
            $overallTax  = $otType === 'pct' ? (($subtotal - $overallDisc) * $otVal / 100) : $otVal;
            $grandTotal  = $subtotal - $overallDisc + $overallTax;
            $paidAmount  = $grandTotal;
        } elseif ($paymentStatus === 'partial') {
            $paidAmount = (float)($_POST['paid_amount'] ?? 0);
            if ($paidAmount <= 0) {
                $error = 'Partial payment: please enter the paid amount.';
            }
        } elseif ($paymentStatus === 'pending') {
            $paidAmount = 0;
        }

        if (!$error) {
            $odType      = $_POST['overall_disc_type'] ?? 'rs';
            $odVal       = (float)($_POST['overall_disc_val'] ?? 0);
            $overallDisc = $odType === 'pct' ? ($subtotal * $odVal / 100) : $odVal;
            $otType      = $_POST['overall_tax_type'] ?? 'rs';
            $otVal       = (float)($_POST['overall_tax_val'] ?? 0);
            $overallTax  = $otType === 'pct' ? (($subtotal - $overallDisc) * $otVal / 100) : $otVal;
            $grandTotal  = $subtotal - $overallDisc + $overallTax;

            if ($paymentStatus === 'paid') $paidAmount = $grandTotal;

            $purchaseData = [
                'supplier_id'    => $_POST['supplier_id'],
                'purchase_date'  => $_POST['purchase_date'],
                'subtotal'       => $subtotal,
                'discount'       => $overallDisc,
                'tax'            => $overallTax,
                'total'          => $grandTotal,
                'payment_status' => $paymentStatus,
                'paid_amount'    => $paidAmount,
                'notes'          => $_POST['notes'] ?? null,
            ];

            $purchaseId = addPurchase($purchaseData, $items);
            if ($purchaseId) {
                $success = 'Purchase added successfully!';
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => $success, 'redirect' => 'view.php?id=' . $purchaseId]);
                    exit;
                }
                echo '<script>setTimeout(function(){ window.location.href="view.php?id=' . $purchaseId . '"; },1500);</script>';
            } else {
                $error = 'Failed to add purchase. Please check database tables exist (run setup_tables.sql).';
            }
        }
    }

    if ($isAjax && (isset($error) && $error || isset($valError) && $valError)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $error ?: $valError]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>


<style>
body{background:#f0f2f5;}
.main-content{margin-left: var(--sb-w, 260px); transition: margin-left .25s ease;padding:20px;min-height:100vh;}
.card-custom{background:#fff;border-radius:15px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:20px;}
.card-header-custom{background:#fff;border-bottom:1px solid #eef2f6;padding:15px 20px;border-radius:15px 15px 0 0;font-weight:600;}
.btn-submit{background:linear-gradient(135deg,#28a745,#20c997);color:#fff;border:none;padding:12px 30px;border-radius:10px;font-weight:600;}
.btn-submit:hover{background:linear-gradient(135deg,#1e7e34,#1aa179);color:#fff;}
.search-container{position:relative;margin-bottom:20px;}
.search-results{position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-radius:10px;max-height:380px;overflow-y:auto;z-index:1050;box-shadow:0 8px 25px rgba(0,0,0,.15);display:none;}
.product-search-result{padding:11px 15px;cursor:pointer;border-bottom:1px solid #eef2f6;transition:background .15s;}
.product-search-result:last-child{border-bottom:none;}
.product-search-result:hover{background:#e3f2fd;}
.psr-name{font-weight:600;font-size:14px;}
.psr-meta{font-size:11px;color:#888;margin-top:3px;}
.psr-meta .psr-pill{display:inline-block;background:#f0f4ff;border-radius:4px;padding:1px 6px;margin-right:5px;color:#555;}
.table-cart{font-size:12px;}
.table-cart th{background:#f8f9fa;font-weight:600;white-space:nowrap;vertical-align:middle;}
.table-cart td{vertical-align:middle;}
.cart-item-total{font-weight:700;color:#1a7a4a;white-space:nowrap;}
.dt-combo{display:flex;gap:3px;align-items:center;}
.dt-combo select{width:58px;flex-shrink:0;font-size:11px;padding:3px 2px;}
.dt-combo input{min-width:0;flex:1;font-size:12px;}
.totals-card{background:#f8fffe;border:1px solid #d1fae5;border-radius:12px;padding:18px 20px;}
.tot-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #e5f0ea;font-size:14px;}
.tot-row:last-child{border-bottom:none;}
.tot-grand{font-size:20px;font-weight:700;color:#1a7a4a;}
.ov-combo{display:flex;gap:5px;align-items:center;}
.ov-combo select{width:68px;font-size:13px;}
.ov-combo input{width:100px;font-size:13px;}
.ov-display{min-width:80px;text-align:right;font-weight:700;}
.payment-info-box{border-radius:10px;padding:14px 18px;margin-top:10px;font-size:14px;}
.pib-paid{background:#d4edda;border:1px solid #c3e6cb;color:#155724;}
.pib-partial{background:#fff3cd;border:1px solid #ffeeba;color:#856404;}
.pib-pending{background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;}
input.required-error{border-color:#dc3545 !important;}
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }}
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

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">New Purchase</h4>
        <p class="text-muted mb-0">Add stock with batch and expiry tracking</p>
    </div>
    <a href="list.php" class="btn btn-outline-secondary"><i class="fas fa-history me-2"></i> Purchase History</a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div id="addPurchaseAlert" class="mb-3"></div>
<form method="POST" id="purchaseForm" onsubmit="return validateForm()"
      data-pharm-form
      data-success-msg="Purchase added successfully!"
      data-loading-text="Saving Purchase…"
      data-alert-target="#addPurchaseAlert"
      novalidate>

<!-- Purchase Info -->
<div class="card-custom">
    <div class="card-header-custom"><i class="fas fa-info-circle me-2 text-primary"></i> Purchase Information</div>
    <div class="card-body p-4">
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Supplier <span class="text-danger">*</span></label>
                <div class="input-group">
                    <select name="supplier_id" id="supplier_id" class="form-select" required>
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>">
                                <?php echo htmlspecialchars($s['supplier_name']); ?> (<?php echo $s['phone']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a href="../suppliers/add.php" target="_blank" class="btn btn-outline-primary" title="Add Supplier">
                        <i class="fas fa-plus-circle"></i>
                    </a>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Purchase Date <span class="text-danger">*</span></label>
                <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Payment Status <span class="text-danger">*</span></label>
                <select name="payment_status" class="form-select" id="payment_status" onchange="togglePaymentFields()">
                    <option value="paid">Paid (Full)</option>
                    <option value="partial">Partial Payment</option>
                    <option value="pending">Pending (Credit)</option>
                </select>
            </div>
            <div class="col-md-2 mb-3" id="paid_amount_div" style="display:none;">
                <label class="form-label">Paid Amount (Rs) <span class="text-danger">*</span></label>
                <input type="number" step="0.01" name="paid_amount" id="paid_amount_input" class="form-control" value="" min="0.01" placeholder="0.00">
            </div>
        </div>

        <div id="payment_info_box" class="payment-info-box pib-paid">
            <i class="fas fa-check-circle me-2"></i>
            <span id="payment_info_text">Full payment will be recorded. Supplier balance will remain zero.</span>
        </div>

        <div class="row mt-3">
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Any additional notes…"></textarea>
            </div>
        </div>
    </div>
</div>

<!-- Products Card -->
<div class="card-custom">
    <div class="card-header-custom"><i class="fas fa-cart-plus me-2 text-primary"></i> Add Products</div>
    <div class="card-body p-4">

        <div class="search-container">
            <label class="form-label fw-semibold">
                Search &amp; Add Product <span class="text-danger">*</span>
                <small class="text-muted fw-normal ms-2">— type name, brand, generic or code</small>
            </label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="fas fa-search text-primary"></i></span>
                <input type="text" id="product_search" class="form-control" placeholder="Search product…" autocomplete="off">
                <button class="btn btn-outline-secondary" type="button" onclick="clearSearch()"><i class="fas fa-times"></i></button>
            </div>
            <div id="search_results" class="search-results"></div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-cart align-middle">
                <thead>
                    <tr>
                        <th style="min-width:150px">Product</th>
                        <th style="min-width:90px">Batch No.</th>
                        <th style="min-width:65px">Units/<br>Pack</th>
                        <th style="min-width:65px">Qty of<br>Packs</th>
                        <th style="min-width:115px">Purchase<br>Price/Unit (Rs) <span class="text-danger">*</span></th>
                        <th style="min-width:115px">Sale<br>Price/Unit (Rs) <span class="text-danger">*</span></th>
                        <th style="min-width:110px">Expiry Date <span class="text-danger">*</span></th>
                        <th style="min-width:155px">Discount <small class="text-muted fw-normal d-block">per item (%/Rs)</small></th>
                        <th style="min-width:155px">Tax <small class="text-muted fw-normal d-block">per item (%/Rs)</small></th>
                        <th style="min-width:95px">Item Total</th>
                        <th style="width:42px"></th>
                    </tr>
                </thead>
                <tbody id="cart_body">
                    <tr id="no_items_row">
                        <td colspan="11" class="text-center text-muted py-5">
                            <i class="fas fa-box-open fa-3x mb-2 d-block"></i>
                            No products added yet. Search and click a product above.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Grand total -->
        <div id="overall_section" style="display:none;">
            <div class="row justify-content-end mt-2">
                <div class="col-md-6 col-lg-5">
                    <div class="totals-card">
                        <div class="tot-row">
                            <span class="text-muted">Items Subtotal</span>
                            <strong id="disp_subtotal">Rs 0.00</strong>
                        </div>
                        <div class="tot-row">
                            <span class="text-muted">Overall Discount</span>
                            <div class="d-flex align-items-center gap-2">
                                <div class="ov-combo">
                                    <select name="overall_disc_type" id="overall_disc_type" class="form-select form-select-sm" onchange="recalcGrand()">
                                        <option value="rs">Rs</option>
                                        <option value="pct">%</option>
                                    </select>
                                    <input type="number" step="0.01" min="0" name="overall_disc_val" id="overall_disc_val" class="form-control form-control-sm" value="0" oninput="recalcGrand()">
                                </div>
                                <span id="disp_overall_disc" class="text-danger fw-bold ov-display">- Rs 0.00</span>
                            </div>
                        </div>
                        <div class="tot-row">
                            <span class="text-muted">Overall Tax</span>
                            <div class="d-flex align-items-center gap-2">
                                <div class="ov-combo">
                                    <select name="overall_tax_type" id="overall_tax_type" class="form-select form-select-sm" onchange="recalcGrand()">
                                        <option value="rs">Rs</option>
                                        <option value="pct">%</option>
                                    </select>
                                    <input type="number" step="0.01" min="0" name="overall_tax_val" id="overall_tax_val" class="form-control form-control-sm" value="0" oninput="recalcGrand()">
                                </div>
                                <span id="disp_overall_tax" class="text-warning fw-bold ov-display">+ Rs 0.00</span>
                            </div>
                        </div>
                        <div class="tot-row tot-grand">
                            <span>Grand Total</span>
                            <span id="disp_grand">Rs 0.00</span>
                        </div>

                        <div id="payment_summary_box" class="mt-3 p-3 rounded" style="background:#eafff4;border:1px solid #b2dfdb;display:none;">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Paid Amount:</span>
                                <strong class="text-success" id="sum_paid">Rs 0.00</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Due / Balance:</span>
                                <strong class="text-danger" id="sum_due">Rs 0.00</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Submit -->
<div class="text-end mb-4">
    <button type="submit" name="submit_purchase" data-submit-btn class="btn btn-submit btn-lg">
        <i class="fas fa-save me-2"></i> Complete Purchase
    </button>
</div>

</form>
</div>
</div>



<script>
var cartItems = [];
var itemIndex = 0;
var srchTO;

/* ── PAYMENT TOGGLE ── */
function togglePaymentFields() {
    var status = $('#payment_status').val();
    var infoBox = $('#payment_info_box');
    var infoText = $('#payment_info_text');

    $('#paid_amount_div').toggle(status === 'partial');

    infoBox.removeClass('pib-paid pib-partial pib-pending');
    if (status === 'paid') {
        infoBox.addClass('pib-paid');
        infoText.html('<i class="fas fa-check-circle me-2"></i>Full payment will be recorded. Supplier balance will remain <strong>zero</strong>.');
    } else if (status === 'partial') {
        infoBox.addClass('pib-partial');
        infoText.html('<i class="fas fa-exclamation-circle me-2"></i>Partial payment. Remaining balance will be added to <strong>supplier account</strong>.');
    } else {
        infoBox.addClass('pib-pending');
        infoText.html('<i class="fas fa-clock me-2"></i>No payment now. Full amount will be added to <strong>supplier balance (credit)</strong>.');
    }
    recalcGrand();
}

/* ── SEARCH ── */
$('#product_search').on('input', function () {
    clearTimeout(srchTO);
    var q = $(this).val().trim();
    if (!q) { $('#search_results').hide().html(''); return; }
    if (q.length < 2) {
        $('#search_results').html('<div class="p-3 text-center text-muted small">Type at least 2 characters…</div>').show();
        return;
    }
    $('#search_results').html('<div class="p-3 text-center"><span class="spinner-border spinner-border-sm text-primary me-2"></span> Searching…</div>').show();
    srchTO = setTimeout(function () {
        $.ajax({
            url: '../ajax/search_product_purchase.php',
            data: { search: q },
            success: function (html) { $('#search_results').html(html).show(); },
            error:   function ()     { $('#search_results').html('<div class="p-3 text-danger small text-center">Error. Try again.</div>').show(); }
        });
    }, 250);
});

function clearSearch() { $('#product_search').val(''); $('#search_results').hide().html(''); }
$(document).on('click', function (e) {
    if (!$(e.target).closest('#product_search,#search_results').length) $('#search_results').hide();
});
$('#product_search').on('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });

/* ── ADD TO CART ── */
function addToCart(productId) {
    $.ajax({
        url: '../ajax/get_product_details.php',
        data: { product_id: productId },
        dataType: 'json',
        success: function (p) {
            if (p.error) { Notify.error(p.error); return; }

            var dU = p.units_per_pack    || 1;
            var dQ = p.quantity_of_packs || 1;
            var dP = p.purchase_price    || 0;
            var dS = p.sale_price        || 0;

            var d = new Date();
            var bn = 'BATCH-' + d.getFullYear() +
                     String(d.getMonth()+1).padStart(2,'0') +
                     String(d.getDate()).padStart(2,'0') +
                     '-' + Math.random().toString(36).substr(2,5).toUpperCase();

            var idx = itemIndex;
            var spClass = dS <= 0 ? ' required-error' : '';
            // Today's date in YYYY-MM-DD for min attribute
            var today = new Date().toISOString().split('T')[0];

            var row = '<tr id="cart_row_' + idx + '">' +
                '<td>' +
                    '<strong>' + escHtml(p.product_name) + '</strong>' +
                    '<br><small class="text-muted fst-italic">' + escHtml(p.generic_name || '') +
                        (p.brand_name ? ' (' + escHtml(p.brand_name) + ')' : '') + '</small>' +
                    '<input type="hidden" name="product_id[]" value="' + p.id + '">' +
                '</td>' +
                '<td><input type="text" name="batch_number[]" class="form-control form-control-sm" value="' + bn + '" required></td>' +
                '<td><input type="number" name="units_per_pack[]" class="form-control form-control-sm units-per-pack" data-index="' + idx + '" value="' + dU + '" min="1" required></td>' +
                '<td><input type="number" name="quantity_of_packs[]" class="form-control form-control-sm qty-of-packs" data-index="' + idx + '" value="' + dQ + '" min="1" required></td>' +
                '<td><input type="number" step="0.01" name="purchase_price[]" class="form-control form-control-sm purchase-price" data-index="' + idx + '" value="' + dP + '" min="0.01" required placeholder="Required"></td>' +
                '<td><input type="number" step="0.01" name="selling_price[]" class="form-control form-control-sm selling-price' + spClass + '" data-index="' + idx + '" value="' + dS + '" min="0.01" required placeholder="Required" title="Sale price is required"></td>' +
                '<td><input type="date" name="expiry_date[]" class="form-control form-control-sm" required min="' + today + '"></td>' +
                '<td><div class="dt-combo">' +
                    '<select name="item_disc_type[]" class="form-select form-select-sm item-disc-type" data-index="' + idx + '"><option value="rs">Rs</option><option value="pct">%</option></select>' +
                    '<input type="number" step="0.01" min="0" name="item_disc_val[]" class="form-control form-control-sm item-disc-val" data-index="' + idx + '" value="0">' +
                '</div></td>' +
                '<td><div class="dt-combo">' +
                    '<select name="item_tax_type[]" class="form-select form-select-sm item-tax-type" data-index="' + idx + '"><option value="rs">Rs</option><option value="pct">%</option></select>' +
                    '<input type="number" step="0.01" min="0" name="item_tax_val[]" class="form-control form-control-sm item-tax-val" data-index="' + idx + '" value="0">' +
                '</div></td>' +
                '<td class="cart-item-total" id="item_total_' + idx + '">Rs 0.00</td>' +
                '<td><button type="button" class="btn btn-danger btn-sm" onclick="removeFromCart(' + idx + ')"><i class="fas fa-trash"></i></button></td>' +
            '<tr>';

            if ($('#no_items_row').length) $('#cart_body').html(row);
            else $('#cart_body').append(row);

            $('#no_items_row').remove();
            $('#overall_section').show();
            cartItems.push({ index: idx, product_id: p.id });
            updateItemTotal(idx);
            itemIndex++;
            clearSearch();

            if (dS <= 0) {
                setTimeout(function(){
                    $('#cart_row_' + idx + ' .selling-price').focus();
                    Notify.warning('Sale price is required! Please enter sale price for: ' + p.product_name);
                }, 100);
            }
        },
        error: function () { Notify.error('Error loading product details. Try again.'); }
    });
}

/* ── ITEM TOTAL ── */
function updateItemTotal(idx) {
    var units  = parseFloat($('#cart_row_' + idx + ' .units-per-pack').val())  || 0;
    var packs  = parseFloat($('#cart_row_' + idx + ' .qty-of-packs').val())    || 0;
    var price  = parseFloat($('#cart_row_' + idx + ' .purchase-price').val())  || 0;
    var gross  = units * packs * price;

    var dType = $('#cart_row_' + idx + ' .item-disc-type').val() || 'rs';
    var dVal  = parseFloat($('#cart_row_' + idx + ' .item-disc-val').val())    || 0;
    var disc  = dType === 'pct' ? (gross * dVal / 100) : dVal;

    var tType = $('#cart_row_' + idx + ' .item-tax-type').val() || 'rs';
    var tVal  = parseFloat($('#cart_row_' + idx + ' .item-tax-val').val())     || 0;
    var tax   = tType === 'pct' ? ((gross - disc) * tVal / 100) : tVal;

    var total = gross - disc + tax;
    $('#item_total_' + idx).text('Rs ' + total.toFixed(2));
    recalcGrand();
}

/* ── GRAND TOTAL ── */
function recalcGrand() {
    var sub = 0;
    $('.cart-item-total').each(function () { sub += parseFloat($(this).text().replace('Rs ','')) || 0; });

    var odT = $('#overall_disc_type').val() || 'rs';
    var odV = parseFloat($('#overall_disc_val').val()) || 0;
    var od  = odT === 'pct' ? (sub * odV / 100) : odV;

    var otT = $('#overall_tax_type').val() || 'rs';
    var otV = parseFloat($('#overall_tax_val').val()) || 0;
    var ot  = otT === 'pct' ? ((sub - od) * otV / 100) : otV;

    var grand = sub - od + ot;

    $('#disp_subtotal').text('Rs ' + sub.toFixed(2));
    $('#disp_overall_disc').text('- Rs ' + od.toFixed(2));
    $('#disp_overall_tax').text('+ Rs ' + ot.toFixed(2));
    $('#disp_grand').text('Rs ' + grand.toFixed(2));

    var status = $('#payment_status').val();
    var paid   = 0;
    if (status === 'paid') paid = grand;
    else if (status === 'partial') paid = parseFloat($('#paid_amount_input').val()) || 0;
    var due = grand - paid;

    if (cartItems.length > 0) {
        $('#payment_summary_box').show();
        $('#sum_paid').text('Rs ' + paid.toFixed(2));
        $('#sum_due').text('Rs ' + (due < 0 ? 0 : due).toFixed(2));
    }
}

/* ── REMOVE ── */
function removeFromCart(idx) {
    $('#cart_row_' + idx).remove();
    cartItems = cartItems.filter(function (i) { return i.index !== idx; });
    if (!cartItems.length) {
        $('#cart_body').html('<tr id="no_items_row"><td colspan="11" class="text-center text-muted py-5"><i class="fas fa-box-open fa-3x mb-2 d-block"></i>No products added yet.<br>Search and click a product above.</td></tr>');
        $('#overall_section').hide();
        $('#payment_summary_box').hide();
    }
    recalcGrand();
}

/* ── EVENT DELEGATION ── */
$(document).on('input change', '.units-per-pack,.qty-of-packs,.purchase-price,.item-disc-type,.item-disc-val,.item-tax-type,.item-tax-val', function () {
    var idx = $(this).data('index');
    if (idx !== undefined) updateItemTotal(idx);
});
$(document).on('input', '#paid_amount_input', recalcGrand);

/* ── FORM VALIDATION (with expiry check) ── */
function validateForm() {
    if (cartItems.length === 0) {
        Notify.warning('Please add at least one product!');
        return false;
    }
    var valid = true;
    var today = new Date().toISOString().split('T')[0];

    cartItems.forEach(function(item) {
        var idx = item.index;
        var pp = parseFloat($('#cart_row_' + idx + ' .purchase-price').val()) || 0;
        var sp = parseFloat($('#cart_row_' + idx + ' .selling-price').val())  || 0;
        var up = parseFloat($('#cart_row_' + idx + ' .units-per-pack').val()) || 0;
        var qp = parseFloat($('#cart_row_' + idx + ' .qty-of-packs').val())   || 0;
        var expiry = $('#cart_row_' + idx + ' input[name="expiry_date[]"]').val();

        if (pp <= 0) {
            Notify.warning('Purchase price must be greater than 0 for all items!');
            $('#cart_row_' + idx + ' .purchase-price').focus().addClass('required-error');
            valid = false; return false;
        }
        if (sp <= 0) {
            Notify.warning('Sale price is required and must be greater than 0 for all items!');
            $('#cart_row_' + idx + ' .selling-price').focus().addClass('required-error');
            valid = false; return false;
        }
        if (up <= 0 || qp <= 0) {
            Notify.warning('Quantity must be greater than 0 for all items!');
            valid = false; return false;
        }
        if (!expiry) {
            Notify.warning('Expiry date is required for all products!');
            $('#cart_row_' + idx + ' input[name="expiry_date[]"]').focus().addClass('required-error');
            valid = false; return false;
        }
        if (expiry < today) {
            Notify.error('Expiry date cannot be in the past. Please select a future date for all products.');
            $('#cart_row_' + idx + ' input[name="expiry_date[]"]').focus().addClass('required-error');
            valid = false; return false;
        }
    });

    if (!valid) return false;

    var status = $('#payment_status').val();
    if (status === 'partial') {
        var pa = parseFloat($('#paid_amount_input').val()) || 0;
        if (pa <= 0) {
            Notify.warning('Please enter paid amount for partial payment!');
            $('#paid_amount_input').focus();
            return false;
        }
    }
    return true;
}

function escHtml(t) {
    if (!t) return '';
    return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ===== AUTO-ADD PRODUCT FROM URL PARAMETER =====
$(document).ready(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const productId = urlParams.get('product_id');
    if (productId && productId > 0) {
        setTimeout(function() {
            addToCart(productId);
            // Optional: scroll to cart table
            $('html, body').animate({ scrollTop: $('#cart_body').offset().top }, 500);
        }, 500);
    }
});

togglePaymentFields();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
