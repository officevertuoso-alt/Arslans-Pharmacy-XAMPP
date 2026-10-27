<?php
/**
 * ajax/update_batch_sale_price.php
 * Update ONLY the selling_price of a single batch.
 * Purchase price, expiry, quantity — sab protected hain.
 */
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('sales', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
requirePharmacist();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$batchId   = (int)($_POST['batch_id'] ?? 0);
$salePrice = isset($_POST['sale_price']) ? (float)$_POST['sale_price'] : -1;

if ($batchId <= 0 || $salePrice < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit;
}

$db = db();

// Verify batch exists
$db->query("SELECT id FROM batches WHERE id = :id");
$db->bind(':id', $batchId);
$row = $db->single();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Batch not found.']);
    exit;
}

// Update only selling_price
$db->query("UPDATE batches SET selling_price = :price WHERE id = :id");
$db->bind(':price', round($salePrice, 2));
$db->bind(':id', $batchId);
$db->execute();

echo json_encode(['success' => true, 'selling_price' => round($salePrice, 2)]);