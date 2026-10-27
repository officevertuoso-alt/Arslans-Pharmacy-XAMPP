<?php
/**
 * debug_pass.php — TEMPORARY, DELETE AFTER USE
 * Place in: pharmacy_software/ajax/debug_pass.php
 * Open: localhost/pharmacy_software/ajax/debug_pass.php?p=YOUR_PASSWORD
 */
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== DB CONNECTION TEST ===\n";
echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'NOT DEFINED') . "\n";
echo "DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'NOT DEFINED') . "\n";
echo "DB_USER: " . (defined('DB_USER') ? DB_USER : 'NOT DEFINED') . "\n";

echo "\n=== DB() FUNCTION TEST ===\n";
try {
    $db = db();
    echo "db() type: " . get_class($db) . "\n";

    // Try to get actual PDO
    $pdo = null;
    if ($db instanceof PDO) {
        $pdo = $db;
        echo "db() returned PDO directly\n";
    } elseif (method_exists($db, 'getConnection')) {
        $pdo = $db->getConnection();
        echo "db() has getConnection()\n";
    } elseif (method_exists($db, 'getPdo')) {
        $pdo = $db->getPdo();
        echo "db() has getPdo()\n";
    }

    if ($pdo) {
        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
        echo "Connected DB: {$dbName}\n";
    }
} catch (\Throwable $e) {
    echo "db() ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== DIRECT PDO CONNECTION ===\n";
try {
    $pdo2 = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $dbName2 = $pdo2->query("SELECT DATABASE()")->fetchColumn();
    echo "Direct PDO DB: {$dbName2}\n";

    $users = $pdo2->query("SELECT id, username, LENGTH(password) as hash_len, SUBSTRING(password,1,7) as hash_start, role, status, is_active FROM users LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "\n=== USERS (direct PDO) ===\n";
    foreach ($users as $u) {
        echo "  id={$u['id']} user={$u['username']} role={$u['role']} status={$u['status']} is_active={$u['is_active']} hash_len={$u['hash_len']} hash_start={$u['hash_start']}\n";
    }

    // Password test
    if (!empty($_GET['p'])) {
        $testPass = $_GET['p'];
        echo "\n=== PASSWORD VERIFY TEST (pass=" . htmlspecialchars($testPass) . ") ===\n";
        $rows = $pdo2->query("SELECT id, username, password FROM users LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $ok = password_verify($testPass, $r['password']);
            $algo = null;
            if (str_starts_with($r['password'], '$2y$')) $algo = 'bcrypt';
            elseif (str_starts_with($r['password'], '$argon')) $algo = 'argon2';
            elseif (str_starts_with($r['password'], 'md5:') || strlen($r['password']) === 32) $algo = 'MD5 (not bcrypt!)';
            else $algo = 'unknown format';
            echo "  [{$r['id']}] {$r['username']}: " . ($ok ? "✅ MATCH" : "❌ no match") . " | algo: {$algo}\n";
        }

        // Also test MD5 just in case
        echo "\n=== MD5 TEST ===\n";
        $md5 = md5($testPass);
        foreach ($rows as $r) {
            echo "  [{$r['id']}] {$r['username']}: md5 match=" . ($md5 === $r['password'] ? '✅ YES (password stored as MD5!)' : 'no') . "\n";
        }

        // SHA1 test
        echo "\n=== SHA1 TEST ===\n";
        $sha1 = sha1($testPass);
        foreach ($rows as $r) {
            echo "  [{$r['id']}] {$r['username']}: sha1 match=" . ($sha1 === $r['password'] ? '✅ YES (stored as SHA1!)' : 'no') . "\n";
        }
    } else {
        echo "\n(Add ?p=yourpassword to test password verification)\n";
    }

} catch (\Throwable $e) {
    echo "Direct PDO ERROR: " . $e->getMessage() . "\n";
}

echo "\n⚠ DELETE THIS FILE AFTER DEBUGGING\n";