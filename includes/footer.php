<?php
/**
 * === FILE: pharmacy_software/includes/footer.php ===
 *
 * Phase 6 — Theme Integration (footer uses theme engine CSS vars):
 *   • Script load order: jQuery → Bootstrap bundle → script.js (correct)
 *   • Page loader complete animation on window.load
 *   • Auto-dismiss .alert (non-permanent) after 5 seconds
 *   • Double-submit protection on form[data-confirm-once]
 *   • Table column sort via th[data-sort] click
 *   • Bootstrap 5 tooltip initialisation
 *   • Confirm dialogs via [data-confirm] attribute
 *
 * NOTE: <div id="page-loader"> is output in includes/header.php (Step 4)
 *       as the very first element inside <body> — do NOT add it here.
 */
?>

    <!-- ═══════════════════════════════════════════════════════════
         GLOBAL FOOTER BAR — Single source of truth (Phase 5)
         Displays: Powered by Dr. Arslan Yasin Sukhera | Year | Version
         Theme-compatible: uses CSS variables from theme engine
         ═══════════════════════════════════════════════════════════ -->
    <footer class="app-global-footer" id="appGlobalFooter">
        <span class="app-footer-brand">
            <i class="fas fa-heartbeat me-1"></i>
            Powered by <strong>Dr. Arslan Yasin Sukhera</strong>
        </span>
        <span class="app-footer-sep" aria-hidden="true">|</span>
        <span class="app-footer-meta">
            &copy; <?php echo date('Y'); ?>
            &nbsp;&mdash;&nbsp;
            <?php echo defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'PharmaCare Pro'; ?>
        </span>
        <span class="app-footer-sep" aria-hidden="true">|</span>
        <span class="app-footer-version">
            <i class="fas fa-code-branch me-1"></i><?php echo defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : 'v5.0'; ?>
        </span>
    </footer>

    <style>
    /* ── Global Footer Bar ────────────────────────────────────────────────── */
    /* ── Phase 6: Footer uses Theme Engine variables ── */
    .app-global-footer {
        display        : flex;
        flex-wrap      : wrap;
        align-items    : center;
        justify-content: center;
        gap            : 0 10px;
        padding        : 10px 20px;
        margin-top     : 24px;
        border-top     : 1px solid var(--pharmacy-border,
                             var(--card-color, #dee2e6));
        background     : var(--card-color,
                             var(--pharmacy-card-bg, #f8f9fa));
        color          : var(--pharmacy-text-muted,
                             color-mix(in srgb, var(--text-color, #444) 60%, transparent));
        font-size      : 12px;
        letter-spacing : .3px;
        line-height    : 1.6;
    }
    .app-global-footer strong   { color: var(--text-color, var(--pharmacy-text, #444)); }
    .app-footer-sep             { opacity: .4; }
    .app-footer-version         { font-family: monospace; font-size: 11px; }
    @media print {
        .app-global-footer      { display: none !important; }
    }
    </style>

    <!-- ═══════════════════════════════════════════════════════════
         SCRIPTS — load order matters:
           1. jQuery (required by legacy AJAX in many pages)
           2. Bootstrap bundle (includes Popper for dropdowns/tooltips)
           3. App script.js (depends on both above)
         ═══════════════════════════════════════════════════════════ -->
    <?php if (!defined('CORE_JS_LOADED')): define('CORE_JS_LOADED', true); ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>js/script.js"></script>
    <!-- Phase 9: Universal Table — CDN dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
    <script src="<?php echo BASE_URL; ?>js/universal_table.js"></script>
    <!-- Phase 10: Universal Form System -->
    <script src="<?php echo BASE_URL; ?>js/modal_framework.js"></script>
    <script src="<?php echo BASE_URL; ?>js/universal_form.js"></script>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════
         GLOBAL JS UTILITIES
         All wrapped in DOMContentLoaded so script.js has already run.
         ═══════════════════════════════════════════════════════════ -->
    <script>
    (function () {
        'use strict';

        // ── 1. Page loader — complete on full page load ───────────────────────
        window.addEventListener('load', function () {
            var loader = document.getElementById('page-loader');
            if (!loader) return;
            loader.style.width   = '100%';
            loader.style.opacity = '1';
            setTimeout(function () {
                loader.style.opacity = '0';
                setTimeout(function () { loader.style.width = '0'; }, 420);
            }, 280);
        });

        // ── 2. Auto-dismiss alerts after 5 seconds ────────────────────────────
        // Skips alerts that have the .alert-permanent class.
        document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function (el) {
            setTimeout(function () {
                // Use Bootstrap Alert API when available, else fade manually
                if (window.bootstrap && bootstrap.Alert) {
                    var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
                    if (bsAlert) {
                        bsAlert.close();
                        return;
                    }
                }
                // Fallback: CSS fade then remove
                el.style.transition = 'opacity 0.4s ease';
                el.style.opacity    = '0';
                setTimeout(function () { el.remove(); }, 420);
            }, 5000);
        });

        // ── 3. Double-submit protection ───────────────────────────────────────
        // Add data-confirm-once to any <form> to prevent re-submission.
        // The submit button gets a spinner + "Processing..." label.
        document.querySelectorAll('form[data-confirm-once]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = this.querySelector('[type="submit"]');
                if (btn && !btn.disabled) {
                    btn.disabled   = true;
                    var label      = btn.dataset.loading || 'Processing...';
                    btn.innerHTML  = '<i class="fas fa-spinner fa-spin me-1"></i>' + label;
                }
            });
        });

        // ── 4. Table column sort via URL param ────────────────────────────────
        // Mark any <th> with data-sort="column_name" to make it sortable.
        // Clicking toggles sort_dir between asc/desc and reloads the page.
        document.querySelectorAll('th[data-sort]').forEach(function (th) {
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';

            // Show current sort indicator
            var url     = new URL(window.location.href);
            var sortBy  = url.searchParams.get('sort_by');
            var sortDir = url.searchParams.get('sort_dir') || 'asc';

            if (sortBy === th.dataset.sort) {
                var icon = document.createElement('i');
                icon.className = 'fas fa-sort-' + (sortDir === 'asc' ? 'up' : 'down')
                               + ' ms-1 text-primary sort-indicator';
                icon.style.fontSize = '0.7em';
                th.appendChild(icon);
            } else {
                // Neutral sort icon shown on hover via CSS
                th.setAttribute('title', 'Click to sort');
            }

            th.addEventListener('click', function () {
                var u      = new URL(window.location.href);
                var cur    = u.searchParams.get('sort_by');
                var dir    = u.searchParams.get('sort_dir') || 'asc';
                var col    = this.dataset.sort;
                u.searchParams.set('sort_by',  col);
                u.searchParams.set('sort_dir', (cur === col && dir === 'asc') ? 'desc' : 'asc');
                u.searchParams.set('page', '1'); // reset to page 1 on sort
                window.location.href = u.toString();
            });
        });

        // ── 5. Bootstrap 5 Tooltip initialisation ────────────────────────────
        // Any element with data-bs-toggle="tooltip" gets auto-initialised.
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el, { trigger: 'hover focus' });
        });

        // ── 6. Confirm dialogs ────────────────────────────────────────────────
        // Add data-confirm="Your message?" to links/buttons for a native confirm.
        // If the user cancels, the default action (navigation or form submit) is
        // prevented entirely.
        document.querySelectorAll('[data-confirm]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var message = this.dataset.confirm || 'Are you sure?';
                if (!confirm(message)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        });

    }());
    </script>

</body>
</html>