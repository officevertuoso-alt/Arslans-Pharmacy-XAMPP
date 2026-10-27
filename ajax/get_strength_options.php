<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();

$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

$db = db();
$db->query("SELECT DISTINCT CONCAT(strength_value, ' ', strength_unit) as value 
            FROM strengths 
            WHERE strength_value LIKE :search OR CONCAT(strength_value, ' ', strength_unit) LIKE :search
            ORDER BY usage_count DESC LIMIT 20");
$db->bind(':search', "%$search%");
$results = $db->resultSet();

header('Content-Type: application/json');
echo json_encode($results);
?>