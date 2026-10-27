<?php
/**
 * View Product - COMPLETE REBUILD
 * 
 * Features:
 * - Full product details with batch-level management
 * - Smart stock display (packs + pieces)
 * - Per-batch reorder level display
 * - Inline batch price edit
 * - Stock movement history
 * - Sales and purchase history tabs
 */

require_once '../config/config.php';
require_once '../includes/product_functions.php';
require_once '../includes/purchase_functions.php';

if (!defined('BASE_URL')) define('BASE_URL', 'http://localhost/pharmacy_software/');

requirePharmacist();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$product = getProductById($id);

if (!$product) {
    header('Location: list.php?error=Product not found');
    exit();
}

$page_title = 'Product Details - ' . htmlspecialchars($product['product_name']);

// Handle batch price update
$batchUpdateMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_batch_price'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $batchUpdateMessage = 'Security token mismatch.';
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $batchUpdateMessage]); exit; }
    } else {
        $batchId = (int)$_POST['batch_id'];
        $newPrice = (float)$_POST['sale_price'];
        if (updateBatchSalePrice($batchId, $newPrice)) {
            $batchUpdateMessage = 'Batch price updated successfully!';
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'message' => $batchUpdateMessage]); exit; }
            $product = getProductById($id);
            logActivity('batch_price_update', "Updated batch #{$batchId} sale price to Rs {$newPrice}");
        } else {
            $batchUpdateMessage = 'Failed to update batch price.';
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $batchUpdateMessage]); exit; }
        }
    }
}

// Handle batch reorder level update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_batch_reorder'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $batchUpdateMessage = 'Security token mismatch.';
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $batchUpdateMessage]); exit; }
    } else {
        $batchId = (int)$_POST['batch_id'];
        $newLevel = (int)$_POST['reorder_level'];
        if (updateBatchReorderLevel($batchId, $newLevel)) {
            $batchUpdateMessage = 'Reorder level updated successfully!';
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'message' => $batchUpdateMessage]); exit; }
            $product = getProductById($id);
        } else {
            $batchUpdateMessage = 'Failed to update reorder level.';
        }
    }
}

