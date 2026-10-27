<?php
// ===== TEMPORARY DEBUG — REMOVE AFTER FIXING =====
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/config.php';
require_once '../includes/return_functions.php';
require_once '../includes/cashbook_functions.php';

// Check login without redirect
if (!isLoggedIn()) {
    die('<h2 style="color:red">NOT LOGGED IN — session expired or cookies blocked</h2>');
}

$return_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$return_id) {
    die('<h2 style="color:red">No ID in URL — add ?id=2 to URL</h2>');
}

echo '<h3>Checking id=' . $return_id . '</h3>';

// Raw DB check
try {
    $db = db();
    $db->query("SELECT COUNT(*) as cnt FROM sale_returns WHERE id = :id");
    $db->bind(':id', $return_id);
    $check = $db->single();
    if (!$check || $check['cnt'] == 0) {
        die('<h2 style="color:red">sale_returns mein id=' . $return_id . ' record EXIST NAHI KARTA! Shayad id=1 ya koi aur try karo.</h2>');
    }
    echo '<p style="color:green">✔ Record found in sale_returns</p>';
} catch (Exception $e) {
    die('<h2 style="color:red">DB Error: ' . $e->getMessage() . '</h2>');
}

// Show raw row
try {
    $db->query("SELECT * FROM sale_returns WHERE id = :id");
    $db->bind(':id', $return_id);
    $raw = $db->single();
    echo '<pre style="background:#f0f0f0;padding:10px;border:1px solid #ccc">RAW sale_returns ROW:<br>' . print_r($raw, true) . '</pre>';
} catch (Exception $e) {
    echo '<p style="color:red">Raw fetch error: ' . $e->getMessage() . '</p>';
}

// Check sales JOIN
try {
    $db->query("SELECT sr.*, s.invoice_no, s.customer_name, s.sale_date, s.customer_phone
                FROM sale_returns sr
                LEFT JOIN sales s ON s.id = sr.sale_id
                WHERE sr.id = :id");
    $db->bind(':id', $return_id);
    $joined = $db->single();
    if (!$joined) {
        echo '<p style="color:orange">⚠ JOIN returned NULL — sales table mein sale_id match nahi karta</p>';
    } else {
        echo '<pre style="background:#e0ffe0;padding:10px;border:1px solid green">JOINED ROW (with sales):<br>' . print_r($joined, true) . '</pre>';
    }
} catch (Exception $e) {
    echo '<p style="color:red">JOIN query error: ' . $e->getMessage() . '</p>';
}

// Check getSaleReturnDetails
if (function_exists('getSaleReturnDetails')) {
    $result = getSaleReturnDetails($return_id);
    echo '<pre style="background:#e0e0ff;padding:10px;border:1px solid blue">getSaleReturnDetails() result:<br>' . print_r($result, true) . '</pre>';
} else {
    echo '<p style="color:red">getSaleReturnDetails() function missing!</p>';
}

// Check redirect() — does it exit properly?
echo '<h3 style="color:green">===== DEBUG DONE =====</h3>';
echo '<p>Agar yahan tak aa gaya toh saari queries theek hain.</p>';