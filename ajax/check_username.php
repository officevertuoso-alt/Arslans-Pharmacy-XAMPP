<?php
/**
 * AJAX — Check Username Availability
 * File: ajax/check_username.php
 * GET: ?username=xyz&exclude_id=5
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';

header('Content-Type: application/json');

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['available' => false, 'message' => 'Unauthenticated']);
    exit;
}

$username  = sanitize($_GET['username']   ?? '');
$excludeId = (int)($_GET['exclude_id']    ?? 0);

if (strlen($username) < 3) {
    echo json_encode(['available' => false, 'message' => 'Too short']);
    exit;
}

$params = [':username' => $username];
$sql    = 'SELECT id FROM users WHERE username = :username';

if ($excludeId > 0) {
    $sql          .= ' AND id != :exclude_id';
    $params[':exclude_id'] = $excludeId;
}

$row = getOne($sql, $params);

echo json_encode([
    'available' => ($row === false || $row === null),
]);