<?php
/**
 * ONE-TIME DATABASE FIX
 * Upload this file to your pharmacy_software root folder
 * Then open: localhost/pharmacy_software/fix_db.php
 * It will add the missing column and fix everything.
 * DELETE THIS FILE after running it.
 */

require_once __DIR__ . '/config/config.php';

$pdo = _getPDO();
$results = [];

// Fix 1: Add missing payment_method column to supplier_payments
try {
    $cols = $pdo->query("SHOW COLUMNS FROM supplier_payments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('payment_method', $cols)) {
        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN payment_method VARCHAR(50) NULL DEFAULT 'cash'");
        $results[] = "✅ Added payment_method column to supplier_payments";
    } else {
        $results[] = "✓ payment_method column already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ payment_method fix failed: " . $e->getMessage();
}

// Fix 2: Add missing created_by column to supplier_payments
try {
    $cols = $pdo->query("SHOW COLUMNS FROM supplier_payments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('created_by', $cols)) {
        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN created_by INT NULL DEFAULT NULL");
        $results[] = "✅ Added created_by column to supplier_payments";
    } else {
        $results[] = "✓ created_by column already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ created_by fix failed: " . $e->getMessage();
}

// Fix 3: Add missing reference_no column to supplier_payments
try {
    $cols = $pdo->query("SHOW COLUMNS FROM supplier_payments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reference_no', $cols)) {
        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN reference_no VARCHAR(100) NULL DEFAULT NULL");
        $results[] = "✅ Added reference_no column to supplier_payments";
    } else {
        $results[] = "✓ reference_no column already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ reference_no fix failed: " . $e->getMessage();
}

// Fix 4: Add missing voided column to cashbook
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cashbook")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('voided', $cols)) {
        $pdo->exec("ALTER TABLE cashbook ADD COLUMN voided TINYINT(1) NOT NULL DEFAULT 0");
        $results[] = "✅ Added voided column to cashbook";
    } else {
        $results[] = "✓ voided column already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ voided fix failed: " . $e->getMessage();
}

// Show current supplier_payments structure
try {
    $cols = $pdo->query("SHOW COLUMNS FROM supplier_payments")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    $results[] = "📋 supplier_payments columns: " . implode(', ', $colNames);
} catch (Exception $e) {
    $results[] = "❌ Could not read structure: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>DB Fix</title>
    <style>
        body { font-family: monospace; padding: 30px; background: #1a1a2e; color: #eee; }
        h2 { color: #00d4ff; }
        .result { padding: 8px 12px; margin: 6px 0; border-radius: 4px; background: #16213e; font-size: 15px; }
        .warn { background: #2d1b00; color: #ffd700; padding: 15px; border-radius: 6px; margin-top: 20px; font-size: 16px; }
    </style>
</head>
<body>
    <h2>⚙️ Database Fix Results</h2>
    <?php foreach ($results as $r): ?>
        <div class="result"><?= htmlspecialchars($r) ?></div>
    <?php endforeach; ?>
    <div class="warn">
        ⚠️ <strong>DELETE this file from your server now!</strong><br>
        Go to your pharmacy_software folder and delete <code>fix_db.php</code>
    </div>
</body>
</html>