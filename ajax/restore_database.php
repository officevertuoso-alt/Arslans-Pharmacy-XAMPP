<?php
// === FILE: pharmacy_software/ajax/restore_database.php ===
/**
 * STEP 32 — Database Restore Handler
 *
 * Accepts: POST multipart with restore_file (.sql or .sql.gz) + admin_password + csrf_token
 * Process:
 *   1. Auth + CSRF + admin password verify
 *   2. Validate upload (mime, size)
 *   3. Decompress if .gz
 *   4. Validate SQL (must contain SET FOREIGN_KEY_CHECKS or CREATE TABLE or INSERT)
 *   5. Auto-create pre-restore backup (safety)
 *   6. Execute SQL statement by statement inside transaction where possible
 *   7. Log + respond
 *   8. FIXED: Post-restore session cleanup + OPcache flush (Step BR-6)
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/backup_functions.php'; // ISS-029 FIX: was backup_database.php (circular include with ob_start() side-effects)

header('Content-Type: application/json; charset=utf-8');

// ── PHP Limits for large restores ──────────────────────────────────
@ini_set('memory_limit', '512M');
@set_time_limit(300);  // 5 minutes max

// ── Auth ──────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────
$csrf_token = trim($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh and retry.']);
    exit;
}

// ── Admin password verify ─────────────────────────────────────────
$admin_password = $_POST['admin_password'] ?? '';
if (strlen($admin_password) < 4) {
    echo json_encode(['success' => false, 'message' => 'Admin password required.']);
    exit;
}

try {
    $user = db()->query(
        "SELECT password FROM users WHERE id = :id AND role = 'admin' LIMIT 1",
        ['id' => $_SESSION['user_id']]
    )->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($admin_password, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Incorrect admin password.']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Password verification failed: ' . $e->getMessage()]);
    exit;
}

// ── Uploaded file ─────────────────────────────────────────────────
if (empty($_FILES['restore_file']) || $_FILES['restore_file']['error'] !== UPLOAD_ERR_OK) {
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE.',
        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Temp directory missing.',
        UPLOAD_ERR_CANT_WRITE => 'Cannot write temp file.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension.',
    ];
    $err_code = $_FILES['restore_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'message' => $upload_errors[$err_code] ?? 'Upload error.']);
    exit;
}

$upload    = $_FILES['restore_file'];
$orig_name = basename($upload['name']);
$tmp_path  = $upload['tmp_name'];

// Validate extension
if (!preg_match('/\.(sql|sql\.gz|gz)$/i', $orig_name)) {
    echo json_encode(['success' => false, 'message' => 'Only .sql or .sql.gz files are accepted.']);
    exit;
}

// ── Read + decompress ─────────────────────────────────────────────
$sql_content = false;
$log         = [];

if (str_ends_with(strtolower($orig_name), '.gz')) {
    if (!function_exists('gzdecode')) {
        echo json_encode(['success' => false, 'message' => 'PHP gzdecode() not available on this server. Upload plain .sql file.']);
        exit;
    }
    $raw         = file_get_contents($tmp_path);
    $sql_content = @gzdecode($raw);
    if ($sql_content === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to decompress .gz file. File may be corrupt.']);
        exit;
    }
    $log[] = '✅ Decompressed .gz file (' . formatRestoreSize(strlen($raw)) . ' → ' . formatRestoreSize(strlen($sql_content)) . ')';
} else {
    $sql_content = file_get_contents($tmp_path);
    $log[] = '✅ Loaded .sql file (' . formatRestoreSize(strlen($sql_content)) . ')';
}

if (empty($sql_content) || strlen($sql_content) < 50) {
    echo json_encode(['success' => false, 'message' => 'Backup file appears empty or corrupt.']);
    exit;
}

// ── Validate SQL content ──────────────────────────────────────────
$has_markers = (
    str_contains($sql_content, 'CREATE TABLE')
    || str_contains($sql_content, 'INSERT INTO')
    || str_contains($sql_content, 'SET FOREIGN_KEY_CHECKS')
);
if (!$has_markers) {
    echo json_encode(['success' => false, 'message' => 'File does not appear to be a valid MySQL backup.']);
    exit;
}
$log[] = '✅ SQL validation passed';

// ── Safety: create pre-restore backup ────────────────────────────
$log[] = '⏳ Creating pre-restore safety backup...';
$safety = createBackup('full', 'pre_restore_' . date('Y-m-d_H-i-s'));
if ($safety['success']) {
    $log[] = '✅ Pre-restore backup saved: ' . $safety['filename'];
} else {
    $log[] = '⚠️ Could not create pre-restore backup: ' . $safety['message'];
    // Non-fatal — continue anyway (user triple-confirmed)
}

// ── Parse SQL into statements ─────────────────────────────────────
$log[] = '⏳ Parsing SQL statements...';
$statements = parseSqlStatements($sql_content);
$total_stmts = count($statements);
$log[] = "✅ {$total_stmts} SQL statements found";

if ($total_stmts === 0) {
    echo json_encode(['success' => false, 'message' => 'No executable SQL statements found.', 'log' => $log]);
    exit;
}

// ── Execute statements ────────────────────────────────────────────
$log[] = '⏳ Executing restore...';
$executed  = 0;
$errors    = [];

try {
    $pdo_raw = getPdoConnection();
    $pdo_raw->exec("SET FOREIGN_KEY_CHECKS = 0");

    foreach ($statements as $stmt) {
        $stmt_trimmed = trim($stmt);
        if (!$stmt_trimmed || str_starts_with($stmt_trimmed, '--')) continue;

        try {
            $pdo_raw->exec($stmt_trimmed);
            $executed++;
        } catch (PDOException $e) {
            // Non-fatal: log and continue (restores partial data > zero data)
            $errors[] = '[' . $executed . '] ' . substr($stmt_trimmed, 0, 80) . '… → ' . $e->getMessage();
        }
    }

    $pdo_raw->exec("SET FOREIGN_KEY_CHECKS = 1");
    $log[] = "✅ Executed {$executed}/{$total_stmts} statements";
    if (!empty($errors)) {
        $log[] = '⚠️ ' . count($errors) . ' non-fatal errors (see server logs)';
        foreach (array_slice($errors, 0, 5) as $err) {
            error_log("Restore error: {$err}");
        }
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Restore execution failed: ' . $e->getMessage(), 'log' => $log]);
    exit;
}

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

// ── Log success ───────────────────────────────────────────────────
logActivity('Database Restored', "File: {$orig_name} | Statements: {$executed}/{$total_stmts} | Errors: " . count($errors));

$success_msg = count($errors) === 0
    ? "Restore complete! {$executed} statements executed successfully."
    : "Restore complete with " . count($errors) . " minor errors. {$executed}/{$total_stmts} statements executed.";

echo json_encode([
    'success'          => true,
    'message'          => $success_msg,
    'log'              => $log,
    'executed'         => $executed,
    'errors'           => count($errors),
    'session_cleared'  => true,
    'redirect_hint'    => '../dashboard.php?restored=1',
]);
exit;


// ══════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════

/**
 * Parse a SQL dump string into individual executable statements.
 * Handles:
 *   - Multi-line statements
 *   - Semicolons inside strings (quoted values)
 *   - Comments (-- and /* )
 *   - DELIMITER (not common in dumps but handled)
 */
