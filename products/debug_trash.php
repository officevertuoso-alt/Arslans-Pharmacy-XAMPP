<?php
/**
 * DEBUG FILE — products/debug_trash.php
 * 
 * Rakhne ki jagah: products/debug_trash.php  (same folder as list.php)
 * Browser se open karo: http://localhost/pharmacy_software/products/debug_trash.php
 * 
 * Yeh file exact error batayegi. Baad mein DELETE kar dena.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Trash Debug</h2><pre>";

// Step 1: config load
echo "Step 1: Loading config...\n";
try {
    require_once '../config/config.php';
    echo "  config.php loaded OK\n";
} catch (Throwable $e) {
    echo "  FAILED: " . $e->getMessage() . "\n"; die();
}

// Step 2: product_functions
echo "\nStep 2: Loading product_functions.php...\n";
$pf = '../includes/product_functions.php';
if (!file_exists($pf)) {
    echo "  FILE NOT FOUND at: $pf\n";
    echo "  Trying alternate path...\n";
    $pf2 = __DIR__ . '/../includes/product_functions.php';
    echo "  Full path tried: $pf2\n";
    echo "  Exists: " . (file_exists($pf2) ? 'YES' : 'NO') . "\n";
} else {
    echo "  File found OK\n";
    try {
        require_once $pf;
        echo "  product_functions.php loaded OK\n";
    } catch (Throwable $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
}

// Step 3: DB connection
echo "\nStep 3: DB connection...\n";
try {
    $db = db();
    echo "  db() OK\n";
} catch (Throwable $e) {
    echo "  FAILED: " . $e->getMessage() . "\n"; die();
}

// Step 4: Check products table status column
echo "\nStep 4: Checking products table...\n";
try {
    $db->query("SELECT COUNT(*) as total FROM products");
    $r = $db->single();
    echo "  Total rows in products: " . $r['total'] . "\n";

    $db->query("SELECT COUNT(*) as active FROM products WHERE status = 1");
    $r = $db->single();
    echo "  Active (status=1): " . $r['active'] . "\n";

    $db->query("SELECT COUNT(*) as trashed FROM products WHERE status = 0");
    $r = $db->single();
    echo "  Trash (status=0): " . $r['trashed'] . "\n";
} catch (Throwable $e) {
    echo "  FAILED: " . $e->getMessage() . "\n";
}

// Step 5: Try getTrashedProducts()
echo "\nStep 5: Calling getTrashedProducts()...\n";
if (!function_exists('getTrashedProducts')) {
    echo "  FUNCTION DOES NOT EXIST — product_functions.php is OLD version\n";
    echo "  You must replace includes/product_functions.php with the new file!\n";

    // Fallback: try raw query
    echo "\n  Trying raw query fallback...\n";
    try {
        $db->query("SELECT id, product_name, product_code, category_id, brand_name,
                           generic_name, strength, dosage_form, size_value, unit_type,
                           sale_price, purchase_price, status
                    FROM products WHERE status = 0 ORDER BY product_name ASC");
        $rows = $db->resultSet();
        echo "  Raw query OK — found " . count($rows) . " trashed products\n";
        if (count($rows)) {
            echo "  First trashed product: " . $rows[0]['product_name'] . "\n";
        }
    } catch (Throwable $e) {
        echo "  Raw query FAILED: " . $e->getMessage() . "\n";
    }
} else {
    try {
        $trashed = getTrashedProducts();
        echo "  OK — found " . count($trashed) . " trashed products\n";
        foreach ($trashed as $p) {
            echo "    - [{$p['id']}] {$p['product_name']}\n";
        }
    } catch (Throwable $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
}

// Step 6: Check inventory_all_products.php path
echo "\nStep 6: Checking inventory_all_products.php...\n";
$inv = __DIR__ . '/inventory_all_products.php';
echo "  Path: $inv\n";
echo "  Exists: " . (file_exists($inv) ? 'YES' : 'NO') . "\n";

// Step 7: Simulate the AJAX call
echo "\nStep 7: Simulating AJAX call to inventory_all_products.php?trash=1\n";
$url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/inventory_all_products.php?trash=1';
echo "  URL: $url\n";
$response = @file_get_contents($url);
if ($response === false) {
    echo "  Could not fetch (file_get_contents disabled or URL wrong)\n";
} else {
    $json = json_decode($response, true);
    if ($json === null) {
        echo "  Response is NOT valid JSON:\n";
        echo "  " . substr($response, 0, 500) . "\n";
    } else {
        echo "  Valid JSON returned — " . count($json) . " items\n";
    }
}

echo "\n=== DONE ===\n";
echo "</pre>";