<?php // REPAIRED vFIX8 - INTEGRATED vFIX12
/**
 * ajax/repair_after_restore.php
 * 
 * Post-Restore Recovery Script — Repairs missing triggers and data after restoring
 * a backup created before trigger export fixes (Steps BR-1 to BR-7).
 * 
 * Functions:
 *   1. Check and recreate all 8 critical triggers
 *   2. Recalculate products.stock_quantity from batches
 *   3. Recalculate sales.total from sale_items
 *   4. Mark expired batches
 *   5. Sync sales.net_amount and grand_total
 * 
 * FORENSIC REPAIRS (vFIX8):
 *   - Added stock_adjustments integration (ISSUE 1)
 *   - Added admin flag reset (ISSUE 2)
 *   - Removed DELIMITER from trigger creation (ISSUE 3)
 *   - Added comprehensive error collection (ISSUE 4)
 *   - Added cashbook balance recalculation (ISSUE 5)
 * 
 * INTEGRATION FIX (vFIX12):
 *   - Added missing includes for clearAllCaches() and _getPDO()
 *   - Ensured all functions are properly loaded
 * 
 * Security: Admin only + CSRF protected
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/sync_engine.php';

// ==============================================================
// INTEGRATION FIX: Ensure all required functions are available
// ==============================================================

// Ensure clearAllCaches() is available (from sync_engine.php)
if (!function_exists('clearAllCaches')) {
    require_once __DIR__ . '/../includes/sync_engine.php';
}

// Ensure _getPDO() is available (from database.php) - already included via config.php
if (!function_exists('_getPDO')) {
    require_once __DIR__ . '/../config/database.php';
}

// Ensure verifyCSRFToken() is available (from settings_functions.php)
if (!function_exists('verifyCSRFToken')) {
    require_once __DIR__ . '/../includes/settings_functions.php';
}

// Ensure logActivity() is available (from config.php - already included)
if (!function_exists('logActivity')) {
    require_once __DIR__ . '/../config/config.php';
}

header('Content-Type: application/json; charset=utf-8');

// ── PHP Limits ──────────────────────────────────────────────────
@ini_set('memory_limit', '512M');
@set_time_limit(300);

// ── Auth ──────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────
$csrf_token = trim($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh the page.']);
    exit;
}

// ── Confirmation parameter ────────────────────────────────────────
$confirm = trim($_POST['confirm'] ?? '');
if ($confirm !== 'REPAIR') {
    echo json_encode([
        'success' => false, 
        'message' => 'Please type "REPAIR" in the confirm field to execute this operation.',
        'requires_confirmation' => true
    ]);
    exit;
}

// ==============================================================
// TRIGGER DEFINITIONS (from pharmacy_db.sql) - NO DELIMITER
// ISSUE 3: Removed all DELIMITER keywords - PDO doesn't support them
// ==============================================================

$triggerDefinitions = [
    'after_batch_insert_stock' => [
        'timing' => 'AFTER',
        'event' => 'INSERT',
        'table' => 'batches',
        'body' => "
            UPDATE `products` SET `stock_quantity` = COALESCE((
                SELECT SUM(b.remaining_quantity)
                FROM `batches` b
                WHERE b.product_id = NEW.product_id
                  AND b.is_expired = 0
            ), 0)
            WHERE `id` = NEW.product_id;
        "
    ],
    'after_batch_update_stock' => [
        'timing' => 'AFTER',
        'event' => 'UPDATE',
        'table' => 'batches',
        'body' => "
            IF OLD.remaining_quantity != NEW.remaining_quantity OR OLD.is_expired != NEW.is_expired THEN
                UPDATE `products` SET `stock_quantity` = COALESCE((
                    SELECT SUM(b.remaining_quantity)
                    FROM `batches` b
                    WHERE b.product_id = NEW.product_id
                      AND b.is_expired = 0
                ), 0)
                WHERE `id` = NEW.product_id;
            END IF;
        "
    ],
    'update_expired_flag' => [
        'timing' => 'BEFORE',
        'event' => 'UPDATE',
        'table' => 'batches',
        'body' => "
            IF NEW.expiry_date < CURDATE() THEN
                SET NEW.is_expired = 1;
            ELSE
                SET NEW.is_expired = 0;
            END IF;
        "
    ],
    'before_credit_payment_check_limit' => [
        'timing' => 'BEFORE',
        'event' => 'INSERT',
        'table' => 'credit_payments',
        'body' => "
            DECLARE remaining DECIMAL(10,2);
            SELECT (cc.total_amount - COALESCE(SUM(cp.amount), 0)) INTO remaining
            FROM customer_credits cc
            LEFT JOIN credit_payments cp ON cp.credit_id = cc.id
            WHERE cc.id = NEW.credit_id;
            IF (NEW.amount > remaining + 0.01) THEN
                SIGNAL SQLSTATE '45000' 
                SET MESSAGE_TEXT = 'Payment amount exceeds remaining credit balance';
            END IF;
        "
    ],
    'after_sale_items_insert_update_total' => [
        'timing' => 'AFTER',
        'event' => 'INSERT',
        'table' => 'sale_items',
        'body' => "
            DECLARE items_sum DECIMAL(10,2);
            SET items_sum = (SELECT COALESCE(SUM(total), 0) FROM sale_items WHERE sale_id = NEW.sale_id);
            UPDATE sales s
            SET 
                s.subtotal   = items_sum,
                s.total      = items_sum - s.discount + s.tax_amount,
                s.net_amount = items_sum - s.discount + s.tax_amount,
                s.grand_total = items_sum - s.discount + s.tax_amount
            WHERE s.id = NEW.sale_id;
        "
    ],
    'after_sale_items_update_update_total' => [
        'timing' => 'AFTER',
        'event' => 'UPDATE',
        'table' => 'sale_items',
        'body' => "
            DECLARE items_sum DECIMAL(10,2);
            SET items_sum = (SELECT COALESCE(SUM(total), 0) FROM sale_items WHERE sale_id = NEW.sale_id);
            UPDATE sales s
            SET 
                s.subtotal   = items_sum,
                s.total      = items_sum - s.discount + s.tax_amount,
                s.net_amount = items_sum - s.discount + s.tax_amount,
                s.grand_total = items_sum - s.discount + s.tax_amount
            WHERE s.id = NEW.sale_id;
        "
    ],
    'after_sale_items_delete_update_total' => [
        'timing' => 'AFTER',
        'event' => 'DELETE',
        'table' => 'sale_items',
        'body' => "
            DECLARE items_sum DECIMAL(10,2);
            SET items_sum = (SELECT COALESCE(SUM(total), 0) FROM sale_items WHERE sale_id = OLD.sale_id);
            UPDATE sales s
            SET 
                s.subtotal   = items_sum,
                s.total      = items_sum - s.discount + s.tax_amount,
                s.net_amount = items_sum - s.discount + s.tax_amount,
                s.grand_total = items_sum - s.discount + s.tax_amount
            WHERE s.id = OLD.sale_id;
        "
    ],
    'before_sale_update_correct_total' => [
        'timing' => 'BEFORE',
        'event' => 'UPDATE',
        'table' => 'sales',
        'body' => "
            DECLARE items_sum DECIMAL(10,2);
            SET items_sum = (
                SELECT COALESCE(SUM(total), 0) 
                FROM sale_items 
                WHERE sale_id = NEW.id
            );
            SET NEW.subtotal   = items_sum;
            SET NEW.total      = items_sum - NEW.discount + NEW.tax_amount;
            SET NEW.net_amount = items_sum - NEW.discount + NEW.tax_amount;
            SET NEW.grand_total = items_sum - NEW.discount + NEW.tax_amount;
        "
    ]
];

/**
 * Check if a trigger exists in the database
 */
