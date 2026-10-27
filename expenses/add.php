<?php
/**
 * Add Expense Page – AJAX Enabled with Sync Integration
 * File: expenses/add.php
 */

require_once '../config/config.php';
require_once '../includes/expense_functions.php';
require_once '../includes/sync_engine.php';

requireLogin();

if (!canAdd('expenses')) {
    setFlash('error', 'Aapko expense add karne ki permission nahi hai.', 'danger');
    header('Location: index.php');
    exit;
}

// AJAX Handler for form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh.']);
        exit;
    }

    $errors = [];
    $date = sanitize($_POST['expense_date'] ?? $_POST['date'] ?? '');
    $category = sanitize($_POST['category'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_method = sanitize($_POST['payment_method'] ?? '');
    $receipt_note = sanitize($_POST['receipt_note'] ?? '');

    if (empty($date)) $errors[] = 'Date required.';
    if (empty($category)) $errors[] = 'Category required.';
    if (empty($description)) $errors[] = 'Description required.';
    if ($amount <= 0) $errors[] = 'Amount must be positive.';
    if (empty($payment_method)) $errors[] = 'Payment method required.';

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        exit;
    }

    $expenseId = addExpense([
        'expense_date'    => $date,
        'category'       => $category,
        'description'    => $description,
        'amount'         => $amount,
        'payment_method' => $payment_method,
        'receipt_note'   => $receipt_note,
        'created_by'     => $_SESSION['user_id']
    ]);

    if ($expenseId) {
        // ── STEP 5 FIX: Sync cashbook + dashboard after expense ──
        if (function_exists('syncAfterExpense')) {
            syncAfterExpense($expenseId, $amount, $payment_method);
        } else {
            // Fallback: clear caches so dashboard reflects new expense
            if (function_exists('clearAllCaches')) clearAllCaches();
        }
        echo json_encode(['success' => true, 'message' => '✅ Expense saved — Cashbook updated', 'id' => $expenseId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save expense. Please try again.']);
    }
    exit;
}

// Non‑AJAX page load
$errors = [];
$formData = [
    'date'           => date('Y-m-d'),
    'category'       => '',
    'description'    => '',
    'amount'         => '',
    'payment_method' => '',
    'receipt_note'   => '',
];

$categories      = getExpenseCategories();
$paymentMethods  = ['Cash', 'Bank Transfer', 'Cheque', 'Credit Card', 'Online'];
$csrfToken = generateCSRFToken();
$pageTitle = 'Naya Expense Add Karein';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include dirname(__DIR__) . '/includes/page_head.php'; ?>
    
    
    <style>
        /* Page fade-in */
        @keyframes fadeInPage{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
        main { animation: fadeInPage 0.28s ease; }

        body { background: var(--pharmacy-body-bg, #f8fafc); }
        .form-card {
            max-width: 720px;
            margin: 0 auto;
            background: var(--pharmacy-card-bg, #fff);
            border: 1px solid var(--pharmacy-border, #e5e7eb);
            border-radius: var(--card-border-radius, .75rem);
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            transition: box-shadow 0.2s ease;
        }
        .form-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.12); }
        .form-card .card-header {
            background: linear-gradient(135deg, var(--pharmacy-primary, #1e40af) 0%, var(--pharmacy-accent, #2563eb) 100%);
            color: #fff;
            border-radius: calc(var(--card-border-radius, .75rem) - 1px) calc(var(--card-border-radius, .75rem) - 1px) 0 0;
            padding: 1.25rem 1.5rem;
            border-bottom: none;
        }
        .required-star { color: #dc2626; }
        .cashbook-note {
            background: color-mix(in srgb, #16a34a 8%, var(--pharmacy-card-bg, #fff));
            border-left: 4px solid #16a34a;
            border-radius: .375rem;
        }
        /* Form controls with pharmacy variables */
        .form-label { font-weight: 600; font-size: .88rem; color: var(--pharmacy-heading, #111827); }
        .form-control, .form-select {
            border-color: var(--pharmacy-border, #d1d5db);
            background: var(--pharmacy-input-bg, #fff);
            color: var(--pharmacy-text, #111827);
            border-radius: 8px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--pharmacy-primary, #2563eb);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--pharmacy-primary, #2563eb) 15%, transparent);
        }
        .input-group-text {
            background: var(--pharmacy-table-th, #f3f4f6);
            border-color: var(--pharmacy-border, #d1d5db);
            color: var(--pharmacy-heading, #374151);
            font-weight: 600;
        }
        /* Buttons */
        .btn { transition: all 0.18s ease; }
        .btn-primary { background: var(--pharmacy-primary, #2563eb); border-color: var(--pharmacy-primary, #2563eb); }
        .btn-primary:hover { background: color-mix(in srgb, var(--pharmacy-primary, #2563eb) 85%, #000); }
        /* Alert */
        .alert { border-radius: 10px; }
        .main-content { margin-left: var(--sb-w, 260px); padding: 24px; min-height: 100vh; transition: margin-left .25s ease; animation: fadeInPage 0.28s ease; }
        body.sb-col-body .main-content { margin-left: var(--sb-w-mini, 62px); }
        @media (max-width: 991.98px) { .main-content { margin-left: 0 !important; } }
    </style>

</head>
<?php
$__dm = !empty($_SESSION["company_settings_cache"]["dark_mode"]) ? " dark-mode" : "";
?>
<body class="<?php echo trim("bg-light" . $__dm); ?>">

<div class="container-fluid">
  <div class="row">
    <?php include '../includes/sidebar.php'; ?>
<?php include '../includes/navbar.php'; ?>

    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">

<!-- content (sidebar above) -->

    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>expenses/index.php">Expenses</a></li>
            <li class="breadcrumb-item active">Naya Expense</li>
        </ol>
    </nav>

    <div class="form-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i><?= htmlspecialchars($pageTitle) ?></h5>
            <small class="opacity-75">Fields jo <span class="required-star">*</span> se marked hain, woh zaroor bhariein</small>
        </div>
        <div class="card-body p-4">

            <div id="alertArea"></div>

            <div class="cashbook-note p-3 mb-4">
                <i class="bi bi-info-circle-fill text-success me-1"></i>
                <strong>Note:</strong> Yeh expense save hone par automatically
                <em>Cashbook mein Debit entry</em> bhi create ho jayegi.
            </div>

            <form id="expenseForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="date" class="form-label fw-semibold">Date <span class="required-star">*</span></label>
                        <input type="date" class="form-control" id="expense_date" name="expense_date" value="<?= htmlspecialchars($formData['date']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="category" class="form-label fw-semibold">Category <span class="required-star">*</span></label>
                        <select class="form-select" id="category" name="category" required>
                            <option value="">— Category Select Karein —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['value']) ?>"><?= htmlspecialchars($cat['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label fw-semibold">Description <span class="required-star">*</span></label>
                        <input type="text" class="form-control" id="description" name="description" placeholder="e.g. August ka kiraya, Bijli ka bill..." maxlength="255" required>
                    </div>
                    <div class="col-md-6">
                        <label for="amount" class="form-label fw-semibold">Amount (Rs) <span class="required-star">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">Rs</span>
                            <input type="number" class="form-control" id="amount" name="amount" placeholder="0.00" min="0.01" step="0.01" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="payment_method" class="form-label fw-semibold">Payment Method <span class="required-star">*</span></label>
                        <select class="form-select" id="payment_method" name="payment_method" required>
                            <option value="">— Payment Method —</option>
                            <?php foreach ($paymentMethods as $pm): ?>
                                <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="receipt_note" class="form-label fw-semibold">Receipt / Note <span class="text-muted fw-normal">(Optional)</span></label>
                        <textarea class="form-control" id="receipt_note" name="receipt_note" rows="3" placeholder="Receipt number, vendor name, ya koi note..." maxlength="500"></textarea>
                    </div>
                </div>

                <hr class="my-4">
                <div class="d-flex justify-content-between align-items-center">
                    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Wapas Jao</a>
                    <button type="submit" class="btn btn-primary px-4" id="submitBtn"><i class="bi bi-save me-1"></i> Expense Save Karein</button>
                </div>
            </form>
        </div>
    </div>
</div>



<script>
$(document).ready(function() {
    $('#expenseForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#submitBtn');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#alertArea').html('<div class="alert alert-success alert-dismissible fade show">' +
                        '<i class="bi bi-check-circle me-1"></i> ' + res.message +
                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>');
                    $('#expenseForm')[0].reset();
                    $('#expense_date').val(new Date().toISOString().split('T')[0]);
                    setTimeout(function() {
                        window.location.href = 'index.php';
                    }, 1500);
                } else {
                    $('#alertArea').html('<div class="alert alert-danger alert-dismissible fade show">' +
                        '<i class="bi bi-exclamation-triangle me-1"></i> ' + res.message +
                        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>');
                    $btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i> Expense Save Karein');
                }
            },
            error: function() {
                $('#alertArea').html('<div class="alert alert-danger">Server error. Please try again.</div>');
                $btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i> Expense Save Karein');
            }
        });
    });
});
</script>
</div><!-- /container-fluid -->
</div><!-- /main-content -->
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
