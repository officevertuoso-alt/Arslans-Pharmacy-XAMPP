<?php
/**
 * === FILE: pharmacy_software/includes/header.php ===
 *
 * Step 4 — Head Enhancement:
 *   • color-scheme meta for dark mode support
 *   • Page loading indicator (thin top progress bar)
 *   • preconnect hints for faster font loading
 *   • generateDynamicCSS() placed AFTER static CSS vars (already correct)
 *   • Page title format: "Page — Pharmacy Name"
 *   • <noscript> fallback message
 *   • <div id="page-loader"> as first element in <body>
 *   • beforeunload JS to animate loader on navigation
 *
 * Requires:
 *   config/config.php        → session, BASE_URL, ROOT_PATH, requireLogin()
 *   includes/functions.php   → getCompanySettings(), getPharmacyName(), getThemeColors()
 *
 * FIX (theme_fix_v3):
 *   themeEngineBootstrap() and themeEngineInjectVars() do not exist in
 *   theme.engine.php. The engine exposes a class-based singleton:
 *     themeEngine()               → ThemeEngine singleton (auto-bootstraps)
 *     themeEngine()->renderCssVars() → outputs <style id="theme-engine-vars">
 *   THEME_ENGINE_NO_AUTO_APPLY is defined BEFORE require_once so the file's
 *   own auto-render block is suppressed; header.php controls injection timing.
 */

if (!isset($skipAuthCheck)) {
    requireLogin();
}

// ── Pull branding from DB (cached in session for 10 min) ─────────────────────
$_settingsCacheKey  = 'company_settings_cache';
$_settingsCacheTime = 'company_settings_cache_time';
$_settingsCacheTTL  = 600; // 10 minutes

if (
    !isset($_SESSION[$_settingsCacheKey]) ||
    (time() - (int)($_SESSION[$_settingsCacheTime] ?? 0)) > $_settingsCacheTTL
) {
    try {
        $_cachedSettings = getCompanySettings(); // from includes/functions.php
        $_SESSION[$_settingsCacheKey]  = $_cachedSettings;
        $_SESSION[$_settingsCacheTime] = time();
    } catch (Throwable $_e) {
        $_cachedSettings = [];
    }
} else {
    $_cachedSettings = $_SESSION[$_settingsCacheKey];
}

$_settings = $_cachedSettings;

// ── Helpers ──────────────────────────────────────────────────────────────────
$_pharmacyName = trim($_settings['pharmacy_name'] ?? $_settings['company_name'] ?? 'Pharmacy MS');
$_pharmacyName = $_pharmacyName ?: 'Pharmacy MS';

$_pageTitle    = isset($page_title) && $page_title !== ''
    ? htmlspecialchars($page_title) . ' — ' . htmlspecialchars($_pharmacyName)
    : htmlspecialchars($_pharmacyName);

// ── Theme colors (with safe fallbacks) ───────────────────────────────────────
$_primary  = preg_match('/^#[0-9A-Fa-f]{3,6}$/', $_settings['primary_color']  ?? '') ? $_settings['primary_color']  : '#0d6efd';
$_sidebar  = preg_match('/^#[0-9A-Fa-f]{3,6}$/', $_settings['sidebar_color']  ?? '') ? $_settings['sidebar_color']  : '#1a1d23';
$_accent   = preg_match('/^#[0-9A-Fa-f]{3,6}$/', $_settings['accent_color']   ?? '') ? $_settings['accent_color']   : '#667eea';

// ── Favicon ───────────────────────────────────────────────────────────────────
$_faviconTag = '';
if (!empty($_settings['favicon_path'])) {
    $_favPath     = ROOT_PATH . ltrim($_settings['favicon_path'], '/');
    $_favCacheBust = file_exists($_favPath) ? filemtime($_favPath) : time();
    $_faviconTag   = '<link rel="icon" type="image/x-icon" href="'
        . htmlspecialchars(BASE_URL . ltrim($_settings['favicon_path'], '/'))
        . '?v=' . $_favCacheBust . '">';
}

