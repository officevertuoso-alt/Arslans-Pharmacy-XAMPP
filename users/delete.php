<?php
/**
 * Users Management — Delete User
 * File: users/delete.php
 * POST only — returns JSON
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// Always return JSON
header('Content-Type: application/json');

// ── Method check ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated.']);
    exit;
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin role required.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']);
    exit;
}

// ── Validate user_id ──────────────────────────────────────────────────────────
$targetId = (int)($_POST['user_id'] ?? 0);
if ($targetId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
    exit;
}

// ── Self-delete check ─────────────────────────────────────────────────────────
if ($targetId === (int)$_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']);
    exit;
}

// ── Load target user ──────────────────────────────────────────────────────────
$targetUser = getOne(
    'SELECT id, full_name, username, role, is_protected FROM users WHERE id = :id',
    [':id' => $targetId]
);

if (!$targetUser) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

// ── Protected user check ─────────────────────────────────────────────────────
// Prevents deletion of arslan (id=1) or any user with is_protected = 1
if (!empty($targetUser['is_protected']) || $targetId === 1) {
    echo json_encode([
        'success' => false,
        'message' => 'This system account is protected and cannot be deleted.',
    ]);
    exit;
}

// ── Last admin check ──────────────────────────────────────────────────────────
if ($targetUser['role'] === 'admin') {
    $adminCount = getOne(
        'SELECT COUNT(*) AS cnt FROM users WHERE role = :role AND status = 1 AND id != :id',
        [':role' => 'admin', ':id' => $targetId]
    );

    if ((int)($adminCount['cnt'] ?? 0) === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete the last active admin. Please promote another user to admin first.',
        ]);
        exit;
    }
}

// ── Delete (hard delete with cascade, or soft delete) ─────────────────────────
// Using hard delete — ensure foreign key cascades are set in DB.
// If you prefer soft delete, change to: UPDATE users SET status = 0 WHERE id = :id
try {
    $db = db();
    $db->beginTransaction();

    // Delete permissions first
    $stmt = $db->prepare('DELETE FROM user_permissions WHERE user_id = :id');
    $stmt->execute([':id' => $targetId]);

    // Delete user
    $stmt2 = $db->prepare('DELETE FROM users WHERE id = :id');
    $stmt2->execute([':id' => $targetId]);

    $db->commit();

    logActivity(
        'DELETE_USER',
        "User deleted: {$targetUser['username']} (ID: {$targetId}, Role: {$targetUser['role']})"
    );

    echo json_encode([
        'success' => true,
        'message' => "User \"{$targetUser['full_name']}\" has been deleted successfully.",
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollback();
    error_log('Delete user error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to delete user. They may have associated records in the system.',
    ]);
}