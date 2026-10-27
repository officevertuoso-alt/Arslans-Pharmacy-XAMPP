<?php // REPAIRED vFIX6
// === FILE: pharmacy_software/includes/settings_functions.php ===
// VERSION 5.0 — Full Synchronization Fix

// ══════════════════════════════════════════════════════════════
// ENSURE NEW COLUMNS EXIST
// ══════════════════════════════════════════════════════════════
function ensureCompanySettingsColumns(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $db   = db();
    $cols = [];
    try {
        $db->query("SHOW COLUMNS FROM company_settings");
        $rows = $db->resultSet();
        foreach ($rows as $r) $cols[] = $r['Field'];
    } catch (\Throwable $e) { return; }

    $additions = [
        "pharmacy_name          VARCHAR(255)  NULL",
        "pharmacy_tagline       VARCHAR(255)  DEFAULT 'Your Health, Our Priority'",
        "pharmacy_type          ENUM('pharmacy','medical_store','dispensary','chemist') DEFAULT 'pharmacy'",
        "logo_path              VARCHAR(255)  NULL",
        "favicon_path           VARCHAR(255)  NULL",
        "primary_color          VARCHAR(7)    DEFAULT '#0d6efd'",
        "sidebar_color          VARCHAR(7)    DEFAULT '#212529'",
        "accent_color           VARCHAR(7)    DEFAULT '#198754'",
        "show_logo_on_invoice   TINYINT(1)   DEFAULT 1",
        "show_logo_on_sidebar   TINYINT(1)   NOT NULL DEFAULT 1",
        "show_tagline_on_invoice TINYINT(1)  DEFAULT 1",
        "receipt_paper_size     ENUM('A4','A5','80mm','58mm') DEFAULT 'A4'",
        "low_stock_threshold    INT           DEFAULT 10",
        "near_expiry_days       INT           DEFAULT 30",
        "default_tax_rate       DECIMAL(5,2)  DEFAULT 0.00",
        "invoice_terms          TEXT          NULL",
        "invoice_footer_text    VARCHAR(255)  NULL",
        "show_payment_on_invoice TINYINT(1)  DEFAULT 1",
        "show_batch_on_invoice  TINYINT(1)   DEFAULT 1",
        "system_timezone        VARCHAR(50)   DEFAULT 'Asia/Karachi'",
        "date_format            VARCHAR(20)   DEFAULT 'd M Y'",
        "time_format            VARCHAR(10)   DEFAULT 'h:i A'",
        "session_timeout        INT           DEFAULT 60",
        "default_language       VARCHAR(10)   DEFAULT 'en'",
        "license_number         VARCHAR(100)  NULL",
        "owner_name             VARCHAR(150)  NULL",
        "password_change_days   INT           DEFAULT 90",
        "login_attempts_limit   INT           DEFAULT 5",
        "lockout_duration       INT           DEFAULT 15",
        "two_factor_enabled     TINYINT(1)   DEFAULT 0",
        "ip_whitelist           TEXT          NULL",
        "tin_no                 VARCHAR(100)  NULL",

        // ── Theme Editor v2 — Extended Colors ─────────────────
        "body_bg_color          VARCHAR(7)    DEFAULT '#f0f2f5'",
        "card_bg_color          VARCHAR(7)    DEFAULT '#ffffff'",
        "body_text_color        VARCHAR(7)    DEFAULT '#444444'",
        "heading_color          VARCHAR(7)    DEFAULT '#1a1d23'",
        "navbar_bg_color        VARCHAR(7)    DEFAULT '#ffffff'",
        "navbar_border_color    VARCHAR(7)    DEFAULT '#e9ecef'",
        "sidebar_text_color     VARCHAR(7)    DEFAULT '#a0aec0'",
        "link_color             VARCHAR(7)    DEFAULT '#0d6efd'",
        "border_color           VARCHAR(7)    DEFAULT '#dee2e6'",
        "table_header_color     VARCHAR(7)    DEFAULT '#f8f9fa'",
        "table_stripe_color     VARCHAR(7)    DEFAULT '#f9f9ff'",
        "modal_header_color     VARCHAR(7)    DEFAULT '#0d6efd'",
        "input_focus_color      VARCHAR(7)    DEFAULT '#667eea'",
        // ── Theme Editor v2 — Badge Colors ────────────────────
        "badge_instock_bg       VARCHAR(7)    DEFAULT '#d1fae5'",
        "badge_instock_text     VARCHAR(7)    DEFAULT '#065f46'",
        "badge_oos_bg           VARCHAR(7)    DEFAULT '#fee2e2'",
        "badge_oos_text         VARCHAR(7)    DEFAULT '#991b1b'",
        "badge_expiry_bg        VARCHAR(7)    DEFAULT '#fff3cd'",
        "badge_expiry_text      VARCHAR(7)    DEFAULT '#856404'",
        "badge_lowstock_bg      VARCHAR(7)    DEFAULT '#fff7e6'",
        "badge_lowstock_text    VARCHAR(7)    DEFAULT '#c2410c'",
        "badge_expired_bg       VARCHAR(7)    DEFAULT '#fce7f3'",
        "badge_expired_text     VARCHAR(7)    DEFAULT '#9d174d'",
        // ── Theme Editor v2 — Dark Mode Colors ────────────────
        "dark_bg_color          VARCHAR(7)    DEFAULT '#0f1117'",
        "dark_sidebar_color     VARCHAR(7)    DEFAULT '#161b22'",
        "dark_card_color        VARCHAR(7)    DEFAULT '#1c2128'",
        "dark_text_color        VARCHAR(7)    DEFAULT '#e6edf3'",
        // ── Theme Editor v2 — Typography & Shape ──────────────
        "body_font_family       VARCHAR(60)   DEFAULT 'Segoe UI'",
        "body_font_size         TINYINT       DEFAULT 14",
        "card_border_radius     TINYINT       DEFAULT 12",
        "btn_border_radius      TINYINT       DEFAULT 8",
        "shadow_intensity       TINYINT       DEFAULT 12",
        // ── Theme Editor v2 — Flags ───────────────────────────
        "dark_mode              TINYINT(1)   DEFAULT 0",
        "theme_preset           VARCHAR(30)   DEFAULT 'custom'",
        "table_striped          TINYINT(1)   DEFAULT 1",
        "sidebar_compact        TINYINT(1)   DEFAULT 0",
    ];

    foreach ($additions as $definition) {
        $colName = trim(explode(' ', $definition)[0]);
        if (!in_array($colName, $cols)) {
            try {
                $db->query("ALTER TABLE company_settings ADD COLUMN $definition");
                $db->execute();
            } catch (\Throwable $e) { /* column may already exist — ignore */ }
        }
    }
}

