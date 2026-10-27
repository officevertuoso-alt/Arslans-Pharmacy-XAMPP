<?php
/**
 * Themes Configuration File
 * Pharmacy Management System
 *
 * Each theme must have:
 *   - id          : unique snake_case key (must match array key)
 *   - name        : human-readable display name
 *   - primary     : main brand / sidebar colour
 *   - secondary   : secondary / accent colour
 *   - success     : success state colour
 *   - info        : info state colour
 *   - warning     : warning state colour
 *   - danger      : danger / error state colour
 *   - light       : light background colour
 *   - dark        : dark text colour
 *   - nav_bg      : sidebar / navbar background
 *   - nav_text    : sidebar / navbar text colour
 *   - nav_active  : active nav item background
 *   - card_header : card header background
 *   - btn_primary : primary button background
 *   - body_bg     : main page background
 *   - description : short description shown in theme picker
 */

$themes = [

    // ──────────────────────────────────────────────
    // 1. DEFAULT (Blue Professional)
    // ──────────────────────────────────────────────
    'default' => [
        'id'          => 'default',
        'name'        => 'Default Blue',
        'primary'     => '#2c3e7a',
        'secondary'   => '#4a6fa5',
        'success'     => '#28a745',
        'info'        => '#17a2b8',
        'warning'     => '#ffc107',
        'danger'      => '#dc3545',
        'light'       => '#f8f9fa',
        'dark'        => '#343a40',
        'nav_bg'      => '#2c3e7a',
        'nav_text'    => '#ffffff',
        'nav_active'  => '#1a2a5e',
        'card_header' => '#2c3e7a',
        'btn_primary' => '#2c3e7a',
        'body_bg'     => '#f4f6f9',
        'description' => 'Classic professional blue theme',
    ],

    // ──────────────────────────────────────────────
    // 2. LIGHT GREEN MEDICAL  ← FIX: was missing
    // ──────────────────────────────────────────────
    'light_green_medical' => [
        'id'          => 'light_green_medical',
        'name'        => 'Light Green Medical',
        'primary'     => '#2e7d32',
        'secondary'   => '#4caf50',
        'success'     => '#388e3c',
        'info'        => '#0097a7',
        'warning'     => '#f9a825',
        'danger'      => '#c62828',
        'light'       => '#f1f8e9',
        'dark'        => '#1b5e20',
        'nav_bg'      => '#2e7d32',
        'nav_text'    => '#ffffff',
        'nav_active'  => '#1b5e20',
        'card_header' => '#388e3c',
        'btn_primary' => '#2e7d32',
        'body_bg'     => '#f1f8f1',
        'description' => 'Clean medical green — ideal for pharmacies and clinics',
    ],

    // ──────────────────────────────────────────────
    // 3. DARK PROFESSIONAL
    // ──────────────────────────────────────────────
    'dark_professional' => [
        'id'          => 'dark_professional',
        'name'        => 'Dark Professional',
        'primary'     => '#1a1a2e',
        'secondary'   => '#16213e',
        'success'     => '#4caf50',
        'info'        => '#2196f3',
        'warning'     => '#ff9800',
        'danger'      => '#f44336',
        'light'       => '#2d2d44',
        'dark'        => '#e0e0e0',
        'nav_bg'      => '#1a1a2e',
        'nav_text'    => '#e0e0e0',
        'nav_active'  => '#0f0f1a',
        'card_header' => '#16213e',
        'btn_primary' => '#3f51b5',
        'body_bg'     => '#12121f',
        'description' => 'Dark mode for reduced eye strain',
    ],

    // ──────────────────────────────────────────────
    // 4. TEAL MEDICAL
    // ──────────────────────────────────────────────
    'teal_medical' => [
        'id'          => 'teal_medical',
        'name'        => 'Teal Medical',
        'primary'     => '#00695c',
        'secondary'   => '#00897b',
        'success'     => '#4caf50',
        'info'        => '#0288d1',
        'warning'     => '#f9a825',
        'danger'      => '#c62828',
        'light'       => '#e0f2f1',
        'dark'        => '#004d40',
        'nav_bg'      => '#00695c',
        'nav_text'    => '#ffffff',
        'nav_active'  => '#004d40',
        'card_header' => '#00796b',
        'btn_primary' => '#00695c',
        'body_bg'     => '#f0faf8',
        'description' => 'Soothing teal for medical environments',
    ],

    // ──────────────────────────────────────────────
    // 5. PURPLE MODERN
    // ──────────────────────────────────────────────
    'purple_modern' => [
        'id'          => 'purple_modern',
        'name'        => 'Purple Modern',
        'primary'     => '#6a1b9a',
        'secondary'   => '#8e24aa',
        'success'     => '#43a047',
        'info'        => '#039be5',
        'warning'     => '#fb8c00',
        'danger'      => '#e53935',
        'light'       => '#f3e5f5',
        'dark'        => '#4a148c',
        'nav_bg'      => '#6a1b9a',
        'nav_text'    => '#ffffff',
        'nav_active'  => '#4a148c',
        'card_header' => '#7b1fa2',
        'btn_primary' => '#6a1b9a',
        'body_bg'     => '#faf4ff',
        'description' => 'Modern purple for a fresh look',
    ],

    // ──────────────────────────────────────────────
    // 6. ORANGE WARM
    // ──────────────────────────────────────────────
    'orange_warm' => [
        'id'          => 'orange_warm',
        'name'        => 'Orange Warm',
        'primary'     => '#e65100',
        'secondary'   => '#f57c00',
        'success'     => '#558b2f',
        'info'        => '#0277bd',
        'warning'     => '#f9a825',
        'danger'      => '#b71c1c',
        'light'       => '#fff3e0',
        'dark'        => '#bf360c',
        'nav_bg'      => '#e65100',
        'nav_text'    => '#ffffff',
        'nav_active'  => '#bf360c',
        'card_header' => '#ef6c00',
        'btn_primary' => '#e65100',
        'body_bg'     => '#fffaf5',
        'description' => 'Energetic warm orange',
    ],

    // ──────────────────────────────────────────────
    // 7. ROSE PINK
    // ──────────────────────────────────────────────
    'rose_pink' => [
        'id'          => 'rose_pink',
        'name'        => 'Rose Pink',
        'primary'     => '#ad1457',
        'secondary'   => '#c2185b',
        'success'     => '#2e7d32',
        'info'        => '#0277bd',
        'warning'     => '#f57f17',
        'danger'      => '#b71c1c',
        'light'       => '#fce4ec',
        'dark'        => '#880e4f',
        'nav_bg'      => '#ad1457',
        'nav_text'    => '#ffffff',
        'nav_active'  => '#880e4f',
        'card_header' => '#c2185b',
        'btn_primary' => '#ad1457',
        'body_bg'     => '#fff8fb',
        'description' => 'Elegant rose pink',
    ],

    // ──────────────────────────────────────────────
    // 8. SLATE GREY
    // ──────────────────────────────────────────────
    'slate_grey' => [
        'id'          => 'slate_grey',
        'name'        => 'Slate Grey',
        'primary'     => '#37474f',
        'secondary'   => '#546e7a',
        'success'     => '#43a047',
        'info'        => '#039be5',
        'warning'     => '#ffb300',
        'danger'      => '#e53935',
        'light'       => '#eceff1',
        'dark'        => '#263238',
        'nav_bg'      => '#37474f',
        'nav_text'    => '#ffffff',
        'nav_active'  => '#263238',
        'card_header' => '#455a64',
        'btn_primary' => '#37474f',
        'body_bg'     => '#f5f7f8',
        'description' => 'Neutral slate grey for minimal UI',
    ],

];

/**
 * Helper: get a single theme by ID.
 * Returns 'default' theme if the requested ID does not exist.
 */
function get_theme(string $theme_id): array
{
    global $themes;
    if (array_key_exists($theme_id, $themes)) {
        return $themes[$theme_id];
    }
    // Fallback — never throw "Unknown theme ID" again
    return $themes['default'];
}

/**
 * Helper: return all theme IDs.
 */
function get_all_theme_ids(): array
{
    global $themes;
    return array_keys($themes);
}

/**
 * Helper: validate theme ID.
 */
function is_valid_theme(string $theme_id): bool
{
    global $themes;
    return array_key_exists($theme_id, $themes);
}
