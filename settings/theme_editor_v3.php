<?php
/**
 * Theme Editor v3 — Settings Page
 * Pharmacy Management System
 *
 * URL: /settings/theme_editor_v3.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../assets/themes/themes.config.php';

define('THEME_ENGINE_NO_AUTO_APPLY', true); // we apply manually below
require_once __DIR__ . '/../assets/themes/theme.engine.php';
require_once __DIR__ . '/../includes/theme_switcher.php';

// Auth guard
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

$page_title   = 'Theme Settings';
$active_id    = get_active_theme_id();
$active_theme = get_theme($active_id);

require_once __DIR__ . '/../includes/page_head.php';
apply_theme($active_id);
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center"
                     style="background:var(--theme-card-header);color:#fff;">
                    <h5 class="mb-0"><i class="fas fa-palette me-2"></i>Theme Settings</h5>
                    <small>Active: <strong><?= htmlspecialchars($active_theme['name'], ENT_QUOTES, 'UTF-8') ?></strong></small>
                </div>
                <div class="card-body">

                    <p class="text-muted mb-4">
                        Choose a theme to personalise the appearance of your pharmacy system.
                        Changes take effect immediately.
                    </p>

                    <?php render_theme_switcher('grid'); ?>

                    <hr class="my-4">

                    <h6 class="text-muted mb-3">Quick Select (Dropdown)</h6>
                    <?php render_theme_switcher('dropdown'); ?>

                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