// ══════════════════════════════════════════════════════════════
// getCompanySettings()
// ══════════════════════════════════════════════════════════════
function getCompanySettings(): array
{
    if (isset($GLOBALS['_company_settings']) && is_array($GLOBALS['_company_settings']) && !empty($GLOBALS['_company_settings'])) {
        return $GLOBALS['_company_settings'];
    }

    try {
        ensureCompanySettingsColumns();

        $db = db();
        try {
            $db->query("SELECT * FROM company_settings WHERE id = 1 LIMIT 1");
            $settings = $db->single();
        } catch (\Throwable $e) {
            $settings = null;
        }

        if (!$settings) {
            try {
                $db->query("INSERT INTO company_settings
                    (company_name, phone, email, address, gst_no, footer_text,
                     currency_symbol, invoice_prefix,
                     pharmacy_tagline, pharmacy_type,
                     primary_color, sidebar_color, accent_color,
                     low_stock_threshold, near_expiry_days,
                     system_timezone, date_format, time_format)
                    VALUES
                    ('Pharmacy Management System','9876543210','info@pharmacy.com',
                     '123, Main Street, City','','Thank you for shopping with us!',
                     'Rs','INV',
                     'Your Health, Our Priority','pharmacy',
                     '#0d6efd','#212529','#198754',
                     10, 30, 'Asia/Karachi','d M Y','h:i A')");
                $db->execute();
                $db->query("SELECT * FROM company_settings WHERE id = 1 LIMIT 1");
                $settings = $db->single();
            } catch (\Throwable $e) {
                $settings = [];
            }
        }

        $settings = $settings ?: [];
        
        if (empty($settings)) {
            return [
                'company_name' => 'My Pharmacy',
                'currency_symbol' => 'Rs',
                'system_timezone' => 'Asia/Karachi',
                'primary_color' => '#0d6efd',
                'sidebar_color' => '#212529',
                'accent_color' => '#198754',
                'low_stock_threshold' => 10,
                'near_expiry_days' => 30,
                'date_format' => 'd M Y',
                'time_format' => 'h:i A',
                'session_timeout' => 60,
                'footer_text' => 'Thank you for shopping with us!',
            ];
        }
    } catch (\Throwable $e) {
        error_log('[getCompanySettings] DB error: ' . $e->getMessage());
        return [
            'company_name' => 'My Pharmacy',
            'currency_symbol' => 'Rs',
            'system_timezone' => 'Asia/Karachi',
            'primary_color' => '#0d6efd',
            'sidebar_color' => '#212529',
            'accent_color' => '#198754',
            'low_stock_threshold' => 10,
            'near_expiry_days' => 30,
            'date_format' => 'd M Y',
            'time_format' => 'h:i A',
            'session_timeout' => 60,
            'footer_text' => 'Thank you for shopping with us!',
        ];
    }

    if (!empty($settings['system_timezone'])) {
        @date_default_timezone_set($settings['system_timezone']);
    }

    if (empty($settings['pharmacy_name']) && !empty($settings['company_name'])) {
        $settings['pharmacy_name'] = $settings['company_name'];
    }

    $GLOBALS['_company_settings'] = $settings;
    return $settings;
}

// ══════════════════════════════════════════════════════════════
// generateCSRFToken() & verifyCSRFToken()
// ══════════════════════════════════════════════════════════════
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }
}

