<?php
/**
 * Users Management — Add New User
 * File: users/add.php
 * Version: V5.0 — Step 15 UI/UX Overhaul
 *
 * PRESERVED FROM V4.1:
 *  - Security question & answer fields (hashed storage)
 *  - security_helpers.php integration
 *  - is_first_login = 1 for new users
 *  - All PHP validation logic intact
 *  - All JavaScript: role→perms, strength meter, avatar preview,
 *    username AJAX check, localStorage draft, inline validation
 *
 * NEW IN V5.0:
 *  - Design system integration (CSS vars, card styling)
 *  - Correct breadcrumb: Dashboard > Users > Add User
 *  - Grouped form cards with clear card-headers
 *  - Smooth transitions, hover effects
 *  - Submit loading state (single-submit protection)
 *  - Auto-dismiss flash alerts after 5s
 *  - Page fade-in animation
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security_helpers.php';

requireLogin();
// Phase 21: admin or manager with users:add permission
if (!isAdmin() && !canAdd('users')) {
    if (function_exists('setFlash')) setFlash('error', 'Permission denied.');
    header('Location: ../dashboard.php'); exit;
}
requirePermission('users', 'add');

$pageTitle = 'Add New User';
$csrfToken = generateCSRFToken();
$errors    = [];
$formData  = [
    'full_name'         => '',
    'username'          => '',
    'email'             => '',
    'phone'             => '',
    'role'              => 'cashier',
    'default_page'      => 'dashboard',
    'notes'             => '',
    'security_question' => '',
    'security_answer'   => '',
];

// ── V4.0 Module & Action Definitions (unchanged) ──────────────────────────────
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
    // Phase 21: Manager — operational lead with broad access, limited settings/backup
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
        'reports'   => ['view','print','export'],
        'users'     => [],
        'settings'  => [],
        'backup'    => [],
    ],
    // Phase 21: Custom — no defaults; admin sets permissions manually
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

// ── POST Handling (added security fields) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please refresh and try again.';
    } else {

        $formData['full_name']         = sanitize($_POST['full_name']    ?? '');
        $formData['username']          = sanitize($_POST['username']     ?? '');
        $formData['email']             = sanitize($_POST['email']        ?? '');
        $formData['phone']             = sanitize($_POST['phone']        ?? '');
        $formData['role']              = sanitize($_POST['role']         ?? 'cashier');
        $formData['default_page']      = sanitize($_POST['default_page'] ?? 'dashboard');
        $formData['notes']             = sanitize($_POST['notes']        ?? '');
        $formData['security_question'] = sanitize($_POST['security_question'] ?? '');
        $formData['security_answer']   = trim($_POST['security_answer'] ?? '');
        $password                      = $_POST['password']         ?? '';
        $confirmPassword               = $_POST['confirm_password'] ?? '';

        if ($formData['full_name'] === '') $errors[] = 'Full name is required.';
        if ($formData['username']  === '') $errors[] = 'Username is required.';
        if ($formData['email']     === '') $errors[] = 'Email is required.';
        elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

        if ($password === '') {
            $errors[] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }
        if ($password !== $confirmPassword) $errors[] = 'Passwords do not match.';

        if ($formData['security_question'] === '') {
            $errors[] = 'Please select a security question.';
        }
        if ($formData['security_answer'] === '') {
            $errors[] = 'Please provide an answer for the security question.';
        } elseif (strlen($formData['security_answer']) < 2) {
            $errors[] = 'Security answer must be at least 2 characters.';
        }

        // Phase 21: manager can create pharmacist/cashier/viewer/custom; admin can create all
        $allowedRoles = ['pharmacist', 'cashier', 'viewer', 'custom'];
        if (isAdmin()) {
            $allowedRoles[] = 'admin';
            $allowedRoles[] = 'manager';
        } elseif (isManager()) {
            $allowedRoles[] = 'manager'; // manager can create other managers
        }
        if (!in_array($formData['role'], $allowedRoles, true)) {
            $errors[] = 'You do not have permission to assign this role.';
        }

        if ($formData['username'] !== '') {
            $exists = getOne('SELECT id FROM users WHERE username = :u', [':u' => $formData['username']]);
            if ($exists) $errors[] = 'Username already taken. Please choose another.';
        }

        if ($formData['email'] !== '') {
            $existsEmail = getOne('SELECT id FROM users WHERE email = :e', [':e' => $formData['email']]);
            if ($existsEmail) $errors[] = 'Email already registered.';
        }

        $profilePhoto = null;
        if (!empty($_FILES['profile_photo']['name'])) {
            $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            // Phase 28: use finfo for MIME detection (not spoofable via extension)
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $fileType = $finfo->file($_FILES['profile_photo']['tmp_name']);
            if (!in_array($fileType, $allowed)) {
                $errors[] = 'Profile photo must be JPG, PNG, GIF or WEBP.';
            } elseif ($_FILES['profile_photo']['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Profile photo must be smaller than 2 MB.';
            } else {
                // Phase 28: derive extension from MIME, not user-supplied filename
                $mimeExtMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                $ext = $mimeExtMap[$fileType] ?? 'jpg';
                $profilePhoto = 'user_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $uploadDir    = '../uploads/avatars/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $profilePhoto)) {
                    $errors[] = 'Failed to upload profile photo.';
                    $profilePhoto = null;
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
            $userData = [
                'full_name'         => $formData['full_name'],
                'username'          => $formData['username'],
                'email'             => $formData['email'],
                'phone'             => $formData['phone'],
                'role'              => $formData['role'],
                'password'          => $password,
                'default_page'      => $formData['default_page'],
                'notes'             => $formData['notes'],
                'security_question' => $formData['security_question'],
                'security_answer'   => $formData['security_answer'],
            ];
            if ($profilePhoto) {
                $userData['profile_photo'] = $profilePhoto;
                $userData['profile_image'] = $profilePhoto; // sync both columns
            }

            $userId = createUser($userData, $permissions);

            if ($userId) {
                logActivity(
                    (int)$_SESSION['user_id'],
                    'CREATE_USER',
                    "New user created: {$formData['username']} (Role: {$formData['role']})"
                );
                setFlash('success', '<i class="bi bi-check-circle-fill me-2"></i>User <strong>' . htmlspecialchars($formData['full_name']) . '</strong> created successfully.');
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'User created successfully!']);
                    exit;
                }
                header('Location: list.php');
                exit;
            } else {
                $errors[] = 'Failed to create user. Please try again.';
            }
        }
    }
}

$selectedRole    = $formData['role'];

if (isset($isAjax) && $isAjax && !empty($errors)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

$currentDefaults = $roleDefaults[$selectedRole] ?? $roleDefaults['cashier'];
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
        .main-content { animation: fadeInPage 0.28s ease; }

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
            display: none; transition: border-color 0.2s ease;
        }
        .avatar-placeholder {
            width: 80px; height: 80px; border-radius: 50%;
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 8%, #fff);
            border: 2px dashed var(--pharmacy-border, #dee2e6);
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem;
            color: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 40%, transparent);
            transition: all 0.2s ease;
        }
        .avatar-placeholder:hover { border-color: var(--pharmacy-primary, #0d6efd); }

        /* ── Username status ──────────────────────────────── */
        .username-status { font-size: .78rem; }

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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-0 fw-bold" style="color:var(--pharmacy-heading,#212529)">
                        <i class="bi bi-person-plus-fill me-2"
                           style="color:var(--pharmacy-primary,#0d6efd)"></i>Add New User
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
                                Add User
                            </li>
                        </ol>
                    </nav>
                </div>
                <a href="list.php" class="btn btn-outline-secondary btn-sm"
                   style="transition:all 0.2s ease">
                    <i class="bi bi-arrow-left me-1"></i>Back to List
                </a>
            </div>

            <?php
            // Build field-level error map for inline display
            $fieldErrors = [];
            foreach ($errors as $e) {
                if (stripos($e, 'full name') !== false)          $fieldErrors['full_name']         = $e;
                elseif (stripos($e, 'username') !== false)       $fieldErrors['username']          = $e;
                elseif (stripos($e, 'email') !== false)          $fieldErrors['email']             = $e;
                elseif (stripos($e, 'password must be at') !== false || stripos($e, 'password is required') !== false || stripos($e, 'password must contain') !== false) $fieldErrors['password'] = $e;
                elseif (stripos($e, 'passwords do not match') !== false) $fieldErrors['confirm_password'] = $e;
                elseif (stripos($e, 'security question') !== false) $fieldErrors['security_question'] = $e;
                elseif (stripos($e, 'security answer') !== false || stripos($e, 'answer for') !== false) $fieldErrors['security_answer'] = $e;
                else $fieldErrors['_general'][] = $e;
            }
            ?>
            <?php if (!empty($fieldErrors['_general'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert"
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

            <div id="addUserAlert" class="mb-3"></div>
            <form method="POST" enctype="multipart/form-data" id="addUserForm"
                  data-pharm-form
                  data-success-msg="User created successfully!"
                  data-redirect="list.php"
                  data-redirect-delay="1500"
                  data-loading-text="Creating User…"
                  data-alert-target="#addUserAlert"
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
                                    <div id="avatarPlaceholder" class="avatar-placeholder">
                                        <i class="bi bi-person"></i>
                                    </div>
                                    <img id="avatarPreview" src="#" alt="Preview">
                                    <div class="flex-fill">
                                        <label class="form-label-styled">
                                            Profile Photo
                                            <span class="fw-normal" style="color:var(--pharmacy-text,#6c757d);font-size:.75rem">(optional)</span>
                                        </label>
                                        <input type="file" name="profile_photo" id="profilePhoto"
                                               class="form-control form-control-sm"
                                               accept="image/jpeg,image/png,image/gif,image/webp">
                                        <div class="form-text" style="font-size:.73rem">Max 2 MB · JPG/PNG/GIF/WEBP</div>
                                    </div>
                                </div>

                                <!-- Full Name -->
                                <div class="mb-3">
                                    <label class="form-label-styled" for="full_name">
                                        Full Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" name="full_name" id="full_name"
                                           class="form-control form-control-sm <?php echo isset($fieldErrors['full_name']) ? 'is-invalid' : ''; ?>"
                                           placeholder="e.g. Ahmed Khan"
                                           value="<?php echo htmlspecialchars($formData['full_name']); ?>" required>
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
                                           placeholder="e.g. ahmed.khan"
                                           value="<?php echo htmlspecialchars($formData['username']); ?>"
                                           required autocomplete="off">
                                    <?php if (isset($fieldErrors['username'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($fieldErrors['username']); ?></div>
                                    <?php endif; ?>
                                    <div id="usernameStatus" class="username-status mt-1"></div>
                                </div>

                                <!-- Email -->
                                <div class="mb-3">
                                    <label class="form-label-styled" for="email">
                                        Email <span class="text-danger">*</span>
                                    </label>
                                    <input type="email" name="email" id="email"
                                           class="form-control form-control-sm <?php echo isset($fieldErrors['email']) ? 'is-invalid' : ''; ?>"
                                           placeholder="user@pharmacy.com"
                                           value="<?php echo htmlspecialchars($formData['email']); ?>">
                                    <?php if (isset($fieldErrors['email'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($fieldErrors['email']); ?></div>
                                    <?php endif; ?>
                                </div>

                                <!-- Phone -->
                                <div class="mb-3">
                                    <label class="form-label-styled" for="phone">Phone</label>
                                    <input type="text" name="phone" id="phone"
                                           class="form-control form-control-sm"
                                           placeholder="03XX-XXXXXXX"
                                           value="<?php echo htmlspecialchars($formData['phone']); ?>">
                                </div>

                                <!-- Role -->
                                <div class="mb-3">
                                    <label class="form-label-styled" for="role">
                                        Role <span class="text-danger">*</span>
                                    </label>
                                    <select name="role" id="role" class="form-select form-select-sm" required>
                                        <?php if (isAdmin()): ?>
                                        <option value="admin" <?php echo $formData['role']==='admin' ? 'selected' : ''; ?>>
                                            🔴 Admin
                                        </option>
                                        <?php endif; ?>
                                        <?php if (isAdmin() || isManager()): ?>
                                        <option value="manager" <?php echo $formData['role']==='manager' ? 'selected' : ''; ?>>🟠 Manager</option>
                                        <?php endif; ?>
                                        <option value="pharmacist" <?php echo $formData['role']==='pharmacist' ? 'selected' : ''; ?>>🔵 Pharmacist</option>
                                        <option value="cashier"    <?php echo $formData['role']==='cashier'    ? 'selected' : ''; ?>>🟢 Cashier</option>
                                        <option value="viewer"     <?php echo $formData['role']==='viewer'     ? 'selected' : ''; ?>>⚪ Viewer (Read-only)</option>
                                        <option value="custom"     <?php echo $formData['role']==='custom'     ? 'selected' : ''; ?>>🛠️ Custom Role</option>
                                    </select>
                                    <div class="form-text" style="font-size:.73rem">
                                        Role selection auto-fills permissions below.
                                    </div>
                                </div>

                                <!-- Status -->
                                <div class="mb-1">
                                    <label class="form-label-styled">Status</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox"
                                               name="status" id="statusToggle" value="1" checked
                                               style="cursor:pointer">
                                        <label class="form-check-label small" for="statusToggle"
                                               style="color:var(--pharmacy-text,#212529)">Active</label>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- Password Card -->
                        <div class="form-card">
                            <div class="form-card-header">
                                <i class="bi bi-key card-title-icon"></i>
                                <span class="card-title-text">Password</span>
                            </div>
                            <div class="form-card-body">

                                <div class="mb-3">
                                    <label class="form-label-styled" for="password">
                                        Password <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="password" id="password"
                                               class="form-control <?php echo isset($fieldErrors['password']) ? 'is-invalid' : ''; ?>"
                                               autocomplete="new-password" required>
                                        <button type="button" class="btn btn-outline-secondary" id="togglePass"
                                                style="transition:all 0.2s ease">
                                            <i class="bi bi-eye-fill" id="togglePassIcon"></i>
                                        </button>
                                        <?php if (isset($fieldErrors['password'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($fieldErrors['password']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex gap-1 mt-2">
                                        <div class="strength-bar flex-fill bg-secondary" id="s1"></div>
                                        <div class="strength-bar flex-fill bg-secondary" id="s2"></div>
                                        <div class="strength-bar flex-fill bg-secondary" id="s3"></div>
                                        <div class="strength-bar flex-fill bg-secondary" id="s4"></div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <small id="strengthLabel" class="text-muted"></small>
                                    </div>
                                    <div class="mt-1 d-flex gap-3" style="font-size:.78rem">
                                        <span id="cr-length" style="color:#dc3545">
                                            <i class="bi bi-circle-fill me-1" style="font-size:.45rem"></i>Min 8 chars
                                        </span>
                                        <span id="cr-number" style="color:#dc3545">
                                            <i class="bi bi-circle-fill me-1" style="font-size:.45rem"></i>1 number
                                        </span>
                                    </div>
                                </div>

                                <div class="mb-1">
                                    <label class="form-label-styled" for="confirm_password">
                                        Confirm Password <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="confirm_password" id="confirm_password"
                                               class="form-control <?php echo isset($fieldErrors['confirm_password']) ? 'is-invalid' : ''; ?>"
                                               autocomplete="new-password" required>
                                        <button type="button" class="btn btn-outline-secondary" id="toggleConfirm"
                                                style="transition:all 0.2s ease">
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
                                <p class="small mb-3" style="color:var(--pharmacy-text,#6c757d)">
                                    Used for password recovery if the user forgets their password.
                                </p>
                                <div class="mb-3">
                                    <label class="form-label-styled" for="security_question">
                                        Security Question <span class="text-danger">*</span>
                                    </label>
                                    <?php
                                    $sqClass = 'form-select form-select-sm' . (isset($fieldErrors['security_question']) ? ' is-invalid' : '');
                                    echo renderSecurityQuestionDropdown(
                                        $formData['security_question'],
                                        'security_question',
                                        $sqClass,
                                        true
                                    );
                                    ?>
                                    <?php if (isset($fieldErrors['security_question'])): ?>
                                        <div class="invalid-feedback d-block"><?php echo htmlspecialchars($fieldErrors['security_question']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label-styled" for="security_answer">
                                        Your Answer <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" name="security_answer" id="security_answer"
                                           class="form-control form-control-sm <?php echo isset($fieldErrors['security_answer']) ? 'is-invalid' : ''; ?>"
                                           placeholder="Case-sensitive answer"
                                           value="<?php echo htmlspecialchars($formData['security_answer']); ?>" required>
                                    <?php if (isset($fieldErrors['security_answer'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($fieldErrors['security_answer']); ?></div>
                                    <?php endif; ?>
                                    <div class="form-text" style="font-size:.73rem">
                                        Answer is hashed and stored securely.
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
                                    <label class="form-label-styled" for="default_page">
                                        Default Page After Login
                                    </label>
                                    <select name="default_page" id="default_page" class="form-select form-select-sm">
                                        <option value="dashboard" <?php echo $formData['default_page']==='dashboard' ? 'selected' : ''; ?>>Dashboard</option>
                                        <option value="sales/pos" <?php echo $formData['default_page']==='sales/pos' ? 'selected' : ''; ?>>POS / Sales</option>
                                        <option value="products"  <?php echo $formData['default_page']==='products'  ? 'selected' : ''; ?>>Products</option>
                                        <option value="reports"   <?php echo $formData['default_page']==='reports'   ? 'selected' : ''; ?>>Reports</option>
                                    </select>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label-styled" for="notes">
                                        Internal Notes
                                        <span class="fw-normal" style="font-size:.75rem;color:var(--pharmacy-text,#6c757d)">(admin-only)</span>
                                    </label>
                                    <textarea name="notes" id="notes" class="form-control form-control-sm"
                                              rows="3"
                                              placeholder="e.g. Part-time staff, morning shift only…"><?php echo htmlspecialchars($formData['notes']); ?></textarea>
                                </div>
                            </div>
                        </div>

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
                                        <i class="bi bi-check-all me-1"></i>Select All
                                    </button>
                                    <button type="button" id="clearAllPerms"
                                            class="btn btn-outline-secondary btn-sm"
                                            style="font-size:.75rem;padding:3px 10px">
                                        <i class="bi bi-x me-1"></i>Clear All
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
                                                           title="Select all <?php echo $action; ?>">
                                                </th>
                                                <?php endforeach; ?>
                                                <th style="font-size:.75rem">Quick</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($modules as $mod => $modLabel):
                                            $applicable = $moduleActions[$mod];
                                        ?>
                                            <tr id="perm-row-<?php echo $mod; ?>"
                                                style="transition:background 0.15s ease">
                                                <td class="fw-semibold ps-2"
                                                    style="font-size:.83rem;color:var(--pharmacy-heading,#212529)">
                                                    <?php echo htmlspecialchars($modLabel); ?>
                                                </td>
                                                <?php foreach ($permActions as $idx => $action):
                                                    $isApplicable = $applicable[$idx] ?? false;
                                                    $checked      = in_array($action, $currentDefaults[$mod] ?? [], true);
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
                                Permissions auto-fill when role changes. "—" = action not applicable.
                            </div>
                        </div>
                    </div><!-- /RIGHT COL -->

                </div><!-- /row -->

                <!-- ── Form Actions ──────────────────────────────────────── -->
                <div class="d-flex justify-content-end gap-3 mt-4 pb-4">
                    <a href="list.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </a>
                    <button type="button" class="btn btn-outline-danger" id="clearFormBtn">
                        <i class="bi bi-trash3 me-1"></i>Clear Fields
                    </button>
                    <button type="submit" data-submit-btn class="btn btn-primary px-4" id="submitBtn"
                            style="background:var(--pharmacy-primary,#0d6efd);border-color:var(--pharmacy-primary,#0d6efd)">
                        <i class="bi bi-person-plus-fill me-1"></i>Create User
                    </button>
                </div>

            </form>
</div>
</div><!-- /container-fluid -->
</div><!-- /main-content -->

<script>
$(function () {
    const roleDefaults   = <?php echo json_encode($roleDefaults); ?>;
    const moduleActions  = <?php echo json_encode($moduleActions); ?>;
    const permActionsArr = <?php echo json_encode($permActions); ?>;
    const DRAFT_KEY      = 'addUserDraft_v1';
    const isPostBack     = <?php echo ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'true' : 'false'; ?>;

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

    function updateStrengthUI(pw) {
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
    }

    $('#password').on('input', function () { updateStrengthUI($(this).val()); checkMatch(); });

    function checkMatch() {
        const pw = $('#password').val(), cpw = $('#confirm_password').val();
        if (!cpw) { $('#matchStatus').text(''); return; }
        if (pw === cpw) {
            $('#matchStatus').html('<span style="color:#198754"><i class="bi bi-check-circle-fill me-1"></i>Passwords match</span>');
        } else {
            $('#matchStatus').html('<span style="color:#dc3545"><i class="bi bi-x-circle-fill me-1"></i>Passwords do not match</span>');
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
            reader.onload = e => {
                $('#avatarPlaceholder').hide();
                $('#avatarPreview').attr('src', e.target.result).show();
                saveDraft();
            };
            reader.readAsDataURL(file);
        }
    });

    // ── Username Availability ─────────────────────────────────────────────────
    let usernameTimer = null;
    $('#username').on('blur input', function () {
        const val = $(this).val().trim();
        clearTimeout(usernameTimer);
        if (val.length < 3) {
            $('#usernameStatus').html('<span class="text-muted">Min 3 characters.</span>');
            return;
        }
        usernameTimer = setTimeout(() => {
            $('#usernameStatus').html('<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Checking…</span>');
            $.get('../ajax/check_username.php', { username: val, exclude_id: '' }, res => {
                if (res.available) {
                    $('#usernameStatus').html('<span style="color:#198754"><i class="bi bi-check-circle-fill me-1"></i>Available</span>');
                } else {
                    $('#usernameStatus').html('<span style="color:#dc3545"><i class="bi bi-x-circle-fill me-1"></i>Already taken</span>');
                }
            }, 'json').fail(() => $('#usernameStatus').html('<span class="text-warning">Could not verify.</span>'));
        }, 500);
    });

    // ── Inline Validation Helpers ─────────────────────────────────────────────
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

    // ── localStorage Draft ────────────────────────────────────────────────────
    function collectPerms() {
        const perms = {};
        $('.perm-checkbox').each(function () {
            const mod = $(this).data('module'), action = $(this).data('action');
            if (!perms[mod]) perms[mod] = {};
            perms[mod][action] = $(this).is(':checked');
        });
        return perms;
    }

    function saveDraft() {
        try {
            const draft = {
                full_name:         $('#full_name').val(),
                username:          $('#username').val(),
                email:             $('#email').val(),
                phone:             $('#phone').val(),
                role:              $('#role').val(),
                default_page:      $('#default_page').val(),
                notes:             $('#notes').val(),
                security_question: $('#security_question').val(),
                security_answer:   $('#security_answer').val(),
                password:          $('#password').val(),
                confirm_password:  $('#confirm_password').val(),
                status:            $('#statusToggle').is(':checked'),
                perms:             collectPerms(),
                avatarSrc:         $('#avatarPreview').attr('src') !== '#' ? $('#avatarPreview').attr('src') : null,
            };
            localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
        } catch(e) {}
    }

    function restoreDraft(draft) {
        if (!draft) return;
        if (draft.full_name)         $('#full_name').val(draft.full_name);
        if (draft.username)          $('#username').val(draft.username);
        if (draft.email)             $('#email').val(draft.email);
        if (draft.phone)             $('#phone').val(draft.phone);
        if (draft.role)              $('#role').val(draft.role);
        if (draft.default_page)      $('#default_page').val(draft.default_page);
        if (draft.notes)             $('#notes').val(draft.notes);
        if (draft.security_question) $('#security_question').val(draft.security_question);
        if (draft.security_answer)   $('#security_answer').val(draft.security_answer);
        if (draft.password)          { $('#password').val(draft.password); updateStrengthUI(draft.password); }
        if (draft.confirm_password)  { $('#confirm_password').val(draft.confirm_password); checkMatch(); }
        if (typeof draft.status !== 'undefined') $('#statusToggle').prop('checked', draft.status);
        if (draft.perms) {
            $('.perm-checkbox').each(function () {
                const mod = $(this).data('module'), action = $(this).data('action');
                if (draft.perms[mod] && typeof draft.perms[mod][action] !== 'undefined') {
                    $(this).prop('checked', draft.perms[mod][action]);
                }
            });
            updateColAllCheckboxes();
        }
        if (draft.avatarSrc) {
            $('#avatarPlaceholder').hide();
            $('#avatarPreview').attr('src', draft.avatarSrc).show();
        }
    }

    function clearDraft() {
        try { localStorage.removeItem(DRAFT_KEY); } catch(e) {}
    }

    // Restore draft on fresh GET only
    if (!isPostBack) {
        try {
            const saved = localStorage.getItem(DRAFT_KEY);
            if (saved) restoreDraft(JSON.parse(saved));
        } catch(e) {}
    }

    // Save draft on any input change (debounced)
    let saveTimer = null;
    $(document).on('input change', '#addUserForm input, #addUserForm select, #addUserForm textarea', function () {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveDraft, 300);
    });
    $(document).on('change', '.perm-checkbox, .perm-col-all', function () { saveDraft(); });

    // ── Clear Fields Button ───────────────────────────────────────────────────
    $('#clearFormBtn').on('click', function () {
        if (!confirm('Clear all fields? This cannot be undone.')) return;
        clearDraft();
        $('#full_name, #username, #email, #phone, #notes, #security_answer').val('');
        $('#role').val('cashier');
        $('#default_page').val('dashboard');
        $('#security_question').val('');
        $('#password, #confirm_password').val('');
        $('#statusToggle').prop('checked', true);
        $('#avatarPlaceholder').show();
        $('#avatarPreview').attr('src', '#').hide();
        $('#profilePhoto').val('');
        applyRoleDefaults('cashier');
        updateColAllCheckboxes();
        updateStrengthUI('');
        $('#matchStatus, #strengthLabel, #usernameStatus').html('');
        $('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').remove();
    });

    // ── Auto-dismiss alerts ───────────────────────────────────────────────────
    document.querySelectorAll('.alert').forEach(el => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // ── Form Submit Validation + Loading State ────────────────────────────────
    $('#addUserForm').on('submit', function (e) {
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
        if (pw === '') markError($('#password'), 'Password is required.');
        else if (pw.length < 8 || !/[0-9]/.test(pw)) markError($('#password'), 'Min 8 characters with at least 1 number.');
        else if (pw !== cpw) markError($('#confirm_password'), 'Passwords do not match.');

        if (!$('#security_question').val()) markError($('#security_question'), 'Please select a security question.');
        if ($('#security_answer').val().trim() === '') markError($('#security_answer'), 'Please provide an answer.');
        else if ($('#security_answer').val().trim().length < 2) markError($('#security_answer'), 'Answer must be at least 2 characters.');

        if ($firstError) {
            e.preventDefault();
            $firstError[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => $firstError.focus(), 400);
            return;
        }

        clearDraft();
        const $btn = $('#submitBtn');
        $btn.prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-1"></span>Creating…');
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>