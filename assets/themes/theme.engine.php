<?php
/**
 * Theme Engine — Pharmacy Management System
 *
 * API expected by topbar.php:
 *   themeEngine()                 → ThemeEngine singleton
 *   themeEngine()->getTheme()     → active theme array (with 'name', 'vars' keys)
 *   themeEngine()->getThemeId()   → active theme ID string
 *   themeEngine()->getGrouped()   → themes grouped by 'dark' / 'light'
 *   themeEngine()->setTheme($id)  → save active theme, return bool
 *
 * Also defines constant THEME_ENGINE_LOADED = true once loaded.
 */

if (defined('THEME_ENGINE_LOADED')) return;

// ── Load theme definitions ────────────────────────────────────────────────
if (!function_exists('get_theme')) {
    require_once __DIR__ . '/themes.config.php';
}

// ─────────────────────────────────────────────────────────────────────────
// ThemeEngine class
// ─────────────────────────────────────────────────────────────────────────
class ThemeEngine
{
    private static ?ThemeEngine $instance = null;
    private array  $themes  = [];
    private string $activeId = 'default';

    private function __construct()
    {
        global $themes;
        $this->themes = $themes ?? [];

        // Determine active theme
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $candidate = $_SESSION['active_theme'] ?? '';

        // Try DB if session is empty
        if (empty($candidate) || !isset($this->themes[$candidate])) {
            $candidate = $this->loadFromDb();
        }

        $this->activeId = isset($this->themes[$candidate]) ? $candidate : 'default';
        $_SESSION['active_theme'] = $this->activeId;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Active theme ID ───────────────────────────────────────────────────
    public function getThemeId(): string
    {
        return $this->activeId;
    }

    // ── Active theme array ────────────────────────────────────────────────
    // Returns shape: ['id'=>..., 'name'=>..., 'vars'=>['--primary-color'=>..., ...]]
    public function getTheme(?string $id = null): array
    {
        $id   = $id ?? $this->activeId;
        $raw  = $this->themes[$id] ?? $this->themes['default'] ?? [];
        return $this->normalise($id, $raw);
    }

    // ── All themes grouped by dark / light ────────────────────────────────
    // Returns: ['dark' => [...], 'light' => [...]]
    public function getGrouped(): array
    {
        $grouped = ['dark' => [], 'light' => []];
        foreach ($this->themes as $id => $raw) {
            $theme  = $this->normalise($id, $raw);
            $bucket = $raw['group'] ?? (in_array($id, ['dark_professional']) ? 'dark' : 'light');
            $grouped[$bucket][$id] = $theme;
        }
        return $grouped;
    }

    // ── Set + persist active theme ────────────────────────────────────────
    public function setTheme(string $id): bool
    {
        if (!isset($this->themes[$id])) {
            return false;
        }
        $this->activeId = $id;
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_SESSION['active_theme'] = $id;
        $this->saveToDb($id);
        return true;
    }

    // ── Output <style> block with CSS custom properties ───────────────────
    public function renderCssVars(?string $id = null): void
    {
        $theme = $this->getTheme($id ?? $this->activeId);
        echo '<style id="theme-engine-vars">' . "\n:root {\n";
        foreach ($theme['vars'] as $prop => $val) {
            echo '    ' . htmlspecialchars($prop, ENT_QUOTES) . ': '
               . htmlspecialchars($val,  ENT_QUOTES) . ";\n";
        }
        echo "}\n</style>\n";
    }

    // ── CSS vars string (for AJAX response) ──────────────────────────────
    public function getCssVarsHtml(?string $id = null): string
    {
        $theme = $this->getTheme($id ?? $this->activeId);
        $out   = '<style id="theme-engine-vars">' . "\n:root {\n";
        foreach ($theme['vars'] as $prop => $val) {
            $out .= '    ' . htmlspecialchars($prop, ENT_QUOTES) . ': '
                  . htmlspecialchars($val,  ENT_QUOTES) . ";\n";
        }
        $out .= "}\n</style>";
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Map the flat themes.config.php entry to the shape the topbar expects:
     *   ['id', 'name', 'vars' => ['--primary-color'=>..., '--sidebar-color'=>...]]
     */
    private function normalise(string $id, array $raw): array
    {
        // Build CSS variable map from raw theme fields
        $vars = [
            '--primary-color'      => $raw['primary']     ?? '#2c3e7a',
            '--secondary-color'    => $raw['secondary']   ?? '#4a6fa5',
            '--sidebar-color'      => $raw['nav_bg']      ?? $raw['primary'] ?? '#2c3e7a',
            '--sidebar-text'       => $raw['nav_text']    ?? '#ffffff',
            '--sidebar-active'     => $raw['nav_active']  ?? '#1a2a5e',
            '--accent-color'       => $raw['success']     ?? '#28a745',
            '--danger-color'       => $raw['danger']      ?? '#dc3545',
            '--warning-color'      => $raw['warning']     ?? '#ffc107',
            '--info-color'         => $raw['info']        ?? '#17a2b8',
            '--body-bg'            => $raw['body_bg']     ?? '#f4f6f9',
            '--card-bg'            => $raw['light']       ?? '#ffffff',
            '--text-color'         => $raw['dark']        ?? '#343a40',

            // Aliases used by topbar / sidebar CSS
            '--pharmacy-primary'   => $raw['primary']     ?? '#2c3e7a',
            '--pharmacy-sidebar'   => $raw['nav_bg']      ?? $raw['primary'] ?? '#2c3e7a',
            '--pharmacy-accent'    => $raw['success']     ?? '#28a745',
        ];

        // Merge any extra vars defined directly in the theme (advanced usage)
        if (!empty($raw['vars']) && is_array($raw['vars'])) {
            $vars = array_merge($vars, $raw['vars']);
        }

        return [
            'id'   => $id,
            'name' => $raw['name'] ?? ucwords(str_replace('_', ' ', $id)),
            'vars' => $vars,
        ];
    }

    private function loadFromDb(): string
    {
        try {
            if (function_exists('get_setting')) {
                $val = get_setting('active_theme');
                if ($val && isset($this->themes[$val])) return $val;
            } elseif (function_exists('db')) {
                $db = db();
                $db->query("SELECT setting_value FROM settings WHERE setting_key = 'active_theme' LIMIT 1");
                $row = $db->single();
                if ($row && isset($this->themes[$row['setting_value']])) {
                    return $row['setting_value'];
                }
            }
        } catch (Throwable $e) {
            error_log('[ThemeEngine] DB load failed: ' . $e->getMessage());
        }
        return 'default';
    }

    private function saveToDb(string $id): void
    {
        try {
            if (function_exists('save_setting')) {
                save_setting('active_theme', $id);
            } elseif (function_exists('db')) {
                $db = db();
                $db->query("INSERT INTO settings (setting_key, setting_value)
                            VALUES ('active_theme', :val)
                            ON DUPLICATE KEY UPDATE setting_value = :val");
                $db->bind(':val', $id);
                $db->execute();
            }
        } catch (Throwable $e) {
            error_log('[ThemeEngine] DB save failed: ' . $e->getMessage());
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Global accessor function — used by topbar.php as themeEngine()
// ─────────────────────────────────────────────────────────────────────────
function themeEngine(): ThemeEngine
{
    return ThemeEngine::getInstance();
}

// ─────────────────────────────────────────────────────────────────────────
// Mark as loaded — topbar.php checks: defined('THEME_ENGINE_LOADED')
// ─────────────────────────────────────────────────────────────────────────
define('THEME_ENGINE_LOADED', true);

// ─────────────────────────────────────────────────────────────────────────
// Auto-render CSS vars unless suppressed
// ─────────────────────────────────────────────────────────────────────────
if (!defined('THEME_ENGINE_NO_AUTO_APPLY')) {
    themeEngine()->renderCssVars();
}