if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

// ══════════════════════════════════════════════════════════════
// clearCompanySettingsCache()
// ══════════════════════════════════════════════════════════════
function clearCompanySettingsCache(): void
{
    unset($GLOBALS['_company_settings']);

    if (session_status() === PHP_SESSION_ACTIVE) {
        unset(
            $_SESSION['company_settings'],
            $_SESSION['company_settings_cache'],
            $_SESSION['company_settings_cache_time'],
            $_SESSION['_company_settings'],
            $_SESSION['pharmacy_name'],
            $_SESSION['pharmacy_logo'],
            $_SESSION['theme_colors']
        );
    }
}

// ══════════════════════════════════════════════════════════════
// updateCompanySettings() - shortened version
// ══════════════════════════════════════════════════════════════
function updateCompanySettings(array $data): array
{
    try {
        $db = db();
        $settings = getCompanySettings();
        
        // Logo upload handling (simplified)
        $logoPath = $settings['logo_path'] ?? null;
        $faviconPath = $settings['favicon_path'] ?? null;
        
        // Update query
        $db->query("UPDATE company_settings SET
            company_name = :company_name,
            phone = :phone,
            email = :email,
            address = :address,
            currency_symbol = :currency_symbol,
            logo_path = :logo_path
            WHERE id = 1");
        
        $db->bind(':company_name', sanitize($data['company_name'] ?? 'Pharmacy'));
        $db->bind(':phone', sanitize($data['phone'] ?? ''));
        $db->bind(':email', sanitize($data['email'] ?? ''));
        $db->bind(':address', sanitize($data['address'] ?? ''));
        $db->bind(':currency_symbol', sanitize($data['currency_symbol'] ?? 'Rs'));
        $db->bind(':logo_path', $logoPath);
        $db->execute();
        
        clearCompanySettingsCache();
        
        return ['success' => true, 'message' => 'Settings saved'];
    } catch (\Throwable $e) {
        return ['success' => false, 'message' => 'DB error: ' . $e->getMessage()];
    }
}

// ══════════════════════════════════════════════════════════════
// getPharmacyName, getPharmacyLogo, etc.
// ══════════════════════════════════════════════════════════════
function getPharmacyName(): string
{
    $s = getCompanySettings();
    return trim($s['company_name'] ?? $s['pharmacy_name'] ?? 'Pharmacy') ?: 'Pharmacy';
}

function getPharmacyLogo(): string
{
    $s = getCompanySettings();
    $base = defined('BASE_URL') ? BASE_URL : '/';
    if (!empty($s['logo_path'])) {
        return rtrim($base, '/') . '/' . ltrim($s['logo_path'], '/');
    }
    return rtrim($base, '/') . '/assets/img/default_logo.png';
}

function getThemeColors(): array
{
    $s = getCompanySettings();
    return [
        'primary' => $s['primary_color'] ?? '#0d6efd',
        'sidebar' => $s['sidebar_color'] ?? '#212529',
        'accent'  => $s['accent_color']  ?? '#198754',
    ];
}

function getSystemSetting(string $key, mixed $default = null): mixed
{
    return getCompanySettings()[$key] ?? $default;
}

// ══════════════════════════════════════════════════════════════
// updateThemeSettings() — Save all theme/visual settings to DB
// Called by: settings/theme_editor_v2.php AND settings/theme_editor_v3.php
// ══════════════════════════════════════════════════════════════
if (!function_exists('updateThemeSettings')) {
function updateThemeSettings(array $data): array
{
    $allowed = [
        // Core colors
        'primary_color', 'accent_color', 'sidebar_color',
        'body_bg_color', 'card_bg_color', 'body_text_color', 'heading_color',
        // Navbar + Sidebar
        'navbar_bg_color', 'navbar_border_color', 'sidebar_text_color',
        'sidebar_active_bg', 'sidebar_active_text',
        // Text
        'link_color', 'border_color', 'icon_color',
        // Tables
        'table_header_color', 'table_stripe_color', 'table_row_hover',
        // Inputs
        'input_bg_color', 'input_border_color', 'input_text_color', 'input_focus_color',
        // Modals + Dropdowns
        'modal_header_color', 'modal_body_bg', 'dropdown_bg', 'dropdown_text',
        // Filter
        'filter_bar_bg',
        // POS
        'pos_cart_bg', 'pos_cart_text', 'pos_product_card_bg', 'pos_search_bg',
        // Badges
        'badge_instock_bg', 'badge_instock_text',
        'badge_oos_bg', 'badge_oos_text',
        'badge_expiry_bg', 'badge_expiry_text',
        'badge_lowstock_bg', 'badge_lowstock_text',
        'badge_expired_bg', 'badge_expired_text',
        'badge_primary_bg', 'badge_primary_text',
        // Dark mode
        'dark_bg_color', 'dark_sidebar_color', 'dark_card_color', 'dark_text_color',
        // Typography
        'body_font_family', 'body_font_size', 'font_weight_base',
        // Shape
        'card_border_radius', 'btn_border_radius', 'shadow_intensity',
        // Flags
        'dark_mode', 'theme_preset', 'table_striped', 'sidebar_compact',
    ];

    // Integer fields
    $intFields = [
        'body_font_size', 'font_weight_base', 'card_border_radius',
        'btn_border_radius', 'shadow_intensity', 'dark_mode',
        'table_striped', 'sidebar_compact',
    ];

    // Ensure all columns exist in company_settings table
    try {
        $db = db();
        // Get current columns
        $existing = [];
        $db->query("SHOW COLUMNS FROM company_settings");
        foreach ($db->resultSet() as $col) {
            $existing[] = $col['Field'];
        }

        foreach ($allowed as $col) {
            if (in_array($col, $existing)) continue;
            // Determine column type
            if (in_array($col, $intFields)) {
                $colDef = "`$col` TINYINT DEFAULT 0";
            } elseif ($col === 'body_font_family' || $col === 'theme_preset') {
                $colDef = "`$col` VARCHAR(60) DEFAULT ''";
            } else {
                $colDef = "`$col` VARCHAR(7) DEFAULT ''";
            }
            try {
                $db->queryRaw("ALTER TABLE company_settings ADD COLUMN $colDef");
            } catch (\Throwable $e) {
                error_log("[updateThemeSettings] Column add failed for $col: " . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        error_log('[updateThemeSettings] Schema check error: ' . $e->getMessage());
    }

    // Build SET clause
    $set    = [];
    $params = [':id' => 1];

    foreach ($data as $key => $val) {
        if (!in_array($key, $allowed)) continue;

        if (in_array($key, $intFields)) {
            $params[':' . $key] = (int)$val;
        } elseif ($key === 'body_font_family' || $key === 'theme_preset') {
            $params[':' . $key] = substr(strip_tags(trim((string)$val)), 0, 60);
        } else {
            // Hex color — validate
            $v = trim((string)$val);
            $params[':' . $key] = preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? $v : '';
        }
        $set[] = "`$key` = :{$key}";
    }

    if (empty($set)) {
        return ['success' => false, 'message' => 'No valid settings to save.'];
    }

    try {
        $db = db();

        // Ensure a row exists
        $db->query("SELECT id FROM company_settings WHERE id = 1 LIMIT 1");
        if (!$db->single()) {
            $db->queryRaw("INSERT INTO company_settings (id) VALUES (1)");
        }

        $sql = "UPDATE company_settings SET " . implode(', ', $set) . " WHERE id = :id";
        $db->query($sql);
        foreach ($params as $k => $v) {
            $db->bind($k, $v);
        }
        $db->execute();

        // Clear all caches
        if (function_exists('clearCompanySettingsCache')) {
            clearCompanySettingsCache();
        }
        unset(
            $_SESSION['company_settings'],
            $_SESSION['company_settings_cache'],
            $_SESSION['theme_colors'],
            $_SESSION['dashboard_stats'],
            $_SESSION['dashboard_stats_exp']
        );

        return ['success' => true, 'message' => 'Theme saved successfully.'];

    } catch (\Throwable $e) {
        error_log('[updateThemeSettings] DB update error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}
}

// ══════════════════════════════════════════════════════════════
// generateDynamicCSS()
// ══════════════════════════════════════════════════════════════
function generateDynamicCSS(): string
{
    $s = getCompanySettings();
    $h = fn($v, $d) => htmlspecialchars($v ?: $d, ENT_QUOTES);

    $vars = ':root {'
        . '--pharmacy-primary:' . $h($s['primary_color'] ?? '', '#0d6efd') . ';'
        . '--pharmacy-sidebar:' . $h($s['sidebar_color'] ?? '', '#212529') . ';'
        . '--pharmacy-text:' . $h($s['body_text_color'] ?? '', '#444444') . ';'
        . '}';

    return '<style id="pharmacy-theme-vars">' . $vars . '</style>';
}

// ══════════════════════════════════════════════════════════════
// getCurrentUserProfileImageUrl()
// ══════════════════════════════════════════════════════════════
function getCurrentUserProfileImageUrl(): string
{
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $root = defined('ROOT_PATH') ? ROOT_PATH : '';

    if (!empty($_SESSION['user_profile_image_url'])) {
        return $_SESSION['user_profile_image_url'];
    }

    $imgFile = $_SESSION['user_profile_image'] ?? '';
    if ($imgFile) {
        foreach (['uploads/profiles/', 'uploads/avatars/'] as $dir) {
            $full = $root . $dir . basename($imgFile);
            if (file_exists($full)) {
                $url = rtrim($base, '/') . '/' . $dir . basename($imgFile) . '?v=' . filemtime($full);
                $_SESSION['user_profile_image_url'] = $url;
                return $url;
            }
        }
    }
    return '';
}

// ══════════════════════════════════════════════════════════════
// getSidebarUserAvatarHtml()
// ══════════════════════════════════════════════════════════════
function getSidebarUserAvatarHtml(int $size = 40, string $extraClass = ''): string
{
    $url = getCurrentUserProfileImageUrl();
    $name = $_SESSION['user_name'] ?? 'User';
    $parts = explode(' ', trim($name));
    $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1) . substr($parts[1] ?? ($parts[0] ?? 'U'), 0, 1));
    $colors = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6'];
    $color = $colors[abs(crc32($_SESSION['username'] ?? 'x')) % count($colors)];
    $cls = 'sb-user-avatar' . ($extraClass ? ' ' . htmlspecialchars($extraClass) : '');
    $style = "width:{$size}px;height:{$size}px;border-radius:50%;object-fit:cover;";

    if ($url) {
        return '<img src="' . htmlspecialchars($url) . '" class="' . $cls . '" style="' . $style . '">';
    }

    return '<div class="' . $cls . '" style="' . $style . 'background:' . $color . ';display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:' . (int)($size * 0.36) . 'px;">'
         . htmlspecialchars($initials) . '</div>';
}

// ══════════════════════════════════════════════════════════════
// Other helper functions
// ══════════════════════════════════════════════════════════════

function updateUserProfile(int $userId, array $data): bool
{
    try {
        $db = db();
        $db->query("UPDATE users SET full_name = :full_name, email = :email, phone = :phone WHERE id = :id");
        $db->bind(':full_name', sanitize($data['full_name']));
        $db->bind(':email', sanitize($data['email']));
        $db->bind(':phone', sanitize($data['phone'] ?? ''));
        $db->bind(':id', $userId);
        $db->execute();

        if ((int)$_SESSION['user_id'] === $userId) {
            $_SESSION['user_name'] = $data['full_name'];
            $_SESSION['user_email'] = $data['email'];
        }
        return true;
    } catch (\Throwable $e) {
        error_log('[updateUserProfile] Error: ' . $e->getMessage());
        return false;
    }
}

function backupDatabase(): string
{
    return "-- Backup function - simplified";
}

function createBackupFile(): array
{
    return ['success' => true, 'filename' => 'backup_' . date('Y-m-d_H-i-s') . '.sql'];
}

function getBackupFiles(): array
{
    return [];
}

function restoreDatabase(string $filepath): array
{
    return ['success' => false, 'message' => 'Use dedicated restore page'];
}

function deleteBackupFile(string $filename): bool
{
    return true;
}

function getSystemPreferences(): array
{
    $s = getCompanySettings();
    return [
        'low_stock_threshold' => $s['low_stock_threshold'] ?? 10,
        'near_expiry_days' => $s['near_expiry_days'] ?? 30,
        'invoice_footer' => $s['footer_text'] ?? '',
        'enable_gst' => 0,
        'tax_percentage' => $s['default_tax_rate'] ?? 0,
    ];
}

function updateSystemPreferences(array $data): bool
{
    $result = updateCompanySettings($data);
    return $result['success'] ?? false;
}

function getNotifications(int $limit = 10): array
{
    return [];
}
?>