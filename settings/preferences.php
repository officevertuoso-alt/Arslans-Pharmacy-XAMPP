<?php
// === FILE: pharmacy_software/settings/preferences.php ===
// STEP 14 Enhanced — System Preferences with settings sub-nav & breadcrumb

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/settings_functions.php';

requireAdmin();

$page_title = 'System Preferences - Pharmacy Management System';
$error = '';
$success = '';

$prefs = getSystemPreferences();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $data = [
            'low_stock_threshold'        => (int)($_POST['low_stock_threshold'] ?? 10),
            'near_expiry_days'           => (int)($_POST['near_expiry_days'] ?? 30),
            'invoice_prefix'             => sanitize($_POST['invoice_prefix'] ?? 'INV'),
            'purchase_prefix'            => sanitize($_POST['purchase_prefix'] ?? 'PUR'),
            'invoice_start_number'       => (int)($_POST['invoice_start_number'] ?? 1),
            'enable_gst'                 => isset($_POST['enable_gst']) ? '1' : '0',
            'gst_percentage'             => (float)($_POST['gst_percentage'] ?? 0),
            'gst_number'                 => sanitize($_POST['gst_number'] ?? ''),
            'date_format'                => sanitize($_POST['date_format'] ?? 'd/m/Y'),
            'currency_symbol'            => sanitize($_POST['currency_symbol'] ?? 'Rs'),
            'decimal_places'             => (int)($_POST['decimal_places'] ?? 2),
            'enable_email_notifications' => isset($_POST['enable_email_notifications']) ? '1' : '0',
            'dark_mode'        => isset($_POST['dark_mode']) ? 1 : 0,
            'theme_preset'     => sanitize($_POST['theme_preset'] ?? 'default'),
            'body_font_family' => sanitize($_POST['body_font_family'] ?? 'Segoe UI'),
            'body_font_size'   => max(11, min(20, (int)($_POST['body_font_size'] ?? 14))),
            'primary_color'    => preg_match('/^#[0-9A-Fa-f]{3,6}$/', $_POST['primary_color'] ?? '') ? $_POST['primary_color'] : '#0d6efd',
            'accent_color'     => preg_match('/^#[0-9A-Fa-f]{3,6}$/', $_POST['accent_color'] ?? '') ? $_POST['accent_color'] : '#667eea',
            'sidebar_color'    => preg_match('/^#[0-9A-Fa-f]{3,6}$/', $_POST['sidebar_color'] ?? '') ? $_POST['sidebar_color'] : '#1a1d23',
            'body_bg_color'    => preg_match('/^#[0-9A-Fa-f]{3,6}$/', $_POST['body_bg_color'] ?? '') ? $_POST['body_bg_color'] : '#f0f2f5',
            'card_bg_color'    => preg_match('/^#[0-9A-Fa-f]{3,6}$/', $_POST['card_bg_color'] ?? '') ? $_POST['card_bg_color'] : '#ffffff',
            'body_text_color'  => preg_match('/^#[0-9A-Fa-f]{3,6}$/', $_POST['body_text_color'] ?? '') ? $_POST['body_text_color'] : '#444444',
            'heading_color'    => preg_match('/^#[0-9A-Fa-f]{3,6}$/', $_POST['heading_color'] ?? '') ? $_POST['heading_color'] : '#1a1d23',
        ];

        if ($data['low_stock_threshold'] < 1) {
            $error = 'Low stock threshold must be at least 1.';
        } elseif ($data['near_expiry_days'] < 1) {
            $error = 'Near expiry days must be at least 1.';
        } elseif ($data['gst_percentage'] < 0 || $data['gst_percentage'] > 100) {
            $error = 'GST percentage must be between 0 and 100.';
        } elseif ($data['decimal_places'] < 0 || $data['decimal_places'] > 4) {
            $error = 'Decimal places must be between 0 and 4.';
        } else {
            if (updateSystemPreferences($data) && (function_exists('updateCompanySettings') ? updateCompanySettings($data) : true)) {
                logActivity('update_preferences', 'System preferences updated');
                $success = 'System preferences saved successfully!';
                unset($_SESSION['company_settings_cache'], $_SESSION['company_settings_cache_time']);
                if (isset($GLOBALS['_company_settings'])) $GLOBALS['_company_settings'] = null;
                $prefs = getSystemPreferences();
                $_SESSION['pref_currency_symbol'] = $data['currency_symbol'];
                $_SESSION['pref_date_format']      = $data['date_format'];
                $_SESSION['pref_decimal_places']   = $data['decimal_places'];
            } else {
                $error = 'Failed to save preferences. Please try again.';
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(isset($error) && $error
            ? ['success' => false, 'message' => $error]
            : ['success' => true,  'message' => isset($success) ? $success : 'Preferences saved!']
        );
        exit;
    }
}

