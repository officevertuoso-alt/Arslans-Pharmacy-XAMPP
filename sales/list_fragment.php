<?php
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
/**
 * sales/list_fragment.php — AJAX-rendered table fragment
 * Phase 17: Full AJAX — included by ajax_handler.php (get_list) and list.php
 *
 * Variables provided by caller:
 *   $sales        – result of getAllSales()
 *   $creditMap    – sale_id => credit row
 *   $page         – current page
 *   $search       – current search string
 *   $fromDate     – filter from
 *   $toDate       – filter to
 *   $serialStart  – row number offset
 */

if (!isset($sales))       { $sales       = ['data' => [], 'total' => 0, 'totalPages' => 0]; }
if (!isset($creditMap))   { $creditMap   = []; }
if (!isset($page))        { $page        = 1; }
if (!isset($search))      { $search      = ''; }
if (!isset($fromDate))    { $fromDate    = ''; }
if (!isset($toDate))      { $toDate      = ''; }
if (!isset($serialStart)) { $serialStart = ($page - 1) * 20 + 1; }

// Item counts — batch fetch (no N+1)
if (!isset($itemCountsCache)) {
    $itemCountsCache = [];
    if (!empty($sales['data'])) {
        $saleIds = array_column($sales['data'], 'id');
        if (!empty($saleIds)) {
            $db2 = db();
            $namedPh  = [];
            $idParams = [];
            foreach ($saleIds as $i => $sid) {
                $k = ':s' . $i;
                $namedPh[]    = $k;
                $idParams[$k] = $sid;
            }
            $countRows = $db2->getAll(
                "SELECT sale_id, COUNT(*) AS cnt FROM sale_items WHERE sale_id IN (" . implode(',', $namedPh) . ") GROUP BY sale_id",
                $idParams
            );
            foreach ($countRows as $cr) {
                $itemCountsCache[$cr['sale_id']] = $cr['cnt'];
            }
        }
    }
}
?>

