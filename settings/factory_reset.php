<?php // REPAIRED vFIX7
// === FILE: pharmacy_software/settings/factory_reset.php ===

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/settings_functions.php';
require_once '../includes/sync_engine.php';

requireAdmin();
// ISS-020 FIX: Factory reset is always admin-only. canDo('settings','reset') was used but
// user_permissions has no can_reset column, so it always returned false (non-functional).
// requireAdmin() above already enforces the correct restriction.

// Fetch company settings via unified function
$company = function_exists('getCompanySettings') ? getCompanySettings() : [];
if (empty($company)) {
    $company = ['company_name' => 'Pharmacy', 'logo_path' => '', 'address' => ''];
}

// Raw name for JS comparison — never htmlspecialchars here
$pharmacyName     = $company['company_name'] ?? 'Pharmacy';
// HTML-safe version for display in HTML attributes / text
$pharmacyNameHtml = htmlspecialchars($pharmacyName, ENT_QUOTES, 'UTF-8');

$csrfToken = generateCSRFToken();

// ISS-035 FIX: Wrapped with if(!function_exists()) — prevents fatal error on double-include
if (!function_exists('getImpactCounts')) {
function getImpactCounts(): array {
    $db = db();
    if (!$db) {
        return [];
    }
    $counts = [];
    $tables = [
        'products'         => "SELECT COUNT(*) as c, 0 as t FROM products",
        'batches'          => "SELECT COUNT(*) as c, 0 as t FROM batches",
        'sales'            => "SELECT COUNT(*) as c, COALESCE(SUM(grand_total),0) as t FROM sales",
        'purchases'        => "SELECT COUNT(*) as c, COALESCE(SUM(total),0) as t FROM purchases",
        'cashbook'         => "SELECT COUNT(*) as c, 0 as t FROM cashbook",
        'customer_credits' => "SELECT COUNT(*) as c, COALESCE(SUM(total_amount),0) as t FROM customer_credits WHERE status IN ('active','overdue')",
        'expenses'         => "SELECT COUNT(*) as c, COALESCE(SUM(amount),0) as t FROM expenses",
        'suppliers'        => "SELECT COUNT(*) as c, COALESCE(SUM(due_amount),0) as t FROM suppliers",
        'activity_logs'    => "SELECT COUNT(*) as c, 0 as t FROM activity_logs",
        'stock_movements'  => "SELECT COUNT(*) as c, 0 as t FROM stock_movements",
        'sale_returns'     => "SELECT COUNT(*) as c, 0 as t FROM sale_returns",
        'purchase_returns' => "SELECT COUNT(*) as c, 0 as t FROM purchase_returns",
    ];

    foreach ($tables as $key => $sql) {
        try {
            $row = $db->getOne($sql);
            $counts[$key] = ['count' => (int)($row['c'] ?? 0), 'total' => (float)($row['t'] ?? 0)];
        } catch (\Throwable $e) {
            error_log("[getImpactCounts] Failed for $key: " . $e->getMessage());
            $counts[$key] = ['count' => 0, 'total' => 0];
        }
    }
    return $counts;
}
} // end if (!function_exists('getImpactCounts'))

$impact = getImpactCounts();

