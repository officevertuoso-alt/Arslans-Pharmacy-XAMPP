<?php
/**
 * Users — Activity Log
 * File: users/activity_log.php
 * Phase 20 — User Management
 * Shows per-user activity log with pagination and filters.
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

requireAdmin();
requirePermission('users', 'view');

$pageTitle = 'User Activity Log';

$userId = (int)($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    setFlash('error', '<i class="bi bi-x-circle-fill me-2"></i>Invalid user ID.');
    header('Location: list.php'); exit;
}

$targetUser = getOne('SELECT id, full_name, username, role, profile_image, status FROM users WHERE id = :id', [':id' => $userId]);
if (!$targetUser) {
    setFlash('error', '<i class="bi bi-x-circle-fill me-2"></i>User not found.');
    header('Location: list.php'); exit;
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;
$search  = sanitize($_GET['search'] ?? '');

$where  = ['al.user_id = :uid'];
$params = [':uid' => $userId];

if ($search !== '') {
    $where[]           = '(al.action LIKE :s OR al.description LIKE :s)';
    $params[':s']      = "%{$search}%";
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$totalRow   = getOne("SELECT COUNT(*) AS cnt FROM activity_logs al {$whereSQL}", $params);
$total      = (int)($totalRow['cnt'] ?? 0);
$totalPages = (int)ceil($total / $perPage);

$logs = getAll(
    "SELECT al.id, al.action, al.description, al.created_at, al.ip_address
     FROM activity_logs al
     {$whereSQL}
     ORDER BY al.created_at DESC
     LIMIT :limit OFFSET :offset",
    array_merge($params, [':limit' => $perPage, ':offset' => $offset])
);
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
        .activity-icon { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
        .log-row:hover td { background:color-mix(in srgb, var(--pharmacy-primary,#0d6efd) 4%, transparent); }
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
            <li class="breadcrumb-item"><a href="list.php">Users</a></li>
            <li class="breadcrumb-item active">Activity Log — <?php echo htmlspecialchars($targetUser['username']); ?></li>
        </ol>
    </nav>

    <!-- Flash -->
    <?php $flash = getFlash('error'); if ($flash): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo $flash; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <?php
        $avatarColors = ['#4e73df','#1cc88a','#36b9cc','#e74a3b','#f6c23e','#858796'];
        $colorIdx = abs(crc32($targetUser['username'])) % count($avatarColors);
        ?>
        <div style="width:52px;height:52px;border-radius:50%;background:<?php echo $avatarColors[$colorIdx]; ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem;font-weight:700;flex-shrink:0">
            <?php
            $imgPath = '../uploads/avatars/' . basename($targetUser['profile_image'] ?? '');
            if (!empty($targetUser['profile_image']) && file_exists($imgPath)):
            ?><img src="<?php echo htmlspecialchars($imgPath); ?>" style="width:52px;height:52px;border-radius:50%;object-fit:cover"><?php
            else: echo strtoupper(substr($targetUser['full_name'],0,1)); endif;
            ?>
        </div>
        <div>
            <h4 class="mb-0" style="color:var(--pharmacy-heading,#212529)"><?php echo htmlspecialchars($targetUser['full_name']); ?></h4>
            <div style="font-size:.84rem;color:var(--pharmacy-text,#6c757d)">
                <span style="font-family:monospace">@<?php echo htmlspecialchars($targetUser['username']); ?></span>
                &nbsp;·&nbsp;
                <span class="badge <?php echo $targetUser['role']==='admin'?'bg-danger':($targetUser['role']==='pharmacist'?'bg-primary':'bg-success'); ?>"><?php echo ucfirst($targetUser['role']); ?></span>
            </div>
        </div>
        <div class="ms-auto d-flex gap-2">
            <a href="edit.php?id=<?php echo $userId; ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-pencil-fill me-1"></i>Edit User
            </a>
            <a href="list.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Users
            </a>
        </div>
    </div>

    <!-- Filter -->
    <div class="filter-bar mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
            <div class="col-md-5">
                <label class="form-label small fw-semibold mb-1">Search Action / Description</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="e.g. LOGIN, DELETE_SALE…">
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Search</button>
                <a href="activity_log.php?user_id=<?php echo $userId; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x me-1"></i>Clear</a>
            </div>
            <div class="col-auto ms-auto">
                <span class="small" style="color:var(--pharmacy-text,#6c757d)">
                    <strong><?php echo number_format($total); ?></strong> entries
                </span>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-3" style="width:40px">#</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>IP Address</th>
                        <th>Date & Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5" style="color:var(--pharmacy-text,#6c757d)">
                            <i class="bi bi-clock-history display-6 d-block mb-2" style="opacity:.2"></i>
                            <div class="fw-semibold">No activity records found</div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php
                    $actionIcons = [
                        'LOGIN'           => ['bi-box-arrow-in-right', '#198754'],
                        'LOGOUT'          => ['bi-box-arrow-right',    '#6c757d'],
                        'CREATE'          => ['bi-plus-circle-fill',   '#0d6efd'],
                        'UPDATE'          => ['bi-pencil-fill',        '#f6c23e'],
                        'DELETE'          => ['bi-trash-fill',         '#dc3545'],
                        'FORCE_LOGOUT'    => ['bi-person-x-fill',      '#fd7e14'],
                        'UPLOAD'          => ['bi-upload',             '#0dcaf0'],
                        'TOGGLE'          => ['bi-toggle-on',          '#6f42c1'],
                    ];
                    $rowNum = $offset + 1;
                    foreach ($logs as $log):
                        $actionKey = strtok($log['action'], '_');
                        $iconData  = $actionIcons[$actionKey] ?? ['bi-activity', '#6c757d'];
                        // Also check full action
                        foreach ($actionIcons as $k => $v) {
                            if (str_starts_with($log['action'], $k)) { $iconData = $v; break; }
                        }
                    ?>
                    <tr class="log-row">
                        <td class="ps-3 small text-muted"><?php echo $rowNum++; ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="activity-icon" style="background:color-mix(in srgb, <?php echo $iconData[1]; ?> 12%, transparent)">
                                    <i class="bi <?php echo $iconData[0]; ?>" style="color:<?php echo $iconData[1]; ?>"></i>
                                </div>
                                <span style="font-family:monospace;font-size:.8rem;font-weight:600;color:var(--pharmacy-heading,#212529)">
                                    <?php echo htmlspecialchars($log['action']); ?>
                                </span>
                            </div>
                        </td>
                        <td style="font-size:.83rem;max-width:400px">
                            <?php echo htmlspecialchars($log['description'] ?? '—'); ?>
                        </td>
                        <td style="font-family:monospace;font-size:.8rem;color:var(--pharmacy-text,#6c757d)">
                            <?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?>
                        </td>
                        <td style="font-size:.82rem;color:var(--pharmacy-text,#6c757d)">
                            <div><?php echo date('d M Y', strtotime($log['created_at'])); ?></div>
                            <div style="font-size:.72rem"><?php echo date('h:i:s A', strtotime($log['created_at'])); ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="p-3 border-top" style="border-color:var(--pharmacy-border,#dee2e6)!important">
            <nav>
                <ul class="pagination pagination-sm mb-0 justify-content-center">
                    <li class="page-item <?php echo $page<=1?'disabled':''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['page'=>$page-1])); ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php for ($i=1;$i<=$totalPages;$i++): ?>
                    <li class="page-item <?php echo $i===$page?'active':''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['page'=>$i])); ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page>=$totalPages?'disabled':''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['page'=>$page+1])); ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div><!-- /table-card -->

</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
