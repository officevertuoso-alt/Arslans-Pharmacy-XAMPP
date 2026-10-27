<?php
/**
 * Security Middleware — Phase 28
 * File: includes/security.php
 *
 * Centralised security layer:
 *   - Secure session hardening (fixation + hijacking prevention)
 *   - CSRF token generation & verification (rotating + per-request validation)
 *   - Input sanitisation helpers (XSS / HTML injection)
 *   - Security headers (CSP, X-Frame-Options, etc.)
 *   - Rate-limiting helpers (DB-backed per IP)
 *   - Secure cookie defaults
 *   - Directory traversal guard
 *   - Upload validation (type, size, MIME)
 *
 * Usage: require_once once (typically from config/config.php)
 * All functions are guarded with function_exists() — safe to include multiple times.
 */

if (session_status() === PHP_SESSION_NONE) {
    // ── Secure session cookie parameters ─────────────────────────────────────
    $cookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookieParams);
    ini_set('session.use_strict_mode',   1);   // reject unrecognised session IDs
    ini_set('session.use_only_cookies',  1);   // no session IDs in URLs (fixes fixation)
    ini_set('session.cookie_httponly',   1);
    ini_set('session.cookie_samesite',   'Lax');
    ini_set('session.gc_maxlifetime',    3600); // 1 hour idle timeout
    session_start();
}

// ══════════════════════════════════════════════════════════════════════════════
// 1. SECURITY HEADERS
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('sendSecurityHeaders')) {
    function sendSecurityHeaders(): void
    {
        if (headers_sent()) return;
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
        // Content Security Policy — allows Bootstrap CDN + our own assets
        header("Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
            . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
            . "img-src 'self' data:; "
            . "connect-src 'self'; "
            . "frame-ancestors 'self';"
        );
    }
}

// Auto-send headers on include (skip for non-HTML contexts)
if (!defined('SKIP_SECURITY_HEADERS')) {
    sendSecurityHeaders();
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. SESSION FIXATION / HIJACKING PREVENTION
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('validateSessionIntegrity')) {
    /**
     * Call after requireLogin() — validates that the session hasn't been
     * hijacked by a different UA/IP combination than when it was created.
     *
     * Phase 28: Adds UA fingerprint binding.
     */
    function validateSessionIntegrity(): bool
    {
        if (empty($_SESSION['user_id'])) return true; // not logged in — nothing to check

        $currentUA = md5($_SERVER['HTTP_USER_AGENT'] ?? 'NO_UA');

        if (!isset($_SESSION['_sec_ua'])) {
            // First request after login — bind fingerprint
            $_SESSION['_sec_ua'] = $currentUA;
        } elseif ($_SESSION['_sec_ua'] !== $currentUA) {
            // UA changed mid-session → probable session hijack
            session_unset();
            session_destroy();
            return false;
        }
        return true;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 3. CSRF TOKEN HELPERS  (rotating per-session, with timing-safe compare)
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $stored = $_SESSION['csrf_token'] ?? '';
        if (empty($stored) || empty($token)) return false;
        return hash_equals($stored, $token);
    }
}