function fmt(float $v): string {
    return 'Rs ' . number_format($v, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>


<link rel="stylesheet" href="../css/style.css">
<style>
/* ══ LAYOUT ═══════════════════════════════════════════════════════ */
.main-content {
    margin-left: var(--sb-w, 260px);
    padding: 24px 28px 40px;
    transition: margin-left .25s ease;
    animation: fadeInPage .28s ease;
}
@keyframes fadeInPage {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

/* ══ DANGER PALETTE ═══════════════════════════════════════════════ */
:root {
    --danger-primary:   #e74c3c;
    --danger-dark:      #c0392b;
    --danger-darker:    #a93226;
    --danger-light-bg:  rgba(231,76,60,.07);
    --danger-ring:      rgba(231,76,60,.18);
    --success-color:    #198754;
    --success-ring:     rgba(25,135,84,.15);
    --warn-color:       #d4a017;
}

/* ══ PAGE HEADER ══════════════════════════════════════════════════ */
.fr-page-header {
    background: var(--pharmacy-card-bg, #fff);
    border: 1px solid var(--pharmacy-border, #dee2e6);
    border-radius: var(--card-border-radius, 12px);
    padding: 20px 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.fr-page-header .fr-header-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--danger-primary), var(--danger-dark));
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(231,76,60,.35);
}
.fr-page-header h4 {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--pharmacy-heading, #1a1d23);
}
.fr-page-header p {
    margin: 2px 0 0;
    font-size: .81rem;
    color: color-mix(in srgb, var(--pharmacy-text, #444) 65%, transparent);
}

/* ══ STEP WIZARD ══════════════════════════════════════════════════ */
.step-wizard-card {
    background: var(--pharmacy-card-bg, #fff);
    border: 1px solid var(--pharmacy-border, #dee2e6);
    border-radius: var(--card-border-radius, 12px);
    padding: 20px 28px;
    margin-bottom: 24px;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
}
.step-wizard { display: flex; align-items: center; }
.step-item {
    display: flex; flex-direction: column; align-items: center; flex: 1;
    position: relative;
}
.step-circle {
    width: 40px; height: 40px; border-radius: 50%;
    background: var(--pharmacy-border, #dee2e6);
    color: color-mix(in srgb, var(--pharmacy-text, #444) 60%, transparent);
    font-weight: 700; font-size: .9rem;
    display: flex; align-items: center; justify-content: center;
    border: 3px solid var(--pharmacy-card-bg, #fff);
    box-shadow: 0 0 0 3px var(--pharmacy-border, #dee2e6);
    transition: all .3s ease;
}
.step-item.active .step-circle {
    background: var(--danger-primary);
    color: #fff;
    box-shadow: 0 0 0 3px rgba(231,76,60,.22);
    transform: scale(1.08);
}
.step-item.done .step-circle {
    background: var(--success-color);
    color: #fff;
    box-shadow: 0 0 0 3px rgba(25,135,84,.2);
}
.step-label {
    font-size: .72rem;
    color: color-mix(in srgb, var(--pharmacy-text, #444) 55%, transparent);
    margin-top: 8px;
    text-align: center;
    font-weight: 500;
    transition: color .2s;
}
.step-item.active .step-label { color: var(--danger-primary); font-weight: 700; }
.step-item.done   .step-label { color: var(--success-color); }
.step-connector {
    flex: 1; height: 3px;
    background: var(--pharmacy-border, #dee2e6);
    margin-bottom: 20px;
    border-radius: 2px;
    transition: background .4s ease;
}
.step-connector.done { background: var(--success-color); }

/* ══ MAIN CARDS ════════════════════════════════════════════════════ */
.fr-card {
    background: var(--pharmacy-card-bg, #fff);
    border: 1px solid var(--pharmacy-border, #dee2e6);
    border-radius: var(--card-border-radius, 12px);
    margin-bottom: 24px;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
    overflow: hidden;
    transition: box-shadow .2s ease;
}
.fr-card-header {
    background: linear-gradient(135deg, var(--danger-primary), var(--danger-dark));
    color: #fff;
    padding: 15px 22px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    font-size: .88rem;
    letter-spacing: .2px;
}
.fr-card-header .step-badge {
    background: rgba(255,255,255,.18);
    border: 1px solid rgba(255,255,255,.25);
    padding: 1px 9px;
    border-radius: 20px;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .4px;
    margin-left: auto;
}
.fr-card-body { padding: 22px 24px; }

/* ══ RESET TYPE CARDS ═════════════════════════════════════════════ */
.reset-type-card {
    border: 2px solid var(--pharmacy-border, #dee2e6);
    border-radius: 10px;
    padding: 18px 16px;
    cursor: pointer;
    transition: all .2s ease;
    position: relative;
    height: 100%;
    background: var(--pharmacy-card-bg, #fff);
    display: block;
}
.reset-type-card:hover {
    border-color: #adb5bd;
    box-shadow: 0 4px 16px rgba(0,0,0,.08);
    transform: translateY(-2px);
}
.reset-type-card.selected-full        { border-color: var(--danger-primary); background: rgba(231,76,60,.04); box-shadow: 0 4px 18px rgba(231,76,60,.14); }
.reset-type-card.selected-financial   { border-color: #e67e22; background: rgba(230,126,34,.04); box-shadow: 0 4px 18px rgba(230,126,34,.12); }
.reset-type-card.selected-transaction { border-color: var(--warn-color);     background: rgba(212,160,23,.04); box-shadow: 0 4px 18px rgba(212,160,23,.12); }
.reset-type-card input[type=radio]    { position: absolute; opacity: 0; }
.reset-type-card .rt-icon     { font-size: 1.9rem; margin-bottom: .6rem; }
.reset-type-card .rt-title    { font-weight: 700; font-size: .9rem; color: var(--pharmacy-heading, #1a1d23); margin-bottom: .3rem; }
.reset-type-card .rt-sub      { font-size: .76rem; color: color-mix(in srgb, var(--pharmacy-text, #444) 65%, transparent); line-height: 1.45; }
.reset-type-card .rt-tag {
    font-size: .65rem; font-weight: 700;
    padding: .18rem .55rem; border-radius: 5px;
    margin-bottom: .5rem; display: inline-block;
    letter-spacing: .3px;
}
.reset-type-card .rt-check {
    position: absolute; top: 12px; right: 12px;
    width: 20px; height: 20px; border-radius: 50%;
    background: var(--danger-primary); color: #fff;
    display: none; align-items: center; justify-content: center;
    font-size: .6rem;
}
.reset-type-card.selected-full .rt-check,
.reset-type-card.selected-financial .rt-check,
.reset-type-card.selected-transaction .rt-check { display: flex; }

/* ══ BACKUP STATUS BOX ════════════════════════════════════════════ */
.backup-status-box {
    border: 1px solid var(--pharmacy-border, #dee2e6);
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: .85rem;
    background: var(--pharmacy-card-bg, #fff);
    transition: all .3s ease;
}
.backup-status-box.done {
    border-color: var(--success-color);
    background: rgba(25,135,84,.05);
    box-shadow: 0 2px 10px rgba(25,135,84,.1);
}

/* ══ IMPACT TABLE ═════════════════════════════════════════════════ */
.impact-table-wrap {
    border: 1px solid var(--pharmacy-border, #dee2e6);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
}
.impact-table { width: 100%; border-collapse: collapse; }
.impact-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--pharmacy-border, #f0f2f5);
    font-size: .84rem;
    color: var(--pharmacy-text, #444);
    vertical-align: middle;
}
.impact-table td:last-child { text-align: right; font-weight: 600; }
.impact-table tr:last-child td { border-bottom: none; }
.impact-row-del td  { color: var(--danger-primary) !important; }
.impact-row-keep td { color: var(--success-color) !important; }
.impact-sep-row td {
    background: color-mix(in srgb, var(--pharmacy-border, #dee2e6) 30%, var(--pharmacy-card-bg, #fff));
    font-size: .67rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .7px;
    color: color-mix(in srgb, var(--pharmacy-text, #444) 55%, transparent) !important;
    padding: 7px 14px;
}

/* ══ CONFIRMATION CHECKBOX ═════════════════════════════════════════ */
.confirm-checkbox-label {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--pharmacy-body-bg, #f8f9fa);
    border: 1px solid var(--pharmacy-border, #dee2e6);
    border-radius: 10px;
    cursor: pointer;
    transition: all .2s ease;
}
.confirm-checkbox-label:hover {
    background: var(--pharmacy-card-bg, #fff);
    border-color: var(--danger-primary);
}
.confirm-checkbox-label input {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: var(--danger-primary);
}
.confirm-checkbox-text {
    font-size: .88rem;
    color: var(--pharmacy-text, #444);
    line-height: 1.4;
}
.confirm-checkbox-text strong {
    color: var(--danger-primary);
}

/* ══ SUMMARY BOX ══════════════════════════════════════════════════ */
.summary-box {
    border: 1px solid var(--pharmacy-border, #dee2e6);
    border-radius: 10px;
    padding: 16px 18px;
    margin-bottom: 20px;
    background: var(--pharmacy-body-bg, #f0f2f5);
    font-size: .85rem;
    line-height: 1.9;
}
.summary-box .summary-label { color: color-mix(in srgb, var(--pharmacy-text, #444) 60%, transparent); }
.summary-box strong { color: var(--pharmacy-heading, #1a1d23); }

/* ══ PROGRESS BAR ═════════════════════════════════════════════════ */
.prog-bar-wrap {
    background: color-mix(in srgb, var(--pharmacy-border, #dee2e6) 50%, var(--pharmacy-body-bg, #f0f2f5));
    border-radius: 20px; height: 8px;
    overflow: hidden; margin: 10px 0;
}
.prog-bar-fill-danger {
    height: 100%;
    background: linear-gradient(90deg, var(--danger-primary), var(--danger-dark));
    border-radius: 20px;
    transition: width .4s ease;
    width: 0%;
}
.exec-log {
    font-size: .78rem;
    color: color-mix(in srgb, var(--pharmacy-text, #444) 70%, transparent);
    font-family: 'Courier New', monospace;
    padding: 6px 0;
}

/* ══ BUTTONS ══════════════════════════════════════════════════════ */
.btn-danger-gradient {
    background: linear-gradient(135deg, var(--danger-primary), var(--danger-dark));
    color: #fff !important;
    border: none;
    border-radius: var(--btn-border-radius, 8px);
    padding: 9px 22px;
    font-weight: 600;
    font-size: .86rem;
    cursor: pointer;
    transition: all .2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-danger-gradient:hover:not(:disabled) {
    background: linear-gradient(135deg, var(--danger-dark), var(--danger-darker));
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(231,76,60,.35);
}
.btn-danger-gradient:disabled { opacity: .5; cursor: not-allowed; }

/* ══ SETTINGS NAV SUB-BAR ═════════════════════════════════════════ */
.settings-subnav {
    background: var(--pharmacy-card-bg, #fff);
    border: 1px solid var(--pharmacy-border, #dee2e6);
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 20px;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.settings-subnav a {
    padding: 5px 13px;
    border-radius: 20px;
    font-size: .79rem;
    font-weight: 600;
    color: var(--pharmacy-text, #444);
    text-decoration: none;
    border: 1px solid transparent;
    transition: all .15s ease;
    display: inline-flex; align-items: center; gap: 5px;
}
.settings-subnav a:hover { background: var(--pharmacy-body-bg, #f0f2f5); border-color: var(--pharmacy-border, #dee2e6); }
.settings-subnav a.active {
    background: var(--pharmacy-primary, #0d6efd);
    color: #fff;
    border-color: var(--pharmacy-primary, #0d6efd);
}
.settings-subnav a.danger-nav { color: var(--danger-primary); }
.settings-subnav a.danger-nav:hover,
.settings-subnav a.danger-nav.active { background: var(--danger-primary); color: #fff; border-color: var(--danger-primary); }
</style>
</head>
<body class="bg-light<?php echo $_darkClass; ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid" style="max-width: 820px; padding-top: 80px;">

    <!-- Settings Sub-Navigation -->
    <div class="settings-subnav">
        <a href="company.php"><i class="fas fa-building"></i> Pharmacy Info</a>
        <a href="theme_editor_v2.php"><i class="fas fa-palette"></i> Theme Editor</a>
        <a href="backup.php"><i class="fas fa-database"></i> Backup</a>
        <a href="preferences.php"><i class="fas fa-sliders-h"></i> Preferences</a>
        <a href="change_password.php"><i class="fas fa-key"></i> Password</a>
        <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
        <a href="factory_reset.php" class="danger-nav active"><i class="fas fa-exclamation-triangle"></i> Factory Reset</a>
    </div>

    <!-- Page Header -->
    <div class="fr-page-header">
        <div style="display:flex;align-items:center;gap:14px;flex:1;min-width:0;">
            <div class="fr-header-icon">
                <i class="fas fa-exclamation-triangle text-white" style="font-size:1.2rem;"></i>
            </div>
            <div style="min-width:0;">
                <h4>Factory Reset</h4>
                <p>Permanently delete pharmacy data — this action is <strong style="color:var(--danger-primary)">irreversible</strong></p>
            </div>
        </div>
        <a href="company.php" class="btn btn-outline-secondary btn-sm" style="flex-shrink:0;">
            <i class="fas fa-arrow-left me-1"></i>Back to Settings
        </a>
    </div>

    <!-- Step Wizard -->
    <div class="step-wizard-card">
        <div class="step-wizard">
            <div class="step-item active" id="step1">
                <div class="step-circle">1</div>
                <div class="step-label">Backup &amp; Type</div>
            </div>
            <div class="step-connector" id="conn1"></div>
            <div class="step-item" id="step2">
                <div class="step-circle">2</div>
                <div class="step-label">Impact Review</div>
            </div>
            <div class="step-connector" id="conn2"></div>
            <div class="step-item" id="step3">
                <div class="step-circle">3</div>
                <div class="step-label">Confirm &amp; Execute</div>
            </div>
        </div>
    </div>

    <!-- ══════ CARD 1: BACKUP + RESET TYPE ══════ -->
    <div class="fr-card" id="card1">
        <div class="fr-card-header">
            <i class="fas fa-shield-alt"></i>
            Step 1 of 3 — Create Backup &amp; Choose Reset Type
            <span class="step-badge">STEP 1</span>
        </div>
        <div class="fr-card-body">

            <p class="mb-4" style="font-size:.84rem;color:color-mix(in srgb, var(--pharmacy-text) 70%, transparent);">
                <i class="fas fa-info-circle me-1" style="color:var(--pharmacy-primary)"></i>
                You must create a safety backup before proceeding. This is your <strong>ONLY</strong> recovery option.
            </p>

            <!-- Reset Type Selector -->
            <label class="d-block mb-2" style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:color-mix(in srgb, var(--pharmacy-text) 60%, transparent);">
                Select Reset Type
            </label>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="reset-type-card w-100" id="rt-full" onclick="selectType('full')">
                        <input type="radio" name="reset_type" value="full" id="rt_full">
                        <div class="rt-check"><i class="fas fa-check"></i></div>
                        <div class="rt-icon">🔴</div>
                        <span class="rt-tag" style="background:rgba(231,76,60,.12);color:var(--danger-primary);">FULL WIPE</span>
                        <div class="rt-title">Full Factory Reset</div>
                        <div class="rt-sub">Clears EVERYTHING — products, sales, purchases, cashbook, suppliers, logs.</div>
                    </label>
                </div>
                <div class="col-md-4">
                    <label class="reset-type-card w-100" id="rt-financial" onclick="selectType('financial')">
                        <input type="radio" name="reset_type" value="financial" id="rt_financial">
                        <div class="rt-check" style="background:#e67e22;"><i class="fas fa-check"></i></div>
                        <div class="rt-icon">🟠</div>
                        <span class="rt-tag" style="background:rgba(230,126,34,.12);color:#e67e22;">KEEP PRODUCTS</span>
                        <div class="rt-title">Financial Data Reset</div>
                        <div class="rt-sub">Clears sales, purchases, cashbook, credits. Keeps products, batches &amp; suppliers.</div>
                    </label>
                </div>
                <div class="col-md-4">
                    <label class="reset-type-card w-100" id="rt-transaction" onclick="selectType('transaction')">
                        <input type="radio" name="reset_type" value="transaction" id="rt_transaction">
                        <div class="rt-check" style="background:var(--warn-color);"><i class="fas fa-check"></i></div>
                        <div class="rt-icon">🟡</div>
                        <span class="rt-tag" style="background:rgba(212,160,23,.15);color:var(--warn-color);">KEEP INVENTORY</span>
                        <div class="rt-title">Transaction Reset</div>
                        <div class="rt-sub">Clears sales, cashbook, credits. Keeps products, batches, suppliers &amp; purchases.</div>
                    </label>
                </div>
            </div>

            <div id="no-type-err" class="alert alert-danger py-2 px-3 small mb-3" style="display:none;">
                <i class="fas fa-exclamation-triangle me-1"></i>Please select a reset type to continue.
            </div>

            <!-- Backup Status -->
            <div class="backup-status-box" id="backupStatusBox">
                <div style="font-size:1.6rem;flex-shrink:0;"><i class="fas fa-box-archive" style="color:color-mix(in srgb, var(--pharmacy-text) 40%, transparent)"></i></div>
                <div style="flex:1;">
                    <div class="fw-semibold" style="color:var(--pharmacy-heading);">No backup created yet</div>
                    <div style="font-size:.78rem;color:color-mix(in srgb, var(--pharmacy-text) 60%, transparent);">Click below to create a safety backup before proceeding.</div>
                </div>
            </div>

            <div class="alert alert-danger py-2 px-3 small mb-4" style="border-radius:8px;">
                <i class="fas fa-exclamation-triangle me-1"></i>
                <strong>Critical:</strong> Without a backup, reset data <u>cannot be recovered</u>.
                Backup is stored in <code>uploads/backups/</code>.
            </div>

            <div id="backupProgress" class="mb-3" style="display:none;">
                <div class="exec-log" id="backupLog">Creating backup...</div>
                <div class="prog-bar-wrap">
                    <div class="prog-bar-fill-danger" id="backupProgFill"></div>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-primary px-4" id="btnCreateBackup" onclick="createBackup()">
                    <i class="fas fa-box-archive me-2"></i>Create Safety Backup Now
                </button>
                <button class="btn-danger-gradient" id="btnStep1Next" disabled onclick="goStep(2)">
                    Next: Review Impact <i class="fas fa-arrow-right ms-1"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ══════ CARD 2: IMPACT REVIEW ══════ -->
    <div class="fr-card d-none" id="card2">
        <div class="fr-card-header">
            <i class="fas fa-magnifying-glass-chart"></i>
            Step 2 of 3 — Review What Will Be Deleted
            <span class="step-badge">STEP 2</span>
        </div>
        <div class="fr-card-body">

            <p class="mb-3" style="font-size:.84rem;color:color-mix(in srgb, var(--pharmacy-text) 70%, transparent);">
                These are real-time counts from your database. Review carefully before proceeding.
            </p>

            <div class="impact-table-wrap mb-3">
                <div id="impactContainer"></div>
            </div>

            <div class="alert alert-danger py-2 px-3 small mt-3 mb-4" style="border-radius:8px;">
                <i class="fas fa-skull-crossbones me-1"></i>
                <strong>THIS ACTION CANNOT BE UNDONE.</strong> All data marked 🔴 will be permanently deleted.
                Backup: <span id="backupNameDisplay" class="font-monospace fw-semibold"></span>
            </div>

            <div class="form-check mb-4 p-3" style="background:var(--pharmacy-body-bg);border-radius:8px;border:1px solid var(--pharmacy-border);">
                <input class="form-check-input" type="checkbox" id="ackCheck" onchange="handleAckCheck()">
                <label class="form-check-label" for="ackCheck" style="font-size:.86rem;color:var(--pharmacy-text);">
                    I have read the impact report. <strong style="color:var(--pharmacy-heading)">I understand this cannot be undone.</strong>
                    I confirm the backup is complete and accept full responsibility.
                </label>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary" onclick="goStep(1)">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </button>
                <button class="btn-danger-gradient" id="btnStep2Next" disabled onclick="goStep(3)">
                    Next: Final Confirmation <i class="fas fa-arrow-right ms-1"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ══════ CARD 3: CONFIRM & EXECUTE ══════ -->
    <div class="fr-card d-none" id="card3">
        <div class="fr-card-header">
            <i class="fas fa-key"></i>
            Step 3 of 3 — Final Confirmation
            <span class="step-badge">STEP 3</span>
        </div>
        <div class="fr-card-body">

            <div class="alert alert-warning py-2 px-3 small mb-4" style="border-radius:8px;">
                <i class="fas fa-circle-info me-1"></i>
                You are about to perform a <strong id="resetTypeLabel">—</strong> reset.
                This will permanently delete the data shown in Step 2.
            </div>

            <!-- Summary -->
            <div class="summary-box">
                <div class="fw-bold mb-2" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:color-mix(in srgb, var(--pharmacy-text) 55%, transparent);">
                    <i class="fas fa-list-check me-1"></i>Reset Summary
                </div>
                <div id="summaryBox">—</div>
            </div>

            <!-- Simple backup confirmation checkbox -->
            <div class="confirm-checkbox-label mb-4" onclick="toggleConfirmCheckbox()">
                <input type="checkbox" id="backupConfirmCheckbox" onclick="event.stopPropagation(); checkExecuteReady()">
                <div class="confirm-checkbox-text">
                    I confirm that I have created a safety backup (<strong><span id="backupNameDisplayStep3">—</span></strong>) and understand that this action is <strong class="text-danger">irreversible</strong>.
                </div>
            </div>

            <!-- Execute progress -->
            <div id="execProgress" style="display:none;" class="mb-3 p-3" style="background:var(--pharmacy-body-bg);border-radius:8px;">
                <div class="exec-log" id="execLog">Initializing reset...</div>
                <div class="prog-bar-wrap">
                    <div class="prog-bar-fill-danger" id="execProgFill"></div>
                </div>
            </div>

            <div class="d-flex gap-2" id="step3Buttons">
                <button class="btn btn-outline-secondary" onclick="goStep(2)">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </button>
                <button class="btn-danger-gradient" id="btnExecute" disabled onclick="executeReset()">
                    <i class="fas fa-trash-can me-2"></i>PERMANENTLY DELETE ALL DATA
                </button>
            </div>
        </div>
    </div>

</div><!-- /container -->
</div><!-- /main-content -->

<!-- Hidden data for JS -->
<input type="hidden" id="csrf_token" value="<?= $csrfToken ?>">
<input type="hidden" id="backup_filename" value="">
<!-- ISSUE 5: Store raw pharmacy name in data attribute for safe JS comparison -->
<div id="pharmacy_name_data" data-name="<?= htmlspecialchars($pharmacyName, ENT_QUOTES) ?>" style="display:none;"></div>

<script>
const IMPACT_DATA   = <?= json_encode($impact) ?>;
const PHARMACY_NAME = document.getElementById('pharmacy_name_data').dataset.name;
</script>


<script>
'use strict';

// ── STATE ──────────────────────────────────────────────────────────
let selectedType   = null;
let backupDone     = false;
let backupFilename = '';
let currentStep    = 1;

// ── RESET TYPE SELECTOR ────────────────────────────────────────────
function selectType(type) {
    selectedType = type;
    ['full','financial','transaction'].forEach(t => {
        document.getElementById('rt-' + t).className = 'reset-type-card w-100';
    });
    document.getElementById('rt-' + type).classList.add('selected-' + type);
    document.getElementById('rt_' + type).checked = true;
    document.getElementById('no-type-err').style.display = 'none';
    checkStep1Ready();
}

function checkStep1Ready() {
    document.getElementById('btnStep1Next').disabled = !(selectedType && backupDone);
}

// ── BACKUP ─────────────────────────────────────────────────────────
function createBackup() {
    if (!selectedType) {
        document.getElementById('no-type-err').style.display = 'block';
        return;
    }
    const btn = document.getElementById('btnCreateBackup');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating Backup...';
    document.getElementById('backupProgress').style.display = 'block';

    let prog = 0;
    const fill = document.getElementById('backupProgFill');
    const log  = document.getElementById('backupLog');
    const steps = [
        'Connecting to database...','Exporting products &amp; batches...',
        'Exporting sales data...','Exporting purchases...',
        'Exporting cashbook &amp; credits...','Compressing...','Saving to disk...'
    ];
    let i = 0;
    const iv = setInterval(() => {
        if (i < steps.length) { log.innerHTML = steps[i++]; }
        prog = Math.min(prog + 12, 90);
        fill.style.width = prog + '%';
    }, 500);

    fetch('../ajax/backup_create.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + document.getElementById('csrf_token').value + '&source=factory_reset'
    })
    .then(r => r.json())
    .then(data => {
        clearInterval(iv);
        fill.style.width = '100%';
        if (data.success) {
            backupDone     = true;
            backupFilename = data.filename;
            document.getElementById('backup_filename').value = data.filename;
            document.getElementById('backupNameDisplay').textContent = data.filename;
            log.innerHTML = '✅ Backup created: ' + data.filename;

            const box = document.getElementById('backupStatusBox');
            box.className = 'backup-status-box done';
            box.innerHTML = `
                <div style="font-size:1.6rem;flex-shrink:0"><i class="fas fa-check-circle text-success"></i></div>
                <div style="flex:1">
                    <div class="fw-semibold text-success">Backup Created Successfully</div>
                    <div class="small" style="opacity:.7">${data.filename} · ${data.size || ''} · ${new Date().toLocaleString()}</div>
                </div>
                <a href="../ajax/download_backup.php?file=${encodeURIComponent(data.filename)}&csrf_token=${document.getElementById('csrf_token').value}"
                   class="text-success small text-decoration-none d-flex align-items-center gap-1" target="_blank">
                    <i class="fas fa-download"></i>Download
                </a>`;

            btn.innerHTML = '<i class="fas fa-check me-2"></i>Backup Ready';
            btn.className = 'btn btn-success px-4';
            checkStep1Ready();
        } else {
            log.innerHTML = '❌ Backup failed: ' + (data.message || 'Unknown error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-rotate me-2"></i>Retry Backup';
        }
    })
    .catch(() => {
        clearInterval(iv);
        log.innerHTML = '❌ Network error. Please retry.';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-rotate me-2"></i>Retry Backup';
    });
}

// ── STEP NAVIGATION ────────────────────────────────────────────────
function goStep(n) {
    if (n === 2) {
        if (!selectedType) { document.getElementById('no-type-err').style.display = 'block'; return; }
        if (!backupDone)   { Notify.warning('Please create a backup first.'); return; }
        renderImpactTable();
        document.getElementById('ackCheck').checked = false;
        document.getElementById('btnStep2Next').disabled = true;
    }
    if (n === 3) { 
        populateStep3(); 
        document.getElementById('backupConfirmCheckbox').checked = false;
        document.getElementById('btnExecute').disabled = true;
    }

    // Update wizard stepper
    [1,2,3].forEach(idx => {
        const stepEl = document.getElementById('step' + idx);
        const circle = stepEl.querySelector('.step-circle');
        stepEl.className = 'step-item';
        if (idx < n) {
            stepEl.classList.add('done');
            circle.innerHTML = '<i class="fas fa-check" style="font-size:.7rem;"></i>';
        } else if (idx === n) {
            stepEl.classList.add('active');
            circle.innerHTML = idx;
        } else {
            circle.innerHTML = idx;
        }
        if (idx < 3) {
            document.getElementById('conn' + idx).className = 'step-connector' + (idx < n ? ' done' : '');
        }
    });

    // Show/hide cards
    ['card1','card2','card3'].forEach((id, i) => {
        document.getElementById(id).classList.toggle('d-none', i + 1 !== n);
    });
    currentStep = n;
    window.scrollTo({top: 0, behavior: 'smooth'});
}

// ── IMPACT TABLE ───────────────────────────────────────────────────
function renderImpactTable() {
    const type = selectedType;
    const deletedFull        = ['products','batches','sales','purchases','cashbook','customer_credits','expenses','suppliers','activity_logs','stock_movements','sale_returns','purchase_returns'];
    const deletedFinancial   = ['sales','purchases','cashbook','customer_credits','expenses','sale_returns','purchase_returns','stock_movements','activity_logs'];
    const keptFinancial      = ['products','batches','suppliers'];
    const deletedTransaction = ['sales','cashbook','customer_credits','expenses','sale_returns','purchase_returns','activity_logs'];
    const keptTransaction    = ['products','batches','suppliers','purchases','stock_movements'];

    let deleted = [], kept = [];
    if (type === 'full')        { deleted = deletedFull;        kept = []; }
    if (type === 'financial')   { deleted = deletedFinancial;   kept = keptFinancial; }
    if (type === 'transaction') { deleted = deletedTransaction; kept = keptTransaction; }

    const labels = {
        products:'Products', batches:'Stock Batches', sales:'Sales', purchases:'Purchases',
        cashbook:'Cashbook Entries', customer_credits:'Customer Credits (Active)',
        expenses:'Expenses', suppliers:'Suppliers', activity_logs:'Activity Logs',
        stock_movements:'Stock Movements', sale_returns:'Sale Returns', purchase_returns:'Purchase Returns',
    };

    let html = '<table class="impact-table">';
    html += '<tr class="impact-sep-row"><td colspan="2">🔴 WILL BE DELETED</td></tr>';
    deleted.forEach(key => {
        const d = IMPACT_DATA[key] || {count:0,total:0};
        const extra = d.total > 0 ? ` — Rs ${Number(d.total).toLocaleString('en-PK',{minimumFractionDigits:2})}` : '';
        html += `<tr class="impact-row-del"><td><i class="fas fa-xmark-circle me-2"></i>${labels[key] || key}</td><td>${d.count.toLocaleString()} records${extra}</td></tr>`;
    });
    if (kept.length) {
        html += '<tr class="impact-sep-row"><td colspan="2" style="padding-top:.8rem;">🟢 WILL BE KEPT</td></tr>';
        kept.forEach(key => {
            const d = IMPACT_DATA[key] || {count:0};
            html += `<tr class="impact-row-keep"><td><i class="fas fa-circle-check me-2"></i>${labels[key] || key}</td><td>${d.count.toLocaleString()} records</td></tr>`;
        });
    }
    html += '</table>';
    document.getElementById('impactContainer').innerHTML = html;
}

function handleAckCheck() {
    document.getElementById('btnStep2Next').disabled = !document.getElementById('ackCheck').checked;
}

// ── STEP 3 POPULATION ─────────────────────────────────────────────
function populateStep3() {
    const typeLabels = { full:'🔴 Full Factory Reset', financial:'🟠 Financial Data Reset', transaction:'🟡 Transaction Reset' };
    document.getElementById('resetTypeLabel').textContent = typeLabels[selectedType] || selectedType;
    document.getElementById('backupNameDisplayStep3').textContent = backupFilename;
    const deletedCounts = {
        full: Object.values(IMPACT_DATA).reduce((s,v) => s + v.count, 0),
        financial: ['sales','purchases','cashbook','customer_credits','expenses','sale_returns','purchase_returns','stock_movements','activity_logs'].reduce((s,k) => s + (IMPACT_DATA[k]?.count||0), 0),
        transaction: ['sales','cashbook','customer_credits','expenses','sale_returns','purchase_returns','activity_logs'].reduce((s,k) => s + (IMPACT_DATA[k]?.count||0), 0),
    };
    document.getElementById('summaryBox').innerHTML = `
        <div><span class="summary-label">Reset Type:</span> <strong>${typeLabels[selectedType]}</strong></div>
        <div><span class="summary-label">Records to Delete:</span> <strong style="color:var(--danger-primary)">${(deletedCounts[selectedType]||0).toLocaleString()}</strong></div>
        <div><span class="summary-label">Backup File:</span> <strong class="font-monospace">${backupFilename}</strong></div>
        <div><span class="summary-label">Executed By:</span> <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></strong></div>`;
}

function toggleConfirmCheckbox() {
    const cb = document.getElementById('backupConfirmCheckbox');
    cb.checked = !cb.checked;
    checkExecuteReady();
}

function checkExecuteReady() {
    const checkbox = document.getElementById('backupConfirmCheckbox');
    document.getElementById('btnExecute').disabled = !checkbox.checked;
}

// ── EXECUTE RESET ──────────────────────────────────────────────────
function executeReset() {
    if (!confirm('⚠ FINAL WARNING: This will permanently delete data. Are you absolutely sure?')) return;
    if (!confirm('This is your LAST CHANCE. Click OK to proceed with the reset.')) return;

    doExecuteReset();
}

function doExecuteReset() {
    document.getElementById('step3Buttons').style.display = 'none';
    document.getElementById('execProgress').style.display = 'block';
    const log  = document.getElementById('execLog');
    const fill = document.getElementById('execProgFill');
    log.textContent  = 'Sending request...';
    fill.style.width = '5%';

    const body = new URLSearchParams({
        reset_type:           selectedType,
        backup_filename:      backupFilename,
        csrf_token:           document.getElementById('csrf_token').value,
    });

    let prog = 5;
    const progSteps = [
        'Verifying security locks...','Disabling foreign key checks...',
        'Clearing sales data...','Clearing purchase records...',
        'Clearing cashbook entries...','Clearing credit records...',
        'Clearing suppliers & customers...','Resetting invoice sequences...',
        'Logging reset event...','Finalizing reset...',
    ];
    let pi = 0;
    const iv = setInterval(() => {
        if (pi < progSteps.length) { log.textContent = progSteps[pi++]; }
        prog = Math.min(prog + 8, 90);
        fill.style.width = prog + '%';
    }, 600);

    fetch('../ajax/execute_factory_reset.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
        clearInterval(iv);
        fill.style.width = '100%';
        if (data.success) {
            // ISSUE 2: Show completion message and redirect
            log.innerHTML = '✅ Reset complete! Redirecting to login...';
            setTimeout(() => {
                window.location.href = data.redirect || '../auth/login.php';
            }, 2000);
        } else {
            log.innerHTML = '❌ Failed: ' + (data.message || 'Unknown error');
            document.getElementById('step3Buttons').style.display = 'flex';
        }
    })
    .catch(err => {
        clearInterval(iv);
        log.innerHTML = '❌ Network error: ' + err.message;
        document.getElementById('step3Buttons').style.display = 'flex';
    });
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
