<?php
/**
 * purchases/add.php — Add Purchase / Supplier Invoice
 * =====================================================
 * SYNCHRONIZED: reorder_level saved per batch row
 * All validations, CSRF, AJAX search, live calculations
 * FIXED: CSRF token handling and product search add to cart
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/purchase_functions.php';
require_once __DIR__ . '/../includes/sync_engine.php';

requirePharmacist();

// ── Handle quick-add supplier ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_supplier') {
    header('Content-Type: application/json');
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']); exit;
    }
    $sName = trim($_POST['supplier_name'] ?? '');
    $sPhone = trim($_POST['phone'] ?? '');
    if (!$sName || !$sPhone) {
        echo json_encode(['success' => false, 'message' => 'Name and phone are required.']); exit;
    }
    $newId = addSupplier([
        'supplier_name'  => $sName,
        'phone'          => $sPhone,
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'email'          => trim($_POST['email'] ?? ''),
        'address'        => trim($_POST['address'] ?? ''),
    ]);
    if ($newId) {
        echo json_encode(['success' => true, 'id' => $newId, 'name' => $sName]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add supplier.']);
    }
    exit;
}

// ── Handle purchase save ──────────────────────────────────────────────────────
$errors   = [];
$success  = false;
$savedInvoice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_purchase'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    // CSRF verification
    $submittedToken = $_POST['csrf_token'] ?? '';
    $storedToken = $_SESSION['csrf_token'] ?? '';
    
    if (empty($storedToken) || !hash_equals($storedToken, $submittedToken)) {
        $errors[] = 'Security token mismatch. Please refresh the page and try again.';
        // Regenerate token for next attempt
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        
        $supplierId    = (int)($_POST['supplier_id'] ?? 0);
        $purchaseDate  = trim($_POST['purchase_date'] ?? '');
        $paymentStatus = trim($_POST['payment_status'] ?? 'pending');
        $paidAmount    = (float)($_POST['paid_amount'] ?? 0);
        $subtotal      = (float)($_POST['subtotal'] ?? 0);
        $discount      = (float)($_POST['overall_discount'] ?? 0);
        $tax           = (float)($_POST['overall_tax'] ?? 0);
        $grandTotal    = (float)($_POST['grand_total'] ?? 0);
        $notes         = trim($_POST['notes'] ?? '');
        $itemsRaw      = $_POST['items'] ?? [];

        if (!$supplierId)    $errors[] = 'Please select a supplier.';
        if (!$purchaseDate)  $errors[] = 'Purchase date is required.';
        if (empty($itemsRaw)) $errors[] = 'Add at least one product to the cart.';

        // Validate each item
        $items = [];
        foreach ($itemsRaw as $idx => $row) {
            $productId    = (int)($row['product_id'] ?? 0);
            $unitsPerPack = (int)($row['units_per_pack'] ?? 1);
            $qtyOfPacks   = (int)($row['qty_of_packs'] ?? 0);
            $reorderLevel = (int)($row['reorder_level'] ?? 10);
            $purchasePrice= (float)($row['purchase_price'] ?? 0);
            $salePrice    = (float)($row['sale_price'] ?? 0);
            $expiryDate   = trim($row['expiry_date'] ?? '');
            $batchNo      = trim($row['batch_number'] ?? '');
            $itemDiscount = (float)($row['item_discount'] ?? 0);
            $itemTax      = (float)($row['item_tax'] ?? 0);
            $totalCost    = (float)($row['total_cost'] ?? 0);

            $n = $idx + 1;
            if (!$productId)      $errors[] = "Row {$n}: Invalid product.";
            if ($unitsPerPack < 1) $errors[] = "Row {$n}: Units/Pack must be ≥ 1.";
            if ($qtyOfPacks < 1)  $errors[] = "Row {$n}: Quantity must be ≥ 1.";
            if ($purchasePrice <= 0) $errors[] = "Row {$n}: Purchase price must be > 0.";
            if ($salePrice <= 0)  $errors[] = "Row {$n}: Sale price must be > 0.";
            if ($reorderLevel < 1) $errors[] = "Row {$n}: Reorder level must be ≥ 1.";
            if (!$expiryDate || strtotime($expiryDate) < strtotime('tomorrow'))
                $errors[] = "Row {$n}: Expiry date must be a future date.";
            if ($salePrice > 0 && $purchasePrice > 0 && $salePrice <= $purchasePrice)
                $errors[] = "Row {$n}: Sale price must be greater than purchase price.";

            $items[] = [
                'product_id'      => $productId,
                'batch_number'    => $batchNo,
                'units_per_pack'  => $unitsPerPack,
                'quantity_of_packs'=> $qtyOfPacks,
                'reorder_level'   => $reorderLevel,
                'purchase_price'  => $purchasePrice,
                'selling_price'   => $salePrice,
                'expiry_date'     => $expiryDate,
                'item_discount'   => $itemDiscount,
                'item_tax'        => $itemTax,
                'total_cost'      => $totalCost,
            ];
        }

        if (empty($errors)) {
            $purchaseData = [
                'supplier_id'    => $supplierId,
                'purchase_date'  => $purchaseDate,
                'subtotal'       => $subtotal,
                'discount'       => $discount,
                'tax'            => $tax,
                'total'          => $grandTotal,
                'payment_status' => $paymentStatus,
                'paid_amount'    => $paidAmount,
                'notes'          => $notes,
            ];

            $purchaseId = addPurchase($purchaseData, $items);
            if ($purchaseId) {
                syncAfterPurchase($purchaseId, $paymentStatus, $paidAmount, $grandTotal);
                $success = true;
                // Clear CSRF token after successful submission
                unset($_SESSION['csrf_token']);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Purchase saved successfully!']);
                    exit;
                }
                header('Location: list.php?success=Purchase+saved+successfully');
                exit;
            } else {
                $errors[] = 'Failed to save purchase: ' . ($GLOBALS['addPurchaseLastError'] ?? 'Unknown error.');
            }
        }
    }

    if ($isAjax && !empty($errors)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }
}

// ── Load suppliers ────────────────────────────────────────────────────────────
$suppliers = getAllSuppliers();

// Pre-fill product from URL (coming from view.php "+ Add New Batch" link)
$prefillProductId   = (int)($_GET['product_id'] ?? 0);
$prefillProductName = htmlspecialchars($_GET['product_name'] ?? '');

// Generate CSRF token - store in session and use same token throughout the page
$csrfToken = generateCSRFToken();

$page_title = 'Add Purchase — Pharmacy Management System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>


<style>
body { background: #f0f2f5; }
.main-content { margin-left: var(--sb-w, 260px); transition: margin-left .25s ease; padding: 20px; }
.card { border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,.05); border: none; }
.card-header { border-radius: 15px 15px 0 0 !important; background: linear-gradient(135deg,#6c3fc5,#8e57f0); color: #fff; }
.section-card { background:#fff; border-radius:15px; padding:20px; margin-bottom:20px; box-shadow:0 2px 10px rgba(0,0,0,.05); }
.cart-table th { background:#f8f9fa; font-size:11px; font-weight:700; text-transform:uppercase; white-space:nowrap; }
.cart-table td { font-size:12px; vertical-align:middle; padding:6px 4px; }
.cart-table input[type=number], .cart-table input[type=text], .cart-table input[type=date] {
    font-size:12px; padding:4px 6px; }
.totals-box { background:#f8f9fa; border-radius:12px; padding:15px; }
.totals-box .row { margin-bottom:4px; }
.search-dropdown { position:absolute; z-index:9999; background:#fff; border:1px solid #dee2e6;
    border-radius:8px; box-shadow:0 4px 15px rgba(0,0,0,.15); max-height:300px; overflow-y:auto; min-width:500px; width:100%; }
.search-item { padding:10px 15px; cursor:pointer; border-bottom:1px solid #f0f0f0; }
.search-item:hover { background:#f0f4ff; }
.search-item .pname { font-weight:600; font-size:13px; }
.search-item .pmeta { font-size:11px; color:#6c757d; }
.total-badge { background:#e8f5e9; color:#1b5e20; font-weight:700; font-size:14px; padding:6px 12px; border-radius:8px; }
.type-toggle { display:inline-flex; border:1px solid #ced4da; border-radius:6px; overflow:hidden; }
.type-toggle input[type=radio] { display:none; }
.type-toggle label { margin:0; padding:3px 7px; font-size:11px; font-weight:600; cursor:pointer; background:#fff; color:#6c757d; border:none; line-height:1.6; transition:.15s; }
.type-toggle input[type=radio]:checked + label { background:#0d6efd; color:#fff; }
.type-toggle label:hover { background:#e9ecef; }
.cart-prod-name { font-weight:700; font-size:12.5px; color:#212529; }
.cart-prod-generic { font-size:10.5px; color:#6c757d; font-style:italic; }
.cart-prod-brand { font-size:10.5px; color:#495057; }
.cart-prod-meta { font-size:10px; color:#6c757d; }
.stock-badge-in { color:#198754; font-weight:600; font-size:10px; }
.stock-badge-out { color:#dc3545; font-weight:600; font-size:10px; }
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
</style>
</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">
<?php include '../includes/sidebar.php'; ?>
<?php if (file_exists('../includes/navbar.php')) include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-shopping-cart me-2 text-primary"></i>Add Purchase / Supplier Invoice</h4>
            <p class="text-muted mb-0 small">Record new stock from supplier</p>
        </div>
        <a href="list.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <strong><i class="fas fa-exclamation-triangle me-2"></i>Please fix the following errors:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div id="purchaseAlertArea" class="mb-3"></div>
    <form method="POST" id="purchaseForm" action=""
          data-pharm-form
          data-success-msg="Purchase saved successfully!"
          data-redirect="list.php"
          data-redirect-delay="1500"
          data-loading-text="Saving Purchase…"
          data-alert-target="#purchaseAlertArea"
          novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="save_purchase" value="1">
        <input type="hidden" name="subtotal" id="fSubtotal" value="0">
        <input type="hidden" name="grand_total" id="fGrandTotal" value="0">

        <!-- ── SECTION 1: Header ── -->
        <div class="section-card">
            <h6 class="fw-bold mb-3"><i class="fas fa-file-invoice me-2 text-primary"></i>Purchase Details</h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
                    <div>
                        <input type="text" id="supplierSearch" class="form-control form-control-sm mb-1"
                               placeholder="Filter suppliers…" autocomplete="off"
                               oninput="filterSuppliers(this.value)">
                        <div class="input-group">
                            <select name="supplier_id" id="supplierSelect" class="form-select" required>
                                <option value="">— Select Supplier —</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"
                                            data-search="<?php echo strtolower(htmlspecialchars($s['supplier_name'] . ' ' . $s['phone'])); ?>">
                                        <?php echo htmlspecialchars($s['supplier_name']); ?> <?php echo $s['phone'] ? '('.$s['phone'].')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-success" onclick="openAddSupplierModal()" title="Add New Supplier"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Purchase Date <span class="text-danger">*</span></label>
                    <input type="date" name="purchase_date" id="purchaseDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Ref / Invoice No <small class="text-muted">(optional)</small></label>
                    <input type="text" name="ref_invoice_no" class="form-control" placeholder="Supplier's invoice no.">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Notes</label>
                    <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                </div>
            </div>
        </div>

        <!-- ── SECTION 2: Product Search ── -->
        <div class="section-card">
            <h6 class="fw-bold mb-3"><i class="fas fa-search me-2 text-success"></i>Add Products to Cart</h6>
            <div class="position-relative" style="max-width:600px">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" id="productSearch" class="form-control" placeholder="Search by name, generic, brand, or code…" autocomplete="off">
                    <span class="input-group-text bg-white" id="searchSpinner" style="display:none"><i class="fas fa-spinner fa-spin text-primary"></i></span>
                </div>
                <div id="searchDropdown" class="search-dropdown" style="display:none;"></div>
            </div>
            <small class="text-muted mt-1 d-block">Type at least 2 characters to search. Click a product to add to cart.</small>
        </div>

        <!-- ── SECTION 3: Cart ── -->
        <div class="section-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0"><i class="fas fa-shopping-basket me-2 text-warning"></i>Purchase Cart</h6>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary" id="cartCount">0 items</span>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="clearCartBtn" onclick="clearCart()" style="display:none;font-size:12px;">
                        <i class="fas fa-times me-1"></i>Clear Cart
                    </button>
                </div>
            </div>
            <div id="emptyCart" class="text-center py-4 text-muted">
                <i class="fas fa-box-open fa-3x mb-2 opacity-25"></i>
                <p>No products added yet. Search above to add products.</p>
            </div>
            <div class="table-responsive" id="cartWrapper" style="display:none">
                <table class="table table-bordered cart-table" id="cartTable">
                    <thead>
                        <tr>
                            <th style="min-width:180px">Product</th>
                            <th style="min-width:130px">Batch No.</th>
                            <th style="min-width:80px">Units/Pack</th>
                            <th style="min-width:80px">Qty Packs</th>
                            <th style="min-width:80px">Total Pcs</th>
                            <th style="min-width:90px">Reorder Level</th>
                            <th style="min-width:100px">Purchase Price<br><small>/piece</small></th>
                            <th style="min-width:100px">Sale Price<br><small>/piece</small></th>
                            <th style="min-width:110px">Expiry Date</th>
                            <th style="min-width:100px">Disc.</th>
                            <th style="min-width:100px">Tax</th>
                            <th style="min-width:100px">Item Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="cartBody"></tbody>
                </table>
            </div>
        </div>

        <!-- ── SECTION 4: Totals & Payment ── -->
        <div class="row g-3">
            <div class="col-md-6">
                <div class="section-card">
                    <h6 class="fw-bold mb-3"><i class="fas fa-percent me-2 text-info"></i>Overall Adjustments</h6>
                    <div class="row g-2 align-items-center mb-2">
                        <div class="col-5"><label class="form-label mb-0">Overall Discount</label></div>
                        <div class="col-4">
                            <div class="input-group input-group-sm">
                                <input type="number" id="overallDiscount" name="overall_discount" class="form-control" value="0" min="0" step="0.01">
                                <select id="overallDiscType" class="form-select" style="max-width:60px">
                                    <option value="rs">Rs</option>
                                    <option value="pct">%</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 align-items-center">
                        <div class="col-5"><label class="form-label mb-0">Overall Tax</label></div>
                        <div class="col-4">
                            <div class="input-group input-group-sm">
                                <input type="number" id="overallTax" name="overall_tax" class="form-control" value="0" min="0" step="0.01">
                                <select id="overallTaxType" class="form-select" style="max-width:60px">
                                    <option value="rs">Rs</option>
                                    <option value="pct">%</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="section-card">
                    <h6 class="fw-bold mb-3"><i class="fas fa-money-bill-wave me-2 text-success"></i>Payment</h6>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Payment Status</label>
                        <div class="d-flex gap-2">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="payment_status" id="psPaid" value="paid" onchange="togglePayment()">
                                <label class="form-check-label" for="psPaid">Paid</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="payment_status" id="psPartial" value="partial" onchange="togglePayment()">
                                <label class="form-check-label" for="psPartial">Partial</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="payment_status" id="psPending" value="pending" checked onchange="togglePayment()">
                                <label class="form-check-label" for="psPending">Pending</label>
                            </div>
                        </div>
                    </div>
                    <div id="paidRow" style="display:none">
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-5"><label class="form-label mb-0">Paid Amount</label></div>
                            <div class="col-5">
                                <input type="number" name="paid_amount" id="paidAmount" class="form-control form-control-sm" value="0" min="0" step="0.01" oninput="recalcTotals()">
                            </div>
                        </div>
                        <div class="row g-2 align-items-center">
                            <div class="col-5"><label class="form-label mb-0 text-danger">Due Amount</label></div>
                            <div class="col-5"><span id="dueAmount" class="fw-bold text-danger">Rs 0.00</span></div>
                        </div>
                    </div>
                    <div class="mb-2 mt-2">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <select name="payment_method" class="form-select form-select-sm">
                            <option value="cash">Cash</option>
                            <option value="bank">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="online">Online</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="section-card h-100">
                    <h6 class="fw-bold mb-3"><i class="fas fa-receipt me-2 text-primary"></i>Invoice Summary</h6>
                    <div class="totals-box">
                        <div class="row"><div class="col">Items Subtotal</div><div class="col-auto text-end" id="dispSubtotal">Rs 0.00</div></div>
                        <div class="row"><div class="col">Item Discounts</div><div class="col-auto text-end text-danger" id="dispItemDisc">-Rs 0.00</div></div>
                        <div class="row"><div class="col">Item Taxes</div><div class="col-auto text-end" id="dispItemTax">+Rs 0.00</div></div>
                        <hr class="my-2">
                        <div class="row"><div class="col">Overall Discount</div><div class="col-auto text-end text-danger" id="dispOvDisc">-Rs 0.00</div></div>
                        <div class="row"><div class="col">Overall Tax</div><div class="col-auto text-end" id="dispOvTax">+Rs 0.00</div></div>
                        <hr class="my-2">
                        <div class="row fw-bold">
                            <div class="col fs-5">Grand Total</div>
                            <div class="col-auto text-end fs-5 text-primary" id="dispGrandTotal">Rs 0.00</div>
                        </div>
                    </div>
                    <div class="mt-3 d-grid gap-2">
                        <button type="submit" data-submit-btn class="btn btn-primary btn-lg" id="savePurchaseBtn">
                            <i class="fas fa-save me-2"></i>Save Purchase Invoice
                        </button>
                        <a href="list.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
</div>

<!-- ── Add Supplier Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-labelledby="addSupplierModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h6 id="addSupplierModalLabel" class="modal-title"><i class="fas fa-truck me-2"></i>Add New Supplier</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="supplierFormError" class="alert alert-danger d-none"></div>
                <div class="mb-2">
                    <label class="form-label fw-semibold">Supplier Name <span class="text-danger">*</span></label>
                    <input type="text" id="newSupplierName" class="form-control" placeholder="Supplier Company / Person Name">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label>
                    <input type="text" id="newSupplierPhone" class="form-control" placeholder="03XX-XXXXXXX">
                </div>
                <div class="mb-2">
                    <label class="form-label">Contact Person</label>
                    <input type="text" id="newSupplierContact" class="form-control" placeholder="Optional">
                </div>
                <div class="mb-2">
                    <label class="form-label">Email</label>
                    <input type="email" id="newSupplierEmail" class="form-control" placeholder="Optional">
                </div>
                <div class="mb-2">
                    <label class="form-label">Address</label>
                    <input type="text" id="newSupplierAddress" class="form-control" placeholder="Optional">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="saveNewSupplier()"><i class="fas fa-save me-1"></i>Save Supplier</button>
            </div>
        </div>
    </div>
</div>



<script src="../js/autosave.js"></script>
<script>
// CSRF token from PHP
var csrfToken = '<?php echo $csrfToken; ?>';

// ── Cart state ────────────────────────────────────────────────────────────────
const CART_KEY = 'purchaseCart_draft';
let cart = [];
let rowIndex = 0;

// Tomorrow date for expiry min
const tomorrowDate = (() => {
    const d = new Date(); d.setDate(d.getDate() + 1);
    return d.toISOString().split('T')[0];
})();

// ── LocalStorage helpers ──────────────────────────────────────────────────────
function saveCartDraft() {
    try { localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch(e) {}
}

function loadCartDraft() {
    try {
        const raw = localStorage.getItem(CART_KEY);
        if (raw) {
            const saved = JSON.parse(raw);
            if (Array.isArray(saved) && saved.length > 0) {
                cart = saved;
                rowIndex = Math.max(...cart.map(r => r.ri)) + 1;
                renderCart();
                cart.forEach(row => {
                    const ri = row.ri;
                    if (row._vals) {
                        const v = row._vals;
                        $(`#batchNo_${ri}`).val(v.batchNo || '');
                        $(`#upp_${ri}`).val(v.upp || row.units_per_pack || 1);
                        $(`#qop_${ri}`).val(v.qop || 1);
                        $(`#reorder_${ri}`).val(v.reorder || 10);
                        $(`#pp_${ri}`).val(v.pp || '');
                        $(`#sp_${ri}`).val(v.sp || '');
                        $(`#exp_${ri}`).val(v.exp || '');
                        $(`#disc_${ri}`).val(v.disc || 0);
                        $(`input[name="discType_${ri}"][value="${v.discType||'rs'}"]`).prop('checked', true);
                        $(`#tax_${ri}`).val(v.tax || 0);
                        $(`input[name="taxType_${ri}"][value="${v.taxType||'rs'}"]`).prop('checked', true);
                        recalcRow(ri);
                        validateSalePrice(ri);
                    }
                });
            }
        }
    } catch(e) {}
}

function snapshotRowValues(ri) {
    const row = cart.find(r => r.ri === ri);
    if (!row) return;
    row._vals = {
        batchNo:  $(`#batchNo_${ri}`).val(),
        upp:      $(`#upp_${ri}`).val(),
        qop:      $(`#qop_${ri}`).val(),
        reorder:  $(`#reorder_${ri}`).val(),
        pp:       $(`#pp_${ri}`).val(),
        sp:       $(`#sp_${ri}`).val(),
        exp:      $(`#exp_${ri}`).val(),
        disc:     $(`#disc_${ri}`).val(),
        discType: $(`input[name="discType_${ri}"]:checked`).val() || 'rs',
        tax:      $(`#tax_${ri}`).val(),
        taxType:  $(`input[name="taxType_${ri}"]:checked`).val() || 'rs',
    };
    saveCartDraft();
}


// ── Supplier live filter ──────────────────────────────────────────────────────
function filterSuppliers(q) {
    q = q.toLowerCase().trim();
    var sel = document.getElementById('supplierSelect');
    Array.from(sel.options).forEach(function(opt) {
        if (!opt.value) { opt.style.display = ''; return; }
        var text = (opt.dataset.search || opt.textContent).toLowerCase();
        opt.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
}

function clearCartDraft() {
    try { localStorage.removeItem(CART_KEY); } catch(e) {}
}

function validateSalePrice(ri) {
    const pp = parseFloat($(`#pp_${ri}`).val()) || 0;
    const sp = parseFloat($(`#sp_${ri}`).val()) || 0;
    const errEl = $(`#spErr_${ri}`);
    if (sp > 0 && pp > 0 && sp <= pp) {
        errEl.show();
    } else {
        errEl.hide();
    }
    snapshotRowValues(ri);
}

// ── Pre-fill from URL if coming from view.php ─────────────────────────────────
window.addEventListener('DOMContentLoaded', function() {
    <?php if ($prefillProductId): ?>
    // Prefill from URL — fetch product by ID then add to cart automatically
    fetch('get_product_json.php?id=<?php echo $prefillProductId; ?>')
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(product => {
            if (product && product.id) {
                addToCart(product);
            } else {
                // Fallback: product not returned — show warning but still load any draft
                console.warn('get_product_json returned:', product);
                if (typeof Notify !== 'undefined') {
                    Notify.warning('Could not pre-load product details. Please search manually.');
                }
                loadCartDraft();
            }
        })
        .catch(err => {
            console.error('Failed to fetch product for prefill:', err);
            if (typeof Notify !== 'undefined') {
                Notify.warning('Could not pre-load product. Please search manually.');
            }
            loadCartDraft();
        });
    <?php else: ?>
    loadCartDraft();
    <?php endif; ?>
});

// ── Product search (debounced AJAX) ───────────────────────────────────────────
let searchTimer;
$('#productSearch').on('input', function() {
    const q = $(this).val().trim();
    clearTimeout(searchTimer);
    if (q.length < 2) { $('#searchDropdown').hide(); return; }
    searchTimer = setTimeout(() => {
        $('#searchSpinner').show();
        $.get('search_product_purchase.php', { search: q }, function(html) {
            $('#searchSpinner').hide();
            $('#searchDropdown').html(html).show();
        }).fail(() => $('#searchSpinner').hide());
    }, 300);
});

$(document).on('click', function(e) {
    if (!$(e.target).closest('#productSearch, #searchDropdown').length) {
        $('#searchDropdown').hide();
    }
});

function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Add product to cart (called from search results) ─────────────────────────
window.addToCart = function(product) {
    console.log('addToCart called with product:', product);
    
    if (typeof product === 'number' || !isNaN(parseInt(product))) {
        const productId = parseInt(product);
        $.getJSON('get_product_json.php', { id: productId }, function(productData) {
            if (productData && productData.id) {
                addToCartObject(productData);
            } else {
                Notify.error('Product not found');
            }
        }).fail(function() {
            Notify.error('Error fetching product details');
        });
    } else if (typeof product === 'object' && product.id) {
        addToCartObject(product);
    } else {
        console.error('Invalid product parameter:', product);
    }
};

window.addToCartFromObject = function(product) {
    console.log('addToCartFromObject called with product:', product);
    if (product && product.id) {
        addToCartObject(product);
    }
};

function addToCartObject(product) {
    $('#searchDropdown').hide();
    $('#productSearch').val('');
    
    if (cart.some(item => item.product_id === product.id)) {
        Notify.warning('Product already in cart!');
        return;
    }
    
    const ri = rowIndex++;
    const row = {
        ri,
        product_id:    product.id,
        product_name:  product.product_name,
        product_code:  product.product_code,
        category_id:   product.category_id || 0,
        generic_name:  product.generic_name || '',
        brand_name:    product.brand_name || '',
        dosage_form:   product.dosage_form || '',
        strength:      product.strength || '',
        size_value:    product.size_value || '',
        unit_type:     product.unit_type || 'Pcs',
        total_stock:   product.total_stock || 0,
        units_per_pack: product.fifo_units_per_pack || product.units_per_pack || 1,
        purchase_price: parseFloat(product.fifo_purchase_price || product.default_purchase_price || 0),
        sale_price:     parseFloat(product.fifo_sale_price || product.default_sale_price || 0),
    };
    cart.push(row);
    saveCartDraft();
    renderCart();
    setTimeout(() => document.getElementById(`batchNo_${ri}`)?.focus(), 50);
    recalcTotals();
}

function renderCart() {
    $('#cartCount').text(cart.length + ' item' + (cart.length !== 1 ? 's' : ''));
    if (!cart.length) {
        $('#emptyCart').show(); $('#cartWrapper').hide();
        $('#clearCartBtn').hide();
        return;
    }
    $('#emptyCart').hide(); $('#cartWrapper').show();
    $('#clearCartBtn').show();

    let html = '';
    cart.forEach(row => {
        const ri  = row.ri;
        const isMed = (row.category_id == 1);
        let prodDetail = '';
        if (isMed) {
            if (row.generic_name) prodDetail += `<div class="cart-prod-generic">(${escHtml(row.generic_name)})</div>`;
            if (row.brand_name)   prodDetail += `<div class="cart-prod-brand">${escHtml(row.brand_name)}</div>`;
            const fm = [row.dosage_form, row.strength].filter(Boolean).map(escHtml).join(' · ');
            if (fm) prodDetail += `<div class="cart-prod-meta">${fm}</div>`;
        } else {
            if (row.brand_name) prodDetail += `<div class="cart-prod-brand">${escHtml(row.brand_name)}</div>`;
            if (row.size_value) prodDetail += `<div class="cart-prod-meta">Size: ${escHtml(row.size_value)}</div>`;
        }
        const stockHtml = (row.total_stock > 0)
            ? `<span class="stock-badge-in"><i class="fas fa-check-circle"></i> ${row.total_stock} in stock</span>`
            : `<span class="stock-badge-out"><i class="fas fa-times-circle"></i> Out of stock</span>`;
        const pp0 = parseFloat(row.purchase_price || 0).toFixed(2);
        const sp0 = parseFloat(row.sale_price || 0).toFixed(2);
        const upp0 = row.units_per_pack || 1;

        html += `
        <tr id="cartRow_${ri}">
            <td style="min-width:185px">
                <input type="hidden" name="items[${ri}][product_id]" value="${row.product_id}">
                <div class="cart-prod-name">${escHtml(row.product_name)}</div>
                ${prodDetail}
                <div class="mt-1">${stockHtml}</div>
                <span class="badge bg-light text-dark border mt-1" style="font-size:10px">${escHtml(row.product_code)}</span>
              </td>
              <td><input type="text" name="items[${ri}][batch_number]" id="batchNo_${ri}" class="form-control" placeholder="Auto-gen" oninput="snapshotRowValues(${ri})"></td>
              <td><input type="number" name="items[${ri}][units_per_pack]" id="upp_${ri}" class="form-control bg-light" value="${upp0}" min="1" readonly style="background:#e9ecef"></td>
              <td><input type="number" name="items[${ri}][qty_of_packs]" id="qop_${ri}" class="form-control" value="1" min="1" oninput="recalcRow(${ri})"></td>
              <td><input type="number" id="totalPcs_${ri}" class="form-control bg-light" value="${upp0}" readonly></td>
              <td><input type="number" name="items[${ri}][reorder_level]" id="reorder_${ri}" class="form-control" value="10" min="1" oninput="snapshotRowValues(${ri})"></td>
              <td><input type="number" name="items[${ri}][purchase_price]" id="pp_${ri}" class="form-control" value="${pp0}" min="0.01" step="0.01" oninput="recalcRow(${ri});validateSalePrice(${ri})"></td>
              <td>
                <input type="number" name="items[${ri}][sale_price]" id="sp_${ri}" class="form-control" value="${sp0}" min="0.01" step="0.01" oninput="validateSalePrice(${ri})">
                <div id="spErr_${ri}" class="text-danger" style="font-size:10px;display:none">⚠ Must be > purchase price</div>
              </td>
              <td><input type="date" name="items[${ri}][expiry_date]" id="exp_${ri}" class="form-control" min="${tomorrowDate}" oninput="snapshotRowValues(${ri})"></td>
            <td>
                <div class="d-flex gap-1">
                    <input type="number" name="items[${ri}][item_discount]" id="disc_${ri}" class="form-control form-control-sm" value="0" step="0.01" oninput="recalcRow(${ri})" style="width:65px">
                    <div class="type-toggle">
                        <input type="radio" name="discType_${ri}" id="discRs_${ri}" value="rs" checked onchange="recalcRow(${ri})">
                        <label for="discRs_${ri}">Rs</label>
                        <input type="radio" name="discType_${ri}" id="discPct_${ri}" value="pct" onchange="recalcRow(${ri})">
                        <label for="discPct_${ri}">%</label>
                    </div>
                </div>
              </td>
            <td>
                <div class="d-flex gap-1">
                    <input type="number" name="items[${ri}][item_tax]" id="tax_${ri}" class="form-control form-control-sm" value="0" step="0.01" oninput="recalcRow(${ri})" style="width:65px">
                    <div class="type-toggle">
                        <input type="radio" name="taxType_${ri}" id="taxRs_${ri}" value="rs" checked onchange="recalcRow(${ri})">
                        <label for="taxRs_${ri}">Rs</label>
                        <input type="radio" name="taxType_${ri}" id="taxPct_${ri}" value="pct" onchange="recalcRow(${ri})">
                        <label for="taxPct_${ri}">%</label>
                    </div>
                </div>
              </td>
             <td>
                <input type="hidden" name="items[${ri}][total_cost]" id="totalCost_${ri}" value="0">
                <strong id="dispTotal_${ri}" class="text-primary">Rs 0.00</strong>
              </td>
              <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeFromCart(${ri})"><i class="fas fa-trash"></i></button></td>
        </tr>`;
    });
    $('#cartBody').html(html);
    cart.forEach(row => recalcRow(row.ri));
}

function removeFromCart(ri) {
    cart = cart.filter(r => r.ri !== ri);
    saveCartDraft();
    renderCart();
    recalcTotals();
}

function recalcRow(ri) {
    const upp  = parseFloat($(`#upp_${ri}`).val()) || 1;
    const qop  = parseFloat($(`#qop_${ri}`).val()) || 0;
    const pp   = parseFloat($(`#pp_${ri}`).val())  || 0;
    const totalPcs = upp * qop;
    $(`#totalPcs_${ri}`).val(totalPcs);

    const gross   = pp * totalPcs;
    const discVal = parseFloat($(`#disc_${ri}`).val()) || 0;
    const discType= $(`input[name="discType_${ri}"]:checked`).val() || 'rs';
    const disc    = discType === 'pct' ? gross * discVal / 100 : discVal;

    const taxVal  = parseFloat($(`#tax_${ri}`).val()) || 0;
    const taxType = $(`input[name="taxType_${ri}"]:checked`).val() || 'rs';
    const tax     = taxType === 'pct' ? (gross - disc) * taxVal / 100 : taxVal;

    const total   = Math.max(0, gross - disc + tax);
    $(`#totalCost_${ri}`).val(total.toFixed(2));
    $(`#dispTotal_${ri}`).text('Rs ' + total.toFixed(2));
    snapshotRowValues(ri);
    recalcTotals();
}

function recalcTotals() {
    let subtotal = 0, totalItemDisc = 0, totalItemTax = 0;
    cart.forEach(row => {
        const ri  = row.ri;
        const upp = parseFloat($(`#upp_${ri}`).val())||1;
        const qop = parseFloat($(`#qop_${ri}`).val())||0;
        const pp  = parseFloat($(`#pp_${ri}`).val())||0;
        const gross = pp * upp * qop;
        subtotal += gross;

        const discVal  = parseFloat($(`#disc_${ri}`).val())||0;
        const discType = $(`input[name="discType_${ri}"]:checked`).val() || 'rs';
        totalItemDisc += discType === 'pct' ? gross * discVal / 100 : discVal;

        const taxVal  = parseFloat($(`#tax_${ri}`).val())||0;
        const taxType = $(`input[name="taxType_${ri}"]:checked`).val() || 'rs';
        totalItemTax  += taxType === 'pct' ? (gross - totalItemDisc) * taxVal / 100 : taxVal;
    });

    const ovDiscVal  = parseFloat($('#overallDiscount').val())||0;
    const ovDiscType = $('#overallDiscType').val();
    const ovDisc     = ovDiscType === 'pct' ? subtotal * ovDiscVal / 100 : ovDiscVal;

    const ovTaxVal  = parseFloat($('#overallTax').val())||0;
    const ovTaxType = $('#overallTaxType').val();
    const ovTax     = ovTaxType === 'pct' ? (subtotal - ovDisc) * ovTaxVal / 100 : ovTaxVal;

    const grandTotal = Math.max(0, subtotal - totalItemDisc + totalItemTax - ovDisc + ovTax);

    $('#dispSubtotal').text('Rs ' + subtotal.toFixed(2));
    $('#dispItemDisc').text('-Rs ' + totalItemDisc.toFixed(2));
    $('#dispItemTax').text('+Rs ' + totalItemTax.toFixed(2));
    $('#dispOvDisc').text('-Rs ' + ovDisc.toFixed(2));
    $('#dispOvTax').text('+Rs ' + ovTax.toFixed(2));
    $('#dispGrandTotal').text('Rs ' + grandTotal.toFixed(2));

    $('#fSubtotal').val(subtotal.toFixed(2));
    $('#fGrandTotal').val(grandTotal.toFixed(2));

    const paid = parseFloat($('#paidAmount').val())||0;
    const due  = Math.max(0, grandTotal - paid);
    $('#dueAmount').text('Rs ' + due.toFixed(2));
}

$('#overallDiscount, #overallTax, #overallDiscType, #overallTaxType').on('change input', recalcTotals);

function togglePayment() {
    const v = $('input[name=payment_status]:checked').val();
    if (v === 'partial') {
        $('#paidRow').show();
    } else if (v === 'paid') {
        const gt = parseFloat($('#fGrandTotal').val())||0;
        $('#paidAmount').val(gt.toFixed(2));
        $('#paidRow').show();
        recalcTotals();
    } else {
        $('#paidRow').hide();
    }
}

function openAddSupplierModal() {
    new bootstrap.Modal(document.getElementById('addSupplierModal')).show();
}

function saveNewSupplier() {
    const name  = $('#newSupplierName').val().trim();
    const phone = $('#newSupplierPhone').val().trim();
    if (!name || !phone) {
        $('#supplierFormError').text('Name and phone are required.').removeClass('d-none');
        return;
    }
    $('#supplierFormError').addClass('d-none');
    $.post(window.location.href, {
        action: 'add_supplier',
        csrf_token: csrfToken,
        supplier_name: name,
        phone: phone,
        contact_person: $('#newSupplierContact').val(),
        email: $('#newSupplierEmail').val(),
        address: $('#newSupplierAddress').val(),
    }, function(data) {
        if (data.success) {
            const opt = new Option(data.name, data.id, true, true);
            $('#supplierSelect').append(opt).trigger('change');
            bootstrap.Modal.getInstance(document.getElementById('addSupplierModal')).hide();
        } else {
            $('#supplierFormError').text(data.message || 'Failed to add supplier.').removeClass('d-none');
        }
    }, 'json');
}

function clearCart() {
    if (!cart.length) return;
    if (!confirm('Clear all products from cart?')) return;
    cart = [];
    rowIndex = 0;
    clearCartDraft();
    renderCart();
    recalcTotals();
}

$('#purchaseForm').on('submit', function(e) {
    if (!cart.length) {
        e.preventDefault();
        Notify.warning('Please add at least one product to the cart before saving.');
        return;
    }

    cart.forEach(row => snapshotRowValues(row.ri));

    const supplierId = $('#supplierSelect').val();
    if (!supplierId) {
        e.preventDefault();
        $('#supplierSelect').addClass('is-invalid').focus();
        return;
    }

    let valid = true;
    const tomorrow = tomorrowDate;

    cart.forEach(row => {
        const ri = row.ri;
        const pp = parseFloat($(`#pp_${ri}`).val()) || 0;
        const sp = parseFloat($(`#sp_${ri}`).val()) || 0;
        const exp = $(`#exp_${ri}`).val();

        if (sp > 0 && pp > 0 && sp <= pp) {
            $(`#sp_${ri}`).addClass('is-invalid');
            $(`#spErr_${ri}`).show();
            valid = false;
        }

        if (!exp || exp < tomorrow) {
            $(`#exp_${ri}`).addClass('is-invalid');
            if (!$(`#expErr_${ri}`).length) {
                $(`#exp_${ri}`).after(`<div id="expErr_${ri}" class="text-danger" style="font-size:10px">⚠ Must be a future date</div>`);
            }
            valid = false;
        } else {
            $(`#exp_${ri}`).removeClass('is-invalid');
            $(`#expErr_${ri}`).remove();
        }
    });

    if (!valid) { e.preventDefault(); return; }

    clearCartDraft();
    var _addUserId = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
    if (typeof AutoSave !== 'undefined' && AutoSave.clear) {
        AutoSave.clear('purchase_add_user_' + _addUserId);
    }
});

$('#supplierSelect').on('change', function() {
    $(this).removeClass('is-invalid');
});
</script>

<script>
/* ── STEP 12: Auto-save header fields (supplier, date, payment) ── */
(function () {
    var userId   = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
    var FORM_KEY = 'purchase_add_user_' + userId;

    $(document).ready(function () {
        setTimeout(function () {
            if (typeof AutoSave !== 'undefined' && AutoSave.init) {
                AutoSave.init('purchaseForm', FORM_KEY);
            }
        }, 800);
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>