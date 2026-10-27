<?php
/**
 * products/add.php — Add New Product
 * Step 8 UI/UX Sync - Updated for database structure
 */

require_once '../config/config.php';
require_once '../includes/product_functions.php';

// Start session if not started (redundant but safe)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requirePharmacist();

$page_title = 'Add New Product — Pharmacy Management System';
$errors  = [];
$success = '';
$oldData = [];

// Enable error logging for debugging
error_log("=== add.php loaded ===");
error_log("Session ID: " . session_id());

// Pre-fetch dosage forms for datalist
$dosageForms = getAllDosageForms();
$defaultForms = ['Tablet','Syrup','Capsule','Injection','Cream','Ointment','Drops','Gel','Inhaler','Suppository','Patch','Powder','Suspension','Solution','Lotion','Granules'];

// ── POST HANDLER ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    error_log("=== POST request received for product save ===");
    error_log("POST data: " . print_r($_POST, true));
    error_log("Session CSRF token: " . ($_SESSION['csrf_token'] ?? 'NOT SET'));
    error_log("POST CSRF token: " . ($_POST['csrf_token'] ?? 'NOT SET'));
    
    // Verify CSRF token
    $submittedToken = $_POST['csrf_token'] ?? '';
    $storedToken = $_SESSION['csrf_token'] ?? '';
    
    if (empty($storedToken) || !hash_equals($storedToken, $submittedToken)) {
        $errors[] = 'Security token mismatch. Please refresh and try again.';
        error_log("CSRF token mismatch - Stored: $storedToken, Submitted: $submittedToken");
        
        // Generate a new token for the next attempt
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        error_log("Generated new CSRF token after mismatch");
    } else {
        error_log("CSRF token verified successfully");
        
        $oldData = $_POST;
        $cat     = (int)($_POST['category_id'] ?? 1);

        $productName = trim($_POST['product_name'] ?? '');
        $brandName   = trim($_POST['brand_name']   ?? '');
        $unitType    = trim($_POST['unit_type']     ?? '');
        $unitsPerPack= (int)($_POST['units_per_pack'] ?? 0);

        if (empty($productName)) $errors[] = 'Product name is required.';
        if (empty($brandName))   $errors[] = 'Brand name is required.';
        if (empty($unitType))    $errors[] = 'Unit type is required.';
        if ($unitsPerPack < 1)   $errors[] = 'Units per pack must be at least 1.';

        if ($cat === 1) {
            if (empty(trim($_POST['generic_name'] ?? ''))) $errors[] = 'Generic name is required for medicines.';
            if (empty(trim($_POST['dosage_form']  ?? ''))) $errors[] = 'Dosage form is required for medicines.';
        }

        // Handle image upload
        $imagePath = null;
        if (!empty($_FILES['product_image']['name'])) {
            $allowed   = ['image/jpeg','image/png','image/gif','image/webp'];
            $maxSize   = 2 * 1024 * 1024;
            $mime      = mime_content_type($_FILES['product_image']['tmp_name']);
            $size      = $_FILES['product_image']['size'];
            if (!in_array($mime, $allowed, true)) {
                $errors[] = 'Image must be JPG, PNG, GIF, or WebP.';
            } elseif ($size > $maxSize) {
                $errors[] = 'Image must be smaller than 2MB.';
            } else {
                $ext      = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
                $filename = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
                $dest     = dirname(__DIR__) . '/uploads/products/' . $filename;
                if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
                if (move_uploaded_file($_FILES['product_image']['tmp_name'], $dest)) {
                    $imagePath = 'uploads/products/' . $filename;
                } else {
                    $errors[] = 'Failed to upload image.';
                    error_log("Image upload failed - temp: " . $_FILES['product_image']['tmp_name'] . ", dest: $dest");
                }
            }
        }

        if (empty($errors)) {
            $data = [
                'category_id'    => $cat,
                'product_name'   => $productName,
                'brand_name'     => $brandName,
                'unit_type'      => $unitType,
                'units_per_pack' => $unitsPerPack,
                'barcode'        => trim($_POST['barcode'] ?? '') ?: null,
                'image_path'     => $imagePath,
            ];
            if ($cat === 1) {
                $data['generic_name'] = trim($_POST['generic_name'] ?? '');
                $data['dosage_form']  = trim($_POST['dosage_form']  ?? '');
                $data['strength']     = trim($_POST['strength']     ?? '') ?: null;
            } else {
                $data['size_value'] = trim($_POST['size_value'] ?? '') ?: null;
            }

            error_log("Calling saveProduct with data: " . print_r($data, true));
            
            $newId = saveProduct($data);
            
            error_log("saveProduct returned ID: " . ($newId ? $newId : '0/false'));
            
            if ($newId && $newId > 0) {
                // Clear the CSRF token after successful submission to prevent double submission
                unset($_SESSION['csrf_token']);
                error_log("CSRF token cleared after successful save");
                
                $_SESSION['flash_success'] = 'Product added successfully! <a href="view.php?id=' . $newId . '" class="alert-link">View Product</a> or <a href="../purchases/add.php?product_id=' . $newId . '&product_name=' . urlencode($productName) . '" class="alert-link">Add Purchase</a>.';
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Product added successfully!']);
                    exit;
                }
                header('Location: list.php');
                exit;
            } else {
                $errors[] = 'Failed to save product. Please check the error log for details.';
                error_log("saveProduct failed - returning 0 or false");
                
                // Regenerate token for retry
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                error_log("Regenerated CSRF token for retry");
            }
        }
    }

    if ($isAjax && !empty($errors)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }
}

