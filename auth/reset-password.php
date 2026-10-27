<?php
require_once '../config/config.php';
require_once '../includes/auth.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = '';

// Token missing → back to login
if (empty($token)) {
    header('Location: login.php');
    exit();
}

// ── Verify token is valid & not expired ──────────────────────────
$db = db();
$db->query(
    "SELECT id, username, full_name
     FROM   users
     WHERE  reset_token   = :token
       AND  reset_expires > NOW()
       AND  (status = 1 OR is_active = 1)
     LIMIT  1"
);
$db->bind(':token', $token);
$user = $db->single();

if (!$user) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

// ── POST: process new password ────────────────────────────────────
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $password         = $_POST['password']         ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($password) || empty($confirm_password)) {
        $error = 'Please fill in both password fields.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = 'Password must contain at least one letter and one number.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        // Hash with bcrypt cost 12 (consistent with rest of the system)
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $db->query(
            "UPDATE users
             SET    password      = :password,
                    reset_token   = NULL,
                    reset_expires = NULL,
                    updated_at    = NOW()
             WHERE  id            = :id"
        );
        $db->bind(':password', $hashedPassword);
        $db->bind(':id',       (int)$user['id']);

        if ($db->execute()) {
            $success = 'Your password has been reset successfully!';

            // Log the activity properly (auth.php signature: userId, action, description)
            if (function_exists('logActivity')) {
                logActivity('Password Reset', 'User reset password via reset link', (int)$user['id']);
            }

            // Invalidate any remember-me cookie for security
            if (function_exists('clearRememberToken')) {
                clearRememberToken((int)$user['id']);
            }
        } else {
            $error = 'Failed to reset password. Please try again.';
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(isset($error) && $error
            ? ['success' => false, 'message' => $error]
            : ['success' => true,  'message' => $success ?? 'Password reset!']
        );
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        :root {
            --accent: #3b82f6;
            --accent2: #6366f1;
        }
        body {
            background: linear-gradient(135deg, #0f1729 0%, #1e3a5f 50%, #0f1729 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', sans-serif;
        }
        .reset-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 20px 60px rgba(0,0,0,.4);
            overflow: hidden;
        }
        .reset-header {
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            padding: 28px 24px 24px;
            text-align: center;
            color: #fff;
        }
        .reset-header .icon-wrap {
            width: 64px; height: 64px;
            background: rgba(255,255,255,.15);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 14px;
            border: 1.5px solid rgba(255,255,255,.25);
        }
        .reset-header h4 { font-weight: 700; margin: 0; font-size: 20px; }
        .reset-header p  { margin: 4px 0 0; opacity: .8; font-size: 13px; }
        .card-body       { padding: 32px 28px; }
        .form-label      { font-weight: 600; font-size: 13px; color: #374151; }
        .input-wrap      { position: relative; }
        .input-icon      { position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
                           color: #9ca3af; font-size: 14px; pointer-events: none; }
        .form-control    { padding: 10px 42px 10px 38px; border-radius: 9px;
                           border: 1.5px solid #e5e7eb; font-size: 14px; transition: border-color .2s; }
        .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
        .toggle-btn      { position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
                           background: none; border: none; color: #9ca3af; cursor: pointer;
                           font-size: 14px; padding: 2px; }
        .toggle-btn:hover { color: #374151; }
        .strength-bar-wrap { height: 5px; background: #e5e7eb; border-radius: 4px; overflow: hidden; margin-top: 6px; }
        .strength-bar      { height: 100%; width: 0; transition: all .3s; border-radius: 4px; }
        .btn-reset {
            width: 100%; padding: 12px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border: none; border-radius: 9px; color: #fff;
            font-weight: 600; font-size: 15px; cursor: pointer;
            transition: opacity .2s, transform .15s;
        }
        .btn-reset:hover:not(:disabled) { opacity: .92; transform: translateY(-1px); }
        .btn-reset:disabled { opacity: .6; cursor: not-allowed; }
        .alert { border-radius: 9px; font-size: 14px; }
        .match-hint { font-size: 12px; margin-top: 4px; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-sm-10">
            <div class="reset-card card">

                <div class="reset-header">
                    <div class="icon-wrap">
                        <i class="fas fa-lock-open fa-2x"></i>
                    </div>
                    <h4>Reset Password</h4>
                    <p>Enter your new password below</p>
                </div>

                <div class="card-body">

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                            <?php if (strpos($error, 'invalid or has expired') !== false): ?>
                                <hr class="my-2">
                                <a href="forgot-password.php" class="btn btn-sm btn-outline-danger w-100">
                                    <i class="fas fa-envelope me-1"></i> Request New Reset Link
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success text-center">
                            <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
                            <strong><?php echo htmlspecialchars($success); ?></strong>
                            <p class="mb-2 mt-1" style="font-size:13px;color:#374151;">
                                Redirecting to login in <span id="countdown">3</span>s...
                            </p>
                            <a href="login.php" class="btn btn-success w-100">
                                <i class="fas fa-sign-in-alt me-2"></i> Login Now
                            </a>
                        </div>
                        <script>
                            let sec = 3;
                            const cd = document.getElementById('countdown');
                            const iv = setInterval(() => {
                                sec--;
                                if (cd) cd.textContent = sec;
                                if (sec <= 0) { clearInterval(iv); window.location.href = 'login.php'; }
                            }, 1000);
                        </script>

                    <?php elseif (!$error || strpos($error, 'invalid or has expired') === false): ?>
                    <?php if (!$user): // token invalid — don't show form ?>
                    <?php else: ?>
                        <div id="resetAlertArea" class="mb-3"></div>
                            <form method="POST" action="" id="resetForm"
                                  data-pharm-form
                                  data-success-msg="Password reset successfully!"
                                  data-redirect="login.php"
                                  data-redirect-delay="2000"
                                  data-loading-text="Resetting Password…"
                                  data-alert-target="#resetAlertArea"
                                  novalidate>
                            <!-- New Password -->
                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <div class="input-wrap">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input type="password" name="password" id="passField"
                                           class="form-control" required minlength="8"
                                           autocomplete="new-password"
                                           placeholder="Min. 8 chars, letters & numbers">
                                    <button type="button" class="toggle-btn" onclick="togglePass('passField','eye1')">
                                        <i class="fas fa-eye" id="eye1"></i>
                                    </button>
                                </div>
                                <!-- Strength bar -->
                                <div class="strength-bar-wrap">
                                    <div class="strength-bar" id="strengthBar"></div>
                                </div>
                                <small id="strengthLabel" class="text-muted" style="font-size:11px;"></small>
                            </div>

                            <!-- Confirm Password -->
                            <div class="mb-4">
                                <label class="form-label">Confirm New Password</label>
                                <div class="input-wrap">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input type="password" name="confirm_password" id="confirmField"
                                           class="form-control" required
                                           autocomplete="new-password"
                                           placeholder="Re-enter your password">
                                    <button type="button" class="toggle-btn" onclick="togglePass('confirmField','eye2')">
                                        <i class="fas fa-eye" id="eye2"></i>
                                    </button>
                                </div>
                                <div class="match-hint" id="matchHint"></div>
                            </div>

                            <button type="submit" data-submit-btn class="btn-reset" id="submitBtn" disabled>
                                <i class="fas fa-save me-2"></i> Reset Password
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php endif; ?>

                </div><!-- /card-body -->
            </div><!-- /card -->

            <div class="text-center mt-3" style="font-size:13px;color:rgba(255,255,255,.6);">
                <a href="login.php" style="color:rgba(255,255,255,.8);text-decoration:none;">
                    <i class="fas fa-arrow-left me-1"></i> Back to Login
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// ── Toggle password visibility ─────────────────────────────────
function togglePass(fieldId, iconId) {
    const f = document.getElementById(fieldId);
    const i = document.getElementById(iconId);
    if (!f) return;
    f.type = (f.type === 'password') ? 'text' : 'password';
    i.className = (f.type === 'text') ? 'fas fa-eye-slash' : 'fas fa-eye';
}

// ── Password strength ──────────────────────────────────────────
const passField    = document.getElementById('passField');
const confirmField = document.getElementById('confirmField');
const strengthBar  = document.getElementById('strengthBar');
const strengthLbl  = document.getElementById('strengthLabel');
const matchHint    = document.getElementById('matchHint');
const submitBtn    = document.getElementById('submitBtn');

function calcStrength(val) {
    let score = 0;
    if (val.length >= 8)           score++;
    if (/[A-Z]/.test(val))         score++;
    if (/[0-9]/.test(val))         score++;
    if (/[^A-Za-z0-9]/.test(val))  score++;
    return score;
}

function updateStrength() {
    if (!passField || !strengthBar) return;
    const val   = passField.value;
    const score = calcStrength(val);
    const colors = ['', '#ef4444', '#f97316', '#eab308', '#22c55e'];
    const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
    strengthBar.style.width      = val.length ? ((score / 4) * 100) + '%' : '0';
    strengthBar.style.background = colors[score] || '#e5e7eb';
    strengthLbl.textContent      = val.length ? labels[score] || '' : '';
    updateMatch();
}

function updateMatch() {
    if (!confirmField || !matchHint) return;
    const p = passField ? passField.value : '';
    const c = confirmField.value;

    if (!c.length) {
        matchHint.textContent = '';
        updateSubmit();
        return;
    }
    if (p === c) {
        matchHint.innerHTML = '<span style="color:#16a34a"><i class="fas fa-check me-1"></i>Passwords match</span>';
    } else {
        matchHint.innerHTML = '<span style="color:#ef4444"><i class="fas fa-times me-1"></i>Passwords do not match</span>';
    }
    updateSubmit();
}

function updateSubmit() {
    if (!submitBtn) return;
    const p     = passField    ? passField.value    : '';
    const c     = confirmField ? confirmField.value : '';
    const valid = p.length >= 8 && p === c && calcStrength(p) >= 2;
    submitBtn.disabled = !valid;
}

if (passField)    passField.addEventListener('input',    updateStrength);
if (confirmField) confirmField.addEventListener('input', updateMatch);
</script>

<!-- ═══ Global Footer (Phase 5) ══════════════════════════════════ -->
<footer style="text-align:center;padding:12px 20px;font-size:11px;color:#6c757d;border-top:1px solid #dee2e6;background:#f8f9fa;margin-top:auto;">
    <i class="fas fa-heartbeat me-1"></i>
    Powered by <strong>Dr. Arslan Yasin Sukhera</strong>
    &nbsp;|&nbsp; &copy; <?php echo date('Y'); ?>
    &nbsp;&mdash;&nbsp; <?php echo defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'PharmaCare Pro'; ?>
    &nbsp;|&nbsp; <span style="font-family:monospace;"><?php echo defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : 'v5.0'; ?></span>
</footer>
</body>
</html>
