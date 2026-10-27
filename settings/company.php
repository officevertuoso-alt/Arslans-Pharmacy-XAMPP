<?php
// === FILE: pharmacy_software/settings/company.php ===
// STEP 14 Enhanced — Pharmacy Info Settings with settings sub-nav & breadcrumb

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/settings_functions.php';

requireAdmin();

// ─────────────────────────────────────────────
// AJAX SAVE HANDLER
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_save'])) {
    header('Content-Type: application/json');
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Refresh and retry.']);
        exit;
    }
    $result = updateCompanySettings($_POST);
    echo json_encode($result);
    exit;
}

// ─────────────────────────────────────────────
// PAGE LOAD
// ─────────────────────────────────────────────
$settings   = getCompanySettings();
$csrf_token = generateCSRFToken();
$pageTitle  = 'Pharmacy Settings';

$timezones = array_merge(
    ['Asia/Karachi','Asia/Kolkata','Asia/Dhaka','Asia/Colombo','Asia/Kabul'],
    DateTimeZone::listIdentifiers(DateTimeZone::ASIA)
);
$timezones = array_unique($timezones);

$_pageSettings = $settings;
$_darkClass    = !empty($_pageSettings['dark_mode']) ? ' dark-mode' : '';
$_pageTitle    = 'Pharmacy Settings — ' . htmlspecialchars(
    trim($_pageSettings['company_name'] ?? $_pageSettings['pharmacy_name'] ?? 'Pharmacy MS') ?: 'Pharmacy MS'
);
$_faviconTag = '';
if (!empty($_pageSettings['favicon_path'])) {
    $_favFull = ROOT_PATH . ltrim($_pageSettings['favicon_path'], '/');
    $_favCb   = file_exists($_favFull) ? filemtime($_favFull) : time();
    $_faviconTag = '<link rel="icon" type="image/x-icon" href="'
        . htmlspecialchars(BASE_URL . ltrim($_pageSettings['favicon_path'], '/'))
        . '?v=' . $_favCb . '">';
}

