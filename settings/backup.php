<?php
// === FILE: pharmacy_software/settings/backup.php ===
// STEP 14 Enhanced — Backup & Restore with settings sub-nav & breadcrumb

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/settings_functions.php';
require_once '../includes/functions.php';

requireAdmin();
if (!canDo('backup', 'view')) {
    header('Location: ../dashboard.php?error=no_permission'); exit;
}

$page_title = 'Database Backup — Pharmacy Management System';

$settings = getCompanySettings();
$company_name        = $settings['company_name'] ?? 'Pharmacy';
$auto_backup_enabled = $settings['auto_backup_enabled'] ?? '0';
$auto_backup_keep    = $settings['auto_backup_keep']    ?? '10';

// ISS-011 FIX: Use db()->getConnection() directly — db() returns a Database object, not PDO
try {
    $pdo_init = db()->getConnection();
    $existing_cols = $pdo_init->query("SHOW COLUMNS FROM company_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('auto_backup_enabled', $existing_cols)) {
        $pdo_init->exec("ALTER TABLE company_settings ADD COLUMN `auto_backup_enabled` TINYINT(1) NOT NULL DEFAULT '0'");
    }
    if (!in_array('auto_backup_keep', $existing_cols)) {
        $pdo_init->exec("ALTER TABLE company_settings ADD COLUMN `auto_backup_keep` INT NOT NULL DEFAULT '10'");
        clearCompanySettingsCache();
        $settings            = getCompanySettings();
        $auto_backup_enabled = $settings['auto_backup_enabled'] ?? '0';
        $auto_backup_keep    = $settings['auto_backup_keep']    ?? '10';
    }
} catch (Exception $e) {
    error_log('backup.php: column check failed: ' . $e->getMessage());
}

function safeDb() {
    $db = db();
    if ($db !== null && $db instanceof PDO) return $db;
    try {
        return new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Exception $e) { return null; }
}

$all_tables = [];
$pdo = safeDb();
if ($pdo !== null) {
    try {
        $stmt = $pdo->query("SHOW TABLES");
        $all_tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Exception $e) {
        error_log("Backup page: " . $e->getMessage());
    }
}

$backup_dir   = dirname(__DIR__) . '/uploads/backups/';
$backup_files = [];
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . 'pharmacy_backup_*.{sql,sql.gz,gz}', GLOB_BRACE);
    if ($files) {
        rsort($files);
        foreach ($files as $fp) {
            $fname = basename($fp);
            $fsize = filesize($fp);
            $fdate = date('d-M-Y H:i:s', filemtime($fp));
            if (strpos($fname, '_full_') !== false)           $ftype = 'Full';
            elseif (strpos($fname, '_data_') !== false)       $ftype = 'Data Only';
            elseif (strpos($fname, '_selective_') !== false)  $ftype = 'Selective';
            elseif (strpos($fname, '_config_') !== false)     $ftype = 'Config';
            elseif (strpos($fname, 'pre_restore_') !== false) $ftype = 'Pre-Reset';
            else $ftype = 'Backup';
            $meta_file = $backup_dir . $fname . '.meta.json';
            $meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
            $backup_files[] = [
                'name'     => $fname,
                'size'     => formatBackupSize($fsize),
                'size_raw' => $fsize,
                'date'     => $fdate,
                'type'     => $ftype,
                'md5'      => $meta['md5'] ?? '',
                'tables'   => $meta['tables'] ?? [],
                'rows'     => $meta['total_rows'] ?? 0,
            ];
        }
    }
}

$table_groups = [
    'Products'  => ['products','product_variants','batches','stock_movements'],
    'Sales'     => ['sales','sale_items','sale_returns'],
    'Purchases' => ['purchases','purchase_items','purchase_returns','suppliers','supplier_payments'],
    'Credits'   => ['customer_credits','credit_payments'],
    'Cashbook'  => ['cashbook','expenses'],
    'Users'     => ['users','user_permissions','permission_presets','activity_logs','login_attempts'],
    'Settings'  => ['company_settings','system_resets','bulk_import_logs'],
];

$csrf = generateCSRFToken();

function formatBackupSize(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1)    . ' KB';
    return $bytes . ' B';
}

$_darkClass = !empty($settings['dark_mode']) ? ' dark-mode' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>


