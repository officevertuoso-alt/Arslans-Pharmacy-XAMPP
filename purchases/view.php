<?php
require_once '../config/config.php';
require_once '../includes/purchase_functions.php';
require_once '../includes/return_functions.php';
require_once '../includes/sync_engine.php';

requirePharmacist();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$purchase = getPurchaseById($id);

if (!$purchase) {
    header('Location: list.php?error=Purchase not found');
    exit;
}

// Returns for this purchase — safe query, no JOIN on potentially-missing columns
try {
    $returns = _pdoAll(
        "SELECT pr.*,
                (SELECT full_name FROM users WHERE id = pr.returned_by LIMIT 1) AS returned_by_name,
                (SELECT p.product_name FROM purchase_items pi
                 JOIN products p ON p.id = pi.product_id
                 WHERE pi.id = pr.purchase_item_id LIMIT 1) AS product_name
         FROM purchase_returns pr
         WHERE pr.purchase_id = :pid
         ORDER BY pr.created_at DESC",
        [':pid' => $id]
    );
} catch (\Throwable $e) {
    // Fallback: schema migration may not have run yet — use minimal safe query
    error_log("view.php returns query error: " . $e->getMessage());
    try {
        $returns = _pdoAll(
            "SELECT * FROM purchase_returns WHERE purchase_id = :pid ORDER BY id DESC",
            [':pid' => $id]
        );
        // Patch missing keys so the template never throws undefined index
        foreach ($returns as &$r) {
            $r['returned_by_name'] = $r['returned_by_name'] ?? '—';
            $r['product_name']     = $r['product_name']     ?? '—';
            $r['returned_by']      = $r['returned_by']      ?? null;
        }
        unset($r);
    } catch (\Throwable $e2) {
        $returns = [];
    }
}

// Cashbook entries linked to this purchase (payments + returns)
try {
    $cashbookEntries = _pdoAll(
        "SELECT c.*
         FROM cashbook c
         WHERE c.reference_id = :pid
           AND c.reference_type IN ('purchase', 'purchase_return')
         ORDER BY c.entry_date ASC",
        [':pid' => $id]
    );
} catch (\Throwable $e) {
    error_log("view.php cashbook query error: " . $e->getMessage());
    $cashbookEntries = [];
}

// Supplier payments (if any)
try {
    $payments = _pdoAll(
        "SELECT sp.*
         FROM supplier_payments sp
         WHERE sp.purchase_id = :pid
         ORDER BY sp.payment_date DESC",
        [':pid' => $id]
    );
} catch (\Throwable $e) {
    error_log("view.php payments query error: " . $e->getMessage());
    $payments = [];
}

// Summary after returns
$returnSummary = getPurchaseReturnSummary($id);
$netTotal = $purchase['total'] - $returnSummary['total_returned_amount'];
$dueAmount = max(0, $netTotal - $purchase['paid_amount']);
$paymentStatus = ($dueAmount <= 0) ? 'paid' : ($purchase['paid_amount'] > 0 ? 'partial' : 'pending');

