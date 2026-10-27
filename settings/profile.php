<?php
/**
 * settings/profile.php
 * Step 14 — My Profile (UI/UX Enhanced, all logic preserved)
 */
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/settings_functions.php';
require_once '../includes/security_helpers.php';

requireLogin();

if (!function_exists('getUserById')) {
    function getUserById($id) {
        $db = db();
        return $db->getOne("SELECT * FROM users WHERE id = :id", [':id' => (int)$id]);
    }
}

$page_title  = 'My Profile';
$error       = '';
$success     = '';

$viewUserId   = (int)$_SESSION['user_id'];
$isOwnProfile = true;
if (!empty($_GET['id']) && isAdmin()) {
    $rid = (int)$_GET['id'];
    if ($rid > 0) { $viewUserId = $rid; $isOwnProfile = ($rid === (int)$_SESSION['user_id']); }
}

$user = getUserById($viewUserId);
if (!$user) {
    $viewUserId   = (int)$_SESSION['user_id'];
    $isOwnProfile = true;
    $user         = getUserById($viewUserId);
}

$allQuestions = getSecurityQuestions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (!$isOwnProfile && !isAdmin()) {
        $error = 'You can only update your own profile.';
    } else {
        if (!empty($_POST['action']) && $_POST['action'] === 'update_security') {
            $newQuestion = sanitize($_POST['security_question'] ?? '');
            $newAnswer   = trim($_POST['security_answer'] ?? '');
            if ($newQuestion === '' || !array_key_exists($newQuestion, $allQuestions)) {
                $error = 'Please select a valid security question.';
            } elseif ($newAnswer === '' || strlen($newAnswer) < 2) {
                $error = 'Answer must be at least 2 characters.';
            } else {
                try {
                    $pdo    = db()->getConnection();
                    $hashed = password_hash($newAnswer, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmt   = $pdo->prepare("UPDATE users SET security_question = :q, security_answer = :a WHERE id = :id");
                    $stmt->execute([':q' => $newQuestion, ':a' => $hashed, ':id' => $viewUserId]);
                    if (function_exists('logActivity')) logActivity('UPDATE_SECURITY_Q', 'Security question updated for user ID: ' . $viewUserId, (int)$_SESSION['user_id']);
                    $success = 'Security question updated successfully!';
                    $user    = getUserById($viewUserId);
                } catch (Exception $e) { $error = 'Failed to update security question: ' . $e->getMessage(); }
            }
        } else {
            $data = [
                'full_name' => sanitize($_POST['full_name']),
                'email'     => sanitize($_POST['email']),
                'phone'     => sanitize($_POST['phone']),
            ];
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $file    = $_FILES['profile_image'];
                $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
                $maxSize = 3 * 1024 * 1024;
                $finfo   = new finfo(FILEINFO_MIME_TYPE);
                $mime    = $finfo->file($file['tmp_name']);
                if (!in_array($mime, $allowed)) {
                    $error = 'Profile image must be a JPEG, PNG, WebP, or GIF file.';
                } elseif ($file['size'] > $maxSize) {
                    $error = 'Profile image must not exceed 3 MB.';
                } else {
                    $destDir = dirname(__DIR__) . '/uploads/profiles/';
                    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                    $ext     = match($mime) { 'image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp',default=>'jpg' };
                    $newName = 'profile_' . $viewUserId . '_' . time() . '.' . $ext;
                    $gdAvailable = extension_loaded('gd') && function_exists('imagecreatefromjpeg');
                    $saved = false;
                    if ($gdAvailable && $mime !== 'image/gif') {
                        [$srcW, $srcH] = @getimagesize($file['tmp_name']) ?: [0, 0];
                        if ($srcW > 0 && $srcH > 0) {
                            $srcImg = match($mime) {
                                'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
                                'image/png'  => @imagecreatefrompng($file['tmp_name']),
                                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
                                default => false,
                            };
                            if ($srcImg !== false && $srcImg !== null) {
                                $thumbSize = 300;
                                $cropSide  = min($srcW, $srcH);
                                $cropX     = (int)(($srcW - $cropSide) / 2);
                                $cropY     = (int)(($srcH - $cropSide) / 2);
                                $dstImg    = imagecreatetruecolor($thumbSize, $thumbSize);
                                if (in_array($mime, ['image/png','image/webp'])) {
                                    imagealphablending($dstImg, false); imagesavealpha($dstImg, true);
                                    $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
                                    imagefilledrectangle($dstImg, 0, 0, $thumbSize, $thumbSize, $transparent);
                                }
                                imagecopyresampled($dstImg, $srcImg, 0, 0, $cropX, $cropY, $thumbSize, $thumbSize, $cropSide, $cropSide);
                                $saved = match($ext) {
                                    'png'  => imagepng($dstImg, $destDir . $newName),
                                    'webp' => function_exists('imagewebp') ? imagewebp($dstImg, $destDir . $newName, 90) : imagejpeg($dstImg, $destDir . $newName . '.jpg', 90),
                                    default => imagejpeg($dstImg, $destDir . $newName, 90),
                                };
                                imagedestroy($srcImg); imagedestroy($dstImg);
                            }
                        }
                    }
                    if (!$saved) $saved = move_uploaded_file($file['tmp_name'], $destDir . $newName);
                    if ($saved) {
                        if (!empty($user['profile_image'])) {
                            $oldFile = $destDir . basename($user['profile_image']);
                            if (file_exists($oldFile)) @unlink($oldFile);
                        }
                        $data['profile_image'] = $newName;
                    } else {
                        $error = 'Failed to save profile image. Check folder permissions.';
                    }
                }
            }
            if (empty($error)) {
                if (updateUserProfile($viewUserId, $data)) {
                    if (function_exists('logActivity')) logActivity('update_profile', 'User profile updated for ID: ' . $viewUserId, (int)$_SESSION['user_id']);
                    if ($isOwnProfile) {
                        $_SESSION['user_name'] = $data['full_name'];
                        if (!empty($data['profile_image'])) {
                            $_SESSION['user_profile_image'] = $data['profile_image'];
                            unset($_SESSION['user_profile_image_url']);
                        }
                    }
                    $success = 'Profile updated successfully!';
                    $user    = getUserById($viewUserId);
                } else {
                    $error = 'Failed to update profile. Please try again.';
                }
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(isset($error) && $error
            ? ['success' => false, 'message' => $error]
            : ['success' => true,  'message' => isset($success) ? $success : 'Saved!']
        );
        exit;
    }
}

$csrf_token    = generateCSRFToken();
$profileImgUrl = '';
if (!empty($user['profile_image'])) {
    $imgFile = dirname(__DIR__) . '/uploads/profiles/' . $user['profile_image'];
    if (file_exists($imgFile)) $profileImgUrl = BASE_URL . 'uploads/profiles/' . $user['profile_image'] . '?v=' . filemtime($imgFile);
}
// Fallback for legacy profile_photo column (will be removed after migration)
if (!$profileImgUrl && !empty($user['profile_photo'])) {
    foreach (['profiles','avatars'] as $dir) {
        $imgFile = dirname(__DIR__) . '/uploads/' . $dir . '/' . $user['profile_photo'];
        if (file_exists($imgFile)) { $profileImgUrl = BASE_URL . 'uploads/' . $dir . '/' . $user['profile_photo'] . '?v=' . filemtime($imgFile); break; }
    }
}
if ($isOwnProfile && $profileImgUrl) {
    $_SESSION['user_profile_image']     = $user['profile_image'] ?? $user['profile_photo'] ?? '';
    $_SESSION['user_profile_image_url'] = $profileImgUrl;
}
$initials           = strtoupper(implode('', array_map(fn($w) => $w[0], explode(' ', trim($user['full_name'])))));
$initials           = substr($initials, 0, 2);
$savedQuestion      = $user['security_question'] ?? '';
$savedQuestionLabel = $allQuestions[$savedQuestion] ?? '';
$hasSecurityQ       = ($savedQuestion !== '' && $savedQuestion !== null);
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
        @keyframes fadeInPage { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        .main-content { margin-left:var(--sb-w,260px); padding:24px; transition:margin-left .25s; animation:fadeInPage .28s ease; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } } }

        /* Sub-nav */
        .settings-subnav { background:var(--pharmacy-card-bg,#fff); border:1px solid var(--pharmacy-border,#dee2e6); border-radius:12px; padding:8px; margin-bottom:24px; display:flex; flex-wrap:wrap; gap:4px; }
        .settings-subnav a { padding:7px 14px; border-radius:8px; font-size:.82rem; font-weight:500; color:var(--pharmacy-text,#495057); text-decoration:none; transition:all .15s; display:flex; align-items:center; gap:6px; }
        .settings-subnav a:hover { background:color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 8%,var(--pharmacy-card-bg,#fff)); color:var(--pharmacy-primary,#0d6efd); }
        .settings-subnav a.active { background:var(--pharmacy-primary,#0d6efd); color:#fff; }

        /* Card */
        .card-custom { background:var(--pharmacy-card-bg,#fff); border:1px solid var(--pharmacy-border,rgba(0,0,0,.08)); border-radius:var(--card-border-radius,15px); box-shadow:0 2px 10px rgba(0,0,0,.05); margin-bottom:20px; color:var(--pharmacy-text,#444); transition:box-shadow .2s; }
        .card-custom:hover { box-shadow:0 6px 24px rgba(0,0,0,.1); }
        .card-header-custom { background:var(--pharmacy-table-th,#f8f9fa) !important; border-bottom:2px solid var(--pharmacy-border,rgba(0,0,0,.08)) !important; padding:14px 20px; border-radius:calc(var(--card-border-radius,15px) - 1px) calc(var(--card-border-radius,15px) - 1px) 0 0; font-weight:600; color:var(--pharmacy-heading,#1a1d23) !important; }

        /* Avatar */
        .avatar-wrap { position:relative; width:120px; height:120px; margin:0 auto 16px; }
        .avatar-img, .avatar-initials { width:120px; height:120px; border-radius:50%; object-fit:cover; border:3px solid var(--pharmacy-border,#e9ecef); }
        .avatar-initials { background:linear-gradient(135deg,var(--pharmacy-accent,#667eea),var(--pharmacy-primary,#764ba2)); display:flex; align-items:center; justify-content:center; font-size:36px; font-weight:700; color:#fff; letter-spacing:2px; }
        .avatar-edit-btn { position:absolute; bottom:4px; right:4px; background:var(--pharmacy-primary,#0d6efd); color:#fff; border:2px solid #fff; border-radius:50%; width:32px; height:32px; font-size:12px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .2s; }
        .avatar-edit-btn:hover { filter:brightness(.9); }

        /* Modal dropzone */
        .img-upload-drop { border:2px dashed var(--pharmacy-border,#dee2e6); border-radius:12px; padding:30px 20px; text-align:center; cursor:pointer; transition:border-color .2s,background .2s; background:var(--pharmacy-body-bg,#fafbfc); }
        .img-upload-drop:hover, .img-upload-drop.dragover { border-color:var(--pharmacy-primary,#0d6efd); background:color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 8%,var(--pharmacy-card-bg,#fff)); }
        .img-upload-drop input[type="file"] { display:none; }
        .crop-preview-wrap { display:none; text-align:center; margin-top:12px; }
        .crop-preview-wrap img { max-width:150px; max-height:150px; border-radius:50%; border:3px solid var(--pharmacy-border,#e9ecef); object-fit:cover; }

        /* Sec-Q badge */
        .sec-q-badge { background:color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 8%,var(--pharmacy-card-bg,#fff)); border:1px solid color-mix(in srgb,var(--pharmacy-primary,#0d6efd) 25%,transparent); border-radius:8px; padding:10px 14px; font-size:13px; }

        /* Form */
        .form-label { color:var(--pharmacy-heading,#1a1d23); font-weight:600; font-size:.87rem; }
        .form-control,.form-select { background:var(--pharmacy-card-bg,#fff); color:var(--pharmacy-text,#444); border-color:var(--pharmacy-border,#dee2e6); transition:border-color .2s,box-shadow .2s; }
        .btn { transition:all .2s ease; }
    </style>
</head>
<body class="bg-light<?php echo $_darkClass ?? ''; ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid" style="max-width:1100px; padding-top:80px;">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-0" style="color:var(--pharmacy-heading)">
                <i class="fas fa-user-circle text-primary me-2"></i>
                <?php echo $isOwnProfile ? 'My Profile' : htmlspecialchars($user['full_name']) . "'s Profile"; ?>
            </h4>
            <small style="color:var(--pharmacy-text);opacity:.65">
                Dashboard &rsaquo; Settings &rsaquo; <?php echo $isOwnProfile ? 'My Profile' : 'User Profile'; ?>
            </small>
        </div>
        <a href="../dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Dashboard
        </a>
    </div>

    <!-- Settings Sub-nav -->
    <nav class="settings-subnav">
        <a href="company.php"><i class="fas fa-hospital"></i>Pharmacy Info</a>
        <a href="theme_editor_v2.php"><i class="fas fa-palette"></i>Theme Editor</a>
        <a href="preferences.php"><i class="fas fa-sliders-h"></i>Preferences</a>
        <a href="profile.php" class="active"><i class="fas fa-user-circle"></i>My Profile</a>
        <a href="change_password.php"><i class="fas fa-key"></i>Change Password</a>
        <a href="backup.php"><i class="fas fa-database"></i>Backup</a>
    </nav>

    <?php if (!$isOwnProfile && isAdmin()): ?>
    <div class="alert alert-info py-2 mb-3" style="font-size:13px;border-radius:10px;">
        <i class="fas fa-eye me-2"></i>
        Viewing profile of <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>.
        <a href="<?php echo BASE_URL; ?>users/edit.php?id=<?php echo $viewUserId; ?>" class="ms-2 btn btn-sm btn-outline-primary py-0">
            <i class="fas fa-edit me-1"></i>Edit User
        </a>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── Left: Avatar & Info ──────────────────────────────── -->
        <div class="col-md-4">
            <div class="card-custom text-center p-4">
                <div class="avatar-wrap">
                    <?php if ($profileImgUrl): ?>
                        <img id="sidebarAvatar" class="avatar-img"
                             src="<?php echo htmlspecialchars($profileImgUrl); ?>" alt="Profile photo">
                    <?php else: ?>
                        <div id="sidebarAvatar" class="avatar-initials"><?php echo htmlspecialchars($initials); ?></div>
                    <?php endif; ?>
                    <?php if ($isOwnProfile): ?>
                    <div class="avatar-edit-btn" id="openImgModal" title="Change photo">
                        <i class="fas fa-camera"></i>
                    </div>
                    <?php endif; ?>
                </div>

                <h5 class="mb-0" style="color:var(--pharmacy-heading)"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                <p class="mb-1" style="color:var(--pharmacy-text);opacity:.7"><?php echo ucfirst($user['role']); ?></p>
                <span class="badge <?php echo ((int)($user['status'] ?? 1)) ? 'bg-success' : 'bg-danger'; ?> mb-3">
                    <?php echo ((int)($user['status'] ?? 1)) ? 'Active' : 'Inactive'; ?>
                </span>
                <hr style="border-color:var(--pharmacy-border)">
                <div class="text-start" style="font-size:.87rem">
                    <p><i class="fas fa-user me-2 text-primary"></i>
                        <strong>Username:</strong> <?php echo htmlspecialchars($user['username']); ?></p>
                    <p><i class="fas fa-envelope me-2 text-primary"></i>
                        <strong>Email:</strong> <?php echo htmlspecialchars($user['email'] ?? '—'); ?></p>
                    <p><i class="fas fa-phone me-2 text-primary"></i>
                        <strong>Phone:</strong> <?php echo htmlspecialchars($user['phone'] ?? '—'); ?></p>
                    <p><i class="fas fa-calendar me-2 text-primary"></i>
                        <strong>Member Since:</strong> <?php echo formatDate($user['created_at']); ?></p>
                    <p class="mb-0"><i class="fas fa-clock me-2 text-primary"></i>
                        <strong>Last Login:</strong>
                        <?php echo $user['last_login'] ? formatDate($user['last_login'], 'd/m/Y h:i A') : 'Never'; ?>
                    </p>
                </div>
            </div>

            <!-- Security Question -->
            <div class="card-custom p-4">
                <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--pharmacy-text);opacity:.6;border-bottom:1px solid var(--pharmacy-border);padding-bottom:6px;margin-bottom:14px;">
                    <i class="fas fa-shield-alt me-1 text-primary"></i>Security Question
                </div>

                <?php if ($hasSecurityQ): ?>
                <div class="sec-q-badge mb-2">
                    <div style="font-size:11px;opacity:.6;margin-bottom:3px;">Question</div>
                    <div style="font-weight:600;font-size:13px;color:var(--pharmacy-heading)">
                        <i class="fas fa-question-circle text-primary me-1"></i>
                        <?php echo htmlspecialchars($savedQuestionLabel); ?>
                    </div>
                </div>
                <div class="sec-q-badge mb-3">
                    <div style="font-size:11px;opacity:.6;margin-bottom:3px;">Answer</div>
                    <div style="font-weight:600;font-size:13px;color:var(--pharmacy-heading)">
                        <i class="fas fa-lock text-success me-1"></i>
                        <span>••••••••</span>
                    </div>
                    <div style="font-size:10px;opacity:.6;margin-top:4px;">
                        <i class="fas fa-shield-alt me-1"></i>Stored securely (hashed)
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-warning py-2 mb-3" style="font-size:12px;border-radius:8px;">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    No security question set. Set one below to enable password recovery.
                </div>
                <?php endif; ?>

                <div id="secQAlertArea" class="mb-2"></div>
                <form method="POST" action="" id="secQForm"
                      data-pharm-form
                      data-success-msg="Security question updated!"
                      data-loading-text="Saving…"
                      data-alert-target="#secQAlertArea"
                      novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="update_security">
                    <div class="mb-2">
                        <label class="form-label small"><?php echo $hasSecurityQ ? 'Change Question' : 'Set Question'; ?></label>
                        <?php echo renderSecurityQuestionDropdown($savedQuestion, 'security_question', 'form-select form-select-sm', true); ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small"><?php echo $hasSecurityQ ? 'New Answer' : 'Your Answer'; ?></label>
                        <input type="text" name="security_answer" class="form-control form-control-sm"
                               placeholder="<?php echo $hasSecurityQ ? 'Enter new answer' : 'Enter your answer'; ?>"
                               required minlength="2" autocomplete="off">
                        <div class="form-text" style="font-size:10px;">Case-sensitive. Will be hashed.</div>
                    </div>
                    <button type="submit" data-submit-btn class="btn btn-sm btn-primary w-100">
                        <i class="fas fa-shield-alt me-1"></i>
                        <?php echo $hasSecurityQ ? 'Update Security Question' : 'Set Security Question'; ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- ── Right: Edit Form ─────────────────────────────────── -->
        <div class="col-md-8">
            <div class="card-custom">
                <div class="card-header-custom">
                    <i class="fas fa-edit me-2 text-primary"></i>
                    <?php echo $isOwnProfile ? 'Edit Profile' : 'Profile Details — ' . htmlspecialchars($user['full_name']); ?>
                </div>
                <div class="card-body p-4">

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div id="profileAlertArea" class="mb-3"></div>
                    <form method="POST" action="" enctype="multipart/form-data" id="profileForm"
                          data-pharm-form
                          data-success-msg="Profile updated successfully!"
                          data-loading-text="Saving Profile…"
                          data-alert-target="#profileAlertArea"
                          novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <?php if ($isOwnProfile): ?>
                        <input type="file" name="profile_image" id="profileImageInput"
                               accept="image/jpeg,image/png,image/webp" style="display:none">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control"
                                   <?php echo !$isOwnProfile && !isAdmin() ? 'readonly' : 'required'; ?>
                                   value="<?php echo htmlspecialchars($user['full_name']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone" class="form-control"
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control"
                                   style="background:var(--pharmacy-table-th)"
                                   value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                            <small style="opacity:.6">Username cannot be changed here.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <input type="text" class="form-control"
                                   style="background:var(--pharmacy-table-th)"
                                   value="<?php echo ucfirst($user['role']); ?>" disabled>
                        </div>
                        <hr style="border-color:var(--pharmacy-border)">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <?php if ($isOwnProfile): ?>
                                <a href="change_password.php" class="btn btn-warning">
                                    <i class="fas fa-key me-2"></i>Change Password
                                </a>
                                <?php endif; ?>
                                <?php if (isAdmin() && !$isOwnProfile): ?>
                                <a href="<?php echo BASE_URL; ?>users/edit.php?id=<?php echo $viewUserId; ?>"
                                   class="btn btn-outline-primary">
                                    <i class="fas fa-user-edit me-1"></i>Full Edit (Admin)
                                </a>
                                <?php endif; ?>
                            </div>
                            <button type="submit" data-submit-btn class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div><!-- /row -->

    <!-- Profile Image Modal -->
    <?php if ($isOwnProfile): ?>
    <div class="modal fade" id="imgUploadModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-semibold">Change Profile Photo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-2">
                    <div class="img-upload-drop" id="imgDropzone">
                        <input type="file" id="modalFileInput" accept="image/jpeg,image/png,image/webp">
                        <i class="fas fa-user-circle fa-3x mb-3" style="opacity:.4"></i>
                        <p class="mb-1 fw-medium" style="color:var(--pharmacy-heading)">Click or drag a photo here</p>
                        <small style="opacity:.6">JPEG, PNG, or WebP · Max 3 MB<br>Will be resized &amp; cropped to 300×300 px</small>
                    </div>
                    <div class="crop-preview-wrap" id="cropPreviewWrap">
                        <p class="mb-2 small" style="opacity:.6">Preview (after upload &amp; crop):</p>
                        <img id="cropPreviewImg" src="" alt="Preview">
                        <p class="mt-2 small" id="imgFileName" style="opacity:.6"></p>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="clearImgBtn">
                            <i class="fas fa-times me-1"></i>Remove
                        </button>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="applyImgBtn" disabled>
                        <i class="fas fa-check me-1"></i>Apply &amp; Save
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /container -->
</div><!-- /main-content -->



<?php if ($isOwnProfile): ?>
<script>
(function () {
    const openBtn         = document.getElementById('openImgModal');
    const imgModal        = new bootstrap.Modal(document.getElementById('imgUploadModal'));
    const imgDropzone     = document.getElementById('imgDropzone');
    const modalFileInput  = document.getElementById('modalFileInput');
    const profileImgInput = document.getElementById('profileImageInput');
    const cropPreviewWrap = document.getElementById('cropPreviewWrap');
    const cropPreviewImg  = document.getElementById('cropPreviewImg');
    const imgFileName     = document.getElementById('imgFileName');
    const clearImgBtn     = document.getElementById('clearImgBtn');
    const applyImgBtn     = document.getElementById('applyImgBtn');
    const profileForm     = document.getElementById('profileForm');
    let selectedFile      = null;

    openBtn.addEventListener('click', () => imgModal.show());
    imgDropzone.addEventListener('click', () => modalFileInput.click());
    imgDropzone.addEventListener('dragover', e => { e.preventDefault(); imgDropzone.classList.add('dragover'); });
    imgDropzone.addEventListener('dragleave', () => imgDropzone.classList.remove('dragover'));
    imgDropzone.addEventListener('drop', e => {
        e.preventDefault(); imgDropzone.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) handleFile(e.dataTransfer.files[0]);
    });
    modalFileInput.addEventListener('change', function () { if (this.files && this.files[0]) handleFile(this.files[0]); });

    function handleFile(file) {
        const allowed = ['image/jpeg','image/png','image/webp'];
        if (!allowed.includes(file.type)) { Notify.warning('Please select a JPEG, PNG, or WebP image.'); return; }
        if (file.size > 3 * 1024 * 1024) { Notify.error('File size must not exceed 3 MB.'); return; }
        selectedFile = file;
        const reader = new FileReader();
        reader.onload = e => {
            cropPreviewImg.src = e.target.result;
            imgFileName.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
            cropPreviewWrap.style.display = 'block';
            applyImgBtn.disabled = false;
        };
        reader.readAsDataURL(file);
    }

    clearImgBtn.addEventListener('click', () => {
        selectedFile = null; modalFileInput.value = '';
        cropPreviewWrap.style.display = 'none'; applyImgBtn.disabled = true;
    });

    applyImgBtn.addEventListener('click', () => {
        if (!selectedFile) return;
        try {
            const dt = new DataTransfer();
            dt.items.add(selectedFile);
            profileImgInput.files = dt.files;
        } catch (err) {
            modalFileInput.name  = 'profile_image';
            profileImgInput.name = '_unused';
        }
        imgModal.hide();
        const previewSrc = cropPreviewImg.src;
        if (previewSrc) {
            document.querySelectorAll('#sidebarUserAvatar,.sidebar-user-avatar,.sb-user-avatar,[data-sidebar-avatar],#navUserAvatar,.nav-user-avatar').forEach(el => {
                if (el.tagName === 'IMG') el.src = previewSrc;
            });
        }
        profileForm.submit();
    });
})();
</script>
<?php endif; ?>
<script>
document.querySelectorAll('.alert:not(.alert-permanent)').forEach(el => {
    setTimeout(() => { try { bootstrap.Alert.getOrCreateInstance(el).close(); } catch(e){} }, 5000);
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
