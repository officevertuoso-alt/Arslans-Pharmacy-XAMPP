<?php // REPAIRED vFIX4
/**
 * Database Restore Page (Final Fix – No Transaction Error)
 * backup/restore.php
 *
 * Security:
 *  - requireAdmin()
 *  - CSRF + double confirmation token
 *  - File extension & signature validation
 *  - Pre‑restore backup
 *  - Executes SQL without manual transaction (avoids DDL auto‑commit issues)
 * 
 * FIXED (Step BR-4):
 *  - Pure PHP fallback for pre-restore backup when mysqldump unavailable
 *  - Triggers are properly exported in pre-restore backup
 * 
 * FIXED (Step BR-6):
 *  - Post-restore session cleanup + OPcache flush
 * 
 * FIXED (Step BR-8):
 *  - Added repair button and functionality for missing triggers after restore
 * 
 * FORENSIC REPAIRS (vFIX4):
 *   - Moved JSON header to POST branch only (ISSUE 1)
 *   - Added defined() guards for constants (ISSUE 2)
 *   - Fixed confirmation token to use cryptographically secure random (ISSUE 3)
 *   - Added post_restore_actions to success JSON response (ISSUE 4)
 *   - Display pre-restore backup notice with download link (ISSUE 5)
 *   - Enhanced dump signature validation with more signatures (ISSUE 6)
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/settings_functions.php';

requireAdmin();

// ISSUE 1: JSON header removed from here - will be set only in POST branch

// ISSUE 2: Define constants with existence guards
if (!defined('BACKUP_DIR')) {
    define('BACKUP_DIR', dirname(__DIR__) . '/uploads/backups/');
}
if (!defined('MAX_RESTORE_SIZE')) {
    define('MAX_RESTORE_SIZE', 50 * 1024 * 1024);   // 50 MB
}
if (!defined('ALLOWED_EXTENSIONS')) {
    define('ALLOWED_EXTENSIONS', ['sql', 'gz']);
}

// ── PHP Limits for large restores ──────────────────────────────────
@ini_set('memory_limit', '512M');
@set_time_limit(300);  // 5 minutes max

// ─────────────────────────────────────────────────────────────
// POST: Perform restore
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');  // ISSUE 1: JSON header only in POST branch

    // CSRF
    $csrf = trim($_POST['csrf_token'] ?? '');
    if (!verifyCSRFToken($csrf)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    // Double confirmation
    $confirm_token = trim($_POST['confirm_token'] ?? '');
    $confirm_text  = trim($_POST['confirm_text'] ?? '');
    $expected_token = $_SESSION['restore_confirm_token'] ?? '';
    if (empty($expected_token) || !hash_equals($expected_token, $confirm_token)) {
        echo json_encode(['success' => false, 'message' => 'Confirmation token mismatch.']);
        exit;
    }
    if ($confirm_text !== 'RESTORE') {
        echo json_encode(['success' => false, 'message' => 'You must type exactly "RESTORE".']);
        exit;
    }
    unset($_SESSION['restore_confirm_token']);

    // Filename & path validation
    $raw_filename = trim($_POST['filename'] ?? '');
    if ($raw_filename === '') {
        echo json_encode(['success' => false, 'message' => 'No backup file specified.']);
        exit;
    }
    $filename = basename($raw_filename);
    if ($filename !== $raw_filename || strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
        echo json_encode(['success' => false, 'message' => 'Invalid filename.']);
        exit;
    }
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only .sql or .sql.gz.']);
        exit;
    }
    if ($ext === 'gz' && !str_ends_with($filename, '.sql.gz')) {
        echo json_encode(['success' => false, 'message' => 'Invalid extension.']);
        exit;
    }

    $filepath = realpath(BACKUP_DIR . $filename);
    if ($filepath === false || strpos($filepath, realpath(BACKUP_DIR)) !== 0) {
        echo json_encode(['success' => false, 'message' => 'Backup file not found.']);
        exit;
    }
    $filesize = filesize($filepath);
    if ($filesize > MAX_RESTORE_SIZE) {
        echo json_encode(['success' => false, 'message' => 'File too large (max 50 MB).']);
        exit;
    }

    // Validate MySQL dump signature
    if (!validateDumpSignature($filepath, $ext)) {
        echo json_encode(['success' => false, 'message' => 'Not a valid MySQL dump.']);
        exit;
    }

    // Pre-restore backup (safety net)
    $pre_backup_result = createPreRestoreBackup();
    $pre_backup_warning = !$pre_backup_result['success'] ? 'Warning: Pre-restore backup failed: ' . $pre_backup_result['message'] : null;
    if ($pre_backup_result['success']) {
        logActivity('Pre-Restore Backup', 'Auto-backup before restore: ' . $pre_backup_result['filename']);
        // ISSUE 5: Store last pre-restore backup in session for UI display
        $_SESSION['last_pre_restore_backup'] = $pre_backup_result['filename'];
    }

    // Read SQL content
    if ($ext === 'gz') {
        $sql_content = @gzdecode(file_get_contents($filepath));
        if ($sql_content === false) {
            echo json_encode(['success' => false, 'message' => 'Failed to decompress backup.']);
            exit;
        }
    } else {
        $sql_content = file_get_contents($filepath);
    }
    if (empty($sql_content)) {
        echo json_encode(['success' => false, 'message' => 'Backup file is empty.']);
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // EXECUTE RESTORE – NO MANUAL TRANSACTION
    // (DDL statements auto‑commit, so we avoid wrapping)
    // ─────────────────────────────────────────────────────────
    try {
        $pdo = db()->getConnection();
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
        $pdo->exec("SET AUTOCOMMIT = 1");   // ensure autocommit mode

        $statements = splitSqlStatements($sql_content);
        $executed = 0;
        $errors = [];

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;

            // Skip comments
            if (substr($stmt, 0, 2) === '--' || substr($stmt, 0, 2) === '/*') continue;

            // Skip transaction control statements (they interfere with autocommit)
            $upper = strtoupper($stmt);
            if (preg_match('/^(COMMIT|ROLLBACK|START\s+TRANSACTION|BEGIN|SET\s+AUTOCOMMIT)/', $upper)) continue;

            try {
                $pdo->exec($stmt);
                $executed++;
            } catch (PDOException $e) {
                $errors[] = substr($stmt, 0, 80) . '… — ' . $e->getMessage();
                if (count($errors) > 20) {
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                    echo json_encode([
                        'success' => false,
                        'message' => 'Too many SQL errors: ' . implode('; ', array_slice($errors, 0, 5))
                    ]);
                    exit;
                }
            }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // ── Post-Restore Cleanup ──────────────────────────────────────────────
        // 1. Clear OPcache if available (flushes query plan cache)
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // 2. Clear application caches if sync_engine loaded
        if (function_exists('clearAllCaches')) {
            try { clearAllCaches(); } catch (Exception $e) { /* non-fatal */ }
        }

        // 3. Clear session POS cart and dashboard cache (stale after restore)
        $preserve_user = $_SESSION['user_id']  ?? null;
        $preserve_role = $_SESSION['role']     ?? null;
        $preserve_csrf = $_SESSION['csrf_token'] ?? null;

        // Clear all session data except auth
        session_unset();
        $_SESSION['user_id']    = $preserve_user;
        $_SESSION['role']       = $preserve_role;
        $_SESSION['user_role']  = $preserve_role;
        $_SESSION['csrf_token'] = $preserve_csrf;

        // 4. Log the restore activity
        logActivity('Database Restored', "File: {$filename} | Executed: {$executed} | Errors: " . count($errors));

    } catch (Exception $e) {
        if (isset($pdo)) {
            try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Exception $ignored) {}
        }
        logActivity('Restore Failed', "File: {$filename} | Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database restore failed: ' . $e->getMessage()]);
        exit;
    }

    // Success
    $response = [
        'success' => true,
        'message' => "Database restored from '{$filename}'. {$executed} statements executed.",
        'executed' => $executed,
        'minor_errors' => count($errors),
        'session_cleared' => true,
        'redirect_hint' => '../dashboard.php?restored=1',
        'show_repair_btn' => true,  // ← Flag to show repair button on dashboard
        // ISSUE 4: Add post_restore_actions
        'post_restore_actions' => ['clear_cache', 'reload_permissions']
    ];
    if ($pre_backup_warning) $response['warning'] = $pre_backup_warning;
    if ($pre_backup_result['success']) $response['pre_backup_file'] = $pre_backup_result['filename'];
    echo json_encode($response);
    exit;
}

