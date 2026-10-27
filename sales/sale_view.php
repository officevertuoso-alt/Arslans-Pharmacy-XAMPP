<?php
/**
 * sales/sale_view.php
 * Complete Sale Details View Page
 */

require_once '../config/config.php';
require_once '../includes/return_functions.php';

requireLogin();

$page_title = 'Sale Details - Pharmacy Management System';

$sale_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$sale_id) {
    header('Location: ../sales/sales_list.php');
    exit;
}

$db = db();

// Fetch sale master record
$sale = $db->getOne(
    "SELECT s.*,
            u.full_name as created_by_name
     FROM sales s
     LEFT JOIN users u ON s.created_by = u.id
     WHERE s.id = :id",
    [':id' => $sale_id]
);

if (!$sale) {
    die('<div class="alert alert-danger m-4">Sale not found.</div>');
}

// Fetch sale items  (si.discount removed — column does not exist in sale_items)
$items = $db->getAll(
    "SELECT
        si.id as sale_item_id,
        si.quantity,
        si.unit_price,
        si.total        as line_total,
        p.product_name,
        p.generic_name,
        p.brand_name,
        b.batch_number,
        b.expiry_date,
        v.variant_value,
        COALESCE((
            SELECT SUM(sr.quantity)
            FROM sale_returns sr
            WHERE sr.sale_item_id = si.id
        ), 0) as returned_qty
     FROM sale_items si
     JOIN products p   ON si.product_id = p.id
     JOIN batches  b   ON si.batch_id   = b.id
     LEFT JOIN product_variants v ON si.variant_id = v.id
     WHERE si.sale_id = :sid
     ORDER BY si.id",
    [':sid' => $sale_id]
);

// Fetch return history
$returns = $db->getAll(
    "SELECT sr.return_no, sr.quantity, sr.total_amount, sr.reason,
            sr.refund_method, sr.created_at,
            p.product_name,
            u.full_name as returned_by_name
     FROM sale_returns sr
     LEFT JOIN sale_items si ON sr.sale_item_id = si.id
     LEFT JOIN products   p  ON si.product_id   = p.id
     LEFT JOIN users      u  ON sr.returned_by  = u.id
     WHERE sr.sale_id = :sid
     ORDER BY sr.created_at DESC",
    [':sid' => $sale_id]
);

// Fetch cashbook entries
$cashEntries = $db->getAll(
    "SELECT * FROM cashbook
     WHERE reference_id = :id AND reference_type IN ('sale','sale_payment')
     ORDER BY created_at DESC",
    [':id' => $sale_id]
);

// Summary from return_functions
$returnSummary = getSaleReturnSummary($sale_id);

