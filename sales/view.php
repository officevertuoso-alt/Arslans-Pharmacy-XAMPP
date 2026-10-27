<?php
require_once '../config/config.php';
require_once '../includes/sale_functions.php';
require_once '../includes/return_functions.php';
require_once '../includes/sync_engine.php';
requireLogin(); // FIX S-05: Enforce authentication on sale view

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sale = getSaleById($id);

if (!$sale) {
    header('Location: list.php?error=Sale not found');
    exit();
}

// Get additional info
$summary = getSalePaymentSummary($id);
$returnSummary = getSaleReturnSummary($id);
$paymentHistory = [];
$creditInfo = null;
$returns = [];

$db = db();

// Always try to fetch credit details (covers credit sales AND partial-payment sales)
$creditInfo = $db->getOne(
    "SELECT cc.*,
            COALESCE(SUM(CASE WHEN (cp.note IS NULL OR (cp.note NOT LIKE 'Upfront%' AND cp.note NOT LIKE 'upfront%')) THEN cp.amount ELSE 0 END), 0) AS total_paid,
            (cc.total_amount - COALESCE(SUM(CASE WHEN (cp.note IS NULL OR (cp.note NOT LIKE 'Upfront%' AND cp.note NOT LIKE 'upfront%')) THEN cp.amount ELSE 0 END), 0)) AS remaining
     FROM customer_credits cc
     LEFT JOIN credit_payments cp ON cp.credit_id = cc.id
     WHERE cc.sale_id = :sid
     GROUP BY cc.id
     ORDER BY cc.id DESC LIMIT 1",
    [':sid' => $id]
);
// If found a credit record, mark sale as credit so the UI shows correctly
if ($creditInfo && (float)($creditInfo['remaining'] ?? 0) > 0.005) {
    $sale['is_credit'] = 1;
}
if ($creditInfo) {
    $paymentHistory = $db->getAll(
        "SELECT cp.*, DATE_FORMAT(payment_date, '%Y-%m-%d %H:%i:%s') AS payment_date_fmt
         FROM credit_payments cp
         WHERE cp.credit_id = :cid
         ORDER BY cp.payment_date DESC",
        [':cid' => $creditInfo['id']]
    );
}

// Get sale returns — 'returned_by' column may not exist; fetch without JOIN
$returns = [];
try {
    $returns = $db->getAll(
        "SELECT sr.*,
                NULL AS returned_by_name
         FROM sale_returns sr
         WHERE sr.sale_id = :sid
         ORDER BY sr.created_at DESC",
        [':sid' => $id]
    );
    // Try to enrich with user name only if column exists
    if (!empty($returns)) {
        foreach ($returns as &$ret) {
            if (!empty($ret['returned_by'])) {
                try {
                    $u = $db->getOne("SELECT full_name FROM users WHERE id = :uid", [':uid' => $ret['returned_by']]);
                    $ret['returned_by_name'] = $u['full_name'] ?? '—';
                } catch (Exception $e) {
                    $ret['returned_by_name'] = '—';
                }
            }
        }
        unset($ret);
    }
} catch (Exception $e) {
    $returns = [];
}

// Stock movement for items in this sale
$stockMovements = [];
$batchIds = array_unique(array_column($sale['items'], 'batch_id'));
if (!empty($batchIds)) {
    // Build named placeholders to avoid mixing positional (?) and named (:param) — PDO disallows this
    $namedPlaceholders = [];
    $params = [];
    foreach ($batchIds as $i => $bid) {
        $key = ':bid' . $i;
        $namedPlaceholders[] = $key;
        $params[$key] = $bid;
    }
    $params[':sid'] = $id;
    $placeholders = implode(',', $namedPlaceholders);

    $stockMovements = $db->getAll(
        "SELECT sm.*, b.batch_number, p.product_name
         FROM stock_movements sm
         JOIN batches b ON sm.batch_id = b.id
         JOIN products p ON b.product_id = p.id
         WHERE sm.batch_id IN ($placeholders)
           AND sm.reference_id = :sid
           AND sm.reference_type = 'sale'
         ORDER BY sm.created_at DESC",
        $params
    );
}

// Fetch cashbook entries for this sale
$cashEntries = $db->getAll(
    "SELECT * FROM cashbook
     WHERE reference_id = :id AND reference_type IN ('sale','sale_payment')
     ORDER BY created_at DESC",
    [':id' => $id]
);

