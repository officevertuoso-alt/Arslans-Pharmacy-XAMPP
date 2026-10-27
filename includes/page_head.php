<?php
/**
 * includes/page_head.php
 * ═══════════════════════════════════════════════════════════════
 * CENTRALIZED PAGE HEAD — Single Source of Truth
 * Include this in every page's <head> section.
 * Outputs: <title>, favicon <link>, dynamic CSS vars, and
 *          window.PHARMACY_CONFIG JS object for all scripts.
 * ═══════════════════════════════════════════════════════════════
 */

// Guard: only load once per request
if (defined('PAGE_HEAD_LOADED')) return;
define('PAGE_HEAD_LOADED', true);

// Ensure settings functions are available
if (!function_exists('getCompanySettings')) {
    $_sf = dirname(__DIR__) . '/includes/settings_functions.php';
    if (file_exists($_sf)) require_once $_sf;
}

// Load settings (cached per request)
$_cs = function_exists('getCompanySettings') ? getCompanySettings() : [];

// ── Resolve values ──────────────────────────────────────────
$_pharmName    = trim($_cs['company_name'] ?? $_cs['pharmacy_name'] ?? 'Pharmacy');
$_pharmTagline = trim($_cs['pharmacy_tagline'] ?? 'Your Health, Our Priority');
$_pharmAddress = trim($_cs['address'] ?? '');
$_pharmPhone   = trim($_cs['phone'] ?? '');
$_pharmEmail   = trim($_cs['email'] ?? '');
$_pharmGST     = trim($_cs['gst_no'] ?? '');
$_primaryColor = preg_match('/^#[0-9a-fA-F]{6}$/', $_cs['primary_color'] ?? '') ? $_cs['primary_color'] : '#0d6efd';
$_sidebarColor = preg_match('/^#[0-9a-fA-F]{6}$/', $_cs['sidebar_color'] ?? '') ? $_cs['sidebar_color'] : '#212529';
$_accentColor  = preg_match('/^#[0-9a-fA-F]{6}$/', $_cs['accent_color']  ?? '') ? $_cs['accent_color']  : '#198754';
$_currency     = $_cs['currency_symbol'] ?? 'Rs';
$_invPrefix    = $_cs['invoice_prefix']  ?? 'INV';
$_paperSize    = $_cs['receipt_paper_size'] ?? 'A4';
$_footerText   = $_cs['invoice_footer_text'] ?? ($_cs['footer_text'] ?? 'Thank you for shopping with us!');
$_invTerms     = $_cs['invoice_terms'] ?? '';
$_showLogo     = !empty($_cs['show_logo_on_invoice'])   ? 'true' : 'false';
$_showTagline  = !empty($_cs['show_tagline_on_invoice']) ? 'true' : 'false';
$_showPayment  = !empty($_cs['show_payment_on_invoice']) ? 'true' : 'false';
$_showBatch    = !empty($_cs['show_batch_on_invoice'])   ? 'true' : 'false';

