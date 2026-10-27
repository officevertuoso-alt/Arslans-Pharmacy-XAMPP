/**
 * ════════════════════════════════════════════════════════════════
 * PHARMACY ERP — UNIVERSAL FORM SYSTEM  (Phase 10)
 * FILE: js/universal_form.js
 * ════════════════════════════════════════════════════════════════
 *
 * Provides a single, reusable PharmForm class that replaces
 * duplicated form-handling code across the entire application.
 *
 * FEATURES:
 *   ✔ HTML5 + custom validation with field-level error messages
 *   ✔ AJAX submission (fetch-based, FormData or URLSearchParams)
 *   ✔ Loading state  — button spinner + disabled during request
 *   ✔ Success state  — toast + optional redirect / reset / callback
 *   ✔ Error state    — inline alert + re-enable button
 *   ✔ Network error  — clear message, never silent failure
 *   ✔ data-pharm-form attribute for zero-JS auto-init
 *
 * USAGE — Auto-init (no JS needed on the page):
 *   <form data-pharm-form
 *         data-success-msg="Saved!"
 *         data-redirect="list.php"
 *         data-loading-text="Saving…">
 *     …
 *     <button type="submit" data-submit-btn>Save</button>
 *   </form>
 *
 * USAGE — Manual init:
 *   const f = new PharmForm('#myForm', {
 *     successMsg   : 'Record saved!',
 *     redirect     : 'list.php',        // or false
 *     redirectDelay: 1500,
 *     resetOnSuccess: true,
 *     loadingText  : 'Saving…',
 *     onSuccess    : function(data) { … },
 *     onError      : function(msg, data) { … },
 *     validate     : function(form) { return true/false; }
 *   });
 *
 * SUPPORTED ATTRIBUTES on <form>:
 *   data-pharm-form          — auto-init trigger
 *   data-success-msg         — toast message on success
 *   data-redirect            — URL to navigate after success
 *   data-redirect-delay      — ms before redirect (default 1500)
 *   data-loading-text        — button label during request
 *   data-reset-on-success    — "true" to reset form fields
 *   data-alert-target        — CSS selector for inline alert area
 *
 * SUPPORTED ATTRIBUTES on submit button:
 *   data-loading-text        — overrides form-level loading text
 *   data-original-html       — auto-saved/restored (internal)
 * ════════════════════════════════════════════════════════════════
 */

