<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
requireAdmin();

echo "<h2>Image Path Fixer</h2>";

$db = db();
$db->query("SELECT id, product_name, image_path FROM products WHERE image_path IS NOT NULL AND image_path != ''");
$products = $db->resultSet();

if (empty($products)) {
    echo "<p>No products have image_path set.</p>";
} else {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Product</th><th>Old Path</th><th>New Path</th><th>File Exists?</th></tr>";
    foreach ($products as $p) {
        $old = $p['image_path'];
        // Clean path
        $new = str_replace('\\', '/', $old);
        $new = preg_replace('#^[A-Za-z]:/#', '', $new);
        $new = preg_replace('#^(\.\./|\./)#', '', $new);
        $new = ltrim($new, '/');
        if (strpos($new, 'uploads/products/') === false && strpos($new, 'products/') !== false) {
            $new = 'uploads/' . $new;
        }
        if (preg_match('#^[A-Za-z]:[/\\\\]#', $new) || strpos($new, 'xampp') !== false) {
            $new = null;
        }
        
        // Check if file exists
        $fileExists = false;
        if ($new) {
            $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/pharmacy_software/' . $new;
            $fullPath = str_replace('\\', '/', $fullPath);
            $fileExists = file_exists($fullPath);
        }
        
        echo "<tr>";
        echo "<td>{$p['id']}</td>";
        echo "<td>" . htmlspecialchars($p['product_name']) . "</td>";
        echo "<td>" . htmlspecialchars($old) . "</td>";
        echo "<td>" . ($new ? htmlspecialchars($new) : 'NULL') . "</td>";
        echo "<td style='color:" . ($fileExists ? 'green' : 'red') . "'>" . ($fileExists ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
        
        if ($new !== $old && $new !== null) {
            $db->query("UPDATE products SET image_path = :new WHERE id = :id");
            $db->bind(':new', $new);
            $db->bind(':id', $p['id']);
            $db->execute();
        }
    }
    echo "</table>";
}
echo "<p><a href='products/list.php'>Go to Products List</a></p>";
?>