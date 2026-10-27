/* ============================================================
   FILE: pharmacy_software/js/permissions.js
   STEP 16 — Client-Side Permission Enforcer
   Reads window.PHARMACY_PERMS (set by page_head.php Step 14)
   and disables/hides elements the current user can't access.

   Usage on any element:
     data-requires-perm="module:action"
     e.g. data-requires-perm="sales:delete"
         data-requires-perm="purchases:add"
         data-requires-perm="reports:export"

   Optional modifier:
     data-perm-action="hide"     → use .perm-hidden  (default: .perm-disabled)
     data-perm-action="readonly" → use .perm-readonly
   ============================================================ */

(function () {

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Check if current user has a specific module:action permission.
     * Admins always return true.
     * Falls back to true (permissive) if PHARMACY_PERMS is not set.
     */
    function can(module, action) {
        var perms = window.PHARMACY_PERMS;
        if (!perms) return true;                         // permissive fallback
        if (perms.isAdmin === true) return true;         // admin bypasses all

        var modules = perms.modules || {};
        var mod     = modules[module];
        if (!mod) return false;                          // module not in permissions

        // Permissions can be stored as object {view:true, add:false, ...}
        // or as array ['view','add',...]
        if (Array.isArray(mod)) {
            return mod.indexOf(action) !== -1;
        }
        return !!mod[action] || !!mod['can_' + action];
    }

    /**
     * Parse "module:action" string into { module, action }.
     * Returns null on invalid format.
     */
    function parsePerm(permStr) {
        if (!permStr || typeof permStr !== 'string') return null;
        var parts = permStr.split(':');
        if (parts.length !== 2) return null;
        return { module: parts[0].trim(), action: parts[1].trim() };
    }

    /**
     * Show a permission-denied toast (non-blocking).
     */
    function showDeniedToast(module, action) {
        var existing = document.getElementById('perm-denied-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.id = 'perm-denied-toast';
        toast.style.cssText = [
            'position:fixed',
            'bottom:20px',
            'left:50%',
            'transform:translateX(-50%)',
            'background:#dc3545',
            'color:#fff',
            'padding:10px 20px',
            'border-radius:8px',
            'font-size:13px',
            'z-index:99999',
            'pointer-events:none',
            'font-family:inherit',
            'box-shadow:0 4px 12px rgba(0,0,0,0.2)',
        ].join(';');
        toast.innerHTML = '&#128274; Permission denied: <strong>' + module + ' &rsaquo; ' + action + '</strong>';
        document.body.appendChild(toast);

        setTimeout(function () {
            toast.style.transition = 'opacity 0.4s';
            toast.style.opacity    = '0';
            setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 400);
        }, 2200);
    }

    // ── Core enforcer ─────────────────────────────────────────

    /**
     * Scan all [data-requires-perm] elements and enforce permissions.
     * Called on DOMContentLoaded and can be called again after
     * dynamic content is injected (e.g. AJAX-loaded tables).
     */
    function enforce(root) {
        root = root || document;
        var elements = root.querySelectorAll('[data-requires-perm]');

        elements.forEach(function (el) {
            var permStr = el.getAttribute('data-requires-perm');
            var parsed  = parsePerm(permStr);
            if (!parsed) return;

            var allowed    = can(parsed.module, parsed.action);
            var permAction = (el.getAttribute('data-perm-action') || 'disable').toLowerCase();

            if (allowed) {
                // Ensure previously denied elements are re-enabled
                // (useful when enforce() is called after role change)
                el.classList.remove('perm-disabled', 'perm-hidden', 'perm-readonly');
                if (el.hasAttribute('data-perm-title-backup')) {
                    el.title = el.getAttribute('data-perm-title-backup');
                    el.removeAttribute('data-perm-title-backup');
                }
                return;
            }

            // ── Apply restriction ─────────────────────────────
            if (permAction === 'hide') {
                el.classList.add('perm-hidden');

            } else if (permAction === 'readonly') {
                el.classList.add('perm-readonly');
                // Disable form inputs
                if (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') {
                    el.setAttribute('readonly', 'readonly');
                    if (el.tagName === 'SELECT') el.setAttribute('disabled', 'disabled');
                }

            } else {
                // Default: disable
                el.classList.add('perm-disabled');

                // Back up existing title then replace with permission message
                if (!el.hasAttribute('data-perm-title-backup')) {
                    el.setAttribute('data-perm-title-backup', el.title || '');
                }
                el.title = 'Permission required: ' + parsed.module + ' › ' + parsed.action;

                // For <a> tags: prevent navigation
                if (el.tagName === 'A') {
                    el.setAttribute('data-perm-href-backup', el.href || '');
                    el.href = 'javascript:void(0)';
                }

                // For <form> submit buttons: intercept click to show toast
                if (el.tagName === 'BUTTON' &&
                    (el.type === 'submit' || el.closest('form'))) {
                    el.setAttribute('data-perm-intercepted', '1');
                }
            }
        });
    }

    // ── Click interceptor for submit buttons ──────────────────
    // Attached once on document — handles dynamically added buttons too
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-requires-perm]');
        if (!el) return;
        if (!el.hasAttribute('data-perm-intercepted')) return;

        // Already disabled by .perm-disabled — but intercept click anyway
        var permStr = el.getAttribute('data-requires-perm');
        var parsed  = parsePerm(permStr);
        if (!parsed) return;

        if (!can(parsed.module, parsed.action)) {
            e.preventDefault();
            e.stopImmediatePropagation();
            showDeniedToast(parsed.module, parsed.action);
        }
    }, true); // capture phase so it fires before other handlers

    // ── Export button guard ───────────────────────────────────
    // Extra safety: intercept form submits containing a denied export button
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var denied = form.querySelector('[data-requires-perm].perm-disabled');
        if (denied) {
            var permStr = denied.getAttribute('data-requires-perm');
            var parsed  = parsePerm(permStr);
            if (parsed && !can(parsed.module, parsed.action)) {
                e.preventDefault();
                showDeniedToast(parsed.module, parsed.action);
            }
        }
    });

    // ── Public API ────────────────────────────────────────────

    window.PharmacyPerms = {
        /**
         * Check permission programmatically.
         * e.g. if (PharmacyPerms.can('sales', 'delete')) { ... }
         */
        can: can,

        /**
         * Re-run enforcer on a specific container (after AJAX load).
         * e.g. PharmacyPerms.enforce(document.getElementById('myTable'))
         */
        enforce: enforce,

        /**
         * Show the denied toast manually.
         */
        showDenied: showDeniedToast,
    };

    // ── Init on DOM ready ─────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enforce(); });
    } else {
        enforce(); // DOM already ready
    }

})();