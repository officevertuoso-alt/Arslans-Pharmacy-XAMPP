<?php
/**
 * AJAX — Get Purchase Details for Purchase Return
 * File: ajax/get_purchase_details.php
 *
 * Method : GET
 * Params : purchase_invoice_no  (string)  — search by supplier invoice number, OR
 *          id                   (int)     — search by purchase ID
 * Returns: JSON — purchase header + line items with returnable_quantity
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('purchases', 'view')) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit;
}
require_once '../config/database.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthenticated.']);
    exit;
}

// ── Input — accept either purchase_invoice_no or id ──────────────────────────
$invoiceNo  = sanitize($_GET['purchase_invoice_no'] ?? '');
$purchaseId = (int)($_GET['id']                     ?? 0);

if ($invoiceNo === '' && $purchaseId <= 0) {
    echo json_encode(['success' => false, 'message' => 'purchase_invoice_no or id is required.']);
    exit;
}

// ── Fetch purchase header ─────────────────────────────────────────────────────
try {
    if ($invoiceNo !== '') {
        $purchase = getOne(
            'SELECT p.id, p.invoice_no, p.purchase_date,
                    p.total_amount, p.discount_amount, p.net_amount,
                    p.payment_status, p.notes,
                    s.name AS supplier_name, s.id AS supplier_id
             FROM   purchases p
             LEFT   JOIN suppliers s ON s.id = p.supplier_id
             WHERE  p.invoice_no = :inv',
            [':inv' => $invoiceNo]
        );
    } else {
        $purchase = getOne(
            'SELECT p.id, p.invoice_no, p.purchase_date,
                    p.total_amount, p.discount_amount, p.net_amount,
                    p.payment_status, p.notes,
                    s.name AS supplier_name, s.id AS supplier_id
             FROM   purchases p
             LEFT   JOIN suppliers s ON s.id = p.supplier_id
             WHERE  p.id = :id',
            [':id' => $purchaseId]
        );
    }
} catch (Exception $e) {
    error_log('get_purchase_details.php [header] error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}

if (!$purchase) {
    echo json_encode(['success' => false, 'message' => 'Purchase not found. Please check the invoice number.']);
    exit;
}

$foundPurchaseId = (int)$purchase['id'];

// ── Fetch purchase items ──────────────────────────────────────────────────────
try {
    $items = getAll(
        'SELECT
             pi.id                           AS purchase_item_id,
             pi.product_id,
             pr.name                         AS product_name,
             pr.generic_name,
             pi.variant_id,
             pi.batch_id,
             b.batch_number,
             b.expiry_date,
             pi.quantity,
             pi.unit_price,
             pi.bonus_quantity,
             pi.discount_amount              AS item_discount,
             pi.total_amount                 AS total
         FROM   purchase_items pi
         JOIN   products       pr ON pr.id = pi.product_id
         LEFT   JOIN batches   b  ON  b.id = pi.batch_id
         WHERE  pi.purchase_id = :purchase_id
         ORDER  BY pi.id ASC',
        [':purchase_id' => $foundPurchaseId]
    );
} catch (Exception $e) {
    error_log('get_purchase_details.php [items] error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load purchase items.']);
    exit;
}

// ── Calculate already_returned per purchase_item ──────────────────────────────
// One query for all return quantities — mapped by purchase_item_id
try {
    $returnRows = getAll(
        'SELECT pr.purchase_item_id, SUM(pr.quantity) AS returned_qty
         FROM   purchase_returns pr
         WHERE  pr.purchase_id = :purchase_id
           AND  pr.status     != :cancelled
         GROUP  BY pr.purchase_item_id',
        [':purchase_id' => $foundPurchaseId, ':cancelled' => 'cancelled']
    );
} catch (Exception $e) {
    error_log('get_purchase_details.php [returns] error: ' . $e->getMessage());
    // Non-fatal — default to 0 returned
    $returnRows = [];
}

// Map: purchase_item_id → already_returned quantity
$returnedMap = [];
foreach ($returnRows as $rr) {
    $returnedMap[(int)$rr['purchase_item_id']] = (int)$rr['returned_qty'];
}

// ── Build items array ─────────────────────────────────────────────────────────
$lineItems = [];
foreach ($items as $item) {
    $purchaseItemId  = (int)$item['purchase_item_id'];
    $qty             = (int)$item['quantity'];
    $alreadyReturned = $returnedMap[$purchaseItemId] ?? 0;
    $returnable      = max(0, $qty - $alreadyReturned);

    $lineItems[] = [
        'purchase_item_id'   => $purchaseItemId,
        'product_id'         => (int)$item['product_id'],
        'product_name'       => $item['product_name'],
        'generic_name'       => $item['generic_name'] ?? '',
        'variant_id'         => $item['variant_id'] ? (int)$item['variant_id'] : null,
        'batch_id'           => $item['batch_id']   ? (int)$item['batch_id']   : null,
        'batch_number'       => $item['batch_number'] ?? '—',
        'expiry_date'        => $item['expiry_date']
                                    ? formatDate($item['expiry_date'])
                                    : '—',
        'quantity'           => $qty,
        'bonus_quantity'     => (int)($item['bonus_quantity'] ?? 0),
        'unit_price'         => (float)$item['unit_price'],
        'item_discount'      => (float)($item['item_discount'] ?? 0),
        'total'              => (float)$item['total'],
        'already_returned'   => $alreadyReturned,
        'returnable_quantity'=> $returnable,
    ];
}

// ── Build response ────────────────────────────────────────────────────────────
echo json_encode([
    'success'  => true,
    'purchase' => [
        'id'              => $foundPurchaseId,
        'invoice_no'      => $purchase['invoice_no'],
        'supplier_id'     => $purchase['supplier_id']   ? (int)$purchase['supplier_id'] : null,
        'supplier_name'   => $purchase['supplier_name'] ?? 'Unknown Supplier',
        'purchase_date'   => formatDate($purchase['purchase_date']),
        'total_amount'    => (float)$purchase['total_amount'],
        'discount_amount' => (float)($purchase['discount_amount'] ?? 0),
        'net_amount'      => (float)$purchase['net_amount'],
        'payment_status'  => $purchase['payment_status'],
        'notes'           => $purchase['notes'] ?? '',
        'items'           => $lineItems,
    ],
]);