<div class="table-card">
    <div class="table-card-header">
        <span class="table-title"><i class="fas fa-table me-2"></i>Sale Records</span>
        <span class="record-count">
            <?php if ($sales['total'] > 0): ?>
                Showing <?php echo count($sales['data']); ?> of <?php echo number_format($sales['total']); ?>
            <?php else: ?>
                No records
            <?php endif; ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover mb-0" id="salesTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Invoice No.</th>
                    <th>Customer</th>
                    <th>Date &amp; Time</th>
                    <th class="text-center">Items</th>
                    <th class="text-end">Total</th>
                    <th>Payment</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($sales['data'])): ?>
                    <?php foreach ($sales['data'] as $idx => $sale):
                        $isCredit  = ($sale['payment_method'] === 'credit' || !empty($sale['is_credit']));
                        $payStatus = $sale['payment_status'] ?? 'paid';

                        // Credit / pending info
                        $creditRow = $creditMap[$sale['id']] ?? null;
                        if ($creditRow) {
                            $saleTotal   = (float)($sale['total']        ?? 0);
                            $upfrontPaid = (float)($sale['paid_amount']  ?? 0);
                            $postPaid    = (float)($creditRow['post_sale_paid'] ?? 0);
                            $remaining   = max(0, $saleTotal - $upfrontPaid - $postPaid);
                            $hasPending  = $remaining > 0.005;
                            $creditId    = $creditRow['credit_id'] ?? 0;
                        } elseif (in_array($payStatus, ['partial', 'pending'])) {
                            $saleTotal   = (float)($sale['total']       ?? 0);
                            $salePaid    = (float)($sale['paid_amount'] ?? 0);
                            $remaining   = max(0, $saleTotal - $salePaid);
                            $hasPending  = $remaining > 0.005;
                            $creditId    = 0;
                        } else {
                            $hasPending  = false;
                            $remaining   = 0;
                            $creditId    = 0;
                        }

                        $badgeClass = match($payStatus) {
                            'paid'     => 'badge-paid',
                            'partial'  => 'badge-partial',
                            'pending'  => 'badge-pending',
                            'returned' => 'badge-returned',
                            default    => 'badge-returned',
                        };

                        $pmIcon = match($sale['payment_method'] ?? 'cash') {
                            'cash'   => 'fa-money-bill-wave',
                            'card'   => 'fa-credit-card',
                            'online' => 'fa-mobile-alt',
                            'credit' => 'fa-file-invoice-dollar',
                            default  => 'fa-money-bill',
                        };

                        $itemCount = $itemCountsCache[$sale['id']] ?? '—';
                        $rowNum    = $serialStart + $idx;
                    ?>
                    <tr class="<?php echo $hasPending ? 'row-pending' : ''; ?>"
                        data-sale-id="<?php echo (int)$sale['id']; ?>">

                        <!-- # -->
                        <td style="font-size:.78rem;opacity:.55"><?php echo $rowNum; ?></td>

                        <!-- Invoice No. -->
                        <td>
                            <a href="#" class="invoice-link btn-preview-invoice"
                               data-sale-id="<?php echo (int)$sale['id']; ?>"
                               title="Preview Invoice">
                                <?php echo htmlspecialchars($sale['invoice_no']); ?>
                            </a>
                            <?php if ($hasPending): ?>
                                <br>
                                <span class="pending-amount">
                                    <i class="fas fa-exclamation-circle me-1"></i>Due: Rs <?php echo number_format($remaining, 2); ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Customer -->
                        <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                            title="<?php echo htmlspecialchars($sale['customer_name']); ?>">
                            <?php echo htmlspecialchars($sale['customer_name']); ?>
                            <?php if (!empty($sale['customer_phone'])): ?>
                                <br><small style="font-size:.72rem;opacity:.6"><?php echo htmlspecialchars($sale['customer_phone']); ?></small>
                            <?php endif; ?>
                        </td>

                        <!-- Date & Time -->
                        <td style="white-space:nowrap;font-size:.82rem">
                            <?php echo function_exists('formatDate') ? formatDate($sale['sale_date'], 'd/m/Y h:i A') : date('d/m/Y h:i A', strtotime($sale['sale_date'])); ?>
                        </td>

                        <!-- Items -->
                        <td class="text-center">
                            <span class="badge rounded-pill"
                                  style="background:color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 12%,transparent);color:var(--pharmacy-primary,#0d6efd);font-size:.72rem;font-weight:700">
                                <?php echo $itemCount; ?>
                            </span>
                        </td>

                        <!-- Total -->
                        <td class="text-end fw-bold" style="font-size:.875rem">
                            Rs <?php echo number_format((float)$sale['total'], 2); ?>
                            <?php if (!empty($sale['total_returned']) && (float)$sale['total_returned'] > 0): ?>
                                <br><small class="text-danger" style="font-size:.7rem">
                                    Ret: &minus;Rs <?php echo number_format((float)$sale['total_returned'], 2); ?>
                                </small>
                            <?php endif; ?>
                        </td>

                        <!-- Payment Method -->
                        <td>
                            <?php if ($isCredit && $hasPending): ?>
                                <span class="pay-method-badge credit-badge">
                                    <i class="fas fa-file-invoice-dollar"></i>Credit
                                </span>
                            <?php elseif ($payStatus === 'partial'): ?>
                                <span class="pay-method-badge partial-badge">
                                    <i class="fas fa-adjust"></i>Partial
                                </span>
                            <?php else: ?>
                                <span class="pay-method-badge">
                                    <i class="fas <?php echo $pmIcon; ?>"></i>
                                    <?php echo ucfirst($sale['payment_method'] ?? 'cash'); ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Status -->
                        <td class="text-center">
                            <span class="status-badge <?php echo $badgeClass; ?>">
                                <?php echo ucfirst($payStatus); ?>
                            </span>
                        </td>

                        <!-- Actions -->
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center flex-nowrap">

                                <!-- View -->
                                <a href="view.php?id=<?php echo (int)$sale['id']; ?>"
                                   class="btn btn-sm btn-outline-primary btn-action"
                                   data-bs-toggle="tooltip" title="View Sale">
                                    <i class="fas fa-eye"></i>
                                </a>

                                <!-- Preview Invoice (AJAX modal) -->
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary btn-action btn-preview-invoice"
                                        data-sale-id="<?php echo (int)$sale['id']; ?>"
                                        data-bs-toggle="tooltip" title="Invoice Preview / Print">
                                    <i class="fas fa-file-invoice"></i>
                                </button>

                                <!-- Edit -->
                                <button type="button"
                                        class="btn btn-sm btn-outline-warning btn-action btn-edit-sale"
                                        data-sale-id="<?php echo (int)$sale['id']; ?>"
                                        data-invoice="<?php echo htmlspecialchars($sale['invoice_no'], ENT_QUOTES); ?>"
                                        data-customer="<?php echo htmlspecialchars($sale['customer_name'], ENT_QUOTES); ?>"
                                        data-phone="<?php echo htmlspecialchars($sale['customer_phone'] ?? '', ENT_QUOTES); ?>"
                                        data-payment-method="<?php echo htmlspecialchars($sale['payment_method'] ?? 'cash', ENT_QUOTES); ?>"
                                        data-bs-toggle="tooltip" title="Edit Sale">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <!-- Return -->
                                <?php if (($sale['status'] ?? '') !== 'voided'): ?>
                                <button type="button"
                                        class="btn btn-sm btn-outline-info btn-action btn-return-sale"
                                        data-sale-id="<?php echo (int)$sale['id']; ?>"
                                        data-invoice="<?php echo htmlspecialchars($sale['invoice_no'], ENT_QUOTES); ?>"
                                        data-bs-toggle="tooltip" title="Process Return">
                                    <i class="fas fa-undo"></i>
                                </button>
                                <?php endif; ?>

                                <!-- Pay Credit -->
                                <?php if ($hasPending): ?>
                                <button type="button"
                                   class="btn btn-sm btn-record-pay btn-open-pay-modal"
                                   data-credit-id="<?php echo (int)$creditId; ?>"
                                   data-sale-id="<?php echo (int)$sale['id']; ?>"
                                   data-invoice="<?php echo htmlspecialchars($sale['invoice_no'], ENT_QUOTES); ?>"
                                   data-customer="<?php echo htmlspecialchars($sale['customer_name'], ENT_QUOTES); ?>"
                                   data-remaining="<?php echo number_format($remaining, 2); ?>"
                                   data-remaining-raw="<?php echo $remaining; ?>"
                                   data-bs-toggle="tooltip"
                                   title="Record Payment — Rs <?php echo number_format($remaining, 2); ?> due">
                                    <i class="fas fa-money-bill-wave me-1"></i>Pay
                                </button>
                                <?php endif; ?>

                                <!-- Void (admin only) -->
                                <?php if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin'], true) && ($sale['status'] ?? '') !== 'voided'): ?>
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger btn-action btn-void-sale"
                                        data-sale-id="<?php echo (int)$sale['id']; ?>"
                                        data-invoice="<?php echo htmlspecialchars($sale['invoice_no'], ENT_QUOTES); ?>"
                                        data-bs-toggle="tooltip" title="Void Sale">
                                    <i class="fas fa-ban"></i>
                                </button>
                                <?php endif; ?>

                            </div>
                        </td>

                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="fas fa-receipt"></i>
                                No sales found.
                                <?php if (!empty($search)): ?>
                                <br><small>No results for &ldquo;<strong><?php echo htmlspecialchars($search); ?></strong>&rdquo;</small>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div><!-- /table-responsive -->

    <!-- Pagination -->
    <?php if ($sales['totalPages'] > 1): ?>
    <div class="pagination-wrapper">
        <div class="pagination-info">
            Showing <strong><?php echo count($sales['data']); ?></strong>
            of <strong><?php echo number_format($sales['total']); ?></strong> sales
        </div>
        <nav aria-label="Sales pagination">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link ajax-page-link" data-page="<?php echo $page - 1; ?>" href="#">&laquo; Prev</a>
                </li>
                <?php
                    $start = max(1, $page - 2);
                    $end   = min($sales['totalPages'], $page + 2);
                    if ($start > 1): ?>
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif;
                    for ($i = $start; $i <= $end; $i++): ?>
                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                    <a class="page-link ajax-page-link" data-page="<?php echo $i; ?>" href="#"><?php echo $i; ?></a>
                </li>
                    <?php endfor;
                    if ($end < $sales['totalPages']): ?>
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                <li class="page-item <?php echo $page >= $sales['totalPages'] ? 'disabled' : ''; ?>">
                    <a class="page-link ajax-page-link" data-page="<?php echo $page + 1; ?>" href="#">Next &raquo;</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>

</div><!-- /table-card -->
