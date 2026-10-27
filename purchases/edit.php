<?php
/**
 * purchases/edit.php — Edit Purchase (AJAX)
 * Phase 16: Edit purchase header (date, payment status, paid amount, notes)
 * Items cannot be changed after creation (use void + re-create for major changes)
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/purchase_functions.php';
require_once __DIR__ . '/../includes/sync_engine.php';

requirePharmacist();

// ── Handle AJAX save ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $id            = (int)($_POST['purchase_id'] ?? 0);
    $purchaseDate  = trim($_POST['purchase_date'] ?? '');
    $paymentStatus = trim($_POST['payment_status'] ?? '');
    $paidAmount    = (float)($_POST['paid_amount'] ?? 0);
    $notes         = trim($_POST['notes'] ?? '');

    if (!$id) { echo json_encode(['success' => false, 'message' => 'Purchase ID missing.']); exit; }

    $p = _pdoOne("SELECT * FROM purchases WHERE id=:id", [':id' => $id]);
    if (!$p) { echo json_encode(['success' => false, 'message' => 'Purchase not found.']); exit; }

    if (!in_array($paymentStatus, ['paid','partial','pending'])) {
        $paymentStatus = $p['payment_status'];
    }

    $grandTotal = (float)$p['total'];
    $due = max(0, $grandTotal - $paidAmount);
    $oldPaid = (float)$p['paid_amount'];

    _pdoRun(
        "UPDATE purchases SET purchase_date=:pdate, payment_status=:status, paid_amount=:paid, due_amount=:due, notes=:notes WHERE id=:id",
        [':pdate' => $purchaseDate ?: $p['purchase_date'],
         ':status' => $paymentStatus,
         ':paid'   => $paidAmount,
         ':due'    => $due,
         ':notes'  => $notes,
         ':id'     => $id]
    );

    // If paid amount increased, sync cashbook
    if ($paidAmount > $oldPaid) {
        $diff = $paidAmount - $oldPaid;
        try {
            $lastRow = _pdoOne("SELECT balance FROM cashbook WHERE voided=0 ORDER BY id DESC LIMIT 1");
            $bal = round((float)($lastRow['balance'] ?? 0) - $diff, 2);
            _pdoRun(
                "INSERT INTO cashbook (entry_date,entry_type,reference_id,reference_type,description,debit,credit,balance,payment_method,created_by)
                 VALUES (:date,'purchase_payment',:rid,'purchase',:desc,:debit,0,:bal,'cash',:user)",
                [':date'  => $purchaseDate ?: $p['purchase_date'],
                 ':rid'   => $id,
                 ':desc'  => "Payment update – purchase #{$id}",
                 ':debit' => $diff,
                 ':bal'   => $bal,
                 ':user'  => (int)($_SESSION['user_id'] ?? 0)]
            );
        } catch (\Throwable $e) {
            error_log("edit.php cashbook sync: " . $e->getMessage());
        }
    }

    echo json_encode(['success' => true, 'message' => 'Purchase updated successfully.']);
    exit;
}

// ── Load purchase for display ──────────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: list.php?error=No+ID+provided');
    exit;
}

$purchase = getPurchaseById($id);
if (!$purchase) {
    header('Location: list.php?error=Purchase+not+found');
    exit;
}

$csrfToken  = generateCSRFToken();
$page_title = 'Edit Purchase — ' . htmlspecialchars($purchase['purchase_invoice_no']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        .main-content { margin-left: var(--sb-w, 260px); padding: 24px 28px; transition: margin-left .3s ease; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991px) { .main-content { margin-left: 0 !important; } }
        .edit-card {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            max-width: 720px;
        }
        .edit-card h5 {
            font-weight: 700;
            color: var(--pharmacy-heading, #111827);
            border-bottom: 2px solid var(--pharmacy-border, #e5e7eb);
            padding-bottom: 12px;
            margin-bottom: 22px;
        }
        .invoice-badge {
            font-family: 'Courier New', monospace;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--pharmacy-primary, #0d6efd);
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 10%, transparent);
            padding: 4px 12px;
            border-radius: 8px;
        }
        .items-preview table th {
            background: var(--pharmacy-table-th, #f8fafc) !important;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 700;
        }
        .items-preview table td { font-size: 0.85rem; vertical-align: middle; }
        .alert-area { min-height: 20px; }
    </style>
</head>
<?php $__dm = !empty($_SESSION['company_settings_cache']['dark_mode']) ? ' dark-mode' : ''; ?>
<body class="<?php echo trim('bg-light' . $__dm); ?>">
<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-edit me-2 text-primary"></i>Edit Purchase</h4>
            <span class="invoice-badge"><?php echo htmlspecialchars($purchase['purchase_invoice_no']); ?></span>
        </div>
        <div class="d-flex gap-2">
            <a href="view.php?id=<?php echo $id; ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-eye me-1"></i>View Invoice
            </a>
            <a href="list.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    <div id="editAlertArea" class="alert-area mb-3"></div>

    <div class="edit-card">
        <h5><i class="fas fa-file-invoice me-2 text-primary"></i>Purchase Details</h5>

        <!-- Read-only summary -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6">
                <label class="form-label text-muted small fw-semibold text-uppercase">Supplier</label>
                <div class="fw-semibold"><?php echo htmlspecialchars($purchase['supplier_name']); ?></div>
            </div>
            <div class="col-sm-6">
                <label class="form-label text-muted small fw-semibold text-uppercase">Total Amount</label>
                <div class="fw-bold fs-5 text-primary">Rs <?php echo number_format($purchase['total'], 2); ?></div>
            </div>
        </div>

        <form id="editPurchaseForm">
            <input type="hidden" name="purchase_id" value="<?php echo $id; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control"
                           value="<?php echo htmlspecialchars($purchase['purchase_date']); ?>" required>
                </div>

                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Payment Status</label>
                    <select name="payment_status" id="editPaymentStatus" class="form-select" onchange="toggleEditPayment()">
                        <option value="pending"  <?php echo $purchase['payment_status']==='pending'  ? 'selected' : ''; ?>>Pending</option>
                        <option value="partial"  <?php echo $purchase['payment_status']==='partial'  ? 'selected' : ''; ?>>Partial</option>
                        <option value="paid"     <?php echo $purchase['payment_status']==='paid'     ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>

                <div class="col-sm-6" id="editPaidRow" style="<?php echo $purchase['payment_status']==='pending' ? 'display:none' : ''; ?>">
                    <label class="form-label fw-semibold">Paid Amount</label>
                    <div class="input-group">
                        <span class="input-group-text">Rs</span>
                        <input type="number" name="paid_amount" id="editPaidAmount" class="form-control"
                               value="<?php echo number_format($purchase['paid_amount'], 2, '.', ''); ?>"
                               min="0" step="0.01" max="<?php echo $purchase['total']; ?>"
                               oninput="updateDue()">
                    </div>
                </div>

                <div class="col-sm-6" id="editDueRow" style="<?php echo $purchase['payment_status']==='pending' ? 'display:none' : ''; ?>">
                    <label class="form-label fw-semibold text-danger">Due Amount</label>
                    <div class="form-control-plaintext fw-bold text-danger fs-5" id="editDueDisplay">
                        Rs <?php echo number_format(max(0, $purchase['total'] - $purchase['paid_amount']), 2); ?>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Optional notes"><?php echo htmlspecialchars($purchase['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" id="editSaveBtn">
                    <i class="fas fa-save me-2"></i>Save Changes
                </button>
                <a href="view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Items preview (read-only) -->
    <div class="edit-card mt-4" style="max-width:100%">
        <h5><i class="fas fa-boxes me-2 text-success"></i>Purchase Items <small class="text-muted fw-normal">(read-only)</small></h5>
        <div class="table-responsive">
            <table class="table table-hover table-bordered items-preview mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Batch No.</th>
                        <th class="text-center">Qty (Units)</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Total</th>
                        <th>Expiry</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = 1; foreach ($purchase['items'] as $item):
                        $totalQty = $item['total_pieces'] ?? ($item['quantity'] * ($item['pieces_per_pack'] ?? 1));
                    ?>
                    <tr>
                        <td class="text-muted"><?php echo $n++; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                            <?php if (!empty($item['brand_name'])): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($item['brand_name']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo htmlspecialchars($item['batch_number']); ?></code></td>
                        <td class="text-center"><?php echo number_format($totalQty); ?></td>
                        <td class="text-end">Rs <?php echo number_format($item['unit_price'] ?? $item['purchase_price'], 2); ?></td>
                        <td class="text-end fw-semibold">Rs <?php echo number_format($item['total'], 2); ?></td>
                        <td><?php echo $item['expiry_date'] ? date('d M Y', strtotime($item['expiry_date'])) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <td colspan="5" class="text-end fw-bold">Grand Total:</td>
                        <td class="text-end fw-bold text-primary">Rs <?php echo number_format($purchase['total'], 2); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>
</div>

<script>
const TOTAL = <?php echo (float)$purchase['total']; ?>;

function toggleEditPayment() {
    const status = document.getElementById('editPaymentStatus').value;
    const paidRow = document.getElementById('editPaidRow');
    const dueRow  = document.getElementById('editDueRow');
    if (status === 'pending') {
        paidRow.style.display = 'none';
        dueRow.style.display  = 'none';
    } else {
        paidRow.style.display = '';
        dueRow.style.display  = '';
        if (status === 'paid') {
            document.getElementById('editPaidAmount').value = TOTAL.toFixed(2);
            updateDue();
        }
    }
}

function updateDue() {
    const paid = parseFloat(document.getElementById('editPaidAmount').value) || 0;
    const due  = Math.max(0, TOTAL - paid);
    document.getElementById('editDueDisplay').textContent = 'Rs ' + due.toFixed(2);
}

document.getElementById('editPurchaseForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn  = document.getElementById('editSaveBtn');
    const data = new FormData(this);

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    fetch('edit.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: data
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-2"></i>Save Changes';
        const alertArea = document.getElementById('editAlertArea');
        if (res.success) {
            alertArea.innerHTML = '<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i>' + res.message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            setTimeout(() => { window.location.href = 'view.php?id=<?php echo $id; ?>'; }, 1200);
        } else {
            alertArea.innerHTML = '<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle me-2"></i>' + res.message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-2"></i>Save Changes';
        document.getElementById('editAlertArea').innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