(function (global) {
    'use strict';

    /* ── Helpers ────────────────────────────────────────────── */

    function qs(sel, ctx) {
        return (ctx || document).querySelector(sel);
    }

    function buildAlert(message, type) {
        type = type || 'danger';
        var icons = {
            success : 'bi-check-circle',
            danger  : 'bi-exclamation-triangle',
            warning : 'bi-exclamation-circle',
            info    : 'bi-info-circle'
        };
        var icon = icons[type] || icons.danger;
        return '<div class="alert alert-' + type + ' alert-dismissible fade show pharma-form-alert" role="alert">' +
               '<i class="bi ' + icon + ' me-2"></i>' +
               escHtml(message) +
               '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
               '</div>';
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function showToast(message, type) {
        if (global.showToast) {
            global.showToast(message, type);
            return;
        }
        // Fallback minimal toast (in case script.js not loaded yet)
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;top:80px;right:20px;z-index:99999;padding:12px 18px;' +
            'background:' + (type === 'success' ? '#198754' : '#dc3545') + ';color:#fff;' +
            'border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.25);font-size:14px;max-width:340px;';
        t.textContent = message;
        document.body.appendChild(t);
        setTimeout(function () {
            t.style.opacity = '0';
            t.style.transition = 'opacity .3s';
            setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 300);
        }, 3500);
    }

    /* ════════════════════════════════════════════════════════
       PharmForm Class
    ════════════════════════════════════════════════════════ */
    function PharmForm(selector, options) {
        var self = this;

        // Accept selector string, DOM element, or jQuery object
        if (typeof selector === 'string') {
            self.form = document.querySelector(selector);
        } else if (selector && selector.jquery) {
            self.form = selector[0];
        } else {
            self.form = selector;
        }

        if (!self.form) {
            console.warn('[PharmForm] Form not found:', selector);
            return;
        }

        /* ── Merge options with data-* attrs ─────────────── */
        var d = self.form.dataset;
        self.options = Object.assign({
            successMsg    : d.successMsg    || 'Saved successfully!',
            redirect      : d.redirect      || false,
            redirectDelay : parseInt(d.redirectDelay || '1500', 10),
            resetOnSuccess: d.resetOnSuccess === 'true',
            loadingText   : d.loadingText   || 'Please wait…',
            alertTarget   : d.alertTarget   || null,
            onSuccess     : null,
            onError       : null,
            validate      : null   // custom validator fn(form) → bool
        }, options || {});

        /* ── Find submit button ──────────────────────────── */
        self._findBtn = function () {
            return self.form.querySelector('[data-submit-btn], [type="submit"]');
        };

        /* ── Find / create alert container ──────────────── */
        self._alertContainer = function () {
            if (self.options.alertTarget) {
                return document.querySelector(self.options.alertTarget);
            }
            var sel = d.alertTarget ? document.querySelector(d.alertTarget) : null;
            if (sel) return sel;
            // Look for an existing #alertArea or .form-alert-area inside form
            var inside = self.form.querySelector('#alertArea, .form-alert-area, [data-alert-area]');
            if (inside) return inside;
            // Create one before the form
            var div = document.createElement('div');
            div.className = 'pharma-alert-area mb-3';
            self.form.parentNode.insertBefore(div, self.form);
            return div;
        };

        /* ── Loading state ───────────────────────────────── */
        self._setLoading = function (loading) {
            var btn = self._findBtn();
            if (!btn) return;

            if (loading) {
                btn.dataset.originalHtml = btn.innerHTML;
                var label = btn.dataset.loadingText || self.options.loadingText;
                btn.disabled  = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>'
                              + escHtml(label);
            } else {
                btn.disabled  = false;
                if (btn.dataset.originalHtml) {
                    btn.innerHTML = btn.dataset.originalHtml;
                    delete btn.dataset.originalHtml;
                }
            }
        };

        /* ── Show inline error / success alert ───────────── */
        self._showAlert = function (message, type) {
            var container = self._alertContainer();
            if (!container) return;
            // Remove previous pharma-form-alerts
            container.querySelectorAll('.pharma-form-alert').forEach(function (el) {
                el.remove();
            });
            container.insertAdjacentHTML('beforeend', buildAlert(message, type));
        };

        /* ── Handle success response ─────────────────────── */
        self._onSuccess = function (data) {
            self._setLoading(false);

            var msg = (data && data.message) ? data.message : self.options.successMsg;
            showToast(msg, 'success');

            if (self.options.resetOnSuccess) {
                self.form.reset();
                self.form.classList.remove('was-validated');
            }

            if (self.options.onSuccess) {
                self.options.onSuccess(data);
            }

            if (self.options.redirect) {
                setTimeout(function () {
                    window.location.href = self.options.redirect;
                }, self.options.redirectDelay);
            }
        };

        /* ── Handle error response ───────────────────────── */
        self._onError = function (message, data) {
            self._setLoading(false);
            var msg = message || 'An error occurred. Please try again.';
            self._showAlert(msg, 'danger');
            showToast(msg, 'error');

            if (self.options.onError) {
                self.options.onError(msg, data);
            }
        };

        /* ── Validate form ───────────────────────────────── */
        self._validate = function () {
            // Custom validator takes priority
            if (typeof self.options.validate === 'function') {
                var ok = self.options.validate(self.form);
                if (!ok) return false;
            }

            // HTML5 constraint validation
            if (!self.form.checkValidity()) {
                self.form.classList.add('was-validated');
                var first = self.form.querySelector(':invalid');
                if (first) {
                    first.focus();
                    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return false;
            }

            return true;
        };

        /* ── Main submit handler ─────────────────────────── */
        self._handleSubmit = function (e) {
            e.preventDefault();

            if (!self._validate()) return;

            self._setLoading(true);

            // Clear any old alerts
            var container = self._alertContainer();
            if (container) {
                container.querySelectorAll('.pharma-form-alert').forEach(function (el) {
                    el.remove();
                });
            }

            var action = self.form.action || window.location.href;
            var method = (self.form.method || 'POST').toUpperCase();
            var fd     = new FormData(self.form);

            // Add AJAX flag for backend detection
            fd.append('ajax_request', '1');

            fetch(action, {
                method  : method,
                body    : fd,
                headers : { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
            .then(function (response) {
                var contentType = response.headers.get('content-type') || '';
                if (contentType.indexOf('application/json') !== -1) {
                    return response.json();
                }
                // Non-JSON response — treat as error
                return response.text().then(function (text) {
                    throw new Error('Server returned non-JSON response. Status: ' + response.status);
                });
            })
            .then(function (data) {
                if (data && data.success === true) {
                    self._onSuccess(data);
                } else {
                    var msg = (data && data.message) ? data.message : 'Request failed. Please try again.';
                    self._onError(msg, data);
                }
            })
            .catch(function (err) {
                console.error('[PharmForm] Network/parse error:', err);
                self._onError('Network error: ' + (err.message || 'Could not connect to server.'), null);
            });
        };

        /* ── Bind ────────────────────────────────────────── */
        self.form.addEventListener('submit', self._handleSubmit);

        /* ── Public API ──────────────────────────────────── */
        self.destroy = function () {
            self.form.removeEventListener('submit', self._handleSubmit);
        };

        self.submit = function () {
            self.form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        };

        self.reset = function () {
            self.form.reset();
            self.form.classList.remove('was-validated');
            var container = self._alertContainer();
            if (container) {
                container.querySelectorAll('.pharma-form-alert').forEach(function (el) { el.remove(); });
            }
        };
    }

    /* ════════════════════════════════════════════════════════
       Auto-init: data-pharm-form attribute
    ════════════════════════════════════════════════════════ */
    function autoInit() {
        document.querySelectorAll('form[data-pharm-form]').forEach(function (form) {
            if (form._pharmFormInstance) return; // already initialised
            var instance = new PharmForm(form);
            form._pharmFormInstance = instance;
        });
    }

    /* Run auto-init after DOM is ready */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        autoInit();
    }

    /* ════════════════════════════════════════════════════════
       Export
    ════════════════════════════════════════════════════════ */
    global.PharmForm = PharmForm;

}(window));