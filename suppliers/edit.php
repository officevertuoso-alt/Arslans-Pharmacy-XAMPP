<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/purchase_functions.php';

requireAdmin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$supplier = getSupplierById($id);

if (!$supplier) {
    header('Location: list.php?error=Supplier not found');
    exit();
}

$page_title = 'Edit Supplier - ' . htmlspecialchars($supplier['supplier_name']);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $data = [
        'supplier_name' => sanitize($_POST['supplier_name']),
        'contact_person' => sanitize($_POST['contact_person'] ?? ''),
        'phone' => sanitize($_POST['phone']),
        'alternative_phone' => sanitize($_POST['alternative_phone'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'address' => sanitize($_POST['address'] ?? ''),
        'gst_no' => sanitize($_POST['gst_no'] ?? '')
    ];

    if (empty($data['supplier_name'])) {
        $error = 'Supplier name is required.';
    } elseif (empty($data['phone'])) {
        $error = 'Phone number is required.';
    } elseif (updateSupplier($id, $data)) {
        $success = 'Supplier updated successfully!';
        $supplier = getSupplierById($id);
    } else {
        $error = 'Failed to update supplier.';
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode($error
            ? ['success' => false, 'message' => $error]
            : ['success' => true,  'message' => $success]
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
                <h4 class="mb-1">Edit Supplier</h4>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($supplier['supplier_name']); ?></p>
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
            <div id="supplierEditAlert" class="mb-3"></div>
            <form method="POST" action=""
                  data-pharm-form
                  data-success-msg="Supplier updated successfully!"
                  data-redirect="list.php"
                  data-redirect-delay="1500"
                  data-loading-text="Saving…"
                  data-alert-target="#supplierEditAlert"
                  novalidate>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Supplier Name *</label>
                        <input type="text" name="supplier_name" class="form-control" required value="<?php echo htmlspecialchars($supplier['supplier_name']); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control" value="<?php echo htmlspecialchars($supplier['contact_person'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Phone *</label>
                        <input type="text" name="phone" class="form-control" required value="<?php echo htmlspecialchars($supplier['phone']); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Alternative Phone</label>
                        <input type="text" name="alternative_phone" class="form-control" value="<?php echo htmlspecialchars($supplier['alternative_phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">GST Number</label>
                        <input type="text" name="gst_no" class="form-control" value="<?php echo htmlspecialchars($supplier['gst_no'] ?? ''); ?>">
                    </div>
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($supplier['address'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="text-end">
                    <button type="submit" data-submit-btn class="btn btn-primary" data-requires-perm="suppliers.edit">
                        <i class="fas fa-save me-2"></i> Update Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
