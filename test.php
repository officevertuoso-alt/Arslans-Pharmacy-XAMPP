<?php
require_once 'config/config.php';
$pdo = db()->getConnection();
$stmt = $pdo->prepare("SELECT id, username, password FROM users WHERE id = ?");
$stmt->execute([2]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo "User: " . $user['username'] . "<br>";
echo "Hash starts with: " . substr($user['password'], 0, 7) . "<br>";
echo "password_verify test: " . (password_verify('password', $user['password']) ? 'TRUE' : 'FALSE') . "<br>";
?>