// ─────────────────────────────────────────────────────────────
// GET: Show restore form
// ─────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

// ISSUE 3: Generate cryptographically secure confirmation token
$restore_confirm_token = bin2hex(random_bytes(32));
$_SESSION['restore_confirm_token'] = $restore_confirm_token;
$csrf_token = generateCSRFToken();

$prefill_file = '';
if (isset($_GET['prefill'])) {
    $pf = basename(sanitize($_GET['prefill']));
    $ext = pathinfo($pf, PATHINFO_EXTENSION);
    if (in_array($ext, ['sql', 'gz'], true)) $prefill_file = $pf;
}

$backup_list = [];
if (is_dir(BACKUP_DIR)) {
    foreach (new DirectoryIterator(BACKUP_DIR) as $f) {
        if ($f->isDot() || $f->isDir()) continue;
        $fname = $f->getFilename();
        $ext = pathinfo($fname, PATHINFO_EXTENSION);
        if (!in_array($ext, ['sql', 'gz'], true)) continue;
        if ($ext === 'gz' && !str_ends_with($fname, '.sql.gz')) continue;
        $backup_list[] = [
            'filename' => $fname,
            'size_human' => formatFileSize($f->getSize()),
            'created_at' => date('d-M-Y H:i:s', $f->getMTime()),
            'mtime' => $f->getMTime(),
        ];
    }
    usort($backup_list, fn($a, $b) => $b['mtime'] - $a['mtime']);
}
$page_title = 'Restore Database Backup';

