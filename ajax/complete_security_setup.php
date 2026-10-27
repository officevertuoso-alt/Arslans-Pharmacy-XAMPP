<?php
/**
 * Complete Security Setup — First Login
 * File: ajax/complete_security_setup.php
 * POST only — returns JSON
 *
 * Called from dashboard.php security setup modal.
 * Dashboard form sends:  question (short-key), answer (plain text), user_id
 * Widget  form sends:    security_question (short-key), security_answer, csrf_token
 *
 * This file handles BOTH naming conventions so it works regardless of which
 * form posted to it.
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
require_once '../includes/auth.php';
require_once '../includes/security_helpers.php';

header('Content-Type: application/json');

// ── Method guard ─────────────────────────────────────────────────────────────
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

$userId = (int)$_SESSION['user_id'];

// ── Accept both field-name conventions ───────────────────────────────────────
// Dashboard form:  name="question"  + name="answer"
// Widget form:     name="security_question" + name="security_answer"
$question = trim(
    $_POST['question'] ??
    $_POST['security_question'] ??
    ''
);
$answer = trim(
    $_POST['answer'] ??
    $_POST['security_answer'] ??
    ''
);

// ── Validate question ─────────────────────────────────────────────────────────
if ($question === '') {
    echo json_encode(['success' => false, 'message' => 'Please select a security question.']);
    exit;
}

$allowedQuestions = getSecurityQuestions();   // ['phone'=>'Your Phone Number', ...]

// Accept both key ("phone") and full label ("Your Phone Number")
if (!array_key_exists($question, $allowedQuestions)) {
    $flipped = array_flip($allowedQuestions);
    if (isset($flipped[$question])) {
        $question = $flipped[$question];        // convert label → key
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

// ── Save to DB ────────────────────────────────────────────────────────────────
try {
    $pdo    = db()->getConnection();
    $hashed = password_hash($answer, PASSWORD_BCRYPT, ['cost' => 12]);

    // First verify columns exist (graceful error if migration not run)
    $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'security_question'")->fetch();
    if (!$colCheck) {
        echo json_encode([
            'success' => false,
            'message' => 'Database not ready. Please run migration_security.sql first.',
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE users
            SET security_question = :q,
                security_answer   = :a,
                is_first_login    = 0
          WHERE id = :id"
    );
    $stmt->execute([':q' => $question, ':a' => $hashed, ':id' => $userId]);

    // Clear session flags
    unset($_SESSION['security_setup_required']);
    $_SESSION['is_first_login'] = 0;

    // Log activity (non-critical — safe wrapper)
    try {
        if (function_exists('logActivity')) {
            $ref        = new ReflectionFunction('logActivity');
            $paramCount = $ref->getNumberOfParameters();
            if ($paramCount >= 3) {
                logActivity($userId, 'SECURITY_SETUP', 'First-login security question set.');
            } else {
                logActivity('SECURITY_SETUP', 'First-login security question set.');
            }
        }
    } catch (Exception $e) { /* non-critical — ignore */ }

    $redirectUrl = defined('BASE_URL') ? BASE_URL . 'dashboard.php' : '../dashboard.php';

    echo json_encode([
        'success'  => true,
        'message'  => 'Security question saved successfully!',
        'redirect' => $redirectUrl,
    ]);

} catch (Exception $e) {
    error_log('[complete_security_setup] ' . $e->getMessage());

    $msg = $e->getMessage();
    if (stripos($msg, 'Unknown column') !== false ||
        stripos($msg, 'security_question') !== false ||
        stripos($msg, 'security_answer')   !== false ||
        stripos($msg, 'is_first_login')    !== false) {
        echo json_encode([
            'success' => false,
            'message' => 'Database not ready. Please run migration_security.sql in phpMyAdmin first.',
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'DB error: ' . $msg,
        ]);
    }
}