<?php
// === FILE: pharmacy_software/auth/logout.php ===
/**
 * Logout handler – destroys session and clears remember token
 */

require_once '../config/config.php';
require_once '../includes/auth.php';

// Define logoutUser if not already defined (fallback)
if (!function_exists('logoutUser')) {
    function logoutUser() {
        // ── STEP 5: Free device from active_sessions so next user can log in ──
        if (function_exists('removeDeviceSession')) {
            removeDeviceSession();
        }

        // Clear all session variables
        $_SESSION = array();

        // Delete session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // Destroy session
        session_destroy();

        // Clear remember_me cookie if exists
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/');
        }
    }
}

// Call logout function
logoutUser();

// ISS-002 FIX: Flash message passed via URL parameter (session is destroyed above)
header('Location: ' . BASE_URL . 'auth/login.php?msg=logged_out');
exit();
?>