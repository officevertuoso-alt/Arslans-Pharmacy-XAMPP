<?php
require_once '../config/config.php';

requireAdmin();

$page_title = 'Activity Logs - Pharmacy Management System';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

$db = db();
$db->query("SELECT 
                al.*,
                u.username,
                u.full_name
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT $offset, $perPage");
$logs = $db->resultSet();

// Get total count
$db->query("SELECT COUNT(*) as total FROM activity_logs");
$total = $db->single()['total'];
$totalPages = ceil($total / $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .main-content { margin-left: var(--sb-w, 260px); transition: margin-left .25s ease; padding: 20px; }
        .card-custom { background: white; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1">Activity Logs</h4>
                <p class="text-muted mb-0">System activity history</p>
            </div>
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print me-2"></i> Print
            </button>
        </div>
        
        <div class="card-custom">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="activityLogTable">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><small><?php echo formatDate($log['created_at'], 'd/m/Y H:i:s'); ?></small></td>
                                <td>
                                    <?php if ($log['full_name']): ?>
                                        <strong><?php echo htmlspecialchars($log['full_name']); ?></strong>
                                        <br><small class="text-muted">@<?php echo htmlspecialchars($log['username']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">System</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($log['action']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($log['description']); ?></td>
                                <td><small><?php echo htmlspecialchars($log['ip_address']); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (count($logs) == 0): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">No activity logs found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-between align-items-center p-3 border-top">
                    <div class="text-muted small">Showing <?php echo count($logs); ?> of <?php echo $total; ?> logs</div>
                    <nav>
                        <ul class="pagination mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page-1; ?>">Previous</a>
                            </li>
                            <?php for($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page+1; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    UniversalTable.init('activityLogTable', {
        title   : 'Activity Log',
        pageSize: 25
    });
});
</script>
</body>
</html>