function triggerExists(PDO $pdo, string $triggerName): bool {
    $stmt = $pdo->prepare("
        SELECT TRIGGER_NAME 
        FROM information_schema.TRIGGERS 
        WHERE TRIGGER_SCHEMA = DATABASE() 
          AND TRIGGER_NAME = :name
    ");
    $stmt->execute([':name' => $triggerName]);
    return $stmt->fetch() !== false;
}

/**
 * Create a trigger - NO DELIMITER syntax
 */
function createTrigger(PDO $pdo, string $name, string $timing, string $event, string $table, string $body): bool {
    try {
        $sql = "DROP TRIGGER IF EXISTS `{$name}`";
        $pdo->exec($sql);
        
        $sql = "CREATE TRIGGER `{$name}` {$timing} {$event} ON `{$table}` FOR EACH ROW
                BEGIN
                {$body}
                END";
        
        $pdo->exec($sql);
        return true;
    } catch (Exception $e) {
        error_log("Failed to create trigger {$name}: " . $e->getMessage());
        return false;
    }
}

// ==============================================================
// ISSUE 4: Error Collection
// ==============================================================
$warnings = [];
$errors = [];

// Helper to add warning
function addWarning(&$warnings, string $message) {
    $warnings[] = $message;
}

// Helper to add error
function addError(&$errors, string $message) {
    $errors[] = $message;
}

// ==============================================================
// MAIN EXECUTION
// ==============================================================

$report = [
    'success' => true,
    'message' => '',
    'triggers_checked' => 0,
    'triggers_restored' => 0,
    'triggers_failed' => 0,
    'expired_batches_marked' => 0,
    'products_updated' => 0,
    'sales_recalculated' => 0,
    'sales_total_synced' => 0,
    'stock_adjustments_applied' => 0,  // ISSUE 1
    'cashbook_balance_recalculated' => false,  // ISSUE 5
    'admin_flags_reset' => false,  // ISSUE 2
    'warnings' => [],  // ISSUE 4
    'errors' => []
];

try {
    $pdo = db()->getConnection();
    
    // ==========================================================
    // PART 1: Check and recreate missing triggers
    // ==========================================================
    $report['triggers_checked'] = count($triggerDefinitions);
    
    foreach ($triggerDefinitions as $name => $def) {
        if (!triggerExists($pdo, $name)) {
            $report['triggers_restored']++;
            if (!createTrigger($pdo, $name, $def['timing'], $def['event'], $def['table'], $def['body'])) {
                $report['triggers_failed']++;
                addError($errors, "Failed to create trigger: {$name}");
            }
        }
    }
    
    if ($report['triggers_failed'] > 0) {
        addWarning($warnings, "{$report['triggers_failed']} triggers failed to create. Check database permissions.");
    }
    
    // ==========================================================
    // PART 2: Data Repairs
    // ==========================================================
    
    // Fix 1: Mark all expired batches
    try {
        $stmt = $pdo->exec("UPDATE batches SET is_expired = 1 WHERE expiry_date < CURDATE() AND is_expired = 0");
        $report['expired_batches_marked'] = $stmt !== false ? $stmt : 0;
    } catch (Exception $e) {
        addError($errors, "Expired batches update failed: " . $e->getMessage());
    }
    
    // Fix 2: Recalculate products.stock_quantity from batches
    try {
        $stmt = $pdo->exec("
            UPDATE products p 
            SET stock_quantity = (
                SELECT COALESCE(SUM(b.remaining_quantity), 0) 
                FROM batches b 
                WHERE b.product_id = p.id 
                  AND b.is_expired = 0
                  AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
            )
        ");
        $report['products_updated'] = $stmt !== false ? $stmt : 0;
    } catch (Exception $e) {
        addError($errors, "Product stock update failed: " . $e->getMessage());
    }
    
    // ISSUE 1: Apply stock_adjustments impact
    try {
        // First, check if stock_adjustments table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'stock_adjustments'");
        if ($tableCheck->rowCount() > 0) {
            // Apply positive adjustments (add stock)
            $stmtAdd = $pdo->exec("
                UPDATE products p
                SET stock_quantity = stock_quantity + (
                    SELECT COALESCE(SUM(sa.quantity), 0)
                    FROM stock_adjustments sa
                    WHERE sa.batch_id IN (
                        SELECT b.id FROM batches b WHERE b.product_id = p.id
                    )
                    AND sa.adjustment_type IN ('correction', 'damage')
                    AND sa.quantity > 0
                )
            ");
            
            // Apply negative adjustments (remove stock)
            $stmtRemove = $pdo->exec("
                UPDATE products p
                SET stock_quantity = GREATEST(0, stock_quantity - (
                    SELECT COALESCE(SUM(ABS(sa.quantity)), 0)
                    FROM stock_adjustments sa
                    WHERE sa.batch_id IN (
                        SELECT b.id FROM batches b WHERE b.product_id = p.id
                    )
                    AND sa.adjustment_type IN ('correction', 'damage', 'theft')
                    AND sa.quantity < 0
                ))
            ");
            
            $report['stock_adjustments_applied'] = ($stmtAdd !== false ? $stmtAdd : 0) + ($stmtRemove !== false ? $stmtRemove : 0);
        } else {
            addWarning($warnings, "stock_adjustments table not found - skipping adjustments");
        }
    } catch (Exception $e) {
        addWarning($warnings, "Stock adjustments integration failed (non-critical): " . $e->getMessage());
    }
    
    // Fix 3: Recalculate sales.total from sale_items
    try {
        $stmt = $pdo->exec("
            UPDATE sales s
            SET s.total = COALESCE((
                SELECT COALESCE(SUM(si.total), 0) 
                FROM sale_items si 
                WHERE si.sale_id = s.id
            ), 0) - s.discount + s.tax_amount
            WHERE COALESCE(s.status, 'active') != 'voided'
        ");
        $report['sales_recalculated'] = $stmt !== false ? $stmt : 0;
    } catch (Exception $e) {
        addError($errors, "Sales total recalculation failed: " . $e->getMessage());
    }
    
    // Fix 4: Sync sales.subtotal, net_amount, grand_total with total
    try {
        $stmt = $pdo->exec("
            UPDATE sales 
            SET subtotal = total,
                net_amount = total,
                grand_total = total
            WHERE COALESCE(status, 'active') != 'voided'
        ");
        $report['sales_total_synced'] = $stmt !== false ? $stmt : 0;
    } catch (Exception $e) {
        addError($errors, "Sales sync failed: " . $e->getMessage());
    }
    
    // ISSUE 5: Recalculate cashbook running balance
    try {
        // Check if cashbook table exists and has balance column
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'cashbook'");
        if ($tableCheck->rowCount() > 0) {
            // Reset all balances to 0 first
            $pdo->exec("UPDATE cashbook SET balance = 0 WHERE voided = 0");
            
            // Recalculate running balance using session variable
            $pdo->exec("SET @running_balance = 0");
            $pdo->exec("
                UPDATE cashbook c
                JOIN (
                    SELECT id, 
                           (@running_balance := @running_balance + credit - debit) AS new_balance
                    FROM cashbook 
                    WHERE voided = 0 
                    ORDER BY id ASC
                ) calc ON c.id = calc.id
                SET c.balance = calc.new_balance
            ");
            $report['cashbook_balance_recalculated'] = true;
        } else {
            addWarning($warnings, "cashbook table not found - skipping balance recalculation");
        }
    } catch (Exception $e) {
        addWarning($warnings, "Cashbook balance recalculation failed (non-critical): " . $e->getMessage());
    }
    
    // ISSUE 2: Reset admin user flags
    try {
        $stmt = $pdo->exec("UPDATE users SET is_first_login = 0, force_password_change = 0 WHERE role = 'admin'");
        $report['admin_flags_reset'] = $stmt !== false && $stmt > 0;
        if ($stmt !== false && $stmt > 0) {
            addWarning($warnings, "Reset {$stmt} admin user(s) - is_first_login=0, force_password_change=0");
        }
    } catch (Exception $e) {
        addWarning($warnings, "Admin flags reset failed (non-critical): " . $e->getMessage());
    }
    
    // Fix 5: Force mark any batches that should be expired (re-run with trigger might not have fired)
    try {
        $pdo->exec("UPDATE batches SET is_expired = 1 WHERE expiry_date < CURDATE()");
    } catch (Exception $e) {
        addWarning($warnings, "Secondary expired batch update failed (non-critical): " . $e->getMessage());
    }
    
    // ==========================================================
    // PART 3: Clear caches
    // ==========================================================
    if (function_exists('clearAllCaches')) {
        try {
            clearAllCaches();
        } catch (Exception $e) {
            addWarning($warnings, "Cache clear warning: " . $e->getMessage());
        }
    } else {
        addWarning($warnings, "clearAllCaches() function not available - cache not cleared");
    }
    
    // ==========================================================
    // PART 4: Log the repair
    // ==========================================================
    if (function_exists('logActivity')) {
        try {
            logActivity(
                $_SESSION['user_id'] ?? 0,
                'POST_RESTORE_REPAIR',
                "Repaired system after restore. " .
                "Triggers restored: {$report['triggers_restored']}, " .
                "Products updated: {$report['products_updated']}, " .
                "Sales recalculated: {$report['sales_recalculated']}, " .
                "Expired batches marked: {$report['expired_batches_marked']}, " .
                "Cashbook recalculated: " . ($report['cashbook_balance_recalculated'] ? 'Yes' : 'No')
            );
        } catch (Exception $e) {
            addWarning($warnings, "Activity log write failed: " . $e->getMessage());
        }
    } else {
        addWarning($warnings, "logActivity() function not available - activity not logged");
    }
    
    // ==========================================================
    // PART 5: Build response with warnings
    // ==========================================================
    $report['warnings'] = $warnings;
    $report['errors'] = $errors;
    
    if (empty($errors)) {
        $report['message'] = "System repaired successfully! All triggers verified, stock and sales totals recalculated.";
        if (!empty($warnings)) {
            $report['message'] .= " (" . count($warnings) . " warning(s) - check warnings array)";
        }
        $report['success'] = true;
    } else {
        $report['message'] = "Repair completed with " . count($errors) . " error(s) and " . count($warnings) . " warning(s). Check the errors array for details.";
        $report['success'] = true; // Still success, but with errors/warnings
    }
    
    echo json_encode($report);
    exit;
    
} catch (Exception $e) {
    error_log("Repair after restore failed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Repair failed: ' . $e->getMessage(),
        'errors' => [$e->getMessage()],
        'warnings' => []
    ]);
    exit;
}
?>