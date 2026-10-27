<?php
/**
 * ajax/update_cart.php — v2
 * Updates quantity of a cart item (session-based).
 *
 * qty sent from JS is the DISPLAYED qty (pieces or packs).
 * We convert to pieces internally for stock check.
 * FIX: index is a uniqid() string — do NOT cast to int.
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

$index    = $_POST['index']    ?? '';
$quantity = max(1, (int)($_POST['quantity'] ?? 1));

if ($index === '' || !isset($_SESSION['pos_cart'][$index])) {
    echo json_encode(['success' => false, 'message' => 'Cart item not found']);
    exit();
}

$item         = $_SESSION['pos_cart'][$index];
$productId    = (int)$item['product_id'];
$sellMode     = $item['sell_mode'] ?? 'piece';
$unitsPerPack = max(1, (int)($item['units_per_pack'] ?? 1));

// Convert displayed qty to pieces
$qtyPieces = ($sellMode === 'pack') ? $quantity * $unitsPerPack : $quantity;

// Check real available stock from all active batches (in pieces)
$db  = db();
$row = $db->getOne(
    "SELECT COALESCE(SUM(remaining_quantity), 0) AS stock
     FROM batches
     WHERE product_id = :pid
       AND remaining_quantity > 0
       AND (expiry_date IS NULL OR expiry_date >= CURDATE())
       AND COALESCE(is_expired, 0) = 0",
    [':pid' => $productId]
);
$availablePieces = (int)($row['stock'] ?? 0);

if ($qtyPieces > $availablePieces) {
    $maxDisplay = ($sellMode === 'pack') ? floor($availablePieces / $unitsPerPack) : $availablePieces;
    echo json_encode([
        'success' => false,
        'message' => 'Only ' . $maxDisplay . ' ' . ($sellMode === 'pack' ? 'pack(s)' : 'units') . ' available'
    ]);
    exit();
}

// Refresh FIFO batches (in case stock changed)
$batches = $db->getAll(
    "SELECT id AS batch_id, batch_number, remaining_quantity,
            selling_price, expiry_date,
            DATEDIFF(expiry_date, CURDATE()) AS days_left
     FROM batches
     WHERE product_id = :pid
       AND remaining_quantity > 0
       AND COALESCE(is_expired, 0) = 0
       AND (expiry_date IS NULL OR expiry_date >= CURDATE())
     ORDER BY expiry_date ASC, id ASC",
    [':pid' => $productId]
);

$_SESSION['pos_cart'][$index]['quantity']        = $quantity;
$_SESSION['pos_cart'][$index]['qty_pieces']      = $qtyPieces;
$_SESSION['pos_cart'][$index]['available_stock'] = $availablePieces;
if (!empty($batches)) {
    $_SESSION['pos_cart'][$index]['batches'] = $batches;
    // Update FIFO price from nearest-expiry batch
    $fifoPrice = (float)$batches[0]['selling_price'];
    if ($sellMode === 'pack') {
        $_SESSION['pos_cart'][$index]['price']           = $fifoPrice * $unitsPerPack;
        $_SESSION['pos_cart'][$index]['price_per_piece'] = $fifoPrice;
    } else {
        $_SESSION['pos_cart'][$index]['price']           = $fifoPrice;
        $_SESSION['pos_cart'][$index]['price_per_piece'] = $fifoPrice;
    }
}

echo json_encode([
    'success'          => true,
    'available_pieces' => $availablePieces,
    'price'            => $_SESSION['pos_cart'][$index]['price'],
]);