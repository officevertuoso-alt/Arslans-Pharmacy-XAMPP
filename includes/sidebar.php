<?php
/**
 * === FILE: pharmacy_software/includes/sidebar.php ===
 *
 * PHASE 6 — Theme Integration: sidebar vars resolved from Theme Engine (Universal Component)
 *
 * Features:
 *   • Permission-based rendering  — canView() / canDo() gate every menu section
 *   • Active state                — isActiveSub(dir,page) + isActive() per-page
 *   • Collapsible mode            — lock/unlock + mini icon-only rail
 *   • Responsive mode             — mobile drawer + backdrop overlay
 *   • Anti-flash pre-render       — applies collapsed class before paint via inline <script>
 *   • Company branding            — logo, name, tagline from DB (session-cached)
 *   • User strip                  — avatar, name, role badge (profile image or initials)
 *   • Tooltip on collapsed icons  — data-tip attr shows label beside icon rail
 *   • Scrollable nav              — thin scrollbar, visible only on hover
 *   • Version: 7.0
 *
 * Requires:
 *   includes/auth.php          — canView(), canDo(), isAdmin()
 *   includes/functions.php     — getCompanySettings()
 *   config/config.php          — BASE_URL, ROOT_PATH, BASE_PATH
 */

// ── Ensure RBAC permission helpers are available ──────────────────────────────
if (!function_exists('canDo')) {
    $__auth_path = dirname(__DIR__) . '/includes/auth.php';
    if (file_exists($__auth_path)) require_once $__auth_path;
}

$current_page = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));

// ─── Active-state helpers ─────────────────────────────────────────────────────

// isActive — top-level: match dir OR page (supports arrays)
if (!function_exists('isActive')) {
    function isActive(string|array $dirs = [], string|array $pages = []): string {
        global $current_dir, $current_page;
        $dirs  = (array)$dirs;
        $pages = (array)$pages;
        if (in_array($current_dir,  $dirs,  true)) return 'active';
        if (in_array($current_page, $pages, true)) return 'active';
        foreach ($pages as $p) { if (str_contains($current_page, $p)) return 'active'; }
        return '';
    }
}

// isActiveSub — sub-item: requires BOTH dir AND page to match (fixes cross-dir collision)
if (!function_exists('isActiveSub')) {
    function isActiveSub(string $dir, string $page): string {
        global $current_dir, $current_page;
        return ($current_dir === $dir && $current_page === $page) ? 'active' : '';
    }
}

// isActiveSubMulti — sub-item matching multiple pages in same dir
if (!function_exists('isActiveSubMulti')) {
    function isActiveSubMulti(string $dir, array $pages): string {
        global $current_dir, $current_page;
        return ($current_dir === $dir && in_array($current_page, $pages, true)) ? 'active' : '';
    }
}

// subMenuOpen — returns 'show' if the current page is inside a sub-menu section
if (!function_exists('subMenuOpen')) {
    function subMenuOpen(string|array $dirs = [], string|array $pages = []): string {
        global $current_dir, $current_page;
        $dirs  = (array)$dirs;
        $pages = (array)$pages;
        if (in_array($current_dir,  $dirs,  true)) return 'show';
        if (in_array($current_page, $pages, true)) return 'show';
        foreach ($pages as $p) { if (str_contains($current_page, $p)) return 'show'; }
        return '';
    }
}

// ─── Permission helpers (fallback when auth.php not yet loaded) ───────────────
if (!function_exists('canView')) {
    function canView(string $module): bool {
        $role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'guest';
        if ($role === 'admin') return true;
        // Phase 21: manager/custom use DB-backed permissions via canDo() in auth.php.
        // This sidebar fallback (loaded when auth.php not yet included) uses a
        // conservative role-map; the real check happens in auth.php's canDo().
        $map = [
            'dashboard' => ['admin','manager','pharmacist','cashier','viewer'],
            'products'  => ['admin','manager','pharmacist'],
            'purchases' => ['admin','manager','pharmacist'],
            'sales'     => ['admin','manager','pharmacist','cashier'],
            'credit'    => ['admin','manager','pharmacist','cashier'],
            'suppliers' => ['admin','manager','pharmacist'],
            'returns'   => ['admin','manager','pharmacist','cashier'],
            'reports'   => ['admin','manager','pharmacist','viewer'],
            'cashbook'  => ['admin','manager','pharmacist'],
            'expenses'  => ['admin','manager','pharmacist','cashier'],
            'stock'     => ['admin','manager','pharmacist'],
            'customers' => ['admin','manager','pharmacist','cashier'],
            'users'     => ['admin','manager'],
            'settings'  => ['admin','manager'],
            'backup'    => ['admin'],
        ];
        return in_array($role, $map[$module] ?? ['admin'], true);
    }
}
if (!function_exists('canDo')) {
    function canDo(string $module, string $action): bool {
        $role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'guest';
        if ($role === 'admin') return true;
        return canView($module);
    }
}
if (!function_exists('isAdmin')) {
    function isAdmin(): bool {
        return ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '') === 'admin';
    }
}
// Phase 21: isManager fallback (auth.php version takes priority when loaded)
if (!function_exists('isManager')) {
    function isManager(): bool {
        return ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '') === 'manager';
    }
}

// ─── Company settings — session-cached (10 min) ───────────────────────────────
$_sbCacheTime = $_SESSION['company_settings_cache_time'] ?? 0;
if (!empty($_SESSION['company_settings_cache']) && (time() - $_sbCacheTime) < 600) {
    $_sbSettings = $_SESSION['company_settings_cache'];
} else {
    try {
        $_sbSettings = function_exists('getCompanySettings') ? getCompanySettings() : [];
        $_SESSION['company_settings_cache']      = $_sbSettings;
        $_SESSION['company_settings_cache_time'] = time();
    } catch (Throwable $_e) {
        $_sbSettings = $_SESSION['company_settings_cache'] ?? [];
    }
}

$_sbPharmacyName = trim($_sbSettings['pharmacy_name'] ?? $_sbSettings['company_name'] ?? 'Pharmacy MS') ?: 'Pharmacy MS';
$_sbTagline      = trim($_sbSettings['pharmacy_tagline'] ?? $_sbSettings['tagline'] ?? '');
$_sbShowLogo     = !empty($_sbSettings['show_logo_on_sidebar']);
$_sbLogoPath     = $_sbSettings['logo_path'] ?? '';

$_sbLogoHtml = '';
if ($_sbShowLogo && !empty($_sbLogoPath)) {
    $_sbLogoFull = (defined('ROOT_PATH') ? ROOT_PATH : '') . ltrim($_sbLogoPath, '/');
    $_sbLogoCb   = file_exists($_sbLogoFull) ? filemtime($_sbLogoFull) : time();
    $_sbLogoHtml = '<img src="' . htmlspecialchars((defined('BASE_URL') ? BASE_URL : '/') . ltrim($_sbLogoPath, '/'))
                 . '?v=' . $_sbLogoCb . '" alt="' . htmlspecialchars($_sbPharmacyName)
                 . '" class="sb-pharmacy-logo d-block mx-auto">';
} else {
    $_sbLogoHtml = '<i class="fas fa-hospital fa-2x" style="color:var(--sb-accent,#667eea);"></i>';
}

// ─── User display info ────────────────────────────────────────────────────────
$_sbUsername     = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$_sbRoleRaw      = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'guest';
// Phase 21: friendly label for custom role
$_sbUserRole     = match($_sbRoleRaw) {
    'admin'      => 'Admin',
    'manager'    => 'Manager',
    'pharmacist' => 'Pharmacist',
    'cashier'    => 'Cashier',
    'viewer'     => 'Viewer',
    'custom'     => 'Custom Role',
    default      => ucfirst($_sbRoleRaw),
};
$_sbAvatarColors = ['#e74c3c','#3498db','#2ecc71','#9b59b6','#f39c12','#1abc9c','#e67e22','#e91e63'];
$_sbAvatarColor  = $_sbAvatarColors[abs(crc32($_sbUsername)) % count($_sbAvatarColors)];
$_sbNameParts    = explode(' ', trim($_sbUsername));
$_sbInitials     = strtoupper(($_sbNameParts[0][0] ?? '') . (isset($_sbNameParts[1]) ? $_sbNameParts[1][0] : '')) ?: 'U';

