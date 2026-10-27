<?php
/**
 * stock/transfers.php — Stock Transfer Management (Phase 15: AJAX)
 *
 * Allows transferring stock units from one batch to another batch of the same product.
 * All synchronization is handled by ajax/save_stock_transfer.php and ajax/inventory_transfers.php.
 *
 * Synchronized with:
 *   batches.remaining_quantity  — from_batch -=qty, to_batch +=qty
 *   stock_movements             — audit trail (transfer_in / transfer_out)
 *   stock_transfers             — transfer record
 *   products.stock_quantity     — recalculated after commit
 */
require_once '../config/config.php';
require_once '../includes/sync_engine.php';

requireLogin();

if (!canView('stock')) {
    setFlash('error', 'Permission denied.', 'danger');
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

// Load active products for the form select
$products = [];
try {
    $products = getAll(
        "SELECT id, product_name, product_code, stock_quantity
         FROM products WHERE status = 1
         ORDER BY product_name ASC",
        []
    );
} catch (Exception $e) {
    error_log('stock/transfers.php products: ' . $e->getMessage());
}

$page_title = 'Stock Transfers';
require_once '../includes/header.php';
?>
<style>
.main-content { margin-left: var(--sb-w, 260px); padding: 20px; min-height: 100vh; transition: margin-left .25s ease; animation: fadeInPage 0.28s ease; }
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
@media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
@keyframes fadeInPage { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

#listWrapper { position: relative; transition: opacity .2s ease; }
#listWrapper.loading { opacity: .45; pointer-events: none; }
.spinner-overlay { position: absolute; inset: 0; z-index: 10; display: none; align-items: center; justify-content: center; background: rgba(255,255,255,.55); border-radius: 8px; }
#listWrapper.loading .spinner-overlay { display: flex; }
</style>
<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid py-4">

  <!-- Page Header -->
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h4 class="mb-0 fw-bold">
        <i class="fas fa-exchange-alt me-2 text-info"></i>Stock Transfers
      </h4>
      <small class="text-muted">Move stock units between batches of the same product</small>
    </div>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">
      <i class="fas fa-tools me-1"></i> Stock Adjustments
    </a>
  </div>

  <div class="row g-4">

    <!-- ── Transfer Form (left) ───────────────────────────────────────────── -->
    <?php if (canAdd('stock')): ?>
    <div class="col-12 col-lg-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-info bg-opacity-10 border-bottom">
          <h6 class="mb-0 fw-semibold text-info">
            <i class="fas fa-exchange-alt me-2"></i>New Transfer
          </h6>
        </div>
        <div class="card-body">
          <div id="transferAlert" class="mb-3"></div>

          <!-- Product select -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
            <select id="tProductSelect" class="form-select">
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

          <!-- From Batch -->
          <div class="mb-3">
            <label class="form-label fw-semibold">From Batch <span class="text-danger">*</span></label>
            <select id="tFromBatch" name="from_batch_id" class="form-select" disabled>
              <option value="">— Select product first —</option>
            </select>
            <div id="fromBatchInfo" class="form-text text-muted mt-1"></div>
          </div>

          <!-- To Batch -->
          <div class="mb-3">
            <label class="form-label fw-semibold">To Batch <span class="text-danger">*</span></label>
            <select id="tToBatch" name="to_batch_id" class="form-select" disabled>
              <option value="">— Select product first —</option>
            </select>
            <div id="toBatchInfo" class="form-text text-muted mt-1"></div>
          </div>

          <!-- Quantity -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Quantity (units) <span class="text-danger">*</span></label>
            <input type="number" id="tQuantity" class="form-control" min="1" step="1" placeholder="e.g. 50">
            <div id="tQtyHelper" class="form-text text-muted"></div>
          </div>

          <!-- Reason -->
          <div class="mb-4">
            <label class="form-label fw-semibold">Reason <small class="text-muted">(optional)</small></label>
            <textarea id="tReason" class="form-control" rows="2" placeholder="e.g. Consolidating old batches before expiry…"></textarea>
          </div>

          <div class="d-flex gap-2">
            <button type="button" id="tSubmitBtn" class="btn btn-info text-white px-4" disabled>
              <i class="fas fa-exchange-alt me-2"></i>Transfer Stock
            </button>
            <button type="button" id="tResetBtn" class="btn btn-outline-secondary">Reset</button>
          </div>
        </div>
        <!-- Info panel -->
        <div class="card-footer bg-transparent border-top-0">
          <small class="text-muted">
            <i class="fas fa-info-circle me-1 text-info"></i>
            Transfers move units between batches. Both source and destination must belong to the same product.
            Stock movements are recorded for full audit trail.
          </small>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Transfers List (right) ─────────────────────────────────────────── -->
    <div class="col-12 col-lg-<?php echo canAdd('stock') ? '8' : '12'; ?>">

      <!-- Summary cards -->
      <div class="row g-3 mb-3">
        <div class="col-6">
          <div class="card border-0 shadow-sm border-start border-info border-4">
            <div class="card-body py-3">
              <div class="text-muted small text-uppercase fw-semibold">Total Transfers</div>
              <div class="h4 text-info mb-0"><span id="sumTransfers">—</span></div>
            </div>
          </div>
        </div>
        <div class="col-6">
          <div class="card border-0 shadow-sm border-start border-primary border-4">
            <div class="card-body py-3">
              <div class="text-muted small text-uppercase fw-semibold">Units Moved</div>
              <div class="h4 text-primary mb-0"><span id="sumUnits">—</span></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Filter bar -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
          <form id="listFilterForm" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
              <label class="form-label small fw-semibold mb-1">From Date</label>
              <input type="date" name="from" id="listFrom" class="form-control form-control-sm"
                     value="<?php echo date('Y-m-01'); ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small fw-semibold mb-1">To Date</label>
              <input type="date" name="to" id="listTo" class="form-control form-control-sm"
                     value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label small fw-semibold mb-1">Search</label>
              <input type="text" name="search" id="listSearch" class="form-control form-control-sm"
                     placeholder="Product name, code or batch…">
            </div>
            <div class="col-12 col-md-2">
              <button type="submit" class="btn btn-primary btn-sm w-100">
                <i class="fas fa-search me-1"></i>Filter
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Table -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center">
          <span class="fw-semibold" id="tRecordCount">Loading…</span>
        </div>
        <div class="card-body p-0">
          <div id="listWrapper" style="min-height:160px;">
            <div class="spinner-overlay">
              <div class="spinner-border text-info" role="status"><span class="visually-hidden">Loading…</span></div>
            </div>
            <div class="table-responsive">
              <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                  <tr>
                    <th class="ps-3">#</th>
                    <th>Date</th>
                    <th>Product</th>
                    <th>From Batch</th>
                    <th class="text-center">→</th>
                    <th>To Batch</th>
                    <th class="text-center">Units</th>
                    <th>Reason</th>
                    <th>By</th>
                  </tr>
                </thead>
                <tbody id="tListBody">
                  <tr><td colspan="9" class="text-center py-4 text-muted">
                    <i class="fas fa-spinner fa-spin me-2"></i>Loading transfers…
                  </td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="card-footer bg-transparent" id="tPagination"></div>
      </div>
    </div>

  </div><!-- /row -->
</div><!-- /container-fluid -->
</div><!-- /main-content -->

<?php require_once '../includes/footer.php'; ?>

<script>
(function () {
  'use strict';

  var BASE_URL    = '<?php echo BASE_URL; ?>';
  var AJAX_LIST   = BASE_URL + 'ajax/inventory_transfers.php';
  var AJAX_SAVE   = BASE_URL + 'ajax/save_stock_transfer.php';
  var AJAX_BATCH  = BASE_URL + 'ajax/inventory_transfers.php?action=get_batches&product_id=';
  var CSRF        = '<?php echo generateCSRFToken(); ?>';

  var currentPage  = 1;
  var listTimer;
  var batchData    = {};       // cache batches by id
  var selectedProductId = 0;

  // ── Transfer form refs ─────────────────────────────────────────────────────
  var tProductSelect = document.getElementById('tProductSelect');
  var tFromBatch     = document.getElementById('tFromBatch');
  var tToBatch       = document.getElementById('tToBatch');
  var tQuantity      = document.getElementById('tQuantity');
  var tReason        = document.getElementById('tReason');
  var tSubmitBtn     = document.getElementById('tSubmitBtn');
  var tResetBtn      = document.getElementById('tResetBtn');
  var transferAlert  = document.getElementById('transferAlert');
  var fromBatchInfo  = document.getElementById('fromBatchInfo');
  var toBatchInfo    = document.getElementById('toBatchInfo');
  var tQtyHelper     = document.getElementById('tQtyHelper');

  // ── List refs ─────────────────────────────────────────────────────────────
  var listWrapper    = document.getElementById('listWrapper');
  var tListBody      = document.getElementById('tListBody');
  var tPagination    = document.getElementById('tPagination');
  var tRecordCount   = document.getElementById('tRecordCount');
  var listFilterForm = document.getElementById('listFilterForm');
  var sumTransfers   = document.getElementById('sumTransfers');
  var sumUnits       = document.getElementById('sumUnits');

  // ── Helpers ───────────────────────────────────────────────────────────────
  function fmt(n)   { return Number(n).toLocaleString(); }
  function fmtDate(d) { if (!d || d === '0000-00-00') return ''; return d; }

  function showAlert(html, type) {
    transferAlert.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show py-2">' +
      html + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
  }

  // ── Load batches for product ───────────────────────────────────────────────
  function loadBatches(pid) {
    batchData = {};
    [tFromBatch, tToBatch].forEach(function(sel) {
      sel.innerHTML = '<option value="">Loading…</option>';
      sel.disabled = true;
    });
    fromBatchInfo.textContent = '';
    toBatchInfo.textContent   = '';
    tSubmitBtn.disabled = true;

    if (!pid) {
      [tFromBatch, tToBatch].forEach(function(sel) {
        sel.innerHTML = '<option value="">— Select product first —</option>';
      });
      return;
    }

    fetch(AJAX_BATCH + pid, { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.success || !data.batches || !data.batches.length) {
          [tFromBatch, tToBatch].forEach(function(sel) {
            sel.innerHTML = '<option value="">No available batches</option>';
          });
          return;
        }

        var opts = '<option value="">— Select Batch —</option>';
        data.batches.forEach(function(b) {
          batchData[b.id] = b;
          var exp = (b.expiry_date && b.expiry_date !== '0000-00-00')
            ? ' | Exp: ' + b.expiry_date + (b.is_expired == 1 ? ' ⚠️' : '')
            : ' | No expiry';
          opts += '<option value="' + b.id + '"' + (b.is_expired == 1 ? ' style="color:#dc3545"' : '') + '>' +
                  b.batch_number + ' | Stock: ' + b.remaining_quantity + exp + '</option>';
        });

        [tFromBatch, tToBatch].forEach(function(sel) {
          sel.innerHTML = opts;
          sel.disabled  = false;
        });
      })
      .catch(function() {
        [tFromBatch, tToBatch].forEach(function(sel) {
          sel.innerHTML = '<option value="">Error loading batches</option>';
        });
      });
  }

  // ── Update batch info labels ───────────────────────────────────────────────
  function updateBatchInfos() {
    var fb = batchData[tFromBatch.value];
    var tb = batchData[tToBatch.value];

    fromBatchInfo.textContent = fb
      ? 'Available: ' + fb.remaining_quantity + ' units | Purchase: Rs. ' + parseFloat(fb.purchase_price).toFixed(2)
      : '';
    toBatchInfo.textContent = tb
      ? 'Available: ' + tb.remaining_quantity + ' units | Purchase: Rs. ' + parseFloat(tb.purchase_price).toFixed(2)
      : '';

    var qty = parseInt(tQuantity.value) || 0;
    if (fb && qty > 0) {
      if (qty > parseInt(fb.remaining_quantity)) {
        tQtyHelper.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Exceeds available stock (' + fb.remaining_quantity + ')!</span>';
      } else {
        tQtyHelper.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Valid — ' + (parseInt(fb.remaining_quantity) - qty) + ' will remain in source batch.</span>';
      }
    } else {
      tQtyHelper.textContent = '';
    }

    var canSubmit = fb && tb && fb.id !== tb.id && qty > 0 && qty <= parseInt(fb.remaining_quantity);
    tSubmitBtn.disabled = !canSubmit;
  }

  // ── Transfer form events ───────────────────────────────────────────────────
  if (tProductSelect) {
    tProductSelect.addEventListener('change', function() {
      selectedProductId = parseInt(this.value) || 0;
      loadBatches(selectedProductId);
    });
  }

  if (tFromBatch) tFromBatch.addEventListener('change', updateBatchInfos);
  if (tToBatch)   tToBatch.addEventListener('change', updateBatchInfos);
  if (tQuantity)  tQuantity.addEventListener('input',  updateBatchInfos);

  // ── Submit transfer ────────────────────────────────────────────────────────
  if (tSubmitBtn) {
    tSubmitBtn.addEventListener('click', function() {
      var fb  = batchData[tFromBatch.value];
      var tb  = batchData[tToBatch.value];
      var qty = parseInt(tQuantity.value) || 0;

      if (!fb || !tb || fb.id === tb.id || qty <= 0) {
        showAlert('<i class="fas fa-exclamation-triangle me-2"></i>Please fill all fields correctly.', 'warning');
        return;
      }

      tSubmitBtn.disabled = true;
      tSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Transferring…';

      var fd = new FormData();
      fd.append('csrf_token',    CSRF);
      fd.append('product_id',    selectedProductId);
      fd.append('from_batch_id', tFromBatch.value);
      fd.append('to_batch_id',   tToBatch.value);
      fd.append('quantity',      qty);
      fd.append('reason',        tReason ? tReason.value : '');

      fetch(AJAX_SAVE, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.success) {
            showAlert('<i class="fas fa-check-circle me-2"></i>' + data.message, 'success');
            // Reload the list
            fetchList(1);
            // Reset batch selects to show updated stock
            loadBatches(selectedProductId);
            if (tQuantity) tQuantity.value = '';
            if (tReason)   tReason.value   = '';
          } else {
            showAlert('<i class="fas fa-exclamation-triangle me-2"></i>' + (data.message || 'Transfer failed.'), 'danger');
            tSubmitBtn.disabled = false;
          }
        })
        .catch(function() {
          showAlert('<i class="fas fa-exclamation-triangle me-2"></i>Network error. Please try again.', 'danger');
          tSubmitBtn.disabled = false;
        })
        .finally(function() {
          tSubmitBtn.innerHTML = '<i class="fas fa-exchange-alt me-2"></i>Transfer Stock';
          updateBatchInfos();
        });
    });
  }

  if (tResetBtn) {
    tResetBtn.addEventListener('click', function() {
      if (tProductSelect) tProductSelect.value = '';
      batchData = {};
      [tFromBatch, tToBatch].forEach(function(sel) {
        if (sel) { sel.innerHTML = '<option value="">— Select product first —</option>'; sel.disabled = true; }
      });
      if (tQuantity) tQuantity.value = '';
      if (tReason)   tReason.value   = '';
      if (fromBatchInfo) fromBatchInfo.textContent = '';
      if (toBatchInfo)   toBatchInfo.textContent   = '';
      if (tQtyHelper)    tQtyHelper.textContent    = '';
      if (tSubmitBtn)    tSubmitBtn.disabled       = true;
      if (transferAlert) transferAlert.innerHTML   = '';
    });
  }

  // ── List AJAX ─────────────────────────────────────────────────────────────
  function buildListParams(page) {
    var fd = new FormData(listFilterForm);
    fd.set('page', page || currentPage);
    return new URLSearchParams(fd).toString();
  }

  function fetchList(page) {
    page = page || 1;
    currentPage = page;
    listWrapper.classList.add('loading');

    fetch(AJAX_LIST + '?' + buildListParams(page), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        listWrapper.classList.remove('loading');
        if (!data.success) {
          tListBody.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">' + (data.message || 'Error loading data.') + '</td></tr>';
          return;
        }
        tListBody.innerHTML = data.tableHtml;
        tRecordCount.textContent = fmt(data.total) + ' Transfer(s)';

        if (data.summary) {
          if (sumTransfers) sumTransfers.textContent = fmt(data.summary.total_transfers || 0);
          if (sumUnits)     sumUnits.textContent     = fmt(data.summary.total_units     || 0);
        }

        tPagination.innerHTML = data.paginationHtml || '';
        bindPaginationClicks();
      })
      .catch(function() {
        listWrapper.classList.remove('loading');
        tListBody.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">Failed to load data. Please try again.</td></tr>';
      });
  }

  function bindPaginationClicks() {
    tPagination.querySelectorAll('a[data-page]').forEach(function(a) {
      a.addEventListener('click', function(e) {
        e.preventDefault();
        var p = parseInt(this.getAttribute('data-page'));
        if (!isNaN(p)) fetchList(p);
      });
    });
  }

  if (listFilterForm) {
    listFilterForm.addEventListener('submit', function(e) {
      e.preventDefault();
      fetchList(1);
    });

    ['listFrom', 'listTo'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('change', function() { fetchList(1); });
    });

    var listSearch = document.getElementById('listSearch');
    if (listSearch) {
      listSearch.addEventListener('input', function() {
        clearTimeout(listTimer);
        listTimer = setTimeout(function() { fetchList(1); }, 400);
      });
    }
  }

  // ── Initial list load ─────────────────────────────────────────────────────
  fetchList(1);

})();
</script>
