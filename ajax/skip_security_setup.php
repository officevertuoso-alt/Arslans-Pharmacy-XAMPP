<?php
/**
 * Skip Security Setup — First Login
 * File: ajax/skip_security_setup.php
 * POST only — returns JSON
 *
 * Allowed ONLY when admin has already pre-set a security_question for the user.
 * Clears the first-login flag without changing question/answer.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
require_once '../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired.']);
    exit;
}

// ISS-016 FIX: Verify CSRF token on mutation endpoint
$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    $pdo = db()->getConnection();

    // Only allow skip if a security_question is already set by admin
    $stmt = $pdo->prepare("SELECT security_question FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['security_question'])) {
        echo json_encode([
            'success' => false,
            'message' => 'You must set a security question before continuing.',
        ]);
        exit;
    }

    // Clear first-login flag only
    $upd = $pdo->prepare("UPDATE users SET is_first_login = 0 WHERE id = :id");
    $upd->execute([':id' => $userId]);

    unset($_SESSION['security_setup_required']);
    $_SESSION['is_first_login'] = 0;

    try {
        if (function_exists('logActivity')) {
            logActivity($userId, 'SECURITY_SETUP_SKIPPED', 'User skipped security setup (admin question already set).');
        }
    } catch (Exception $e) {}

    $redirectUrl = defined('BASE_URL') ? BASE_URL . 'dashboard.php' : '../dashboard.php';

    echo json_encode([
        'success'  => true,
        'message'  => 'Skipped. Existing security question kept.',
        'redirect' => $redirectUrl,
    ]);

} catch (Exception $e) {
    error_log('[skip_security_setup] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}