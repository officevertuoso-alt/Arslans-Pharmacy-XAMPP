<?php
/**
 * AJAX — Get Sale Details for Sale Return
 * File: ajax/get_sale_details.php
 *
 * Method : GET
 * Params : invoice_no  (string)  — search by invoice number, OR
 *          id          (int)     — search by sale ID
 * Returns: JSON — sale header + line items with returnable_quantity
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if (!canDo('sales', 'view')) {
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

// ── Input — accept either invoice_no or id ────────────────────────────────────
$invoiceNo = sanitize($_GET['invoice_no'] ?? '');
$saleId    = (int)($_GET['id']           ?? 0);

if ($invoiceNo === '' && $saleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'invoice_no or id is required.']);
    exit;
}

// ── Fetch sale header ─────────────────────────────────────────────────────────
try {
    if ($invoiceNo !== '') {
        $sale = getOne(
            'SELECT s.id, s.invoice_no, s.customer_name, s.sale_date,
                    s.total_amount, s.discount_amount, s.net_amount,
                    s.payment_status, s.payment_method, s.notes
             FROM   sales s
             WHERE  s.invoice_no = :inv',
            [':inv' => $invoiceNo]
        );
    } else {
        $sale = getOne(
            'SELECT s.id, s.invoice_no, s.customer_name, s.sale_date,
                    s.total_amount, s.discount_amount, s.net_amount,
                    s.payment_status, s.payment_method, s.notes
             FROM   sales s
             WHERE  s.id = :id',
            [':id' => $saleId]
        );
    }
} catch (Exception $e) {
    error_log('get_sale_details.php [header] error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}

if (!$sale) {
    echo json_encode(['success' => false, 'message' => 'Sale not found. Please check the invoice number.']);
    exit;
}

$foundSaleId = (int)$sale['id'];

// ── Fetch sale items ──────────────────────────────────────────────────────────
try {
    $items = getAll(
        'SELECT
             si.id                           AS sale_item_id,
             si.product_id,
             p.name                          AS product_name,
             p.generic_name,
             si.variant_id,
             si.batch_id,
             b.batch_number,
             si.quantity,
             si.unit_price,
             si.discount_amount              AS item_discount,
             si.total_amount                 AS total
         FROM   sale_items si
         JOIN   products   p  ON  p.id = si.product_id
         LEFT   JOIN batches b ON  b.id = si.batch_id
         WHERE  si.sale_id = :sale_id
         ORDER  BY si.id ASC',
        [':sale_id' => $foundSaleId]
    );
} catch (Exception $e) {
    error_log('get_sale_details.php [items] error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load sale items.']);
    exit;
}

// ── Calculate already_returned per sale_item ──────────────────────────────────
// Pull all return quantities for this sale in one query, then map by sale_item_id
try {
    $returnRows = getAll(
        'SELECT sr.sale_item_id, SUM(sr.quantity) AS returned_qty
         FROM   sale_returns sr
         WHERE  sr.sale_id   = :sale_id
           AND  sr.status   != :cancelled
         GROUP  BY sr.sale_item_id',
        [':sale_id' => $foundSaleId, ':cancelled' => 'cancelled']
    );
} catch (Exception $e) {
    error_log('get_sale_details.php [returns] error: ' . $e->getMessage());
    // Non-fatal — continue with 0 returned quantities
    $returnRows = [];
}

// Map: sale_item_id → already_returned quantity
$returnedMap = [];
foreach ($returnRows as $rr) {
    $returnedMap[(int)$rr['sale_item_id']] = (int)$rr['returned_qty'];
}

// ── Build items array ─────────────────────────────────────────────────────────
$lineItems = [];
foreach ($items as $item) {
    $saleItemId      = (int)$item['sale_item_id'];
    $qty             = (int)$item['quantity'];
    $alreadyReturned = $returnedMap[$saleItemId] ?? 0;
    $returnable      = max(0, $qty - $alreadyReturned);

    $lineItems[] = [
        'sale_item_id'       => $saleItemId,
        'product_id'         => (int)$item['product_id'],
        'product_name'       => $item['product_name'],
        'generic_name'       => $item['generic_name'] ?? '',
        'variant_id'         => $item['variant_id'] ? (int)$item['variant_id'] : null,
        'batch_id'           => $item['batch_id']   ? (int)$item['batch_id']   : null,
        'batch_number'       => $item['batch_number'] ?? '—',
        'quantity'           => $qty,
        'unit_price'         => (float)$item['unit_price'],
        'item_discount'      => (float)($item['item_discount'] ?? 0),
        'total'              => (float)$item['total'],
        'already_returned'   => $alreadyReturned,
        'returnable_quantity'=> $returnable,
    ];
}

// ── Build response ────────────────────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'sale'    => [
        'id'             => $foundSaleId,
        'invoice_no'     => $sale['invoice_no'],
        'customer_name'  => $sale['customer_name'] ?: 'Walk-in Customer',
        'sale_date'      => formatDate($sale['sale_date']),
        'total_amount'   => (float)$sale['total_amount'],
        'discount_amount'=> (float)($sale['discount_amount'] ?? 0),
        'net_amount'     => (float)$sale['net_amount'],
        'payment_status' => $sale['payment_status'],
        'payment_method' => $sale['payment_method'] ?? '',
        'notes'          => $sale['notes']           ?? '',
        'items'          => $lineItems,
    ],
]);