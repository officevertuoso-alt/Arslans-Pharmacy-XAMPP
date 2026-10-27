<?php
// === FILE: pharmacy_software/auth/login.php ===
/**
 * STEP 33 — Advanced Login Page
 * Features: User Switcher, Remember Me (30-day), Math CAPTCHA, Rate Limiting,
 *           AJAX user list, Session fixation prevention, Company branding
 * UPDATED: Forgot Password (security Q&A), CAPTCHA always fresh, first‑login flag
 * FIXED: Removed is_active column reference (Step 7 migration)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/settings_functions.php';

// ── AJAX: return user list (no session required) ──────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_users') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmt = db()->prepare(
            "SELECT id, full_name, username, role, profile_image
             FROM users
             WHERE status = 1
             ORDER BY role DESC, full_name ASC
             LIMIT 10"
        );
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $role_colors = [
            'admin'      => '#dc2626',
            'pharmacist' => '#2563eb',
            'cashier'    => '#16a34a',
            'viewer'     => '#d97706',
        ];

        $result = [];
        foreach ($users as $u) {
            $parts    = explode(' ', trim($u['full_name']));
            $initials = strtoupper(
                substr($parts[0] ?? 'U', 0, 1) .
                substr($parts[1] ?? ($parts[0] ?? 'U'), 0, 1)
            );
            $colors = ['#6366f1','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#14b8a6'];
            $color  = $colors[crc32($u['username']) % count($colors)];

            // Resolve avatar URL from profile_image
            $avatarUrl = null;
            $profileImg = $u['profile_image'] ?? null;
            if ($profileImg) {
                $path = dirname(__DIR__) . '/uploads/profiles/' . basename($profileImg);
                if (file_exists($path)) {
                    $avatarUrl = BASE_URL . 'uploads/profiles/' . basename($profileImg) . '?v=' . filemtime($path);
                }
            }

            $result[] = [
                'id'           => (int)$u['id'],
                'full_name'    => $u['full_name'],
                'username'     => $u['username'],
                'role'         => $u['role'],
                'initials'     => $initials,
                'avatar_color' => $color,
                'role_color'   => $role_colors[$u['role']] ?? '#6b7280',
                'avatar_url'   => $avatarUrl,
                'is_active'    => true, // status=1 ensures active
            ];
        }
        echo json_encode(['success' => true, 'users' => $result]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'users' => []]);
    }
    exit;
}

// ── loginUser (FIXED: removed is_active column) ──
if (!function_exists('loginUser')) {
    function loginUser($username, $password, $remember = false) {
        try {
            $pdo = db()->getConnection();
            $stmt = $pdo->prepare(
                "SELECT id, username, password, full_name, role, status,
                        profile_image
                 FROM users
                 WHERE username = :u
                 LIMIT 1"
            );
            $stmt->execute([':u' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) return false;

            // Check status only (is_active column removed)
            $active = isset($user['status']) ? (int)$user['status'] : 1;
            if (!$active) return false;

            $stored = $user['password'];
            $valid  = false;

            if (strlen($stored) === 60 && (substr($stored, 0, 3) === '$2y' || substr($stored, 0, 3) === '$2b')) {
                $valid = password_verify($password, $stored);
            }
            if (!$valid && strlen($stored) === 32 && ctype_xdigit($stored)) {
                $valid = (md5($password) === $stored);
            }
            if (!$valid && $stored === $password) {
                $valid = true;
            }
            if (!$valid) return false;

            if (strlen($stored) !== 60 || (substr($stored,0,3) !== '$2y' && substr($stored,0,3) !== '$2b')) {
                $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $upd = $pdo->prepare("UPDATE users SET password = :p WHERE id = :id");
                $upd->execute([':p' => $newHash, ':id' => (int)$user['id']]);
            }

            $_SESSION['user_id']       = (int)$user['id'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['user_name']     = $user['full_name'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['logged_in']     = true;
            $_SESSION['login_time']    = time();

            // Store profile image in session for sidebar display
            $_SESSION['user_profile_image'] = $user['profile_image'] ?? '';

            // ── STEP 3: Single-device session lock ──
            kickOutOtherSessions((int)$user['id'], session_id());
            registerDeviceSession((int)$user['id'], session_id());

            if ($remember) {
                $token  = bin2hex(random_bytes(32));
                $expiry = time() + (30 * 24 * 3600);
                try {
                    $pdo->prepare("UPDATE users SET remember_token = :t WHERE id = :id")
                        ->execute([':t' => hash('sha256', $token), ':id' => (int)$user['id']]);
                    setcookie('remember_token', $token, $expiry, '/', '', false, true);
                    setcookie('remember_user',  (string)$user['id'], $expiry, '/', '', false, true);
                } catch (\Exception $e) { /* column may not exist */ }
            }

            return true;
        } catch (\Exception $e) {
            error_log('[loginUser] ' . $e->getMessage());
            return false;
        }
    }
}

