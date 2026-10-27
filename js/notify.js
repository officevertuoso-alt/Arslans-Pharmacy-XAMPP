/**
 * PharmaCare Unified Notification Framework
 * Phase 12 — Single source of truth for all JS notifications
 *
 * API:
 *   Notify.success(message [, options])
 *   Notify.error(message [, options])
 *   Notify.warning(message [, options])
 *   Notify.info(message [, options])
 *   Notify.confirm(message, onConfirm [, options])   — replaces Swal.fire confirm dialogs
 *
 * Options (all optional):
 *   duration  — ms before auto-dismiss (default: 4000; 0 = sticky)
 *   title     — bold header above message
 *   onClose   — callback when toast dismissed
 *
 * Legacy shims (zero-breaking-change):
 *   window.showToast(message, type) — still works, delegates here
 *   window.alert(message)           — replaced with Notify.error
 */

(function (window, document) {
    'use strict';

    /* ── 1. Inject styles once ──────────────────────────────────── */
    (function () {
        if (document.getElementById('ph-notify-styles')) return;
        var s = document.createElement('style');
        s.id = 'ph-notify-styles';
        s.textContent = [
            /* Container */
            '#ph-notify-container{',
            '  position:fixed;top:76px;right:18px;z-index:100000;',
            '  display:flex;flex-direction:column;gap:8px;',
            '  max-width:360px;width:calc(100% - 36px);',
            '  pointer-events:none;',
            '}',

            /* Toast base */
            '.ph-toast{',
            '  display:flex;align-items:flex-start;gap:12px;',
            '  padding:13px 16px;border-radius:10px;',
            '  background:#fff;color:#1a1d23;',
            '  box-shadow:0 4px 24px rgba(0,0,0,.14);',
            '  font-size:13.5px;line-height:1.45;',
            '  border-left:4px solid #6c757d;',
            '  pointer-events:all;',
            '  animation:ph-slideIn .28s cubic-bezier(.2,.8,.4,1) forwards;',
            '  position:relative;overflow:hidden;',
            '}',

            /* Type accents */
            '.ph-toast.ph-success{border-left-color:#198754;}',
            '.ph-toast.ph-error  {border-left-color:#dc3545;}',
            '.ph-toast.ph-warning{border-left-color:#e67e22;}',
            '.ph-toast.ph-info   {border-left-color:#0d6efd;}',

            /* Icon */
            '.ph-toast-icon{font-size:18px;flex-shrink:0;margin-top:1px;}',
            '.ph-success .ph-toast-icon{color:#198754;}',
            '.ph-error   .ph-toast-icon{color:#dc3545;}',
            '.ph-warning .ph-toast-icon{color:#e67e22;}',
            '.ph-info    .ph-toast-icon{color:#0d6efd;}',

            /* Body */
            '.ph-toast-body{flex:1;}',
            '.ph-toast-title{font-weight:700;margin-bottom:2px;font-size:13px;}',
            '.ph-toast-msg{color:#444;}',

            /* Close btn */
            '.ph-toast-close{',
            '  flex-shrink:0;background:none;border:none;',
            '  cursor:pointer;color:#999;font-size:16px;line-height:1;',
            '  padding:0 0 0 6px;margin-top:-1px;',
            '}',
            '.ph-toast-close:hover{color:#333;}',

            /* Progress bar */
            '.ph-toast-bar{',
            '  position:absolute;bottom:0;left:0;height:3px;',
            '  border-radius:0 0 0 10px;',
            '  animation:ph-bar linear forwards;',
            '}',
            '.ph-success .ph-toast-bar{background:#198754;}',
            '.ph-error   .ph-toast-bar{background:#dc3545;}',
            '.ph-warning .ph-toast-bar{background:#e67e22;}',
            '.ph-info    .ph-toast-bar{background:#0d6efd;}',

            /* Confirm dialog overlay */
            '.ph-confirm-overlay{',
            '  position:fixed;inset:0;background:rgba(0,0,0,.45);',
            '  z-index:100001;display:flex;align-items:center;justify-content:center;',
            '  animation:ph-fadeIn .2s ease;',
            '}',
            '.ph-confirm-box{',
            '  background:#fff;border-radius:14px;padding:28px 28px 22px;',
            '  max-width:400px;width:calc(100% - 40px);',
            '  box-shadow:0 8px 40px rgba(0,0,0,.2);text-align:center;',
            '  animation:ph-popIn .25s cubic-bezier(.2,.8,.4,1);',
            '}',
            '.ph-confirm-icon{font-size:42px;margin-bottom:12px;}',
            '.ph-confirm-title{font-size:18px;font-weight:700;margin-bottom:8px;color:#1a1d23;}',
            '.ph-confirm-msg{color:#555;margin-bottom:22px;font-size:14px;}',
            '.ph-confirm-actions{display:flex;gap:10px;justify-content:center;}',
            '.ph-confirm-actions button{',
            '  min-width:100px;padding:9px 22px;border-radius:8px;',
            '  font-size:14px;font-weight:600;cursor:pointer;border:none;',
            '  transition:opacity .15s;',
            '}',
            '.ph-confirm-actions button:hover{opacity:.85;}',
            '.ph-btn-cancel{background:#f0f2f5;color:#444;}',
            '.ph-btn-ok{background:#dc3545;color:#fff;}',
            '.ph-btn-ok.ok-primary{background:#0d6efd;}',

            /* Keyframes */
            '@keyframes ph-slideIn{from{opacity:0;transform:translateX(110%)}to{opacity:1;transform:translateX(0)}}',
            '@keyframes ph-slideOut{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(110%)}}',
            '@keyframes ph-bar{from{width:100%}to{width:0%}}',
            '@keyframes ph-fadeIn{from{opacity:0}to{opacity:1}}',
            '@keyframes ph-popIn{from{opacity:0;transform:scale(.88)}to{opacity:1;transform:scale(1)}}',
        ].join('');
        document.head.appendChild(s);
    }());

    /* ── 2. Container ───────────────────────────────────────────── */
    function getContainer() {
        var c = document.getElementById('ph-notify-container');
        if (!c) {
            c = document.createElement('div');
            c.id = 'ph-notify-container';
            document.body.appendChild(c);
        }
        return c;
    }

    /* ── 3. Icon map ────────────────────────────────────────────── */
    var ICONS = {
        success : '<i class="fas fa-check-circle"></i>',
        error   : '<i class="fas fa-times-circle"></i>',
        warning : '<i class="fas fa-exclamation-triangle"></i>',
        info    : '<i class="fas fa-info-circle"></i>'
    };

    /* ── 4. Core toast function ─────────────────────────────────── */
    function show(type, message, options) {
        options  = options  || {};
        var dur  = (options.duration !== undefined) ? options.duration : 4000;
        var title = options.title || '';
        var onClose = options.onClose || null;

        var toast = document.createElement('div');
        toast.className = 'ph-toast ph-' + type;

        var bodyHtml = title
            ? '<div class="ph-toast-title">' + escHtml(title) + '</div>'
                + '<div class="ph-toast-msg">'  + escHtml(message) + '</div>'
            : '<div class="ph-toast-msg">'      + escHtml(message) + '</div>';

        var barHtml = dur > 0
            ? '<div class="ph-toast-bar" style="animation-duration:' + dur + 'ms"></div>'
            : '';

        toast.innerHTML =
            '<div class="ph-toast-icon">' + (ICONS[type] || ICONS.info) + '</div>' +
            '<div class="ph-toast-body">' + bodyHtml + '</div>' +
            '<button class="ph-toast-close" aria-label="Close">&times;</button>' +
            barHtml;

        var container = getContainer();
        container.appendChild(toast);

        function dismiss() {
            toast.style.animation = 'ph-slideOut .25s ease forwards';
            setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
                if (onClose) onClose();
            }, 260);
        }

        toast.querySelector('.ph-toast-close').addEventListener('click', dismiss);

        if (dur > 0) {
            setTimeout(dismiss, dur);
        }

        return { dismiss: dismiss };
    }

    /* ── 5. Confirm dialog ─────────────────────────────────────── */
    function confirm(message, onConfirm, options) {
        options = options || {};
        var title     = options.title      || 'Are you sure?';
        var okText    = options.okText     || 'Confirm';
        var cancelText= options.cancelText || 'Cancel';
        var danger    = (options.danger !== false);   // default dangerous=red
        var icon      = options.icon      || (danger ? '⚠️' : '❓');

        var overlay = document.createElement('div');
        overlay.className = 'ph-confirm-overlay';
        overlay.innerHTML =
            '<div class="ph-confirm-box">' +
            '  <div class="ph-confirm-icon">' + icon + '</div>' +
            '  <div class="ph-confirm-title">' + escHtml(title) + '</div>' +
            '  <div class="ph-confirm-msg">'   + escHtml(message) + '</div>' +
            '  <div class="ph-confirm-actions">' +
            '    <button class="ph-btn-cancel">' + escHtml(cancelText) + '</button>' +
            '    <button class="ph-btn-ok' + (danger ? '' : ' ok-primary') + '">' + escHtml(okText) + '</button>' +
            '  </div>' +
            '</div>';

        document.body.appendChild(overlay);

        function close() {
            overlay.style.animation = 'ph-slideOut .2s ease forwards';
            setTimeout(function () {
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            }, 220);
        }

        overlay.querySelector('.ph-btn-cancel').addEventListener('click', close);
        overlay.querySelector('.ph-btn-ok').addEventListener('click', function () {
            close();
            if (onConfirm) onConfirm();
        });

        // Close on backdrop click
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });
    }

    /* ── 6. Helper ──────────────────────────────────────────────── */
    function escHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;');
    }

    /* ── 7. Public API ──────────────────────────────────────────── */
    window.Notify = {
        success : function (msg, opts) { return show('success', msg, opts); },
        error   : function (msg, opts) { return show('error',   msg, opts); },
        warning : function (msg, opts) { return show('warning', msg, opts); },
        info    : function (msg, opts) { return show('info',    msg, opts); },
        confirm : confirm
    };

    /* ── 8. Legacy shims (zero-breaking-change) ─────────────────── */

    // window.showToast(message, type)  — keep working
    window.showToast = function (message, type) {
        type = type || 'success';
        // map Bootstrap type names
        if (type === 'danger') type = 'error';
        if (window.Notify[type]) { window.Notify[type](message); }
        else                     { window.Notify.info(message); }
    };

    // Replace window.alert with Notify.error toast (non-blocking)
    // Store original for any edge case
    window._nativeAlert = window.alert;
    window.alert = function (message) {
        // Heuristic: treat as error if message looks like a problem, else warning
        var lower = String(message).toLowerCase();
        var isError = /error|fail|invalid|not found|exceed|limit|cannot|can't|unable/i.test(lower);
        if (isError) {
            window.Notify.error(message);
        } else {
            window.Notify.warning(message);
        }
    };

}(window, document));
