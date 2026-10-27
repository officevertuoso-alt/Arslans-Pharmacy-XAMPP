<?php
/**
 * Forgot Password – Security Question Recovery
 * File: auth/forgot_password.php
 * Version: V2.0 — Added AJAX/JSON mode for login.php modal
 *
 * Supports both:
 *  1. AJAX POST (from login.php modal via $.post) — returns JSON
 *  2. Direct page visit — renders HTML form (two-step)
 */

require_once '../config/config.php';
require_once '../includes/auth.php';

// ── AJAX JSON mode ────────────────────────────────────────────────────────────
// Triggered by login.php modal (jQuery $.post — sends X-Requested-With header)
// OR when _ajax=1 is posted (fallback for forms that don't set XHR header)
$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
     strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    !empty($_POST['_ajax'])
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json');

    // ── Step 1: verify security answer ──────────────────────────────────────
    // Triggered when: token is NOT posted (first step)
    $postedToken = trim($_POST['token'] ?? '');
    $isStep2     = ($postedToken !== '' && !isset($_POST['html_step2']));

    if (!$isStep2) {
        // STEP 1
        $username = sanitize($_POST['username'] ?? '');
        $question = sanitize($_POST['question'] ?? '');
        $answer   = trim($_POST['answer'] ?? '');

        if (empty($username) || empty($question) || empty($answer)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit;
        }

        // Ensure security helpers are loaded
        if (!function_exists('verifySecurityAnswer')) {
            require_once '../includes/security_helpers.php';
        }

        $userId = verifySecurityAnswer($username, $question, $answer);
        if ($userId) {
            $token = bin2hex(random_bytes(32));
            if (!isset($_SESSION['reset_token'])) {
                $_SESSION['reset_token'] = [];
            }
            $_SESSION['reset_token'][$token] = [
                'user_id' => $userId,
                'expires' => time() + 600,
            ];
            echo json_encode(['success' => true, 'token' => $token]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid username, security question, or answer.']);
        }
        exit;
    }

    // ── Step 2: reset password ───────────────────────────────────────────────
    $token   = $postedToken;
    $newPass = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? $newPass;

    if (empty($token) ||
        !isset($_SESSION['reset_token'][$token]) ||
        $_SESSION['reset_token'][$token]['expires'] < time()) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired token. Please start over.']);
        exit;
    }
    if (strlen($newPass) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        exit;
    }
    if (!preg_match('/[0-9]/', $newPass)) {
        echo json_encode(['success' => false, 'message' => 'Password must contain at least one number.']);
        exit;
    }
    if ($newPass !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit;
    }

    $userId = (int)$_SESSION['reset_token'][$token]['user_id'];
    $hashed = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
    $db = db();
    $db->query("UPDATE users SET password = :p WHERE id = :id");
    $db->bind(':p',  $hashed);
    $db->bind(':id', $userId);

    if ($db->execute()) {
        unset($_SESSION['reset_token'][$token]);
        echo json_encode(['success' => true, 'message' => 'Password reset successfully! You can now log in.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
    exit;
}

// ── HTML page mode (direct visit) ─────────────────────────────────────────────

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$step     = 1;
$error    = '';
$success  = '';
$username = '';
$token    = $_GET['token'] ?? '';

// Token in URL → go straight to step 2
if (!empty($token) && isset($_SESSION['reset_token'][$token]) && $_SESSION['reset_token'][$token]['expires'] > time()) {
    $step   = 2;
    $userId = $_SESSION['reset_token'][$token]['user_id'];
    $user   = getOne("SELECT username FROM users WHERE id = :id", [':id' => $userId]);
    if ($user) {
        $username = $user['username'];
    } else {
        $error = 'Invalid token. Please restart the recovery process.';
        $step  = 1;
    }
}

// Handle direct HTML POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['html_step1'])) {
        $username = sanitize($_POST['username'] ?? '');
        $question = sanitize($_POST['question'] ?? '');
        $answer   = trim($_POST['answer'] ?? '');

        if (empty($username) || empty($question) || empty($answer)) {
            $error = 'All fields are required.';
        } else {
            $userId = verifySecurityAnswer($username, $question, $answer);
            if ($userId) {
                $token = bin2hex(random_bytes(32));
                $_SESSION['reset_token'][$token] = [
                    'user_id' => $userId,
                    'expires' => time() + 600,
                ];
                redirect('forgot_password.php?token=' . $token);
            } else {
                $error = 'Invalid username, security question, or answer.';
            }
        }
    }

    if (isset($_POST['html_step2'])) {
        $token   = $_POST['token']           ?? '';
        $newPass = $_POST['new_password']    ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($token) || !isset($_SESSION['reset_token'][$token]) || $_SESSION['reset_token'][$token]['expires'] < time()) {
            $error = 'Invalid or expired token. Please restart.';
            $step  = 1;
        } elseif (strlen($newPass) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[0-9]/', $newPass)) {
            $error = 'Password must contain at least one number.';
        } elseif ($newPass !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $userId = $_SESSION['reset_token'][$token]['user_id'];
            $hashed = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db = db();
            $db->query("UPDATE users SET password = :p WHERE id = :id");
            $db->bind(':p', $hashed);
            $db->bind(':id', $userId);
            if ($db->execute()) {
                unset($_SESSION['reset_token'][$token]);
                $success = 'Password reset successfully. You can now <a href="login.php">log in</a>.';
                $step    = 3;
            } else {
                $error = 'Database error. Please try again.';
            }
        }
    }
}

