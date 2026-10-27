<?php
/**
 * stock/index.php — Stock Adjustment Management (Phase 15: Full AJAX)
 *
 * AJAX-powered list for stock adjustments: damage, theft, correction, expiry_manual.
 * All filtering, searching, and pagination happen via AJAX (no page reloads).
 *
 * AJAX endpoint: ../ajax/inventory_adjustments.php
 *
 * Synchronized with: batches.remaining_quantity, stock_movements, cashbook (loss entries).
 */
require_once '../config/config.php';
require_once '../includes/sync_engine.php';

requireLogin();

if (!canView('stock')) {
    setFlash('error', 'Permission denied.', 'danger');
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

// ── Ensure table exists (safe migration) ─────────────────────────────────────
$pdo = _getPDO();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_adjustments` (
        `id`               int(11)       NOT NULL AUTO_INCREMENT,
        `batch_id`         int(11)       NOT NULL,
        `product_id`       int(11)       NOT NULL DEFAULT 0,
        `adjustment_type`  enum('damage','theft','correction','expiry_manual') NOT NULL,
        `quantity`         int(11)       NOT NULL,
        `reason`           varchar(255)  NOT NULL,
        `cashbook_entry_id` int(11)      DEFAULT NULL,
        `created_by`       int(11)       NOT NULL,
        `created_at`       timestamp     NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_batch`   (`batch_id`),
        KEY `idx_product` (`product_id`),
        KEY `idx_date`    (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    $pdo->exec("UPDATE stock_adjustments sa JOIN batches b ON b.id = sa.batch_id SET sa.product_id = b.product_id WHERE sa.product_id = 0");
} catch (Exception $e) {
    error_log('stock/index.php table check: ' . $e->getMessage());
}

// ── CSV Export (server-side, no AJAX needed) ──────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv' && canView('stock')) {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $type = $_GET['type'] ?? '';

    $where  = "sa.created_at BETWEEN :from AND :to_end";
    $params = [':from' => $from . ' 00:00:00', ':to_end' => $to . ' 23:59:59'];
    if ($type) { $where .= " AND sa.adjustment_type = :type"; $params[':type'] = $type; }

    $rows = getAll(
        "SELECT sa.id, sa.adjustment_type, sa.quantity, sa.reason, sa.created_at,
                p.product_name, p.product_code, b.batch_number, b.expiry_date,
                b.purchase_price, u.full_name AS done_by
         FROM stock_adjustments sa
         JOIN batches b ON b.id = sa.batch_id
         JOIN products p ON p.id = b.product_id
         LEFT JOIN users u ON u.id = sa.created_by
         WHERE $where ORDER BY sa.created_at DESC", $params
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_adjustments_' . date('Ymd') . '.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['ID','Date','Type','Product','Code','Batch','Expiry','Qty','Est.Loss (Rs)','Reason','Done By']);
    foreach ($rows as $r) {
        $loss = $r['quantity'] < 0 ? abs($r['quantity']) * (float)$r['purchase_price'] : 0;
        fputcsv($f, [
            $r['id'], date('d M Y H:i', strtotime($r['created_at'])),
            ucfirst(str_replace('_', ' ', $r['adjustment_type'])),
            $r['product_name'], $r['product_code'], $r['batch_number'],
            $r['expiry_date'] ? date('M Y', strtotime($r['expiry_date'])) : 'N/A',
            $r['quantity'], number_format($loss, 2), $r['reason'], $r['done_by'] ?? 'System',
        ]);
    }
    fclose($f);
    exit;
}

$page_title = 'Stock Adjustments';
require_once '../includes/header.php';
?>
<style>
.main-content { margin-left: var(--sb-w, 260px); padding: 20px; min-height: 100vh; transition: margin-left .25s ease; animation: fadeInPage 0.28s ease; }
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
@media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
@keyframes fadeInPage { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

/* ── AJAX loading state ── */
#tableWrapper { position: relative; transition: opacity .2s ease; }
#tableWrapper.loading { opacity: .45; pointer-events: none; }
#tableWrapper.loading::after {
    content: '';
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
}
.spinner-overlay {
    position: absolute; inset: 0; z-index: 10;
    display: none; align-items: center; justify-content: center;
    background: rgba(255,255,255,.55);
    border-radius: 8px;
}
#tableWrapper.loading .spinner-overlay { display: flex; }

