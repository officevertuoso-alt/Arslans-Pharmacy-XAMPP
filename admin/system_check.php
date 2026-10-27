<?php
/**
 * System Health Check & Maintenance Tool
 * Only accessible by admin
 */

require_once '../config/config.php';
require_once '../includes/auth.php';

requireAdmin();

$page_title = 'System Health Check - Pharmacy Management System';
$fix_message = '';
$fix_error = '';

// Auto-fix actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_action'])) {
    $fix_action = $_POST['fix_action'];
    $fix_result = false;
    
    switch ($fix_action) {
        case 'invoice_sequences':
            $fix_result = fixInvoiceSequences();
            break;
        case 'admin_permissions':
            $fix_result = fixAdminPermissions();
            break;
        case 'expired_batches':
            $fix_result = fixExpiredBatches();
            break;
        case 'backup_htaccess':
            $fix_result = fixBackupHtaccess();
            break;
        case 'stock_quantity_zero':
            $fix_result = fixStockQuantityZero();
            break;
    }
    
    if ($fix_result) {
        $fix_message = "Fix applied successfully: " . htmlspecialchars($fix_action);
        // Re-run checks after fix (redirect to refresh)
        header("Location: system_check.php?fixed=1");
        exit;
    } else {
        $fix_error = "Failed to apply fix: " . htmlspecialchars($fix_action);
    }
}

