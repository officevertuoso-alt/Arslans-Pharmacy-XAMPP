<?php
/**
 * stock/adjust.php — Record a Stock Adjustment
 *
 * FULLY SYNCHRONIZED WITH:
 *   batches.remaining_quantity   — adjusted up or down
 *   stock_movements              — audit trail entry (adjustment_in / adjustment_out)
 *   stock_adjustments            — record with type, qty, reason, cashbook_entry_id
 *   cashbook                     — DEBIT entry for damage/theft (loss), none for correction
 *   products.stock_quantity      — recalculated after commit
 *   sync_engine                  — clearAllCaches() on success
 *
 * ADJUSTMENT TYPES:
 *   damage        → REMOVE qty, INSERT cashbook loss debit
 *   theft         → REMOVE qty, INSERT cashbook loss debit
 *   expiry_manual → REMOVE qty, no cashbook (expiry handled separately)
 *   correction    → ADD qty (stock count fix), no cashbook
 */
require_once '../config/config.php';
require_once '../includes/purchase_functions.php';
require_once '../includes/sync_engine.php';
require_once '../includes/cashbook_functions.php';

requireLogin();

if (!canAdd('stock')) {
    setFlash('error', 'You do not have permission to add stock adjustments.', 'danger');
    header('Location: index.php');
    exit;
}

$page_title = 'New Stock Adjustment';
$error   = '';
$success = '';

// ── AJAX product lookup ───────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_batches') {
    requireLogin();
    header('Content-Type: application/json');
    $pid = (int)($_GET['product_id'] ?? 0);
    if (!$pid) { echo json_encode(['batches' => []]); exit; }
    $batches = _pdoAll(
        "SELECT b.id, b.batch_number, b.expiry_date, b.remaining_quantity,
                b.purchase_price, b.selling_price, b.is_expired
         FROM batches b
         WHERE b.product_id = :pid AND b.remaining_quantity > 0
         ORDER BY b.expiry_date ASC, b.id ASC",
        [':pid' => $pid]
    );
    echo json_encode(['batches' => $batches]);
    exit;
}

