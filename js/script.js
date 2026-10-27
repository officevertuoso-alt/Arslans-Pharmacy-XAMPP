/**
 * pharmacy_software/js/script.js
 * Global utilities: theme sync, breadcrumb, sidebar, tooltips
 * v4.0 — Unified for all pages + Step 17 enhancements
 */

'use strict';

/* ══════════════════════════════════════════════
   1. DARK MODE CLASS SYNC
   PHP injects <body-class-injector data-dark="1">
   This ensures dark-mode class is on <body> ASAP
══════════════════════════════════════════════ */
(function () {
    var dm = document.querySelector('body-class-injector');
    if (dm && dm.dataset.dark === '1') {
        document.body.classList.add('dark-mode');
    }
})();

/* ══════════════════════════════════════════════
   2. SIDEBAR TOGGLE (Mobile + Desktop)
   Sidebar JS is primarily embedded in sidebar.php
   This provides the global window.toggleSidebar()
   used by navbar hamburger buttons
══════════════════════════════════════════════ */
window.toggleSidebar = function () {
    var sb       = document.getElementById('mainSidebar');
    var bd       = document.getElementById('sbBackdrop');
    var mc       = document.querySelector('.main-content');
    var isMobile = window.innerWidth < 992;

    if (!sb) return;

    if (isMobile) {
        sb.classList.toggle('sb-mobile-open');
        if (bd) bd.classList.toggle('active');
    } else {
        var isCollapsing = !sb.classList.contains('sb-col');
        sb.classList.toggle('sb-col');
        document.body.classList.toggle('sb-col-body');

        // Sync main-content margin
        if (mc) {
            mc.style.marginLeft = isCollapsing
                ? 'var(--sb-w-mini, 62px)'
                : 'var(--sb-w, 260px)';
        }

        // Sync navbar margin
        document.querySelectorAll('.navbar, .top-navbar').forEach(function (n) {
            n.style.marginLeft = isCollapsing
                ? 'var(--sb-w-mini, 62px)'
                : 'var(--sb-w, 260px)';
        });

        // Persist state
        try {
            localStorage.setItem('sbCollapsed', isCollapsing ? '1' : '0');
        } catch (e) { /* storage blocked */ }
    }
};

// Restore sidebar collapse state on page load
(function () {
    try {
        if (localStorage.getItem('sbCollapsed') === '1') {
            var sb = document.getElementById('mainSidebar');
            var mc = document.querySelector('.main-content');
            if (sb) {
                sb.classList.add('sb-col');
                document.body.classList.add('sb-col-body');
            }
            if (mc) mc.style.marginLeft = 'var(--sb-w-mini, 62px)';
            document.querySelectorAll('.navbar, .top-navbar').forEach(function (n) {
                n.style.marginLeft = 'var(--sb-w-mini, 62px)';
            });
        }
    } catch (e) { /* storage blocked */ }
})();

/* ══════════════════════════════════════════════
   3. DOM READY — Bootstrap components + auto-dismiss
══════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {

    // ── Bootstrap Tooltips ──────────────────────
    var tooltipEls = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipEls.forEach(function (el) {
        new bootstrap.Tooltip(el, { trigger: 'hover' });
    });

    // ── Bootstrap Popovers ──────────────────────
    var popoverEls = document.querySelectorAll('[data-bs-toggle="popover"]');
    popoverEls.forEach(function (el) {
        new bootstrap.Popover(el);
    });

    // ── Auto-dismiss alerts after 5 seconds ─────
    // Covers both legacy .auto-dismiss class AND any .alert without .alert-permanent
    setTimeout(function () {
        document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function (a) {
            try {
                var bsAlert = bootstrap.Alert.getOrCreateInstance(a);
                if (bsAlert) bsAlert.close();
            } catch (e) { a.remove(); }
        });
    }, 5000);

    // ── Active sidebar link highlight ───────────
    (function () {
        var currentPath = window.location.pathname;
        document.querySelectorAll('#mainSidebar .sb-link').forEach(function (link) {
            if (link.getAttribute('href') && currentPath.endsWith(link.getAttribute('href').split('?')[0])) {
                link.classList.add('active');
            }
        });
    })();

    // ── Breadcrumb back/forward button state ────
    // Forward button can't be reliably detected, but we can
    // disable back if no history is available
    var backBtn = document.getElementById('bcBackBtn');
    if (backBtn && window.history.length <= 1) {
        backBtn.classList.add('disabled');
        backBtn.setAttribute('disabled', 'disabled');
    }

});

/* ══════════════════════════════════════════════
   4. CONFIRM DELETE UTILITY
   Usage: confirmDelete('Sure?', 'formId')
          confirmDelete() — uses default message
══════════════════════════════════════════════ */
window.confirmDelete = function (message, formId) {
    message = message || 'Are you sure you want to delete this item? This action cannot be undone.';
    if (confirm(message)) {
        if (formId) {
            var form = document.getElementById(formId);
            if (form) { form.submit(); }
        }
        return true;
    }
    return false;
};