/* ── Summary card animation ── */
.summary-val { transition: all .3s ease; }
</style>
<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid py-4">

  <!-- Page Header -->
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h4 class="mb-0 fw-bold">
        <i class="fas fa-tools me-2 text-warning"></i>Stock Adjustments
      </h4>
      <small class="text-muted">Record and track damage, theft, corrections and manual expiry</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="transfers.php" class="btn btn-outline-info btn-sm">
        <i class="fas fa-exchange-alt me-1"></i> Stock Transfers
      </a>
      <?php if (canAdd('stock')): ?>
      <a href="adjust.php" class="btn btn-warning btn-sm">
        <i class="fas fa-plus-circle me-1"></i> New Adjustment
      </a>
      <?php endif; ?>
      <button type="button" class="btn btn-outline-success btn-sm" id="btnExportCSV">
        <i class="fas fa-file-csv me-1"></i> Export CSV
      </button>
    </div>
  </div>

  <!-- Summary Cards (AJAX-updated) -->
  <div class="row g-3 mb-4" id="summaryCards">
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm border-start border-danger border-4">
        <div class="card-body py-3">
          <div class="text-muted small text-uppercase fw-semibold">Damage</div>
          <div class="h4 text-danger mb-0"><span class="summary-val" id="sumDamage">—</span> units</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm border-start border-warning border-4">
        <div class="card-body py-3">
          <div class="text-muted small text-uppercase fw-semibold">Theft</div>
          <div class="h4 text-warning mb-0"><span class="summary-val" id="sumTheft">—</span> units</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm border-start border-info border-4">
        <div class="card-body py-3">
          <div class="text-muted small text-uppercase fw-semibold">Corrections</div>
          <div class="h4 text-info mb-0"><span class="summary-val" id="sumCorrection">—</span> units</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card border-0 shadow-sm border-start border-secondary border-4">
        <div class="card-body py-3">
          <div class="text-muted small text-uppercase fw-semibold">Est. Loss Value</div>
          <div class="h4 text-secondary mb-0">Rs. <span class="summary-val" id="sumLoss">—</span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Filter Form -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
      <form id="filterForm" class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
          <label class="form-label small fw-semibold mb-1">From Date</label>
          <input type="date" name="from" id="fromDate" class="form-control form-control-sm"
                 value="<?php echo date('Y-m-01'); ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small fw-semibold mb-1">To Date</label>
          <input type="date" name="to" id="toDate" class="form-control form-control-sm"
                 value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small fw-semibold mb-1">Type</label>
          <select name="type" id="typeFilter" class="form-select form-select-sm">
            <option value="">All Types</option>
            <option value="damage">Damage</option>
            <option value="theft">Theft</option>
            <option value="correction">Correction</option>
            <option value="expiry_manual">Manual Expiry</option>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label small fw-semibold mb-1">Search</label>
          <input type="text" name="search" id="searchInput" class="form-control form-control-sm"
                 placeholder="Product name, code or batch number…">
        </div>
        <div class="col-6 col-md-1">
          <label class="form-label small fw-semibold mb-1">Per Page</label>
          <select name="per_page" id="perPage" class="form-select form-select-sm">
            <option value="25" selected>25</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </div>
        <div class="col-6 col-md-1">
          <button type="submit" class="btn btn-primary btn-sm w-100 mt-auto">
            <i class="fas fa-search"></i>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Results Table -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center">
      <span class="fw-semibold" id="recordCount">Loading…</span>
      <span class="text-muted small" id="dateRange"></span>
    </div>
    <div class="card-body p-0">
      <div id="tableWrapper" style="min-height:200px;">
        <div class="spinner-overlay">
          <div class="spinner-border text-warning" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th class="ps-3">#</th>
                <th>Date &amp; Time</th>
                <th>Product</th>
                <th>Batch</th>
                <th>Type</th>
                <th class="text-center">Qty Change</th>
                <th class="text-end">Est. Loss</th>
                <th>Reason</th>
                <th>Cashbook</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody id="tableBody">
              <tr><td colspan="10" class="text-center py-4 text-muted">
                <i class="fas fa-spinner fa-spin me-2"></i>Loading adjustments…
              </td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <!-- Pagination -->
    <div class="card-footer bg-transparent" id="paginationWrap"></div>
  </div>

</div><!-- /container-fluid -->
</div><!-- /main-content -->

