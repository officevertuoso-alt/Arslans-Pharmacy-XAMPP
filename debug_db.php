<?php
require_once 'config/config.php';
$db = db();

echo "<pre>";

// Check tables
$tables = ['purchases', 'purchase_items', 'batches', 'suppliers', 'supplier_payments'];
foreach ($tables as $table) {
    try {
        $db->query("SELECT 1 FROM $table LIMIT 1");
        $db->execute();
        echo "✅ Table '$table' exists.\n";
        // Show columns
        $db->query("SHOW COLUMNS FROM $table");
        $cols = $db->resultSet();
        echo "   Columns: " . implode(', ', array_column($cols, 'Field')) . "\n";
    } catch (Exception $e) {
        echo "❌ Table '$table' missing or error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
echo "</pre>";
?>