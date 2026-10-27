<?php
/**
 * ajax/backup_download.php
 * Secure download handler for backup files
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// Auth check
if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header('HTTP/1.0 403 Forbidden');
    die('Unauthorized');
}

// CSRF check
$csrf = trim($_GET['csrf'] ?? '');
if (!verifyCSRFToken($csrf)) {
    header('HTTP/1.0 403 Forbidden');
    die('Invalid security token');
}

$filename = basename(trim($_GET['file'] ?? ''));
if (!$filename) {
    header('HTTP/1.0 400 Bad Request');
    die('No file specified');
}

$backup_dir = dirname(__DIR__) . '/uploads/backups/';
$filepath = realpath($backup_dir . $filename);

// Security: ensure file is inside backups directory
if ($filepath === false || strpos($filepath, realpath($backup_dir)) !== 0) {
    header('HTTP/1.0 403 Forbidden');
    die('Invalid file path');
}

if (!file_exists($filepath)) {
    header('HTTP/1.0 404 Not Found');
    die('File not found');
}

// Send file
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, no-cache');
header('Pragma: no-cache');

readfile($filepath);
exit;
?>