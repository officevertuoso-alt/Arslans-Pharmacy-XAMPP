<?php
/**
 * ajax/backup_create.php — Create Database Backup
 */

// ISS-017/ISS-025 FIX: Let config.php control error reporting globally

// Start output buffering
ob_start();

// Required files
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

// Clear buffer and set JSON header
ob_clean();
header('Content-Type: application/json');

// Helper function for JSON response
function sendJsonResponse($success, $message, $data = array()) {
    $response = array('success' => $success, 'message' => $message);
    foreach ($data as $key => $value) {
        $response[$key] = $value;
    }
    echo json_encode($response);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION)) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    sendJsonResponse(false, 'Not logged in. Please login again.');
}

// Check admin permission
if (!function_exists('isAdmin')) {
    sendJsonResponse(false, 'Authentication system error.');
}

if (!isAdmin()) {
    sendJsonResponse(false, 'Admin access required.');
}

// Check backup permission
if (function_exists('canDo') && !canDo('backup', 'add')) {
    sendJsonResponse(false, 'You do not have permission to create backups.');
}

// Check CSRF token
$csrf = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($csrf)) {
    sendJsonResponse(false, 'Invalid security token. Please refresh the page.');
}

// Set action for backup_database.php
$_POST['action'] = 'create';

if (!isset($_POST['backup_type'])) {
    $_POST['backup_type'] = 'full';
}

// Include the backup engine
$backupFile = dirname(__DIR__) . '/ajax/backup_database.php';
if (!file_exists($backupFile)) {
    sendJsonResponse(false, 'Backup engine not found.');
}

require_once $backupFile;
exit;
?>