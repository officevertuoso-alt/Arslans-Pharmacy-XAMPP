<?php
/**
 * Login Fix Tool — Run this ONCE then DELETE it
 * Place in: C:\xampp\htdocs\pharmacy_software\reset_password.php
 * Visit: http://localhost/pharmacy_software/reset_password.php
 */

// Start fresh session
session_start();

// Clear ALL login attempt throttling
unset($_SESSION['login_attempts']);
unset($_SESSION['captcha_q']);
unset($_SESSION['captcha_a']);
session_destroy();

// Now set correct password via DB
require_once __DIR__ . '/config/config.php';

$newPassword = 'arslan123';
$bcryptHash  = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);

try {
    $pdo = db()->getConnection();
    $stmt = $pdo->prepare("UPDATE users SET password = ?");
    $stmt->execute([$bcryptHash]);
    $affected = $stmt->rowCount();
    
    // Also clear any active sessions table
    try { $pdo->exec("DELETE FROM user_sessions"); } catch(Exception $e) {}
    try { $pdo->exec("UPDATE users SET remember_token = NULL"); } catch(Exception $e) {}

    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:40px;max-width:500px;margin:auto'>";
    echo "<h2 style='color:#16a34a'>✅ Sab Fix Ho Gaya!</h2>";
    echo "<p>✔ <strong>$affected user(s)</strong> ka password reset: <code>arslan123</code></p>";
    echo "<p>✔ Login attempts cleared (CAPTCHA hatega)</p>";
    echo "<p>✔ Old sessions cleared</p>";
    echo "<hr>";
    
    $rows = $pdo->query("SELECT id, username, full_name, role FROM users ORDER BY id")->fetchAll();
    echo "<table border='1' cellpadding='10' style='border-collapse:collapse;width:100%'>";
    echo "<tr style='background:#f3f4f6'><th>Username</th><th>Name</th><th>Role</th><th>Password</th></tr>";
    foreach ($rows as $r) {
        echo "<tr><td><strong>{$r['username']}</strong></td><td>{$r['full_name']}</td><td>{$r['role']}</td><td>arslan123</td></tr>";
    }
    echo "</table>";
    echo "<br><a href='auth/login.php' style='background:#3b82f6;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;font-size:16px'>🔑 Login Karo</a>";
    echo "<br><br><span style='color:#dc2626'>⚠️ Is file ko DELETE karo login ke baad!</span>";
    echo "</body></html>";
    
} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ Error: " . $e->getMessage() . "</h2>";
}
?>
