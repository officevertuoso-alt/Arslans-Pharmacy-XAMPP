<?php
/**
 * === FILE: pharmacy_software/includes/topbar.php ===
 *
 * GLOBAL HEADER — Single Source of Truth (Phase 3)
 * ────────────────────────────────────────────────────────────────
 * Contains: Logo · System Name · Global Search · Notifications
 *           Theme Placeholder · Profile Menu · Logout
 *           Breadcrumb Bar · Mobile Sidebar Toggle
 *
 * Replaces the old navbar.php (which now simply includes this file).
 * Do NOT modify navbar.php — edit this file instead.
 *
 * Dependencies (must be loaded before this file):
 *   • page_head.php or header.php — CSS vars, Bootstrap, jQuery
 *   • sidebar.php                 — defines --sb-w, --sb-w-mini
 *   • includes/auth.php           — session, canDo()
 *
 * Version: 1.0 — Phase 3 Global Header
 */

// Guard: only render once per request
if (defined('TOPBAR_LOADED')) return;
define('TOPBAR_LOADED', true);

// ═══════════════════════════════════════════════════════════════════════════════
// BREADCRUMB ENGINE
// ═══════════════════════════════════════════════════════════════════════════════
if (!function_exists('generateBreadcrumb')) {
function generateBreadcrumb(): string {
    $dir  = basename(dirname($_SERVER['PHP_SELF']));
    $page = basename($_SERVER['PHP_SELF'], '.php');
    $base = defined('BASE_URL') ? BASE_URL : '/';

    $fullMap = [
        '.' => [
            'dashboard' => ['label' => 'Dashboard', 'section' => null, 'section_url' => null],
        ],
        'products' => [
            'list'           => ['label' => 'Product List',    'section' => 'Products',  'section_url' => 'products/list.php'],
            'add'            => ['label' => 'Add Product',     'section' => 'Products',  'section_url' => 'products/list.php'],
            'edit'           => ['label' => 'Edit Product',    'section' => 'Products',  'section_url' => 'products/list.php'],
            'view'           => ['label' => 'View Product',    'section' => 'Products',  'section_url' => 'products/list.php'],
            'bulk_import'    => ['label' => 'Bulk Import',     'section' => 'Products',  'section_url' => 'products/list.php'],
            'trash_products' => ['label' => 'Trash Items',     'section' => 'Products',  'section_url' => 'products/list.php'],
            'add_purchase'   => ['label' => 'Add Purchase',    'section' => 'Products',  'section_url' => 'products/list.php'],
        ],
        'sales' => [
            'pos'           => ['label' => 'POS / New Sale',    'section' => 'Sales',     'section_url' => 'sales/list.php'],
            'list'          => ['label' => 'Sales List',         'section' => 'Sales',     'section_url' => 'sales/list.php'],
            'view'          => ['label' => 'Sale Details',       'section' => 'Sales',     'section_url' => 'sales/list.php'],
            'sale_view'     => ['label' => 'Sale View',          'section' => 'Sales',     'section_url' => 'sales/list.php'],
            'customers'     => ['label' => 'Customers',          'section' => 'Sales',     'section_url' => 'sales/list.php'],
            'customer_view' => ['label' => 'Customer Details',   'section' => 'Sales',     'section_url' => 'sales/list.php'],
            'invoice'       => ['label' => 'Invoice',            'section' => 'Sales',     'section_url' => 'sales/list.php'],
        ],
        'purchases' => [
            'list' => ['label' => 'Purchase List',    'section' => 'Purchases', 'section_url' => 'purchases/list.php'],
            'add'  => ['label' => 'Add Purchase',     'section' => 'Purchases', 'section_url' => 'purchases/list.php'],
            'view' => ['label' => 'Purchase Details', 'section' => 'Purchases', 'section_url' => 'purchases/list.php'],
        ],
        'returns' => [
            'sale_return'          => ['label' => 'Sale Return',             'section' => 'Returns', 'section_url' => 'returns/sale_return_list.php'],
            'sale_return_list'     => ['label' => 'Sale Return List',        'section' => 'Returns', 'section_url' => 'returns/sale_return_list.php'],
            'sale_return_view'     => ['label' => 'Sale Return Details',     'section' => 'Returns', 'section_url' => 'returns/sale_return_list.php'],
            'purchase_return'      => ['label' => 'Purchase Return',         'section' => 'Returns', 'section_url' => 'returns/sale_return_list.php'],
            'purchase_return_list' => ['label' => 'Purchase Return List',    'section' => 'Returns', 'section_url' => 'returns/sale_return_list.php'],
            'purchase_return_view' => ['label' => 'Purchase Return Details', 'section' => 'Returns', 'section_url' => 'returns/sale_return_list.php'],
        ],
        'suppliers' => [
            'list'      => ['label' => 'Supplier List',    'section' => 'Suppliers', 'section_url' => 'suppliers/list.php'],
            'add'       => ['label' => 'Add Supplier',     'section' => 'Suppliers', 'section_url' => 'suppliers/list.php'],
            'edit'      => ['label' => 'Edit Supplier',    'section' => 'Suppliers', 'section_url' => 'suppliers/list.php'],
            'view'      => ['label' => 'Supplier Details', 'section' => 'Suppliers', 'section_url' => 'suppliers/list.php'],
            'pay'       => ['label' => 'Make Payment',     'section' => 'Suppliers', 'section_url' => 'suppliers/list.php'],
            'statement' => ['label' => 'Statement',        'section' => 'Suppliers', 'section_url' => 'suppliers/list.php'],
        ],
        'credit' => [
            'index' => ['label' => 'Manage Credits', 'section' => 'Credit', 'section_url' => 'credit/list.php'],
            'list'  => ['label' => 'Credit Sales',   'section' => 'Credit', 'section_url' => 'credit/list.php'],
        ],
        'cashbook' => [
            'index'     => ['label' => 'Cashbook',  'section' => 'Cashbook', 'section_url' => 'cashbook/index.php'],
            'add_entry' => ['label' => 'Add Entry', 'section' => 'Cashbook', 'section_url' => 'cashbook/index.php'],
            'report'    => ['label' => 'Report',    'section' => 'Cashbook', 'section_url' => 'cashbook/index.php'],
        ],
        'expenses' => [
            'index' => ['label' => 'View Expenses', 'section' => 'Expenses', 'section_url' => 'expenses/index.php'],
            'add'   => ['label' => 'Add Expense',   'section' => 'Expenses', 'section_url' => 'expenses/index.php'],
        ],
        'reports' => [
            'index'              => ['label' => 'Overview',         'section' => 'Reports', 'section_url' => 'reports/index.php'],
            'sales_report'       => ['label' => 'Sales Report',     'section' => 'Reports', 'section_url' => 'reports/index.php'],
            'purchase_report'    => ['label' => 'Purchase Report',  'section' => 'Reports', 'section_url' => 'reports/index.php'],
            'stock_report'       => ['label' => 'Stock Report',     'section' => 'Reports', 'section_url' => 'reports/index.php'],
            'expiry_report'      => ['label' => 'Expiry Report',    'section' => 'Reports', 'section_url' => 'reports/index.php'],
            'profit_loss_report' => ['label' => 'Profit & Loss',    'section' => 'Reports', 'section_url' => 'reports/index.php'],
            'customer_report'    => ['label' => 'Customer Report',  'section' => 'Reports', 'section_url' => 'reports/index.php'],
            'supplier_report'    => ['label' => 'Supplier Report',  'section' => 'Reports', 'section_url' => 'reports/index.php'],
        ],
        'users' => [
            'list' => ['label' => 'User List', 'section' => 'Users', 'section_url' => 'users/list.php'],
            'add'  => ['label' => 'Add User',  'section' => 'Users', 'section_url' => 'users/list.php'],
            'edit' => ['label' => 'Edit User', 'section' => 'Users', 'section_url' => 'users/list.php'],
        ],
        'settings' => [
            'company'         => ['label' => 'Pharmacy Info',    'section' => 'Settings', 'section_url' => 'settings/company.php'],
            'theme_editor_v2' => ['label' => 'Theme Editor',     'section' => 'Settings', 'section_url' => 'settings/company.php'],
            'backup'          => ['label' => 'Backup & Restore', 'section' => 'Settings', 'section_url' => 'settings/company.php'],
            'change_password' => ['label' => 'Change Password',  'section' => 'Settings', 'section_url' => 'settings/company.php'],
            'preferences'     => ['label' => 'Preferences',      'section' => 'Settings', 'section_url' => 'settings/company.php'],
            'profile'         => ['label' => 'My Profile',       'section' => 'Settings', 'section_url' => 'settings/company.php'],
            'factory_reset'   => ['label' => 'Factory Reset',    'section' => 'Settings', 'section_url' => 'settings/company.php'],
        ],
        'logs' => [
            'activity' => ['label' => 'Activity Log', 'section' => 'Logs', 'section_url' => 'logs/activity.php'],
        ],
        'stock' => [
            'index'  => ['label' => 'Stock Overview', 'section' => 'Stock', 'section_url' => 'stock/index.php'],
            'adjust' => ['label' => 'Adjust Stock',   'section' => 'Stock', 'section_url' => 'stock/index.php'],
        ],
        'backup' => [
            'index'   => ['label' => 'Backup & Restore', 'section' => 'Settings', 'section_url' => 'settings/company.php'],
            'restore' => ['label' => 'Restore',          'section' => 'Settings', 'section_url' => 'settings/company.php'],
        ],
    ];

    $entry        = $fullMap[$dir][$page] ?? null;
    $currentLabel = null;
    $sectionLabel = null;
    $sectionUrl   = null;

    if ($entry) {
        $currentLabel = $entry['label'];
        $sectionLabel = $entry['section'];
        $sectionUrl   = $entry['section_url'] ? $base . $entry['section_url'] : null;
    } else {
        $currentLabel = ucwords(str_replace('_', ' ', $page));
        $knownSections = [
            'products' => 'Products', 'sales' => 'Sales', 'purchases' => 'Purchases',
            'returns' => 'Returns', 'suppliers' => 'Suppliers', 'credit' => 'Credit',
            'cashbook' => 'Cashbook', 'expenses' => 'Expenses', 'reports' => 'Reports',
            'users' => 'Users', 'settings' => 'Settings', 'logs' => 'Logs',
            'stock' => 'Stock', 'backup' => 'Backup',
        ];
        if ($dir !== '.' && isset($knownSections[$dir])) {
            $sectionLabel = $knownSections[$dir];
        }
    }

    $parts = [];
    $isDashboard = ($page === 'dashboard' && ($dir === '.' || $dir === basename(dirname($base))));

    $parts[] = '<a href="' . htmlspecialchars($base . 'dashboard.php') . '" class="bc-crumb" title="Dashboard">'
             . '<i class="fas fa-home"></i>'
             . '<span>Dashboard</span>'
             . '</a>';

    if (!$isDashboard && $sectionLabel !== null) {
        $secHref = $sectionUrl ?? ($base . strtolower($sectionLabel) . '/index.php');
        $parts[] = '<a href="' . htmlspecialchars($secHref) . '" class="bc-crumb">'
                 . htmlspecialchars($sectionLabel)
                 . '</a>';
    }

    if (!$isDashboard && $currentLabel !== null) {
        $parts[] = '<span class="current" title="' . htmlspecialchars($currentLabel) . '">'
                 . htmlspecialchars($currentLabel)
                 . '</span>';
    }

    $sep = '<span class="separator" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>';
    return implode($sep, $parts);
}
}

