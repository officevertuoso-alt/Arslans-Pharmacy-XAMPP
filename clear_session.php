<?php
session_start();
session_destroy();

// Clear all session variables
$_SESSION = array();

// Delete session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Delete remember me cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time()-3600, '/');
}

echo "Session cleared successfully!";
echo "<br><a href='auth/login.php'>Go to Login Page</a>";
?>