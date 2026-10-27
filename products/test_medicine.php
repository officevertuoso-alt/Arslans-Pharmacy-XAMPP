<?php
require_once '../config/config.php';
require_once '../includes/product_functions.php';

// Simple test data for a medicine product
$data = [
    'product_code' => '',
    'product_name' => 'Test Medicine ' . time(),
    'category_id' => 1,
    'brand_name' => 'Test Brand',
    'generic_name' => 'Test Generic',
    'strength' => '500mg',
    'dosage_form' => 'Tablet',
    'unit_type' => 'Strip',
    'units_per_pack' => 10,
    'quantity_of_packs' => 1,
    'purchase_price' => 10,
    'sale_price' => 15,
    'reorder_level' => 10,
    'is_variant' => 0,
    'status' => 1
];

echo "<pre>";
echo "Attempting to insert product...\n";

// Try direct PDO insert to see the error
try {
    $db = db();
    $sql = "INSERT INTO products (product_name, category_id, brand_name, generic_name, strength, dosage_form, unit_type, units_per_pack, quantity_of_packs, purchase_price, sale_price, reorder_level, status) 
            VALUES (:name, :cat, :brand, :generic, :strength, :dosage, :unit, :units, :qty, :purchase, :sale, :reorder, :status)";
    $db->query($sql);
    $db->bind(':name', $data['product_name']);
    $db->bind(':cat', $data['category_id']);
    $db->bind(':brand', $data['brand_name']);
    $db->bind(':generic', $data['generic_name']);
    $db->bind(':strength', $data['strength']);
    $db->bind(':dosage', $data['dosage_form']);
    $db->bind(':unit', $data['unit_type']);
    $db->bind(':units', $data['units_per_pack']);
    $db->bind(':qty', $data['quantity_of_packs']);
    $db->bind(':purchase', $data['purchase_price']);
    $db->bind(':sale', $data['sale_price']);
    $db->bind(':reorder', $data['reorder_level']);
    $db->bind(':status', $data['status']);
    
    if ($db->execute()) {
        echo "SUCCESS! Inserted ID: " . $db->lastInsertId();
    } else {
        echo "FAILED: Execute returned false";
    }
} catch (PDOException $e) {
    echo "PDO Exception: " . $e->getMessage();
}
echo "</pre>";
?>