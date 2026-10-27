<?php
/**
 * products/trash_edit_handler.php
 *
 * Yeh file trash se edit karne ke baad call hoti hai.
 * edit.php save karne ke baad yahan redirect karta hai jab from_trash=1 ho.
 *
 * Usage (edit.php mein save success ke baad add karo):
 *   if (!empty($_POST['from_trash'])) {
 *       header('Location: trash_edit_handler.php?id=' . $productId);
 *       exit;
 *   }
 *
 * Yeh script:
 *   1. Product ka status = 1 set karta hai (restore)
 *   2. Activity log karta hai
 *   3. list.php pe redirect karta hai with success message
 */
require_once '../config/config.php';
require_once '../includes/product_functions.php';
requirePharmacist();

$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
    $db = db();
    $db->query("SELECT product_name FROM products WHERE id = :id");
    $db->bind(':id', $id);
    $product = $db->single();

    if ($product) {
        restoreProduct($id);
        logActivity('restore_after_edit', "Restored product #{$id} after edit from trash: {$product['product_name']}");
        $_SESSION['success'] = "Product '{$product['product_name']}' updated and restored to active list.";
    }
}

header('Location: list.php');
exit;