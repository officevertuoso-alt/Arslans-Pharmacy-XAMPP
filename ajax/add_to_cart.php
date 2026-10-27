<?php
/**
 * ajax/add_to_cart.php — v8 FIFO MULTI-BATCH + PER-PIECE/PER-PACK
 *
 * FEATURES:
 *  - FIFO batch price (nearest expiry first)
 *  - Real total stock from all active non-expired batches
 *  - String index (uniqid) — never cast to int
 *  - Already-in-cart: check total qty (cart + new) against real stock
 *  - Stores batches[] in cart item for multi-batch FIFO split at sale time
 *  - Per-piece / per-pack mode: if sell_mode=pack, qty is in packs, price = pack price
 *  - FIFO split per-batch rows shown in invoice (handled at process_sale time)
 */
session_start();
require_once '../config/config.php';
require_once '../includes/product_functions.php';
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

$productId = (int)($_POST['product_id'] ?? 0);
$quantity  = max(1, (int)($_POST['quantity'] ?? 1));
$sellMode  = in_array($_POST['sell_mode'] ?? 'piece', ['piece','pack'])
             ? ($_POST['sell_mode'] ?? 'piece') : 'piece';

if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit;
}

// ── Product basics ────────────────────────────────────────────────────────
$db      = db();
$product = $db->getOne(
    "SELECT id, product_name, generic_name, brand_name, category_id,
            unit_type, units_per_pack, strength, dosage_form, size_value
     FROM products WHERE id = :id AND status = 1",
    [':id' => $productId]
);
if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

$unitsPerPack = max(1, (int)($product['units_per_pack'] ?? 1));

// Force piece mode if pack not applicable (units_per_pack <= 1)
if ($unitsPerPack <= 1) {
    $sellMode = 'piece';
}

// ── All active batches FIFO (nearest expiry first) ────────────────────────
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

if (empty($batches)) {
    echo json_encode(['success' => false, 'message' => 'Product is out of stock']);
    exit;
}

// Total real stock (in individual pieces)
$availableStock = array_sum(array_column($batches, 'remaining_quantity'));

// FIFO price = price of the batch that will be sold first (nearest expiry)
$fifoPricePerPiece = (float)$batches[0]['selling_price'];

// If sell_mode = pack, convert quantity to pieces & calculate pack price
if ($sellMode === 'pack' && $unitsPerPack > 1) {
    $qtyInPieces  = $quantity * $unitsPerPack;
    $priceToStore = $fifoPricePerPiece * $unitsPerPack;  // price per PACK shown in cart
    $displayMode  = 'pack';
} else {
    $qtyInPieces  = $quantity;
    $priceToStore = $fifoPricePerPiece;
    $displayMode  = 'piece';
    $sellMode     = 'piece';
}

// ── Initialize cart ───────────────────────────────────────────────────────
if (!isset($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}

// ── Already in cart? ──────────────────────────────────────────────────────
foreach ($_SESSION['pos_cart'] as $idx => &$cartItem) {
    if ((int)$cartItem['product_id'] === $productId && ($cartItem['sell_mode'] ?? 'piece') === $sellMode) {
        $alreadyQtyPieces = (int)$cartItem['qty_pieces'];
        $newQtyPieces     = $alreadyQtyPieces + $qtyInPieces;
        if ($newQtyPieces > $availableStock) {
            $remainPieces  = $availableStock - $alreadyQtyPieces;
            $unit          = $sellMode === 'pack' ? 'pack(s)' : 'unit(s)';
            $remainDisplay = $sellMode === 'pack' ? floor($remainPieces / $unitsPerPack) : $remainPieces;
            echo json_encode([
                'success' => false,
                'message' => 'Only ' . $remainDisplay . ' ' . $unit . ' more available (stock: ' . $availableStock . ' pieces)'
            ]);
            exit;
        }
        $displayQty                  = $sellMode === 'pack' ? (int)($newQtyPieces / $unitsPerPack) : $newQtyPieces;
        $cartItem['quantity']        = $displayQty;
        $cartItem['qty_pieces']      = $newQtyPieces;
        $cartItem['price']           = $priceToStore;
        $cartItem['price_per_piece'] = $fifoPricePerPiece;
        $cartItem['available_stock'] = $availableStock;
        $cartItem['batches']         = $batches;
        echo json_encode([
            'success'  => true,
            'action'   => 'updated',
            'index'    => $idx,
            'new_qty'  => $displayQty
        ]);
        exit;
    }
}
unset($cartItem);

// ── Qty check ─────────────────────────────────────────────────────────────
if ($qtyInPieces > $availableStock) {
    $unit      = $sellMode === 'pack' ? 'pack(s)' : 'unit(s)';
    $available = $sellMode === 'pack' ? floor($availableStock / $unitsPerPack) : $availableStock;
    echo json_encode([
        'success' => false,
        'message' => 'Only ' . $available . ' ' . $unit . ' available'
    ]);
    exit;
}

// ── Build detail text ─────────────────────────────────────────────────────
$isMed      = ((int)$product['category_id'] === 1);
$detailText = '';
if ($isMed) {
    $parts = [];
    if (!empty($product['strength']))    $parts[] = $product['strength'];
    if (!empty($product['dosage_form'])) $parts[] = $product['dosage_form'];
    $detailText = implode(' ', $parts);
} else {
    $detailText = trim($product['size_value'] ?? '');
}

// ── Add new cart item ─────────────────────────────────────────────────────
$index      = uniqid('ci_', true);
$displayQty = $sellMode === 'pack' ? (int)($qtyInPieces / $unitsPerPack) : $qtyInPieces;

$_SESSION['pos_cart'][$index] = [
    'index'           => $index,
    'product_id'      => $productId,
    'product_name'    => $product['product_name'],
    'generic_name'    => $product['generic_name']   ?? '',
    'brand_name'      => $product['brand_name']      ?? '',
    'category_id'     => (int)$product['category_id'],
    'unit_type'       => $product['unit_type']        ?? 'Pcs',
    'units_per_pack'  => $unitsPerPack,
    'strength'        => $product['strength']         ?? '',
    'dosage_form'     => $product['dosage_form']      ?? '',
    'size_value'      => $product['size_value']       ?? '',
    'detail_text'     => $detailText,
    'price'           => $priceToStore,        // price per displayed unit (piece or pack)
    'price_per_piece' => $fifoPricePerPiece,   // always stored for FIFO deduction reference
    'quantity'        => $displayQty,          // displayed qty (pieces or packs)
    'qty_pieces'      => $qtyInPieces,         // ACTUAL pieces to deduct from stock
    'sell_mode'       => $sellMode,            // 'piece' or 'pack'
    'item_discount'   => 0.0,
    'item_tax'        => 0.0,
    'available_stock' => $availableStock,      // total pieces in stock
    'batches'         => $batches,             // FIFO batch list for multi-batch split
];

echo json_encode([
    'success'       => true,
    'action'        => 'added',
    'index'         => $index,
    'price'         => $priceToStore,
    'stock'         => $availableStock,
    'sell_mode'     => $sellMode,
    'units_per_pack'=> $unitsPerPack,
]);