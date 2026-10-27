<?php
/**
 * Users — Profile View / Edit
 * File: users/profile.php
 * Phase 20 — User Management
 *
 * - Any logged-in user can view/edit their OWN profile (name, phone, avatar, security Q&A, password)
 * - Admin can view/edit any user's profile
 * - Links from: users/list.php, topbar/navbar avatar
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security_helpers.php';

$pageTitle = 'User Profile';
$csrfToken = generateCSRFToken();
$errors    = [];
$success   = '';

// ── Target user ───────────────────────────────────────────────────────────────
$viewId     = (int)($_GET['id'] ?? $_SESSION['user_id']);
$isSelfView = ($viewId === (int)$_SESSION['user_id']);
$isAdminAct = isAdmin();

// Non-admin can only view own profile
if (!$isAdminAct && !$isSelfView) {
    setFlash('error', 'You can only view your own profile.');
    header('Location: ' . BASE_URL . '/dashboard.php'); exit;
}

$userRecord = getOne('SELECT * FROM users WHERE id = :id', [':id' => $viewId]);
if (!$userRecord) {
    setFlash('error', 'User not found.');
    header('Location: list.php'); exit;
}

$securityQuestions = getSecurityQuestions();

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $tab = sanitize($_POST['tab'] ?? 'basic');

        // ── Tab: Basic Info ────────────────────────────────────────────────────
        if ($tab === 'basic') {
            $fullName = sanitize($_POST['full_name'] ?? '');
            $phone    = sanitize($_POST['phone']     ?? '');
            $email    = sanitize($_POST['email']     ?? '');

            if ($fullName === '') $errors[] = 'Full name is required.';
            if ($email    === '') $errors[] = 'Email is required.';
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';

            // Email uniqueness
            if (empty($errors)) {
                $dup = getOne('SELECT id FROM users WHERE email = :e AND id != :id', [':e' => $email, ':id' => $viewId]);
                if ($dup) $errors[] = 'Email already used by another account.';
            }

            if (empty($errors)) {
                $db = db();
                $stmt = $db->prepare('UPDATE users SET full_name=:fn, phone=:ph, email=:em, updated_at=NOW() WHERE id=:id');
                $stmt->execute([':fn'=>$fullName,':ph'=>$phone,':em'=>$email,':id'=>$viewId]);
                if ($isSelfView) {
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['email']     = $email;
                }
                logActivity((int)$_SESSION['user_id'], 'UPDATE_PROFILE', "Profile basic info updated for user ID: {$viewId}");
                $userRecord['full_name'] = $fullName;
                $userRecord['phone']     = $phone;
                $userRecord['email']     = $email;
                $success = 'Profile information updated successfully.';
            }
        }

        // ── Tab: Password ──────────────────────────────────────────────────────
        if ($tab === 'password') {
            $currentPw  = $_POST['current_password']  ?? '';
            $newPw      = $_POST['new_password']       ?? '';
            $confirmPw  = $_POST['confirm_password']   ?? '';

            // Admins editing other users don't need current password
            if ($isSelfView || !$isAdminAct) {
                if ($currentPw === '') {
                    $errors[] = 'Current password is required.';
                } elseif (!password_verify($currentPw, $userRecord['password'])) {
                    $errors[] = 'Current password is incorrect.';
                }
            }
            if (strlen($newPw) < 8) $errors[] = 'New password must be at least 8 characters.';
            if (!preg_match('/[0-9]/', $newPw)) $errors[] = 'New password must contain at least one number.';
            if ($newPw !== $confirmPw) $errors[] = 'New passwords do not match.';

            if (empty($errors)) {
                $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
                $db   = db();
                $stmt = $db->prepare('UPDATE users SET password=:pw, force_password_change=0, updated_at=NOW() WHERE id=:id');
                $stmt->execute([':pw'=>$hash,':id'=>$viewId]);
                logActivity((int)$_SESSION['user_id'], 'CHANGE_PASSWORD', "Password changed for user ID: {$viewId}");
                $success = 'Password changed successfully.';
            }
        }

        // ── Tab: Security ──────────────────────────────────────────────────────
        if ($tab === 'security') {
            $question = sanitize($_POST['security_question'] ?? '');
            $answer   = trim($_POST['security_answer'] ?? '');

            if ($question === '') $errors[] = 'Please select a security question.';
            if (strlen($answer) < 2) $errors[] = 'Security answer must be at least 2 characters.';

            if (empty($errors)) {
                $hashedAnswer = password_hash($answer, PASSWORD_BCRYPT);
                $db = db();
                $stmt = $db->prepare('UPDATE users SET security_question=:q, security_answer=:a, updated_at=NOW() WHERE id=:id');
                $stmt->execute([':q'=>$question,':a'=>$hashedAnswer,':id'=>$viewId]);
                $userRecord['security_question'] = $question;
                logActivity((int)$_SESSION['user_id'], 'UPDATE_SECURITY_Q', "Security question updated for user ID: {$viewId}");
                $success = 'Security question updated successfully.';
            }
        }

        // ── Tab: Avatar upload ─────────────────────────────────────────────────
        if ($tab === 'avatar') {
            $fileUploaded = isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE;
            if ($fileUploaded) {
                $fileErr = $_FILES['profile_photo']['error'];
                if ($fileErr !== UPLOAD_ERR_OK) {
                    $errors[] = 'Upload error (code: ' . $fileErr . ').';
                } else {
                    $finfo    = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($_FILES['profile_photo']['tmp_name']);
                    $allowed  = ['image/jpeg','image/png','image/gif','image/webp'];
                    if (!in_array($mimeType, $allowed, true)) {
                        $errors[] = 'Only JPG, PNG, GIF or WEBP allowed.';
                    } elseif ($_FILES['profile_photo']['size'] > 2*1024*1024) {
                        $errors[] = 'Photo must be under 2 MB.';
                    } else {
                        $ext       = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                        $newFile   = 'user_' . $viewId . '_' . time() . '.' . $ext;
                        $uploadDir = '../uploads/avatars/';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $newFile)) {
                            // Delete old
                            if (!empty($userRecord['profile_image'])) {
                                $old = $uploadDir . basename($userRecord['profile_image']);
                                if (file_exists($old)) @unlink($old);
                            }
                            $db = db();
                            $stmt = $db->prepare('UPDATE users SET profile_image=:img, updated_at=NOW() WHERE id=:id');
                            $stmt->execute([':img'=>$newFile,':id'=>$viewId]);
                            $userRecord['profile_image'] = $newFile;
                            logActivity((int)$_SESSION['user_id'], 'UPLOAD_AVATAR', "Avatar uploaded for user ID: {$viewId}");
                            $success = 'Profile picture updated.';
                        } else {
                            $errors[] = 'Failed to save the uploaded file.';
                        }
                    }
                }
            }
            // Remove avatar
            if (isset($_POST['remove_avatar'])) {
                if (!empty($userRecord['profile_image'])) {
                    $old = '../uploads/avatars/' . basename($userRecord['profile_image']);
                    if (file_exists($old)) @unlink($old);
                }
                $db = db();
                $stmt = $db->prepare('UPDATE users SET profile_image=NULL, updated_at=NOW() WHERE id=:id');
                $stmt->execute([':id'=>$viewId]);
                $userRecord['profile_image'] = null;
                $success = 'Profile picture removed.';
            }
        }
    }
}

// Avatar resolve
$avatarColors = ['#4e73df','#1cc88a','#36b9cc','#e74a3b','#f6c23e','#858796','#6f42c1','#fd7e14'];
$colorIdx     = abs(crc32($userRecord['username'])) % count($avatarColors);
$avatarColor  = $avatarColors[$colorIdx];
$initials     = strtoupper(substr($userRecord['full_name'], 0, 1));
$avatarPath   = '';
if (!empty($userRecord['profile_image'])) {
    foreach (['../uploads/avatars/', '../uploads/profiles/'] as $dir) {
        $candidate = $dir . basename($userRecord['profile_image']);
        if (file_exists($candidate)) { $avatarPath = $candidate; break; }
    }
}

$roleBadge = match($userRecord['role']) {
    'admin'      => 'bg-danger',
    'pharmacist' => 'bg-primary',
    'cashier'    => 'bg-success',
    default      => 'bg-secondary',
};

// Recent activity
$recentActivity = getAll(
    'SELECT action, description, created_at FROM activity_logs WHERE user_id = :uid ORDER BY created_at DESC LIMIT 10',
    [':uid' => $viewId]
);
$isProtected = !empty($userRecord['is_protected']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        @keyframes fadeInPage { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
        .main-content { animation: fadeInPage .28s ease; }
        .profile-avatar { width:100px; height:100px; border-radius:50%; object-fit:cover; border:4px solid var(--pharmacy-border,#dee2e6); }
        .avatar-placeholder { width:100px; height:100px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:2.5rem; font-weight:700; color:#fff; border:4px solid rgba(255,255,255,.3); }
        .profile-card { border-radius: var(--card-border-radius,12px); border:1px solid var(--pharmacy-border,#dee2e6); background:var(--pharmacy-card-bg,#fff); box-shadow:0 2px 12px rgba(0,0,0,.06); overflow:hidden; margin-bottom:1.25rem; }
        .profile-card-header { background:var(--pharmacy-table-th,#f8f9fa); border-bottom:2px solid var(--pharmacy-border,#dee2e6); padding:12px 18px; display:flex; align-items:center; gap:8px; }
        .profile-card-body { padding:20px; }
        .nav-pills .nav-link { color:var(--pharmacy-text,#212529); font-size:.85rem; }
        .nav-pills .nav-link.active { background:var(--pharmacy-primary,#0d6efd); color:#fff; }
        .meta-item { display:flex; align-items:center; gap:8px; padding:8px 0; border-bottom:1px solid var(--pharmacy-border,#dee2e6); font-size:.85rem; }
        .meta-item:last-child { border-bottom:none; }
        .meta-label { min-width:130px; color:var(--pharmacy-text,#6c757d); font-size:.8rem; font-weight:600; }
        #avatarPreview { width:90px; height:90px; border-radius:50%; object-fit:cover; border:3px solid var(--pharmacy-border,#dee2e6); }
        .strength-bar { height:5px; border-radius:3px; transition:all .3s; }
        .protected-badge { background:color-mix(in srgb,#dc3545 10%,transparent); color:#dc3545; border:1px solid color-mix(in srgb,#dc3545 30%,transparent); border-radius:20px; padding:2px 10px; font-size:.72rem; font-weight:700; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="d-flex">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-content flex-grow-1 p-4">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/dashboard.php">Dashboard</a></li>
            <?php if ($isAdminAct): ?>
            <li class="breadcrumb-item"><a href="list.php">Users</a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active">Profile — <?php echo htmlspecialchars($userRecord['full_name']); ?></li>
        </ol>
    </nav>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="flashAlert">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($errors): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-x-circle-fill me-2"></i><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── Left: Profile Card ────────────────────────── -->
        <div class="col-md-4 col-lg-3">
            <div class="profile-card text-center">
                <div class="profile-card-body">
                    <!-- Avatar -->
                    <div class="mb-3 position-relative d-inline-block">
                        <?php if ($avatarPath): ?>
                        <img src="<?php echo htmlspecialchars($avatarPath); ?>?v=<?php echo filemtime($avatarPath); ?>"
                             class="profile-avatar" id="mainAvatar" alt="Profile">
                        <?php else: ?>
                        <div class="avatar-placeholder" id="mainAvatar"
                             style="background:<?php echo $avatarColor; ?>">
                            <?php echo $initials; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($isAdminAct || $isSelfView): ?>
                        <label for="quickAvatarInput"
                               style="position:absolute;bottom:2px;right:2px;width:26px;height:26px;border-radius:50%;background:var(--pharmacy-primary,#0d6efd);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.75rem;box-shadow:0 2px 6px rgba(0,0,0,.3)"
                               title="Change photo">
                            <i class="bi bi-camera-fill"></i>
                        </label>
                        <input type="file" id="quickAvatarInput" accept="image/*" style="display:none">
                        <?php endif; ?>
                    </div>

                    <h5 class="mb-1" style="color:var(--pharmacy-heading,#212529)">
                        <?php echo htmlspecialchars($userRecord['full_name']); ?>
                    </h5>
                    <div style="font-size:.83rem;color:var(--pharmacy-text,#6c757d);font-family:monospace">
                        @<?php echo htmlspecialchars($userRecord['username']); ?>
                    </div>

                    <div class="d-flex align-items-center justify-content-center gap-2 mt-2">
                        <span class="badge <?php echo $roleBadge; ?> px-2 py-1" style="font-size:.75rem">
                            <?php echo ucfirst($userRecord['role']); ?>
                        </span>
                        <?php if ($userRecord['status']): ?>
                        <span class="badge bg-success px-2 py-1" style="font-size:.75rem">Active</span>
                        <?php else: ?>
                        <span class="badge bg-secondary px-2 py-1" style="font-size:.75rem">Inactive</span>
                        <?php endif; ?>
                        <?php if ($isProtected): ?>
                        <span class="protected-badge"><i class="bi bi-shield-fill me-1"></i>Protected</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($isSelfView && !empty($userRecord['is_first_login'])): ?>
                    <div class="alert alert-warning mt-3 py-2" style="font-size:.8rem">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Please complete your account setup — set a security question below.
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Meta info -->
                <div class="px-3 pb-3">
                    <?php if ($userRecord['email']): ?>
                    <div class="meta-item">
                        <i class="bi bi-envelope-fill" style="color:var(--pharmacy-primary,#0d6efd);width:16px"></i>
                        <div>
                            <div class="meta-label">Email</div>
                            <div style="font-size:.83rem;word-break:break-all"><?php echo htmlspecialchars($userRecord['email']); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($userRecord['phone']): ?>
                    <div class="meta-item">
                        <i class="bi bi-telephone-fill" style="color:#198754;width:16px"></i>
                        <div>
                            <div class="meta-label">Phone</div>
                            <div style="font-size:.83rem"><?php echo htmlspecialchars($userRecord['phone']); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($userRecord['last_login']): ?>
                    <div class="meta-item">
                        <i class="bi bi-clock-fill" style="color:#fd7e14;width:16px"></i>
                        <div>
                            <div class="meta-label">Last Login</div>
                            <div style="font-size:.83rem"><?php echo date('d M Y, h:i A', strtotime($userRecord['last_login'])); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="meta-item">
                        <i class="bi bi-calendar-check-fill" style="color:#6f42c1;width:16px"></i>
                        <div>
                            <div class="meta-label">Member Since</div>
                            <div style="font-size:.83rem"><?php echo date('d M Y', strtotime($userRecord['created_at'])); ?></div>
                        </div>
                    </div>
                    <?php if (!empty($userRecord['login_count'])): ?>
                    <div class="meta-item">
                        <i class="bi bi-arrow-repeat" style="color:#0dcaf0;width:16px"></i>
                        <div>
                            <div class="meta-label">Total Logins</div>
                            <div style="font-size:.83rem"><?php echo number_format($userRecord['login_count']); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Admin actions -->
                <?php if ($isAdminAct && !$isSelfView && !$isProtected): ?>
                <div class="px-3 pb-3 d-flex flex-column gap-2">
                    <a href="edit.php?id=<?php echo $viewId; ?>" class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-pencil-fill me-1"></i>Full Edit
                    </a>
                    <a href="activity_log.php?user_id=<?php echo $viewId; ?>" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-activity me-1"></i>Activity Log
                    </a>
                </div>
                <?php elseif ($isAdminAct && !$isSelfView && $isProtected): ?>
                <div class="px-3 pb-3">
                    <div class="alert alert-warning py-2 mb-0" style="font-size:.78rem">
                        <i class="bi bi-shield-fill me-1"></i>This is a protected system account.
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Right: Edit Tabs ────────────────────────────── -->
        <div class="col-md-8 col-lg-9">

            <!-- Tab Nav -->
            <ul class="nav nav-pills mb-3 gap-1" id="profileTabs">
                <li class="nav-item">
                    <button class="nav-link active" data-tab="basic">
                        <i class="bi bi-person-fill me-1"></i>Basic Info
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-tab="avatar">
                        <i class="bi bi-camera-fill me-1"></i>Profile Photo
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-tab="password">
                        <i class="bi bi-key-fill me-1"></i>Password
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-tab="security">
                        <i class="bi bi-shield-lock-fill me-1"></i>Security
                    </button>
                </li>
                <?php if (!empty($recentActivity)): ?>
                <li class="nav-item">
                    <button class="nav-link" data-tab="activity">
                        <i class="bi bi-activity me-1"></i>Activity
                    </button>
                </li>
                <?php endif; ?>
            </ul>

            <!-- ── Tab: Basic Info ──────────────────────────── -->
            <div class="tab-pane" id="tab-basic">
                <div class="profile-card">
                    <div class="profile-card-header">
                        <i class="bi bi-person-fill" style="color:var(--pharmacy-primary,#0d6efd)"></i>
                        <span class="fw-bold" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.06em">Basic Information</span>
                    </div>
                    <div class="profile-card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="tab" value="basic">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" style="font-size:.82rem">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($userRecord['full_name']); ?>"
                                           <?php echo $isProtected ? 'readonly' : ''; ?> required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" style="font-size:.82rem">Username</label>
                                    <input type="text" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($userRecord['username']); ?>" readonly
                                           style="background:var(--pharmacy-table-th,#f8f9fa)">
                                    <div class="form-text" style="font-size:.74rem">Username cannot be changed.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" style="font-size:.82rem">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($userRecord['email']); ?>"
                                           <?php echo $isProtected ? 'readonly' : ''; ?> required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" style="font-size:.82rem">Phone</label>
                                    <input type="text" name="phone" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($userRecord['phone'] ?? ''); ?>"
                                           <?php echo $isProtected ? 'readonly' : ''; ?>>
                                </div>
                                <?php if (!$isProtected): ?>
                                <div class="col-12 pt-1">
                                    <button type="submit" class="btn btn-primary btn-sm px-4">
                                        <i class="bi bi-check-circle-fill me-1"></i>Save Changes
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ── Tab: Avatar ─────────────────────────────── -->
            <div class="tab-pane d-none" id="tab-avatar">
                <div class="profile-card">
                    <div class="profile-card-header">
                        <i class="bi bi-camera-fill" style="color:var(--pharmacy-primary,#0d6efd)"></i>
                        <span class="fw-bold" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.06em">Profile Photo</span>
                    </div>
                    <div class="profile-card-body">
                        <?php if ($isProtected): ?>
                        <div class="alert alert-warning"><i class="bi bi-shield-fill me-1"></i>Profile photo of protected accounts cannot be changed here.</div>
                        <?php else: ?>
                        <div class="d-flex align-items-start gap-4 flex-wrap">
                            <!-- Current avatar -->
                            <div class="text-center">
                                <div class="mb-2" style="font-size:.8rem;font-weight:600;color:var(--pharmacy-text,#6c757d)">Current Photo</div>
                                <?php if ($avatarPath): ?>
                                <img src="<?php echo htmlspecialchars($avatarPath); ?>?v=<?php echo filemtime($avatarPath); ?>"
                                     id="avatarPreview" style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid var(--pharmacy-border,#dee2e6)">
                                <?php else: ?>
                                <div id="avatarPreview" style="width:100px;height:100px;border-radius:50%;background:<?php echo $avatarColor; ?>;display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:700;color:#fff">
                                    <?php echo $initials; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <!-- Upload form -->
                            <div class="flex-grow-1">
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="tab" value="avatar">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold" style="font-size:.82rem">
                                            <i class="bi bi-upload me-1"></i>Upload New Photo
                                        </label>
                                        <input type="file" name="profile_photo" id="avatarFileInput"
                                               class="form-control form-control-sm" accept="image/*">
                                        <div class="form-text">JPG, PNG, GIF or WEBP · Max 2 MB</div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="bi bi-upload me-1"></i>Upload Photo
                                        </button>
                                        <?php if ($avatarPath): ?>
                                        <button type="submit" name="remove_avatar" value="1"
                                                class="btn btn-outline-danger btn-sm"
                                                onclick="return confirm('Remove profile photo?')">
                                            <i class="bi bi-trash me-1"></i>Remove Photo
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── Tab: Password ───────────────────────────── -->
            <div class="tab-pane d-none" id="tab-password">
                <div class="profile-card">
                    <div class="profile-card-header">
                        <i class="bi bi-key-fill" style="color:var(--pharmacy-primary,#0d6efd)"></i>
                        <span class="fw-bold" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.06em">Change Password</span>
                    </div>
                    <div class="profile-card-body">
                        <?php if ($isProtected && !$isAdminAct): ?>
                        <div class="alert alert-warning"><i class="bi bi-shield-fill me-1"></i>Password of this account can only be changed by an administrator.</div>
                        <?php else: ?>
                        <form method="POST" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="tab" value="password">
                            <div class="row g-3" style="max-width:480px">
                                <?php if ($isSelfView || !$isAdminAct): ?>
                                <div class="col-12">
                                    <label class="form-label fw-semibold" style="font-size:.82rem">Current Password <span class="text-danger">*</span></label>
                                    <input type="password" name="current_password" class="form-control form-control-sm" autocomplete="current-password">
                                </div>
                                <?php endif; ?>
                                <div class="col-12">
                                    <label class="form-label fw-semibold" style="font-size:.82rem">New Password <span class="text-danger">*</span></label>
                                    <input type="password" name="new_password" id="newPw" class="form-control form-control-sm"
                                           autocomplete="new-password" minlength="8">
                                    <div class="mt-1 strength-bar" id="strengthBar" style="width:0;background:#dc3545"></div>
                                    <div style="font-size:.74rem;color:var(--pharmacy-text,#6c757d)" id="strengthText"></div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold" style="font-size:.82rem">Confirm New Password <span class="text-danger">*</span></label>
                                    <input type="password" name="confirm_password" id="confirmPw" class="form-control form-control-sm" autocomplete="new-password">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary btn-sm px-4">
                                        <i class="bi bi-key-fill me-1"></i>Change Password
                                    </button>
                                </div>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── Tab: Security ───────────────────────────── -->
            <div class="tab-pane d-none" id="tab-security">
                <div class="profile-card">
                    <div class="profile-card-header">
                        <i class="bi bi-shield-lock-fill" style="color:var(--pharmacy-primary,#0d6efd)"></i>
                        <span class="fw-bold" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.06em">Security Question</span>
                    </div>
                    <div class="profile-card-body">
                        <p style="font-size:.84rem;color:var(--pharmacy-text,#6c757d)">
                            Your security question is used to verify your identity when resetting your password.
                        </p>
                        <?php if ($isProtected): ?>
                        <div class="alert alert-warning"><i class="bi bi-shield-fill me-1"></i>Security settings of protected accounts are managed by the system.</div>
                        <?php else: ?>
                        <form method="POST" style="max-width:480px">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="tab" value="security">
                            <div class="mb-3">
                                <label class="form-label fw-semibold" style="font-size:.82rem">Security Question <span class="text-danger">*</span></label>
                                <select name="security_question" class="form-select form-select-sm" required>
                                    <option value="">— Select a question —</option>
                                    <?php foreach ($securityQuestions as $q): ?>
                                    <option value="<?php echo htmlspecialchars($q); ?>"
                                            <?php echo ($userRecord['security_question'] ?? '') === $q ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($q); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold" style="font-size:.82rem">Answer <span class="text-danger">*</span></label>
                                <input type="text" name="security_answer" class="form-control form-control-sm"
                                       placeholder="Enter your answer (case-insensitive)"
                                       autocomplete="off" minlength="2">
                                <div class="form-text">Your answer is stored securely (hashed). Leave blank to keep the existing answer.</div>
                            </div>
                            <?php if ($userRecord['security_question']): ?>
                            <div class="alert alert-info py-2 mb-3" style="font-size:.8rem">
                                <i class="bi bi-check-circle-fill me-1"></i>
                                Current question: <strong><?php echo htmlspecialchars($userRecord['security_question']); ?></strong>
                            </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary btn-sm px-4">
                                <i class="bi bi-shield-check me-1"></i>Update Security Question
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── Tab: Activity ───────────────────────────── -->
            <?php if (!empty($recentActivity)): ?>
            <div class="tab-pane d-none" id="tab-activity">
                <div class="profile-card">
                    <div class="profile-card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-activity" style="color:var(--pharmacy-primary,#0d6efd)"></i>
                            <span class="fw-bold" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.06em">Recent Activity</span>
                        </div>
                        <a href="activity_log.php?user_id=<?php echo $viewId; ?>" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.75rem">
                            View All
                        </a>
                    </div>
                    <div class="profile-card-body p-0">
                        <?php foreach ($recentActivity as $log): ?>
                        <div class="d-flex align-items-start gap-3 p-3 border-bottom" style="border-color:var(--pharmacy-border,#dee2e6)!important">
                            <div style="width:32px;height:32px;border-radius:50%;background:color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 10%,transparent);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                <i class="bi bi-activity" style="color:var(--pharmacy-primary,#0d6efd);font-size:.8rem"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div style="font-size:.83rem;font-weight:600;color:var(--pharmacy-heading,#212529)">
                                    <?php echo htmlspecialchars($log['action']); ?>
                                </div>
                                <div style="font-size:.78rem;color:var(--pharmacy-text,#6c757d)">
                                    <?php echo htmlspecialchars($log['description'] ?? ''); ?>
                                </div>
                            </div>
                            <div style="font-size:.74rem;color:var(--pharmacy-text,#6c757d);white-space:nowrap">
                                <?php echo date('d M, h:i A', strtotime($log['created_at'])); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /right col -->
    </div><!-- /row -->

</div>
</div>

<!-- Quick avatar upload toast container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999">
    <div id="profileToast" class="toast align-items-center text-white border-0">
        <div class="d-flex">
            <div class="toast-body" id="profileToastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
(function () {

    // ── Tab switching ─────────────────────────────────────────────────────────
    const panes = document.querySelectorAll('.tab-pane');
    document.querySelectorAll('[data-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-tab]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            panes.forEach(p => p.classList.add('d-none'));
            const target = document.getElementById('tab-' + btn.dataset.tab);
            if (target) target.classList.remove('d-none');
        });
    });

    // ── Auto-dismiss flash alert ──────────────────────────────────────────────
    const flashAlert = document.getElementById('flashAlert');
    if (flashAlert) setTimeout(() => { const ba = bootstrap.Alert.getOrCreateInstance(flashAlert); if (ba) ba.close(); }, 5000);

    // ── Avatar file preview ───────────────────────────────────────────────────
    const avatarFileInput = document.getElementById('avatarFileInput');
    if (avatarFileInput) {
        avatarFileInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;
            const url = URL.createObjectURL(file);
            const prev = document.getElementById('avatarPreview');
            if (prev && prev.tagName === 'IMG') {
                prev.src = url;
            } else if (prev) {
                const img = document.createElement('img');
                img.src = url;
                img.id  = 'avatarPreview';
                img.style.cssText = 'width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid var(--pharmacy-border,#dee2e6)';
                prev.replaceWith(img);
            }
        });
    }

    // ── Quick avatar via camera icon (AJAX) ───────────────────────────────────
    const quickInput = document.getElementById('quickAvatarInput');
    const mainAvatar = document.getElementById('mainAvatar');
    if (quickInput && mainAvatar) {
        quickInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('action', 'upload_avatar');
            fd.append('csrf_token', '<?php echo $csrfToken; ?>');
            fd.append('user_id', '<?php echo $viewId; ?>');
            fd.append('avatar', file);

            fetch('ajax_handler.php', { method:'POST', body:fd, credentials:'same-origin' })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const url = res.url + '?v=' + Date.now();
                        if (mainAvatar.tagName === 'IMG') {
                            mainAvatar.src = url;
                        } else {
                            const img = document.createElement('img');
                            img.src = url;
                            img.id  = 'mainAvatar';
                            img.className = 'profile-avatar';
                            img.alt = 'Profile';
                            mainAvatar.replaceWith(img);
                        }
                        showProfileToast(res.message, 'success');
                    } else {
                        showProfileToast(res.message || 'Upload failed.', 'danger');
                    }
                })
                .catch(() => showProfileToast('Upload error. Please try again.', 'danger'));
        });
    }

    function showProfileToast(msg, type) {
        const el = document.getElementById('profileToast');
        if (!el) return;
        el.classList.remove('bg-success','bg-danger','bg-warning');
        el.classList.add('bg-' + type);
        document.getElementById('profileToastMsg').textContent = msg;
        bootstrap.Toast.getOrCreateInstance(el, {delay:3500}).show();
    }

    // ── Password strength ─────────────────────────────────────────────────────
    const newPwInput = document.getElementById('newPw');
    const strengthBar  = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    if (newPwInput && strengthBar) {
        newPwInput.addEventListener('input', function () {
            const v = this.value;
            let score = 0;
            if (v.length >= 8)            score++;
            if (/[A-Z]/.test(v))          score++;
            if (/[0-9]/.test(v))          score++;
            if (/[^A-Za-z0-9]/.test(v))   score++;
            const pcts  = ['0%','30%','50%','75%','100%'];
            const clrs  = ['#dc3545','#dc3545','#fd7e14','#ffc107','#198754'];
            const texts = ['','Weak','Fair','Good','Strong'];
            strengthBar.style.width    = pcts[score];
            strengthBar.style.background = clrs[score];
            if (strengthText) strengthText.textContent = texts[score];
        });
    }

    // Open correct tab if flash was from a specific tab (preserve tab on error)
    <?php if ($errors): ?>
    // Try to open the correct tab based on POST data
    const postedTab = '<?php echo htmlspecialchars(sanitize($_POST['tab'] ?? 'basic')); ?>';
    const tabBtn = document.querySelector('[data-tab="' + postedTab + '"]');
    if (tabBtn) tabBtn.click();
    <?php endif; ?>

})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
