<?php
/**
 * AJAX — Force Logout User
 * File: ajax/force_logout_user.php
 * POST: user_id, csrf_token
 * Phase 20: delegates to users/ajax_handler.php logic
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthenticated.']);
    exit;
}
if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$targetId = (int)($_POST['user_id'] ?? 0);
if ($targetId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
    exit;
}
if ($targetId === (int)$_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'You cannot force-logout yourself.']);
    exit;
}

$target = getOne('SELECT id, username, is_protected FROM users WHERE id = :id', [':id' => $targetId]);
if (!$target) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}
if (!empty($target['is_protected'])) {
    echo json_encode(['success' => false, 'message' => 'This system account cannot be force-logged out.']);
    exit;
}

try {
    $pdo  = db()->getConnection();
    $stmt = $pdo->prepare('DELETE FROM active_sessions WHERE user_id = :uid');
    $stmt->execute([':uid' => $targetId]);

    logActivity((int)$_SESSION['user_id'], 'FORCE_LOGOUT',
        "Forced logout for user: {$target['username']} (ID: {$targetId})");

    echo json_encode([
        'success' => true,
        'message' => "User \"{$target['username']}\" has been logged out from all devices.",
    ]);
} catch (Exception $e) {
    error_log('force_logout_user error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not terminate sessions.']);
}
