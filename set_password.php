<?php
require_once 'config/config.php';

try {
    $pdo = db()->getConnection();

    // Generate a fresh bcrypt hash for 'arslan123'
    $hash = password_hash('arslan123', PASSWORD_BCRYPT);

    // Insert or update the user – only one placeholder :password
    $stmt = $pdo->prepare("
        INSERT INTO users (username, password, full_name, email, role, status)
        VALUES ('arslan', :password, 'Arslan', 'arslan@example.com', 'admin', 1)
        ON DUPLICATE KEY UPDATE
            password = VALUES(password),
            full_name = VALUES(full_name),
            email = VALUES(email),
            role = VALUES(role),
            status = VALUES(status)
    ");
    $stmt->execute([':password' => $hash]);

    // Verify it worked
    $check = $pdo->prepare("SELECT username, status FROM users WHERE username = 'arslan'");
    $check->execute();
    $user = $check->fetch();

    if ($user) {
        echo "✅ User 'arslan' created/updated successfully!<br>";
        echo "Password: <strong>arslan123</strong><br>";
        echo "Hash stored: <code>" . htmlspecialchars($hash) . "</code><br>";
        echo "<br><a href='auth/login.php'>Go to Login</a>";
    } else {
        echo "❌ Insert failed – user not found after insert.";
    }

} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "Stack trace: <pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}