// Helper fix functions
function fixInvoiceSequences() {
    $db = db();
    $year = date('Y');
    $month = date('m');
    
    // Check max invoice number from sales
    $db->query("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_no, '-', -1) AS UNSIGNED)) as max_inv 
                FROM sales WHERE invoice_no LIKE 'INV-$year$month-%'");
    $max_inv = (int)$db->single()['max_inv'];
    
    // Insert or update sequence
    $db->query("INSERT INTO invoice_sequences (prefix, year, month, last_number) 
                VALUES ('INV', :year, :month, :last)
                ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, :last)");
    $db->bind(':year', $year);
    $db->bind(':month', $month);
    $db->bind(':last', $max_inv);
    $db->execute();
    
    // Same for PUR
    $db->query("SELECT MAX(CAST(SUBSTRING_INDEX(purchase_invoice_no, '-', -1) AS UNSIGNED)) as max_pur 
                FROM purchases WHERE purchase_invoice_no LIKE 'PUR-$year$month-%'");
    $max_pur = (int)$db->single()['max_pur'];
    $db->query("INSERT INTO invoice_sequences (prefix, year, month, last_number) 
                VALUES ('PUR', :year, :month, :last)
                ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, :last)");
    $db->bind(':year', $year);
    $db->bind(':month', $month);
    $db->bind(':last', $max_pur);
    return $db->execute();
}

function fixAdminPermissions() {
    $db = db();
    $modules = ['dashboard','products','purchases','sales','suppliers','returns',
                'reports','users','settings','backup','credit','cashbook','expenses'];
    $db->beginTransaction();
    try {
        // Delete existing for user 1
        $db->query("DELETE FROM user_permissions WHERE user_id = 1");
        $db->execute();
        
        $stmt = $db->prepare("INSERT INTO user_permissions (user_id, module, can_view, can_add, can_edit, can_delete, can_print) 
                              VALUES (1, :mod, 1, 1, 1, 1, 1)");
        foreach ($modules as $mod) {
            $stmt->bindParam(':mod', $mod);
            $stmt->execute();
        }
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("fixAdminPermissions: " . $e->getMessage());
        return false;
    }
}

function fixExpiredBatches() {
    $db = db();
    $db->query("UPDATE batches SET is_expired = 1 WHERE expiry_date < CURDATE() AND is_expired = 0");
    return $db->execute();
}

function fixBackupHtaccess() {
    $backup_dir = UPLOAD_PATH . 'backups/';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    $htaccess = $backup_dir . '.htaccess';
    $content = "# Deny access to backup files\nRequire all denied\nOptions -Indexes\n";
    return file_put_contents($htaccess, $content) !== false;
}

function fixStockQuantityZero() {
    $db = db();
    $db->query("UPDATE products SET stock_quantity = 0 WHERE stock_quantity IS NULL OR stock_quantity != 0");
    return $db->execute();
}

// Run checks
$checks = [];

// 1. PHP Version
$checks['php_version'] = [
    'name' => 'PHP Version (>= 8.0 required)',
    'status' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'value' => PHP_VERSION,
    'fixable' => false
];

// 2. Database Connection
try {
    $db = db();
    $db->query("SELECT 1");
    $db->execute();
    $checks['db_connection'] = [
        'name' => 'Database Connection',
        'status' => true,
        'value' => 'Connected',
        'fixable' => false
    ];
} catch (Exception $e) {
    $checks['db_connection'] = [
        'name' => 'Database Connection',
        'status' => false,
        'value' => $e->getMessage(),
        'fixable' => false
    ];
}

// 3. Required Tables
$required_tables = ['users', 'products', 'batches', 'sales', 'purchases', 'suppliers', 
                     'invoice_sequences', 'user_permissions', 'cashbook', 'expenses', 
                     'product_barcodes', 'activity_logs', 'stock_movements'];
$missing_tables = [];
foreach ($required_tables as $table) {
    if (!tableExists($table)) {
        $missing_tables[] = $table;
    }
}
$checks['tables'] = [
    'name' => 'Required Tables',
    'status' => empty($missing_tables),
    'value' => empty($missing_tables) ? 'All present' : 'Missing: ' . implode(', ', $missing_tables),
    'fixable' => false
];

// 4. Required Columns (sample)
$required_columns = [
    'users' => ['id', 'username', 'password', 'role', 'status'],
    'products' => ['id', 'product_name', 'status', 'deleted_at'],
    'batches' => ['id', 'remaining_quantity', 'expiry_date', 'is_expired']
];
$missing_columns = [];
foreach ($required_columns as $table => $cols) {
    $existing = $db->queryRaw("SHOW COLUMNS FROM `$table`");
    $existing_cols = array_column($existing, 'Field');
    foreach ($cols as $col) {
        if (!in_array($col, $existing_cols)) {
            $missing_columns[] = "$table.$col";
        }
    }
}
$checks['columns'] = [
    'name' => 'Required Columns',
    'status' => empty($missing_columns),
    'value' => empty($missing_columns) ? 'All present' : 'Missing: ' . implode(', ', $missing_columns),
    'fixable' => false
];

// 5. Upload folders writable
$upload_writable = is_writable(UPLOAD_PATH);
$backup_writable = is_writable(UPLOAD_PATH . 'backups/');
$checks['folders'] = [
    'name' => 'Upload Folders Writable',
    'status' => ($upload_writable && $backup_writable),
    'value' => 'Uploads: ' . ($upload_writable ? 'Writable' : 'Not writable') . ' | Backups: ' . ($backup_writable ? 'Writable' : 'Not writable'),
    'fixable' => false
];

// 6. Invoice sequences initialized
$year = date('Y');
$month = date('m');
$db->query("SELECT * FROM invoice_sequences WHERE prefix IN ('INV','PUR') AND year = :year AND month = :month");
$db->bind(':year', $year);
$db->bind(':month', $month);
$seqs = $db->resultSet();
$seq_ok = (count($seqs) >= 2);
$checks['sequences'] = [
    'name' => 'Invoice Sequences (current month)',
    'status' => $seq_ok,
    'value' => $seq_ok ? 'Initialized' : 'Missing sequences for current month',
    'fixable' => true,
    'fix_action' => 'invoice_sequences'
];

// 7. Admin permissions set
$db->query("SELECT COUNT(*) as cnt FROM user_permissions WHERE user_id = 1");
$perm_count = (int)$db->single()['cnt'];
$perm_ok = ($perm_count > 10);
$checks['permissions'] = [
    'name' => 'Admin Permissions',
    'status' => $perm_ok,
    'value' => $perm_ok ? "Found $perm_count permissions" : 'Missing default admin permissions',
    'fixable' => true,
    'fix_action' => 'admin_permissions'
];

// 8. Backup folder .htaccess
$htaccess_path = UPLOAD_PATH . 'backups/.htaccess';
$htaccess_ok = file_exists($htaccess_path);
if ($htaccess_ok) {
    $content = file_get_contents($htaccess_path);
    $htaccess_ok = (strpos($content, 'Require all denied') !== false);
}
$checks['backup_secure'] = [
    'name' => 'Backup Folder Protected',
    'status' => $htaccess_ok,
    'value' => $htaccess_ok ? '.htaccess present' : 'Missing or incorrect .htaccess',
    'fixable' => true,
    'fix_action' => 'backup_htaccess'
];

// 9. Error display in production
$error_display_ok = true;
if (APP_ENV === 'production') {
    $error_display_ok = (ini_get('display_errors') == '0');
}
$checks['error_display'] = [
    'name' => 'Error Display (' . APP_ENV . ' mode)',
    'status' => $error_display_ok,
    'value' => $error_display_ok ? 'OK' : 'display_errors is ON in production',
    'fixable' => false
];

// 10. CSRF token test
$token = generateCSRFToken();
$csrf_ok = (!empty($token) && verifyCSRFToken($token));
$checks['csrf'] = [
    'name' => 'CSRF Token Generation',
    'status' => $csrf_ok,
    'value' => $csrf_ok ? 'Working' : 'Failed',
    'fixable' => false
];

// 11. Expired batches flag
$db->query("SELECT COUNT(*) as cnt FROM batches WHERE expiry_date < CURDATE() AND is_expired = 0");
$expired_misflagged = (int)$db->single()['cnt'];
$checks['expired_flag'] = [
    'name' => 'Expired Batches Flag',
    'status' => ($expired_misflagged == 0),
    'value' => $expired_misflagged . ' batches expired but not flagged',
    'fixable' => true,
    'fix_action' => 'expired_batches'
];

// 12. Stock quantity column (should be 0)
$db->query("SELECT COUNT(*) as cnt FROM products WHERE stock_quantity IS NOT NULL AND stock_quantity != 0");
$non_zero_stock = (int)$db->single()['cnt'];
$checks['stock_quantity'] = [
    'name' => 'Products.stock_quantity (should be 0)',
    'status' => ($non_zero_stock == 0),
    'value' => $non_zero_stock . ' products have non-zero stock_quantity',
    'fixable' => true,
    'fix_action' => 'stock_quantity_zero'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; }
        .main-content { margin-left: 260px; padding: 20px; }
        .card-custom { background: white; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
        .status-success { background: #d4edda; color: #155724; }
        .status-danger { background: #f8d7da; color: #721c24; }
        .status-warning { background: #fff3cd; color: #856404; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
    </style>
<?php if (function_exists("generateDynamicCSS")) echo generateDynamicCSS(); ?>
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
                <h4 class="mb-1"><i class="fas fa-stethoscope me-2"></i>System Health Check</h4>
                <p class="text-muted mb-0">Verify your installation and fix common issues</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i> Back to Dashboard</a>
        </div>

        <?php if (isset($_GET['fixed'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> Fix applied successfully. Refresh to see updated status.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($fix_error): ?>
            <div class="alert alert-danger"><?php echo $fix_error; ?></div>
        <?php endif; ?>

        <div class="row">
            <?php foreach ($checks as $key => $check): ?>
            <div class="col-md-6 mb-3">
                <div class="card-custom p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1"><?php echo htmlspecialchars($check['name']); ?></h6>
                            <small class="text-muted"><?php echo htmlspecialchars($check['value']); ?></small>
                        </div>
                        <div>
                            <?php if ($check['status']): ?>
                                <span class="status-badge status-success"><i class="fas fa-check me-1"></i>Pass</span>
                            <?php else: ?>
                                <span class="status-badge status-danger"><i class="fas fa-times me-1"></i>Fail</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!$check['status'] && isset($check['fixable']) && $check['fixable']): ?>
                        <div class="mt-2 text-end">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="fix_action" value="<?php echo $check['fix_action']; ?>">
                                <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Apply fix for <?php echo htmlspecialchars($check['name']); ?>?')">
                                    <i class="fas fa-wrench me-1"></i> Auto-Fix
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card-custom p-4 mt-2">
            <h5><i class="fas fa-info-circle me-2"></i>System Information</h5>
            <hr>
            <div class="row">
                <div class="col-md-4"><strong>Server Software:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></div>
                <div class="col-md-4"><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></div>
                <div class="col-md-4"><strong>Database:</strong> MariaDB / MySQL <?php 
                    $version = $db->queryRaw("SELECT VERSION() as v");
                    echo $version[0]['v'] ?? 'Unknown';
                ?></div>
                <div class="col-md-4"><strong>Base URL:</strong> <?php echo BASE_URL; ?></div>
                <div class="col-md-4"><strong>Environment:</strong> <?php echo APP_ENV; ?></div>
                <div class="col-md-4"><strong>Last Check:</strong> <?php echo date('d-m-Y H:i:s'); ?></div>
            </div>
            <hr>
            <div class="text-muted small">
                <i class="fas fa-shield-alt me-1"></i> Security Note: Ensure debug mode is OFF in production.
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>