$totalReturned = $returnSummary['total_returned_amount'] ?? 0;
$netAmount     = $returnSummary['net_amount'] ?? $sale['grand_total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        body { background: #f0f2f5; }
        .main-content { margin-left: var(--sb-w, 260px); transition: margin-left .25s ease; padding: 20px; }
        .card-custom { background: #fff; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); margin-bottom: 20px; }
        .card-header-custom { background: #fff; border-bottom: 1px solid #eef2f6; padding: 15px 20px; border-radius: 15px 15px 0 0; font-weight: 600; font-size: 15px; }
        .info-label { color: #6c757d; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 2px; }
        .info-value { font-size: 15px; font-weight: 500; color: #212529; }
        .badge-paid     { background: #d1fae5; color: #065f46; }
        .badge-partial  { background: #fef3c7; color: #92400e; }
        .badge-pending  { background: #fee2e2; color: #991b1b; }
        .badge-voided   { background: #e5e7eb; color: #374151; }
        .summary-box { border-radius: 12px; padding: 18px 20px; }
        .summary-box .amount { font-size: 22px; font-weight: 700; }
        .summary-box .label  { font-size: 12px; opacity: .85; }
        .table th { font-size: 12px; text-transform: uppercase; letter-spacing: .4px; color: #6c757d; font-weight: 600; }
        .table td { vertical-align: middle; font-size: 14px; }
        @media print {
            .main-content { margin-left: 0 !important; }
            .no-print { display: none !important; }
            .card-custom { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
        }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
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
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h4 class="mb-1">Sale Details</h4>
            <p class="text-muted mb-0">Invoice: <strong><?php echo htmlspecialchars($sale['invoice_no']); ?></strong></p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-secondary">
                <i class="fas fa-print me-2"></i> Print
            </button>
            <?php if ($sale['status'] !== 'voided' && $sale['payment_status'] !== 'pending'): ?>
            <a href="../returns/sale_return.php?invoice_no=<?php echo urlencode($sale['invoice_no']); ?>" class="btn btn-warning">
                <i class="fas fa-undo-alt me-2"></i> Process Return
            </a>
            <?php endif; ?>
            <a href="javascript:history.back()" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left me-2"></i> Back
            </a>
        </div>
    </div>

    <!-- Summary Boxes -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="summary-box" style="background: linear-gradient(135deg,#667eea,#764ba2); color:#fff;">
                <div class="label">Grand Total</div>
                <div class="amount"><?php echo formatCurrency($sale['grand_total'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="summary-box" style="background: linear-gradient(135deg,#11998e,#38ef7d); color:#fff;">
                <div class="label">Paid Amount</div>
                <div class="amount"><?php echo formatCurrency($sale['paid_amount'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="summary-box" style="background: linear-gradient(135deg,#f093fb,#f5576c); color:#fff;">
                <div class="label">Total Returned</div>
                <div class="amount"><?php echo formatCurrency($totalReturned); ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="summary-box" style="background: linear-gradient(135deg,#4facfe,#00f2fe); color:#fff;">
                <div class="label">Net Amount</div>
                <div class="amount"><?php echo formatCurrency($netAmount); ?></div>
            </div>
        </div>
    </div>

    <!-- Sale Info -->
    <div class="card-custom">
        <div class="card-header-custom">
            <i class="fas fa-file-invoice me-2 text-primary"></i> Sale Information
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3 col-6">
                    <div class="info-label">Invoice No.</div>
                    <div class="info-value"><?php echo htmlspecialchars($sale['invoice_no']); ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="info-label">Sale Date</div>
                    <div class="info-value"><?php echo formatDate($sale['created_at'] ?? '', true); ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="info-label">Payment Status</div>
                    <div class="info-value">
                        <?php
                        $ps = $sale['payment_status'] ?? 'pending';
                        $badgeClass = match($ps) {
                            'paid'    => 'badge-paid',
                            'partial' => 'badge-partial',
                            'pending' => 'badge-pending',
                            'voided'  => 'badge-voided',
                            default   => 'badge-secondary'
                        };
                        ?>
                        <span class="badge <?php echo $badgeClass; ?> px-3 py-2" style="font-size:13px;">
                            <?php echo ucfirst($ps); ?>
                        </span>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="info-label">Sale Status</div>
                    <div class="info-value">
                        <span class="badge <?php echo $sale['status'] === 'voided' ? 'badge-voided' : 'badge-paid'; ?> px-3 py-2" style="font-size:13px;">
                            <?php echo ucfirst($sale['status'] ?? 'active'); ?>
                        </span>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="info-label">Customer Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="info-label">Customer Phone</div>
                    <div class="info-value"><?php echo htmlspecialchars($sale['customer_phone'] ?? '—'); ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="info-label">Payment Method</div>
                    <div class="info-value"><?php echo ucfirst($sale['payment_method'] ?? '—'); ?></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="info-label">Created By</div>
                    <div class="info-value"><?php echo htmlspecialchars($sale['created_by_name'] ?? '—'); ?></div>
                </div>
                <?php if (!empty($sale['discount'])): ?>
                <div class="col-md-3 col-6">
                    <div class="info-label">Discount</div>
                    <div class="info-value text-danger"><?php echo formatCurrency($sale['discount']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($sale['notes'])): ?>
                <div class="col-12">
                    <div class="info-label">Notes</div>
                    <div class="info-value"><?php echo nl2br(htmlspecialchars($sale['notes'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sale Items -->
    <div class="card-custom">
        <div class="card-header-custom">
            <i class="fas fa-pills me-2 text-success"></i> Sale Items
            <span class="badge bg-secondary ms-2"><?php echo count($items); ?> items</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Batch</th>
                            <th>Expiry</th>
                            <th class="text-center">Qty Sold</th>
                            <th class="text-center">Returned</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($items) > 0): ?>
                            <?php foreach ($items as $i => $item): ?>
                            <tr>
                                <td class="text-muted"><?php echo $i + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                    <?php if (!empty($item['generic_name'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($item['generic_name']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($item['variant_value'])): ?>
                                        <br><span class="badge bg-light text-dark"><?php echo htmlspecialchars($item['variant_value']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($item['batch_number']); ?></td>
                                <td>
                                    <?php
                                    $exp = $item['expiry_date'] ?? '';
                                    $expColor = '';
                                    if ($exp) {
                                        $expTs = strtotime($exp);
                                        if ($expTs < time()) $expColor = 'text-danger fw-bold';
                                        elseif ($expTs < strtotime('+3 months')) $expColor = 'text-warning fw-bold';
                                    }
                                    ?>
                                    <span class="<?php echo $expColor; ?>"><?php echo $exp ? date('M Y', strtotime($exp)) : '—'; ?></span>
                                </td>
                                <td class="text-center"><?php echo (int)$item['quantity']; ?></td>
                                <td class="text-center">
                                    <?php if ((int)$item['returned_qty'] > 0): ?>
                                        <span class="badge bg-warning text-dark"><?php echo (int)$item['returned_qty']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo formatCurrency($item['unit_price']); ?></td>
                                <td class="text-end fw-bold"><?php echo formatCurrency($item['line_total']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No items found</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="7" class="text-end fw-bold">Grand Total:</td>
                            <td class="text-end fw-bold text-primary fs-6"><?php echo formatCurrency($sale['grand_total'] ?? 0); ?></td>
                        </tr>
                        <?php if (!empty($sale['discount']) && $sale['discount'] > 0): ?>
                        <tr>
                            <td colspan="7" class="text-end text-muted">Discount:</td>
                            <td class="text-end text-danger">- <?php echo formatCurrency($sale['discount']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($totalReturned > 0): ?>
                        <tr>
                            <td colspan="7" class="text-end text-muted">Total Returned:</td>
                            <td class="text-end text-warning">- <?php echo formatCurrency($totalReturned); ?></td>
                        </tr>
                        <tr>
                            <td colspan="7" class="text-end fw-bold">Net Amount:</td>
                            <td class="text-end fw-bold text-success"><?php echo formatCurrency($netAmount); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Return History -->
    <?php if (count($returns) > 0): ?>
    <div class="card-custom">
        <div class="card-header-custom">
            <i class="fas fa-undo-alt me-2 text-warning"></i> Return History
            <span class="badge bg-warning text-dark ms-2"><?php echo count($returns); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Return No.</th>
                            <th>Product</th>
                            <th class="text-center">Qty</th>
                            <th>Refund Method</th>
                            <th>Reason</th>
                            <th>Returned By</th>
                            <th>Date</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returns as $ret): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($ret['return_no']); ?></strong></td>
                            <td><?php echo htmlspecialchars($ret['product_name'] ?? '—'); ?></td>
                            <td class="text-center"><?php echo (int)$ret['quantity']; ?></td>
                            <td><?php echo ucfirst($ret['refund_method'] ?? 'cash'); ?></td>
                            <td><small><?php echo htmlspecialchars($ret['reason'] ?? '—'); ?></small></td>
                            <td><?php echo htmlspecialchars($ret['returned_by_name'] ?? '—'); ?></td>
                            <td><?php echo formatDate($ret['created_at'], true); ?></td>
                            <td class="text-end text-danger fw-bold"><?php echo formatCurrency($ret['total_amount']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Cashbook Entries -->
    <?php if (count($cashEntries) > 0): ?>
    <div class="card-custom no-print">
        <div class="card-header-custom">
            <i class="fas fa-book me-2 text-info"></i> Cashbook Entries
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Payment Method</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cashEntries as $ce): ?>
                        <tr>
                            <td><?php echo formatDate($ce['created_at'], true); ?></td>
                            <td><?php echo htmlspecialchars($ce['description'] ?? '—'); ?></td>
                            <td><?php echo ucfirst($ce['payment_method'] ?? '—'); ?></td>
                            <td class="text-end text-success"><?php echo $ce['debit'] > 0 ? formatCurrency($ce['debit']) : '—'; ?></td>
                            <td class="text-end text-danger"><?php echo $ce['credit'] > 0 ? formatCurrency($ce['credit']) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
