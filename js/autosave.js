/* ============================================================
   FILE: pharmacy_software/js/autosave.js
   STEP 10 — Power Failure Recovery: Auto-Save Engine
   Saves form data to localStorage every 5 seconds.
   Restores on page reload after crash/power failure.
   No internet or server required — purely local.
   ============================================================ */

var AutoSave = (function () {

    var timers       = {};   // { storageKey: intervalId }
    var INTERVAL_MS  = 5000; // save every 5 seconds

    // ── Prefix ────────────────────────────────────────────────
    // Key format: pharmacy_autosave_{pageName}_user_{userId}
    // Ensures different users on the same machine don't clash.
    function buildKey(storageKey) {
        return 'pharmacy_autosave_' + storageKey;
    }

    // ── Serialize form to plain object ────────────────────────
    function serializeForm(form) {
        var state = {};
        try {
            new FormData(form).forEach(function (val, name) {
                // Skip file inputs — can't store binary in localStorage
                var el = form.querySelector('[name="' + name + '"]');
                if (el && el.type === 'file') return;
                // For multi-select / checkboxes, collect as array
                if (state.hasOwnProperty(name)) {
                    if (!Array.isArray(state[name])) {
                        state[name] = [state[name]];
                    }
                    state[name].push(val);
                } else {
                    state[name] = val;
                }
            });
        } catch (e) { /* FormData unavailable */ }
        return state;
    }

    // ── Write to localStorage ─────────────────────────────────
    function writeStorage(key, data) {
        try {
            localStorage.setItem(buildKey(key), JSON.stringify({
                data      : data,
                savedAt   : new Date().toISOString(),
                pageUrl   : window.location.pathname,
            }));
            return true;
        } catch (e) {
            // localStorage full or unavailable
            console.warn('[AutoSave] Storage write failed:', e.message);
            return false;
        }
    }

    // ── Read from localStorage ────────────────────────────────
    function readStorage(key) {
        try {
            var raw = localStorage.getItem(buildKey(key));
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    // ── Show toast indicator ──────────────────────────────────
    function showIndicator(message) {
        var existing = document.getElementById('autosave-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.id = 'autosave-toast';
        toast.style.cssText = [
            'position:fixed',
            'bottom:20px',
            'right:20px',
            'background:rgba(30,30,30,0.88)',
            'color:#fff',
            'padding:8px 16px',
            'border-radius:8px',
            'font-size:12px',
            'z-index:99999',
            'pointer-events:none',
            'transition:opacity 0.4s',
            'font-family:inherit',
        ].join(';');

        var now   = new Date();
        var hh    = String(now.getHours()).padStart(2, '0');
        var mm    = String(now.getMinutes()).padStart(2, '0');
        var ss    = String(now.getSeconds()).padStart(2, '0');
        toast.innerHTML = '&#10003; ' + (message || ('Auto-saved at ' + hh + ':' + mm + ':' + ss));
        document.body.appendChild(toast);

        setTimeout(function () {
            toast.style.opacity = '0';
            setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 400);
        }, 2500);
    }

    // ── Show restore banner ───────────────────────────────────
    function showRestoreBanner(storageKey, savedAt, onRestore, onDiscard) {
        var existing = document.getElementById('autosave-restore-banner');
        if (existing) existing.remove();

        // Format saved timestamp nicely
        var ts = '';
        try {
            var d = new Date(savedAt);
            ts = d.toLocaleDateString() + ' ' + d.toLocaleTimeString();
        } catch (e) { ts = savedAt; }

        var banner = document.createElement('div');
        banner.id = 'autosave-restore-banner';
        banner.style.cssText = [
            'position:fixed',
            'top:0',
            'left:0',
            'right:0',
            'background:#1d4ed8',
            'color:#fff',
            'padding:12px 20px',
            'z-index:99998',
            'display:flex',
            'align-items:center',
            'justify-content:space-between',
            'gap:12px',
            'font-size:13px',
            'font-family:inherit',
            'box-shadow:0 2px 8px rgba(0,0,0,0.2)',
        ].join(';');

        banner.innerHTML =
            '<span>&#9889; Unsaved entry from <strong>' + ts + '</strong> found &mdash; Restore?</span>' +
            '<span style="display:flex;gap:8px;">' +
                '<button id="autosave-btn-restore" style="background:#fff;color:#1d4ed8;border:none;padding:5px 14px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px;">Restore</button>' +
                '<button id="autosave-btn-discard" style="background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.4);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12px;">Discard</button>' +
            '</span>';

        document.body.prepend(banner);

        document.getElementById('autosave-btn-restore').addEventListener('click', function () {
            banner.remove();
            if (typeof onRestore === 'function') onRestore();
        });
        document.getElementById('autosave-btn-discard').addEventListener('click', function () {
            banner.remove();
            if (typeof onDiscard === 'function') onDiscard();
            AutoSave.clear(storageKey);
        });
    }

    // ── Restore saved values into form ────────────────────────
    function populateForm(form, data) {
        Object.keys(data).forEach(function (name) {
            var val = data[name];
            var elements = form.querySelectorAll('[name="' + CSS.escape(name) + '"]');
            elements.forEach(function (el) {
                if (el.type === 'file') return; // skip file inputs
                if (el.type === 'checkbox') {
                    var arr = Array.isArray(val) ? val : [val];
                    el.checked = arr.indexOf(el.value) !== -1;
                } else if (el.type === 'radio') {
                    el.checked = (el.value === val);
                } else if (el.tagName === 'SELECT' && el.multiple) {
                    var arr2 = Array.isArray(val) ? val : [val];
                    Array.from(el.options).forEach(function (opt) {
                        opt.selected = arr2.indexOf(opt.value) !== -1;
                    });
                } else {
                    el.value = val;
                    // Trigger change event so any dependent logic runs
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════

    /**
     * AutoSave.init(formId, storageKey)
     * Attach auto-save to a form. Starts the 5-second interval.
     * Also checks for a previously saved draft and prompts to restore.
     *
     * @param {string} formId      — id of the <form> element
     * @param {string} storageKey  — unique key (e.g. 'purchase_add_user_3')
     */
    function init(formId, storageKey) {
        var form = document.getElementById(formId);
        if (!form) {
            console.warn('[AutoSave] Form not found: #' + formId);
            return;
        }

        // Check for existing draft on page load
        var saved = readStorage(storageKey);
        if (saved && saved.data && Object.keys(saved.data).length > 0) {
            showRestoreBanner(
                storageKey,
                saved.savedAt,
                function () {
                    // Restore
                    populateForm(form, saved.data);
                    showIndicator('Draft restored from ' + new Date(saved.savedAt).toLocaleTimeString());
                },
                function () {
                    // Discard — already cleared in banner handler
                }
            );
        }

        // Start the auto-save interval
        if (timers[storageKey]) {
            clearInterval(timers[storageKey]);
        }
        timers[storageKey] = setInterval(function () {
            save(formId, storageKey);
        }, INTERVAL_MS);

        // Also save immediately on input change (debounced)
        var debounceTimer = null;
        form.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                save(formId, storageKey);
            }, 1000);
        });
    }

    /**
     * AutoSave.save(formId, storageKey)
     * Manually trigger a save. Called by the interval and on input.
     */
    function save(formId, storageKey) {
        var form = document.getElementById(formId);
        if (!form) return;
        var data = serializeForm(form);
        if (writeStorage(storageKey, data)) {
            showIndicator();
        }
    }

    /**
     * AutoSave.restore(formId, storageKey)
     * Manually restore a saved draft into the form (no prompt).
     */
    function restore(formId, storageKey) {
        var form = document.getElementById(formId);
        if (!form) return;
        var saved = readStorage(storageKey);
        if (saved && saved.data) {
            populateForm(form, saved.data);
        }
    }

    /**
     * AutoSave.clear(storageKey)
     * Delete the saved draft. Call after successful form submit.
     */
    function clear(storageKey) {
        try {
            localStorage.removeItem(buildKey(storageKey));
        } catch (e) { /* ignore */ }
        if (timers[storageKey]) {
            clearInterval(timers[storageKey]);
            delete timers[storageKey];
        }
    }

    /**
     * AutoSave.showIndicator(message)
     * Public access to show the toast (e.g. after manual save).
     */

    return {
        init          : init,
        save          : save,
        restore       : restore,
        clear         : clear,
        showIndicator : showIndicator,
    };

})();