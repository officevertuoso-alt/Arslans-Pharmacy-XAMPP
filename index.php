<?php
require_once 'config/config.php';

// If logged in, go to dashboard
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'dashboard.php');
} else {
    header('Location: ' . BASE_URL . 'auth/login.php');
}
exit();
?>