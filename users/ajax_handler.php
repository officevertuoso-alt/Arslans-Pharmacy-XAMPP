<?php
/**
 * Users Module — AJAX Handler
 * File: users/ajax_handler.php
 * Phase 20 — User Management
 *
 * Actions:
 *   check_username     — availability check
 *   upload_avatar      — profile picture upload (returns filename)
 *   remove_avatar      — remove profile picture
 *   force_logout       — kick user out of all sessions
 *   send_password_reset— send password reset email
 *   get_user_summary   — quick JSON summary of user for inline preview
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated.']);
    exit;
}

$action = sanitize($_REQUEST['action'] ?? '');

// ── check_username (GET, no CSRF needed) ─────────────────────────────────────
if ($action === 'check_username') {
    $username   = sanitize($_GET['username'] ?? '');
    $excludeId  = (int)($_GET['exclude_id'] ?? 0);

    if ($username === '') {
        echo json_encode(['available' => false, 'message' => 'Username required.']);
        exit;
    }
    if (strlen($username) < 3) {
        echo json_encode(['available' => false, 'message' => 'Min 3 characters.']);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        echo json_encode(['available' => false, 'message' => 'Letters, numbers and _ only.']);
        exit;
    }
    $row = getOne(
        'SELECT id FROM users WHERE username = :u AND id != :ex',
        [':u' => $username, ':ex' => $excludeId]
    );
    if ($row) {
        echo json_encode(['available' => false, 'message' => 'Username already taken.']);
    } else {
        echo json_encode(['available' => true,  'message' => 'Username available.']);
    }
    exit;
}

// ── All POST actions require CSRF ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

// ── upload_avatar ─────────────────────────────────────────────────────────────
if ($action === 'upload_avatar') {
    requirePermission('users', 'edit');

    $targetId = (int)($_POST['user_id'] ?? 0);
    if ($targetId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
        exit;
    }

    // Non-admin users can only change their own avatar
    if (!isAdmin() && $targetId !== (int)$_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
        exit;
    }

    $err = $_FILES['avatar']['error'];
    if ($err !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk.',
        ];
        echo json_encode(['success' => false, 'message' => $errMap[$err] ?? 'Upload error.']);
        exit;
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($_FILES['avatar']['tmp_name']);
    $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (!in_array($mimeType, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, GIF or WEBP allowed.']);
        exit;
    }
    if ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File must be under 2 MB.']);
        exit;
    }

    $ext       = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
    $newFile   = 'user_' . $targetId . '_' . time() . '.' . $ext;
    $uploadDir = '../uploads/avatars/';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'Cannot create upload directory.']);
        exit;
    }
    if (!is_writable($uploadDir)) {
        echo json_encode(['success' => false, 'message' => 'Upload directory not writable.']);
        exit;
    }

    // Get current image to delete old one
    $current = getOne('SELECT profile_image FROM users WHERE id = :id', [':id' => $targetId]);
    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $newFile)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
        exit;
    }

    // Delete old file
    if (!empty($current['profile_image'])) {
        $oldPath = $uploadDir . basename($current['profile_image']);
        if (file_exists($oldPath)) @unlink($oldPath);
    }

    // Update DB (sync both columns)
    try {
        $db = db();
        $stmt = $db->prepare('UPDATE users SET profile_image = :img, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':img' => $newFile, ':id' => $targetId]);
    } catch (Exception $e) {
        error_log('upload_avatar DB error: ' . $e->getMessage());
        @unlink($uploadDir . $newFile);
        echo json_encode(['success' => false, 'message' => 'Failed to update database.']);
        exit;
    }

    logActivity((int)$_SESSION['user_id'], 'UPLOAD_AVATAR', "Avatar updated for user ID: {$targetId}");

    echo json_encode([
        'success'   => true,
        'message'   => 'Profile picture updated.',
        'filename'  => $newFile,
        'url'       => '../uploads/avatars/' . $newFile,
    ]);
    exit;
}

// ── remove_avatar ─────────────────────────────────────────────────────────────
if ($action === 'remove_avatar') {
    requirePermission('users', 'edit');
    $targetId = (int)($_POST['user_id'] ?? 0);

    if (!isAdmin() && $targetId !== (int)$_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    $current = getOne('SELECT profile_image FROM users WHERE id = :id', [':id' => $targetId]);
    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    if (!empty($current['profile_image'])) {
        $path = '../uploads/avatars/' . basename($current['profile_image']);
        if (file_exists($path)) @unlink($path);
    }

    $db = db();
    $stmt = $db->prepare('UPDATE users SET profile_image = NULL, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $targetId]);

    logActivity((int)$_SESSION['user_id'], 'REMOVE_AVATAR', "Avatar removed for user ID: {$targetId}");
    echo json_encode(['success' => true, 'message' => 'Profile picture removed.']);
    exit;
}

// ── force_logout ──────────────────────────────────────────────────────────────
if ($action === 'force_logout') {
    requireAdmin();
    $targetId = (int)($_POST['user_id'] ?? 0);

    if ($targetId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
        exit;
    }
    if ($targetId === (int)$_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'You cannot force-logout yourself.']);
        exit;
    }

    // Check protected
    $target = getOne('SELECT id, username, is_protected FROM users WHERE id = :id', [':id' => $targetId]);
    if (!$target) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }
    if (!empty($target['is_protected'])) {
        echo json_encode(['success' => false, 'message' => 'This system user cannot be force-logged out.']);
        exit;
    }

    try {
        $pdo = db()->getConnection();
        // Delete active sessions
        $stmt = $pdo->prepare('DELETE FROM active_sessions WHERE user_id = :uid');
        $stmt->execute([':uid' => $targetId]);
        logActivity((int)$_SESSION['user_id'], 'FORCE_LOGOUT', "Forced logout for user: {$target['username']} (ID: {$targetId})");
        echo json_encode(['success' => true, 'message' => "User \"{$target['username']}\" has been logged out from all devices."]);
    } catch (Exception $e) {
        error_log('force_logout error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not terminate sessions.']);
    }
    exit;
}

// ── get_user_summary ──────────────────────────────────────────────────────────
if ($action === 'get_user_summary') {
    requireAdmin();
    $targetId = (int)($_POST['user_id'] ?? 0);
    $user = getOne(
        'SELECT id, full_name, username, email, phone, role, status, last_login, login_count, created_at, profile_image FROM users WHERE id = :id',
        [':id' => $targetId]
    );
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }
    echo json_encode(['success' => true, 'user' => $user]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