/* ══════════════════════════════════════════════
   5. FORM VALIDATION HELPER
   Usage: onsubmit="return validateForm('myForm')"
══════════════════════════════════════════════ */
window.validateForm = function (formId) {
    var form = document.getElementById(formId);
    if (!form) return true;
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        // Scroll to first invalid field
        var first = form.querySelector(':invalid');
        if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return false;
    }
    return true;
};

/* ══════════════════════════════════════════════
   6. NUMBER FORMATTING
   Usage: formatNumber(1234.5)        → "1,234.50"
          formatNumber(1234.5, 0)     → "1,235"
          formatCurrency(1234.5)      → "Rs 1,234.50"
══════════════════════════════════════════════ */
window.formatNumber = function (num, decimals) {
    decimals = (decimals !== undefined && decimals !== null) ? decimals : 2;
    return parseFloat(num || 0)
        .toFixed(decimals)
        .replace(/\B(?=(\d{3})+(?!\d))/g, ',');
};

window.formatCurrency = function (num, symbol) {
    symbol = symbol || 'Rs';
    return symbol + ' ' + window.formatNumber(num, 2);
};

/* ══════════════════════════════════════════════
   7. TOAST NOTIFICATION
   Usage: showToast('Saved!', 'success')
          showToast('Error occurred', 'error')
   Types: success | error | warning | info
══════════════════════════════════════════════ */
window.showToast = function (message, type) {
    type = type || 'success';

    var colors = {
        success : '#198754',
        error   : '#dc3545',
        warning : '#e67e22',
        info    : '#0d6efd'
    };

    var icons = {
        success : '✔',
        error   : '✖',
        warning : '⚠',
        info    : 'ℹ'
    };

    var toast = document.createElement('div');
    toast.style.cssText = [
        'position:fixed',
        'top:80px',
        'right:20px',
        'z-index:99999',
        'padding:12px 18px',
        'background:' + (colors[type] || colors.success),
        'color:#fff',
        'border-radius:10px',
        'box-shadow:0 4px 20px rgba(0,0,0,.25)',
        'font-size:14px',
        'max-width:340px',
        'display:flex',
        'align-items:center',
        'gap:10px',
        'animation:toastIn .3s ease'
    ].join(';');

    var icon = document.createElement('span');
    icon.style.cssText = 'font-size:16px;flex-shrink:0;';
    icon.textContent = icons[type] || icons.success;

    var text = document.createElement('span');
    text.textContent = message;

    toast.appendChild(icon);
    toast.appendChild(text);
    document.body.appendChild(toast);

    setTimeout(function () {
        toast.style.animation = 'toastOut .3s ease forwards';
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 300);
    }, 3500);
};