// ─── Resolve profile image ────────────────────────────────────────────────────
$_sbProfileImgUrl = '';
$_sbHasProfileImg = false;

if (!empty($_SESSION['user_profile_image_url'])) {
    $_sbProfileImgUrl = $_SESSION['user_profile_image_url'];
    $_sbHasProfileImg = true;
} else {
    $_sbImgFile = $_SESSION['user_profile_image'] ?? $_SESSION['profile_image'] ?? '';
    if (!empty($_sbImgFile)) {
        $_sbRoot = defined('ROOT_PATH') ? ROOT_PATH : rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/pharmacy_software/';
        foreach (['uploads/profiles/', 'uploads/avatars/'] as $_sbDir) {
            $_sbFull = $_sbRoot . $_sbDir . basename($_sbImgFile);
            if (file_exists($_sbFull)) {
                $_sbCb            = filemtime($_sbFull);
                $_sbProfileImgUrl = (defined('BASE_URL') ? BASE_URL : '/') . $_sbDir . basename($_sbImgFile) . '?v=' . $_sbCb;
                $_SESSION['user_profile_image_url'] = $_sbProfileImgUrl;
                $_sbHasProfileImg = true;
                break;
            }
        }
    }
    if (!$_sbHasProfileImg && !empty($_SESSION['user_id'])) {
        try {
            if (function_exists('db')) {
                $_sbDb = db();
                $_sbDb->query("SELECT profile_image FROM users WHERE id = :id LIMIT 1");
                $_sbDb->bind(':id', (int)$_SESSION['user_id']);
                $_sbDbRow = $_sbDb->single();
                if ($_sbDbRow) {
                    $_sbRoot = defined('ROOT_PATH') ? ROOT_PATH : rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/pharmacy_software/';
                    foreach (array_filter([$_sbDbRow['profile_image'] ?? '']) as $_sbFname) {
                        foreach (['uploads/profiles/', 'uploads/avatars/'] as $_sbDir) {
                            $_sbFull = $_sbRoot . $_sbDir . basename($_sbFname);
                            if (file_exists($_sbFull)) {
                                $_sbCb            = filemtime($_sbFull);
                                $_sbProfileImgUrl = (defined('BASE_URL') ? BASE_URL : '/') . $_sbDir . basename($_sbFname) . '?v=' . $_sbCb;
                                $_SESSION['user_profile_image']     = $_sbFname;
                                $_SESSION['user_profile_image_url'] = $_sbProfileImgUrl;
                                $_sbHasProfileImg = true;
                                break 2;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $_e) {}
    }
}

// ─── Sidebar theme colors ─────────────────────────────────────────────────────
// ── Phase 6: Sidebar colors sourced from Theme Engine CSS vars ───────────────
// PHP fallbacks kept only as last-resort; Theme Engine vars take precedence.
$_sbBg      = $_sbSettings['sidebar_color'] ?? '#1a1d23';
$_sbAccent  = $_sbSettings['accent_color']  ?? '#667eea';
$_sbPrimary = $_sbSettings['primary_color'] ?? '#0d6efd';
if (!empty($_sbSettings['dark_mode'])) {
    $_sbBg = $_sbSettings['dark_sidebar_color'] ?? '#161b22';
}
?>

<!-- ── Phase 4: Anti-flash — apply collapsed state BEFORE sidebar renders ──────
     Synchronously reads localStorage so the sidebar is already the right width
     on first paint, eliminating any visible layout jump.                       -->
<script>
(function () {
    var locked    = localStorage.getItem('pharm_sb_lock') === 'true';
    var collapsed = localStorage.getItem('pharm_sb_col')  === 'true';
    if (locked && collapsed) {
        var s = document.createElement('style');
        s.id  = 'sb-prerender-col';
        s.textContent =
            '#mainSidebar{width:var(--sb-w-mini,62px)!important}' +
            '#mainSidebar .sb-txt,' +
            '#mainSidebar .sb-section,' +
            '#mainSidebar #sbBrandFull,' +
            '#mainSidebar #sbUserFull,' +
            '#mainSidebar #sbFooter,' +
            '#mainSidebar .collapse{display:none!important}' +
            '#mainSidebar #sbBrandMini,' +
            '#mainSidebar #sbUserMini{display:block!important}' +
            '#mainSidebar .sb-link{justify-content:center;padding:10px 0}' +
            'body{padding-left:0}';
        document.head.appendChild(s);
    }
})();
</script>

<!-- ══════════════════════════════════════════════════════════════════════════
     PHASE 4 — GLOBAL SIDEBAR
     Universal sidebar component. All pages include this single file.
     No inline sidebar HTML should exist in any individual page.
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="mainSidebar" role="navigation" aria-label="Main navigation"
     style="background:<?php echo htmlspecialchars($_sbBg); ?>">

    <!-- ── Brand header ────────────────────────────────────────────────── -->
    <div id="sbHeader">

        <!-- Expanded brand (full sidebar) -->
        <div id="sbBrandFull">
            <div class="sb-logo-wrap"><?php echo $_sbLogoHtml; ?></div>
            <div class="sb-name">
                <?php echo htmlspecialchars(
                    mb_strlen($_sbPharmacyName) > 25
                        ? mb_substr($_sbPharmacyName, 0, 24) . '…'
                        : $_sbPharmacyName
                ); ?>
            </div>
            <?php if (!empty($_sbTagline)): ?>
            <div class="sb-tagline"><?php echo htmlspecialchars($_sbTagline); ?></div>
            <?php endif; ?>
        </div>

        <!-- Mini brand (collapsed / icon-rail mode) -->
        <div id="sbBrandMini"><?php echo $_sbLogoHtml; ?></div>

        <!-- Controls: lock + collapse + theme -->
            <button type="button" id="sbThemeBtn" class="sb-ctrl-btn" title="Change theme" aria-label="Change theme"
                    data-bs-toggle="modal" data-bs-target="#globalThemeModal">
                <i class="fas fa-palette"></i>
            </button>

        <div id="sbCtrlRow">
            <button id="sbLockBtn" class="sb-ctrl-btn" title="Lock sidebar open" aria-label="Lock sidebar">
                <i id="sbLockIco" class="fas fa-lock-open"></i>
            </button>
            <button id="sbColBtn" class="sb-ctrl-btn" title="Collapse sidebar" aria-label="Collapse sidebar">
                <i id="sbColIco" class="fas fa-angles-left"></i>
            </button>
        </div>

    </div><!-- /#sbHeader -->

    <!-- ── User strip ───────────────────────────────────────────────────── -->
    <div id="sbUserFull">
        <?php if ($_sbHasProfileImg): ?>
        <img src="<?php echo htmlspecialchars($_sbProfileImgUrl); ?>"
             class="sb-avatar-img"
             alt="<?php echo htmlspecialchars($_sbInitials); ?>"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <span class="sb-avatar" style="background:<?php echo $_sbAvatarColor; ?>;display:none;">
            <?php echo htmlspecialchars($_sbInitials); ?>
        </span>
        <?php else: ?>
        <span class="sb-avatar" style="background:<?php echo $_sbAvatarColor; ?>;">
            <?php echo htmlspecialchars($_sbInitials); ?>
        </span>
        <?php endif; ?>
        <div class="sb-user-info">
            <strong><?php echo htmlspecialchars($_sbUsername); ?></strong>
            <span class="sb-role-badge"><?php echo htmlspecialchars($_sbUserRole); ?></span>
        </div>
    </div>

    <!-- Mini user strip (collapsed mode) -->
    <div id="sbUserMini">
        <?php if ($_sbHasProfileImg): ?>
        <img src="<?php echo htmlspecialchars($_sbProfileImgUrl); ?>"
             class="sb-avatar-img-sm"
             title="<?php echo htmlspecialchars($_sbUsername . ' — ' . $_sbUserRole); ?>"
             alt="<?php echo htmlspecialchars($_sbInitials); ?>"
             onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
        <span class="sb-avatar sb-avatar-sm"
              style="background:<?php echo $_sbAvatarColor; ?>;display:none;"
              title="<?php echo htmlspecialchars($_sbUsername . ' — ' . $_sbUserRole); ?>">
            <?php echo htmlspecialchars($_sbInitials); ?>
        </span>
        <?php else: ?>
        <span class="sb-avatar sb-avatar-sm"
              style="background:<?php echo $_sbAvatarColor; ?>;"
              title="<?php echo htmlspecialchars($_sbUsername . ' — ' . $_sbUserRole); ?>">
            <?php echo htmlspecialchars($_sbInitials); ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
         NAV — scrollable, permission-gated, active-state aware
         ══════════════════════════════════════════════════════════════════ -->
    <nav id="sbNav" aria-label="Sidebar navigation">

        <!-- ─── Dashboard ─────────────────────────────────────────────── -->
        <?php if (canView('dashboard')): ?>
        <a href="<?php echo BASE_URL; ?>dashboard.php"
           class="sb-link <?php echo isActive(pages: ['dashboard.php']); ?>"
           data-tip="Dashboard">
            <i class="fas fa-tachometer-alt sb-ico"></i>
            <span class="sb-txt"> Dashboard</span>
        </a>
        <?php endif; ?>

        <!-- ════════════════ SECTION: MANAGEMENT ═════════════════════════ -->
        <div class="sb-section sb-txt">
            <i class="fas fa-layer-group sb-section-ico"></i>
            <span>Management</span>
        </div>

        <!-- ─── Products ──────────────────────────────────────────────── -->
        <?php if (canView('products')): ?>
        <?php $_o = subMenuOpen(dirs: 'products'); ?>
        <a href="#sp-products"
           class="sb-link sb-toggle <?php echo $_o ? 'open' : ''; ?>"
           data-bs-toggle="collapse"
           aria-expanded="<?php echo $_o ? 'true' : 'false'; ?>"
           data-tip="Products">
            <i class="fas fa-capsules sb-ico"></i>
            <span class="sb-txt"> Products <i class="fas fa-chevron-down sb-arr"></i></span>
        </a>
        <div class="collapse <?php echo $_o; ?>" id="sp-products">
            <div class="sb-sub">
                <a href="<?php echo BASE_URL; ?>products/list.php"
                   class="sb-link <?php echo isActiveSub('products', 'list.php'); ?>">
                    <i class="fas fa-list sb-ico"></i><span class="sb-txt"> Product List</span>
                </a>
                <a href="<?php echo BASE_URL; ?>products/add.php"
                   class="sb-link <?php echo isActiveSub('products', 'add.php'); ?>">
                    <i class="fas fa-plus-circle sb-ico"></i><span class="sb-txt"> Add Product</span>
                </a>
                <?php if (canDo('products', 'import')): ?>
                <a href="<?php echo BASE_URL; ?>products/bulk_import.php"
                   class="sb-link <?php echo isActiveSub('products', 'bulk_import.php'); ?>">
                    <i class="fas fa-file-upload sb-ico"></i><span class="sb-txt"> Bulk Import</span>
                </a>
                <?php endif; ?>
                <?php if (canView('stock')): ?>
                <a href="<?php echo BASE_URL; ?>stock/index.php"
                   class="sb-link <?php echo isActiveSub('stock', 'index.php'); ?>">
                    <i class="fas fa-tools sb-ico" style="color:var(--warning,#f59e0b);"></i>
                    <span class="sb-txt"> Stock Adjustments</span>
                </a>
                <a href="<?php echo BASE_URL; ?>stock/transfers.php"
                   class="sb-link <?php echo isActiveSub('stock', 'transfers.php'); ?>">
                    <i class="fas fa-exchange-alt sb-ico" style="color:var(--info,#0dcaf0);"></i>
                    <span class="sb-txt"> Stock Transfers</span>
                </a>
                <?php endif; ?>
                <?php if ($current_page === 'list.php' && $current_dir === 'products'): ?>
                <a href="#" class="sb-link"
                   onclick="openTrashModal();return false;"
                   data-tip="Trash Items">
                    <i class="fas fa-trash-alt sb-ico sb-ico-danger"></i>
                    <span class="sb-txt"> Trash Items</span>
                </a>
                <?php else: ?>
                <a href="<?php echo BASE_URL; ?>products/list.php#openTrash"
                   class="sb-link <?php echo isActiveSub('products', 'trash_products.php'); ?>"
                   data-tip="Trash Items">
                    <i class="fas fa-trash-alt sb-ico sb-ico-danger"></i>
                    <span class="sb-txt"> Trash Items</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Sales ─────────────────────────────────────────────────── -->
        <?php if (canView('sales')): ?>
        <?php $_o = subMenuOpen(dirs: 'sales'); ?>
        <a href="#sp-sales"
           class="sb-link sb-toggle <?php echo $_o ? 'open' : ''; ?>"
           data-bs-toggle="collapse"
           aria-expanded="<?php echo $_o ? 'true' : 'false'; ?>"
           data-tip="Sales">
            <i class="fas fa-cash-register sb-ico"></i>
            <span class="sb-txt"> Sales <i class="fas fa-chevron-down sb-arr"></i></span>
        </a>
        <div class="collapse <?php echo $_o; ?>" id="sp-sales">
            <div class="sb-sub">
                <a href="<?php echo BASE_URL; ?>sales/pos.php"
                   class="sb-link <?php echo isActiveSub('sales', 'pos.php'); ?>">
                    <i class="fas fa-desktop sb-ico"></i><span class="sb-txt"> POS / New Sale</span>
                </a>
                <a href="<?php echo BASE_URL; ?>sales/list.php"
                   class="sb-link <?php echo isActiveSub('sales', 'list.php'); ?>">
                    <i class="fas fa-list sb-ico"></i><span class="sb-txt"> Sales List</span>
                </a>
                <a href="<?php echo BASE_URL; ?>sales/customers.php"
                   class="sb-link <?php echo isActiveSub('sales', 'customers.php'); ?>">
                    <i class="fas fa-users sb-ico"></i><span class="sb-txt"> Customers</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Purchases ─────────────────────────────────────────────── -->
        <?php if (canView('purchases')): ?>
        <?php $_o = subMenuOpen(dirs: 'purchases'); ?>
        <a href="#sp-purchases"
           class="sb-link sb-toggle <?php echo $_o ? 'open' : ''; ?>"
           data-bs-toggle="collapse"
           aria-expanded="<?php echo $_o ? 'true' : 'false'; ?>"
           data-tip="Purchases">
            <i class="fas fa-shopping-cart sb-ico"></i>
            <span class="sb-txt"> Purchases <i class="fas fa-chevron-down sb-arr"></i></span>
        </a>
        <div class="collapse <?php echo $_o; ?>" id="sp-purchases">
            <div class="sb-sub">
                <a href="<?php echo BASE_URL; ?>purchases/add.php"
                   class="sb-link <?php echo isActiveSub('purchases', 'add.php'); ?>">
                    <i class="fas fa-plus-circle sb-ico"></i><span class="sb-txt"> Add Purchase</span>
                </a>
                <a href="<?php echo BASE_URL; ?>purchases/list.php"
                   class="sb-link <?php echo isActiveSub('purchases', 'list.php'); ?>">
                    <i class="fas fa-list sb-ico"></i><span class="sb-txt"> Purchase List</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Returns ───────────────────────────────────────────────── -->
        <?php if (canView('returns')): ?>
        <?php $_o = subMenuOpen(
            dirs: 'returns',
            pages: ['sale_return.php','purchase_return.php','sale_return_list.php','purchase_return_list.php']
        ); ?>
        <a href="#sp-returns"
           class="sb-link sb-toggle <?php echo $_o ? 'open' : ''; ?>"
           data-bs-toggle="collapse"
           aria-expanded="<?php echo $_o ? 'true' : 'false'; ?>"
           data-tip="Returns">
            <i class="fas fa-undo-alt sb-ico"></i>
            <span class="sb-txt"> Returns <i class="fas fa-chevron-down sb-arr"></i></span>
        </a>
        <div class="collapse <?php echo $_o; ?>" id="sp-returns">
            <div class="sb-sub">
                <a href="<?php echo BASE_URL; ?>returns/sale_return.php"
                   class="sb-link <?php echo isActiveSub('returns', 'sale_return.php'); ?>">
                    <i class="fas fa-undo sb-ico"></i><span class="sb-txt"> Sale Return</span>
                </a>
                <a href="<?php echo BASE_URL; ?>returns/purchase_return.php"
                   class="sb-link <?php echo isActiveSub('returns', 'purchase_return.php'); ?>">
                    <i class="fas fa-exchange-alt sb-ico"></i><span class="sb-txt"> Purchase Return</span>
                </a>
                <a href="<?php echo BASE_URL; ?>returns/sale_return_list.php"
                   class="sb-link <?php echo isActiveSub('returns', 'sale_return_list.php'); ?>">
                    <i class="fas fa-list sb-ico"></i><span class="sb-txt"> Sale Return List</span>
                </a>
                <a href="<?php echo BASE_URL; ?>returns/purchase_return_list.php"
                   class="sb-link <?php echo isActiveSub('returns', 'purchase_return_list.php'); ?>">
                    <i class="fas fa-list sb-ico"></i><span class="sb-txt"> Purchase Return List</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Credit ────────────────────────────────────────────────── -->
        <?php if (canView('credit')): ?>
        <?php $_o = subMenuOpen(dirs: 'credit'); ?>
        <a href="#sp-credit"
           class="sb-link sb-toggle <?php echo $_o ? 'open' : ''; ?>"
           data-bs-toggle="collapse"
           aria-expanded="<?php echo $_o ? 'true' : 'false'; ?>"
           data-tip="Credit">
            <i class="fas fa-file-invoice-dollar sb-ico"></i>
            <span class="sb-txt"> Credit <i class="fas fa-chevron-down sb-arr"></i></span>
        </a>
        <div class="collapse <?php echo $_o; ?>" id="sp-credit">
            <div class="sb-sub">
                <a href="<?php echo BASE_URL; ?>credit/index.php"
                   class="sb-link <?php echo isActiveSub('credit', 'index.php'); ?>">
                    <i class="fas fa-tasks sb-ico"></i><span class="sb-txt"> Manage Credits</span>
                </a>
                <a href="<?php echo BASE_URL; ?>credit/list.php"
                   class="sb-link <?php echo isActiveSub('credit', 'list.php'); ?>">
                    <i class="fas fa-list sb-ico"></i><span class="sb-txt"> Credit Sales</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Suppliers ─────────────────────────────────────────────── -->
        <?php if (canView('suppliers')): ?>
        <?php $_o = subMenuOpen(dirs: 'suppliers'); ?>
        <a href="#sp-suppliers"
           class="sb-link sb-toggle <?php echo $_o ? 'open' : ''; ?>"
           data-bs-toggle="collapse"
           aria-expanded="<?php echo $_o ? 'true' : 'false'; ?>"
           data-tip="Suppliers">
            <i class="fas fa-truck sb-ico"></i>
            <span class="sb-txt"> Suppliers <i class="fas fa-chevron-down sb-arr"></i></span>
        </a>
        <div class="collapse <?php echo $_o; ?>" id="sp-suppliers">
            <div class="sb-sub">
                <a href="<?php echo BASE_URL; ?>suppliers/list.php"
                   class="sb-link <?php echo isActiveSub('suppliers', 'list.php'); ?>">
                    <i class="fas fa-list sb-ico"></i><span class="sb-txt"> Supplier List</span>
                </a>
                <a href="<?php echo BASE_URL; ?>suppliers/add.php"
                   class="sb-link <?php echo isActiveSub('suppliers', 'add.php'); ?>">
                    <i class="fas fa-plus-circle sb-ico"></i><span class="sb-txt"> Add Supplier</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Cashbook ──────────────────────────────────────────────── -->
        <?php if (canView('cashbook')): ?>
        <?php $_o = subMenuOpen(dirs: 'cashbook'); ?>
        <a href="#sp-cashbook"
           class="sb-link sb-toggle <?php echo $_o ? 'open' : ''; ?>"
           data-bs-toggle="collapse"
           aria-expanded="<?php echo $_o ? 'true' : 'false'; ?>"
           data-tip="Cashbook">
            <i class="fas fa-book-open sb-ico"></i>
            <span class="sb-txt"> Cashbook <i class="fas fa-chevron-down sb-arr"></i></span>
        </a>
        <div class="collapse <?php echo $_o; ?>" id="sp-cashbook">
            <div class="sb-sub">
                <a href="<?php echo BASE_URL; ?>cashbook/index.php"
                   class="sb-link <?php echo isActiveSub('cashbook', 'index.php'); ?>">
                    <i class="fas fa-book sb-ico"></i><span class="sb-txt"> Cashbook</span>
                </a>
                <a href="<?php echo BASE_URL; ?>cashbook/add_entry.php"
                   class="sb-link <?php echo isActiveSub('cashbook', 'add_entry.php'); ?>">
                    <i class="fas fa-plus-circle sb-ico"></i><span class="sb-txt"> Add Entry</span>
                </a>
                <a href="<?php echo BASE_URL; ?>cashbook/report.php"
                   class="sb-link <?php echo isActiveSub('cashbook', 'report.php'); ?>">
                    <i class="fas fa-chart-line sb-ico"></i><span class="sb-txt"> Cashbook Report</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Expenses ──────────────────────────────────────────────── -->
        <?php if (canView('expenses')): ?>
        <?php $_o = subMenuOpen(dirs: 'expenses'); ?>
        <a href="#sp-expenses"
           class="sb-link sb-toggle <?php echo $_o ? 'open' : ''; ?>"
           data-bs-toggle="collapse"
           aria-expanded="<?php echo $_o ? 'true' : 'false'; ?>"
           data-tip="Expenses">
            <i class="fas fa-receipt sb-ico"></i>
            <span class="sb-txt"> Expenses <i class="fas fa-chevron-down sb-arr"></i></span>
        </a>
        <div class="collapse <?php echo $_o; ?>" id="sp-expenses">
            <div class="sb-sub">
                <a href="<?php echo BASE_URL; ?>expenses/index.php"
                   class="sb-link <?php echo isActiveSub('expenses', 'index.php'); ?>">
                    <i class="fas fa-list sb-ico"></i><span class="sb-txt"> View Expenses</span>
                </a>
                <a href="<?php echo BASE_URL; ?>expenses/add.php"
                   class="sb-link <?php echo isActiveSub('expenses', 'add.php'); ?>">
                    <i class="fas fa-plus-circle sb-ico"></i><span class="sb-txt"> Add Expense</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ════════════════ SECTION: REPORTS ════════════════════════════ -->
        <?php if (canView('reports')): ?>
        <div class="sb-section sb-txt">
            <i class="fas fa-chart-bar sb-section-ico"></i>
            <span>Reports</span>
        </div>

        <?php $_o = subMenuOpen(dirs: 'reports'); ?>
        <a href="#sp-reports"
           class="sb-link sb-toggle <?php echo $_o ? 'open' : ''; ?>"
           data-bs-toggle="collapse"
           aria-expanded="<?php echo $_o ? 'true' : 'false'; ?>"
           data-tip="Reports">
            <i class="fas fa-chart-bar sb-ico"></i>
            <span class="sb-txt"> Reports <i class="fas fa-chevron-down sb-arr"></i></span>
        </a>
        <div class="collapse <?php echo $_o; ?>" id="sp-reports">
            <div class="sb-sub">
                <a href="<?php echo BASE_URL; ?>reports/profit_loss_report.php"
                   class="sb-link <?php echo isActiveSub('reports', 'profit_loss_report.php'); ?>">
                    <i class="fas fa-balance-scale sb-ico"></i><span class="sb-txt"> Profit &amp; Loss</span>
                </a>
                <a href="<?php echo BASE_URL; ?>reports/sales_report.php"
                   class="sb-link <?php echo isActiveSub('reports', 'sales_report.php'); ?>">
                    <i class="fas fa-chart-line sb-ico"></i><span class="sb-txt"> Sales Report</span>
                </a>
                <a href="<?php echo BASE_URL; ?>reports/purchase_report.php"
                   class="sb-link <?php echo isActiveSub('reports', 'purchase_report.php'); ?>">
                    <i class="fas fa-file-invoice sb-ico"></i><span class="sb-txt"> Purchase Report</span>
                </a>
                <a href="<?php echo BASE_URL; ?>reports/stock_report.php"
                   class="sb-link <?php echo isActiveSub('reports', 'stock_report.php'); ?>">
                    <i class="fas fa-boxes sb-ico"></i><span class="sb-txt"> Stock Report</span>
                </a>
                <a href="<?php echo BASE_URL; ?>reports/expiry_report.php"
                   class="sb-link <?php echo isActiveSub('reports', 'expiry_report.php'); ?>">
                    <i class="fas fa-calendar-times sb-ico"></i><span class="sb-txt"> Expiry Report</span>
                </a>
                <a href="<?php echo BASE_URL; ?>reports/customer_report.php"
                   class="sb-link <?php echo isActiveSub('reports', 'customer_report.php'); ?>">
                    <i class="fas fa-user sb-ico"></i><span class="sb-txt"> Customer Report</span>
                </a>
                <a href="<?php echo BASE_URL; ?>reports/supplier_report.php"
                   class="sb-link <?php echo isActiveSub('reports', 'supplier_report.php'); ?>">
                    <i class="fas fa-building sb-ico"></i><span class="sb-txt"> Supplier Report</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ════════════════ SECTION: ADMIN ══════════════════════════════ -->
        <?php if (canView('users') || canView('settings')): ?>
        <div class="sb-section sb-txt">
            <i class="fas fa-shield-halved sb-section-ico"></i>
            <span>Admin</span>
        </div>
        <?php endif; ?>

        <!-- ─── Users (admin only) ────────────────────────────────────── -->
        <?php if (canView('users')): ?>
        <?php $_o = subMenuOpen(dirs: 'users'); ?>
        <a href="#sp-users"
           class="sb-link sb-toggle <?php echo $_o ? 'open' : ''; ?>"
           data-bs-toggle="collapse"
           aria-expanded="<?php echo $_o ? 'true' : 'false'; ?>"
           data-tip="Users">
            <i class="fas fa-users sb-ico"></i>
            <span class="sb-txt"> Users <i class="fas fa-chevron-down sb-arr"></i></span>
        </a>
        <div class="collapse <?php echo $_o; ?>" id="sp-users">
            <div class="sb-sub">
                <a href="<?php echo BASE_URL; ?>users/list.php"
                   class="sb-link <?php echo isActiveSub('users', 'list.php'); ?>">
                    <i class="fas fa-list sb-ico"></i><span class="sb-txt"> User List</span>
                </a>
                <a href="<?php echo BASE_URL; ?>users/add.php"
                   class="sb-link <?php echo isActiveSub('users', 'add.php'); ?>">
                    <i class="fas fa-user-plus sb-ico"></i><span class="sb-txt"> Add User</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Settings (admin only) ─────────────────────────────────── -->
        <?php if (canView('settings')): ?>
        <?php $_o = subMenuOpen(dirs: 'settings'); ?>
        <a href="#sp-settings"
           class="sb-link sb-toggle <?php echo $_o ? 'open' : ''; ?>"
           data-bs-toggle="collapse"
           aria-expanded="<?php echo $_o ? 'true' : 'false'; ?>"
           data-tip="Settings">
            <i class="fas fa-cog sb-ico"></i>
            <span class="sb-txt"> Settings <i class="fas fa-chevron-down sb-arr"></i></span>
        </a>
        <div class="collapse <?php echo $_o; ?>" id="sp-settings">
            <div class="sb-sub">
                <a href="<?php echo BASE_URL; ?>settings/company.php"
                   class="sb-link <?php echo isActiveSub('settings', 'company.php'); ?>">
                    <i class="fas fa-hospital sb-ico"></i><span class="sb-txt"> Pharmacy Info</span>
                </a>
                <!-- Theme Editor: hidden from nav (use theme switcher below) -->
                <a href="<?php echo BASE_URL; ?>settings/theme_editor_v3.php"
                   class="sb-link <?php echo isActiveSub('settings', 'theme_editor_v3.php'); ?>"
                   style="display:none!important; pointer-events:none; visibility:hidden;">
                    <i class="fas fa-palette sb-ico"></i>
                    <span class="sb-txt"> Theme Editor</span>
                </a>
                <!-- Inline Theme Switcher -->
                <div class="sb-link sb-theme-picker">
                    <div class="sb-theme-label">
                        <i class="fas fa-palette sb-ico" style="color:var(--sb-accent,#667eea);"></i>
                        <span class="sb-txt" style="font-weight:600;"> Theme</span>
                    </div>
                    <?php
                    $__tsp = defined('BASE_PATH') ? BASE_PATH . '/includes/theme_switcher.php' : '';
                    if ($__tsp && file_exists($__tsp)) include $__tsp;
                    ?>
                </div>
                <a href="<?php echo BASE_URL; ?>settings/preferences.php"
                   class="sb-link <?php echo isActiveSub('settings', 'preferences.php'); ?>">
                    <i class="fas fa-sliders-h sb-ico"></i><span class="sb-txt"> Preferences</span>
                </a>
                <?php if (isAdmin()): ?>
                <a href="<?php echo BASE_URL; ?>settings/backup.php"
                   class="sb-link <?php echo isActiveSub('settings', 'backup.php'); ?>">
                    <i class="fas fa-database sb-ico"></i><span class="sb-txt"> Backup &amp; Restore</span>
                </a>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>settings/change_password.php"
                   class="sb-link <?php echo isActiveSub('settings', 'change_password.php'); ?>">
                    <i class="fas fa-key sb-ico"></i><span class="sb-txt"> Change Password</span>
                </a>
                <?php if (isAdmin() && canDo('settings', 'reset')): ?>
                <a href="<?php echo BASE_URL; ?>settings/factory_reset.php"
                   class="sb-link sb-danger <?php echo isActiveSub('settings', 'factory_reset.php'); ?>">
                    <i class="fas fa-exclamation-triangle sb-ico"></i>
                    <span class="sb-txt"> Factory Reset</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Logout — pinned at nav bottom ────────────────────────── -->
        <div class="sb-logout-wrap">
            <a href="<?php echo BASE_URL; ?>auth/logout.php"
               class="sb-link sb-link-logout"
               data-tip="Logout">
                <i class="fas fa-sign-out-alt sb-ico"></i>
                <span class="sb-txt"> Logout</span>
            </a>
        </div>

    </nav><!-- /#sbNav -->

    <!-- ── Footer ────────────────────────────────────────────────────── -->
    <div id="sbFooter">
        <small>
            <?php echo htmlspecialchars($_sbPharmacyName); ?>
            <span style="color:var(--sb-accent,#667eea);">v7.0</span>
        </small>
    </div>

</div><!-- /#mainSidebar -->

<!-- Mobile backdrop — tapping closes the sidebar -->
<div id="sbBackdrop" aria-hidden="true"></div>


<!-- ══════════════════════════════════════════════════════════════════════════
     PHASE 4 — SIDEBAR CSS
     ══════════════════════════════════════════════════════════════════════════ -->
<style>
/* ── CSS Variables ───────────────────────────────────────────────────────── */
:root {
    /* Phase 6: resolved from Theme Engine vars with PHP DB fallbacks */
    --sb-bg      : var(--sidebar-color,  <?php echo htmlspecialchars($_sbBg); ?>);
    --sb-accent  : var(--accent-color,   <?php echo htmlspecialchars($_sbAccent); ?>);
    --sb-primary : var(--primary-color,  <?php echo htmlspecialchars($_sbPrimary); ?>);
    --sb-bg-text : var(--text-color,     #e2e8f0);
    --sb-w       : 260px;
    --sb-w-mini  : 62px;
    --sb-trans   : width .25s ease, transform .25s ease;
}

/* ── Sidebar Container ───────────────────────────────────────────────────── */
#mainSidebar {
    position       : fixed;
    top            : 0;
    left           : 0;
    width          : var(--sb-w);
    height         : 100vh;
    display        : flex;
    flex-direction : column;
    overflow       : hidden;
    z-index        : 1040;
    transition     : var(--sb-trans);
    box-shadow     : 2px 0 20px rgba(0,0,0,.35);
}

/* ── Main content push ───────────────────────────────────────────────────── */
.main-content {
    margin-left : var(--sb-w);
    transition  : margin-left .25s ease;
}

/* ── Brand Header ────────────────────────────────────────────────────────── */
#sbHeader {
    flex-shrink   : 0;
    border-bottom : 1px solid rgba(255,255,255,.1);
    padding-bottom: 4px;
}
#sbBrandFull {
    text-align : center;
    padding    : 14px 12px 6px;
}
#sbBrandMini {
    display    : none;
    text-align : center;
    padding    : 14px 0 6px;
}
.sb-pharmacy-logo {
    max-height : 48px;
    max-width  : 100%;
    object-fit : contain;
}
.sb-name {
    color         : #fff;
    font-weight   : 700;
    font-size     : .92rem;
    margin-top    : 6px;
    white-space   : nowrap;
    overflow      : hidden;
    text-overflow : ellipsis;
    max-width     : 220px;
    margin-left   : auto;
    margin-right  : auto;
}
.sb-tagline {
    color         : rgba(255,255,255,.45);
    font-size     : .7rem;
    margin-top    : 2px;
    display       : block;
    white-space   : nowrap;
    overflow      : hidden;
    text-overflow : ellipsis;
}

/* Controls row */
#sbCtrlRow {
    display         : flex;
    justify-content : flex-end;
    gap             : 4px;
    padding         : 0 8px 6px;
}
.sb-ctrl-btn {
    background      : rgba(255,255,255,.07);
    border          : 1px solid rgba(255,255,255,.12);
    color           : rgba(255,255,255,.65);
    border-radius   : 6px;
    width           : 28px;
    height          : 28px;
    display         : flex;
    align-items     : center;
    justify-content : center;
    cursor          : pointer;
    font-size       : .72rem;
    transition      : background .15s ease, color .15s ease;
    flex-shrink     : 0;
    outline         : none;
}
.sb-ctrl-btn:hover       { background: rgba(255,255,255,.16); color: #fff; }
.sb-ctrl-btn.sb-locked   { background: var(--sb-accent); border-color: var(--sb-accent); color: #fff; }

/* ── User Strip ──────────────────────────────────────────────────────────── */
#sbUserFull {
    flex-shrink   : 0;
    display       : flex;
    align-items   : center;
    gap           : 10px;
    padding       : 8px 12px;
    border-bottom : 1px solid rgba(255,255,255,.1);
}
#sbUserMini {
    display       : none;
    text-align    : center;
    padding       : 6px 0;
    flex-shrink   : 0;
    border-bottom : 1px solid rgba(255,255,255,.1);
}
.sb-avatar {
    width           : 36px;
    height          : 36px;
    border-radius   : 50%;
    display         : flex;
    align-items     : center;
    justify-content : center;
    font-weight     : 700;
    font-size       : .82rem;
    color           : #fff;
    flex-shrink     : 0;
}
.sb-avatar-sm      { width: 30px; height: 30px; font-size: .72rem; display: inline-flex; }
.sb-avatar-img     { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 2px solid var(--sb-accent); }
.sb-avatar-img-sm  { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 2px solid var(--sb-accent); }
.sb-user-info      { overflow: hidden; }
.sb-user-info strong {
    display       : block;
    color         : #fff;
    font-size     : .83rem;
    white-space   : nowrap;
    overflow      : hidden;
    text-overflow : ellipsis;
    max-width     : 160px;
}
.sb-role-badge {
    display       : inline-block;
    background    : var(--sb-accent);
    color         : #fff;
    border-radius : 20px;
    font-size     : .62rem;
    padding       : 1px 8px;
    margin-top    : 2px;
    font-weight   : 600;
}

/* ── NAV — scrollable ────────────────────────────────────────────────────── */
#sbNav {
    flex           : 1 1 0;
    min-height     : 0;
    overflow-y     : auto;
    overflow-x     : hidden;
    padding        : 8px 6px;
    display        : flex;
    flex-direction : column;
    scrollbar-width: thin;
    scrollbar-color: transparent transparent;
    transition     : scrollbar-color .2s;
}
#sbNav:hover                        { scrollbar-color: rgba(255,255,255,.28) transparent; }
#sbNav::-webkit-scrollbar           { width: 5px; }
#sbNav::-webkit-scrollbar-track     { background: transparent; }
#sbNav::-webkit-scrollbar-thumb     { background: transparent; border-radius: 4px; }
#sbNav:hover::-webkit-scrollbar-thumb { background: rgba(255,255,255,.28); }
#sbNav::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.5); }