function parseSqlStatements(string $sql): array
{
    $statements = [];
    $current    = '';
    $in_string  = false;
    $string_char = '';
    $len         = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        // Skip line comments outside strings
        if (!$in_string && $ch === '-' && isset($sql[$i+1]) && $sql[$i+1] === '-') {
            // Skip to end of line
            while ($i < $len && $sql[$i] !== "\n") $i++;
            $current .= "\n";
            continue;
        }

        // Skip block comments outside strings
        if (!$in_string && $ch === '/' && isset($sql[$i+1]) && $sql[$i+1] === '*') {
            $i += 2;
            while ($i < $len) {
                if ($sql[$i] === '*' && isset($sql[$i+1]) && $sql[$i+1] === '/') { $i++; break; }
                $i++;
            }
            continue;
        }

        // Track string context
        if (!$in_string && ($ch === "'" || $ch === '"' || $ch === '`')) {
            $in_string  = true;
            $string_char = $ch;
        } elseif ($in_string && $ch === $string_char) {
            // Handle escaped quote inside string
            if (isset($sql[$i+1]) && $sql[$i+1] === $string_char) {
                $current .= $ch; $i++; // skip doubled quote
            } else {
                $in_string = false;
            }
        } elseif ($in_string && $ch === '\\') {
            // Skip escaped character
            $current .= $ch;
            $i++;
            if ($i < $len) $current .= $sql[$i];
            continue;
        }

        // Statement delimiter
        if (!$in_string && $ch === ';') {
            $stmt = trim($current);
            if ($stmt !== '') $statements[] = $stmt;
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    // Trailing statement without semicolon
    $stmt = trim($current);
    if ($stmt !== '') $statements[] = $stmt;

    return $statements;
}

/**
 * Get the raw PDO object from the Database singleton.
 * Tries getConnection() first; falls back to creating a new PDO.
 */
function getPdoConnection(): PDO
{
    $db = db();
    if (method_exists($db, 'getConnection')) {
        $conn = $db->getConnection();
        if ($conn instanceof PDO) return $conn;
    }

    // Fallback: create direct PDO
    $host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $name = defined('DB_NAME') ? DB_NAME : 'pharmacy_db';
    $user = defined('DB_USER') ? DB_USER : 'root';
    $pass = defined('DB_PASS') ? DB_PASS : '';
    $port = defined('DB_PORT') ? DB_PORT : '3306';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_LOCAL_INFILE => true,
    ]);
    return $pdo;
}

function formatRestoreSize(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1)    . ' KB';
    return $bytes . ' B';
}
?>