// ── Extended Theme Variables (Theme Editor v3) ───────────────
$_hx = function(string $v, string $d): string {
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $d;
};
$_bodyBg       = $_hx($_cs['body_bg_color']      ?? '', '#f0f2f5');
$_cardBg       = $_hx($_cs['card_bg_color']      ?? '', '#ffffff');
$_bodyText     = $_hx($_cs['body_text_color']    ?? '', '#444444');
$_headingColor = $_hx($_cs['heading_color']      ?? '', '#1a1d23');
$_navbarBg     = $_hx($_cs['navbar_bg_color']    ?? '', '#ffffff');
$_navbarBorder = $_hx($_cs['navbar_border_color']?? '', '#e9ecef');
$_sidebarText  = $_hx($_cs['sidebar_text_color'] ?? '', '#a0aec0');
$_linkColor    = $_hx($_cs['link_color']         ?? '', $_primaryColor);
$_borderColor  = $_hx($_cs['border_color']       ?? '', '#dee2e6');
$_tableHeader  = $_hx($_cs['table_header_color'] ?? '', '#f8f9fa');
$_tableStripe  = $_hx($_cs['table_stripe_color'] ?? '', '#f9f9ff');
$_inputBg      = $_hx($_cs['input_bg_color']     ?? '', '#ffffff');
$_inputBorder  = $_hx($_cs['input_border_color'] ?? '', '#dee2e6');
$_inputText    = $_hx($_cs['input_text_color']   ?? '', '#444444');
$_inputFocus   = $_hx($_cs['input_focus_color']  ?? '', $_accentColor);
$_modalHeader  = $_hx($_cs['modal_header_color'] ?? '', $_primaryColor);
$_modalBody    = $_hx($_cs['modal_body_bg']      ?? '', '#ffffff');
$_dropdownBg   = $_hx($_cs['dropdown_bg']        ?? '', '#ffffff');
$_dropdownText = $_hx($_cs['dropdown_text']      ?? '', '#444444');
$_bInstBg      = $_hx($_cs['badge_instock_bg']   ?? '', '#d1fae5');
$_bInstText    = $_hx($_cs['badge_instock_text']  ?? '', '#065f46');
$_bOosBg       = $_hx($_cs['badge_oos_bg']       ?? '', '#fee2e2');
$_bOosText     = $_hx($_cs['badge_oos_text']     ?? '', '#991b1b');
$_bExpBg       = $_hx($_cs['badge_expiry_bg']    ?? '', '#fff3cd');
$_bExpText     = $_hx($_cs['badge_expiry_text']  ?? '', '#856404');
$_bLowBg       = $_hx($_cs['badge_lowstock_bg']  ?? '', '#fff7e6');
$_bLowText     = $_hx($_cs['badge_lowstock_text'] ?? '', '#c2410c');
$_bExpiredBg   = $_hx($_cs['badge_expired_bg']   ?? '', '#fce7f3');
$_bExpiredText = $_hx($_cs['badge_expired_text'] ?? '', '#9d174d');
$_fontFamily   = htmlspecialchars(strip_tags($_cs['body_font_family'] ?? 'Segoe UI'), ENT_QUOTES);
$_fontSize     = max(11, min(22, (int)($_cs['body_font_size']     ?? 14)));
$_cardRadius   = max(0,  min(32, (int)($_cs['card_border_radius'] ?? 12)));
$_btnRadius    = max(0,  min(32, (int)($_cs['btn_border_radius']  ?? 8)));
$_shadow       = max(0,  min(40, (int)($_cs['shadow_intensity']   ?? 12)));
$_shadowAlpha  = number_format(min($_shadow / 40, 0.25), 3);
$_shadowPx     = (int)($_shadow * 0.8 + 4);
$_darkMode     = !empty($_cs['dark_mode']);

// ── Computed hover variants ──────────────────────────────────
if (!function_exists('_te3Darken')) {
function _te3Darken(string $hex, int $pct): string {
    $n = hexdec(ltrim($hex, '#'));
    $r = max(0, (int)(($n >> 16 & 255) * (1 - $pct / 100)));
    $g = max(0, (int)(($n >> 8  & 255) * (1 - $pct / 100)));
    $b = max(0, (int)(($n       & 255) * (1 - $pct / 100)));
    return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT)
               . str_pad(dechex($g), 2, '0', STR_PAD_LEFT)
               . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
}
}
$_primaryHover = _te3Darken($_primaryColor, 12);
$_accentHover  = _te3Darken($_accentColor, 12);

// ── Logo URL with cache-busting ─────────────────────────────
$_logoUrl = '';
if (!empty($_cs['logo_path'])) {
    $_base     = defined('BASE_URL') ? BASE_URL : '/';
    $_logoFull = (defined('ROOT_PATH') ? ROOT_PATH : $_SERVER['DOCUMENT_ROOT'] . '/') . ltrim($_cs['logo_path'], '/');
    $_logoCB   = file_exists($_logoFull) ? filemtime($_logoFull) : time();
    $_logoUrl  = rtrim($_base, '/') . '/' . ltrim($_cs['logo_path'], '/') . '?v=' . $_logoCB;
}

// ── Favicon URL with cache-busting ──────────────────────────
$_favUrl = '';
if (!empty($_cs['favicon_path'])) {
    $_base     = defined('BASE_URL') ? BASE_URL : '/';
    $_favFull  = (defined('ROOT_PATH') ? ROOT_PATH : $_SERVER['DOCUMENT_ROOT'] . '/') . ltrim($_cs['favicon_path'], '/');
    $_favCB    = file_exists($_favFull) ? filemtime($_favFull) : time();
    $_favUrl   = rtrim($_base, '/') . '/' . ltrim($_cs['favicon_path'], '/') . '?v=' . $_favCB;
}

