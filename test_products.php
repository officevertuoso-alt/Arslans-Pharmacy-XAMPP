<?php
require_once 'config/config.php';

$db = db();
$db->query("SELECT id, product_name, product_code, status FROM products LIMIT 10");
$products = $db->resultSet();

echo "<h2>Products in Database:</h2>";
if (count($products) > 0) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>ID</th><th>Product Name</th><th>Product Code</th><th>Status</th></tr>";
    foreach ($products as $p) {
        echo "<tr>";
        echo "<td>" . $p['id'] . "</td>";
        echo "<td>" . htmlspecialchars($p['product_name']) . "</td>";
        echo "<td>" . htmlspecialchars($p['product_code']) . "</td>";
        echo "<td>" . ($p['status'] == 1 ? 'Active' : 'Inactive') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red'>No products found! Please add products first.</p>";
    echo "<a href='products/add.php'>Click here to add a product</a>";
}
?>