<?php require_once '../includes/footer.php'; ?>

<script>
(function () {
  'use strict';

  var AJAX_URL   = '<?php echo BASE_URL; ?>ajax/inventory_adjustments.php';
  var BASE_URL   = '<?php echo BASE_URL; ?>';
  var csrf       = '<?php echo generateCSRFToken(); ?>';
  var currentPage = 1;
  var searchTimer;

  // ── DOM refs ──────────────────────────────────────────────────────────────
  var filterForm    = document.getElementById('filterForm');
  var tableBody     = document.getElementById('tableBody');
  var tableWrapper  = document.getElementById('tableWrapper');
  var paginationWrap= document.getElementById('paginationWrap');
  var recordCount   = document.getElementById('recordCount');
  var dateRange     = document.getElementById('dateRange');
  var searchInput   = document.getElementById('searchInput');

  // Summary card spans
  var sumDamage     = document.getElementById('sumDamage');
  var sumTheft      = document.getElementById('sumTheft');
  var sumCorrection = document.getElementById('sumCorrection');
  var sumLoss       = document.getElementById('sumLoss');

  // ── Helpers ───────────────────────────────────────────────────────────────
  function fmt(n) { return Number(n).toLocaleString(); }

  function buildParams(page) {
    var fd = new FormData(filterForm);
    fd.set('page', page || currentPage);
    return new URLSearchParams(fd).toString();
  }

  function setLoading(on) {
    if (on) { tableWrapper.classList.add('loading'); }
    else     { tableWrapper.classList.remove('loading'); }
  }

  // ── Main AJAX fetch ───────────────────────────────────────────────────────
  function fetchTable(page) {
    page = page || 1;
    currentPage = page;
    setLoading(true);

    var qs = buildParams(page);
    history.replaceState(null, '', '?' + qs);

    fetch(AJAX_URL + '?' + qs, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        setLoading(false);
        if (!data.success) {
          tableBody.innerHTML = '<tr><td colspan="10" class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle me-2"></i>' + (data.message || 'Error loading data.') + '</td></tr>';
          return;
        }

        // Update table rows
        tableBody.innerHTML = data.tableHtml;

        // Update record count + date range
        recordCount.textContent = fmt(data.total) + ' Record(s)';
        if (data.filters) {
          dateRange.textContent = (data.filters.from || '') + ' — ' + (data.filters.to || '');
        }

        // Update summary cards
        if (data.summary) {
          sumDamage.textContent     = fmt(data.summary.damage     || 0);
          sumTheft.textContent      = fmt(data.summary.theft      || 0);
          sumCorrection.textContent = fmt(data.summary.correction || 0);
          sumLoss.textContent       = fmt(data.summary.loss_value || 0);
        }

        // Update pagination
        paginationWrap.innerHTML = data.paginationHtml || '';
        bindPaginationClicks();
      })
      .catch(function (err) {
        setLoading(false);
        tableBody.innerHTML = '<tr><td colspan="10" class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle me-2"></i>Failed to load data. Please try again.</td></tr>';
      });
  }

  // ── Pagination click delegation ───────────────────────────────────────────
  function bindPaginationClicks() {
    paginationWrap.querySelectorAll('a[data-page]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var p = parseInt(this.getAttribute('data-page'));
        if (!isNaN(p)) fetchTable(p);
      });
    });
  }

  // ── Form submit (Filter button) ───────────────────────────────────────────
  filterForm.addEventListener('submit', function (e) {
    e.preventDefault();
    fetchTable(1);
  });

  // ── Date fields instant trigger ───────────────────────────────────────────
  ['fromDate', 'toDate', 'typeFilter', 'perPage'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', function () { fetchTable(1); });
  });

  // ── Search debounce (400ms) ───────────────────────────────────────────────
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { fetchTable(1); }, 400);
    });
  }

  // ── CSV Export — include current filters ──────────────────────────────────
  var btnExport = document.getElementById('btnExportCSV');
  if (btnExport) {
    btnExport.addEventListener('click', function () {
      var fd   = new FormData(filterForm);
      fd.set('export', 'csv');
      var qs   = new URLSearchParams(fd).toString();
      window.location.href = 'index.php?' + qs;
    });
  }

  // ── Initial load ──────────────────────────────────────────────────────────
  fetchTable(1);

})();
</script>