// Toast keyframe styles (injected once)
(function () {
    if (document.getElementById('pharmacy-toast-styles')) return;
    var style = document.createElement('style');
    style.id = 'pharmacy-toast-styles';
    style.textContent = [
        '@keyframes toastIn  { from { transform: translateX(120%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }',
        '@keyframes toastOut { from { opacity: 1; } to { opacity: 0; transform: translateX(120%); } }'
    ].join('');
    document.head.appendChild(style);
})();

/* ══════════════════════════════════════════════
   8. LIVE THEME PREVIEW (Settings pages)
   Called from settings/preferences.php pickers
══════════════════════════════════════════════ */
window.livePreview = function () {
    var get = function (id) { return document.getElementById(id); };

    var primary  = (get('primaryColorPicker')  || {}).value || '#0d6efd';
    var accent   = (get('accentColorPicker')   || {}).value || '#667eea';
    var sidebar  = (get('sidebarColorPicker')  || {}).value || '#1a1d23';
    var bodyBg   = (get('bodyBgPicker')        || {}).value || '#f0f2f5';
    var cardBg   = (get('cardBgPicker')        || {}).value || '#ffffff';
    var textCol  = (get('textColorPicker')     || {}).value || '#444444';
    var fontSize = ((get('fontSizeRange')      || {}).value || '14') + 'px';
    var fontFam  = (get('fontFamilySelect')    || {}).value || 'Segoe UI';

    var root = document.documentElement;
    root.style.setProperty('--pharmacy-primary', primary);
    root.style.setProperty('--pharmacy-accent',  accent);
    root.style.setProperty('--pharmacy-sidebar', sidebar);
    root.style.setProperty('--pharmacy-body-bg', bodyBg);
    root.style.setProperty('--pharmacy-card-bg', cardBg);
    root.style.setProperty('--pharmacy-text',    textCol);
    root.style.setProperty('--font-size-base',   fontSize);
    root.style.setProperty('--font-family',      '"' + fontFam + '",system-ui,sans-serif');

    // Update sidebar background directly
    var sb = document.getElementById('mainSidebar');
    if (sb) sb.style.setProperty('background', sidebar, 'important');

    // Update live preview box
    var box = get('livePreviewBox');
    if (box) {
        box.style.background = cardBg;
        box.style.color      = textCol;
        box.style.fontFamily = '"' + fontFam + '",system-ui,sans-serif';
        box.style.fontSize   = fontSize;
    }
};

/* ══════════════════════════════════════════════
   9. THEME PRESET APPLICATOR (Settings pages)
══════════════════════════════════════════════ */
window.applyPreset = function (key, primary, accent, sidebar) {
    document.querySelectorAll('.preset-card').forEach(function (c) {
        c.classList.remove('border-primary');
    });

    var card = document.querySelector('[data-preset="' + key + '"]');
    if (card) card.classList.add('border-primary');

    var inp = document.getElementById('themePresetInput');
    if (inp) inp.value = key;

    var get = function (id) { return document.getElementById(id); };
    if (primary && get('primaryColorPicker'))  get('primaryColorPicker').value = primary;
    if (accent  && get('accentColorPicker'))   get('accentColorPicker').value  = accent;
    if (sidebar && get('sidebarColorPicker'))  get('sidebarColorPicker').value = sidebar;

    window.livePreview();
};

/* ══════════════════════════════════════════════
   10. PRINT UTILITY
   Usage: printArea('divId')
══════════════════════════════════════════════ */
window.printArea = function (elementId) {
    var el = document.getElementById(elementId);
    if (!el) { window.print(); return; }

    var win = window.open('', '_blank', 'width=900,height=700');
    win.document.write([
        '<!DOCTYPE html><html><head>',
        '<meta charset="UTF-8">',
        '<title>Print</title>',
        '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">',
        '<style>body{padding:20px;font-size:13px;}@media print{.no-print{display:none!important}}</style>',
        '</head><body>',
        el.innerHTML,
        '<script>window.onload=function(){window.print();window.close();}<\/script>',
        '</body></html>'
    ].join(''));
    win.document.close();
};

