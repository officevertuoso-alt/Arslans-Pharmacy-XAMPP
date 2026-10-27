<?php // REPAIRED vFIX10 - INTEGRATED vFIX11 - UPDATED vFIX17

// Include database configuration
require_once __DIR__ . '/database.php';

// =============================================
// SESSION CONFIGURATION
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie parameters BEFORE session start
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

// =============================================
// ENVIRONMENT SETTING
// =============================================
// FIX S-11: Read APP_ENV from .env file (via database.php env() loader) — defaults to 'production' for safety
if (!defined('APP_ENV')) define('APP_ENV', function_exists('env') ? env('APP_ENV', 'production') : 'production');

// =============================================
// ERROR REPORTING
// =============================================
if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', dirname(__DIR__) . '/logs/php_errors.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

// =============================================
// TIME ZONE
// =============================================
date_default_timezone_set('Asia/Karachi');

// =============================================
// PATHS
// =============================================
define('BASE_PATH', dirname(__DIR__));
if (!defined('ROOT_PATH')) define('ROOT_PATH', BASE_PATH . DIRECTORY_SEPARATOR);

function _detectBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    $docRoot  = rtrim(realpath($_SERVER['DOCUMENT_ROOT']), DIRECTORY_SEPARATOR);
    $basePath = rtrim(realpath(BASE_PATH), DIRECTORY_SEPARATOR);
    
    if (strpos($basePath, $docRoot) === 0) {
        $relPath = ltrim(substr($basePath, strlen($docRoot)), DIRECTORY_SEPARATOR);
        $relPath = str_replace(DIRECTORY_SEPARATOR, '/', $relPath);
    } else {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        if (preg_match('#^(.*?)(?:/config/config\.php|/config\.php)$#', $scriptName, $matches)) {
            $relPath = ltrim($matches[1], '/');
        } else {
            $relPath = ltrim(dirname($scriptName), '/');
            if ($relPath === '.' || $relPath === '') $relPath = '';
        }
    }
    
    $relPath = trim($relPath, '/');
    $baseUrl = $protocol . '://' . $host;
    if ($relPath !== '') $baseUrl .= '/' . $relPath;
    $baseUrl .= '/';
    
    return $baseUrl;
}
define('BASE_URL', _detectBaseUrl());

define('ASSETS_URL', BASE_URL . 'assets/');
define('CSS_URL', BASE_URL . 'css/');
define('JS_URL', BASE_URL . 'js/');

// =============================================
// FILE UPLOAD SETTINGS
// =============================================
define('MAX_FILE_SIZE', 5242880);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf']);
define('UPLOAD_PATH', BASE_PATH . '/uploads/');

// Create required directories
if (!file_exists(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0777, true);
if (!file_exists(UPLOAD_PATH . 'backups/')) mkdir(UPLOAD_PATH . 'backups/', 0777, true);
if (!file_exists(UPLOAD_PATH . 'products/')) mkdir(UPLOAD_PATH . 'products/', 0777, true);
if (!file_exists(BASE_PATH . '/logs/')) mkdir(BASE_PATH . '/logs/', 0755, true);

// =============================================
// CONSTANTS
// =============================================
define('INVOICE_PREFIX', 'INV');
define('INVOICE_START', 1001);
define('CURRENCY', 'Rs');
define('CURRENCY_SYMBOL', 'Rs');
define('CURRENCY_CODE', 'PKR');
define('DECIMAL_PLACES', 2);
define('CACHE_TTL', 60);
define('SYNC_VERSION', '3.0');
define('LOW_STOCK_THRESHOLD', 10);
define('NEAR_EXPIRY_DAYS', 30);
define('DATE_FORMAT', 'd-m-Y');
define('DATE_FORMAT_PHP', 'd-m-Y');
define('DATETIME_FORMAT', 'd-m-Y h:i A');
define('DB_DATE_FORMAT', 'Y-m-d');
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);
if (!defined('CSRF_TOKEN_LENGTH')) define('CSRF_TOKEN_LENGTH', 32);

// =============================================
// HELPER FUNCTIONS
// =============================================

function redirect($url) {
    header("Location: " . BASE_URL . ltrim($url, '/'));
    exit();
}

function setFlash($key, $message, $type = 'success') {
    $_SESSION['flash'][$key] = ['message' => $message, 'type' => $type];
}

