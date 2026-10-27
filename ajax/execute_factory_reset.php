<?php // REPAIRED vFIX1
/**
 * Execute Factory Reset (Force Default Admin)
 * ajax/execute_factory_reset.php
 *
 * This version does not require admin password or pharmacy name confirmation.
 * It always creates a default admin user (admin / admin123) after clearing data.
 * 
 * FIXED (Step BR-3):
 *   - Admin INSERT includes NOT NULL columns (full_name, email)
 *   - Added missing tables to truncation lists
 *   - Recalculates products.stock_quantity after reset
 *   - Resets supplier balances on full reset
 *   - Prunes old pre-restore backups
 * 
 * FORENSIC REPAIRS (vFIX1):
 *   - Fixed login redirect loop (is_first_login=0, force_password_change=0)
 *   - Added missing non-nullable columns in users INSERT
 *   - Added backup_log + backup_logs to common tables
 *   - Fixed full reset truncation (added system_resets, preserved expense_categories)
 *   - Improved session destruction (cookie clear)
 *   - Added system_resets audit logging
 *   - Fixed user_permissions deletion order
 */

require_once '../config/config.php';
require_once '../config/database.php';      // provides _getPDO()
require_once '../includes/auth.php';        // provides isAdmin(), verifyCSRFToken()
require_once '../includes/sync_engine.php'; // provides clearAllCaches(), logActivity()

header('Content-Type: application/json');

// ── PHP Limits for large operations ──────────────────────────────────
@ini_set('memory_limit', '512M');
@set_time_limit(300);  // 5 minutes max

