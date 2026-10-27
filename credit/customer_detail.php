<?php
/**
 * Credit Customer Detail Page
 * Displays all credit transactions for a specific customer
 */

require_once '../config/config.php';
require_once '../includes/sync_engine.php';

requireLogin();

$phone = isset($_GET['phone']) ? sanitize($_GET['phone']) : '';
if (empty($phone)) {
    header('Location: index.php');
    exit();
}

// Handle AJAX requests (mark overdue)
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    if ($_POST['ajax'] === 'mark_overdue' && isAdmin()) {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $db = db();
        $db->query("UPDATE customer_credits 
                    SET status = 'overdue' 
                    WHERE customer_phone = :phone 
                      AND due_date < CURDATE() 
                      AND status = 'active'");
        $db->bind(':phone', $phone);
        $affected = $db->execute() ? $db->rowCount() : 0;
        clearAllCaches();
        echo json_encode(['success' => true, 'updated' => $affected]);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$db = db();

// Fetch customer basic info (from credits or sales)
$customer = $db->getOne(
    "SELECT DISTINCT customer_name AS name, customer_phone AS phone 
     FROM customer_credits 
     WHERE customer_phone = :phone LIMIT 1",
    [':phone' => $phone]
);
if (!$customer) {
    $customer = $db->getOne(
        "SELECT DISTINCT customer_name AS name, customer_phone AS phone 
         FROM sales 
         WHERE customer_phone = :phone AND customer_name IS NOT NULL LIMIT 1",
        [':phone' => $phone]
    );
}
if (!$customer) {
    setFlash('error', 'Customer not found', 'danger');
    header('Location: index.php');
    exit();
}

$customerName = $customer['name'];
$customerPhone = $customer['phone'];

// --- FIXED SUMMARY QUERY ---
// Use two separate simple queries to avoid subquery issues
$totalCredit = (float) $db->getOne(
    "SELECT COALESCE(SUM(total_amount), 0) AS total FROM customer_credits WHERE customer_phone = :phone",
    [':phone' => $phone]
)['total'] ?? 0;

$totalPaid = (float) $db->getOne(
    "SELECT COALESCE(SUM(cp.amount), 0) AS total 
     FROM credit_payments cp
     JOIN customer_credits cc ON cc.id = cp.credit_id
     WHERE cc.customer_phone = :phone",
    [':phone' => $phone]
)['total'] ?? 0;

$netOutstanding = $totalCredit - $totalPaid;

// Fetch all credits for this customer
$credits = $db->getAll(
    "SELECT 
        cc.*,
        s.invoice_no,
        s.sale_date,
        COALESCE((SELECT SUM(amount) FROM credit_payments WHERE credit_id = cc.id), 0) AS paid_amount
     FROM customer_credits cc
     LEFT JOIN sales s ON s.id = cc.sale_id
     WHERE cc.customer_phone = :phone
     ORDER BY cc.created_at DESC",
    [':phone' => $phone]
);

// Fetch payment history
$payments = $db->getAll(
    "SELECT 
        cp.*,
        cc.customer_name,
        (SELECT id FROM cashbook 
         WHERE entry_type = 'credit_received' 
           AND reference_id = cp.id 
         LIMIT 1) AS cashbook_entry_id
     FROM credit_payments cp
     JOIN customer_credits cc ON cc.id = cp.credit_id
     WHERE cc.customer_phone = :phone
     ORDER BY cp.payment_date DESC",
    [':phone' => $phone]
);

$pageTitle = 'Customer Credit Details: ' . htmlspecialchars($customerName);
include '../includes/header.php';
include '../includes/sidebar.php';
include '../includes/navbar.php';
?>

<div class="main-content">
<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mt-3 mb-4">
        <h1 class="h3">
            <i class="fas fa-user-circle me-2"></i> Credit Details: <?= htmlspecialchars($customerName) ?>
            <small class="text-muted fs-6">(<?= htmlspecialchars($customerPhone) ?>)</small>
        </h1>
        <div>
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <a href="<?= BASE_URL ?>credit/index.php" class="btn btn-outline-primary ms-2">
                <i class="fas fa-arrow-left me-1"></i> Back to Credits
            </a>
        </div>
    </div>

    <!-- Summary Cards Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-primary h-100">
                <div class="card-body">
                    <div class="text-muted small">Total Credit Given</div>
                    <div class="h3 mt-2"><?= formatCurrency($totalCredit) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-success h-100">
                <div class="card-body">
                    <div class="text-muted small">Total Paid</div>
                    <div class="h3 mt-2 text-success"><?= formatCurrency($totalPaid) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-danger h-100">
                <div class="card-body">
                    <div class="text-muted small">Net Outstanding</div>
                    <div class="h3 mt-2 text-danger"><?= formatCurrency($netOutstanding) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Credits Table -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-file-invoice me-1"></i> Credit Transactions</span>
            <?php if (isAdmin()): ?>
                <button type="button" id="markOverdueBtn" class="btn btn-sm btn-warning"
                        data-requires-perm="credit.mark_overdue">
                    <i class="fas fa-exclamation-triangle me-1"></i> Mark All Overdue
                </button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Sale Invoice</th>
                            <th>Sale Date</th>
                            <th>Credit Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($credits)): ?>
                            <tr><td colspan="8" class="text-center text-muted">No credit records found.<?= '?' ?></td></td> ?>
                        <?php else: ?>
                            <?php foreach ($credits as $credit):
                                $creditId = $credit['id'];
                                $totalAmount = (float) $credit['total_amount'];
                                $paidAmount = (float) ($credit['paid_amount'] ?? 0);
                                $balance = max(0, $totalAmount - $paidAmount);
                                $isOverdue = (strtotime($credit['due_date']) < time()) && $credit['status'] === 'active';
                                $status = $credit['status'];
                                if ($isOverdue && $status === 'active') $status = 'overdue';
                            ?>
                            <tr>
                                <td>
                                    <?php if (!empty($credit['invoice_no'])): ?>
                                        <a href="<?= BASE_URL ?>sales/view.php?invoice=<?= urlencode($credit['invoice_no']) ?>" class="text-decoration-none">
                                            <code><?= htmlspecialchars($credit['invoice_no']) ?></code>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatDate($credit['sale_date'] ?? $credit['created_at'], 'd-m-Y') ?></td>
                                <td class="text-end"><?= formatCurrency($totalAmount) ?></td>
                                <td><?= formatDate($credit['due_date'], 'd-m-Y') ?></td>
                                <td>
                                    <?php if ($status === 'paid'): ?>
                                        <span class="badge bg-success">Paid</span>
                                    <?php elseif ($status === 'overdue'): ?>
                                        <span class="badge bg-danger">Overdue</span>
                                    <?php elseif ($status === 'active'): ?>
                                        <span class="badge bg-primary">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= ucfirst($status) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= formatCurrency($paidAmount) ?></td>
                                <td class="text-end fw-bold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= formatCurrency($balance) ?>
                                </td>
                                <td>
                                    <?php if ($balance > 0 && $status !== 'paid' && $status !== 'cancelled'): ?>
                                        <button class="btn btn-sm btn-success pay-credit-btn"
                                                data-id="<?= $creditId ?>"
                                                data-invoice="<?= htmlspecialchars($credit['sale_id']) ?>"
                                                data-total="<?= $totalAmount ?>"
                                                data-balance="<?= $balance ?>"
                                                data-requires-perm="credit.pay">
                                            <i class="fas fa-money-bill-wave me-1"></i> Pay
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-secondary" disabled>Paid</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Payment History Table -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-history me-1"></i> Payment History
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Notes</th>
                            <th>Cashbook Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr><td colspan="6" class="text-center text-muted">No payments recorded.<?= '?' ?></td></td> ?>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= formatDate($payment['payment_date'], 'd-m-Y') ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($payment['amount']) ?></td>
                                <td><?= ucfirst($payment['payment_method'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($payment['reference_no'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($payment['notes'] ?? '-') ?></td>
                                <td>
                                    <?php if (!empty($payment['cashbook_entry_id'])): ?>
                                        <span class="badge bg-success">Synced</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Missing</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Pay Credit Modal -->
<div class="modal fade" id="payCreditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Credit Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="payCreditForm">
                    <input type="hidden" id="pay_credit_id" name="credit_id">
                    <div class="mb-3">
                        <label class="form-label">Sale Invoice</label>
                        <input type="text" id="pay_invoice_no" class="form-control" readonly disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Total Credit</label>
                        <input type="text" id="pay_total_amount" class="form-control" readonly disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remaining Balance</label>
                        <input type="text" id="pay_balance" class="form-control" readonly disabled>
                    </div>
                    <div class="mb-3">
                        <label for="payment_amount" class="form-label">Payment Amount *</label>
                        <input type="number" step="0.01" id="payment_amount" name="amount" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="payment_method" class="form-label">Payment Method *</label>
                        <select id="payment_method" name="payment_method" class="form-select" required>
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="online">Online</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="reference_no" class="form-label">Reference No (optional)</label>
                        <input type="text" id="reference_no" name="reference_no" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label for="payment_notes" class="form-label">Notes</label>
                        <textarea id="payment_notes" name="notes" rows="2" class="form-control"></textarea>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="submitPaymentBtn" class="btn btn-primary">Submit Payment</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Pay button click
    $('.pay-credit-btn').on('click', function() {
        const creditId = $(this).data('id');
        const invoiceNo = $(this).data('invoice');
        const totalAmount = $(this).data('total');
        const balance = $(this).data('balance');
        
        $('#pay_credit_id').val(creditId);
        $('#pay_invoice_no').val(invoiceNo);
        $('#pay_total_amount').val(formatCurrencyNumber(totalAmount));
        $('#pay_balance').val(formatCurrencyNumber(balance));
        $('#payment_amount').val(balance.toFixed(2));
        $('#payment_method').val('cash');
        $('#reference_no').val('');
        $('#payment_notes').val('');
        
        $('#payCreditModal').modal('show');
    });
    
    // Submit payment
    $('#submitPaymentBtn').on('click', function() {
        const amount = parseFloat($('#payment_amount').val());
        const balanceText = $('#pay_balance').val();
        const balance = parseFloat(balanceText.replace(/[^0-9.-]+/g, ''));
        
        if (isNaN(amount) || amount <= 0) {
            Notify.error('Please enter a valid amount');
            return;
        }
        if (amount > balance) {
            Notify.error('Payment amount cannot exceed remaining balance');
            return;
        }
        
        const formData = {
            credit_id: $('#pay_credit_id').val(),
            amount: amount,
            payment_method: $('#payment_method').val(),
            reference_no: $('#reference_no').val(),
            notes: $('#payment_notes').val(),
            csrf_token: $('input[name="csrf_token"]').val()
        };
        
        $.ajax({
            url: '<?= BASE_URL ?>credit/pay_credit.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Notify.success(response.message, { onClose: function(){ location.reload(); } });
                } else {
                    Notify.error(response.message || 'Payment failed');
                }
            },
            error: function() {
                Notify.error('Server error. Please try again.');
            }
        });
    });
    
    // Mark All Overdue
    $('#markOverdueBtn').on('click', function() {
        Notify.confirm(
            'This will mark all active credits with due date before today as overdue.',
            function() {
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        ajax: 'mark_overdue',
                        csrf_token: '<?= generateCSRFToken() ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Notify.success(response.updated + ' credit(s) marked as overdue.', { onClose: function(){ location.reload(); } });
                        } else {
                            Notify.error(response.message);
                        }
                    },
                    error: function() {
                        Notify.error('Request failed');
                    }
                });
            },
            { title: 'Mark All Overdue?', okText: 'Yes, mark overdue', danger: true }
        );
    });
    
    function formatCurrencyNumber(value) {
        return 'Rs ' + parseFloat(value).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
});
</script>

</div><!-- /main-content -->
<?php include '../includes/footer.php'; ?>