<?php
/**
 * Users Management — Edit User
 * File: users/edit.php
 * Version: V5.0 — Step 15 UI/UX Overhaul
 *
 * PRESERVED FROM V4.1:
 *  - Security question & answer fields (editable by admin)
 *  - security_helpers.php integration
 *  - All PHP validation & update logic intact
 *  - All JavaScript: role→perms, strength meter, avatar preview,
 *    username AJAX check, force logout modal, password reset email,
 *    inline validation, column-all toggles
 *  - Activity log panel (last 5 actions)
 *  - User meta info bar (last login, login count, created date)
 *  - Force Logout & Send Password Reset buttons
 *
 * NEW IN V5.0:
 *  - Design system integration (CSS vars, card styling)
 *  - Correct breadcrumb: Dashboard > Users > Edit User
 *  - Form cards with styled card-headers
 *  - Smooth transitions, hover effects
 *  - Submit loading state (single-submit protection)
 *  - Auto-dismiss flash alerts after 5s
 *  - Page fade-in animation
 *  - Toast notifications for AJAX actions
 *  - FIXED: Sync profile_photo to profile_image (Step 7)
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security_helpers.php';

requireLogin();
// Phase 21: admin or manager with users:edit permission
if (!isAdmin() && !canEdit('users')) {
    if (function_exists('setFlash')) setFlash('error', 'Permission denied.');
    header('Location: ../dashboard.php'); exit;
}
requirePermission('users', 'edit');

$pageTitle = 'Edit User';
$csrfToken = generateCSRFToken();
$errors    = [];

// ── Load target user ──────────────────────────────────────────────────────────
$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    setFlash('error', '<i class="bi bi-x-circle-fill me-2"></i>Invalid user ID.');
    header('Location: list.php'); exit;
}

$userRecord = getOne('SELECT * FROM users WHERE id = :id', [':id' => $userId]);
if (!$userRecord) {
    setFlash('error', '<i class="bi bi-x-circle-fill me-2"></i>User not found.');
    header('Location: list.php'); exit;
}

$createdByUser = null;
if (!empty($userRecord['created_by'])) {
    $createdByUser = getOne('SELECT full_name FROM users WHERE id = :id', [':id' => $userRecord['created_by']]);
}

// ── Protected user guard ──────────────────────────────────────────────────────
// Users with is_protected=1 (e.g. arslan, id=1) may only have role/permissions
// edited by the super-admin; username, deletion and deactivation are blocked.
$isProtectedUser = !empty($userRecord['is_protected']) || $userId === 1;

// ── Module / Action Definitions (unchanged) ───────────────────────────────────
$modules = [
    'dashboard' => 'Dashboard',
    'products'  => 'Products',
    'sales'     => 'Sales',
    'purchases' => 'Purchases',
    'returns'   => 'Returns',
    'credits'   => 'Credits',
    'suppliers' => 'Suppliers',
    'cashbook'  => 'Cashbook',
    'expenses'  => 'Expenses',
    'stock'     => 'Stock Adjustments',
    'reports'   => 'Reports',
    'users'     => 'Users',
    'settings'  => 'Settings',
    'backup'    => 'Backup',
];

$moduleActions = [
    'dashboard' => [true,  false, false, false, false, false, false,  false],
    'products'  => [true,  true,  true,  true,  true,  true,  true,   false],
    'sales'     => [true,  true,  true,  true,  true,  true,  false,  true],
    'purchases' => [true,  true,  true,  true,  true,  true,  true,   false],
    'returns'   => [true,  true,  false, false, true,  true,  false,  false],
    'credits'   => [true,  true,  false, false, false, true,  false,  false],
    'suppliers' => [true,  true,  true,  true,  false, true,  false,  false],
    'cashbook'  => [true,  true,  false, false, false, true,  false,  false],
    'expenses'  => [true,  true,  false, false, false, true,  false,  false],
    'stock'     => [true,  true,  true,  false, false, true,  false,  false],
    'reports'   => [true,  false, false, false, true,  true,  false,  false],
    'users'     => [true,  true,  true,  true,  false, false, false,  false],
    'settings'  => [true,  false, true,  false, false, false, false,  false],
    'backup'    => [true,  false, false, false, false, false, false,  false],
];

$permActions = ['view', 'add', 'edit', 'delete', 'print', 'export', 'import', 'void'];

$roleDefaults = [
    'admin' => [
        'dashboard' => ['view'],
        'products'  => ['view','add','edit','delete','print','export','import'],
        'sales'     => ['view','add','edit','delete','print','export','void'],
        'purchases' => ['view','add','edit','delete','print','export','import'],
        'returns'   => ['view','add','print','export'],
        'credits'   => ['view','add','export'],
        'suppliers' => ['view','add','edit','delete','export'],
        'cashbook'  => ['view','add','export'],
        'expenses'  => ['view','add','export'],
        'stock'     => ['view','add','edit','export'],
        'reports'   => ['view','print','export'],
        'users'     => ['view','add','edit','delete'],
        'settings'  => ['view','edit'],
        'backup'    => ['view'],
    ],
    // Phase 21: Manager — operational lead
    'manager' => [
        'dashboard' => ['view'],
        'products'  => ['view','add','edit','print','export','import'],
        'sales'     => ['view','add','edit','print','export','void'],
        'purchases' => ['view','add','edit','delete','print','export','import'],
        'returns'   => ['view','add','print','export'],
        'credits'   => ['view','add','edit','export'],
        'suppliers' => ['view','add','edit','print','export'],
        'cashbook'  => ['view','add','print','export'],
        'expenses'  => ['view','add','edit','export'],
        'stock'     => ['view','add','edit','export'],
        'reports'   => ['view','print','export'],
        'users'     => ['view','add','edit'],
        'settings'  => ['view','edit'],
        'backup'    => ['view'],
    ],
    'pharmacist' => [
        'dashboard' => ['view'],
        'products'  => ['view','add','edit','print','export','import'],
        'sales'     => ['view','add','print','export','void'],
        'purchases' => ['view','add','print','export'],
        'returns'   => ['view','add','print'],
        'credits'   => ['view','add'],
        'suppliers' => ['view','add','edit'],
        'cashbook'  => ['view'],
        'expenses'  => ['view','add'],
        'stock'     => ['view','add'],
        'reports'   => ['view','print','export'],
        'users'     => [],
        'settings'  => [],
        'backup'    => [],
    ],
    'cashier' => [
        'dashboard' => ['view'],
        'products'  => ['view'],
        'sales'     => ['view','add','print'],
        'purchases' => [],
        'returns'   => ['view','add'],
        'credits'   => ['view','add'],
        'suppliers' => [],
        'cashbook'  => ['view'],
        'expenses'  => ['view','add'],
        'stock'     => [],
        'reports'   => ['view'],
        'users'     => [],
        'settings'  => [],
        'backup'    => [],
    ],
    'viewer' => [
        'dashboard' => ['view'],
        'products'  => ['view'],
        'sales'     => ['view','print'],
        'purchases' => ['view'],
        'returns'   => [],
        'credits'   => [],
        'suppliers' => [],
        'cashbook'  => [],
        'expenses'  => [],
        'stock'     => [],
        'reports'   => ['view','print','export'],
        'users'     => [],
        'settings'  => [],
        'backup'    => [],
    ],
    // Phase 21: Custom — no defaults; all permissions set manually
    'custom' => [
        'dashboard' => [],
        'products'  => [],
        'sales'     => [],
        'purchases' => [],
        'returns'   => [],
        'credits'   => [],
        'suppliers' => [],
        'cashbook'  => [],
        'expenses'  => [],
        'stock'     => [],
        'reports'   => [],
        'users'     => [],
        'settings'  => [],
        'backup'    => [],
    ],
];

// ── Load existing permissions ──────────────────────────────────────────────────
$existingPermissions = getUserPermissions($userId);

// ── Last activity log ─────────────────────────────────────────────────────────
$lastActivity = getLastUserActivity($userId, 5);

// ── POST Handling ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please refresh and try again.';
    } else {

        $fullName         = sanitize($_POST['full_name']    ?? '');
        $username         = sanitize($_POST['username']     ?? '');
        $email            = sanitize($_POST['email']        ?? '');
        $phone            = sanitize($_POST['phone']        ?? '');
        $role             = sanitize($_POST['role']         ?? '');
        $status           = (int)($_POST['status']          ?? 1);
        $defaultPg        = sanitize($_POST['default_page'] ?? 'dashboard');
        $notes            = sanitize($_POST['notes']        ?? '');
        $password         = $_POST['password']              ?? '';
        $confirmPw        = $_POST['confirm_password']      ?? '';
        $securityQuestion = sanitize($_POST['security_question'] ?? '');
        $securityAnswer   = trim($_POST['security_answer'] ?? '');

        if ($fullName === '') $errors[] = 'Full name is required.';
        if ($username  === '') $errors[] = 'Username is required.';
        if ($email === '')     $errors[] = 'Email is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

        // Phase 21: manager can assign pharmacist/cashier/viewer/custom/manager; admin can assign all
        $allowedRoles = ['pharmacist', 'cashier', 'viewer', 'custom'];
        if (isAdmin()) {
            $allowedRoles[] = 'admin';
            $allowedRoles[] = 'manager';
        } elseif (isManager()) {
            $allowedRoles[] = 'manager';
        }
        if (!in_array($role, $allowedRoles, true)) {
            $errors[] = 'You do not have permission to assign this role.';
        }

        $dupUser = getOne(
            'SELECT id FROM users WHERE username = :u AND id != :id',
            [':u' => $username, ':id' => $userId]
        );
        if ($dupUser) $errors[] = 'Username already taken by another user.';

        $dupEmail = getOne(
            'SELECT id FROM users WHERE email = :e AND id != :id',
            [':e' => $email, ':id' => $userId]
        );
        if ($dupEmail) $errors[] = 'Email already registered to another user.';

        if ($securityQuestion !== '' && $securityAnswer === '') {
            $errors[] = 'If you change the security question, you must provide a new answer.';
        }

        if ($password !== '') {
            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            } elseif (!preg_match('/[0-9]/', $password)) {
                $errors[] = 'Password must contain at least one number.';
            } elseif ($password !== $confirmPw) {
                $errors[] = 'Passwords do not match.';
            }
        }

        if ($userId === (int)$_SESSION['user_id'] && $status === 0) {
            $errors[] = 'You cannot deactivate your own account.';
        }

        // ── Protected user: block deactivation ───────────────────────────────
        if (!empty($isProtectedUser) && $status === 0) {
            $errors[] = 'This system account is protected and cannot be deactivated.';
        }

        // ── Profile photo upload (FIX: Sync to BOTH profile_photo and profile_image) ──
        $profilePhoto = $userRecord['profile_photo'] ?? $userRecord['profile_image'] ?? null;
        $fileUploaded = isset($_FILES['profile_photo'])
            && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE
            && !empty($_FILES['profile_photo']['name']);

        if ($fileUploaded) {
            $fileErr = $_FILES['profile_photo']['error'];
            if ($fileErr !== UPLOAD_ERR_OK) {
                $uploadErrMap = [
                    UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
                    UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder is missing.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                    UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
                ];
                $errors[] = $uploadErrMap[$fileErr] ?? 'File upload error (code: ' . $fileErr . ').';
            } else {
                $tmpPath  = $_FILES['profile_photo']['tmp_name'];
                $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $fileType = $finfo->file($tmpPath);

                if (!in_array($fileType, $allowed, true)) {
                    $errors[] = 'Profile photo must be JPG, PNG, GIF or WEBP.';
                } elseif ($_FILES['profile_photo']['size'] > 2 * 1024 * 1024) {
                    $errors[] = 'Profile photo must be smaller than 2 MB.';
                } else {
                    // Phase 28: derive extension from validated MIME type
                    $mimeExtMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                    $ext = $mimeExtMap[$fileType] ?? 'jpg';
                    $newPhoto  = 'user_' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $uploadDir = '../uploads/avatars/';

                    if (!is_dir($uploadDir)) {
                        if (!mkdir($uploadDir, 0755, true)) {
                            $errors[] = 'Could not create upload directory.';
                        }
                    }

                    if (empty($errors)) {
                        if (!is_writable($uploadDir)) {
                            $errors[] = 'Upload directory is not writable.';
                        } elseif (move_uploaded_file($tmpPath, $uploadDir . $newPhoto)) {
                            // Delete old profile photo from both possible locations
                            if (!empty($userRecord['profile_photo']) && $userRecord['profile_photo'] !== $newPhoto) {
                                $oldPath = $uploadDir . $userRecord['profile_photo'];
                                if (file_exists($oldPath)) @unlink($oldPath);
                            }
                            if (!empty($userRecord['profile_image']) && $userRecord['profile_image'] !== $newPhoto) {
                                $oldPath = $uploadDir . $userRecord['profile_image'];
                                if (file_exists($oldPath)) @unlink($oldPath);
                            }
                            $profilePhoto = $newPhoto;
                        } else {
                            $errors[] = 'Failed to move uploaded file.';
                        }
                    }
                }
            }
        }

        $permissions = [];
        foreach ($modules as $mod => $modLabel) {
            $permissions[$mod] = [];
            foreach ($permActions as $action) {
                if (!empty($_POST['perm'][$mod][$action])) {
                    $permissions[$mod][] = $action;
                }
            }
        }

        if (empty($errors)) {
            $updateData = [
                'full_name'         => $fullName,
                'username'          => $username,
                'email'             => $email,
                'phone'             => $phone,
                'role'              => $role,
                'status'            => $status,
                'default_page'      => $defaultPg,
                'notes'             => $notes,
                'password'          => $password ?: null,
                // FIX: Sync both columns until profile_photo is dropped
                'profile_photo'     => $profilePhoto,
                'profile_image'     => $profilePhoto,
                'security_question' => $securityQuestion ?: null,
            ];
            if ($securityAnswer !== '') {
                $updateData['security_answer'] = $securityAnswer;
            }

            $updated = updateUser($userId, $updateData, $permissions);

            if ($updated) {
                logActivity(
                    (int)$_SESSION['user_id'],
                    'UPDATE_USER',
                    "User updated: {$username} (ID: {$userId})"
                );
                setFlash('success', '<i class="bi bi-check-circle-fill me-2"></i>User <strong>' . htmlspecialchars($fullName) . '</strong> updated successfully.');
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'User updated successfully!']);
                    exit;
                }
                header('Location: list.php'); exit;
            } else {
                $errors[] = 'Failed to update user. Please try again.';
            }
        }

        // Repopulate on error
        $userRecord['full_name']         = $fullName;
        $userRecord['username']          = $username;
        $userRecord['email']             = $email;
        $userRecord['phone']             = $phone;
        $userRecord['role']              = $role;
        $userRecord['status']            = $status;
        $userRecord['default_page']      = $defaultPg;
        $userRecord['notes']             = $notes;
        $userRecord['security_question'] = $securityQuestion;
        $existingPermissions             = $permissions;
    }
}

if (isset($isAjax) && $isAjax && !empty($errors)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

$isOwnAccount = ($userId === (int)$_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ── Page animation ───────────────────────────────── */
        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .main-content { margin-left: var(--sb-w, 260px); padding: 24px; min-height: 100vh; transition: margin-left .25s ease; animation: fadeInPage 0.28s ease; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }

        /* ── Card styling ──────────────────────────────────── */
        .form-card {
            border-radius: var(--card-border-radius, 12px);
            border: 1px solid var(--pharmacy-border, #dee2e6);
            background: var(--pharmacy-card-bg, #fff);
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            overflow: hidden;
            margin-bottom: 1.25rem;
            transition: box-shadow 0.2s ease;
        }
        .form-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.10); }
        .form-card-header {
            background: var(--pharmacy-table-th, #f8f9fa);
            border-bottom: 2px solid var(--pharmacy-border, #dee2e6);
            padding: 12px 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-card-header .card-title-text {
            font-size: .8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--pharmacy-heading, #212529);
        }
        .form-card-header .card-title-icon {
            color: var(--pharmacy-primary, #0d6efd);
            font-size: 1rem;
        }
        .form-card-body { padding: 18px; }

        /* ── Form labels ──────────────────────────────────── */
        .form-label-styled {
            font-size: .82rem;
            font-weight: 600;
            color: var(--pharmacy-heading, #212529);
            margin-bottom: 5px;
        }
        .form-text { color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 70%, transparent); }

        /* ── Permissions table ────────────────────────────── */
        .perm-table { min-width: 680px; }
        .perm-table th, .perm-table td { font-size: .82rem; vertical-align: middle; }
        .perm-table th:first-child { width: 130px; }
        .perm-table th {
            background: var(--pharmacy-table-th, #f8f9fa) !important;
            color: var(--pharmacy-heading, #212529) !important;
            position: sticky; top: 0; z-index: 1;
        }
        .perm-table td.na-cell { color: var(--pharmacy-border, #ccc); text-align: center; }
        .perm-col-all { cursor: pointer; color: var(--pharmacy-primary, #0d6efd); }
        .btn-row-full, .btn-row-none {
            font-size: .7rem; padding: 1px 6px; border-radius: 3px; cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-row-full { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .btn-row-none { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
        .btn-row-full:hover { background: #badbcc; }
        .btn-row-none:hover { background: #f5c2c7; }

        /* ── Password strength ────────────────────────────── */
        .strength-bar { height: 5px; border-radius: 3px; transition: all .3s; }

        /* ── Avatar ───────────────────────────────────────── */
        #avatarPreview {
            width: 80px; height: 80px; border-radius: 50%; object-fit: cover;
            border: 3px solid var(--pharmacy-border, #dee2e6);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        #avatarPreview:hover {
            border-color: var(--pharmacy-primary, #0d6efd);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 15%, transparent);
        }

        /* ── Meta info bar ────────────────────────────────── */
        .meta-bar {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #dee2e6);
            border-left: 4px solid var(--pharmacy-primary, #0d6efd);
            border-radius: 10px;
            padding: 10px 18px;
            font-size: .83rem;
            color: var(--pharmacy-text, #6c757d);
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }
        .meta-bar strong { color: var(--pharmacy-heading, #212529); }

        /* ── Activity log ─────────────────────────────────── */
        .activity-log-item {
            font-size: .8rem;
            padding: 8px 4px;
            border-bottom: 1px solid var(--pharmacy-border, #f0f0f0);
            transition: background 0.15s ease;
        }
        .activity-log-item:last-child { border-bottom: none; }
        .activity-log-item:hover {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 4%, transparent);
            border-radius: 6px;
        }

        /* ── Smooth form controls ─────────────────────────── */
        .form-control, .form-select {
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            border-color: var(--pharmacy-border, #dee2e6);
            color: var(--pharmacy-text, #212529);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--pharmacy-primary, #0d6efd);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 15%, transparent);
        }/* modal animation — see css/style.css */

        /* ── Btn transitions ──────────────────────────────── */
        .btn { transition: all 0.2s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        /* ── Username status ──────────────────────────────── */
        #usernameStatus { font-size: .78rem; }
    </style>
</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
<div class="container-fluid">
<div>

            <!-- ── Page Header ──────────────────────────────────────────── -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h4 class="mb-0 fw-bold" style="color:var(--pharmacy-heading,#212529)">
                        <i class="bi bi-pencil-square me-2" style="color:#f6c23e"></i>
                        Edit User —
                        <span style="color:var(--pharmacy-primary,#0d6efd)">
                            <?php echo htmlspecialchars($userRecord['full_name']); ?>
                        </span>
                    </h4>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0" style="font-size:.8rem">
                            <li class="breadcrumb-item">
                                <a href="../dashboard.php" style="color:var(--pharmacy-primary,#0d6efd)">
                                    <i class="bi bi-house-fill me-1"></i>Dashboard
                                </a>
                            </li>
                            <li class="breadcrumb-item">
                                <a href="list.php" style="color:var(--pharmacy-primary,#0d6efd)">Users</a>
                            </li>
                            <li class="breadcrumb-item active" style="color:var(--pharmacy-text,#6c757d)">
                                Edit User
                            </li>
                        </ol>
                    </nav>
                </div>
                <div class="d-flex gap-2">
                    <?php if (!$isOwnAccount && !empty($userRecord['email'])): ?>
                    <button type="button" class="btn btn-outline-info btn-sm" id="btnSendReset"
                            data-user-id="<?php echo $userId; ?>"
                            data-email="<?php echo htmlspecialchars($userRecord['email']); ?>">
                        <i class="bi bi-envelope-fill me-1"></i>Send Reset
                    </button>
                    <?php endif; ?>
                    <?php if (!$isOwnAccount && empty($isProtectedUser)): ?>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="btnForceLogout"
                            data-user-id="<?php echo $userId; ?>"
                            data-csrf="<?php echo $csrfToken; ?>">
                        <i class="bi bi-door-closed-fill me-1"></i>Force Logout
                    </button>
                    <?php endif; ?>
                    <a href="list.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i>Back
                    </a>
                </div>
            </div>

            <!-- ── User Meta Info Bar ───────────────────────────────────── -->
            <div class="meta-bar mb-3 d-flex flex-wrap gap-3 align-items-center">
                <span>
                    <i class="bi bi-clock-history me-1"></i>
                    <strong>Last Login:</strong>
                    <?php echo $userRecord['last_login']
                        ? date('d M Y, h:i A', strtotime($userRecord['last_login']))
                        : 'Never'; ?>
                </span>
                <span>
                    <i class="bi bi-arrow-repeat me-1"></i>
                    <strong>Login Count:</strong>
                    <?php echo number_format((int)($userRecord['login_count'] ?? 0)); ?>
                </span>
                <span>
                    <i class="bi bi-calendar3 me-1"></i>
                    <strong>Created:</strong>
                    <?php echo date('d M Y', strtotime($userRecord['created_at'])); ?>
                    <?php if ($createdByUser): ?>
                        by <strong><?php echo htmlspecialchars($createdByUser['full_name']); ?></strong>
                    <?php endif; ?>
                </span>
                <span class="ms-auto">
                    <span class="badge"
                          style="background:<?php echo $userRecord['status'] ? '#d1fae5' : '#f3f4f6'; ?>;
                                 color:<?php echo $userRecord['status'] ? '#065f46' : '#6b7280'; ?>;
                                 padding:4px 12px;border-radius:20px;font-size:.75rem;font-weight:600">
                        <?php echo $userRecord['status'] ? '● Active' : '○ Inactive'; ?>
                    </span>
                </span>
            </div>

            <?php
            // Build field-level error map for inline display
            $fieldErrors = [];
            foreach ($errors as $e) {
                if (stripos($e, 'full name') !== false)          $fieldErrors['full_name']         = $e;
                elseif (stripos($e, 'username') !== false)       $fieldErrors['username']          = $e;
                elseif (stripos($e, 'email') !== false)          $fieldErrors['email']             = $e;
                elseif (stripos($e, 'password must be at') !== false || stripos($e, 'password must contain') !== false) $fieldErrors['password'] = $e;
                elseif (stripos($e, 'passwords do not match') !== false) $fieldErrors['confirm_password'] = $e;
                elseif (stripos($e, 'security question') !== false && stripos($e, 'answer') !== false) $fieldErrors['security_answer'] = $e;
                elseif (stripos($e, 'security question') !== false) $fieldErrors['security_question'] = $e;
                elseif (stripos($e, 'security answer') !== false) $fieldErrors['security_answer'] = $e;
                else $fieldErrors['_general'][] = $e;
            }
            ?>
            <?php if (!empty($fieldErrors['_general'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4"
                 style="border-radius:10px;border-left:4px solid #dc3545">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Please fix the following errors:</strong>
                <ul class="mb-0 mt-1">
                    <?php foreach ($fieldErrors['_general'] as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!empty($isProtectedUser)): ?>
            <div class="alert mb-3 d-flex align-items-start gap-2"
                 style="background:#fff3cd;border:1px solid #ffc107;border-left:4px solid #dc3545;border-radius:10px">
                <i class="bi bi-shield-fill-exclamation text-danger mt-1"></i>
                <div>
                    <strong style="color:#dc3545">Protected System Account</strong><br>
                    <span style="font-size:.85rem;color:#856404">
                        This account is protected. Username cannot be changed and the account cannot be deleted or deactivated.
                        Role and permissions may still be updated by an administrator.
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <div id="editUserAlert" class="mb-3"></div>
            <form method="POST" enctype="multipart/form-data" id="editUserForm"
                  data-pharm-form
                  data-success-msg="User updated successfully!"
                  data-redirect="list.php"
                  data-redirect-delay="1500"
                  data-loading-text="Saving Changes…"
                  data-alert-target="#editUserAlert"
                  novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <div class="row g-4">

                    <!-- ══ LEFT COLUMN ════════════════════════════════════════ -->
                    <div class="col-lg-4">

                        <!-- Basic Information Card -->
                        <div class="form-card">
                            <div class="form-card-header">
                                <i class="bi bi-person-badge card-title-icon"></i>
                                <span class="card-title-text">Basic Information</span>
                            </div>
                            <div class="form-card-body">

                                <!-- Avatar -->
                                <div class="d-flex align-items-center gap-3 mb-4">
                                    <?php
                                    // Check both profile_photo and profile_image for backward compatibility
                                    $avatarFile = $userRecord['profile_photo'] ?? $userRecord['profile_image'] ?? '';
                                    $avatarPath = '../uploads/avatars/' . $avatarFile;
                                    $hasAvatar  = !empty($avatarFile) && file_exists($avatarPath);
                                    ?>
                                    <img id="avatarPreview"
                                         src="<?php echo $hasAvatar
                                            ? htmlspecialchars($avatarPath)
                                            : 'https://ui-avatars.com/api/?name=' . urlencode($userRecord['full_name']) . '&background=0d6efd&color=fff&size=80'; ?>"
                                         alt="Avatar">
                                    <div class="flex-fill">
                                        <label class="form-label-styled">Profile Photo</label>
                                        <input type="file" name="profile_photo" id="profilePhoto"
                                               class="form-control form-control-sm"
                                               accept="image/jpeg,image/png,image/gif,image/webp">
                                        <div class="form-text" style="font-size:.73rem">
                                            Leave empty to keep current
                                        </div>
                                    </div>
                                </div>

                                <!-- Full Name -->
                                <div class="mb-3">
                                    <label class="form-label-styled" for="full_name">
                                        Full Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" name="full_name" id="full_name"
                                           class="form-control form-control-sm <?php echo isset($fieldErrors['full_name']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($userRecord['full_name']); ?>" required>
                                    <?php if (isset($fieldErrors['full_name'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($fieldErrors['full_name']); ?></div>
                                    <?php endif; ?>
                                </div>

                                <!-- Username -->
                                <div class="mb-3">
                                    <label class="form-label-styled" for="username">
                                        Username <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" name="username" id="username"
                                           class="form-control form-control-sm <?php echo isset($fieldErrors['username']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($userRecord['username']); ?>"
                                           required autocomplete="off">
                                    <?php if (isset($fieldErrors['username'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($fieldErrors['username']); ?></div>
                                    <?php endif; ?>
                                    <div id="usernameStatus" class="mt-1"></div>
                                </div>

                                <!-- Email -->
                                <div class="mb-3">
                                    <label class="form-label-styled" for="email">
                                        Email <span class="text-danger">*</span>
                                    </label>
                                    <input type="email" name="email" id="email"
                                           class="form-control form-control-sm <?php echo isset($fieldErrors['email']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($userRecord['email']); ?>">
                                    <?php if (isset($fieldErrors['email'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($fieldErrors['email']); ?></div>
                                    <?php endif; ?>
                                </div>

                                <!-- Phone -->
                                <div class="mb-3">
                                    <label class="form-label-styled" for="phone">Phone</label>
                                    <input type="text" name="phone" id="phone"
                                           class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($userRecord['phone'] ?? ''); ?>">
                                </div>

                                <!-- Role -->
                                <div class="mb-3">
                                    <label class="form-label-styled" for="role">
                                        Role <span class="text-danger">*</span>
                                    </label>
                                    <select name="role" id="role" class="form-select form-select-sm" required>
                                        <?php if (isAdmin()): ?>
                                        <option value="admin"
                                            <?php echo $userRecord['role']==='admin' ? 'selected' : ''; ?>>
                                            🔴 Admin
                                        </option>
                                        <?php endif; ?>
                                        <?php if (isAdmin() || isManager()): ?>
                                        <option value="manager" <?php echo $userRecord['role']==='manager' ? 'selected':''; ?>>🟠 Manager</option>
                                        <?php endif; ?>
                                        <option value="pharmacist" <?php echo $userRecord['role']==='pharmacist' ? 'selected':''; ?>>🔵 Pharmacist</option>
                                        <option value="cashier"    <?php echo $userRecord['role']==='cashier'    ? 'selected':''; ?>>🟢 Cashier</option>
                                        <option value="viewer"     <?php echo $userRecord['role']==='viewer'     ? 'selected':''; ?>>⚪ Viewer</option>
                                        <option value="custom"     <?php echo $userRecord['role']==='custom'     ? 'selected':''; ?>>🛠️ Custom Role</option>
                                    </select>
                                    <div class="form-text" style="font-size:.73rem">
                                        Changing role will suggest resetting permissions.
                                    </div>
                                </div>

                                <!-- Status -->
                                <div class="mb-1">
                                    <label class="form-label-styled">Status</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox"
                                               name="status" id="statusToggle" value="1"
                                               <?php echo $userRecord['status'] ? 'checked' : ''; ?>
                                               <?php echo $isOwnAccount ? 'disabled' : ''; ?>
                                               style="cursor:pointer">
                                        <label class="form-check-label small" for="statusToggle"
                                               style="color:var(--pharmacy-text,#212529)">
                                            Active
                                            <?php if ($isOwnAccount): ?>
                                            <span style="color:var(--pharmacy-text,#6c757d);font-size:.75rem">
                                                (cannot deactivate own account)
                                            </span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                    <?php if ($isOwnAccount): ?>
                                    <input type="hidden" name="status" value="1">
                                    <?php endif; ?>
                                </div>

                            </div>
                        </div>

                        <!-- Change Password Card -->
                        <div class="form-card">
                            <div class="form-card-header">
                                <i class="bi bi-key card-title-icon"></i>
                                <span class="card-title-text">Change Password</span>
                                <span class="ms-1" style="font-size:.72rem;font-weight:400;color:var(--pharmacy-text,#6c757d)">
                                    (leave blank to keep current)
                                </span>
                            </div>
                            <div class="form-card-body">

                                <div class="mb-3">
                                    <label class="form-label-styled" for="password">New Password</label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="password" id="password"
                                               class="form-control <?php echo isset($fieldErrors['password']) ? 'is-invalid' : ''; ?>"
                                               autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary" id="togglePass">
                                            <i class="bi bi-eye-fill" id="togglePassIcon"></i>
                                        </button>
                                        <?php if (isset($fieldErrors['password'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($fieldErrors['password']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div id="strengthWrapper" style="display:none">
                                        <div class="d-flex gap-1 mt-2">
                                            <div class="strength-bar flex-fill bg-secondary" id="s1"></div>
                                            <div class="strength-bar flex-fill bg-secondary" id="s2"></div>
                                            <div class="strength-bar flex-fill bg-secondary" id="s3"></div>
                                            <div class="strength-bar flex-fill bg-secondary" id="s4"></div>
                                        </div>
                                        <small id="strengthLabel" class="text-muted"></small>
                                    </div>
                                    <div class="mt-1 d-flex gap-3" style="font-size:.78rem">
                                        <span id="cr-length" style="color:#6c757d">Min 8 characters</span>
                                        <span id="cr-number" style="color:#6c757d">1 number</span>
                                    </div>
                                </div>

                                <div class="mb-1">
                                    <label class="form-label-styled" for="confirm_password">Confirm New Password</label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="confirm_password" id="confirm_password"
                                               class="form-control <?php echo isset($fieldErrors['confirm_password']) ? 'is-invalid' : ''; ?>"
                                               autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary" id="toggleConfirm">
                                            <i class="bi bi-eye-fill" id="toggleConfirmIcon"></i>
                                        </button>
                                        <?php if (isset($fieldErrors['confirm_password'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($fieldErrors['confirm_password']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div id="matchStatus" class="small mt-1"></div>
                                </div>

                            </div>
                        </div>

                        <!-- Security Questions Card -->
                        <div class="form-card">
                            <div class="form-card-header">
                                <i class="bi bi-shield-lock card-title-icon"></i>
                                <span class="card-title-text">Security Questions</span>
                            </div>
                            <div class="form-card-body">
                                <div class="mb-3">
                                    <label class="form-label-styled" for="security_question">Security Question</label>
                                    <?php
                                    $currentQuestion = $userRecord['security_question'] ?? '';
                                    $sqClass = 'form-select form-select-sm' . (isset($fieldErrors['security_question']) ? ' is-invalid' : '');
                                    echo renderSecurityQuestionDropdown(
                                        $currentQuestion,
                                        'security_question',
                                        $sqClass,
                                        false
                                    );
                                    ?>
                                    <?php if (isset($fieldErrors['security_question'])): ?>
                                        <div class="invalid-feedback d-block"><?php echo htmlspecialchars($fieldErrors['security_question']); ?></div>
                                    <?php endif; ?>
                                    <div class="form-text" style="font-size:.73rem">Leave unchanged to keep current question.</div>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label-styled" for="security_answer">New Answer (optional)</label>
                                    <input type="text" name="security_answer" id="security_answer"
                                           class="form-control form-control-sm <?php echo isset($fieldErrors['security_answer']) ? 'is-invalid' : ''; ?>"
                                           placeholder="Leave empty to keep current answer">
                                    <?php if (isset($fieldErrors['security_answer'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($fieldErrors['security_answer']); ?></div>
                                    <?php endif; ?>
                                    <div class="form-text" style="font-size:.73rem">
                                        Only fill if changing the answer. Answer will be hashed.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Settings Card -->
                        <div class="form-card">
                            <div class="form-card-header">
                                <i class="bi bi-gear card-title-icon"></i>
                                <span class="card-title-text">Additional Settings</span>
                            </div>
                            <div class="form-card-body">
                                <div class="mb-3">
                                    <label class="form-label-styled" for="default_page">Default Page After Login</label>
                                    <select name="default_page" id="default_page" class="form-select form-select-sm">
                                        <option value="dashboard" <?php echo ($userRecord['default_page']??'dashboard')==='dashboard' ? 'selected':''; ?>>Dashboard</option>
                                        <option value="sales/pos" <?php echo ($userRecord['default_page']??'')==='sales/pos' ? 'selected':''; ?>>POS / Sales</option>
                                        <option value="products"  <?php echo ($userRecord['default_page']??'')==='products'  ? 'selected':''; ?>>Products</option>
                                        <option value="reports"   <?php echo ($userRecord['default_page']??'')==='reports'   ? 'selected':''; ?>>Reports</option>
                                    </select>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label-styled" for="notes">Internal Notes</label>
                                    <textarea name="notes" id="notes"
                                              class="form-control form-control-sm" rows="3"><?php
                                        echo htmlspecialchars($userRecord['notes'] ?? '');
                                    ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Activity Log Card -->
                        <?php if (!empty($lastActivity)): ?>
                        <div class="form-card" style="margin-bottom:0">
                            <div class="form-card-header">
                                <i class="bi bi-activity card-title-icon"></i>
                                <span class="card-title-text">Last 5 Actions</span>
                            </div>
                            <div class="form-card-body p-2">
                                <?php foreach ($lastActivity as $log): ?>
                                <div class="activity-log-item px-2">
                                    <div class="fw-semibold" style="font-size:.8rem;color:var(--pharmacy-heading,#212529)">
                                        <?php echo htmlspecialchars($log['action']); ?>
                                    </div>
                                    <div style="font-size:.75rem;color:var(--pharmacy-text,#6c757d)">
                                        <?php echo htmlspecialchars($log['description']); ?>
                                    </div>
                                    <div style="font-size:.72rem;color:var(--pharmacy-text,#adb5bd)">
                                        <i class="bi bi-clock me-1"></i>
                                        <?php echo date('d M Y, h:i A', strtotime($log['created_at'])); ?>
                                        &nbsp;·&nbsp;
                                        <i class="bi bi-geo-alt me-1"></i>
                                        <?php echo htmlspecialchars($log['ip_address']); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div><!-- /LEFT COL -->

                    <!-- ══ RIGHT COLUMN: Permissions Matrix ═══════════════════ -->
                    <div class="col-lg-8">
                        <div class="form-card" style="margin-bottom:0">
                            <div class="form-card-header d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-shield-lock-fill card-title-icon"></i>
                                    <span class="card-title-text">Permissions Matrix</span>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="button" id="selectAllPerms"
                                            class="btn btn-outline-success btn-sm"
                                            style="font-size:.75rem;padding:3px 10px">
                                        <i class="bi bi-check-all me-1"></i>All
                                    </button>
                                    <button type="button" id="clearAllPerms"
                                            class="btn btn-outline-secondary btn-sm"
                                            style="font-size:.75rem;padding:3px 10px">
                                        <i class="bi bi-x me-1"></i>None
                                    </button>
                                    <button type="button" id="resetRolePerms"
                                            class="btn btn-outline-primary btn-sm"
                                            style="font-size:.75rem;padding:3px 10px">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Reset to Role
                                    </button>
                                </div>
                            </div>
                            <div class="form-card-body p-2">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm perm-table mb-0">
                                        <thead>
                                            <tr class="text-center">
                                                <th class="text-start ps-2">Module</th>
                                                <?php foreach ($permActions as $action): ?>
                                                <th>
                                                    <div style="font-size:.75rem"><?php echo ucfirst($action); ?></div>
                                                    <input type="checkbox" class="form-check-input perm-col-all mt-1"
                                                           data-action-col="<?php echo $action; ?>"
                                                           title="Toggle all <?php echo $action; ?>">
                                                </th>
                                                <?php endforeach; ?>
                                                <th style="font-size:.75rem">Quick</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($modules as $mod => $modLabel):
                                            $applicable = $moduleActions[$mod];
                                            $savedPerms = $existingPermissions[$mod] ?? [];
                                        ?>
                                            <tr id="perm-row-<?php echo $mod; ?>"
                                                style="transition:background 0.15s ease">
                                                <td class="fw-semibold ps-2"
                                                    style="font-size:.83rem;color:var(--pharmacy-heading,#212529)">
                                                    <?php echo htmlspecialchars($modLabel); ?>
                                                </td>
                                                <?php foreach ($permActions as $idx => $action):
                                                    $isApplicable = $applicable[$idx] ?? false;
                                                    if (is_array($savedPerms) && !empty($savedPerms)) {
                                                        if (array_is_list($savedPerms)) {
                                                            $checked = in_array($action, $savedPerms, true);
                                                        } else {
                                                            $checked = !empty($savedPerms[$action]);
                                                        }
                                                    } else {
                                                        $checked = false;
                                                    }
                                                ?>
                                                <td class="text-center <?php echo !$isApplicable ? 'na-cell' : ''; ?>">
                                                    <?php if ($isApplicable): ?>
                                                    <input type="checkbox"
                                                           class="form-check-input perm-checkbox"
                                                           name="perm[<?php echo $mod; ?>][<?php echo $action; ?>]"
                                                           value="1"
                                                           data-module="<?php echo $mod; ?>"
                                                           data-action="<?php echo $action; ?>"
                                                           <?php echo $checked ? 'checked' : ''; ?>
                                                           style="cursor:pointer">
                                                    <?php else: ?>&mdash;<?php endif; ?>
                                                </td>
                                                <?php endforeach; ?>
                                                <td class="text-center" style="white-space:nowrap">
                                                    <button type="button" class="btn-row-full"
                                                            data-row="<?php echo $mod; ?>">All</button>
                                                    <button type="button" class="btn-row-none"
                                                            data-row="<?php echo $mod; ?>">None</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="px-3 py-2 border-top"
                                 style="font-size:.78rem;color:var(--pharmacy-text,#6c757d);border-color:var(--pharmacy-border,#dee2e6)!important">
                                <i class="bi bi-info-circle me-1"></i>
                                Loaded from saved permissions. "Reset to Role" re-applies role baseline.
                            </div>
                        </div>
                    </div><!-- /RIGHT COL -->

                </div><!-- /row -->

                <!-- ── Form Actions ──────────────────────────────────────── -->
                <div class="d-flex justify-content-end gap-3 mt-4 pb-4">
                    <a href="list.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </a>
                    <button type="submit" data-submit-btn class="btn btn-warning px-4 text-dark fw-bold" id="submitBtn">
                        <i class="bi bi-save-fill me-1"></i>Save Changes
                    </button>
                </div>

            </form>
</div>
</div><!-- /container-fluid -->
</div><!-- /main-content -->

<!-- ── Force Logout Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="forceLogoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white"
                 style="background:#dc3545;border-radius:12px 12px 0 0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-door-closed-fill me-2"></i>Force Logout
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <p style="color:var(--pharmacy-text,#212529)">
                    This will immediately invalidate
                    <strong><?php echo htmlspecialchars($userRecord['full_name']); ?></strong>'s
                    session and remember-me token.
                </p>
                <div class="alert alert-danger py-2 mb-0" style="font-size:.83rem">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    Their unsaved work may be lost.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm px-3" id="confirmForceLogout">
                    <i class="bi bi-door-closed-fill me-1"></i>Force Logout Now
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Toast ─────────────────────────────────────────────────────────────── -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999">
    <div id="actionToast" class="toast align-items-center text-white border-0 shadow" style="min-width:280px">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
$(function () {
    const roleDefaults   = <?php echo json_encode($roleDefaults); ?>;
    const permActionsArr = <?php echo json_encode($permActions); ?>;
    const excludeUserId  = <?php echo $userId; ?>;
    const origUsername   = '<?php echo addslashes($userRecord['username']); ?>';

    const toast      = new bootstrap.Toast('#actionToast', { delay: 3500 });
    const forceModal = new bootstrap.Modal('#forceLogoutModal');

    function showToast(msg, type = 'success') {
        $('#actionToast').removeClass('bg-success bg-danger bg-warning bg-info').addClass('bg-' + type);
        $('#toastMsg').text(msg);
        toast.show();
    }

    // ── Auto-dismiss alerts ───────────────────────────────────────────────────
    document.querySelectorAll('.alert').forEach(el => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // ── Role → Permissions ────────────────────────────────────────────────────
    $('#role').on('change', function () { applyRoleDefaults($(this).val()); updateColAllCheckboxes(); });

    function applyRoleDefaults(role) {
        const defaults = roleDefaults[role] || {};
        $('.perm-checkbox').each(function () {
            const mod = $(this).data('module'), action = $(this).data('action');
            $(this).prop('checked', (defaults[mod] || []).includes(action));
        });
    }

    $(document).on('change', '.perm-col-all', function () {
        const action = $(this).data('action-col'), checked = $(this).is(':checked');
        $(`.perm-checkbox[data-action="${action}"]`).prop('checked', checked);
    });

    function updateColAllCheckboxes() {
        permActionsArr.forEach(function (action) {
            const $boxes = $(`.perm-checkbox[data-action="${action}"]`);
            const allChecked = $boxes.length > 0 && $boxes.toArray().every(el => el.checked);
            $(`.perm-col-all[data-action-col="${action}"]`).prop('checked', allChecked);
        });
    }

    $(document).on('change', '.perm-checkbox', function () { updateColAllCheckboxes(); });
    $('#selectAllPerms').on('click', function () { $('.perm-checkbox').prop('checked', true);  $('.perm-col-all').prop('checked', true); });
    $('#clearAllPerms').on('click',  function () { $('.perm-checkbox').prop('checked', false); $('.perm-col-all').prop('checked', false); });
    $('#resetRolePerms').on('click', function () { applyRoleDefaults($('#role').val()); updateColAllCheckboxes(); });
    $(document).on('click', '.btn-row-full', function () {
        $(`input.perm-checkbox[data-module="${$(this).data('row')}"]`).prop('checked', true);
        updateColAllCheckboxes();
    });
    $(document).on('click', '.btn-row-none', function () {
        $(`input.perm-checkbox[data-module="${$(this).data('row')}"]`).prop('checked', false);
        updateColAllCheckboxes();
    });

    updateColAllCheckboxes();

    // ── Password Strength ─────────────────────────────────────────────────────
    const bars   = ['#s1','#s2','#s3','#s4'];
    const colors = ['bg-danger','bg-warning','bg-info','bg-success'];
    const labels = ['Very Weak','Weak','Fair','Strong'];

    function calcStrength(pw) {
        let s = 0;
        if (pw.length >= 8) s++;
        if (pw.length >= 12) s++;
        if (/[0-9]/.test(pw)) s++;
        if (/[^a-zA-Z0-9]/.test(pw)) s++;
        return Math.min(s, 4);
    }

    $('#password').on('input', function () {
        const pw = $(this).val();
        $('#strengthWrapper').toggle(pw.length > 0);
        const score = pw.length ? calcStrength(pw) : 0;
        bars.forEach((id, i) => {
            $(id).removeClass('bg-danger bg-warning bg-info bg-success bg-secondary')
                 .addClass(i < score ? colors[score - 1] : 'bg-secondary');
        });
        if (pw.length) {
            $('#strengthLabel').text('Strength: ' + labels[score - 1])
                .css('color', score<=1?'#dc3545':score===2?'#ffc107':score===3?'#0dcaf0':'#198754');
        } else {
            $('#strengthLabel').text('');
        }
        $('#cr-length').css('color', pw.length >= 8 ? '#198754' : '#dc3545');
        $('#cr-number').css('color', /[0-9]/.test(pw) ? '#198754' : '#dc3545');
        checkMatch();
    });

    function checkMatch() {
        const pw = $('#password').val(), cpw = $('#confirm_password').val();
        if (!pw || !cpw) { $('#matchStatus').text(''); return; }
        if (pw === cpw) {
            $('#matchStatus').html('<span style="color:#198754"><i class="bi bi-check-circle-fill me-1"></i>Match</span>');
        } else {
            $('#matchStatus').html('<span style="color:#dc3545"><i class="bi bi-x-circle-fill me-1"></i>No match</span>');
        }
    }

    $('#confirm_password').on('input', checkMatch);

    $('#togglePass').on('click', function () {
        const $i = $('#password');
        $i.attr('type', $i.attr('type') === 'password' ? 'text' : 'password');
        $('#togglePassIcon').toggleClass('bi-eye-fill bi-eye-slash-fill');
    });
    $('#toggleConfirm').on('click', function () {
        const $i = $('#confirm_password');
        $i.attr('type', $i.attr('type') === 'password' ? 'text' : 'password');
        $('#toggleConfirmIcon').toggleClass('bi-eye-fill bi-eye-slash-fill');
    });

    // ── Avatar Preview ────────────────────────────────────────────────────────
    $('#profilePhoto').on('change', function () {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = e => $('#avatarPreview').attr('src', e.target.result);
            reader.readAsDataURL(file);
        }
    });

    // ── Username Availability ─────────────────────────────────────────────────
    let usernameTimer = null;
    $('#username').on('blur input', function () {
        const val = $(this).val().trim();
        clearTimeout(usernameTimer);
        if (val === origUsername) {
            $('#usernameStatus').html('<span style="color:#198754"><i class="bi bi-check-circle-fill me-1"></i>Current username</span>');
            return;
        }
        if (val.length < 3) { $('#usernameStatus').html('<span class="text-muted">Min 3 characters.</span>'); return; }
        usernameTimer = setTimeout(() => {
            $('#usernameStatus').html('<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Checking…</span>');
            $.get('../ajax/check_username.php', { username: val, exclude_id: excludeUserId }, res => {
                if (res.available) {
                    $('#usernameStatus').html('<span style="color:#198754"><i class="bi bi-check-circle-fill me-1"></i>Available</span>');
                } else {
                    $('#usernameStatus').html('<span style="color:#dc3545"><i class="bi bi-x-circle-fill me-1"></i>Taken</span>');
                }
            }, 'json').fail(() => $('#usernameStatus').html('<span class="text-warning">Could not verify.</span>'));
        }, 500);
    });

    // ── Force Logout ──────────────────────────────────────────────────────────
    $('#btnForceLogout').on('click', function () { forceModal.show(); });

    $('#confirmForceLogout').on('click', function () {
        const $btn = $(this);
        $btn.prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-1"></span>Processing…');
        $.post('../ajax/force_logout_user.php',
            { user_id: <?php echo $userId; ?>, csrf_token: '<?php echo $csrfToken; ?>' },
            res => {
                forceModal.hide();
                if (res.success) showToast('User has been logged out.', 'success');
                else showToast(res.message || 'Failed to force logout.', 'danger');
            }, 'json')
        .fail(() => { forceModal.hide(); showToast('Server error. Try again.', 'danger'); })
        .always(() => {
            $btn.prop('disabled', false)
                .html('<i class="bi bi-door-closed-fill me-1"></i>Force Logout Now');
        });
    });

    // ── Send Password Reset Email ─────────────────────────────────────────────
    $('#btnSendReset').on('click', function () {
        const email = $(this).data('email');
        if (!confirm(`Send password reset email to: ${email}?`)) return;
        const $btn = $(this);
        $btn.prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-1"></span>Sending…');
        $.post('../ajax/send_password_reset.php',
            { user_id: <?php echo $userId; ?>, csrf_token: '<?php echo $csrfToken; ?>' },
            res => {
                if (res.success) showToast('Password reset email sent!', 'success');
                else showToast(res.message || 'Failed to send email.', 'danger');
            }, 'json')
        .fail(() => showToast('Server error.', 'danger'))
        .always(() => {
            $btn.prop('disabled', false)
                .html('<i class="bi bi-envelope-fill me-1"></i>Send Reset');
        });
    });

    // ── Inline Field Validation Helpers ──────────────────────────────────────
    function showFieldError($field, msg) {
        $field.addClass('is-invalid');
        if ($field.siblings('.invalid-feedback').length === 0 &&
            $field.parent().find('.invalid-feedback').length === 0) {
            $field.after('<div class="invalid-feedback">' + msg + '</div>');
        }
    }
    function clearFieldError($field) {
        $field.removeClass('is-invalid');
        $field.siblings('.invalid-feedback').remove();
        $field.parent().find('.invalid-feedback').remove();
    }
    $(document).on('input change', '.form-control, .form-select', function () {
        $(this).removeClass('is-invalid');
        $(this).siblings('.invalid-feedback').remove();
    });

    // Auto-focus first server-side invalid field
    const $firstInvalid = $('.is-invalid').first();
    if ($firstInvalid.length) {
        $firstInvalid[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => $firstInvalid.focus(), 400);
    }

    // ── Form Submit Validation + Loading State ────────────────────────────────
    $('#editUserForm').on('submit', function (e) {
        let $firstError = null;

        function markError($el, msg) {
            clearFieldError($el);
            showFieldError($el, msg);
            if (!$firstError) $firstError = $el;
        }

        if ($('#full_name').val().trim() === '') markError($('#full_name'), 'Full name is required.');
        if ($('#username').val().trim() === '')  markError($('#username'),  'Username is required.');

        const emailVal = $('#email').val().trim();
        if (emailVal === '') markError($('#email'), 'Email is required.');
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) markError($('#email'), 'Please enter a valid email address.');

        const pw = $('#password').val(), cpw = $('#confirm_password').val();
        if (pw !== '') {
            if (pw.length < 8 || !/[0-9]/.test(pw)) markError($('#password'), 'Min 8 characters with at least 1 number.');
            else if (pw !== cpw) markError($('#confirm_password'), 'Passwords do not match.');
        }

        const sq = $('#security_question').val(), sa = $('#security_answer').val().trim();
        if (sq !== '' && sa === '') markError($('#security_answer'), 'If you change the security question, you must provide a new answer.');

        if ($firstError) {
            e.preventDefault();
            $firstError[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => $firstError.focus(), 400);
            return;
        }

        const $btn = $('#submitBtn');
        $btn.prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>