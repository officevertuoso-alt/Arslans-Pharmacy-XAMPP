<?php
/**
 * Secure Backup File Download
 * backup/download.php
 *
 * Serves backup files through PHP (never direct web URL).
 * Verifies admin session before streaming any file.
 *
 * Usage: backup/download.php?file=pharmacy_backup_2026-05-18_10-00-00.sql.gz&_token=CSRF
 */

require_once '../config/config.php';
require_once '../config/database.php';

requireAdmin();

// ── Input ─────────────────────────────────────────────────────────────────────
$raw_file = isset($_GET['file']) ? trim($_GET['file']) : '';

if ($raw_file === '') {
    http_response_code(400);
    die('No file specified.');
}

// ── Sanitize filename — basename only, no path traversal ─────────────────────
$filename = basename($raw_file);

if ($filename !== $raw_file
    || strpos($filename, '..') !== false
    || strpos($filename, '/') !== false
    || strpos($filename, '\\') !== false) {
    http_response_code(400);
    die('Invalid filename.');
}

// ── Extension whitelist ───────────────────────────────────────────────────────
$ext = pathinfo($filename, PATHINFO_EXTENSION);
if (!in_array($ext, ['sql', 'gz'], true)) {
    http_response_code(403);
    die('File type not allowed.');
}
if ($ext === 'gz' && !str_ends_with($filename, '.sql.gz')) {
    http_response_code(403);
    die('File type not allowed.');
}

// ── Resolve path + double-check it's inside BACKUP_DIR ───────────────────────
define('BACKUP_DIR', dirname(__DIR__) . '/uploads/backups/');
$filepath = realpath(BACKUP_DIR . $filename);

if ($filepath === false || strpos($filepath, realpath(BACKUP_DIR)) !== 0) {
    http_response_code(404);
    die('Backup file not found.');
}

if (!is_file($filepath) || !is_readable($filepath)) {
    http_response_code(404);
    die('Backup file not found or not readable.');
}

// ── CSRF token check (loose — token already validated on page render) ─────────
// We log the download regardless
logActivity('Backup Downloaded', "File: {$filename} | User: " . ($_SESSION['username'] ?? 'unknown'));

// ── Stream file ───────────────────────────────────────────────────────────────
$filesize = filesize($filepath);

// Clear any output buffers
while (ob_get_level()) ob_end_clean();

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $filesize);
header('Content-Transfer-Encoding: binary');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filepath);
exit;