<?php
/**
 * ajax/backup_uploads.php — Phase 29
 * Creates a ZIP archive of all uploads subdirectories:
 *   uploads/products/, uploads/profiles/, uploads/avatars/, uploads/settings/
 *
 * Auth:   Admin + backup:add permission + CSRF
 * Output: JSON { success, filename, size, file_count }
 */

ob_start();
ini_set('memory_limit', '512M');
@set_time_limit(300);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

function ujson(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

// ── Auth & CSRF ───────────────────────────────────────────────────
requireAdmin();

if (function_exists('canDo') && !canDo('backup', 'add')) {
    ujson(false, 'You do not have permission to create backups.');
}

$csrf = trim($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($csrf)) {
    ujson(false, 'Invalid security token. Please refresh the page.');
}

// ── Check ZipArchive availability ─────────────────────────────────
if (!class_exists('ZipArchive')) {
    ujson(false, 'ZipArchive extension is not available on this server.');
}

// ── Directories to include ────────────────────────────────────────
$rootDir    = dirname(__DIR__);
$uploadsDir = $rootDir . '/uploads/';
$backupDir  = $uploadsDir . 'backups/';

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$sourceDirs = [
    'products'  => $uploadsDir . 'products/',
    'profiles'  => $uploadsDir . 'profiles/',
    'avatars'   => $uploadsDir . 'avatars/',
    'settings'  => $uploadsDir . 'settings/',
];

// ── Create ZIP ────────────────────────────────────────────────────
$timestamp = date('Y-m-d_H-i-s');
$filename  = "pharmacy_backup_uploads_{$timestamp}.zip";
$filepath  = $backupDir . $filename;

$zip = new ZipArchive();
if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    ujson(false, 'Could not create ZIP file. Check directory permissions.');
}

$fileCount = 0;
$skipped   = [];

foreach ($sourceDirs as $alias => $dir) {
    if (!is_dir($dir)) {
        $skipped[] = $alias;
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) continue;

        $realPath = $file->getRealPath();
        $baseName = basename($realPath);

        // Skip .htaccess and PHP files — security: only archive safe image/doc types
        $ext = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));
        $allowedExts = ['jpg','jpeg','png','gif','webp','bmp','svg','pdf','csv','xlsx','xls','txt'];
        if (!in_array($ext, $allowedExts, true)) continue;

        // Relative path inside ZIP: e.g. "products/img_abc123.jpg"
        $relativePath = $alias . '/' . ltrim(str_replace($dir, '', $realPath), '/\\');
        $zip->addFile($realPath, $relativePath);
        $fileCount++;
    }
}

// Add a manifest
$manifest = json_encode([
    'type'       => 'uploads_backup',
    'created_at' => date('Y-m-d H:i:s'),
    'dirs'       => array_keys($sourceDirs),
    'skipped'    => $skipped,
    'file_count' => $fileCount,
], JSON_PRETTY_PRINT);
$zip->addFromString('MANIFEST.json', $manifest);

$zip->close();

if (!file_exists($filepath) || filesize($filepath) === 0) {
    ujson(false, 'ZIP was created but appears empty. Check upload directories.');
}

$sizeBytes = filesize($filepath);
$sizeHuman = $sizeBytes >= 1048576
    ? round($sizeBytes / 1048576, 2) . ' MB'
    : round($sizeBytes / 1024, 1) . ' KB';

// Log activity
if (function_exists('logActivity')) {
    logActivity('UPLOADS_BACKUP', "Uploads backup created: {$filename} ({$fileCount} files)");
}

ujson(true, 'Uploads backup created successfully.', [
    'filename'   => $filename,
    'size'       => $sizeHuman,
    'size_bytes' => $sizeBytes,
    'file_count' => $fileCount,
    'skipped'    => $skipped,
]);
