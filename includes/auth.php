<?php // REPAIRED vFIX2 - UPDATED vFIX16
/**
 * Authentication & User Management Helpers
 * File: includes/auth.php
 * Version: V4.5 — Strict Single-User Session Lock (Fixed Dashboard Post-Redirect Loop)
 * FIXED: Removed is_active column reference (Step 7)
 * 
 * FORENSIC REPAIRS (vFIX2):
 *   - Added force_password_change to loginUser() SELECT and SESSION storage
 *   - Added force_password_change redirect check in requireLogin()
 *   - Enhanced isAdmin() to check both $_SESSION['user_role'] and $_SESSION['role']
 *   - Fixed is_first_login default to 0 (not 1) in loginUser()
 *   - Fixed static $permCache cross-user stale cache bug with user ID tracking
 * 
 * UPDATED (vFIX16):
 *   - Fixed requireSingleSession() to prevent infinite session conflict loop
 *   - Added reset_sessions.php to allowed scripts list
 *   - Added session conflict avoidance for login page
 */

// Ensure session is started only once
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─────────────────────────────────────────────────────────────────────────────
// getOne() / getAll() — Database class style (named params)
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('getOne')) {
    function getOne(string $sql, array $params = []) {
        $db = db();
        $db->query($sql);
        foreach ($params as $key => $val) {
            $db->bind($key, $val);
        }
        return $db->single();
    }
}

if (!function_exists('getAll')) {
    function getAll(string $sql, array $params = []) {
        $db = db();
        $db->query($sql);
        foreach ($params as $key => $val) {
            $db->bind($key, $val);
        }
        return $db->resultSet();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// V4.0 PERMISSION SYSTEM — canDo() / canView() / requirePermission()
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('canDo')) {
    function canDo(string $module, string $action): bool
    {
        if (!isLoggedIn()) return false;

        $role   = $_SESSION['user_role'] ?? '';
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($role === 'admin') return true;
        // Phase 21: manager has full DB-backed permissions (no hardcoded bypass)

        // ISSUE 5: Fix cross-user stale cache bug
        static $permCache = [];
        static $lastUserId = null;
        
        if ($lastUserId !== $userId) {
            $permCache = [];
            $lastUserId = $userId;
        }

        if (!isset($permCache[$module])) {
            $perm = getOne(
                "SELECT can_view, can_add, can_edit, can_delete,
                        can_print, can_export, can_import, can_void
                 FROM   user_permissions
                 WHERE  user_id = :uid AND module = :mod
                 LIMIT  1",
                [':uid' => $userId, ':mod' => $module]
            );
            $permCache[$module] = $perm ?: [];
        }

        $col = 'can_' . $action;
        return !empty($permCache[$module][$col]);
    }
}

if (!function_exists('canView')) {
    function canView(string $module): bool
    {
        return canDo($module, 'view');
    }
}

if (!function_exists('requirePermission')) {
    function requirePermission(string $module, string $action): void
    {
        if (canDo($module, $action)) return;

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
            exit;
        }

        if (function_exists('setFlash')) {
            setFlash('error', '<i class="bi bi-shield-exclamation me-2"></i>Access denied. You do not have permission to perform this action.');
        }
        
        $redirectUrl = defined('BASE_URL') ? BASE_URL . '/dashboard.php' : (basename(dirname($_SERVER['PHP_SELF'])) === 'auth' ? '../dashboard.php' : 'dashboard.php');
        header('Location: ' . $redirectUrl);
        exit;
    }
}

if (!function_exists('hasPermission')) {
    function hasPermission(string $module, string $action): bool
    {
        return canDo($module, $action);
    }
}

// Phase 21: additional action shorthand helpers
if (!function_exists('canExport')) { function canExport(string $module): bool { return canDo($module, 'export'); } }
if (!function_exists('canImport')) { function canImport(string $module): bool { return canDo($module, 'import'); } }
if (!function_exists('canVoid'))   { function canVoid(string $module):   bool { return canDo($module, 'void'); } }
if (!function_exists('canPrint'))  { function canPrint(string $module):  bool { return canDo($module, 'print'); } }
if (!function_exists('canDelete')) { function canDelete(string $module): bool { return canDo($module, 'delete'); } }
if (!function_exists('canAdd'))    { function canAdd(string $module):    bool { return canDo($module, 'add'); } }
if (!function_exists('canEdit'))   { function canEdit(string $module):   bool { return canDo($module, 'edit'); } }

