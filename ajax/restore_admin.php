<?php
/**
 * restore_admin.php — TEMPORARY, DELETE AFTER USE
 * Place in: pharmacy_software/ajax/restore_admin.php
 * Open: localhost/pharmacy_software/ajax/restore_admin.php
 * This inserts a fresh admin user when users table is empty.
 */
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// ISS-016 FIX: Require admin auth + CSRF for this sensitive endpoint
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}
$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token.']);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Check if users table is empty
    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "Current user count: {$count}\n\n";

    if ($count > 0) {
        echo "Users already exist. Nothing to do.\n";
        echo "If you still have login issues, open with ?force=1 to reset admin password.\n";

        if (!isset($_GET['force'])) {
            exit;
        }
        echo "\n-- FORCE MODE: updating existing admin password --\n";
    }

    // Read actual columns
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $allCols = array_column($cols, 'Field');

    $uname = 'admin';
    $upass = 'admin123';
    $hash  = password_hash($upass, PASSWORD_BCRYPT);

    if ($count > 0 && isset($_GET['force'])) {
        // Just update password of first admin
        $pdo->prepare("UPDATE users SET password = ? WHERE role = 'admin' ORDER BY id ASC LIMIT 1")
            ->execute([$hash]);
        echo "Admin password reset to: admin123\n";
        echo "Please login with: admin / admin123\n";
        echo "\n⚠ DELETE THIS FILE AFTER USE\n";
        exit;
    }

    // Build INSERT with only existing columns
    $insertCols = ['username', 'password'];
    $insertVals = [$uname, $hash];

    foreach (['full_name','name','display_name','fullname'] as $nc) {
        if (in_array($nc, $allCols, true)) {
            $insertCols[] = $nc; $insertVals[] = 'Administrator'; break;
        }
    }
    if (in_array('email',               $allCols, true)) { $insertCols[] = 'email';               $insertVals[] = 'admin@pharmacy.com'; }
    if (in_array('role',                $allCols, true)) { $insertCols[] = 'role';                $insertVals[] = 'admin'; }
    if (in_array('status',              $allCols, true)) { $insertCols[] = 'status';              $insertVals[] = 1; }
    if (in_array('is_active',           $allCols, true)) { $insertCols[] = 'is_active';           $insertVals[] = 1; }
    if (in_array('permissions_set',     $allCols, true)) { $insertCols[] = 'permissions_set';     $insertVals[] = 1; }
    if (in_array('is_first_login',      $allCols, true)) { $insertCols[] = 'is_first_login';      $insertVals[] = 0; }
    if (in_array('force_password_change',$allCols,true)) { $insertCols[] = 'force_password_change';$insertVals[] = 0; }
    if (in_array('created_at',          $allCols, true)) { $insertCols[] = 'created_at';          $insertVals[] = date('Y-m-d H:i:s'); }

    $colStr = implode(', ', array_map(fn($c) => "`{$c}`", $insertCols));
    $phStr  = implode(', ', array_fill(0, count($insertVals), '?'));
    $pdo->prepare("INSERT INTO `users` ({$colStr}) VALUES ({$phStr})")->execute($insertVals);

    $newId = $pdo->lastInsertId();
    echo "✅ Admin user created successfully!\n";
    echo "   ID:       {$newId}\n";
    echo "   Username: {$uname}\n";
    echo "   Password: {$upass}\n\n";
    echo "Now go to: localhost/pharmacy_software/login.php\n";
    echo "Login with: admin / admin123\n";
    echo "\n⚠ DELETE THIS FILE IMMEDIATELY AFTER USE\n";

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}