/* ── Nav Links ───────────────────────────────────────────────────────────── */
.sb-link {
    display         : flex;
    align-items     : center;
    padding         : 8px 10px;
    border-radius   : 7px;
    color           : rgba(255,255,255,.82);
    text-decoration : none;
    font-size       : .875rem;
    white-space     : nowrap;
    transition      : background .15s ease, color .15s ease, box-shadow .15s ease;
    margin-bottom   : 1px;
    line-height     : 1.2;
    width           : 100%;
    box-sizing      : border-box;
}
.sb-link:hover {
    background : rgba(255,255,255,.09);
    color      : #fff;
    text-decoration: none;
}

/* ── Active state — gradient ─────────────────────────────────────────────── */
.sb-link.active {
    background : linear-gradient(135deg, var(--sb-accent) 0%, var(--sb-primary) 100%);
    color      : #fff !important;
    box-shadow : 0 2px 10px rgba(0,0,0,.3);
    font-weight: 600;
}

/* ── Danger links ────────────────────────────────────────────────────────── */
.sb-link.sb-danger         { color: rgba(255,130,120,.85); }
.sb-link.sb-danger:hover   { background: rgba(239,68,68,.15); color: #fca5a5; }
.sb-link.sb-link-logout:hover { background: rgba(239,68,68,.12); color: #fca5a5; }
.sb-ico-danger             { color: rgba(239,68,68,.8) !important; }
.sb-link:hover .sb-ico-danger { color: #f87171 !important; }

/* ── Icon ────────────────────────────────────────────────────────────────── */
.sb-ico {
    display     : inline-block;
    width       : 20px;
    min-width   : 20px;
    text-align  : center;
    flex-shrink : 0;
    font-size   : .95rem;
    line-height : 1;
}

/* ── Text span ───────────────────────────────────────────────────────────── */
.sb-txt {
    flex          : 1;
    min-width     : 0;
    display       : inline-flex;
    align-items   : center;
    overflow      : hidden;
    text-overflow : ellipsis;
    white-space   : nowrap;
}

/* ── Chevron arrow in toggle links ───────────────────────────────────────── */
.sb-arr {
    font-size   : .65rem;
    margin-left : auto;
    flex-shrink : 0;
    transition  : transform .2s ease;
    display     : inline-block;
    padding-left: 6px;
}
.sb-toggle.open .sb-arr,
.sb-toggle[aria-expanded="true"] .sb-arr { transform: rotate(180deg); }

/* ── Sub-menu ────────────────────────────────────────────────────────────── */
.sb-sub {
    padding-left  : 14px;
    border-left   : 2px solid rgba(255,255,255,.12);
    margin-left   : 14px;
    margin-bottom : 4px;
}
.sb-sub .sb-link {
    font-size  : .82rem;
    padding    : 6px 10px;
    opacity    : .82;
    transition : background .15s ease, color .15s ease, opacity .15s ease;
}
.sb-sub .sb-link:hover { opacity: 1; }
.sb-sub .sb-link.active {
    opacity    : 1;
    background : linear-gradient(135deg, var(--sb-accent) 0%, var(--sb-primary) 100%);
    color      : #fff !important;
    box-shadow : 0 2px 8px rgba(0,0,0,.25);
}

/* Theme picker inside sub-menu */
.sb-theme-picker {
    flex-direction: column;
    align-items   : flex-start;
    cursor        : default;
    padding-bottom: 4px;
}
.sb-theme-label {
    display    : flex;
    align-items: center;
    gap        : 6px;
    margin-bottom: 4px;
}

/* ── Section Labels (Management / Reports / Admin) ───────────────────────── */
.sb-section {
    display        : flex;
    align-items    : center;
    gap            : 6px;
    font-size      : .63rem;
    letter-spacing : .8px;
    text-transform : uppercase;
    color          : rgba(255,255,255,.5);
    font-weight    : 700;
    padding        : 14px 10px 4px;
    user-select    : none;
}
.sb-section-ico { font-size: .65rem; opacity: .7; }

/* ── Logout wrap — bottom of nav ─────────────────────────────────────────── */
.sb-logout-wrap {
    margin-top    : auto;
    padding-top   : 8px;
    border-top    : 1px solid rgba(255,255,255,.1);
}

/* ── Footer ──────────────────────────────────────────────────────────────── */
#sbFooter {
    flex-shrink : 0;
    text-align  : center;
    padding     : 6px;
    border-top  : 1px solid rgba(255,255,255,.1);
    color       : rgba(255,255,255,.32);
    font-size   : .68rem;
}

/* ══════════════════════════════════════════════════
   COLLAPSED STATE (icon-rail mode)
══════════════════════════════════════════════════ */
#mainSidebar.sb-col { width: var(--sb-w-mini); }

body.sb-col-body .main-content { margin-left: var(--sb-w-mini); }

/* Hide text/sections in collapsed mode */
#mainSidebar.sb-col .sb-txt,
#mainSidebar.sb-col .sb-section,
#mainSidebar.sb-col #sbBrandFull,
#mainSidebar.sb-col #sbUserFull,
#mainSidebar.sb-col #sbFooter,
/* Collapsed: hide nested panels until user expands sidebar via toggle click */
#mainSidebar.sb-col .collapse { display: none !important; }
/* After expand-from-toggle, allow collapses */
#mainSidebar:not(.sb-col) .collapse.show { display: block !important; }

/* Show mini elements */
#mainSidebar.sb-col #sbBrandMini,
#mainSidebar.sb-col #sbUserMini  { display: block !important; }

/* Centre icons in rail mode */
#mainSidebar.sb-col #sbNav {
    padding     : 6px 4px;
    align-items : stretch;
}
#mainSidebar.sb-col .sb-link {
    justify-content : center;
    padding         : 10px 0;
    width           : 100%;
    text-align      : center;
}
#mainSidebar.sb-col .sb-ico {
    font-size : 1.05rem;
    width     : auto;
    min-width : auto;
    margin    : 0;
}
#mainSidebar.sb-col #sbCtrlRow { justify-content: center; }
#mainSidebar.sb-col #sbColIco  { transform: rotate(180deg); }

