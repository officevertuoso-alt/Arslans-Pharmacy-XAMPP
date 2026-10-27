/**
 * === FILE: pharmacy_software/js/theme-picker.js ===
 *
 * Theme Picker — applies themes via AJAX and updates the UI live.
 *
 * Expects in the DOM (rendered by topbar.php / theme picker widget):
 *   [data-theme-swatch]          — clickable colour swatch buttons
 *     data-theme-id="default"    — theme ID to apply
 *   #theme-picker-label          — shows active theme name (e.g. "Default Blue")
 *   #theme-engine-vars           — <style> tag injected by renderCssVars()
 *   [data-active-theme]          — element whose data attribute holds current theme ID
 *                                  (on <body> or the picker container)
 *
 * AJAX endpoint:
 *   BASE_URL + 'ajax/apply_theme.php'
 *   BASE_URL is read from <meta name="base-url"> or window.BASE_URL global.
 *
 * FIX NOTES (vs previous version):
 *   • URL built from <meta name="base-url"> so it always resolves to the
 *     correct subdirectory — no more wrong-path network errors.
 *   • fetch() errors and non-OK HTTP responses are caught separately and
 *     shown as meaningful messages in the picker instead of silent failures.
 *   • Swatch active state is set BEFORE the request (optimistic) and rolled
 *     back on failure — feels instant.
 *   • CSS vars injected by replacing/inserting #theme-engine-vars <style>
 *     tag — no page reload needed.
 *   • body.dark-mode toggled when a dark theme is applied.
 */