// Current settings page identifier for sub-nav active state
$_current_settings_page = 'company';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $_pageTitle; ?></title>
    <?php echo $_faviconTag; ?>
    <meta name="application-name" content="<?php echo htmlspecialchars(trim($_pageSettings['company_name'] ?? 'Pharmacy MS')); ?>">
    <?php echo function_exists('generateDynamicCSS') ? generateDynamicCSS() : ''; ?>
    
    
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .main-content { margin-left: var(--sb-w, 260px); padding: 24px; transition: margin-left .25s; }
        @media(max-width:768px){ .main-content { margin-left: 0 !important; } }

        /* ── Settings Sub-Navigation ── */
        .settings-subnav {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, rgba(0,0,0,.08));
            border-radius: 12px;
            padding: 8px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            box-shadow: 0 1px 6px rgba(0,0,0,.05);
        }
        .settings-subnav .snav-link {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: .82rem;
            font-weight: 500;
            color: var(--pharmacy-text, #6c757d);
            text-decoration: none;
            transition: all .15s ease;
            white-space: nowrap;
        }
        .settings-subnav .snav-link:hover {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 8%, var(--pharmacy-card-bg, #fff));
            color: var(--pharmacy-primary, #0d6efd);
        }
        .settings-subnav .snav-link.active {
            background: linear-gradient(135deg, var(--pharmacy-accent, #667eea), var(--pharmacy-primary, #0d6efd));
            color: #fff !important;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
        }
        .settings-subnav .snav-link i { font-size: .85rem; }

        /* ── Breadcrumb bar ── */
        .page-breadcrumb {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, rgba(0,0,0,.07));
            border-radius: 10px;
            padding: 8px 16px;
            margin-bottom: 20px;
            font-size: .8rem;
            color: color-mix(in srgb, var(--pharmacy-text, #444) 70%, transparent);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .page-breadcrumb a { color: var(--pharmacy-primary, #0d6efd); text-decoration: none; }
        .page-breadcrumb a:hover { text-decoration: underline; }
        .page-breadcrumb .bc-sep { opacity: .4; font-size: .7rem; }
        .page-breadcrumb .bc-current { color: var(--pharmacy-heading, #1a1d23); font-weight: 600; }

        /* ── Card box ── */
        .card-box {
            border: 1px solid var(--pharmacy-border, rgba(0,0,0,.08)); border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
            background: var(--pharmacy-card-bg, #fff); margin-bottom: 24px;
            color: var(--pharmacy-text, #444);
            transition: box-shadow .2s;
        }
        .card-box:hover { box-shadow: 0 4px 18px rgba(0,0,0,.09); }
        .section-header {
            background: linear-gradient(135deg, var(--pharmacy-accent, #667eea), var(--pharmacy-primary, #764ba2));
            color: #fff; border-radius: 12px 12px 0 0;
            padding: 14px 20px; font-weight: 600; font-size: 15px;
        }
        .btn-gradient {
            background: linear-gradient(135deg, var(--pharmacy-accent, #11998e), #38ef7d);
            color:#fff; border:none; border-radius:10px; padding:9px 20px;
            transition: filter .2s;
        }
        .btn-gradient:hover { filter: brightness(.92); color:#fff; }
        .sticky-save-bar {
            position: sticky; bottom: 20px; z-index: 1020;
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, rgba(0,0,0,.08));
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.1);
            padding: 12px 20px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .nav-tabs .nav-link { color: var(--pharmacy-text, #6c757d); font-size: .875rem; }
        .nav-tabs .nav-link.active { font-weight: 600; color: var(--pharmacy-primary, #0d6efd) !important; }

        .logo-upload-area {
            border: 2px dashed var(--pharmacy-border, #dee2e6); border-radius: 12px;
            padding: 1.5rem; text-align: center; cursor: pointer;
            transition: border-color .2s, background .2s;
            background: color-mix(in srgb, var(--pharmacy-body-bg, #f8f9fa) 80%, white);
        }
        .logo-upload-area:hover, .logo-upload-area.dragover {
            border-color: var(--pharmacy-primary, #0d6efd);
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 6%, var(--pharmacy-card-bg, #fff));
        }
        .logo-preview-wrap { position: relative; display: inline-block; }
        .logo-preview-wrap img {
            max-height: 120px; max-width: 260px;
            border-radius: 8px; border: 1px solid var(--pharmacy-border, #e9ecef);
            padding: 4px; background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .btn-remove-asset {
            position: absolute; top: -8px; right: -8px;
            border-radius: 50%; width: 22px; height: 22px;
            font-size: 10px; padding: 0; line-height: 22px; text-align: center;
        }
        .color-picker-wrap { display: flex; align-items: center; gap: .5rem; }
        .color-picker-wrap input[type="color"] {
            width: 40px; height: 36px; border: 1px solid var(--pharmacy-border, #dee2e6);
            border-radius: 6px; padding: 2px; cursor: pointer;
        }
        .color-hex-label {
            font-family: monospace; font-size: .85rem;
            background: color-mix(in srgb, var(--pharmacy-body-bg, #f8f9fa) 80%, white);
            border: 1px solid var(--pharmacy-border, #dee2e6); border-radius: 6px;
            padding: 4px 10px; min-width: 80px; color: var(--pharmacy-text, #444);
        }
        #brandingPreview { border-radius: 10px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,.12); }
        #previewSidebar  { background: var(--preview-sidebar, #212529); padding: 1rem .75rem; min-height: 200px; }
        #previewTopbar   { background: var(--preview-primary, #0d6efd); padding: .5rem 1rem; color: #fff; font-size: .8rem; }
        #previewBtn      { background: var(--preview-accent, #198754); color:#fff; border:none; border-radius:5px; padding:4px 12px; font-size:.8rem; margin-top:.5rem; }
    </style>
</head>
<body class="bg-light<?php echo $_darkClass; ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<!-- SAVE TOAST -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:9999">
    <div id="settingsToast" class="toast align-items-center text-white border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body fw-semibold" id="toastMessage">Settings saved!</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<div class="main-content">
<div class="container-fluid" style="max-width:1200px; padding-top:80px;">

    <!-- Breadcrumb -->
    <div class="page-breadcrumb">
        <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <i class="fas fa-chevron-right bc-sep"></i>
        <span>Settings</span>
        <i class="fas fa-chevron-right bc-sep"></i>
        <span class="bc-current">Pharmacy Info</span>
    </div>

    <!-- Settings Sub-Navigation -->
    <div class="settings-subnav">
        <a href="company.php" class="snav-link active">
            <i class="fas fa-hospital"></i> Pharmacy Info
        </a>
        <a href="theme_editor_v2.php" class="snav-link">
            <i class="fas fa-palette"></i> Theme Editor
        </a>
        <a href="preferences.php" class="snav-link">
            <i class="fas fa-sliders-h"></i> Preferences
        </a>
        <a href="backup.php" class="snav-link">
            <i class="fas fa-database"></i> Backup &amp; Restore
        </a>
        <a href="profile.php" class="snav-link">
            <i class="fas fa-user-circle"></i> My Profile
        </a>
        <a href="change_password.php" class="snav-link">
            <i class="fas fa-key"></i> Change Password
        </a>
        <a href="factory_reset.php" class="snav-link" style="color:#e74c3c !important;">
            <i class="fas fa-exclamation-triangle"></i> Factory Reset
        </a>
    </div>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-1" style="color:var(--pharmacy-heading,#1a1d23);">
                <i class="fas fa-cog text-primary me-2"></i>Pharmacy Settings
            </h4>
            <p class="text-muted mb-0">Manage all pharmacy configuration — updates all pages instantly</p>
        </div>
        <a href="../dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Dashboard
        </a>
    </div>

    <form id="settingsForm" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="ajax_save"  value="1">
        <input type="hidden" name="remove_logo"    id="removeLogo"    value="0">
        <input type="hidden" name="remove_favicon" id="removeFavicon" value="0">

        <!-- TAB BAR -->
        <ul class="nav nav-tabs mb-4" id="settingsTabs">
            <li class="nav-item">
                <button class="nav-link active" type="button" data-bs-toggle="tab" data-bs-target="#tab1">
                    <i class="fas fa-hospital me-1"></i>Pharmacy Info
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#tab2">
                    <i class="fas fa-palette me-1"></i>Branding
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#tab3">
                    <i class="fas fa-file-invoice me-1"></i>Invoice &amp; Receipt
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#tab4">
                    <i class="fas fa-sliders-h me-1"></i>System
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#tab5">
                    <i class="fas fa-shield-alt me-1"></i>Security
                </button>
            </li>
            <li class="nav-item ms-auto">
                <a href="theme_editor_v2.php" class="btn btn-sm" style="background:linear-gradient(135deg,var(--pharmacy-accent,#667eea),var(--pharmacy-primary,#0d6efd));color:#fff;border:none;padding:7px 16px;border-radius:8px;font-weight:600;white-space:nowrap;">
                    <i class="fas fa-palette me-1"></i>Theme Editor
                </a>
            </li>
        </ul>

        <div class="tab-content">

        <!-- ════ TAB 1 — PHARMACY INFO ════ -->
        <div class="tab-pane fade show active" id="tab1">
            <div class="card-box mb-4">
                <div class="section-header">
                    <i class="fas fa-hospital me-2"></i>Pharmacy Information
                </div>
                <div class="p-4">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">
                                Pharmacy Name <span class="text-danger">*</span>
                                <small class="text-muted fw-normal">(appears on all pages, invoices)</small>
                            </label>
                            <input type="text" name="company_name" class="form-control form-control-lg" required
                                   id="inputPharmacyName"
                                   value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Pharmacy Type</label>
                            <select name="pharmacy_type" class="form-select">
                                <?php foreach (['pharmacy'=>'Pharmacy','medical_store'=>'Medical Store','dispensary'=>'Dispensary','chemist'=>'Chemist'] as $v => $l): ?>
                                    <option value="<?= $v ?>" <?= ($settings['pharmacy_type'] ?? 'pharmacy') === $v ? 'selected' : '' ?>>
                                        <?= $l ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">
                                Tagline / Slogan
                                <small class="text-muted fw-normal">(shown below name on invoices)</small>
                            </label>
                            <input type="text" name="pharmacy_tagline" class="form-control"
                                   placeholder="Your Health, Our Priority"
                                   value="<?= htmlspecialchars($settings['pharmacy_tagline'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Phone Number <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" required
                                   value="<?= htmlspecialchars($settings['phone'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Email Address</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($settings['email'] ?? '') ?>">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Address</label>
                            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($settings['address'] ?? '') ?></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">GST Number</label>
                            <input type="text" name="gst_no" class="form-control"
                                   value="<?= htmlspecialchars($settings['gst_no'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">TIN Number</label>
                            <input type="text" name="tin_no" class="form-control"
                                   value="<?= htmlspecialchars($settings['tin_no'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">License Number</label>
                            <input type="text" name="license_number" class="form-control"
                                   value="<?= htmlspecialchars($settings['license_number'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Owner / Manager Name</label>
                            <input type="text" name="owner_name" class="form-control"
                                   value="<?= htmlspecialchars($settings['owner_name'] ?? '') ?>">
                        </div>

                    </div>
                </div>
            </div>
        </div><!-- /tab1 -->

        <!-- ════ TAB 2 — BRANDING & APPEARANCE ════ -->
        <div class="tab-pane fade" id="tab2">
            <div class="row g-4">
                <div class="col-lg-7">

                    <!-- Logo card -->
                    <div class="card-box mb-4">
                        <div class="section-header">
                            <i class="fas fa-image me-2"></i>Pharmacy Logo
                        </div>
                        <div class="p-4">
                            <p class="text-muted small mb-3">JPEG, PNG, WebP · Max 2 MB · Max 800×800 px</p>
                            <div class="logo-upload-area" id="logoDropzone">
                                <div id="logoPreviewWrap" class="<?= empty($settings['logo_path']) ? 'd-none' : '' ?> mb-2">
                                    <div class="logo-preview-wrap">
                                        <img id="logoPreviewImg"
                                             src="<?= !empty($settings['logo_path'])
                                                 ? htmlspecialchars((defined('BASE_URL') ? BASE_URL : '../') . $settings['logo_path'] . '?v=' . time())
                                                 : '' ?>"
                                             alt="Pharmacy Logo">
                                        <button type="button" class="btn btn-danger btn-sm btn-remove-asset"
                                                id="removeLogoBtn" title="Remove logo">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <div id="logoUploadPrompt" class="<?= !empty($settings['logo_path']) ? 'd-none' : '' ?>">
                                    <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                    <p class="mb-0 text-muted">Click or drag &amp; drop logo here</p>
                                </div>
                                <input type="file" name="company_logo" id="logoInput" accept="image/*" class="d-none">
                            </div>
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" name="show_logo_on_sidebar"
                                       id="showLogoSidebar" value="1"
                                       <?= (!isset($settings['show_logo_on_sidebar']) || !empty($settings['show_logo_on_sidebar'])) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="showLogoSidebar">Show logo in sidebar</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="show_logo_on_invoice"
                                       id="showLogoInvoice" value="1"
                                       <?= !empty($settings['show_logo_on_invoice']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="showLogoInvoice">Show logo on invoices</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="show_tagline_on_invoice"
                                       id="showTaglineInvoice" value="1"
                                       <?= !empty($settings['show_tagline_on_invoice']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="showTaglineInvoice">Show tagline on invoices</label>
                            </div>
                        </div>
                    </div>

                    <!-- Favicon card -->
                    <div class="card-box mb-4">
                        <div class="section-header">
                            <i class="fas fa-star me-2"></i>Favicon (Browser Tab Icon)
                        </div>
                        <div class="p-4">
                            <p class="text-muted small mb-3">PNG or ICO · 32×32 or 64×64 · Max 512 KB</p>
                            <div class="logo-upload-area" id="faviconDropzone">
                                <div id="faviconPreviewWrap" class="<?= empty($settings['favicon_path']) ? 'd-none' : '' ?> mb-2">
                                    <div class="logo-preview-wrap">
                                        <img id="faviconPreviewImg"
                                             src="<?= !empty($settings['favicon_path'])
                                                 ? htmlspecialchars((defined('BASE_URL') ? BASE_URL : '../') . $settings['favicon_path'] . '?v=' . time())
                                                 : '' ?>"
                                             alt="Favicon" style="max-height:64px;max-width:64px;">
                                        <button type="button" class="btn btn-danger btn-sm btn-remove-asset"
                                                id="removeFaviconBtn" title="Remove favicon">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <div id="faviconUploadPrompt" class="<?= !empty($settings['favicon_path']) ? 'd-none' : '' ?>">
                                    <i class="fas fa-star fa-2x text-muted mb-2"></i>
                                    <p class="mb-0 text-muted">Click or drag favicon here</p>
                                </div>
                                <input type="file" name="company_favicon" id="faviconInput" accept=".png,.ico" class="d-none">
                            </div>
                        </div>
                    </div>

                    <!-- Colors card -->
                    <div class="card-box mb-4">
                        <div class="section-header">
                            <i class="fas fa-palette me-2"></i>Theme Colors
                        </div>
                        <div class="p-4">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Primary Color</label>
                                    <p class="text-muted small mb-1">Buttons, header highlights</p>
                                    <div class="color-picker-wrap">
                                        <input type="color" name="primary_color" id="primaryColor"
                                               value="<?= htmlspecialchars($settings['primary_color'] ?? '#0d6efd') ?>">
                                        <span class="color-hex-label" id="primaryColorHex"><?= htmlspecialchars($settings['primary_color'] ?? '#0d6efd') ?></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Sidebar Color</label>
                                    <p class="text-muted small mb-1">Left navigation bar</p>
                                    <div class="color-picker-wrap">
                                        <input type="color" name="sidebar_color" id="sidebarColor"
                                               value="<?= htmlspecialchars($settings['sidebar_color'] ?? '#212529') ?>">
                                        <span class="color-hex-label" id="sidebarColorHex"><?= htmlspecialchars($settings['sidebar_color'] ?? '#212529') ?></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Accent Color</label>
                                    <p class="text-muted small mb-1">Success badges, accents</p>
                                    <div class="color-picker-wrap">
                                        <input type="color" name="accent_color" id="accentColor"
                                               value="<?= htmlspecialchars($settings['accent_color'] ?? '#198754') ?>">
                                        <span class="color-hex-label" id="accentColorHex"><?= htmlspecialchars($settings['accent_color'] ?? '#198754') ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /col-lg-7 -->

                <!-- Right: LIVE PREVIEW -->
                <div class="col-lg-5">
                    <div class="card-box sticky-top" style="top:80px;">
                        <div class="section-header">
                            <i class="fas fa-eye me-2"></i>Live Preview
                        </div>
                        <div class="p-3">
                            <div id="brandingPreview">
                                <div class="d-flex" style="min-height:220px;">
                                    <div id="previewSidebar" style="width:110px; flex-shrink:0;">
                                        <div class="text-center mb-2">
                                            <img id="previewLogoImg"
                                                 src="<?= !empty($settings['logo_path'])
                                                     ? htmlspecialchars((defined('BASE_URL')?BASE_URL:'../') . $settings['logo_path'] . '?v=' . time())
                                                     : '../assets/img/default_logo.png' ?>"
                                                 alt="Logo"
                                                 style="max-height:40px;max-width:90px;border-radius:4px;background:rgba(255,255,255,.15);padding:3px;"
                                                 onerror="this.style.display='none'">
                                        </div>
                                        <div class="text-white text-center" style="font-size:.65rem;font-weight:700;" id="previewName">
                                            <?= htmlspecialchars($settings['company_name'] ?? 'Pharmacy') ?>
                                        </div>
                                        <div class="text-center mt-2" style="opacity:.7;">
                                            <div style="background:rgba(255,255,255,.15);border-radius:4px;margin:3px 0;padding:4px;font-size:.6rem;color:#fff;">
                                                <i class="fas fa-tachometer-alt"></i> Dashboard
                                            </div>
                                            <div style="background:rgba(255,255,255,.15);border-radius:4px;margin:3px 0;padding:4px;font-size:.6rem;color:#fff;">
                                                <i class="fas fa-shopping-cart"></i> Sales
                                            </div>
                                            <div style="background:rgba(255,255,255,.15);border-radius:4px;margin:3px 0;padding:4px;font-size:.6rem;color:#fff;">
                                                <i class="fas fa-pills"></i> Products
                                            </div>
                                        </div>
                                    </div>
                                    <div style="flex:1;">
                                        <div id="previewTopbar">
                                            <span id="previewTopName"><?= htmlspecialchars($settings['company_name'] ?? 'Pharmacy') ?></span>
                                            &nbsp;<small>| Admin</small>
                                        </div>
                                        <div style="padding:.75rem;background:#f8f9fa;">
                                            <div style="font-size:.7rem;color:#333;margin-bottom:.5rem;font-weight:600;">Quick Actions</div>
                                            <button id="previewBtn" style="background:var(--preview-accent,#198754);color:#fff;border:none;border-radius:5px;padding:4px 10px;font-size:.65rem;">
                                                + New Sale
                                            </button>
                                            <div style="margin-top:.75rem;background:#fff;border-radius:6px;padding:.5rem;font-size:.65rem;color:#666;box-shadow:0 1px 4px rgba(0,0,0,.08);">
                                                📊 Dashboard Stats Preview
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p class="text-muted text-center small mt-2 mb-0">
                                <i class="fas fa-info-circle me-1"></i>Updates live as you change colors
                            </p>
                        </div>
                    </div>
                </div>

            </div>
        </div><!-- /tab2 -->

        <!-- ════ TAB 3 — INVOICE & RECEIPT ════ -->
        <div class="tab-pane fade" id="tab3">
            <div class="card-box mb-4">
                <div class="section-header">
                    <i class="fas fa-file-invoice-dollar me-2"></i>Invoice &amp; Receipt Settings
                </div>
                <div class="p-4">
                    <div class="row g-3">

                        <div class="col-md-3">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Currency Symbol</label>
                            <input type="text" name="currency_symbol" class="form-control"
                                   maxlength="5" placeholder="Rs"
                                   value="<?= htmlspecialchars($settings['currency_symbol'] ?? 'Rs') ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Invoice Prefix</label>
                            <input type="text" name="invoice_prefix" class="form-control"
                                   maxlength="10" placeholder="INV"
                                   value="<?= htmlspecialchars($settings['invoice_prefix'] ?? 'INV') ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Receipt Paper Size</label>
                            <select name="receipt_paper_size" class="form-select">
                                <?php foreach (['A4','A5','80mm','58mm'] as $ps): ?>
                                    <option value="<?= $ps ?>" <?= ($settings['receipt_paper_size'] ?? 'A4') === $ps ? 'selected' : '' ?>>
                                        <?= $ps ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Default Tax Rate (%)</label>
                            <input type="number" name="default_tax_rate" class="form-control"
                                   step="0.01" min="0" max="100" placeholder="0.00"
                                   value="<?= htmlspecialchars($settings['default_tax_rate'] ?? '0.00') ?>">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Invoice Terms &amp; Conditions</label>
                            <textarea name="invoice_terms" class="form-control" rows="3"
                                      placeholder="e.g. Goods once sold will not be returned..."><?= htmlspecialchars($settings['invoice_terms'] ?? '') ?></textarea>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Invoice Footer Text</label>
                            <input type="text" name="invoice_footer_text" class="form-control"
                                   placeholder="Thank you for your business! Visit again."
                                   value="<?= htmlspecialchars($settings['invoice_footer_text'] ?? $settings['footer_text'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold d-block" style="color:var(--pharmacy-heading,#1a1d23);">Invoice Display Options</label>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="show_payment_on_invoice"
                                       id="showPaymentInv" value="1"
                                       <?= !empty($settings['show_payment_on_invoice']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="showPaymentInv">Show Payment Method on Invoice</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="show_batch_on_invoice"
                                       id="showBatchInv" value="1"
                                       <?= !empty($settings['show_batch_on_invoice']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="showBatchInv">Show Batch Number on Invoice</label>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div><!-- /tab3 -->

        <!-- ════ TAB 4 — SYSTEM SETTINGS ════ -->
        <div class="tab-pane fade" id="tab4">
            <div class="card-box mb-4">
                <div class="section-header">
                    <i class="fas fa-sliders-h me-2"></i>System Settings
                </div>
                <div class="p-4">
                    <div class="row g-3">

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Session Timeout</label>
                            <select name="session_timeout" class="form-select">
                                <?php foreach ([30=>'30 minutes',60=>'1 hour',120=>'2 hours',240=>'4 hours',0=>'Never'] as $v => $l): ?>
                                    <option value="<?= $v ?>" <?= (int)($settings['session_timeout'] ?? 60) === $v ? 'selected' : '' ?>>
                                        <?= $l ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">System Timezone</label>
                            <select name="system_timezone" class="form-select">
                                <?php
                                $currentTz = $settings['system_timezone'] ?? 'Asia/Karachi';
                                $asiaZones = array_filter(DateTimeZone::listIdentifiers(), fn($z) => str_starts_with($z, 'Asia/'));
                                $otherZones = array_filter(DateTimeZone::listIdentifiers(), fn($z) => !str_starts_with($z, 'Asia/'));
                                echo '<optgroup label="Asia">';
                                foreach ($asiaZones as $tz): ?>
                                    <option value="<?= $tz ?>" <?= $currentTz === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                                <?php endforeach;
                                echo '</optgroup><optgroup label="Other">';
                                foreach ($otherZones as $tz): ?>
                                    <option value="<?= $tz ?>" <?= $currentTz === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                                <?php endforeach;
                                echo '</optgroup>'; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Date Format</label>
                            <select name="date_format" class="form-select">
                                <?php
                                $currentDf = $settings['date_format'] ?? 'd M Y';
                                $dfOptions = ['d M Y'=>'31 Jan 2025','d/m/Y'=>'31/01/2025','Y-m-d'=>'2025-01-31','m/d/Y'=>'01/31/2025'];
                                foreach ($dfOptions as $v => $ex): ?>
                                    <option value="<?= $v ?>" <?= $currentDf === $v ? 'selected' : '' ?>>
                                        <?= $ex ?> (<?= $v ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Time Format</label>
                            <select name="time_format" class="form-select">
                                <option value="h:i A" <?= ($settings['time_format'] ?? 'h:i A') === 'h:i A' ? 'selected' : '' ?>>
                                    12-hour (2:30 PM)
                                </option>
                                <option value="H:i" <?= ($settings['time_format'] ?? '') === 'H:i' ? 'selected' : '' ?>>
                                    24-hour (14:30)
                                </option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Default Language</label>
                            <select name="default_language" class="form-select">
                                <option value="en" <?= ($settings['default_language'] ?? 'en') === 'en' ? 'selected' : '' ?>>
                                    🇺🇸 English
                                </option>
                                <option value="ur" <?= ($settings['default_language'] ?? '') === 'ur' ? 'selected' : '' ?>>
                                    🇵🇰 Urdu (اردو)
                                </option>
                            </select>
                            <small class="text-muted">Full Urdu support coming in a future update</small>
                        </div>

                    </div>
                </div>
            </div>
        </div><!-- /tab4 -->

        <!-- ════ TAB 5 — SECURITY ════ -->
        <div class="tab-pane fade" id="tab5">
            <div class="card-box mb-4">
                <div class="section-header">
                    <i class="fas fa-shield-alt me-2"></i>Security Settings
                    <small class="ms-2 opacity-75">(Admin only)</small>
                </div>
                <div class="p-4">
                    <div class="row g-3">

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Force Password Change After</label>
                            <select name="password_change_days" class="form-select">
                                <?php foreach ([30=>'30 days',60=>'60 days',90=>'90 days',0=>'Never'] as $v => $l): ?>
                                    <option value="<?= $v ?>" <?= (int)($settings['password_change_days'] ?? 90) === $v ? 'selected' : '' ?>>
                                        <?= $l ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Login Attempts Before Lockout</label>
                            <select name="login_attempts_limit" class="form-select">
                                <?php foreach ([3,5,10] as $v): ?>
                                    <option value="<?= $v ?>" <?= (int)($settings['login_attempts_limit'] ?? 5) === $v ? 'selected' : '' ?>>
                                        <?= $v ?> attempts
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Lockout Duration</label>
                            <select name="lockout_duration" class="form-select">
                                <?php foreach ([5=>'5 minutes',15=>'15 minutes',30=>'30 minutes',60=>'1 hour'] as $v => $l): ?>
                                    <option value="<?= $v ?>" <?= (int)($settings['lockout_duration'] ?? 15) === $v ? 'selected' : '' ?>>
                                        <?= $l ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold d-block" style="color:var(--pharmacy-heading,#1a1d23);">Two-Factor Authentication</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="two_factor_enabled"
                                       id="twoFactorToggle" value="1"
                                       <?= !empty($settings['two_factor_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="twoFactorToggle">
                                    Enable Two-Factor Authentication
                                    <span class="badge bg-warning text-dark ms-2">Coming Soon</span>
                                </label>
                            </div>
                            <small class="text-muted">2FA via TOTP app (Google Authenticator) — will be activated in a future release.</small>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">
                                IP Whitelist
                                <small class="text-muted fw-normal">(one IP per line — leave blank to allow all)</small>
                            </label>
                            <?php
                            $_ipVal = $settings['ip_whitelist'] ?? '';
                            $_ipPlaceholder = "192.168.1.100\n192.168.1.101\n203.0.113.0/24";
                            if (trim($_ipVal) === trim($_ipPlaceholder)) $_ipVal = '';
                            ?>
                            <textarea name="ip_whitelist" class="form-control font-monospace" rows="4"
                                      placeholder="192.168.1.100&#10;192.168.1.101&#10;203.0.113.0/24"><?= htmlspecialchars($_ipVal) ?></textarea>
                            <div class="alert alert-warning mt-2 py-2 small">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                <strong>Warning:</strong> If you add your IP here and your IP changes, you may be locked out.
                                Your current IP: <strong><?= htmlspecialchars(function_exists('getClientIP') ? getClientIP() : ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown')) ?></strong>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div><!-- /tab5 -->

        </div><!-- /tab-content -->

        <!-- STICKY SAVE BAR -->
        <div class="sticky-save-bar">
            <span class="text-muted small">
                <i class="fas fa-info-circle me-1"></i>Changes apply to <strong>all pages</strong> immediately
            </span>
            <button type="submit" class="btn btn-gradient px-5" id="btnSaveSettings">
                <i class="fas fa-save me-2"></i>Save All Settings
            </button>
        </div>

    </form>
</div><!-- /container -->
</div><!-- /main-content -->


<script>
'use strict';

// AJAX FORM SAVE
document.getElementById('settingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn  = document.getElementById('btnSaveSettings');
    const orig = btn.innerHTML;
    btn.disabled    = true;
    btn.innerHTML   = '<span class="spinner-border spinner-border-sm me-2"></span>Saving…';
    const fd = new FormData(this);
    fetch('company.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(resp => {
        btn.disabled  = false;
        btn.innerHTML = orig;
        if (resp.success) {
            showToast('✅ ' + (resp.message || 'Settings saved — all pages updated'), 'success');
            applyLiveUpdates(resp);
        } else {
            showToast('❌ ' + (resp.message || 'Save failed'), 'danger');
        }
    })
    .catch(err => {
        btn.disabled  = false;
        btn.innerHTML = orig;
        showToast('❌ Network error: ' + err.message, 'danger');
    });
});

// APPLY LIVE UPDATES
function applyLiveUpdates(resp) {
    if (resp.updated_name) {
        document.querySelectorAll('.pharmacy-name, .sidebar-pharmacy-name, #sidebarPharmacyName, .sb-name, [data-pharmacy-name]').forEach(el => { el.textContent = resp.updated_name; });
        document.querySelectorAll('.navbar-pharmacy-name, #navPharmacyName, .nav-pharmacy-name').forEach(el => { el.textContent = resp.updated_name; });
        document.title = 'Pharmacy Settings — ' + resp.updated_name;
        const metaApp = document.querySelector('meta[name="application-name"]');
        if (metaApp) metaApp.content = resp.updated_name;
    }
    const showInSidebar = resp.show_logo_on_sidebar;
    const logoWrap      = document.querySelectorAll('.sb-logo-wrap, #sbBrandMini');
    if (resp.updated_logo && showInSidebar) {
        logoWrap.forEach(wrap => {
            wrap.querySelectorAll('i.fa-hospital, i.fas').forEach(i => i.remove());
            let img = wrap.querySelector('img');
            if (!img) {
                img = document.createElement('img');
                img.className = 'pharmacy-sidebar-logo d-block mx-auto';
                img.style.maxHeight = '48px';
                wrap.appendChild(img);
            }
            img.src = resp.updated_logo;
            img.style.display = 'block';
        });
        const prevLogo = document.getElementById('previewLogoImg');
        if (prevLogo) { prevLogo.src = resp.updated_logo; prevLogo.style.display = 'block'; }
    } else if (!showInSidebar || resp.updated_logo === '') {
        logoWrap.forEach(wrap => {
            wrap.querySelectorAll('img').forEach(i => i.style.display = 'none');
            if (!wrap.querySelector('i.fas')) {
                const icon = document.createElement('i');
                icon.className = 'fas fa-hospital fa-2x text-primary';
                wrap.appendChild(icon);
            }
        });
    }
    if (resp.updated_favicon) {
        let link = document.querySelector("link[rel~='icon']");
        if (!link) { link = document.createElement('link'); link.rel = 'icon'; document.head.appendChild(link); }
        link.href = resp.updated_favicon;
    }
    const root = document.documentElement;
    if (resp.primary_color) { root.style.setProperty('--pharmacy-primary', resp.primary_color); }
    if (resp.sidebar_color) {
        root.style.setProperty('--pharmacy-sidebar', resp.sidebar_color);
        const sb = document.getElementById('mainSidebar');
        if (sb) sb.style.setProperty('background', resp.sidebar_color, 'important');
    }
    if (resp.accent_color) { root.style.setProperty('--pharmacy-accent', resp.accent_color); }
    if (typeof window.COMPANY !== 'undefined') {
        if (resp.updated_name)    window.COMPANY.name    = resp.updated_name;
        if (resp.tagline != null) window.COMPANY.tagline = resp.tagline;
        if (resp.address != null) window.COMPANY.address = resp.address;
        if (resp.phone   != null) window.COMPANY.phone   = resp.phone;
        if (resp.email   != null) window.COMPANY.email   = resp.email;
        if (resp.gst_no  != null) window.COMPANY.gst_no  = resp.gst_no;
        if (resp.currency         != null) { window.COMPANY.currency = resp.currency; window.CURRENCY = resp.currency; }
        if (resp.invoice_prefix   != null) window.COMPANY.invoice_prefix   = resp.invoice_prefix;
        if (resp.footer_text      != null) window.COMPANY.footer_text      = resp.footer_text;
        if (resp.invoice_terms    != null) window.COMPANY.invoice_terms    = resp.invoice_terms;
        if (resp.show_payment     != null) window.COMPANY.show_payment     = !!resp.show_payment;
        if (resp.show_batch       != null) window.COMPANY.show_batch       = !!resp.show_batch;
        if (resp.show_tagline     != null) window.COMPANY.show_tagline     = !!resp.show_tagline;
        if (resp.show_logo        != null) window.COMPANY.show_logo        = !!resp.show_logo;
        if (resp.updated_logo)            window.COMPANY.logo_url          = resp.updated_logo;
        if (resp.primary_color    != null) window.COMPANY.primary_color    = resp.primary_color;
        if (resp.accent_color     != null) window.COMPANY.accent_color     = resp.accent_color;
    }
}

function showToast(message, type) {
    const toastEl = document.getElementById('settingsToast');
    const msgEl   = document.getElementById('toastMessage');
    toastEl.className = 'toast align-items-center text-white border-0 bg-' + (type || 'success');
    msgEl.textContent = message;
    bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4000 }).show();
}

// LOGO UPLOAD & PREVIEW
(function() {
    const logoInput        = document.getElementById('logoInput');
    const logoDropzone     = document.getElementById('logoDropzone');
    const logoPreviewWrap  = document.getElementById('logoPreviewWrap');
    const logoPreviewImg   = document.getElementById('logoPreviewImg');
    const logoUploadPrompt = document.getElementById('logoUploadPrompt');
    const removeLogo       = document.getElementById('removeLogo');
    const removeLogoBtn    = document.getElementById('removeLogoBtn');
    const previewLogoImg   = document.getElementById('previewLogoImg');

    logoDropzone.addEventListener('click', e => { if (!e.target.closest('#removeLogoBtn')) logoInput.click(); });
    logoDropzone.addEventListener('dragover', e => { e.preventDefault(); logoDropzone.classList.add('dragover'); });
    logoDropzone.addEventListener('dragleave', () => logoDropzone.classList.remove('dragover'));
    logoDropzone.addEventListener('drop', e => {
        e.preventDefault();
        logoDropzone.classList.remove('dragover');
        if (e.dataTransfer.files[0]) { logoInput.files = e.dataTransfer.files; previewLogo(e.dataTransfer.files[0]); }
    });
    logoInput.addEventListener('change', function() { if (this.files && this.files[0]) previewLogo(this.files[0]); });

    function previewLogo(file) {
        if (!['image/jpeg','image/png','image/webp','image/gif'].includes(file.type)) {
            Notify.warning('Please select JPEG, PNG, WebP, or GIF.'); logoInput.value = ''; return;
        }
        if (file.size > 2*1024*1024) {
            Notify.error('File must not exceed 2 MB.'); logoInput.value = ''; return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                if (img.width > 800 || img.height > 800) {
                    Notify.error(`Logo must not exceed 800×800 px. Got ${img.width}×${img.height} px.`);
                    logoInput.value = ''; return;
                }
                logoPreviewImg.src = e.target.result;
                if (previewLogoImg) previewLogoImg.src = e.target.result;
                logoPreviewWrap.classList.remove('d-none');
                logoUploadPrompt.classList.add('d-none');
                removeLogo.value = '0';
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }

    removeLogoBtn.addEventListener('click', e => {
        e.stopPropagation();
        logoPreviewImg.src = '';
        if (previewLogoImg) previewLogoImg.src = '';
        logoPreviewWrap.classList.add('d-none');
        logoUploadPrompt.classList.remove('d-none');
        logoInput.value  = '';
        removeLogo.value = '1';
    });
})();

// FAVICON UPLOAD & PREVIEW
(function() {
    const faviconInput        = document.getElementById('faviconInput');
    const faviconDropzone     = document.getElementById('faviconDropzone');
    const faviconPreviewWrap  = document.getElementById('faviconPreviewWrap');
    const faviconPreviewImg   = document.getElementById('faviconPreviewImg');
    const faviconUploadPrompt = document.getElementById('faviconUploadPrompt');
    const removeFavicon       = document.getElementById('removeFavicon');
    const removeFaviconBtn    = document.getElementById('removeFaviconBtn');

    faviconDropzone.addEventListener('click', e => { if (!e.target.closest('#removeFaviconBtn')) faviconInput.click(); });
    faviconDropzone.addEventListener('dragover', e => { e.preventDefault(); faviconDropzone.classList.add('dragover'); });
    faviconDropzone.addEventListener('dragleave', () => faviconDropzone.classList.remove('dragover'));
    faviconDropzone.addEventListener('drop', e => {
        e.preventDefault();
        faviconDropzone.classList.remove('dragover');
        if (e.dataTransfer.files[0]) { faviconInput.files = e.dataTransfer.files; previewFavicon(e.dataTransfer.files[0]); }
    });
    faviconInput.addEventListener('change', function() { if (this.files && this.files[0]) previewFavicon(this.files[0]); });

    function previewFavicon(file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            faviconPreviewImg.src = e.target.result;
            faviconPreviewWrap.classList.remove('d-none');
            faviconUploadPrompt.classList.add('d-none');
            removeFavicon.value = '0';
            let link = document.querySelector("link[rel='icon']");
            if (!link) { link = document.createElement('link'); link.rel = 'icon'; document.head.appendChild(link); }
            link.href = e.target.result;
        };
        reader.readAsDataURL(file);
    }

    removeFaviconBtn.addEventListener('click', e => {
        e.stopPropagation();
        faviconPreviewImg.src = '';
        faviconPreviewWrap.classList.add('d-none');
        faviconUploadPrompt.classList.remove('d-none');
        faviconInput.value     = '';
        removeFavicon.value    = '1';
    });
})();

// COLOR PICKERS — LIVE PREVIEW
(function() {
    const root = document.documentElement;
    function setupColorPicker(inputId, hexLabelId) {
        const input    = document.getElementById(inputId);
        const hexLabel = document.getElementById(hexLabelId);
        if (!input) return;
        input.addEventListener('input', function() {
            const color = this.value;
            if (hexLabel) hexLabel.textContent = color;
            if (inputId === 'sidebarColor') {
                document.getElementById('previewSidebar').style.background = color;
            } else if (inputId === 'primaryColor') {
                document.getElementById('previewTopbar').style.background = color;
                root.style.setProperty('--pharmacy-primary', color);
            } else if (inputId === 'accentColor') {
                document.getElementById('previewBtn').style.background = color;
                root.style.setProperty('--pharmacy-accent', color);
            }
        });
    }
    setupColorPicker('primaryColor', 'primaryColorHex');
    setupColorPicker('sidebarColor', 'sidebarColorHex');
    setupColorPicker('accentColor',  'accentColorHex');
})();

// LIVE PREVIEW — pharmacy name update
document.getElementById('inputPharmacyName').addEventListener('input', function() {
    const name = this.value || 'Pharmacy';
    document.getElementById('previewName').textContent    = name;
    document.getElementById('previewTopName').textContent = name;
});

// Auto-dismiss alerts
document.querySelectorAll('.alert:not(.alert-permanent)').forEach(el => {
    setTimeout(() => { const a = bootstrap.Alert.getOrCreateInstance(el); if (a) a.close(); }, 5000);
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