// ── Load Theme Engine early so THEME_ENGINE_LOADED is available for the
//    inline <script> block and renderCssVars() call below.
//    THEME_ENGINE_NO_AUTO_APPLY must be defined BEFORE require_once so the
//    file's own bottom auto-render block is suppressed — header.php owns
//    the injection point (after generateDynamicCSS).
// ─────────────────────────────────────────────────────────────────────────────
if (!defined('THEME_ENGINE_NO_AUTO_APPLY')) {
    define('THEME_ENGINE_NO_AUTO_APPLY', true);
}

$__themeEnginePath = BASE_PATH . '/assets/themes/theme.engine.php';
if (file_exists($__themeEnginePath) && !defined('THEME_ENGINE_LOADED')) {
    require_once $__themeEnginePath;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Dynamic title: "Page — Pharmacy Name" -->
    <title><?php echo $_pageTitle; ?></title>

    <!-- Dynamic favicon (cache-busted) -->
    <?php echo $_faviconTag; ?>

    <!-- Meta tags with pharmacy info -->
    <meta name="application-name" content="<?php echo htmlspecialchars($_pharmacyName); ?>">
    <meta name="description"      content="<?php echo htmlspecialchars($_pharmacyName); ?> — Pharmacy Management System">
    <meta name="theme-color"      content="<?php echo htmlspecialchars($_primary); ?>">

    <!-- Dark mode support: browser honours prefers-color-scheme AND our .dark-mode class -->
    <meta name="color-scheme" content="light dark">

    <!-- Preconnect hints — reduces font/CDN latency -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons (used by navbar notification renderer) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- App stylesheet -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>css/style.css">
    <!-- Phase 9: Universal Table System -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>css/universal_table.css">

    <!-- Preload script.js (includes PharmaModal — Step 8) so the browser fetches
         it in parallel with CSS; the actual <script> tag lives in footer.php -->
    <link rel="preload" href="<?php echo BASE_URL; ?>js/script.js" as="script">

    <!-- Google Fonts — loaded for font selector in Settings > Theme & Display -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;600&family=Inter:wght@300;400;600&family=Poppins:wght@300;400;600&family=Open+Sans:wght@300;400;600&family=Nunito:wght@300;400;600&family=Lato:wght@300;400&display=swap" rel="stylesheet">

    <!-- NoScript fallback — shown only when JavaScript is disabled -->
    <noscript>
        <style>
            .noscript-warning { display: block !important; }
            /* Hide JS-dependent UI that would be broken without JS */
            .sidebar-toggle-btn,
            #notificationBell,
            [data-bs-toggle],
            .ajax-dependent { opacity: 0.5; pointer-events: none; }
        </style>
    </noscript>

    <!-- ══════════════════════════════════════════════
         PHASE 6 — THEME INTEGRATION CORE
         Hardcoded :root color vars removed.
         All theme vars now sourced exclusively from:
           1. themeEngine()->renderCssVars() — emits --bg-color, --sidebar-color,
              --primary-color, --accent-color, --text-color, --card-color
           2. page_head.php  — emits --pharmacy-* aliases for legacy CSS
         Only structural/layout CSS lives here.
         ══════════════════════════════════════════════ -->
    <style>
        /* ── Sidebar branding elements ── */
        .pharmacy-sidebar-logo {
            max-width    : 80px;
            max-height   : 60px;
            object-fit   : contain;
            margin-bottom: 8px;
        }
        .pharmacy-name {
            font-size     : 1rem;
            font-weight   : 700;
            color         : var(--pharmacy-sidebar-text, #fff);
            display       : block;
            text-overflow : ellipsis;
            overflow      : hidden;
            white-space   : nowrap;
            max-width     : 200px;
        }
        .pharmacy-tagline {
            font-size : 0.68rem;
            opacity   : 0.65;
            color     : var(--pharmacy-sidebar-text, #fff);
            display   : block;
        }

        /* ── User avatar circle (navbar + sidebar) ── */
        .user-avatar-circle {
            width          : 36px;
            height         : 36px;
            border-radius  : 50%;
            display        : inline-flex;
            align-items    : center;
            justify-content: center;
            font-weight    : 700;
            font-size      : 0.85rem;
            color          : #fff;
            flex-shrink    : 0;
        }

        /* ── Page wrapper push — uses sidebar CSS vars ── */
        .main-content {
            margin-left: var(--sb-w, 260px);
            min-height : 100vh;
            transition : margin-left .25s ease;
        }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0 !important; }
        }

        /* ── Page loading indicator — uses theme engine primary color ── */
        #page-loader {
            position       : fixed;
            top            : 0;
            left           : 0;
            width          : 0;
            height         : 3px;
            background     : var(--primary-color, var(--pharmacy-primary, #0d6efd));
            z-index        : 99999;
            transition     : width 0.3s ease, opacity 0.4s ease;
            pointer-events : none;
        }
        #page-loader.loading  { width: 80%; }
        #page-loader.complete { width: 100%; opacity: 0; }

        /* ── NoScript warning bar — semantic amber, not a theme color ── */
        .noscript-warning {
            display       : none; /* shown via <noscript> CSS above */
            background    : #fff3cd;
            color         : #856404;
            text-align    : center;
            padding       : 8px 16px;
            font-size     : 0.85rem;
            border-bottom : 1px solid #ffc107;
            position      : sticky;
            top           : 0;
            z-index       : 9998;
        }
    </style>

    <!-- ══════════════════════════════════════════════
         DYNAMIC FULL THEME CSS (Step 3)
         generateDynamicCSS() outputs:
           • All --pharmacy-* CSS variables (dark mode aware)
           • body/card/table/form/modal/navbar overrides
           • Font family + font size
           • Dark mode color resolution
         Placed AFTER static vars so it takes precedence.
         ══════════════════════════════════════════════ -->
    <?php
    if (function_exists('generateDynamicCSS')) {
        echo generateDynamicCSS();
    }
    ?>

    <!-- ══════════════════════════════════════════════
         PART 1: THEME ENGINE — injects :root CSS vars:
           --primary-color, --secondary-color,
           --sidebar-color, --sidebar-text, --sidebar-active,
           --accent-color, --danger-color, --warning-color,
           --info-color, --body-bg, --card-bg, --text-color,
           --pharmacy-primary, --pharmacy-sidebar, --pharmacy-accent
         Loaded per-user from user_settings / settings table.
         Placed AFTER generateDynamicCSS() so theme engine vars
         take final precedence over any static defaults above.

         FIX: themeEngineBootstrap() and themeEngineInjectVars()
         do not exist. The correct call is:
           themeEngine()->renderCssVars()
         themeEngine() both bootstraps the singleton on first call
         AND returns it — no separate bootstrap step is needed.
         THEME_ENGINE_NO_AUTO_APPLY was defined above (before the
         require_once) so the engine file did not self-render on
         load; we control the injection point here.
         ══════════════════════════════════════════════ -->
    <?php
    if (defined('THEME_ENGINE_LOADED')) {
        // themeEngine() instantiates the singleton (reads session / DB for active theme)
        // renderCssVars() outputs: <style id="theme-engine-vars"> :root { ... } </style>
        themeEngine()->renderCssVars();
    }
    ?>

    <!-- Page loader animation — runs before any JS bundle loads -->
    <script>
        // Show loader immediately on page unload (navigation away)
        window.addEventListener('beforeunload', function () {
            var loader = document.getElementById('page-loader');
            if (loader) {
                loader.style.width   = '80%';
                loader.style.opacity = '1';
            }
        });
    </script>

</head>
<?php
// ── Dark mode body class ─────────────────────────────────────────────────────
// Read directly from $_settings (already loaded above) — no extra DB call.
$_darkModeClass = !empty($_settings['dark_mode']) ? ' dark-mode' : '';
?>
<body class="bg-light<?php echo $_darkModeClass; ?>">

<!-- Page loader bar — must be first visible element in <body> -->
<div id="page-loader"></div>

<!-- NoScript warning — visible only when JS is disabled -->
<div class="noscript-warning">
    <i class="fas fa-exclamation-triangle me-1"></i>
    JavaScript is disabled. Some features of <?php echo htmlspecialchars($_pharmacyName); ?> may not work correctly.
    Please enable JavaScript for the best experience.
</div>