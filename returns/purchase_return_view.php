<?php
// returns/purchase_return_view.php
/**
 * View details of a single purchase return by ID.
 * ALL PHP logic preserved. UI updated: design system vars, stat cards, table-card, status-badge.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/return_functions.php';

requireLogin();

$return_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$return_id) {
    header('Location: purchase_return_list.php');
    exit;
}

$return = _pdoOne(
    "SELECT pr.*,
            p.purchase_invoice_no, p.purchase_date, p.total AS purchase_total,
            p.paid_amount AS purchase_paid,
            s.supplier_name,
            COALESCE(s.current_balance, 0) AS supplier_balance,
            COALESCE(s.due_amount, 0)      AS supplier_due,
            u.full_name AS returned_by_name
     FROM purchase_returns pr
     JOIN purchases  p  ON pr.purchase_id  = p.id
     JOIN suppliers  s  ON p.supplier_id   = s.id
     LEFT JOIN users u  ON pr.returned_by  = u.id
     WHERE pr.id = :rid",
    [':rid' => $return_id]
);

if (!$return) {
    echo '<div class="alert alert-danger m-4">Return record not found.</div>';
    exit;
}

$item = _pdoOne(
    "SELECT pi.quantity AS orig_qty, pi.unit_price AS orig_price,
            prod.product_name, prod.generic_name, prod.brand_name,
            COALESCE(prod.units_per_pack, 1) AS units_per_pack,
            b.batch_number, b.expiry_date,
            b.remaining_quantity AS current_batch_stock,
            COALESCE((
                SELECT SUM(si.quantity)
                FROM sale_items si WHERE si.batch_id = pi.batch_id
            ), 0) AS pieces_sold_from_batch
     FROM purchase_items pi
     JOIN products prod ON pi.product_id = prod.id
     LEFT JOIN batches  b  ON pi.batch_id  = b.id
     WHERE pi.id = :piid",
    [':piid' => $return['purchase_item_id']]
);

$cashbook = _pdoOne(
    "SELECT * FROM cashbook
     WHERE reference_id = :rid AND reference_type = 'purchase_return'
     ORDER BY id DESC LIMIT 1",
    [':rid' => $return_id]
);

$allReturns = _pdoAll(
    "SELECT pr.id, pr.return_no, pr.total_amount, pr.quantity,
            pr.refund_method, pr.reason, pr.credit_note_ref, pr.created_at,
            prod.product_name,
            (SELECT full_name FROM users WHERE id = pr.returned_by LIMIT 1) AS returned_by_name
     FROM purchase_returns pr
     LEFT JOIN purchase_items pi2  ON pr.purchase_item_id = pi2.id
     LEFT JOIN products prod       ON pi2.product_id      = prod.id
     WHERE pr.purchase_id = :pid
     ORDER BY pr.created_at DESC",
    [':pid' => $return['purchase_id']]
);

$totalReturnedForPurchase = array_sum(array_column($allReturns, 'total_amount'));

$page_title = 'Purchase Return Details — ' . htmlspecialchars($return['return_no']);
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<?php include '../includes/navbar.php'; ?>
<div class="main-content">
    <div class="content-area">

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 no-print flex-wrap gap-2">
            <div>
                <h4 class="mb-1 fw-bold" style="color: var(--pharmacy-heading);">
                    <i class="fas fa-undo-alt me-2" style="color: #ef4444;"></i>Purchase Return Details
                </h4>
                <p class="mb-0" style="color: color-mix(in srgb, var(--pharmacy-text) 60%, transparent); font-size: 0.88rem;">
                    <a href="purchase_return_list.php" style="color: color-mix(in srgb, var(--pharmacy-text) 60%, transparent); text-decoration: none;">
                        <i class="fas fa-arrow-left me-1"></i> Back to Return History
                    </a>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="purchase_return.php?invoice_no=<?php echo urlencode($return['purchase_invoice_no']); ?>"
                   class="btn btn-outline-primary btn-sm no-print">
                    <i class="fas fa-exchange-alt me-1"></i> New Return (same invoice)
                </a>
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
                    <i class="fas fa-print me-1"></i> Print
                </button>
            </div>
        </div>

        <!-- Hero Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: #ef4444;">
                    <div class="stat-label">Return Number</div>
                    <div class="stat-value" style="font-size: 1.1rem; color: #ef4444;"><?php echo htmlspecialchars($return['return_no']); ?></div>
                    <div class="stat-label mt-1"><?php echo !empty($return['created_at']) ? date('d M Y, h:i A', strtotime($return['created_at'])) : '—'; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: #10b981;">
                    <div class="stat-label">Return Amount</div>
                    <div class="stat-value" style="color: #10b981;">Rs <?php echo number_format((float)$return['total_amount'], 0); ?></div>
                    <div class="stat-label mt-1">Credited from Supplier</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: #f59e0b;">
                    <div class="stat-label">Refund Method</div>
                    <?php
                    $rm = $return['refund_method'] ?? 'credit_note';
                    $rmLabel = ucfirst(str_replace('_', ' ', $rm));
                    ?>
                    <div class="stat-value" style="font-size: 1rem; color: #f59e0b;"><?php echo $rmLabel; ?></div>
                    <?php if (!empty($return['credit_note_ref'])): ?>
                    <div class="stat-label mt-1">Ref: <?php echo htmlspecialchars($return['credit_note_ref']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: <?php echo $cashbook ? '#10b981' : '#ef4444'; ?>;">
                    <div class="stat-label">Cashbook Status</div>
                    <?php if ($cashbook): ?>
                    <div class="stat-value" style="font-size: 1rem; color: #10b981;"><i class="fas fa-check-circle me-1"></i>Synced</div>
                    <div class="stat-label mt-1">Entry #<?php echo $cashbook['id']; ?></div>
                    <?php else: ?>
                    <div class="stat-value" style="font-size: 1rem; color: #ef4444;"><i class="fas fa-exclamation-triangle me-1"></i>Not in Cashbook</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Supplier Info + Purchase Invoice -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card h-100" style="border-radius: var(--card-border-radius,12px); border: 1px solid var(--pharmacy-border); box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                    <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 1px solid var(--pharmacy-border); border-radius: calc(var(--card-border-radius,12px) - 1px) calc(var(--card-border-radius,12px) - 1px) 0 0;">
                        <span class="fw-bold" style="color: var(--pharmacy-heading);">
                            <i class="fas fa-truck me-2" style="color: var(--pharmacy-primary);"></i>Supplier Information
                        </span>
                    </div>
                    <div class="card-body" style="background: var(--pharmacy-card-bg);">
                        <div class="row g-3">
                            <div class="col-12">
                                <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Supplier Name</small>
                                <strong style="color: var(--pharmacy-heading); font-size: 1rem;"><?php echo htmlspecialchars($return['supplier_name']); ?></strong>
                            </div>
                            <div class="col-6">
                                <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Current Balance</small>
                                <strong style="color: var(--pharmacy-text);">Rs <?php echo number_format((float)$return['supplier_balance'], 2); ?></strong>
                            </div>
                            <div class="col-6">
                                <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Due Amount</small>
                                <strong style="color: <?php echo (float)$return['supplier_due'] > 0 ? '#ef4444' : '#10b981'; ?>;">Rs <?php echo number_format((float)$return['supplier_due'], 2); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100" style="border-radius: var(--card-border-radius,12px); border: 1px solid var(--pharmacy-border); box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                    <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 1px solid var(--pharmacy-border); border-radius: calc(var(--card-border-radius,12px) - 1px) calc(var(--card-border-radius,12px) - 1px) 0 0;">
                        <span class="fw-bold" style="color: var(--pharmacy-heading);">
                            <i class="fas fa-file-invoice me-2" style="color: var(--pharmacy-primary);"></i>Original Purchase Invoice
                        </span>
                    </div>
                    <div class="card-body" style="background: var(--pharmacy-card-bg);">
                        <div class="row g-3">
                            <div class="col-6">
                                <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Invoice No</small>
                                <strong style="color: var(--pharmacy-primary);"><?php echo htmlspecialchars($return['purchase_invoice_no']); ?></strong>
                            </div>
                            <div class="col-6">
                                <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Purchase Date</small>
                                <span style="color: var(--pharmacy-text);"><?php echo !empty($return['purchase_date']) ? date('d M Y', strtotime($return['purchase_date'])) : '—'; ?></span>
                            </div>
                            <div class="col-6">
                                <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Invoice Total</small>
                                <span style="color: var(--pharmacy-text);">Rs <?php echo number_format((float)$return['purchase_total'], 2); ?></span>
                            </div>
                            <div class="col-6">
                                <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Total Returned (all)</small>
                                <strong style="color: #ef4444;">Rs <?php echo number_format((float)$totalReturnedForPurchase, 2); ?></strong>
                            </div>
                            <div class="col-12">
                                <a href="../purchases/view.php?id=<?php echo (int)$return['purchase_id']; ?>"
                                   class="btn btn-sm btn-outline-primary no-print" target="_blank">
                                    <i class="fas fa-external-link-alt me-1"></i> View Original Invoice
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Returned Item Detail Table -->
        <div class="table-card mb-4">
            <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 2px solid var(--pharmacy-border); padding: 14px 18px;">
                <span class="fw-bold" style="color: var(--pharmacy-heading);">
                    <i class="fas fa-boxes me-2" style="color: var(--pharmacy-primary);"></i>Returned Item Detail
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Generic / Brand</th>
                            <th>Batch No.</th>
                            <th>Expiry</th>
                            <th class="text-center">Purchased</th>
                            <th class="text-center">Sold (pcs)</th>
                            <th class="text-center">Returned (pcs)</th>
                            <th class="text-end">Price/Piece</th>
                            <th class="text-end">Return Amount</th>
                            <th class="text-center">Batch Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $uop = max(1, (int)($item['units_per_pack'] ?? 1));
                        $origPacks    = (int)($item['orig_qty'] ?? 0);
                        $origPieces   = $origPacks * $uop;
                        $piecesSold   = (int)($item['pieces_sold_from_batch'] ?? 0);
                        $retQtyPieces = (int)$return['quantity'];
                        ?>
                        <tr style="background: color-mix(in srgb, var(--pharmacy-primary) 4%, var(--pharmacy-card-bg));">
                            <td>
                                <strong style="color: var(--pharmacy-heading);"><?php echo htmlspecialchars($item['product_name'] ?? '—'); ?></strong>
                                <?php if ($uop > 1): ?>
                                <br><span class="status-badge" style="background: var(--pharmacy-table-th); color: var(--pharmacy-text); font-size:0.65rem;"><?php echo $uop; ?> pcs/pack</span>
                                <?php endif; ?>
                            </td>
                            <td style="color: color-mix(in srgb, var(--pharmacy-text) 65%, transparent); font-size: 0.85rem;">
                                <?php echo htmlspecialchars(($item['generic_name'] ?? '') . ($item['brand_name'] ? ' / ' . $item['brand_name'] : '')); ?>
                            </td>
                            <td>
                                <span class="status-badge" style="background: var(--pharmacy-table-th); color: var(--pharmacy-text);">
                                    <?php echo htmlspecialchars($item['batch_number'] ?? '—'); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                if (!empty($item['expiry_date'])) {
                                    $exp = strtotime($item['expiry_date']);
                                    $expired = $exp < time();
                                    echo '<span style="color: ' . ($expired ? '#ef4444' : '#10b981') . '; font-weight: 500;">';
                                    echo date('M Y', $exp);
                                    if ($expired) echo ' <i class="fas fa-exclamation-circle"></i>';
                                    echo '</span>';
                                } else { echo '—'; }
                                ?>
                            </td>
                            <td class="text-center">
                                <?php if ($uop > 1): ?>
                                <strong style="color: var(--pharmacy-heading);"><?php echo $origPacks; ?></strong> packs
                                <small class="d-block" style="color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);"><?php echo $origPieces; ?> pcs</small>
                                <?php else: ?>
                                <strong style="color: var(--pharmacy-heading);"><?php echo $origPieces; ?></strong>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($piecesSold > 0): ?>
                                <span class="status-badge badge-expiring"><?php echo $piecesSold; ?></span>
                                <?php else: ?>
                                <span style="color: color-mix(in srgb, var(--pharmacy-text) 40%, transparent);">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="status-badge badge-oos" style="font-size: 0.82rem; padding: 4px 10px;">
                                    <?php echo $retQtyPieces; ?> pcs
                                </span>
                            </td>
                            <td class="text-end" style="color: var(--pharmacy-text);">
                                Rs <?php echo number_format((float)$return['unit_price'], 2); ?>
                                <small class="d-block" style="font-size:0.68rem; color: color-mix(in srgb, var(--pharmacy-text) 50%, transparent);">per piece</small>
                            </td>
                            <td class="text-end fw-bold" style="color: #10b981;">
                                Rs <?php echo number_format((float)$return['total_amount'], 2); ?>
                            </td>
                            <td class="text-center">
                                <?php $cs = (int)($item['current_batch_stock'] ?? 0); ?>
                                <span class="status-badge <?php echo $cs <= 0 ? 'badge-oos' : ($cs <= 10 ? 'badge-expiring' : 'badge-instock'); ?>">
                                    <?php echo $cs; ?> pcs
                                </span>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr style="background: var(--pharmacy-table-th);">
                            <td colspan="8" class="text-end fw-bold" style="color: var(--pharmacy-heading);">Total Return Amount:</td>
                            <td class="text-end fw-bold" style="color: #10b981; font-size: 1rem;">
                                Rs <?php echo number_format((float)$return['total_amount'], 2); ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Return Reason + Processed By -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card" style="border-radius: var(--card-border-radius,12px); border: 1px solid var(--pharmacy-border); box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                    <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 1px solid var(--pharmacy-border); border-radius: calc(var(--card-border-radius,12px) - 1px) calc(var(--card-border-radius,12px) - 1px) 0 0;">
                        <span class="fw-bold" style="color: var(--pharmacy-heading);">
                            <i class="fas fa-comment-alt me-2" style="color: var(--pharmacy-primary);"></i>Return Reason
                        </span>
                    </div>
                    <div class="card-body" style="background: var(--pharmacy-card-bg);">
                        <p class="mb-0" style="color: var(--pharmacy-text);">
                            <?php echo nl2br(htmlspecialchars($return['reason'] ?? 'No reason provided.')); ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card" style="border-radius: var(--card-border-radius,12px); border: 1px solid var(--pharmacy-border); box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                    <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 1px solid var(--pharmacy-border); border-radius: calc(var(--card-border-radius,12px) - 1px) calc(var(--card-border-radius,12px) - 1px) 0 0;">
                        <span class="fw-bold" style="color: var(--pharmacy-heading);">
                            <i class="fas fa-user me-2" style="color: var(--pharmacy-primary);"></i>Processed By
                        </span>
                    </div>
                    <div class="card-body" style="background: var(--pharmacy-card-bg);">
                        <strong style="color: var(--pharmacy-heading); font-size: 1rem;"><?php echo htmlspecialchars($return['returned_by_name'] ?? 'System'); ?></strong>
                        <div class="mt-1" style="color: color-mix(in srgb, var(--pharmacy-text) 60%, transparent); font-size: 0.85rem;">
                            on <?php echo !empty($return['created_at']) ? date('d M Y \a\t h:i A', strtotime($return['created_at'])) : '—'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cashbook Entry Detail -->
        <?php if ($cashbook): ?>
        <div class="card mb-4" style="border-radius: var(--card-border-radius,12px); border: 1px solid var(--pharmacy-border); box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 1px solid var(--pharmacy-border); border-radius: calc(var(--card-border-radius,12px) - 1px) calc(var(--card-border-radius,12px) - 1px) 0 0;">
                <span class="fw-bold" style="color: var(--pharmacy-heading);">
                    <i class="fas fa-book me-2" style="color: var(--pharmacy-primary);"></i>Cashbook Entry
                    <span class="status-badge badge-instock ms-2">Synced</span>
                </span>
            </div>
            <div class="card-body" style="background: var(--pharmacy-card-bg);">
                <div class="row g-3">
                    <div class="col-md-2">
                        <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Entry ID</small>
                        <strong style="color: var(--pharmacy-heading);">#<?php echo $cashbook['id']; ?></strong>
                    </div>
                    <div class="col-md-3">
                        <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Entry Type</small>
                        <span style="color: var(--pharmacy-text);"><?php echo htmlspecialchars($cashbook['entry_type'] ?? '—'); ?></span>
                    </div>
                    <div class="col-md-2">
                        <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Debit</small>
                        <span style="color: #ef4444; font-weight: 600;">Rs <?php echo number_format((float)($cashbook['debit'] ?? 0), 2); ?></span>
                    </div>
                    <div class="col-md-2">
                        <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Credit</small>
                        <span style="color: #10b981; font-weight: 600;">Rs <?php echo number_format((float)($cashbook['credit'] ?? 0), 2); ?></span>
                    </div>
                    <div class="col-md-3">
                        <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Payment Method</small>
                        <span style="color: var(--pharmacy-text);"><?php echo ucfirst(str_replace('_', ' ', $cashbook['payment_method'] ?? '—')); ?></span>
                    </div>
                    <?php if (!empty($cashbook['description'])): ?>
                    <div class="col-12">
                        <small class="d-block" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.4px; color: color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">Description</small>
                        <span style="color: color-mix(in srgb, var(--pharmacy-text) 70%, transparent); font-size: 0.85rem;"><?php echo htmlspecialchars($cashbook['description']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- All Returns for Same Purchase -->
        <?php if (count($allReturns) > 1): ?>
        <div class="table-card no-print">
            <div class="card-header" style="background: var(--pharmacy-table-th); border-bottom: 2px solid var(--pharmacy-border); padding: 14px 18px;">
                <span class="fw-bold" style="color: var(--pharmacy-heading);">
                    <i class="fas fa-history me-2" style="color: var(--pharmacy-primary);"></i>
                    All Returns for Invoice
                    <strong class="ms-1" style="color: var(--pharmacy-primary);"><?php echo htmlspecialchars($return['purchase_invoice_no']); ?></strong>
                </span>
                <span class="status-badge badge-expiring ms-2"><?php echo count($allReturns); ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Return No</th>
                            <th>Product</th>
                            <th class="text-center">Qty</th>
                            <th>Refund Method</th>
                            <th>Credit Note</th>
                            <th class="text-end">Amount</th>
                            <th>Date</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allReturns as $ar): ?>
                        <tr style="<?php echo $ar['id'] == $return_id ? 'background: color-mix(in srgb, var(--pharmacy-primary) 6%, var(--pharmacy-card-bg));' : ''; ?>">
                            <td>
                                <?php if ($ar['id'] == $return_id): ?>
                                <strong style="color: var(--pharmacy-primary);"><?php echo htmlspecialchars($ar['return_no']); ?></strong>
                                <?php else: ?>
                                <span style="color: var(--pharmacy-text);"><?php echo htmlspecialchars($ar['return_no']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="color: var(--pharmacy-text); font-size: 0.88rem;"><?php echo htmlspecialchars($ar['product_name'] ?? '—'); ?></td>
                            <td class="text-center"><span class="status-badge" style="background: var(--pharmacy-table-th); color: var(--pharmacy-text);"><?php echo (int)$ar['quantity']; ?></span></td>
                            <td>
                                <?php $rm2 = $ar['refund_method'] ?? '—'; ?>
                                <?php if ($rm2 === 'cash') echo '<span class="status-badge badge-instock">Cash</span>';
                                elseif ($rm2 === 'bank') echo '<span class="status-badge" style="background: color-mix(in srgb, var(--pharmacy-primary) 15%, transparent); color: var(--pharmacy-primary);">Bank</span>';
                                elseif ($rm2 === 'credit_note') echo '<span class="status-badge badge-expiring">Credit Note</span>';
                                else echo '<span style="color: var(--pharmacy-text);">' . htmlspecialchars($rm2) . '</span>'; ?>
                            </td>
                            <td style="color: color-mix(in srgb, var(--pharmacy-text) 60%, transparent); font-size: 0.85rem;"><?php echo htmlspecialchars($ar['credit_note_ref'] ?? '-'); ?></td>
                            <td class="text-end fw-bold" style="color: #10b981;">
                                Rs <?php echo number_format((float)$ar['total_amount'], 2); ?>
                            </td>
                            <td style="color: color-mix(in srgb, var(--pharmacy-text) 60%, transparent); font-size: 0.85rem;">
                                <?php echo !empty($ar['created_at']) ? date('d M Y', strtotime($ar['created_at'])) : '—'; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($ar['id'] != $return_id): ?>
                                <a href="purchase_return_view.php?id=<?php echo $ar['id']; ?>"
                                   class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php else: ?>
                                <span class="status-badge" style="background: var(--pharmacy-table-th); color: var(--pharmacy-text);">Current</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: var(--pharmacy-table-th);">
                            <td colspan="5" class="text-end fw-bold" style="color: var(--pharmacy-heading);">Grand Total Returned:</td>
                            <td class="text-end fw-bold" style="color: #ef4444;">
                                Rs <?php echo number_format((float)$totalReturnedForPurchase, 2); ?>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Print styles -->
<style>
@media print {
    .no-print { display: none !important; }
    .main-content { margin-left: 0 !important; padding: 0 !important; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
    .card, .table-card { box-shadow: none !important; border: 1px solid #ccc !important; }
    body { background: #fff !important; }
    @page { margin: 1.5cm; }
}
</style>

<?php include '../includes/footer.php'; ?>