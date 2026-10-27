<?php
/**
 * ajax/update_cart_item.php
 *
 * Updates a single field (item_discount or item_tax) of a cart item in session.
 * Called by onDiscInput() and onTaxInput() in pos.php.
 *
 * POST params:
 *   index  — cart item uniqid string
 *   field  — 'item_discount' | 'item_tax'
 *   value  — float
 *
 * Response: { success }
 */
session_start();
require_once '../config/config.php';
require_once '../includes/sale_functions.php';

requireLogin();
// Phase 28: CSRF protection on all cart mutation endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($_csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security token invalid. Refresh the page.']);
        exit;
    }
}

header('Content-Type: application/json');

$index = $_POST['index'] ?? '';
$field = $_POST['field'] ?? '';
$value = (float)($_POST['value'] ?? 0);

$allowedFields = ['item_discount', 'item_tax'];

if ($index === '' || !in_array($field, $allowedFields)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

if (!isset($_SESSION['pos_cart'][$index])) {
    echo json_encode(['success' => false, 'message' => 'Cart item not found']);
    exit;
}

// Clamp 0–100
$value = max(0, min(100, $value));
$_SESSION['pos_cart'][$index][$field] = $value;

echo json_encode(['success' => true]);
