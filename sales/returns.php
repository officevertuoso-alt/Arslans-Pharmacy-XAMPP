<?php
/**
 * sales/returns.php
 * Canonical redirect — sale returns live in /returns/ module.
 * This stub ensures any direct link to sales/returns.php works correctly.
 */
session_start();
require_once '../config/config.php';

requireLogin();

// Forward any query params (e.g. ?invoice_no=...)
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . BASE_URL . 'returns/sale_return.php' . $qs);
exit;