// Only admin can trigger reset
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF protection
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$reset_type = $_POST['reset_type'] ?? '';
if (!in_array($reset_type, ['full', 'financial', 'transaction'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid reset type']);
    exit;
}

// Optional backup filename (not used for restoration, only for logging)
$backup_filename = $_POST['backup_filename'] ?? '';

try {
    $pdo = _getPDO();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // -----------------------------------------------------------------
    // 1. Determine which tables to truncate based on reset type
    // -----------------------------------------------------------------
    $commonTables = [
        'sales', 'sale_items', 'sale_returns',
        'purchases', 'purchase_items', 'purchase_returns',
        'cashbook', 'customer_credits', 'credit_payments',
        'expenses', 'stock_movements', 'activity_logs',
        'bulk_import_logs', 'login_attempts', 'sync_events',
        'active_sessions', 'form_drafts',   // ← FIX B: Added missing tables
        'backup_log', 'backup_logs'         // ← ISSUE 3: Restore history must be wiped
    ];

    $truncateTables = $commonTables;

    if ($reset_type === 'financial') {
        // Financial reset also removes purchases and stock movements (already in common)
        // Keep products, batches, suppliers – not truncated
    } elseif ($reset_type === 'full') {
        // Full reset removes all master data as well
        // NOTE: expense_categories is preserved (config, not data) - ISSUE 4
        $truncateTables = array_merge($commonTables, [
            'products', 'batches', 'suppliers', 'user_permissions',
            'customers', 'product_barcodes', 'product_variants',
            'stock_adjustments', 'supplier_payments',  // ← FIX B: Added missing tables
            'system_resets'                           // ← ISSUE 4: Audit table cleared on full reset
        ]);
    }

    // Truncate each table (skip if table does not exist)
    foreach ($truncateTables as $table) {
        try {
            $pdo->exec("TRUNCATE TABLE `$table`");
        } catch (PDOException $e) {
            // Table might not exist – ignore error
            if (stripos($e->getMessage(), 'base table or view not found') === false) {
                error_log("Could not truncate $table: " . $e->getMessage());
            }
        }
    }

    // ISSUE 7: user_permissions DELETE moved here (after truncation, not before)
    // Note: For 'full' reset, user_permissions is already in $truncateTables above.
    // For other reset types, we delete permissions manually to ensure clean state.
    if ($reset_type !== 'full') {
        $pdo->exec("DELETE FROM user_permissions");
    }

    // -----------------------------------------------------------------
    // FIX C: Recalculate products.stock_quantity from surviving batches
    // -----------------------------------------------------------------
    try {
        $pdo->exec("
            UPDATE products p 
            SET stock_quantity = (
                SELECT COALESCE(SUM(b.remaining_quantity), 0)
                FROM batches b 
                WHERE b.product_id = p.id 
                  AND b.is_expired = 0
                  AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
            )
        ");
    } catch (PDOException $e) {
        error_log('Post-reset stock recalc failed: ' . $e->getMessage());
    }

    // Reset supplier balances for full reset
    if ($reset_type === 'full') {
        try {
            $pdo->exec("UPDATE suppliers SET current_balance = 0, due_amount = 0");
            // Also try to update balance column if it still exists (for backward compatibility)
            try {
                $pdo->exec("UPDATE suppliers SET balance = 0");
            } catch (PDOException $e) {
                // Column may have been dropped already – ignore
            }
        } catch (PDOException $e) {
            error_log('Supplier balance reset failed: ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // 2. Reset invoice sequences (start from 1 for current month)
    // -----------------------------------------------------------------
    $year = date('Y');
    $month = date('m');
    // Ensure invoice_sequences table exists
    $checkSeq = $pdo->query("SHOW TABLES LIKE 'invoice_sequences'");
    if ($checkSeq->rowCount() === 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `invoice_sequences` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `prefix` VARCHAR(10) NOT NULL,
            `year` INT NOT NULL,
            `month` INT NOT NULL,
            `last_number` INT NOT NULL DEFAULT 0,
            UNIQUE KEY `prefix_year_month` (`prefix`,`year`,`month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $pdo->exec("DELETE FROM invoice_sequences");
    $stmtInv = $pdo->prepare("INSERT INTO invoice_sequences (prefix, year, month, last_number) VALUES ('INV', ?, ?, 0)");
    $stmtInv->execute([$year, $month]);
    $stmtPur = $pdo->prepare("INSERT INTO invoice_sequences (prefix, year, month, last_number) VALUES ('PUR', ?, ?, 0)");
    $stmtPur->execute([$year, $month]);

    // -----------------------------------------------------------------
    // ── PART 1: Default admin = arslan / arslan123, is_first_login = 1
    // -----------------------------------------------------------------
    $pdo->exec("DELETE FROM users");                       // clear all users

    // Also clear per-user theme settings
    try { $pdo->exec("DELETE FROM user_settings"); } catch (Throwable $_e) {}

    $adminHash = password_hash('arslan123', PASSWORD_BCRYPT);
    $insertAdmin = $pdo->prepare("
        INSERT INTO users
            (username, password, full_name, email, role, status,
             permissions_set, force_password_change, is_first_login, login_count, created_at)
        VALUES
            ('arslan', :hash, 'Administrator', 'admin@pharmacy.local',
             'admin', 1, 1, 0, 1, 0, NOW())
    ");
    $insertAdmin->execute([':hash' => $adminHash]);
    $adminId = $pdo->lastInsertId();

    // ── Phase 29: Apply Dark Blue Professional theme for new admin ────────────
    // 1. Mark the company_settings theme preset
    try {
        $pdo->exec("UPDATE company_settings SET theme_preset = 'dark_blue_professional' WHERE id = 1");
    } catch (Throwable $_e) {}

    // 2. Ensure user_settings table exists and upsert theme for new admin
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `user_settings` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `user_id`        INT NOT NULL,
                `selected_theme` VARCHAR(50) NOT NULL DEFAULT 'dark_blue_professional',
                `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_user_settings_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $themeStmt = $pdo->prepare("
            INSERT INTO user_settings (user_id, selected_theme, updated_at)
            VALUES (?, 'dark_blue_professional', NOW())
            ON DUPLICATE KEY UPDATE selected_theme = 'dark_blue_professional', updated_at = NOW()
        ");
        $themeStmt->execute([$adminId]);
    } catch (Throwable $_e) {
        error_log('Phase 29: user_settings theme apply failed: ' . $_e->getMessage());
    }

    // -----------------------------------------------------------------
    // 4. (Optional) Grant full permissions to admin – not strictly needed
    //    because isAdmin() bypasses permission checks. However, if the system
    //    uses the permission table for admin, we grant all.
    // -----------------------------------------------------------------
    // Check if user_permissions table exists and has the expected structure
    $checkPermTable = $pdo->query("SHOW TABLES LIKE 'user_permissions'");
    if ($checkPermTable->rowCount() > 0) {
        // Determine column set (some versions use 'permission_key' + boolean columns)
        $columns = $pdo->query("DESCRIBE user_permissions")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('permission_key', $columns) && in_array('can_view', $columns)) {
            // Classic structure: permission_key, can_view, can_add, can_edit, can_delete, can_approve, can_export
            $permKeys = ['dashboard', 'products', 'sales', 'purchases', 'returns', 'credits',
                         'suppliers', 'cashbook', 'expenses', 'reports', 'users', 'settings', 'backup'];
            $permStmt = $pdo->prepare("
                INSERT INTO user_permissions
                (user_id, permission_key, can_view, can_add, can_edit, can_delete, can_approve, can_export)
                VALUES (?, ?, 1, 1, 1, 1, 1, 1)
            ");
            foreach ($permKeys as $key) {
                $permStmt->execute([$adminId, $key]);
            }
        } elseif (in_array('module', $columns) && in_array('can_view', $columns)) {
            // Newer structure: module, can_view, can_add, etc.
            $modules = ['dashboard', 'products', 'sales', 'purchases', 'returns', 'credits',
                        'suppliers', 'cashbook', 'expenses', 'reports', 'users', 'settings', 'backup'];
            $permStmt = $pdo->prepare("
                INSERT INTO user_permissions
                (user_id, module, can_view, can_add, can_edit, can_print, can_export, can_import, can_void)
                VALUES (?, ?, 1, 1, 1, 1, 1, 1, 1)
            ");
            foreach ($modules as $module) {
                $permStmt->execute([$adminId, $module]);
            }
        }
        // If structure differs, we skip to avoid errors – admin role still works.
    }

    // -----------------------------------------------------------------
    // ISSUE 6: Log this reset in system_resets table
    // -----------------------------------------------------------------
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $pdo->prepare("
            INSERT INTO system_resets (reset_type, reset_by, reset_at, notes, backup_filename_before, ip_address)
            VALUES (:type, 0, NOW(), 'Factory reset via UI', :backup, :ip)
        ");
        $stmt->execute([
            ':type' => $reset_type,
            ':backup' => $backup_filename,
            ':ip' => $ip_address
        ]);
    } catch (PDOException $e) {
        // Non-fatal – table might not exist in older versions
        error_log('Could not log to system_resets: ' . $e->getMessage());
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // -----------------------------------------------------------------
    // 5. Clear cached data and log the reset
    // -----------------------------------------------------------------
    clearAllCaches();
    
    // -----------------------------------------------------------------
    // FIX D: Prune old pre-restore backups (keep last 5 only)
    // -----------------------------------------------------------------
    try {
        $backup_dir = dirname(__DIR__) . '/uploads/backups/';
        if (is_dir($backup_dir)) {
            $pre_files = glob($backup_dir . 'pre_restore_*.{sql,gz}', GLOB_BRACE) ?: array();
            if (count($pre_files) > 5) {
                usort($pre_files, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                $to_delete = array_slice($pre_files, 0, count($pre_files) - 5);
                foreach ($to_delete as $old) {
                    @unlink($old);
                    @unlink($old . '.meta.json');
                }
            }
        }
    } catch (Exception $e) {
        // Non-fatal – log and continue
        error_log('Pre-restore backup prune failed: ' . $e->getMessage());
    }
    
    logActivity('FACTORY_RESET', "System reset (type: $reset_type). Default admin created. Backup: $backup_filename");

    // -----------------------------------------------------------------
    // ISSUE 5: Destroy session completely and clear cookie
    // -----------------------------------------------------------------
    session_destroy();
    // Ensure cookie is fully invalidated
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_start();
    session_regenerate_id(true);
    session_destroy();

    echo json_encode([
        'success'  => true,
        'redirect' => BASE_URL . 'auth/login.php',
        'message'  => 'Factory reset complete. Please login with <strong>arslan</strong> / <strong>arslan123</strong>'
    ]);
    exit;

} catch (Exception $e) {
    // Ensure foreign key checks are re-enabled even on error
    if (isset($pdo)) {
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Exception $ignored) {}
    }
    error_log('Factory reset error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Reset failed: ' . $e->getMessage()]);
}
?>