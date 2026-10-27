<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/purchase_functions.php';

requireLogin();
// Phase 21: admin, manager, and pharmacist can add suppliers (DB-backed permission)
if (!canAdd('suppliers')) {
    if (function_exists('setFlash')) setFlash('error', 'Permission denied: cannot add suppliers.');
    header('Location: ../dashboard.php'); exit;
}

$page_title = 'Add Supplier - Pharmacy Management System';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_POST['ajax_request']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $data = [
        'supplier_name'     => sanitize($_POST['supplier_name'] ?? ''),
        'contact_person'    => sanitize($_POST['contact_person'] ?? ''),
        'phone'             => sanitize($_POST['phone'] ?? ''),
        'alternative_phone' => sanitize($_POST['alternative_phone'] ?? ''),
        'email'             => sanitize($_POST['email'] ?? ''),
        'address'           => sanitize($_POST['address'] ?? ''),
        'gst_no'            => sanitize($_POST['gst_no'] ?? ''),
        'opening_balance'   => (float)($_POST['opening_balance'] ?? 0)
    ];

    if (empty($data['supplier_name'])) {
        $error = 'Supplier name is required.';
    } elseif (empty($data['phone'])) {
        $error = 'Phone number is required.';
    } else {
        $supplierId = addSupplier($data);
        if ($supplierId) {
            $success = 'Supplier added successfully!';
            $_POST = [];
        } else {
            $error = 'Failed to add supplier. Please try again.';
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode($error
            ? ['success' => false, 'message' => $error]
            : ['success' => true,  'message' => $success, 'supplier_id' => $supplierId ?? null]
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
        body { background: #f0f2f5; }
        .main-content { margin-left: var(--sb-w, 260px); transition: margin-left .25s ease; padding: 20px; }
        .form-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
    </style>

</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">

<?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1">Add Supplier</h4>
                <p class="text-muted mb-0">Add new supplier to the system</p>
            </div>
            <a href="list.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i> Back to List</a>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="form-card">
            <div id="supplierAlertArea" class="mb-3"></div>
            <form method="POST" action=""
                  id="addSupplierForm"
                  data-pharm-form
                  data-success-msg="Supplier added successfully!"
                  data-redirect="list.php"
                  data-redirect-delay="1500"
                  data-loading-text="Saving Supplier…"
                  data-alert-target="#supplierAlertArea"
                  novalidate>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Supplier Name *</label>
                        <input type="text" name="supplier_name" class="form-control" required value="<?php echo htmlspecialchars($_POST['supplier_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control" value="<?php echo htmlspecialchars($_POST['contact_person'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Phone *</label>
                        <input type="text" name="phone" class="form-control" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Alternative Phone</label>
                        <input type="text" name="alternative_phone" class="form-control" value="<?php echo htmlspecialchars($_POST['alternative_phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">GST Number</label>
                        <input type="text" name="gst_no" class="form-control" value="<?php echo htmlspecialchars($_POST['gst_no'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Opening Balance</label>
                        <input type="number" step="0.01" name="opening_balance" class="form-control" value="<?php echo htmlspecialchars($_POST['opening_balance'] ?? 0, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="text-end">
                    <button type="submit" class="btn btn-primary" data-submit-btn data-requires-perm="suppliers.add">
                        <i class="fas fa-save me-2"></i> Save Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>