function getFlash($key) {
    if (isset($_SESSION['flash'][$key])) {
        $flash = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        $msg  = is_array($flash) ? ($flash['message'] ?? '') : $flash;
        $type = is_array($flash) ? ($flash['type']    ?? 'info') : 'info';
        if ($type === 'error') $type = 'danger';
        $safeMsg  = htmlspecialchars($msg,  ENT_QUOTES, 'UTF-8');
        $safeType = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
        $iconMap  = ['success'=>'fas fa-check-circle','danger'=>'fas fa-times-circle','warning'=>'fas fa-exclamation-triangle','info'=>'fas fa-info-circle'];
        $icon     = $iconMap[$safeType] ?? 'fas fa-info-circle';
        return '<div class="alert alert-' . $safeType . ' alert-dismissible fade show" role="alert">' .
               '<i class="' . $icon . ' me-2"></i>' .
               $safeMsg .
               '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' .
               '</div>';
    }
    return null;
}

// Phase 12: Load unified notification component
require_once dirname(__DIR__) . '/includes/notify.php';

function formatCurrency($amount) {
    return CURRENCY_SYMBOL . ' ' . number_format($amount, DECIMAL_PLACES);
}

function formatPKR($amount) {
    return 'Rs ' . number_format($amount, DECIMAL_PLACES);
}

function formatDate($date, $format = DATE_FORMAT) {
    if (!$date || $date == '0000-00-00') return '-';
    return date($format, strtotime($date));
}

function getCurrentPakistanTime() {
    return date('Y-m-d H:i:s');
}

function getCurrentPakistanDate() {
    return date('Y-m-d');
}

function getPakistanDateTime() {
    return date('d-m-Y h:i A');
}

// =============================================
// INVOICE NUMBER GENERATION
// =============================================

function _generateSequenceNo($prefix) {
    $db = db();
    $pdo = $db->getConnection();
    $year = (int) date('Y');
    $month = (int) date('m');

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO invoice_sequences (prefix, year, month, last_number)
             VALUES (:prefix, :year, :month, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1"
        );
        $stmt->execute([':prefix' => $prefix, ':year' => $year, ':month' => $month]);

        $stmt = $pdo->prepare(
            "SELECT last_number FROM invoice_sequences
             WHERE prefix = :prefix AND year = :year AND month = :month"
        );
        $stmt->execute([':prefix' => $prefix, ':year' => $year, ':month' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $number = (int) $row['last_number'];
        $pdo->commit();

        return $prefix . '-' . $year . str_pad($month, 2, '0', STR_PAD_LEFT)
               . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return $prefix . '-' . date('YmdHis') . '-' . rand(100, 999);
    }
}

function generateInvoiceNo() {
    return _generateSequenceNo('INV');
}

function generatePurchaseInvoiceNo() {
    return _generateSequenceNo('PUR');
}

function generateBatchNo() {
    return 'BATCH-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
}

// ISS-034 FIX: Use REMOTE_ADDR as the authoritative (unforgeable) IP for security-critical
// purposes (login_attempts, activity_logs). X-Forwarded-For headers can be spoofed by any client.
// Pass $trustProxy=true only for informational logging behind a known reverse proxy.
function getClientIP(bool $trustProxy = false) {
    // REMOTE_ADDR is always the direct connection IP — cannot be spoofed by client
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    if ($trustProxy) {
        // Only use forwarded headers for informational/display purposes
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR']
                  ?? $_SERVER['HTTP_X_FORWARDED']
                  ?? $_SERVER['HTTP_CLIENT_IP']
                  ?? $_SERVER['HTTP_FORWARDED_FOR']
                  ?? $remoteAddr;
        // X-Forwarded-For may be comma-separated; take the first (original client)
        return trim(explode(',', $forwarded)[0]);
    }

    return $remoteAddr;
}

// =============================================
// logActivity() - SINGLE SOURCE OF TRUTH
// ISS-030 FIX: Canonical signature is logActivity($action, $description='', $userId=null)
// The auth.php fallback (with $userId as first param) is never reached because
// config.php loads first. All callers must use: logActivity('Action', 'desc', $id)
// =============================================
if (!function_exists('logActivity')) {
    function logActivity($action, $description = '', $userId = null) {
        if ($userId === null) $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) return;
        try {
            $db = db();
            $username = $_SESSION['username'] ?? '';
            
            $db->query("INSERT INTO activity_logs 
                (user_id, username, action, description, ip_address, user_agent, created_at)
                VALUES (:uid, :uname, :action, :desc, :ip, :ua, NOW())");
            $db->bind(':uid',    $userId);
            $db->bind(':uname',  $username);
            $db->bind(':action', $action);
            $db->bind(':desc',   $description);
            $db->bind(':ip',     $_SERVER['REMOTE_ADDR'] ?? '');
            $db->bind(':ua',     $_SERVER['HTTP_USER_AGENT'] ?? '');
            $db->execute();
        } catch (Throwable $e) {
            error_log('[logActivity] ' . $e->getMessage());
        }
    }
}

// =============================================
// AUTHENTICATION HELPERS (Basic)
// =============================================

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

function isPharmacist() {
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'pharmacist';
}

function isCashier() {
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'cashier';
}

// Phase 21: manager role helper (fallback — auth.php version takes priority)
if (!function_exists('isManager')) {
    function isManager() {
        return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'manager';
    }
}

function hasRole($roles) {
    if (!isLoggedIn()) return false;
    if (is_string($roles)) return $_SESSION['user_role'] === $roles;
    return in_array($_SESSION['user_role'], $roles);
}

// ISS-008 FIX: Wrapped with if(!function_exists()) so auth.php version (with force_password_change logic) takes priority
if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (!isLoggedIn()) redirect('auth/login.php');
    }
}