(function () {
    'use strict';

    // ── Resolve BASE_URL ──────────────────────────────────────────────────────
    // Priority: window.BASE_URL (set by PHP in <script>) → <meta name="base-url"> → auto-detect
    function getBaseUrl() {
        if (typeof window.BASE_URL === 'string' && window.BASE_URL) {
            return window.BASE_URL.replace(/\/?$/, '/');
        }
        const meta = document.querySelector('meta[name="base-url"]');
        if (meta && meta.content) {
            return meta.content.replace(/\/?$/, '/');
        }
        // Fallback: derive from current URL up to /pharmacy_software/
        const match = window.location.pathname.match(/^(.*?\/pharmacy_software\/)/i);
        if (match) return window.location.origin + match[1];
        // Last resort: same origin + root
        return window.location.origin + '/';
    }

    const ENDPOINT = getBaseUrl() + 'ajax/apply_theme.php';

    // ── DOM refs ──────────────────────────────────────────────────────────────
    function getSwatches()   { return document.querySelectorAll('[data-theme-swatch]'); }
    function getLabel()      { return document.getElementById('theme-picker-label'); }
    function getErrorEl()    { return document.getElementById('theme-picker-error'); }
    function getStyleTag()   { return document.getElementById('theme-engine-vars'); }

    // ── Set active swatch highlight ───────────────────────────────────────────
    function setActiveSwatchUI(themeId) {
        getSwatches().forEach(function (el) {
            const isActive = el.dataset.themeId === themeId;
            el.classList.toggle('active', isActive);
            el.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        // Keep the picker container's data attribute in sync
        const container = document.getElementById('theme-picker-dropdown')
                       || document.querySelector('[data-active-theme]');
        if (container) container.dataset.activeTheme = themeId;

        // Keep <body> in sync for sidebar CSS
        document.body.dataset.activeTheme = themeId;
    }

    // ── Show / clear error message inside picker ──────────────────────────────
    function showError(msg) {
        const el = getErrorEl();
        if (!el) {
            console.error('[ThemePicker]', msg);
            return;
        }
        el.textContent = msg;
        el.style.display = 'block';
        clearTimeout(el._hideTimer);
        el._hideTimer = setTimeout(function () {
            el.style.display = 'none';
        }, 5000);
    }
    function clearError() {
        const el = getErrorEl();
        if (el) { el.style.display = 'none'; el.textContent = ''; }
    }

    // ── Inject new CSS vars without page reload ───────────────────────────────
    function injectCssVars(cssVarsHtml) {
        // cssVarsHtml = '<style id="theme-engine-vars">:root{...}</style>'
        // Extract just the inner text and update the existing <style> tag,
        // or replace/insert the whole block.
        const existing = getStyleTag();
        const tmp = document.createElement('div');
        tmp.innerHTML = cssVarsHtml;
        const newStyle = tmp.querySelector('style');

        if (existing && newStyle) {
            existing.textContent = newStyle.textContent;
        } else if (newStyle) {
            document.head.appendChild(newStyle);
        } else {
            // cssVarsHtml was plain CSS text, not a <style> tag
            if (existing) {
                existing.textContent = cssVarsHtml;
            } else {
                const s = document.createElement('style');
                s.id = 'theme-engine-vars';
                s.textContent = cssVarsHtml;
                document.head.appendChild(s);
            }
        }
    }

    // ── Toggle dark-mode body class ───────────────────────────────────────────
    const DARK_THEME_IDS = ['dark_professional']; // extend if you add more dark themes
    function syncDarkMode(themeId) {
        document.body.classList.toggle('dark-mode', DARK_THEME_IDS.includes(themeId));
    }

    // ── Core: apply a theme ───────────────────────────────────────────────────
    function applyTheme(themeId, swatchEl) {
        clearError();

        // Optimistic UI — highlight the swatch immediately
        const previousActive = document.querySelector('[data-theme-swatch].active');
        const previousId     = previousActive ? previousActive.dataset.themeId : null;
        setActiveSwatchUI(themeId);

        // Disable all swatches while request is in flight
        getSwatches().forEach(function (el) { el.style.pointerEvents = 'none'; });

        const formData = new FormData();
        formData.append('theme_id', themeId);

        fetch(ENDPOINT, {
            method      : 'POST',
            credentials : 'same-origin', // send session cookie
            body        : formData,
        })
        .then(function (response) {
            // Non-2xx responses: read body for error detail before throwing
            if (!response.ok) {
                return response.text().then(function (text) {
                    let msg = 'Server error ' + response.status;
                    try {
                        const data = JSON.parse(text);
                        msg = data.message || msg;
                    } catch (_) {}
                    throw new Error(msg);
                });
            }
            return response.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (_) {
                    // Response was not valid JSON — likely PHP error / HTML page
                    console.error('[ThemePicker] Non-JSON response:', text.substring(0, 300));
                    throw new Error('Server returned invalid response. Check PHP error log.');
                }
            });
        })
        .then(function (data) {
            if (!data.success) {
                throw new Error(data.message || 'Theme could not be applied.');
            }

            // ✓ Success — update label and CSS vars live
            const label = getLabel();
            if (label) label.textContent = data.theme_name || themeId;

            if (data.css_vars) {
                injectCssVars(data.css_vars);
            }

            syncDarkMode(themeId);

            // Persist active theme ID on meta tag so PHP can read it on next load
            let metaTheme = document.querySelector('meta[name="active-theme"]');
            if (!metaTheme) {
                metaTheme = document.createElement('meta');
                metaTheme.name = 'active-theme';
                document.head.appendChild(metaTheme);
            }
            metaTheme.content = themeId;
        })
        .catch(function (err) {
            // Roll back optimistic highlight to previous theme
            if (previousId) setActiveSwatchUI(previousId);
            else getSwatches().forEach(function (el) {
                el.classList.remove('active');
                el.removeAttribute('aria-pressed');
            });

            showError('✗ ' + err.message);
            console.error('[ThemePicker] applyTheme failed:', err);
        })
        .finally(function () {
            // Re-enable swatches
            getSwatches().forEach(function (el) { el.style.pointerEvents = ''; });
        });
    }

    // ── Initialise swatch click handlers ─────────────────────────────────────
    function initSwatches() {
        getSwatches().forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                const id = el.dataset.themeId;
                if (!id) return;
                // Don't re-apply if already active
                if (el.classList.contains('active')) return;
                applyTheme(id, el);
            });
        });
    }

    // ── Init on DOMContentLoaded (or immediately if already ready) ────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSwatches);
    } else {
        initSwatches();
    }

    // ── Public API (for topbar.php inline scripts if needed) ─────────────────
    window.ThemePicker = { applyTheme: applyTheme };

})();
