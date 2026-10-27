<?php
/**
 * products/edit.php — Edit Product
 * Step 8 UI/UX Sync — Rebuilt to match add.php interface exactly.
 * Rules:
 *  - Same Medicine / Non-Medicine toggle UI as add.php
 *  - All add.php fields pre-filled with existing product data
 *  - Only editable when ALL batches are expired OR all batches out-of-stock
 *    (i.e. no active/in-stock batches exist)
 *  - Batch info is NOT editable here — pricing/stock is set at purchase level
 *  - Live preview panel identical to add.php
 *  - CSRF protection
 *  - All colors via CSS variables — no hardcoded theme values
 *  - Submit button single-submit protection + loading state
 *  - Auto-dismiss alerts after 5 seconds
 *  - Smooth transitions on all interactive elements
 *  - Page fade-in animation
 *  - Breadcrumb: Dashboard > Products > Edit Product
 *  - All existing PHP logic preserved
 */

require_once '../config/config.php';
require_once '../includes/product_functions.php';

requirePharmacist();

if (!function_exists('sanitize')) {
    function sanitize($v) { return htmlspecialchars(trim((string)$v), ENT_QUOTES, 'UTF-8'); }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── Load product ──────────────────────────────────────────────────────────
$product = null;
try {
    $product = _pdoOne("SELECT * FROM products WHERE id = :id AND status != -1 LIMIT 1", [':id' => $id]);
} catch (\Throwable $e) {
    if (function_exists('getProductById')) {
        $product = getProductById($id);
    }
}

if (!$product) {
    header('Location: list.php?error=Product+not+found');
    exit;
}

// ── Check if product is editable ─────────────────────────────────────────
$activeCount = 0;
try {
    $row = _pdoOne(
        "SELECT COUNT(*) AS cnt FROM batches
         WHERE product_id = :pid
           AND remaining_quantity > 0
           AND COALESCE(is_expired, 0) = 0
           AND (expiry_date IS NULL OR expiry_date >= CURDATE())",
        [':pid' => $id]
    );
    $activeCount = (int)($row['cnt'] ?? 0);
} catch (\Throwable $e) { }

$isEditable = ($activeCount === 0);

// ── Pre-fetch dosage forms & strengths ───────────────────────────────────
$dosageForms  = function_exists('getAllDosageForms') ? getAllDosageForms() : [];
$defaultForms = ['Tablet','Syrup','Capsule','Injection','Cream','Ointment','Drops','Gel','Inhaler','Suppository','Patch','Powder','Suspension','Solution','Lotion','Granules'];
$strengths    = function_exists('getAllStrengths') ? getAllStrengths() : [];

$page_title = 'Edit Product — ' . htmlspecialchars($product['product_name']);
$errors  = [];
$success = '';

// ── POST HANDLER ─────────────────────────────────────────────────────────
if ($isEditable && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token mismatch. Please refresh and try again.';
    } else {
        $cat          = (int)($_POST['category_id'] ?? 1);
        $productName  = trim($_POST['product_name'] ?? '');
        $brandName    = trim($_POST['brand_name']   ?? '');
        $unitType     = trim($_POST['unit_type']    ?? '');
        $unitsPerPack = (int)($_POST['units_per_pack'] ?? 0);

        if (empty($productName))  $errors[] = 'Product name is required.';
        if (empty($brandName))    $errors[] = 'Brand name is required.';
        if (empty($unitType))     $errors[] = 'Unit type is required.';
        if ($unitsPerPack < 1)    $errors[] = 'Units per pack must be at least 1.';

        if ($cat === 1) {
            if (empty(trim($_POST['generic_name'] ?? ''))) $errors[] = 'Generic name is required for medicines.';
            if (empty(trim($_POST['dosage_form']  ?? ''))) $errors[] = 'Dosage form is required for medicines.';
        }

        // Handle image upload
        $imagePath = $product['image_path'] ?? null;
        if (isset($_POST['remove_image']) && $_POST['remove_image'] == 1) {
            if (!empty($imagePath) && file_exists('../' . $imagePath)) {
                @unlink('../' . $imagePath);
            }
            $imagePath = null;
        } elseif (!empty($_FILES['product_image']['name'])) {
            $allowed  = ['image/jpeg','image/png','image/gif','image/webp'];
            $maxSize  = 2 * 1024 * 1024;
            $mime     = mime_content_type($_FILES['product_image']['tmp_name']);
            $size     = $_FILES['product_image']['size'];
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
                    if (!empty($product['image_path']) && file_exists('../' . $product['image_path'])) {
                        @unlink('../' . $product['image_path']);
                    }
                    $imagePath = 'uploads/products/' . $filename;
                }
            }
        }

        if (empty($errors)) {
            $updateData = [
                ':category_id'   => $cat,
                ':product_name'  => $productName,
                ':brand_name'    => $brandName,
                ':unit_type'     => $unitType,
                ':units_per_pack'=> $unitsPerPack,
                ':barcode'       => trim($_POST['barcode'] ?? '') ?: null,
                ':image_path'    => $imagePath,
                ':id'            => $id,
            ];

            if ($cat === 1) {
                $updateData[':generic_name'] = trim($_POST['generic_name'] ?? '');
                $updateData[':dosage_form']  = trim($_POST['dosage_form']  ?? '');
                $updateData[':strength']     = trim($_POST['strength']     ?? '') ?: null;
                $updateData[':size_value']   = null;
                if (function_exists('saveCustomDosageForm') && !empty($updateData[':dosage_form'])) {
                    saveCustomDosageForm($updateData[':dosage_form']);
                }
                if (function_exists('saveCustomStrength') && !empty($updateData[':strength'])) {
                    saveCustomStrength($updateData[':strength'], '');
                }
            } else {
                $updateData[':generic_name'] = null;
                $updateData[':dosage_form']  = null;
                $updateData[':strength']     = null;
                $updateData[':size_value']   = trim($_POST['size_value'] ?? '') ?: null;
            }

            try {
                _pdoRun(
                    "UPDATE products SET
                        category_id    = :category_id,
                        product_name   = :product_name,
                        brand_name     = :brand_name,
                        unit_type      = :unit_type,
                        units_per_pack = :units_per_pack,
                        barcode        = :barcode,
                        image_path     = :image_path,
                        generic_name   = :generic_name,
                        dosage_form    = :dosage_form,
                        strength       = :strength,
                        size_value     = :size_value,
                        updated_at     = NOW()
                     WHERE id = :id",
                    $updateData
                );

                if (function_exists('logActivity')) {
                    logActivity('product_updated', "Edited product ID: $id — $productName");
                }

                $_SESSION['flash_success'] = 'Product updated successfully!';
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Product updated successfully!']);
                    exit;
                }
                header('Location: list.php');
                exit;
            } catch (\Throwable $e) {
                $errors[] = 'Failed to update product: ' . $e->getMessage();
            }
        }

        // On error, keep form data from POST
        $product = array_merge($product, $_POST);
    }
}

