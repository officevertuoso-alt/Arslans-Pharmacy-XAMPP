<?php
/**
 * === FILE: pharmacy_software/ajax/apply_theme.php ===
 *
 * AJAX: Apply Theme
 * POST body: theme_id=light_green_medical  (form-encoded)
 *        or: {"theme_id":"light_green_medical"}  (JSON)
 *
 * Response: {
 *   "success"    : true,
 *   "theme_id"   : "light_green_medical",
 *   "theme_name" : "Light Green Medical",
 *   "css_vars"   : "<style id=\"theme-engine-vars\">...</style>"
 * }
 *
 * FIX NOTES:
 *   • ob_start() at the very top catches any stray whitespace / BOM / error
 *     output from config.php or auth.php that would corrupt the JSON response
 *     and cause the JS to show "Network error".
 *   • BASE_PATH resolved from __DIR__ so the file works regardless of the
 *     URL path used to call it.
 *   • Fallback: if is_logged_in() does not exist (auth loaded differently)
 *     the script degrades gracefully instead of fatal-erroring.
 *   • CORS header added for localhost dev environments.
 *   • Detailed error messages in JSON so browser console shows the real cause.
 */

// ── 1. Buffer ALL output — nothing must leak before our JSON ─────────────────
ob_start();

// ── 2. Force JSON content type & basic CORS for localhost dev ────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Allow localhost origins (remove in production if not needed)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^https?://localhost(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

// ── 3. Resolve paths relative to THIS file — never rely on cwd ───────────────
$_basePath = dirname(__DIR__); // pharmacy_software/

// ── 4. Load config (session, DB, BASE_URL, BASE_PATH constants) ──────────────
$_configPath = $_basePath . '/config/config.php';
if (!file_exists($_configPath)) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server config missing. Expected: ' . $_configPath,
    ]);
    exit;
}
require_once $_configPath;

// ── 5. Load auth helper (optional — degrade gracefully if missing) ────────────
$_authPath = $_basePath . '/includes/auth.php';
if (file_exists($_authPath)) {
    require_once $_authPath;
}

// ── 6. Auth check ─────────────────────────────────────────────────────────────
if (function_exists('is_logged_in') && !is_logged_in()) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized — please log in again.']);
    exit;
}
// If is_logged_in() doesn't exist, requireLogin() from config may already handle it.
// Trust the session check done by config.php in that case.

// ── 7. Method guard ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

// ── 8. Load theme engine (suppress auto-render) ───────────────────────────────
if (!defined('THEME_ENGINE_NO_AUTO_APPLY')) {
    define('THEME_ENGINE_NO_AUTO_APPLY', true);
}

$_themesConfigPath = $_basePath . '/assets/themes/themes.config.php';
$_themeEnginePath  = $_basePath . '/assets/themes/theme.engine.php';

if (!file_exists($_themesConfigPath)) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'themes.config.php not found at: ' . $_themesConfigPath,
    ]);
    exit;
}
if (!file_exists($_themeEnginePath)) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'theme.engine.php not found at: ' . $_themeEnginePath,
    ]);
    exit;
}

require_once $_themesConfigPath;

if (!defined('THEME_ENGINE_LOADED')) {
    require_once $_themeEnginePath;
}

if (!defined('THEME_ENGINE_LOADED')) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Theme engine failed to load (THEME_ENGINE_LOADED not defined after require).',
    ]);
    exit;
}

// ── 9. Read theme_id (form-encoded or JSON body) ──────────────────────────────
$theme_id = '';
$ct = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($ct, 'application/json')) {
    $rawBody  = file_get_contents('php://input');
    $body     = json_decode($rawBody, true);
    $theme_id = trim($body['theme_id'] ?? '');
} else {
    $theme_id = trim($_POST['theme_id'] ?? '');
}

// Sanitise — only lowercase letters, digits, underscores
$theme_id = preg_replace('/[^a-z0-9_]/', '', strtolower($theme_id));

if ($theme_id === '') {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'theme_id is required.']);
    exit;
}

// ── 10. Apply via engine ──────────────────────────────────────────────────────
try {
    $engine  = themeEngine();
    $applied = $engine->setTheme($theme_id);
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Theme engine exception: ' . $e->getMessage(),
    ]);
    exit;
}

if (!$applied) {
    global $themes;
    $validIds = array_keys($themes ?? []);
    ob_end_clean();
    http_response_code(422);
    echo json_encode([
        'success'      => false,
        'message'      => "Unknown theme ID: '{$theme_id}'.",
        'valid_themes' => $validIds,
    ]);
    exit;
}

$theme = $engine->getTheme($theme_id);

// ── 11. Discard any stray output, send clean JSON ─────────────────────────────
$strayOutput = ob_get_clean();
// (strayOutput is intentionally discarded — it was garbage from includes)

echo json_encode([
    'success'    => true,
    'theme_id'   => $theme_id,
    'theme_name' => $theme['name'],
    'css_vars'   => $engine->getCssVarsHtml($theme_id),
]);
