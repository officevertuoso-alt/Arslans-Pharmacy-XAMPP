<?php
/**
 * ajax/backup_delete.php
 * Delete a backup file
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

// Auth check
if (!isset($_SESSION['user_id']) || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF check
$csrf = trim($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

// Permission check
if (!canDo('backup', 'delete')) {
    echo json_encode(['success' => false, 'message' => 'No permission to delete backups']);
    exit;
}

$filename = basename(trim($_POST['filename'] ?? ''));
if (!$filename) {
    echo json_encode(['success' => false, 'message' => 'No filename provided']);
    exit;
}

$backup_dir = dirname(__DIR__) . '/uploads/backups/';
$filepath = $backup_dir . $filename;

if (!file_exists($filepath)) {
    echo json_encode(['success' => false, 'message' => 'File not found']);
    exit;
}

if (@unlink($filepath)) {
    @unlink($filepath . '.meta.json');
    logActivity('Backup Deleted', "File: {$filename}");
    echo json_encode(['success' => true, 'message' => 'Backup deleted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Cannot delete file. Check permissions.']);
}
?>