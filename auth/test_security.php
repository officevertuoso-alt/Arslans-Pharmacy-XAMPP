<?php
/**
 * DIAGNOSTIC FILE — Delete after fixing
 * Place at: auth/test_security.php
 * Visit: localhost/pharmacy_software/auth/test_security.php
 */

require_once '../config/config.php';
require_once '../includes/auth.php';

session_start() || true;

echo "<pre style='font-family:monospace;font-size:13px;padding:20px;'>";
echo "=== Security Setup Diagnostics ===\n\n";

// 1. Check session
echo "1. Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";
echo "   security_setup_required: " . ($_SESSION['security_setup_required'] ?? 'NOT SET') . "\n";
echo "   is_first_login: " . ($_SESSION['is_first_login'] ?? 'NOT SET') . "\n\n";

// 2. Check DB columns
try {
    $pdo = db()->getConnection();
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $needed = ['security_question','security_answer','is_first_login'];
    echo "2. DB Columns check:\n";
    foreach ($needed as $col) {
        echo "   " . $col . ": " . (in_array($col, $cols) ? "EXISTS ✓" : "MISSING ✗ — run migration SQL!") . "\n";
    }
} catch (Exception $e) {
    echo "2. DB Error: " . $e->getMessage() . "\n";
}

// 3. Check files exist
echo "\n3. Required files:\n";
$files = [
    '../includes/security_helpers.php',
    '../auth/save_security_setup.php',
];
foreach ($files as $f) {
    echo "   " . $f . ": " . (file_exists($f) ? "EXISTS ✓" : "MISSING ✗") . "\n";
}

// 4. Check security_helpers
echo "\n4. security_helpers.php loaded: ";
if (function_exists('getSecurityQuestions')) {
    echo "YES ✓\n   Questions: " . implode(', ', array_keys(getSecurityQuestions())) . "\n";
} else {
    if (file_exists('../includes/security_helpers.php')) {
        require_once '../includes/security_helpers.php';
        echo "NOW LOADED ✓\n";
    } else {
        echo "NOT AVAILABLE ✗\n";
    }
}

// 5. Test direct UPDATE
if (isset($_SESSION['user_id'])) {
    echo "\n5. Test DB update for user_id=" . $_SESSION['user_id'] . ":\n";
    try {
        $pdo = db()->getConnection();
        $stmt = $pdo->prepare("UPDATE users SET security_question='phone', security_answer='test', is_first_login=1 WHERE id=:id");
        $ok = $stmt->execute([':id' => $_SESSION['user_id']]);
        echo "   UPDATE result: " . ($ok ? "OK ✓" : "FAILED ✗") . "\n";
        echo "   Rows affected: " . $stmt->rowCount() . "\n";
        // Revert
        $pdo->prepare("UPDATE users SET is_first_login=1 WHERE id=:id")->execute([':id'=>$_SESSION['user_id']]);
    } catch (Exception $e) {
        echo "   ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n=== End Diagnostics ===\n";
echo "</pre>";