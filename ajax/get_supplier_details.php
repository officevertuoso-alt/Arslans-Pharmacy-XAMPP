<?php
/**
 * ajax/get_supplier_details.php
 * Returns supplier profile + outstanding balance for AJAX calls.
 * Used by: purchases/add.php (auto-fill supplier info) and any form
 * that selects a supplier from a dropdown.
 *
 * GET params:
 *   ?id=SUPPLIER_ID   OR  ?supplier_id=SUPPLIER_ID
 *
 * Returns JSON: { success, supplier: {...} }
 */
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';

requireLogin();
header('Content-Type: application/json');

$supplierId = (int)($_GET['id'] ?? $_GET['supplier_id'] ?? 0);

if (!$supplierId) {
    echo json_encode(['success' => false, 'message' => 'Supplier ID is required']);
    exit;
}

$db = db();

try {
    $supplier = $db->getOne(
        "SELECT
             s.id,
             s.supplier_name,
             s.company_name,
             s.phone,
             s.email,
             s.address,
             s.current_balance,
             s.status,
             COUNT(p.id)                        AS total_purchases,
             COALESCE(SUM(p.total), 0)          AS total_billed,
             COALESCE(SUM(p.paid_amount), 0)    AS total_paid,
             COALESCE(SUM(p.due_amount), 0)     AS total_due
         FROM suppliers s
         LEFT JOIN purchases p ON p.supplier_id = s.id
         WHERE s.id = :id
         GROUP BY s.id",
        [':id' => $supplierId]
    );

    if (!$supplier) {
        echo json_encode(['success' => false, 'message' => 'Supplier not found']);
        exit;
    }

    if ($supplier['status'] != 1) {
        echo json_encode(['success' => false, 'message' => 'Supplier is inactive']);
        exit;
    }

    // Recent unpaid purchases
    $unpaid = $db->getAll(
        "SELECT
             id,
             purchase_invoice_no,
             purchase_date,
             total,
             paid_amount,
             due_amount,
             payment_status
         FROM purchases
         WHERE supplier_id = :sid
           AND payment_status != 'paid'
           AND due_amount > 0
         ORDER BY purchase_date DESC
         LIMIT 5",
        [':sid' => $supplierId]
    );

    echo json_encode([
        'success'  => true,
        'supplier' => [
            'id'              => (int)$supplier['id'],
            'supplier_name'   => $supplier['supplier_name'],
            'company_name'    => $supplier['company_name'],
            'phone'           => $supplier['phone'],
            'email'           => $supplier['email'],
            'address'         => $supplier['address'],
            'current_balance' => (float)$supplier['current_balance'],
            'total_purchases' => (int)$supplier['total_purchases'],
            'total_billed'    => (float)$supplier['total_billed'],
            'total_paid'      => (float)$supplier['total_paid'],
            'total_due'       => (float)$supplier['total_due'],
        ],
        'unpaid_purchases' => $unpaid,
    ]);

} catch (Exception $e) {
    error_log('get_supplier_details error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
exit;
