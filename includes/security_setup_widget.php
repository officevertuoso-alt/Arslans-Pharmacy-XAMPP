<?php
/**
 * Security Setup Widget
 * File: includes/security_setup_widget.php
 *
 * Include this at the BOTTOM of dashboard.php (before </body>):
 *   <?php require_once 'includes/security_setup_widget.php'; ?>
 *
 * It auto-shows a modal if $_SESSION['security_setup_required'] is set.
 */
if (empty($_SESSION['security_setup_required'])) return; // nothing to show

$_csrfToken  = generateCSRFToken();
$_setupUrl   = rtrim(BASE_URL, '/') . '/auth/save_security_setup.php';
?>

<!-- ═══ SECURITY SETUP MODAL ═══════════════════════════════════════════════ -->
<div class="modal fade" id="securitySetupModal" tabindex="-1"
     aria-labelledby="secModalLabel" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-440">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header border-0 pb-0"
                 style="background:linear-gradient(135deg,#3b82f6,#6366f1);padding:20px 24px 16px;">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:36px;height:36px;background:rgba(255,255,255,.2);border-radius:9px;
                                display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-shield-alt" style="color:#fff;font-size:16px;"></i>
                    </div>
                    <h5 class="modal-title mb-0" id="secModalLabel"
                        style="color:#fff;font-weight:700;font-size:16px;">
                        Security Setup Required
                    </h5>
                </div>
            </div>

            <!-- Body -->
            <div class="modal-body" style="padding:24px;">
                <p style="font-size:13px;color:#6b7280;margin-bottom:20px;">
                    Please set a security question and answer.
                    This will be used to recover your password in the future.
                </p>

                <form id="securitySetupForm" autocomplete="off">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_csrfToken) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold"
                               style="font-size:13px;">Security Question</label>
                        <select name="security_question"
                                class="form-select form-select-sm" required>
                            <option value="">-- Select a question --</option>
                            <option value="phone">Your Phone Number</option>
                            <option value="nickname">Your Nickname</option>
                            <option value="pet">Your Favourite Pet</option>
                            <option value="hometown">Your Hometown</option>
                            <option value="dob">Your Date of Birth</option>
                            <option value="cnic">Your ID Card Number</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"
                               style="font-size:13px;">Your Answer</label>
                        <input type="text" name="security_answer"
                               class="form-control form-control-sm"
                               placeholder="Your answer (case-sensitive)"
                               required minlength="2">
                        <div class="form-text" style="font-size:11px;">
                            Answer is case-sensitive and will be hashed.
                        </div>
                    </div>

                    <div id="setupResult" class="mb-2"></div>

                    <button type="submit" class="btn btn-primary w-100 fw-semibold"
                            id="btnSaveSetup"
                            style="background:linear-gradient(135deg,#3b82f6,#6366f1);
                                   border:none;border-radius:9px;padding:10px;">
                        <i class="fas fa-shield-alt me-1"></i>Save &amp; Continue
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// ── Auto-show on page load ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('securitySetupModal');
    if (!el) return;
    var modal = new bootstrap.Modal(el, { backdrop: 'static', keyboard: false });
    modal.show();
});

// ── Form submit via AJAX ───────────────────────────────────────────────────
(function () {
    var SETUP_URL = '<?= $_setupUrl ?>';

    document.getElementById('securitySetupForm').addEventListener('submit', function (e) {
        e.preventDefault();

        var btn     = document.getElementById('btnSaveSetup');
        var result  = document.getElementById('setupResult');
        var formData = new FormData(this);

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Saving...';
        result.innerHTML = '';

        fetch(SETUP_URL, {
            method: 'POST',
            body:   formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (res) {
            if (res.success) {
                result.innerHTML =
                    '<div class="alert alert-success py-2 mb-0">' +
                    '<i class="fas fa-check-circle me-1"></i>' + res.message + '</div>';
                setTimeout(function () {
                    window.location.href = res.redirect || window.location.href;
                }, 1000);
            } else {
                result.innerHTML =
                    '<div class="alert alert-danger py-2 mb-0">' +
                    '<i class="fas fa-exclamation-circle me-1"></i>' +
                    (res.message || 'Unknown error.') + '</div>';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-shield-alt me-1"></i>Save &amp; Continue';
            }
        })
        .catch(function (err) {
            result.innerHTML =
                '<div class="alert alert-danger py-2 mb-0">' +
                '<i class="fas fa-exclamation-circle me-1"></i>' +
                'Could not reach server (' + err.message + '). Check that <code>auth/save_security_setup.php</code> exists.</div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-shield-alt me-1"></i>Save &amp; Continue';
        });
    });
}());
</script>
<!-- ═══ END SECURITY SETUP MODAL ════════════════════════════════════════════ -->