// ── Ensure BASE_URL ────────────────────────────────────────────────────────────
if (!defined('BASE_URL')) {
    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $path       = rtrim(dirname($scriptName), '/\\');
    if (basename($path) === 'includes') $path = dirname($path);
    define('BASE_URL', $protocol . '://' . $host . $path . '/');
}

// ── Notification count (session-cached 5 min) ─────────────────────────────────
$_navNotifCount   = 0;
$_navCacheKey     = 'notif_count';
$_navCacheTimeKey = 'notif_cache_time';
$_navCacheTTL     = 300;

if (
    !isset($_SESSION[$_navCacheKey]) ||
    (time() - (int)($_SESSION[$_navCacheTimeKey] ?? 0)) > $_navCacheTTL
) {
    try {
        $pdo = db()->getConnection();
        $__stmtLs = $pdo->query(
            "SELECT COUNT(*) AS cnt
             FROM   products p
             JOIN   (SELECT product_id, SUM(remaining_quantity) AS total_qty
                     FROM   batches
                     WHERE  expiry_date >= CURDATE() AND is_blocked = 0
                     GROUP  BY product_id) b ON b.product_id = p.id
             WHERE  b.total_qty <= p.reorder_level
             AND    p.status = 1"
        );
        $__ls = $__stmtLs ? (int)$__stmtLs->fetchColumn() : 0;

        $__stmtNe = $pdo->query(
            "SELECT COUNT(*) AS cnt
             FROM   batches
             WHERE  expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             AND    remaining_quantity > 0 AND is_blocked = 0"
        );
        $__ne = $__stmtNe ? (int)$__stmtNe->fetchColumn() : 0;

        $_navNotifCount = $__ls + $__ne;
        $_SESSION[$_navCacheKey]     = $_navNotifCount;
        $_SESSION[$_navCacheTimeKey] = time();
    } catch (Throwable $_e) {
        $_navNotifCount = (int)($_SESSION[$_navCacheKey] ?? 0);
    }
} else {
    $_navNotifCount = (int)$_SESSION[$_navCacheKey];
}

// ── User data ─────────────────────────────────────────────────────────────────
$_navUsername  = $_SESSION['user_name']  ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$_navUserRole  = ucfirst($_SESSION['role'] ?? $_SESSION['user_role'] ?? 'guest');
$_navUserEmail = $_SESSION['user_email'] ?? '';