// ── Redirect if already logged in ────────────────────────────────
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'dashboard.php'); exit();
}
checkRememberToken();
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'dashboard.php'); exit();
}

// ── Company settings ───────────────────────────────────────────
$settings     = function_exists('getCompanySettings') ? getCompanySettings() : [];
$company_name = $settings['company_name'] ?? 'Pharmacy Management System';
$company_logo = $settings['logo_path']    ?? '';
$company_tag  = $settings['tagline']      ?? 'Your trusted pharmacy partner';

// ── Rate limiting ─────────────────────────────────────────────────
$rate_limit        = checkLoginRateLimit();
$remaining_lockout = 0;
$show_captcha      = false;
$fail_count        = $rate_limit['attempts'] ?? 0;

if ($rate_limit['locked']) {
    $remaining_lockout = max(1, ceil(($rate_limit['lockout_until'] - time()) / 60));
}
if ($fail_count >= 3 && !$rate_limit['locked']) {
    $show_captcha = true;
}

// ── FIX: CAPTCHA only regenerate on GET (not POST) ─────────────
if ($show_captcha && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Only generate new question on fresh page load, preserve during POST
    $a = rand(1, 9); $b = rand(1, 9);
    $_SESSION['captcha_q'] = "$a + $b";
    $_SESSION['captcha_a'] = $a + $b;
} elseif ($show_captcha && empty($_SESSION['captcha_q'])) {
    // Fallback: generate if session empty
    $a = rand(1, 9); $b = rand(1, 9);
    $_SESSION['captcha_q'] = "$a + $b";
    $_SESSION['captcha_a'] = $a + $b;
}

$error   = '';
$success = '';

// ISS-002 FIX: Handle logout success message via URL parameter (session is destroyed on logout)
if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $success = 'You have been logged out successfully.';
}

// ── POST: process login ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rate_limit['locked']) {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    if ($show_captcha) {
        $captcha_input = (int)($_POST['captcha'] ?? -1);
        if ($captcha_input !== (int)($_SESSION['captcha_a'] ?? -99)) {
            $error = 'Incorrect math answer. Please try again.';
            // Regenerate new question for next try
            $a = rand(1,9); $b = rand(1,9);
            $_SESSION['captcha_q'] = "$a + $b";
            $_SESSION['captcha_a'] = $a + $b;
            recordFailedLoginAttempt($username);
            goto render;
        }
    }

    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
        recordFailedLoginAttempt($username);
    } else {
        if (loginUser($username, $password, $remember)) {
            // DO NOT call session_regenerate_id() here — loginUser() already did it
            // DO NOT call registerDeviceSession() here — loginUser() already did it
            // Both calls above would create a mismatched session ID causing session_conflict loop

            $_SESSION['user_agent'] = md5($_SERVER['HTTP_USER_AGENT'] ?? '');
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';

            // Clear the _session_just_registered flag so it doesn't persist across requests
            // (it was set by registerDeviceSession inside loginUser — job done)
            unset($_SESSION['_session_just_registered']);

            clearFailedLoginAttempts($username);
            unset($_SESSION['captcha_q'], $_SESSION['captcha_a']);
            // Clear the conflict/timeout flag so login page does not show stale alerts
            unset($_SESSION['session_conflict'], $_SESSION['session_timeout']);

            // ── FIRST LOGIN DETECTION (Part 1: show "update admin details" modal) ──
            $checkFirst = getOne("SELECT is_first_login FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
            if ($checkFirst && (int)$checkFirst['is_first_login'] === 1) {
                $_SESSION['security_setup_required']  = true;
                // Part 1: also flag for "update admin details?" modal on dashboard
                $_SESSION['show_admin_update_modal'] = true;
            }

            $redirectUrl = function_exists('getRoleRedirect')
                ? getRoleRedirect()
                : BASE_URL . 'dashboard.php';
            header('Location: ' . $redirectUrl);
            exit();
        } else {
            try {
                $stmt = db()->prepare("SELECT status FROM users WHERE username = :u LIMIT 1");
                $stmt->execute([':u' => $username]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u && !($u['status'] ?? 1)) {
                    $error = 'Account disabled — Contact administrator.';
                } else {
                    $fail_count++;
                    $error = 'Incorrect username or password.';
                    if ($fail_count >= 3) {
                        $show_captcha = true;
                        // Regenerate captcha immediately
                        $a = rand(1,9); $b = rand(1,9);
                        $_SESSION['captcha_q'] = "$a + $b";
                        $_SESSION['captcha_a'] = $a + $b;
                    }
                }
            } catch (Exception $e) {
                $error = 'Incorrect username or password.';
            }
            recordFailedLoginAttempt($username);
        }
    }
}