$csrf_token = generateCSRFToken();

function pref($prefs, $key, $default = '') {
    return $prefs[$key] ?? $default;
}

$_darkClass = !empty($_companySettings['dark_mode']) ? ' dark-mode' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;600&family=Inter:wght@300;400;600&family=Poppins:wght@300;400;600&family=Open+Sans:wght@300;400;600&family=Nunito:wght@300;400;600&family=Lato:wght@300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .main-content { margin-left: var(--sb-w, 260px); padding: 24px; transition: margin-left .25s ease; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } } }

        /* ── Settings Sub-Navigation ── */
        .settings-subnav {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, rgba(0,0,0,.08));
            border-radius: 12px; padding: 8px; margin-bottom: 24px;
            display: flex; flex-wrap: wrap; gap: 4px;
            box-shadow: 0 1px 6px rgba(0,0,0,.05);
        }
        .settings-subnav .snav-link {
            display: flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 8px; font-size: .82rem; font-weight: 500;
            color: var(--pharmacy-text, #6c757d); text-decoration: none;
            transition: all .15s ease; white-space: nowrap;
        }
        .settings-subnav .snav-link:hover {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 8%, var(--pharmacy-card-bg, #fff));
            color: var(--pharmacy-primary, #0d6efd);
        }
        .settings-subnav .snav-link.active {
            background: linear-gradient(135deg, var(--pharmacy-accent, #667eea), var(--pharmacy-primary, #0d6efd));
            color: #fff !important; font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
        }

        /* ── Breadcrumb ── */
        .page-breadcrumb {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, rgba(0,0,0,.07));
            border-radius: 10px; padding: 8px 16px; margin-bottom: 20px;
            font-size: .8rem; color: color-mix(in srgb, var(--pharmacy-text, #444) 70%, transparent);
            display: flex; align-items: center; gap: 6px;
        }
        .page-breadcrumb a { color: var(--pharmacy-primary, #0d6efd); text-decoration: none; }
        .page-breadcrumb a:hover { text-decoration: underline; }
        .page-breadcrumb .bc-sep { opacity: .4; font-size: .7rem; }
        .page-breadcrumb .bc-current { color: var(--pharmacy-heading, #1a1d23); font-weight: 600; }

        /* ── Card Box ── */
        .card-box {
            border: 1px solid var(--pharmacy-border, rgba(0,0,0,.08));
            border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,.05);
            background: var(--pharmacy-card-bg, #fff); margin-bottom: 24px;
            color: var(--pharmacy-text, #444); transition: box-shadow .2s;
        }
        .card-box:hover { box-shadow: 0 4px 18px rgba(0,0,0,.09); }
        .section-header {
            background: linear-gradient(135deg, var(--pharmacy-accent, #667eea), var(--pharmacy-primary, #764ba2));
            color: #fff; border-radius: 12px 12px 0 0;
            padding: 14px 20px; font-weight: 600; font-size: 15px;
        }
        .sticky-save-bar {
            position: sticky; bottom: 20px; z-index: 1020;
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, rgba(0,0,0,.08));
            border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,.1);
            padding: 12px 20px; display: flex; justify-content: space-between; align-items: center;
        }
        .btn-gradient {
            background: linear-gradient(135deg, var(--pharmacy-accent, #11998e), #38ef7d);
            color: #fff; border: none; border-radius: 10px; padding: 9px 20px; transition: filter .2s;
        }
        .btn-gradient:hover { filter: brightness(.92); color: #fff; }
        .form-switch .form-check-input { width: 3em; height: 1.6em; cursor: pointer; }
        .form-switch .form-check-input:checked {
            background-color: var(--pharmacy-accent, #667eea);
            border-color: var(--pharmacy-accent, #667eea);
        }
        .badge-default {
            background: var(--pharmacy-table-th, #f1f3f5); color: var(--pharmacy-text, #495057);
            font-size: 11px; font-weight: 500; padding: 3px 8px; border-radius: 20px;
        }
        .preset-card {
            cursor: pointer; transition: transform .15s, box-shadow .15s;
            border-color: var(--pharmacy-border, rgba(0,0,0,.12)) !important;
            background: var(--pharmacy-card-bg, #fff); color: var(--pharmacy-text, #444);
        }
        .preset-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .preset-card.border-primary {
            border-color: var(--pharmacy-primary, #0d6efd) !important;
            box-shadow: 0 0 0 2px var(--pharmacy-primary, #0d6efd);
        }
        #livePreviewBox {
            border-color: var(--pharmacy-border, rgba(0,0,0,.08)) !important;
            border-radius: 10px; transition: all .3s;
        }
        .dark-mode input[type=range] { accent-color: var(--pharmacy-accent, #667eea); }
    </style>
</head>
<body class="bg-light<?php echo $_darkClass; ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid" style="max-width:1200px; padding-top:80px;">

    <!-- Breadcrumb -->
    <div class="page-breadcrumb">
        <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <i class="fas fa-chevron-right bc-sep"></i>
        <span>Settings</span>
        <i class="fas fa-chevron-right bc-sep"></i>
        <span class="bc-current"><i class="fas fa-sliders-h me-1"></i>Preferences</span>
    </div>

    <!-- Settings Sub-Navigation -->
    <div class="settings-subnav">
        <a href="company.php" class="snav-link">
            <i class="fas fa-hospital"></i> Pharmacy Info
        </a>
        <a href="theme_editor_v2.php" class="snav-link">
            <i class="fas fa-palette"></i> Theme Editor
        </a>
        <a href="preferences.php" class="snav-link active">
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
                <i class="fas fa-sliders-h text-primary me-2"></i>System Preferences
            </h4>
            <p class="text-muted mb-0">Configure system-wide behaviour and defaults</p>
        </div>
        <a href="company.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Back to Settings
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div id="prefsAlertArea" class="mb-3"></div>
    <form method="POST" action="" id="prefsForm"
          data-pharm-form
          data-success-msg="Preferences saved successfully!"
          data-loading-text="Saving Preferences…"
          data-alert-target="#prefsAlertArea"
          novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

        <!-- 1. STOCK SETTINGS -->
        <div class="card-box mb-4">
            <div class="section-header">
                <i class="fas fa-boxes me-2"></i>Stock Settings
            </div>
            <div class="p-4">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">
                            Low Stock Threshold
                            <span class="badge-default ms-1">Default: 10</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-cubes"></i></span>
                            <input type="number" name="low_stock_threshold" class="form-control"
                                   value="<?php echo (int)pref($prefs, 'low_stock_threshold', 10); ?>"
                                   min="1" max="9999" required>
                            <span class="input-group-text">units</span>
                        </div>
                        <small class="text-muted">Alert when stock falls below this quantity.</small>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">
                            Near Expiry Days
                            <span class="badge-default ms-1">Default: 30</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-calendar-times"></i></span>
                            <input type="number" name="near_expiry_days" class="form-control"
                                   value="<?php echo (int)pref($prefs, 'near_expiry_days', 30); ?>"
                                   min="1" max="365" required>
                            <span class="input-group-text">days</span>
                        </div>
                        <small class="text-muted">Warn when a batch expires within this many days.</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. INVOICE SETTINGS -->
        <div class="card-box mb-4">
            <div class="section-header">
                <i class="fas fa-file-invoice me-2"></i>Invoice Settings
            </div>
            <div class="p-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">
                            Invoice Prefix <span class="badge-default ms-1">Default: INV</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-tag"></i></span>
                            <input type="text" name="invoice_prefix" class="form-control text-uppercase"
                                   value="<?php echo htmlspecialchars(pref($prefs, 'invoice_prefix', 'INV')); ?>"
                                   maxlength="10" placeholder="INV">
                        </div>
                        <small class="text-muted">e.g. INV-0001</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">
                            Purchase Prefix <span class="badge-default ms-1">Default: PUR</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-tag"></i></span>
                            <input type="text" name="purchase_prefix" class="form-control text-uppercase"
                                   value="<?php echo htmlspecialchars(pref($prefs, 'purchase_prefix', 'PUR')); ?>"
                                   maxlength="10" placeholder="PUR">
                        </div>
                        <small class="text-muted">e.g. PUR-0001</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Starting Number</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                            <input type="number" name="invoice_start_number" class="form-control"
                                   value="<?php echo (int)pref($prefs, 'invoice_start_number', 1); ?>"
                                   min="1" max="999999">
                        </div>
                        <small class="text-muted">First invoice/purchase number.</small>
                    </div>
                </div>
                <div class="alert alert-light border mt-3 py-2" id="invoicePreview">
                    <small class="text-muted me-2">Preview:</small>
                    <strong id="prevInv"></strong>
                    <span class="mx-2 text-muted">·</span>
                    <strong id="prevPur"></strong>
                </div>
            </div>
        </div>

        <!-- 3. TAX / GST -->
        <div class="card-box mb-4">
            <div class="section-header">
                <i class="fas fa-percent me-2"></i>Tax / GST Settings
            </div>
            <div class="p-4">
                <div class="mb-3">
                    <div class="form-check form-switch d-flex align-items-center gap-3">
                        <input class="form-check-input" type="checkbox" name="enable_gst" id="enableGst"
                               <?php echo pref($prefs, 'enable_gst', '0') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label ms-2" for="enableGst">
                            <span class="fw-semibold">Enable GST on Invoices</span><br>
                            <span class="text-muted small">Adds GST calculation to sales invoices.</span>
                        </label>
                    </div>
                </div>
                <div id="gstFields" class="row g-3 <?php echo pref($prefs, 'enable_gst', '0') !== '1' ? 'd-none' : ''; ?>">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">GST Percentage (%)</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-percent"></i></span>
                            <input type="number" name="gst_percentage" class="form-control"
                                   value="<?php echo number_format((float)pref($prefs, 'gst_percentage', 0), 2); ?>"
                                   min="0" max="100" step="0.01" placeholder="0.00">
                        </div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">GST Registration Number</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                            <input type="text" name="gst_number" class="form-control"
                                   value="<?php echo htmlspecialchars(pref($prefs, 'gst_number', '')); ?>"
                                   maxlength="30" placeholder="Enter GST number">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. DISPLAY -->
        <div class="card-box mb-4">
            <div class="section-header">
                <i class="fas fa-display me-2"></i>Display Settings
            </div>
            <div class="p-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Date Format</label>
                        <select name="date_format" class="form-select">
                            <?php
                            $dateFormats = [
                                'd/m/Y' => date('d/m/Y') . '  (DD/MM/YYYY)',
                                'm/d/Y' => date('m/d/Y') . '  (MM/DD/YYYY)',
                                'Y-m-d' => date('Y-m-d') . '  (YYYY-MM-DD)',
                                'd-m-Y' => date('d-m-Y') . '  (DD-MM-YYYY)',
                                'd M Y' => date('d M Y') . '  (DD Mon YYYY)',
                            ];
                            $currentFmt = pref($prefs, 'date_format', 'd/m/Y');
                            foreach ($dateFormats as $val => $label): ?>
                                <option value="<?php echo htmlspecialchars($val); ?>"
                                    <?php echo $currentFmt === $val ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Currency Symbol</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-money-bill"></i></span>
                            <input type="text" name="currency_symbol" class="form-control"
                                   value="<?php echo htmlspecialchars(pref($prefs, 'currency_symbol', 'Rs')); ?>"
                                   maxlength="5" placeholder="Rs">
                        </div>
                        <small class="text-muted">Shown in reports and invoices.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Decimal Places</label>
                        <select name="decimal_places" class="form-select">
                            <?php
                            $currentDec = (int)pref($prefs, 'decimal_places', 2);
                            foreach ([0, 1, 2, 3, 4] as $d): ?>
                                <option value="<?php echo $d; ?>" <?php echo $currentDec === $d ? 'selected' : ''; ?>>
                                    <?php echo $d; ?> decimal place<?php echo $d !== 1 ? 's' : ''; ?>
                                    (<?php echo number_format(1234.5678, $d); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. NOTIFICATIONS -->
        <div class="card-box mb-4">
            <div class="section-header">
                <i class="fas fa-bell me-2"></i>Notification Settings
            </div>
            <div class="p-4">
                <div class="form-check form-switch d-flex align-items-center gap-3 mb-2">
                    <input class="form-check-input" type="checkbox" name="enable_email_notifications" id="enableEmail"
                           <?php echo pref($prefs, 'enable_email_notifications', '0') === '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label ms-2" for="enableEmail">
                        <span class="fw-semibold">Enable Email Notifications</span><br>
                        <span class="text-muted small">Send low-stock and expiry alerts via email (SMTP setup required).</span>
                    </label>
                </div>
                <div class="alert alert-light border mt-3 mb-0 py-2">
                    <i class="fas fa-info-circle text-primary me-2"></i>
                    <small>Email notification delivery will be available in a future update.</small>
                </div>
            </div>
        </div>

        <!-- 6. THEME & DISPLAY -->
        <div class="card-box mb-4">
            <div class="section-header">
                <i class="fas fa-palette me-2"></i>Theme &amp; Display
                <small class="ms-2 opacity-75">— for advanced theme control use <a href="theme_editor_v2.php" class="text-white text-decoration-underline">Theme Editor</a></small>
            </div>
            <div class="p-4">
                <div class="row g-4">

                    <!-- Dark Mode -->
                    <div class="col-12">
                        <h6 class="fw-bold mb-2" style="color:var(--pharmacy-heading,#1a1d23);">
                            <i class="fas fa-moon me-2 text-primary"></i>Dark Mode
                        </h6>
                        <div class="form-check form-switch d-flex align-items-center gap-3">
                            <input class="form-check-input" type="checkbox" id="darkModeToggle"
                                   name="dark_mode" value="1"
                                   <?php echo !empty($_companySettings['dark_mode']) ? 'checked' : ''; ?>>
                            <label class="form-check-label ms-2" for="darkModeToggle">
                                <span class="fw-semibold">Enable Dark Mode</span><br>
                                <span class="text-muted small">Applies on next page load after saving.</span>
                            </label>
                        </div>
                    </div>

                    <!-- Theme Presets -->
                    <div class="col-12">
                        <h6 class="fw-bold mb-2" style="color:var(--pharmacy-heading,#1a1d23);">
                            <i class="fas fa-swatchbook me-2 text-primary"></i>Theme Preset
                        </h6>
                        <div class="row g-2" id="themePresets">
                            <?php
                            $presets = [
                                'default' => ['name'=>'Default Blue',  'primary'=>'#0d6efd','accent'=>'#667eea','sidebar'=>'#1a1d23'],
                                'purple'  => ['name'=>'Royal Purple',  'primary'=>'#6f42c1','accent'=>'#764ba2','sidebar'=>'#2d1b4e'],
                                'green'   => ['name'=>'Nature Green',  'primary'=>'#198754','accent'=>'#11998e','sidebar'=>'#1a2f1e'],
                                'teal'    => ['name'=>'Ocean Teal',    'primary'=>'#0dcaf0','accent'=>'#1abc9c','sidebar'=>'#0d2137'],
                                'red'     => ['name'=>'Crimson Red',   'primary'=>'#dc3545','accent'=>'#e74c3c','sidebar'=>'#2c0a0a'],
                                'orange'  => ['name'=>'Sunset Orange', 'primary'=>'#fd7e14','accent'=>'#f39c12','sidebar'=>'#2a1a00'],
                                'dark'    => ['name'=>'Full Dark',     'primary'=>'#5865f2','accent'=>'#57f287','sidebar'=>'#202225'],
                                'custom'  => ['name'=>'Custom',        'primary'=>'',       'accent'=>'',       'sidebar'=>''],
                            ];
                            $currentPreset = $_companySettings['theme_preset'] ?? 'default';
                            foreach ($presets as $key => $preset): ?>
                            <div class="col-6 col-md-3">
                                <div class="preset-card p-2 rounded border text-center <?php echo $currentPreset === $key ? 'border-primary' : ''; ?>"
                                     onclick="applyPreset('<?php echo $key; ?>','<?php echo $preset['primary']; ?>','<?php echo $preset['accent']; ?>','<?php echo $preset['sidebar']; ?>')"
                                     data-preset="<?php echo $key; ?>">
                                    <?php if ($key !== 'custom'): ?>
                                    <div style="display:flex;gap:4px;justify-content:center;margin-bottom:6px;">
                                        <div style="width:20px;height:20px;border-radius:4px;background:<?php echo $preset['primary']; ?>"></div>
                                        <div style="width:20px;height:20px;border-radius:4px;background:<?php echo $preset['accent']; ?>"></div>
                                        <div style="width:20px;height:20px;border-radius:4px;background:<?php echo $preset['sidebar']; ?>"></div>
                                    </div>
                                    <?php else: ?>
                                    <div style="display:flex;gap:4px;justify-content:center;margin-bottom:6px;">
                                        <div style="width:20px;height:20px;border-radius:4px;background:linear-gradient(135deg,#f00,#0f0,#00f)"></div>
                                    </div>
                                    <?php endif; ?>
                                    <small class="<?php echo $currentPreset === $key ? 'fw-bold text-primary' : ''; ?>"><?php echo $preset['name']; ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="theme_preset" id="themePresetInput" value="<?php echo htmlspecialchars($currentPreset); ?>">
                    </div>

                    <!-- Custom Colors -->
                    <div class="col-12">
                        <h6 class="fw-bold mb-2" style="color:var(--pharmacy-heading,#1a1d23);">
                            <i class="fas fa-fill-drip me-2 text-primary"></i>Custom Colors
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Primary Color</label>
                                <input type="color" class="form-control form-control-color w-100"
                                       name="primary_color" id="primaryColorPicker"
                                       value="<?php echo htmlspecialchars($_companySettings['primary_color'] ?? '#0d6efd'); ?>"
                                       oninput="livePreview()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Accent / Success Color</label>
                                <input type="color" class="form-control form-control-color w-100"
                                       name="accent_color" id="accentColorPicker"
                                       value="<?php echo htmlspecialchars($_companySettings['accent_color'] ?? '#667eea'); ?>"
                                       oninput="livePreview()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Sidebar Color</label>
                                <input type="color" class="form-control form-control-color w-100"
                                       name="sidebar_color" id="sidebarColorPicker"
                                       value="<?php echo htmlspecialchars($_companySettings['sidebar_color'] ?? '#1a1d23'); ?>"
                                       oninput="livePreview()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Body Background</label>
                                <input type="color" class="form-control form-control-color w-100"
                                       name="body_bg_color" id="bodyBgPicker"
                                       value="<?php echo htmlspecialchars($_companySettings['body_bg_color'] ?? '#f0f2f5'); ?>"
                                       oninput="livePreview()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Card Background</label>
                                <input type="color" class="form-control form-control-color w-100"
                                       name="card_bg_color" id="cardBgPicker"
                                       value="<?php echo htmlspecialchars($_companySettings['card_bg_color'] ?? '#ffffff'); ?>"
                                       oninput="livePreview()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Text Color</label>
                                <input type="color" class="form-control form-control-color w-100"
                                       name="body_text_color" id="textColorPicker"
                                       value="<?php echo htmlspecialchars($_companySettings['body_text_color'] ?? '#444444'); ?>"
                                       oninput="livePreview()">
                            </div>
                        </div>
                    </div>

                    <!-- Font Settings -->
                    <div class="col-12">
                        <h6 class="fw-bold mb-2" style="color:var(--pharmacy-heading,#1a1d23);">
                            <i class="fas fa-font me-2 text-primary"></i>Font Settings
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Font Family</label>
                                <select class="form-select" name="body_font_family" id="fontFamilySelect" onchange="livePreview()">
                                    <?php
                                    $fonts = ['Segoe UI','Roboto','Inter','Poppins','Open Sans','Nunito','Lato','Raleway','Ubuntu','Source Sans Pro'];
                                    $curFont = $_companySettings['body_font_family'] ?? 'Segoe UI';
                                    foreach ($fonts as $f): ?>
                                    <option value="<?php echo $f; ?>" <?php echo $curFont === $f ? 'selected' : ''; ?>
                                            style="font-family:'<?php echo $f; ?>'">
                                        <?php echo $f; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">
                                    Font Size: <span id="fontSizeLabel"><?php echo (int)($_companySettings['body_font_size'] ?? 14); ?>px</span>
                                </label>
                                <input type="range" class="form-range" name="body_font_size" id="fontSizeRange"
                                       min="11" max="18" step="1"
                                       value="<?php echo (int)($_companySettings['body_font_size'] ?? 14); ?>"
                                       oninput="document.getElementById('fontSizeLabel').textContent=this.value+'px'; livePreview()">
                            </div>
                        </div>
                        <!-- Live Preview Box -->
                        <div class="mt-3 p-3 border rounded" id="livePreviewBox">
                            <p class="mb-1 fw-bold" id="previewHeading" style="color:var(--pharmacy-heading,#1a1d23);">
                                Preview: Heading Text
                            </p>
                            <p class="mb-0 text-muted" id="previewBody">
                                Body text preview — fonts and colors update instantly as you adjust settings.
                            </p>
                            <div class="mt-2 d-flex gap-2 flex-wrap">
                                <span class="badge bg-primary">Primary</span>
                                <span class="badge bg-success">Accent</span>
                                <button type="button" class="btn btn-primary btn-sm">Button</button>
                                <span class="badge-default">Badge</span>
                            </div>
                        </div>
                    </div>

                </div><!-- /row -->
            </div>
        </div>

        <!-- STICKY SAVE BAR -->
        <div class="sticky-save-bar">
            <span class="text-muted small">
                <i class="fas fa-info-circle me-1"></i>Changes apply immediately after saving
            </span>
            <button type="submit" data-submit-btn class="btn btn-gradient px-5" id="btnSave">
                <i class="fas fa-save me-2"></i>Save All Settings
            </button>
        </div>

    </form>

</div>
</div><!-- /main-content -->


<script>
(function () {
    // GST toggle
    const enableGst = document.getElementById('enableGst');
    const gstFields = document.getElementById('gstFields');
    enableGst.addEventListener('change', function () {
        gstFields.classList.toggle('d-none', !this.checked);
    });

    // Invoice prefix live preview
    const invPrefix = document.querySelector('[name="invoice_prefix"]');
    const purPrefix = document.querySelector('[name="purchase_prefix"]');
    const startNum  = document.querySelector('[name="invoice_start_number"]');
    const prevInv   = document.getElementById('prevInv');
    const prevPur   = document.getElementById('prevPur');

    function updatePreview() {
        const num = String(parseInt(startNum.value) || 1).padStart(4, '0');
        prevInv.textContent = (invPrefix.value.trim().toUpperCase() || 'INV') + '-' + num;
        prevPur.textContent = (purPrefix.value.trim().toUpperCase() || 'PUR') + '-' + num;
    }
    [invPrefix, purPrefix, startNum].forEach(el => el.addEventListener('input', updatePreview));
    updatePreview();

    // Auto-uppercase prefix fields
    [invPrefix, purPrefix].forEach(function (el) {
        el.addEventListener('input', function () {
            const pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    });
})();

// Theme preset loader
function applyPreset(key, primary, accent, sidebar) {
    document.querySelectorAll('.preset-card').forEach(c => {
        c.classList.remove('border-primary');
        c.querySelector('small')?.classList.remove('fw-bold', 'text-primary');
    });
    const card = document.querySelector('[data-preset="' + key + '"]');
    if (card) {
        card.classList.add('border-primary');
        card.querySelector('small')?.classList.add('fw-bold', 'text-primary');
    }
    document.getElementById('themePresetInput').value = key;
    if (primary) document.getElementById('primaryColorPicker').value = primary;
    if (accent)  document.getElementById('accentColorPicker').value  = accent;
    if (sidebar) document.getElementById('sidebarColorPicker').value = sidebar;
    livePreview();
}

// Live preview — updates CSS vars instantly
function livePreview() {
    const primary  = document.getElementById('primaryColorPicker')?.value  || '#0d6efd';
    const accent   = document.getElementById('accentColorPicker')?.value   || '#667eea';
    const sidebar  = document.getElementById('sidebarColorPicker')?.value  || '#1a1d23';
    const bodyBg   = document.getElementById('bodyBgPicker')?.value        || '#f0f2f5';
    const cardBg   = document.getElementById('cardBgPicker')?.value        || '#ffffff';
    const textCol  = document.getElementById('textColorPicker')?.value     || '#444444';
    const fontSize = (document.getElementById('fontSizeRange')?.value || '14') + 'px';
    const fontFam  = document.getElementById('fontFamilySelect')?.value    || 'Segoe UI';

    const root = document.documentElement;
    root.style.setProperty('--pharmacy-primary',  primary);
    root.style.setProperty('--pharmacy-accent',   accent);
    root.style.setProperty('--pharmacy-sidebar',  sidebar);
    root.style.setProperty('--pharmacy-body-bg',  bodyBg);
    root.style.setProperty('--pharmacy-card-bg',  cardBg);
    root.style.setProperty('--pharmacy-text',     textCol);
    root.style.setProperty('--pharmacy-heading',  textCol);
    root.style.setProperty('--font-size-base',    fontSize);
    root.style.setProperty('--font-family',       '"' + fontFam + '",system-ui,sans-serif');

    const sb = document.getElementById('mainSidebar');
    if (sb) sb.style.setProperty('background', sidebar, 'important');
    document.body.style.backgroundColor = bodyBg;

    const box = document.getElementById('livePreviewBox');
    if (box) {
        box.style.background = cardBg;
        box.style.color      = textCol;
        box.style.fontFamily = '"' + fontFam + '", system-ui, sans-serif';
        box.style.fontSize   = fontSize;
    }

    document.querySelectorAll('.section-header').forEach(h => {
        h.style.background = 'linear-gradient(135deg,' + accent + ',' + primary + ')';
    });
    document.querySelectorAll('.btn-gradient').forEach(b => {
        b.style.background = 'linear-gradient(135deg,' + accent + ',#38ef7d)';
    });
}

document.addEventListener('DOMContentLoaded', livePreview);

// Auto-dismiss success alerts
document.querySelectorAll('.alert-success').forEach(el => {
    setTimeout(() => { const a = bootstrap.Alert.getOrCreateInstance(el); if (a) a.close(); }, 5000);
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