$cat  = (int)($product['category_id'] ?? 1);
$csrf = function_exists('generateCSRFToken') ? generateCSRFToken() : ($_SESSION['csrf_token'] ?? bin2hex(random_bytes(16)));

// Existing image URL
$existingImageUrl = '';
if (!empty($product['image_path'])) {
    $existingImageUrl = (defined('BASE_URL') ? BASE_URL : '/') . ltrim(str_replace('\\', '/', $product['image_path']), '/');

    if ($isAjax && !empty($errors)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }
}

$medUnits    = ['Strip','Bottle','Box','Tube','Sachet','Vial','Ampoule','Pcs'];
$nonMedUnits = ['Pcs','Box','Bottle','Tube','Sachet','Strip','Bag','Roll'];
$allUnits    = array_unique(array_merge($medUnits, $nonMedUnits));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>


<style>
/* Layout */
.main-content {
  margin-left: var(--sb-w, 260px);
  padding: 24px;
  animation: fadeInPage 0.28s ease;
}
@keyframes fadeInPage {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: translateY(0); }
}
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

body { font-size: 14px; background: var(--pharmacy-body-bg, #f0f2f5); }

/* Page header */
.page-header-title {
  color: var(--pharmacy-heading, #212529) !important;
  font-weight: 700;
}
.page-header-sub {
  font-size: 0.82rem;
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 65%, transparent);
}

/* Form cards */
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

/* Form labels */
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

/* Inputs */
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

/* Category toggle */
.cat-toggle { display: flex; gap: 12px; margin-bottom: 20px; }
.cat-btn {
  flex: 1; padding: 18px; border-radius: 12px;
  border: 2px solid var(--pharmacy-border, #dee2e6);
  background: var(--pharmacy-card-bg, #fff);
  cursor: pointer; text-align: center;
  transition: all 0.2s ease; font-size: 15px; font-weight: 600;
  color: var(--pharmacy-text, #343a40);
}
.cat-btn:hover:not(.disabled-toggle) {
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
.cat-btn.disabled-toggle {
  pointer-events: none;
  opacity: 0.65;
  cursor: not-allowed;
}
.cat-btn .small.text-muted {
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 60%, transparent) !important;
}

/* Preview panel */
.preview-card {
  background: color-mix(in srgb, var(--pharmacy-table-th, #f8f9fa) 80%, var(--pharmacy-card-bg, #fff));
  border: 1px solid var(--pharmacy-border, #dee2e6);
  border-radius: 12px; padding: 16px;
}
.preview-name    { font-weight: 700; font-size: 15px; color: var(--pharmacy-heading, #212529); }
.preview-generic { font-style: italic; color: color-mix(in srgb, var(--pharmacy-text, #343a40) 60%, transparent); font-size: 13px; }
.preview-stock {
  border-radius: 8px; padding: 8px 12px;
  font-size: 13px; font-weight: 600;
}
.preview-stock.batch-ok {
  background: color-mix(in srgb, #198754 12%, var(--pharmacy-card-bg, #fff));
  border: 1px solid color-mix(in srgb, #198754 30%, transparent);
  color: #0a5c36;
}
.preview-stock.batch-locked {
  background: color-mix(in srgb, #ffc107 12%, var(--pharmacy-card-bg, #fff));
  border: 1px solid color-mix(in srgb, #ffc107 50%, transparent);
  color: #7a4f00;
}

/* Note box */
.note-box {
  background: color-mix(in srgb, #ffc107 12%, var(--pharmacy-card-bg, #fff));
  border: 1px solid color-mix(in srgb, #ffc107 50%, transparent);
  border-radius: 8px; padding: 10px 14px; font-size: 12.5px;
  color: var(--pharmacy-text, #343a40);
}

/* Locked overlay */
.locked-overlay {
  background: color-mix(in srgb, #ffc107 10%, var(--pharmacy-card-bg, #fff));
  border: 2px solid color-mix(in srgb, #ffc107 60%, transparent);
  border-radius: var(--card-border-radius, 12px);
  padding: 18px 20px;
}
.locked-overlay h5 {
  color: var(--pharmacy-heading, #212529) !important;
}

/* Batch info row */
.batch-info-row {
  background: color-mix(in srgb, var(--pharmacy-table-th, #f8f9fa) 100%, transparent);
  border-radius: 8px; padding: 8px 12px; font-size: 12px; margin-bottom: 4px;
  color: var(--pharmacy-text, #343a40);
}

/* Read-only table card */
.table-card {
  border-radius: var(--card-border-radius, 12px);
  overflow: hidden;
  border: 1px solid var(--pharmacy-border, #dee2e6);
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  background: var(--pharmacy-card-bg, #fff);
}
.table-card .table th {
  background: var(--pharmacy-table-th, #f8f9fa) !important;
  color: var(--pharmacy-heading, #212529) !important;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  font-weight: 700;
  padding: 12px 16px;
  border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important;
}
.table-card .table td {
  padding: 11px 16px;
  vertical-align: middle;
  color: var(--pharmacy-text, #343a40) !important;
  border-color: var(--pharmacy-border, #dee2e6) !important;
}

/* Submit buttons */
.btn-submit-save {
  background: var(--pharmacy-primary, #f7971e);
  color: #fff; border: none; border-radius: 10px;
  padding: 10px 28px; font-weight: 600;
  transition: all 0.2s ease;
}
.btn-submit-save:hover:not(:disabled) {
  filter: brightness(1.08); color: #fff;
  transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.18);
}
.btn-submit-save:disabled { opacity: 0.75; cursor: not-allowed; }

/* Buttons general transitions */
.btn { transition: all 0.18s ease; }

/* Alert auto-dismiss */
.alert { transition: opacity 0.4s ease; }

/* Image preview */
#imgPreviewThumb {
  border: 2px solid var(--pharmacy-border, #dee2e6);
  border-radius: 8px; max-height: 120px;
}

/* Preview table cells */
.preview-table td:first-child {
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 60%, transparent);
  font-size: 12px;
}
.preview-table td:last-child {
  font-weight: 600; color: var(--pharmacy-heading, #212529);
}

/* No-image placeholder */
.img-placeholder {
  background: color-mix(in srgb, var(--pharmacy-table-th, #f8f9fa) 100%, transparent);
  border: 2px dashed var(--pharmacy-border, #dee2e6);
  border-radius: 10px; padding: 24px;
  text-align: center;
  color: color-mix(in srgb, var(--pharmacy-text, #343a40) 45%, transparent);
}

/* Unit modal header */
.unit-modal-header {
  background: linear-gradient(135deg, var(--pharmacy-primary, #667eea), var(--pharmacy-accent, #764ba2));
  color: #fff; border-radius: 16px 16px 0 0;
}

/* Section heading */
.section-title, .card-title, h1, h2, h3, h4, h5, h6 {
  color: var(--pharmacy-heading, #212529) !important;
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
        <i class="fas fa-edit me-2" style="color:var(--pharmacy-primary,#f7971e)"></i>Edit Product
      </h4>
      <p class="page-header-sub mb-0">
        <?= htmlspecialchars($product['product_name']) ?>
        <span class="badge ms-2" style="background:var(--pharmacy-table-th,#e9ecef);color:var(--pharmacy-text,#343a40);font-weight:600;">
          <?= htmlspecialchars($product['product_code'] ?? '') ?>
        </span>
        <?php if ($isEditable): ?>
          <span class="badge ms-1" style="background:color-mix(in srgb,#198754 15%,transparent);color:#0a5c36;font-weight:600;">
            <i class="fas fa-unlock-alt me-1"></i>Editing Unlocked
          </span>
        <?php else: ?>
          <span class="badge ms-1" style="background:color-mix(in srgb,#ffc107 18%,transparent);color:#7a4f00;font-weight:600;">
            <i class="fas fa-lock me-1"></i>Locked - Active Stock
          </span>
        <?php endif; ?>
      </p>
    </div>
    <a href="list.php" class="btn btn-outline-secondary">
      <i class="fas fa-arrow-left me-1"></i>Back to List
    </a>
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

  <?php if (!$isEditable): ?>
  <!-- LOCKED STATE - Active stock exists -->
  <div class="locked-overlay mb-4">
    <h5 class="fw-bold mb-2">
      <i class="fas fa-lock me-2" style="color:var(--pharmacy-primary,#ffc107)"></i>
      Product is Locked - Active Stock Exists
    </h5>
    <p class="mb-2" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 80%,transparent)">
      This product has <strong><?= $activeCount ?> active batch(es)</strong> with stock in hand.
      Product details can only be edited when all batches are either <strong>expired</strong> or <strong>out of stock</strong>.
    </p>
    <p class="mb-0 small" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">
      <i class="fas fa-info-circle me-1"></i>To modify pricing or expiry, go to
      <a href="../purchases/add.php" class="fw-semibold">Add Purchase</a> for new batches.
    </p>
  </div>

  <!-- Read-only product info -->
  <div class="row g-4">
    <div class="col-lg-8">

      <!-- Basic Info card -->
      <div class="form-card">
        <div class="form-card-header">
          <span class="card-icon" style="background:color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 15%,transparent)">
            <i class="fas fa-tag" style="color:var(--pharmacy-primary,#0d6efd)"></i>
          </span>
          <h6>Product Details</h6>
          <span class="card-subtitle">Read-Only - Active stock exists</span>
        </div>
        <div class="form-card-body">
          <div class="table-responsive">
            <table class="table table-borderless table-sm mb-0">
              <tbody>
                <tr>
                  <td class="fw-semibold" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent);width:180px">Category</td>
                  <td>
                    <?php if ($cat === 1): ?>
                      <span class="badge" style="background:color-mix(in srgb,#0d6efd 15%,transparent);color:#0d6efd;font-weight:600;">
                        <i class="fas fa-pills me-1"></i>Medicine
                      </span>
                    <?php else: ?>
                      <span class="badge" style="background:color-mix(in srgb,#20c997 15%,transparent);color:#0f7855;font-weight:600;">
                        <i class="fas fa-box me-1"></i>Non-Medicine
                      </span>
                    <?php endif; ?>
                    </td>
                </tr>
                <tr>
                  <td class="fw-semibold" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">Product Name</td>
                  <td style="color:var(--pharmacy-heading,#212529);font-weight:600"><?= htmlspecialchars($product['product_name']) ?></td>
                </tr>
                <tr>
                  <td class="fw-semibold" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">Brand</td>
                  <td style="color:var(--pharmacy-text,#343a40)"><?= htmlspecialchars($product['brand_name']) ?></td>
                </tr>
                <?php if ($cat === 1): ?>
                <tr>
                  <td class="fw-semibold" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">Generic Name</td>
                  <td style="color:var(--pharmacy-text,#343a40)"><?= htmlspecialchars($product['generic_name'] ?? '-') ?></td>
                </tr>
                <tr>
                  <td class="fw-semibold" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">Dosage Form</td>
                  <td style="color:var(--pharmacy-text,#343a40)"><?= htmlspecialchars($product['dosage_form'] ?? '-') ?></td>
                </tr>
                <tr>
                  <td class="fw-semibold" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">Strength</td>
                  <td style="color:var(--pharmacy-text,#343a40)"><?= htmlspecialchars($product['strength'] ?? '-') ?></td>
                </tr>
                <?php else: ?>
                <tr>
                  <td class="fw-semibold" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">Size / Variant</td>
                  <td style="color:var(--pharmacy-text,#343a40)"><?= htmlspecialchars($product['size_value'] ?? '-') ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                  <td class="fw-semibold" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">Unit Type</td>
                  <td style="color:var(--pharmacy-text,#343a40)"><?= htmlspecialchars($product['unit_type'] ?? '-') ?></td>
                </tr>
                <tr>
                  <td class="fw-semibold" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">Units Per Pack</td>
                  <td style="color:var(--pharmacy-text,#343a40)"><?= (int)($product['units_per_pack'] ?? 1) ?></td>
                </tr>
                <tr>
                  <td class="fw-semibold" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">Barcode</td>
                  <td style="color:var(--pharmacy-text,#343a40)"><?= htmlspecialchars($product['barcode'] ?? '-') ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>

    <!-- Side panel: image + lock note -->
    <div class="col-lg-4">
      <div class="form-card p-4">
        <?php if (!empty($existingImageUrl)): ?>
          <img src="<?= htmlspecialchars($existingImageUrl) ?>" class="img-fluid rounded mb-3" style="max-height:180px;object-fit:cover;width:100%;" alt="Product Image">
        <?php else: ?>
          <div class="img-placeholder mb-3">
            <i class="fas fa-image fa-3x mb-2 d-block"></i>
            <small>No product image</small>
          </div>
        <?php endif; ?>
        <div class="note-box">
          <i class="fas fa-lock me-2" style="color:var(--pharmacy-primary,#ffc107)"></i>
          <strong>Editing disabled.</strong> Sell or mark all batches as depleted/expired to unlock editing.
        </div>
      </div>
    </div>
  </div>
  <!-- END LOCKED STATE -->

<?php else: ?>
  <!-- EDITABLE FORM - All batches depleted/expired -->
  <div class="row g-4">

    <!-- MAIN FORM -->
    <div class="col-lg-8">
      <div id="editProductAlert" class="mb-3"></div>
      <form method="POST" enctype="multipart/form-data" id="editProductForm"
            data-pharm-form
            data-success-msg="Product updated successfully!"
            data-redirect="list.php"
            data-redirect-delay="1500"
            data-loading-text="Saving Changes…"
            data-alert-target="#editProductAlert"
            novalidate>
        <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="save_product" value="1">
        <input type="hidden" name="category_id"  id="category_id" value="<?= $cat ?>">

        <!-- CARD 1: Basic Information -->
        <div class="form-card">
          <div class="form-card-header">
            <span class="card-icon" style="background:color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 15%,transparent)">
              <i class="fas fa-tag" style="color:var(--pharmacy-primary,#0d6efd)"></i>
            </span>
            <h6>Basic Information</h6>
            <span class="card-subtitle">Name, brand & category</span>
          </div>
          <div class="form-card-body">

            <!-- Category Toggle -->
            <div class="cat-toggle">
              <div class="cat-btn med <?= $cat===1?'active':'' ?>" onclick="setCategory(1)">
                <i class="fas fa-pills fa-2x mb-2 d-block"></i>
                Medicine
                <div class="small text-muted fw-normal mt-1">Tablet, Syrup, Capsule...</div>
              </div>
              <div class="cat-btn nonmed <?= $cat===2?'active':'' ?>" onclick="setCategory(2)">
                <i class="fas fa-box fa-2x mb-2 d-block"></i>
                Non-Medicine
                <div class="small text-muted fw-normal mt-1">Surgical, Cosmetic, Equipment...</div>
              </div>
            </div>

            <!-- Common fields -->
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label">Product Name <span class="text-danger">*</span></label>
                <input type="text" name="product_name" id="f_product_name" class="form-control"
                       placeholder="e.g. Panadol"
                       value="<?= htmlspecialchars($product['product_name'] ?? '') ?>"
                       oninput="updatePreview()" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Brand Name <span class="text-danger">*</span></label>
                <input type="text" name="brand_name" id="f_brand_name" class="form-control"
                       placeholder="e.g. GSK"
                       value="<?= htmlspecialchars($product['brand_name'] ?? '') ?>"
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
                         value="<?= htmlspecialchars($product['generic_name'] ?? '') ?>"
                         oninput="updatePreview()">
                </div>
                <div class="col-md-6">
                  <label class="form-label">
                    Strength
                    <span class="label-hint">(optional)</span>
                  </label>
                  <input type="text" name="strength" id="f_strength" class="form-control"
                         placeholder="e.g. 250mg, 500mg" list="strengthList"
                         value="<?= htmlspecialchars($product['strength'] ?? '') ?>"
                         oninput="updatePreview()">
                  <datalist id="strengthList">
                    <?php foreach ($strengths as $s): ?>
                    <option value="<?= htmlspecialchars(is_array($s) ? ($s['strength'] ?? '') : (string)$s) ?>">
                    <?php endforeach; ?>
                  </datalist>
                </div>
              </div>
              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label">Dosage Form <span class="text-danger">*</span></label>
                  <input type="text" name="dosage_form" id="f_dosage_form" class="form-control"
                         list="dosageFormList" placeholder="e.g. Tablet"
                         value="<?= htmlspecialchars($product['dosage_form'] ?? '') ?>"
                         oninput="updatePreview()">
                  <datalist id="dosageFormList">
                    <?php foreach ($defaultForms as $df): ?>
                    <option value="<?= htmlspecialchars($df) ?>">
                    <?php endforeach; ?>
                    <?php foreach ($dosageForms as $df):
                      $dfVal = is_array($df) ? ($df['form_name'] ?? $df['dosage_form'] ?? '') : (string)$df;
                      if (!in_array($dfVal, $defaultForms, true)): ?>
                    <option value="<?= htmlspecialchars($dfVal) ?>">
                    <?php endif; endforeach; ?>
                  </datalist>
                </div>
              </div>
            </div>

            <!-- Non-Medicine-only fields -->
            <div id="nonMedFields" style="display:none">
              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label">
                    Size / Variant
                    <span class="label-hint">(optional)</span>
                  </label>
                  <input type="text" name="size_value" id="f_size_value" class="form-control"
                         placeholder="e.g. Small, 250ml, 1CC, 100g"
                         value="<?= htmlspecialchars($product['size_value'] ?? '') ?>"
                         oninput="updatePreview()">
                </div>
              </div>
            </div>

          </div>
        </div>

        <!-- CARD 2: Packing & Barcode -->
        <div class="form-card">
          <div class="form-card-header">
            <span class="card-icon" style="background:color-mix(in srgb,#20c997 15%,transparent)">
              <i class="fas fa-box-open" style="color:#20c997"></i>
            </span>
            <h6>Packing & Barcode</h6>
            <span class="card-subtitle">Unit type, units per pack & barcode</span>
          </div>
          <div class="form-card-body">
            <div class="row g-3 mb-3">
              <div class="col-md-4">
                <label class="form-label">Unit Type <span class="text-danger">*</span></label>
                <div class="input-group">
                  <select name="unit_type" id="f_unit_type" class="form-select" onchange="onUnitTypeChange()">
                    <option value="">-- Select --</option>
                    <?php
                    $selUnit = $product['unit_type'] ?? '';
                    foreach ($allUnits as $u): ?>
                    <option value="<?= htmlspecialchars($u) ?>" <?= $selUnit === $u ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
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
                  <i class="fas fa-info-circle ms-1" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 45%,transparent)"
                     data-bs-toggle="tooltip" title="How many individual pieces in 1 pack/strip/bottle"></i>
                </label>
                <input type="number" name="units_per_pack" id="f_units_per_pack" class="form-control"
                       min="1" value="<?= (int)($product['units_per_pack'] ?? 1) ?>"
                       oninput="updatePreview()">
              </div>
              <div class="col-md-4">
                <label class="form-label">
                  Barcode
                  <span class="label-hint">(optional)</span>
                </label>
                <input type="text" name="barcode" class="form-control"
                       placeholder="Scan or type barcode"
                       value="<?= htmlspecialchars($product['barcode'] ?? '') ?>">
              </div>
            </div>

            <!-- Note -->
            <div class="note-box">
              <i class="fas fa-info-circle me-2" style="color:var(--pharmacy-primary,#ffc107)"></i>
              <strong>Note:</strong> Pricing and reorder level are managed <strong>per batch</strong> when adding a purchase.
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
            <span class="card-subtitle">Optional - max 2MB (JPG/PNG/WebP)</span>
          </div>
          <div class="form-card-body">
            <input type="file" name="product_image" class="form-control" accept="image/*"
                   onchange="previewImage(this)">

            <?php if (!empty($existingImageUrl)): ?>
            <div class="mt-3 d-flex align-items-center gap-3" id="currentImageWrap">
              <img src="<?= htmlspecialchars($existingImageUrl) ?>" class="rounded" style="max-height:90px;border:2px solid var(--pharmacy-border,#dee2e6);border-radius:8px;" alt="Current">
              <div>
                <p class="mb-1 small fw-semibold" style="color:var(--pharmacy-heading,#212529)">Current image</p>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="removeImageCheck">
                  <label class="form-check-label text-danger small" for="removeImageCheck">
                    <i class="fas fa-trash me-1"></i>Remove current image
                  </label>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <img id="imgPreviewThumb" src="" class="mt-3 d-none" alt="Preview">
          </div>
        </div>

        <!-- Submit -->
        <div class="d-flex gap-2 mb-4">
          <button type="submit" data-submit-btn class="btn btn-submit-save" id="submitBtn"
                  data-requires-perm="products.edit"
                  data-loading="Saving Changes...">
            <i class="fas fa-save me-2"></i>Save Changes
          </button>
          <a href="list.php" class="btn btn-outline-secondary">
            <i class="fas fa-times me-1"></i>Cancel
          </a>
        </div>

      </form>
    </div>

    <!-- PREVIEW PANEL -->
    <div class="col-lg-4">
      <div class="form-card p-4" style="position:sticky;top:80px;">
        <h6 class="fw-bold mb-3" style="color:var(--pharmacy-heading,#212529)">
          <i class="fas fa-eye me-2" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 45%,transparent)"></i>Live Preview
        </h6>
        <div class="preview-card">
          <!-- Image + name row -->
          <div class="d-flex gap-3 mb-3">
            <div id="previewImgWrap" style="width:48px;height:48px;border-radius:8px;background:var(--pharmacy-table-th,#e9ecef);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid var(--pharmacy-border,#dee2e6)">
              <?php if (!empty($existingImageUrl)): ?>
                <img src="<?= htmlspecialchars($existingImageUrl) ?>" id="previewImgEl" style="width:100%;height:100%;object-fit:cover;" alt="">
              <?php else: ?>
                <i class="fas fa-image" id="previewImgEl" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 35%,transparent)"></i>
              <?php endif; ?>
            </div>
            <div style="min-width:0">
              <div class="preview-name" id="prev_name"><?= htmlspecialchars($product['product_name']) ?></div>
              <div class="preview-generic" id="prev_generic">
                <?= !empty($product['generic_name']) ? '(' . htmlspecialchars($product['generic_name']) . ')' : '' ?>
              </div>
              <div id="prev_brand" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 70%,transparent);font-size:13px">
                <?= htmlspecialchars($product['brand_name'] ?? '') ?>
              </div>
              <div class="mt-1">
                <span class="badge" style="background:var(--pharmacy-table-th,#e9ecef);color:var(--pharmacy-text,#343a40);border:1px solid var(--pharmacy-border,#dee2e6);font-weight:600" id="prev_code">
                  <?= htmlspecialchars($product['product_code'] ?? ($cat===1?'MED':'NMED').'XXXX') ?>
                </span>
              </div>
            </div>
          </div>

          <hr style="border-color:var(--pharmacy-border,#dee2e6)">

          <table class="table table-sm table-borderless mb-0 small preview-table">
            <tr>
              <td>Form / Size</td>
              <td id="prev_form">-</td>
            </tr>
            <tr>
              <td>Units / Pack</td>
              <td id="prev_units">-</td>
            </tr>
            <tr>
              <td>Reorder Level</td>
              <td style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 55%,transparent);font-style:italic">Set at purchase</td>
            </tr>
            <tr>
              <td>Price</td>
              <td style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 55%,transparent);font-style:italic">Set at purchase</td>
            </tr>
          </table>

          <!-- Batch summary -->
          <div class="mt-3 preview-stock batch-ok">
            <i class="fas fa-check-circle me-1"></i>
            <?php
            try {
                $batchSummary = _pdoOne(
                    "SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN remaining_quantity > 0 AND COALESCE(is_expired,0)=0 AND (expiry_date IS NULL OR expiry_date >= CURDATE()) THEN 1 ELSE 0 END) AS active,
                        SUM(CASE WHEN remaining_quantity = 0 THEN 1 ELSE 0 END) AS oos,
                        SUM(CASE WHEN is_expired=1 OR expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired
                     FROM batches WHERE product_id = :pid",
                    [':pid' => $id]
                );
                echo "Batches: " . (int)($batchSummary['total'] ?? 0) .
                     " | Active: " . (int)($batchSummary['active'] ?? 0) .
                     " | OOS: " . (int)($batchSummary['oos'] ?? 0) .
                     " | Expired: " . (int)($batchSummary['expired'] ?? 0);
            } catch (\Throwable $e) {
                echo 'Editing unlocked';
            }
            ?>
          </div>
        </div>

        <hr style="border-color:var(--pharmacy-border,#dee2e6)">
        <div class="small" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 65%,transparent)">
          <i class="fas fa-lightbulb me-2" style="color:var(--pharmacy-primary,#ffc107)"></i>
          <strong style="color:var(--pharmacy-heading,#212529)">Editing allowed</strong> - all batches are depleted or expired.
          After saving, go to <a href="../purchases/add.php" style="color:var(--pharmacy-primary,#0d6efd);font-weight:600">Add Purchase</a> to restock.
        </div>
      </div>
    </div>

  </div>
  <!-- END EDITABLE FORM -->

<?php endif; ?>

</div><!-- /container-fluid -->
</div><!-- /main-content -->


<!-- ═══════════════════════════════════════════════════════════════════════
     Custom Unit Type Modal — same as add.php
═══════════════════════════════════════════════════════════════════════ -->
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
          <div class="col-md-6">
            <h6 class="fw-bold mb-3 text-success"><i class="fas fa-eye me-1"></i>Live Preview</h6>
            <p class="mb-2" style="font-size:12px;color:color-mix(in srgb,var(--pharmacy-text,#343a40) 60%,transparent)">
              How it will appear in the products list:
            </p>
            <div class="border rounded p-3 mb-3" style="background:var(--pharmacy-card-bg,#fff)">
              <div class="d-flex justify-content-between align-items-center mb-2 pb-2"
                   style="border-bottom:1px solid var(--pharmacy-border,#dee2e6)">
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
// ═══════════════════════════════════════════════════════════════════════════
// Category toggle
// ═══════════════════════════════════════════════════════════════════════════
function setCategory(cat) {
    document.getElementById('category_id').value = cat;
    document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
    const btns = document.querySelectorAll('.cat-btn');
    if (cat === 1) { btns[0].classList.add('active'); }
    else           { btns[1].classList.add('active'); }
    document.getElementById('medFields').style.display    = cat === 1 ? '' : 'none';
    document.getElementById('nonMedFields').style.display = cat === 2 ? '' : 'none';
    const gn = document.getElementById('f_generic_name');
    const df = document.getElementById('f_dosage_form');
    if (gn) gn.required = cat === 1;
    if (df) df.required = cat === 1;
    updatePreview();
}

<?php if ($isEditable): ?>
setCategory(<?= (int)$cat ?>);
<?php endif; ?>

// ═══════════════════════════════════════════════════════════════════════════
// Units/Pack helpers — mirrors PHP formatUnitsPerPackDisplay() exactly
// Same fix as add.php so preview matches list.php
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

function getCustomUnits() {
    try { return JSON.parse(localStorage.getItem('pharmacyCustomUnitTypes') || '{}'); } catch(e) { return {}; }
}

function formatUnitsPerPackDisplay(units, unitType, dosageForm) {
    units = Math.max(1, parseInt(units) || 1);
    const df = (dosageForm || '').toLowerCase().trim();
    const ut = (unitType   || '').toLowerCase().trim();
    const pieceLabel = smartPieceLabel(dosageForm, unitType);
    const packLabel  = smartPackLabel(unitType);

    // Custom units from localStorage
    const customUnits = getCustomUnits();
    if (customUnits[ut]) {
        const cu = customUnits[ut];
        const plural = cu.labelPlural || (cu.label + 's');
        const displayItem = units > 1 ? plural : cu.label;
        return units + ' ' + displayItem + '/' + cu.unitName;
    }
    // Single-item packs
    if (units === 1 && (ut === 'bottle' || ut === 'tube' || ut === 'vial' || ut === 'ampoule')) {
        return '1 ' + packLabel;
    }
    if (ut === 'strip' && (df === 'tablet' || df === 'tablets')) {
        return units + ' Tablet' + (units > 1 ? 's' : '') + '/Strip';
    }
    if (ut === 'strip' && (df === 'capsule' || df === 'capsules')) {
        return units + ' Capsule' + (units > 1 ? 's' : '') + '/Strip';
    }
    if (ut === 'bottle' && df === 'syrup') {
        return units + ' Syrup/Bottle';
    }
    if (ut === 'pcs' || ut === 'piece' || ut === 'pieces') {
        return units === 1 ? '1 Pc/Pack' : units + ' Pcs/Pack';
    }
    const pluralPiece = units > 1 ? (pieceLabel + 's') : pieceLabel;
    return units + ' ' + pluralPiece + '/' + packLabel;
}

// ═══════════════════════════════════════════════════════════════════════════
// Live preview
// ═══════════════════════════════════════════════════════════════════════════
function updatePreview() {
    const cat   = parseInt(document.getElementById('category_id')?.value || <?= (int)$cat ?>);
    const name  = document.getElementById('f_product_name')?.value.trim()  || 'Product Name';
    const brand = document.getElementById('f_brand_name')?.value.trim()    || '';
    const gen   = cat === 1 ? (document.getElementById('f_generic_name')?.value.trim() || '') : '';
    const df    = cat === 1 ? (document.getElementById('f_dosage_form')?.value.trim()  || '') : '';
    const st    = cat === 1 ? (document.getElementById('f_strength')?.value.trim()     || '') : '';
    const sv    = cat === 2 ? (document.getElementById('f_size_value')?.value.trim()   || '') : '';
    const ut    = document.getElementById('f_unit_type')?.value   || '';
    const upp   = parseInt(document.getElementById('f_units_per_pack')?.value) || 1;

    const $set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };

    $set('prev_name',    name);
    $set('prev_brand',   brand);
    $set('prev_generic', gen ? '(' + gen + ')' : '');

    let form = '-';
    if (cat === 1 && df && st) form = df + ' / ' + st;
    else if (cat === 1 && df)  form = df;
    else if (cat === 1 && st)  form = st;
    else if (cat === 2 && sv)  form = sv;
    $set('prev_form', form);

    // FIX: Correct format matching list.php (e.g. "10 Tablets/Strip" not "10 Strips")
    const unitsDisplay = ut ? formatUnitsPerPackDisplay(upp, ut, df) : '-';
    $set('prev_units', unitsDisplay);
}

// ═══════════════════════════════════════════════════════════════════════════
// Image preview
// ═══════════════════════════════════════════════════════════════════════════
function previewImage(input) {
    const thumb    = document.getElementById('imgPreviewThumb');
    const prevWrap = document.getElementById('previewImgWrap');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            if (thumb) { thumb.src = e.target.result; thumb.classList.remove('d-none'); }
            if (prevWrap) {
                prevWrap.innerHTML = '<img src="' + e.target.result + '" id="previewImgEl" style="width:100%;height:100%;object-fit:cover;" alt="">';
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Remove image checkbox
const removeCheck = document.getElementById('removeImageCheck');
if (removeCheck) {
    removeCheck.addEventListener('change', function() {
        const wrap = document.getElementById('currentImageWrap');
        if (wrap) wrap.style.opacity = this.checked ? '0.4' : '1';
        const prevWrap = document.getElementById('previewImgWrap');
        if (prevWrap && this.checked) {
            prevWrap.innerHTML = '<i class="fas fa-image" style="color:color-mix(in srgb,var(--pharmacy-text,#343a40) 35%,transparent)"></i>';
        }
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// Custom Unit Type Modal (same as add.php)
// ═══════════════════════════════════════════════════════════════════════════
const CUSTOM_UNITS_KEY = 'pharmacyCustomUnitTypes';

function saveCustomUnitsToStorage(units) {
    try { localStorage.setItem(CUSTOM_UNITS_KEY, JSON.stringify(units)); } catch(e) {}
}

function injectCustomUnitsIntoDropdown() {
    const units = getCustomUnits();
    const sel   = document.getElementById('f_unit_type');
    if (!sel) return;
    sel.querySelectorAll('option[data-custom]').forEach(o => o.remove());
    sel.querySelectorAll('optgroup[data-custom-group]').forEach(g => g.remove());
    const keys = Object.keys(units);
    if (keys.length === 0) return;
    const grp = document.createElement('optgroup');
    grp.label = '✦ Custom Units';
    grp.dataset.customGroup = '1';
    keys.forEach(key => {
        const opt = document.createElement('option');
        opt.value = units[key].unitName;
        opt.textContent = units[key].unitName;
        opt.dataset.custom = '1';
        // Keep existing product's custom unit selected
        if (units[key].unitName === (<?= json_encode($product['unit_type'] ?? '') ?>)) {
            opt.selected = true;
        }
        grp.appendChild(opt);
    });
    sel.appendChild(grp);
}

function onUnitTypeChange() {
    updatePreview();
}

function openAddUnitModal() {
    document.getElementById('cu_unit_name').value   = '';
    document.getElementById('cu_item_label').value  = '';
    document.getElementById('cu_item_plural').value = '';
    document.getElementById('cu_test_count').value  = '10';
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

    const typeEl = document.getElementById('cu_preview_type');
    if (typeEl) typeEl.textContent = unitName || '—';

    const unitsEl = document.getElementById('cu_preview_units');
    if (!unitsEl) return;
    if (!unitName || !itemLabel) { unitsEl.textContent = '—'; return; }
    const plural      = itemPlural || (itemLabel + 's');
    const displayItem = count > 1 ? plural : itemLabel;
    unitsEl.textContent = count + ' ' + displayItem + '/' + unitName;
}

function escHtml(str) {
    const d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML;
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

function saveCustomUnit() {
    const unitName  = (document.getElementById('cu_unit_name')?.value  || '').trim();
    const itemLabel = (document.getElementById('cu_item_label')?.value || '').trim();
    const itemPlural= (document.getElementById('cu_item_plural')?.value || '').trim();

    if (!unitName)  { alert('Unit name is required!');  document.getElementById('cu_unit_name')?.focus();  return; }
    if (!itemLabel) { alert('Item label is required!'); document.getElementById('cu_item_label')?.focus(); return; }

    const units = getCustomUnits();
    const key   = unitName.toLowerCase();
    units[key]  = { unitName, label: itemLabel, labelPlural: itemPlural || (itemLabel + 's'), perLabel: unitName };
    saveCustomUnitsToStorage(units);
    injectCustomUnitsIntoDropdown();
    renderCustomUnitsList();

    const sel = document.getElementById('f_unit_type');
    if (sel) sel.value = unitName;
    updatePreview();

    document.getElementById('cu_unit_name').value   = '';
    document.getElementById('cu_item_label').value  = '';
    document.getElementById('cu_item_plural').value = '';
    document.getElementById('cu_test_count').value  = '10';
    updateUnitPreview();

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

// ═══════════════════════════════════════════════════════════════════════════
// Submit: single-submit protection
// ═══════════════════════════════════════════════════════════════════════════
const editForm  = document.getElementById('editProductForm');
const submitBtn = document.getElementById('submitBtn');
if (editForm && submitBtn) {
    editForm.addEventListener('submit', function() {
        if (!submitBtn.disabled) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + (submitBtn.dataset.loading || 'Saving...');
        }
    });
}

// Auto-dismiss alerts after 5 seconds
document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function(el) {
    setTimeout(function() {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
        if (bsAlert) bsAlert.close();
    }, 5000);
});

// Bootstrap tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
    new bootstrap.Tooltip(el, { trigger: 'hover' });
});

// Init: inject custom units from localStorage + run preview
injectCustomUnitsIntoDropdown();
updatePreview();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>