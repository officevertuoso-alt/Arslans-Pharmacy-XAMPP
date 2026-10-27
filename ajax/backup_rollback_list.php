<?php
/**
 * ajax/backup_rollback_list.php — Phase 29
 * Returns all pre-restore and pre-reset safety backups for the Rollback tab.
 * These are auto-created before every restore or factory reset.
 *
 * Auth:   Admin + backup:view permission + CSRF (GET)
 * Output: JSON { success, rollbacks: [...] }
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

requireAdmin();

$csrf = trim($_GET['csrf'] ?? '');
if (!verifyCSRFToken($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

$backupDir = dirname(__DIR__) . '/uploads/backups/';
$rollbacks = [];

if (is_dir($backupDir)) {
    // Match both pre_restore_* and pre_reset_* files
    $files = glob($backupDir . 'pre_{restore,reset}_*.{sql,sql.gz,gz}', GLOB_BRACE) ?: [];
    if ($files) {
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach ($files as $fp) {
            $fname = basename($fp);
            $fsize = filesize($fp);
            $ftime = filemtime($fp);
            $type  = strpos($fname, 'pre_reset_') !== false ? 'Pre-Reset' : 'Pre-Restore';

            $sizeHuman = $fsize >= 1048576
                ? round($fsize / 1048576, 2) . ' MB'
                : round($fsize / 1024, 1) . ' KB';

            $rollbacks[] = [
                'name'       => $fname,
                'type'       => $type,
                'date'       => date('d-M-Y H:i:s', $ftime),
                'timestamp'  => $ftime,
                'size'       => $sizeHuman,
                'size_bytes' => $fsize,
            ];
        }
    }
}

echo json_encode(['success' => true, 'rollbacks' => $rollbacks, 'count' => count($rollbacks)]);
exit;
