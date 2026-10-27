<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h3>Step 1: config.php</h3>";
require_once '../config/config.php';
echo "✅ config.php OK<br>";

echo "<h3>Step 2: purchase_functions.php</h3>";
if (file_exists('../includes/purchase_functions.php')) {
    require_once '../includes/purchase_functions.php';
    echo "✅ purchase_functions.php OK<br>";
} else {
    echo "❌ purchase_functions.php NOT FOUND<br>";
}

echo "<h3>Step 3: sync_engine.php</h3>";
if (file_exists('../includes/sync_engine.php')) {
    require_once '../includes/sync_engine.php';
    echo "✅ sync_engine.php OK<br>";
} else {
    echo "⚠️ sync_engine.php not found (skip)<br>";
}

echo "<h3>Step 4: Functions check</h3>";
echo "_getPDO exists: " . (function_exists('_getPDO') ? '✅' : '❌') . "<br>";
echo "_pdoOne exists: " . (function_exists('_pdoOne') ? '✅' : '❌') . "<br>";
echo "_pdoAll exists: " . (function_exists('_pdoAll') ? '✅' : '❌') . "<br>";
echo "requireLogin exists: " . (function_exists('requireLogin') ? '✅' : '❌') . "<br>";

echo "<h3>Step 5: DB Test</h3>";
try {
    $row = _pdoOne("SELECT COUNT(*) as cnt FROM suppliers");
    echo "✅ DB query OK — Suppliers count: " . $row['cnt'] . "<br>";
} catch (Exception $e) {
    echo "❌ DB Error: " . $e->getMessage() . "<br>";
}

echo "<h3>Step 6: sidebar.php</h3>";
if (file_exists('../includes/sidebar.php')) {
    echo "✅ sidebar.php exists<br>";
} else {
    echo "❌ sidebar.php NOT FOUND<br>";
}

echo "<h3>Step 7: navbar.php</h3>";
if (file_exists('../includes/navbar.php')) {
    echo "✅ navbar.php exists<br>";
} else {
    echo "❌ navbar.php NOT FOUND<br>";
}

echo "<hr><strong>All checks done. Share this output.</strong>";