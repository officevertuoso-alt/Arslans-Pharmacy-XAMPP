<?php
/**
 * ajax/save_product.php
 * Handles Add New Product and Edit Product via AJAX.
 *
 * POST params:
 *   action       = 'add' | 'edit'
 *   product_id   = int (required for edit)
 *   csrf_token
 *   category_id, product_name, brand_name, unit_type, units_per_pack
 *   generic_name, dosage_form, strength  (medicine)
 *   size_value                            (non-medicine)
 *   barcode      (optional)
 *   product_image  (file, optional)
 *   remove_image = 1 (optional, edit only)
 *
 * Returns JSON: { success, message, product_id? }
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('products', 'add')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
require_once '../includes/product_functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requirePharmacist();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

$action    = trim($_POST['action'] ?? '');
$productId = (int)($_POST['product_id'] ?? 0);

if (!in_array($action, ['add', 'edit'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

// ── Validate inputs ─────────────────────────────────────────────────────────
$errors = [];
$cat          = (int)($_POST['category_id']   ?? 1);
$productName  = trim($_POST['product_name']   ?? '');
$brandName    = trim($_POST['brand_name']     ?? '');
$unitType     = trim($_POST['unit_type']      ?? '');
$unitsPerPack = (int)($_POST['units_per_pack'] ?? 0);
$barcode      = trim($_POST['barcode']        ?? '') ?: null;
$genericName  = trim($_POST['generic_name']   ?? '');
$dosageForm   = trim($_POST['dosage_form']    ?? '');
$strength     = trim($_POST['strength']       ?? '') ?: null;
$sizeValue    = trim($_POST['size_value']     ?? '') ?: null;

if (empty($productName))  $errors[] = 'Product name is required.';
if (empty($brandName))    $errors[] = 'Brand name is required.';
if (empty($unitType))     $errors[] = 'Unit type is required.';
if ($unitsPerPack < 1)    $errors[] = 'Units per pack must be at least 1.';

if ($cat === 1) {
    if (empty($genericName)) $errors[] = 'Generic name is required for medicines.';
    if (empty($dosageForm))  $errors[] = 'Dosage form is required for medicines.';
}

if ($action === 'edit' && $productId <= 0) {
    $errors[] = 'Invalid product ID for editing.';
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// ── For edit: check product exists and is editable ──────────────────────────
$existingProduct = null;
if ($action === 'edit') {
    try {
        $existingProduct = _pdoOne(
            "SELECT * FROM products WHERE id = :id AND status != -1 LIMIT 1",
            [':id' => $productId]
        );
    } catch (\Throwable $e) {}

    if (!$existingProduct) {
        echo json_encode(['success' => false, 'message' => 'Product not found.']);
        exit;
    }

    // Check active stock (edit blocked if active stock exists)
    try {
        $activeRow = _pdoOne(
            "SELECT COUNT(*) AS cnt FROM batches
             WHERE product_id = :pid
               AND remaining_quantity > 0
               AND COALESCE(is_expired, 0) = 0
               AND (expiry_date IS NULL OR expiry_date >= CURDATE())",
            [':pid' => $productId]
        );
        $activeCount = (int)($activeRow['cnt'] ?? 0);
    } catch (\Throwable $e) {
        $activeCount = 0;
    }

    if ($activeCount > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot edit "' . htmlspecialchars($existingProduct['product_name']) . '" — it has active stock. Sell or expire all stock first.'
        ]);
        exit;
    }
}

// ── Handle image upload ──────────────────────────────────────────────────────
$imagePath = ($action === 'edit') ? ($existingProduct['image_path'] ?? null) : null;

if ($action === 'edit' && isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {
    if (!empty($imagePath)) {
        $fullPath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $imagePath), '/');
        if (file_exists($fullPath)) @unlink($fullPath);
    }
    $imagePath = null;
} elseif (!empty($_FILES['product_image']['name']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 2 * 1024 * 1024;
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($_FILES['product_image']['tmp_name']);
    $size    = $_FILES['product_image']['size'];

    if (!in_array($mime, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Image must be JPG, PNG, GIF, or WebP.']);
        exit;
    }
    if ($size > $maxSize) {
        echo json_encode(['success' => false, 'message' => 'Image must be smaller than 2MB.']);
        exit;
    }

    $extMap   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext      = $extMap[$mime];
    $prefix   = ($action === 'edit') ? ('product_' . $productId . '_') : 'product_';
    $filename = $prefix . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $uploadDir = defined('UPLOAD_PATH') ? UPLOAD_PATH . 'products/' : dirname(__DIR__) . '/uploads/products/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $dest = $uploadDir . $filename;

    if (move_uploaded_file($_FILES['product_image']['tmp_name'], $dest)) {
        // Remove old image on edit
        if ($action === 'edit' && !empty($imagePath)) {
            $oldFull = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $imagePath), '/');
            if (file_exists($oldFull)) @unlink($oldFull);
        }
        $imagePath = 'uploads/products/' . $filename;
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to upload image. Please try again.']);
        exit;
    }
}

// ── Save / Update ────────────────────────────────────────────────────────────
if ($action === 'add') {
    $data = [
        'category_id'    => $cat,
        'product_name'   => $productName,
        'brand_name'     => $brandName,
        'unit_type'      => $unitType,
        'units_per_pack' => $unitsPerPack,
        'barcode'        => $barcode,
        'image_path'     => $imagePath,
    ];
    if ($cat === 1) {
        $data['generic_name'] = $genericName;
        $data['dosage_form']  = $dosageForm;
        $data['strength']     = $strength;
    } else {
        $data['size_value'] = $sizeValue;
    }

    $newId = saveProduct($data);

    if ($newId && $newId > 0) {
        if (function_exists('logActivity')) {
            logActivity('add_product', "Added product #{$newId}: {$productName}");
        }
        echo json_encode([
            'success'    => true,
            'message'    => "Product \"{$productName}\" added successfully!",
            'product_id' => $newId,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save product. Please try again.']);
    }

} else {
    // edit
    $updateData = [
        ':category_id'    => $cat,
        ':product_name'   => $productName,
        ':brand_name'     => $brandName,
        ':unit_type'      => $unitType,
        ':units_per_pack' => $unitsPerPack,
        ':barcode'        => $barcode,
        ':image_path'     => $imagePath,
        ':generic_name'   => ($cat === 1) ? $genericName : null,
        ':dosage_form'    => ($cat === 1) ? $dosageForm  : null,
        ':strength'       => ($cat === 1) ? $strength    : null,
        ':size_value'     => ($cat !== 1) ? $sizeValue   : null,
        ':id'             => $productId,
    ];

    try {
        _pdoRun(
            "UPDATE products SET
                category_id    = :category_id,
                product_name   = :product_name,
                brand_name     = :brand_name,
                unit_type      = :unit_type,
                units_per_pack = :units_per_pack,
                barcode        = :barcode,
                image_path     = :image_path,
                generic_name   = :generic_name,
                dosage_form    = :dosage_form,
                strength       = :strength,
                size_value     = :size_value,
                updated_at     = NOW()
             WHERE id = :id",
            $updateData
        );

        if (function_exists('logActivity')) {
            logActivity('product_updated', "Edited product #{$productId}: {$productName}");
        }

        echo json_encode([
            'success'    => true,
            'message'    => "Product \"{$productName}\" updated successfully!",
            'product_id' => $productId,
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update product: ' . $e->getMessage()]);
    }
}
?>
