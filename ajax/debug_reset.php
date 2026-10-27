<?php
/**
 * debug_reset.php — TEMPORARY DEBUG FILE
 * Place in: pharmacy_software/ajax/debug_reset.php
 * Open in browser, check output, then DELETE this file immediately.
 */
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: text/plain; charset=utf-8');

session_start();

echo "=== SESSION KEYS ===\n";
foreach ($_SESSION as $k => $v) {
    // mask password if stored
    if (stripos($k, 'pass') !== false) $v = '***hidden***';
    echo "  [{$k}] => " . print_r($v, true) . "\n";
}

echo "\n=== USERS TABLE COLUMNS ===\n";
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $cols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  " . $c['Field'] . " (" . $c['Type'] . ")" . ($c['Key'] === 'PRI' ? ' [PK]' : '') . "\n";
    }

    echo "\n=== USERS TABLE DATA (passwords masked) ===\n";
    $users = $pdo->query("SELECT * FROM `users` LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $u) {
        foreach ($u as $k => $v) {
            if (stripos($k, 'pass') !== false) $u[$k] = substr($v, 0, 7) . '... [hash len=' . strlen($v) . ']';
        }
        echo "  " . json_encode($u) . "\n";
    }

    echo "\n=== PASSWORD VERIFY TEST ===\n";
    echo "Enter your password in URL: ?test_pass=yourpassword\n";
    if (!empty($_GET['test_pass'])) {
        $testPass = $_GET['test_pass'];
        $allUsers = $pdo->query("SELECT id, username, password FROM `users` LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allUsers as $u) {
            $result = password_verify($testPass, $u['password']);
            echo "  User [{$u['id']}] {$u['username']}: " . ($result ? "✅ MATCH" : "❌ no match") . "\n";
        }
    }

} catch (\Throwable $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}

echo "\n⚠ DELETE THIS FILE AFTER DEBUGGING\n";