<?php
/**
 * purchases/ajax_handler.php — Central AJAX Endpoint for Purchases Module
 * Phase 16: Full AJAX conversion
 *
 * Actions:
 *   get_suppliers     – Supplier search/list for select2
 *   get_purchase      – Fetch single purchase for edit/view
 *   save_purchase     – Create new purchase (AJAX)
 *   update_purchase   – Update existing purchase header fields
 *   delete_purchase   – Void a purchase (admin only)
 *   search_products   – Product search for cart
 *   get_product       – Single product JSON
 *   add_supplier      – Quick-add supplier
 *   get_list          – Paginated purchases list (HTML fragment)
 *   get_summary       – Summary stat cards (HTML fragment)
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/purchase_functions.php';
require_once __DIR__ . '/../includes/sync_engine.php';

header('Content-Type: application/json');

// ── Auth ───────────────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);

// Phase 21: per-action permission enforcement
$writeActions = ['save_purchase','update_purchase','add_supplier'];
$deleteActions = ['delete_purchase'];
if (in_array($action, $writeActions) && !canAdd('purchases') && !canEdit('purchases')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Permission denied.']); exit;
}
if (in_array($action, $deleteActions) && !isAdmin() && !canDelete('purchases')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Permission denied.']); exit;
}
if (!in_array($action, array_merge($writeActions,$deleteActions)) && !canView('purchases')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Permission denied.']); exit;
}

// ── Route ──────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Supplier search (for autocomplete / select) ────────────────────────
    case 'get_suppliers':
        $search = sanitize($_GET['q'] ?? '');
        $suppliers = getAllSuppliers($search);
        echo json_encode(['success' => true, 'data' => array_map(fn($s) => [
            'id'   => $s['id'],
            'text' => $s['supplier_name'] . ($s['phone'] ? ' (' . $s['phone'] . ')' : ''),
            'name' => $s['supplier_name'],
            'phone'=> $s['phone'] ?? '',
        ], $suppliers)]);
        break;

    // ── Quick-add supplier ─────────────────────────────────────────────────
    case 'add_supplier':
        requireMethodPost();
        verifyCSRF();
        $name  = trim($_POST['supplier_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if (!$name || !$phone) {
            jsonError('Name and phone are required.');
        }
        $id = addSupplier([
            'supplier_name'  => $name,
            'phone'          => $phone,
            'contact_person' => trim($_POST['contact_person'] ?? ''),
            'email'          => trim($_POST['email'] ?? ''),
            'address'        => trim($_POST['address'] ?? ''),
        ]);
        if ($id) {
            echo json_encode(['success' => true, 'id' => $id, 'name' => $name]);
        } else {
            jsonError('Failed to add supplier.');
        }
        break;

    // ── Get single product JSON ────────────────────────────────────────────
    case 'get_product':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('Product ID required');
        // Re-use existing get_product_json.php logic
        $product = _pdoOne(
            "SELECT p.*, 
                    COALESCE(SUM(b.remaining_quantity),0) AS total_stock,
                    (SELECT b2.purchase_price FROM batches b2 WHERE b2.product_id=p.id AND b2.remaining_quantity>0 ORDER BY b2.expiry_date ASC LIMIT 1) AS fifo_purchase_price,
                    (SELECT b2.selling_price FROM batches b2 WHERE b2.product_id=p.id AND b2.remaining_quantity>0 ORDER BY b2.expiry_date ASC LIMIT 1) AS fifo_sale_price,
                    (SELECT b2.units_per_pack FROM batches b2 WHERE b2.product_id=p.id AND b2.remaining_quantity>0 ORDER BY b2.expiry_date ASC LIMIT 1) AS fifo_units_per_pack
             FROM products p
             LEFT JOIN batches b ON b.product_id=p.id AND b.remaining_quantity>0
             WHERE p.id=:id
             GROUP BY p.id",
            [':id' => $id]
        );
        if ($product) {
            echo json_encode(['success' => true, 'data' => $product]);
        } else {
            jsonError('Product not found');
        }
        break;

    // ── Product search ─────────────────────────────────────────────────────
    case 'search_products':
        $q = sanitize($_GET['search'] ?? '');
        if (strlen($q) < 2) {
            echo json_encode(['success' => true, 'html' => '']);
            break;
        }
        // Return HTML fragment (same as search_product_purchase.php)
        ob_start();
        $products = _pdoAll(
            "SELECT p.*,
                    COALESCE(SUM(b.remaining_quantity),0) AS total_stock,
                    (SELECT b2.purchase_price FROM batches b2 WHERE b2.product_id=p.id AND b2.remaining_quantity>0 ORDER BY b2.expiry_date ASC LIMIT 1) AS fifo_purchase_price,
                    (SELECT b2.selling_price FROM batches b2 WHERE b2.product_id=p.id AND b2.remaining_quantity>0 ORDER BY b2.expiry_date ASC LIMIT 1) AS fifo_sale_price,
                    (SELECT b2.units_per_pack FROM batches b2 WHERE b2.product_id=p.id AND b2.remaining_quantity>0 ORDER BY b2.expiry_date ASC LIMIT 1) AS fifo_units_per_pack,
                    c.category_name
             FROM products p
             LEFT JOIN batches b ON b.product_id=p.id AND b.remaining_quantity>0
             LEFT JOIN categories c ON c.id=p.category_id
             WHERE p.status=1 AND (p.product_name LIKE :q OR p.product_code LIKE :q OR p.generic_name LIKE :q OR p.brand_name LIKE :q)
             GROUP BY p.id
             ORDER BY p.product_name ASC
             LIMIT 20",
            [':q' => "%$q%"]
        );
        header('Content-Type: text/html');
        foreach ($products as $prod) {
            $stock = (int)($prod['total_stock'] ?? 0);
            $stockHtml = $stock > 0
                ? "<span class='text-success'><i class='fas fa-check-circle'></i> {$stock} in stock</span>"
                : "<span class='text-danger'><i class='fas fa-times-circle'></i> Out of stock</span>";
            $pp = htmlspecialchars(json_encode($prod), ENT_QUOTES);
            echo "<div class='search-item' onclick='addToCartFromObject({$pp})'>";
            echo "<div class='pname'>" . htmlspecialchars($prod['product_name']) . "</div>";
            echo "<div class='pmeta'>";
            if ($prod['generic_name']) echo htmlspecialchars($prod['generic_name']) . ' &bull; ';
            echo htmlspecialchars($prod['product_code']);
            echo " &bull; " . $stockHtml;
            if ($prod['fifo_purchase_price']) echo " &bull; PP: Rs " . number_format($prod['fifo_purchase_price'], 2);
            echo "</div></div>";
        }
        if (empty($products)) {
            echo "<div class='search-item text-muted'><i class='fas fa-search me-2'></i>No products found for \"" . htmlspecialchars($q) . "\"</div>";
        }
        $html = ob_get_clean();
        echo json_encode(['success' => true, 'html' => $html]);
        break;

    // ── Get single purchase (for edit modal) ──────────────────────────────
    case 'get_purchase':
        requirePharmacist();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID required');
        $p = getPurchaseById($id);
        if (!$p) jsonError('Purchase not found');
        echo json_encode(['success' => true, 'data' => $p]);
        break;

    // ── Save new purchase (AJAX) ───────────────────────────────────────────
    case 'save_purchase':
        requirePharmacist();
        requireMethodPost();
        verifyCSRF();

        $supplierId    = (int)($_POST['supplier_id'] ?? 0);
        $purchaseDate  = trim($_POST['purchase_date'] ?? '');
        $paymentStatus = trim($_POST['payment_status'] ?? 'pending');
        $paidAmount    = (float)($_POST['paid_amount'] ?? 0);
        $subtotal      = (float)($_POST['subtotal'] ?? 0);
        $discount      = (float)($_POST['overall_discount'] ?? 0);
        $tax           = (float)($_POST['overall_tax'] ?? 0);
        $grandTotal    = (float)($_POST['grand_total'] ?? 0);
        $notes         = trim($_POST['notes'] ?? '');
        $itemsRaw      = $_POST['items'] ?? [];

        $errors = [];
        if (!$supplierId)    $errors[] = 'Please select a supplier.';
        if (!$purchaseDate)  $errors[] = 'Purchase date is required.';
        if (empty($itemsRaw)) $errors[] = 'Add at least one product to the cart.';

        $items = [];
        foreach ($itemsRaw as $idx => $row) {
            $n = $idx + 1;
            $productId     = (int)($row['product_id'] ?? 0);
            $unitsPerPack  = (int)($row['units_per_pack'] ?? 1);
            $qtyOfPacks    = (int)($row['qty_of_packs'] ?? 0);
            $reorderLevel  = (int)($row['reorder_level'] ?? 10);
            $purchasePrice = (float)($row['purchase_price'] ?? 0);
            $salePrice     = (float)($row['sale_price'] ?? 0);
            $expiryDate    = trim($row['expiry_date'] ?? '');
            $batchNo       = trim($row['batch_number'] ?? '');
            $itemDiscount  = (float)($row['item_discount'] ?? 0);
            $itemTax       = (float)($row['item_tax'] ?? 0);
            $totalCost     = (float)($row['total_cost'] ?? 0);

            if (!$productId)      $errors[] = "Row {$n}: Invalid product.";
            if ($unitsPerPack < 1) $errors[] = "Row {$n}: Units/Pack must be ≥ 1.";
            if ($qtyOfPacks < 1)  $errors[] = "Row {$n}: Quantity must be ≥ 1.";
            if ($purchasePrice <= 0) $errors[] = "Row {$n}: Purchase price must be > 0.";
            if ($salePrice <= 0)  $errors[] = "Row {$n}: Sale price must be > 0.";
            if (!$expiryDate || strtotime($expiryDate) < strtotime('tomorrow'))
                $errors[] = "Row {$n}: Expiry date must be a future date.";
            if ($salePrice > 0 && $purchasePrice > 0 && $salePrice <= $purchasePrice)
                $errors[] = "Row {$n}: Sale price must be greater than purchase price.";

            $items[] = [
                'product_id'        => $productId,
                'batch_number'      => $batchNo,
                'units_per_pack'    => $unitsPerPack,
                'quantity_of_packs' => $qtyOfPacks,
                'reorder_level'     => $reorderLevel,
                'purchase_price'    => $purchasePrice,
                'selling_price'     => $salePrice,
                'expiry_date'       => $expiryDate,
                'item_discount'     => $itemDiscount,
                'item_tax'          => $itemTax,
                'total_cost'        => $totalCost,
            ];
        }

        if (!empty($errors)) jsonError(implode(' ', $errors));

        $purchaseData = [
            'supplier_id'    => $supplierId,
            'purchase_date'  => $purchaseDate,
            'subtotal'       => $subtotal,
            'discount'       => $discount,
            'tax'            => $tax,
            'total'          => $grandTotal,
            'payment_status' => $paymentStatus,
            'paid_amount'    => $paidAmount,
            'notes'          => $notes,
        ];

        $purchaseId = addPurchase($purchaseData, $items);
        if ($purchaseId) {
            syncAfterPurchase($purchaseId, $paymentStatus, $paidAmount, $grandTotal);
            unset($_SESSION['csrf_token']);
            echo json_encode([
                'success'    => true,
                'message'    => 'Purchase saved successfully!',
                'purchase_id'=> $purchaseId,
                'redirect'   => 'list.php',
            ]);
        } else {
            jsonError('Failed to save purchase: ' . ($GLOBALS['addPurchaseLastError'] ?? 'Unknown error.'));
        }
        break;

    // ── Update purchase (edit payment/notes/date) ──────────────────────────
    case 'update_purchase':
        requirePharmacist();
        requireMethodPost();
        verifyCSRF();

        $id            = (int)($_POST['purchase_id'] ?? 0);
        $paymentStatus = trim($_POST['payment_status'] ?? '');
        $paidAmount    = (float)($_POST['paid_amount'] ?? 0);
        $notes         = trim($_POST['notes'] ?? '');
        $purchaseDate  = trim($_POST['purchase_date'] ?? '');

        if (!$id) jsonError('Purchase ID required');

        $p = _pdoOne("SELECT * FROM purchases WHERE id=:id", [':id' => $id]);
        if (!$p) jsonError('Purchase not found');

        $allowedStatuses = ['paid', 'partial', 'pending'];
        if (!in_array($paymentStatus, $allowedStatuses)) {
            $paymentStatus = $p['payment_status'];
        }

        $grandTotal = (float)$p['total'];
        $due = max(0, $grandTotal - $paidAmount);

        $updateParams = [
            ':id'     => $id,
            ':status' => $paymentStatus,
            ':paid'   => $paidAmount,
            ':due'    => $due,
            ':notes'  => $notes,
        ];

        $sql = "UPDATE purchases SET payment_status=:status, paid_amount=:paid, due_amount=:due, notes=:notes";
        if ($purchaseDate) {
            $sql .= ", purchase_date=:pdate";
            $updateParams[':pdate'] = $purchaseDate;
        }
        $sql .= " WHERE id=:id";

        _pdoRun($sql, $updateParams);

        // Sync cashbook if payment changed
        if ($paidAmount != (float)$p['paid_amount'] && $paidAmount > (float)$p['paid_amount']) {
            $diff = $paidAmount - (float)$p['paid_amount'];
            syncAfterPurchase($id, $paymentStatus, $diff, $grandTotal);
        }

        echo json_encode(['success' => true, 'message' => 'Purchase updated successfully.']);
        break;

    // ── Delete/Void purchase ───────────────────────────────────────────────
    case 'delete_purchase':
        requireAdmin();
        requireMethodPost();
        verifyCSRF();
        // Delegate to delete.php logic inline
        $purchaseId = (int)($_POST['purchase_id'] ?? 0);
        if (!$purchaseId) jsonError('Purchase ID required');
        // Forward to delete.php via include trick — re-read its logic
        // Actually, delete.php already handles JSON responses so just call it:
        require_once __DIR__ . '/delete_logic.php';
        voidPurchase($purchaseId, $userId);
        break;

    // ── Get paginated list (HTML fragment for AJAX refresh) ───────────────
    case 'get_list':
        requirePharmacist();
        header('Content-Type: text/html');
        // Include list.php in "fragment mode"
        $_GET['_ajax_fragment'] = '1';
        ob_start();
        include __DIR__ . '/list_fragment.php';
        $html = ob_get_clean();
        // Return as HTML (already set content-type above)
        echo $html;
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
}

// ── Helpers ────────────────────────────────────────────────────────────────
function requireMethodPost(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required']);
        exit;
    }
}

function verifyCSRF(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
        exit;
    }
}

function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}