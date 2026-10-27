<?php
/**
 * Theme Switcher
 * Pharmacy Management System
 *
 * Renders the theme picker UI (dropdown or grid of swatches).
 * Include anywhere in settings / navbar where you want the picker.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/theme_switcher.php';
 *   render_theme_switcher();          // full grid picker
 *   render_theme_switcher('dropdown'); // compact dropdown
 */

if (!function_exists('get_theme')) {
    require_once __DIR__ . '/../assets/themes/themes.config.php';
}
if (!function_exists('get_active_theme_id')) {
    require_once __DIR__ . '/../assets/themes/theme.engine.php';
}

/**
 * Render the theme switcher widget.
 *
 * @param string $style  'grid' (default) | 'dropdown'
 */
function render_theme_switcher(string $style = 'grid'): void
{
    global $themes;
    $active_id = get_active_theme_id();

    if ($style === 'dropdown') {
        render_theme_dropdown($themes, $active_id);
    } else {
        render_theme_grid($themes, $active_id);
    }
}

// ─────────────────────────────────────────────────────────────
// Grid style
// ─────────────────────────────────────────────────────────────
function render_theme_grid(array $themes, string $active_id): void
{
    ?>
    <div class="theme-switcher-grid" id="themeSwitcherGrid">
        <?php foreach ($themes as $id => $theme): ?>
            <?php
            $is_active = ($id === $active_id);
            $safe_id   = htmlspecialchars($id,              ENT_QUOTES, 'UTF-8');
            $safe_name = htmlspecialchars($theme['name'],   ENT_QUOTES, 'UTF-8');
            $safe_desc = htmlspecialchars($theme['description'] ?? '', ENT_QUOTES, 'UTF-8');
            $safe_pri  = htmlspecialchars($theme['primary'],ENT_QUOTES, 'UTF-8');
            $safe_nav  = htmlspecialchars($theme['nav_bg'], ENT_QUOTES, 'UTF-8');
            $active_cls= $is_active ? ' theme-card-active' : '';
            ?>
            <div class="theme-card<?= $active_cls ?>"
                 data-theme-id="<?= $safe_id ?>"
                 title="<?= $safe_desc ?>"
                 onclick="applyTheme('<?= $safe_id ?>')">
                <div class="theme-preview">
                    <div class="theme-preview-nav"
                         style="background:<?= $safe_nav ?>"></div>
                    <div class="theme-preview-body"
                         style="background:<?= $safe_pri ?>20">
                        <div class="theme-preview-bar"
                             style="background:<?= $safe_pri ?>"></div>
                    </div>
                </div>
                <div class="theme-card-name">
                    <?= $safe_name ?>
                    <?php if ($is_active): ?>
                        <i class="fas fa-check-circle text-success ms-1"></i>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <style>
    .theme-switcher-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        padding: 10px 0;
    }
    .theme-card {
        width: 110px;
        border: 2px solid #dee2e6;
        border-radius: 8px;
        overflow: hidden;
        cursor: pointer;
        transition: border-color .2s, transform .15s;
    }
    .theme-card:hover          { border-color: var(--theme-primary, #2c3e7a); transform: translateY(-2px); }
    .theme-card-active         { border-color: var(--theme-primary, #2c3e7a); box-shadow: 0 0 0 3px rgba(44,62,122,.25); }
    .theme-preview             { height: 60px; display: flex; flex-direction: column; }
    .theme-preview-nav         { height: 18px; }
    .theme-preview-body        { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; padding: 4px; }
    .theme-preview-bar         { height: 8px; border-radius: 3px; width: 70%; }
    .theme-card-name           { font-size: 11px; text-align: center; padding: 4px 4px 6px; font-weight: 500; color: #343a40; }
    </style>

    <script>
    function applyTheme(themeId) {
        fetch('<?= rtrim(defined('BASE_URL') ? BASE_URL : '..', '/') ?>/ajax/apply_theme.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body   : JSON.stringify({ theme_id: themeId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Reload to apply new CSS variables server-side
                location.reload();
            } else {
                alert('Theme error: ' + data.message);
                console.error('[ThemeSwitcher]', data);
            }
        })
        .catch(err => {
            console.error('[ThemeSwitcher] Network error:', err);
            alert('Could not apply theme. Please try again.');
        });
    }
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────
// Dropdown style
// ─────────────────────────────────────────────────────────────
function render_theme_dropdown(array $themes, string $active_id): void
{
    $active_name = $themes[$active_id]['name'] ?? 'Default';
    ?>
    <div class="dropdown theme-dropdown">
        <button class="btn btn-outline-secondary btn-sm dropdown-toggle"
                type="button" id="themeDropdownBtn"
                data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fas fa-palette me-1"></i>
            <?= htmlspecialchars($active_name, ENT_QUOTES, 'UTF-8') ?>
        </button>
        <ul class="dropdown-menu" aria-labelledby="themeDropdownBtn">
            <?php foreach ($themes as $id => $theme): ?>
                <?php
                $safe_id   = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
                $safe_name = htmlspecialchars($theme['name'], ENT_QUOTES, 'UTF-8');
                $safe_pri  = htmlspecialchars($theme['primary'], ENT_QUOTES, 'UTF-8');
                $active_cls= ($id === $active_id) ? ' active' : '';
                ?>
                <li>
                    <a class="dropdown-item<?= $active_cls ?>"
                       href="#"
                       onclick="applyTheme('<?= $safe_id ?>'); return false;">
                        <span class="theme-dot me-2"
                              style="display:inline-block;width:12px;height:12px;
                                     border-radius:50%;background:<?= $safe_pri ?>">
                        </span>
                        <?= $safe_name ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <script>
    /* applyTheme() is defined by theme_switcher grid — include only once */
    if (typeof applyTheme === 'undefined') {
        function applyTheme(themeId) {
            fetch('<?= rtrim(defined('BASE_URL') ? BASE_URL : '..', '/') ?>/ajax/apply_theme.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body   : JSON.stringify({ theme_id: themeId })
            })
            .then(r => r.json())
            .then(data => { if (data.success) { location.reload(); } else { alert(data.message); } })
            .catch(err => { console.error(err); });
        }
    }
    </script>
    <?php
}
