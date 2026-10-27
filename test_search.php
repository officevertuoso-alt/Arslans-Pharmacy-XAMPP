<?php
require_once 'config/config.php';

$search = 'nims';

echo "<h2>Testing Search for: $search</h2>";

$db = db();

// Direct query
$sql = "SELECT id, product_name, product_code, generic_name, brand_name 
        FROM products 
        WHERE status = 1 
        AND (product_name LIKE :search OR generic_name LIKE :search OR brand_name LIKE :search)";
$db->query($sql);
$db->bind(':search', "%$search%");
$results = $db->resultSet();

echo "<h3>Search Results:</h3>";
if (count($results) > 0) {
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>ID</th><th>Product Name</th><th>Code</th><th>Generic</th><th>Brand</th></tr>";
    foreach ($results as $r) {
        echo "<tr>";
        echo "<td>" . $r['id'] . "</td>";
        echo "<td>" . htmlspecialchars($r['product_name']) . "</td>";
        echo "<td>" . htmlspecialchars($r['product_code']) . "</td>";
        echo "<td>" . htmlspecialchars($r['generic_name']) . "</td>";
        echo "<td>" . htmlspecialchars($r['brand_name']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red'>No products found matching '$search'</p>";
}

// Show all products
echo "<h3>All Products in Database:</h3>";
$db->query("SELECT id, product_name FROM products WHERE status = 1");
$all = $db->resultSet();
echo "<ul>";
foreach ($all as $p) {
    echo "<li>ID: " . $p['id'] . " - " . htmlspecialchars($p['product_name']) . "</li>";
}
echo "</ul>";
?>