// ── Handle POST submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $batchId    = (int)($_POST['batch_id']       ?? 0);
        $adjType    = trim($_POST['adjustment_type'] ?? '');
        $quantity   = (int)($_POST['quantity']       ?? 0);
        $reason     = trim($_POST['reason']          ?? '');
        $adjDate    = trim($_POST['adj_date']        ?? date('Y-m-d'));

        $validTypes = ['damage', 'theft', 'correction', 'expiry_manual'];
        $isRemoval  = in_array($adjType, ['damage', 'theft', 'expiry_manual']);

        // ── Validation ────────────────────────────────────────────────────────
        if (!$batchId)                         $error = 'Please select a batch.';
        elseif (!in_array($adjType,$validTypes)) $error = 'Invalid adjustment type.';
        elseif ($quantity <= 0)                $error = 'Quantity must be greater than zero.';
        elseif (empty($reason))               $error = 'Reason / notes are required for audit trail.';
        elseif (!strtotime($adjDate))         $error = 'Invalid date.';
        else {
            $pdo   = _getPDO();
            $batch = _pdoOne(
                "SELECT b.*, p.product_name, p.id AS prod_id, p.product_code, p.units_per_pack
                 FROM batches b
                 JOIN products p ON p.id = b.product_id
                 WHERE b.id = :id",
                [':id' => $batchId]
            );

            if (!$batch) {
                $error = 'Selected batch not found.';
            } elseif ($isRemoval && $quantity > (int)$batch['remaining_quantity']) {
                $error = 'Cannot remove ' . $quantity . ' units — only ' .
                         $batch['remaining_quantity'] . ' available in this batch.';
            } else {
                // ── Process adjustment ─────────────────────────────────────────
                $qtyChange   = $isRemoval ? -$quantity : $quantity;
                $movType     = $isRemoval ? 'adjustment_out' : 'adjustment_in';
                $productId   = (int)$batch['prod_id'];
                $userId      = (int)($_SESSION['user_id'] ?? 1);
                $purchasePrice = (float)$batch['purchase_price'];
                $lossValue   = ($isRemoval && in_array($adjType, ['damage','theft']))
                               ? round($quantity * $purchasePrice, 2)
                               : 0;

                $pdo->beginTransaction();
                try {
                    // 1. Update batch remaining_quantity
                    _pdoRun(
                        "UPDATE batches
                         SET remaining_quantity = remaining_quantity + :q
                         WHERE id = :id",
                        [':q' => $qtyChange, ':id' => $batchId]
                    );

                    // 2. Insert stock_movements (audit trail)
                    _pdoRun(
                        "INSERT INTO stock_movements
                             (batch_id, product_id, movement_type, quantity, created_by, notes, created_at)
                         VALUES (:bid, :pid, :mtype, :qty, :uid, :notes, :dt)",
                        [
                            ':bid'   => $batchId,
                            ':pid'   => $productId,
                            ':mtype' => $movType,
                            ':qty'   => $quantity,
                            ':uid'   => $userId,
                            ':notes' => strtoupper($adjType) . ': ' . $reason,
                            ':dt'    => $adjDate . ' ' . date('H:i:s'),
                        ]
                    );

                    // 3. Cashbook loss entry for damage/theft
                    $cashbookEntryId = null;
                    if ($lossValue > 0) {
                        $typeLabel = $adjType === 'damage' ? 'Damaged Stock Loss' : 'Theft Stock Loss';
                        $cashbookEntryId = addCashbookEntry(
                            'expired_loss',
                            null,
                            'stock_adjustment',
                            $typeLabel . ' — ' .
                            $batch['product_name'] . ' (' . $batch['product_code'] . ')' .
                            ' Batch: ' . $batch['batch_number'] .
                            ' — ' . $reason,
                            $lossValue,
                            0,
                            'adjustment',
                            $userId,
                            $adjDate,
                            false
                        );
                    }

                    // 4. Insert stock_adjustment record
                    _pdoRun(
                        "INSERT INTO stock_adjustments
                             (batch_id, product_id, adjustment_type, quantity, reason, cashbook_entry_id, created_by, created_at)
                         VALUES (:bid, :pid, :atype, :qty, :reason, :cbid, :uid, :dt)",
                        [
                            ':bid'    => $batchId,
                            ':pid'    => $productId,
                            ':atype'  => $adjType,
                            ':qty'    => $qtyChange,
                            ':reason' => $reason,
                            ':cbid'   => $cashbookEntryId,
                            ':uid'    => $userId,
                            ':dt'     => $adjDate . ' ' . date('H:i:s'),
                        ]
                    );

                    $pdo->commit();

                    // 5. Refresh product stock (outside transaction)
                    updateProductStockAndPrice($productId);

                    // 6. Clear caches
                    clearAllCaches();

                    $actionWord = $isRemoval ? 'removed from' : 'added to';
                    $cbNote = ($cashbookEntryId)
                        ? ' Cashbook loss entry (Rs. ' . number_format($lossValue, 2) . ') recorded.'
                        : '';
                    $success = $quantity . ' unit(s) ' . $actionWord . ' stock for ' .
                               htmlspecialchars($batch['product_name']) . '.' . $cbNote;

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Database error while saving adjustment. Please try again.';
                    error_log('stock/adjust.php error: ' . $e->getMessage());
                }
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(isset($error) && $error
            ? ['success' => false, 'message' => $error]
            : ['success' => true,  'message' => $success ?? 'Adjustment saved.']
        );
        exit;
    }
}

// ── Load products for select ──────────────────────────────────────────────────
$products = _pdoAll(
    "SELECT id, product_name, product_code, stock_quantity
     FROM products WHERE status = 1
     ORDER BY product_name ASC",
    []
);

