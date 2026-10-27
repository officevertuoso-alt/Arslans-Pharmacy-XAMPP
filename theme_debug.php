<?php
/**
 * === FILE: pharmacy_software/theme_debug.php ===
 *
 * DROP THIS FILE temporarily into pharmacy_software/ root.
 * Visit: http://localhost/pharmacy_software/theme_debug.php
 *
 * It will show you:
 *  1. Whether config.php loads cleanly
 *  2. Whether theme engine files exist at the expected paths
 *  3. Whether the AJAX endpoint returns valid JSON
 *  4. The actual PHP error if apply_theme.php is crashing
 *
 * DELETE THIS FILE after debugging — it exposes internal paths.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = __DIR__;

echo '<h2>Theme Debug — ' . date('H:i:s') . '</h2><pre>';

// ── 1. File existence checks ──────────────────────────────────────────────────
$files = [
    'config/config.php',
    'includes/auth.php',
    'includes/functions.php',
    'assets/themes/themes.config.php',
    'assets/themes/theme.engine.php',
    'ajax/apply_theme.php',
    'js/theme-picker.js',
];

echo "=== FILE EXISTENCE ===\n";
foreach ($files as $f) {
    $full = $base . '/' . $f;
    $exists = file_exists($full);
    echo ($exists ? '✓' : '✗ MISSING') . '  ' . $f . "\n";
}

// ── 2. Load config safely ─────────────────────────────────────────────────────
echo "\n=== CONFIG LOAD ===\n";
ob_start();
try {
    require_once $base . '/config/config.php';
    $leaked = ob_get_clean();
    if ($leaked !== '') {
        echo "⚠ config.php leaked output (" . strlen($leaked) . " bytes) — THIS BREAKS JSON!\n";
        echo "  First 200 chars: " . substr(json_encode($leaked), 0, 200) . "\n";
    } else {
        echo "✓ config.php loaded with no stray output\n";
    }
    echo '  BASE_URL = ' . (defined('BASE_URL') ? BASE_URL : 'NOT DEFINED') . "\n";
    echo '  BASE_PATH = ' . (defined('BASE_PATH') ? BASE_PATH : 'NOT DEFINED') . "\n";
    echo '  ROOT_PATH = ' . (defined('ROOT_PATH') ? ROOT_PATH : 'NOT DEFINED') . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "✗ config.php threw: " . $e->getMessage() . "\n";
}

// ── 3. Load theme engine ──────────────────────────────────────────────────────
echo "\n=== THEME ENGINE LOAD ===\n";
ob_start();
try {
    if (!defined('THEME_ENGINE_NO_AUTO_APPLY')) define('THEME_ENGINE_NO_AUTO_APPLY', true);
    require_once $base . '/assets/themes/themes.config.php';
    require_once $base . '/assets/themes/theme.engine.php';
    $leaked = ob_get_clean();
    if ($leaked !== '') {
        echo "⚠ theme engine leaked output — THIS BREAKS JSON!\n";
        echo "  Content: " . substr(json_encode($leaked), 0, 300) . "\n";
    } else {
        echo "✓ Theme engine loaded cleanly\n";
    }
    echo '  THEME_ENGINE_LOADED = ' . (defined('THEME_ENGINE_LOADED') ? 'true' : 'NOT DEFINED') . "\n";
    $engine = themeEngine();
    echo '  Active theme ID = ' . $engine->getThemeId() . "\n";
    $theme = $engine->getTheme();
    echo '  Active theme name = ' . $theme['name'] . "\n";
    echo '  CSS vars count = ' . count($theme['vars']) . "\n";
    echo '  Vars: ' . implode(', ', array_keys($theme['vars'])) . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "✗ Theme engine threw: " . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine() . "\n";
}

// ── 4. Simulate the AJAX call internally ─────────────────────────────────────
echo "\n=== SIMULATE apply_theme.php (POST theme_id=default) ===\n";
ob_start();
try {
    $engine2  = themeEngine();
    $applied  = $engine2->setTheme('default');
    $leaked   = ob_get_clean();
    if ($leaked !== '') {
        echo "⚠ setTheme() leaked output: " . substr(json_encode($leaked), 0, 200) . "\n";
    }
    if ($applied) {
        $t   = $engine2->getTheme('default');
        $out = json_encode([
            'success'    => true,
            'theme_id'   => 'default',
            'theme_name' => $t['name'],
            'css_vars'   => $engine2->getCssVarsHtml('default'),
        ]);
        echo "✓ setTheme('default') succeeded\n";
        echo "  JSON response length: " . strlen($out) . " bytes\n";
        echo "  JSON valid: " . (json_decode($out) !== null ? 'YES' : 'NO — ' . json_last_error_msg()) . "\n";
    } else {
        echo "✗ setTheme('default') returned false\n";
    }
} catch (Throwable $e) {
    ob_end_clean();
    echo "✗ Simulation threw: " . $e->getMessage() . "\n";
}

// ── 5. Session check ──────────────────────────────────────────────────────────
echo "\n=== SESSION ===\n";
echo '  active_theme in session = ' . ($_SESSION['active_theme'] ?? 'NOT SET') . "\n";
echo '  Session ID = ' . session_id() . "\n";

// ── 6. BASE_URL → AJAX URL ────────────────────────────────────────────────────
echo "\n=== AJAX ENDPOINT URL ===\n";
$ajaxUrl = (defined('BASE_URL') ? BASE_URL : 'http://localhost/pharmacy_software/') . 'ajax/apply_theme.php';
echo "  Expected URL: $ajaxUrl\n";
echo "  Make sure theme-picker.js resolves to exactly this URL.\n";
echo "  If your JS builds the URL differently, that is your network error.\n";

echo "\n=== DONE ===\n";
echo '</pre>';
echo '<p style="color:red;font-weight:bold">⚠ DELETE theme_debug.php after debugging!</p>';
