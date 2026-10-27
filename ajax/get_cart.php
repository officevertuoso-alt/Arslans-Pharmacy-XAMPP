<?php
/**
 * ajax/get_cart.php
 *
 * Returns the current session cart as JSON.
 * The frontend (fetchAndRenderCart) calls this to rebuild cartState.
 *
 * Response: { cart: [...items] }
 */
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/sale_functions.php';

requireLogin();
header('Content-Type: application/json');

if (!isset($_SESSION['pos_cart']) || !is_array($_SESSION['pos_cart'])) {
    echo json_encode(['cart' => []]);
    exit;
}

$cartItems = array_values($_SESSION['pos_cart']);

echo json_encode(['cart' => $cartItems]);
