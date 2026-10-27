<?php
/**
 * ajax/get_return_items.php
 * Get returnable line-items for a given sale or purchase.
 * Called by: returns/sale_return.php, returns/purchase_return.php via AJAX.
 *
 * GET params:
 *   ?type=sale|purchase
 *   &id=SALE_ID or PURCHASE_ID
 *
 * Returns JSON: { success, items: [...] }
 */
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/return_functions.php';

requireLogin();
header('Content-Type: application/json');

$type = trim($_GET['type'] ?? 'sale');
$id   = (int)($_GET['id'] ?? 0);

if (!in_array($type, ['sale', 'purchase'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid type. Use sale or purchase.']);
    exit;
}

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID is required']);
    exit;
}

if (!function_exists('getReturnableItems')) {
    echo json_encode(['success' => false, 'message' => 'Return functions not available']);
    exit;
}

try {
    $items = getReturnableItems($id, $type);

    if (empty($items)) {
        echo json_encode([
            'success' => false,
            'message' => 'No returnable items found. All items may already be returned.',
            'items'   => [],
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'type'    => $type,
        'parent_id' => $id,
        'items'   => $items,
        'count'   => count($items),
    ]);

} catch (Exception $e) {
    error_log('get_return_items error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error loading items. Please try again.']);
}
exit;