// Questions list for HTML page dropdown
$questions = [
    'phone'    => 'Your Phone Number',
    'nickname' => 'Your Nickname',
    'pet'      => 'Your Favourite Pet',
    'hometown' => 'Your Hometown',
    'dob'      => 'Your Date of Birth',
    'cnic'     => 'Your ID Card Number',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .strength-bar { height: 5px; border-radius: 3px; transition: all .3s; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card">
                <div class="card-header bg-primary text-white text-center">
                    <i class="fas fa-key fa-2x mb-2"></i>
                    <h4 class="mb-0">Forgot Password</h4>
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <?php if ($step == 1): ?>
                        <p class="text-muted">Enter your username and answer your security question.</p>
                        <div id="step1AlertArea" class="mb-3"></div>
                        <form method="POST" action=""
                              data-pharm-form
                              data-loading-text="Verifying…"
                              data-alert-target="#step1AlertArea"
                              novalidate>
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" required autofocus value="<?= htmlspecialchars($username) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Security Question</label>
                                <select name="question" class="form-select" required>
                                    <option value="">Select your security question</option>
                                    <?php foreach ($questions as $val => $label): ?>
                                        <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Your Answer</label>
                                <input type="text" name="answer" class="form-control" required>
                                <div class="form-text text-muted">Answer is case‑sensitive.</div>
                            </div>
                            <button type="submit" name="html_step1" data-submit-btn class="btn btn-primary w-100">
                                <i class="fas fa-check-circle me-2"></i>Verify & Continue
                            </button>
                        </form>

                    <?php elseif ($step == 2): ?>
                        <p class="text-muted">Set a new password for <strong><?= htmlspecialchars($username) ?></strong>.</p>
                        <div id="step2AlertArea" class="mb-3"></div>
                        <form method="POST" action=""
                              data-pharm-form
                              data-success-msg="Password reset successfully!"
                              data-redirect="login.php"
                              data-redirect-delay="2000"
                              data-loading-text="Resetting Password…"
                              data-alert-target="#step2AlertArea"
                              novalidate>
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" id="newPass" class="form-control" required>
                                <div class="d-flex gap-1 mt-2">
                                    <?php for ($i=1;$i<=4;$i++): ?><div class="strength-bar flex-fill bg-secondary" id="s<?=$i?>"></div><?php endfor; ?>
                                </div>
                                <small id="strengthLabel" class="text-muted"></small>
                                <div class="mt-1 small">
                                    <span id="cr-length" style="color:#6c757d">Min 8 characters</span>
                                    &nbsp;<span id="cr-number" style="color:#6c757d">1 number</span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" name="confirm_password" id="confirmPass" class="form-control" required>
                                <div id="matchStatus" class="small mt-1"></div>
                            </div>
                            <button type="submit" name="html_step2" data-submit-btn class="btn btn-success w-100">
                                <i class="fas fa-save me-2"></i>Reset Password
                            </button>
                        </form>

                    <?php elseif ($step == 3): ?>
                        <div class="text-center">
                            <a href="login.php" class="btn btn-primary"><i class="fas fa-sign-in-alt me-2"></i>Go to Login</a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer text-center bg-light">
                    <a href="login.php" class="text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($step == 2): ?>
<script>
function calcStrength(pw) {
    let s=0; if(pw.length>=8)s++; if(pw.length>=12)s++; if(/[0-9]/.test(pw))s++; if(/[^a-zA-Z0-9]/.test(pw))s++;
    return Math.min(s,4);
}
function updateStrength() {
    const pw=document.getElementById('newPass').value, s=pw.length?calcStrength(pw):0;
    const colors=['bg-danger','bg-warning','bg-info','bg-success'], labels=['Very Weak','Weak','Fair','Strong'];
    [1,2,3,4].forEach((n,i)=>{
        const b=document.getElementById('s'+n);
        b.className='strength-bar flex-fill '+(i<s?colors[s-1]:'bg-secondary');
    });
    const lbl=document.getElementById('strengthLabel');
    lbl.textContent=pw.length?'Strength: '+labels[s-1]:'';
    document.getElementById('cr-length').style.color=pw.length>=8?'#198754':'#dc3545';
    document.getElementById('cr-number').style.color=/[0-9]/.test(pw)?'#198754':'#dc3545';
    checkMatch();
}
function checkMatch() {
    const pw=document.getElementById('newPass').value, cpw=document.getElementById('confirmPass').value;
    const d=document.getElementById('matchStatus');
    if(!cpw){d.innerHTML='';return;}
    d.innerHTML=pw===cpw?'<span class="text-success"><i class="fas fa-check-circle me-1"></i>Match</span>':'<span class="text-danger"><i class="fas fa-times-circle me-1"></i>No match</span>';
}
document.getElementById('newPass').addEventListener('input',updateStrength);
document.getElementById('confirmPass').addEventListener('input',checkMatch);
</script>
<?php endif; ?>

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