// ISSUE 5: Check for last pre-restore backup notice
$last_pre_backup = $_SESSION['last_pre_restore_backup'] ?? null;
$pre_backup_notice = null;
if ($last_pre_backup) {
    $pre_backup_notice = [
        'filename' => $last_pre_backup,
        'download_url' => '../uploads/backups/' . rawurlencode($last_pre_backup)
    ];
    // Clear after displaying (only show once)
    unset($_SESSION['last_pre_restore_backup']);
}

// ── Helper functions ─────────────────────────────────────────────────────────
// ISSUE 6: Enhanced dump signature validation
function validateDumpSignature(string $filepath, string $ext): bool {
    if ($ext === 'gz') {
        $handle = gzopen($filepath, 'rb');
        if (!$handle) return false;
        $header = gzread($handle, 2048);
        gzclose($handle);
    } else {
        $handle = fopen($filepath, 'rb');
        if (!$handle) return false;
        $header = fread($handle, 2048);
        fclose($handle);
    }
    if (empty($header)) return false;
    // ISSUE 6: Enhanced signatures - added phpMyAdmin and SET SQL_MODE
    $signatures = [
        '-- phpMyAdmin SQL Dump',
        '-- MySQL dump', 
        '-- MariaDB dump',
        'CREATE TABLE',
        'INSERT INTO',
        'DROP TABLE IF EXISTS',
        'SET SQL_MODE',
        'SET FOREIGN_KEY_CHECKS'
    ];
    foreach ($signatures as $sig) {
        if (stripos($header, $sig) !== false) return true;
    }
    return false;
}

