<?php
/**
 * PharmaCare Unified Notification Framework — PHP side
 * Phase 12
 *
 * Provides:
 *  setFlash($key, $message, $type)      — store a flash message (already in config.php, kept)
 *  getFlash($key)                       — retrieve + clear (already in config.php, kept)
 *  renderFlashAll()                     — renders all pending flash keys as Bootstrap alerts
 *  notifyAlert($message, $type, $opts)  — inline (non-flash) Bootstrap alert HTML
 *
 * Types: success | danger | warning | info
 * Icons: auto-assigned per type
 */

if (defined('PH_NOTIFY_LOADED')) return;
define('PH_NOTIFY_LOADED', true);

/* ── Icon map ───────────────────────────────────────────────── */
function _notifyIcon(string $type): string {
    $map = [
        'success' => 'fas fa-check-circle',
        'danger'  => 'fas fa-times-circle',
        'warning' => 'fas fa-exclamation-triangle',
        'info'    => 'fas fa-info-circle',
    ];
    return $map[$type] ?? $map['info'];
}

/* ── Single inline alert (no session) ─────────────────────── */
function notifyAlert(string $message, string $type = 'info', array $opts = []): string {
    $dismissible = $opts['dismissible'] ?? true;
    $permanent   = $opts['permanent']   ?? false;
    $icon        = $opts['icon']        ?? _notifyIcon($type);

    $classes  = 'alert alert-' . htmlspecialchars($type, ENT_QUOTES) . ' alert-dismissible fade show';
    if ($permanent) $classes .= ' alert-permanent';

    $html  = '<div class="' . $classes . '" role="alert">';
    $html .= '<i class="' . htmlspecialchars($icon, ENT_QUOTES) . ' me-2"></i>';
    $html .= $message; // caller is responsible for escaping or trusting HTML
    if ($dismissible) {
        $html .= '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
    }
    $html .= '</div>';
    return $html;
}

/* ── Render all pending flash messages ─────────────────────── */
function renderFlashAll(): string {
    $out = '';
    $keys = ['success', 'danger', 'warning', 'info', 'error'];
    foreach ($keys as $key) {
        // normalise 'error' → 'danger' for Bootstrap
        $type = ($key === 'error') ? 'danger' : $key;
        if (!isset($_SESSION['flash'][$key])) continue;
        $flash = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        $msg = is_array($flash) ? ($flash['message'] ?? '') : $flash;
        $rawType = is_array($flash) ? ($flash['type'] ?? $type) : $type;
        // normalise again from stored type
        if ($rawType === 'error') $rawType = 'danger';
        $out .= notifyAlert($msg, $rawType);
    }
    return $out;
}

/* ── Upgrade: fix setFlash so 'error' normalises to 'danger' ─ */
// Wrap only if not already wrapped (config.php defines the base)
if (!function_exists('setFlashNotify')) {
    /**
     * Preferred wrapper — use this going forward.
     * Accepts 'error' as alias for 'danger'.
     */
    function setFlashNotify(string $key, string $message, string $type = 'success'): void {
        if ($type === 'error') $type = 'danger';
        if (function_exists('setFlash')) {
            setFlash($key, $message, $type);
        } else {
            $_SESSION['flash'][$key] = ['message' => $message, 'type' => $type];
        }
    }
}
?>
