<?php
/**
 * DB PATCH — Add missing `can_print` column to role_permissions / modules table
 * pharmacy_software/config/db_patch.php
 *
 * RUN THIS ONCE to fix:
 *   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'can_print' in 'field list'
 *
 * Place this file in: pharmacy_software/config/db_patch.php
 * Then open in browser: http://localhost/pharmacy_software/config/db_patch.php
 * Delete after running.
 */

require_once __DIR__ . '/config.php';

$db  = db();
$pdo = $db->getConnection();

$errors  = [];
$success = [];

// ─────────────────────────────────────────────────────────────
// 1. Add can_print to role_permissions if missing
// ─────────────────────────────────────────────────────────────
try {
    $tables = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchAll();
    if ($tables) {
        $cols = array_column(
            $pdo->query("SHOW COLUMNS FROM role_permissions")->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
        if (!in_array('can_print', $cols)) {
            $pdo->exec("ALTER TABLE role_permissions ADD COLUMN can_print TINYINT(1) NOT NULL DEFAULT 1 AFTER can_delete");
            $success[] = "✅ Added 'can_print' column to role_permissions";
        } else {
            $success[] = "ℹ️  'can_print' already exists in role_permissions";
        }
        // Also add other commonly missing columns
        foreach (['can_view','can_add','can_edit','can_delete','can_print','can_export'] as $col) {
            if (!in_array($col, $cols)) {
                $pdo->exec("ALTER TABLE role_permissions ADD COLUMN `$col` TINYINT(1) NOT NULL DEFAULT 1");
                $success[] = "✅ Added '$col' to role_permissions";
            }
        }
    } else {
        $success[] = "ℹ️  role_permissions table does not exist (skipped)";
    }
} catch (\PDOException $e) {
    $errors[] = "role_permissions patch failed: " . $e->getMessage();
}

// ─────────────────────────────────────────────────────────────
// 2. Add can_print to modules if queried there
// ─────────────────────────────────────────────────────────────
try {
    $tables = $pdo->query("SHOW TABLES LIKE 'modules'")->fetchAll();
    if ($tables) {
        $cols = array_column(
            $pdo->query("SHOW COLUMNS FROM modules")->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
        foreach (['can_print','is_active','module_key'] as $col) {
            if (!in_array($col, $cols)) {
                $def = ($col === 'is_active' || $col === 'can_print') ? "TINYINT(1) NOT NULL DEFAULT 1" : "VARCHAR(100) DEFAULT NULL";
                $pdo->exec("ALTER TABLE modules ADD COLUMN `$col` $def");
                $success[] = "✅ Added '$col' to modules";
            }
        }
        // Populate module_key from module name if empty
        $pdo->exec("UPDATE modules SET module_key = LOWER(REPLACE(name,' ','_')) WHERE module_key IS NULL OR module_key = ''");
        $success[] = "✅ Populated empty module_key values";
    }
} catch (\PDOException $e) {
    $errors[] = "modules patch failed: " . $e->getMessage();
}

// ─────────────────────────────────────────────────────────────
// 3. Ensure cashbook module exists in modules table
// ─────────────────────────────────────────────────────────────
try {
    $tables = $pdo->query("SHOW TABLES LIKE 'modules'")->fetchAll();
    if ($tables) {
        $cashbookModule = $db->getOne("SELECT id FROM modules WHERE module_key = 'cashbook'");
        if (!$cashbookModule) {
            $pdo->exec("INSERT INTO modules (name, module_key, is_active) VALUES ('Cashbook', 'cashbook', 1)");
            $success[] = "✅ Cashbook module added to modules table";
        } else {
            $success[] = "ℹ️  Cashbook module already registered";
        }
    }
} catch (\PDOException $e) {
    $errors[] = "Cashbook module insert failed: " . $e->getMessage();
}

// ─────────────────────────────────────────────────────────────
// Output results
// ─────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>DB Patch — Pharmacy Software</title>
    <style>
        body { font-family: monospace; max-width: 700px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
        h2 { color: #2c3e50; }
        .ok  { color: #155724; background: #d4edda; padding: 6px 10px; margin: 4px 0; border-radius: 4px; }
        .err { color: #721c24; background: #f8d7da; padding: 6px 10px; margin: 4px 0; border-radius: 4px; }
        .warn{ color: #856404; background: #fff3cd; padding: 6px 10px; margin: 4px 0; border-radius: 4px; }
        a { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #2c3e50; color: #fff; text-decoration: none; border-radius: 6px; }
    </style>
</head>
<body>
    <h2>🔧 Database Patch Results</h2>
    <?php foreach ($success as $msg): ?>
        <div class="ok"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $msg): ?>
        <div class="err">❌ <?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php if (empty($errors)): ?>
        <div class="ok" style="margin-top:16px;font-weight:bold;">✅ Patch complete! You can now delete this file.</div>
        <a href="../cashbook/index.php">Go to Cashbook →</a>
    <?php else: ?>
        <div class="warn">⚠️ Some patches failed. Check errors above and run again or fix manually.</div>
    <?php endif; ?>
    <br><br>
    <small style="color:#999;">Delete this file after use: <code>pharmacy_software/config/db_patch.php</code></small>
</body>
</html>
