<?php
/**
 * ajax/update_product_image.php
 * Upload, update or remove product image.
 * Works for ALL products regardless of stock status.
 */
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('products', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
requirePharmacist();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// CSRF check
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$productId = (int)($_POST['product_id'] ?? 0);
$action    = $_POST['action'] ?? '';

if ($productId <= 0 || !in_array($action, ['upload', 'remove'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit;
}

$db = db();

// Fetch current image path
$db->query("SELECT image_path FROM products WHERE id = :id AND status = 1");
$db->bind(':id', $productId);
$row = $db->single();
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}
$currentImage = $row['image_path'] ?? '';

// Helper to delete old image file
function deleteImageFile($path) {
    if (empty($path)) return;
    $clean = str_replace('\\', '/', $path);
    $clean = ltrim(preg_replace('#^[A-Za-z]:/#', '', $clean), '/');
    $full  = BASE_PATH . '/' . $clean;
    if (file_exists($full)) @unlink($full);
}

if ($action === 'remove') {
    deleteImageFile($currentImage);
    $db->query("UPDATE products SET image_path = NULL WHERE id = :id");
    $db->bind(':id', $productId);
    $db->execute();
    echo json_encode(['success' => true]);
    exit;
}

// === UPLOAD ===
if (!isset($_FILES['product_image']) || $_FILES['product_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No image file received.']);
    exit;
}

$file     = $_FILES['product_image'];
$maxSize  = 2 * 1024 * 1024; // 2 MB
$allowed  = ['image/jpeg','image/png','image/webp','image/gif'];

if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'Image must be under 2 MB.']);
    exit;
}

// Validate MIME via finfo
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
if (!in_array($mimeType, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid image type. Use JPG, PNG or WEBP.']);
    exit;
}

$ext       = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mimeType];
$uploadDir = UPLOAD_PATH . 'products/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$filename  = 'product_' . $productId . '_' . time() . '.' . $ext;
$destPath  = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save image file.']);
    exit;
}

// Delete old image
deleteImageFile($currentImage);

$newRelPath = 'uploads/products/' . $filename;
$db->query("UPDATE products SET image_path = :path WHERE id = :id");
$db->bind(':path', $newRelPath);
$db->bind(':id', $productId);
$db->execute();

echo json_encode(['success' => true, 'image_path' => $newRelPath]);