/* ══════════════════════════════════════════════
   11. DEBOUNCE UTILITY
   Usage: var fn = debounce(myFunc, 300)
══════════════════════════════════════════════ */
window.debounce = function (func, wait) {
    var timeout;
    return function () {
        var context = this, args = arguments;
        clearTimeout(timeout);
        timeout = setTimeout(function () {
            func.apply(context, args);
        }, wait || 300);
    };
};

/* ══════════════════════════════════════════════
   12. AJAX HELPER
   Usage:
     ajaxPost('ajax/save.php', {key:'val'}, function(data){...})
     ajaxGet('ajax/get.php?id=1', function(data){...})
══════════════════════════════════════════════ */
window.ajaxPost = function (url, data, onSuccess, onError) {
    var body;
    if (data instanceof FormData) {
        body = data;
    } else {
        body = new URLSearchParams(data || {}).toString();
    }

    fetch(url, {
        method  : 'POST',
        headers : (data instanceof FormData) ? {} : { 'Content-Type': 'application/x-www-form-urlencoded' },
        body    : body
    })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (onSuccess) onSuccess(d); })
    .catch(function (e) {
        if (onError) onError(e);
        else console.error('ajaxPost error:', e);
    });
};

window.ajaxGet = function (url, onSuccess, onError) {
    fetch(url)
    .then(function (r) { return r.json(); })
    .then(function (d) { if (onSuccess) onSuccess(d); })
    .catch(function (e) {
        if (onError) onError(e);
        else console.error('ajaxGet error:', e);
    });
};

/* ══════════════════════════════════════════════
   13. TABLE SEARCH FILTER (Client-side)
   Usage: <input oninput="filterTable('tableId', this.value)">
══════════════════════════════════════════════ */
window.filterTable = function (tableId, query) {
    var table = document.getElementById(tableId);
    if (!table) return;
    var rows  = table.querySelectorAll('tbody tr');
    var q     = (query || '').toLowerCase().trim();

    rows.forEach(function (row) {
        var text = row.textContent.toLowerCase();
        row.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
};
/* ══════════════════════════════════════════════
   14. GLOBAL AJAX TABLE REFRESH UTILITY (Step 17)
   Usage: PharmaTable.refresh('myTableContainerId', '/current/page.php?params')
══════════════════════════════════════════════ */
window.PharmaTable = {
    refresh: function (tableContainerId, url) {
        var container = document.getElementById(tableContainerId);
        if (!container) return;
        container.style.opacity = '0.5';
        container.style.pointerEvents = 'none';
        fetch(url || window.location.href, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newTable = doc.getElementById(tableContainerId);
                if (newTable) {
                    container.innerHTML = newTable.innerHTML;
                    // Re-init tooltips inside refreshed table
                    container.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                        new bootstrap.Tooltip(el, { trigger: 'hover' });
                    });
                }
                container.style.opacity = '1';
                container.style.pointerEvents = '';
            })
            .catch(function (e) {
                console.error('PharmaTable.refresh error:', e);
                container.style.opacity = '1';
                container.style.pointerEvents = '';
            });
    }
};

/* ══════════════════════════════════════════════
   15. SMOOTH NUMBER / COUNT-UP ANIMATION (Step 17)
   Usage: animateNumber(el, 1234)
          animateNumber(el, 9999, 800)   ← custom duration ms
══════════════════════════════════════════════ */
window.animateNumber = function (el, target, duration) {
    if (!el) return;
    duration = duration || 600;
    var start     = parseFloat((el.textContent || '0').replace(/[^0-9.]/g, '')) || 0;
    var range     = target - start;
    var startTime = null;

    function update(currentTime) {
        if (!startTime) startTime = currentTime;
        var elapsed  = currentTime - startTime;
        var progress = Math.min(elapsed / duration, 1);
        // Ease-out cubic
        var eased    = 1 - Math.pow(1 - progress, 3);
        el.textContent = Math.round(start + range * eased).toLocaleString();
        if (progress < 1) requestAnimationFrame(update);
    }
    requestAnimationFrame(update);
};

