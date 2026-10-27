<?php
// ISS-015 FIX: sales/customers.php is a duplicate of customers/customers.php
// Canonical customer management page is customers/customers.php
// This file now redirects to prevent split maintenance.
require_once '../config/config.php';
header('Location: ' . BASE_URL . 'customers/customers.php');
exit();
