<?php
/**
 * Users Management — List
 * File: users/list.php
 * Version: V5.0 — Step 15 UI/UX Overhaul
 *
 * CHANGES V5.0:
 *  - Full design system integration (CSS vars, table-card, filter-bar, stat cards)
 *  - Avatar circles with username-hash color (fully preserved logic)
 *  - Role badges styled with design system
 *  - Status toggle AJAX (no page reload) — fully preserved
 *  - AJAX delete with row fade-out — fully preserved
 *  - Correct breadcrumb: Dashboard > Users > User List
 *  - Smooth transitions on all interactive elements
 *  - Auto-dismiss flash alerts after 5s
 *  - AJAX search with debounce
 *  - Page fade-in animation
 *  - Permission badge legend preserved
 *  - Pagination styled consistently
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

requireAdmin();
requirePermission('users', 'view');

$pageTitle = 'Users Management';

// ── Filters ──────────────────────────────────────────────────────────────────
$search       = sanitize($_GET['search']  ?? '');
$roleFilter   = sanitize($_GET['role']    ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($search !== '') {
    $where[]           = '(u.full_name LIKE :search OR u.username LIKE :search OR u.email LIKE :search)';
    $params[':search'] = "%{$search}%";
}
if ($roleFilter !== '') {
    $where[]         = 'u.role = :role';
    $params[':role'] = $roleFilter;
}
if ($statusFilter !== '') {
    $where[]           = 'u.status = :status';
    $params[':status'] = (int)$statusFilter;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Summary counts ────────────────────────────────────────────────────────────
$summaryRow  = getOne(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS inactive,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins
     FROM users"
);
$totalAll      = (int)($summaryRow['total']    ?? 0);
$totalActive   = (int)($summaryRow['active']   ?? 0);
$totalInactive = (int)($summaryRow['inactive'] ?? 0);
$totalAdmins   = (int)($summaryRow['admins']   ?? 0);

// ── Count filtered ────────────────────────────────────────────────────────────
$totalRow   = getOne("SELECT COUNT(*) AS cnt FROM users u {$whereSQL}", $params);
$total      = (int)($totalRow['cnt'] ?? 0);
$totalPages = (int)ceil($total / $perPage);

// ── Fetch users ───────────────────────────────────────────────────────────────
$paginatedParams = array_merge($params, [
    ':limit'  => $perPage,
    ':offset' => $offset,
]);

$users = getAll(
    "SELECT u.id, u.full_name, u.username, u.email, u.phone,
            u.role, u.status, u.last_login, u.created_at,
            u.profile_image, u.login_count
     FROM   users u
     {$whereSQL}
     ORDER  BY u.id DESC
     LIMIT  :limit OFFSET :offset",
    $paginatedParams
);

// ── Fetch permissions for each user (batched) ─────────────────────────────────
$userIds = array_column($users, 'id');
$allPerms = [];

if (!empty($userIds)) {
    $placeholders = implode(',', array_map(fn($id) => (int)$id, $userIds));
    $permRows = getAll(
        "SELECT user_id, module,
                can_view, can_add, can_edit, can_delete,
                can_print, can_export, can_import, can_void
         FROM   user_permissions
         WHERE  user_id IN ({$placeholders})",
        []
    );
    foreach ($permRows as $pr) {
        if (!isset($allPerms[$pr['user_id']])) {
            $allPerms[$pr['user_id']] = [];
        }
        $allPerms[$pr['user_id']][$pr['module']] = $pr;
    }
}

// Helper: aggregate a user's permissions into summary flags
function aggregatePermissions(array $userPerms): array {
    $flags = [
        'view' => false, 'add' => false, 'edit' => false, 'delete' => false,
        'print' => false, 'export' => false, 'import' => false, 'void' => false,
    ];
    foreach ($userPerms as $perm) {
        if ($perm['can_view'])   $flags['view']   = true;
        if ($perm['can_add'])    $flags['add']    = true;
        if ($perm['can_edit'])   $flags['edit']   = true;
        if ($perm['can_delete']) $flags['delete'] = true;
        if ($perm['can_print'])  $flags['print']  = true;
        if ($perm['can_export']) $flags['export'] = true;
        if ($perm['can_import']) $flags['import'] = true;
        if ($perm['can_void'])   $flags['void']   = true;
    }
    return $flags;
}

$csrfToken = generateCSRFToken();
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

        /* ── Stat cards ───────────────────────────────────── */
        .stat-card {
            border-radius: var(--card-border-radius, 12px);
            border-left: 4px solid var(--pharmacy-primary, #0d6efd);
            background: var(--pharmacy-card-bg, #fff);
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            padding: 18px 20px;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 28px rgba(0,0,0,0.13);
        }
        .stat-card .stat-value {
            font-size: 1.7rem;
            font-weight: 700;
            color: var(--pharmacy-heading, #212529);
            line-height: 1;
        }
        .stat-card .stat-label {
            font-size: 0.72rem;
            color: color-mix(in srgb, var(--pharmacy-text, #212529) 60%, transparent);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .stat-card .stat-icon {
            font-size: 1.8rem;
            opacity: 0.12;
        }
        .stat-card.border-primary  { border-left-color: var(--pharmacy-primary, #0d6efd); }
        .stat-card.border-success  { border-left-color: #198754; }
        .stat-card.border-secondary{ border-left-color: #6c757d; }
        .stat-card.border-danger   { border-left-color: #dc3545; }

        /* ── Filter bar ───────────────────────────────────── */
        .filter-bar {
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #dee2e6);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 16px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.06);
        }

        /* ── Table card ───────────────────────────────────── */
        .table-card {
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--pharmacy-border, #dee2e6);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            background: var(--pharmacy-card-bg, #fff);
        }
        .table-card .table th {
            background: var(--pharmacy-table-th, #f8f9fa) !important;
            color: var(--pharmacy-heading, #212529) !important;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 700;
            padding: 11px 14px;
            border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important;
            white-space: nowrap;
        }
        .table-card .table td {
            padding: 10px 14px;
            vertical-align: middle;
            color: var(--pharmacy-text, #212529) !important;
            border-color: var(--pharmacy-border, #dee2e6) !important;
            transition: background 0.15s ease;
        }
        .table-card .table-hover tbody tr:hover td {
            background: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 5%, var(--pharmacy-card-bg, #fff)) !important;
        }
        .table-card .table tbody tr:not(:last-child) td {
            border-bottom: 1px solid var(--pharmacy-border, #dee2e6) !important;
        }

        /* ── Role badges ──────────────────────────────────── */
        .role-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            display: inline-block;
        }
        .role-admin      { background: #fde8e8; color: #991b1b; }
        .role-pharmacist { background: #dbeafe; color: #1e40af; }
        .role-cashier    { background: #dcfce7; color: #166534; }
        .role-viewer     { background: #f3f4f6; color: #4b5563; }

        /* ── Status badge ─────────────────────────────────── */
        .status-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            display: inline-block;
        }
        .badge-active   { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #f3f4f6; color: #6b7280; }

        /* ── Permission mini badges ───────────────────────── */
        .perm-badges { display: flex; gap: 2px; flex-wrap: nowrap; }
        .perm-badge {
            display: inline-block; width: 18px; height: 18px;
            border-radius: 3px; font-size: 10px; font-weight: 700;
            line-height: 18px; text-align: center; cursor: help;
            transition: transform 0.15s ease;
        }
        .perm-badge:hover { transform: scale(1.2); }
        .perm-badge.has  { background: var(--pharmacy-primary, #0d6efd); color: #fff; }
        .perm-badge.none { background: var(--pharmacy-border, #e9ecef); color: #adb5bd; }

        /* ── Avatar circle ────────────────────────────────── */
        .avatar-circle {
            width: 36px; height: 36px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: .82rem; color: #fff; flex-shrink: 0;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            transition: transform 0.2s ease;
        }
        .avatar-circle:hover { transform: scale(1.08); }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }

        /* ── Name link ────────────────────────────────────── */
        .name-link {
            color: var(--pharmacy-heading, #212529) !important;
            font-weight: 600;
            text-decoration: none !important;
            transition: color 0.15s ease;
        }
        .name-link:hover { color: var(--pharmacy-primary, #0d6efd) !important; }

        /* ── Inactive row ─────────────────────────────────── */
        .user-row-inactive { opacity: .50; }

        /* ── Action buttons ───────────────────────────────── */
        .action-btn {
            width: 30px; height: 30px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 6px; font-size: .8rem;
            transition: all 0.15s ease;
        }
        .action-btn:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.15); }

        /* ── Summary stat ─────────────────────────────────── */
        .summary-stat .num {
            font-size: 1.3rem; font-weight: 700; line-height: 1;
            color: var(--pharmacy-heading, #212529);
        }
        .summary-stat .lbl { font-size: .75rem; color: color-mix(in srgb, var(--pharmacy-text, #6c757d) 65%, transparent); }

        /* ── Card header ──────────────────────────────────── */
        .card-header-styled {
            background: var(--pharmacy-table-th, #f8f9fa) !important;
            border-bottom: 2px solid var(--pharmacy-border, #dee2e6) !important;
            color: var(--pharmacy-heading, #212529) !important;
        }

        /* ── Pagination ───────────────────────────────────── */
        .page-link {
            color: var(--pharmacy-primary, #0d6efd);
            border-color: var(--pharmacy-border, #dee2e6);
            transition: all 0.15s ease;
        }
        .page-item.active .page-link {
            background-color: var(--pharmacy-primary, #0d6efd);
            border-color: var(--pharmacy-primary, #0d6efd);
        }
        .page-link:hover {
            background-color: color-mix(in srgb, var(--pharmacy-primary, #0d6efd) 10%, #fff);
            color: var(--pharmacy-primary, #0d6efd);
        }/* modal animation — see css/style.css */

        /* ── Toast ────────────────────────────────────────── */
        #actionToast { min-width: 280px; }

        /* ── Search loading spinner ───────────────────────── */
        #searchSpinner { display: none; }
        #tableWrapper { transition: opacity 0.2s ease; }
        #tableWrapper.loading { opacity: 0.5; pointer-events: none; }
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
                        <i class="bi bi-people-fill me-2" style="color:var(--pharmacy-primary,#0d6efd)"></i>
                        Users Management
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
                                User List
                            </li>
                        </ol>
                    </nav>
                </div>
                <?php if (canDo('users', 'add')): ?>
                <a href="add.php" class="btn btn-primary btn-sm px-3"
                   data-requires-perm="users.add"
                   style="background:var(--pharmacy-primary,#0d6efd);border-color:var(--pharmacy-primary,#0d6efd);transition:all 0.2s ease">
                    <i class="bi bi-person-plus-fill me-1"></i>Add New User
                </a>
                <?php endif; ?>
            </div>

            <!-- ── Flash Messages (auto-dismiss) ──────────────────────── -->
            <div id="flashContainer">
                <?php echo renderFlashAll(); ?>
            </div>

            <!-- ── Stat Cards ──────────────────────────────────────────── -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card border-primary">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-value text-primary"><?php echo $totalAll; ?></div>
                                <div class="stat-label">Total Users</div>
                            </div>
                            <i class="bi bi-people stat-icon text-primary"></i>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card border-success">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-value" style="color:#198754"><?php echo $totalActive; ?></div>
                                <div class="stat-label">Active</div>
                            </div>
                            <i class="bi bi-person-check stat-icon" style="color:#198754"></i>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card border-secondary">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-value" style="color:#6c757d"><?php echo $totalInactive; ?></div>
                                <div class="stat-label">Inactive</div>
                            </div>
                            <i class="bi bi-person-dash stat-icon" style="color:#6c757d"></i>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card border-danger">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-value" style="color:#dc3545"><?php echo $totalAdmins; ?></div>
                                <div class="stat-label">Admins</div>
                            </div>
                            <i class="bi bi-shield-fill stat-icon" style="color:#dc3545"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Filter Bar ──────────────────────────────────────────── -->
            <div class="filter-bar mb-4">
                <form method="GET" id="filterForm" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold mb-1"
                               style="color:var(--pharmacy-text,#212529)">
                            <i class="bi bi-search me-1"></i>Search
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="text" name="search" id="searchInput"
                                   class="form-control"
                                   placeholder="Name, username or email…"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   autocomplete="off">
                            <span class="input-group-text" id="searchSpinner">
                                <span class="spinner-border spinner-border-sm"></span>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold mb-1"
                               style="color:var(--pharmacy-text,#212529)">Role</label>
                        <select name="role" id="roleFilter" class="form-select form-select-sm">
                            <option value="">All Roles</option>
                            <option value="admin"      <?php echo $roleFilter==='admin'      ? 'selected':''; ?>>Admin</option>
                            <option value="pharmacist" <?php echo $roleFilter==='pharmacist' ? 'selected':''; ?>>Pharmacist</option>
                            <option value="cashier"    <?php echo $roleFilter==='cashier'    ? 'selected':''; ?>>Cashier</option>
                            <option value="viewer"     <?php echo $roleFilter==='viewer'     ? 'selected':''; ?>>Viewer</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold mb-1"
                               style="color:var(--pharmacy-text,#212529)">Status</label>
                        <select name="status" id="statusFilter" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="1" <?php echo $statusFilter==='1' ? 'selected':''; ?>>Active</option>
                            <option value="0" <?php echo $statusFilter==='0' ? 'selected':''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary btn-sm px-3"
                                style="transition:all 0.2s ease">
                            <i class="bi bi-funnel-fill me-1"></i>Filter
                        </button>
                        <a href="list.php" class="btn btn-outline-secondary btn-sm"
                           style="transition:all 0.2s ease">
                            <i class="bi bi-x-circle me-1"></i>Clear
                        </a>
                        <span class="ms-auto small" style="color:var(--pharmacy-text,#6c757d)">
                            <strong><?php echo number_format($total); ?></strong>
                            user<?php echo $total !== 1 ? 's' : ''; ?> found
                        </span>
                    </div>
                </form>
            </div>

            <!-- ── Permission Badge Legend ─────────────────────────────── -->
            <div class="mb-2 d-flex align-items-center flex-wrap gap-2"
                 style="font-size:.78rem;color:var(--pharmacy-text,#6c757d)">
                <span><i class="bi bi-info-circle me-1"></i>Permission badges:</span>
                <?php
                $permLabels = ['V'=>'View','A'=>'Add','E'=>'Edit','D'=>'Delete','P'=>'Print','X'=>'Export','I'=>'Import','O'=>'Void'];
                foreach ($permLabels as $letter => $label):
                ?>
                <span class="d-inline-flex align-items-center gap-1">
                    <span class="perm-badge has"><?php echo $letter; ?></span>
                    <span><?php echo $label; ?></span>
                </span>
                <?php endforeach; ?>
                <span class="d-inline-flex align-items-center gap-1">
                    <span class="perm-badge none">V</span>
                    <span>= No permission</span>
                </span>
            </div>

            <!-- ── Users Table ──────────────────────────────────────────── -->
            <div id="tableWrapper">
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="usersTable">
                            <thead>
                                <tr>
                                    <th class="ps-3" style="width:44px">#</th>
                                    <th>User</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Permissions</th>
                                    <th>Last Login</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center pe-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5"
                                        style="color:var(--pharmacy-text,#6c757d)">
                                        <i class="bi bi-people display-6 d-block mb-2" style="opacity:.2"></i>
                                        <div class="fw-semibold mb-1">No users found</div>
                                        <?php if ($search || $roleFilter || $statusFilter): ?>
                                            <a href="list.php" class="btn btn-outline-primary btn-sm mt-1">
                                                <i class="bi bi-x-circle me-1"></i>Clear filters
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php
                                $avatarColors = ['#4e73df','#1cc88a','#36b9cc','#e74a3b','#f6c23e','#858796','#6f42c1','#fd7e14'];
                                $rowNum = $offset + 1;
                                foreach ($users as $user):
                                    $initials    = strtoupper(substr($user['full_name'], 0, 1));
                                    $colorIdx    = crc32($user['username']) % count($avatarColors);
                                    $avatarColor = $avatarColors[abs($colorIdx)];
                                    $isOwnRow    = ($user['id'] === (int)$_SESSION['user_id']);
                                    $isProtRow   = !empty($user['is_protected']) || $user['id'] === 1;

                                    // Permissions
                                    $isAdminUser = ($user['role'] === 'admin');
                                    $userPerms   = $allPerms[$user['id']] ?? [];
                                    $flags       = $isAdminUser
                                        ? array_fill_keys(['view','add','edit','delete','print','export','import','void'], true)
                                        : aggregatePermissions($userPerms);

                                    $permDef = [
                                        'V' => ['view',   'View'],
                                        'A' => ['add',    'Add'],
                                        'E' => ['edit',   'Edit'],
                                        'D' => ['delete', 'Delete'],
                                        'P' => ['print',  'Print'],
                                        'X' => ['export', 'Export'],
                                        'I' => ['import', 'Import'],
                                        'O' => ['void',   'Void'],
                                    ];

                                    $tooltipLines = [];
                                    foreach ($permDef as $letter => [$key, $label]) {
                                        $tooltipLines[] = $label . ': ' . ($flags[$key] ? '✓' : '✗');
                                    }
                                    $tooltip = implode(' | ', $tooltipLines);

                                    $roleBadgeClass = match ($user['role']) {
                                        'admin'      => 'role-admin',
                                        'pharmacist' => 'role-pharmacist',
                                        'cashier'    => 'role-cashier',
                                        default      => 'role-viewer',
                                    };
                                    $roleIcon = match ($user['role']) {
                                        'admin'      => '🔴',
                                        'pharmacist' => '🔵',
                                        'cashier'    => '🟢',
                                        default      => '⚪',
                                    };
                                    $roleLabel = match ($user['role']) {
                                        'admin'      => 'Admin',
                                        'pharmacist' => 'Pharmacist',
                                        'cashier'    => 'Cashier',
                                        default      => 'Viewer',
                                    };
                                ?>
                                <tr id="user-row-<?php echo $user['id']; ?>"
                                    class="<?php echo $user['status'] ? '' : 'user-row-inactive'; ?>"
                                    style="transition:all 0.2s ease">

                                    <!-- Row # -->
                                    <td class="ps-3 small" style="color:var(--pharmacy-text,#6c757d)">
                                        <?php echo $rowNum++; ?>
                                    </td>

                                    <!-- Avatar + Name + Email -->
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-circle" style="background:<?php echo $avatarColor; ?>">
                                                <?php
                                                $photoShown = false;
                                                $candidateFiles = array_filter([
                                                    $user['profile_image'] ?? '',
                                                    $user['profile_image'] ?? '',
                                                ]);
                                                foreach ($candidateFiles as $fname) {
                                                    if ($photoShown) break;
                                                    foreach (['../uploads/profiles/', '../uploads/avatars/'] as $dir) {
                                                        $path = $dir . basename($fname);
                                                        if (file_exists($path)) {
                                                            echo '<img src="' . htmlspecialchars($path) . '?v=' . filemtime($path) . '" alt="">';
                                                            $photoShown = true;
                                                            break;
                                                        }
                                                    }
                                                }
                                                if (!$photoShown) echo $initials;
                                                ?>
                                            </div>
                                            <div>
                                                <div style="font-size:.9rem">
                                                    <a href="profile.php?id=<?php echo $user['id']; ?>"
                                                       class="name-link" title="View Profile">
                                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                                    </a>
                                                    <?php if ($isOwnRow): ?>
                                                    <span class="badge ms-1"
                                                          style="background:var(--pharmacy-primary,#0d6efd);font-size:.62rem;padding:2px 6px;border-radius:10px">
                                                        You
                                                    </span>
                                                    <?php endif; ?>
                                                    <?php if ($isProtRow): ?>
                                                    <span class="badge ms-1"
                                                          style="background:#dc354522;color:#dc3545;border:1px solid #dc354544;font-size:.62rem;padding:2px 6px;border-radius:10px"
                                                          title="Protected system account — cannot be deleted or deactivated">
                                                        <i class="bi bi-shield-fill me-1"></i>Protected
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="font-size:.78rem;color:var(--pharmacy-text,#6c757d)">
                                                    <?php echo htmlspecialchars($user['email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Username -->
                                    <td style="font-size:.83rem;color:var(--pharmacy-text,#6c757d)">
                                        <span style="font-family:monospace">@<?php echo htmlspecialchars($user['username']); ?></span>
                                        <?php if ($user['login_count']): ?>
                                        <div style="font-size:.72rem">
                                            <i class="bi bi-arrow-repeat"></i>
                                            <?php echo number_format($user['login_count']); ?> logins
                                        </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Role -->
                                    <td>
                                        <span class="role-badge <?php echo $roleBadgeClass; ?>">
                                            <?php echo $roleIcon; ?> <?php echo $roleLabel; ?>
                                        </span>
                                    </td>

                                    <!-- Permissions Badges -->
                                    <td>
                                        <?php if ($isAdminUser): ?>
                                        <span class="role-badge role-admin">
                                            <i class="bi bi-shield-fill me-1"></i>Full Access
                                        </span>
                                        <?php else: ?>
                                        <div class="perm-badges"
                                             data-bs-toggle="tooltip"
                                             data-bs-placement="top"
                                             title="<?php echo htmlspecialchars($tooltip); ?>">
                                            <?php foreach ($permDef as $letter => [$key, $label]): ?>
                                            <span class="perm-badge <?php echo $flags[$key] ? 'has' : 'none'; ?>"
                                                  title="<?php echo $label; ?>: <?php echo $flags[$key] ? 'Yes' : 'No'; ?>">
                                                <?php echo $letter; ?>
                                            </span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Last Login -->
                                    <td style="font-size:.82rem;color:var(--pharmacy-text,#6c757d)">
                                        <?php if ($user['last_login']): ?>
                                            <div><?php echo date('d M Y', strtotime($user['last_login'])); ?></div>
                                            <div style="font-size:.72rem">
                                                <?php echo date('h:i A', strtotime($user['last_login'])); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-style:italic;opacity:.6">Never</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status Toggle -->
                                    <td class="text-center">
                                        <div class="form-check form-switch d-flex justify-content-center mb-0">
                                            <input type="checkbox"
                                                   class="form-check-input status-toggle"
                                                   data-requires-perm="users.edit"
                                                   style="cursor:pointer;transition:all 0.2s ease"
                                                   data-user-id="<?php echo $user['id']; ?>"
                                                   data-csrf="<?php echo $csrfToken; ?>"
                                                   <?php echo $user['status'] ? 'checked' : ''; ?>
                                                   <?php echo $isOwnRow ? 'disabled title="Cannot deactivate own account"' : ''; ?>
                                                   <?php echo $isProtRow ? 'disabled title="Protected system account"' : ''; ?>
                                                   <?php echo !canDo('users','edit') ? 'disabled' : ''; ?>>
                                        </div>
                                    </td>

                                    <!-- Actions -->
                                    <td class="text-center pe-3" style="white-space:nowrap">
                                        <div class="d-inline-flex gap-1">
                                            <?php if (canDo('users','edit')): ?>
                                            <a href="edit.php?id=<?php echo $user['id']; ?>"
                                               class="action-btn btn btn-outline-primary"
                                               data-requires-perm="users.edit"
                                               data-bs-toggle="tooltip" title="Edit User">
                                                <i class="bi bi-pencil-fill"></i>
                                            </a>
                                            <?php endif; ?>

                                            <a href="activity_log.php?user_id=<?php echo $user['id']; ?>"
                                               class="action-btn btn btn-outline-secondary"
                                               data-bs-toggle="tooltip" title="View Activity Log">
                                                <i class="bi bi-activity"></i>
                                            </a>

                                            <?php if (canDo('users','delete') && !$isOwnRow && !$isProtRow): ?>
                                            <button type="button"
                                                    class="action-btn btn btn-outline-danger btn-delete"
                                                    data-requires-perm="users.delete"
                                                    data-user-id="<?php echo $user['id']; ?>"
                                                    data-user-name="<?php echo htmlspecialchars($user['full_name']); ?>"
                                                    data-csrf="<?php echo $csrfToken; ?>"
                                                    data-bs-toggle="tooltip" title="Delete User">
                                                <i class="bi bi-trash-fill"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- ── Pagination ──────────────────────────────────── -->
                    <?php if ($totalPages > 1): ?>
                    <div class="p-3 border-top" style="border-color:var(--pharmacy-border,#dee2e6)!important">
                        <nav>
                            <ul class="pagination pagination-sm mb-0 justify-content-center">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($totalPages, $page + 2);
                                if ($start > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                                    </li>
                                    <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                                <?php endif; ?>
                                <?php for ($i = $start; $i <= $end; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                <?php if ($end < $totalPages): ?>
                                    <?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>">
                                            <?php echo $totalPages; ?>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                       href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>

                </div><!-- /table-card -->
            </div><!-- /tableWrapper -->

</div>
</div><!-- /container-fluid -->
</div><!-- /main-content -->

<!-- ── Delete Confirm Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white"
                 style="background:#dc3545;border-radius:12px 12px 0 0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <p class="mb-1" style="color:var(--pharmacy-text,#212529)">
                    Are you sure you want to delete:
                </p>
                <p class="fw-bold fs-5 mb-3" id="deleteUserName" style="color:var(--pharmacy-heading,#212529)"></p>
                <div class="alert alert-danger py-2 mb-0" style="font-size:.83rem">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    This action permanently removes the user. Associated records are preserved.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">
                    Cancel
                </button>
                <button type="button" class="btn btn-danger btn-sm px-3" id="confirmDeleteBtn"
                        style="transition:all 0.2s ease">
                    <i class="bi bi-trash-fill me-1"></i>Yes, Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Toast ─────────────────────────────────────────────────────────────── -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999">
    <div id="actionToast" class="toast align-items-center text-white border-0 shadow">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>



<script>
$(function () {

    // ── Bootstrap Tooltips ────────────────────────────────────────────────────
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el, { trigger: 'hover' });
    });

    const deleteModal = new bootstrap.Modal('#deleteModal');
    const toast       = new bootstrap.Toast('#actionToast', { delay: 3500 });

    function showToast(message, type = 'success') {
        const $toast = $('#actionToast');
        $toast.removeClass('bg-success bg-danger bg-warning bg-info').addClass('bg-' + type);
        $('#toastMsg').text(message);
        toast.show();
    }

    // ── Auto-dismiss flash alerts ─────────────────────────────────────────────
    document.querySelectorAll('#flashContainer .alert').forEach(el => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // ── AJAX Search (debounced) ───────────────────────────────────────────────
    let searchTimer = null;
    $('#searchInput').on('input', function () {
        clearTimeout(searchTimer);
        const val = $(this).val();
        $('#searchSpinner').show();
        searchTimer = setTimeout(() => {
            const url = new URL(window.location.href);
            url.searchParams.set('search', val);
            url.searchParams.set('page', '1');
            if ($('#roleFilter').val())   url.searchParams.set('role',   $('#roleFilter').val());
            if ($('#statusFilter').val()) url.searchParams.set('status', $('#statusFilter').val());

            $('#tableWrapper').addClass('loading');

            fetch(url.toString(), { credentials: 'same-origin' })
                .then(r => r.text())
                .then(html => {
                    const doc    = new DOMParser().parseFromString(html, 'text/html');
                    const newTbl = doc.getElementById('tableWrapper');
                    if (newTbl) {
                        document.getElementById('tableWrapper').innerHTML = newTbl.innerHTML;
                        // Re-init tooltips on new rows
                        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                            new bootstrap.Tooltip(el, { trigger: 'hover' });
                        });
                    }
                })
                .catch(() => {})
                .finally(() => {
                    $('#searchSpinner').hide();
                    $('#tableWrapper').removeClass('loading');
                });
        }, 420);
    });

    // Also auto-submit on role/status filter change
    $('#roleFilter, #statusFilter').on('change', function () {
        document.getElementById('filterForm').submit();
    });

    // ── Status Toggle (AJAX, no page reload) ─────────────────────────────────
    $(document).on('change', '.status-toggle', function () {
        const $toggle   = $(this);
        const userId    = $toggle.data('user-id');
        const newStatus = $toggle.is(':checked') ? 1 : 0;
        const csrf      = $toggle.data('csrf');

        $toggle.prop('disabled', true);

        $.ajax({
            url:      '../ajax/toggle_user_status.php',
            type:     'POST',
            data:     { user_id: userId, status: newStatus, csrf_token: csrf },
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    const $row = $('#user-row-' + userId);
                    if (newStatus === 0) $row.addClass('user-row-inactive');
                    else                 $row.removeClass('user-row-inactive');
                    showToast(res.message, 'success');
                } else {
                    $toggle.prop('checked', !$toggle.is(':checked'));
                    showToast(res.message || 'Status update failed.', 'danger');
                }
            },
            error: function () {
                $toggle.prop('checked', !$toggle.is(':checked'));
                showToast('Server error. Please try again.', 'danger');
            },
            complete: function () { $toggle.prop('disabled', false); }
        });
    });

    // ── Delete Modal ──────────────────────────────────────────────────────────
    let deleteUserId = null, deleteCsrf = null;

    $(document).on('click', '.btn-delete', function () {
        deleteUserId = $(this).data('user-id');
        deleteCsrf   = $(this).data('csrf');
        $('#deleteUserName').text($(this).data('user-name'));
        deleteModal.show();
    });

    $('#confirmDeleteBtn').on('click', function () {
        if (!deleteUserId) return;
        const $btn = $(this);
        $btn.prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-1"></span>Deleting…');

        $.ajax({
            url:      'delete.php',
            type:     'POST',
            data:     { user_id: deleteUserId, csrf_token: deleteCsrf },
            dataType: 'json',
            success: function (res) {
                deleteModal.hide();
                if (res.success) {
                    $('#user-row-' + deleteUserId).fadeOut(400, function () { $(this).remove(); });
                    showToast(res.message, 'success');
                } else {
                    showToast(res.message || 'Delete failed.', 'danger');
                }
            },
            error: function () {
                deleteModal.hide();
                showToast('Server error. Please try again.', 'danger');
            },
            complete: function () {
                $btn.prop('disabled', false)
                    .html('<i class="bi bi-trash-fill me-1"></i>Yes, Delete');
            }
        });
    });

});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    UniversalTable.init('usersTable', {
        title: 'Users List',
        pageSize: 0,
        searchable: true,
        sortable: true,
        exportExcel: true,
        exportPdf: true,
        printable: true,
        excludeCols: [0, 6]
    });
});
</script>
</body>
</html>