/* ══════════════════════════════════════════════
   16. FILTER FORM PERSISTENCE (Step 17)
   Saves filter state to sessionStorage so back
   button restores the user's last filter settings.
   Usage: attach data-filter-key="page-name" to your
          filter <form> element — everything else is
          automatic.
══════════════════════════════════════════════ */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[data-filter-key]');
        if (!form) return;

        var key = 'pharma_filter_' + form.dataset.filterKey;

        // Restore saved values on page load (back-button scenario)
        try {
            var saved = sessionStorage.getItem(key);
            if (saved) {
                var state = JSON.parse(saved);
                Object.keys(state).forEach(function (name) {
                    var el = form.elements[name];
                    if (!el) return;
                    if (el.type === 'checkbox' || el.type === 'radio') {
                        el.checked = (el.value === state[name]);
                    } else {
                        el.value = state[name];
                    }
                });
            }
        } catch (e) { /* storage unavailable */ }

        // Save state whenever any filter input changes
        form.addEventListener('change', function () {
            try {
                var state = {};
                new FormData(form).forEach(function (val, name) {
                    state[name] = val;
                });
                sessionStorage.setItem(key, JSON.stringify(state));
            } catch (e) { /* storage unavailable */ }
        });

        // Also save on form submit
        form.addEventListener('submit', function () {
            try {
                var state = {};
                new FormData(form).forEach(function (val, name) {
                    state[name] = val;
                });
                sessionStorage.setItem(key, JSON.stringify(state));
            } catch (e) { /* storage unavailable */ }
        });
    });
})();

/* ══════════════════════════════════════════════
   17. SMOOTH FORM SUBMIT PROTECTION (Step 17)
   Prevents double-submit and shows spinner.
   Usage: add data-confirm-once to your <form>
          Optionally add data-loading="Saving..." to
          the submit button for a custom label.
══════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-confirm-once]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = this.querySelector('[type="submit"]');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                var label = btn.dataset.loading || 'Processing...';
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + label;
            }
        });
    });
});

/* ══════════════════════════════════════════════
   18. TABLE SORT VISUAL INDICATOR (Step 17)
   Add data-sort="column_name" to any <th> to make
   it clickable — appends ?sort_by=col&sort_dir=asc/desc
══════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('th[data-sort]').forEach(function (th) {
        th.style.cursor = 'pointer';
        // Show current sort arrow if URL matches
        var url      = new URL(window.location.href);
        var sortBy   = url.searchParams.get('sort_by');
        var sortDir  = url.searchParams.get('sort_dir') || 'asc';
        if (sortBy === th.dataset.sort) {
            th.insertAdjacentHTML('beforeend',
                ' <i class="fas fa-sort-' + (sortDir === 'asc' ? 'up' : 'down') +
                ' text-primary" style="font-size:0.7em;"></i>'
            );
        }
        th.addEventListener('click', function () {
            var u       = new URL(window.location.href);
            var current = u.searchParams.get('sort_by');
            var dir     = u.searchParams.get('sort_dir') || 'asc';
            u.searchParams.set('sort_by', this.dataset.sort);
            u.searchParams.set('sort_dir',
                (current === this.dataset.sort && dir === 'asc') ? 'desc' : 'asc'
            );
            window.location.href = u.toString();
        });
    });
});

/* ══════════════════════════════════════════════
   19. CONFIRM DIALOGS WITH data-confirm (Step 17)
   Usage: <a data-confirm="Are you sure?">Delete</a>
══════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            var msg = this.dataset.confirm || 'Are you sure?';
            if (!confirm(msg)) e.preventDefault();
        });
    });
});

/* ══════════════════════════════════════════════
   20. PAGE LOADER COMPLETE (Step 17)
   Works with #page-loader bar added in header.php
══════════════════════════════════════════════ */
window.addEventListener('load', function () {
    var loader = document.getElementById('page-loader');
    if (loader) {
        loader.style.width = '100%';
        setTimeout(function () {
            loader.style.opacity = '0';
            setTimeout(function () { loader.style.width = '0'; loader.style.opacity = '1'; }, 400);
        }, 300);
    }
});

