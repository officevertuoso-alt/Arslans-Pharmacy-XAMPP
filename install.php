<?php
// ══════════════════════════════════════════════════════════════════
// PRODUCTION SAFETY LOCK — BUG #5 FIX
// This file must NEVER be accessible in production.
// Access is restricted to localhost (127.0.0.1 / ::1) only.
// DELETE this file immediately after installation is complete.
// ══════════════════════════════════════════════════════════════════
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Access denied. Delete install.php after installation. Remote installation is not permitted.');
}
// End IP Lock — original installer code below
/**
 * Arslan's Pharmacy ERP — Auto Installer v7.0
 * Run once after deployment on XAMPP / fresh server.
 * Applies enterprise schema patch, verifies all tables, creates missing indexes.
 * Powered by Dr. Arslan Yasin Sukhera
 *
 * USAGE: http://localhost/pharmacy_software/install.php
 * DELETE this file after successful installation.
 */

// ── Security: Only run from localhost unless override key is set ──────────────
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$allowedIps = ['127.0.0.1', '::1', 'localhost'];
$bypassKey  = $_GET['key'] ?? '';
// Phase 28: Hardened bypass key — change this before deploying or DELETE install.php entirely
$secureBypassKey = hash('sha256', 'CHANGE_ME_BEFORE_DEPLOY_' . gethostname());

if (!in_array($remoteIp, $allowedIps, true) && !hash_equals($secureBypassKey, $bypassKey)) {
    http_response_code(403);
    die('<h2>Access Denied</h2><p>Installer can only be run from localhost.</p>');
}

// ── Prevent re-run if lock file exists ───────────────────────────────────────
$lockFile = __DIR__ . '/logs/install.lock';
if (file_exists($lockFile) && !isset($_GET['force'])) {
    die('<h2>Already Installed</h2><p>Lock file exists. Add ?force=1 to re-run, or delete <code>logs/install.lock</code>.</p>');
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config/database.php';

$errors  = [];
$success = [];
$warnings = [];

// ── Get PDO connection ────────────────────────────────────────────────────────
try {
    $pdo = _getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $success[] = "✅ Database connection successful";
} catch (Throwable $e) {
    die('<h2>Database Connection Failed</h2><p>' . htmlspecialchars($e->getMessage()) . '</p>');
}

// ── Run enterprise patch SQL ──────────────────────────────────────────────────
$patchFile = __DIR__ . '/database_enterprise_patch.sql';
if (file_exists($patchFile)) {
    $sql = file_get_contents($patchFile);
    // Split on semicolons, run each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (empty($stmt) || str_starts_with($stmt, '--') || str_starts_with($stmt, 'SET') || str_starts_with($stmt, 'SELECT')) continue;
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // Duplicate key / already exists errors are OK
            if (!str_contains($e->getMessage(), 'Duplicate') && !str_contains($e->getMessage(), '1060') && !str_contains($e->getMessage(), '1061')) {
                $warnings[] = "⚠️ " . htmlspecialchars(substr($stmt, 0, 80)) . "… → " . $e->getMessage();
            }
        }
    }
    $success[] = "✅ Enterprise schema patch applied";
} else {
    $warnings[] = "⚠️ database_enterprise_patch.sql not found — skipped";
}

// ── Verify required tables ────────────────────────────────────────────────────
$requiredTables = [
    'active_sessions', 'activity_logs', 'batches', 'categories', 'cashbook',
    'company_settings', 'credit_payments', 'customer_credits', 'customers',
    'dosage_forms', 'expense_categories', 'expenses', 'invoice_sequences',
    'products', 'product_variants', 'purchases', 'purchase_items',
    'purchase_returns', 'sales', 'sale_items', 'sale_returns',
    'stock_movements', 'strengths', 'suppliers', 'supplier_payments',
    'system_preferences', 'user_permissions', 'users'
];

$missingTables = [];
foreach ($requiredTables as $table) {
    $exists = $pdo->query("SHOW TABLES LIKE '{$table}'")->rowCount() > 0;
    if (!$exists) $missingTables[] = $table;
}

if (empty($missingTables)) {
    $success[] = "✅ All " . count($requiredTables) . " required tables present";
} else {
    $errors[] = "❌ Missing tables: " . implode(', ', $missingTables);
}

