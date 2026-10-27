/**
 * assets/js/app.js
 * Pharmacy Management System — Core App JavaScript
 * Created: Rebuild Step 5
 */

'use strict';

// ─── GLOBAL NAMESPACE ────────────────────────────────────────────────
window.PharmacyApp = window.PharmacyApp || {};

// ─── TOAST NOTIFICATIONS ─────────────────────────────────────────────
PharmacyApp.toast = (function () {
    function show(message, type = 'success', duration = 3500) {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        const icons = { success: 'fa-check-circle', error: 'fa-times-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
        const icon  = icons[type] || icons.info;
        const toast = document.createElement('div');
        toast.className = `toast-item ${type}`;
        toast.innerHTML = `<i class="fas ${icon}"></i><span>${message}</span>`;
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            toast.style.transition = 'all .3s ease';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
    return { show, success: m => show(m,'success'), error: m => show(m,'error'), warning: m => show(m,'warning'), info: m => show(m,'info') };
})();

// ─── LOADING OVERLAY ─────────────────────────────────────────────────
PharmacyApp.loader = (function () {
    let overlay = null;
    function show() {
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'loading-overlay';
            overlay.innerHTML = '<div class="loading-spinner"></div>';
            document.body.appendChild(overlay);
        }
        overlay.style.display = 'flex';
    }
    function hide() { if (overlay) overlay.style.display = 'none'; }
    return { show, hide };
})();

// ─── AJAX HELPER ─────────────────────────────────────────────────────
PharmacyApp.ajax = async function (url, options = {}) {
    try {
        const resp = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) },
            ...options
        });
        const ct = resp.headers.get('Content-Type') || '';
        if (ct.includes('application/json')) return await resp.json();
        return { success: resp.ok, html: await resp.text() };
    } catch (err) {
        console.error('PharmacyApp.ajax error:', err);
        return { success: false, message: 'Network error. Please try again.' };
    }
};

// ─── CSRF TOKEN HELPER ───────────────────────────────────────────────
PharmacyApp.getCSRF = function () {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
};

// ─── FORM AJAX SUBMIT ────────────────────────────────────────────────
PharmacyApp.submitForm = function (form, options = {}) {
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn = form.querySelector('[type="submit"]');
        const originalText = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }

        const data = new FormData(form);
        const csrf = PharmacyApp.getCSRF();
        if (csrf) data.append('csrf_token', csrf);

        const result = await PharmacyApp.ajax(form.action || window.location.href, {
            method: 'POST',
            body: data
        });

        if (btn) { btn.disabled = false; btn.innerHTML = originalText; }

        if (result.success) {
            PharmacyApp.toast.success(result.message || 'Saved successfully!');
            if (options.onSuccess) options.onSuccess(result);
            if (options.redirect) setTimeout(() => window.location.href = options.redirect, 800);
        } else {
            PharmacyApp.toast.error(result.message || 'An error occurred.');
            if (options.onError) options.onError(result);
        }
    });
};

// ─── CONFIRM DELETE ──────────────────────────────────────────────────
PharmacyApp.confirmDelete = function (url, options = {}) {
    const msg = options.message || 'Are you sure you want to delete this item?';
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: options.title || 'Confirm Delete',
            text: msg,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: options.confirmText || 'Yes, Delete',
            cancelButtonText: 'Cancel'
        }).then(result => {
            if (result.isConfirmed) PharmacyApp._doDelete(url, options);
        });
    } else if (confirm(msg)) {
        PharmacyApp._doDelete(url, options);
    }
};

PharmacyApp._doDelete = async function (url, options = {}) {
    const result = await PharmacyApp.ajax(url, { method: 'POST', body: new URLSearchParams({ action: 'delete', csrf_token: PharmacyApp.getCSRF() }) });
    if (result.success) {
        PharmacyApp.toast.success(result.message || 'Deleted successfully!');
        if (options.onSuccess) options.onSuccess(result);
        else if (options.rowId) document.getElementById(options.rowId)?.remove();
        else if (options.reload) setTimeout(() => window.location.reload(), 600);
    } else {
        PharmacyApp.toast.error(result.message || 'Delete failed.');
    }
};

// ─── THEME SWITCHER ──────────────────────────────────────────────────
PharmacyApp.switchTheme = async function (themeId) {
    const result = await PharmacyApp.ajax('ajax/apply_theme.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ theme_id: themeId })
    });
    if (result.success) {
        // Inject new CSS vars
        const existing = document.getElementById('theme-engine-vars');
        if (existing) existing.remove();
        document.head.insertAdjacentHTML('beforeend', result.css_vars);
        PharmacyApp.toast.success('Theme applied: ' + (result.theme_name || themeId));
        // Update active indicator in theme picker if present
        document.querySelectorAll('[data-theme-id]').forEach(el => {
            el.classList.toggle('active', el.dataset.themeId === themeId);
        });
    } else {
        PharmacyApp.toast.error(result.message || 'Failed to apply theme.');
    }
};

// ─── DEBOUNCE HELPER ─────────────────────────────────────────────────
PharmacyApp.debounce = function (fn, delay = 350) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
};

// ─── AUTO-INIT AJAX FORMS ────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    // Forms with data-ajax="true"
    document.querySelectorAll('form[data-ajax="true"]').forEach(form => {
        PharmacyApp.submitForm(form, {
            redirect: form.dataset.redirect || null,
            reload:   form.dataset.reload === 'true'
        });
    });

    // Delete links/buttons with data-confirm-delete
    document.querySelectorAll('[data-confirm-delete]').forEach(el => {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            PharmacyApp.confirmDelete(this.href || this.dataset.url, {
                message: this.dataset.confirmDelete,
                reload: true
            });
        });
    });

    // Theme picker cards
    document.querySelectorAll('[data-theme-id]').forEach(el => {
        el.addEventListener('click', function () {
            PharmacyApp.switchTheme(this.dataset.themeId);
        });
    });

    // Page load animation
    document.body.classList.add('loaded');
});

// Expose for inline use
window.showToast = (msg, type) => PharmacyApp.toast.show(msg, type);
