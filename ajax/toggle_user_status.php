<?php
/**
 * AJAX — Toggle User Status (Active / Inactive)
 * File: ajax/toggle_user_status.php
 * POST: user_id, status (0|1), csrf_token
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthenticated.']);
    exit;
}

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

// ── Method ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$targetId  = (int)($_POST['user_id'] ?? 0);
$newStatus = (int)($_POST['status']  ?? 0);
$newStatus = ($newStatus === 1) ? 1 : 0;

// ── Validate ──────────────────────────────────────────────────────────────────
if ($targetId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
    exit;
}

// Prevent deactivating self
if ($targetId === (int)$_SESSION['user_id'] && $newStatus === 0) {
    echo json_encode(['success' => false, 'message' => 'You cannot deactivate your own account.']);
    exit;
}

// Load target
$targetUser = getOne('SELECT id, username, role, is_protected FROM users WHERE id = :id', [':id' => $targetId]);
if (!$targetUser) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

// Protected user check — arslan/id=1 cannot be deactivated
if (!empty($targetUser['is_protected']) || $targetId === 1) {
    if ($newStatus === 0) {
        echo json_encode(['success' => false, 'message' => 'This system account is protected and cannot be deactivated.']);
        exit;
    }
}

// Last admin check when deactivating
if ($newStatus === 0 && $targetUser['role'] === 'admin') {
    $remaining = getOne(
        'SELECT COUNT(*) AS cnt FROM users WHERE role = :role AND status = 1 AND id != :id',
        [':role' => 'admin', ':id' => $targetId]
    );
    if ((int)($remaining['cnt'] ?? 0) === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot deactivate the last active admin.',
        ]);
        exit;
    }
}

// ── Update ────────────────────────────────────────────────────────────────────
try {
    $db   = db();
    $stmt = $db->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':status' => $newStatus, ':id' => $targetId]);

    $statusLabel = $newStatus === 1 ? 'activated' : 'deactivated';
    logActivity('TOGGLE_USER_STATUS', "User {$targetUser['username']} {$statusLabel} (ID: {$targetId})");

    echo json_encode([
        'success' => true,
        'message' => "User has been " . ($newStatus ? 'activated' : 'deactivated') . " successfully.",
        'status'  => $newStatus,
    ]);

} catch (Exception $e) {
    error_log('toggle_user_status error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}