require_once '../includes/header.php';
?>
<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid py-4">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="mb-0 fw-bold">
        <i class="fas fa-tools me-2 text-warning"></i>New Stock Adjustment
      </h4>
      <small class="text-muted">Record damage, theft, correction or manual expiry</small>
    </div>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">
      <i class="fas fa-arrow-left me-1"></i> Back to List
    </a>
  </div>

  <!-- Flash Messages -->
  <?php if ($error): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <?php if ($success): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <!-- PharmForm handles redirect after success -->
  <?php endif; ?>

  <!-- Form Card -->
  <div class="row justify-content-center">
    <div class="col-12 col-lg-8 col-xl-7">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-warning bg-opacity-10 border-bottom">
          <h6 class="mb-0 fw-semibold text-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>Adjustment Details
          </h6>
        </div>
        <div class="card-body">
          <div id="adjustAlertArea" class="mb-3"></div>
          <form method="POST" id="adjustForm"
                data-pharm-form
                data-success-msg="Stock adjustment saved!"
                data-redirect="index.php"
                data-redirect-delay="2000"
                data-loading-text="Saving Adjustment…"
                data-alert-target="#adjustAlertArea"
                novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

            <!-- Row 1: Product + Date -->
            <div class="row g-3 mb-3">
              <div class="col-md-8">
                <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
                <select id="productSelect" class="form-select" required>
                  <option value="">— Select Product —</option>
                  <?php foreach ($products as $p): ?>
                  <option value="<?php echo $p['id']; ?>">
                    <?php echo htmlspecialchars($p['product_name']); ?>
                    (<?php echo htmlspecialchars($p['product_code']); ?>)
                    — Stock: <?php echo number_format($p['stock_quantity']); ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                <input type="date" name="adj_date" class="form-control"
                       value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
              </div>
            </div>

            <!-- Row 2: Batch -->
            <div class="mb-3">
              <label class="form-label fw-semibold">Batch <span class="text-danger">*</span></label>
              <select name="batch_id" id="batchSelect" class="form-select" required>
                <option value="">— Select product first —</option>
              </select>
              <div id="batchInfo" class="form-text"></div>
            </div>

            <!-- Row 3: Type + Qty -->
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Adjustment Type <span class="text-danger">*</span></label>
                <select name="adjustment_type" id="adjType" class="form-select" required onchange="updateUI()">
                  <option value="">— Select Type —</option>
                  <option value="damage">📦  Damage — Remove damaged units</option>
                  <option value="theft">🚨  Theft — Remove stolen units</option>
                  <option value="correction">✏️  Correction — Add units (stock count fix)</option>
                  <option value="expiry_manual">⏰  Manual Expiry — Remove expired units</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Quantity (units) <span class="text-danger">*</span></label>
                <input type="number" name="quantity" id="quantityInput" class="form-control"
                       min="1" step="1" required placeholder="e.g. 10">
                <div id="lossEstimate" class="form-text text-danger fw-semibold"></div>
              </div>
            </div>

            <!-- Type hint banner -->
            <div id="typeHintBox" class="alert alert-warning py-2 px-3 mb-3 small d-none">
              <i class="fas fa-info-circle me-1"></i><span id="typeHintText"></span>
            </div>

            <!-- Reason -->
            <div class="mb-4">
              <label class="form-label fw-semibold">Reason / Notes <span class="text-danger">*</span></label>
              <textarea name="reason" class="form-control" rows="3" required
                        placeholder="Mandatory: describe what happened for audit trail..."></textarea>
            </div>

            <!-- Submit -->
            <div class="d-flex gap-2">
              <button type="submit" id="submitBtn" data-submit-btn class="btn btn-warning px-4" disabled>
                <i class="fas fa-save me-2"></i>Save Adjustment
              </button>
              <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>

      <!-- Info panel -->
      <div class="card border-0 shadow-sm mt-3 border-start border-info border-3">
        <div class="card-body py-2 px-3">
          <small class="text-muted">
            <strong>📌 Cashbook Note:</strong>
            Damage and Theft adjustments automatically record a loss debit in the cashbook.
            Corrections and Manual Expiry do not create cashbook entries.
          </small>
        </div>
      </div>
    </div>
  </div>

