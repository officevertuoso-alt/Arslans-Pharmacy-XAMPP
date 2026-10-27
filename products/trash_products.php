<?php
/**
 * products/trash_products.php
 *
 * Handles Trash Items modal operations:
 *   GET  ?trash=1          → Returns JSON array of soft-deleted products
 *   POST action=restore    → Restores a single product (product_id)
 *   POST action=bulk_restore → Restores multiple products (product_ids[])
 *
 * All operations use status column only — no rows are ever permanently deleted here.
 */

ob_start();

require_once '../config/config.php';
require_once '../includes/product_functions.php';
requireLogin();

ob_clean();
header('Content-Type: application/json; charset=utf-8');

// ── GET: Return list of trashed products ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['trash'])) {
    try {
        $pdo  = _getPDO();
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.product_name,
                p.brand_name,
                p.generic_name,
                p.dosage_form,
                p.product_code,
                p.category_id,
                p.size_value,
                p.deleted_at
            FROM products p
            WHERE p.status = 0
            ORDER BY p.deleted_at DESC, p.id DESC
        ");
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($items);
    } catch (Throwable $e) {
        error_log('trash_products GET: ' . $e->getMessage());
        echo json_encode([]);
    }
    exit;
}

// ── POST: Restore actions ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // Single restore
    if ($action === 'restore') {
        $id = (int)($_POST['product_id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid product ID.']);
            exit;
        }
        $ok = restoreProduct($id);
        if ($ok) {
            logActivity('restore_product', "Restored product #{$id} from Trash");
            echo json_encode(['success' => true, 'message' => 'Product restored successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Restore failed. Product may already be active or not found.']);
        }
        exit;
    }

    // Bulk restore
    if ($action === 'bulk_restore') {
        $ids = array_filter(array_map('intval', $_POST['product_ids'] ?? []), fn($v) => $v > 0);
        if (empty($ids)) {
            echo json_encode(['success' => false, 'message' => 'No valid product IDs provided.']);
            exit;
        }
        $restoredCount = 0;
        foreach ($ids as $id) {
            if (restoreProduct($id)) {
                logActivity('restore_product', "Bulk-restored product #{$id} from Trash");
                $restoredCount++;
            }
        }
        if ($restoredCount > 0) {
            echo json_encode(['success' => true, 'count' => $restoredCount, 'message' => "{$restoredCount} product(s) restored."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No products were restored. They may already be active.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// Fallback
echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
exit;
?>
