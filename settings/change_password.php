<?php
/**
 * settings/change_password.php
 * Step 14 — Change Password (UI/UX Enhanced, all logic preserved)
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/settings_functions.php';

requireLogin();

$page_title = 'Change Password';
$error   = '';
$success = '';

$csrf_token = generateCSRFToken();

if (!function_exists('changePassword')) {
    function changePassword($userId, $currentPassword, $newPassword) {
        try {
            $pdo = db()->getConnection();
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => (int)$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || empty($user['password'])) return false;
            $stored = $user['password'];
            $valid  = false;
            if (substr($stored, 0, 3) === '$2y' || substr($stored, 0, 3) === '$2b') {
                $valid = password_verify($currentPassword, $stored);
            }
            if (!$valid && strlen($stored) === 32 && ctype_xdigit($stored)) {
                $valid = (md5($currentPassword) === $stored);
            }
            if (!$valid && $stored === $currentPassword) $valid = true;
            if (!$valid) return false;
            $newHashed = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $upd = $pdo->prepare("UPDATE users SET password = :pass WHERE id = :id");
            return $upd->execute([':pass' => $newHashed, ':id' => (int)$userId]);
        } catch (\PDOException $e) {
            error_log('[changePassword] Error: ' . $e->getMessage());
            return false;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password']     ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'Please fill all fields.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            $error = 'New password must contain at least one letter and one number.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } elseif ($currentPassword === $newPassword) {
            $error = 'New password must be different from current password.';
        } elseif (changePassword((int)$_SESSION['user_id'], $currentPassword, $newPassword)) {
            $success = 'Password changed successfully!';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $success]);
                exit;
            }
            logout();
        } else {
            $error = 'Current password is incorrect.';
        }
    }
    if ($isAjax && isset($error) && $error) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $error]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* ── Page fade-in ─────────────────────────────────────── */
        @keyframes fadeInPage { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        .main-content {
            margin-left: var(--sb-w, 260px);
            padding: 24px;
            transition: margin-left .25s ease;
            animation: fadeInPage .28s ease;
        }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } } }

        /* ── Settings sub-nav ─────────────────────────────────── */
        .settings-subnav {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #dee2e6);
            border-radius: 12px;
            padding: 8px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .settings-subnav a {
            padding: 7px 14px;
            border-radius: 8px;
            font-size: .82rem;
            font-weight: 500;
            color: var(--pharmacy-text, #495057);
            text-decoration: none;
            transition: all .15s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .settings-subnav a:hover {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 8%, var(--pharmacy-card-bg, #fff));
            color: var(--pharmacy-primary, #0d6efd);
        }
        .settings-subnav a.active {
            background: var(--pharmacy-primary, #0d6efd);
            color: #fff;
        }

        /* ── Card box ─────────────────────────────────────────── */
        .card-box {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #dee2e6);
            border-radius: var(--card-border-radius, 15px);
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
            margin-bottom: 24px;
            color: var(--pharmacy-text, #444);
            max-width: 540px;
            margin-left: auto;
            margin-right: auto;
        }
        .section-header {
            background: linear-gradient(135deg, var(--pharmacy-accent, #667eea), var(--pharmacy-primary, #0d6efd));
            color: #fff;
            border-radius: calc(var(--card-border-radius, 15px) - 1px) calc(var(--card-border-radius, 15px) - 1px) 0 0;
            padding: 14px 20px;
            font-weight: 600;
            font-size: 15px;
        }

        /* ── Form ─────────────────────────────────────────────── */
        .form-label { color: var(--pharmacy-heading, #1a1d23); font-weight: 600; font-size: .87rem; }
        .form-control {
            background: var(--pharmacy-card-bg, #fff);
            color: var(--pharmacy-text, #444);
            border-color: var(--pharmacy-border, #dee2e6);
            transition: border-color .2s, box-shadow .2s;
        }
        .form-control:focus { border-color: var(--pharmacy-primary, #0d6efd); }
        .btn { transition: all .2s ease; }
        .btn-gradient {
            background: linear-gradient(135deg, var(--pharmacy-accent, #667eea), var(--pharmacy-primary, #0d6efd));
            color: #fff; border: none; border-radius: 10px; padding: 9px 24px;
        }
        .btn-gradient:hover { filter: brightness(.92); color: #fff; }

        /* ── Strength bar ─────────────────────────────────────── */
        .strength-track {
            height: 5px;
            background: var(--pharmacy-border, #e5e7eb);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 6px;
        }
        #strengthBar { height: 100%; width: 0; transition: width .3s, background .3s; border-radius: 4px; }
    </style>
</head>
<body class="bg-light<?php echo $_darkClass ?? ''; ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid" style="max-width:900px; padding-top:80px;">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-0" style="color:var(--pharmacy-heading)">
                <i class="fas fa-key text-primary me-2"></i>Change Password
            </h4>
            <small style="color:var(--pharmacy-text);opacity:.65">
                Dashboard &rsaquo; Settings &rsaquo; Change Password
            </small>
        </div>
        <a href="profile.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Back to Profile
        </a>
    </div>

    <!-- Settings Sub-nav -->
    <nav class="settings-subnav">
        <a href="company.php"><i class="fas fa-hospital"></i>Pharmacy Info</a>
        <a href="theme_editor_v2.php"><i class="fas fa-palette"></i>Theme Editor</a>
        <a href="preferences.php"><i class="fas fa-sliders-h"></i>Preferences</a>
        <a href="profile.php"><i class="fas fa-user-circle"></i>My Profile</a>
        <a href="change_password.php" class="active"><i class="fas fa-key"></i>Change Password</a>
        <a href="backup.php"><i class="fas fa-database"></i>Backup</a>
    </nav>

    <!-- Change Password Card -->
    <div class="card-box">
        <div class="section-header">
            <i class="fas fa-lock me-2"></i>Change Your Password
        </div>
        <div class="p-4">

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                </div>
                <script>setTimeout(() => { window.location.href = '../auth/login.php'; }, 2000);</script>
            <?php endif; ?>

            <?php if (!$success): ?>
            <div id="pwAlertArea" class="mb-3"></div>
            <form method="POST" action=""
                  data-pharm-form
                  data-success-msg="Password changed! You will be logged out."
                  data-redirect="../auth/login.php"
                  data-redirect-delay="2000"
                  data-loading-text="Changing Password…"
                  data-alert-target="#pwAlertArea"
                  data-confirm-once
                  novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <!-- Current Password -->
                <div class="mb-3">
                    <label class="form-label">Current Password</label>
                    <div class="input-group">
                        <input type="password" name="current_password" id="currentPass"
                               class="form-control" required autocomplete="current-password"
                               placeholder="Enter current password">
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePass('currentPass','eye1')">
                            <i class="fas fa-eye" id="eye1"></i>
                        </button>
                    </div>
                </div>

                <!-- New Password -->
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <div class="input-group">
                        <input type="password" name="new_password" id="newPass"
                               class="form-control" required minlength="8" autocomplete="new-password"
                               placeholder="Min 8 chars, letters + numbers">
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePass('newPass','eye2')">
                            <i class="fas fa-eye" id="eye2"></i>
                        </button>
                    </div>
                    <small style="color:var(--pharmacy-text);opacity:.6">
                        Minimum 8 characters, include letters &amp; numbers
                    </small>
                    <div class="strength-track">
                        <div id="strengthBar"></div>
                    </div>
                    <small id="strengthLabel" style="font-size:11px;opacity:.7"></small>
                </div>

                <!-- Confirm New Password -->
                <div class="mb-4">
                    <label class="form-label">Confirm New Password</label>
                    <div class="input-group">
                        <input type="password" name="confirm_password" id="confirmPass"
                               class="form-control" required autocomplete="new-password"
                               placeholder="Repeat new password">
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePass('confirmPass','eye3')">
                            <i class="fas fa-eye" id="eye3"></i>
                        </button>
                    </div>
                    <div id="matchStatus" class="small mt-1"></div>
                </div>

                <hr style="border-color:var(--pharmacy-border)">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                    <a href="profile.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-1"></i>Cancel
                    </a>
                    <button type="submit" data-submit-btn class="btn btn-gradient" id="btnSubmit" data-loading="Changing...">
                        <i class="fas fa-save me-2"></i>Change Password
                    </button>
                </div>
            </form>
            <?php endif; ?>

        </div>
    </div>

</div><!-- /container -->
</div><!-- /main-content -->


<script>
// ── Toggle password visibility ──────────────────────────────────────────────
function togglePass(fieldId, iconId) {
    const f = document.getElementById(fieldId);
    const i = document.getElementById(iconId);
    if (f.type === 'password') { f.type = 'text';     i.className = 'fas fa-eye-slash'; }
    else                       { f.type = 'password'; i.className = 'fas fa-eye'; }
}

// ── Password strength meter ─────────────────────────────────────────────────
const newPass     = document.getElementById('newPass');
const confirmPass = document.getElementById('confirmPass');
const strengthBar = document.getElementById('strengthBar');
const strengthLbl = document.getElementById('strengthLabel');
const matchStatus = document.getElementById('matchStatus');

function updateStrength() {
    if (!newPass) return;
    const val = newPass.value;
    let score = 0;
    if (val.length >= 8)           score++;
    if (/[A-Z]/.test(val))         score++;
    if (/[0-9]/.test(val))         score++;
    if (/[^A-Za-z0-9]/.test(val))  score++;
    const colors = ['#ef4444','#f97316','#eab308','#22c55e'];
    const labels = ['Weak','Fair','Good','Strong'];
    strengthBar.style.width      = ((score / 4) * 100) + '%';
    strengthBar.style.background = colors[score - 1] || 'var(--pharmacy-border,#e5e7eb)';
    strengthLbl.textContent      = score > 0 ? labels[score - 1] : '';
    checkMatch();
}

function checkMatch() {
    if (!confirmPass || !confirmPass.value) { matchStatus.innerHTML = ''; return; }
    if (newPass.value === confirmPass.value) {
        matchStatus.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Passwords match</span>';
    } else {
        matchStatus.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Passwords do not match</span>';
    }
}

if (newPass)     newPass.addEventListener('input', updateStrength);
if (confirmPass) confirmPass.addEventListener('input', checkMatch);

// ── Single-submit protection ────────────────────────────────────────────────
document.querySelectorAll('form[data-confirm-once]').forEach(form => {
    form.addEventListener('submit', function() {
        const btn = this.querySelector('[type=submit]');
        if (btn && !btn.disabled) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (btn.dataset.loading || 'Processing...');
        }
    });
});

// ── Auto-dismiss alerts ─────────────────────────────────────────────────────
document.querySelectorAll('.alert:not(.alert-permanent)').forEach(el => {
    setTimeout(() => { try { bootstrap.Alert.getOrCreateInstance(el).close(); } catch(e){} }, 5000);
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