// Helper functions
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) { return 'Rs ' . number_format((float)$amount, 2); }
}
if (!function_exists('formatDate')) {
    function formatDate($date) { return $date ? date('d M Y', strtotime($date)) : '—'; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    <style>
        body { background: #f0f2f5; }
        .main-content { margin-left: var(--sb-w, 260px); transition: margin-left .25s ease; padding: 20px; }
        .info-card { background: #fff; border-radius: 15px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,.05); }
        .info-label { font-weight: 600; color: #6c757d; font-size: 11px; text-transform: uppercase; margin-bottom: 4px; }
        .info-value { font-size: 15px; font-weight: 500; margin-bottom: 12px; }
        .product-img { max-width: 120px; max-height: 120px; border-radius: 10px; border: 1px solid #dee2e6; cursor: pointer; }
        .batch-table th { background: #f8f9fa; font-size: 12px; }
        .batch-table td { font-size: 12px; vertical-align: middle; }
        .nav-tabs .nav-link { font-size: 13px; }
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><?php echo htmlspecialchars($product['product_name']); ?></h4>
                <?php if (!empty($product['generic_name'])): ?>
                    <p class="text-muted mb-0">(<?php echo htmlspecialchars($product['generic_name']); ?>)</p>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-dark" onclick="printBarcodeLabel()"><i class="fas fa-barcode me-2"></i>Print Barcode Label</button>
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-primary"
                   data-requires-perm="products.edit">
                    <i class="fas fa-edit me-2"></i>Edit Product
                </a>
                <a href="list.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back to List</a>
            </div>
        </div>

        <?php if ($batchUpdateMessage): ?>
            <div class="alert alert-info alert-dismissible fade show"><?php echo htmlspecialchars($batchUpdateMessage); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- Info Cards Row -->
        <div class="row">
            <div class="col-md-4">
                <div class="info-card">
                    <h6 class="mb-3"><i class="fas fa-info-circle me-2 text-primary"></i>Basic Information</h6>
                    <div class="info-label">Product Code</div>
                    <div class="info-value"><strong><?php echo htmlspecialchars($product['product_code']); ?></strong></div>
                    <div class="info-label">Product Type</div>
                    <div class="info-value"><span class="badge <?php echo $product['category_id'] == 1 ? 'bg-info' : 'bg-secondary'; ?>"><?php echo $product['category_id'] == 1 ? 'Medicine' : 'Non-Medicine'; ?></span></div>
                    <div class="info-label">Brand Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($product['brand_name'] ?? '—'); ?></div>
                    <?php if (!empty($product['generic_name'])): ?>
                        <div class="info-label">Generic Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($product['generic_name']); ?></div>
                    <?php endif; ?>
                    <div class="info-label">Product Image</div>
                    <div class="info-value">
                        <?php if (!empty($product['image_path'])): ?>
                            <img src="<?php echo BASE_URL . ltrim($product['image_path'], '/'); ?>" class="product-img" onclick="openImageModal()">
                        <?php else: ?>
                            <span class="text-muted">No image</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-card">
                    <h6 class="mb-3"><i class="fas fa-boxes me-2 text-primary"></i>Packing</h6>
                    <div class="info-label">Unit Type</div>
                    <div class="info-value"><?php echo htmlspecialchars($product['unit_type'] ?? 'Pcs'); ?></div>
                    <div class="info-label">Units per Pack</div>
                    <div class="info-value"><?php
                        /* Use same formatUnitsPerPackDisplay() as list.php so display is identical */
                        echo formatUnitsPerPackDisplay(
                            (int)($product['units_per_pack'] ?? 1),
                            $product['unit_type']    ?? 'Pcs',
                            $product['dosage_form']  ?? '',
                            $product['unit_type']    ?? ''
                        );
                    ?></div>
                    <div class="info-label">Pack Type</div>
                    <div class="info-value"><?php
                        /* Pack type = smart label from unit_type (Box, Strip, Bottle, etc.) */
                        $ut = strtolower(trim($product['unit_type'] ?? ''));
                        $packTypeLabel = match($ut) {
                            'strip'   => 'Strip',
                            'bottle'  => 'Bottle',
                            'box'     => 'Box',
                            'tube'    => 'Tube',
                            'sachet'  => 'Sachet',
                            'vial'    => 'Vial',
                            'ampoule' => 'Ampoule',
                            default   => ucfirst($product['unit_type'] ?? 'Pack'),
                        };
                        echo htmlspecialchars($packTypeLabel);
                    ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-card">
                    <h6 class="mb-3"><i class="fas fa-chart-line me-2 text-success"></i>Stock & Profitability</h6>
                    <div class="info-label">Current Stock</div>
                    <div class="info-value"><?php
                        $totalActive = array_sum(array_column($product['active_batches'] ?? [], 'remaining_quantity'));
                        echo formatStockDisplay((int)$totalActive, (int)($product['units_per_pack'] ?? 1), $product['unit_type'] ?? 'Pcs', $product['dosage_form'] ?? '');
                    ?></div>
                    <div class="info-label">Active Batches</div>
                    <div class="info-value"><?php echo count($product['active_batches'] ?? []); ?> batch(es)</div>
                    <div class="info-label">Expired Batches</div>
                    <div class="info-value"><?php echo count($product['expired_batches'] ?? []); ?> batch(es)</div>
                </div>
            </div>
        </div>

        <!-- Active Batches Table -->
        <div class="info-card">
            <h6 class="mb-3"><i class="fas fa-warehouse me-2 text-primary"></i>Active Batches</h6>
            <?php if (!empty($product['active_batches'])): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered batch-table">
                        <thead>
                            <tr>
                                <th>Batch No.</th>
                                <th>Supplier</th>
                                <th>Reorder Level</th>
                                <th>Purchase Date</th>
                                <th>Expiry Date</th>
                                <th>Purchase Price</th>
                                <th>Sale Price/Unit</th>
                                <th>Stock (Pieces)</th>
                                <th>Status</th>
                                <th>Barcode</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($product['active_batches'] as $batch): 
                                $stockDisplay = formatStockDisplay((int)$batch['remaining_quantity'], (int)($batch['units_per_pack'] ?? $product['units_per_pack'] ?? 1), $product['unit_type'] ?? 'Pcs', $product['dosage_form'] ?? '');
                                $isLowStock = (int)$batch['remaining_quantity'] <= (int)($batch['reorder_level'] ?? 10);
                            ?>
                            <tr class="<?php echo $isLowStock ? 'table-warning' : ''; ?>">
                                <td><strong><?php echo htmlspecialchars($batch['batch_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($batch['supplier_name'] ?? '—'); ?></td>
                                <td><?php echo (int)($batch['reorder_level'] ?? 10); ?> units
                                    <button class="btn btn-sm btn-link p-0 ms-1" onclick="openReorderModal(<?php echo $batch['id']; ?>, <?php echo (int)($batch['reorder_level'] ?? 10); ?>)"><i class="fas fa-pen"></i></button>
                                </td>
                                <td><?php echo formatDate($batch['purchase_date']); ?></td>
                                <td>
                                    <?php
                                    if (!empty($batch['expiry_date'])):
                                        echo '<div class="fw-semibold">' . date('d M Y', strtotime($batch['expiry_date'])) . '</div>';
                                        echo '<div>' . getExpiryDisplay($batch['expiry_date']) . '</div>';
                                    else:
                                        echo '<span class="badge bg-secondary">No Expiry</span>';
                                    endif;
                                    ?>
                                </td>
                                <td><?php echo formatCurrency($batch['purchase_price']); ?></td>
                                <td>
                                    <?php echo formatCurrency($batch['selling_price']); ?>
                                    <button class="btn btn-sm btn-link p-0 ms-1" onclick="openPriceModal(<?php echo $batch['id']; ?>, <?php echo $batch['selling_price']; ?>)"><i class="fas fa-pen text-primary"></i></button>
                                </td>
                                <td><?php echo $stockDisplay; ?></td>
                                <td><span class="badge <?php echo $isLowStock ? 'bg-warning text-dark' : 'bg-success'; ?>"><?php echo $isLowStock ? 'Low Stock' : 'Active'; ?></span></td>
                                <td>
                                    <?php
                                    $bExpired = !empty($batch['expiry_date']) && (new DateTime('today') > new DateTime($batch['expiry_date']));
                                    $bOOS     = ((int)$batch['remaining_quantity'] <= 0);
                                    if (!$bExpired && !$bOOS):
                                    ?>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="printSingleBarcode(<?php echo (int)$batch['id']; ?>, <?php echo $id; ?>)"><i class="fas fa-barcode me-1"></i>Print</button>
                                    <?php else: ?>
                                        <span class="text-muted small"><?php echo $bExpired ? 'Expired' : 'OOS'; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="../purchases/add.php?product_id=<?php echo $id; ?>&product_name=<?php echo urlencode($product['product_name']); ?>"
                                       class="btn btn-sm btn-success"
                                       data-requires-perm="purchases.add">
                                        + Add Stock
                                    </a>
                                 </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted">No active batches found.</p>
                <a href="../purchases/add.php?product_id=<?php echo $id; ?>&product_name=<?php echo urlencode($product['product_name']); ?>"
                   class="btn btn-sm btn-success"
                   data-requires-perm="purchases.add">
                    + Add First Batch
                </a>
            <?php endif; ?>
        </div>

        <!-- Expired Batches (collapsible) -->
        <?php if (!empty($product['expired_batches'])): ?>
        <div class="info-card">
            <h6 class="mb-3"><i class="fas fa-exclamation-triangle me-2 text-danger"></i>Expired Batches</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-danger">
                        <tr><th>Batch No.</th><th>Supplier</th><th>Expiry Date</th><th>Remaining Qty</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($product['expired_batches'] as $batch): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                            <td><?php echo htmlspecialchars($batch['supplier_name'] ?? '—'); ?></td>
                            <td>
                                <?php
                                if (!empty($batch['expiry_date'])):
                                    echo '<div class="fw-semibold">' . date('d M Y', strtotime($batch['expiry_date'])) . '</div>';
                                    echo '<div>' . getExpiryDisplay($batch['expiry_date']) . '</div>';
                                else:
                                    echo '<span class="badge bg-secondary">No Expiry</span>';
                                endif;
                                ?>
                             </td>
                            <td><?php echo (int)$batch['remaining_quantity']; ?> <?php echo $product['unit_type']; ?></td>
                            <td><span class="badge bg-danger">Expired</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tabs for additional info -->
        <div class="info-card">
            <ul class="nav nav-tabs mb-3" id="productTabs">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#movements">Stock Movements</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#purchases">Purchase History</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="movements">
                    <p class="text-muted small">Last 50 stock movements</p>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Date</th><th>Type</th><th>Quantity</th><th>Reference</th></tr></thead>
                            <tbody>
                                <?php
                                $movements = _pf_all("
                                    SELECT sm.*, b.batch_number
                                    FROM stock_movements sm
                                    JOIN batches b ON sm.batch_id = b.id
                                    WHERE b.product_id = :pid
                                    ORDER BY sm.created_at DESC LIMIT 50
                                ", [':pid' => $id]);
                                foreach ($movements as $m):
                                    $sign = in_array($m['movement_type'], ['purchase', 'return_in', 'adjustment_in']) ? '+' : '-';
                                ?>
                                <tr>
                                    <td><?php echo date('d M Y H:i', strtotime($m['created_at'])); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo ucfirst(str_replace('_', ' ', $m['movement_type'])); ?></span></td>
                                    <td class="<?php echo $sign === '+' ? 'text-success' : 'text-danger'; ?>"><?php echo $sign; ?><?php echo (int)$m['quantity']; ?></td>
                                    <td><?php echo htmlspecialchars($m['notes'] ?? '—'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="tab-pane fade" id="purchases">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Purchase Invoice</th><th>Date</th><th>Batch</th><th>Qty</th><th>Price</th></tr></thead>
                            <tbody>
                                <?php
                                $purchases = _pf_all("
                                    SELECT p.purchase_invoice_no, p.purchase_date, b.batch_number, pi.quantity, pi.unit_price
                                    FROM purchase_items pi
                                    JOIN purchases p ON pi.purchase_id = p.id
                                    JOIN batches b ON pi.batch_id = b.id
                                    WHERE pi.product_id = :pid
                                    ORDER BY p.purchase_date DESC
                                ", [':pid' => $id]);
                                foreach ($purchases as $pur):
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pur['purchase_invoice_no']); ?></td>
                                    <td><?php echo formatDate($pur['purchase_date']); ?></td>
                                    <td><?php echo htmlspecialchars($pur['batch_number']); ?></td>
                                    <td><?php echo (int)$pur['quantity']; ?> packs</td>
                                    <td><?php echo formatCurrency($pur['unit_price']); ?>/piece</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals -->
<div class="modal fade" id="priceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div id="batchPriceAlert" class="mb-2"></div>
                <form method="POST"
                      data-pharm-form
                      data-success-msg="Batch price updated!"
                      data-loading-text="Saving…"
                      data-alert-target="#batchPriceAlert"
                      novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="update_batch_price" value="1">
                <input type="hidden" name="batch_id" id="priceBatchId">
                <div class="modal-header bg-primary text-white">
                    <h6 class="modal-title"><i class="fas fa-tag me-2"></i>Edit Batch Sale Price</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">New Sale Price (per unit)</label>
                        <input type="number" step="0.01" name="sale_price" id="priceValue" class="form-control" required min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" data-submit-btn class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="reorderModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div id="batchReorderAlert" class="mb-2"></div>
                <form method="POST"
                      data-pharm-form
                      data-success-msg="Reorder level updated!"
                      data-loading-text="Saving…"
                      data-alert-target="#batchReorderAlert"
                      novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="update_batch_reorder" value="1">
                <input type="hidden" name="batch_id" id="reorderBatchId">
                <div class="modal-header bg-warning">
                    <h6 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Edit Reorder Level</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Reorder Level (units)</label>
                        <input type="number" name="reorder_level" id="reorderValue" class="form-control" required min="1">
                        <small class="text-muted">Low stock alert when remaining quantity falls below this number.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" data-submit-btn class="btn btn-warning">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>



<script>
function openPriceModal(batchId, currentPrice) {
    $('#priceBatchId').val(batchId);
    $('#priceValue').val(currentPrice);
    new bootstrap.Modal(document.getElementById('priceModal')).show();
}

function openReorderModal(batchId, currentLevel) {
    $('#reorderBatchId').val(batchId);
    $('#reorderValue').val(currentLevel);
    new bootstrap.Modal(document.getElementById('reorderModal')).show();
}

function printBarcodeLabel() {
    let form = document.createElement('form');
    form.method = 'POST';
    form.action = '../ajax/print_barcode_labels.php';
    form.target = '_blank';
    let csrf = document.createElement('input');
    csrf.type = 'hidden'; csrf.name = 'csrf_token'; csrf.value = '<?php echo generateCSRFToken(); ?>';
    form.appendChild(csrf);
    let inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'product_ids[]'; inp.value = <?php echo $id; ?>;
    form.appendChild(inp);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

function printSingleBarcode(batchId, productId) {
    let form = document.createElement('form');
    form.method = 'POST';
    form.action = '../ajax/print_barcode_labels.php';
    form.target = '_blank';
    // CSRF
    let csrf = document.createElement('input');
    csrf.type = 'hidden'; csrf.name = 'csrf_token'; csrf.value = '<?php echo generateCSRFToken(); ?>';
    form.appendChild(csrf);
    // product_ids[] — required by barcode script
    let pi = document.createElement('input');
    pi.type = 'hidden'; pi.name = 'product_ids[]'; pi.value = productId;
    form.appendChild(pi);
    // batch_id — for batch_specific mode
    let bi = document.createElement('input');
    bi.type = 'hidden'; bi.name = 'batch_id'; bi.value = batchId;
    form.appendChild(bi);
    // label_mode
    let lm = document.createElement('input');
    lm.type = 'hidden'; lm.name = 'label_mode'; lm.value = 'batch_specific';
    form.appendChild(lm);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

function openImageModal() {
    window.location.href = 'edit.php?id=<?php echo $id; ?>';
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>