</div><!-- /.container-fluid -->

<script>
const BASE_URL = '<?php echo BASE_URL; ?>';
let batchData  = {};

// Load batches when product changes
document.getElementById('productSelect').addEventListener('change', function() {
    const pid = this.value;
    const sel = document.getElementById('batchSelect');
    const info = document.getElementById('batchInfo');
    batchData = {};

    sel.innerHTML = '<option value="">Loading batches…</option>';
    document.getElementById('submitBtn').disabled = true;

    if (!pid) {
        sel.innerHTML = '<option value="">— Select product first —</option>';
        return;
    }

    fetch(`adjust.php?ajax=get_batches&product_id=${pid}`)
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">— Select Batch —</option>';
            if (!data.batches || !data.batches.length) {
                sel.innerHTML = '<option value="">No available batches</option>';
                return;
            }
            data.batches.forEach(b => {
                batchData[b.id] = b;
                const exp = b.expiry_date
                    ? ` | Exp: ${b.expiry_date}${b.is_expired == 1 ? ' ⚠️EXPIRED' : ''}`
                    : ' | No expiry';
                const opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = `${b.batch_number} | Stock: ${b.remaining_quantity}${exp}`;
                if (b.is_expired == 1) opt.style.color = '#dc3545';
                sel.appendChild(opt);
            });
        })
        .catch(() => {
            sel.innerHTML = '<option value="">Error loading batches</option>';
        });
});

// Update info when batch changes
document.getElementById('batchSelect').addEventListener('change', function() {
    const b = batchData[this.value];
    const info = document.getElementById('batchInfo');
    if (b) {
        info.innerHTML = `Available: <strong>${b.remaining_quantity}</strong> units | 
                          Purchase price: <strong>Rs. ${parseFloat(b.purchase_price).toFixed(2)}</strong> per unit`;
        document.getElementById('submitBtn').disabled = false;
    } else {
        info.textContent = '';
        document.getElementById('submitBtn').disabled = true;
    }
    updateLossEstimate();
});

document.getElementById('quantityInput').addEventListener('input', updateLossEstimate);

function updateLossEstimate() {
    const batchId = document.getElementById('batchSelect').value;
    const b = batchData[batchId];
    const qty = parseInt(document.getElementById('quantityInput').value) || 0;
    const type = document.getElementById('adjType').value;
    const lossDiv = document.getElementById('lossEstimate');

    if (b && qty > 0 && (type === 'damage' || type === 'theft')) {
        const loss = qty * parseFloat(b.purchase_price);
        lossDiv.textContent = `Estimated cashbook loss: Rs. ${loss.toFixed(2)}`;
    } else {
        lossDiv.textContent = '';
    }
}

function updateUI() {
    const type = document.getElementById('adjType').value;
    const hintBox = document.getElementById('typeHintBox');
    const hintTxt = document.getElementById('typeHintText');
    const hints = {
        'damage':        '⚠️ Removes units from stock. Records a LOSS DEBIT in Cashbook equal to qty × purchase price.',
        'theft':         '⚠️ Removes units from stock. Records a LOSS DEBIT in Cashbook equal to qty × purchase price.',
        'correction':    '✅ Adds units to stock (use for stock count corrections). No cashbook entry.',
        'expiry_manual': '⏰ Removes expired units from stock. No cashbook entry (use Expiry Sync for bulk).',
    };
    if (hints[type]) {
        hintTxt.textContent = hints[type];
        hintBox.classList.remove('d-none');
    } else {
        hintBox.classList.add('d-none');
    }
    updateLossEstimate();
}
</script>
</div><!-- /.main-content -->
<?php require_once '../includes/footer.php'; ?>
