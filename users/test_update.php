<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// Test updateUser with user id=1
$data = [
    'full_name' => 'Arslan',
    'username'  => 'admin',
    'email'     => 'admin@pharmacy.com',
    'phone'     => '9876543210',
    'role'      => 'admin',
    'status'    => 1,
];

try {
    $result = updateUser(1, $data, []);
    echo "Result: " . ($result ? "SUCCESS" : "FAILED");
} catch (Throwable $e) {
    echo "Exception: " . $e->getMessage();
}