$page_title = 'Purchase Invoice #' . htmlspecialchars($purchase['purchase_invoice_no']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    <style>
        body { background: #f0f2f5; }
        .main-content { margin-left: var(--sb-w, 260px); transition: margin-left .25s ease; padding: 20px; }
        .invoice-container { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 2px 15px rgba(0,0,0,0.1); max-width: 1000px; margin: 0 auto; }
        .info-card { background: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
        @media print {
            .main-content { margin-left: 0; padding: 0; }
            .no-print { display: none; }
            .invoice-container { box-shadow: none; padding: 15px; }
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
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <div class="d-flex align-items-center gap-3">
                <a href="list.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i> Back to Purchases</a>
                <span id="payment_status_badge" class="badge fs-6 px-3 py-2
                    <?php echo $paymentStatus === 'paid' ? 'bg-success' : ($paymentStatus === 'partial' ? 'bg-warning text-dark' : 'bg-danger'); ?>">
                    <?php echo ucfirst($paymentStatus); ?>
                </span>
            </div>
            <div class="d-flex gap-2">
                <?php if ($paymentStatus !== 'paid'): ?>
                <button class="btn btn-success no-print" data-bs-toggle="modal" data-bs-target="#makePaymentModal">
                    <i class="fas fa-money-bill-wave me-2"></i>Make Payment
                    <span class="badge bg-light text-dark ms-1">Rs <?php echo number_format($dueAmount, 2); ?></span>
                </button>
                <?php endif; ?>
                <button onclick="window.print();" class="btn btn-primary"><i class="fas fa-print me-2"></i> Print Invoice</button>
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary no-print"><i class="fas fa-edit me-1"></i>Edit Purchase</a>
                <a href="add.php?product_id=<?php echo $purchase['items'][0]['product_id'] ?? ''; ?>" class="btn btn-outline-secondary">+ Add Stock</a>
            </div>
        </div>

        <!-- Purchase Details -->
        <div class="invoice-container">
            <div class="invoice-header d-flex justify-content-between align-items-end border-bottom pb-3 mb-3">
                <div>
                    <div class="invoice-title fs-3 fw-bold text-primary">PURCHASE INVOICE</div>
                    <div class="invoice-sub text-muted"># <?php echo htmlspecialchars($purchase['purchase_invoice_no']); ?></div>
                </div>
                <div class="text-end">
                    <div><strong>Date:</strong> <?php echo date('d/m/Y', strtotime($purchase['purchase_date'])); ?></div>
                    <div><strong>Status:</strong> <?php echo ucfirst($paymentStatus); ?></div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <strong>Supplier Details</strong><br>
                    <?php echo htmlspecialchars($purchase['supplier_name']); ?><br>
                    <?php echo htmlspecialchars($purchase['address'] ?? ''); ?><br>
                    Phone: <?php echo htmlspecialchars($purchase['phone'] ?? ''); ?><br>
                    Email: <?php echo htmlspecialchars($purchase['email'] ?? ''); ?>
                </div>
                <div class="col-md-6 text-md-end">
                    <strong>Payment Summary</strong><br>
                    Original Total: Rs <?php echo number_format($purchase['total'], 2); ?><br>
                    Total Returns: <span class="text-danger">Rs <?php echo number_format($returnSummary['total_returned_amount'], 2); ?></span><br>
                    Net Amount: <strong>Rs <?php echo number_format($netTotal, 2); ?></strong><br>
                    Paid: Rs <?php echo number_format($purchase['paid_amount'], 2); ?><br>
                    Due: <strong class="text-danger">Rs <?php echo number_format($dueAmount, 2); ?></strong>
                </div>
            </div>

            <!-- Items Table -->
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr><th>#</th><th>Product</th><th>Batch No.</th><th>Batch Details</th><th>Qty (Units)</th><th>Unit Price (Rs)</th><th>Total (Rs)</th></tr>
                </thead>
                <tbody>
                    <?php $sr = 1; foreach ($purchase['items'] as $item): 
                        $batchInfo = '';
                        if (isset($item['units_per_pack']) && isset($item['quantity_of_packs'])) {
                            $batchInfo = $item['units_per_pack'] . '/' . ($item['unit_type'] ?? 'pcs') . ' per pack, ' . $item['quantity_of_packs'] . ' pack(s)';
                        }
                        $totalQty = $item['total_pieces'] ?? ($item['quantity'] * $item['pieces_per_pack']);
                    ?>
                    <tr>
                        <td><?php echo $sr++; ?></td>
                        <td><strong><?php echo htmlspecialchars($item['product_name']); ?></strong><?php if (!empty($item['brand_name'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($item['brand_name']); ?></small><?php endif; ?></td>
                        <td><?php echo htmlspecialchars($item['batch_number']); ?></td>
                        <td><small><?php echo $batchInfo; ?></small></td>
                        <td><?php echo $totalQty; ?></td>
                        <td>Rs <?php echo number_format($item['unit_price'], 2); ?></td>
                        <td>Rs <?php echo number_format($item['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Totals -->
            <div class="totals-table mt-3" style="width:100%; max-width:350px; margin-left:auto;">
                <table class="table table-sm">
                    <tr><td>Subtotal:</td><td class="text-end">Rs <?php echo number_format($purchase['subtotal'], 2); ?></td></tr>
                    <?php if ($purchase['discount'] > 0): ?>
                    <tr><td>Discount:</td><td class="text-end">- Rs <?php echo number_format($purchase['discount'], 2); ?></td></tr>
                    <?php endif; ?>
                    <?php if ($purchase['tax_amount'] > 0): ?>
                    <tr><td>Tax:</td><td class="text-end">+ Rs <?php echo number_format($purchase['tax_amount'], 2); ?></td></tr>
                    <?php endif; ?>
                    <tr class="fw-bold"><td>Original Total:</td><td class="text-end">Rs <?php echo number_format($purchase['total'], 2); ?></td></tr>
                    <?php if ($returnSummary['total_returned_amount'] > 0): ?>
                    <tr><td>Total Returns:</td><td class="text-end text-danger">- Rs <?php echo number_format($returnSummary['total_returned_amount'], 2); ?></td></tr>
                    <tr class="table-success"><td>Net Total:</td><td class="text-end">Rs <?php echo number_format($netTotal, 2); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Returns Section -->
            <?php if (!empty($returns)): ?>
            <hr>
            <h6 class="mt-3"><i class="fas fa-undo-alt text-warning me-2"></i>Purchase Returns</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead><tr><th>Return No</th><th>Date</th><th>Product</th><th>Qty</th><th>Amount</th><th>Refund Method</th><th>Note</th></tr></thead>
                    <tbody>
                        <?php foreach ($returns as $ret): ?>
                        <tr>
                            <td><a href="../returns/purchase_return_view.php?id=<?php echo $ret['id']; ?>"><?php echo htmlspecialchars($ret['return_no']); ?></a></td>
                            <td><?php echo formatDate($ret['created_at'], true); ?></td>
                            <td><?php echo htmlspecialchars($ret['product_name'] ?? '—'); ?></td>
                            <td><?php echo $ret['quantity']; ?></td>
                            <td class="text-success">Rs <?php echo number_format($ret['total_amount'], 2); ?></td>
                            <td><?php echo ucfirst($ret['refund_method']); ?></td>
                            <td><?php echo htmlspecialchars($ret['credit_note_ref'] ?? $ret['reason'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Cashbook Entries -->
            <?php if (!empty($cashbookEntries)): ?>
            <hr>
            <h6 class="mt-3"><i class="fas fa-book me-2 text-info"></i>Cashbook Entries</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Debit</th><th>Credit</th><th>Method</th></tr></thead>
                    <tbody>
                        <?php foreach ($cashbookEntries as $cb): ?>
                        <tr>
                            <td><?php echo formatDate($cb['entry_date']); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $cb['entry_type'])); ?></td>
                            <td><?php echo htmlspecialchars($cb['description']); ?></td>
                            <td class="text-danger"><?php echo $cb['debit'] ? 'Rs '.number_format($cb['debit'],2) : '—'; ?></td>
                            <td class="text-success"><?php echo $cb['credit'] ? 'Rs '.number_format($cb['credit'],2) : '—'; ?></td>
                            <td><?php echo ucfirst($cb['payment_method']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Supplier Payments -->
            <?php if (!empty($payments)): ?>
            <hr>
            <h6 class="mt-3"><i class="fas fa-hand-holding-usd me-2 text-success"></i>Payments to Supplier</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($payments as $pay): ?>
                        <tr>
                            <td><?php echo formatDate($pay['payment_date']); ?></td>
                            <td class="text-success">Rs <?php echo number_format($pay['amount'], 2); ?></td>
                            <td><?php echo ucfirst($pay['payment_method']); ?></td>
                            <td><?php echo htmlspecialchars($pay['notes'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($purchase['notes'])): ?>
            <div class="mt-3 p-2 bg-light small">
                <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($purchase['notes'])); ?>
            </div>
            <?php endif; ?>
            <div class="text-center mt-4 text-muted small">Thank you for your business!</div>
        </div>

        <!-- Action Buttons (outside invoice container for print exclusion) -->
        <div class="d-flex gap-2 mt-3 no-print flex-wrap">
            <a href="../returns/purchase_return.php?invoice_no=<?php echo urlencode($purchase['purchase_invoice_no']); ?>"
               class="btn btn-warning">
                <i class="fas fa-exchange-alt me-2"></i>Create Return
            </a>
            <?php if ($paymentStatus !== 'paid'): ?>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#makePaymentModal">
                <i class="fas fa-money-bill-wave me-2"></i>Make Payment
            </button>
            <?php endif; ?>
        </div>
        <!-- Alert area for payment success/error -->
        <div id="payment_alert" class="mt-2"></div>

    </div>
</div>




<!-- Make Payment Modal -->
<div class="modal fade" id="makePaymentModal" tabindex="-1" aria-labelledby="makePaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="makePaymentModalLabel">
                    <i class="fas fa-money-bill-wave me-2"></i>Make Payment to Supplier
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 p-3 bg-light rounded">
                    <div class="row text-center">
                        <div class="col-4">
                            <small class="text-muted d-block">Net Total</small>
                            <strong>Rs <?php echo number_format($netTotal, 2); ?></strong>
                        </div>
                        <div class="col-4">
                            <small class="text-muted d-block">Paid</small>
                            <strong class="text-success">Rs <?php echo number_format($purchase['paid_amount'], 2); ?></strong>
                        </div>
                        <div class="col-4">
                            <small class="text-muted d-block">Due</small>
                            <strong class="text-danger" id="modal_due_display">Rs <?php echo number_format($dueAmount, 2); ?></strong>
                        </div>
                    </div>
                </div>
                <div id="payment_modal_alert"></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">Rs</span>
                        <input type="number" id="pay_amount" class="form-control" step="0.01" min="0.01"
                               max="<?php echo $dueAmount; ?>"
                               value="<?php echo number_format($dueAmount, 2, '.', ''); ?>"
                               placeholder="Enter amount">
                    </div>
                    <small class="text-muted">Max payable: Rs <?php echo number_format($dueAmount, 2); ?></small>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
                    <select id="pay_method" class="form-select">
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="online">Online / Mobile</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Reference No <small class="text-muted">(optional)</small></label>
                    <input type="text" id="pay_ref" class="form-control" placeholder="Cheque no, transaction ID…">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Notes <small class="text-muted">(optional)</small></label>
                    <textarea id="pay_notes" class="form-control" rows="2" placeholder="Any notes…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="btn_submit_payment" class="btn btn-success">
                    <i class="fas fa-check me-1"></i> Confirm Payment
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {

    // Submit payment via AJAX to suppliers/pay.php
    $('#btn_submit_payment').on('click', function () {
        const amount = parseFloat($('#pay_amount').val()) || 0;
        const method = $('#pay_method').val();
        const ref    = $('#pay_ref').val().trim();
        const notes  = $('#pay_notes').val().trim();
        const due    = <?php echo (float)$dueAmount; ?>;

        if (amount <= 0) {
            showPayAlert('Please enter a valid amount.', 'danger'); return;
        }
        if (amount > due + 0.01) {
            showPayAlert('Amount exceeds due amount of Rs ' + due.toFixed(2), 'danger'); return;
        }

        const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Processing…');

        $.ajax({
            url: '../suppliers/pay.php',
            method: 'POST',
            data: {
                csrf_token:    '<?php if(empty($_SESSION["csrf_token"])){$_SESSION["csrf_token"]=bin2hex(random_bytes(32));}echo $_SESSION["csrf_token"]; ?>',

                supplier_id:   <?php echo (int)$purchase['supplier_id']; ?>,
                purchase_id:   <?php echo $id; ?>,
                amount:        amount,
                payment_method: method,
                reference_no:  ref,
                notes:         notes,
                ajax:          1
            },
            dataType: 'json',
            success: function (r) {
                $btn.prop('disabled', false).html('<i class="fas fa-check me-1"></i> Confirm Payment');
                if (r && r.success) {
                    const newStatus = r.new_status || 'partial';
                    const badgeClass = newStatus === 'paid' ? 'bg-success' : (newStatus === 'partial' ? 'bg-warning text-dark' : 'bg-danger');
                    $('#payment_status_badge')
                        .removeClass('bg-success bg-warning bg-danger text-dark')
                        .addClass(badgeClass)
                        .text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));

                    if (newStatus === 'paid') {
                        $('[data-bs-target="#makePaymentModal"]').hide();
                    }

                    bootstrap.Modal.getInstance(document.getElementById('makePaymentModal')).hide();

                    $('#payment_alert').html(
                        '<div class="alert alert-success alert-dismissible fade show mt-2">' +
                        '<i class="fas fa-check-circle me-2"></i><strong>Payment of Rs ' + amount.toFixed(2) +
                        ' recorded successfully.</strong> Refreshing...' +
                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
                    );

                    setTimeout(() => location.reload(), 2000);
                } else {
                    showPayAlert(r.message || 'Payment failed.', 'danger');
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false).html('<i class="fas fa-check me-1"></i> Confirm Payment');
                let msg = 'Server error. Please try again.';
                try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                showPayAlert(msg, 'danger');
            }
        });
    });

    function showPayAlert(msg, type) {
        $('#payment_modal_alert').html(
            '<div class="alert alert-' + type + ' py-2">' + msg + '</div>'
        );
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>