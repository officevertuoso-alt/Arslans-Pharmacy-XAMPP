<?php
/**
 * ajax/clear_cart.php
 *
 * Clears the entire POS session cart.
 * Called by newSale() in pos.php.
 *
 * Response: { success: true }
 */
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/sale_functions.php';

requireLogin();
require_once '../includes/security.php';
requireCSRF();
header('Content-Type: application/json');

unset($_SESSION['pos_cart']);
$_SESSION['pos_cart'] = [];

echo json_encode(['success' => true]);
