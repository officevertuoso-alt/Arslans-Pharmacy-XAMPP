<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  PHARMACY ERP — FIRST LOGIN MODAL (Part 1)                     ║
 * ║  /includes/first_login_modal.php                                ║
 * ║                                                                  ║
 * ║  Include this in dashboard.php (or any page that checks the     ║
 * ║  session flag). Shows ONCE after factory reset / first login.   ║
 * ║                                                                  ║
 * ║  Usage in dashboard.php (before </body>):                       ║
 * ║    <?php include __DIR__ . '/includes/first_login_modal.php'; ?>║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

// Only show if flagged by login.php
if (empty($_SESSION['show_admin_update_modal'])) return;

// Consume the flag immediately so it won't show again on next page load
unset($_SESSION['show_admin_update_modal']);
?>
<!-- ════════════════════════════════════════════════════════════
     FIRST-LOGIN MODAL — "Do you want to update admin details?"
     ════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="firstLoginModal" tabindex="-1"
     aria-labelledby="firstLoginModalLabel" aria-modal="true" role="dialog"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <!-- Header -->
            <div class="modal-header border-0 pb-0 pt-4 px-4" style="
                background: linear-gradient(135deg,
                    var(--primary-color, #3b82f6) 0%,
                    var(--accent-color, #667eea) 100%);
                color: #fff;
            ">
                <div class="d-flex align-items-center gap-3">
                    <div style="
                        width: 44px; height: 44px;
                        border-radius: 50%;
                        background: rgba(255,255,255,0.2);
                        display: flex; align-items: center; justify-content: center;
                    ">
                        <i class="fas fa-user-shield fa-lg"></i>
                    </div>
                    <div>
                        <h5 class="modal-title mb-0 fw-bold" id="firstLoginModalLabel">
                            Welcome to Pharmacy ERP
                        </h5>
                        <small style="opacity:.85;">First Login Detected</small>
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div class="modal-body px-4 py-4" style="color: var(--text-color, #e2e8f0);">
                <p class="mb-1" style="font-size:1.05rem; font-weight:600;">
                    Do you want to update your admin details?
                </p>
                <p class="mb-3" style="opacity:.75; font-size:.9rem;">
                    You are currently using default credentials
                    <code style="
                        background: rgba(255,255,255,0.08);
                        padding: 1px 6px;
                        border-radius: 4px;
                        font-size:.85rem;
                    ">arslan / arslan123</code>.
                    It is strongly recommended to update your username, password, and profile.
                </p>

                <div class="rounded-3 p-3 mb-2" style="
                    background: rgba(255, 193, 7, 0.08);
                    border: 1px solid rgba(255, 193, 7, 0.25);
                ">
                    <div class="d-flex gap-2 align-items-start">
                        <i class="fas fa-exclamation-triangle mt-1" style="color:#ffc107;"></i>
                        <div style="font-size:.85rem; color: var(--text-color, #e2e8f0); opacity:.9;">
                            Using default credentials is a <strong>security risk</strong>.
                            Update your password before going live.
                        </div>
                    </div>
                </div>

                <p class="mt-3 mb-0" style="font-size:.82rem; opacity:.6;">
                    You can also update these details later via <em>Settings → Profile</em>.
                </p>
            </div>

            <!-- Footer -->
            <div class="modal-footer border-0 px-4 pb-4 pt-0 d-flex gap-2">
                <!-- SKIP — keep defaults, close modal -->
                <button type="button"
                        class="btn btn-outline-secondary flex-fill"
                        id="firstLoginSkipBtn"
                        style="border-radius:8px;">
                    <i class="fas fa-forward me-1"></i> Skip for Now
                </button>

                <!-- UPDATE — go to profile page -->
                <a href="<?php echo BASE_URL; ?>users/edit.php?id=<?php echo (int)($_SESSION['user_id'] ?? 0); ?>&first_login=1"
                   class="btn flex-fill fw-semibold"
                   style="
                       background: linear-gradient(135deg, var(--primary-color,#3b82f6), var(--accent-color,#667eea));
                       color: #fff;
                       border: none;
                       border-radius: 8px;
                   ">
                    <i class="fas fa-user-edit me-1"></i> Update Admin Details
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-open the modal after page load
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('firstLoginModal');
    if (el && typeof bootstrap !== 'undefined') {
        var modal = new bootstrap.Modal(el);
        modal.show();
    }

    // Skip button — just close
    var skipBtn = document.getElementById('firstLoginSkipBtn');
    if (skipBtn) {
        skipBtn.addEventListener('click', function () {
            var modal = bootstrap.Modal.getInstance(
                document.getElementById('firstLoginModal')
            );
            if (modal) modal.hide();

            // Mark as dismissed via AJAX (optional, prevents re-show on hard refresh)
            fetch('<?php echo BASE_URL; ?>ajax/dismiss_first_login_modal.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'csrf_token=<?php echo htmlspecialchars($_SESSION["csrf_token"] ?? "", ENT_QUOTES); ?>'
            }).catch(function () {});
        });
    }
});
</script>