function requireAdmin() {
    requireLogin();
    // isAdmin() function must be available - loaded from auth.php
    if (!function_exists('isAdmin') || !isAdmin()) {
        redirect('dashboard.php');
    }
}

function requirePharmacist() {
    requireLogin();
    // Phase 21: admin and manager also satisfy pharmacist-level pages
    if (function_exists('isAdmin')) {
        $role = $_SESSION['user_role'] ?? '';
        if (!isAdmin() && $role !== 'pharmacist' && $role !== 'manager') {
            redirect('dashboard.php');
        }
    } else {
        // Fallback: only check role from session
        $role = $_SESSION['user_role'] ?? '';
        if ($role !== 'admin' && $role !== 'pharmacist' && $role !== 'manager') {
            redirect('dashboard.php');
        }
    }
}

function sanitize($input) {
    return strip_tags(trim($input));
}

// =============================================
// PERMISSION HELPERS (RBAC)
// =============================================

function _loadPermissions() {
    if (!isLoggedIn()) return;
    if (isset($_SESSION['permissions'])) return;
    if (function_exists('isAdmin') && isAdmin()) {
        $_SESSION['permissions'] = '__admin__';
        return;
    }

    $db  = db();
    $pdo = $db->getConnection();

    // ── Detect which columns actually exist in user_permissions ──
    // This prevents fatal errors when optional columns (can_print,
    // can_export, can_import, can_void) have not been added yet.
    try {
        $existingCols = array_column(
            $pdo->query("SHOW COLUMNS FROM user_permissions")->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
    } catch (PDOException $e) {
        // Table may not exist yet — skip loading permissions
        $_SESSION['permissions'] = [];
        return;
    }

    // Build SELECT list from only the columns that are present
    $wantedCols = ['module', 'can_view', 'can_add', 'can_edit', 'can_delete',
                   'can_print', 'can_export', 'can_import', 'can_void'];
    $selectCols = array_filter($wantedCols, fn($c) => in_array($c, $existingCols, true));
    if (!in_array('module', $selectCols, true)) {
        $_SESSION['permissions'] = [];
        return;
    }
    $selectSQL = implode(', ', $selectCols);

    $db->query("SELECT $selectSQL FROM user_permissions WHERE user_id = :uid");
    $db->bind(':uid', $_SESSION['user_id']);
    $rows  = $db->resultSet();
    $perms = [];
    foreach ($rows as $row) {
        $perms[$row['module']] = [
            'view'   => (bool)($row['can_view']   ?? true),
            'add'    => (bool)($row['can_add']     ?? true),
            'edit'   => (bool)($row['can_edit']    ?? true),
            'delete' => (bool)($row['can_delete']  ?? true),
            'print'  => (bool)($row['can_print']   ?? true),
            'export' => (bool)($row['can_export']  ?? true),
            'import' => (bool)($row['can_import']  ?? true),
            'void'   => (bool)($row['can_void']    ?? true),
        ];
    }
    $_SESSION['permissions'] = $perms;
}

function _hasPerm($module, $action) {
    if (!isLoggedIn()) return false;
    if (function_exists('isAdmin') && isAdmin()) return true;
    _loadPermissions();
    if (!is_array($_SESSION['permissions'])) return false;
    return !empty($_SESSION['permissions'][$module][$action]);
}

function clearPermissionCache() {
    unset($_SESSION['permissions']);
}

// ISS-001 FIX: Wrapped with if(!function_exists()) so auth.php DB-backed versions take priority
if (!function_exists('canView'))   { function canView($module)   { return _hasPerm($module, 'view'); } }
if (!function_exists('canAdd'))    { function canAdd($module)    { return _hasPerm($module, 'add'); } }
if (!function_exists('canEdit'))   { function canEdit($module)   { return _hasPerm($module, 'edit'); } }
if (!function_exists('canDelete')) { function canDelete($module) { return _hasPerm($module, 'delete'); } }
if (!function_exists('canPrint'))  { function canPrint($module)  { return _hasPerm($module, 'print'); } }
// Phase 21: additional action helpers
if (!function_exists('canExport')) { function canExport($module) { return _hasPerm($module, 'export'); } }
if (!function_exists('canImport')) { function canImport($module) { return _hasPerm($module, 'import'); } }
if (!function_exists('canVoid'))   { function canVoid($module)   { return _hasPerm($module, 'void'); } }

function requirePermission($module, $action) {
    requireLogin();
    if (!_hasPerm($module, $action)) {
        setFlash('error', 'Aapke paas yeh action karne ki permission nahi hai.', 'danger');
        redirect('dashboard.php');
    }
}

// =============================================
// LOGIN RATE LIMITING
// =============================================

if (!function_exists('checkLoginRateLimit')) {
    function checkLoginRateLimit(): array {
        $maxAttempts    = LOGIN_MAX_ATTEMPTS;
        $lockoutMinutes = LOGIN_LOCKOUT_MINUTES;

        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = [];
        }

        $now = time();
        foreach ($_SESSION['login_attempts'] as $key => $timestamp) {
            if ($timestamp < $now - ($lockoutMinutes * 60)) {
                unset($_SESSION['login_attempts'][$key]);
            }
        }

        $attempts    = count($_SESSION['login_attempts']);
        $locked      = ($attempts >= $maxAttempts);
        $lockoutUntil = $locked
            ? (max($_SESSION['login_attempts']) + ($lockoutMinutes * 60))
            : 0;

        return [
            'locked'       => $locked,
            'attempts'     => $attempts,
            'lockout_until' => $lockoutUntil,
        ];
    }
}