$cat   = (int)($_POST['category_id'] ?? $oldData['category_id'] ?? 1);
// Generate a fresh CSRF token for the form
$csrf  = generateCSRFToken();
error_log("Generated CSRF token for form: " . substr($csrf, 0, 20) . "...");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>


<style>
/* ══════════════════════════════════════════════════════════════
   products/add.php — Design System
   ══════════════════════════════════════════════════════════════ */

@keyframes fadeInPage {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: translateY(0); }
}
.main-content {
  margin-left: var(--sb-w, 260px); transition: margin-left .25s ease;
  padding: 24px;
  animation: fadeInPage 0.28s ease;
}
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
@media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

body { font-size: 14px; background: var(--pharmacy-body-bg, #f0f2f5); }

.page-header-title {
  color: var(--pharmacy-heading, #212529) !important;
  font-weight: 700;
}
.page-header-sub {
  font-size: 0.82rem;
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 65%, transparent);
}

.form-card {
  background: var(--pharmacy-card-bg, #fff);
  border: 1px solid var(--pharmacy-border, #dee2e6);
  border-radius: var(--card-border-radius, 12px);
  box-shadow: 0 2px 12px rgba(0,0,0,0.06);
  margin-bottom: 20px;
  transition: box-shadow 0.2s ease;
  overflow: hidden;
}
.form-card:hover {
  box-shadow: 0 4px 20px rgba(0,0,0,0.09);
}
.form-card-header {
  background: var(--pharmacy-table-th, #f8f9fa);
  border-bottom: 2px solid var(--pharmacy-border, #dee2e6);
  padding: 13px 20px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.form-card-header .card-icon {
  width: 32px; height: 32px; border-radius: 8px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 14px; flex-shrink: 0;
}
.form-card-header h6 {
  margin: 0;
  font-weight: 700;
  font-size: 0.9rem;
  color: var(--pharmacy-heading, #212529);
}
.form-card-header .card-subtitle {
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 60%, transparent);
  margin-left: auto;
}
.form-card-body { padding: 20px; }

.form-label {
  color: var(--pharmacy-heading, #343a40) !important;
  font-weight: 600;
  font-size: 0.83rem;
  margin-bottom: 5px;
}
.form-label .label-hint {
  font-weight: 400;
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 55%, transparent);
  font-size: 0.76rem;
}

.form-control, .form-select {
  color: var(--pharmacy-text, #343a40) !important;
  background: var(--pharmacy-card-bg, #fff) !important;
  border-color: var(--pharmacy-border, #dee2e6) !important;
  transition: border-color 0.18s ease, box-shadow 0.18s ease;
}
.form-control:focus, .form-select:focus {
  border-color: var(--pharmacy-primary, #0d6efd) !important;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 15%, transparent) !important;
}

.cat-toggle { display: flex; gap: 12px; margin-bottom: 20px; }
.cat-btn {
  flex: 1; padding: 18px; border-radius: 12px;
  border: 2px solid var(--pharmacy-border, #dee2e6);
  background: var(--pharmacy-card-bg, #fff);
  cursor: pointer; text-align: center;
  transition: all 0.2s ease; font-size: 15px; font-weight: 600;
  color: var(--pharmacy-text, #343a40);
}
.cat-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(0,0,0,0.1);
  border-color: var(--pharmacy-primary, #0d6efd);
}
.cat-btn.active.med {
  border-color: #0d6efd;
  background: color-mix(in srgb, #0d6efd 10%, var(--pharmacy-card-bg, #fff));
  color: #0d6efd;
}
.cat-btn.active.nonmed {
  border-color: #20c997;
  background: color-mix(in srgb, #20c997 10%, var(--pharmacy-card-bg, #fff));
  color: #20c997;
}
.cat-btn .small.text-muted { color: color-mix(in srgb, var(--pharmacy-text, #343a40) 60%, transparent) !important; }

.preview-card {
  background: color-mix(in srgb, var(--pharmacy-table-th, #f8f9fa) 80%, var(--pharmacy-card-bg, #fff));
  border: 1px solid var(--pharmacy-border, #dee2e6);
  border-radius: 12px; padding: 16px;
}
.preview-name { font-weight: 700; font-size: 15px; color: var(--pharmacy-heading, #212529); }
.preview-generic { font-style: italic; color: color-mix(in srgb, var(--pharmacy-text, #343a40) 60%, transparent); font-size: 13px; }
.preview-stock {
  background: color-mix(in srgb, #198754 12%, var(--pharmacy-card-bg, #fff));
  border: 1px solid color-mix(in srgb, #198754 30%, transparent);
  border-radius: 8px; padding: 8px 12px;
  font-size: 13px; font-weight: 600;
  color: #0a5c36;
}

.note-box {
  background: color-mix(in srgb, #ffc107 12%, var(--pharmacy-card-bg, #fff));
  border: 1px solid color-mix(in srgb, #ffc107 50%, transparent);
  border-radius: 8px; padding: 10px 14px; font-size: 12.5px;
  color: var(--pharmacy-text, #343a40);
}

.btn-bulk-import {
  background: linear-gradient(135deg, #11998e, #38ef7d);
  color: #fff; border: none; border-radius: 10px; padding: 9px 20px;
  transition: all 0.2s ease;
}
.btn-bulk-import:hover {
  filter: brightness(1.08); color: #fff;
  transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.15);
}

.btn-submit-primary {
  background: var(--pharmacy-primary, #0d6efd);
  color: #fff; border: none; border-radius: 10px;
  padding: 10px 28px; font-weight: 600;
  transition: all 0.2s ease;
}
.btn-submit-primary:hover:not(:disabled) {
  filter: brightness(1.08); color: #fff;
  transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.18);
}
.btn-submit-primary:disabled { opacity: 0.75; cursor: not-allowed; }

.btn { transition: all 0.18s ease; }
.alert { transition: opacity 0.4s ease; }

.unit-modal-header {
  background: linear-gradient(135deg, var(--pharmacy-primary, #667eea), var(--pharmacy-accent, #764ba2));
  color: #fff; border-radius: 16px 16px 0 0;
}

.preview-table td:first-child {
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 60%, transparent);
  font-size: 12px;
}
.preview-table td:last-child {
  font-weight: 600; color: var(--pharmacy-heading, #212529);
}

#imgPreviewThumb {
  border: 2px solid var(--pharmacy-border, #dee2e6);
  border-radius: 8px; max-height: 120px;
}

.input-group .btn { transition: all 0.18s ease; }

.debug-info {
  font-size: 11px;
  background: #f8f9fa;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  padding: 8px 12px;
  margin-top: 15px;
  color: #6c757d;
}
.debug-info code {
  font-size: 10px;
  background: #e9ecef;
  padding: 2px 4px;
  border-radius: 3px;
}

/* Force no-cache headers for the form page */
.no-cache {
  margin: 0;
  padding: 0;
}
</style>
</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">
<?php include '../includes/sidebar.php'; ?>
<?php if (file_exists('../includes/navbar.php')) include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid" style="max-width:1100px;">

  <!-- Page Header -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="mb-1 page-header-title">
        <i class="fas fa-plus-circle me-2" style="color:var(--pharmacy-primary,#198754)"></i>Add New Product
      </h4>
      <p class="page-header-sub mb-0">Fill in product details. Pricing &amp; reorder level are set when adding stock.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="bulk_import.php" class="btn btn-bulk-import">
        <i class="fas fa-file-import me-2"></i>Bulk Import
      </a>
      <a href="list.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Back to List
      </a>
    </div>
  </div>

  <!-- Error Alerts -->
  <?php if (!empty($errors)): ?>
  <div class="alert alert-danger alert-dismissible fade show" id="errorAlert">
    <strong><i class="fas fa-exclamation-triangle me-2"></i>Please fix:</strong>
    <ul class="mb-0 mt-1">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- Success Flash Message -->
  <?php if (isset($_SESSION['flash_success'])): ?>
  <div class="alert alert-success alert-dismissible fade show">
    <?= $_SESSION['flash_success'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>

  <div class="row g-4">

    <!-- MAIN FORM -->
    <div class="col-lg-8">
      <div id="addProductAlert" class="mb-3"></div>
      <form method="POST" enctype="multipart/form-data" id="addProductForm" autocomplete="off"
            data-pharm-form
            data-success-msg="Product added successfully!"
            data-redirect="list.php"
            data-redirect-delay="1500"
            data-loading-text="Saving Product…"
            data-alert-target="#addProductAlert"
            novalidate>
        <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="save_product" value="1">
        <input type="hidden" name="category_id"  id="category_id" value="<?= $cat ?>">

        <!-- CARD 1: Basic Information -->
        <div class="form-card">
          <div class="form-card-header">
            <span class="card-icon" style="background:color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 15%,transparent)">
              <i class="fas fa-tag" style="color:var(--pharmacy-primary,#0d6efd)"></i>
            </span>
            <h6>Basic Information</h6>
            <span class="card-subtitle">Name, brand &amp; category</span>
          </div>
          <div class="form-card-body">

            <!-- Category Toggle -->
            <div class="cat-toggle">
              <div class="cat-btn med <?= $cat===1?'active':'' ?>" onclick="setCategory(1)">
                <i class="fas fa-pills fa-2x mb-2 d-block"></i>
                💊 Medicine
                <div class="small text-muted fw-normal mt-1">Tablet, Syrup, Capsule…</div>
              </div>
              <div class="cat-btn nonmed <?= $cat===2?'active':'' ?>" onclick="setCategory(2)">
                <i class="fas fa-box fa-2x mb-2 d-block"></i>
                📦 Non-Medicine
                <div class="small text-muted fw-normal mt-1">Surgical, Cosmetic, Equipment…</div>
              </div>
            </div>

            <!-- Common fields -->
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label">Product Name <span class="text-danger">*</span></label>
                <input type="text" name="product_name" id="f_product_name" class="form-control"
                       placeholder="e.g. Panadol"
                       value="<?= htmlspecialchars($oldData['product_name'] ?? '') ?>"
                       oninput="updatePreview()" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Brand Name <span class="text-danger">*</span></label>
                <input type="text" name="brand_name" id="f_brand_name" class="form-control"
                       placeholder="e.g. GSK"
                       value="<?= htmlspecialchars($oldData['brand_name'] ?? '') ?>"
                       oninput="updatePreview()" required>
              </div>
            </div>

            <!-- Medicine-only fields -->
            <div id="medFields">
              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label">Generic Name <span class="text-danger">*</span></label>
                  <input type="text" name="generic_name" id="f_generic_name" class="form-control"
                         placeholder="e.g. Paracetamol"
                         value="<?= htmlspecialchars($oldData['generic_name'] ?? '') ?>"
                         oninput="updatePreview()">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Strength <span class="label-hint">(optional)</span></label>
                  <input type="text" name="strength" id="f_strength" class="form-control"
                         placeholder="e.g. 250mg or 125ml/5ml"
                         value="<?= htmlspecialchars($oldData['strength'] ?? '') ?>"
                         oninput="updatePreview()">
                </div>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Dosage Form <span class="text-danger">*</span></label>
                <input type="text" name="dosage_form" id="f_dosage_form" class="form-control"
                       list="dosageFormList" placeholder="e.g. Tablet"
                       value="<?= htmlspecialchars($oldData['dosage_form'] ?? '') ?>"
                       oninput="updatePreview()">
                <datalist id="dosageFormList">
                  <?php foreach ($defaultForms as $df): ?>
                  <option value="<?= htmlspecialchars($df) ?>">
                  <?php endforeach; ?>
                  <?php foreach ($dosageForms as $df): if (!in_array($df, $defaultForms, true)): ?>
                  <option value="<?= htmlspecialchars($df) ?>">
                  <?php endif; endforeach; ?>
                </datalist>
              </div>
            </div>

            <!-- Non-Medicine-only fields -->
            <div id="nonMedFields" style="display:none">
              <div class="col-md-6 mb-3">
                <label class="form-label">Size / Variant <span class="label-hint">(optional)</span></label>
                <input type="text" name="size_value" id="f_size_value" class="form-control"
                       placeholder="e.g. Small, 250ml, 1CC, 100g"
                       value="<?= htmlspecialchars($oldData['size_value'] ?? '') ?>"
                       oninput="updatePreview()">
              </div>
            </div>

          </div>
        </div>

        <!-- CARD 2: Packing & Barcode -->
        <div class="form-card">
          <div class="form-card-header">
            <span class="card-icon" style="background:color-mix(in srgb,#198754 15%,transparent)">
              <i class="fas fa-boxes" style="color:#198754"></i>
            </span>
            <h6>Packing &amp; Barcode</h6>
            <span class="card-subtitle">Unit type, quantity per pack</span>
          </div>
          <div class="form-card-body">

            <div class="row g-3 mb-3">
              <div class="col-md-4">
                <label class="form-label">Unit Type <span class="text-danger">*</span></label>
                <div class="input-group">
                  <select name="unit_type" id="f_unit_type" class="form-select" onchange="onUnitTypeChange(this)">
                    <option value="">-- Select --</option>
                    <?php
                    $medUnits    = ['Strip','Bottle','Box','Tube','Sachet','Vial','Ampoule','Pcs'];
                    $nonMedUnits = ['Pcs','Box','Bottle','Tube','Sachet','Strip','Bag','Roll'];
                    $allUnits    = array_unique(array_merge($medUnits, $nonMedUnits));
                    $selUnit     = $oldData['unit_type'] ?? '';
                    foreach ($allUnits as $u): ?>
                    <option value="<?= $u ?>" <?= $selUnit===$u?'selected':'' ?>><?= $u ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="btn btn-outline-primary btn-sm px-2"
                          onclick="openAddUnitModal()" title="Add custom unit type"
                          data-bs-toggle="tooltip">
                    <i class="fas fa-plus"></i>
                  </button>
                </div>
              </div>
              <div class="col-md-4">
                <label class="form-label">
                  Units Per Pack <span class="text-danger">*</span>
                  <i class="fas fa-info-circle ms-1" data-bs-toggle="tooltip"
                     title="How many individual pieces in 1 pack/strip/bottle (e.g. 10 tablets per strip)"></i>
                </label>
                <input type="number" name="units_per_pack" id="f_units_per_pack" class="form-control"
                       min="1" value="<?= (int)($oldData['units_per_pack'] ?? 1) ?>"
                       oninput="updatePreview()">
              </div>
              <div class="col-md-4">
                <label class="form-label">Barcode <span class="label-hint">(optional)</span></label>
                <input type="text" name="barcode" class="form-control"
                       placeholder="Scan or type barcode"
                       value="<?= htmlspecialchars($oldData['barcode'] ?? '') ?>">
              </div>
            </div>

            <div class="note-box">
              <i class="fas fa-info-circle text-warning me-2"></i>
              <strong>Note:</strong> Pricing and reorder level are set <strong>per batch</strong> when adding a purchase.
              <a href="../purchases/add.php" class="fw-semibold ms-1">Go to Add Purchase →</a>
            </div>

          </div>
        </div>

        <!-- CARD 3: Product Image -->
        <div class="form-card">
          <div class="form-card-header">
            <span class="card-icon" style="background:color-mix(in srgb,#6f42c1 15%,transparent)">
              <i class="fas fa-image" style="color:#6f42c1"></i>
            </span>
            <h6>Product Image</h6>
            <span class="card-subtitle">Optional — max 2MB</span>
          </div>
          <div class="form-card-body">
            <input type="file" name="product_image" class="form-control" accept="image/*"
                   onchange="previewImage(this)">
            <img id="imgPreviewThumb" src="" class="mt-2 d-none" alt="Preview">
          </div>
        </div>

        <!-- Submit Actions -->
        <div class="d-flex gap-2 flex-wrap">
          <button type="submit" data-submit-btn class="btn btn-submit-primary" id="submitBtn"
                  data-requires-perm="products.add"
                  data-loading="Saving…">
            <i class="fas fa-save me-2"></i>Save Product
          </button>
          <button type="button" class="btn btn-outline-danger" onclick="clearAllFields()">
            <i class="fas fa-eraser me-2"></i>Clear Fields
          </button>
          <a href="list.php" class="btn btn-outline-secondary">Cancel</a>
        </div>

      </form>
      
    </div>

    <!-- PREVIEW PANEL -->
    <div class="col-lg-4">
      <div class="form-card" style="position:sticky;top:80px;">
        <div class="form-card-header">
          <span class="card-icon" style="background:color-mix(in srgb,var(--pharmacy-text,#343a40) 10%,transparent)">
            <i class="fas fa-eye" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)"></i>
          </span>
          <h6>Live Preview</h6>
        </div>
        <div class="form-card-body">
          <div class="preview-card">
            <div class="d-flex gap-3 mb-3">
              <div style="width:48px;height:48px;border-radius:8px;background:color-mix(in srgb,var(--pharmacy-border,#dee2e6) 60%,var(--pharmacy-card-bg,#fff));display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-image" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 35%,transparent)"></i>
              </div>
              <div>
                <div class="preview-name" id="prev_name">Product Name</div>
                <div class="preview-generic" id="prev_generic"></div>
                <div style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent);font-size:12px" id="prev_brand"></div>
                <div style="font-size:11px">
                  <span class="badge bg-light text-dark border" id="prev_code">CODE</span>
                </div>
              </div>
            </div>
            <hr style="border-color:var(--pharmacy-border,#dee2e6)">
            <table class="table table-sm table-borderless mb-0 small preview-table">
              <tr><td>Form/Size</th><td id="prev_form">—</td></tr>
              <tr><td>Units/Pack</th><td id="prev_units">—</td></tr>
              <tr><td>Reorder Level</th><td style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 50%,transparent);font-style:italic">Set at purchase</td></tr>
              <tr><td>Price</th><td style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 50%,transparent);font-style:italic">Set at purchase</td></tr>
            </table>
            <div class="preview-stock mt-3">
              <i class="fas fa-info-circle me-1"></i>Stock added via Purchase module
            </div>
          </div>
          <hr style="border-color:var(--pharmacy-border,#dee2e6)">
          <div class="small" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 60%,transparent)">
            <i class="fas fa-lightbulb text-warning me-2"></i>
            <strong style="color:var(--pharmacy-heading,#212529)">After saving:</strong> Go to
            <a href="../purchases/add.php" style="color:var(--pharmacy-primary,#0d6efd)">Add Purchase</a>
            to add stock with batch-level pricing and reorder level.
          </div>
        </div>
      </div>
    </div>

  </div>

</div>
</div>

<!-- Custom Unit Type Modal -->
<div class="modal fade" id="addUnitModal" tabindex="-1" aria-labelledby="addUnitModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header unit-modal-header py-3">
        <h5 class="modal-title" id="addUnitModalLabel">
          <i class="fas fa-plus-circle me-2"></i>Add Custom Unit Type
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4" style="background:var(--pharmacy-card-bg,#fff)">
        <div class="row g-3">
          <!-- LEFT: Config -->
          <div class="col-md-6">
            <h6 class="fw-bold mb-3" style="color:var(--pharmacy-primary,#0d6efd)">
              <i class="fas fa-edit me-1"></i>Configure Unit
            </h6>
            <div class="mb-3">
              <label class="form-label">Unit Name <span class="text-danger">*</span>
                <small class="label-hint ms-1">e.g. Strip, Bottle, Blister</small>
              </label>
              <input type="text" id="cu_unit_name" class="form-control" placeholder="e.g. Blister"
                     oninput="updateUnitPreview()">
            </div>
            <div class="mb-3">
              <label class="form-label">Item Label <span class="text-danger">*</span>
                <small class="label-hint ms-1">what's inside each unit</small>
              </label>
              <input type="text" id="cu_item_label" class="form-control" placeholder="e.g. Capsule"
                     oninput="updateUnitPreview()">
            </div>
            <div class="mb-3">
              <label class="form-label">Item Plural <span class="label-hint">(optional — auto-adds 's' if blank)</span></label>
              <input type="text" id="cu_item_plural" class="form-control" placeholder="e.g. Capsules"
                     oninput="updateUnitPreview()">
            </div>
            <div class="mb-3">
              <label class="form-label">Test Quantity (Units/Pack)</label>
              <input type="number" id="cu_test_count" class="form-control" value="10" min="1"
                     oninput="updateUnitPreview()">
            </div>
          </div>
          <!-- RIGHT: Preview -->
          <div class="col-md-6">
            <h6 class="fw-bold mb-3 text-success"><i class="fas fa-eye me-1"></i>Live Preview</h6>
            <p class="mb-2" style="font-size:12px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 60%,transparent)">
              How it will appear in the products list:
            </p>
            <!-- FIX: All IDs used in JS actually exist here; no missing element crash -->
            <div class="border rounded p-3 mb-3" style="background:var(--pharmacy-card-bg,#fff)">
              <div class="d-flex justify-content-between align-items-center mb-2 pb-2" style="border-bottom:1px solid var(--pharmacy-border,#dee2e6)">
                <span style="font-size:12px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 60%,transparent)">Units/Pack</span>
                <span id="cu_preview_units" class="fw-bold">—</span>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <span style="font-size:12px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 60%,transparent)">Unit Type</span>
                <span id="cu_preview_type" class="fw-bold" style="color:var(--pharmacy-primary,#0d6efd)">—</span>
              </div>
            </div>
            <div>
              <h6 class="fw-semibold mb-2" style="font-size:12px">Your Custom Units</h6>
              <div id="customUnitsList" class="border rounded p-2"
                   style="max-height:130px;overflow-y:auto;font-size:12px;background:var(--pharmacy-table-th,#f8f9fa)">
                <span style="font-style:italic;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 50%,transparent)">
                  No custom units yet
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="background:var(--pharmacy-table-th,#f8f9fa);border-radius:0 0 16px 16px">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" onclick="saveCustomUnit()">
          <i class="fas fa-save me-2"></i>Add Unit Type
        </button>
      </div>
    </div>
  </div>
</div>


<script>
// Prevent page caching to avoid CSRF token issues
window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        // Page was loaded from cache, reload to get fresh CSRF token
        window.location.reload();
    }
});

// localStorage persistence
const LS_KEY = 'addProduct_draft';

const FIELDS = [
    { id: 'category_id',      type: 'value'  },
    { id: 'f_product_name',   type: 'value'  },
    { id: 'f_brand_name',     type: 'value'  },
    { id: 'f_generic_name',   type: 'value'  },
    { id: 'f_strength',       type: 'value'  },
    { id: 'f_dosage_form',    type: 'value'  },
    { id: 'f_size_value',     type: 'value'  },
    { id: 'f_unit_type',      type: 'value'  },
    { id: 'f_units_per_pack', type: 'value'  },
];

function saveDraft() {
    const draft = {};
    FIELDS.forEach(f => {
        const el = document.getElementById(f.id);
        if (el) draft[f.id] = el.value;
    });
    const bc = document.querySelector('[name="barcode"]');
    if (bc) draft['barcode'] = bc.value;
    try { localStorage.setItem(LS_KEY, JSON.stringify(draft)); } catch(e) {}
}

function loadDraft() {
    <?php if (!empty($oldData)): ?>
    return;
    <?php endif; ?>
    let draft;
    try { draft = JSON.parse(localStorage.getItem(LS_KEY) || 'null'); } catch(e) { return; }
    if (!draft) return;
    FIELDS.forEach(f => {
        const el = document.getElementById(f.id);
        if (el && draft[f.id] !== undefined) el.value = draft[f.id];
    });
    const bc = document.querySelector('[name="barcode"]');
    if (bc && draft['barcode'] !== undefined) bc.value = draft['barcode'];
    const cat = parseInt(draft['category_id'] || '1');
    setCategory(cat);
}

function clearAllFields() {
    if (!confirm('Clear all fields?')) return;
    try { localStorage.removeItem(LS_KEY); } catch(e) {}
    document.getElementById('addProductForm').reset();
    setCategory(1);
    const thumb = document.getElementById('imgPreviewThumb');
    if (thumb) { thumb.src = ''; thumb.classList.add('d-none'); }
    updatePreview();
}

function attachDraftListeners() {
    FIELDS.forEach(f => {
        const el = document.getElementById(f.id);
        if (el) el.addEventListener('input', saveDraft);
        if (el) el.addEventListener('change', saveDraft);
    });
    const bc = document.querySelector('[name="barcode"]');
    if (bc) bc.addEventListener('input', saveDraft);
}

// Single-submit protection
document.getElementById('addProductForm')?.addEventListener('submit', function(e) {
    const btn = document.getElementById('submitBtn');
    if (btn && !btn.disabled) {
        btn.disabled = true;
        const loadingText = btn.dataset.loading || 'Processing...';
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + loadingText;
    }
});

// Category toggle
function setCategory(cat) {
    document.getElementById('category_id').value = cat;
    document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.cat-btn')[cat === 1 ? 0 : 1].classList.add('active');
    document.getElementById('medFields').style.display = cat === 1 ? '' : 'none';
    document.getElementById('nonMedFields').style.display = cat === 2 ? '' : 'none';
    ['generic_name','dosage_form'].forEach(n => {
        const el = document.getElementById('f_' + n);
        if (el) el.required = cat === 1;
    });
    saveDraft();
    updatePreview();
}

// ═══════════════════════════════════════════════════════════════════════════
// FIX #1 — Units/Pack preview matches list.php display exactly
// Mirrors PHP: _smartPieceLabel(), _smartPackLabel(), formatUnitsPerPackDisplay()
// ═══════════════════════════════════════════════════════════════════════════

function smartPieceLabel(dosageForm, unitType) {
    const df = (dosageForm || '').toLowerCase().trim();
    const ut = (unitType   || '').toLowerCase().trim();
    if (df === 'tablet'  || df === 'tablets')  return 'Tablet';
    if (df === 'capsule' || df === 'capsules') return 'Capsule';
    if (df === 'syrup')                        return 'Syrup';
    if (df === 'injection' || ut === 'ampoule' || ut === 'vial') return 'Unit';
    if (ut === 'sachet')  return 'Sachet';
    if (ut === 'tube')    return 'Tube';
    if (ut === 'bottle')  return 'Bottle';
    return 'Pc';
}

function smartPackLabel(unitType) {
    switch ((unitType || '').toLowerCase().trim()) {
        case 'strip':   return 'Strip';
        case 'bottle':  return 'Bottle';
        case 'box':     return 'Box';
        case 'tube':    return 'Tube';
        case 'sachet':  return 'Sachet';
        case 'vial':    return 'Vial';
        case 'ampoule': return 'Ampoule';
        default:        return 'Pack';
    }
}

function formatUnitsPerPackDisplay(units, unitType, dosageForm) {
    units = Math.max(1, parseInt(units) || 1);
    const df = (dosageForm || '').toLowerCase().trim();
    const ut = (unitType   || '').toLowerCase().trim();
    const pieceLabel = smartPieceLabel(dosageForm, unitType);
    const packLabel  = smartPackLabel(unitType);

    // Check custom units first
    const customUnits = getCustomUnits();
    if (customUnits[ut]) {
        const cu = customUnits[ut];
        const plural = cu.labelPlural || (cu.label + 's');
        const displayItem = units > 1 ? plural : cu.label;
        return units + ' ' + displayItem + '/' + cu.unitName;
    }

    // Single-item bottle/tube/vial/ampoule → "1 Bottle"
    if (units === 1 && (ut === 'bottle' || ut === 'tube' || ut === 'vial' || ut === 'ampoule')) {
        return '1 ' + packLabel;
    }
    // Strip + Tablet → "10 Tablets/Strip"
    if (ut === 'strip' && (df === 'tablet' || df === 'tablets')) {
        return units + ' Tablet' + (units > 1 ? 's' : '') + '/Strip';
    }
    // Strip + Capsule → "10 Capsules/Strip"
    if (ut === 'strip' && (df === 'capsule' || df === 'capsules')) {
        return units + ' Capsule' + (units > 1 ? 's' : '') + '/Strip';
    }
    // Bottle + Syrup
    if (ut === 'bottle' && df === 'syrup') {
        return units + ' Syrup/Bottle';
    }
    // Pcs
    if (ut === 'pcs' || ut === 'piece' || ut === 'pieces') {
        return units === 1 ? '1 Pc/Pack' : units + ' Pcs/Pack';
    }
    // Generic fallback
    const pluralPiece = units > 1 ? (pieceLabel + 's') : pieceLabel;
    return units + ' ' + pluralPiece + '/' + packLabel;
}

// Live preview
function updatePreview() {
    const cat = parseInt(document.getElementById('category_id').value);
    const name = document.getElementById('f_product_name').value || 'Product Name';
    const brand = document.getElementById('f_brand_name').value || '';
    const gen = cat === 1 ? (document.getElementById('f_generic_name').value || '') : '';
    const df = cat === 1 ? (document.getElementById('f_dosage_form').value || '') : '';
    const st = cat === 1 ? (document.getElementById('f_strength').value || '') : '';
    const sv = cat === 2 ? (document.getElementById('f_size_value').value || '') : '';
    const ut = document.getElementById('f_unit_type').value || '';
    const upp = parseInt(document.getElementById('f_units_per_pack').value) || 1;

    document.getElementById('prev_name').textContent = name;
    document.getElementById('prev_brand').textContent = brand;
    document.getElementById('prev_generic').textContent = gen ? '(' + gen + ')' : '';

    let form = '—';
    if (cat === 1 && df && st) form = df + ' / ' + st;
    else if (cat === 1 && df) form = df;
    else if (cat === 1 && st) form = st;
    else if (cat === 2 && sv) form = sv;
    document.getElementById('prev_form').textContent = form;

    // FIX: Show correct "10 Tablets/Strip" format (mirrors PHP formatUnitsPerPackDisplay)
    const unitsDisplay = ut ? formatUnitsPerPackDisplay(upp, ut, df) : '—';
    document.getElementById('prev_units').textContent = unitsDisplay;

    const codePrefix = cat === 1 ? 'MED' : 'NMED';
    document.getElementById('prev_code').textContent = codePrefix + 'XXXX';
}

// Image preview
function previewImage(input) {
    const thumb = document.getElementById('imgPreviewThumb');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { thumb.src = e.target.result; thumb.classList.remove('d-none'); };
        reader.readAsDataURL(input.files[0]);
    }
}

// Custom Unit Type Management
const CUSTOM_UNITS_KEY = 'pharmacyCustomUnitTypes';

function getCustomUnits() {
    try { return JSON.parse(localStorage.getItem(CUSTOM_UNITS_KEY) || '{}'); } catch(e) { return {}; }
}

function saveCustomUnitsToStorage(units) {
    try { localStorage.setItem(CUSTOM_UNITS_KEY, JSON.stringify(units)); } catch(e) {}
}

function injectCustomUnitsIntoDropdown() {
    const units = getCustomUnits();
    const sel = document.getElementById('f_unit_type');
    if (!sel) return;
    sel.querySelectorAll('option[data-custom]').forEach(o => o.remove());
    const keys = Object.keys(units);
    if (keys.length === 0) return;
    const grp = document.createElement('optgroup');
    grp.label = '✦ Custom Units';
    keys.forEach(key => {
        const opt = document.createElement('option');
        opt.value = units[key].unitName;
        opt.textContent = units[key].unitName;
        opt.dataset.custom = '1';
        grp.appendChild(opt);
    });
    sel.appendChild(grp);
}

function onUnitTypeChange() {
    updatePreview();
    saveDraft();
}

function openAddUnitModal() {
    document.getElementById('cu_unit_name').value = '';
    document.getElementById('cu_item_label').value = '';
    document.getElementById('cu_item_plural').value = '';
    document.getElementById('cu_test_count').value = '10';
    updateUnitPreview();
    renderCustomUnitsList();
    const modal = new bootstrap.Modal(document.getElementById('addUnitModal'));
    modal.show();
}

function updateUnitPreview() {
    const unitName  = (document.getElementById('cu_unit_name')?.value  || '').trim();
    const itemLabel = (document.getElementById('cu_item_label')?.value || '').trim();
    const itemPlural= (document.getElementById('cu_item_plural')?.value || '').trim();
    const count     = parseInt(document.getElementById('cu_test_count')?.value) || 10;

    // Update "Unit Type" row
    const typeEl = document.getElementById('cu_preview_type');
    if (typeEl) typeEl.textContent = unitName || '—';

    // Update "Units/Pack" row — e.g. "10 Capsules/Blister"
    const unitsEl = document.getElementById('cu_preview_units');
    if (!unitsEl) return;

    if (!unitName || !itemLabel) {
        unitsEl.textContent = '—';
        return;
    }
    const plural      = itemPlural || (itemLabel + 's');
    const displayItem = count > 1 ? plural : itemLabel;
    unitsEl.textContent = count + ' ' + displayItem + '/' + unitName;
}

function renderCustomUnitsList() {
    const units     = getCustomUnits();
    const container = document.getElementById('customUnitsList');
    if (!container) return;
    const keys = Object.keys(units);
    if (keys.length === 0) {
        container.innerHTML = '<span style="font-style:italic;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 50%,transparent)">No custom units yet</span>';
        return;
    }
    let html = '';
    keys.forEach(key => {
        const u = units[key];
        const plural = u.labelPlural || (u.label + 's');
        html += `<div class="d-flex justify-content-between align-items-center py-1 border-bottom">
            <div>
              <span class="badge bg-primary me-1">${escHtml(u.unitName)}</span>
              <small style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">
                ${escHtml(u.label + ' / ' + plural)}
              </small>
            </div>
            <button type="button" class="btn btn-link btn-sm text-danger p-0 ms-2"
                    onclick="deleteCustomUnit('${escHtml(key)}')" title="Remove">
              <i class="fas fa-trash-alt"></i>
            </button>
          </div>`;
    });
    container.innerHTML = html;
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

function saveCustomUnit() {
    const unitName  = (document.getElementById('cu_unit_name')?.value  || '').trim();
    const itemLabel = (document.getElementById('cu_item_label')?.value || '').trim();
    const itemPlural= (document.getElementById('cu_item_plural')?.value || '').trim();

    if (!unitName) {
        alert('Unit name is required!');
        document.getElementById('cu_unit_name')?.focus();
        return;
    }
    if (!itemLabel) {
        alert('Item label is required!');
        document.getElementById('cu_item_label')?.focus();
        return;
    }

    const units = getCustomUnits();
    const key   = unitName.toLowerCase();
    units[key]  = {
        unitName   : unitName,
        label      : itemLabel,
        labelPlural: itemPlural || (itemLabel + 's'),
        perLabel   : unitName,
    };
    saveCustomUnitsToStorage(units);
    injectCustomUnitsIntoDropdown();
    renderCustomUnitsList();

    // Auto-select the newly added unit in the dropdown
    const sel = document.getElementById('f_unit_type');
    if (sel) sel.value = unitName;
    updatePreview();

    // Reset modal inputs
    document.getElementById('cu_unit_name').value   = '';
    document.getElementById('cu_item_label').value  = '';
    document.getElementById('cu_item_plural').value = '';
    document.getElementById('cu_test_count').value  = '10';
    updateUnitPreview();

    // Close the modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('addUnitModal'));
    if (modal) modal.hide();
}

function deleteCustomUnit(key) {
    if (!confirm('Delete this custom unit type?')) return;
    const units = getCustomUnits();
    delete units[key];
    saveCustomUnitsToStorage(units);
    injectCustomUnitsIntoDropdown();
    renderCustomUnitsList();
    updatePreview();
}

// Auto-dismiss error alert
(function() {
    const alertEl = document.getElementById('errorAlert');
    if (alertEl) {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
            if (bsAlert) bsAlert.close();
        }, 6000);
    }
})();

// Bootstrap tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));

// Init
loadDraft();
setCategory(parseInt(document.getElementById('category_id').value) || <?= $cat ?>);
attachDraftListeners();
injectCustomUnitsIntoDropdown();
updatePreview();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>