<link rel="stylesheet" href="../css/style.css">
<style>
/* ── Layout ── */
.main-content { margin-left: var(--sb-w, 260px); padding: 24px; transition: margin-left .25s; }
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

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
    padding: 7px 14px; border-radius: 8px;
    font-size: .82rem; font-weight: 500;
    color: var(--pharmacy-text, #6c757d);
    text-decoration: none; transition: all .15s ease; white-space: nowrap;
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

/* ── Card boxes ── */
.card-box {
    border: 1px solid var(--pharmacy-border, rgba(0,0,0,.08)); border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
    background: var(--pharmacy-card-bg, #fff); margin-bottom: 24px;
    color: var(--pharmacy-text, #444); transition: box-shadow .2s;
}
.card-box:hover { box-shadow: 0 4px 18px rgba(0,0,0,.09); }
.section-header {
    background: linear-gradient(135deg, var(--pharmacy-accent, #667eea), var(--pharmacy-primary, #764ba2));
    color: #fff; border-radius: 12px 12px 0 0;
    padding: 14px 20px; font-weight: 600; font-size: 15px;
}

/* ── Nav tabs ── */
.nav-tabs .nav-link { color: var(--pharmacy-text, #6c757d); font-size: .875rem; }
.nav-tabs .nav-link.active { font-weight: 600; color: var(--pharmacy-primary, #0d6efd) !important; }

/* ── Backup type cards ── */
.backup-type-card {
    border: 2px solid var(--pharmacy-border, #dee2e6); border-radius: 10px;
    padding: 16px; cursor: pointer; transition: all .2s; position: relative;
    background: var(--pharmacy-card-bg, #fff); color: var(--pharmacy-text, #444);
    display: block;
}
.backup-type-card:hover, .backup-type-card.selected {
    border-color: var(--pharmacy-primary, #0d6efd);
    background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 6%, var(--pharmacy-card-bg, #fff));
}
.backup-type-card input[type=radio] { position: absolute; top: 14px; right: 14px; }
.backup-type-card .btype-icon { font-size: 24px; margin-bottom: 8px; }
.backup-type-card h6 { font-weight: 600; margin-bottom: 4px; color: var(--pharmacy-heading, #1a1d23); }
.backup-type-card p { font-size: 12px; color: var(--pharmacy-text, #6c757d); margin: 0; }

/* ── Table group label ── */
.table-group-label { font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--pharmacy-heading, #1a1d23); }

/* ── Progress ── */
.progress-wrap { display: none; }
.progress-wrap.active { display: block; }

/* ── Badge types ── */
.badge-type { font-size: 11px; padding: 3px 8px; border-radius: 20px; font-weight: 500; }
.badge-full { background: #dbeafe; color: #1d4ed8; }
.badge-data { background: #d1fae5; color: #065f46; }
.badge-selective { background: #fef3c7; color: #92400e; }
.badge-config { background: #f3e8ff; color: #6b21a8; }
.badge-pre-reset { background: #fee2e2; color: #991b1b; }

/* ── Table wrapper ── */
.table-card { border-radius: 10px; overflow: hidden; border: 1px solid var(--pharmacy-border, #dee2e6); }
.table th {
    background: var(--pharmacy-table-th, #f8f9fa) !important;
    color: var(--pharmacy-heading, #1a1d23) !important;
    font-size: .78rem; text-transform: uppercase; letter-spacing: .4px;
    font-weight: 700; padding: 11px 14px;
    border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important;
}
.table td { padding: 10px 14px; vertical-align: middle; color: var(--pharmacy-text, #444) !important; }
.table-hover tbody tr:hover td {
    background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 5%, var(--pharmacy-card-bg, #fff)) !important;
}

/* ── Restore dropzone ── */
.restore-dropzone {
    border: 2px dashed var(--pharmacy-border, #dee2e6); border-radius: 10px;
    padding: 40px; text-align: center; cursor: pointer; transition: all .2s;
    background: var(--pharmacy-card-bg, #fff);
}
.restore-dropzone:hover, .restore-dropzone.dragover {
    border-color: var(--pharmacy-primary, #0d6efd);
    background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 6%, var(--pharmacy-card-bg, #fff));
}
.restore-dropzone i { font-size: 40px; color: var(--pharmacy-text, #6c757d); }
</style>
</head>
<body class="bg-light<?php echo $_darkClass ?? ''; ?>">

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
        <span class="bc-current"><i class="fas fa-database me-1"></i>Backup &amp; Restore</span>
    </div>

    <!-- Settings Sub-Navigation -->
    <div class="settings-subnav">
        <a href="company.php" class="snav-link">
            <i class="fas fa-hospital"></i> Pharmacy Info
        </a>
        <a href="theme_editor_v2.php" class="snav-link">
            <i class="fas fa-palette"></i> Theme Editor
        </a>
        <a href="preferences.php" class="snav-link">
            <i class="fas fa-sliders-h"></i> Preferences
        </a>
        <a href="backup.php" class="snav-link active">
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
                <i class="fas fa-database text-primary me-2"></i>Database Backup
            </h4>
            <p class="text-muted mb-0"><?= htmlspecialchars($company_name) ?> — Backup &amp; Restore Management</p>
        </div>
        <a href="company.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Back to Settings
        </a>
    </div>

    <div id="globalAlert" class="d-none mb-3"></div>

    <ul class="nav nav-tabs mb-4" id="backupTabs">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabCreate"><i class="fas fa-plus-circle me-1"></i>Create Backup</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabUploads"><i class="fas fa-images me-1"></i>Uploads Backup</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabAuto"><i class="fas fa-clock me-1"></i>Auto-Backup</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabHistory"><i class="fas fa-history me-1"></i>Backup History <span class="badge bg-secondary ms-1"><?= count($backup_files) ?></span></a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabRestore"><i class="fas fa-undo-alt me-1"></i>Restore</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabRollback"><i class="fas fa-clock-rotate-left me-1"></i>Rollback Points</a></li>
    </ul>

    <div class="tab-content">

    <!-- TAB 1: CREATE BACKUP -->
    <div class="tab-pane fade show active" id="tabCreate">
        <div class="card-box mb-4">
            <div class="section-header"><i class="fas fa-box me-2"></i> Select Backup Type</div>
            <div class="p-4">
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="backup-type-card selected w-100" id="type-card-full">
                            <input type="radio" name="backup_type" value="full" checked onchange="selectType(this)">
                            <div class="btype-icon">🗄️</div>
                            <h6>Full Database</h6>
                            <p>All tables — schema + data</p>
                        </label>
                    </div>
                    <div class="col-md-3">
                        <label class="backup-type-card w-100" id="type-card-data">
                            <input type="radio" name="backup_type" value="data" onchange="selectType(this)">
                            <div class="btype-icon">📊</div>
                            <h6>Data Only</h6>
                            <p>INSERT statements only</p>
                        </label>
                    </div>
                    <div class="col-md-3">
                        <label class="backup-type-card w-100" id="type-card-selective">
                            <input type="radio" name="backup_type" value="selective" onchange="selectType(this)">
                            <div class="btype-icon">☑️</div>
                            <h6>Selective</h6>
                            <p>Pick specific tables</p>
                        </label>
                    </div>
                    <div class="col-md-3">
                        <label class="backup-type-card w-100" id="type-card-config">
                            <input type="radio" name="backup_type" value="config" onchange="selectType(this)">
                            <div class="btype-icon">⚙️</div>
                            <h6>Config + Users</h6>
                            <p>Settings &amp; permissions</p>
                        </label>
                    </div>
                </div>

                <div id="selectiveTables" class="d-none mb-4">
                    <div class="alert alert-info py-2 px-3 mb-3"><i class="fas fa-info-circle me-1"></i> Select table groups to include.</div>
                    <div class="row g-3">
                        <?php foreach ($table_groups as $group => $tables):
                            $available = array_intersect($tables, $all_tables); ?>
                        <div class="col-md-4"><div class="border rounded p-3" style="border-color:var(--pharmacy-border,#dee2e6)!important;background:var(--pharmacy-card-bg,#fff);">
                            <div class="table-group-label">
                                <input type="checkbox" class="form-check-input group-check me-2"
                                       id="grp_<?= strtolower($group) ?>" data-group="<?= strtolower($group) ?>"
                                       onchange="toggleGroup(this)" checked>
                                <label for="grp_<?= strtolower($group) ?>"><?= $group ?></label>
                            </div>
                            <?php foreach ($available as $tbl): ?>
                            <div class="form-check ms-3">
                                <input class="form-check-input tbl-check" type="checkbox" name="tables[]"
                                       value="<?= $tbl ?>" data-group="<?= strtolower($group) ?>"
                                       id="tbl_<?= $tbl ?>" checked>
                                <label class="form-check-label small" for="tbl_<?= $tbl ?>"><?= $tbl ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div></div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="progress-wrap" id="backupProgress">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small fw-semibold" id="progressLabel">Initializing...</span>
                        <span class="small text-muted" id="progressPct">0%</span>
                    </div>
                    <div class="progress mb-3" style="height:8px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="progressBar" style="width:0%"></div>
                    </div>
                </div>
                <div id="backupResult" class="d-none mt-3"></div>

                <div class="d-flex gap-2 mt-2">
                    <button class="btn btn-primary px-4" id="btnCreateBackup" onclick="startBackup()">
                        <i class="fas fa-database me-2"></i> Create Backup Now
                    </button>
                    <span class="text-muted small align-self-center">
                        <i class="fas fa-lock me-1"></i> Stored in <code>uploads/backups/</code>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 2: UPLOADS BACKUP -->
    <div class="tab-pane fade" id="tabUploads">
        <div class="card-box mb-4">
            <div class="section-header"><i class="fas fa-images me-2"></i> Uploads & Media Backup</div>
            <div class="p-4">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Creates a <strong>.zip</strong> archive of all uploaded files — product images, profile photos,
                    avatars, and settings media. Store alongside your database backups for a complete restore.
                </div>

                <div class="row g-3 mb-4">
                    <?php
                    $uploadFolders = [
                        ['dir' => 'products',  'icon' => '💊', 'label' => 'Product Images'],
                        ['dir' => 'profiles',  'icon' => '👤', 'label' => 'Profile Photos'],
                        ['dir' => 'avatars',   'icon' => '🖼️', 'label' => 'Avatars'],
                        ['dir' => 'settings',  'icon' => '⚙️', 'label' => 'Settings Media'],
                    ];
                    foreach ($uploadFolders as $uf):
                        $dir = dirname(__DIR__) . '/uploads/' . $uf['dir'] . '/';
                        $count = 0;
                        if (is_dir($dir)) {
                            $files_in = glob($dir . '*.*');
                            $count = $files_in ? count(array_filter($files_in, 'is_file')) : 0;
                        }
                    ?>
                    <div class="col-6 col-md-3">
                        <div class="border rounded p-3 text-center" style="border-color:var(--pharmacy-border,#dee2e6)!important;background:var(--pharmacy-card-bg,#fff);">
                            <div style="font-size:1.8rem;"><?= $uf['icon'] ?></div>
                            <div class="fw-semibold small mt-1" style="color:var(--pharmacy-heading,#1a1d23);"><?= $uf['label'] ?></div>
                            <div class="text-muted" style="font-size:.75rem;"><?= $count ?> file<?= $count !== 1 ? 's' : '' ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div id="uploadsProgress" class="d-none mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small fw-semibold" id="uploadProgressLabel">Zipping files...</span>
                        <span class="small text-muted" id="uploadProgressPct">0%</span>
                    </div>
                    <div class="progress mb-2" style="height:8px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" id="uploadProgressBar" style="width:0%"></div>
                    </div>
                </div>
                <div id="uploadsResult" class="d-none mb-3"></div>

                <div class="d-flex gap-2 mt-2">
                    <button class="btn btn-success px-4" id="btnUploadsBackup" onclick="startUploadsBackup()">
                        <i class="fas fa-file-zipper me-2"></i> Create Uploads ZIP Backup
                    </button>
                    <span class="text-muted small align-self-center">
                        <i class="fas fa-lock me-1"></i> Stored in <code>uploads/backups/</code>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 3: AUTO-BACKUP -->
    <div class="tab-pane fade" id="tabAuto">
        <div class="card-box mb-4">
            <div class="section-header"><i class="fas fa-clock me-2"></i> Auto-Backup Settings</div>
            <div class="p-4">
                <div class="mb-4">
                    <div class="form-check form-switch fs-5 mb-0">
                        <input class="form-check-input" type="checkbox" id="autoBackupToggle"
                            <?= $auto_backup_enabled == '1' ? 'checked' : '' ?> onchange="saveAutoSettings()">
                        <label class="form-check-label fw-semibold" for="autoBackupToggle">
                            Enable Daily Auto-Backup (midnight)
                        </label>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">Keep Last N Backups</label>
                    <select class="form-select w-auto" id="autoBackupKeep" onchange="saveAutoSettings()">
                        <?php foreach ([10,20,30,50] as $n): ?>
                        <option value="<?= $n ?>" <?= $auto_backup_keep == $n ? 'selected' : '' ?>><?= $n ?> Backups</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Older backups beyond this limit will be auto-deleted.</div>
                </div>
                <div class="alert alert-info">
                    <strong><i class="fas fa-terminal me-1"></i> Cron Job:</strong><br>
                    <code>0 0 * * * php <?= dirname(dirname(__DIR__)) ?>/pharmacy_software/ajax/auto_backup.php</code>
                </div>
                <div id="autoSaveStatus"></div>
            </div>
        </div>
    </div>

    <!-- TAB 3: HISTORY -->
    <div class="tab-pane fade" id="tabHistory">
        <div class="card-box mb-4">
            <div class="section-header d-flex justify-content-between">
                <span><i class="fas fa-history me-2"></i> Backup History</span>
                <span><?= count($backup_files) ?> backups</span>
            </div>
            <div class="p-0">
                <?php if (empty($backup_files)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-folder-open fa-3x mb-3 d-block"></i>
                    No backups found. Create your first backup above.
                </div>
                <?php else: ?>
                <div class="table-responsive table-card">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Filename</th>
                                <th>Size</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($backup_files as $bf): ?>
                        <tr>
                            <td style="color:var(--pharmacy-text,#444);"><?= htmlspecialchars($bf['date']) ?></td>
                            <td>
                                <span class="badge-type <?= match($bf['type']){'Full'=>'badge-full','Data Only'=>'badge-data','Selective'=>'badge-selective','Config'=>'badge-config','Pre-Reset'=>'badge-pre-reset',default=>'bg-secondary'} ?>">
                                    <?= $bf['type'] ?>
                                </span>
                            </td>
                            <td><code class="small"><?= htmlspecialchars($bf['name']) ?></code></td>
                            <td style="color:var(--pharmacy-text,#444);"><?= $bf['size'] ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="../ajax/backup_download.php?file=<?= urlencode($bf['name']) ?>&csrf=<?= $csrf ?>"
                                       class="btn btn-outline-success" title="Download" data-bs-toggle="tooltip">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <button class="btn btn-outline-primary" title="Restore from this backup"
                                            data-bs-toggle="tooltip"
                                            onclick="restoreFromHistory('<?= htmlspecialchars($bf['name'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-undo-alt"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" title="Delete backup"
                                            data-bs-toggle="tooltip"
                                            onclick="deleteBackup('<?= htmlspecialchars($bf['name'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
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

    <!-- TAB 4: RESTORE -->
    <div class="tab-pane fade" id="tabRestore">
        <div class="card-box mb-4">
            <div class="section-header" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
                <i class="fas fa-undo-alt me-2"></i> Restore Database
            </div>
            <div class="p-4">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>DANGER ZONE</strong> — Restoring overwrites all current data. A pre-restore backup is created automatically.
                </div>

                <div class="mb-4">
                    <h6 class="fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">
                        <span class="badge bg-primary me-2">1</span> Select Backup File
                    </h6>
                    <div class="restore-dropzone" id="dropzone"
                         onclick="document.getElementById('restoreFile').click()"
                         ondragover="event.preventDefault();this.classList.add('dragover')"
                         ondragleave="this.classList.remove('dragover')"
                         ondrop="handleDrop(event)">
                        <i class="fas fa-cloud-upload-alt fa-3x"></i>
                        <h6 class="mt-2">Drop .sql or .sql.gz file here</h6>
                        <p class="text-muted small mb-0">or click to browse</p>
                    </div>
                    <input type="file" id="restoreFile" class="d-none" accept=".sql,.gz" onchange="fileSelected(this)">
                    <div id="fileInfo" class="mt-2 d-none">
                        <div class="alert alert-success py-2 mb-0">
                            <i class="fas fa-file-code me-1"></i>
                            <strong id="selectedFileName"></strong>
                            <span id="selectedFileSize" class="text-muted ms-2"></span>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <h6 class="fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">
                        <span class="badge bg-warning text-dark me-2">2</span> Confirm
                    </h6>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="chk1" onchange="checkRestoreReady()">
                        <label class="form-check-label" for="chk1">I understand this will OVERWRITE all current data.</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="chk2" onchange="checkRestoreReady()">
                        <label class="form-check-label" for="chk2">I have created a fresh backup before restoring.</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="chk3" onchange="checkRestoreReady()">
                        <label class="form-check-label" for="chk3">This action is irreversible.</label>
                    </div>
                </div>

                <div class="mb-4">
                    <h6 class="fw-semibold" style="color:var(--pharmacy-heading,#1a1d23);">
                        <span class="badge bg-secondary me-2">3</span> Admin Password
                    </h6>
                    <input type="password" class="form-control w-50" id="adminPassword"
                           onkeyup="checkRestoreReady()" placeholder="Enter your admin password">
                </div>

                <div id="restoreProgress" class="d-none mb-3">
                    <div class="progress mb-2">
                        <div class="progress-bar bg-warning progress-bar-striped progress-bar-animated" id="restoreProgressBar" style="width:0%"></div>
                    </div>
                    <div id="restoreLog" class="bg-dark text-light p-2 rounded small" style="max-height:200px;overflow:auto;font-family:monospace;"></div>
                </div>
                <div id="restoreResult" class="d-none mb-3"></div>

                <button class="btn btn-danger px-4" id="btnRestore" disabled onclick="startRestore()">
                    <i class="fas fa-undo-alt me-2"></i> Restore Database
                </button>
            </div>
        </div>
    </div>

    </div><!-- tab-content -->

    <!-- TAB 6: ROLLBACK POINTS (appended at end) -->
    <div class="tab-pane fade" id="tabRollback" style="display:none;"><!-- populated by JS --></div>

    </div><!-- tab-content wrapper -->

</div><!-- container -->
</div><!-- main-content -->

<input type="hidden" id="csrfToken" value="<?= $csrf ?>">

<script>
const csrf = document.getElementById('csrfToken').value;
let selectedRestoreFile = null;

// Tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));

// ── Backup Type Selection ──
function selectType(radio) {
    document.querySelectorAll('.backup-type-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('type-card-' + radio.value).classList.add('selected');
    document.getElementById('selectiveTables').classList.toggle('d-none', radio.value !== 'selective');
}

function toggleGroup(cb) {
    const group = cb.dataset.group;
    document.querySelectorAll(`.tbl-check[data-group="${group}"]`).forEach(c => c.checked = cb.checked);
}

// ── Create Backup ──
function startBackup() {
    const btn         = document.getElementById('btnCreateBackup');
    const progressDiv = document.getElementById('backupProgress');
    const resultDiv   = document.getElementById('backupResult');
    const type        = document.querySelector('input[name="backup_type"]:checked')?.value || 'full';
    const selectedTables = [];
    if (type === 'selective') {
        document.querySelectorAll('.tbl-check:checked').forEach(cb => selectedTables.push(cb.value));
        if (selectedTables.length === 0) {
            showGlobalAlert('warning', 'Please select at least one table for selective backup.');
            return;
        }
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating...';
    progressDiv.classList.add('active');
    resultDiv.classList.add('d-none');
    setProgress(20, 'Connecting to database...');
    const body = new URLSearchParams({ csrf_token: csrf, backup_type: type });
    if (type === 'selective') selectedTables.forEach(t => body.append('tables[]', t));
    setProgress(40, 'Exporting tables...');
    fetch('../ajax/backup_create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
    .then(r => { if (!r.ok) throw new Error('Server error: ' + r.status); return r.json(); })
    .then(data => {
        setProgress(100, 'Done!');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-database me-2"></i> Create Backup Now';
        if (data.success) {
            resultDiv.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Backup created!</strong>
                    <code class="ms-2">${escHtml(data.filename)}</code>
                    <span class="text-muted ms-2">${escHtml(data.size || '')}</span>
                    <a href="../ajax/backup_download.php?file=${encodeURIComponent(data.filename)}&csrf=${csrf}"
                       class="btn btn-sm btn-outline-success ms-3">
                        <i class="fas fa-download me-1"></i>Download
                    </a>
                </div>`;
            resultDiv.classList.remove('d-none');
            setTimeout(() => location.reload(), 3000);
        } else {
            resultDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>${escHtml(data.message || 'Backup failed.')}</div>`;
            resultDiv.classList.remove('d-none');
            progressDiv.classList.remove('active');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-database me-2"></i> Create Backup Now';
        progressDiv.classList.remove('active');
        showGlobalAlert('danger', '❌ Error: ' + err.message);
    });
}

function setProgress(pct, label) {
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('progressPct').textContent = pct + '%';
    document.getElementById('progressLabel').textContent = label;
}

// ── Auto-Backup Settings ──
function saveAutoSettings() {
    const enabled = document.getElementById('autoBackupToggle').checked ? '1' : '0';
    const keep    = document.getElementById('autoBackupKeep').value;
    const status  = document.getElementById('autoSaveStatus');
    status.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
    fetch('../ajax/save_settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `csrf_token=${csrf}&auto_backup_enabled=${enabled}&auto_backup_keep=${keep}`
    })
    .then(r => r.json())
    .then(data => {
        status.innerHTML = data.success
            ? '<div class="alert alert-success py-1 px-3 d-inline-block mt-2"><i class="fas fa-check me-1"></i>Saved!</div>'
            : '<div class="alert alert-danger py-1 px-3 d-inline-block mt-2">Save failed.</div>';
        setTimeout(() => status.innerHTML = '', 3000);
    })
    .catch(() => { status.innerHTML = '<div class="alert alert-danger py-1 px-3 d-inline-block mt-2">Network error.</div>'; });
}

// ── Delete Backup ──
function deleteBackup(filename) {
    if (!confirm('Delete backup: ' + filename + '\n\nThis cannot be undone.')) return;
    fetch('../ajax/backup_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `csrf_token=${csrf}&filename=${encodeURIComponent(filename)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { showGlobalAlert('success', '✅ Backup deleted: ' + filename); setTimeout(() => location.reload(), 1500); }
        else showGlobalAlert('danger', '❌ ' + (data.message || 'Delete failed.'));
    })
    .catch(() => showGlobalAlert('danger', '❌ Network error.'));
}

function restoreFromHistory(filename) {
    const tab = document.querySelector('a[href="#tabRestore"]');
    bootstrap.Tab.getOrCreateInstance(tab).show();
    showGlobalAlert('info', `ℹ️ Upload the file <strong>${escHtml(filename)}</strong> or download it first, then upload in the Restore tab.`);
}

// ── Restore: File selection ──
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('dropzone').classList.remove('dragover');
    const file = e.dataTransfer.files[0];
    if (file) processFileSelection(file);
}

function fileSelected(input) { if (input.files[0]) processFileSelection(input.files[0]); }

function processFileSelection(file) {
    const name = file.name.toLowerCase();
    if (!['.sql', '.gz'].some(ext => name.endsWith(ext))) {
        showGlobalAlert('warning', 'Only .sql or .sql.gz files are allowed.'); return;
    }
    selectedRestoreFile = file;
    document.getElementById('selectedFileName').textContent = file.name;
    document.getElementById('selectedFileSize').textContent = '(' + formatSize(file.size) + ')';
    document.getElementById('fileInfo').classList.remove('d-none');
    checkRestoreReady();
}

function formatSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024)    return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' B';
}

function checkRestoreReady() {
    const fileOk = selectedRestoreFile !== null;
    const chksOk = ['chk1','chk2','chk3'].every(id => document.getElementById(id).checked);
    const passOk = document.getElementById('adminPassword').value.length >= 4;
    document.getElementById('btnRestore').disabled = !(fileOk && chksOk && passOk);
}

function startRestore() {
    if (!selectedRestoreFile) { Notify.warning('Please select a backup file.'); return; }
    if (!confirm('⚠ FINAL CONFIRMATION\n\nRestoring will COMPLETELY OVERWRITE the current database.\nA pre-restore backup will be created first.\n\nAre you sure?')) return;
    const btn         = document.getElementById('btnRestore');
    const progressDiv = document.getElementById('restoreProgress');
    const logDiv      = document.getElementById('restoreLog');
    const resultDiv   = document.getElementById('restoreResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Restoring...';
    progressDiv.classList.remove('d-none');
    resultDiv.classList.add('d-none');
    logDiv.innerHTML = '⏳ Uploading backup file...\n';
    document.getElementById('restoreProgressBar').style.width = '20%';
    const formData = new FormData();
    formData.append('csrf_token',    csrf);
    formData.append('backup_file',   selectedRestoreFile);
    formData.append('admin_password', document.getElementById('adminPassword').value);
    fetch('../ajax/backup_restore.php', { method: 'POST', body: formData })
    .then(r => { if (!r.ok) throw new Error('Server error: ' + r.status); return r.json(); })
    .then(data => {
        document.getElementById('restoreProgressBar').style.width = '100%';
        btn.innerHTML = '<i class="fas fa-undo-alt me-2"></i> Restore Database';
        if (data.success) {
            logDiv.innerHTML += '✅ ' + escHtml(data.message) + '\n';
            if (data.pre_backup_file) logDiv.innerHTML += '📦 Pre-backup: ' + escHtml(data.pre_backup_file) + '\n';
            if (data.minor_errors > 0) logDiv.innerHTML += `ℹ️ ${data.minor_errors} minor SQL errors skipped.\n`;
            resultDiv.innerHTML = `<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><strong>Restore successful!</strong> ${escHtml(data.message)}</div>`;
            resultDiv.classList.remove('d-none');
            setTimeout(() => window.location.href = '../dashboard.php', 3000);
        } else {
            logDiv.innerHTML += '❌ ' + escHtml(data.message) + '\n';
            resultDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>${escHtml(data.message || 'Restore failed.')}</div>`;
            resultDiv.classList.remove('d-none');
            btn.disabled = false;
        }
    })
    .catch(err => {
        document.getElementById('restoreProgressBar').style.width = '0%';
        logDiv.innerHTML += '❌ Error: ' + err.message + '\n';
        resultDiv.innerHTML = `<div class="alert alert-danger">❌ ${escHtml(err.message)}</div>`;
        resultDiv.classList.remove('d-none');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-undo-alt me-2"></i> Restore Database';
    });
}

function showGlobalAlert(type, html) {
    const div = document.getElementById('globalAlert');
    div.className = 'alert alert-' + type + ' alert-dismissible fade show mb-3';
    div.innerHTML = html + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str || '')));
    return d.innerHTML;
}

// Auto-dismiss alerts
document.querySelectorAll('.alert:not(.alert-danger):not(.alert-permanent)').forEach(el => {
    setTimeout(() => { const a = bootstrap.Alert.getOrCreateInstance(el); if (a) a.close(); }, 6000);
});

// ── Uploads Backup ──
function startUploadsBackup() {
    const btn  = document.getElementById('btnUploadsBackup');
    const prog = document.getElementById('uploadsProgress');
    const res  = document.getElementById('uploadsResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating ZIP...';
    prog.classList.remove('d-none');
    res.classList.add('d-none');
    let pct = 20;
    const fill  = document.getElementById('uploadProgressBar');
    const label = document.getElementById('uploadProgressLabel');
    const pctEl = document.getElementById('uploadProgressPct');
    const iv = setInterval(() => {
        pct = Math.min(pct + 15, 85);
        fill.style.width = pct + '%';
        pctEl.textContent = pct + '%';
        label.textContent = pct < 50 ? 'Scanning files...' : pct < 75 ? 'Compressing...' : 'Saving archive...';
    }, 600);
    fetch('../ajax/backup_uploads.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + csrf
    })
    .then(r => r.json())
    .then(data => {
        clearInterval(iv);
        fill.style.width = '100%';
        pctEl.textContent = '100%';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-file-zipper me-2"></i> Create Uploads ZIP Backup';
        if (data.success) {
            res.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Uploads ZIP created!</strong>
                    <code class="ms-2">${escHtml(data.filename)}</code>
                    <span class="text-muted ms-2">${escHtml(data.size || '')} — ${data.file_count || 0} files</span>
                    <a href="../ajax/backup_download.php?file=${encodeURIComponent(data.filename)}&csrf=${csrf}"
                       class="btn btn-sm btn-outline-success ms-3">
                        <i class="fas fa-download me-1"></i>Download
                    </a>
                </div>`;
            res.classList.remove('d-none');
        } else {
            res.innerHTML = `<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>${escHtml(data.message || 'Failed.')}</div>`;
            res.classList.remove('d-none');
            prog.classList.add('d-none');
        }
    })
    .catch(err => {
        clearInterval(iv);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-file-zipper me-2"></i> Create Uploads ZIP Backup';
        prog.classList.add('d-none');
        showGlobalAlert('danger', '❌ Error: ' + err.message);
    });
}

// ── Rollback Points ──
document.querySelector('a[href="#tabRollback"]').addEventListener('shown.bs.tab', loadRollbackPoints);

function loadRollbackPoints() {
    const pane = document.getElementById('tabRollback');
    pane.style.display = '';
    pane.innerHTML = '<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
    fetch('../ajax/backup_rollback_list.php?csrf=' + csrf)
    .then(r => r.json())
    .then(data => {
        if (!data.success) { pane.innerHTML = `<div class="alert alert-danger">${escHtml(data.message)}</div>`; return; }
        const items = data.rollbacks || [];
        if (items.length === 0) {
            pane.innerHTML = `
                <div class="card-box"><div class="section-header"><i class="fas fa-clock-rotate-left me-2"></i> Rollback Points</div>
                <div class="p-4 text-center text-muted"><i class="fas fa-circle-info fa-2x mb-3 d-block"></i>
                No rollback points found yet.<br><small>They are created automatically before every Restore or Factory Reset.</small>
                </div></div>`;
            return;
        }
        let rows = items.map(rb => `
            <tr>
                <td style="color:var(--pharmacy-text,#444);">${escHtml(rb.date)}</td>
                <td><span class="badge-type ${rb.type === 'Pre-Reset' ? 'badge-pre-reset' : 'badge-config'}">${escHtml(rb.type)}</span></td>
                <td><code class="small">${escHtml(rb.name)}</code></td>
                <td style="color:var(--pharmacy-text,#444);">${escHtml(rb.size)}</td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <a href="../ajax/backup_download.php?file=${encodeURIComponent(rb.name)}&csrf=${csrf}"
                           class="btn btn-outline-success" title="Download" data-bs-toggle="tooltip">
                            <i class="fas fa-download"></i>
                        </a>
                        <button class="btn btn-outline-primary" title="Restore from this rollback point"
                                data-bs-toggle="tooltip"
                                onclick="restoreFromHistory('${rb.name.replace(/'/g,'\\\'\')}')">
                            <i class="fas fa-undo-alt"></i>
                        </button>
                        <button class="btn btn-outline-danger" title="Delete rollback point"
                                data-bs-toggle="tooltip"
                                onclick="deleteBackup('${rb.name.replace(/'/g,'\\\'\')}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>`).join('');
        pane.innerHTML = `
            <div class="card-box">
                <div class="section-header d-flex justify-content-between">
                    <span><i class="fas fa-clock-rotate-left me-2"></i> Rollback Points</span>
                    <span>${items.length} point${items.length !== 1 ? 's' : ''}</span>
                </div>
                <div class="alert alert-info m-3 py-2"><i class="fas fa-info-circle me-1"></i>
                    These are <strong>auto-created safety snapshots</strong> taken before every Restore or Factory Reset.
                    Use them to roll back if a restore went wrong.
                </div>
                <div class="table-responsive table-card">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Date</th><th>Type</th><th>Filename</th><th>Size</th><th>Actions</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </div>`;
        pane.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));
    })
    .catch(err => { pane.innerHTML = `<div class="alert alert-danger">❌ ${escHtml(err.message)}</div>`; });
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