$page_title = 'Sale Details - ' . $sale['invoice_no'];
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
        .info-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .info-label { font-weight: 600; color: #6c757d; font-size: 12px; text-transform: uppercase; margin-bottom: 2px; }
        .credit-overdue { background: #fff3cd; border-left: 4px solid #ffc107; }
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1">Sale Details</h4>
                <p class="text-muted mb-0">Invoice: <strong><?php echo htmlspecialchars($sale['invoice_no']); ?></strong></p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="print_invoice.php?id=<?php echo $id; ?>" target="_blank" class="btn btn-secondary">
                    <i class="fas fa-print me-2"></i>Thermal Print
                </a>
                <a href="invoice_pdf.php?id=<?php echo $id; ?>&view=1" target="_blank" class="btn btn-primary">
                    <i class="fas fa-file-invoice me-2"></i>A4 Invoice
                </a>
                <a href="invoice_pdf.php?id=<?php echo $id; ?>" target="_blank" class="btn btn-outline-danger">
                    <i class="fas fa-file-pdf me-2"></i>Download PDF
                </a>
                <button type="button" class="btn btn-outline-warning"
                        id="btnEditSaleView"
                        data-sale-id="<?php echo $id; ?>"
                        data-invoice="<?php echo htmlspecialchars($sale['invoice_no'], ENT_QUOTES); ?>"
                        data-customer="<?php echo htmlspecialchars($sale['customer_name'], ENT_QUOTES); ?>"
                        data-phone="<?php echo htmlspecialchars($sale['customer_phone'] ?? '', ENT_QUOTES); ?>">
                    <i class="fas fa-edit me-2"></i>Edit
                </button>
                <?php if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin'], true)): ?>
                <button type="button" class="btn btn-outline-danger"
                        id="btnVoidSaleView"
                        data-sale-id="<?php echo $id; ?>"
                        data-invoice="<?php echo htmlspecialchars($sale['invoice_no'], ENT_QUOTES); ?>">
                    <i class="fas fa-ban me-2"></i>Void Sale
                </button>
                <?php endif; ?>
                <a href="pos.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>New Sale
                </a>
                <a href="list.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back
                </a>
            </div>
        </div>

        <!-- Row: Customer & Sale Info -->
        <div class="row">
            <!-- Customer Info -->
            <div class="col-md-6">
                <div class="info-card">
                    <h6 class="mb-3"><i class="fas fa-user me-2 text-primary"></i>Customer Information</h6>
                    <div class="info-label">Customer Name</div>
                    <div class="mb-3"><?php echo htmlspecialchars($sale['customer_name']); ?></div>
                    <div class="info-label">Phone</div>
                    <div class="mb-3"><?php echo htmlspecialchars($sale['customer_phone'] ?: '—'); ?></div>
                </div>
            </div>

            <!-- Sale Info -->
            <div class="col-md-6">
                <div class="info-card">
                    <h6 class="mb-3"><i class="fas fa-receipt me-2 text-primary"></i>Sale Information</h6>
                    <div class="info-label">Sale Date & Time</div>
                    <div class="mb-3"><?php echo formatDate($sale['sale_date'], 'd/m/Y h:i A'); ?></div>
                    <div class="info-label">Payment Method</div>
                    <div class="mb-3">
                        <?php
                        $pm = $sale['payment_method'];
                        $pmColor = ['cash' => 'success', 'card' => 'primary', 'online' => 'warning', 'credit' => 'danger'];
                        $pmIcon  = ['cash' => 'money-bill-wave', 'card' => 'credit-card', 'online' => 'mobile-alt', 'credit' => 'file-invoice-dollar'];
                        ?>
                        <span class="badge bg-<?php echo $pmColor[$pm] ?? 'secondary'; ?>">
                            <i class="fas fa-<?php echo $pmIcon[$pm] ?? 'circle'; ?> me-1"></i>
                            <?php echo ucfirst($pm); ?>
                        </span>
                    </div>
                    <div class="info-label">Payment Status</div>
                    <div>
                        <?php
                        $status = $sale['payment_status'] ?? 'paid';
                        $statusColor = match($status) {
                            'paid' => 'success',
                            'partial' => 'warning',
                            'pending' => 'danger',
                            'returned' => 'secondary',
                            default => 'secondary'
                        };
                        ?>
                        <span class="badge bg-<?php echo $statusColor; ?>">
                            <?php echo ucfirst($status); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Totals Card: Grand Total vs Net Amount after Returns -->
        <div class="info-card">
            <h6 class="mb-3"><i class="fas fa-chart-line me-2 text-primary"></i>Financial Summary</h6>
            <div class="row">
                <div class="col-md-3">
                    <div class="info-label">Grand Total</div>
                    <div class="fw-bold fs-5">Rs <?php echo number_format((float)($sale['grand_total'] ?? $sale['total'] ?? 0), 2); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Total Returns</div>
                    <div class="fw-bold text-danger">Rs <?php echo number_format((float)($returnSummary['total_returned_amount'] ?? 0), 2); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Net Amount (after returns)</div>
                    <div class="fw-bold text-success">Rs <?php echo number_format((float)($returnSummary['net_amount'] ?? 0), 2); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Paid Amount</div>
                    <div class="fw-bold">Rs <?php echo number_format((float)($sale['paid_amount'] ?? 0), 2); ?></div>
                </div>
            </div>
            <?php if ($sale['is_credit'] && $creditInfo): ?>
            <hr>
            <div class="row">
                <div class="col-md-3">
                    <div class="info-label">Original Credit Amount</div>
                    <div class="fw-bold">Rs <?php echo number_format((float)($creditInfo['total_amount'] ?? $creditInfo['amount'] ?? 0), 2); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Total Paid Back</div>
                    <div class="fw-bold text-success">Rs <?php echo number_format((float)($creditInfo['total_paid'] ?? 0), 2); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Remaining Balance</div>
                    <div class="fw-bold <?php echo ($creditInfo['remaining'] ?? 0) > 0 ? 'text-danger' : 'text-success'; ?>">
                        Rs <?php echo number_format((float)($creditInfo['remaining'] ?? 0), 2); ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Due Date</div>
                    <div class="<?php echo (strtotime($creditInfo['due_date']) < time()) ? 'text-danger fw-bold' : ''; ?>">
                        <?php echo formatDate($creditInfo['due_date']); ?>
                        <?php if (strtotime($creditInfo['due_date']) < time() && $creditInfo['remaining'] > 0): ?>
                            <i class="fas fa-exclamation-triangle"></i> Overdue
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Items Table -->
        <div class="info-card">
            <h6 class="mb-3"><i class="fas fa-boxes me-2 text-primary"></i>Items Sold</h6>
            <div class="table-responsive">
                <?php
                /* ── Fetch product details from DB (same as print_invoice) ── */
                $viewPdataMap = [];
                if (!empty($sale['items'])) {
                    $viewAllPids  = array_unique(array_column($sale['items'], 'product_id'));
                    $viewInKeys   = [];
                    $viewInParams = [];
                    foreach ($viewAllPids as $vi => $vpid) {
                        $vk = ':vpid' . $vi;
                        $viewInKeys[]     = $vk;
                        $viewInParams[$vk] = $vpid;
                    }
                    $viewPRows = $db->getAll(
                        "SELECT id, product_name, generic_name, brand_name, dosage_form, strength, category_id, size_value FROM products WHERE id IN (" . implode(',', $viewInKeys) . ")",
                        $viewInParams
                    );
                    foreach ($viewPRows as $vpr) {
                        $viewPdataMap[(int)$vpr['id']] = $vpr;
                    }
                }

                /* ── Group by product_id + sell_mode (same as POS cart & print_invoice) ── */
                $groupedItems = [];
                $pidOrder     = [];
                foreach ($sale['items'] as $item) {
                    $pid   = (int)($item['product_id'] ?? 0);
                    $mode  = $item['sell_mode'] ?? 'piece';
                    $key   = $pid . '_' . $mode;
                    $pdata = $viewPdataMap[$pid] ?? [];
                    if (!isset($groupedItems[$key])) {
                        $pidOrder[]         = $key;
                        $groupedItems[$key] = [
                            'product_name'  => $pdata['product_name'] ?? $item['product_name']  ?? '',
                            'generic_name'  => $pdata['generic_name'] ?? $item['generic_name']  ?? '',
                            'brand_name'    => $pdata['brand_name']   ?? $item['brand_name']    ?? '',
                            'strength_info' => $pdata['strength']     ?? $item['strength_info'] ?? '',
                            'dosage_form'   => $pdata['dosage_form']  ?? $item['dosage_form']   ?? '',
                            'size_value'    => $pdata['size_value']   ?? $item['size_value']    ?? '',
                            'category_id'   => (int)($pdata['category_id'] ?? $item['category_id'] ?? 0),
                            'sell_mode'     => $mode,
                            'units_per_pack'=> max(1, (int)($item['units_per_pack'] ?? 1)),
                            'batches'       => [],
                            'total_qty_pcs' => 0,
                            'total_amount'  => 0.0,
                        ];
                    }
                    $groupedItems[$key]['batches'][]      = $item;
                    $groupedItems[$key]['total_qty_pcs'] += (int)($item['quantity'] ?? 0);
                    $groupedItems[$key]['total_amount']  += (float)($item['total']  ?? 0);
                }
                ?>
                <table class="table table-bordered align-middle mb-0" style="font-size:13.5px;">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px;">#</th>
                            <th>Product / Details</th>
                            <th class="text-center" style="width:80px;">Mode</th>
                            <th class="text-center" style="width:70px;">Qty</th>
                            <th class="text-end"    style="width:100px;">Price</th>
                            <th class="text-end"    style="width:100px;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $srNo = 0; foreach ($pidOrder as $key):
                        $g      = $groupedItems[$key];
                        $srNo++;
                        $mode   = $g['sell_mode'];
                        $upp    = $g['units_per_pack'];
                        $isMed  = ($g['category_id'] === 1);
                        $totPcs = $g['total_qty_pcs'];

                        /* ── Detail sub-line ── */
                        $sub = '';
                        if ($isMed) {
                            $generic  = trim($g['generic_name']);
                            $form     = trim($g['dosage_form']);
                            $strength = trim($g['strength_info']);
                            $parts    = array_filter([$form, $strength]);
                            if ($generic) $sub = '<strong>' . htmlspecialchars($generic) . '</strong>'
                                . ($parts ? ' <span class="text-muted" style="font-size:11px;">&middot; ' . htmlspecialchars(implode(' ', $parts)) . '</span>' : '');
                        } else {
                            $brand = trim($g['brand_name']);
                            $size  = trim($g['size_value']);
                            if ($brand) $sub = '<strong>' . htmlspecialchars($brand) . '</strong>'
                                . ($size ? ' <span class="text-muted" style="font-size:11px;">&middot; ' . htmlspecialchars($size) . '</span>' : '');
                        }
                        // Pack line
                        if ($mode === 'pack' && $upp > 1) {
                            $packsSold = (int)floor($totPcs / $upp);
                            $sub .= ($sub ? '<br>' : '') . '<span class="text-success" style="font-size:11px;">'
                                . $packsSold . ' Pack' . ($packsSold > 1 ? 's' : '') . ' &times;' . $upp . ' = ' . $totPcs . ' pcs</span>';
                        }

                        /* ── Mode badge ── */
                        $modeBadge = $mode === 'pack'
                            ? '<span class="badge" style="background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;font-size:10px;">PACK &times;' . $upp . '</span>'
                            : '<span class="badge" style="background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;font-size:10px;">PIECE</span>';

                        foreach ($g['batches'] as $bi => $b):
                            $isFirst     = ($bi === 0);
                            $qtyPcs      = (int)($b['quantity']            ?? 0);
                            $pricePC     = (float)($b['unit_price']        ?? 0);
                            $rowTotal    = (float)($b['total']             ?? 0);
                            $discPct     = (float)($b['item_discount_pct'] ?? 0);
                            $batchNo     = trim($b['batch_number'] ?? '');
                            $expDate     = trim($b['expiry_date']  ?? '');
                            $batchLabel  = $bi + 1;
                    ?>
                        <tr style="<?= !$isFirst ? 'background:#faf5ff;' : '' ?>">
                            <!-- # -->
                            <td class="text-center fw-bold" style="color:<?= $isFirst ? 'inherit' : '#764ba2' ?>;">
                                <?= $isFirst ? $srNo : '&#8627;' ?>
                            </td>
                            <!-- Product / Details -->
                            <td style="padding-left:<?= $isFirst ? '10' : '20' ?>px;">
                                <?php if ($isFirst): ?>
                                    <div class="fw-bold" style="font-size:14px;"><?= htmlspecialchars($g['product_name']) ?></div>
                                    <?php if ($sub): ?><div style="margin-top:2px;font-size:12px;"><?= $sub ?></div><?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#764ba2;font-weight:600;font-size:12px;">&#8627; <?= htmlspecialchars($g['product_name']) ?></span>
                                <?php endif; ?>
                                <?php if ($discPct > 0): ?>
                                <div style="margin-top:2px;">
                                    <span class="text-warning fw-semibold" style="font-size:11px;"><i class="fas fa-tag"></i> Disc <?= number_format($discPct, 1) ?>%</span>
                                </div>
                                <?php endif; ?>
                            </td>
                            <!-- Mode (only on first row) -->
                            <td class="text-center"><?= $isFirst ? $modeBadge : '' ?></td>
                            <!-- Qty -->
                            <td class="text-center">
                                <?= $qtyPcs ?><span class="text-muted" style="font-size:11px;"> pc</span>
                            </td>
                            <!-- Price -->
                            <td class="text-end">Rs <?= number_format($pricePC, 2) ?></td>
                            <!-- Total -->
                            <td class="text-end fw-<?= $isFirst ? 'bold text-success' : 'semibold' ?>">
                                Rs <?= number_format($rowTotal, 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; // batches ?>
                    <?php endforeach; // grouped ?>
                    </tbody>
                    <tfoot class="table-light">
                        <?php
                        $subtotal = (float)($sale['subtotal'] ?? $sale['total'] ?? 0);
                        $discAmt  = (float)($sale['discount'] ?? $sale['discount_amount'] ?? 0);
                        $taxAmt   = (float)($sale['tax_amount'] ?? $sale['tax'] ?? 0);
                        $discPct2 = (float)($sale['discount_percent'] ?? 0);
                        $taxPct2  = (float)($sale['tax_percent'] ?? 0);
                        $grandTot = (float)($sale['total'] ?? 0);
                        ?>
                        <tr>
                            <td colspan="5" class="text-end fw-bold">Sub-Total:</td>
                            <td class="text-end fw-bold">Rs <?= number_format($subtotal, 2) ?></td>
                        </tr>
                        <?php if ($discAmt > 0): ?>
                        <tr>
                            <td colspan="5" class="text-end text-danger">Discount<?= $discPct2 > 0 ? ' (' . $discPct2 . '%)' : '' ?>:</td>
                            <td class="text-end text-danger fw-bold">-Rs <?= number_format($discAmt, 2) ?></td>
                        </tr>
                        <?php endif; if ($taxAmt > 0): ?>
                        <tr>
                            <td colspan="5" class="text-end text-success">Tax<?= $taxPct2 > 0 ? ' (' . $taxPct2 . '%)' : '' ?>:</td>
                            <td class="text-end text-success fw-bold">+Rs <?= number_format($taxAmt, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="table-primary">
                            <td colspan="5" class="text-end fw-bold fs-6">GRAND TOTAL:</td>
                            <td class="text-end fw-bold fs-6">Rs <?= number_format($grandTot, 2) ?></td>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end">Payment: <strong><?= ucfirst(htmlspecialchars($sale['payment_method'])) ?></strong></td>
                            <td class="text-end">Paid: <strong>Rs <?= number_format((float)($sale['paid_amount'] ?? 0), 2) ?></strong></td>
                        </tr>
                        <?php
                        $paid    = (float)($sale['paid_amount'] ?? $grandTot);
                        $balance = $grandTot - $paid;
                        ?>
                        <?php if ($balance != 0): ?>
                        <tr>
                            <td colspan="5" class="text-end fw-bold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $balance > 0 ? 'Balance Due:' : 'Change:' ?>
                            </td>
                            <td class="text-end fw-bold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                                Rs <?= number_format(abs($balance), 2) ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($returnSummary['total_returned_amount'] > 0): ?>
                        <tr>
                            <td colspan="5" class="text-end text-danger">Total Returns:</td>
                            <td class="text-end text-danger fw-bold">-Rs <?= number_format($returnSummary['total_returned_amount'], 2) ?></td>
                        </tr>
                        <tr class="table-success">
                            <td colspan="5" class="text-end fw-bold">Net Amount:</td>
                            <td class="text-end fw-bold">Rs <?= number_format($returnSummary['net_amount'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Returns Section -->
        <?php if (!empty($returns)): ?>
        <div class="info-card">
            <h6 class="mb-3"><i class="fas fa-undo-alt me-2 text-warning"></i>Sale Returns</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr><th>Return No</th><th>Date</th><th>Product</th><th>Qty</th><th>Amount</th><th>Refund Method</th><th>Processed By</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returns as $ret): ?>
                        <tr>
                            <td><a href="../returns/sale_return_view.php?id=<?php echo $ret['id']; ?>"><?php echo htmlspecialchars($ret['return_no']); ?></a></td>
                            <td><?php echo formatDate($ret['created_at'], true); ?></td>
                            <td><?php echo htmlspecialchars($ret['product_name'] ?? '—'); ?></td>
                            <td><?php echo $ret['quantity']; ?></td>
                            <td class="text-danger">Rs <?php echo number_format($ret['total_amount'], 2); ?></td>
                            <td><?php echo ucfirst($ret['refund_method']); ?></td>
                            <td><?php echo htmlspecialchars($ret['returned_by_name'] ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Credit Payment History (if credit/partial with any record) -->
        <?php
        // ── Ground truth: always use sale totals, NOT credit.total_amount ──────────
        // credit.total_amount = only the credit portion (e.g. 209.99 out of 509.99)
        // sale.total          = actual full invoice total (509.99) ← correct
        // sale.paid_amount    = upfront cash paid at POS (300.00)
        // Additional payments after sale = credit_payments excluding 'Upfront%' notes

        $sale_grand_total  = (float)($sale['total'] ?? 0);
        $sale_upfront_paid = (float)($sale['paid_amount'] ?? 0);

        // Sum of payments made AFTER the sale (exclude upfront entries)
        $post_sale_paid = 0.0;
        if ($creditInfo) {
            foreach (($paymentHistory ?? []) as $ph) {
                $note = trim($ph['note'] ?? '');
                if ($note === '' || stripos($note, 'Upfront') !== 0) {
                    $post_sale_paid += (float)$ph['amount'];
                }
            }
        }

        $view_total      = $sale_grand_total;
        $view_paid       = $sale_upfront_paid + $post_sale_paid;
        $view_remaining  = max(0, $view_total - $view_paid);
        $view_hasPending = $view_remaining > 0.005;
        $view_payUrl     = '#';

        if ($view_hasPending && $creditInfo) {
            $view_payUrl = BASE_URL . 'CREDIT/list.php?highlight=' . $creditInfo['id'] . '&open_pay=1';
        } elseif ($view_hasPending) {
            $view_payUrl = BASE_URL . 'CREDIT/index.php?sale_id=' . $id;
        }
        ?>

        <?php if ($view_hasPending): ?>
        <div class="info-card" id="viewPendingCard" style="border-left: 4px solid #dc3545; background: #fff8f8;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 class="mb-1 text-danger"><i class="fas fa-exclamation-circle me-2"></i>Payment Pending</h6>
                    <div class="text-muted small">
                        Total: <strong>Rs <?php echo number_format($view_total, 2); ?></strong>
                        &nbsp;|&nbsp; Paid: <strong class="text-success">Rs <?php echo number_format($view_paid, 2); ?></strong>
                        &nbsp;|&nbsp; Remaining: <strong class="text-danger" id="viewRemainingText">Rs <?php echo number_format($view_remaining, 2); ?></strong>
                    </div>
                </div>
                <button type="button" class="btn btn-success btn-open-pay-modal"
                    data-requires-perm="sales.credit_pay"
                    data-credit-id="<?php echo (int)($creditInfo['id'] ?? 0); ?>"
                    data-sale-id="<?php echo $id; ?>"
                    data-invoice="<?php echo htmlspecialchars($sale['invoice_no'], ENT_QUOTES); ?>"
                    data-customer="<?php echo htmlspecialchars($sale['customer_name'], ENT_QUOTES); ?>"
                    data-remaining="<?php echo $view_remaining; ?>">
                    <i class="fas fa-money-bill-wave me-2"></i>Record Payment
                    <span class="badge bg-light text-dark ms-1" id="viewRemainingBtnBadge">Rs <?php echo number_format($view_remaining, 2); ?> due</span>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($creditInfo && !empty($paymentHistory)): ?>
        <div class="info-card">
            <h6 class="mb-3"><i class="fas fa-history me-2 text-info"></i>Credit Payment History</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Note</th></tr></thead>
                    <tbody id="creditHistoryTbody">
                        <?php foreach ($paymentHistory as $pay): ?>
                        <tr>
                            <td><?php
                                $pdate = $pay['payment_date_fmt'] ?? $pay['payment_date'] ?? '';
                                echo $pdate ? date('d/m/Y h:i A', strtotime($pdate)) : '—';
                            ?></td>
                            <td class="text-success fw-bold">Rs <?php echo number_format($pay['amount'], 2); ?></td>
                            <td><?php echo ucfirst($pay['payment_method']); ?></td>
                            <td><?php echo htmlspecialchars($pay['note'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stock Movement History -->
        <?php if (!empty($stockMovements)): ?>
        <div class="info-card">
            <h6 class="mb-3"><i class="fas fa-chart-line me-2 text-secondary"></i>Stock Movement History</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Product</th><th>Batch</th><th>Movement Type</th><th>Qty</th><th>Before</th><th>After</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($stockMovements as $sm): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sm['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($sm['batch_number']); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $sm['movement_type'])); ?></td>
                            <td><?php echo $sm['quantity']; ?></td>
                            <td><?php echo $sm['previous_quantity']; ?></td>
                            <td><?php echo $sm['new_quantity']; ?></td>
                            <td><?php echo formatDate($sm['created_at'], true); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cashbook Entries (added from sale_view.php) -->
        <?php if (!empty($cashEntries)): ?>
        <div class="info-card">
            <h6 class="mb-3"><i class="fas fa-book me-2 text-info"></i>Cashbook Entries</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
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
                            <td class="text-end text-success"><?php echo $ce['debit'] > 0 ? 'Rs ' . number_format($ce['debit'], 2) : '—'; ?></td>
                            <td class="text-end text-danger"><?php echo $ce['credit'] > 0 ? 'Rs ' . number_format($ce['credit'], 2) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="d-flex gap-2 mb-4 flex-wrap">
            <a href="sale_return.php?invoice_no=<?php echo urlencode($sale['invoice_no']); ?>"
               class="btn btn-warning"
               data-requires-perm="sales.return">
                <i class="fas fa-undo-alt me-2"></i>Process Return
            </a>
            <?php if ($view_hasPending): ?>
            <button type="button" class="btn btn-success btn-open-pay-modal"
                data-requires-perm="sales.credit_pay"
                data-credit-id="<?php echo (int)($creditInfo['id'] ?? 0); ?>"
                data-sale-id="<?php echo $id; ?>"
                data-invoice="<?php echo htmlspecialchars($sale['invoice_no'], ENT_QUOTES); ?>"
                data-customer="<?php echo htmlspecialchars($sale['customer_name'], ENT_QUOTES); ?>"
                data-remaining="<?php echo $view_remaining; ?>">
                <i class="fas fa-money-bill-wave me-2"></i>Record Payment
                <span class="badge bg-light text-dark ms-1">Rs <?php echo number_format($view_remaining, 2); ?> due</span>
            </button>
            <?php endif; ?>
            <a href="list.php" class="btn btn-outline-secondary ms-auto">
                <i class="fas fa-arrow-left me-2"></i>Back to Sales
            </a>
        </div>

    </div>
</div>


<!-- ═══════════════════════════════════════════
     CREDIT PAYMENT MODAL — sales/view.php
     Pay URL: CREDIT/list.php?ajax=pay_credit
     (no CSRF needed — that endpoint doesn't require it)
     ═══════════════════════════════════════════ -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-460">
    <div class="modal-content">

      <div class="modal-header modal-header-success py-3">
        <h5 class="modal-title mb-0"><i class="fas fa-money-bill-wave me-2"></i>Record Credit Payment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-4">
        <!-- Summary box -->
        <div style="background:#f8f9fa;border-radius:8px;padding:12px 16px;margin-bottom:16px;border-left:4px solid #dc3545">
          <div class="row">
            <div class="col-6">
              <div style="font-size:11px;color:#6c757d;font-weight:600;text-transform:uppercase">Invoice</div>
              <div style="font-size:14px;font-weight:700" id="pm_invoice">—</div>
            </div>
            <div class="col-6">
              <div style="font-size:11px;color:#6c757d;font-weight:600;text-transform:uppercase">Customer</div>
              <div style="font-size:14px;font-weight:700" id="pm_customer">—</div>
            </div>
          </div>
          <div class="mt-2">
            <div style="font-size:11px;color:#6c757d;font-weight:600;text-transform:uppercase">Remaining Balance</div>
            <div style="font-size:18px;font-weight:700;color:#dc3545" id="pm_remaining">Rs 0.00</div>
          </div>
        </div>

        <!-- Amount -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Payment Amount <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text">Rs</span>
            <input type="number" id="pm_amount" class="form-control form-control-lg" step="0.01" min="0.01" placeholder="0.00">
          </div>
          <div class="form-text">
            <a href="#" id="pm_pay_full" class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i>Pay full amount</a>
          </div>
        </div>

        <!-- Method -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Payment Method</label>
          <div class="d-flex gap-2">
            <button type="button" class="pm-method-btn active" data-method="cash"
              style="border:2px solid #198754;background:#e8f5e9;color:#198754;border-radius:8px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer">
              <i class="fas fa-money-bill-wave me-1"></i>Cash
            </button>
            <button type="button" class="pm-method-btn" data-method="card"
              style="border:2px solid #dee2e6;background:white;border-radius:8px;padding:7px 14px;font-size:13px;cursor:pointer">
              <i class="fas fa-credit-card me-1"></i>Card
            </button>
            <button type="button" class="pm-method-btn" data-method="online"
              style="border:2px solid #dee2e6;background:white;border-radius:8px;padding:7px 14px;font-size:13px;cursor:pointer">
              <i class="fas fa-mobile-alt me-1"></i>Online
            </button>
          </div>
          <input type="hidden" id="pm_method" value="cash">
        </div>

        <!-- Note -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Note <span class="text-muted fw-normal">(optional)</span></label>
          <input type="text" id="pm_note" class="form-control" placeholder="e.g. Partial payment, cheque no...">
        </div>

        <div class="alert alert-danger d-none py-2 px-3" id="pm_error"></div>

        <div class="text-center d-none" id="pm_success">
          <div style="font-size:3rem;color:#198754"><i class="fas fa-check-circle"></i></div>
          <h6 class="mt-2 mb-1 text-success" id="pm_success_msg"></h6>
          <p class="text-muted small mb-0" id="pm_success_bal"></p>
        </div>
      </div>

      <div class="modal-footer" id="pm_footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success px-4" id="pm_submit_btn">
          <span id="pm_btn_text"><i class="fas fa-check me-1"></i>Record Payment</span>
          <span id="pm_btn_spin" class="d-none"><span class="spinner-border spinner-border-sm me-1" style="width:1rem;height:1rem;border-width:2px"></span>Processing…</span>
        </button>
      </div>

    </div>
  </div>
</div>

<script>
(function($){
    /* ── The ONLY pay endpoint that works without CSRF ── */
    var PAY_URL = '<?php echo rtrim(BASE_URL, "/"); ?>/credit/list.php?ajax=pay_credit';
    var _rem    = 0;
    var _credId = 0;
    var modal   = null;

    function getModal(){
        if(!modal) modal = new bootstrap.Modal(document.getElementById('payModal'));
        return modal;
    }

    /* ── Open modal from any .btn-open-pay-modal button ── */
    $(document).on('click', '.btn-open-pay-modal', function(){
        var $b = $(this);
        _credId = parseInt($b.data('credit-id')) || 0;
        _rem    = parseFloat($b.data('remaining')) || 0;

        $('#pm_invoice').text($b.data('invoice')  || '—');
        $('#pm_customer').text($b.data('customer') || '—');
        $('#pm_remaining').text('Rs ' + _rem.toFixed(2));

        /* reset form */
        $('#pm_amount').val('');
        $('#pm_note').val('');
        $('#pm_method').val('cash');
        $('.pm-method-btn').css({border:'2px solid #dee2e6',background:'white',color:'inherit',fontWeight:'400'});
        $('.pm-method-btn[data-method="cash"]').css({border:'2px solid #198754',background:'#e8f5e9',color:'#198754',fontWeight:'600'});
        $('#pm_error').addClass('d-none');
        $('#pm_success').addClass('d-none');
        $('#pm_footer').removeClass('d-none');
        $('#pm_submit_btn').prop('disabled', false);
        setSpin(false);

        getModal().show();
    });

    /* ── Pay full amount link ── */
    $('#pm_pay_full').on('click', function(e){
        e.preventDefault();
        $('#pm_amount').val(_rem.toFixed(2));
    });

    /* ── Method toggle ── */
    $(document).on('click', '.pm-method-btn', function(){
        $('.pm-method-btn').css({border:'2px solid #dee2e6',background:'white',color:'inherit',fontWeight:'400'});
        $(this).css({border:'2px solid #198754',background:'#e8f5e9',color:'#198754',fontWeight:'600'});
        $('#pm_method').val($(this).data('method'));
    });

    /* ── Submit ── */
    $('#pm_submit_btn').on('click', function(){
        var amt = parseFloat($('#pm_amount').val());
        if(isNaN(amt)||amt<=0){ showErr('Please enter a valid payment amount.'); return; }
        if(amt>_rem+0.01){ showErr('Amount exceeds remaining balance of Rs '+_rem.toFixed(2)); return; }

        $('#pm_error').addClass('d-none');
        setSpin(true);
        $('#pm_submit_btn').prop('disabled', true);

        $.post(PAY_URL, {
            credit_id:      _credId,
            amount:         amt.toFixed(2),
            payment_method: $('#pm_method').val(),
            note:           $('#pm_note').val().trim()
        }, function(r){
            setSpin(false);
            if(r.success){
                onSuccess(r, amt);
            } else {
                $('#pm_submit_btn').prop('disabled', false);
                showErr(r.message || 'Payment failed. Please try again.');
            }
        }, 'json').fail(function(xhr){
            setSpin(false);
            $('#pm_submit_btn').prop('disabled', false);
            var msg = 'Network error. Please check your connection and try again.';
            try{ var j=JSON.parse(xhr.responseText); if(j&&j.message) msg=j.message; }catch(e){}
            showErr(msg);
        });
    });

    function onSuccess(r, amt){
        var newBal = parseFloat(r.new_balance ?? r.new_remaining ?? 0);
        var fullyPaid = r.fully_paid || newBal < 0.01;

        $('#pm_success_msg').text('Payment of Rs '+amt.toFixed(2)+' recorded!');
        $('#pm_success_bal').text(fullyPaid ? 'Credit fully settled ✓' : 'Remaining: Rs '+newBal.toFixed(2));
        $('#pm_success').removeClass('d-none');
        $('#pm_footer').addClass('d-none');

        /* ── Update page UI ── */
        if(fullyPaid){
            $('#viewPendingCard').fadeOut(400, function(){ $(this).remove(); });
            $('.btn-open-pay-modal').remove();
            /* Update payment status badge */
            $('span.badge').filter(function(){ return $(this).text().trim()==='Partial'||$(this).text().trim()==='Pending'; })
                .first().removeClass('bg-warning bg-danger').addClass('bg-success').text('Paid');
            /* Show success banner */
            var $banner=$('<div class="alert alert-success d-flex align-items-center gap-2 mb-3 mt-2"><i class="fas fa-check-circle fa-lg"></i><strong>Credit fully paid!</strong> This invoice is now settled.</div>');
            $('.container-fluid').find('h4').closest('div').after($banner);
        } else {
            _rem = newBal;
            $('#viewRemainingText').text('Rs '+newBal.toFixed(2));
            $('#viewRemainingBtnBadge').text('Rs '+newBal.toFixed(2)+' due');
            $('.btn-open-pay-modal').attr('data-remaining', newBal.toFixed(2));
            /* Add payment to history table */
            var $tbody = $('#creditHistoryTbody');
            if($tbody.length){
                var now = new Date();
                var ds  = now.toLocaleDateString('en-GB',{day:'2-digit',month:'2-digit',year:'numeric'})
                        + ' ' + now.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}).replace(',',' ').toUpperCase();
                $tbody.prepend('<tr><td>'+ds+'</td><td class="text-success fw-bold">Rs '+amt.toFixed(2)+'</td>'
                    +'<td>'+($('#pm_method').val()||'Cash').charAt(0).toUpperCase()+($('#pm_method').val()||'cash').slice(1)+'</td>'
                    +'<td>'+($.trim($('#pm_note').val())||'—')+'</td></tr>');
            }
        }

        /* ── Broadcast to other tabs (list, credit/list, customers) ── */
        try{
            var bc = new BroadcastChannel('credit_sync');
            bc.postMessage({event:'payment',credit_id:_credId,new_remaining:newBal,fully_paid:fullyPaid,amount:amt});
            bc.close();
        }catch(e){}

        setTimeout(function(){ getModal().hide(); }, 1800);
    }

    function setSpin(on){
        $('#pm_btn_text').toggleClass('d-none', on);
        $('#pm_btn_spin').toggleClass('d-none', !on);
    }
    function showErr(msg){ $('#pm_error').text(msg).removeClass('d-none'); }

})(jQuery);
</script>
<!-- ════════════════════ EDIT SALE MODAL (AJAX) ════════════════════ -->
<div class="modal fade" id="editSaleViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2 text-warning"></i>Edit Sale</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="editViewAlert" class="alert d-none"></div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="editViewCustomerName">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Phone</label>
          <input type="text" class="form-control" id="editViewCustomerPhone">
        </div>
        <input type="hidden" id="editViewSaleId">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="editViewSaveBtn">
          <span id="editViewBtnText"><i class="fas fa-save me-1"></i>Save</span>
          <span id="editViewBtnSpin" class="d-none spinner-border spinner-border-sm"></span>
        </button>
      </div>
    </div>
  </div>
</div>
<!-- ════════════════════ VOID SALE MODAL (AJAX) ════════════════════ -->
<div class="modal fade" id="voidSaleViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold text-danger"><i class="fas fa-ban me-2"></i>Void Sale</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="voidViewAlert" class="alert d-none"></div>
        <p>You are about to void invoice <strong id="voidViewInvoiceDisplay">—</strong>. Stock will be restored.</p>
        <div class="mb-3">
          <label class="form-label fw-semibold">Void Reason <span class="text-danger">*</span></label>
          <textarea class="form-control" id="voidViewReason" rows="2"></textarea>
        </div>
        <input type="hidden" id="voidViewSaleId">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="voidViewConfirmBtn">
          <span id="voidViewBtnText"><i class="fas fa-ban me-1"></i>Void Sale</span>
          <span id="voidViewBtnSpin" class="d-none spinner-border spinner-border-sm"></span>
        </button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  const AH = 'ajax_handler.php';
  const CS = '<?php echo generateCSRFToken(); ?>';

  function showAl(el, type, msg) { el.className = 'alert alert-' + type; el.textContent = msg; }
  function setSpin(t, s, b, on) { t.classList.toggle('d-none', on); s.classList.toggle('d-none', !on); b.disabled = on; }

  /* Edit */
  var editModal = new bootstrap.Modal(document.getElementById('editSaleViewModal'));
  document.getElementById('btnEditSaleView').addEventListener('click', function () {
    document.getElementById('editViewSaleId').value        = this.dataset.saleId;
    document.getElementById('editViewCustomerName').value  = this.dataset.customer;
    document.getElementById('editViewCustomerPhone').value = this.dataset.phone || '';
    document.getElementById('editViewAlert').classList.add('d-none');
    editModal.show();
  });
  document.getElementById('editViewSaveBtn').addEventListener('click', function () {
    var name = document.getElementById('editViewCustomerName').value.trim();
    if (!name) { showAl(document.getElementById('editViewAlert'), 'danger', 'Customer name required.'); return; }
    setSpin(document.getElementById('editViewBtnText'), document.getElementById('editViewBtnSpin'), this, true);
    var fd = new FormData();
    fd.append('action','update_sale'); fd.append('csrf_token',CS);
    fd.append('sale_id', document.getElementById('editViewSaleId').value);
    fd.append('customer_name', name);
    fd.append('customer_phone', document.getElementById('editViewCustomerPhone').value);
    var self = this;
    fetch(AH, {method:'POST',body:fd}).then(r=>r.json()).then(data => {
      setSpin(document.getElementById('editViewBtnText'), document.getElementById('editViewBtnSpin'), self, false);
      if (data.success) { editModal.hide(); setTimeout(()=>location.reload(), 300); }
      else showAl(document.getElementById('editViewAlert'), 'danger', data.message || 'Failed.');
    }).catch(() => { setSpin(document.getElementById('editViewBtnText'), document.getElementById('editViewBtnSpin'), self, false); showAl(document.getElementById('editViewAlert'), 'danger', 'Network error.'); });
  });

  /* Void */
  var btnVoid = document.getElementById('btnVoidSaleView');
  if (btnVoid) {
    var voidModal = new bootstrap.Modal(document.getElementById('voidSaleViewModal'));
    btnVoid.addEventListener('click', function () {
      document.getElementById('voidViewSaleId').value = this.dataset.saleId;
      document.getElementById('voidViewInvoiceDisplay').textContent = this.dataset.invoice;
      document.getElementById('voidViewReason').value = '';
      document.getElementById('voidViewAlert').classList.add('d-none');
      voidModal.show();
    });
    document.getElementById('voidViewConfirmBtn').addEventListener('click', function () {
      var reason = document.getElementById('voidViewReason').value.trim();
      if (!reason) { showAl(document.getElementById('voidViewAlert'), 'danger', 'Please enter a void reason.'); return; }
      setSpin(document.getElementById('voidViewBtnText'), document.getElementById('voidViewBtnSpin'), this, true);
      var fd = new FormData();
      fd.append('action','delete_sale'); fd.append('csrf_token',CS);
      fd.append('sale_id', document.getElementById('voidViewSaleId').value);
      fd.append('reason', reason);
      var self = this;
      fetch(AH, {method:'POST',body:fd}).then(r=>r.json()).then(data => {
        setSpin(document.getElementById('voidViewBtnText'), document.getElementById('voidViewBtnSpin'), self, false);
        if (data.success) { voidModal.hide(); window.location.href = 'list.php'; }
        else showAl(document.getElementById('voidViewAlert'), 'danger', data.message || 'Failed.');
      }).catch(() => { setSpin(document.getElementById('voidViewBtnText'), document.getElementById('voidViewBtnSpin'), self, false); showAl(document.getElementById('voidViewAlert'), 'danger', 'Network error.'); });
    });
  }
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>