/* ══════════════════════════════════════════════
   21. REAL-TIME SSE SYNC LISTENER (Step 9)
   Connects to ajax/sync_stream.php via Server-Sent Events.
   Updates dashboard stats and stock badges without page reload.
   Only active on dashboard and POS pages.
   Auto-reconnects after 5 seconds on connection drop.
══════════════════════════════════════════════ */
(function () {
    // Only run on dashboard or POS pages
    var page = window.location.pathname;
    var isDashboard = page.indexOf('dashboard') !== -1;
    var isPOS       = page.indexOf('pos') !== -1;
    if (!isDashboard && !isPOS) return;

    // Resolve path to sync_stream.php from any subdirectory
    var streamUrl = (function () {
        var base = window.location.pathname;
        // If we're inside a subdirectory (e.g. /sales/pos.php), go up one level
        var depth = (base.match(/\//g) || []).length - 1;
        var prefix = depth > 1 ? '../' : '';
        return prefix + 'ajax/sync_stream.php';
    })();

    var es         = null;
    var reconnectTimer = null;
    var reconnectDelay = 5000; // 5 seconds

    function connect() {
        if (es) {
            es.close();
            es = null;
        }

        es = new EventSource(streamUrl);

        // ── Connection established ────────────────────────────────────────
        es.addEventListener('connected', function (e) {
            console.log('[SSE] Connected to sync stream');
            reconnectDelay = 5000; // reset backoff on successful connect
        });

        // ── Sale completed → refresh dashboard stats ──────────────────────
        es.addEventListener('sale_completed', function (e) {
            var data = JSON.parse(e.data || '{}');
            console.log('[SSE] sale_completed', data);
            refreshDashboardStats();
            updateNotificationBadge();
        });

        // ── Purchase added → refresh stats + notification badge ───────────
        es.addEventListener('purchase_added', function (e) {
            var data = JSON.parse(e.data || '{}');
            console.log('[SSE] purchase_added', data);
            refreshDashboardStats();
            updateNotificationBadge();
        });

        // ── Stock updated → refresh stock badges ──────────────────────────
        es.addEventListener('stock_updated', function (e) {
            var data = JSON.parse(e.data || '{}');
            console.log('[SSE] stock_updated', data);
            refreshStockBadges();
        });

        // ── Return processed → refresh dashboard ──────────────────────────
        es.addEventListener('return_processed', function (e) {
            var data = JSON.parse(e.data || '{}');
            console.log('[SSE] return_processed', data);
            refreshDashboardStats();
        });

        // ── Auth error → stop reconnecting (user logged out) ──────────────
        es.addEventListener('auth_error', function (e) {
            console.warn('[SSE] Auth error — stopping sync listener');
            es.close();
            es = null;
        });

        // ── Connection error → auto-reconnect with backoff ────────────────
        es.onerror = function () {
            console.warn('[SSE] Connection lost — reconnecting in ' + (reconnectDelay / 1000) + 's');
            es.close();
            es = null;
            clearTimeout(reconnectTimer);
            reconnectTimer = setTimeout(function () {
                reconnectDelay = Math.min(reconnectDelay * 2, 30000); // max 30s backoff
                connect();
            }, reconnectDelay);
        };
    }

    // ── Dashboard stats refresh (AJAX call to existing stats endpoint) ────
    function refreshDashboardStats() {
        // Only update if stat cards exist on the page
        if (!document.querySelector('[data-stat]') &&
            !document.getElementById('todaySale') &&
            !document.getElementById('stat-today-sale')) return;

        // Try the standard dashboard stats endpoint
        fetch('../ajax/get_dashboard_stats.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.success) return;
                var s = data.stats || data;

                // Update whichever stat element IDs exist in the page
                var map = {
                    'todaySale'        : s.today_sale,
                    'todayPurchase'    : s.today_purchase,
                    'todayProfit'      : s.today_profit,
                    'stat-today-sale'  : s.today_sale,
                    'stat-today-profit': s.today_profit,
                    'stat-low-stock'   : s.low_stock_count,
                    'stat-credit'      : s.total_credit_pending,
                };
                Object.keys(map).forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el && map[id] !== undefined) {
                        el.textContent = map[id];
                    }
                });

                // Also update any [data-stat="key"] elements
                document.querySelectorAll('[data-stat]').forEach(function (el) {
                    var key = el.dataset.stat;
                    if (s[key] !== undefined) el.textContent = s[key];
                });
            })
            .catch(function () { /* stats refresh failed silently */ });
    }

    // ── Stock badge refresh ───────────────────────────────────────────────
    function refreshStockBadges() {
        document.querySelectorAll('[data-stock-id]').forEach(function (el) {
            var productId = el.dataset.stockId;
            if (!productId) return;
            fetch('../ajax/get_stock.php?product_id=' + productId, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (data && data.stock !== undefined) {
                        el.textContent = data.stock;
                        el.className = el.className.replace(/\bbg-\w+\b/g, '');
                        el.classList.add(data.stock <= 0 ? 'bg-danger' : data.stock <= 10 ? 'bg-warning' : 'bg-success');
                    }
                })
                .catch(function () {});
        });
    }

    // ── Notification badge refresh ────────────────────────────────────────
    function updateNotificationBadge() {
        var badge = document.getElementById('notificationBadge') ||
                    document.querySelector('.notification-badge');
        if (!badge) return;
        fetch('../ajax/get_notifications_count.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.count !== undefined) {
                    badge.textContent = data.count;
                    badge.style.display = data.count > 0 ? '' : 'none';
                }
            })
            .catch(function () {});
    }

    // ── Close SSE on page unload ──────────────────────────────────────────
    window.addEventListener('beforeunload', function () {
        clearTimeout(reconnectTimer);
        if (es) { es.close(); es = null; }
    });

    // ── Start connection ──────────────────────────────────────────────────
    connect();

})();