function splitSqlStatements(string $sql): array {
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") $sql = substr($sql, 3);
    $statements = [];
    $current = '';
    $delimiter = ';';
    $in_string = false;
    $string_char = '';
    $len = strlen($sql);
    $i = 0;
    while ($i < $len) {
        $char = $sql[$i];
        if (!$in_string && strtoupper(substr($sql, $i, 9)) === 'DELIMITER') {
            $rest = substr($sql, $i + 9);
            if (preg_match('/^\s+(\S+)/', $rest, $delim_m)) {
                $delimiter = $delim_m[1];
                $i += 9 + strlen($delim_m[0]);
                continue;
            }
        }
        if (!$in_string && $char === '-' && substr($sql, $i, 2) === '--') {
            $nl = strpos($sql, "\n", $i);
            if ($nl === false) break;
            $i = $nl + 1;
            continue;
        }
        if (!$in_string && $char === '/' && substr($sql, $i, 2) === '/*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) break;
            $i = $end + 2;
            continue;
        }
        if (!$in_string && ($char === '\'' || $char === '"' || $char === '`')) {
            $in_string = true;
            $string_char = $char;
            $current .= $char;
            $i++;
            continue;
        }
        if ($in_string) {
            if ($char === '\\') {
                $current .= $char . ($sql[$i + 1] ?? '');
                $i += 2;
                continue;
            }
            if ($char === $string_char) $in_string = false;
            $current .= $char;
            $i++;
            continue;
        }
        if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
            $current = trim($current);
            if ($current !== '') $statements[] = $current;
            $current = '';
            $i += strlen($delimiter);
            continue;
        }
        $current .= $char;
        $i++;
    }
    $current = trim($current);
    if ($current !== '') $statements[] = $current;
    return $statements;
}

// ==============================================================
// PURE PHP PRE-RESTORE BACKUP (includes tables + triggers)
// ==============================================================
function purePhpPreRestoreBackup(PDO $pdo, string $output_file): bool {
    try {
        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $sql  = "-- Pre-Restore Safety Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
        
        // Tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";
            
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                foreach ($rows as $row) {
                    $vals = array_map(function($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        return $pdo->quote((string)$v);
                    }, array_values($row));
                    $sql .= "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(', ', $vals) . ");\n";
                }
                $sql .= "\n";
            }
        }
        
        // Triggers (CRITICAL — this is what was missing)
        $trigStmt = $pdo->prepare(
            "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE,
                    ACTION_TIMING, ACTION_STATEMENT
             FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?"
        );
        $trigStmt->execute([$dbName]);
        $triggers = $trigStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($triggers)) {
            $sql .= "\n-- ════ TRIGGERS ════\n";
            foreach ($triggers as $trig) {
                $sql .= "DROP TRIGGER IF EXISTS `{$trig['TRIGGER_NAME']}`;\n";
                $sql .= "DELIMITER ;;\n";
                $sql .= "CREATE TRIGGER `{$trig['TRIGGER_NAME']}` {$trig['ACTION_TIMING']} ";
                $sql .= "{$trig['EVENT_MANIPULATION']} ON `{$trig['EVENT_OBJECT_TABLE']}` FOR EACH ROW\n";
                $sql .= "{$trig['ACTION_STATEMENT']} ;;\n";
                $sql .= "DELIMITER ;\n\n";
            }
        }
        
        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $written = file_put_contents($output_file, $sql);
        return $written !== false && $written > 0;
    } catch (Exception $e) {
        error_log("purePhpPreRestoreBackup failed: " . $e->getMessage());
        return false;
    }
}

