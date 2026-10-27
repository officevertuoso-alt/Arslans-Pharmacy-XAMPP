<?php
require_once '../config/config.php';
requireAdmin(); // Only admin can create new users

$error = '';
$success = '';

$roles = ['admin', 'pharmacist', 'cashier'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $full_name = sanitize($_POST['full_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $role = sanitize($_POST['role'] ?? 'pharmacist');
    
    // Validation
    if (empty($username) || empty($password) || empty($full_name)) {
        $error = 'Please fill all required fields';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } elseif (!in_array($role, $roles)) {
        $error = 'Invalid role selected';
    } else {
        // Check if username exists
        $db = db();
        $db->query("SELECT id FROM users WHERE username = :username");
        $db->bind(':username', $username);
        if ($db->single()) {
            $error = 'Username already exists';
        } else {
            if (createUser($username, $password, $full_name, $email, $phone, $role)) {
                $success = 'User created successfully!';
                // Clear form
                $_POST = array();
            } else {
                $error = 'Failed to create user';
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(isset($error) && $error
            ? ['success' => false, 'message' => $error]
            : ['success' => true,  'message' => $success ?? 'User created successfully!']
        );
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    <style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .register-container {
            max-width: 500px;
            margin: 50px auto;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border: none;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px;
            font-weight: 600;
        }
        .btn-create {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 10px;
            font-weight: 600;
        }
        .btn-create:hover {
            background: linear-gradient(135deg, #5a67d8 0%, #6b46a0 100%);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="register-container">
            <div class="card">
                <div class="card-header text-center">
                    <i class="fas fa-user-plus fa-2x mb-2"></i>
                    <h4 class="mb-0">Create New User</h4>
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <div id="registerAlertArea" class="mb-3"></div>
                    <form method="POST" action=""
                          data-pharm-form
                          data-success-msg="User created successfully!"
                          data-redirect="list.php"
                          data-redirect-delay="1500"
                          data-loading-text="Creating User…"
                          data-alert-target="#registerAlertArea"
                          novalidate>
                        <div class="mb-3">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" required>
                            <small class="text-muted">Minimum 6 characters</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password *</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role *</label>
                            <select name="role" class="form-select" required>
                                <option value="pharmacist" <?php echo ($_POST['role'] ?? '') == 'pharmacist' ? 'selected' : ''; ?>>Pharmacist</option>
                                <option value="cashier" <?php echo ($_POST['role'] ?? '') == 'cashier' ? 'selected' : ''; ?>>Cashier</option>
                                <option value="admin" <?php echo ($_POST['role'] ?? '') == 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        <button type="submit" data-submit-btn class="btn btn-create text-white w-100">
                            <i class="fas fa-save me-2"></i> Create User
                        </button>
                    </form>
                </div>
                <div class="card-footer text-center bg-light">
                    <a href="<?php echo BASE_URL; ?>users/list.php" class="text-decoration-none">
                        <i class="fas fa-arrow-left me-1"></i> Back to Users List
                    </a>
                </div>
            </div>
        </div>
    </div>

<!-- ═══ Global Footer (Phase 5) ══════════════════════════════════ -->
<footer style="text-align:center;padding:12px 20px;font-size:11px;color:#6c757d;border-top:1px solid #dee2e6;background:#f8f9fa;margin-top:auto;">
    <i class="fas fa-heartbeat me-1"></i>
    Powered by <strong>Dr. Arslan Yasin Sukhera</strong>
    &nbsp;|&nbsp; &copy; <?php echo date('Y'); ?>
    &nbsp;&mdash;&nbsp; <?php echo defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'PharmaCare Pro'; ?>
    &nbsp;|&nbsp; <span style="font-family:monospace;"><?php echo defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : 'v5.0'; ?></span>
</footer>
</body>
</html>