/* ═══════════════════════════════════════════════════════════════════════════
   PharmaModal — World's fastest modal system                    Step 8
   Features: skeleton loader, in-memory cache, GPU-accelerated animations,
             CSRF auto-inject, requestAnimationFrame rendering, zero-layout-thrash
   ═══════════════════════════════════════════════════════════════════════════ */
window.PharmaModal = (function() {
    'use strict';
    const _cache   = new Map();   // URL → HTML cache
    const _pending = new Set();   // URLs currently being fetched

    // Skeleton template (shown instantly while fetching)
    const SKELETON = `
        <div class="pm-skeleton">
            <div class="pm-sk-line pm-sk-w80"></div>
            <div class="pm-sk-line pm-sk-w60 pm-sk-mt8"></div>
            <div class="pm-sk-line pm-sk-w90 pm-sk-mt8"></div>
            <div class="pm-sk-line pm-sk-w50 pm-sk-mt8"></div>
            <div class="pm-sk-separator"></div>
            <div class="pm-sk-line pm-sk-w70"></div>
            <div class="pm-sk-line pm-sk-w80 pm-sk-mt8"></div>
        </div>`;

    // Pre-fetch on hover (called on [data-pm-prefetch] elements)
    function prefetch(url) {
        if (_cache.has(url) || _pending.has(url)) return;
        _pending.add(url);
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.text())
            .then(html => { _cache.set(url, html); _pending.delete(url); })
            .catch(() => _pending.delete(url));
    }

    // Open modal — shows skeleton INSTANTLY, loads content async
    function open(modalId, url, opts = {}) {
        const modal = document.getElementById(modalId);
        if (!modal) { console.warn('PharmaModal: modal not found:', modalId); return; }
        const body  = modal.querySelector('.modal-body') || modal.querySelector('.pm-body');
        const title = modal.querySelector('.modal-title');

        // 1. Show skeleton IMMEDIATELY (synchronous, zero delay)
        if (body && !opts.noSkeleton) {
            requestAnimationFrame(() => { body.innerHTML = SKELETON; });
        }
        if (title && opts.title) title.textContent = opts.title;

        // 2. Show Bootstrap modal right away
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal, { backdrop: opts.static ? 'static' : true });
        bsModal.show();

        // 3. Serve from cache instantly if available
        if (_cache.has(url)) {
            requestAnimationFrame(() => {
                if (body) body.innerHTML = _cache.get(url);
                if (opts.onLoad) opts.onLoad(modal, body);
                reinitModalScripts(modal);
            });
            return bsModal;
        }

        // 4. Fetch content
        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
        })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(html => {
            _cache.set(url, html); // cache for next open
            requestAnimationFrame(() => {
                if (body) {
                    body.style.transition = 'opacity 0.15s ease';
                    body.style.opacity    = '0';
                    body.innerHTML        = html;
                    requestAnimationFrame(() => { body.style.opacity = '1'; });
                }
                if (opts.onLoad) opts.onLoad(modal, body);
                reinitModalScripts(modal);
            });
        })
        .catch(err => {
            if (body) body.innerHTML = `<div class="alert alert-danger m-3"><i class="fas fa-exclamation-circle me-2"></i>Failed to load content. <button class="btn btn-sm btn-outline-danger ms-2" onclick="PharmaModal.open('${modalId}','${url}')">Retry</button></div>`;
            console.error('PharmaModal fetch error:', err);
        });

        return bsModal;
    }

    // Close modal + optionally invalidate cache for a URL (force fresh on next open)
    function close(modalId, invalidateUrl = null) {
        const modal = document.getElementById(modalId);
        if (modal) bootstrap.Modal.getOrCreateInstance(modal).hide();
        if (invalidateUrl) _cache.delete(invalidateUrl);
    }

    // Re-initialize scripts inside modal (tooltips, select2, auto-focus)
    function reinitModalScripts(modal) {
        modal.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
        modal.querySelectorAll('select.select2-enabled')?.forEach(el => {
            if (window.jQuery && $.fn.select2) $(el).select2({ dropdownParent: $(modal) });
        });
        // Auto-focus first visible, enabled input
        setTimeout(() => {
            modal.querySelector('input:not([type=hidden]):not([disabled])')?.focus();
        }, 150);
    }

    // Invalidate all cache (or by URL pattern — call after save/edit actions)
    function clearCache(urlPattern = null) {
        if (!urlPattern) { _cache.clear(); return; }
        _cache.forEach((_, key) => { if (key.includes(urlPattern)) _cache.delete(key); });
    }

    // Auto-init via data attributes:
    //   data-pm-trigger="modal"
    //   data-pm-modal="modalId"
    //   data-pm-url="/ajax/get_details.php?id=X"
    //   data-pm-title="View Details"
    //   data-pm-static="1"       (static backdrop)
    //   data-pm-nocache="1"      (bypass cache)
    //   data-pm-prefetch         (hover-prefetch)
    function init() {
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('[data-pm-trigger="modal"]');
            if (!btn) return;
            e.preventDefault();
            const modalId = btn.dataset.pmModal;
            const url     = btn.dataset.pmUrl;
            const title   = btn.dataset.pmTitle || '';
            const noCache = btn.dataset.pmNocache === '1';
            if (noCache) _cache.delete(url);
            open(modalId, url, { title, static: btn.dataset.pmStatic === '1' });
        });

        // Prefetch on hover — reduces perceived latency to ~0ms for quick hovers
        document.addEventListener('mouseover', function(e) {
            const btn = e.target.closest('[data-pm-prefetch]');
            if (btn && btn.dataset.pmUrl) prefetch(btn.dataset.pmUrl);
        });
    }

    return { open, close, prefetch, clearCache, init };
})();

// Auto-initialize on DOM ready
document.addEventListener('DOMContentLoaded', PharmaModal.init);