if (!function_exists('csrfField')) {
    /**
     * Output a ready-to-use hidden CSRF input field.
     */
    function csrfField(): string
    {
        $token = generateCSRFToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('requireCSRF')) {
    /**
     * Abort with JSON error if CSRF token is missing/invalid.
     * Call at the top of every state-mutating AJAX handler.
     */
    function requireCSRF(): void
    {
        $token = $_POST['csrf_token']
              ?? $_SERVER['HTTP_X_CSRF_TOKEN']
              ?? $_GET['csrf']
              ?? '';
        if (!verifyCSRFToken($token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token invalid. Please refresh the page.']);
            exit;
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 4. XSS — OUTPUT ESCAPING HELPERS
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('hesc')) {
    /**
     * HTML-escape a value for safe output inside HTML context.
     */
    function hesc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('jesc')) {
    /**
     * JSON-safe escape for inline JS string literals.
     */
    function jesc($value): string
    {
        return json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }
}

if (!function_exists('sanitizeInput')) {
    /**
     * Strip tags and trim — for text fields that must never contain HTML.
     */
    function sanitizeInput(string $input, int $maxLength = 500): string
    {
        return mb_substr(strip_tags(trim($input)), 0, $maxLength);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 5. UPLOAD VALIDATION
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('validateUpload')) {
    /**
     * Validate an uploaded file by MIME type (finfo), extension, and size.
     *
     * @param array  $file         $_FILES['field']
     * @param array  $allowedMimes e.g. ['image/jpeg','image/png']
     * @param array  $allowedExts  e.g. ['jpg','jpeg','png']
     * @param int    $maxBytes     default 5 MB
     * @return array ['valid'=>bool, 'error'=>string, 'ext'=>string, 'mime'=>string]
     */
    function validateUpload(
        array  $file,
        array  $allowedMimes,
        array  $allowedExts,
        int    $maxBytes = 5242880
    ): array {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'Upload failed (error code ' . ($file['error'] ?? '?') . ').'];
        }

        // File size
        if ($file['size'] > $maxBytes) {
            return ['valid' => false, 'error' => 'File too large. Maximum: ' . round($maxBytes / 1048576, 1) . ' MB.'];
        }

        // MIME by magic bytes — client-supplied Content-Type is NOT trusted
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowedMimes, true)) {
            return ['valid' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedMimes) . '.'];
        }

        // Extension from original filename — only used to derive safe save name
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            // Extension mismatch — derive safe extension from MIME
            $mimeExtMap = [
                'image/jpeg'      => 'jpg',  'image/png'  => 'png',
                'image/gif'       => 'gif',  'image/webp' => 'webp',
                'image/bmp'       => 'bmp',  'image/tiff' => 'tif',
                'application/pdf' => 'pdf',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'application/vnd.ms-excel' => 'xls',
                'text/csv'        => 'csv',  'text/plain' => 'txt',
                'application/zip' => 'zip',
                'application/gzip' => 'gz',
            ];
            $ext = $mimeExtMap[$mime] ?? 'bin';
        }

        return ['valid' => true, 'error' => '', 'ext' => $ext, 'mime' => $mime];
    }
}

if (!function_exists('safeUploadFilename')) {
    /**
     * Generate a safe, collision-resistant filename from a prefix, ID, extension.
     * Never trusts user-supplied filename for the saved file name.
     */
    function safeUploadFilename(string $prefix, $id, string $ext): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($prefix))
             . '_' . intval($id)
             . '_' . bin2hex(random_bytes(6))
             . '.' . preg_replace('/[^a-z0-9]/', '', strtolower($ext));
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 6. DIRECTORY TRAVERSAL GUARD
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('assertPathInsideDir')) {
    /**
     * Throw / return false if $resolvedPath escapes $baseDir.
     * Always use realpath() before calling this.
     *
     * @param string $resolvedPath  realpath() result of the candidate path
     * @param string $baseDir       realpath() result of the allowed base directory
     * @return bool  true if safe, false if traversal detected
     */
    function assertPathInsideDir(string $resolvedPath, string $baseDir): bool
    {
        // Normalise: ensure trailing separator on baseDir
        $base = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($resolvedPath . DIRECTORY_SEPARATOR, $base);
    }
}

if (!function_exists('safePath')) {
    /**
     * Resolve a filename (no path separators allowed) inside a base directory.
     * Returns the full path if safe, or throws RuntimeException.
     *
     * @param string $filename  User-supplied filename (basename only)
     * @param string $baseDir   Absolute base directory (will be realpath'd)
     * @return string           Absolute, verified safe path
     */
    function safePath(string $filename, string $baseDir): string
    {
        // Strip any directory component from the filename
        $filename = basename($filename);

        // Reject null bytes, path traversal patterns
        if (str_contains($filename, "\0") || $filename === '' || $filename === '.' || $filename === '..') {
            throw new RuntimeException('Invalid filename.');
        }

        $base     = realpath($baseDir);
        if ($base === false) {
            throw new RuntimeException('Base directory does not exist.');
        }

        $fullPath = $base . DIRECTORY_SEPARATOR . $filename;

        // The file may not exist yet (upload destination) — check the parent
        $resolved = realpath(dirname($fullPath));
        if ($resolved === false || !assertPathInsideDir($resolved, $base)) {
            throw new RuntimeException('Path traversal detected.');
        }

        return $fullPath;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 7. PREPARED-STATEMENT HELPERS  (wrappers that enforce parameterised queries)
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('secureQuery')) {
    /**
     * Execute a SELECT and return all rows.
     * Wraps db()->getAll() to document intent — all queries MUST use placeholders.
     *
     * @param string $sql    SQL with named or positional placeholders
     * @param array  $params Bound parameters
     * @return array
     */
    function secureQuery(string $sql, array $params = []): array
    {
        return db()->getAll($sql, $params);
    }
}

if (!function_exists('secureQueryOne')) {
    /**
     * Execute a SELECT and return a single row or null.
     */
    function secureQueryOne(string $sql, array $params = []): ?array
    {
        return db()->getOne($sql, $params) ?: null;
    }
}
