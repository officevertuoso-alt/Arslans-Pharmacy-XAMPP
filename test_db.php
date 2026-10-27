<?php
require_once 'config/config.php';

echo "<h2>Database Connection Test</h2>";

try {
    $db = db();
    $result = $db->single("SELECT 'Connection Successful!' as message");
    
    echo "<p style='color:green;'>✅ " . $result['message'] . "</p>";
    echo "<p>Database: " . DB_NAME . "</p>";
    echo "<p>Host: " . DB_HOST . "</p>";
    
    // Test query
    $products = getAll("SELECT COUNT(*) as total FROM products");
    echo "<p>Total Products: " . $products[0]['total'] . "</p>";
    
    $suppliers = getAll("SELECT COUNT(*) as total FROM suppliers");
    echo "<p>Total Suppliers: " . $suppliers[0]['total'] . "</p>";
    
    echo "<hr>";
    echo "<h3>Session Test:</h3>";
    $_SESSION['test'] = 'Working';
    echo "<p>Session ID: " . session_id() . "</p>";
    echo "<p>Session Variable: " . ($_SESSION['test'] ?? 'Not set') . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>