function createPreRestoreBackup(): array {
    $backup_dir = dirname(__DIR__) . '/uploads/backups/';
    if (!is_dir($backup_dir) && !mkdir($backup_dir, 0755, true)) {
        return ['success' => false, 'message' => 'Cannot create backup directory.'];
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $sql_file = $backup_dir . 'pre_restore_' . $timestamp . '.sql';
    $gz_file = $sql_file . '.gz';
    
    // Try mysqldump first (it includes triggers, routines, and is faster)
    try {
        $pdo = db()->getConnection();
        $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $db_name = defined('DB_NAME') ? DB_NAME : 'pharmacy_db';
        $db_user = defined('DB_USER') ? DB_USER : 'root';
        $db_pass = defined('DB_PASS') ? DB_PASS : '';
        $db_port = defined('DB_PORT') ? DB_PORT : '3306';
        
        $paths = ['mysqldump', 'C:\\xampp\\mysql\\bin\\mysqldump.exe', 
                  'C:\\wamp64\\bin\\mysql\\mysql*\\bin\\mysqldump.exe',
                  '/usr/bin/mysqldump', '/usr/local/bin/mysqldump'];
        $bin = 'mysqldump';
        foreach ($paths as $p) {
            // Handle wildcard paths for WAMP
            if (strpos($p, '*') !== false) {
                $matches = glob($p);
                if (!empty($matches)) $p = $matches[0];
            }
            if (is_executable($p)) { $bin = $p; break; }
        }
        
        $cmd_parts = [
            escapeshellcmd($bin),
            '--single-transaction', '--routines', '--triggers',
            '--add-drop-table', '--comments',
            '-h', escapeshellarg($db_host),
            '-P', escapeshellarg($db_port),
            '-u', escapeshellarg($db_user),
        ];
        if ($db_pass !== '') $cmd_parts[] = '-p' . escapeshellarg($db_pass);
        $cmd_parts[] = escapeshellarg($db_name);
        $cmd_parts[] = '>';
        $cmd_parts[] = escapeshellarg($sql_file);
        $cmd_parts[] = '2>&1';
        $cmd = implode(' ', $cmd_parts);
        
        $ret = 0;
        exec($cmd, $out, $ret);
        
        if ($ret === 0 && file_exists($sql_file) && filesize($sql_file) > 100) {
            // mysqldump succeeded
            $final = $sql_file;
            if (function_exists('gzencode')) {
                $content = file_get_contents($sql_file);
                if ($content !== false) {
                    $gz = gzencode($content, 6);
                    if ($gz !== false && file_put_contents($gz_file, $gz)) {
                        @unlink($sql_file);
                        $final = $gz_file;
                    }
                }
            }
            chmod($final, 0640);
            return ['success' => true, 'filename' => basename($final), 'size' => filesize($final)];
        }
    } catch (Exception $e) {
        error_log("mysqldump pre-restore backup failed: " . $e->getMessage());
    }
    
    // FALLBACK: Pure PHP dump with triggers (no exec required)
    try {
        $pdo = db()->getConnection();
        if (purePhpPreRestoreBackup($pdo, $sql_file)) {
            $final = $sql_file;
            if (function_exists('gzencode')) {
                $content = file_get_contents($sql_file);
                if ($content !== false) {
                    $gz = gzencode($content, 6);
                    if ($gz !== false && file_put_contents($gz_file, $gz)) {
                        @unlink($sql_file);
                        $final = $gz_file;
                    }
                }
            }
            chmod($final, 0640);
            return ['success' => true, 'filename' => basename($final), 'size' => filesize($final)];
        }
    } catch (Exception $e) {
        error_log("Pure PHP pre-restore backup failed: " . $e->getMessage());
    }
    
    return ['success' => false, 'message' => 'Could not create pre-restore backup (mysqldump and PHP fallback both failed).'];
}

function formatFileSize(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .main-content { margin-left: var(--sb-w, 260px); padding: 24px; transition: margin-left .25s; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } } }
        .restore-card { border: 1px solid var(--pharmacy-border, rgba(0,0,0,.08)); border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,.09); background: var(--pharmacy-card-bg, #fff); color: var(--pharmacy-text, #444); }
        .danger-zone { border: 2px solid #dc3545; border-radius: 10px; background: color-mix(in srgb, #dc3545 5%, var(--pharmacy-card-bg, #fff)); }
        .step-badge { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: .85rem; flex-shrink: 0; }
        #loadingOverlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9999; align-items: center; justify-content: center; flex-direction: column; color: #fff; text-align: center; }
        #loadingOverlay.show { display: flex; }
        #repairSection { display: none; margin-top: 20px; }
        #repairSection.show { display: block; }
    </style>
</head>
<body class="bg-light<?php echo $_darkClass; ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container py-4" style="max-width: 820px;">

<div id="loadingOverlay">
    <div class="spinner-border text-light mb-3" style="width:3.5rem;height:3.5rem;" role="status"></div>
    <div class="fs-4 fw-bold mb-1">Restoring Database…</div>
    <div class="opacity-75 mb-3">This may take a few minutes. Do not close this window.</div>
    <div class="progress w-50" style="height:6px;"><div class="progress-bar progress-bar-striped progress-bar-animated bg-warning w-100"></div></div>
</div>

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <div><h4 class="mb-0 fw-bold text-danger"><i class="bi bi-arrow-counterclockwise me-2"></i><?= $page_title ?></h4><small class="text-muted">Admin access only — Irreversible operation</small></div>
    </div>

    <!-- ISSUE 5: Pre-restore backup notice -->
    <?php if ($pre_backup_notice): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="bi bi-info-circle-fill me-2"></i>
        <strong>Safety Backup Available:</strong> Last restore created a safety backup 
        <code><?= htmlspecialchars($pre_backup_notice['filename']) ?></code>
        <a href="<?= htmlspecialchars($pre_backup_notice['download_url']) ?>" class="alert-link ms-2" download>
            <i class="bi bi-download"></i> Download
        </a>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="alert alert-danger d-flex align-items-start gap-3 mb-4" role="alert">
        <i class="bi bi-exclamation-octagon-fill fs-3 flex-shrink-0 mt-1"></i>
        <div>
            <strong class="d-block mb-1">⚠️ DANGER: This operation replaces your entire database!</strong>
            <ul class="mb-0 small"><li>All current data will be permanently overwritten.</li><li>A pre‑restore backup is automatically created.</li><li>This action cannot be undone.</li></ul>
        </div>
    </div>

    <div class="card restore-card">
        <div class="card-header bg-white border-bottom py-3"><span class="fw-semibold"><i class="bi bi-database-check me-2 text-danger"></i>Restore Configuration</span></div>
        <div class="card-body p-4">
            <div class="d-flex align-items-start gap-3 mb-4">
                <div class="step-badge bg-primary text-white">1</div>
                <div class="flex-grow-1">
                    <label class="form-label fw-semibold mb-1">Select Backup File</label>
                    <select id="selectBackupFile" class="form-select">
                        <option value="">— Select a backup file —</option>
                        <?php foreach ($backup_list as $bk): ?>
                        <option value="<?= htmlspecialchars($bk['filename'], ENT_QUOTES) ?>" <?= ($prefill_file === $bk['filename']) ? 'selected' : '' ?>><?= htmlspecialchars($bk['filename']) ?> (<?= $bk['size_human'] ?> — <?= $bk['created_at'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($backup_list)): ?><div class="alert alert-warning mt-2 mb-0 small">No backup files found. <a href="index.php">Create a backup first.</a></div><?php endif; ?>
                </div>
            </div>
            <div class="d-flex align-items-start gap-3 mb-4">
                <div class="step-badge bg-success text-white">2</div>
                <div class="flex-grow-1"><div class="fw-semibold mb-1">Automatic Pre-Restore Backup</div><div class="alert alert-success small mb-0">Before restoring, the system will automatically create a backup of your current database.</div></div>
            </div>
            <div class="d-flex align-items-start gap-3 mb-4">
                <div class="step-badge bg-danger text-white">3</div>
                <div class="flex-grow-1">
                    <label class="form-label fw-semibold text-danger mb-1">Double Confirmation Required</label>
                    <div class="danger-zone p-3">
                        <p class="small text-muted mb-3">Type <strong>RESTORE</strong> (uppercase) in the field below to confirm.</p>
                        <input type="text" id="confirmText" class="form-control form-control-sm font-monospace" placeholder="Type RESTORE here" autocomplete="off">
                        <div id="confirmFeedback" class="form-text mt-1"></div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 pt-2 border-top mt-2">
                <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Cancel</a>
                <button id="btnRestore" class="btn btn-danger" disabled><i class="bi bi-arrow-counterclockwise me-1"></i>Restore Database</button>
            </div>
        </div>
    </div>

    <!-- Post-Restore Repair Section -->
    <div id="repairSection" class="card border-warning mt-4">
        <div class="card-header bg-warning text-dark">
            <i class="bi bi-hammer me-2"></i>System Repair Tool
        </div>
        <div class="card-body">
            <p class="mb-2">If you restored an old backup, some database triggers or stock calculations might be missing.</p>
            <p class="small text-muted mb-3">This tool will check and repair all triggers, recalculate stock levels, and fix sales totals.</p>
            <div class="alert alert-info py-2 mb-3">
                <i class="bi bi-info-circle me-1"></i> Run this AFTER restoring a backup to ensure full functionality.
            </div>
            <div class="d-flex align-items-center gap-3">
                <input type="text" id="repairConfirm" class="form-control w-auto" placeholder="Type REPAIR to confirm" style="font-family: monospace;">
                <button id="btnRunRepair" class="btn btn-warning" disabled>
                    <i class="bi bi-hammer me-1"></i>Run System Repair
                </button>
            </div>
            <div id="repairResult" class="mt-3" style="display: none;"></div>
        </div>
    </div>

    <?php if (!empty($backup_list)): ?>
    <div class="card restore-card mt-4">
        <div class="card-header bg-white border-bottom py-3 fw-semibold"><i class="bi bi-archive me-2 text-secondary"></i>Available Backup Files</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Filename</th><th>Size</th><th>Created</th><th class="text-center">Select</th></tr></thead>
                    <tbody><?php foreach ($backup_list as $bk): ?><tr><td><code class="small"><?= htmlspecialchars($bk['filename']) ?></code></td><td><?= $bk['size_human'] ?></td><td><?= $bk['created_at'] ?></td><td class="text-center"><button type="button" class="btn btn-outline-primary btn-sm btn-select-file" data-filename="<?= htmlspecialchars($bk['filename'], ENT_QUOTES) ?>"><i class="bi bi-check2"></i> Select</button></td></tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div></div>


<script>
(function () {
    const CSRF_TOKEN    = <?= json_encode($csrf_token) ?>;
    const CONFIRM_TOKEN = <?= json_encode($restore_confirm_token) ?>;
    const selectFile   = document.getElementById('selectBackupFile');
    const confirmInput = document.getElementById('confirmText');
    const btnRestore   = document.getElementById('btnRestore');
    const feedback     = document.getElementById('confirmFeedback');
    const overlay      = document.getElementById('loadingOverlay');
    const repairSection = document.getElementById('repairSection');
    const repairConfirm = document.getElementById('repairConfirm');
    const btnRunRepair  = document.getElementById('btnRunRepair');
    const repairResult  = document.getElementById('repairResult');

    function updateButtonState() {
        const fileOk    = selectFile.value !== '';
        const confirmOk = confirmInput.value.trim() === 'RESTORE';
        btnRestore.disabled = !(fileOk && confirmOk);
        if (confirmInput.value.length > 0) {
            confirmInput.classList.toggle('is-valid', confirmOk);
            confirmInput.classList.toggle('is-invalid', !confirmOk);
            feedback.textContent = confirmOk ? '✅ Confirmed' : `Type exactly "RESTORE" (${confirmInput.value.length}/7 chars)`;
            feedback.className = 'form-text mt-1 ' + (confirmOk ? 'text-success' : 'text-danger');
        } else {
            confirmInput.classList.remove('is-valid', 'is-invalid');
            feedback.textContent = '';
        }
    }

    function updateRepairButtonState() {
        if (repairConfirm) {
            btnRunRepair.disabled = repairConfirm.value.trim() !== 'REPAIR';
        }
    }

    selectFile.addEventListener('change', updateButtonState);
    confirmInput.addEventListener('input', updateButtonState);
    
    if (repairConfirm) {
        repairConfirm.addEventListener('input', updateRepairButtonState);
    }

    document.querySelectorAll('.btn-select-file').forEach(btn => {
        btn.addEventListener('click', function () {
            selectFile.value = this.dataset.filename;
            updateButtonState();
            selectFile.scrollIntoView({ behavior: 'smooth', block: 'center' });
            selectFile.focus();
        });
    });

    btnRestore.addEventListener('click', function () {
        const filename = selectFile.value;
        const confirmText = confirmInput.value.trim();
        if (!filename) { Notify.warning('Please select a backup file.'); return; }
        if (confirmText !== 'RESTORE') { Notify.error('Please type "RESTORE" exactly.'); confirmInput.focus(); return; }
        if (!confirm('FINAL CONFIRMATION\n\nYou are about to restore: ' + filename + '\n\nThis will completely overwrite your current database.\nA pre-restore backup will be created first.\n\nAre you absolutely sure?')) return;

        overlay.classList.add('show');
        btnRestore.disabled = true;
        const body = new URLSearchParams({ csrf_token: CSRF_TOKEN, confirm_token: CONFIRM_TOKEN, confirm_text: confirmText, filename: filename });
        fetch('restore.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
        .then(r => r.json())
        .then(data => {
            overlay.classList.remove('show');
            if (data.success) {
                let msg = '✅ ' + data.message;
                if (data.pre_backup_file) msg += '\n\n📦 Pre-restore backup: ' + data.pre_backup_file;
                if (data.warning) msg += '\n\n⚠ ' + data.warning;
                if (data.minor_errors > 0) msg += '\n\nℹ ' + data.minor_errors + ' minor SQL errors were skipped.';
                Notify.error(msg);
                
                // Show repair section
                repairSection.classList.add('show');
                
                // Redirect after 2.5 seconds using redirect_hint if provided
                var redirectUrl = data.redirect_hint || '../dashboard.php?restored=1';
                setTimeout(function() {
                    window.location.href = redirectUrl;
                }, 2500);
            } else {
                Notify.error('Restore Failed: ' + (data.message || 'Unknown error.'));
                btnRestore.disabled = false;
            }
        })
        .catch(err => { overlay.classList.remove('show'); Notify.error('Network error: ' + err.message); btnRestore.disabled = false; });
    });

    // Repair function
    if (btnRunRepair) {
        btnRunRepair.addEventListener('click', function () {
            const confirmVal = repairConfirm.value.trim();
            if (confirmVal !== 'REPAIR') {
                Notify.error('Please type "REPAIR" in the confirm field.');
                return;
            }
            if (!confirm('Running repair will check and fix triggers, recalculate stock levels and sales totals.\nContinue?')) return;
            
            btnRunRepair.disabled = true;
            btnRunRepair.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Running repair...';
            repairResult.style.display = 'block';
            repairResult.innerHTML = '<div class="alert alert-info">⏳ Checking system integrity...</div>';
            
            const formData = new URLSearchParams({
                csrf_token: CSRF_TOKEN,
                confirm: 'REPAIR'
            });
            
            fetch('../ajax/repair_after_restore.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    let html = '<div class="alert alert-success">';
                    html += '<i class="bi bi-check-circle-fill me-2"></i><strong>' + data.message + '</strong><br>';
                    html += '<hr class="my-2">';
                    html += '<div class="small">';
                    html += '🔧 Triggers restored: ' + data.triggers_restored + '/' + data.triggers_checked + '<br>';
                    html += '📦 Products updated: ' + data.products_updated + '<br>';
                    html += '💰 Sales recalculated: ' + data.sales_recalculated + '<br>';
                    html += '⏰ Expired batches marked: ' + data.expired_batches_marked + '<br>';
                    html += '🔄 Sales totals synced: ' + data.sales_total_synced;
                    if (data.errors && data.errors.length > 0) {
                        html += '<hr class="my-2"><span class="text-warning">⚠️ Warnings:</span><ul>';
                        data.errors.forEach(e => html += '<li>' + e + '</li>');
                        html += '</ul>';
                    }
                    html += '</div></div>';
                    repairResult.innerHTML = html;
                } else {
                    repairResult.innerHTML = '<div class="alert alert-danger">❌ Repair failed: ' + (data.message || 'Unknown error') + '</div>';
                }
                btnRunRepair.disabled = false;
                btnRunRepair.innerHTML = '<i class="bi bi-hammer me-1"></i>Run System Repair';
            })
            .catch(err => {
                repairResult.innerHTML = '<div class="alert alert-danger">❌ Network error: ' + err.message + '</div>';
                btnRunRepair.disabled = false;
                btnRunRepair.innerHTML = '<i class="bi bi-hammer me-1"></i>Run System Repair';
            });
        });
    }

    updateButtonState();
    <?php if ($prefill_file): ?>setTimeout(() => confirmInput.focus(), 300);<?php endif; ?>
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
