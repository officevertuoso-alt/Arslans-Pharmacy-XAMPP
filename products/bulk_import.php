<?php
/**
 * products/bulk_import.php
 * Bulk Import — Rebuilt to match add.php style exactly
 * - Medicine / Non-Medicine toggle (same as add.php)
 * - Same card-box, section-header, gradient buttons
 * - CSRF protection
 * - PDO-based via product_functions.php
 */

require_once '../config/config.php';
require_once '../includes/product_functions.php';

requirePharmacist();

if (function_exists('canDo') && !canDo('products', 'import')) {
    die('Access Denied — You do not have permission to import products.');
}

if (!function_exists('sanitize')) {
    function sanitize($v) { return htmlspecialchars(trim((string)$v), ENT_QUOTES, 'UTF-8'); }
}

$csrf = generateCSRFToken();

// Pharmacy name
$pharmacyName = 'Pharmacy';
try {
    $companyRow   = _pdoOne("SELECT company_name FROM company_settings LIMIT 1");
    $pharmacyName = $companyRow['company_name'] ?? 'Pharmacy';
} catch (\Throwable $e) {}

// Import History
$importHistory = [];
try {
    $importHistory = _pdoAll("
        SELECT il.*, u.full_name AS imported_by_name
        FROM bulk_import_logs il
        LEFT JOIN users u ON u.id = il.imported_by
        ORDER BY il.created_at DESC
        LIMIT 50
    ");
} catch (\Throwable $e) { $importHistory = []; }

$page_title = 'Bulk Product Import — Pharmacy Management System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>


<style>
/* ── Layout (matches add.php exactly) ── */

body  { background: #f0f2f5; font-size: 14px; }
.main-content { margin-left: var(--sb-w, 260px); padding: 24px; min-height: 100vh; transition: margin-left .25s ease; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
@media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

/* ── Card style (matches add.php) ── */
.card-box {
    border: none; border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,.05); background: #fff;
}
.section-header {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: #fff; border-radius: 12px 12px 0 0; padding: 14px 20px;
}
.section-header-green {
    background: linear-gradient(135deg, #11998e, #38ef7d);
    color: #fff; border-radius: 12px 12px 0 0; padding: 14px 20px;
}

/* ── Category toggle (copy of add.php) ── */
.cat-toggle { display: flex; gap: 12px; margin-bottom: 20px; }
.cat-btn {
    flex: 1; padding: 18px; border-radius: 12px;
    border: 2px solid #dee2e6; background: #fff; cursor: pointer;
    text-align: center; transition: .2s; font-size: 15px; font-weight: 600;
}
.cat-btn.active.med    { border-color: #0d6efd; background: #e8f0fe; color: #0d6efd; }
.cat-btn.active.nonmed { border-color: #20c997; background: #e6f9f3; color: #20c997; }
.cat-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.1); }

/* ── Header buttons (matches add.php) ── */
.btn-bulk-import {
    background: linear-gradient(135deg, #11998e, #38ef7d);
    color: #fff; border: none; border-radius: 10px; padding: 9px 20px;
}
.btn-bulk-import:hover { background: linear-gradient(135deg, #0e8c80, #2fd672); color: #fff; }

/* ── Step wizard ── */
.step-wizard { display: flex; align-items: center; gap: 0; }
.step-item   { display: flex; flex-direction: column; align-items: center; position: relative; flex: 1; }
.step-circle {
    width: 38px; height: 38px; border-radius: 50%;
    background: #dee2e6; color: #6c757d; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; z-index: 1; border: 3px solid #fff;
    box-shadow: 0 0 0 3px #dee2e6;
}
.step-item.active .step-circle { background: #0d6efd; color: #fff; box-shadow: 0 0 0 3px #b6d4fe; }
.step-item.done   .step-circle { background: #198754; color: #fff; box-shadow: 0 0 0 3px #a3cfbb; }
.step-label { font-size: .72rem; color: #6c757d; margin-top: 6px; text-align: center; white-space: nowrap; }
.step-item.active .step-label  { color: #0d6efd; font-weight: 600; }
.step-item.done   .step-label  { color: #198754; }
.step-connector { flex: 1; height: 3px; background: #dee2e6; margin-top: -20px; z-index: 0; }
.step-connector.done { background: #198754; }

/* ── Drop zone ── */
.drop-zone {
    border: 3px dashed #adb5bd; border-radius: 12px;
    background: #f8f9fa; padding: 3rem 2rem;
    text-align: center; cursor: pointer;
    transition: border-color .25s, background .25s;
}
.drop-zone:hover, .drop-zone.drag-over { border-color: #0d6efd; background: #e7f1ff; }
.drop-zone .drop-icon { font-size: 3.5rem; color: #adb5bd; display: block; margin-bottom: 1rem; }
.drop-zone.drag-over .drop-icon { color: #0d6efd; }

/* ── Template cards ── */
.template-card {
    border-radius: 12px; overflow: hidden;
    border: 2px solid transparent; transition: .2s;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.template-card:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,.1); }
.template-card.med-card  { border-color: #0d6efd; }
.template-card.nmed-card { border-color: #20c997; }
.template-card-header-med  { background: linear-gradient(135deg,#667eea,#764ba2); color: #fff; padding: 16px 20px; }
.template-card-header-nmed { background: linear-gradient(135deg,#11998e,#38ef7d); color: #fff; padding: 16px 20px; }

/* ── Preview table ── */
#previewTable th { font-size: .78rem; }
#previewTable td { font-size: .8rem; vertical-align: middle; }

/* ── Import log ── */
#importLog {
    background: #1e1e1e; color: #d4d4d4; font-family: monospace;
    font-size: .8rem; border-radius: 8px; padding: 1rem;
    height: 280px; overflow-y: auto; white-space: pre-wrap;
}
.log-ok   { color: #4ec9b0; }
.log-warn { color: #ce9178; }
.log-fail { color: #f44747; }
.log-info { color: #9cdcfe; }

/* ── Note box (matches add.php) ── */
.note-box {
    background: #fff3cd; border: 1px solid #ffc107;
    border-radius: 8px; padding: 10px 14px; font-size: 12.5px;
}

/* ── History table ── */
.history-table th { font-size: 12px; }
.history-table td { font-size: 13px; vertical-align: middle; }
</style>

</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">
<?php include '../includes/sidebar.php'; ?>
<?php if (file_exists('../includes/navbar.php')) include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid" style="max-width:1200px;">

  <!-- ── Header (matches add.php style) ── -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="mb-1 fw-bold"><i class="fas fa-file-import me-2 text-success"></i>Bulk Product Import</h4>
      <p class="text-muted mb-0 small">Import multiple products at once using Excel or CSV template.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="list.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to List</a>
      <a href="add.php" class="btn btn-bulk-import"><i class="fas fa-plus-circle me-2"></i>Add Single Product</a>
    </div>
  </div>

  <!-- ── Tabs ── -->
  <ul class="nav nav-tabs mb-4" id="importTabs">
    <li class="nav-item">
      <button class="nav-link active fw-semibold" data-bs-toggle="tab" data-bs-target="#tabImport">
        <i class="fas fa-upload me-1"></i>Import Products
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tabHistory">
        <i class="fas fa-history me-1"></i>Import History
        <?php if (!empty($importHistory)): ?>
        <span class="badge bg-secondary ms-1"><?= count($importHistory) ?></span>
        <?php endif; ?>
      </button>
    </li>
  </ul>

  <div class="tab-content">

  <!-- ════════════════ TAB 1: IMPORT ════════════════ -->
  <div class="tab-pane fade show active" id="tabImport">

    <!-- Step Wizard -->
    <div class="card-box p-4 mb-4">
      <div class="step-wizard" id="stepWizard">
        <div class="step-item active" id="step1">
          <div class="step-circle">1</div>
          <div class="step-label">Choose<br>Type</div>
        </div>
        <div class="step-connector" id="conn1"></div>
        <div class="step-item" id="step2">
          <div class="step-circle">2</div>
          <div class="step-label">Download<br>Template</div>
        </div>
        <div class="step-connector" id="conn2"></div>
        <div class="step-item" id="step3">
          <div class="step-circle">3</div>
          <div class="step-label">Upload<br>File</div>
        </div>
        <div class="step-connector" id="conn3"></div>
        <div class="step-item" id="step4">
          <div class="step-circle">4</div>
          <div class="step-label">Review &<br>Validate</div>
        </div>
        <div class="step-connector" id="conn4"></div>
        <div class="step-item" id="step5">
          <div class="step-circle">5</div>
          <div class="step-label">Import<br>Complete</div>
        </div>
      </div>
    </div>

    <!-- ── STEP 1: Product Type Toggle (same as add.php cat toggle) ── -->
    <div class="card-box mb-4">
      <div class="section-header">
        <i class="fas fa-tags me-2"></i>Step 1 — Select Product Type
      </div>
      <div class="p-4">
        <p class="text-muted small mb-3">Choose what type of products you are importing. This determines the required columns in your file.</p>
        <div class="cat-toggle">
          <div class="cat-btn med active" id="catMed" onclick="setImportType('medicine')">
            <i class="fas fa-pills fa-2x mb-2 d-block"></i>
            💊 Medicine
            <div class="small text-muted fw-normal mt-1">Generic Name, Dosage Form, Strength required</div>
          </div>
          <div class="cat-btn nonmed" id="catNonMed" onclick="setImportType('non_medicine')">
            <i class="fas fa-box fa-2x mb-2 d-block"></i>
            📦 Non-Medicine
            <div class="small text-muted fw-normal mt-1">Size/Variant optional, no Generic/Dosage needed</div>
          </div>
        </div>
        <input type="hidden" id="selectedImportType" value="medicine">

        <!-- Field requirements info -->
        <div id="medFieldInfo" class="note-box">
          <i class="fas fa-info-circle text-primary me-2"></i>
          <strong>Medicine columns:</strong>
          Product Name*, Brand Name*, Generic Name*, Dosage Form*, Strength, Unit Type*, Units Per Pack*, Barcode
        </div>
        <div id="nonMedFieldInfo" class="note-box" style="display:none;">
          <i class="fas fa-info-circle text-success me-2"></i>
          <strong>Non-Medicine columns:</strong>
          Product Name*, Brand Name*, Size/Variant, Unit Type*, Units Per Pack*, Barcode
        </div>
      </div>
    </div>

    <!-- ── STEP 2: Download Template ── -->
    <div class="card-box mb-4">
      <div class="section-header">
        <i class="fas fa-file-download me-2"></i>Step 2 — Download Template
      </div>
      <div class="p-4">
        <p class="text-muted small mb-3">Download the template file for your selected product type, fill in your data, then come back to upload.</p>
        <div class="row g-3">
          <!-- Medicine template -->
          <div class="col-md-6" id="medTemplateCard">
            <div class="template-card med-card">
              <div class="template-card-header-med d-flex align-items-center gap-3">
                <i class="fas fa-pills fa-2x"></i>
                <div>
                  <div class="fw-bold fs-6">💊 Medicine Template</div>
                  <div class="small opacity-75">Includes sample data for medicines</div>
                </div>
              </div>
              <div class="p-4 text-center bg-white">
                <p class="text-muted small mb-3">Download, fill product data row by row, then upload back.</p>
                <div class="d-flex justify-content-center gap-3">
                  <a href="../ajax/download_import_template.php?type=medicine&format=xlsx" class="btn btn-success">
                    <i class="fas fa-file-excel me-1"></i> Excel (.xlsx)
                  </a>
                  <a href="../ajax/download_import_template.php?type=medicine&format=csv" class="btn btn-outline-success">
                    <i class="fas fa-file-csv me-1"></i> CSV
                  </a>
                </div>
              </div>
            </div>
          </div>
          <!-- Non-Medicine template -->
          <div class="col-md-6" id="nonMedTemplateCard">
            <div class="template-card nmed-card">
              <div class="template-card-header-nmed d-flex align-items-center gap-3">
                <i class="fas fa-box fa-2x"></i>
                <div>
                  <div class="fw-bold fs-6">📦 Non-Medicine Template</div>
                  <div class="small opacity-75">Includes sample data for non-medicine items</div>
                </div>
              </div>
              <div class="p-4 text-center bg-white">
                <p class="text-muted small mb-3">Download, fill product data row by row, then upload back.</p>
                <div class="d-flex justify-content-center gap-3">
                  <a href="../ajax/download_import_template.php?type=non_medicine&format=xlsx" class="btn btn-success">
                    <i class="fas fa-file-excel me-1"></i> Excel (.xlsx)
                  </a>
                  <a href="../ajax/download_import_template.php?type=non_medicine&format=csv" class="btn btn-outline-success">
                    <i class="fas fa-file-csv me-1"></i> CSV
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── STEP 3: Upload File ── -->
    <div class="card-box mb-4">
      <div class="section-header">
        <i class="fas fa-cloud-upload-alt me-2"></i>Step 3 — Upload Your File
        <small class="ms-3 opacity-75">Accepts .xlsx, .xls, .csv &nbsp;|&nbsp; Max 5MB &nbsp;|&nbsp; Max 500 rows</small>
      </div>
      <div class="p-4">
        <div class="drop-zone" id="dropZone">
          <i class="fas fa-cloud-upload-alt drop-icon"></i>
          <p class="mb-1 fw-semibold fs-5">Drag & Drop your file here</p>
          <p class="text-muted small mb-3">or click to browse</p>
          <button type="button" class="btn btn-primary" id="browseBtn">
            <i class="fas fa-folder-open me-1"></i>Choose File
          </button>
          <input type="file" id="fileInput" accept=".xlsx,.xls,.csv" class="d-none">
        </div>

        <div class="mt-3" id="fileSelectedInfo" style="display:none;">
          <div class="alert alert-info d-flex align-items-center gap-3 mb-0">
            <i class="fas fa-file-alt fa-2x"></i>
            <div>
              <div class="fw-semibold" id="fileSelectedName"></div>
              <small class="text-muted" id="fileSelectedSize"></small>
            </div>
            <button type="button" class="btn btn-primary ms-auto" id="btnValidate">
              <i class="fas fa-search me-1"></i>Preview & Validate
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearFile" title="Remove file">
              <i class="fas fa-times"></i>
            </button>
          </div>
        </div>

        <div class="text-center mt-3" id="validating" style="display:none;">
          <div class="spinner-border text-primary" role="status"></div>
          <p class="mt-2 text-muted">Validating file…</p>
        </div>
      </div>
    </div>

    <!-- ── STEP 4: Preview & Validation ── -->
    <div class="card-box mb-4" id="sectionPreview" style="display:none;">
      <div class="section-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-table me-2"></i>Step 4 — Preview & Validation Results</span>
        <div id="summaryBadges"></div>
      </div>
      <div class="p-4">
        <div id="summaryBar" class="mb-3"></div>
        <div id="validationAlert" class="mb-3"></div>
        <div class="table-responsive">
          <table class="table table-sm table-hover table-bordered" id="previewTable">
            <thead class="table-dark">
              <tr>
                <th>#</th>
                <th>Product Name</th>
                <th>Brand</th>
                <th>Generic / Size</th>
                <th>Dosage Form</th>
                <th>Strength</th>
                <th>Code</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="previewBody"></tbody>
          </table>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-3">
          <button type="button" class="btn btn-outline-secondary" id="btnReupload">
            <i class="fas fa-redo me-1"></i>Re-upload Fixed File
          </button>
          <button type="button" class="btn btn-success" id="btnImport" style="display:none;">
            <i class="fas fa-check me-1"></i>Confirm & Import All
          </button>
          <button type="button" class="btn btn-outline-danger" id="btnDownloadErrors" style="display:none;">
            <i class="fas fa-download me-1"></i>Download Error Report
          </button>
        </div>
      </div>
    </div>

    <!-- ── STEP 5a: Progress ── -->
    <div class="card-box mb-4" id="sectionProgress" style="display:none;">
      <div class="section-header" style="background:linear-gradient(135deg,#232526,#414345);">
        <i class="fas fa-cogs me-2"></i>Importing Products…
      </div>
      <div class="p-4">
        <div class="progress mb-3" style="height:28px;">
          <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
               id="progressBar" role="progressbar" style="width:0%">0%</div>
        </div>
        <div id="importLog" class="mb-3"><span class="log-info">Preparing import…</span></div>
      </div>
    </div>

    <!-- ── STEP 5b: Complete ── -->
    <div class="card-box mb-4 border-success" id="sectionComplete" style="display:none;">
      <div class="section-header" style="background:linear-gradient(135deg,#198754,#28a745);">
        <i class="fas fa-check-circle me-2"></i>Import Complete!
      </div>
      <div class="p-4">
        <div class="row text-center g-3 mb-4" id="importSummaryCards"></div>
        <div class="d-flex gap-2 flex-wrap">
          <a href="list.php" class="btn btn-primary"><i class="fas fa-boxes me-1"></i>View Products List</a>
          <button type="button" class="btn btn-outline-info" id="btnViewLog">
            <i class="fas fa-history me-1"></i>View Import Log
          </button>
          <button type="button" class="btn btn-outline-success" id="btnDownloadReport">
            <i class="fas fa-file-excel me-1"></i>Download Report
          </button>
          <button type="button" class="btn btn-outline-secondary" id="btnImportAnother">
            <i class="fas fa-plus me-1"></i>Import Another File
          </button>
        </div>
      </div>
    </div>

  </div><!-- /tabImport -->

  <!-- ════════════════ TAB 2: HISTORY ════════════════ -->
  <div class="tab-pane fade" id="tabHistory">
    <div class="card-box">
      <div class="section-header">
        <i class="fas fa-history me-2"></i>Import History (Last 50 Imports)
      </div>
      <div class="p-0">
        <?php if (empty($importHistory)): ?>
        <div class="text-center text-muted py-5 p-4">
          <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
          <p class="mb-0">No import history yet. Import your first batch above.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover table-striped mb-0 history-table">
            <thead class="table-dark">
              <tr>
                <th>Date</th>
                <th>File</th>
                <th>Type</th>
                <th class="text-center">Total</th>
                <th class="text-center">Success</th>
                <th class="text-center">Failed</th>
                <th>Imported By</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($importHistory as $log): ?>
              <tr>
                <td class="text-nowrap"><?= htmlspecialchars(date('d M Y H:i', strtotime($log['created_at']))) ?></td>
                <td class="text-truncate" style="max-width:180px;" title="<?= htmlspecialchars($log['filename']) ?>">
                  <?= htmlspecialchars($log['filename']) ?>
                </td>
                <td>
                  <?= $log['import_type'] === 'medicine'
                        ? '<span class="badge bg-primary"><i class="fas fa-pills me-1"></i>Medicine</span>'
                        : '<span class="badge bg-success"><i class="fas fa-box me-1"></i>Non-Medicine</span>' ?>
                </td>
                <td class="text-center"><?= (int)$log['total_rows'] ?></td>
                <td class="text-center">
                  <span class="badge bg-success"><?= (int)$log['success_rows'] ?></span>
                </td>
                <td class="text-center">
                  <span class="badge <?= (int)($log['failed_rows'] ?? 0) > 0 ? 'bg-danger' : 'bg-secondary' ?>">
                    <?= (int)($log['failed_rows'] ?? 0) ?>
                  </span>
                </td>
                <td><?= htmlspecialchars($log['imported_by_name'] ?? '—') ?></td>
                <td>
                  <?php if (!empty($log['errors_json']) && $log['errors_json'] !== '[]'): ?>
                  <button class="btn btn-sm btn-outline-danger"
                          onclick="showImportErrors(<?= $log['id'] ?>, <?= htmlspecialchars($log['errors_json'], ENT_QUOTES) ?>)">
                    <i class="fas fa-exclamation-triangle me-1"></i>Errors
                  </button>
                  <?php else: ?>
                  <span class="text-success"><i class="fas fa-check me-1"></i>Clean</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  </div><!-- /tab-content -->

</div><!-- /container -->
</div><!-- /main-content -->

<!-- ── Error Details Modal ── -->
<div class="modal fade" id="errorModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header modal-header-danger">
        <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Import Errors</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="errorModalBody"></div>
    </div>
  </div>
</div>


<script>
'use strict';
let selectedFile = null, validationData = null, importResult = null;

/* ── Step helper ── */
function setStep(n) {
    for (let i = 1; i <= 5; i++) {
        const el   = document.getElementById('step' + i);
        const conn = document.getElementById('conn' + i);
        if (el) {
            el.classList.remove('active', 'done');
            if (i < n)      el.classList.add('done');
            else if (i === n) el.classList.add('active');
        }
        if (conn) conn.classList.toggle('done', i < n);
    }
}

/* ── Show / hide shortcuts ── */
const showEl = id => { const el = document.getElementById(id); if(el) el.style.display = ''; };
const hideEl = id => { const el = document.getElementById(id); if(el) el.style.display = 'none'; };

/* ── Category toggle (same logic as add.php) ── */
function setImportType(type) {
    document.getElementById('selectedImportType').value = type;
    const medBtn    = document.getElementById('catMed');
    const nonMedBtn = document.getElementById('catNonMed');
    medBtn.classList.toggle('active', type === 'medicine');
    nonMedBtn.classList.toggle('active', type === 'non_medicine');
    document.getElementById('medFieldInfo').style.display    = type === 'medicine'     ? '' : 'none';
    document.getElementById('nonMedFieldInfo').style.display = type === 'non_medicine' ? '' : 'none';
    // Highlight the relevant template card
    document.getElementById('medTemplateCard').style.opacity    = type === 'medicine'     ? '1' : '0.5';
    document.getElementById('nonMedTemplateCard').style.opacity = type === 'non_medicine' ? '1' : '0.5';
    setStep(2);
}
setImportType('medicine'); // default

/* ── File selection ── */
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');

document.getElementById('browseBtn').addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', () => { if (fileInput.files.length) handleFileSelected(fileInput.files[0]); });
dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault(); dropZone.classList.remove('drag-over');
    if (e.dataTransfer.files[0]) handleFileSelected(e.dataTransfer.files[0]);
});

function handleFileSelected(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['xlsx','xls','csv'].includes(ext)) { Notify.warning('Only .xlsx, .xls, .csv files are allowed.'); return; }
    if (file.size > 5 * 1024 * 1024) { Notify.info('File too large. Maximum 5MB.'); return; }
    selectedFile = file;
    document.getElementById('fileSelectedName').textContent = file.name;
    document.getElementById('fileSelectedSize').textContent = (file.size / 1024).toFixed(1) + ' KB';
    showEl('fileSelectedInfo'); hideEl('sectionPreview'); hideEl('sectionProgress'); hideEl('sectionComplete');
    validationData = null; setStep(3);
}

document.getElementById('btnClearFile').addEventListener('click', () => {
    selectedFile = null; fileInput.value = '';
    hideEl('fileSelectedInfo'); hideEl('sectionPreview'); hideEl('sectionProgress'); hideEl('sectionComplete');
    setStep(2);
});
document.getElementById('btnReupload').addEventListener('click', () => fileInput.click());

/* ── Validate ── */
document.getElementById('btnValidate').addEventListener('click', () => { if (selectedFile) doValidate(); });

function doValidate() {
    const importType = document.getElementById('selectedImportType').value;
    showEl('validating'); hideEl('sectionPreview'); setStep(4);
    const fd = new FormData();
    fd.append('import_file', selectedFile);
    fd.append('import_type', importType);
    fetch('../ajax/process_bulk_import.php?action=validate', { method:'POST', body:fd })
        .then(r => r.json())
        .then(resp => {
            hideEl('validating');
            if (!resp.success) { Notify.error('Validation Error: ' + resp.message); setStep(3); return; }
            validationData = resp;
            renderValidation(resp);
            showEl('sectionPreview');
            setStep(4);
            document.getElementById('sectionPreview').scrollIntoView({ behavior:'smooth', block:'start' });
        })
        .catch(err => { hideEl('validating'); Notify.error('Network error: ' + err.message); setStep(3); });
}

function renderValidation(resp) {
    const { total, valid, warnings, errors, rows, can_import } = resp;
    document.getElementById('summaryBadges').innerHTML =
        `<span class="badge bg-secondary me-1">Total: ${total}</span>
         <span class="badge bg-success me-1">✅ Valid: ${valid}</span>
         <span class="badge bg-warning text-dark me-1">⚠️ Warn: ${warnings}</span>
         <span class="badge bg-danger me-1">❌ Errors: ${errors}</span>`;
    document.getElementById('summaryBar').innerHTML =
        `<div class="alert ${errors>0?'alert-danger':warnings>0?'alert-warning':'alert-success'} mb-0 py-2">
           <strong>Total Rows: ${total}</strong> &nbsp;|&nbsp;
           ✅ Valid: ${valid} &nbsp;|&nbsp;
           ❌ Errors: ${errors} &nbsp;|&nbsp;
           ⚠️ Warnings: ${warnings}
         </div>`;
    document.getElementById('validationAlert').innerHTML = errors > 0
        ? `<div class="alert alert-danger"><i class="fas fa-ban me-2"></i><strong>Cannot import</strong> — Fix ${errors} error(s) in your file first.</div>`
        : (warnings > 0
            ? `<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i><strong>${warnings} warning(s)</strong> — Review and confirm to proceed.</div>`
            : `<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><strong>All ${total} rows valid</strong> — Ready to import!</div>`);

    const tbody = document.getElementById('previewBody');
    tbody.innerHTML = '';
    rows.forEach(r => {
        const d = r.data || {};
        const statusHtml = r.status === 'error'
            ? `<span class="badge bg-danger">❌ Error</span><div class="text-danger small mt-1">${r.errors.join('<br>')}</div>`
            : (r.status === 'warning'
                ? `<span class="badge bg-warning text-dark">⚠️ Warning</span><div class="text-warning small mt-1">${r.warnings.join('<br>')}</div>`
                : `<span class="badge bg-success">✅ Valid</span>`);
        const rowClass = r.status === 'error' ? 'table-danger' : (r.status === 'warning' ? 'table-warning' : '');
        tbody.innerHTML += `<tr class="${rowClass}">
            <td>${r.row_num}</td>
            <td><strong>${escHtml(d.product_name||'—')}</strong></td>
            <td>${escHtml(d.brand_name||'—')}</td>
            <td>${escHtml(d.generic_name||(d.size_value||'—'))}</td>
            <td>${escHtml(d.dosage_form||'—')}</td>
            <td>${escHtml(d.strength||'—')}</td>
            <td>${escHtml(d.product_code||'Auto')}</td>
            <td>${statusHtml}</td>
        </tr>`;
    });
    if (can_import) showEl('btnImport'); else hideEl('btnImport');
    if (errors > 0) showEl('btnDownloadErrors'); else hideEl('btnDownloadErrors');
}

/* ── Import ── */
document.getElementById('btnImport').addEventListener('click', () => {
    if (!validationData || !validationData.can_import) return;
    const total = validationData.total || validationData.valid;
    if (!confirm(`Ready to import ${total} products.\n\nThis will add them to your products list.\n\nProceed?`)) return;
    doImport();
});

function doImport() {
    const importType = document.getElementById('selectedImportType').value;
    hideEl('sectionPreview'); showEl('sectionProgress'); setStep(5);
    const log = document.getElementById('importLog');
    const bar = document.getElementById('progressBar');
    log.innerHTML = '<span class="log-info">⚙️ Starting import…\n</span>';
    bar.style.width = '10%'; bar.textContent = '10%';
    const fd = new FormData();
    fd.append('import_file', selectedFile);
    fd.append('import_type', importType);
    fetch('../ajax/process_bulk_import.php?action=import', { method:'POST', body:fd })
        .then(r => r.json())
        .then(resp => {
            importResult = resp;
            bar.style.width = '100%'; bar.textContent = '100%';
            bar.classList.remove('progress-bar-animated');
            if (!resp.success) {
                bar.classList.add('bg-danger');
                log.innerHTML += `<span class="log-fail">❌ IMPORT FAILED: ${escHtml(resp.message)}\n</span>`;
                return;
            }
            if (resp.rows && resp.rows.length) {
                resp.rows.forEach(r => {
                    const icon = r.status === 'success' ? '✅' : '❌';
                    const cls  = r.status === 'success' ? 'log-ok' : 'log-fail';
                    log.innerHTML += `<span class="${cls}">${icon} Row ${r.row_num}: ${escHtml(r.product_name)} — ${escHtml(r.message)}\n</span>`;
                    if (r.warnings && r.warnings.length)
                        r.warnings.forEach(w => log.innerHTML += `<span class="log-warn">    ⚠️ ${escHtml(w)}\n</span>`);
                    log.scrollTop = log.scrollHeight;
                });
            }
            log.innerHTML += `\n<span class="log-info">━━━ Import Complete ━━━\n</span>`;
            log.scrollTop = log.scrollHeight;
            renderImportSummary(resp);
            showEl('sectionComplete');
            document.getElementById('sectionComplete').scrollIntoView({ behavior:'smooth' });
        })
        .catch(err => {
            bar.classList.add('bg-danger');
            log.innerHTML += `<span class="log-fail">❌ Network Error: ${escHtml(err.message)}\n</span>`;
        });
}

function renderImportSummary(resp) {
    document.getElementById('importSummaryCards').innerHTML = `
        <div class="col-6 col-md-2"><div class="card bg-success text-white text-center py-3"><div class="fs-1 fw-bold">${resp.imported ?? 0}</div><div class="small">✅ Imported</div></div></div>
        <div class="col-6 col-md-2"><div class="card bg-primary text-white text-center py-3"><div class="fs-1 fw-bold">${resp.products_created ?? 0}</div><div class="small">📦 New Products</div></div></div>
        <div class="col-6 col-md-2"><div class="card bg-info text-white text-center py-3"><div class="fs-1 fw-bold">${resp.batches_added ?? 0}</div><div class="small">📋 Batches</div></div></div>
        <div class="col-6 col-md-2"><div class="card bg-warning text-dark text-center py-3"><div class="fs-1 fw-bold">${resp.warnings ?? 0}</div><div class="small">⚠️ Warnings</div></div></div>
        <div class="col-6 col-md-2"><div class="card bg-danger text-white text-center py-3"><div class="fs-1 fw-bold">${resp.failed ?? 0}</div><div class="small">❌ Failed</div></div></div>
    `;
}

/* ── Download error report ── */
document.getElementById('btnDownloadErrors').addEventListener('click', () => {
    if (!validationData) return;
    const rows = validationData.rows.filter(r => r.status === 'error');
    let csv = 'Row#,Product Name,Errors\n';
    rows.forEach(r => {
        const name = (r.data && r.data.product_name) ? r.data.product_name : '';
        csv += `${r.row_num},"${name.replace(/"/g,'""')}","${r.errors.join(' | ').replace(/"/g,'""')}"\n`;
    });
    downloadText(csv, 'import_errors.csv', 'text/csv');
});

document.getElementById('btnDownloadReport').addEventListener('click', () => {
    if (!importResult || !importResult.rows) return;
    let csv = 'Row#,Product Name,Status,Message\n';
    importResult.rows.forEach(r => {
        csv += `${r.row_num},"${(r.product_name||'').replace(/"/g,'""')}","${r.status}","${(r.message||'').replace(/"/g,'""')}"\n`;
    });
    downloadText(csv, 'import_report.csv', 'text/csv');
});

document.getElementById('btnImportAnother').addEventListener('click', () => {
    selectedFile = null; validationData = null; importResult = null;
    fileInput.value = '';
    hideEl('fileSelectedInfo'); hideEl('sectionPreview'); hideEl('sectionProgress'); hideEl('sectionComplete');
    const bar = document.getElementById('progressBar');
    bar.style.width = '0%'; bar.textContent = '0%';
    bar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-success';
    document.getElementById('importLog').innerHTML = '';
    setStep(1); window.scrollTo({ top: 0, behavior:'smooth' });
});

document.getElementById('btnViewLog').addEventListener('click', () => {
    document.querySelector('[data-bs-target="#tabHistory"]').click();
    setTimeout(() => location.reload(), 100);
});

/* ── Import history error modal ── */
function showImportErrors(logId, errorsJson) {
    let errors = [];
    try { errors = typeof errorsJson === 'string' ? JSON.parse(errorsJson) : errorsJson; } catch(e) {}
    let html = '<p class="text-muted">No error details available.</p>';
    if (errors && errors.length) {
        html = '<table class="table table-sm table-bordered"><thead><tr><th>Row</th><th>Field</th><th>Error</th></tr></thead><tbody>';
        errors.forEach(e => {
            html += `<tr><td>${e.row||'—'}</td><td>${escHtml(e.field||'—')}</td><td>${escHtml(e.error||'')}</td></tr>`;
        });
        html += '</tbody></table>';
    }
    document.getElementById('errorModalBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('errorModal')).show();
}

/* ── Utilities ── */
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function downloadText(content, filename, mimeType) {
    const blob = new Blob(['\uFEFF' + content], { type: mimeType });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = filename; a.click();
    URL.revokeObjectURL(url);
}

setStep(1);
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