// ── Page title: prefer $pageTitle set by the page, fallback to company name ──
$_pageTitle = '';
if (!empty($pageTitle)) {
    $_pageTitle = $pageTitle . ' — ' . ($_pharmName ?: 'Pharmacy');
} elseif (!empty($page_title)) {
    $_pageTitle = $page_title;
} else {
    $_pageTitle = ($_pharmName ?: 'Pharmacy') . ' Management System';
}
?>
    <title><?= htmlspecialchars($_pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="application-name" content="<?= htmlspecialchars($_pharmName, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="<?= htmlspecialchars($_primaryColor, ENT_QUOTES, 'UTF-8') ?>">

<?php if (!empty($_favUrl)): ?>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($_favUrl, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars($_favUrl, ENT_QUOTES, 'UTF-8') ?>">
<?php else: ?>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars((defined('BASE_URL') ? BASE_URL : '/') . 'assets/img/favicon.ico', ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>

    <!-- ═══ Dynamic Theme CSS Variables ═══ -->
    <style id="pharmacy-theme-vars">
        :root {
            /* ── Core ── */
            --pharmacy-primary:        <?= $_primaryColor ?>;
            --pharmacy-primary-hover:  <?= $_primaryHover ?>;
            --pharmacy-accent:         <?= $_accentColor ?>;
            --pharmacy-accent-hover:   <?= $_accentHover ?>;
            --pharmacy-sidebar:        <?= $_sidebarColor ?>;
            --pharmacy-sidebar-text:   rgba(255,255,255,0.85);
            /* ── Page & Cards ── */
            --pharmacy-body-bg:        <?= $_bodyBg ?>;
            --pharmacy-card-bg:        <?= $_cardBg ?>;
            --pharmacy-text:           <?= $_bodyText ?>;
            --pharmacy-heading:        <?= $_headingColor ?>;
            /* ── Navbar ── */
            --pharmacy-navbar-bg:      <?= $_navbarBg ?>;
            --pharmacy-navbar-border:  <?= $_navbarBorder ?>;
            /* ── Borders & Links ── */
            --pharmacy-border:         <?= $_borderColor ?>;
            /* ── Tables ── */
            --pharmacy-table-th:       <?= $_tableHeader ?>;
            --pharmacy-table-stripe:   <?= $_tableStripe ?>;
            /* ── Inputs ── */
            --pharmacy-input-bg:       <?= $_inputBg ?>;
            --pharmacy-input-border:   <?= $_inputBorder ?>;
            --pharmacy-input-text:     <?= $_inputText ?>;
            /* ── Modals ── */
            --pharmacy-modal-bg:       <?= $_modalBody ?>;
            /* ── Dropdowns ── */
            --pharmacy-dropdown-bg:    <?= $_dropdownBg ?>;
            --pharmacy-dropdown-text:  <?= $_dropdownText ?>;
            /* ── Badges ── */
            --pharmacy-badge-instock-bg:    <?= $_bInstBg ?>;
            --pharmacy-badge-instock-text:  <?= $_bInstText ?>;
            --pharmacy-badge-oos-bg:        <?= $_bOosBg ?>;
            --pharmacy-badge-oos-text:      <?= $_bOosText ?>;
            --pharmacy-badge-expiry-bg:     <?= $_bExpBg ?>;
            --pharmacy-badge-expiry-text:   <?= $_bExpText ?>;
            --pharmacy-badge-lowstock-bg:   <?= $_bLowBg ?>;
            --pharmacy-badge-lowstock-text: <?= $_bLowText ?>;
            --pharmacy-badge-expired-bg:    <?= $_bExpiredBg ?>;
            --pharmacy-badge-expired-text:  <?= $_bExpiredText ?>;
            /* ── Typography ── */
            --font-family:             '<?= $_fontFamily ?>', system-ui, sans-serif;
            --font-size-base:          <?= $_fontSize ?>px;
            /* ── Shape ── */
            --card-border-radius:      <?= $_cardRadius ?>px;
            --btn-border-radius:       <?= $_btnRadius ?>px;
            --pharmacy-shadow:         0 2px <?= $_shadowPx ?>px rgba(0,0,0,<?= $_shadowAlpha ?>);
            /* ── Sidebar width ── */
            --sb-w:                    260px;
        }
        /* ── Typography ── */
        body {
            font-family: '<?= $_fontFamily ?>', system-ui, sans-serif !important;
            font-size:   <?= $_fontSize ?>px !important;
            background:  <?= $_bodyBg ?> !important;
            color:       <?= $_bodyText ?> !important;
        }
        /* ── Primary ── */
        .btn-primary  { background-color: <?= $_primaryColor ?> !important; border-color: <?= $_primaryColor ?> !important; color:#fff !important; }
        .btn-primary:hover { background-color: <?= $_primaryHover ?> !important; border-color: <?= $_primaryHover ?> !important; }
        .bg-primary   { background-color: <?= $_primaryColor ?> !important; }
        .text-primary { color: <?= $_primaryColor ?> !important; }
        .border-primary { border-color: <?= $_primaryColor ?> !important; }
        /* ── Accent/Success ── */
        .btn-success, .badge.bg-success { background-color: <?= $_accentColor ?> !important; border-color: <?= $_accentColor ?> !important; color:#fff !important; }
        .btn-success:hover { background-color: <?= $_accentHover ?> !important; border-color: <?= $_accentHover ?> !important; }
        .bg-success   { background-color: <?= $_accentColor ?> !important; }
        /* ── Buttons ── */
        .btn { border-radius: <?= $_btnRadius ?>px !important; }
        /* ── Cards ── */
        .card { border-radius: <?= $_cardRadius ?>px !important; background: <?= $_cardBg ?> !important; box-shadow: 0 2px <?= $_shadowPx ?>px rgba(0,0,0,<?= $_shadowAlpha ?>) !important; }
        .card-header { background: <?= $_tableHeader ?> !important; border-color: <?= $_borderColor ?> !important; }
        .card-body, .card-footer { background: <?= $_cardBg ?> !important; }
        /* ── Sidebar ── */
        #sidebar, .sidebar, #mainSidebar { background-color: var(--sidebar-color, <?= $_sidebarColor ?>) !important; }
        /* ── Navbar ── */
        .top-navbar { background: <?= $_navbarBg ?> !important; border-bottom-color: <?= $_navbarBorder ?> !important; }
        .page-breadcrumb { background: <?= $_cardBg ?> !important; border-color: <?= $_borderColor ?> !important; }
        /* ── Headings ── */
        h1,h2,h3,h4,h5,h6,.card-title,.section-title { color: <?= $_headingColor ?> !important; }
        /* ── Tables ── */
        .table thead th, .table > thead > tr > th { background: <?= $_tableHeader ?> !important; border-color: <?= $_borderColor ?> !important; }
        .table tbody td, .table > tbody > tr > td  { color: <?= $_bodyText ?> !important; border-color: <?= $_borderColor ?> !important; }
        .table, table { border-color: <?= $_borderColor ?> !important; }
        .table-striped tbody tr:nth-of-type(even) td { background: <?= $_tableStripe ?> !important; }
        /* ── Inputs ── */
        .form-control, .form-select {
            background: <?= $_inputBg ?> !important;
            border-color: <?= $_inputBorder ?> !important;
            color: <?= $_inputText ?> !important;
        }
        .form-control:focus, .form-select:focus {
            border-color: <?= $_inputFocus ?> !important;
            box-shadow: 0 0 0 3px <?= $_inputFocus ?>33 !important;
        }
        .input-group-text { background: <?= $_inputBg ?> !important; border-color: <?= $_inputBorder ?> !important; color: <?= $_inputText ?> !important; }
        /* ── Modals ── */
        .modal-header { background: linear-gradient(135deg, <?= $_accentColor ?>, <?= $_primaryColor ?>) !important; }
        .modal-content, .modal-body { background: <?= $_modalBody ?> !important; color: <?= $_bodyText ?> !important; }
        .modal-footer { background: <?= $_cardBg ?> !important; border-color: <?= $_borderColor ?> !important; }
        /* ── Dropdowns ── */
        .dropdown-menu { background: <?= $_dropdownBg ?> !important; border-color: <?= $_borderColor ?> !important; }
        .dropdown-item { color: <?= $_dropdownText ?> !important; }
        /* ── Links ── */
        a:not(.btn):not(.nav-link):not(.dropdown-item):not(.page-link):not(.sb-link) { color: <?= $_linkColor ?>; }
        .page-breadcrumb a { color: <?= $_linkColor ?> !important; }
        /* ── Badges ── */
        .badge-instock, .badge.stock-in   { background: <?= $_bInstBg ?>  !important; color: <?= $_bInstText ?>  !important; }
        .badge-oos, .badge.stock-out       { background: <?= $_bOosBg ?>   !important; color: <?= $_bOosText ?>   !important; }
        .badge-expiry, .badge.near-expiry  { background: <?= $_bExpBg ?>   !important; color: <?= $_bExpText ?>   !important; }
        .badge-lowstock, .badge.low-stock  { background: <?= $_bLowBg ?>   !important; color: <?= $_bLowText ?>   !important; }
        .badge-expired, .badge.expired     { background: <?= $_bExpiredBg ?>!important; color: <?= $_bExpiredText ?>!important; }
        /* ── Pagination ── */
        .page-link { background: <?= $_cardBg ?> !important; border-color: <?= $_borderColor ?> !important; color: <?= $_primaryColor ?> !important; }
        .page-item.active .page-link { background: <?= $_primaryColor ?> !important; border-color: <?= $_primaryColor ?> !important; color: #fff !important; }
        /* ── Nav Tabs ── */
        .nav-tabs { border-color: <?= $_borderColor ?> !important; }
        .nav-tabs .nav-link.active { color: <?= $_primaryColor ?> !important; border-color: <?= $_borderColor ?> <?= $_borderColor ?> <?= $_cardBg ?> !important; }
        /* ── List Group ── */
        .list-group-item { background: <?= $_cardBg ?> !important; border-color: <?= $_borderColor ?> !important; color: <?= $_bodyText ?> !important; }
        /* ── Navbar brand ── */
        .navbar-brand-name { color: var(--pharmacy-sidebar-text, var(--text-color, #fff)) !important; }
        <?php if ($_darkMode): ?>
        /* ── Dark Mode Overrides ── */
        body { background: <?= $_hx($_cs['dark_bg_color']   ?? '', '#0f1117') ?> !important; color: <?= $_hx($_cs['dark_text_color'] ?? '', '#e6edf3') ?> !important; }
        .card, .card-body { background: <?= $_hx($_cs['dark_card_color']  ?? '', '#1c2128') ?> !important; }
        #mainSidebar { background: <?= $_hx($_cs['dark_sidebar_color'] ?? '', '#161b22') ?> !important; }
        <?php endif; ?>
    </style>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- PharmaCare Global Stylesheet -->
    <link rel="stylesheet" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>css/style.css">
<link rel="stylesheet" href="<?php echo defined('CSS_URL') ? CSS_URL : BASE_URL . 'css/'; ?>ui_consistency.css">
    <!-- Phase 9: Universal Table System -->
    <link rel="stylesheet" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>css/universal_table.css">
    <!-- Core JS (loaded once via page_head, defer to not block render) -->
    <?php if (!defined('CORE_JS_LOADED')): define('CORE_JS_LOADED', true); ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>js/script.js"></script>
    <script src="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>js/notify.js"></script>
    <script src="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>js/theme-picker.js"></script>
    <script>window.BASE_URL = window.BASE_URL || "<?php echo addslashes(defined('BASE_URL')?BASE_URL:'/'); ?>";</script>
    <?php endif; ?>

    <!-- ═══ Global Company Config for JavaScript ═══ -->
    <script>
    window.PHARMACY_CONFIG = {
        name:           <?= json_encode($_pharmName) ?>,
        tagline:        <?= json_encode($_pharmTagline) ?>,
        address:        <?= json_encode($_pharmAddress) ?>,
        phone:          <?= json_encode($_pharmPhone) ?>,
        email:          <?= json_encode($_pharmEmail) ?>,
        gst_no:         <?= json_encode($_pharmGST) ?>,
        logo_url:       <?= json_encode($_logoUrl) ?>,
        favicon_url:    <?= json_encode($_favUrl) ?>,
        primary_color:  <?= json_encode($_primaryColor) ?>,
        sidebar_color:  <?= json_encode($_sidebarColor) ?>,
        accent_color:   <?= json_encode($_accentColor) ?>,
        currency:       <?= json_encode($_currency) ?>,
        invoice_prefix: <?= json_encode($_invPrefix) ?>,
        paper_size:     <?= json_encode($_paperSize) ?>,
        footer_text:    <?= json_encode($_footerText) ?>,
        invoice_terms:  <?= json_encode($_invTerms) ?>,
        show_logo:      <?= $_showLogo ?>,
        show_tagline:   <?= $_showTagline ?>,
        show_payment:   <?= $_showPayment ?>,
        show_batch:     <?= $_showBatch ?>
    };

    // ── STEP 14: User Permission Object ──
    window.PHARMACY_PERMS = {
        role:    <?= json_encode($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') ?>,
        isAdmin: <?= (function_exists('isAdmin') && isAdmin()) ? 'true' : 'false' ?>,
        modules: <?= json_encode($_SESSION['permissions'] ?? []) ?>
    };
    </script>