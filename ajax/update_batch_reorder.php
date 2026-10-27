<?php
/**
 * AJAX: Update Batch Reorder Level
 * 
 * Updates batches.reorder_level for low stock alerts
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('products', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
require_once '../includes/product_functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$batchId = (int)($_POST['batch_id'] ?? 0);
$newReorderLevel = (int)($_POST['reorder_level'] ?? 0);

if ($batchId <= 0 || $newReorderLevel < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid batch ID or reorder level (must be ≥ 1)']);
    exit;
}

try {
    $result = updateBatchReorderLevel($batchId, $newReorderLevel);
    
    if ($result) {
        if (function_exists('logActivity')) {
            logActivity('batch_reorder_update', "Updated batch #{$batchId} reorder level to {$newReorderLevel}");
        }
        
        echo json_encode([
            'success' => true,
            'batch_id' => $batchId,
            'reorder_level' => $newReorderLevel,
            'message' => 'Reorder level updated successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database update failed']);
    }
    
} catch (Exception $e) {
    error_log("[update_batch_reorder] Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>