/* Tooltip on collapsed icons */
#mainSidebar.sb-col .sb-link[data-tip]       { position: relative; }
#mainSidebar.sb-col .sb-link[data-tip]:hover::after {
    content       : attr(data-tip);
    position      : fixed;
    left          : calc(var(--sb-w-mini) + 8px);
    background    : var(--card-color, #1e2533);
    color         : #fff;
    padding       : 5px 12px;
    border-radius : 6px;
    font-size     : .78rem;
    white-space   : nowrap;
    pointer-events: none;
    z-index       : 9999;
    box-shadow    : 0 3px 12px rgba(0,0,0,.4);
    border        : 1px solid rgba(255,255,255,.1);
}

/* ══════════════════════════════════════════════════
   RESPONSIVE — mobile drawer mode
══════════════════════════════════════════════════ */
@media (max-width: 991.98px) {
    #mainSidebar {
        transform : translateX(-100%);
        width     : var(--sb-w) !important;
        z-index   : 1050;
    }
    #mainSidebar.sb-mobile-open { transform: translateX(0); }
    .main-content { margin-left: 0 !important; }

    /* Mobile backdrop */
    #sbBackdrop {
        display    : none;
        position   : fixed;
        inset      : 0;
        background : rgba(0,0,0,.55);
        z-index    : 1045;
        cursor     : pointer;
    }
    #sbBackdrop.active { display: block; }
}
</style>



