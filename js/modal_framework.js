/**
 * PharmaCare Pro — Modal Framework
 * Phase 11 — Reusable modal utilities
 * 
 * Provides:
 *   PharmaModal.confirm()   — standardised confirm dialog
 *   PharmaModal.alert()     — info/warn/success/error alert dialog
 *   PharmaModal.loading()   — show/hide a loading overlay inside a modal
 *   PharmaModal.resize()    — programmatically apply size class
 */

(function (global) {
    'use strict';

    /* ── Shared backdrop / focus-trap handled by Bootstrap 5 ── */

    /* ── Confirm modal ──────────────────────────────────────────────────────── */
    /**
     * PharmaModal.confirm({
     *   title      : 'Delete product?',
     *   body       : 'This cannot be undone.',
     *   icon       : 'fas fa-trash-alt',           // optional FA icon
     *   iconColor  : 'text-danger',                 // optional
     *   confirmText: 'Delete',                      // default 'Confirm'
     *   cancelText : 'Cancel',                      // default 'Cancel'
     *   variant    : 'danger',                      // header colour: danger|warning|success|info|default
     * }).then(confirmed => { if(confirmed) { ... } });
     */
    function confirm(opts) {
        return new Promise(function (resolve) {
            var id = 'pharma-confirm-' + Date.now();
            var variant = opts.variant || 'danger';
            var headerClass = variant === 'default' ? '' : 'modal-header-' + variant;
            var confirmBtnClass = variant === 'danger'   ? 'btn-danger'
                                : variant === 'warning'  ? 'btn-warning'
                                : variant === 'success'  ? 'btn-success'
                                : variant === 'info'     ? 'btn-info'
                                : 'btn-primary';

            var iconHtml = opts.icon
                ? '<i class="' + opts.icon + ' ' + (opts.iconColor || 'text-' + variant) + ' fa-3x mb-3 d-block"></i>'
                : '';

            var html = [
                '<div class="modal fade" id="' + id + '" tabindex="-1" aria-modal="true" role="dialog">',
                '  <div class="modal-dialog modal-dialog-centered modal-440">',
                '    <div class="modal-content modal-confirm">',
                '      <div class="modal-header ' + headerClass + '">',
                '        <h5 class="modal-title">' + (opts.title || 'Confirm') + '</h5>',
                '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>',
                '      </div>',
                '      <div class="modal-body text-center py-4">',
                '        ' + iconHtml,
                '        <p class="mb-0" style="color:var(--pharmacy-text)">' + (opts.body || 'Are you sure?') + '</p>',
                '      </div>',
                '      <div class="modal-footer justify-content-center gap-2">',
                '        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' + (opts.cancelText || 'Cancel') + '</button>',
                '        <button type="button" class="btn ' + confirmBtnClass + ' btn-confirm-action">' + (opts.confirmText || 'Confirm') + '</button>',
                '      </div>',
                '    </div>',
                '  </div>',
                '</div>'
            ].join('\n');

            document.body.insertAdjacentHTML('beforeend', html);
            var el  = document.getElementById(id);
            var bsm = new bootstrap.Modal(el, { backdrop: 'static', keyboard: false });

            el.querySelector('.btn-confirm-action').addEventListener('click', function () {
                bsm.hide();
                resolve(true);
            });

            el.addEventListener('hide.bs.modal', function () {
                resolve(false);
            });

            el.addEventListener('hidden.bs.modal', function () {
                el.remove();
            });

            bsm.show();
        });
    }

    /* ── Alert modal ────────────────────────────────────────────────────────── */
    /**
     * PharmaModal.alert({
     *   title   : 'Saved!',
     *   body    : 'Your changes were saved successfully.',
     *   icon    : 'fas fa-check-circle',
     *   variant : 'success',    // success|danger|warning|info|default
     *   btnText : 'OK',
     * });
     */
    function alert(opts) {
        return new Promise(function (resolve) {
            var id      = 'pharma-alert-' + Date.now();
            var variant = opts.variant || 'info';
            var headerClass = variant === 'default' ? '' : 'modal-header-' + variant;
            var iconHtml = opts.icon
                ? '<i class="' + opts.icon + ' text-' + variant + ' fa-3x mb-3 d-block"></i>'
                : '';

            var html = [
                '<div class="modal fade" id="' + id + '" tabindex="-1" aria-modal="true" role="dialog">',
                '  <div class="modal-dialog modal-dialog-centered modal-440">',
                '    <div class="modal-content modal-confirm">',
                '      <div class="modal-header ' + headerClass + '">',
                '        <h5 class="modal-title">' + (opts.title || 'Notice') + '</h5>',
                '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>',
                '      </div>',
                '      <div class="modal-body text-center py-4">',
                '        ' + iconHtml,
                '        <p class="mb-0" style="color:var(--pharmacy-text)">' + (opts.body || '') + '</p>',
                '      </div>',
                '      <div class="modal-footer justify-content-center">',
                '        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">' + (opts.btnText || 'OK') + '</button>',
                '      </div>',
                '    </div>',
                '  </div>',
                '</div>'
            ].join('\n');

            document.body.insertAdjacentHTML('beforeend', html);
            var el  = document.getElementById(id);
            var bsm = new bootstrap.Modal(el);

            el.addEventListener('hidden.bs.modal', function () {
                el.remove();
                resolve();
            });

            bsm.show();
        });
    }

    /* ── Loading overlay inside a modal body ────────────────────────────────── */
    /**
     * var spinner = PharmaModal.loading(modalBodyEl, 'Saving…');
     * spinner.remove(); // when done
     */
    function loading(container, text) {
        var overlay = document.createElement('div');
        overlay.className = 'pharma-modal-loading';
        overlay.innerHTML = [
            '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;',
                           'min-height:120px;gap:12px;color:var(--pharmacy-text)">',
            '  <div class="spinner-border text-primary" style="width:2rem;height:2rem;"></div>',
            '  <span style="font-size:14px;font-weight:500">' + (text || 'Loading…') + '</span>',
            '</div>'
        ].join('');
        overlay.style.cssText = 'position:absolute;inset:0;background:var(--pharmacy-modal-bg,#fff);z-index:10;border-radius:0 0 16px 16px;';
        if (container) {
            container.style.position = 'relative';
            container.appendChild(overlay);
        }
        return overlay;
    }

    /* ── Size helper ────────────────────────────────────────────────────────── */
    /**
     * PharmaModal.resize('#myModal', 600); // sets max-width to 600px class
     */
    var SIZES = [440, 460, 500, 600, 700];
    function resize(selector, px) {
        var el = document.querySelector(selector + ' .modal-dialog');
        if (!el) return;
        SIZES.forEach(function (s) { el.classList.remove('modal-' + s); });
        var cls = 'modal-' + px;
        if (SIZES.indexOf(px) > -1) {
            el.classList.add(cls);
            el.style.maxWidth = '';
        } else {
            el.style.maxWidth = px + 'px';
        }
    }

    /* ── Auto-fix: add aria-modal + role to any modal missing them ── */
    function _autoFix() {
        document.querySelectorAll('.modal.fade:not([role])').forEach(function (el) {
            el.setAttribute('role', 'dialog');
            if (!el.getAttribute('aria-modal')) el.setAttribute('aria-modal', 'true');
        });
    }

    /* ── Auto-dismiss inline .alert inside modals after 5 s ── */
    document.addEventListener('shown.bs.modal', function (e) {
        var alerts = e.target.querySelectorAll('.alert:not([data-permanent])');
        alerts.forEach(function (a) {
            setTimeout(function () {
                var bsA = bootstrap.Alert && bootstrap.Alert.getOrCreateInstance(a);
                if (bsA) { try { bsA.close(); } catch(ex) {} }
            }, 5000);
        });
        _autoFix();
    });

    /* ── Expose globally ── */
    global.PharmaModal = { confirm: confirm, alert: alert, loading: loading, resize: resize };

}(window));
