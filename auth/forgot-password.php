<?php
/**
 * Forgot Password – Security Question Recovery
 * File: auth/forgot_password.php
 * 
 * Two-step process:
 * 1. User enters username, selects security question, provides answer.
 * 2. On correct verification, user sets a new password.
 * Token stored in session, expires after 10 minutes.
 */

require_once '../config/config.php';
require_once '../includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$step       = 1;          // 1 = ask question, 2 = reset password
$error      = '';
$success    = '';
$username   = '';
$token      = $_GET['token'] ?? '';

// If token is provided via URL, go to step 2 directly
if (!empty($token) && isset($_SESSION['reset_token'][$token]) && $_SESSION['reset_token'][$token]['expires'] > time()) {
    $step = 2;
    $userId = $_SESSION['reset_token'][$token]['user_id'];
    $user = getOne("SELECT username FROM users WHERE id = :id", [':id' => $userId]);
    if ($user) {
        $username = $user['username'];
    } else {
        $error = 'Invalid token. Please restart the recovery process.';
        $step = 1;
    }
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['step1'])) {
        // STEP 1: Verify security question
        $username = sanitize($_POST['username'] ?? '');
        $question = sanitize($_POST['question'] ?? '');
        $answer   = trim($_POST['answer'] ?? '');

        if (empty($username) || empty($question) || empty($answer)) {
            $error = 'All fields are required.';
        } else {
            $userId = verifySecurityAnswer($username, $question, $answer);
            if ($userId) {
                // Generate a short-lived token (10 minutes)
                $token = bin2hex(random_bytes(32));
                $_SESSION['reset_token'][$token] = [
                    'user_id' => $userId,
                    'expires' => time() + 600
                ];
                // Redirect to same page with token (step 2)
                redirect('forgot_password.php?token=' . $token);
            } else {
                $error = 'Invalid username, security question, or answer.';
            }
        }
    } elseif (isset($_POST['step2'])) {
        // STEP 2: Reset password using token
        $token   = $_POST['token'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($token) || !isset($_SESSION['reset_token'][$token]) || $_SESSION['reset_token'][$token]['expires'] < time()) {
            $error = 'Invalid or expired token. Please restart the recovery process.';
            $step = 1;
        } else {
            if (strlen($newPass) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif (!preg_match('/[0-9]/', $newPass)) {
                $error = 'Password must contain at least one number.';
            } elseif ($newPass !== $confirm) {
                $error = 'Passwords do not match.';
            } else {
                $userId = $_SESSION['reset_token'][$token]['user_id'];
                $hashed = password_hash($newPass, PASSWORD_BCRYPT);
                $db = db();
                $db->query("UPDATE users SET password = :p WHERE id = :id");
                $db->bind(':p', $hashed);
                $db->bind(':id', $userId);
                if ($db->execute()) {
                    // Clear the used token
                    unset($_SESSION['reset_token'][$token]);
                    $success = 'Password has been reset successfully. You can now <a href="login.php">login</a>.';
                    $step = 3; // success state, no form
                } else {
                    $error = 'Database error. Please try again.';
                }
            }
        }
    }
}

