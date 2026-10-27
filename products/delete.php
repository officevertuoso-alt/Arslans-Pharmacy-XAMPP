<?php
/**
 * products/delete.php — Fixed
 * Handles soft-delete and restore. No hard deletes ever.
 *
 * FIX: canDelete() gracefully falls back to role-based check
 *      if user_permissions table doesn't exist yet.
 */

ob_start();

$isAjax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
     strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_GET['ajax']) && $_GET['ajax'] == '1')
);

// ── Helper: safe JSON exit ────────────────────────────────────────────────────
function ajaxExit(array $payload): void {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

// ── Helper: role-based delete permission fallback ─────────────────────────────
// Used when canDelete() is not defined OR when user_permissions table is missing.
// Admin and manager can always delete. Others cannot.
function roleCanDelete(): bool {
    $role = strtolower($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
    return in_array($role, ['admin', 'manager'], true);
}

// ── Helper: safe canDelete wrapper ────────────────────────────────────────────
// Calls the real canDelete() if it exists; if it throws (missing table) or
// doesn't exist, falls back to roleCanDelete().
function safeCanDelete(string $module): bool {
    if (!function_exists('canDelete')) {
        return roleCanDelete();
    }
    try {
        return (bool) canDelete($module);
    } catch (\Throwable $e) {
        // user_permissions table missing or other DB error — fall back to role
        return roleCanDelete();
    }
}

try {

    require_once '../config/config.php';
    require_once '../includes/product_functions.php';

    if ($isAjax) { ob_clean(); header('Content-Type: application/json; charset=utf-8'); }

    // Auth
    if (function_exists('requireLogin'))      { requireLogin(); }
    elseif (function_exists('requirePharmacist')) { requirePharmacist(); }

    if ($isAjax) { ob_clean(); header('Content-Type: application/json; charset=utf-8'); }

    // Permission check — safe wrapper handles missing table
    if (!safeCanDelete('products')) {
        if ($isAjax) ajaxExit(['success' => false, 'message' => 'Permission denied. Only admin or manager can delete products.']);
        $_SESSION['flash_error'] = 'Permission denied.';
        header('Location: list.php'); exit;
    }

    $id        = (int)($_POST['product_id'] ?? $_POST['id'] ?? $_GET['id'] ?? 0);
    $isRestore = isset($_GET['restore']) && $_GET['restore'] == '1';

    if ($id <= 0) {
        if ($isAjax) ajaxExit(['success' => false, 'message' => 'Invalid product ID.']);
        $_SESSION['flash_error'] = 'Invalid product ID.';
        header('Location: list.php'); exit;
    }

    $db = db();

    $db->query("SELECT id, product_name, status FROM products WHERE id = :id");
    $db->bind(':id', $id);
    $product = $db->single();

    if (!$product) {
        if ($isAjax) ajaxExit(['success' => false, 'message' => 'Product not found.']);
        $_SESSION['flash_error'] = 'Product not found.';
        header('Location: list.php'); exit;
    }

    // ── RESTORE ──────────────────────────────────────────────────────────────
    if ($isRestore) {
        $ok = restoreProduct($id);
        if ($ok) {
            if (function_exists('logActivity')) logActivity('restore_product', "Restored product #{$id}: {$product['product_name']}");
            if ($isAjax) ajaxExit(['success' => true, 'message' => "Product '{$product['product_name']}' restored to active list."]);
            $_SESSION['flash_success'] = "Product '{$product['product_name']}' restored.";
            header('Location: list.php'); exit;
        }
        if ($isAjax) ajaxExit(['success' => false, 'message' => 'Restore failed. Please try again.']);
        $_SESSION['flash_error'] = 'Restore failed.';
        header('Location: list.php'); exit;
    }

    // ── STOCK PROTECTION CHECK ────────────────────────────────────────────────
    $db->query(
        "SELECT SUM(b.remaining_quantity) AS total_qty
         FROM batches b
         WHERE b.product_id = :pid
           AND b.remaining_quantity > 0
           AND COALESCE(b.is_expired, 0) = 0
           AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())"
    );
    $db->bind(':pid', $id);
    $stockCheck  = $db->single();
    $activeStock = (int)($stockCheck['total_qty'] ?? 0);

    if ($activeStock > 0) {
        $msg = "Cannot delete \"{$product['product_name']}\" — it has {$activeStock} unit(s) of active stock. Sell or wait for expiry first.";
        if ($isAjax) ajaxExit(['success' => false, 'blocked' => true, 'stock' => $activeStock, 'message' => $msg]);
        $_SESSION['flash_error'] = $msg;
        header('Location: list.php'); exit;
    }

    // ── SOFT DELETE ───────────────────────────────────────────────────────────
    $ok = deleteProduct($id);
    if ($ok) {
        if (function_exists('logActivity')) logActivity('soft_delete_product', "Moved #{$id} to Trash: {$product['product_name']}");
        if ($isAjax) ajaxExit(['success' => true, 'message' => "Product '{$product['product_name']}' moved to Trash."]);
        $_SESSION['flash_success'] = "Product '{$product['product_name']}' moved to Trash.";
        header('Location: list.php'); exit;
    }

    if ($isAjax) ajaxExit(['success' => false, 'message' => 'Could not move to Trash. Please try again.']);
    $_SESSION['flash_error'] = 'Could not move product to Trash.';
    header('Location: list.php'); exit;

} catch (\Throwable $e) {
    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
    throw $e;
}