// ─────────────────────────────────────────────────────────────────────────────
// REMEMBER ME TOKEN FUNCTIONS
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('setRememberToken')) {
    function setRememberToken(int $userId): void
    {
        $token       = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);
        $expires     = date('Y-m-d H:i:s', strtotime('+30 days'));

        $db = db();
        $db->query("UPDATE users SET remember_token = :token, remember_expires = :expires WHERE id = :id");
        $db->bind(':token',   $hashedToken);
        $db->bind(':expires', $expires);
        $db->bind(':id',      $userId);
        $db->execute();

        // ISS-014 FIX: Use array form with secure=true so token is never sent over plain HTTP
        setcookie('remember_token', $token, [
            'expires'  => time() + (86400 * 30),
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('checkRememberToken')) {
    function checkRememberToken(): bool
    {
        if (!isset($_COOKIE['remember_token'])) {
            return false;
        }

        $hashedToken = hash('sha256', $_COOKIE['remember_token']);

        $db = db();
        $db->query("
            SELECT id, username, full_name, email, role
            FROM   users
            WHERE  remember_token = :token
              AND  (remember_expires IS NULL OR remember_expires > NOW())
              AND  status = 1
        ");
        $db->bind(':token', $hashedToken);
        $user = $db->single();

        if ($user) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email']     = $user['email'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['user_role'] = $user['role'];

            if (function_exists('kickOutOtherSessions')) {
                kickOutOtherSessions((int)$user['id'], session_id());
            }
            if (function_exists('registerDeviceSession')) {
                registerDeviceSession((int)$user['id'], session_id());
            }

            if (function_exists('logActivity')) {
                logActivity('Auto Login', 'User logged in via remember me token', (int)$user['id']);
            }
            return true;
        }

        setcookie('remember_token', '', time() - 3600, '/');
        return false;
    }
}

if (!function_exists('clearRememberToken')) {
    function clearRememberToken(int $userId): void
    {
        $db = db();
        $db->query("UPDATE users SET remember_token = NULL, remember_expires = NULL WHERE id = :id");
        $db->bind(':id', $userId);
        $db->execute();

        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/');
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FORCE LOGOUT
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('forceLogoutUser')) {
    function forceLogoutUser(int $targetUserId): bool
    {
        try {
            $db = db();
            $db->query("UPDATE users SET remember_token = NULL, remember_expires = NULL,
                               session_token = NULL
                        WHERE id = :id");
            $db->bind(':id', $targetUserId);
            $db->execute();

            $pdo = db()->getConnection();
            $stmt = $pdo->prepare("DELETE FROM active_sessions WHERE user_id = :uid");
            $stmt->execute([':uid' => $targetUserId]);

            if (function_exists('logActivity')) {
                logActivity(
                    'FORCE_LOGOUT',
                    "Forced logout for user ID: {$targetUserId}",
                    (int)($_SESSION['user_id'] ?? 0)
                );
            }
            return true;
        } catch (Exception $e) {
            error_log('forceLogoutUser() error: ' . $e->getMessage());
            return false;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// LOGIN RATE LIMITING
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('checkLoginRateLimit')) {
    function checkLoginRateLimit(): array
    {
        $maxAttempts    = defined('LOGIN_MAX_ATTEMPTS')    ? LOGIN_MAX_ATTEMPTS    : 5;
        $lockoutMinutes = defined('LOGIN_LOCKOUT_MINUTES') ? LOGIN_LOCKOUT_MINUTES : 15;

        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = [];
        }

        $now = time();
        foreach ($_SESSION['login_attempts'] as $key => $timestamp) {
            if ($timestamp < $now - ($lockoutMinutes * 60)) {
                unset($_SESSION['login_attempts'][$key]);
            }
        }

        $attempts     = count($_SESSION['login_attempts']);
        $locked       = ($attempts >= $maxAttempts);
        $lockoutUntil = ($locked && count($_SESSION['login_attempts']) > 0)
            ? (max($_SESSION['login_attempts']) + ($lockoutMinutes * 60))
            : 0;

        return [
            'locked'        => $locked,
            'attempts'      => $attempts,
            'lockout_until' => $lockoutUntil,
        ];
    }
}

if (!function_exists('recordFailedLoginAttempt')) {
    function recordFailedLoginAttempt(string $username = ''): void
    {
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = [];
        }
        $_SESSION['login_attempts'][] = time();
    }
}

if (!function_exists('clearFailedLoginAttempts')) {
    function clearFailedLoginAttempts(string $username = ''): void
    {
        unset($_SESSION['login_attempts']);
    }
}

if (!function_exists('recordFailedLogin')) {
    function recordFailedLogin(string $username = ''): void { recordFailedLoginAttempt($username); }
}
if (!function_exists('clearFailedLogins')) {
    function clearFailedLogins(string $username = ''): void { clearFailedLoginAttempts($username); }
}

// ─────────────────────────────────────────────────────────────────────────────
// SESSION / LOGIN STATUS
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

// ISSUE 3: Enhanced isAdmin to check both session keys
if (!function_exists('isAdmin')) {
    function isAdmin(): bool
    {
        if (!isLoggedIn()) return false;
        $role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
        return $role === 'admin';
    }
}

// Phase 21: isManager helper
if (!function_exists('isManager')) {
    function isManager(): bool
    {
        if (!isLoggedIn()) return false;
        $role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
        return $role === 'manager';
    }
}

// Phase 21: hasAnyRole — check if current user has any of the given roles
if (!function_exists('hasAnyRole')) {
    function hasAnyRole(array $roles): bool
    {
        if (!isLoggedIn()) return false;
        $role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
        return in_array($role, $roles, true);
    }
}

// Phase 21: requireManager — redirects unless user is admin or manager
if (!function_exists('requireManager')) {
    function requireManager(): void
    {
        requireLogin();
        if (!isAdmin() && !isManager()) {
            if (function_exists('setFlash')) {
                setFlash('error', '<i class="bi bi-shield-exclamation me-2"></i>Manager or Admin access required.');
            }
            $redirectUrl = defined('BASE_URL') ? BASE_URL . '/dashboard.php' : 'dashboard.php';
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
}

// ISSUE 2: Added force_password_change redirect check
if (!function_exists('requireLogin')) {
    function requireLogin(): void
    {
        if (!isLoggedIn()) {
            if (function_exists('checkRememberToken') && checkRememberToken()) return;
            
            $loginUrl = defined('BASE_URL') ? BASE_URL . '/auth/login.php' : (basename(dirname($_SERVER['PHP_SELF'])) === 'auth' ? 'login.php' : 'auth/login.php');
            header('Location: ' . $loginUrl);
            exit;
        }
        
        // Check if force_password_change is required
        $forcePasswordChange = (int)($_SESSION['force_password_change'] ?? 0);
        $currentScript = basename($_SERVER['PHP_SELF']);
        $isChangePasswordPage = (strpos($currentScript, 'change_password') !== false || 
                                 strpos($_SERVER['PHP_SELF'], 'change_password') !== false);
        
        if ($forcePasswordChange === 1 && !$isChangePasswordPage) {
            $changePwUrl = defined('BASE_URL') ? BASE_URL . '/settings/change_password.php' : 'settings/change_password.php';
            header('Location: ' . $changePwUrl);
            exit;
        }
    }
}

if (!function_exists('requireAdmin')) {
    function requireAdmin(): void
    {
        requireLogin();
        if (!isAdmin()) {
            if (function_exists('setFlash')) {
                setFlash('error', '<i class="bi bi-shield-exclamation me-2"></i>Admin access required.');
            }
            $redirectUrl = defined('BASE_URL') ? BASE_URL . '/dashboard.php' : (basename(dirname($_SERVER['PHP_SELF'])) === 'auth' ? '../dashboard.php' : 'dashboard.php');
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
}

if (!function_exists('logout')) {
    function logout(): void
    {
        if (isLoggedIn()) {
            $userId = (int)$_SESSION['user_id'];

            // Step 1: Remove device session from DB BEFORE destroying PHP session
            if (function_exists('removeDeviceSession')) {
                removeDeviceSession();
            }

            // Step 2: Also nuke ALL sessions for this user to avoid stale DB records
            try {
                $pdo  = db()->getConnection();
                $stmt = $pdo->prepare("DELETE FROM active_sessions WHERE user_id = :uid");
                $stmt->execute([':uid' => $userId]);
            } catch (\Throwable $e) {
                error_log('[logout] active_sessions cleanup error: ' . $e->getMessage());
            }

            // Step 3: Clear remember token
            clearRememberToken($userId);
        }

        // Step 4: Destroy PHP session cleanly
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();

        // Step 5: Clear remember_token cookie
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/');
        }
        if (isset($_COOKIE['remember_user'])) {
            setcookie('remember_user', '', time() - 3600, '/');
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CORE LOGIN FUNCTION (FIXED: added force_password_change)
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('loginUser')) {
    function loginUser(string $username, string $password, bool $remember = false): bool
    {
        try {
            $pdo = db()->getConnection();

            // ISSUE 1: Added force_password_change to SELECT query
            $stmt = $pdo->prepare(
                "SELECT id, username, password, full_name, email, role, status,
                        is_first_login, force_password_change
                 FROM   users
                 WHERE  username = :username
                 LIMIT  1"
            );
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            error_log('[loginUser] Query error: ' . $e->getMessage());
            return false;
        }

        if (!$user) return false;

        $statusOk = isset($user['status']) && (int)$user['status'] === 1;
        if (!$statusOk) return false;

        $stored   = $user['password'];
        $verified = false;

        if (!$verified && (substr($stored, 0, 3) === '$2y' || substr($stored, 0, 3) === '$2b')) {
            $verified = password_verify($password, $stored);
        }
        if (!$verified && strlen($stored) === 32 && ctype_xdigit($stored)) {
            $verified = (md5($password) === $stored);
        }
        // BUG #6 FIX: Plaintext password fallback REMOVED.
        // Plaintext passwords are a critical security vulnerability.
        // Only bcrypt ($2y/$2b) and legacy md5 (for migration only) are accepted.
        // If a user cannot log in, their password must be reset via the forgot-password flow.
        if (!$verified) return false;

        if (substr($stored, 0, 3) !== '$2y' && substr($stored, 0, 3) !== '$2b') {
            try {
                $upgradeStmt = $pdo->prepare("UPDATE users SET password = :p WHERE id = :id");
                $upgradeStmt->execute([
                    ':p'  => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                    ':id' => $user['id'],
                ]);
            } catch (\PDOException $e) {
                error_log('[loginUser] Password upgrade warning: ' . $e->getMessage());
            }
        }

        // 1. Regenerate first to establish a fresh unique identifier
        session_regenerate_id(true);

        // 2. Map payload parameters cleanly to global state variables
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email']     = $user['email'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['user_role'] = $user['role'];
        // ISSUE 4: Fixed default to 0 (not 1)
        $_SESSION['is_first_login'] = (int)($user['is_first_login'] ?? 0);
        // ISSUE 1: Store force_password_change in session
        $_SESSION['force_password_change'] = (int)($user['force_password_change'] ?? 0);

        // 3. Purge existing sessions and insert the matching current token explicitly
        kickOutOtherSessions((int)$user['id'], session_id());
        registerDeviceSession((int)$user['id'], session_id());

        try {
            $upd = $pdo->prepare(
                "UPDATE users SET last_login = NOW(),
                        login_count = COALESCE(login_count, 0) + 1
                 WHERE  id = :id"
            );
            $upd->execute([':id' => $user['id']]);
        } catch (\PDOException $e) {
            error_log('[loginUser] Update warning: ' . $e->getMessage());
        }

        if ($remember) {
            setRememberToken((int)$user['id']);
        }

        if (function_exists('logActivity')) {
            logActivity('Login', 'User logged in successfully', (int)$user['id']);
        }

        return true;
    }
}

if (!function_exists('getRoleRedirect')) {
    function getRoleRedirect(): string
    {
        $base = defined('BASE_URL') ? BASE_URL : (basename(dirname($_SERVER['PHP_SELF'])) === 'auth' ? '../' : './');
        return $base . 'dashboard.php';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTIVITY LOGGING & USER CREATION (Unchanged — wrapped with function_exists)
// ─────────────────────────────────────────────────────────────────────────────

// logActivity is now defined in config/config.php — this wrapper prevents redeclaration
if (!function_exists('logActivity')) {
    function logActivity(?int $userId, string $action, string $description = '', string $ip = null, string $userAgent = null): void
    {
        $ip        = $ip        ?? ($_SERVER['REMOTE_ADDR']     ?? '');
        $userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');

        $db = db();
        $db->query("
            INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at)
            VALUES (:user_id, :action, :description, :ip, :agent, NOW())
        ");
        $db->bind(':user_id',     $userId);
        $db->bind(':action',      $action);
        $db->bind(':description', $description);
        $db->bind(':ip',          $ip);
        $db->bind(':agent',       $userAgent);
        $db->execute();
    }
}

if (!function_exists('getLastUserActivity')) {
    function getLastUserActivity(int $userId, int $limit = 5): array
    {
        // ISS-026 FIX: LIMIT cannot be safely bound as a named param in all MySQL drivers.
        // Cast to int and embed directly in SQL to prevent type mismatch errors.
        $limit = max(1, (int)$limit);
        $pdo  = db()->getConnection();
        $stmt = $pdo->prepare(
            "SELECT action, description, ip_address, created_at
             FROM   activity_logs
             WHERE  user_id = :uid
             ORDER  BY created_at DESC
             LIMIT  " . $limit
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('createUser')) {
    function createUser(array $data, array $permissions = []): int|false
    {
        $db  = db();
        $pdo = $db->getConnection();

        try {
            $pdo->beginTransaction();
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $securityQuestion = $data['security_question'] ?? null;
            $securityAnswer   = !empty($data['security_answer']) ? password_hash($data['security_answer'], PASSWORD_BCRYPT) : null;
            $isFirstLogin     = 1;
            $profilePhoto = $data['profile_image'] ?? null;

            $db->query(
                "INSERT INTO users (full_name, username, email, phone, role, password, status, created_at,
                                    profile_image, security_question, security_answer, is_first_login)
                 VALUES (:full_name, :username, :email, :phone, :role, :password, 1, NOW(),
                         :profile_image, :sec_q, :sec_a, :first)"
            );
            $db->bind(':full_name',     $data['full_name']);
            $db->bind(':username',      $data['username']);
            $db->bind(':email',         $data['email']);
            $db->bind(':phone',         $data['phone'] ?? null);
            $db->bind(':role',          $data['role']);
            $db->bind(':password',      $hashedPassword);
            $db->bind(':profile_image', $profilePhoto);
            $db->bind(':sec_q',         $securityQuestion);
            $db->bind(':sec_a',         $securityAnswer);
            $db->bind(':first',         $isFirstLogin);
            $db->execute();

            $newUserId = (int)$db->lastId();
            if ($newUserId && !empty($permissions)) {
                _savePermissionsInTransaction($pdo, $newUserId, $permissions);
            }

            $pdo->commit();
            return $newUserId;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return false;
        }
    }
}

if (!function_exists('updateUser')) {
    function updateUser(int $userId, array $data, array $permissions = []): bool
    {
        $db  = db();
        $pdo = $db->getConnection();

        try {
            $pdo->beginTransaction();
            $setClauses = ['full_name = :full_name', 'username = :username', 'email = :email', 'phone = :phone', 'role = :role', 'status = :status', 'updated_at = NOW()'];
            $binds = [':full_name' => $data['full_name'], ':username' => $data['username'], ':email' => $data['email'], ':phone' => $data['phone'] ?? null, ':role' => $data['role'], ':status' => (int)$data['status'], ':id' => $userId];

            if (array_key_exists('profile_image', $data)) { $setClauses[] = 'profile_image = :profile_image'; $binds[':profile_image'] = $data['profile_image']; }
            if (array_key_exists('default_page', $data)) { $setClauses[] = 'default_page = :default_page'; $binds[':default_page'] = $data['default_page']; }
            if (array_key_exists('notes', $data)) { $setClauses[] = 'notes = :notes'; $binds[':notes'] = $data['notes']; }
            if (isset($data['security_question'])) { $setClauses[] = 'security_question = :sec_q'; $binds[':sec_q'] = $data['security_question']; }
            if (isset($data['security_answer']) && !empty($data['security_answer'])) { $setClauses[] = 'security_answer = :sec_a'; $binds[':sec_a'] = password_hash($data['security_answer'], PASSWORD_BCRYPT); }
            if (isset($data['is_first_login'])) { $setClauses[] = 'is_first_login = :first'; $binds[':first'] = (int)$data['is_first_login']; }
            if (isset($data['force_password_change'])) { $setClauses[] = 'force_password_change = :force'; $binds[':force'] = (int)$data['force_password_change']; }
            if (!empty($data['password'])) { $setClauses[] = 'password = :password'; $binds[':password'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]); }

            $sql = 'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
            $db->query($sql);
            foreach ($binds as $key => $val) { $db->bind($key, $val); }
            $db->execute();

            if (!empty($permissions)) { _savePermissionsInTransaction($pdo, $userId, $permissions); }
            $pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return false;
        }
    }
}

if (!function_exists('getUserPermissions')) {
    function getUserPermissions(int $userId): array
    {
        $rows = getAll("SELECT module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void FROM user_permissions WHERE user_id = :uid", [':uid' => $userId]);
        $permissions = [];
        foreach ($rows as $row) {
            $permissions[$row['module']] = ['view' => (bool)$row['can_view'], 'add' => (bool)$row['can_add'], 'edit' => (bool)$row['can_edit'], 'delete' => (bool)$row['can_delete'], 'print' => (bool)$row['can_print'], 'export' => (bool)$row['can_export'], 'import' => (bool)$row['can_import'], 'void' => (bool)$row['can_void']];
        }
        return $permissions;
    }
}

if (!function_exists('saveUserPermissions')) {
    function saveUserPermissions(int $userId, array $permissions): bool
    {
        $pdo = db()->getConnection();
        try {
            $pdo->beginTransaction();
            _savePermissionsInTransaction($pdo, $userId, $permissions);
            $pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return false;
        }
    }
}

if (!function_exists('_savePermissionsInTransaction')) {
    function _savePermissionsInTransaction(PDO $pdo, int $userId, array $permissions): void
    {
        $del = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = :uid");
        $del->execute([':uid' => $userId]);
        if (empty($permissions)) return;

        // ISS-027 NOTE: static $permCols caches the user_permissions column list for the
        // entire PHP process lifetime. This is intentional and safe for short-lived Apache/XAMPP
        // requests — each request is a new process so there is no stale-cache risk in practice.
        // If you add a column via ALTER TABLE (e.g. can_reset) during a long-running PHP-FPM worker,
        // restart PHP-FPM to flush the cache, or call opcache_reset().
        static $permCols = null;
        if ($permCols === null) { $permCols = array_flip($pdo->query('SHOW COLUMNS FROM user_permissions')->fetchAll(PDO::FETCH_COLUMN)); }
        $hasCreatedAt = isset($permCols['created_at']);
        $hasUpdatedAt = isset($permCols['updated_at']);
        $extraCols = ($hasCreatedAt ? ', created_at' : '') . ($hasUpdatedAt ? ', updated_at' : '');
        $extraVals = ($hasCreatedAt ? ', NOW()' : '') . ($hasUpdatedAt ? ', NOW()' : '');

        $ins = $pdo->prepare("INSERT INTO user_permissions (user_id, module, can_view, can_add, can_edit, can_delete, can_print, can_export, can_import, can_void{$extraCols}) VALUES (:uid, :module, :view, :add, :edit, :delete, :print, :export, :import, :void{$extraVals})");

        foreach ($permissions as $module => $actions) {
            if (!is_array($actions)) continue;
            $isIndexed = array_is_list($actions);
            $flag = $isIndexed ? fn(string $a) => (int)in_array($a, $actions, true) : fn(string $a) => (int)(!empty($actions[$a]));
            $ins->execute([':uid' => $userId, ':module' => $module, ':view' => $flag('view'), ':add' => $flag('add'), ':edit' => $flag('edit'), ':delete' => $flag('delete'), ':print' => $flag('print'), ':export' => $flag('export'), ':import' => $flag('import'), ':void' => $flag('void')]);
        }
    }
}

if (!function_exists('setUserSecurity')) {
    function setUserSecurity(int $userId, string $question, string $answer): bool
    {
        $hashedAnswer = password_hash($answer, PASSWORD_BCRYPT);
        $db = db(); $db->query("UPDATE users SET security_question = :q, security_answer = :a WHERE id = :id");
        $db->bind(':q', $question); $db->bind(':a', $hashedAnswer); $db->bind(':id', $userId);
        return $db->execute();
    }
}

if (!function_exists('needsSecuritySetup')) {
    function needsSecuritySetup(int $userId): bool
    {
        $user = getOne("SELECT is_first_login FROM users WHERE id = :id", [':id' => $userId]);
        return $user && (int)$user['is_first_login'] === 1;
    }
}

if (!function_exists('completeSecuritySetup')) {
    function completeSecuritySetup(int $userId, string $question, string $answer): bool
    {
        $hashed = password_hash($answer, PASSWORD_BCRYPT);
        $db = db(); $db->query("UPDATE users SET security_question = :q, security_answer = :a, is_first_login = 0 WHERE id = :id");
        $db->bind(':q', $question); $db->bind(':a', $hashed); $db->bind(':id', $userId);
        return $db->execute();
    }
}

if (!function_exists('verifySecurityAnswer')) {
    function verifySecurityAnswer(string $username, string $question, string $answer): int|false
    {
        $user = getOne("SELECT id, security_question, security_answer FROM users WHERE username = :u", [':u' => $username]);
        if (!$user || $user['security_question'] !== $question) { return false; }
        return password_verify($answer, $user['security_answer']) ? (int)$user['id'] : false;
    }
}

// =============================================================================
// STEP 1 — SINGLE-USER SESSION LOCK PER DEVICE
// =============================================================================

if (!function_exists('getDeviceFingerprint')) {
    function getDeviceFingerprint(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $ip = trim(explode(',', $ip)[0]);
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'NO_UA';
        return md5($ip . '|' . $userAgent);
    }
}

if (!function_exists('registerDeviceSession')) {
    function registerDeviceSession(int $userId, string $sessionId): void
    {
        try {
            $pdo  = db()->getConnection();
            $fp   = getDeviceFingerprint();
            $ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
            $ip   = $ip ? trim(explode(',', $ip)[0]) : null;
            $ua   = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $uname = $_SESSION['username'] ?? null;

            $stmt = $pdo->prepare("INSERT INTO active_sessions (user_id, session_id, device_fingerprint, ip_address, user_agent, username, logged_in_at, last_activity) VALUES (:uid, :sid, :fp, :ip, :ua, :uname, NOW(), NOW()) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), session_id = VALUES(session_id), ip_address = VALUES(ip_address), user_agent = VALUES(user_agent), username = VALUES(username), logged_in_at = NOW(), last_activity = NOW()");
            $stmt->execute([':uid' => $userId, ':sid' => $sessionId, ':fp' => $fp, ':ip' => $ip, ':ua' => $ua ? substr($ua, 0, 500) : null, ':uname' => $uname]);

            // Mark that session was just registered in this request — prevents same-request conflict false-positive
            $_SESSION['_session_just_registered'] = true;
        } catch (\Throwable $e) {
            error_log('[registerDeviceSession] ' . $e->getMessage());
        }
    }
}

if (!function_exists('isDeviceSessionValid')) {
    function isDeviceSessionValid(): bool
    {
        if (!isLoggedIn()) return false;
        
        // Loop mitigation block
        $currentScript = basename($_SERVER['PHP_SELF']);
        if ($currentScript === 'login.php') {
            return true;
        }

        // If session was just registered (fresh login), always valid
        if (!empty($_SESSION['_session_just_registered'])) {
            return true;
        }

        try {
            $pdo  = db()->getConnection();
            $sid  = session_id();
            $uid  = (int)($_SESSION['user_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT session_id FROM active_sessions WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $uid]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            // If entry is completely missing, do not drop session to prevent cold startup issues
            if (!$row) {
                registerDeviceSession($uid, $sid);
                return true;
            }
            return ($row['session_id'] === $sid);
        } catch (\Throwable $e) {
            return true; // Fallback structure protection
        }
    }
}

if (!function_exists('updateSessionActivity')) {
    function updateSessionActivity(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo  = db()->getConnection();
            $stmt = $pdo->prepare("UPDATE active_sessions SET last_activity = NOW() WHERE session_id = :sid");
            $stmt->execute([':sid' => session_id()]);
        } catch (\Throwable $e) {}
    }
}

if (!function_exists('removeDeviceSession')) {
    function removeDeviceSession(): void
    {
        try {
            $pdo  = db()->getConnection();
            $sid  = session_id();
            $stmt = $pdo->prepare("DELETE FROM active_sessions WHERE session_id = :sid");
            $stmt->execute([':sid' => $sid]);
        } catch (\Throwable $e) {}
    }
}

if (!function_exists('kickOutOtherSessions')) {
    function kickOutOtherSessions(int $userId, string $keepSessionId): void
    {
        try {
            $pdo  = db()->getConnection();
            $stmt = $pdo->prepare("DELETE FROM active_sessions WHERE user_id = :uid AND session_id != :sid");
            $stmt->execute([':uid' => $userId, ':sid' => $keepSessionId]);
        } catch (\Throwable $e) {}
    }
}

if (!function_exists('requireSingleSession')) {
    function requireSingleSession(): void
    {
        if (!isLoggedIn()) return;

        $currentScript = basename($_SERVER['PHP_SELF']);
        
        // Allow these scripts to bypass session conflict check
        $allowedScripts = ['login.php', 'logout.php', 'reset_sessions.php', 'test.php'];
        if (in_array($currentScript, $allowedScripts)) {
            return;
        }

        // If session was JUST registered in this same request (e.g. fresh login), skip conflict check
        if (!empty($_SESSION['_session_just_registered'])) {
            unset($_SESSION['_session_just_registered']); // clear after use — one-time flag
            return;
        }

        $isAuthFolder = (basename(dirname($_SERVER['PHP_SELF'])) === 'auth');

        if (!isDeviceSessionValid()) {
            // Clear the session and cookie
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();

            // Check if we are already in a conflict loop
            if (strpos($_SERVER['REQUEST_URI'], 'session_conflict') !== false) {
                // Already tried redirecting - just show error and let user login manually
                error_log("[Session] Infinite conflict loop detected on: " . $_SERVER['REQUEST_URI']);
                return;
            }

            $loginUrl = defined('BASE_URL') ? BASE_URL . '/auth/login.php' : ($isAuthFolder ? 'login.php' : 'auth/login.php');
            header('Location: ' . $loginUrl . '?session_conflict=1');
            exit();
        }

        try {
            $pdo  = db()->getConnection();
            $stmt = $pdo->prepare("SELECT last_activity FROM active_sessions WHERE session_id = :sid LIMIT 1");
            $stmt->execute([':sid' => session_id()]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $idleSeconds = time() - strtotime($row['last_activity']);
                if ($idleSeconds > 3600) {
                    if (function_exists('removeDeviceSession')) { removeDeviceSession(); }
                    $_SESSION = [];
                    if (ini_get('session.use_cookies')) {
                        $p = session_get_cookie_params();
                        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                    }
                    session_destroy();
                    
                    $loginUrl = defined('BASE_URL') ? BASE_URL . '/auth/login.php' : ($isAuthFolder ? 'login.php' : 'auth/login.php');
                    header('Location: ' . $loginUrl . '?session_timeout=1');
                    exit();
                }
            }
        } catch (\Throwable $e) {}

        updateSessionActivity();
    }
}

// ── Auto-hook ────────────────────────────────────────────────────────────────
if (!defined('SINGLE_SESSION_HOOKED')) {
    define('SINGLE_SESSION_HOOKED', true);
    if (isLoggedIn()) {
        requireSingleSession();
        // Phase 28: UA fingerprint-based session hijacking prevention
        if (function_exists('validateSessionIntegrity') && !validateSessionIntegrity()) {
            $loginUrl = defined('BASE_URL') ? BASE_URL . '/auth/login.php' : 'auth/login.php';
            header('Location: ' . $loginUrl . '?session_hijack=1');
            exit;
        }
    }
}
?>