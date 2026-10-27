<?php
/**
 * ajax/remove_from_cart.php
 * 
 * Removes a single item from the session POS cart.
 * FIX: index is a uniqid() string — never cast to int.
 * 
 * POST params:
 *   index  — the cart item's uniqid string key
 *
 * Returns JSON: { success, count }
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

if ($index === '') {
    echo json_encode(['success' => false, 'message' => 'No index provided']);
    exit;
}

if (!isset($_SESSION['pos_cart']) || !is_array($_SESSION['pos_cart'])) {
    echo json_encode(['success' => true, 'count' => 0, 'message' => 'Cart already empty']);
    exit;
}

if (isset($_SESSION['pos_cart'][$index])) {
    unset($_SESSION['pos_cart'][$index]);
    echo json_encode([
        'success' => true,
        'count'   => count($_SESSION['pos_cart'])
    ]);
} else {
    // Item not found — still return success so UI stays in sync
    echo json_encode([
        'success' => true,
        'count'   => count($_SESSION['pos_cart']),
        'message' => 'Item not found (may have been already removed)'
    ]);
}