<!-- Global Theme Modal -->
<div class="modal fade" id="globalThemeModal" tabindex="-1" aria-labelledby="globalThemeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius:14px;overflow:hidden;">
      <div class="modal-header" style="background:var(--pharmacy-primary,#0d9488);color:#fff;">
        <h5 class="modal-title" id="globalThemeModalLabel"><i class="fas fa-palette me-2"></i>Choose Theme</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" style="background:#f8fafc;">
        <p class="text-muted small mb-3">Applies on <strong>all pages</strong> and is saved to your account/session.</p>
        <div id="globalThemeGrid" class="row g-2"></div>
        <div id="globalThemeStatus" class="small mt-2 text-success" style="display:none;"></div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     PHASE 4 — SIDEBAR JS
     Handles: collapse, lock, mobile open/close, margin sync, resize
     ══════════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    var SB  = document.getElementById('mainSidebar');
    var COL = document.getElementById('sbColBtn');
    var LOC = document.getElementById('sbLockBtn');
    var ICI = document.getElementById('sbColIco');
    var ILI = document.getElementById('sbLockIco');
    var BKD = document.getElementById('sbBackdrop');

    if (!SB || !COL || !LOC) return; // guard: sidebar elements missing

    var K_COL  = 'pharm_sb_col';   // localStorage key
    var K_LOCK = 'pharm_sb_lock';  // localStorage key

    var locked    = localStorage.getItem(K_LOCK) === 'true';
    var collapsed = localStorage.getItem(K_COL)  === 'true';

    // If not locked, always open on page load
    if (!locked) collapsed = false;

    // ── Sync all layout-dependent elements ───────────────────────────────
    function syncMargins(animate) {
        var isMobile = window.innerWidth < 992;
        var target   = isMobile ? '0px'
                     : collapsed ? 'var(--sb-w-mini, 62px)'
                     : 'var(--sb-w, 260px)';

        // Navbar / topbar
        document.querySelectorAll(
            '.navbar, .top-navbar, #topNavbar, #topbar'
        ).forEach(function (el) { el.style.marginLeft = target; });

        // Breadcrumb bar
        document.querySelectorAll(
            '.page-breadcrumb, #pageBreadcrumbBar, .breadcrumb-bar'
        ).forEach(function (el) { el.style.marginLeft = target; });
    }

    // ── Render sidebar state ──────────────────────────────────────────────
    function render(animate) {
        if (!animate) SB.style.transition = 'none';

        // Remove pre-render anti-flash style once JS takes control
        var pre = document.getElementById('sb-prerender-col');
        if (pre) pre.parentNode.removeChild(pre);

        if (collapsed) {
            SB.classList.add('sb-col');
            document.body.classList.add('sb-col-body');
        } else {
            SB.classList.remove('sb-col');
            document.body.classList.remove('sb-col-body');
        }

        // Lock button
        if (locked) {
            LOC.classList.add('sb-locked');
            ILI.className = 'fas fa-lock';
            LOC.title     = 'Sidebar locked — click to unlock';
        } else {
            LOC.classList.remove('sb-locked');
            ILI.className = 'fas fa-lock-open';
            LOC.title     = 'Sidebar unlocked — click to lock';
        }

        syncMargins(animate);

        if (!animate) {
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    SB.style.transition = '';
                });
            });
        }
    }

    function save() {
        localStorage.setItem(K_COL,  String(collapsed));
        localStorage.setItem(K_LOCK, String(locked));
    }

    // ── Collapse button ────────────────────────────────────────────────────
    COL.addEventListener('click', function (e) {
        e.stopPropagation();
        collapsed = !collapsed;
        // Closing collapses: hide any open submenus so they do not flash
        if (collapsed) {
            SB.querySelectorAll('.collapse.show').forEach(function (c) {
                c.classList.remove('show');
            });
            SB.querySelectorAll('.sb-toggle.open').forEach(function (a) {
                a.classList.remove('open');
                a.setAttribute('aria-expanded', 'false');
            });
        }
        save();
        render(true);
    });

    // ── Lock button ────────────────────────────────────────────────────────
    LOC.addEventListener('click', function (e) {
        e.stopPropagation();
        locked = !locked;
        save();
        render(true);
    });

    // ── FIX: submenu while collapsed — expand sidebar first, then open panel ─
    SB.querySelectorAll('a.sb-toggle[data-bs-toggle="collapse"]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (!collapsed) return; // normal Bootstrap behaviour
            e.preventDefault();
            e.stopPropagation();
            var targetSel = link.getAttribute('href') || link.getAttribute('data-bs-target');
            collapsed = false;
            save();
            render(true);
            setTimeout(function () {
                var panel = targetSel ? document.querySelector(targetSel) : null;
                if (panel) {
                    panel.classList.add('show');
                    link.classList.add('open');
                    link.setAttribute('aria-expanded', 'true');
                    try { panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (err) {}
                }
            }, 220);
        }, true);
    });

    // ── Theme modal: build swatches + apply ────────────────────────────────
    (function setupThemeModal() {
        var grid = document.getElementById('globalThemeGrid');
        if (!grid) return;
        var themes = [
            {id:'default', name:'Default Blue', c:'#3b82f6'},
            {id:'light_green_medical', name:'Medical Green', c:'#0d9488'},
            {id:'pharmacy_teal', name:'Pharmacy Teal', c:'#14b8a6'},
            {id:'dark_professional', name:'Dark Pro', c:'#1e293b'},
            {id:'soft_purple', name:'Soft Purple', c:'#8b5cf6'},
            {id:'warm_orange', name:'Warm Orange', c:'#f97316'},
            {id:'clean_gray', name:'Clean Gray', c:'#64748b'},
            {id:'clinical_cyan', name:'Clinical Cyan', c:'#06b6d4'}
        ];
        // Prefer cards already on page from theme_switcher
        var existing = document.querySelectorAll('[data-theme-id], [data-theme-swatch]');
        if (existing.length) {
            grid.innerHTML = '';
            existing.forEach(function (el) {
                var id = el.getAttribute('data-theme-id') || el.getAttribute('data-theme-swatch');
                if (!id) return;
                var name = (el.getAttribute('title') || el.textContent || id).trim().slice(0, 40);
                var col = el.style.background || '#0d9488';
                var d = document.createElement('div');
                d.className = 'col-6 col-md-4 col-lg-3';
                d.innerHTML = '<button type="button" class="btn w-100 theme-pick-btn" data-tid="'+id+'" style="border:2px solid #e2e8f0;border-radius:12px;padding:12px;text-align:left;background:#fff;">'
                    + '<span style="display:inline-block;width:18px;height:18px;border-radius:50%;background:'+col+';vertical-align:middle;margin-right:8px;"></span>'
                    + '<span style="font-weight:600;font-size:.85rem;">'+name+'</span></button>';
                grid.appendChild(d);
            });
        } else {
            grid.innerHTML = themes.map(function (th) {
                return '<div class="col-6 col-md-4 col-lg-3"><button type="button" class="btn w-100 theme-pick-btn" data-tid="'+th.id+'" style="border:2px solid #e2e8f0;border-radius:12px;padding:12px;text-align:left;background:#fff;">'
                    + '<span style="display:inline-block;width:18px;height:18px;border-radius:50%;background:'+th.c+';vertical-align:middle;margin-right:8px;"></span>'
                    + '<span style="font-weight:600;font-size:.85rem;">'+th.name+'</span></button></div>';
            }).join('');
        }

        function getBase() {
            if (window.BASE_URL) return String(window.BASE_URL).replace(/\/?$/, '/');
            var m = document.querySelector('meta[name="base-url"]');
            if (m && m.content) return m.content.replace(/\/?$/, '/');
            var match = location.pathname.match(/^(.*?\/pharmacy_software\/)/i);
            return match ? (location.origin + match[1]) : (location.origin + '/');
        }

        function applyThemeId(themeId) {
            var st = document.getElementById('globalThemeStatus');
            if (st) { st.style.display = 'block'; st.textContent = 'Applying…'; }
            var body = new URLSearchParams();
            body.set('theme_id', themeId);
            fetch(getBase() + 'ajax/apply_theme.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data || !data.success) {
                    if (st) st.textContent = (data && data.message) ? data.message : 'Failed';
                    return;
                }
                if (data.css_vars) {
                    var old = document.getElementById('theme-engine-vars');
                    if (old) old.remove();
                    var wrap = document.createElement('div');
                    wrap.innerHTML = data.css_vars;
                    var style = wrap.querySelector('style') || wrap.firstChild;
                    if (style) document.head.appendChild(style);
                }
                document.body.setAttribute('data-active-theme', themeId);
                if (data.is_dark) document.body.classList.add('dark-mode');
                else document.body.classList.remove('dark-mode');
                localStorage.setItem('pharm_theme_id', themeId);
                if (st) st.textContent = 'Theme saved: ' + (data.theme_name || themeId);
                // keep modal open briefly then close
                setTimeout(function () {
                    var modalEl = document.getElementById('globalThemeModal');
                    if (modalEl && window.bootstrap) {
                        var inst = bootstrap.Modal.getInstance(modalEl);
                        if (inst) inst.hide();
                    }
                }, 600);
            }).catch(function (err) {
                if (st) st.textContent = 'Network error applying theme';
            });
        }

        grid.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-tid]');
            if (!btn) return;
            applyThemeId(btn.getAttribute('data-tid'));
        });

        // Also expose for theme_switcher onclick="applyTheme(...)"
        window.applyTheme = function (id) { applyThemeId(id); };
        if (typeof window.applyThemeAjax !== 'function') {
            window.applyThemeAjax = window.applyTheme;
        }
    })();

    // ── Initial paint (no transition flash) ───────────────────────────────
    render(false);

    // ── Re-sync on window resize ───────────────────────────────────────────
    window.addEventListener('resize', function () { render(false); });

    // ── Mobile toggle ──────────────────────────────────────────────────────
    // Matches multiple button IDs used across pages
    ['sidebarToggleBtn','sidebarToggle','sbToggle','mobileSidebarBtn'].forEach(function (id) {
        var btn = document.getElementById(id);
        if (!btn) return;
        btn.addEventListener('click', function () {
            SB.classList.toggle('sb-mobile-open');
            if (BKD) BKD.classList.toggle('active');
        });
    });

    // ── Backdrop close ─────────────────────────────────────────────────────
    if (BKD) {
        BKD.addEventListener('click', function () {
            SB.classList.remove('sb-mobile-open');
            BKD.classList.remove('active');
        });
    }

    // ── Legacy backdrop (topbar.php may also create one) ──────────────────
    var legacyBkd = document.getElementById('sidebarBackdrop');
    if (legacyBkd && legacyBkd !== BKD) {
        legacyBkd.addEventListener('click', function () {
            SB.classList.remove('sb-mobile-open');
            SB.classList.remove('sidebar-open');
            legacyBkd.classList.remove('active');
            if (BKD) BKD.classList.remove('active');
        });
    }

})();
</script>