render:
$captcha_q = $_SESSION['captcha_q'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* Your existing CSS (unchanged) - keep as is */
*, *::before, *::after { box-sizing: border-box; }
:root {
    --left-bg: #0f1729;
    --accent:  #3b82f6;
    --accent2: #6366f1;
    --surface: #ffffff;
    --border:  #e5e7eb;
    --text:    #111827;
    --muted:   #6b7280;
    --danger:  #ef4444;
    --success: #10b981;
    --radius:  14px;
}
body {
    margin: 0; min-height: 100vh; display: flex;
    font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
    background: #f3f4f6;
}
.left-panel { width: 40%; min-height: 100vh; background: var(--left-bg); display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 48px 40px; position: relative; overflow: hidden; }
.left-panel::before { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse at 30% 20%, rgba(99,102,241,.25) 0%, transparent 60%), radial-gradient(ellipse at 80% 80%, rgba(59,130,246,.2) 0%, transparent 55%); }
.left-panel .blob { position: absolute; border-radius: 50%; filter: blur(60px); opacity: .18; }
.blob-1 { width:300px; height:300px; background:#6366f1; top:-80px; left:-80px; }
.blob-2 { width:250px; height:250px; background:#3b82f6; bottom:-60px; right:-60px; }
.left-content { position: relative; z-index: 1; text-align: center; }
.pharmacy-logo { width: 80px; height: 80px; border-radius: 20px; background: rgba(255,255,255,.1); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; border: 1.5px solid rgba(255,255,255,.15); }
.pharmacy-logo img { width:64px; height:64px; object-fit:contain; border-radius:12px; }
.pharmacy-logo i { font-size:36px; color:#93c5fd; }
.left-content h1 { color: #fff; font-size: 26px; font-weight: 800; margin: 0 0 8px; line-height: 1.2; letter-spacing: -.3px; }
.left-content .tagline { color: #94a3b8; font-size: 14px; margin-bottom: 40px; }
.feature-list { list-style: none; padding: 0; margin: 0 0 40px; text-align: left; }
.feature-list li { color: #cbd5e1; font-size: 13px; display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.feature-list li i { color: var(--accent); width: 16px; }
.powered-badge { background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.12); border-radius: 20px; padding: 6px 16px; color: #94a3b8; font-size: 11px; letter-spacing: .5px; }

.right-panel { flex: 1; min-height: 100vh; overflow-y: auto; display: flex; align-items: flex-start; justify-content: center; padding: 48px 40px; }
.login-wrap { width: 100%; max-width: 520px; }
.live-clock { font-size: 13px; color: var(--muted); text-align: right; margin-bottom: 28px; letter-spacing: .3px; }
.live-clock span { font-weight: 600; color: var(--text); }
.welcome-head { margin-bottom: 24px; }
.welcome-head h2 { font-size: 26px; font-weight: 800; margin: 0 0 4px; color: var(--text); }
.welcome-head p  { color: var(--muted); font-size: 14px; margin: 0; }
.switcher-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: var(--muted); margin-bottom: 12px; }
.user-cards-scroll { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 10px; margin-bottom: 24px; }
.user-cards-scroll::-webkit-scrollbar { height: 4px; }
.user-cards-scroll::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
.user-card { flex: 0 0 100px; padding: 14px 10px; border-radius: 12px; border: 2px solid var(--border); background: #fff; text-align: center; cursor: pointer; transition: all .18s ease; user-select: none; }
.user-card:hover { border-color: #93c5fd; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59,130,246,.15); }
.user-card.selected { transform: scale(1.04); box-shadow: 0 4px 16px rgba(59,130,246,.2); }
.user-card.inactive { opacity: .5; cursor: not-allowed; }
.user-card.inactive:hover { transform: none; box-shadow: none; }
.avatar-wrap { position: relative; width: 48px; height: 48px; margin: 0 auto 8px; }
.avatar-img { width:48px; height:48px; border-radius:50%; object-fit:cover; }
.avatar-init { width:48px; height:48px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:17px; font-weight:700; color:#fff; }
.online-dot { position: absolute; bottom: 1px; right: 1px; width: 11px; height: 11px; background: #22c55e; border-radius: 50%; border: 2px solid #fff; }
.user-card-name { font-size: 11.5px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.role-badge { display: inline-block; margin-top: 4px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; padding: 2px 7px; border-radius: 10px; color: #fff; }
.rb-admin      { background: #dc2626; }
.rb-pharmacist { background: #2563eb; }
.rb-cashier    { background: #16a34a; }
.rb-viewer     { background: #d97706; }
.rb-inactive   { background: #9ca3af; }
.skel-card { flex: 0 0 100px; height: 110px; border-radius: 12px; background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%); background-size: 400% 100%; animation: shimmer 1.2s infinite; }
@keyframes shimmer { 0%{background-position:100% 50%} 100%{background-position:0% 50%} }
.form-label { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 6px; }
.input-wrap { position: relative; }
.input-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 14px; pointer-events: none; }
.form-field { width: 100%; padding: 11px 40px 11px 40px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 14px; font-family: inherit; transition: border-color .2s, box-shadow .2s; background: #fff; color: var(--text); outline: none; }
.form-field:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.form-field.error { border-color: var(--danger); }
.field-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--muted); cursor: pointer; font-size: 14px; padding: 2px; }
.field-btn:hover { color: var(--text); }
.pass-label-line { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.remember-row { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
.remember-row input[type=checkbox] { width:16px; height:16px; accent-color:var(--accent); cursor:pointer; }
.remember-row label { font-size: 13px; color: var(--text); cursor: pointer; }
.remember-note { font-size: 11px; color: var(--muted); margin-top: 2px; }
.captcha-box { background: #fefce8; border: 1.5px solid #fde047; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; }
.captcha-box label { font-size: 13px; font-weight: 600; white-space: nowrap; }
.captcha-box input { width: 70px; padding: 7px 10px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 14px; text-align: center; }
.lockout-box { background: #fff7ed; border: 1.5px solid #fdba74; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; }
.lockout-timer { font-size: 28px; font-weight: 800; color: #ea580c; text-align: center; margin: 8px 0 4px; }
.btn-login { width: 100%; padding: 13px; border-radius: 10px; border: none; background: linear-gradient(135deg, var(--accent), var(--accent2)); color: #fff; font-size: 15px; font-weight: 700; font-family: inherit; cursor: pointer; transition: all .2s; letter-spacing: .3px; }
.btn-login:hover:not(:disabled) { transform: translateY(-1px); filter: brightness(1.08); box-shadow: 0 4px 12px rgba(59,130,246,.4); }
.btn-login:disabled { opacity: .7; cursor: not-allowed; }
.alert-box { border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; font-size: 13px; display: flex; align-items: flex-start; gap: 10px; }
.alert-danger  { background: #fef2f2; border: 1.5px solid #fca5a5; color: #991b1b; }
.alert-success { background: #f0fdf4; border: 1.5px solid #86efac; color: #166534; }
.alert-box i   { margin-top: 1px; flex-shrink: 0; }
@keyframes shake { 0%,100%{transform:translateX(0)} 20%{transform:translateX(-8px)} 40%{transform:translateX(8px)} 60%{transform:translateX(-5px)} 80%{transform:translateX(5px)} }
.shake { animation: shake .4s ease; }
@media (max-width: 768px) {
    .left-panel { display: none; }
    .right-panel { padding: 24px 20px; }
    .mobile-header { display: flex !important; align-items: center; gap: 14px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1.5px solid var(--border); }
    .mobile-header-logo { width:44px; height:44px; border-radius:10px; background:#0f1729; display:flex; align-items:center; justify-content:center; }
    .mobile-header-logo i { color:#93c5fd; font-size:22px; }
    .mobile-header h3 { font-size:17px; font-weight:800; margin:0 0 2px; }
    .mobile-header p  { font-size:12px; color:var(--muted); margin:0; }
    .user-cards-scroll { gap: 8px; }
    .user-card { flex: 0 0 88px; }
}
.mobile-header { display: none; }
</style>
</head>
<body>

<!-- LEFT PANEL (unchanged) -->
<div class="left-panel">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="left-content">
        <div class="pharmacy-logo">
            <?php if ($company_logo && file_exists(dirname(__DIR__) . '/uploads/' . $company_logo)): ?>
                <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($company_logo) ?>" alt="Logo">
            <?php else: ?>
                <i class="fas fa-pills"></i>
            <?php endif; ?>
        </div>
        <h1><?= htmlspecialchars($company_name) ?></h1>
        <p class="tagline"><?= htmlspecialchars($company_tag) ?></p>
        <ul class="feature-list">
            <li><i class="fas fa-check-circle"></i> Complete Inventory Management</li>
            <li><i class="fas fa-check-circle"></i> Sales & Purchase Tracking</li>
            <li><i class="fas fa-check-circle"></i> Credit & Cashbook Sync</li>
            <li><i class="fas fa-check-circle"></i> Real-time Stock Monitoring</li>
            <li><i class="fas fa-check-circle"></i> Multi-user Role Access</li>
        </ul>
        <div class="powered-badge">⚡ Powered by Dr. Arslan Yasin Sukhera</div>
    </div>
</div>

<!-- RIGHT PANEL -->
<div class="right-panel">
    <div class="login-wrap">
        <div class="mobile-header">
            <div class="mobile-header-logo">
                <?php if ($company_logo && file_exists(dirname(__DIR__) . '/uploads/' . $company_logo)): ?>
                    <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($company_logo) ?>" style="width:36px;border-radius:6px;" alt="">
                <?php else: ?>
                    <i class="fas fa-pills"></i>
                <?php endif; ?>
            </div>
            <div>
                <h3><?= htmlspecialchars($company_name) ?></h3>
                <p><?= htmlspecialchars($company_tag) ?></p>
            </div>
        </div>

        <div class="live-clock">
            <i class="far fa-clock me-1"></i>
            <span id="liveClock">--:--:--</span> &nbsp;|&nbsp;
            <span id="liveDate">---</span>
        </div>

        <div class="welcome-head">
            <h2>Welcome Back 👋</h2>
            <p>Select your account or enter credentials below.</p>
        </div>

        <div class="switcher-label">Select User</div>
        <div class="user-cards-scroll" id="userCardsRow">
            <div class="skel-card"></div>
            <div class="skel-card"></div>
            <div class="skel-card"></div>
        </div>

        <?php if ($remaining_lockout > 0): ?>
        <div class="lockout-box">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="fas fa-lock text-warning"></i>
                <strong>Account Temporarily Locked</strong>
            </div>
            <div class="lockout-timer" id="lockoutTimer"><?= $remaining_lockout ?>:00</div>
            <div style="font-size:12px;color:#92400e;text-align:center;">
                Too many failed attempts. Wait for the timer, then try again.
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert-box alert-danger" id="errorAlert">
            <i class="fas fa-exclamation-circle"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert-box alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?= htmlspecialchars($success) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm" autocomplete="on">
            <div class="mb-4">
                <label class="form-label">Username</label>
                <div class="input-wrap">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" name="username" id="usernameField"
                           class="form-field" autocomplete="username"
                           placeholder="Enter username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           <?= $remaining_lockout > 0 ? 'disabled' : '' ?>
                           oninput="onUsernameType()">
                    <button type="button" class="field-btn" id="clearUserBtn"
                            onclick="clearUserSelection()" style="display:none" title="Clear">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <div class="mb-4">
                <div class="pass-label-line">
                    <label class="form-label mb-0" id="passLabel">Password</label>
                </div>
                <div class="input-wrap">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" name="password" id="passwordField"
                           class="form-field" autocomplete="current-password"
                           placeholder="Enter password"
                           <?= $remaining_lockout > 0 ? 'disabled' : '' ?>
                           onkeydown="if(event.key==='Enter'){document.getElementById('loginForm').requestSubmit();}">
                    <button type="button" class="field-btn" onclick="togglePassword()" title="Show/hide">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <?php if ($show_captcha && $captcha_q): ?>
            <div class="captcha-box" id="captchaWrap">
                <i class="fas fa-shield-alt" style="color:#ca8a04;font-size:18px;"></i>
                <label for="captchaInput">What is <?= htmlspecialchars($captcha_q) ?> =</label>
                <input type="number" name="captcha" id="captchaInput"
                       placeholder="?" min="0" max="18" required
                       <?= $remaining_lockout > 0 ? 'disabled' : '' ?>>
            </div>
            <?php endif; ?>

            <div class="mb-4">
                <div class="remember-row">
                    <input type="checkbox" name="remember" id="rememberMe"
                           <?= $remaining_lockout > 0 ? 'disabled' : '' ?>>
                    <label for="rememberMe">Keep me logged in for 30 days</label>
                </div>
                <div class="remember-note">
                    <i class="fas fa-info-circle me-1"></i>
                    Avoid on shared or public computers.
                </div>
            </div>

            <button type="submit" class="btn-login" id="submitBtn"
                    <?= $remaining_lockout > 0 ? 'disabled' : '' ?>>
                <i class="fas fa-sign-in-alt me-2"></i> Login
            </button>
        </form>

        <!-- Forgot Password Link -->
        <div class="text-center mt-3">
            <a href="#" data-bs-toggle="modal" data-bs-target="#forgotModal" class="text-decoration-none small">
                <i class="fas fa-question-circle me-1"></i> Forgot Password?
            </a>
        </div>

        <div style="text-align:center;margin-top:28px;font-size:11.5px;color:var(--muted);">
            <i class="fas fa-shield-alt me-1" style="color:var(--accent)"></i>
            Secure login · Session encrypted · <?= htmlspecialchars($company_name) ?>
        </div>
    </div>
</div>

<span id="lockoutSeconds" style="display:none"><?= $remaining_lockout * 60 ?></span>

<!-- FORGOT PASSWORD MODAL (Security Question based) -->
<div class="modal fade" id="forgotModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-key me-2"></i>Recover Password</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="forgotStep1">
                    <p class="text-muted">Verify your identity using your security question.</p>
                    <form id="forgotFormStep1">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Security Question</label>
                            <select name="question" class="form-select" required>
                                <option value="">Select your question</option>
                                <option value="nickname">Your Nickname</option>
                                <option value="pet">Your Favourite Pet</option>
                                <option value="hometown">Your Hometown</option>
                                <option value="dob">Your Date of Birth</option>
                                <option value="cnic">Your ID Card Number</option>
                                <option value="phone">Your Phone Number</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Your Answer</label>
                            <input type="text" name="answer" class="form-control" required>
                            <div class="form-text">Answer is case‑sensitive.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Verify</button>
                    </form>
                </div>
                <div id="forgotStep2" style="display:none;">
                    <p class="text-muted">Set a new password for <span id="resetUsername"></span>.</p>
                    <form id="forgotFormStep2">
                        <input type="hidden" name="token" id="resetToken">
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" id="resetNewPass" class="form-control" required>
                            <div class="d-flex gap-1 mt-2">
                                <div class="strength-bar flex-fill bg-secondary" id="resetS1"></div>
                                <div class="strength-bar flex-fill bg-secondary" id="resetS2"></div>
                                <div class="strength-bar flex-fill bg-secondary" id="resetS3"></div>
                                <div class="strength-bar flex-fill bg-secondary" id="resetS4"></div>
                            </div>
                            <small id="resetStrengthLabel" class="text-muted"></small>
                            <div class="mt-1 small">
                                <span id="resetCrLength" style="color:#6c757d">Min 8 characters</span>
                                &nbsp;
                                <span id="resetCrNumber" style="color:#6c757d">1 number</span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" id="resetConfirmPass" class="form-control" required>
                            <div id="resetMatchStatus" class="small mt-1"></div>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Reset Password</button>
                    </form>
                </div>
                <div id="forgotResult" class="mt-3"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
// ── Live Clock ─────────────────────────────────────────────
(function clock() {
    const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function tick() {
        const now = new Date();
        const h   = String(now.getHours()).padStart(2,'0');
        const m   = String(now.getMinutes()).padStart(2,'0');
        const s   = String(now.getSeconds()).padStart(2,'0');
        document.getElementById('liveClock').textContent = `${h}:${m}:${s}`;
        document.getElementById('liveDate').textContent  =
            `${days[now.getDay()]}, ${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
    }
    tick(); setInterval(tick, 1000);
})();

// ── Lockout Countdown ──────────────────────────────────────
(function lockoutCountdown() {
    const secEl = document.getElementById('lockoutSeconds');
    const timerEl = document.getElementById('lockoutTimer');
    if (!secEl || !timerEl) return;
    let sec = parseInt(secEl.textContent, 10) || 0;
    if (sec <= 0) return;
    const iv = setInterval(() => {
        sec--;
        if (sec <= 0) { clearInterval(iv); location.reload(); return; }
        const m = String(Math.floor(sec / 60)).padStart(2,'0');
        const s = String(sec % 60).padStart(2,'0');
        timerEl.textContent = `${m}:${s}`;
    }, 1000);
})();

// ── User Switcher ──────────────────────────────────────────
let selectedUser = null;
const roleColors = { admin:'#dc2626', pharmacist:'#2563eb', cashier:'#16a34a', viewer:'#d97706' };
const roleClass  = { admin:'rb-admin', pharmacist:'rb-pharmacist', cashier:'rb-cashier', viewer:'rb-viewer' };

function renderUserCards(users) {
    const row = document.getElementById('userCardsRow');
    if (!users || !users.length) {
        row.innerHTML = '<div class="text-muted small py-2">No active users found.</div>';
        return;
    }
    row.innerHTML = users.map(u => `
    <div class="user-card ${u.is_active === false ? 'inactive' : ''}" 
         id="card_${u.id}"
         data-username="${escHtml(u.username)}"
         data-name="${escHtml(u.full_name)}"
         data-role="${escHtml(u.role)}"
         data-color="${escHtml(u.role_color)}"
         onclick="selectUser(${u.id}, this)">
        <div class="avatar-wrap">
            ${u.avatar_url
                ? `<img src="${escHtml(u.avatar_url)}" class="avatar-img" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                   <div class="avatar-init" style="background:${u.avatar_color};display:none">${escHtml(u.initials)}</div>`
                : `<div class="avatar-init" style="background:${u.avatar_color}">${escHtml(u.initials)}</div>`
            }
        </div>
        <div class="user-card-name">${escHtml(u.full_name.split(' ')[0])}</div>
        <span class="role-badge ${roleClass[u.role] || ''}">${escHtml(u.role)}</span>
    </div>`).join('');
}

function selectUser(id, card) {
    if (card.classList.contains('inactive')) return;
    document.querySelectorAll('.user-card').forEach(c => {
        c.classList.remove('selected');
        c.style.borderColor = '';
    });
    card.classList.add('selected');
    card.style.borderColor = card.dataset.color;
    selectedUser = { id, username: card.dataset.username, name: card.dataset.name };

    document.getElementById('usernameField').value = card.dataset.username;
    document.getElementById('clearUserBtn').style.display = 'block';
    document.getElementById('passLabel').textContent = `Password for ${card.dataset.name.split(' ')[0]}:`;
    document.getElementById('passwordField').focus();
}

function clearUserSelection() {
    selectedUser = null;
    document.querySelectorAll('.user-card').forEach(c => {
        c.classList.remove('selected');
        c.style.borderColor = '';
    });
    document.getElementById('usernameField').value = '';
    document.getElementById('clearUserBtn').style.display = 'none';
    document.getElementById('passLabel').textContent = 'Password';
    document.getElementById('usernameField').focus();
}

function onUsernameType() {
    const val = document.getElementById('usernameField').value;
    if (selectedUser && val !== selectedUser.username) {
        document.querySelectorAll('.user-card').forEach(c => {
            c.classList.remove('selected');
            c.style.borderColor = '';
        });
        selectedUser = null;
        document.getElementById('passLabel').textContent = 'Password';
    }
    document.getElementById('clearUserBtn').style.display = val ? 'block' : 'none';
}

fetch('login.php?ajax=get_users')
    .then(r => r.json())
    .then(d => renderUserCards(d.users || []))
    .catch(() => {
        document.getElementById('userCardsRow').innerHTML =
            '<div class="text-muted small py-2"><i class="fas fa-wifi-slash me-1"></i>Could not load users.</div>';
    });

function togglePassword() {
    const f = document.getElementById('passwordField');
    const i = document.getElementById('eyeIcon');
    if (f.type === 'password') {
        f.type = 'text';
        i.className = 'fas fa-eye-slash';
    } else {
        f.type = 'password';
        i.className = 'fas fa-eye';
    }
}

const form = document.getElementById('loginForm');
const submitBtn = document.getElementById('submitBtn');
form.addEventListener('submit', function() {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Logging in...';
});

<?php if ($error): ?>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('errorAlert');
    if (el) { el.classList.add('shake'); setTimeout(() => el.classList.remove('shake'), 500); }
    const pf = document.getElementById('passwordField');
    if (pf) pf.classList.add('error');
});
<?php endif; ?>

function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── FORGOT PASSWORD (two-step via forgot_password.php) ──
let resetToken = null;

$('#forgotFormStep1').on('submit', function(e) {
    e.preventDefault();
    $('#forgotResult').html('');
    $.post('forgot_password.php', $(this).serialize(), function(res) {
        if (res.success) {
            resetToken = res.token;
            $('#resetToken').val(resetToken);
            $('#resetUsername').text($('input[name="username"]', '#forgotFormStep1').val());
            $('#forgotStep1').hide();
            $('#forgotStep2').show();
            $('#forgotResult').html('<div class="alert alert-success">Verified! Set new password.</div>');
        } else {
            $('#forgotResult').html('<div class="alert alert-danger">' + res.message + '</div>');
        }
    }, 'json').fail(() => {
        $('#forgotResult').html('<div class="alert alert-danger">Server error. Try again.</div>');
    });
});

$('#forgotFormStep2').on('submit', function(e) {
    e.preventDefault();
    const newPass = $('#resetNewPass').val();
    const confirm = $('#resetConfirmPass').val();
    if (newPass !== confirm) {
        $('#resetMatchStatus').html('<span class="text-danger">Passwords do not match</span>');
        return;
    }
    const data = $(this).serialize() + '&new_password=' + encodeURIComponent(newPass);
    $.post('forgot_password.php', data, function(res) {
        if (res.success) {
            $('#forgotResult').html('<div class="alert alert-success">' + res.message + ' Redirecting...</div>');
            setTimeout(() => { window.location.href = 'login.php'; }, 2000);
        } else {
            $('#forgotResult').html('<div class="alert alert-danger">' + res.message + '</div>');
        }
    }, 'json').fail(() => {
        $('#forgotResult').html('<div class="alert alert-danger">Server error. Try again.</div>');
    });
});

// Password strength meter for reset modal
function resetStrength() {
    const pw = $('#resetNewPass').val();
    const score = pw.length ? calcStrengthReset(pw) : 0;
    const bars = ['#resetS1','#resetS2','#resetS3','#resetS4'];
    const colors = ['bg-danger','bg-warning','bg-info','bg-success'];
    const labels = ['Very Weak','Weak','Fair','Strong'];
    bars.forEach((id, i) => {
        const bar = $(id);
        bar.removeClass('bg-danger bg-warning bg-info bg-success bg-secondary');
        bar.addClass(i < score ? colors[score-1] : 'bg-secondary');
    });
    if (pw.length) {
        $('#resetStrengthLabel').text('Strength: ' + labels[score-1]);
    } else {
        $('#resetStrengthLabel').text('');
    }
    $('#resetCrLength').css('color', pw.length >= 8 ? '#198754' : '#dc3545');
    $('#resetCrNumber').css('color', /[0-9]/.test(pw) ? '#198754' : '#dc3545');
    if ($('#resetConfirmPass').val()) resetMatch();
}

function resetMatch() {
    const pw = $('#resetNewPass').val();
    const cpw = $('#resetConfirmPass').val();
    if (!cpw) { $('#resetMatchStatus').html(''); return; }
    if (pw === cpw) {
        $('#resetMatchStatus').html('<span class="text-success"><i class="fas fa-check-circle me-1"></i>Match</span>');
    } else {
        $('#resetMatchStatus').html('<span class="text-danger"><i class="fas fa-times-circle me-1"></i>No match</span>');
    }
}

function calcStrengthReset(pw) {
    let score = 0;
    if (pw.length >= 8) score++;
    if (pw.length >= 12) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^a-zA-Z0-9]/.test(pw)) score++;
    return Math.min(score, 4);
}

$('#resetNewPass').on('input', resetStrength);
$('#resetConfirmPass').on('input', resetMatch);

// Reset modal when closed
$('#forgotModal').on('hidden.bs.modal', function () {
    $('#forgotStep1').show();
    $('#forgotStep2').hide();
    $('#forgotResult').html('');
    $('#forgotFormStep1')[0].reset();
    $('#forgotFormStep2')[0].reset();
    resetToken = null;
});

// ── SECURITY SETUP MODAL (First Login) ────────────────────────
<?php if (!empty($_SESSION['security_setup_required'])): ?>
document.addEventListener('DOMContentLoaded', function () {
    var setupModal = new bootstrap.Modal(document.getElementById('securitySetupModal'), {
        backdrop: 'static',   // cannot dismiss by clicking outside
        keyboard: false       // cannot dismiss with Escape key
    });
    setupModal.show();
});
<?php endif; ?>

$('#securitySetupForm').on('submit', function (e) {
    e.preventDefault();
    var btn = $('#btnSaveSetup');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving...');
    $('#setupResult').html('');

    $.post('save_security_setup.php', $(this).serialize(), function (res) {
        if (res.success) {
            $('#setupResult').html('<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i>' + res.message + '</div>');
            setTimeout(function () {
                window.location.href = res.redirect || '../dashboard.php';
            }, 1200);
        } else {
            $('#setupResult').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i>' + res.message + '</div>');
            btn.prop('disabled', false).html('<i class="fas fa-shield-alt me-1"></i>Save & Continue');
        }
    }, 'json').fail(function () {
        $('#setupResult').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i>Server error. Please try again.</div>');
        btn.prop('disabled', false).html('<i class="fas fa-shield-alt me-1"></i>Save & Continue');
    });
});
</script>

<!-- SECURITY SETUP MODAL (shown on first login, cannot be dismissed) -->
<div class="modal fade" id="securitySetupModal" tabindex="-1" aria-labelledby="securitySetupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="securitySetupModalLabel">
                    <i class="fas fa-shield-alt me-2"></i>Security Setup Required
                </h5>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted" style="font-size:13px;">
                    Please set a security question and answer. This will be used to recover your password in the future.
                </p>
                <form id="securitySetupForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken()) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:13px;">Security Question</label>
                        <select name="security_question" class="form-select form-select-sm" required>
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
                        <label class="form-label fw-semibold" style="font-size:13px;">Your Answer</label>
                        <input type="text" name="security_answer" class="form-control form-control-sm"
                               placeholder="Your answer (case-sensitive)" required minlength="2">
                        <div class="form-text">Answer is case-sensitive and will be hashed.</div>
                    </div>
                    <div id="setupResult"></div>
                    <button type="submit" class="btn btn-primary w-100" id="btnSaveSetup">
                        <i class="fas fa-shield-alt me-1"></i>Save & Continue
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

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
