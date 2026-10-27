<?php
/**
 * suppliers/delete.php — Supplier Delete Handler
 * Phase 19: Supports both AJAX (JSON) and direct GET (redirect) calls.
 */
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/purchase_functions.php';

requireLogin();

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       || (isset($_POST['csrf_token']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH']));

if (!canDelete('suppliers')) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }
    setFlash('error', 'Permission denied.', 'danger');
    header('Location: list.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    // Check pending dues
    $dueRow = getOne(
        "SELECT COALESCE(SUM(due_amount), 0) AS total_due
         FROM purchases
         WHERE supplier_id = :id AND payment_status != 'paid' AND status != 'cancelled'",
        [':id' => $id]
    );
    $pendingDue = (float)($dueRow['total_due'] ?? 0);

    if ($pendingDue > 0.005) {
        $msg = 'Supplier delete nahi ho sakta — pehle Rs ' . number_format($pendingDue, 2) . ' ki pending dues clear karen.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }
        setFlash('error', $msg, 'danger');
    } else {
        $supplier = getOne("SELECT supplier_name, phone FROM suppliers WHERE id=:id", [':id' => $id]);
        deleteSupplier($id);
        if ($supplier) {
            logActivity('Supplier Deleted', "ID $id – {$supplier['supplier_name']} ({$supplier['phone']})", $_SESSION['user_id'] ?? 0);
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            $name = $supplier['supplier_name'] ?? "ID $id";
            echo json_encode(['success' => true, 'message' => "Supplier \"$name\" deleted"]);
            exit;
        }
        setFlash('success', 'Supplier deleted successfully!', 'success');
    }
}

header('Location: list.php');
exit;