// Dropdown options for security questions (same as in add/edit user)
$questions = [
    'nickname'  => 'Your Nickname',
    'pet'       => 'Your Favourite Pet',
    'hometown'  => 'Your Hometown',
    'dob'       => 'Your Date of Birth',
    'cnic'      => 'Your ID Card Number',
    'phone'     => 'Your Phone Number'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .strength-bar {
            height: 5px;
            border-radius: 3px;
            transition: all .3s;
        }
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
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <?php if ($step == 1): ?>
                        <p class="text-muted">Enter your username and answer the security question you set up during registration.</p>
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" required autofocus
                                       value="<?php echo htmlspecialchars($username); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Security Question</label>
                                <select name="question" class="form-select" required>
                                    <option value="">Select your security question</option>
                                    <?php foreach ($questions as $val => $label): ?>
                                        <option value="<?php echo $val; ?>"><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Your Answer</label>
                                <input type="text" name="answer" class="form-control" required>
                                <div class="form-text text-muted">Answer is case‑sensitive.</div>
                            </div>
                            <button type="submit" name="step1" class="btn btn-primary w-100">
                                <i class="fas fa-check-circle me-2"></i> Verify & Continue
                            </button>
                        </form>
                    <?php elseif ($step == 2): ?>
                        <p class="text-muted">Set a new password for <strong><?php echo htmlspecialchars($username); ?></strong>.</p>
                        <form method="POST" action="">
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" id="newPass" class="form-control" required>
                                <div class="d-flex gap-1 mt-2">
                                    <div class="strength-bar flex-fill bg-secondary" id="s1"></div>
                                    <div class="strength-bar flex-fill bg-secondary" id="s2"></div>
                                    <div class="strength-bar flex-fill bg-secondary" id="s3"></div>
                                    <div class="strength-bar flex-fill bg-secondary" id="s4"></div>
                                </div>
                                <small id="strengthLabel" class="text-muted"></small>
                                <div class="mt-1 small">
                                    <span id="cr-length" style="color:#6c757d">Min 8 characters</span>
                                    &nbsp;
                                    <span id="cr-number" style="color:#6c757d">1 number</span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" name="confirm_password" id="confirmPass" class="form-control" required>
                                <div id="matchStatus" class="small mt-1"></div>
                            </div>
                            <button type="submit" name="step2" class="btn btn-success w-100">
                                <i class="fas fa-save me-2"></i> Reset Password
                            </button>
                        </form>
                    <?php elseif ($step == 3): ?>
                        <div class="text-center">
                            <a href="login.php" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt me-2"></i> Go to Login
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer text-center bg-light">
                    <a href="login.php" class="text-decoration-none">
                        <i class="fas fa-arrow-left me-1"></i> Back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Password strength meter (only on step 2)
<?php if ($step == 2): ?>
const bars   = ['#s1','#s2','#s3','#s4'];
const colors = ['bg-danger','bg-warning','bg-info','bg-success'];
const labels = ['Very Weak','Weak','Fair','Strong'];

function calcStrength(pw) {
    let score = 0;
    if (pw.length >= 8)              score++;
    if (pw.length >= 12)             score++;
    if (/[0-9]/.test(pw))           score++;
    if (/[^a-zA-Z0-9]/.test(pw))   score++;
    return Math.min(score, 4);
}

function updateStrength() {
    const pw = document.getElementById('newPass').value;
    const score = pw.length ? calcStrength(pw) : 0;
    bars.forEach((id, i) => {
        const bar = document.querySelector(id);
        bar.classList.remove('bg-danger','bg-warning','bg-info','bg-success','bg-secondary');
        bar.classList.add(i < score ? colors[score-1] : 'bg-secondary');
    });
    const label = document.getElementById('strengthLabel');
    if (pw.length) {
        label.textContent = 'Strength: ' + labels[score-1];
        label.style.color = (score <= 1) ? '#dc3545' : (score === 2) ? '#ffc107' : (score === 3) ? '#0dcaf0' : '#198754';
    } else {
        label.textContent = '';
    }
    const lenOk = pw.length >= 8;
    const numOk = /[0-9]/.test(pw);
    document.getElementById('cr-length').style.color = lenOk ? '#198754' : '#dc3545';
    document.getElementById('cr-number').style.color = numOk ? '#198754' : '#dc3545';
    checkMatch();
}

function checkMatch() {
    const pw = document.getElementById('newPass').value;
    const cpw = document.getElementById('confirmPass').value;
    const matchDiv = document.getElementById('matchStatus');
    if (!cpw) { matchDiv.innerHTML = ''; return; }
    if (pw === cpw) {
        matchDiv.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Passwords match</span>';
    } else {
        matchDiv.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Passwords do not match</span>';
    }
}

document.getElementById('newPass').addEventListener('input', updateStrength);
document.getElementById('confirmPass').addEventListener('input', checkMatch);
<?php endif; ?>
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>