if (!function_exists('recordFailedLoginAttempt')) {
    function recordFailedLoginAttempt(string $username = ''): void {
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = [];
        }
        $_SESSION['login_attempts'][] = time();
    }
}

if (!function_exists('clearFailedLoginAttempts')) {
    function clearFailedLoginAttempts(string $username = ''): void {
        unset($_SESSION['login_attempts']);
    }
}

if (!function_exists('recordFailedLogin')) {
    function recordFailedLogin(string $username = ''): void {
        recordFailedLoginAttempt($username);
    }
}
if (!function_exists('clearFailedLogins')) {
    function clearFailedLogins(string $username = ''): void {
        clearFailedLoginAttempts($username);
    }
}

// =============================================
// UI HELPER FUNCTIONS
// =============================================

function getStockBadge($quantity, $reorderLevel = LOW_STOCK_THRESHOLD) {
    if ($quantity <= 0) return '<span class="badge bg-danger">Out of Stock</span>';
    if ($quantity <= $reorderLevel) return '<span class="badge bg-warning text-dark">Low Stock (' . $quantity . ')</span>';
    return '<span class="badge bg-success">In Stock (' . $quantity . ')</span>';
}

function getExpiryBadge($expiryDate) {
    if (!$expiryDate || $expiryDate == '0000-00-00') return '<span class="badge bg-secondary">N/A</span>';
    $today = new DateTime();
    $expiry = new DateTime($expiryDate);
    $diff = (int) $today->diff($expiry)->days;
    if ($expiry < $today) return '<span class="badge bg-danger">Expired</span>';
    if ($diff <= NEAR_EXPIRY_DAYS) return '<span class="badge bg-warning text-dark">Near Expiry (' . $diff . ' days)</span>';
    return '<span class="badge bg-success">Valid</span>';
}

// =============================================
// CSRF TOKEN FUNCTIONS
// =============================================

if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken(string $token): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $stored = $_SESSION['csrf_token'] ?? '';
        if (empty($stored) || empty($token)) return false;
        return hash_equals($stored, $token);
    }
}

// =============================================
// THEME & SETTINGS FUNCTIONS (auto-load)
// =============================================
if (!function_exists('generateDynamicCSS') && !function_exists('getCompanySettings')) {
    $__sfPath = __DIR__ . '/../includes/settings_functions.php';
    if (file_exists($__sfPath)) {
        require_once $__sfPath;
    }
    unset($__sfPath);
}

// =============================================
// PHASE 28 SECURITY MIDDLEWARE
// =============================================
if (!defined('SECURITY_LOADED')) {
    define('SECURITY_LOADED', true);
    require_once __DIR__ . '/../includes/security.php';
}
?>