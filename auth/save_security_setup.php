<?php
/**
 * Security Setup — Save Security Question & Answer (First Login)
 * File: auth/save_security_setup.php
 * POST only — returns JSON
 *
 * Called from:
 *   1. login.php   (form fields: security_question, security_answer, csrf_token)
 *   2. includes/security_setup_widget.php (same fields)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/security_helpers.php';

header('Content-Type: application/json');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Session guard ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

// ── CSRF check ────────────────────────────────────────────────────────────────
// Only enforce when token is actually submitted (widget always sends it)
$submittedToken = $_POST['csrf_token'] ?? '';
if ($submittedToken !== '' && !verifyCSRFToken($submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh the page.']);
    exit;
}

// ── Input — support both field-name conventions ───────────────────────────────
$question = trim(
    $_POST['security_question'] ??
    $_POST['question'] ??
    ''
);
$answer = trim(
    $_POST['security_answer'] ??
    $_POST['answer'] ??
    ''
);

// ── Validate question ─────────────────────────────────────────────────────────
if ($question === '') {
    echo json_encode(['success' => false, 'message' => 'Please select a security question.']);
    exit;
}

$allowedQuestions = getSecurityQuestions();

// Accept both key ("phone") and full label ("Your Phone Number")
if (!array_key_exists($question, $allowedQuestions)) {
    $flipped = array_flip($allowedQuestions);
    if (isset($flipped[$question])) {
        $question = $flipped[$question];
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid security question selected.']);
        exit;
    }
}

// ── Validate answer ───────────────────────────────────────────────────────────
if ($answer === '') {
    echo json_encode(['success' => false, 'message' => 'Please provide an answer.']);
    exit;
}
if (strlen($answer) < 2) {
    echo json_encode(['success' => false, 'message' => 'Answer must be at least 2 characters.']);
    exit;
}

// ── Save to DB ─────────────────────────────────────────────────────────────────
$userId = (int)$_SESSION['user_id'];

try {
    $pdo = db()->getConnection();

    // Graceful check: verify columns exist before UPDATE
    $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'security_question'")->fetch();
    if (!$colCheck) {
        echo json_encode([
            'success' => false,
            'message' => 'Database columns missing. Please run migration_security.sql first.',
        ]);
        exit;
    }

    $hashed = password_hash($answer, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $pdo->prepare(
        "UPDATE users
            SET security_question = :q,
                security_answer   = :a,
                is_first_login    = 0
          WHERE id = :id"
    );
    $result = $stmt->execute([
        ':q'  => $question,
        ':a'  => $hashed,
        ':id' => $userId,
    ]);

    // rowCount() = 0 can mean values unchanged (already same) — treat as OK
    if (!$result) {
        // Verify user exists
        $check = $pdo->prepare("SELECT id FROM users WHERE id = :id");
        $check->execute([':id' => $userId]);
        if (!$check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'User not found. Please log in again.']);
            exit;
        }
    }

    // Clear session flags
    unset($_SESSION['security_setup_required']);
    $_SESSION['is_first_login'] = 0;

    // Log activity — safe wrapper
    try {
        if (function_exists('logActivity')) {
            $ref        = new ReflectionFunction('logActivity');
            $paramCount = $ref->getNumberOfParameters();
            if ($paramCount >= 3) {
                logActivity($userId, 'SECURITY_SETUP', 'User completed first-login security question setup.');
            } else {
                logActivity('SECURITY_SETUP', 'User completed first-login security question setup.');
            }
        }
    } catch (Exception $logEx) {
        error_log('[save_security_setup] logActivity error: ' . $logEx->getMessage());
    }

    $redirectUrl = defined('BASE_URL') ? BASE_URL . 'dashboard.php' : '../dashboard.php';

    echo json_encode([
        'success'  => true,
        'message'  => 'Security question saved successfully!',
        'redirect' => $redirectUrl,
    ]);

} catch (Exception $e) {
    error_log('[save_security_setup] Exception: ' . $e->getMessage());

    $msg = $e->getMessage();
    if (stripos($msg, 'security_question') !== false ||
        stripos($msg, 'security_answer')   !== false ||
        stripos($msg, 'is_first_login')    !== false ||
        stripos($msg, 'Unknown column')    !== false) {
        echo json_encode([
            'success' => false,
            'message' => 'Database columns missing. Please run migration_security.sql first. Error: ' . $msg,
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $msg,
        ]);
    }
}