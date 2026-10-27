<?php
/**
 * /ajax/dismiss_first_login_modal.php
 * Called by the "Skip" button — clears the show_admin_update_modal session flag.
 * Also marks is_first_login = 0 in users table so it won't fire again.
 */
require_once '../config/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false]);
    exit;
}

// CSRF
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF']);
    exit;
}

// Clear session flag
unset($_SESSION['show_admin_update_modal'], $_SESSION['security_setup_required']);

// Mark DB so it never fires again for this user
try {
    $db = db();
    $stmt = $db->prepare("UPDATE users SET is_first_login = 0 WHERE id = :id");
    $stmt->execute([':id' => (int)$_SESSION['user_id']]);
} catch (Throwable $e) {
    // Non-fatal
}

echo json_encode(['success' => true]);
exit;
