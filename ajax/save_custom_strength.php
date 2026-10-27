<?php
/**
 * ajax/save_custom_strength.php
 * Save a user-defined custom strength value.
 * Called from products/add.php and products/edit.php strength dropdown.
 * Returns JSON: { success, id, strength }
 */
session_start();
require_once '../config/config.php';

requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$strength = trim($_POST['strength'] ?? '');

if (empty($strength)) {
    echo json_encode(['success' => false, 'message' => 'Strength value is required']);
    exit;
}

if (strlen($strength) > 50) {
    echo json_encode(['success' => false, 'message' => 'Strength value too long (max 50 chars)']);
    exit;
}

$db = db();

// Check for existing (case-insensitive)
try {
    $existing = $db->getOne(
        "SELECT id, strength FROM custom_strengths WHERE LOWER(strength) = LOWER(:s)",
        [':s' => $strength]
    );
    if ($existing) {
        echo json_encode([
            'success'  => true,
            'id'       => (int)$existing['id'],
            'strength' => $existing['strength'],
            'message'  => 'Already exists',
        ]);
        exit;
    }

    // Ensure table exists
    $db->run(
        "CREATE TABLE IF NOT EXISTS `custom_strengths` (
            `id`         int(11)      NOT NULL AUTO_INCREMENT,
            `strength`   varchar(50)  NOT NULL,
            `created_by` int(11)      DEFAULT NULL,
            `created_at` timestamp    NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_strength` (`strength`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        []
    );

    $db->run(
        "INSERT INTO custom_strengths (strength, created_by) VALUES (:s, :uid)",
        [':s' => $strength, ':uid' => (int)($_SESSION['user_id'] ?? 1)]
    );
    $newId = (int)$db->lastInsertId();

    echo json_encode([
        'success'  => true,
        'id'       => $newId,
        'strength' => $strength,
        'message'  => 'Saved successfully',
    ]);

} catch (Exception $e) {
    error_log('save_custom_strength error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
exit;