// ── Profile image URL ─────────────────────────────────────────────────────────
$_navProfileImgUrl = '';
$_navHasImg        = false;
if (!empty($_SESSION['user_profile_image_url'])) {
    $_navProfileImgUrl = $_SESSION['user_profile_image_url'];
    $_navHasImg        = true;
} else {
    $_navImgFile = $_SESSION['user_profile_image'] ?? $_SESSION['profile_image'] ?? '';
    if (!empty($_navImgFile)) {
        $_navRoot = defined('ROOT_PATH') ? ROOT_PATH : rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/pharmacy_software/';
        foreach (['uploads/profiles/', 'uploads/avatars/'] as $_navDir) {
            $_navFull = $_navRoot . $_navDir . basename($_navImgFile);
            if (file_exists($_navFull)) {
                $_navCb            = filemtime($_navFull);
                $_navProfileImgUrl = (defined('BASE_URL') ? BASE_URL : '/') . $_navDir . basename($_navImgFile) . '?v=' . $_navCb;
                $_SESSION['user_profile_image_url'] = $_navProfileImgUrl;
                $_navHasImg = true;
                break;
            }
        }
    }
    if (!$_navHasImg && !empty($_SESSION['user_id'])) {
        try {
            if (function_exists('db')) {
                $_navDb = db();
                $_navDb->query("SELECT profile_image FROM users WHERE id = :id LIMIT 1");
                $_navDb->bind(':id', (int)$_SESSION['user_id']);
                $_navDbRow = $_navDb->single();
                if ($_navDbRow) {
                    $_navRoot = defined('ROOT_PATH') ? ROOT_PATH : rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/pharmacy_software/';
                    foreach (array_filter([$_navDbRow['profile_image'] ?? '']) as $_navFname) {
                        foreach (['uploads/profiles/', 'uploads/avatars/'] as $_navDir) {
                            $_navFull = $_navRoot . $_navDir . basename($_navFname);
                            if (file_exists($_navFull)) {
                                $_navCb            = filemtime($_navFull);
                                $_navProfileImgUrl = (defined('BASE_URL') ? BASE_URL : '/') . $_navDir . basename($_navFname) . '?v=' . $_navCb;
                                $_SESSION['user_profile_image']     = $_navFname;
                                $_SESSION['user_profile_image_url'] = $_navProfileImgUrl;
                                $_navHasImg = true;
                                break 2;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $_e) {}
    }
}

// ── Avatar color & initials ────────────────────────────────────────────────────
$_navAvatarColors = ['#e74c3c','#3498db','#2ecc71','#9b59b6','#f39c12','#1abc9c','#e67e22','#e91e63'];
$_navAvatarColor  = $_navAvatarColors[abs(crc32($_navUsername)) % count($_navAvatarColors)];
$_navNameParts    = explode(' ', trim($_navUsername));
$_navInitials     = strtoupper(
    ($_navNameParts[0][0] ?? '') . (isset($_navNameParts[1]) ? $_navNameParts[1][0] : '')
) ?: 'U';

// ── Company settings (logo, name) ─────────────────────────────────────────────
$_navSettings     = $_SESSION['company_settings_cache'] ?? [];
$_navPharmacyName = trim($_navSettings['pharmacy_name'] ?? $_navSettings['company_name'] ?? 'Pharmacy MS') ?: 'Pharmacy MS';
$_navLogoUrl      = '';
if (!empty($_navSettings['logo_path'])) {
    $_navLogoBase = defined('BASE_URL') ? BASE_URL : '/';
    $_navLogoFull = (defined('ROOT_PATH') ? ROOT_PATH : $_SERVER['DOCUMENT_ROOT'] . '/') . ltrim($_navSettings['logo_path'], '/');
    $_navLogoCb   = file_exists($_navLogoFull) ? filemtime($_navLogoFull) : time();
    $_navLogoUrl  = rtrim($_navLogoBase, '/') . '/' . ltrim($_navSettings['logo_path'], '/') . '?v=' . $_navLogoCb;
}

// ── isAdmin helper ─────────────────────────────────────────────────────────────
if (!function_exists('isAdmin')) {
    function isAdmin(): bool {
        return ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '') === 'admin';
    }
}
?>

<!-- ═══════════════════════════════════════════════════════════════════════
     GLOBAL TOPBAR — Phase 3
     Layout: [MobileToggle] [Logo + System Name] [GlobalSearch] [Controls]
     margin-left syncs with sidebar state via JS + CSS class
     ═══════════════════════════════════════════════════════════════════════ -->
<nav class="top-navbar navbar navbar-expand-lg navbar-light px-3"
     id="topNavbar"
     style="margin-left:var(--sb-w,260px);"
     role="banner"
     aria-label="Global navigation">
    <div class="container-fluid gap-2">

        <!-- ── Mobile: sidebar toggle ─────────────────────────────────────── -->
        <button class="btn btn-sm btn-outline-secondary d-lg-none flex-shrink-0"
                id="sidebarToggleBtn"
                type="button"
                aria-label="Toggle sidebar">
            <i class="fas fa-bars"></i>
        </button>

        <!-- ══════════════════════════════════════════════════════════════════
             LOGO + SYSTEM NAME — visible on desktop; hidden on mobile
             (mobile shows the sidebar logo in the drawer instead)
             ══════════════════════════════════════════════════════════════════ -->
        <a href="<?php echo htmlspecialchars(BASE_URL . 'dashboard.php'); ?>"
           class="navbar-brand d-none d-lg-flex align-items-center gap-2 text-decoration-none flex-shrink-0"
           id="topbarBrand"
           title="Go to Dashboard">

            <!-- Logo -->
            <span class="topbar-logo-wrap" id="topbarLogoWrap">
                <?php if (!empty($_navLogoUrl)): ?>
                <img src="<?php echo htmlspecialchars($_navLogoUrl); ?>"
                     alt="<?php echo htmlspecialchars($_navPharmacyName); ?>"
                     class="topbar-logo"
                     id="topbarLogoImg"
                     onerror="this.style.display='none';document.getElementById('topbarLogoFallback').style.display='flex';">
                <span class="topbar-logo-fallback" id="topbarLogoFallback" style="display:none;">
                    <i class="fas fa-hospital-alt"></i>
                </span>
                <?php else: ?>
                <span class="topbar-logo-fallback" id="topbarLogoFallback">
                    <i class="fas fa-hospital-alt"></i>
                </span>
                <?php endif; ?>
            </span>

            <!-- System Name -->
            <span class="topbar-system-name" id="topbarSystemName">
                <?php echo htmlspecialchars(
                    mb_strlen($_navPharmacyName) > 28
                        ? mb_substr($_navPharmacyName, 0, 27) . '…'
                        : $_navPharmacyName
                ); ?>
            </span>
        </a>

        <!-- Mobile: compact pharmacy name (no logo) -->
        <a href="<?php echo htmlspecialchars(BASE_URL . 'dashboard.php'); ?>"
           class="d-lg-none fw-bold text-truncate text-decoration-none"
           style="max-width:150px; color:var(--pharmacy-primary,#0d6efd); font-size:.9rem;">
            <?php echo htmlspecialchars(
                mb_strlen($_navPharmacyName) > 18
                    ? mb_substr($_navPharmacyName, 0, 17) . '…'
                    : $_navPharmacyName
            ); ?>
        </a>

        <!-- ══════════════════════════════════════════════════════════════════
             GLOBAL SEARCH
             Quick-access search bar — searches products, sales, customers
             ══════════════════════════════════════════════════════════════════ -->
        <div class="topbar-search-wrap d-none d-md-flex flex-grow-1 mx-3" id="globalSearchWrap">
            <div class="input-group input-group-sm topbar-search-group" role="search">
                <span class="input-group-text topbar-search-icon" aria-hidden="true">
                    <i class="fas fa-search"></i>
                </span>
                <input type="search"
                       class="form-control topbar-search-input"
                       id="globalSearchInput"
                       placeholder="Search products, sales, customers…"
                       autocomplete="off"
                       aria-label="Global search"
                       aria-autocomplete="list"
                       aria-controls="globalSearchResults"
                       aria-expanded="false">
            </div>
            <!-- Results dropdown -->
            <div class="topbar-search-results" id="globalSearchResults" role="listbox" aria-label="Search results" hidden>
                <div class="topbar-search-empty" id="globalSearchEmpty">
                    <i class="fas fa-search me-1 text-muted"></i>
                    <span>Type to search…</span>
                </div>
            </div>
        </div>

        <!-- ── Right-side controls ────────────────────────────────────────── -->
        <div class="ms-auto d-flex align-items-center gap-3 flex-shrink-0">

            <!-- Date / Time -->
            <div class="text-muted d-none d-md-flex align-items-center gap-1 small">
                <i class="fas fa-calendar-alt"></i>
                <span><?php echo date('d M Y'); ?></span>
                <span class="text-secondary mx-1">|</span>
                <i class="fas fa-clock"></i>
                <span id="navbar-clock"><?php echo date('h:i:s A'); ?></span>
            </div>

            <!-- ── Theme Switcher Dropdown (Phase 13) ────────────────────── -->
            <div class="dropdown" id="themeSwitcherDropdown">
                <button class="btn btn-sm btn-light border topbar-theme-btn"
                        id="topbarThemeToggle"
                        type="button"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        title="Switch theme"
                        aria-label="Switch colour theme">
                    <i class="fas fa-palette" id="topbarThemeIcon"></i>
                </button>

                <div class="dropdown-menu dropdown-menu-end shadow p-0"
                     id="themeDropdownPanel"
                     style="min-width:268px; border-radius:12px; overflow:hidden;">

                    <!-- Panel header -->
                    <div class="d-flex align-items-center justify-content-between px-3 py-2 text-white"
                         style="background:var(--pharmacy-sidebar,#1a1d23);">
                        <span class="fw-semibold small">
                            <i class="fas fa-palette me-1"></i> Choose Theme
                        </span>
                        <span class="badge rounded-pill small" id="themeActiveName"
                              style="background:var(--pharmacy-primary,#0d6efd); font-size:.68rem;">
                            <?php
                            $__teP = BASE_PATH . '/assets/themes/theme.engine.php';
                            if (file_exists($__teP) && !defined('THEME_ENGINE_LOADED')) require_once $__teP;
                            echo defined('THEME_ENGINE_LOADED') ? htmlspecialchars(themeEngine()->getTheme()['name'] ?? 'Default') : 'Default';
                            ?>
                        </span>
                    </div>

                    <!-- Theme swatches -->
                    <div class="px-3 py-2" id="themeSwatchArea">
                        <?php
                        if (defined('THEME_ENGINE_LOADED')) {
                            $__eng      = themeEngine();
                            $__activeId = $__eng->getThemeId();
                            $__grouped  = $__eng->getGrouped();
                            foreach (['dark' => 'Dark Themes', 'light' => 'Light Themes'] as $__grp => $__grpLabel) {
                                if (empty($__grouped[$__grp])) continue;
                                echo '<div class="ts-group-label mb-1">' . $__grpLabel . '</div>';
                                echo '<div class="ts-swatches mb-2">';
                                foreach ($__grouped[$__grp] as $__id => $__th) {
                                    $__active = ($__id === $__activeId) ? ' ts-swatch--active' : '';
                                    $__bg1 = htmlspecialchars($__th['vars']['--sidebar-color']);
                                    $__bg2 = htmlspecialchars($__th['vars']['--primary-color']);
                                    $__name = htmlspecialchars($__th['name']);
                                    echo '<button type="button"'
                                        . ' class="ts-swatch' . $__active . '"'
                                        . ' data-theme-id="' . htmlspecialchars($__id) . '"'
                                        . ' title="' . $__name . '"'
                                        . ' style="background:linear-gradient(135deg,' . $__bg1 . ' 50%,' . $__bg2 . ' 100%);'
                                        . '">'
                                        ;
                                    echo '<span class="ts-swatch-check"><i class="fas fa-check"></i></span>';
                                    echo '<span class="ts-swatch-name">' . $__name . '</span>';
                                    echo '</button>';
                                }
                                echo '</div>';
                            }
                        } else {
                            echo '<p class="text-muted small mb-0">Theme engine unavailable.</p>';
                        }
                        ?>
                        <div id="themeApplyStatus" class="ts-status mt-1" style="display:none;"></div>
                    </div>

                    <!-- Footer link -->
                    <div class="border-top text-center py-2">
                        <a href="<?php echo BASE_URL; ?>settings/company.php#theme"
                           class="small text-decoration-none"
                           style="color:var(--pharmacy-primary,#0d6efd);">
                            <i class="fas fa-sliders-h me-1"></i> Advanced Theme Editor
                        </a>
                    </div>

                </div>
            </div><!-- /themeSwitcherDropdown -->

            <!-- ── Notification Bell ─────────────────────────────────────── -->
            <div class="dropdown" id="notif-dropdown">
                <button class="btn btn-sm btn-light position-relative border"
                        type="button"
                        id="notifBellBtn"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        title="Notifications">
                    <i class="fas fa-bell fs-6"></i>
                    <span id="notif-badge"
                          class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                          style="font-size:.65rem; <?php echo $_navNotifCount === 0 ? 'display:none;' : ''; ?>">
                        <span id="notif-count">
                            <?php echo $_navNotifCount > 99 ? '99+' : $_navNotifCount; ?>
                        </span>
                        <span class="visually-hidden">unread notifications</span>
                    </span>
                </button>

                <div class="dropdown-menu dropdown-menu-end shadow p-0"
                     id="notif-panel"
                     style="min-width:340px; max-width:420px; border-radius:10px; overflow:hidden;">

                    <div class="d-flex align-items-center justify-content-between px-3 py-2 text-white"
                         style="background-color:var(--pharmacy-sidebar,#1a1d23);">
                        <span class="fw-semibold small">
                            <i class="fas fa-bell me-1"></i> Alerts &amp; Notifications
                        </span>
                        <span class="badge rounded-pill small" id="notif-panel-count"
                              style="background-color:var(--pharmacy-primary,#0d6efd);">
                            <?php echo $_navNotifCount; ?>
                        </span>
                    </div>

                    <div id="notif-list" style="max-height:400px; overflow-y:auto;">
                        <div class="text-center py-4 text-muted small">
                            <i class="fas fa-spinner fa-spin me-1"></i> Loading...
                        </div>
                    </div>

                    <div class="border-top text-center py-2 d-flex justify-content-around">
                        <a href="<?php echo BASE_URL; ?>reports/expiry_report.php"
                           class="small text-decoration-none"
                           style="color:var(--pharmacy-primary,#0d6efd);">
                            <i class="fas fa-eye me-1"></i> View All Alerts
                        </a>
                        <a href="#" id="fixCashbookGapsBtn"
                           class="small text-success text-decoration-none">
                            <i class="fas fa-sync-alt me-1"></i> Fix Cashbook Gaps
                        </a>
                    </div>
                </div>
            </div><!-- /notif dropdown -->

            <!-- ── Profile Menu ──────────────────────────────────────────── -->
            <div class="dropdown">
                <a href="#"
                   class="d-flex align-items-center gap-2 text-dark text-decoration-none dropdown-toggle"
                   data-bs-toggle="dropdown"
                   aria-expanded="false"
                   id="userDropdownBtn">

                    <?php if ($_navHasImg): ?>
                    <img src="<?php echo htmlspecialchars($_navProfileImgUrl); ?>"
                         alt="<?php echo htmlspecialchars($_navInitials); ?>"
                         class="rounded-circle border border-2"
                         style="width:36px; height:36px; object-fit:cover;
                                border-color:var(--pharmacy-primary,#0d6efd) !important;"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <span class="user-avatar-circle"
                          style="background-color:<?php echo $_navAvatarColor; ?>;display:none;">
                        <?php echo htmlspecialchars($_navInitials); ?>
                    </span>
                    <?php else: ?>
                    <span class="user-avatar-circle"
                          style="background-color:<?php echo $_navAvatarColor; ?>;">
                        <?php echo htmlspecialchars($_navInitials); ?>
                    </span>
                    <?php endif; ?>

                    <span class="d-none d-sm-inline small fw-semibold text-truncate"
                          style="max-width:120px;">
                        <?php echo htmlspecialchars($_navUsername); ?>
                    </span>
                </a>

                <ul class="dropdown-menu dropdown-menu-end shadow"
                    style="min-width:210px; border-radius:10px; overflow:hidden;">

                    <!-- User header -->
                    <li class="px-3 py-2 border-bottom"
                        style="background:var(--pharmacy-table-th,#f8f9fa);color:var(--pharmacy-text,#444);">
                        <div class="fw-semibold small">
                            <?php echo htmlspecialchars($_navUsername); ?>
                        </div>
                        <?php if (!empty($_navUserEmail)): ?>
                        <div class="text-muted" style="font-size:.74rem;">
                            <?php echo htmlspecialchars($_navUserEmail); ?>
                        </div>
                        <?php endif; ?>
                        <span class="badge mt-1" style="font-size:.68rem;
                              background-color:var(--pharmacy-primary,#0d6efd);">
                            <?php echo htmlspecialchars($_navUserRole); ?>
                        </span>
                    </li>

                    <li>
                        <a class="dropdown-item py-2"
                           href="<?php echo BASE_URL; ?>settings/profile.php">
                            <i class="fas fa-user me-2 fa-fw text-primary"></i> My Profile
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item py-2"
                           href="<?php echo BASE_URL; ?>settings/change_password.php">
                            <i class="fas fa-key me-2 fa-fw text-warning"></i> Change Password
                        </a>
                    </li>
                    <?php if (isAdmin()): ?>
                    <li>
                        <a class="dropdown-item py-2"
                           href="<?php echo BASE_URL; ?>settings/company.php">
                            <i class="fas fa-cog me-2 fa-fw text-secondary"></i> Settings
                        </a>
                    </li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <a class="dropdown-item py-2 text-danger"
                           href="<?php echo BASE_URL; ?>auth/logout.php">
                            <i class="fas fa-sign-out-alt me-2 fa-fw"></i> Logout
                        </a>
                    </li>
                </ul>
            </div><!-- /profile dropdown -->

        </div><!-- /ms-auto -->
    </div><!-- /container-fluid -->
</nav>

<!-- ═══════════════════════════════════════════════════════════════════════
     BREADCRUMB BAR
     Layout: [← Back]  [Home > Section > Current Page]  [Forward →]
     Sticky below navbar; margin synced with sidebar state
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="page-breadcrumb" id="pageBreadcrumbBar">

    <button class="bc-nav-btn" id="bcBackBtn"
            onclick="history.back()"
            title="Go Back"
            aria-label="Go back">
        <i class="fas fa-chevron-left"></i>
    </button>

    <nav aria-label="breadcrumb" class="bc-path-wrap">
        <div class="d-flex align-items-center flex-wrap gap-1">
            <?php echo generateBreadcrumb(); ?>
        </div>
    </nav>

    <button class="bc-nav-btn" id="bcFwdBtn"
            onclick="history.forward()"
            title="Go Forward"
            aria-label="Go forward">
        <i class="fas fa-chevron-right"></i>
    </button>

</div>

<!-- Mobile sidebar backdrop -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TOPBAR STYLES
     ═══════════════════════════════════════════════════════════════════════ -->
<style>
/* ── Topbar brand: logo + system name ──────────────────────────────────── */
.topbar-logo-wrap {
    display         : flex;
    align-items     : center;
    justify-content : center;
    width           : 36px;
    height          : 36px;
    flex-shrink     : 0;
}
.topbar-logo {
    max-width  : 36px;
    max-height : 36px;
    object-fit : contain;
}
.topbar-logo-fallback {
    display         : flex;
    align-items     : center;
    justify-content : center;
    width           : 36px;
    height          : 36px;
    border-radius   : 8px;
    background      : linear-gradient(135deg, var(--pharmacy-primary,#0d6efd), var(--pharmacy-accent,#198754));
    color           : #fff;
    font-size       : 1rem;
}
.topbar-system-name {
    font-size   : .95rem;
    font-weight : 700;
    color       : var(--pharmacy-heading, #1a1d23);
    white-space : nowrap;
    letter-spacing: -.01em;
    transition  : color .3s;
}
a#topbarBrand:hover .topbar-system-name {
    color: var(--pharmacy-primary, #0d6efd);
}

/* ── Global search ──────────────────────────────────────────────────────── */
.topbar-search-wrap {
    position   : relative;
    max-width  : 380px;
}
.topbar-search-group {
    border-radius : 20px;
    overflow      : hidden;
    box-shadow    : 0 1px 4px rgba(0,0,0,.07);
}
.topbar-search-icon {
    background    : var(--pharmacy-input-bg, #fff);
    border-right  : 0;
    border-color  : var(--pharmacy-input-border, #dee2e6);
    color         : var(--pharmacy-text, #888);
    padding-left  : 14px;
}
.topbar-search-input {
    border-left   : 0;
    border-color  : var(--pharmacy-input-border, #dee2e6);
    background    : var(--pharmacy-input-bg, #fff);
    color         : var(--pharmacy-input-text, #444);
    font-size     : .85rem;
    padding-right : 14px;
}
.topbar-search-input:focus {
    border-color : var(--pharmacy-primary, #0d6efd);
    box-shadow   : 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary,#0d6efd) 15%, transparent);
    outline      : none;
}
.topbar-search-input::placeholder { color: var(--pharmacy-text-muted, color-mix(in srgb, var(--text-color,#444) 50%, transparent)); }

/* Search results dropdown */
.topbar-search-results {
    position      : absolute;
    top           : calc(100% + 6px);
    left          : 0;
    right         : 0;
    background    : var(--pharmacy-card-bg, #fff);
    border        : 1px solid var(--pharmacy-border, #dee2e6);
    border-radius : 10px;
    box-shadow    : 0 6px 24px rgba(0,0,0,.12);
    z-index       : 1055;
    overflow      : hidden;
    max-height    : 400px;
    overflow-y    : auto;
}
.topbar-search-empty {
    padding    : 1rem;
    text-align : center;
    color      : var(--pharmacy-text-muted, color-mix(in srgb, var(--text-color,#6c757d) 55%, transparent));
    font-size  : .85rem;
}
.topbar-search-section-header {
    padding       : 6px 14px 4px;
    font-size     : .7rem;
    font-weight   : 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color         : var(--pharmacy-primary, #0d6efd);
    background    : var(--pharmacy-table-th, #f8f9fa);
    border-bottom : 1px solid var(--pharmacy-border, #dee2e6);
}
.topbar-search-item {
    display     : flex;
    align-items : center;
    gap         : 10px;
    padding     : 9px 14px;
    cursor      : pointer;
    transition  : background .12s;
    font-size   : .85rem;
    color       : var(--pharmacy-text, #444);
    text-decoration: none;
}
.topbar-search-item:hover,
.topbar-search-item:focus {
    background : color-mix(in srgb, var(--pharmacy-primary,#0d6efd) 8%, transparent);
    color      : var(--pharmacy-primary, #0d6efd);
    outline    : none;
}
.topbar-search-item-icon {
    width           : 28px;
    height          : 28px;
    border-radius   : 6px;
    display         : flex;
    align-items     : center;
    justify-content : center;
    flex-shrink     : 0;
    font-size       : .75rem;
    background      : color-mix(in srgb, var(--pharmacy-primary,#0d6efd) 12%, transparent);
    color           : var(--pharmacy-primary, #0d6efd);
}
.topbar-search-item-meta {
    font-size : .72rem;
    color     : var(--pharmacy-text-muted, color-mix(in srgb, var(--text-color,#6c757d) 55%, transparent));
    margin-top: 1px;
}
.topbar-search-no-results {
    padding    : 1.2rem 1rem;
    text-align : center;
    color      : var(--pharmacy-text-muted, color-mix(in srgb, var(--text-color,#6c757d) 55%, transparent));
    font-size  : .85rem;
}

/* ── Theme toggle button ────────────────────────────────────────────────── */
.topbar-theme-btn {
    width  : 34px;
    height : 34px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px !important;
    font-size: .9rem;
    transition: background .2s, color .2s;
}
.topbar-theme-btn:hover,
#themeSwitcherDropdown.show .topbar-theme-btn {
    background : var(--pharmacy-primary, #0d6efd) !important;
    color      : #fff !important;
    border-color: var(--pharmacy-primary, #0d6efd) !important;
}

/* ── Theme Switcher Dropdown (Phase 13) ─────────────────────────────────── */
.ts-group-label {
    font-size      : .68rem;
    text-transform : uppercase;
    letter-spacing : .08em;
    opacity        : .55;
    color          : var(--pharmacy-text, #333);
    font-weight    : 600;
}
.ts-swatches {
    display  : flex;
    gap      : 7px;
    flex-wrap: wrap;
}
.ts-swatch {
    position     : relative;
    width        : 34px;
    height       : 34px;
    border-radius: 8px;
    border       : 2.5px solid transparent;
    cursor       : pointer;
    overflow     : hidden;
    transition   : transform .15s, border-color .15s, box-shadow .15s;
    outline      : none;
    flex-shrink  : 0;
}
.ts-swatch:hover            { transform: scale(1.13); border-color: rgba(255,255,255,0.45); }
.ts-swatch--active          { border-color: #fff !important; box-shadow: 0 0 0 2.5px var(--pharmacy-primary,#3b82f6); }
.ts-swatch-check            { display:none; position:absolute; inset:0; align-items:center; justify-content:center; color:#fff; font-size:.72rem; text-shadow:0 1px 3px rgba(0,0,0,.6); }
.ts-swatch--active .ts-swatch-check { display:flex; }
.ts-swatch-name             {
    display      : none;
    position     : absolute;
    bottom       : calc(100% + 5px);
    left         : 50%;
    transform    : translateX(-50%);
    background   : rgba(0,0,0,.85);
    color        : #fff;
    font-size    : .67rem;
    white-space  : nowrap;
    padding      : 3px 7px;
    border-radius: 4px;
    pointer-events: none;
    z-index      : 9999;
}
.ts-swatch:hover .ts-swatch-name { display:block; }
.ts-status {
    font-size  : .76rem;
    color      : var(--pharmacy-primary, #3b82f6);
    min-height : 18px;
}

/* ── User avatar circle ─────────────────────────────────────────────────── */
.user-avatar-circle {
    display         : flex;
    align-items     : center;
    justify-content : center;
    width           : 36px;
    height          : 36px;
    border-radius   : 50%;
    font-weight     : bold;
    font-size       : 0.9rem;
    color           : #fff;
    text-transform  : uppercase;
    flex-shrink     : 0;
}

/* ── Navbar z-index / transition ────────────────────────────────────────── */
nav#topNavbar { z-index: 1030; background: var(--pharmacy-navbar-bg, #fff); border-bottom: 1px solid var(--pharmacy-navbar-border, #e9ecef); }
#userDropdownBtn { outline: none; }

body.sb-col-body #topNavbar {
    margin-left: var(--sb-w-mini, 62px) !important;
}
@media (max-width: 991.98px) {
    #topNavbar { margin-left: 0 !important; }
    .topbar-search-wrap { max-width: 240px; }
}
@media (max-width: 767.98px) {
    .topbar-search-wrap { display: none !important; }
}

/* ── Mobile backdrop ────────────────────────────────────────────────────── */
.sidebar-backdrop {
    position   : fixed;
    top        : 0; left: 0;
    width      : 100%; height: 100%;
    background : rgba(0,0,0,0.5);
    z-index    : 1040;
    display    : none;
}
.sidebar-backdrop.active { display: block; }

/* ── Breadcrumb bar ─────────────────────────────────────────────────────── */
.page-breadcrumb {
    display       : flex;
    align-items   : center;
    gap           : 8px;
    padding       : 7px 16px;
    background    : var(--pharmacy-card-bg, #fff);
    border-bottom : 1px solid var(--pharmacy-border, rgba(0,0,0,.08));
    margin-left   : var(--sb-w, 260px);
    transition    : margin-left .25s ease, background .3s, border-color .3s;
    font-size     : .8rem;
    position      : sticky;
    top           : 57px;
    z-index       : 98;
    box-shadow    : 0 1px 3px rgba(0,0,0,.04);
}
body.sb-col-body .page-breadcrumb { margin-left: var(--sb-w-mini, 62px); }
@media (max-width: 991.98px) {
    .page-breadcrumb { margin-left: 0 !important; top: 54px; padding: 6px 12px; }
}
.bc-path-wrap { flex: 1; min-width: 0; overflow: hidden; }
.page-breadcrumb .bc-crumb {
    display: inline-flex; align-items: center; gap: 4px;
    color: var(--pharmacy-primary, #0d6efd); text-decoration: none;
    transition: opacity .15s; white-space: nowrap; font-weight: 500;
}
.page-breadcrumb .bc-crumb:hover { opacity: .75; text-decoration: underline; }
.page-breadcrumb .bc-crumb i { font-size: .7rem; opacity: .85; }
.page-breadcrumb .separator { color: var(--pharmacy-text, #444); opacity: .3; display: inline-flex; align-items: center; font-size: .6rem; }
.page-breadcrumb .current { color: var(--pharmacy-heading, #222); font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
@media (max-width: 576px) { .page-breadcrumb .current { max-width: 120px; } }
.page-breadcrumb .bc-nav-btn {
    background: var(--pharmacy-input-bg, #fff); border: 1px solid var(--pharmacy-border, rgba(0,0,0,.1));
    color: var(--pharmacy-text, #444); border-radius: 6px; width: 28px; height: 28px; min-width: 28px;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: .72rem; flex-shrink: 0; padding: 0; line-height: 1;
    transition: background .15s, color .15s, border-color .15s;
}
.page-breadcrumb .bc-nav-btn:hover {
    background: var(--pharmacy-primary, #0d6efd); color: #fff;
    border-color: var(--pharmacy-primary, #0d6efd);
    box-shadow: 0 2px 8px color-mix(in srgb, var(--pharmacy-primary,#0d6efd) 30%, transparent);
}
.notif-item:hover {
    background: color-mix(in srgb, var(--pharmacy-primary,#0d6efd) 5%, var(--pharmacy-card-bg,#fff));
}
</style>

<!-- ═══════════════════════════════════════════════════════════════════════
     TOPBAR JAVASCRIPT
     ═══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    /* ── Live clock ─────────────────────────────────────────────────────── */
    function updateClock() {
        var now  = new Date();
        var h    = now.getHours();
        var m    = String(now.getMinutes()).padStart(2, '0');
        var s    = String(now.getSeconds()).padStart(2, '0');
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        var el = document.getElementById('navbar-clock');
        if (el) el.textContent = String(h).padStart(2, '0') + ':' + m + ':' + s + ' ' + ampm;
    }
    updateClock();
    setInterval(updateClock, 1000);

    /* ── Mobile sidebar toggle ──────────────────────────────────────────── */
    var sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    var mainSidebar      = document.getElementById('mainSidebar');
    var backdrop         = document.getElementById('sidebarBackdrop');

    function openSidebar() {
        if (mainSidebar) mainSidebar.classList.add('sidebar-open');
        if (backdrop)    backdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        if (mainSidebar) mainSidebar.classList.remove('sidebar-open');
        if (backdrop)    backdrop.classList.remove('active');
        document.body.style.overflow = '';
    }
    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', function () {
            mainSidebar && mainSidebar.classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
        });
    }
    if (backdrop) backdrop.addEventListener('click', closeSidebar);

    /* ── Sync navbar + breadcrumb margin with sidebar collapse ──────────── */
    var navbar     = document.getElementById('topNavbar');
    var breadcrumb = document.getElementById('pageBreadcrumbBar');

    function syncMargins() {
        var collapsed = document.body.classList.contains('sb-col-body');
        var isMobile  = window.innerWidth < 992;
        var margin    = isMobile ? '0px' : (collapsed ? 'var(--sb-w-mini,62px)' : 'var(--sb-w,260px)');
        if (navbar)     navbar.style.marginLeft     = margin;
        if (breadcrumb) breadcrumb.style.marginLeft = margin;
    }
    if (typeof MutationObserver !== 'undefined') {
        var mo = new MutationObserver(syncMargins);
        mo.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }
    window.addEventListener('resize', syncMargins);
    syncMargins();

    /* ── Theme Switcher (Phase 13 — AJAX, no reload) ─────────────────────── */
    (function () {
        var BASE_URL_TS = '<?php echo addslashes(BASE_URL); ?>';
        var CSRF_TS     = '<?php echo addslashes($_SESSION["csrf_token"] ?? ""); ?>';

        function applyThemeAjax(themeId) {
            var status = document.getElementById('themeApplyStatus');
            if (status) { status.textContent = 'Applying…'; status.style.display = 'block'; }

            fetch(BASE_URL_TS + 'ajax/apply_theme.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'theme_id=' + encodeURIComponent(themeId) + '&csrf_token=' + encodeURIComponent(CSRF_TS)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    // 1. Hot-swap CSS vars — no page reload
                    var oldStyle = document.getElementById('theme-engine-vars');
                    if (oldStyle) oldStyle.remove();
                    document.head.insertAdjacentHTML('beforeend', data.css_vars);

                    // 2. Update swatch active state
                    document.querySelectorAll('.ts-swatch').forEach(function (s) {
                        s.classList.remove('ts-swatch--active');
                    });
                    var activeSwatch = document.querySelector('.ts-swatch[data-theme-id="' + themeId + '"]');
                    if (activeSwatch) activeSwatch.classList.add('ts-swatch--active');

                    // 3. Update badge label in dropdown header
                    var nameEl = document.getElementById('themeActiveName');
                    if (nameEl) nameEl.textContent = data.theme_name || themeId;

                    if (status) {
                        status.textContent = '✓ ' + (data.theme_name || themeId) + ' applied';
                        status.style.color = 'var(--accent-color, #10b981)';
                        setTimeout(function () { status.style.display = 'none'; }, 2200);
                    }
                } else {
                    if (status) {
                        status.textContent = '✗ ' + (data.message || 'Apply failed');
                        status.style.color = '#ef4444';
                    }
                }
            })
            .catch(function () {
                if (status) { status.textContent = '✗ Network error'; status.style.color = '#ef4444'; }
            });
        }

        // Delegate click on all .ts-swatch buttons inside the topbar dropdown
        document.addEventListener('DOMContentLoaded', function () {
            var swatchArea = document.getElementById('themeSwatchArea');
            if (!swatchArea) return;
            swatchArea.addEventListener('click', function (e) {
                var btn = e.target.closest('.ts-swatch');
                if (!btn) return;
                var tid = btn.getAttribute('data-theme-id');
                if (tid) applyThemeAjax(tid);
            });
        });
    })();

    /* ── Global Search ───────────────────────────────────────────────────── */
    var searchInput   = document.getElementById('globalSearchInput');
    var searchResults = document.getElementById('globalSearchResults');
    var searchEmpty   = document.getElementById('globalSearchEmpty');
    var searchTimer   = null;
    var BASE_URL      = '<?php echo addslashes(BASE_URL); ?>';

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function showResults(show) {
        if (!searchResults) return;
        searchResults.hidden = !show;
        if (searchInput) searchInput.setAttribute('aria-expanded', show ? 'true' : 'false');
    }

    function buildResultsHtml(data) {
        if (!data || (!data.products?.length && !data.sales?.length && !data.customers?.length)) {
            return '<div class="topbar-search-no-results"><i class="fas fa-search me-1"></i>No results found</div>';
        }
        var html = '';

        if (data.products && data.products.length) {
            html += '<div class="topbar-search-section-header"><i class="fas fa-pills me-1"></i>Products</div>';
            data.products.forEach(function (p) {
                html += '<a href="' + escHtml(BASE_URL + 'products/view.php?id=' + p.id) + '" class="topbar-search-item">'
                      + '<span class="topbar-search-item-icon"><i class="fas fa-pills"></i></span>'
                      + '<span><div>' + escHtml(p.name) + '</div>'
                      + '<div class="topbar-search-item-meta">' + escHtml(p.category || '') + (p.stock !== undefined ? ' · Stock: ' + escHtml(String(p.stock)) : '') + '</div></span>'
                      + '</a>';
            });
        }
        if (data.customers && data.customers.length) {
            html += '<div class="topbar-search-section-header"><i class="fas fa-users me-1"></i>Customers</div>';
            data.customers.forEach(function (c) {
                html += '<a href="' + escHtml(BASE_URL + 'sales/customer_view.php?id=' + c.id) + '" class="topbar-search-item">'
                      + '<span class="topbar-search-item-icon"><i class="fas fa-user"></i></span>'
                      + '<span><div>' + escHtml(c.name) + '</div>'
                      + '<div class="topbar-search-item-meta">' + escHtml(c.phone || c.email || '') + '</div></span>'
                      + '</a>';
            });
        }
        if (data.sales && data.sales.length) {
            html += '<div class="topbar-search-section-header"><i class="fas fa-receipt me-1"></i>Sales</div>';
            data.sales.forEach(function (s) {
                html += '<a href="' + escHtml(BASE_URL + 'sales/view.php?id=' + s.id) + '" class="topbar-search-item">'
                      + '<span class="topbar-search-item-icon"><i class="fas fa-receipt"></i></span>'
                      + '<span><div>' + escHtml(s.invoice_no || 'Sale #' + s.id) + '</div>'
                      + '<div class="topbar-search-item-meta">' + escHtml(s.customer_name || '') + (s.total ? ' · ' + escHtml(String(s.total)) : '') + '</div></span>'
                      + '</a>';
            });
        }
        return html;
    }

    function doSearch(query) {
        if (!query || query.length < 2) {
            showResults(false);
            return;
        }
        if (searchResults) {
            searchResults.innerHTML = '<div class="topbar-search-empty"><i class="fas fa-spinner fa-spin me-1"></i>Searching…</div>';
            showResults(true);
        }
        fetch(BASE_URL + 'ajax/global_search.php?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) {
                if (searchResults) {
                    searchResults.innerHTML = buildResultsHtml(data);
                    showResults(true);
                }
            })
            .catch(function () {
                if (searchResults) {
                    searchResults.innerHTML = '<div class="topbar-search-no-results"><i class="fas fa-exclamation-triangle me-1 text-warning"></i>Search unavailable</div>';
                    showResults(true);
                }
            });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var q = searchInput.value.trim();
            searchTimer = setTimeout(function () { doSearch(q); }, 260);
        });
        searchInput.addEventListener('focus', function () {
            if (searchInput.value.trim().length >= 2) showResults(true);
        });
        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && (!searchResults || !searchResults.contains(e.target))) {
                showResults(false);
            }
        });
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { showResults(false); searchInput.blur(); }
        });
    }

    /* ── Notification helpers ─────────────────────────────────────────────── */
    var NOTIF_ENDPOINT   = BASE_URL + 'ajax/get_notifications.php';
    var REFRESH_INTERVAL = 2 * 60 * 1000;

    function setBadge(count) {
        var badge  = document.getElementById('notif-badge');
        var span   = document.getElementById('notif-count');
        var panelC = document.getElementById('notif-panel-count');
        if (!badge || !span) return;
        if (count > 0) {
            span.textContent    = count > 99 ? '99+' : count;
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
        if (panelC) panelC.textContent = count;
    }

    function renderGroupedNotifications(data) {
        var container = document.getElementById('notif-list');
        if (!container) return;
        if (!data || !data.success) {
            container.innerHTML = '<div class="text-center py-4 text-danger small"><i class="fas fa-exclamation-triangle me-1"></i>Load failed</div>';
            return;
        }
        var total = (data.summary && data.summary.total_alerts) || data.count || 0;
        setBadge(total);
        if (total === 0) {
            container.innerHTML = '<div class="text-center py-4 text-muted small"><i class="fas fa-check-circle text-success me-1"></i>All clear — no alerts</div>';
            return;
        }
        var categories = [
            { key: 'expired',                  label: 'Expired Stock',     icon: 'fa-skull-crossbones', color: 'danger'    },
            { key: 'overdue_credits',           label: 'Overdue Credits',   icon: 'fa-hand-holding-usd', color: 'info'      },
            { key: 'cashbook_gaps',             label: 'Cashbook Gaps',     icon: 'fa-book-open',        color: 'warning'   },
            { key: 'unprocessed_returns',       label: 'Pending Returns',   icon: 'fa-undo',             color: 'warning'   },
            { key: 'near_expiry',               label: 'Near Expiry',       icon: 'fa-calendar-times',   color: 'warning'   },
            { key: 'low_stock',                 label: 'Low Stock',         icon: 'fa-boxes',            color: 'warning'   },
            { key: 'pending_supplier_payments', label: 'Supplier Dues',     icon: 'fa-truck',            color: 'secondary' },
        ];
        var html = '';
        categories.forEach(function (cat) {
            var d = data[cat.key];
            if (!d || d.count === 0) return;
            html += '<div class="border-bottom"><div class="px-3 py-2 d-flex justify-content-between align-items-center">'
                  + '<span class="fw-semibold small text-' + cat.color + '"><i class="fas ' + cat.icon + ' me-1"></i>' + cat.label + '</span>'
                  + '<span class="badge bg-' + cat.color + ' rounded-pill">' + d.count + '</span>'
                  + '</div><div class="px-3 py-1 small">';
            if (cat.key === 'overdue_credits' && d.total_amount) html += '<div class="text-danger fw-semibold mb-1">Total Due: ' + escHtml(d.total_amount) + '</div>';
            if (cat.key === 'pending_supplier_payments' && d.total_amount) html += '<div class="text-warning fw-semibold mb-1">Total Payable: ' + escHtml(d.total_amount) + '</div>';
            (d.items || []).forEach(function (item) {
                if (cat.key === 'expired')        html += '<div><i class="fas fa-exclamation-triangle text-danger me-1"></i>' + escHtml(item.product) + ' (Batch ' + escHtml(item.batch) + ') — ' + escHtml(item.qty) + ' units, expired ' + escHtml(item.expiry) + '</div>';
                else if (cat.key === 'near_expiry') html += '<div><i class="fas fa-calendar-week text-warning me-1"></i>' + escHtml(item.product) + ' — ' + escHtml(String(item.days)) + ' days left (Batch ' + escHtml(item.batch) + ', ' + escHtml(String(item.qty)) + ' units)</div>';
                else if (cat.key === 'low_stock')   html += '<div><i class="fas fa-box text-warning me-1"></i>' + escHtml(item.product) + ' — only ' + escHtml(String(item.stock)) + ' left (Reorder: ' + escHtml(String(item.reorder)) + ')</div>';
                else if (cat.key === 'overdue_credits') html += '<div><i class="fas fa-user text-info me-1"></i>' + escHtml(item.customer) + ' — ' + escHtml(item.amount) + ' (Due ' + escHtml(item.due_date) + ')</div>';
                else if (cat.key === 'pending_supplier_payments') html += '<div><i class="fas fa-building me-1"></i>' + escHtml(item.supplier) + ' — ' + escHtml(item.amount) + '</div>';
                else if (cat.key === 'unprocessed_returns') html += '<div><i class="fas fa-undo me-1"></i>' + escHtml(item.type) + ': ' + escHtml(item.ref) + '</div>';
                else if (cat.key === 'cashbook_gaps') html += '<div><i class="fas fa-book me-1"></i>' + escHtml(item.type) + ' ' + escHtml(item.ref) + ' — ' + escHtml(item.amount) + '</div>';
            });
            html += '</div></div>';
        });
        container.innerHTML = html || '<div class="text-center py-4 text-muted small">No new alerts</div>';
    }

    function fetchNotifications(showLoading) {
        var container = document.getElementById('notif-list');
        if (showLoading && container) {
            container.innerHTML = '<div class="text-center py-4 text-muted small"><i class="fas fa-spinner fa-spin me-1"></i> Loading...</div>';
        }
        fetch(NOTIF_ENDPOINT, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) { renderGroupedNotifications(data); })
            .catch(function () {
                var c = document.getElementById('notif-list');
                if (c) c.innerHTML = '<div class="text-center py-3 text-muted small"><i class="fas fa-wifi text-danger me-1"></i>Could not load</div>';
                setBadge(0);
            });
    }

    var bellBtn = document.getElementById('notifBellBtn');
    if (bellBtn) bellBtn.addEventListener('show.bs.dropdown', function () { fetchNotifications(true); });
    setInterval(function () { fetchNotifications(false); }, REFRESH_INTERVAL);

    /* ── Fix cashbook gaps ───────────────────────────────────────────────── */
    var fixBtn = document.getElementById('fixCashbookGapsBtn');
    if (fixBtn) {
        fixBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (confirm('This will create missing cashbook entries. Proceed?')) {
                window.location.href = BASE_URL + 'ajax/fix_cashbook_gaps.php';
            }
        });
    }

    /* ── Live brand update (called by settings/company.php after save) ───── */
    window.topbarUpdateBrand = function (name, logoUrl) {
        var nameEl = document.getElementById('topbarSystemName');
        if (nameEl && name) nameEl.textContent = name.length > 28 ? name.substring(0, 27) + '…' : name;
        var imgEl = document.getElementById('topbarLogoImg');
        var fbEl  = document.getElementById('topbarLogoFallback');
        if (imgEl && logoUrl) {
            imgEl.src = logoUrl;
            imgEl.style.display = '';
            if (fbEl) fbEl.style.display = 'none';
        }
    };

})();
</script>
