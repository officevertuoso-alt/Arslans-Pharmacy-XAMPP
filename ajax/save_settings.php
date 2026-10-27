<?php
/**
 * ajax/save_settings.php
 * Saves company / auto-backup settings via POST.
 * Returns JSON { success: bool, message: string }
 *
 * Accepted fields (all optional, only provided keys are updated):
 *   auto_backup_enabled   — '0' or '1'
 *   auto_backup_keep      — integer (5-100)
 *   + any other company_settings column (future-proof)
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/settings_functions.php';

header('Content-Type: application/json; charset=utf-8');

requireAdmin();

// ── Method ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$csrf = trim($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh the page.']);
    exit;
}

// ── Whitelist of allowed settings keys ────────────────────────────────────────
// Only columns that exist (or will exist) in company_settings are allowed.
$allowed_keys = [
    'auto_backup_enabled',
    'auto_backup_keep',
    'company_name',
    'phone',
    'email',
    'address',
    'currency_symbol',
    'invoice_prefix',
    'low_stock_threshold',
    'near_expiry_days',
    'default_tax_rate',
    'system_timezone',
    'date_format',
    'time_format',
    'session_timeout',
    'primary_color',
    'sidebar_color',
    'accent_color',
    'show_logo_on_sidebar',
    'show_logo_on_invoice',
    'receipt_paper_size',
];

// ── Collect and validate submitted fields ─────────────────────────────────────
$updates = [];

foreach ($allowed_keys as $key) {
    if (!array_key_exists($key, $_POST)) continue;

    $value = $_POST[$key];

    // Type-specific validation
    switch ($key) {
        case 'auto_backup_enabled':
            $value = in_array($value, ['0', '1'], true) ? $value : '0';
            break;

        case 'auto_backup_keep':
            $value = (int)$value;
            if ($value < 5)   $value = 5;
            if ($value > 100) $value = 100;
            $value = (string)$value;
            break;

        case 'low_stock_threshold':
        case 'near_expiry_days':
        case 'session_timeout':
            $value = max(0, (int)$value);
            $value = (string)$value;
            break;

        case 'default_tax_rate':
            $value = max(0, min(100, (float)$value));
            $value = number_format($value, 2, '.', '');
            break;

        default:
            $value = trim((string)$value);
            break;
    }

    $updates[$key] = $value;
}

if (empty($updates)) {
    echo json_encode(['success' => false, 'message' => 'No valid fields to update.']);
    exit;
}

// ── Ensure the auto_backup columns exist ─────────────────────────────────────
// (These may not exist in older installs — add them safely)
try {
    $pdo = getRawPdo();

    // Check existing columns
    $cols_stmt = $pdo->query("SHOW COLUMNS FROM company_settings");
    $existing  = $cols_stmt->fetchAll(PDO::FETCH_COLUMN);

    $needed_cols = [
        'auto_backup_enabled' => "TINYINT(1) NOT NULL DEFAULT '0'",
        'auto_backup_keep'    => "INT NOT NULL DEFAULT '10'",
    ];
    foreach ($needed_cols as $col => $definition) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec("ALTER TABLE company_settings ADD COLUMN `{$col}` {$definition}");
        }
    }
} catch (Exception $e) {
    // Non-fatal — column may already exist or DB issue
    error_log('save_settings: column check failed: ' . $e->getMessage());
}

// ── Build UPDATE query ────────────────────────────────────────────────────────
try {
    $pdo = getRawPdo();

    // Build SET clause
    $set_parts = [];
    $bindings  = [];
    foreach ($updates as $col => $val) {
        // Extra safety: only word-char column names
        if (!preg_match('/^\w+$/', $col)) continue;
        $set_parts[] = "`{$col}` = :{$col}";
        $bindings[":{$col}"] = $val;
    }

    if (empty($set_parts)) {
        echo json_encode(['success' => false, 'message' => 'Nothing to update.']);
        exit;
    }

    // Ensure row with id=1 exists
    $exists = $pdo->query("SELECT COUNT(*) FROM company_settings WHERE id = 1")->fetchColumn();
    if (!$exists) {
        $pdo->exec("INSERT INTO company_settings (id) VALUES (1)");
    }

    $sql  = "UPDATE company_settings SET " . implode(', ', $set_parts) . " WHERE id = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bindings);

    // Clear settings cache so next page load gets fresh data
    if (function_exists('clearCompanySettingsCache')) {
        clearCompanySettingsCache();
    }

    // Log activity
    $changed = implode(', ', array_keys($updates));
    try {
        if (function_exists('logActivity')) {
            logActivity('Settings Updated', "Fields: {$changed} | User: " . ($_SESSION['username'] ?? 'unknown'));
        }
    } catch (Exception $e) {}

    echo json_encode(['success' => true, 'message' => 'Settings saved.', 'updated' => array_keys($updates)]);
    exit;

} catch (Exception $e) {
    error_log('save_settings error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// ── Helper: get raw PDO (works with any DB wrapper) ───────────────────────────
function getRawPdo(): PDO {
    $db = db();
    if ($db instanceof PDO) return $db;
    if (is_object($db) && method_exists($db, 'getConnection')) {
        $c = $db->getConnection();
        if ($c instanceof PDO) return $c;
    }
    if (is_object($db) && method_exists($db, 'getPdo')) {
        $c = $db->getPdo();
        if ($c instanceof PDO) return $c;
    }
    return new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}