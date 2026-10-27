<?php
/**
 * ONE-TIME PATCH SCRIPT
 * =====================
 * Run this ONCE from browser: http://localhost/pharmacy_software/fix_return_functions_patch.php
 * OR just manually edit return_functions.php as described below.
 *
 * WHAT IT DOES:
 * Wraps processPurchaseReturn() in return_functions.php with
 * if (!function_exists()) guard to prevent "Cannot redeclare" fatal errors.
 *
 * MANUAL FIX (if you prefer):
 * Open:  includes/return_functions.php
 * Find:  function processPurchaseReturn(
 * Above that line, add:   if (!function_exists('processPurchaseReturn')) {
 * After the closing }     add one more: }
 */

// Security: only run from CLI or localhost
$allowedIPs = ['127.0.0.1', '::1', 'localhost'];
$clientIP   = $_SERVER['REMOTE_ADDR'] ?? 'cli';
if (php_sapi_name() !== 'cli' && !in_array($clientIP, $allowedIPs)) {
    die('Access denied. Run from localhost only.');
}

$filePath = __DIR__ . '/includes/return_functions.php';

if (!file_exists($filePath)) {
    die("ERROR: File not found: {$filePath}\nCheck the path and try again.\n");
}

$content = file_get_contents($filePath);
$original = $content;

// ── Check if already patched ──────────────────────────────────────────────
if (strpos($content, "if (!function_exists('processPurchaseReturn'))") !== false) {
    echo "✓ return_functions.php already has the guard. No changes needed.\n";
    exit;
}

// ── Find the function declaration ─────────────────────────────────────────
// Handles both: "function processPurchaseReturn(" and typed variants
$pattern = '/^([ \t]*function\s+processPurchaseReturn\s*\()/m';

if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
    die("ERROR: Could not find 'function processPurchaseReturn(' in return_functions.php.\nManual fix required.\n");
}

$funcPos  = $matches[0][1];   // char offset of the function keyword
$indent   = $matches[1][0];   // leading whitespace (if any)
$funcLine = $matches[0][0];   // full matched line start

// ── Find the matching closing brace of this function ─────────────────────
$braceStart = strpos($content, '{', $funcPos);
if ($braceStart === false) {
    die("ERROR: Could not find opening brace of processPurchaseReturn.\n");
}

$depth  = 0;
$len    = strlen($content);
$endPos = -1;
for ($i = $braceStart; $i < $len; $i++) {
    if ($content[$i] === '{') $depth++;
    elseif ($content[$i] === '}') {
        $depth--;
        if ($depth === 0) { $endPos = $i; break; }
    }
}

if ($endPos === -1) {
    die("ERROR: Could not find closing brace of processPurchaseReturn.\n");
}

// ── Build patched content ─────────────────────────────────────────────────
$before    = substr($content, 0, $funcPos);
$funcBlock = substr($content, $funcPos, $endPos - $funcPos + 1);
$after     = substr($content, $endPos + 1);

$patched = $before
    . "if (!function_exists('processPurchaseReturn')) {\n"
    . $funcBlock . "\n"
    . "}\n"
    . $after;

// ── Backup original ───────────────────────────────────────────────────────
$backupPath = $filePath . '.bak_' . date('Ymd_His');
file_put_contents($backupPath, $original);

// ── Write patched file ────────────────────────────────────────────────────
$written = file_put_contents($filePath, $patched);
if ($written === false) {
    die("ERROR: Could not write to {$filePath}. Check file permissions.\n");
}

echo "✓ SUCCESS!\n";
echo "  Backed up original to: {$backupPath}\n";
echo "  Patched: {$filePath}\n";
echo "  processPurchaseReturn() is now safely guarded.\n";
echo "\nYou can now delete this patch file.\n";