// ── Verify default admin user ─────────────────────────────────────────────────
try {
    $admin = $pdo->query("SELECT id, username, role FROM users WHERE username = 'arslan' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($admin) {
        $success[] = "✅ Default admin user 'arslan' exists (ID: {$admin['id']}, Role: {$admin['role']})";
    } else {
        // Create default admin with is_first_login = 1 (triggers update details modal)
        $hash = password_hash('arslan123', PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("INSERT INTO users (username, password, full_name, role, status, is_first_login, permissions_set, login_count) VALUES ('arslan', ?, 'Dr. Arslan Yasin Sukhera', 'admin', 1, 1, 1, 0)")->execute([$hash]);
        $success[] = "✅ Default admin user 'arslan' created (password: arslan123) — first login modal will appear";
    }

    // ── Part 1: Create user_settings table if not exists ──────────────────────
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `user_settings` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `user_id` INT(11) NOT NULL,
            `selected_theme` VARCHAR(60) NOT NULL DEFAULT 'dark_blue_professional',
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_settings_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $success[] = "✅ user_settings table ready (Theme Engine — Part 1)";
    } catch (Throwable $eTe) {
        $errors[] = "⚠️ user_settings table: " . $eTe->getMessage();
    }
} catch (Throwable $e) {
    $errors[] = "❌ User check failed: " . $e->getMessage();
}

// ── Verify uploads directories ────────────────────────────────────────────────
$uploadDirs = ['uploads', 'uploads/products', 'uploads/avatars', 'uploads/profiles', 'uploads/settings', 'uploads/backups', 'logs'];
foreach ($uploadDirs as $dir) {
    $fullPath = __DIR__ . '/' . $dir;
    if (!is_dir($fullPath)) {
        mkdir($fullPath, 0755, true);
        $success[] = "✅ Created directory: {$dir}";
    }
    if (!is_writable($fullPath)) {
        $errors[] = "❌ Not writable: {$dir} — run: chmod 755 {$dir}";
    }
}

// ── Check .env file ───────────────────────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $success[] = "✅ .env file found";
} else {
    $exampleEnv = __DIR__ . '/.env.example';
    if (file_exists($exampleEnv)) {
        copy($exampleEnv, $envFile);
        $warnings[] = "⚠️ .env created from .env.example — update DB credentials if needed";
    } else {
        $warnings[] = "⚠️ No .env file — using config defaults (APP_ENV=production)";
    }
}

// ── Create install lock ───────────────────────────────────────────────────────
if (empty($errors)) {
    file_put_contents($lockFile, date('Y-m-d H:i:s') . " — Installation completed successfully\n");
}

// ── Output ────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Arslan's Pharmacy ERP — Installer</title>
<style>
body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;margin:0;padding:20px}
.container{max-width:800px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);overflow:hidden}
.header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:30px;text-align:center}
.header h1{margin:0;font-size:24px}.header p{margin:5px 0 0;opacity:.85}
.body{padding:30px}
.item{padding:10px 15px;margin:6px 0;border-radius:8px;font-size:14px}
.success{background:#d4edda;border-left:4px solid #28a745;color:#155724}
.warning{background:#fff3cd;border-left:4px solid #ffc107;color:#856404}
.error{background:#f8d7da;border-left:4px solid #dc3545;color:#721c24}
.footer{background:#f8f9fa;padding:20px 30px;border-top:1px solid #dee2e6;text-align:center;color:#6c757d;font-size:13px}
.btn{display:inline-block;padding:12px 30px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;text-decoration:none;border-radius:8px;font-weight:600;margin-top:20px}
h3{color:#343a40;border-bottom:2px solid #e9ecef;padding-bottom:8px}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>🏥 Arslan's Pharmacy ERP — Installer v7.0</h1>
    <p>Powered by Dr. Arslan Yasin Sukhera</p>
  </div>
  <div class="body">
    <?php if (!empty($success)): ?>
    <h3>✅ Successful Steps</h3>
    <?php foreach ($success as $msg): ?>
    <div class="item success"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($warnings)): ?>
    <h3>⚠️ Warnings</h3>
    <?php foreach ($warnings as $msg): ?>
    <div class="item warning"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <h3>❌ Errors</h3>
    <?php foreach ($errors as $msg): ?>
    <div class="item error"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (empty($errors)): ?>
    <div style="text-align:center;margin-top:20px">
      <p style="color:#28a745;font-weight:600;font-size:16px">🎉 Installation completed successfully!</p>
      <a href="auth/login.php" class="btn">→ Go to Login</a>
    </div>
    <?php else: ?>
    <div style="text-align:center;margin-top:20px">
      <p style="color:#dc3545;font-weight:600">Fix the errors above, then <a href="install.php?force=1">re-run installer</a>.</p>
    </div>
    <?php endif; ?>
  </div>
  <div class="footer">
    <strong>⚠️ Security Notice:</strong> Delete <code>install.php</code> after successful installation!<br>
    Powered by Dr. Arslan Yasin Sukhera &nbsp;|&nbsp; &copy; <?php echo date('Y'); ?> &nbsp;&mdash;&nbsp; Arslan's Pharmacy ERP v7.0
  </div>
</div>
</body>
</html>
