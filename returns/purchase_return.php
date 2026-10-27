<?php
// returns/purchase_return.php
/**
 * Purchase Return — Process return items to supplier
 * ALL PHP logic preserved exactly as original.
 * UI updated: design system CSS vars, table-card, filter-bar, status-badge, breadcrumb.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/purchase_functions.php';
require_once __DIR__ . '/../includes/return_functions.php';

if (file_exists(__DIR__ . '/../includes/sync_engine.php')) {
    require_once __DIR__ . '/../includes/sync_engine.php';
}

requireLogin();

if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

$page_title    = 'Purchase Return';
$error         = '';
$purchase      = null;
$purchaseItems = [];
$returnHistory = [];
$netAfterReturns = 0;

$searchTerm   = trim($_GET['search']        ?? '');
$invoiceNo    = trim($_GET['invoice_no']    ?? '');
$supplierName = trim($_GET['supplier_name'] ?? '');

if (!empty($searchTerm)) {
    $purchaseRow = _pdoOne(
        "SELECT p.id, p.purchase_invoice_no FROM purchases p
         JOIN suppliers s ON p.supplier_id = s.id
         WHERE p.purchase_invoice_no = :exact
         ORDER BY p.purchase_date DESC LIMIT 1",
        [':exact' => $searchTerm]
    );
    if (!$purchaseRow) {
        $purchaseRow = _pdoOne(
            "SELECT p.id, p.purchase_invoice_no FROM purchases p
             JOIN suppliers s ON p.supplier_id = s.id
             WHERE s.supplier_name LIKE :like
             ORDER BY p.purchase_date DESC LIMIT 1",
            [':like' => "%{$searchTerm}%"]
        );
    }
    if ($purchaseRow) {
        $purchase = getPurchaseForReturn($purchaseRow['purchase_invoice_no'], false);
        if ($purchase) {
            $invoiceNo    = $purchase['purchase_invoice_no'];
            $supplierName = $purchase['supplier_name'] ?? '';
        }
    }

} elseif (!empty($invoiceNo)) {
    $purchase = getPurchaseForReturn($invoiceNo, false);
    if ($purchase) {
        $supplierName = $purchase['supplier_name'] ?? '';
    }

} elseif (!empty($supplierName)) {
    $purchaseRow = _pdoOne(
        "SELECT p.id, p.purchase_invoice_no FROM purchases p
         JOIN suppliers s ON p.supplier_id = s.id
         WHERE s.supplier_name LIKE :name
         ORDER BY p.purchase_date DESC LIMIT 1",
        [':name' => "%{$supplierName}%"]
    );
    if ($purchaseRow) {
        $purchase = getPurchaseForReturn($purchaseRow['purchase_invoice_no'], false);
        if ($purchase) {
            $invoiceNo = $purchase['purchase_invoice_no'];
        }
    }
}

if ($purchase) {
    $purchaseItems = [];
    foreach (($purchase['items'] ?? []) as $item) {
        $unitsPerPack     = max(1, (int)($item['units_per_pack'] ?? 1));
        $purchasedPacks   = (int)($item['purchased_quantity'] ?? 0);
        $purchasedPieces  = $purchasedPacks * $unitsPerPack;
        $alreadyRetPieces = (int)($item['already_returned_pieces'] ?? 0);
        $piecesSold       = (int)($item['pieces_sold_from_batch'] ?? 0);
        $batchStockPieces = (int)($item['batch_stock_pieces'] ?? 0);
        $returnablePieces = max(0, $purchasedPieces - $piecesSold - $alreadyRetPieces);

        $purchaseItems[] = [
            'id'                 => $item['purchase_item_id'],
            'product_name'       => $item['product_name']  ?? '',
            'generic_name'       => $item['generic_name']  ?? '',
            'batch_number'       => $item['batch_number']  ?? '-',
            'expiry_date'        => $item['expiry_date']   ?? '',
            'units_per_pack'     => $unitsPerPack,
            'purchased_packs'    => $purchasedPacks,
            'purchased_pieces'   => $purchasedPieces,
            'pieces_sold'        => $piecesSold,
            'already_returned'   => $alreadyRetPieces,
            'batch_stock_pieces' => $batchStockPieces,
            'returnable'         => $returnablePieces,
            'unit_price'         => (float)($item['unit_price'] ?? 0),
            'pack_total'         => (float)($item['line_total'] ?? 0),
        ];
    }

    $returnHistory = _pdoAll(
        "SELECT pr.id, pr.return_no, pr.total_amount, pr.refund_method, pr.reason,
                pr.credit_note_ref, pr.created_at,
                (SELECT full_name FROM users WHERE id = pr.returned_by LIMIT 1) AS returned_by_name
         FROM purchase_returns pr
         WHERE pr.purchase_id = :pid
         ORDER BY pr.created_at DESC",
        [':pid' => $purchase['id']]
    );

    $summary         = getPurchaseReturnSummary($purchase['id']);
    $netAfterReturns = $summary['net_amount'];
}
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
                    <i class="fas fa-undo-alt me-2" style="color: var(--pharmacy-primary);"></i>Purchase Return
                </h4>
                <p class="mb-0" style="color: color-mix(in srgb, var(--pharmacy-text) 60%, transparent); font-size: 0.88rem;">
                    Return items to supplier and adjust stock
                </p>
            </div>
            <a href="purchase_return_list.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-history me-1"></i> Return History
            </a>
        </div>

        <!-- Alert & Toast area -->
        <div id="alert_area"></div>
        <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" style="z-index:1100;"></div>

        <!-- Search Card -->
        <div class="card mb-4" style="border-radius: var(--card-border-radius,12px); border: 1px solid var(--pharmacy-border); box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 1px solid var(--pharmacy-border); border-radius: calc(var(--card-border-radius,12px) - 1px) calc(var(--card-border-radius,12px) - 1px) 0 0;">
                <span class="fw-bold" style="color: var(--pharmacy-heading);">
                    <i class="fas fa-search me-2" style="color: var(--pharmacy-primary);"></i>Find Purchase Invoice
                </span>
            </div>
            <div class="card-body" style="background: var(--pharmacy-card-bg);">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold" style="color: var(--pharmacy-text);">Invoice Number</label>
                        <input type="text" name="invoice_no" class="form-control"
                               placeholder="e.g. PUR-20260521-0001"
                               value="<?php echo htmlspecialchars($invoiceNo); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold" style="color: var(--pharmacy-text);">Supplier Name</label>
                        <input type="text" name="supplier_name" class="form-control"
                               placeholder="Supplier name..."
                               value="<?php echo htmlspecialchars($supplierName); ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <?php if ($purchase): ?>
                        <a href="purchase_return.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
                <small class="mt-2 d-block" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">
                    <i class="fas fa-info-circle me-1"></i>
                    Search by full invoice number (e.g. PUR-20260521-0001) or supplier name.
                </small>
            </div>
        </div>

        <?php if (!empty($searchTerm) || !empty($invoiceNo) || !empty($supplierName)): ?>
        <?php if (!$purchase): ?>
        <div class="alert alert-warning" style="border-radius: var(--card-border-radius,12px);">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No purchase found. Please check the invoice number or supplier name and try again.
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($purchase): ?>

        <!-- Purchase Details Card -->
        <div class="card mb-4" style="border-radius: var(--card-border-radius,12px); border: 1px solid var(--pharmacy-border); box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 1px solid var(--pharmacy-border); border-radius: calc(var(--card-border-radius,12px) - 1px) calc(var(--card-border-radius,12px) - 1px) 0 0;">
                <span class="fw-bold" style="color: var(--pharmacy-heading);">
                    <i class="fas fa-truck me-2" style="color: var(--pharmacy-primary);"></i>Purchase Details
                </span>
            </div>
            <div class="card-body" style="background: var(--pharmacy-card-bg);">
                <div class="row g-3">
                    <div class="col-md-3 col-sm-6">
                        <small class="d-block" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px;">Invoice No</small>
                        <strong style="color: var(--pharmacy-primary);"><?php echo htmlspecialchars($purchase['purchase_invoice_no']); ?></strong>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <small class="d-block" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px;">Supplier</small>
                        <strong style="color: var(--pharmacy-heading);"><?php echo htmlspecialchars($purchase['supplier_name'] ?? '—'); ?></strong>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <small class="d-block" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px;">Purchase Date</small>
                        <span style="color: var(--pharmacy-text);"><?php echo !empty($purchase['purchase_date']) ? date('d M Y', strtotime($purchase['purchase_date'])) : '—'; ?></span>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <small class="d-block" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px;">Gross Total</small>
                        <strong style="color: #ef4444;">Rs <?php echo number_format($purchase['total'], 2); ?></strong>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <small class="d-block" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px;">Payment Status</small>
                        <?php $ps = $purchase['payment_status'] ?? 'pending'; ?>
                        <?php if ($ps === 'paid'): ?>
                            <span class="status-badge badge-instock">Paid</span>
                        <?php elseif ($ps === 'partial'): ?>
                            <span class="status-badge badge-expiring">Partial</span>
                        <?php else: ?>
                            <span class="status-badge badge-oos">Pending</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <small class="d-block" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px;">Paid Amount</small>
                        <span style="color: var(--pharmacy-text);">Rs <?php echo number_format($purchase['paid_amount'], 2); ?></span>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <small class="d-block" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px;">Net after Previous Returns</small>
                        <strong style="color: var(--pharmacy-primary);">Rs <?php echo number_format($netAfterReturns, 2); ?></strong>
                    </div>
                    <div class="col-md-2">
                        <small class="d-block" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px;">Actions</small>
                        <a href="../purchases/view.php?id=<?php echo (int)$purchase['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                            <i class="fas fa-eye me-1"></i> View Invoice
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3" id="returnTabs" role="tablist" style="border-bottom: 2px solid var(--pharmacy-border);">
            <li class="nav-item">
                <button class="nav-link active" id="return-form-tab" data-bs-toggle="tab"
                        data-bs-target="#returnForm" type="button" role="tab"
                        style="font-weight: 600;">
                    <i class="fas fa-exchange-alt me-1"></i> Process Return
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="history-tab" data-bs-toggle="tab"
                        data-bs-target="#returnHistory" type="button" role="tab"
                        style="font-weight: 600;">
                    <i class="fas fa-history me-1"></i>
                    Return History
                    <?php if (!empty($returnHistory)): ?>
                    <span class="status-badge badge-expiring ms-1" style="font-size: 0.68rem; padding: 2px 7px;"><?php echo count($returnHistory); ?></span>
                    <?php endif; ?>
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Return Form Tab -->
            <div class="tab-pane fade show active" id="returnForm" role="tabpanel">
                <form id="purchase_return_form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                    <input type="hidden" name="type" value="purchase">
                    <input type="hidden" name="parent_id" value="<?php echo (int)$purchase['id']; ?>">

                    <!-- Items Table -->
                    <div class="table-card mb-4">
                        <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 2px solid var(--pharmacy-border); padding: 14px 18px;">
                            <span class="fw-bold" style="color: var(--pharmacy-heading);">
                                <i class="fas fa-boxes me-2" style="color: var(--pharmacy-primary);"></i>Select Items to Return
                            </span>
                        </div>
                        <?php if (empty($purchaseItems)): ?>
                        <div class="p-4">
                            <div class="alert alert-info mb-0">No items found for this purchase.</div>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>Batch No.</th>
                                        <th>Expiry</th>
                                        <th class="text-center">Purchased<br><small style="font-weight:400; font-size:0.68rem;">(packs × pcs)</small></th>
                                        <th class="text-center">Sold<br><small style="font-weight:400; font-size:0.68rem;">(pcs)</small></th>
                                        <th class="text-center">Returned<br><small style="font-weight:400; font-size:0.68rem;">(pcs)</small></th>
                                        <th class="text-center">Returnable<br><small style="font-weight:400; font-size:0.68rem;">(in stock)</small></th>
                                        <th class="text-end">Price/Piece</th>
                                        <th class="text-center">Return Qty<br><small style="font-weight:400; font-size:0.68rem;">(pieces)</small></th>
                                        <th class="text-end">Return Amt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $sr = 1; foreach ($purchaseItems as $item):
                                        $fullyReturned   = ($item['returnable'] <= 0);
                                        $expiryFormatted = !empty($item['expiry_date'])
                                            ? date('d M Y', strtotime($item['expiry_date'])) : '-';
                                        $uop = (int)$item['units_per_pack'];
                                    ?>
                                    <tr style="<?php echo $fullyReturned ? 'opacity:0.5;' : ''; ?>">
                                        <td style="color: var(--pharmacy-text);"><?php echo $sr++; ?></td>
                                        <td>
                                            <strong style="color: var(--pharmacy-heading);"><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                            <?php if (!empty($item['generic_name'])): ?>
                                            <br><small style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);"><?php echo htmlspecialchars($item['generic_name']); ?></small>
                                            <?php endif; ?>
                                            <?php if ($uop > 1): ?>
                                            <br><span class="status-badge" style="background: var(--pharmacy-table-th); color: var(--pharmacy-text); font-size:0.65rem;"><?php echo $uop; ?> pcs/pack</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color: var(--pharmacy-text);"><?php echo htmlspecialchars($item['batch_number']); ?></td>
                                        <td style="color: var(--pharmacy-text);"><?php echo $expiryFormatted; ?></td>
                                        <td class="text-center">
                                            <?php if ($uop > 1): ?>
                                            <strong style="color: var(--pharmacy-heading);"><?php echo $item['purchased_packs']; ?></strong>
                                            <small class="d-block" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);"><?php echo $item['purchased_pieces']; ?> pcs</small>
                                            <?php else: ?>
                                            <strong style="color: var(--pharmacy-heading);"><?php echo $item['purchased_pieces']; ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($item['pieces_sold'] > 0): ?>
                                            <span class="status-badge badge-expiring"><?php echo $item['pieces_sold']; ?></span>
                                            <?php else: ?>
                                            <span style="color: color-mix(in srgb, var(--pharmacy-text) 40%, transparent);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($item['already_returned'] > 0): ?>
                                            <span class="status-badge badge-oos"><?php echo $item['already_returned']; ?></span>
                                            <?php else: ?>
                                            <span style="color: color-mix(in srgb, var(--pharmacy-text) 40%, transparent);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="status-badge <?php echo $fullyReturned ? '' : 'badge-instock'; ?>" style="<?php echo $fullyReturned ? 'background: var(--pharmacy-table-th); color: var(--pharmacy-text);' : ''; ?> font-size: 0.78rem; padding: 4px 10px;">
                                                <?php echo $item['returnable']; ?>
                                            </span>
                                            <small class="d-block" style="font-size:0.65rem; color: color-mix(in srgb, var(--pharmacy-text) 50%, transparent);">in stock</small>
                                        </td>
                                        <td class="text-end" style="color: var(--pharmacy-text);">
                                            Rs <?php echo number_format($item['unit_price'], 2); ?>
                                            <small class="d-block" style="font-size: 0.68rem; color: color-mix(in srgb, var(--pharmacy-text) 50%, transparent);">per piece</small>
                                        </td>
                                        <td class="text-center">
                                            <input type="number"
                                                   class="form-control form-control-sm return-qty text-center"
                                                   data-item-id="<?php echo (int)$item['id']; ?>"
                                                   data-max="<?php echo (int)$item['returnable']; ?>"
                                                   data-price="<?php echo (float)$item['unit_price']; ?>"
                                                   data-uop="<?php echo $uop; ?>"
                                                   value="0" min="0" max="<?php echo (int)$item['returnable']; ?>"
                                                   placeholder="pcs"
                                                   style="width:85px; margin:auto;"
                                                   <?php echo $fullyReturned ? 'disabled' : ''; ?>>
                                            <?php if ($uop > 1): ?>
                                            <small class="d-block" style="font-size:0.65rem; color: color-mix(in srgb, var(--pharmacy-text) 50%, transparent);">pieces</small>
                                            <?php endif; ?>
                                            <div class="stock-warning" style="display:none; background: #fff3cd; border-left: 3px solid #f59e0b; padding: 4px 8px; margin-top: 4px; font-size: 11px; border-radius: 4px;">
                                                ⚠️ Exceeds returnable!
                                            </div>
                                        </td>
                                        <td class="text-end item-return-total fw-semibold" style="color: var(--pharmacy-text);">Rs 0.00</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background: var(--pharmacy-table-th);">
                                        <td colspan="10" class="text-end fw-bold" style="color: var(--pharmacy-heading);">Total Return Amount:</td>
                                        <td class="text-end">
                                            <strong id="total_return_amount" style="color: #ef4444; font-size: 1rem;">Rs 0.00</strong>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Refund Details Card -->
                    <div class="card mb-4" style="border-radius: var(--card-border-radius,12px); border: 1px solid var(--pharmacy-border); box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                        <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 1px solid var(--pharmacy-border); border-radius: calc(var(--card-border-radius,12px) - 1px) calc(var(--card-border-radius,12px) - 1px) 0 0;">
                            <span class="fw-bold" style="color: var(--pharmacy-heading);">
                                <i class="fas fa-file-invoice me-2" style="color: var(--pharmacy-primary);"></i>Refund Details
                            </span>
                        </div>
                        <div class="card-body" style="background: var(--pharmacy-card-bg);">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold" style="color: var(--pharmacy-text);">
                                        Credit Note Reference <span style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">(optional)</span>
                                    </label>
                                    <input type="text" id="credit_note_ref" name="credit_note_ref"
                                           class="form-control" placeholder="e.g. CN-2026-00123">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold" style="color: var(--pharmacy-text);">Refund Method</label>
                                    <div class="d-flex gap-3 flex-wrap mt-1">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="refund_method"
                                                   id="rm_credit_note" value="credit_note" checked>
                                            <label class="form-check-label" for="rm_credit_note" style="color: var(--pharmacy-text);">Credit Note</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="refund_method"
                                                   id="rm_cash" value="cash">
                                            <label class="form-check-label" for="rm_cash" style="color: var(--pharmacy-text);">Cash Refund</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="refund_method"
                                                   id="rm_bank" value="bank">
                                            <label class="form-check-label" for="rm_bank" style="color: var(--pharmacy-text);">Bank Transfer</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div id="refund_preview" class="mt-3 alert alert-info" style="display:none; border-radius: 8px;">
                                <i class="fas fa-info-circle me-2"></i>
                                <span id="refund_preview_text"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Return Reason Card -->
                    <div class="card mb-4" style="border-radius: var(--card-border-radius,12px); border: 1px solid var(--pharmacy-border); box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                        <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 1px solid var(--pharmacy-border); border-radius: calc(var(--card-border-radius,12px) - 1px) calc(var(--card-border-radius,12px) - 1px) 0 0;">
                            <span class="fw-bold" style="color: var(--pharmacy-heading);">
                                <i class="fas fa-comment me-2" style="color: var(--pharmacy-primary);"></i>Return Reason
                            </span>
                        </div>
                        <div class="card-body" style="background: var(--pharmacy-card-bg);">
                            <textarea name="overall_reason" id="overall_reason" class="form-control" rows="3"
                                      placeholder="e.g. Damaged goods, expired, wrong items..." required></textarea>
                        </div>
                    </div>

                    <div class="text-end mb-4">
                        <button type="button" id="btn_process_return" class="btn btn-danger btn-lg">
                            <i class="fas fa-exchange-alt me-2"></i> Process Return to Supplier
                        </button>
                    </div>
                </form>
            </div>

            <!-- Return History Tab -->
            <div class="tab-pane fade" id="returnHistory" role="tabpanel">
                <div class="table-card">
                    <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 2px solid var(--pharmacy-border); padding: 14px 18px;">
                        <span class="fw-bold" style="color: var(--pharmacy-heading);">
                            <i class="fas fa-history me-2" style="color: var(--pharmacy-primary);"></i>Previous Returns for this Purchase
                        </span>
                    </div>
                    <?php if (empty($returnHistory)): ?>
                    <div class="p-4">
                        <p class="mb-0" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">No previous returns for this purchase.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Return No</th>
                                    <th>Date</th>
                                    <th class="text-end">Amount</th>
                                    <th>Refund Method</th>
                                    <th>Credit Note</th>
                                    <th>Reason</th>
                                    <th>Processed By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($returnHistory as $ret): ?>
                                <tr>
                                    <td><strong style="color: var(--pharmacy-primary);"><?php echo htmlspecialchars($ret['return_no'] ?? '—'); ?></strong></td>
                                    <td style="color: var(--pharmacy-text);"><?php echo !empty($ret['created_at']) ? date('d M Y H:i', strtotime($ret['created_at'])) : '—'; ?></td>
                                    <td class="text-end fw-bold" style="color: #10b981;">Rs <?php echo number_format((float)($ret['total_amount'] ?? 0), 2); ?></td>
                                    <td>
                                        <?php $rm = $ret['refund_method'] ?? 'credit_note'; ?>
                                        <span class="status-badge <?php echo $rm === 'cash' ? 'badge-instock' : ($rm === 'bank' ? '' : 'badge-expiring'); ?>"
                                              style="<?php echo $rm === 'bank' ? 'background: color-mix(in srgb, var(--pharmacy-primary) 15%, transparent); color: var(--pharmacy-primary);' : ''; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $rm)); ?>
                                        </span>
                                    </td>
                                    <td style="color: var(--pharmacy-text);"><?php echo htmlspecialchars($ret['credit_note_ref'] ?? '-'); ?></td>
                                    <td style="color: color-mix(in srgb, var(--pharmacy-text) 70%, transparent); font-size: 0.85rem;"><?php echo htmlspecialchars($ret['reason'] ?? '-'); ?></td>
                                    <td style="color: var(--pharmacy-text);"><?php echo htmlspecialchars($ret['returned_by_name'] ?? '—'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background: var(--pharmacy-table-th);">
                                    <td colspan="2" class="text-end fw-bold" style="color: var(--pharmacy-heading);">Total Returned:</td>
                                    <td class="text-end fw-bold" style="color: #10b981;">
                                        Rs <?php echo number_format(array_sum(array_column($returnHistory, 'total_amount')), 2); ?>
                                    </td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Hidden print slip -->
        <div id="return-slip" style="display:none;"></div>

        <?php endif; ?>
    </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="returnSuccessModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h6 class="modal-title"><i class="fas fa-check-circle me-2"></i> Return Processed</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="background: var(--pharmacy-card-bg);">
                <div class="text-center">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <h5 id="modal_return_no" class="mb-1" style="color: var(--pharmacy-heading);">—</h5>
                    <p class="mb-1" style="color: color-mix(in srgb, var(--pharmacy-text) 60%, transparent);">Return Amount</p>
                    <h3 id="modal_return_amount" class="text-success">Rs 0.00</h3>
                    <hr style="border-color: var(--pharmacy-border);">
                    <div id="modal_stock_removed" class="small text-start" style="color: var(--pharmacy-text);"></div>
                    <div id="modal_supplier_balance" class="small text-start mt-2" style="color: var(--pharmacy-text);"></div>
                </div>
            </div>
            <div class="modal-footer justify-content-center gap-2" style="background: var(--pharmacy-card-bg); border-top: 1px solid var(--pharmacy-border);">
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-primary" id="btn_print_slip">
                    <i class="fas fa-print me-2"></i> Print Slip
                </button>
                <button class="btn btn-success" id="btn_reload_page">
                    <i class="fas fa-sync me-2"></i> Refresh
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Print styles -->
<style>
@media print {
    body * { visibility: hidden; }
    #return-slip, #return-slip * { visibility: visible; }
    #return-slip { position: fixed; left: 0; top: 0; width: 80mm; font-family: monospace; font-size: 11px; display: block !important; }
}
</style>

<?php include '../includes/footer.php'; ?>

<script>
function updateTotalsAndPreview() {
    let grand = 0;
    document.querySelectorAll('.return-qty').forEach(function(inp) {
        let qty       = parseInt(inp.value)               || 0;
        let price     = parseFloat(inp.dataset.price)     || 0;
        let lineTotal = qty * price;
        grand += lineTotal;
        inp.closest('tr').querySelector('.item-return-total').textContent = 'Rs ' + lineTotal.toFixed(2);
    });
    document.getElementById('total_return_amount').textContent = 'Rs ' + grand.toFixed(2);

    let method = document.querySelector('input[name="refund_method"]:checked')?.value || 'credit_note';
    let previewText = method === 'credit_note'
        ? `Credit note value: Rs ${grand.toFixed(2)} (no cash movement — reduces payable balance)`
        : `Supplier will refund: Rs ${grand.toFixed(2)} via ${method === 'cash' ? 'Cash' : 'Bank Transfer'}`;
    let previewEl = document.getElementById('refund_preview');
    if (grand > 0) {
        previewEl.style.display = '';
        document.getElementById('refund_preview_text').textContent = previewText;
    } else {
        previewEl.style.display = 'none';
    }
}

document.querySelectorAll('.return-qty').forEach(function(inp) {
    inp.addEventListener('input', function() {
        let max = parseInt(this.dataset.max) || 0;
        let qty = parseInt(this.value) || 0;
        if (qty < 0) qty = 0;
        if (qty > max) {
            qty = max;
            this.closest('td').querySelector('.stock-warning').style.display = '';
        } else {
            this.closest('td').querySelector('.stock-warning').style.display = 'none';
        }
        this.value = qty;
        updateTotalsAndPreview();
    });
});

document.querySelectorAll('input[name="refund_method"]').forEach(function(r) {
    r.addEventListener('change', updateTotalsAndPreview);
});

function showToast(message, type) {
    type = type || 'success';
    let bg = type === 'success' ? 'bg-success' : (type === 'danger' ? 'bg-danger' : 'bg-warning');
    let container = document.getElementById('toastContainer');
    let div = document.createElement('div');
    div.innerHTML = `<div class="toast align-items-center text-white ${bg} border-0" role="alert" data-bs-autohide="true" data-bs-delay="5000">
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>`;
    container.appendChild(div.firstChild);
    let toastEl = container.lastChild;
    new bootstrap.Toast(toastEl).show();
    toastEl.addEventListener('hidden.bs.toast', function() { this.remove(); });
}

document.getElementById('btn_process_return')?.addEventListener('click', function() {
    let reason = document.getElementById('overall_reason').value.trim();
    if (!reason) { showToast('Please enter a return reason.', 'danger'); document.getElementById('overall_reason').focus(); return; }

    let items = [];
    document.querySelectorAll('.return-qty').forEach(function(inp) {
        let qty = parseInt(inp.value) || 0;
        if (qty > 0) items.push({ item_id: inp.dataset.itemId, quantity: qty });
    });
    if (!items.length) { showToast('Please enter a return quantity for at least one item.', 'danger'); return; }

    let totalReturn    = parseFloat(document.getElementById('total_return_amount').textContent.replace('Rs ', '').replace(/,/g, '')) || 0;
    let netAfterReturns = <?php echo json_encode((float)$netAfterReturns); ?>;
    if (totalReturn > netAfterReturns + 0.01) { showToast('Return amount cannot exceed net purchase amount after previous returns.', 'danger'); return; }
    if (!confirm(`Process this return to supplier?\nTotal: Rs ${totalReturn.toFixed(2)}\nStock will be deducted.`)) return;

    let btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing…';

    let postData = {
        csrf_token:      document.querySelector('input[name="csrf_token"]').value,
        type:            'purchase',
        parent_id:       document.querySelector('input[name="parent_id"]').value,
        items:           JSON.stringify(items),
        overall_reason:  reason,
        refund_method:   document.querySelector('input[name="refund_method"]:checked')?.value || 'credit_note',
        credit_note_ref: document.getElementById('credit_note_ref').value.trim()
    };

    fetch('../ajax/process_return.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(postData)
    })
    .then(r => r.json())
    .then(function(r) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-exchange-alt me-2"></i> Process Return to Supplier';
        if (r && r.success) {
            showToast(`✅ Return ${r.return_no} processed. Stock reduced.`, 'success');
            document.getElementById('modal_return_no').textContent = r.return_no;
            document.getElementById('modal_return_amount').textContent = 'Rs ' + parseFloat(r.return_amount).toFixed(2);
            let stockHtml = '<strong>Stock Removed:</strong><ul class="mb-0 mt-1">';
            if (r.stock_removed && r.stock_removed.length) {
                r.stock_removed.forEach(s => {
                    stockHtml += `<li>${s.product_name} (Batch: ${s.batch_no}) — ${s.qty_removed} units removed → Remaining: ${s.new_batch_qty}</li>`;
                });
            } else { stockHtml += '<li>No stock details returned</li>'; }
            stockHtml += '</ul>';
            document.getElementById('modal_stock_removed').innerHTML = stockHtml;
            if (r.supplier_new_balance !== undefined) {
                document.getElementById('modal_supplier_balance').innerHTML = `<strong>Supplier New Balance:</strong> Rs ${parseFloat(r.supplier_new_balance).toFixed(2)}`;
            }
            new bootstrap.Modal(document.getElementById('returnSuccessModal')).show();
            document.getElementById('alert_area').innerHTML = `<div class="alert alert-success alert-dismissible fade show" style="border-radius: var(--card-border-radius,12px);">
                <i class="fas fa-check-circle me-2"></i> ${r.return_no} — Return of <strong>Rs ${parseFloat(r.return_amount).toFixed(2)}</strong> processed.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
            document.querySelectorAll('#purchase_return_form input, #purchase_return_form textarea, #btn_process_return').forEach(el => el.disabled = true);
            // Build print slip
            let rows = '';
            items.forEach(it => {
                let inp  = document.querySelector(`.return-qty[data-item-id="${it.item_id}"]`);
                let name = inp ? inp.closest('tr').querySelector('td:nth-child(2) strong').textContent : '';
                let price = parseFloat(inp?.dataset.price) || 0;
                rows += `<tr><td>${name}</td><td style="text-align:center">${it.quantity}</td><td style="text-align:right">Rs ${(it.quantity * price).toFixed(2)}</td></tr>`;
            });
            let now = new Date().toLocaleString('en-PK');
            let cnRef = postData.credit_note_ref;
            document.getElementById('return-slip').innerHTML = `<div style="font-family:monospace;font-size:12px;padding:8px;">
                <div style="text-align:center;border-bottom:1px dashed #999;padding-bottom:6px;margin-bottom:6px;"><strong style="font-size:15px;">PURCHASE RETURN</strong><br>${r.return_no}<br><small>${now}</small></div>
                ${cnRef ? `<div>Credit Note: <strong>${cnRef}</strong></div>` : ''}
                <table style="width:100%;border-collapse:collapse;margin:8px 0;font-size:11px;"><tr style="border-bottom:1px solid #ddd"><th>Item</th><th>Qty</th><th style="text-align:right">Amt</th></tr>${rows}</table>
                <div style="border-top:1px dashed #999;padding-top:5px;"><div style="display:flex;justify-content:space-between;font-weight:bold;font-size:14px;"><span>TOTAL:</span><span>Rs ${parseFloat(r.return_amount).toFixed(2)}</span></div><div style="margin-top:4px;font-size:10px">Reason: ${reason}</div></div>
                <div style="text-align:center;margin-top:8px;font-size:10px;border-top:1px dashed #999;padding-top:6px;">Supplier Copy</div></div>`;
        } else {
            showToast('Error: ' + (r && r.message ? r.message : 'Unknown error'), 'danger');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-exchange-alt me-2"></i> Process Return to Supplier';
        showToast('Server error. Please check server logs.', 'danger');
    });
});

document.getElementById('btn_print_slip')?.addEventListener('click', function() { window.print(); });
document.getElementById('btn_reload_page')?.addEventListener('click', function() { location.reload(); });

updateTotalsAndPreview();
</script>